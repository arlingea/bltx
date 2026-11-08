<?php
// Dual-mode file:
//  • Standalone:  /purchases/returns.php  (full page with header + footer)
//  • Partial:     included by /purchases/purchases.php when tab=returns
//
// CATATAN PERUBAHAN:
// Card "Return List" (daftar return LAMA berbasis tabel `returns`) sudah
// DIHAPUS dari halaman ini beserta seluruh kode pendukungnya: handler delete
// lewat GET, query pencarian, pemanggilan pf_highlight(), dan alert
// $_SESSION['success'] miliknya. Halaman ini sekarang hanya menampilkan
// Purchase Return flow baru (PO → Goods Receipt → Invoice → Return).
//
// Tabel `returns` di database TIDAK dihapus dan TIDAK diubah — datanya tetap
// utuh sebagai historical data, hanya tidak lagi ditampilkan di sini.
$__included_as_partial = defined('APP_NAME') && isset($LANG, $conn);

if (!$__included_as_partial) {
    require_once '../includes/config.php';
    $page_title = $LANG['returns'];
    include '../includes/header.php';
}

// Helper flow BARU (PO -> GR -> Invoice -> Return). Dimuat di kedua mode.
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/goods_receipt_function.php';
require_once __DIR__ . '/../includes/purchase_returns_function.php';

if (!checkPermission('returns', 'can_access')) redirect(url('dashboard.php'));

// ── Data Purchase Return (tabel purchase_returns) ─────────────────────────
// Dirender penuh lalu dipaginasi oleh DataTables di sisi browser, persis
// seperti perilaku daftar Return lama. Filter Report Data Scope
// (All Data / Own Data Only) tetap diterapkan di dalam pret_getList().
$pr_csrf  = pret_csrfToken();
$pr_ready = pret_tablesExist($conn);
$pr_total = $pr_ready ? pret_countList($conn, []) : 0;
$pr_rows  = ($pr_ready && $pr_total > 0) ? pret_getList($conn, [], $pr_total, 0) : [];
?>

<?php
/* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4 — menggantikan
   blok HTML+JS yang isinya identik dengan berkas lain. Kunci sesinya
   TIDAK diubah, hanya cara merendernya. */
bltx_render_flash('pr');
?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="bi bi-arrow-return-left"></i> <?php echo $LANG['pr_new_flow_list']; ?></div>
        <div class="card-tools">
            <?php if (!canViewAllPurchaseReturn()): ?>
            <span class="badge text-bg-light border text-muted me-2" title="<?php echo htmlspecialchars($LANG['perm_scope_own_hint']); ?>">
                <i class="bi bi-person-lock"></i> <?php echo htmlspecialchars($LANG['perm_dreport_scope_own']); ?>
            </span>
            <?php endif; ?>
            <?php if (canExportPurchaseReturn()): ?>
            <a href="<?= url('includes/export/export_purchases.php?type=excel&data=returns') ?>" class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-excel"></i> <?php echo $LANG['export_excel']; ?>
            </a>
            <?php endif; ?>
            <?php if (canCreatePurchaseReturn()): ?>
            <a href="<?php echo url('purchases/purchase_return_add.php'); ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle"></i> <?php echo $LANG['add']; ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">

            <!-- ✅ DATATABLE ENGINE AKTIF -->
            <table class="table table-striped table-hover datatable" id="dataTable">

                <thead>
                    <tr>
                        <th><?php echo $LANG['pr_return_number']; ?></th>
                        <th><?php echo $LANG['po_number']; ?></th>
                        <th><?php echo $LANG['gr_number']; ?></th>
                        <th><?php echo $LANG['supplier']; ?></th>
                        <th><?php echo $LANG['return_date']; ?></th>
                        <th><?php echo $LANG['total']; ?></th>
                        <th><?php echo $LANG['status']; ?></th>
                        <th><?php echo $LANG['created_by']; ?></th>
                        <th><?php echo $LANG['actions']; ?></th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($pr_rows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['return_number']); ?></td>
                        <td><?php echo htmlspecialchars($row['po_number'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($row['gr_number'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($row['supplier_name'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($row['return_date']))); ?></td>
                        <td><?php echo htmlspecialchars(formatCurrency((float)$row['total_amount'])); ?></td>
                        <td>
                            <span class="badge bg-<?php echo pret_statusColor((string)$row['status']); ?>">
                                <?php echo htmlspecialchars(pret_statusLabel((string)$row['status'])); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($row['created_by_name']); ?></td>
                        <td>
                            <div class="btn btn-sm-group btn-group-sm">
                                <?php if (canReadPurchaseReturn()): ?>
                                    <a href="<?php echo url('purchases/purchase_return_view.php?id=' . (int)$row['id']); ?>"
                                       class="btn btn-sm btn-info" title="<?php echo $LANG['view']; ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if ($row['status'] === 'pending' && canUpdatePurchaseReturn()): ?>
                                    <a href="<?php echo url('purchases/purchase_return_edit.php?id=' . (int)$row['id']); ?>"
                                       class="btn btn-sm btn-warning" title="<?php echo $LANG['edit']; ?>">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
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

<?php if (!$__included_as_partial) include '../includes/footer.php'; ?>