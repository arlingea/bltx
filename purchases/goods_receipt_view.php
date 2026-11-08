<?php
// ═══════════════════════════════════════════════════════════════════════════
//  GOODS RECEIPT — VIEW
//
//  Menampilkan dokumen penerimaan, perbandingan PO vs aktual, serta
//  traceability ke Purchase Invoice dan Purchase Return.
//
//  Seluruh aksi yang mengubah state memakai POST + CSRF + permission.
//  Tidak ada aksi GET yang mengubah data.
// ═══════════════════════════════════════════════════════════════════════════
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/purchase_orders_function.php';
require_once __DIR__ . '/../includes/goods_receipt_function.php';
require_once __DIR__ . '/../includes/purchase_returns_function.php';

$page_title = $LANG['goods_receipt'];
include '../includes/header.php';

requireGoodsReceiptAccess();
// GERBANG PURCHASE — modul 'purchase' harus aktif. Tanpa baris ini,
// mematikan gerbang tidak menutup halaman ini bila diketik langsung
// lewat URL. Superadmin selalu lolos di dalam _dbCan().
requirePurchaseModule('goods_receipt');

if (!canReadGoodsReceipt()) {
    bltx_flash_error($LANG['no_permission']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=goods_receipt'));
}
if (!gr_tablesExist($conn)) {
    bltx_flash_error($LANG['gr_tables_missing']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=goods_receipt'));
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect(url('purchases/pr_dahsboard_gate.php?tab=goods_receipt'));

$csrf_token = gr_csrfToken();

// ── POST: workflow actions (whitelist ketat) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    $csrf   = (string)($_POST['csrf_token'] ?? '');

    if ($action === 'gr_cancel') {
        // Alasan pembatalan diteruskan apa adanya; gr_cancel() yang menentukan
        // wajib atau tidaknya (wajib untuk GR berstatus received).
        $r = gr_cancel($conn, $id, $csrf, (string)($_POST['cancel_reason'] ?? ''));
    } elseif ($action === 'gr_record_payment') {
        // SATU-SATUNYA pintu penulisan data pembayaran.
        $r = gr_recordPayment($conn, $id, $_POST);
    } elseif ($action === 'gr_make_invoice') {
        // Tanggal dari modal "Input Date" diteruskan MENTAH; seluruh validasi
        // (format kalender + batas maxDate hari ini) dikerjakan di dalam
        // gr_createInvoiceFromGr() supaya aturannya hanya ada di satu tempat.
        $r = gr_createInvoiceFromGr($conn, $id, $csrf, (string)($_POST['invoice_input_date'] ?? ''));
    } else {
        $r = ['success' => false, 'message_key' => 'error'];
    }

    $key = (string)$r['message_key'];
    if ($r['success']) {
        bltx_flash_success(isset($LANG[$key]) ? $LANG[$key] : $LANG['save_success']);
        if ($action === 'gr_make_invoice' && !empty($r['purchase_id'])) {
            // Purchase Invoice yang baru terbit TIDAK LAGI membuka halaman
            // purchase_view.php. Id-nya dititipkan sekali pakai lewat session,
            // lalu halaman ini menampilkannya sebagai MODAL sehingga user
            // tetap berada di dokumen penerimaan barangnya.
            // Redirect tetap ke halaman ini sendiri (pola POST-Redirect-GET
            // yang sudah dipakai di bawah), jadi refresh tidak pernah
            // menerbitkan invoice kedua.
            $_SESSION['gr_invoice_modal_id'] = (int)$r['purchase_id'];
        }
    } else {
        bltx_flash_error(isset($LANG[$key]) ? $LANG[$key] : $LANG['error']);
    }
    redirect(url('purchases/goods_receipt_view.php?id=' . $id));
}

$gr = gr_getOne($conn, $id);
if (!$gr) {
    bltx_flash_error($LANG['gr_not_found']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=goods_receipt'));
}
// REPORT DATA SCOPE: cegah pembukaan dokumen milik user lain lewat ?id=
if (!gr_scopeAllows($gr)) {
    bltx_flash_error($LANG['perm_scope_denied']);
    redirect(url('purchases/pr_dahsboard_gate.php?tab=goods_receipt'));
}

$invoices    = gr_getInvoicesByGr($conn, $id);
$gr_returns  = pret_getByGr($conn, $id);
$sColor      = gr_statusColor((string)$gr['status']);
$sLabel      = gr_statusLabel((string)$gr['status']);

// ── STATUS PEMBAYARAN ─────────────────────────────────────────────────────
// Dimensi TERPISAH dari $gr['status']: status menyatakan kondisi FISIK barang,
// payment_status menyatakan apakah uangnya sudah keluar. GR yang dibuat
// sebelum migrasi bernilai 'paid', sehingga tidak ada dokumen lama yang
// tombol Create Invoice-nya ikut terkunci.
$payReady  = gr_hasPaymentStatusColumn($conn) && gr_hasPaymentColumns($conn);
$payStatus = gr_paymentStatus($gr);
$is_unpaid = ($payStatus === 'unpaid');
$pColor    = gr_paymentStatusColor($payStatus);
$pLabel    = gr_paymentStatusLabel($payStatus);

$has_invoice = count($invoices) > 0;
$has_return  = count($gr_returns) > 0;

// Mencatat pembayaran memakai permission goods_receipt.can_update yang SUDAH
// ADA — tidak ada permission baru, agar tidak terjadi double permission.
$can_pay     = $payReady && canUpdateGoodsReceipt()
               && $gr['status'] === 'received' && $is_unpaid;

$can_edit    = canUpdateGoodsReceipt() && $gr['status'] !== 'cancelled' && !$has_invoice;
$can_cancel  = canCancelGoodsReceipt() && $gr['status'] !== 'cancelled' && !$has_invoice && !$has_return;
// GERBANG PEMBAYARAN: invoice hanya bisa dibuat setelah pembayaran tercatat.
$can_invoice = canCreatePurchase() && $gr['status'] === 'received' && !$has_invoice
               && !$is_unpaid && gr_purchasesHasGrColumn($conn);
$can_return  = canCreatePurchaseReturn() && $gr['status'] === 'received' && pret_tablesExist($conn);

// Data dropdown modal Record Payment. Ketiga query ini HANYA dijalankan bila
// modalnya memang akan dirender, sehingga halaman view untuk GR yang sudah
// lunas tidak menanggung query tambahan sama sekali.
$pay_methods = $pay_staff = $pay_shifts = [];
if ($can_pay) {
    $__pm = $conn->query("SELECT id, name FROM payment_methods WHERE is_active = 1 ORDER BY name ASC");
    if ($__pm) { while ($__r = $__pm->fetch_assoc()) $pay_methods[] = $__r; }
    $__st = $conn->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC");
    if ($__st) { while ($__r = $__st->fetch_assoc()) $pay_staff[] = $__r; }
    $__sh = $conn->query("SELECT id, name FROM employee_shifts WHERE is_active = 1 ORDER BY name ASC");
    if ($__sh) { while ($__r = $__sh->fetch_assoc()) $pay_shifts[] = $__r; }
}

$subtotal = 0.0;
foreach ($gr['items'] as $it) $subtotal += (float)$it['total_price'];
?>

<style>
/* ══════════════════════════════════════════════════════════════════════
   RESPONSIVE — TABLET & MOBILE (blok TAMBAHAN, hanya @media query)
   Aturan lain pada halaman ini tidak diubah sama sekali.
   ══════════════════════════════════════════════════════════════════════ */
@media (max-width: 991.98px) {
    /* Kolom dokumen (col-lg-8) dan sidebar info (col-lg-4) menumpuk penuh */
    .page-title-bar { flex-wrap: wrap; gap: .5rem; }
    .page-actions { width: 100%; display: flex; flex-wrap: wrap; gap: .35rem; }
    .page-actions .btn { flex: 1 1 auto; }
}
@media (max-width: 767.98px) {
    /* Tabel perbandingan PO vs aktual punya 10 kolom — digulir horizontal
       dengan momentum, ukuran huruf dirapatkan agar lebih banyak terlihat */
    .table-responsive { -webkit-overflow-scrolling: touch; }
    .table-responsive > table { font-size: .8rem; }
    .table-responsive > table th,
    .table-responsive > table td { white-space: nowrap; padding: .35rem .4rem; }

    .card-header { flex-wrap: wrap; gap: .5rem; }
    .card-body { padding: .85rem; }

    /* Tombol workflow di sidebar kanan dibuat nyaman disentuh */
    .d-grid.gap-2 .btn { min-height: 42px; }
}
@media (max-width: 575.98px) {
    .page-title-text { font-size: 1.15rem; }
    .table-responsive > table { font-size: .75rem; }
}
</style>

<div class="page-title-bar">
    <div>
        <h2 class="page-title-text">
            <i class="bi bi-box-seam"></i>
            <?= htmlspecialchars($gr['gr_number']) ?>
            <span class="badge bg-<?= $sColor ?> ms-2 align-middle" style="font-size:.65em;"><?= htmlspecialchars($sLabel) ?></span>
            <?php if ($payReady && $gr['status'] !== 'cancelled'): ?>
            <span class="badge bg-<?= $pColor ?> ms-1 align-middle" style="font-size:.65em;"><?= htmlspecialchars($pLabel) ?></span>
            <?php endif; ?>
        </h2>
        <div class="page-subtitle"><?= htmlspecialchars($page_title) ?></div>
    </div>
    <div class="page-actions">
        <a href="<?= url('purchases/pr_dahsboard_gate.php?tab=goods_receipt') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back']) ?>
        </a>
        <?php if ($can_edit): ?>
        <a href="<?= url('purchases/goods_receipt_edit.php?id=' . $id) ?>" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil-square"></i> <?= htmlspecialchars($LANG['edit']) ?>
        </a>
        <?php endif; ?>
        <?php if (!empty($gr['po_id'])): ?>
        <a href="<?= url('purchases/po_view.php?id=' . (int)$gr['po_id']) ?>" class="btn btn-sm btn-info">
            <i class="bi bi-file-earmark-text"></i> <?= htmlspecialchars($LANG['purchase_order']) ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php
/* RONDE B — pembaca flash ikut dipindah ke helper terpusat.
   Setter-nya kini menulis ke kunci bersama 'success'/'error', jadi blok
   yang membaca kunci lama TIDAK AKAN PERNAH menyala lagi — pesannya
   hilang diam-diam. Menggantikan toast sukses manual (±30 baris
   HTML+JS) dan satu alert gagal inline. */
bltx_render_flash('gr');
?>

<div class="row g-3">

    <!-- ── Dokumen penerimaan ── -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-clipboard-check"></i> <?= htmlspecialchars($LANG['gr_comparison']) ?></div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars($LANG['item_name']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['gr_ordered_qty']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['gr_received_qty']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['gr_rejected_qty']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['gr_accepted_qty']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['gr_variance_qty']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['gr_po_price']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['gr_actual_price']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['gr_price_variance']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['subtotal']) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($gr['items'] as $it):
                            $isExtra  = empty($it['po_item_id']);
                            $ordered  = (float)$it['ordered_quantity'];
                            $accepted = (float)$it['accepted_quantity'];
                            $varQty   = $accepted - $ordered;
                            $varPrice = (float)$it['unit_price'] - (float)$it['po_unit_price'];
                            $unitTxt  = $it['unit_symbol'] ?: ($it['unit_name'] ?: '');
                        ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($it['item_name']) ?>
                                    <?php if ($isExtra): ?>
                                        <span class="badge text-bg-warning ms-1"><?= htmlspecialchars($LANG['gr_extra_item']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($it['close_line'])): ?>
                                        <span class="badge text-bg-secondary ms-1" title="<?= htmlspecialchars($LANG['gr_close_line_hint']) ?>">
                                            <i class="bi bi-slash-circle"></i> <?= htmlspecialchars($LANG['gr_close_line_badge']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($it['notes'])): ?>
                                        <br><small class="text-muted"><?= nl2br(htmlspecialchars($it['notes'])) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= $isExtra ? '—' : htmlspecialchars(gr_formatQty($ordered) . ' ' . $unitTxt) ?></td>
                                <td class="text-end"><?= htmlspecialchars(gr_formatQty($it['received_quantity'])) ?></td>
                                <td class="text-end <?= (float)$it['rejected_quantity'] > 0 ? 'text-danger' : '' ?>">
                                    <?= htmlspecialchars(gr_formatQty($it['rejected_quantity'])) ?>
                                </td>
                                <td class="text-end fw-semibold"><?= htmlspecialchars(gr_formatQty($accepted)) ?></td>
                                <td class="text-end <?= $varQty < 0 ? 'text-danger' : ($varQty > 0 ? 'text-success' : '') ?>">
                                    <?= $isExtra ? '—' : (($varQty > 0 ? '+' : '') . htmlspecialchars(gr_formatQty($varQty))) ?>
                                </td>
                                <td class="text-end"><?= $isExtra ? '—' : htmlspecialchars(formatCurrency((float)$it['po_unit_price'])) ?></td>
                                <td class="text-end"><?= htmlspecialchars(formatCurrency((float)$it['unit_price'])) ?></td>
                                <td class="text-end <?= $varPrice > 0 ? 'text-danger' : ($varPrice < 0 ? 'text-success' : '') ?>">
                                    <?= $isExtra ? '—' : (($varPrice > 0 ? '+' : '') . htmlspecialchars(formatCurrency($varPrice))) ?>
                                </td>
                                <td class="text-end"><?= htmlspecialchars(formatCurrency((float)$it['total_price'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="9" class="text-end"><?= htmlspecialchars($LANG['subtotal']) ?></th>
                                <th class="text-end"><?= htmlspecialchars(formatCurrency($subtotal)) ?></th>
                            </tr>
                            <tr>
                                <td colspan="9" class="text-end"><?= htmlspecialchars($LANG['overall_discount']) ?></td>
                                <td class="text-end"><?= htmlspecialchars(formatCurrency((float)$gr['overall_discount'])) ?></td>
                            </tr>
                            <tr>
                                <td colspan="9" class="text-end"><?= htmlspecialchars($LANG['price_rounding']) ?></td>
                                <td class="text-end"><?= htmlspecialchars(formatCurrency((float)$gr['overall_price_rounding'])) ?></td>
                            </tr>
                            <tr>
                                <td colspan="9" class="text-end"><?= htmlspecialchars($LANG['po_doc_ppn_label']) ?></td>
                                <td class="text-end"><?= htmlspecialchars(formatCurrency((float)$gr['tax'])) ?></td>
                            </tr>
                            <tr class="table-light">
                                <th colspan="9" class="text-end"><?= htmlspecialchars($LANG['grand_total']) ?></th>
                                <th class="text-end"><?= htmlspecialchars(formatCurrency((float)$gr['total_amount'])) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if (!empty($gr['variance_reason'])): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    <strong><?= htmlspecialchars($LANG['gr_variance_reason']) ?>:</strong><br>
                    <?= nl2br(htmlspecialchars($gr['variance_reason'])) ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($gr['notes'])): ?>
                <div class="alert alert-light border mt-3 mb-0">
                    <strong><?= htmlspecialchars($LANG['notes']) ?>:</strong><br>
                    <?= nl2br(htmlspecialchars($gr['notes'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Sidebar : info + workflow + traceability ── -->
    <div class="col-lg-4">

        <div class="card mb-3">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-info-circle"></i> <?= htmlspecialchars($LANG['gr_header']) ?></div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['gr_receipt_date']) ?></div>
                        <div class="fw-semibold"><?= htmlspecialchars(date('d M Y', strtotime($gr['receipt_date']))) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['gr_delivery_note']) ?></div>
                        <div><?= htmlspecialchars($gr['delivery_note_number'] ?: '—') ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['po_number']) ?></div>
                        <div>
                            <?php if (!empty($gr['po_id'])): ?>
                                <a href="<?= url('purchases/po_view.php?id=' . (int)$gr['po_id']) ?>"><?= htmlspecialchars($gr['po_number']) ?></a>
                            <?php else: ?>
                                <span class="text-muted"><?= htmlspecialchars($LANG['gr_no_po']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['supplier']) ?></div>
                        <div><?= htmlspecialchars($gr['supplier_name'] ?: '—') ?></div>
                    </div>
                    <?php if (!empty($gr['purchase_staff_name'])): ?>
                    <div class="col-6">
                        <?php
                        /* NAMA purchase staff, tersimpan permanen sebagai teks.
                           Untuk GR dengan PO berasal dari kontak Shipping
                           Address PO; untuk GR tanpa PO dari dropdown pada
                           form. Ditampilkan read-only — satu-satunya tempat
                           penulisannya adalah saat GR dibuat. */
                        ?>
                        <div class="small text-muted"><?= htmlspecialchars($LANG['purchase_staff']) ?></div>
                        <div><?= htmlspecialchars($gr['purchase_staff_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['created_by']) ?></div>
                        <div><?= htmlspecialchars($gr['created_by_name']) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['created_at']) ?></div>
                        <div><?= htmlspecialchars(date('d M Y H:i', strtotime($gr['created_at']))) ?></div>
                    </div>
                    <?php if (!empty($gr['updated_by_name'])): ?>
                    <div class="col-12">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['updated_at']) ?></div>
                        <div><?= htmlspecialchars($gr['updated_by_name']) ?> &middot; <?= htmlspecialchars(date('d M Y H:i', strtotime($gr['updated_at']))) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($gr['cancelled_by_name'])): ?>
                    <div class="col-12">
                        <div class="small text-muted"><?= htmlspecialchars($LANG['cancelled']) ?></div>
                        <div class="text-danger"><?= htmlspecialchars($gr['cancelled_by_name']) ?> &middot; <?= htmlspecialchars(date('d M Y H:i', strtotime((string)$gr['cancelled_at']))) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-stoplights"></i> <?= htmlspecialchars($LANG['status_workflow']) ?></div>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-2"><?= htmlspecialchars($LANG['current_status']) ?>:</p>
                <p><span class="badge bg-<?= $sColor ?> p-2 fs-6"><?= htmlspecialchars($sLabel) ?></span></p>

                <?php if ($gr['status'] === 'cancelled' && !empty($gr['cancel_reason'])): ?>
                <!-- Alasan pembatalan ditampilkan, bukan hanya disimpan —
                     catatan permanen tidak ada gunanya bila tidak pernah terbaca. -->
                <div class="alert alert-danger small py-2">
                    <i class="bi bi-chat-left-text"></i>
                    <strong><?= htmlspecialchars($LANG['gr_cancel_reason']) ?>:</strong><br>
                    <?= nl2br(htmlspecialchars($gr['cancel_reason'])) ?>
                </div>
                <?php endif; ?>

                <?php if ($payReady && $gr['status'] !== 'cancelled'): ?>
                <p class="small text-muted mb-2"><?= htmlspecialchars($LANG['gr_payment_status']) ?>:</p>
                <p class="mb-2"><span class="badge bg-<?= $pColor ?> p-2 fs-6"><?= htmlspecialchars($pLabel) ?></span></p>
                <?php if ($is_unpaid): ?>
                <div class="alert alert-warning small py-2">
                    <i class="bi bi-hourglass-split"></i> <?= htmlspecialchars($LANG['gr_unpaid_notice']) ?>
                </div>
                <?php elseif (!empty($gr['paid_at'])): ?>
                <div class="small text-muted mb-2">
                    <i class="bi bi-check2-circle"></i>
                    <?= htmlspecialchars($LANG['gr_paid_at']) ?>:
                    <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$gr['paid_at']))) ?>
                    <?php if (!empty($gr['paid_by_name'])): ?>
                    <br><i class="bi bi-person-check"></i>
                    <?= htmlspecialchars($LANG['gr_paid_by']) ?>: <?= htmlspecialchars($gr['paid_by_name']) ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <div class="alert alert-secondary small py-2">
                    <i class="bi bi-shield-check"></i> <?= htmlspecialchars($LANG['gr_no_inventory_notice']) ?>
                </div>

                <div class="d-grid gap-2">
                    <?php if ($can_pay): ?>
                    <!-- Membuka modal Record Payment. Tombol ini adalah satu-satunya
                         jalan masuk penulisan data pembayaran di seluruh sistem. -->
                    <button type="button" class="btn btn-sm btn-success w-100"
                            data-bs-toggle="modal" data-bs-target="#grPaymentModal">
                        <i class="bi bi-cash-coin"></i> <?= htmlspecialchars($LANG['gr_record_payment']) ?>
                    </button>
                    <?php endif; ?>

                    <?php if ($payReady && $is_unpaid && $gr['status'] === 'received' && !$can_invoice): ?>
                    <div class="form-text small">
                        <i class="bi bi-lock"></i> <?= htmlspecialchars($LANG['gr_invoice_needs_paid']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($can_invoice): ?>
                    <!-- Membuka modal Input Date. Konfirmasi tidak hilang —
                         teksnya ($LANG['gr_confirm_invoice']) sekarang tampil
                         sebagai peringatan DI DALAM modal, persis pola modal
                         Cancel Receipt di halaman yang sama, sehingga user
                         tetap membaca peringatannya sebelum menekan Save. -->
                    <button type="button" class="btn btn-sm btn-primary w-100"
                            data-bs-toggle="modal" data-bs-target="#grInvoiceDateModal">
                        <i class="bi bi-receipt"></i> <?= htmlspecialchars($LANG['gr_create_invoice']) ?>
                    </button>
                    <?php endif; ?>

                    <?php if ($can_return): ?>
                    <a href="<?= url('purchases/purchase_return_add.php?gr_id=' . $id) ?>" class="btn btn-sm btn-warning w-100">
                        <i class="bi bi-arrow-return-left"></i> <?= htmlspecialchars($LANG['pr_create_return']) ?>
                    </a>
                    <?php endif; ?>

                    <?php if ($can_cancel): ?>
                    <!-- Pembatalan GR berstatus 'received' menghapus catatan
                         penerimaan atas barang yang fisiknya sudah di gudang,
                         dan bila belum dibayar sekaligus melenyapkan hutang ke
                         supplier. Karena itu alasannya diminta lewat modal dan
                         disimpan permanen di kolom cancel_reason. GR 'draft'
                         belum berdampak apa pun, jadi tetap konfirmasi biasa. -->
                    <?php if ($gr['status'] === 'received'): ?>
                    <button type="button" class="btn btn-sm btn-danger w-100"
                            data-bs-toggle="modal" data-bs-target="#grCancelModal">
                        <i class="bi bi-x-circle"></i> <?= htmlspecialchars($LANG['gr_cancel_receipt']) ?>
                    </button>
                    <?php else: ?>
                    <form method="POST" action="" class="gr-confirm-form" data-confirm-msg="<?= htmlspecialchars($LANG['gr_confirm_cancel']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="gr_cancel">
                        <button class="btn btn-sm btn-danger w-100">
                            <i class="bi bi-x-circle"></i> <?= htmlspecialchars($LANG['gr_cancel_receipt']) ?>
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($has_invoice): ?>
                    <div class="form-text small"><i class="bi bi-lock"></i> <?= htmlspecialchars($LANG['gr_has_invoice_locked']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="bi bi-diagram-3"></i> <?= htmlspecialchars($LANG['gr_traceability']) ?></div>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-1"><?= htmlspecialchars($LANG['gr_invoice_history']) ?></p>
                <?php if (!$invoices): ?>
                    <p class="small text-muted"><?= htmlspecialchars($LANG['no_data']) ?></p>
                <?php else: ?>
                <ul class="list-unstyled mb-3">
                    <?php foreach ($invoices as $inv): ?>
                    <li>
                        <a href="<?= url('purchases/purchase_view.php?id=' . (int)$inv['id']) ?>">
                            <i class="bi bi-receipt"></i> <?= htmlspecialchars($LANG['invoice']) ?> #<?= (int)$inv['id'] ?>
                        </a>
                        <small class="text-muted">&middot; <?= htmlspecialchars(date('d/m/Y', strtotime($inv['invoice_date']))) ?></small>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <p class="small text-muted mb-1"><?= htmlspecialchars($LANG['gr_return_history']) ?></p>
                <?php if (!$gr_returns): ?>
                    <p class="small text-muted mb-0"><?= htmlspecialchars($LANG['no_data']) ?></p>
                <?php else: ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($gr_returns as $rt): ?>
                    <li class="d-flex justify-content-between align-items-center">
                        <a href="<?= url('purchases/purchase_return_view.php?id=' . (int)$rt['id']) ?>">
                            <i class="bi bi-arrow-return-left"></i> <?= htmlspecialchars($rt['return_number']) ?>
                        </a>
                        <span class="badge bg-<?= pret_statusColor((string)$rt['status']) ?>"><?= htmlspecialchars(pret_statusLabel((string)$rt['status'])) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php if ($can_pay): ?>
<!-- ══════════════════════════════════════════════════════════════════════
     MODAL — RECORD PAYMENT
     Satu-satunya tempat penulisan data pembayaran. Yang dikirim HANYA
     keempat field pembayaran; baris item, qty, harga, total, dan status GR
     tidak pernah ikut terkirim, sehingga mencatat pembayaran tidak mungkin
     mengubah isi dokumen penerimaan.
     Dropdown memakai <select> biasa (bukan komponen pencarian) supaya
     atribut `required` bawaan browser tetap bisa memfokuskan field-nya.
     ══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="grPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" id="grPaymentForm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cash-coin"></i> <?= htmlspecialchars($LANG['gr_record_payment']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars($LANG['cancel']) ?>"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="gr_record_payment">

                    <div class="alert alert-light border small py-2">
                        <i class="bi bi-info-circle"></i>
                        <?= htmlspecialchars($gr['gr_number']) ?> &middot;
                        <?= htmlspecialchars(formatCurrency((float)$gr['total_amount'])) ?>
                    </div>

                    <!-- Tanggal Pembayaran — default hari ini, ditampilkan DD-MM-YYYY.
                         Kotak yang terlihat readonly (dipilih lewat daterangepicker
                         mode singleDatePicker); nilai yang benar-benar dikirim ke
                         server ada di input tersembunyi payment_date (format Y-m-d),
                         mengikuti pola yang sama dipakai di stock_opname.php. -->
                    <div class="mb-3">
                        <label class="form-label" for="grPaymentDateInput"><i class="bi bi-calendar-event me-1"></i><?= htmlspecialchars($LANG['gr_payment_date']) ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="grPaymentDateInput"
                               value="<?= date('d-m-Y') ?>" placeholder="DD-MM-YYYY"
                               readonly autocomplete="off">
                        <input type="hidden" name="payment_date" id="grPaymentDateHidden" value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-credit-card me-1"></i><?= htmlspecialchars($LANG['payment_method']) ?> <span class="text-danger">*</span></label>
                        <select class="form-select" name="payment_method" required>
                            <option value="">-- <?= htmlspecialchars($LANG['select']) ?> --</option>
                            <?php foreach ($pay_methods as $pm): ?>
                            <option value="<?= (int)$pm['id'] ?>"><?= htmlspecialchars($pm['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-person me-1"></i><?= htmlspecialchars($LANG['payment_by']) ?> <span class="text-danger">*</span></label>
                        <select class="form-select" name="payment_by" required>
                            <option value="">-- <?= htmlspecialchars($LANG['select']) ?> --</option>
                            <?php foreach ($pay_staff as $st): ?>
                            <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-person-check me-1"></i><?= htmlspecialchars($LANG['payer_name']) ?> <span class="text-danger">*</span></label>
                        <select class="form-select" name="payer_name" required>
                            <option value="">-- <?= htmlspecialchars($LANG['select']) ?> --</option>
                            <?php foreach ($pay_staff as $st): ?>
                            <option value="<?= (int)$st['id'] ?>"><?= htmlspecialchars($st['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-clock me-1"></i><?= htmlspecialchars($LANG['payer_shift']) ?> <span class="text-danger">*</span></label>
                        <select class="form-select" name="payer_shift" required>
                            <option value="">-- <?= htmlspecialchars($LANG['select']) ?> --</option>
                            <?php foreach ($pay_shifts as $sh): ?>
                            <option value="<?= (int)$sh['id'] ?>"><?= htmlspecialchars($sh['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Catatan pembayaran — TIDAK wajib diisi.
                         Tinggi disamakan dengan kolom lain di modal ini: rows="1"
                         plus height 38px, yaitu tinggi .form-select Bootstrap 5 pada
                         ukuran baku. Tanpa itu, textarea rows="1" tetap lebih pendek
                         karena line-height-nya berbeda dari select.
                         Nilainya disimpan ke goods_receipts.notes, dan dari sana
                         terbawa otomatis ke purchases.notes saat invoice dibuat. -->
                    <div class="mb-0">
                        <label class="form-label"><i class="bi bi-sticky me-1"></i><?= htmlspecialchars($LANG['notes']) ?></label>
                        <textarea class="form-control" name="notes" rows="1"
                                  style="height:38px; resize:vertical;" maxlength="500"
                                  placeholder="<?= htmlspecialchars($LANG['optional']) ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> <?= htmlspecialchars($LANG['cancel']) ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-check2-circle"></i> <?= htmlspecialchars($LANG['save']) ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Daterangepicker CSS — dimuat lewat url(), bukan path hardcode. Hanya
     dicetak bila modal Record Payment memang dirender. -->
<link rel="stylesheet" href="<?= url('assets/vendor/daterangepicker/daterangepicker.css') ?>">

<script>
/* ══════════════════════════════════════════════════════════════════════════
   PAYMENT DATE PICKER — modal Record Payment
   Kotak yang terlihat (#grPaymentDateInput) readonly, format tampilan
   DD-MM-YYYY, default hari ini. Nilai yang benar-benar terkirim ke server
   ada di input tersembunyi #grPaymentDateHidden (format Y-m-d), karena
   gr_recordPayment() memvalidasi payment_date dengan DateTime::createFromFormat
   'Y-m-d'. moment.js & daterangepicker dimuat lazy dari assets/vendor lokal,
   sama seperti pola yang sudah dipakai di inventory_in.php / stock_opname.php.
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    function loadScript(src, onload) {
        var s = document.createElement('script');
        s.src = src;
        s.onload = onload || null;
        document.head.appendChild(s);
    }

    function initGrPaymentDatePicker() {
        var input = document.getElementById('grPaymentDateInput');
        var hidden = document.getElementById('grPaymentDateHidden');
        if (!input || !hidden) return;
        if (typeof jQuery === 'undefined' || typeof moment === 'undefined' || typeof jQuery.fn.daterangepicker === 'undefined') {
            return;
        }

        jQuery(input).daterangepicker({
            singleDatePicker: true,
            autoUpdateInput: false,
            showDropdowns: true,
            startDate: moment(hidden.value, 'YYYY-MM-DD'),
            locale: {
                format: 'DD-MM-YYYY',
                cancelLabel: '<?= htmlspecialchars($LANG['cancel']) ?>'
            }
        });

        jQuery(input).on('apply.daterangepicker', function (ev, picker) {
            input.value = picker.startDate.format('DD-MM-YYYY');
            hidden.value = picker.startDate.format('YYYY-MM-DD');
        });
    }

    function tryInit() {
        if (typeof jQuery !== 'undefined' && typeof moment !== 'undefined') {
            if (typeof jQuery.fn.daterangepicker === 'undefined') {
                loadScript('<?= url('assets/vendor/daterangepicker/daterangepicker.min.js') ?>', initGrPaymentDatePicker);
            } else {
                initGrPaymentDatePicker();
            }
        } else {
            setTimeout(tryInit, 100);
        }
    }

    if (typeof moment === 'undefined') {
        loadScript('<?= url('assets/vendor/moment/moment.min.js') ?>', tryInit);
    } else {
        tryInit();
    }
})();
</script>
<?php endif; ?>

<?php if ($can_cancel && $gr['status'] === 'received'): ?>
<!-- ══════════════════════════════════════════════════════════════════════
     MODAL — CANCEL RECEIPT (alasan wajib)
     Alasannya disimpan permanen di kolom cancel_reason, bukan hanya lewat
     di activity log, supaya pembatalan penerimaan barang selalu bisa
     dipertanggungjawabkan saat rekonsiliasi dengan supplier.
     ══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="grCancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" id="grCancelForm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-x-circle"></i> <?= htmlspecialchars($LANG['gr_cancel_receipt']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars($LANG['cancel']) ?>"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="gr_cancel">

                    <div class="alert alert-warning small py-2">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($LANG['gr_confirm_cancel']) ?>
                    </div>

                    <label class="form-label"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($LANG['gr_cancel_reason']) ?> <span class="text-danger">*</span></label>
                    <textarea name="cancel_reason" class="form-control" rows="3" required
                              maxlength="1000" placeholder="<?= htmlspecialchars($LANG['gr_cancel_reason_placeholder']) ?>"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['cancel']) ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-danger">
                        <i class="bi bi-x-circle"></i> <?= htmlspecialchars($LANG['yes']) ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($can_invoice): ?>
<!-- ══════════════════════════════════════════════════════════════════════
     MODAL — INPUT DATE (Create Purchase Invoice)
     Satu-satunya pintu penerbitan Purchase Invoice dari dokumen ini.
     Tanggal yang dipilih di sinilah yang tersimpan ke purchases.input_date
     dan tampil di kolom Input Date pada purchases_invoice.php.
     Yang dikirim HANYA csrf_token, action, dan invoice_input_date — baris
     item, qty, harga, dan status GR tidak pernah ikut terkirim, sehingga
     menerbitkan invoice tidak mungkin mengubah isi dokumen penerimaan.
     ══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="grInvoiceDateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" id="grInvoiceForm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-receipt"></i> <?= htmlspecialchars($LANG['gr_create_invoice']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars($LANG['cancel']) ?>"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="gr_make_invoice">

                    <div class="alert alert-warning small py-2">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($LANG['gr_confirm_invoice']) ?>
                    </div>

                    <div class="alert alert-light border small py-2">
                        <i class="bi bi-info-circle"></i>
                        <?= htmlspecialchars($gr['gr_number']) ?> &middot;
                        <?= htmlspecialchars(formatCurrency((float)$gr['total_amount'])) ?>
                    </div>

                    <!-- Input Date — default hari ini, ditampilkan DD-MM-YYYY.
                         Kotak yang terlihat readonly (dipilih lewat
                         daterangepicker mode singleDatePicker); nilai yang
                         benar-benar dikirim ke server ada di input tersembunyi
                         invoice_input_date (format Y-m-d), karena
                         gr_createInvoiceFromGr() memvalidasinya dengan
                         DateTime::createFromFormat 'Y-m-d'. Pola ini sama
                         persis dengan modal Record Payment di atas. -->
                    <div class="mb-0">
                        <label class="form-label" for="grInvoiceDateInput"><i class="bi bi-calendar-event me-1"></i><?= htmlspecialchars($LANG['input_date']) ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="grInvoiceDateInput"
                               value="<?= date('d-m-Y') ?>" placeholder="DD-MM-YYYY"
                               readonly autocomplete="off">
                        <input type="hidden" name="invoice_input_date" id="grInvoiceDateHidden" value="<?= date('Y-m-d') ?>">
                        <div class="form-text small"><?= htmlspecialchars($LANG['gr_invoice_date_hint']) ?></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> <?= htmlspecialchars($LANG['cancel']) ?>
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check2-circle"></i> <?= htmlspecialchars($LANG['save']) ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!$can_pay): ?>
<!-- Daterangepicker CSS — dimuat lewat url(), bukan path hardcode.
     Dibungkus if (!$can_pay) sebagai pengaman ganda terhadap CSS ganda:
     $can_pay mensyaratkan $is_unpaid == true sedangkan $can_invoice
     mensyaratkan $is_unpaid == false, jadi keduanya memang tidak pernah
     aktif bersamaan. -->
<link rel="stylesheet" href="<?= url('assets/vendor/daterangepicker/daterangepicker.css') ?>">
<?php endif; ?>

<script>
/* ══════════════════════════════════════════════════════════════════════════
   INPUT DATE PICKER — modal Create Purchase Invoice
   Kotak yang terlihat (#grInvoiceDateInput) readonly, format tampilan
   DD-MM-YYYY, default hari ini. Nilai yang benar-benar terkirim ke server
   ada di input tersembunyi #grInvoiceDateHidden (format Y-m-d).
   maxDate dikunci ke hari ini — tanggal di masa depan tidak bisa dipilih,
   dan batas yang sama ditegakkan ulang di server.
   moment.js & daterangepicker dimuat lazy dari assets/vendor lokal, sama
   seperti pola yang sudah dipakai di modal Record Payment.
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    function loadScript(src, onload) {
        var s = document.createElement('script');
        s.src = src;
        s.onload = onload || null;
        document.head.appendChild(s);
    }

    function initGrInvoiceDatePicker() {
        var input  = document.getElementById('grInvoiceDateInput');
        var hidden = document.getElementById('grInvoiceDateHidden');
        if (!input || !hidden) return;
        if (typeof jQuery === 'undefined' || typeof moment === 'undefined' || typeof jQuery.fn.daterangepicker === 'undefined') {
            return;
        }

        jQuery(input).daterangepicker({
            singleDatePicker: true,
            autoUpdateInput: false,
            showDropdowns: true,
            startDate: moment(hidden.value, 'YYYY-MM-DD'),
            maxDate: moment(),
            locale: {
                format: 'DD-MM-YYYY',
                cancelLabel: '<?= htmlspecialchars($LANG['cancel']) ?>'
            }
        });

        jQuery(input).on('apply.daterangepicker', function (ev, picker) {
            input.value  = picker.startDate.format('DD-MM-YYYY');
            hidden.value = picker.startDate.format('YYYY-MM-DD');
        });
    }

    function tryInit() {
        if (typeof jQuery !== 'undefined' && typeof moment !== 'undefined') {
            if (typeof jQuery.fn.daterangepicker === 'undefined') {
                loadScript('<?= url('assets/vendor/daterangepicker/daterangepicker.min.js') ?>', initGrInvoiceDatePicker);
            } else {
                initGrInvoiceDatePicker();
            }
        } else {
            setTimeout(tryInit, 100);
        }
    }

    if (typeof moment === 'undefined') {
        loadScript('<?= url('assets/vendor/moment/moment.min.js') ?>', tryInit);
    } else {
        tryInit();
    }
})();
</script>
<?php endif; ?>

<script>
document.querySelectorAll('.gr-confirm-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        Swal.fire({
            text: form.dataset.confirmMsg,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<?= htmlspecialchars($LANG['yes']) ?>',
            cancelButtonText: '<?= htmlspecialchars($LANG['cancel']) ?>'
        }).then(function (result) {
            if (result.isConfirmed) form.submit();
        });
    });
});

/* Cegah klik ganda pada ketiga modal: sekali submit, tombolnya dikunci
   supaya satu pembayaran tidak pernah tercatat dua kali dan satu Purchase
   Invoice tidak pernah terbit dua kali. */
['grPaymentForm', 'grCancelForm', 'grInvoiceForm'].forEach(function (id) {
    var f = document.getElementById(id);
    if (!f) return;
    f.addEventListener('submit', function () {
        var b = f.querySelector('button[type="submit"]');
        if (b) { b.disabled = true; b.classList.add('disabled'); }
    });
});
</script>

<?php
/* ══════════════════════════════════════════════════════════════════════════
   PURCHASE INVOICE YANG BARU TERBIT → DITAMPILKAN SEBAGAI MODAL
   Id-nya dititipkan sekali pakai oleh handler POST di atas. Isi session
   TIDAK PERNAH dipercaya begitu saja — sebelum modal dirender, id itu harus
   lolos tiga syarat:
     1. benar-benar milik Goods Receipt ini (dicocokkan ke $invoices yang
        dibaca dari purchases.gr_id, bukan dari input apa pun),
     2. user memang punya izin baca Purchase Invoice (canReadPurchase(),
        permission existing dari database — tidak ada permission baru),
     3. session langsung dihapus sehingga sekali tampil saja; refresh
        halaman tidak memunculkan modal lagi.
   ══════════════════════════════════════════════════════════════════════════ */
$new_invoice_id = 0;
if (!empty($_SESSION['gr_invoice_modal_id'])) {
    $__cand = (int)$_SESSION['gr_invoice_modal_id'];
    unset($_SESSION['gr_invoice_modal_id']);
    if ($__cand > 0 && canReadPurchase()) {
        foreach ($invoices as $__inv) {
            if ((int)$__inv['id'] === $__cand) { $new_invoice_id = $__cand; break; }
        }
    }
}
?>

<?php if ($new_invoice_id > 0): ?>
<!-- ══════════════════════════════════════════════════════════════════════
     MODAL — PURCHASE INVOICE VIEW (AJAX)
     Isinya diambil dari purchase_view.php?modal=1 yang MEMANG SUDAH punya
     mode fragment (lihat purchase_view.php baris 6-11). Halaman tersebut
     tidak diubah sama sekali. Struktur modal ini mengikuti modal yang sudah
     dipakai di purchases_invoice.php supaya tampilannya seragam.
     ══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="purchaseViewModal" tabindex="-1" aria-labelledby="purchaseViewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="purchaseViewModalLabel">
                    <i class="bi bi-receipt"></i> <?= htmlspecialchars($LANG['view'] . ' ' . $LANG['purchase']) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars($LANG['close']) ?>"></button>
            </div>
            <div class="modal-body p-0">
                <div id="pvLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden"><?= htmlspecialchars($LANG['loading']) ?></span>
                    </div>
                    <p class="mt-2 text-muted"><?= htmlspecialchars($LANG['loading']) ?></p>
                </div>
                <div id="pvContent"></div>
            </div>
            <div class="modal-footer">
                <?php if (canExportPurchase()): ?>
                <button type="button" class="btn btn-success btn-sm" id="pvModalBtnExcel">
                    <i class="bi bi-file-earmark-excel"></i> <?= htmlspecialchars($LANG['export_excel']) ?>
                </button>
                <?php endif; ?>
                <!-- btn-sm ditambahkan agar tingginya sama persis dengan
                     tombol Export Excel di sebelahnya. -->
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> <?= htmlspecialchars($LANG['close']) ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php
/* $extra_js di-echo footer.php SETELAH jQuery, Bootstrap, dan SweetAlert2
   selesai dimuat, sehingga bootstrap.Modal dan Swal dijamin sudah ada.
   Seluruh teks di-resolve di PHP dulu (tidak ada PHP inline di dalam blok
   JS) supaya tidak ada key $LANG yang menabrak tengah-tengah script. */
$pv_js = [
    'view_url'        => addslashes(url('purchases/purchase_view.php')),
    'invoice_id'      => (int)$new_invoice_id,
    'load_error'      => addslashes($LANG['load_error']),
    'confirm_export'  => addslashes($LANG['confirm_export']),
    'export_to'       => addslashes($LANG['export_to']),
    'yes_export'      => addslashes($LANG['yes_export']),
    'cancel'          => addslashes($LANG['cancel']),
    'exporting'       => addslashes($LANG['exporting']),
    'please_wait'     => addslashes($LANG['please_wait']),
    'export_success'  => addslashes($LANG['export_success']),
    'file_downloaded' => addslashes($LANG['file_downloaded']),
    'purchase'        => addslashes($LANG['purchase']),
    'details'         => addslashes($LANG['details']),
];

$extra_js = '
<script>
/* ══════════════════════════════════════════════════════════════════════════
   PURCHASE INVOICE MODAL — dibuka otomatis setelah invoice berhasil terbit
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    var modalEl = document.getElementById("purchaseViewModal");
    if (!modalEl || typeof bootstrap === "undefined") return;

    var loadingEl = document.getElementById("pvLoading");
    var contentEl = document.getElementById("pvContent");
    var invoiceId = ' . $pv_js['invoice_id'] . ';
    var viewUrl   = "' . $pv_js['view_url'] . '";

    /* Teks SweetAlert untuk tombol export DI DALAM fragment purchase_view.
       Tanpa injeksi ini fragment akan jatuh ke teks bawaan bahasa Inggris —
       multi-language-nya bocor. */
    window._pvLang = {
        confirmExport: "' . $pv_js['confirm_export']  . '",
        exportTo:      "' . $pv_js['export_to']       . '",
        yesExport:     "' . $pv_js['yes_export']      . '",
        cancel:        "' . $pv_js['cancel']          . '",
        exporting:     "' . $pv_js['exporting']       . '",
        pleaseWait:    "' . $pv_js['please_wait']     . '",
        expSuccess:    "' . $pv_js['export_success']  . '",
        fileDl:        "' . $pv_js['file_downloaded'] . '",
        purchase:      "' . $pv_js['purchase']        . '",
        details:       "' . $pv_js['details']         . '"
    };

    var pvModal = new bootstrap.Modal(modalEl);
    pvModal.show();

    fetch(viewUrl + "?id=" + encodeURIComponent(invoiceId) + "&modal=1", {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin"
    })
    .then(function (r) {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.text();
    })
    .then(function (html) {
        if (loadingEl) loadingEl.style.display = "none";
        contentEl.innerHTML = html;
        /* <script> di dalam innerHTML tidak dieksekusi browser — harus
           dibuat ulang. Pola yang sama dipakai purchases_invoice.php. */
        contentEl.querySelectorAll("script").forEach(function (s) {
            var ns = document.createElement("script");
            if (s.src) { ns.src = s.src; } else { ns.textContent = s.textContent; }
            document.head.appendChild(ns);
        });
    })
    .catch(function () {
        if (loadingEl) loadingEl.style.display = "none";
        contentEl.textContent = "";
        var box = document.createElement("div");
        box.className = "alert alert-danger m-3";
        /* .textContent, bukan .innerHTML — tanda baca seperti \' " & tampil
           apa adanya, tidak berubah jadi &amp; dan sejenisnya. */
        box.textContent = "' . $pv_js['load_error'] . '";
        contentEl.appendChild(box);
    });

    modalEl.addEventListener("hidden.bs.modal", function () {
        contentEl.innerHTML = "";
        if (loadingEl) loadingEl.style.display = "block";
    });

    /* Tombol Export PDF beserta wiring-nya SUDAH DIHAPUS; tinggal Excel. */
    var btnExcel = document.getElementById("pvModalBtnExcel");
    if (btnExcel) {
        btnExcel.addEventListener("click", function () {
            if (typeof window.pvModalExport === "function") window.pvModalExport("excel");
        });
    }
})();
</script>
';
?>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>