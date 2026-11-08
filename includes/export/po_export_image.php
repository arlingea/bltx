<?php
/**
 * includes/export/po_export_image.php
 * Location : public_html/bltx/includes/export/po_export_image.php
 * Handles  : JPEG export for a single Purchase Order.
 *
 * Renders the same "PO document" HTML used on po_view.php / the PDF export
 * (po_renderDocumentBody() in includes/purchase_orders_function.php) inside
 * an A4-landscape-proportioned container, then uses html2canvas (bundled at
 * assets/vendor/html2canvas/) to capture it client-side and auto-download a
 * JPEG. Capturing in the browser avoids depending on a server-side PDF-to-
 * image extension (Imagick/Ghostscript) that may not be installed on every
 * host, while still producing exactly the same layout as the PDF.
 */

require_once __DIR__ . '/../config.php';
/* PRASYARAT PENYATUAN CSRF — lihat RENCANA-PENYATUAN.md.
   Berkas ini memakai fungsi CSRF modulnya sendiri, tetapi TIDAK memuat
   includes/global_permission.php. Selama fungsi itu berdiri sendiri hal
   itu tidak masalah; begitu ia diteruskan ke bltx_csrf_*, panggilannya
   akan fatal error di sini — dan error itu baru muncul saat seseorang
   menekan tombol simpan, bukan saat deploy.
   global_permission.php aman dimuat di mana pun: isinya murni definisi
   fungsi, nol kode tingkat atas, dan semuanya berpagar function_exists. */
require_once __DIR__ . '/../global_permission.php';
require_once __DIR__ . '/../purchase_orders_function.php';

// ── Guard: must be logged in ────────────────────────────────────────────────
if (!isLoggedIn()) {
    redirect(url('index.php'));
}

// ── Guard: must have purchase_orders export permission ──────────────────────
if (!checkPermission('purchase_orders', 'can_export')) {
    http_response_code(403);
    exit(htmlspecialchars($LANG['export_access_denied']));
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit(htmlspecialchars($LANG['no_data'] ?? 'No data'));
}

$bundle = po_getDocumentBundle($conn, $id);
if (!$bundle) {
    http_response_code(404);
    exit(htmlspecialchars($LANG['po_not_found']));
}

$po_number  = $bundle['po']['po_number'];
$safeNumber = preg_replace('/[^A-Za-z0-9_-]/', '', $po_number);
$bodyHtml   = po_renderDocumentBody($bundle, 'web'); // 'web' = <img> via UPLOAD_URL (same-origin, fine for html2canvas)
$css        = po_documentCss();

if (function_exists('logActivity')) logActivity('po_export_image', 'Exported PO ' . $po_number . ' to JPEG');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars('PO ' . $po_number) ?></title>
<style>
body { background:#e9ecef; font-family: Arial, "Noto Sans SC", sans-serif; margin:0; padding:20px 12px; }
#statusBar { max-width:1122px; margin:0 auto 16px auto; padding:12px 16px; background:#fff; border-radius:8px;
             box-shadow:0 1px 3px rgba(0,0,0,.15); display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
#dlBtn { display:none; background:#198754; color:#fff; text-decoration:none; padding:6px 14px; border-radius:6px; font-size:14px; }
#captureScroll { max-width:1122px; margin:0 auto; overflow-x:auto; }
#captureArea { width:1122px; background:#fff; padding:24px; box-sizing:border-box; }
<?= $css ?>
</style>
</head>
<body>

<div id="statusBar">
    <span id="statusMsg"><?= htmlspecialchars($LANG['po_export_preparing']) ?></span>
    <a id="dlBtn" href="#" download="PO_<?= htmlspecialchars($safeNumber) ?>.jpg"><?= htmlspecialchars($LANG['export_image']) ?></a>
</div>

<div id="captureScroll">
    <div id="captureArea">
        <?= $bodyHtml ?>
    </div>
</div>

<script src="<?= url('assets/vendor/html2canvas/html2canvas.min.js') ?>"></script>
<script>
(function () {
    'use strict';
    var statusMsg = document.getElementById('statusMsg');
    var dlBtn     = document.getElementById('dlBtn');
    var target    = document.getElementById('captureArea');

    function doCapture() {
        html2canvas(target, { scale: 2, useCORS: true, backgroundColor: '#ffffff' }).then(function (canvas) {
            canvas.toBlob(function (blob) {
                if (!blob) { statusMsg.textContent = '<?= addslashes($LANG['error']) ?>'; return; }
                var blobUrl = URL.createObjectURL(blob);
                dlBtn.href = blobUrl;
                dlBtn.style.display = 'inline-block';
                statusMsg.textContent = '<?= addslashes($LANG['export_success']) ?>';

                var a = document.createElement('a');
                a.href = blobUrl;
                a.download = 'PO_<?= addslashes($safeNumber) ?>.jpg';
                document.body.appendChild(a);
                a.click();
                a.remove();
            }, 'image/jpeg', 0.92);
        }).catch(function (err) {
            statusMsg.textContent = '<?= addslashes($LANG['error']) ?>: ' + err.message;
        });
    }

    if (document.readyState === 'complete') {
        setTimeout(doCapture, 150);
    } else {
        window.addEventListener('load', function () { setTimeout(doCapture, 150); });
    }
})();
</script>
</body>
</html>
