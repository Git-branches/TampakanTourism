<?php
declare(strict_types=1);

namespace App\Core;

/**
 * =============================================================================
 *  TourSync — Tourism Attraction Visitor Record                      Feature 2
 * -----------------------------------------------------------------------------
 *  The monthly form the Municipal Tourism Office actually submits. Built to the
 *  office's own sheet, column for column, so what the system prints is what
 *  they already file rather than a reinterpretation they have to transcribe.
 *
 *  THE SPLIT IS BY PROVINCE, NOT MUNICIPALITY
 *
 *  The sheet reads "This province" / "Other Province" / "Foreign Country
 *  Residence". Polomolok, Koronadal and Tupi are South Cotabato, so they belong
 *  in the SAME column as Tampakan — a municipality-level split would scatter
 *  them into the wrong one. OriginClassifier records origin_province for exactly
 *  this reason; this class does the grouping.
 *
 *  WHAT IS COUNTED
 *
 *  Only approved reports. tourist_arrivals is written by approval and by nothing
 *  else on this path, so a figure appearing here has been through a manager and
 *  an officer. Voided rows are excluded.
 *
 *  SEX MAY BE UNKNOWN, AND THAT IS ALLOWED
 *
 *  The paper logbook has no sex column. The office's own note says
 *  "** Sex & ***Residence entries are optional. Total number of this month must
 *  be reported", so Total is authoritative and Male + Female may come to less
 *  than it. The unspecified remainder is carried explicitly rather than being
 *  quietly folded into one of the two, which would put an invented figure into
 *  a report to the DOT.
 * =============================================================================
 */
final class VisitorRecord
{
    /** The three residence columns of the sheet, in its own order. */
    public const COLUMNS = ['this_province', 'other_province', 'foreign'];

    /**
     * Builds one month of the record.
     *
     * @param  int  $year
     * @param  int  $month
     * @param  bool $gensanIsLocal Whether General Santos City counts as "this
     *              province". It is geographically inside South Cotabato and
     *              administratively independent of it, so the answer is the
     *              Tourism Officer's to give — the screen asks rather than this
     *              class deciding.
     * @return array<string, mixed>
     */
    public static function build(int $year, int $month, bool $gensanIsLocal = false): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', (int) strtotime($start));

        $province = (string) (setting('office_province') ?: 'South Cotabato');

        /* Attractions come from the destinations table, not from whoever
           happened to have visitors — the sheet lists every attraction in the
           municipality, and one with no arrivals this month prints an empty row
           rather than vanishing. A missing line reads as an oversight; an empty
           one reads as "nobody came", which is the fact. */
        $attractions = Database::all(
            "SELECT id, name, attraction_code
               FROM destinations
              WHERE status = 'active'
              ORDER BY name ASC"
        );

        $blank = [
            'this_province'  => ['male' => 0, 'female' => 0, 'unspecified' => 0, 'total' => 0],
            'other_province' => ['male' => 0, 'female' => 0, 'unspecified' => 0, 'total' => 0],
            'foreign'        => ['male' => 0, 'female' => 0, 'unspecified' => 0, 'total' => 0],
            'grand'          => ['male' => 0, 'female' => 0, 'unspecified' => 0, 'total' => 0],
        ];

        $rows = [];

        foreach ($attractions as $attraction) {
            $rows[(int) $attraction['id']] = [
                'id'      => (int) $attraction['id'],
                'name'    => (string) $attraction['name'],
                'code'    => (string) ($attraction['attraction_code'] ?? ''),
                'figures' => $blank,
            ];
        }

        /* ONLY WHAT A MANAGER SUBMITTED AND THE OFFICE APPROVED.
         *
         * The join to arrival_reports is the rule, not an optimisation. This
         * sheet is filed with the DOT, and every figure on it has to answer
         * "where did this come from" with a report: a named manager, a date, a
         * logbook page behind it.
         *
         * Filtering on status='valid' alone was not that guarantee. It counted
         * any row in the table, and the table has other writers — the retired
         * QR endpoint, and until today a manual-entry screen where an officer
         * could type five hundred visitors straight onto this form with no
         * manager, no review and nothing to point at. The rule now holds
         * whatever else ever writes there.
         *
         * Bucketed in PHP rather than in four aggregate queries: the General
         * Santos rule is a runtime choice, and expressing it as SQL in three
         * places is three places for it to drift. */
        $arrivals = Database::all(
            "SELECT a.destination_id, a.tourist_type, a.origin_province, a.sex,
                    COALESCE(SUM(a.total_visitors), 0) AS visitors
               FROM tourist_arrivals a
               JOIN arrival_reports r ON r.id = a.report_id AND r.status = 'approved'
              WHERE a.status = 'valid'
                AND a.visit_date BETWEEN ? AND ?
              GROUP BY a.destination_id, a.tourist_type, a.origin_province, a.sex",
            [$start, $end]
        );

        /* Rows in the month that this sheet deliberately does NOT count.
         *
         * Surfaced rather than dropped in silence. If something ever writes an
         * arrival with no approved report behind it, the officer should learn
         * that from the screen instead of from a total that quietly disagrees
         * with the arrivals list. Zero on a healthy system. */
        $excluded = (int) Database::scalar(
            "SELECT COALESCE(SUM(a.total_visitors), 0)
               FROM tourist_arrivals a
               LEFT JOIN arrival_reports r ON r.id = a.report_id
              WHERE a.status = 'valid'
                AND a.visit_date BETWEEN ? AND ?
                AND (a.report_id IS NULL OR r.status <> 'approved')",
            [$start, $end]
        );

        $unknownProvince = 0;

        foreach ($arrivals as $arrival) {
            $destinationId = (int) $arrival['destination_id'];

            /* An arrival for an archived destination still happened. It is
               counted into the month's total even though the attraction has no
               printed line, so the bottom figure stays true. */
            if (!isset($rows[$destinationId])) {
                $rows[$destinationId] = [
                    'id'       => $destinationId,
                    'name'     => (string) (Database::scalar('SELECT name FROM destinations WHERE id = ?', [$destinationId]) ?: 'Unknown attraction'),
                    'code'     => '',
                    'figures'  => $blank,
                    'archived' => true,
                ];
            }

            $column = self::columnFor(
                (string) $arrival['tourist_type'],
                $arrival['origin_province'] !== null ? (string) $arrival['origin_province'] : null,
                $province,
                $gensanIsLocal
            );

            if ($column === null) {
                /* Residence not recorded. Counted in the grand total — the
                   person came — but not placed in a residence column it cannot
                   be shown to belong to. */
                $unknownProvince += (int) $arrival['visitors'];

                $bucket = match ($arrival['sex']) {
                    'male'   => 'male',
                    'female' => 'female',
                    default  => 'unspecified',
                };

                $rows[$destinationId]['figures']['grand'][$bucket] += (int) $arrival['visitors'];
                $rows[$destinationId]['figures']['grand']['total']  += (int) $arrival['visitors'];

                continue;
            }

            $bucket   = match ($arrival['sex']) {
                'male'   => 'male',
                'female' => 'female',
                default  => 'unspecified',
            };
            $visitors = (int) $arrival['visitors'];

            $rows[$destinationId]['figures'][$column][$bucket] += $visitors;
            $rows[$destinationId]['figures'][$column]['total'] += $visitors;
            $rows[$destinationId]['figures']['grand'][$bucket] += $visitors;
            $rows[$destinationId]['figures']['grand']['total'] += $visitors;
        }

        /* Column order follows the sheet: alphabetical by attraction. */
        uasort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $totals = $blank;

        foreach ($rows as $row) {
            foreach (array_keys($blank) as $column) {
                foreach (['male', 'female', 'unspecified', 'total'] as $bucket) {
                    $totals[$column][$bucket] += $row['figures'][$column][$bucket];
                }
            }
        }

        return [
            'month'            => $month,
            'year'             => $year,
            'period_start'     => $start,
            'period_end'       => $end,
            'month_label'      => strtoupper(date('F Y', (int) strtotime($start))),
            'province'         => $province,
            'municipality'     => self::municipality(),
            'rows'             => array_values($rows),
            'totals'           => $totals,
            'unknown_province' => $unknownProvince,
            'gensan_is_local'  => $gensanIsLocal,
            'has_data'         => $totals['grand']['total'] > 0,

            /* Visitors in this month that exist in the arrivals table but are
               NOT behind an approved report, so this sheet does not count them.
               Zero on a healthy system; if it is not, the screen says so. */
            'excluded'         => $excluded,
        ];
    }

    /**
     * Which residence column an arrival belongs in.
     *
     * OFWs go under Foreign Country Residence: the sheet's heading is
     * "Foreign Country RESIDENCE", and an overseas Filipino worker resides
     * abroad. Their nationality is not what this column asks about.
     *
     * @return string|null null when residence was never recorded
     */
    private static function columnFor(
        string $touristType,
        ?string $originProvince,
        string $officeProvince,
        bool $gensanIsLocal
    ): ?string {
        if ($touristType === 'foreign' || $touristType === 'overseas_filipino') {
            return 'foreign';
        }

        /* 'local' is set by the classifier only for the office's own
           municipality, which is always inside the office's own province. */
        if ($touristType === 'local') {
            return 'this_province';
        }

        if ($originProvince === null || $originProvince === '') {
            return null;
        }

        if (strcasecmp($originProvince, $officeProvince) === 0) {
            return 'this_province';
        }

        if ($gensanIsLocal && stripos($originProvince, 'General Santos') !== false) {
            return 'this_province';
        }

        return 'other_province';
    }

    /** "Municipality of Tampakan" -> "Tampakan", for the group heading. */
    public static function municipality(): string
    {
        $name = (string) (setting('office_municipality') ?: 'Tampakan');

        return trim((string) preg_replace('/^(municipality|city)\s+of\s+/i', '', $name));
    }

    /**
     * The two signatures at the foot of the sheet.
     *
     * Held in settings and overridable per print, because the people change:
     * a data encoder resigns, a coordinator is reassigned, and a report that
     * can only be signed by whoever held the post when the system was installed
     * is a report somebody retypes in Word.
     *
     * @param  array<string, string> $overrides
     * @return array<string, string>
     */
    public static function signatories(array $overrides = []): array
    {
        $defaults = [
            'prepared_by'       => (string) (setting('report_prepared_by') ?: ''),
            'prepared_by_title' => (string) (setting('report_prepared_by_title') ?: 'Data Encoder'),
            'approved_by'       => (string) (setting('report_approved_by') ?: ''),
            'approved_by_title' => (string) (setting('report_approved_by_title') ?: 'Municipal Tourism Coordinator'),
        ];

        foreach ($defaults as $key => $value) {
            $supplied = trim((string) ($overrides[$key] ?? ''));

            if ($supplied !== '') {
                $defaults[$key] = mb_substr($supplied, 0, 120);
            }
        }

        return $defaults;
    }

    /** A dash where the sheet leaves a dash, so an empty cell is visibly empty. */
    public static function cell(int $value): string
    {
        return $value > 0 ? number_format($value) : '-';
    }
}
