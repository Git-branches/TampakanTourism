<?php
declare(strict_types=1);

/**
 * TourSync — data retention.       Officer only.       RA 10173
 *
 * The Data Privacy Act asks that personal data not be kept longer than the
 * purpose requires. The purpose here is counting visitor arrivals, and a count
 * does not need anybody's name.
 *
 * So this job clears the identifying columns and leaves everything else:
 * the visit still happened, the party size is unchanged, and every report ever
 * produced still reconciles. Deleting the rows outright would satisfy privacy
 * and destroy the municipality's tourism statistics at the same time.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;

Auth::require('officer');

$pageTitle    = 'Data Retention';
$pageIcon     = 'fa-user-shield';
$pageSubtitle = 'Anonymise personal details in older records';

$months = max(6, min((int) setting('retention_months', '36'), 120));
$cutoff = date('Y-m-d', strtotime("-{$months} months"));

if (is_post()) {
    Csrf::verify();

    if (($_POST['confirm'] ?? '') !== 'ANONYMISE') {
        Session::flash('danger', 'Type ANONYMISE exactly to confirm.');
        redirect(base_url('/admin/settings/retention.php'));
    }

    // Only the identifying columns are cleared. total_visitors, tourist_type,
    // visit_date, destination, and every other statistical field are untouched.
    $affected = Database::affected(
        "UPDATE tourist_arrivals
            SET full_name = NULL, contact_number = NULL, email = NULL,
                device_hash = NULL, anonymised_at = NOW()
          WHERE visit_date < ?
            AND anonymised_at IS NULL
            AND (full_name IS NOT NULL OR contact_number IS NOT NULL
                 OR email IS NOT NULL OR device_hash IS NOT NULL)",
        [$cutoff]
    );

    ActivityLog::record('retention.run', 'arrival', null,
        "Anonymised personal fields on {$affected} arrival record(s) older than {$cutoff}");

    Session::flash('success', $affected === 0
        ? 'Nothing needed anonymising — no records older than the retention period still hold personal details.'
        : "Personal details cleared from {$affected} record(s). Every count and report figure is unchanged.");

    redirect(base_url('/admin/settings/retention.php'));
}

$pending = Database::first(
    "SELECT COUNT(*) AS records, COALESCE(SUM(total_visitors), 0) AS visitors
       FROM tourist_arrivals
      WHERE visit_date < ?
        AND anonymised_at IS NULL
        AND (full_name IS NOT NULL OR contact_number IS NOT NULL
             OR email IS NOT NULL OR device_hash IS NOT NULL)",
    [$cutoff]
);

$alreadyDone = (int) Database::scalar('SELECT COUNT(*) FROM tourist_arrivals WHERE anonymised_at IS NOT NULL');
$oldest      = Database::scalar("SELECT MIN(visit_date) FROM tourist_arrivals");

require __DIR__ . '/../_partials/head.php';
?>

<div class="panel panel--notice">
    <div class="panel__body">
        <h2><i class="fa-solid fa-scale-balanced"></i> What this does, and what it does not</h2>
        <p>
            Under <strong>Republic Act No. 10173</strong>, personal data should not be kept longer
            than the purpose requires. This system's purpose is counting arrivals — and a count
            needs no name.
        </p>
        <div class="retention-split">
            <div class="retention-split__col retention-split__col--clear">
                <h3><i class="fa-solid fa-eraser"></i> Cleared</h3>
                <ul>
                    <li>Visitor name</li>
                    <li>Contact number</li>
                    <li>Email address</li>
                    <li>Device fingerprint</li>
                </ul>
            </div>
            <div class="retention-split__col retention-split__col--keep">
                <h3><i class="fa-solid fa-shield"></i> Kept</h3>
                <ul>
                    <li>Visit date and destination</li>
                    <li>Party size and visitor count</li>
                    <li>Tourist type, stay type, purpose</li>
                    <li>Origin city, province, country</li>
                    <li>Age group and sex</li>
                </ul>
            </div>
        </div>
        <p class="report-note">
            Rows are never deleted. Deleting them would satisfy privacy and destroy the
            municipality's tourism statistics in the same stroke — every past report would stop
            reconciling with its own source data.
        </p>
    </div>
</div>

<div class="panel-row">
    <div>
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-list-check"></i> Records Due</h2></header>
            <div class="panel__body">
                <dl class="detail-grid">
                    <div><dt>Retention period</dt><dd><?= n($months) ?> months</dd></div>
                    <div><dt>Cut-off date</dt><dd><?= e(format_date($cutoff)) ?></dd></div>
                    <div><dt>Oldest record held</dt><dd><?= $oldest ? e(format_date((string) $oldest)) : '—' ?></dd></div>
                    <div><dt>Already anonymised</dt><dd><?= n($alreadyDone) ?> record(s)</dd></div>
                </dl>

                <?php if ((int) $pending['records'] === 0): ?>
                    <div class="alert alert-success mb-0 mt-3">
                        <i class="fa-solid fa-circle-check"></i>
                        <strong>Nothing is overdue.</strong>
                        No record older than <?= e(format_date($cutoff)) ?> still holds personal details.
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mb-0 mt-3">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <strong><?= n($pending['records']) ?> record(s)</strong> older than
                        <?= e(format_date($cutoff)) ?> still hold personal details.
                        Their <?= n($pending['visitors']) ?> counted visitors will remain in every report.
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="panel-stack">
        <section class="panel">
            <header class="panel__head"><h2><i class="fa-solid fa-play"></i> Run the Job</h2></header>
            <div class="panel__body">
                <?php if ((int) $pending['records'] === 0): ?>
                    <p class="text-muted small mb-0">There is nothing to anonymise right now.</p>
                <?php else: ?>
                    <p class="text-muted small">
                        This cannot be undone — that is the point of it. Type <strong>ANONYMISE</strong>
                        to confirm you intend it.
                    </p>
                    <form method="post" onsubmit="return confirm('Permanently clear personal details from <?= (int) $pending['records'] ?> record(s)?');">
                        <?= csrf_field() ?>
                        <input type="text" name="confirm" class="form-control mb-2" placeholder="Type ANONYMISE" autocomplete="off" required>
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="fa-solid fa-eraser"></i> Anonymise <?= n($pending['records']) ?> record(s)
                        </button>
                    </form>
                <?php endif; ?>
                <p class="report-note">
                    Change the retention period in <a href="index.php">Settings</a>.
                    The run is recorded in the activity log.
                </p>
            </div>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../_partials/foot.php'; ?>
