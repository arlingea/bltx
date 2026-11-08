<?php
/* ── Partial: Purchase Order tab — included by purchases.php hub ──────────
   Renders the PO listing table. Data comes from $po_list (populated by
   the hub before include). Logic kept minimal here. */

// Direct-access guard
if (!defined('APP_NAME') || !isset($LANG)) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// Defensive: hub provides $po_list, but if called out-of-context we tolerate it.
$po_list = isset($po_list) && is_array($po_list) ? $po_list : [];

// Detect missing tables — show friendly migration banner instead of crashing
$_po_tables_ok = function_exists('po_tablesExist') ? po_tablesExist($conn) : true;

if (!$_po_tables_ok):
?>
<div class="alert alert-warning d-flex align-items-start gap-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-3 flex-shrink-0"></i>
    <div class="flex-grow-1">
        <h5 class="alert-heading mb-1"><?= htmlspecialchars($LANG['po_setup_required']) ?></h5>
        <p class="mb-2">
            <?= htmlspecialchars($LANG['po_tables_missing_msg']) ?>
        </p>
        <p class="mb-2">
            <code>migrations/2026_purchase_orders.sql</code>
        </p>
        <ol class="mb-0 small">
            <li><?= htmlspecialchars($LANG['po_setup_step_1']) ?></li>
            <li><?= htmlspecialchars($LANG['po_setup_step_2']) ?></li>
            <li><?= htmlspecialchars($LANG['po_setup_step_3']) ?></li>
            <li><?= htmlspecialchars($LANG['po_setup_step_4']) ?></li>
        </ol>
    </div>
</div>
<?php
return; // Stop rendering the rest of the partial
endif;

// ── BUG FIX: Below was previously OUTSIDE the <?php block — variables never set.
$_po_can_create = canCreatePurchaseOrder();
$_po_can_update = canUpdatePurchaseOrder();
$_po_can_delete = canDeletePurchaseOrder();
$_po_can_export = canExportPurchaseOrder();
$_po_is_super   = isSuperAdmin(); // superadmin can Edit/Delete a PO in any status; everyone else still follows the draft/sent (edit) and draft-only (delete) rule below

// Status badge color map
$_po_status_color = [
    'draft'     => 'secondary',
    'sent'      => 'info',
    'approved'  => 'primary',
    'partial'   => 'warning',
    'received'  => 'success',
    'cancelled' => 'danger',
];
$_po_status_label = [
    'draft'     => $LANG['po_status_draft'],
    'sent'      => $LANG['po_status_sent'],
    'approved'  => $LANG['po_status_approved'],
    'partial'   => $LANG['po_status_partial'],
    'received'  => $LANG['po_status_received'],
    'cancelled' => $LANG['po_status_cancelled'],
];
?>

<?php
/* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4. Kunci sesi
   po_success/po_error TIDAK diubah — hanya cara merendernya. */
bltx_render_flash('po');
?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="bi bi-file-earmark-text"></i>
            <?= htmlspecialchars($LANG['purchase_order_list']) ?>
        </div>
        <div class="card-tools">
            <?php if ($_po_can_create): ?>
                <a href="<?= url('purchases/po_add.php') ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i>
                    <?= htmlspecialchars($LANG['add_purchase_order']) ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">

        <?php $_po_show_actions = canReadPurchaseOrder() || $_po_can_update || $_po_can_delete; ?>
        <div class="table-responsive">

            <!-- ✅ DATATABLE ENGINE AKTIF -->
            <table class="table table-striped table-hover datatable" id="dataTable">

                <thead>
                    <tr>
                        <th><?= htmlspecialchars($LANG['po_number']) ?></th>
                        <th><?= htmlspecialchars($LANG['order_date']) ?></th>
                        <th><?= htmlspecialchars($LANG['expected_date']) ?></th>
                        <th><?= htmlspecialchars($LANG['supplier']) ?></th>
                        <th><?= htmlspecialchars($LANG['items_count']) ?></th>
                        <th><?= htmlspecialchars($LANG['total_amount']) ?></th>
                        <th><?= htmlspecialchars($LANG['status']) ?></th>
                        <?php if ($_po_show_actions): ?>
                        <th><?= htmlspecialchars($LANG['actions']) ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>
        <?php
            foreach ($po_list as $po):
                $sKey   = $po['status'] ?? 'draft';
                $sColor = $_po_status_color[$sKey] ?? 'secondary';
                $sLabel = $_po_status_label[$sKey] ?? ucfirst($sKey);
        ?>
                <tr>
                    <td><span class="fw-semibold"><?= htmlspecialchars($po['po_number']) ?></span></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($po['order_date']))) ?></td>
                    <td>
                        <?= !empty($po['expected_date']) ? htmlspecialchars(date('d M Y', strtotime($po['expected_date']))) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td>
                        <?= !empty($po['supplier_name'])
                            ? htmlspecialchars($po['supplier_name'])
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td>
                        <span class="badge bg-secondary"><?= (int)($po['items_count'] ?? 0) ?></span>
                    </td>
                    <td>
                        <?= function_exists('formatCurrency')
                            ? formatCurrency((float)$po['total_amount'])
                            : number_format((float)$po['total_amount'], 2) ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $sColor ?>"><?= htmlspecialchars($sLabel) ?></span>
                    </td>
                    <?php if ($_po_show_actions): ?>
                    <td>
                        <div class="btn btn-sm-group btn-group-sm">
                            <a href="<?= url('purchases/po_view.php?id=' . (int)$po['id']) ?>"
                               class="btn btn-sm btn-info"
                               title="<?= htmlspecialchars($LANG['view']) ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if ($_po_can_update && ($_po_is_super || in_array($sKey, ['draft','sent'], true))): ?>
                            <a href="<?= url('purchases/po_edit.php?id=' . (int)$po['id']) ?>"
                               class="btn btn-sm btn-warning"
                               title="<?= htmlspecialchars($LANG['edit']) ?>">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($_po_can_delete && ($_po_is_super || $sKey === 'draft')): ?>
                            <button type="button" class="btn btn-sm btn-danger"
                                    <?php /* TAHAP 3 — XSS TERSIMPAN DITUTUP.
                                       Sebelumnya: '<?= addslashes($po['po_number']) ?>'.
                                       addslashes() mengamankan konteks string JavaScript,
                                       BUKAN konteks atribut HTML — backslash tidak punya arti
                                       bagi parser HTML. Nomor PO diisi pengguna, jadi nilai
                                       seperti  x" onmouseover=alert(1) y="  menutup atribut
                                       onclick lebih awal dan atribut sisipannya ikut berjalan
                                       di browser setiap orang yang membuka daftar ini.

                                       bltx_js() menghasilkan literal JSON lengkap dengan tanda
                                       kutipnya; JSON_HEX_QUOT/APOS/TAG/AMP mengubah " ' < &
                                       di dalam nilai menjadi \u00xx sehingga tidak ada yang
                                       tersisa untuk menutup atribut. bltx_e() kemudian
                                       mengamankan lapis atribut HTML-nya. */ ?>
                                    onclick="poDeleteConfirm(<?= (int)$po['id'] ?>, <?= bltx_e(bltx_js($po['po_number'])) ?>)"
                                    title="<?= htmlspecialchars($LANG['delete']) ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
        <?php
            endforeach;
        ?>
                </tbody>

            </table>
        </div>
    </div>
</div>

<!-- PO Delete confirmation form (POSTs to purchases.php?tab=order) -->
<?php if ($_po_can_delete): ?>
<form id="poDeleteForm" method="POST" action="<?= url('purchases/pr_dahsboard_gate.php?tab=order') ?>" style="display:none;">
    <input type="hidden" name="action" value="po_delete">
    <input type="hidden" name="po_id" id="poDeleteFormId" value="">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($po_csrf_token ?? po_csrfToken()) ?>">
</form>

<script>
function poDeleteConfirm(id, label) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: <?= bltx_js($LANG['confirm']) ?>,
            text:  <?= bltx_js($LANG['confirm_delete_po'] . ' ') ?> + label,
            icon:  'warning',
            showCancelButton: true,
            confirmButtonText: <?= bltx_js($LANG['delete']) ?>,
            cancelButtonText:  <?= bltx_js($LANG['cancel']) ?>,
            confirmButtonColor: '#dc3545'
        }).then(function (r) {
            if (r.isConfirmed) {
                document.getElementById('poDeleteFormId').value = id;
                document.getElementById('poDeleteForm').submit();
            }
        });
    } else if (confirm(<?= bltx_js($LANG['confirm_delete_po']) ?>)) {
        document.getElementById('poDeleteFormId').value = id;
        document.getElementById('poDeleteForm').submit();
    }
}
</script>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════
     URUTAN BARIS: DATA TERBARU DI ATAS

     po_getList() sudah mengurutkan "ORDER BY po.order_date DESC, po.id DESC",
     jadi baris teratas dari server memang PO terbaru. Namun auto-init
     DataTables di footer.php memakai `ordering: true` TANPA opsi `order`,
     sehingga DataTables mengurutkan ulang berdasarkan kolom pertama secara
     MENAIK dan urutan dari server hilang.

     Tabel ini karena itu diinisialisasi SENDIRI di sini dengan `order: []`
     (tanpa pengurutan awal), memakai opsi yang sama persis dengan footer.php.
     Auto-init di footer.php akan melewatinya karena sudah ada pengecekan
     `if ($.fn.DataTable.isDataTable(this)) return;` — jadi tidak ada
     inisialisasi ganda dan footer.php TIDAK perlu diubah.

     Header tabel TETAP bisa diklik untuk mengurutkan manual seperti biasa.
     ══════════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery || !$.fn.DataTable) return;
    var el = document.getElementById('dataTable');
    if (!el || $.fn.DataTable.isDataTable(el)) return;

    $(el).DataTable({
        order: [],
        pageLength: <?php echo (int)getSetting('pagination', 10); ?>,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        paging: true, searching: true, ordering: true, info: true,
        autoWidth: false, responsive: true,
        language: {
            search:    "<?php echo $LANG['search']; ?>:",
            lengthMenu:"<?php echo $LANG['show']; ?> _MENU_ <?php echo $LANG['entries']; ?>",
            info:      "<?php echo $LANG['showing']; ?> _START_ <?php echo $LANG['to']; ?> _END_ <?php echo $LANG['of']; ?> _TOTAL_ <?php echo $LANG['entries']; ?>",
            paginate: {
                first:"<?php echo $LANG['first']; ?>", last:"<?php echo $LANG['last']; ?>",
                next:"<?php echo $LANG['next']; ?>", previous:"<?php echo $LANG['previous']; ?>"
            },
            emptyTable:"<?php echo $LANG['no_data']; ?>", zeroRecords:"<?php echo $LANG['no_matching']; ?>"
        },
        dom:
            "<'row mb-3'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
            "<'row'<'col-sm-12'tr>>" +
            "<'row mt-3'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>"
    });
});
</script>