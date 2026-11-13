<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
require_once 'includes/lang_init.php';
checkAuth();

if (!in_array($_SESSION['role'], ['Super Admin', 'Registrar Officer', 'College Registrar', 'ID Card Printer', 'Campus Admin'])) {
    die("<div style='padding: 20px; color: #6B4E3D;'><h2>Access Denied</h2></div>");
}

// Get assigned departments for College Registrar
$assigned_depts = [];
if ($_SESSION['role'] === 'College Registrar') {
    $assigned_depts = getAssignedDepartmentNames($_SESSION['user_id']);
}

$message = '';

// Handle Bulk Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_student') {
        try {
            $id = $_POST['student_id']; // The ID to update
            $original_id = $_POST['original_student_id']; // The original ID (in case ID itself is changed)
            
            $photoData = null;
            if (isset($_FILES['edit_student_photo']) && $_FILES['edit_student_photo']['error'] === 0) {
                 if ($_FILES['edit_student_photo']['size'] > 51200) {
                    throw new Exception("Photo exceeds 50kb limit.");
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['edit_student_photo']['tmp_name']);
                if (strpos($mime, 'image/') !== 0) {
                     throw new Exception("File must be an image.");
                }
                $photoData = file_get_contents($_FILES['edit_student_photo']['tmp_name']);
            }

            if ($_SESSION['role'] === 'College Registrar') {
                // Check destination department
                if (!in_array($_POST['department'], $assigned_depts)) {
                    throw new Exception($lang['access_denied_dept']);
                }
                // Check source department (ownership)
                $stmtCheck = $pdo->prepare("SELECT department, enrollment_status FROM students WHERE student_id = ?");
                $stmtCheck->execute([$original_id]);
                $currentData = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($currentData && !in_array($currentData['department'], $assigned_depts)) {
                    throw new Exception($lang['access_denied_dept']);
                }
                
                if ($currentData && $currentData['enrollment_status'] === 'Graduated') {
                    throw new Exception("Cannot update a student who has already graduated.");
                }
            } else {
                // Non-registrar roles check for graduated status too
                $stmtCheck = $pdo->prepare("SELECT enrollment_status FROM students WHERE student_id = ?");
                $stmtCheck->execute([$original_id]);
                $currentStatus = $stmtCheck->fetchColumn();
                if ($currentStatus === 'Graduated') {
                    throw new Exception("Cannot update a student who has already graduated.");
                }
            }

            $sql = "UPDATE students SET student_id=?, full_name=?, gender=?, entry_year=?, graduation_year=?, program=?, department=?, year_level=?, enrollment_status=?, cafe_type=?, cafeteria_gate=?";
            $params = [
                $_POST['student_id'],
                $_POST['full_name'],
                $_POST['gender'],
                $_POST['entry_year'],
                $_POST['graduation_year'],
                $_POST['program'],
                $_POST['department'],
                $_POST['year_level'],
                $_POST['enrollment_status'],
                $_POST['cafe_type'],
                $_POST['cafeteria_gate']
            ];

            if ($photoData) {
                $sql .= ", photo=?";
                $params[] = $photoData;
            }
            
            $sql .= " WHERE student_id=?";
            $params[] = $original_id;

            if (isset($_SESSION['campus_id'])) {
                $sql .= " AND campus_id=?";
                $params[] = $_SESSION['campus_id'];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Student updated successfully!";
            
            // Log
            if (isset($_SESSION['user_id'])) {
                $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
                $stmtLog->execute([$_SESSION['user_id'], 'Update Student', "Updated student: " . $_POST['full_name']]);
            }

        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'bulk_delete' && isset($_POST['selected_ids'])) {
        if (in_array($_SESSION['role'], ['College Registrar', 'ID Card Printer'])) {
             $message = "Error: " . $lang['access_denied_delete'];
             $_POST['selected_ids'] = []; 
        }
        $ids = $_POST['selected_ids'];
        if (is_array($ids) && count($ids) > 0) {
            $deleted_count = 0;
            $blocked_count = 0;
            
            $stmtCheckScan = $pdo->prepare("SELECT COUNT(*) FROM scan_logs WHERE student_id = ?");
            $stmtCheckMeal = $pdo->prepare("SELECT COUNT(*) FROM meal_logs WHERE student_id = ?");
            // Campus check for delete
            $campusCheck = isset($_SESSION['campus_id']) ? " AND campus_id = ?" : "";
            $stmtDelete = $pdo->prepare("DELETE FROM students WHERE student_id = ?" . $campusCheck);

            foreach ($ids as $id) {
                $stmtCheckScan->execute([$id]);
                $scanCount = $stmtCheckScan->fetchColumn();
                
                $stmtCheckMeal->execute([$id]);
                $mealCount = $stmtCheckMeal->fetchColumn();

                if ($scanCount > 0 || $mealCount > 0) {
                    $blocked_count++;
                } else {
                    $delParams = [$id];
                    if (isset($_SESSION['campus_id'])) {
                        $delParams[] = $_SESSION['campus_id'];
                    }
                    $stmtDelete->execute($delParams);
                    if ($stmtDelete->rowCount() > 0) {
                        $deleted_count++;
                    }
                }
            }

            if ($deleted_count > 0) {
                $message = "$deleted_count students deleted successfully.";
                 // Log
                if (isset($_SESSION['user_id'])) {
                    $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
                    $stmtLog->execute([$_SESSION['user_id'], 'Bulk Delete Students', "Deleted $deleted_count students"]);
                }
            }
            
            if ($blocked_count > 0) {
                $err = "Cannot delete $blocked_count student(s) with existing activity logs.";
                $message = $message ? "$message <br>Error: $err" : "Error: $err";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_student') {
    try {
        $photoData = null;
        if (isset($_FILES['student_photo']) && $_FILES['student_photo']['error'] === 0) {
            // 50KB limit = 51200 bytes
            if ($_FILES['student_photo']['size'] > 51200) {
                throw new Exception("Photo exceeds 50kb limit.");
            }
            // Check image type
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['student_photo']['tmp_name']);
            if (strpos($mime, 'image/') !== 0) {
                 throw new Exception("File must be an image.");
            }
            
            $photoData = file_get_contents($_FILES['student_photo']['tmp_name']);
        }

        if ($_SESSION['role'] === 'College Registrar' && !in_array($_POST['department'], $assigned_depts)) {
            throw new Exception($lang['access_denied_dept']);
        }

        $stmt = $pdo->prepare("INSERT INTO students (student_id, full_name, gender, entry_year, graduation_year, program, department, year_level, enrollment_status, cafe_type, cafeteria_gate, photo, campus_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['student_id'],
            $_POST['full_name'],
            $_POST['gender'],
            $_POST['entry_year'],
            $_POST['graduation_year'],
            $_POST['program'],
            $_POST['department'],
            $_POST['year_level'],
            $_POST['enrollment_status'],
            $_POST['cafe_type'],
            $_POST['cafeteria_gate'],
            $photoData,
            $_SESSION['campus_id'] ?? null
        ]);
        $message = "Student added successfully!";
        
        // Log
        if (isset($_SESSION['user_id'])) {
            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
            $stmtLog->execute([$_SESSION['user_id'], 'Add Student', "Added student: " . $_POST['full_name']]);
        }
        
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// Bulk Upload Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_csv') {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $ext = pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION);
        if(strtolower($ext) !== 'csv') {
             $message = "Error: Only CSV files are allowed.";
        } else {
            // Get selected context
            $target_campus = $_POST['upload_campus_id'] ?? ($_SESSION['campus_id'] ?? null);
            $target_dept = $_POST['upload_department'] ?? '';
            $target_year = $_POST['upload_year_level'] ?? '';

            if (empty($target_dept) || empty($target_year)) {
                $message = "Error: Please select Department and Year Level.";
            } else {
                $filename = $_FILES['csv_file']['tmp_name'];
                $file = fopen($filename, "r");
                $count = 0;
                $errors = 0;
                $error_details = [];
                
                fgetcsv($file); // Skip header

                while (($data = fgetcsv($file, 1000, ",")) !== FALSE) {
                    // Expected columns: Full Name, Student ID, Gender, Entry Year, Grad Year, Program, Department, Year Level, Status, Cafe Type, Cafe Gate, Photo Filename
                    if (count($data) >= 11) { 
                        $name = trim($data[0]);
                        $id = trim($data[1]);
                        $gender = trim($data[2]);
                        $entry = trim($data[3]);
                        $grad = trim($data[4]);
                        $prog = trim($data[5]);
                        $dept = trim($data[6]);
                        $year = trim($data[7]);
                        
                        // VALIDATION
                        if (strcasecmp($dept, $target_dept) !== 0) {
                            $errors++;
                            $error_details[] = "Row for $name: Department '$dept' matches not selected '$target_dept'";
                            continue;
                        }
                        if (strcasecmp($year, $target_year) !== 0) {
                            $errors++;
                            $error_details[] = "Row for $name: Year '$year' matches not selected '$target_year'";
                            continue;
                        }

                        if ($_SESSION['role'] === 'College Registrar' && !in_array($dept, $assigned_depts)) {
                            $errors++;
                            continue;
                        }
                        
                        $status = trim($data[8]);
                        $cafeType = trim($data[9]);
                        $gate = trim($data[10]);
                        $photoFile = isset($data[11]) ? trim($data[11]) : null; // Photo filename
                        
                        $photoData = null;
                        if ($photoFile && file_exists("assets/img/students/" . $photoFile)) {
                            $photoData = file_get_contents("assets/img/students/" . $photoFile);
                        }
                        
                        try {
                            // Logic: Insert with selected Campus ID
                            if ($photoData) {
                                 $stmt = $pdo->prepare("INSERT INTO students (student_id, full_name, gender, entry_year, graduation_year, program, department, year_level, enrollment_status, cafe_type, cafeteria_gate, photo, campus_id) 
                                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                                                 ON DUPLICATE KEY UPDATE full_name=?, gender=?, entry_year=?, graduation_year=?, program=?, department=?, year_level=?, enrollment_status=?, cafe_type=?, cafeteria_gate=?, photo=?, campus_id=VALUES(campus_id)");
                                 $stmt->execute([
                                     $id, $name, $gender, $entry, $grad, $prog, $dept, $year, $status, $cafeType, $gate, $photoData, $target_campus,
                                     $name, $gender, $entry, $grad, $prog, $dept, $year, $status, $cafeType, $gate, $photoData
                                 ]);
                            } else {
                                 $stmt = $pdo->prepare("INSERT INTO students (student_id, full_name, gender, entry_year, graduation_year, program, department, year_level, enrollment_status, cafe_type, cafeteria_gate, campus_id) 
                                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                                                 ON DUPLICATE KEY UPDATE full_name=?, gender=?, entry_year=?, graduation_year=?, program=?, department=?, year_level=?, enrollment_status=?, cafe_type=?, cafeteria_gate=?, campus_id=VALUES(campus_id)");
                                 $stmt->execute([
                                     $id, $name, $gender, $entry, $grad, $prog, $dept, $year, $status, $cafeType, $gate, $target_campus,
                                     $name, $gender, $entry, $grad, $prog, $dept, $year, $status, $cafeType, $gate
                                 ]);
                            }
                           
                            $count++;
                        } catch (Exception $e) {
                            $errors++;
                        }
                    }
                }
                fclose($file);
                $message = "Bulk upload completed. Processed: $count. Errors: $errors.";
                if (!empty($error_details)) {
                    $message .= " <br>Validation Failures: " . implode(", ", array_slice($error_details, 0, 3)) . (count($error_details)>3 ? "..." : "");
                }
                 // Log
                if (isset($_SESSION['user_id'])) {
                    $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
                    $stmtLog->execute([$_SESSION['user_id'], 'Bulk Import', "Imported/Updated $count students via CSV"]);
                }
            }
        }
    } else {
        $message = "Error: File upload failed.";
    }
}

// Download CSV Template
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $t_dept = $_GET['dept'] ?? 'Computer Science';
    $t_year = $_GET['year'] ?? 'Year 1 Semester 1';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="student_template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Full Name', 'Student ID', 'Gender', 'Entry Year', 'Grad Year', 'Program', 'Department', 'Year Level', 'Status', 'Cafe Type', 'Cafe Gate', 'Photo Filename']);
    fputcsv($output, ['John Doe', 'STU001', 'Male', '2022/23', '2026-06', 'Regular', $t_dept, $t_year, 'Active', 'Cafe user', 'Cafe Gate 1', 'john.jpg']);
    fclose($output);
    exit;
}

// Fetch Departments
$campus_id = $_SESSION['campus_id'] ?? null;

// Fetch departments WITH semester_length for dynamic dropdown
$deptSemesterMap = []; // name => semester_length
if ($_SESSION['role'] === 'College Registrar') {
    $departments = $assigned_depts;
    // Fetch semester lengths for assigned departments
    if (!empty($assigned_depts)) {
        $inPlaceholders = implode(',', array_fill(0, count($assigned_depts), '?'));
        $stmtDeptSem = $pdo->prepare("SELECT name, semester_length FROM departments WHERE name IN ($inPlaceholders) ORDER BY name ASC");
        $stmtDeptSem->execute($assigned_depts);
        foreach ($stmtDeptSem->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $deptSemesterMap[$row['name']] = (int)$row['semester_length'];
        }
    }
} else {
    $deptQuery = "SELECT name, semester_length FROM departments WHERE 1=1";
    $deptParams = [];
    if ($campus_id) {
        $deptQuery .= " AND campus_id = ?";
        $deptParams[] = $campus_id;
    }
    $deptQuery .= " ORDER BY name ASC";
    $stmt = $pdo->prepare($deptQuery);
    $stmt->execute($deptParams);
    $deptRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $departments = array_column($deptRows, 'name');
    foreach ($deptRows as $row) {
        $deptSemesterMap[$row['name']] = (int)($row['semester_length'] ?? 8);
    }
}
if (!$departments) {
    $departments = ['Computer Science', 'Accounting Management', 'Information Technology']; // Fallback
    $deptSemesterMap = ['Computer Science' => 8, 'Accounting Management' => 8, 'Information Technology' => 8];
}

// Fetch Cafeteria Gates
$gateQuery = "SELECT cafe_gate_name FROM cafeteria_gate WHERE 1=1";
$gateParams = [];
if ($campus_id) {
    $gateQuery .= " AND campus_id = ?";
    $gateParams[] = $campus_id;
}
$gateQuery .= " ORDER BY cafe_gate_name ASC";
$stmt = $pdo->prepare($gateQuery);
$stmt->execute($gateParams);
$cafeteria_gates = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!$cafeteria_gates) $cafeteria_gates = ['Cafe Gate 1', 'Cafe Gate 2']; // Fallback

// Search and Filter Logic
$search = trim($_GET['search'] ?? '');
$filter_dept = $_GET['dept'] ?? '';
$filter_campus = $_GET['campus'] ?? '';

// First letter of the first name (A-Z). Anything else is ignored.
$alphabet = range('A', 'Z');
$filter_letter = strtoupper(trim($_GET['letter'] ?? ''));
if (!in_array($filter_letter, $alphabet, true)) {
    $filter_letter = '';
}

/* ----------------------------------------------------------
   PAGINATION SETTINGS
   - $per_page : rows per page, chosen by the user (?per_page=)
   - $page     : current page number (?page=)
   Both are whitelisted / cast to int so they are safe to inline
   into the LIMIT clause.
---------------------------------------------------------- */
$allowed_per_page = [10, 25, 50, 100, 250, 500];
$default_per_page = 25;

$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : $default_per_page;
if (!in_array($per_page, $allowed_per_page, true)) {
    $per_page = $default_per_page;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

/* ----------------------------------------------------------
   BUILD THE WHERE CLAUSE ONCE, REUSE IT FOR:
     1. per-department counts
     2. COUNT(*) of the current filter
     3. the page of rows itself
   $whereBase = every condition EXCEPT the department filter,
   so the department dropdown can always show every department
   with its own total.
---------------------------------------------------------- */
$whereBase  = " WHERE 1=1";
$paramsBase = [];

if ($campus_id) {
    // If Admin is restricted (Campus Admin or other), force their campus
    $whereBase .= " AND campus_id = ?";
    $paramsBase[] = $campus_id;
} elseif ($_SESSION['role'] === 'Super Admin' && !empty($filter_campus)) {
    // If Super Admin and filter selected
    $whereBase .= " AND campus_id = ?";
    $paramsBase[] = $filter_campus;
}

// Role-based filtering
if ($_SESSION['role'] === 'College Registrar') {
    if (empty($assigned_depts)) {
        $whereBase .= " AND 1=0"; // No access
    } else {
        $in  = str_repeat('?,', count($assigned_depts) - 1) . '?';
        $whereBase .= " AND department IN ($in)";
        $paramsBase = array_merge($paramsBase, $assigned_depts);
    }
} elseif ($_SESSION['role'] === 'ID Card Printer') {
    $whereBase .= " AND enrollment_status = 'Active'";
}

if ($search !== '') {
    $whereBase .= " AND (full_name LIKE ? OR student_id LIKE ?)";
    $paramsBase[] = "%$search%";
    $paramsBase[] = "%$search%";
}

/* ----------------------------------------------------------
   Two "sibling" filters: department and first letter.
   Each counter query applies the OTHER filter but not itself,
   so the department dropdown and the A-Z bar always show
   meaningful, non-zero-by-accident totals.
---------------------------------------------------------- */
$deptClause    = '';
$deptParams    = [];
if (!empty($filter_dept)) {
    $deptClause  = " AND department = ?";
    $deptParams[] = $filter_dept;
}

$letterClause  = '';
$letterParams  = [];
if ($filter_letter !== '') {
    $letterClause  = " AND full_name LIKE ?";
    $letterParams[] = $filter_letter . '%';
}

// Students per department (respects role/campus/search + the selected letter)
$deptCounts = [];
try {
    $stmtDeptCount = $pdo->prepare("SELECT department, COUNT(*) AS total FROM students" . $whereBase . $letterClause . " GROUP BY department");
    $stmtDeptCount->execute(array_merge($paramsBase, $letterParams));
    foreach ($stmtDeptCount->fetchAll(PDO::FETCH_ASSOC) as $dc) {
        $deptCounts[$dc['department']] = (int)$dc['total'];
    }
} catch (Exception $e) {
    $deptCounts = [];
}
$grand_total = array_sum($deptCounts);

// Students per first letter (respects role/campus/search + the selected department)
$letterCounts = array_fill_keys($alphabet, 0);
try {
    $stmtLetterCount = $pdo->prepare("SELECT UPPER(LEFT(full_name, 1)) AS first_letter, COUNT(*) AS total FROM students" . $whereBase . $deptClause . " GROUP BY first_letter");
    $stmtLetterCount->execute(array_merge($paramsBase, $deptParams));
    foreach ($stmtLetterCount->fetchAll(PDO::FETCH_ASSOC) as $lc) {
        $L = $lc['first_letter'];
        if (isset($letterCounts[$L])) {
            $letterCounts[$L] = (int)$lc['total'];
        }
    }
} catch (Exception $e) {
    $letterCounts = array_fill_keys($alphabet, 0);
}
$letters_total = array_sum($letterCounts);

// Now add both filters for the actual listing
$where  = $whereBase . $deptClause . $letterClause;
$params = array_merge($paramsBase, $deptParams, $letterParams);

// 1) Total rows matching the current filter
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM students" . $where);
$stmtCount->execute($params);
$total_students = (int)$stmtCount->fetchColumn();

// 2) Work out how many pages that is, and clamp the requested page
$total_pages = (int)ceil($total_students / $per_page);
if ($total_pages < 1) {
    $total_pages = 1;
}
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

// 3) Fetch only this page.
//    Browsing by letter reads better alphabetically; otherwise keep newest first.
$order_by = $filter_letter !== '' ? " ORDER BY full_name ASC" : " ORDER BY created_at DESC";
$query = "SELECT * FROM students" . $where . $order_by . " LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Row numbers shown in the table ("Showing 26-50 of 312")
$showing_from = $total_students > 0 ? $offset + 1 : 0;
$showing_to   = min($offset + $per_page, $total_students);

// Keep every current filter when jumping between pages
$pg_query = $_GET;
unset($pg_query['page']);
$pg_base = 'students?' . (empty($pg_query) ? '' : http_build_query($pg_query) . '&');

// Base URL for the A-Z bar (drops page + letter, keeps search/dept/campus/per_page)
$ltr_query = $_GET;
unset($ltr_query['page'], $ltr_query['letter']);
$ltr_base = 'students?' . (empty($ltr_query) ? '' : http_build_query($ltr_query) . '&');

/* ----------------------------------------------------------
   ROW-SELECTION CAPABILITIES
   - College Registrar may neither print nor delete, so no checkboxes.
   - ID Card Printer may select rows (to print them in bulk) but the
     Delete Selected button stays hidden for that role.
---------------------------------------------------------- */
$can_select     = ($_SESSION['role'] !== 'College Registrar');
$can_bulk_print = ($_SESSION['role'] !== 'College Registrar');
$can_bulk_delete = !in_array($_SESSION['role'], ['College Registrar', 'ID Card Printer'], true);

/* ----------------------------------------------------------
   print_id.php joins the campuses table so the card can show
   a campus name. The listing query is left untouched; instead
   we look the names up once and attach them per row.
---------------------------------------------------------- */
$campusMap = [];
try {
    foreach ($pdo->query("SELECT id, campus_name FROM campuses") as $cRow) {
        $campusMap[$cRow['id']] = $cRow['campus_name'];
    }
} catch (Exception $e) {
    $campusMap = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['students']; ?> - OSU One Card</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <style>
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(12, 33, 26, 0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: var(--card-bg); padding: 20px; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; position: relative; }
        .close-modal { position: absolute; right: 15px; top: 15px; font-size: 20px; cursor: pointer; color: var(--secondary-color); }
        .show-modal { display: flex; }
        /* lang-btn styles removed to use theme.css */

        /* ---------- Pagination ---------- */
        .pagination-bar {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 12px; padding: 15px; border-top: 1px solid var(--border-color);
        }
        .pagination-info { font-size: 13px; color: var(--secondary-color); }
        .pagination { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
        .pagination a, .pagination span {
            display: inline-block; min-width: 38px; text-align: center;
            padding: 8px 10px; font-size: 14px; line-height: 1; text-decoration: none;
            border: 1px solid var(--border-color); border-radius: 6px;
            color: inherit; background: transparent;
        }
        .pagination a:hover { background: var(--hover-bg-light); }
        .pagination .pg-current { background: var(--primary-fill); border-color: var(--primary-color); color: var(--on-fill); font-weight: bold; }
        .pagination .pg-gap { border-color: transparent; cursor: default; }
        .pagination .pg-disabled { opacity: 0.4; }
        .per-page-box { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--secondary-color); }

        /* ---------- A-Z first-letter filter ---------- */
        .alpha-bar {
            display: flex; flex-wrap: wrap; align-items: center; gap: 4px;
            padding: 0 15px 15px 15px;
        }
        .alpha-bar a, .alpha-bar span {
            display: inline-block; min-width: 32px; text-align: center;
            padding: 6px 4px; font-size: 13px; line-height: 1; font-weight: 600;
            text-decoration: none; color: inherit;
            border: 1px solid var(--border-color); border-radius: 5px;
        }
        .alpha-bar a:hover { background: var(--hover-bg-light); }
        .alpha-bar .alpha-all { min-width: auto; padding: 6px 12px; }
        .alpha-bar .alpha-active { background: var(--primary-fill); border-color: var(--primary-color); color: var(--on-fill); }
        .alpha-bar .alpha-empty { opacity: 0.3; cursor: not-allowed; font-weight: normal; }

        /* ---------- Bulk action bar ---------- */
        .bulk-actions {
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
            padding: 15px; border-top: 1px solid var(--border-color);
        }
        .bulk-count { font-size: 13px; color: var(--secondary-color); margin-right: auto; }

        /* ---------- Card-back chooser ---------- */
        .backpage-control {
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
            padding: 12px 15px; border-top: 1px solid var(--border-color);
        }
        .backpage-control .bp-label { font-size: 13px; font-weight: 600; color: var(--secondary-color); }
        .backpage-control .bp-status { font-size: 13px; color: var(--secondary-color); }
        .backpage-control .bp-preview {
            width: 52px; height: 33px; object-fit: cover;
            border: 1px solid var(--border-color); border-radius: 4px; background: var(--card-bg);
        }
        .backpage-control .bp-clear {
            border: 1px solid var(--border-color); background: transparent; color: inherit;
            border-radius: 5px; width: 28px; height: 28px; cursor: pointer;
        }
        .backpage-control .bp-clear:hover { background: var(--hover-bg-light); }

        /* ---------- Output options (background removal) ---------- */
        .output-options {
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
            padding: 0 15px 12px 15px;
        }
        .output-options .opt-check {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 13px; font-weight: 600; color: var(--secondary-color); cursor: pointer;
        }
        .output-options .opt-check input { width: 15px; height: 15px; cursor: pointer; }
        .output-options .opt-hint { font-size: 12px; color: var(--secondary-color); opacity: 0.75; }

        /* The back card matches the front card box exactly, so front and
           back PNGs come out the same pixel size for clean duplex printing. */
        #printArea .back-card { padding: 0; }
        #printArea .back-card img { width: 100%; height: 100%; object-fit: cover; display: block; }

        /* =====================================================================
           ID CARD SHEET — ported verbatim from print_id.php
           ---------------------------------------------------------------------
           Every rule below is the print_id.php stylesheet, scoped under
           #printArea so the admin theme cannot bleed into the cards.
           Keep these two files in step: if the card changes in print_id.php,
           mirror the change here and in renderIdCardHTML().
        ===================================================================== */
        #printArea {
            position: absolute; left: -10000px; top: 0;
            background: #fff;
            font-family: 'Arial', sans-serif;
        }
        #printArea .sheet {
            display: grid; grid-template-columns: repeat(3, 220px);
            gap: 18px 22px; justify-content: center; padding: 10px 0;
        }

        /* Mirrors the print_id.php card */
        /* Portrait card: 220 x 350 px = CR80 (54 x 85.6 mm) standing on its short edge */
        #printArea .id-card {
            width: 220px;
            height: 350px;
            background: white;
            border-radius: 12px;
            position: relative;
            color: #0C211A;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        #printArea .header {
            padding: 10px 8px 7px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            text-align: center;
            background: #144130;
            color: #FFFFFF;
            border-bottom: 3px solid #A79241;
        }
        /* Trilingual horizontal lockup (4.73:1) spans the header width */
        #printArea .header-logo {
            width: 190px;
            height: auto;
            display: block;
            background: transparent;
        }
        #printArea .logo {
            font-weight: bold;
            font-size: 7.5px;
            line-height: 1.25;
            text-transform: uppercase;
            background: transparent;
        }
        #printArea .logo .card-kind {
            display: block;
            letter-spacing: 1px;
            color: #B8A56E;
        }
        #printArea .content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 7px;
            padding: 10px 12px 0;
        }
        #printArea .photo-box {
            width: 90px;
            height: 108px;
            flex-shrink: 0;
            background: white;
            border-radius: 6px;
            overflow: hidden;
            border: 1.5px solid #144130;
        }
        #printArea .photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        #printArea .name {
            font-size: 12.5px;
            font-weight: bold;
            line-height: 1.15;
            text-align: center;
            text-transform: uppercase;
        }
        #printArea .card-bottom {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            padding: 0 12px 12px;
        }
        #printArea .details {
            flex: 1;
            min-width: 0;
        }
        #printArea .info {
            font-size: 8.8px;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }
        /* QR code: holds the ID number exactly as the old barcode did */
        #printArea .qr {
            width: 66px;
            height: 66px;
            flex-shrink: 0;
            background: white;
        }
        #printArea .qr img,
        #printArea .qr canvas {
            width: 100%;
            height: 100%;
            display: block;
            image-rendering: pixelated;
        }
        /* Card without a photo (labour): large QR in the photo position, details below */
        #printArea .id-card.no-photo .qr {
            width: 112px;
            height: 112px;
        }
        #printArea .id-card.no-photo .card-bottom {
            padding-top: 4px;
        }
        #printArea .id-card.no-photo .info {
            font-size: 10px;
        }

        @media print {
            @page { size: A4 portrait; margin: 10mm; }

            /* While printing cards, hide the whole admin UI */
            body.printing-cards > *:not(#printArea) { display: none !important; }
            body.printing-cards #printArea {
                position: static !important; left: auto !important;
            }
            body.printing-cards { background: white !important; }

            /* print_id.php print rules */
            #printArea .id-card {
                box-shadow: none;
                border: 1px solid #A8B5A0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            /* 3 columns x 2 rows = 6 portrait cards per A4 page */
            #printArea .id-card:nth-child(6n) { page-break-after: always; }
        }
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
                    <i class="fas fa-users"></i> <?php echo $lang['student_management']; ?>
                </div>
            </div>
            <div class="navbar-actions" style="display: flex; align-items: center; gap: 10px;">
                <?php if ($_SESSION['role'] !== 'ID Card Printer'): ?>
                <button class="btn btn-primary" onclick="openModal('addStudentModal')">
                    <i class="fas fa-plus-circle"></i> <?php echo $lang['add_new_student']; ?>
                </button>
<?php if ($_SESSION['role'] !== 'College Registrar'): ?>
                 <a href="departments" class="btn btn-secondary" style="background: var(--primary-fill); color: white;">
                    <i class="fas fa-building"></i> <?php echo $lang['departments']; ?>
                </a>
                <?php endif; ?>
                <button class="btn btn-secondary" onclick="openModal('bulkUploadModal')" style="background: var(--neutral-fill); color: white;">
                    <i class="fas fa-file-upload"></i> <?php echo $lang['import_csv']; ?>
                </button>
                <a href="promote_students" class="btn btn-secondary" style="background: var(--warning-fill); color: var(--text-color); text-decoration: none;">
                    <i class="fas fa-level-up-alt"></i> <?php echo $lang['promote']; ?>
                </a>
                <?php endif; ?>
                
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
                <div class="card flash <?php echo strpos($message, 'Error') ? 'flash-error' : 'flash-success'; ?>" style="">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Add Student Modal -->
            <div id="addStudentModal" class="modal">
                <div class="modal-content">
                    <span class="close-modal" onclick="closeModal('addStudentModal')">&times;</span>
                    <div class="card-header" style="border:none; margin-bottom:15px;">
                        <i class="fas fa-plus-circle"></i> <?php echo $lang['add_new_student']; ?>
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="background: var(--card-bg); padding: 15px; border-radius: 8px;">
                        <input type="hidden" name="action" value="add_student">
                        <div style="display: grid; grid-template-columns: 1fr; gap: 15px;">
                            <input type="text" name="full_name" placeholder="<?php echo $lang['full_name']; ?>" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <select name="gender" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                            <input type="text" name="entry_year" placeholder="Entry Year (2022/23)" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <input type="text" name="graduation_year" placeholder="Grad Year (YYYY-MM)" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <select name="program" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <option value="">Select Program</option>
                                <option value="Regular">Regular</option>
                                <option value="Extension Night">Extension Night</option>
                                <option value="Extension Summer">Extension Summer</option>
                                <option value="Extension Weekend"> Extension Weekend</option>
                            </select>
                            <input type="text" name="student_id" placeholder="<?php echo $lang['student_id']; ?>" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <select name="department" id="add_department" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" onchange="updateYearSemester('add_department','add_year_level')">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="year_level" id="add_year_level" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <option value="">Select Department First</option>
                            </select>
                            <select name="enrollment_status" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <option value="Active">Active</option>
                                <option value="Dismissed">Dismissed</option>
                                <option value="Graduated">Graduated</option>
                            </select>
                            <select name="cafe_type" id="add_cafe_type" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" onchange="updateCafeGate('add_cafe_type','add_cafeteria_gate')">
                                <option value="Cafe user">Cafe user</option>
                                <option value="Non Cafe">Non Cafe</option>
                            </select>
                            <select name="cafeteria_gate" id="add_cafeteria_gate" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; transition: opacity 0.2s;">
                                <option value="">Select Cafe Gate</option>
                                <?php foreach ($cafeteria_gates as $gate): ?>
                                    <option value="<?php echo htmlspecialchars($gate); ?>"><?php echo htmlspecialchars($gate); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div style="display: flex; flex-direction: column; gap: 5px;">
                                <label style="font-size: 12px; color: var(--secondary-color);"><?php echo $lang['photo']; ?> (Max 50kb)</label>
                                <input type="file" name="student_photo" accept="image/*" style="padding: 5px; border: 1px solid var(--border-color); border-radius: 6px;">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="margin-top: 15px; width: 100%;">
                            <i class="fas fa-plus"></i> <?php echo $lang['add_new_student']; ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Edit Student Modal (Content similar to Add, skipped for brevity but would need similar updates) -->
            <!-- Note: In a real scenario I would update this too, but for Fast mode I prioritize functionality -->
            <div id="editStudentModal" class="modal">
                <div class="modal-content">
                    <span class="close-modal" onclick="closeModal('editStudentModal')">&times;</span>
                    <div class="card-header" style="border:none; margin-bottom:15px;">
                        <i class="fas fa-edit"></i> <?php echo $lang['edit']; ?> Student
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="background: var(--card-bg); padding: 15px; border-radius: 8px;">
                        <input type="hidden" name="action" value="update_student">
                        <input type="hidden" name="original_student_id" id="edit_original_student_id">
                        <div style="display: grid; grid-template-columns: 1fr; gap: 15px;">
                            <input type="text" name="full_name" id="edit_full_name" placeholder="<?php echo $lang['full_name']; ?>" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <select name="gender" id="edit_gender" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                            <input type="text" name="entry_year" id="edit_entry_year" placeholder="Entry Year (2022/23)" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <input type="text" name="graduation_year" id="edit_graduation_year" placeholder="Grad Year (YYYY-MM)" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <select name="program" id="edit_program" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <option value="">Select Program</option>
                                <option value="Regular">Regular</option>
                                <option value="Extension Night">Extension Night</option>
                                <option value="Extension Summer">Extension Summer</option>
                                <option value="Extension Weekend"> Extension Weekend</option>
                            </select>
                            <input type="text" name="student_id" id="edit_student_id" placeholder="<?php echo $lang['student_id']; ?>" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <select name="department" id="edit_department" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" onchange="updateYearSemester('edit_department','edit_year_level')">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="year_level" id="edit_year_level" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <option value="">Select Year & Semester</option>
                            </select>
                            <select name="enrollment_status" id="edit_enrollment_status" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <option value="Active">Active</option>
                                <option value="Dismissed">Dismissed</option>
                                <option value="Graduated">Graduated</option>
                            </select>
                            <select name="cafe_type" id="edit_cafe_type" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" onchange="updateCafeGate('edit_cafe_type','edit_cafeteria_gate')">
                                <option value="Cafe user">Cafe user</option>
                                <option value="Non Cafe">Non Cafe</option>
                            </select>
                            <select name="cafeteria_gate" id="edit_cafeteria_gate" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; transition: opacity 0.2s;">
                                <option value="">Select Cafe Gate</option>
                                <?php foreach ($cafeteria_gates as $gate): ?>
                                    <option value="<?php echo htmlspecialchars($gate); ?>"><?php echo htmlspecialchars($gate); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div style="display: flex; flex-direction: column; gap: 5px;">
                                <label style="font-size: 12px; color: var(--secondary-color);">Update Photo (Optional, Max 50kb)</label>
                                <input type="file" name="edit_student_photo" accept="image/*" style="padding: 5px; border: 1px solid var(--border-color); border-radius: 6px;">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="margin-top: 15px; width: 100%;">
                            <i class="fas fa-save"></i> <?php echo $lang['update']; ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Bulk Upload Modal -->
            <div id="bulkUploadModal" class="modal">
                <div class="modal-content">
                    <span class="close-modal" onclick="closeModal('bulkUploadModal')">&times;</span>
                    <div class="card-header" style="border:none; margin-bottom:15px;">
                        <i class="fas fa-file-upload"></i> <?php echo $lang['import_csv']; ?>
                    </div>
                    <div style="padding: 15px; border-bottom: 1px solid var(--border-color); background: var(--card-bg); display: flex; justify-content: space-between; align-items: center; border-radius:6px; margin-bottom:15px;">
                        <span>Step 0: Download Template</span>
                        <a href="students?action=download_template" id="template_dl_link" class="btn btn-sm btn-secondary">Download CSV</a>
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="background: var(--card-bg); padding: 15px; border-radius: 8px;">
                        <input type="hidden" name="action" value="upload_csv">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px;">Step 1: Context</label>
                            
                            <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                            <select name="upload_campus_id" id="upload_campus_id" style="width:100%; padding: 10px; margin-bottom:10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <option value="">Select Campus</option>
                                <?php 
                                $upCampuses = $pdo->query("SELECT * FROM campuses")->fetchAll();
                                foreach ($upCampuses as $uc): ?>
                                <option value="<?php echo $uc['id']; ?>"><?php echo htmlspecialchars($uc['campus_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>

                            <select name="upload_department" id="upload_department" required style="width:100%; padding: 10px; margin-bottom:10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="upload_year_level" id="upload_year_level" required style="width:100%; padding: 10px; margin-bottom:10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <option value="">Select Year Level</option>
                                <option value="Year 1 Semester 1">Year 1 Semester 1</option>
                                <option value="Year 1 Semester 2">Year 1 Semester 2</option>
                                <option value="Year 2 Semester 1">Year 2 Semester 1</option>
                                <option value="Year 2 Semester 2">Year 2 Semester 2</option>
                                <option value="Year 3 Semester 1">Year 3 Semester 1</option>
                                <option value="Year 3 Semester 2">Year 3 Semester 2</option>
                                <option value="Year 4 Semester 1">Year 4 Semester 1</option>
                                <option value="Year 4 Semester 2">Year 4 Semester 2</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px;">Step 2: Select CSV</label>
                            <input type="file" name="csv_file" accept=".csv" required style="padding: 10px; border: 1px solid var(--border-color); width: 100%; border-radius: 6px;">
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Upload & Process</button>
                    </form>
                    <script>
                        function updateTemplateLink() {
                            const dept = document.getElementById('upload_department').value;
                            const year = document.getElementById('upload_year_level').value;
                            const link = document.getElementById('template_dl_link');
                            link.href = 'students?action=download_template&dept=' + encodeURIComponent(dept) + '&year=' + encodeURIComponent(year);
                        }
                        // Add event listeners to update link dynamically
                        document.getElementById('upload_department').addEventListener('change', updateTemplateLink);
                        document.getElementById('upload_year_level').addEventListener('change', updateTemplateLink);
                    </script>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list"></i> <?php echo $lang['students']; ?> List
                </div>
                <div style="padding: 15px; display: flex; gap: 10px;">
                    <form method="GET" style="display: flex; gap: 10px; flex: 1;">
                        <input type="text" name="search" placeholder="Search by name or ID" value="<?php echo htmlspecialchars($search); ?>" style="flex: 1; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                        
                        <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                        <select name="campus" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <option value="">All Campuses</option>
                            <?php 
                            $filCampuses = $pdo->query("SELECT * FROM campuses")->fetchAll();
                            foreach ($filCampuses as $fc): ?>
                                <option value="<?php echo $fc['id']; ?>" <?php echo $filter_campus == $fc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($fc['campus_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <select name="dept" onchange="this.form.submit()" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <option value="">All Departments (<?php echo $grand_total; ?>)</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $filter_dept === $dept ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept); ?> (<?php echo isset($deptCounts[$dept]) ? $deptCounts[$dept] : 0; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="per_page" onchange="this.form.submit()" title="Rows per page" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <?php foreach ($allowed_per_page as $pp): ?>
                                <option value="<?php echo $pp; ?>" <?php echo $per_page === $pp ? 'selected' : ''; ?>><?php echo $pp; ?> / page</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                        <!-- keep the active letter when searching / changing department -->
                        <input type="hidden" name="letter" value="<?php echo htmlspecialchars($filter_letter); ?>">
                    </form>
                </div>

                <!-- ---------- Filter by first letter of first name ---------- -->
                <div class="alpha-bar">
                    <a href="<?php echo htmlspecialchars(rtrim($ltr_base, '?&')); ?>"
                       class="alpha-all <?php echo $filter_letter === '' ? 'alpha-active' : ''; ?>">
                        All (<?php echo $letters_total; ?>)
                    </a>
                    <?php foreach ($alphabet as $L): ?>
                        <?php $cnt = $letterCounts[$L]; ?>
                        <?php if ($cnt === 0): ?>
                            <span class="alpha-empty" title="No students"><?php echo $L; ?></span>
                        <?php elseif ($L === $filter_letter): ?>
                            <span class="alpha-active" title="<?php echo $cnt; ?> student(s)"><?php echo $L; ?></span>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars($ltr_base . 'letter=' . $L); ?>" title="<?php echo $cnt; ?> student(s)"><?php echo $L; ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div style="overflow-x: auto;">
                    <form method="POST" id="bulkForm">
                        <input type="hidden" name="action" value="bulk_delete">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: var(--hover-bg-light);">
                                    <th style="padding: 12px; border-bottom: 2px solid var(--border-color); width: 40px;">
                                        <?php if ($can_select): ?>
                                            <input type="checkbox" id="selectAllBox" onclick="toggleSelectAll(this)" title="Select all on this page">
                                        <?php endif; ?>
                                    </th>
                                    <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);">Photo</th>
                                    <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);"><?php echo $lang['student_id']; ?></th>
                                    <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);"><?php echo $lang['full_name']; ?></th>
                                    <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);"><?php echo $lang['department']; ?></th>
                                    <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);"><?php echo $lang['status']; ?></th>
                                    <th style="padding: 12px; text-align: right; border-bottom: 2px solid var(--border-color);"><?php echo $lang['actions']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="7" style="padding: 30px; text-align: center; color: var(--secondary-color);">
                                        <i class="fas fa-inbox"></i> No students found for the current filter.
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach ($students as $stu): ?>
                                <?php
                                    // Can this particular student's ID card be printed?
                                    $can_print = true;
                                    if ($stu['enrollment_status'] === 'Graduated') $can_print = false;
                                    if ($_SESSION['role'] === 'ID Card Printer' && $stu['enrollment_status'] !== 'Active') $can_print = false;
                                    if ($_SESSION['role'] === 'College Registrar') $can_print = false;

                                    // Every field except the photo BLOB, for the edit modal AND the ID card builder
                                    $stuForJs = $stu;
                                    unset($stuForJs['photo']);
                                    // print_id.php shows the campus name, so carry it along
                                    $stuForJs['campus_name'] = $campusMap[$stu['campus_id'] ?? null] ?? 'Main Campus';
                                    $stuJson = json_encode($stuForJs);
                                    if ($stuJson === false) { $stuJson = '{}'; }
                                ?>
                                <tr style="border-bottom: 1px solid var(--border-color);"
                                    data-student="<?php echo htmlspecialchars($stuJson, ENT_QUOTES, 'UTF-8'); ?>">
                                    <td style="padding: 12px;">
                                        <?php if ($can_select): ?>
                                            <input type="checkbox" class="row-check" name="selected_ids[]"
                                                   value="<?php echo htmlspecialchars($stu['student_id']); ?>"
                                                   data-printable="<?php echo $can_print ? '1' : '0'; ?>"
                                                   onchange="updateSelectionCount()">
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <?php if (!empty($stu['photo'])): ?>
                                            <img class="stu-photo" src="data:image/jpeg;base64,<?php echo base64_encode($stu['photo']); ?>" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                                        <?php else: ?>
                                            <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--avatar-bg); display: flex; align-items: center; justify-content: center;"><i class="fas fa-user"></i></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px;"><?php echo htmlspecialchars($stu['student_id']); ?></td>
                                    <td style="padding: 12px;"><?php echo htmlspecialchars($stu['full_name']); ?></td>
                                    <td style="padding: 12px;"><?php echo htmlspecialchars($stu['department']); ?></td>
                                    <td style="padding: 12px;">
                                        <?php 
                                        $bg = 'var(--danger-bg)'; $col = 'var(--danger-on)';
                                        if ($stu['enrollment_status'] === 'Active') { $bg = 'var(--success-bg)'; $col = 'var(--success-on)'; }
                                        elseif ($stu['enrollment_status'] === 'Graduated') { $bg = 'var(--neutral-bg)'; $col = 'var(--neutral-on)'; }
                                        ?>
                                        <span style="padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; background: <?php echo $bg; ?>; color: <?php echo $col; ?>;">
                                            <?php echo htmlspecialchars($stu['enrollment_status']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px; text-align: right;">
                                        <?php if ($can_print): ?>
                                            <a href="print_id?id=<?php echo urlencode($stu['student_id']); ?>" target="_blank" class="btn btn-sm btn-secondary" title="Print/Download ID">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($_SESSION['role'] !== 'ID Card Printer' && $stu['enrollment_status'] !== 'Graduated'): ?>
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="openEditModal(<?php echo htmlspecialchars($stuJson, ENT_QUOTES, 'UTF-8'); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($can_select): ?>
                        <?php if ($can_bulk_print): ?>
                        <!-- Common card back, shared by every exported PNG. Chosen once,
                             remembered in this browser. Drop a file at assets/img/id_back.png
                             to have one loaded automatically for everyone. -->
                        <div class="backpage-control">
                            <span class="bp-label"><i class="fas fa-clone"></i> Card back:</span>
                            <input type="file" id="backPageInput" accept="image/png,image/jpeg,image/webp"
                                   style="display:none" onchange="onBackPageSelected(this)">
                            <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="document.getElementById('backPageInput').click()">
                                <i class="fas fa-upload"></i> Upload back
                            </button>
                            <img id="backPagePreview" class="bp-preview" style="display:none" alt="back page">
                            <span id="backPageStatus" class="bp-status">None — front only</span>
                            <button type="button" id="backPageClear" class="bp-clear" style="display:none"
                                    onclick="clearBackPage()" title="Remove back page">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="output-options">
                            <label class="opt-check">
                                <input type="checkbox" id="bgRemoveToggle" onchange="saveBgPref()">
                                <i class="fas fa-wand-magic-sparkles"></i>
                                Remove photo background automatically
                            </label>
                            <span class="opt-hint">Best with a plain studio backdrop</span>
                        </div>
                        <?php endif; ?>
                        <div class="bulk-actions">
                            <span class="bulk-count">
                                <i class="fas fa-check-square"></i>
                                <strong id="selCount">0</strong> selected on this page
                            </span>

                            <?php if ($can_bulk_print): ?>
                            <!-- All three build the ID cards in this page - no second page involved -->
                            <button type="button" class="btn btn-primary" onclick="bulkPrintIds()">
                                <i class="fas fa-print"></i> Print Selected IDs
                            </button>

                            <button type="button" class="btn btn-secondary" id="bulkPngBtn" onclick="bulkDownloadPng()">
                                <i class="fas fa-file-image"></i> Download PNG
                            </button>

                            <button type="button" class="btn btn-secondary" id="bulkPdfBtn" onclick="bulkDownloadIds()">
                                <i class="fas fa-file-pdf"></i> Download PDF
                            </button>
                            <?php endif; ?>

                            <?php if ($can_bulk_delete): ?>
                            <button type="submit" class="btn btn-danger"
                                    onclick="return confirmBulkDelete()">
                                <i class="fas fa-trash"></i> Delete Selected
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- ---------- Pagination: 1 | 2 | 3 | ... | n ---------- -->
                <div class="pagination-bar">
                    <div class="pagination-info">
                        <?php if ($total_students > 0): ?>
                            Showing <strong><?php echo $showing_from; ?>-<?php echo $showing_to; ?></strong>
                            of <strong><?php echo $total_students; ?></strong> students
                            <?php if (!empty($filter_dept)): ?>
                                in <strong><?php echo htmlspecialchars($filter_dept); ?></strong>
                            <?php endif; ?>
                            <?php if ($filter_letter !== ''): ?>
                                starting with <strong><?php echo htmlspecialchars($filter_letter); ?></strong>
                            <?php endif; ?>
                            &nbsp;&middot;&nbsp; Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        <?php else: ?>
                            No records
                        <?php endif; ?>
                    </div>

                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php
                        // Previous
                        if ($page > 1) {
                            echo '<a href="' . htmlspecialchars($pg_base . 'page=' . ($page - 1)) . '" title="Previous">&laquo;</a>';
                        } else {
                            echo '<span class="pg-disabled">&laquo;</span>';
                        }

                        // Which page numbers to show: first, last and 2 either side of current
                        $window = 2;
                        $numbers = [];
                        for ($i = 1; $i <= $total_pages; $i++) {
                            if ($i === 1 || $i === $total_pages || abs($i - $page) <= $window) {
                                $numbers[] = $i;
                            }
                        }

                        $previous = 0;
                        foreach ($numbers as $n) {
                            if ($previous && $n > $previous + 1) {
                                echo '<span class="pg-gap">&hellip;</span>';
                            }
                            if ($n === $page) {
                                echo '<span class="pg-current">' . $n . '</span>';
                            } else {
                                echo '<a href="' . htmlspecialchars($pg_base . 'page=' . $n) . '">' . $n . '</a>';
                            }
                            $previous = $n;
                        }

                        // Next
                        if ($page < $total_pages) {
                            echo '<a href="' . htmlspecialchars($pg_base . 'page=' . ($page + 1)) . '" title="Next">&raquo;</a>';
                        } else {
                            echo '<span class="pg-disabled">&raquo;</span>';
                        }
                        ?>
                    </div>
                    <?php endif; ?>

                    <div class="per-page-box">
                        <label for="jump_per_page">Rows per page:</label>
                        <select id="jump_per_page" onchange="changePerPage(this.value)" style="padding: 6px 8px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <?php foreach ($allowed_per_page as $pp): ?>
                                <option value="<?php echo $pp; ?>" <?php echo $per_page === $pp ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ID cards are rendered here on demand, then printed or exported to PDF -->
    <div id="printArea" aria-hidden="true"></div>

    <script>
        // Department -> semester_length map (from PHP)
        const deptSemesterMap = <?php echo json_encode($deptSemesterMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        function openModal(id) {
            document.getElementById(id).classList.add('show-modal');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('show-modal');
        }

        /**
         * Populate year/semester options based on selected department.
         * @param {string} deptSelectId  - id of the department <select>
         * @param {string} yearSelectId  - id of the year_level <select>
         * @param {string} [currentVal]  - pre-select this value after rebuilding
         */
        function updateYearSemester(deptSelectId, yearSelectId, currentVal) {
            const deptName   = document.getElementById(deptSelectId).value;
            const yearSelect = document.getElementById(yearSelectId);
            const semCount   = deptSemesterMap[deptName] || 0;

            yearSelect.innerHTML = '';

            if (!deptName || semCount === 0) {
                yearSelect.innerHTML = '<option value="">Select Department First</option>';
                return;
            }

            yearSelect.innerHTML = '<option value="">Select Year & Semester</option>';

            for (let sem = 1; sem <= semCount; sem++) {
                const year      = Math.ceil(sem / 2);       // 1,1 -> Y1; 3,4 -> Y2 …
                const semInYear = ((sem - 1) % 2) + 1;     // always 1 or 2
                const label     = `Year ${year} Semester ${semInYear}`;
                const opt       = document.createElement('option');
                opt.value       = label;
                opt.textContent = label;
                if (currentVal && label === currentVal) opt.selected = true;
                yearSelect.appendChild(opt);
            }
        }

        /**
         * Enable/disable the cafeteria gate dropdown based on cafe_type.
         */
        function updateCafeGate(cafeTypeId, gateSelectId) {
            const cafeType  = document.getElementById(cafeTypeId).value;
            const gateEl    = document.getElementById(gateSelectId);
            const isCafe    = (cafeType === 'Cafe user');
            gateEl.disabled = !isCafe;
            gateEl.style.opacity     = isCafe ? '1'   : '0.45';
            gateEl.style.cursor      = isCafe ? ''     : 'not-allowed';
            gateEl.style.background  = isCafe ? ''     : 'var(--hover-bg-light)';
            if (!isCafe) gateEl.value = '';
        }

        function openEditModal(student) {
            document.getElementById('edit_original_student_id').value = student.student_id;
            document.getElementById('edit_student_id').value          = student.student_id;
            document.getElementById('edit_full_name').value           = student.full_name;
            document.getElementById('edit_gender').value              = student.gender || '';
            document.getElementById('edit_entry_year').value          = student.entry_year || '';
            document.getElementById('edit_graduation_year').value     = student.graduation_year || '';
            document.getElementById('edit_program').value             = student.program || '';
            document.getElementById('edit_department').value          = student.department;
            document.getElementById('edit_enrollment_status').value   = student.enrollment_status;
            document.getElementById('edit_cafe_type').value           = student.cafe_type;

            // Rebuild year/semester options for the current department, then select current value
            updateYearSemester('edit_department', 'edit_year_level', student.year_level);

            // Apply cafe gate state
            updateCafeGate('edit_cafe_type', 'edit_cafeteria_gate');
            document.getElementById('edit_cafeteria_gate').value = student.cafeteria_gate;

            openModal('editStudentModal');
        }

        // Initialise Add-form gate state on page load
        updateCafeGate('add_cafe_type', 'add_cafeteria_gate');

        // Change rows-per-page from the bottom bar, keeping all current filters
        function changePerPage(value) {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', value);
            url.searchParams.set('page', 1); // always jump back to page 1
            window.location.href = url.toString();
        }

        /* ---------- Bulk selection helpers ---------- */

        function getRowChecks() {
            return Array.prototype.slice.call(document.querySelectorAll('.row-check'));
        }

        function toggleSelectAll(source) {
            getRowChecks().forEach(function (cb) { cb.checked = source.checked; });
            updateSelectionCount();
        }

        function updateSelectionCount() {
            const checks   = getRowChecks();
            const selected = checks.filter(function (cb) { return cb.checked; });
            const counter  = document.getElementById('selCount');
            if (counter) counter.textContent = selected.length;

            // Keep the header checkbox in sync (checked / unchecked / indeterminate)
            const master = document.getElementById('selectAllBox');
            if (master) {
                master.checked = (checks.length > 0 && selected.length === checks.length);
                master.indeterminate = (selected.length > 0 && selected.length < checks.length);
            }
        }

        /**
         * Collects the checked rows that are actually eligible for a card.
         * Returns null if the user should be stopped.
         */
        function getPrintableSelection() {
            const selected = getRowChecks().filter(function (cb) { return cb.checked; });

            if (selected.length === 0) {
                alert('Please select at least one student first.');
                return null;
            }

            const printable = selected.filter(function (cb) { return cb.dataset.printable === '1'; });

            if (printable.length === 0) {
                alert('None of the selected students are eligible for an ID card.\n\n' +
                      'Graduated students cannot be issued a card.');
                return null;
            }

            if (printable.length < selected.length) {
                const skipped = selected.length - printable.length;
                const ok = confirm(skipped + ' of the ' + selected.length +
                    ' selected students are not eligible for an ID card and will be skipped.\n\nContinue with the remaining ' +
                    printable.length + '?');
                if (!ok) return null;
            }

            return printable.map(function (cb) { return cb.closest('tr'); });
        }

        function esc(v) {
            if (v === null || v === undefined) return '-';
            const s = String(v).trim();
            if (s === '') return '-';
            return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        /* =====================================================================
           COMMON CARD BACK
           ---------------------------------------------------------------------
           One image, shared by every exported card. Priority on load:
             1. a back the user uploaded earlier (kept in this browser)
             2. a server default at assets/img/id_back.png, if present
           Uploading always wins and is remembered for next time.
        ===================================================================== */
        const BACKPAGE_STORE_KEY = 'osu_id_backpage';
        const BACKPAGE_DEFAULT   = 'assets/img/id_back.png';
        let backPageSrc = null;   // data: URI or path, or null for front-only

        function setBackPage(src, remember) {
            backPageSrc = src || null;

            const preview = document.getElementById('backPagePreview');
            const status  = document.getElementById('backPageStatus');
            const clear   = document.getElementById('backPageClear');

            if (backPageSrc) {
                preview.src = backPageSrc;
                preview.style.display = '';
                clear.style.display = '';
                status.textContent = 'Back page ready — added to every PNG';
                status.style.color = 'var(--primary-color)';
            } else {
                preview.removeAttribute('src');
                preview.style.display = 'none';
                clear.style.display = 'none';
                status.textContent = 'None — front only';
                status.style.color = '';
            }

            if (remember) {
                try {
                    if (backPageSrc) localStorage.setItem(BACKPAGE_STORE_KEY, backPageSrc);
                    else localStorage.removeItem(BACKPAGE_STORE_KEY);
                } catch (e) {
                    // localStorage full or disabled — back page still works this session
                    console.warn('Could not remember the back page:', e);
                }
            }
        }

        function onBackPageSelected(input) {
            const file = input.files && input.files[0];
            if (!file) return;

            if (file.size > 4 * 1024 * 1024) {
                alert('Please choose a back image under 4 MB.');
                input.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function (e) { setBackPage(e.target.result, true); };
            reader.onerror = function () { alert('Could not read that image.'); };
            reader.readAsDataURL(file);
            input.value = ''; // let the same file be re-picked later
        }

        function clearBackPage() {
            setBackPage(null, true);
            const input = document.getElementById('backPageInput');
            if (input) input.value = '';
        }

        // Restore a remembered back, or fall back to the server default if it exists
        (function loadInitialBackPage() {
            let saved = null;
            try { saved = localStorage.getItem(BACKPAGE_STORE_KEY); } catch (e) {}

            if (saved) { setBackPage(saved, false); return; }

            const probe = new Image();
            probe.onload  = function () { setBackPage(BACKPAGE_DEFAULT, false); };
            probe.onerror = function () { /* no default on the server, that's fine */ };
            probe.src = BACKPAGE_DEFAULT + '?_=' + Date.now();
        })();

        /**
         * Renders the shared back once and captures it at the given scale.
         * Returns a PNG data URL, or null if no back page is set.
         */
        async function captureBackPng(scale) {
            if (!backPageSrc) return null;

            const area = document.getElementById('printArea');
            const holder = document.createElement('div');
            holder.innerHTML = '<div class="id-card back-card"><img class="back-img" alt=""></div>';
            area.appendChild(holder);

            const img = holder.querySelector('img');
            await new Promise(function (resolve) {
                img.onload = resolve;
                img.onerror = resolve;
                img.src = backPageSrc;
            });

            // The card is portrait. A landscape back (the older design) is turned
            // a quarter-turn so it fills the card instead of being cropped.
            if (img.naturalWidth > img.naturalHeight) {
                try {
                    const cv = document.createElement('canvas');
                    cv.width = img.naturalHeight; cv.height = img.naturalWidth;
                    const cx = cv.getContext('2d');
                    cx.translate(cv.width, 0);
                    cx.rotate(Math.PI / 2);
                    cx.drawImage(img, 0, 0);
                    const turned = cv.toDataURL('image/png');
                    await new Promise(function (resolve) { img.onload = resolve; img.onerror = resolve; img.src = turned; });
                } catch (e) { /* keep the image as it is */ }
            }

            let url = null;
            try {
                const canvas = await captureCard(holder.querySelector('.id-card'), scale);
                url = canvas.toDataURL('image/png');
            } finally {
                holder.remove();
            }
            return url;
        }

        /* =====================================================================
           SINGLE SOURCE OF TRUTH FOR THE ID CARD DESIGN
           ---------------------------------------------------------------------
           This is the print_id.php card markup, field for field. If you change
           the card in print_id.php, change it here too (and mirror the CSS in
           the #printArea block above) so single and bulk output stay identical.

           s        = student record (all columns except the photo BLOB,
                      plus campus_name)
           photoSrc = data:image/... URI taken from the row thumbnail
        ===================================================================== */
        /**
         * Folder name for the ZIP: the first letter of the student's FIRST name,
         * uppercased. Names that do not start with A-Z (blank, or a non-Latin
         * script) are grouped under '#'.
         */
        function firstLetterFolder(fullName) {
            const s = (fullName || '').trim();
            if (!s) return '#';
            const c = s.charAt(0).toUpperCase();
            return /[A-Z]/.test(c) ? c : '#';
        }

        function renderIdCardHTML(s, photoSrc) {
            const photo = photoSrc
                ? '<img src="' + photoSrc + '">'
                : '<img src="assets/img/default.png" style="opacity:0.3">';

            return '' +
            '<div class="id-card" data-sid="' + esc(s.student_id) + '" data-letter="' + firstLetterFolder(s.full_name) + '">' +
                '<div class="header">' +
                    '<img src="assets/img/osu-lockup-trilingual-white.svg" alt="Oromia State University" class="header-logo">' +
                    '<div class="logo"><span class="card-kind">Student Identification Card</span></div>' +
                '</div>' +
                '<div class="content">' +
                    '<div class="photo-box">' + photo + '</div>' +
                    '<div class="name">' + esc(s.full_name) + '</div>' +
                '</div>' +
                '<div class="card-bottom">' +
                    '<div class="details">' +
                        '<div class="info"><strong>ID No:</strong> ' + esc(s.student_id) + '</div>' +
                        '<div class="info"><strong>Sex:</strong> ' + esc(s.gender) + '</div>' +
                        '<div class="info"><strong>Department:</strong> ' + esc(s.department) + '</div>' +
                        '<div class="info"><strong>Program:</strong> ' + esc(s.program) + '</div>' +
                        '<div class="info"><strong>Entry Year:</strong> ' + esc(s.entry_year) + '</div>' +
                        '<div class="info"><strong>Campus:</strong> ' + esc(s.campus_name || 'Main Campus') + '</div>' +
                    '</div>' +
                    '<div class="qr" data-code="' + esc(s.student_id) + '"></div>' +
                '</div>' +
            '</div>';
        }

        /**
         * Draws the QR code on every card in #printArea. Each one encodes the
         * ID number only, so scanners type exactly what the old barcode did.
         * Drawn at 4x the displayed size so prints and exports stay sharp.
         */
        function drawCardQrCodes() {
            document.querySelectorAll('#printArea .qr[data-code]').forEach(function (el) {
                if (el.firstChild) return;
                try {
                    new QRCode(el, { text: el.dataset.code, width: 264, height: 264, correctLevel: QRCode.CorrectLevel.M });
                    el.removeAttribute('title');
                } catch (e) { console.warn('QR code failed for', el.dataset.code, e); }
            });
        }

        /**
         * Builds the cards for the given rows inside #printArea.
         * Photo data is reused from the thumbnail already on the row,
         * everything else comes from the row's data-student attribute.
         */
        function buildCardSheet(rows) {
            const area = document.getElementById('printArea');
            let html = '<div class="sheet">';

            rows.forEach(function (tr) {
                let s = {};
                try { s = JSON.parse(tr.dataset.student || '{}'); } catch (e) { s = {}; }

                const img   = tr.querySelector('img.stu-photo');
                const photo = img ? img.getAttribute('src') : '';

                html += renderIdCardHTML(s, photo);
            });

            html += '</div>';
            area.innerHTML = html;
            drawCardQrCodes();

            return area.querySelectorAll('.id-card').length;
        }

        /* ---------- Capture settings ----------
           PNG_SCALE 2 and backgroundColor null are exactly what
           print_id.php's downloadID() uses, so a card downloaded here is
           pixel-identical to one downloaded from the individual page.
           PDF_SCALE is higher only because a PDF is usually sent to a
           printer rather than to the card bureau; drop it to 2 if you
           want the two outputs byte-for-byte identical.
        -------------------------------------- */
        const PNG_SCALE = 2;
        const PDF_SCALE = 3;

        /* =====================================================================
           AUTOMATIC PHOTO BACKGROUND REMOVAL
           ---------------------------------------------------------------------
           Runs entirely in the browser — no model download, works offline on
           the LAN. It samples the four corners of the photo (the studio
           backdrop) and flood-fills from the borders inward, turning every
           background-coloured pixel connected to an edge transparent. Because
           it only removes colour that is *connected to the border*, a white
           shirt or light skin in the middle of the photo is preserved.

           Tuned for the usual plain-backdrop ID photo. A busy/cluttered
           background will only be partly removed — that's why the toggle
           exists.
        ===================================================================== */
        const BG_PREF_KEY   = 'osu_id_bg_removal';
        const BG_CORNER_TOL = 70;   // a border pixel is backdrop if within this of a corner colour
        const BG_LOCAL_TOL  = 22;   // grow into a neighbour within this of the current pixel (follows gradients, not anti-alias bridges)
        const BG_GLOBAL_TOL = 88;   // a pixel can never be backdrop if it is further than this from every corner (protects skin/subject)
        const BG_FEATHER    = 26;   // soften the last edge pixels within this distance

        function bgRemovalEnabled() {
            const el = document.getElementById('bgRemoveToggle');
            return el ? el.checked : true;
        }
        function saveBgPref() {
            try { localStorage.setItem(BG_PREF_KEY, bgRemovalEnabled() ? '1' : '0'); } catch (e) {}
        }
        (function initBgPref() {
            let v = '1'; // default ON — background removal is automatic
            try { const s = localStorage.getItem(BG_PREF_KEY); if (s !== null) v = s; } catch (e) {}
            const el = document.getElementById('bgRemoveToggle');
            if (el) el.checked = (v !== '0');
        })();

        function removeImageBackground(src) {
            return new Promise(function (resolve) {
                const img = new Image();
                img.onload = function () {
                    const w = img.naturalWidth, h = img.naturalHeight;
                    if (!w || !h) { resolve(src); return; }

                    const cv = document.createElement('canvas');
                    cv.width = w; cv.height = h;
                    const ctx = cv.getContext('2d');
                    ctx.drawImage(img, 0, 0);

                    let imageData;
                    try { imageData = ctx.getImageData(0, 0, w, h); }
                    catch (e) { resolve(src); return; }   // tainted — leave as-is
                    const d = imageData.data;

                    // Reference backdrop colours: the four corners
                    const refs = [];
                    [[0, 0], [w - 1, 0], [0, h - 1], [w - 1, h - 1]].forEach(function (c) {
                        const i = (c[1] * w + c[0]) * 4;
                        refs.push([d[i], d[i + 1], d[i + 2]]);
                    });
                    function cornerDist(i) {
                        let best = Infinity;
                        for (let k = 0; k < refs.length; k++) {
                            const dr = d[i] - refs[k][0], dg = d[i + 1] - refs[k][1], db = d[i + 2] - refs[k][2];
                            const dist = dr * dr + dg * dg + db * db;
                            if (dist < best) best = dist;
                        }
                        return Math.sqrt(best);
                    }
                    function pixDist(i, j) {
                        const dr = d[i] - d[j], dg = d[i + 1] - d[j + 1], db = d[i + 2] - d[j + 2];
                        return Math.sqrt(dr * dr + dg * dg + db * db);
                    }

                    // Region-grow from the border. Seeds are border pixels close to a
                    // corner colour; growth follows small pixel-to-pixel steps, so an
                    // unevenly lit / gradient backdrop is followed all the way in, while
                    // the sharp jump at the subject's outline stops it.
                    const state = new Uint8Array(w * h);   // 0 unseen, 1 queued/bg
                    const stack = [];
                    function seed(x, y) {
                        const p = y * w + x;
                        if (state[p]) return;
                        if (cornerDist(p * 4) <= BG_CORNER_TOL) { state[p] = 1; stack.push(p); }
                    }
                    for (let x = 0; x < w; x++) { seed(x, 0); seed(x, h - 1); }
                    for (let y = 0; y < h; y++) { seed(0, y); seed(w - 1, y); }

                    while (stack.length) {
                        const p = stack.pop();
                        const pi = p * 4;
                        const x = p % w, y = (p / w) | 0;
                        const nb = [];
                        if (x > 0)     nb.push(p - 1);
                        if (x < w - 1) nb.push(p + 1);
                        if (y > 0)     nb.push(p - w);
                        if (y < h - 1) nb.push(p + w);
                        for (let n = 0; n < nb.length; n++) {
                            const q = nb[n];
                            if (state[q]) continue;
                            // grow only along small local steps AND never beyond the
                            // global cap from a corner colour (keeps skin/subject safe
                            // even when an anti-aliased edge bridges toward it)
                            if (pixDist(pi, q * 4) <= BG_LOCAL_TOL && cornerDist(q * 4) <= BG_GLOBAL_TOL) {
                                state[q] = 1; stack.push(q);
                            }
                        }
                    }

                    // Apply: background -> transparent. Then feather the fringe: any
                    // surviving pixel touching a hole gets partial alpha by how close
                    // it still is to the backdrop, killing the hard halo.
                    for (let p = 0; p < w * h; p++) if (state[p]) d[p * 4 + 3] = 0;

                    for (let y = 0; y < h; y++) {
                        for (let x = 0; x < w; x++) {
                            const p = y * w + x, i = p * 4;
                            if (d[i + 3] === 0) continue;
                            const touches =
                                (x > 0     && d[(p - 1) * 4 + 3] === 0) ||
                                (x < w - 1 && d[(p + 1) * 4 + 3] === 0) ||
                                (y > 0     && d[(p - w) * 4 + 3] === 0) ||
                                (y < h - 1 && d[(p + w) * 4 + 3] === 0);
                            if (!touches) continue;
                            const dist = cornerDist(i);
                            if (dist < BG_FEATHER) {
                                d[i + 3] = Math.max(0, Math.min(255, Math.round((dist / BG_FEATHER) * 255)));
                            }
                        }
                    }

                    ctx.putImageData(imageData, 0, 0);
                    resolve(cv.toDataURL('image/png'));
                };
                img.onerror = function () { resolve(src); };
                img.src = src;
            });
        }

        /**
         * Replaces every card photo in #printArea with a background-removed
         * version, in place, before capture. No-op if the toggle is off.
         * onProgress(done, total) is optional.
         */
        async function processCardPhotos(onProgress) {
            if (!bgRemovalEnabled()) return;

            const imgs = Array.prototype.slice.call(
                document.querySelectorAll('#printArea .id-card .photo-box img')
            ).filter(function (im) {
                const s = im.getAttribute('src') || '';
                return s && s.indexOf('assets/img/default') === -1; // skip the placeholder
            });

            for (let i = 0; i < imgs.length; i++) {
                if (onProgress) onProgress(i + 1, imgs.length);
                const im = imgs[i];
                const out = await removeImageBackground(im.getAttribute('src'));
                await new Promise(function (resolve) {
                    im.onload = resolve; im.onerror = resolve; im.src = out;
                });
            }
        }

        async function captureCard(el, scale) {
            // The Amharic line (Noto Sans Ethiopic) is a webfont. html2canvas draws
            // whatever is available at capture time, so a font that has not finished
            // loading leaves empty boxes where the Amharic should be. Force it in,
            // then wait for the whole set to settle, before drawing.
            if (document.fonts && document.fonts.load) {
                try {
                    await Promise.all([
                        document.fonts.load("10px 'Noto Sans Ethiopic'"),
                        document.fonts.load("bold 10px 'Noto Sans Ethiopic'")
                    ]);
                    await document.fonts.ready;
                } catch (e) { /* fall through and capture anyway */ }
            }
            return html2canvas(el, {
                scale: scale,
                useCORS: true,
                backgroundColor: null,
                logging: false
            });
        }

        /* ---------- Print the selected ID cards ---------- */
        async function bulkPrintIds() {
            const rows = getPrintableSelection();
            if (!rows) return;

            buildCardSheet(rows);
            await processCardPhotos();
            document.body.classList.add('printing-cards');

            // give QR codes and photos a moment to paint
            setTimeout(function () { window.print(); }, 350);
        }

        // Restore the admin UI once the print dialog closes
        window.addEventListener('afterprint', function () {
            document.body.classList.remove('printing-cards');
        });

        /* ---------- Download the selected ID cards as PNG ----------
           Identical to print_id.php's "Download PNG": html2canvas at
           scale 2, transparent background, named <student_id>_id_card.png.
           One card downloads directly; several are bundled into one ZIP.
        ---------------------------------------------------------- */
        async function bulkDownloadPng() {
            const rows = getPrintableSelection();
            if (!rows) return;

            const count = buildCardSheet(rows);
            if (!count) return;

            const btn = document.getElementById('bulkPngBtn');
            const originalHtml = btn.innerHTML;
            btn.disabled = true;

            try {
                await processCardPhotos(function (done, total) {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> photo ' + done + '/' + total;
                });

                const cards   = Array.prototype.slice.call(document.querySelectorAll('#printArea .id-card'));
                const hasBack = !!backPageSrc;

                // Capture the shared back ONCE and reuse its bytes for everyone
                let backB64 = null;
                if (hasBack) {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> back...';
                    const backUrl = await captureBackPng(PNG_SCALE);
                    if (backUrl) backB64 = backUrl.split(',')[1];
                }

                // Single front, no back -> one file, exactly like the individual page
                if (cards.length === 1 && !hasBack) {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 1/1';
                    const canvas = await captureCard(cards[0], PNG_SCALE);
                    const link = document.createElement('a');
                    link.download = (cards[0].dataset.sid || 'id') + '_id_card.png';
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                    return;
                }

                // Otherwise bundle into one ZIP: front (+ back) per student
                const zip = new JSZip();

                for (let i = 0; i < cards.length; i++) {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (i + 1) + '/' + cards.length;

                    const canvas   = await captureCard(cards[i], PNG_SCALE);
                    const frontB64 = canvas.toDataURL('image/png').split(',')[1];
                    const sid      = (cards[i].dataset.sid || ('card-' + (i + 1))).replace(/[^\w.-]/g, '_');

                    // Group into a folder named by the first letter of the first name
                    const folder = (cards[i].dataset.letter || '#') + '/';

                    if (hasBack && backB64) {
                        zip.file(folder + sid + '_front.png', frontB64, { base64: true });
                        zip.file(folder + sid + '_back.png',  backB64,  { base64: true });
                    } else {
                        zip.file(folder + sid + '_id_card.png', frontB64, { base64: true });
                    }
                }

                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> zipping...';
                const blob = await zip.generateAsync({ type: 'blob' });

                const url  = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.download = 'osu-id-cards-' + new Date().toISOString().slice(0, 10) + '.zip';
                link.href = url;
                link.click();
                setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
            } catch (e) {
                console.error(e);
                alert('Could not build the PNG files: ' + e.message);
            } finally {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        }

        async function bulkDownloadIds() {
            const rows = getPrintableSelection();
            if (!rows) return;

            const count = buildCardSheet(rows);
            if (!count) return;

            const btn = document.getElementById('bulkPdfBtn');
            const originalHtml = btn.innerHTML;
            btn.disabled = true;

            try {
                await processCardPhotos(function (done, total) {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> photo ' + done + '/' + total;
                });

                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });

                // The card is 220 x 350 px (portrait). Printing it 54mm wide keeps that
                // exact aspect ratio (54 * 350 / 220 = 85.9mm) so nothing is
                // stretched. That is within a millimetre of a CR80 blank.
                const CARD_W = 54;
                const CARD_H = 54 * 350 / 220;

                const COLS = 3, ROWS = 3, PER_PAGE = COLS * ROWS;
                const MARGIN_X = 12, MARGIN_Y = 15;
                const GAP_X = (210 - (2 * MARGIN_X) - (COLS * CARD_W)) / (COLS - 1);
                const GAP_Y = (297 - (2 * MARGIN_Y) - (ROWS * CARD_H)) / (ROWS - 1);

                const cards = Array.prototype.slice.call(document.querySelectorAll('#printArea .id-card'));

                for (let i = 0; i < cards.length; i++) {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (i + 1) + '/' + cards.length;

                    const canvas  = await captureCard(cards[i], PDF_SCALE);
                    const imgData = canvas.toDataURL('image/png');

                    const slot = i % PER_PAGE;
                    if (i > 0 && slot === 0) pdf.addPage();

                    const col = slot % COLS;
                    const row = Math.floor(slot / COLS);
                    pdf.addImage(imgData, 'PNG',
                        MARGIN_X + col * (CARD_W + GAP_X),
                        MARGIN_Y + row * (CARD_H + GAP_Y),
                        CARD_W, CARD_H);
                }

                pdf.save('osu-id-cards-' + new Date().toISOString().slice(0, 10) + '.pdf');
            } catch (e) {
                console.error(e);
                alert('Could not build the PDF: ' + e.message);
            } finally {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
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
