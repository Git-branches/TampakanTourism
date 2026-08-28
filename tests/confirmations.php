<?php
declare(strict_types=1);

/**
 * Every confirmation dialog asks a question, not a fragment of PHP.
 *
 * WHY THIS EXISTS
 *
 * `data-confirm="&lt;?= … ?&gt;"` is valid HTML, valid PHP, and completely
 * broken: the tags are escaped, so nothing runs and the officer is shown the
 * source of the ternary where the question should be. It cannot be seen in the
 * editor, cannot be seen in view-source, and only appears in the dialog itself.
 *
 * Eight of them were live at once. Two mattered a great deal — one asked an
 * officer to confirm spending real SMS credits, another to confirm permanently
 * clearing personal details from visitor records — and both showed code.
 *
 * This is a source check rather than a browser one. The fault is in the markup,
 * and reading the markup catches it everywhere at once instead of needing a
 * test per screen.
 */

require_once __DIR__ . '/bootstrap.php';

echo "\n=== confirmation dialogs ===\n\n";

$root  = dirname(__DIR__);
$files = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

foreach ($it as $f) {
    if (!$f->isFile()) {
        continue;
    }

    $path = str_replace('\\', '/', $f->getPathname());

    if (!str_ends_with($path, '.php')) {
        continue;
    }

    if (str_contains($path, '/vendor/') || str_contains($path, '/.git/')
        || str_contains($path, '/tests/')) {
        continue;
    }

    $files[] = $path;
}

sort($files);

printf("scanning %d php files\n\n", count($files));

echo "--- no attribute may contain an escaped PHP tag ---\n";

$escaped = [];

foreach ($files as $path) {
    $lines = file($path) ?: [];

    foreach ($lines as $n => $line) {
        /* The comment in accounts.php quotes the broken pattern on purpose,
           to explain it. A comment is not markup. */
        if (preg_match('#^\s*(/\*|\*|//)#', $line)) {
            continue;
        }

        if (str_contains($line, '&lt;?=') || str_contains($line, '?&gt;')) {
            $escaped[] = sprintf('%s:%d', str_replace($root . '/', '', $path), $n + 1);
        }
    }
}

check('files with an escaped PHP tag in markup', $escaped, []);

if ($escaped !== []) {
    foreach ($escaped as $where) {
        printf("      %s\n", $where);
    }
}

echo "\n--- every data-confirm resolves to words, not code ---\n";

$confirms = 0;
$broken   = [];

foreach ($files as $path) {
    $c = (string) file_get_contents($path);

    if (!preg_match_all('/data-confirm="([^"]*)"/s', $c, $m)) {
        continue;
    }

    foreach ($m[1] as $value) {
        $confirms++;

        /* A rendered attribute may legitimately hold `<?= … ?>` — that is PHP
           about to run. What it may never hold is the ESCAPED form, which is
           the literal text the browser will show. */
        if (str_contains($value, '&lt;?') || str_contains($value, '?&gt;')) {
            $broken[] = str_replace($root . '/', '', $path) . ': ' . substr($value, 0, 60);
        }
    }
}

printf("    %d data-confirm attributes found\n", $confirms);

check('none of them show PHP source', $broken, []);

if ($broken !== []) {
    foreach ($broken as $where) {
        printf("      %s\n", $where);
    }
}

echo "\n--- addslashes never guards an HTML attribute ---\n";

/* addslashes escapes for a PHP string literal, not for markup. A surname with
   an apostrophe passed through it lands in an attribute as \' — which is not
   an escape HTML understands, and one nesting level away from breaking out. */
$slashed = [];

foreach ($files as $path) {
    $lines = file($path) ?: [];

    foreach ($lines as $n => $line) {
        if (preg_match('/(data-confirm|title|alt|value|onclick)="[^"]*addslashes/', $line)) {
            $slashed[] = sprintf('%s:%d', str_replace($root . '/', '', $path), $n + 1);
        }
    }
}

check('no attribute is escaped with addslashes', $slashed, []);

if ($slashed !== []) {
    foreach ($slashed as $where) {
        printf("      %s\n", $where);
    }
}

echo "\n--- the confirmations that guard something irreversible say so ---\n";

/* Not a style rule. These four destroy something or spend money, and each was
   found showing PHP source rather than a warning. */
$mustWarn = [
    'admin/announcements/view.php'     => 'SMS credits',
    'admin/settings/retention.php'     => 'personal details',
    'admin/arrival-reports/review.php' => 'tourism records',
    'admin/managers/access.php'        => 'lose access',
];

foreach ($mustWarn as $file => $phrase) {
    $c = (string) @file_get_contents($root . '/' . $file);

    check(sprintf('%-34s warns about "%s"', $file, $phrase),
        $c !== '' && str_contains($c, $phrase), true);
}

test_finish();
