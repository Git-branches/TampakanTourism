<?php
declare(strict_types=1);

/**
 * TourSync — writing the directions to a destination.                Feature 5
 *
 * One entry per starting point, because that is how directions are actually
 * given. Somebody at the municipal gym and somebody arriving from General
 * Santos need different first sentences, and the office knows both.
 *
 * The map that already exists on the public page answers "where is it".
 * This answers "how do I get there", which is a different question and the one
 * a visitor asks at the junction where the concrete ends.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Uploader;
use App\Repositories\DestinationRepository;
use App\Repositories\RouteRepository as Routes;

Auth::require();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$d  = DestinationRepository::find($id);

if ($d === null) {
    Session::flash('danger', 'That destination no longer exists.');
    redirect(base_url('/admin/destinations/index.php'));
}

if (is_post()) {
    Csrf::verify();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $routeId  = (int) ($_POST['route_id'] ?? 0);
        $landmark = trim((string) ($_POST['from_landmark'] ?? ''));
        $body     = trim((string) ($_POST['directions'] ?? ''));

        /* Both refusals are about what the visitor ends up reading. A route
           with no starting point cannot be followed, and one with no
           directions is a heading with nothing under it. */
        if ($landmark === '' || $body === '') {
            Session::flash('danger', 'A route needs a starting landmark and the directions themselves.');
            redirect(base_url('/admin/destinations/routes.php?id=' . $id));
        }

        $payload = [
            'from_landmark' => $landmark,
            'directions'    => $body,
            'travel_time'   => $_POST['travel_time'] ?? '',
            'distance'      => $_POST['distance']    ?? '',
            'transport'     => $_POST['transport']   ?? '',
            'fare_note'     => $_POST['fare_note']   ?? '',
            'sort_order'    => $_POST['sort_order']  ?? 0,
        ];

        if ($routeId > 0) {
            Routes::update($routeId, $id, $payload);
            ActivityLog::record('destination.route_updated', 'destination', $id, 'Route from ' . $landmark);
            Session::flash('success', 'Route updated.');
        } else {
            Routes::create($id, $payload);
            ActivityLog::record('destination.route_added', 'destination', $id, 'Route from ' . $landmark);
            Session::flash('success', 'Route added.');
        }
    }

    if ($action === 'delete') {
        /* Scoped to this destination inside the repository too — the id in a
           form is not proof of which destination it belongs to. */
        Routes::delete((int) ($_POST['route_id'] ?? 0), $id);
        ActivityLog::record('destination.route_removed', 'destination', $id, 'Route removed');
        Session::flash('success', 'Route removed.');
    }

    if ($action === 'map') {
        if (empty($_FILES['offline_map']['name'])) {
            Session::flash('warning', 'No file was selected.');
        } else {
            $uploader = new Uploader();
            $stored   = $uploader->store($_FILES['offline_map'], 'destinations');

            if ($stored === null) {
                Session::flash('danger', implode(' ', array_unique($uploader->errors())) ?: 'That file was rejected.');
            } else {
                DestinationRepository::setOfflineMap($id, $stored);
                ActivityLog::record('destination.offline_map', 'destination', $id, 'Offline map set for ' . $d['name']);
                Session::flash('success', 'Offline map saved. Visitors can now download it.');
            }
        }
    }

    if ($action === 'map_remove') {
        DestinationRepository::setOfflineMap($id, null);
        Session::flash('success', 'Offline map removed.');
    }

    redirect(base_url('/admin/destinations/routes.php?id=' . $id));
}

$routes = Routes::forDestination($id);
$edit   = null;

if (($eid = (int) ($_GET['edit'] ?? 0)) > 0) {
    $candidate = Routes::find($eid);

    /* Only if it belongs to the destination on screen. */
    if ($candidate !== null && (int) $candidate['destination_id'] === $id) {
        $edit = $candidate;
    }
}

$pageTitle    = 'Directions';
$pageIcon     = 'fa-diamond-turn-right';
$pageSubtitle = $d['name'];

/* Skips the shell when this page was asked for as a dialog fragment.
   Additive: without ?modal=1 nothing here changes at all. */
if (!is_modal_request()) { require __DIR__ . '/../_partials/head.php'; }
?>

<?php
/* .record-bar, not a bare flex row — and the difference matters.
 *
 * These three are the SAME three the destination card already offers: All
 * destinations is the list you are looking at, Edit and Photos are on the card
 * and in its menu. Opened in the dialog they were a second set of the same
 * controls, one press away from the first.
 *
 * The class is what the modal hides, so this bar is for the full page — the
 * address somebody reaches by middle-clicking, or with the script off — and
 * disappears inside the dialog without any rule written for this file. */
?>
<div class="record-bar">
    <a href="index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left"></i> All destinations
    </a>
    <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-pen"></i> Edit details
    </a>
    <a href="photos.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-images"></i> Photos
    </a>
</div>

<?php /* The printable sheet is NOT one of the three: nothing else on the card
         or in its menu offers it, so it stays visible in the dialog. It belongs
         with the route it prints, rather than in a bar of navigation. */ ?>
<p class="mb-3">
    <a href="<?= e(base_url('/directions.php?slug=' . urlencode((string) $d['slug']))) ?>"
       target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-print"></i> Preview the printable sheet
    </a>
</p>

<?php if ($routes === []): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <strong>No directions have been written for <?= e((string) $d['name']) ?>.</strong>
        The public page shows a map pin and nothing else, which does not help anyone
        past the point where the concrete road ends.
    </div>
<?php endif; ?>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-<?= $edit ? 'pen' : 'plus'; ?>"></i>
            <?= $edit ? 'Edit route from ' . e((string) $edit['from_landmark']) : 'Add a route' ?></h2>
        <?php if ($edit): ?>
            <a href="routes.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">Cancel</a>
        <?php endif; ?>
    </header>

    <div class="panel__body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="route_id" value="<?= (int) ($edit['id'] ?? 0) ?>">

            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="from_landmark">
                        Starting from <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control" id="from_landmark" name="from_landmark"
                           maxlength="160" required
                           placeholder="Tampakan Municipal Gym / Tampakan National High School / General Santos City"
                           value="<?= e((string) ($edit['from_landmark'] ?? '')) ?>">
                    <p class="form-text">
                        A place a stranger can find and a tricycle driver knows by name.
                    </p>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="sort_order">Order</label>
                    <input type="number" class="form-control" id="sort_order" name="sort_order"
                           min="0" max="999" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
                    <p class="form-text">Lower shows first.</p>
                </div>

                <div class="col-12">
                    <label class="form-label" for="directions">
                        The directions <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control" id="directions" name="directions" rows="6" required
                              placeholder="Head south on the national highway. Pass the National High School on your right. Turn left at the covered court in Purok 3 and follow the concrete road for about 2 km until it becomes gravel. The entrance is on the right, after the second bamboo bridge."><?= e((string) ($edit['directions'] ?? '')) ?></textarea>
                    <p class="form-text">
                        Write it the way you would say it out loud &mdash; puroks, barangays, schools,
                        the covered court, the bridge. A visitor reads this where there is no signal.
                    </p>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="travel_time">Travel time</label>
                    <input type="text" class="form-control" id="travel_time" name="travel_time"
                           maxlength="60" placeholder="25-30 minutes"
                           value="<?= e((string) ($edit['travel_time'] ?? '')) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="distance">Distance</label>
                    <input type="text" class="form-control" id="distance" name="distance"
                           maxlength="60" placeholder="about 8 km"
                           value="<?= e((string) ($edit['distance'] ?? '')) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="transport">How to travel</label>
                    <input type="text" class="form-control" id="transport" name="transport"
                           maxlength="160" placeholder="Tricycle, habal-habal, or private vehicle"
                           value="<?= e((string) ($edit['transport'] ?? '')) ?>">
                </div>

                <div class="col-12">
                    <label class="form-label" for="fare_note">Fare</label>
                    <input type="text" class="form-control" id="fare_note" name="fare_note"
                           maxlength="160" placeholder="Around P50 per person by habal-habal (2026 rate)"
                           value="<?= e((string) ($edit['fare_note'] ?? '')) ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-brand mt-3">
                <i class="fa-solid fa-floppy-disk"></i> <?= $edit ? 'Save changes' : 'Add route' ?>
            </button>
        </form>
    </div>
</section>

<?php if ($routes !== []): ?>
<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-route"></i> <?= n(count($routes)) ?> route(s)</h2>
    </header>

    <div class="panel__body">
        <?php foreach ($routes as $r): ?>
            <div class="border rounded p-3 mb-3">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>
                        <strong><i class="fa-solid fa-location-dot text-muted"></i>
                            From <?= e((string) $r['from_landmark']) ?></strong>
                        <p class="text-muted small mb-2">
                            <?= $r['travel_time'] ? e((string) $r['travel_time']) : 'no time given' ?>
                            <?php if ($r['distance']): ?>&middot; <?= e((string) $r['distance']) ?><?php endif; ?>
                            <?php if ($r['transport']): ?>&middot; <?= e((string) $r['transport']) ?><?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="routes.php?id=<?= $id ?>&edit=<?= (int) $r['id'] ?>"
                           class="btn btn-sm btn-outline-secondary">
                            <i class="fa-solid fa-pen"></i> Edit
                        </a>
                        <form method="post" data-confirm="Remove this route?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="route_id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" aria-label="Remove this route">
                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <p class="mb-0"><?= nl2br(e((string) $r['directions'])) ?></p>
                <?php if ($r['fare_note']): ?>
                    <p class="text-muted small mb-0 mt-2">
                        <i class="fa-solid fa-coins"></i> <?= e((string) $r['fare_note']) ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="panel">
    <header class="panel__head">
        <h2><i class="fa-solid fa-map"></i> Offline map</h2>
    </header>

    <div class="panel__body">
        <?php /* An uploaded image rather than a generated one. The map that
                 helps at the last junction is often a sketch of a trail fork
                 that no tile server has surveyed, and the office is the only
                 party that can draw it. */ ?>
        <p class="text-muted">
            A picture a visitor can save to their phone before they lose signal &mdash; a photographed
            sketch map, a screenshot with the turning marked, or a scan of a printed one.
        </p>

        <?php if ($d['offline_map_image']): ?>
            <img src="<?= e(base_url('/' . $d['offline_map_image'])) ?>" alt="Offline map for <?= e((string) $d['name']) ?>"
                 class="img-fluid rounded border mb-3" style="max-height: 320px">
            <form method="post" class="mb-3" data-confirm="Remove the offline map?">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="map_remove">
                <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i> Remove</button>
            </form>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="map">
            <div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label class="form-label" for="offline_map">Choose an image</label>
                    <input type="file" class="form-control" id="offline_map" name="offline_map"
                           accept="image/jpeg,image/png,image/webp"
                           data-max-mb="<?= n(upload_limit_mb()) ?>">
                </div>
                <div class="col-md-4">
                    <button class="btn btn-brand w-100">
                        <i class="fa-solid fa-upload"></i> <?= $d['offline_map_image'] ? 'Replace' : 'Upload' ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</section>

<?php if (!is_modal_request()) { require __DIR__ . '/../_partials/foot.php'; } ?>
