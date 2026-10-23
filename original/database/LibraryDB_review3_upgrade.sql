-- =====================================================================
-- LIBRARY MANAGEMENT SYSTEM - REVIEW 3 UPGRADE (SAFE FOR YOUR EXISTING DATABASE)
-- Database Systems BACSE202
--
-- Use THIS script on the LibraryDB you already built in Review 2.
--   * It does NOT drop, reset or recreate the database or any table.
--   * It does NOT delete, insert or change any row of your data.
--   * It only ADDS: 4 views (and refreshes LOAN_DETAILS), 1 function,
--     4 procedures, 14 triggers, 4 CHECK constraints and 1 index.
--   * A CHECK constraint is added only if every existing row already passes it;
--     otherwise it is skipped and the report at the end tells you which rows to look at.
--   * It can be run again safely: each step checks what is already there.
--
-- BEFORE RUNNING, take a backup (one line, in the terminal):
--   mysqldump -u root -p --routines --triggers LibraryDB > LibraryDB_backup.sql
--
-- Run it: open this file in MySQL Workbench and press the lightning bolt
-- (execute all), or in the terminal:
--   mysql -u root -p LibraryDB < database/LibraryDB_review3_upgrade.sql
--
-- Target: MySQL 8.0.16+ (also MariaDB 10.4+).
-- For a brand-new empty database use LibraryDB_final.sql instead.
-- =====================================================================

USE LibraryDB;

-- ---------------------------------------------------------------------
-- STEP 0. A log of what this script did (temporary: disappears on disconnect)
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS r3_upgrade_log;
CREATE TEMPORARY TABLE r3_upgrade_log (
  step_no INT AUTO_INCREMENT PRIMARY KEY,
  item    VARCHAR(100),
  result  VARCHAR(255)
);

-- ---------------------------------------------------------------------
-- STEP 1. Check that the Review 2 tables exist (read-only)
-- ---------------------------------------------------------------------
INSERT INTO r3_upgrade_log (item, result)
SELECT 'Review 2 tables found',
       CONCAT(COUNT(*), ' of 14',
              IF(COUNT(*) = 14, ' - OK', ' - STOP: run the Review 2 script first'))
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
  AND table_name IN ('MEMBERSHIP_PLAN','PUBLISHER','CATEGORY','BRANCH','AUTHOR','BOOK',
                     'BOOK_AUTHOR','MEMBER','STAFF','BOOK_COPY','RESERVATION','LOAN','FINE','PAYMENT');

INSERT INTO r3_upgrade_log (item, result)
SELECT 'Rows before upgrade',
       CONCAT('books ', (SELECT COUNT(*) FROM BOOK), ', members ', (SELECT COUNT(*) FROM MEMBER),
              ', copies ', (SELECT COUNT(*) FROM BOOK_COPY), ', loans ', (SELECT COUNT(*) FROM LOAN),
              ', fines ', (SELECT COUNT(*) FROM FINE), ', payments ', (SELECT COUNT(*) FROM PAYMENT));

-- ---------------------------------------------------------------------
-- STEP 2. Helper procedures (removed again at the end)
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS r3_add_check;
DROP PROCEDURE IF EXISTS r3_add_index;

DELIMITER $$

-- Adds CHECK (p_expr) named p_name to p_table, but only if no existing row breaks it.
CREATE PROCEDURE r3_add_check(IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_expr TEXT)
BEGIN
  DECLARE v_exists INT DEFAULT 0;
  SELECT COUNT(*) INTO v_exists FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND table_name = p_table AND constraint_name = p_name;
  IF v_exists > 0 THEN
    INSERT INTO r3_upgrade_log (item, result) VALUES (p_name, 'already present - unchanged');
  ELSE
    SET @r3_sql = CONCAT('SELECT COUNT(*) INTO @r3_bad FROM `', p_table, '` WHERE NOT (', p_expr, ')');
    PREPARE r3_stmt FROM @r3_sql; EXECUTE r3_stmt; DEALLOCATE PREPARE r3_stmt;
    IF @r3_bad = 0 THEN
      SET @r3_sql = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_name, '` CHECK (', p_expr, ')');
      PREPARE r3_stmt FROM @r3_sql; EXECUTE r3_stmt; DEALLOCATE PREPARE r3_stmt;
      INSERT INTO r3_upgrade_log (item, result) VALUES (p_name, 'added');
    ELSE
      INSERT INTO r3_upgrade_log (item, result)
      VALUES (p_name, CONCAT('SKIPPED: ', @r3_bad, ' existing row(s) in ', p_table, ' break this rule - see STEP 5'));
    END IF;
  END IF;
END$$

CREATE PROCEDURE r3_add_index(IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_cols VARCHAR(200))
BEGIN
  DECLARE v_exists INT DEFAULT 0;
  SELECT COUNT(*) INTO v_exists FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = p_table AND index_name = p_name;
  IF v_exists > 0 THEN
    INSERT INTO r3_upgrade_log (item, result) VALUES (p_name, 'already present - unchanged');
  ELSE
    SET @r3_sql = CONCAT('CREATE INDEX `', p_name, '` ON `', p_table, '` (', p_cols, ')');
    PREPARE r3_stmt FROM @r3_sql; EXECUTE r3_stmt; DEALLOCATE PREPARE r3_stmt;
    INSERT INTO r3_upgrade_log (item, result) VALUES (p_name, 'added');
  END IF;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------
-- STEP 3. New constraints and index (the Review 2 constraints stay as they are)
-- ---------------------------------------------------------------------
CALL r3_add_check('MEMBER', 'chk_member_phone', 'phone IS NULL OR phone REGEXP ''^[0-9]{10}$''');
CALL r3_add_check('MEMBER', 'chk_member_email', 'email IS NULL OR email LIKE ''%_@_%._%''');
CALL r3_add_check('STAFF',  'chk_staff_email',  'email IS NULL OR email LIKE ''%_@_%._%''');
CALL r3_add_check('LOAN',   'chk_loan_status_return',
  '(return_date IS NULL AND status IN (''Borrowed'',''Overdue'')) OR (return_date IS NOT NULL AND status = ''Returned'')');
CALL r3_add_index('BOOK', 'idx_book_title', 'title');


-- ---------------------------------------------------------------------
-- STEP 4. Views, function, procedures and triggers
--         (only objects with these names are replaced; no table data changes)
-- ---------------------------------------------------------------------
DROP FUNCTION IF EXISTS fn_open_loans;
DROP PROCEDURE IF EXISTS sp_refresh_fine_status;
DROP PROCEDURE IF EXISTS sp_issue_book;
DROP PROCEDURE IF EXISTS sp_return_book;
DROP PROCEDURE IF EXISTS sp_refresh_statuses;
DROP TRIGGER IF EXISTS trg_loan_before_insert;
DROP TRIGGER IF EXISTS trg_loan_after_insert;
DROP TRIGGER IF EXISTS trg_loan_before_update;
DROP TRIGGER IF EXISTS trg_loan_after_update;
DROP TRIGGER IF EXISTS trg_loan_after_delete;
DROP TRIGGER IF EXISTS trg_copy_before_update;
DROP TRIGGER IF EXISTS trg_fine_before_insert;
DROP TRIGGER IF EXISTS trg_fine_before_update;
DROP TRIGGER IF EXISTS trg_payment_before_insert;
DROP TRIGGER IF EXISTS trg_payment_before_update;
DROP TRIGGER IF EXISTS trg_payment_after_insert;
DROP TRIGGER IF EXISTS trg_payment_after_update;
DROP TRIGGER IF EXISTS trg_payment_after_delete;
DROP TRIGGER IF EXISTS trg_reservation_before_insert;


-- 3.1 Review 2 view: loan details (five-table join, composite join on the weak entity)
CREATE OR REPLACE VIEW LOAN_DETAILS AS
SELECT l.loan_id, m.member_id, m.full_name AS member_name,
       b.title AS book_title, bc.copy_no, s.full_name AS issued_by,
       l.issue_date, l.due_date, l.return_date, l.status
FROM LOAN l
JOIN MEMBER m     ON l.member_id = m.member_id
JOIN BOOK b       ON l.book_id = b.book_id
JOIN BOOK_COPY bc ON l.book_id = bc.book_id AND l.copy_no = bc.copy_no
JOIN STAFF s      ON l.staff_id = s.staff_id;

-- 3.2 Copies per title and how many can be issued now
CREATE OR REPLACE VIEW BOOK_AVAILABILITY AS
SELECT b.book_id, b.title,
       COUNT(bc.copy_no) AS total_copies,
       COALESCE(SUM(bc.status = 'Available'), 0) AS available_copies
FROM BOOK b
LEFT JOIN BOOK_COPY bc ON bc.book_id = b.book_id
GROUP BY b.book_id, b.title;

-- 3.3 Fine amount, total paid and balance (derived, never stored)
CREATE OR REPLACE VIEW FINE_SUMMARY AS
SELECT f.fine_no, f.loan_id, m.member_id, m.full_name AS member_name,
       f.amount, COALESCE(SUM(p.amount_paid), 0) AS total_paid,
       f.amount - COALESCE(SUM(p.amount_paid), 0) AS balance,
       f.status, f.fine_date, f.reason
FROM FINE f
JOIN LOAN l    ON l.loan_id = f.loan_id
JOIN MEMBER m  ON m.member_id = l.member_id
LEFT JOIN PAYMENT p ON p.fine_no = f.fine_no
GROUP BY f.fine_no, f.loan_id, m.member_id, m.full_name,
         f.amount, f.status, f.fine_date, f.reason;

-- 3.4 Per-member borrowing position against the plan limit
CREATE OR REPLACE VIEW MEMBER_SUMMARY AS
SELECT m.member_id, m.full_name, m.status, mp.plan_name, mp.max_books, mp.loan_days,
       (SELECT COUNT(*) FROM LOAN l
         WHERE l.member_id = m.member_id AND l.return_date IS NULL) AS open_loans,
       (SELECT COALESCE(SUM(fs.balance), 0) FROM FINE_SUMMARY fs
         WHERE fs.member_id = m.member_id) AS outstanding_fines
FROM MEMBER m
JOIN MEMBERSHIP_PLAN mp ON mp.plan_id = m.plan_id;

-- 3.5 Open loans past their due date, with days overdue
CREATE OR REPLACE VIEW OVERDUE_LOANS AS
SELECT l.loan_id, m.member_id, m.full_name AS member_name, m.phone,
       b.title AS book_title, l.copy_no, l.due_date,
       DATEDIFF(CURDATE(), l.due_date) AS days_overdue
FROM LOAN l
JOIN MEMBER m ON m.member_id = l.member_id
JOIN BOOK b   ON b.book_id = l.book_id
WHERE l.return_date IS NULL AND l.due_date < CURDATE();

-- 4. Stored function and procedures
DELIMITER $$

-- Number of copies a member currently holds (loans not yet returned)
CREATE FUNCTION fn_open_loans(p_member_id INT) RETURNS INT
READS SQL DATA
BEGIN
  DECLARE v_count INT;
  SELECT COUNT(*) INTO v_count FROM LOAN
   WHERE member_id = p_member_id AND return_date IS NULL;
  RETURN v_count;
END$$

-- Recalculate FINE.status from the payments made against it
CREATE PROCEDURE sp_refresh_fine_status(IN p_fine_no INT)
BEGIN
  DECLARE v_amount DECIMAL(8,2);
  DECLARE v_paid   DECIMAL(8,2);
  SELECT amount INTO v_amount FROM FINE WHERE fine_no = p_fine_no;
  SELECT COALESCE(SUM(amount_paid), 0) INTO v_paid FROM PAYMENT WHERE fine_no = p_fine_no;
  UPDATE FINE
     SET status = CASE WHEN v_paid = 0 THEN 'Unpaid'
                       WHEN v_paid < v_amount THEN 'Partially Paid'
                       ELSE 'Paid' END
   WHERE fine_no = p_fine_no;
END$$

-- Issue a copy to a member. Due date comes from the member's plan (loan_days).
-- All rule checks (active member, copy available, limit, fines) are in the LOAN trigger.
CREATE PROCEDURE sp_issue_book(IN p_member_id INT, IN p_book_id INT, IN p_copy_no INT,
                               IN p_staff_id INT, IN p_issue_date DATE,
                               OUT p_loan_id INT)
BEGIN
  DECLARE v_days INT;
  SELECT mp.loan_days INTO v_days
    FROM MEMBER m JOIN MEMBERSHIP_PLAN mp ON mp.plan_id = m.plan_id
   WHERE m.member_id = p_member_id;
  IF v_days IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Member does not exist.';
  END IF;
  SELECT COALESCE(MAX(loan_id), 500) + 1 INTO p_loan_id FROM LOAN;
  INSERT INTO LOAN (loan_id, issue_date, due_date, return_date, status,
                    member_id, book_id, copy_no, staff_id)
  VALUES (p_loan_id, p_issue_date, DATE_ADD(p_issue_date, INTERVAL v_days DAY), NULL,
          'Borrowed', p_member_id, p_book_id, p_copy_no, p_staff_id);
END$$

-- Return a loan. If it is late, raise (or raise the existing) fine:
-- fine = days late x p_fine_per_day. Returns the fine amount (0 if on time).
CREATE PROCEDURE sp_return_book(IN p_loan_id INT, IN p_return_date DATE,
                                IN p_fine_per_day DECIMAL(8,2),
                                OUT p_fine_amount DECIMAL(8,2))
BEGIN
  DECLARE v_due DATE;
  DECLARE v_issue DATE;
  DECLARE v_returned DATE;
  DECLARE v_days_late INT;
  DECLARE v_fine_no INT;

  SELECT due_date, issue_date, return_date INTO v_due, v_issue, v_returned
    FROM LOAN WHERE loan_id = p_loan_id;
  IF v_due IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Loan does not exist.';
  END IF;
  IF v_returned IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This loan has already been returned.';
  END IF;
  IF p_return_date < v_issue THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Return date cannot be before the issue date.';
  END IF;

  UPDATE LOAN SET return_date = p_return_date, status = 'Returned'
   WHERE loan_id = p_loan_id;

  SET v_days_late = DATEDIFF(p_return_date, v_due);
  SET p_fine_amount = 0;
  IF v_days_late > 0 THEN
    SET p_fine_amount = v_days_late * p_fine_per_day;
    SELECT fine_no INTO v_fine_no FROM FINE WHERE loan_id = p_loan_id;
    IF v_fine_no IS NULL THEN
      SELECT COALESCE(MAX(fine_no), 600) + 1 INTO v_fine_no FROM FINE;
      INSERT INTO FINE (fine_no, amount, reason, status, fine_date, loan_id)
      VALUES (v_fine_no, p_fine_amount, CONCAT('Overdue return: ', v_days_late, ' day(s) late'),
              'Unpaid', p_return_date, p_loan_id);
    ELSE
      -- An interim fine was raised while the book was overdue: bring it up to the final amount.
      UPDATE FINE
         SET amount = GREATEST(amount, p_fine_amount),
             reason = CONCAT('Overdue return: ', v_days_late, ' day(s) late')
       WHERE fine_no = v_fine_no;
      CALL sp_refresh_fine_status(v_fine_no);
      SELECT amount INTO p_fine_amount FROM FINE WHERE fine_no = v_fine_no;
    END IF;
  END IF;
END$$

-- Daily housekeeping: mark late open loans Overdue and old pending reservations Expired.
CREATE PROCEDURE sp_refresh_statuses()
BEGIN
  UPDATE LOAN SET status = 'Overdue'
   WHERE return_date IS NULL AND due_date < CURDATE() AND status = 'Borrowed';
  UPDATE RESERVATION SET status = 'Expired'
   WHERE status = 'Pending' AND expires_on IS NOT NULL AND expires_on < CURDATE();
END$$

-- 5. Triggers (business rules from the Review 2 limitations section)

-- 5.1 Before a loan is created
CREATE TRIGGER trg_loan_before_insert
BEFORE INSERT ON LOAN
FOR EACH ROW
BEGIN
  DECLARE v_member_status VARCHAR(20);
  DECLARE v_max_books INT;
  DECLARE v_loan_days INT;
  DECLARE v_copy_status VARCHAR(20);
  DECLARE v_open INT;
  DECLARE v_balance DECIMAL(10,2);
  DECLARE v_msg VARCHAR(255);

  SELECT m.status, mp.max_books, mp.loan_days
    INTO v_member_status, v_max_books, v_loan_days
    FROM MEMBER m JOIN MEMBERSHIP_PLAN mp ON mp.plan_id = m.plan_id
   WHERE m.member_id = NEW.member_id;

  IF v_member_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Member does not exist.';
  END IF;
  IF v_member_status <> 'Active' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Inactive members cannot borrow books.';
  END IF;

  IF NEW.return_date IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A new loan cannot already have a return date.';
  END IF;

  SELECT status INTO v_copy_status FROM BOOK_COPY
   WHERE book_id = NEW.book_id AND copy_no = NEW.copy_no;
  IF v_copy_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This copy does not exist.';
  END IF;
  IF v_copy_status <> 'Available' THEN
    SET v_msg = CONCAT('Copy ', NEW.book_id, '-', NEW.copy_no, ' is not available (status: ', v_copy_status, ').');
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
  END IF;
  IF EXISTS (SELECT 1 FROM LOAN WHERE book_id = NEW.book_id AND copy_no = NEW.copy_no
                                  AND return_date IS NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This copy already has an open loan.';
  END IF;

  SET v_open = fn_open_loans(NEW.member_id);
  IF v_open >= v_max_books THEN
    SET v_msg = CONCAT('Borrowing limit reached: plan allows ', v_max_books,
                       ' book(s) and the member already has ', v_open, '.');
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
  END IF;

  SELECT COALESCE(SUM(fs.balance), 0) INTO v_balance
    FROM FINE_SUMMARY fs WHERE fs.member_id = NEW.member_id;
  IF v_balance > 0 THEN
    SET v_msg = CONCAT('Member has unpaid fines of Rs. ', v_balance, '. Clear them before borrowing.');
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
  END IF;

  IF DATEDIFF(NEW.due_date, NEW.issue_date) > v_loan_days THEN
    SET v_msg = CONCAT('Due date exceeds the plan loan period of ', v_loan_days, ' days.');
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
  END IF;

  SET NEW.status = 'Borrowed';
END$$

-- 5.2 After a loan is created: copy becomes Borrowed, member's pending reservation is fulfilled
CREATE TRIGGER trg_loan_after_insert
AFTER INSERT ON LOAN
FOR EACH ROW
BEGIN
  UPDATE BOOK_COPY SET status = 'Borrowed'
   WHERE book_id = NEW.book_id AND copy_no = NEW.copy_no;
  UPDATE RESERVATION SET status = 'Fulfilled'
   WHERE member_id = NEW.member_id AND book_id = NEW.book_id AND status = 'Pending';
END$$

-- 5.3 Before a loan is changed: keep status consistent with return_date
CREATE TRIGGER trg_loan_before_update
BEFORE UPDATE ON LOAN
FOR EACH ROW
BEGIN
  IF NEW.book_id <> OLD.book_id OR NEW.copy_no <> OLD.copy_no OR NEW.member_id <> OLD.member_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Member and copy of an existing loan cannot be changed. Return it and issue a new loan.';
  END IF;
  IF NEW.return_date IS NOT NULL THEN
    SET NEW.status = 'Returned';
  ELSEIF NEW.due_date < CURDATE() THEN
    SET NEW.status = 'Overdue';
  ELSE
    SET NEW.status = 'Borrowed';
  END IF;
END$$

-- 5.4 After a loan is changed: returning frees the copy
CREATE TRIGGER trg_loan_after_update
AFTER UPDATE ON LOAN
FOR EACH ROW
BEGIN
  IF OLD.return_date IS NULL AND NEW.return_date IS NOT NULL THEN
    UPDATE BOOK_COPY SET status = 'Available'
     WHERE book_id = NEW.book_id AND copy_no = NEW.copy_no AND status = 'Borrowed';
  END IF;
END$$

-- 5.5 Deleting an open loan frees the copy
CREATE TRIGGER trg_loan_after_delete
AFTER DELETE ON LOAN
FOR EACH ROW
BEGIN
  IF OLD.return_date IS NULL THEN
    UPDATE BOOK_COPY SET status = 'Available'
     WHERE book_id = OLD.book_id AND copy_no = OLD.copy_no AND status = 'Borrowed';
  END IF;
END$$

-- 5.6 A copy on an open loan cannot be marked anything other than Borrowed (and vice versa)
CREATE TRIGGER trg_copy_before_update
BEFORE UPDATE ON BOOK_COPY
FOR EACH ROW
BEGIN
  DECLARE v_open INT;
  IF NEW.status <> OLD.status THEN
    SELECT COUNT(*) INTO v_open FROM LOAN
     WHERE book_id = OLD.book_id AND copy_no = OLD.copy_no AND return_date IS NULL;
    IF v_open > 0 AND NEW.status <> 'Borrowed' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This copy is on an open loan. Return the loan first.';
    END IF;
    IF v_open = 0 AND NEW.status = 'Borrowed' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A copy becomes Borrowed only by issuing a loan.';
    END IF;
  END IF;
END$$

-- 5.7 A new fine always starts Unpaid
CREATE TRIGGER trg_fine_before_insert
BEFORE INSERT ON FINE
FOR EACH ROW
BEGIN
  SET NEW.status = 'Unpaid';
END$$

-- 5.8 A fine cannot be reduced below what has already been paid
CREATE TRIGGER trg_fine_before_update
BEFORE UPDATE ON FINE
FOR EACH ROW
BEGIN
  DECLARE v_paid DECIMAL(10,2);
  IF NEW.amount <> OLD.amount THEN
    SELECT COALESCE(SUM(amount_paid), 0) INTO v_paid FROM PAYMENT WHERE fine_no = OLD.fine_no;
    IF NEW.amount < v_paid THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fine amount cannot be less than the amount already paid.';
    END IF;
    SET NEW.status = CASE WHEN v_paid = 0 THEN 'Unpaid'
                          WHEN v_paid < NEW.amount THEN 'Partially Paid'
                          ELSE 'Paid' END;
  END IF;
END$$

-- 5.9 Payments cannot exceed the fine
CREATE TRIGGER trg_payment_before_insert
BEFORE INSERT ON PAYMENT
FOR EACH ROW
BEGIN
  DECLARE v_amount DECIMAL(8,2);
  DECLARE v_paid DECIMAL(10,2);
  DECLARE v_msg VARCHAR(255);
  SELECT amount INTO v_amount FROM FINE WHERE fine_no = NEW.fine_no;
  IF v_amount IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fine does not exist.';
  END IF;
  SELECT COALESCE(SUM(amount_paid), 0) INTO v_paid FROM PAYMENT WHERE fine_no = NEW.fine_no;
  IF v_paid + NEW.amount_paid > v_amount THEN
    SET v_msg = CONCAT('Payment exceeds the balance. Balance due is Rs. ', v_amount - v_paid, '.');
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
  END IF;
END$$

CREATE TRIGGER trg_payment_before_update
BEFORE UPDATE ON PAYMENT
FOR EACH ROW
BEGIN
  DECLARE v_amount DECIMAL(8,2);
  DECLARE v_paid DECIMAL(10,2);
  IF NEW.fine_no <> OLD.fine_no THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A payment cannot be moved to another fine.';
  END IF;
  SELECT amount INTO v_amount FROM FINE WHERE fine_no = NEW.fine_no;
  SELECT COALESCE(SUM(amount_paid), 0) INTO v_paid FROM PAYMENT
   WHERE fine_no = NEW.fine_no AND payment_no <> OLD.payment_no;
  IF v_paid + NEW.amount_paid > v_amount THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment exceeds the fine balance.';
  END IF;
END$$

-- 5.10 Fine status follows the payments automatically
CREATE TRIGGER trg_payment_after_insert
AFTER INSERT ON PAYMENT
FOR EACH ROW
BEGIN
  CALL sp_refresh_fine_status(NEW.fine_no);
END$$

CREATE TRIGGER trg_payment_after_update
AFTER UPDATE ON PAYMENT
FOR EACH ROW
BEGIN
  CALL sp_refresh_fine_status(NEW.fine_no);
END$$

CREATE TRIGGER trg_payment_after_delete
AFTER DELETE ON PAYMENT
FOR EACH ROW
BEGIN
  CALL sp_refresh_fine_status(OLD.fine_no);
END$$

-- 5.11 Reservations: active members only, one pending reservation per member per book,
--      default expiry 7 days
CREATE TRIGGER trg_reservation_before_insert
BEFORE INSERT ON RESERVATION
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(20);
  SELECT status INTO v_status FROM MEMBER WHERE member_id = NEW.member_id;
  IF v_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Member does not exist.';
  END IF;
  IF v_status <> 'Active' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Inactive members cannot reserve books.';
  END IF;
  IF NEW.status = 'Pending' AND EXISTS (
       SELECT 1 FROM RESERVATION
        WHERE member_id = NEW.member_id AND book_id = NEW.book_id AND status = 'Pending') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This member already has a pending reservation for this book.';
  END IF;
  IF NEW.expires_on IS NULL THEN
    SET NEW.expires_on = DATE_ADD(NEW.reserved_on, INTERVAL 7 DAY);
  END IF;
END$$

DELIMITER ;

INSERT INTO r3_upgrade_log (item, result)
SELECT 'Views / routines / triggers now in LibraryDB',
       CONCAT((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'VIEW'), ' views, ',
              (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE()
                 AND routine_name IN ('fn_open_loans','sp_issue_book','sp_return_book','sp_refresh_statuses','sp_refresh_fine_status')), ' routines, ',
              (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name LIKE 'trg\_%'), ' triggers');

-- ---------------------------------------------------------------------
-- STEP 5. Data consistency report (READ-ONLY - nothing is changed)
--         Rows listed here were valid in Review 2 but disagree with each other.
--         The app still works; fix them yourself only if you want (see STEP 6).
-- ---------------------------------------------------------------------
INSERT INTO r3_upgrade_log (item, result)
SELECT 'Copies marked Available while on an open loan',
       COALESCE(GROUP_CONCAT(CONCAT(bc.book_id, '-', bc.copy_no, ' (loan ', l.loan_id, ')') SEPARATOR ', '), 'none')
FROM BOOK_COPY bc
JOIN LOAN l ON l.book_id = bc.book_id AND l.copy_no = bc.copy_no AND l.return_date IS NULL
WHERE bc.status <> 'Borrowed';

INSERT INTO r3_upgrade_log (item, result)
SELECT 'Loans whose status disagrees with return_date',
       COALESCE(GROUP_CONCAT(loan_id SEPARATOR ', '), 'none')
FROM LOAN
WHERE NOT ((return_date IS NULL AND status IN ('Borrowed','Overdue'))
        OR (return_date IS NOT NULL AND status = 'Returned'));

INSERT INTO r3_upgrade_log (item, result)
SELECT 'Fines whose status disagrees with their payments',
       COALESCE(GROUP_CONCAT(fine_no SEPARATOR ', '), 'none')
FROM FINE_SUMMARY
WHERE status <> CASE WHEN total_paid = 0 THEN 'Unpaid' WHEN balance > 0 THEN 'Partially Paid' ELSE 'Paid' END;

INSERT INTO r3_upgrade_log (item, result)
SELECT 'Members with a phone that is not 10 digits',
       COALESCE(GROUP_CONCAT(member_id SEPARATOR ', '), 'none')
FROM MEMBER WHERE phone IS NOT NULL AND phone NOT REGEXP '^[0-9]{10}$';

INSERT INTO r3_upgrade_log (item, result)
SELECT 'Rows after upgrade (must equal the rows before)',
       CONCAT('books ', (SELECT COUNT(*) FROM BOOK), ', members ', (SELECT COUNT(*) FROM MEMBER),
              ', copies ', (SELECT COUNT(*) FROM BOOK_COPY), ', loans ', (SELECT COUNT(*) FROM LOAN),
              ', fines ', (SELECT COUNT(*) FROM FINE), ', payments ', (SELECT COUNT(*) FROM PAYMENT));

DROP PROCEDURE IF EXISTS r3_add_check;
DROP PROCEDURE IF EXISTS r3_add_index;

-- The upgrade report: read every line.
SELECT item, result FROM r3_upgrade_log ORDER BY step_no;

-- ---------------------------------------------------------------------
-- STEP 6. OPTIONAL data corrections - NOT run automatically.
--   These change existing rows, so they are commented out. Read the
--   STEP 5 report first; if you agree, remove the leading "-- " from the
--   lines you want and run only those lines.
-- ---------------------------------------------------------------------

-- 6a. A copy that is out on an open loan should say Borrowed
--     (in the Review 2 sample data this is copy 101-2, on loan 508):
-- UPDATE BOOK_COPY bc
--    SET bc.status = 'Borrowed'
--  WHERE bc.status <> 'Borrowed'
--    AND EXISTS (SELECT 1 FROM LOAN l WHERE l.book_id = bc.book_id
--                AND l.copy_no = bc.copy_no AND l.return_date IS NULL);

-- 6b. The Review 2 sample data credits "The C Programming Language" (book 108)
--     to Andrew S. Tanenbaum; its real authors are Kernighan and Ritchie.
--     Run these four statements together only if your book 108 is that book:
-- INSERT INTO AUTHOR (author_id, full_name, nationality, birth_date)
--   SELECT COALESCE(MAX(author_id), 0) + 1, 'Brian W. Kernighan', 'Canadian', '1942-01-30' FROM AUTHOR;
-- INSERT INTO AUTHOR (author_id, full_name, nationality, birth_date)
--   SELECT COALESCE(MAX(author_id), 0) + 1, 'Dennis M. Ritchie', 'American', '1941-09-09' FROM AUTHOR;
-- UPDATE BOOK_AUTHOR SET author_id = (SELECT author_id FROM AUTHOR WHERE full_name = 'Brian W. Kernighan')
--  WHERE book_id = 108 AND author_id = (SELECT author_id FROM AUTHOR WHERE full_name = 'Andrew S. Tanenbaum');
-- INSERT INTO BOOK_AUTHOR (book_id, author_id)
--   SELECT 108, author_id FROM AUTHOR WHERE full_name = 'Dennis M. Ritchie';
