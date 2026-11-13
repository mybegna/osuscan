<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
require_once 'includes/lang_init.php';
checkAuth();

if (!in_array($_SESSION['role'], ['Super Admin', 'Campus Admin'])) {
    die("<div style='padding: 20px; color: #6B4E3D;'><h2>Access Denied</h2></div>");
}

$campus_id = $_SESSION['campus_id'] ?? null;
// Fetch Campuses for Super Admin
$campuses = [];
if ($_SESSION['role'] === 'Super Admin') {
    $campuses = $pdo->query("SELECT * FROM campuses ORDER BY campus_name ASC")->fetchAll();
}

$message = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add User
    if (isset($_POST['action']) && $_POST['action'] === 'add_user') {
        try {
            $username = trim($_POST['username']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $fullName = trim($_POST['full_name']);
            $roleId = intval($_POST['role_id']);
            
            $newValCampus = null;
            if ($_SESSION['role'] === 'Super Admin') {
                $newValCampus = !empty($_POST['campus_id']) ? $_POST['campus_id'] : null;
            } else {
                $newValCampus = $campus_id;
            }

            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, campus_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$username, $password, $fullName, $roleId, $newValCampus]);
            $newUserId = $pdo->lastInsertId();

            if (isset($_POST['departments']) && is_array($_POST['departments'])) {
                $stmtIns = $pdo->prepare("INSERT INTO user_departments (user_id, department_id) VALUES (?, ?)");
                foreach ($_POST['departments'] as $did) {
                    $stmtIns->execute([$newUserId, $did]);
                }
            }
            $message = "User added successfully!";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }
    // Update User (Super Admin editing another user)
    elseif (isset($_POST['action']) && $_POST['action'] === 'edit_user') {
        try {
            $editId = $_POST['edit_user_id'];
            $editUsername = trim($_POST['edit_username']);
            $editFullName = trim($_POST['edit_full_name']);
            $editPassword = $_POST['edit_password'];
            $editRole = $_POST['edit_role_id'];

            $editCampusVal = null; 
            // Only Super Admin can change campus, or if we assume editing user keeps their campus unless changed. 
            // Simpler: If Super Admin, allow update campus. If Campus Admin, force own campus (or keep existing).
            // For now, let's assume we don't change campus on edit for simplicity unless Super Admin.
            
            $sql = "UPDATE users SET username = ?, full_name = ?, role_id = ?";
            $params = [$editUsername, $editFullName, $editRole];
            
            if (!empty($editPassword)) {
                $sql .= ", password_hash = ?";
                $params[] = password_hash($editPassword, PASSWORD_DEFAULT);
            }
            
            if ($_SESSION['role'] === 'Super Admin' && isset($_POST['edit_campus_id'])) {
                 $sql .= ", campus_id = ?";
                 $params[] = !empty($_POST['edit_campus_id']) ? $_POST['edit_campus_id'] : null;
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $editId;
            
            // Campus Admin security check
            if ($campus_id) {
                 $sql .= " AND campus_id = ?";
                 $params[] = $campus_id;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $stmtDel = $pdo->prepare("DELETE FROM user_departments WHERE user_id = ?");
            $stmtDel->execute([$editId]);

            if (isset($_POST['edit_departments']) && is_array($_POST['edit_departments'])) {
                $stmtIns = $pdo->prepare("INSERT INTO user_departments (user_id, department_id) VALUES (?, ?)");
                foreach ($_POST['edit_departments'] as $did) {
                    $stmtIns->execute([$editId, $did]);
                }
            }
            $message = "User details updated successfully.";
        } catch (Exception $e) {
            $message = "Error updating user: " . $e->getMessage();
        }
    }
}

// GET Actions (Block/Unblock/Delete)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $userId = intval($_GET['id']);
    // Prevent self-action
    if ($userId == $_SESSION['user_id']) {
        $message = "Error: You cannot perform actions on your own account.";
    } else {
        if ($_GET['action'] === 'block') {
            $stmt = $pdo->prepare("UPDATE users SET is_blocked = 1 WHERE id = ?");
            $stmt->execute([$userId]);
            $message = "User blocked successfully.";
        } elseif ($_GET['action'] === 'unblock') {
            $stmt = $pdo->prepare("UPDATE users SET is_blocked = 0 WHERE id = ?");
            $stmt->execute([$userId]);
            $message = "User unblocked successfully.";
        } elseif ($_GET['action'] === 'delete') {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $message = "User deleted completely.";
            } catch (Exception $e) {
                $message = "Error deleting user: " . $e->getMessage();
            }
        }
    }
}

$users = $pdo->query("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id LIMIT 50")->fetchAll();

$search = $_GET['search'] ?? '';
$query = "SELECT u.*, r.role_name, (SELECT GROUP_CONCAT(department_id) FROM user_departments WHERE user_id = u.id) as assigned_dept_ids, c.campus_name 
            FROM users u 
            LEFT JOIN roles r ON u.role_id = r.id 
            LEFT JOIN campuses c ON u.campus_id = c.id
            WHERE 1=1";
$params = [];

if ($campus_id) {
    $query .= " AND u.campus_id = ?";
    $params[] = $campus_id;
} elseif ($_SESSION['role'] === 'Super Admin' && !empty($_GET['filter_campus'])) {
    $query .= " AND u.campus_id = ?";
    $params[] = $_GET['filter_campus'];
}

if (!empty($_GET['filter_role'])) {
    $query .= " AND u.role_id = ?";
    $params[] = $_GET['filter_role'];
}

if (!empty($search)) {
    $query .= " AND (u.username LIKE ? OR u.full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$query .= " LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

$roleQuery = "SELECT * FROM roles";
if ($_SESSION['role'] !== 'Super Admin') {
    $roleQuery .= " WHERE role_name != 'Super Admin'";
}
$roleQuery .= " ORDER BY role_name ASC";
$roles = $pdo->query($roleQuery)->fetchAll();

// Departments also need filtering if we enforce that users interact with depts of their campus
$deptQuery = "SELECT * FROM departments";
if ($campus_id) {
    $deptQuery .= " WHERE campus_id = '$campus_id'";
}
$deptQuery .= " ORDER BY name ASC";
$departments = $pdo->query($deptQuery)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['users']; ?> - OSU One Card</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(12, 33, 26, 0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: var(--card-bg); padding: 20px; border-radius: 8px; width: 90%; max-width: 500px; position: relative; }
        .close-modal { position: absolute; right: 15px; top: 15px; font-size: 20px; cursor: pointer; }
        .show-modal { display: flex; }
        
        @media print {
            body * {
                visibility: hidden;
            }
            #printModal, #printModal * {
                visibility: visible;
            }
            #printModal {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background: white;
                display: block !important;
            }
            .close-modal, .btn {
                display: none !important;
            }
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
                    <i class="fas fa-user-shield"></i> <?php echo $lang['user_management']; ?>
                </div>
            </div>
            <div class="navbar-actions">
                <button class="btn btn-primary" onclick="openModal('addUserModal')">
                    <i class="fas fa-user-plus"></i> <?php echo $lang['add_new_user']; ?>
                </button>
                <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                <a href="campuses" class="btn btn-primary">
                    <i class="fas fa-university"></i> Manage Campuses
                </a>
                <?php endif; ?>
                
                <!-- Language Switcher -->
                <div style="display: flex; margin-right: 10px;">
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

            <!-- Add User Modal -->
            <div id="addUserModal" class="modal">
                <div class="modal-content" style="background-color: var(--card-bg);"> <!-- White background as requested -->
                    <span class="close-modal" onclick="closeModal('addUserModal')">&times;</span>
                    <div class="card-header" style="border:none; margin-bottom:15px;">
                        <i class="fas fa-user-plus"></i> <?php echo $lang['add_new_user']; ?>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_user">
                        <div style="display: flex; flex-direction: column; gap: 15px;">
                            <input type="text" name="username" placeholder="<?php echo $lang['username']; ?>" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <div style="display: flex; gap: 10px;">
                                <input type="password" name="password" id="add_password" placeholder="<?php echo $lang['password']; ?>" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <button type="button" class="btn btn-secondary" onclick="generatePassword('add_password')" title="Generate Strong Password"><i class="fas fa-key"></i></button>
                            </div>
                            <input type="text" name="full_name" placeholder="<?php echo $lang['full_name']; ?>" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            
                            <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                            <select name="campus_id" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                <option value="">Select Campus (Global if empty)</option>
                                <?php foreach ($campuses as $camp): ?>
                                    <option value="<?php echo $camp['id']; ?>"><?php echo htmlspecialchars($camp['campus_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>

                            <select name="role_id" id="add_role_id" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" onchange="toggleDept('add_role_id', 'add_dept_section')">
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role_item): ?>
                                    <option value="<?php echo $role_item['id']; ?>"><?php echo htmlspecialchars($role_item['role_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="add_dept_section" style="display:none;">
                                <label style="font-size: 12px; color: var(--secondary-color);"><?php echo $lang['assigned_departments']; ?></label>
                                <select name="departments[]" multiple style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; height: 100px;">
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color: var(--secondary-color);"><?php echo $lang['hold_ctrl']; ?></small>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="margin-top: 15px; width: 100%;">
                            <i class="fas fa-save"></i> <?php echo $lang['save']; ?> User
                        </button>
                    </form>
                </div>
            </div>

            <!-- Edit User Modal -->
            <div id="editUserModal" class="modal">
                <div class="modal-content" style="background-color: var(--card-bg);">
                    <span class="close-modal" onclick="closeModal('editUserModal')">&times;</span>
                    <div class="card-header" style="border:none; margin-bottom:15px;">
                        <i class="fas fa-user-edit"></i> <?php echo $lang['edit']; ?> User
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="edit_user_id" id="edit_user_id">
                        <div style="display: flex; flex-direction: column; gap: 15px;">
                            <div>
                                <label style="font-size: 12px; color: var(--secondary-color);"><?php echo $lang['username']; ?></label>
                                <input type="text" name="edit_username" id="edit_username" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            </div>
                            <div>
                                <label style="font-size: 12px; color: var(--secondary-color);"><?php echo $lang['full_name']; ?></label>
                                <input type="text" name="edit_full_name" id="edit_full_name" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            </div>
                            <div>
                                <label style="font-size: 12px; color: var(--secondary-color);">New Password (leave blank to keep current)</label>
                                <div style="display: flex; gap: 10px;">
                                    <input type="password" name="edit_password" id="edit_password" placeholder="New Password" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                    <button type="button" class="btn btn-secondary" onclick="generatePassword('edit_password')" title="Generate Strong Password"><i class="fas fa-key"></i></button>
                                </div>
                            </div>
                            <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                            <div>
                                <label style="font-size: 12px; color: var(--secondary-color);">Campus</label>
                                <select name="edit_campus_id" id="edit_campus_id" style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                                    <option value="">Global / All</option>
                                    <?php foreach ($campuses as $camp): ?>
                                        <option value="<?php echo $camp['id']; ?>"><?php echo htmlspecialchars($camp['campus_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div>
                                <label style="font-size: 12px; color: var(--secondary-color);"><?php echo $lang['role']; ?></label>
                                <select name="edit_role_id" id="edit_role_id" required style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;" onchange="toggleDept('edit_role_id', 'edit_dept_section')">
                                    <?php foreach ($roles as $role_item): ?>
                                        <option value="<?php echo $role_item['id']; ?>"><?php echo htmlspecialchars($role_item['role_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="edit_dept_section" style="display:none; margin-top: 10px;">
                                    <label style="font-size: 12px; color: var(--secondary-color);"><?php echo $lang['assigned_departments']; ?></label>
                                    <select name="edit_departments[]" id="edit_departments" multiple style="width:100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; height: 100px;">
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small style="color: var(--secondary-color);"><?php echo $lang['hold_ctrl']; ?></small>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="margin-top: 15px; width: 100%;">
                            <i class="fas fa-save"></i> <?php echo $lang['update']; ?> User
                        </button>
                    </form>
                </div>
            </div>

            <!-- Search Users -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-search"></i> Search Users
                </div>
                <form method="GET" style="padding: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <input type="text" name="search" placeholder="Search by name or username" value="<?php echo htmlspecialchars($search); ?>" style="flex: 1; min-width: 200px; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                    
                    <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                    <select name="filter_campus" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                        <option value="">All Campuses</option>
                        <?php foreach ($campuses as $camp): ?>
                            <option value="<?php echo $camp['id']; ?>" <?php echo (isset($_GET['filter_campus']) && $_GET['filter_campus'] == $camp['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($camp['campus_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <select name="filter_role" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                        <option value="">All Roles</option>
                        <?php foreach ($roles as $rItem): ?>
                            <option value="<?php echo $rItem['id']; ?>" <?php echo (isset($_GET['filter_role']) && $_GET['filter_role'] == $rItem['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($rItem['role_name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                    </button>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list"></i> <?php echo $lang['system_users']; ?>
                </div>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: var(--hover-bg-light);">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);"><?php echo $lang['username']; ?></th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);"><?php echo $lang['full_name']; ?></th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);"><?php echo $lang['role']; ?></th>
                                <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                                    <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);">Campus</th>
                                <?php endif; ?>
                                <th style="padding: 12px; text-align: right; border-bottom: 2px solid var(--border-color);"><?php echo $lang['actions']; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 12px;">
                                    <?php echo htmlspecialchars($u['username']); ?>
                                    <?php if ($u['is_blocked']): ?>
                                        <span style="font-size: 10px; background: var(--danger-fill); color: white; padding: 2px 5px; border-radius: 4px; margin-left: 5px;">BLOCKED</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($u['full_name']); ?></td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($u['role_name'] ?? 'N/A'); ?></td>
                                <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                                    <td style="padding: 12px;"><?php echo htmlspecialchars($u['campus_name'] ?? 'All/Global'); ?></td>
                                <?php endif; ?>
                                <td style="padding: 12px; text-align: right; white-space: nowrap;">
                                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                        <!-- Print Button -->
                                        <button onclick="printUser('<?php echo htmlspecialchars(addslashes($u['username'])); ?>', '<?php echo htmlspecialchars(addslashes($u['full_name'])); ?>', '<?php echo htmlspecialchars(addslashes($u['role_name'])); ?>', '<?php echo htmlspecialchars(addslashes($u['campus_name'] ?? 'Global')); ?>')" class="btn btn-sm" style="background: var(--neutral-fill); color: white; margin-right: 5px; border:none; cursor:pointer;" title="Print Credentials">
                                            <i class="fas fa-print"></i>
                                        </button>

                                        <!-- Edit Button -->
                                        <button onclick="editUser('<?php echo $u['id']; ?>', '<?php echo htmlspecialchars(addslashes($u['username'])); ?>', '<?php echo htmlspecialchars(addslashes($u['full_name'])); ?>', '<?php echo $u['role_id']; ?>', '<?php echo $u['assigned_dept_ids'] ?? ''; ?>', '<?php echo $u['campus_id'] ?? ''; ?>')" class="btn btn-sm" style="background: var(--primary-fill); color: white; margin-right: 5px; border:none; cursor:pointer;" title="<?php echo $lang['edit']; ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <?php if ($u['is_blocked']): ?>
                                            <a href="users?action=unblock&id=<?php echo $u['id']; ?>" class="btn btn-sm" style="background: var(--success-fill); color: white; margin-right: 5px;" title="<?php echo $lang['unblock']; ?>">
                                                <i class="fas fa-unlock"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="users?action=block&id=<?php echo $u['id']; ?>" class="btn btn-sm" style="background: var(--warning-fill); color: var(--osu-ink); margin-right: 5px;" title="<?php echo $lang['block']; ?>">
                                                <i class="fas fa-ban"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="users?action=delete&id=<?php echo $u['id']; ?>" class="btn btn-sm" style="background: var(--danger-fill); color: white;" title="<?php echo $lang['delete']; ?>" onclick="return confirm('Are you sure you want to delete this user completely?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Print Modal -->
    <div id="printModal" class="modal">
        <div class="modal-content" style="background-color: var(--card-bg); width: 400px; text-align: center;">
            <span class="close-modal" onclick="closeModal('printModal')">&times;</span>
            <h3> User Credentials</h3>
            <div id="printArea" style="padding: 20px; border: 1px dashed var(--border-color); margin: 10px 0; text-align: left;">
                <p><strong>Username:</strong> <span id="p_username"></span></p>
                <p><strong>Full Name:</strong> <span id="p_fullname"></span></p>
                <p><strong>Role:</strong> <span id="p_role"></span></p>
                <p><strong>Campus:</strong> <span id="p_campus"></span></p>
                <p><strong>Password:</strong> ******** (Hidden)</p>
                <small style="color: var(--danger-color); font-size: 10px;">* Password cannot be retrieved. Reset to change.</small>
            </div>
            <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Print</button>
        </div>
    </div>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('show-modal');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('show-modal');
        }
        function editUser(id, username, fullName, roleId, deptIds, campusId) {
            document.getElementById('edit_user_id').value = id;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_full_name').value = fullName;
            document.getElementById('edit_role_id').value = roleId;
            
            if (document.getElementById('edit_campus_id')) {
                document.getElementById('edit_campus_id').value = campusId;
            }
            
            // Handle departments
            const deptSelect = document.getElementById('edit_departments');
            for (let i = 0; i < deptSelect.options.length; i++) {
                deptSelect.options[i].selected = false;
            }
            if (deptIds) {
                const ids = deptIds.toString().split(','); // toString safely
                for (let i = 0; i < deptSelect.options.length; i++) {
                    if (ids.includes(deptSelect.options[i].value)) {
                        deptSelect.options[i].selected = true;
                    }
                }
            }
            
            toggleDept('edit_role_id', 'edit_dept_section');
            openModal('editUserModal');
        }
        
        function toggleDept(roleSelectId, sectionId) {
            const roleSelect = document.getElementById(roleSelectId);
            const section = document.getElementById(sectionId);
            const selectedText = roleSelect.options[roleSelect.selectedIndex].text;
            // College Registrar ID is 8, assume Name 'College Registrar'
            if (selectedText === 'College Registrar') {
                section.style.display = 'block';
            } else {
                section.style.display = 'none';
            }
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
    <script>
        function generatePassword(targetId) {
            const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
            let pass = "";
            for (let i = 0; i < 14; i++) {
                pass += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            const input = document.getElementById(targetId);
            input.type = "text"; 
            input.value = pass;
            //alert("Generated Password: " + pass); // Removed alert, just show in text field
        }

        function printUser(username, fullname, role, campus) {
             document.getElementById('p_username').textContent = username;
             document.getElementById('p_fullname').textContent = fullname;
             document.getElementById('p_role').textContent = role;
             document.getElementById('p_campus').textContent = campus;
             openModal('printModal');
        }
    </script>
</body>
</html>