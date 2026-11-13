<?php
/**
 * Barcode Scanner API
 * Processes student barcode scans and returns access information
 * MUST RETURN VALID JSON ONLY
 */

// Step 1: Output control - disable all output except JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Set Timezone to GMT+3 (Africa/Addis_Ababa)
date_default_timezone_set('Africa/Addis_Ababa');

// Step 2: JSON header
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Step 3: JSON helper functions
function sendJSON($data, $httpCode = 200) {
    ob_end_clean();
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    exit();
}

function getCafeteriaMetrics($pdo, $meal_type) {
    $metrics = ['total_today' => 0, 'current_meal' => 0, 'total_eligible' => 0];
    try {
        $today = date('Y-m-d');
        // 1. Total meals served today
        $m_stmt = $pdo->prepare("SELECT COUNT(*) FROM meal_logs WHERE DATE(scan_time) = ?");
        $m_stmt->execute([$today]);
        $metrics['total_today'] = $m_stmt->fetchColumn();

        // 2. Current meal count
        if ($meal_type) {
            $m_stmt = $pdo->prepare("SELECT COUNT(*) FROM meal_logs WHERE DATE(scan_time) = ? AND meal_type = ?");
            $m_stmt->execute([$today, $meal_type]);
            $metrics['current_meal'] = $m_stmt->fetchColumn();
        }

        // 3. Total eligible
        $m_stmt = $pdo->query("SELECT COUNT(*) FROM students WHERE enrollment_status = 'Active' AND cafe_type = 'Cafe user'");
        $metrics['total_eligible'] = $m_stmt->fetchColumn();
    } catch (Exception $e) {}
    return $metrics;
}

// Step 4: Load Session & Database (Use sessions.php to ensure shared session state)
try {
    // Adjust path to root sessions.php
    require_once __DIR__ . '/../sessions.php';
} catch (Throwable $e) {
    sendJSON(['success' => false, 'message' => 'Initialization failed: ' . $e->getMessage()], 500);
}

// Step 5: Check database loaded
if (!isset($pdo)) {
    sendJSON(['success' => false, 'message' => 'Database not available'], 500);
}

// Step 6: Check authentication
// sessions.php starts the session, so $_SESSION should be populated if logged in
$user_id = $_SESSION['user_id'] ?? null;
$user_campus_id = $_SESSION['campus_id'] ?? null;

if (empty($user_id)) {
    sendJSON(['success' => false, 'message' => 'Not authenticated. Please log in first.', 'code' => 'AUTH_REQUIRED'], 401);
}

// Step 7: Get barcode input
$barcode = trim($_POST['barcode'] ?? '');
$barcode = trim($_POST['barcode'] ?? '');
$raw_location = trim($_POST['location'] ?? 'Main Gate'); // Contains Gate Name for Cafeteria
$scanner_type = trim($_POST['scanner_type'] ?? 'gate');

// Map scanner type to Database ENUM location
$scan_log_location = 'Main Gate'; // Default
switch(strtolower($scanner_type)) {
    case 'gate': 
        $scan_log_location = 'Main Gate'; 
        break;
    case 'library': 
        $scan_log_location = 'Library'; 
        break;
    case 'clinic': 
        $scan_log_location = 'Clinic'; 
        break;
    case 'cafeteria': 
        $scan_log_location = 'Cafeteria'; 
        break;
}

if (empty($barcode)) {
    sendJSON(['success' => false, 'message' => 'Barcode is empty'], 400);
}

// Step 8: Query database
try {
    $sql = "SELECT student_id, full_name, department, year_level, enrollment_status, cafe_type, photo, cafeteria_gate, campus_id FROM students WHERE student_id = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    
    if (!$stmt) {
        sendJSON(['success' => false, 'message' => 'Database query error'], 500);
    }
    
    $stmt->execute([$barcode]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    $is_labour = false;
    $labour = null;

    if (!$student) {
        $labour_stmt = $pdo->prepare("SELECT * FROM labour_personnel WHERE labour_id = ? LIMIT 1");
        $labour_stmt->execute([$barcode]);
        $labour = $labour_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$labour) {
            sendJSON(['success' => false, 'message' => 'ID not found', 'barcode' => htmlspecialchars($barcode)], 404);
        }
        $is_labour = true;
    }

    // --- LABOUR PERSONNEL LOGIC ---
    if ($is_labour) {
        $response = [
            'success' => true,
            'student' => [
                'student_id' => $labour['labour_id'],
                'full_name' => $labour['full_name'],
                'department' => $labour['work_type'] ?? 'Labour Personnel',
                'year_level' => 'Exp: ' . ($labour['expiry_date'] ?? 'N/A'),
                'enrollment_status' => $labour['status'],
                'cafe_type' => 'None',
                'cafeteria_gate' => 'None'
            ],
            'allowed' => false,
            'message' => '',
            'status_color' => 'red',
            'audio_alert' => true
        ];

        if (!empty($labour['photo'])) {
            $response['student']['photo_data'] = 'data:image/jpeg;base64,' . base64_encode($labour['photo']);
        } else {
            $response['student']['photo_data'] = null;
        }

        // Cross-Campus Check
        if ($user_campus_id && ($labour['campus_id'] != $user_campus_id)) {
            $response['message'] = 'Cross-Campus Access Denied';
            try {
                $log_stmt = $pdo->prepare("INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)");
                $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'DENIED_CROSS_CAMPUS', $user_campus_id]);
            } catch (Throwable $e) {}
            sendJSON($response);
        }

        // Scanner Type Check
        if ($scanner_type !== 'gate') {
            $response['message'] = 'Labour ID only valid at Gate Scanner';
            try {
                $log_stmt = $pdo->prepare("INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)");
                $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'DENIED_WRONG_SCANNER', $user_campus_id]);
            } catch (Throwable $e) {}
            sendJSON($response);
        }

        // Status Check
        if ($labour['status'] !== 'Active') {
            $response['message'] = 'Status: ' . htmlspecialchars($labour['status']) . ' - Access Denied';
            try {
                $log_stmt = $pdo->prepare("INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)");
                $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'DENIED_INACTIVE', $user_campus_id]);
            } catch (Throwable $e) {}
            sendJSON($response);
        }

        // Expiry Date Check
        $expiry = strtotime($labour['expiry_date']);
        $today = strtotime(date('Y-m-d'));
        if ($expiry < $today) {
            $response['message'] = 'ID Expired on ' . htmlspecialchars($labour['expiry_date']);
            try {
                $log_stmt = $pdo->prepare("INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)");
                $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'DENIED_EXPIRED', $user_campus_id]);
            } catch (Throwable $e) {}
            sendJSON($response);
        }

        // Access Granted
        $response['allowed'] = true;
        $response['message'] = 'Access Granted';
        $response['status_color'] = 'green';
        $response['audio_alert'] = false;

        try {
            $log_stmt = $pdo->prepare("INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)");
            $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'ALLOWED', $user_campus_id]);
        } catch (Throwable $e) {}

        sendJSON($response);
    }
    // --- END LABOUR PERSONNEL LOGIC ---
    
    // Cross-Campus Check
    if ($user_campus_id && ($student['campus_id'] != $user_campus_id)) {
         // Optionally allow Super Admin (but logic usually implies Super Admin handles all, though specific scan might be tied to location? 
         // Assuming Super Admin has campus_id check handled or is exempt.
         // But here we are checking if logged in user has campus_id. If Super Admin (null), this check skips.
         
         $response = [
            'success' => true, // Return true to show student info but deny access
            'student' => [
                'full_name' => $student['full_name'] ?? 'Unknown',
                'photo_data' => !empty($student['photo']) ? 'data:image/jpeg;base64,' . base64_encode($student['photo']) : null
            ],
            'allowed' => false,
            'message' => 'Cross-Campus Access Denied',
            'status_color' => 'red',
            'audio_alert' => true
        ];
        
        try {
            $log_sql = "INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $pdo->prepare($log_sql);
            if ($log_stmt) $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'DENIED_CROSS_CAMPUS', $user_campus_id]);
        } catch (Throwable $e) {}
        
        sendJSON($response);
    }

    // Build response
    $response = [
        'success' => true,
        'student' => [
            'student_id' => $student['student_id'] ?? '',
            'full_name' => $student['full_name'] ?? 'Unknown',
            'department' => $student['department'] ?? 'N/A',
            'year_level' => $student['year_level'] ?? '0',
            'enrollment_status' => $student['enrollment_status'] ?? 'Unknown',
            'cafe_type' => $student['cafe_type'] ?? 'Unknown',
            'cafeteria_gate' => $student['cafeteria_gate'] ?? 'N/A',
        ],
        'allowed' => false,
        'message' => '',
        'status_color' => 'grey',
        'audio_alert' => false
    ];

    // Convert photo to base64
    if (!empty($student['photo'])) {
        $response['student']['photo_data'] = 'data:image/jpeg;base64,' . base64_encode($student['photo']);
    } else {
        $response['student']['photo_data'] = null;
    }

    // Determine current meal type (needed for metrics and validation)
    $current_time = date('H:i:s');
    $meal_type = '';
    try {
        $sch_stmt = $pdo->prepare("SELECT * FROM cafe_schedules WHERE is_active = 1");
        $sch_stmt->execute();
        while ($sch = $sch_stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($current_time >= $sch['start_time'] && $current_time <= $sch['end_time']) {
                $meal_type = $sch['meal_type'];
                break;
            }
        }
    } catch (Exception $e) {}

    // Function to get metrics using CURDATE() for consistency
    function getCafeteriaMetricsFresh($pdo, $meal_type, $campus_id = null) {
        $metrics = ['total_today' => 0, 'current_meal' => 0, 'total_eligible' => 0];
        try {
            // 1. Total meals served today (Use CURDATE())
            $sql = "SELECT COUNT(*) FROM meal_logs WHERE DATE(scan_time) = CURDATE()";
            $params = [];
            if ($campus_id) { $sql .= " AND campus_id = ?"; $params[] = $campus_id; }
            $m_stmt = $pdo->prepare($sql);
            $m_stmt->execute($params);
            $metrics['total_today'] = $m_stmt->fetchColumn();

            // 2. Current meal count
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
        return $metrics;
    }


    // Check location-specific rules
    if ($scanner_type === 'cafeteria') {
        
        // 1. GATE ACCESS CONTROL (New Feature)
        $assigned_gate = trim($student['cafeteria_gate'] ?? '');
        $current_gate = trim($raw_location);
        
        // Only check if assigned gate is set and we are scanning at a specific gate
        if (!empty($assigned_gate) && !empty($current_gate) && strcasecmp($assigned_gate, $current_gate) !== 0) {
            $response['allowed'] = false;
            $response['message'] = "Wrong Gate! Assigned to: $assigned_gate";
            $response['status_color'] = 'red';
            $response['audio_alert'] = true;
            
            // Log access denied
             try {
                $log_sql = "INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)";
                $log_stmt = $pdo->prepare($log_sql);
                if ($log_stmt) $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'DENIED_WRONG_GATE', $user_campus_id]);
            } catch (Throwable $e) {}

            // Attach Metrics and Send
            $response['metrics'] = getCafeteriaMetricsFresh($pdo, $meal_type);
            sendJSON($response);
        }

        // 2. Check Meal Time
        if (empty($meal_type)) {
            $response['allowed'] = false;
            $response['message'] = 'Outside Meal Hours';
            $response['status_color'] = 'red';
            $response['audio_alert'] = true;
            
            try {
                $log_sql = "INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)";
                $log_stmt = $pdo->prepare($log_sql);
                if ($log_stmt) $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'DENIED_CLOSED', $user_campus_id]);
            } catch (Throwable $e) {}

            $response['metrics'] = getCafeteriaMetricsFresh($pdo, $meal_type);
            sendJSON($response);
        }

        // 3. Check Eligibility (Active & Cafe User)
        $enrollment_status = $student['enrollment_status'];
        $cafe_type = $student['cafe_type'];

        if ($enrollment_status === 'Graduated') {
            $response['allowed'] = false;
            $response['message'] = 'Graduated Student - Access Denied';
            $response['status_color'] = 'red';
            $response['audio_alert'] = true;
             // Log
             try {
                $log_sql = "INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)";
                $log_stmt = $pdo->prepare($log_sql);
                if ($log_stmt) $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'DENIED_GRADUATED', $user_campus_id]);
            } catch (Throwable $e) {}
            
            $response['metrics'] = getCafeteriaMetricsFresh($pdo, $meal_type);
            sendJSON($response);
        }

        if ($enrollment_status !== 'Active' || $cafe_type !== 'Cafe user') {
            $response['allowed'] = false;
            $response['message'] = 'Not Allowed (' . htmlspecialchars($enrollment_status) . ')';
            $response['status_color'] = 'red'; // Red Bar
            $response['audio_alert'] = true; // Audio Alert

            // Log denial
             try {
                $log_sql = "INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)";
                $log_stmt = $pdo->prepare($log_sql);
                if ($log_stmt) $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'DENIED_ELIGIBILITY', $user_campus_id]);
            } catch (Throwable $e) {}

            $response['metrics'] = getCafeteriaMetricsFresh($pdo, $meal_type);
            sendJSON($response);
        }

        // 4. Check for double dip with 2-minute grace period
        $is_within_grace_period = false;
        try {
            $check_sql = "SELECT scan_time FROM meal_logs WHERE student_id = ? AND meal_type = ? AND DATE(scan_time) = CURDATE() ORDER BY scan_time DESC LIMIT 1";
            $check_stmt = $pdo->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->execute([$barcode, $meal_type]);
                $check_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($check_result && isset($check_result['scan_time'])) {
                    $last_scan_time = strtotime($check_result['scan_time']);
                    $current_time_ts = time();
                    
                    if (($current_time_ts - $last_scan_time) <= 120) {
                        // Allowed (within 2 minutes)
                        $is_within_grace_period = true;
                    } else {
                        // Rejected (after 2 minutes)
                        $served_time_formatted = date('h:i A', $last_scan_time);
                        
                        $response['allowed'] = false;
                        $response['message'] = 'Meal already served.';
                        $response['meal_served_time'] = $served_time_formatted;
                        $response['meal_served_detail'] = 'Student received ' . $meal_type . ' at ' . $served_time_formatted . '.';
                        $response['meal_served_warning'] = 'Further service is not allowed.';
                        $response['status_color'] = 'red';
                        $response['audio_alert'] = true;
                        
                        try {
                            $log_sql = "INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)";
                            $log_stmt = $pdo->prepare($log_sql);
                            if ($log_stmt) $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'DENIED_DOUBLE', $user_campus_id]);
                        } catch (Throwable $e) {}
                        
                        $response['metrics'] = getCafeteriaMetricsFresh($pdo, $meal_type);
                        sendJSON($response);
                    }
                }
            }
        } catch (Throwable $e) {}

        // 5. Allow meal and log it
        $response['allowed'] = true;
        $response['message'] = 'Have a great Meal';
        $response['status_color'] = 'green'; // Green Bar
        $response['audio_alert'] = false;

        if (!$is_within_grace_period) {
            try {
                $meal_sql = "INSERT INTO meal_logs (student_id, meal_type, scanned_by, campus_id) VALUES (?, ?, ?, ?)";
                $meal_stmt = $pdo->prepare($meal_sql);
                if ($meal_stmt) {
                    $meal_stmt->execute([$barcode, $meal_type, $user_id, $user_campus_id]);
                }
            } catch (Throwable $e) {}

            try {
                $log_sql = "INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)";
                $log_stmt = $pdo->prepare($log_sql);
                if ($log_stmt) {
                    $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'ALLOWED_' . strtoupper($meal_type), $user_campus_id]);
                }
            } catch (Throwable $e) {}
        }

        // Send metrics with success response
        $response['metrics'] = getCafeteriaMetricsFresh($pdo, $meal_type, $user_campus_id);
        sendJSON($response);
    }
    
    // Default Logic for other scanners (Gate/Library/Clinic)
    
    // Check Status for non-cafeteria
    // Check Status for non-cafeteria
    $status = trim($student['enrollment_status'] ?? '');
    
    if ($status === 'Graduated') {
        $response['allowed'] = false;
        $response['message'] = 'Graduated Student - Access Denied';
        $response['status_color'] = 'red';
        $response['audio_alert'] = true;
        
        try {
            $log_sql = "INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $pdo->prepare($log_sql);
            if ($log_stmt) $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'DENIED_GRADUATED', $user_campus_id]);
        } catch (Throwable $e) {}
        
        sendJSON($response);
    }

    if ($status !== 'Active') {
        $response['allowed'] = false;
        $response['message'] = 'Status: ' . htmlspecialchars($status) . ' - Access Denied';
        $response['status_color'] = 'red';
        $response['audio_alert'] = true;
        
        try {
            $log_sql = "INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $pdo->prepare($log_sql);
            if ($log_stmt) $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'DENIED_' . strtoupper($status), $user_campus_id]);
        } catch (Throwable $e) {}
        
        sendJSON($response);
    }

    // Access Granted
    $response['allowed'] = true;
    $response['message'] = 'Access Granted';
    $response['status_color'] = 'green';

    try {
        $log_sql = "INSERT INTO scan_logs (student_id, location, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?)";
        $log_stmt = $pdo->prepare($log_sql);
        if ($log_stmt) $log_stmt->execute([$barcode, $scan_log_location, $user_id, 'ALLOWED', $user_campus_id]);
    } catch (Throwable $e) {}

    sendJSON($response);

} catch (Throwable $e) {
    sendJSON(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
}
?>
