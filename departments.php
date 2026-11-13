<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
checkAuth();

if (!in_array($_SESSION['role'], ['Super Admin', 'Registrar Officer', 'Campus Admin'])) {
    die("<div style='padding: 20px; color: #6B4E3D;'><h2>Access Denied</h2></div>");
}

$campus_id = $_SESSION['campus_id'] ?? null;
// Fetch Campuses for Super Admin
$campuses = [];
if ($_SESSION['role'] === 'Super Admin') {
    $campuses = $pdo->query("SELECT * FROM campuses ORDER BY campus_name ASC")->fetchAll();
}

$message = '';

// Add/Update Department
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_dept') {
        $name = trim($_POST['name']);
        $sem_len = intval($_POST['semester_length']);
        
        $newValCampus = null;
        if ($_SESSION['role'] === 'Super Admin') {
            $newValCampus = !empty($_POST['campus_id']) ? $_POST['campus_id'] : null;
        } else {
            $newValCampus = $campus_id;
        }

        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO departments (name, semester_length, campus_id) VALUES (?, ?, ?)");
            $stmt->execute([$name, $sem_len, $newValCampus]);
            $message = "Department added successfully!";
            // Log action
            if (isset($_SESSION['user_id'])) {
                $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
                $stmtLog->execute([$_SESSION['user_id'], 'Add Department', "Added department: $name ($sem_len semesters)"]);
            }
        }
    } elseif ($_POST['action'] === 'edit_dept') {
        $id = intval($_POST['dept_id']);
        $name = trim($_POST['name']);
        $sem_len = intval($_POST['semester_length']);
        
        $sql = "UPDATE departments SET name = ?, semester_length = ?";
        $params = [$name, $sem_len];
        
        if ($_SESSION['role'] === 'Super Admin' && isset($_POST['edit_campus_id'])) {
            $sql .= ", campus_id = ?";
            $params[] = !empty($_POST['edit_campus_id']) ? $_POST['edit_campus_id'] : null;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;

        if ($campus_id) {
            $sql .= " AND campus_id = ?";
            $params[] = $campus_id;
        }

        if (!empty($name) && $id > 0) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $message = "Department updated successfully!";
            // Log action
            if (isset($_SESSION['user_id'])) {
                $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
                $stmtLog->execute([$_SESSION['user_id'], 'Update Department', "Updated department ID: $id to $name ($sem_len semesters)"]);
            }
        }
    } elseif ($_POST['action'] === 'upload_csv') {
        // Bulk Import Logic
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $ext = pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION);
            if(strtolower($ext) !== 'csv') {
                 $message = "Error: Only CSV files are allowed.";
            } else {
                $target_campus = null;

                if ($_SESSION['role'] === 'Super Admin') {
                    if (empty($_POST['upload_campus_id'])) {
                        $message = "Error: Please select a campus.";
                    } else {
                        $target_campus = $_POST['upload_campus_id'];
                    }
                } else {
                    // Restrict to own campus
                    $target_campus = $_SESSION['campus_id'];
                }

                if ($target_campus || ($_SESSION['role'] === 'Super Admin' && empty($message)) || $_SESSION['role'] !== 'Super Admin') {
                    // Logic: Super Admin *must* select campus if they want to assign to specific campus, 
                    // but wait, user req: "The system must first require the user to select a campus." -> So validation is mandatory for Super Admin.
                    
                     if ($_SESSION['role'] === 'Super Admin' && empty($target_campus)) {
                        $message = "Error: You must select a campus for bulk import.";
                     } else {
                        $filename = $_FILES['csv_file']['tmp_name'];
                        $file = fopen($filename, "r");
                        $count = 0;
                        $errors = 0;
                        $dupes = 0;
                        $error_details = [];
                        
                        fgetcsv($file); // Skip header

                        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE name = ? AND campus_id = ?");
                        $stmtInsert = $pdo->prepare("INSERT INTO departments (name, semester_length, campus_id) VALUES (?, ?, ?)");

                        while (($data = fgetcsv($file, 1000, ",")) !== FALSE) {
                            // Expected: Department Name, Semester Length
                            if (count($data) >= 1) {
                                $dName = trim($data[0]);
                                $dLen = isset($data[1]) ? intval(trim($data[1])) : 8;
                                if ($dLen < 1) $dLen = 8;

                                if (empty($dName)) continue;

                                // Check Duplicate
                                $stmtCheck->execute([$dName, $target_campus]);
                                if ($stmtCheck->fetchColumn() > 0) {
                                    $dupes++;
                                    $error_details[] = "skipped '$dName' (Duplicate)";
                                    continue;
                                }

                                try {
                                    $stmtInsert->execute([$dName, $dLen, $target_campus]);
                                    $count++;
                                } catch (Exception $e) {
                                    $errors++;
                                    //$error_details[] = "Error inserting '$dName': " . $e->getMessage();
                                }
                            }
                        }
                        fclose($file);
                        $message = "Bulk upload completed. Added: $count. ";
                        if ($dupes > 0) $message .= "Skipped $dupes duplicates. ";
                        if ($errors > 0) $message .= "Failed $errors. ";
                        
                        // Log
                        if (isset($_SESSION['user_id'])) {
                            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
                            $stmtLog->execute([$_SESSION['user_id'], 'Bulk Import Depts', "Imported $count depts to Campus ID: $target_campus"]);
                        }
                     }
                }
            }
        } else {
            $message = "Error: File upload failed or no file selected.";
        }
    }
}

// Download CSV Template
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="departments_template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Department Name', 'Semester Length']);
    fputcsv($output, ['Computer Science', '8']);
    fputcsv($output, ['Medicine', '12']);
    fclose($output);
    exit;
}

// Delete Department
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    // Check if department is being used by students
    $stmtCheck = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
    $stmtCheck->execute([$id]);
    $deptName = $stmtCheck->fetchColumn();
    
    if ($deptName) {
        $stmtUsage = $pdo->prepare("SELECT COUNT(*) FROM students WHERE department = ?");
        $stmtUsage->execute([$deptName]);
        if ($stmtUsage->fetchColumn() > 0) {
            $message = "Error: Cannot delete department '$deptName' because it has students assigned to it.";
        } else {
            $delQuery = "DELETE FROM departments WHERE id = ?";
            $delParams = [$id];
            if ($campus_id) {
                $delQuery .= " AND campus_id = ?";
                $delParams[] = $campus_id;
            }
            $stmt = $pdo->prepare($delQuery);
            $stmt->execute($delParams);
            $message = "Department deleted successfully!";
             // Log action
             if (isset($_SESSION['user_id'])) {
                $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
                $stmtLog->execute([$_SESSION['user_id'], 'Delete Department', "Deleted department ID: $id ($deptName)"]);
            }
        }
    }
}

// Filtering Logic
$search = $_GET['search'] ?? '';
$filterCampus = $_GET['filter_campus'] ?? '';

$listQuery = "SELECT d.*, c.campus_name FROM departments d LEFT JOIN campuses c ON d.campus_id = c.id WHERE 1=1";
$listParams = [];

if (!empty($search)) {
    $listQuery .= " AND d.name LIKE ?";
    $listParams[] = "%$search%";
}

if ($campus_id) {
    $listQuery .= " AND d.campus_id = ?";
    $listParams[] = $campus_id;
} elseif ($_SESSION['role'] === 'Super Admin' && !empty($filterCampus)) {
    $listQuery .= " AND d.campus_id = ?";
    $listParams[] = $filterCampus;
}

$listQuery .= " ORDER BY d.name ASC";
$stmt = $pdo->prepare($listQuery);
$stmt->execute($listParams);
$departments = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments - OSU One Card</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(12, 33, 26, 0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: var(--card-bg); padding: 20px; border-radius: 8px; width: 90%; max-width: 500px; position: relative; }
        .close-modal { position: absolute; right: 15px; top: 15px; font-size: 20px; cursor: pointer; color: var(--secondary-color); }
        .show-modal { display: flex; }
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
                    <i class="fas fa-building"></i> Manage Departments
                </div>
            </div>
            <div class="navbar-actions">
                <a href="students" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Students
                </a>
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
            <?php if ($message): ?>
                <div class="card flash <?php echo strpos($message, 'Error') ? 'flash-error' : 'flash-success'; ?>" style="padding: 15px; margin-bottom: 20px;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Search & Actions -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-search"></i> Search & Actions
                </div>
                <div style="padding: 15px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between;">
                    <form method="GET" style="display: flex; gap: 10px; flex: 1; min-width: 300px;">
                        <input type="text" name="search" placeholder="Search Department Name" value="<?php echo htmlspecialchars($search); ?>" style="flex: 1; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                        
                        <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                        <select name="filter_campus" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <option value="">All Campuses</option>
                            <?php foreach ($campuses as $camp): ?>
                                <option value="<?php echo $camp['id']; ?>" <?php echo ($filterCampus == $camp['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($camp['campus_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                    </form>
                    
                    <button class="btn btn-secondary" onclick="openModal('bulkAddModal')" style="white-space: nowrap;">
                        <i class="fas fa-file-import"></i> Bulk Add
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-plus"></i> Add New Department
                </div>
                <form method="POST" style="padding: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <input type="hidden" name="action" value="add_dept">
                    <input type="text" name="name" placeholder="Department Name" required style="flex: 2; min-width: 200px; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                    <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                        <select name="campus_id" style="flex: 1; min-width: 150px; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <option value="">Select Campus</option>
                            <?php foreach ($campuses as $camp): ?>
                                <option value="<?php echo $camp['id']; ?>"><?php echo htmlspecialchars($camp['campus_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <input type="number" name="semester_length" placeholder="Semesters (e.g. 8)" required min="1" max="14" style="flex: 1; min-width: 100px; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                    <button type="submit" class="btn btn-primary" style="padding: 10px 25px;">Add</button>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list"></i> Existing Departments
                </div>
                <div style="padding: 15px; overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: var(--hover-bg-light); border-bottom: 2px solid var(--border-color);">
                                <th style="padding: 12px; text-align: left;">ID</th>
                                <th style="padding: 12px; text-align: left;">Name</th>
                                <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                                    <th style="padding: 12px; text-align: left;">Campus</th>
                                <?php endif; ?>
                                <th style="padding: 12px; text-align: center;">Semesters</th>
                                <th style="padding: 12px; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($departments as $dept): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 12px;"><?php echo htmlspecialchars($dept['id']); ?></td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($dept['name']); ?></td>
                                <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                                    <td style="padding: 12px;"><?php echo htmlspecialchars($dept['campus_name'] ?? 'Global'); ?></td>
                                <?php endif; ?>
                                <td style="padding: 12px; text-align: center;"><?php echo htmlspecialchars($dept['semester_length'] ?? '8'); ?></td>
                                <td style="padding: 12px; text-align: right; display: flex; gap: 5px; justify-content: flex-end;">
                                    <button type="button" class="btn btn-sm btn-secondary" onclick='openEditModal(<?php echo json_encode($dept); ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="departments?action=delete&id=<?php echo $dept['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this department?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Bulk Add Modal -->
            <div id="bulkAddModal" class="modal">
                <div class="modal-content">
                    <span class="close-modal" onclick="closeModal('bulkAddModal')">&times;</span>
                    <div class="card-header" style="border:none; margin-bottom: 15px;">Bulk Add Departments</div>
                    
                    <div style="padding: 10px; border-bottom: 1px solid var(--border-color); background: var(--hover-bg-light); display: flex; justify-content: space-between; align-items: center; border-radius:6px; margin-bottom:15px;">
                        <span style="font-size: 14px;">Step 1: Download Template</span>
                        <a href="departments?action=download_template" class="btn btn-sm btn-secondary" style="text-decoration: none;">
                            <i class="fas fa-download"></i> Download CSV
                        </a>
                    </div>

                    <form method="POST" enctype="multipart/form-data" style="background: var(--card-bg); padding: 15px; border-radius: 8px;">
                        <input type="hidden" name="action" value="upload_csv">
                        
                        <div style="margin-bottom: 15px;">
                            <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Step 2: Select Campus</label>
                                <select name="upload_campus_id" required style="width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 6px;">
                                    <option value="">Select Campus</option>
                                    <?php foreach ($campuses as $camp): ?>
                                        <option value="<?php echo $camp['id']; ?>"><?php echo htmlspecialchars($camp['campus_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <p style="margin-bottom: 10px; font-size: 14px; color: var(--secondary-color);">
                                     Departments will be added to your assigned campus.
                                </p>
                            <?php endif; ?>
                            
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Step 3: Upload CSV File</label>
                            <input type="file" name="csv_file" accept=".csv" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <p style="font-size: 12px; color: var(--secondary-color); margin-top: 5px;">
                                Columns: <b>Department Name</b>, <b>Semester Length</b> (Optional, default 8).
                            </p>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Import Departments</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Department Modal -->
    <div id="editDeptModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('editDeptModal')">&times;</span>
            <div class="card-header" style="border:none; margin-bottom:15px; padding:0;">
                <i class="fas fa-edit"></i> Edit Department
            </div>
            <form method="POST" style="display: flex; flex-direction: column; gap: 15px;">
                <input type="hidden" name="action" value="edit_dept">
                <input type="hidden" name="dept_id" id="edit_dept_id">
                
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label>Department Name</label>
                    <input type="text" name="name" id="edit_name" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                </div>
                
                    <input type="number" name="semester_length" id="edit_semester_length" required min="1" max="14" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                </div>

                <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <label>Campus</label>
                    <select name="edit_campus_id" id="edit_campus_id" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                        <option value="">Select Campus</option>
                        <?php foreach ($campuses as $camp): ?>
                            <option value="<?php echo $camp['id']; ?>"><?php echo htmlspecialchars($camp['campus_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Update Department</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('show-modal');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show-modal');
        }

        function openEditModal(dept) {
            document.getElementById('edit_dept_id').value = dept.id;
            document.getElementById('edit_name').value = dept.name;
            document.getElementById('edit_semester_length').value = dept.semester_length || 8;
            if (document.getElementById('edit_campus_id')) {
                document.getElementById('edit_campus_id').value = dept.campus_id || '';
            }
            openModal('editDeptModal');
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show-modal');
            }
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
