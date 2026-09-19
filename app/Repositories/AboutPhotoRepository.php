<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Uploader;

/**
 * TourSync — photographs belonging to a block of the public About section.
 *
 * Cultural Heritage moved off the destination pages and onto About, and the
 * office asked to upload several photographs for it rather than the one a
 * settings row can hold. A list of unknown length is a table; a single fixed
 * value stays a settings row, which is why the Municipal Building and the two
 * officials' portraits are not in here.
 *
 * KEYED ON THE BLOCK, not hard-wired to heritage. The About page draws four
 * blocks through one partial and any of them can take a gallery, so the next one
 * that wants photographs needs no second table and no second admin screen.
 *
 * This is the only home of Cultural Heritage now. The per-destination
 * heritage table (destination_heritage, HeritageRepository) was retired on
 * 2026-09-19; its entries were exported before the table was dropped.
 */
final class AboutPhotoRepository
{
    /**
     * Blocks that may hold a gallery, keyed by the id the public page uses.
     * A section outside this list is refused — the value reaches a WHERE clause
     * and an INSERT, and neither should take whatever a form posted.
     *
     * All four take several photographs. They differ only in how the PAGE draws
     * them: Cultural Heritage gets the 2×2 grid, the other three keep a single
     * image with "View all photos" over its corner. That is the office's
     * instruction and it lives in index.php, not here — this class stores
     * photographs and has no opinion about layout.
     *
     * @var array<string, string>
     */
    public const SECTIONS = [
        'history'  => 'A Brief History',
        'tampakan' => 'About Tampakan',
        'office'   => 'About the Tourism Office',
        'heritage' => 'Cultural Heritage',
    ];

    public static function isSection(string $section): bool
    {
        return array_key_exists($section, self::SECTIONS);
    }

    /**
     * Every photograph for a block, in the office's order.
     *
     * Rows pointing at a file that is no longer on disk are DROPPED rather than
     * returned: uploaded_url() would give null and the page would draw an empty
     * frame in the middle of a grid, which reads as a broken layout rather than
     * as a missing picture.
     *
     * @return array<int, array{id:int, url:string, caption:string}>
     */
    public static function published(string $section): array
    {
        $out = [];

        foreach (self::all($section) as $row) {
            $url = uploaded_url((string) $row['file_path']);

            if ($url === null) {
                continue;
            }

            $out[] = [
                'id'      => (int) $row['id'],
                'url'     => $url,
                'caption' => (string) ($row['caption'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Every row for a block, including any whose file has gone. The admin screen
     * needs those — they are what the office deletes to tidy up.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(string $section): array
    {
        if (!self::isSection($section)) {
            return [];
        }

        return Database::all(
            'SELECT * FROM about_photos WHERE section = ? ORDER BY sort_order, id',
            [$section]
        );
    }

    public static function count(string $section): int
    {
        if (!self::isSection($section)) {
            return 0;
        }

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM about_photos WHERE section = ?',
            [$section]
        );
    }

    /**
     * Adds one stored file to the end of a block's gallery.
     *
     * Takes a path that is ALREADY on disk rather than an upload, so the caller
     * decides what to do when a file is rejected — the settings panel reports it
     * and saves nothing else, which it could not do if this method swallowed the
     * failure.
     */
    public static function add(string $section, string $path, string $caption = ''): int
    {
        if (!self::isSection($section)) {
            return 0;
        }

        /* Appended, not inserted at a position. An office uploading three
           photographs expects them in the order they chose them. */
        $next = (int) Database::scalar(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM about_photos WHERE section = ?',
            [$section]
        );

        return Database::insert(
            'INSERT INTO about_photos (section, file_path, caption, sort_order) VALUES (?, ?, ?, ?)',
            [
                $section,
                mb_substr(trim($path), 0, 255),
                trim($caption) === '' ? null : mb_substr(trim($caption), 0, 190),
                $next,
            ]
        );
    }

    /**
     * Removes one photograph, and its file.
     *
     * THE FILE GOES WITH THE ROW. A picture the office has taken off the page
     * still sitting in uploads/ is storage nobody is managing, and on a
     * municipal site it is material somebody removed for a reason.
     *
     * The row is read before it is deleted so the path is known; the file is
     * unlinked after, so a failure to delete the row cannot orphan the image.
     */
    public static function delete(int $id): void
    {
        $path = (string) (Database::scalar(
            'SELECT file_path FROM about_photos WHERE id = ?',
            [$id]
        ) ?? '');

        if ($path === '') {
            return;
        }

        Database::run('DELETE FROM about_photos WHERE id = ?', [$id]);

        Uploader::delete($path);
    }
}
