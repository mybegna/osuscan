<?php
require_once 'sessions.php';
require_once 'includes/auth-check.php';
checkAuth();

$personnel = null;
$error = '';

if (!in_array($_SESSION['role'], ['Super Admin', 'Campus Admin'])) {
    die("Access Denied: You cannot print labour IDs.");
}

if ($_GET['id']) {
    $stmt = $pdo->prepare("SELECT lp.*, c.campus_name FROM labour_personnel lp LEFT JOIN campuses c ON lp.campus_id = c.id WHERE lp.labour_id = ?");
    $stmt->execute([$_GET['id']]);
    $personnel = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$personnel) {
        $error = 'Personnel not found.';
    } else {
        // Enforce campus constraint for Campus Admin
        if ($_SESSION['role'] === 'Campus Admin' && $personnel['campus_id'] != $_SESSION['campus_id']) {
            die("Access Denied: You cannot print ID for personnel outside your campus.");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Labour ID Card - <?php echo $personnel ? htmlspecialchars($personnel['full_name']) : 'Error'; ?></title>
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
            background: #6B4E3D;
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
            border: 1.5px solid #6B4E3D;
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
            background: #1B4D3E;
        }
        @media print {
            body { background: white; }
            .actions { display: none; }
            .id-card { box-shadow: none; border: 1px solid #A8B5A0; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <?php if ($personnel): ?>
        <div class="id-card no-photo" id="idCard">
            <div class="header">
                <img src="assets/img/osu-lockup-trilingual-white.svg" alt="Yuunivarsiitii Naannoo Oromiyaa · ኦሮሚያ ስቴት ዩኒቨርሲቲ · Oromia State University" class="header-logo">
                <div class="logo"><span class="card-kind">Labour Identification Card</span></div>
            </div>
            <div class="content">
                <!-- No photo on a labour card: the QR code takes the photo position -->
                <div class="qr" data-code="<?php echo htmlspecialchars($personnel['labour_id']); ?>" aria-label="QR code of the ID number"></div>
                <div class="name"><?php echo htmlspecialchars($personnel['full_name']); ?></div>
            </div>
            <div class="card-bottom">
                <div class="details">
                    <div class="info"><strong>ID No:</strong> <?php echo htmlspecialchars($personnel['labour_id']); ?></div>
                    <div class="info"><strong>Sex:</strong> <?php echo htmlspecialchars($personnel['gender']); ?></div>
                    <div class="info"><strong>Work Type:</strong> <?php echo htmlspecialchars($personnel['work_type']); ?></div>
                    <div class="info"><strong>Expiry Date:</strong> <?php echo htmlspecialchars($personnel['expiry_date']); ?></div>
                    <div class="info"><strong>Campus:</strong> <?php echo htmlspecialchars($personnel['campus_name'] ?? 'Main Campus'); ?></div>
                </div>
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

            function downloadID() {
                const idCard = document.getElementById('idCard');
                html2canvas(idCard, {
                    scale: 2, // Higher resolution
                    useCORS: true,
                    backgroundColor: null
                }).then(canvas => {
                    const link = document.createElement('a');
                    link.download = '<?php echo $personnel['labour_id']; ?>_labour_id.png';
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                });
            }
        </script>
    <?php else: ?>
        <div style="text-align: center; color: #3A6B5A;">
            <h2>Personnel Not Found</h2>
            <p><?php echo htmlspecialchars($error); ?></p>
        </div>
    <?php endif; ?>
</body>
</html>
