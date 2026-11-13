<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
require_once 'includes/lang_init.php'; // Load Language
checkAuth();

// Set Timezone
date_default_timezone_set('Africa/Addis_Ababa');

$type = $_GET['type'] ?? 'gate';
$role = $_SESSION['role'] ?? '';
$campus_id = $_SESSION['campus_id'] ?? null;

// Role-based access control
$allowedScanners = [];
if ($role === 'Gate Security User' || $role === 'Security Officer') $allowedScanners[] = 'gate';
if ($role === 'Library User') $allowedScanners[] = 'library';
if ($role === 'Clinic User') $allowedScanners[] = 'clinic';
if (strpos($role, 'Cafeteria') !== false || $role === 'Cafe Ticker' || $role === 'Cafe Manager') $allowedScanners[] = 'cafeteria';
if ($role === 'Super Admin' || $role === 'Campus Admin') $allowedScanners = ['gate', 'library', 'clinic', 'cafeteria'];

if (!in_array($type, $allowedScanners)) {
    die("<div style='padding: 20px; text-align: center; color: #6B4E3D;'><h2>Access Denied</h2><p>You don't have permission to access this scanner.</p></div>");
}

$scannerNames = [
    'gate' => $lang['gate_scanner'],
    'library' => $lang['library_scanner'],
    'clinic' => $lang['clinic_scanner'],
    'cafeteria' => $lang['cafeteria_scanner']
];

$scannerTitle = $scannerNames[$type] ?? 'Scanner';

// Fetch Gates for Cafeteria Selection
$cafeteria_gates = [];
if ($type === 'cafeteria') {
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
}

// Check Meal Time if Cafeteria
$mealStatusMsg = "";
$isMealTime = false;
$mealType = "";

// Default values for other scanners
$totalMealsToday = 0;
$currentMealScanned = 0;
$totalEligible = 0;
$nextMealInfo = "";

if ($type === 'cafeteria') {
    $current_time = date('H:i:s');
    
    // Fetch active schedules
    try {
        $schQuery = "SELECT * FROM cafe_schedules WHERE is_active = 1";
        $schParams = [];
        if ($campus_id) {
            $schQuery .= " AND campus_id = ?";
            $schParams[] = $campus_id;
        }
        $stmt = $pdo->prepare($schQuery);
        $stmt->execute($schParams);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($schedules as $sch) {
            if ($current_time >= $sch['start_time'] && $current_time <= $sch['end_time']) {
                $mealType = $sch['meal_type'];
                $isMealTime = true;
                break;
            }
        }
        
        $today = date('Y-m-d');
        
        // 1. Total meals served today (all types)
        $mealLogQuery = "SELECT COUNT(*) FROM meal_logs WHERE DATE(scan_time) = ?";
        $mealLogParams = [$today];
        if ($campus_id) {
            $mealLogQuery .= " AND campus_id = ?";
            $mealLogParams[] = $campus_id;
        }
        $stmt = $pdo->prepare($mealLogQuery);
        $stmt->execute($mealLogParams);
        $totalMealsToday = $stmt->fetchColumn();
        
        // 2. Students scanned for CURRENT meal
        if ($isMealTime) {
            $curMealQuery = "SELECT COUNT(*) FROM meal_logs WHERE DATE(scan_time) = ? AND meal_type = ?";
            $curMealParams = [$today, $mealType];
            if ($campus_id) {
                $curMealQuery .= " AND campus_id = ?";
                $curMealParams[] = $campus_id;
            }
            $stmt = $pdo->prepare($curMealQuery);
            $stmt->execute($curMealParams);
            $currentMealScanned = $stmt->fetchColumn();
        }
        
        // 3. Total eligible students (Active + Cafe user)
        $studQuery = "SELECT COUNT(*) FROM students WHERE enrollment_status = 'Active' AND cafe_type = 'Cafe user'";
        $studParams = [];
        if ($campus_id) {
             $studQuery .= " AND campus_id = ?";
             $studParams[] = $campus_id;
        }
        $stmt = $pdo->prepare($studQuery);
        $stmt->execute($studParams);
        $totalEligible = $stmt->fetchColumn();
        
        // Get next meal if not currently meal time
        if (!$isMealTime) {

            $nextSchQuery = "SELECT * FROM cafe_schedules WHERE is_active = 1";
            $nextSchParams = [];
            if ($campus_id) {
                $nextSchQuery .= " AND campus_id = ?";
                $nextSchParams[] = $campus_id;
            }
            $nextSchQuery .= " ORDER BY start_time ASC";
            $stmt = $pdo->prepare($nextSchQuery);
            $stmt->execute($nextSchParams);
            $allSchedules = $stmt->fetchAll();
            foreach ($allSchedules as $sch) {
                if ($sch['start_time'] > $current_time) {
                    $nextMealInfo = "Next meal is " . $sch['meal_type'] . " at " . date('h:i A', strtotime($sch['start_time']));
                    break;
                }
            }
            if (empty($nextMealInfo) && !empty($allSchedules)) {
                $nextMealInfo = "Next meal is " . $allSchedules[0]['meal_type'] . " tomorrow at " . date('h:i A', strtotime($allSchedules[0]['start_time']));
            }
        }
    } catch (Exception $e) {
        // Fallback or ignore
    }
    
    if ($isMealTime) {
        $mealStatusMsg = "<div style='background-color: var(--success-bg); color: var(--success-on); padding: 10px; border-radius: 5px; margin-bottom: 15px; font-weight: bold;'>{$lang['meal_time_msg']} ($mealType)</div>";
    } else {
        $mealStatusMsg = "<div style='background-color: var(--danger-bg); color: var(--danger-on); padding: 10px; border-radius: 5px; margin-bottom: 15px; font-weight: bold;'>{$lang['closed_msg']}</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $scannerTitle; ?> - OSU One Card</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* lang-btn styles removed to use theme.css */
        
        /* Compact Layout Styles */
        .page-content { padding: 10px 20px !important; }
        .scanner-container { display: flex; gap: 20px; height: calc(100vh - 140px); }
        .left-col { flex: 0 0 40%; display: flex; flex-direction: column; }
        .right-col { flex: 1; border-left: 1px solid var(--border-color); padding-left: 20px; }
        
        .scan-box { background: rgba(12, 33, 26, 0.02); padding: 20px; border-radius: 8px; text-align: center; border: 1px solid var(--border-color); }
        .metrics-row { display: flex; gap: 10px; margin-top: 15px; }
        .metric-card { flex: 1; background: var(--card-bg); padding: 10px; border-radius: 6px; border: 1px solid var(--border-color); text-align: center; box-shadow: 0 1px 3px rgba(12, 33, 26, 0.05); }
        .metric-val { font-size: 20px; font-weight: bold; color: var(--primary-color); }
        .metric-label { font-size: 12px; color: var(--secondary-color); }

        .result-layout { display: flex; gap: 20px; align-items: flex-start; height: 100%; }
        .student-photo-large { width: 200px; height: 200px; object-fit: cover; border-radius: 8px; border: 4px solid var(--border-color); }
        .student-details { flex: 1; text-align: left; }
        
        @media (max-width: 900px) {
            .scanner-container { flex-direction: column; height: auto; }
            .left-col { flex: none; width: 100%; }
            .right-col { border-left: none; border-top: 1px solid var(--border-color); padding-left: 0; padding-top: 20px; }
            .result-layout { flex-direction: column; align-items: center; text-align: center; }
            .student-details { text-align: center; }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Top Navbar -->
        <div class="navbar-top" style="padding: 10px 20px; min-height: 60px;">
            <div style="display: flex; align-items: center;">
                <button class="hamburger-menu" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="navbar-title" style="font-size: 18px;">
                    <i class="fas fa-barcode"></i> <?php echo $scannerTitle; ?>
                </div>
            </div>
            
            <div class="navbar-actions" style="display: flex; align-items: center; gap: 15px;">
                <?php if ($type === 'cafeteria'): ?>
                    <select id="gate-selector" onchange="updateGate(this.value)" onfocus="pauseScanning()" onblur="resumeScanning()" 
                        style="padding: 10px; border-radius: 4px; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-color); font-size: 14px;">
                        <option value="">Select Gate...</option>
                        <?php foreach ($cafeteria_gates as $gate): ?>
                            <option value="<?php echo htmlspecialchars($gate); ?>"><?php echo htmlspecialchars($gate); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <!-- Live Clock -->
                <div id="top-clock" style="color: var(--text-color); font-weight: 600; font-size: 14px;"></div>
                
                <!-- Language Switcher -->
                <div style="display: flex;">
                    <a href="?type=<?php echo $type; ?>&lang=en" class="lang-btn <?php echo ($_SESSION['lang']??'en') == 'en' ? 'active' : ''; ?>">ENG</a>
                    <a href="?type=<?php echo $type; ?>&lang=am" class="lang-btn <?php echo ($_SESSION['lang']??'en') == 'am' ? 'active' : ''; ?>">AMH</a>
                    <a href="?type=<?php echo $type; ?>&lang=or" class="lang-btn <?php echo ($_SESSION['lang']??'en') == 'or' ? 'active' : ''; ?>">ORM</a>
                </div>

                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>
                <?php include 'includes/topbar_user.php'; ?>
            </div>
        </div>

        <!-- Page Content -->
        <div class="page-content">
            <?php if ($type === 'cafeteria'): ?>
                <?php echo $mealStatusMsg; ?>
            <?php endif; ?>

            <div class="scanner-container">
                <!-- Left Column -->
                <div class="left-col">
                    <!-- Row 1: Scan Input -->
                    <div class="scan-box">
                        <div style="font-size: 32px; color: var(--primary-color); margin-bottom: 10px;">
                            <i class="fas fa-barcode"></i>
                        </div>
                        <?php if ($type !== 'cafeteria' || $isMealTime): ?>
                            <h2 style="margin-bottom: 5px; font-size: 18px;"><?php echo $lang['scan_barcode']; ?></h2>
                            <p style="color: var(--secondary-color); margin-bottom: 15px; font-size: 14px;"><?php echo $lang['ready_scan']; ?></p>
                            <input type="text" id="scan-input" placeholder="" autofocus autocomplete="off" onblur="checkFocus()"
                                style="font-size: 1px; color: transparent; background: transparent; border: none; outline: none; caret-color: transparent; width: 1px; height: 1px; overflow: hidden;">
                            <div style="padding: 40px; border: 2px dashed var(--border-color); border-radius: 8px; color: var(--secondary-color); cursor: pointer;" onclick="document.getElementById('scan-input').focus()">
                                <i class="fas fa-barcode" style="font-size: 48px; margin-bottom: 10px; opacity: 0.5;"></i>
                                <div>Scanning Active</div>
                            </div>
                        <?php else: ?>
                            <h2 style="margin-bottom: 10px; color: var(--danger-color);">Scanning Disabled</h2>
                            <p><?php echo $nextMealInfo; ?></p>
                        <?php endif; ?>
                        
                        <div id="loading" style="display: none; text-align: center; margin-top: 10px;">
                            <div style="border: 3px solid var(--primary-light); border-top: 3px solid var(--primary-color); border-radius: 50%; width: 24px; height: 24px; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                        </div>
                        <div id="error-alert" style="display: none; background: var(--danger-bg); color: var(--danger-on); padding: 10px; border-radius: 6px; margin-top: 10px; font-size: 14px;"></div>
                    </div>

                    <!-- Row 2: Metrics (Cafeteria Only) -->
                    <?php if ($type === 'cafeteria'): ?>
                    <div class="metrics-row">
                        <div class="metric-card">
                            <div class="metric-val" id="stat-total-today"><?php echo $totalMealsToday; ?></div>
                            <div class="metric-label"><?php echo $lang['total_meals']; ?></div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-val" id="stat-current-meal"><?php echo $currentMealScanned; ?></div>
                            <div class="metric-label"><?php echo $isMealTime ? $mealType : $lang['current_meal']; ?></div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-val" id="stat-total-eligible"><?php echo $totalEligible; ?></div>
                            <div class="metric-label"><?php echo $lang['total_eligible']; ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right Column: Results -->
                <div class="right-col">
                     <div id="result-placeholder" style="color: var(--secondary-color); margin-top: 50px; text-align: center; display: block;">
                        <i class="fas fa-user-graduate" style="font-size: 64px; margin-bottom: 20px; opacity: 0.3;"></i>
                        <h3 style="opacity: 0.6;"><?php echo $lang['student_info_placeholder']; ?></h3>
                     </div>

                     <div id="result-card" style="display: none; height: 100%;">
                        <div class="result-layout">
                            <!-- Student Photo -->
                            <div>
                                <img id="student-photo" src="assets/img/default.png" class="student-photo-large">
                            </div>
                            
                            <!-- Student Details -->
                            <div class="student-details">
                                <h2 id="student-name" style="font-size: 28px; font-weight: bold; margin: 0 0 5px 0; color: var(--text-color);"><?php echo $lang['student_name']; ?></h2>
                                <h3 id="student-id" style="font-size: 20px; color: var(--secondary-color); margin: 0 0 10px 0;">ID: 0000</h3>
                                
                                <div style="display: grid; grid-template-columns: auto 1fr; gap: 8px 15px; margin-bottom: 15px; font-size: 15px;">
                                    <span style="font-weight: bold; color: var(--secondary-color);"><?php echo $lang['department']; ?>:</span>
                                    <span id="student-dept">Unknown</span>
                                    
                                    <span style="font-weight: bold; color: var(--secondary-color);">Year:</span>
                                    <span id="student-year">Unknown</span>
                                </div>

                                <!-- Status Bar -->
                                <div id="status-bar" style="padding: 10px 15px; border-radius: 6px; color: white; font-weight: bold; font-size: 18px; text-align: center; margin-top: 10px; display: inline-block; min-width: 200px;">
                                    <?php echo $lang['status']; ?>
                                </div>
                                <p id="message" style="font-size: 18px; margin-top: 10px; font-weight: bold;"></p>
                            </div>
                        </div>
                     </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Audio Element for Alerts -->
    <audio id="alert-audio" src="data:audio/wav;base64,UklGRl9vT1BXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YU..."></audio> 
    
    <style>
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>

    <script>
        const type = '<?php echo $type; ?>';
        let selectedGate = localStorage.getItem('selected_gate_<?php echo $type; ?>') || '';

        // Set initial gate selection
        if (document.getElementById('gate-selector')) {
            document.getElementById('gate-selector').value = selectedGate;
        }

        function updateGate(val) {
            selectedGate = val;
            localStorage.setItem('selected_gate_<?php echo $type; ?>', val);
        }
        
        function toggleTheme() {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
        }

        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
        }

        // Live Clock
        function updateClock() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            const formatted = now.toLocaleDateString('en-US', options);
            const el = document.getElementById('top-clock');
            if(el) el.textContent = formatted;
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Keep focus on input
        const scanInput = document.getElementById('scan-input');
        let debounceTimer;
        let resetTimer;

        function resetState() {
            document.getElementById('result-card').style.display = 'none';
            document.getElementById('result-placeholder').style.display = 'block';
            
            // Reset fields
            document.getElementById('student-photo').src = 'assets/img/default.png';
            document.getElementById('student-name').textContent = '<?php echo $lang['student_name']; ?>';
            document.getElementById('message').textContent = '';
            const statusBar = document.getElementById('status-bar');
            if(statusBar) {
                statusBar.style.backgroundColor = '';
                statusBar.textContent = '<?php echo $lang['status']; ?>';
            }
        }
        
        let isScanningPaused = false;
        
        function pauseScanning() {
            isScanningPaused = true;
        }

        function resumeScanning() {
            isScanningPaused = false;
            if (scanInput) scanInput.focus();
        }

        function checkFocus() {
            if (!isScanningPaused && scanInput) {
                scanInput.focus();
            }
        }

        if (scanInput) {
            scanInput.focus();
            document.addEventListener('click', function(e) {
                 // Don't steal focus if clicking on interactive elements or if paused
                 if (isScanningPaused) return;
                 if (e.target.tagName === 'SELECT' || e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON' || e.target.closest('a')) return;
                 
                 if(scanInput && document.activeElement !== scanInput) scanInput.focus();
            });

            scanInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    clearTimeout(debounceTimer);
                    processBarcode(this.value);
                    this.value = '';
                }
            });

            // Automatic Scan Detection
            scanInput.addEventListener('input', function(e) {
                clearTimeout(debounceTimer);
                if (this.value.length > 2) { 
                    debounceTimer = setTimeout(() => {
                         if(scanInput.value.length > 0) {
                            processBarcode(scanInput.value);
                            scanInput.value = '';
                         }
                    }, 500); // 500ms debounce
                }
            });
        }

        function processBarcode(barcode) {
            // Validate Gate Selection for Cafeteria
            if (type === 'cafeteria' && !selectedGate) {
                 alert('Please select a Cafeteria Gate first!');
                 return;
            }

            clearTimeout(resetTimer); // Cancel pending reset
            document.getElementById('loading').style.display = 'block';
            document.getElementById('result-placeholder').style.display = 'none';
            document.getElementById('result-card').style.display = 'none';
            document.getElementById('error-alert').style.display = 'none';

            const locationToSend = (type === 'cafeteria') ? selectedGate : type;

            fetch('api/process_scan', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'barcode=' + encodeURIComponent(barcode) + '&location=' + encodeURIComponent(locationToSend) + '&scanner_type=' + encodeURIComponent(type)
            })
            .then(r => r.json())
            .then(data => {
                document.getElementById('loading').style.display = 'none';
                
                // UPDATE REAL-TIME METRICS
                if (data.metrics) {
                    if(document.getElementById('stat-total-today')) document.getElementById('stat-total-today').textContent = data.metrics.total_today;
                    if(document.getElementById('stat-current-meal')) document.getElementById('stat-current-meal').textContent = data.metrics.current_meal;
                    if(document.getElementById('stat-total-eligible')) document.getElementById('stat-total-eligible').textContent = data.metrics.total_eligible;
                }

                if (data.success && data.student) {
                    // Update Info
                    document.getElementById('student-name').textContent = data.student.full_name;
                    if (document.getElementById('student-id')) {
                        document.getElementById('student-id').textContent = 'ID: ' + (data.student.student_id || barcode);
                    }
                    if (document.getElementById('student-dept')) {
                        document.getElementById('student-dept').textContent = data.student.department || 'Unknown';
                    }
                    if (document.getElementById('student-year')) {
                        document.getElementById('student-year').textContent = data.student.year_level || 'Unknown';
                    }
                    
                    document.getElementById('student-photo').src = data.student.photo_data || 'assets/img/default.png';
                    
                    // Status Logic
                    const statusBar = document.getElementById('status-bar');
                    const message = document.getElementById('message');
                    
                    // Set color based on response
                    if (data.status_color === 'green') {
                        statusBar.style.backgroundColor = 'var(--success-fill)'; // Allowed: Rich Forest
                        statusBar.textContent = "<?php echo $lang['allowed']; ?>";
                    } else if (data.status_color === 'red') {
                        statusBar.style.backgroundColor = 'var(--danger-fill)'; // Denied: Earth Clay
                        statusBar.textContent = "<?php echo $lang['denied']; ?>";
                    } else {
                        statusBar.style.backgroundColor = 'var(--warning-fill)'; // Unknown: Golden Olive
                        statusBar.textContent = "<?php echo $lang['unknown']; ?>";
                    }

                    message.textContent = data.message;
                    
                    // Enhanced duplicate meal rejection display
                    if (data.meal_served_detail) {
                        message.innerHTML = '<div style="margin-top: 5px;">' +
                            '<div style="font-size: 20px; font-weight: bold; color: var(--danger-color); margin-bottom: 8px;">' +
                                '<i class="fas fa-exclamation-triangle"></i> ' + data.message +
                            '</div>' +
                            '<div style="background: var(--warning-bg); color: var(--warning-on); padding: 10px 15px; border-radius: 6px; border-left: 4px solid var(--warning-color); margin-bottom: 8px; font-size: 16px;">' +
                                '<i class="fas fa-clock"></i> ' + data.meal_served_detail +
                            '</div>' +
                            '<div style="background: var(--danger-bg); color: var(--danger-on); padding: 8px 15px; border-radius: 6px; border-left: 4px solid var(--danger-color); font-size: 15px; font-weight: 600;">' +
                                '<i class="fas fa-ban"></i> ' + data.meal_served_warning +
                            '</div>' +
                        '</div>';
                    }
                    
                    document.getElementById('result-card').style.display = 'block';

                    // Audio Alert
                    if (data.audio_alert) {
                         try {
                             let audio = new Audio('assets/deny.mp3'); 
                             audio.play().catch(e => console.log('Audio play failed', e));
                         } catch (e) {}
                    }
                    
                    // Auto Reset Timer
                    resetTimer = setTimeout(resetState, 10000);

                } else {
                    document.getElementById('error-alert').textContent = data.message || 'Error processing scan';
                    document.getElementById('error-alert').style.display = 'block';
                    resetTimer = setTimeout(resetState, 10000);
                }
            })
            .catch(err => {
                document.getElementById('loading').style.display = 'none';
                document.getElementById('error-alert').textContent = 'Error: ' + err.message;
                document.getElementById('error-alert').style.display = 'block';
                resetTimer = setTimeout(resetState, 10000);
            });
        }

        // Live Stat Polling
        function fetchLiveStats() {
            if (type !== 'cafeteria') return;

            fetch('api/get_metrics')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.metrics) {
                        if(document.getElementById('stat-total-today')) 
                            document.getElementById('stat-total-today').textContent = data.metrics.total_today;
                        
                        if(document.getElementById('stat-current-meal')) 
                            document.getElementById('stat-current-meal').textContent = data.metrics.current_meal;
                        
                        if(document.getElementById('stat-total-eligible')) 
                            document.getElementById('stat-total-eligible').textContent = data.metrics.total_eligible;
                    }
                })
                .catch(e => console.error("Stat poll failed", e));
        }

        // Poll every 2 seconds if in cafeteria mode
        if (type === 'cafeteria') {
            setInterval(fetchLiveStats, 2000);
        }
    </script>
</body>
</html>
