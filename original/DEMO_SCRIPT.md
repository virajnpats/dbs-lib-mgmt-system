# Review 3 — Demonstration Script and Viva Preparation

About 12 minutes of demo, then questions. Each member presents one part: the review rules
say every member must take part and understand the whole project.

## Before the review (5 minutes)

1. Start MySQL, then in the project folder run `php -S localhost:8000`
   (Windows teammates: start Apache in XAMPP).
2. Open <http://localhost:8000> and keep MySQL Workbench open on `LibraryDB`.
3. Open the **Tests** page and run the tests once: you should see 58 of 58 passed.
4. Zoom the browser to 110–125% so the panel can read it. Close other tabs.

**Do not reset the database before the demo.** The steps below add a few new records
(a publisher, an author, a book, a member, a loan, a payment, a reservation). That is fine.
If you rehearse more than once, the second run gives "duplicate" errors for the same names;
use a different book title and email each time, or restore your backup
(`mysql -u root -p LibraryDB < ~/Desktop/LibraryDB_backup.sql`).

Fines grow by Rs. 5 per day, so read the amounts from the screen rather than memorising them.

---

## Part 1 — Database and architecture (Viraj Patil, ~3 min)

1. **Workbench:** run
   `SHOW FULL TABLES;` → 14 base tables + 5 views, then
   `SELECT trigger_name, event_object_table FROM information_schema.triggers WHERE trigger_schema='LibraryDB';` → 14 triggers.
2. **One-sentence architecture:** "The browser talks to PHP pages; PHP connects through PDO with
   prepared statements; MySQL stores the data and enforces the rules with constraints and triggers."
3. Open `db.php` in VS Code: one PDO connection, exceptions on errors, prepared statements,
   settings in one place (password in `config.local.php`).
4. **Dashboard:** every number is a live query. Overdue loans come from the view `OVERDUE_LOANS`;
   `sp_refresh_statuses()` marks late loans Overdue when the page opens.
5. What changed since Review 2: no table changed; we added 4 views, 1 function, 4 procedures,
   14 triggers and 4 CHECK constraints, all solving the limitations we listed in Review 2.

## Part 2 — CRUD, search and validation (Neeharika V Rao, ~4 min)

1. **Catalogue → Publishers:** add *McGraw Hill Education*. **Authors:** add *Abraham Silberschatz*.
2. **Books — validation:** leave the title empty, type ISBN `9780078022158` (wrong check digit) and
   year `3020`, click *Add book*. The browser stops it first (layer 1). Explain that PHP checks again
   on the server (layer 2) and the database checks a third time (layer 3).
3. **Books — create:** *Database System Concepts*, ISBN `978-0-07-802215-9`, year 2019, edition 7th,
   publisher McGraw Hill, category Technology, author Silberschatz. The app moves to **Copies**:
   add a copy on shelf `T-06`, branch Technology Reading Room. Copy number 1 is assigned per book
   (BOOK_COPY is a weak entity).
4. **Search:** on Books type `Tanenbaum` → *Computer Networks* (the search goes through the M:N table
   BOOK_AUTHOR). Show the category filter and "only with an available copy".
5. **Members:** register *Priya Sharma* (valid email, 10-digit phone, plan Standard). Register another
   member with the same email → "Duplicate value" (UNIQUE constraint).
6. **Update / delete:** edit the new book's edition (update). Try to delete *1984* → refused because
   copies still use it (FOREIGN KEY). Add a category *Poetry* and delete it (delete).

## Part 3 — Loans, returns, fines, reports, queries, tests (Siddhanth Narayan, ~5 min)

1. **Loans → Issue:** Priya + *Database System Concepts* + Kavitha Rao → message with the due date
   (issue date + 14 days for the Standard plan). The copy is now Borrowed.
2. **Business rule:** issue *Sapiens* to **Aarav Sharma** → refused: "Member has unpaid fines".
   That message comes from the trigger `trg_loan_before_insert` (SIGNAL SQLSTATE '45000').
3. **Returns:** search `Diya` → loan 502, overdue, with the fine it would carry → *Return* →
   "Returned late: fine of Rs. … recorded". `sp_return_book` set the return date, the trigger freed
   the copy, and the fine was raised.
4. **Fines:** try to pay more than Diya's balance → refused. Pay the exact balance by UPI →
   the fine becomes **Paid** automatically (payment trigger).
5. **Reservations:** reserve *Clean Code* for Rohan Kumar with no expiry → expiry = today + 7 (trigger).
   Reserve it again → refused (one pending reservation per title).
6. **Member page:** open Priya → profile, books held 1 of 4, loan history.
7. **Reports:** overdue list, stock by branch, member activity; click *Show SQL* under one;
   *Print / save as PDF*.
8. **SQL Queries:** query 20 (books with all their authors, GROUP_CONCAT), 18 (HAVING on a view),
   24 (parameterised: Technology, year 2005, *Run query*).
9. **Tests:** *Run all 58 tests* → 58 of 58 passed. The suite runs in a transaction and is rolled back,
   so it never changes the data.

---

## Likely viva questions (with short answers)

**Why PHP and PDO?** PHP runs with no build step; PDO gives prepared statements (protection from SQL
injection) and exceptions for error handling.

**What is a prepared statement?** SQL sent with `?` placeholders; the values travel separately, so input
such as `' OR '1'='1` is treated as text (test TC19 proves this).

**Where is input validated?** Three places: HTML5 attributes in the browser, PHP in
`includes/validation.php`, and the database (NOT NULL, UNIQUE, FK, CHECK, triggers). The browser check
can be switched off, so the PHP and database checks are the real protection.

**What is a trigger? Give one from your project.** SQL that runs automatically BEFORE or AFTER an
INSERT, UPDATE or DELETE. `trg_loan_before_insert` checks that the member is active, the copy is
available, the plan limit is not exceeded and there are no unpaid fines.

**Procedure vs function?** A function returns one value and is used inside SQL (`fn_open_loans(member_id)`);
a procedure is run with CALL, can have OUT parameters and several statements (`sp_issue_book`, `sp_return_book`).

**What is SIGNAL SQLSTATE '45000'?** It raises a user-defined error from a trigger or procedure; the
statement is cancelled and PHP receives error 1644 with the message, which the page shows.

**Why is BOOK_COPY a weak entity?** A copy cannot be identified without its book: copy 1 of *1984* and
copy 1 of *Sapiens* both have copy_no 1. Its key is (book_id, copy_no), and LOAN references it with a
composite foreign key.

**How is the M:N Book–Author relationship stored?** In the junction table BOOK_AUTHOR with the
composite primary key (book_id, author_id).

**Why is FINE.loan_id UNIQUE?** It makes LOAN–FINE a 1 : 0..1 relationship: a loan has at most one fine.

**Why is age not stored?** It is a derived attribute; a stored age would be wrong after every birthday.
It is calculated with `TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE())`.

**WHERE vs HAVING?** WHERE filters rows before grouping; HAVING filters groups after GROUP BY
(queries 4 and 18).

**What does a view give you?** A saved SELECT used like a table. LOAN_DETAILS hides a five-table join;
FINE_SUMMARY computes the balance so it is never stored and never out of date.

**How did you upgrade the database without losing data?** `LibraryDB_review3_upgrade.sql` only adds
objects. Each CHECK constraint is added only after confirming no existing row breaks it, and the script
prints the row counts before and after so we can see nothing changed.

**What if two librarians add a member at the same moment?** IDs are MAX(id)+1, so one save could
collide; the PRIMARY KEY rejects it and the user retries. AUTO_INCREMENT would remove this (listed as
a limitation).

**How is BOOK_COPY.status kept correct?** Only through loans: the AFTER INSERT trigger on LOAN sets
Borrowed, returning sets Available, and `trg_copy_before_update` refuses manual changes that contradict
an open loan.

**What is a transaction and where do you use one?** Statements that succeed or fail together (COMMIT /
ROLLBACK). Adding a book writes BOOK and BOOK_AUTHOR in one transaction; issue and return run in
transactions; the test suite rolls everything back.

**ACID?** Atomicity (all or nothing), Consistency (constraints hold), Isolation (concurrent transactions
do not interfere), Durability (committed data survives a crash).

**Normal form?** Third normal form: every non-key column depends on the whole key and only the key —
publisher details live in PUBLISHER, not repeated in BOOK.

**How is a late fine calculated?** (return date − due date) × Rs. 5, inside `sp_return_book`. If an
interim fine already existed it is raised to the final amount.

**What is CSRF and how do you prevent it?** A forged form submission from another site. Every form
carries a random token stored in the session; a POST without it is rejected.
