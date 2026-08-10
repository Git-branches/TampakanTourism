<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/_helpers.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\AnnouncementRepository;
use App\Repositories\ManagerRepository;

Auth::require();

$id = (int) ($_GET['id'] ?? 0);
$a  = AnnouncementRepository::find($id);

if ($a === null) {
    Session::flash('danger', 'That announcement no longer exists.');
    redirect(base_url('/admin/announcements/index.php'));
}

$pageTitle    = 'Edit Announcement';
$pageIcon     = 'fa-pen';
$pageSubtitle = $a['title'];

$destinations   = Database::all("SELECT id, name FROM destinations ORDER BY name");
$recipientCount = count(ManagerRepository::smsRecipients());

if (is_post()) {
    Csrf::verify();

    $v = new Validator($_POST);
    validate_announcement($v);

    if ($v->fails()) {
        flash_back($v->errors(), $_POST, 'edit.php?id=' . $id);
    }

    $data = collect_announcement_input($v);
    AnnouncementRepository::update($id, $data);

    ActivityLog::record('announcement.update', 'announcement', $id, 'Updated "' . $data['title'] . '"');
    Session::flash('success', 'Changes saved.');
    redirect(base_url('/admin/announcements/view.php?id=' . $id));
}

foreach (array_keys($a) as $k) {
    $old = old_all();
    if (isset($old[$k])) { $a[$k] = $old[$k]; }
}

require __DIR__ . '/../_partials/head.php';
require __DIR__ . '/_form.php';
require __DIR__ . '/../_partials/foot.php';
