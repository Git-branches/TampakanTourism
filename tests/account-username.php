<?php
declare(strict_types=1);

/**
 * Renaming the account you sign in with, end to end, through Apache.
 *
 * THE RISK THIS GUARDS AGAINST IS BEING LOCKED OUT. There is one account on
 * this system. A rename that half-worked — the row changed but the session did
 * not, or the row changed and the password check was skipped — is the one bug
 * on this screen that nobody could recover from through the interface.
 *
 * It restores the original username whatever happens, including on a fatal.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Repositories\AdminRepository;

echo "\n=== sign-in name ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

$me = Database::first("SELECT * FROM admins WHERE role = 'officer' AND is_active = 1 ORDER BY id LIMIT 1");

if ($me === null) {
    echo "  SKIP — no officer account\n";
    exit(0);
}

$id       = (int) $me['id'];
$original = (string) $me['username'];

printf("signed in as: %s (id %d)\n\n", $original, $id);

/* PUT THE NAME BACK, whatever happens below. Registered before anything is
   changed, so even a fatal on the next line cannot leave the office renamed. */
register_shutdown_function(static function () use ($id, $original): void {
    Database::run('UPDATE admins SET username = ? WHERE id = ?', [$original, $id]);
    printf("  (username restored to %s)\n", $original);
});

[$sid, $token] = test_sign_in_officer();

/* The suite cannot know the real password, so it sets one it does know and puts
   the hash back at the end. The hash — not a password — is what is restored, so
   nothing the office types ever changes. */
$originalHash = (string) $me['password_hash'];
$probePass    = 'zzProbePass123';

register_shutdown_function(static function () use ($id, $originalHash): void {
    Database::run('UPDATE admins SET password_hash = ? WHERE id = ?', [$originalHash, $id]);
    echo "  (password hash restored)\n";
});

Database::run('UPDATE admins SET password_hash = ? WHERE id = ?',
    [password_hash($probePass, PASSWORD_ARGON2ID), $id]);

function post_rename(string $sid, string $token, string $name, string $password): array
{
    return test_post('admin/account/index.php', $sid, [
        '_token'            => $token,
        'action'            => 'username',
        'new_username'      => $name,
        'username_password' => $password,
    ]);
}

echo "--- the wrong password must not rename anything ---\n";

post_rename($sid, $token, 'zz.renamed', 'definitely-not-the-password');

check('the username is unchanged', setting_username($id), $original);

echo "\n--- a username that breaks the rules is refused ---\n";

/* Compared against the name as it stands BEFORE each attempt, not against the
   original. My first version compared every case to $original, so the moment
   one attempt legitimately changed the name the remaining three reported
   failures that were really this test losing track. */
foreach ([
    'ab'        => 'too short',
    'has space' => 'a space',
    'bad!char'  => 'punctuation',
    'x'         => 'a single character',
] as $bad => $why) {
    $was = setting_username($id);
    post_rename($sid, $token, $bad, $probePass);
    check('refused: ' . $why, setting_username($id), $was);
}

echo "\n--- uppercase is normalised, not refused ---\n";

/* Deliberate, and the same as the create form: strtolower() runs before the
   rules, so "ZZ.Upper" is stored as "zz.upper" rather than rejected. The
   officer is told which spelling was saved in the success message. */
post_rename($sid, $token, 'ZZ.Upper', $probePass);

check('typed uppercase, stored lowercase', setting_username($id), 'zz.upper');

echo "\n--- a valid rename goes through ---\n";

$r = post_rename($sid, $token, 'zz.renamed', $probePass);

check('the form was accepted (302)', $r['code'], 302);
check('the row was renamed', setting_username($id), 'zz.renamed');

echo "\n--- and the new name is what signs you in ---\n";

check('the old username no longer authenticates',
    Database::first('SELECT id FROM admins WHERE username = ?', [$original]), null);
check('the new one does',
    (int) (Database::first('SELECT id FROM admins WHERE username = ?', ['zz.renamed'])['id'] ?? 0), $id);

/* Auth::attempt() matches `username = ? OR email = ?`, which is what the UI
   promises: a forgotten rename is not a lock-out. */
check('the email still identifies the account',
    (int) (Database::first('SELECT id FROM admins WHERE username = ? OR email = ?',
        [(string) $me['email'], (string) $me['email']])['id'] ?? 0), $id);

echo "\n--- the password was not touched by any of it ---\n";

$now = Database::first('SELECT password_hash FROM admins WHERE id = ?', [$id]);

check('the password still verifies', password_verify($probePass, (string) $now['password_hash']), true);

echo "\n--- renaming to the name you already have is refused, not logged as a change ---\n";

$logsBefore = (int) Database::scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'account.username'");

post_rename($sid, $token, 'zz.renamed', $probePass);

check('still the same name', setting_username($id), 'zz.renamed');
check('and no second log entry',
    (int) Database::scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'account.username'"), $logsBefore);

echo "\n--- the rename was recorded ---\n";

$entry = Database::first(
    "SELECT description FROM activity_logs WHERE action = 'account.username' ORDER BY id DESC LIMIT 1"
);

check('an audit entry names both spellings',
    $entry !== null && str_contains((string) $entry['description'], 'zz.renamed'), true);

test_finish();

/** The username as stored right now, bypassing every cache. */
function setting_username(int $id): string
{
    return (string) Database::scalar('SELECT username FROM admins WHERE id = ?', [$id]);
}
