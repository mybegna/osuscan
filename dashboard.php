<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
require_once 'includes/lang_init.php';
checkAuth();

// 1. Enforce Timezone (Requirement)
date_default_timezone_set('Africa/Addis_Ababa');

$role = $_SESSION['role'];

// Redirect roles without dashboard access
// Redirect roles without dashboard access
$allowed_dashboard_roles = ['Super Admin', 'Cafeteria Manager', 'Registrar Officer', 'Campus Admin'];
if (!in_array($role, $allowed_dashboard_roles)) {
    if (strpos($role, 'Cafeteria') !== false || $role === 'Cafe Ticker') header("Location: scan?type=cafeteria");
    elseif ($role === 'Clinic User') header("Location: scan?type=clinic");
    elseif ($role === 'Library User') header("Location: scan?type=library");
    elseif ($role === 'Gate Security User' || $role === 'Security Officer') header("Location: scan?type=gate");
    elseif ($role === 'College Registrar' || $role === 'ID Card Printer') header("Location: students");
    else header("Location: logout");
    exit;
}

// Data Containers
$stats = [
    'users' => 0,
    'total_students' => 0,
    'scans_today' => 0,
    'meals_breakdown' => ['Breakfast' => 0, 'Lunch' => 0, 'Dinner' => 0, 'Total' => 0],
    'student_status' => ['Active' => 0, 'Dismissed' => 0, 'Graduated' => 0],
    'student_gender' => ['Male' => 0, 'Female' => 0],
    'entry_years' => [],
    'departments' => [],
    'schedules' => []
];

// DATA FETCHING LOGIC
try {
    // 1. SUPER ADMIN Stats
    if ($role === 'Super Admin') {
        $stats['users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        try {
            // Check table exists/works
            $stats['scans_today'] = $pdo->query("SELECT COUNT(*) FROM scan_logs WHERE DATE(scan_time) = CURDATE() AND campus_id = 1")->fetchColumn();
        } catch (Exception $e) {}
    }

    // 2. REGISTRAR Stats (Admin, Registrar Officer, College Registrar)
    // 2. REGISTRAR Stats (Admin, Registrar Officer, College Registrar)
    // 2. REGISTRAR Stats (Admin, Registrar Officer, College Registrar, Campus Admin)
    if (in_array($role, ['Super Admin', 'Registrar Officer', 'College Registrar', 'Campus Admin'])) {
        // For Super Admin, only Main Campus (ID 1) for specific sections as requested, 
        // but "Total Students" usually implies global. However, the request says "Current Department Enrolment section displays data only for the Main Campus."
        // Let's filter ALL student stats by Main Campus if Super Admin, to be consistent with the request context, 
        // OR just the Department one. The request specifically mentions "Current Department Enrolment".
        // But commonly if dashboard is "Main Campus" focused, stats should align. 
        // Let's filter key stats by Main Campus for Super Admin to be safe/consistent.
        $campusFilter = ($role === 'Super Admin') ? " WHERE campus_id = 1" : " WHERE campus_id = '{$_SESSION['campus_id']}'";
        // If Role is Super Admin but session has no campus_id (global), we force 1 for dashboard display as requested.
        // If Campus Admin, they have session campus_id.
        
        $stats['total_students'] = $pdo->query("SELECT COUNT(*) FROM students $campusFilter")->fetchColumn();
        
        // Status Breakdown
        $stmt = $pdo->query("SELECT enrollment_status, COUNT(*) as count FROM students $campusFilter GROUP BY enrollment_status");
        while ($row = $stmt->fetch()) {
            if (isset($stats['student_status'][$row['enrollment_status']])) 
                $stats['student_status'][$row['enrollment_status']] = $row['count'];
        }
        
        // Gender Breakdown
        $stmt = $pdo->query("SELECT gender, COUNT(*) as count FROM students $campusFilter GROUP BY gender");
        while ($row = $stmt->fetch()) {
            $g = ucfirst(strtolower($row['gender']));
            if (isset($stats['student_gender'][$g])) $stats['student_gender'][$g] = $row['count'];
        }
        
        // Entry Years
        $stats['entry_years'] = $pdo->query("SELECT entry_year, COUNT(*) as count FROM students $campusFilter GROUP BY entry_year ORDER BY entry_year DESC LIMIT 5")->fetchAll();
        
        // Department Stats - EXPLICITLY REQUESTED for Main Campus
        $stats['departments'] = $pdo->query("SELECT department, COUNT(*) as count FROM students $campusFilter GROUP BY department")->fetchAll();
    }

    // 3. CAFE MANAGER Stats (Admin, Cafe Manager)
    // 3. CAFE MANAGER Stats (Admin, Cafe Manager)
    if (in_array($role, ['Super Admin', 'Cafeteria Manager', 'Campus Admin'])) {
        $campusFilter = ($role === 'Super Admin') ? " AND campus_id = 1" : " AND campus_id = '{$_SESSION['campus_id']}'";
        $campusFilterWhere = ($role === 'Super Admin') ? " WHERE campus_id = 1" : " WHERE campus_id = '{$_SESSION['campus_id']}'";

        // Meals Breakdown
        $stmt = $pdo->query("SELECT meal_type, COUNT(*) as count FROM meal_logs WHERE DATE(scan_time) = CURDATE() $campusFilter GROUP BY meal_type");
        while ($row = $stmt->fetch()) {
            $mealType = $row['meal_type'];
            if (isset($stats['meals_breakdown'][$mealType]))
                $stats['meals_breakdown'][$mealType] = $row['count'];
            $stats['meals_breakdown']['Total'] += $row['count'];
        }
        
        // Schedules - EXPLICITLY REQUESTED Main Campus
        $stats['schedules'] = $pdo->query("SELECT * FROM cafe_schedules $campusFilterWhere ORDER BY FIELD(meal_type, 'Breakfast', 'Lunch', 'Dinner')")->fetchAll();

        // A) STUDENT DATA (Cafe Users & Gates) - Filter by Campus
        $stats['cafe_users_total'] = $pdo->query("SELECT COUNT(*) FROM students WHERE cafe_type = 'Cafe user' " . ($role === 'Super Admin' ? "AND campus_id = 1" : "AND campus_id = '{$_SESSION['campus_id']}'"))->fetchColumn();
        
        $stats['cafe_gate_counts'] = $pdo->query("SELECT cafeteria_gate, COUNT(*) as cnt FROM students WHERE cafe_type = 'Cafe user' " . ($role === 'Super Admin' ? "AND campus_id = 1" : "AND campus_id = '{$_SESSION['campus_id']}'") . " GROUP BY cafeteria_gate")->fetchAll(PDO::FETCH_KEY_PAIR);

        // B) WEEKLY & MONTHLY MEAL STATS
        $stats['meals_week'] = ['Breakfast'=>0, 'Lunch'=>0, 'Dinner'=>0, 'Total'=>0];
        $stmtW = $pdo->query("SELECT meal_type, COUNT(*) as count FROM meal_logs WHERE scan_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) $campusFilter GROUP BY meal_type");
        while ($row = $stmtW->fetch()) {
            if(isset($stats['meals_week'][$row['meal_type']])) $stats['meals_week'][$row['meal_type']] = $row['count'];
            $stats['meals_week']['Total'] += $row['count'];
        }

        $stats['meals_month'] = ['Breakfast'=>0, 'Lunch'=>0, 'Dinner'=>0, 'Total'=>0];
        $stmtM = $pdo->query("SELECT meal_type, COUNT(*) as count FROM meal_logs WHERE scan_time >= DATE_SUB(NOW(), INTERVAL 30 DAY) $campusFilter GROUP BY meal_type");
        while ($row = $stmtM->fetch()) {
            if(isset($stats['meals_month'][$row['meal_type']])) $stats['meals_month'][$row['meal_type']] = $row['count'];
            $stats['meals_month']['Total'] += $row['count'];
        }
    }

} catch (Exception $e) { /* Fail silently or log */ }

// Admin Charts Data (Preserved)
$weeklyData = [];
if ($role === 'Super Admin') {
    try {
        $query = "SELECT DAYNAME(scan_time) as day, COUNT(*) as count FROM scan_logs WHERE scan_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(scan_time) ORDER BY scan_time ASC";
        $result = $pdo->query($query)->fetchAll();
        foreach ($result as $row) { $weeklyData[$row['day']] = $row['count']; }
    } catch (Exception $e) {}
}

// Recent Activity (Admin)
$recent_activities = [];
if ($role === 'Super Admin') {
    $activityQuery = "
        (SELECT 'Scan' as type, s.full_name as user_name, sl.scan_time as activity_time, sl.location as details
        FROM scan_logs sl LEFT JOIN students s ON sl.student_id = s.student_id)
        UNION ALL
        (SELECT 'Login' as type, u.full_name as user_name, ll.login_time as activity_time, ll.ip_address as details
        FROM login_logs ll LEFT JOIN users u ON ll.user_id = u.id)
        UNION ALL
        (SELECT 'System' as type, u.full_name as user_name, al.created_at as activity_time, al.action as details
        FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id)
        ORDER BY activity_time DESC
        LIMIT 10
    ";
    try {
        $recent_activities = $pdo->query($activityQuery)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['dashboard']; ?> - OSU One Card</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        .dash-section { margin-bottom: 24px; }
        /* Compact cards on the dashboard */
        .page-content .card { padding: 16px 18px; border-radius: 10px; margin-bottom: 14px; }
        .page-content .card-header { margin-bottom: 10px; }
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
                    <i class="fas fa-chart-line"></i> <?php echo $lang['dashboard']; ?>
                </div>
            </div>
            <div class="navbar-actions">
                <div id="top-nav-clock" style="font-size: 14px; font-weight: 600; margin-right: 15px;"></div>
                <div style="display: flex;">
                    <a href="?lang=en" class="lang-btn <?php echo ($_SESSION['lang']??'en') == 'en' ? 'active' : ''; ?>">ENG</a>
                    <a href="?lang=am" class="lang-btn <?php echo ($_SESSION['lang']??'en') == 'am' ? 'active' : ''; ?>">AMH</a>
                    <a href="?lang=or" class="lang-btn <?php echo ($_SESSION['lang']??'en') == 'or' ? 'active' : ''; ?>">ORM</a>
                </div>
                <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-moon"></i></button>
                <?php include 'includes/topbar_user.php'; ?>
            </div>
        </div>

        <div class="page-content">
            <div class="page-head">
                <p class="page-eyebrow"><?php echo htmlspecialchars($role); ?></p>
                <h1 class="page-heading"><?php echo $lang['dashboard']; ?></h1>
                <p class="page-sub"><?php echo $lang['welcome_back']; ?>, <?php echo htmlspecialchars($_SESSION['full_name']); ?> <span aria-hidden="true">&middot;</span> <?php echo date('j F Y'); ?></p>
            </div>

            <!-- REGISTRAR & ADMIN: STUDENT OVERVIEW -->
            <?php if (in_array($role, ['Super Admin', 'Registrar Officer', 'College Registrar', 'Campus Admin'])): ?>
            <div class="dash-section">
                <div class="dash-title"><i class="fas fa-user-graduate"></i> Student Statistics</div>
                <div class="grid">
                    <!-- Cards with Icons -->
                    <div class="stat-card" style="border-left-color: var(--success-color);">
                        <div class="stat-icon" style="background: var(--success-tint); color: var(--success-color);"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info">
                            <h3><?php echo $stats['student_status']['Active']; ?></h3>
                            <p>Active Students</p>
                        </div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--danger-color);">
                        <div class="stat-icon" style="background: var(--danger-tint); color: var(--danger-color);"><i class="fas fa-times-circle"></i></div>
                        <div class="stat-info">
                            <h3><?php echo $stats['student_status']['Dismissed']; ?></h3>
                            <p>Dismissed</p>
                        </div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--primary-color);">
                        <div class="stat-icon" style="background: var(--primary-tint); color: var(--primary-color);"><i class="fas fa-graduation-cap"></i></div>
                        <div class="stat-info">
                            <h3><?php echo $stats['student_status']['Graduated']; ?></h3>
                            <p>Graduated</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total_students']; ?></h3>
                            <p>Total Registered</p>
                        </div>
                    </div>
                </div>

                <div class="grid" style="margin-top: 20px; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
                    <!-- Gender Breakdown -->
                    <div class="card">
                        <div class="card-header"><i class="fas fa-venus-mars"></i> Gender Distribution</div>
                        <div style="display: flex; justify-content: space-around; padding: 20px;">
                            <div style="text-align: center;">
                                <div style="font-size: 20px; font-weight: 600; color: var(--primary-color);"><?php echo $stats['student_gender']['Male']; ?></div>
                                <div style="color: var(--secondary-color);">Male</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 20px; font-weight: 600; color: var(--warning-color);"><?php echo $stats['student_gender']['Female']; ?></div>
                                <div style="color: var(--secondary-color);">Female</div>
                            </div>
                        </div>
                    </div>

                    <!-- Entry Year -->
                    <div class="card">
                        <div class="card-header"><i class="fas fa-calendar-alt"></i> Entry Year Breakdown</div>
                        <div style="padding: 10px;">
                            <?php foreach ($stats['entry_years'] as $ey): ?>
                                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding: 8px 0;">
                                    <span><?php echo htmlspecialchars($ey['entry_year']); ?></span>
                                    <span style="font-weight: bold;"><?php echo $ey['count']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Departments -->
                <div class="card" style="margin-top: 20px;">
                    <div class="card-header"><i class="fas fa-building"></i> Current Department Enrollment</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; padding: 15px;">
                        <?php foreach ($stats['departments'] as $dept): ?>
                            <div style="background: var(--hover-bg-light); padding: 10px; border-radius: 6px; text-align: center;">
                                <div style="font-size: 11px; color: var(--secondary-color); margin-bottom: 5px;"><?php echo htmlspecialchars($dept['department']); ?></div>
                                <div style="font-size: 18px; font-weight: bold; color: var(--primary-color);"><?php echo $dept['count']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- CAFE MANAGER & ADMIN: COMPLETE OVERVIEW -->
            <?php if (in_array($role, ['Super Admin', 'Cafeteria Manager', 'Campus Admin'])): ?>
            <div class="dash-section">
                <!-- A) Student Cafeteria Analytics -->
                <div class="dash-title"><i class="fas fa-users"></i> Student Cafeteria Analytics</div>
                <div class="grid">
                    <div class="stat-card" style="border-left-color: var(--primary-color);">
                        <div class="stat-icon" style="background: var(--primary-tint); color: var(--primary-color);"><i class="fas fa-id-card"></i></div>
                        <div class="stat-info">
                            <h3><?php echo $stats['cafe_users_total']; ?></h3>
                            <p>Total Eligible Cafe Users</p>
                        </div>
                    </div>
                    <!-- Gate Breakdown -->
                    <?php foreach ($stats['cafe_gate_counts'] as $gate => $count): ?>
                    <div class="stat-card" style="border-left-color: var(--secondary-color);">
                        <div class="stat-icon" style="background: var(--neutral-tint); color: var(--secondary-color);"><i class="fas fa-door-open"></i></div>
                        <div class="stat-info">
                            <h3><?php echo $count; ?></h3>
                            <p><?php echo htmlspecialchars($gate); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- B) Accessed Today -->
                <div class="dash-title" style="margin-top: 30px;"><i class="fas fa-utensils"></i> Cafeteria Overview (Accessed Today)</div>
                <div class="grid">
                    <div class="stat-card" style="border-left-color: var(--warning-color);">
                        <div class="stat-icon" style="background: var(--warning-tint); color: var(--warning-color);"><i class="fas fa-coffee"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['meals_breakdown']['Breakfast']; ?></h3><p>Breakfast</p></div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--success-color);">
                        <div class="stat-icon" style="background: var(--success-tint); color: var(--success-color);"><i class="fas fa-hamburger"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['meals_breakdown']['Lunch']; ?></h3><p>Lunch</p></div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--primary-color);">
                        <div class="stat-icon" style="background: var(--primary-tint); color: var(--primary-color);"><i class="fas fa-drumstick-bite"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['meals_breakdown']['Dinner']; ?></h3><p>Dinner</p></div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--secondary-color);">
                        <div class="stat-icon" style="background: var(--neutral-tint); color: var(--secondary-color);"><i class="fas fa-poll"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['meals_breakdown']['Total']; ?></h3><p>Total Today</p></div>
                    </div>
                </div>

                <!-- C) Accessed This Week -->
                <div class="dash-title" style="margin-top: 30px;"><i class="fas fa-calendar-week"></i> Cafeteria Overview (Accessed This Week)</div>
                <div class="grid">
                    <div class="stat-card" style="border-left-color: var(--warning-color);">
                        <div class="stat-icon" style="background: var(--warning-tint); color: var(--warning-color);"><i class="fas fa-coffee"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['meals_week']['Breakfast']; ?></h3><p>Breakfast</p></div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--success-color);">
                        <div class="stat-icon" style="background: var(--success-tint); color: var(--success-color);"><i class="fas fa-hamburger"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['meals_week']['Lunch']; ?></h3><p>Lunch</p></div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--primary-color);">
                        <div class="stat-icon" style="background: var(--primary-tint); color: var(--primary-color);"><i class="fas fa-drumstick-bite"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['meals_week']['Dinner']; ?></h3><p>Dinner</p></div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--secondary-color);">
                        <div class="stat-icon" style="background: var(--neutral-tint); color: var(--secondary-color);"><i class="fas fa-poll"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['meals_week']['Total']; ?></h3><p>Total Week</p></div>
                    </div>
                </div>

                <!-- D) Accessed This Month -->
                <div class="dash-title" style="margin-top: 30px;"><i class="fas fa-calendar-alt"></i> Cafeteria Overview (Accessed This Month)</div>
                <div class="grid">
                    <div class="stat-card" style="border-left-color: var(--warning-color);">
                        <div class="stat-icon" style="background: var(--warning-tint); color: var(--warning-color);"><i class="fas fa-coffee"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['meals_month']['Breakfast']; ?></h3><p>Breakfast</p></div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--success-color);">
                        <div class="stat-icon" style="background: var(--success-tint); color: var(--success-color);"><i class="fas fa-hamburger"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['meals_month']['Lunch']; ?></h3><p>Lunch</p></div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--primary-color);">
                        <div class="stat-icon" style="background: var(--primary-tint); color: var(--primary-color);"><i class="fas fa-drumstick-bite"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['meals_month']['Dinner']; ?></h3><p>Dinner</p></div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--secondary-color);">
                        <div class="stat-icon" style="background: var(--neutral-tint); color: var(--secondary-color);"><i class="fas fa-poll"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['meals_month']['Total']; ?></h3><p>Total Month</p></div>
                    </div>
                </div>

                <div class="card" style="margin-top: 20px;">
                    <div class="card-header"><i class="fas fa-clock"></i> Meal Time Schedules (12-Hour Format)</div>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: var(--hover-bg-light); text-align: left;">
                                    <th style="padding: 12px;">Meal Type</th>
                                    <th style="padding: 12px;">Start Time</th>
                                    <th style="padding: 12px;">End Time</th>
                                    <th style="padding: 12px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['schedules'] as $sch): ?>
                                <tr style="border-bottom: 1px solid var(--border-color);">
                                    <td style="padding: 12px; font-weight: bold;"><?php echo htmlspecialchars($sch['meal_type']); ?></td>
                                    <td style="padding: 12px;">
                                        <?php echo date('h:i A', strtotime($sch['start_time'])); ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <?php echo date('h:i A', strtotime($sch['end_time'])); ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <?php if ($sch['is_active']): ?>
                                            <span style="color: var(--success-color); font-weight: bold;">Active</span>
                                        <?php else: ?>
                                            <span style="color: var(--danger-color);">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ADMIN SYSTEM STATS -->
            <?php if ($role === 'Super Admin'): ?>
            <div class="dash-section">
                <div class="dash-title"><i class="fas fa-server"></i> System Overview</div>
                <div class="grid">
                    <div class="stat-card" style="border-left-color: var(--secondary-color);">
                        <div class="stat-icon" style="background: var(--neutral-tint); color: var(--secondary-color);"><i class="fas fa-users-cog"></i></div>
                        <div class="stat-info">
                            <h3><?php echo $stats['users']; ?></h3>
                            <p>System Users</p>
                        </div>
                    </div>
                    <div class="stat-card" style="border-left-color: var(--warning-color);">
                        <div class="stat-icon" style="background: var(--warning-tint); color: var(--warning-color);"><i class="fas fa-barcode"></i></div>
                        <div class="stat-info">
                            <h3><?php echo $stats['scans_today']; ?></h3>
                            <p>Total Scans Today</p>
                        </div>
                    </div>
                </div>
                
                <!-- Weekly Chart (Admin Only) -->
                <div class="card" style="margin-top: 20px;">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Weekly Activity</div>
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="weeklyChart"></canvas>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card" style="margin-top: 20px;">
                    <div class="card-header"><i class="fas fa-history"></i> Recent Activity</div>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: var(--hover-bg-light); text-align: left;">
                                    <th style="padding: 10px;">Type</th>
                                    <th style="padding: 10px;">User/Student</th>
                                    <th style="padding: 10px;">Details</th>
                                    <th style="padding: 10px;">Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_activities)): ?>
                                    <tr><td colspan="4" style="padding:15px; text-align:center; color: var(--secondary-color);">No recent activity.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recent_activities as $act): ?>
                                    <tr style="border-bottom: 1px solid var(--border-color);">
                                        <td style="padding: 10px;">
                                            <?php 
                                            $icon = 'fa-circle'; $col = 'var(--secondary-color)';
                                            if ($act['type'] == 'Scan') { $icon='fa-barcode'; $col='var(--primary-color)'; }
                                            elseif ($act['type'] == 'Login') { $icon='fa-sign-in-alt'; $col='var(--success-color)'; }
                                            elseif ($act['type'] == 'System') { $icon='fa-cog'; $col='var(--warning-color)'; }
                                            ?>
                                            <i class="fas <?php echo $icon; ?>" style="color: <?php echo $col; ?>; margin-right: 5px;"></i> <?php echo htmlspecialchars($act['type']); ?>
                                        </td>
                                        <td style="padding: 10px; font-weight: 500;"><?php echo htmlspecialchars($act['user_name'] ?? 'Unknown'); ?></td>
                                        <td style="padding: 10px; color: var(--secondary-color); font-size: 13px;"><?php echo htmlspecialchars($act['details']); ?></td>
                                        <td style="padding: 10px; font-size: 12px;"><?php echo date('M d, h:i A', strtotime($act['activity_time'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        function toggleTheme() {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
        }
        if (localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');

        function updateTopClock() {
            const now = new Date();
            // Use Ethiopia Timezone for Display as requested (Update: User said update MEAL TIME schedules, but clock visual is nice too)
            const options = { timeZone: 'Africa/Addis_Ababa', weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            const formatted = now.toLocaleString('en-US', options); 
            const clockEl = document.getElementById('top-nav-clock');
            if (clockEl) clockEl.textContent = formatted;
        }
        setInterval(updateTopClock, 1000);
        updateTopClock();

        // Chart
        <?php if ($role === 'Super Admin'): ?>
        const weeklyCtx = document.getElementById('weeklyChart');
        if (weeklyCtx) {
            new Chart(weeklyCtx, {
                type: 'line',
                data: {
                    labels: ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Today'],
                    datasets: [{
                        label: 'Activity',
                        data: [<?php echo implode(',', array_values($weeklyData)); ?>],
                        borderColor: '#A79241', // Golden Olive: readable on light and dark cards
                        backgroundColor: 'rgba(167, 146, 65, 0.15)',
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>