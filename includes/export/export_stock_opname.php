<?php
/**
 * export_stock_opname.php
 * File: public_html/bltx/includes/export/export_stock_opname.php
 *
 * Export DAFTAR dokumen Stock Opname mengikuti filter yang sedang aktif di
 * halaman stock_opname.php (kini tab Stock Opname di inventory_gate.php).
 * Mendukung dua format lewat parameter ?type= :
 *
 *   type=excel  → berkas .xls (tabel HTML + BOM UTF-8)
 *   type=pdf    → berkas .pdf (dompdf, A4 landscape, footer tiap halaman)
 *
 * Berbeda dengan inventory/stock_opname_export_pdf.php yang mengekspor ISI
 * SATU dokumen (header + seluruh item), berkas ini mengekspor DAFTAR-nya:
 * satu baris per dokumen, persis seperti tabel yang tampil di layar.
 *
 * Berkas ini SEBELUMNYA TIDAK PERNAH ADA. Tombol Export Excel/PDF di halaman
 * Stock Opname sudah memanggil nama ini sejak awal, sehingga selama ini
 * menghasilkan 404. Sekarang berkas itu dibuat, dan diletakkan di
 * includes/export/ mengikuti berkas export terpusat lain yang sudah ada
 * di folder yang sama (export_inventory.php, export_purchases.php,
 * export_dreport.php, po_export_pdf.php).
 *
 * Pola yang diikuti:
 *   - Guard & permission  : inventory/stock_opname_export_pdf.php
 *   - Filter & query      : soQueryOpnames() di includes/inventory_function.php
 *                           (SATU sumber kebenaran — filter tidak ditulis ulang)
 *   - Excel               : inventory/export_inventory_out.php
 *   - PDF + footer + CJK  : inventory/stock_opname_export_pdf.php
 */

require_once __DIR__ . '/../config.php';

// ── Guard: do not access directly — harus login ─────────────────────────────
if (!isLoggedIn()) {
    redirect(url('index.php'));
}

require_once BASE_PATH . '/includes/global_permission.php';
require_once BASE_PATH . '/includes/inventory_function.php';

// Bahasa aktif. Hasil export MENGIKUTI bahasa yang sedang dipilih pengguna,
// termasuk Mandarin. TANPA fallback language.
$LANG = loadLanguage($_SESSION['lang'] ?? 'en');

// ── Guard: akses modul + izin export, dibaca langsung dari database ─────────
// requireStockOpnameAccess() menegakkan gerbang induk 'inventory' sekaligus
// modul 'stock_opname'. Superadmin selalu lolos di dalam _dbCan().
requireStockOpnameAccess();
if (!canExportStockOpname()) {
    http_response_code(403);
    exit(htmlspecialchars($LANG['export_access_denied'], ENT_QUOTES, 'UTF-8'));
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

/* ── Filter dari URL ────────────────────────────────────────────────────────
   Nama dan aturan validasinya dibuat SAMA PERSIS dengan stock_opname.php,
   sehingga hasil export selalu sama dengan yang terlihat di layar.
   Parameter 'tab' yang ikut terbawa dari form filter sengaja DIABAIKAN —
   itu hanya penanda tab, bukan filter data.                                 */
$f_opname_number = trim((string)($_GET['opname_number'] ?? ''));
$f_date_from     = soValidDate($_GET['date_from'] ?? null);
$f_date_to       = soValidDate($_GET['date_to']   ?? null);
$f_location_id   = (int)($_GET['location_id'] ?? 0);
$f_status        = (string)($_GET['status'] ?? '');
$f_created_by    = (int)($_GET['created_by'] ?? 0);

$validStatuses = ['draft', 'counting', 'review', 'approved', 'posted', 'cancelled'];
if (!in_array($f_status, $validStatuses, true)) {
    $f_status = '';
}

// Own Data Only: filter Created By DIPAKSA ke user yang sedang login, apa pun
// nilai yang dikirim lewat URL. Penegakan di server — tanpa ini, cakupan data
// bisa ditembus hanya dengan mengubah created_by di URL export.
if (!canViewAllStockOpname()) {
    $f_created_by = $user_id;
}

$filters = [
    'opname_number' => $f_opname_number,
    'date_from'     => $f_date_from,
    'date_to'       => $f_date_to,
    'location_id'   => $f_location_id,
    'status'        => $f_status,
    'created_by'    => $f_created_by,
];

/* ── Ambil data — query yang SAMA dengan halaman daftar ──────────────────── */
$result  = soQueryOpnames($conn, $filters);
$opnames = [];
$ids     = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $opnames[] = $row;
        $ids[]     = (int)$row['id'];
    }
}
$summaries = soGetVarianceSummaries($conn, $ids);

/* ── Keterangan filter untuk kepala laporan ─────────────────────────────── */
$filter_parts = [];

if ($f_date_from !== '' && $f_date_to !== '') {
    $filter_parts[] = $LANG['period'] . ': '
                    . date('d-m-Y', strtotime($f_date_from)) . ' - '
                    . date('d-m-Y', strtotime($f_date_to));
} else {
    $filter_parts[] = $LANG['period'] . ': ' . $LANG['all'];
}

if ($f_opname_number !== '') {
    $filter_parts[] = $LANG['so_opname_number'] . ': ' . $f_opname_number;
}

if ($f_location_id > 0) {
    $stmt = $conn->prepare(
        "SELECT location_code, location_name FROM warehouse_locations WHERE id = ? LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param('i', $f_location_id);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r && ($loc = $r->fetch_assoc())) {
            $filter_parts[] = $LANG['location'] . ': '
                            . $loc['location_code'] . ' - ' . $loc['location_name'];
        }
        $stmt->close();
    }
} else {
    $filter_parts[] = $LANG['location'] . ': ' . $LANG['all'];
}

$filter_parts[] = $LANG['status'] . ': '
                . ($f_status !== '' ? ($LANG[$f_status] ?? $f_status) : $LANG['all']);

if ($f_created_by > 0) {
    // Nama pembuat untuk keterangan filter diambil dari kolom NAMA PERMANEN
    // di dokumen stock opname itu sendiri, dengan cadangan tabel users bila
    // user tersebut belum pernah membuat opname. Tidak lagi memakai arsip
    // terpisah — sumbernya data permanen di baris transaksinya.
    $stmt = $conn->prepare(
        "SELECT COALESCE(
                    (SELECT so2.created_by_name FROM stock_opnames so2
                      WHERE so2.created_by = ? AND so2.created_by_name <> ''
                      ORDER BY so2.id DESC LIMIT 1),
                    (SELECT u.full_name FROM users u WHERE u.id = ? LIMIT 1)
                ) AS full_name"
    );
    if ($stmt) {
        $stmt->bind_param('ii', $f_created_by, $f_created_by);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r && ($usr = $r->fetch_assoc()) && $usr['full_name'] !== null) {
            $filter_parts[] = $LANG['created_by'] . ': ' . $usr['full_name'];
        }
        $stmt->close();
    }
} else {
    $filter_parts[] = $LANG['created_by'] . ': ' . $LANG['all'];
}

$filter_text = implode(' | ', $filter_parts);

/* ── Helper bersama ─────────────────────────────────────────────────────── */

// Escape untuk keluaran HTML (dipakai .xls maupun PDF). ENT_QUOTES + UTF-8
// supaya tanda baca ' " & tampil apa adanya dan tidak menjadi &amp; / &#039;.
$esc = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// Simbol mata uang mengikuti pengaturan database (multi currency).
$currency_symbol = getSetting('currency_symbol', 'Rp');

$money = static function ($v) use ($currency_symbol) {
    return $currency_symbol . ' ' . number_format((float)$v, 2, ',', '.');
};

// Ringkasan variance untuk satu dokumen, dengan nilai bawaan yang aman
// bila dokumen belum punya item sama sekali.
$sumOf = static function ($id) use ($summaries) {
    return $summaries[$id] ?? [
        'total_items'          => 0,
        'matched_count'        => 0,
        'short_count'          => 0,
        'over_count'           => 0,
        'total_variance_value' => 0,
    ];
};

// Judul kolom — satu daftar dipakai Excel dan PDF supaya keduanya tidak
// pernah bisa berbeda urutan atau isinya.
$columns = [
    $LANG['no'],
    $LANG['so_opname_number'],
    $LANG['date'],
    $LANG['location'],
    $LANG['so_total_items'],
    $LANG['matched'],
    $LANG['short'],
    $LANG['over'],
    $LANG['so_total_variance'],
    $LANG['created_by'],
    $LANG['status'],
];
$colCount = count($columns);

$report_title = $LANG['so_export_title'];
$type         = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : 'excel';
if (!in_array($type, ['excel', 'pdf'], true)) {
    $type = 'excel';
}

$stamp = date('Y-m-d_His');

/* ═══════════════════════════════════════════════════════════════════════════
   EXPORT EXCEL
   Memakai tabel HTML yang dibaca Excel, pola sama dengan
   inventory/export_inventory_out.php.

   BOM UTF-8 (\xEF\xBB\xBF) WAJIB dicetak lebih dulu: tanpa itu Excel membaca
   berkas sebagai ANSI dan seluruh karakter Mandarin berubah menjadi ????.
   ═══════════════════════════════════════════════════════════════════════ */
if ($type === 'excel') {

    $filename = 'stock_opname_' . $stamp . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // BOM UTF-8 — kunci agar Mandarin tidak jadi ????

    echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    echo '<table border="1">';

    echo '<tr><th colspan="' . $colCount . '" style="text-align:center;font-weight:bold;font-size:16px;">'
       . $esc($report_title) . '</th></tr>';
    echo '<tr><th colspan="' . $colCount . '" style="text-align:center;font-size:12px;">'
       . $esc($filter_text) . '</th></tr>';
    echo '<tr><th colspan="' . $colCount . '" style="text-align:center;font-size:11px;">'
       . $esc($LANG['printed_at'] . ': ' . date('d-m-Y H:i:s')) . '</th></tr>';
    echo '<tr>';
    foreach ($columns as $c) {
        echo '<th style="font-weight:bold;">' . $esc($c) . '</th>';
    }
    echo '</tr>';

    if (empty($opnames)) {
        echo '<tr><td colspan="' . $colCount . '" style="text-align:center;">'
           . $esc($LANG['no_data']) . '</td></tr>';
    } else {
        $no = 1;
        foreach ($opnames as $row) {
            $s      = $sumOf((int)$row['id']);
            $status = (string)$row['status'];

            echo '<tr>';
            echo '<td>' . $no++ . '</td>';
            // mso-number-format menahan Excel mengubah nomor dokumen menjadi
            // angka atau notasi ilmiah; nomor harus tetap apa adanya.
            echo '<td style="mso-number-format:\'\@\';">' . $esc($row['opname_number']) . '</td>';
            echo '<td>' . $esc(date('d-m-Y', strtotime((string)$row['opname_date']))) . '</td>';
            echo '<td>' . $esc(trim(($row['location_code'] ?? '') . ' - ' . ($row['location_name'] ?? ''), ' -')) . '</td>';
            echo '<td>' . (int)$s['total_items']   . '</td>';
            echo '<td>' . (int)$s['matched_count'] . '</td>';
            echo '<td>' . (int)$s['short_count']   . '</td>';
            echo '<td>' . (int)$s['over_count']    . '</td>';
            echo '<td>' . $esc($money($s['total_variance_value'])) . '</td>';
            echo '<td>' . $esc($row['created_by_name'] ?? '-') . '</td>';
            echo '<td>' . $esc($LANG[$status] ?? $status) . '</td>';
            echo '</tr>';
        }
    }

    echo '</table>';

    logActivity(
        $user_id,
        'export_excel_stock_opname_list',
        'Exported Stock Opname list to Excel (' . count($opnames) . ' documents) | ' . $filter_text
    );
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════════
   EXPORT PDF
   dompdf, A4 landscape, footer di setiap halaman — teknik page_text /
   page_script sama persis dengan inventory/stock_opname_export_pdf.php
   dan includes/export/po_export_pdf.php.
   ═══════════════════════════════════════════════════════════════════════ */

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

require_once BASE_PATH . '/assets/vendor/dompdf/autoload.inc.php';

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
// Subsetting ON supaya font CJK yang besar tidak membuat berkas PDF ikut besar.
$options->set('isFontSubsettingEnabled', true);
// dompdf hanya boleh membaca berkas lokal dari dalam "chroot"-nya; dilebarkan
// ke seluruh aplikasi agar registerFont() bisa membaca font CJK.
$options->setChroot([BASE_PATH]);

$dompdf = new \Dompdf\Dompdf($options);

// Daftarkan Noto Sans SC supaya karakter Mandarin tampil benar dan tidak
// menjadi ???? atau kotak — font bawaan PDF tidak punya glyph CJK.
// Bila berkas font tidak ada, registrasi dilewati dengan aman.
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

$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>';
$html .= '@page { margin: 12mm 10mm 14mm 10mm; }';
$html .= 'body { font-family: "Noto Sans SC", DejaVu Sans, Arial, sans-serif; margin:0; padding:0; color:#212529; font-size:10px; }';
$html .= 'h1 { font-size:16px; margin:0 0 2px 0; }';
$html .= '.sub { color:#6c757d; font-size:10px; margin-bottom:10px; }';
$html .= 'table { width:100%; border-collapse:collapse; }';
$html .= 'th, td { border:1px solid #999; padding:4px 6px; font-size:9.5px; text-align:left; }';
$html .= 'th { background:#f1f3f5; font-weight:bold; }';
$html .= 'thead { display: table-header-group; }';  // header berulang tiap halaman
$html .= 'tr { page-break-inside: avoid; }';
$html .= '.num { text-align:right; }';
$html .= '.ctr { text-align:center; }';
$html .= '</style></head><body>';

$html .= '<h1>' . $esc($report_title) . '</h1>';
$html .= '<div class="sub">' . $esc($filter_text) . '</div>';

$html .= '<table><thead><tr>';
foreach ($columns as $i => $c) {
    $cls = in_array($i, [0, 4, 5, 6, 7, 10], true) ? ' class="ctr"' : ($i === 8 ? ' class="num"' : '');
    $html .= '<th' . $cls . '>' . $esc($c) . '</th>';
}
$html .= '</tr></thead><tbody>';

if (empty($opnames)) {
    $html .= '<tr><td colspan="' . $colCount . '" class="ctr">' . $esc($LANG['no_data']) . '</td></tr>';
} else {
    $no = 1;
    foreach ($opnames as $row) {
        $s      = $sumOf((int)$row['id']);
        $status = (string)$row['status'];

        $html .= '<tr>';
        $html .= '<td class="ctr">' . $no++ . '</td>';
        $html .= '<td>' . $esc($row['opname_number']) . '</td>';
        $html .= '<td>' . $esc(date('d-m-Y', strtotime((string)$row['opname_date']))) . '</td>';
        $html .= '<td>' . $esc(trim(($row['location_code'] ?? '') . ' - ' . ($row['location_name'] ?? ''), ' -')) . '</td>';
        $html .= '<td class="ctr">' . (int)$s['total_items']   . '</td>';
        $html .= '<td class="ctr">' . (int)$s['matched_count'] . '</td>';
        $html .= '<td class="ctr">' . (int)$s['short_count']   . '</td>';
        $html .= '<td class="ctr">' . (int)$s['over_count']    . '</td>';
        $html .= '<td class="num">' . $esc($money($s['total_variance_value'])) . '</td>';
        $html .= '<td>' . $esc($row['created_by_name'] ?? '-') . '</td>';
        $html .= '<td class="ctr">' . $esc($LANG[$status] ?? $status) . '</td>';
        $html .= '</tr>';
    }
}

$html .= '</tbody></table>';
$html .= '</body></html>';

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// ── Footer di setiap halaman ───────────────────────────────────────────────
$canvas           = $dompdf->getCanvas();
$font             = $dompdf->getFontMetrics()->getFont('Arial', 'normal');
$pageW            = $canvas->get_width();
$pageH            = $canvas->get_height();
$margin_lr_pt     = 10 * (72 / 25.4);   // 10mm → pt
$bottom_margin_pt = 14 * (72 / 25.4);   // 14mm → pt
$y_footer         = $pageH - $bottom_margin_pt + 5;

// Kiri: judul laporan
$canvas->page_text($margin_lr_pt, $y_footer, $report_title, $font, 7, [0, 0, 0]);

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

logActivity(
    $user_id,
    'export_pdf_stock_opname_list',
    'Exported Stock Opname list to PDF (' . count($opnames) . ' documents) | ' . $filter_text
);

$filename = 'stock_opname_' . $stamp . '.pdf';
$dompdf->stream($filename, ['Attachment' => 1]);
exit;
