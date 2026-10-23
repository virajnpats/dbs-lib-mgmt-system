<?php
/*
 * test_suite.php - automated test cases for the prototype.
 *
 * The whole suite runs inside ONE transaction that is rolled back at the end,
 * and every test runs inside its own SAVEPOINT, so running the tests never
 * changes the real data. Tests that need specific data create their own
 * temporary member / book / copy first, so results do not depend on what was
 * done in the application before.
 *
 * Run from the browser (tests.php) or the command line: php tests/run_tests.php
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/catalogue_config.php';

/* ---------- helpers used by the tests ---------- */

/** Create a temporary member and return its ID. Plan 1 = Basic (2 books, 7 days). */
function tc_member(string $status = 'Active', int $plan = 1): int
{
    $id = next_id('MEMBER', 'member_id', 201);
    execute('INSERT INTO MEMBER (member_id, full_name, email, phone, address, status, date_of_birth, join_date, plan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)',
            [$id, 'Test Member', "tc$id@test.example", '9000000000', 'Vellore', $status, '2004-01-01', $plan]);
    return $id;
}

/** Create a temporary book with $copies available copies. Returns the book ID. */
function tc_book(int $copies = 1): int
{
    $id = next_id('BOOK', 'book_id', 101);
    execute("INSERT INTO BOOK (book_id, isbn, title, language, publish_year, edition, publisher_id, category_id)
             VALUES (?, NULL, ?, 'English', 2020, '1st', 1, 1)", [$id, "Test Book $id"]);
    for ($c = 1; $c <= $copies; $c++) {
        execute("INSERT INTO BOOK_COPY (book_id, copy_no, shelf_location, `condition`, status, price, branch_id)
                 VALUES (?, ?, 'Z-99', 'New', 'Available', 100.00, 1)", [$id, $c]);
    }
    return $id;
}

function tc_staff(): int
{
    return (int)fetch_value('SELECT MIN(staff_id) FROM STAFF');
}

/** Issue through the stored procedure, return the new loan ID. */
function tc_issue(int $member, int $book, int $copy = 1, ?string $date = null): int
{
    $stmt = db()->prepare('CALL sp_issue_book(?, ?, ?, ?, ?, @tc_loan)');
    $stmt->execute([$member, $book, $copy, tc_staff(), $date ?? date('Y-m-d')]);
    $stmt->closeCursor();
    return (int)db()->query('SELECT @tc_loan')->fetchColumn();
}

function tc_return(int $loan, ?string $date = null): float
{
    $stmt = db()->prepare('CALL sp_return_book(?, ?, ?, @tc_fine)');
    $stmt->execute([$loan, $date ?? date('Y-m-d'), FINE_PER_DAY]);
    $stmt->closeCursor();
    return (float)db()->query('SELECT @tc_fine')->fetchColumn();
}

/**
 * Run $fn and expect the database to reject it.
 * $codes: accepted MySQL/MariaDB error numbers. $contains: text the message must contain.
 */
function tc_expect_error(callable $fn, array $codes, string $contains = ''): array
{
    try {
        $fn();
        return [false, 'No error - the operation was accepted'];
    } catch (PDOException $e) {
        $code = (int)($e->errorInfo[1] ?? 0);
        $msg = db_error_message($e);
        $ok = in_array($code, $codes, true) && ($contains === '' || stripos($msg, $contains) !== false);
        return [$ok, "Rejected (error $code): $msg"];
    }
}

/** Expect validation errors; pass if one of them contains $contains. */
function tc_expect_invalid(array $errors, string $contains): array
{
    foreach ($errors as $e) {
        if (stripos($e, $contains) !== false) {
            return [true, 'Rejected: ' . $e];
        }
    }
    return [false, $errors ? 'Different errors: ' . implode(' | ', $errors) : 'Accepted (no validation error)'];
}

const CHECK_CODES = [3819, 4025];   // MySQL 8 / MariaDB CHECK constraint violation
const TRIGGER_CODE = [1644];        // SIGNAL SQLSTATE '45000'

/* ---------- the test cases ---------- */

function get_test_cases(): array
{
    $T = [];

    /* ===== 1. Database connectivity ===== */
    $T[] = ['Connectivity', 'Application connects and the final schema is complete',
        'Open the app / query information_schema', 'At least 14 tables, 5 views, 14 triggers, 5 routines',
        function () {
            $t = fetch_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");
            $v = fetch_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'VIEW'");
            $g = fetch_value('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()');
            $r = fetch_value('SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE()');
            return [$t >= 14 && $v >= 5 && $g >= 14 && $r >= 5, "$t tables, $v views, $g triggers, $r routines"];
        }];

    /* ===== 2. CRUD ===== */
    $T[] = ['CRUD - Book', 'Create a book with two authors (BOOK + BOOK_AUTHOR)',
        'Title "Test Driven Development", authors 5 and 6', 'BOOK row and 2 BOOK_AUTHOR rows inserted',
        function () {
            $id = next_id('BOOK', 'book_id', 101);
            execute("INSERT INTO BOOK (book_id, isbn, title, language, publish_year, edition, publisher_id, category_id)
                     VALUES (?, '9780321146533', 'Test Driven Development', 'English', 2002, '1st', 4, 3)", [$id]);
            execute('INSERT INTO BOOK_AUTHOR VALUES (?, 5), (?, 6)', [$id, $id]);
            $n = fetch_value('SELECT COUNT(*) FROM BOOK_AUTHOR WHERE book_id = ?', [$id]);
            $title = fetch_value('SELECT title FROM BOOK WHERE book_id = ?', [$id]);
            return [$title === 'Test Driven Development' && $n == 2, "Book #$id \"$title\" with $n author link(s)"];
        }];

    $T[] = ['CRUD - Book', 'Update a book title', 'Change title of a test book', 'Title changed, 1 row affected',
        function () {
            $id = tc_book(0);
            $n = execute("UPDATE BOOK SET title = 'Updated Title' WHERE book_id = ?", [$id]);
            $t = fetch_value('SELECT title FROM BOOK WHERE book_id = ?', [$id]);
            return [$n === 1 && $t === 'Updated Title', "$n row updated, title now \"$t\""];
        }];

    $T[] = ['CRUD - Book', 'Delete a book that has no copies', 'Delete a test book with 0 copies', 'Row deleted',
        function () {
            $id = tc_book(0);
            $n = execute('DELETE FROM BOOK WHERE book_id = ?', [$id]);
            return [$n === 1, "$n row deleted"];
        }];

    $T[] = ['CRUD - Book', 'Delete a book that still has copies', 'Delete book 101 (has copies and loans)',
        'Rejected by FOREIGN KEY (referential integrity)',
        function () {
            return tc_expect_error(fn() => execute('DELETE FROM BOOK WHERE book_id = 101'), [1451]);
        }];

    $T[] = ['CRUD - Book', 'Duplicate ISBN', 'New book with ISBN 9780451524935 (already used by 1984)',
        'Rejected by UNIQUE constraint',
        function () {
            return tc_expect_error(fn() => execute("INSERT INTO BOOK (book_id, isbn, title, category_id)
                                                    VALUES (?, '9780451524935', 'Copy of 1984', 1)", [next_id('BOOK', 'book_id', 101)]), [1062]);
        }];

    $T[] = ['CRUD - Member', 'Register a new member', 'Valid member, plan Basic', 'Member row inserted with status Active',
        function () {
            $id = tc_member();
            $m = fetch_one('SELECT full_name, status FROM MEMBER WHERE member_id = ?', [$id]);
            return [$m && $m['status'] === 'Active', "Member #$id {$m['full_name']} ({$m['status']})"];
        }];

    $T[] = ['CRUD - Member', 'Update member status', 'Set a test member to Inactive', 'Status becomes Inactive',
        function () {
            $id = tc_member();
            execute("UPDATE MEMBER SET status = 'Inactive' WHERE member_id = ?", [$id]);
            $s = fetch_value('SELECT status FROM MEMBER WHERE member_id = ?', [$id]);
            return [$s === 'Inactive', "Status = $s"];
        }];

    $T[] = ['CRUD - Member', 'Delete a member who has loans', 'Delete member 201', 'Rejected by FOREIGN KEY',
        function () {
            return tc_expect_error(fn() => execute('DELETE FROM MEMBER WHERE member_id = 201'), [1451]);
        }];

    $T[] = ['CRUD - Member', 'Duplicate member email', 'New member with email aarav@example.com', 'Rejected by UNIQUE constraint',
        function () {
            return tc_expect_error(fn() => execute("INSERT INTO MEMBER (member_id, full_name, email, status, join_date, plan_id)
                                                    VALUES (?, 'Someone', 'aarav@example.com', 'Active', CURDATE(), 1)",
                                                   [next_id('MEMBER', 'member_id', 201)]), [1062]);
        }];

    $T[] = ['CRUD - Copy', 'Add a copy to a book (weak entity numbering)', 'Add a copy to book 105 (has copies 1 and 2)',
        'New copy gets copy_no = MAX(copy_no) + 1 for that book',
        function () {
            $expected = (int)fetch_value('SELECT MAX(copy_no) + 1 FROM BOOK_COPY WHERE book_id = 105');
            $no = (int)fetch_value('SELECT COALESCE(MAX(copy_no), 0) + 1 FROM BOOK_COPY WHERE book_id = 105');
            execute("INSERT INTO BOOK_COPY VALUES (105, ?, 'T-06', 'New', 'Available', 550.00, 3)", [$no]);
            $ok = (bool)fetch_value('SELECT COUNT(*) FROM BOOK_COPY WHERE book_id = 105 AND copy_no = ?', [$no]);
            return [$ok && $no === $expected, "Copy 105-$no created"];
        }];

    $T[] = ['CRUD - Catalogue', 'Add, update and delete an author', 'Author "Test Author"', 'All three operations succeed',
        function () {
            $id = next_id('AUTHOR', 'author_id', 1);
            execute("INSERT INTO AUTHOR VALUES (?, 'Test Author', 'Indian', '1980-05-05')", [$id]);
            $u = execute("UPDATE AUTHOR SET nationality = 'British' WHERE author_id = ?", [$id]);
            $d = execute('DELETE FROM AUTHOR WHERE author_id = ?', [$id]);
            return [$u === 1 && $d === 1, "Inserted #$id, updated $u, deleted $d"];
        }];

    $T[] = ['CRUD - Catalogue', 'Delete a category that books use', 'Delete category 3 (Technology)', 'Rejected by FOREIGN KEY',
        function () {
            return tc_expect_error(fn() => execute('DELETE FROM CATEGORY WHERE category_id = 3'), [1451]);
        }];

    $T[] = ['CRUD - Fine', 'Raise a fine manually and delete it', 'Fine of Rs. 100 on a test loan, then delete',
        'Fine starts Unpaid; delete succeeds while it has no payments',
        function () {
            $m = tc_member(); $b = tc_book(1); $loan = tc_issue($m, $b);
            $no = next_id('FINE', 'fine_no', 601);
            execute("INSERT INTO FINE VALUES (?, 100.00, 'Damaged book', 'Paid', CURDATE(), ?)", [$no, $loan]);
            $s = fetch_value('SELECT status FROM FINE WHERE fine_no = ?', [$no]);
            $d = execute('DELETE FROM FINE WHERE fine_no = ?', [$no]);
            return [$s === 'Unpaid' && $d === 1, "Inserted as '$s' (trigger ignores the typed 'Paid'), deleted $d"];
        }];

    /* ===== 3. Search / retrieval ===== */
    $T[] = ['Search', 'Search books by title', "Keyword 'code'", 'Clean Code is returned',
        function () {
            $rows = fetch_all('SELECT title FROM BOOK WHERE title LIKE ?', ['%code%']);
            $titles = array_column($rows, 'title');
            return [in_array('Clean Code', $titles, true), count($rows) . ' row(s): ' . implode(', ', $titles)];
        }];

    $T[] = ['Search', 'Search books by author (through BOOK_AUTHOR)', "Author keyword 'Tanenbaum'", 'Computer Networks is returned',
        function () {
            $rows = fetch_all('SELECT DISTINCT b.title FROM BOOK b JOIN BOOK_AUTHOR ba ON ba.book_id = b.book_id
                               JOIN AUTHOR a ON a.author_id = ba.author_id WHERE a.full_name LIKE ?', ['%Tanenbaum%']);
            $titles = array_column($rows, 'title');
            return [in_array('Computer Networks', $titles, true), implode(', ', $titles)];
        }];

    $T[] = ['Search', 'Search members by email', "Keyword 'diya'", 'Diya Patel is returned',
        function () {
            $rows = fetch_all('SELECT full_name FROM MEMBER WHERE email LIKE ? OR full_name LIKE ?', ['%diya%', '%diya%']);
            return [in_array('Diya Patel', array_column($rows, 'full_name'), true), implode(', ', array_column($rows, 'full_name'))];
        }];

    $T[] = ['Search', 'Filter loans by status through the LOAN_DETAILS view', "Status = 'Overdue' after sp_refresh_statuses",
        'Only open loans past the due date are listed',
        function () {
            db()->exec('CALL sp_refresh_statuses()');
            $rows = fetch_all("SELECT loan_id, due_date, return_date FROM LOAN_DETAILS WHERE status = 'Overdue'");
            $bad = array_filter($rows, fn($r) => $r['return_date'] !== null || $r['due_date'] >= date('Y-m-d'));
            return [count($rows) > 0 && !$bad, count($rows) . ' overdue loan(s): ' . implode(', ', array_column($rows, 'loan_id'))];
        }];

    $T[] = ['Search', 'SQL injection attempt in a search box', "Keyword: ' OR '1'='1", '0 rows - input is treated as text, not SQL',
        function () {
            $rows = fetch_all('SELECT title FROM BOOK WHERE title LIKE ?', ["%' OR '1'='1%"]);
            return [count($rows) === 0, count($rows) . ' row(s) returned'];
        }];

    $T[] = ['Search', 'HTML in data is escaped on output (XSS)', "<script>alert(1)</script>", 'Printed as text, not executed',
        function () {
            $out = h('<script>alert(1)</script>');
            return [strpos($out, '<script>') === false, $out];
        }];

    /* ===== 4. Input validation (PHP layer) ===== */
    $good = ['full_name' => 'Priya Sharma', 'email' => 'priya@example.com', 'phone' => '9876543210', 'address' => 'Vellore',
             'status' => 'Active', 'date_of_birth' => '2005-06-15', 'join_date' => date('Y-m-d'), 'plan_id' => '2'];

    $T[] = ['Validation', 'Valid member form is accepted', 'Priya Sharma, priya@example.com, 9876543210', 'No validation errors',
        function () use ($good) {
            [, $e] = validate_member($good);
            return [!$e, $e ? implode(' | ', $e) : 'Accepted'];
        }];

    $T[] = ['Validation', 'Book without a title', 'Title left empty', '"Title is required."',
        function () {
            [, $e] = validate_book(['title' => '', 'category_id' => '1', 'authors' => ['1']]);
            return tc_expect_invalid($e, 'Title is required');
        }];

    $T[] = ['Validation', 'ISBN with a wrong check digit', 'ISBN 9780451524936', 'ISBN rejected',
        function () {
            [, $e] = validate_book(['title' => 'X', 'isbn' => '9780451524936', 'category_id' => '1', 'authors' => ['1']]);
            return tc_expect_invalid($e, 'ISBN');
        }];

    $T[] = ['Validation', 'Publish year out of range', 'Year 3000', 'Year rejected',
        function () {
            [, $e] = validate_book(['title' => 'X', 'publish_year' => '3000', 'category_id' => '1', 'authors' => ['1']]);
            return tc_expect_invalid($e, 'Publish year');
        }];

    $T[] = ['Validation', 'Book with no author selected', 'Authors empty', 'At least one author required',
        function () {
            [, $e] = validate_book(['title' => 'X', 'category_id' => '1']);
            return tc_expect_invalid($e, 'author');
        }];

    $T[] = ['Validation', 'Invalid email address', 'priya@', 'Email rejected',
        function () use ($good) {
            [, $e] = validate_member(['email' => 'priya@'] + $good);
            return tc_expect_invalid($e, 'valid email');
        }];

    $T[] = ['Validation', 'Phone number with 9 digits', '987654321', 'Phone rejected',
        function () use ($good) {
            [, $e] = validate_member(['phone' => '987654321'] + $good);
            return tc_expect_invalid($e, '10 digits');
        }];

    $T[] = ['Validation', 'Name containing digits', 'Priya123', 'Name rejected',
        function () use ($good) {
            [, $e] = validate_member(['full_name' => 'Priya123'] + $good);
            return tc_expect_invalid($e, 'letters');
        }];

    $T[] = ['Validation', 'Date of birth in the future', 'DOB = tomorrow', 'Date rejected',
        function () use ($good) {
            [, $e] = validate_member(['date_of_birth' => date('Y-m-d', strtotime('+1 day'))] + $good);
            return tc_expect_invalid($e, 'future');
        }];

    $T[] = ['Validation', 'Impossible calendar date', 'Join date 2026-02-30', 'Date rejected',
        function () use ($good) {
            [, $e] = validate_member(['join_date' => '2026-02-30'] + $good);
            return tc_expect_invalid($e, 'valid date');
        }];

    $T[] = ['Validation', 'Zero or negative payment', 'Amount 0 and -5', 'Both rejected',
        function () {
            [, $e1] = validate_payment(['fine_no' => '601', 'amount_paid' => '0', 'payment_date' => date('Y-m-d'), 'method' => 'UPI']);
            [, $e2] = validate_payment(['fine_no' => '601', 'amount_paid' => '-5', 'payment_date' => date('Y-m-d'), 'method' => 'UPI']);
            return [(bool)$e1 && (bool)$e2, 'Rejected: ' . ($e1[0] ?? '-') . ' / ' . ($e2[0] ?? '-')];
        }];

    $T[] = ['Validation', 'Payment method not in the list', "Method 'Cheque'", 'Method rejected',
        function () {
            [, $e] = validate_payment(['fine_no' => '601', 'amount_paid' => '5', 'payment_date' => date('Y-m-d'), 'method' => 'Cheque']);
            return tc_expect_invalid($e, 'Payment method');
        }];

    $T[] = ['Validation', 'Reservation expiring before it starts', 'Reserved today, expires yesterday', 'Rejected',
        function () {
            [, $e] = validate_reservation(['member_id' => '201', 'book_id' => '101', 'reserved_on' => date('Y-m-d'),
                                          'expires_on' => date('Y-m-d', strtotime('-1 day'))]);
            return tc_expect_invalid($e, 'before');
        }];

    $T[] = ['Validation', 'Catalogue: membership plan with 0 max books', 'max_books = 0', 'Rejected',
        function () {
            [, $e] = validate_catalogue('plans', ['plan_name' => 'Trial', 'max_books' => '0', 'annual_fee' => '0', 'loan_days' => '7']);
            return tc_expect_invalid($e, 'Max books');
        }];

    /* ===== 5. Database constraints (last line of defence) ===== */
    $T[] = ['DB constraint', 'Member status outside the allowed list', "status = 'Blocked'", 'Rejected by CHECK',
        function () {
            return tc_expect_error(fn() => execute("UPDATE MEMBER SET status = 'Blocked' WHERE member_id = 201"), CHECK_CODES);
        }];

    $T[] = ['DB constraint', 'Copy with a negative price', 'price = -10', 'Rejected by CHECK',
        function () {
            return tc_expect_error(fn() => execute('UPDATE BOOK_COPY SET price = -10 WHERE book_id = 103 AND copy_no = 1'), CHECK_CODES);
        }];

    $T[] = ['DB constraint', 'Member phone that is not 10 digits', "phone = '12345'", 'Rejected by CHECK (REGEXP)',
        function () {
            return tc_expect_error(fn() => execute("UPDATE MEMBER SET phone = '12345' WHERE member_id = 201"), CHECK_CODES);
        }];

    $T[] = ['DB constraint', 'Payment method outside the allowed list', "method = 'Cheque'", 'Rejected by CHECK',
        function () {
            return tc_expect_error(fn() => execute("UPDATE PAYMENT SET method = 'Cheque' WHERE payment_no = 701"), CHECK_CODES);
        }];

    $T[] = ['DB constraint', 'Loan pointing to a copy that does not exist', 'Insert loan for copy 101-99', 'Rejected',
        function () {
            $m = tc_member();
            return tc_expect_error(fn() => execute("INSERT INTO LOAN VALUES (?, CURDATE(), CURDATE(), NULL, 'Borrowed', ?, 101, 99, ?)",
                                                   [next_id('LOAN', 'loan_id', 501), $m, tc_staff()]), [1644, 1452], 'not exist');
        }];

    /* ===== 6. Business rules (stored procedures + triggers) ===== */
    $T[] = ['Business rule', 'Issue a book to an eligible member', 'Active Basic member, available copy',
        'Loan created, due = issue + 7 days, copy becomes Borrowed',
        function () {
            $m = tc_member('Active', 1); $b = tc_book(1);
            $loan = tc_issue($m, $b);
            $l = fetch_one('SELECT status, DATEDIFF(due_date, issue_date) AS days FROM LOAN WHERE loan_id = ?', [$loan]);
            $copy = fetch_value('SELECT status FROM BOOK_COPY WHERE book_id = ? AND copy_no = 1', [$b]);
            return [$l['status'] === 'Borrowed' && (int)$l['days'] === 7 && $copy === 'Borrowed',
                    "Loan #$loan {$l['status']}, {$l['days']} days, copy $copy"];
        }];

    $T[] = ['Business rule', 'Inactive member cannot borrow', 'Issue to an Inactive member', 'Rejected by trigger',
        function () {
            $m = tc_member('Inactive'); $b = tc_book(1);
            return tc_expect_error(fn() => tc_issue($m, $b), TRIGGER_CODE, 'Inactive');
        }];

    $T[] = ['Business rule', 'Copy already on loan cannot be issued again', 'Issue the same copy twice', 'Second issue rejected',
        function () {
            $m1 = tc_member(); $m2 = tc_member(); $b = tc_book(1);
            tc_issue($m1, $b);
            return tc_expect_error(fn() => tc_issue($m2, $b), TRIGGER_CODE, 'not available');
        }];

    $T[] = ['Business rule', 'Borrowing limit of the membership plan', 'Basic plan (2 books): issue a 3rd book', 'Third issue rejected',
        function () {
            $m = tc_member('Active', 1); $b = tc_book(3);
            tc_issue($m, $b, 1); tc_issue($m, $b, 2);
            return tc_expect_error(fn() => tc_issue($m, $b, 3), TRIGGER_CODE, 'limit');
        }];

    $T[] = ['Business rule', 'Member with unpaid fines cannot borrow', 'Member 201 (fine 601 unpaid)', 'Rejected by trigger',
        function () {
            $b = tc_book(1);
            return tc_expect_error(fn() => tc_issue(201, $b), TRIGGER_CODE, 'unpaid fines');
        }];

    $T[] = ['Business rule', 'Return on time', 'Return a loan on the issue date', 'Returned, copy Available, no fine',
        function () {
            $m = tc_member(); $b = tc_book(1); $loan = tc_issue($m, $b);
            $fine = tc_return($loan);
            $l = fetch_value('SELECT status FROM LOAN WHERE loan_id = ?', [$loan]);
            $c = fetch_value('SELECT status FROM BOOK_COPY WHERE book_id = ? AND copy_no = 1', [$b]);
            $f = fetch_value('SELECT COUNT(*) FROM FINE WHERE loan_id = ?', [$loan]);
            return [$l === 'Returned' && $c === 'Available' && $fine == 0 && $f == 0, "Loan $l, copy $c, fine " . money($fine)];
        }];

    $T[] = ['Business rule', 'Late return creates a fine automatically', 'Issued 20 days ago on a 7-day plan, returned today',
        '13 days late x Rs. 5 = Rs. 65.00 fine, Unpaid',
        function () {
            $m = tc_member('Active', 1); $b = tc_book(1);
            $loan = tc_issue($m, $b, 1, date('Y-m-d', strtotime('-20 days')));
            $fine = tc_return($loan);
            $f = fetch_one('SELECT amount, status FROM FINE WHERE loan_id = ?', [$loan]);
            return [$f && (float)$f['amount'] === 65.0 && $f['status'] === 'Unpaid', 'Fine ' . money($fine) . ' ' . ($f['status'] ?? '')];
        }];

    $T[] = ['Business rule', 'Fine status follows payments', 'Rs. 65 fine: pay 25, then 40', 'Partially Paid, then Paid',
        function () {
            $m = tc_member(); $b = tc_book(1);
            $loan = tc_issue($m, $b, 1, date('Y-m-d', strtotime('-20 days')));
            tc_return($loan);
            $fine = (int)fetch_value('SELECT fine_no FROM FINE WHERE loan_id = ?', [$loan]);
            execute("INSERT INTO PAYMENT VALUES (?, 25.00, CURDATE(), 'UPI', ?)", [next_id('PAYMENT', 'payment_no', 701), $fine]);
            $s1 = fetch_value('SELECT status FROM FINE WHERE fine_no = ?', [$fine]);
            execute("INSERT INTO PAYMENT VALUES (?, 40.00, CURDATE(), 'Cash', ?)", [next_id('PAYMENT', 'payment_no', 701), $fine]);
            $s2 = fetch_value('SELECT status FROM FINE WHERE fine_no = ?', [$fine]);
            return [$s1 === 'Partially Paid' && $s2 === 'Paid', "$s1 -> $s2"];
        }];

    $T[] = ['Business rule', 'Payment larger than the balance', 'Fine 601 (Rs. 50 due): pay Rs. 80', 'Rejected by trigger',
        function () {
            return tc_expect_error(fn() => execute("INSERT INTO PAYMENT VALUES (?, 80.00, CURDATE(), 'Cash', 601)",
                                                   [next_id('PAYMENT', 'payment_no', 701)]), TRIGGER_CODE, 'exceeds');
        }];

    $T[] = ['Business rule', 'Deleting a payment recalculates the fine status', 'Delete payment 702 of fine 603 (Paid)',
        'Fine 603 goes back to Unpaid',
        function () {
            execute('DELETE FROM PAYMENT WHERE payment_no = 702');
            $s = fetch_value('SELECT status FROM FINE WHERE fine_no = 603');
            return [$s === 'Unpaid', "Fine 603 is now $s"];
        }];

    $T[] = ['Business rule', 'Fine cannot be reduced below the amount paid', 'Fine 602 (Rs. 15 paid): set amount to 10',
        'Rejected by trigger',
        function () {
            return tc_expect_error(fn() => execute('UPDATE FINE SET amount = 10 WHERE fine_no = 602'), TRIGGER_CODE, 'already paid');
        }];

    $T[] = ['Business rule', 'Copy on an open loan cannot be marked Available', 'Copy on loan set to Available manually',
        'Rejected by trigger',
        function () {
            $m = tc_member(); $b = tc_book(1); tc_issue($m, $b);
            return tc_expect_error(fn() => execute("UPDATE BOOK_COPY SET status = 'Available' WHERE book_id = ? AND copy_no = 1", [$b]),
                                   TRIGGER_CODE, 'open loan');
        }];

    $T[] = ['Business rule', 'Reservation gets a default 7-day expiry', 'Reserve with no expiry date', 'expires_on = reserved_on + 7',
        function () {
            $m = tc_member(); $b = tc_book(1);
            $id = next_id('RESERVATION', 'reservation_id', 401);
            execute("INSERT INTO RESERVATION (reservation_id, reserved_on, status, member_id, book_id) VALUES (?, CURDATE(), 'Pending', ?, ?)", [$id, $m, $b]);
            $d = (int)fetch_value('SELECT DATEDIFF(expires_on, reserved_on) FROM RESERVATION WHERE reservation_id = ?', [$id]);
            return [$d === 7, "Expires $d days after reservation"];
        }];

    $T[] = ['Business rule', 'Duplicate pending reservation', 'Same member reserves the same book twice', 'Second reservation rejected',
        function () {
            $m = tc_member(); $b = tc_book(1);
            $ins = fn() => execute("INSERT INTO RESERVATION (reservation_id, reserved_on, status, member_id, book_id) VALUES (?, CURDATE(), 'Pending', ?, ?)",
                                   [next_id('RESERVATION', 'reservation_id', 401), $m, $b]);
            $ins();
            return tc_expect_error($ins, TRIGGER_CODE, 'already has a pending');
        }];

    $T[] = ['Business rule', 'Inactive member cannot reserve', 'Member 205 (Inactive) reserves a book', 'Rejected by trigger',
        function () {
            return tc_expect_error(fn() => execute("INSERT INTO RESERVATION (reservation_id, reserved_on, status, member_id, book_id) VALUES (?, CURDATE(), 'Pending', 205, 103)",
                                                   [next_id('RESERVATION', 'reservation_id', 401)]), TRIGGER_CODE, 'Inactive');
        }];

    $T[] = ['Business rule', 'Issuing a reserved title fulfils the reservation', 'Member reserves a book, then borrows it',
        'Reservation status becomes Fulfilled',
        function () {
            $m = tc_member(); $b = tc_book(1);
            $id = next_id('RESERVATION', 'reservation_id', 401);
            execute("INSERT INTO RESERVATION (reservation_id, reserved_on, status, member_id, book_id) VALUES (?, CURDATE(), 'Pending', ?, ?)", [$id, $m, $b]);
            tc_issue($m, $b);
            $s = fetch_value('SELECT status FROM RESERVATION WHERE reservation_id = ?', [$id]);
            return [$s === 'Fulfilled', "Reservation $id is $s"];
        }];

    $T[] = ['Business rule', 'Due date cannot exceed the plan loan period', 'Basic plan (7 days): direct insert with 30-day loan',
        'Rejected by trigger',
        function () {
            $m = tc_member('Active', 1); $b = tc_book(1);
            return tc_expect_error(fn() => execute("INSERT INTO LOAN VALUES (?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), NULL, 'Borrowed', ?, ?, 1, ?)",
                                                   [next_id('LOAN', 'loan_id', 501), $m, $b, tc_staff()]), TRIGGER_CODE, 'loan period');
        }];

    $T[] = ['Business rule', 'Overdue status is refreshed automatically', 'Open loan issued 10 days ago on a 7-day plan, run sp_refresh_statuses',
        'Loan status becomes Overdue',
        function () {
            $m = tc_member('Active', 1); $b = tc_book(1);
            $loan = tc_issue($m, $b, 1, date('Y-m-d', strtotime('-10 days')));
            db()->exec('CALL sp_refresh_statuses()');
            $s = fetch_value('SELECT status FROM LOAN WHERE loan_id = ?', [$loan]);
            return [$s === 'Overdue', "Loan #$loan is $s"];
        }];

    $T[] = ['Business rule', 'Returning a loan twice', 'Return the same loan again', 'Rejected by procedure',
        function () {
            $m = tc_member(); $b = tc_book(1); $loan = tc_issue($m, $b); tc_return($loan);
            return tc_expect_error(fn() => tc_return($loan), TRIGGER_CODE, 'already been returned');
        }];

    // Give every test an ID: TC01, TC02, ...
    $out = [];
    foreach ($T as $i => $t) {
        $out[] = ['id' => sprintf('TC%02d', $i + 1), 'module' => $t[0], 'title' => $t[1], 'input' => $t[2],
                  'expected' => $t[3], 'run' => $t[4]];
    }
    return $out;
}

/** Run every test inside a transaction + savepoints and roll everything back. */
function run_test_suite(): array
{
    $pdo = db();
    $results = [];
    $pdo->beginTransaction();
    try {
        foreach (get_test_cases() as $tc) {
            $pdo->exec('SAVEPOINT tc');
            $start = microtime(true);
            try {
                [$pass, $actual] = ($tc['run'])();
            } catch (Throwable $e) {
                $pass = false;
                $actual = 'Unexpected error: ' . db_error_message($e);
            }
            $pdo->exec('ROLLBACK TO SAVEPOINT tc');
            $results[] = ['id' => $tc['id'], 'module' => $tc['module'], 'title' => $tc['title'], 'input' => $tc['input'],
                          'expected' => $tc['expected'], 'actual' => $actual, 'pass' => (bool)$pass,
                          'ms' => round((microtime(true) - $start) * 1000, 1)];
        }
    } finally {
        $pdo->rollBack();   // leave the real data untouched
    }
    return $results;
}
