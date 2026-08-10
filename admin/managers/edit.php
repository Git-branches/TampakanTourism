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

$id = (int) ($_GET['id'] ?? 0);
$m  = ManagerRepository::find($id);

if ($m === null) {
    Session::flash('danger', 'That manager is no longer on record.');
    redirect(base_url('/admin/managers/index.php'));
}

$pageTitle    = 'Edit Manager';
$pageIcon     = 'fa-user-pen';
$pageSubtitle = $m['full_name'];

$destinations = Database::all("SELECT id, name FROM destinations ORDER BY name");

if (is_post()) {
    Csrf::verify();

    $v = new Validator($_POST);
    $v->require('full_name', 'destination_id', 'mobile_number')
      ->length('full_name', 2, 120)
      ->mobile('mobile_number')
      ->email('email');

    $normalised = SmsGateway::normalise((string) ($_POST['mobile_number'] ?? ''));

    if ($normalised !== null && ManagerRepository::numberExists($normalised, $id)) {
        $v->addError('mobile_number', 'That number is already registered to another manager.');
    }

    if ($v->fails()) {
        flash_back($v->errors(), $_POST, 'edit.php?id=' . $id);
    }

    ManagerRepository::update($id, [
        'destination_id' => (int) $v->value('destination_id'),
        'full_name'      => (string) $v->value('full_name'),
        'position'       => (string) $v->value('position', ''),
        'mobile_number'  => (string) $normalised,
        'email'          => (string) $v->value('email', ''),
        'sms_opt_in'     => !empty($_POST['sms_opt_in']),
        'is_active'      => !empty($_POST['is_active']),
    ]);

    ActivityLog::record('manager.update', 'manager', $id, 'Updated ' . $v->value('full_name'));
    Session::flash('success', 'Changes saved.');
    redirect(base_url('/admin/managers/index.php'));
}

foreach (array_keys($m) as $k) {
    $old = old_all();
    if (isset($old[$k])) { $m[$k] = $old[$k]; }
}

require __DIR__ . '/../_partials/head.php';
require __DIR__ . '/_form.php';
require __DIR__ . '/../_partials/foot.php';
