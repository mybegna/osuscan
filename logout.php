<?php
require_once 'sessions.php';
session_destroy();
setcookie('PHPSESSID', '', time() - 3600, '/');
header("Location: login");
exit;
?>
