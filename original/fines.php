<?php
/*
 * fines.php - FINE and PAYMENT CRUD.
 * FINE.status is never typed in by the user: payment triggers recalculate it
 * (Unpaid / Partially Paid / Paid), and a payment larger than the balance is rejected.
 */
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();
    $action = $_POST['action'] ?? '';
    $pdo = db();
    try {
        if ($action === 'pay') {
            [$d, $errors] = validate_payment($_POST);
            if (!$errors) {
                $balance = fetch_value('SELECT balance FROM FINE_SUMMARY WHERE fine_no = ?', [$d['fine_no']]);
                if ($balance === false) {
                    $errors[] = 'Fine not found.';
                } elseif ((float)$d['amount_paid'] > (float)$balance) {
                    $errors[] = 'Amount paid (' . money($d['amount_paid']) . ') is more than the balance due (' . money($balance) . ').';
                }
            }
            if ($errors) {
                flash_errors($errors);
                remember_form($_POST);
                redirect('fines.php#pay');
            }
            $paymentNo = next_id('PAYMENT', 'payment_no', 701);
            execute('INSERT INTO PAYMENT (payment_no, amount_paid, payment_date, method, fine_no) VALUES (?, ?, ?, ?, ?)',
                    [$paymentNo, $d['amount_paid'], $d['payment_date'], $d['method'], $d['fine_no']]);
            $f = fetch_one('SELECT status, balance FROM FINE_SUMMARY WHERE fine_no = ?', [$d['fine_no']]);
            flash('success', "Payment #$paymentNo of " . money($d['amount_paid']) . " recorded. Fine {$d['fine_no']} is now {$f['status']} (balance " . money($f['balance']) . ').');
            redirect('fines.php');
        }

        if ($action === 'create_fine') {
            [$d, $errors] = validate_fine($_POST, true);
            if ($errors) {
                flash_errors($errors);
                remember_form($_POST);
                redirect('fines.php#fine');
            }
            $fineNo = next_id('FINE', 'fine_no', 601);
            execute("INSERT INTO FINE (fine_no, amount, reason, status, fine_date, loan_id) VALUES (?, ?, ?, 'Unpaid', ?, ?)",
                    [$fineNo, $d['amount'], $d['reason'], $d['fine_date'], $d['loan_id']]);
            flash('success', "Fine #$fineNo of " . money($d['amount']) . " raised against loan {$d['loan_id']}.");
            redirect('fines.php');
        }

        if ($action === 'update_fine') {
            $fineNo = (int)($_POST['fine_no'] ?? 0);
            [$d, $errors] = validate_fine($_POST, false);
            if ($errors) {
                flash_errors($errors);
                redirect("fines.php?edit=$fineNo#fine");
            }
            execute('UPDATE FINE SET amount = ?, reason = ? WHERE fine_no = ?', [$d['amount'], $d['reason'], $fineNo]);
            flash('success', "Fine #$fineNo updated.");
            redirect('fines.php');
        }

        if ($action === 'delete_fine') {
            $fineNo = (int)($_POST['fine_no'] ?? 0);
            $n = execute('DELETE FROM FINE WHERE fine_no = ?', [$fineNo]);
            flash($n ? 'success' : 'error', $n ? "Fine #$fineNo deleted (waived)." : 'Fine not found.');
            redirect('fines.php');
        }

        if ($action === 'delete_payment') {
            $paymentNo = (int)($_POST['payment_no'] ?? 0);
            $n = execute('DELETE FROM PAYMENT WHERE payment_no = ?', [$paymentNo]);
            flash($n ? 'success' : 'error', $n ? "Payment #$paymentNo deleted. The fine status was recalculated." : 'Payment not found.');
            redirect('fines.php');
        }
    } catch (Throwable $e) {
        flash('error', db_error_message($e));
        remember_form($_POST);
        redirect('fines.php');
    }
}

$form = old_form();
$editNo = (int)($_GET['edit'] ?? 0);
$editFine = $editNo ? fetch_one('SELECT * FROM FINE_SUMMARY WHERE fine_no = ?', [$editNo]) : null;

$openFines = fetch_all("SELECT fine_no, CONCAT(fine_no, ' - ', member_name, ' - loan ', loan_id, ' - balance Rs. ', balance) AS label
                        FROM FINE_SUMMARY WHERE balance > 0 ORDER BY fine_no");
$loansWithoutFine = fetch_all("SELECT ld.loan_id, CONCAT(ld.loan_id, ' - ', ld.member_name, ' - ', ld.book_title, ' (', ld.status, ')') AS label
                               FROM LOAN_DETAILS ld
                               WHERE NOT EXISTS (SELECT 1 FROM FINE f WHERE f.loan_id = ld.loan_id)
                               ORDER BY ld.loan_id DESC");

$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($status === 'open') { $where[] = 'balance > 0'; }
elseif ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
if ($q !== '') { $where[] = '(member_name LIKE ? OR CAST(fine_no AS CHAR) = ? OR CAST(loan_id AS CHAR) = ?)'; array_push($params, "%$q%", $q, $q); }
$fines = fetch_all('SELECT * FROM FINE_SUMMARY' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY balance > 0 DESC, fine_no', $params);
$totals = fetch_one('SELECT COALESCE(SUM(amount),0) AS raised, COALESCE(SUM(total_paid),0) AS paid, COALESCE(SUM(balance),0) AS due FROM FINE_SUMMARY');

$payments = fetch_all('SELECT p.*, fs.member_name, fs.loan_id FROM PAYMENT p JOIN FINE_SUMMARY fs ON fs.fine_no = p.fine_no
                       ORDER BY p.payment_date DESC, p.payment_no DESC');

page_header('Fines & Payments');
?>
<section class="stats">
  <div class="stat"><small>Total fines raised</small><strong><?= h(money($totals['raised'])) ?></strong></div>
  <div class="stat"><small>Collected</small><strong><?= h(money($totals['paid'])) ?></strong></div>
  <div class="stat alert"><small>Outstanding</small><strong><?= h(money($totals['due'])) ?></strong></div>
</section>

<div class="two-col">
  <section class="card" id="pay">
    <h2>Record a payment</h2>
    <form method="post" class="form-grid">
      <?= csrf_field() ?><input type="hidden" name="action" value="pay">
      <label class="wide">Fine <span class="req">*</span>
        <select name="fine_no" required><?= options($openFines, 'fine_no', 'label', $form['fine_no'] ?? ($_GET['fine_no'] ?? ''), '— Select fine with a balance —') ?></select></label>
      <label>Amount (Rs.) <span class="req">*</span>
        <input type="number" name="amount_paid" required min="0.01" step="0.01" max="10000" value="<?= h($form['amount_paid'] ?? '') ?>"></label>
      <label>Method <span class="req">*</span>
        <select name="method" required><?= enum_options(PAYMENT_METHODS, $form['method'] ?? 'UPI') ?></select></label>
      <label>Payment date <span class="req">*</span>
        <input type="date" name="payment_date" required max="<?= date('Y-m-d') ?>" value="<?= h($form['payment_date'] ?? date('Y-m-d')) ?>"></label>
      <div class="actions"><button>Record payment</button></div>
    </form>
  </section>

  <section class="card" id="fine">
    <?php if ($editFine): ?>
      <div class="card-head"><h2>Edit fine #<?= h($editNo) ?></h2><a class="btn small secondary" href="fines.php">Cancel</a></div>
      <p class="muted">Member <?= h($editFine['member_name']) ?>, loan <?= h($editFine['loan_id']) ?>. Already paid: <?= h(money($editFine['total_paid'])) ?> (the amount cannot go below this).</p>
      <form method="post" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="action" value="update_fine"><input type="hidden" name="fine_no" value="<?= h($editNo) ?>">
        <label>Amount (Rs.) <span class="req">*</span><input type="number" name="amount" required min="0.01" step="0.01" max="10000" value="<?= h($editFine['amount']) ?>"></label>
        <label class="wide">Reason <span class="req">*</span><input name="reason" required maxlength="200" value="<?= h($editFine['reason']) ?>"></label>
        <div class="actions"><button>Save fine</button></div>
      </form>
    <?php else: ?>
      <h2>Raise a fine manually</h2>
      <p class="muted">Late-return fines are created automatically on return. Use this for lost or damaged books.</p>
      <form method="post" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="action" value="create_fine">
        <label class="wide">Loan <span class="req">*</span>
          <select name="loan_id" required><?= options($loansWithoutFine, 'loan_id', 'label', $form['loan_id'] ?? '', '— Select loan (one fine per loan) —') ?></select></label>
        <label>Amount (Rs.) <span class="req">*</span><input type="number" name="amount" required min="0.01" step="0.01" max="10000" value="<?= h($form['amount'] ?? '') ?>"></label>
        <label>Fine date <span class="req">*</span><input type="date" name="fine_date" required max="<?= date('Y-m-d') ?>" value="<?= h($form['fine_date'] ?? date('Y-m-d')) ?>"></label>
        <label class="wide">Reason <span class="req">*</span><input name="reason" required maxlength="200" placeholder="Damaged book" value="<?= h($form['reason'] ?? '') ?>"></label>
        <div class="actions"><button>Raise fine</button></div>
      </form>
    <?php endif; ?>
  </section>
</div>

<section class="card" style="margin-top:18px">
  <div class="card-head"><h2>Fines</h2><span class="muted">view FINE_SUMMARY: balance = amount - SUM(payments)</span></div>
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Member, fine no. or loan ID">
    <select name="status">
      <option value="">All fines</option>
      <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>With a balance</option>
      <?= enum_options(['Unpaid', 'Partially Paid', 'Paid'], $status) ?>
    </select>
    <button>Search</button><a class="btn secondary" href="fines.php">Clear</a>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Fine</th><th>Member</th><th>Loan</th><th>Date</th><th>Reason</th><th class="num">Amount</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($fines as $f): ?>
      <tr>
        <td><?= h($f['fine_no']) ?></td>
        <td><a href="member.php?id=<?= h($f['member_id']) ?>"><?= h($f['member_name']) ?></a></td>
        <td><?= h($f['loan_id']) ?></td>
        <td class="nw"><?= h($f['fine_date']) ?></td>
        <td><?= h($f['reason']) ?></td>
        <td class="num"><?= h(money($f['amount'])) ?></td>
        <td class="num"><?= h(money($f['total_paid'])) ?></td>
        <td class="num"><strong><?= h(money($f['balance'])) ?></strong></td>
        <td><?= status_badge($f['status']) ?></td>
        <td class="actions">
          <?php if ($f['balance'] > 0): ?><a class="btn small" href="fines.php?fine_no=<?= h($f['fine_no']) ?>#pay">Pay</a><?php endif; ?>
          <a class="btn small secondary" href="fines.php?edit=<?= h($f['fine_no']) ?>#fine">Edit</a>
          <form method="post" class="inline" onsubmit="return confirm('Delete (waive) fine #<?= h($f['fine_no']) ?>? Fines with payments cannot be deleted.');">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_fine"><input type="hidden" name="fine_no" value="<?= h($f['fine_no']) ?>">
            <button class="small danger">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$fines): ?><tr><td colspan="10" class="muted">No fines match.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<section class="card">
  <h2>Payments</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Payment</th><th>Fine</th><th>Member</th><th>Date</th><th>Method</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td><?= h($p['payment_no']) ?></td><td><?= h($p['fine_no']) ?></td><td><?= h($p['member_name']) ?></td>
        <td class="nw"><?= h($p['payment_date']) ?></td><td><?= h($p['method']) ?></td><td class="num"><?= h(money($p['amount_paid'])) ?></td>
        <td><form method="post" class="inline" onsubmit="return confirm('Delete payment #<?= h($p['payment_no']) ?>?');">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete_payment"><input type="hidden" name="payment_no" value="<?= h($p['payment_no']) ?>">
          <button class="small danger">Delete</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php page_footer();
