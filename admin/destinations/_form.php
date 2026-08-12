<?php
/**
 * Shared destination form, used by both create.php and edit.php.
 *
 * Expects $d — an associative array of current values. For a new destination
 * create.php supplies an array of empty strings, so this file never needs to
 * know which mode it is in.
 */

use App\Repositories\DestinationRepository;

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

$facilityList = implode(', ', DestinationRepository::decodeFacilities($d['facilities'] ?? null));
$isEdit = !empty($d['id']);
?>

<form method="post" enctype="multipart/form-data" class="form-grid" novalidate>
    <?= csrf_field() ?>

    <!-- ================= BASICS ================= -->
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-circle-info"></i> Basic Information</h2></header>
        <div class="panel__body">

            <div class="row g-3">
                <div class="col-md-8">
                    <label for="name" class="form-label">Destination name <span class="req">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="160"
                           class="form-control <?= has_error('name') ? 'is-invalid' : '' ?>"
                           value="<?= e((string) ($d['name'] ?? '')) ?>"
                           placeholder="e.g. Mt. Matutum Viewpoint">
                    <?php if (has_error('name')): ?>
                        <div class="field-error"><?= e(error_for('name')) ?></div>
                    <?php endif; ?>
                    <?php if ($isEdit): ?>
                        <p class="field-hint">
                            Public address: <code>/destination.php?slug=<?= e($d['slug']) ?></code>
                            — this stays fixed when the name changes, so existing links keep working.
                        </p>
                    <?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label for="category_id" class="form-label">Category</label>
                    <select id="category_id" name="category_id" class="form-select">
                        <option value="">— none —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"
                                <?= (int) ($d['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label for="short_description" class="form-label">Short description</label>
                    <input type="text" id="short_description" name="short_description" maxlength="300"
                           class="form-control" value="<?= e((string) ($d['short_description'] ?? '')) ?>"
                           placeholder="One sentence — this is what appears on cards and in search results">
                    <p class="field-hint">Shown on the destination card and the map popup. Keep it under 160 characters.</p>
                </div>

                <div class="col-12">
                    <label for="description" class="form-label">Full description</label>
                    <textarea id="description" name="description" rows="5" class="form-control"
                              placeholder="What a visitor sees, does, and should know"><?= e((string) ($d['description'] ?? '')) ?></textarea>
                </div>

                <div class="col-12">
                    <label for="history" class="form-label">Historical background</label>
                    <textarea id="history" name="history" rows="4" class="form-control"
                              placeholder="Origin of the name, cultural significance, notable events"><?= e((string) ($d['history'] ?? '')) ?></textarea>
                    <p class="field-hint">Appears on the QR information page — the screen a tourist reads while standing there.</p>
                </div>

                <div class="col-12">
                    <label for="cultural_heritage" class="form-label">Cultural heritage</label>
                    <textarea id="cultural_heritage" name="cultural_heritage" rows="4" class="form-control"
                              placeholder="What this place means to the community — traditions, beliefs, indigenous significance, how it should be treated"><?= e((string) ($d['cultural_heritage'] ?? '')) ?></textarea>
                    <p class="field-hint">
                        One of the three things the QR sign carries. Written for a visitor standing at the
                        site, not for a brochure.
                    </p>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="is_featured" name="is_featured" value="1"
                            <?= !empty($d['is_featured']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_featured">
                            Feature on the homepage
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= VISITOR INFORMATION ================= -->
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-clipboard-list"></i> Visitor Information</h2></header>
        <div class="panel__body">
            <div class="row g-3">

                <div class="col-md-6">
                    <label for="operating_hours" class="form-label">Operating hours</label>
                    <input type="text" id="operating_hours" name="operating_hours" maxlength="160" class="form-control"
                           value="<?= e((string) ($d['operating_hours'] ?? '')) ?>"
                           placeholder="e.g. Daily, 6:00 AM – 5:00 PM">
                </div>

                <div class="col-md-6">
                    <label for="entrance_fee" class="form-label">Entrance fee</label>
                    <input type="text" id="entrance_fee" name="entrance_fee" maxlength="120" class="form-control"
                           value="<?= e((string) ($d['entrance_fee'] ?? '')) ?>"
                           placeholder="e.g. Free, or PHP 30 per person">
                </div>

                <div class="col-12">
                    <label for="facilities" class="form-label">Facilities and amenities</label>
                    <input type="text" id="facilities" name="facilities" class="form-control"
                           value="<?= e($facilityList) ?>"
                           placeholder="Parking, Restroom, Guide, Cottages, Water Source">
                    <p class="field-hint">Separate each with a comma. They display as individual tags.</p>
                </div>

                <div class="col-12">
                    <label for="reminders" class="form-label">Important reminders</label>
                    <textarea id="reminders" name="reminders" rows="3" class="form-control"
                              placeholder="Safety notes, what to bring, cultural protocols to observe"><?= e((string) ($d['reminders'] ?? '')) ?></textarea>
                </div>

                <div class="col-12">
                    <label for="safety_notes" class="form-label">Site-specific hazards</label>
                    <textarea id="safety_notes" name="safety_notes" rows="3" class="form-control"
                              placeholder="e.g. the rocks below the second tier are slippery after rain; no swimming when the water is brown"><?= e((string) ($d['safety_notes'] ?? '')) ?></textarea>
                    <p class="field-hint">
                        Shown in a warning box directly under the emergency numbers on the QR page.
                        Leave blank unless this site has a real hazard &mdash; a warning on every
                        destination is a warning nobody reads.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= LOCATION ================= -->
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-map-location-dot"></i> Location</h2></header>
        <div class="panel__body">
            <div class="row g-3">

                <div class="col-md-4">
                    <label for="barangay" class="form-label">Barangay</label>
                    <input type="text" id="barangay" name="barangay" maxlength="120" class="form-control"
                           value="<?= e((string) ($d['barangay'] ?? '')) ?>" placeholder="e.g. Danlag">
                </div>

                <div class="col-md-8">
                    <label for="address" class="form-label">Address or directions</label>
                    <input type="text" id="address" name="address" maxlength="255" class="form-control"
                           value="<?= e((string) ($d['address'] ?? '')) ?>"
                           placeholder="Sitio, landmark, or how to reach the site">
                </div>

                <div class="col-12">
                    <label class="form-label">Map coordinates</label>
                    <p class="field-hint mb-2">
                        Click the map to drop the pin, or type the values directly. Accurate coordinates
                        matter — the public map and the visitor's directions link both read them.
                    </p>
                    <div id="pickerMap" class="picker-map"
                         data-lat="<?= e((string) ($d['latitude'] ?? '')) ?>"
                         data-lng="<?= e((string) ($d['longitude'] ?? '')) ?>"></div>
                </div>

                <div class="col-md-6">
                    <label for="latitude" class="form-label">Latitude</label>
                    <input type="text" id="latitude" name="latitude" class="form-control"
                           value="<?= e((string) ($d['latitude'] ?? '')) ?>" placeholder="6.4333">
                    <?php if (has_error('latitude')): ?>
                        <div class="field-error"><?= e(error_for('latitude')) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label for="longitude" class="form-label">Longitude</label>
                    <input type="text" id="longitude" name="longitude" class="form-control"
                           value="<?= e((string) ($d['longitude'] ?? '')) ?>" placeholder="124.9167">
                    <?php if (has_error('longitude')): ?>
                        <div class="field-error"><?= e(error_for('longitude')) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= CONTACT ================= -->
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-address-book"></i> Site Contact</h2></header>
        <div class="panel__body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="contact_person" class="form-label">Contact person</label>
                    <input type="text" id="contact_person" name="contact_person" maxlength="120" class="form-control"
                           value="<?= e((string) ($d['contact_person'] ?? '')) ?>">
                </div>
                <div class="col-md-4">
                    <label for="contact_phone" class="form-label">Phone</label>
                    <input type="text" id="contact_phone" name="contact_phone" maxlength="40" class="form-control"
                           value="<?= e((string) ($d['contact_phone'] ?? '')) ?>" placeholder="0917 123 4567">
                    <p class="field-hint">Shown as a tap-to-call number on the QR page.</p>
                </div>

                <div class="col-md-4">
                    <label for="local_hotline" class="form-label">On-site emergency number</label>
                    <input type="text" id="local_hotline" name="local_hotline" maxlength="120" class="form-control"
                           value="<?= e((string) ($d['local_hotline'] ?? '')) ?>" placeholder="0917 123 4567">
                    <p class="field-hint">
                        A number reachable AT this site &mdash; the guardhouse, the barangay, the nearest
                        rescue post. Listed above the municipal hotlines, because somebody two hundred
                        metres away is more use than somebody in town.
                    </p>
                </div>
                <div class="col-md-4">
                    <label for="contact_email" class="form-label">Email</label>
                    <input type="email" id="contact_email" name="contact_email" maxlength="160" class="form-control <?= has_error('contact_email') ? 'is-invalid' : '' ?>"
                           value="<?= e((string) ($d['contact_email'] ?? '')) ?>">
                    <?php if (has_error('contact_email')): ?>
                        <div class="field-error"><?= e(error_for('contact_email')) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <?php if (!$isEdit): ?>
    <!-- ================= PHOTOS (create only) ================= -->
    <section class="panel">
        <header class="panel__head"><h2><i class="fa-solid fa-images"></i> Photos</h2></header>
        <div class="panel__body">
            <label for="photos" class="form-label">Upload photos</label>
            <input type="file" id="photos" name="photos[]" multiple accept="image/jpeg,image/png,image/webp"
                   class="form-control">
            <p class="field-hint">
                JPG, PNG, or WebP up to 5 MB each. The first becomes the cover photo; you can change
                that afterwards. Images are re-encoded and resized on upload.
            </p>
        </div>
    </section>
    <?php endif; ?>

    <div class="form-actions">
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-brand">
            <i class="fa-solid fa-floppy-disk"></i>
            <?= $isEdit ? 'Save Changes' : 'Create Destination' ?>
        </button>
    </div>
</form>

<link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
/* Coordinate picker. Centres on Tampakan when the destination has no
   coordinates yet, so the officer starts in the right municipality. */
(function () {
    const el = document.getElementById('pickerMap');
    if (!el || typeof L === 'undefined') return;

    const latField = document.getElementById('latitude');
    const lngField = document.getElementById('longitude');

    const startLat = parseFloat(el.dataset.lat) || 6.4333;
    const startLng = parseFloat(el.dataset.lng) || 124.9167;
    const hasPin   = el.dataset.lat !== '' && el.dataset.lng !== '';

    const map = L.map(el).setView([startLat, startLng], hasPin ? 14 : 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    let marker = hasPin ? L.marker([startLat, startLng], { draggable: true }).addTo(map) : null;

    function setPin(lat, lng) {
        latField.value = lat.toFixed(7);
        lngField.value = lng.toFixed(7);
    }

    if (marker) {
        marker.on('dragend', () => {
            const p = marker.getLatLng();
            setPin(p.lat, p.lng);
        });
    }

    map.on('click', (e) => {
        if (marker) {
            marker.setLatLng(e.latlng);
        } else {
            marker = L.marker(e.latlng, { draggable: true }).addTo(map);
            marker.on('dragend', () => {
                const p = marker.getLatLng();
                setPin(p.lat, p.lng);
            });
        }
        setPin(e.latlng.lat, e.latlng.lng);
    });

    // Typing coordinates moves the pin, so both directions stay in sync.
    [latField, lngField].forEach((field) => {
        field.addEventListener('change', () => {
            const lat = parseFloat(latField.value);
            const lng = parseFloat(lngField.value);
            if (Number.isNaN(lat) || Number.isNaN(lng)) return;

            if (marker) { marker.setLatLng([lat, lng]); }
            else { marker = L.marker([lat, lng], { draggable: true }).addTo(map); }
            map.setView([lat, lng], 15);
        });
    });
})();
</script>
