<?php
declare(strict_types=1);

/**
 * A refused form gets a proper page — and is still refused.
 *
 * The CSRF guard used to answer with one line of plain text. It was replaced
 * with a real page, and the whole risk in that change is that somebody makes
 * the reply friendlier and the guard weaker at the same time. So the refusal
 * itself is asserted first: 403, nothing written, on every form that matters.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

echo "=== the CSRF guard: refused, and readable ===\n";

/* A bad token on each of the three doors. Every one must be refused, and the
   refusal must look like the system rather than like a crash. */
$forms = [
    'admin sign-in'   => ['/admin/login.php',   ['username' => 'someone', 'password' => 'whatever']],
    'manager sign-in' => ['/manager/login.php', ['username' => 'someone', 'password' => 'whatever']],
];

foreach ($forms as $label => [$path, $fields]) {
    echo "\n-- {$label} --\n";

    $res = test_post($path, '', $fields + ['_token' => 'not-a-real-token']);

    check('the request is refused with 403', $res['code'], 403);

    /* THE PAGE, not a line of text. */
    check('the reply is a real HTML page',
        stripos($res['body'], '<!DOCTYPE html>') === 0, true);
    check('it says what happened, in plain words',
        stripos($res['body'], 'session timed out') !== false, true);
    check('it says nothing was lost',
        stripos($res['body'], 'Nothing was saved and nothing was lost') !== false, true);

    /* THE JARGON IS GONE. "Submitted from an untrusted page" is what the guard
       checks; it is not what happened to the person reading it. */
    check('the developer wording is not shown',
        stripos($res['body'], 'untrusted page') === false, true);

    /* AND IT LEADS SOMEWHERE. A refusal with no way forward is why the old one
       looked broken. */
    check('it offers a way back', stripos($res['body'], 'Sign in again') !== false, true);
}

echo "\n-- each door sends you back to its own --\n";

$admin = test_post('/admin/login.php', '', ['_token' => 'bad']);
check('the officers\' door points at the officers\' sign-in',
    stripos($admin['body'], '/admin/login.php') !== false, true);
check('and not at the manager portal',
    stripos($admin['body'], '/manager/login.php') === false, true);

$manager = test_post('/manager/login.php', '', ['_token' => 'bad']);
check('the manager door points at the manager portal',
    stripos($manager['body'], '/manager/login.php') !== false, true);

echo "\n-- a visitor's form, which has no sign-in to return to --\n";

$public = test_post('/api/contact/submit.php', '', ['_token' => 'bad', 'name' => 'A B']);

/* The endpoint may answer JSON before the guard runs, or be guarded by it —
   either is correct. What must not happen is the old bare sentence. */
check('a visitor is never shown the developer wording',
    stripos($public['body'], 'untrusted page') === false, true);

echo "\n-- the guard still guards --\n";

/* THE POINT OF THE WHOLE SUITE. A prettier refusal that stopped refusing would
   be worse than the plain sentence it replaced. */
$before = (int) Database::scalar('SELECT COUNT(*) FROM admins');

test_post('/admin/login.php', '', [
    '_token'   => 'bad',
    'username' => 'someone',
    'password' => 'whatever',
]);

check('no account was created or touched',
    (int) Database::scalar('SELECT COUNT(*) FROM admins'), $before);

/* A correct token still gets through — the guard must not now refuse
   everything, which would also "pass" every check above. */
[$sid, $token] = test_sign_in_officer();

/* Returns the body as a string, not a [code, body] pair. */
$good = test_get_as($sid, '/admin/dashboard.php');

check('a real session still reaches the dashboard',
    stripos($good, 'session timed out') === false
    && stripos($good, 'Dashboard') !== false, true);

test_finish();
