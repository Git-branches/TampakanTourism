<?php
declare(strict_types=1);

/**
 * TourSync — shared scaffolding for the test suites.
 *
 * WHY THESE TESTS EXIST IN THE REPOSITORY AND NOT IN A SCRATCH FOLDER
 *
 * The homepage hero was rewritten to read from the hero_slides table, and some
 * days later index.php was saved back over from a stale editor buffer. The whole
 * change vanished — the import, the query, the fallback — and the front page
 * quietly went back to showing stock photographs of somewhere else. Nothing
 * failed. Nothing logged. The admin screen still showed the uploaded pictures,
 * so the office had every reason to believe it had worked.
 *
 * It was found by a person looking at the website, which is the most expensive
 * way to find anything. A test file living in a temporary folder would not have
 * caught it either. So they live here.
 */

define('TOURSYNC_TESTS', true);

require_once dirname(__DIR__) . '/bootstrap.php';

/** The site's own address, for the suites that go through Apache. */
function test_base_url(): string
{
    return rtrim((string) (getenv('TOURSYNC_TEST_URL') ?: 'http://localhost/TampakanTourism'), '/');
}

/**
 * Whether the web server is answering.
 *
 * The end-to-end suites need Apache, because Uploader::store() calls
 * is_uploaded_file() and that is false for anything a CLI script fakes into
 * $_FILES. A suite that worked around the check would pass while the browser
 * path stayed broken, which is the exact failure it is meant to catch.
 */
function test_server_up(): bool
{
    $ch = curl_init(test_base_url() . '/index.php');

    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);

    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code > 0 && $code < 500;
}

/**
 * Writes a signed-in officer session straight into session.save_path and
 * returns [session id, csrf token].
 *
 * Apache picks it up from the cookie, so a suite can post to a page that
 * requires an officer without this repository ever holding a password.
 * The file is deleted when the process ends, whatever the outcome.
 *
 * @return array{0: string, 1: string}
 */
function test_sign_in_officer(): array
{
    $me = \App\Core\Database::first(
        "SELECT * FROM admins WHERE role = 'officer' AND is_active = 1 ORDER BY id LIMIT 1"
    );

    if ($me === null) {
        fwrite(STDERR, "  no active officer account to sign in as\n");
        exit(1);
    }

    $sid   = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(32));

    /* PHP's own serializer, so the format matches whatever session.serialize_handler
       is set to rather than a guess at its output. */
    $keep = $_SESSION ?? [];

    $_SESSION = [
        '_admin' => [
            'id'        => (int) $me['id'],
            'username'  => (string) $me['username'],
            'full_name' => (string) $me['full_name'],
            'role'      => 'officer',
        ],
        '_admin_seen'  => time(),
        '_admin_start' => time(),
        '_csrf_token'  => $token,
    ];

    $encoded  = session_encode();
    $_SESSION = $keep;

    $file = rtrim(session_save_path(), '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    file_put_contents($file, $encoded);

    register_shutdown_function(static function () use ($file): void {
        if (is_file($file)) {
            @unlink($file);
        }
    });

    return [$sid, $token];
}

/**
 * Writes a signed-in DESTINATION MANAGER session and returns [session id, csrf].
 *
 * The arrival workflow spans two roles: a manager submits the month's figures
 * and an officer approves them. Testing only one half would leave the join
 * between them — the part that actually breaks — unexercised.
 *
 * @return array{0: string, 1: string, 2: int}  session id, token, destination id
 */
function test_sign_in_manager(): array
{
    $m = \App\Core\Database::first(
        'SELECT m.*, d.name AS destination_name
           FROM destination_managers m
           JOIN destinations d ON d.id = m.destination_id
          ORDER BY m.id LIMIT 1'
    );

    if ($m === null) {
        return ['', '', 0];
    }

    $sid   = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(32));
    $keep  = $_SESSION ?? [];

    $_SESSION = [
        '_manager' => [
            'id'             => (int) $m['id'],
            'full_name'      => (string) $m['full_name'],
            'username'       => (string) $m['username'],
            'destination_id' => (int) $m['destination_id'],
            'destination'    => (string) $m['destination_name'],
        ],
        '_manager_seen'  => time(),
        '_manager_start' => time(),
        '_csrf_token'    => $token,
    ];

    $encoded  = session_encode();
    $_SESSION = $keep;

    $file = rtrim(session_save_path(), '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    file_put_contents($file, $encoded);

    register_shutdown_function(static function () use ($file): void {
        if (is_file($file)) {
            @unlink($file);
        }
    });

    return [$sid, $token, (int) $m['destination_id']];
}

/**
 * POSTs to a page as that signed-in session, optionally with one file.
 *
 * @param array<string, mixed> $fields
 * @return array{code: int, body: string}
 */
function test_post(string $path, string $sid, array $fields, ?string $file = null, string $field = 'image'): array
{
    if ($file !== null) {
        $fields[$field] = new CURLFile($file, mime_content_type($file) ?: 'image/png', basename($file));
    }

    $ch = curl_init(test_base_url() . '/' . ltrim($path, '/'));

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIE         => session_name() . '=' . $sid,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => $body];
}

/**
 * Walks a public form the way a visitor does: load the page, keep the session
 * cookie, read the CSRF token out of the markup, and post with both.
 *
 * The public endpoints call Csrf::verify(), and a token belongs to a session —
 * so a POST assembled from nothing is refused with a 403, correctly. Faking a
 * token would be testing a hole rather than the form.
 *
 * @param array<string, mixed> $fields
 * @return array{code: int, body: string, token: string}
 */
function test_public_form(string $pageUrl, string $postUrl, array $fields): array
{
    $jar = tempnam(sys_get_temp_dir(), 'toursync-jar');

    register_shutdown_function(static function () use ($jar): void {
        if (is_file($jar)) {
            @unlink($jar);
        }
    });

    /* 1. The page, which starts a session and prints a token. */
    $ch = curl_init(test_base_url() . '/' . ltrim($pageUrl, '/'));

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $html = (string) curl_exec($ch);
    curl_close($ch);

    $token = '';

    if (preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m)
        || preg_match('/value="([^"]+)"\s+name="_token"/', $html, $m)) {
        $token = $m[1];
    }

    /* 2. The post, carrying the same cookie and that token. */
    $ch = curl_init(test_base_url() . '/' . ltrim($postUrl, '/'));

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => array_merge($fields, ['_token' => $token]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => $body, 'token' => $token];
}

/** GETs a page as a signed-in session — the officer's or the manager's. */
function test_get_as(string $sid, string $path): string
{
    $ch = curl_init(test_base_url() . '/' . ltrim($path, '/'));

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIE         => session_name() . '=' . $sid,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = (string) curl_exec($ch);
    curl_close($ch);

    return $body;
}

/** GETs a page as an anonymous visitor. */
function test_get(string $path): string
{
    $ch = curl_init(test_base_url() . '/' . ltrim($path, '/'));

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = (string) curl_exec($ch);
    curl_close($ch);

    return $body;
}

/** Writes a real PNG of the given size and returns its path. */
function test_make_png(string $path, string $label = 'TEST', int $w = 1920, int $h = 1080): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 32, 96, 48));
    imagestring($im, 5, 40, 40, $label, imagecolorallocate($im, 255, 255, 255));
    imagepng($im, $path);
    imagedestroy($im);

    return $path;
}

/**
 * Reads one setting straight from the database.
 *
 * NOT setting(). That helper loads every row once into a `static` and keeps it
 * for the life of the process — correct for a page render, useless to a test
 * that posts a change and then asks whether it landed: it would keep answering
 * with the values from before the POST and the suite would report a passing
 * save that never happened.
 */
function setting_fresh(string $key): string
{
    return (string) (\App\Core\Database::scalar(
        'SELECT setting_value FROM settings WHERE setting_key = ?', [$key]
    ) ?? '');
}

/**
 * Whether a stored file is on disk RIGHT NOW.
 *
 * NOT a bare is_file(). PHP caches stat results per process, and these suites
 * ask the question across a process boundary: the file is created and deleted
 * by Apache, and checked here. A path this process has already looked at keeps
 * answering with what it saw the first time — which reported a file as still
 * present after the web server had removed it, and failed a passing test.
 */
function file_on_disk(string $relative): bool
{
    $absolute = dirname(APP_PATH) . '/' . ltrim($relative, '/');

    clearstatcache(true, $absolute);

    return is_file($absolute);
}

/* ---- Assertions ---------------------------------------------------------- */

$GLOBALS['_t_pass'] = 0;
$GLOBALS['_t_fail'] = 0;

function check(string $what, mixed $got, mixed $want): void
{
    $ok = $got === $want;
    $ok ? $GLOBALS['_t_pass']++ : $GLOBALS['_t_fail']++;

    printf("  %-54s %s%s\n", $what, $ok ? 'ok' : 'FAIL',
        $ok ? '' : '  (got ' . var_export($got, true) . ', want ' . var_export($want, true) . ')');
}

function test_finish(): never
{
    printf("\n  %d passed, %d failed\n", $GLOBALS['_t_pass'], $GLOBALS['_t_fail']);
    exit($GLOBALS['_t_fail'] === 0 ? 0 : 1);
}
