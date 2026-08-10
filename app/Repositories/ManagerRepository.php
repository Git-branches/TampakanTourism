<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Destination managers — the people the Tourism Office needs to reach.
 *
 * They are contact records, not user accounts. Section 3.0 of the brief
 * describes two sides to the system, public and admin, and lists managers only
 * as people the Admin maintains and the system notifies. Giving them logins
 * would add a third portal, a role, and a read-status model that SMS cannot
 * feed anyway.
 */
final class ManagerRepository
{
    public static function all(array $filters = []): array
    {
        $clauses = [];
        $params  = [];

        if (!empty($filters['destination_id'])) {
            $clauses[] = 'm.destination_id = ?';
            $params[]  = (int) $filters['destination_id'];
        }

        if (isset($filters['active'])) {
            $clauses[] = 'm.is_active = ?';
            $params[]  = $filters['active'] ? 1 : 0;
        }

        if (!empty($filters['search'])) {
            $clauses[] = '(m.full_name LIKE ? OR m.mobile_number LIKE ? OR m.position LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term);
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        return Database::all(
            "SELECT m.*, d.name AS destination_name, d.status AS destination_status
               FROM destination_managers m
               JOIN destinations d ON d.id = m.destination_id
               {$where}
              ORDER BY d.name, m.full_name",
            $params
        );
    }

    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT m.*, d.name AS destination_name
               FROM destination_managers m
               JOIN destinations d ON d.id = m.destination_id
              WHERE m.id = ?',
            [$id]
        );
    }

    /**
     * Everyone who should receive an SMS blast.
     *
     * Three conditions, all required: the manager is active, they have opted
     * in, and their destination is still active. Texting the manager of an
     * archived destination is how a system loses the trust of the people it
     * depends on for data.
     */
    public static function smsRecipients(?int $destinationId = null): array
    {
        $sql = "SELECT m.*, d.name AS destination_name
                  FROM destination_managers m
                  JOIN destinations d ON d.id = m.destination_id
                 WHERE m.is_active = 1
                   AND m.sms_opt_in = 1
                   AND d.status = 'active'";
        $params = [];

        if ($destinationId !== null) {
            $sql .= ' AND m.destination_id = ?';
            $params[] = $destinationId;
        }

        return Database::all($sql . ' ORDER BY d.name, m.full_name', $params);
    }

    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO destination_managers
                (destination_id, full_name, position, mobile_number, email, sms_opt_in, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)',
            [
                $data['destination_id'],
                $data['full_name'],
                $data['position'] ?: null,
                $data['mobile_number'],
                $data['email'] ?: null,
                !empty($data['sms_opt_in']) ? 1 : 0,
            ]
        );
    }

    public static function update(int $id, array $data): void
    {
        Database::run(
            'UPDATE destination_managers
                SET destination_id = ?, full_name = ?, position = ?, mobile_number = ?,
                    email = ?, sms_opt_in = ?, is_active = ?
              WHERE id = ?',
            [
                $data['destination_id'],
                $data['full_name'],
                $data['position'] ?: null,
                $data['mobile_number'],
                $data['email'] ?: null,
                !empty($data['sms_opt_in']) ? 1 : 0,
                !empty($data['is_active']) ? 1 : 0,
                $id,
            ]
        );
    }

    /** Deactivate rather than delete, so past delivery records keep their recipient. */
    public static function setActive(int $id, bool $active): void
    {
        Database::run('UPDATE destination_managers SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    public static function counts(): array
    {
        return [
            'total'    => (int) Database::scalar('SELECT COUNT(*) FROM destination_managers'),
            'active'   => (int) Database::scalar('SELECT COUNT(*) FROM destination_managers WHERE is_active = 1'),
            'opted_in' => (int) Database::scalar(
                'SELECT COUNT(*) FROM destination_managers WHERE is_active = 1 AND sms_opt_in = 1'),
        ];
    }

    /** Destinations with no manager on record — a gap the office should close. */
    public static function destinationsWithoutManager(): array
    {
        return Database::all(
            "SELECT d.id, d.name
               FROM destinations d
               LEFT JOIN destination_managers m ON m.destination_id = d.id AND m.is_active = 1
              WHERE d.status = 'active' AND m.id IS NULL
              ORDER BY d.name"
        );
    }

    /** Is this number already on record? Stops one person being texted twice. */
    public static function numberExists(string $number, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT 1 FROM destination_managers WHERE mobile_number = ?';
        $params = [$number];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        return Database::scalar($sql, $params) !== null;
    }
}
