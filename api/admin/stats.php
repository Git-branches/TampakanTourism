<?php
declare(strict_types=1);

/**
 * TourSync — dashboard counters, polled by the browser.
 *
 * "Real-time" in Feature 2 means the Officer sees today's arrivals without
 * waiting for a courier, not sub-second latency. A 30-second poll delivers
 * that; WebSockets would need a persistent process that cPanel shared hosting
 * will not run, so choosing them would break the deployment requirement to
 * solve a problem the Office does not have.
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Auth;
use App\Repositories\ArrivalRepository;

// Same server-side gate as any admin page. An endpoint that skipped this
// because "it only returns numbers" would leak the municipality's figures.
if (!Auth::check()) {
    json_response(['error' => 'Not authorised'], 401);
}

$stats = ArrivalRepository::dashboardStats();

$mostVisited = App\Core\Database::first(
    "SELECT d.name, COALESCE(SUM(s.total_visitors), 0) AS visitors
       FROM destinations d
       LEFT JOIN arrival_daily_summary s ON s.destination_id = d.id
      WHERE d.status = 'active'
      GROUP BY d.id, d.name
      ORDER BY visitors DESC
      LIMIT 1"
);

json_response([
    'today'        => $stats['today'],
    'yesterday'    => $stats['yesterday'],
    'month'        => $stats['month'],
    'total'        => $stats['total'],
    'records'      => $stats['records'],
    'flagged'      => $stats['flagged'],
    'destinations' => $stats['destinations'],
    'most_visited' => $mostVisited === null || (int) $mostVisited['visitors'] === 0
        ? null
        : ['name' => $mostVisited['name'], 'visitors' => (int) $mostVisited['visitors']],
    'generated_at' => date('c'),
]);
