<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Core\SmsGateway;
use App\Core\Validator;
use App\Repositories\ManagerRepository;

Auth::require();

$pageTitle = 'Add Destination Manager';
$pageIcon  = 'fa-user-plus';

$destinations = Database::all("SELECT id, name FROM destinations WHERE status='active' ORDER BY name");

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
    Session::flash('success', $v->value('full_name') . ' was added to the manager registry.');
    redirect(base_url('/admin/managers/index.php'));
}

$m = array_fill_keys(['id','full_name','position','destination_id','mobile_number','email','sms_opt_in','is_active'], '');
foreach (array_keys($m) as $k) {
    $old = old_all();
    if (isset($old[$k])) { $m[$k] = $old[$k]; }
}

require __DIR__ . '/../_partials/head.php';
require __DIR__ . '/_form.php';
require __DIR__ . '/../_partials/foot.php';
