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

$pageTitle = 'Add Tour Guide';
$pageIcon  = 'fa-user-plus';

if (is_post()) {
    Csrf::verify();

    $v = new Validator($_POST);
    $v->require('full_name')->length('full_name', 2, 160);

    if (trim((string) ($_POST['email'] ?? '')) !== '') {
        $v->email('email');
    }

    /* Normalised the same way every other number in this system is, so the
       office can tap it and the SMS gateway can use it without a second
       opinion about what a Philippine mobile number looks like. Left as typed
       when it will not normalise — a landline is still worth recording. */
    $mobile = trim((string) ($_POST['mobile_number'] ?? ''));

    if ($mobile !== '') {
        $mobile = SmsGateway::normalise($mobile) ?? $mobile;
    }

    /* A card that expired before it was issued is a typo, not a decision. */
    $validUntil = trim((string) ($_POST['valid_until'] ?? ''));

    if ($validUntil !== '' && $validUntil < date('Y-m-d')) {
        $v->addError('valid_until', 'That date has already passed, so the card would be expired the moment it was issued.');
    }

    if ($v->fails()) {
        flash_back($v->errors(), $_POST, 'index.php');
    }

    $photoPath = null;

    if (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $uploader  = new Uploader();
        $photoPath = $uploader->store($_FILES['photo'], 'guides');

        if ($photoPath === null) {
            flash_back(['photo' => $uploader->firstError() ?? 'That picture could not be saved.'], $_POST, 'index.php');
        }
    }

    $id = Roster::create([
        'full_name'     => (string) $v->value('full_name'),
        'address'       => (string) ($_POST['address'] ?? ''),
        'mobile_number' => $mobile,
        'email'         => (string) ($_POST['email'] ?? ''),
        'photo_path'    => $photoPath,
        'status'        => (string) ($_POST['status'] ?? 'active'),
        'valid_until'   => $validUntil,
        'status_note'   => (string) ($_POST['status_note'] ?? ''),
        'notes'         => (string) ($_POST['notes'] ?? ''),
        'created_by'    => Auth::id(),
    ]);

    Roster::replaceCredentials($id, Roster::pairCredentials(
        $_POST['credential_label']  ?? [],
        $_POST['credential_issuer'] ?? []
    ));

    $guide = Roster::find($id);

    ActivityLog::record('guide.create', 'tour_guide', $id,
        'Accredited ' . $v->value('full_name') . ' as ' . ($guide['guide_code'] ?? ''));

    /* Names the next step rather than only announcing the last one. Somebody who
       has just filled in a guide almost always has their certificates in hand,
       and this is the screen with the upload box on it. */
    Session::flash('success',
        $v->value('full_name') . ' was added as ' . ($guide['guide_code'] ?? '')
        . '. You can attach their scanned certificates below.');

    redirect(base_url('/admin/tour-guides/view.php?id=' . $id));
}

$g = array_fill_keys(
    ['id', 'full_name', 'address', 'mobile_number', 'email', 'photo_path',
     'status', 'valid_until', 'status_note', 'notes'],
    ''
);

$g['status'] = 'active';

foreach (array_keys($g) as $key) {
    $old = old_all();

    if (isset($old[$key])) {
        $g[$key] = $old[$key];
    }
}

$isEdit      = false;
$credentials = [];

require __DIR__ . '/../_partials/head.php';
require __DIR__ . '/_form.php';
require __DIR__ . '/../_partials/foot.php';
