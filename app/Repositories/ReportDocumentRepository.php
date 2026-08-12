<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\DocumentUploader;

/**
 * =============================================================================
 *  TourSync — supporting logbook documents                           Feature 2
 * -----------------------------------------------------------------------------
 *  The photograph or PDF of the paper page that backs a submission.
 *
 *  Every read here takes the report id, and every caller has already checked
 *  that the report belongs to whoever is asking. There is no method that finds
 *  a document by its own id alone, deliberately: that is the signature that
 *  invites a page to fetch one straight from ?doc= without an ownership check.
 * =============================================================================
 */
final class ReportDocumentRepository
{
    /** @return array<int, array<string, mixed>> */
    public static function forReport(int $reportId): array
    {
        return Database::all(
            'SELECT d.*, m.full_name AS uploaded_by_name
               FROM arrival_report_documents d
               LEFT JOIN destination_managers m ON m.id = d.uploaded_by
              WHERE d.report_id = ?
              ORDER BY d.covers_date IS NULL, d.covers_date ASC, d.id ASC',
            [$reportId]
        );
    }

    /**
     * One document, scoped to its report.
     *
     * The report id is required rather than optional — the caller has to say
     * which report they believe this document belongs to, and a mismatch
     * returns nothing.
     */
    public static function find(int $id, int $reportId): ?array
    {
        return Database::first(
            'SELECT * FROM arrival_report_documents WHERE id = ? AND report_id = ?',
            [$id, $reportId]
        );
    }

    public static function countFor(int $reportId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM arrival_report_documents WHERE report_id = ?',
            [$reportId]
        );
    }

    /**
     * @param array{stored_name:string, original_name:string, mime_type:string, byte_size:int} $stored
     */
    public static function add(
        int $reportId,
        array $stored,
        ?int $managerId,
        ?string $coversDate = null,
        string $caption = ''
    ): int {
        return Database::insert(
            'INSERT INTO arrival_report_documents
                (report_id, stored_name, original_name, mime_type, byte_size, covers_date, caption, uploaded_by)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $reportId,
                $stored['stored_name'],
                $stored['original_name'],
                $stored['mime_type'],
                $stored['byte_size'],
                $coversDate !== null && $coversDate !== '' ? $coversDate : null,
                $caption !== '' ? mb_substr($caption, 0, 200) : null,
                $managerId,
            ]
        );
    }

    /**
     * Removes a document and the file behind it.
     *
     * The row goes first. A file left on disk with no row is wasted bytes; a
     * row left pointing at a deleted file is a broken link in an audit trail,
     * and of the two the second is worse.
     */
    public static function remove(int $id, int $reportId): bool
    {
        $document = self::find($id, $reportId);

        if ($document === null) {
            return false;
        }

        Database::run('DELETE FROM arrival_report_documents WHERE id = ? AND report_id = ?', [$id, $reportId]);
        DocumentUploader::delete((string) $document['stored_name']);

        return true;
    }

    /**
     * How this submission arrived, for the office's list.
     *
     * "Digital + Logbook Photo" is not decoration: an officer deciding whether
     * to open a submission needs to know whether there are records to read, a
     * page to inspect, or both.
     */
    public static function methodLabel(int $entryCount, int $documentCount): string
    {
        if ($entryCount > 0 && $documentCount > 0) {
            return 'Digital + Logbook Photo';
        }

        if ($entryCount > 0) {
            return 'Digital records';
        }

        if ($documentCount > 0) {
            return 'Logbook photo / PDF';
        }

        return 'Empty';
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
