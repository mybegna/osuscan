<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
}

$lang_code = $_SESSION['lang'] ?? 'en';
$lang_file = __DIR__ . "/languages/{$lang_code}.php";

if (file_exists($lang_file)) {
    require_once $lang_file;
} else {
    require_once __DIR__ . "/languages/en.php";
}
?>