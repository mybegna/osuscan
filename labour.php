<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
require_once 'includes/lang_init.php';
checkAuth();

if (!in_array($_SESSION['role'], ['Super Admin', 'Campus Admin'])) {
    die("<div style='padding: 20px; color: #6B4E3D;'><h2>Access Denied</h2></div>");
}

$campus_id = $_SESSION['campus_id'] ?? null;
$message = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_labour' || $_POST['action'] === 'edit_labour') {
        try {
            $labour_id = trim($_POST['labour_id']);
            $full_name = trim($_POST['full_name']);
            $gender = trim($_POST['gender']);
            $phone_number = trim($_POST['phone_number']);
            $work_type = trim($_POST['work_type']);
            $guarantee = trim($_POST['guarantee'] ?? '');
            $start_date = trim($_POST['start_date']);
            $expiry_date = trim($_POST['expiry_date']);
            $address = trim($_POST['address']);
            $status = trim($_POST['status']);
            $target_campus = ($_SESSION['role'] === 'Super Admin') ? $_POST['campus_id'] : $campus_id;

            $photoData = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
                if ($_FILES['photo']['size'] > 51200) {
                    throw new Exception("Photo exceeds 50kb limit.");
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['photo']['tmp_name']);
                if (strpos($mime, 'image/') !== 0) {
                     throw new Exception("File must be an image.");
                }
                $photoData = file_get_contents($_FILES['photo']['tmp_name']);
            }

            if ($_POST['action'] === 'add_labour') {
                $sql = "INSERT INTO labour_personnel (labour_id, full_name, gender, phone_number, work_type, guarantee, start_date, expiry_date, address, campus_id, status";
                $params = [$labour_id, $full_name, $gender, $phone_number, $work_type, $guarantee, $start_date, $expiry_date, $address, $target_campus, $status];
                
                if ($photoData) {
                    $sql .= ", photo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $params[] = $photoData;
                } else {
                    $sql .= ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $message = "Labour personnel added successfully.";
                
                if (isset($_SESSION['user_id'])) {
                    $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
                    $stmtLog->execute([$_SESSION['user_id'], 'Add Labour', "Added labour personnel: $full_name"]);
                }
            } else {
                $original_id = $_POST['original_id'];
                $sql = "UPDATE labour_personnel SET labour_id=?, full_name=?, gender=?, phone_number=?, work_type=?, guarantee=?, start_date=?, expiry_date=?, address=?, status=?";
                $params = [$labour_id, $full_name, $gender, $phone_number, $work_type, $guarantee, $start_date, $expiry_date, $address, $status];

                if ($_SESSION['role'] === 'Super Admin') {
                    $sql .= ", campus_id=?";
                    $params[] = $target_campus;
                }

                if ($photoData) {
                    $sql .= ", photo=?";
                    $params[] = $photoData;
                }
                $sql .= " WHERE id=?";
                $params[] = $original_id;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $message = "Labour personnel updated successfully.";
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'delete_labour') {
        try {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM labour_personnel WHERE id=?");
            $stmt->execute([$id]);
            $message = "Record deleted successfully.";
        } catch (Exception $e) {
            $message = "Error deleting record: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'toggle_status') {
        try {
            $id = $_POST['id'];
            $new_status = ($_POST['current_status'] === 'Active') ? 'Inactive' : 'Active';
            $stmt = $pdo->prepare("UPDATE labour_personnel SET status=? WHERE id=?");
            $stmt->execute([$new_status, $id]);
            $message = "Personnel status updated to $new_status successfully.";
        } catch (Exception $e) {
            $message = "Error updating status: " . $e->getMessage();
        }
    }
}

// Fetch Campuses for Super Admin
$campuses = [];
if ($_SESSION['role'] === 'Super Admin') {
    $campuses = $pdo->query("SELECT * FROM campuses ORDER BY campus_name ASC")->fetchAll();
}

// Fetch Records
$search = $_GET['search'] ?? '';
$sql = "SELECT lp.*, c.campus_name FROM labour_personnel lp LEFT JOIN campuses c ON lp.campus_id = c.id WHERE 1=1";
$params = [];

if ($campus_id) {
    $sql .= " AND lp.campus_id = ?";
    $params[] = $campus_id;
}
if (!empty($search)) {
    $sql .= " AND (lp.full_name LIKE ? OR lp.labour_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY lp.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$personnel = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Labour Personnel Management - OSU One Card</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(12, 33, 26, 0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: var(--card-bg); padding: 20px; border-radius: 8px; width: 90%; max-width: 600px; position: relative; max-height: 90vh; overflow-y: auto; }
        .close-modal { position: absolute; right: 15px; top: 15px; font-size: 20px; cursor: pointer; color: var(--secondary-color); }
        .show-modal { display: flex; }
        .photo-preview { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-color); }
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
                    <i class="fas fa-hard-hat"></i> <?php echo $lang['labour_management'] ?? 'Labour Personnel Management'; ?>
                </div>
            </div>
            <div class="navbar-actions" style="display: flex; align-items: center; gap: 10px;">
                <!-- Language Switcher -->
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

            <div class="card" style="margin-bottom: 20px;">
                <div style="padding: 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <form method="GET" style="display: flex; gap: 10px; flex: 1; min-width: 300px;">
                        <input type="text" name="search" placeholder="Search by Name or ID" value="<?php echo htmlspecialchars($search); ?>" style="flex: 1; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                    </form>
                    <button class="btn btn-primary" onclick="openModal('addModal')">
                        <i class="fas fa-plus"></i> <?php echo $lang['add_new_labour'] ?? 'Add New Personnel'; ?>
                    </button>
                </div>
            </div>

            <div class="card">
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: var(--hover-bg-light); border-bottom: 2px solid var(--border-color);">
                                <th style="padding: 12px; text-align: left;">Photo</th>
                                <th style="padding: 12px; text-align: left;">Personnel ID</th>
                                <th style="padding: 12px; text-align: left;">Name</th>
                                <th style="padding: 12px; text-align: left;">Work Type</th>
                                <th style="padding: 12px; text-align: left;">Guarantee</th>
                                <th style="padding: 12px; text-align: left;">Expiry</th>
                                <th style="padding: 12px; text-align: left;">Status</th>
                                <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                                <th style="padding: 12px; text-align: left;">Campus</th>
                                <?php endif; ?>
                                <th style="padding: 12px; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($personnel as $p): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 12px;">
                                    <?php if ($p['photo']): ?>
                                        <img src="data:image/jpeg;base64,<?php echo base64_encode($p['photo']); ?>" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--avatar-bg); display: flex; align-items: center; justify-content: center;"><i class="fas fa-user"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; font-weight: bold;"><?php echo htmlspecialchars($p['labour_id']); ?></td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($p['full_name']); ?></td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($p['work_type']); ?></td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($p['guarantee'] ?? ''); ?></td>
                                <td style="padding: 12px;">
                                    <?php 
                                        $exp = strtotime($p['expiry_date']);
                                        $is_expired = $exp < time();
                                        echo "<span style='color: " . ($is_expired ? 'var(--danger-color)' : 'var(--success-color)') . "'>" . htmlspecialchars($p['expiry_date']) . "</span>";
                                    ?>
                                </td>
                                <td style="padding: 12px;">
                                    <span style="padding: 3px 8px; border-radius: 12px; font-size: 12px; background: <?php echo $p['status'] === 'Active' ? 'var(--success-bg)' : 'var(--danger-bg)'; ?>; color: <?php echo $p['status'] === 'Active' ? 'var(--success-on)' : 'var(--danger-on)'; ?>;">
                                        <?php echo htmlspecialchars($p['status']); ?>
                                    </span>
                                </td>
                                <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($p['campus_name']); ?></td>
                                <?php endif; ?>
                                <td style="padding: 12px; text-align: right; display: flex; gap: 5px; justify-content: flex-end;">
                                    <?php 
                                        $p_safe = $p;
                                        unset($p_safe['photo']); 
                                    ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="current_status" value="<?php echo $p['status']; ?>">
                                        <button type="submit" class="btn btn-sm" style="background-color: <?php echo $p['status'] === 'Active' ? 'var(--warning-fill)' : 'var(--success-fill)'; ?>; border-color: <?php echo $p['status'] === 'Active' ? 'var(--warning-fill)' : 'var(--success-fill)'; ?>; color: var(--on-fill);" title="<?php echo $p['status'] === 'Active' ? 'Deactivate' : 'Activate'; ?>">
                                            <i class="fas <?php echo $p['status'] === 'Active' ? 'fa-ban' : 'fa-check-circle'; ?>"></i>
                                        </button>
                                    </form>
                                    <a href="print_labour_id?id=<?php echo urlencode($p['labour_id']); ?>" target="_blank" class="btn btn-sm btn-primary" title="Print ID" style="background: var(--primary-fill); border-color: var(--primary-color);">
                                        <i class="fas fa-id-card"></i>
                                    </a>
                                    <button class="btn btn-sm btn-secondary" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($p_safe), ENT_QUOTES, "UTF-8"); ?>)' title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this record?');">
                                        <input type="hidden" name="action" value="delete_labour">
                                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete" style="background: var(--danger-fill);">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($personnel)): ?>
                            <tr>
                                <td colspan="8" style="padding: 20px; text-align: center; color: var(--secondary-color);">No labour personnel found.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content" style="background-color: var(--card-bg); color: var(--text-color);">
            <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
            <div class="card-header" style="border:none; margin-bottom: 15px; padding:0; font-size: 18px;"><i class="fas fa-plus"></i> Add Labour Personnel</div>
            <form method="POST" enctype="multipart/form-data" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <input type="hidden" name="action" value="add_labour">
                
                <div style="grid-column: span 2;">
                    <label>Personnel ID *</label>
                    <input type="text" name="labour_id" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label>Full Name *</label>
                    <input type="text" name="full_name" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label>Gender *</label>
                    <select name="gender" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                <div>
                    <label>Phone Number</label>
                    <input type="text" name="phone_number" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label>Work Type</label>
                    <input type="text" name="work_type" placeholder="e.g. Construction, Maintenance" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label>Guarantee</label>
                    <input type="text" name="guarantee" placeholder="Guarantee person or reference" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label>Start Date</label>
                    <input type="date" name="start_date" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label>Expiry Date *</label>
                    <input type="date" name="expiry_date" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div style="grid-column: span 2;">
                    <label>Address</label>
                    <textarea name="address" rows="2" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;"></textarea>
                </div>
                
                <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                <div style="grid-column: span 2;">
                    <label>Campus *</label>
                    <select name="campus_id" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                        <option value="">Select Campus</option>
                        <?php foreach ($campuses as $camp): ?>
                            <option value="<?php echo $camp['id']; ?>"><?php echo htmlspecialchars($camp['campus_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div>
                    <label>Status *</label>
                    <select name="status" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                <div>
                    <label>Photo (Max 50KB)</label>
                    <input type="file" name="photo" accept="image/*" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>

                <div style="grid-column: span 2; margin-top: 10px;">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Save Personnel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content" style="background-color: var(--card-bg); color: var(--text-color);">
            <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
            <div class="card-header" style="border:none; margin-bottom: 15px; padding:0; font-size: 18px;"><i class="fas fa-edit"></i> Edit Labour Personnel</div>
            <form method="POST" enctype="multipart/form-data" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <input type="hidden" name="action" value="edit_labour">
                <input type="hidden" name="original_id" id="edit_original_id">
                
                <div style="grid-column: span 2;">
                    <label>Personnel ID *</label>
                    <input type="text" name="labour_id" id="edit_labour_id" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label>Full Name *</label>
                    <input type="text" name="full_name" id="edit_full_name" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label>Gender *</label>
                    <select name="gender" id="edit_gender" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                <div>
                    <label>Phone Number</label>
                    <input type="text" name="phone_number" id="edit_phone_number" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label>Work Type</label>
                    <input type="text" name="work_type" id="edit_work_type" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label>Guarantee</label>
                    <input type="text" name="guarantee" id="edit_guarantee" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label>Start Date</label>
                    <input type="date" name="start_date" id="edit_start_date" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div>
                    <label>Expiry Date *</label>
                    <input type="date" name="expiry_date" id="edit_expiry_date" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>
                <div style="grid-column: span 2;">
                    <label>Address</label>
                    <textarea name="address" id="edit_address" rows="2" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;"></textarea>
                </div>

                <?php if ($_SESSION['role'] === 'Super Admin'): ?>
                <div style="grid-column: span 2;">
                    <label>Campus *</label>
                    <select name="campus_id" id="edit_campus_id" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                        <option value="">Select Campus</option>
                        <?php foreach ($campuses as $camp): ?>
                            <option value="<?php echo $camp['id']; ?>"><?php echo htmlspecialchars($camp['campus_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div>
                    <label>Status *</label>
                    <select name="status" id="edit_status" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                <div>
                    <label>Update Photo (Max 50KB)</label>
                    <input type="file" name="photo" accept="image/*" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
                </div>

                <div style="grid-column: span 2; margin-top: 10px;">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Update Personnel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).classList.add('show-modal'); }
        function closeModal(id) { document.getElementById(id).classList.remove('show-modal'); }
        window.onclick = function(e) { if(e.target.classList.contains('modal')) e.target.classList.remove('show-modal'); }
        
        function openEditModal(data) {
            document.getElementById('edit_original_id').value = data.id;
            document.getElementById('edit_labour_id').value = data.labour_id;
            document.getElementById('edit_full_name').value = data.full_name;
            document.getElementById('edit_gender').value = data.gender;
            document.getElementById('edit_phone_number').value = data.phone_number;
            document.getElementById('edit_work_type').value = data.work_type;
            document.getElementById('edit_guarantee').value = data.guarantee || '';
            document.getElementById('edit_start_date').value = data.start_date;
            document.getElementById('edit_expiry_date').value = data.expiry_date;
            document.getElementById('edit_address').value = data.address;
            document.getElementById('edit_status').value = data.status;
            
            if (document.getElementById('edit_campus_id') && data.campus_id) {
                document.getElementById('edit_campus_id').value = data.campus_id;
            }
            openModal('editModal');
        }

        function toggleTheme() {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
        }
        if (localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');
    </script>
</body>
</html>
