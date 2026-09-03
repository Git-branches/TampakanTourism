<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\DocumentUploader;

/**
 * TourSync — the accredited tour guide tour guide list.
 *
 * SEPARATE FROM TourGuideRepository, WHICH IS ABOUT REQUESTS
 *
 * The two share a subject and almost nothing else. That one records a visitor
 * asking for a guide and the office answering; this one records who the guides
 * are, what they are accredited for, and whether their card is still good.
 * Folding them together would produce a thousand-line class doing two jobs,
 * and the day somebody edits the wrong half is the day a visitor gets texted a
 * revoked guide's number.
 *
 * WHY 'expired' IS NOT A STORED STATUS
 *
 * status holds what a person decided — active, suspended, revoked. Whether a
 * card has run out is a question about today's date and valid_until, and the
 * answer changes at midnight with nobody present to write it down. A stored
 * 'expired' would be a lie every morning between the card lapsing and some job
 * getting round to running, and the verification page is supposed to be right
 * the moment somebody scans it at a trailhead.
 *
 * So effectiveStatus() computes it. Nothing is scheduled, nothing drifts.
 */
final class TourGuideRosterRepository
{
    /** What an administrator can set. 'expired' is deliberately absent. */
    public const STATUSES = [
        'active'    => 'Active',
        'suspended' => 'Suspended',
        'revoked'   => 'Revoked',
    ];

    /**
     * Every state a guide can actually be in, including the two nobody sets by
     * hand. Used for the badge on the tour guide list and on the verification page.
     */
    public const EFFECTIVE = [
        'active'    => 'Active',
        'expired'   => 'Expired',
        'suspended' => 'Suspended',
        'revoked'   => 'Revoked',
        'no_id'     => 'No ID issued',
    ];

    // -------------------------------------------------------------------------
    //  Status
    // -------------------------------------------------------------------------

    /**
     * What this guide's card is worth today.
     *
     * Order matters and is not arbitrary. Revoked outranks everything — a
     * withdrawn accreditation is withdrawn whether or not the printed date has
     * passed. Suspension outranks expiry for the same reason. Only then does
     * the calendar get a say.
     *
     * @param array<string, mixed> $guide
     */
    public static function effectiveStatus(array $guide): string
    {
        $set = (string) ($guide['status'] ?? 'active');

        if ($set === 'revoked' || $set === 'suspended') {
            return $set;
        }

        $validUntil = trim((string) ($guide['valid_until'] ?? ''));

        /* No date means no card has been issued yet. That is not the same as an
           expired one: the guide is on the tour guide list and simply has nothing to
           show, which is what an officer needs to be told before they try to
           assign them. */
        if ($validUntil === '') {
            return 'no_id';
        }

        return $validUntil < date('Y-m-d') ? 'expired' : 'active';
    }

    /**
     * Whether this guide may be given a request.
     *
     * The single gate §19 asks for: active, with a card that has not run out.
     * Suspended, revoked, expired and never-issued all fail it.
     *
     * @param array<string, mixed> $guide
     */
    public static function isAssignable(array $guide): bool
    {
        return self::effectiveStatus($guide) === 'active';
    }

    // -------------------------------------------------------------------------
    //  Reading
    // -------------------------------------------------------------------------

    /**
     * The tour guide list.
     *
     * @param  array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function all(array $filters = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['status']) && isset(self::STATUSES[$filters['status']])) {
            $where[]  = 'g.status = ?';
            $params[] = $filters['status'];
        }

        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $term     = '%' . trim((string) $filters['search']) . '%';
            $where[]  = '(g.full_name LIKE ? OR g.guide_code LIKE ? OR g.mobile_number LIKE ?)';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql = 'SELECT g.* FROM tour_guides g';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY g.full_name';

        $rows = Database::all($sql, $params);

        /* Computed once here rather than at every call site. A screen that
           works out expiry itself is a screen that will disagree with the
           verification page the first time somebody changes one of them. */
        foreach ($rows as &$row) {
            $row['effective_status'] = self::effectiveStatus($row);
        }

        unset($row);

        /* FILTERED ON THE DERIVED STATUS, WHICH SQL CANNOT SEE.
         *
         * 'expired' and 'no_id' are not values of the `status` column — they are
         * worked out from valid_until and today's date, deliberately, so the
         * tour guide list can never disagree with the verification page. That means this
         * filter has to happen here rather than in the WHERE clause above.
         *
         * 'barred' is the officer's question, not the database's: suspended and
         * revoked are different decisions but the same answer to "can I send
         * this person to a visitor". */
        $show = (string) ($filters['show'] ?? '');

        if ($show !== '') {
            $wanted = $show === 'barred' ? ['suspended', 'revoked'] : [$show];

            $rows = array_values(array_filter(
                $rows,
                static fn(array $r): bool => in_array($r['effective_status'], $wanted, true)
            ));
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Database::first('SELECT * FROM tour_guides WHERE id = ?', [$id]);

        if ($row !== null) {
            $row['effective_status'] = self::effectiveStatus($row);
        }

        return $row;
    }

    /**
     * The guide a scanned QR code refers to.
     *
     * Shape-checked before the query, so a malformed token costs a regex rather
     * than a round trip — the verification endpoint is public and unauthenticated,
     * and anything reachable without signing in gets hit by scanners.
     *
     * @return array<string, mixed>|null
     */
    public static function findByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }

        $row = Database::first('SELECT * FROM tour_guides WHERE verify_token = ?', [$token]);

        if ($row !== null) {
            $row['effective_status'] = self::effectiveStatus($row);
        }

        return $row;
    }

    // -------------------------------------------------------------------------
    //  Writing
    // -------------------------------------------------------------------------

    /**
     * Adds a guide and issues their accreditation number.
     *
     * @param  array<string, mixed> $data
     * @return int the new id
     */
    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO tour_guides
                (guide_code, verify_token, full_name, address, mobile_number, email,
                 photo_path, status, valid_until, status_note, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::nextCode(),
                bin2hex(random_bytes(16)),
                mb_substr(trim((string) $data['full_name']), 0, 160),
                self::nullable($data['address'] ?? '', 255),
                self::nullable($data['mobile_number'] ?? '', 20),
                self::nullable($data['email'] ?? '', 190),
                self::nullable($data['photo_path'] ?? '', 255),
                isset(self::STATUSES[$data['status'] ?? '']) ? $data['status'] : 'active',
                ($data['valid_until'] ?? '') !== '' ? $data['valid_until'] : null,
                self::nullable($data['status_note'] ?? '', 600),
                self::nullable($data['notes'] ?? '', 600),
                $data['created_by'] ?? null,
            ]
        );
    }

    /**
     * Edits a guide.
     *
     * guide_code and verify_token are deliberately not editable here. The code
     * is printed on a card somebody is carrying and the token is what the QR on
     * that card points at — changing either from an edit form would silently
     * kill a card in a wallet. rotateToken() exists for when that is the
     * intention.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        Database::run(
            'UPDATE tour_guides
                SET full_name = ?, address = ?, mobile_number = ?, email = ?,
                    status = ?, valid_until = ?, status_note = ?, notes = ?
              WHERE id = ?',
            [
                mb_substr(trim((string) $data['full_name']), 0, 160),
                self::nullable($data['address'] ?? '', 255),
                self::nullable($data['mobile_number'] ?? '', 20),
                self::nullable($data['email'] ?? '', 190),
                isset(self::STATUSES[$data['status'] ?? '']) ? $data['status'] : 'active',
                ($data['valid_until'] ?? '') !== '' ? $data['valid_until'] : null,
                self::nullable($data['status_note'] ?? '', 600),
                self::nullable($data['notes'] ?? '', 600),
                $id,
            ]
        );
    }

    /** Records the photograph, replacing whatever was there. */
    public static function setPhoto(int $id, ?string $path): void
    {
        Database::run('UPDATE tour_guides SET photo_path = ? WHERE id = ?', [$path, $id]);
    }

    /**
     * Marks the card as issued, now.
     *
     * The ID is generated from the record rather than designed, so "issuing"
     * is not a rendering step — the card can be printed at any time. This
     * stamps WHEN the office last considered it issued, which is what tells an
     * officer whether the card in a guide's wallet predates the details on
     * screen.
     */
    public static function markIssued(int $id): void
    {
        Database::run('UPDATE tour_guides SET id_issued_at = NOW() WHERE id = ?', [$id]);
    }

    /**
     * Issues a fresh verification token, killing every printed card.
     *
     * That is the point of it: the remedy when a card is lost or stolen. The
     * accreditation number stays, because the person is still the same person.
     */
    public static function rotateToken(int $id): string
    {
        $token = bin2hex(random_bytes(16));

        Database::run('UPDATE tour_guides SET verify_token = ? WHERE id = ?', [$token, $id]);

        return $token;
    }

    /**
     * Deletes a guide and everything filed under them.
     *
     * The certificate FILES have to go by hand — the foreign key cascades the
     * rows and knows nothing about what is on disk. Done before the delete,
     * because afterwards there is no list of what to remove.
     */
    public static function delete(int $id): void
    {
        foreach (self::certificatesFor($id) as $certificate) {
            DocumentUploader::delete((string) $certificate['stored_name'], 'certificates');
        }

        Database::run('DELETE FROM tour_guides WHERE id = ?', [$id]);
    }

    // -------------------------------------------------------------------------
    //  The accreditation number
    // -------------------------------------------------------------------------

    /**
     * The next number for this year: TGID-2026-0001.
     *
     * 'TGID' and not 'TG'. Requests already use TG- with five random
     * characters, and an officer holding a receipt and a card at the same
     * counter should not have to work out which kind of code they are reading.
     *
     * Sequential within the year, which is what a municipality issuing
     * accreditations expects and is fine here — the number is printed on a card
     * and is not a secret. The unguessable part is verify_token, which is what
     * the QR actually carries.
     *
     * Derived from the highest existing number rather than a count, so deleting
     * a guide cannot make the next one collide with a card already in a wallet.
     */
    public static function nextCode(): string
    {
        $year   = date('Y');
        $prefix = 'TGID-' . $year . '-';

        /* The SUBSTRING position is INLINED, not bound.
         *
         * It is derived from a prefix this method builds itself — never from a
         * request — so there is nothing to inject. And a bound parameter in a
         * function's positional argument is the kind of thing that works on one
         * MySQL and is rejected by a MariaDB two minor versions away, which is
         * a failure that would first appear on the client's server rather than
         * this one. Cast to int so it cannot be anything but a number. */
        $from = (int) strlen($prefix) + 1;

        $highest = Database::scalar(
            "SELECT MAX(CAST(SUBSTRING(guide_code, {$from}) AS UNSIGNED))
               FROM tour_guides WHERE guide_code LIKE ?",
            [$prefix . '%']
        );

        return $prefix . str_pad((string) (((int) $highest) + 1), 4, '0', STR_PAD_LEFT);
    }

    // -------------------------------------------------------------------------
    //  Credentials — the bullet points on the card
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public static function credentialsFor(int $guideId): array
    {
        return Database::all(
            'SELECT * FROM tour_guide_credentials WHERE guide_id = ? ORDER BY sort_order, id',
            [$guideId]
        );
    }

    /**
     * The credential boxes as the form posts them, paired up.
     *
     * Two parallel arrays rather than one nested one, because that is what a
     * repeating pair of plain inputs sends. Here rather than in a page, because
     * three pages submit this form — the tour guide list's sheet, create.php and
     * edit.php — and the same pairing written out three times is two copies
     * waiting to disagree.
     *
     * Blank labels survive this and are dropped by replaceCredentials(), so a
     * spare empty box costs nothing either way.
     *
     * @param  mixed $labels  $_POST['credential_label']
     * @param  mixed $issuers $_POST['credential_issuer']
     * @return array<int, array{label: string, issuer: string}>
     */
    public static function pairCredentials(mixed $labels, mixed $issuers): array
    {
        if (!is_array($labels)) {
            return [];
        }

        $rows = [];

        foreach ($labels as $i => $label) {
            $rows[] = [
                'label'  => is_scalar($label) ? (string) $label : '',
                'issuer' => is_array($issuers) && isset($issuers[$i]) && is_scalar($issuers[$i])
                    ? (string) $issuers[$i]
                    : '',
            ];
        }

        return $rows;
    }

    /**
     * Replaces the whole list.
     *
     * Wholesale rather than row by row because that is how the form works — a
     * repeating set of text boxes submitted together. Deleting and reinserting
     * inside a transaction keeps the list from being half-written if the
     * request dies partway.
     *
     * @param array<int, array{label: string, issuer: string}> $rows
     */
    public static function replaceCredentials(int $guideId, array $rows): void
    {
        Database::transaction(static function () use ($guideId, $rows): void {
            Database::run('DELETE FROM tour_guide_credentials WHERE guide_id = ?', [$guideId]);

            $position = 0;

            foreach ($rows as $row) {
                $label = trim((string) ($row['label'] ?? ''));

                /* A blank line is somebody leaving a spare box empty, not a
                   credential called nothing. */
                if ($label === '') {
                    continue;
                }

                Database::run(
                    'INSERT INTO tour_guide_credentials (guide_id, label, issuer, sort_order)
                     VALUES (?, ?, ?, ?)',
                    [
                        $guideId,
                        mb_substr($label, 0, 160),
                        self::nullable($row['issuer'] ?? '', 160),
                        $position++,
                    ]
                );
            }
        });
    }

    // -------------------------------------------------------------------------
    //  Certificates — the scanned documents
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public static function certificatesFor(int $guideId): array
    {
        return Database::all(
            'SELECT * FROM tour_guide_certificates WHERE guide_id = ? ORDER BY issued_on DESC, id DESC',
            [$guideId]
        );
    }

    /** @return array<string, mixed>|null */
    public static function findCertificate(int $id): ?array
    {
        return Database::first('SELECT * FROM tour_guide_certificates WHERE id = ?', [$id]);
    }

    /**
     * Files one certificate against a guide.
     *
     * @param array<string, mixed> $data the DocumentUploader result plus metadata
     */
    public static function addCertificate(int $guideId, array $data): int
    {
        return Database::insert(
            'INSERT INTO tour_guide_certificates
                (guide_id, title, issuer, issued_on, expires_on,
                 stored_name, original_name, mime_type, byte_size, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $guideId,
                mb_substr(trim((string) $data['title']), 0, 160),
                self::nullable($data['issuer'] ?? '', 160),
                ($data['issued_on']  ?? '') !== '' ? $data['issued_on']  : null,
                ($data['expires_on'] ?? '') !== '' ? $data['expires_on'] : null,
                (string) $data['stored_name'],
                (string) $data['original_name'],
                (string) $data['mime_type'],
                (int) $data['byte_size'],
                $data['uploaded_by'] ?? null,
            ]
        );
    }

    /** Removes the row and the file behind it. */
    public static function deleteCertificate(int $id): void
    {
        $certificate = self::findCertificate($id);

        if ($certificate === null) {
            return;
        }

        DocumentUploader::delete((string) $certificate['stored_name'], 'certificates');
        Database::run('DELETE FROM tour_guide_certificates WHERE id = ?', [$id]);
    }

    // -------------------------------------------------------------------------
    //  Availability
    // -------------------------------------------------------------------------

    /**
     * Guides who can take a request on a given date.
     *
     * Two questions, and only two:
     *
     *   is the card good today   effectiveStatus() — active, not expired,
     *                            not suspended, not revoked
     *
     *   are they already out     assigned to another request on the same
     *                            preferred_date that has not been closed
     *
     * WHAT IS DELIBERATELY NOT CHECKED
     *
     * Destination qualification. §19 lists it as "if applicable", and there is
     * no record anywhere of which guide is accredited for which site — the
     * office has never kept one. A check against a table nobody fills is either
     * a no-op or it refuses everybody, and both are worse than being honest
     * that the office decides this by knowing their own people.
     *
     * The time of day is not checked either, for a related reason: a request
     * carries one optional preferred_time and no duration, so "free at 9am"
     * cannot be answered from what is stored. A guide already booked that day
     * is flagged rather than hidden, and the officer decides — which is what
     * they were doing before this screen existed.
     *
     * @return array<int, array<string, mixed>> each with a 'busy' count
     */
    public static function availableOn(?string $date, int $exceptRequestId = 0): array
    {
        $guides = self::all(['status' => 'active']);
        $out    = [];

        foreach ($guides as $guide) {
            if (!self::isAssignable($guide)) {
                continue;
            }

            $guide['busy'] = 0;

            if ($date !== null && $date !== '') {
                $guide['busy'] = (int) Database::scalar(
                    "SELECT COUNT(*) FROM tour_guide_requests
                      WHERE guide_id = ? AND preferred_date = ?
                        AND status IN ('assigned')
                        AND id <> ?",
                    [(int) $guide['id'], $date, $exceptRequestId]
                );
            }

            $out[] = $guide;
        }

        return $out;
    }

    /**
     * Where a scanned card sends somebody.
     *
     * QrService::publicBase() and not base_url(), for the reason spelled out in
     * that class: a printed card keeps whatever hostname it was generated with
     * for years, and a card produced on localhost points at the scanning phone
     * itself.
     */
    public static function verifyUrl(string $token): string
    {
        /* THE SHORT ROUTE, because this ends up as a QR code on a 2.63 in card.
         *
         * /g/<token> rather than /guide-verify.php?id=<token> — 52 characters
         * instead of 69, which at error-correction level H is a 41x41 grid
         * instead of 49x49. Across the 18 mm the card can spare that is 0.44 mm
         * per module instead of 0.37, and the code is scanned off a card that
         * has been in somebody's pocket, at a trailhead, by whatever phone they
         * brought. The destination signage made the same trade; see the rewrite
         * rules in the document root .htaccess. */
        return \App\Core\QrService::publicBase() . '/g/' . $token;
    }

    // -------------------------------------------------------------------------

    /** Empty becomes NULL rather than ''. A blank column should read as absent. */
    private static function nullable(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
