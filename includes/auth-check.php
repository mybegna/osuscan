<?php
/**
 * Role-based access control helper
 */

function checkAuth() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        header("Location: login");
        exit;
    }
}

function hasRole($requiredRoles) {
    checkAuth();
    if (!is_array($requiredRoles)) {
        $requiredRoles = [$requiredRoles];
    }
    return in_array($_SESSION['role'], $requiredRoles);
}

function canAccessScanner($scannerType) {
    $role = $_SESSION['role'] ?? '';
    
    switch ($scannerType) {
        case 'gate':
            return $role === 'Security Officer';
        case 'library':
            return $role === 'Library User';
        case 'clinic':
            return $role === 'Clinic User';
        case 'cafeteria':
            return strpos($role, 'Cafeteria') !== false;
        default:
            return false;
    }
}


function getAvailableScanners() {
    $role = $_SESSION['role'] ?? '';
    $scanners = [];
    
    if ($role === 'Security Officer') {
        $scanners[] = ['name' => 'Gate Scanner', 'icon' => 'fas fa-door-open', 'link' => 'scan?type=gate'];
    }
    if ($role === 'Library User') {
        $scanners[] = ['name' => 'Library Scanner', 'icon' => 'fas fa-book', 'link' => 'scan?type=library'];
    }
    if ($role === 'Clinic User') {
        $scanners[] = ['name' => 'Clinic Scanner', 'icon' => 'fas fa-hospital-user', 'link' => 'scan?type=clinic'];
    }
    if (strpos($role, 'Cafeteria') !== false) {
        $scanners[] = ['name' => 'Cafeteria Scanner', 'icon' => 'fas fa-utensils', 'link' => 'scan?type=cafeteria'];
    }
    
    return $scanners;
}

function getAssignedDepartmentNames($user_id) {
    global $pdo;
    if (!isset($pdo)) return [];
    
    try {
        $stmt = $pdo->prepare("
            SELECT d.name 
            FROM user_departments ud
            JOIN departments d ON ud.department_id = d.id
            WHERE ud.user_id = ?
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}
?>
