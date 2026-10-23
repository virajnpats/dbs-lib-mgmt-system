<?php
/*
 * catalogue_config.php - describes the smaller "master" tables so one page
 * (catalogue.php) can provide Create / Read / Update / Delete for all of them.
 * Each field lists its type and validation rules.
 */

const CATALOGUE = [
    'authors' => [
        'title' => 'Authors', 'table' => 'AUTHOR', 'pk' => 'author_id', 'start' => 1, 'order' => 'full_name',
        'fields' => [
            'full_name'   => ['label' => 'Full name', 'type' => 'name', 'required' => true, 'max' => 100],
            'nationality' => ['label' => 'Nationality', 'type' => 'text', 'max' => 50,
                              'pattern' => '/^[A-Za-z ]+$/', 'pattern_msg' => 'Nationality may contain only letters.'],
            'birth_date'  => ['label' => 'Birth date', 'type' => 'date', 'not_future' => true],
        ],
    ],
    'publishers' => [
        'title' => 'Publishers', 'table' => 'PUBLISHER', 'pk' => 'publisher_id', 'start' => 1, 'order' => 'name',
        'fields' => [
            'name'    => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 100],
            'website' => ['label' => 'Website', 'type' => 'url'],
            'address' => ['label' => 'Address / city', 'type' => 'text', 'max' => 200],
        ],
    ],
    'categories' => [
        'title' => 'Categories', 'table' => 'CATEGORY', 'pk' => 'category_id', 'start' => 1, 'order' => 'category_name',
        'fields' => [
            'category_name' => ['label' => 'Category name', 'type' => 'text', 'required' => true, 'max' => 60],
            'description'   => ['label' => 'Description', 'type' => 'text', 'max' => 255],
        ],
    ],
    'branches' => [
        'title' => 'Branches', 'table' => 'BRANCH', 'pk' => 'branch_id', 'start' => 1, 'order' => 'branch_name',
        'fields' => [
            'branch_name' => ['label' => 'Branch name', 'type' => 'text', 'required' => true, 'max' => 100],
            'phone'       => ['label' => 'Phone', 'type' => 'text', 'max' => 20,
                              'pattern' => '/^[0-9][0-9\- ]{5,19}$/', 'pattern_msg' => 'Phone may contain only digits, spaces and hyphens (e.g. 0416-2201000).'],
            'address'     => ['label' => 'Address', 'type' => 'text', 'max' => 200],
        ],
    ],
    'staff' => [
        'title' => 'Staff', 'table' => 'STAFF', 'pk' => 'staff_id', 'start' => 301, 'order' => 'full_name',
        'fields' => [
            'full_name' => ['label' => 'Full name', 'type' => 'name', 'required' => true, 'max' => 100],
            'role'      => ['label' => 'Role', 'type' => 'enum', 'required' => true,
                            'values' => ['Librarian', 'Assistant Librarian', 'Library Assistant']],
            'email'     => ['label' => 'Email', 'type' => 'email', 'required' => true],
            'hire_date' => ['label' => 'Hire date', 'type' => 'date', 'required' => true, 'not_future' => true],
            'branch_id' => ['label' => 'Branch', 'type' => 'fk', 'required' => true,
                            'sql' => 'SELECT branch_id AS id, branch_name AS label FROM BRANCH ORDER BY branch_name'],
        ],
    ],
    'plans' => [
        'title' => 'Membership plans', 'table' => 'MEMBERSHIP_PLAN', 'pk' => 'plan_id', 'start' => 1, 'order' => 'plan_id',
        'fields' => [
            'plan_name'  => ['label' => 'Plan name', 'type' => 'text', 'required' => true, 'max' => 50],
            'max_books'  => ['label' => 'Max books', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 20],
            'annual_fee' => ['label' => 'Annual fee (Rs.)', 'type' => 'decimal', 'required' => true, 'min' => 0, 'max' => 100000],
            'loan_days'  => ['label' => 'Loan days', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 90],
        ],
    ],
];

/** Validate one catalogue record using its field rules. Returns [data, errors]. */
function validate_catalogue(string $key, array $input): array
{
    $cfg = CATALOGUE[$key];
    $v = new Validator($input);
    $data = [];
    foreach ($cfg['fields'] as $name => $f) {
        $req = !empty($f['required']);
        switch ($f['type']) {
            case 'name':
                $data[$name] = $v->text($name, $f['label'], $req, $f['max'] ?? 100, NAME_PATTERN, NAME_MESSAGE);
                break;
            case 'email':
                $data[$name] = $v->email($name, $f['label'], $req);
                break;
            case 'url':
                $data[$name] = $v->url($name, $f['label'], $req);
                break;
            case 'date':
                $data[$name] = $v->date($name, $f['label'], $req, !empty($f['not_future']));
                break;
            case 'int':
                $data[$name] = $v->int($name, $f['label'], $req, $f['min'] ?? null, $f['max'] ?? null);
                break;
            case 'decimal':
                $data[$name] = $v->decimal($name, $f['label'], $req, $f['min'] ?? null, $f['max'] ?? null);
                break;
            case 'enum':
                $data[$name] = $v->enum($name, $f['label'], $f['values'], $req);
                break;
            case 'fk':
                $data[$name] = $v->int($name, $f['label'], $req, 1);
                break;
            default:
                $data[$name] = $v->text($name, $f['label'], $req, $f['max'] ?? 255,
                                        $f['pattern'] ?? null, $f['pattern_msg'] ?? '');
        }
    }
    return [$data, $v->errors];
}
