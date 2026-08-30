<?php
declare(strict_types=1);

/**
 * TourSync — destination listing (admin).
 *
 * Feature 5 / Problem 4: the one place destination information is maintained.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/_helpers.php';

use App\Core\Paginator;
use App\Core\Auth;
use App\Repositories\CategoryRepository;
use App\Repositories\DestinationRepository;

Auth::require();

$pageTitle    = 'Destinations';
$pageIcon     = 'fa-mountain-sun';
$pageSubtitle = 'Every tourist destination in the municipality';

$filters = [
    'search'      => trim((string) ($_GET['q'] ?? '')),
    'category_id' => (int) ($_GET['category'] ?? 0) ?: null,
    'status'      => in_array($_GET['status'] ?? '', ['active', 'archived', 'all'], true)
                        ? $_GET['status'] : 'active',
    'sort'        => (string) ($_GET['sort'] ?? 'created_at'),
    'dir'         => (string) ($_GET['dir'] ?? 'desc'),
];

$result     = DestinationRepository::paginate($filters, (int) ($_GET['page'] ?? 1), Paginator::PER_PAGE);
$pager      = Paginator::adopt($result);
$categories = CategoryRepository::all();

/* One query for the whole page rather than one per row — twenty destinations
   should not mean twenty round trips to draw a badge. */
$heritageCounts = \App\Repositories\HeritageRepository::countsByDestination();

/* NOT $d. The table below walks the list with `foreach ($result['rows'] as $d)`,
   and the dialog is rendered after it — so a variable called $d here would hold
   the last destination on the page by the time the form read it, and the
   rejected input would come back as somebody else's record. */
$sheetDestination = array_fill_keys([
    'id', 'name', 'slug', 'category_id', 'short_description', 'description', 'history',
    'cultural_heritage', 'operating_hours', 'entrance_fee', 'facilities', 'reminders',
    'safety_notes', 'barangay', 'address',
    'latitude', 'longitude', 'contact_person', 'contact_phone', 'local_hotline',
    'contact_email', 'is_featured',
], '');

foreach (array_keys($sheetDestination) as $key) {
    $rejected = old_all();
    if (isset($rejected[$key])) { $sheetDestination[$key] = $rejected[$key]; }
}

$sheetOpen = old_all() !== [];

$counts = [
    'active'   => (int) App\Core\Database::scalar("SELECT COUNT(*) FROM destinations WHERE status = 'active'"),
    'archived' => (int) App\Core\Database::scalar("SELECT COUNT(*) FROM destinations WHERE status = 'archived'"),
];

/** Rebuilds the current query string with one value replaced. */
function filter_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
}

require __DIR__ . '/../_partials/head.php';
?>

<div class="toolbar">
    <form class="toolbar__filters" method="get">
        <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($filters['search']) ?>"
                   placeholder="Search by name, barangay, or description">
        </div>

        <select name="category" class="form-select form-select-sm">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $filters['category_id'] === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status" class="form-select form-select-sm">
            <option value="active"   <?= $filters['status'] === 'active'   ? 'selected' : '' ?>>Active (<?= $counts['active'] ?>)</option>
            <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>>Archived (<?= $counts['archived'] ?>)</option>
            <option value="all"      <?= $filters['status'] === 'all'      ? 'selected' : '' ?>>All</option>
        </select>

        <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
        <?php if ($filters['search'] || $filters['category_id'] || $filters['status'] !== 'active'): ?>
            <a href="index.php" class="btn btn-sm btn-link">Clear</a>
        <?php endif; ?>
    </form>

    <button type="button" class="btn btn-brand btn-sm" data-dialog="addDestination">
        <i class="fa-solid fa-plus"></i> Add Destination
    </button>
</div>

<?php if ($result['rows'] === []): ?>

    <div class="panel">
        <div class="panel__body">
            <div class="empty">
                <i class="fa-solid fa-mountain-sun"></i>
                <?php if ($filters['search'] || $filters['category_id']): ?>
                    <p><strong>No destinations match those filters.</strong></p>
                    <p><a href="index.php">Clear the filters</a> to see everything.</p>
                <?php else: ?>
                    <p><strong>No destinations registered yet.</strong></p>
                    <p>Add the first one and it appears on the public website immediately —
                       no code change, no separate upload.</p>
                    <p class="mt-3">
                        <button type="button" class="btn btn-brand btn-sm" data-dialog="addDestination">
                            <i class="fa-solid fa-plus"></i> Add the first destination
                        </button>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php else: ?>

    <p class="result-count">
        <?= n($result['total']) ?> destination<?= $result['total'] === 1 ? '' : 's' ?>
        <?= $filters['status'] !== 'all' ? '(' . e($filters['status']) . ')' : '' ?>
    </p>

    <div class="dest-grid">
        <?php foreach ($result['rows'] as $d): ?>
            <article class="dest-tile <?= $d['status'] === 'archived' ? 'is-archived' : '' ?>">

                <div class="dest-tile__media">
                    <?php if (!empty($d['cover_photo'])): ?>
                        <img src="<?= e(base_url($d['cover_photo'])) ?>" alt="<?= e($d['name']) ?>" loading="lazy">
                    <?php else: ?>
                        <div class="dest-tile__placeholder"><i class="fa-solid fa-image"></i><span>No photo</span></div>
                    <?php endif; ?>

                    <?php if ($d['status'] === 'archived'): ?>
                        <span class="dest-tile__flag dest-tile__flag--archived">Archived</span>
                    <?php elseif ((int) $d['is_featured'] === 1): ?>
                        <span class="dest-tile__flag dest-tile__flag--featured"><i class="fa-solid fa-star"></i> Featured</span>
                    <?php endif; ?>
                </div>

                <div class="dest-tile__body">
                    <?php if (!empty($d['category_name'])): ?>
                        <span class="tag"><?= e($d['category_name']) ?></span>
                    <?php endif; ?>

                    <h3><?= e($d['name']) ?></h3>

                    <p class="dest-tile__meta">
                        <i class="fa-solid fa-location-dot"></i>
                        <?= e($d['barangay'] ?: 'Barangay not set') ?>
                    </p>

                    <p class="dest-tile__excerpt">
                        <?= e($d['short_description'] ?: 'No short description yet.') ?>
                    </p>

                    <?php
                    /* THE NUMBERS SAY WHAT THEY ARE NOW.
                     *
                     * This row was three icons and three bare values — a person
                     * glyph, a picture glyph, and the word "Set". The mockup that
                     * asked for this card read them as "Reviews" and "Set Gallery".
                     * Both readings are wrong: the first is recorded visitors and
                     * the third is whether the destination has map coordinates.
                     *
                     * That is the argument for labelling them. Somebody looking
                     * carefully enough to redraw the screen still guessed wrong
                     * twice, and they had the whole page in front of them. */
                    $hasPin = $d['latitude'] !== null && $d['longitude'] !== null;
                    ?>
                    <div class="dest-tile__stats">
                        <span>
                            <i class="fa-solid fa-users" aria-hidden="true"></i>
                            <strong><?= n($d['visitors']) ?></strong>
                            <small>Visitors</small>
                        </span>
                        <span>
                            <i class="fa-solid fa-image" aria-hidden="true"></i>
                            <strong><?= n($d['photo_count']) ?></strong>
                            <small>Photos</small>
                        </span>
                        <span class="<?= $hasPin ? '' : 'is-missing' ?>">
                            <i class="fa-solid fa-map-pin" aria-hidden="true"></i>
                            <strong><?= $hasPin ? 'Set' : 'None' ?></strong>
                            <small>Map pin</small>
                        </span>
                    </div>
                </div>

                <?php
                /* FOUR EQUAL BUTTONS, and the labels are wrapped so they can be
                   dropped without touching the icons.
                 *
                 * They used to be four plain buttons of whatever width their
                 * own text needed, inside a 268px column. They did not fit, so
                 * each label wrapped under its icon and the row became two
                 * lines of ragged, differently-sized controls — with the fourth
                 * button carrying no label at all, which made it look like a
                 * different kind of thing from the three beside it.
                 *
                 * Every title= is set whether or not the label is showing, so
                 * the meaning survives at the widths where the text does not. */
                ?>
                <?php
                /* ONE PRIMARY, ONE SECONDARY, AND THE REST BEHIND A MENU.
                 *
                 * There were five equal buttons in a 268px column. They did not
                 * fit, so CSS dropped every label and left five unlabelled icons
                 * — a pen, two pictures, a signpost and a little building — and
                 * the officer had to hover each one to find out what it was.
                 *
                 * Trying to fit five labels was the wrong problem. Only two of
                 * the five are used often: opening the public page to check it,
                 * and editing. Those keep their words. Photos, Route and
                 * Heritage go behind a menu, where they can be spelled out and
                 * carry their counts.
                 *
                 * The heart in the mockup is not here. On a public card it means
                 * "save this place"; on the office's own list it would mean
                 * nothing that "Featured" does not already say. */
                $canView = $d['status'] === 'active';
                ?>
                <div class="dest-tile__actions">
                    <?php if ($canView): ?>
                        <a href="<?= e(base_url('/destination.php?slug=' . $d['slug'])) ?>"
                           target="_blank" rel="noopener" class="btn btn-sm btn-brand dest-tile__go">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            View Destination
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    <?php else: ?>
                        <?php /* Archived pages 404. The slot is held rather than
                                 left to stretch the other two, and it says why. */ ?>
                        <span class="btn btn-sm btn-outline-secondary is-disabled dest-tile__go"
                              aria-disabled="true" title="Archived — this has no public page">
                            <i class="fa-solid fa-eye-slash" aria-hidden="true"></i>
                            Not public
                        </span>
                    <?php endif; ?>

                    <?php /* Still a real href. The script intercepts a plain click and
                             opens the dialog; a middle-click, Ctrl-click or "open in
                             new tab" falls through to the page it always went to. */ ?>
                    <a href="edit.php?id=<?= (int) $d['id'] ?>" class="btn btn-sm btn-outline-secondary"
                       data-modal-page data-modal-title="Edit <?= e($d['name']) ?>">
                        <i class="fa-solid fa-pen" aria-hidden="true"></i> Edit
                    </a>

                    <?php $menuId = 'destMenu' . (int) $d['id']; ?>
                    <div class="card-menu">
                        <button type="button" class="btn btn-sm btn-outline-secondary card-menu__toggle"
                                data-card-menu="<?= e($menuId) ?>"
                                aria-haspopup="true" aria-expanded="false"
                                aria-label="More for <?= e($d['name']) ?>">
                            <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                        </button>

                        <div class="card-menu__panel" id="<?= e($menuId) ?>" hidden>
                            <a href="photos.php?id=<?= (int) $d['id'] ?>"
                               data-modal-page data-modal-title="Photos &mdash; <?= e($d['name']) ?>">
                                <i class="fa-solid fa-images" aria-hidden="true"></i>
                                Photos
                                <span class="card-menu__count"><?= n($d['photo_count']) ?></span>
                            </a>
                            <a href="routes.php?id=<?= (int) $d['id'] ?>"
                               data-modal-page data-modal-title="Route &mdash; <?= e($d['name']) ?>">
                                <i class="fa-solid fa-diamond-turn-right" aria-hidden="true"></i>
                                Route
                            </a>
                            <a href="heritage.php?id=<?= (int) $d['id'] ?>"
                               data-modal-page data-modal-title="Heritage &mdash; <?= e($d['name']) ?>">
                                <i class="fa-solid fa-landmark-dome" aria-hidden="true"></i>
                                Heritage
                                <?php if (($heritageCounts[(int) $d['id']] ?? 0) > 0): ?>
                                    <span class="card-menu__count"><?= n($heritageCounts[(int) $d['id']]) ?></span>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php require __DIR__ . '/../../app/views/partials/pager.php'; ?>

<?php endif; ?>

<?php /* The list's own copy of the form. Same _form.php create.php and edit.php
         use, so a field added there appears here without anyone remembering to.
         Rendered last, which is why the values are held in $sheetDestination
         until now — the table above walks the list in $d. */ ?>
<?php
/* ONE DIALOG, FILLED WHEN IT OPENS.
 *
 * Edit, Photos, Route and Heritage now open here instead of navigating away.
 * The dialog is empty until a button is pressed; then it fetches that page with
 * ?modal=1, which makes the page render its own body and skip the shell.
 *
 * WHY FETCH RATHER THAN RENDER THEM INLINE. The house pattern elsewhere is to
 * extract a partial and include it — that is what the tour guide ID card does,
 * because X-Frame-Options is DENY and a dialog cannot iframe an admin page. It
 * works there because there is one card. It does not work here: _form.php gives
 * its fields fixed ids, so rendering it once per destination would put twelve
 * elements called id="name" on one page and break every <label for> on it.
 * Fixing that would mean rewriting a form that works.
 *
 * Nothing about those four pages changed except that they skip the shell when
 * asked to. Every existing link to them still opens the full page.
 *
 * WHY THIS SITS ABOVE THE ADD SHEET. Edit's fragment is _form.php, and the Add
 * sheet below is the same _form.php — so while Edit is open the page really does
 * hold two elements called id="pickerMap", id="latitude", id="name". The
 * injected script calls document.getElementById, which answers with whichever
 * comes FIRST in the document; being above the Add sheet is what makes that the
 * Edit copy. The script clears this body on close, so the duplicates exist only
 * while the dialog is open and the Add sheet is shut. */
?>
<dialog class="sheet sheet--wide" id="destPageModal">
    <header class="sheet__head">
        <h2 id="destPageModalTitle"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Loading&hellip;</h2>
        <button type="button" class="sheet__close" data-dialog-close aria-label="Close">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
    </header>

    <div class="sheet__body" id="destPageModalBody"></div>
</dialog>

<dialog class="sheet sheet--wide" id="addDestination"<?= $sheetOpen ? ' data-open' : '' ?>>
    <?php $inSheet = true; $d = $sheetDestination; require __DIR__ . '/_form.php'; ?>
</dialog>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
