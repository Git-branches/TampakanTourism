<?php
/**
 * The sheet itself — shared by the screen and the print view so the two can
 * never drift. Expects $record and $signatories from whichever page includes it.
 *
 * The header is three rows deep because the office's form is: a residence band
 * spanning six columns, split into Philippines and Foreign Country, each split
 * again into Male / Female / Total. Reproduced rather than flattened, so an
 * officer comparing this against the paper sheet is looking at the same shape.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

/** @var array<string, mixed> $record */
/** @var array<string, string> $signatories */

$cell = static fn (int $v): string => $v > 0 ? number_format($v) : '-';
?>

<table class="visitor-record">
    <thead>
        <tr>
            <th colspan="2" class="vr-band">Visitor Attraction</th>
            <th colspan="9" class="vr-band">*** Place of Residence</th>
            <th colspan="3" class="vr-band">* Grand Total<br><span class="vr-sub">Number of Visitors</span></th>
        </tr>
        <tr>
            <th rowspan="2" class="vr-name">Name</th>
            <th rowspan="2" class="vr-code">Attraction<br>Code</th>
            <th colspan="6" class="vr-band">Philippines</th>
            <th colspan="3" class="vr-band">Foreign Country<br><span class="vr-sub">Residence</span></th>
            <th rowspan="2">Male</th>
            <th rowspan="2">Female</th>
            <th rowspan="2">Total</th>
        </tr>
        <tr>
            <th colspan="3" class="vr-band">This province</th>
            <th colspan="3" class="vr-band">Other Province</th>
            <th>Male</th><th>Female</th><th>Total</th>
        </tr>
        <tr class="vr-subhead">
            <th></th><th></th>
            <th>Male</th><th>Female</th><th>Total</th>
            <th>Male</th><th>Female</th><th>Total</th>
            <th></th><th></th><th></th>
            <th></th><th></th><th></th>
        </tr>
    </thead>

    <tbody>
        <!-- The municipality heading, exactly as the office's sheet groups it. -->
        <tr class="vr-group">
            <td colspan="14"><?= e($record['municipality']) ?></td>
        </tr>

        <?php foreach ($record['rows'] as $row): $f = $row['figures']; ?>
            <tr>
                <td class="vr-name">
                    <?= e($row['name']) ?>
                    <?php if (!empty($row['archived'])): ?>
                        <span class="vr-sub">(archived)</span>
                    <?php endif; ?>
                </td>
                <td class="vr-code"><?= e($row['code']) ?></td>

                <td><?= $cell($f['this_province']['male']) ?></td>
                <td><?= $cell($f['this_province']['female']) ?></td>
                <td class="vr-total"><?= $cell($f['this_province']['total']) ?></td>

                <td><?= $cell($f['other_province']['male']) ?></td>
                <td><?= $cell($f['other_province']['female']) ?></td>
                <td class="vr-total"><?= $cell($f['other_province']['total']) ?></td>

                <td><?= $cell($f['foreign']['male']) ?></td>
                <td><?= $cell($f['foreign']['female']) ?></td>
                <td class="vr-total"><?= $cell($f['foreign']['total']) ?></td>

                <td><?= $cell($f['grand']['male']) ?></td>
                <td><?= $cell($f['grand']['female']) ?></td>
                <td class="vr-total"><?= $cell($f['grand']['total']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>

    <tfoot>
        <tr class="vr-grand">
            <th colspan="2">Total of this Month ****</th>
            <?php foreach (['this_province', 'other_province', 'foreign', 'grand'] as $column): ?>
                <th><?= $cell($record['totals'][$column]['male']) ?></th>
                <th><?= $cell($record['totals'][$column]['female']) ?></th>
                <th class="vr-total"><?= $cell($record['totals'][$column]['total']) ?></th>
            <?php endforeach; ?>
        </tr>
    </tfoot>
</table>

<?php
/* Shown only when it applies. A footnote that appears every month is a footnote
   nobody reads by the third month; one that appears when a figure genuinely
   does not add up is a footnote that gets acted on. */
$unspecified = $record['totals']['grand']['unspecified'];
?>
<?php if ($unspecified > 0): ?>
    <p class="vr-footnote">
        ** <?= number_format($unspecified) ?> visitor(s) have no recorded sex, so Male + Female comes to
        less than the Total. The paper logbook has no sex column; the Total is the figure to report.
    </p>
<?php endif; ?>

<div class="vr-signatures">
    <div>
        <span class="vr-signatures__label">Prepared by:</span>
        <span class="vr-signatures__name"><?= e($signatories['prepared_by']) ?></span>
        <span class="vr-signatures__title"><?= e($signatories['prepared_by_title']) ?></span>
    </div>
    <div>
        <span class="vr-signatures__label">Approved by:</span>
        <span class="vr-signatures__name"><?= e($signatories['approved_by']) ?></span>
        <span class="vr-signatures__title"><?= e($signatories['approved_by_title']) ?></span>
    </div>
</div>
