<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — schema migrations
 * -----------------------------------------------------------------------------
 *  Applies structural changes to an existing database without destroying data.
 *  install.php drops and recreates every table; this does not, which is what
 *  makes it safe to run against a system that already holds arrival records.
 *
 *      php database/migrate.php
 *
 *  Each migration is guarded so running the script twice is harmless.
 * =============================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Migrations run from the command line only.');
}

$config = require dirname(__DIR__) . '/app/config/config.php';
$db = $config['database'];

$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}",
    $db['user'], $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "\nTourSync — migrations\n" . str_repeat('=', 60) . "\n";

/** Adds a column only when it is missing. */
$addColumn = static function (PDO $pdo, string $table, string $column, string $definition) use ($db): void {
    $exists = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $exists->execute([$db['name'], $table, $column]);

    if ($exists->fetchColumn()) {
        echo "  skip  {$table}.{$column} — already present\n";
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    echo "  ok    {$table}.{$column} added\n";
};

// -----------------------------------------------------------------------------
// 2026-08 — Track when a password was last changed.
//
// The installer generates the first password and prints it to a terminal.
// Without this column there is no way to tell whether the officer ever changed
// it, and a system in production still using an installer-generated password
// is a finding waiting to happen.
// -----------------------------------------------------------------------------
$addColumn($pdo, 'admins', 'password_changed_at', 'password_changed_at DATETIME NULL AFTER password_hash');

// -----------------------------------------------------------------------------
// 2026-08 — Record when personal fields were anonymised.
//
// RA 10173 asks that personal data not be kept longer than the purpose
// requires. The retention job clears identifying columns while leaving the
// counts intact; this records that it happened so the office can show it did.
// -----------------------------------------------------------------------------
$addColumn($pdo, 'tourist_arrivals', 'anonymised_at', 'anonymised_at DATETIME NULL AFTER created_at');

echo str_repeat('=', 60) . "\n  Migrations complete.\n\n";
