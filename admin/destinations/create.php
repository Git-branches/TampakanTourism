<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/_helpers.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Core\Uploader;
use App\Core\Validator;
use App\Repositories\CategoryRepository;
use App\Repositories\DestinationRepository;

Auth::require();

$pageTitle    = 'Add Destination';
$pageIcon     = 'fa-plus';
$pageSubtitle = 'It appears on the public website as soon as it is saved';

$categories = CategoryRepository::all();

if (is_post()) {
    Csrf::verify();

    $v = new Validator($_POST);
    $v->require('name')
      ->length('name', 3, 160)
      ->email('contact_email');

    validate_coordinates($v);

    if ($v->fails()) {
        /* Back to the list, where the form lives in a dialog; it reopens with
           the rejected input still in it. create.php on its own is still the
           same form without the dialog around it. */
        flash_back($v->errors(), $_POST, 'index.php');
    }

    $data = collect_destination_input($v);
    $id   = null;

    try {
        // The destination row and its photos are written together: a
        // half-created destination with orphaned files helps nobody.
        $id = Database::transaction(static function () use ($data) {
            $newId = DestinationRepository::create($data, Auth::id());

            if (!empty($_FILES['photos']['name'][0])) {
                $uploader = new Uploader();
                foreach ($uploader->storeMany($_FILES['photos'], 'destinations') as $path) {
                    DestinationRepository::addPhoto($newId, $path);
                }
            }

            return $newId;
        });
    } catch (Throwable $e) {
        error_log('Destination create failed: ' . $e->getMessage());
        Session::flash('danger', 'The destination could not be saved. Please try again.');
        flash_back([], $_POST, 'index.php');
    }

    ActivityLog::record('destination.create', 'destination', $id, 'Created "' . $data['name'] . '"');
    Session::flash('success', '"' . $data['name'] . '" was created and is now live on the public site.');
    redirect(base_url('/admin/destinations/edit.php?id=' . $id));
}

// Blank values, so the shared form renders without knowing which mode it is in.
$d = array_fill_keys([
    'id', 'name', 'slug', 'category_id', 'short_description', 'description', 'history',
    'cultural_heritage', 'operating_hours', 'entrance_fee', 'facilities', 'reminders',
    'safety_notes', 'barangay', 'address',
    'latitude', 'longitude', 'contact_person', 'contact_phone', 'local_hotline',
    'contact_email', 'is_featured',
], '');

// Repopulate after a validation failure so nothing is retyped.
$old = old_all();
foreach (array_keys($d) as $key) {
    if (isset($old[$key])) {
        $d[$key] = $old[$key];
    }
}

require __DIR__ . '/../_partials/head.php';
require __DIR__ . '/_form.php';
require __DIR__ . '/../_partials/foot.php';
