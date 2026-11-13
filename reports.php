<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
require_once 'includes/lang_init.php';
checkAuth();

// ---------- Role-based report permissions ----------
// Keys: cafeteria, gate, clinic, library
$roleReportAccess = [
    'Super Admin'       => ['cafeteria', 'gate', 'clinic', 'library'],
    'Campus Admin'      => ['cafeteria', 'gate', 'clinic', 'library'],
    'Cafeteria Manager' => ['cafeteria'],
    'Cafe Manager'      => ['cafeteria'],
    'Security Officer'  => ['gate'],
];

$currentRole = $_SESSION['role'];
$allowedSections = $roleReportAccess[$currentRole] ?? [];

if (empty($allowedSections)) {
    die("<div style='padding: 20px; color: #6B4E3D;'><h2>Access Denied</h2></div>");
}

$canSeeCafeteria = in_array('cafeteria', $allowedSections);
$canSeeGate      = in_array('gate',      $allowedSections);
$canSeeClinic    = in_array('clinic',    $allowedSections);
$canSeeLibrary   = in_array('library',   $allowedSections);

$reportData = [];
$reportType = '';
$period = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $period = $_POST['period'];

    // --- Backend enforcement: map action+location to a section key ---
    $requestedSection = '';
    if ($action === 'meal_report') {
        $requestedSection = 'cafeteria';
    } elseif ($action === 'activity_report') {
        $loc = $_POST['location'] ?? '';
        if ($loc === 'Main Gate')    $requestedSection = 'gate';
        elseif ($loc === 'Clinic')   $requestedSection = 'clinic';
        elseif ($loc === 'Library')  $requestedSection = 'library';
        elseif ($loc === 'Cafeteria') $requestedSection = 'cafeteria';
        else $requestedSection = 'gate'; // default fallback
    }

    if (!in_array($requestedSection, $allowedSections)) {
        $reportType = "Error: You do not have permission to view this report.";
    } else {
        // Determine Date Range
        $startDate = '';
        if ($period === 'daily')      $startDate = date('Y-m-d 00:00:00');
        elseif ($period === 'weekly')  $startDate = date('Y-m-d 00:00:00', strtotime('-7 days'));
        elseif ($period === 'monthly') $startDate = date('Y-m-d 00:00:00', strtotime('-30 days'));
        elseif ($period === 'semester')$startDate = date('Y-m-d 00:00:00', strtotime('-6 months'));

        if ($currentRole === 'Super Admin') {
            $target_campus = $_POST['campus_id'] ?? ($_SESSION['campus_id'] ?? null);
        } else {
            $target_campus = $_SESSION['campus_id'] ?? null;
        }

        if ($currentRole === 'Super Admin' && empty($target_campus)) {
            $reportData = [];
            $reportType = "Error: Please select a campus.";
        } else {
            if ($action === 'meal_report') {
                $reportType = "Cafeteria Meal Report (" . ucfirst($period) . ")";
                $sql = "SELECT meal_type, COUNT(*) as count, DATE(scan_time) as date 
                        FROM meal_logs 
                        WHERE scan_time >= ? AND campus_id = ?
                        GROUP BY meal_type, DATE(scan_time) 
                        ORDER BY date DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$startDate, $target_campus]);
                $reportData = $stmt->fetchAll();
            } elseif ($action === 'activity_report') {
                $reportType = "Location Activity Report (" . ucfirst($period) . ")";
                $target_location = $_POST['location'] ?? '';
                
                $sql = "SELECT 
                            CASE 
                                WHEN location LIKE '%Cafeteria%' OR location LIKE '%Breakfast%' OR location LIKE '%Lunch%' OR location LIKE '%Dinner%' OR location = 'Cafeteria' THEN 'Cafeteria'
                                WHEN location LIKE '%Gate%' OR location = '' OR location IS NULL THEN 'Main Gate'
                                ELSE location 
                            END as location_normalized, 
                            COUNT(*) as count, 
                            DATE(scan_time) as date 
                        FROM scan_logs 
                        WHERE scan_time >= ? AND campus_id = ?";
                $params = [$startDate, $target_campus];
                
                if (!empty($target_location)) {
                    if ($target_location === 'Cafeteria') {
                        $sql .= " AND (location = 'Cafeteria' OR location LIKE '%Meal%' OR location LIKE '%Breakfast%' OR location LIKE '%Lunch%' OR location LIKE '%Dinner%')";
                    } elseif ($target_location === 'Main Gate') {
                         $sql .= " AND (location LIKE '%Gate%' OR location = '' OR location IS NULL)";
                    } else {
                        $sql .= " AND location = ?";
                        $params[] = $target_location;
                    }
                    $reportType .= " - " . $target_location;
                }
                
                $sql .= " GROUP BY 
                            CASE 
                                WHEN location LIKE '%Cafeteria%' OR location LIKE '%Breakfast%' OR location LIKE '%Lunch%' OR location LIKE '%Dinner%' OR location = 'Cafeteria' THEN 'Cafeteria'
                                WHEN location LIKE '%Gate%' OR location = '' OR location IS NULL THEN 'Main Gate'
                                ELSE location 
                            END, 
                            DATE(scan_time) 
                          ORDER BY date DESC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $reportData = [];
                while ($row = $stmt->fetch()) {
                     $row['location'] = $row['location_normalized'];
                     $reportData[] = $row;
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - OSU One Card</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
                    <i class="fas fa-chart-bar"></i> Reports
                </div>
            </div>
            <div class="navbar-actions" style="display: flex; align-items: center; gap: 10px;">
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
            
            <?php $rCampuses = $pdo->query("SELECT * FROM campuses")->fetchAll(); ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                <?php if ($canSeeCafeteria): ?>
                <!-- Cafeteria Meal Reports -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-utensils"></i> Cafeteria Reports
                    </div>
                    <form method="POST" style="padding: 20px; display: grid; gap: 10px;">
                        <input type="hidden" name="action" value="meal_report">
                        
                        <?php if ($currentRole === 'Super Admin'): ?>
                        <label>Select Campus:</label>
                        <select name="campus_id" required style="padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                            <option value="">-- Select Campus --</option>
                            <?php foreach ($rCampuses as $rc): ?>
                                <option value="<?php echo $rc['id']; ?>"><?php echo htmlspecialchars($rc['campus_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>

                        <label>Select Period:</label>
                        <select name="period" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                            <option value="daily">Daily (Today)</option>
                            <option value="weekly">Weekly (Last 7 Days)</option>
                            <option value="monthly">Monthly (Last 30 Days)</option>
                            <option value="semester">Semester (Last 6 Months)</option>
                        </select>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-chart-pie"></i> Generate Cafeteria Report</button>
                    </form>
                </div>
                <?php endif; ?>

                <?php if ($canSeeGate): ?>
                <!-- Gate Reports -->
                <div class="card">
                     <div class="card-header">
                        <i class="fas fa-door-open"></i> Gate Reports
                     </div>
                     <form method="POST" style="padding: 20px; display: grid; gap: 10px;">
                        <input type="hidden" name="action" value="activity_report">
                        <input type="hidden" name="location" value="Main Gate">
                        <?php if ($currentRole === 'Super Admin'): ?>
                        <label>Select Campus:</label>
                        <select name="campus_id" required style="padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                            <option value="">-- Select Campus --</option>
                            <?php foreach ($rCampuses as $rc): ?>
                                <option value="<?php echo $rc['id']; ?>"><?php echo htmlspecialchars($rc['campus_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <label>Select Period:</label>
                        <select name="period" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                            <option value="daily">Daily (Today)</option>
                            <option value="weekly">Weekly (Last 7 Days)</option>
                            <option value="monthly">Monthly (Last 30 Days)</option>
                            <option value="semester">Semester (Last 6 Months)</option>
                        </select>
                        <button type="submit" class="btn btn-primary" style="background: var(--success-fill); border-color:var(--success-color);"><i class="fas fa-chart-line"></i> Generate Gate Report</button>
                     </form>
                </div>
                <?php endif; ?>

                <?php if ($canSeeClinic): ?>
                <!-- Clinic Reports -->
                <div class="card">
                     <div class="card-header">
                        <i class="fas fa-notes-medical"></i> Clinic Reports
                     </div>
                     <form method="POST" style="padding: 20px; display: grid; gap: 10px;">
                        <input type="hidden" name="action" value="activity_report">
                        <input type="hidden" name="location" value="Clinic">
                        <?php if ($currentRole === 'Super Admin'): ?>
                        <label>Select Campus:</label>
                        <select name="campus_id" required style="padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                            <option value="">-- Select Campus --</option>
                            <?php foreach ($rCampuses as $rc): ?>
                                <option value="<?php echo $rc['id']; ?>"><?php echo htmlspecialchars($rc['campus_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <label>Select Period:</label>
                        <select name="period" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                            <option value="daily">Daily (Today)</option>
                            <option value="weekly">Weekly (Last 7 Days)</option>
                            <option value="monthly">Monthly (Last 30 Days)</option>
                            <option value="semester">Semester (Last 6 Months)</option>
                        </select>
                        <button type="submit" class="btn btn-primary" style="background: var(--primary-fill); border-color:var(--primary-color);"><i class="fas fa-chart-line"></i> Generate Clinic Report</button>
                     </form>
                </div>
                <?php endif; ?>

                <?php if ($canSeeLibrary): ?>
                <!-- Library Reports -->
                <div class="card">
                     <div class="card-header">
                        <i class="fas fa-book"></i> Library Reports
                     </div>
                     <form method="POST" style="padding: 20px; display: grid; gap: 10px;">
                        <input type="hidden" name="action" value="activity_report">
                        <input type="hidden" name="location" value="Library">
                        <?php if ($currentRole === 'Super Admin'): ?>
                        <label>Select Campus:</label>
                        <select name="campus_id" required style="padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                            <option value="">-- Select Campus --</option>
                            <?php foreach ($rCampuses as $rc): ?>
                                <option value="<?php echo $rc['id']; ?>"><?php echo htmlspecialchars($rc['campus_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <label>Select Period:</label>
                        <select name="period" style="padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                            <option value="daily">Daily (Today)</option>
                            <option value="weekly">Weekly (Last 7 Days)</option>
                            <option value="monthly">Monthly (Last 30 Days)</option>
                            <option value="semester">Semester (Last 6 Months)</option>
                        </select>
                        <button type="submit" class="btn btn-primary" style="background: var(--neutral-fill); border-color:var(--secondary-color);"><i class="fas fa-chart-line"></i> Generate Library Report</button>
                     </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Usage Statistics (Quick Glance) -->
            <div style="margin-top: 30px;">
                <h3 style="margin-bottom: 15px; color: var(--text-color);"><i class="fas fa-chart-area"></i> Usage Statistics (Quick Glance)</h3>
                
                <?php
                try {
                    $qStatsWhere = "";
                    if ($currentRole === 'Super Admin') {
                        $qStatsWhere = " AND campus_id = 1";
                    } elseif (isset($_SESSION['campus_id'])) {
                        $qStatsWhere = " AND campus_id = '{$_SESSION['campus_id']}'";
                    }

                    // Only query stats for allowed sections
                    $cafeDaily = $cafeWeekly = $cafeMonthly = 0;
                    $gateDaily = $gateWeekly = $gateMonthly = 0;
                    $clinicDaily = $clinicWeekly = $clinicMonthly = 0;
                    $libDaily = $libWeekly = $libMonthly = 0;
                    $mealTypes = [];

                    if ($canSeeCafeteria) {
                        $stmt = $pdo->query("SELECT COUNT(*) FROM meal_logs WHERE DATE(scan_time) = CURDATE() $qStatsWhere");
                        $cafeDaily = $stmt->fetchColumn();
                        $stmt = $pdo->query("SELECT COUNT(*) FROM meal_logs WHERE scan_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) $qStatsWhere");
                        $cafeWeekly = $stmt->fetchColumn();
                        $stmt = $pdo->query("SELECT COUNT(*) FROM meal_logs WHERE scan_time >= DATE_SUB(NOW(), INTERVAL 30 DAY) $qStatsWhere");
                        $cafeMonthly = $stmt->fetchColumn();
                        $stmt = $pdo->query("SELECT meal_type, COUNT(*) as cnt FROM meal_logs WHERE DATE(scan_time) = CURDATE() $qStatsWhere GROUP BY meal_type");
                        $mealTypes = $stmt->fetchAll();
                    }
                    if ($canSeeGate) {
                        $stmt = $pdo->query("SELECT COUNT(*) FROM scan_logs WHERE (location LIKE '%Gate%' OR location = '' OR location IS NULL) AND DATE(scan_time) = CURDATE() $qStatsWhere");
                        $gateDaily = $stmt->fetchColumn();
                        $stmt = $pdo->query("SELECT COUNT(*) FROM scan_logs WHERE (location LIKE '%Gate%' OR location = '' OR location IS NULL) AND scan_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) $qStatsWhere");
                        $gateWeekly = $stmt->fetchColumn();
                        $stmt = $pdo->query("SELECT COUNT(*) FROM scan_logs WHERE (location LIKE '%Gate%' OR location = '' OR location IS NULL) AND scan_time >= DATE_SUB(NOW(), INTERVAL 30 DAY) $qStatsWhere");
                        $gateMonthly = $stmt->fetchColumn();
                    }
                    if ($canSeeClinic) {
                        $stmt = $pdo->query("SELECT COUNT(*) FROM scan_logs WHERE location = 'Clinic' AND DATE(scan_time) = CURDATE() $qStatsWhere");
                        $clinicDaily = $stmt->fetchColumn();
                        $stmt = $pdo->query("SELECT COUNT(*) FROM scan_logs WHERE location = 'Clinic' AND scan_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) $qStatsWhere");
                        $clinicWeekly = $stmt->fetchColumn();
                        $stmt = $pdo->query("SELECT COUNT(*) FROM scan_logs WHERE location = 'Clinic' AND scan_time >= DATE_SUB(NOW(), INTERVAL 30 DAY) $qStatsWhere");
                        $clinicMonthly = $stmt->fetchColumn();
                    }
                    if ($canSeeLibrary) {
                        $stmt = $pdo->query("SELECT COUNT(*) FROM scan_logs WHERE location = 'Library' AND DATE(scan_time) = CURDATE() $qStatsWhere");
                        $libDaily = $stmt->fetchColumn();
                        $stmt = $pdo->query("SELECT COUNT(*) FROM scan_logs WHERE location = 'Library' AND scan_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) $qStatsWhere");
                        $libWeekly = $stmt->fetchColumn();
                        $stmt = $pdo->query("SELECT COUNT(*) FROM scan_logs WHERE location = 'Library' AND scan_time >= DATE_SUB(NOW(), INTERVAL 30 DAY) $qStatsWhere");
                        $libMonthly = $stmt->fetchColumn();
                    }
                } catch (Exception $e) {
                    $cafeDaily = $cafeWeekly = $cafeMonthly = 0;
                    $gateDaily = $gateWeekly = $gateMonthly = 0;
                    $clinicDaily = $clinicWeekly = $clinicMonthly = 0;
                    $libDaily = $libWeekly = $libMonthly = 0;
                    $mealTypes = [];
                }
                ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                    
                    <?php if ($canSeeCafeteria): ?>
                    <!-- Cafeteria Stats -->
                    <div class="card hover-elevate">
                        <div class="card-header" style="background: var(--primary-fill); color: white; border-radius: 8px 8px 0 0; padding: 10px 15px; border-bottom: none;"><i class="fas fa-utensils"></i> Cafeteria</div>
                        <div style="padding: 15px; display: flex; justify-content: space-between; text-align: center;">
                            <div><div style="font-size: 20px; font-weight: bold; color: var(--primary-color);"><?php echo $cafeDaily; ?></div><div style="font-size: 11px; color: var(--secondary-color);">Today</div></div>
                            <div><div style="font-size: 20px; font-weight: bold; color: var(--success-color);"><?php echo $cafeWeekly; ?></div><div style="font-size: 11px; color: var(--secondary-color);">7 Days</div></div>
                            <div><div style="font-size: 20px; font-weight: bold; color: var(--primary-color);"><?php echo $cafeMonthly; ?></div><div style="font-size: 11px; color: var(--secondary-color);">30 Days</div></div>
                        </div>
                        <?php if(!empty($mealTypes)): ?>
                        <div style="padding: 10px 15px; border-top: 1px solid var(--border-color); font-size: 11px; color: var(--secondary-color);">
                            <?php foreach ($mealTypes as $mt): ?>
                                <span style="margin-right: 10px;"><b><?php echo htmlspecialchars($mt['meal_type']); ?>:</b> <?php echo $mt['cnt']; ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($canSeeGate): ?>
                    <!-- Gate Stats -->
                    <div class="card hover-elevate">
                        <div class="card-header" style="background: var(--success-fill); color: white; border-radius: 8px 8px 0 0; padding: 10px 15px; border-bottom: none;"><i class="fas fa-door-open"></i> Main Gate</div>
                        <div style="padding: 15px; display: flex; justify-content: space-between; text-align: center;">
                            <div><div style="font-size: 20px; font-weight: bold; color: var(--primary-color);"><?php echo $gateDaily; ?></div><div style="font-size: 11px; color: var(--secondary-color);">Today</div></div>
                            <div><div style="font-size: 20px; font-weight: bold; color: var(--success-color);"><?php echo $gateWeekly; ?></div><div style="font-size: 11px; color: var(--secondary-color);">7 Days</div></div>
                            <div><div style="font-size: 20px; font-weight: bold; color: var(--primary-color);"><?php echo $gateMonthly; ?></div><div style="font-size: 11px; color: var(--secondary-color);">30 Days</div></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($canSeeClinic): ?>
                    <!-- Clinic Stats -->
                    <div class="card hover-elevate">
                        <div class="card-header" style="background: var(--primary-fill); color: white; border-radius: 8px 8px 0 0; padding: 10px 15px; border-bottom: none;"><i class="fas fa-notes-medical"></i> Clinic</div>
                        <div style="padding: 15px; display: flex; justify-content: space-between; text-align: center;">
                            <div><div style="font-size: 20px; font-weight: bold; color: var(--primary-color);"><?php echo $clinicDaily; ?></div><div style="font-size: 11px; color: var(--secondary-color);">Today</div></div>
                            <div><div style="font-size: 20px; font-weight: bold; color: var(--success-color);"><?php echo $clinicWeekly; ?></div><div style="font-size: 11px; color: var(--secondary-color);">7 Days</div></div>
                            <div><div style="font-size: 20px; font-weight: bold; color: var(--primary-color);"><?php echo $clinicMonthly; ?></div><div style="font-size: 11px; color: var(--secondary-color);">30 Days</div></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($canSeeLibrary): ?>
                    <!-- Library Stats -->
                    <div class="card hover-elevate">
                        <div class="card-header" style="background: var(--neutral-fill); color: white; border-radius: 8px 8px 0 0; padding: 10px 15px; border-bottom: none;"><i class="fas fa-book"></i> Library</div>
                        <div style="padding: 15px; display: flex; justify-content: space-between; text-align: center;">
                            <div><div style="font-size: 20px; font-weight: bold; color: var(--primary-color);"><?php echo $libDaily; ?></div><div style="font-size: 11px; color: var(--secondary-color);">Today</div></div>
                            <div><div style="font-size: 20px; font-weight: bold; color: var(--success-color);"><?php echo $libWeekly; ?></div><div style="font-size: 11px; color: var(--secondary-color);">7 Days</div></div>
                            <div><div style="font-size: 20px; font-weight: bold; color: var(--primary-color);"><?php echo $libMonthly; ?></div><div style="font-size: 11px; color: var(--secondary-color);">30 Days</div></div>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <?php if (!empty($reportData)): ?>
            <div class="card" style="margin-top: 30px;">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <span><i class="fas fa-table"></i> <?php echo htmlspecialchars($reportType); ?></span>
                    <div style="display: flex; gap: 8px;">
                        <button onclick="exportToPDF()" class="btn btn-sm" style="background: var(--danger-fill); color: white;"><i class="fas fa-file-pdf"></i> PDF</button>
                        <button onclick="exportToExcel()" class="btn btn-sm" style="background: var(--success-fill); color: white;"><i class="fas fa-file-excel"></i> Excel</button>
                        <button onclick="window.print()" class="btn btn-sm btn-secondary"><i class="fas fa-print"></i> Print</button>
                    </div>
                </div>
                <div style="overflow-x: auto; padding: 15px;">
                    <table id="reportTable" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: var(--hover-bg-light); border-bottom: 2px solid var(--border-color);">
                                <th style="padding: 10px; text-align: left;">Date</th>
                                <th style="padding: 10px; text-align: left;">Category</th>
                                <th style="padding: 10px; text-align: right;">Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total = 0;
                            foreach ($reportData as $row): 
                                $total += $row['count'];
                            ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px;"><?php echo htmlspecialchars($row['date']); ?></td>
                                <td style="padding: 10px;"><?php echo htmlspecialchars($row['meal_type'] ?? $row['location']); ?></td>
                                <td style="padding: 10px; text-align: right;"><?php echo htmlspecialchars($row['count']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="font-weight: bold; background: var(--hover-bg-light);">
                                <td style="padding: 10px;">TOTAL</td>
                                <td style="padding: 10px;"></td>
                                <td style="padding: 10px; text-align: right;"><?php echo $total; ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <div class="card" style="margin-top: 20px; padding: 20px; text-align: center; color: var(--secondary-color);">
                    No data found for the selected period.
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        function exportToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            const title = "<?php echo addslashes($reportType); ?>";
            doc.text(title, 14, 15);
            doc.autoTable({ html: '#reportTable', startY: 20 });
            doc.save('report_' + new Date().getTime() + '.pdf');
        }

        function exportToExcel() {
            const table = document.getElementById('reportTable');
            const wb = XLSX.utils.table_to_book(table);
            XLSX.writeFile(wb, 'report_' + new Date().getTime() + '.xlsx');
        }

        function toggleTheme() {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
        }
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
        }
    </script>
</body>
</html>
