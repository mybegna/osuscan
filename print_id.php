<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
checkAuth();

$student = null;
$error = '';

// Deny College Registrar
if ($_SESSION['role'] === 'College Registrar') {
    die("Access Denied: College Registrar cannot print IDs.");
}

if ($_GET['id']) {
    $stmt = $pdo->prepare("SELECT s.*, c.campus_name FROM students s LEFT JOIN campuses c ON s.campus_id = c.id WHERE s.student_id = ?");
    $stmt->execute([$_GET['id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        $error = 'Student not found.';
    } else {
        // Deny Graduated (ForAll)
        if ($student['enrollment_status'] === 'Graduated') {
            die("Access Denied: Cannot print ID for Graduated students.");
        }
        // Deny ID Card Printer if not Active
        if ($_SESSION['role'] === 'ID Card Printer' && $student['enrollment_status'] !== 'Active') {
            die("Access Denied: ID Card Printer can only print Active students.");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ID Card - <?php echo $student ? htmlspecialchars($student['full_name']) : 'Error'; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background: #EDE6D6;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            flex-direction: column;
            gap: 20px;
        }
        /* Portrait card: 220 x 350 px = CR80 (54 x 85.6 mm) standing on its short edge */
        .id-card {
            width: 220px;
            height: 350px;
            background: white;
            border-radius: 12px;
            position: relative;
            color: #0C211A;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .header {
            padding: 10px 8px 7px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            text-align: center;
            background: #144130;
            color: #FFFFFF;
            border-bottom: 3px solid #A79241;
        }
        /* Trilingual horizontal lockup (4.73:1) spans the header width */
        .header-logo {
            width: 190px;
            height: auto;
            display: block;
            background: transparent;
        }
        .logo {
            font-weight: bold;
            font-size: 7.5px;
            line-height: 1.25;
            text-transform: uppercase;
            background: transparent;
        }
        .logo .card-kind {
            display: block;
            letter-spacing: 1px;
            color: #B8A56E;
        }
        .content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 7px;
            padding: 10px 12px 0;
        }
        .photo-box {
            width: 90px;
            height: 108px;
            flex-shrink: 0;
            background: white;
            border-radius: 6px;
            overflow: hidden;
            border: 1.5px solid #144130;
        }
        .photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .name {
            font-size: 12.5px;
            font-weight: bold;
            line-height: 1.15;
            text-align: center;
            text-transform: uppercase;
        }
        .card-bottom {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            padding: 0 12px 12px;
        }
        .details {
            flex: 1;
            min-width: 0;
        }
        .info {
            font-size: 8.8px;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }
        /* QR code: holds the ID number exactly as the old barcode did */
        .qr {
            width: 66px;
            height: 66px;
            flex-shrink: 0;
            background: white;
        }
        .qr img,
        .qr canvas {
            width: 100%;
            height: 100%;
            display: block;
            image-rendering: pixelated;
        }
        /* Card without a photo (labour): large QR in the photo position, details below */
        .id-card.no-photo .qr {
            width: 112px;
            height: 112px;
        }
        .id-card.no-photo .card-bottom {
            padding-top: 4px;
        }
        .id-card.no-photo .info {
            font-size: 10px;
        }
        .actions {
            display: flex;
            gap: 10px;
        }
        .action-btn {
            padding: 10px 20px;
            background: #144130;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            box-shadow: 0 2px 5px rgba(12, 33, 26, 0.2);
        }
        .action-btn.download {
            background: #144130;
        }
        @media print {
            body { background: white; }
            .actions { display: none; }
            .id-card { box-shadow: none; border: 1px solid #A8B5A0; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <?php if ($student): ?>
        <div class="id-card" id="idCard">
            <div class="header">
                <img src="assets/img/osu-lockup-trilingual-white.svg" alt="Yuunivarsiitii Naannoo Oromiyaa · ኦሮሚያ ስቴት ዩኒቨርሲቲ · Oromia State University" class="header-logo">
                <div class="logo"><span class="card-kind">Student Identification Card</span></div>
            </div>
            <div class="content">
                <div class="photo-box">
                    <?php if ($student['photo']): ?>
                        <img id="studentPhoto" src="data:image/jpeg;base64,<?php echo base64_encode($student['photo']); ?>">
                    <?php else: ?>
                        <img src="assets/img/default.png" style="opacity:0.3">
                    <?php endif; ?>
                </div>
                <div class="name"><?php echo htmlspecialchars($student['full_name']); ?></div>
            </div>
            <div class="card-bottom">
                <div class="details">
                    <div class="info"><strong>ID No:</strong> <?php echo htmlspecialchars($student['student_id']); ?></div>
                    <div class="info"><strong>Sex:</strong> <?php echo htmlspecialchars($student['gender']); ?></div>
                    <div class="info"><strong>Department:</strong> <?php echo htmlspecialchars($student['department']); ?></div>
                    <div class="info"><strong>Program:</strong> <?php echo htmlspecialchars($student['program']); ?></div>
                    <div class="info"><strong>Entry Year:</strong> <?php echo htmlspecialchars($student['entry_year']); ?></div>
                    <div class="info"><strong>Campus:</strong> <?php echo htmlspecialchars($student['campus_name'] ?? 'Main Campus'); ?></div>
                </div>
                <div class="qr" data-code="<?php echo htmlspecialchars($student['student_id']); ?>" aria-label="QR code of the ID number"></div>
            </div>
        </div>

        <div class="actions">
            <button class="action-btn" onclick="window.print()">Print ID Card</button>
            <button class="action-btn download" onclick="downloadID()">Download PNG</button>
        </div>

        <script>
            /* One QR code per card. It encodes the ID number only, so scanners
               type exactly what the old barcode did. Drawn at 4x for sharp print. */
            document.querySelectorAll('.qr[data-code]').forEach(function (el) {
                try {
                    new QRCode(el, { text: el.dataset.code, width: 264, height: 264, correctLevel: QRCode.CorrectLevel.M });
                    el.removeAttribute('title');
                } catch (e) { console.warn('QR code failed for', el.dataset.code, e); }
            });

            /* =====================================================================
               AUTOMATIC PHOTO BACKGROUND REMOVAL (same engine as students.php)
               Runs on load so the on-screen card, Print, and Download PNG all
               show the cut-out. Handles plain / studio / gradient backdrops
               (incl. white). Falls back to the original photo on any problem.
            ===================================================================== */
            const BG_CORNER_TOL = 70, BG_LOCAL_TOL = 22, BG_GLOBAL_TOL = 88, BG_FEATHER = 26;

            function removeImageBackground(src) {
                return new Promise(function (resolve) {
                    const img = new Image();
                    img.onload = function () {
                        const w = img.naturalWidth, h = img.naturalHeight;
                        if (!w || !h) { resolve(src); return; }
                        const cv = document.createElement('canvas');
                        cv.width = w; cv.height = h;
                        const ctx = cv.getContext('2d');
                        ctx.drawImage(img, 0, 0);
                        let imageData;
                        try { imageData = ctx.getImageData(0, 0, w, h); } catch (e) { resolve(src); return; }
                        const d = imageData.data;
                        const refs = [];
                        [[0, 0], [w - 1, 0], [0, h - 1], [w - 1, h - 1]].forEach(function (c) {
                            const i = (c[1] * w + c[0]) * 4; refs.push([d[i], d[i + 1], d[i + 2]]);
                        });
                        function cornerDist(i) {
                            let best = Infinity;
                            for (let k = 0; k < refs.length; k++) {
                                const dr = d[i] - refs[k][0], dg = d[i + 1] - refs[k][1], db = d[i + 2] - refs[k][2];
                                const s = dr * dr + dg * dg + db * db; if (s < best) best = s;
                            }
                            return Math.sqrt(best);
                        }
                        function pixDist(i, j) {
                            const dr = d[i] - d[j], dg = d[i + 1] - d[j + 1], db = d[i + 2] - d[j + 2];
                            return Math.sqrt(dr * dr + dg * dg + db * db);
                        }
                        const state = new Uint8Array(w * h), stack = [];
                        function seed(x, y) {
                            const p = y * w + x; if (state[p]) return;
                            if (cornerDist(p * 4) <= BG_CORNER_TOL) { state[p] = 1; stack.push(p); }
                        }
                        for (let x = 0; x < w; x++) { seed(x, 0); seed(x, h - 1); }
                        for (let y = 0; y < h; y++) { seed(0, y); seed(w - 1, y); }
                        while (stack.length) {
                            const p = stack.pop(), pi = p * 4, x = p % w, y = (p / w) | 0, nb = [];
                            if (x > 0) nb.push(p - 1);
                            if (x < w - 1) nb.push(p + 1);
                            if (y > 0) nb.push(p - w);
                            if (y < h - 1) nb.push(p + w);
                            for (let n = 0; n < nb.length; n++) {
                                const q = nb[n]; if (state[q]) continue;
                                if (pixDist(pi, q * 4) <= BG_LOCAL_TOL && cornerDist(q * 4) <= BG_GLOBAL_TOL) {
                                    state[q] = 1; stack.push(q);
                                }
                            }
                        }
                        for (let p = 0; p < w * h; p++) if (state[p]) d[p * 4 + 3] = 0;
                        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
                            const p = y * w + x, i = p * 4;
                            if (d[i + 3] === 0) continue;
                            const touches =
                                (x > 0 && d[(p - 1) * 4 + 3] === 0) || (x < w - 1 && d[(p + 1) * 4 + 3] === 0) ||
                                (y > 0 && d[(p - w) * 4 + 3] === 0) || (y < h - 1 && d[(p + w) * 4 + 3] === 0);
                            if (!touches) continue;
                            const dist = cornerDist(i);
                            if (dist < BG_FEATHER) d[i + 3] = Math.max(0, Math.min(255, Math.round((dist / BG_FEATHER) * 255)));
                        }
                        ctx.putImageData(imageData, 0, 0);
                        resolve(cv.toDataURL('image/png'));
                    };
                    img.onerror = function () { resolve(src); };
                    img.src = src;
                });
            }

            // Apply on load, before the user prints or downloads
            (async function () {
                const im = document.getElementById('studentPhoto');
                if (!im) return;
                try {
                    const out = await removeImageBackground(im.getAttribute('src'));
                    await new Promise(function (r) { im.onload = r; im.onerror = r; im.src = out; });
                } catch (e) { /* keep original photo */ }
            })();

            function downloadID() {
                const idCard = document.getElementById('idCard');
                html2canvas(idCard, {
                    scale: 2, // Higher resolution
                    useCORS: true,
                    backgroundColor: null
                }).then(canvas => {
                    const link = document.createElement('a');
                    link.download = '<?php echo $student['student_id']; ?>_id_card.png';
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                });
            }
        </script>
    <?php else: ?>
        <div style="text-align: center; color: #3A6B5A;">
            <h2>Student Not Found</h2>
            <p><?php echo htmlspecialchars($error); ?></p>
        </div>
    <?php endif; ?>
</body>
</html>
