<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
require_once 'includes/lang_init.php';
checkAuth();

if ($_SESSION['role'] !== 'Super Admin') {
    die("<div style='padding: 20px; color: #6B4E3D;'><h2>Access Denied</h2></div>");
}

$message = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_campus') {
        try {
            $name = trim($_POST['campus_name']);
            $code = trim($_POST['campus_code']);
            $loc = trim($_POST['location']);
            
            $stmt = $pdo->prepare("INSERT INTO campuses (campus_name, campus_code, location) VALUES (?, ?, ?)");
            $stmt->execute([$name, $code, $loc]);
            $message = "Campus added successfully!";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'edit_campus') {
        try {
            $id = $_POST['id'];
            $name = trim($_POST['campus_name']);
            $code = trim($_POST['campus_code']);
            $loc = trim($_POST['location']);

            $stmt = $pdo->prepare("UPDATE campuses SET campus_name=?, campus_code=?, location=? WHERE id=?");
            $stmt->execute([$name, $code, $loc, $id]);
            $message = "Campus updated successfully!";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_campus') {
        try {
            $id = $_POST['id'];
            // Prevent deleting Main Campus (ID 1)
            if ($id == 1) {
                throw new Exception("Cannot delete the Main Campus.");
            }
            $stmt = $pdo->prepare("DELETE FROM campuses WHERE id=?");
            $stmt->execute([$id]);
            $message = "Campus deleted successfully.";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }
}

$campuses = $pdo->query("SELECT * FROM campuses ORDER BY id ASC")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Campuses - OSU One Card</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(12, 33, 26, 0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: var(--card-bg); padding: 20px; border-radius: 8px; width: 90%; max-width: 500px; position: relative; }
        .close-modal { position: absolute; right: 15px; top: 15px; font-size: 20px; cursor: pointer; }
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
                    <i class="fas fa-university"></i> Manage Campuses
                </div>
            </div>
            <div class="navbar-actions">
                <button class="btn btn-primary" onclick="openModal('addCampusModal')">
                    <i class="fas fa-plus"></i> Add New Campus
                </button>
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
                    <i class="fas fa-list"></i> Campus List
                </div>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: var(--hover-bg-light);">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);">ID</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);">Name</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);">Code</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid var(--border-color);">Location</th>
                                <th style="padding: 12px; text-align: right; border-bottom: 2px solid var(--border-color);">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campuses as $campus): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 12px;"><?php echo $campus['id']; ?></td>
                                <td style="padding: 12px; font-weight: bold;"><?php echo htmlspecialchars($campus['campus_name']); ?></td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($campus['campus_code']); ?></td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($campus['location']); ?></td>
                                <td style="padding: 12px; text-align: right;">
                                    <button onclick='openEditModal(<?php echo json_encode($campus); ?>)' class="btn btn-sm btn-secondary">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($campus['id'] != 1): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure? This will delete the campus. Users and data associated might be affected.');">
                                        <input type="hidden" name="action" value="delete_campus">
                                        <input type="hidden" name="id" value="<?php echo $campus['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="background: var(--danger-fill); color: white;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <a href="users.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Users</a>
            </div>

            <!-- Add Modal -->
            <div id="addCampusModal" class="modal">
                <div class="modal-content" style="background-color: var(--card-bg);">
                    <span class="close-modal" onclick="closeModal('addCampusModal')">&times;</span>
                    <div class="card-header" style="border:none;">Add Campus</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_campus">
                        <div style="display:flex; flex-direction:column; gap:15px;">
                            <input type="text" name="campus_name" placeholder="Campus Name" required style="padding:10px; border:1px solid var(--border-color); border-radius:6px;">
                            <input type="text" name="campus_code" placeholder="Campus Code (e.g. MAIN)" required style="padding:10px; border:1px solid var(--border-color); border-radius:6px;">
                            <input type="text" name="location" placeholder="Location" required style="padding:10px; border:1px solid var(--border-color); border-radius:6px;">
                            <button type="submit" class="btn btn-primary">Add</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Modal -->
            <div id="editCampusModal" class="modal">
                <div class="modal-content">
                    <span class="close-modal" onclick="closeModal('editCampusModal')">&times;</span>
                    <div class="card-header" style="border:none;">Edit Campus</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="edit_campus">
                        <input type="hidden" name="id" id="edit_id">
                        <div style="display:flex; flex-direction:column; gap:15px;">
                            <input type="text" name="campus_name" id="edit_name" placeholder="Campus Name" required style="padding:10px; border:1px solid var(--border-color); border-radius:6px;">
                            <input type="text" name="campus_code" id="edit_code" placeholder="Campus Code" required style="padding:10px; border:1px solid var(--border-color); border-radius:6px;">
                            <input type="text" name="location" id="edit_loc" placeholder="Location" required style="padding:10px; border:1px solid var(--border-color); border-radius:6px;">
                            <button type="submit" class="btn btn-primary">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).classList.add('show-modal'); }
        function closeModal(id) { document.getElementById(id).classList.remove('show-modal'); }
        function openEditModal(c) {
            document.getElementById('edit_id').value = c.id;
            document.getElementById('edit_name').value = c.campus_name;
            document.getElementById('edit_code').value = c.campus_code;
            document.getElementById('edit_loc').value = c.location;
            openModal('editCampusModal');
        }
        window.onclick = function(e) { if(e.target.classList.contains('modal')) e.target.classList.remove('show-modal'); }
        function toggleTheme() {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
        }
        if (localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-mode');
    </script>
</body>
</html>
