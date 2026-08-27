<?php
declare(strict_types=1);

/**
 * TourSync — runs every suite in this folder.
 *
 *     php tests/run.php            all suites
 *     php tests/run.php hero       only suites whose name contains "hero"
 *
 * Each suite runs in its own process, so one fatal error does not take the rest
 * down with it, and so a suite that writes to $_SESSION or $_SERVER cannot leak
 * that into the next one.
 *
 * Suites that need Apache say so and skip cleanly when it is not running, which
 * is why a skip is not a failure here.
 */

$filter = $argv[1] ?? '';

$files = glob(__DIR__ . '/*.php') ?: [];

$files = array_values(array_filter($files, static function (string $f) use ($filter): bool {
    $name = basename($f, '.php');

    if ($name === 'run' || $name === 'bootstrap') {
        return false;
    }

    return $filter === '' || str_contains($name, $filter);
}));

sort($files);

if ($files === []) {
    echo "no suites matched\n";
    exit(1);
}

$php = PHP_BINARY;
$failed = [];
$ran    = 0;

foreach ($files as $file) {
    $name = basename($file, '.php');

    passthru(sprintf('%s %s', escapeshellarg($php), escapeshellarg($file)), $code);

    $ran++;

    if ($code !== 0) {
        $failed[] = $name;
    }
}

echo "\n", str_repeat('=', 62), "\n";

if ($failed === []) {
    printf("  %d suite%s, all passing\n\n", $ran, $ran === 1 ? '' : 's');
    exit(0);
}

printf("  %d of %d suite%s FAILED: %s\n\n", count($failed), $ran,
    $ran === 1 ? '' : 's', implode(', ', $failed));

exit(1);
