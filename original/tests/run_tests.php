<?php
/*
 * Command-line test runner:  php tests/run_tests.php
 * Prints every test case with PASS / FAIL and exits with code 1 if any test fails.
 * Add --csv to also write tests/test_results.csv
 */
require_once __DIR__ . '/../includes/test_suite.php';

$results = run_test_suite();
$passed = 0;
foreach ($results as $r) {
    $passed += $r['pass'] ? 1 : 0;
    printf("%s  %-4s  %-17s %s\n        expected: %s\n        actual:   %s\n",
           $r['id'], $r['pass'] ? 'PASS' : 'FAIL', '[' . $r['module'] . ']', $r['title'], $r['expected'], $r['actual']);
}
printf("\n%d of %d tests passed.\n", $passed, count($results));

if (in_array('--csv', $argv, true)) {
    $fh = fopen(__DIR__ . '/test_results.csv', 'w');
    fputcsv($fh, ['ID', 'Module', 'Test case', 'Input', 'Expected', 'Actual', 'Status']);
    foreach ($results as $r) {
        fputcsv($fh, [$r['id'], $r['module'], $r['title'], $r['input'], $r['expected'], $r['actual'], $r['pass'] ? 'Pass' : 'Fail']);
    }
    fclose($fh);
    echo "Results written to tests/test_results.csv\n";
}
exit($passed === count($results) ? 0 : 1);
