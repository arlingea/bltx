<?php
// Direct-access guard (added by audit)
if (!defined("APP_NAME")) { http_response_code(403); exit("Direct access not permitted."); }

// ================================================================
// INVENTORY IN LIST - MENAMPILKAN DATA INVENTORY IN
// ================================================================
if (
    ($_GET['action'] ?? '') === 'add' &&
    !isset($_GET['allow_transaction'])
) {
    return;
}

if (!isset($conn)) {
    exit('Direct access not allowed');
}

// Izin dibaca lewat helper terpusat di includes/global_permission.php —
// LANGSUNG dari tabel `permissions` dan sekaligus menegakkan gerbang induk
// modul 'inventory'. Modul izinnya TETAP 'inventory_in' yang sudah ada,
// jadi tidak ada permission ganda.
if (!inventoryModuleCan('inventory_in', 'can_read')) {
    redirect('?tab=inventory_data');
}

// Get filters — date kosong = tidak ada filter tanggal (tampilkan semua data)
$filter_from     = (isset($_GET['from_date']) && $_GET['from_date'] !== '') ? $_GET['from_date'] : '';
$filter_to       = (isset($_GET['to_date'])   && $_GET['to_date']   !== '') ? $_GET['to_date']   : '';
$filter_user     = isset($_GET['user_id'])     ? intval($_GET['user_id'])     : 0;
$filter_category = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

// Use invQueryIn() from inventory_function.php if available, else fallback
if (function_exists('invQueryIn')) {
    $result = invQueryIn([
        'from_date'   => $filter_from,
        'to_date'     => $filter_to,
        'user_id'     => $filter_user,
        'category_id' => $filter_category,
    ]);
} else {
    // Fallback inline prepared query
    $where_clauses = [];
    $params        = [];
    $types         = '';

    if ($filter_from !== '' && $filter_to !== '') {
        $where_clauses[] = "DATE(ii.created_at) BETWEEN ? AND ?";
        $params[]        = $filter_from;
        $params[]        = $filter_to;
        $types          .= 'ss';
    }
    if ($filter_user > 0) {
        $where_clauses[] = 'ii.created_by = ?';
        $params[]        = $filter_user;
        $types          .= 'i';
    }
    if ($filter_category > 0) {
        $where_clauses[] = 'i.category_id = ?';
        $params[]        = $filter_category;
        $types          .= 'i';
    }

    $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    /* ii.created_by_name AS full_name — nama pencatat dibaca LANGSUNG dari
       kolom permanen di baris inventory_in itu sendiri, tidak lagi lewat JOIN
       ke tabel users, sehingga tetap tampil setelah akun users-nya dihapus. */
    $sql = "SELECT ii.*, i.product_code, i.item_name, i.brand, u.symbol, ii.created_by_name AS full_name, c.name as category_name
            FROM inventory_in ii
            JOIN inventory i ON ii.inventory_id = i.id
            LEFT JOIN units u ON ii.unit_id = u.id
            LEFT JOIN categories c ON i.category_id = c.id
            $where_sql
            ORDER BY ii.created_at DESC";

    if (empty($params)) {
        $result = $conn->query($sql);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    }
}

// Get users list for filter
$users_result = function_exists('invGetUsers')
    ? invGetUsers()
    : $conn->query("SELECT id, full_name FROM users ORDER BY full_name");

// Get categories list for filter
$categories_result = function_exists('invGetCategories')
    ? invGetCategories()
    : $conn->query("SELECT id, name FROM categories ORDER BY name");

// Permission checks — sumber tunggal: includes/global_permission.php
$_can_create_in  = inventoryModuleCan('inventory_in', 'can_create');
$_can_export_in  = inventoryModuleCan('inventory_in', 'can_export');

// Format quantity
function formatQty($qty) {
    $qty = floatval($qty);
    return ($qty == floor($qty)) ? number_format($qty, 0) : rtrim(rtrim(number_format($qty, 2), '0'), '.');
}
?>

<!-- SweetAlert2 CSS & JS -->
<link rel="stylesheet" href="<?php echo url('assets/vendor/sweetalert2/sweetalert2.min.css'); ?>">
<!-- TAHAP 4 — PEMUATAN ULANG ASET DIHAPUS.
     Baris ini memuat lagi sweetalert2.min.js, padahal includes/footer.php
     sudah memuat berkas yang sama persis. Tiga tag script lain di folder
     inventory (html5-qrcode dan pemindai stock opname) TETAP, karena
     footer.php memang tidak memuat keduanya. -->

<!-- DataTables CSS & JS -->
<link rel="stylesheet" href="<?php echo url('assets/vendor/datatables/dataTables.bootstrap5.min.css'); ?>">

<!-- Daterangepicker CSS -->
<link rel="stylesheet" href="<?php echo url('assets/vendor/daterangepicker/daterangepicker.css'); ?>">

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

/* Table styling */
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

/* Button styling */
.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Header styling */
h5 {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
}

/* Filter card */
.filter-card {
    background-color: #f8f9fa;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.filter-card .row {
    align-items: end;
}

/* Daterangepicker input cursor pointer */
.daterangepicker-input {
    cursor: pointer;
}

/* Responsive */
@media (max-width: 992px) {
    .card-body {
        padding: 1rem;
    }

    .card-header {
        padding: 0.75rem 1rem;
    }

    .table th, .table td {
        padding: 0.5rem;
        font-size: 0.875rem;
    }

    h5 {
        font-size: 1.1rem;
    }

    .filter-card {
        padding: 0.75rem;
    }
}

@media (max-width: 768px) {
    h5 {
        font-size: 1rem;
    }

    .card-body {
        padding: 0.75rem;
    }

    .card-header {
        padding: 0.5rem 0.75rem;
    }

    .filter-card {
        padding: 0.5rem;
    }
}

@media (max-width: 576px) {
    .table th, .table td {
        padding: 0.4rem;
        font-size: 0.8rem;
    }

    h5 {
        font-size: 0.95rem;
    }
}
</style>

<div class="card border-0 shadow-sm">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-box-arrow-in-down"></i> <?php echo $lang['inventory_in']; ?></div>
        <?php if ($_can_create_in): ?>
        <div class="card-tools">
            <a href="<?php echo url('inventory/inventory_gate.php?tab=inventory_in&action=add'); ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle"></i> <?php echo $lang['add_inventory']; ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="card-body">
        
        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- ============================================================
             KESELURUHAN WRAP CARD FILTER INVENTORY IN
             Seluruh section filter (form + semua tombol termasuk export)
             HANYA tampil jika can_export = true.
             Jika can_export = false → seluruh wrap tidak tampil sama sekali.
             ============================================================ -->
        <?php if ($_can_export_in): ?>
        <div class="filter-card">
            <div class="row g-3">
                <div class="col-md-4 col-sm-6">
                    <label class="form-label small"><?php echo $lang['date_range']; ?></label>
                    <input type="text" class="form-control form-control-sm daterangepicker-input" id="date_range_in" placeholder="<?php echo $lang['select_date_range']; ?>" readonly>
                    <input type="hidden" id="from_date_in_hidden" value="<?php echo htmlspecialchars($filter_from, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" id="to_date_in_hidden" value="<?php echo htmlspecialchars($filter_to, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-4 col-sm-6">
                    <label class="form-label small"><?php echo $lang['created_by']; ?></label>
                    <select id="user_id_in" class="form-select form-select-sm">
                        <option value="0"><?php echo $lang['all']; ?></option>
                        <?php while ($user = $users_result->fetch_assoc()): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $filter_user == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-4 col-sm-6">
                    <label class="form-label small"><?php echo $lang['category']; ?></label>
                    <select id="category_id_in" class="form-select form-select-sm">
                        <option value="0"><?php echo $lang['all']; ?></option>
                        <?php while ($cat = $categories_result->fetch_assoc()): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary btn-sm" onclick="applyFilterIn()">
                            <i class="bi bi-funnel"></i> <?php echo $lang['filter']; ?>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="resetFilterIn()">
                            <i class="bi bi-arrow-clockwise"></i> <?php echo $lang['reset']; ?>
                        </button>
                        <button type="button" class="btn btn-success btn-sm" id="btnExportExcelIn">
                            <i class="bi bi-file-earmark-excel"></i> <?php echo $lang['export_excel']; ?>
                        </button>
                        <button type="button" class="btn btn-danger btn-sm" id="btnExportPdfIn">
                            <i class="bi bi-file-earmark-pdf"></i> <?php echo $lang['export_pdf']; ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Data Table -->
        <div class="table-responsive">
            <table id="inventoryInTable" class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th><?php echo $lang['no']; ?></th>
                        <th><?php echo $lang['transaction_date']; ?></th>
                        <th><?php echo $lang['product_code']; ?></th>
                        <th><?php echo $lang['item_name']; ?></th>
                        <th><?php echo $lang['category']; ?></th>
                        <th><?php echo $lang['quantity']; ?></th>
                        <th><?php echo $lang['reference_number']; ?></th>
                        <th><?php echo $lang['created_by']; ?></th>
                        <th><?php echo $lang['notes']; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if ($result && $result->num_rows > 0):
                        $no = 1;
                        while ($row = $result->fetch_assoc()):
                    ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td data-order="<?php echo $row['created_at']; ?>"><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($row['product_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['item_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['category_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo formatQty($row['quantity']) . ' ' . htmlspecialchars($row['symbol'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['reference_number'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['full_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['notes'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    
    function init() {
        if (typeof jQuery === 'undefined') {
            setTimeout(init, 100);
            return;
        }
        
        jQuery(document).ready(function($) {
            // Initialize DataTable
            if ($.fn.DataTable) {
                if ($.fn.DataTable.isDataTable('#inventoryInTable')) {
                    $('#inventoryInTable').DataTable().destroy();
                }

                $('#inventoryInTable').DataTable({
                    pageLength: 25,
                    order: [[1, 'desc']],
                    columns: [
                        { orderable: false },
                        { type: 'string' },
                        null,
                        null,
                        null,
                        null,
                        null,
                        null,
                        null
                    ],
                    language: {
                        search: "<?php echo $lang['search']; ?>:",
                        lengthMenu: "<?php echo $lang['show']; ?> _MENU_ <?php echo $lang['entries']; ?>",
                        info: "<?php echo $lang['showing']; ?> _START_ <?php echo $lang['to']; ?> _END_ <?php echo $lang['of']; ?> _TOTAL_ <?php echo $lang['entries']; ?>",
                        infoEmpty: "<?php echo $lang['showing']; ?> 0 <?php echo $lang['to']; ?> 0 <?php echo $lang['of']; ?> 0 <?php echo $lang['entries']; ?>",
                        emptyTable: "<?php echo $lang['no_data']; ?>",
                        zeroRecords: "<?php echo $lang['no_data']; ?>",
                        paginate: {
                            first: "<?php echo $lang['first']; ?>",
                            last: "<?php echo $lang['last']; ?>",
                            next: "<?php echo $lang['next']; ?>",
                            previous: "<?php echo $lang['previous']; ?>"
                        }
                    }
                });
            }
            
            // Export Excel with SweetAlert
            $('#btnExportExcelIn').on('click', function() {
                Swal.fire({
                    title: '<?php echo $lang['export_confirmation']; ?>',
                    text: '<?php echo $lang['export_excel_message']; ?>',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#198754',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<i class="bi bi-check-circle"></i> <?php echo $lang['yes_export']; ?>',
                    cancelButtonText: '<i class="bi bi-x-circle"></i> <?php echo $lang['cancel']; ?>'
                }).then((result) => {
                    if (result.isConfirmed) {
                        var fromDate = document.getElementById('from_date_in_hidden').value;
                        var toDate = document.getElementById('to_date_in_hidden').value;
                        var userId = document.getElementById('user_id_in').value;
                        var categoryId = document.getElementById('category_id_in').value;
                        var url = '<?php echo url('inventory/export_inventory_in.php'); ?>' +
                                  '?type=excel' +
                                  '&from_date=' + encodeURIComponent(fromDate) +
                                  '&to_date=' + encodeURIComponent(toDate) +
                                  '&user_id=' + encodeURIComponent(userId) +
                                  '&category_id=' + encodeURIComponent(categoryId);
                        window.location.href = url;
                    }
                });
            });
            
            // Export PDF with SweetAlert
            $('#btnExportPdfIn').on('click', function() {
                Swal.fire({
                    title: '<?php echo $lang['export_confirmation']; ?>',
                    text: '<?php echo $lang['export_pdf_message']; ?>',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<i class="bi bi-check-circle"></i> <?php echo $lang['yes_export']; ?>',
                    cancelButtonText: '<i class="bi bi-x-circle"></i> <?php echo $lang['cancel']; ?>'
                }).then((result) => {
                    if (result.isConfirmed) {
                        var fromDate = document.getElementById('from_date_in_hidden').value;
                        var toDate = document.getElementById('to_date_in_hidden').value;
                        var userId = document.getElementById('user_id_in').value;
                        var categoryId = document.getElementById('category_id_in').value;
                        var url = '<?php echo url('inventory/export_inventory_in.php'); ?>' +
                                  '?type=pdf' +
                                  '&from_date=' + encodeURIComponent(fromDate) +
                                  '&to_date=' + encodeURIComponent(toDate) +
                                  '&user_id=' + encodeURIComponent(userId) +
                                  '&category_id=' + encodeURIComponent(categoryId);
                        window.location.href = url;
                    }
                });
            });
        });
    }
    
    init();
})();

// Initialize Daterangepicker untuk inventory_in
function initDaterangepickerIn() {
    if (typeof jQuery !== 'undefined' && typeof moment !== 'undefined' && typeof $.fn.daterangepicker !== 'undefined') {
        $('#date_range_in').daterangepicker({
            autoUpdateInput: false,
            locale: {
                cancelLabel: '<?php echo $lang['clear']; ?>',
                applyLabel: '<?php echo $lang['apply']; ?>',
                format: 'YYYY-MM-DD',
                <?php if (isset($current_lang) && $current_lang === 'id'): ?>
                daysOfWeek: ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'],
                monthNames: ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']
                <?php elseif (isset($current_lang) && $current_lang === 'ch'): ?>
                daysOfWeek: ['日', '一', '二', '三', '四', '五', '六'],
                monthNames: ['一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月']
                <?php else: ?>
                daysOfWeek: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
                monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
                <?php endif; ?>
            }
        });

        $('#date_range_in').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));
            $('#from_date_in_hidden').val(picker.startDate.format('YYYY-MM-DD'));
            $('#to_date_in_hidden').val(picker.endDate.format('YYYY-MM-DD'));
        });

        $('#date_range_in').on('cancel.daterangepicker', function(ev, picker) {
            $(this).val('');
            $('#from_date_in_hidden').val('');
            $('#to_date_in_hidden').val('');
        });

        var fromDate = '<?php echo htmlspecialchars($filter_from, ENT_QUOTES, 'UTF-8'); ?>';
        var toDate = '<?php echo htmlspecialchars($filter_to, ENT_QUOTES, 'UTF-8'); ?>';
        if (fromDate && toDate) {
            $('#date_range_in').val(fromDate + ' - ' + toDate);
        }
    } else {
        setTimeout(initDaterangepickerIn, 100);
    }
}

// Load vendor daterangepicker dari local assets
(function loadDrpIn() {
    function loadScript(src, onload) {
        var s = document.createElement('script');
        s.src = src;
        s.onload = onload || null;
        document.head.appendChild(s);
    }

    function tryInit() {
        if (typeof jQuery !== 'undefined' && typeof moment !== 'undefined') {
            if (typeof $.fn.daterangepicker === 'undefined') {
                loadScript('<?php echo url('assets/vendor/daterangepicker/daterangepicker.min.js'); ?>', initDaterangepickerIn);
            } else {
                initDaterangepickerIn();
            }
        } else {
            setTimeout(tryInit, 100);
        }
    }

    // Load moment dari local vendor terlebih dahulu
    if (typeof moment === 'undefined') {
        loadScript('<?php echo url('assets/vendor/moment/moment.min.js'); ?>', tryInit);
    } else {
        tryInit();
    }
})();

// Apply filter menggunakan hidden input dari daterangepicker
function applyFilterIn() {
    var fromDate = document.getElementById('from_date_in_hidden').value;
    var toDate = document.getElementById('to_date_in_hidden').value;
    var userId = document.getElementById('user_id_in').value;
    var categoryId = document.getElementById('category_id_in').value;

    var params = ['tab=inventory_in', 'action=list'];

    if (fromDate && toDate) {
        params.push('from_date=' + encodeURIComponent(fromDate));
        params.push('to_date=' + encodeURIComponent(toDate));
    }

    if (userId && userId !== '0') {
        params.push('user_id=' + encodeURIComponent(userId));
    }

    if (categoryId && categoryId !== '0') {
        params.push('category_id=' + encodeURIComponent(categoryId));
    }

    window.location.href = '<?php echo url('inventory/inventory_gate.php'); ?>?' + params.join('&');
}

// Reset filter
function resetFilterIn() {
    window.location.href = '<?php echo url('inventory/inventory_gate.php?tab=inventory_in&action=list'); ?>';
}
</script>