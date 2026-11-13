<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
checkAuth(); // All authenticated users may access their profile

// Ensure username is available
if (!isset($_SESSION['username'])) {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $_SESSION['username'] = $stmt->fetchColumn();
}
$username = $_SESSION['username'] ?? '';

// Profile Update Logic
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    try {
        $email = trim($_POST['email']);
        $currentPassword = $_POST['current_password'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        $userId = $_SESSION['user_id'];

        // Verify current password
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            throw new Exception("Incorrect current password.");
        }

        if (!empty($newPassword)) {
            if ($newPassword !== $confirmPassword) {
                throw new Exception("New passwords do not match.");
            }
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET username = ?, password_hash = ? WHERE id = ?");
            $stmt->execute([$email, $hashedPassword, $userId]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
            $stmt->execute([$email, $userId]);
        }
        
        $_SESSION['username'] = $email;
        $username = $email; // Update local variable
        $message = "Profile updated successfully!";
        
        // Log action
        $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
        $stmtLog->execute([$_SESSION['user_id'], 'Update Profile', "Updated profile/password"]);

    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - OSU One Card</title>
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
                    <i class="fas fa-user-circle"></i> My Profile
                </div>
            </div>
            <div class="navbar-actions">
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
                <div class="card flash <?php echo strpos($message, 'Error') ? 'flash-error' : 'flash-success'; ?>" style="">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width: 600px; margin: 0 auto;">
                <div class="card-header">
                    <i class="fas fa-edit"></i> Edit Profile
                </div>
                <form method="POST" style="padding: 20px;">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; color: var(--secondary-color);">Email Address (Username)</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($username); ?>" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; color: var(--secondary-color);">Current Password (Required)</label>
                        <input type="password" name="current_password" required style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                    </div>

                    <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 20px 0;">
                    <p style="font-size: 13px; color: var(--secondary-color); margin-bottom: 15px;">Leave blank if you don't want to change password.</p>

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; color: var(--secondary-color);">New Password</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="password" name="new_password" id="new_password" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                            <button type="button" class="btn btn-secondary" onclick="generatePassword('new_password', 'confirm_password')" title="Generate Strong Password"><i class="fas fa-key"></i></button>
                        </div>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 5px; color: var(--secondary-color);">Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px;">
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">Update Profile</button>
                </form>
            </div>
        </div>
    </div>
    <script>
        function toggleTheme() {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
        }
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
        }

        function generatePassword(targetId, confirmId) {
            const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
            let pass = "";
            for (let i = 0; i < 14; i++) {
                pass += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            const input = document.getElementById(targetId);
            input.type = "text"; 
            input.value = pass;
            if(confirmId) document.getElementById(confirmId).value = pass;
        }
    </script>
</body>
</html>
