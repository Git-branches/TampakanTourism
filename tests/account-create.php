<?php
declare(strict_types=1);

/**
 * Creating an account, through the real form.
 *
 * The Role selector came off this screen: the office is one desk and every
 * account carries full access. The risk in removing a field is that the handler
 * still wants it — a validator rule left behind, a NOT NULL column with no
 * default — and account creation stops working with a message about a field
 * nobody can see any more.
 *
 * So this checks the two things that matter after that change: an account can
 * still be made, and the account that comes out has full access.
 *
 * Everything it creates, it deletes.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Repositories\AdminRepository;

echo "\n=== creating an account ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

[$sid, $token] = test_sign_in_officer();

$before = (int) Database::scalar('SELECT COUNT(*) FROM admins');

/* Nothing this test makes may survive it, including after a fatal. */
register_shutdown_function(static function (): void {
    Database::run("DELETE FROM admins WHERE username LIKE 'zz.%'");
});

function create(string $sid, string $token, array $over = []): array
{
    return test_post('admin/settings/accounts.php', $sid, array_merge([
        '_token'    => $token,
        'action'    => 'create',
        'full_name' => 'ZZ Probe Person',
        'username'  => 'zz.probe',
        'email'     => 'zz.probe@example.test',
        'password'  => 'zzProbePass123',
    ], $over));
}

echo "--- an account can still be made with no Role field ---\n";

$r = create($sid, $token);

check('the form was accepted (302)', $r['code'], 302);
check('an account appeared', (int) Database::scalar('SELECT COUNT(*) FROM admins'), $before + 1);

$made = Database::first("SELECT * FROM admins WHERE username = 'zz.probe'");

check('it exists', $made !== null, true);

if ($made === null) {
    test_finish();
}

echo "\n--- and it has full access ---\n";

check('the role is officer', (string) $made['role'], 'officer');
check('it is active', (int) $made['is_active'], 1);
check('the password verifies', password_verify('zzProbePass123', (string) $made['password_hash']), true);
check('it has not signed in yet', $made['last_login_at'], null);
check('and is flagged as never having changed its password',
    $made['password_changed_at'], null);

echo "\n--- an account posting a role cannot talk itself into something else ---\n";

/* The field is gone from the form, not from the internet. A hand-made POST
   carrying role=staff must not be honoured. */
Database::run("DELETE FROM admins WHERE username = 'zz.probe'");

create($sid, $token, ['username' => 'zz.sneak', 'email' => 'zz.sneak@example.test', 'role' => 'staff']);

$sneak = Database::first("SELECT role FROM admins WHERE username = 'zz.sneak'");

check('a posted role is ignored', $sneak !== null ? (string) $sneak['role'] : 'MISSING', 'officer');

echo "\n--- the username rules are still enforced ---\n";

$count = (int) Database::scalar('SELECT COUNT(*) FROM admins');

create($sid, $token, ['username' => 'zz sneak two', 'email' => 'zz.two@example.test']);

check('a username with a space is refused',
    (int) Database::scalar('SELECT COUNT(*) FROM admins'), $count);

create($sid, $token, ['username' => 'zz.sneak', 'email' => 'zz.three@example.test']);

check('a duplicate username is refused',
    (int) Database::scalar('SELECT COUNT(*) FROM admins'), $count);

echo "\n--- clean up ---\n";

Database::run("DELETE FROM admins WHERE username LIKE 'zz.%'");

check('every probe account removed',
    (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE username LIKE 'zz.%'"), 0);
check('the roster is back to its original size',
    (int) Database::scalar('SELECT COUNT(*) FROM admins'), $before);
check('the real account is untouched',
    (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE role = 'officer' AND is_active = 1"), 1);

test_finish();
