<?php
// ============================================================
// DATABASE CONFIGURATION — LINUX PRODUCTION SERVER
// Copy this file to db.php and fill in the real credentials
// ============================================================

date_default_timezone_set('Africa/Addis_Ababa');

$host   = 'localhost';
$dbname = 'osuethio_scanbarcode';
$user   = 'CHANGE_ME';           // MySQL username created on Linux server
$pass   = 'CHANGE_ME';     // MySQL password — change after first login

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Set MySQL session timezone to Ethiopia (GMT+3)
    $pdo->exec("SET time_zone = '+03:00'");
    // Ensure UTF-8 multibyte for Amharic/Oromoo text
    $pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_general_ci'");

} catch (PDOException $e) {
    // Log the actual error (not shown to user)
    error_log('[OSUScan DB Error] ' . date('Y-m-d H:i:s') . ' — ' . $e->getMessage());

    // Return JSON if this is an API request, otherwise die cleanly
    if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed. Please contact the system administrator.']);
    } else {
        http_response_code(500);
        die('<!DOCTYPE html><html><head><title>Service Unavailable</title></head><body style="font-family:sans-serif;text-align:center;padding:50px;"><h2>⚠️ Service Temporarily Unavailable</h2><p>Database connection failed. Please contact the system administrator.</p></body></html>');
    }
    exit;
}
?>
