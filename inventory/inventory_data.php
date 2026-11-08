<?php
// File: inventory/inventory_data.php
// Note: This file is included in index.php, so no need for header/footer

if (!checkPermission('inventory', 'can_access')) {
    redirect('dashboard.php');
}

// Ambil data departemen untuk dropdown klasifikasi
$departments = [];
$dept_query = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
if ($dept_query) {
    while ($d = $dept_query->fetch_assoc()) {
        $departments[] = $d;
    }
}

$action = $_GET['action'] ?? '';
$active_tab = $_GET['tab'] ?? '';

if ($action === 'edit' && isset($_GET['id'])) {
    include __DIR__ . '/inventory_edit.php';
    return;
}

// Delete — POST-only CSRF-protected
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'delete_inventory') {
    if (!isset($_POST['_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_token'])) {
        $_SESSION['error'] = $LANG['invalid_token'] ?? 'Invalid CSRF token';
        redirect('?tab=inventory_data');
    }
    if (!checkPermission('inventory', 'can_delete')) {
        $_SESSION['error'] = $LANG['no_permission'] ?? 'No permission';
        redirect('?tab=inventory_data');
    }
    $id = intval($_POST['delete_id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("SELECT item_image FROM inventory WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($image);
        if ($stmt->fetch()) {
            if ($image && file_exists(UPLOAD_PATH . $image)) {
                unlink(UPLOAD_PATH . $image);
            }
        }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            logActivity('delete_inventory', "Deleted inventory ID: $id");
            $_SESSION['success'] = $LANG['delete_success'] ?? 'Item deleted successfully';
        }
        $stmt->close();
    }
    redirect('?tab=inventory_data');
}

// Toggle Status — POST AJAX Handler (Tanpa Refresh Halaman)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'toggle_status') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    if (!isset($_POST['_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_token'])) {
        echo json_encode(['success' => false, 'message' => $LANG['invalid_token'] ?? 'Invalid CSRF token']);
        exit;
    }
    if (!checkPermission('inventory', 'can_edit') && !checkPermission('inventory', 'can_update')) {
        echo json_encode(['success' => false, 'message' => $LANG['no_permission'] ?? 'No permission']);
        exit;
    }
    
    $id = intval($_POST['toggle_id'] ?? 0);
    $new_status = intval($_POST['new_status'] ?? 0);
    
    if ($id > 0) {
        $success = false;
        if (function_exists('invToggleStatus')) {
            $success = invToggleStatus($id, $new_status);
        } else {
            $stmt = $conn->prepare("UPDATE inventory SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_status, $id);
            $success = $stmt->execute();
            $stmt->close();
        }

        if ($success) {
            logActivity('toggle_inventory_status', "Toggled inventory ID: $id to status: $new_status");
            echo json_encode(['success' => true, 'new_status' => $new_status, 'message' => $LANG['update_success'] ?? 'Status berhasil diperbarui.']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => $LANG['update_failed'] ?? 'Gagal mengubah status barang.']);
    exit;
}

// Update Classification — POST AJAX Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'update_classification') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    if (!isset($_POST['_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['_token'])) {
        echo json_encode(['success' => false, 'message' => $LANG['invalid_token'] ?? 'Invalid CSRF token']);
        exit;
    }
    if (!checkPermission('inventory', 'can_edit') && !checkPermission('inventory', 'can_update')) {
        echo json_encode(['success' => false, 'message' => $LANG['no_permission'] ?? 'No permission']);
        exit;
    }
    
    $id = intval($_POST['id'] ?? 0);
    $classification_id = empty($_POST['classification_id']) ? null : intval($_POST['classification_id']);
    
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE inventory SET classification_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $classification_id, $id);
        if ($stmt->execute()) {
            logActivity('update_inventory_classification', "Updated classification for inventory ID: $id to " . ($classification_id ?: 'NULL'));
            echo json_encode(['success' => true, 'message' => $LANG['update_success'] ?? 'Klasifikasi berhasil diperbarui.']);
            exit;
        }
        $stmt->close();
    }

    echo json_encode(['success' => false, 'message' => $LANG['update_failed'] ?? 'Gagal mengubah klasifikasi.']);
    exit;
}

// Build query with filters
$filter_item = $_GET['filter_item'] ?? '';
$filter_category = $_GET['filter_category'] ?? '';
$filter_supplier = $_GET['filter_supplier'] ?? '';
$filter_location = $_GET['filter_location'] ?? '';
$filter_min_stock = $_GET['filter_min_stock'] ?? '';

if (function_exists('invQueryData')) {
    $result = invQueryData([
        'filter_item'      => $filter_item,
        'filter_category'  => $filter_category,
        'filter_supplier'  => $filter_supplier,
        'filter_location'  => $filter_location,
        'filter_min_stock' => $filter_min_stock,
    ]);
} else {
    // Fallback inline query (is_active DESC = status aktif (1) di atas, nonaktif (0) di bawah)
    $conditions = ['1=1'];
    $params     = [];
    $types      = '';

    if (!empty($filter_item)) {
        $conditions[] = 'i.item_name = ?';
        $params[]     = $filter_item;
        $types       .= 's';
    }
    if (!empty($filter_category)) {
        $conditions[] = 'i.category_id = ?';
        $params[]     = (int)$filter_category;
        $types       .= 'i';
    }
    if (!empty($filter_supplier)) {
        $conditions[] = 'i.supplier_id = ?';
        $params[]     = (int)$filter_supplier;
        $types       .= 'i';
    }
    if (!empty($filter_location)) {
        $conditions[] = 'i.location_id = ?';
        $params[]     = (int)$filter_location;
        $types       .= 'i';
    }
    if ($filter_min_stock !== '') {
        $conditions[] = 'i.quantity <= ?';
        $params[]     = (float)$filter_min_stock;
        $types       .= 'd';
    }

    $where = implode(' AND ', $conditions);
    
    $sql = "SELECT i.*,
                   c.name   AS category_name,
                   s.name   AS supplier_name,
                   u.name   AS unit_name,
                   u.symbol AS unit_symbol,
                   uqpu.symbol AS qpu_unit_symbol,
                   wl.location_code,
                   wl.location_name,
                   (i.quantity * i.unit_price) AS total_value
            FROM inventory i
            LEFT JOIN categories c           ON i.category_id        = c.id
            LEFT JOIN suppliers  s           ON i.supplier_id        = s.id
            LEFT JOIN units      u           ON i.unit_id            = u.id
            LEFT JOIN units      uqpu        ON i.qty_per_unit_unit_id = uqpu.id
            LEFT JOIN warehouse_locations wl ON i.location_id        = wl.id
            WHERE $where
            ORDER BY i.is_active DESC, i.created_at ASC, i.id ASC";

    if (empty($params)) {
        $result = $conn->query($sql);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    }
}

// Get dropdown option results
$items_result = function_exists('invGetItemNames') ? invGetItemNames() : $conn->query("SELECT DISTINCT item_name FROM inventory ORDER BY item_name ASC");
$categories_result = function_exists('invGetCategories') ? invGetCategories() : $conn->query("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name ASC");
$suppliers_result = function_exists('invGetSuppliers') ? invGetSuppliers() : $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC");
$locations_result = function_exists('invGetLocations') ? invGetLocations() : $conn->query("SELECT id, location_code, location_name FROM warehouse_locations WHERE is_active = 1 ORDER BY location_code ASC");

// Currency & Utility settings
$currency_symbol = getSetting('currency_symbol', 'Rp');
$current_currency = getSetting('currency', 'IDR');

function formatNumber($number) {
    $formatted = number_format($number, 2, ',', '.');
    $formatted = preg_replace('/,0+$/', '', $formatted);
    $formatted = preg_replace('/,(\d)0$/', ',$1', $formatted);
    return $formatted;
}

$export_url = url('includes/export/export_inventory.php');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$_can_create = checkPermission('inventory', 'can_create');
$_can_view   = checkPermission('inventory', 'can_read');
$_can_edit   = checkPermission('inventory', 'can_edit') || checkPermission('inventory', 'can_update');
$_can_delete = checkPermission('inventory', 'can_delete');
$_can_export = checkPermission('inventory_data', 'can_export');
$_can_print  = canPrintInventoryBarcode();

$print_barcode_url = url('inventory/print_barcode.php');

$_js_print_url    = json_encode($print_barcode_url);
$_js_export_url   = substr(bltx_js($export_url), 1, -1);

$_jl_processing   = substr(bltx_js($LANG['processing'] ?? 'Processing...'), 1, -1);
$_jl_search       = substr(bltx_js($LANG['search'] ?? 'Search'), 1, -1);
$_jl_show         = substr(bltx_js($LANG['show'] ?? 'Show'), 1, -1);
$_jl_entries      = substr(bltx_js($LANG['entries'] ?? 'entries'), 1, -1);
$_jl_showing      = substr(bltx_js($LANG['showing'] ?? 'Showing'), 1, -1);
$_jl_to           = substr(bltx_js($LANG['to'] ?? 'to'), 1, -1);
$_jl_of           = substr(bltx_js($LANG['of'] ?? 'of'), 1, -1);
$_jl_filtered     = substr(bltx_js($LANG['filtered_from'] ?? 'filtered'), 1, -1);
$_jl_from         = substr(bltx_js($LANG['from'] ?? 'from'), 1, -1);
$_jl_total        = substr(bltx_js($LANG['total_entries'] ?? 'total'), 1, -1);
$_jl_first        = substr(bltx_js($LANG['first'] ?? 'First'), 1, -1);
$_jl_last         = substr(bltx_js($LANG['last'] ?? 'Last'), 1, -1);
$_jl_next         = substr(bltx_js($LANG['next'] ?? 'Next'), 1, -1);
$_jl_previous     = substr(bltx_js($LANG['previous'] ?? 'Previous'), 1, -1);

$_jl_sure         = substr(bltx_js($LANG['are_you_sure'] ?? 'Are you sure?'), 1, -1);
$_jl_del_confirm  = substr(bltx_js($LANG['confirm_delete'] ?? 'You want to delete this data?'), 1, -1);
$_jl_yes          = substr(bltx_js($LANG['yes'] ?? 'Yes'), 1, -1);
$_jl_delete       = substr(bltx_js($LANG['delete'] ?? 'Delete'), 1, -1);
$_jl_cancel       = substr(bltx_js($LANG['cancel'] ?? 'Cancel'), 1, -1);
$_jl_exp_confirm  = substr(bltx_js($LANG['confirm_export'] ?? 'Confirm Export'), 1, -1);
$_jl_export_to    = substr(bltx_js($LANG['export_to'] ?? 'Export to'), 1, -1);
$_jl_yes_export   = substr(bltx_js($LANG['yes_export'] ?? 'Yes, Export'), 1, -1);
$_jl_exporting    = substr(bltx_js($LANG['exporting'] ?? 'Exporting...'), 1, -1);
$_jl_please_wait  = substr(bltx_js($LANG['please_wait'] ?? 'Please wait'), 1, -1);
$_jl_exp_success  = substr(bltx_js($LANG['export_success'] ?? 'Export Success'), 1, -1);
$_jl_downloaded   = substr(bltx_js($LANG['file_downloaded'] ?? 'File downloaded'), 1, -1);
$_jl_all_data     = substr(bltx_js($LANG['export_all_data'] ?? 'Export All Data'), 1, -1);
$_jl_item_name    = substr(bltx_js($LANG['item_name'] ?? 'Item Name'), 1, -1);
$_jl_category     = substr(bltx_js($LANG['category'] ?? 'Category'), 1, -1);
$_jl_supplier     = substr(bltx_js($LANG['supplier'] ?? 'Supplier'), 1, -1);
$_jl_location     = substr(bltx_js($LANG['warehouse_location'] ?? 'Location'), 1, -1);
$_jl_min_stock    = substr(bltx_js($LANG['min_stock'] ?? 'Min Stock'), 1, -1);
$_jl_all          = substr(bltx_js($LANG['all'] ?? 'All'), 1, -1);
$_jl_no_filter    = substr(bltx_js($LANG['no_filter'] ?? 'No Filter'), 1, -1);
$_jl_filter_lbl   = substr(bltx_js($LANG['filter'] ?? 'Filter'), 1, -1);

$_jl_warning      = substr(bltx_js($LANG['warning'] ?? 'Warning'), 1, -1);
$_jl_no_barcode   = substr(bltx_js($LANG['barcode_not_available'] ?? 'Barcode not available'), 1, -1);
$_jl_print_bc     = substr(bltx_js($LANG['print_barcode'] ?? 'Print Barcode'), 1, -1);
$_jl_printer      = substr(bltx_js($LANG['printer'] ?? 'Printer'), 1, -1);
$_jl_def_printer  = substr(bltx_js($LANG['default_printer'] ?? 'Default Printer'), 1, -1);
$_jl_prt_note     = substr(bltx_js($LANG['print_dialog_printer_note'] ?? 'Make sure your printer is ready'), 1, -1);
$_jl_copies       = substr(bltx_js($LANG['copies'] ?? 'Copies'), 1, -1);
$_jl_copies_inv   = substr(bltx_js($LANG['copies_invalid'] ?? 'Invalid Copies'), 1, -1);
$_jl_active       = substr(bltx_js($LANG['active'] ?? 'Active'), 1, -1);
$_jl_inactive     = substr(bltx_js($LANG['inactive'] ?? 'Inactive'), 1, -1);
$_jl_upd_success  = substr(bltx_js($LANG['update_success'] ?? 'Data updated successfully'), 1, -1);
$_jl_upd_failed   = substr(bltx_js($LANG['update_failed'] ?? 'Failed to update data'), 1, -1);
$_jl_err_system   = substr(bltx_js($LANG['error_occurred'] ?? 'An error occurred'), 1, -1);

// ── $extra_js ────────
$extra_js = <<<EXTRAJS
<style>
/* Skeleton loading */
.skeleton {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
    border-radius: 4px;
}

@keyframes loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* Image optimization */
.item-thumbnail {
    display: block;
    margin: 0 auto;
    width: 80px;
    height: 60px;
    object-fit: cover;
    cursor: pointer;
    border-radius: 4px;
    transition: transform 0.2s ease, opacity 0.2s ease;
    background: #f8f9fa;
    opacity: 0;
}

.item-thumbnail.loaded {
    opacity: 1;
}

.item-thumbnail:hover {
    transform: scale(1.1);
}

.item-placeholder {
    display: inline-block;
    width: 80px;
    height: 60px;
    background: #e9ecef;
    border-radius: 4px;
    position: relative;
}

.item-placeholder::after {
    content: "📦";
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 24px;
    opacity: 0.3;
}

/* LOW STOCK WARNING */
td.low-stock {
    color: #dc3545 !important;
    font-weight: 700;
}

td.warning-stock {
    color: #ffc107 !important;
    font-weight: 600;
}

/* Table responsive */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

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

/* Modal styling */
/* CSS #itemImg dihapus — gaya gambar kini di lightbox bersama. */

/* DIHAPUS: .modal-content.bg-transparent { background-color: rgba(0,0,0,0.9) }

   Aturan ini memaksa latar hitam pada SETIAP .modal-content.bg-transparent,
   termasuk lightbox bersama di includes/footer.php. Akibatnya kotak modal
   tampak sebagai persegi gelap yang lebih besar dari gambarnya — ruang kosong
   di sisi kiri-kanan atau atas-bawah, tergantung proporsi gambar.

   Selektornya juga BOCOR ke luar halaman ini: kelas .modal-content
   .bg-transparent dipakai lightbox di purchase_view.php, purchases_invoice.php,
   dan manual_dreport.php. Selama halaman Inventory Data dibuka, ketiganya
   ikut kena.

   Kegelapan latar sudah disediakan .modal-backdrop bawaan Bootstrap, yang
   menutupi seluruh layar — bukan hanya kotak modal. Tanpa aturan ini, tepi
   modal menempel persis ke tepi gambar. */

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

/* Custom Toggle Switch (Model users.php) */
.form-switch .form-check-input.status-switch {
    width: 2.5em;
    height: 1.25em;
    cursor: pointer;
}
.form-switch .form-check-input.status-switch:checked {
    background-color: #198754;
    border-color: #198754;
}
</style>

<script>
let table;

document.addEventListener("DOMContentLoaded", function() {

    // Initialize DataTables: Kolom 8 (Status) urutan ASC -> 1 (Aktif) di atas, 2 (Nonaktif) di bawah
    table = $("#inventoryTable").DataTable({
        order: [[8, "asc"], [2, "asc"]],
        pageLength: 25,
        lengthMenu: [25, 50, 100, 250, 500],
        responsive: true,
        autoWidth: false,
        deferRender: true,
        language: {
            processing: "{$_jl_processing}",
            search: "{$_jl_search}:",
            lengthMenu: "{$_jl_show} _MENU_ {$_jl_entries}",
            info: "{$_jl_showing} _START_ {$_jl_to} _END_ {$_jl_of} _TOTAL_ {$_jl_entries}",
            infoEmpty: "{$_jl_showing} 0 {$_jl_to} 0 {$_jl_of} 0 {$_jl_entries}",
            infoFiltered: "({$_jl_filtered} {$_jl_from} _TOTAL_ {$_jl_total} {$_jl_entries})",
            paginate: {
                first: "{$_jl_first}",
                last: "{$_jl_last}",
                next: "{$_jl_next}",
                previous: "{$_jl_previous}"
            }
        }
    });

    function initializeImageObservers() {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    const src = img.dataset.src;

                    const tempImg = new Image();
                    tempImg.onload = function() {
                        img.src = src;
                        img.classList.add("loaded");
                    };
                    tempImg.onerror = function() {
                        img.style.opacity = "0.3";
                        img.alt = "Failed to load";
                    };
                    tempImg.src = src;

                    observer.unobserve(img);
                }
            });
        }, {
            rootMargin: "50px"
        });

        document.querySelectorAll(".item-thumbnail").forEach(img => {
            imageObserver.observe(img);
        });
    }

    /* Pratinjau gambar memakai lightbox BERSAMA di includes/footer.php —
       acuannya openPhotoModal() di arw_pos/manual_dreport.php.
       Modal, CSS, dan ~45 baris JS zoom milik halaman ini dihapus; semua
       halaman kini memakai satu implementasi yang sama. */
    document.querySelector("#inventoryTable tbody").addEventListener("click", function(e) {
        if (e.target.classList.contains("item-thumbnail")) {
            const src = e.target.dataset.src;
            if (src) { bltxPhotoLightbox(src); }
        }
    });

    initializeImageObservers();

    table.on("draw", function() {
        initializeImageObservers();
    });

});

// Update Classification via AJAX
function updateClassificationAjax(element, csrfToken) {
    const select = $(element);
    const itemId = select.data("id");
    const classId = select.val();

    select.prop("disabled", true);

    $.ajax({
        url: "?tab=inventory_data",
        type: "POST",
        data: {
            _action: "update_classification",
            id: itemId,
            classification_id: classId,
            _token: csrfToken
        },
        dataType: "json",
        success: function(response) {
            if (response.success) {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000,
                    timerProgressBar: true
                });
                Toast.fire({
                    icon: 'success',
                    title: response.message || '{$_jl_upd_success}'
                });
            } else {
                showFloatingAlert(response.message || '{$_jl_upd_failed}', 'error');
            }
        },
        error: function() {
            showFloatingAlert('{$_jl_err_system}', 'error');
        },
        complete: function() {
            select.prop("disabled", false);
        }
    });
}

// Toggle Status via AJAX (Tanpa Refresh) & Re-sort Otomatis
function toggleStatusAjax(element, csrfToken) {
    const switchInput = $(element);
    const itemId = switchInput.data("id");
    const currentStatus = parseInt(switchInput.data("status"));
    const newStatus = currentStatus === 1 ? 0 : 1;
    const tdCell = switchInput.closest("td");

    switchInput.prop("disabled", true);

    $.ajax({
        url: "?tab=inventory_data",
        type: "POST",
        data: {
            _action: "toggle_status",
            toggle_id: itemId,
            new_status: newStatus,
            _token: csrfToken
        },
        dataType: "json",
        success: function(response) {
            if (response.success) {
                // Update atribut elemen switch
                switchInput.data("status", newStatus);
                switchInput.prop("checked", newStatus === 1);
                switchInput.attr("title", newStatus === 1 ? "{$_jl_active}" : "{$_jl_inactive}");
                
                // Ubah data-order: 1 = Active (di atas), 2 = Inactive (di paling bawah)
                const newOrderVal = newStatus === 1 ? 1 : 2;
                tdCell.attr("data-order", newOrderVal);

                // Minta DataTables membaca ulang atribut sel dan langsung mengurutkan ulang (tanpa reload)
                if (table) {
                    table.rows().invalidate().draw(false);
                }

                // Tampilkan notifikasi Toast
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000,
                    timerProgressBar: true
                });
                Toast.fire({
                    icon: 'success',
                    title: response.message || '{$_jl_upd_success}'
                });
            } else {
                switchInput.prop("checked", currentStatus === 1);
                showFloatingAlert(response.message || '{$_jl_upd_failed}', 'error');
            }
        },
        error: function() {
            switchInput.prop("checked", currentStatus === 1);
            showFloatingAlert('{$_jl_err_system}', 'error');
        },
        complete: function() {
            switchInput.prop("disabled", false);
        }
    });
}

// Confirm delete
function confirmDelete(itemId, csrfToken) {
    Swal.fire({
        title: "{$_jl_sure}",
        text: "{$_jl_del_confirm}",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#3085d6",
        confirmButtonText: "{$_jl_yes}, {$_jl_delete}!",
        cancelButtonText: "{$_jl_cancel}"
    }).then(function(result) {
        if (result.isConfirmed) {
            var form = document.createElement("form");
            form.method = "POST";
            form.action = "?tab=inventory_data";

            var actionInput = document.createElement("input");
            actionInput.type  = "hidden";
            actionInput.name  = "_action";
            actionInput.value = "delete_inventory";

            var idInput = document.createElement("input");
            idInput.type  = "hidden";
            idInput.name  = "delete_id";
            idInput.value = itemId;

            var tokenInput = document.createElement("input");
            tokenInput.type  = "hidden";
            tokenInput.name  = "_token";
            tokenInput.value = csrfToken;

            form.appendChild(actionInput);
            form.appendChild(idInput);
            form.appendChild(tokenInput);
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function resetFilter() {
    window.location.href = "?tab=inventory_data";
}

function applyFilter() {
    const form = document.getElementById("filterForm");
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    window.location.href = "?tab=inventory_data&" + params.toString();
}

function exportData(type) {
    const filterItem     = document.getElementById("filter_item").value;
    const filterCategory = document.getElementById("filter_category").value;
    const filterSupplier = document.getElementById("filter_supplier").value;
    const filterLocation = document.getElementById("filter_location").value;
    const filterMinStock = document.getElementById("filter_min_stock").value;

    var filterDesc = [];

    if (filterItem) {
        var itemName = document.getElementById("filter_item").options[document.getElementById("filter_item").selectedIndex].text;
        filterDesc.push("<strong>{$_jl_item_name}:</strong> " + itemName);
    }
    if (filterCategory) {
        var categoryName = document.getElementById("filter_category").options[document.getElementById("filter_category").selectedIndex].text;
        filterDesc.push("<strong>{$_jl_category}:</strong> " + categoryName);
    }
    if (filterSupplier) {
        var supplierName = document.getElementById("filter_supplier").options[document.getElementById("filter_supplier").selectedIndex].text;
        filterDesc.push("<strong>{$_jl_supplier}:</strong> " + supplierName);
    }
    if (filterLocation) {
        var locationName = document.getElementById("filter_location").options[document.getElementById("filter_location").selectedIndex].text;
        filterDesc.push("<strong>{$_jl_location}:</strong> " + locationName);
    }
    if (filterMinStock) {
        filterDesc.push("<strong>{$_jl_min_stock}:</strong> " + filterMinStock);
    }

    var filterHtml  = "";
    var warningHtml = "";

    if (filterDesc.length === 0) {
        warningHtml = '<div class="alert alert-warning mt-2"><i class="bi bi-exclamation-triangle"></i> {$_jl_all_data}</div>';
        filterHtml  = '<div style="text-align:left;margin-top:10px"><strong>{$_jl_filter_lbl}:</strong> {$_jl_all} ({$_jl_no_filter})</div>';
    } else {
        filterHtml = '<div style="text-align:left;margin-top:10px">' + filterDesc.join("<br>") + '</div>';
    }

    Swal.fire({
        title: "{$_jl_exp_confirm}",
        html: '<div style="text-align:left"><strong>{$_jl_export_to}:</strong> ' + type.toUpperCase() + '</div>' + filterHtml + warningHtml,
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: type === "excel" ? "#28a745" : "#dc3545",
        cancelButtonColor: "#6c757d",
        confirmButtonText: '<i class="bi bi-check-circle"></i> {$_jl_yes_export}',
        cancelButtonText: '<i class="bi bi-x-circle"></i> {$_jl_cancel}',
        reverseButtons: true
    }).then(function(result) {
        if (result.isConfirmed) {
            const form = document.getElementById("filterForm");
            const formData = new FormData(form);
            performExport(type, formData);
        }
    });
}

function performExport(type, formData) {
    var params = new URLSearchParams();
    for (var pair of formData.entries()) {
        params.set(pair[0], pair[1]);
    }
    params.set("type", type);

    var exportUrl = "{$_js_export_url}?" + params.toString();

    Swal.fire({
        title: "{$_jl_exporting}",
        html: "{$_jl_please_wait}",
        allowOutsideClick: false,
        didOpen: function() {
            Swal.showLoading();
        }
    });

    window.location.href = exportUrl;

    setTimeout(function() {
        Swal.close();
        Swal.fire({
            icon: "success",
            title: "{$_jl_exp_success}",
            text: "{$_jl_downloaded}",
            timer: 2000,
            showConfirmButton: false
        });
    }, 2000);
}

// Print Barcode
var _printBarcodeUrl = {$_js_print_url};

function printBarcode(sku, itemName) {
    if (!sku || sku.trim() === "" || sku === "-") {
        Swal.fire({
            icon: "warning",
            title: "{$_jl_warning}",
            text: "{$_jl_no_barcode}",
            timer: 2500,
            showConfirmButton: false
        });
        return;
    }

    var dialogHtml = ""
        + '<div style="text-align:left;font-size:13px">'
        + '<div style="margin-bottom:12px">'
        + '  <label style="display:block;font-weight:600;margin-bottom:4px">{$_jl_printer}</label>'
        + '  <select id="swal-printer" style="width:100%;padding:7px 10px;border:1px solid #ced4da;border-radius:5px;font-size:13px">'
        + '    <option value="default">{$_jl_def_printer}</option>'
        + '  </select>'
        + '  <div style="font-size:11px;color:#6c757d;margin-top:4px">{$_jl_prt_note}</div>'
        + '</div>'
        + '<div>'
        + '  <label style="display:block;font-weight:600;margin-bottom:4px">{$_jl_copies}</label>'
        + '  <input id="swal-copies" type="number" min="1" max="99" value="1"'
        + '    style="width:100%;padding:7px 10px;border:1px solid #ced4da;border-radius:5px;font-size:13px">'
        + '</div>'
        + '</div>';

    Swal.fire({
        title: "{$_jl_print_bc}",
        html: dialogHtml,
        icon: "question",
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-printer"></i> {$_jl_print_bc}',
        cancelButtonText: "{$_jl_cancel}",
        confirmButtonColor: "#0d6efd",
        cancelButtonColor: "#6c757d",
        width: 360,
        didOpen: function() {
            setTimeout(function(){ document.getElementById("swal-copies").select(); }, 100);
        },
        preConfirm: function() {
            var copies = parseInt(document.getElementById("swal-copies").value, 10);
            if (!copies || copies < 1 || copies > 99) {
                Swal.showValidationMessage("{$_jl_copies_inv}");
                return false;
            }
            return { copies: copies };
        }
    }).then(function(result) {
        if (!result.isConfirmed) return;
        var copies = result.value.copies;

        var url = _printBarcodeUrl
            + "?sku="    + encodeURIComponent(sku)
            + "&item="   + encodeURIComponent(itemName)
            + "&copies=" + copies;

        var a = document.createElement("a");
        a.href   = url;
        a.target = "_blank";
        a.rel    = "noopener noreferrer";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    });
}
</script>
EXTRAJS;
?>

<?php
/* Toast melayang lewat helper terpusat Tahap 0.4, menggantikan dua modal
   SweetAlert2 yang membaca $_SESSION['success'] / ['error'].

   Ini flash SISI SERVER, jadi padanannya bltx_render_flash() — bukan
   showFloatingAlert() yang dipakai untuk notifikasi sisi JavaScript di
   bagian atas berkas ini.

   Helper juga mengurus unset()-nya, jadi kedua blok <?php unset(...) ?>
   di bawahnya ikut hilang. Markup yang dihasilkan murni HTML+CSS sehingga
   tetap tampil walau JavaScript mati — modal SweetAlert2 tidak. */
bltx_render_flash('invdata');
?>

<?php
$grand_total = 0;
if ($result && $result->num_rows > 0) {
    $result->data_seek(0);
    while ($row_temp = $result->fetch_assoc()) {
        $grand_total += floatval($row_temp['total_value']);
    }
    $result->data_seek(0);
}

$grand_total_formatted = formatCurrency($grand_total);
$grand_symbol = $currency_symbol;
$grand_value  = ltrim(str_replace($currency_symbol, '', $grand_total_formatted));
?>

<div class="card border-0 shadow-sm">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-box-seam"></i> <?php echo $LANG['inventory_data'] ?? 'Inventory Data'; ?></div>
        <div class="card-tools">
            <div class="me-3 d-inline-flex align-items-center">
                <strong><?= $LANG['grand_total'] ?? 'Grand Total' ?>:</strong>
                <strong>
                    <span class="currency-cell text-primary fs-6 ms-2 d-inline-flex">
                        <span class="curr-symbol"><?= htmlspecialchars($grand_symbol, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="curr-value"><?= htmlspecialchars(trim($grand_value), ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                </strong>
            </div>
            <?php if ($_can_create): ?>
            <a href="?tab=inventory_data&action=add" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle"></i> <?php echo $LANG['add'] ?? 'Add'; ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="card-body">
        <?php if ($_can_export): ?>
        <div class="filter-section">
            <form id="filterForm" class="m-0 p-0">
                <input type="hidden" name="tab" value="inventory_data">
                <div class="row g-3">
                    
                    <div class="col-12 col-sm-6 col-lg">
                        <label for="filter_item" class="form-label small"><?= $LANG['item_name'] ?? 'Item Name' ?></label>
                        <select name="filter_item" id="filter_item" class="form-select form-select-sm">
                            <option value=""><?= $LANG['select'] ?? 'Select' ?></option>
                            <?php while ($item = $items_result->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>" <?= $filter_item == $item['item_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-lg">
                        <label for="filter_category" class="form-label small"><?= $LANG['category'] ?? 'Category' ?></label>
                        <select name="filter_category" id="filter_category" class="form-select form-select-sm">
                            <option value=""><?= $LANG['select'] ?? 'Select' ?></option>
                            <?php while ($cat = $categories_result->fetch_assoc()): ?>
                                <option value="<?= $cat['id'] ?>" <?= $filter_category == $cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-lg">
                        <label for="filter_supplier" class="form-label small"><?= $LANG['supplier'] ?? 'Supplier' ?></label>
                        <select name="filter_supplier" id="filter_supplier" class="form-select form-select-sm">
                            <option value=""><?= $LANG['select'] ?? 'Select' ?></option>
                            <?php while ($sup = $suppliers_result->fetch_assoc()): ?>
                                <option value="<?= $sup['id'] ?>" <?= $filter_supplier == $sup['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sup['name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-lg">
                        <label for="filter_location" class="form-label small"><?= $LANG['warehouse_location'] ?? 'Location' ?></label>
                        <select name="filter_location" id="filter_location" class="form-select form-select-sm">
                            <option value=""><?= $LANG['select'] ?? 'Select' ?></option>
                            <?php while ($loc = $locations_result->fetch_assoc()): ?>
                                <option value="<?= $loc['id'] ?>" <?= $filter_location == $loc['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($loc['location_code'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-lg">
                        <label for="filter_min_stock" class="form-label small"><?= $LANG['min_stock'] ?? 'Min Stock' ?></label>
                        <input type="number" 
                               name="filter_min_stock" 
                               id="filter_min_stock" 
                               class="form-control form-control-sm" 
                               placeholder="<?= $LANG['min_stock'] ?? 'Min Stock' ?>"
                               value="<?= htmlspecialchars($filter_min_stock, ENT_QUOTES, 'UTF-8') ?>"
                               min="0"
                               step="0.01">
                    </div>

                </div>

                <div class="col-12 mt-3">
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" onclick="applyFilter()" class="btn btn-primary btn-sm">
                            <i class="bi bi-funnel"></i> <?= $LANG['filter'] ?? 'Filter' ?>
                         </button>
                        <button type="button" onclick="resetFilter()" class="btn btn-secondary btn-sm">
                           <i class="bi bi-arrow-counterclockwise"></i> <?= $LANG['reset'] ?? 'Reset' ?>
                        </button>
                        <button type="button" onclick="exportData('excel')" class="btn btn-success btn-sm">
                            <i class="bi bi-file-earmark-excel"></i> <?= $LANG['export_excel'] ?? 'Export Excel' ?>
                        </button>
                        <button type="button" onclick="exportData('pdf')" class="btn btn-danger btn-sm">
                            <i class="bi bi-file-earmark-pdf"></i> <?= $LANG['export_pdf'] ?? 'Export PDF' ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Table Section -->
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="inventoryTable">
                <thead>
                    <tr>
                        <th class="text-center"><?php echo $LANG['image'] ?? 'Image'; ?></th>
                        <th class="text-center"><?php echo $LANG['item_code'] ?? 'Item Code'; ?></th>
                        <th class="text-center"><?php echo $LANG['item_name'] ?? 'Item Name'; ?></th>
                        <th class="text-center"><?php echo $LANG['category'] ?? 'Category'; ?></th>
                        <th class="text-center"><?php echo $LANG['location'] ?? 'Location'; ?></th>
                        <th class="text-center"><?php echo $LANG['stock'] ?? 'Stock'; ?></th>
                        <th class="text-center"><?php echo $LANG['price'] ?? 'Price'; ?></th>
                        <th class="text-center"><?php echo $LANG['total'] ?? 'Total'; ?></th>
                        <th class="text-center"><?php echo $LANG['status'] ?? 'Status'; ?></th>
                        <th class="text-center"><?php echo $LANG['classification'] ?? 'Classification'; ?></th>
                        <th class="text-center"><?php echo $LANG['actions'] ?? 'Actions'; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <?php
                        $price_formatted = formatCurrency($row['unit_price']);
                        $total_formatted = formatCurrency($row['total_value']);
                        $price_value = ltrim(str_replace($currency_symbol, '', $price_formatted));
                        $total_value = ltrim(str_replace($currency_symbol, '', $total_formatted));
                        
                        $is_active = isset($row['is_active']) ? (int)$row['is_active'] : 1;
                        // data-order: 1 untuk Aktif (di atas), 2 untuk Nonaktif (di bawah)
                        $order_val = $is_active ? 1 : 2;
                        ?>
                        <tr>
                            <td>
                                <?php if ($row['item_image']): ?>
                                    <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" 
                                         class="item-thumbnail"
                                         data-src="<?php echo UPLOAD_URL . ltrim($row['item_image'], '/'); ?>"
                                         alt="item">
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['product_code'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php
                                $display_name = htmlspecialchars($row['item_name'] ?? '-', ENT_QUOTES, 'UTF-8');
                                if (!empty($row['qty_per_unit']) && (float)$row['qty_per_unit'] > 0) {
                                    $qpu     = rtrim(rtrim(number_format((float)$row['qty_per_unit'],2,'.',''),'0'),'.');
                                    $qpu_sym = htmlspecialchars($row['qpu_unit_symbol'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $display_name .= ' <span class="text-muted small">(' . $qpu . ($qpu_sym ? ' ' . $qpu_sym : '') . ')</span>';
                                }
                                echo $display_name;
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['category_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php echo htmlspecialchars($row['location_code'] ?? '-', ENT_QUOTES, 'UTF-8'); ?><br>
                                <small class="text-muted"><?php echo htmlspecialchars($row['location_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td class="<?php
                                if ($row['quantity'] <= $row['min_stock']) {
                                    echo 'low-stock';
                                } elseif ($row['quantity'] <= ($row['min_stock'] * 2)) {
                                    echo 'warning-stock';
                                }
                                ?>">
                                <?php echo formatNumber($row['quantity']) . ' ' . htmlspecialchars($row['unit_symbol'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            </td>

                            <td>
                                <span class="currency-cell">
                                    <span class="curr-symbol"><?= htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="curr-value"><?= htmlspecialchars(trim($price_value), ENT_QUOTES, 'UTF-8') ?></span>
                                </span>
                            </td>

                            <td>
                                <strong>
                                    <span class="currency-cell">
                                        <span class="curr-symbol"><?= htmlspecialchars($currency_symbol, ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="curr-value"><?= htmlspecialchars(trim($total_value), ENT_QUOTES, 'UTF-8') ?></span>
                                    </span>
                                </strong>
                            </td>
                            
                            <!-- Status Toggle Switch (Model users.php - AJAX) -->
                            <td class="text-center" data-order="<?php echo $order_val; ?>">
                                <div class="form-check form-switch d-flex justify-content-center align-items-center m-0">
                                    <input class="form-check-input status-switch" 
                                           type="checkbox" 
                                           role="switch" 
                                           data-id="<?php echo (int)$row['id']; ?>"
                                           data-status="<?php echo $is_active; ?>"
                                           <?php echo $is_active ? 'checked' : ''; ?> 
                                           onchange="toggleStatusAjax(this, '<?php echo $csrf_token; ?>')"
                                           <?php echo !$_can_edit ? 'disabled' : ''; ?>
                                           title="<?php echo $is_active ? ($LANG['active'] ?? 'Active') : ($LANG['inactive'] ?? 'Inactive'); ?>">
                                </div>
                            </td>
                            
                            <!-- Classification Dropdown -->
                            <td>
                                <select class="form-select form-select-sm" 
                                        data-id="<?php echo (int)$row['id']; ?>" 
                                        onchange="updateClassificationAjax(this, '<?php echo $csrf_token; ?>')"
                                        <?php echo !$_can_edit ? 'disabled' : ''; ?>>
                                    <option value=""><?php echo $LANG['select_department'] ?? '-- ' . ($LANG['select'] ?? 'Select') . ' ' . ($LANG['department'] ?? 'Department') . ' --'; ?></option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" <?php echo (isset($row['classification_id']) && $row['classification_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php if ($_can_view): ?>
                                        <button type="button"
                                                class="btn btn-info text-white"
                                                onclick="openInventoryView(<?php echo (int)$row['id']; ?>)"
                                                title="<?php echo $LANG['view'] ?? 'View'; ?>">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($_can_edit): ?>
                                        <a href="?tab=inventory_data&action=edit&id=<?php echo $row['id']; ?>"
                                           class="btn btn-warning"
                                           title="<?php echo $LANG['edit'] ?? 'Edit'; ?>">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($_can_delete): ?>
                                        <button type="button"
                                                class="btn btn-danger"
                                                onclick="confirmDelete(<?php echo (int)$row['id']; ?>, '<?php echo $csrf_token; ?>')"
                                                title="<?php echo $LANG['delete'] ?? 'Delete'; ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($_can_print): ?>
                                        <button type="button"
                                                class="btn btn-secondary"
                                                onclick="printBarcode(<?php echo json_encode($row['product_code'] ?? ''); ?>, <?php echo json_encode($row['item_name'] ?? ''); ?>)"
                                                title="<?php echo $LANG['print_barcode'] ?? 'Print Barcode'; ?>">
                                            <i class="bi bi-upc-scan"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Item Image Modal -->
<!-- Modal gambar item DIHAPUS — digantikan lightbox bersama di
     includes/footer.php (#bltxLightboxModal). Lihat pemanggilan
     bltxPhotoLightbox() di blok skrip halaman ini. -->

<!-- Inventory View Modal -->
<div class="modal fade" id="inventoryViewModal" tabindex="-1" aria-labelledby="inventoryViewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="inventoryViewModalLabel">
            <i class="bi bi-eye"></i> <?php echo $LANG['inventory_view_title'] ?? 'Inventory Details'; ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="inventoryViewModalBody">
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden"><?php echo $LANG['processing'] ?? 'Processing...'; ?></span>
            </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
            <i class="bi bi-x-circle"></i> <?php echo $LANG['close'] ?? 'Close'; ?>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
function openInventoryView(id) {
    var modal = document.getElementById('inventoryViewModal');
    var body  = document.getElementById('inventoryViewModalBody');
    body.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div></div>';
    var bsModal = bootstrap.Modal.getOrCreateInstance(modal);
    bsModal.show();
    fetch('<?php echo url('inventory/inventory_view.php'); ?>?id=' + id + '&modal=1', {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.text(); })
    .then(function(html) { body.innerHTML = html; })
    .catch(function() {
        body.innerHTML = '<div class="alert alert-danger m-3"><i class="bi bi-exclamation-triangle me-2"></i><?php echo substr(bltx_js($LANG['error'] ?? 'Error'), 1, -1) ?>: could not load inventory detail.</div>';
    });
}
</script>