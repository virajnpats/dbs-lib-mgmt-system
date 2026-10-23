<?php
/*
 * validation.php - server-side input validation.
 *
 * Validation happens in three layers:
 *   1. Browser  : HTML5 attributes (required, type="email", min, max, pattern).
 *   2. PHP      : the functions in this file (cannot be bypassed by editing the form).
 *   3. Database : NOT NULL, UNIQUE, FOREIGN KEY, CHECK constraints and triggers.
 *
 * Each validate_xxx() function returns [cleanData, errors]. If errors is empty,
 * cleanData is safe to pass to a prepared statement.
 */

class Validator
{
    /** @var array<string,mixed> */
    private $input;
    /** @var string[] */
    public $errors = [];

    public function __construct(array $input)
    {
        $this->input = $input;
    }

    private function raw(string $key): string
    {
        $v = $this->input[$key] ?? '';
        return is_array($v) ? '' : trim((string)$v);
    }

    /** Free text. Returns null for an empty optional field. */
    public function text(string $key, string $label, bool $required = false, int $max = 255,
                         ?string $pattern = null, string $patternMessage = ''): ?string
    {
        $v = $this->raw($key);
        if ($v === '') {
            if ($required) {
                $this->errors[] = "$label is required.";
            }
            return null;
        }
        if (mb_strlen($v) > $max) {
            $this->errors[] = "$label must be at most $max characters.";
        }
        if ($pattern !== null && !preg_match($pattern, $v)) {
            $this->errors[] = $patternMessage !== '' ? $patternMessage : "$label has an invalid format.";
        }
        return $v;
    }

    public function int(string $key, string $label, bool $required = false, ?int $min = null, ?int $max = null): ?int
    {
        $v = $this->raw($key);
        if ($v === '') {
            if ($required) {
                $this->errors[] = "$label is required.";
            }
            return null;
        }
        if (!preg_match('/^-?\d+$/', $v)) {
            $this->errors[] = "$label must be a whole number.";
            return null;
        }
        $n = (int)$v;
        if ($min !== null && $n < $min) {
            $this->errors[] = "$label must be at least $min.";
        }
        if ($max !== null && $n > $max) {
            $this->errors[] = "$label must be at most $max.";
        }
        return $n;
    }

    public function decimal(string $key, string $label, bool $required = false, ?float $min = null,
                            ?float $max = null, bool $minExclusive = false): ?string
    {
        $v = $this->raw($key);
        if ($v === '') {
            if ($required) {
                $this->errors[] = "$label is required.";
            }
            return null;
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $v)) {
            $this->errors[] = "$label must be a positive amount with at most 2 decimal places.";
            return null;
        }
        $n = (float)$v;
        if ($min !== null && ($minExclusive ? $n <= $min : $n < $min)) {
            $this->errors[] = $minExclusive ? "$label must be greater than $min." : "$label must be at least $min.";
        }
        if ($max !== null && $n > $max) {
            $this->errors[] = "$label must be at most " . number_format($max, 2, '.', '') . '.';
        }
        return number_format($n, 2, '.', '');
    }

    /** Date in YYYY-MM-DD. $notFuture rejects dates after today. */
    public function date(string $key, string $label, bool $required = false, bool $notFuture = false): ?string
    {
        $v = $this->raw($key);
        if ($v === '') {
            if ($required) {
                $this->errors[] = "$label is required.";
            }
            return null;
        }
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            $this->errors[] = "$label must be a valid date (YYYY-MM-DD).";
            return null;
        }
        if ($notFuture && $v > date('Y-m-d')) {
            $this->errors[] = "$label cannot be in the future.";
        }
        return $v;
    }

    public function email(string $key, string $label, bool $required = false): ?string
    {
        $v = $this->text($key, $label, $required, 100);
        if ($v !== null && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = "$label must be a valid email address (e.g. name@example.com).";
        }
        return $v === null ? null : strtolower($v);
    }

    public function url(string $key, string $label, bool $required = false): ?string
    {
        $v = $this->text($key, $label, $required, 200);
        if ($v !== null && !preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $v)) {
            $this->errors[] = "$label must start with http:// or https://";
        }
        return $v;
    }

    public function enum(string $key, string $label, array $allowed, bool $required = true): ?string
    {
        $v = $this->raw($key);
        if ($v === '') {
            if ($required) {
                $this->errors[] = "$label is required.";
            }
            return null;
        }
        if (!in_array($v, $allowed, true)) {
            $this->errors[] = "$label must be one of: " . implode(', ', $allowed) . '.';
            return null;
        }
        return $v;
    }

    /** Array of positive integer IDs (e.g. a multi-select). */
    public function idList(string $key, string $label, bool $required = false): array
    {
        $v = $this->input[$key] ?? [];
        if (!is_array($v)) {
            $v = [$v];
        }
        $ids = [];
        foreach ($v as $item) {
            if (preg_match('/^\d+$/', (string)$item)) {
                $ids[] = (int)$item;
            } elseif ((string)$item !== '') {
                $this->errors[] = "$label contains an invalid value.";
            }
        }
        $ids = array_values(array_unique($ids));
        if ($required && !$ids) {
            $this->errors[] = "Select at least one $label.";
        }
        return $ids;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function ok(): bool
    {
        return !$this->errors;
    }
}

const NAME_PATTERN   = "/^[A-Za-z][A-Za-z .'\-]*$/";
const NAME_MESSAGE   = 'Name may contain only letters, spaces, dots, hyphens and apostrophes.';
const COPY_CONDITIONS = ['New', 'Good', 'Fair', 'Damaged'];
const COPY_STATUSES   = ['Available', 'Borrowed', 'Reserved', 'Maintenance'];
const MEMBER_STATUSES = ['Active', 'Inactive'];
const PAYMENT_METHODS = ['Cash', 'Card', 'UPI', 'Online'];

/** ISBN-10 or ISBN-13 with a correct check digit. Hyphens/spaces are ignored. */
function isbn_is_valid(string $isbn): bool
{
    $s = strtoupper(str_replace(['-', ' '], '', $isbn));
    if (preg_match('/^\d{13}$/', $s)) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$s[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        return (10 - $sum % 10) % 10 === (int)$s[12];
    }
    if (preg_match('/^\d{9}[\dX]$/', $s)) {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = $s[$i] === 'X' ? 10 : (int)$s[$i];
            $sum += $digit * (10 - $i);
        }
        return $sum % 11 === 0;
    }
    return false;
}

function validate_book(array $in): array
{
    $v = new Validator($in);
    $d = [];
    $d['title']        = $v->text('title', 'Title', true, 200);
    $isbn              = $v->text('isbn', 'ISBN', false, 20);
    if ($isbn !== null) {
        if (!isbn_is_valid($isbn)) {
            $v->addError('ISBN must be a valid 10- or 13-digit ISBN (check digit is verified).');
        }
        $isbn = strtoupper(str_replace(['-', ' '], '', $isbn));
    }
    $d['isbn']         = $isbn;
    $d['language']     = $v->text('language', 'Language', false, 40, '/^[A-Za-z ]+$/', 'Language may contain only letters.');
    $d['publish_year'] = $v->int('publish_year', 'Publish year', false, 1000, (int)date('Y') + 1);
    $d['edition']      = $v->text('edition', 'Edition', false, 30);
    $d['publisher_id'] = $v->int('publisher_id', 'Publisher', false, 1);
    $d['category_id']  = $v->int('category_id', 'Category', true, 1);
    $d['authors']      = $v->idList('authors', 'author', true);
    return [$d, $v->errors];
}

function validate_member(array $in): array
{
    $v = new Validator($in);
    $d = [];
    $d['full_name']     = $v->text('full_name', 'Full name', true, 100, NAME_PATTERN, NAME_MESSAGE);
    if ($d['full_name'] !== null && mb_strlen($d['full_name']) < 2) {
        $v->addError('Full name must be at least 2 characters.');
    }
    $d['email']         = $v->email('email', 'Email', true);
    $d['phone']         = $v->text('phone', 'Phone', false, 10, '/^[0-9]{10}$/', 'Phone must be exactly 10 digits.');
    $d['address']       = $v->text('address', 'Address', false, 200);
    $d['status']        = $v->enum('status', 'Status', MEMBER_STATUSES);
    $d['date_of_birth'] = $v->date('date_of_birth', 'Date of birth', false, true);
    $d['join_date']     = $v->date('join_date', 'Join date', true, true);
    $d['plan_id']       = $v->int('plan_id', 'Membership plan', true, 1);

    if ($d['date_of_birth'] !== null && $d['date_of_birth'] <= date('Y-m-d')) {
        $age = (new DateTime($d['date_of_birth']))->diff(new DateTime('today'))->y;
        if ($age < 5 || $age > 120) {
            $v->addError('Member age must be between 5 and 120 years.');
        }
        if ($d['join_date'] !== null && $d['join_date'] < $d['date_of_birth']) {
            $v->addError('Join date cannot be before the date of birth.');
        }
    }
    return [$d, $v->errors];
}

function validate_copy(array $in): array
{
    $v = new Validator($in);
    $d = [];
    $d['book_id']        = $v->int('book_id', 'Book', true, 1);
    $d['shelf_location'] = $v->text('shelf_location', 'Shelf location', true, 50, '/^[A-Za-z0-9\- ]+$/',
                                    'Shelf location may contain only letters, digits and hyphens (e.g. A-01).');
    $d['condition']      = $v->enum('condition', 'Condition', COPY_CONDITIONS);
    $d['status']         = $v->enum('status', 'Status', COPY_STATUSES);
    $d['price']          = $v->decimal('price', 'Price', false, 0, 100000);
    $d['branch_id']      = $v->int('branch_id', 'Branch', true, 1);
    if ($d['shelf_location'] !== null) {
        $d['shelf_location'] = strtoupper($d['shelf_location']);
    }
    return [$d, $v->errors];
}

/** Issue form: copy is posted as "bookId-copyNo". */
function validate_issue(array $in): array
{
    $v = new Validator($in);
    $d = [];
    $d['member_id']  = $v->int('member_id', 'Member', true, 1);
    $copy            = $v->text('copy', 'Book copy', true, 30, '/^\d+-\d+$/', 'Select a valid book copy.');
    $d['staff_id']   = $v->int('staff_id', 'Issued by (staff)', true, 1);
    $d['issue_date'] = $v->date('issue_date', 'Issue date', true, true);
    $d['book_id'] = $d['copy_no'] = null;
    if ($copy !== null && preg_match('/^(\d+)-(\d+)$/', $copy, $m)) {
        $d['book_id'] = (int)$m[1];
        $d['copy_no'] = (int)$m[2];
    }
    if ($d['issue_date'] !== null && $d['issue_date'] < date('Y-m-d', strtotime('-30 days'))) {
        $v->addError('Issue date cannot be more than 30 days in the past.');
    }
    return [$d, $v->errors];
}

function validate_return(array $in): array
{
    $v = new Validator($in);
    $d = [];
    $d['loan_id']     = $v->int('loan_id', 'Loan', true, 1);
    $d['return_date'] = $v->date('return_date', 'Return date', true, true);
    return [$d, $v->errors];
}

function validate_reservation(array $in): array
{
    $v = new Validator($in);
    $d = [];
    $d['member_id']   = $v->int('member_id', 'Member', true, 1);
    $d['book_id']     = $v->int('book_id', 'Book', true, 1);
    $d['reserved_on'] = $v->date('reserved_on', 'Reserved on', true, true);
    $d['expires_on']  = $v->date('expires_on', 'Expires on', false);
    if ($d['reserved_on'] && $d['expires_on']) {
        if ($d['expires_on'] < $d['reserved_on']) {
            $v->addError('Expiry date cannot be before the reservation date.');
        } elseif ($d['expires_on'] > date('Y-m-d', strtotime($d['reserved_on'] . ' +30 days'))) {
            $v->addError('A reservation can be held for at most 30 days.');
        }
    }
    return [$d, $v->errors];
}

function validate_fine(array $in, bool $isNew = true): array
{
    $v = new Validator($in);
    $d = [];
    if ($isNew) {
        $d['loan_id']   = $v->int('loan_id', 'Loan', true, 1);
        $d['fine_date'] = $v->date('fine_date', 'Fine date', true, true);
    }
    $d['amount'] = $v->decimal('amount', 'Fine amount', true, 0, 10000, true);
    $d['reason'] = $v->text('reason', 'Reason', true, 200);
    return [$d, $v->errors];
}

function validate_payment(array $in): array
{
    $v = new Validator($in);
    $d = [];
    $d['fine_no']      = $v->int('fine_no', 'Fine', true, 1);
    $d['amount_paid']  = $v->decimal('amount_paid', 'Amount paid', true, 0, 10000, true);
    $d['payment_date'] = $v->date('payment_date', 'Payment date', true, true);
    $d['method']       = $v->enum('method', 'Payment method', PAYMENT_METHODS);
    return [$d, $v->errors];
}
