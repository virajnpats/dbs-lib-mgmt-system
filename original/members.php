<?php
/* members.php - MEMBER CRUD + search (uses the MEMBER_SUMMARY view for loans / fines). */
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();
    $action = $_POST['action'] ?? '';
    $memberId = (int)($_POST['member_id'] ?? 0);

    if ($action === 'save') {
        [$d, $errors] = validate_member($_POST);
        $back = 'members.php' . ($memberId ? "?edit=$memberId" : '') . '#form';
        if ($errors) {
            flash_errors($errors);
            remember_form($_POST);
            redirect($back);
        }
        $params = [$d['full_name'], $d['email'], $d['phone'], $d['address'], $d['status'],
                   $d['date_of_birth'], $d['join_date'], $d['plan_id']];
        try {
            if ($memberId === 0) {
                $memberId = next_id('MEMBER', 'member_id', 201);
                execute('INSERT INTO MEMBER (full_name, email, phone, address, status, date_of_birth, join_date, plan_id, member_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($params, [$memberId]));
                flash('success', "Member #$memberId {$d['full_name']} registered.");
            } else {
                execute('UPDATE MEMBER SET full_name = ?, email = ?, phone = ?, address = ?, status = ?,
                                           date_of_birth = ?, join_date = ?, plan_id = ?
                         WHERE member_id = ?', array_merge($params, [$memberId]));
                flash('success', "Member #$memberId updated.");
            }
            redirect('member.php?id=' . $memberId);
        } catch (Throwable $e) {
            flash('error', db_error_message($e));
            remember_form($_POST);
            redirect($back);
        }
    }

    if ($action === 'delete') {
        try {
            $n = execute('DELETE FROM MEMBER WHERE member_id = ?', [$memberId]);
            flash($n ? 'success' : 'error', $n ? "Member #$memberId deleted." : 'Member not found.');
        } catch (Throwable $e) {
            flash('error', db_error_message($e) . ' Tip: set the member to Inactive instead of deleting.');
        }
        redirect('members.php');
    }
}

$plans = fetch_all("SELECT plan_id, CONCAT(plan_name, ' (', max_books, ' books, ', loan_days, ' days)') AS label
                    FROM MEMBERSHIP_PLAN ORDER BY plan_id");

$editId = (int)($_GET['edit'] ?? 0);
$form = old_form();
if (!$form && $editId) {
    $form = fetch_one('SELECT * FROM MEMBER WHERE member_id = ?', [$editId]) ?? [];
    if (!$form) {
        flash('error', 'Member not found.');
        $editId = 0;
    }
}

/* ---------- Search ---------- */
$q      = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$plan   = $_GET['plan'] ?? '';
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(m.full_name LIKE ? OR m.email LIKE ? OR m.phone LIKE ? OR CAST(m.member_id AS CHAR) = ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", $q);
}
if ($status !== '') { $where[] = 'm.status = ?';  $params[] = $status; }
if ($plan !== '')   { $where[] = 'm.plan_id = ?'; $params[] = (int)$plan; }

$members = fetch_all(
    'SELECT m.*, s.plan_name, s.max_books, s.open_loans, s.outstanding_fines,
            TIMESTAMPDIFF(YEAR, m.date_of_birth, CURDATE()) AS age
     FROM MEMBER m JOIN MEMBER_SUMMARY s ON s.member_id = m.member_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY m.full_name', $params);

page_header('Members');
?>
<section class="card" id="form">
  <div class="card-head">
    <h2><?= $editId ? 'Edit member #' . h($editId) : 'Register a new member' ?></h2>
    <?php if ($editId): ?><a href="members.php" class="btn secondary small">Cancel edit</a><?php endif; ?>
  </div>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="member_id" value="<?= h($editId ?: '') ?>">
    <label>Full name <span class="req">*</span>
      <input name="full_name" required minlength="2" maxlength="100" pattern="[A-Za-z][A-Za-z .'\-]*" value="<?= h($form['full_name'] ?? '') ?>"></label>
    <label>Email <span class="req">*</span>
      <input type="email" name="email" required maxlength="100" value="<?= h($form['email'] ?? '') ?>"></label>
    <label>Phone <span class="hint">10 digits</span>
      <input name="phone" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" value="<?= h($form['phone'] ?? '') ?>"></label>
    <label>Address
      <input name="address" maxlength="200" value="<?= h($form['address'] ?? '') ?>"></label>
    <label>Date of birth
      <input type="date" name="date_of_birth" max="<?= date('Y-m-d') ?>" value="<?= h($form['date_of_birth'] ?? '') ?>"></label>
    <label>Join date <span class="req">*</span>
      <input type="date" name="join_date" required max="<?= date('Y-m-d') ?>" value="<?= h($form['join_date'] ?? date('Y-m-d')) ?>"></label>
    <label>Status <span class="req">*</span>
      <select name="status"><?= enum_options(MEMBER_STATUSES, $form['status'] ?? 'Active') ?></select></label>
    <label>Membership plan <span class="req">*</span>
      <select name="plan_id" required><?= options($plans, 'plan_id', 'label', $form['plan_id'] ?? '', '— Select plan —') ?></select></label>
    <div class="actions"><button><?= $editId ? 'Save changes' : 'Register member' ?></button></div>
  </form>
</section>

<section class="card">
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Name, email, phone or member ID">
    <select name="status"><?= enum_options(MEMBER_STATUSES, $status, 'Any status') ?></select>
    <select name="plan"><?= options(fetch_all('SELECT plan_id, plan_name FROM MEMBERSHIP_PLAN'), 'plan_id', 'plan_name', $plan, 'Any plan') ?></select>
    <button>Search</button>
    <a class="btn secondary" href="members.php">Clear</a>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th class="num">Age</th><th>Plan</th><th>Status</th><th class="num">Books held</th><th class="num">Fines due</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($members as $m): ?>
      <tr>
        <td><?= h($m['member_id']) ?></td>
        <td><a href="member.php?id=<?= h($m['member_id']) ?>"><strong><?= h($m['full_name']) ?></strong></a><br><span class="muted"><?= h($m['address']) ?></span></td>
        <td><?= h($m['email']) ?></td>
        <td><?= h($m['phone']) ?></td>
        <td class="num"><?= h($m['age']) ?></td>
        <td><?= h($m['plan_name']) ?></td>
        <td><?= status_badge($m['status']) ?></td>
        <td class="num"><?= h($m['open_loans']) ?> / <?= h($m['max_books']) ?></td>
        <td class="num"><?= $m['outstanding_fines'] > 0 ? '<strong style="color:#9b2525">' . h(money($m['outstanding_fines'])) . '</strong>' : '—' ?></td>
        <td class="actions">
          <a class="btn small secondary" href="member.php?id=<?= h($m['member_id']) ?>">View</a>
          <a class="btn small secondary" href="members.php?edit=<?= h($m['member_id']) ?>#form">Edit</a>
          <form method="post" class="inline" onsubmit="return confirm('Delete this member? Members with loans or reservations cannot be deleted.');">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="member_id" value="<?= h($m['member_id']) ?>">
            <button class="small danger">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$members): ?><tr><td colspan="10" class="muted">No members match your search. <a href="members.php">Show all members</a></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="muted"><?= count($members) ?> member(s) found. Age is a derived attribute calculated with TIMESTAMPDIFF.</p>
</section>
<?php page_footer();
