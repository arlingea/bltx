<?php
// inventory/inventory_out.php - Barang Keluar Tab
if (!isset($_SESSION['user_id'])) exit;

// Get filter parameters from URL
$from_date = !empty($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = !empty($_GET['to_date']) ? $_GET['to_date'] : '';
$item_id = !empty($_GET['item_id']) ? intval($_GET['item_id']) : '';
$taken_by = !empty($_GET['taken_by']) ? intval($_GET['taken_by']) : '';

// Use invQueryOut() from inventory_function.php if available, else fallback
if (function_exists('invQueryOut')) {
    $result = invQueryOut([
        'from_date' => $from_date,
        'to_date'   => $to_date,
        'item_id'   => $item_id,
        'taken_by'  => $taken_by,
    ]);
} else {
    // Fallback inline prepared query
    $where_conditions = [];
    $params           = [];
    $types            = '';

    if ($from_date && $to_date) {
        $where_conditions[] = "DATE(io.created_at) BETWEEN ? AND ?";
        $params[]           = $from_date;
        $params[]           = $to_date;
        $types             .= 'ss';
    }
    if ($item_id) {
        $where_conditions[] = 'io.inventory_id = ?';
        $params[]           = (int)$item_id;
        $types             .= 'i';
    }
    if ($taken_by) {
        $where_conditions[] = 'io.taken_by = ?';
        $params[]           = (int)$taken_by;
        $types             .= 'i';
    }

    $where_clause = count($where_conditions) > 0 ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $sql = "SELECT io.id, io.transaction_date, io.reference_number,
                   i.product_code, i.item_name,
                   io.quantity, u.symbol as unit_symbol,
                   -- Nama pengambil dibaca LANGSUNG dari kolom permanen
                   -- inventory_out.taken_by_name yang diisi saat baris ditulis,
                   -- sehingga tetap tampil setelah akun users-nya dihapus.
                   io.taken_by_name as taker_name,
                   io.notes, io.created_at
            FROM inventory_out io
            JOIN inventory i ON io.inventory_id = i.id
            LEFT JOIN units u ON io.unit_id = u.id
            $where_clause
            ORDER BY io.created_at DESC";

    if (empty($params)) {
        $result = $conn->query($sql);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    }
}

// Get list of users for filter dropdown
$users_sql = "SELECT DISTINCT u.id, u.full_name 
              FROM users u 
              INNER JOIN inventory_out io ON u.id = io.taken_by
              WHERE u.full_name IS NOT NULL AND u.full_name != ''
              ORDER BY u.full_name ASC";
$users_result = $conn->query($users_sql);

// Get list of items (product_code) for filter dropdown
$items_sql = "SELECT DISTINCT i.id, i.product_code, i.item_name 
              FROM inventory i 
              INNER JOIN inventory_out io ON i.id = io.inventory_id
              WHERE i.product_code IS NOT NULL AND i.product_code != ''
              ORDER BY i.product_code ASC";
$items_result = $conn->query($items_sql);

// Permission check for export
$_can_export_out = checkPermission('inventory_out', 'can_export');
?>

<!-- CSS Dependencies -->
<link rel="stylesheet" href="<?= url('assets/vendor/datatables/dataTables.bootstrap5.min.css') ?>">
<link rel="stylesheet" href="<?= url('assets/vendor/sweetalert2/sweetalert2.min.css') ?>">
<link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>">
<link rel="stylesheet" href="<?= url('assets/vendor/daterangepicker/daterangepicker.css') ?>">

<!-- JS Dependencies: dimuat via createElement agar tidak merusak DOM/event listener -->
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

    function loadSweetAlert(next) {
        if (typeof Swal !== 'undefined') { next && next(); return; }
        loadScript('<?= url('assets/vendor/sweetalert2/sweetalert2.min.js') ?>', next);
    }

    function start() {
        loadMoment(function() {
            loadDaterangepicker(function() {
                loadSweetAlert(function() {
                    document.dispatchEvent(new Event('depsOutReady'));
                });
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

/* Table styling */
.table {
    margin-bottom: 0;
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

/* Header styling */
h5 {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
}

/* Filter section styling */
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

/* Button groups styling */
.btn-group-custom {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.btn-group-custom .btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

/* Date range picker custom */
.daterangepicker-input {
    cursor: pointer;
}

/* Responsive design */
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
    
    .filter-section {
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
    
    .filter-section {
        padding: 0.5rem;
    }
    
    .btn-group-custom {
        width: 100%;
        margin-top: 0.5rem;
    }
    
    .btn-group-custom .btn {
        flex: 1;
        justify-content: center;
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
    
    .btn-group-custom .btn {
        font-size: 0.85rem;
        padding: 0.375rem 0.5rem;
    }
}

/* DataTables responsive styling */
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter,
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_paginate {
    padding: 0.5rem 0;
}

@media (max-width: 768px) {
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter {
        text-align: left;
        margin-bottom: 0.5rem;
    }
    
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
        text-align: center;
        margin-top: 0.5rem;
    }
}

/* Performance optimization */
* {
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

.table {
    will-change: contents;
}
</style>

<script>
// Initialize DataTable
function initInventoryOut() {
    if (jQuery.fn.DataTable.isDataTable('#inventoryOutTable')) {
        jQuery('#inventoryOutTable').DataTable().destroy();
    }

    jQuery('#inventoryOutTable').DataTable({
        responsive: true,
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
            null
        ],
        language: {
            <?php if ($current_lang === 'id'): ?>
            search: "Cari:",
            lengthMenu: "Tampilkan _MENU_ entri per halaman",
            info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri",
            infoEmpty: "Menampilkan 0 sampai 0 dari 0 entri",
            infoFiltered: "(difilter dari _MAX_ total entri)",
            paginate: {
                first: "Pertama",
                last: "Terakhir",
                next: "Selanjutnya",
                previous: "Sebelumnya"
            },
            zeroRecords: "Tidak ada data yang ditemukan",
            emptyTable: "Tidak ada data tersedia"
            <?php elseif ($current_lang === 'ch'): ?>
            search: "搜索：",
            lengthMenu: "每页显示 _MENU_ 条",
            info: "显示 _START_ 至 _END_ 条，共 _TOTAL_ 条",
            infoEmpty: "显示 0 至 0 条，共 0 条",
            infoFiltered: "（从 _MAX_ 条记录中筛选）",
            paginate: {
                first: "首页",
                last: "末页",
                next: "下一页",
                previous: "上一页"
            },
            zeroRecords: "没有找到数据",
            emptyTable: "没有可用数据"
            <?php else: ?>
            search: "Search:",
            lengthMenu: "Show _MENU_ entries per page",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoEmpty: "Showing 0 to 0 of 0 entries",
            infoFiltered: "(filtered from _MAX_ total entries)",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            },
            zeroRecords: "No data found",
            emptyTable: "No data available"
            <?php endif; ?>
        }
    });
}

// Initialize setelah semua dependency siap
document.addEventListener('depsOutReady', function() {
    initInventoryOut();
    initDaterangepicker();
});

if (typeof jQuery !== 'undefined' && typeof jQuery.fn.DataTable !== 'undefined') {
    initInventoryOut();
}

// Initialize Daterangepicker
function initDaterangepicker() {
    if (typeof jQuery !== 'undefined' && typeof moment !== 'undefined' && typeof $.fn.daterangepicker !== 'undefined') {
        $('#date_range').daterangepicker({
            autoUpdateInput: false,
            locale: {
                cancelLabel: '<?php echo $lang['clear']; ?>',
                applyLabel: '<?php echo $lang['apply']; ?>',
                format: 'YYYY-MM-DD',
                <?php if ($current_lang === 'id'): ?>
                daysOfWeek: ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'],
                monthNames: ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']
                <?php elseif ($current_lang === 'ch'): ?>
                daysOfWeek: ['日', '一', '二', '三', '四', '五', '六'],
                monthNames: ['一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月']
                <?php else: ?>
                daysOfWeek: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
                monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
                <?php endif; ?>
            }
        });

        $('#date_range').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));
            $('#from_date_hidden').val(picker.startDate.format('YYYY-MM-DD'));
            $('#to_date_hidden').val(picker.endDate.format('YYYY-MM-DD'));
        });

        $('#date_range').on('cancel.daterangepicker', function(ev, picker) {
            $(this).val('');
            $('#from_date_hidden').val('');
            $('#to_date_hidden').val('');
        });
        
        const fromDate = '<?php echo htmlspecialchars($_GET['from_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>';
        const toDate = '<?php echo htmlspecialchars($_GET['to_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>';
        
        if (fromDate && toDate) {
            $('#date_range').val(fromDate + ' - ' + toDate);
            $('#from_date_hidden').val(fromDate);
            $('#to_date_hidden').val(toDate);
        }
    } else {
        setTimeout(initDaterangepicker, 100);
    }
}

// Filter function
function applyFilter() {
    const dateRange = document.getElementById('date_range').value;
    const itemId = document.getElementById('item_filter').value;
    const takenBy = document.getElementById('taken_by_filter').value;
    
    let params = [];
    
    if (dateRange) {
        const dates = dateRange.split(' - ');
        params.push('from_date=' + encodeURIComponent(dates[0]));
        params.push('to_date=' + encodeURIComponent(dates[1]));
    }
    
    if (itemId) {
        params.push('item_id=' + encodeURIComponent(itemId));
    }
    
    if (takenBy) {
        params.push('taken_by=' + encodeURIComponent(takenBy));
    }
    
    if (params.length > 0) {
        window.location.href = '<?= url("inventory/inventory_gate.php?tab=inventory_out") ?>&' + params.join('&');
    } else {
        showFloatingAlert('<?php echo $lang['please_select_filter']; ?>', 'success');
    }
}

// Reset filter function
function resetFilter() {
    window.location.href = '<?= url("inventory/inventory_gate.php?tab=inventory_out") ?>';
}

// Export functions with confirmation
function exportInventoryOut(type) {
    const fromDate = document.getElementById('from_date_hidden').value;
    const toDate = document.getElementById('to_date_hidden').value;
    const itemId = document.getElementById('item_filter').value;
    const takenBy = document.getElementById('taken_by_filter').value;
    
    let filterDesc = [];
    
    if (fromDate && toDate) {
        filterDesc.push('<strong><?php echo $lang['period']; ?>:</strong> ' + fromDate + ' - ' + toDate);
    }
    
    if (itemId) {
        const itemName = document.getElementById('item_filter').options[document.getElementById('item_filter').selectedIndex].text;
        filterDesc.push('<strong><?php echo $lang['item']; ?>:</strong> ' + itemName);
    }
    
    if (takenBy) {
        const takenByName = document.getElementById('taken_by_filter').options[document.getElementById('taken_by_filter').selectedIndex].text;
        filterDesc.push('<strong><?php echo $lang['taken_by']; ?>:</strong> ' + takenByName);
    }
    
    let filterHtml = '';
    let warningHtml = '';
    
    if (filterDesc.length === 0) {
        warningHtml = '<div class="alert alert-warning mt-2"><i class="bi bi-exclamation-triangle"></i> <?php echo $lang['export_all_data']; ?></div>';
        filterHtml = '<div style="text-align: left; margin-top: 10px;"><strong><?php echo $lang['filter']; ?>:</strong> <?php echo $lang['all']; ?> (<?php echo $lang['no_filter']; ?>)</div>';
    } else {
        filterHtml = '<div style="text-align: left; margin-top: 10px;">' + filterDesc.join('<br>') + '</div>';
    }
    
    Swal.fire({
        title: '<?php echo $lang['confirm_export']; ?>',
        html: '<div style="text-align: left;"><strong><?php echo $lang['export_to']; ?>:</strong> ' + type.toUpperCase() + '</div>' + filterHtml + warningHtml,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: type === 'excel' ? '#28a745' : '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-check-circle"></i> <?php echo $lang['yes_export']; ?>',
        cancelButtonText: '<i class="bi bi-x-circle"></i> <?php echo $lang['cancel']; ?>',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            let url = '<?= url("inventory/export_inventory_out.php") ?>?type=' + type;
            
            if (fromDate && toDate) {
                url += '&from_date=' + encodeURIComponent(fromDate) + '&to_date=' + encodeURIComponent(toDate);
            }
            
            if (itemId) {
                url += '&item_id=' + encodeURIComponent(itemId);
            }
            
            if (takenBy) {
                url += '&taken_by=' + encodeURIComponent(takenBy);
            }
            
            Swal.fire({
                title: '<?php echo $lang['exporting']; ?>',
                html: '<?php echo $lang['please_wait']; ?>',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            window.location.href = url;
            
            setTimeout(() => {
                Swal.close();
                showFloatingAlert('<?php echo $lang['file_downloaded']; ?>', 'success');
            }, 2000);
        }
    });
}
</script>

<div class="card border-0 shadow-sm">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-box-arrow-up"></i> <?php echo $lang['inventory_out']; ?></div>
    </div>
    <div class="card-body">
        <!-- ============================================================
             KESELURUHAN WRAP CARD FILTER INVENTORY OUT
             Seluruh section filter (form + semua tombol termasuk export)
             HANYA tampil jika can_export = true.
             Jika can_export = false → seluruh wrap tidak tampil sama sekali.
             ============================================================ -->
        <?php if ($_can_export_out): ?>
        <div class="filter-section">
            <div class="row g-3">
                <div class="col-md-4 col-sm-6">
                    <label for="date_range" class="form-label small"><?php echo $lang['date_range']; ?></label>
                    <input type="text" class="form-control form-control-sm daterangepicker-input" id="date_range" name="date_range" placeholder="<?php echo $lang['select_date_range']; ?>" readonly>
                    <input type="hidden" id="from_date_hidden" name="from_date_hidden" value="<?php echo htmlspecialchars($_GET['from_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" id="to_date_hidden" name="to_date_hidden" value="<?php echo htmlspecialchars($_GET['to_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-4 col-sm-6">
                    <label for="item_filter" class="form-label small"><?php echo $lang['item']; ?></label>
                    <select class="form-select form-select-sm" id="item_filter" name="item_filter">
                        <option value=""><?php echo $lang['all']; ?></option>
                        <?php 
                        if ($items_result && $items_result->num_rows > 0):
                            while ($item = $items_result->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $item['id']; ?>" <?php echo (isset($_GET['item_id']) && $_GET['item_id'] == $item['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($item['product_code'], ENT_QUOTES, 'UTF-8') . ' - ' . htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php 
                            endwhile;
                        endif;
                        ?>
                    </select>
                </div>
                <div class="col-md-4 col-sm-6">
                    <label for="taken_by_filter" class="form-label small"><?php echo $lang['taken_by']; ?></label>
                    <select class="form-select form-select-sm" id="taken_by_filter" name="taken_by_filter">
                        <option value=""><?php echo $lang['all']; ?></option>
                        <?php 
                        if ($users_result && $users_result->num_rows > 0):
                            while ($user = $users_result->fetch_assoc()): 
                        ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo (isset($_GET['taken_by']) && $_GET['taken_by'] == $user['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php 
                            endwhile;
                        endif;
                        ?>
                    </select>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary btn-sm" onclick="applyFilter()">
                            <i class="bi bi-funnel"></i> <?php echo $lang['filter']; ?>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="resetFilter()">
                            <i class="bi bi-arrow-clockwise"></i> <?php echo $lang['reset']; ?>
                        </button>
                        <button type="button" class="btn btn-success btn-sm" onclick="exportInventoryOut('excel')">
                            <i class="bi bi-file-earmark-excel"></i> <?php echo $lang['export_excel']; ?>
                        </button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="exportInventoryOut('pdf')">
                            <i class="bi bi-file-earmark-pdf"></i> <?php echo $lang['export_pdf']; ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Table Section -->
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="inventoryOutTable">
                <thead>
                    <tr>
                        <th><?php echo $lang['no']; ?></th>
                        <th><?php echo $lang['transaction_date']; ?></th>
                        <th><?php echo $lang['product_code']; ?></th>
                        <th><?php echo $lang['item_name']; ?></th>
                        <th><?php echo $lang['quantity']; ?></th>
                        <th><?php echo $lang['taken_by']; ?></th>
                        <th><?php echo $lang['reference_number']; ?></th>
                        <th><?php echo $lang['notes']; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    if ($result && $result->num_rows > 0):
                        while ($row = $result->fetch_assoc()): 
                            $qty = floatval($row['quantity']);
                            $unit = $row['unit_symbol'] ?? '';
                            $qty_formatted = ($qty == floor($qty)) ? number_format($qty, 0) : rtrim(rtrim(number_format($qty, 2), '0'), '.');
                            $qty_display = $qty_formatted . ($unit ? ' ' . $unit : '');
                    ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td data-order="<?php echo $row['created_at']; ?>"><?php echo date('d/m/Y H:i:s', strtotime($row['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($row['product_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['item_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $qty_display; ?></td>
                        <td><?php echo htmlspecialchars($row['taker_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['reference_number'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['notes'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>