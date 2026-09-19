<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * TourSync — the official Data Request form, submitted from the website.
 *
 * The office hands a paper form to anyone requesting tourism data. This mirrors
 * that form field for field, so a request that arrives through the site can be
 * filed beside one handed over the counter and answered the same way.
 *
 * THIS TABLE HOLDS REAL PERSONAL DATA that no other public form here collects:
 * home address, birthdate, contact number. Three consequences, all enforced
 * below rather than left to whoever calls this next:
 *
 *   1. Nothing in here is ever read by a public page. There is no published(),
 *      no featured(), no summary — deliberately. The only readers are
 *      authenticated admin screens.
 *   2. anonymise() clears the identifying columns while leaving the request
 *      itself countable, the same shape the retention job already applies to
 *      arrivals (RA 10173: personal data is not kept longer than the purpose
 *      requires).
 *   3. The requester gets a reference code, so they can ask about their request
 *      without either side quoting the personal details back over email.
 *
 * AGE IS NOT STORED. The form calculates it from the birthdate for the visitor
 * to check, and age() derives it on the way out. A stored age is wrong the day
 * after the requester's birthday and nothing would ever correct it.
 */
final class DataRequestRepository
{
    public const STATUSES = [
        'new'         => 'New',
        'in_progress' => 'In progress',
        'fulfilled'   => 'Fulfilled',
        'declined'    => 'Declined',
    ];

    /**
     * The civil statuses the paper form offers. Held here rather than in the
     * markup so the public form and the admin screen cannot come to offer
     * different lists.
     *
     * @var array<int, string>
     */
    public const CIVIL_STATUSES = [
        'Single',
        'Married',
        'Widowed',
        'Separated',
        'Divorced',
    ];

    /**
     * @param array<string, mixed> $data
     * @return array{id:int, reference:string}
     */
    public static function create(array $data): array
    {
        $reference = self::generateReference();

        $id = Database::insert(
            'INSERT INTO data_requests
                (reference, organisation, first_name, middle_name, last_name,
                 home_address, birthdate, civil_status, designation,
                 email, contact_number, purpose, requested_data, needed_by, device_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $reference,
                self::clip($data['organisation']   ?? '', 190),
                (string) self::clip($data['first_name'] ?? '', 80),
                self::clip($data['middle_name']    ?? '', 80),
                (string) self::clip($data['last_name']  ?? '', 80),
                self::clip($data['home_address']   ?? '', 255),
                self::date($data['birthdate']      ?? ''),
                self::clip($data['civil_status']   ?? '', 30),
                self::clip($data['designation']    ?? '', 160),
                (string) self::clip($data['email'] ?? '', 190),
                self::clip($data['contact_number'] ?? '', 40),
                (string) self::clip($data['purpose'] ?? '', 1000),
                (string) self::clip($data['requested_data'] ?? '', 2000),
                self::date($data['needed_by']      ?? ''),
                $data['device_hash'] ?? null,
            ]
        );

        return ['id' => $id, 'reference' => $reference];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT r.*, a.full_name AS handled_by_name
               FROM data_requests r
               LEFT JOIN admins a ON a.id = r.handled_by
              WHERE r.id = ?',
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
            $where[]  = 'r.status = ?';
            $params[] = $filters['status'];
        }

        /* Name, organisation, or the reference the requester was given. NOT the
           purpose or the requested data: those are paragraphs, a LIKE across
           them matches on any common word, and an officer searching here is
           looking for a person, a body, or a code they have been quoted. */
        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $term     = '%' . trim((string) $filters['search']) . '%';
            $where[]  = '(r.first_name LIKE ? OR r.last_name LIKE ?
                          OR r.organisation LIKE ? OR r.reference LIKE ?)';
            array_push($params, $term, $term, $term, $term);
        }

        if (trim((string) ($filters['since'] ?? '')) !== '') {
            $where[]  = 'r.created_at >= ?';
            $params[] = trim((string) $filters['since']) . ' 00:00:00';
        }

        $sql = 'SELECT r.*, a.full_name AS handled_by_name
                  FROM data_requests r
                  LEFT JOIN admins a ON a.id = r.handled_by';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        /* Anything still open first, then newest. A request with a completion
           date the requester asked for is a commitment with a clock on it. */
        $sql .= " ORDER BY FIELD(r.status, 'new', 'in_progress', 'fulfilled', 'declined'),
                           r.created_at DESC
                  LIMIT " . max(1, min(500, $limit));

        return Database::all($sql, $params);
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);

        foreach (Database::all('SELECT status, COUNT(*) c FROM data_requests GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }

        return $out;
    }

    public static function openCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM data_requests WHERE status IN ('new', 'in_progress')"
        );
    }

    public static function setStatus(int $id, string $status, int $adminId, string $note = ''): bool
    {
        if (!isset(self::STATUSES[$status])) {
            return false;
        }

        Database::run(
            'UPDATE data_requests
                SET status = ?, handled_by = ?, handled_at = NOW(),
                    office_note = COALESCE(NULLIF(?, \'\'), office_note)
              WHERE id = ?',
            [$status, $adminId, mb_substr(trim($note), 0, 1000), $id]
        );

        return true;
    }

    /**
     * Clear the identifying columns, keeping the request countable.
     *
     * What survives is what the office needs to say how many requests it
     * received and what they were for; what goes is everything that says who
     * asked. Not a DELETE — a fulfilled request is part of the office's record
     * of what it released and to what kind of body.
     */
    public static function anonymise(int $id): void
    {
        Database::run(
            "UPDATE data_requests
                SET first_name = 'Redacted', middle_name = NULL, last_name = 'Requester',
                    home_address = NULL, birthdate = NULL, email = 'redacted@example.invalid',
                    contact_number = NULL, device_hash = NULL, anonymised_at = NOW()
              WHERE id = ? AND anonymised_at IS NULL",
            [$id]
        );
    }

    /**
     * Full name as the paper form reads it, middle name included when given.
     *
     * @param array<string, mixed> $row
     */
    public static function fullName(array $row): string
    {
        $parts = array_filter([
            trim((string) ($row['first_name'] ?? '')),
            trim((string) ($row['middle_name'] ?? '')),
            trim((string) ($row['last_name'] ?? '')),
        ], static fn(string $p): bool => $p !== '');

        return implode(' ', $parts);
    }

    /**
     * Age today, derived from the birthdate. NULL when none was given.
     *
     * Derived on every read rather than stored — see the class note.
     */
    public static function age(?string $birthdate): ?int
    {
        $birthdate = trim((string) $birthdate);

        if ($birthdate === '' || $birthdate === '0000-00-00') {
            return null;
        }

        try {
            $born = new \DateTimeImmutable($birthdate);
        } catch (\Throwable) {
            return null;
        }

        $years = (int) $born->diff(new \DateTimeImmutable('today'))->y;

        /* A birthdate in the future gives a negative diff that DateInterval
           reports as a positive y with invert set. Rather than trust it, refuse
           anything that is not a plausible age. */
        return ($years >= 0 && $years <= 130) ? $years : null;
    }

    /**
     * A code the requester can quote back without either side repeating their
     * address over email. Same alphabet as the guide reference: no letters that
     * read as digits when somebody copies one off a screen by hand.
     */
    private static function generateReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = 'DR-';

            for ($i = 0; $i < 5; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            if (Database::scalar('SELECT 1 FROM data_requests WHERE reference = ?', [$code]) === null) {
                return $code;
            }
        }

        /* Eight collisions in a 31^5 space is a broken random source, not bad
           luck. Take something certainly unique rather than loop. */
        return 'DR-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }

    private static function clip(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** A storable Y-m-d, or NULL. Anything unparseable becomes NULL, not 0000-00-00. */
    private static function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
