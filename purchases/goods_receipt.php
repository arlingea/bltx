<?php
// ═══════════════════════════════════════════════════════════════════════════
//  GOODS RECEIPT — LIST
//
//  Dual-mode file (pola sama persis dengan purchases/returns.php):
//   • Standalone : /purchases/goods_receipt.php          (halaman penuh)
//   • Partial    : di-include oleh /purchases/pr_dahsboard_gate.php saat tab=goods_receipt
//
//  Halaman ini HANYA MEMBACA. Tidak ada perubahan status/dokumen di sini.
// ═══════════════════════════════════════════════════════════════════════════
$__gr_included_as_partial = defined('APP_NAME') && isset($LANG, $conn);

if (!$__gr_included_as_partial) {
    require_once '../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';
    require_once __DIR__ . '/../includes/purchase_orders_function.php';
    require_once __DIR__ . '/../includes/goods_receipt_function.php';
    $page_title = $LANG['goods_receipt'];
    include '../includes/header.php';
} else {
    require_once __DIR__ . '/../includes/global_permission.php';
    require_once __DIR__ . '/../includes/purchase_orders_function.php';
    require_once __DIR__ . '/../includes/goods_receipt_function.php';
}

requireGoodsReceiptAccess();
// GERBANG PURCHASE — modul 'purchase' harus aktif. Tanpa baris ini,
// mematikan gerbang tidak menutup halaman ini bila diketik langsung
// lewat URL. Superadmin selalu lolos di dalam _dbCan().
requirePurchaseModule('goods_receipt');

// ── Migrasi belum dijalankan? Tampilkan banner, jangan fatal error ─────────
$gr_ready = gr_tablesExist($conn);

// ── Filter (GET, semua di-bind lewat prepared statement di helper) ─────────
$g_search   = isset($_GET['gr_search']) ? trim((string)$_GET['gr_search']) : '';
$g_status   = isset($_GET['gr_status']) ? trim((string)$_GET['gr_status']) : '';
if (!in_array($g_status, ['draft', 'received', 'cancelled'], true)) $g_status = '';
$g_supplier = isset($_GET['gr_supplier']) ? (int)$_GET['gr_supplier'] : 0;
// Filter Payment Status. Whitelist ketat: apa pun di luar dua nilai ini
// diabaikan, sehingga nilai dari URL tidak pernah masuk query mentah-mentah.
$g_paystat  = isset($_GET['gr_paystat']) ? trim((string)$_GET['gr_paystat']) : '';
if (!in_array($g_paystat, ['paid', 'unpaid'], true)) $g_paystat = '';
$gr_paystat_ready = gr_hasPaymentStatusColumn($conn);
if (!$gr_paystat_ready) $g_paystat = '';
$g_from     = isset($_GET['gr_from']) ? trim((string)$_GET['gr_from']) : '';
$g_to       = isset($_GET['gr_to'])   ? trim((string)$_GET['gr_to'])   : '';
if ($g_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $g_from)) $g_from = '';
if ($g_to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $g_to))   $g_to   = '';

$gr_filters = [
    'search'      => $g_search,
    'status'      => $g_status,
    'supplier_id' => $g_supplier,
    'from_date'   => $g_from,
    'to_date'     => $g_to,
    // Dipakai gr_buildListWhere(); flag di bawah mencegah query menyebut
    // kolom payment_status sebelum migrasinya dijalankan.
    'payment_status'       => $g_paystat,
    '__has_payment_status' => $gr_paystat_ready,
];

// Seluruh baris dirender sekaligus lalu dipaginasi oleh DataTables di sisi
// browser, persis perilaku daftar Return. Filter Report Data Scope
// (All Data / Own Data Only) tetap diterapkan di dalam gr_getList().
$g_per   = (int) getSetting('pagination', 10);
if ($g_per < 1) $g_per = 10;
$g_page  = isset($_GET['gr_page']) ? max(1, (int)$_GET['gr_page']) : 1;
$g_total = $gr_ready ? gr_countList($conn, $gr_filters) : 0;
$g_pages = $g_total > 0 ? (int)ceil($g_total / $g_per) : 1;
if ($g_page > $g_pages) $g_page = $g_pages;
$gr_rows = ($gr_ready && $g_total > 0) ? gr_getList($conn, $gr_filters, $g_total, 0) : [];

// Supplier dropdown (kecil, aman untuk di-render langsung)
$gr_suppliers = [];
$__sup = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC");
if ($__sup) { while ($__s = $__sup->fetch_assoc()) $gr_suppliers[] = $__s; }

// Basis URL untuk paginasi/filter — berbeda antara mode partial dan standalone
$gr_base_url = $__gr_included_as_partial
    ? url('purchases/pr_dahsboard_gate.php?tab=goods_receipt')
    : url('purchases/goods_receipt.php');
$gr_form_action = $__gr_included_as_partial
    ? url('purchases/pr_dahsboard_gate.php')
    : url('purchases/goods_receipt.php');

/** Bangun URL paginasi dengan mempertahankan seluruh filter aktif. */
if (!function_exists('gr_pageUrl')) {
    function gr_pageUrl(string $base, bool $partial, array $f, int $page): string {
        $q = [
            'gr_page'     => $page,
            'gr_search'   => $f['search'],
            'gr_status'   => $f['status'],
            'gr_supplier' => $f['supplier_id'] ?: '',
            'gr_from'     => $f['from_date'],
            'gr_to'       => $f['to_date'],
            'gr_paystat'  => $f['payment_status'] ?? '',
        ];
        $sep = (strpos($base, '?') === false) ? '?' : '&';
        return $base . $sep . http_build_query($q);
    }
}
?>

<style>
/* ══════════════════════════════════════════════════════════════════════
   RESPONSIVE — TABLET & MOBILE (blok TAMBAHAN, hanya @media query)
   Aturan lain pada halaman ini tidak diubah sama sekali.
   ══════════════════════════════════════════════════════════════════════ */
@media (max-width: 767.98px) {
    /* Header card: judul dan tombol menumpuk, tidak saling desak */
    .card-header { flex-wrap: wrap; gap: .5rem; }
    .card-header .card-tools { width: 100%; display: flex; flex-wrap: wrap; gap: .35rem; }
    .card-header .card-tools .btn { flex: 1 1 auto; }

    /* Tabel digulir horizontal dengan momentum di perangkat sentuh */
    .table-responsive { -webkit-overflow-scrolling: touch; }
    #dataTable { font-size: .85rem; }
    #dataTable th, #dataTable td { white-space: nowrap; }

    /* Kontrol DataTables (search / length / paging) melebar penuh */
    .dataTables_wrapper .row > div { width: 100%; }
    .dataTables_filter input { width: 100% !important; }
}
@media (max-width: 575.98px) {
    #dataTable { font-size: .8rem; }
    .btn-group-sm > .btn { min-width: 40px; min-height: 36px; }
}
</style>

<?php
/* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4 — menggantikan
   blok HTML+JS yang isinya identik dengan berkas lain. Kunci sesinya
   TIDAK diubah, hanya cara merendernya. */
bltx_render_flash('gr');
?>

<?php if (!$gr_ready): ?>
<div class="alert alert-warning">
    <i class="bi bi-database-exclamation"></i> <?= htmlspecialchars($LANG['gr_tables_missing']) ?>
</div>
<?php endif; ?>

<div class="card">

        <div class="card-header">
            <div class="card-title">
            <i class="bi bi-box-seam"></i> <?= htmlspecialchars($LANG['gr_list']) ?></div>
        <div class="card-tools">
            <?php if ($gr_paystat_ready): ?>
            <!-- ══ FILTER PAYMENT STATUS ═════════════════════════════════════
                 Difilter di SERVER (bukan di DataTables) supaya hitungan baris
                 dan paginasi tetap akurat pada data besar, dan supaya
                 kombinasi Received + Unpaid benar-benar menjadi daftar hutang
                 usaha — bukan sekadar penyaringan tampilan halaman aktif.
                 Filter lain yang sudah ada diteruskan lewat hidden input agar
                 tidak hilang saat filter ini diubah.
                 ══════════════════════════════════════════════════════════ -->
            <form method="GET" action="<?= htmlspecialchars($gr_form_action) ?>" class="d-inline-block me-2" id="grPayStatForm">
                <?php if ($__gr_included_as_partial): ?>
                <input type="hidden" name="tab" value="goods_receipt">
                <?php endif; ?>
                <input type="hidden" name="gr_search"   value="<?= htmlspecialchars($g_search) ?>">
                <input type="hidden" name="gr_status"   value="<?= htmlspecialchars($g_status) ?>">
                <input type="hidden" name="gr_supplier" value="<?= $g_supplier > 0 ? (int)$g_supplier : '' ?>">
                <input type="hidden" name="gr_from"     value="<?= htmlspecialchars($g_from) ?>">
                <input type="hidden" name="gr_to"       value="<?= htmlspecialchars($g_to) ?>">
                <select name="gr_paystat" class="form-select form-select-sm" onchange="this.form.submit()"
                        title="<?= htmlspecialchars($LANG['gr_payment_status']) ?>">
                    <option value=""><?= htmlspecialchars($LANG['gr_payment_filter_all']) ?></option>
                    <option value="paid"   <?= $g_paystat === 'paid'   ? 'selected' : '' ?>><?= htmlspecialchars($LANG['gr_payment_paid']) ?></option>
                    <option value="unpaid" <?= $g_paystat === 'unpaid' ? 'selected' : '' ?>><?= htmlspecialchars($LANG['gr_payment_unpaid']) ?></option>
                </select>
            </form>
            <?php endif; ?>
            <?php if (!canViewAllGoodsReceipt()): ?>
            <span class="badge text-bg-light border text-muted me-2" title="<?= htmlspecialchars($LANG['perm_scope_own_hint']) ?>">
                <i class="bi bi-person-lock"></i> <?= htmlspecialchars($LANG['perm_dreport_scope_own']) ?>
            </span>
            <?php endif; ?>
            <?php if ($gr_ready && canCreateGoodsReceipt()): ?>
            <a href="<?= url('purchases/goods_receipt_add.php') ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle"></i> <?= htmlspecialchars($LANG['add']) ?>
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
                        <th><?= htmlspecialchars($LANG['gr_number']) ?></th>
                        <th><?= htmlspecialchars($LANG['po_number']) ?></th>
                        <th><?= htmlspecialchars($LANG['supplier']) ?></th>
                        <th><?= htmlspecialchars($LANG['gr_receipt_date']) ?></th>
                        <th><?= htmlspecialchars($LANG['total']) ?></th>
                        <th><?= htmlspecialchars($LANG['status']) ?></th>
                        <?php if ($gr_paystat_ready): ?>
                        <th><?= htmlspecialchars($LANG['gr_payment_status']) ?></th>
                        <?php endif; ?>
                        <th><?= htmlspecialchars($LANG['created_by']) ?></th>
                        <th><?= htmlspecialchars($LANG['actions']) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($gr_rows as $g): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($g['gr_number']) ?></strong>
                            <?php if (!empty($g['delivery_note_number'])): ?>
                            <br><small class="text-muted"><?= htmlspecialchars($g['delivery_note_number']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($g['po_id'])): ?>
                                <a href="<?= url('purchases/po_view.php?id=' . (int)$g['po_id']) ?>">
                                    <?= htmlspecialchars($g['po_number'] ?: ('#' . (int)$g['po_id'])) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted"><?= htmlspecialchars($LANG['gr_no_po']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($g['supplier_name'] ?: '—') ?></td>
                        <?php
                        /* data-order = kunci urut yang dibaca DataTables.
                           Tampilannya tetap d/m/Y, tetapi pengurutannya memakai
                           Y-m-d + id (nol di depan) sehingga:
                             - urutannya kronologis, bukan alfabetis. Tanpa ini
                               "02/01/2026" dianggap lebih besar dari
                               "01/12/2025" karena dibandingkan sebagai teks;
                             - GR pada tanggal yang sama tetap urut sesuai
                               urutan pembuatan, sama persis dengan
                               ORDER BY receipt_date DESC, id DESC di server. */
                        $g_sort_key = date('Y-m-d', strtotime($g['receipt_date']))
                                    . ' ' . str_pad((string)(int)$g['id'], 12, '0', STR_PAD_LEFT);
                        ?>
                        <td data-order="<?= htmlspecialchars($g_sort_key) ?>"><?= htmlspecialchars(date('d/m/Y', strtotime($g['receipt_date']))) ?></td>
                        <td><?= htmlspecialchars(formatCurrency((float)$g['total_amount'])) ?></td>
                        <td>
                            <span class="badge bg-<?= gr_statusColor((string)$g['status']) ?>">
                                <?= htmlspecialchars(gr_statusLabel((string)$g['status'])) ?>
                            </span>
                        </td>
                        <?php if ($gr_paystat_ready): ?>
                        <td>
                            <?php if ($g['status'] === 'cancelled'): ?>
                                <span class="text-muted">—</span>
                            <?php else: $__ps = gr_paymentStatus($g); ?>
                                <span class="badge bg-<?= gr_paymentStatusColor($__ps) ?>">
                                    <?= htmlspecialchars(gr_paymentStatusLabel($__ps)) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($g['created_by_name']) ?></td>
                        <td>
                            <div class="btn btn-sm-group btn-group-sm">
                                <?php if (canReadGoodsReceipt()): ?>
                                <a href="<?= url('purchases/goods_receipt_view.php?id=' . (int)$g['id']) ?>"
                                   class="btn btn-sm btn-info" title="<?= htmlspecialchars($LANG['view']) ?>">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ($g['status'] !== 'cancelled' && canUpdateGoodsReceipt()): ?>
                                <a href="<?= url('purchases/goods_receipt_edit.php?id=' . (int)$g['id']) ?>"
                                   class="btn btn-sm btn-warning" title="<?= htmlspecialchars($LANG['edit']) ?>">
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

<script>
/* ── Urutan awal tabel: DATA TERBARU DI PALING ATAS ───────────────────────
   gr_getList() sudah mengirim baris dengan ORDER BY receipt_date DESC, id DESC,
   TETAPI DataTables meng-init dengan `ordering: true` tanpa opsi `order`,
   sehingga ia memakai bawaannya sendiri: kolom ke-0 (GR Number) ASCENDING —
   dan urutan dari server tertimpa.

   Perbaikannya dijaga agar TIDAK menyentuh includes/footer.php, karena init
   di sana dipakai bersama oleh SELURUH tabel di sistem. Caranya: mendaftarkan
   listener `init.dt` LEBIH DULU (skrip ini berada sebelum footer di halaman,
   jadi listener-nya terdaftar lebih dahulu), lalu mengurutkan ulang tabel ini
   saja begitu DataTables selesai meng-init-nya.

   Kolom ke-3 = Receipt Date. Indeksnya tetap 3 baik saat kolom Payment Status
   tampil maupun tidak, karena kolom itu berada SESUDAHNYA. */
document.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery || !$.fn.DataTable) return;
    $(document).on('init.dt', function (e, settings) {
        var tbl = settings.nTable;
        if (!tbl || tbl.id !== 'dataTable') return;
        $(tbl).DataTable().order([[3, 'desc']]).draw();
    });
});
</script>

<?php if (!$__gr_included_as_partial) include '../includes/footer.php'; ?>