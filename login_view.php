<?php
require_once 'sessions.php';
require_once 'includes/lang_init.php'; // Load Language
date_default_timezone_set('Africa/Addis_Ababa');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT u.id, u.username, u.password_hash, u.full_name, u.role_id, u.is_blocked, u.failed_attempts, u.locked_until, u.campus_id, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user) {
                // Check if account is locked
                if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                    $unlockTime = date('h:i A', strtotime($user['locked_until']));
                    $error = "Account locked due to too many failed attempts. Try again after $unlockTime.";
                
                // Check if blocked by admin
                } elseif (isset($user['is_blocked']) && $user['is_blocked'] == 1) {
                    $error = $lang['access_denied'] . ": Your account has been blocked.";
                
                // Verify Password
                } elseif (password_verify($password, $user['password_hash'])) {
                    
                    // Reset failed attempts on success
                    $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$user['id']]);

                    if (!session_id()) {
                        session_start();
                    }
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role_name'];
                    $_SESSION['username'] = $user['username']; // Useful for profile
                    $_SESSION['campus_id'] = $user['campus_id'];
                    
                    try {
                        $stmt = $pdo->prepare("INSERT INTO login_logs (user_id, ip_address) VALUES (?, ?)");
                        $stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR']]);
                    } catch (Exception $e) {}

                    header("Location: dashboard");
                    exit;
                } else {
                    // Update failed attempts
                    $attempts = $user['failed_attempts'] + 1;
                    $LOCK_LIMIT = 5;
                    $LOCK_DURATION = '+15 minutes';

                    if ($attempts >= $LOCK_LIMIT) {
                        $lockTime = date('Y-m-d H:i:s', strtotime($LOCK_DURATION));
                        $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?")->execute([$attempts, $lockTime, $user['id']]);
                        $error = "Too many failed attempts. Account locked for 15 minutes.";
                    } else {
                        $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?")->execute([$attempts, $user['id']]);
                        $error = $lang['invalid_credentials'];
                    }
                }
            } else {
                $error = $lang['invalid_credentials'];
            }
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['login_title']; ?> - Oromia State University, SSMS</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page">
<div class="lg">
  <div class="lg-card">

    <aside class="lg-brand">
        <img class="lg-logo" src="assets/img/osu-logo-primary-white.svg" width="300" height="190" alt="Yuunivarsiitii Naannoo Oromiyaa · Oromia State University">
        <div>
            <p class="lg-eyebrow"><?php echo $lang['login_eyebrow'] ?? 'Student Service Management System'; ?></p>
            <p class="lg-headline"><?php echo $lang['login_headline'] ?? 'One card for every campus service.'; ?></p>
            <p class="lg-commit"><?php echo $lang['welcome_system']; ?></p>
        </div>
    </aside>

    <main class="lg-form">
        <div class="lg-lang lang-switch">
            <a href="?lang=or" class="lang-btn <?php echo ($_SESSION['lang']??'en') == 'or' ? 'active' : ''; ?>">ORM</a>
            <a href="?lang=en" class="lang-btn <?php echo ($_SESSION['lang']??'en') == 'en' ? 'active' : ''; ?>">ENG</a>
            <a href="?lang=am" class="lang-btn <?php echo ($_SESSION['lang']??'en') == 'am' ? 'active' : ''; ?>">AMH</a>
        </div>

        <h1><?php echo $lang['login_title']; ?></h1>
        <p class="lg-sub"><?php echo $lang['login_subtitle']; ?></p>

        <?php if ($error): ?>
            <div class="lg-alert" role="alert">
                <i class="fas fa-exclamation-circle"></i><div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="lg-field">
                <label class="lg-label" for="username"><?php echo $lang['username']; ?></label>
                <input class="lg-input" type="text" id="username" name="username" autocomplete="username" spellcheck="false" required>
            </div>

            <div class="lg-field">
                <label class="lg-label" for="password"><?php echo $lang['password']; ?></label>
                <div class="lg-input-wrap">
                    <input class="lg-input has-toggle" type="password" id="password" name="password" autocomplete="current-password" required>
                    <button type="button" class="lg-show password-toggle-btn" onclick="togglePassword()" aria-controls="password" aria-label="Show or hide password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="lg-btn"><?php echo $lang['login_btn']; ?></button>
        </form>

        <p class="lg-foot">Oromia State University &middot; Student Service Management System</p>
    </main>

  </div>
</div>

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const btn = document.querySelector('.password-toggle-btn');
            if (input.type === 'password') {
                input.type = 'text';
                btn.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                input.type = 'password';
                btn.innerHTML = '<i class="fas fa-eye"></i>';
            }
        }
    </script>
</body>
</html>