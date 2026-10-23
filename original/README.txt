LIBRARY MANAGEMENT SYSTEM - REVIEW 3 (working prototype)
Database Systems BACSE202
=========================================================

PHP + MySQL web application on the LibraryDB database from Review 2.
CRUD and search on every table, book issue / return / renew with automatic
late fines, reservations, fines and payments, reports, 25 SQL queries and 58
automated test cases.

Tested here on PHP 8.3 + MySQL 8.0.46 (and MariaDB 10.11), on an exact copy of
the Review 2 database: 58/58 automated tests and a 27-step browser walkthrough
pass. Not yet tested on your own Mac - do the "Check it works" step below.


=========================================================
THE EASY WAY: ONE DOUBLE-CLICK
=========================================================
  Mac:      double-click  "Start Library.command"
  Windows:  double-click  "Start Library.bat"   (needs XAMPP installed)

  It installs PHP if needed (asks first), starts MySQL, asks your MySQL
  password once, BACKS UP and safely upgrades your existing LibraryDB (or
  creates it if this computer has none), runs the 58 tests, starts the
  backend and opens library.html in your browser.

  library.html is the whole interface in ONE file. It reads and changes the
  real MySQL database through api.php. While the launcher window is open you
  can also just double-click library.html. (The older multi-page version is
  still there at http://127.0.0.1:8000/index.php.) Keep its window open while you use
  the website; close the window to stop. Double-click again any time.

  Mac, first time only: if macOS says it "cannot be opened" or "Apple could
  not verify" it, right-click the file > Open > Open. Or in Terminal:
      bash "Start Library.command"

  Backups it makes go into the backups/ folder. Restore one with:
      mysql -u root -p LibraryDB < backups/<file name>.sql

  The rest of this file is the manual way, if you want to do the steps yourself.


---------------------------------------------------------
0. WHERE TO TYPE EACH COMMAND
---------------------------------------------------------
  [Terminal]  VS Code: menu Terminal > New Terminal. Type the line, press Enter.
  [MySQL]     MySQL Workbench query tab (or the mysql> prompt in a terminal).
  [Browser]   Safari or Chrome address bar.

Lines starting with # are explanations - do not type them.


---------------------------------------------------------
1. ONE-TIME SETUP ON macOS
---------------------------------------------------------

1a. Homebrew (the Mac package installer). Check if you have it:      [Terminal]
      brew --version
    If you see "command not found", install it (it asks for your Mac password):
      /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
    When it finishes it prints "Next steps" with two lines starting with
    echo / eval. Copy and run those two lines, then close and reopen the terminal.

1b. PHP. Your Mac said "zsh: command not found: php", so install it:  [Terminal]
      brew install php
      php --version
    You should now see "PHP 8.x". (pdo_mysql, which this project needs, is included.)

1c. MySQL. Your LibraryDB already lives in MySQL. Check the client:   [Terminal]
      mysql --version
    - If it prints a version: good.
    - If "command not found" and you installed MySQL from mysql.com (the .dmg):
        echo 'export PATH="/usr/local/mysql/bin:$PATH"' >> ~/.zprofile
        source ~/.zprofile
        mysql --version
    - If you have no MySQL at all:
        brew install mysql
        brew services start mysql
      (then follow "BRAND-NEW DATABASE" in step 3 instead of the upgrade.)

    Start the MySQL server if it is not running:
    - Installed from mysql.com: System Settings > MySQL > Start MySQL Server.
    - Installed with Homebrew:  brew services start mysql


---------------------------------------------------------
2. PUT THE NEW FILES INTO YOUR PROJECT FOLDER (keeps a backup)
---------------------------------------------------------
    In the terminal, go to the folder that CONTAINS library_management, e.g.:
      cd ~/Desktop                        # change to wherever your folder is
      cp -R library_management library_management_backup_review2
      unzip -o ~/Downloads/Library_Management_Review3_Final.zip
    unzip -o overwrites the files with the same names (books.php, db.php,
    index.php, loans.php, members.php, queries.php, style.css, README.txt) and
    adds the new ones. Nothing else is deleted. Your old version stays in
    library_management_backup_review2.


---------------------------------------------------------
3. UPGRADE YOUR EXISTING DATABASE (keeps all your data)
---------------------------------------------------------
    NEVER run LibraryDB_final.sql on your existing database - it resets it.

    3a. Back up the database first:                                   [Terminal]
          mysqldump -u root -p --routines --triggers LibraryDB > ~/Desktop/LibraryDB_backup.sql
        (type your MySQL password when asked; nothing is shown while typing)

    3b. Run the upgrade:                                              [Terminal]
          cd library_management
          mysql -u root -p LibraryDB < database/LibraryDB_review3_upgrade.sql
        or open database/LibraryDB_review3_upgrade.sql in MySQL Workbench and
        press the lightning bolt.                                         [MySQL]

        It prints a report. Check that "Rows before upgrade" and "Rows after
        upgrade" are identical. It only ADDS views, procedures, triggers, four
        CHECK constraints and an index; it never deletes or changes your rows,
        and it is safe to run twice. If a CHECK line says SKIPPED, some of your
        own rows break that rule; the report names them.

        Optional fixes for two mistakes in the Review 2 sample data (copy
        101-2 shown Available while on loan 508; book 108 credited to the wrong
        author) are at the end of the upgrade file, commented out. Run them
        only if you want those rows corrected.

    BRAND-NEW DATABASE (only on a computer that has no LibraryDB yet):
          mysql -u root -p < database/LibraryDB_final.sql


---------------------------------------------------------
4. TELL THE APP YOUR MYSQL PASSWORD
---------------------------------------------------------
                                                                      [Terminal]
      cp config.local.example.php config.local.php
      code config.local.php
    Replace your-mysql-password with your real MySQL root password, save.
    (No password? Use define('DB_PASS', '');)
    All database settings live in db.php + config.local.php only. Do not
    submit or share config.local.php.


---------------------------------------------------------
5. RUN IT
---------------------------------------------------------
                                                                      [Terminal]
      cd library_management          # if you are not already inside it
      php -S localhost:8000
                                                                       [Browser]
      http://localhost:8000

    Leave the terminal open while you use the app. Ctrl + C stops it.


---------------------------------------------------------
6. CHECK IT WORKS ON YOUR MAC
---------------------------------------------------------
    Browser: open the "Tests" page and click "Run all 58 tests".
    Or in a second terminal tab:
      php tests/run_tests.php
    Expect "58 of 58 tests passed". The tests run inside a transaction and are
    rolled back, so they never change your data.


---------------------------------------------------------
7. PAGES
---------------------------------------------------------
  Dashboard     live counts, overdue loans, recent loans, most borrowed
  Books         add / edit / delete books with authors; search title, ISBN, author, category
  Copies        physical copies (weak entity), shelf, condition, status, branch
  Members       register / edit / delete; search; member page with history and fines
  Loans         issue (sp_issue_book), return, renew, delete; open / overdue / returned
  Returns       return desk: find a borrowed book, see the fine, return it
  Reservations  reserve a title, extend, cancel
  Fines         record payments, raise / edit / waive fines
  Catalogue     authors, publishers, categories, branches, staff, plans
  Reports       6 printable reports from live SQL (Print / save as PDF)
  SQL Queries   25 queries with their SQL and results (3 take your input)
  Tests         the 58 automated test cases


---------------------------------------------------------
8. TROUBLESHOOTING
---------------------------------------------------------
  "command not found: php"     -> step 1b
  "Access denied for user"     -> wrong password in config.local.php (step 4)
  "Unknown database LibraryDB" -> create it: step 3, BRAND-NEW DATABASE
  "Connection refused"         -> MySQL is not running (step 1c)
  "could not find driver"      -> brew reinstall php
  Port 8000 busy               -> php -S localhost:8080, open http://localhost:8080

Windows teammates: install XAMPP, copy the folder into C:\xampp\htdocs, start
Apache only (keep using the MySQL80 server you already have), do steps 3-4, and
open http://localhost/library_management/

More: DEMO_SCRIPT.md (demo order + viva answers), REVIEW3_STATUS.md (checklist).
