<?php
/**
 * bulk_print.php — OSU One Card
 * ---------------------------------------------------------------
 * Renders every SELECTED student ID card on one printable sheet.
 *
 * Accepts:
 *   POST selected_ids[]           (from the students.php bulk form)
 *   GET  ?ids=STU001,STU002,...   (direct link / testing)
 *   POST output_mode = print | download
 *
 * Add ?debug=1 to any request to see full PHP errors on screen
 * instead of a blank 500 page.
 * ---------------------------------------------------------------
 */

/* ---------- 0. Diagnostics & limits ---------- */
$DEBUG = isset($_GET['debug']) || isset($_POST['debug']);

if ($DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Photos are BLOBs; base64-encoding a few hundred of them is memory-hungry.
@ini_set('memory_limit', '512M');
@set_time_limit(120);

/**
 * Turns any fatal into a readable page instead of a white 500 screen.
 */
function bp_fail($title, $detail = '', $showBack = true) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo "<div style='font-family:Arial,sans-serif;max-width:760px;margin:40px auto;padding:24px;"
       . "border:1px solid #6B4E3D;background:#EDE6D6;color:#6B4E3D;border-radius:8px;'>";
    echo "<h2 style='margin-top:0;'>" . htmlspecialchars($title) . "</h2>";
    if ($detail !== '') {
        echo "<pre style='white-space:pre-wrap;background:#F7F3E9;padding:12px;border-radius:6px;"
           . "font-size:13px;overflow:auto;'>" . htmlspecialchars($detail) . "</pre>";
    }
    if ($showBack) {
        echo "<p><a href='students' style='color:#144130;'>&laquo; Back to student list</a></p>";
    }
    echo "</div>";
    exit;
}

set_exception_handler(function ($e) use ($DEBUG) {
    bp_fail(
        'Bulk print failed',
        $DEBUG ? ($e->getMessage() . "\n\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString())
               : $e->getMessage() . "\n\n(Add ?debug=1 to the URL for the full trace.)"
    );
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            bp_fail('Bulk print stopped', $err['message'] . "\n" . $err['file'] . ':' . $err['line']);
        }
    }
});

/* ---------- 1. Bootstrap (same includes as students.php) ---------- */
foreach (['sessions.php', 'includes/auth-check.php', 'includes/lang_init.php'] as $inc) {
    if (!file_exists($inc)) {
        bp_fail('Missing include: ' . $inc,
                "bulk_print.php must sit in the same folder as students.php.\nCurrent working directory: " . getcwd(),
                false);
    }
    require_once $inc;
}

if (function_exists('checkAuth')) {
    checkAuth();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    bp_fail('Database connection not available',
            'The $pdo object was not created by sessions.php. Make sure bulk_print.php is reached through index.php (route: /bulk_print) rather than opened directly.');
}

/* ---------- 2. WHO MAY BULK PRINT ----------
   Edit this one list to grant or revoke the permission.
   College Registrar is excluded because that role cannot issue cards.
-------------------------------------------- */
$BULK_PRINT_ROLES = [
    'Super Admin',
    'Registrar Officer',
    'ID Card Printer',
    'Campus Admin',
];

$currentRole = $_SESSION['role'] ?? '(no role in session)';

if (!in_array($currentRole, $BULK_PRINT_ROLES, true)) {
    if (!headers_sent()) { http_response_code(403); }
    echo "<div style='font-family:Arial,sans-serif;max-width:640px;margin:40px auto;padding:24px;"
       . "border:1px solid #A79241;background:#EDE6D6;color:#0C211A;border-radius:8px;'>";
    echo "<h2 style='margin-top:0;'>Access Denied</h2>";
    echo "<p>Your role <strong>" . htmlspecialchars($currentRole) . "</strong> is not permitted to print ID cards in bulk.</p>";
    echo "<p style='font-size:13px;'>Permitted roles: " . htmlspecialchars(implode(', ', $BULK_PRINT_ROLES)) . "</p>";
    echo "<p><a href='students' style='color:#144130;'>&laquo; Back to student list</a></p>";
    echo "</div>";
    exit;
}

/* ---------- 3. Collect the requested student IDs ---------- */
$ids = [];
if (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
    $ids = $_POST['selected_ids'];
} elseif (!empty($_GET['ids'])) {
    $ids = explode(',', $_GET['ids']);
}

$ids = array_values(array_unique(array_filter(array_map('trim', $ids), 'strlen')));

if (empty($ids)) {
    bp_fail('No students selected',
            "Nothing arrived in selected_ids[].\n\n"
          . "If you did select rows, PHP's max_input_vars (default 1000) may have truncated the POST.\n"
          . "Lower the rows-per-page setting, or raise max_input_vars in php.ini.",
            true);
}

// Safety valve — one sheet of thousands of cards will exhaust memory.
$MAX_CARDS = 200;
$truncated = false;
if (count($ids) > $MAX_CARDS) {
    $ids = array_slice($ids, 0, $MAX_CARDS);
    $truncated = true;
}

$mode_in = $_POST['output_mode'] ?? $_GET['mode'] ?? 'print';
$output_mode = ($mode_in === 'download') ? 'download' : 'print';

/* ---------- 4. Fetch them, applying the SAME rules as students.php ---------- */
$in     = implode(',', array_fill(0, count($ids), '?'));
$sql    = "SELECT student_id, full_name, gender, entry_year, graduation_year, program,
                  department, year_level, enrollment_status, cafe_type, cafeteria_gate, photo
           FROM students WHERE student_id IN ($in)";
$params = $ids;

// Campus scoping
if (!empty($_SESSION['campus_id'])) {
    $sql .= " AND campus_id = ?";
    $params[] = $_SESSION['campus_id'];
}

// Graduated students never get a card
$sql .= " AND enrollment_status <> 'Graduated'";

// The ID Card Printer role may only print Active students
if ($currentRole === 'ID Card Printer') {
    $sql .= " AND enrollment_status = 'Active'";
}

$sql .= " ORDER BY department ASC, full_name ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    bp_fail('Could not load the selected students',
            $e->getMessage() . ($DEBUG ? "\n\nSQL:\n" . $sql : ''));
}

$requested = count($ids);
$issued    = count($cards);
$skipped   = $requested - $issued;

if ($issued === 0) {
    bp_fail('Nothing to print',
            "None of the $requested selected students are eligible for an ID card.\n"
          . "Graduated students are always excluded"
          . ($currentRole === 'ID Card Printer' ? ", and your role may only print students with Active status." : "."));
}

/* ---------- 5. Audit log ---------- */
if (isset($_SESSION['user_id'])) {
    try {
        $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
        $stmtLog->execute([
            $_SESSION['user_id'],
            'Bulk Print ID Cards',
            "Generated $issued ID card(s)" . ($skipped > 0 ? " ($skipped skipped)" : '')
        ]);
    } catch (Exception $e) {
        // Logging must never block printing
    }
}

/**
 * Keeps empty DB values from printing as blanks.
 */
if (!function_exists('fieldOrDash')) {
    function fieldOrDash($value) {
        $value = trim((string)$value);
        return $value === '' ? '-' : htmlspecialchars($value);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Print ID Cards (<?php echo $issued; ?>) - OSU One Card</title>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<style>
    :root {
        --osu-teal:   #144130;
        --osu-sky:    #B8A56E;
        --osu-maroon: #6B4E3D;
    }

    * { box-sizing: border-box; }

    body { margin: 0; padding: 20px; background: #EDE6D6; font-family: Arial, Helvetica, sans-serif; }

    .toolbar {
        position: sticky; top: 0; z-index: 50;
        display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
        background: #F7F3E9; padding: 12px 16px; margin-bottom: 20px;
        border-radius: 8px; box-shadow: 0 2px 6px rgba(12, 33, 26, .12);
    }
    .toolbar .info { margin-right: auto; font-size: 14px; color: #0C211A; }
    .toolbar .warn { color: #0C211A; background: #EDE6D6; padding: 6px 10px; border-radius: 5px; font-size: 13px; }
    .tb-btn {
        padding: 9px 16px; border: none; border-radius: 6px; cursor: pointer;
        font-size: 14px; font-weight: bold; color: #fff; background: var(--osu-teal);
        text-decoration: none; display: inline-block;
    }
    .tb-btn.grey { background: #3A6B5A; }
    .tb-btn:disabled { opacity: .5; cursor: not-allowed; }

    .sheet {
        display: grid;
        grid-template-columns: repeat(3, 54mm);
        gap: 8mm 8mm;
        justify-content: center;
    }

    /* CR80 / Fargo card, portrait: 54mm x 85.6mm */
    .id-card {
        width: 54mm; height: 85.6mm;
        background: #fff; border: 1px solid #A8B5A0; border-radius: 3mm;
        overflow: hidden; position: relative;
        page-break-inside: avoid; break-inside: avoid;
        display: flex; flex-direction: column;
    }

    .card-head {
        background: var(--osu-teal); color: #fff;
        padding: 2.4mm 2mm 1.8mm; display: flex; flex-direction: column; align-items: center; gap: 1mm;
        text-align: center; border-bottom: 0.7mm solid #A79241;
    }
    /* Trilingual horizontal lockup (4.73:1) spans the header width */
    .card-head img { width: 46.5mm; height: auto; display: block; }
    .card-head .sub { font-size: 5.2pt; font-weight: bold; letter-spacing: 0.25mm; line-height: 1.25; color: var(--osu-sky); }

    .card-body { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 1.6mm; padding: 2.4mm 3mm 0 3mm; }

    .photo-box {
        width: 22mm; height: 26.5mm;
        border: 0.4mm solid var(--osu-teal); border-radius: 1.2mm;
        overflow: hidden; background: #EDE6D6;
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .photo-box img { width: 100%; height: 100%; object-fit: cover; }
    .photo-box .noimg { font-size: 6pt; color: #A8B5A0; }

    .stu-name {
        font-size: 8.5pt; font-weight: bold; color: var(--osu-teal);
        line-height: 1.15; text-align: center; text-transform: uppercase;
        max-width: 100%; overflow-wrap: anywhere;
    }

    /* details on the left, QR code on the right */
    .card-foot { display: flex; align-items: flex-end; gap: 2mm; padding: 0 3mm 2mm 3mm; }
    .details { flex: 1; min-width: 0; }
    .detail-row { font-size: 5.6pt; line-height: 1.45; color: #0C211A; display: flex; gap: 1mm; }
    .detail-row .lbl { width: 11.5mm; color: #3A6B5A; flex-shrink: 0; }
    .detail-row .val { font-weight: bold; min-width: 0; overflow-wrap: anywhere; }

    .qr-col { flex-shrink: 0; display: flex; flex-direction: column; align-items: center; gap: 1mm; }
    /* QR code: holds the ID number only, as the old barcode did */
    .qr { width: 16.5mm; height: 16.5mm; background: #fff; }
    .qr img, .qr canvas { width: 100%; height: 100%; display: block; image-rendering: pixelated; }
    .cafe-tag {
        font-size: 5pt; font-weight: bold; color: #fff; text-align: center;
        background: var(--osu-maroon); padding: 0.6mm 1.5mm; border-radius: 1mm; white-space: nowrap;
        max-width: 16.5mm; overflow: hidden; text-overflow: ellipsis;
    }
    .cafe-tag.none { background: #3A6B5A; }

    @page { size: A4 portrait; margin: 12mm; }

    @media print {
        body { background: #fff; padding: 0; }
        .toolbar { display: none !important; }
        .sheet { gap: 5mm 8mm; }
        .id-card { border: 0.3mm dashed #A8B5A0; }
        /* 3 columns x 3 rows = 9 portrait cards per A4 page */
        .id-card:nth-child(9n) { page-break-after: always; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <div class="info">
        <strong><?php echo $issued; ?></strong> ID card<?php echo $issued === 1 ? '' : 's'; ?> ready
        <?php if ($skipped > 0): ?>
            &middot; <span style="color:#6B4E3D;"><?php echo $skipped; ?> skipped (not eligible)</span>
        <?php endif; ?>
        <?php if ($truncated): ?>
            <br><span class="warn">Only the first <?php echo $MAX_CARDS; ?> selected students are shown. Print the rest in a second batch.</span>
        <?php endif; ?>
    </div>
    <button type="button" class="tb-btn" onclick="window.print()">Print</button>
    <button type="button" class="tb-btn" id="pdfBtn" onclick="downloadPdf()">Download PDF</button>
    <a href="students" class="tb-btn grey">Back to list</a>
</div>

<div class="sheet" id="sheet">
<?php foreach ($cards as $stu): ?>
    <!-- =====================================================
         ONE ID CARD
         If print_id.php already has your official card design,
         paste that markup here instead. Everything else on this
         page (selection, permissions, sheet layout, PDF export)
         keeps working unchanged.
    ====================================================== -->
    <div class="id-card">
        <div class="card-head">
            <img src="assets/img/osu-lockup-trilingual-white.svg" alt="Yuunivarsiitii Naannoo Oromiyaa · ኦሮሚያ ስቴት ዩኒቨርሲቲ · Oromia State University">
            <div class="sub">STUDENT IDENTIFICATION CARD</div>
        </div>

        <div class="card-body">
            <div class="photo-box">
                <?php if (!empty($stu['photo'])): ?>
                    <img src="data:image/jpeg;base64,<?php echo base64_encode($stu['photo']); ?>" alt="">
                <?php else: ?>
                    <span class="noimg">NO PHOTO</span>
                <?php endif; ?>
            </div>
            <div class="stu-name"><?php echo fieldOrDash($stu['full_name']); ?></div>
        </div>

        <div class="card-foot">
            <div class="details">
                <div class="detail-row"><span class="lbl">ID No.</span><span class="val"><?php echo fieldOrDash($stu['student_id']); ?></span></div>
                <div class="detail-row"><span class="lbl">Department</span><span class="val"><?php echo fieldOrDash($stu['department']); ?></span></div>
                <div class="detail-row"><span class="lbl">Program</span><span class="val"><?php echo fieldOrDash($stu['program']); ?></span></div>
                <div class="detail-row"><span class="lbl">Year/Sem</span><span class="val"><?php echo fieldOrDash($stu['year_level']); ?></span></div>
                <div class="detail-row"><span class="lbl">Entry Year</span><span class="val"><?php echo fieldOrDash($stu['entry_year']); ?></span></div>
                <div class="detail-row"><span class="lbl">Valid Until</span><span class="val"><?php echo fieldOrDash($stu['graduation_year']); ?></span></div>
            </div>
            <div class="qr-col">
                <div class="qr" data-code="<?php echo htmlspecialchars($stu['student_id']); ?>"></div>
                <?php $isCafe = ($stu['cafe_type'] === 'Cafe user'); ?>
                <span class="cafe-tag <?php echo $isCafe ? '' : 'none'; ?>">
                    <?php echo $isCafe ? fieldOrDash($stu['cafeteria_gate']) : 'NON CAFE'; ?>
                </span>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<script>
    /* ---------- One QR code per card ----------
       It encodes the ID number only, so scanners type exactly what the old
       barcode did. Drawn at 4x the printed size so it stays sharp. */
    document.querySelectorAll('.qr[data-code]').forEach(function (el) {
        try {
            new QRCode(el, { text: el.dataset.code, width: 264, height: 264, correctLevel: QRCode.CorrectLevel.M });
            el.removeAttribute('title');
        } catch (e) {
            console.warn('QR code failed for', el.dataset.code, e);
        }
    });

    /* ---------- Every card into one A4 PDF ---------- */
    async function downloadPdf() {
        const btn = document.getElementById('pdfBtn');
        const cards = Array.prototype.slice.call(document.querySelectorAll('.id-card'));
        if (cards.length === 0) return;

        btn.disabled = true;
        const originalLabel = btn.textContent;

        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });

        const CARD_W = 54, CARD_H = 85.6;   // portrait CR80
        const COLS = 3, ROWS = 3, PER_PAGE = COLS * ROWS;
        const MARGIN_X = 12, MARGIN_Y = 15;
        const GAP_X = (210 - (2 * MARGIN_X) - (COLS * CARD_W)) / (COLS - 1);
        const GAP_Y = (297 - (2 * MARGIN_Y) - (ROWS * CARD_H)) / (ROWS - 1);

        for (let i = 0; i < cards.length; i++) {
            btn.textContent = 'Building ' + (i + 1) + '/' + cards.length + '...';

            // scale 4 is roughly 300 dpi on a 54mm-wide card
            const canvas = await html2canvas(cards[i], { scale: 4, backgroundColor: '#ffffff', useCORS: true });
            const img = canvas.toDataURL('image/jpeg', 0.92);

            const slot = i % PER_PAGE;
            if (i > 0 && slot === 0) pdf.addPage();

            const col = slot % COLS;
            const row = Math.floor(slot / COLS);
            pdf.addImage(img, 'JPEG',
                MARGIN_X + col * (CARD_W + GAP_X),
                MARGIN_Y + row * (CARD_H + GAP_Y),
                CARD_W, CARD_H);
        }

        pdf.save('osu-id-cards-' + new Date().toISOString().slice(0, 10) + '.pdf');

        btn.textContent = originalLabel;
        btn.disabled = false;
    }

    /* ---------- Run whichever action was clicked ---------- */
    window.addEventListener('load', function () {
        const mode = <?php echo json_encode($output_mode); ?>;
        setTimeout(function () {
            if (mode === 'download') { downloadPdf(); } else { window.print(); }
        }, 700);
    });
</script>

</body>
</html>
