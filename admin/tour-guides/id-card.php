<?php
declare(strict_types=1);

/**
 * TourSync — the tour guide ID card, on a page of its own.
 *
 * A thin shell now. The card itself lives in _card.php, which this page and the
 * record's dialog both include — so there is one implementation of a printed
 * artefact rather than two that drift.
 *
 * WHAT THIS PAGE ADDS that the dialog does not: a toolbar, and a document it
 * owns outright, which is the reliable place to print from when a browser is
 * awkward about printing out of a dialog.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\QrService;
use App\Core\Session;
use App\Repositories\TourGuideRosterRepository as Roster;

Auth::require();

$id    = (int) ($_GET['id'] ?? 0);
$guide = $id > 0 ? Roster::find($id) : null;

if ($guide === null) {
    Session::flash('danger', 'That guide could not be found.');
    redirect(base_url('/admin/tour-guides/index.php'));
}

$effective = (string) $guide['effective_status'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ID <?= e((string) $guide['guide_code']) ?> — <?= e((string) $guide['full_name']) ?></title>
<link rel="icon" href="<?= e(asset('img/tampakan_logo.png')) ?>" sizes="any">
<style>
    body {
        margin: 0; padding: 1.5rem 1rem 3rem;
        font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
        color: #16211A; background: #EEF1EF;
    }
    .bar { max-width: 62rem; margin: 0 auto 1.25rem; display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
    .bar a, .bar button {
        padding: .55rem 1rem; border: 1px solid #123D1E; border-radius: 8px;
        background: #fff; color: #123D1E; font: inherit; font-size: .88rem;
        text-decoration: none; cursor: pointer;
    }
    .bar .primary { background: #123D1E; color: #fff; }
    .bar .grow { margin-left: auto; font-size: .82rem; color: #5A6B60; }
    .warn {
        max-width: 62rem; margin: 0 auto 1.25rem; padding: .8rem 1rem;
        border-left: 4px solid #C62828; border-radius: 6px;
        background: #FDECEA; color: #8E1F1B; font-size: .9rem;
    }
    @media print {
        body { background: #fff; padding: 0; }
        .bar, .warn { display: none !important; }
    }
</style>
</head>
<body>

<div class="bar">
    <a href="<?= e(base_url('/admin/tour-guides/view.php?id=' . $id)) ?>">&larr; Back to the record</a>
    <button class="primary" onclick="window.print()">Print both sides</button>
    <form method="post" action="<?= e(base_url('/admin/tour-guides/view.php?id=' . $id)) ?>" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="issued">
        <button type="submit">Record as issued today</button>
    </form>
    <span class="grow">
        2.63 &times; 3.88&nbsp;in portrait. Print at 100%, <strong>no scaling</strong> &mdash;
        &ldquo;fit to page&rdquo; will make the card the wrong size.
    </span>
</div>

<?php if ($effective !== 'active'): ?>
    <div class="warn">
        <strong>This card would not verify.</strong>
        Scanning it shows <strong><?= e(strtoupper(Roster::EFFECTIVE[$effective])) ?></strong>.
        <?= $effective === 'no_id'
            ? 'Set a “valid until” date on the record before printing.'
            : 'Fix the status or the expiry date first.' ?>
    </div>
<?php elseif (!QrService::isPublishable()): ?>
    <div class="warn">
        <strong>Do not print yet.</strong>
        <?= e(QrService::unpublishableReason()) ?>
        Set the public website address in Settings first.
    </div>
<?php elseif (QrService::warning() !== ''): ?>
    <div class="warn"><?= e(QrService::warning()) ?></div>
<?php endif; ?>

<?php
$tgidStandalone = true;
require __DIR__ . '/_card.php';
?>

</body>
</html>
