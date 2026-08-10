<?php
declare(strict_types=1);

/**
 * TourSync — chart series for the dashboard.
 *
 * Reads the daily rollup rather than the arrivals table. That is what the
 * denormalised summary exists for: without it, drawing a 30-day trend means
 * scanning every arrival row on every dashboard refresh.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\ArrivalRepository;

if (!Auth::check()) {
    json_response(['error' => 'Not authorised'], 401);
}

$series = (string) ($_GET['series'] ?? 'trend');
$days   = max(7, min((int) ($_GET['days'] ?? 30), 365));

switch ($series) {

    // Daily visitor totals. Days with no arrivals must appear as zero rather
    // than be missing, otherwise the line silently skips them and a quiet week
    // looks like a busy one.
    case 'trend':
        $rows = ArrivalRepository::dailyTrend($days);

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['visit_date']] = (int) $row['visitors'];
        }

        $labels = [];
        $values = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date     = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = date('M j', strtotime($date));
            $values[] = $byDate[$date] ?? 0;
        }

        json_response(['labels' => $labels, 'values' => $values]);

    case 'destinations':
        $rows = ArrivalRepository::byDestination();
        $rows = array_slice($rows, 0, 8);

        json_response([
            'labels' => array_map(static fn($r) => $r['name'], $rows),
            'values' => array_map(static fn($r) => (int) $r['visitors'], $rows),
        ]);

    case 'types':
        $breakdown = ArrivalRepository::typeBreakdown();

        json_response([
            'labels' => ['Local', 'Domestic', 'Foreign', 'Overseas Filipino'],
            'values' => [
                $breakdown['local'],
                $breakdown['domestic'],
                $breakdown['foreign'],
                $breakdown['overseas_filipino'],
            ],
        ]);

    default:
        json_response(['error' => 'Unknown series'], 400);
}
