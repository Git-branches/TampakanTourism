<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\OriginClassifier;

/**
 * =============================================================================
 *  TourSync — the transcribed paper logbook                          Feature 2
 * -----------------------------------------------------------------------------
 *  One row here is one line on a paper page: Name, Address, Contact no. The
 *  page's Date is written once at the top and applies to every line under it,
 *  which is why visit_date lives on the entry rather than being asked for
 *  line by line. The Signature column stays on paper — the photograph of the
 *  page is its record.
 *
 *  THE COUNTS ARE NEVER TYPED. arrival_report_days is rebuilt from these rows
 *  every time a page is saved. A manager cannot enter a total that disagrees
 *  with the lines behind it, because there is no field in which to enter one.
 *
 *  TWO STORES, ON PURPOSE
 *
 *  arrival_report_entries is the draft: what the manager typed, while it is
 *  being written and reviewed. tourist_arrivals is the municipality's record.
 *  Approval copies across; withdrawing an approval deletes the copies. Typing
 *  straight into tourist_arrivals would put unreviewed names into the arrivals
 *  list, the analytics and every DOT-format report the moment a key was
 *  pressed — figures published before anyone had looked at the page.
 *
 *  PRIVACY. Names, addresses and contact numbers of private individuals, held
 *  under RA 10173. Nothing in this class is reachable from the public site or
 *  the chatbot, and nothing here should ever be made so.
 * =============================================================================
 */
final class LogbookEntryRepository
{
    /** A paper page has ~25 lines; the ceiling is generous and only guards abuse. */
    public const MAX_ROWS_PER_PAGE = 120;

    private const TYPES = ['local', 'domestic', 'foreign', 'overseas_filipino'];

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public static function forDate(int $reportId, string $date): array
    {
        return Database::all(
            'SELECT * FROM arrival_report_entries
              WHERE report_id = ? AND visit_date = ?
              ORDER BY row_no ASC',
            [$reportId, $date]
        );
    }

    /**
     * The pages of one report, in BOTH vocabularies.
     *
     * local / domestic / foreign / ofw is what this system stores and what
     * arrival_daily_summary is keyed on. this_province / other_province /
     * foreign is what the Municipal Tourism Office actually submits on the
     * monthly Tourism Attraction Visitor Record.
     *
     * They are different cuts of the same people. Polomolok and Koronadal are
     * "domestic" — not this municipality — but "this province", because they are
     * South Cotabato. A manager shown only the first set cannot check their own
     * figures against the sheet the office files, so both are returned and the
     * manager's screen shows the one that gets reported.
     *
     * `unplaced` counts lines whose address yielded no province at all. Those
     * land in the Grand Total on the sheet and in none of the three residence
     * columns, so they are surfaced here rather than left to be discovered as a
     * row that does not add up.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pages(int $reportId): array
    {
        $province = (string) (setting('office_province') ?: 'South Cotabato');

        return Database::all(
            "SELECT visit_date,
                    COUNT(*) AS entries,

                    SUM(tourist_type = 'local')             AS local_count,
                    SUM(tourist_type = 'domestic')          AS domestic_count,
                    SUM(tourist_type = 'foreign')           AS foreign_count,
                    SUM(tourist_type = 'overseas_filipino') AS ofw_count,

                    SUM(tourist_type = 'local'
                        OR (tourist_type = 'domestic' AND origin_province = ?))   AS this_province,
                    SUM(tourist_type = 'domestic'
                        AND origin_province IS NOT NULL AND origin_province <> ?) AS other_province,
                    SUM(tourist_type IN ('foreign', 'overseas_filipino'))         AS foreign_total,
                    SUM(tourist_type = 'domestic' AND origin_province IS NULL)    AS unplaced,

                    SUM(confidence = 'low') AS unsure
               FROM arrival_report_entries
              WHERE report_id = ?
              GROUP BY visit_date
              ORDER BY visit_date ASC",
            [$province, $province, $reportId]
        );
    }

    /**
     * Lines whose address yielded no province, across the whole report.
     *
     * These are the ones that will be counted in the month's total and shown in
     * none of its residence columns. Worth fixing before submission, which is
     * why the manager is told the number rather than left to notice it later.
     */
    public static function unplaced(int $reportId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM arrival_report_entries
              WHERE report_id = ? AND tourist_type = 'domestic' AND origin_province IS NULL",
            [$reportId]
        );
    }

    public static function countFor(int $reportId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM arrival_report_entries WHERE report_id = ?',
            [$reportId]
        );
    }

    /**
     * Lines the classifier was unsure about, across the whole report.
     *
     * Surfaced before submission: a guess that reaches the office unchallenged
     * becomes a municipal statistic nobody can trace back to a decision.
     */
    public static function unsure(int $reportId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM arrival_report_entries WHERE report_id = ? AND confidence = 'low'",
            [$reportId]
        );
    }

    // -------------------------------------------------------------------------
    // Writing a page
    // -------------------------------------------------------------------------

    /**
     * Replaces one page — one date — with the lines given.
     *
     * Wholesale rather than line by line, for the same reason replaceDays() is:
     * the form posts the whole page every time, and reconciling insertions,
     * edits and deletions individually is a much larger surface for a partial
     * write than rewriting the page inside a transaction.
     *
     * Each row: full_name (required), address_text, contact_number, and
     * optionally tourist_type when the manager has overridden the classifier.
     *
     * @param array<int, array<string, string|null>> $rows
     */
    public static function replaceForDate(int $reportId, string $date, array $rows): void
    {
        Database::transaction(static function () use ($reportId, $date, $rows): void {
            Database::run(
                'DELETE FROM arrival_report_entries WHERE report_id = ? AND visit_date = ?',
                [$reportId, $date]
            );

            $lineNo = 0;

            foreach ($rows as $row) {
                $name = trim((string) ($row['full_name'] ?? ''));

                /* A line with no name is an empty line on the page. The paper
                   has plenty of those below the last visitor. */
                if ($name === '') {
                    continue;
                }

                if (++$lineNo > self::MAX_ROWS_PER_PAGE) {
                    break;
                }

                $address = trim((string) ($row['address_text'] ?? ''));
                $guess   = OriginClassifier::classify($address);

                /* An override is the manager DISAGREEING with what the row was
                   showing them — not merely the value the dropdown happened to
                   hold. Those are different things, and conflating them is a
                   bug that silently disables the classifier: every new line
                   starts with the dropdown on some default, and comparing
                   against the fresh guess would read that untouched default as
                   a deliberate decision. A visitor from "Tamp." would then be
                   filed as domestic on every line the manager never touched.
                   So the form posts what it displayed, and the comparison is
                   against that. */
                $shown    = (string) ($row['suggested_type'] ?? '');
                $override = (string) ($row['tourist_type'] ?? '');

                $overridden = in_array($override, self::TYPES, true)
                    && in_array($shown, self::TYPES, true)
                    && $override !== $shown;

                /* The paper logbook has no sex column, so this is blank unless
                   somebody actually knows. NULL is a supported answer on the
                   office's own form — guessing it from a first name would put
                   an invented figure into a report to the DOT. */
                $sex = (string) ($row['sex'] ?? '');

                Database::run(
                    'INSERT INTO arrival_report_entries
                        (report_id, visit_date, row_no, full_name, address_text, contact_number, sex,
                         tourist_type, origin_city, origin_province, origin_country, confidence)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $reportId,
                        $date,
                        $lineNo,
                        mb_substr($name, 0, 160),
                        $address !== '' ? mb_substr($address, 0, 160) : null,
                        ($c = trim((string) ($row['contact_number'] ?? ''))) !== '' ? mb_substr($c, 0, 40) : null,
                        in_array($sex, ['male', 'female'], true) ? $sex : null,
                        $overridden ? $override : $guess['tourist_type'],
                        $guess['origin_city'],
                        $guess['origin_province'],
                        $guess['origin_country'],
                        $overridden ? 'high' : $guess['confidence'],
                    ]
                );
            }

            self::recomputeDay($reportId, $date);
        });
    }

    /**
     * Rebuilds one day's figures from the lines on its page.
     *
     * The only writer of arrival_report_days on this path. A page with no lines
     * left removes the day entirely rather than leaving a row of zeroes, which
     * would submit as "the site was open and nobody came" instead of "there is
     * no page for that date".
     */
    public static function recomputeDay(int $reportId, string $date): void
    {
        $totals = Database::first(
            "SELECT SUM(tourist_type = 'local')             AS local_count,
                    SUM(tourist_type = 'domestic')          AS domestic_count,
                    SUM(tourist_type = 'foreign')           AS foreign_count,
                    SUM(tourist_type = 'overseas_filipino') AS ofw_count,
                    COUNT(*)                                AS entries
               FROM arrival_report_entries
              WHERE report_id = ? AND visit_date = ?",
            [$reportId, $date]
        );

        if ($totals === null || (int) $totals['entries'] === 0) {
            Database::run(
                'DELETE FROM arrival_report_days WHERE report_id = ? AND visit_date = ?',
                [$reportId, $date]
            );

            return;
        }

        /* The sex and age columns stay NULL: the paper form does not ask for
           them, and inventing a breakdown the source never recorded would put
           numbers into a municipal report that no page supports. */
        Database::run(
            'INSERT INTO arrival_report_days
                (report_id, visit_date, local_count, domestic_count, foreign_count, ofw_count)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                local_count    = VALUES(local_count),
                domestic_count = VALUES(domestic_count),
                foreign_count  = VALUES(foreign_count),
                ofw_count      = VALUES(ofw_count)',
            [
                $reportId,
                $date,
                (int) $totals['local_count'],
                (int) $totals['domestic_count'],
                (int) $totals['foreign_count'],
                (int) $totals['ofw_count'],
            ]
        );
    }

    /** Rebuilds every day of a report. Used after a period change drops dates. */
    public static function recomputeAll(int $reportId): void
    {
        Database::run('DELETE FROM arrival_report_days WHERE report_id = ?', [$reportId]);

        foreach (self::pages($reportId) as $page) {
            self::recomputeDay($reportId, (string) $page['visit_date']);
        }
    }

    /**
     * Drops pages that fall outside the report's period.
     *
     * A manager who narrows the dates after typing would otherwise submit lines
     * for days the report claims not to cover — and those days would still be
     * written into the summary on approval.
     */
    public static function trimToPeriod(int $reportId, string $start, string $end): int
    {
        $removed = (int) Database::scalar(
            'SELECT COUNT(*) FROM arrival_report_entries
              WHERE report_id = ? AND (visit_date < ? OR visit_date > ?)',
            [$reportId, $start, $end]
        );

        if ($removed > 0) {
            Database::run(
                'DELETE FROM arrival_report_entries
                  WHERE report_id = ? AND (visit_date < ? OR visit_date > ?)',
                [$reportId, $start, $end]
            );

            self::recomputeAll($reportId);
        }

        return $removed;
    }

    // -------------------------------------------------------------------------
    // Crossing into the municipality's record
    // -------------------------------------------------------------------------

    /**
     * Copies an approved report's lines into tourist_arrivals.
     *
     * Called from inside ArrivalReportRepository::approve()'s transaction, so a
     * report that is marked approved and a set of arrival records always land
     * together or not at all.
     *
     * Existing copies are cleared first: approving a corrected report a second
     * time must replace what the first approval published, not add to it.
     */
    public static function publish(int $reportId, int $destinationId): int
    {
        Database::run('DELETE FROM tourist_arrivals WHERE report_id = ?', [$reportId]);

        $written = 0;

        foreach (Database::all('SELECT * FROM arrival_report_entries WHERE report_id = ? ORDER BY visit_date, row_no', [$reportId]) as $e) {
            $date = (string) $e['visit_date'];

            Database::run(
                'INSERT INTO tourist_arrivals
                    (destination_id, report_id, visit_date, arrived_at, full_name, contact_number, sex,
                     tourist_type, origin_country, origin_province, origin_city,
                     logbook_address, logbook_row,
                     companions_count, total_visitors, consent_given, source, status)
                 /* consent_given = 0, deliberately.
                    The paper page has a Signature column, but the form carries
                    no data-privacy notice above it — a signature collected
                    without one is not evidence of consent to process personal
                    data under RA 10173, and recording 1 here would be the
                    system asserting something it cannot show. Once the office
                    adds a privacy line to the printed form, this becomes 1.
                    Nothing gates on the flag today; it is reported and
                    exported, which is exactly where an auditor should see the
                    honest answer. */
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,1,0,\'manual\',\'valid\')',
                [
                    $destinationId,
                    $reportId,
                    $date,
                    /* Midday, matching the convention the manual entry screen
                       already uses for a backdated record: the paper page
                       carries a date and no time, and midnight would read as a
                       precision nobody has. */
                    $date . ' 12:00:00',
                    $e['full_name'],
                    $e['contact_number'],
                    $e['sex'],
                    $e['tourist_type'],
                    $e['origin_country'],
                    $e['origin_province'],
                    $e['origin_city'],
                    $e['address_text'],
                    (int) $e['row_no'],
                ]
            );

            $written++;
        }

        return $written;
    }

    /**
     * Removes an approval's published rows.
     *
     * Called when an approved report is sent back. The draft in
     * arrival_report_entries is untouched — that is what the manager corrects.
     */
    public static function withdraw(int $reportId): void
    {
        Database::run('DELETE FROM tourist_arrivals WHERE report_id = ?', [$reportId]);
    }
}
