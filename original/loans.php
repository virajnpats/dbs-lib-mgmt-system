<?php
/*
 * loans.php - issue, return, renew and search loans.
 * Issuing and returning go through stored procedures (sp_issue_book, sp_return_book);
 * the triggers on LOAN enforce the library rules and keep BOOK_COPY.status in step.
 */
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();
    $action = $_POST['action'] ?? '';
    $pdo = db();
    $back = $_POST['back'] ?? 'loans.php';
    if (!preg_match('/^(loans|returns)\.php/', $back)) {
        $back = 'loans.php';
    }

    try {
        if ($action === 'issue') {
            [$d, $errors] = validate_issue($_POST);
            if ($errors) {
                flash_errors($errors);
                remember_form($_POST);
                redirect('loans.php#issue');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('CALL sp_issue_book(?, ?, ?, ?, ?, @new_loan)');
            $stmt->execute([$d['member_id'], $d['book_id'], $d['copy_no'], $d['staff_id'], $d['issue_date']]);
            $stmt->closeCursor();
            $loanId = (int)$pdo->query('SELECT @new_loan')->fetchColumn();
            $pdo->commit();
            $loan = fetch_one('SELECT * FROM LOAN_DETAILS WHERE loan_id = ?', [$loanId]);
            flash('success', "Loan #$loanId issued: \"{$loan['book_title']}\" (copy {$loan['copy_no']}) to {$loan['member_name']}. Due back on {$loan['due_date']}.");
            redirect('loans.php?filter=open');
        }

        if ($action === 'return') {
            [$d, $errors] = validate_return($_POST);
            if ($errors) {
                flash_errors($errors);
                redirect($back);
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('CALL sp_return_book(?, ?, ?, @fine)');
            $stmt->execute([$d['loan_id'], $d['return_date'], FINE_PER_DAY]);
            $stmt->closeCursor();
            $fine = (float)$pdo->query('SELECT @fine')->fetchColumn();
            $pdo->commit();
            $msg = "Loan #{$d['loan_id']} returned on {$d['return_date']}. The copy is available again.";
            if ($fine > 0) {
                $msg .= ' Returned late: fine of ' . money($fine) . ' recorded (Rs. ' . FINE_PER_DAY . ' per day).';
            }
            flash('success', $msg);
            redirect($back);
        }

        if ($action === 'renew') {
            $loanId = (int)($_POST['loan_id'] ?? 0);
            $loan = fetch_one('SELECT l.*, mp.loan_days FROM LOAN l
                               JOIN MEMBER m ON m.member_id = l.member_id
                               JOIN MEMBERSHIP_PLAN mp ON mp.plan_id = m.plan_id
                               WHERE l.loan_id = ?', [$loanId]);
            if (!$loan || $loan['return_date'] !== null) {
                throw new RuntimeException('Only open loans can be renewed.');
            }
            if ($loan['due_date'] < date('Y-m-d')) {
                throw new RuntimeException('Overdue loans cannot be renewed. Return the book and clear the fine first.');
            }
            $waiting = (int)fetch_value("SELECT COUNT(*) FROM RESERVATION
                                         WHERE book_id = ? AND status = 'Pending' AND member_id <> ?",
                                        [$loan['book_id'], $loan['member_id']]);
            if ($waiting > 0) {
                throw new RuntimeException('Cannot renew: another member has a pending reservation for this book.');
            }
            execute('UPDATE LOAN SET due_date = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE loan_id = ?',
                    [$loan['loan_days'], $loanId]);
            $newDue = fetch_value('SELECT due_date FROM LOAN WHERE loan_id = ?', [$loanId]);
            flash('success', "Loan #$loanId renewed. New due date: $newDue.");
            redirect($back);
        }

        if ($action === 'delete') {
            $loanId = (int)($_POST['loan_id'] ?? 0);
            $n = execute('DELETE FROM LOAN WHERE loan_id = ?', [$loanId]);
            flash($n ? 'success' : 'error', $n ? "Loan #$loanId deleted." : 'Loan not found.');
            redirect($back);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', db_error_message($e));
        if ($action === 'issue') {
            remember_form($_POST);
            redirect('loans.php#issue');
        }
        redirect($back);
    }
}

db()->exec('CALL sp_refresh_statuses()');

/* ---------- Data for the issue form ---------- */
$form = old_form();
$preMember = $form['member_id'] ?? ($_GET['member_id'] ?? '');
$members = fetch_all("SELECT member_id,
        CONCAT(member_id, ' - ', full_name, '  (', plan_name, ', holds ', open_loans, '/', max_books,
               IF(outstanding_fines > 0, CONCAT(', fines Rs. ', outstanding_fines), ''), ')') AS label
    FROM MEMBER_SUMMARY WHERE status = 'Active' ORDER BY full_name");
$copies = fetch_all("SELECT CONCAT(bc.book_id, '-', bc.copy_no) AS copy_key,
        CONCAT(b.title, '  [copy ', bc.book_id, '-', bc.copy_no, ' | ', br.branch_name, ' | shelf ', bc.shelf_location, ']') AS label
    FROM BOOK_COPY bc JOIN BOOK b ON b.book_id = bc.book_id JOIN BRANCH br ON br.branch_id = bc.branch_id
    WHERE bc.status = 'Available' ORDER BY b.title, bc.copy_no");
$staff = fetch_all("SELECT staff_id, CONCAT(full_name, ' (', role, ')') AS label FROM STAFF ORDER BY full_name");

/* ---------- Search ---------- */
$filter = $_GET['filter'] ?? 'all';
$q = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($filter === 'open')     { $where[] = 'return_date IS NULL'; }
if ($filter === 'overdue')  { $where[] = "status = 'Overdue'"; }
if ($filter === 'returned') { $where[] = 'return_date IS NOT NULL'; }
if ($q !== '') {
    $where[] = '(member_name LIKE ? OR book_title LIKE ? OR CAST(loan_id AS CHAR) = ? OR CAST(member_id AS CHAR) = ?)';
    array_push($params, "%$q%", "%$q%", $q, $q);
}
$loans = fetch_all('SELECT *, IF(return_date IS NULL AND due_date < CURDATE(), DATEDIFF(CURDATE(), due_date), 0) AS days_late
                    FROM LOAN_DETAILS' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
                   ' ORDER BY (return_date IS NULL) DESC, due_date ASC, loan_id DESC', $params);
$backUrl = 'loans.php?' . http_build_query(['filter' => $filter, 'q' => $q]);

page_header('Loans');
?>
<section class="card" id="issue">
  <div class="card-head"><h2>Issue a book</h2><span class="muted">Due date = issue date + plan loan days. Rules are checked by trigger trg_loan_before_insert.</span></div>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="issue">
    <label class="wide">Member <span class="req">*</span>
      <select name="member_id" required><?= options($members, 'member_id', 'label', $preMember, '— Select active member —') ?></select></label>
    <label class="wide">Available copy <span class="req">*</span>
      <select name="copy" required><?= options($copies, 'copy_key', 'label', $form['copy'] ?? ($_GET['copy'] ?? ''), '— Select an available copy (' . count($copies) . ') —') ?></select></label>
    <label>Issued by <span class="req">*</span>
      <select name="staff_id" required><?= options($staff, 'staff_id', 'label', $form['staff_id'] ?? '', '— Select staff —') ?></select></label>
    <label>Issue date <span class="req">*</span>
      <input type="date" name="issue_date" required max="<?= date('Y-m-d') ?>" value="<?= h($form['issue_date'] ?? date('Y-m-d')) ?>"></label>
    <div class="actions"><button>Issue book</button></div>
  </form>
</section>

<section class="card">
  <div class="tabs">
    <?php foreach (['all' => 'All loans', 'open' => 'Open (not returned)', 'overdue' => 'Overdue', 'returned' => 'Returned'] as $k => $label): ?>
      <a href="loans.php?filter=<?= $k ?>" class="<?= $filter === $k ? 'active' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>
  <form class="filters" method="get">
    <input type="hidden" name="filter" value="<?= h($filter) ?>">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Member, book, loan ID or member ID">
    <button>Search</button>
    <a class="btn secondary" href="loans.php?filter=<?= h($filter) ?>">Clear</a>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Loan</th><th>Member</th><th>Book (copy)</th><th>Issued by</th><th>Issued</th><th>Due</th><th>Returned</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($loans as $l): ?>
      <tr>
        <td><?= h($l['loan_id']) ?></td>
        <td><a href="member.php?id=<?= h($l['member_id']) ?>"><?= h($l['member_name']) ?></a></td>
        <td><?= h($l['book_title']) ?> <span class="muted">(<?= h($l['copy_no']) ?>)</span></td>
        <td><?= h($l['issued_by']) ?></td>
        <td class="nw"><?= h($l['issue_date']) ?></td>
        <td class="nw"><?= h($l['due_date']) ?><?= $l['days_late'] > 0 ? '<br><span class="muted">' . h($l['days_late']) . ' day(s) late</span>' : '' ?></td>
        <td class="nw"><?= h($l['return_date']) ?></td>
        <td><?= status_badge($l['status']) ?></td>
        <td class="actions">
          <?php if ($l['return_date'] === null): ?>
            <form method="post" class="inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="return">
              <input type="hidden" name="loan_id" value="<?= h($l['loan_id']) ?>"><input type="hidden" name="back" value="<?= h($backUrl) ?>">
              <input type="date" name="return_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" min="<?= h($l['issue_date']) ?>" required>
              <button class="small">Return</button>
            </form>
            <?php if ($l['status'] !== 'Overdue'): ?>
            <form method="post" class="inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="renew">
              <input type="hidden" name="loan_id" value="<?= h($l['loan_id']) ?>"><input type="hidden" name="back" value="<?= h($backUrl) ?>">
              <button class="small secondary">Renew</button>
            </form>
            <?php endif; ?>
          <?php endif; ?>
          <form method="post" class="inline" onsubmit="return confirm('Delete loan #<?= h($l['loan_id']) ?>? Use this only to correct a mistake.');">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <input type="hidden" name="loan_id" value="<?= h($l['loan_id']) ?>"><input type="hidden" name="back" value="<?= h($backUrl) ?>">
            <button class="small danger">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$loans): ?><tr><td colspan="9" class="muted">No loans match.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="muted"><?= count($loans) ?> loan(s). Data from view LOAN_DETAILS. Late returns are fined Rs. <?= h(FINE_PER_DAY) ?> per day.</p>
</section>
<?php page_footer();
