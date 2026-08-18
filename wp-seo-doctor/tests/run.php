<?php
/**
 * Runs every suite and reports one verdict.
 *
 * Usage: php tests/run.php
 */

$suites = [
    'fidelity'        => 'harness fidelity audit',
    'smoke'           => 'pure logic, no database',
    'integration'     => 'every SQL statement against a real server',
    'content-filters' => 'links injected by the_content filters',
    'navigation-links' => 'site chrome, orphans and crawl depth',
    'scale'           => 'duplicate detection on a large corpus',
];

$total_pass = 0;
$total_fail = 0;
$failed     = [];

foreach ($suites as $suite => $description) {
    $file = __DIR__ . '/' . $suite . '.php';
    if (!is_file($file)) {
        printf("  ?  %-18s missing\n", $suite);
        continue;
    }

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($file) . ' 2>&1', $output, $status);

    $text = implode("\n", $output);
    $pass = 0;
    $fail = 0;
    if (preg_match('/(\d+) passed, (\d+) failed/', $text, $m)) {
        $pass = (int) $m[1];
        $fail = (int) $m[2];
    }

    $total_pass += $pass;
    $total_fail += $fail;

    $mark = ($status === 0 && $fail === 0) ? '✓' : '✗';
    printf("  %s  %-18s %4d passed  %2d failed   %s\n", $mark, $suite, $pass, $fail, $description);

    if ($status !== 0 || $fail > 0) {
        $failed[$suite] = $text;
    }
}

printf("\n  %d passed, %d failed across %d suites\n", $total_pass, $total_fail, count($suites));

foreach ($failed as $suite => $text) {
    echo "\n── {$suite} ──\n";
    foreach (explode("\n", $text) as $line) {
        if (strpos($line, '✗') !== false || stripos($line, 'fatal') !== false || stripos($line, 'THREW') !== false) {
            echo "  {$line}\n";
        }
    }
}

exit($total_fail > 0 || $failed ? 1 : 0);
