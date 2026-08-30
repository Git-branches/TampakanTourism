<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\ArrivalRepository;

/**
 * Builds tourism reports from recorded arrivals.       Feature 4 / Problem 5
 *
 * Everything here is a query. The Office previously produced these figures by
 * hand from paper logbooks, which is where both the delay and the arithmetic
 * errors came from; the point is not that a computer is cleverer, but that it
 * does not get tired on the third page of tallies.
 *
 * One rule runs through every method: only rows with status 'valid' are
 * counted. Flagged records await review and voided records were removed by an
 * officer with a stated reason, so neither belongs in a published figure.
 */
final class ReportBuilder
{
    public const PERIODS = [
        'daily'     => 'Daily',
        'monthly'   => 'Monthly',
        'quarterly' => 'Quarterly',
        'annual'    => 'Annual',
        'custom'    => 'Custom range',
    ];

    /**
     * Resolves a period selection into a concrete date range.
     *
     * @return array{start:string, end:string, label:string}
     */
    /**
     * A date this class can hand to strtotime(), or the fallback.
     *
     * strtotime() answers `false` for anything it cannot read, and PHP 8's
     * date() throws a TypeError when given it. Every period below is built out
     * of numbers taken straight from the query string, so `?month=13` became
     * "2026-13-01", became false, and became a white screen on the reports
     * page — as did a mistyped `?date=`. Neither is exotic: a month is chosen
     * from a dropdown, but the URL it produces is editable, bookmarkable, and
     * gets pasted into chat messages.
     */
    private static function validDate(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $fallback;
        }

        $stamp = strtotime($value);

        return $stamp === false ? $fallback : date('Y-m-d', $stamp);
    }

    public static function resolvePeriod(string $type, array $params): array
    {
        /* Clamped rather than rejected. An officer who lands here with a bad
           number wants a report, not an error page — they get the nearest
           period that exists, with its name printed at the top so it is
           obvious which one they are looking at. */
        $year    = min(2100, max(2000, (int) ($params['year']  ?? date('Y'))));
        $month   = min(12,   max(1,    (int) ($params['month'] ?? date('n'))));
        $quarter = min(4,    max(1,    (int) ($params['quarter'] ?? ceil((int) date('n') / 3))));

        switch ($type) {
            case 'daily':
                $date = self::validDate($params['date'] ?? '', date('Y-m-d'));
                return ['start' => $date, 'end' => $date, 'label' => format_date($date, 'F j, Y')];

            case 'monthly':
                $start = sprintf('%04d-%02d-01', $year, $month);
                return [
                    'start' => $start,
                    'end'   => date('Y-m-t', strtotime($start)),
                    'label' => date('F Y', strtotime($start)),
                ];

            case 'quarterly':
                $startMonth = (($quarter - 1) * 3) + 1;
                $start = sprintf('%04d-%02d-01', $year, $startMonth);
                $end   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $startMonth + 2)));
                return ['start' => $start, 'end' => $end, 'label' => "Q{$quarter} {$year} (" . date('F', strtotime($start)) . ' – ' . date('F Y', strtotime($end)) . ')'];

            case 'annual':
                return [
                    'start' => "{$year}-01-01",
                    'end'   => "{$year}-12-31",
                    'label' => "Calendar Year {$year}",
                ];

            default: // custom
                $start = self::validDate($params['start'] ?? '', date('Y-m-01'));
                $end   = self::validDate($params['end']   ?? '', date('Y-m-d'));

                /* A range typed backwards produced a report of nothing and no
                   hint as to why. Read the way it was plainly meant. */
                if ($end < $start) {
                    [$start, $end] = [$end, $start];
                }

                return [
                    'start' => $start,
                    'end'   => $end,
                    'label' => format_date($start) . ' – ' . format_date($end),
                ];
        }
    }

    /**
     * The complete report for a period.
     *
     * Assembled in one place so the on-screen view, the print view, and the
     * CSV export cannot disagree with each other about what the month's total
     * was — a report that differs from its own export is worse than none.
     */
    public static function build(string $type, array $params): array
    {
        $period = self::resolvePeriod($type, $params);
        [$start, $end] = [$period['start'], $period['end']];

        return [
            'type'         => $type,
            'period'       => $period,
            'generated_at' => date('Y-m-d H:i:s'),
            'totals'       => self::totals($start, $end),
            'comparison'   => self::comparison($type, $start, $end),
            'destinations' => self::byDestination($start, $end),
            'types'        => self::byTouristType($start, $end),
            'stay'         => self::byStayType($start, $end),
            'demographics' => self::demographics($start, $end),
            'origins'      => self::origins($start, $end),
            'purposes'     => self::byPurpose($start, $end),
            'timeline'     => self::timeline($type, $start, $end),
            'peak'         => self::peakDays($start, $end),
            'integrity'    => self::integrity($start, $end),
        ];
    }

    // -------------------------------------------------------------------------

    public static function totals(string $start, string $end): array
    {
        $row = Database::first(
            "SELECT COUNT(*) AS records,
                    COALESCE(SUM(total_visitors), 0) AS visitors,
                    COALESCE(AVG(total_visitors), 0) AS avg_party,
                    COUNT(DISTINCT destination_id) AS destinations,
                    COUNT(DISTINCT visit_date) AS active_days
               FROM tourist_arrivals
              WHERE status = 'valid' AND visit_date BETWEEN ? AND ?",
            [$start, $end]
        );

        $days = max(1, (int) ((strtotime($end) - strtotime($start)) / 86400) + 1);

        return [
            'records'      => (int) ($row['records'] ?? 0),
            'visitors'     => (int) ($row['visitors'] ?? 0),
            'avg_party'    => round((float) ($row['avg_party'] ?? 0), 1),
            'destinations' => (int) ($row['destinations'] ?? 0),
            'active_days'  => (int) ($row['active_days'] ?? 0),
            'period_days'  => $days,
            'daily_avg'    => round(((int) ($row['visitors'] ?? 0)) / $days, 1),
        ];
    }

    /**
     * The same period one cycle earlier.
     *
     * A total on its own answers nothing an officer can act on. "Up 18% on last
     * month" is the sentence that belongs in a report to the Mayor.
     */
    public static function comparison(string $type, string $start, string $end): array
    {
        $length = (strtotime($end) - strtotime($start)) / 86400 + 1;

        switch ($type) {
            case 'monthly':
                $prevStart = date('Y-m-01', strtotime($start . ' -1 month'));
                $prevEnd   = date('Y-m-t', strtotime($prevStart));
                $label     = 'previous month';
                break;
            case 'quarterly':
                $prevStart = date('Y-m-01', strtotime($start . ' -3 months'));
                $prevEnd   = date('Y-m-t', strtotime($prevStart . ' +2 months'));
                $label     = 'previous quarter';
                break;
            case 'annual':
                $prevStart = date('Y-01-01', strtotime($start . ' -1 year'));
                $prevEnd   = date('Y-12-31', strtotime($prevStart));
                $label     = 'previous year';
                break;
            default:
                $prevEnd   = date('Y-m-d', strtotime($start . ' -1 day'));
                $prevStart = date('Y-m-d', strtotime($prevEnd . ' -' . ($length - 1) . ' days'));
                $label     = 'previous period';
        }

        $current  = (int) Database::scalar(
            "SELECT COALESCE(SUM(total_visitors),0) FROM tourist_arrivals
              WHERE status='valid' AND visit_date BETWEEN ? AND ?", [$start, $end]);
        $previous = (int) Database::scalar(
            "SELECT COALESCE(SUM(total_visitors),0) FROM tourist_arrivals
              WHERE status='valid' AND visit_date BETWEEN ? AND ?", [$prevStart, $prevEnd]);

        return [
            'label'        => $label,
            'previous'     => $previous,
            'current'      => $current,
            'change'       => $current - $previous,
            // A percentage against zero is undefined, not "infinite growth".
            'change_pct'   => $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : null,
            'period_label' => format_date($prevStart) . ' – ' . format_date($prevEnd),
        ];
    }

    public static function byDestination(string $start, string $end): array
    {
        return Database::all(
            "SELECT d.id, d.name, d.barangay,
                    COUNT(a.id) AS records,
                    COALESCE(SUM(a.total_visitors), 0) AS visitors
               FROM destinations d
               LEFT JOIN tourist_arrivals a
                      ON a.destination_id = d.id AND a.status = 'valid'
                     AND a.visit_date BETWEEN ? AND ?
              WHERE d.status = 'active'
              GROUP BY d.id, d.name, d.barangay
              ORDER BY visitors DESC, d.name",
            [$start, $end]
        );
    }

    public static function byTouristType(string $start, string $end): array
    {
        $out = array_fill_keys(array_keys(ArrivalRepository::TYPES), 0);

        foreach (Database::all(
            "SELECT tourist_type, COALESCE(SUM(total_visitors),0) AS visitors
               FROM tourist_arrivals
              WHERE status='valid' AND visit_date BETWEEN ? AND ?
              GROUP BY tourist_type", [$start, $end]
        ) as $row) {
            $out[$row['tourist_type']] = (int) $row['visitors'];
        }

        return $out;
    }

    /**
     * Day visitors and overnight tourists, counted separately.
     *
     * National tourism statistics treat an excursionist and an overnight
     * tourist as different things, so a report that merges them cannot be
     * carried straight into a DOT submission.
     */
    public static function byStayType(string $start, string $end): array
    {
        $out = ['day_trip' => 0, 'overnight' => 0, 'not_stated' => 0];

        foreach (Database::all(
            "SELECT COALESCE(stay_type,'not_stated') AS stay, COALESCE(SUM(total_visitors),0) AS visitors
               FROM tourist_arrivals
              WHERE status='valid' AND visit_date BETWEEN ? AND ?
              GROUP BY stay", [$start, $end]
        ) as $row) {
            $out[$row['stay']] = (int) $row['visitors'];
        }

        return $out;
    }

    /** Age and sex breakdown — the Feature 2 requirement that reports fulfil. */
    public static function demographics(string $start, string $end): array
    {
        $age = array_fill_keys(array_keys(ArrivalRepository::AGE_BRACKETS), 0);
        $age['not_stated'] = 0;

        foreach (Database::all(
            "SELECT COALESCE(age_bracket,'not_stated') AS bracket, COALESCE(SUM(total_visitors),0) AS visitors
               FROM tourist_arrivals
              WHERE status='valid' AND visit_date BETWEEN ? AND ?
              GROUP BY bracket", [$start, $end]
        ) as $row) {
            $age[$row['bracket']] = (int) $row['visitors'];
        }

        $sex = ['male' => 0, 'female' => 0, 'prefer_not_to_say' => 0, 'not_stated' => 0];

        foreach (Database::all(
            "SELECT COALESCE(sex,'not_stated') AS s, COALESCE(SUM(total_visitors),0) AS visitors
               FROM tourist_arrivals
              WHERE status='valid' AND visit_date BETWEEN ? AND ?
              GROUP BY s", [$start, $end]
        ) as $row) {
            $sex[$row['s']] = (int) $row['visitors'];
        }

        return ['age' => $age, 'sex' => $sex];
    }

    /** Where visitors travelled from — cities, provinces, and countries. */
    public static function origins(string $start, string $end, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));

        $query = static function (string $column) use ($start, $end, $limit): array {
            // The column is chosen from a fixed list below, never from request input.
            return Database::all(
                "SELECT {$column} AS place, COALESCE(SUM(total_visitors),0) AS visitors
                   FROM tourist_arrivals
                  WHERE status='valid' AND visit_date BETWEEN ? AND ?
                    AND {$column} IS NOT NULL AND {$column} <> ''
                  GROUP BY {$column}
                  ORDER BY visitors DESC
                  LIMIT {$limit}",
                [$start, $end]
            );
        };

        return [
            'cities'    => $query('origin_city'),
            'provinces' => $query('origin_province'),
            'countries' => $query('origin_country'),
        ];
    }

    public static function byPurpose(string $start, string $end): array
    {
        $out = array_fill_keys(array_keys(ArrivalRepository::PURPOSES), 0);
        $out['not_stated'] = 0;

        foreach (Database::all(
            "SELECT COALESCE(purpose,'not_stated') AS p, COALESCE(SUM(total_visitors),0) AS visitors
               FROM tourist_arrivals
              WHERE status='valid' AND visit_date BETWEEN ? AND ?
              GROUP BY p", [$start, $end]
        ) as $row) {
            $out[$row['p']] = (int) $row['visitors'];
        }

        return $out;
    }

    /**
     * Visitors over time, grouped to suit the period length.
     *
     * A year plotted by day is 365 unreadable points; a week plotted by month
     * is one. The grouping follows the report type rather than a fixed choice.
     */
    public static function timeline(string $type, string $start, string $end): array
    {
        $byMonth = in_array($type, ['annual', 'quarterly'], true)
            || (strtotime($end) - strtotime($start)) > 86400 * 92;

        if ($byMonth) {
            $rows = Database::all(
                "SELECT DATE_FORMAT(visit_date, '%Y-%m') AS bucket,
                        COALESCE(SUM(total_visitors),0) AS visitors
                   FROM tourist_arrivals
                  WHERE status='valid' AND visit_date BETWEEN ? AND ?
                  GROUP BY bucket ORDER BY bucket", [$start, $end]
            );

            return array_map(static fn($r) => [
                'label'    => date('M Y', strtotime($r['bucket'] . '-01')),
                'visitors' => (int) $r['visitors'],
            ], $rows);
        }

        $rows = Database::all(
            "SELECT visit_date AS bucket, COALESCE(SUM(total_visitors),0) AS visitors
               FROM tourist_arrivals
              WHERE status='valid' AND visit_date BETWEEN ? AND ?
              GROUP BY visit_date ORDER BY visit_date", [$start, $end]
        );

        return array_map(static fn($r) => [
            'label'    => date('M j', strtotime($r['bucket'])),
            'visitors' => (int) $r['visitors'],
        ], $rows);
    }

    /** Busiest dates and busiest weekdays — the basis for staffing advice. */
    public static function peakDays(string $start, string $end): array
    {
        $busiest = Database::all(
            "SELECT visit_date, COALESCE(SUM(total_visitors),0) AS visitors
               FROM tourist_arrivals
              WHERE status='valid' AND visit_date BETWEEN ? AND ?
              GROUP BY visit_date ORDER BY visitors DESC LIMIT 5", [$start, $end]
        );

        $weekdays = Database::all(
            "SELECT DAYNAME(visit_date) AS day, DAYOFWEEK(visit_date) AS n,
                    COALESCE(SUM(total_visitors),0) AS visitors
               FROM tourist_arrivals
              WHERE status='valid' AND visit_date BETWEEN ? AND ?
              GROUP BY day, n ORDER BY n", [$start, $end]
        );

        return ['busiest_dates' => $busiest, 'weekdays' => $weekdays];
    }

    /**
     * Records excluded from the figures, and why.
     *
     * Printed on every report on purpose. A total presented without saying
     * what was left out invites the question at the worst possible moment;
     * stating it up front is what makes the number defensible.
     */
    public static function integrity(string $start, string $end): array
    {
        $row = Database::first(
            "SELECT
                SUM(status='valid')   AS valid_records,
                SUM(status='flagged') AS flagged_records,
                SUM(status='voided')  AS voided_records,
                COALESCE(SUM(CASE WHEN status='flagged' THEN total_visitors END),0) AS flagged_visitors,
                COALESCE(SUM(CASE WHEN status='voided'  THEN total_visitors END),0) AS voided_visitors,
                COALESCE(SUM(CASE WHEN status='valid' AND source='qr'     THEN total_visitors END),0) AS qr_visitors,
                COALESCE(SUM(CASE WHEN status='valid' AND source='manual' THEN total_visitors END),0) AS manual_visitors
               FROM tourist_arrivals
              WHERE visit_date BETWEEN ? AND ?",
            [$start, $end]
        );

        return [
            'valid_records'    => (int) ($row['valid_records'] ?? 0),
            'flagged_records'  => (int) ($row['flagged_records'] ?? 0),
            'voided_records'   => (int) ($row['voided_records'] ?? 0),
            'flagged_visitors' => (int) ($row['flagged_visitors'] ?? 0),
            'voided_visitors'  => (int) ($row['voided_visitors'] ?? 0),
            'qr_visitors'      => (int) ($row['qr_visitors'] ?? 0),
            'manual_visitors'  => (int) ($row['manual_visitors'] ?? 0),
        ];
    }

    /** Records a produced report so a figure quoted in a meeting stays reproducible. */
    public static function save(string $type, array $period, ?int $adminId, array $params = []): int
    {
        return Database::insert(
            'INSERT INTO reports (title, type, period_start, period_end, parameters, generated_by)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                self::PERIODS[$type] . ' Report — ' . $period['label'],
                $type,
                $period['start'],
                $period['end'],
                json_encode($params, JSON_UNESCAPED_UNICODE),
                $adminId,
            ]
        );
    }

    public static function history(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        return Database::all(
            "SELECT r.*, a.full_name AS generated_by_name
               FROM reports r
               LEFT JOIN admins a ON a.id = r.generated_by
              ORDER BY r.created_at DESC LIMIT {$limit}"
        );
    }

    /** Earliest and latest recorded arrival — bounds the year pickers. */
    public static function dataRange(): array
    {
        $row = Database::first(
            "SELECT MIN(visit_date) AS first, MAX(visit_date) AS last
               FROM tourist_arrivals WHERE status='valid'"
        );

        return [
            'first' => $row['first'] ?? null,
            'last'  => $row['last'] ?? null,
        ];
    }
}
