<?php
declare(strict_types=1);

/**
 * Temporary passwords, the forced first change, and the sessions that must end.
 *
 * End to end through Apache, as a browser would do it: the officer creates a
 * manager and is shown a temporary password once; the manager signs in with it
 * and cannot reach anything but "Set Your New Password"; the new password
 * works and the temporary one never does again. Then the things that used to
 * be silently wrong: a password change signs out the OTHER devices, and a
 * deactivated account is thrown out of a session it already had.
 *
 * WHAT IT TOUCHES: only accounts it creates — managers named "_qa_ …" and an
 * officer account "qaforce…" — purged on the way in as well as the way out, so
 * an interrupted run leaves nothing behind for the next. Every real account's
 * password hash is read before and compared after; a suite that changes a real
 * password is the bug this project has already had twice.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

echo "=== temporary passwords and sessions ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

/* ---- our own rows, and nobody else's ------------------------------------ */

$purge = static function (): void {
    $managers = array_column(Database::all(
        "SELECT id FROM destination_managers WHERE full_name LIKE '\\_qa\\_%'"), 'id');

    foreach ($managers as $mid) {
        Database::run('DELETE FROM activity_logs WHERE manager_id = ?', [$mid]);
        Database::run('DELETE FROM destination_managers WHERE id = ?', [$mid]);
    }

    $admins = array_column(Database::all(
        "SELECT id FROM admins WHERE username LIKE 'qaforce%'"), 'id');

    foreach ($admins as $aid) {
        Database::run('DELETE FROM activity_logs WHERE admin_id = ?', [$aid]);
        Database::run('DELETE FROM admins WHERE id = ?', [$aid]);
    }
};

$purge();
register_shutdown_function($purge);

$realHashes = static fn (): array => [
    'admins'   => Database::all("SELECT id, password_hash FROM admins WHERE username NOT LIKE 'qaforce%' ORDER BY id"),
    'managers' => Database::all("SELECT id, password_hash FROM destination_managers WHERE full_name NOT LIKE '\\_qa\\_%' ORDER BY id"),
];
$before = $realHashes();

$jars = [];
register_shutdown_function(static function () use (&$jars): void {
    foreach ($jars as $jar) { @unlink($jar); }
});

/** One browser: its own cookie jar. */
$browser = static function () use (&$jars): string {
    $jar = tempnam(sys_get_temp_dir(), 'qajar');
    $jars[] = $jar;
    return $jar;
};

/** @return array{code:int, location:string, body:string, headers:string} */
$http = static function (string $jar, string $path, ?array $post = null): array {
    $ch = curl_init(test_base_url() . '/' . ltrim($path, '/'));

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 60,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($raw, 0, $size);
    preg_match('/^Location:\s*(\S+)/mi', $headers, $m);

    return ['code' => $code, 'location' => $m[1] ?? '', 'body' => substr($raw, $size), 'headers' => $headers];
};

$token = static function (string $html): string {
    preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
};

/** Signs a browser in at a login page; returns the POST's response. */
$signIn = static function (string $jar, string $loginPath, string $user, string $pass) use ($http, $token): array {
    $page = $http($jar, $loginPath);
    return $http($jar, $loginPath, ['_token' => $token($page['body']), 'username' => $user, 'password' => $pass]);
};

$goesTo = static fn (array $r, string $needle): bool =>
    in_array($r['code'], [301, 302, 303], true) && str_contains($r['location'], $needle);

$mobile = static fn (): string => '0999' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);

[$sid, $csrf] = test_sign_in_officer();

$destination = (int) Database::scalar("SELECT id FROM destinations WHERE status = 'active' ORDER BY id LIMIT 1");

/* =========================================================================
   1. The officer creates a manager and is shown the temporary password once
   ====================================================================== */

echo "--- creating a manager ---\n";

$wanted = 'qa.firstlogin.' . bin2hex(random_bytes(2));

$made = test_post('/admin/managers/create.php', $sid, [
    '_token'         => $csrf,
    'full_name'      => '_qa_ First Login',
    'destination_id' => (string) $destination,
    'mobile_number'  => $mobile(),
    'username'       => $wanted,
]);

preg_match('#Temporary password</dt>\s*<dd><code[^>]*>([^<]+)</code>#', $made['body'], $pw);
$temp = html_entity_decode($pw[1] ?? '');

check('the account-created page is shown', $made['code'] === 200 && str_contains($made['body'], 'Manager account created'), true);
check('it shows a temporary password', strlen($temp) >= 10, true);
check('and the username that was asked for', str_contains($made['body'], $wanted), true);

$row = Database::first('SELECT * FROM destination_managers WHERE username = ?', [$wanted]);

check('the manager reached the database', $row !== null, true);

if ($row === null || $temp === '') {
    echo "  cannot continue\n";
    test_finish();
}

$managerId = (int) $row['id'];

check('the password is stored as an Argon2id hash', str_starts_with((string) $row['password_hash'], '$argon2id$'), true);
check('the hash is of the password shown', password_verify($temp, (string) $row['password_hash']), true);
check('the readable password is in no column', in_array(true, array_map(
    static fn ($v): bool => is_string($v) && str_contains($v, $temp), $row), true), false);
check('it is flagged to be replaced at first sign-in', (int) $row['must_change_password'], 1);

$again = test_post('/admin/managers/create.php', $sid, [
    '_token'         => $csrf,
    'full_name'      => '_qa_ Second Manager',
    'destination_id' => (string) $destination,
    'mobile_number'  => $mobile(),
    'username'       => '',
]);

preg_match('#Temporary password</dt>\s*<dd><code[^>]*>([^<]+)</code>#', $again['body'], $pw2);
$temp2  = html_entity_decode($pw2[1] ?? '');
$second = Database::first("SELECT * FROM destination_managers WHERE full_name = '_qa_ Second Manager'");

check('a second manager gets a different password', $temp2 !== '' && $temp2 !== $temp, true);
check('a blank username is made from the destination', str_starts_with((string) ($second['username'] ?? ''), 'manager.'), true);

/* =========================================================================
   2. The temporary password opens one door
   ====================================================================== */

echo "\n--- first sign-in ---\n";

$a = $browser();
$in = $signIn($a, '/manager/login.php', $wanted, $temp);

check('the temporary password signs in', in_array($in['code'], [302, 303], true), true);
check('the dashboard sends them to set a password', $goesTo($http($a, '/manager/index.php'), 'set-password.php'), true);
check('so does every other manager page', $goesTo($http($a, '/manager/reports.php'), 'set-password.php'), true);

$page = $http($a, '/manager/set-password.php');
check('the page asks them to set a new password', str_contains($page['body'], 'Set Your New Password'), true);

$try = static fn (string $new, string $confirm) => $http($a, '/manager/set-password.php', [
    '_token' => $token($http($a, '/manager/set-password.php')['body']),
    'new_password' => $new, 'confirm_password' => $confirm,
]);

check('two different entries are refused', str_contains($try('Brookside-Trail-7731', 'Brookside-Trail-7732')['body'], 'do not match'), true);
check('a weak password is refused', str_contains($try('short1', 'short1')['body'], 'at least 10 characters'), true);
check('the temporary password itself is refused', str_contains($try($temp, $temp)['body'], 'different from the temporary'), true);
check('none of that changed anything', (int) Database::scalar('SELECT must_change_password FROM destination_managers WHERE id = ?', [$managerId]), 1);

$new = 'Brookside-Trail-7731';
$set = $try($new, $new);

$row = Database::first('SELECT * FROM destination_managers WHERE id = ?', [$managerId]);

check('a good password is accepted', $goesTo($set, 'index.php'), true);
check('and saved as the new hash', password_verify($new, (string) $row['password_hash']), true);
check('the flag is cleared', (int) $row['must_change_password'], 0);
check('the dashboard opens now', $http($a, '/manager/index.php')['code'], 200);
check('the set-password page is no longer the destination', $goesTo($http($a, '/manager/set-password.php'), 'account.php'), true);

$c = $browser();
check('the temporary password no longer works', str_contains($signIn($c, '/manager/login.php', $wanted, $temp)['body'], 'Incorrect username or password'), true);
check('the new one does', in_array($signIn($c, '/manager/login.php', $wanted, $new)['code'], [302, 303], true), true);
check('straight to the dashboard, with no detour', $http($c, '/manager/index.php')['code'], 200);

/* =========================================================================
   3. A password change ends the other sessions
   ====================================================================== */

echo "\n--- a password change signs out other devices ---\n";

$account = $http($a, '/manager/account.php');
$newer   = 'Riverbank-Path-4482';
$change  = $http($a, '/manager/account.php', [
    '_token' => $token($account['body']), 'action' => 'password',
    'current_password' => $new, 'new_password' => $newer, 'confirm_password' => $newer,
]);

check('the manager changes their own password', $goesTo($change, 'account.php'), true);
check('the device that changed it stays signed in', $http($a, '/manager/index.php')['code'], 200);
check('the other device is signed out', $goesTo($http($c, '/manager/index.php'), 'login.php'), true);
check('and told why', str_contains($http($c, '/manager/login.php')['body'], 'password for this account was changed'), true);

/* =========================================================================
   4. Deactivation takes effect on a live session
   ====================================================================== */

echo "\n--- deactivation ---\n";

test_post('/admin/managers/index.php', $sid, ['_token' => $csrf, 'id' => (string) $managerId, 'activate' => '0']);

check('the account is inactive', (int) Database::scalar('SELECT is_active FROM destination_managers WHERE id = ?', [$managerId]), 0);
check('the signed-in manager is thrown out on the next click', $goesTo($http($a, '/manager/index.php'), 'login.php'), true);

$d = $browser();
check('and cannot sign in again', str_contains($signIn($d, '/manager/login.php', $wanted, $newer)['body'], 'deactivated'), true);

/* =========================================================================
   5. Delete refuses a manager with history, allows a mistaken entry
   ====================================================================== */

echo "\n--- deleting a manager ---\n";

test_post('/admin/managers/index.php', $sid, ['_token' => $csrf, 'id' => (string) $managerId, 'action' => 'delete']);
check('a manager who has signed in is not deleted', Database::scalar('SELECT 1 FROM destination_managers WHERE id = ?', [$managerId]) !== null, true);

$secondId = (int) ($second['id'] ?? 0);
test_post('/admin/managers/index.php', $sid, ['_token' => $csrf, 'id' => (string) $secondId, 'action' => 'delete']);
check('one who never did anything is', Database::scalar('SELECT 1 FROM destination_managers WHERE id = ?', [$secondId]), null);

$list = test_get_as($sid, '/admin/managers/index.php');
check('the registry puts its actions behind one menu', substr_count($list, 'class="kebab kebab--pop"') >= 1, true);

/* =========================================================================
   6. The officer side: an account made by another officer
   ====================================================================== */

echo "\n--- an officer account created by another officer ---\n";

$officerName = 'qaforce' . random_int(1000, 9999);
$officerPass = 'Harbourline-9120';

test_post('/admin/settings/accounts.php', $sid, [
    '_token' => $csrf, 'action' => 'create', 'full_name' => 'QA Forced Change',
    'username' => $officerName, 'email' => $officerName . '@example.com', 'password' => $officerPass,
]);

$officer = Database::first('SELECT * FROM admins WHERE username = ?', [$officerName]);
check('the account exists and must change its password', (int) ($officer['must_change_password'] ?? -1), 1);

$o = $browser();
$signIn($o, '/admin/login.php', $officerName, $officerPass);

check('the dashboard sends them to change it', $goesTo($http($o, '/admin/dashboard.php'), 'account/index.php'), true);

$acct = $http($o, '/admin/account/index.php');
check('the account page opens, and says why', str_contains($acct['body'], 'Set your own password to continue'), true);

$officerNew = 'Harbourline-9121x';
$http($o, '/admin/account/index.php', [
    '_token' => $token($acct['body']), 'action' => 'password',
    'current_password' => $officerPass, 'new_password' => $officerNew, 'confirm_password' => $officerNew,
]);

check('after the change the flag is cleared',
    (int) Database::scalar('SELECT must_change_password FROM admins WHERE username = ?', [$officerName]), 0);
check('and the dashboard opens', $http($o, '/admin/dashboard.php')['code'], 200);

test_post('/admin/settings/accounts.php', $sid, [
    '_token' => $csrf, 'action' => 'active', 'id' => (string) $officer['id'], 'activate' => '0',
]);
check('a deactivated officer is thrown out of a live session', $goesTo($http($o, '/admin/dashboard.php'), 'login.php'), true);

/* =========================================================================
   7. Nobody real was touched
   ====================================================================== */

echo "\n--- real accounts ---\n";

check('no real password hash changed', $realHashes() === $before, true);

test_finish();
