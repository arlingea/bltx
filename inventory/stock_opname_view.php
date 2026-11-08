<?php
/**
 * stock_opname_view.php
 * File: public_html/bltx/inventory/stock_opname_view.php
 *
 * Halaman detail satu dokumen Stock Opname. Menyatukan seluruh alur kerja
 * (counting -> review -> approve -> post -> cancel) dalam satu halaman,
 * karena setiap transisi butuh melihat tabel item & variance secara utuh
 * sebelum diputuskan — bukan tombol icon kecil di baris list. Lihat
 * penjelasan perubahan untuk alasan keputusan UX ini.
 */

require_once __DIR__ . '/../includes/config.php';

if (!isLoggedIn()) {
    redirect(url('index.php'));
}

require_once BASE_PATH . '/includes/global_permission.php';
require_once BASE_PATH . '/includes/inventory_function.php';

requireStockOpnameAccess();

$LANG = loadLanguage($_SESSION['lang'] ?? 'en');

if (!canReadStockOpname()) {
    redirectNoAccess();
}

$can_update = canUpdateStockOpname();
$can_delete = canDeleteStockOpname();
$can_export = canExportStockOpname();
$can_approve = canApproveStockOpname();
$can_post    = canPostStockOpname();
$can_cancel  = canCancelStockOpname();

// Cakupan data (Report Data Scope) dari database:
// true = All Data (Full Data), false = Own Data Only. Superadmin selalu true.
$can_view_all = canViewAllStockOpname();

$csrf_token = invCsrfToken();
$user_id    = (int)($_SESSION['user_id'] ?? 0);

$id = (int)($_GET['id'] ?? 0);
$header = $id > 0 ? soGetOpnameHeader($conn, $id) : null;

// Own Data Only: dokumen milik user lain tidak boleh dibuka, termasuk lewat
// tebak-tebakan ID di URL. Diperlakukan sama seperti dokumen tidak ditemukan
// supaya keberadaan dokumen milik orang lain tidak ikut terungkap.
if ($header && !$can_view_all && (int)$header['created_by'] !== $user_id) {
    $header = null;
}

require_once BASE_PATH . '/includes/header.php';

if (!$header) {
    ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?= invHtml($LANG['so_not_found']) ?>
            </div>
            <a href="<?= url('inventory/stock_opname.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-arrow-counterclockwise"></i> <?= invHtml($LANG['back']) ?>
            </a>
        </div>
    </div>
    <?php
    require_once BASE_PATH . '/includes/footer.php';
    exit;
}

$status = $header['status'];

$itemsResult = soGetOpnameItems($conn, $id);
$items = [];
if ($itemsResult) {
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = $row;
    }
}
$summary = soGetVarianceSummaries($conn, [$id]);
$sum = $summary[$id] ?? ['total_items' => 0, 'matched_count' => 0, 'short_count' => 0, 'over_count' => 0, 'pending_count' => 0, 'total_variance_value' => 0];

$isCounting  = ($status === 'counting');
$canEditRows = $isCounting && $can_update;
$canEditNotes = in_array($status, ['draft', 'counting'], true) && $can_update;

/* Lokasi file library scanner. Dicari di beberapa nama file yang umum dipakai
   karena penamaan build html5-qrcode berbeda-beda; kalau tidak satu pun
   ditemukan, tombol kamera disembunyikan dan diganti keterangan yang jelas
   (daripada tombol yang ada tapi diam saja saat diklik). */
$soScannerRelPath = null;
foreach ([
    'assets/vendor/html5-qrcode/html5-qrcode.min.js',
    'assets/vendor/html5-qrcode/html5-qrcode.js',
    'assets/vendor/html5-qrcode/minified/html5-qrcode.min.js',
] as $_soCandidate) {
    if (file_exists(BASE_PATH . '/' . $_soCandidate)) {
        $soScannerRelPath = $_soCandidate;
        break;
    }
}
unset($_soCandidate);

// Currency symbol — dibutuhkan oleh markup .currency-cell (UI), sumber sama
// persis dengan yang dipakai inventory_data.php sebagai acuan tampilan.
$currency_symbol = getSetting('currency_symbol', 'Rp');
$total_variance_formatted = formatCurrency($sum['total_variance_value']);
$total_variance_value     = ltrim(str_replace($currency_symbol, '', $total_variance_formatted));
?>

<style>
/* Card styling */
.card {
    border-radius: 0.5rem;
    box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
    border: 1px solid rgba(0,0,0,0.125);
}

.card-body {
    padding: 1.5rem;
}

.card-header {
    background-color: #fff;
    border-bottom: 1px solid rgba(0,0,0,0.125);
    padding: 1rem 1.5rem;
}

/* Table responsive */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.table th, .table td {
    vertical-align: middle;
    padding: 0.75rem;
}

.table th {
    white-space: nowrap;
    font-weight: 600;
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.table td {
    word-break: break-word;
}

.table-striped tbody tr:nth-of-type(odd) {
    background-color: rgba(0,0,0,0.02);
}

.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,0.04);
}

.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

h5 {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
}

.alert {
    border-radius: 0.5rem;
    border: none;
}

.filter-section {
    background-color: #f8f9fa;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border: 1px solid #dee2e6;
}

.filter-section .row {
    align-items: end;
}

.currency-cell {
    display: flex;
    align-items: center;
    gap: 0;
    white-space: nowrap;
}
.currency-cell .curr-symbol {
    text-align: left;
    flex-shrink: 0;
    padding-right: 2px;
}
.currency-cell .curr-value {
    text-align: right;
    flex: 1;
}

/* ========== SCAN SECTION ========== */
.scan-section {
    background-color: #f8f9fa;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border: 1px solid #dee2e6;
}

#reader {
    border-radius: 8px;
    overflow: hidden;
    max-width: 100%;
    margin-top: 0.5rem;
}

/* Baris yang baru saja di-scan disorot sementara */
tr.scan-highlight > td {
    background-color: #fff3cd !important;
    transition: background-color 0.4s ease;
}
</style>

<div class="card border-0 shadow-sm">
    <div class="card-header">
        <div class="card-title">
            <i class="bi bi-clipboard-check"></i> <?= invHtml($header['opname_number']) ?>
            <span class="badge bg-<?= soStatusBadgeClass($status) ?> ms-2"><?= invHtml($LANG[$status]) ?></span>
        </div>
        <div class="card-tools">
            <a href="<?= url('inventory/stock_opname.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-arrow-counterclockwise"></i> <?= invHtml($LANG['back']) ?>
            </a>

            <?php if ($can_export): ?>
            <button type="button" class="btn btn-secondary btn-sm" id="btnOpenPrintPreview">
                <i class="bi bi-printer"></i> <?= invHtml($LANG['print']) ?>
            </button>
            <?php endif; ?>

            <?php if ($status === 'draft' && $can_update): ?>
            <button type="button" class="btn btn-info text-white btn-sm" id="btnStartCounting">
                <i class="bi bi-play-circle"></i> <?= invHtml($LANG['so_start_counting']) ?>
            </button>
            <?php endif; ?>

            <?php if ($isCounting && $can_update): ?>
            <button type="button" class="btn btn-warning btn-sm" id="btnSubmitReview">
                <i class="bi bi-send"></i> <?= invHtml($LANG['so_submit_review']) ?>
            </button>
            <?php endif; ?>

            <?php if ($status === 'review' && $can_approve): ?>
            <button type="button" class="btn btn-primary btn-sm" id="btnApprove">
                <i class="bi bi-check-circle"></i> <?= invHtml($LANG['approve']) ?>
            </button>
            <?php endif; ?>

            <?php if ($status === 'approved' && $can_post): ?>
            <button type="button" class="btn btn-success btn-sm" id="btnPost">
                <i class="bi bi-send-check"></i> <?= invHtml($LANG['post']) ?>
            </button>
            <?php endif; ?>

            <?php if (soIsCancellable($status) && $can_cancel): ?>
            <button type="button" class="btn btn-warning btn-sm" id="btnCancel">
                <i class="bi bi-x-circle"></i> <?= invHtml($LANG['cancel']) ?>
            </button>
            <?php endif; ?>

            <?php if ($status === 'draft' && $can_delete): ?>
            <button type="button" class="btn btn-danger btn-sm" id="btnDelete">
                <i class="bi bi-trash"></i> <?= invHtml($LANG['delete']) ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body">

        <?php if ($status === 'posted'): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= invHtml($LANG['so_immutable_notice']) ?></div>
        <?php elseif ($status === 'cancelled'): ?>
        <div class="alert alert-secondary">
            <i class="bi bi-x-circle"></i> <?= invHtml($LANG['cancelled']) ?><?= $header['cancel_reason'] ? ' — ' . invHtml($header['cancel_reason']) : '' ?>
        </div>
        <?php endif; ?>

        <!-- Header Info Section -->
        <div class="filter-section">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small"><?= invHtml($LANG['date']) ?></label>
                    <div><?= invHtml($header['opname_date']) ?></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small"><?= invHtml($LANG['location']) ?></label>
                    <div>
                        <?= invHtml($header['location_code']) ?><br>
                        <small class="text-muted"><?= invHtml($header['location_name']) ?></small>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small"><?= invHtml($LANG['created_by']) ?></label>
                    <div><?= invHtml(soActorWithDate($header['created_by_name'], $header['created_at'])) ?></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small"><?= invHtml($LANG['approved_by']) ?></label>
                    <div><?= $header['approved_by_name'] ? invHtml(soActorWithDate($header['approved_by_name'], $header['approved_at'])) : '<span class="text-muted">-</span>' ?></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small"><?= invHtml($LANG['posted_by']) ?></label>
                    <div><?= $header['posted_by_name'] ? invHtml(soActorWithDate($header['posted_by_name'], $header['posted_at'])) : '<span class="text-muted">-</span>' ?></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small"><?= invHtml($LANG['so_total_items']) ?></label>
                    <div><?= invFormatQty($sum['total_items']) ?></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small"><?= invHtml($LANG['so_total_variance']) ?></label>
                    <div>
                        <strong>
                            <span class="currency-cell">
                                <span class="curr-symbol"><?= invHtml($currency_symbol, '') ?></span>
                                <span class="curr-value"><?= invHtml(trim($total_variance_value), '') ?></span>
                            </span>
                        </strong>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">&nbsp;</label>
                    <div>
                        <span class="badge bg-success"><?= invHtml($LANG['matched']) ?>: <?= invFormatQty($sum['matched_count']) ?></span>
                        <span class="badge bg-danger"><?= invHtml($LANG['short']) ?>: <?= invFormatQty($sum['short_count']) ?></span>
                        <span class="badge bg-warning"><?= invHtml($LANG['over']) ?>: <?= invFormatQty($sum['over_count']) ?></span>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label small d-flex justify-content-between align-items-center">
                        <span><?= invHtml($LANG['notes']) ?></span>
                        <?php if ($canEditNotes): ?>
                        <button type="button" class="btn btn-link btn-sm p-0" id="btnEditNotes"><i class="bi bi-pencil"></i></button>
                        <?php endif; ?>
                    </label>
                    <div id="notesDisplay"><?= $header['notes'] ? nl2br(invHtml($header['notes'])) : '<span class="text-muted">-</span>' ?></div>
                    <?php if ($canEditNotes): ?>
                    <div id="notesEdit" class="d-none mt-1">
                        <textarea class="form-control" id="notesTextarea" rows="2"><?= invVal($header['notes']) ?></textarea>
                        <div class="mt-2 d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-primary btn-sm" id="btnSaveNotes">
                                <i class="bi bi-save"></i> <?= invHtml($LANG['save']) ?>
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" id="btnCancelNotes">
                                <i class="bi bi-x-circle"></i> <?= invHtml($LANG['cancel']) ?>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($canEditRows): ?>
        <!-- Scan Section — hanya tampil saat status counting & user boleh update -->
        <div class="scan-section">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h6 class="mb-0">
                    <i class="bi bi-qr-code-scan"></i> <?= invHtml($LANG['scan_product']) ?>
                </h6>
                <?php if ($soScannerRelPath): ?>
                <button type="button" class="btn btn-primary btn-sm" id="btnToggleScanner">
                    <i class="bi bi-camera" id="scannerIcon"></i>
                    <span id="scannerText"><?= invHtml($LANG['scanner_active']) ?></span>
                </button>
                <?php else: ?>
                <span class="small text-danger">
                    <i class="bi bi-exclamation-triangle"></i> <?= invHtml($LANG['scanner_unavailable']) ?>
                </span>
                <?php endif; ?>
            </div>

            <?php if ($soScannerRelPath): ?>
            <div id="reader" style="display:none;"></div>
            <?php endif; ?>

            <form id="scanForm" class="mt-3">
                <div class="input-group input-group-sm">
                    <input type="text" id="scanCode" class="form-control" autocomplete="off"
                           placeholder="<?= invHtml($LANG['enter_code']) ?>">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-search"></i> <?= invHtml($LANG['search']) ?>
                    </button>
                </div>
            </form>

            <div id="scanStatus" class="mt-2 small"></div>
        </div>
        <?php endif; ?>

        <?php if ($canEditRows): ?>
        <div class="d-flex justify-content-end align-items-center mb-3 flex-wrap gap-2">
            <button type="button" class="btn btn-primary btn-sm" id="btnSaveCounting">
                <i class="bi bi-save"></i> <?= invHtml($LANG['so_save_counting']) ?>
            </button>
        </div>
        <?php endif; ?>

        <!-- Table Section -->
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="itemsTable">
                <thead>
                    <tr>
                        <th class="text-center"><?= invHtml($LANG['product_code']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['item_name']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['unit']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['so_system_qty']) ?></th>
                        <th class="text-center" style="min-width:120px"><?= invHtml($LANG['so_physical_qty']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['difference']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['price']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['so_difference_value']) ?></th>
                        <th class="text-center" style="min-width:140px"><?= invHtml($LANG['remarks']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['status']) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item):
                        $variance = soVarianceStatus($item['counted_qty'], $item['difference_qty']);
                        $countedVal = $item['counted_qty'] !== null
                            ? rtrim(rtrim(number_format((float)$item['counted_qty'], 2, '.', ''), '0'), '.')
                            : '';
                        $price_formatted = formatCurrency($item['unit_price']);
                        $price_value     = ltrim(str_replace($currency_symbol, '', $price_formatted));
                        $diffval_formatted = formatCurrency($item['difference_value']);
                        $diffval_value     = ltrim(str_replace($currency_symbol, '', $diffval_formatted));
                    ?>
                    <tr data-item-id="<?= (int)$item['id'] ?>"
                        data-code="<?= invHtml($item['product_code'], '') ?>"
                        data-search="<?= invHtml(strtolower($item['product_code'] . ' ' . $item['item_name'])) ?>"
                        data-system-qty="<?= (float)$item['system_qty'] ?>"
                        data-unit-price="<?= (float)$item['unit_price'] ?>">
                        <td><?= invHtml($item['product_code']) ?></td>
                        <td><?= invHtml($item['item_name']) ?><?php if (!empty($item['brand'])): ?><br><small class="text-muted"><?= invHtml($item['brand']) ?></small><?php endif; ?></td>
                        <td><?= invHtml($item['unit_symbol'] ?: $item['unit_name']) ?></td>
                        <td class="text-center"><?= invFormatQty($item['system_qty']) ?></td>
                        <td>
                            <?php if ($canEditRows): ?>
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end counted-qty-input" value="<?= invHtml($countedVal, '') ?>">
                            <?php else: ?>
                                <div class="text-center"><?= $item['counted_qty'] !== null ? invFormatQty($item['counted_qty']) : '<span class="text-muted">-</span>' ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center difference-cell"><?= $item['counted_qty'] !== null ? invFormatQty($item['difference_qty']) : '-' ?></td>
                        <td>
                            <span class="currency-cell">
                                <span class="curr-symbol"><?= invHtml($currency_symbol, '') ?></span>
                                <span class="curr-value"><?= invHtml(trim($price_value), '') ?></span>
                            </span>
                        </td>
                        <td class="difference-value-cell">
                            <?php if ($item['counted_qty'] !== null): ?>
                            <strong>
                                <span class="currency-cell">
                                    <span class="curr-symbol"><?= invHtml($currency_symbol, '') ?></span>
                                    <span class="curr-value"><?= invHtml(trim($diffval_value), '') ?></span>
                                </span>
                            </strong>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($canEditRows): ?>
                                <input type="text" class="form-control form-control-sm remarks-input" value="<?= invVal($item['remarks']) ?>">
                            <?php else: ?>
                                <?= invHtml($item['remarks']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center variance-badge">
                            <span class="badge bg-<?= soVarianceBadgeClass($variance) ?>"><?= invHtml($LANG[$variance]) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Cancel modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cancelModalLabel">
            <i class="bi bi-x-circle"></i> <?= invHtml($LANG['so_confirm_cancel']) ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label class="form-label"><?= invHtml($LANG['so_cancel_reason']) ?></label>
        <textarea class="form-control" id="cancelReasonInput" rows="3" placeholder="<?= invHtml($LANG['so_cancel_reason_placeholder']) ?>"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
            <i class="bi bi-x-circle"></i> <?= invHtml($LANG['close']) ?>
        </button>
        <button type="button" class="btn btn-warning btn-sm" id="btnConfirmCancel">
            <i class="bi bi-x-circle"></i> <?= invHtml($LANG['cancel']) ?>
        </button>
      </div>
    </div>
  </div>
</div>

<?php if ($can_export): ?>
<!-- Print Preview Modal — menampilkan stock_opname_print.php di dalam halaman
     ini, sehingga tidak perlu membuka tab baru dan halaman detail tidak tertutup -->
<div class="modal fade" id="printPreviewModal" tabindex="-1" aria-labelledby="printPreviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="printPreviewModalLabel">
            <i class="bi bi-printer"></i> <?= invHtml($LANG['so_print_preview']) ?>
            <span class="text-muted small ms-1"><?= invHtml($header['opname_number']) ?></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0" style="min-height:60vh;">
        <iframe id="printPreviewFrame" title="<?= invHtml($LANG['so_print_preview']) ?>"
                style="width:100%;height:70vh;border:0;display:block;"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
            <i class="bi bi-x-circle"></i> <?= invHtml($LANG['close']) ?>
        </button>
        <a href="<?= url('inventory/stock_opname_export_pdf.php?id=' . $id) ?>" target="_blank" class="btn btn-danger btn-sm">
            <i class="bi bi-file-earmark-pdf"></i> <?= invHtml($LANG['export_pdf']) ?>
        </a>
        <button type="button" class="btn btn-primary btn-sm" id="btnPrintPreviewPrint">
            <i class="bi bi-printer"></i> <?= invHtml($LANG['print']) ?>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<input type="hidden" id="csrf_token" value="<?= invHtml($csrf_token, '') ?>">

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

<?php if ($canEditRows && $soScannerRelPath): ?>
<!-- html5-qrcode — dimuat setelah footer JS agar jQuery sudah tersedia (pola sama dengan take_items.php) -->
<script src="<?= url($soScannerRelPath) ?>"></script>
<?php endif; ?>

<script>
$(function () {
    var csrfToken  = $('#csrf_token').val();
    var processUrl = '<?= url('inventory/stock_opname_process.php') ?>';
    var opnameId   = <?= (int)$id ?>;

    function notify(success, message) {
        if (window.Swal) {
            /* Toast melayang menggantikan modal SweetAlert2 — A4.1 / B2 poin 5:
               SweetAlert2 hanya untuk dialog konfirmasi. Lima Swal.fire lain di
               berkas ini memang konfirmasi dan TETAP dipertahankan. */
            showFloatingAlert(message, success ? 'success' : 'error');
        } else {
            alert(message);
        }
    }
    function post(action, extra, onSuccess) {
        var data = Object.assign({ action: action, csrf_token: csrfToken, id: opnameId }, extra || {});
        $.post(processUrl, data, function (resp) {
            if (resp.success) {
                if (onSuccess) onSuccess(resp);
                else { notify(true, resp.message); setTimeout(function () { window.location.reload(); }, 400); }
            } else {
                notify(false, resp.message);
            }
        }, 'json').fail(function () { notify(false, 'Request failed.'); });
    }

    /* Live diff calculation while counting */
    var soCurrencySymbol = <?= json_encode((string)$currency_symbol, JSON_UNESCAPED_UNICODE) ?>;

    /* Bangun ulang markup .currency-cell (sama seperti yang dirender PHP) supaya
       simbol mata uang tidak hilang saat nilai diperbarui secara live. Memakai
       .text() pada tiap span agar isi tetap aman dari injeksi HTML. */
    function soRenderCurrencyCell($cell, value) {
        $cell.empty().append(
            $('<strong>').append(
                $('<span>').addClass('currency-cell')
                    .append($('<span>').addClass('curr-symbol').text(soCurrencySymbol))
                    .append($('<span>').addClass('curr-value').text(value))
            )
        );
    }

    function recalcRow($row) {
        var sys   = parseFloat($row.data('system-qty')) || 0;
        var price = parseFloat($row.data('unit-price')) || 0;
        var val   = $row.find('.counted-qty-input').val();
        if (val === '') {
            $row.find('.difference-cell').text('-');
            $row.find('.difference-value-cell').text('-');
            return;
        }
        var counted = parseFloat(val);
        if (isNaN(counted) || counted < 0) {
            $row.find('.difference-cell').text('-');
            return;
        }
        var diff = counted - sys;
        $row.find('.difference-cell').text(diff.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1'));
        var diffVal = diff * price;
        soRenderCurrencyCell($row.find('.difference-value-cell'), diffVal.toFixed(2));
        var $badge = $row.find('.variance-badge .badge');
        $badge.removeClass('bg-success bg-danger bg-warning bg-secondary');
        if (Math.abs(diff) < 0.00001) { $badge.addClass('bg-success').text(<?= json_encode($LANG['matched'], JSON_UNESCAPED_UNICODE) ?>); }
        else if (diff < 0) { $badge.addClass('bg-danger').text(<?= json_encode($LANG['short'], JSON_UNESCAPED_UNICODE) ?>); }
        else { $badge.addClass('bg-warning').text(<?= json_encode($LANG['over'], JSON_UNESCAPED_UNICODE) ?>); }
    }
    $(document).on('input change keyup', '.counted-qty-input', function () {
        recalcRow($(this).closest('tr'));
    });

    /* Tabel item memakai DataTables agar tampilannya sama persis dengan tabel
       di stock_opname.php: show entries, kolom search, pagination, dan info
       pagination. Opsi disamakan dengan halaman list, kecuali `order` yang
       dikosongkan supaya urutan item mengikuti urutan asli dari server. */
    var itemsTable = $('#itemsTable').DataTable({
        order: [],
        pageLength: 25,
        lengthMenu: [25, 50, 100, 250, 500],
        responsive: true,
        autoWidth: false,
        deferRender: true,
        language: { search: <?= json_encode($LANG['search'] . ':', JSON_UNESCAPED_UNICODE) ?> }
    });

    /* SEMUA baris tabel, termasuk yang sedang berada di halaman pagination lain
       atau sedang tersaring oleh kotak search. Wajib dipakai menggantikan
       $('#itemsTable tbody tr'), karena selector itu hanya melihat baris pada
       halaman yang sedang tampil sehingga input hasil hitung di halaman lain
       akan terlewat. */
    function soAllItemRows() {
        return $(itemsTable.rows().nodes());
    }

    /* Pindah ke halaman pagination yang memuat baris tertentu, lalu jalankan
       callback setelah tabel selesai digambar ulang. */
    function soGoToRowPage($row, done) {
        var pageLen = itemsTable.page.len();
        if (pageLen <= 0) { done(); return; }

        var idx    = itemsTable.row($row).index();
        var order  = itemsTable.rows({ order: 'applied', search: 'applied' }).indexes().toArray();
        var pos    = order.indexOf(idx);

        if (pos === -1) {
            /* Baris sedang tersaring oleh kotak search — bersihkan search dulu */
            itemsTable.search('').draw(false);
            order = itemsTable.rows({ order: 'applied', search: 'applied' }).indexes().toArray();
            pos   = order.indexOf(idx);
        }
        if (pos === -1) { done(); return; }

        var targetPage = Math.floor(pos / pageLen);
        if (itemsTable.page() !== targetPage) {
            itemsTable.page(targetPage).draw(false);
        }
        done();
    }

    /* Save counting */
    $('#btnSaveCounting').on('click', function () {
        var items = [];
        soAllItemRows().each(function () {
            var $row = $(this);
            var val = $row.find('.counted-qty-input').val();
            if (val === '' || val === undefined) return;
            items.push({
                item_id: $row.data('item-id'),
                counted_qty: val,
                remarks: $row.find('.remarks-input').val() || ''
            });
        });
        if (items.length === 0) { notify(false, <?= json_encode($LANG['so_invalid_qty'], JSON_UNESCAPED_UNICODE) ?>); return; }
        post('save_counting', { items: JSON.stringify(items) }, function (resp) {
            notify(true, resp.message + ' (' + resp.saved_count + ')');
        });
    });

    $('#btnStartCounting').on('click', function () {
        if (window.Swal) {
            Swal.fire({ icon: 'question', text: <?= json_encode($LANG['so_confirm_start_counting'], JSON_UNESCAPED_UNICODE) ?>, showCancelButton: true })
                .then(function (r) { if (r.isConfirmed) post('start_counting'); });
        } else if (confirm(<?= json_encode($LANG['so_confirm_start_counting'], JSON_UNESCAPED_UNICODE) ?>)) { post('start_counting'); }
    });

    $('#btnSubmitReview').on('click', function () {
        if (window.Swal) {
            Swal.fire({ icon: 'question', text: <?= json_encode($LANG['so_confirm_submit_review'], JSON_UNESCAPED_UNICODE) ?>, showCancelButton: true })
                .then(function (r) { if (r.isConfirmed) post('submit_review'); });
        } else if (confirm(<?= json_encode($LANG['so_confirm_submit_review'], JSON_UNESCAPED_UNICODE) ?>)) { post('submit_review'); }
    });

    $('#btnApprove').on('click', function () {
        if (window.Swal) {
            Swal.fire({ icon: 'question', text: <?= json_encode($LANG['so_confirm_approve'], JSON_UNESCAPED_UNICODE) ?>, showCancelButton: true })
                .then(function (r) { if (r.isConfirmed) post('approve'); });
        } else if (confirm(<?= json_encode($LANG['so_confirm_approve'], JSON_UNESCAPED_UNICODE) ?>)) { post('approve'); }
    });

    $('#btnPost').on('click', function () {
        if (window.Swal) {
            Swal.fire({ icon: 'warning', text: <?= json_encode($LANG['so_confirm_post'], JSON_UNESCAPED_UNICODE) ?>, showCancelButton: true, confirmButtonColor: '#198754' })
                .then(function (r) { if (r.isConfirmed) post('post'); });
        } else if (confirm(<?= json_encode($LANG['so_confirm_post'], JSON_UNESCAPED_UNICODE) ?>)) { post('post'); }
    });

    $('#btnCancel').on('click', function () {
        $('#cancelReasonInput').val('');
        new bootstrap.Modal(document.getElementById('cancelModal')).show();
    });
    $('#btnConfirmCancel').on('click', function () {
        post('cancel', { reason: $('#cancelReasonInput').val() });
    });

    $('#btnDelete').on('click', function () {
        var go = function () { post('delete', {}, function (resp) { notify(true, resp.message); setTimeout(function () { window.location.href = '<?= url('inventory/stock_opname.php') ?>'; }, 400); }); };
        if (window.Swal) {
            Swal.fire({ icon: 'warning', text: <?= json_encode($LANG['so_confirm_delete'], JSON_UNESCAPED_UNICODE) ?>, showCancelButton: true, confirmButtonColor: '#dc3545' })
                .then(function (r) { if (r.isConfirmed) go(); });
        } else if (confirm(<?= json_encode($LANG['so_confirm_delete'], JSON_UNESCAPED_UNICODE) ?>)) { go(); }
    });

    /* ==========================================================
       PRINT PREVIEW — tampilkan dokumen di dalam modal (iframe),
       bukan membuka tab baru, supaya halaman detail tetap terbuka.
       ========================================================== */
    $('#btnOpenPrintPreview').on('click', function () {
        $('#printPreviewFrame').attr(
            'src',
            '<?= url('inventory/stock_opname_print.php') ?>?id=' + encodeURIComponent(opnameId) + '&embed=1'
        );
        new bootstrap.Modal(document.getElementById('printPreviewModal')).show();
    });

    /* Cetak isi iframe saja, bukan seluruh halaman di belakang modal */
    $('#btnPrintPreviewPrint').on('click', function () {
        var frame = document.getElementById('printPreviewFrame');
        if (!frame || !frame.contentWindow) return;
        frame.contentWindow.focus();
        frame.contentWindow.print();
    });

    /* Kosongkan iframe saat modal ditutup supaya dokumen selalu dimuat segar */
    $('#printPreviewModal').on('hidden.bs.modal', function () {
        $('#printPreviewFrame').attr('src', '');
    });

    /* Notes inline edit */
    $('#btnEditNotes').on('click', function () {
        $('#notesDisplay').addClass('d-none');
        $('#notesEdit').removeClass('d-none');
    });
    $('#btnCancelNotes').on('click', function () {
        $('#notesEdit').addClass('d-none');
        $('#notesDisplay').removeClass('d-none');
    });
    $('#btnSaveNotes').on('click', function () {
        post('update_notes', { notes: $('#notesTextarea').val() }, function () {
            window.location.reload();
        });
    });

    /* ==========================================================
       SCAN ITEM — cari baris berdasarkan Product Code lalu
       langsung fokuskan input Physical Qty pada baris tersebut,
       sehingga user tidak perlu mencari manual di tabel.
       Hanya aktif saat status counting (blok ini hanya dirender
       jika $canEditRows true).
       ========================================================== */
<?php if ($canEditRows): ?>
    var scannerActive   = false;
    var scanStarting    = false;
    var html5QrCode     = null;
    var lastScannedCode = '';
    var lastScanTime    = 0;

    var scanTranslations = {
        scannerActive:      <?= json_encode($LANG['scanner_active'], JSON_UNESCAPED_UNICODE) ?>,
        scannerUnavailable: <?= json_encode($LANG['scanner_unavailable'], JSON_UNESCAPED_UNICODE) ?>,
        scanned:            <?= json_encode($LANG['scanned'], JSON_UNESCAPED_UNICODE) ?>,
        enterCode:          <?= json_encode($LANG['enter_code'], JSON_UNESCAPED_UNICODE) ?>,
        notFound:           <?= json_encode($LANG['so_scan_not_found'], JSON_UNESCAPED_UNICODE) ?>
    };

    function scanSetStatus(html, cssClass) {
        $('#scanStatus').removeClass('text-success text-danger text-muted')
                        .addClass(cssClass || 'text-muted')
                        .html(html);
    }

    function scanPlayBeep() {
        try {
            var ctx  = new (window.AudioContext || window.webkitAudioContext)();
            var osc  = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 800;
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.1);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.1);
        } catch (e) {}
    }

    /* Cari baris tabel berdasarkan product code (case-insensitive, trim). */
    function scanFindRow(code) {
        var needle = String(code).trim().toLowerCase();
        if (!needle) return $();
        var $found = $();
        soAllItemRows().each(function () {
            var rowCode = String($(this).data('code') || '').trim().toLowerCase();
            if (rowCode && rowCode === needle) {
                $found = $(this);
                return false;
            }
        });
        return $found;
    }

    function scanLookup(code) {
        code = String(code || '').trim();
        if (!code) {
            scanSetStatus(scanTranslations.enterCode, 'text-muted');
            return;
        }

        var $row = scanFindRow(code);

        if ($row.length === 0) {
            scanSetStatus('<i class="bi bi-exclamation-triangle"></i> ' + scanTranslations.notFound + ' (' + escHtmlScan(code) + ')', 'text-danger');
            return;
        }

        /* Pindah ke halaman pagination yang memuat baris tersebut lebih dulu,
           supaya baris hasil scan benar-benar terlihat walau berada di halaman
           lain atau sedang tersaring oleh kotak search. */
        soGoToRowPage($row, function () {
            /* Sorot baris sementara */
            soAllItemRows().removeClass('scan-highlight');
            $row.addClass('scan-highlight');

            /* Gulirkan ke baris tersebut lalu fokuskan input Physical Qty */
            var rowTop = $row.offset().top;
            $('html, body').stop().animate({ scrollTop: rowTop - 140 }, 300);

            var $input = $row.find('.counted-qty-input');
            if ($input.length) {
                $input.trigger('focus').trigger('select');
            }
        });

        var itemName = $.trim($row.find('td').eq(1).clone().children('small').remove().end().text());
        scanSetStatus('<i class="bi bi-check-circle"></i> ' + scanTranslations.scanned + ': <strong>' + escHtmlScan(code) + '</strong> — ' + escHtmlScan(itemName), 'text-success');

        /* Kosongkan input kode supaya siap untuk scan berikutnya */
        $('#scanCode').val('');
    }

    function escHtmlScan(text) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    /* Input manual / scanner gun (keyboard wedge) — submit dengan Enter */
    $('#scanForm').on('submit', function (e) {
        e.preventDefault();
        scanLookup($('#scanCode').val());
    });

    /* Kamera scanner */
    function scanBuildConfig() {
        var config = {
            fps: 20,
            qrbox: { width: 250, height: 250 },
            aspectRatio: 1.0,
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: true
            }
        };
        /* formatsToSupport hanya ditambahkan bila global-nya benar-benar ada.
           Beberapa build html5-qrcode tidak mengekspos Html5QrcodeSupportedFormats,
           dan merujuknya langsung akan melempar ReferenceError yang membuat
           tombol kamera seolah-olah mati tanpa pesan apa pun. */
        if (typeof Html5QrcodeSupportedFormats !== 'undefined') {
            config.formatsToSupport = [
                Html5QrcodeSupportedFormats.QR_CODE,
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.CODE_39,
                Html5QrcodeSupportedFormats.CODE_93,
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.EAN_8,
                Html5QrcodeSupportedFormats.UPC_A,
                Html5QrcodeSupportedFormats.UPC_E,
                Html5QrcodeSupportedFormats.ITF,
                Html5QrcodeSupportedFormats.CODABAR,
                Html5QrcodeSupportedFormats.DATA_MATRIX
            ];
        }
        return config;
    }

    function scanOnSuccess(decodedText) {
        var now = Date.now();
        /* Debounce: abaikan kode sama yang terbaca ulang dalam 1 detik */
        if (decodedText === lastScannedCode && (now - lastScanTime) < 1000) return;
        lastScannedCode = decodedText;
        lastScanTime    = now;

        if (navigator.vibrate) navigator.vibrate(200);
        scanPlayBeep();

        scanLookup(decodedText);
    }

    function scanFail(err) {
        console.error('Scanner error:', err);
        /* Kembalikan state ke posisi mati. Tanpa ini, start yang gagal bisa
           menyisakan scannerActive/html5QrCode dalam kondisi menggantung,
           sehingga klik berikutnya memanggil stop() pada scanner yang tidak
           berjalan dan errornya salah dilaporkan sebagai "Camera unavailable". */
        scannerActive = false;
        scanStarting  = false;
        html5QrCode   = null;
        $('#reader').hide();
        $('#scannerText').text(scanTranslations.scannerActive);
        $('#scannerIcon').removeClass('bi-camera-video-off').addClass('bi-camera');
        var detail = '';
        if (err) {
            detail = (typeof err === 'string') ? err : (err.message || err.name || '');
        }
        scanSetStatus(
            '<i class="bi bi-exclamation-triangle"></i> ' + scanTranslations.scannerUnavailable +
            (detail ? ' <span class="text-muted">(' + escHtmlScan(detail) + ')</span>' : ''),
            'text-danger'
        );
        /* Tampilkan juga sebagai popup — kalau hanya teks kecil di bawah form,
           kegagalan kamera mudah terlewat dan tombol terkesan tidak berfungsi. */
        notify(false, scanTranslations.scannerUnavailable + (detail ? ' (' + detail + ')' : ''));
    }

    function scanMarkStarted() {
        scanStarting  = false;
        scannerActive = true;
        $('#scannerText').text(scanTranslations.scannerActive);
        $('#scannerIcon').removeClass('bi-camera').addClass('bi-camera-video-off');
        scanSetStatus('', 'text-muted');
    }

    function startScanner() {
        /* Cegah start ganda. Klik dua kali saat proses start masih berjalan
           akan membuat instance kedua di elemen #reader yang sama, sehingga
           state internal library jadi tidak sinkron dan stop() berikutnya
           melempar "Cannot stop, scanner is not running or paused." */
        if (scanStarting) return;

        if (typeof Html5Qrcode === 'undefined') {
            scanFail('html5-qrcode not loaded');
            return;
        }

        /* Kamera hanya bisa diakses pada halaman aman (HTTPS atau localhost) */
        if (!window.isSecureContext && location.hostname !== 'localhost') {
            scanFail('insecure context');
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            scanFail('getUserMedia unsupported');
            return;
        }

        /* Bersihkan instance lama yang mungkin masih menggantung */
        if (html5QrCode) {
            try { html5QrCode.stop(); } catch (e) {}
            html5QrCode = null;
        }

        scanStarting = true;

        try {
            html5QrCode = new Html5Qrcode('reader');
        } catch (e) {
            scanFail(e);
            return;
        }

        $('#reader').show();
        var config = scanBuildConfig();

        /* Coba pakai kamera belakang lebih dulu; jika gagal, ambil kamera
           apa pun yang tersedia lewat getCameras() (laptop biasanya hanya
           punya kamera depan, sehingga facingMode:environment bisa gagal). */
        html5QrCode.start({ facingMode: 'environment' }, config, scanOnSuccess)
            .then(scanMarkStarted)
            .catch(function (firstErr) {
                console.warn('Rear camera failed, trying camera list:', firstErr);
                Html5Qrcode.getCameras().then(function (devices) {
                    if (!devices || devices.length === 0) {
                        scanFail('no camera found');
                        return;
                    }
                    var deviceId = devices[devices.length - 1].id;
                    html5QrCode.start({ deviceId: { exact: deviceId } }, config, scanOnSuccess)
                        .then(scanMarkStarted)
                        .catch(scanFail);
                }).catch(scanFail);
            });
    }

    function scanResetUi() {
        scannerActive = false;
        scanStarting  = false;
        $('#reader').hide();
        $('#scannerText').text(scanTranslations.scannerActive);
        $('#scannerIcon').removeClass('bi-camera-video-off').addClass('bi-camera');
    }

    function stopScanner() {
        var instance = html5QrCode;
        /* State UI dikembalikan lebih dulu, supaya tombol selalu kembali ke
           posisi "siap start" apa pun hasil stop()-nya. */
        scanResetUi();
        html5QrCode = null;

        if (!instance) return;

        /* stop() bisa melempar error SINKRON (mis. "Cannot stop, scanner is not
           running or paused.") — bukan promise. Error ini tidak berbahaya dan
           TIDAK boleh dilaporkan sebagai kegagalan kamera, karena akan menutupi
           pesan error start yang sebenarnya. */
        try {
            var result = instance.stop();
            if (result && typeof result.catch === 'function') {
                result.catch(function (err) { console.warn('Stop error (diabaikan):', err); });
            }
        } catch (err) {
            console.warn('Stop error (diabaikan):', err);
        }
    }

    $('#btnToggleScanner').on('click', function () {
        try {
            scannerActive ? stopScanner() : startScanner();
        } catch (e) {
            scanFail(e);
        }
    });

    $(window).on('beforeunload', function () {
        if (html5QrCode) {
            try { html5QrCode.stop(); } catch (e) {}
        }
    });
<?php endif; ?>
});
</script>