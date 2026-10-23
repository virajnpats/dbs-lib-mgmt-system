<?php
/*
 * books.php - BOOK CRUD + search.
 * A book's authors are stored in the M:N junction table BOOK_AUTHOR, so adding,
 * editing or deleting a book changes two tables inside one transaction.
 */
require_once __DIR__ . '/includes/functions.php';

/* ---------------- Handle form submissions (Create / Update / Delete) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_post();
    $action = $_POST['action'] ?? '';
    $bookId = (int)($_POST['book_id'] ?? 0);
    $pdo = db();

    if ($action === 'save') {
        [$d, $errors] = validate_book($_POST);
        if ($errors) {
            flash_errors($errors);
            remember_form($_POST);
            redirect('books.php' . ($bookId ? "?edit=$bookId" : '') . '#form');
        }
        try {
            $pdo->beginTransaction();
            $params = [$d['isbn'], $d['title'], $d['language'], $d['publish_year'], $d['edition'],
                       $d['publisher_id'], $d['category_id']];
            if ($bookId === 0) {                                   // CREATE
                $bookId = next_id('BOOK', 'book_id', 101);
                execute('INSERT INTO BOOK (isbn, title, language, publish_year, edition, publisher_id, category_id, book_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)', array_merge($params, [$bookId]));
                $message = "Book #$bookId \"{$d['title']}\" added. Now add its copies.";
            } else {                                               // UPDATE
                execute('UPDATE BOOK SET isbn = ?, title = ?, language = ?, publish_year = ?, edition = ?,
                                         publisher_id = ?, category_id = ?
                         WHERE book_id = ?', array_merge($params, [$bookId]));
                execute('DELETE FROM BOOK_AUTHOR WHERE book_id = ?', [$bookId]);
                $message = "Book #$bookId updated.";
            }
            $ins = $pdo->prepare('INSERT INTO BOOK_AUTHOR (book_id, author_id) VALUES (?, ?)');
            foreach ($d['authors'] as $authorId) {
                $ins->execute([$bookId, $authorId]);
            }
            $pdo->commit();
            flash('success', $message);
            redirect(strpos($message, 'added') !== false ? "copies.php?book_id=$bookId#form" : 'books.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', db_error_message($e));
            remember_form($_POST);
            redirect('books.php' . ($bookId && ($_POST['book_id'] ?? '') !== '' ? "?edit=$bookId" : '') . '#form');
        }
    }

    if ($action === 'delete') {
        try {
            $pdo->beginTransaction();
            execute('DELETE FROM BOOK_AUTHOR WHERE book_id = ?', [$bookId]);
            $n = execute('DELETE FROM BOOK WHERE book_id = ?', [$bookId]);
            $pdo->commit();
            flash($n ? 'success' : 'error', $n ? "Book #$bookId deleted." : 'Book not found.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('error', db_error_message($e));
        }
        redirect('books.php');
    }
}

/* ---------------- Data for the page ---------------- */
$publishers = fetch_all('SELECT publisher_id, name FROM PUBLISHER ORDER BY name');
$categories = fetch_all('SELECT category_id, category_name FROM CATEGORY ORDER BY category_name');
$authors    = fetch_all('SELECT author_id, full_name FROM AUTHOR ORDER BY full_name');

// Form values: re-show what the user typed after an error, else the record being edited, else blanks.
$editId = (int)($_GET['edit'] ?? 0);
$form = old_form();
if (!$form && $editId) {
    $form = fetch_one('SELECT * FROM BOOK WHERE book_id = ?', [$editId]) ?? [];
    $form['authors'] = array_column(fetch_all('SELECT author_id FROM BOOK_AUTHOR WHERE book_id = ?', [$editId]), 'author_id');
    if (!isset($form['book_id'])) {
        flash('error', 'Book not found.');
        $editId = 0;
    }
}
$formAuthors = array_map('strval', (array)($form['authors'] ?? []));

/* ---------------- Search / retrieval ---------------- */
$q        = trim($_GET['q'] ?? '');
$category = $_GET['category'] ?? '';
$avail    = $_GET['avail'] ?? '';

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(b.title LIKE ? OR b.isbn LIKE ? OR CAST(b.book_id AS CHAR) = ?
                 OR EXISTS (SELECT 1 FROM BOOK_AUTHOR x JOIN AUTHOR a ON a.author_id = x.author_id
                            WHERE x.book_id = b.book_id AND a.full_name LIKE ?))';
    array_push($params, "%$q%", "%$q%", $q, "%$q%");
}
if ($category !== '') {
    $where[] = 'b.category_id = ?';
    $params[] = (int)$category;
}
if ($avail === '1') {
    $where[] = 'ba.available_copies > 0';
}
$sql = "SELECT b.book_id, b.title, b.isbn, b.language, b.publish_year, b.edition,
               p.name AS publisher, c.category_name, ba.total_copies, ba.available_copies,
               (SELECT GROUP_CONCAT(a.full_name ORDER BY a.full_name SEPARATOR ', ')
                  FROM BOOK_AUTHOR x JOIN AUTHOR a ON a.author_id = x.author_id
                 WHERE x.book_id = b.book_id) AS authors
        FROM BOOK b
        LEFT JOIN PUBLISHER p ON p.publisher_id = b.publisher_id
        LEFT JOIN CATEGORY c  ON c.category_id = b.category_id
        JOIN BOOK_AVAILABILITY ba ON ba.book_id = b.book_id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY b.title';
$books = fetch_all($sql, $params);

page_header('Books');
?>
<section class="card" id="form">
  <div class="card-head">
    <h2><?= $editId ? 'Edit book #' . h($editId) : 'Add a new book' ?></h2>
    <?php if ($editId): ?><a href="books.php" class="btn secondary small">Cancel edit</a><?php endif; ?>
  </div>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="book_id" value="<?= h($editId ?: '') ?>">
    <label class="wide">Title <span class="req">*</span>
      <input name="title" required maxlength="200" value="<?= h($form['title'] ?? '') ?>"></label>
    <label>ISBN <span class="hint">10 or 13 digits</span>
      <input name="isbn" maxlength="20" pattern="[0-9Xx\- ]{10,17}" value="<?= h($form['isbn'] ?? '') ?>"></label>
    <label>Language
      <input name="language" maxlength="40" value="<?= h($form['language'] ?? 'English') ?>"></label>
    <label>Publish year
      <input type="number" name="publish_year" min="1000" max="<?= date('Y') + 1 ?>" value="<?= h($form['publish_year'] ?? '') ?>"></label>
    <label>Edition
      <input name="edition" maxlength="30" value="<?= h($form['edition'] ?? '') ?>"></label>
    <label>Publisher
      <select name="publisher_id"><?= options($publishers, 'publisher_id', 'name', $form['publisher_id'] ?? '', '— None —') ?></select></label>
    <label>Category <span class="req">*</span>
      <select name="category_id" required><?= options($categories, 'category_id', 'category_name', $form['category_id'] ?? '', '— Select —') ?></select></label>
    <div class="wide">
      <label>Authors <span class="req">*</span> <span class="hint">(BOOK_AUTHOR, many-to-many) — add new authors on the Catalogue page</span></label>
      <div class="checks">
        <?php foreach ($authors as $a): ?>
          <label><input type="checkbox" name="authors[]" value="<?= h($a['author_id']) ?>"
            <?= in_array((string)$a['author_id'], $formAuthors, true) ? 'checked' : '' ?>> <?= h($a['full_name']) ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="actions"><button type="submit"><?= $editId ? 'Save changes' : 'Add book' ?></button></div>
  </form>
</section>

<section class="card">
  <div class="card-head"><h2>Catalogue search</h2></div>
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Title, ISBN, author or book ID">
    <select name="category"><?= options($categories, 'category_id', 'category_name', $category, 'All categories') ?></select>
    <select name="avail">
      <option value="">All books</option>
      <option value="1" <?= $avail === '1' ? 'selected' : '' ?>>Only with an available copy</option>
    </select>
    <button>Search</button>
    <a class="btn secondary" href="books.php">Clear</a>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>ID</th><th>Title</th><th>Author(s)</th><th>ISBN</th><th>Year</th><th>Category</th><th>Publisher</th><th class="num">Available / copies</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($books as $b): ?>
      <tr>
        <td><?= h($b['book_id']) ?></td>
        <td><strong><?= h($b['title']) ?></strong><br><span class="muted"><?= h($b['edition']) ?> ed. &middot; <?= h($b['language']) ?></span></td>
        <td><?= h($b['authors']) ?></td>
        <td><?= h($b['isbn']) ?></td>
        <td><?= h($b['publish_year']) ?></td>
        <td><?= h($b['category_name']) ?></td>
        <td><?= h($b['publisher']) ?></td>
        <td class="num"><?= $b['available_copies'] > 0 ? '<strong>' . h($b['available_copies']) . '</strong>' : status_badge('None free') ?> / <?= h($b['total_copies']) ?></td>
        <td class="actions">
          <a class="btn small secondary" href="books.php?edit=<?= h($b['book_id']) ?>#form">Edit</a>
          <a class="btn small secondary" href="copies.php?book_id=<?= h($b['book_id']) ?>">Copies</a>
          <form method="post" class="inline" onsubmit="return confirm('Delete this book? Books that still have copies or reservations cannot be deleted.');">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <input type="hidden" name="book_id" value="<?= h($b['book_id']) ?>">
            <button class="small danger">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$books): ?><tr><td colspan="9" class="muted">No books match your search. <a href="books.php">Show all books</a></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="muted"><?= count($books) ?> book(s) found.</p>
</section>
<?php page_footer();
