<?php
/**
 * File   : inventory/print_barcode.php
 * Purpose: Render Code128B barcode label(s) and trigger browser print dialog.
 * Printer: Gainscha GA-E250I — 203 DPI, 80mm roll, 72mm printable width.
 * Paper  : 80mm × 50mm thermal label.
 * Notes  :
 *   – Barcode generated server-side (pure PHP, no GD, no CDN, no library).
 *   – Page opened as new browser tab (_blank) — not blocked by Chrome.
 *   – `copies` param renders N identical label pages for multi-copy print.
 *   – window.print() fires automatically after layout; user just picks
 *     printer and clicks OK in the OS print dialog.
 *   – FIX: @page includes orientation:portrait to always force portrait.
 *   – FIX: Direct access guard — requires active session login.
 *   – FIX: Path resolution via BASE_PATH constant (no hardcoded __DIR__/../).
 *   – FIX: canPrintInventoryBarcode() sourced from global_permission.php.
 */

// ── Direct Access Guard ─────────────────────────────────────────────────────
// Halaman ini tidak boleh diakses langsung tanpa sesi login.
// BASE_PATH harus didefinisikan di config.php (contoh: define('BASE_PATH', dirname(__DIR__));)
// Jika diakses langsung tanpa config.php, define BASE_PATH dari lokasi file ini.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// ── Core ───────────────────────────────────────────────────────────────────
require_once BASE_PATH . '/includes/config.php';
require_once BASE_PATH . '/includes/global_permission.php';

// ── Guards ─────────────────────────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(403);
    exit('403 – Access denied. Please log in.');
}
if (!canPrintInventoryBarcode()) {
    http_response_code(403);
    exit('403 – You do not have permission to print barcodes.');
}

// ── Input ──────────────────────────────────────────────────────────────────
$raw_sku  = isset($_GET['sku'])    ? trim($_GET['sku'])    : '';
$raw_item = isset($_GET['item'])   ? trim($_GET['item'])   : '';
$copies   = isset($_GET['copies']) ? (int)$_GET['copies']  : 1;
$copies   = max(1, min(99, $copies));

if ($raw_sku !== '' && !preg_match('/^[A-Za-z0-9\-_.\/\s]+$/', $raw_sku)) {
    http_response_code(400);
    exit('400 – Invalid SKU format.');
}

$sku       = htmlspecialchars($raw_sku,  ENT_QUOTES, 'UTF-8');
$item_name = htmlspecialchars($raw_item, ENT_QUOTES, 'UTF-8');

// ── Language ───────────────────────────────────────────────────────────────
$lang_code = getSetting('language', 'en');
$LANG      = [];
$lang_file = BASE_PATH . '/languages/' . $lang_code . '.php';
if (file_exists($lang_file)) { require $lang_file; }

// ── Company name ───────────────────────────────────────────────────────────
$company_name = getSetting('company_name', '');

// ══════════════════════════════════════════════════════════════════════════════
//  PURE-PHP CODE128B BARCODE ENCODER → SVG
//  ISO/IEC 15417.  No GD, no Imagick, no CDN, no composer package.
//  Compatible with PHP 7.4+.
// ══════════════════════════════════════════════════════════════════════════════
function makeBarcode128(
    string $data,
    int    $mod_w = 2,
    int    $bar_h = 80,
    string $fg    = '#000000',
    string $bg    = '#ffffff'
): string {
    // 106 symbol patterns (11 modules each).  Index = Code128 value.
    static $SYM = [
        '11011001100','11001101100','11001100110','10010011000','10010001100',
        '10001001100','10011001000','10011000100','10001100100','11001001000',
        '11001000100','11000100100','10110011100','10011011100','10011001110',
        '10111001100','10011101100','10011100110','11001110010','11001011100',
        '11001001110','11011100100','11001110100','11101101110','11101001100',
        '11100101100','11100100110','11101100100','11100110100','11100110010',
        '11011011000','11011000110','11000110110','10100011000','10001011000',
        '10001000110','10110001000','10001101000','10001100010','11010001000',
        '11000101000','11000100010','10110111000','10110001110','10001101110',
        '10111011000','10111000110','10001110110','11101110110','11010001110',
        '11000101110','11011101000','11011100010','11011101110','11101011000',
        '11101000110','11100010110','11101101000','11101100010','11100011010',
        '11101111010','11001000010','11110001010','10100110000','10100001100',
        '10010110000','10010000110','10000101100','10000100110','10110010000',
        '10110000100','10011010000','10011000010','10000110100','10000110010',
        '11000010010','11001010000','11110111010','11000010100','10001111010',
        '10100111100','10010111100','10010011110','10111100100','10011110100',
        '10011110010','11110100100','11110010100','11110010010','11011011110',
        '11011110110','11110110110','10101111000','10100011110','10001011110',
        '10111101000','10111100010','11110101000','11110100010','10111011110',
        '10111101110','11101011110','11110101110','11010000100','11010010000',
        '11010011100',
    ];
    $START = '11010010000';    // START Code B (value 104)
    $STOP  = '1100011101011';  // STOP  (13 modules)

    // Build code values
    $vals  = [];
    $cksum = 104;
    $len   = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $a = ord($data[$i]);
        if ($a < 32 || $a > 127) { $a = 63; }
        $v       = $a - 32;
        $vals[]  = $v;
        $cksum  += $v * ($i + 1);
    }
    $vals[] = $cksum % 103;

    // Build bit string with 10-module quiet zones (ISO 15417 §4.4.1)
    $bits = str_repeat('0', 10) . $START;
    foreach ($vals as $v) { $bits .= $SYM[$v]; }
    $bits .= $STOP . str_repeat('0', 10);

    // RLE → SVG <rect> elements (bars only; background rect covers spaces)
    $total_w = strlen($bits) * $mod_w;
    $rects   = '';
    $n = strlen($bits);
    $i = 0;
    while ($i < $n) {
        $b = $bits[$i]; $run = 1;
        while ($i + $run < $n && $bits[$i + $run] === $b) { $run++; }
        if ($b === '1') {
            $rects .= '<rect x="' . ($i * $mod_w) . '" y="0" width="'
                    . ($run * $mod_w) . '" height="' . $bar_h
                    . '" fill="' . $fg . '"/>';
        }
        $i += $run;
    }

    $esc = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return '<svg xmlns="http://www.w3.org/2000/svg"'
         . ' width="'   . $total_w . '"'
         . ' height="'  . $bar_h   . '"'
         . ' viewBox="0 0 ' . $total_w . ' ' . $bar_h . '"'
         . ' role="img" aria-label="' . $esc . '">'
         . '<rect width="' . $total_w . '" height="' . $bar_h . '" fill="' . $bg . '"/>'
         . $rects
         . '</svg>';
}

// ── Generate SVG ───────────────────────────────────────────────────────────
$barcode_svg = '';
$barcode_ok  = false;
$barcode_err = '';

if ($raw_sku !== '') {
    try {
        // mod_w=2 → 2 dots per module at 203dpi = 0.25mm (GS1 minimum)
        // bar_h=80 SVG px; CSS scales to ~20mm during print
        $barcode_svg = makeBarcode128($raw_sku, 2, 80);
        $barcode_ok  = true;
    } catch (Throwable $e) {
        $barcode_err = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}

// ── Pre-render label HTML fragment (reused N times for copies) ────────────
function labelHtml(
    string $sku, string $item_name, string $company_name,
    string $barcode_svg, bool $barcode_ok, string $barcode_err,
    array  $LANG
): string {
    $out = '<div class="label">';

    if ($company_name !== '') {
        $out .= '<div class="lbl-company">'
              . htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8')
              . '</div>';
    }
    if ($item_name !== '') {
        $out .= '<div class="lbl-name">' . $item_name . '</div>';
    }
    if ($barcode_ok) {
        $out .= '<div class="lbl-barcode">' . $barcode_svg . '</div>';
        $out .= '<div class="lbl-sku">' . $sku . '</div>';
    } elseif ($sku === '') {
        $out .= '<div class="lbl-err">'
              . ($LANG['barcode_not_available'])
              . '</div>';
    } else {
        $out .= '<div class="lbl-err">Barcode error'
              . ($barcode_err ? ': ' . $barcode_err : '') . '</div>';
    }

    $out .= '</div>';
    return $out;
}

$label_html = labelHtml(
    $sku, $item_name, $company_name,
    $barcode_svg, $barcode_ok, $barcode_err,
    $LANG
);
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang_code, ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<!-- FIX: maximum-scale=1.0 mencegah browser mobile auto-zoom yang merusak layout print -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?php echo $LANG['print_barcode_title']; ?> — <?php echo $sku; ?></title>
<style>
/* ════════════════════════════════════════════════════════
   RESET
════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* ════════════════════════════════════════════════════════
   SCREEN — preview + info
════════════════════════════════════════════════════════ */
body {
    background: #dee2e6;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 13px;
    color: #212529;
    padding: 20px 16px 40px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    min-height: 100vh;
}

.page-title {
    font-size: 13px;
    font-weight: 700;
    color: #495057;
    display: flex;
    align-items: center;
    gap: 6px;
}

.info-bar {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 5px;
    padding: 8px 14px;
    font-size: 12px;
    color: #664d03;
    max-width: 380px;
    text-align: center;
    line-height: 1.6;
}

/* Preview label card (screen only, hidden during print) */
.preview-card {
    background: #fff;
    border: 2px dashed #adb5bd;
    border-radius: 6px;
    padding: 8px;
    box-shadow: 0 3px 14px rgba(0,0,0,.14);
}

/*
 * Label dimensions — screen preview at 96 dpi:
 *   80mm → 302px   50mm → 189px
 */
.label {
    width:  302px;
    height: 189px;
    background: #fff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 5px 7px 4px;
    gap: 2px;
    overflow: hidden;
}
.lbl-company {
    font-size: 7px; color: #666;
    text-transform: uppercase; letter-spacing: .9px;
    width: 100%; text-align: center;
}
.lbl-name {
    font-size: 9px; font-weight: 700;
    text-align: center; width: 100%; line-height: 1.3;
    display: -webkit-box; -webkit-line-clamp: 2;
    -webkit-box-orient: vertical; overflow: hidden;
}
.lbl-barcode {
    display: flex; align-items: center;
    justify-content: center; width: 100%;
}
.lbl-barcode svg {
    width: 100%; max-width: 286px; height: auto;
    display: block; image-rendering: pixelated;
}
.lbl-sku {
    font-family: 'Courier New', Courier, monospace;
    font-size: 8px; letter-spacing: 1.4px;
    text-align: center; color: #000; width: 100%;
}
.lbl-err {
    color: #dc3545; font-size: 10px;
    text-align: center; padding: 10px 0;
}

/* Copies badge */
.copies-badge {
    font-size: 12px; color: #495057;
    background: #e9ecef; border-radius: 20px;
    padding: 3px 12px;
}

/* Buttons */
.btn-row { display:flex; gap:10px; flex-wrap:wrap; justify-content:center; }
.btn {
    padding: 9px 22px; border: none; border-radius: 5px;
    cursor: pointer; font-size: 13px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 7px;
}
.btn:hover { filter: brightness(.9); }
.btn-print  { background: #0d6efd; color: #fff; }
.btn-close  { background: #6c757d; color: #fff; }

.hint {
    font-size: 11px; color: #6c757d;
    text-align: center; max-width: 360px; line-height: 1.65;
}

/* ════════════════════════════════════════════════════════
   PRINT — Gainscha GA-E250I | 203 DPI | 80 × 50 mm
   Driver confirmed: T203DPIv1x64.INF
   Printable width: 72mm = 576 dots @ 203dpi
   Module width   : 2 dots = 0.25mm (GS1 spec minimum)
   Bar height     : ~20mm
════════════════════════════════════════════════════════ */
@media print {

    /* ── FIX: Force portrait orientation on all browsers ── */
    /* Chrome, Edge, Firefox: orientation dalam @page */
    @page {
        size:        80mm 50mm portrait;
        orientation: portrait;
        margin:      0;
    }

    /* Remove everything from screen layout */
    body {
        background: #fff !important;
        padding: 0 !important;
        margin: 0 !important;
        display: block !important;
        min-height: unset !important;
        overflow: visible !important;
    }

    /* Hide all screen-only elements */
    .page-title,
    .info-bar,
    .preview-card,
    .copies-badge,
    .btn-row,
    .hint,
    .screen-only {
        display: none !important;
    }

    /* ── Each .print-page = exactly one 80×50mm label ── */
    .print-page {
        display: flex !important;
        width:  80mm;
        height: 50mm;
        page-break-after: always;
        break-after: page;
        overflow: hidden;
    }

    /* Last page: no trailing blank page */
    .print-page:last-child {
        page-break-after: avoid;
        break-after: avoid;
    }

    .print-page .label {
        width:  80mm !important;
        height: 50mm !important;
        padding: 2mm 4mm 1.5mm !important;
        gap: 0.8mm !important;
    }

    .print-page .lbl-company { font-size: 5.5pt !important; }
    .print-page .lbl-name    { font-size: 7.5pt !important; -webkit-line-clamp: 2 !important; }
    .print-page .lbl-sku     { font-size: 6.5pt !important; letter-spacing: 1px !important; }

    /*
     * Barcode SVG width = 72mm (printable area of 80mm roll).
     * viewBox auto-scales: each 2-px module → 2 dots at 203dpi = 0.25mm.
     * image-rendering:pixelated keeps edges sharp — no anti-alias blur.
     */
    .print-page .lbl-barcode svg {
        width:  72mm !important;
        height: auto !important;
        max-width: 72mm !important;
        image-rendering: pixelated !important;
        image-rendering: crisp-edges !important;
    }
}

/* .print-page hidden on screen */
.print-page {
    display: none;
}
</style>
</head>
<body>

<!-- ── Screen: title ─────────────────────────────────────────── -->
<div class="page-title screen-only">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
        <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1"/>
        <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2zm3 6a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1z"/>
    </svg>
    <?php echo $LANG['print_barcode_title']; ?>
</div>

<?php if ($barcode_ok): ?>

<!-- ── Screen: info bar ──────────────────────────────────────── -->
<div class="info-bar screen-only">
    <?php echo $LANG['print_dialog_printer_note']; ?>
</div>

<!-- ── Screen: label preview (1 copy shown) ─────────────────── -->
<div class="preview-card screen-only">
    <?php echo $label_html; ?>
</div>

<!-- ── Copies badge ──────────────────────────────────────────── -->
<div class="copies-badge screen-only">
    <?php
    $copies_label = $LANG['copies'];
    echo htmlspecialchars($copies_label, ENT_QUOTES, 'UTF-8') . ': ' . $copies;
    ?>
</div>

<!-- ── Buttons ───────────────────────────────────────────────── -->
<div class="btn btn-sm-row screen-only">
    <button class="btn btn-sm btn-print" onclick="doPrint()">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
            <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1"/>
            <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2zm3 6a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1z"/>
        </svg>
        <?php echo $LANG['print_barcode']; ?>
    </button>
    <button class="btn btn-close" onclick="window.close()">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
            <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>
        </svg>
        <?php echo $LANG['close']; ?>
    </button>
</div>

<p class="hint screen-only">
    <?php echo $LANG['print_ready']; ?><br>
    <span style="font-size:10px">
        <?php
        // Windows 11 setup tip
        echo $LANG['win11_paper_tip'];
        ?>
    </span>
</p>

<!-- ════════════════════════════════════════════════════════════
     PRINT PAGES — N copies, each in its own .print-page div.
     Each div = 1 physical label = @page 80×50mm portrait.
     page-break-after:always forces a new page per copy.
════════════════════════════════════════════════════════════ -->
<?php for ($c = 1; $c <= $copies; $c++): ?>
<div class="print-page">
    <?php echo $label_html; ?>
</div>
<?php endfor; ?>

<script>
/**
 * Auto-open the OS print dialog.
 * Delay 500ms: lets browser finish rendering all N label pages
 * so @page size is applied correctly before the print snapshot.
 * Compatible: Chrome, Firefox, Edge, Safari.
 *
 * FIX: Sebelum print, inject <style> untuk memastikan portrait orientation
 * terbaca oleh semua browser (workaround Chrome bug pada @page orientation).
 */
window.addEventListener('load', function () {
    // FIX: Inject runtime portrait style sebagai fallback tambahan untuk Chrome
    var styleEl = document.createElement('style');
    styleEl.textContent = '@page { size: 80mm 50mm portrait; orientation: portrait; margin: 0; }';
    document.head.appendChild(styleEl);

    setTimeout(function () { window.print(); }, 500);
});

function doPrint() { window.print(); }
</script>

<?php else: /* no barcode */ ?>

<div class="preview-card screen-only">
    <?php echo $label_html; ?>
</div>
<div class="btn btn-sm-row screen-only">
    <button class="btn btn-close" onclick="window.close()">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
            <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>
        </svg>
        <?php echo $LANG['close']; ?>
    </button>
</div>

<?php endif; ?>

</body>
</html>