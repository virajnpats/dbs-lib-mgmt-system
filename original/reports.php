<?php
/*
 * reports.php - printable management reports built from live SQL.
 * Each report shows its result and, under "Show SQL", the query that produced it.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/reports_list.php';

db()->exec('CALL sp_refresh_statuses()');

$summary = fetch_one("
    SELECT
      (SELECT COUNT(*) FROM LOAN WHERE issue_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01'))   AS issued_month,
      (SELECT COUNT(*) FROM LOAN WHERE return_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01'))  AS returned_month,
      (SELECT COUNT(*) FROM LOAN WHERE return_date IS NULL)                                AS on_loan,
      (SELECT COUNT(*) FROM OVERDUE_LOANS)                                                 AS overdue,
      (SELECT COALESCE(SUM(amount), 0) FROM FINE)                                          AS fines_raised,
      (SELECT COALESCE(SUM(amount_paid), 0) FROM PAYMENT)                                  AS fines_paid");


$results = [];
foreach (REPORTS as $key => $r) {
    try {
        $params = array_map(fn($p) => $p === 'fine_per_day' ? FINE_PER_DAY : null, $r['params'] ?? []);
        $results[$key] = ['rows' => fetch_all($r['sql'], $params), 'error' => ''];
    } catch (Throwable $e) {
        $results[$key] = ['rows' => [], 'error' => db_error_message($e)];
    }
}

page_header('Reports');
?>
<section class="card no-print">
  <div class="card-head">
    <h2>Library reports</h2>
    <div class="actions">
      <?php foreach (REPORTS as $key => $r): ?><a class="btn small secondary" href="#<?= h($key) ?>"><?= h($r['title']) ?></a><?php endforeach; ?>
      <button class="small" onclick="window.print()">Print / save as PDF</button>
    </div>
  </div>
  <p class="muted">Generated <?= h(date('d M Y, H:i')) ?> from the live database. Every figure is a query; nothing is stored or typed in.</p>
</section>

<section class="stats">
  <div class="stat"><small>Loans issued this month</small><strong><?= h($summary['issued_month']) ?></strong></div>
  <div class="stat"><small>Returned this month</small><strong><?= h($summary['returned_month']) ?></strong></div>
  <div class="stat"><small>On loan now</small><strong><?= h($summary['on_loan']) ?></strong></div>
  <div class="stat <?= $summary['overdue'] > 0 ? 'alert' : '' ?>"><small>Overdue now</small><strong><?= h($summary['overdue']) ?></strong></div>
  <div class="stat"><small>Fines raised</small><strong><?= h(money($summary['fines_raised'])) ?></strong></div>
  <div class="stat"><small>Fines collected</small><strong><?= h(money($summary['fines_paid'])) ?></strong></div>
  <div class="stat <?= $summary['fines_raised'] - $summary['fines_paid'] > 0 ? 'alert' : '' ?>"><small>Fines outstanding</small><strong><?= h(money($summary['fines_raised'] - $summary['fines_paid'])) ?></strong></div>
</section>

<?php foreach (REPORTS as $key => $r): $res = $results[$key]; ?>
<section class="card report" id="<?= h($key) ?>">
  <div class="card-head"><h2><?= h($r['title']) ?></h2><a class="no-print muted" href="#top">Back to top</a></div>
  <p class="muted"><?= h($r['about']) ?></p>
  <?php if ($res['error']): ?>
    <div class="flash error"><?= h($res['error']) ?></div>
  <?php else: ?>
    <?= result_table($res['rows']) ?>
  <?php endif; ?>
  <details class="no-print"><summary class="muted">Show SQL</summary><pre class="sql"><?= h($r['sql']) ?></pre></details>
</section>
<?php endforeach; ?>
<?php page_footer();
