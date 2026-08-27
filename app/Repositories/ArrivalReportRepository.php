<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * =============================================================================
 *  TourSync — manager-submitted arrival reports                      Feature 2
 * -----------------------------------------------------------------------------
 *  The electronic replacement for the trip to the Municipal Tourism Office.
 *
 *  TWO RULES THIS CLASS ENFORCES, BECAUSE NOTHING ELSE CAN
 *
 *  1. Approved periods may not overlap. The daily summary keys on
 *     (destination, date) and the office's published figures are read from it,
 *     so two approved reports covering the same Tuesday would either double the
 *     day or silently overwrite it depending on approval order. Overlap is
 *     therefore refused at submission — the earliest point a human can still
 *     fix it — rather than discovered in a report to the Mayor.
 *
 *  2. Approval REPLACES the summary row for a date, it does not add to it.
 *     A manager's figure is the count for that day, not a contribution towards
 *     it. Adding would mean a corrected re-approval doubles the day.
 * =============================================================================
 */
final class ArrivalReportRepository
{
    public const STATUSES = [
        'draft'     => 'Draft',
        'submitted' => 'Submitted',
        'reviewing' => 'Under review',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
    ];

    /** Statuses that hold a claim on their dates. A rejected report does not. */
    private const CLAIMS_DATES = ['draft', 'submitted', 'reviewing', 'approved'];

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT r.*, d.name AS destination_name, m.full_name AS submitted_by_name,
                    a.full_name AS reviewed_by_name,
                    (SELECT COUNT(*) FROM arrival_report_days WHERE report_id = r.id) AS day_count,
                    (SELECT COALESCE(SUM(total_visitors), 0) FROM arrival_report_days WHERE report_id = r.id) AS visitors
               FROM arrival_reports r
               JOIN destinations d ON d.id = r.destination_id
               LEFT JOIN destination_managers m ON m.id = r.submitted_by
               LEFT JOIN admins a ON a.id = r.reviewed_by
              WHERE r.id = ?',
            [$id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function days(int $reportId): array
    {
        return Database::all(
            'SELECT * FROM arrival_report_days WHERE report_id = ? ORDER BY visit_date ASC',
            [$reportId]
        );
    }

    /**
     * One destination's reports. The manager area calls this and nothing else,
     * so a manager cannot list a neighbour's submissions.
     */
    public static function forDestination(int $destinationId, int $limit = 50): array
    {
        return Database::all(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM arrival_report_days WHERE report_id = r.id) AS day_count,
                    (SELECT COALESCE(SUM(total_visitors), 0) FROM arrival_report_days WHERE report_id = r.id) AS visitors
               FROM arrival_reports r
              WHERE r.destination_id = ?
              ORDER BY r.period_start DESC, r.id DESC
              LIMIT ' . max(1, min($limit, 200)),
            [$destinationId]
        );
    }

    /** The officer's queue. Drafts are excluded — they have not been handed over. */
    public static function queue(array $filters = [], int $limit = 200): array
    {
        $clauses = ["r.status <> 'draft'"];
        $params  = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'r.status = ?';
            $params[]  = $filters['status'];
        }

        if (!empty($filters['destination_id'])) {
            $clauses[] = 'r.destination_id = ?';
            $params[]  = (int) $filters['destination_id'];
        }

        /* The counts the office's list needs to decide what to open: how many
           records, and whether there is a paper page behind them. Sub-selects
           rather than joins — a JOIN across two one-to-many children multiplies
           the rows and both counts come back wrong. */
        return Database::all(
            'SELECT r.*, d.name AS destination_name, m.full_name AS submitted_by_name,
                    (SELECT COALESCE(SUM(total_visitors), 0) FROM arrival_report_days WHERE report_id = r.id) AS visitors,
                    (SELECT COUNT(*) FROM arrival_report_entries   WHERE report_id = r.id) AS entry_count,
                    (SELECT COUNT(*) FROM arrival_report_documents WHERE report_id = r.id) AS document_count
               FROM arrival_reports r
               JOIN destinations d ON d.id = r.destination_id
               LEFT JOIN destination_managers m ON m.id = r.submitted_by
              WHERE ' . implode(' AND ', $clauses) . '
              ORDER BY FIELD(r.status, \'submitted\', \'reviewing\', \'rejected\', \'approved\'), r.submitted_at ASC
              LIMIT ' . max(1, min(500, $limit)),
            $params
        );
    }

    public static function counts(?int $destinationId = null): array
    {
        $where  = $destinationId !== null ? 'WHERE destination_id = ?' : '';
        $params = $destinationId !== null ? [$destinationId] : [];

        $rows = Database::all(
            "SELECT status, COUNT(*) AS n FROM arrival_reports {$where} GROUP BY status",
            $params
        );

        $out = array_fill_keys(array_keys(self::STATUSES), 0);

        foreach ($rows as $row) {
            $out[$row['status']] = (int) $row['n'];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Overlap
    // -------------------------------------------------------------------------

    /**
     * Another live report already covering any of these dates.
     *
     * Rejected reports are ignored: they hold no claim, which is what lets a
     * manager correct one and resubmit the same period.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function overlapping(int $destinationId, string $start, string $end, int $exceptId = 0): array
    {
        $in = implode(',', array_fill(0, count(self::CLAIMS_DATES), '?'));

        return Database::all(
            "SELECT id, period_start, period_end, status
               FROM arrival_reports
              WHERE destination_id = ?
                AND id <> ?
                AND status IN ({$in})
                AND period_start <= ?
                AND period_end   >= ?",
            array_merge([$destinationId, $exceptId], self::CLAIMS_DATES, [$end, $start])
        );
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    public static function createDraft(int $destinationId, string $start, string $end, string $notes = ''): int
    {
        return Database::insert(
            'INSERT INTO arrival_reports (destination_id, period_start, period_end, status, notes)
             VALUES (?, ?, ?, \'draft\', ?)',
            [$destinationId, $start, $end, $notes !== '' ? $notes : null]
        );
    }

    public static function updateDraft(int $id, string $start, string $end, string $notes = ''): void
    {
        Database::run(
            'UPDATE arrival_reports SET period_start = ?, period_end = ?, notes = ?
              WHERE id = ? AND status IN (\'draft\', \'rejected\')',
            [$start, $end, $notes !== '' ? $notes : null, $id]
        );
    }

    /**
     * Replaces the whole set of day rows for a report.
     *
     * Wholesale rather than row by row: the form posts the complete table every
     * time, and reconciling additions, edits and deletions individually is a
     * larger surface for a partial write than simply rewriting the set inside a
     * transaction.
     *
     * @param array<int, array<string, int|string|null>> $days
     */
    public static function replaceDays(int $reportId, array $days): void
    {
        Database::transaction(static function () use ($reportId, $days): void {
            Database::run('DELETE FROM arrival_report_days WHERE report_id = ?', [$reportId]);

            foreach ($days as $day) {
                Database::run(
                    'INSERT INTO arrival_report_days
                        (report_id, visit_date, local_count, domestic_count, foreign_count, ofw_count,
                         male_count, female_count, children_count, adults_count, seniors_count)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $reportId,
                        $day['visit_date'],
                        (int) ($day['local_count']    ?? 0),
                        (int) ($day['domestic_count'] ?? 0),
                        (int) ($day['foreign_count']  ?? 0),
                        (int) ($day['ofw_count']      ?? 0),
                        $day['male_count']     !== null && $day['male_count']     !== '' ? (int) $day['male_count']     : null,
                        $day['female_count']   !== null && $day['female_count']   !== '' ? (int) $day['female_count']   : null,
                        $day['children_count'] !== null && $day['children_count'] !== '' ? (int) $day['children_count'] : null,
                        $day['adults_count']   !== null && $day['adults_count']   !== '' ? (int) $day['adults_count']   : null,
                        $day['seniors_count']  !== null && $day['seniors_count']  !== '' ? (int) $day['seniors_count']  : null,
                    ]
                );
            }
        });
    }

    public static function submit(int $id, int $managerId): void
    {
        Database::run(
            'UPDATE arrival_reports
                SET status = \'submitted\', submitted_by = ?, submitted_at = NOW(),
                    rejection_reason = NULL, reviewed_by = NULL, reviewed_at = NULL
              WHERE id = ? AND status IN (\'draft\', \'rejected\')',
            [$managerId, $id]
        );
    }

    public static function markReviewing(int $id, int $adminId): void
    {
        Database::run(
            'UPDATE arrival_reports SET status = \'reviewing\', reviewed_by = ?
              WHERE id = ? AND status = \'submitted\'',
            [$adminId, $id]
        );
    }

    /**
     * Sends a report back to its manager with a reason.
     *
     * An already-approved report may be rejected: a figure found wrong next
     * month has to be correctable, or the municipality's record stays wrong
     * forever. Withdrawing an approval also withdraws its figures — the summary
     * rows this report published are deleted here.
     *
     * Without that, a corrected resubmission covering fewer days would leave the
     * dropped dates behind in the summary, still counted, with nothing left
     * pointing at where they came from.
     */
    public static function reject(int $id, int $adminId, string $reason): void
    {
        Database::transaction(static function () use ($id, $adminId, $reason): void {
            $report = Database::first(
                'SELECT destination_id, status FROM arrival_reports WHERE id = ? FOR UPDATE',
                [$id]
            );

            if ($report === null || !in_array($report['status'], ['submitted', 'reviewing', 'approved'], true)) {
                return;
            }

            if ($report['status'] === 'approved') {
                foreach (Database::all('SELECT visit_date FROM arrival_report_days WHERE report_id = ?', [$id]) as $day) {
                    Database::run(
                        'DELETE FROM arrival_daily_summary WHERE destination_id = ? AND visit_date = ?',
                        [(int) $report['destination_id'], $day['visit_date']]
                    );
                }

                /* The transcribed names go back out of the municipality's
                   record too. The draft in arrival_report_entries stays — that
                   is the copy the manager corrects and resubmits. */
                LogbookEntryRepository::withdraw($id);
            }

            Database::run(
                'UPDATE arrival_reports
                    SET status = \'rejected\', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ?
                  WHERE id = ?',
                [$adminId, $reason, $id]
            );
        });
    }

    /**
     * Approves a report and publishes its figures.
     *
     * The whole thing runs in one transaction: a report marked approved whose
     * figures never reached the summary would show as counted on the officer's
     * screen and be missing from every report drawn from it.
     *
     * REPLACE, not add. The manager's figure is the count for that day. An
     * ON DUPLICATE KEY that incremented would double every day of a report that
     * was corrected and approved a second time.
     */
    public static function approve(int $id, int $adminId): void
    {
        Database::transaction(static function () use ($id, $adminId): void {
            $report = Database::first(
                'SELECT destination_id, status FROM arrival_reports WHERE id = ? FOR UPDATE',
                [$id]
            );

            if ($report === null || !in_array($report['status'], ['submitted', 'reviewing'], true)) {
                return;
            }

            $destinationId = (int) $report['destination_id'];

            foreach (Database::all('SELECT * FROM arrival_report_days WHERE report_id = ?', [$id]) as $day) {
                Database::run(
                    'INSERT INTO arrival_daily_summary
                        (destination_id, visit_date, total_records, total_visitors,
                         local_count, domestic_count, foreign_count, ofw_count)
                     VALUES (?, ?, 1, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        total_records  = VALUES(total_records),
                        total_visitors = VALUES(total_visitors),
                        local_count    = VALUES(local_count),
                        domestic_count = VALUES(domestic_count),
                        foreign_count  = VALUES(foreign_count),
                        ofw_count      = VALUES(ofw_count)',
                    [
                        $destinationId,
                        $day['visit_date'],
                        (int) $day['total_visitors'],
                        (int) $day['local_count'],
                        (int) $day['domestic_count'],
                        (int) $day['foreign_count'],
                        (int) $day['ofw_count'],
                    ]
                );
            }

            /* The transcribed logbook lines cross into the municipality's record
               here and nowhere else. Inside the same transaction as the status
               change, so a report that reads "approved" always has its arrival
               rows behind it. */
            LogbookEntryRepository::publish($id, $destinationId);

            Database::run(
                'UPDATE arrival_reports
                    SET status = \'approved\', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = NULL
                  WHERE id = ?',
                [$adminId, $id]
            );
        });
    }

    public static function deleteDraft(int $id): void
    {
        /* Only a draft. Once handed over, a submission is part of the record —
           the officer rejects it, the manager corrects it, and the history of
           both stays. */
        Database::run('DELETE FROM arrival_reports WHERE id = ? AND status = \'draft\'', [$id]);
    }
}
