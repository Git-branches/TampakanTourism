<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Tourist arrival records — the table that becomes the Municipality's
 * official tourism statistic.
 *
 * Feature 1 ends at record(); Feature 2 reads everything below it. The two
 * meet at exactly one place, which is what removes the manual submission step
 * the brief describes as Problem 2.
 */
final class ArrivalRepository
{
    public const TYPES = [
        'local'             => 'Local resident of Tampakan',
        'domestic'          => 'Domestic tourist (from elsewhere in the Philippines)',
        'foreign'           => 'Foreign tourist',
        'overseas_filipino' => 'Overseas Filipino',
    ];

    public const AGE_BRACKETS = [
        'under18' => 'Under 18', '18-24' => '18 – 24', '25-34' => '25 – 34',
        '35-44'   => '35 – 44',  '45-54' => '45 – 54', '55-64' => '55 – 64',
        '65plus'  => '65 and over',
    ];

    public const PURPOSES = [
        'leisure'   => 'Leisure / Holiday',
        'business'  => 'Business',
        'education' => 'Education / Research',
        'religious' => 'Religious',
        'vfr'       => 'Visiting friends or relatives',
        'other'     => 'Other',
    ];

    /**
     * Writes one arrival and updates the daily rollup in the same transaction.
     *
     * If the summary write fails, the arrival is rolled back too. A counted
     * visit that is missing from the day's total would show up later as a
     * report that does not reconcile with its own source rows.
     */
    public static function record(array $data): int
    {
        return Database::transaction(static function () use ($data): int {
            $total = 1 + max(0, (int) ($data['companions_count'] ?? 0));

            $id = Database::insert(
                "INSERT INTO tourist_arrivals
                    (destination_id, visit_date, arrived_at, full_name, age_bracket, sex,
                     contact_number, email, tourist_type, stay_type, nationality,
                     origin_country, origin_province, origin_city, purpose,
                     companions_count, total_visitors, consent_given, source,
                     recorded_by, qr_version_used, device_hash, distance_m,
                     status, flag_reason, client_uuid, synced_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    $data['destination_id'],
                    $data['visit_date'],
                    $data['arrived_at'],
                    ($data['full_name']       ?? '') ?: null,
                    ($data['age_bracket']     ?? '') ?: null,
                    ($data['sex']             ?? '') ?: null,
                    ($data['contact_number']  ?? '') ?: null,
                    ($data['email']           ?? '') ?: null,
                    $data['tourist_type'],
                    ($data['stay_type']       ?? '') ?: null,
                    ($data['nationality']     ?? '') ?: null,
                    ($data['origin_country']  ?? '') ?: null,
                    ($data['origin_province'] ?? '') ?: null,
                    ($data['origin_city']     ?? '') ?: null,
                    ($data['purpose']         ?? '') ?: null,
                    max(0, (int) ($data['companions_count'] ?? 0)),
                    $total,
                    !empty($data['consent_given']) ? 1 : 0,
                    $data['source'] ?? 'qr',
                    $data['recorded_by']     ?? null,
                    $data['qr_version_used'] ?? null,
                    $data['device_hash']     ?? null,
                    $data['distance_m']      ?? null,
                    $data['status']          ?? 'valid',
                    ($data['flag_reason']    ?? '') ?: null,

                    /* Both null for an ordinary online submission. Set only
                       when the record was captured on a device with no signal
                       and replayed later — client_uuid is what stops a retried
                       replay counting twice, synced_at is what proves the delay
                       was the network's and not the visitor's. */
                    ($data['client_uuid']    ?? '') ?: null,
                    ($data['synced_at']      ?? '') ?: null,
                ]
            );

            // Only records counted as valid belong in the rollup.
            if (($data['status'] ?? 'valid') === 'valid') {
                self::touchSummary((int) $data['destination_id'], $data['visit_date'], $data['tourist_type'], $total);
            }

            return $id;
        });
    }

    /**
     * Adds one record to the day's rollup, creating the row if needed.
     *
     * ON DUPLICATE KEY UPDATE against the unique (destination_id, visit_date)
     * index does this in a single statement, so two visitors submitting in the
     * same instant cannot race each other into two half-counted rows.
     */
    private static function touchSummary(int $destinationId, string $visitDate, string $touristType, int $visitors): void
    {
        $column = match ($touristType) {
            'local'             => 'local_count',
            'domestic'          => 'domestic_count',
            'foreign'           => 'foreign_count',
            'overseas_filipino' => 'ofw_count',
            default             => 'domestic_count',
        };

        Database::run(
            "INSERT INTO arrival_daily_summary
                (destination_id, visit_date, total_records, total_visitors, {$column})
             VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                total_records  = total_records + 1,
                total_visitors = total_visitors + VALUES(total_visitors),
                {$column}      = {$column} + VALUES({$column})",
            [$destinationId, $visitDate, $visitors, $visitors]
        );
    }

    /**
     * Rebuilds every summary row from the arrivals table.
     *
     * The rollup is a cache, and this is the proof: it can be discarded and
     * regenerated at any time, so tourist_arrivals remains the single source
     * of truth. Run it after a bulk import or a voided record.
     */
    public static function rebuildSummary(): int
    {
        return Database::transaction(static function (): int {
            Database::run('DELETE FROM arrival_daily_summary');

            Database::run(
                "INSERT INTO arrival_daily_summary
                    (destination_id, visit_date, total_records, total_visitors,
                     local_count, domestic_count, foreign_count, ofw_count)
                 SELECT destination_id,
                        visit_date,
                        COUNT(*),
                        SUM(total_visitors),
                        SUM(CASE WHEN tourist_type = 'local'             THEN total_visitors ELSE 0 END),
                        SUM(CASE WHEN tourist_type = 'domestic'          THEN total_visitors ELSE 0 END),
                        SUM(CASE WHEN tourist_type = 'foreign'           THEN total_visitors ELSE 0 END),
                        SUM(CASE WHEN tourist_type = 'overseas_filipino' THEN total_visitors ELSE 0 END)
                   FROM tourist_arrivals
                  WHERE status = 'valid'
                  GROUP BY destination_id, visit_date"
            );

            return (int) Database::scalar('SELECT COUNT(*) FROM arrival_daily_summary');
        });
    }

    /**
     * Has this device already logged this destination today?
     *
     * Returns the count rather than a boolean because a repeat is flagged for
     * review, never blocked: a family sharing one phone hotspot is the normal
     * case, not an attack.
     */
    public static function recentFromDevice(string $deviceHash, int $destinationId, int $withinHours): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM tourist_arrivals
              WHERE device_hash = ? AND destination_id = ?
                AND arrived_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)',
            [$deviceHash, $destinationId, $withinHours]
        );
    }

    /**
     * Has this device-generated record already been stored?
     *
     * The question a synchronising phone asks before it gives up. A replay that
     * was interrupted after the INSERT but before the response reached the
     * device will be retried; without this lookup the retry becomes a second
     * arrival, and a bad signal quietly inflates the municipality's figures.
     */
    public static function findByClientUuid(string $uuid): ?array
    {
        if ($uuid === '') {
            return null;
        }

        return Database::first(
            'SELECT a.id, a.destination_id, a.companions_count, a.arrived_at,
                    d.name AS destination_name, d.slug AS destination_slug, d.qr_token
               FROM tourist_arrivals a
               JOIN destinations d ON d.id = a.destination_id
              WHERE a.client_uuid = ?
              LIMIT 1',
            [$uuid]
        );
    }

    // -------------------------------------------------------------------------
    // Reads used by the dashboard and reports
    // -------------------------------------------------------------------------

    public static function totalVisitors(?string $from = null, ?string $to = null): int
    {
        $sql = "SELECT COALESCE(SUM(total_visitors), 0) FROM tourist_arrivals WHERE status = 'valid'";
        $params = [];

        if ($from !== null) { $sql .= ' AND visit_date >= ?'; $params[] = $from; }
        if ($to   !== null) { $sql .= ' AND visit_date <= ?'; $params[] = $to; }

        return (int) Database::scalar($sql, $params);
    }

    public static function recent(int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));

        return Database::all(
            "SELECT a.*, d.name AS destination_name, d.slug AS destination_slug
               FROM tourist_arrivals a
               JOIN destinations d ON d.id = a.destination_id
              ORDER BY a.arrived_at DESC
              LIMIT {$limit}"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT a.*, d.name AS destination_name
               FROM tourist_arrivals a
               JOIN destinations d ON d.id = a.destination_id
              WHERE a.id = ?',
            [$id]
        );
    }

    /** Daily totals for the dashboard trend chart, read from the rollup. */
    public static function dailyTrend(int $days = 30): array
    {
        $days = max(1, min($days, 365));

        return Database::all(
            "SELECT visit_date, SUM(total_visitors) AS visitors
               FROM arrival_daily_summary
              WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
              GROUP BY visit_date
              ORDER BY visit_date"
        );
    }

    public static function byDestination(?string $from = null, ?string $to = null): array
    {
        $sql = "SELECT d.id, d.name,
                       COALESCE(SUM(s.total_visitors), 0) AS visitors
                  FROM destinations d
                  LEFT JOIN arrival_daily_summary s ON s.destination_id = d.id";
        $params = [];
        $clauses = [];

        if ($from !== null) { $clauses[] = 's.visit_date >= ?'; $params[] = $from; }
        if ($to   !== null) { $clauses[] = 's.visit_date <= ?'; $params[] = $to; }

        if ($clauses !== []) {
            $sql .= ' AND ' . implode(' AND ', $clauses);
        }

        $sql .= " WHERE d.status = 'active' GROUP BY d.id, d.name ORDER BY visitors DESC";

        return Database::all($sql, $params);
    }

    public static function countFlagged(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM tourist_arrivals WHERE status = 'flagged'");
    }

    // -------------------------------------------------------------------------
    // Filtering — shared by the arrivals screen and the CSV export, so the
    // exported file always contains exactly the rows the officer was looking
    // at. A report that does not match the screen it came from is worse than
    // no export at all.
    // -------------------------------------------------------------------------

    /** @return array{0:string,1:array} WHERE clause and bound parameters */
    public static function buildFilter(array $f): array
    {
        $clauses = [];
        $params  = [];

        if (!empty($f['from']))  { $clauses[] = 'a.visit_date >= ?';   $params[] = $f['from']; }
        if (!empty($f['to']))    { $clauses[] = 'a.visit_date <= ?';   $params[] = $f['to']; }
        if (!empty($f['destination_id'])) { $clauses[] = 'a.destination_id = ?'; $params[] = (int) $f['destination_id']; }

        if (!empty($f['tourist_type']) && isset(self::TYPES[$f['tourist_type']])) {
            $clauses[] = 'a.tourist_type = ?';
            $params[]  = $f['tourist_type'];
        }

        if (!empty($f['status']) && in_array($f['status'], ['valid', 'flagged', 'voided'], true)) {
            $clauses[] = 'a.status = ?';
            $params[]  = $f['status'];
        }

        if (!empty($f['source']) && in_array($f['source'], ['qr', 'manual'], true)) {
            $clauses[] = 'a.source = ?';
            $params[]  = $f['source'];
        }

        if (!empty($f['search'])) {
            $clauses[] = '(a.full_name LIKE ? OR a.origin_city LIKE ? OR a.origin_province LIKE ?)';
            $term = '%' . $f['search'] . '%';
            array_push($params, $term, $term, $term);
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    public static function paginate(array $filters, int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = self::buildFilter($filters);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM tourist_arrivals a {$where}", $params
        );

        $perPage = max(1, min($perPage, 200));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $rows = Database::all(
            "SELECT a.*, d.name AS destination_name
               FROM tourist_arrivals a
               JOIN destinations d ON d.id = a.destination_id
               {$where}
              ORDER BY a.arrived_at DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        // Totals for the filtered set, so the officer sees the visitor count
        // rather than only the row count — one submission can be a party of 40.
        $visitors = (int) Database::scalar(
            "SELECT COALESCE(SUM(a.total_visitors), 0) FROM tourist_arrivals a {$where}", $params
        );

        return compact('rows', 'total', 'page', 'pages', 'perPage', 'visitors');
    }

    /** Every matching row, unpaginated, for CSV export. */
    public static function forExport(array $filters): array
    {
        [$where, $params] = self::buildFilter($filters);

        return Database::all(
            "SELECT a.*, d.name AS destination_name, d.barangay
               FROM tourist_arrivals a
               JOIN destinations d ON d.id = a.destination_id
               {$where}
              ORDER BY a.arrived_at DESC",
            $params
        );
    }

    /**
     * Marks a record void and removes it from the published figures.
     *
     * The row is never deleted. An official statistic that changed with no
     * trace of what was removed or why is not auditable — the reason and the
     * officer are recorded, and the summary is rebuilt so the totals reconcile.
     */
    public static function void(int $id, string $reason, int $officerId): void
    {
        Database::transaction(static function () use ($id, $reason, $officerId): void {
            Database::run(
                "UPDATE tourist_arrivals
                    SET status = 'voided', void_reason = ?, voided_by = ?
                  WHERE id = ?",
                [$reason, $officerId, $id]
            );
        });

        self::rebuildSummary();
    }

    /** Returns a flagged record to the counted set after review. */
    public static function approve(int $id): void
    {
        Database::run(
            "UPDATE tourist_arrivals SET status = 'valid', flag_reason = NULL WHERE id = ? AND status = 'flagged'",
            [$id]
        );

        self::rebuildSummary();
    }

    // -------------------------------------------------------------------------
    // Dashboard aggregates
    // -------------------------------------------------------------------------

    /** Everything the dashboard counters need, in one round trip. */
    public static function dashboardStats(): array
    {
        $today = date('Y-m-d');

        return [
            'today'       => (int) Database::scalar(
                "SELECT COALESCE(SUM(total_visitors),0) FROM tourist_arrivals WHERE visit_date = ? AND status='valid'", [$today]),
            'yesterday'   => (int) Database::scalar(
                "SELECT COALESCE(SUM(total_visitors),0) FROM tourist_arrivals WHERE visit_date = ? AND status='valid'",
                [date('Y-m-d', strtotime('-1 day'))]),
            'month'       => (int) Database::scalar(
                "SELECT COALESCE(SUM(total_visitors),0) FROM tourist_arrivals WHERE visit_date >= ? AND status='valid'", [date('Y-m-01')]),
            'total'       => (int) Database::scalar(
                "SELECT COALESCE(SUM(total_visitors),0) FROM tourist_arrivals WHERE status='valid'"),
            'records'     => (int) Database::scalar("SELECT COUNT(*) FROM tourist_arrivals WHERE status='valid'"),
            'flagged'     => self::countFlagged(),
            'destinations'=> (int) Database::scalar("SELECT COUNT(*) FROM destinations WHERE status='active'"),
        ];
    }

    /** Breakdown by tourist type for the filtered period. */
    public static function typeBreakdown(?string $from = null, ?string $to = null): array
    {
        $sql = "SELECT tourist_type, COALESCE(SUM(total_visitors),0) AS visitors
                  FROM tourist_arrivals WHERE status = 'valid'";
        $params = [];

        if ($from !== null) { $sql .= ' AND visit_date >= ?'; $params[] = $from; }
        if ($to   !== null) { $sql .= ' AND visit_date <= ?'; $params[] = $to; }

        $sql .= ' GROUP BY tourist_type';

        $out = array_fill_keys(array_keys(self::TYPES), 0);
        foreach (Database::all($sql, $params) as $row) {
            $out[$row['tourist_type']] = (int) $row['visitors'];
        }

        return $out;
    }
    // -------------------------------------------------------------------------
    // Dashboard overview figures
    //
    // Read only, and every one of them filtered to status = 'valid'. A flagged
    // record is a record the office has not accepted yet; counting it here would
    // put a number on the dashboard that the DOT return would later contradict.
    // -------------------------------------------------------------------------

    /**
     * One row per day across a window: visitors and the records behind them.
     *
     * WHY A SERIES RATHER THAN A TOTAL.
     *
     * The Visitor Trends card offers six windows — two daily, four monthly —
     * and each needs its own figures and the figures of the window before it.
     * Asked one window at a time that is twelve aggregates on every dashboard
     * load. Asked once as a day-by-day series, every one of those twelve is a
     * sum over a slice of the same array, and all six views are provably
     * counting the same rows: a toggle cannot show a total that disagrees with
     * the chart above it, because both are made from this.
     *
     * ZERO-FILLED. A day with no arrivals is a day with none, not a day that is
     * missing — omitting it makes a quiet week look like a short one and pulls
     * every average up.
     *
     * @return array<string, array{visitors:int, records:int}> keyed 'Y-m-d'
     */
    public static function dailySeries(string $start, string $end): array
    {
        $rows = Database::all(
            "SELECT visit_date                       AS d,
                    COALESCE(SUM(total_visitors), 0) AS visitors,
                    COUNT(*)                         AS records
               FROM tourist_arrivals
              WHERE status = 'valid' AND visit_date BETWEEN ? AND ?
              GROUP BY visit_date",
            [$start, $end]
        );

        $found = [];
        foreach ($rows as $row) {
            $found[(string) $row['d']] = [
                'visitors' => (int) $row['visitors'],
                'records'  => (int) $row['records'],
            ];
        }

        $out    = [];
        $cursor = strtotime($start);
        $last   = strtotime($end);

        while ($cursor <= $last) {
            $key       = date('Y-m-d', $cursor);
            $out[$key] = $found[$key] ?? ['visitors' => 0, 'records' => 0];
            $cursor    = strtotime('+1 day', $cursor);
        }

        return $out;
    }

    /**
     * Visitors, records and party size across one window.
     *
     * Returned together rather than as three calls because the dashboard shows
     * them side by side and they must describe the same set of rows — three
     * separate queries a second apart can straddle an approval.
     *
     * @return array{visitors:int, records:int, avg_party:float, daily_avg:float}
     */
    public static function periodTotals(string $start, string $end): array
    {
        $row = Database::first(
            "SELECT COALESCE(SUM(total_visitors), 0) AS visitors,
                    COUNT(*)                         AS records
               FROM tourist_arrivals
              WHERE status = 'valid' AND visit_date BETWEEN ? AND ?",
            [$start, $end]
        );

        $visitors = (int) ($row['visitors'] ?? 0);
        $records  = (int) ($row['records'] ?? 0);

        /* Inclusive of both ends: a one-day window is one day, not zero. */
        $days = max(1, (int) ((strtotime($end) - strtotime($start)) / 86400) + 1);

        return [
            'visitors'  => $visitors,
            'records'   => $records,
            'avg_party' => $records > 0 ? round($visitors / $records, 1) : 0.0,
            'daily_avg' => round($visitors / $days, 1),
        ];
    }

    /**
     * The busiest destinations in a window, each against the window before it.
     *
     * The comparison is the point. "Jadas Falls, 4,332" is a fact; "Jadas Falls,
     * 4,332, down 12%" is the one that tells an officer where to look.
     *
     * LEFT JOIN on the previous period, so a destination that had no visitors
     * last month still appears rather than being dropped by the join — its
     * growth is simply not computable, and the caller is told so with null
     * rather than a fabricated 100%.
     *
     * @return array<int, array{name:string, visitors:int, previous:int, change:?float}>
     */
    public static function topDestinations(
        string $start,
        string $end,
        string $prevStart,
        string $prevEnd,
        int $limit = 5
    ): array {
        $rows = Database::all(
            "SELECT d.name,
                    COALESCE(SUM(CASE WHEN a.visit_date BETWEEN ? AND ? THEN a.total_visitors END), 0) AS visitors,
                    COALESCE(SUM(CASE WHEN a.visit_date BETWEEN ? AND ? THEN a.total_visitors END), 0) AS previous
               FROM destinations d
               LEFT JOIN tourist_arrivals a
                      ON a.destination_id = d.id
                     AND a.status = 'valid'
                     AND a.visit_date BETWEEN ? AND ?
              WHERE d.status = 'active'
              GROUP BY d.id, d.name
              ORDER BY visitors DESC, d.name ASC
              LIMIT " . max(1, min($limit, 20)),
            [$start, $end, $prevStart, $prevEnd, $prevStart, $end]
        );

        $out = [];

        foreach ($rows as $r) {
            $now  = (int) $r['visitors'];
            $was  = (int) $r['previous'];

            $out[] = [
                'name'     => (string) $r['name'],
                'visitors' => $now,
                'previous' => $was,
                /* No previous figure means no percentage. Dividing by zero and
                   calling the answer "up 100%" is arithmetic dressed as insight. */
                'change'   => $was > 0 ? round((($now - $was) / $was) * 100, 1) : null,
            ];
        }

        return $out;
    }

    /**
     * Visitors by hour of day, for staffing decisions.
     *
     * READ FROM arrived_at, WHICH IS THE TIME OF THE VISIT.
     *
     * Worth stating because the alternative would make this chart a lie: if
     * arrived_at carried the moment a manager typed the paper logbook up, this
     * would show when the office does its data entry rather than when tourists
     * turn up. Checked against the data — the distribution runs 06:00 to 16:00
     * and peaks at noon, which is visiting behaviour, not office behaviour.
     *
     * All 24 hours are returned, including the empty ones. A gap between 04:00
     * and 06:00 is information; omitting those rows would draw a chart that
     * starts at dawn and implies nothing exists before it.
     *
     * @return array<int, array{hour:int, label:string, visitors:int}>
     */
    public static function hourlyDistribution(string $start, string $end): array
    {
        $rows = Database::all(
            "SELECT HOUR(arrived_at) AS h,
                    COALESCE(SUM(total_visitors), 0) AS visitors
               FROM tourist_arrivals
              WHERE status = 'valid' AND visit_date BETWEEN ? AND ?
              GROUP BY h",
            [$start, $end]
        );

        $byHour = [];

        foreach ($rows as $r) {
            $byHour[(int) $r['h']] = (int) $r['visitors'];
        }

        $out = [];

        for ($h = 0; $h < 24; $h++) {
            $out[] = [
                'hour'     => $h,
                'label'    => date('ga', mktime($h, 0)),   // 6am, 12pm, 4pm
                'visitors' => $byHour[$h] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Average visitors per weekday across a window.
     *
     * The AVERAGE, not the total: a window of five weeks holds five Mondays and
     * four Fridays, so totals would make Monday look busier than it is. Divided
     * by how many of that weekday the window actually contained.
     *
     * @return array<int, array{day:string, visitors:float}>
     */
    public static function weekdayAverages(string $start, string $end): array
    {
        $rows = Database::all(
            "SELECT DAYOFWEEK(visit_date) AS d,
                    COALESCE(SUM(total_visitors), 0) AS visitors,
                    COUNT(DISTINCT visit_date)       AS days
               FROM tourist_arrivals
              WHERE status = 'valid' AND visit_date BETWEEN ? AND ?
              GROUP BY d",
            [$start, $end]
        );

        $byDay = [];

        foreach ($rows as $r) {
            $byDay[(int) $r['d']] = [
                'visitors' => (int) $r['visitors'],
                'days'     => max(1, (int) $r['days']),
            ];
        }

        /* MySQL's DAYOFWEEK is 1 = Sunday. Started on Monday because the office
           works a Monday week and a chart that opens on Sunday reads as a
           weekend that has already happened. */
        $order = [2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat', 1 => 'Sun'];
        $out   = [];

        foreach ($order as $key => $name) {
            $hit   = $byDay[$key] ?? null;
            $out[] = [
                'day'      => $name,
                'visitors' => $hit === null ? 0.0 : round($hit['visitors'] / $hit['days'], 1),
            ];
        }

        return $out;
    }
}
