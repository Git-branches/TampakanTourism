<?php
declare(strict_types=1);

/**
 * TourSync — the Archive.        Officer only.
 *
 * WHY THIS PAGE EXISTS. Half a dozen screens could take something out of
 * service — archive a destination, archive an event, remove a compliance
 * requirement, deactivate a manager or an officer account, suspend a guide,
 * hide a review — and none of them could show what had been taken out. The
 * office had an Archive button and no archive: once a destination was
 * archived it left the list, and the only way back was to know it existed and
 * to change the filter on the page it had left.
 *
 * So everything withdrawn, in one place, with the way back beside it. Nothing
 * here is deleted and nothing here is new state: each row is a record whose own
 * table already says it is out of service, read through the repository that
 * owns it.
 *
 * NOT A RECYCLE BIN FOR PERMANENT DELETES. Where the system genuinely deletes —
 * an unused destination, a spam message, a dismissed alert — the row is gone
 * and cannot be listed. Those deletions are refused whenever anything depends
 * on the record (see DestinationRepository::dependents() and the delete rules
 * beside each one), so what can be permanently deleted is exactly what has
 * nothing to restore.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Repositories\AnnouncementRepository;
use App\Repositories\DestinationRepository;
use App\Repositories\InspectionRepository;
use App\Repositories\ManagerRepository;

Auth::require('officer');

$back = base_url('/admin/settings/archive.php');

if (is_post()) {
    Csrf::verify();

    $what = (string) ($_POST['what'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);

    if ($id <= 0) {
        Session::flash('danger', 'Nothing was named to restore.');
        redirect($back);
    }

    /* One switch, one restore each, every one of them the module's own method
       rather than an UPDATE written again here. */
    switch ($what) {
        case 'destination':
            $row = DestinationRepository::find($id);
            if ($row !== null) {
                DestinationRepository::setStatus($id, 'active');
                ActivityLog::record('destination.restore', 'destination', $id, 'Restored "' . $row['name'] . '" from the archive');
                Session::flash('success', '"' . $row['name'] . '" is public again.');
            }
            break;

        case 'announcement':
            $row = AnnouncementRepository::find($id);
            if ($row !== null) {
                /* Back to DRAFT, never straight to published: an event archived
                   last year would otherwise reappear on the homepage the
                   instant it was restored, with its old date on it. */
                AnnouncementRepository::setStatus($id, 'draft');
                ActivityLog::record('announcement.restore', 'announcement', $id, 'Restored "' . $row['title'] . '" as a draft');
                Session::flash('success', '"' . $row['title'] . '" was restored as a draft. Publish it when the details are right.');
            }
            break;

        case 'requirement':
            $row = InspectionRepository::findRequirement($id);
            if ($row !== null) {
                InspectionRepository::saveRequirement([
                    'title'       => $row['title'],
                    'guidance'    => (string) ($row['guidance'] ?? ''),
                    'is_required' => (int) $row['is_required'] === 1,
                    'is_active'   => true,
                    'sort_order'  => (int) $row['sort_order'],
                    'min_photos'  => (int) ($row['min_photos'] ?? 1),
                    'max_photos'  => (int) ($row['max_photos'] ?? 2),
                ], $id, (int) Auth::id());

                $told = InspectionRepository::announceRequirement($id, (string) $row['title'], true);
                ActivityLog::record('inspection.requirement_restored', 'inspection_requirement', $id, 'Restored: ' . $row['title']);
                Session::flash('success', '"' . $row['title'] . '" is back on every checklist. ' . n($told) . ' destination(s) were notified.');
            }
            break;

        case 'manager':
            $row = ManagerRepository::find($id);
            if ($row !== null) {
                ManagerRepository::setActive($id, true);
                ActivityLog::record('manager.activate', 'manager', $id, 'Reactivated ' . $row['full_name'] . ' from the archive');
                Session::flash('success', $row['full_name'] . ' can sign in again and will receive notices.');
            }
            break;

        case 'account':
            $row = Database::first('SELECT id, full_name FROM admins WHERE id = ?', [$id]);
            if ($row !== null) {
                Database::run('UPDATE admins SET is_active = 1 WHERE id = ?', [$id]);
                ActivityLog::record('account.activate', 'admin', $id, 'Reactivated ' . $row['full_name'] . ' from the archive');
                Session::flash('success', $row['full_name'] . ' can sign in again.');
            }
            break;

        case 'guide':
            $row = Database::first('SELECT id, full_name FROM tour_guides WHERE id = ?', [$id]);
            if ($row !== null) {
                Database::run("UPDATE tour_guides SET status = 'active' WHERE id = ?", [$id]);
                ActivityLog::record('guide.restore', 'tour_guide', $id, 'Returned ' . $row['full_name'] . ' to the roster');
                Session::flash('success', $row['full_name'] . ' is back on the accredited roster.');
            }
            break;

        case 'feedback':
            $row = Database::first('SELECT id FROM feedback WHERE id = ?', [$id]);
            if ($row !== null) {
                Database::run("UPDATE feedback SET status = 'published' WHERE id = ?", [$id]);
                ActivityLog::record('feedback.restore', 'feedback', $id, 'Published a hidden review');
                Session::flash('success', 'That review is on the destination page again.');
            }
            break;

        case 'guide_review':
            $row = Database::first('SELECT id FROM guide_reviews WHERE id = ?', [$id]);
            if ($row !== null) {
                Database::run("UPDATE guide_reviews SET status = 'published' WHERE id = ?", [$id]);
                ActivityLog::record('guide_review.restore', 'guide_review', $id, 'Published a hidden guide rating');
                Session::flash('success', 'That rating is visible again.');
            }
            break;

        default:
            Session::flash('danger', 'That is not something this page restores.');
    }

    redirect($back);
}

/* ---------------------------------------------------------------------------
   What is currently out of service
   ---------------------------------------------------------------------------
   Each group: what it is, why it is out, and what Restore does. Read straight
   from the tables that already hold the state — there is no separate archive
   table to drift out of step with them.
   ------------------------------------------------------------------------ */

$groups = [];

$groups[] = [
    'key'   => 'destination',
    'icon'  => 'fa-mountain-sun',
    'title' => 'Destinations',
    'lede'  => 'Off the public site and off the map. Every arrival, report and inspection they hold is kept.',
    'back'  => 'Restore puts it back on the public site.',
    'rows'  => array_map(
        static fn (array $r): array => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['name'],
            'note' => trim((string) ($r['barangay'] ?? '')) !== '' ? 'Barangay ' . $r['barangay'] : '',
            'when' => (string) $r['updated_at'],
        ],
        Database::all("SELECT id, name, barangay, updated_at FROM destinations WHERE status = 'archived' ORDER BY name")
    ),
];

$groups[] = [
    'key'   => 'announcement',
    'icon'  => 'fa-bullhorn',
    'title' => 'Announcements &amp; Events',
    'lede'  => 'Withdrawn from the website. Their delivery records are kept.',
    'back'  => 'Restore brings it back as a draft, so the date can be checked before it is published.',
    'rows'  => array_map(
        static fn (array $r): array => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['title'],
            'note' => AnnouncementRepository::TYPES[$r['type']] ?? (string) $r['type'],
            'when' => (string) $r['updated_at'],
        ],
        Database::all("SELECT id, title, type, updated_at FROM announcements WHERE status = 'archived' ORDER BY updated_at DESC")
    ),
];

$groups[] = [
    'key'   => 'requirement',
    'icon'  => 'fa-list-check',
    'title' => 'Compliance requirements',
    'lede'  => 'Off every destination\'s checklist. The reports that were checked against them keep them.',
    'back'  => 'Restore puts it back on every checklist and notifies the managers.',
    'rows'  => array_map(
        static fn (array $r): array => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['title'],
            'note' => (int) $r['is_required'] === 1 ? 'Was required' : 'Was optional',
            'when' => '',
        ],
        Database::all('SELECT id, title, is_required FROM inspection_requirements WHERE is_active = 0 ORDER BY sort_order, id')
    ),
];

$groups[] = [
    'key'   => 'manager',
    'icon'  => 'fa-user-slash',
    'title' => 'Destination managers',
    'lede'  => 'Signed out and unable to sign in. Every report they filed keeps their name.',
    'back'  => 'Restore lets them sign in again with their existing password.',
    'rows'  => array_map(
        static fn (array $r): array => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['full_name'],
            'note' => (string) ($r['destination_name'] ?? ''),
            'when' => (string) ($r['last_login_at'] ?? ''),
        ],
        Database::all(
            'SELECT m.id, m.full_name, m.last_login_at, d.name AS destination_name
               FROM destination_managers m LEFT JOIN destinations d ON d.id = m.destination_id
              WHERE m.is_active = 0 ORDER BY m.full_name'
        )
    ),
];

$groups[] = [
    'key'   => 'account',
    'icon'  => 'fa-users-gear',
    'title' => 'Office accounts',
    'lede'  => 'Deactivated staff sign-ins. Everything they recorded is kept, under their name.',
    'back'  => 'Restore lets them sign in again.',
    'rows'  => array_map(
        static fn (array $r): array => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['full_name'],
            'note' => (string) $r['username'],
            'when' => (string) ($r['last_login_at'] ?? ''),
        ],
        Database::all('SELECT id, full_name, username, last_login_at FROM admins WHERE is_active = 0 ORDER BY full_name')
    ),
];

$groups[] = [
    'key'   => 'guide',
    'icon'  => 'fa-id-card',
    'title' => 'Tour guides',
    'lede'  => 'Suspended or revoked accreditations. Their record and their bookings are kept.',
    'back'  => 'Restore returns them to the accredited roster, and their ID verifies again.',
    'rows'  => array_map(
        static fn (array $r): array => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['full_name'],
            'note' => ucfirst((string) $r['status']),
            'when' => (string) ($r['updated_at'] ?? ''),
        ],
        Database::all("SELECT id, full_name, status, updated_at FROM tour_guides WHERE status <> 'active' ORDER BY full_name")
    ),
];

$groups[] = [
    'key'   => 'feedback',
    'icon'  => 'fa-comment-slash',
    'title' => 'Hidden visitor reviews',
    'lede'  => 'Taken off the destination pages by a moderator. The review itself is kept.',
    'back'  => 'Restore publishes it again.',
    'rows'  => array_map(
        static fn (array $r): array => [
            'id'   => (int) $r['id'],
            'name' => trim((string) ($r['visitor_name'] ?? '')) !== '' ? (string) $r['visitor_name'] : 'Anonymous visitor',
            'note' => ((string) ($r['destination_name'] ?? '')) . ' · ' . (int) $r['rating'] . '/5',
            'when' => (string) $r['created_at'],
        ],
        Database::all(
            "SELECT f.id, f.visitor_name, f.rating, f.created_at, d.name AS destination_name
               FROM feedback f LEFT JOIN destinations d ON d.id = f.destination_id
              WHERE f.status = 'hidden' ORDER BY f.created_at DESC"
        )
    ),
];

$groups[] = [
    'key'   => 'guide_review',
    'icon'  => 'fa-star-half-stroke',
    'title' => 'Hidden guide ratings',
    'lede'  => 'Taken off the guide\'s record by a moderator.',
    'back'  => 'Restore makes it visible again.',
    'rows'  => array_map(
        static fn (array $r): array => [
            'id'   => (int) $r['id'],
            'name' => trim((string) ($r['visitor_name'] ?? '')) !== '' ? (string) $r['visitor_name'] : 'Anonymous visitor',
            'note' => ((string) ($r['guide_name'] ?? '')) . ' · ' . (int) $r['rating'] . '/5',
            'when' => (string) $r['created_at'],
        ],
        Database::all(
            "SELECT r.id, r.visitor_name, r.rating, r.created_at, g.full_name AS guide_name
               FROM guide_reviews r LEFT JOIN tour_guides g ON g.id = r.guide_id
              WHERE r.status = 'hidden' ORDER BY r.created_at DESC"
        )
    ),
];

$total = array_sum(array_map(static fn (array $g): int => count($g['rows']), $groups));

$pageTitle    = 'Archive';
$pageIcon     = 'fa-box-archive';
$pageSubtitle = 'Everything withdrawn from service, and the way back';

$settingsTab = 'archive';

require __DIR__ . '/../_partials/head.php';
require __DIR__ . '/../_partials/settings-tabs.php';
?>

<div class="panel panel--notice">
    <div class="panel__body">
        <h2><i class="fa-solid fa-box-archive"></i> Nothing here is deleted</h2>
        <p class="mb-0">
            Archiving takes a record out of service without losing it: a destination leaves the public
            site but keeps every arrival ever recorded against it, and a deactivated manager keeps their
            name on the reports they filed. Everything on this page can be put back, exactly as it was.
            <?php if ($total === 0): ?>
                Nothing is archived at the moment.
            <?php else: ?>
                <strong><?= n($total) ?></strong> record<?= $total === 1 ? ' is' : 's are' ?> currently archived.
            <?php endif; ?>
        </p>
    </div>
</div>

<?php foreach ($groups as $group): ?>
    <?php if ($group['rows'] === []) { continue; } ?>

    <section class="panel">
        <header class="panel__head">
            <h2><i class="fa-solid <?= e($group['icon']) ?>"></i> <?= $group['title'] ?></h2>
            <span class="text-muted small"><?= n(count($group['rows'])) ?></span>
        </header>

        <div class="panel__body">
            <p class="result-count"><?= $group['lede'] ?> <?= e($group['back']) ?></p>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                        <?php foreach ($group['rows'] as $row): ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e($row['name']) ?></span>
                                    <?php if ($row['note'] !== ''): ?>
                                        <span class="cell-sub"><?= e($row['note']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small">
                                    <?= $row['when'] !== '' ? e(format_date($row['when'], 'M j, Y')) : '' ?>
                                </td>
                                <td class="text-end">
                                    <?php
                                    $ask = 'Restore "' . $row['name'] . '"? ' . $group['back'];
                                    ?>
                                    <form method="post" data-confirm="<?= e($ask) ?>" data-confirm-tone="normal">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="what" value="<?= e($group['key']) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Restore
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endforeach; ?>

<?php if ($total === 0): ?>
    <div class="panel"><div class="panel__body">
        <div class="empty">
            <i class="fa-solid fa-box-open"></i>
            <p><strong>The archive is empty.</strong></p>
            <p>
                Archive a destination, withdraw an announcement, remove a compliance requirement or
                deactivate an account, and it waits here until somebody puts it back.
            </p>
        </div>
    </div></div>
<?php endif; ?>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
