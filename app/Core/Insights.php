<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Assisted decision support.                          Feature 4 / Problem 5
 *
 * -----------------------------------------------------------------------------
 * THERE IS NO MACHINE LEARNING IN THIS CLASS.
 * -----------------------------------------------------------------------------
 *
 * Every figure below is arithmetic a spreadsheet could reproduce: sums,
 * averages, a percentile, and an ordinary least-squares line. That is a
 * deliberate choice, not a shortcut. The Office has to defend these numbers to
 * a Mayor and a provincial tourism officer, and a figure nobody can explain is
 * a figure nobody should act on.
 *
 * Each method returns its own method name and limitation alongside the result,
 * so the screen can print them next to the number rather than presenting a
 * bare figure as if it were certain.
 */
final class Insights
{
    /** Below this many months of history, no forecast is offered at all. */
    public const MIN_MONTHS_FOR_FORECAST = 12;

    /** Two full years are needed before seasonality means anything. */
    public const MONTHS_FOR_SEASONALITY = 24;

    // -------------------------------------------------------------------------
    // History
    // -------------------------------------------------------------------------

    /** Monthly visitor totals, oldest first. */
    public static function monthlyHistory(int $months = 36): array
    {
        $months = max(1, min($months, 120));

        $rows = Database::all(
            "SELECT DATE_FORMAT(visit_date, '%Y-%m') AS ym,
                    COALESCE(SUM(total_visitors), 0) AS visitors
               FROM tourist_arrivals
              WHERE status = 'valid'
                AND visit_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL {$months} MONTH)
              GROUP BY ym
              ORDER BY ym"
        );

        // Months with no arrivals must appear as zero. Omitting them makes a
        // quiet season look like a short one and skews every average below.
        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row['ym']] = (int) $row['visitors'];
        }

        $out = [];
        if ($byMonth !== []) {
            $cursor = min(array_keys($byMonth)) . '-01';
            $stop   = date('Y-m-01');

            while (strtotime($cursor) <= strtotime($stop)) {
                $key   = date('Y-m', strtotime($cursor));
                $out[] = ['month' => $key, 'label' => date('M Y', strtotime($cursor)), 'visitors' => $byMonth[$key] ?? 0];
                $cursor = date('Y-m-01', strtotime($cursor . ' +1 month'));
            }
        }

        return $out;
    }

    public static function monthsOfData(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(DISTINCT DATE_FORMAT(visit_date, '%Y-%m'))
               FROM tourist_arrivals WHERE status = 'valid'"
        );
    }

    // -------------------------------------------------------------------------
    // Trend
    // -------------------------------------------------------------------------

    /**
     * Direction of travel over recent months.
     *
     * Describes what happened. It does not explain why — a drop could be
     * weather, a road closure, or simply that nobody installed the QR sign.
     */
    public static function trend(int $months = 6): array
    {
        $history = self::monthlyHistory(max($months * 2, 12));
        $recent  = array_slice($history, -$months);

        if (count($recent) < 2) {
            return [
                'available'  => false,
                'reason'     => 'At least two months of records are needed before a trend can be described.',
                'method'     => 'Period-over-period comparison',
            ];
        }

        $values = array_column($recent, 'visitors');
        $half   = (int) floor(count($values) / 2);

        $older  = array_slice($values, 0, $half);
        $newer  = array_slice($values, -$half);

        $olderAvg = $older ? array_sum($older) / count($older) : 0;
        $newerAvg = $newer ? array_sum($newer) / count($newer) : 0;

        $change = $olderAvg > 0 ? (($newerAvg - $olderAvg) / $olderAvg) * 100 : null;

        return [
            'available'    => true,
            'months'       => count($recent),
            'series'       => $recent,
            'moving_avg'   => self::movingAverage($values, 3),
            'older_avg'    => round($olderAvg, 1),
            'newer_avg'    => round($newerAvg, 1),
            'change_pct'   => $change === null ? null : round($change, 1),
            'direction'    => $change === null ? 'unknown' : ($change > 5 ? 'rising' : ($change < -5 ? 'falling' : 'steady')),
            'method'       => 'Average of the most recent ' . count($newer) . ' month(s) compared with the ' . count($older) . ' before them, plus a 3-month moving average.',
            'limitation'   => 'Describes what happened, not why. A fall may reflect weather, access, or missing signage rather than lower interest.',
        ];
    }

    /** Simple moving average, used to smooth a noisy series. */
    public static function movingAverage(array $values, int $window = 3): array
    {
        $out = [];
        $count = count($values);

        for ($i = 0; $i < $count; $i++) {
            $slice = array_slice($values, max(0, $i - $window + 1), min($window, $i + 1));
            $out[] = round(array_sum($slice) / max(1, count($slice)), 1);
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Forecast
    // -------------------------------------------------------------------------

    /**
     * Next month's expected arrivals.
     *
     * Two methods, blended:
     *
     *   Seasonal naive  — the same month last year. Tourism is strongly
     *                     seasonal, so last February predicts this February
     *                     better than last month does.
     *   Linear trend    — ordinary least squares over the whole series, to
     *                     carry growth or decline the seasonal figure misses.
     *
     * Below twelve months of history this returns unavailable rather than a
     * number. That refusal is the honest answer: a line drawn through four
     * points is decoration, and presenting one to a Mayor who then plans
     * staffing around it does real harm.
     */
    public static function forecast(): array
    {
        $history = self::monthlyHistory(36);
        $months  = count($history);

        $base = [
            'method'     => 'Seasonal-naive (same month last year) blended with an ordinary least-squares trend line.',
            'limitation' => 'Requires ' . self::MIN_MONTHS_FOR_FORECAST . ' months of history, and ' . self::MONTHS_FOR_SEASONALITY . ' before seasonality is meaningful. Assumes conditions similar to the past — it cannot know about a road closure, a festival, or a typhoon.',
        ];

        if ($months < self::MIN_MONTHS_FOR_FORECAST) {
            $needed = self::MIN_MONTHS_FOR_FORECAST - $months;

            return $base + [
                'available'      => false,
                'months_of_data' => $months,
                'months_needed'  => $needed,
                'reason'         => sprintf(
                    'Insufficient history. %d month(s) of records exist; %d are required. Forecasting becomes available in approximately %d month(s).',
                    $months, self::MIN_MONTHS_FOR_FORECAST, $needed
                ),
            ];
        }

        $values = array_column($history, 'visitors');

        // Same month one year ago.
        $seasonal = $months >= 12 ? $values[$months - 12] : null;

        // Least-squares line projected one step beyond the series.
        $trend = self::leastSquaresNext($values);

        $estimate = ($seasonal !== null && $seasonal > 0)
            ? (int) round(($seasonal * 0.6) + ($trend * 0.4))
            : (int) round($trend);

        $estimate = max(0, $estimate);

        // Confidence widens when the series is volatile. Presented as a range,
        // because a single number implies a precision this method lacks.
        $recent = array_slice($values, -6);
        $mean   = array_sum($recent) / max(1, count($recent));
        $spread = $mean > 0 ? sqrt(array_sum(array_map(static fn($v) => ($v - $mean) ** 2, $recent)) / count($recent)) : 0;

        return $base + [
            'available'      => true,
            'months_of_data' => $months,
            'seasonal'       => $seasonal,
            'trend'          => (int) round($trend),
            'estimate'       => $estimate,
            'range_low'      => max(0, (int) round($estimate - $spread)),
            'range_high'     => (int) round($estimate + $spread),
            'target_month'   => date('F Y', strtotime('+1 month')),
            'seasonality'    => $months >= self::MONTHS_FOR_SEASONALITY,
            'confidence'     => $months >= self::MONTHS_FOR_SEASONALITY ? 'moderate' : 'low',
        ];
    }

    /** Projects one step past the end of a series using least squares. */
    private static function leastSquaresNext(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return $values[0] ?? 0.0;
        }

        $sumX = $sumY = $sumXY = $sumXX = 0.0;

        foreach ($values as $i => $y) {
            $x      = $i + 1;
            $sumX  += $x;
            $sumY  += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }

        $denominator = ($n * $sumXX) - ($sumX ** 2);
        if ($denominator == 0.0) {
            return $sumY / $n;
        }

        $slope     = (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        return $intercept + ($slope * ($n + 1));
    }

    // -------------------------------------------------------------------------
    // Identification and recommendations
    // -------------------------------------------------------------------------

    /**
     * Destinations carrying unusually heavy days.
     *
     * The threshold is the 90th percentile of that destination's own daily
     * totals, not a municipality-wide number: a site that averages eight
     * visitors and a site that averages three hundred cannot share a
     * definition of "busy".
     */
    public static function highTraffic(int $days = 90): array
    {
        $days = max(7, min($days, 365));

        $rows = Database::all(
            "SELECT d.id, d.name,
                    s.visit_date, s.total_visitors
               FROM arrival_daily_summary s
               JOIN destinations d ON d.id = s.destination_id
              WHERE d.status = 'active'
                AND s.visit_date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
              ORDER BY d.name, s.visit_date"
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['id']]['name'] = $row['name'];
            $grouped[$row['id']]['days'][] = (int) $row['total_visitors'];
        }

        $out = [];

        foreach ($grouped as $id => $data) {
            $values = $data['days'];
            sort($values);

            $count = count($values);
            if ($count < 5) {
                continue;   // too few days to describe a normal one
            }

            $p90   = $values[(int) floor($count * 0.9)] ?? end($values);
            $busy  = count(array_filter($data['days'], static fn($v) => $v >= $p90 && $p90 > 0));
            $total = array_sum($data['days']);

            $out[] = [
                'id'            => $id,
                'name'          => $data['name'],
                'total'         => $total,
                'active_days'   => $count,
                'daily_average' => round($total / max(1, $count), 1),
                'busiest_day'   => max($data['days']),
                'threshold'     => $p90,
                'days_over'     => $busy,
            ];
        }

        usort($out, static fn($a, $b) => $b['total'] <=> $a['total']);

        return $out;
    }

    /**
     * Explicit, rule-based prompts for human judgement.
     *
     * Each carries the rule that produced it, so an officer can disagree with
     * the rule rather than with a black box. None of these are instructions —
     * the system knows nothing about budget, staffing, or road conditions.
     */
    public static function recommendations(): array
    {
        $out = [];

        // --- Destinations under sustained pressure ---
        foreach (self::highTraffic(90) as $d) {
            if ($d['days_over'] >= 5 && $d['daily_average'] >= 10) {
                $out[] = [
                    'priority' => 'high',
                    'icon'     => 'fa-users',
                    'title'    => $d['name'] . ' is regularly at its busiest level',
                    'detail'   => sprintf(
                        '%d of the last %d recorded days reached %d or more visitors, against a daily average of %s. Consider additional guides, sanitation, or parking on peak days.',
                        $d['days_over'], $d['active_days'], $d['threshold'], $d['daily_average']
                    ),
                    'rule'     => 'Five or more days at or above the destination\'s own 90th-percentile daily total, in the last 90 days.',
                ];
            }
        }

        // --- Destinations recording nothing ---
        $silent = Database::all(
            "SELECT d.name,
                    (SELECT MAX(visit_date) FROM tourist_arrivals a
                      WHERE a.destination_id = d.id AND a.status='valid') AS last_seen
               FROM destinations d
              WHERE d.status = 'active'
              HAVING last_seen IS NULL OR last_seen < DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        );

        foreach ($silent as $d) {
            $out[] = [
                'priority' => 'medium',
                'icon'     => 'fa-qrcode',
                'title'    => $d['name'] . ' has recorded no arrivals recently',
                'detail'   => $d['last_seen'] === null
                    ? 'No arrival has ever been recorded here. Check that the QR poster is printed, installed, and readable.'
                    : 'The last recorded arrival was ' . format_date($d['last_seen']) . '. This may mean genuinely no visitors, or a missing or damaged QR sign.',
                'rule'     => 'No valid arrival in the last 30 days.',
            ];
        }

        // --- Records awaiting review ---
        $flagged = (int) Database::scalar("SELECT COUNT(*) FROM tourist_arrivals WHERE status='flagged'");
        if ($flagged > 0) {
            $out[] = [
                'priority' => 'medium',
                'icon'     => 'fa-flag',
                'title'    => $flagged . ' arrival record(s) awaiting review',
                'detail'   => 'These are excluded from every published figure until an officer approves or voids them, so current totals understate arrivals by that amount.',
                'rule'     => 'Any arrival with status "flagged".',
            ];
        }

        // --- Destinations nobody can be notified about ---
        $unmanaged = Database::all(
            "SELECT d.name FROM destinations d
               LEFT JOIN destination_managers m ON m.destination_id = d.id AND m.is_active = 1
              WHERE d.status='active' AND m.id IS NULL"
        );

        if ($unmanaged !== []) {
            $out[] = [
                'priority' => 'medium',
                'icon'     => 'fa-address-book',
                'title'    => count($unmanaged) . ' destination(s) have no manager on record',
                'detail'   => 'Nobody at ' . implode(', ', array_column($unmanaged, 'name'))
                    . ' receives advisories or closure notices from this system.',
                'rule'     => 'An active destination with no active manager.',
            ];
        }

        // --- Weekday concentration ---
        $weekday = Database::all(
            "SELECT DAYNAME(visit_date) AS day, COALESCE(SUM(total_visitors),0) AS visitors
               FROM tourist_arrivals
              WHERE status='valid' AND visit_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
              GROUP BY day ORDER BY visitors DESC"
        );

        if (count($weekday) >= 3) {
            $total = array_sum(array_column($weekday, 'visitors'));
            $top   = $weekday[0];

            if ($total > 0 && ($top['visitors'] / $total) > 0.35) {
                $out[] = [
                    'priority' => 'low',
                    'icon'     => 'fa-calendar-week',
                    'title'    => 'Arrivals concentrate heavily on ' . $top['day'] . 's',
                    'detail'   => sprintf(
                        '%s%% of the last 90 days\' visitors arrived on a %s. Staffing and supplies could be weighted towards that day rather than spread evenly.',
                        round(($top['visitors'] / $total) * 100), $top['day']
                    ),
                    'rule'     => 'One weekday accounting for more than 35% of visitors over 90 days.',
                ];
            }
        }

        // --- Forecast, when it is available at all ---
        $forecast = self::forecast();
        if (!empty($forecast['available'])) {
            $out[] = [
                'priority' => 'low',
                'icon'     => 'fa-chart-line',
                'title'    => 'Expect roughly ' . number_format($forecast['estimate']) . ' visitors in ' . $forecast['target_month'],
                'detail'   => sprintf(
                    'Likely between %s and %s, based on %d months of records. Confidence is %s.',
                    number_format($forecast['range_low']), number_format($forecast['range_high']),
                    $forecast['months_of_data'], $forecast['confidence']
                ),
                'rule'     => $forecast['method'],
            ];
        }

        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($out, static fn($a, $b) => $order[$a['priority']] <=> $order[$b['priority']]);

        return $out;
    }
}
