<?php
/**
 * API Initialization — Linux Production
 * Loads database session handler and database connection.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

// Load database session handler (also loads db.php internally)
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../sessions.php';
}
?>
