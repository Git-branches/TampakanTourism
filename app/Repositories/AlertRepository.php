<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\SmsGateway;

/**
 * =============================================================================
 *  TourSync — destination alerts                                     Feature 3
 * -----------------------------------------------------------------------------
 *  The manager's channel TO the office, which is the direction that matters
 *  when something has gone wrong. A landslide closes the trail; the manager is
 *  standing at the waterfall with no data signal and one bar of GSM; the office
 *  learns about it in a minute instead of on Friday.
 *
 *  WHAT THE PARSER IS AND IS NOT
 *
 *  It reads a text for words that suggest what happened and how bad it is, and
 *  it is a suggestion — never a decision. Every parsed alert keeps the original
 *  message verbatim, and the office can change the category and severity in one
 *  click. A parser that quietly downgraded "may nalunod" to routine because it
 *  did not recognise the word would be worse than no parser at all, so the
 *  DEFAULT IS URGENT for anything unrecognised: a false alarm costs a phone
 *  call, a missed emergency costs more.
 *
 *  Bilingual on purpose. A manager under pressure writes in whichever language
 *  reaches their hands first, and it is usually not English.
 * =============================================================================
 */
final class AlertRepository
{
    public const CATEGORIES = [
        'closure'  => 'Closure',
        'hazard'   => 'Hazard',
        'accident' => 'Accident or injury',
        'weather'  => 'Weather',
        'utility'  => 'Utility or facility',
        'crowding' => 'Overcrowding',
        'other'    => 'Other',
    ];

    public const SEVERITIES = [
        'info'    => 'For information',
        'warning' => 'Needs attention',
        'urgent'  => 'Urgent',
    ];

    public const STATUSES = [
        'new'          => 'New',
        'acknowledged' => 'Acknowledged',
        'resolved'     => 'Resolved',
        'dismissed'    => 'Dismissed',
    ];

    /**
     * Words that point at a category, English and Filipino.
     *
     * Ordered by how much they matter: accident is checked before hazard, and
     * hazard before closure, because a text saying "landslide, closed, may
     * nasugatan" is an accident first.
     */
    private const CATEGORY_CUES = [
        'accident' => ['accident', 'injured', 'injury', 'drown', 'drowned', 'drowning', 'rescue',
                       'nalunod', 'nasugatan', 'aksidente', 'nahulog', 'sugatan', 'namatay', 'casualty'],
        'hazard'   => ['landslide', 'rockfall', 'collapse', 'crack', 'broken', 'damaged', 'unsafe',
                       'guhô', 'guho', 'landslip', 'sira', 'nasira', 'delikado', 'mapanganib', 'bitak', 'snake', 'ahas'],
        'weather'  => ['flood', 'flooding', 'typhoon', 'storm', 'heavy rain', 'lightning',
                       'baha', 'bagyo', 'ulan', 'kidlat', 'malakas na ulan'],
        'closure'  => ['closed', 'closing', 'close', 'shut', 'suspend', 'suspended',
                       'sarado', 'isasara', 'sinara', 'walang pasok', 'hindi bukas'],
        'utility'  => ['no water', 'power', 'brownout', 'outage', 'electricity', 'toilet', 'restroom',
                       'walang tubig', 'walang kuryente', 'sirang', 'cr'],
        'crowding' => ['crowded', 'overcrowd', 'too many', 'full', 'capacity',
                       'siksikan', 'punô', 'puno', 'ang dami', 'sobrang dami'],
    ];

    /** Words that raise or lower how urgent a message is taken to be. */
    private const URGENT_CUES = [
        'urgent', 'emergency', 'now', 'immediately', 'help', 'sos', 'critical', 'serious',
        'emergency!', 'tulong', 'saklolo', 'agad', 'ngayon na', 'delikado', 'grabe',
    ];

    private const CALM_CUES = [
        'fyi', 'for information', 'update', 'reminder', 'no problem', 'minor', 'small',
        'para alam', 'pakisabi', 'wala namang', 'maliit lang',
    ];

    // -------------------------------------------------------------------------
    // Reading a message
    // -------------------------------------------------------------------------

    /**
     * Reads a free-text message into a category and a severity.
     *
     * @return array{category:string, severity:string, confident:bool}
     */
    public static function classify(string $text): array
    {
        $norm = ' ' . mb_strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? '')) . ' ';

        $category  = 'other';
        $confident = false;

        foreach (self::CATEGORY_CUES as $key => $cues) {
            foreach ($cues as $cue) {
                if (str_contains($norm, ' ' . $cue) || str_contains($norm, $cue . ' ')) {
                    $category  = $key;
                    $confident = true;
                    break 2;
                }
            }
        }

        /* Severity starts high and is talked DOWN, never up. An unrecognised
           message from a destination manager is not routine by default — they
           do not text the office about nothing. */
        $severity = 'urgent';

        $hasUrgent = false;
        foreach (self::URGENT_CUES as $cue) {
            if (str_contains($norm, ' ' . $cue) || str_contains($norm, $cue . ' ')) {
                $hasUrgent = true;
                break;
            }
        }

        if (!$hasUrgent) {
            /* An accident stays urgent whatever else the message says. */
            if ($category === 'accident') {
                $severity = 'urgent';
            } else {
                $severity = 'warning';

                foreach (self::CALM_CUES as $cue) {
                    if (str_contains($norm, ' ' . $cue) || str_contains($norm, $cue . ' ')) {
                        $severity = 'info';
                        break;
                    }
                }
            }
        }

        return ['category' => $category, 'severity' => $severity, 'confident' => $confident];
    }

    /**
     * The manager an inbound number belongs to.
     *
     * Best-effort identification, not authentication — a number can be spoofed.
     * The office sees the raw number on every alert so they can judge.
     */
    public static function managerForNumber(string $number): ?array
    {
        $normalised = SmsGateway::normalise($number);

        if ($normalised === null) {
            return null;
        }

        /* Stored numbers are typed by a human and come in every shape, so both
           sides are reduced to their last ten digits before comparing:
           +639171112233, 09171112233 and 9171112233 are one phone. */
        $tail = substr(preg_replace('/\D/', '', $normalised) ?? '', -10);

        if (strlen($tail) !== 10) {
            return null;
        }

        return Database::first(
            "SELECT m.*, d.name AS destination_name
               FROM destination_managers m
               JOIN destinations d ON d.id = m.destination_id
              WHERE m.is_active = 1
                AND m.mobile_number IS NOT NULL
                AND RIGHT(REGEXP_REPLACE(m.mobile_number, '[^0-9]', ''), 10) = ?
              LIMIT 1",
            [$tail]
        );
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO destination_alerts
                (destination_id, raised_by, channel, category, severity, message,
                 raw_text, from_number, provider_ref)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                !empty($data['destination_id']) ? (int) $data['destination_id'] : null,
                !empty($data['raised_by']) ? (int) $data['raised_by'] : null,
                in_array($data['channel'] ?? '', ['portal', 'sms'], true) ? $data['channel'] : 'portal',
                isset(self::CATEGORIES[$data['category'] ?? '']) ? $data['category'] : 'other',
                isset(self::SEVERITIES[$data['severity'] ?? '']) ? $data['severity'] : 'warning',
                mb_substr(trim((string) ($data['message'] ?? '')), 0, 1000),
                isset($data['raw_text']) ? mb_substr((string) $data['raw_text'], 0, 1000) : null,
                isset($data['from_number']) ? mb_substr((string) $data['from_number'], 0, 20) : null,
                isset($data['provider_ref']) ? mb_substr((string) $data['provider_ref'], 0, 120) : null,
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT a.*, d.name AS destination_name, m.full_name AS raised_by_name,
                    m.mobile_number AS raised_by_number,
                    ad.full_name AS acknowledged_by_name,
                    rp.full_name AS replied_by_name
               FROM destination_alerts a
               LEFT JOIN destinations d ON d.id = a.destination_id
               LEFT JOIN destination_managers m ON m.id = a.raised_by
               LEFT JOIN admins ad ON ad.id = a.acknowledged_by
               LEFT JOIN admins rp ON rp.id = a.replied_by
              WHERE a.id = ?',
            [$id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function inbox(array $filters = [], int $limit = 100): array
    {
        $clauses = ['1=1'];
        $params  = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'a.status = ?';
            $params[]  = $filters['status'];
        }

        if (!empty($filters['severity'])) {
            $clauses[] = 'a.severity = ?';
            $params[]  = $filters['severity'];
        }

        if (!empty($filters['destination_id'])) {
            $clauses[] = 'a.destination_id = ?';
            $params[]  = (int) $filters['destination_id'];
        }

        return Database::all(
            'SELECT a.*, d.name AS destination_name, m.full_name AS raised_by_name,
                    ad.full_name AS acknowledged_by_name,
                    rp.full_name AS replied_by_name
               FROM destination_alerts a
               LEFT JOIN destinations d ON d.id = a.destination_id
               LEFT JOIN destination_managers m ON m.id = a.raised_by
               LEFT JOIN admins ad ON ad.id = a.acknowledged_by
               LEFT JOIN admins rp ON rp.id = a.replied_by
              WHERE ' . implode(' AND ', $clauses) . "
              ORDER BY FIELD(a.status, 'new', 'acknowledged', 'resolved', 'dismissed'),
                       FIELD(a.severity, 'urgent', 'warning', 'info'),
                       a.created_at DESC
              LIMIT " . max(1, min($limit, 300)),
            $params
        );
    }

    /** One destination's alerts, for the manager's own history. */
    public static function forDestination(int $destinationId, int $limit = 50): array
    {
        return Database::all(
            'SELECT a.*, ad.full_name AS acknowledged_by_name
               FROM destination_alerts a
               LEFT JOIN admins ad ON ad.id = a.acknowledged_by
              WHERE a.destination_id = ?
              ORDER BY a.created_at DESC
              LIMIT ' . max(1, min($limit, 200)),
            [$destinationId]
        );
    }

    public static function counts(): array
    {
        $out = ['new' => 0, 'acknowledged' => 0, 'resolved' => 0, 'dismissed' => 0, 'urgent_new' => 0];

        foreach (Database::all('SELECT status, COUNT(*) n FROM destination_alerts GROUP BY status') as $row) {
            $out[$row['status']] = (int) $row['n'];
        }

        $out['urgent_new'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM destination_alerts WHERE status = 'new' AND severity = 'urgent'"
        );

        return $out;
    }

    /** Unacknowledged urgent alerts, for the badge every officer screen shows. */
    public static function urgentWaiting(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM destination_alerts WHERE status = 'new' AND severity = 'urgent'"
        );
    }

    // -------------------------------------------------------------------------
    // The office acting on one
    // -------------------------------------------------------------------------

    public static function acknowledge(int $id, int $adminId): void
    {
        Database::run(
            "UPDATE destination_alerts
                SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = NOW()
              WHERE id = ? AND status = 'new'",
            [$adminId, $id]
        );
    }

    public static function resolve(int $id, int $adminId, string $note): void
    {
        Database::run(
            "UPDATE destination_alerts
                SET status = 'resolved', resolved_at = NOW(), resolution_note = ?,
                    acknowledged_by = COALESCE(acknowledged_by, ?),
                    acknowledged_at = COALESCE(acknowledged_at, NOW())
              WHERE id = ?",
            [$note !== '' ? mb_substr($note, 0, 600) : null, $adminId, $id]
        );
    }

    public static function dismiss(int $id, int $adminId, string $note): void
    {
        Database::run(
            "UPDATE destination_alerts
                SET status = 'dismissed', resolved_at = NOW(), resolution_note = ?,
                    acknowledged_by = COALESCE(acknowledged_by, ?),
                    acknowledged_at = COALESCE(acknowledged_at, NOW())
              WHERE id = ?",
            [$note !== '' ? mb_substr($note, 0, 600) : null, $adminId, $id]
        );
    }

    /** The office reclassifying what the parser guessed. */
    public static function reclassify(int $id, string $category, string $severity): void
    {
        if (!isset(self::CATEGORIES[$category]) || !isset(self::SEVERITIES[$severity])) {
            return;
        }

        Database::run(
            'UPDATE destination_alerts SET category = ?, severity = ? WHERE id = ?',
            [$category, $severity, $id]
        );
    }

    /**
     * Tells the office an urgent alert has arrived.
     *
     * WITHOUT THIS, AN ALERT IS A ROW IN A TABLE. It appears in the inbox, and
     * the inbox is a page somebody has to be looking at. A manager reporting a
     * drowning at two in the afternoon needs the officer's phone to ring, not a
     * badge that will be noticed on Monday.
     *
     * URGENT ONLY, and deliberately. Texting the officer about every closure
     * and broken tap drains the balance and — worse — teaches them to ignore
     * the messages, so the one that matters arrives in a stream they have
     * stopped reading.
     *
     * Off until the office puts numbers in Settings. No recipients means no
     * SMS, silently, because a system that texts nobody is better than one that
     * texts a number left over from a demo.
     *
     * Never throws and never blocks: the alert is already saved by the time
     * this runs, and a provider outage must not lose it.
     *
     * @return int how many messages went out
     */
    public static function notifyOffice(int $alertId): int
    {
        $alert = self::find($alertId);

        if ($alert === null) {
            return 0;
        }

        /* How serious it has to be before it becomes a text. A manager writing
           "closed for maintenance" is exactly what this flow carries, so the
           default threshold is 'warning' and not 'urgent'. Texting on 'info'
           too is how a recipient learns to stop reading them. */
        $rank      = ['info' => 0, 'warning' => 1, 'urgent' => 2];
        $threshold = (string) (setting('alert_sms_threshold', 'warning') ?? 'warning');

        if (($rank[$alert['severity']] ?? 0) < ($rank[$threshold] ?? 1)) {
            return 0;
        }

        $where = (string) ($alert['destination_name'] ?: 'an unverified number');
        $label = $alert['severity'] === 'urgent' ? 'URGENT' : ucfirst((string) self::CATEGORIES[$alert['category']]);

        /* Short on purpose. One segment where possible, and the detail is on the
           screen the officer opens next. */
        $body = 'TourSync ' . $label . ' — ' . $where . ': '
            . mb_substr((string) $alert['message'], 0, 88)
            . ' | Reply in Destination Alerts.';

        $sent = 0;

        foreach (self::officeRecipients() as $number) {
            try {
                if (!empty(SmsGateway::send($number, $body)['ok'])) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                /* Never blocks: the alert is already saved, and a provider
                   outage must not lose it. */
                error_log('Alert notification failed: ' . $e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * Whose phone an office notification goes to.
     *
     * Active accounts that carry a number and have not opted out, plus any
     * extra numbers the office typed in Settings — for people who need the
     * alert but hold no account, like the MDRRMO duty officer.
     *
     * Deduplicated: an officer whose number is also in the extras list should
     * get one text, not two.
     *
     * @return array<int, string> normalised E.164 numbers
     */
    public static function officeRecipients(): array
    {
        $numbers = [];

        foreach (Database::all(
            "SELECT mobile_number FROM admins
              WHERE is_active = 1 AND alert_sms_opt_in = 1
                AND mobile_number IS NOT NULL AND mobile_number <> ''"
        ) as $row) {
            $numbers[] = (string) $row['mobile_number'];
        }

        $extra = (string) (setting('alert_sms_recipients', '') ?? '');

        if (trim($extra) !== '') {
            /* COMMAS AND NEWLINES, NOT SPACES.
             *
             * This split on \s as well, so "0917 123 4567" — which is how a
             * Philippine mobile number is written by every person who has ever
             * written one — arrived as "0917", "123", "4567". Each piece failed
             * normalise(), the list came out empty, and the setting silently did
             * nothing at all.
             *
             * normalise() strips whatever is not a digit anyway, so spaces
             * inside one number need no help from here. */
            foreach (preg_split('/[,;
]+/', $extra) ?: [] as $raw) {
                $numbers[] = $raw;
            }
        }

        $out = [];

        foreach ($numbers as $raw) {
            $number = SmsGateway::normalise(trim($raw));

            if ($number !== null) {
                $out[$number] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Writes the office's answer onto the alert.
     *
     * Saved BEFORE the text is attempted, and separately from it. The portal is
     * the record; the SMS is the convenience. If the provider is down, the
     * manager still finds the answer when they next open the system, and the
     * office does not have to remember to type it twice.
     *
     * A reply also acknowledges: an officer who has written a sentence back has
     * plainly read it, and making them press a second button to say so is how
     * an alert sits at "New" with an answer underneath it.
     */
    public static function recordReply(int $id, string $body, int $adminId): void
    {
        Database::run(
            "UPDATE destination_alerts
                SET office_reply = ?, replied_by = ?, replied_at = NOW(),
                    status          = IF(status = 'new', 'acknowledged', status),
                    acknowledged_by = COALESCE(acknowledged_by, ?),
                    acknowledged_at = COALESCE(acknowledged_at, NOW())
              WHERE id = ?",
            [mb_substr(trim($body), 0, 600), $adminId, $adminId, $id]
        );
    }

    /**
     * Texts the manager the office's reply.
     *
     * The other half of the loop the client asked for: the manager reports
     * through the system, the office answers through the system, and each side
     * hears about it on the phone they are actually holding. A reply the
     * manager never sees leaves them assuming nobody read the report.
     *
     * @return array{sent:bool, reason:string}
     */
    public static function notifyManagerOfReply(int $alertId, string $officeMessage): array
    {
        $alert = self::find($alertId);

        if ($alert === null) {
            return ['sent' => false, 'reason' => 'Alert not found.'];
        }

        /* The number they texted from wins over the one on file — it is the
           handset that was actually in their hand at the destination. */
        $number = SmsGateway::normalise((string) (($alert['from_number'] ?: $alert['raised_by_number']) ?: ''));

        if ($number === null) {
            return ['sent' => false, 'reason' => 'No mobile number on record for this manager.'];
        }

        /* An opt-out is honoured even when the office presses send. They can
           still read it in the portal; this only governs the text. */
        if ($alert['raised_by'] !== null) {
            $optIn = Database::scalar(
                'SELECT reply_sms_opt_in FROM destination_managers WHERE id = ?',
                [(int) $alert['raised_by']]
            );

            if ($optIn !== null && (int) $optIn === 0) {
                return ['sent' => false, 'reason' => 'This manager has turned off SMS replies.'];
            }
        }

        $body = 'Tampakan Tourism Office: ' . mb_substr(trim($officeMessage), 0, 200);

        try {
            $result = SmsGateway::send($number, $body);
        } catch (\Throwable $e) {
            error_log('Reply notification failed: ' . $e->getMessage());
            return ['sent' => false, 'reason' => 'The message could not be sent.'];
        }

        if (empty($result['ok'])) {
            return ['sent' => false, 'reason' => (string) ($result['error'] ?? 'The message could not be sent.')];
        }

        Database::run('UPDATE destination_alerts SET reply_sent_at = NOW() WHERE id = ?', [$alertId]);

        return ['sent' => true, 'reason' => ''];
    }


    // -------------------------------------------------------------------------
    // The inbound log
    // -------------------------------------------------------------------------

    public static function logInbound(array $data): void
    {
        try {
            Database::run(
                'INSERT INTO sms_inbox (from_number, body, provider_ref, outcome, alert_id, note, ip_address)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE note = CONCAT(COALESCE(note, \'\'), \' | repeat\')',
                [
                    isset($data['from_number']) ? mb_substr((string) $data['from_number'], 0, 20) : null,
                    isset($data['body']) ? mb_substr((string) $data['body'], 0, 1000) : null,
                    !empty($data['provider_ref']) ? mb_substr((string) $data['provider_ref'], 0, 120) : null,
                    (string) ($data['outcome'] ?? 'rejected'),
                    !empty($data['alert_id']) ? (int) $data['alert_id'] : null,
                    isset($data['note']) ? mb_substr((string) $data['note'], 0, 255) : null,
                    isset($_SERVER['REMOTE_ADDR']) ? @inet_pton($_SERVER['REMOTE_ADDR']) ?: null : null,
                ]
            );
        } catch (\Throwable $e) {
            /* Logging must never break the thing it records. */
            error_log('sms_inbox write failed: ' . $e->getMessage());
        }
    }

    public static function seenProviderRef(string $ref): bool
    {
        return $ref !== ''
            && Database::scalar('SELECT 1 FROM sms_inbox WHERE provider_ref = ?', [$ref]) !== null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function inboundLog(int $limit = 50): array
    {
        return Database::all(
            'SELECT * FROM sms_inbox ORDER BY id DESC LIMIT ' . max(1, min($limit, 200))
        );
    }
}
