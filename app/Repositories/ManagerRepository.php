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

    /**
     * What is wrong with a proposed sign-in name, as whole sentences. Empty
     * means it can be used.
     *
     * Shared by account creation and by Access, which used to hold these rules
     * inline — one column, one set of rules.
     */
    public static function usernameProblems(string $username, int $ignoreId = 0): array
    {
        if (mb_strlen($username) < 3 || mb_strlen($username) > 60) {
            return ['A username is 3 to 60 characters long.'];
        }

        if (preg_match('/^[a-z0-9._-]+$/', $username) !== 1) {
            return ['Use lowercase letters, numbers, dots, hyphens, or underscores only.'];
        }

        if (Database::scalar(
            'SELECT 1 FROM destination_managers WHERE username = ? AND id <> ? LIMIT 1',
            [$username, $ignoreId]
        ) !== null) {
            return ['That username is already in use by another manager.'];
        }

        /* The two tables are separate, but a manager and an officer sharing a
           username is a support call waiting to happen — somebody will type one
           into the other's login page. */
        if (Database::scalar('SELECT 1 FROM admins WHERE username = ? LIMIT 1', [$username]) !== null) {
            return ['That username belongs to an administrator account.'];
        }

        return [];
    }

    /**
     * A sign-in name for a new manager, from their destination: the Kolondatal
     * manager is "manager.kolondatal". Numbered if a second manager of the same
     * destination needs one.
     */
    public static function suggestUsername(int $destinationId): string
    {
        $slug = (string) Database::scalar('SELECT slug FROM destinations WHERE id = ?', [$destinationId]);
        $slug = trim((string) preg_replace('/[^a-z0-9._-]+/', '-', strtolower($slug)), '-.');
        $base = 'manager.' . mb_substr($slug !== '' ? $slug : 'destination-' . $destinationId, 0, 48);

        $candidate = $base;
        for ($n = 2; self::usernameProblems($candidate) !== [] && $n < 100; $n++) {
            $candidate = $base . $n;
        }

        return $candidate;
    }

    /**
     * Everything that records this manager as its author, counted.
     *
     * Decides whether a manager can be deleted at all. Every foreign key to
     * destination_managers is SET NULL or CASCADE, so the database would happily
     * let the row go — and a report would lose the name of whoever submitted
     * it, or an announcement its record of who was texted. The database allows
     * it; the office's records do not.
     */
    public static function history(int $id): array
    {
        $count = static fn (string $sql): int => (int) Database::scalar($sql, [$id]);

        return array_filter([
            'arrival reports submitted'  => $count('SELECT COUNT(*) FROM arrival_reports WHERE submitted_by = ?'),
            'inspection reports'         => $count('SELECT COUNT(*) FROM inspection_reports WHERE submitted_by = ?'),
            'inspection photographs'     => $count('SELECT COUNT(*) FROM inspection_photos WHERE uploaded_by = ?'),
            'report documents'           => $count('SELECT COUNT(*) FROM arrival_report_documents WHERE uploaded_by = ?'),
            'alerts raised'              => $count('SELECT COUNT(*) FROM destination_alerts WHERE raised_by = ?'),
            'change requests'            => $count('SELECT COUNT(*) FROM destination_change_requests WHERE requested_by = ?'),
            'SMS delivery records'       => $count('SELECT COUNT(*) FROM notifications WHERE manager_id = ?'),
            'sign-ins and activity'      => $count('SELECT COUNT(*) FROM activity_logs WHERE manager_id = ?'),
        ]);
    }

    /**
     * Removes a manager who never did anything — an entry made by mistake.
     *
     * Refuses (returns false) if there is any history at all; the caller offers
     * Deactivate instead. Checked again here rather than trusted from the page,
     * because the page was drawn before somebody may have signed in.
     */
    public static function deleteIfUnused(int $id): bool
    {
        return Database::transaction(static function () use ($id): bool {
            $row = Database::first(
                'SELECT id, last_login_at FROM destination_managers WHERE id = ? FOR UPDATE',
                [$id]
            );

            if ($row === null || $row['last_login_at'] !== null || self::history($id) !== []) {
                return false;
            }

            Database::run('DELETE FROM destination_managers WHERE id = ?', [$id]);

            return true;
        });
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
