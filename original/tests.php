<?php
/* tests.php - runs the automated test cases in the browser. All changes are rolled back. */
require_once __DIR__ . '/includes/test_suite.php';

$run = isset($_GET['run']);
$results = $run ? run_test_suite() : [];
$passed = count(array_filter($results, fn($r) => $r['pass']));

page_header('Test cases');
?>
<section class="card">
  <div class="card-head">
    <h2>Automated test suite</h2>
    <a class="btn" href="tests.php?run=1">Run all <?= count(get_test_cases()) ?> tests</a>
  </div>
  <p>Each test runs inside a database transaction (one SAVEPOINT per test) that is rolled back afterwards,
     so running the tests never changes the library data. The same suite runs from the command line with
     <code>php tests/run_tests.php</code>.</p>
  <?php if ($run): ?>
    <div class="flash <?= $passed === count($results) ? 'success' : 'error' ?>">
      <?= $passed ?> of <?= count($results) ?> tests passed (<?= h(date('d M Y, H:i')) ?>).
    </div>
  <?php endif; ?>
</section>

<?php if ($run): ?>
<section class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>ID</th><th>Module</th><th>Test case</th><th>Input</th><th>Expected result</th><th>Actual result</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($results as $r): ?>
      <tr>
        <td><?= h($r['id']) ?></td>
        <td><?= h($r['module']) ?></td>
        <td><?= h($r['title']) ?></td>
        <td><?= h($r['input']) ?></td>
        <td><?= h($r['expected']) ?></td>
        <td><?= h($r['actual']) ?></td>
        <td><?= status_badge($r['pass'] ? 'Pass' : 'Fail') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php else: ?>
<section class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>ID</th><th>Module</th><th>Test case</th><th>Input</th><th>Expected result</th></tr></thead>
    <tbody>
    <?php foreach (get_test_cases() as $t): ?>
      <tr><td><?= h($t['id']) ?></td><td><?= h($t['module']) ?></td><td><?= h($t['title']) ?></td><td><?= h($t['input']) ?></td><td><?= h($t['expected']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php page_footer();
