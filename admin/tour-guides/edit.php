<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\SmsGateway;
use App\Core\Uploader;
use App\Core\Validator;
use App\Repositories\TourGuideRosterRepository as Roster;

Auth::require();

$id    = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$guide = $id > 0 ? Roster::find($id) : null;

if ($guide === null) {
    Session::flash('danger', 'That guide could not be found.');
    redirect(base_url('/admin/tour-guides/index.php'));
}

$pageTitle = 'Edit ' . $guide['full_name'];
$pageIcon  = 'fa-user-pen';

if (is_post()) {
    Csrf::verify();

    $v = new Validator($_POST);
    $v->require('full_name')->length('full_name', 2, 160);

    if (trim((string) ($_POST['email'] ?? '')) !== '') {
        $v->email('email');
    }

    $mobile = trim((string) ($_POST['mobile_number'] ?? ''));

    if ($mobile !== '') {
        $mobile = SmsGateway::normalise($mobile) ?? $mobile;
    }

    /* NOT the same rule as on create. A card that has already lapsed is a fact
       here, and refusing to save the record because of it would leave the
       office unable to correct anything else about a guide whose card ran out
       last month. The status is computed from the date either way. */
    $validUntil = trim((string) ($_POST['valid_until'] ?? ''));

    if ($v->fails()) {
        flash_back($v->errors(), $_POST, 'edit.php?id=' . $id);
    }

    if (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $uploader = new Uploader();
        $path     = $uploader->store($_FILES['photo'], 'guides');

        if ($path === null) {
            flash_back(
                ['photo' => $uploader->firstError() ?? 'That picture could not be saved.'],
                $_POST,
                'edit.php?id=' . $id
            );
        }

        /* The old file goes only once the new one is safely stored. Deleting
           first would leave a guide with no photograph if the upload failed. */
        Uploader::delete((string) ($guide['photo_path'] ?? ''));
        Roster::setPhoto($id, $path);
    }

    Roster::update($id, [
        'full_name'     => (string) $v->value('full_name'),
        'address'       => (string) ($_POST['address'] ?? ''),
        'mobile_number' => $mobile,
        'email'         => (string) ($_POST['email'] ?? ''),
        'status'        => (string) ($_POST['status'] ?? 'active'),
        'valid_until'   => $validUntil,
        'status_note'   => (string) ($_POST['status_note'] ?? ''),
        'notes'         => (string) ($_POST['notes'] ?? ''),
    ]);

    Roster::replaceCredentials($id, Roster::pairCredentials(
        $_POST['credential_label']  ?? [],
        $_POST['credential_issuer'] ?? []
    ));

    ActivityLog::record('guide.update', 'tour_guide', $id, 'Updated ' . $v->value('full_name'));
    Session::flash('success', 'Saved. The verification page now shows these details.');

    redirect(base_url('/admin/tour-guides/view.php?id=' . $id));
}

$g   = $guide;
$old = old_all();

foreach (array_keys($g) as $key) {
    if (isset($old[$key])) {
        $g[$key] = $old[$key];
    }
}

$isEdit      = true;
$credentials = Roster::credentialsFor($id);

require __DIR__ . '/../_partials/head.php';
require __DIR__ . '/_form.php';
require __DIR__ . '/../_partials/foot.php';
