<?php
/*
 * reservations.php - RESERVATION CRUD + search.
 * A reservation is for a title (BOOK), not a specific copy. When the member is
 * later issued any copy of that title, trg_loan_after_insert marks it Fulfilled.
 */
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();
    $action = $_POST['action'] ?? '';
    $resId = (int)($_POST['reservation_id'] ?? 0);

    try {
        if ($action === 'create') {
            [$d, $errors] = validate_reservation($_POST);
            if ($errors) {
                flash_errors($errors);
                remember_form($_POST);
                redirect('reservations.php#form');
            }
            $resId = next_id('RESERVATION', 'reservation_id', 401);
            // expires_on may be NULL: the trigger then sets it to reserved_on + 7 days.
            execute("INSERT INTO RESERVATION (reservation_id, reserved_on, expires_on, status, member_id, book_id)
                     VALUES (?, ?, ?, 'Pending', ?, ?)",
                    [$resId, $d['reserved_on'], $d['expires_on'], $d['member_id'], $d['book_id']]);
            $exp = fetch_value('SELECT expires_on FROM RESERVATION WHERE reservation_id = ?', [$resId]);
            flash('success', "Reservation #$resId created. It is held until $exp.");
            redirect('reservations.php');
        }
        if ($action === 'cancel') {
            $n = execute("UPDATE RESERVATION SET status = 'Cancelled' WHERE reservation_id = ? AND status = 'Pending'", [$resId]);
            flash($n ? 'success' : 'error', $n ? "Reservation #$resId cancelled." : 'Only pending reservations can be cancelled.');
            redirect('reservations.php');
        }
        if ($action === 'extend') {
            $n = execute("UPDATE RESERVATION SET expires_on = DATE_ADD(GREATEST(expires_on, CURDATE()), INTERVAL 7 DAY)
                          WHERE reservation_id = ? AND status = 'Pending'", [$resId]);
            flash($n ? 'success' : 'error', $n ? "Reservation #$resId extended by 7 days." : 'Only pending reservations can be extended.');
            redirect('reservations.php');
        }
        if ($action === 'delete') {
            $n = execute('DELETE FROM RESERVATION WHERE reservation_id = ?', [$resId]);
            flash($n ? 'success' : 'error', $n ? "Reservation #$resId deleted." : 'Reservation not found.');
            redirect('reservations.php');
        }
    } catch (Throwable $e) {
        flash('error', db_error_message($e));
        if ($action === 'create') {
            remember_form($_POST);
        }
        redirect('reservations.php#form');
    }
}

db()->exec('CALL sp_refresh_statuses()');

$form = old_form();
$members = fetch_all("SELECT member_id, CONCAT(member_id, ' - ', full_name) AS label FROM MEMBER WHERE status = 'Active' ORDER BY full_name");
$books = fetch_all("SELECT book_id, CONCAT(title, '  (', available_copies, ' of ', total_copies, ' available)') AS label
                    FROM BOOK_AVAILABILITY ORDER BY title");

$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($status !== '') { $where[] = 'r.status = ?'; $params[] = $status; }
if (!empty($_GET['member_id'])) { $where[] = 'r.member_id = ?'; $params[] = (int)$_GET['member_id']; }
if ($q !== '') {
    $where[] = '(m.full_name LIKE ? OR b.title LIKE ? OR CAST(r.reservation_id AS CHAR) = ?)';
    array_push($params, "%$q%", "%$q%", $q);
}
$rows = fetch_all('SELECT r.*, m.full_name, b.title, ba.available_copies
                   FROM RESERVATION r
                   JOIN MEMBER m ON m.member_id = r.member_id
                   JOIN BOOK b ON b.book_id = r.book_id
                   JOIN BOOK_AVAILABILITY ba ON ba.book_id = r.book_id'
                  . ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
                  " ORDER BY r.status = 'Pending' DESC, r.reserved_on DESC", $params);

page_header('Reservations');
?>
<section class="card" id="form">
  <div class="card-head"><h2>New reservation</h2><span class="muted">Leave expiry empty for the default of 7 days (set by trigger).</span></div>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>Member <span class="req">*</span>
      <select name="member_id" required><?= options($members, 'member_id', 'label', $form['member_id'] ?? ($_GET['member_id'] ?? ''), '— Select active member —') ?></select></label>
    <label class="wide">Book <span class="req">*</span>
      <select name="book_id" required><?= options($books, 'book_id', 'label', $form['book_id'] ?? '', '— Select book —') ?></select></label>
    <label>Reserved on <span class="req">*</span>
      <input type="date" name="reserved_on" required max="<?= date('Y-m-d') ?>" value="<?= h($form['reserved_on'] ?? date('Y-m-d')) ?>"></label>
    <label>Expires on <span class="hint">optional, max 30 days</span>
      <input type="date" name="expires_on" value="<?= h($form['expires_on'] ?? '') ?>"></label>
    <div class="actions"><button>Reserve</button></div>
  </form>
</section>

<section class="card">
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Member, book or reservation ID">
    <select name="status"><?= enum_options(['Pending', 'Fulfilled', 'Cancelled', 'Expired'], $status, 'Any status') ?></select>
    <button>Search</button><a class="btn secondary" href="reservations.php">Clear</a>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>ID</th><th>Member</th><th>Book</th><th>Reserved on</th><th>Expires on</th><th>Status</th><th>Copy free now?</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['reservation_id']) ?></td>
        <td><a href="member.php?id=<?= h($r['member_id']) ?>"><?= h($r['full_name']) ?></a></td>
        <td><?= h($r['title']) ?></td>
        <td class="nw"><?= h($r['reserved_on']) ?></td>
        <td class="nw"><?= h($r['expires_on']) ?></td>
        <td><?= status_badge($r['status']) ?></td>
        <td><?= $r['status'] === 'Pending' ? ($r['available_copies'] > 0 ? 'Yes — ' . h($r['available_copies']) . ' available' : 'No') : '' ?></td>
        <td class="actions">
          <?php if ($r['status'] === 'Pending'): ?>
            <?php if ($r['available_copies'] > 0): ?><a class="btn small" href="loans.php?member_id=<?= h($r['member_id']) ?>#issue">Issue</a><?php endif; ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="extend"><input type="hidden" name="reservation_id" value="<?= h($r['reservation_id']) ?>"><button class="small secondary">+7 days</button></form>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="reservation_id" value="<?= h($r['reservation_id']) ?>"><button class="small secondary">Cancel</button></form>
          <?php endif; ?>
          <form method="post" class="inline" onsubmit="return confirm('Delete reservation #<?= h($r['reservation_id']) ?>?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="reservation_id" value="<?= h($r['reservation_id']) ?>"><button class="small danger">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">No reservations match.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="muted"><?= count($rows) ?> reservation(s). Pending reservations past their expiry are marked Expired by sp_refresh_statuses.</p>
</section>
<?php page_footer();
