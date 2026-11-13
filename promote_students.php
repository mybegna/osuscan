<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
require_once 'includes/lang_init.php';
checkAuth();

if (!in_array($_SESSION['role'], ['Super Admin', 'Registrar Officer', 'College Registrar', 'Campus Admin'])) {
    die("<div style='padding: 20px; color: #6B4E3D;'><h2>Access Denied</h2></div>");
}
$campus_id = $_SESSION['campus_id'] ?? null;

$message = '';
$students_to_promote = [];
$from_level = $_POST['from_level'] ?? '';
$department = $_POST['department'] ?? '';
$to_level = '';

// Define progression map (for dropdown only)
$all_levels = [];
for ($y=1; $y<=7; $y++) {
    $all_levels[] = "Year $y Semester 1";
    $all_levels[] = "Year $y Semester 2";
}

// Fetch Departments
$deptQuery = "SELECT name FROM departments WHERE 1=1";
$deptParams = [];
if ($campus_id) {
    $deptQuery .= " AND campus_id = ?";
    $deptParams[] = $campus_id;
}
$deptQuery .= " ORDER BY name ASC";
$stmt = $pdo->prepare($deptQuery);
$stmt->execute($deptParams);
$departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!$departments) $departments = []; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Step 1: Filter Students
    if (isset($_POST['action']) && $_POST['action'] === 'filter') {
        if (!empty($from_level) && !empty($department)) {
            if ($_SESSION['role'] === 'College Registrar') {
                $comp_assigned = getAssignedDepartmentNames($_SESSION['user_id']);
                if (!in_array($department, $comp_assigned)) {
                    $message = "Access Denied: You cannot promote students from this department.";
                    // Force empty list
                    $department = null; 
                }
            }
            
            if ($department) {
            
            // Logic to determine next level based on department semester length
            $deptSql = "SELECT semester_length FROM departments WHERE name = ?";
            $deptP = [$department];
            if ($campus_id) {
                $deptSql .= " AND campus_id = ?";
                $deptP[] = $campus_id;
            }
            $stmtDept = $pdo->prepare($deptSql);
            $stmtDept->execute($deptP);
            $deptInfo = $stmtDept->fetch();
            $max_semesters = $deptInfo ? ($deptInfo['semester_length'] ?? 8) : 8;

            // Parse current level
            if (preg_match('/Year (\d+) Semester (\d+)/', $from_level, $matches)) {
                $current_sem_index = ($matches[1] - 1) * 2 + $matches[2];
                
                if ($current_sem_index >= $max_semesters) {
                    $to_level = 'Graduated';
                } else {
                    $next_index = $current_sem_index + 1;
                    $next_year = ceil($next_index / 2);
                    $next_sem = ($next_index % 2 == 0) ? 2 : 1;
                    $to_level = "Year $next_year Semester $next_sem";
                }
            }

            if ($to_level) {
                // Get students matching criteria
                $studSql = "SELECT * FROM students WHERE year_level = ? AND department = ? AND enrollment_status = 'Active'";
                $studP = [$from_level, $department];
                if ($campus_id) {
                     $studSql .= " AND campus_id = ?";
                     $studP[] = $campus_id;
                }
                $studSql .= " ORDER BY full_name ASC";
                $stmt = $pdo->prepare($studSql);
                $stmt->execute($studP);
                $students_to_promote = $stmt->fetchAll();
                
                if (count($students_to_promote) === 0) {
                    $message = "No active students found in $department - $from_level.";
                }
            } else {
                $message = "Error: Invalid starting level or no next level defined.";
            }
            }
        } else {
            $message = "Please select both Department and Current Level.";
        }
    } 
    // Step 2: Promote Selected
    elseif (isset($_POST['action']) && $_POST['action'] === 'promote_selected') {
        $selected_ids = $_POST['selected_ids'] ?? [];
        $target_level = $_POST['target_level'];
        
        if (!empty($selected_ids) && !empty($target_level)) {
            try {
                $placeholders = str_repeat('?,', count($selected_ids) - 1) . '?';
                
                if ($target_level === 'Graduated') {
                     // Graduate them
                     $grad_date = date('Y-m');
                     $params = array_merge([$grad_date], $selected_ids);
                     $sql = "UPDATE students SET enrollment_status = 'Graduated', year_level = 'Graduated', graduation_year = ? WHERE student_id IN ($placeholders)";
                } else {
                     // Normal promotion
                     $params = array_merge([$target_level], $selected_ids);
                     $sql = "UPDATE students SET year_level = ? WHERE student_id IN ($placeholders)";
                }
                
                if ($campus_id) {
                     $sql .= " AND campus_id = ?";
                     $params[] = $campus_id;
                }

                $stmt = $pdo->prepare($sql);

                $stmt->execute($params);
                
                $count = $stmt->rowCount();
                $message = "Successfully promoted $count students to $target_level.";
                
                // Log action
                if (isset($_SESSION['user_id'])) {
                    $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
                    $stmtLog->execute([$_SESSION['user_id'], 'Promote Students', "Promoted $count students to $target_level"]);
                }
                
            } catch (Exception $e) {
                $message = "Error promoting students: " . $e->getMessage();
            }
        } else {
            $message = "No students selected for promotion.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Promote Students - OSU One Card</title>
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
                    <i class="fas fa-level-up-alt"></i> Student Promotion
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
            <?php if ($message): ?>
                <div class="card flash <?php echo strpos($message, 'Error') ? 'flash-error' : 'flash-success'; ?>" style="">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-graduation-cap"></i> Select Students to Promote
                </div>
                <div style="padding: 20px;">
                    <p style="color: var(--secondary-color); margin-bottom: 20px;">
                        Select Department and Level to view eligible students.
                    </p>

                    <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        <input type="hidden" name="action" value="filter">
                        
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: bold;">Department:</label>
                            <select name="department" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; width: 100%;">
                                <option value="">Select Department...</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: bold;">Current Level:</label>
                            <select name="from_level" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; width: 100%;">
                                <option value="">Select Level...</option>
                                <?php foreach ($all_levels as $level): ?>
                                    <option value="<?php echo $level; ?>" <?php echo $from_level === $level ? 'selected' : ''; ?>>
                                        <?php echo $level; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display: flex; align-items: flex-end;">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-search"></i> Find Students
                            </button>
                        </div>
                    </form>

                    <?php if (!empty($students_to_promote)): ?>
                    <form method="POST" onsubmit="return confirm('Promote selected students to <?php echo htmlspecialchars($to_level); ?>?');">
                        <input type="hidden" name="action" value="promote_selected">
                        <input type="hidden" name="target_level" value="<?php echo htmlspecialchars($to_level); ?>">
                        
                        <div style="margin-top: 30px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                <h3>Eligible Students (<?php echo count($students_to_promote); ?>)</h3>
                                <div style="font-weight: bold; color: var(--primary-color);">
                                    Promoting to: <?php echo htmlspecialchars($to_level); ?>
                                </div>
                            </div>

                            <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: var(--hover-bg-light);">
                                            <th style="padding: 10px; text-align: center; border-bottom: 2px solid var(--border-color);">
                                                <input type="checkbox" id="selectAll" checked>
                                            </th>
                                            <th style="padding: 10px; text-align: left; border-bottom: 2px solid var(--border-color);">ID</th>
                                            <th style="padding: 10px; text-align: left; border-bottom: 2px solid var(--border-color);">Name</th>
                                            <th style="padding: 10px; text-align: left; border-bottom: 2px solid var(--border-color);">Current Level</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students_to_promote as $student): ?>
                                        <tr style="border-bottom: 1px solid var(--border-color);">
                                            <td style="padding: 10px; text-align: center;">
                                                <input type="checkbox" name="selected_ids[]" value="<?php echo htmlspecialchars($student['student_id']); ?>" checked class="student-checkbox">
                                            </td>
                                            <td style="padding: 10px;"><?php echo htmlspecialchars($student['student_id']); ?></td>
                                            <td style="padding: 10px;"><?php echo htmlspecialchars($student['full_name']); ?></td>
                                            <td style="padding: 10px;"><?php echo htmlspecialchars($student['year_level']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <button type="submit" class="btn btn-primary" style="margin-top: 20px; background: var(--success-fill);">
                                <i class="fas fa-check-circle"></i> Promote Selected Students
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
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

        const selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function(e) {
                const checkboxes = document.querySelectorAll('.student-checkbox');
                checkboxes.forEach(cb => cb.checked = e.target.checked);
            });
        }
    </script>
</body>
</html>
