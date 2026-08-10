<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Single PDO connection for the whole request.
 *
 * The connection is opened lazily — a page that renders purely static content
 * never pays for a database handshake it does not use.
 *
 * Every query in TourSync goes through this class. No page file builds SQL.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static array $config = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    /** Opens the connection on first use, then reuses it. */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $c = self::$config;
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], $c['port'], $c['name'], $c['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                // Real prepared statements, not client-side interpolation.
                // This is what actually makes parameter binding a defence
                // against SQL injection rather than a convenience.
                PDO::ATTR_EMULATE_PREPARES   => false,

                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // The message can contain credentials — never let it reach a page.
            error_log('TourSync DB connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed.');
        }

        return self::$pdo;
    }

    /** Runs a prepared statement and returns it. */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** First matching row, or null. */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** All matching rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Single scalar value from the first column of the first row. */
    public static function scalar(string $sql, array $params = [])
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** Rows affected by an UPDATE or DELETE. */
    public static function affected(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /**
     * Wraps a callable in a transaction, rolling back on any exception.
     * Used wherever an arrival insert and its summary update must both
     * succeed or both fail.
     */
    public static function transaction(callable $work)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
