<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Repositories\ArrivalRepository;

Auth::require();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$a  = ArrivalRepository::find($id);

if ($a === null) {
    Session::flash('danger', 'That arrival record no longer exists.');
    redirect(base_url('/admin/arrivals/index.php'));
}

$pageTitle    = 'Arrival Record';
$pageIcon     = 'fa-user-check';
$pageSubtitle = '#' . $id . ' · ' . $a['destination_name'];

if (is_post()) {
    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');

    // Voiding changes a published government figure, so it is officer-only
    // and always carries a stated reason.
    if ($action === 'void') {
        if (!Auth::isOfficer()) {
            Session::flash('danger', 'Only the Tourism Officer can void an arrival record.');
            redirect(base_url('/admin/arrivals/view.php?id=' . $id));
        }

        $reason = trim((string) ($_POST['void_reason'] ?? ''));
        if ($reason === '') {
            Session::flash('danger', 'A reason is required before a record can be voided.');
            redirect(base_url('/admin/arrivals/view.php?id=' . $id));
        }

        ArrivalRepository::void($id, $reason, (int) Auth::id());
        ActivityLog::record('arrival.void', 'arrival', $id,
            'Voided arrival at "' . $a['destination_name'] . '": ' . $reason);
        Session::flash('success', 'Record voided and removed from all published figures. The totals have been recalculated.');
    }

    if ($action === 'approve') {
        ArrivalRepository::approve($id);
        ActivityLog::record('arrival.approve', 'arrival', $id,
            'Approved flagged arrival at "' . $a['destination_name'] . '"');
        Session::flash('success', 'Record approved and now included in published figures.');
    }

    redirect(base_url('/admin/arrivals/view.php?id=' . $id));
}

/** Renders a value or an em dash, never an empty cell. */
function field($value): string
{
    $value = is_string($value) ? trim($value) : $value;
    return ($value === '' || $value === null) ? '—' : e((string) $value);
}

require __DIR__ . '/../_partials/head.php';
?>

<div class="record-bar">
    <div class="record-bar__facts">
        <?php if ($a['status'] === 'valid'): ?>
            <span class="pill pill--ok">Counted in published figures</span>
        <?php elseif ($a['status'] === 'flagged'): ?>
            <span class="pill pill--flag">Flagged — excluded from figures</span>
        <?php else: ?>
            <span class="pill pill--void">Voided — excluded from figures</span>
        <?php endif; ?>

        <span><i class="fa-solid fa-users"></i> <?= n($a['total_visitors']) ?> visitor<?= (int) $a['total_visitors'] === 1 ? '' : 's' ?></span>
        <span><i class="fa-regular fa-clock"></i> <?= e(format_date($a['arrived_at'], 'M j, Y g:i A')) ?></span>
    </div>
    <div class="record-bar__actions">
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
</div>

<?php if ($a['status'] === 'flagged'): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-flag"></i>
        <strong>Why this was flagged:</strong> <?= e((string) $a['flag_reason']) ?>
        <p class="mb-0 mt-2 small">
            Flagged records are held out of every published figure until reviewed. A repeat
            submission is often legitimate — a family sharing one phone, or a guide filing for
            several guests — so nothing was blocked at the time.
        </p>
    </div>
<?php endif; ?>

<?php if ($a['status'] === 'voided'): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-ban"></i>
        <strong>Voided.</strong> <?= e((string) $a['void_reason']) ?>
    </div>
<?php endif; ?>

<div class="panel-row">
    <div>
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-clipboard-list"></i> Visit Details</h2></header>
            <div class="panel__body">
                <dl class="detail-grid">
                    <div><dt>Destination</dt><dd><?= e($a['destination_name']) ?></dd></div>
                    <div><dt>Visit date</dt><dd><?= e(format_date($a['visit_date'])) ?></dd></div>
                    <div><dt>Recorded at</dt><dd><?= e(format_date($a['arrived_at'], 'M j, Y g:i A')) ?></dd></div>
                    <div><dt>Tourist type</dt><dd><?= e(ArrivalRepository::TYPES[$a['tourist_type']] ?? $a['tourist_type']) ?></dd></div>
                    <div><dt>Stay</dt><dd><?= $a['stay_type'] === 'overnight' ? 'Overnight' : ($a['stay_type'] === 'day_trip' ? 'Day trip' : '—') ?></dd></div>
                    <div><dt>Purpose</dt><dd><?= field(ArrivalRepository::PURPOSES[$a['purpose']] ?? null) ?></dd></div>
                    <div><dt>Party size</dt><dd><?= n($a['total_visitors']) ?> (respondent + <?= n($a['companions_count']) ?>)</dd></div>
                    <div><dt>Origin</dt><dd><?= field(implode(', ', array_filter([$a['origin_city'], $a['origin_province'], $a['origin_country']]))) ?></dd></div>
                </dl>
            </div>
        </section>

        <section class="panel">
            <header class="panel__head"><h2><i class="fa-regular fa-address-card"></i> Personal Details</h2></header>
            <div class="panel__body">
                <p class="text-muted small mb-3">
                    <i class="fa-solid fa-shield-halved"></i>
                    Optional fields under RA 10173. Blank entries mean the visitor chose not to
                    give them, which is their right — the statistics do not depend on any of this.
                </p>
                <dl class="detail-grid">
                    <div><dt>Name</dt><dd><?= field($a['full_name']) ?></dd></div>
                    <div><dt>Age group</dt><dd><?= field(ArrivalRepository::AGE_BRACKETS[$a['age_bracket']] ?? null) ?></dd></div>
                    <div><dt>Sex</dt><dd><?= field($a['sex'] ? ucfirst(str_replace('_', ' ', $a['sex'])) : null) ?></dd></div>
                    <div><dt>Contact</dt><dd><?= field($a['contact_number']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= field($a['email']) ?></dd></div>
                    <div><dt>Consent given</dt><dd><?= (int) $a['consent_given'] === 1 ? 'Yes' : 'No' ?></dd></div>
                </dl>
            </div>
        </section>
    </div>

    <div class="panel-stack">
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-fingerprint"></i> Record Provenance</h2></header>
            <div class="panel__body">
                <dl class="detail-grid detail-grid--single">
                    <div><dt>Source</dt><dd><?= $a['source'] === 'qr' ? 'QR scan by the visitor' : 'Manual entry by staff' ?></dd></div>
                    <?php if ($a['source'] === 'qr'): ?>
                        <div><dt>Signage version</dt><dd>v<?= field($a['qr_version_used']) ?></dd></div>
                    <?php endif; ?>
                    <div>
                        <dt>Device fingerprint</dt>
                        <dd class="mono small"><?= $a['device_hash'] ? e(substr($a['device_hash'], 0, 20)) . '…' : '—' ?></dd>
                    </div>
                </dl>
                <p class="text-muted small mb-0 mt-2">
                    A salted hash of address and browser. It detects repeat submissions from one
                    device without the arrivals table becoming a record of who was where.
                </p>
            </div>
        </section>

        <?php if ($a['status'] === 'flagged'): ?>
            <section class="panel">
                <header class="panel__head"><h2><i class="fa-solid fa-gavel"></i> Review</h2></header>
                <div class="panel__body">
                    <form method="post" onsubmit="return confirm('Include this record in published figures?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-brand btn-sm w-100">
                            <i class="fa-solid fa-check"></i> Approve and count this record
                        </button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($a['status'] !== 'voided' && Auth::isOfficer()): ?>
            <section class="panel">
                <header class="panel__head"><h2><i class="fa-solid fa-ban"></i> Void Record</h2></header>
                <div class="panel__body">
                    <p class="text-muted small">
                        Voiding removes this record from every published figure and recalculates the
                        totals. The row itself is kept — an official statistic that changed with no
                        trace of what was removed is not auditable.
                    </p>
                    <form method="post" onsubmit="return confirm('Void this record? Published totals will be recalculated.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="void">
                        <label for="void_reason" class="form-label">Reason <span class="req">*</span></label>
                        <textarea id="void_reason" name="void_reason" rows="3" class="form-control mb-2" required
                                  placeholder="e.g. Duplicate of record #12, confirmed with the site attendant"></textarea>
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                            <i class="fa-solid fa-ban"></i> Void this record
                        </button>
                    </form>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
