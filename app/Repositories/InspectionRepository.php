<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\DocumentUploader;

/**
 * =============================================================================
 *  TourSync — Digital Compliance Inspection
 * -----------------------------------------------------------------------------
 *  Photo-based verification of the tourism standards, so an officer travels to
 *  a destination when a photograph genuinely cannot settle the question, and
 *  not to look at a fire extinguisher that is either there or is not.
 *
 *  THREE RULES THIS CLASS ENFORCES
 *
 *  1. A report cannot be submitted with a required standard unanswered.
 *     Checked here rather than in the form, because a submission that arrives
 *     half-complete costs the office a review cycle and the manager a week.
 *
 *  2. Approval of the REPORT is derived, never typed. It happens when every
 *     required item is approved and none is outstanding — so an officer cannot
 *     mark a destination compliant while a requirement sits rejected behind it.
 *
 *  3. 'needs_revision' is a real third answer, not a soft rejection. Rejected
 *     means the standard is not met; needs_revision means the office cannot
 *     tell from what was sent. The manager does something different in each
 *     case — fix the site, or re-photograph it — so the system says which.
 * =============================================================================
 */
final class InspectionRepository
{
    public const STATUSES = [
        'draft'     => 'Draft',
        'submitted' => 'Submitted',
        'reviewing' => 'Under review',
        'approved'  => 'Compliant',
        'rejected'  => 'Not compliant',
    ];

    public const ITEM_STATUSES = [
        'pending'        => 'Not yet answered',
        'submitted'      => 'Awaiting review',
        'approved'       => 'Approved',
        'rejected'       => 'Not met',
        'needs_revision' => 'Needs clearer evidence',
    ];

    /** Statuses a manager may still edit. */
    private const EDITABLE = ['draft', 'rejected'];

    /** How long an approval stands before the office should look again. */
    private const VALID_MONTHS = 12;

    // -------------------------------------------------------------------------
    // Requirements — what the office asks for
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public static function requirements(bool $activeOnly = true): array
    {
        return Database::all(
            'SELECT * FROM inspection_requirements'
            . ($activeOnly ? ' WHERE is_active = 1' : '')
            . ' ORDER BY sort_order ASC, id ASC'
        );
    }

    public static function findRequirement(int $id): ?array
    {
        return Database::first('SELECT * FROM inspection_requirements WHERE id = ?', [$id]);
    }

    public static function saveRequirement(array $data, ?int $id = null, ?int $adminId = null): int
    {
        $fields = [
            trim((string) ($data['title'] ?? '')),
            trim((string) ($data['guidance'] ?? '')) !== '' ? trim((string) $data['guidance']) : null,
            !empty($data['is_required']) ? 1 : 0,
            !empty($data['is_active']) ? 1 : 0,
            (int) ($data['sort_order'] ?? 0),
        ];

        if ($id !== null && $id > 0) {
            Database::run(
                'UPDATE inspection_requirements
                    SET title = ?, guidance = ?, is_required = ?, is_active = ?, sort_order = ?
                  WHERE id = ?',
                array_merge($fields, [$id])
            );

            return $id;
        }

        return Database::insert(
            'INSERT INTO inspection_requirements (title, guidance, is_required, is_active, sort_order, created_by)
             VALUES (?,?,?,?,?,?)',
            array_merge($fields, [$adminId])
        );
    }

    // -------------------------------------------------------------------------
    // Reports
    // -------------------------------------------------------------------------

    public static function find(int $id): ?array
    {
        return Database::first(
            'SELECT r.*, d.name AS destination_name,
                    m.full_name AS submitted_by_name,
                    a.full_name AS reviewed_by_name
               FROM inspection_reports r
               JOIN destinations d ON d.id = r.destination_id
               LEFT JOIN destination_managers m ON m.id = r.submitted_by
               LEFT JOIN admins a ON a.id = r.reviewed_by
              WHERE r.id = ?',
            [$id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function forDestination(int $destinationId, int $limit = 30): array
    {
        return Database::all(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM inspection_items i WHERE i.report_id = r.id) AS item_count,
                    (SELECT COUNT(*) FROM inspection_items i
                      WHERE i.report_id = r.id AND i.status = \'approved\') AS approved_count,
                    (SELECT COUNT(*) FROM inspection_photos p
                       JOIN inspection_items i ON i.id = p.item_id
                      WHERE i.report_id = r.id) AS photo_count
               FROM inspection_reports r
              WHERE r.destination_id = ?
              ORDER BY r.id DESC
              LIMIT ' . max(1, min($limit, 100)),
            [$destinationId]
        );
    }

    /** The office queue. Drafts never appear — they have not been handed over. */
    public static function queue(array $filters = [], int $limit = 200): array
    {
        $clauses = ["r.status <> 'draft'"];
        $params  = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'r.status = ?';
            $params[]  = $filters['status'];
        }

        if (!empty($filters['destination_id'])) {
            $clauses[] = 'r.destination_id = ?';
            $params[]  = (int) $filters['destination_id'];
        }

        return Database::all(
            'SELECT r.*, d.name AS destination_name, m.full_name AS submitted_by_name,
                    (SELECT COUNT(*) FROM inspection_items i WHERE i.report_id = r.id) AS item_count,
                    (SELECT COUNT(*) FROM inspection_items i
                      WHERE i.report_id = r.id AND i.status = \'approved\') AS approved_count,
                    (SELECT COUNT(*) FROM inspection_items i
                      WHERE i.report_id = r.id AND i.status IN (\'submitted\',\'pending\')) AS waiting_count,
                    (SELECT COUNT(*) FROM inspection_photos p
                       JOIN inspection_items i ON i.id = p.item_id
                      WHERE i.report_id = r.id) AS photo_count
               FROM inspection_reports r
               JOIN destinations d ON d.id = r.destination_id
               LEFT JOIN destination_managers m ON m.id = r.submitted_by
              WHERE ' . implode(' AND ', $clauses) . '
              ORDER BY FIELD(r.status, \'submitted\', \'reviewing\', \'rejected\', \'approved\'),
                       r.submitted_at ASC
              LIMIT ' . max(1, min(500, $limit)),
            $params
        );
    }

    public static function counts(?int $destinationId = null): array
    {
        $rows = Database::all(
            'SELECT status, COUNT(*) AS n FROM inspection_reports'
            . ($destinationId !== null ? ' WHERE destination_id = ?' : '')
            . ' GROUP BY status',
            $destinationId !== null ? [$destinationId] : []
        );

        $out = array_fill_keys(array_keys(self::STATUSES), 0);

        foreach ($rows as $row) {
            $out[$row['status']] = (int) $row['n'];
        }

        return $out;
    }

    public static function isEditable(?array $report): bool
    {
        return $report !== null && in_array($report['status'], self::EDITABLE, true);
    }

    /**
     * The destination's open report, creating one if there is none.
     *
     * A manager should not have to think about "starting" an inspection — they
     * open the page because they have a photograph to add. One open report at a
     * time per destination, so evidence cannot be split across two submissions
     * the office then has to reconcile.
     */
    public static function openFor(int $destinationId): array
    {
        /* Everything except approved. A submitted report is still this
           destination's live one — the manager must see it, read-only, while
           the office looks at it.
         *
         * Matching only draft and rejected was a bug: the moment a report was
         * submitted it stopped matching, so the page built a fresh empty draft
         * and the manager lost sight of what they had just sent, along with the
         * office's remarks and the site-visit notice on it. */
        $existing = Database::first(
            "SELECT * FROM inspection_reports
              WHERE destination_id = ? AND status <> 'approved'
              ORDER BY id DESC LIMIT 1",
            [$destinationId]
        );

        if ($existing !== null) {
            /* Only a report the manager can still edit takes on a newly added
               standard. Adding one to a submitted report would change the thing
               the office is in the middle of reviewing. */
            if (in_array($existing['status'], self::EDITABLE, true)) {
                self::syncItems((int) $existing['id']);
            }

            return self::find((int) $existing['id']);
        }

        $id = Database::insert(
            "INSERT INTO inspection_reports (destination_id, status) VALUES (?, 'draft')",
            [$destinationId]
        );

        self::syncItems($id);

        return self::find($id);
    }

    /**
     * Gives the report a row for every active requirement.
     *
     * Called whenever a report is opened, so a requirement the office adds this
     * month appears on a draft started last month rather than being silently
     * missing from it. Existing rows are never touched — INSERT IGNORE against
     * the unique key — so a manager's answers survive.
     */
    public static function syncItems(int $reportId): void
    {
        foreach (self::requirements() as $requirement) {
            Database::run(
                'INSERT IGNORE INTO inspection_items (report_id, requirement_id) VALUES (?, ?)',
                [$reportId, (int) $requirement['id']]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Items
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public static function items(int $reportId): array
    {
        $items = Database::all(
            'SELECT i.*, q.title, q.guidance, q.is_required, q.sort_order,
                    a.full_name AS reviewed_by_name,
                    (SELECT COUNT(*) FROM inspection_photos p WHERE p.item_id = i.id) AS photo_count
               FROM inspection_items i
               JOIN inspection_requirements q ON q.id = i.requirement_id
               LEFT JOIN admins a ON a.id = i.reviewed_by
              WHERE i.report_id = ?
              ORDER BY q.sort_order ASC, q.id ASC',
            [$reportId]
        );

        foreach ($items as &$item) {
            $item['photos'] = self::photos((int) $item['id']);
        }

        return $items;
    }

    public static function findItem(int $itemId, int $reportId): ?array
    {
        return Database::first(
            'SELECT i.*, q.title, q.is_required
               FROM inspection_items i
               JOIN inspection_requirements q ON q.id = i.requirement_id
              WHERE i.id = ? AND i.report_id = ?',
            [$itemId, $reportId]
        );
    }

    /**
     * Saves a manager's remarks and moves the item off 'pending'.
     *
     * An item counts as answered once it has at least one photograph. Remarks
     * alone are not evidence — the whole point is the picture.
     */
    public static function saveItemRemarks(int $itemId, int $reportId, string $remarks): void
    {
        Database::run(
            'UPDATE inspection_items SET remarks = ? WHERE id = ? AND report_id = ?',
            [$remarks !== '' ? mb_substr($remarks, 0, 600) : null, $itemId, $reportId]
        );

        self::refreshItemStatus($itemId);
    }

    /**
     * Recomputes whether an item is answered, from the photographs present.
     *
     * Only ever moves between 'pending' and 'submitted'. An office decision —
     * approved, rejected, needs_revision — is never overwritten by a manager
     * adding a photo; that would let a rejected item quietly look unanswered
     * again and drop out of the office's queue.
     */
    public static function refreshItemStatus(int $itemId): void
    {
        $item = Database::first('SELECT status FROM inspection_items WHERE id = ?', [$itemId]);

        if ($item === null || !in_array($item['status'], ['pending', 'submitted'], true)) {
            return;
        }

        $photos = (int) Database::scalar('SELECT COUNT(*) FROM inspection_photos WHERE item_id = ?', [$itemId]);

        Database::run(
            'UPDATE inspection_items SET status = ? WHERE id = ?',
            [$photos > 0 ? 'submitted' : 'pending', $itemId]
        );
    }

    // -------------------------------------------------------------------------
    // Photos
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public static function photos(int $itemId): array
    {
        return Database::all(
            'SELECT p.*, m.full_name AS uploaded_by_name
               FROM inspection_photos p
               LEFT JOIN destination_managers m ON m.id = p.uploaded_by
              WHERE p.item_id = ?
              ORDER BY p.id ASC',
            [$itemId]
        );
    }

    /**
     * One photo, scoped to its report.
     *
     * The report id is required rather than optional, so a caller has to say
     * which report they believe the photo belongs to and a mismatch returns
     * nothing. Same reasoning as the logbook documents: a find-by-id-alone is
     * the signature that invites an unchecked ?photo= lookup.
     */
    public static function findPhoto(int $photoId, int $reportId): ?array
    {
        return Database::first(
            'SELECT p.*, i.report_id
               FROM inspection_photos p
               JOIN inspection_items i ON i.id = p.item_id
              WHERE p.id = ? AND i.report_id = ?',
            [$photoId, $reportId]
        );
    }

    /** @param array{stored_name:string, original_name:string, mime_type:string, byte_size:int} $stored */
    public static function addPhoto(int $itemId, array $stored, ?int $managerId, string $caption = ''): int
    {
        $id = Database::insert(
            'INSERT INTO inspection_photos
                (item_id, stored_name, original_name, mime_type, byte_size, caption, uploaded_by)
             VALUES (?,?,?,?,?,?,?)',
            [
                $itemId,
                $stored['stored_name'],
                $stored['original_name'],
                $stored['mime_type'],
                $stored['byte_size'],
                $caption !== '' ? mb_substr($caption, 0, 300) : null,
                $managerId,
            ]
        );

        self::refreshItemStatus($itemId);

        return $id;
    }

    public static function removePhoto(int $photoId, int $reportId): bool
    {
        $photo = self::findPhoto($photoId, $reportId);

        if ($photo === null) {
            return false;
        }

        Database::run('DELETE FROM inspection_photos WHERE id = ?', [$photoId]);
        DocumentUploader::delete((string) $photo['stored_name'], 'inspections');

        self::refreshItemStatus((int) $photo['item_id']);

        return true;
    }

    // -------------------------------------------------------------------------
    // The workflow
    // -------------------------------------------------------------------------

    /**
     * Required standards with no photograph behind them.
     *
     * The reason a submission is refused, phrased as the list of things to go
     * and photograph — a manager who is told "incomplete" has to work out what
     * is missing, and a manager who is told "Fire Extinguisher, First Aid Kit"
     * can pick up their phone and walk.
     *
     * @return array<int, string>
     */
    public static function missingRequired(int $reportId): array
    {
        $rows = Database::all(
            "SELECT q.title
               FROM inspection_items i
               JOIN inspection_requirements q ON q.id = i.requirement_id
              WHERE i.report_id = ?
                AND q.is_required = 1
                AND q.is_active = 1
                AND (SELECT COUNT(*) FROM inspection_photos p WHERE p.item_id = i.id) = 0
              ORDER BY q.sort_order ASC",
            [$reportId]
        );

        return array_column($rows, 'title');
    }

    public static function submit(int $reportId, int $managerId): bool
    {
        if (self::missingRequired($reportId) !== []) {
            return false;
        }

        Database::transaction(static function () use ($reportId, $managerId): void {
            Database::run(
                "UPDATE inspection_reports
                    SET status = 'submitted', submitted_by = ?, submitted_at = NOW(),
                        office_remarks = NULL, reviewed_by = NULL, reviewed_at = NULL,
                        site_visit_required = 0, site_visit_at = NULL, site_visit_note = NULL
                  WHERE id = ? AND status IN ('draft','rejected')",
                [$managerId, $reportId]
            );

            /* Anything the office had not decided on becomes 'submitted'. An
               item they already rejected keeps that status, so a resubmission
               still shows what was wrong last time. */
            Database::run(
                "UPDATE inspection_items i
                    SET i.status = 'submitted'
                  WHERE i.report_id = ?
                    AND i.status IN ('pending','needs_revision')
                    AND (SELECT COUNT(*) FROM inspection_photos p WHERE p.item_id = i.id) > 0",
                [$reportId]
            );
        });

        return true;
    }

    public static function markReviewing(int $reportId, int $adminId): void
    {
        Database::run(
            "UPDATE inspection_reports SET status = 'reviewing', reviewed_by = ?
              WHERE id = ? AND status = 'submitted'",
            [$adminId, $reportId]
        );
    }

    /** The office's decision on one requirement. */
    public static function decideItem(int $itemId, int $reportId, string $status, string $comment, int $adminId): bool
    {
        if (!in_array($status, ['approved', 'rejected', 'needs_revision'], true)) {
            return false;
        }

        /* A rejection or a request for clearer evidence without a written
           reason is a message that says only "again" — which is the phone call
           this feature exists to remove. */
        if ($status !== 'approved' && trim($comment) === '') {
            return false;
        }

        Database::run(
            'UPDATE inspection_items
                SET status = ?, office_comment = ?, reviewed_by = ?, reviewed_at = NOW()
              WHERE id = ? AND report_id = ?',
            [$status, trim($comment) !== '' ? mb_substr(trim($comment), 0, 600) : null, $adminId, $itemId, $reportId]
        );

        return true;
    }

    /**
     * What still stands between this report and compliance.
     *
     * @return array{ready:bool, approved:int, required:int, outstanding:array<int,string>}
     */
    public static function readiness(int $reportId): array
    {
        $rows = Database::all(
            'SELECT q.title, q.is_required, i.status
               FROM inspection_items i
               JOIN inspection_requirements q ON q.id = i.requirement_id
              WHERE i.report_id = ? AND q.is_active = 1
              ORDER BY q.sort_order ASC',
            [$reportId]
        );

        $required    = 0;
        $approved    = 0;
        $outstanding = [];

        foreach ($rows as $row) {
            $isRequired = (int) $row['is_required'] === 1;

            if ($isRequired) {
                $required++;
            }

            if ($row['status'] === 'approved') {
                $approved++;
                continue;
            }

            /* An optional standard left unanswered does not block compliance —
               a destination with no restroom cannot photograph a clean one. */
            if (!$isRequired && $row['status'] === 'pending') {
                continue;
            }

            $outstanding[] = (string) $row['title'] . ' — ' . self::ITEM_STATUSES[$row['status']];
        }

        return [
            'ready'       => $outstanding === [],
            'approved'    => $approved,
            'required'    => $required,
            'outstanding' => $outstanding,
        ];
    }

    /**
     * Grants compliance.
     *
     * Refuses unless every requirement is settled. The office can decide each
     * item however they like; what they cannot do is declare a destination
     * compliant while one of its standards sits rejected — that is a
     * certificate that contradicts its own evidence.
     */
    public static function approve(int $reportId, int $adminId, string $remarks = ''): bool
    {
        if (!self::readiness($reportId)['ready']) {
            return false;
        }

        Database::run(
            "UPDATE inspection_reports
                SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(),
                    office_remarks = ?, valid_until = DATE_ADD(CURDATE(), INTERVAL ? MONTH)
              WHERE id = ? AND status IN ('submitted','reviewing')",
            [$adminId, $remarks !== '' ? mb_substr($remarks, 0, 1000) : null, self::VALID_MONTHS, $reportId]
        );

        return true;
    }

    public static function reject(int $reportId, int $adminId, string $remarks): bool
    {
        if (trim($remarks) === '') {
            return false;
        }

        Database::run(
            "UPDATE inspection_reports
                SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), office_remarks = ?
              WHERE id = ? AND status IN ('submitted','reviewing','approved')",
            [$adminId, mb_substr(trim($remarks), 0, 1000), $reportId]
        );

        return true;
    }

    /**
     * Books a physical visit.
     *
     * The honest answer when a photograph cannot settle it — an extinguisher
     * that is present but whose gauge is unreadable, a smell, a structural
     * doubt. Recorded on the report so the manager sees it is coming and why,
     * and so nobody has to remember it was agreed on the phone.
     */
    public static function scheduleSiteVisit(int $reportId, int $adminId, ?string $when, string $note): void
    {
        Database::run(
            'UPDATE inspection_reports
                SET site_visit_required = 1, site_visit_at = ?, site_visit_note = ?, reviewed_by = ?
              WHERE id = ?',
            [
                $when !== null && $when !== '' ? $when : null,
                $note !== '' ? mb_substr($note, 0, 500) : null,
                $adminId,
                $reportId,
            ]
        );
    }

    public static function cancelSiteVisit(int $reportId): void
    {
        Database::run(
            'UPDATE inspection_reports
                SET site_visit_required = 0, site_visit_at = NULL, site_visit_note = NULL
              WHERE id = ?',
            [$reportId]
        );
    }

    /** The destination's current standing, for a dashboard line. */
    public static function currentStanding(int $destinationId): ?array
    {
        return Database::first(
            "SELECT * FROM inspection_reports
              WHERE destination_id = ? AND status = 'approved'
              ORDER BY reviewed_at DESC LIMIT 1",
            [$destinationId]
        );
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
