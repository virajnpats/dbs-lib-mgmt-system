<?php
/*
 * returns.php - the return desk: find a borrowed book and return it.
 * The Return button posts to loans.php (action=return), which calls the stored
 * procedure sp_return_book, so there is only ONE return implementation.
 */
require_once __DIR__ . '/includes/functions.php';

db()->exec('CALL sp_refresh_statuses()');

$q = trim($_GET['q'] ?? '');
$show = $_GET['show'] ?? 'all';
$where = ['return_date IS NULL'];
$params = [];
if ($q !== '') {
    $where[] = '(member_name LIKE ? OR book_title LIKE ? OR CAST(loan_id AS CHAR) = ? OR CAST(member_id AS CHAR) = ?)';
    array_push($params, "%$q%", "%$q%", $q, $q);
}
if ($show === 'overdue') {
    $where[] = 'due_date < CURDATE()';
}
$open = fetch_all('SELECT *, GREATEST(DATEDIFF(CURDATE(), due_date), 0) AS days_late
                   FROM LOAN_DETAILS WHERE ' . implode(' AND ', $where) . '
                   ORDER BY due_date ASC, loan_id', $params);
$recent = fetch_all('SELECT ld.*, f.amount AS fine_amount
                     FROM LOAN_DETAILS ld LEFT JOIN FINE f ON f.loan_id = ld.loan_id
                     WHERE ld.return_date IS NOT NULL
                     ORDER BY ld.return_date DESC, ld.loan_id DESC LIMIT 8');
$back = 'returns.php?' . http_build_query(['q' => $q, 'show' => $show]);

page_header('Book returns');
?>
<section class="card">
  <div class="card-head">
    <h2>Books currently borrowed</h2>
    <span class="muted">Returning calls sp_return_book: it records the return date, frees the copy and raises a fine of Rs. <?= h(FINE_PER_DAY) ?> per late day.</span>
  </div>
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Member name, book title, loan ID or member ID" autofocus>
    <select name="show">
      <option value="all">All borrowed books</option>
      <option value="overdue" <?= $show === 'overdue' ? 'selected' : '' ?>>Only overdue</option>
    </select>
    <button>Search</button>
    <a class="btn secondary" href="returns.php">Clear</a>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Loan</th><th>Member</th><th>Book (copy)</th><th>Issued</th><th>Due</th><th>Status</th><th class="num">Days late</th><th class="num">Fine if returned today</th><th>Return</th></tr></thead>
    <tbody>
    <?php foreach ($open as $l): ?>
      <tr>
        <td><?= h($l['loan_id']) ?></td>
        <td><a href="member.php?id=<?= h($l['member_id']) ?>"><?= h($l['member_name']) ?></a></td>
        <td><?= h($l['book_title']) ?> <span class="muted">(<?= h($l['copy_no']) ?>)</span></td>
        <td class="nw"><?= h($l['issue_date']) ?></td>
        <td class="nw"><?= h($l['due_date']) ?></td>
        <td><?= status_badge($l['status']) ?></td>
        <td class="num"><?= $l['days_late'] > 0 ? h($l['days_late']) : '—' ?></td>
        <td class="num"><?= $l['days_late'] > 0 ? h(money($l['days_late'] * FINE_PER_DAY)) : 'No fine' ?></td>
        <td>
          <form method="post" action="loans.php" class="inline" onsubmit="return confirm('Return loan #<?= h($l['loan_id']) ?> (<?= h(addslashes($l['book_title'])) ?>)?');">
            <?= csrf_field() ?><input type="hidden" name="action" value="return">
            <input type="hidden" name="loan_id" value="<?= h($l['loan_id']) ?>">
            <input type="hidden" name="back" value="<?= h($back) ?>">
            <input type="date" name="return_date" value="<?= date('Y-m-d') ?>" min="<?= h($l['issue_date']) ?>" max="<?= date('Y-m-d') ?>" required>
            <button class="small">Return</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$open): ?>
      <tr><td colspan="9" class="muted"><?= $q !== '' || $show === 'overdue' ? 'No borrowed books match your search.' : 'No books are out on loan right now.' ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
  <p class="muted"><?= count($open) ?> book(s) out on loan<?= $show === 'overdue' ? ' and overdue' : '' ?>.</p>
</section>

<section class="card">
  <div class="card-head"><h2>Recently returned</h2><a href="loans.php?filter=returned">All returned loans</a></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Loan</th><th>Member</th><th>Book</th><th>Due</th><th>Returned</th><th class="num">Fine</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr><td><?= h($r['loan_id']) ?></td><td><?= h($r['member_name']) ?></td><td><?= h($r['book_title']) ?></td>
          <td class="nw"><?= h($r['due_date']) ?></td><td class="nw"><?= h($r['return_date']) ?></td>
          <td class="num"><?= $r['fine_amount'] !== null ? '<a href="fines.php?q=' . h($r['loan_id']) . '">' . h(money($r['fine_amount'])) . '</a>' : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="6" class="muted">No returns recorded yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php page_footer();
