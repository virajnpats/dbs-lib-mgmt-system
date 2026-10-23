# Review 3 — Project Status and Checklist

Library Management System · Database Systems BACSE202 · status as of 23 October 2026

## A. Project status

The prototype is complete and tested on an exact copy of the Review 2 database. It runs on PHP + MySQL,
connects to the existing `LibraryDB`, and covers every item in the Review 3 brief
(DATABASE_SYSTEMS_L39L40.pdf): simple UI, database connectivity, CRUD, search, important SQL through the
application, input validation, test cases, final database, final report and presentation.

The database is **upgraded in place**, not replaced: `database/LibraryDB_review3_upgrade.sql` adds
Review 3 objects and leaves every existing row untouched (verified by comparing a full data dump
before and after: identical).

**Not yet verified on your Mac.** Your computer was not reachable from this session, so the real
project folder and the real LibraryDB on your Mac have not been inspected or tested. Section F gives the
steps; section G says exactly what was and was not tested.

## B. Review 3 checklist

Source of requirements: DATABASE_SYSTEMS_L39L40.pdf (Review 3 section) plus the prompt you sent.
"Starter" = the Library_Management_Review3_Starter folder before this work.

| Requirement | In the starter | What was missing | Files | Done | Tested |
| --- | --- | --- | --- | --- | --- |
| Simple user interface, navigation | Basic pages, 5 nav links | Loans / returns / fines / reservations / reports pages, consistent layout, messages, empty states, mobile layout | all pages, `includes/functions.php`, `style.css` | Yes | Yes (browser, desktop + 390 px phone width) |
| Database connectivity | PDO in `db.php` | Friendly failure page, settings in one place, local password file, time zone | `db.php`, `config.local.example.php` | Yes | Yes (connected, wrong password, MySQL stopped) |
| Dashboard from real data | 4 counts | Available copies, open / overdue loans, pending reservations, fines due, recent loans, most borrowed | `index.php` | Yes | Yes |
| Book management (CRUD + authors) | Add / edit / delete | Authors (BOOK_AUTHOR), availability, ISBN check digit, safe delete message | `books.php` | Yes | Yes |
| Copies (stock / availability) | None | Whole page: copies per book are the "quantity" in this schema | `copies.php` | Yes | Yes |
| Member management | Add / edit / delete | Detail page with history and fines, plan limit, age, validation | `members.php`, `member.php` | Yes | Yes |
| Book borrowing | None (view only) | Issue form, all rule checks, due date from plan, copy status update | `loans.php`, `sp_issue_book`, LOAN triggers | Yes | Yes |
| Book returns | None | Return desk, overdue days, fine preview, return once only, copy freed | `returns.php`, `loans.php`, `sp_return_book` | Yes | Yes |
| Fines and payments (in the schema) | None | Pay, raise, edit, waive; no overpayment; status kept by triggers | `fines.php`, PAYMENT / FINE triggers | Yes | Yes |
| Reservations (in the schema) | None | Create, extend, cancel, delete; fulfilled automatically on issue | `reservations.php` | Yes | Yes |
| Master tables CRUD | None | Authors, publishers, categories, branches, staff, plans | `catalogue.php`, `includes/catalogue_config.php` | Yes | Yes |
| Search / filtering | Books, members, loans by text | Author, category, availability, status, plan, branch, overdue filters | every list page | Yes | Yes |
| Important SQL queries in the app | 8 queries | 25 queries with explanations, 3 parameterised | `queries.php`, `includes/queries_list.php` | Yes | Yes |
| Reports | None | 6 printable reports with their SQL | `reports.php` | Yes | Yes |
| Input validation | Title / year / email only | Three layers: HTML5, PHP rules for every form, DB constraints + triggers | `includes/validation.php` | Yes | Yes (14 PHP + 5 DB tests) |
| Error handling | Generic messages | Readable DB errors (duplicate, in use, rule broken), no raw PHP errors, CSRF | `includes/functions.php` | Yes | Yes |
| Test cases | None | 58 automated tests + 27-step browser test | `includes/test_suite.php`, `tests.php`, `tests/run_tests.php` | Yes | Yes |
| Final database | Review 2 script | Safe upgrade script; full script for a new computer | `database/` | Yes | Yes (MySQL 8) |
| Final project report | — | Docs report (exports to Word / PDF) | separate | Yes | Read through |
| Presentation | — | 15-slide deck with speaker notes | separate | Yes | — |

Partially complete: none of the Review 3 items. Remaining (outside the brief, listed as future work):
staff login, AUTO_INCREMENT keys, a nightly event for overdue status.

## C. Modified files (existed in the starter)

| File | What changed |
| --- | --- |
| `README.txt` | Rewritten: macOS setup, safe upgrade, run and troubleshooting steps |
| `db.php` | Same PDO connection and database name; settings can come from `config.local.php`; IST time zone; helpful failure page |
| `index.php` | Dashboard with 7 live counts, overdue loans, recent loans, most borrowed |
| `books.php` | Rewritten readably; authors, availability, ISBN check, filters, empty state, safe delete |
| `members.php` | Rewritten; validation, plan / loans / fines columns, filters, empty state |
| `loans.php` | From view-only to issue, return, renew, delete, filters |
| `queries.php` | From 8 to 25 queries with explanations; parameterised queries |
| `style.css` | New consistent look, badges, messages, responsive and print rules |

## D. New files

| File | Purpose |
| --- | --- |
| `copies.php`, `member.php`, `returns.php`, `reservations.php`, `fines.php`, `catalogue.php`, `reports.php`, `tests.php` | New pages |
| `includes/functions.php` | Layout (header / nav / footer), escaping, CSRF, messages, DB helpers, friendly errors |
| `includes/validation.php` | Server-side validation rules |
| `includes/catalogue_config.php` | Field rules for the master tables |
| `includes/queries_list.php` | The 25 SQL queries |
| `includes/test_suite.php` | The 58 test cases |
| `tests/run_tests.php`, `tests/test_results.csv` | Command-line test runner and its last results |
| `config.local.example.php` | Template for your local MySQL password |
| `database/LibraryDB_review3_upgrade.sql` | **Use this** on your existing database (non-destructive) |
| `database/LibraryDB_final.sql` | Full script for a computer with no LibraryDB (resets data) |
| `library.html` | The whole user interface in one file (all 13 screens); talks to MySQL through api.php |
| `api.php` | JSON backend for library.html: every read and CRUD action, same validation, procedures and triggers |
| `includes/reports_list.php` | Report definitions shared by reports.php and api.php |
| `Start Library.command` / `Start Library.bat` | One double-click: installs PHP if needed, starts MySQL, backs up + upgrades the database, runs the tests, starts the website and opens the browser |
| `setup.php`, `router.php` | What the launchers run: database setup / self-test, and a router that keeps backups, scripts and settings off the website |
| `.htaccess` in backups, database, includes, tests | Same protection when served by Apache (XAMPP) |
| `DEMO_SCRIPT.md` | Demo order for three presenters + viva answers |
| `REVIEW3_STATUS.md` | This file |

Nothing from the starter was deleted. `README.md`, which an earlier draft added, was folded back into
`README.txt`.

## E. Database connection

- Database: `LibraryDB` (MySQL 8.0.16+; MariaDB 10.4+ also works).
- Configuration: `db.php` (defaults: host 127.0.0.1, port 3306, user root, empty password),
  overridden by `config.local.php` if present. No other file holds credentials.
- You need: the MySQL user name and password you use in Workbench, with rights on LibraryDB.
- Connection: PDO, `ERRMODE_EXCEPTION`, real prepared statements (`EMULATE_PREPARES` off), utf8mb4,
  session time zone +05:30 so `CURDATE()` matches PHP's date.

Schema changes made by the upgrade, and why:

| Change | Why | Effect on data |
| --- | --- | --- |
| Views BOOK_AVAILABILITY, FINE_SUMMARY, MEMBER_SUMMARY, OVERDUE_LOANS (LOAN_DETAILS refreshed, same columns) | Availability, balances and limits are derived, never stored | None |
| fn_open_loans, sp_issue_book, sp_return_book, sp_refresh_statuses, sp_refresh_fine_status | Issue / return / housekeeping as single database operations | None until called |
| 14 triggers | Enforce the Review 2 limitations (double loans, limits, inactive members, fines, overpayment) | Checks future writes only |
| CHECKs chk_member_phone, chk_member_email, chk_staff_email, chk_loan_status_return | Stop bad contact data and status / return-date mismatches | Added only if all current rows pass; else skipped and reported |
| Index idx_book_title | Faster title search | None |

## F. Run instructions

**Easiest:** double-click `Start Library.command` (Mac) or `Start Library.bat` (Windows). Tested here end
to end on a copy of the Review 2 database (asked for the password, backed up, upgraded, 58/58 tests,
opened the website). The Windows file has not been run on Windows.

Manual way (Mac, VS Code terminal); full detail is in `README.txt`:

```
brew --version                     # if missing, install Homebrew (README step 1a)
brew install php
php --version                      # PHP 8.x
mysql --version                    # README step 1c if missing
mysqldump -u root -p --routines --triggers LibraryDB > ~/Desktop/LibraryDB_backup.sql
cd library_management
mysql -u root -p LibraryDB < database/LibraryDB_review3_upgrade.sql
cp config.local.example.php config.local.php     # then put your password in it
php -S localhost:8000
```

Then open <http://localhost:8000> in the browser. Start MySQL first: System Settings → MySQL (mysql.com
installer) or `brew services start mysql` (Homebrew).

## G. Testing results

Environment used: Ubuntu container, PHP 8.3.6, MySQL 8.0.46, Chromium; database = the Review 2 script
you attached, then the upgrade script applied.

| Area | Result |
| --- | --- |
| Upgrade script on the Review 2 database | Pass: 14 tables found, all objects added, row counts equal, full data dump identical before / after |
| Upgrade script run a second time | Pass: every step "already present", nothing changed |
| Upgrade when a row breaks a new rule (test member with 8-digit phone) | Pass: that CHECK skipped and the member reported; nothing else affected |
| Optional data fixes (end of upgrade file) | Pass when run by hand |
| Automated suite, 58 cases | **58 / 58 pass** (connectivity 1, CRUD 13, search + security 6, validation 14, DB constraints 5, business rules 19) |
| Browser walkthrough, 27 steps (add, validate, search, register, issue, refuse, return, pay, reserve, reports, queries, update, delete, empty search, run tests) | **27 / 27 pass** |
| Full fresh-install script, run twice | Pass |
| Error handling: wrong password, MySQL stopped, unknown table, POST without CSRF token | Pass: readable message, no raw PHP error, nothing written |
| Phone-width layout (390 px) | Checked by screenshot |
| MariaDB 10.11 | Earlier build: 58/58 and full walkthrough pass; the upgrade script itself was not run on MariaDB |

**Not tested:** your Mac (PHP install, your MySQL, your real data), Windows XAMPP, Safari. Run
`php tests/run_tests.php` on your Mac after setup: 58 of 58 is the target. If your own data breaks a new
CHECK, the upgrade report says which rows, and test TC37 will fail until those rows are corrected.

## H. Review 3 demonstration

The full script, split between the three presenters, is in `DEMO_SCRIPT.md`. The order:

1. Open <http://localhost:8000> (and Workbench showing the 14 tables, 5 views, 14 triggers).
2. Dashboard: live counts and overdue loans.
3. Add a publisher and an author (Catalogue), then a book with its author and a copy.
4. Show validation: bad ISBN / empty title rejected; duplicate email rejected.
5. Add a member.
6. Borrow: issue the new book to the new member; show a refused issue (member with unpaid fine).
7. Return: Returns page → return Diya's overdue loan → fine recorded; pay it on Fines → status Paid.
8. Search: books by author, members by email, loans by status.
9. Database records: member page (history), Reports page, Workbench `SELECT * FROM LOAN_DETAILS;`.
10. SQL queries: 20, 18, 24 on the SQL Queries page.
11. Tests page: run all 58.

## I. Final status

**Mostly ready — one step remains on your side.** All Review 3 features are built and pass every test
here on a copy of your Review 2 database. What is left is to install PHP on your Mac, run the upgrade
script on your real LibraryDB (after the backup), set your password and confirm 58 of 58 tests pass on
your machine. That takes about 15 minutes, and then the project is ready for Review 3.
