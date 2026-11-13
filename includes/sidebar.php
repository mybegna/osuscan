<?php
$role = $_SESSION['role'] ?? 'Guest';
require_once 'includes/lang_init.php'; // Ensure languages are loaded

$sbRoute    = $route ?? '';
$sbScanType = $_GET['type'] ?? '';
$sbName     = trim($_SESSION['full_name'] ?? '') ?: 'User';

// Avatar initials: first letter of the first two words of the name
$sbInitials = '';
foreach (array_slice(preg_split('/\s+/u', $sbName), 0, 2) as $sbWord) {
    $sbInitials .= mb_strtoupper(mb_substr($sbWord, 0, 1));
}

/** One navigation link. Returns [isActive, html]. */
$sbLink = function (bool $active, string $href, string $icon, string $label): array {
    $html = '<a class="side-link' . ($active ? ' active' : '') . '" href="' . htmlspecialchars($href) . '" title="' . htmlspecialchars($label) . '"'
          . ($active ? ' aria-current="page"' : '') . '><i class="fas fa-' . $icon . '"></i><span>' . htmlspecialchars($label) . '</span></a>';
    return [$active, $html];
};
/** One collapsible group; opens automatically when it holds the current page. */
$sbGroup = function (string $id, string $icon, string $label, array $links, bool $defaultOpen = false): string {
    if (!$links) return '';
    $open = $defaultOpen || in_array(true, array_column($links, 0), true);
    return '<details class="side-group" data-group="' . $id . '"' . ($open ? ' open' : '') . '>'
         . '<summary title="' . htmlspecialchars($label) . '"><span class="tile"><i class="fas fa-' . $icon . '"></i></span>'
         . '<span class="glabel">' . htmlspecialchars($label) . '</span><span class="plus" aria-hidden="true"></span></summary>'
         . '<div class="side-links">' . implode('', array_column($links, 1)) . '</div></details>';
};

$sbIsAdmin = in_array($role, ['Super Admin', 'Campus Admin']);

// Main
$sbMain = [];
if (in_array($role, ['Super Admin', 'Cafeteria Manager', 'Registrar Officer', 'Campus Admin'])) {
    $sbMain[] = $sbLink($sbRoute === 'dashboard', 'dashboard', 'home', $lang['dashboard']);
}

// Registry
$sbRegistry = [];
if (in_array($role, ['Super Admin', 'Registrar Officer', 'College Registrar', 'ID Card Printer', 'Campus Admin'])) {
    $sbRegistry[] = $sbLink($sbRoute === 'students', 'students', 'graduation-cap', $lang['students']);
}
if (in_array($role, ['Super Admin', 'Registrar Officer', 'College Registrar', 'Campus Admin'])) {
    $sbRegistry[] = $sbLink($sbRoute === 'promote_students', 'promote_students', 'level-up-alt', $lang['promote_students']);
}
if ($sbIsAdmin) {
    $sbRegistry[] = $sbLink($sbRoute === 'labour', 'labour', 'hard-hat', $lang['labour_personnel'] ?? 'Labour Personnel');
}

// Scanners
$sbScanners = [];
if ($role === 'Security Officer' || $role === 'Gate Security User' || $sbIsAdmin) {
    $sbScanners[] = $sbLink($sbScanType === 'gate', 'scan?type=gate', 'door-open', $lang['gate_scanner']);
}
if ($role === 'Library User' || $sbIsAdmin) {
    $sbScanners[] = $sbLink($sbScanType === 'library', 'scan?type=library', 'book', $lang['library_scanner']);
}
if ($role === 'Clinic User' || $sbIsAdmin) {
    $sbScanners[] = $sbLink($sbScanType === 'clinic', 'scan?type=clinic', 'hospital-user', $lang['clinic_scanner']);
}
if (strpos($role, 'Cafeteria') !== false || $role === 'Cafe Ticker' || $role === 'Cafe Manager' || $sbIsAdmin) {
    $sbScanners[] = $sbLink($sbScanType === 'cafeteria', 'scan?type=cafeteria', 'utensils', $lang['cafeteria_scanner']);
}

// Cafeteria & reports
$sbCafe = [];
if (in_array($role, ['Super Admin', 'Campus Admin', 'Cafeteria Manager'])) {
    $sbCafe[] = $sbLink($sbRoute === 'manage_cafe', 'manage_cafe', 'coffee', $lang['manage_cafe']);
}
if (in_array($role, ['Super Admin', 'Campus Admin', 'Cafeteria Manager', 'Cafe Manager'])) {
    $sbCafe[] = $sbLink($sbRoute === 'reports', 'reports', 'chart-bar', $lang['reports']);
}

// Administration
$sbAdmin = [];
if ($sbIsAdmin) {
    $sbAdmin[] = $sbLink($sbRoute === 'users', 'users', 'user-shield', $lang['users']);
    $sbAdmin[] = $sbLink($sbRoute === 'departments', 'departments', 'building', $lang['departments']);
    $sbAdmin[] = $sbLink($sbRoute === 'audit_logs', 'audit_logs', 'history', $lang['audit_logs']);
}
?>
<div class="sidebar-overlay" onclick="toggleSidebarMobile()"></div>

<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <!-- Brand + collapse -->
    <div class="sidebar-top">
        <a class="sidebar-brand" href="dashboard" title="Oromia State University">
            <img class="logo-full" src="assets/img/osu-logo-white.svg" alt="Oromia State University" width="147" height="40">
            <img class="logo-mark" src="assets/img/osu-mark-white.svg" alt="Oromia State University" width="38" height="38">
        </a>
        <button class="side-collapse" type="button" onclick="toggleSidebar()" aria-label="Toggle sidebar" title="Toggle sidebar">
            <i class="fas fa-angle-double-left"></i>
        </button>
    </div>

    <!-- Signed-in user -->
    <a class="side-user" href="profile" title="<?php echo htmlspecialchars($lang['profile']); ?>">
        <span class="avatar"><?php echo htmlspecialchars($sbInitials); ?></span>
        <div>
            <strong><?php echo htmlspecialchars($sbName); ?></strong>
            <small><?php echo htmlspecialchars($role); ?></small>
        </div>
    </a>

    <!-- Menu -->
    <nav>
        <?php
        echo $sbGroup('main', 'home', $lang['nav_main'] ?? 'Main', $sbMain, true);
        echo $sbGroup('registry', 'id-card', $lang['nav_registry'] ?? 'Registry', $sbRegistry, true);
        echo $sbGroup('scanners', 'barcode', $lang['nav_scanners'] ?? 'Scanners', $sbScanners, true);
        echo $sbGroup('cafe', 'utensils', $lang['nav_cafe_reports'] ?? 'Cafeteria & Reports', $sbCafe);
        echo $sbGroup('admin', 'sliders-h', $lang['nav_administration'] ?? 'Administration', $sbAdmin);
        ?>
    </nav>

    <!-- Footer -->
    <div class="side-foot">
        <?php echo $sbLink($sbRoute === 'profile', 'profile', 'user-circle', $lang['profile'])[1]; ?>
        <a class="side-link" href="logout" title="<?php echo htmlspecialchars($lang['sign_out']); ?>"><i class="fas fa-sign-out-alt"></i><span><?php echo htmlspecialchars($lang['sign_out']); ?></span></a>
    </div>
</aside>

<script>
    // Unified Toggle Function (works for both mobile and desktop)
    function toggleSidebar() {
        if (window.innerWidth <= 768) {
            toggleSidebarMobile();
        } else {
            toggleSidebarDesktop();
        }
    }

    // Mobile Toggle
    function toggleSidebarMobile() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        if (sidebar && overlay) {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            // Prevent body scroll when sidebar is open on mobile
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }
    }

    // Desktop Collapse
    function setSidebarCollapsed(collapsed) {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');
        if (!sidebar) return;
        sidebar.classList.toggle('collapsed', collapsed);
        if (mainContent) mainContent.classList.toggle('expanded', collapsed);
    }

    function toggleSidebarDesktop() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        const collapsed = !sidebar.classList.contains('collapsed');
        setSidebarCollapsed(collapsed);
        try { localStorage.setItem('sidebar-collapsed', collapsed ? 'true' : 'false'); } catch (e) {}
    }

    // Apply the saved collapsed state straight away (desktop only) to avoid a flash
    try {
        if (localStorage.getItem('sidebar-collapsed') === 'true' && window.innerWidth > 768) {
            document.getElementById('sidebar').classList.add('collapsed');
        }
    } catch (e) {}

    document.addEventListener('DOMContentLoaded', () => {
        const sidebar = document.getElementById('sidebar');
        if (sidebar && sidebar.classList.contains('collapsed')) setSidebarCollapsed(true);

        // Remember which groups are open. A group holding the current page always opens.
        document.querySelectorAll('.side-group[data-group]').forEach((g) => {
            const key = 'sidebar-group-' + g.dataset.group;
            try {
                const saved = localStorage.getItem(key);
                if (saved !== null && !g.querySelector('.side-link.active')) g.open = saved === 'open';
            } catch (e) {}
            g.addEventListener('toggle', () => {
                try { localStorage.setItem(key, g.open ? 'open' : 'closed'); } catch (e) {}
            });
        });
    });

    // Handle window resize - close mobile sidebar if resizing to desktop
    window.addEventListener('resize', () => {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        
        if (window.innerWidth > 768) {
            // Desktop view - remove mobile classes
            if (sidebar) sidebar.classList.remove('active');
            if (overlay) overlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    });

    // Add enhanced mobile styles
    const style = document.createElement('style');
    style.innerHTML = `
        /* Mobile-specific improvements */
        @media (max-width: 768px) {
            .sidebar-brand button { display: block !important; }
            .d-none { display: none !important; }
            .d-md-flex { display: none !important; }
            
            /* Ensure hamburger is visible */
            .hamburger-menu {
                display: block !important;
                z-index: 1001;
            }
            
            /* Improve touch targets */
            .sidebar-menu-link {
                padding: 15px 20px !important;
                min-height: 48px;
            }
            
            /* Better navbar spacing on mobile */
            .navbar-top {
                padding: 10px 15px !important;
            }
            
            .navbar-actions {
                gap: 8px !important;
            }
            
            /* Responsive language buttons */
            .lang-btn {
                padding: 4px 8px !important;
                font-size: 11px !important;
            }
            
            /* Ensure content doesn't overflow */
            .page-content {
                padding: 15px !important;
            }
            
            /* Make tables scrollable */
            .card {
                overflow-x: auto;
            }
            
            /* Stat cards stack on mobile */
            .stat-card {
                min-width: 100%;
            }
        }
        
        @media (min-width: 769px) {
            .d-md-none { display: none !important; }
            .d-md-flex { display: flex !important; }
            
            /* Hide hamburger on desktop */
            .hamburger-menu {
                display: none !important;
            }
        }
        
        /* Tablet-specific adjustments */
        @media (min-width: 481px) and (max-width: 768px) {
            .grid {
                grid-template-columns: repeat(2, 1fr) !important;
            }
        }
        
        /* Small mobile adjustments */
        @media (max-width: 480px) {
            .navbar-title {
                font-size: 14px !important;
            }
            
            #top-nav-clock {
                display: none !important;
            }
            
            .stat-info h3 {
                font-size: 20px !important;
            }
            
            .stat-info p {
                font-size: 12px !important;
            }
        }
    `;
    document.head.appendChild(style);
</script>
