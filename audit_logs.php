<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
require_once 'includes/lang_init.php';
require_once 'includes/helpers.php';
date_default_timezone_set('Africa/Addis_Ababa');
checkAuth();

if (!in_array($_SESSION['role'], ['Super Admin', 'Campus Admin'])) {
    die("<div style='padding: 20px; color: #6B4E3D;'><h2>Access Denied</h2></div>");
}
$campus_id = $_SESSION['campus_id'] ?? null;

// Fetch logs
// We will union scan_logs, meal_logs, login_logs and audit_logs for a comprehensive view
// Or just display them in separate tabs/tables. The requirement says "Display all system logs, including location scans, meals served (by type), and user activities".
// A unified table is best for "Audit Logs".

$logs = [];

// Filter params
$search_student_id = $_GET['student_id'] ?? '';
$search_username = $_GET['username'] ?? '';

// 1. Audit Logs (User Actions)
$sql = "SELECT a.id, u.username, a.action, a.details, a.created_at, 'User Action' as type 
        FROM audit_logs a 
        LEFT JOIN users u ON a.user_id = u.id WHERE 1=1";
if ($campus_id) {
    $sql .= " AND u.campus_id = '$campus_id'";
}
if (!empty($search_username)) {
    $sql .= " AND u.username LIKE '%$search_username%'";
}
// Audit logs don't have student_id usually, but details might.
if (!empty($search_student_id)) {
     $sql .= " AND a.details LIKE '%$search_student_id%'";
}

$stmt = $pdo->query($sql);
while($row = $stmt->fetch()) {
    $logs[] = $row;
}

// 2. Scan Logs
$sql = "SELECT s.id, u.username as scanned_by_user, concat('Scan: ', s.location) as action, concat('Student: ', s.student_id, ' Status: ', s.status_at_scan) as details, s.scan_time as created_at, 'Location Scan' as type 
        FROM scan_logs s 
        LEFT JOIN users u ON s.scanned_by = u.id WHERE 1=1";
if ($campus_id) {
    $sql .= " AND s.campus_id = '$campus_id'";
}
if (!empty($search_username)) {
    $sql .= " AND u.username LIKE '%$search_username%'";
}
if (!empty($search_student_id)) {
     $sql .= " AND s.student_id LIKE '%$search_student_id%'";
}

$stmt = $pdo->query($sql);
while($row = $stmt->fetch()) {
    $row['username'] = $row['scanned_by_user']; // map to common key
    $logs[] = $row;
}

// 3. Meal Logs
$sql = "SELECT m.id, u.username as scanned_by_user, concat('Meal: ', m.meal_type) as action, concat('Student: ', m.student_id) as details, m.scan_time as created_at, 'Meal Served' as type 
        FROM meal_logs m 
        LEFT JOIN users u ON m.scanned_by = u.id WHERE 1=1";
if ($campus_id) {
    $sql .= " AND m.campus_id = '$campus_id'";
}
if (!empty($search_username)) {
    $sql .= " AND u.username LIKE '%$search_username%'";
}
if (!empty($search_student_id)) {
     $sql .= " AND m.student_id LIKE '%$search_student_id%'";
}

$stmt = $pdo->query($sql);
while($row = $stmt->fetch()) {
    $row['username'] = $row['scanned_by_user'];
    $logs[] = $row;
}

// 4. Login Logs
$sql = "SELECT l.id, u.username, 'Login' as action, concat('IP: ', l.ip_address) as details, l.login_time as created_at, 'Login' as type 
        FROM login_logs l 
        LEFT JOIN users u ON l.user_id = u.id WHERE 1=1";
if ($campus_id) {
    $sql .= " AND u.campus_id = '$campus_id'";
}
if (!empty($search_username)) {
    $sql .= " AND u.username LIKE '%$search_username%'";
}
// Login logs don't have student_id

if (empty($search_student_id)) { // Only show login logs if NOT searching for student
    $stmt = $pdo->query($sql);
    while($row = $stmt->fetch()) {
        $logs[] = $row;
    }
}

// Sort by created_at DESC
usort($logs, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Pagination (simple array slice for now, real pagination better for large datasets but keeping it simple)
$page = $_GET['page'] ?? 1;
$perPage = 100;
$total = count($logs);
$logs = array_slice($logs, ($page-1)*$perPage, $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - OSU One Card</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                    <i class="fas fa-history"></i> Audit Logs
                </div>
            </div>
            <div class="navbar-actions" style="display: flex; gap: 10px; align-items: center;">
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
            <!-- Search Logs -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-search"></i> Filter Logs
                </div>
                <form method="GET" style="padding: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 200px;">
                        <input type="text" name="student_id" placeholder="Search by Student ID" value="<?php echo htmlspecialchars($search_student_id); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                    </div>
                    <div style="flex: 1; min-width: 200px;">
                        <input type="text" name="username" placeholder="Search by Username" value="<?php echo htmlspecialchars($search_username); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="audit_logs.php" class="btn btn-secondary" style="background: var(--neutral-fill); color: white;">
                        <i class="fas fa-sync"></i> Reset
                    </a>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list"></i> System Activity Logs
                </div>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: var(--hover-bg-light); border-bottom: 2px solid var(--border-color);">
                                <th style="padding: 10px; text-align: left;">Time</th>
                                <th style="padding: 10px; text-align: left;">Type</th>
                                <th style="padding: 10px; text-align: left;">User</th>
                                <th style="padding: 10px; text-align: left;">Action</th>
                                <th style="padding: 10px; text-align: left;">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px; white-space: nowrap;"><?php echo formatEthiopiaTime($log['created_at']); ?></td>
                                <td style="padding: 10px;">
                                    <span style="font-size: 11px; padding: 2px 6px; border-radius: 4px; background: var(--neutral-bg); color: var(--neutral-on);">
                                        <?php echo htmlspecialchars($log['type']); ?>
                                    </span>
                                </td>
                                <td style="padding: 10px; font-weight: 500;"><?php echo htmlspecialchars($log['username'] ?? 'System/Unknown'); ?></td>
                                <td style="padding: 10px;"><?php echo htmlspecialchars($log['action']); ?></td>
                                <td style="padding: 10px; font-size: 13px; color: var(--secondary-color);"><?php echo htmlspecialchars($log['details']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total > $perPage): ?>
                <div style="padding: 15px; display: flex; justify-content: center; gap: 10px;">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page-1; ?>" class="btn btn-sm btn-secondary">Previous</a>
                    <?php endif; ?>
                    <?php if (count($logs) == $perPage): ?>
                    <a href="?page=<?php echo $page+1; ?>" class="btn btn-sm btn-secondary">Next</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
