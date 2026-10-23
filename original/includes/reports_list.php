<?php
/*
 * reports_list.php - the management reports (used by reports.php and api.php).
 * 'params' => ['fine_per_day'] means the query takes FINE_PER_DAY as its ? value.
 */

const REPORTS = [
    'overdue' => [
        'title' => 'Overdue loans',
        'about' => 'Members to contact today, most overdue first, with the fine due if the book came back today.',
        'sql'   => "SELECT o.loan_id AS loan, o.member_name AS member, o.phone, o.book_title AS book,
       o.due_date, o.days_overdue, o.days_overdue * ? AS fine_if_returned_today
FROM OVERDUE_LOANS o
ORDER BY o.days_overdue DESC",
        'params' => ['fine_per_day'],
    ],
    'branches' => [
        'title' => 'Stock by branch',
        'about' => 'How many copies each branch holds, how many are on the shelf, and their total value.',
        'sql'   => "SELECT br.branch_name AS branch,
       COUNT(bc.copy_no) AS copies,
       SUM(bc.status = 'Available') AS available,
       SUM(bc.status = 'Borrowed') AS borrowed,
       SUM(bc.status IN ('Reserved', 'Maintenance')) AS reserved_or_maintenance,
       COALESCE(SUM(bc.price), 0) AS stock_value_rs
FROM BRANCH br
LEFT JOIN BOOK_COPY bc ON bc.branch_id = br.branch_id
GROUP BY br.branch_id, br.branch_name
ORDER BY br.branch_name",
    ],
    'categories' => [
        'title' => 'Usage by category',
        'about' => 'Titles, copies and total loans per category: which parts of the collection are used most.',
        'sql'   => "SELECT c.category_name AS category,
       COUNT(DISTINCT b.book_id) AS titles,
       COUNT(DISTINCT CONCAT(bc.book_id, '-', bc.copy_no)) AS copies,
       COUNT(DISTINCT l.loan_id) AS times_borrowed
FROM CATEGORY c
LEFT JOIN BOOK b       ON b.category_id = c.category_id
LEFT JOIN BOOK_COPY bc ON bc.book_id = b.book_id
LEFT JOIN LOAN l       ON l.book_id = b.book_id
GROUP BY c.category_id, c.category_name
ORDER BY times_borrowed DESC, category",
    ],
    'members' => [
        'title' => 'Member activity',
        'about' => 'Every member with their total loans, books held now against the plan limit, and fines owed.',
        'sql'   => "SELECT s.member_id, s.full_name AS member, s.status, s.plan_name AS plan,
       (SELECT COUNT(*) FROM LOAN l WHERE l.member_id = s.member_id) AS total_loans,
       CONCAT(s.open_loans, ' / ', s.max_books) AS holding_vs_limit,
       s.outstanding_fines AS fines_due_rs
FROM MEMBER_SUMMARY s
ORDER BY total_loans DESC, member",
    ],
    'fines' => [
        'title' => 'Fine collection',
        'about' => 'Money collected by payment method. Outstanding balances are in the summary above.',
        'sql'   => "SELECT method, COUNT(*) AS payments, SUM(amount_paid) AS collected_rs
FROM PAYMENT
GROUP BY method
ORDER BY collected_rs DESC",
    ],
    'monthly' => [
        'title' => 'Loans per month',
        'about' => 'Circulation trend: loans issued and returned in each month.',
        'sql'   => "SELECT DATE_FORMAT(issue_date, '%Y-%m') AS month,
       COUNT(*) AS loans_issued,
       SUM(return_date IS NOT NULL) AS returned_so_far
FROM LOAN
GROUP BY DATE_FORMAT(issue_date, '%Y-%m')
ORDER BY month DESC",
    ],
];
