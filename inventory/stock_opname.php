<?php
/**
 * stock_opname.php
 * File: public_html/bltx/inventory/stock_opname.php
 *
 * Halaman utama modul Stock Opname: daftar dokumen + filter + New Stock
 * Opname. DataTables client-side, konsisten dengan inventory_in.php /
 * inventory_out.php / warehouse_location.php.
 *
 * PERUBAHAN — HALAMAN INI KINI TAMPIL DI DALAM TAB inventory_gate.php.
 *
 * Sebelumnya berkas ini adalah halaman berdiri sendiri yang memuat
 * header.php dan footer.php-nya sendiri, sehingga mengkliknya membuat
 * pengguna KELUAR dari hub Inventory dan kehilangan barisan tab.
 *
 * Sekarang isinya dirender sebagai PANE di dalam inventory_gate.php.
 * Agar hanya ada SATU tempat halaman ini tampil, akses langsung ke
 * stock_opname.php DIALIHKAN ke hub pada tab Stock Opname. Dengan begitu
 * seluruh tautan lama yang sudah menunjuk ke berkas ini — misalnya tombol
 * kembali di stock_opname_view.php — otomatis mendarat di dalam tab, tanpa
 * satu pun berkas lain perlu diubah.
 *
 * header.php dan footer.php TIDAK lagi dimuat di sini: keduanya milik hub.
 */

require_once __DIR__ . '/../includes/config.php';

// INV_GATE_EMBED didefinisikan oleh inventory_gate.php tepat sebelum berkas
// ini di-include. Ketiadaannya berarti berkas dibuka langsung lewat URL.
$SO_EMBEDDED = defined('INV_GATE_EMBED') && INV_GATE_EMBED === true;

if (!$SO_EMBEDDED) {
    // Alihkan ke lokasi kanonik: tab Stock Opname di dalam hub Inventory.
    // Seluruh parameter filter yang sedang dibawa (opname_number, date_from,
    // status, dan seterusnya) DITERUSKAN apa adanya supaya URL berfilter dan
    // bookmark lama tetap membuka hasil yang sama. Parameter 'tab' bawaan
    // dibuang lebih dulu agar tidak tertulis dua kali.
    $so_params = [];
    parse_str((string)($_SERVER['QUERY_STRING'] ?? ''), $so_params);
    unset($so_params['tab']);

    $so_target = 'inventory/inventory_gate.php?tab=stock_opname';
    if (!empty($so_params)) {
        $so_target .= '&' . http_build_query($so_params);
    }

    redirect(url($so_target));
}

// Pemeriksaan login tetap dilakukan sebagai lapis pengaman sendiri, tidak
// bergantung pada pemanggilnya.
if (!isLoggedIn()) {
    redirect(url('index.php'));
}

require_once BASE_PATH . '/includes/global_permission.php';
require_once BASE_PATH . '/includes/inventory_function.php';

requireStockOpnameAccess();

$LANG = loadLanguage($_SESSION['lang'] ?? 'en');

$can_create  = canCreateStockOpname();
$can_read    = canReadStockOpname();
$can_update  = canUpdateStockOpname();
$can_delete  = canDeleteStockOpname();
$can_export  = canExportStockOpname();
$can_cancel  = canCancelStockOpname();

// Cakupan data (Report Data Scope) dari database:
// true = All Data (Full Data), false = Own Data Only. Superadmin selalu true.
$can_view_all = canViewAllStockOpname();

$csrf_token = invCsrfToken();
$user_id    = (int)($_SESSION['user_id'] ?? 0);

// Currency symbol — dibutuhkan oleh markup .currency-cell (UI), sumber sama
// persis dengan yang dipakai inventory_data.php sebagai acuan tampilan.
$currency_symbol = getSetting('currency_symbol', 'Rp');

/* ---------------- Filters (GET, persisted in the URL) ---------------- */
$f_opname_number = trim((string)($_GET['opname_number'] ?? ''));
$f_date_from      = soValidDate($_GET['date_from'] ?? null);
$f_date_to        = soValidDate($_GET['date_to'] ?? null);
$f_location_id    = (int)($_GET['location_id'] ?? 0);
$f_status         = (string)($_GET['status'] ?? '');
$f_created_by     = (int)($_GET['created_by'] ?? 0);

$validStatuses = ['draft', 'counting', 'review', 'approved', 'posted', 'cancelled'];
if (!in_array($f_status, $validStatuses, true)) {
    $f_status = '';
}

// Own Data Only: filter Created By DIPAKSA ke user yang sedang login, apa pun
// nilai yang dikirim lewat URL — ini penegakan di server, bukan sekadar UI.
if (!$can_view_all) {
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

$opnameResult = soQueryOpnames($conn, $filters);
$opnames = [];
$ids     = [];
if ($opnameResult) {
    while ($row = $opnameResult->fetch_assoc()) {
        $opnames[] = $row;
        $ids[]     = (int)$row['id'];
    }
}
$summaries = soGetVarianceSummaries($conn, $ids);

/* ---------------- Dropdown data ---------------- */
$locationsResult = invGetAllLocations(true);
$locations = [];
if ($locationsResult) {
    while ($row = $locationsResult->fetch_assoc()) {
        $locations[] = $row;
    }
}
$usersResult = invGetUsers();
$usersList = [];
if ($usersResult) {
    while ($row = $usersResult->fetch_assoc()) {
        $usersList[] = $row;
    }
}

// header.php TIDAK dimuat di sini. Hub inventory_gate.php sudah mencetaknya
// sebelum berkas ini di-include; memuatnya lagi akan menggandakan seluruh
// kerangka halaman (sidebar, navbar, breadcrumb) di dalam tab.
?>

<!-- CSS Dependencies Daterangepicker -->
<link rel="stylesheet" href="<?= url('assets/vendor/daterangepicker/daterangepicker.css') ?>">

<!-- JS Dependencies Daterangepicker Auto Loader -->
<script>
(function() {
    function loadScript(src, onload) {
        var s = document.createElement('script');
        s.src = src;
        if (onload) s.onload = onload;
        document.head.appendChild(s);
    }

    function loadMoment(next) {
        if (typeof moment !== 'undefined') { next && next(); return; }
        loadScript('<?= url('assets/vendor/moment/moment.min.js') ?>', next);
    }

    function loadDaterangepicker(next) {
        if (typeof $.fn !== 'undefined' && typeof $.fn.daterangepicker !== 'undefined') { next && next(); return; }
        loadScript('<?= url('assets/vendor/daterangepicker/daterangepicker.min.js') ?>', next);
    }

    function start() {
        loadMoment(function() {
            loadDaterangepicker(function() {
                document.dispatchEvent(new Event('depsSoReady'));
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
</script>

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
</style>

<div class="card border-0 shadow-sm">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-clipboard-check"></i> <?= invHtml($LANG['stock_opname']) ?></div>
        <div class="card-tools">
            <?php if ($can_create): ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newOpnameModal">
                <i class="bi bi-plus-circle"></i> <?= invHtml($LANG['new_stock_opname']) ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body">
        <?php if ($can_read): ?>
        <div class="filter-section">
            <form id="filterForm" method="get" class="m-0 p-0">
                <?php
                // Form GET tanpa atribut action mengirim HANYA field di dalamnya,
                // sehingga query string URL berjalan (termasuk ?tab=stock_opname)
                // akan HILANG saat tombol Filter ditekan dan pengguna terlempar
                // ke tab pertama. Field tersembunyi ini yang menahannya tetap di
                // tab Stock Opname.
                ?>
                <input type="hidden" name="tab" value="stock_opname">
                <div class="row g-3">

                    <div class="col">
                        <label for="f_opname_number" class="form-label small"><?= invHtml($LANG['so_opname_number']) ?></label>
                        <input type="text" class="form-control form-control-sm" name="opname_number" id="f_opname_number"
                               value="<?= invHtml($f_opname_number, '') ?>" placeholder="SO-...">
                    </div>

                    <div class="col">
                        <label for="f_date_range" class="form-label small"><?= invHtml($LANG['so_date_range']) ?></label>
                        <input type="text" class="form-control form-control-sm" id="f_date_range" autocomplete="off" placeholder="<?= invHtml($LANG['so_date_range']) ?>">
                        <input type="hidden" name="date_from" id="f_date_from" value="<?= invHtml($f_date_from, '') ?>">
                        <input type="hidden" name="date_to" id="f_date_to" value="<?= invHtml($f_date_to, '') ?>">
                    </div>

                    <div class="col">
                        <label for="f_location_id" class="form-label small"><?= invHtml($LANG['location']) ?></label>
                        <select class="form-select form-select-sm" name="location_id" id="f_location_id">
                            <option value=""><?= invHtml($LANG['so_all_locations']) ?></option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= (int)$loc['id'] ?>" <?= $f_location_id === (int)$loc['id'] ? 'selected' : '' ?>>
                                    <?= invHtml(soLocationLabel($loc['location_code'], $loc['location_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col">
                        <label for="f_status" class="form-label small"><?= invHtml($LANG['status']) ?></label>
                        <select class="form-select form-select-sm" name="status" id="f_status">
                            <option value=""><?= invHtml($LANG['so_all_status']) ?></option>
                            <?php foreach ($validStatuses as $st): ?>
                                <option value="<?= invHtml($st) ?>" <?= $f_status === $st ? 'selected' : '' ?>>
                                    <?= invHtml($LANG[$st]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($can_view_all): ?>
                    <div class="col">
                        <label for="f_created_by" class="form-label small"><?= invHtml($LANG['created_by']) ?></label>
                        <select class="form-select form-select-sm" name="created_by" id="f_created_by">
                            <option value=""><?= invHtml($LANG['all']) ?></option>
                            <?php foreach ($usersList as $u): ?>
                                <option value="<?= (int)$u['id'] ?>" <?= $f_created_by === (int)$u['id'] ? 'selected' : '' ?>>
                                    <?= invHtml($u['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                </div>

                <div class="col-12 mt-3">
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-funnel"></i> <?= invHtml($LANG['filter']) ?>
                        </button>
                        <a href="<?= url('inventory/inventory_gate.php?tab=stock_opname') ?>" class="btn btn-secondary btn-sm">
                            <i class="bi bi-arrow-counterclockwise"></i> <?= invHtml($LANG['reset']) ?>
                        </a>
                        <?php if ($can_export): ?>
                        <button type="button" class="btn btn-success btn-sm" id="btnExportExcel">
                            <i class="bi bi-file-earmark-excel"></i> <?= invHtml($LANG['export_excel']) ?>
                        </button>
                        <button type="button" class="btn btn-danger btn-sm" id="btnExportPdf">
                            <i class="bi bi-file-earmark-pdf"></i> <?= invHtml($LANG['export_pdf']) ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Table Section -->
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="opnameTable" style="width:100%">
                <thead>
                    <tr>
                        <th class="text-center"><?= invHtml($LANG['so_opname_number']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['date']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['location']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['so_total_items']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['matched']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['short']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['over']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['so_total_variance']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['created_by']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['status']) ?></th>
                        <th class="text-center"><?= invHtml($LANG['actions']) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($opnames as $row):
                        $id = (int)$row['id'];
                        $sum = $summaries[$id] ?? ['total_items' => 0, 'matched_count' => 0, 'short_count' => 0, 'over_count' => 0, 'total_variance_value' => 0];
                        $status = $row['status'];
                        $variance_formatted = formatCurrency($sum['total_variance_value']);
                        $variance_value     = ltrim(str_replace($currency_symbol, '', $variance_formatted));
                    ?>
                    <tr>
                        <td><strong><?= invHtml($row['opname_number']) ?></strong></td>
                        <td data-order="<?= invHtml($row['opname_date'], '') ?>"><?= invHtml(date('d-m-Y', strtotime($row['opname_date'])), '') ?></td>
                        <td>
                            <?= invHtml($row['location_code']) ?><br>
                            <small class="text-muted"><?= invHtml($row['location_name']) ?></small>
                        </td>
                        <td class="text-center"><?= invFormatQty($sum['total_items']) ?></td>
                        <td class="text-center text-success"><?= invFormatQty($sum['matched_count']) ?></td>
                        <td class="text-center text-danger"><?= invFormatQty($sum['short_count']) ?></td>
                        <td class="text-center text-warning"><?= invFormatQty($sum['over_count']) ?></td>
                        <td>
                            <strong>
                                <span class="currency-cell">
                                    <span class="curr-symbol"><?= invHtml($currency_symbol, '') ?></span>
                                    <span class="curr-value"><?= invHtml(trim($variance_value), '') ?></span>
                                </span>
                            </strong>
                        </td>
                        <td><?= invHtml($row['created_by_name']) ?></td>
                        <td class="text-center"><span class="badge bg-<?= soStatusBadgeClass($status) ?>"><?= invHtml($LANG[$status]) ?></span></td>
                        <td>
                            <div class="btn btn-sm-group btn-group-sm">
                                <?php if ($can_read): ?>
                                    <a href="<?= url('inventory/stock_opname_view.php?id=' . $id) ?>"
                                       class="btn btn-sm btn-info text-white"
                                       title="<?= invHtml($LANG['view']) ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if ($can_export): ?>
                                    <button type="button"
                                            class="btn btn-sm btn-secondary btn-print-opname"
                                            data-id="<?= $id ?>"
                                            data-number="<?= invHtml($row['opname_number']) ?>"
                                            title="<?= invHtml($LANG['print']) ?>">
                                        <i class="bi bi-printer"></i>
                                    </button>
                                <?php endif; ?>

                                <?php if ($can_cancel && soIsCancellable($status)): ?>
                                    <button type="button"
                                            class="btn btn-sm btn-warning btn-cancel-opname"
                                            data-id="<?= $id ?>"
                                            data-number="<?= invHtml($row['opname_number']) ?>"
                                            title="<?= invHtml($LANG['cancel']) ?>">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                <?php endif; ?>

                                <?php if ($can_delete && $status === 'draft'): ?>
                                    <button type="button"
                                            class="btn btn-sm btn-danger btn-delete-opname"
                                            data-id="<?= $id ?>"
                                            data-number="<?= invHtml($row['opname_number']) ?>"
                                            title="<?= invHtml($LANG['delete']) ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- New Stock Opname Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="newOpnameModal" tabindex="-1" aria-labelledby="newOpnameModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="newOpnameForm">
        <div class="modal-header">
          <h5 class="modal-title" id="newOpnameModalLabel">
              <i class="bi bi-plus-circle"></i> <?= invHtml($LANG['new_stock_opname']) ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small"><?= invHtml($LANG['so_create_help']) ?></p>
          <div class="mb-3">
            <label class="form-label"><?= invHtml($LANG['so_select_location']) ?> <span class="text-danger">*</span></label>
            <select class="form-select" name="location_id" id="new_location_id" required>
              <option value=""><?= invHtml($LANG['so_select_location_placeholder']) ?></option>
              <?php foreach ($locations as $loc): ?>
                <option value="<?= (int)$loc['id'] ?>">
                  <?= invHtml(soLocationLabel($loc['location_code'], $loc['location_name'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-0">
            <label class="form-label"><?= invHtml($LANG['notes']) ?></label>
            <textarea class="form-control" name="notes" id="new_notes" rows="3" placeholder="<?= invHtml($LANG['notes_placeholder']) ?>"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
              <i class="bi bi-x-circle"></i> <?= invHtml($LANG['close']) ?>
          </button>
          <button type="submit" class="btn btn-primary btn-sm" id="btnCreateOpname">
              <i class="bi bi-save"></i> <?= invHtml($LANG['save']) ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Cancel reason modal -->
<div class="modal fade" id="cancelOpnameModal" tabindex="-1" aria-labelledby="cancelOpnameModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cancelOpnameModalLabel">
            <i class="bi bi-x-circle"></i> <?= invHtml($LANG['so_confirm_cancel']) ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p><strong id="cancelOpnameNumber"></strong></p>
        <label class="form-label"><?= invHtml($LANG['so_cancel_reason']) ?></label>
        <textarea class="form-control" id="cancelReason" rows="3" placeholder="<?= invHtml($LANG['so_cancel_reason_placeholder']) ?>"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
            <i class="bi bi-x-circle"></i> <?= invHtml($LANG['close']) ?>
        </button>
        <button type="button" class="btn btn-warning btn-sm" id="btnConfirmCancelOpname">
            <i class="bi bi-x-circle"></i> <?= invHtml($LANG['cancel']) ?>
        </button>
      </div>
    </div>
  </div>
</div>

<?php if ($can_export): ?>
<!-- Print Preview Modal — menampilkan stock_opname_print.php di dalam halaman
     ini, sehingga tidak perlu membuka tab baru dan halaman daftar tidak tertutup -->
<div class="modal fade" id="printPreviewModal" tabindex="-1" aria-labelledby="printPreviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="printPreviewModalLabel">
            <i class="bi bi-printer"></i> <?= invHtml($LANG['so_print_preview']) ?>
            <span class="text-muted small ms-1" id="printPreviewNumber"></span>
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
        <a href="#" target="_blank" class="btn btn-danger btn-sm" id="btnPrintPreviewPdf">
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

<?php
// footer.php TIDAK dimuat di sini. Hub inventory_gate.php mencetaknya satu
// kali di akhir halaman, setelah seluruh pane selesai dirender.
?>

<script>
function initStockOpnameDaterangepicker() {
    if (typeof jQuery !== 'undefined' && typeof moment !== 'undefined' && typeof $.fn.daterangepicker !== 'undefined') {
        $('#f_date_range').daterangepicker({
            autoUpdateInput: false,
            locale: { 
                format: 'DD-MM-YYYY',
                cancelLabel: '<?= invHtml($LANG['clear']) ?>' 
            }
        });

        $('#f_date_range').on('apply.daterangepicker', function (ev, picker) {
            // Kotak yang terlihat: format tampilan DD-MM-YYYY.
            $(this).val(picker.startDate.format('DD-MM-YYYY') + ' - ' + picker.endDate.format('DD-MM-YYYY'));
            // Hidden input yang DIKIRIM ke server: WAJIB YYYY-MM-DD, karena
            // soValidDate() di server hanya menerima format 'Y-m-d'. Kalau
            // dikirim DD-MM-YYYY, nilainya ditolak menjadi NULL dan filter
            // tanggal diabaikan diam-diam (data tampil seolah tidak terfilter).
            $('#f_date_from').val(picker.startDate.format('YYYY-MM-DD'));
            $('#f_date_to').val(picker.endDate.format('YYYY-MM-DD'));
        });
        
        $('#f_date_range').on('cancel.daterangepicker', function () {
            $(this).val('');
            $('#f_date_from').val('');
            $('#f_date_to').val('');
        });

        <?php if ($f_date_from && $f_date_to): ?>
        // Nilai dari URL disimpan YYYY-MM-DD, tetapi ditampilkan DD-MM-YYYY
        // agar konsisten dengan format yang dipakai daterangepicker di atas.
        $('#f_date_range').val('<?= invHtml(date('d-m-Y', strtotime($f_date_from)), '') ?> - <?= invHtml(date('d-m-Y', strtotime($f_date_to)), '') ?>');
        $('#f_date_range').data('daterangepicker').setStartDate(moment('<?= invHtml($f_date_from, '') ?>', 'YYYY-MM-DD'));
        $('#f_date_range').data('daterangepicker').setEndDate(moment('<?= invHtml($f_date_to, '') ?>', 'YYYY-MM-DD'));
        <?php endif; ?>
    } else {
        setTimeout(initStockOpnameDaterangepicker, 100);
    }
}

document.addEventListener('depsSoReady', function() {
    initStockOpnameDaterangepicker();
});

/* PENTING — URUTAN PEMUATAN JAVASCRIPT.
 *
 * jQuery dimuat oleh footer.php. Dulu berkas ini memuat footer.php sendiri di
 * tengah halaman, sehingga blok di bawah berjalan SETELAH jQuery siap.
 * Sekarang footer.php dicetak oleh hub inventory_gate.php di AKHIR halaman,
 * jadi blok ini terurai lebih dulu daripada jQuery. Memanggil $() langsung di
 * sini akan gagal dengan "$ is not defined" dan mematikan SELURUH JavaScript
 * Stock Opname secara diam-diam.
 *
 * soBootWhenReady() menahan eksekusi sampai jQuery dan DataTables benar-benar
 * tersedia, lalu menjalankannya lewat jQuery(fn) seperti semula. */
function soBootWhenReady(fn) {
    if (typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.DataTable) {
        jQuery(fn);
        return;
    }
    setTimeout(function () { soBootWhenReady(fn); }, 50);
}

soBootWhenReady(function () {
    var csrfToken = $('#csrf_token').val();
    var processUrl = '<?= url('inventory/stock_opname_process.php') ?>';

    var table = $('#opnameTable').DataTable({
        order: [[1, 'desc']],
        pageLength: 25,
        lengthMenu: [25, 50, 100, 250, 500],
        responsive: true,
        autoWidth: false,
        deferRender: true,
        language: { search: '<?= invHtml($LANG['search']) ?>:' }
    });

    initStockOpnameDaterangepicker();

    function buildExportUrl(type) {
        var params = $('#filterForm').serialize();
        // Berkas export daftar Stock Opname berada di includes/export/,
        // sejajar dengan berkas export terpusat lain (export_inventory.php,
        // export_purchases.php, export_dreport.php). Sebelumnya baris ini
        // menunjuk ke inventory/export_stock_opname.php yang TIDAK PERNAH ADA,
        // sehingga tombol Export selalu menghasilkan 404.
        //
        // params ikut membawa 'tab=stock_opname' dari form filter; berkas
        // export sengaja mengabaikannya karena itu penanda tab, bukan filter.
        return '<?= url('includes/export/export_stock_opname.php') ?>?type=' + type + (params ? '&' + params : '');
    }

    /* Cek apakah minimal SATU filter sudah diisi/dipilih.
       Berlaku untuk salah satu filter, sebagian filter, maupun seluruh filter.
       Export baru boleh berjalan bila fungsi ini mengembalikan true. */
    function soHasActiveFilter() {
        if ($.trim($('#f_opname_number').val() || '') !== '') return true;
        if ($.trim($('#f_date_from').val()     || '') !== '') return true;
        if ($.trim($('#f_date_to').val()       || '') !== '') return true;
        if ($.trim($('#f_location_id').val()   || '') !== '') return true;
        if ($.trim($('#f_status').val()        || '') !== '') return true;
        if ($.trim($('#f_created_by').val()    || '') !== '') return true;
        return false;
    }

    function soExport(type) {
        if (!soHasActiveFilter()) {
            var msg = <?= json_encode($LANG['so_export_filter_required'], JSON_UNESCAPED_UNICODE) ?>;
            if (window.Swal) {
                Swal.fire({ icon: 'warning', text: msg });
            } else {
                alert(msg);
            }
            return;
        }
        window.location.href = buildExportUrl(type);
    }

    $('#btnExportExcel').on('click', function () { soExport('excel'); });
    $('#btnExportPdf').on('click', function () { soExport('pdf'); });

    $('#newOpnameForm').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#btnCreateOpname').prop('disabled', true);
        $.post(processUrl, {
            action: 'create',
            csrf_token: csrfToken,
            location_id: $('#new_location_id').val(),
            notes: $('#new_notes').val()
        }, function (resp) {
            if (resp.success) {
                window.location.href = '<?= url('inventory/stock_opname_view.php') ?>?id=' + resp.id;
            } else {
                $btn.prop('disabled', false);
                if (window.Swal) Swal.fire({ icon: 'error', text: resp.message }); else alert(resp.message);
            }
        }, 'json').fail(function () {
            $btn.prop('disabled', false);
            if (window.Swal) Swal.fire({ icon: 'error', text: 'Request failed.' }); else alert('Request failed.');
        });
    });

    var cancelTargetId = null;
    $('.btn-cancel-opname').on('click', function () {
        cancelTargetId = $(this).data('id');
        $('#cancelOpnameNumber').text($(this).data('number'));
        $('#cancelReason').val('');
        new bootstrap.Modal(document.getElementById('cancelOpnameModal')).show();
    });
    $('#btnConfirmCancelOpname').on('click', function () {
        if (!cancelTargetId) return;
        $.post(processUrl, {
            action: 'cancel', csrf_token: csrfToken, id: cancelTargetId, reason: $('#cancelReason').val()
        }, function (resp) {
            if (resp.success) { window.location.reload(); }
            else if (window.Swal) Swal.fire({ icon: 'error', text: resp.message }); else alert(resp.message);
        }, 'json');
    });

    /* ==========================================================
       PRINT PREVIEW — tampilkan dokumen di dalam modal (iframe),
       bukan membuka tab baru, supaya halaman daftar tetap terbuka.
       ========================================================== */
    var printBaseUrl = '<?= url('inventory/stock_opname_print.php') ?>';
    var pdfBaseUrl   = '<?= url('inventory/stock_opname_export_pdf.php') ?>';

    $('.btn-print-opname').on('click', function () {
        var id     = $(this).data('id');
        var number = $(this).data('number');
        if (!id) return;

        $('#printPreviewNumber').text(number || '');
        $('#btnPrintPreviewPdf').attr('href', pdfBaseUrl + '?id=' + encodeURIComponent(id));
        /* embed=1 -> halaman cetak menyembunyikan tombol bawaannya */
        $('#printPreviewFrame').attr('src', printBaseUrl + '?id=' + encodeURIComponent(id) + '&embed=1');

        new bootstrap.Modal(document.getElementById('printPreviewModal')).show();
    });

    /* Cetak isi iframe saja, bukan seluruh halaman di belakang modal */
    $('#btnPrintPreviewPrint').on('click', function () {
        var frame = document.getElementById('printPreviewFrame');
        if (!frame || !frame.contentWindow) return;
        frame.contentWindow.focus();
        frame.contentWindow.print();
    });

    /* Kosongkan iframe saat modal ditutup supaya dokumen berikutnya selalu
       dimuat ulang segar dan memori tidak menumpuk. */
    $('#printPreviewModal').on('hidden.bs.modal', function () {
        $('#printPreviewFrame').attr('src', '');
        $('#printPreviewNumber').text('');
    });

    $('.btn-delete-opname').on('click', function () {
        var id = $(this).data('id');
        var number = $(this).data('number');
        var doDelete = function () {
            $.post(processUrl, { action: 'delete', csrf_token: csrfToken, id: id }, function (resp) {
                if (resp.success) { window.location.reload(); }
                else if (window.Swal) Swal.fire({ icon: 'error', text: resp.message }); else alert(resp.message);
            }, 'json');
        };
        if (window.Swal) {
            Swal.fire({
                icon: 'warning', text: '<?= invHtml($LANG['so_confirm_delete']) ?>' + ' (' + number + ')',
                showCancelButton: true,
                confirmButtonText: '<?= invHtml($LANG['delete']) ?>',
                cancelButtonText: '<?= invHtml($LANG['cancel']) ?>'
            }).then(function (r) { if (r.isConfirmed) doDelete(); });
        } else if (confirm('<?= invHtml($LANG['so_confirm_delete']) ?>')) {
            doDelete();
        }
    });
});
</script>