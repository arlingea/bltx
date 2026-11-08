<?php
// Direct-access guard (added by audit)
if (!defined("APP_NAME")) { http_response_code(403); exit("Direct access not permitted."); }

// ================================================================
// WAREHOUSE LOCATION — CRUD Management
// File: inventory/warehouse_location.php
// Included dari: inventory/inventory_gate.php (tab=warehouse_location)
//
// Tabel: warehouse_locations
// Kolom: id, location_code, location_name, description, is_active,
//        created_at, updated_at
//
// Relasi: inventory.location_id → warehouse_locations.id (ON DELETE SET NULL)
// ================================================================

if (!isset($_SESSION['user_id'])) exit;

if (!_canAccess('warehouse_location', 'can_access')) {
    redirect(url('dashboard.php'));
}

// ================================================================
// PERMISSION CHECKS
// ================================================================
$_wh_can_create = _canAccess('warehouse_location', 'can_create');
$_wh_can_read   = _canAccess('warehouse_location', 'can_read');
$_wh_can_update = _canAccess('warehouse_location', 'can_update');
$_wh_can_delete = _canAccess('warehouse_location', 'can_delete');
$_wh_can_export = checkPermission('warehouse_location', 'can_export');

// ================================================================
// HANDLE POST — ADD / EDIT / DELETE
// Traditional POST + PRG pattern (Post-Redirect-Get) untuk keamanan
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wh_action'])) {

    // Validasi CSRF
    if (!function_exists('invValidateCsrf') || !invValidateCsrf($_POST['csrf_token'] ?? '')) {
        bltx_flash_error($lang['invalid_csrf']);
        header('Location: ' . url('inventory/inventory_gate.php?tab=warehouse_location'));
        exit;
    }

    $wh_action = $_POST['wh_action'];

    // ——— ADD ———
    if ($wh_action === 'add' && $_wh_can_create) {
        $code = trim($_POST['location_code'] ?? '');
        $name = trim($_POST['location_name'] ?? '');
        $desc = trim($_POST['description']   ?? '');

        if ($code === '' || $name === '') {
            bltx_flash_error($lang['required_field']);
        } elseif (function_exists('invAddLocation')) {
            $res = invAddLocation($code, $name, $desc);
            if ($res['success']) { bltx_flash_success($res['message']); } else { bltx_flash_error($res['message']); }
        } else {
            // Inline fallback dengan prepared statement
            $chk = $conn->prepare('SELECT id FROM warehouse_locations WHERE location_code = ?');
            $chk->bind_param('s', $code);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                bltx_flash_error($lang['location_code_exists']);
            } else {
                $ins = $conn->prepare(
                    'INSERT INTO warehouse_locations (location_code, location_name, description, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, 1, NOW(), NOW())'
                );
                $ins->bind_param('sss', $code, $name, $desc);
                if ($ins->execute()) {
                    logActivity('add_warehouse_location', "Added: $code - $name");
                    bltx_flash_success($lang['location_added']);
                } else {
                    bltx_flash_error($lang['error']);
                }
                $ins->close();
            }
            $chk->close();
        }
    }

    // ——— EDIT ———
    elseif ($wh_action === 'edit' && $_wh_can_update) {
        $id   = (int)($_POST['location_id']   ?? 0);
        $code = trim($_POST['location_code']  ?? '');
        $name = trim($_POST['location_name']  ?? '');
        $desc = trim($_POST['description']    ?? '');

        if ($id <= 0 || $code === '' || $name === '') {
            bltx_flash_error($lang['required_field']);
        } elseif (function_exists('invUpdateLocation')) {
            $res = invUpdateLocation($id, $code, $name, $desc);
            if ($res['success']) { bltx_flash_success($res['message']); } else { bltx_flash_error($res['message']); }
        } else {
            // Inline fallback
            $chk = $conn->prepare('SELECT id FROM warehouse_locations WHERE location_code = ? AND id != ?');
            $chk->bind_param('si', $code, $id);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                bltx_flash_error($lang['location_code_exists']);
            } else {
                $upd = $conn->prepare(
                    'UPDATE warehouse_locations
                     SET location_code = ?, location_name = ?, description = ?, updated_at = NOW()
                     WHERE id = ?'
                );
                $upd->bind_param('sssi', $code, $name, $desc, $id);
                if ($upd->execute()) {
                    logActivity('update_warehouse_location', "Updated ID: $id");
                    bltx_flash_success($lang['location_updated']);
                } else {
                    bltx_flash_error($lang['error']);
                }
                $upd->close();
            }
            $chk->close();
        }
    }

    // ——— DELETE ———
    elseif ($wh_action === 'delete' && $_wh_can_delete) {
        $id = (int)($_POST['location_id'] ?? 0);

        if ($id <= 0) {
            bltx_flash_error('Invalid ID');
        } elseif (function_exists('invDeleteLocation')) {
            $res = invDeleteLocation($id);
            if ($res['success']) { bltx_flash_success($res['message']); } else { bltx_flash_error($res['message']); }
        } else {
            // Inline fallback
            $chk = $conn->prepare('SELECT id FROM inventory WHERE location_id = ? LIMIT 1');
            $chk->bind_param('i', $id);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                bltx_flash_error($lang['location_in_use']);
            } else {
                $del = $conn->prepare('DELETE FROM warehouse_locations WHERE id = ?');
                $del->bind_param('i', $id);
                if ($del->execute()) {
                    logActivity('delete_warehouse_location', "Deleted ID: $id");
                    bltx_flash_success($lang['location_deleted']);
                } else {
                    bltx_flash_error($lang['error']);
                }
                $del->close();
            }
            $chk->close();
        }
    }

    else {
        bltx_flash_error('No permission');
    }

    // PRG — redirect setelah POST agar tidak double-submit saat refresh
    header('Location: ' . url('inventory/inventory_gate.php?tab=warehouse_location'));
    exit;
}

// ================================================================
// FETCH DATA — semua lokasi gudang urut location_code
// ================================================================
$_wh_result = $conn->query(
    "SELECT id, location_code, location_name, description, is_active, created_at, updated_at
     FROM warehouse_locations
     ORDER BY location_code ASC"
);

// ================================================================
// CSRF TOKEN
// ================================================================
$_wh_csrf = function_exists('invCsrfToken') ? invCsrfToken() : bin2hex(random_bytes(16));
if (!function_exists('invCsrfToken')) {
    $_SESSION['_inv_csrf'] = $_wh_csrf;
}
?>

<style>
/* Card */
.card {
    border-radius: 0.5rem;
    box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
    border: 1px solid rgba(0,0,0,0.125);
}
.card-header {
    background-color: #fff;
    border-bottom: 1px solid rgba(0,0,0,0.125);
    padding: 1rem 1.5rem;
}
.card-body { padding: 1.5rem; }

/* Table */
.table { margin-bottom: 0; }
.table th, .table td { vertical-align: middle; padding: 0.75rem; }
.table th {
    white-space: nowrap;
    font-weight: 600;
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}
.table td { word-break: break-word; }
.table-striped tbody tr:nth-of-type(odd) { background-color: rgba(0,0,0,0.02); }
.table-hover tbody tr:hover { background-color: rgba(0,0,0,0.04); }

/* Badge status */
.badge-active   { background-color: #198754; color: #fff; }
.badge-inactive { background-color: #6c757d; color: #fff; }

/* Filter section */
.filter-section {
    background-color: #f8f9fa;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border: 1px solid #dee2e6;
}
.filter-section .row { align-items: end; }

/* Button group */
.btn-group-sm > .btn { padding: 0.25rem 0.5rem; font-size: 0.875rem; }

/* Responsive */
@media (max-width: 992px) {
    .card-header { padding: 0.75rem 1rem; }
    .card-body   { padding: 1rem; }
    .table th, .table td { padding: 0.5rem; font-size: 0.875rem; }
    .filter-section { padding: 0.75rem; }
}
@media (max-width: 768px) {
    .card-header { padding: 0.5rem 0.75rem; }
    .card-body   { padding: 0.75rem; }
    .filter-section { padding: 0.5rem; }
}
@media (max-width: 576px) {
    .table th, .table td { padding: 0.4rem; font-size: 0.8rem; }
}
</style>

<div class="card border-0 shadow-sm">
    <div class="card-header">
        <div class="card-title">
            <i class="bi bi-geo-alt-fill"></i>
            <?php echo $lang['warehouse_location']; ?>
        </div>
        <?php if ($_wh_can_create): ?>
        <div class="card-tools">
            <button type="button" class="btn btn-primary btn-sm"
                    data-bs-toggle="modal" data-bs-target="#modalAddLocation">
                <i class="bi bi-plus-circle"></i>
                <?php echo $lang['add_location']; ?>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <div class="card-body">

        <?php
        /* RONDE B — toast melayang lewat helper terpusat Tahap 0.4.

           Menggantikan DUA blok sekaligus: fallback <noscript> di sini, dan
           popup SweetAlert2 di bagian bawah berkas. Keduanya tidak diperlukan
           lagi karena bltx_render_flash() murni HTML+CSS — ia tetap tampil
           walau JavaScript mati, jadi fallback noscript-nya mubazir.

           Popup SweetAlert2-nya juga melanggar B2 poin 5: SweetAlert2 hanya
           untuk dialog konfirmasi, tidak untuk notifikasi hasil. Ini satu-
           satunya yang tersisa di sistem, dan terlewat di Tahap 4. */
        bltx_render_flash('wh');
        ?>

        <!-- ============================================================
             KESELURUHAN WRAP CARD FILTER WAREHOUSE LOCATION
             Seluruh section filter (form + semua tombol termasuk export)
             HANYA tampil jika can_export = true.
             Jika can_export = false → seluruh wrap tidak tampil sama sekali.
             ============================================================ -->
        <?php if ($_wh_can_export): ?>
        <div class="filter-section">
            <div class="row g-3">
                <div class="col-md-4 col-sm-6">
                    <label for="wh_filter_code" class="form-label small">
                        <?php echo $lang['location_code']; ?>
                    </label>
                    <input type="text" id="wh_filter_code" class="form-control form-control-sm"
                           placeholder="<?php echo $lang['location_code']; ?>">
                </div>
                <div class="col-md-4 col-sm-6">
                    <label for="wh_filter_name" class="form-label small">
                        <?php echo $lang['location_name']; ?>
                    </label>
                    <input type="text" id="wh_filter_name" class="form-control form-control-sm"
                           placeholder="<?php echo $lang['location_name']; ?>">
                </div>
                <div class="col-md-4 col-sm-12">
                    <label for="wh_filter_status" class="form-label small">
                        <?php echo $lang['status']; ?>
                    </label>
                    <select id="wh_filter_status" class="form-select form-select-sm">
                        <option value=""><?php echo $lang['all']; ?></option>
                        <option value="active"><?php echo $lang['active']; ?></option>
                        <option value="inactive"><?php echo $lang['inactive']; ?></option>
                    </select>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="button" class="btn btn-primary btn-sm" onclick="whApplyFilter()">
                    <i class="bi bi-funnel"></i> <?php echo $lang['filter']; ?>
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="whResetFilter()">
                    <i class="bi bi-arrow-counterclockwise"></i> <?php echo $lang['reset']; ?>
                </button>
                <button type="button" class="btn btn-success btn-sm" onclick="whExport('excel')">
                    <i class="bi bi-file-earmark-excel"></i> <?php echo $lang['export_excel']; ?>
                </button>
                <button type="button" class="btn btn-danger btn-sm" onclick="whExport('pdf')">
                    <i class="bi bi-file-earmark-pdf"></i> <?php echo $lang['export_pdf']; ?>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Data Table -->
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="warehouseLocationTable">
                <thead>
                    <tr>
                        <th><?php echo $lang['col_no']; ?></th>
                        <th><?php echo $lang['location_code']; ?></th>
                        <th><?php echo $lang['location_name']; ?></th>
                        <th><?php echo $lang['description']; ?></th>
                        <th><?php echo $lang['status']; ?></th>
                        <?php if ($_wh_can_update || $_wh_can_delete): ?>
                        <th><?php echo $lang['actions']; ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $no = 1;
                    if ($_wh_result && $_wh_result->num_rows > 0):
                        while ($row = $_wh_result->fetch_assoc()):
                            $is_active = (int)$row['is_active'];
                    ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['location_code'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['location_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="text-muted">
                            <?php echo htmlspecialchars($row['description'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $is_active ? 'badge-active' : 'badge-inactive'; ?>">
                                <?php echo $is_active
                                    ? ($lang['active'])
                                    : ($lang['inactive']); ?>
                            </span>
                        </td>
                        <?php if ($_wh_can_update || $_wh_can_delete): ?>
                        <td>
                            <div class="btn btn-sm-group btn-group-sm">
                                <?php if ($_wh_can_update): ?>
                                <button type="button"
                                        class="btn btn-sm btn-warning"
                                        title="<?php echo $lang['edit']; ?>"
                                        onclick="whOpenEdit(
                                            <?php echo (int)$row['id']; ?>,
                                            <?php echo json_encode($row['location_code']); ?>,
                                            <?php echo json_encode($row['location_name']); ?>,
                                            <?php echo json_encode($row['description'] ?? ''); ?>
                                        )">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($_wh_can_delete): ?>
                                <button type="button"
                                        class="btn btn-sm btn-danger"
                                        title="<?php echo $lang['delete']; ?>"
                                        onclick="whConfirmDelete(
                                            <?php echo (int)$row['id']; ?>,
                                            <?php echo json_encode($row['location_code']); ?>
                                        )">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                    <tr>
                        <td colspan="<?php echo ($_wh_can_update || $_wh_can_delete) ? 6 : 5; ?>"
                            class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-4 d-block mb-1 opacity-50"></i>
                            <?php echo $lang['no_data']; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div><!-- /.card-body -->
</div><!-- /.card -->


<!-- ================================================================
     MODAL ADD LOCATION
================================================================ -->
<?php if ($_wh_can_create): ?>
<div class="modal fade" id="modalAddLocation" tabindex="-1"
     aria-labelledby="modalAddLocationLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST"
                  action="<?php echo url('inventory/inventory_gate.php?tab=warehouse_location'); ?>"
                  id="formAddLocation" novalidate>
                <input type="hidden" name="wh_action" value="add">
                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars($_wh_csrf, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalAddLocationLabel">
                        <i class="bi bi-plus-circle"></i>
                        <?php echo $lang['add_location']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <!-- Location Code -->
                    <div class="mb-3">
                        <label for="add_location_code" class="form-label fw-semibold">
                            <?php echo $lang['location_code']; ?>
                            <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control"
                               id="add_location_code"
                               name="location_code"
                               maxlength="50"
                               placeholder="<?php echo $lang['location_code']; ?>"
                               required>
                        <div class="invalid-feedback">
                            <?php echo $lang['required_field']; ?>
                        </div>
                    </div>
                    <!-- Location Name -->
                    <div class="mb-3">
                        <label for="add_location_name" class="form-label fw-semibold">
                            <?php echo $lang['location_name']; ?>
                            <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control"
                               id="add_location_name"
                               name="location_name"
                               maxlength="100"
                               placeholder="<?php echo $lang['location_name']; ?>"
                               required>
                        <div class="invalid-feedback">
                            <?php echo $lang['required_field']; ?>
                        </div>
                    </div>
                    <!-- Description -->
                    <div class="mb-3">
                        <label for="add_description" class="form-label fw-semibold">
                            <?php echo $lang['description']; ?>
                        </label>
                        <textarea class="form-control"
                                  id="add_description"
                                  name="description"
                                  rows="2"
                                  placeholder="<?php echo $lang['description']; ?>"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> <?php echo $lang['cancel']; ?>
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm"
                            onclick="return whValidateForm('formAddLocation')">
                        <i class="bi bi-check-circle"></i> <?php echo $lang['save']; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- ================================================================
     MODAL EDIT LOCATION
================================================================ -->
<?php if ($_wh_can_update): ?>
<div class="modal fade" id="modalEditLocation" tabindex="-1"
     aria-labelledby="modalEditLocationLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST"
                  action="<?php echo url('inventory/inventory_gate.php?tab=warehouse_location'); ?>"
                  id="formEditLocation" novalidate>
                <input type="hidden" name="wh_action" value="edit">
                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars($_wh_csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="location_id" id="edit_location_id">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditLocationLabel">
                        <i class="bi bi-pencil"></i>
                        <?php echo $lang['edit_location']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <!-- Location Code -->
                    <div class="mb-3">
                        <label for="edit_location_code" class="form-label fw-semibold">
                            <?php echo $lang['location_code']; ?>
                            <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control"
                               id="edit_location_code"
                               name="location_code"
                               maxlength="50"
                               required>
                        <div class="invalid-feedback">
                            <?php echo $lang['required_field']; ?>
                        </div>
                    </div>
                    <!-- Location Name -->
                    <div class="mb-3">
                        <label for="edit_location_name" class="form-label fw-semibold">
                            <?php echo $lang['location_name']; ?>
                            <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control"
                               id="edit_location_name"
                               name="location_name"
                               maxlength="100"
                               required>
                        <div class="invalid-feedback">
                            <?php echo $lang['required_field']; ?>
                        </div>
                    </div>
                    <!-- Description -->
                    <div class="mb-3">
                        <label for="edit_description" class="form-label fw-semibold">
                            <?php echo $lang['description']; ?>
                        </label>
                        <textarea class="form-control"
                                  id="edit_description"
                                  name="description"
                                  rows="2"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> <?php echo $lang['cancel']; ?>
                    </button>
                    <button type="submit" class="btn btn-warning btn-sm"
                            onclick="return whValidateForm('formEditLocation')">
                        <i class="bi bi-check-circle"></i> <?php echo $lang['save']; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- ================================================================
     HIDDEN FORM DELETE — submit via JS / SweetAlert confirm
================================================================ -->
<?php if ($_wh_can_delete): ?>
<form method="POST"
      action="<?php echo url('inventory/inventory_gate.php?tab=warehouse_location'); ?>"
      id="formDeleteLocation"
      style="display:none;">
    <input type="hidden" name="wh_action" value="delete">
    <input type="hidden" name="csrf_token"
           value="<?php echo htmlspecialchars($_wh_csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="location_id" id="delete_location_id">
</form>
<?php endif; ?>


<!-- ================================================================
     JAVASCRIPT
================================================================ -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    // ——— DataTable ———
    if (typeof jQuery !== 'undefined' && jQuery.fn.DataTable) {

        if (jQuery.fn.DataTable.isDataTable('#warehouseLocationTable')) {
            jQuery('#warehouseLocationTable').DataTable().destroy();
        }

        jQuery('#warehouseLocationTable').DataTable({
            responsive: true,
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100],
            order: [[1, 'asc']],
            columnDefs: [
                { orderable: false, targets: -1 }   // kolom Actions tidak sortable
            ],
            language: {
                search:     '<?php echo substr(bltx_js($lang['search']), 1, -1) ?>:',
                lengthMenu: '<?php echo substr(bltx_js($lang['show']), 1, -1) ?> _MENU_ <?php echo substr(bltx_js($lang['entries']), 1, -1) ?>',
                info:       '<?php echo substr(bltx_js($lang['showing']), 1, -1) ?> _START_ <?php echo substr(bltx_js($lang['to']), 1, -1) ?> _END_ <?php echo substr(bltx_js($lang['of']), 1, -1) ?> _TOTAL_ <?php echo substr(bltx_js($lang['entries']), 1, -1) ?>',
                infoEmpty:  '<?php echo substr(bltx_js($lang['showing']), 1, -1) ?> 0 <?php echo substr(bltx_js($lang['to']), 1, -1) ?> 0 <?php echo substr(bltx_js($lang['of']), 1, -1) ?> 0 <?php echo substr(bltx_js($lang['entries']), 1, -1) ?>',
                emptyTable:  <?php echo bltx_js($lang['no_data']) ?>,
                zeroRecords: <?php echo bltx_js($lang['no_matching']) ?>,
                paginate: {
                    first:    <?php echo bltx_js($lang['first']) ?>,
                    last:     <?php echo bltx_js($lang['last']) ?>,
                    next:     <?php echo bltx_js($lang['next']) ?>,
                    previous: <?php echo bltx_js($lang['previous']) ?>
                }
            }
        });
    }

    /* Popup SweetAlert2 untuk notifikasi hasil DIHAPUS — digantikan toast
       melayang bltx_render_flash('wh') di bagian atas berkas. SweetAlert2
       tetap dipakai di berkas ini untuk dialog KONFIRMASI hapus, dan itu
       memang pemakaian yang benar. */

});

// ——— Buka modal edit — isi field dengan data baris ———
function whOpenEdit(id, code, name, desc) {
    document.getElementById('edit_location_id').value   = id;
    document.getElementById('edit_location_code').value = code;
    document.getElementById('edit_location_name').value = name;
    document.getElementById('edit_description').value   = desc;

    // Reset validasi Bootstrap
    var form = document.getElementById('formEditLocation');
    form.classList.remove('was-validated');

    var modal = new bootstrap.Modal(document.getElementById('modalEditLocation'));
    modal.show();
}

// ——— Konfirmasi hapus via SweetAlert ———
function whConfirmDelete(id, code) {
    if (typeof Swal === 'undefined') {
        if (confirm('<?php echo substr(bltx_js($lang['confirm_delete_location']), 1, -1) ?> (' + code + ')')) {
            document.getElementById('delete_location_id').value = id;
            document.getElementById('formDeleteLocation').submit();
        }
        return;
    }
    Swal.fire({
        title: <?php echo bltx_js($lang['are_you_sure']) ?>,
        html:  '<?php echo substr(bltx_js($lang['confirm_delete_location']), 1, -1) ?><br><code>' + code + '</code>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-trash"></i> <?php echo substr(bltx_js($lang['delete']), 1, -1) ?>',
        cancelButtonText:  '<i class="bi bi-x-circle"></i> <?php echo substr(bltx_js($lang['cancel']), 1, -1) ?>',
        reverseButtons: true
    }).then(function (result) {
        if (result.isConfirmed) {
            document.getElementById('delete_location_id').value = id;
            document.getElementById('formDeleteLocation').submit();
        }
    });
}

// ——— Validasi form Bootstrap sebelum submit ———
function whValidateForm(formId) {
    var form = document.getElementById(formId);
    if (!form.checkValidity()) {
        form.classList.add('was-validated');
        return false;
    }
    return true;
}

// ——— Filter tabel — gunakan DataTable column search (client-side) ———
function whApplyFilter() {
    if (typeof jQuery === 'undefined' || !jQuery.fn.DataTable) return;
    var dt     = jQuery('#warehouseLocationTable').DataTable();
    var code   = (document.getElementById('wh_filter_code')   || {value:''}).value.trim();
    var name   = (document.getElementById('wh_filter_name')   || {value:''}).value.trim();
    var status = (document.getElementById('wh_filter_status') || {value:''}).value;

    var statusText = '';
    if (status === 'active')   statusText = <?php echo bltx_js($lang['active']) ?>;
    if (status === 'inactive') statusText = <?php echo bltx_js($lang['inactive']) ?>;

    dt.column(1).search(code, false, false)
      .column(2).search(name, false, false)
      .column(4).search(statusText, false, false)
      .draw();
}

// ——— Reset filter ———
function whResetFilter() {
    ['wh_filter_code', 'wh_filter_name', 'wh_filter_status'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
    });
    if (typeof jQuery !== 'undefined' && jQuery.fn.DataTable) {
        jQuery('#warehouseLocationTable').DataTable()
            .column(1).search('')
            .column(2).search('')
            .column(4).search('')
            .draw();
    }
}

// ——— Export ———
function whExport(type) {
    var exportUrl = '<?php echo url('inventory/export_warehouse_location.php'); ?>?type=' + type;
    if (typeof Swal === 'undefined') {
        window.location.href = exportUrl;
        return;
    }
    Swal.fire({
        title: <?php echo bltx_js($lang['confirm_export']) ?>,
        html:  '<strong><?php echo substr(bltx_js($lang['export_to']), 1, -1) ?>:</strong> ' + type.toUpperCase(),
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: type === 'excel' ? '#198754' : '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-check-circle"></i> <?php echo substr(bltx_js($lang['yes_export']), 1, -1) ?>',
        cancelButtonText:  '<i class="bi bi-x-circle"></i> <?php echo substr(bltx_js($lang['cancel']), 1, -1) ?>',
        reverseButtons: true
    }).then(function (result) {
        if (result.isConfirmed) {
            Swal.fire({
                title: <?php echo bltx_js($lang['exporting']) ?>,
                html:  <?php echo bltx_js($lang['please_wait']) ?>,
                allowOutsideClick: false,
                didOpen: function () { Swal.showLoading(); }
            });
            window.location.href = exportUrl;
            setTimeout(function () {
                Swal.close();
                Swal.fire({
                    icon: 'success',
                    title: <?php echo bltx_js($lang['export_success']) ?>,
                    text:  <?php echo bltx_js($lang['file_downloaded']) ?>,
                    timer: 2000,
                    showConfirmButton: false
                });
            }, 2000);
        }
    });
}
</script>