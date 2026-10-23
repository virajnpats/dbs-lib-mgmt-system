<?php
/*
 * db.php - database connectivity (PDO).
 * Every page includes this file once; it is the ONLY place the database
 * settings live. Set your password in config.local.php (recommended) or below.
 */

// Optional: put your own settings in config.local.php (copy config.local.example.php).
// That keeps your password out of this shared file. Anything not set there uses the default below.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', 3306);          // MySQL default port
defined('DB_NAME') || define('DB_NAME', 'LibraryDB');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');            // your MySQL root password (XAMPP default: blank)

const FINE_PER_DAY = 5.00;     // Rs. charged per day a book is returned late
const APP_TIMEZONE = 'Asia/Kolkata';

date_default_timezone_set(APP_TIMEZONE);

/**
 * Returns the shared PDO connection (created on first use).
 * - ERRMODE_EXCEPTION: every SQL error becomes a PDOException we can catch.
 * - EMULATE_PREPARES false: real server-side prepared statements (protects against SQL injection).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Make CURDATE() in SQL agree with PHP's date() (India Standard Time).
        $pdo->exec("SET time_zone = '+05:30'");
    }
    return $pdo;
}

try {
    $pdo = db();
} catch (PDOException $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database connection failed: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (defined('LIBRARY_API')) {        // api.php: answer in JSON for library.html
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'db_down' => true,
                          'errors' => ['Cannot connect to the database: ' . $e->getMessage()]]);
        exit;
    }
    http_response_code(500);
    $detail = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html><html><head><meta charset="utf-8"><title>Database connection failed</title>
<link rel="stylesheet" href="style.css"></head><body><main class="narrow">
<h1>Cannot connect to the database</h1>
<p class="flash error">$detail</p>
<ol>
  <li>Start MySQL. Mac: System Settings &rarr; MySQL &rarr; Start, or <code>brew services start mysql</code>.
      Windows: start the MySQL80 service (or MySQL in the XAMPP Control Panel).</li>
  <li>"Access denied": put your MySQL password in <code>config.local.php</code> (copy <code>config.local.example.php</code>).</li>
  <li>"Unknown database 'LibraryDB'": create it once with <code>database/LibraryDB_final.sql</code>.
      If LibraryDB already exists, never run that file; run <code>database/LibraryDB_review3_upgrade.sql</code> instead.</li>
  <li>"Connection refused": MySQL is not running, or it uses another port (<code>DB_PORT</code> in <code>db.php</code>).</li>
</ol>
</main></body></html>
HTML;
    exit;
}
