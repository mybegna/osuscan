<?php
// Signed-in user chip for the top bar (initials, name, role). Links to the profile page.
$tbName = trim($_SESSION['full_name'] ?? '') ?: 'User';
$tbInitials = '';
foreach (array_slice(preg_split('/\s+/u', $tbName), 0, 2) as $tbWord) {
    $tbInitials .= mb_strtoupper(mb_substr($tbWord, 0, 1));
}
?>
<a class="top-user" href="profile" title="<?php echo htmlspecialchars($lang['profile'] ?? 'Profile'); ?>">
    <span class="avatar"><?php echo htmlspecialchars($tbInitials); ?></span>
    <span class="top-user-text">
        <strong><?php echo htmlspecialchars($tbName); ?></strong>
        <small><?php echo htmlspecialchars($_SESSION['role'] ?? ''); ?></small>
    </span>
</a>
