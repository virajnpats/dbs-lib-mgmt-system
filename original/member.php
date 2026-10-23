<?php
/* member.php - one member's full record: profile, loans, reservations and fines. */
require_once __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$m = fetch_one('SELECT m.*, s.plan_name, s.max_books, s.loan_days, s.open_loans, s.outstanding_fines,
                       TIMESTAMPDIFF(YEAR, m.date_of_birth, CURDATE()) AS age
                FROM MEMBER m JOIN MEMBER_SUMMARY s ON s.member_id = m.member_id
                WHERE m.member_id = ?', [$id]);
if (!$m) {
    flash('error', 'Member not found.');
    redirect('members.php');
}

$loans = fetch_all('SELECT * FROM LOAN_DETAILS WHERE member_id = ? ORDER BY issue_date DESC', [$id]);
$reservations = fetch_all('SELECT r.*, b.title FROM RESERVATION r JOIN BOOK b ON b.book_id = r.book_id
                           WHERE r.member_id = ? ORDER BY r.reserved_on DESC', [$id]);
$fines = fetch_all('SELECT * FROM FINE_SUMMARY WHERE member_id = ? ORDER BY fine_date DESC', [$id]);

$canBorrow = $m['status'] === 'Active' && $m['open_loans'] < $m['max_books'] && $m['outstanding_fines'] <= 0;

page_header($m['full_name']);
?>
<div class="two-col">
  <section class="card">
    <div class="card-head"><h2>Profile</h2>
      <a class="btn small secondary" href="members.php?edit=<?= h($id) ?>#form">Edit</a></div>
    <dl class="kv">
      <dt>Member ID</dt><dd><?= h($m['member_id']) ?></dd>
      <dt>Email</dt><dd><?= h($m['email']) ?></dd>
      <dt>Phone</dt><dd><?= h($m['phone']) ?></dd>
      <dt>Address</dt><dd><?= h($m['address']) ?></dd>
      <dt>Date of birth</dt><dd><?= h($m['date_of_birth']) ?> (age <?= h($m['age']) ?>)</dd>
      <dt>Joined</dt><dd><?= h($m['join_date']) ?></dd>
      <dt>Status</dt><dd><?= status_badge($m['status']) ?></dd>
    </dl>
  </section>
  <section class="card">
    <div class="card-head"><h2>Borrowing position</h2></div>
    <dl class="kv">
      <dt>Plan</dt><dd><?= h($m['plan_name']) ?> &middot; up to <?= h($m['max_books']) ?> books for <?= h($m['loan_days']) ?> days</dd>
      <dt>Books held now</dt><dd><?= h($m['open_loans']) ?> of <?= h($m['max_books']) ?></dd>
      <dt>Fines outstanding</dt><dd><?= h(money($m['outstanding_fines'])) ?></dd>
      <dt>Can borrow?</dt><dd><?= $canBorrow ? status_badge('Active') . ' Yes' : status_badge('Unpaid') . ' No — ' .
        h($m['status'] !== 'Active' ? 'member is inactive' : ($m['outstanding_fines'] > 0 ? 'fines must be cleared' : 'borrowing limit reached')) ?></dd>
    </dl>
    <p class="actions" style="margin-top:12px">
      <?php if ($canBorrow): ?><a class="btn" href="loans.php?member_id=<?= h($id) ?>#issue">Issue a book</a><?php endif; ?>
      <?php if ($m['outstanding_fines'] > 0): ?><a class="btn" href="fines.php?q=<?= urlencode($m['full_name']) ?>#pay">Pay fines</a><?php endif; ?>
      <a class="btn secondary" href="reservations.php?member_id=<?= h($id) ?>#form">Reserve a book</a>
    </p>
  </section>
</div>

<section class="card" style="margin-top:18px">
  <h2>Loan history</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Loan</th><th>Book</th><th>Copy</th><th>Issued by</th><th>Issued</th><th>Due</th><th>Returned</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($loans as $l): ?>
      <tr><td><?= h($l['loan_id']) ?></td><td><?= h($l['book_title']) ?></td><td><?= h($l['copy_no']) ?></td><td><?= h($l['issued_by']) ?></td>
          <td class="nw"><?= h($l['issue_date']) ?></td><td class="nw"><?= h($l['due_date']) ?></td><td class="nw"><?= h($l['return_date']) ?></td><td><?= status_badge($l['status']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$loans): ?><tr><td colspan="8" class="muted">No loans yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<div class="two-col">
  <section class="card">
    <h2>Reservations</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>ID</th><th>Book</th><th>Reserved</th><th>Expires</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($reservations as $r): ?>
        <tr><td><?= h($r['reservation_id']) ?></td><td><?= h($r['title']) ?></td><td class="nw"><?= h($r['reserved_on']) ?></td><td class="nw"><?= h($r['expires_on']) ?></td><td><?= status_badge($r['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$reservations): ?><tr><td colspan="5" class="muted">No reservations.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
  <section class="card">
    <h2>Fines</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Fine</th><th>Loan</th><th class="num">Amount</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($fines as $f): ?>
        <tr><td><?= h($f['fine_no']) ?></td><td><?= h($f['loan_id']) ?></td><td class="num"><?= h(money($f['amount'])) ?></td>
            <td class="num"><?= h(money($f['total_paid'])) ?></td><td class="num"><?= h(money($f['balance'])) ?></td><td><?= status_badge($f['status']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$fines): ?><tr><td colspan="6" class="muted">No fines.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
</div>
<?php page_footer();
