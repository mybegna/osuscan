<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
require_once 'includes/lang_init.php';
checkAuth();

// Cafeteria Manager has read-only access; Super Admin and Campus Admin have full control
$isCafeAdmin = in_array($_SESSION['role'], ['Super Admin', 'Campus Admin', 'Cafeteria Manager']);
if (!$isCafeAdmin && $_SESSION['role'] !== 'Cafeteria Manager') {
    die("<div style='padding: 20px; color: #6B4E3D;'><h2>Access Denied</h2></div>");
}
$campus_id = $_SESSION['campus_id'] ?? null;
if ($_SESSION['role'] === 'Super Admin') {
    $campus_id = 1; // Enforce Main Campus for Super Admin
}

// Download CSV Template
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="cafeteria_scan_template.csv"');
    echo "Student ID,Meal Type,Scan Date Time (YYYY-MM-DD HH:MM:SS),Gate Name (Optional)\n";
    echo "OSU001,Breakfast,2023-10-01 07:30:00,Gate 1\n";
    echo "OSU002,Lunch,2023-10-01 12:45:00,Gate 2\n";
    exit();
}

// Seed Schedules for this campus if needed
if ($campus_id) {
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM cafe_schedules WHERE campus_id = ?");
        $check->execute([$campus_id]);
        if ($check->fetchColumn() == 0) {
            $pdo->prepare("INSERT INTO cafe_schedules (meal_type, start_time, end_time, is_active, campus_id) VALUES 
                ('Breakfast', '07:00:00', '09:00:00', 1, ?),
                ('Lunch', '12:00:00', '14:00:00', 1, ?),
                ('Dinner', '18:00:00', '20:00:00', 1, ?)")->execute([$campus_id, $campus_id, $campus_id]);
        }
    } catch (Exception $e) {}
}

$message = '';

/**
 * Switch one student between "Cafe user" and "Non Cafe" and write an audit entry.
 * Only students in $campus_id can be changed. Throws an Exception with the reason on refusal.
 * Returns the student row plus 'new_type' and 'changed'.
 */
function setStudentCafeStatus(PDO $pdo, $campus_id, string $sid, string $newType, string $newGate = ''): array {
    if (!in_array($newType, ['Cafe user', 'Non Cafe'], true)) {
        throw new Exception("Invalid cafe status.");
    }
    $q = "SELECT student_id, full_name, enrollment_status, cafe_type, cafeteria_gate FROM students WHERE student_id = ?";
    $qp = [$sid];
    if ($campus_id) { $q .= " AND campus_id = ?"; $qp[] = $campus_id; } // own campus only
    $stmt = $pdo->prepare($q);
    $stmt->execute($qp);
    $stu = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stu) {
        throw new Exception("Student not found in your campus.");
    }
    if ($stu['enrollment_status'] === 'Graduated') {
        throw new Exception("Cannot update a student who has already graduated.");
    }

    if ($newType === 'Non Cafe') {
        $gate = ''; // non-cafe students have no cafeteria gate
    } else {
        $gate = (string)$stu['cafeteria_gate'];
        if ($newGate !== '') {
            $gq = "SELECT COUNT(*) FROM cafeteria_gate WHERE cafe_gate_name = ?";
            $gp = [$newGate];
            if ($campus_id) { $gq .= " AND campus_id = ?"; $gp[] = $campus_id; }
            $gstmt = $pdo->prepare($gq);
            $gstmt->execute($gp);
            if ($gstmt->fetchColumn() == 0) {
                throw new Exception("Unknown cafe gate.");
            }
            $gate = $newGate;
        }
    }

    $stu['new_type'] = $newType;
    $stu['changed']  = ($stu['cafe_type'] !== $newType || (string)$stu['cafeteria_gate'] !== $gate);
    if ($stu['changed']) {
        $pdo->prepare("UPDATE students SET cafe_type = ?, cafeteria_gate = ? WHERE student_id = ?")
            ->execute([$newType, $gate, $stu['student_id']]);
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)")
            ->execute([$_SESSION['user_id'], 'Change Cafe Status',
                "Student {$stu['student_id']} ({$stu['full_name']}): {$stu['cafe_type']} -> $newType" . ($gate !== '' ? " (gate: $gate)" : '')]);
    }
    return $stu;
}

// Handle Cafe Type Actions — write operations restricted to admins only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!$isCafeAdmin) {
        // Cafeteria Manager may only use upload_csv_scans
        if ($_POST['action'] !== 'upload_csv_scans') {
            $message = "Error: You do not have permission to perform this action.";
            $_POST['action'] = '__blocked__'; // neutralise further handling
        }
    }
    if ($_POST['action'] === 'add_type') {
        $type = trim($_POST['type_name']);
        if (!empty($type)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO cafeteria_gate (cafe_gate_name, campus_id) VALUES (?, ?)");
                $stmt->execute([$type, $campus_id]);
                $message = "Cafe type added successfully.";
            } catch (Exception $e) {
                $message = "Error adding type: " . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'delete_type') {
        $id = $_POST['id'];
        $typeName = $_POST['type_name']; 
        try {
            $stmt = $pdo->prepare("DELETE FROM cafeteria_gate WHERE id = ? AND campus_id = ?");
            $stmt->execute([$id, $campus_id]);
            
            // Only update students of SAME campus
            $stmtUpdate = $pdo->prepare("UPDATE students SET cafeteria_gate = '' WHERE cafeteria_gate = ? AND campus_id = ?");
            $stmtUpdate->execute([$typeName, $campus_id]);
            $message = "Cafe type deleted and removed from student records.";
        } catch (Exception $e) {
            $message = "Error deleting type: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'update_schedule') {
        $id = $_POST['id'];
        $start = $_POST['start_time'];
        $end = $_POST['end_time'];
        $active = isset($_POST['is_active']) ? 1 : 0;
        try {
            $stmt = $pdo->prepare("UPDATE cafe_schedules SET start_time = ?, end_time = ?, is_active = ? WHERE id = ? AND campus_id = ?");
            $stmt->execute([$start, $end, $active, $id, $campus_id]);
            $message = "Schedule updated successfully.";
        } catch (Exception $e) {
            $message = "Error updating schedule: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'set_cafe_status') {
        // Single student
        try {
            $stu = setStudentCafeStatus($pdo, $campus_id, trim($_POST['student_id'] ?? ''), $_POST['cafe_type'] ?? '', trim($_POST['cafeteria_gate'] ?? ''));
            $message = "{$stu['full_name']} ({$stu['student_id']}) is now set to {$stu['new_type']}.";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'set_cafe_status_batch') {
        // Many students: ticked rows and/or a pasted list of IDs
        $ids = array_map('strval', (array)($_POST['student_ids'] ?? []));
        if (!empty($_POST['id_list'])) {
            $ids = array_merge($ids, preg_split('/[\s,;]+/', $_POST['id_list'], -1, PREG_SPLIT_NO_EMPTY));
        }
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids), 'strlen')));
        $newType = $_POST['cafe_type'] ?? '';
        $newGate = trim($_POST['cafeteria_gate'] ?? '');

        if (!$ids) {
            $message = "Error: Select at least one student or paste at least one student ID.";
        } elseif (count($ids) > 500) {
            $message = "Error: A batch can hold at most 500 students. You sent " . count($ids) . ".";
        } else {
            $updated = 0; $unchanged = 0; $failed = [];
            foreach ($ids as $sid) {
                try {
                    $stu = setStudentCafeStatus($pdo, $campus_id, $sid, $newType, $newGate);
                    if ($stu['changed']) $updated++; else $unchanged++;
                } catch (Exception $e) {
                    $failed[] = "$sid (" . rtrim($e->getMessage(), '.') . ")";
                }
            }
            $message = ($failed ? "Warning: " : "") . "Batch complete. Set to $newType: $updated | Already $newType: $unchanged | Not updated: " . count($failed) . ".";
            if ($failed) {
                $message .= " Not updated: " . implode(', ', array_slice($failed, 0, 20)) . (count($failed) > 20 ? ', ...' : '') . ".";
            }
            if ($failed && $updated === 0 && $unchanged === 0) {
                $message = "Error: No students were updated. " . implode(', ', array_slice($failed, 0, 20)) . (count($failed) > 20 ? ', ...' : '') . ".";
            }
        }
    } elseif ($_POST['action'] === 'upload_csv_scans') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $ext = pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION);
            if (strtolower($ext) !== 'csv') {
                $message = "Error: Only CSV files are allowed.";
            } else {
                $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
                $header = fgetcsv($file); // skip header
                
                $success_count = 0;
                $duplicate_count = 0;
                $error_count = 0;
                
                $campus_id_to_use = ($_SESSION['role'] === 'Super Admin' && !empty($_POST['campus_id'])) ? $_POST['campus_id'] : $campus_id;
                $user_id = $_SESSION['user_id'];

                while (($data = fgetcsv($file)) !== FALSE) {
                    if (count($data) < 3) continue;
                    $student_id = trim($data[0]);
                    $meal_type = trim($data[1]);
                    $scan_time = trim($data[2]);
                    $gate_name = isset($data[3]) ? trim($data[3]) : 'Cafeteria';
                    
                    if (empty($student_id) || empty($meal_type) || empty($scan_time)) {
                        $error_count++;
                        continue;
                    }
                    
                    // Validate Student Exists, is Active, and is a Cafe User
                    $stu_chk = $pdo->prepare("SELECT enrollment_status, cafe_type, campus_id FROM students WHERE student_id = ?");
                    $stu_chk->execute([$student_id]);
                    $student = $stu_chk->fetch(PDO::FETCH_ASSOC);

                    if (!$student || $student['enrollment_status'] !== 'Active' || $student['cafe_type'] !== 'Cafe user') {
                        $error_count++;
                        continue;
                    }

                    // Enforce Campus Rules: Users can only upload for students in the selected/assigned campus
                    if ($_SESSION['role'] !== 'Super Admin' && $student['campus_id'] != $campus_id_to_use) {
                        $error_count++;
                        continue;
                    }
                    
                    $date_only = date('Y-m-d', strtotime($scan_time));

                    // Duplicate Check for the same meal on the same day
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM meal_logs WHERE student_id = ? AND meal_type = ? AND DATE(scan_time) = ?");
                    $chk->execute([$student_id, $meal_type, $date_only]);
                    if ($chk->fetchColumn() > 0) {
                        $duplicate_count++;
                    } else {
                        try {
                            $stmt = $pdo->prepare("INSERT INTO meal_logs (student_id, meal_type, scan_time, scanned_by, campus_id) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$student_id, $meal_type, $scan_time, $user_id, $campus_id_to_use]);
                            
                            $log_stmt = $pdo->prepare("INSERT INTO scan_logs (student_id, location, scan_time, scanned_by, status_at_scan, campus_id) VALUES (?, ?, ?, ?, ?, ?)");
                            $log_stmt->execute([$student_id, $gate_name, $scan_time, $user_id, 'ALLOWED_BULK_' . strtoupper($meal_type), $campus_id_to_use]);
                            
                            $success_count++;
                        } catch (Exception $e) {
                            $error_count++;
                        }
                    }
                }
                fclose($file);
                
                // Color coding format for final message
                $messageType = ($success_count > 0 && $error_count == 0) ? 'Success' : 'Warning';
                $message = "$messageType: Import Complete! Successfully Inserted: $success_count | Duplicates Skipped: $duplicate_count | Errors: $error_count.";
            }
        } else {
            $message = "Error: Please select a valid CSV file to upload.";
        }
    }
}

// Fetch Data
$gateQuery = "SELECT * FROM cafeteria_gate WHERE 1=1";
$gateParams = [];
if ($campus_id) { $gateQuery .= " AND campus_id = ?"; $gateParams[] = $campus_id; }
$gateQuery .= " ORDER BY FIELD(cafe_gate_name, 'id', 'cafe_gate_name')";
$stmt = $pdo->prepare($gateQuery);
$stmt->execute($gateParams);
$cafe_types = $stmt->fetchAll();

$schQuery = "SELECT * FROM cafe_schedules WHERE 1=1";
$schParams = [];
if ($campus_id) { $schQuery .= " AND campus_id = ?"; $schParams[] = $campus_id; }
$schQuery .= " ORDER BY FIELD(meal_type, 'Breakfast', 'Lunch', 'Dinner')";
$stmt = $pdo->prepare($schQuery);
$stmt->execute($schParams);
$schedules = $stmt->fetchAll();

// Student cafe status: search by ID or name (and/or current status) within this campus
$studentQ      = trim($_REQUEST['student_q'] ?? '');
$statusFilter  = $_REQUEST['status_filter'] ?? '';
if (!in_array($statusFilter, ['Cafe user', 'Non Cafe'], true)) $statusFilter = '';
$cafeSearched  = ($studentQ !== '' || $statusFilter !== '');
$cafeStudents  = [];
$cafeListLimit = 100;
if ($isCafeAdmin && $cafeSearched) {
    $sq = "SELECT student_id, full_name, department, year_level, enrollment_status, cafe_type, cafeteria_gate
           FROM students WHERE (student_id LIKE ? OR full_name LIKE ?)";
    $sp = ["%$studentQ%", "%$studentQ%"];
    if ($statusFilter !== '') { $sq .= " AND cafe_type = ?"; $sp[] = $statusFilter; }
    if ($campus_id) { $sq .= " AND campus_id = ?"; $sp[] = $campus_id; }
    $sq .= " ORDER BY (student_id = ?) DESC, full_name LIMIT $cafeListLimit";
    $sp[] = $studentQ;
    $stmt = $pdo->prepare($sq);
    $stmt->execute($sp);
    $cafeStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Meal Statistics Queries
$mealStats = [
    'today' => ['Breakfast' => 0, 'Lunch' => 0, 'Dinner' => 0],
    'week' => ['Breakfast' => 0, 'Lunch' => 0, 'Dinner' => 0],
    'month' => ['Breakfast' => 0, 'Lunch' => 0, 'Dinner' => 0]
];

try {
    // Today
    $sql = "SELECT meal_type, COUNT(*) as cnt FROM meal_logs WHERE DATE(scan_time) = CURDATE()";
    if ($campus_id) $sql .= " AND campus_id = '$campus_id'";
    $sql .= " GROUP BY meal_type";
    
    foreach ($pdo->query($sql) as $row) {
        $mealStats['today'][$row['meal_type']] = $row['cnt'];
    }

    // This Week
    $sql = "SELECT meal_type, COUNT(*) as cnt FROM meal_logs WHERE YEARWEEK(scan_time, 1) = YEARWEEK(CURDATE(), 1)";
    if ($campus_id) $sql .= " AND campus_id = '$campus_id'";
    $sql .= " GROUP BY meal_type";
    
    foreach ($pdo->query($sql) as $row) {
        $mealStats['week'][$row['meal_type']] = $row['cnt'];
    }

    // This Month
    $sql = "SELECT meal_type, COUNT(*) as cnt FROM meal_logs WHERE MONTH(scan_time) = MONTH(CURDATE()) AND YEAR(scan_time) = YEAR(CURDATE())";
    if ($campus_id) $sql .= " AND campus_id = '$campus_id'";
    $sql .= " GROUP BY meal_type";
    
    foreach ($pdo->query($sql) as $row) {
        $mealStats['month'][$row['meal_type']] = $row['cnt'];
    }
} catch (Exception $e) { }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['manage_cafe']; ?> - OSU One Card</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* lang-btn styles removed to use theme.css */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: var(--card-bg);
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(12, 33, 26, 0.05);
            border: 1px solid var(--border-color);
        }
        .stat-title { font-size: 14px; color: var(--secondary-color); margin-bottom: 5px; font-weight: bold; }
        .stat-value { font-size: 20px; font-weight: bold; color: var(--primary-color); }
        .stat-row { display: flex; justify-content: space-between; margin-bottom: 5px; border-bottom: 1px solid var(--border-color); padding-bottom: 2px;}
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="navbar-top">
            <div style="display: flex; align-items: center;">
                <button class="hamburger-menu" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="navbar-title">
                    <i class="fas fa-coffee"></i> <?php echo $lang['manage_cafe']; ?>
                </div>
            </div>
            <div class="navbar-actions" style="display: flex; align-items: center; gap: 10px;">
                <!-- Language Switcher -->
                <div style="display: flex;">
                    <a href="?lang=en" class="lang-btn <?php echo ($_SESSION['lang']??'en') == 'en' ? 'active' : ''; ?>">ENG</a>
                    <a href="?lang=am" class="lang-btn <?php echo ($_SESSION['lang']??'en') == 'am' ? 'active' : ''; ?>">AMH</a>
                    <a href="?lang=or" class="lang-btn <?php echo ($_SESSION['lang']??'en') == 'or' ? 'active' : ''; ?>">ORM</a>
                </div>

                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>
                <?php include 'includes/topbar_user.php'; ?>
            </div>
        </div>

        <div class="page-content">
            <?php if ($message): ?>
                <div class="card flash <?php echo strpos($message, 'Error') === 0 ? 'flash-error' : (strpos($message, 'Warning') === 0 ? 'flash-warning' : 'flash-success'); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div style="display: flex; flex-direction: column; gap: 20px;">
                
                <!-- New: Meal Statistics -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-pie"></i> <?php echo $lang['meal_stats']; ?>
                    </div>
                    <div class="stats-grid">
                        <!-- Today -->
                        <div class="stat-card">
                            <div class="stat-title"><?php echo $lang['today']; ?></div>
                            <div class="stat-row"><span><?php echo $lang['breakfast']; ?></span> <span><?php echo $mealStats['today']['Breakfast']; ?></span></div>
                            <div class="stat-row"><span><?php echo $lang['lunch']; ?></span> <span><?php echo $mealStats['today']['Lunch']; ?></span></div>
                            <div class="stat-row" style="border: none;"><span><?php echo $lang['dinner']; ?></span> <span><?php echo $mealStats['today']['Dinner']; ?></span></div>
                        </div>
                        <!-- This Week -->
                        <div class="stat-card">
                            <div class="stat-title"><?php echo $lang['this_week']; ?></div>
                            <div class="stat-row"><span><?php echo $lang['breakfast']; ?></span> <span><?php echo $mealStats['week']['Breakfast']; ?></span></div>
                            <div class="stat-row"><span><?php echo $lang['lunch']; ?></span> <span><?php echo $mealStats['week']['Lunch']; ?></span></div>
                            <div class="stat-row" style="border: none;"><span><?php echo $lang['dinner']; ?></span> <span><?php echo $mealStats['week']['Dinner']; ?></span></div>
                        </div>
                        <!-- This Month -->
                        <div class="stat-card">
                            <div class="stat-title"><?php echo $lang['this_month']; ?></div>
                            <div class="stat-row"><span><?php echo $lang['breakfast']; ?></span> <span><?php echo $mealStats['month']['Breakfast']; ?></span></div>
                            <div class="stat-row"><span><?php echo $lang['lunch']; ?></span> <span><?php echo $mealStats['month']['Lunch']; ?></span></div>
                            <div class="stat-row" style="border: none;"><span><?php echo $lang['dinner']; ?></span> <span><?php echo $mealStats['month']['Dinner']; ?></span></div>
                        </div>
                    </div>
                </div>

                <!-- Manage Schedules -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-clock"></i> Meal Schedules
                    </div>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: var(--hover-bg-light);">
                                    <th style="padding: 10px; text-align: left;">Meal</th>
                                    <th style="padding: 10px; text-align: left;">Start</th>
                                    <th style="padding: 10px; text-align: left;">End</th>
                                    <th style="padding: 10px; text-align: center;">Active</th>
                                    <th style="padding: 10px; text-align: center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schedules as $sch): ?>
                                <tr style="border-bottom: 1px solid var(--border-color);">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="update_schedule">
                                        <input type="hidden" name="id" value="<?php echo $sch['id']; ?>">
                                        <td style="padding: 10px; font-weight: bold;"><?php echo $sch['meal_type']; ?></td>
                                        <td style="padding: 10px;">
                                            <input type="time" name="start_time" value="<?php echo $sch['start_time']; ?>" required style="padding: 5px; border-radius: 4px; border: 1px solid var(--border-color);">
                                        </td>
                                        <td style="padding: 10px;">
                                            <input type="time" name="end_time" value="<?php echo $sch['end_time']; ?>" required style="padding: 5px; border-radius: 4px; border: 1px solid var(--border-color);">
                                        </td>
                                        <td style="padding: 10px; text-align: center;">
                                            <input type="checkbox" name="is_active" <?php echo $sch['is_active'] ? 'checked' : ''; ?> <?php echo !$isCafeAdmin ? 'disabled' : ''; ?>>
                                        </td>
                                        <td style="padding: 10px; text-align: center;">
                                            <?php if ($isCafeAdmin): ?>
                                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                            <?php else: ?>
                                            <span style="font-size: 11px; color: var(--secondary-color);"><i class="fas fa-lock"></i></span>
                                            <?php endif; ?>
                                        </td>
                                    </form>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Student Cafe Status -->
                <?php if ($isCafeAdmin):
                    $gateOptions = '';
                    foreach ($cafe_types as $type) {
                        $g = htmlspecialchars($type['cafe_gate_name']);
                        $gateOptions .= "<option value=\"$g\">$g</option>";
                    }
                ?>
                <div class="card" id="cafe-status">
                    <div class="card-header">
                        <i class="fas fa-user-check"></i> Student Café Status
                    </div>
                    <p style="color: var(--secondary-color); font-size: 14px; margin-bottom: 15px;">
                        Switch students between Cafe user and Non Cafe, one at a time or in a batch. Non Cafe students are refused at the cafeteria scanner.
                    </p>

                    <form method="GET" action="manage_cafe#cafe-status" style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                        <input type="text" name="student_q" value="<?php echo htmlspecialchars($studentQ); ?>" placeholder="Student ID or name" style="flex: 1; min-width: 220px; padding: 10px; border: 1px solid var(--border-color);">
                        <select name="status_filter" style="padding: 10px; border: 1px solid var(--border-color);" aria-label="Current café status">
                            <option value="">Any status</option>
                            <option value="Cafe user" <?php echo $statusFilter === 'Cafe user' ? 'selected' : ''; ?>>Cafe user</option>
                            <option value="Non Cafe" <?php echo $statusFilter === 'Non Cafe' ? 'selected' : ''; ?>>Non Cafe</option>
                        </select>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                    </form>

                    <?php if ($cafeSearched && !$cafeStudents): ?>
                        <p style="color: var(--secondary-color);">No students match this search in your campus.</p>
                    <?php elseif ($cafeStudents): ?>

                    <!-- Batch: applies to the ticked rows -->
                    <form method="POST" action="manage_cafe#cafe-status" id="cafeBatchForm" onsubmit="return confirmCafeBatch(this);"
                          style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 12px; margin-bottom: 10px; border: 1px solid var(--border-color); border-radius: 10px; background: var(--table-head-bg);">
                        <input type="hidden" name="action" value="set_cafe_status_batch">
                        <input type="hidden" name="student_q" value="<?php echo htmlspecialchars($studentQ); ?>">
                        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($statusFilter); ?>">
                        <strong><span id="cafeSelCount">0</span> selected</strong>
                        <select name="cafeteria_gate" style="padding: 6px 8px; border: 1px solid var(--border-color);" aria-label="Cafe gate for Cafe users">
                            <option value="">Keep current gate</option>
                            <?php echo $gateOptions; ?>
                        </select>
                        <button type="submit" name="cafe_type" value="Cafe user" class="btn btn-sm btn-success"><i class="fas fa-utensils"></i> Set selected to Cafe user</button>
                        <button type="submit" name="cafe_type" value="Non Cafe" class="btn btn-sm btn-warning"><i class="fas fa-ban"></i> Set selected to Non Cafe</button>
                    </form>

                    <div style="overflow-x: auto;">
                    <table style="width: 100%;">
                        <thead>
                            <tr style="text-align: left;">
                                <th style="padding: 10px; width: 36px;"><input type="checkbox" id="cafeSelAll" aria-label="Select all students in this list"></th>
                                <th style="padding: 10px;">ID</th>
                                <th style="padding: 10px;">Name</th>
                                <th style="padding: 10px;">Department</th>
                                <th style="padding: 10px;">Enrollment</th>
                                <th style="padding: 10px;">Café status</th>
                                <th style="padding: 10px; text-align: right;">Change</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cafeStudents as $cs): $csIsCafe = ($cs['cafe_type'] === 'Cafe user'); $csLocked = ($cs['enrollment_status'] === 'Graduated'); ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px;">
                                    <?php if (!$csLocked): ?>
                                    <input type="checkbox" class="cafe-sel" form="cafeBatchForm" name="student_ids[]" value="<?php echo htmlspecialchars($cs['student_id']); ?>" aria-label="Select <?php echo htmlspecialchars($cs['full_name']); ?>">
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px;"><?php echo htmlspecialchars($cs['student_id']); ?></td>
                                <td style="padding: 10px;"><?php echo htmlspecialchars($cs['full_name']); ?></td>
                                <td style="padding: 10px;"><?php echo htmlspecialchars($cs['department']); ?></td>
                                <td style="padding: 10px;"><?php echo htmlspecialchars($cs['enrollment_status']); ?></td>
                                <td style="padding: 10px;">
                                    <span class="status-pill <?php echo $csIsCafe ? 'status-success' : 'status-warning'; ?>">
                                        <?php echo $csIsCafe ? 'Cafe user' : 'Non Cafe'; ?>
                                    </span>
                                    <?php if ($csIsCafe && $cs['cafeteria_gate'] !== '' && $cs['cafeteria_gate'] !== null): ?>
                                        <span style="font-size: 12px; color: var(--secondary-color);"><?php echo htmlspecialchars($cs['cafeteria_gate']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px; text-align: right;">
                                    <?php if ($csLocked): ?>
                                        <span style="font-size: 12px; color: var(--secondary-color);"><i class="fas fa-lock"></i> Graduated</span>
                                    <?php else: ?>
                                    <form method="POST" action="manage_cafe#cafe-status" style="display: inline-flex; gap: 8px; align-items: center; justify-content: flex-end;"
                                          onsubmit="return confirm('Set <?php echo htmlspecialchars(addslashes($cs['full_name'])); ?> to <?php echo $csIsCafe ? 'Non Cafe' : 'Cafe user'; ?>?');">
                                        <input type="hidden" name="action" value="set_cafe_status">
                                        <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($cs['student_id']); ?>">
                                        <input type="hidden" name="student_q" value="<?php echo htmlspecialchars($studentQ); ?>">
                                        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($statusFilter); ?>">
                                        <input type="hidden" name="cafe_type" value="<?php echo $csIsCafe ? 'Non Cafe' : 'Cafe user'; ?>">
                                        <?php if (!$csIsCafe): ?>
                                        <select name="cafeteria_gate" style="padding: 6px 8px; border: 1px solid var(--border-color);" aria-label="Cafe gate">
                                            <option value="">Cafe gate (optional)</option>
                                            <?php echo $gateOptions; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-utensils"></i> Set Cafe user</button>
                                        <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-warning"><i class="fas fa-ban"></i> Set Non Cafe</button>
                                        <?php endif; ?>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php if (count($cafeStudents) === $cafeListLimit): ?>
                        <p style="font-size: 12px; color: var(--secondary-color); margin-top: 10px;">Showing the first <?php echo $cafeListLimit; ?> matches. Narrow the search to see others.</p>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- Batch: paste a list of IDs -->
                    <details style="margin-top: 18px; border-top: 1px solid var(--border-color); padding-top: 14px;">
                        <summary style="cursor: pointer; font-weight: 600;"><i class="fas fa-list-ol"></i> Batch update from a list of student IDs</summary>
                        <form method="POST" action="manage_cafe#cafe-status" onsubmit="return confirmCafeBatch(this);" style="display: flex; flex-direction: column; gap: 10px; margin-top: 12px; max-width: 520px;">
                            <input type="hidden" name="action" value="set_cafe_status_batch">
                            <input type="hidden" name="student_q" value="<?php echo htmlspecialchars($studentQ); ?>">
                            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($statusFilter); ?>">
                            <label for="cafeIdList" style="font-size: 14px;">Student IDs, one per line or separated by commas (up to 500)</label>
                            <textarea name="id_list" id="cafeIdList" rows="6" required placeholder="OSU001&#10;OSU002&#10;OSU003" style="padding: 10px; border: 1px solid var(--border-color); font-family: inherit;"></textarea>
                            <select name="cafeteria_gate" style="padding: 8px; border: 1px solid var(--border-color);" aria-label="Cafe gate for Cafe users">
                                <option value="">Keep current gate</option>
                                <?php echo $gateOptions; ?>
                            </select>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button type="submit" name="cafe_type" value="Cafe user" class="btn btn-success"><i class="fas fa-utensils"></i> Set all to Cafe user</button>
                                <button type="submit" name="cafe_type" value="Non Cafe" class="btn btn-warning"><i class="fas fa-ban"></i> Set all to Non Cafe</button>
                            </div>
                        </form>
                    </details>
                </div>

                <script>
                    // Batch selection helpers for the Student Café Status card
                    (function () {
                        const boxes = Array.from(document.querySelectorAll('.cafe-sel'));
                        const all = document.getElementById('cafeSelAll');
                        const count = document.getElementById('cafeSelCount');
                        function refresh() {
                            const n = boxes.filter(b => b.checked).length;
                            if (count) count.textContent = n;
                            if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
                        }
                        boxes.forEach(b => b.addEventListener('change', refresh));
                        if (all) all.addEventListener('change', () => { boxes.forEach(b => { b.checked = all.checked; }); refresh(); });
                        refresh();
                    })();

                    // Ask before a batch change, naming the status and how many students
                    function confirmCafeBatch(form) {
                        const status = (document.activeElement && document.activeElement.name === 'cafe_type') ? document.activeElement.value : 'the chosen status';
                        let n;
                        if (form.id === 'cafeBatchForm') {
                            n = document.querySelectorAll('.cafe-sel:checked').length;
                            if (n === 0) { alert('Tick at least one student first.'); return false; }
                        } else {
                            n = (form.id_list.value.split(/[\s,;]+/).filter(Boolean)).length;
                        }
                        return confirm('Set ' + n + ' student' + (n === 1 ? '' : 's') + ' to ' + status + '?');
                    }
                </script>
                <?php endif; ?>

                <!-- Manage Cafe Types -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-list"></i> Café Gates
                    </div>
                    
                    <?php if ($isCafeAdmin): ?>
                    <form method="POST" style="margin-bottom: 20px; display: flex; gap: 10px;">
                        <input type="hidden" name="action" value="add_type">
                        <input type="text" name="type_name" placeholder="New Café Gate (e.g. Gate 4)" required style="flex: 1; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add</button>
                    </form>
                    <?php endif; ?>

                    <ul style="list-style: none; padding: 0;">
                        <?php foreach ($cafe_types as $type): ?>
                        <li style="display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid var(--border-color);">
                            <span><?php echo htmlspecialchars($type['cafe_gate_name']); ?></span>
                            <?php if ($isCafeAdmin): ?>
                            <form method="POST" onsubmit="return confirm('Are you sure? This will remove this gate from all students assigned to it.');">
                                <input type="hidden" name="action" value="delete_type">
                                <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
                                <input type="hidden" name="type_name" value="<?php echo htmlspecialchars($type['cafe_gate_name']); ?>">
                                <button type="submit" class="btn btn-sm btn-danger" style="background: var(--danger-fill); color: white;"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php else: ?>
                            <span style="font-size: 11px; color: var(--secondary-color);"><i class="fas fa-lock"></i> View Only</span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Bulk Import Cafe Scans (Offline Fallback) -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-file-upload"></i> Bulk Import Scans (Offline Fallback)
                    </div>
                    <div style="padding: 15px;">
                        <p style="color: var(--secondary-color); font-size: 14px; margin-bottom: 15px;">
                            Use this feature to upload cafeteria scan logs in bulk when recovering from a power outage or system downtime.
                            The system will automatically prevent duplicate entries for the same student, meal, and date context.
                        </p>
                        
                        <div style="margin-bottom: 15px;">
                            <a href="?action=download_template" class="btn btn-primary" style="background: var(--primary-fill); border-color: var(--primary-color);">
                                <i class="fas fa-download"></i> Download CSV Template
                            </a>
                        </div>

                        <form method="POST" enctype="multipart/form-data" style="background: var(--card-bg); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 10px;">
                            <input type="hidden" name="action" value="upload_csv_scans">
                            
                            <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                            <label style="font-weight: bold; font-size: 14px; color: var(--text-color);">Select Campus for Records:</label>
                            <select name="campus_id" required style="padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); max-width: 400px;">
                                <option value="">-- Select Campus --</option>
                                <?php 
                                $rCampuses = $pdo->query("SELECT * FROM campuses")->fetchAll();
                                foreach ($rCampuses as $rc): ?>
                                    <option value="<?php echo $rc['id']; ?>"><?php echo htmlspecialchars($rc['campus_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>

                            <label style="font-weight: bold; font-size: 14px; color: var(--text-color);">Upload Completed CSV File:</label>
                            <input type="file" name="csv_file" accept=".csv" required style="padding: 10px; border: 1px dashed var(--border-color); border-radius: 6px; max-width: 400px; color: var(--text-color);">
                            
                            <div>
                                <button type="submit" class="btn btn-primary" style="margin-top: 10px;"><i class="fas fa-upload"></i> Upload & Process Records</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <script>
        function toggleTheme() {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
        }
        if (localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');
    </script>
</body>
</html>
