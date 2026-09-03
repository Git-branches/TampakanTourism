<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\SmsGateway;

/**
 * TourSync — tour guide requests.                                    Feature 4
 *
 * A tourist standing at Jadas taps "Request a Tour Guide". The Municipal
 * Tourism Office learns about it on a phone within seconds, answers in the
 * system, and the answer reaches the tourist as a text.
 *
 * WHY THE OFFICE IS TEXTED AND NOT ONLY SHOWN A BADGE
 *
 * The person who can arrange a guide is frequently not at the desk — they are
 * at a barangay meeting or out at a site. A request that waits for someone to
 * open a browser is a request the visitor has given up on. The system is the
 * record; the text is what makes the record arrive in time.
 *
 * WHY THE REPLY GOES BOTH WAYS
 *
 * A tourist who asks and hears nothing assumes the answer is no. Every status
 * change that a visitor would want to know about carries an SMS, and the send
 * is recorded on the row — because "we told them" and "we meant to tell them"
 * look identical afterwards otherwise.
 */
final class TourGuideRepository
{
    /** What the office can move a request to, and what a visitor is told it means. */
    public const STATUSES = [
        'new'          => 'New',
        'acknowledged' => 'Seen by the Office',
        'assigned'     => 'Guide assigned',
        'completed'    => 'Completed',
        'declined'     => 'Declined',
        'cancelled'    => 'Cancelled',
        'no_show'      => 'Did not arrive',
    ];

    /**
     * A visit that has not happened yet, or has not been closed.
     *
     * NOT the same list as OPEN_STATUSES below, and the difference matters.
     * That one drives the badge and means "the office must act now"; an
     * assigned request needs nothing until the day arrives, so it is
     * deliberately absent from it.
     *
     * This one is what the calendar watches: a promise made and not yet
     * discharged. An assigned request is very much still owed.
     */
    public const OUTSTANDING_STATUSES = ['new', 'acknowledged', 'assigned'];

    /**
     * The times a visitor may ask for.
     *
     * THIS WAS A TEXT BOX. People typed "morning", "9am", "9:00 AM", "around
     * 10ish" — all reasonable things to write, none of them comparable. The
     * office could not sort a day's visits into the order they would happen,
     * and the week-ahead panel ordered by this column as text, which put
     * "morning" and "9:00 AM" in an order that meant nothing.
     *
     * Stored as 24-hour HH:MM because that is the one format that sorts
     * correctly as a string, and shown to people in the 12-hour clock they
     * actually speak. The form uses <input type="time"> stepping in half hours
     * between these two — one line high, rather than the twenty-six item
     * dropdown that replaced the text box first and opened taller than the
     * screen.
     *
     * An empty value is deliberate and kept: somebody planning a sunrise trek
     * three weeks out genuinely does not know, and forcing a number out of
     * them would only record a guess.
     */
    public const TIME_OPENS  = '05:00';
    public const TIME_CLOSES = '17:00';

    /**
     * Why a submitted time cannot be used, or null when it can.
     *
     * The picker offers half hours between opening and closing, but a time
     * input can be typed into as well as spun, and the field is only a
     * suggestion to anything posting straight at the endpoint.
     */
    public static function timeProblem(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw)) {
            return 'Please choose a time from the picker.';
        }

        if ($raw < self::TIME_OPENS || $raw > self::TIME_CLOSES) {
            return 'The destinations are open ' . self::formatTime(self::TIME_OPENS)
                 . ' to ' . self::formatTime(self::TIME_CLOSES) . '. Choose a time in between, '
                 . 'or leave it blank and the Office will advise.';
        }

        return null;
    }

    /**
     * A stored time as a person reads it.
     *
     * Anything that is not HH:MM is handed back untouched — the requests taken
     * before this was a picker hold free text like "morning", and a record of
     * what somebody actually asked for should not be quietly rewritten.
     */
    public static function formatTime(?string $raw): string
    {
        $raw = trim((string) $raw);

        if ($raw === '' || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw, $m)) {
            return $raw;
        }

        $hour = (int) $m[1];
        $am   = $hour < 12;
        $show = $hour % 12 === 0 ? 12 : $hour % 12;

        return $show . ':' . $m[2] . ' ' . ($am ? 'AM' : 'PM');
    }

    /**
     * Where visitors are collected: the Tourism Office, from Settings.
     *
     * Falls back to the office's name alone when no address has been set, and
     * to a plain phrase when even that is blank — a receipt that says "the
     * Municipal Tourism Office" is still true, where an empty line is a
     * question the visitor has to ring up to ask.
     */
    public static function officeMeetingPoint(): string
    {
        $name    = trim((string) (setting('office_name', '') ?? '')) ?: 'Municipal Tourism Office';
        $address = trim((string) (setting('office_address', '') ?? ''));

        return $address !== '' ? $name . ', ' . $address : $name;
    }

    /** Statuses that still need somebody to do something. Drives the badge. */
    public const OPEN_STATUSES = ['new', 'acknowledged'];

    /**
     * Records a request and returns [id, reference_code].
     *
     * The reference code is generated here rather than derived from the id so
     * it can be shown on the confirmation screen without a second query, and so
     * consecutive requests do not produce guessable codes — TG-0007 invites
     * somebody to try TG-0008 and read a stranger's arrangements.
     *
     * @param array<string, mixed> $data
     * @return array{id: int, reference: string}
     */
    public static function create(array $data): array
    {
        $reference = self::generateReference();

        /* ONE REQUEST, SEVERAL PLACES.
         *
         * destination_ids is the list the visitor built on the form, in the
         * order they intend to visit. destination_id — the column that has
         * always been here — keeps the FIRST of them, because every existing
         * query reads it: the inbox filter, the office badge, the week-ahead
         * panel. It is the primary destination, not a second copy of the list.
         *
         * A caller passing the old single destination_id still works. The form
         * was not the only thing that ever wrote here. */
        $chosen = $data['destination_ids'] ?? null;

        if (!is_array($chosen)) {
            $chosen = isset($data['destination_id']) && $data['destination_id']
                ? [(int) $data['destination_id']]
                : [];
        }

        /* Unique, positive, and in the order given. array_unique rather than a
           trust in the form's JavaScript — this is the public endpoint, and the
           unique index on the join table would otherwise reject the whole
           insert over a duplicate the visitor cannot see. */
        $chosen = array_values(array_unique(array_filter(
            array_map('intval', $chosen),
            static fn(int $id): bool => $id > 0
        )));

        $primary = $chosen[0] ?? null;

        /* Written together or not at all. A request row whose destinations
           failed to insert is a request for nowhere, and the visitor is holding
           a receipt that says otherwise. */
        $id = Database::transaction(static function () use ($data, $reference, $chosen, $primary): int {
            $id = Database::insert(
                /* issued_at is stamped now, not when the office decides. The
                   receipt exists from the moment the tourist presses send — that
                   is the thing they are owed for filling the form in. */
                "INSERT INTO tour_guide_requests
                    (reference_code, issued_at, destination_id, needs_advice, source, visitor_name,
                     contact_number, contact_email, party_size, preferred_date, preferred_time,
                     notes, device_hash)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $reference,
                    $primary,
                    /* Recorded as a fact, not inferred from an empty list. A
                       visitor who asked to be advised and one who skipped the
                       field both leave destination_id NULL, and the office
                       answers those two differently. */
                    !empty($data['needs_advice']) ? 1 : 0,
                    in_array($data['source'] ?? '', ['qr', 'website'], true) ? $data['source'] : 'website',
                    mb_substr(trim((string) $data['visitor_name']), 0, 120),
                    (string) $data['contact_number'],
                    ($data['contact_email'] ?? '') !== '' ? mb_substr((string) $data['contact_email'], 0, 190) : null,
                    max(1, min(200, (int) ($data['party_size'] ?? 1))),
                    ($data['preferred_date'] ?? '') !== '' ? $data['preferred_date'] : null,
                    ($data['preferred_time'] ?? '') !== '' ? mb_substr((string) $data['preferred_time'], 0, 40) : null,
                    ($data['notes'] ?? '') !== '' ? mb_substr((string) $data['notes'], 0, 600) : null,
                    $data['device_hash'] ?? null,
                ]
            );

            foreach ($chosen as $position => $destinationId) {
                Database::run(
                    'INSERT INTO tour_request_destinations (request_id, destination_id, sort_order)
                     VALUES (?, ?, ?)',
                    [$id, $destinationId, $position]
                );
            }

            return $id;
        });

        return ['id' => $id, 'reference' => $reference];
    }

    /**
     * Every destination on one request, in the order the visitor chose them.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function destinationsFor(int $requestId): array
    {
        return Database::all(
            "SELECT d.id, d.name, d.slug, t.sort_order
               FROM tour_request_destinations t
               JOIN destinations d ON d.id = t.destination_id
              WHERE t.request_id = ?
              ORDER BY t.sort_order, d.name",
            [$requestId]
        );
    }

    /**
     * The same thing for a whole list of requests, in one query.
     *
     * The office inbox draws a hundred rows and each one names its
     * destinations. Asking per row is a hundred round trips for a page that
     * used to take one, which is the cost nobody notices in development and
     * everybody notices on a municipal connection.
     *
     * @param  array<int, int> $requestIds
     * @return array<int, array<int, string>> request id => destination names
     */
    public static function destinationsForMany(array $requestIds): array
    {
        $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));

        if ($requestIds === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($requestIds), '?'));

        $rows = Database::all(
            "SELECT t.request_id, d.name
               FROM tour_request_destinations t
               JOIN destinations d ON d.id = t.destination_id
              WHERE t.request_id IN ({$marks})
              ORDER BY t.request_id, t.sort_order, d.name",
            $requestIds
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['request_id']][] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * The destinations of one request as a person would say them.
     *
     * 'Jadas Falls', 'Jadas Falls and Kolon Ridge', 'Jadas Falls, Kolon Ridge
     * and Bulol Falls'. Used in the SMS and nowhere that needs the list itself.
     *
     * Returns '' when there is nothing to name, and the callers rely on that:
     * an empty string means the phrase is left out of the message entirely
     * rather than replaced with a place the visitor never asked about.
     *
     * @param array<int, string> $names
     */
    public static function nameList(array $names): string
    {
        $names = array_values(array_filter(array_map('trim', $names), static fn(string $n): bool => $n !== ''));

        if ($names === []) {
            return '';
        }

        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }

    /**
     * What to call this request's destinations in a message to the visitor.
     *
     * Falls back to the primary destination column when the join table holds
     * nothing — which is the case for a request taken before this table
     * existed and not yet backfilled, and for any row written by older code.
     *
     * $maxNamed caps how many are spelled out, for the messages that are
     * charged by the segment. A visitor who picked five places gets 'Jadas
     * Falls, Kolon Ridge and 3 more' rather than a third SMS the office pays
     * for — and the receipt, which costs nothing to be long, passes no cap and
     * lists all five.
     *
     * @param array<string, mixed> $request a row from find()
     */
    public static function destinationPhrase(array $request, ?int $maxNamed = null): string
    {
        $names = array_column(self::destinationsFor((int) $request['id']), 'name');

        if ($names === []) {
            $single = trim((string) ($request['destination_name'] ?? ''));
            $names  = $single !== '' ? [$single] : [];
        }

        if ($maxNamed !== null && count($names) > $maxNamed) {
            $rest  = count($names) - $maxNamed;
            $names = array_slice($names, 0, $maxNamed);

            return implode(', ', $names) . ' and ' . $rest . ' more';
        }

        return self::nameList($names);
    }

    /**
     * A short code a person can read down a phone line.
     *
     * Drawn from an alphabet with no O/0 and no I/1/L, because the code exists
     * to be spoken and those are the characters that get spoken wrongly. Retries
     * on the astronomically unlikely collision rather than trusting one draw.
     */
    private static function generateReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = 'TG-';

            for ($i = 0; $i < 5; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            if (Database::scalar('SELECT 1 FROM tour_guide_requests WHERE reference_code = ?', [$code]) === null) {
                return $code;
            }
        }

        /* Eight collisions in a 31^5 space means something is wrong with the
           random source, not with luck. Fall back to something certainly
           unique rather than looping forever. */
        return 'TG-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::first(
            "SELECT g.*, d.name AS destination_name, d.slug AS destination_slug,
                    a.full_name AS handled_by_name
               FROM tour_guide_requests g
               LEFT JOIN destinations d ON d.id = g.destination_id
               LEFT JOIN admins a       ON a.id = g.handled_by
              WHERE g.id = ?",
            [$id]
        );
    }

    /**
     * The office inbox. Open requests first, then newest — a request from this
     * morning that nobody has touched outranks one that was settled last week.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function inbox(array $filters = [], int $limit = 100): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['status']) && isset(self::STATUSES[$filters['status']])) {
            $where[]  = 'g.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['destination_id'])) {
            $where[]  = 'g.destination_id = ?';
            $params[] = (int) $filters['destination_id'];
        }

        /* SEARCHED ON WHAT THE OFFICE HAS IN FRONT OF THEM.
         *
         * A reference read off a receipt at the counter, a name, or the number
         * the visitor is calling from — those are the three things somebody
         * arrives holding. Nothing else is worth an index scan. */
        /* WHEN THE VISIT IS, not when the request arrived.
         *
         * An officer filtering by date is asking "what is happening this week",
         * and the answer is preferred_date. created_at would answer "what came
         * in this week", which is a different question and the one the queue is
         * already sorted by.
         *
         * A request with no date given is excluded from every window rather than
         * appearing in all of them — the visitor has not said when, so no week
         * can honestly claim it. */
        $window = (string) ($filters['date'] ?? '');

        if ($window !== '') {
            $where[] = 'g.preferred_date IS NOT NULL';

            switch ($window) {
                case 'today':
                    $where[] = 'g.preferred_date = CURDATE()';
                    break;
                case 'week':
                    $where[] = 'g.preferred_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
                    break;
                case 'month':
                    $where[] = 'g.preferred_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
                    break;
                case 'past':
                    $where[] = 'g.preferred_date < CURDATE()';
                    break;
                default:
                    /* Not a window this system offers — drop the IS NOT NULL that
                       was pushed for it, so an unknown value filters nothing
                       rather than silently hiding every dateless request. */
                    array_pop($where);
            }
        }

        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $term     = '%' . trim((string) $filters['search']) . '%';
            $where[]  = '(g.reference_code LIKE ? OR g.visitor_name LIKE ? OR g.contact_number LIKE ?)';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql = "SELECT g.*, d.name AS destination_name, a.full_name AS handled_by_name
                  FROM tour_guide_requests g
                  LEFT JOIN destinations d ON d.id = g.destination_id
                  LEFT JOIN admins a       ON a.id = g.handled_by";

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= " ORDER BY FIELD(g.status, 'new', 'acknowledged', 'assigned', 'completed', 'declined', 'cancelled'),
                           g.created_at DESC
                  LIMIT " . max(1, min(500, $limit));

        return Database::all($sql, $params);
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);

        foreach (Database::all('SELECT status, COUNT(*) c FROM tour_guide_requests GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }

        return $out;
    }

    /** How many requests nobody has settled. The sidebar badge. */
    /**
     * Who is coming, between today and $days from now.
     *
     * The office had no way to ask this. A request accepted three weeks out was
     * assigned and then out of sight — the queue is ordered by when a request
     * arrived, which is not the order the visitors do.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function upcoming(int $days = 7, int $limit = 25): array
    {
        $marks = implode(',', array_fill(0, count(self::OUTSTANDING_STATUSES), '?'));

        return Database::all(
            "SELECT g.*, d.name AS destination_name,
                    DATEDIFF(g.preferred_date, CURDATE()) AS days_away
               FROM tour_guide_requests g
               LEFT JOIN destinations d ON d.id = g.destination_id
              WHERE g.preferred_date IS NOT NULL
                AND g.preferred_date >= CURDATE()
                AND g.preferred_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                AND g.status IN ({$marks})
              /* NULLs last: a visitor with no preference is not the first
                 appointment of the day, they are the one still to be pinned
                 down. MySQL sorts NULL first without the guard. */
              ORDER BY g.preferred_date, g.preferred_time IS NULL, g.preferred_time
              LIMIT " . max(1, min(100, $limit)),
            array_merge([max(0, $days)], self::OUTSTANDING_STATUSES)
        );
    }

    /**
     * Visits whose date has gone by with nobody closing the request.
     *
     * These are invisible in an inbox sorted by arrival: they sink, and the
     * longer they sit the further down they go. A request that says "assigned"
     * six weeks after the visit is not a record of anything — nobody knows
     * whether that visitor was met.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function overdue(int $limit = 25): array
    {
        $marks = implode(',', array_fill(0, count(self::OUTSTANDING_STATUSES), '?'));

        return Database::all(
            "SELECT g.*, d.name AS destination_name,
                    DATEDIFF(CURDATE(), g.preferred_date) AS days_late
               FROM tour_guide_requests g
               LEFT JOIN destinations d ON d.id = g.destination_id
              WHERE g.preferred_date IS NOT NULL
                AND g.preferred_date < CURDATE()
                AND g.status IN ({$marks})
              ORDER BY g.preferred_date
              LIMIT " . max(1, min(100, $limit)),
            self::OUTSTANDING_STATUSES
        );
    }

    /** How many visits have gone by unclosed. Cheap enough for every page. */
    public static function overdueCount(): int
    {
        $marks = implode(',', array_fill(0, count(self::OUTSTANDING_STATUSES), '?'));

        return (int) Database::scalar(
            "SELECT COUNT(*) FROM tour_guide_requests
              WHERE preferred_date IS NOT NULL
                AND preferred_date < CURDATE()
                AND status IN ({$marks})",
            self::OUTSTANDING_STATUSES
        );
    }

    public static function openCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM tour_guide_requests WHERE status IN ('new','acknowledged')"
        );
    }

    /**
     * Moves a request along.
     *
     * REFUSES to assign without a guide's name, and to decline without a
     * reason. Both refusals exist because the visitor is told the outcome: an
     * assignment with nobody named tells them to expect a guide they cannot
     * find, and a bare decline reads as the office not bothering.
     *
     * @param array<string, mixed> $data
     * @return string|null null on success, otherwise why it was refused
     */
    public static function decide(int $id, string $status, int $adminId, array $data = []): ?string
    {
        if (!isset(self::STATUSES[$status])) {
            return 'That is not a status this system uses.';
        }

        $guideName    = trim((string) ($data['guide_name'] ?? ''));
        $guideContact = trim((string) ($data['guide_contact'] ?? ''));
        $meetingPoint = trim((string) ($data['meeting_point'] ?? ''));
        $note         = trim((string) ($data['office_note'] ?? ''));

        /* CHOSEN FROM THE TOUR GUIDE LIST, OR TYPED.
         *
         * The office now keeps a tour guide list, and picking from it is the normal
         * path. Typing a name still works and is deliberately kept: the tour guide list
         * is new, it will be incomplete for a while, and an officer with a
         * visitor waiting must not be blocked because the person who is free
         * this afternoon has not been entered yet.
         *
         * A chosen guide's name and number are SNAPSHOT onto the request rather
         * than read through the join later. The visitor was texted a particular
         * name and number; if that guide later changes their mobile or leaves
         * the tour guide list, the record of what the visitor was told must not quietly
         * rewrite itself. */
        $guideId = (int) ($data['guide_id'] ?? 0);

        if ($status === 'assigned' && $guideId > 0) {
            $roster = TourGuideRosterRepository::find($guideId);

            if ($roster === null) {
                return 'That guide is no longer on the tour guide list. Choose another.';
            }

            /* The gate §19 asks for, enforced here rather than only in the
               dropdown — the dropdown is a convenience and this is the rule. An
               expired, suspended or revoked guide must not reach a visitor. */
            if (!TourGuideRosterRepository::isAssignable($roster)) {
                return $roster['full_name'] . ' cannot be assigned — their ID is '
                    . strtolower(TourGuideRosterRepository::EFFECTIVE[$roster['effective_status']])
                    . '. Choose another guide, or fix their record first.';
            }

            $guideName    = (string) $roster['full_name'];
            $guideContact = (string) ($roster['mobile_number'] ?? '');
        }

        if ($status === 'assigned' && $guideName === '') {
            return 'Name the guide before assigning — the visitor is told who to look for.';
        }

        /* WHERE, filled in rather than asked for.
         *
         * The office collects every visitor at the Tourism Office and the guide
         * meets them there — one place, every time. So this is not a question
         * put to the officer on each request; it is read from Settings.
         *
         * Stored on the row all the same rather than looked up when the receipt
         * is drawn. A receipt is a record of what somebody was told, and an
         * office that moves next year must not silently rewrite the address on
         * every arrangement it ever made. */
        if ($status === 'assigned' && $meetingPoint === '') {
            $meetingPoint = self::officeMeetingPoint();
        }

        if ($status === 'declined' && $note === '') {
            return 'Say why this is being declined. The visitor receives this sentence.';
        }

        if ($status === 'no_show' && $note === '') {
            return 'Say what happened. "Did not arrive" is a record about a person, '
                 . 'and it should carry a sentence explaining itself.';
        }

        /* met_at has existed since the table was created and nothing ever wrote
           to it. It is stamped here, on the one transition that means the visit
           actually took place — which is the only thing it was ever for. */
        Database::run(
            "UPDATE tour_guide_requests
                SET status = ?,
                    guide_id      = COALESCE(NULLIF(?, 0), guide_id),
                    guide_name    = COALESCE(NULLIF(?, ''), guide_name),
                    guide_contact = COALESCE(NULLIF(?, ''), guide_contact),
                    meeting_point = COALESCE(NULLIF(?, ''), meeting_point),
                    office_note   = COALESCE(NULLIF(?, ''), office_note),
                    met_at        = CASE WHEN ? = 'completed' AND met_at IS NULL
                                         THEN NOW() ELSE met_at END,
                    handled_by = ?, handled_at = NOW()
              WHERE id = ?",
            [
                $status,
                $guideId,
                mb_substr($guideName, 0, 120),
                self::normaliseOrKeep($guideContact),
                mb_substr($meetingPoint, 0, 160),
                mb_substr($note, 0, 600),
                $status,
                $adminId,
                $id,
            ]
        );

        return null;
    }

    /**
     * A guide's contact stored in the same shape as every other number here, so
     * the office can tap it on a phone. Left as typed when it will not
     * normalise — a landline or an extension is still worth recording.
     */
    private static function normaliseOrKeep(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        return SmsGateway::normalise($raw) ?? mb_substr($raw, 0, 20);
    }

    /**
     * Texts the office that a request has come in.
     *
     * Recipients are the alert recipients — the same office staff, the same
     * opt-out, the same extra numbers from Settings. Deliberately reused rather
     * than reimplemented: two lists of office phone numbers drift apart, and
     * the day they do is the day one of them is silently wrong.
     *
     * Never throws. The request is saved before this runs and a provider outage
     * must not lose it.
     *
     * @return int how many messages went out
     */
    /**
     * Texts the office that a request has come in.
     *
     * Recipients are the alert recipients — the same office staff, the same
     * opt-out, the same extra numbers from Settings. Deliberately reused rather
     * than reimplemented: two lists of office phone numbers drift apart, and
     * the day they do is the day one of them is silently wrong.
     *
     * Never throws. The request is saved before this runs and a provider outage
     * must not lose it.
     *
     * @return int how many messages went out
     */
    public static function notifyOffice(int $id): int
    {
        if ((string) (setting('guide_sms_enabled', '1') ?? '1') !== '1') {
            return 0;
        }

        $request = self::find($id);

        if ($request === null) {
            return 0;
        }

        /* The destinations are named or they are not — never "Tampakan"
           standing in for a choice the visitor did not make. Same rule as the
           messages to the visitor, for the same reason.
           Two named at most: this is a nudge to open the screen, not the
           record, and the screen has the full list. */
        $named = self::destinationPhrase($request, 2);
        $at    = $named !== '' ? ' at ' . $named : '';

        $when = !empty($request['preferred_date'])
            ? date('M j', strtotime((string) $request['preferred_date']))
            : 'no date given';

        $body = 'TourSync — Tour guide requested' . $at . '. '
            . (int) $request['party_size'] . ' pax, ' . $when . '. '
            . 'Ref ' . $request['reference_code'] . '. Answer in Tour Guide Requests.';

        $sent = 0;

        foreach (AlertRepository::officeRecipients() as $number) {
            try {
                if (!empty(SmsGateway::send($number, $body)['ok'])) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                error_log('Guide request notification failed: ' . $e->getMessage());
            }
        }

        /* Stamped only when something actually went. "We told them" and "we
           meant to tell them" look identical afterwards otherwise. */
        if ($sent > 0) {
            Database::run('UPDATE tour_guide_requests SET office_notified_at = NOW() WHERE id = ?', [$id]);
        }

        return $sent;
    }

    /**
     * Texts the visitor the office's answer.
     *
     * @return array{sent: bool, error: ?string}
     */
    public static function notifyVisitor(int $id): array
    {
        /* THE KILL SWITCH COVERS THIS ONE TOO.
         *
         * An earlier version gated notifyOffice() only, so a run with SMS
         * "disabled" still texted the visitor — real money, a real number. A
         * switch that turns off half the messages is worse than no switch,
         * because it is believed. */
        if ((string) (setting('guide_sms_enabled', '1') ?? '1') !== '1') {
            return [
                'sent'  => false,
                'error' => 'SMS is switched off for guide requests in Settings, so the visitor was not texted.',
            ];
        }

        $request = self::find($id);

        if ($request === null) {
            return ['sent' => false, 'error' => 'Request not found.'];
        }

        $number = SmsGateway::normalise((string) $request['contact_number']);

        if ($number === null) {
            /* Says what to do, not only what went wrong. An officer reading
               "not a mobile number" still has a visitor waiting for an answer,
               and the answer now has to travel by voice. */
            return [
                'sent'  => false,
                'error' => 'That is not a mobile number this system can text ('
                    . $request['contact_number'] . '). Please call the visitor instead.',
            ];
        }

        /* THE DESTINATION IS MENTIONED, OR IT IS NOT.
         *
         * This used to fall back to "Tampakan" when a visitor had not chosen a
         * destination, so the message read "your guide request for Tampakan" —
         * a place they never asked about, in a text from the municipality they
         * are already standing in. The office asked for the phrase to be left
         * out entirely instead, which is what a person would do.
         *
         * Built once as a fragment that is either " for Jadas Falls" or nothing
         * at all, so no branch below can forget the rule.
         *
         * Now a list rather than one name, because a request can carry several
         * — 'for Jadas Falls and Kolon Ridge'. Capped at two spelled out: the
         * assigned message is already two segments and a third costs the office
         * real money, and the visitor is holding a receipt that lists them all
         * anyway. */
        $named = self::destinationPhrase($request, 2);
        $for   = $named !== '' ? ' for ' . $named : '';

        $note = trim((string) ($request['office_note'] ?? ''));
        $ref  = (string) $request['reference_code'];

        switch ((string) $request['status']) {
            case 'assigned':
                /* Where to stand, not only who to look for. A visitor holding a
                   name and a number and no place still has to ring the office.
                   Trimmed to 110 characters: the office's full address is long
                   and a third SMS segment is money this office does not have. */
                $meet = trim((string) ($request['meeting_point'] ?? ''));

                $body = 'Tampakan Tourism Office: Your tour guide' . $for . ' is '
                    . $request['guide_name']
                    . ($request['guide_contact'] ? ' (' . $request['guide_contact'] . ')' : '')
                    . ($meet !== '' ? '. Please meet at ' . mb_substr($meet, 0, 110) : '')
                    . '. Please present your digital receipt.';
                break;

            case 'declined':
                $body = 'Tampakan Tourism Office: We cannot arrange a guide' . $for
                    . ' — ' . mb_substr($note, 0, 90) . ' Ref: ' . $ref . '.';
                break;

            case 'acknowledged':
                $body = 'Tampakan Tourism Office: We received your tour guide request' . $for
                    . ' and are arranging a guide. We will contact you shortly. Ref: ' . $ref . '.';
                break;

            default:
                $body = 'Tampakan Tourism Office: Update on your tour guide request' . $for
                    . ' — ' . self::STATUSES[(string) $request['status']]
                    . ($note !== '' ? '. ' . mb_substr($note, 0, 80) : '')
                    . '. Ref: ' . $ref . '.';
        }

        try {
            $result = SmsGateway::send($number, $body);
        } catch (\Throwable $e) {
            error_log('Guide reply to visitor failed: ' . $e->getMessage());

            /* The provider's own words are not shown to the officer — they can
               carry account identifiers. What the officer needs to know is that
               it did not go, so they can pick up a phone. */
            return ['sent' => false, 'error' => 'The message could not be sent. Please call the visitor instead.'];
        }

        if (empty($result['ok'])) {
            return ['sent' => false, 'error' => 'The message could not be sent. Please call the visitor instead.'];
        }

        Database::run('UPDATE tour_guide_requests SET visitor_notified_at = NOW() WHERE id = ?', [$id]);

        return ['sent' => true, 'error' => null];
    }

    /**
     * Requests this device has filed recently.
     *
     * Backstop for the rate limiter: somebody who clears cookies gets a fresh
     * IP window, but the device hash survives it. Not a security boundary —
     * an anti-nuisance measure on a form that anyone may legitimately use.
     */
    public static function recentForDevice(string $deviceHash, int $withinHours = 6): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM tour_guide_requests
              WHERE device_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)',
            [$deviceHash, $withinHours]
        );
    }
}
