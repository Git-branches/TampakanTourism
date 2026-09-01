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

$pageTitle    = 'New Announcement';
$pageIcon     = 'fa-bullhorn';
$pageSubtitle = 'One message for the website, the managers, or both';

$destinations   = Database::all("SELECT id, name FROM destinations WHERE status='active' ORDER BY name");
$recipientCount = count(ManagerRepository::smsRecipients());

if (is_post()) {
    Csrf::verify();

    $v = new Validator($_POST);
    validate_announcement($v);

    if ($v->fails()) {
        /* Back to the list, where the composer lives in a dialog; it reopens
           with the rejected input still in it. create.php on its own is
           still the same form without the dialog around it. */
        flash_back($v->errors(), $_POST, 'index.php');
    }

    $data = collect_announcement_input($v);
    $id   = AnnouncementRepository::create($data, Auth::id());

    store_announcement_banner($id);

    ActivityLog::record('announcement.create', 'announcement', $id, 'Created "' . $data['title'] . '"');

    Session::flash('success', $data['status'] === 'published'
        ? 'Announcement published. To notify destination managers by SMS, use Send Notifications below.'
        : 'Announcement saved as a draft.');

    redirect(base_url('/admin/announcements/view.php?id=' . $id));
}

$a = array_fill_keys([
    'id','title','summary','body','type','audience','status',
    'destination_id','event_date','event_location','publish_at','expires_at','banner_path',
], '');
$a['type'] = 'announcement';
$a['audience'] = 'public';
$a['status'] = 'draft';

foreach (array_keys($a) as $k) {
    $old = old_all();
    if (isset($old[$k])) { $a[$k] = $old[$k]; }
}

require __DIR__ . '/../_partials/head.php';
require __DIR__ . '/_form.php';
require __DIR__ . '/../_partials/foot.php';
