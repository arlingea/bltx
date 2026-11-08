<?php
/**
 * manual_yesterdaycash_view.php
 * Yesterday Cash Total — Summary View (modal AJAX target)
 * Location: public_html/bltx/arw_pos/manual_yesterdaycash_view.php
 *
 * DO NOT access directly — must be called via AJAX (X-Requested-With: XMLHttpRequest)
 * or will redirect to login / dreport page.
 */
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

// Block direct (non-AJAX) access
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!$isAjax) {
    if (!isLoggedIn()) {
        redirect(url('index.php'));
        exit;
    }
    redirect(url('arw_pos/manual_dreport.php'));
    exit;
}

// Access control — same permission as dreport module (can_access)
requireYesterdayCashViewAccess();

// Load language (session-based, same as header.php)
$LANG = loadLanguage($_SESSION['lang'] ?? 'en');

// Fetch summary data
$summary = getDreportYesterdayCashSummary();

// Helper: format money using system currency setting
function ycFormatMoney(float $amount): string {
    if (function_exists('dreportFormatMoney')) {
        return dreportFormatMoney($amount);
    }
    if (function_exists('formatCurrency')) {
        return formatCurrency($amount);
    }
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

/**
 * Split formatted currency string into [symbol, number].
 * Handles all outputs from formatCurrency():
 *   IDR → "Rp 1.500.000"   → ['Rp', '1.500.000']
 *   USD → "$1,500.00"       → ['$',  '1,500.00']
 *   CNY → "¥1,500.00"       → ['¥',  '1,500.00']
 * Returns ['', full_string] if unrecognised (safe fallback).
 */
function ycSplitMoney(string $formatted): array {
    // IDR: "Rp 1.500.000" — symbol is "Rp", separated by space
    if (str_starts_with($formatted, 'Rp ')) {
        return ['Rp', ltrim(substr($formatted, 3))];
    }
    // USD: "$..." or CNY: "¥..." — single-char symbol, no space
    if (strlen($formatted) > 1) {
        $first = mb_substr($formatted, 0, 1);
        if (in_array($first, ['$', '¥', '€', '£'], true)) {
            return [$first, mb_substr($formatted, 1)];
        }
    }
    // Unrecognised — return empty symbol so caller renders as-is
    return ['', $formatted];
}
?>
<?php if (!$summary['has_data']): ?>
<div class="alert alert-info m-3">
    <i class="bi bi-info-circle me-2"></i>
    <?= htmlspecialchars($LANG['yesterday_cash_view_no_data']) ?>
</div>
<?php else: ?>
<style>
/* Scoped — hanya di dalam #yesterdayCashViewBody.

   Seluruh CSS buatan tangan untuk warna, latar, border, dan radius DIHAPUS,
   diganti kelas utilitas AdminLTE 4 / Bootstrap 5. Yang lama memaku
   background:#fff serta warna heksadesimal (#f0f4ff, #1a3a6e, #7c3aed, ...),
   sehingga tabel ini tidak pernah ikut tema dan pecah di dark mode.

   Yang tersisa hanya penyesuaian ukuran untuk layar sangat sempit: Bootstrap
   tidak menyediakan utilitas untuk breakpoint di bawah 400px, dan perilaku
   mobile yang lama tidak ada alasan untuk diubah. */
@media (max-width: 400px) {
    #yesterdayCashViewBody .table > :not(caption) > * > * {
        padding: 0.5rem 0.65rem;
        font-size: 0.85rem;
    }
    #yesterdayCashViewBody .ycsv-row-grand > * {
        font-size: 0.92rem;
    }
}
</style>

<div class="table-responsive">
    <table class="table table-striped table-hover align-middle mb-0">
        <thead>
            <tr>
                <th><?= htmlspecialchars($LANG['description']) ?></th>
                <th class="text-end"><?= htmlspecialchars($LANG['amount']) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($summary['show_two_days_ago']): ?>
            <tr>
                <td>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <span class="badge text-bg-light fw-normal">
                            <i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars(date('d/m/Y', strtotime('-2 days'))) ?>
                        </span>
                        <span class="d-inline-flex align-items-center gap-1">
                            <i class="bi bi-cash-stack text-secondary"></i>
                            <?= htmlspecialchars($LANG['yesterday_cash_view_two_days_ago']) ?>
                        </span>
                    </div>
                </td>
                <td class="text-end text-nowrap fw-semibold text-secondary">
                    <?php [$tdaSym, $tdaNum] = ycSplitMoney(ycFormatMoney($summary['two_days_ago_total'])); ?>
                    <?php if ($tdaSym !== ''): ?>
                    <div class="d-flex justify-content-between align-items-center gap-1">
                        <span><?= htmlspecialchars($tdaSym) ?></span>
                        <span class="flex-fill text-end"><?= htmlspecialchars($tdaNum) ?></span>
                    </div>
                    <?php else: ?>
                    <?= htmlspecialchars($tdaNum) ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <td>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <span class="badge text-bg-light fw-normal">
                            <i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars(date('d/m/Y', strtotime('-1 day'))) ?>
                        </span>
                        <span class="d-inline-flex align-items-center gap-1">
                            <i class="bi bi-cash-stack text-primary"></i>
                            <?= htmlspecialchars($LANG['yesterday_cash_view_yesterday_total']) ?>
                        </span>
                    </div>
                </td>
                <td class="text-end text-nowrap fw-semibold text-primary">
                    <?php [$ycSym, $ycNum] = ycSplitMoney(ycFormatMoney($summary['yesterday_cash_total'])); ?>
                    <?php if ($ycSym !== ''): ?>
                    <div class="d-flex justify-content-between align-items-center gap-1">
                        <span><?= htmlspecialchars($ycSym) ?></span>
                        <span class="flex-fill text-end"><?= htmlspecialchars($ycNum) ?></span>
                    </div>
                    <?php else: ?>
                    <?= htmlspecialchars($ycNum) ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <span class="badge text-bg-light fw-normal">
                            <i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars(date('d/m/Y')) ?>
                        </span>
                        <span class="d-inline-flex align-items-center gap-1">
                            <i class="bi bi-plus-circle text-success"></i>
                            <?= htmlspecialchars($LANG['yesterday_cash_view_today_cash']) ?>
                        </span>
                    </div>
                </td>
                <td class="text-end text-nowrap fw-semibold text-success">
                    <?php [$tcSym, $tcNum] = ycSplitMoney(ycFormatMoney($summary['today_cash_total'])); ?>
                    <?php if ($tcSym !== ''): ?>
                    <div class="d-flex justify-content-between align-items-center gap-1">
                        <span><?= htmlspecialchars($tcSym) ?></span>
                        <span class="flex-fill text-end"><?= htmlspecialchars($tcNum) ?></span>
                    </div>
                    <?php else: ?>
                    <?= htmlspecialchars($tcNum) ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="table-active fw-bold ycsv-row-grand">
                <td>
                    <span class="d-inline-flex align-items-center gap-1">
                        <i class="bi bi-calculator"></i>
                        <?= htmlspecialchars($LANG['yesterday_cash_view_grand_total']) ?>
                    </span>
                </td>
                <td class="text-end text-nowrap">
                    <?php [$gtSym, $gtNum] = ycSplitMoney(ycFormatMoney($summary['grand_total'])); ?>
                    <?php if ($gtSym !== ''): ?>
                    <div class="d-flex justify-content-between align-items-center gap-1">
                        <span><?= htmlspecialchars($gtSym) ?></span>
                        <span class="flex-fill text-end"><?= htmlspecialchars($gtNum) ?></span>
                    </div>
                    <?php else: ?>
                    <?= htmlspecialchars($gtNum) ?>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>
<?php endif; ?>
