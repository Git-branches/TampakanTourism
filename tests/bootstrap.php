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
