<?php
/*
 * api.php - JSON backend for library.html (the single-page interface).
 *
 * Every request: api.php?action=<name>  (GET to read, POST with a JSON body to change data).
 * Every answer:  {"ok": true, "data": ..., "message": "..."}  or  {"ok": false, "errors": [...]}.
 *
 * It uses exactly the same pieces as the PHP pages: db.php (PDO), the validation rules in
 * includes/validation.php, the stored procedures sp_issue_book / sp_return_book and the
 * triggers in the database. Nothing here trusts the browser: every value is validated again
 * and every SQL value is bound to a ? placeholder.
 */

define('LIBRARY_API', true);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/catalogue_config.php';
require_once __DIR__ . '/includes/queries_list.php';
require_once __DIR__ . '/includes/reports_list.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* ---------- Who may call it ----------
 * library.html is served by the same PHP server (same origin), or opened straight from the
 * Mac's disk (origin "null") while the server runs. Only those origins get CORS access, and
 * every call must carry the X-Requested-With header, which a forged cross-site form cannot send. */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === 'null' || preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function reply($data = null, string $message = ''): void
{
    echo json_encode(['ok' => true, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function refuse(array $errors, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'errors' => array_values($errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log('[Library API] ' . $e);
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    refuse([db_error_message($e)], $e instanceof PDOException ? 200 : 500);
});

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'LibraryApp') {
    refuse(['Requests must come from library.html.'], 403);
}

$action = $_GET['action'] ?? '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in = $isPost ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
if (!is_array($in)) {
    refuse(['The request body is not valid JSON.'], 400);
}

/** int from input, 0 when missing */
function in_int(array $in, string $key): int
{
    return isset($in[$key]) && preg_match('/^\d+$/', (string)$in[$key]) ? (int)$in[$key] : 0;
}

function where_sql(array $where): string
{
    return $where ? ' WHERE ' . implode(' AND ', $where) : '';
}

/* =====================================================================
 * READ ACTIONS (GET)
 * ===================================================================== */
$reads = [

    'ping' => function () {
        return ['database' => fetch_value('SELECT DATABASE()'), 'version' => fetch_value('SELECT VERSION()'),
                'today' => date('Y-m-d'), 'fine_per_day' => FINE_PER_DAY];
    },

    'dashboard' => function () {
        db()->exec('CALL sp_refresh_statuses()');
        return [
            'stats' => fetch_one("SELECT
                (SELECT COUNT(*) FROM BOOK) AS titles,
                (SELECT COUNT(*) FROM BOOK_COPY) AS copies,
                (SELECT COUNT(*) FROM BOOK_COPY WHERE status = 'Available') AS available,
                (SELECT COUNT(*) FROM MEMBER WHERE status = 'Active') AS active_members,
                (SELECT COUNT(*) FROM MEMBER) AS members,
                (SELECT COUNT(*) FROM LOAN WHERE return_date IS NULL) AS open_loans,
                (SELECT COUNT(*) FROM OVERDUE_LOANS) AS overdue,
                (SELECT COUNT(*) FROM RESERVATION WHERE status = 'Pending') AS pending_res,
                (SELECT COALESCE(SUM(balance), 0) FROM FINE_SUMMARY) AS fines_due"),
            'overdue' => fetch_all('SELECT * FROM OVERDUE_LOANS ORDER BY days_overdue DESC'),
            'recent'  => fetch_all('SELECT * FROM LOAN_DETAILS ORDER BY issue_date DESC, loan_id DESC LIMIT 6'),
            'popular' => fetch_all('SELECT b.title, COUNT(*) AS times_borrowed FROM LOAN l JOIN BOOK b ON b.book_id = l.book_id
                                    GROUP BY b.book_id, b.title ORDER BY times_borrowed DESC, b.title LIMIT 5'),
        ];
    },

    /* Lists for drop-downs */
    'lookups' => function () {
        return [
            'publishers' => fetch_all('SELECT publisher_id AS id, name AS label FROM PUBLISHER ORDER BY name'),
            'categories' => fetch_all('SELECT category_id AS id, category_name AS label FROM CATEGORY ORDER BY category_name'),
            'authors'    => fetch_all('SELECT author_id AS id, full_name AS label FROM AUTHOR ORDER BY full_name'),
            'branches'   => fetch_all('SELECT branch_id AS id, branch_name AS label FROM BRANCH ORDER BY branch_name'),
            'staff'      => fetch_all("SELECT staff_id AS id, CONCAT(full_name, ' (', role, ')') AS label FROM STAFF ORDER BY full_name"),
            'plans'      => fetch_all("SELECT plan_id AS id, CONCAT(plan_name, ' (', max_books, ' books, ', loan_days, ' days)') AS label, plan_name FROM MEMBERSHIP_PLAN ORDER BY plan_id"),
            'books'      => fetch_all("SELECT book_id AS id, CONCAT(title, '  (', available_copies, ' of ', total_copies, ' available)') AS label FROM BOOK_AVAILABILITY ORDER BY title"),
            'active_members' => fetch_all("SELECT member_id AS id,
                    CONCAT(member_id, ' - ', full_name, '  (', plan_name, ', holds ', open_loans, '/', max_books,
                           IF(outstanding_fines > 0, CONCAT(', fines Rs. ', outstanding_fines), ''), ')') AS label
                    FROM MEMBER_SUMMARY WHERE status = 'Active' ORDER BY full_name"),
            'available_copies' => fetch_all("SELECT CONCAT(bc.book_id, '-', bc.copy_no) AS id,
                    CONCAT(b.title, '  [copy ', bc.book_id, '-', bc.copy_no, ' | ', br.branch_name, ' | shelf ', bc.shelf_location, ']') AS label
                    FROM BOOK_COPY bc JOIN BOOK b ON b.book_id = bc.book_id JOIN BRANCH br ON br.branch_id = bc.branch_id
                    WHERE bc.status = 'Available' ORDER BY b.title, bc.copy_no"),
            'open_fines' => fetch_all("SELECT fine_no AS id, CONCAT(fine_no, ' - ', member_name, ' - loan ', loan_id, ' - balance Rs. ', balance) AS label, balance
                    FROM FINE_SUMMARY WHERE balance > 0 ORDER BY fine_no"),
            'loans_without_fine' => fetch_all("SELECT ld.loan_id AS id, CONCAT(ld.loan_id, ' - ', ld.member_name, ' - ', ld.book_title, ' (', ld.status, ')') AS label
                    FROM LOAN_DETAILS ld WHERE NOT EXISTS (SELECT 1 FROM FINE f WHERE f.loan_id = ld.loan_id) ORDER BY ld.loan_id DESC"),
            'enums' => ['copy_conditions' => COPY_CONDITIONS, 'copy_statuses' => COPY_STATUSES,
                        'member_statuses' => MEMBER_STATUSES, 'payment_methods' => PAYMENT_METHODS],
            'today' => date('Y-m-d'),
            'fine_per_day' => FINE_PER_DAY,
        ];
    },

    'books' => function ($in) {
        $where = []; $p = [];
        $q = trim((string)($in['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(b.title LIKE ? OR b.isbn LIKE ? OR CAST(b.book_id AS CHAR) = ?
                         OR EXISTS (SELECT 1 FROM BOOK_AUTHOR x JOIN AUTHOR a ON a.author_id = x.author_id
                                    WHERE x.book_id = b.book_id AND a.full_name LIKE ?))';
            array_push($p, "%$q%", "%$q%", $q, "%$q%");
        }
        if (in_int($in, 'category')) { $where[] = 'b.category_id = ?'; $p[] = in_int($in, 'category'); }
        if (($in['avail'] ?? '') === '1') { $where[] = 'ba.available_copies > 0'; }
        return fetch_all("SELECT b.book_id, b.title, b.isbn, b.language, b.publish_year, b.edition, b.publisher_id, b.category_id,
                   p.name AS publisher, c.category_name, ba.total_copies, ba.available_copies,
                   (SELECT GROUP_CONCAT(a.full_name ORDER BY a.full_name SEPARATOR ', ')
                      FROM BOOK_AUTHOR x JOIN AUTHOR a ON a.author_id = x.author_id WHERE x.book_id = b.book_id) AS authors,
                   (SELECT GROUP_CONCAT(x.author_id) FROM BOOK_AUTHOR x WHERE x.book_id = b.book_id) AS author_ids
            FROM BOOK b
            LEFT JOIN PUBLISHER p ON p.publisher_id = b.publisher_id
            LEFT JOIN CATEGORY c ON c.category_id = b.category_id
            JOIN BOOK_AVAILABILITY ba ON ba.book_id = b.book_id" . where_sql($where) . ' ORDER BY b.title', $p);
    },

    'copies' => function ($in) {
        $where = []; $p = [];
        if (in_int($in, 'book_id'))   { $where[] = 'bc.book_id = ?';   $p[] = in_int($in, 'book_id'); }
        if (in_int($in, 'branch_id')) { $where[] = 'bc.branch_id = ?'; $p[] = in_int($in, 'branch_id'); }
        if (in_array($in['status'] ?? '', COPY_STATUSES, true)) { $where[] = 'bc.status = ?'; $p[] = $in['status']; }
        $q = trim((string)($in['q'] ?? ''));
        if ($q !== '') { $where[] = '(b.title LIKE ? OR bc.shelf_location LIKE ?)'; array_push($p, "%$q%", "%$q%"); }
        return fetch_all("SELECT bc.*, b.title, br.branch_name,
                (SELECT l.loan_id FROM LOAN l WHERE l.book_id = bc.book_id AND l.copy_no = bc.copy_no AND l.return_date IS NULL LIMIT 1) AS open_loan,
                (SELECT COUNT(*) FROM LOAN l WHERE l.book_id = bc.book_id AND l.copy_no = bc.copy_no) AS times_loaned
            FROM BOOK_COPY bc JOIN BOOK b ON b.book_id = bc.book_id JOIN BRANCH br ON br.branch_id = bc.branch_id"
            . where_sql($where) . ' ORDER BY b.title, bc.copy_no', $p);
    },

    'members' => function ($in) {
        $where = []; $p = [];
        $q = trim((string)($in['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(m.full_name LIKE ? OR m.email LIKE ? OR m.phone LIKE ? OR CAST(m.member_id AS CHAR) = ?)';
            array_push($p, "%$q%", "%$q%", "%$q%", $q);
        }
        if (in_array($in['status'] ?? '', MEMBER_STATUSES, true)) { $where[] = 'm.status = ?'; $p[] = $in['status']; }
        if (in_int($in, 'plan')) { $where[] = 'm.plan_id = ?'; $p[] = in_int($in, 'plan'); }
        return fetch_all('SELECT m.*, s.plan_name, s.max_books, s.open_loans, s.outstanding_fines,
                                 TIMESTAMPDIFF(YEAR, m.date_of_birth, CURDATE()) AS age
                          FROM MEMBER m JOIN MEMBER_SUMMARY s ON s.member_id = m.member_id'
                         . where_sql($where) . ' ORDER BY m.full_name', $p);
    },

    'member' => function ($in) {
        $id = in_int($in, 'id');
        $m = fetch_one('SELECT m.*, s.plan_name, s.max_books, s.loan_days, s.open_loans, s.outstanding_fines,
                               TIMESTAMPDIFF(YEAR, m.date_of_birth, CURDATE()) AS age
                        FROM MEMBER m JOIN MEMBER_SUMMARY s ON s.member_id = m.member_id WHERE m.member_id = ?', [$id]);
        if (!$m) {
            refuse(['Member not found.']);
        }
        return [
            'member' => $m,
            'can_borrow' => $m['status'] === 'Active' && $m['open_loans'] < $m['max_books'] && $m['outstanding_fines'] <= 0,
            'loans' => fetch_all('SELECT * FROM LOAN_DETAILS WHERE member_id = ? ORDER BY issue_date DESC', [$id]),
            'reservations' => fetch_all('SELECT r.*, b.title FROM RESERVATION r JOIN BOOK b ON b.book_id = r.book_id
                                         WHERE r.member_id = ? ORDER BY r.reserved_on DESC', [$id]),
            'fines' => fetch_all('SELECT * FROM FINE_SUMMARY WHERE member_id = ? ORDER BY fine_date DESC', [$id]),
        ];
    },

    'loans' => function ($in) {
        db()->exec('CALL sp_refresh_statuses()');
        $where = []; $p = [];
        $f = $in['filter'] ?? 'all';
        if ($f === 'open')     { $where[] = 'return_date IS NULL'; }
        if ($f === 'overdue')  { $where[] = 'return_date IS NULL AND due_date < CURDATE()'; }
        if ($f === 'returned') { $where[] = 'return_date IS NOT NULL'; }
        $q = trim((string)($in['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(member_name LIKE ? OR book_title LIKE ? OR CAST(loan_id AS CHAR) = ? OR CAST(member_id AS CHAR) = ?)';
            array_push($p, "%$q%", "%$q%", $q, $q);
        }
        return [
            'loans' => fetch_all('SELECT *, IF(return_date IS NULL AND due_date < CURDATE(), DATEDIFF(CURDATE(), due_date), 0) AS days_late
                                  FROM LOAN_DETAILS' . where_sql($where) . ' ORDER BY (return_date IS NULL) DESC, due_date ASC, loan_id DESC', $p),
            'recent_returns' => fetch_all('SELECT ld.*, f.amount AS fine_amount FROM LOAN_DETAILS ld LEFT JOIN FINE f ON f.loan_id = ld.loan_id
                                           WHERE ld.return_date IS NOT NULL ORDER BY ld.return_date DESC, ld.loan_id DESC LIMIT 8'),
        ];
    },

    'reservations' => function ($in) {
        db()->exec('CALL sp_refresh_statuses()');
        $where = []; $p = [];
        if (in_array($in['status'] ?? '', ['Pending', 'Fulfilled', 'Cancelled', 'Expired'], true)) { $where[] = 'r.status = ?'; $p[] = $in['status']; }
        if (in_int($in, 'member_id')) { $where[] = 'r.member_id = ?'; $p[] = in_int($in, 'member_id'); }
        $q = trim((string)($in['q'] ?? ''));
        if ($q !== '') { $where[] = '(m.full_name LIKE ? OR b.title LIKE ? OR CAST(r.reservation_id AS CHAR) = ?)'; array_push($p, "%$q%", "%$q%", $q); }
        return fetch_all("SELECT r.*, m.full_name, b.title, ba.available_copies
                          FROM RESERVATION r JOIN MEMBER m ON m.member_id = r.member_id
                          JOIN BOOK b ON b.book_id = r.book_id JOIN BOOK_AVAILABILITY ba ON ba.book_id = r.book_id"
                         . where_sql($where) . " ORDER BY r.status = 'Pending' DESC, r.reserved_on DESC", $p);
    },

    'fines' => function ($in) {
        $where = []; $p = [];
        $st = $in['status'] ?? '';
        if ($st === 'open') { $where[] = 'balance > 0'; }
        elseif (in_array($st, ['Unpaid', 'Partially Paid', 'Paid'], true)) { $where[] = 'status = ?'; $p[] = $st; }
        $q = trim((string)($in['q'] ?? ''));
        if ($q !== '') { $where[] = '(member_name LIKE ? OR CAST(fine_no AS CHAR) = ? OR CAST(loan_id AS CHAR) = ?)'; array_push($p, "%$q%", $q, $q); }
        return [
            'totals' => fetch_one('SELECT COALESCE(SUM(amount),0) AS raised, COALESCE(SUM(total_paid),0) AS paid, COALESCE(SUM(balance),0) AS due FROM FINE_SUMMARY'),
            'fines' => fetch_all('SELECT * FROM FINE_SUMMARY' . where_sql($where) . ' ORDER BY balance > 0 DESC, fine_no', $p),
            'payments' => fetch_all('SELECT p.*, fs.member_name, fs.loan_id FROM PAYMENT p JOIN FINE_SUMMARY fs ON fs.fine_no = p.fine_no
                                     ORDER BY p.payment_date DESC, p.payment_no DESC'),
        ];
    },

    'catalogue' => function ($in) {
        $tab = $in['t'] ?? 'authors';
        if (!isset(CATALOGUE[$tab])) {
            $tab = 'authors';
        }
        $cfg = CATALOGUE[$tab];
        $cols = array_keys($cfg['fields']);
        $quoted = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $fk = [];
        foreach ($cfg['fields'] as $name => $f) {
            if ($f['type'] === 'fk') {
                $fk[$name] = fetch_all($f['sql']);
            }
        }
        $sql = "SELECT {$cfg['pk']} AS id, $quoted FROM {$cfg['table']}";
        $p = [];
        $q = trim((string)($in['q'] ?? ''));
        $search = array_filter($cols, fn($c) => in_array($cfg['fields'][$c]['type'], ['text', 'name', 'email', 'enum'], true));
        if ($q !== '' && $search) {
            $sql .= ' WHERE ' . implode(' OR ', array_map(fn($c) => "`$c` LIKE ?", $search));
            $p = array_fill(0, count($search), "%$q%");
        }
        $fields = [];
        foreach ($cfg['fields'] as $name => $f) {
            unset($f['sql']);
            $fields[$name] = $f;
        }
        return [
            'tabs' => array_map(fn($c) => ['title' => $c['title'], 'table' => $c['table']], CATALOGUE),
            'tab' => $tab, 'title' => $cfg['title'], 'table' => $cfg['table'],
            'fields' => $fields, 'fk' => $fk,
            'rows' => fetch_all($sql . " ORDER BY {$cfg['order']}", $p),
        ];
    },

    'reports' => function () {
        db()->exec('CALL sp_refresh_statuses()');
        $out = [];
        foreach (REPORTS as $key => $r) {
            $params = array_map(fn($x) => $x === 'fine_per_day' ? FINE_PER_DAY : null, $r['params'] ?? []);
            try {
                $out[] = ['key' => $key, 'title' => $r['title'], 'about' => $r['about'], 'sql' => $r['sql'],
                          'rows' => fetch_all($r['sql'], $params), 'error' => ''];
            } catch (Throwable $e) {
                $out[] = ['key' => $key, 'title' => $r['title'], 'about' => $r['about'], 'sql' => $r['sql'],
                          'rows' => [], 'error' => db_error_message($e)];
            }
        }
        return [
            'summary' => fetch_one("SELECT
                (SELECT COUNT(*) FROM LOAN WHERE issue_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS issued_month,
                (SELECT COUNT(*) FROM LOAN WHERE return_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS returned_month,
                (SELECT COUNT(*) FROM LOAN WHERE return_date IS NULL) AS on_loan,
                (SELECT COUNT(*) FROM OVERDUE_LOANS) AS overdue,
                (SELECT COALESCE(SUM(amount), 0) FROM FINE) AS fines_raised,
                (SELECT COALESCE(SUM(amount_paid), 0) FROM PAYMENT) AS fines_paid"),
            'reports' => $out,
            'generated' => date('d M Y, H:i'),
        ];
    },

    'queries' => function () {
        $list = [];
        foreach (QUERY_LIST as $n => $q) {
            $params = [];
            foreach ($q['params'] ?? [] as $p) {
                if ($p['type'] === 'select') {
                    $p['options'] = fetch_all($p['sql']);
                }
                unset($p['sql']);
                $params[] = $p;
            }
            $list[] = ['id' => $n, 'group' => $q['group'], 'title' => $q['title'], 'concept' => $q['concept'],
                       'explain' => $q['explain'], 'sql' => $q['sql'], 'params' => $params];
        }
        return $list;
    },

    'query_run' => function ($in) {
        $id = in_int($in, 'id');
        if (!isset(QUERY_LIST[$id])) {
            refuse(['Unknown query.']);
        }
        $q = QUERY_LIST[$id];
        $bound = [];
        $errors = [];
        foreach ($q['params'] ?? [] as $p) {
            $raw = trim((string)($in[$p['name']] ?? $p['default']));
            if ($p['type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) { $errors[] = $p['label'] . ' must be a date.'; }
            if ($p['type'] === 'number' && !preg_match('/^\d{1,4}$/', $raw)) { $errors[] = $p['label'] . ' must be a year (whole number).'; }
            if ($p['type'] === 'select' && !preg_match('/^\d+$/', $raw)) { $errors[] = 'Select a ' . strtolower($p['label']) . '.'; }
            $bound[] = $raw;
        }
        if ($errors) {
            refuse($errors);
        }
        $sql = rtrim($q['sql'], ';');
        return ['rows' => $bound ? fetch_all($sql, $bound) : db()->query($sql)->fetchAll(), 'bound' => $bound];
    },

    'tests_run' => function () {
        require_once __DIR__ . '/includes/test_suite.php';
        $results = run_test_suite();
        return ['results' => $results, 'passed' => count(array_filter($results, fn($r) => $r['pass'])),
                'total' => count($results), 'at' => date('d M Y, H:i')];
    },
];

/* =====================================================================
 * WRITE ACTIONS (POST)
 * ===================================================================== */
$writes = [

    /* ---------- Books (BOOK + BOOK_AUTHOR in one transaction) ---------- */
    'book_save' => function ($in) {
        [$d, $errors] = validate_book($in);
        if ($errors) { refuse($errors); }
        $id = in_int($in, 'book_id');
        $pdo = db();
        $pdo->beginTransaction();
        $vals = [$d['isbn'], $d['title'], $d['language'], $d['publish_year'], $d['edition'], $d['publisher_id'], $d['category_id']];
        if ($id === 0) {
            $id = next_id('BOOK', 'book_id', 101);
            execute('INSERT INTO BOOK (isbn, title, language, publish_year, edition, publisher_id, category_id, book_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)', array_merge($vals, [$id]));
            $msg = "Book #$id \"{$d['title']}\" added. Now add its copies.";
        } else {
            if (!fetch_value('SELECT COUNT(*) FROM BOOK WHERE book_id = ?', [$id])) { $pdo->rollBack(); refuse(['Book not found.']); }
            execute('UPDATE BOOK SET isbn = ?, title = ?, language = ?, publish_year = ?, edition = ?, publisher_id = ?, category_id = ?
                     WHERE book_id = ?', array_merge($vals, [$id]));
            execute('DELETE FROM BOOK_AUTHOR WHERE book_id = ?', [$id]);
            $msg = "Book #$id updated.";
        }
        foreach ($d['authors'] as $a) {
            execute('INSERT INTO BOOK_AUTHOR (book_id, author_id) VALUES (?, ?)', [$id, $a]);
        }
        $pdo->commit();
        reply(['book_id' => $id], $msg);
    },

    'book_delete' => function ($in) {
        $id = in_int($in, 'book_id');
        $pdo = db();
        $pdo->beginTransaction();
        execute('DELETE FROM BOOK_AUTHOR WHERE book_id = ?', [$id]);
        $n = execute('DELETE FROM BOOK WHERE book_id = ?', [$id]);
        $pdo->commit();
        $n ? reply(null, "Book #$id deleted.") : refuse(['Book not found.']);
    },

    /* ---------- Copies (weak entity) ---------- */
    'copy_save' => function ($in) {
        [$d, $errors] = validate_copy($in);
        $isNew = empty($in['copy_no']);
        if ($isNew && $d['status'] === 'Borrowed') {
            $errors[] = 'A new copy cannot start as Borrowed. Issue it from Loans.';
        }
        if ($errors) { refuse($errors); }
        if ($isNew) {
            $no = (int)fetch_value('SELECT COALESCE(MAX(copy_no), 0) + 1 FROM BOOK_COPY WHERE book_id = ?', [$d['book_id']]);
            execute('INSERT INTO BOOK_COPY (book_id, copy_no, shelf_location, `condition`, status, price, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$d['book_id'], $no, $d['shelf_location'], $d['condition'], $d['status'], $d['price'], $d['branch_id']]);
            reply(['book_id' => $d['book_id'], 'copy_no' => $no], "Copy {$d['book_id']}-$no added.");
        }
        $no = in_int($in, 'copy_no');
        $n = execute('UPDATE BOOK_COPY SET shelf_location = ?, `condition` = ?, status = ?, price = ?, branch_id = ? WHERE book_id = ? AND copy_no = ?',
                     [$d['shelf_location'], $d['condition'], $d['status'], $d['price'], $d['branch_id'], $d['book_id'], $no]);
        reply(null, "Copy {$d['book_id']}-$no " . ($n ? 'updated.' : 'saved (no changes).'));
    },

    'copy_delete' => function ($in) {
        $b = in_int($in, 'book_id'); $c = in_int($in, 'copy_no');
        try {
            $n = execute('DELETE FROM BOOK_COPY WHERE book_id = ? AND copy_no = ?', [$b, $c]);
        } catch (PDOException $e) {
            refuse([db_error_message($e) . ' (A copy with loan history cannot be deleted; mark it Maintenance instead.)']);
        }
        $n ? reply(null, "Copy $b-$c deleted.") : refuse(['Copy not found.']);
    },

    /* ---------- Members ---------- */
    'member_save' => function ($in) {
        [$d, $errors] = validate_member($in);
        if ($errors) { refuse($errors); }
        $id = in_int($in, 'member_id');
        $vals = [$d['full_name'], $d['email'], $d['phone'], $d['address'], $d['status'], $d['date_of_birth'], $d['join_date'], $d['plan_id']];
        if ($id === 0) {
            $id = next_id('MEMBER', 'member_id', 201);
            execute('INSERT INTO MEMBER (full_name, email, phone, address, status, date_of_birth, join_date, plan_id, member_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($vals, [$id]));
            reply(['member_id' => $id], "Member #$id {$d['full_name']} registered.");
        }
        execute('UPDATE MEMBER SET full_name = ?, email = ?, phone = ?, address = ?, status = ?, date_of_birth = ?, join_date = ?, plan_id = ?
                 WHERE member_id = ?', array_merge($vals, [$id]));
        reply(['member_id' => $id], "Member #$id updated.");
    },

    'member_delete' => function ($in) {
        $id = in_int($in, 'member_id');
        try {
            $n = execute('DELETE FROM MEMBER WHERE member_id = ?', [$id]);
        } catch (PDOException $e) {
            refuse([db_error_message($e) . ' Tip: set the member to Inactive instead of deleting.']);
        }
        $n ? reply(null, "Member #$id deleted.") : refuse(['Member not found.']);
    },

    /* ---------- Loans: issue / return / renew / delete ---------- */
    'loan_issue' => function ($in) {
        [$d, $errors] = validate_issue($in);
        if ($errors) { refuse($errors); }
        $pdo = db();
        $pdo->beginTransaction();
        $st = $pdo->prepare('CALL sp_issue_book(?, ?, ?, ?, ?, @new_loan)');
        $st->execute([$d['member_id'], $d['book_id'], $d['copy_no'], $d['staff_id'], $d['issue_date']]);
        $st->closeCursor();
        $loanId = (int)$pdo->query('SELECT @new_loan')->fetchColumn();
        $pdo->commit();
        $l = fetch_one('SELECT * FROM LOAN_DETAILS WHERE loan_id = ?', [$loanId]);
        reply(['loan_id' => $loanId], "Loan #$loanId issued: \"{$l['book_title']}\" (copy {$l['copy_no']}) to {$l['member_name']}. Due back on {$l['due_date']}.");
    },

    'loan_return' => function ($in) {
        [$d, $errors] = validate_return($in);
        if ($errors) { refuse($errors); }
        $pdo = db();
        $pdo->beginTransaction();
        $st = $pdo->prepare('CALL sp_return_book(?, ?, ?, @fine)');
        $st->execute([$d['loan_id'], $d['return_date'], FINE_PER_DAY]);
        $st->closeCursor();
        $fine = (float)$pdo->query('SELECT @fine')->fetchColumn();
        $pdo->commit();
        $msg = "Loan #{$d['loan_id']} returned on {$d['return_date']}. The copy is available again.";
        if ($fine > 0) {
            $msg .= ' Returned late: fine of ' . money($fine) . ' recorded (Rs. ' . FINE_PER_DAY . ' per day).';
        }
        reply(['fine' => $fine], $msg);
    },

    'loan_renew' => function ($in) {
        $id = in_int($in, 'loan_id');
        $l = fetch_one('SELECT l.*, mp.loan_days FROM LOAN l JOIN MEMBER m ON m.member_id = l.member_id
                        JOIN MEMBERSHIP_PLAN mp ON mp.plan_id = m.plan_id WHERE l.loan_id = ?', [$id]);
        if (!$l || $l['return_date'] !== null) { refuse(['Only open loans can be renewed.']); }
        if ($l['due_date'] < date('Y-m-d')) { refuse(['Overdue loans cannot be renewed. Return the book and clear the fine first.']); }
        if (fetch_value("SELECT COUNT(*) FROM RESERVATION WHERE book_id = ? AND status = 'Pending' AND member_id <> ?", [$l['book_id'], $l['member_id']])) {
            refuse(['Cannot renew: another member has a pending reservation for this book.']);
        }
        execute('UPDATE LOAN SET due_date = DATE_ADD(CURDATE(), INTERVAL ? DAY) WHERE loan_id = ?', [$l['loan_days'], $id]);
        reply(null, "Loan #$id renewed. New due date: " . fetch_value('SELECT due_date FROM LOAN WHERE loan_id = ?', [$id]) . '.');
    },

    'loan_delete' => function ($in) {
        $id = in_int($in, 'loan_id');
        $n = execute('DELETE FROM LOAN WHERE loan_id = ?', [$id]);
        $n ? reply(null, "Loan #$id deleted.") : refuse(['Loan not found.']);
    },

    /* ---------- Reservations ---------- */
    'reservation_create' => function ($in) {
        [$d, $errors] = validate_reservation($in);
        if ($errors) { refuse($errors); }
        $id = next_id('RESERVATION', 'reservation_id', 401);
        execute("INSERT INTO RESERVATION (reservation_id, reserved_on, expires_on, status, member_id, book_id) VALUES (?, ?, ?, 'Pending', ?, ?)",
                [$id, $d['reserved_on'], $d['expires_on'], $d['member_id'], $d['book_id']]);
        reply(['reservation_id' => $id], "Reservation #$id created. It is held until "
              . fetch_value('SELECT expires_on FROM RESERVATION WHERE reservation_id = ?', [$id]) . '.');
    },

    'reservation_cancel' => function ($in) {
        $id = in_int($in, 'reservation_id');
        $n = execute("UPDATE RESERVATION SET status = 'Cancelled' WHERE reservation_id = ? AND status = 'Pending'", [$id]);
        $n ? reply(null, "Reservation #$id cancelled.") : refuse(['Only pending reservations can be cancelled.']);
    },

    'reservation_extend' => function ($in) {
        $id = in_int($in, 'reservation_id');
        $n = execute("UPDATE RESERVATION SET expires_on = DATE_ADD(GREATEST(expires_on, CURDATE()), INTERVAL 7 DAY)
                      WHERE reservation_id = ? AND status = 'Pending'", [$id]);
        $n ? reply(null, "Reservation #$id extended by 7 days.") : refuse(['Only pending reservations can be extended.']);
    },

    'reservation_delete' => function ($in) {
        $id = in_int($in, 'reservation_id');
        $n = execute('DELETE FROM RESERVATION WHERE reservation_id = ?', [$id]);
        $n ? reply(null, "Reservation #$id deleted.") : refuse(['Reservation not found.']);
    },

    /* ---------- Fines and payments ---------- */
    'payment_create' => function ($in) {
        [$d, $errors] = validate_payment($in);
        if (!$errors) {
            $bal = fetch_value('SELECT balance FROM FINE_SUMMARY WHERE fine_no = ?', [$d['fine_no']]);
            if ($bal === false) { $errors[] = 'Fine not found.'; }
            elseif ((float)$d['amount_paid'] > (float)$bal) {
                $errors[] = 'Amount paid (' . money($d['amount_paid']) . ') is more than the balance due (' . money($bal) . ').';
            }
        }
        if ($errors) { refuse($errors); }
        $no = next_id('PAYMENT', 'payment_no', 701);
        execute('INSERT INTO PAYMENT (payment_no, amount_paid, payment_date, method, fine_no) VALUES (?, ?, ?, ?, ?)',
                [$no, $d['amount_paid'], $d['payment_date'], $d['method'], $d['fine_no']]);
        $f = fetch_one('SELECT status, balance FROM FINE_SUMMARY WHERE fine_no = ?', [$d['fine_no']]);
        reply(null, "Payment #$no of " . money($d['amount_paid']) . " recorded. Fine {$d['fine_no']} is now {$f['status']} (balance " . money($f['balance']) . ').');
    },

    'payment_delete' => function ($in) {
        $no = in_int($in, 'payment_no');
        $n = execute('DELETE FROM PAYMENT WHERE payment_no = ?', [$no]);
        $n ? reply(null, "Payment #$no deleted. The fine status was recalculated.") : refuse(['Payment not found.']);
    },

    'fine_create' => function ($in) {
        [$d, $errors] = validate_fine($in, true);
        if ($errors) { refuse($errors); }
        $no = next_id('FINE', 'fine_no', 601);
        execute("INSERT INTO FINE (fine_no, amount, reason, status, fine_date, loan_id) VALUES (?, ?, ?, 'Unpaid', ?, ?)",
                [$no, $d['amount'], $d['reason'], $d['fine_date'], $d['loan_id']]);
        reply(['fine_no' => $no], "Fine #$no of " . money($d['amount']) . " raised against loan {$d['loan_id']}.");
    },

    'fine_update' => function ($in) {
        $no = in_int($in, 'fine_no');
        [$d, $errors] = validate_fine($in, false);
        if ($errors) { refuse($errors); }
        execute('UPDATE FINE SET amount = ?, reason = ? WHERE fine_no = ?', [$d['amount'], $d['reason'], $no]);
        reply(null, "Fine #$no updated.");
    },

    'fine_delete' => function ($in) {
        $no = in_int($in, 'fine_no');
        $n = execute('DELETE FROM FINE WHERE fine_no = ?', [$no]);
        $n ? reply(null, "Fine #$no deleted (waived).") : refuse(['Fine not found.']);
    },

    /* ---------- Catalogue (master tables) ---------- */
    'catalogue_save' => function ($in) {
        $tab = (string)($in['t'] ?? '');
        if (!isset(CATALOGUE[$tab])) { refuse(['Unknown table.']); }
        $cfg = CATALOGUE[$tab];
        [$d, $errors] = validate_catalogue($tab, $in);
        if ($errors) { refuse($errors); }
        $cols = array_keys($cfg['fields']);
        $q = array_map(fn($c) => "`$c`", $cols);
        $id = in_int($in, 'id');
        if ($id === 0) {
            $id = next_id($cfg['table'], $cfg['pk'], $cfg['start']);
            execute("INSERT INTO {$cfg['table']} ({$cfg['pk']}, " . implode(', ', $q) . ') VALUES (' . implode(', ', array_fill(0, count($q) + 1, '?')) . ')',
                    array_merge([$id], array_values($d)));
            reply(['id' => $id], "Record #$id added to {$cfg['table']}.");
        }
        execute("UPDATE {$cfg['table']} SET " . implode(', ', array_map(fn($c) => "$c = ?", $q)) . " WHERE {$cfg['pk']} = ?",
                array_merge(array_values($d), [$id]));
        reply(['id' => $id], "Record #$id in {$cfg['table']} updated.");
    },

    'catalogue_delete' => function ($in) {
        $tab = (string)($in['t'] ?? '');
        if (!isset(CATALOGUE[$tab])) { refuse(['Unknown table.']); }
        $cfg = CATALOGUE[$tab];
        $id = in_int($in, 'id');
        $n = execute("DELETE FROM {$cfg['table']} WHERE {$cfg['pk']} = ?", [$id]);
        $n ? reply(null, "Record #$id deleted.") : refuse(['Record not found.']);
    },
];

/* ---------- Dispatch ---------- */
if ($isPost) {
    if (!isset($writes[$action])) { refuse(["Unknown action: $action"], 404); }
    ($writes[$action])($in);
} else {
    if (!isset($reads[$action])) { refuse(["Unknown action: $action"], 404); }
    reply(($reads[$action])($in));
}
