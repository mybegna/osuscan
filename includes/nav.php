<?php
require_once dirname(__DIR__) . '/sessions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="dashboard.php">OSU One Card</a>
    <button
      class="navbar-toggler"
      type="button"
      data-mdb-toggle="collapse"
      data-mdb-target="#navbarNav"
      aria-controls="navbarNav"
      aria-expanded="false"
      aria-label="Toggle navigation"
    >
      <i class="fas fa-bars"></i>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link" href="dashboard.php">Dashboard</a>
        </li>
        <?php if($_SESSION['role'] === 'Super Admin' || $_SESSION['role'] === 'Registrar Officer'): ?>
        <li class="nav-item">
          <a class="nav-link" href="students.php">Students</a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a class="nav-link" href="scan.php">Scanner</a>
        </li>
      </ul>
      
      <!-- Clock -->
      <span id="nav-clock" class="text-white me-4" style="font-weight: 500;"></span>
      <script>
        function updateNavClock() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            const formatted = now.toLocaleDateString('en-US', options);
            const el = document.getElementById('nav-clock');
            if(el) el.textContent = formatted;
        }
        setInterval(updateNavClock, 1000);
        updateNavClock();
      </script>

      <span class="navbar-text me-3 text-white">
        <?php echo htmlspecialchars($_SESSION['full_name']); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)
      </span>
      <a class="btn btn-outline-light btn-sm" href="logout.php">Logout</a>
    </div>
  </div>
</nav>
