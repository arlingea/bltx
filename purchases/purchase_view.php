<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/purchases_function.php';
// BARU — dipakai oleh blok Traceability di bawah. Ketiganya pustaka fungsi
// murni (semua deklarasinya berpenjaga function_exists) dan mengikuti pola
// require yang sudah dipakai po_view.php serta goods_receipt_view.php.
require_once __DIR__ . '/../includes/purchase_orders_function.php';
require_once __DIR__ . '/../includes/goods_receipt_function.php';
require_once __DIR__ . '/../includes/purchase_returns_function.php';

/* ═══════════════════════════════════════════════════════════════════════════
   TRACEABILITY  (BLOK BARU)

   Seluruh fitur penelusuran dokumen ditulis DI BERKAS INI SAJA. Tidak ada
   satu pun fungsi di includes/ yang diubah — semuanya DIPAKAI ULANG:
     po_getOne(), gr_getOne(), gr_getByPo(), gr_getInvoicesByGr(),
     gr_getInvoicesByPo(), pret_getByGr(), gr_statusColor/Label(),
     pret_statusColor/Label(), gr_paymentStatus*(), pf_currencyCell().

   Kolom yang dipakai (po_id, gr_id, purchase_id) SUDAH ADA dan sudah
   terindeks di database — tidak ada kolom baru.

   Hasilnya dirender lewat SATU fungsi yang dipanggil dua kali: sekali untuk
   mode modal, sekali untuk mode halaman penuh. Jadi kalau nanti tampilannya
   diubah, cukup satu tempat.
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Kumpulkan seluruh data penelusuran untuk satu invoice.
 * Hanya MEMBACA — tidak ada satu pun operasi tulis di sini.
 */
if (!function_exists('pv_collectTraceability')) {
    function pv_collectTraceability(mysqli $conn, int $purchaseId): array {
        $out = [
            'po_id'            => 0,
            'gr_id'            => 0,
            'po'               => null,
            'gr'               => null,
            'gr_list'          => [],
            'invoice_list'     => [],
            'return_list'      => [],
            'gr_paystat_ready' => false,
        ];
        if ($purchaseId <= 0) return $out;

        // ── 1. Sumber invoice: po_id & gr_id ──────────────────────────────
        // gr_id baru ada setelah migrasi modul Goods Receipt dijalankan,
        // jadi keberadaannya diperiksa dulu supaya instalasi lama tidak
        // menghasilkan SQL error.
        $hasGrCol = function_exists('gr_purchasesHasGrColumn')
                    ? gr_purchasesHasGrColumn($conn)
                    : false;

        $sql  = $hasGrCol
              ? "SELECT po_id, gr_id FROM purchases WHERE id = ? LIMIT 1"
              : "SELECT po_id, NULL AS gr_id FROM purchases WHERE id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $purchaseId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $out['po_id'] = (int)($row['po_id'] ?? 0);
                $out['gr_id'] = (int)($row['gr_id'] ?? 0);
            }
        }

        // ── 2. Header dokumen sumber ──────────────────────────────────────
        if ($out['gr_id'] > 0 && function_exists('gr_getOne')) {
            $out['gr'] = gr_getOne($conn, $out['gr_id']);
        }
        if ($out['po_id'] > 0 && function_exists('po_getOne')) {
            $out['po'] = po_getOne($conn, $out['po_id']);
        }
        // Sebagian invoice lama hanya menyimpan gr_id, sementara PO-nya
        // tercatat di GR. Rantainya disambung dari sana supaya tetap utuh.
        if (!$out['po'] && $out['gr'] && !empty($out['gr']['po_id']) && function_exists('po_getOne')) {
            $out['po_id'] = (int)$out['gr']['po_id'];
            $out['po']    = po_getOne($conn, $out['po_id']);
        }

        // ── 3. Riwayat Goods Receipt ──────────────────────────────────────
        if ($out['po_id'] > 0 && function_exists('gr_getByPo')) {
            $out['gr_list'] = gr_getByPo($conn, $out['po_id']);
        }
        // Tanpa PO, GR sumbernya sendiri yang ditampilkan agar riwayat tidak
        // kosong padahal dokumennya ada.
        if (!$out['gr_list'] && $out['gr']) {
            $out['gr_list'] = [[
                'id'             => (int)$out['gr']['id'],
                'gr_number'      => (string)$out['gr']['gr_number'],
                'receipt_date'   => (string)$out['gr']['receipt_date'],
                'status'         => (string)$out['gr']['status'],
                'total_amount'   => $out['gr']['total_amount'] ?? 0,
                'created_by_name'=> (string)($out['gr']['created_by_name'] ?? ''),
                'payment_status' => (string)($out['gr']['payment_status'] ?? 'paid'),
            ]];
        }

        // ── 4. Invoice lain dari sumber yang sama ─────────────────────────
        // Berguna untuk mendeteksi invoice ganda atas satu penerimaan barang.
        if ($out['gr_id'] > 0 && function_exists('gr_getInvoicesByGr')) {
            $out['invoice_list'] = gr_getInvoicesByGr($conn, $out['gr_id']);
        } elseif ($out['po_id'] > 0 && function_exists('gr_getInvoicesByPo')) {
            $out['invoice_list'] = gr_getInvoicesByPo($conn, $out['po_id']);
        }

        // ── 5. Riwayat Purchase Return ────────────────────────────────────
        // Dua jalur, lalu di-dedup berdasarkan id:
        //   a. retur yang menunjuk LANGSUNG ke invoice ini (purchase_id)
        //   b. retur yang menunjuk ke GR yang sama
        if (function_exists('pret_tablesExist') && pret_tablesExist($conn)) {
            $bucket = [];
            $stmtR  = $conn->prepare(
                "SELECT id, return_number, return_date, status, total_amount, created_by_name
                   FROM purchase_returns
                  WHERE purchase_id = ?
                  ORDER BY return_date ASC, id ASC"
            );
            if ($stmtR) {
                $stmtR->bind_param('i', $purchaseId);
                $stmtR->execute();
                $resR = $stmtR->get_result();
                while ($r = $resR->fetch_assoc()) $bucket[(int)$r['id']] = $r;
                $stmtR->close();
            }
            if ($out['gr_id'] > 0 && function_exists('pret_getByGr')) {
                foreach (pret_getByGr($conn, $out['gr_id']) as $r) {
                    $bucket[(int)$r['id']] = $r;
                }
            }
            $out['return_list'] = array_values($bucket);
        }

        $out['gr_paystat_ready'] = function_exists('gr_hasPaymentStatusColumn')
                                   && gr_hasPaymentStatusColumn($conn);

        return $out;
    }
}

/** Label + warna status Purchase Order, memakai key bahasa yang sudah ada. */
if (!function_exists('pv_poStatusBadge')) {
    function pv_poStatusBadge(string $status): array {
        global $LANG;
        $color = [
            'draft' => 'secondary', 'sent' => 'info', 'approved' => 'primary',
            'partial' => 'warning', 'received' => 'success', 'cancelled' => 'danger',
        ];
        $label = [
            'draft'     => $LANG['po_status_draft'],
            'sent'      => $LANG['po_status_sent'],
            'approved'  => $LANG['po_status_approved'],
            'partial'   => $LANG['po_status_partial'],
            'received'  => $LANG['po_status_received'],
            'cancelled' => $LANG['po_status_cancelled'],
        ];
        return [$color[$status] ?? 'secondary', $label[$status] ?? $status];
    }
}

/**
 * Render kartu Traceability + Audit Trail.
 * Dipanggil dua kali (mode modal & mode halaman penuh) dengan data yang sama,
 * sehingga isinya dijamin tidak pernah berbeda antara keduanya.
 */
if (!function_exists('pv_renderTraceability')) {
    function pv_renderTraceability(array $t, int $purchaseId, array $L): string {
        // Hak akses memakai helper yang SUDAH ADA — tidak ada permission baru
        // dan tidak ada permission ganda.
        $canPo  = function_exists('canReadPurchaseOrder')  ? canReadPurchaseOrder()  : false;
        $canGr  = function_exists('canReadGoodsReceipt')   ? canReadGoodsReceipt()   : false;
        $canRet = function_exists('canReadPurchaseReturn') ? canReadPurchaseReturn() : false;

        $h  = static function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $d  = static function ($v) { return $v ? date('d/m/Y', strtotime((string)$v)) : '-'; };

        ob_start();
        ?>
        <div class="card pv-trace-card">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-diagram-3"></i>&nbsp;<?php echo $h($L['gr_traceability']); ?></div>
            </div>
            <div class="card-body">

                <!-- ── Penanda invoice tanpa dokumen sumber ─────────────
                     Strip alur "PO → GR → Invoice" SUDAH DIHAPUS sesuai
                     permintaan, karena informasinya sudah terwakili oleh
                     tiga daftar riwayat di bawah.

                     Yang sengaja disisakan hanya penanda untuk invoice yang
                     memang diinput langsung tanpa PO/GR. Tanpa penanda ini,
                     invoice semacam itu hanya akan menampilkan tiga baris
                     "No data available" berturut-turut, yang mudah salah
                     dibaca sebagai data gagal dimuat. -->
                <?php if (!$t['po'] && !$t['gr']): ?>
                    <p class="mb-3">
                        <span class="badge bg-secondary">
                            <i class="bi bi-pencil-square"></i> <?php echo $h($L['pv_direct_entry']); ?>
                        </span>
                    </p>
                <?php endif; ?>

                <div class="row g-3">

                    <!-- ── Riwayat Purchase Order ──────────────────────── -->
                    <?php if ($canPo): ?>
                    <div class="col-12">
                        <p class="small text-muted mb-1">
                            <i class="bi bi-file-earmark-text"></i> <?php echo $h($L['purchase_order']); ?>
                        </p>
                        <?php if (!$t['po']): ?>
                            <p class="small text-muted mb-0"><i class="bi bi-info-circle"></i> <?php echo $h($L['no_data']); ?></p>
                        <?php else: $b = pv_poStatusBadge((string)$t['po']['status']); ?>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span>
                                    <?php if ($canPo): ?>
                                        <a href="<?php echo url('purchases/po_view.php?id=' . (int)$t['po']['id']); ?>">
                                            <?php echo $h($t['po']['po_number']); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo $h($t['po']['po_number']); ?>
                                    <?php endif; ?>
                                    <br><small class="text-muted">
                                        <?php echo $h($d($t['po']['order_date'])); ?>
                                        &middot; <?php echo $h($t['po']['supplier_name'] ?: '-'); ?>
                                    </small>
                                </span>
                                <span class="badge bg-<?php echo $b[0]; ?>"><?php echo $h($b[1]); ?></span>
                            </li>
                        </ul>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- ── Riwayat Goods Receipt ───────────────────────── -->
                    <?php if ($canGr): ?>
                    <div class="col-12">
                        <p class="small text-muted mb-1">
                            <i class="bi bi-box-seam"></i> <?php echo $h($L['gr_history']); ?>
                        </p>
                        <?php if (!$t['gr_list']): ?>
                            <p class="small text-muted mb-0"><i class="bi bi-info-circle"></i> <?php echo $h($L['no_data']); ?></p>
                        <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($t['gr_list'] as $g):
                                $isSource = ((int)$g['id'] === (int)$t['gr_id']); ?>
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center<?php echo $isSource ? ' pv-trace-current' : ''; ?>">
                                <span>
                                    <a href="<?php echo url('purchases/goods_receipt_view.php?id=' . (int)$g['id']); ?>">
                                        <?php echo $h($g['gr_number']); ?>
                                    </a>
                                    <br><small class="text-muted">
                                        <?php echo $h($d($g['receipt_date'])); ?>
                                        &middot; <?php echo $h($g['created_by_name'] ?: '-'); ?>
                                    </small>
                                </span>
                                <span class="text-nowrap">
                                    <span class="badge bg-<?php echo $h(gr_statusColor((string)$g['status'])); ?>">
                                        <?php echo $h(gr_statusLabel((string)$g['status'])); ?>
                                    </span>
                                    <?php if ($t['gr_paystat_ready'] && $g['status'] !== 'cancelled'):
                                        $ps = gr_paymentStatus($g); ?>
                                        <span class="badge bg-<?php echo $h(gr_paymentStatusColor($ps)); ?>">
                                            <?php echo $h(gr_paymentStatusLabel($ps)); ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- ── Riwayat Purchase Return ─────────────────────── -->
                    <?php if ($canRet): ?>
                    <div class="col-12">
                        <p class="small text-muted mb-1">
                            <i class="bi bi-arrow-return-left"></i> <?php echo $h($L['gr_return_history']); ?>
                        </p>
                        <?php if (!$t['return_list']): ?>
                            <p class="small text-muted mb-0"><i class="bi bi-info-circle"></i> <?php echo $h($L['no_data']); ?></p>
                        <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($t['return_list'] as $rt): ?>
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span>
                                    <a href="<?php echo url('purchases/purchase_return_view.php?id=' . (int)$rt['id']); ?>">
                                        <?php echo $h($rt['return_number']); ?>
                                    </a>
                                    <br><small class="text-muted">
                                        <?php echo $h($d($rt['return_date'])); ?>
                                        &middot; <?php echo $h($rt['created_by_name'] ?: '-'); ?>
                                    </small>
                                </span>
                                <span class="badge bg-<?php echo $h(pret_statusColor((string)$rt['status'])); ?>">
                                    <?php echo $h(pret_statusLabel((string)$rt['status'])); ?>
                                </span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- ── Invoice lain dari sumber yang sama ──────────────── -->
                <?php
                $siblings = [];
                foreach ($t['invoice_list'] as $inv) {
                    if ((int)$inv['id'] !== (int)$purchaseId) $siblings[] = $inv;
                }
                if ($siblings): ?>
                <hr class="my-3">
                <p class="small text-muted mb-1">
                    <i class="bi bi-receipt"></i> <?php echo $h($L['gr_invoice_history']); ?>
                </p>
                <ul class="list-group list-group-flush">
                    <?php foreach ($siblings as $inv): ?>
                    <li class="list-group-item px-0">
                        <a href="<?php echo url('purchases/purchase_view.php?id=' . (int)$inv['id']); ?>">
                            <?php echo $h($L['invoice']); ?> #<?php echo (int)$inv['id']; ?>
                        </a>
                        <small class="text-muted">
                            &middot; <?php echo $h($d($inv['invoice_date'])); ?>
                            <?php if (!empty($inv['gr_number'])): ?>
                                &middot; <?php echo $h($inv['gr_number']); ?>
                            <?php endif; ?>
                        </small>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}

// ── Modal mode: return only inner HTML fragment (no layout) ─────────────────
$is_modal = (
    isset($_GET['modal']) && $_GET['modal'] === '1' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

// ── Access guard ────────────────────────────────────────────────────────────
if (!isLoggedIn()) {
    if ($is_modal) {
        http_response_code(403);
        echo '<div class="alert alert-danger m-3">' . htmlspecialchars($LANG['no_permission']) . '</div>';
        exit;
    }
    redirect(url('index.php'));
}

// GERBANG PURCHASE — modul 'purchase' harus aktif. Tanpa baris ini,
// mematikan gerbang tidak menutup halaman ini bila diketik langsung
// lewat URL. Superadmin selalu lolos di dalam _dbCan().
requirePurchaseModule('purchases');

if (!purchaseModuleAllowed('purchases')) {
    if ($is_modal) {
        http_response_code(403);
        echo '<div class="alert alert-danger m-3">' . htmlspecialchars($LANG['no_permission']) . '</div>';
        exit;
    }
    redirect(url('purchases/pr_dahsboard_gate.php'));
}

// ── Load data ────────────────────────────────────────────────────────────────
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$purchase = pf_getPurchaseData($conn, $id);

if (!$purchase) {
    if ($is_modal) {
        http_response_code(404);
        echo '<div class="alert alert-warning m-3">' . htmlspecialchars($LANG['no_data']) . '</div>';
        exit;
    }
    redirect(url('purchases/pr_dahsboard_gate.php'));
}

$items       = pf_getPurchaseItems($conn, $id);
$items_subtotal      = array_sum(array_column($items, 'total_price'));
$grand_total         = $items_subtotal; // raw sum of item totals (kept for backward compat)
$overall_discount    = (float)($purchase['overall_discount']       ?? 0);
$overall_price_rounding = (float)($purchase['overall_price_rounding'] ?? 0);
$purchase_tax        = (float)($purchase['tax']                    ?? 0);
$final_total         = $items_subtotal - $overall_discount + $overall_price_rounding + $purchase_tax;

// ── Traceability + Audit Trail ──────────────────────────────────────────────
// Dirender SEKALI di sini lalu dipakai oleh kedua mode (modal & halaman penuh)
// supaya isinya dijamin identik dan querynya tidak dijalankan dua kali.
$pv_trace      = pv_collectTraceability($conn, $id);
$pv_trace_html = pv_renderTraceability($pv_trace, $id, $LANG);

// ── For modal: output only the inner HTML then exit ──────────────────────────
if ($is_modal) {
    ?>
    <style>
    /* Currency cell split inside purchase_view modal */
    .pv-cur-cell {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
    }
    .pv-cur-cell .cur-sym {
        flex-shrink: 0;
        text-align: left;
        color: #6c757d;
        font-size: 0.85em;
    }
    .pv-cur-cell .cur-val {
        flex: 1;
        text-align: right;
        font-weight: 600;
    }
    /* Lightbox overlay — custom div, bukan Bootstrap modal (mencegah loading di parent modal) */
    #pvLightboxOverlay {
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0,0,0,0.88);
        z-index: 10060;
        align-items: center;
        justify-content: center;
        flex-direction: column;
    }
    #pvLightboxOverlay.pv-lb-active { display: flex; }
    #pvLightboxOverlay img {
        max-width: 88vw;
        max-height: 82vh;
        object-fit: contain;
        border-radius: .5rem;
        display: block;
        cursor: grab;
        user-select: none;
        touch-action: none;
    }
    #pvLightboxCloseBtn {
        margin-top: 1rem;
        background: rgba(255,255,255,0.18);
        border: 1px solid rgba(255,255,255,0.35);
        color: #fff;
        padding: .4rem 1.4rem;
        border-radius: .4rem;
        cursor: pointer;
        font-size: .9rem;
        display: flex;
        align-items: center;
        gap: .4rem;
    }
    #pvLightboxCloseBtn:hover { background: rgba(255,255,255,0.28); }
    /* Purchase Details table: label | : | value — colon aligned vertically, compact */
    .pv-detail-tbl tr td { padding: .2rem .4rem !important; vertical-align: middle; border: none; }
    .pv-detail-lbl { white-space: nowrap; font-weight: 600; padding-right: 0 !important; width: 1%; }
    .pv-detail-sep { width: 12px; text-align: center; padding-left: 2px !important; padding-right: 6px !important; font-weight: 600; color: #495057; }
    .pv-detail-val { word-break: break-word; }
    /* ── Traceability ───────────────────────────────────────────── */
    .pv-trace-current { background-color:rgba(13,110,253,.06); }
    /* ── Invoice Document: gambar rata tengah (atas-bawah & kiri-kanan) ──
       Kartu memakai h-100 agar tingginya rata dengan Purchase Details.
       Ruang sisa diisi dengan MEMOSISIKAN gambar di tengah, bukan dengan
       meregangkannya — max-width:100% + height:auto menjaga rasio asli
       sehingga gambar tidak pernah gepeng atau ketarik. */
    .pv-doc-body { display:flex; align-items:center; justify-content:center; }
    .pv-doc-body img { max-width:100%; height:auto; object-fit:contain; }
    </style>

    <div class="row g-0">
        <div class="col-md-8 p-3">

            <!-- ══ BARIS ATAS: Purchase Details + Invoice Document ═══════
                 Dibagi 50/50 lewat col-lg-6. Jarak antar kartu memakai
                 g-3 (1rem) — nilainya sama persis dengan mb-3/mt-3 yang
                 dipakai antar card lain, jadi jaraknya seragam.

                 col-lg-6 (bukan col-md-6): di bawah 992px kedua kartu
                 menumpuk vertikal, supaya tabel detail tidak terjepit saat
                 modal dilihat di tablet.

                 h-100 pada kedua kartu membuat tingginya rata — kartu yang
                 lebih pendek ikut memanjang mengikuti yang lebih tinggi. -->
            <div class="row g-3 mb-3">

                <div class="col-lg-6">
                <div class="card h-100">
                <div class="card-header">
                    <div class="card-title">
                        <i class="bi bi-file-earmark-text"></i>&nbsp;<?php echo htmlspecialchars($LANG['purchase']); ?> <?php echo htmlspecialchars($LANG['details']); ?><span class="badge bg-secondary ms-2">#<?php echo (int)$purchase['id']; ?></span></div>
                </div>
                <div class="card-body p-2">
                    <table class="table table-borderless table-sm mb-0 pv-detail-tbl">
                        <tr>
                            <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['input_date']); ?></td>
                            <td class="pv-detail-sep">:</td>
                            <td class="pv-detail-val"><?php echo date('d/m/Y', strtotime($purchase['input_date'])); ?></td>
                        </tr>
                        <tr>
                            <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['invoice_date']); ?></td>
                            <td class="pv-detail-sep">:</td>
                            <td class="pv-detail-val"><?php echo date('d/m/Y', strtotime($purchase['invoice_date'])); ?></td>
                        </tr>
                        <tr>
                            <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['supplier']); ?></td>
                            <td class="pv-detail-sep">:</td>
                            <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['supplier_name'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['purchase_staff']); ?></td>
                            <td class="pv-detail-sep">:</td>
                            <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['purchase_staff_name'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['payment_by']); ?></td>
                            <td class="pv-detail-sep">:</td>
                            <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['payment_by_name'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['payer_name']); ?></td>
                            <td class="pv-detail-sep">:</td>
                            <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['payer_name_label'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['payer_shift']); ?></td>
                            <td class="pv-detail-sep">:</td>
                            <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['payer_shift_name'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['payment_method']); ?></td>
                            <td class="pv-detail-sep">:</td>
                            <td class="pv-detail-val">
                                <span class="badge bg-info">
                                    <?php echo htmlspecialchars($purchase['payment_method_name'] ?: '-'); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['notes']); ?></td>
                            <td class="pv-detail-sep">:</td>
                            <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['notes'] ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['created_by']); ?></td>
                            <td class="pv-detail-sep">:</td>
                            <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['created_by_name'] ?: '-'); ?></td>
                        </tr>
                    </table>
                </div>
                </div>
                </div>

                <!-- Invoice Document — berdampingan dengan Purchase Details.
                     Kartu ini SELALU dirender, baik invoice punya lampiran
                     maupun tidak. Saat tidak ada lampiran, isinya diganti
                     badge penanda "No Document" — kolomnya tidak hilang,
                     sehingga pembagian 50/50 dan tinggi kartu yang rata
                     tetap terjaga di semua kondisi. -->
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <div class="card-title">
                                <i class="bi bi-file-earmark-image"></i>&nbsp;<?php echo htmlspecialchars($LANG['invoice']); ?> <?php echo htmlspecialchars($LANG['document']); ?></div>
                        </div>
                        <div class="card-body text-center p-2 pv-doc-body">
                            <?php if (!empty($purchase['document'])): ?>
                            <img src="<?php echo url('uploads/purchase/' . htmlspecialchars($purchase['document'])); ?>"
                                 class="img-fluid"
                                 style="max-height:320px; cursor:zoom-in; border-radius:4px;"
                                 onclick="pvOpenLightbox(this.src)"
                                 title="<?php echo htmlspecialchars($LANG['click_to_zoom']); ?>">
                            <?php else: ?>
                            <span class="badge bg-secondary">
                                <i class="bi bi-file-earmark-x"></i> <?php echo htmlspecialchars($LANG['no_document']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Items table -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="bi bi-list-check"></i>&nbsp;<?php echo htmlspecialchars($LANG['items']); ?></div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-sm mb-0">
                            <thead>
                                <tr>
                                    <th><?php echo htmlspecialchars($LANG['item_name']); ?></th>
                                    <th><?php echo htmlspecialchars($LANG['quantity']); ?></th>
                                    <th><?php echo htmlspecialchars($LANG['unit_price']); ?></th>
                                    <th><?php echo htmlspecialchars($LANG['discount']); ?></th>
                                    <th><?php echo htmlspecialchars($LANG['price_rounding']); ?></th>
                                    <th><?php echo htmlspecialchars($LANG['total']); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($item['item_name']); ?>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($item['category_name'] ?: '-'); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars(pf_formatQuantity($item['quantity'])); ?> <?php echo htmlspecialchars($item['unit_name'] ?: ''); ?></td>
                                        <td><div class="pv-cur-cell"><?php echo pf_currencyCell((float)$item['unit_price']); ?></div></td>
                                        <td><div class="pv-cur-cell"><?php echo pf_currencyCell((float)$item['discount']); ?></div></td>
                                        <td><div class="pv-cur-cell"><?php echo pf_currencyCell((float)$item['price_rounding']); ?></div></td>
                                        <td><div class="pv-cur-cell"><?php echo pf_currencyCell((float)$item['total_price']); ?></div></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($overall_discount != 0): ?>
                                <tr>
                                    <th colspan="5" class="text-end text-muted fw-normal"><?php echo htmlspecialchars($LANG['purchase_overall_discount']); ?>:</th>
                                    <th><div class="pv-cur-cell"><?php echo pf_currencyCell($overall_discount); ?></div></th>
                                </tr>
                                <?php endif; ?>
                                <?php if ($overall_price_rounding != 0): ?>
                                <tr>
                                    <th colspan="5" class="text-end text-muted fw-normal"><?php echo htmlspecialchars($LANG['purchase_overall_price_rounding']); ?>:</th>
                                    <th><div class="pv-cur-cell"><?php echo pf_currencyCell($overall_price_rounding); ?></div></th>
                                </tr>
                                <?php endif; ?>
                                <?php if ($purchase_tax != 0): ?>
                                <tr>
                                    <th colspan="5" class="text-end text-muted fw-normal"><?php echo htmlspecialchars($LANG['purchase_tax']); ?>:</th>
                                    <th><div class="pv-cur-cell"><?php echo pf_currencyCell($purchase_tax); ?></div></th>
                                </tr>
                                <?php endif; ?>
                                <tr class="table-active">
                                    <th colspan="5" class="text-end"><?php echo htmlspecialchars($LANG['grand_total']); ?>:</th>
                                    <th><div class="pv-cur-cell"><?php echo pf_currencyCell($final_total); ?></div></th>
                                </tr>                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ TRACEABILITY (mode modal) ════════════════════════════════
             Menempati kolom kanan bekas Invoice Document, yang kini sudah
             dipindah berdampingan dengan Purchase Details di kolom kiri. -->
        <div class="col-md-4 p-3">
            <?php echo $pv_trace_html; ?>
        </div>
    </div>

    <!-- Lightbox overlay — custom div (bukan Bootstrap modal, agar tidak trigger loading di parent modal) -->
    <div id="pvLightboxOverlay" onclick="if(event.target===this||event.target.id==='pvLightboxOverlay')pvCloseLightbox()">
        <img id="pvLightboxImg" src="" alt="">
        <button id="pvLightboxCloseBtn" onclick="pvCloseLightbox()">
            <i class="bi bi-x-circle"></i><?php echo htmlspecialchars($LANG['close']); ?>
        </button>
    </div>

    <script>
    (function () {
        var overlay  = document.getElementById('pvLightboxOverlay');
        var img      = document.getElementById('pvLightboxImg');
        var scale    = 1;
        var transX   = 0;
        var transY   = 0;
        var dragStart = null;
        var pinchStartDist  = 0;
        var pinchStartScale = 1;

        function applyTransform() {
            img.style.transform = 'scale(' + scale + ') translate(' + transX + 'px,' + transY + 'px)';
        }
        function clampScale(s) { return Math.min(Math.max(s, 1), 5); }

        window.pvOpenLightbox = function (src) {
            if (!overlay || !img) return;
            img.src          = src;
            scale            = 1; transX = 0; transY = 0;
            img.style.transform = '';
            img.style.cursor    = 'grab';
            overlay.classList.add('pv-lb-active');
            document.body.style.overflow = 'hidden';
        };

        window.pvCloseLightbox = function () {
            overlay.classList.remove('pv-lb-active');
            document.body.style.overflow = '';
        };

        /* Wheel zoom */
        if (overlay) {
            overlay.addEventListener('wheel', function (e) {
                e.preventDefault();
                var delta = e.deltaY < 0 ? 0.15 : -0.15;
                scale = clampScale(scale + delta);
                if (scale === 1) { transX = 0; transY = 0; }
                applyTransform();
            }, { passive: false });
        }

        /* Drag to pan */
        if (img) {
            img.addEventListener('mousedown', function (e) {
                if (scale <= 1) return;
                e.preventDefault();
                dragStart = { x: e.clientX - transX, y: e.clientY - transY };
                img.style.cursor = 'grabbing';
            });
        }
        document.addEventListener('mousemove', function (e) {
            if (!dragStart) return;
            transX = e.clientX - dragStart.x;
            transY = e.clientY - dragStart.y;
            applyTransform();
        });
        document.addEventListener('mouseup', function () {
            if (dragStart) { dragStart = null; if (img) img.style.cursor = 'grab'; }
        });

        /* Pinch-to-zoom (mobile) */
        if (img) {
            img.addEventListener('touchstart', function (e) {
                if (e.touches.length === 2) {
                    e.preventDefault();
                    var dx = e.touches[0].clientX - e.touches[1].clientX;
                    var dy = e.touches[0].clientY - e.touches[1].clientY;
                    pinchStartDist  = Math.sqrt(dx * dx + dy * dy);
                    pinchStartScale = scale;
                }
            }, { passive: false });
            img.addEventListener('touchmove', function (e) {
                if (e.touches.length === 2) {
                    e.preventDefault();
                    var dx   = e.touches[0].clientX - e.touches[1].clientX;
                    var dy   = e.touches[0].clientY - e.touches[1].clientY;
                    var dist = Math.sqrt(dx * dx + dy * dy);
                    scale = clampScale(pinchStartScale * (dist / pinchStartDist));
                    if (scale === 1) { transX = 0; transY = 0; }
                    applyTransform();
                }
            }, { passive: false });
        }

        /* Escape key */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay && overlay.classList.contains('pv-lb-active')) {
                pvCloseLightbox();
            }
        });
    })();

    /**
     * pvModalExport — export current purchase_view data only (modal context).
     * Uses SweetAlert confirmation (same pattern as exportData() in purchases_invoice.php).
     * Reads L strings from window._pvLang injected by purchases_invoice.php.
     * Opens export in new tab so parent modal stays open.
     */
    window.pvModalExport = function (type) {
        var purchaseId = <?php echo (int)$purchase['id']; ?>;
        var exportUrl  = '<?php echo url('includes/export/export_purchases.php'); ?>'
            + '?data=purchase_view'
            + '&purchase_id=' + purchaseId
            + '&type=' + encodeURIComponent(type);
        var L = window._pvLang || {};
        var confirmExport = L.confirmExport || 'Confirm Export';
        var exportTo      = L.exportTo      || 'Export to';
        var yesExport     = L.yesExport     || 'Yes, Export';
        var cancel        = L.cancel        || 'Cancel';
        var exporting     = L.exporting     || 'Exporting...';
        var pleaseWait    = L.pleaseWait    || 'Please wait...';
        var expSuccess    = L.expSuccess    || 'Export Successful';
        var fileDl        = L.fileDl        || 'Your file has been downloaded';
        var purchaseLbl   = L.purchase      || 'Purchase';
        var detailsLbl    = L.details       || 'Details';
        var idInfo        = '<strong>#' + purchaseId + '</strong>';

        if (typeof Swal === 'undefined') {
            window.open(exportUrl, '_blank');
            return;
        }
        Swal.fire({
            title: confirmExport,
            html:  '<div style="text-align:left;">'
                 + '<strong>' + exportTo + ':</strong> ' + type.toUpperCase()
                 + '</div>'
                 + '<div style="text-align:left;margin-top:10px;">'
                 + '<strong>' + purchaseLbl + ' ' + detailsLbl + ':</strong> ' + idInfo
                 + '</div>',
            icon:  'question',
            showCancelButton:   true,
            confirmButtonColor: type === 'excel' ? '#28a745' : '#dc3545',
            cancelButtonColor:  '#6c757d',
            confirmButtonText:  '<i class="bi bi-check-circle"></i> ' + yesExport,
            cancelButtonText:   '<i class="bi bi-x-circle"></i> ' + cancel,
            reverseButtons:     true
        }).then(function (res) {
            if (!res.isConfirmed) return;
            Swal.fire({
                title: exporting, html: pleaseWait,
                allowOutsideClick: false, didOpen: function () { Swal.showLoading(); }
            });
            window.open(exportUrl, '_blank');
            setTimeout(function () {
                Swal.close();
                Swal.fire({ icon: 'success', title: expSuccess, text: fileDl, timer: 2000, showConfirmButton: false });
            }, 2000);
        });
    };
    </script>
    <?php
    exit;
}

// ── Standalone page (non-modal) ──────────────────────────────────────────────
$page_title = $LANG['view'] . ' ' . ($LANG['purchase']);
include '../includes/header.php';
?>
<div class="page-title-bar">
    <h2 class="page-title-text">
        <i class="bi bi-eye"></i>
        <?php echo htmlspecialchars($page_title); ?> #<?php echo (int)$id; ?>
    </h2>
    <div class="page-actions">
        <?php if (_dbCan('purchases', 'can_export')): ?>
        <button type="button" class="btn btn-sm btn-success" onclick="pvExport('excel')" id="pvBtnExcelStandalone">
            <i class="bi bi-file-earmark-excel"></i> <?php echo htmlspecialchars($LANG['export_excel']); ?>
        </button>
        <?php endif; ?>
        <a href="<?php echo url('purchases/pr_dahsboard_gate.php'); ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-x-circle"></i> <?php echo htmlspecialchars($LANG['close']); ?>
        </a>
    </div>
</div>

<style>
/* Currency cell — standalone page */
.pv-cur-cell {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
}
.pv-cur-cell .cur-sym {
    flex-shrink: 0;
    text-align: left;
    color: #6c757d;
    font-size: 0.85em;
}
.pv-cur-cell .cur-val {
    flex: 1;
    text-align: right;
    font-weight: 600;
}
/* Purchase Details table: label | : | value — colon aligned vertically, compact */
.pv-detail-tbl tr td { padding: .2rem .4rem !important; vertical-align: middle; border: none; }
.pv-detail-lbl { white-space: nowrap; font-weight: 600; padding-right: 0 !important; width: 1%; }
.pv-detail-sep { width: 14px; text-align: center; padding-left: 2px !important; padding-right: 6px !important; font-weight: 600; color: #495057; }
.pv-detail-val { word-break: break-word; }
    /* ── Traceability ───────────────────────────────────────────── */
    .pv-trace-current { background-color:rgba(13,110,253,.06); }
    /* ── Invoice Document: gambar rata tengah (atas-bawah & kiri-kanan) ──
       Kartu memakai h-100 agar tingginya rata dengan Purchase Details.
       Ruang sisa diisi dengan MEMOSISIKAN gambar di tengah, bukan dengan
       meregangkannya — max-width:100% + height:auto menjaga rasio asli
       sehingga gambar tidak pernah gepeng atau ketarik. */
    .pv-doc-body { display:flex; align-items:center; justify-content:center; }
    .pv-doc-body img { max-width:100%; height:auto; object-fit:contain; }
</style>

<div class="row">
    <div class="col-md-8">

        <!-- ══ BARIS ATAS: Purchase Details + Invoice Document ═══════════
             Pembagian 50/50 dengan jarak g-3 (1rem), sama dengan mt-3 yang
             memisahkan kartu Items di bawahnya. h-100 menyamakan tinggi. -->
        <div class="row g-3">

            <div class="col-lg-6">
            <div class="card h-100">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-file-earmark-text"></i>&nbsp;<?php echo htmlspecialchars($LANG['purchase']); ?> <?php echo htmlspecialchars($LANG['details']); ?></div>
            </div>
            <div class="card-body">
                <table class="table table-borderless pv-detail-tbl">
                    <tr>
                        <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['input_date']); ?></td>
                        <td class="pv-detail-sep">:</td>
                        <td class="pv-detail-val"><?php echo date('d/m/Y', strtotime($purchase['input_date'])); ?></td>
                    </tr>
                    <tr>
                        <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['invoice_date']); ?></td>
                        <td class="pv-detail-sep">:</td>
                        <td class="pv-detail-val"><?php echo date('d/m/Y', strtotime($purchase['invoice_date'])); ?></td>
                    </tr>
                    <tr>
                        <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['supplier']); ?></td>
                        <td class="pv-detail-sep">:</td>
                        <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['supplier_name'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['purchase_staff']); ?></td>
                        <td class="pv-detail-sep">:</td>
                        <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['purchase_staff_name'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['payment_by']); ?></td>
                        <td class="pv-detail-sep">:</td>
                        <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['payment_by_name'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['payer_name']); ?></td>
                        <td class="pv-detail-sep">:</td>
                        <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['payer_name_label'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['payer_shift']); ?></td>
                        <td class="pv-detail-sep">:</td>
                        <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['payer_shift_name'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['payment_method']); ?></td>
                        <td class="pv-detail-sep">:</td>
                        <td class="pv-detail-val">
                            <span class="badge bg-info">
                                <?php echo htmlspecialchars($purchase['payment_method_name'] ?: '-'); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['notes']); ?></td>
                        <td class="pv-detail-sep">:</td>
                        <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['notes'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="pv-detail-lbl"><?php echo htmlspecialchars($LANG['created_by']); ?></td>
                        <td class="pv-detail-sep">:</td>
                        <td class="pv-detail-val"><?php echo htmlspecialchars($purchase['created_by_name'] ?: '-'); ?></td>
                    </tr>
                </table>
            </div>
            </div>
            </div>

            <!-- Invoice Document — berdampingan dengan Purchase Details.
                 Kartu ini SELALU dirender, baik invoice punya lampiran
                 maupun tidak. Saat tidak ada lampiran, isinya diganti
                 badge penanda "No Document" — kolomnya tidak hilang,
                 sehingga pembagian 50/50 dan tinggi kartu yang rata
                 tetap terjaga di semua kondisi. -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="bi bi-file-earmark-image"></i>&nbsp;<?php echo htmlspecialchars($LANG['invoice']); ?> <?php echo htmlspecialchars($LANG['document']); ?></div>
                    </div>
                    <div class="card-body text-center pv-doc-body">
                        <?php if (!empty($purchase['document'])): ?>
                        <img src="<?php echo url('uploads/purchase/' . htmlspecialchars($purchase['document'])); ?>"
                             class="img-fluid"
                             style="max-height:400px; cursor:zoom-in;"
                             onclick="openPhotoModal(this.src)"
                             title="<?php echo htmlspecialchars($LANG['click_to_zoom']); ?>">
                        <?php else: ?>
                        <span class="badge bg-secondary">
                            <i class="bi bi-file-earmark-x"></i> <?php echo htmlspecialchars($LANG['no_document']); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <div class="card mt-3">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-list-check"></i>&nbsp;<?php echo htmlspecialchars($LANG['items']); ?></div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars($LANG['item_name']); ?></th>
                                <th><?php echo htmlspecialchars($LANG['quantity']); ?></th>
                                <th><?php echo htmlspecialchars($LANG['unit_price']); ?></th>
                                <th><?php echo htmlspecialchars($LANG['discount']); ?></th>
                                <th><?php echo htmlspecialchars($LANG['price_rounding']); ?></th>
                                <th><?php echo htmlspecialchars($LANG['total']); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($item['category_name'] ?: '-'); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars(pf_formatQuantity($item['quantity'])); ?> <?php echo htmlspecialchars($item['unit_name'] ?: ''); ?></td>
                                    <td><div class="pv-cur-cell"><?php echo pf_currencyCell((float)$item['unit_price']); ?></div></td>
                                    <td><div class="pv-cur-cell"><?php echo pf_currencyCell((float)$item['discount']); ?></div></td>
                                    <td><div class="pv-cur-cell"><?php echo pf_currencyCell((float)$item['price_rounding']); ?></div></td>
                                    <td><div class="pv-cur-cell"><?php echo pf_currencyCell((float)$item['total_price']); ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($overall_discount != 0): ?>
                            <tr>
                                <th colspan="5" class="text-end text-muted fw-normal"><?php echo htmlspecialchars($LANG['purchase_overall_discount']); ?>:</th>
                                <th><div class="pv-cur-cell"><?php echo pf_currencyCell($overall_discount); ?></div></th>
                            </tr>
                            <?php endif; ?>
                            <?php if ($overall_price_rounding != 0): ?>
                            <tr>
                                <th colspan="5" class="text-end text-muted fw-normal"><?php echo htmlspecialchars($LANG['purchase_overall_price_rounding']); ?>:</th>
                                <th><div class="pv-cur-cell"><?php echo pf_currencyCell($overall_price_rounding); ?></div></th>
                            </tr>
                            <?php endif; ?>
                            <?php if ($purchase_tax != 0): ?>
                            <tr>
                                <th colspan="5" class="text-end text-muted fw-normal"><?php echo htmlspecialchars($LANG['purchase_tax']); ?>:</th>
                                <th><div class="pv-cur-cell"><?php echo pf_currencyCell($purchase_tax); ?></div></th>
                            </tr>
                            <?php endif; ?>
                            <tr class="table-active">
                                <th colspan="5" class="text-end"><?php echo htmlspecialchars($LANG['grand_total']); ?>:</th>
                                <th><div class="pv-cur-cell"><?php echo pf_currencyCell($final_total); ?></div></th>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ TRACEABILITY (mode halaman penuh) ════════════════════════════
         Menempati kolom kanan bekas Invoice Document. -->
    <div class="col-md-4">
        <?php echo $pv_trace_html; ?>
    </div>
</div>

<script>
/**
 * pvExport — export current purchase_view data only (standalone page).
 * Uses SweetAlert confirmation (same pattern as exportData() in purchases_invoice.php).
 * Redirects to export_purchases.php with data=purchase_view&purchase_id=<id>&type=<excel|pdf>
 */
function pvExport(type) {
    var purchaseId    = <?php echo (int)$id; ?>;
    var exportUrl     = '<?php echo url('includes/export/export_purchases.php'); ?>'
        + '?data=purchase_view'
        + '&purchase_id=' + purchaseId
        + '&type=' + encodeURIComponent(type);
    var confirmExport = <?php echo bltx_js($LANG['confirm_export']) ?>;
    var exportTo      = <?php echo bltx_js($LANG['export_to']) ?>;
    var yesExport     = <?php echo bltx_js($LANG['yes_export']) ?>;
    var cancel        = <?php echo bltx_js($LANG['cancel']) ?>;
    var exporting     = <?php echo bltx_js($LANG['exporting']) ?>;
    var pleaseWait    = <?php echo bltx_js($LANG['please_wait']) ?>;
    var expSuccess    = <?php echo bltx_js($LANG['export_success']) ?>;
    var fileDl        = <?php echo bltx_js($LANG['file_downloaded']) ?>;
    var purchaseLbl   = <?php echo bltx_js($LANG['purchase']) ?>;
    var detailsLbl    = <?php echo bltx_js($LANG['details']) ?>;
    var idInfo        = '<strong>#' + purchaseId + '</strong>';

    if (typeof Swal === 'undefined') {
        window.location.href = exportUrl;
        return;
    }
    Swal.fire({
        title: confirmExport,
        html:  '<div style="text-align:left;">'
             + '<strong>' + exportTo + ':</strong> ' + type.toUpperCase()
             + '</div>'
             + '<div style="text-align:left;margin-top:10px;">'
             + '<strong>' + purchaseLbl + ' ' + detailsLbl + ':</strong> ' + idInfo
             + '</div>',
        icon:  'question',
        showCancelButton:   true,
        confirmButtonColor: type === 'excel' ? '#28a745' : '#dc3545',
        cancelButtonColor:  '#6c757d',
        confirmButtonText:  '<i class="bi bi-check-circle"></i> ' + yesExport,
        cancelButtonText:   '<i class="bi bi-x-circle"></i> ' + cancel,
        reverseButtons:     true
    }).then(function (res) {
        if (!res.isConfirmed) return;
        Swal.fire({
            title: exporting, html: pleaseWait,
            allowOutsideClick: false, didOpen: function () { Swal.showLoading(); }
        });
        window.location.href = exportUrl;
        setTimeout(function () {
            Swal.close();
            Swal.fire({ icon: 'success', title: expSuccess, text: fileDl, timer: 2000, showConfirmButton: false });
        }, 2000);
    });
}
</script>

<!-- Photo lightbox modal (same pattern as manual_dreport.php) -->
<div class="modal fade" id="photoLightboxModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:90vw;width:auto;">
        <div class="modal-content bg-transparent border-0 shadow-none">
            <div class="modal-body p-0 text-center">
                <img id="photoLightboxImg" src=""
                     alt="<?php echo htmlspecialchars($LANG['invoice']); ?>"
                     style="max-width:88vw;max-height:82vh;object-fit:contain;border-radius:.5rem;display:block;margin:0 auto;cursor:grab;user-select:none;touch-action:none;">
            </div>
        </div>
    </div>
</div>
<!-- Close button fixed outside modal-dialog so it is not affected by img transform -->
<button type="button" id="photoLightboxClose"
        class="btn btn-sm btn-light px-4"
        style="display:none;position:fixed;bottom:2rem;left:50%;transform:translateX(-50%);z-index:1056;">
    <i class="bi bi-x-circle me-1"></i><?php echo htmlspecialchars($LANG['close']); ?>
</button>

<script>
function openPhotoModal(src) {
    var img      = document.getElementById('photoLightboxImg');
    var modalEl  = document.getElementById('photoLightboxModal');
    var closeBtn = document.getElementById('photoLightboxClose');
    if (!img || !modalEl) return;

    img.src = src;

    var scale  = 1;
    var transX = 0;
    var transY = 0;

    function applyTransform() {
        img.style.transform = 'scale(' + scale + ') translate(' + transX + 'px,' + transY + 'px)';
    }
    function clampScale(s) { return Math.min(Math.max(s, 1), 5); }

    function onShow() { closeBtn.style.display = 'block'; }
    function onHide() {
        closeBtn.style.display = 'none';
        scale = 1; transX = 0; transY = 0;
        img.style.transform = '';
        img.style.cursor    = 'grab';
        modalEl.removeEventListener('shown.bs.modal',  onShow);
        modalEl.removeEventListener('hidden.bs.modal', onHide);
    }
    modalEl.addEventListener('shown.bs.modal',  onShow);
    modalEl.addEventListener('hidden.bs.modal', onHide);

    closeBtn.onclick = function () {
        bootstrap.Modal.getInstance(modalEl).hide();
    };

    /* Wheel zoom */
    img.onwheel = function (e) {
        e.preventDefault();
        var delta = e.deltaY < 0 ? 0.15 : -0.15;
        scale = clampScale(scale + delta);
        if (scale === 1) { transX = 0; transY = 0; }
        applyTransform();
    };

    /* Drag to pan */
    var dragStart = null;
    img.onmousedown = function (e) {
        if (scale <= 1) return;
        e.preventDefault();
        dragStart = { x: e.clientX - transX, y: e.clientY - transY };
        img.style.cursor = 'grabbing';
    };
    window.addEventListener('mousemove', function (e) {
        if (!dragStart) return;
        transX = e.clientX - dragStart.x;
        transY = e.clientY - dragStart.y;
        applyTransform();
    });
    window.addEventListener('mouseup', function () {
        if (dragStart) { dragStart = null; img.style.cursor = scale > 1 ? 'grab' : 'grab'; }
    });

    /* Pinch-to-zoom (mobile) */
    var pinchStartDist  = 0;
    var pinchStartScale = 1;
    img.ontouchstart = function (e) {
        if (e.touches.length === 2) {
            e.preventDefault();
            var dx = e.touches[0].clientX - e.touches[1].clientX;
            var dy = e.touches[0].clientY - e.touches[1].clientY;
            pinchStartDist  = Math.sqrt(dx * dx + dy * dy);
            pinchStartScale = scale;
        }
    };
    img.ontouchmove = function (e) {
        if (e.touches.length === 2) {
            e.preventDefault();
            var dx   = e.touches[0].clientX - e.touches[1].clientX;
            var dy   = e.touches[0].clientY - e.touches[1].clientY;
            var dist = Math.sqrt(dx * dx + dy * dy);
            scale = clampScale(pinchStartScale * (dist / pinchStartDist));
            if (scale === 1) { transX = 0; transY = 0; }
            applyTransform();
        }
    };

    new bootstrap.Modal(modalEl).show();
}
</script>

<?php include '../includes/footer.php'; ?>