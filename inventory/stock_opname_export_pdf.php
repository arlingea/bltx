<?php
/**
 * stock_opname_export_pdf.php
 * File: public_html/bltx/inventory/stock_opname_export_pdf.php
 *
 * Export PDF untuk SATU dokumen Stock Opname.
 * A4 landscape, dengan footer di setiap halaman — memakai teknik yang sama
 * persis dengan includes/export/po_export_pdf.php (page_text / page_script
 * pada canvas dompdf).
 *
 * Berbeda dengan export_stock_opname.php yang mengekspor DAFTAR dokumen
 * mengikuti filter aktif, file ini mengekspor ISI satu dokumen (header +
 * seluruh item + ringkasan variance), yaitu versi PDF dari apa yang tampil
 * di stock_opname_print.php.
 *
 * Semua asset (dompdf + font) diambil lewat BASE_PATH, bukan path hardcoded.
 */

require_once __DIR__ . '/../includes/config.php';

// ── Guard: harus login (tidak boleh diakses langsung) ───────────────────────
if (!isLoggedIn()) {
    redirect(url('index.php'));
}

require_once BASE_PATH . '/includes/global_permission.php';
require_once BASE_PATH . '/includes/inventory_function.php';

$LANG = loadLanguage($_SESSION['lang'] ?? 'en');

// ── Guard: harus punya akses modul + permission export dari database ────────
requireStockOpnameAccess();
if (!canExportStockOpname()) {
    http_response_code(403);
    exit(htmlspecialchars($LANG['export_access_denied'], ENT_QUOTES, 'UTF-8'));
}

// ── Validasi ID ─────────────────────────────────────────────────────────────
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit(htmlspecialchars($LANG['no_data'], ENT_QUOTES, 'UTF-8'));
}

$header = soGetOpnameHeader($conn, $id);
if (!$header) {
    http_response_code(404);
    exit(htmlspecialchars($LANG['so_not_found'], ENT_QUOTES, 'UTF-8'));
}

// ── Proteksi IDOR: hormati cakupan data (Own Data Only) ─────────────────────
// Dokumen milik user lain tidak boleh diekspor hanya dengan menebak ID di URL.
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!canViewAllStockOpname() && (int)$header['created_by'] !== $user_id) {
    http_response_code(404);
    exit(htmlspecialchars($LANG['so_not_found'], ENT_QUOTES, 'UTF-8'));
}

// ── Ambil detail item + ringkasan variance ──────────────────────────────────
$itemsResult = soGetOpnameItems($conn, $id);
$items = [];
if ($itemsResult) {
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = $row;
    }
}
$summary = soGetVarianceSummaries($conn, [$id]);
$sum = $summary[$id] ?? ['total_items' => 0, 'matched_count' => 0, 'short_count' => 0, 'over_count' => 0, 'total_variance_value' => 0];

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

require_once BASE_PATH . '/assets/vendor/dompdf/autoload.inc.php';

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
// Subsetting ON supaya font CJK yang besar tidak membuat file PDF ikut besar:
// hanya glyph yang benar-benar dipakai dokumen ini yang ikut disematkan.
$options->set('isFontSubsettingEnabled', true);
// dompdf hanya boleh membaca file lokal dari dalam "chroot"-nya. Default-nya
// folder vendor dompdf sendiri, yang TIDAK mencakup font CJK di bawah — jadi
// dilebarkan ke seluruh aplikasi agar registerFont() bisa membacanya.
$options->setChroot([BASE_PATH]);

$dompdf = new \Dompdf\Dompdf($options);

// Daftarkan font Noto Sans SC supaya karakter Mandarin tampil benar dan tidak
// menjadi ???? atau kotak — font bawaan PDF (DejaVu Sans) tidak punya glyph CJK.
// Jika file font tidak ada, registrasi dilewati dengan aman (jatuh ke DejaVu).
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
    // Non-fatal — PDF tetap dirender, hanya tanpa jaminan cakupan CJK.
}

/* ── Susun HTML dokumen ─────────────────────────────────────────────────────
   Semua nilai di-escape dengan htmlspecialchars(..., ENT_QUOTES, 'UTF-8')
   supaya tanda baca seperti ' " & tetap tampil apa adanya di PDF, bukan
   menjadi &amp; / &#039;. Baris baru pada catatan diubah dengan nl2br()
   sehingga tidak muncul sebagai \r\n mentah.                                */
$esc = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$opname_number = $header['opname_number'];

$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
@page { margin: 12mm 10mm 14mm 10mm; }
body { font-family: "Noto Sans SC", DejaVu Sans, Arial, sans-serif; margin:0; padding:0; color:#212529; font-size:10px; }
h1 { font-size:16px; margin:0 0 2px 0; }
.sub { color:#6c757d; font-size:10px; margin-bottom:10px; }
table { width:100%; border-collapse:collapse; margin-top:6px; }
th, td { border:1px solid #999; padding:4px 6px; font-size:9.5px; text-align:left; }
th { background:#eee; font-weight:bold; }
.text-end { text-align:right; }
.meta-table { border:0; margin-bottom:8px; }
.meta-table td { border:0; padding:2px 8px 2px 0; font-size:9.5px; vertical-align:top; }
.meta-label { color:#6c757d; font-size:8.5px; text-transform:uppercase; }
.summary td { border:0; padding:2px 14px 2px 0; font-size:9.5px; }
.notes { margin-top:10px; font-size:9.5px; }
</style></head><body>';

$html .= '<h1>' . $esc($LANG['stock_opname']) . ' — ' . $esc($opname_number) . '</h1>';
$html .= '<div class="sub">' . $esc($LANG['status']) . ': ' . $esc($LANG[$header['status']]) . '</div>';

// Header dokumen — disusun sebagai tabel karena dompdf tidak mendukung CSS grid.
$html .= '<table class="meta-table"><tr>'
       . '<td><span class="meta-label">' . $esc($LANG['date']) . '</span><br>' . $esc(date('d-m-Y', strtotime($header['opname_date']))) . '</td>'
       . '<td><span class="meta-label">' . $esc($LANG['location']) . '</span><br>' . $esc(soLocationLabel($header['location_code'], $header['location_name'])) . '</td>'
       . '<td><span class="meta-label">' . $esc($LANG['so_total_items']) . '</span><br>' . $esc(invFormatQty($sum['total_items'])) . '</td>'
       . '</tr><tr>'
       . '<td><span class="meta-label">' . $esc($LANG['created_by']) . '</span><br>' . $esc(soActorWithDate($header['created_by_name'], $header['created_at'])) . '</td>'
       . '<td><span class="meta-label">' . $esc($LANG['approved_by']) . '</span><br>' . ($header['approved_by_name'] ? $esc(soActorWithDate($header['approved_by_name'], $header['approved_at'])) : '-') . '</td>'
       . '<td><span class="meta-label">' . $esc($LANG['posted_by']) . '</span><br>' . ($header['posted_by_name'] ? $esc(soActorWithDate($header['posted_by_name'], $header['posted_at'])) : '-') . '</td>'
       . '</tr></table>';

// Ringkasan variance
$html .= '<table class="summary"><tr>'
       . '<td>' . $esc($LANG['matched']) . ': <strong>' . $esc(invFormatQty($sum['matched_count'])) . '</strong></td>'
       . '<td>' . $esc($LANG['short'])   . ': <strong>' . $esc(invFormatQty($sum['short_count']))   . '</strong></td>'
       . '<td>' . $esc($LANG['over'])    . ': <strong>' . $esc(invFormatQty($sum['over_count']))    . '</strong></td>'
       . '<td>' . $esc($LANG['so_total_variance']) . ': <strong>' . $esc(formatCurrency($sum['total_variance_value'])) . '</strong></td>'
       . '</tr></table>';

// Tabel item
$html .= '<table><thead><tr>'
       . '<th style="width:4%">'  . $esc($LANG['col_no']) . '</th>'
       . '<th style="width:12%">' . $esc($LANG['product_code']) . '</th>'
       . '<th style="width:24%">' . $esc($LANG['item_name']) . '</th>'
       . '<th style="width:7%">'  . $esc($LANG['unit']) . '</th>'
       . '<th style="width:9%" class="text-end">'  . $esc($LANG['so_system_qty']) . '</th>'
       . '<th style="width:9%" class="text-end">'  . $esc($LANG['so_physical_qty']) . '</th>'
       . '<th style="width:8%" class="text-end">'  . $esc($LANG['difference']) . '</th>'
       . '<th style="width:12%" class="text-end">' . $esc($LANG['so_difference_value']) . '</th>'
       . '<th style="width:15%">' . $esc($LANG['remarks']) . '</th>'
       . '</tr></thead><tbody>';

$no = 1;
foreach ($items as $item) {
    $counted = $item['counted_qty'] !== null;
    $html .= '<tr>'
        . '<td>' . $no++ . '</td>'
        . '<td>' . $esc($item['product_code']) . '</td>'
        . '<td>' . $esc($item['item_name']) . '</td>'
        . '<td>' . $esc($item['unit_symbol'] ?: $item['unit_name']) . '</td>'
        . '<td class="text-end">' . $esc(invFormatQty($item['system_qty'])) . '</td>'
        . '<td class="text-end">' . ($counted ? $esc(invFormatQty($item['counted_qty'])) : '-') . '</td>'
        . '<td class="text-end">' . ($counted ? $esc(invFormatQty($item['difference_qty'])) : '-') . '</td>'
        . '<td class="text-end">' . ($counted ? $esc(formatCurrency($item['difference_value'])) : '-') . '</td>'
        . '<td>' . nl2br($esc($item['remarks'])) . '</td>'
        . '</tr>';
}
$html .= '</tbody></table>';

if (!empty($header['notes'])) {
    $html .= '<div class="notes"><strong>' . $esc($LANG['notes']) . ':</strong><br>' . nl2br($esc($header['notes'])) . '</div>';
}
if ($header['status'] === 'cancelled' && !empty($header['cancel_reason'])) {
    $html .= '<div class="notes"><strong>' . $esc($LANG['so_cancel_reason']) . ':</strong><br>' . nl2br($esc($header['cancel_reason'])) . '</div>';
}

$html .= '</body></html>';

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// ── Footer di setiap halaman — teknik sama dengan po_export_pdf.php ─────────
$canvas           = $dompdf->getCanvas();
$font             = $dompdf->getFontMetrics()->getFont('Arial', 'normal');
$pageW            = $canvas->get_width();
$pageH            = $canvas->get_height();
$margin_lr_pt     = 10 * (72 / 25.4);   // 10mm → pt
$bottom_margin_pt = 14 * (72 / 25.4);   // 14mm → pt
$y_footer         = $pageH - $bottom_margin_pt + 5;

// Kiri: nomor dokumen Stock Opname
$canvas->page_text($margin_lr_pt, $y_footer, $opname_number, $font, 7, [0, 0, 0]);

// Tengah: waktu cetak — Kanan: nomor halaman
$printed_at_text = $LANG['printed_at'] . ': ' . date('d-m-Y H:i:s');
$page_label      = $LANG['page'];
$of_label        = $LANG['of'];
$right_edge      = $pageW - $margin_lr_pt;
$font_size       = 7;

$canvas->page_script(function ($pageNumber, $pageCount, $canvasObj, $fontMetrics)
    use ($font, $pageW, $right_edge, $y_footer, $font_size, $printed_at_text, $page_label, $of_label) {
        $tw_center = $fontMetrics->getTextWidth($printed_at_text, $font, $font_size);
        $x_center  = ($pageW / 2) - ($tw_center / 2);
        $canvasObj->text($x_center, $y_footer, $printed_at_text, $font, $font_size, [0, 0, 0]);

        $text_page = $page_label . ' ' . $pageNumber . ' ' . $of_label . ' ' . $pageCount;
        $tw_right  = $fontMetrics->getTextWidth($text_page, $font, $font_size);
        $x_right   = $right_edge - $tw_right;
        $canvasObj->text($x_right, $y_footer, $text_page, $font, $font_size, [0, 0, 0]);
    }
);

logActivity($user_id, 'export_pdf_stock_opname_document', "Exported Stock Opname document {$opname_number} to PDF");

$safeNumber = preg_replace('/[^A-Za-z0-9_-]/', '', $opname_number);
$filename   = 'SO_' . $safeNumber . '.pdf';
$dompdf->stream($filename, ['Attachment' => 1]);
exit;