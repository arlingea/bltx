<?php
// File: arw_pos/manual_dreport_view.php
// Called via AJAX (X-Requested-With: XMLHttpRequest) — returns HTML fragment only.
// Direct access is blocked.

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/manual_dreport_functions.php';
/* PRASYARAT PENYATUAN CSRF — lihat RENCANA-PENYATUAN.md.
   Berkas ini memakai fungsi CSRF modulnya sendiri; begitu fungsi itu
   diteruskan ke bltx_csrf_*, includes/global_permission.php harus termuat.

   DIMUAT SETELAH berkas fungsi modul, dan itu WAJIB. Kedua berkas sama-sama
   mendefinisikan requireYesterdayCashViewAccess(), tetapi versi di
   manual_dreport_functions.php TIDAK berpagar function_exists. Kalau
   global_permission.php dimuat lebih dulu, definisi keduanya bentrok dan PHP
   berhenti dengan 'Cannot redeclare' -> HTTP 500.

   Urutan ini juga MENJAGA PERILAKU LAMA: implementasi yang menang tetap milik
   manual_dreport_functions.php (memakai _getDreportPerm()), bukan milik
   global_permission.php (memakai checkPermission()). Keduanya tidak identik,
   jadi pemenangnya tidak boleh berubah.

   Urutan tidak mempengaruhi ketersediaan bltx_csrf_*: PHP menyelesaikan
   panggilan fungsi saat dijalankan, bukan saat di-include. */
require_once __DIR__ . '/../includes/global_permission.php';

// Require login & dreport access
requireDreportAccess();

// Must be AJAX request
if (
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    exit('Forbidden');
}

$id   = (int)($_GET['id'] ?? 0);
$LANG = loadLanguage($_SESSION['lang'] ?? 'en');

if ($id <= 0) {
    http_response_code(400);
    echo '<div class="alert alert-danger m-3"><i class="bi bi-exclamation-triangle me-2"></i>'
        . htmlspecialchars($LANG['no_data']) . '</div>';
    exit;
}

$data = getDreportViewData($id);

if (!$data) {
    http_response_code(404);
    echo '<div class="alert alert-warning m-3"><i class="bi bi-exclamation-triangle me-2"></i>'
        . htmlspecialchars($LANG['no_data']) . '</div>';
    exit;
}

$r     = $data['report'];
$items = $data['items'];
$urls  = $data['urls'];

$diff      = (float)($r['difference']     ?? 0);
$sysRev    = (float)($r['system_revenue'] ?? 0);
$manual    = (float)($r['total_manual']   ?? 0);
$ycTotal   = (float)($r['yesterday_cash_total']   ?? 0);
$ycUsage   = (float)($r['yesterday_cash_usage']   ?? 0);
$ycBalance = (float)($r['yesterday_cash_balance'] ?? 0);

$imgFile   = $r['image_file']           ?? '';
$ycImg     = $r['yesterday_cash_image'] ?? '';
$rposImg   = $r['rpos_image']           ?? '';
$notes     = trim($r['notes']           ?? '');
$notesCash = trim($r['notes_cash']      ?? '');

// Format date safely
$reportDateFmt = '-';
if (!empty($r['report_date'])) {
    $dt = \DateTime::createFromFormat('Y-m-d', $r['report_date']);
    $reportDateFmt = $dt ? $dt->format('d/m/Y') : htmlspecialchars($r['report_date']);
}

// Diff CSS class & label
$diffClass = $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : 'text-muted');
$diffLabel = $diff > 0
    ? '+' . dreportFormatMoney($diff)
    : ($diff < 0 ? dreportFormatMoney($diff) : dreportFormatMoney(0));

$ycBalClass = $ycBalance > 0 ? 'text-success' : ($ycBalance < 0 ? 'text-danger' : 'text-muted');

// Helper: render photo block — klik gambar memanggil openPhotoModal() di parent page (manual_dreport.php)
// Sama persis dengan perilaku klik gambar di tabel utama: lightbox dengan zoom & drag.
// $type: 'summary' | 'cash' | 'rpos'  — harus cocok dengan switch di openPhotoModal().
// $baseUrl: URL direktori upload dari $urls array — diisi langsung ke src agar gambar tampil segera.
function _viewPhotoBlock(string $filename, string $type, string $label, string $baseUrl): string {
    if ($filename === '') {
        return '<p class="text-muted small mb-0"><i class="bi bi-image me-1"></i>'
            . htmlspecialchars($label) . ': -</p>';
    }
    // encodedFilename sama dengan yang dipakai di tabel: rawurlencode, lalu addslashes untuk JS string
    $encodedJs  = addslashes(rawurlencode($filename));
    $typeJs     = htmlspecialchars($type, ENT_QUOTES);
    $labelEsc   = htmlspecialchars($label, ENT_QUOTES);
    // src diisi langsung dari PHP agar gambar tampil segera tanpa menunggu JS
    $srcUrl     = htmlspecialchars($baseUrl . rawurlencode($filename), ENT_QUOTES);
    return '<a href="#" title="' . $labelEsc . '"'
        . ' onclick="openPhotoModal(\'' . $encodedJs . '\',\'' . $typeJs . '\');return false;">'
        . '<img src="' . $srcUrl . '"'
        . ' data-encoded="' . htmlspecialchars(rawurlencode($filename), ENT_QUOTES) . '"'
        . ' data-type="' . htmlspecialchars($type, ENT_QUOTES) . '"'
        . ' alt="' . $labelEsc . '"'
        . ' class="dreport-view-thumb" loading="lazy">'
        . '</a>';
}
?>
<div class="row g-3">

    <!-- Row 1: Date, Cashier, Shift -->
    <div class="col-sm-4">
        <span class="text-muted small d-block"><?= htmlspecialchars($LANG['date']) ?></span>
        <div class="fw-semibold"><?= $reportDateFmt ?></div>
    </div>
    <div class="col-sm-4">
        <span class="text-muted small d-block"><?= htmlspecialchars($LANG['cashier_name']) ?></span>
        <div class="fw-semibold"><?= htmlspecialchars($r['cashier_name'] ?? '-') ?></div>
    </div>
    <div class="col-sm-4">
        <span class="text-muted small d-block"><?= htmlspecialchars($LANG['shifts']) ?></span>
        <div>
            <span class="badge bg-info text-dark"><?= htmlspecialchars($r['shift_name'] ?? '-') ?></span>
        </div>
    </div>

    <!-- Row 2: Payment Breakdown table — style shifts.php (table-hover table-sm, no thead, no table-bordered) -->
    <!-- Baris pertama tetap: System Revenue                                                               -->
    <!-- Baris tengah: payment method items                                                                -->
    <!-- Baris terakhir tetap: Manual Total + Difference                                                   -->
    <div class="col-12">
        <span class="text-muted small d-block mb-1"><?= htmlspecialchars($LANG['payment_breakdown']) ?></span>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <tbody>
                    <!-- Baris pertama tetap: System Revenue -->
                    <tr class="dreport-view-row-fixed dreport-view-row-first">
                        <td class="fw-semibold"><?= htmlspecialchars($LANG['system_revenue']) ?></td>
                        <td class="text-end fw-semibold"><?= dreportFormatMoney($sysRev) ?></td>
                    </tr>
                    <!-- Baris tengah: payment method items -->
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="2" class="text-center text-muted fst-italic">-</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars($it['method_name'] ?? '-') ?></td>
                            <td class="text-end"><?= dreportFormatMoney((float)($it['amount'] ?? 0)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <!-- Baris terakhir tetap: Manual Total -->
                    <tr class="dreport-view-row-fixed">
                        <td class="fw-semibold"><?= htmlspecialchars($LANG['total_manual']) ?></td>
                        <td class="text-end fw-semibold"><?= dreportFormatMoney($manual) ?></td>
                    </tr>
                    <!-- Baris terakhir tetap: Difference -->
                    <tr class="dreport-view-row-fixed dreport-view-row-last">
                        <td class="fw-semibold"><?= htmlspecialchars($LANG['difference']) ?></td>
                        <td class="text-end fw-bold <?= $diffClass ?>"><?= $diffLabel ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Judul "Summary" -->
    <div class="col-12">
        <span class="text-muted small fw-semibold d-block mb-2">
            <i class="bi bi-bar-chart-fill me-1"></i><?= htmlspecialchars($LANG['summary']) ?>
        </span>
    </div>

    <!-- Row 3: Summary cards — urutan: System Revenue, Manual Total, Difference (poin 5) -->
    <div class="col-sm-4">
        <div class="card border-0 bg-light">
            <div class="card-body py-2 px-3 text-center">
                <div class="text-muted small"><?= htmlspecialchars($LANG['system_revenue']) ?></div>
                <div class="fw-bold"><?= dreportFormatMoney($sysRev) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 bg-light">
            <div class="card-body py-2 px-3 text-center">
                <div class="text-muted small"><?= htmlspecialchars($LANG['total_manual']) ?></div>
                <div class="fw-bold"><?= dreportFormatMoney($manual) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 bg-light">
            <div class="card-body py-2 px-3 text-center">
                <div class="text-muted small"><?= htmlspecialchars($LANG['difference']) ?></div>
                <div class="fw-bold <?= $diffClass ?>"><?= $diffLabel ?></div>
            </div>
        </div>
    </div>

    <!-- Notes of Daily Reports — selalu tampil di bawah Summary -->
    <div class="col-12">
        <span class="text-muted small d-block"><?= htmlspecialchars($LANG['notes_daily_report']) ?></span>
        <div><?= $notes !== '' ? nl2br(htmlspecialchars($notes)) : '<span class="text-muted fst-italic">-</span>' ?></div>
    </div>

    <!-- Divider -->
    <div class="col-12"><hr class="my-1"></div>

    <!-- Row 4: Yesterday Cash — tampilan kartu sama dengan Summary (poin 6) -->
    <div class="col-12">
        <span class="text-muted small fw-semibold d-block mb-2">
            <i class="bi bi-cash-coin me-1"></i><?= htmlspecialchars($LANG['yesterday_cash_section']) ?>
        </span>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 bg-light">
            <div class="card-body py-2 px-3 text-center">
                <div class="text-muted small"><?= htmlspecialchars($LANG['yesterday_cash_total']) ?></div>
                <div class="fw-bold"><?= dreportFormatMoney($ycTotal) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 bg-light">
            <div class="card-body py-2 px-3 text-center">
                <div class="text-muted small"><?= htmlspecialchars($LANG['yesterday_cash_usage']) ?></div>
                <div class="fw-bold"><?= dreportFormatMoney($ycUsage) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 bg-light">
            <div class="card-body py-2 px-3 text-center">
                <div class="text-muted small"><?= htmlspecialchars($LANG['yesterday_cash_balance']) ?></div>
                <div class="fw-bold <?= $ycBalClass ?>"><?= dreportFormatMoney(abs($ycBalance)) ?></div>
            </div>
        </div>
    </div>

    <!-- Notes of Cash — selalu tampil di bawah Yesterday Cash -->
    <div class="col-12">
        <span class="text-muted small d-block"><?= htmlspecialchars($LANG['notes_cash']) ?></span>
        <div><?= $notesCash !== '' ? nl2br(htmlspecialchars($notesCash)) : '<span class="text-muted fst-italic">-</span>' ?></div>
    </div>

    <!-- Divider -->
    <div class="col-12"><hr class="my-1"></div>

    <!-- Row 5: Photos -->
    <div class="col-12">
        <span class="text-muted small fw-semibold d-block mb-2">
            <i class="bi bi-images me-1"></i><?= htmlspecialchars($LANG['photo']) ?>
        </span>
    </div>

    <!-- Summary Photo -->
    <div class="col-sm-4">
        <span class="text-muted small d-block mb-1"><?= htmlspecialchars($LANG['summary_photo']) ?></span>
        <?= _viewPhotoBlock($imgFile, 'summary', $LANG['summary_photo'], $urls['summary']) ?>
    </div>

    <!-- Yesterday Cash Photo -->
    <div class="col-sm-4">
        <span class="text-muted small d-block mb-1"><?= htmlspecialchars($LANG['yesterday_cash_image']) ?></span>
        <?= _viewPhotoBlock($ycImg, 'cash', $LANG['yesterday_cash_image'], $urls['yesterday_cash']) ?>
    </div>

    <!-- rPos Document Photo -->
    <div class="col-sm-4">
        <span class="text-muted small d-block mb-1"><?= htmlspecialchars($LANG['rpos_image']) ?></span>
        <?= _viewPhotoBlock($rposImg, 'rpos', $LANG['rpos_image'], $urls['rpos']) ?>
    </div>

</div>

<style>
/* Thumbnail di view modal — konsisten dengan dreport-thumb di tabel utama */
.dreport-view-thumb {
    width: 100%;
    max-width: 200px;
    max-height: 160px;
    object-fit: contain;
    border-radius: 0.4rem;
    border: 1px solid #dee2e6;
    display: block;
    cursor: zoom-in;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.dreport-view-thumb:hover {
    transform: scale(1.04);
    box-shadow: 0 2px 10px rgba(0,0,0,0.18);
}
/* Baris pertama tetap (System Revenue) — border atas lebih tebal */
.dreport-view-row-first td {
    border-top: 2px solid #dee2e6 !important;
}
/* Baris terakhir tetap (Difference) — border bawah lebih tebal, background tipis */
.dreport-view-row-last td {
    border-bottom: 2px solid #dee2e6 !important;
    background-color: #f8f9fa;
}
/* Semua baris fixed — teks sedikit lebih menonjol */
.dreport-view-row-fixed td {
    background-color: #f8f9fa;
}
</style>