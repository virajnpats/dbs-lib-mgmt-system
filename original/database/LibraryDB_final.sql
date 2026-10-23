-- =====================================================================
-- LIBRARY MANAGEMENT SYSTEM - FINAL DATABASE (Review 3)
-- Database Systems BACSE202
-- Target: MySQL 8.0.16+  (also tested on MariaDB 10.4+ as shipped with XAMPP)
--
-- Run the WHOLE script in MySQL Workbench (lightning icon), the mysql
-- command line client, or the phpMyAdmin "SQL"/"Import" tab.
--
-- !!! FRESH INSTALL / RESET ONLY !!!
-- This script DROPS and recreates the 14 LibraryDB tables and reloads the sample
-- data, so anything you added yourself is lost. Use it only on a new computer or
-- when you deliberately want the sample data back.
-- To upgrade the database you ALREADY have, run LibraryDB_review3_upgrade.sql
-- instead: it keeps every row and only adds the Review 3 objects.
--
-- What changed since Review 2 (all changes keep the same ER model / 14 tables):
--   1. Script can be re-run safely (views, routines and triggers are dropped first).
--   2. Data fixes: copy (101, 2) is on open loan 508, so its status is now 'Borrowed';
--      book 108 (The C Programming Language) now has its real authors, Kernighan
--      and Ritchie (2 new AUTHOR rows), which also shows a book with two authors.
--   3. Extra CHECK constraints: loan status must agree with return_date,
--      member phone must be 10 digits, emails must look like emails.
--      Table-level CHECKs are now named (chk_...) so error messages say which rule failed.
--   4. Index on BOOK(title) for faster title search.
--   5. Four new views: BOOK_AVAILABILITY, FINE_SUMMARY, MEMBER_SUMMARY, OVERDUE_LOANS.
--   6. One stored function and four stored procedures (issue book, return book,
--      refresh statuses, refresh fine status).
--   7. Triggers that enforce the business rules listed as limitations in Review 2
--      (double loans, borrowing limit, inactive members, outstanding fines,
--      overpayment, fine status, copy status kept in step with loans,
--      duplicate reservations).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS LibraryDB;
USE LibraryDB;

-- ---------------------------------------------------------------------
-- 0. Clean up old objects (views and routines are not removed by DROP TABLE)
-- ---------------------------------------------------------------------
DROP VIEW IF EXISTS LOAN_DETAILS;
DROP VIEW IF EXISTS BOOK_AVAILABILITY;
DROP VIEW IF EXISTS FINE_SUMMARY;
DROP VIEW IF EXISTS MEMBER_SUMMARY;
DROP VIEW IF EXISTS OVERDUE_LOANS;
DROP FUNCTION IF EXISTS fn_open_loans;
DROP PROCEDURE IF EXISTS sp_issue_book;
DROP PROCEDURE IF EXISTS sp_return_book;
DROP PROCEDURE IF EXISTS sp_refresh_statuses;
DROP PROCEDURE IF EXISTS sp_refresh_fine_status;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS PAYMENT;
DROP TABLE IF EXISTS FINE;
DROP TABLE IF EXISTS LOAN;
DROP TABLE IF EXISTS RESERVATION;
DROP TABLE IF EXISTS BOOK_COPY;
DROP TABLE IF EXISTS BOOK_AUTHOR;
DROP TABLE IF EXISTS STAFF;
DROP TABLE IF EXISTS MEMBER;
DROP TABLE IF EXISTS BOOK;
DROP TABLE IF EXISTS AUTHOR;
DROP TABLE IF EXISTS CATEGORY;
DROP TABLE IF EXISTS PUBLISHER;
DROP TABLE IF EXISTS BRANCH;
DROP TABLE IF EXISTS MEMBERSHIP_PLAN;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. Tables (parents first)
-- ---------------------------------------------------------------------
CREATE TABLE MEMBERSHIP_PLAN (
  plan_id     INT PRIMARY KEY,
  plan_name   VARCHAR(50)  NOT NULL UNIQUE,
  max_books   INT          NOT NULL CHECK (max_books > 0),
  annual_fee  DECIMAL(8,2) NOT NULL CHECK (annual_fee >= 0),
  loan_days   INT          NOT NULL CHECK (loan_days > 0)
);

CREATE TABLE PUBLISHER (
  publisher_id INT PRIMARY KEY,
  name         VARCHAR(100) NOT NULL,
  website      VARCHAR(200),
  address      VARCHAR(200)
);

CREATE TABLE CATEGORY (
  category_id   INT PRIMARY KEY,
  category_name VARCHAR(60) NOT NULL UNIQUE,
  description   VARCHAR(255)
);

CREATE TABLE BRANCH (
  branch_id   INT PRIMARY KEY,
  branch_name VARCHAR(100) NOT NULL,
  phone       VARCHAR(20),
  address     VARCHAR(200)
);

CREATE TABLE AUTHOR (
  author_id   INT PRIMARY KEY,
  full_name   VARCHAR(100) NOT NULL,
  nationality VARCHAR(50),
  birth_date  DATE
);

CREATE TABLE BOOK (
  book_id      INT PRIMARY KEY,
  isbn         VARCHAR(20) UNIQUE,
  title        VARCHAR(200) NOT NULL,
  language     VARCHAR(40),
  publish_year INT,
  edition      VARCHAR(30),
  publisher_id INT,
  category_id  INT,
  FOREIGN KEY (publisher_id) REFERENCES PUBLISHER(publisher_id),
  FOREIGN KEY (category_id)  REFERENCES CATEGORY(category_id),
  CONSTRAINT chk_book_year CHECK (publish_year IS NULL OR publish_year BETWEEN 1000 AND 2100)
);

CREATE TABLE MEMBER (
  member_id     INT PRIMARY KEY,
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(100) UNIQUE,
  phone         VARCHAR(20),
  address       VARCHAR(200),
  status        VARCHAR(20) NOT NULL DEFAULT 'Active',
  date_of_birth DATE,
  join_date     DATE NOT NULL,
  plan_id       INT  NOT NULL,
  FOREIGN KEY (plan_id) REFERENCES MEMBERSHIP_PLAN(plan_id),
  CONSTRAINT chk_member_status CHECK (status IN ('Active', 'Inactive')),
  CONSTRAINT chk_member_phone CHECK (phone IS NULL OR phone REGEXP '^[0-9]{10}$'),
  CONSTRAINT chk_member_email CHECK (email IS NULL OR email LIKE '%_@_%._%')
);

CREATE TABLE STAFF (
  staff_id  INT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  role      VARCHAR(50),
  email     VARCHAR(100) UNIQUE,
  hire_date DATE,
  branch_id INT NOT NULL,
  FOREIGN KEY (branch_id) REFERENCES BRANCH(branch_id),
  CONSTRAINT chk_staff_email CHECK (email IS NULL OR email LIKE '%_@_%._%')
);

CREATE TABLE BOOK_AUTHOR (
  book_id   INT NOT NULL,
  author_id INT NOT NULL,
  PRIMARY KEY (book_id, author_id),
  FOREIGN KEY (book_id)   REFERENCES BOOK(book_id),
  FOREIGN KEY (author_id) REFERENCES AUTHOR(author_id)
);

-- Weak entity: copy_no is unique only within a particular book.
CREATE TABLE BOOK_COPY (
  book_id        INT NOT NULL,
  copy_no        INT NOT NULL,
  shelf_location VARCHAR(50),
  `condition`    VARCHAR(30),
  status         VARCHAR(20) NOT NULL DEFAULT 'Available',
  price          DECIMAL(8,2),
  branch_id      INT NOT NULL,
  PRIMARY KEY (book_id, copy_no),
  FOREIGN KEY (book_id)   REFERENCES BOOK(book_id),
  FOREIGN KEY (branch_id) REFERENCES BRANCH(branch_id),
  CONSTRAINT chk_copy_price CHECK (price IS NULL OR price >= 0),
  CONSTRAINT chk_copy_condition CHECK (`condition` IS NULL OR `condition` IN ('New','Good','Fair','Damaged')),
  CONSTRAINT chk_copy_status CHECK (status IN ('Available','Borrowed','Reserved','Maintenance'))
);

CREATE TABLE RESERVATION (
  reservation_id INT PRIMARY KEY,
  reserved_on    DATE NOT NULL,
  expires_on     DATE,
  status         VARCHAR(20) NOT NULL DEFAULT 'Pending',
  member_id      INT NOT NULL,
  book_id        INT NOT NULL,
  FOREIGN KEY (member_id) REFERENCES MEMBER(member_id),
  FOREIGN KEY (book_id)   REFERENCES BOOK(book_id),
  CONSTRAINT chk_reservation_status CHECK (status IN ('Pending','Fulfilled','Cancelled','Expired')),
  CONSTRAINT chk_reservation_dates CHECK (expires_on IS NULL OR expires_on >= reserved_on)
);

CREATE TABLE LOAN (
  loan_id     INT PRIMARY KEY,
  issue_date  DATE NOT NULL,
  due_date    DATE NOT NULL,
  return_date DATE,
  status      VARCHAR(20) NOT NULL DEFAULT 'Borrowed',
  member_id   INT NOT NULL,
  book_id     INT NOT NULL,
  copy_no     INT NOT NULL,
  staff_id    INT NOT NULL,
  FOREIGN KEY (member_id)         REFERENCES MEMBER(member_id),
  FOREIGN KEY (book_id, copy_no)  REFERENCES BOOK_COPY(book_id, copy_no),
  FOREIGN KEY (staff_id)          REFERENCES STAFF(staff_id),
  CONSTRAINT chk_loan_due CHECK (due_date >= issue_date),
  CONSTRAINT chk_loan_return CHECK (return_date IS NULL OR return_date >= issue_date),
  CONSTRAINT chk_loan_status CHECK (status IN ('Borrowed','Returned','Overdue')),
  -- New in Review 3: status must agree with return_date
  CONSTRAINT chk_loan_status_return CHECK ((return_date IS NULL AND status IN ('Borrowed','Overdue'))
      OR (return_date IS NOT NULL AND status = 'Returned'))
);

CREATE TABLE FINE (
  fine_no   INT PRIMARY KEY,
  amount    DECIMAL(8,2) NOT NULL CHECK (amount >= 0),
  reason    VARCHAR(200),
  status    VARCHAR(20) NOT NULL DEFAULT 'Unpaid',
  fine_date DATE NOT NULL,
  loan_id   INT NOT NULL UNIQUE,
  FOREIGN KEY (loan_id) REFERENCES LOAN(loan_id),
  CONSTRAINT chk_fine_status CHECK (status IN ('Unpaid','Partially Paid','Paid'))
);

CREATE TABLE PAYMENT (
  payment_no   INT PRIMARY KEY,
  amount_paid  DECIMAL(8,2) NOT NULL CHECK (amount_paid > 0),
  payment_date DATE NOT NULL,
  method       VARCHAR(30) NOT NULL,
  fine_no      INT NOT NULL,
  FOREIGN KEY (fine_no) REFERENCES FINE(fine_no),
  CONSTRAINT chk_payment_method CHECK (method IN ('Cash','Card','UPI','Online'))
);

-- Secondary index used by the title search in the application.
CREATE INDEX idx_book_title ON BOOK(title);

-- ---------------------------------------------------------------------
-- 2. Sample data (independent tables first). Same data as Review 2, except the
--    two fixes described in the header (copy 101-2 status and book 108 authors).
--    Triggers are created AFTER this section, so these rows load as-is.
-- ---------------------------------------------------------------------
INSERT INTO MEMBERSHIP_PLAN VALUES
(1,'Basic',2,300.00,7),
(2,'Standard',4,600.00,14),
(3,'Premium',6,1000.00,21);

INSERT INTO PUBLISHER VALUES
(1,'Penguin Random House','https://www.penguinrandomhouse.com','New York'),
(2,'HarperCollins','https://www.harpercollins.com','New York'),
(3,'Oxford University Press','https://global.oup.com','Oxford'),
(4,'Pearson','https://www.pearson.com','London');

INSERT INTO CATEGORY VALUES
(1,'Fiction','Fictional stories and novels'),
(2,'Science','Science and scientific concepts'),
(3,'Technology','Computing and technology'),
(4,'History','Historical works');

INSERT INTO BRANCH VALUES
(1,'Main Library','0416-2201000','Vellore Campus'),
(2,'Science Block Library','0416-2202000','Science Block'),
(3,'Technology Reading Room','0416-2203000','Technology Block');

INSERT INTO AUTHOR VALUES
(1,'George Orwell','British','1903-06-25'),
(2,'J. K. Rowling','British','1965-07-31'),
(3,'Yuval Noah Harari','Israeli','1976-02-24'),
(4,'Stephen Hawking','British','1942-01-08'),
(5,'Robert C. Martin','American','1952-12-05'),
(6,'Andrew S. Tanenbaum','American','1944-03-16'),
(7,'Jane Austen','British','1775-12-16'),
(8,'Brian W. Kernighan','Canadian','1942-01-30'),   -- added in Review 3
(9,'Dennis M. Ritchie','American','1941-09-09');    -- added in Review 3

INSERT INTO BOOK VALUES
(101,'9780451524935','1984','English',1949,'1st',1,1),
(102,'9780747532699','Harry Potter and the Philosophers Stone','English',1997,'1st',2,1),
(103,'9780062316097','Sapiens','English',2011,'1st',2,4),
(104,'9780553380163','A Brief History of Time','English',1988,'Updated',1,2),
(105,'9780132350884','Clean Code','English',2008,'1st',4,3),
(106,'9780132126953','Computer Networks','English',2010,'5th',3,3),
(107,'9780141439518','Pride and Prejudice','English',1813,'1st',1,1),
(108,'9780131103627','The C Programming Language','English',1988,'2nd',3,3),
(109,'9780199535569','A Brief Introduction to Science','English',2022,'1st',3,2),
(110,'9780134494166','Modern Software Design','English',2023,'1st',4,3);

-- Review 3 fix: The C Programming Language (108) is by Kernighan and Ritchie,
-- not Tanenbaum. It now also shows the M:N relationship with two authors.
INSERT INTO BOOK_AUTHOR VALUES
(101,1),(102,2),(103,3),(104,4),(105,5),
(106,6),(107,7),(108,8),(108,9),(109,4),(110,5);

INSERT INTO MEMBER VALUES
(201,'Aarav Sharma','aarav@example.com','9876500001','Vellore','Active','2005-03-14','2026-01-10',2),
(202,'Diya Patel','diya@example.com','9876500002','Katpadi','Active','2004-07-20','2026-01-15',3),
(203,'Rohan Kumar','rohan@example.com','9876500003','Vellore','Active','2005-11-02','2026-02-01',1),
(204,'Ananya Iyer','ananya@example.com','9876500004','Chennai','Active','2004-01-08','2026-02-11',2),
(205,'Kabir Mehta','kabir@example.com','9876500005','Vellore','Inactive','2003-09-12','2025-08-20',1),
(206,'Meera Nair','meera@example.com','9876500006','Bengaluru','Active','2005-05-25','2026-03-02',3),
(207,'Ishaan Rao','ishaan@example.com','9876500007','Hyderabad','Active','2004-12-17','2026-03-10',2),
(208,'Sara Thomas','sara@example.com','9876500008','Vellore','Active','2005-08-09','2026-04-01',1);

INSERT INTO STAFF VALUES
(301,'Kavitha Rao','Librarian','kavitha@library.example','2022-06-01',1),
(302,'Arjun Menon','Assistant Librarian','arjun@library.example','2023-01-15',1),
(303,'Nisha Verma','Librarian','nisha@library.example','2021-09-10',2),
(304,'Rahul Das','Library Assistant','rahul@library.example','2024-02-20',3);

INSERT INTO BOOK_COPY VALUES
(101,1,'A-01','Good','Borrowed',350.00,1),
(101,2,'A-02','Good','Borrowed',350.00,1),   -- fixed: on open loan 508
(102,1,'B-01','New','Borrowed',450.00,1),
(102,2,'B-02','Good','Available',450.00,2),
(103,1,'C-01','Good','Available',600.00,2),
(104,1,'S-01','Fair','Borrowed',400.00,2),
(105,1,'T-01','New','Borrowed',550.00,3),
(105,2,'T-02','Good','Available',550.00,3),
(106,1,'T-03','Good','Available',650.00,3),
(107,1,'A-03','Good','Available',300.00,1),
(108,1,'T-04','Good','Available',500.00,3),
(109,1,'S-02','New','Available',700.00,2),
(110,1,'T-05','New','Available',750.00,3);

INSERT INTO RESERVATION VALUES
(401,'2026-10-10','2026-10-20','Pending',203,101),
(402,'2026-10-11','2026-10-21','Pending',204,105),
(403,'2026-10-12','2026-10-22','Fulfilled',206,103),
(404,'2026-10-13','2026-10-23','Cancelled',208,102),
(405,'2026-10-14','2026-10-24','Pending',201,106);

INSERT INTO LOAN VALUES
(501,'2026-10-01','2026-10-15',NULL,'Overdue',201,101,1,301),
(502,'2026-10-03','2026-10-17',NULL,'Overdue',202,102,1,302),
(503,'2026-10-04','2026-10-18',NULL,'Overdue',204,104,1,303),
(504,'2026-10-08','2026-10-22',NULL,'Borrowed',206,105,1,304),
(505,'2026-09-01','2026-09-15','2026-09-14','Returned',203,106,1,303),
(506,'2026-09-05','2026-09-19','2026-09-18','Returned',207,101,1,301),
(507,'2026-09-10','2026-09-24','2026-09-23','Returned',208,102,1,302),
(508,'2026-10-09','2026-10-23',NULL,'Borrowed',207,101,2,301);

INSERT INTO FINE VALUES
(601,50.00,'Overdue return','Unpaid','2026-10-16',501),
(602,30.00,'Overdue return','Partially Paid','2026-10-17',502),
(603,20.00,'Overdue return','Paid','2026-10-18',503);

INSERT INTO PAYMENT VALUES
(701,10.00,'2026-10-18','UPI',602),
(702,20.00,'2026-10-18','Card',603),
(703,5.00,'2026-10-19','Cash',602);

-- ---------------------------------------------------------------------
-- 3. Views
-- ---------------------------------------------------------------------

-- 3.1 Review 2 view: loan details (five-table join, composite join on the weak entity)
CREATE VIEW LOAN_DETAILS AS
SELECT l.loan_id, m.member_id, m.full_name AS member_name,
       b.title AS book_title, bc.copy_no, s.full_name AS issued_by,
       l.issue_date, l.due_date, l.return_date, l.status
FROM LOAN l
JOIN MEMBER m     ON l.member_id = m.member_id
JOIN BOOK b       ON l.book_id = b.book_id
JOIN BOOK_COPY bc ON l.book_id = bc.book_id AND l.copy_no = bc.copy_no
JOIN STAFF s      ON l.staff_id = s.staff_id;

-- 3.2 Copies per title and how many can be issued now
CREATE VIEW BOOK_AVAILABILITY AS
SELECT b.book_id, b.title,
       COUNT(bc.copy_no) AS total_copies,
       COALESCE(SUM(bc.status = 'Available'), 0) AS available_copies
FROM BOOK b
LEFT JOIN BOOK_COPY bc ON bc.book_id = b.book_id
GROUP BY b.book_id, b.title;

-- 3.3 Fine amount, total paid and balance (derived, never stored)
CREATE VIEW FINE_SUMMARY AS
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
CREATE VIEW MEMBER_SUMMARY AS
SELECT m.member_id, m.full_name, m.status, mp.plan_name, mp.max_books, mp.loan_days,
       (SELECT COUNT(*) FROM LOAN l
         WHERE l.member_id = m.member_id AND l.return_date IS NULL) AS open_loans,
       (SELECT COALESCE(SUM(fs.balance), 0) FROM FINE_SUMMARY fs
         WHERE fs.member_id = m.member_id) AS outstanding_fines
FROM MEMBER m
JOIN MEMBERSHIP_PLAN mp ON mp.plan_id = m.plan_id;

-- 3.5 Open loans past their due date, with days overdue
CREATE VIEW OVERDUE_LOANS AS
SELECT l.loan_id, m.member_id, m.full_name AS member_name, m.phone,
       b.title AS book_title, l.copy_no, l.due_date,
       DATEDIFF(CURDATE(), l.due_date) AS days_overdue
FROM LOAN l
JOIN MEMBER m ON m.member_id = l.member_id
JOIN BOOK b   ON b.book_id = l.book_id
WHERE l.return_date IS NULL AND l.due_date < CURDATE();

-- ---------------------------------------------------------------------
-- 4. Stored function and procedures
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- 5. Triggers (business rules from the Review 2 limitations section)
-- ---------------------------------------------------------------------

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

-- ---------------------------------------------------------------------
-- 6. Important queries (Review 2 queries 1-15 + Review 3 queries 16-22)
--    The same queries are available on the "SQL Queries" page of the app.
-- ---------------------------------------------------------------------
-- 1. SELECT
SELECT * FROM BOOK;

-- 2. WHERE and ORDER BY
SELECT title, publish_year FROM BOOK WHERE publish_year > 2020 ORDER BY title ASC;

-- 3. COUNT and GROUP BY
SELECT c.category_name, COUNT(*) AS total_books
FROM BOOK b JOIN CATEGORY c ON b.category_id = c.category_id
GROUP BY c.category_id, c.category_name;

-- 4. HAVING
SELECT c.category_name, COUNT(*) AS total_books
FROM BOOK b JOIN CATEGORY c ON b.category_id = c.category_id
GROUP BY c.category_id, c.category_name
HAVING COUNT(*) > 2;

-- 5. AVG
SELECT br.branch_name, AVG(bc.price) AS average_copy_price
FROM BOOK_COPY bc JOIN BRANCH br ON bc.branch_id = br.branch_id
GROUP BY br.branch_id, br.branch_name;

-- 6. JOIN
SELECT b.title, p.name AS publisher FROM BOOK b JOIN PUBLISHER p ON b.publisher_id = p.publisher_id;

-- 7. View query
SELECT * FROM LOAN_DETAILS;

-- 8. Subquery
SELECT title, publish_year FROM BOOK WHERE publish_year > (SELECT AVG(publish_year) FROM BOOK);

-- 9. EXISTS subquery
SELECT m.member_id, m.full_name FROM MEMBER m
WHERE EXISTS (SELECT 1 FROM LOAN l WHERE l.member_id = m.member_id);

-- 10. Fine totals and payments
SELECT f.fine_no, f.amount AS fine_amount, COALESCE(SUM(p.amount_paid), 0) AS total_paid
FROM FINE f LEFT JOIN PAYMENT p ON f.fine_no = p.fine_no
GROUP BY f.fine_no, f.amount;

-- 11. Derived age
SELECT member_id, full_name, TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) AS age
FROM MEMBER WHERE date_of_birth IS NOT NULL;

-- 12. Available copies
SELECT b.title, bc.copy_no, br.branch_name, bc.shelf_location
FROM BOOK_COPY bc JOIN BOOK b ON bc.book_id = b.book_id
JOIN BRANCH br ON bc.branch_id = br.branch_id
WHERE bc.status = 'Available' ORDER BY b.title;

-- 13. Loans handled by each staff member
SELECT s.full_name, COUNT(l.loan_id) AS loans_handled
FROM STAFF s LEFT JOIN LOAN l ON s.staff_id = l.staff_id
GROUP BY s.staff_id, s.full_name ORDER BY loans_handled DESC;

-- 14. Reservations with member and book
SELECT r.reservation_id, m.full_name, b.title, r.reserved_on, r.expires_on, r.status
FROM RESERVATION r JOIN MEMBER m ON r.member_id = m.member_id
JOIN BOOK b ON r.book_id = b.book_id;

-- 15. Payments associated with fines
SELECT f.fine_no, f.amount, p.payment_no, p.amount_paid, p.payment_date, p.method
FROM FINE f LEFT JOIN PAYMENT p ON f.fine_no = p.fine_no
ORDER BY f.fine_no, p.payment_date;

-- 16. Overdue loans (view) - most overdue first
SELECT * FROM OVERDUE_LOANS ORDER BY days_overdue DESC;

-- 17. Availability of every title (view)
SELECT * FROM BOOK_AVAILABILITY ORDER BY title;

-- 18. Members with an outstanding fine balance (view + WHERE)
SELECT member_id, member_name, SUM(balance) AS total_due
FROM FINE_SUMMARY GROUP BY member_id, member_name HAVING SUM(balance) > 0;

-- 19. Most borrowed titles (GROUP BY + ORDER BY + LIMIT)
SELECT b.title, COUNT(*) AS times_borrowed
FROM LOAN l JOIN BOOK b ON b.book_id = l.book_id
GROUP BY b.book_id, b.title ORDER BY times_borrowed DESC, b.title LIMIT 5;

-- 20. Books with all their authors (M:N through BOOK_AUTHOR, GROUP_CONCAT)
SELECT b.title, GROUP_CONCAT(a.full_name ORDER BY a.full_name SEPARATOR ', ') AS authors
FROM BOOK b JOIN BOOK_AUTHOR ba ON ba.book_id = b.book_id
JOIN AUTHOR a ON a.author_id = ba.author_id
GROUP BY b.book_id, b.title;

-- 21. Members who have never borrowed (NOT EXISTS)
SELECT m.member_id, m.full_name FROM MEMBER m
WHERE NOT EXISTS (SELECT 1 FROM LOAN l WHERE l.member_id = m.member_id);

-- 22. Fine collection by payment method
SELECT method, COUNT(*) AS payments, SUM(amount_paid) AS collected
FROM PAYMENT GROUP BY method ORDER BY collected DESC;

-- ---------------------------------------------------------------------
-- 7. Final verification
-- ---------------------------------------------------------------------
SHOW TABLES;
SELECT COUNT(*) AS total_books   FROM BOOK;
SELECT COUNT(*) AS total_members FROM MEMBER;
SELECT COUNT(*) AS total_loans   FROM LOAN;
SELECT table_type, COUNT(*) AS objects
FROM information_schema.tables WHERE table_schema = 'LibraryDB'
GROUP BY table_type;
SELECT trigger_name, event_manipulation, event_object_table, action_timing
FROM information_schema.triggers WHERE trigger_schema = 'LibraryDB'
ORDER BY event_object_table, trigger_name;
SELECT routine_name, routine_type
FROM information_schema.routines WHERE routine_schema = 'LibraryDB';
