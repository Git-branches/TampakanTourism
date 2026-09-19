<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\ManagerAuth;
use App\Core\Session;
use App\Core\SmsGateway;
use App\Core\Validator;
use App\Repositories\ManagerRepository;

Auth::require();

$pageTitle = 'Add Destination Manager';
$pageIcon  = 'fa-user-plus';

$destinations = Database::all("SELECT id, name, slug FROM destinations WHERE status='active' ORDER BY name");

/** Shown once, on this response only, then gone — see below. */
$created = null;

if ($destinations === []) {
    Session::flash('warning', 'Add a destination before registering its manager.');
    redirect(base_url('/admin/destinations/index.php'));
}

if (is_post()) {
    Csrf::verify();

    $v = new Validator($_POST);
    $v->require('full_name', 'destination_id', 'mobile_number')
      ->length('full_name', 2, 120)
      ->mobile('mobile_number')
      ->email('email');

    $normalised = SmsGateway::normalise((string) ($_POST['mobile_number'] ?? ''));

    // One number, one person. Two records sharing a number means somebody
    // receives every announcement twice and the office pays for it twice.
    if ($normalised !== null && ManagerRepository::numberExists($normalised)) {
        $v->addError('mobile_number', 'That number is already registered to another manager.');
    }

    /* The sign-in is issued with the account — officer only, as on Access. A
       typed username is held to the same rules Access uses; a blank one is made
       from the destination once everything else has passed. */
    $issueSignIn = Auth::isOfficer();
    $username    = strtolower(trim((string) ($_POST['username'] ?? '')));

    if ($issueSignIn && $username !== '') {
        foreach (ManagerRepository::usernameProblems($username) as $problem) {
            $v->addError('username', $problem);
        }
    }

    /* The destination must be one on the list — an id from an edited form is
       otherwise a manager attached to an archived or non-existent site. */
    $destinationId = (int) $v->value('destination_id');
    if ($destinationId > 0 && !in_array($destinationId, array_map('intval', array_column($destinations, 'id')), true)) {
        $v->addError('destination_id', 'Choose one of the listed destinations.');
    }

    if ($v->fails()) {
        /* Back to the registry, not to this page: the form lives in a dialog
           there now, and index.php reopens it with the rejected input still
           in the fields. Visiting create.php directly still works — it is
           the same form without the dialog around it. */
        flash_back($v->errors(), $_POST, 'index.php');
    }

    $id = ManagerRepository::create([
        'destination_id' => (int) $v->value('destination_id'),
        'full_name'      => (string) $v->value('full_name'),
        'position'       => (string) $v->value('position', ''),
        'mobile_number'  => (string) $normalised,
        'email'          => (string) $v->value('email', ''),
        'sms_opt_in'     => !empty($_POST['sms_opt_in']),
    ]);

    ActivityLog::record('manager.create', 'manager', $id, 'Registered ' . $v->value('full_name'));

    if (!$issueSignIn) {
        Session::flash('success', $v->value('full_name') . ' was added to the manager registry. '
            . 'The Tourism Officer issues their sign-in from Access.');
        redirect(base_url('/admin/managers/index.php'));
    }

    if ($username === '') {
        $username = ManagerRepository::suggestUsername((int) $v->value('destination_id'));
    }

    $password = ManagerAuth::issueTemporaryPassword($id, $username);

    ActivityLog::record('manager.access_issued', 'manager', $id,
        'Issued sign-in for ' . $v->value('full_name') . ' (' . $username . ') at account creation');

    /* RENDERED, NOT REDIRECTED. A redirect would have to carry the password
       through the session store — a file on disk until the session expires.
       It exists in readable form on this one response and nowhere else. */
    $created = [
        'id'          => $id,
        'full_name'   => (string) $v->value('full_name'),
        'destination' => (string) (ManagerRepository::find($id)['destination_name'] ?? ''),
        'username'    => $username,
        'password'    => $password,
    ];

    /* No caching of a page with a password on it — Back must not bring it
       back from the browser's cache on a shared office computer. */
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

if ($created !== null) {
    $pageTitle    = 'Manager Account Created';
    $pageIcon     = 'fa-circle-check';
    $pageSubtitle = $created['full_name'];

    require __DIR__ . '/../_partials/head.php';
    ?>
    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid fa-circle-check"></i> Manager account created</h2>
        </header>
        <div class="panel__body">
            <div class="alert alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <strong>Write this down or hand it over now.</strong>
                The password is stored only as a hash and is never shown again. It is temporary:
                <?= e($created['full_name']) ?> is asked to replace it the first time they sign in.
                If it is lost, reset it from <em>Access</em>.
            </div>

            <dl class="detail-grid">
                <div><dt>Manager</dt><dd><?= e($created['full_name']) ?></dd></div>
                <div><dt>Destination</dt><dd><?= e($created['destination']) ?></dd></div>
                <div>
                    <dt>Sign-in address</dt>
                    <dd><code><?= e(base_url('/manager/login.php')) ?></code></dd>
                </div>
                <div>
                    <dt>Username</dt>
                    <dd><code><?= e($created['username']) ?></code></dd>
                </div>
                <div>
                    <dt>Temporary password</dt>
                    <dd><code style="font-size:1.1rem; letter-spacing:.08em;"><?= e($created['password']) ?></code></dd>
                </div>
            </dl>

            <div class="mt-3 d-flex gap-2 flex-wrap">
                <a href="<?= e(base_url('/admin/managers/index.php')) ?>" class="btn btn-brand btn-sm">
                    <i class="fa-solid fa-arrow-left"></i> Back to Destination Managers
                </a>
            </div>
        </div>
    </section>
    <?php
    require __DIR__ . '/../_partials/foot.php';
    exit;
}

$m = array_fill_keys(['id','full_name','position','destination_id','mobile_number','email','sms_opt_in','is_active','username'], '');
foreach (array_keys($m) as $k) {
    $old = old_all();
    if (isset($old[$k])) { $m[$k] = $old[$k]; }
}

require __DIR__ . '/../_partials/head.php';
require __DIR__ . '/_form.php';
require __DIR__ . '/../_partials/foot.php';
