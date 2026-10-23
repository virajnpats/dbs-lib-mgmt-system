<?php
/*
 * functions.php - helpers shared by every page:
 * output escaping, flash messages, CSRF protection, ID generation,
 * friendly database error messages and the page header / footer.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/validation.php';

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
 * Never show raw PHP errors to the librarian. Anything unexpected is written to
 * the PHP error log and the user sees a short, readable message instead.
 */
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    set_exception_handler(function (Throwable $e) {
        error_log('[Library] ' . $e);
        if (!headers_sent()) {
            http_response_code(500);
        }
        $msg = htmlspecialchars(db_error_message($e), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Something went wrong</title>'
           . '<link rel="stylesheet" href="style.css"></head><body><main class="narrow">'
           . '<h1>Something went wrong</h1><div class="flash error">' . $msg . '</div>'
           . '<p>Nothing was saved. <a href="index.php">Back to the dashboard</a></p></main></body></html>';
    });
}

/** Escape a value before printing it into HTML (prevents XSS). */
function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Format money as Rs. 1,234.50 */
function money($value): string
{
    return 'Rs. ' . number_format((float)$value, 2);
}

/* ---------- Flash messages (shown once after a redirect) ---------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_errors(array $errors): void
{
    foreach ($errors as $e) {
        flash('error', $e);
    }
}

function take_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/** Post/Redirect/Get: after a POST we redirect so refreshing the page does not resubmit. */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Keep what the user typed when a form fails validation. */
function remember_form(array $input): void
{
    $_SESSION['old_form'] = $input;
}

function old_form(): array
{
    $old = $_SESSION['old_form'] ?? [];
    unset($_SESSION['old_form']);
    return $old;
}

/* ---------- CSRF protection ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Every POST must carry the token issued with the form. */
function require_valid_post(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) {
        flash('error', 'Your session expired. Please submit the form again.');
        redirect($_SERVER['REQUEST_URI'] ?? 'index.php');
    }
}

/* ---------- Database helpers ---------- */

/**
 * Next primary key value = MAX(id) + 1 (IDs are plain INT keys, as designed in Review 2).
 * Table / column names come from code, never from the user.
 */
function next_id(string $table, string $column, int $start): int
{
    $sql = "SELECT COALESCE(MAX($column), " . ($start - 1) . ") + 1 FROM $table";
    return (int)db()->query($sql)->fetchColumn();
}

/** Run a SELECT with parameters and return all rows. */
function fetch_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function fetch_value(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function execute(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** "((a) or (b))" -> "(a) or (b)": removes parentheses that wrap the whole expression. */
function strip_outer_parens(string $expr): string
{
    $expr = trim($expr);
    while (strlen($expr) > 1 && $expr[0] === '(' && substr($expr, -1) === ')') {
        $depth = 0;
        for ($i = 0; $i < strlen($expr) - 1; $i++) {
            $depth += $expr[$i] === '(' ? 1 : ($expr[$i] === ')' ? -1 : 0);
            if ($depth === 0) {
                return $expr;           // the first "(" closes before the end: not a wrapper
            }
        }
        $expr = trim(substr($expr, 1, -1));
    }
    return $expr;
}

/** Readable messages for the named CHECK constraints in LibraryDB_final.sql */
const CHECK_MESSAGES = [
    'chk_book_year'          => 'Publish year must be between 1000 and 2100.',
    'chk_member_status'      => 'Member status must be Active or Inactive.',
    'chk_member_phone'       => 'Member phone must be exactly 10 digits.',
    'chk_member_email'       => 'Member email is not a valid address.',
    'chk_staff_email'        => 'Staff email is not a valid address.',
    'chk_copy_price'         => 'Copy price cannot be negative.',
    'chk_copy_condition'     => 'Copy condition must be New, Good, Fair or Damaged.',
    'chk_copy_status'        => 'Copy status must be Available, Borrowed, Reserved or Maintenance.',
    'chk_reservation_status' => 'Reservation status must be Pending, Fulfilled, Cancelled or Expired.',
    'chk_reservation_dates'  => 'Reservation expiry cannot be before the reservation date.',
    'chk_loan_due'           => 'Due date cannot be before the issue date.',
    'chk_loan_return'        => 'Return date cannot be before the issue date.',
    'chk_loan_status'        => 'Loan status must be Borrowed, Returned or Overdue.',
    'chk_loan_status_return' => 'Loan status does not match its return date.',
    'chk_fine_status'        => 'Fine status must be Unpaid, Partially Paid or Paid.',
    'chk_payment_method'     => 'Payment method must be Cash, Card, UPI or Online.',
];

/**
 * Translate a MySQL / MariaDB error into a message a librarian can understand.
 * Trigger errors (SIGNAL SQLSTATE '45000', code 1644) already carry a readable message.
 */
function db_error_message(Throwable $e): string
{
    if (!$e instanceof PDOException) {
        return $e->getMessage();
    }
    $code = (int)($e->errorInfo[1] ?? 0);
    $text = (string)($e->errorInfo[2] ?? $e->getMessage());

    switch ($code) {
        case 1644: // raised by our triggers / procedures
            return $text;
        case 1062: // duplicate UNIQUE / PRIMARY KEY
            if (preg_match("/Duplicate entry '(.*)' for key '(?:[^.']*\\.)?([^']*)'/", $text, $m)) {
                $field = str_replace(['_UNIQUE', 'PRIMARY'], ['', 'ID'], $m[2]);
                return "Duplicate value \"{$m[1]}\" for {$field}. This value must be unique.";
            }
            return 'Duplicate value: this record already exists.';
        case 1451: // delete/update parent row that is still referenced
            if (preg_match('/a foreign key constraint fails \(`[^`]*`\.`([^`]*)`/', $text, $m)) {
                return "Cannot delete or change this record because it is still used in {$m[1]}. Remove those {$m[1]} records first.";
            }
            return 'Cannot delete this record because other records still refer to it.';
        case 1452: // child row refers to a missing parent
            return 'The selected related record does not exist.';
        case 3819: // MySQL 8 CHECK constraint
        case 4025: // MariaDB CHECK constraint
            if (preg_match("/constraint [`']([^`']+)[`']/i", $text, $m)) {
                $name = $m[1];
                if (isset(CHECK_MESSAGES[$name])) {
                    return CHECK_MESSAGES[$name] . " (CHECK constraint $name)";
                }
                // Review 2 constraints have automatic names (e.g. MEMBER_chk_1): show the rule itself.
                try {
                    $rule = fetch_value('SELECT check_clause FROM information_schema.check_constraints
                                         WHERE constraint_schema = DATABASE() AND constraint_name = ?', [$name]);
                } catch (Throwable $ignored) {
                    $rule = false;
                }
                if ($rule) {
                    $rule = strip_outer_parens(str_replace(['`', '_utf8mb4', '_latin1', "\\'"], ['', '', '', "'"], $rule));
                    return "The value breaks the database rule: $rule (CHECK constraint $name)";
                }
            }
            return 'The database rejected a value that breaks a CHECK constraint (' . $text . ').';
        case 1048:
            return 'A required value is missing (' . $text . ').';
        case 1406:
            return 'A value is too long for its column (' . $text . ').';
        default:
            return 'Database error ' . $code . ': ' . $text;
    }
}

/* ---------- HTML helpers ---------- */

/** <option> list from rows. $selected may be a single value or an array. */
function options(array $rows, string $valueKey, string $labelKey, $selected = null, ?string $placeholder = null): string
{
    $html = $placeholder !== null ? '<option value="">' . h($placeholder) . '</option>' : '';
    $selectedValues = is_array($selected) ? array_map('strval', $selected) : [(string)$selected];
    foreach ($rows as $row) {
        $value = (string)$row[$valueKey];
        $isSel = in_array($value, $selectedValues, true) ? ' selected' : '';
        $html .= '<option value="' . h($value) . '"' . $isSel . '>' . h($row[$labelKey]) . '</option>';
    }
    return $html;
}

/** Simple options from a plain list of strings. */
function enum_options(array $values, $selected = null, ?string $placeholder = null): string
{
    $rows = array_map(fn($v) => ['v' => $v], $values);
    return options($rows, 'v', 'v', $selected, $placeholder);
}

function status_badge(?string $status): string
{
    if ($status === null || $status === '') {
        return '';
    }
    $class = strtolower(str_replace(' ', '-', $status));
    return '<span class="badge ' . h($class) . '">' . h($status) . '</span>';
}

/** Render rows as an HTML table (used by the SQL Queries and Test pages). */
function result_table(array $rows): string
{
    if (!$rows) {
        return '<p class="muted">No rows returned.</p>';
    }
    $html = '<div class="table-wrap"><table><thead><tr>';
    foreach (array_keys($rows[0]) as $col) {
        $html .= '<th>' . h($col) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $value) {
            $html .= '<td>' . ($value === null ? '<span class="null">NULL</span>' : h($value)) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div><p class="muted">' . count($rows) . ' row(s)</p>';
}

/* ---------- Layout ---------- */

const NAV_ITEMS = [
    'index.php'        => 'Dashboard',
    'books.php'        => 'Books',
    'copies.php'       => 'Copies',
    'members.php'      => 'Members',
    'loans.php'        => 'Loans',
    'returns.php'      => 'Returns',
    'reservations.php' => 'Reservations',
    'fines.php'        => 'Fines',
    'catalogue.php'    => 'Catalogue',
    'reports.php'      => 'Reports',
    'queries.php'      => 'SQL Queries',
    'tests.php'        => 'Tests',
];

function page_header(string $title): void
{
    $current = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    if ($current === 'member.php') {
        $current = 'members.php';
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' | Library Management System</title>';
    echo '<link rel="stylesheet" href="style.css"></head><body>';
    echo '<header class="topbar"><div class="brand"><span class="logo">LMS</span>';
    echo '<div><strong>Library Management System</strong><small>LibraryDB &middot; ' . h(date('d M Y')) . '</small></div></div>';
    echo '<nav>';
    foreach (NAV_ITEMS as $file => $label) {
        $active = $file === $current ? ' class="active"' : '';
        echo '<a href="' . $file . '"' . $active . '>' . h($label) . '</a>';
    }
    echo '</nav></header><main>';
    echo '<h1>' . h($title) . '</h1>';
    foreach (take_flashes() as $f) {
        echo '<div class="flash ' . h($f['type']) . '">' . h($f['message']) . '</div>';
    }
}

function page_footer(): void
{
    echo '</main><footer>Library Management System &middot; Database Systems (BACSE202) &middot; PHP + MySQL prototype</footer>';
    // After a redirect, make sure success / error messages are visible.
    echo "<script>window.addEventListener('load',function(){var f=document.querySelector('.flash');if(f){setTimeout(function(){f.scrollIntoView({block:'center'});},0);}});</script>";
    echo '</body></html>';
}
