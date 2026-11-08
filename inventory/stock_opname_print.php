<?php
/**
 * stock_opname_print.php
 * File: public_html/bltx/inventory/stock_opname_print.php
 *
 * Halaman cetak (browser print) untuk satu dokumen Stock Opname. Sengaja
 * dibuat sebagai halaman HTML biasa + window.print(), BUKAN Dompdf —
 * supaya Chinese/Unicode selalu tampil benar (browser memakai font OS-nya
 * sendiri, tidak bergantung pada font CJK yang ter-install di server).
 * Untuk file PDF yang bisa diunduh, lihat export_stock_opname.php.
 */

require_once __DIR__ . '/../includes/config.php';

if (!isLoggedIn()) {
    redirect(url('index.php'));
}

require_once BASE_PATH . '/includes/global_permission.php';
require_once BASE_PATH . '/includes/inventory_function.php';

requireStockOpnameAccess();
if (!canExportStockOpname()) {
    redirectNoAccess();
}

$LANG = loadLanguage($_SESSION['lang'] ?? 'en');

// Mode embed: halaman ini ditampilkan di dalam modal (iframe) pada
// stock_opname.php / stock_opname_view.php. Saat embed, tombol cetak bawaan
// halaman ini disembunyikan karena tombolnya sudah disediakan oleh modal.
$embed = isset($_GET['embed']) && $_GET['embed'] === '1';

$id     = (int)($_GET['id'] ?? 0);
$header = $id > 0 ? soGetOpnameHeader($conn, $id) : null;

// Proteksi IDOR: hormati cakupan data (Own Data Only) — dokumen milik user
// lain tidak boleh dibuka hanya dengan menebak ID di URL.
if ($header && !canViewAllStockOpname() && (int)$header['created_by'] !== (int)($_SESSION['user_id'] ?? 0)) {
    $header = null;
}

if (!$header) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:2rem">'
       . htmlspecialchars($LANG['so_not_found'], ENT_QUOTES, 'UTF-8') . '</body>';
    exit;
}

$itemsResult = soGetOpnameItems($conn, $id);
$items = [];
if ($itemsResult) {
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = $row;
    }
}
$summary = soGetVarianceSummaries($conn, [$id]);
$sum = $summary[$id] ?? ['total_items' => 0, 'matched_count' => 0, 'short_count' => 0, 'over_count' => 0, 'total_variance_value' => 0];

logActivity($_SESSION['user_id'] ?? 0, 'print_stock_opname', "Printed Stock Opname {$header['opname_number']}");
?>
<!doctype html>
<html lang="<?= invHtml($_SESSION['lang'] ?? 'en', 'en') ?>">
<head>
<meta charset="utf-8">
<title><?= invHtml($LANG['stock_opname']) ?> - <?= invHtml($header['opname_number']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, 'Segoe UI', Roboto, 'PingFang SC', 'Microsoft YaHei', Arial, sans-serif; color: #212529; margin: 24px; }
  h1 { font-size: 20px; margin: 0 0 2px 0; }
  .sub { color: #6c757d; font-size: 13px; margin-bottom: 18px; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; }
  th, td { border: 1px solid #dee2e6; padding: 6px 8px; font-size: 12.5px; text-align: left; }
  th { background: #f1f3f5; }
  .text-end { text-align: right; }
  .text-center { text-align: center; }
  .meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px 24px; margin-bottom: 16px; }
  .meta div span.label { display: block; color: #6c757d; font-size: 11px; text-transform: uppercase; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; color: #fff; }
  .bg-success { background: #198754; } .bg-danger { background: #dc3545; }
  .bg-warning { background: #ffc107; color:#212529; } .bg-secondary { background: #6c757d; }
  .bg-primary { background: #0d6efd; } .bg-info { background: #0dcaf0; color:#212529; }
  .summary-bar span { margin-right: 14px; }
  .no-print { margin-top: 20px; }

  /* ── Footer dokumen — isi & tata letaknya dibuat sama dengan footer PDF
        di stock_opname_export_pdf.php: kiri nomor dokumen, tengah waktu
        cetak, kanan nomor halaman. Ukuran 7pt dan margin kiri/kanan 10mm
        juga mengikuti nilai yang dipakai di PDF.                            */
  .print-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    font-size: 7pt;
    color: #000;
    margin-top: 18px;
    padding-top: 6px;
    border-top: 1px solid #dee2e6;
  }
  .print-footer .pf-left   { flex: 1 1 0; text-align: left; }
  .print-footer .pf-center { flex: 1 1 0; text-align: center; }
  .print-footer .pf-right  { flex: 1 1 0; text-align: right; }

  @media print {
    .no-print { display: none; }
    body { margin: 8mm; padding-bottom: 16mm; }

    /* Margin halaman disamakan dengan @page milik PDF (12mm 10mm 14mm 10mm) */
    @page { margin: 12mm 10mm 14mm 10mm; }

    /* Footer menempel di bagian bawah SETIAP halaman cetak */
    .print-footer {
      position: fixed;
      left: 0;
      right: 0;
      bottom: 0;
      margin-top: 0;
      background: #fff;
      border-top: 1px solid #dee2e6;
    }

    /* Nomor halaman hanya bermakna saat dicetak. Diisi lewat CSS counter
       supaya angkanya mengikuti halaman cetak yang sebenarnya; label
       "Page"/"of" diambil dari atribut data-* agar tetap multi-bahasa. */
    .print-footer .pf-page::after {
      content: attr(data-page-label) " " counter(page) " " attr(data-of-label) " " counter(pages);
    }
  }
<?php if ($embed): ?>
  /* Tampil di dalam modal: margin dikecilkan supaya ruang terpakai maksimal */
  body { margin: 14px; }
<?php endif; ?>
</style>
</head>
<body>

  <h1><?= invHtml($LANG['stock_opname']) ?> — <?= invHtml($header['opname_number']) ?></h1>
  <div class="sub">
    <span class="badge bg-<?= soStatusBadgeClass($header['status']) ?>"><?= invHtml($LANG[$header['status']]) ?></span>
  </div>

  <div class="meta">
    <div><span class="label"><?= invHtml($LANG['date']) ?></span><?= invHtml($header['opname_date']) ?></div>
    <div><span class="label"><?= invHtml($LANG['location']) ?></span><?= invHtml(soLocationLabel($header['location_code'], $header['location_name'])) ?></div>
    <div><span class="label"><?= invHtml($LANG['created_by']) ?></span><?= invHtml(soActorWithDate($header['created_by_name'], $header['created_at'])) ?></div>
    <div><span class="label"><?= invHtml($LANG['approved_by']) ?></span><?= $header['approved_by_name'] ? invHtml(soActorWithDate($header['approved_by_name'], $header['approved_at'])) : '-' ?></div>
    <div><span class="label"><?= invHtml($LANG['posted_by']) ?></span><?= $header['posted_by_name'] ? invHtml(soActorWithDate($header['posted_by_name'], $header['posted_at'])) : '-' ?></div>
    <div><span class="label"><?= invHtml($LANG['so_total_items']) ?></span><?= invFormatQty($sum['total_items']) ?></div>
  </div>

  <div class="summary-bar sub">
    <span><span class="badge bg-success"><?= invHtml($LANG['matched']) ?></span> <?= invFormatQty($sum['matched_count']) ?></span>
    <span><span class="badge bg-danger"><?= invHtml($LANG['short']) ?></span> <?= invFormatQty($sum['short_count']) ?></span>
    <span><span class="badge bg-warning"><?= invHtml($LANG['over']) ?></span> <?= invFormatQty($sum['over_count']) ?></span>
    <span><strong><?= invHtml($LANG['so_total_variance']) ?>:</strong> <?= formatCurrency($sum['total_variance_value']) ?></span>
  </div>

  <table>
    <thead>
      <tr>
        <th><?= invHtml($LANG['col_no']) ?></th>
        <th><?= invHtml($LANG['product_code']) ?></th>
        <th><?= invHtml($LANG['item_name']) ?></th>
        <th><?= invHtml($LANG['unit']) ?></th>
        <th class="text-end"><?= invHtml($LANG['so_system_qty']) ?></th>
        <th class="text-end"><?= invHtml($LANG['so_physical_qty']) ?></th>
        <th class="text-end"><?= invHtml($LANG['difference']) ?></th>
        <th class="text-end"><?= invHtml($LANG['so_difference_value']) ?></th>
        <th><?= invHtml($LANG['remarks']) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php $no = 1; foreach ($items as $item):
          $variance = soVarianceStatus($item['counted_qty'], $item['difference_qty']);
      ?>
      <tr>
        <td><?= $no++ ?></td>
        <td><?= invHtml($item['product_code']) ?></td>
        <td><?= invHtml($item['item_name']) ?></td>
        <td><?= invHtml($item['unit_symbol'] ?: $item['unit_name']) ?></td>
        <td class="text-end"><?= invFormatQty($item['system_qty']) ?></td>
        <td class="text-end"><?= $item['counted_qty'] !== null ? invFormatQty($item['counted_qty']) : '-' ?></td>
        <td class="text-end"><?= $item['counted_qty'] !== null ? invFormatQty($item['difference_qty']) : '-' ?></td>
        <td class="text-end"><?= $item['counted_qty'] !== null ? formatCurrency($item['difference_value']) : '-' ?></td>
        <td><?= invHtml($item['remarks'], '') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!empty($header['notes'])): ?>
  <p style="margin-top:16px"><strong><?= invHtml($LANG['notes']) ?>:</strong><br><?= nl2br(invHtml($header['notes'])) ?></p>
  <?php endif; ?>

  <?php if ($header['status'] === 'cancelled' && !empty($header['cancel_reason'])): ?>
  <p style="margin-top:16px"><strong><?= invHtml($LANG['so_cancel_reason']) ?>:</strong><br><?= nl2br(invHtml($header['cancel_reason'])) ?></p>
  <?php endif; ?>

  <div class="print-footer">
    <span class="pf-left"><?= invHtml($header['opname_number']) ?></span>
    <span class="pf-center"><?= invHtml($LANG['printed_at']) ?>: <?= invHtml(date('d-m-Y H:i:s')) ?></span>
    <span class="pf-right pf-page"
          data-page-label="<?= invHtml($LANG['page']) ?>"
          data-of-label="<?= invHtml($LANG['of']) ?>"></span>
  </div>

  <?php if (!$embed): ?>
  <div class="no-print">
    <button onclick="window.print()" style="padding:8px 16px;font-size:14px"><?= invHtml($LANG['print']) ?></button>
  </div>
  <?php endif; ?>

</body>
</html>