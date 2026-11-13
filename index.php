<?php
// index.php - Front Controller

// Security: Prevent direct access in included files
define('ACCESS_GRANTED', true);

// 1. Parse Route
$route = $_GET['route'] ?? '';
// Remove trailing slashes
$route = rtrim($route, '/');

// Default Route (Home)
if ($route === '') {
    $route = 'login';
}

// 2. Routing Table
// Map Clean URLs to Physical Files
$routes = [
    'login'            => 'login_view.php',
    'dashboard'        => 'dashboard.php',
    'users'            => 'users.php',
    'students'         => 'students.php',
    'campuses'         => 'campuses.php',
    'departments'      => 'departments.php',
    'manage_cafe'      => 'manage_cafe.php',
    'reports'          => 'reports.php',
    'profile'          => 'profile.php',
    'logout'           => 'logout.php',
    'scan'             => 'scan.php',
    'audit_logs'       => 'audit_logs.php',
    'promote_students' => 'promote_students.php',
    'print_id'         => 'print_id.php',
    'bulk_print'       => 'bulk_print.php',
    'labour'           => 'labour.php',
    'print_labour_id'  => 'print_labour_id.php',
    // API Routes (referenced by JS)
    'api/process_scan' => 'api/process_scan.php',
    'api/get_metrics'  => 'api/get_metrics.php'
];

// 3. Dispatch
if (array_key_exists($route, $routes)) {
    $file = $routes[$route];
    
    // Ensure file exists
    if (file_exists($file)) {
        // Execute the file
        require $file;
    } else {
        // File missing
        http_response_code(404);
        echo "<div style='font-family:sans-serif; text-align:center; padding:50px; color:#0C211A;'>";
        echo "<h1>404 Not Found</h1>";
        echo "<p>System error: The mapped file '$file' was not found.</p>";
        echo "</div>";
    }
} else {
    // Route not found
    http_response_code(404);
    echo "<div style='font-family:sans-serif; text-align:center; padding:50px; color:#0C211A;'>";
    echo "<h1>404 Not Found</h1>";
    echo "<p>The requested page '/" . htmlspecialchars($route) . "' does not exist.</p>";
    echo "<a href='dashboard' style='color:#144130; text-decoration:none;'>Return to Dashboard</a>";
    echo "</div>";
}
?>
