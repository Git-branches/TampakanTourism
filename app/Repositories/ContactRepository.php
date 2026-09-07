<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * TourSync — messages from the homepage contact form.                Feature 7
 *
 * Small on purpose. This is an inbox, not a ticketing system: the office reads
 * a message, replies from their own email client, and marks it answered. Threads,
 * assignment and SLAs would all be machinery around a form that receives a
 * handful of messages a month.
 */
final class ContactRepository
{
    public const STATUSES = [
        'new'      => 'New',
        'read'     => 'Read',
        'answered' => 'Answered',
        'spam'     => 'Spam',
    ];

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO contact_messages (name, email, phone, subject, message, device_hash)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                mb_substr(trim((string) $data['name']), 0, 120),
                mb_substr(trim((string) $data['email']), 0, 190),
                trim((string) ($data['phone'] ?? '')) !== ''
                    ? mb_substr(trim((string) $data['phone']), 0, 40) : null,
                mb_substr(trim((string) $data['subject']), 0, 120),
                mb_substr(trim((string) $data['message']), 0, 2000),
                $data['device_hash'] ?? null,
            ]
        );
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT m.*, a.full_name AS handled_by_name
               FROM contact_messages m
               LEFT JOIN admins a ON a.id = m.handled_by
              WHERE m.id = ?',
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function inbox(array $filters = [], int $limit = 100): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['status']) && isset(self::STATUSES[$filters['status']])) {
            $where[]  = 'm.status = ?';
            $params[] = $filters['status'];
        }

        /* Name, address, or what the message is about. The body is deliberately
           not searched: it holds whole paragraphs, a LIKE across it matches on
           any common word, and the office is looking for a person or a topic. */
        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $term     = '%' . trim((string) $filters['search']) . '%';
            $where[]  = '(m.name LIKE ? OR m.email LIKE ? OR m.subject LIKE ?)';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        /* The topic the visitor picked on the public form. An exact match, not
           a LIKE: it comes from a fixed list of six, so a partial match would
           only ever be a way to get the wrong one. */
        if (trim((string) ($filters['category'] ?? '')) !== '') {
            $where[]  = 'm.subject = ?';
            $params[] = trim((string) $filters['category']);
        }

        /* Everything since a date. One bound rather than two: the inbox is read
           newest first, and "the last week" is the question an officer asks —
           "between the 3rd and the 9th of last month" is a report. */
        if (trim((string) ($filters['since'] ?? '')) !== '') {
            $where[]  = 'm.created_at >= ?';
            $params[] = trim((string) $filters['since']) . ' 00:00:00';
        }

        $sql = 'SELECT m.*, a.full_name AS handled_by_name
                  FROM contact_messages m
                  LEFT JOIN admins a ON a.id = m.handled_by';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        /* Spam sinks to the bottom rather than being hidden. An officer should
           be able to see what the filter caught, because the one time it is
           wrong it is somebody's genuine enquiry. */
        $sql .= " ORDER BY FIELD(m.status, 'new', 'read', 'answered', 'spam'), m.created_at DESC
                  LIMIT " . max(1, min(500, $limit));

        return Database::all($sql, $params);
    }

    /**
     * The topics actually present in the inbox, for the category filter.
     *
     * Read from the messages rather than hard-coded from the public form's list
     * of six: the form's topics have changed once already, and a filter offering
     * a topic nobody has ever written under returns nothing and looks broken.
     *
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return array_column(
            Database::all(
                "SELECT DISTINCT subject FROM contact_messages
                  WHERE subject <> '' ORDER BY subject"
            ),
            'subject'
        );
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);

        foreach (Database::all('SELECT status, COUNT(*) c FROM contact_messages GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }

        return $out;
    }

    public static function unreadCount(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");
    }

    public static function setStatus(int $id, string $status, int $adminId, string $note = ''): bool
    {
        if (!isset(self::STATUSES[$status])) {
            return false;
        }

        Database::run(
            'UPDATE contact_messages
                SET status = ?, handled_by = ?, handled_at = NOW(),
                    office_note = COALESCE(NULLIF(?, \'\'), office_note)
              WHERE id = ?',
            [$status, $adminId, mb_substr(trim($note), 0, 600), $id]
        );

        return true;
    }
}
