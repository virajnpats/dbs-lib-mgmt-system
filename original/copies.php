<?php
/*
 * copies.php - BOOK_COPY CRUD + search.
 * BOOK_COPY is a weak entity: its key is (book_id, copy_no), and copy_no is
 * numbered 1, 2, 3 ... separately for every book.
 */
require_once __DIR__ . '/includes/functions.php';

/** "101-2" -> [101, 2] */
function parse_copy_key(string $key): array
{
    return preg_match('/^(\d+)-(\d+)$/', $key, $m) ? [(int)$m[1], (int)$m[2]] : [0, 0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();
    $action = $_POST['action'] ?? '';
    [$keyBook, $keyCopy] = parse_copy_key($_POST['key'] ?? '');

    if ($action === 'save') {
        [$d, $errors] = validate_copy($_POST);
        $isNew = $keyBook === 0;
        if ($isNew && $d['status'] === 'Borrowed') {
            $errors[] = 'A new copy cannot start as Borrowed. Issue it from the Loans page.';
        }
        $back = 'copies.php?' . ($isNew ? 'book_id=' . (int)($_POST['book_id'] ?? 0) : "edit=$keyBook-$keyCopy") . '#form';
        if ($errors) {
            flash_errors($errors);
            remember_form($_POST);
            redirect($back);
        }
        try {
            if ($isNew) {
                $copyNo = (int)fetch_value('SELECT COALESCE(MAX(copy_no), 0) + 1 FROM BOOK_COPY WHERE book_id = ?', [$d['book_id']]);
                execute('INSERT INTO BOOK_COPY (book_id, copy_no, shelf_location, `condition`, status, price, branch_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$d['book_id'], $copyNo, $d['shelf_location'], $d['condition'], $d['status'], $d['price'], $d['branch_id']]);
                flash('success', "Copy {$d['book_id']}-$copyNo added.");
                redirect('copies.php?book_id=' . $d['book_id']);
            }
            // The book of an existing copy is part of its key and is not changed.
            execute('UPDATE BOOK_COPY SET shelf_location = ?, `condition` = ?, status = ?, price = ?, branch_id = ?
                     WHERE book_id = ? AND copy_no = ?',
                    [$d['shelf_location'], $d['condition'], $d['status'], $d['price'], $d['branch_id'], $keyBook, $keyCopy]);
            flash('success', "Copy $keyBook-$keyCopy updated.");
            redirect('copies.php?book_id=' . $keyBook);
        } catch (Throwable $e) {
            flash('error', db_error_message($e));
            remember_form($_POST);
            redirect($back);
        }
    }

    if ($action === 'delete') {
        try {
            $n = execute('DELETE FROM BOOK_COPY WHERE book_id = ? AND copy_no = ?', [$keyBook, $keyCopy]);
            flash($n ? 'success' : 'error', $n ? "Copy $keyBook-$keyCopy deleted." : 'Copy not found.');
        } catch (Throwable $e) {
            flash('error', db_error_message($e) . ' (A copy with loan history cannot be deleted; mark it Maintenance instead.)');
        }
        redirect('copies.php' . ($keyBook ? "?book_id=$keyBook" : ''));
    }
}

$books    = fetch_all("SELECT book_id, CONCAT(book_id, ' - ', title) AS label FROM BOOK ORDER BY title");
$branches = fetch_all('SELECT branch_id, branch_name FROM BRANCH ORDER BY branch_name');

$editKey = $_GET['edit'] ?? '';
[$editBook, $editCopy] = parse_copy_key($editKey);
$form = old_form();
if (!$form && $editBook) {
    $form = fetch_one('SELECT * FROM BOOK_COPY WHERE book_id = ? AND copy_no = ?', [$editBook, $editCopy]) ?? [];
    if (!$form) {
        flash('error', 'Copy not found.');
        $editBook = 0;
    }
}
$isEdit = $editBook > 0;
$currentStatus = $form['status'] ?? 'Available';

/* ---------- Search ---------- */
$fBook   = $_GET['book_id'] ?? '';
$fBranch = $_GET['branch_id'] ?? '';
$fStatus = $_GET['status'] ?? '';
$q       = trim($_GET['q'] ?? '');
if (!$isEdit && $fBook !== '' && !isset($form['book_id'])) {
    $form['book_id'] = $fBook;                // pre-select book in the add form
}

$where = [];
$params = [];
if ($fBook !== '')   { $where[] = 'bc.book_id = ?';   $params[] = (int)$fBook; }
if ($fBranch !== '') { $where[] = 'bc.branch_id = ?'; $params[] = (int)$fBranch; }
if ($fStatus !== '') { $where[] = 'bc.status = ?';    $params[] = $fStatus; }
if ($q !== '')       { $where[] = '(b.title LIKE ? OR bc.shelf_location LIKE ?)'; array_push($params, "%$q%", "%$q%"); }

$copies = fetch_all(
    "SELECT bc.*, b.title, br.branch_name,
            (SELECT l.loan_id FROM LOAN l WHERE l.book_id = bc.book_id AND l.copy_no = bc.copy_no
              AND l.return_date IS NULL LIMIT 1) AS open_loan,
            (SELECT COUNT(*) FROM LOAN l WHERE l.book_id = bc.book_id AND l.copy_no = bc.copy_no) AS times_loaned
     FROM BOOK_COPY bc
     JOIN BOOK b    ON b.book_id = bc.book_id
     JOIN BRANCH br ON br.branch_id = bc.branch_id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY b.title, bc.copy_no', $params);

page_header('Book copies');
?>
<section class="card" id="form">
  <div class="card-head">
    <h2><?= $isEdit ? 'Edit copy ' . h("$editBook-$editCopy") : 'Add a copy' ?></h2>
    <?php if ($isEdit): ?><a href="copies.php" class="btn secondary small">Cancel edit</a><?php endif; ?>
  </div>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="key" value="<?= $isEdit ? h("$editBook-$editCopy") : '' ?>">
    <label class="wide">Book <span class="req">*</span>
      <?php if ($isEdit): ?>
        <input type="hidden" name="book_id" value="<?= h($editBook) ?>">
        <select disabled><?= options($books, 'book_id', 'label', $editBook) ?></select>
      <?php else: ?>
        <select name="book_id" required><?= options($books, 'book_id', 'label', $form['book_id'] ?? '', '— Select book —') ?></select>
      <?php endif; ?>
    </label>
    <label>Copy number <span class="hint">assigned automatically per book</span>
      <input value="<?= $isEdit ? h($editCopy) : 'next number' ?>" disabled></label>
    <label>Shelf location <span class="req">*</span>
      <input name="shelf_location" required maxlength="50" pattern="[A-Za-z0-9\- ]+" placeholder="A-04" value="<?= h($form['shelf_location'] ?? '') ?>"></label>
    <label>Condition <span class="req">*</span>
      <select name="condition" required><?= enum_options(COPY_CONDITIONS, $form['condition'] ?? 'New') ?></select></label>
    <label>Status <span class="req">*</span>
      <?php if ($currentStatus === 'Borrowed'): ?>
        <input type="hidden" name="status" value="Borrowed"><input value="Borrowed (on loan)" disabled>
      <?php else: ?>
        <select name="status" required><?= enum_options(['Available', 'Reserved', 'Maintenance'], $currentStatus) ?></select>
      <?php endif; ?>
    </label>
    <label>Price (Rs.)
      <input type="number" name="price" min="0" max="100000" step="0.01" value="<?= h($form['price'] ?? '') ?>"></label>
    <label>Branch <span class="req">*</span>
      <select name="branch_id" required><?= options($branches, 'branch_id', 'branch_name', $form['branch_id'] ?? '', '— Select branch —') ?></select></label>
    <div class="actions"><button><?= $isEdit ? 'Save changes' : 'Add copy' ?></button></div>
  </form>
</section>

<section class="card">
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Title or shelf">
    <select name="book_id"><?= options($books, 'book_id', 'label', $fBook, 'All books') ?></select>
    <select name="branch_id"><?= options($branches, 'branch_id', 'branch_name', $fBranch, 'All branches') ?></select>
    <select name="status"><?= enum_options(COPY_STATUSES, $fStatus, 'Any status') ?></select>
    <button>Filter</button>
    <a class="btn secondary" href="copies.php">Clear</a>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Copy</th><th>Title</th><th>Branch</th><th>Shelf</th><th>Condition</th><th>Status</th><th class="num">Price</th><th class="num">Times loaned</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($copies as $c): $key = $c['book_id'] . '-' . $c['copy_no']; ?>
      <tr>
        <td><?= h($key) ?></td>
        <td><?= h($c['title']) ?></td>
        <td><?= h($c['branch_name']) ?></td>
        <td><?= h($c['shelf_location']) ?></td>
        <td><?= status_badge($c['condition']) ?></td>
        <td><?= status_badge($c['status']) ?><?= $c['open_loan'] ? ' <span class="muted">loan ' . h($c['open_loan']) . '</span>' : '' ?></td>
        <td class="num"><?= $c['price'] !== null ? h(money($c['price'])) : '' ?></td>
        <td class="num"><?= h($c['times_loaned']) ?></td>
        <td class="actions">
          <a class="btn small secondary" href="copies.php?edit=<?= h($key) ?>#form">Edit</a>
          <form method="post" class="inline" onsubmit="return confirm('Delete copy <?= h($key) ?>?');">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="key" value="<?= h($key) ?>">
            <button class="small danger">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$copies): ?><tr><td colspan="9" class="muted">No copies match these filters. <a href="copies.php">Show all copies</a></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="muted"><?= count($copies) ?> cop<?= count($copies) === 1 ? 'y' : 'ies' ?>.</p>
</section>
<?php page_footer();
