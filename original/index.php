<?php
/* index.php - Dashboard: live counts, overdue loans and recent activity. */
require_once __DIR__ . '/includes/functions.php';

// Housekeeping stored procedure: marks late loans Overdue and old reservations Expired.
db()->exec('CALL sp_refresh_statuses()');

$stats = fetch_one("
    SELECT
      (SELECT COUNT(*) FROM BOOK)                                   AS titles,
      (SELECT COUNT(*) FROM BOOK_COPY)                              AS copies,
      (SELECT COUNT(*) FROM BOOK_COPY WHERE status = 'Available')   AS available,
      (SELECT COUNT(*) FROM MEMBER WHERE status = 'Active')         AS active_members,
      (SELECT COUNT(*) FROM LOAN WHERE return_date IS NULL)         AS open_loans,
      (SELECT COUNT(*) FROM OVERDUE_LOANS)                          AS overdue,
      (SELECT COUNT(*) FROM RESERVATION WHERE status = 'Pending')   AS pending_res,
      (SELECT COALESCE(SUM(balance), 0) FROM FINE_SUMMARY)          AS fines_due
");

$overdue = fetch_all('SELECT * FROM OVERDUE_LOANS ORDER BY days_overdue DESC');
$recent  = fetch_all('SELECT * FROM LOAN_DETAILS ORDER BY issue_date DESC, loan_id DESC LIMIT 6');
$popular = fetch_all("
    SELECT b.title, COUNT(*) AS times_borrowed
    FROM LOAN l JOIN BOOK b ON b.book_id = l.book_id
    GROUP BY b.book_id, b.title
    ORDER BY times_borrowed DESC, b.title
    LIMIT 5");

page_header('Dashboard');
?>
<section class="stats">
  <a class="stat" href="books.php"><small>Book titles</small><strong><?= h($stats['titles']) ?></strong></a>
  <a class="stat" href="copies.php?status=Available"><small>Copies available</small><strong><?= h($stats['available']) ?> / <?= h($stats['copies']) ?></strong></a>
  <a class="stat" href="members.php?status=Active"><small>Active members</small><strong><?= h($stats['active_members']) ?></strong></a>
  <a class="stat" href="loans.php?filter=open"><small>Open loans</small><strong><?= h($stats['open_loans']) ?></strong></a>
  <a class="stat <?= $stats['overdue'] > 0 ? 'alert' : '' ?>" href="loans.php?filter=overdue"><small>Overdue loans</small><strong><?= h($stats['overdue']) ?></strong></a>
  <a class="stat" href="reservations.php?status=Pending"><small>Pending reservations</small><strong><?= h($stats['pending_res']) ?></strong></a>
  <a class="stat <?= $stats['fines_due'] > 0 ? 'alert' : '' ?>" href="fines.php?status=open"><small>Fines outstanding</small><strong><?= h(money($stats['fines_due'])) ?></strong></a>
</section>

<section class="card">
  <div class="card-head"><h2>Quick actions</h2></div>
  <div class="actions">
    <a class="btn" href="loans.php#issue">Issue a book</a>
    <a class="btn" href="loans.php?filter=open">Return a book</a>
    <a class="btn secondary" href="members.php#form">Add member</a>
    <a class="btn secondary" href="books.php#form">Add book</a>
    <a class="btn secondary" href="reservations.php#form">New reservation</a>
    <a class="btn secondary" href="fines.php#pay">Record fine payment</a>
  </div>
</section>

<section class="card">
  <div class="card-head"><h2>Overdue loans</h2><span class="muted">from view OVERDUE_LOANS</span></div>
  <?php if (!$overdue): ?>
    <p class="muted">No overdue loans.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Loan</th><th>Member</th><th>Phone</th><th>Book</th><th>Copy</th><th>Due date</th><th class="num">Days overdue</th><th class="num">Fine if returned today</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($overdue as $o): ?>
      <tr>
        <td><?= h($o['loan_id']) ?></td>
        <td><a href="member.php?id=<?= h($o['member_id']) ?>"><?= h($o['member_name']) ?></a></td>
        <td><?= h($o['phone']) ?></td>
        <td><?= h($o['book_title']) ?></td>
        <td><?= h($o['copy_no']) ?></td>
        <td class="nw"><?= h($o['due_date']) ?></td>
        <td class="num"><?= h($o['days_overdue']) ?></td>
        <td class="num"><?= h(money($o['days_overdue'] * FINE_PER_DAY)) ?></td>
        <td><a class="btn small secondary" href="loans.php?filter=open&amp;q=<?= h($o['loan_id']) ?>">Return</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<div class="two-col">
  <section class="card">
    <div class="card-head"><h2>Recent loans</h2><a href="loans.php">All loans</a></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Loan</th><th>Member</th><th>Book</th><th>Issued</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr><td><?= h($r['loan_id']) ?></td><td><?= h($r['member_name']) ?></td><td><?= h($r['book_title']) ?></td>
            <td class="nw"><?= h($r['issue_date']) ?></td><td><?= status_badge($r['status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <section class="card">
    <div class="card-head"><h2>Most borrowed titles</h2><a href="queries.php?q=19">See query</a></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Title</th><th class="num">Times borrowed</th></tr></thead>
      <tbody>
      <?php foreach ($popular as $p): ?>
        <tr><td><?= h($p['title']) ?></td><td class="num"><?= h($p['times_borrowed']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
</div>
<?php page_footer();
