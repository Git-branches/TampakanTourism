<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * TourSync — a database dump the office can take themselves.
 *
 * WHAT THIS FILE CONTAINS, stated plainly because the screen that offers it has
 * to say so too: every visitor's name and contact number, every logbook entry,
 * every feedback message, and the officer's password hash. It is the single
 * most sensitive artefact this system can produce. Anyone holding it holds the
 * municipality's tourism records.
 *
 * Which is why it is STREAMED to the browser and never written to disk. A file
 * left under the document root is a file somebody can request by URL, and the
 * one place a backup must not sit is inside the webroot of the machine it is a
 * backup of.
 *
 * Written in PHP rather than shelling out to mysqldump. cPanel hosts routinely
 * disable exec(), and a backup button that works on the developer's laptop and
 * fails silently on the live host is worse than no button — the office would
 * believe they had backups.
 */
final class Backup
{
    /** Rows read and written at a time. 6,988 arrivals will not fit in memory as one string. */
    private const CHUNK = 500;

    /** A filename that sorts chronologically and says what it is. */
    public static function filename(): string
    {
        return 'toursync-backup-' . date('Y-m-d-His') . '.sql';
    }

    /**
     * Writes the whole database to the output buffer as SQL.
     *
     * The caller has already sent the headers. Output is flushed as it goes so
     * a large database starts downloading immediately rather than sitting in
     * memory until the last row is read.
     */
    public static function stream(): void
    {
        $pdo = Database::pdo();

        self::line('-- TourSync database backup');
        self::line('-- Taken ' . date('Y-m-d H:i:s'));
        self::line('--');
        self::line('-- CONTAINS PERSONAL DATA: visitor names, contact numbers, logbook');
        self::line('-- entries, feedback, and account password hashes. Store it the way you');
        self::line('-- would store the paper logbooks it replaced.');
        self::line('');
        self::line('SET NAMES utf8mb4;');
        self::line('SET FOREIGN_KEY_CHECKS = 0;');
        self::line('SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";');
        self::line('');

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            self::table($pdo, (string) $table);
        }

        self::line('SET FOREIGN_KEY_CHECKS = 1;');
        self::line('');
        self::line('-- ' . count($tables) . ' table(s). End of backup.');
    }

    /** One table: its definition, then its rows. */
    private static function table(PDO $pdo, string $table): void
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';

        self::line('');
        self::line('-- ---------------------------------------------------------------------');
        self::line('-- ' . $table);
        self::line('-- ---------------------------------------------------------------------');
        self::line('DROP TABLE IF EXISTS ' . $quoted . ';');

        $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);

        self::line(($create[1] ?? '') . ';');
        self::line('');

        $total = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();

        if ($total === 0) {
            self::line('-- (no rows)');
            return;
        }

        self::line('-- ' . number_format($total) . ' row(s)');

        /* LIMIT/OFFSET rather than one unbuffered cursor: a single query held
           open across a slow download is a connection the rest of the office
           cannot use, and this runs on shared hosting. */
        for ($offset = 0; $offset < $total; $offset += self::CHUNK) {
            $rows = $pdo->query(
                'SELECT * FROM ' . $quoted . ' LIMIT ' . self::CHUNK . ' OFFSET ' . $offset
            )->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                break;
            }

            $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
            $values  = [];

            foreach ($rows as $row) {
                $cells = [];

                foreach ($row as $cell) {
                    $cells[] = $cell === null ? 'NULL' : $pdo->quote((string) $cell);
                }

                $values[] = '(' . implode(',', $cells) . ')';
            }

            self::line('INSERT INTO ' . $quoted . ' (' . $columns . ') VALUES');
            self::line(implode(",\n", $values) . ';');
        }
    }

    /** Emitted and flushed, so the download starts before the dump finishes. */
    private static function line(string $sql): void
    {
        echo $sql, "\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        @flush();
    }
}
