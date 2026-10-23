<?php
/*
 * setup.php - one-time (and every-launch) setup, run by the Start Library launchers.
 * You never need to run it yourself, but you can:   php setup.php
 *
 * What it does, in order:
 *   1. Connects to MySQL (asks for your password the first time, saves it in config.local.php).
 *   2. If LibraryDB does not exist yet  -> creates it with the sample data.
 *      If LibraryDB exists but is not upgraded -> BACKS IT UP to backups/, then runs the
 *      safe Review 3 upgrade (adds views / procedures / triggers; never deletes your data).
 *      If it is already upgraded       -> leaves it alone.
 *   3. Runs the 58 automated tests (inside a transaction that is rolled back).
 *
 * Exit codes: 0 = ready, 1 = stopped with a message, 3 = MySQL server not running.
 * "php setup.php --free-port" only prints a free port number for the web server.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const ROOT = __DIR__;
const CONFIG_FILE = ROOT . '/config.local.php';
const UPGRADE_SQL = ROOT . '/database/LibraryDB_review3_upgrade.sql';
const FRESH_SQL = ROOT . '/database/LibraryDB_final.sql';
const PROJECT_TABLES = ['MEMBERSHIP_PLAN', 'PUBLISHER', 'CATEGORY', 'BRANCH', 'AUTHOR', 'BOOK', 'BOOK_AUTHOR',
                        'MEMBER', 'STAFF', 'BOOK_COPY', 'RESERVATION', 'LOAN', 'FINE', 'PAYMENT'];

/* ---------------- small helpers ---------------- */

function say(string $msg = ''): void { fwrite(STDOUT, $msg . PHP_EOL); }
function step(string $msg): void { say(''); say('==> ' . $msg); }
function ok(string $msg): void { say('    OK  ' . $msg); }
function stop(string $msg, int $code = 1): void
{
    say('');
    say('!!  ' . str_replace("\n", "\n    ", $msg));
    exit($code);
}

function ask(string $question, string $default = '', bool $hidden = false): string
{
    fwrite(STDOUT, $question . ($default !== '' ? " [$default]" : '') . ': ');
    $canHide = $hidden && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && posix_isatty_safe();
    if ($canHide) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $line = fgets(STDIN);
    if ($canHide) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }
    if ($line === false) {
        stop('No answer could be read (is this running without a keyboard?).');
    }
    $line = rtrim($line, "\r\n");
    return $line === '' ? $default : $line;
}

function posix_isatty_safe(): bool
{
    return function_exists('posix_isatty') ? @posix_isatty(STDIN) : true;
}

/** Free TCP port for the web server, starting at 8000. */
function free_port(int $start = 8000): int
{
    for ($p = $start; $p < $start + 50; $p++) {
        $s = @stream_socket_server("tcp://127.0.0.1:$p", $errno, $errstr);
        if ($s) {
            fclose($s);
            return $p;
        }
    }
    return $start;
}

if (in_array('--free-port', $argv, true)) {
    echo free_port();
    exit(0);
}

/* ---------------- settings ---------------- */

function load_settings(): array
{
    $s = ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'LibraryDB', 'user' => 'root', 'pass' => '', 'saved' => false];
    if (is_file(CONFIG_FILE)) {
        // Read the define() lines without executing anything else.
        $text = file_get_contents(CONFIG_FILE);
        $map = ['DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASS' => 'pass'];
        foreach ($map as $const => $key) {
            if (preg_match("/define\\(\\s*'$const'\\s*,\\s*('(?:[^'\\\\]|\\\\.)*'|\\d+)\\s*\\)/", $text, $m)) {
                $v = $m[1];
                $s[$key] = $v[0] === "'" ? stripcslashes(substr($v, 1, -1)) : (int)$v;
            }
        }
        $s['saved'] = true;
    }
    return $s;
}

function save_settings(array $s): void
{
    $php = "<?php\n/* Written by setup.php - your local MySQL settings. Do not share or submit this file. */\n"
         . "define('DB_HOST', " . var_export($s['host'], true) . ");\n"
         . "define('DB_PORT', " . (int)$s['port'] . ");\n"
         . "define('DB_NAME', " . var_export($s['name'], true) . ");\n"
         . "define('DB_USER', " . var_export($s['user'], true) . ");\n"
         . "define('DB_PASS', " . var_export($s['pass'], true) . ");\n";
    if (file_put_contents(CONFIG_FILE, $php) === false) {
        stop('Could not write config.local.php. Is the project folder read-only?');
    }
    @chmod(CONFIG_FILE, 0600);
}

/** Connect to the MySQL SERVER (no database selected). Throws PDOException. */
function server_connect(array $s): PDO
{
    $pdo = new PDO("mysql:host={$s['host']};port={$s['port']};charset=utf8mb4", $s['user'], $s['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,   // plain text queries: needed for CREATE PROCEDURE / TRIGGER
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec("SET time_zone = '+05:30'");
    return $pdo;
}

/* ---------------- running .sql files (handles DELIMITER) ---------------- */

/** Remove a trailing "-- comment" from a line, ignoring "--" inside quotes. */
function strip_line_comment(string $line): string
{
    $quote = null;
    $len = strlen($line);
    for ($i = 0; $i < $len; $i++) {
        $c = $line[$i];
        if ($quote !== null) {
            if ($c === '\\') { $i++; continue; }
            if ($c === $quote) { $quote = null; }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; continue; }
        if ($c === '-' && $i + 1 < $len && $line[$i + 1] === '-' && ($i + 2 >= $len || ctype_space($line[$i + 2]))) {
            return rtrim(substr($line, 0, $i));
        }
        if ($c === '#') {
            return rtrim(substr($line, 0, $i));
        }
    }
    return $line;
}

/** Split a MySQL script into statements, honouring DELIMITER lines. */
function split_sql(string $sql): array
{
    $statements = [];
    $delimiter = ';';
    $buffer = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
            $delimiter = $m[1];
            continue;
        }
        $line = strip_line_comment($line);
        if (trim($line) === '') {
            continue;
        }
        $buffer .= $line . "\n";
        $trimmed = rtrim($buffer);
        if (substr($trimmed, -strlen($delimiter)) === $delimiter) {
            $stmt = trim(substr($trimmed, 0, -strlen($delimiter)));
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

/**
 * Run every statement of a script on one connection. Returns the last result set.
 * $dbName replaces the database name in the script's CREATE DATABASE / USE lines,
 * so an existing database spelled differently (e.g. librarydb) is the one used.
 */
function run_sql_file(PDO $pdo, string $file, string $dbName = 'LibraryDB'): array
{
    $last = [];
    foreach (split_sql(file_get_contents($file)) as $n => $stmt) {
        if (preg_match('/^(CREATE DATABASE IF NOT EXISTS|USE)\s+`?LibraryDB`?$/i', $stmt, $m)) {
            $stmt = $m[1] . ' `' . str_replace('`', '', $dbName) . '`';
        }
        try {
            $q = $pdo->query($stmt);
            do {
                if ($q->columnCount() > 0) {
                    $last = $q->fetchAll();
                }
            } while ($q->nextRowset());
            $q->closeCursor();
        } catch (PDOException $e) {
            stop("A statement in " . basename($file) . " failed:\n" . $e->getMessage()
                 . "\n\nStatement:\n" . substr($stmt, 0, 400));
        }
    }
    return $last;
}

/* ---------------- backup (data + table definitions), PHP only ---------------- */

function backup_database(PDO $pdo, string $db): string
{
    $dir = ROOT . '/backups';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        stop('Could not create the backups folder, so the upgrade was NOT run.');
    }
    $file = $dir . '/' . $db . '_backup_' . date('Ymd_His') . '.sql';
    $out = fopen($file, 'w');
    if (!$out) {
        stop('Could not write the backup file, so the upgrade was NOT run.');
    }
    fwrite($out, "-- Backup of $db made by setup.php on " . date('Y-m-d H:i:s') . "\n"
               . "-- Restore (replaces these tables):  mysql -u root -p $db < " . basename($file) . "\n"
               . "SET FOREIGN_KEY_CHECKS = 0;\n\n");
    $tables = $pdo->query("SELECT table_name AS t FROM information_schema.tables
                           WHERE table_schema = " . $pdo->quote($db) . " AND table_type = 'BASE TABLE'
                           ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    $rows = 0;
    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$db`.`$t`")->fetch(PDO::FETCH_NUM)[1];
        fwrite($out, "DROP TABLE IF EXISTS `$t`;\n$create;\n");
        $q = $pdo->query("SELECT * FROM `$db`.`$t`");
        while ($r = $q->fetch(PDO::FETCH_NUM)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r);
            fwrite($out, "INSERT INTO `$t` VALUES (" . implode(',', $vals) . ");\n");
            $rows++;
        }
        fwrite($out, "\n");
    }
    fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($out);
    if (!filesize($file)) {
        stop('The backup file is empty, so the upgrade was NOT run.');
    }
    return $file . "  (" . count($tables) . " tables, $rows rows)";
}

/* =====================================================================
 * MAIN
 * ===================================================================== */

say('Library Management System - setup');
say('---------------------------------');

if (!extension_loaded('pdo_mysql')) {
    stop("This PHP has no pdo_mysql extension.\nMac: brew reinstall php    Windows/XAMPP: enable extension=pdo_mysql in php.ini");
}

/* 1. Connect */
step('Connecting to MySQL');
$s = load_settings();
$pdo = null;
for ($attempt = 1; $attempt <= 4; $attempt++) {
    try {
        $pdo = server_connect($s);
        break;
    } catch (PDOException $e) {
        $code = (int)($e->errorInfo[1] ?? $e->getCode());
        if (in_array($code, [2002, 2003, 2006], true) || stripos($e->getMessage(), 'refused') !== false
            || stripos($e->getMessage(), 'No such file') !== false) {
            say('    MySQL is not running (or not on ' . $s['host'] . ':' . $s['port'] . ').');
            exit(3);
        }
        if ($code === 1045 || $code === 1698) {
            if ($attempt === 4) {
                stop("MySQL refused the user name / password 3 times.\nCheck them in MySQL Workbench, then double-click Start Library again.");
            }
            say($attempt === 1 && !$s['saved']
                ? '    MySQL needs your login (the same one you use in MySQL Workbench).'
                : '    That user name / password was not accepted. Try again.');
            $s['user'] = ask('    MySQL user name', $s['user'] ?: 'root');
            $s['pass'] = ask('    MySQL password (typing is hidden; just press Enter if you have none)', '', true);
            continue;
        }
        stop('Could not connect to MySQL: ' . $e->getMessage());
    }
}
$version = $pdo->query('SELECT VERSION()')->fetchColumn();
ok("connected as {$s['user']} to MySQL $version");
if (stripos($version, 'mariadb') === false && version_compare(preg_replace('/[^0-9.].*$/', '', $version), '8.0.16', '<')) {
    say('    Note: MySQL older than 8.0.16 does not enforce CHECK constraints. The app still works.');
}

/* 2. Find the database (names are case-insensitive on a Mac) */
step('Checking the LibraryDB database');
$dbName = $pdo->query("SELECT schema_name FROM information_schema.schemata
                       WHERE LOWER(schema_name) = 'librarydb' LIMIT 1")->fetchColumn();

if ($dbName === false) {
    say('    LibraryDB does not exist on this computer yet. Creating it with the sample data...');
    run_sql_file($pdo, FRESH_SQL);
    $dbName = 'LibraryDB';
    ok('LibraryDB created (14 tables, sample data, views, procedures, triggers)');
} else {
    $q = $pdo->query("SELECT table_name FROM information_schema.tables
                      WHERE table_schema = " . $pdo->quote($dbName) . " AND table_type = 'BASE TABLE'");
    $have = array_map('strtoupper', $q->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_diff(PROJECT_TABLES, $have);

    if (count($have) === 0) {
        say("    $dbName exists but is empty. Filling it with the project tables and sample data...");
        run_sql_file($pdo, FRESH_SQL, $dbName);
        ok('tables, sample data, views, procedures and triggers created');
    } elseif ($missing) {
        stop("$dbName exists but these project tables are missing: " . implode(', ', $missing)
             . "\nNothing was changed. Run your Review 2 script for the missing tables first.");
    } else {
        $upgraded = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers
                                      WHERE trigger_schema = " . $pdo->quote($dbName) . "
                                        AND trigger_name = 'trg_loan_before_insert'")->fetchColumn();
        $views = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.views
                                   WHERE table_schema = " . $pdo->quote($dbName) . "
                                     AND table_name IN ('BOOK_AVAILABILITY','FINE_SUMMARY','MEMBER_SUMMARY','OVERDUE_LOANS')")->fetchColumn();
        if ($upgraded && $views === 4) {
            ok("$dbName found with all 14 tables and already upgraded for Review 3 - left as it is");
        } else {
            say("    $dbName found with your existing data (all 14 tables). It needs the Review 3 upgrade.");
            say('    Backing it up first...');
            $backup = backup_database($pdo, $dbName);
            ok('backup saved: backups/' . basename(explode('  (', $backup)[0]) . '  (' . explode('  (', $backup)[1]);
            say('    Running the safe upgrade (adds objects only, never deletes or changes rows)...');
            $report = run_sql_file($pdo, UPGRADE_SQL, $dbName);
            foreach ($report as $r) {
                say(sprintf('      %-48s %s', $r['item'], $r['result']));
            }
            ok('upgrade finished');
        }
    }
}

/* 3. Save settings so the website uses the same login */
$s['name'] = $dbName;
save_settings($s);
ok('settings saved in config.local.php (used by the website)');

/* 4. Self-test */
step('Running the 58 automated tests (changes are rolled back)');
$php = escapeshellarg(PHP_BINARY);
$output = [];
exec("$php " . escapeshellarg(ROOT . '/tests/run_tests.php') . ' 2>&1', $output, $rc);
$summary = end($output) ?: '';
foreach ($output as $line) {
    if (strpos($line, 'FAIL') !== false) {
        say('    ' . $line);
    }
}
say('    ' . $summary);
if ($rc !== 0) {
    say('    Some tests failed. The website will still start; see the list above or open the Tests page.');
}

say('');
say('Setup complete.');
exit(0);
