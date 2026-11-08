<?php
/**
 * includes/export/po_export_pdf.php
 * Location : public_html/bltx/includes/export/po_export_pdf.php
 * Handles  : PDF export for a single Purchase Order.
 *
 * Renders the same "PO document" HTML used on po_view.php
 * (po_renderDocumentBody() in includes/purchase_orders_function.php) through
 * dompdf, A4 landscape, with a footer on every page — same technique as
 * includes/export/export_purchases.php (page_text/page_script on the
 * dompdf canvas).
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

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

// Load dompdf using BASE_PATH constant (no hardcoded path) — same convention
// already used by includes/export/export_purchases.php.
require_once BASE_PATH . '/assets/vendor/dompdf/autoload.inc.php';

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
// Subsetting ON (unlike export_purchases.php) so bundling a large CJK
// source font below still produces a small PDF: only the glyphs actually
// used in this PO get embedded.
$options->set('isFontSubsettingEnabled', true);
// dompdf only allows loading local files from inside its configured
// "chroot" (a security boundary against arbitrary local file access via
// crafted CSS). By default that's dompdf's own vendor folder, which does
// NOT include our bundled CJK font below — widen it to the whole app so
// registerFont() can actually read assets/vendor/dompdf/fonts/*.
$options->setChroot([BASE_PATH]);

$dompdf = new \Dompdf\Dompdf($options);

// Register the bundled Noto Sans SC font as a fallback so item names or
// notes containing Chinese characters render correctly instead of showing
// boxes — the app's default PDF font (DejaVu Sans) has no CJK glyphs.
// These are static TrueType builds (not the original CFF-outline .otf, and
// not a variable font) — dompdf's font subsetter needs plain TrueType
// (glyf) outlines to embed a font correctly; both alternatives silently
// produced unusable font subsets when tested. Registration is skipped
// safely (falls back to DejaVu Sans only) if that's ever not the case.
try {
    $cjkRegularPath = BASE_PATH . '/assets/vendor/dompdf/fonts/NotoSansSC-Regular.ttf';
    $cjkBoldPath    = BASE_PATH . '/assets/vendor/dompdf/fonts/NotoSansSC-Bold.ttf';
    if (is_file($cjkRegularPath)) {
        $dompdf->getFontMetrics()->registerFont(
            ['family' => 'Noto Sans SC', 'style' => 'normal', 'weight' => 'normal'],
            'file://' . $cjkRegularPath
        );
    }
    if (is_file($cjkBoldPath)) {
        $dompdf->getFontMetrics()->registerFont(
            ['family' => 'Noto Sans SC', 'style' => 'normal', 'weight' => 'bold'],
            'file://' . $cjkBoldPath
        );
    }
} catch (Throwable $e) {
    // Non-fatal — PDF still renders, just without guaranteed CJK coverage.
}

$po_number = $bundle['po']['po_number'];
$bodyHtml  = po_renderDocumentBody($bundle, 'pdf');
$css       = po_documentCss();

$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { margin: 12mm 10mm 14mm 10mm; }
body { font-family: "Noto Sans SC", Arial, sans-serif; margin:0; padding:0; }
' . $css . '
</style>
</head>
<body>
' . $bodyHtml . '
</body>
</html>';

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// ── Footer on every page — same technique as export_purchases.php ──────────
$canvas           = $dompdf->getCanvas();
$font             = $dompdf->getFontMetrics()->getFont('Arial', 'normal');
$pageW            = $canvas->get_width();
$pageH            = $canvas->get_height();
$margin_lr_pt     = 10 * (72 / 25.4);   // 10mm → pt
$bottom_margin_pt = 14 * (72 / 25.4);   // 14mm → pt
$y_footer         = $pageH - $bottom_margin_pt + 5;

// Left: PO reference number
$canvas->page_text($margin_lr_pt, $y_footer, $po_number, $font, 7, [0, 0, 0]);

// Center & right: printed at + page number
$printed_at_text = ($LANG['printed_at'] ?? 'Printed at') . ': ' . date('d/m/Y H:i:s');
$right_edge      = $pageW - $margin_lr_pt;
$font_size       = 7;
$canvas->page_script(function ($pageNumber, $pageCount, $canvasObj, $fontMetrics)
    use ($font, $pageW, $margin_lr_pt, $right_edge, $y_footer, $font_size, $printed_at_text) {
        $tw_center = $fontMetrics->getTextWidth($printed_at_text, $font, $font_size);
        $x_center  = ($pageW / 2) - ($tw_center / 2);
        $canvasObj->text($x_center, $y_footer, $printed_at_text, $font, $font_size, [0, 0, 0]);

        $text_page = "Page $pageNumber of $pageCount";
        $tw_right  = $fontMetrics->getTextWidth($text_page, $font, $font_size);
        $x_right   = $right_edge - $tw_right;
        $canvasObj->text($x_right, $y_footer, $text_page, $font, $font_size, [0, 0, 0]);
    }
);

$safeNumber = preg_replace('/[^A-Za-z0-9_-]/', '', $po_number);
$filename   = 'PO_' . $safeNumber . '.pdf';
$dompdf->stream($filename, ['Attachment' => 1]);

if (function_exists('logActivity')) logActivity('po_export_pdf', 'Exported PO ' . $po_number . ' to PDF');
