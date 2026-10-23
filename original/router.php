<?php
/*
 * router.php - used by the Start Library launchers with PHP's built-in web server
 * (php -S 127.0.0.1:8000 router.php). It blocks folders that must never be served
 * (database scripts, backups, tests, includes, local settings) and serves the rest.
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (preg_match('#^/(backups|database|includes|tests)(/|$)#i', $path)
    || preg_match('#^/(config\.local\.php|setup\.php|router\.php|.*\.(sql|md|txt|csv|command|bat))$#i', $path)) {
    http_response_code(403);
    echo 'Not available.';
    return true;
}
if ($path === '/') {               // the single-page interface is the front door
    header('Location: /library.html');
    return true;
}
return false;   // let the built-in server handle the request normally
