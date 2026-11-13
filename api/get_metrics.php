<?php
/**
 * Metrics API
 * Returns live cafeteria statistics
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

date_default_timezone_set('Africa/Addis_Ababa');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

function sendJSON($data, $httpCode = 200) {
    ob_end_clean();
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    exit();
}

try {
    // Determine path to sessions.php. Since this file is in /api/, we go up one level.
    require_once __DIR__ . '/../sessions.php';
} catch (Throwable $e) {
    sendJSON(['success' => false, 'message' => 'Init failed'], 500);
}

if (!isset($pdo)) {
    sendJSON(['success' => false, 'message' => 'Database error'], 500);
}

// Optional: Check if user is logged in
if (empty($_SESSION['user_id'])) {
    sendJSON(['success' => false, 'message' => 'Auth required'], 401);
}

$campus_id = $_SESSION['campus_id'] ?? null;

// Calculate Meal Type
$current_time = date('H:i:s');
$meal_type = '';
try {

    $schQuery = "SELECT * FROM cafe_schedules WHERE is_active = 1";
    $schParams = [];
    if ($campus_id) {
        $schQuery .= " AND campus_id = ?";
        $schParams[] = $campus_id;
    }
    $sch_stmt = $pdo->prepare($schQuery);
    $sch_stmt->execute($schParams);
    while ($sch = $sch_stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($current_time >= $sch['start_time'] && $current_time <= $sch['end_time']) {
            $meal_type = $sch['meal_type'];
            break;
        }
    }
} catch (Exception $e) {}

// Calculate Metrics using CURDATE() for database-side date consistency
$metrics = ['total_today' => 0, 'current_meal' => 0, 'total_eligible' => 0];
try {
    // 1. Total meals served today - Use CURDATE()
    $sql = "SELECT COUNT(*) FROM meal_logs WHERE DATE(scan_time) = CURDATE()";
    $params = [];
    if ($campus_id) { $sql .= " AND campus_id = ?"; $params[] = $campus_id; }
    $m_stmt = $pdo->prepare($sql);
    $m_stmt->execute($params);
    $metrics['total_today'] = $m_stmt->fetchColumn();

    // 2. Current meal count - Use CURDATE()
    if ($meal_type) {
        $sql = "SELECT COUNT(*) FROM meal_logs WHERE DATE(scan_time) = CURDATE() AND meal_type = ?";
        $params = [$meal_type];
        if ($campus_id) { $sql .= " AND campus_id = ?"; $params[] = $campus_id; }
        $m_stmt = $pdo->prepare($sql);
        $m_stmt->execute($params);
        $metrics['current_meal'] = $m_stmt->fetchColumn();
    }

    // 3. Total eligible
    $sql = "SELECT COUNT(*) FROM students WHERE enrollment_status = 'Active' AND cafe_type = 'Cafe user'";
    $params = [];
    if ($campus_id) { $sql .= " AND campus_id = ?"; $params[] = $campus_id; }
    $m_stmt = $pdo->prepare($sql);
    $m_stmt->execute($params);
    $metrics['total_eligible'] = $m_stmt->fetchColumn();
    
} catch (Exception $e) {}

sendJSON(['success' => true, 'metrics' => $metrics, 'meal_type' => $meal_type]);
?>
