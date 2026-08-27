<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * TourSync — a manager proposing changes to their own destination.   Feature 6
 *
 * WHY A MANAGER CANNOT SIMPLY EDIT
 *
 * The destination record is the municipality's official public statement about
 * a place — its opening hours, its fees, its safety warnings. A live edit box
 * on that is an unreviewed change to a government publication, made by somebody
 * the office cannot see at the moment they make it.
 *
 * So a manager proposes and the office publishes. In practice this is faster
 * than what it replaces, which was a phone call or a trip to town.
 *
 * WHY THE PROPOSAL STORES ONLY THE CHANGED FIELDS
 *
 * An earlier sketch stored the whole proposed destination row. That silently
 * reverts anything the office edited while the proposal sat waiting: the
 * officer fixes a typo in the description on Monday, approves an unrelated
 * hours change on Tuesday, and the typo comes back. Storing only what the
 * manager actually touched makes approval a patch, not a replacement.
 */
final class ChangeRequestRepository
{
    /**
     * What a manager is allowed to propose.
     *
     * An ALLOW-LIST, and the reason it is a list rather than a rule: a manager
     * must not be able to propose a change to `status`, `slug`, `qr_token` or
     * `attraction_code`. Archiving a destination, moving its public URL, or
     * rotating the token behind a printed sign are office decisions with
     * consequences well outside the destination itself.
     *
     * @var array<string, array{label: string, type: string, max: int}>
     */
    public const FIELDS = [
        'short_description' => ['label' => 'Short description', 'type' => 'text',     'max' => 300],
        'description'       => ['label' => 'Full description',  'type' => 'textarea', 'max' => 5000],
        'operating_hours'   => ['label' => 'Operating hours',   'type' => 'text',     'max' => 160],
        'entrance_fee'      => ['label' => 'Entrance fee',      'type' => 'text',     'max' => 120],
        'facilities'        => ['label' => 'Facilities',        'type' => 'text',     'max' => 600],
        'reminders'         => ['label' => 'Visitor reminders', 'type' => 'textarea', 'max' => 2000],
        'safety_notes'      => ['label' => 'Safety notes',      'type' => 'textarea', 'max' => 2000],
        'contact_person'    => ['label' => 'Contact person',    'type' => 'text',     'max' => 120],
        'contact_phone'     => ['label' => 'Contact number',    'type' => 'text',     'max' => 40],
        'local_hotline'     => ['label' => 'On-site hotline',   'type' => 'text',     'max' => 120],
        'contact_email'     => ['label' => 'Contact email',     'type' => 'text',     'max' => 160],
    ];

    public const STATUSES = [
        'pending'   => 'Waiting for the Office',
        'approved'  => 'Approved and published',
        'rejected'  => 'Not approved',
        'withdrawn' => 'Withdrawn by the manager',
    ];

    /**
     * Reduces a submitted form to only the fields that actually differ.
     *
     * A manager who opens the form, changes one line and submits should not
     * produce a proposal listing eleven unchanged fields — the officer would
     * have to read all of them to find the one that matters.
     *
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $current
     * @return array<string, string>
     */
    public static function diff(array $submitted, array $current): array
    {
        $changes = [];

        foreach (self::FIELDS as $field => $rules) {
            if (!array_key_exists($field, $submitted)) {
                continue;
            }

            $new = trim((string) $submitted[$field]);
            $old = trim((string) ($current[$field] ?? ''));

            /* Normalised before comparison so a stray trailing newline from a
               textarea does not read as an edit. */
            $new = str_replace("\r\n", "\n", $new);
            $old = str_replace("\r\n", "\n", $old);

            if ($new !== $old) {
                $changes[$field] = mb_substr($new, 0, $rules['max']);
            }
        }

        return $changes;
    }

    /**
     * @param array<string, string> $changes
     * @return int|null the new id, or null when nothing actually changed
     */
    public static function create(int $destinationId, int $managerId, array $changes, string $reason): ?int
    {
        if ($changes === []) {
            return null;
        }

        return Database::insert(
            'INSERT INTO destination_change_requests
                (destination_id, requested_by, proposed, reason)
             VALUES (?, ?, ?, ?)',
            [
                $destinationId,
                $managerId,
                json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                mb_substr(trim($reason), 0, 600) ?: null,
            ]
        );
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Database::first(
            'SELECT r.*, d.name AS destination_name, d.slug AS destination_slug,
                    m.full_name AS manager_name, a.full_name AS reviewer_name
               FROM destination_change_requests r
               JOIN destinations d ON d.id = r.destination_id
               LEFT JOIN destination_managers m ON m.id = r.requested_by
               LEFT JOIN admins a ON a.id = r.reviewed_by
              WHERE r.id = ?',
            [$id]
        );

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function all(array $filters = [], int $limit = 100): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['status']) && isset(self::STATUSES[$filters['status']])) {
            $where[]  = 'r.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['destination_id'])) {
            $where[]  = 'r.destination_id = ?';
            $params[] = (int) $filters['destination_id'];
        }

        $sql = 'SELECT r.*, d.name AS destination_name,
                       m.full_name AS manager_name, a.full_name AS reviewer_name
                  FROM destination_change_requests r
                  JOIN destinations d ON d.id = r.destination_id
                  LEFT JOIN destination_managers m ON m.id = r.requested_by
                  LEFT JOIN admins a ON a.id = r.reviewed_by';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= " ORDER BY FIELD(r.status, 'pending', 'approved', 'rejected', 'withdrawn'), r.created_at DESC
                  LIMIT " . max(1, min(500, $limit));

        return array_map([self::class, 'hydrate'], Database::all($sql, $params));
    }

    /**
     * Decodes `proposed` and attaches the field labels.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        $decoded = json_decode((string) $row['proposed'], true);

        /* A row whose JSON will not decode is shown as empty rather than
           allowed to fatal a whole inbox. It cannot happen through this class,
           but the column is nullable to nobody and the page must survive it. */
        $row['changes'] = is_array($decoded) ? $decoded : [];

        return $row;
    }

    public static function pendingCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM destination_change_requests WHERE status = 'pending'"
        );
    }

    /**
     * Applies the proposal to the destination and marks it approved.
     *
     * IN ONE TRANSACTION, and re-reads the row inside it. Two officers opening
     * the same proposal in two tabs would otherwise both apply it, and the
     * second would overwrite a field the first had already changed by hand.
     *
     * @return string|null null on success, otherwise why it was refused
     */
    public static function approve(int $id, int $adminId, string $note = ''): ?string
    {
        return Database::transaction(static function () use ($id, $adminId, $note): ?string {
            $row = Database::first(
                'SELECT * FROM destination_change_requests WHERE id = ? FOR UPDATE',
                [$id]
            );

            if ($row === null) {
                return 'That request no longer exists.';
            }

            if ($row['status'] !== 'pending') {
                return 'That request has already been ' . self::STATUSES[$row['status']] . '.';
            }

            $changes = json_decode((string) $row['proposed'], true);

            if (!is_array($changes) || $changes === []) {
                return 'That request contains nothing to apply.';
            }

            /* Built from the allow-list, never from the keys in the JSON. A row
               edited directly in the database must not become a way to write to
               an arbitrary column. */
            $sets   = [];
            $values = [];

            foreach ($changes as $field => $value) {
                if (!isset(self::FIELDS[$field])) {
                    continue;
                }

                $sets[]   = "`{$field}` = ?";
                $values[] = mb_substr((string) $value, 0, self::FIELDS[$field]['max']);
            }

            if ($sets === []) {
                return 'That request contains no fields this system can apply.';
            }

            $values[] = (int) $row['destination_id'];

            Database::run(
                'UPDATE destinations SET ' . implode(', ', $sets) . ' WHERE id = ?',
                $values
            );

            Database::run(
                "UPDATE destination_change_requests
                    SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
                  WHERE id = ?",
                [$adminId, mb_substr(trim($note), 0, 600) ?: null, $id]
            );

            return null;
        });
    }

    /**
     * Turns a proposal down.
     *
     * REQUIRES A REASON. The manager wrote a change because they believe the
     * public page is wrong; "no" without a sentence leaves them believing the
     * page is still wrong and that nobody read it.
     *
     * @return string|null null on success, otherwise why it was refused
     */
    public static function reject(int $id, int $adminId, string $note): ?string
    {
        $note = trim($note);

        if ($note === '') {
            return 'Say why. The manager sees this, and a bare refusal tells them nothing.';
        }

        $affected = Database::affected(
            "UPDATE destination_change_requests
                SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
              WHERE id = ? AND status = 'pending'",
            [$adminId, mb_substr($note, 0, 600), $id]
        );

        return $affected === 0 ? 'That request is no longer waiting for a decision.' : null;
    }

    /**
     * The manager changing their mind.
     *
     * Scoped to the manager who raised it, so a manager cannot withdraw
     * somebody else's proposal by posting its id.
     */
    public static function withdraw(int $id, int $managerId): bool
    {
        return Database::affected(
            "UPDATE destination_change_requests
                SET status = 'withdrawn'
              WHERE id = ? AND requested_by = ? AND status = 'pending'",
            [$id, $managerId]
        ) > 0;
    }
}
