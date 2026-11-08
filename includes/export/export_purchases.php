<?php
/**
 * export_purchases.php
 * Location : public_html/bltx/includes/export/export_purchases.php
 * Handles  : Excel & PDF export for purchases, returns, suppliers
 */

require_once __DIR__ . '/../config.php';

// ── Guard: must be logged in ──────────────────────────────────────────────────
if (!isLoggedIn()) {
    redirect(url('index.php'));
}

// ── Input sanitization ────────────────────────────────────────────────────────
$type             = isset($_GET['type'])             ? sanitize($_GET['type'])             : 'excel';
$dataType         = isset($_GET['data'])             ? sanitize($_GET['data'])             : 'purchases';
$purchase_view_id = !empty($_GET['purchase_id'])     ? (int)$_GET['purchase_id']           : 0;
$from_date        = !empty($_GET['from_date'])       ? $_GET['from_date']                  : '1970-01-01';
$to_date          = !empty($_GET['to_date'])         ? $_GET['to_date']                    : date('Y-m-d');
$item_name        = !empty($_GET['item_name'])       ? $_GET['item_name']                  : '';
$filter_supplier  = !empty($_GET['filter_supplier']) ? intval($_GET['filter_supplier'])    : 0;
$filter_payment   = !empty($_GET['filter_payment'])  ? intval($_GET['filter_payment'])     : 0;
$filter_payment_by = !empty($_GET['filter_payment_by']) ? intval($_GET['filter_payment_by']) : 0;

// Support date_range "YYYY-MM-DD to YYYY-MM-DD" as fallback
if (empty($_GET['from_date']) && !empty($_GET['date_range'])) {
    $date_range = trim($_GET['date_range']);
    if (strpos($date_range, ' to ') !== false) {
        [$from_date, $to_date] = explode(' to ', $date_range, 2);
        $from_date = trim($from_date);
        $to_date   = trim($to_date);
    }
}

// ── Permission check per dataType ────────────────────────────────────────────
$module_map = [
    'purchases'      => 'purchases',
    'purchase_view'  => 'purchases',
    'returns'        => 'returns',
    'suppliers'      => 'suppliers',
    // Dataset baru: memakai modul permission yang SUDAH ADA ('categories',
    // 'units'), bukan modul ekspor tersendiri, agar tidak double permission.
    'categories'     => 'categories',
    'units'          => 'units',
];
$perm_module = $module_map[$dataType] ?? 'purchases';

if (!checkPermission($perm_module, 'can_export')) {
    http_response_code(403);
    $msg = $LANG['export_access_denied'];
    exit(htmlspecialchars($msg));
}

// ── Validate export type ──────────────────────────────────────────────────────
// 'pdf' DIKELUARKAN dari daftar yang diizinkan karena cabang PDF sudah dihapus
// (lihat catatan di bagian bawah berkas). Dengan begini, URL lama yang masih
// membawa ?type=pdf ditolak di sini dengan pesan yang jelas, bukan berjalan
// sampai jauh lalu berhenti diam-diam tanpa mengirim berkas apa pun.
if (!in_array($type, ['excel'], true)) {
    exit('Invalid export type.');
}

// ── Load purchases_function.php for purchase_view export helper ───────────────
if ($dataType === 'purchase_view') {
    require_once BASE_PATH . '/includes/purchases_function.php';
    if ($purchase_view_id <= 0) {
        http_response_code(400);
        exit(htmlspecialchars($LANG['no_data']));
    }
}

// ── Fetch data — stream langsung dari result set, tidak buffer ke array ───────
// Ini menghindari alokasi memory besar untuk data ribuan baris

// ── Resolve filter label names for display ────────────────────────────────────
$label_supplier   = '';
$label_payment    = '';
$label_payment_by = '';
if ($filter_supplier > 0) {
    $r = $conn->query("SELECT name FROM suppliers WHERE id = " . intval($filter_supplier));
    if ($r && $row = $r->fetch_assoc()) $label_supplier = $row['name'];
}
if ($filter_payment > 0) {
    $r = $conn->query("SELECT name FROM payment_methods WHERE id = " . intval($filter_payment));
    if ($r && $row = $r->fetch_assoc()) $label_payment = $row['name'];
}
if ($filter_payment_by > 0) {
    $r = $conn->query("SELECT full_name FROM users WHERE id = " . intval($filter_payment_by));
    if ($r && $row = $r->fetch_assoc()) $label_payment_by = $row['full_name'];
}

// ── Build query & result set sekali — distream langsung tanpa buffer array ────
$stmt_purchases = null;
$result_returns = null;
$result_suppliers = null;
$result_categories = null;
$result_units      = null;

// Shared SQL columns — dipakai oleh purchases DAN purchase_view
$_pv_sql_cols = "SELECT pi.item_name, pi.quantity, pi.unit_price, pi.discount, pi.price_rounding, pi.total_price,
                        p.input_date, p.invoice_date, pm.name AS payment_method,
                        COALESCE(pb.full_name, p.payment_by) AS payment_by_name,
                        u.name AS unit_name, u.symbol AS unit_symbol,
                        c.name AS category_name, s.name AS supplier_name,
                        p.id AS purchase_row_id, p.tax AS purchase_tax
                 FROM purchase_items pi
                 JOIN purchases p ON pi.purchase_id = p.id
                 LEFT JOIN units u ON pi.unit_id = u.id
                 LEFT JOIN categories c ON pi.category_id = c.id
                 LEFT JOIN suppliers s ON p.supplier_id = s.id
                 LEFT JOIN payment_methods pm ON p.payment_method = pm.id
                 LEFT JOIN users pb ON p.payment_by REGEXP '^[0-9]+$' AND pb.id = CAST(p.payment_by AS UNSIGNED)";

if ($dataType === 'purchases') {
    $where  = "WHERE p.input_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];
    $types  = "ss";
    if ($item_name) {
        $where   .= " AND pi.item_name LIKE ?";
        $params[] = "%$item_name%";
        $types   .= "s";
    }
    if ($filter_supplier > 0)  { $where .= " AND p.supplier_id = ?";    $params[] = $filter_supplier;  $types .= "i"; }
    if ($filter_payment > 0)   { $where .= " AND p.payment_method = ?"; $params[] = $filter_payment;   $types .= "i"; }
    // Kolom p.payment_by kini menyimpan NAMA user, sedangkan dropdown filter
    // tetap mengirim ID. Cocokkan ke nama maupun ID dalam bentuk teks.
    if ($filter_payment_by > 0){ $where .= " AND (p.payment_by = ? OR p.payment_by = ?)"; $params[] = $label_payment_by; $params[] = (string)$filter_payment_by; $types .= "ss"; }

    $sql = $_pv_sql_cols . " $where ORDER BY p.input_date ASC, pi.id ASC";
    $stmt_purchases = $conn->prepare($sql);
    $stmt_purchases->bind_param($types, ...$params);
    $stmt_purchases->execute();
    $stmt_purchases->store_result();   // buffered — memungkinkan multi-pass jika perlu
    $stmt_purchases->bind_result(
        $_p_item, $_p_qty, $_p_uprice, $_p_disc, $_p_round, $_p_total,
        $_p_idate, $_p_invdate, $_p_pmeth, $_p_pby,
        $_p_uname, $_p_usym, $_p_cat, $_p_sup,
        $_p_pid, $_p_tax
    );

} elseif ($dataType === 'purchase_view') {
    // Query identik dengan purchases — hanya filter by purchase ID tunggal
    $sql = $_pv_sql_cols . " WHERE p.id = ? ORDER BY pi.id ASC";
    $stmt_purchases = $conn->prepare($sql);
    $stmt_purchases->bind_param('i', $purchase_view_id);
    $stmt_purchases->execute();
    $stmt_purchases->store_result();
    $stmt_purchases->bind_result(
        $_p_item, $_p_qty, $_p_uprice, $_p_disc, $_p_round, $_p_total,
        $_p_idate, $_p_invdate, $_p_pmeth, $_p_pby,
        $_p_uname, $_p_usym, $_p_cat, $_p_sup,
        $_p_pid, $_p_tax
    );

} elseif ($dataType === 'returns') {
    // [DIUBAH] Sumber data Purchase Return sekarang tabel `purchase_returns`
    // (flow PO -> Goods Receipt -> Invoice -> Return), bukan lagi tabel
    // `returns` lama. Daftar Return lama sudah tidak ditampilkan di UI, jadi
    // export harus mengikuti sumber yang sama supaya isi file cocok dengan
    // apa yang dilihat user di layar.
    //
    // Tabel `returns` lama TIDAK dihapus dan TIDAK diubah — hanya tidak lagi
    // menjadi sumber export ini.
    require_once BASE_PATH . '/includes/global_permission.php';
    require_once BASE_PATH . '/includes/purchase_returns_function.php';

    if (!pret_tablesExist($conn)) {
        http_response_code(400);
        exit(htmlspecialchars($LANG['pr_tables_missing']));
    }

    $_ret_sql    = "SELECT pr.return_number, pr.po_number, pr.gr_number, pr.supplier_name,
                           pr.return_date, pr.total_amount, pr.status, pr.created_by_name
                      FROM purchase_returns pr";
    $_ret_types  = '';
    $_ret_params = [];

    // REPORT DATA SCOPE — aturan yang sama persis dengan daftar di layar,
    // supaya user ber-scope "Own Data Only" tidak bisa menarik seluruh data
    // lewat URL export.
    $_ret_owner = permScopeOwnerId(canViewAllPurchaseReturn());
    if ($_ret_owner !== null) {
        $_ret_sql     .= " WHERE pr.created_by = ?";
        $_ret_types   .= 'i';
        $_ret_params[] = $_ret_owner;
    }
    $_ret_sql .= " ORDER BY pr.return_date DESC, pr.id DESC";

    if ($_ret_params) {
        $stmt_returns = $conn->prepare($_ret_sql);
        if ($stmt_returns) {
            $stmt_returns->bind_param($_ret_types, ...$_ret_params);
            $stmt_returns->execute();
            $result_returns = $stmt_returns->get_result();
        }
    } else {
        $result_returns = $conn->query($_ret_sql);
    }

} elseif ($dataType === 'suppliers') {
    $result_suppliers = $conn->query(
        "SELECT id, name, contact_person, phone, email, address,
                tax_number, postal_code, website, payment_terms,
                bank_name, bank_account, account_name, credit_limit,
                rating, status
         FROM suppliers ORDER BY name"
    );

} elseif ($dataType === 'categories') {
    $result_categories = $conn->query(
        "SELECT id, name, description FROM categories ORDER BY name"
    );

} elseif ($dataType === 'units') {
    $result_units = $conn->query(
        "SELECT id, name, symbol, description FROM units ORDER BY name"
    );
}

// ── purchase_view: single purchase header + items ─────────────────────────────
$pv_data = null;
if ($dataType === 'purchase_view') {
    $pv_data = pf_exportPurchaseViewData($conn, $purchase_view_id);
    if (!$pv_data['purchase']) {
        http_response_code(404);
        exit(htmlspecialchars($LANG['no_data']));
    }
}

// ── Helper: strip trailing decimal zeros (1.00→1, 1.10→1.1) ─────────────────
function formatQty($qty) {
    $f   = floatval($qty);
    $str = number_format($f, 2, '.', '');
    $str = rtrim($str, '0');
    $str = rtrim($str, '.');
    return $str;
}

// ── Language helpers ──────────────────────────────────────────────────────────
function L(string $key, string $fallback = ''): string {
    global $LANG;
    return $LANG[$key] ?? $fallback;
}

// ── Currency helper (wrap formatCurrency from config.php) ────────────────────
function fmtCurrency(float $amount): string {
    return formatCurrency($amount);
}

// ── Pre-cache currency symbol & setting — query DB sekali saja ───────────────
// Hindari getSetting() dipanggil ribuan kali di dalam loop
$_cur_cache = null;
function _getCurCache(): array {
    global $_cur_cache, $conn;
    if ($_cur_cache !== null) return $_cur_cache;
    $currency = getSetting('currency', 'IDR');
    switch ($currency) {
        case 'USD': $sym = '$';  break;
        case 'CNY': $sym = '¥'; break;
        default:    $sym = 'Rp'; break;
    }
    $_cur_cache = ['currency' => $currency, 'sym' => $sym];
    return $_cur_cache;
}

// ── Format nilai angka saja (tanpa symbol) — tidak query DB ──────────────────
function fmtVal(float $amount): string {
    $c = _getCurCache();
    switch ($c['currency']) {
        case 'USD': return number_format($amount / floatval(getSetting('usd_rate', 15000)), 2, '.', ',');
        case 'CNY': return number_format($amount / floatval(getSetting('cny_rate', 2100)),  2, '.', ',');
        default:    return number_format($amount, 0, ',', '.');
    }
}

// ── Format angka MURNI untuk Excel — tanpa symbol, tanpa pemisah ribuan ─────
// [BARU] Dipakai HANYA oleh Excel export dataset 'purchases' dan
// 'purchase_view' (halaman purchases_invoice.php). Nilai tetap mengikuti mata
// uang aktif di database (multi-currency), namun dikeluarkan sebagai angka
// mentah supaya Excel membacanya sebagai NUMBER, bukan teks:
//   IDR -> 1500000        (0 desimal, tanpa titik ribuan)
//   USD -> 100.00         (2 desimal, titik = pemisah desimal)
//   CNY -> 714.29         (2 desimal, titik = pemisah desimal)
// Kurs di-cache statis sekali per request, jadi tidak ada query DB di dalam
// loop meskipun barisnya ribuan.
function fmtNum(float $amount): string {
    static $rate = null, $dec = 0;
    if ($rate === null) {
        switch (_getCurCache()['currency']) {
            case 'USD': $rate = floatval(getSetting('usd_rate', 15000)); $dec = 2; break;
            case 'CNY': $rate = floatval(getSetting('cny_rate', 2100));  $dec = 2; break;
            default:    $rate = 1.0;                                     $dec = 0; break;
        }
        if ($rate <= 0) $rate = 1.0;
    }
    return number_format($amount / $rate, $dec, '.', '');
}

// ── Render <table> mini currency cell: symbol kiri, nilai kanan ──────────────
function curCell(float $amount): string {
    $sym = _getCurCache()['sym'];
    $val = fmtVal($amount);
    return '<table class="cur-cell"><tr>'
         . '<td class="cur-sym">' . htmlspecialchars($sym) . '</td>'
         . '<td class="cur-val">' . htmlspecialchars($val) . '</td>'
         . '</tr></table>';
}

// ─────────────────────────────────────────────────────────────────────────────
// EXCEL EXPORT
// ─────────────────────────────────────────────────────────────────────────────
if ($type === 'excel') {

    // [DIUBAH] Bersihkan sisa output buffer milik config.php (ob_start di baris 2)
    // supaya BOM UTF-8 di bawah benar-benar menjadi byte pertama berkas.
    if (ob_get_length()) { ob_clean(); }

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $dataType . '_report_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');

    // [DIUBAH] BOM UTF-8. Excel mengabaikan <meta charset> pada berkas .xls
    // berbasis HTML dan jatuh ke ANSI/lokal, sehingga aksara Han tampil sebagai
    // kotak-kotak. BOM memaksa Excel membuka berkas sebagai UTF-8.
    echo "\xEF\xBB\xBF";

    echo '<!DOCTYPE html>
<html xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<style>
body { font-family: Arial, "Microsoft YaHei", "微软雅黑", SimSun, "宋体", "Noto Sans CJK SC", sans-serif; font-size: 11px; }
h2 { text-align: center; color: #333; margin-bottom: 5px; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #000; padding: 6px; text-align: left; font-family: Arial, "Microsoft YaHei", "微软雅黑", SimSun, "宋体", "Noto Sans CJK SC", sans-serif; }
th { background-color: #4CAF50; color: white; font-weight: bold; text-align: center; font-size: 10px; }
td { font-size: 10px; vertical-align: middle; }
.text-right  { text-align: right; }
.text-center { text-align: center; }
.total-row { background-color: #f0f0f0; font-weight: bold; }
.filter-info { background-color: #f8f9fa; padding: 8px; margin-bottom: 10px; border: 1px solid #dee2e6; }
.info { font-size: 9px; margin-bottom: 8px; }
.cur-cell { width: 100%; border-collapse: collapse; }
.cur-sym { text-align: left; white-space: nowrap; font-size: 9px; padding-right: 2px; vertical-align: middle; }
.cur-val { text-align: right; white-space: nowrap; vertical-align: middle; }
</style>
</head>
<body>';

    // ---- PURCHASES ----
    if ($dataType === 'purchases') {
        echo '<h2>' . L('purchases_report', 'Purchases Report') . '</h2>';

        echo '<div class="filter-info"><strong>' . L('filter', 'Filter') . ':</strong><ul>';
        $has_any = false;
        if ($from_date !== '1970-01-01' || $to_date !== date('Y-m-d')) {
            echo '<li>' . L('export_filter_date', 'Date Range') . ': ' . htmlspecialchars($from_date) . ' s/d ' . htmlspecialchars($to_date) . '</li>';
            $has_any = true;
        }
        if ($item_name)        { echo '<li>' . L('export_item_name_label', 'Item Name') . ': '    . htmlspecialchars($item_name)        . '</li>'; $has_any = true; }
        if ($label_supplier)   { echo '<li>' . L('export_supplier_label', 'Supplier') . ': '      . htmlspecialchars($label_supplier)   . '</li>'; $has_any = true; }
        if ($label_payment)    { echo '<li>' . L('export_payment_label', 'Payment Method') . ': ' . htmlspecialchars($label_payment)    . '</li>'; $has_any = true; }
        if ($label_payment_by) { echo '<li>' . L('export_payment_by_label', 'Payment By') . ': '  . htmlspecialchars($label_payment_by) . '</li>'; $has_any = true; }
        if (!$has_any) echo '<li><em>' . L('export_no_filter', 'All Data (no filter applied)') . '</em></li>';
        echo '</ul></div>';

        echo '<div class="info">' . L('printed_at', 'Printed at') . ': ' . date('d/m/Y H:i:s') . '</div>';

        echo '<table><thead><tr>';
        echo '<th>' . L('col_no', 'No') . '</th>';
        echo '<th>' . L('input_date', 'Input Date') . '</th>';
        echo '<th>' . L('invoice_date', 'Invoice Date') . '</th>';
        echo '<th>' . L('item_name', 'Item Name') . '</th>';
        echo '<th>' . L('category', 'Category') . '</th>';
        echo '<th>' . L('supplier', 'Supplier') . '</th>';
        echo '<th>' . L('quantity', 'Qty') . '</th>';
        echo '<th>' . L('unit', 'Unit') . '</th>';
        echo '<th>' . L('unit_price', 'Unit Price') . '</th>';
        echo '<th>' . L('discount', 'Discount') . '</th>';
        echo '<th>' . L('price_rounding', 'Price Rounding') . '</th>';
        echo '<th>' . L('tax', 'Tax') . '</th>';
        echo '<th>' . L('total_price', 'Total Price') . '</th>';
        echo '<th>' . L('payment_method', 'Payment Method') . '</th>';
        echo '<th>' . L('payment_by', 'Payment By') . '</th>';
        echo '</tr></thead><tbody>';

        // Pre-cache currency symbol sekali sebelum loop
        _getCurCache();
        $no          = 1;
        $grand_total = 0.0;

        // [BARU] Penyesuaian level NOTA (header). Kolom overall_discount,
        // overall_price_rounding dan tax tersimpan di tabel `purchases`, BUKAN
        // di `purchase_items`, sehingga tidak pernah ikut terjumlah ketika kita
        // menjumlahkan baris item. Diambil lewat SATU query agregat dengan
        // WHERE yang IDENTIK dengan daftar item di atas, dan SELECT DISTINCT
        // p.id supaya nota yang punya banyak item tetap dihitung SEKALI.
        // Hasilnya membuat grand total di sini sama persis dengan kolom Total
        // di layar purchases_invoice.php dan KPI "This Month" di dashboard.php.
        $agg_disc = 0.0; $agg_round = 0.0; $agg_tax = 0.0;
        $agg_sql  = "SELECT COALESCE(SUM(t.od), 0), COALESCE(SUM(t.opr), 0), COALESCE(SUM(t.tx), 0)
                       FROM (SELECT DISTINCT p.id,
                                    p.overall_discount       AS od,
                                    p.overall_price_rounding AS opr,
                                    p.tax                    AS tx
                               FROM purchase_items pi
                               JOIN purchases p ON pi.purchase_id = p.id
                               $where) t";
        if ($stmt_agg = $conn->prepare($agg_sql)) {
            $stmt_agg->bind_param($types, ...$params);
            $stmt_agg->execute();
            $stmt_agg->bind_result($_agg_d, $_agg_r, $_agg_t);
            if ($stmt_agg->fetch()) {
                $agg_disc  = (float)$_agg_d;
                $agg_round = (float)$_agg_r;
                $agg_tax   = (float)$_agg_t;
            }
            $stmt_agg->close();
        }

        // [BARU] Penanda nota yang nilai Tax-nya sudah dicetak. Tax adalah nilai
        // per NOTA, jadi hanya ditampilkan pada baris item PERTAMA dari nota
        // tersebut — baris berikutnya diisi '-'. Dengan begitu kolom Tax bila
        // dijumlahkan di Excel hasilnya tepat sama dengan baris ringkasan Tax.
        $seen_tax_pid = [];

        while ($stmt_purchases->fetch()) {
            $pm  = trim($_p_pmeth ?? ''); if (!$pm) $pm = 'Cash';
            $sym = trim($_p_usym ?? $_p_uname ?? '');
            // [DIUBAH] Quantity dipecah menjadi dua kolom terpisah: Qty (angka
            // murni) dan Unit (simbol satuan, jatuh ke nama satuan bila simbol
            // kosong) — sebelumnya digabung dalam satu sel "5 Kg".
            $qty  = formatQty($_p_qty);
            $unit = $sym !== '' ? $sym : '-';
            // [BARU] Tax dicetak sekali per nota (lihat catatan $seen_tax_pid).
            $_pid = (int)$_p_pid;
            if (isset($seen_tax_pid[$_pid])) {
                $tax_cell = '-';
            } else {
                $seen_tax_pid[$_pid] = true;
                $tax_cell = fmtNum((float)$_p_tax);
            }
            // Pre-format tanggal — date() lebih cepat dari strtotime() + date()
            $idate   = $_p_idate   ? date('d/m/Y', strtotime($_p_idate))   : '-';
            $invdate = $_p_invdate ? date('d/m/Y', strtotime($_p_invdate)) : '-';
            echo '<tr>'
                . '<td class="text-center">'  . $no++ . '</td>'
                . '<td class="text-center">'  . $idate . '</td>'
                . '<td class="text-center">'  . $invdate . '</td>'
                . '<td>'                       . htmlspecialchars($_p_item) . '</td>'
                . '<td>'                       . htmlspecialchars($_p_cat ?? '-') . '</td>'
                . '<td>'                       . htmlspecialchars($_p_sup ?? '-') . '</td>'
                . '<td class="text-right">'   . htmlspecialchars($qty) . '</td>'
                . '<td class="text-center">'  . htmlspecialchars($unit) . '</td>'
                // [DIUBAH] fmtNum() menggantikan curCell(): tidak ada tabel mini
                // di dalam sel, sehingga kolom simbol (Rp) tidak lagi terbentuk,
                // dan angkanya tanpa pemisah ribuan.
                . '<td class="text-right">'   . fmtNum((float)$_p_uprice) . '</td>'
                . '<td class="text-right">'   . fmtNum((float)$_p_disc) . '</td>'
                . '<td class="text-right">'   . fmtNum((float)$_p_round) . '</td>'
                . '<td class="text-right">'   . $tax_cell . '</td>'
                . '<td class="text-right">'   . fmtNum((float)$_p_total) . '</td>'
                . '<td class="text-center">'  . htmlspecialchars($pm) . '</td>'
                . '<td>'                       . htmlspecialchars($_p_pby ?? '-') . '</td>'
                . '</tr>';
            $grand_total += (float)$_p_total;
        }
        $stmt_purchases->close();

        // [DIUBAH] Baris ringkasan + grand total.
        // Total kolom kini 15 (No .. Payment By); nilai berada di kolom ke-13
        // (Total Price), sehingga label memakai colspan 12 dan sisanya 2.
        // Rumus: Subtotal Item - Overall Discount + Overall Price Rounding + Tax
        // — identik dengan grand_total di includes/purchases_function.php dan
        // dashGrandTotal() di dashboard.php.
        // Overall Discount ditulis negatif supaya kolom nilai bila dijumlahkan
        // di Excel menghasilkan Grand Total yang sama persis.
        $final_total = $grand_total - $agg_disc + $agg_round + $agg_tax;

        echo '<tr class="total-row">'
            . '<td colspan="12" class="text-right">' . L('subtotal', 'Subtotal') . ':</td>'
            . '<td class="text-right">' . fmtNum($grand_total) . '</td>'
            . '<td colspan="2"></td>'
            . '</tr>';
        echo '<tr class="total-row">'
            . '<td colspan="12" class="text-right">' . L('overall_discount', 'Overall Discount') . ':</td>'
            . '<td class="text-right">' . fmtNum(-$agg_disc) . '</td>'
            . '<td colspan="2"></td>'
            . '</tr>';
        echo '<tr class="total-row">'
            . '<td colspan="12" class="text-right">' . L('purchase_overall_price_rounding', 'Overall Price Rounding') . ':</td>'
            . '<td class="text-right">' . fmtNum($agg_round) . '</td>'
            . '<td colspan="2"></td>'
            . '</tr>';
        echo '<tr class="total-row">'
            . '<td colspan="12" class="text-right">' . L('tax', 'Tax') . ':</td>'
            . '<td class="text-right">' . fmtNum($agg_tax) . '</td>'
            . '<td colspan="2"></td>'
            . '</tr>';
        echo '<tr class="total-row">'
            . '<td colspan="12" class="text-right">' . L('grand_total', 'Grand Total') . ':</td>'
            . '<td class="text-right">' . fmtNum($final_total) . '</td>'
            . '<td colspan="2"></td>'
            . '</tr>';
        echo '</tbody></table>';

        // [BARU] Catatan cara perhitungan — ditulis dengan bahasa sederhana
        // supaya pembaca yang tidak terbiasa dengan istilah akuntansi pun
        // langsung paham dari mana angka Grand Total berasal. Memakai class
        // .filter-info yang SUDAH ADA, jadi tidak ada CSS baru.
        echo '<div class="filter-info"><strong>' . L('export_calc_note_title', 'How this total is calculated') . '</strong><ul>';
        echo '<li>' . L('export_calc_note_subtotal', 'Subtotal is the sum of every Total Price in the item rows above.') . '</li>';
        echo '<li>' . L('export_calc_note_discount', 'Overall Discount is a discount given for the whole invoice, not per item. It is written as a minus because it reduces the total.') . '</li>';
        echo '<li>' . L('export_calc_note_rounding', 'Overall Price Rounding is a rounding adjustment applied to the whole invoice. It can be plus or minus.') . '</li>';
        echo '<li>' . L('export_calc_note_tax', 'Tax is charged per invoice, so it is shown only once on the first item row of each invoice. A row marked "-" is not tax free; its tax was already counted on the first row of that invoice.') . '</li>';
        echo '<li>' . L('export_calc_note_formula', 'Grand Total = Subtotal - Overall Discount + Overall Price Rounding + Tax.') . '</li>';
        echo '<li>' . L('export_calc_note_number', 'All amounts are written as plain numbers without thousand separators so you can add them up directly in Excel.') . '</li>';
        echo '<li>' . L('export_calc_note_match', 'This Grand Total matches the Total column on the Purchase Invoice page and the dashboard figure for the same date range.') . '</li>';
        echo '</ul></div>';


    // ---- RETURNS ----
    } elseif ($dataType === 'returns') {
        echo '<h2>' . L('returns_report', 'Returns Report') . '</h2>';
        echo '<div class="info">' . L('printed_at', 'Printed at') . ': ' . date('d/m/Y H:i:s') . '</div>';
        echo '<table><thead><tr>';
        echo '<th>' . L('col_no', 'No') . '</th>';
        echo '<th>' . L('pr_return_number', 'Return Number') . '</th>';
        echo '<th>' . L('po_number', 'PO Number') . '</th>';
        echo '<th>' . L('gr_number', 'GR Number') . '</th>';
        echo '<th>' . L('supplier', 'Supplier') . '</th>';
        echo '<th>' . L('return_date', 'Return Date') . '</th>';
        echo '<th>' . L('total', 'Total') . '</th>';
        echo '<th>' . L('status', 'Status') . '</th>';
        echo '<th>' . L('created_by', 'Created By') . '</th>';
        echo '</tr></thead><tbody>';
        $no = 1;
        if ($result_returns) {
        while ($r = $result_returns->fetch_assoc()) {
            echo '<tr>'
                . '<td class="text-center">' . $no++ . '</td>'
                . '<td>'                      . htmlspecialchars($r['return_number']) . '</td>'
                . '<td>'                      . htmlspecialchars($r['po_number'] ?: '-') . '</td>'
                . '<td>'                      . htmlspecialchars($r['gr_number'] ?: '-') . '</td>'
                . '<td>'                      . htmlspecialchars($r['supplier_name'] ?: '-') . '</td>'
                . '<td class="text-center">'  . htmlspecialchars($r['return_date']) . '</td>'
                . '<td class="text-right">'   . htmlspecialchars(fmtCurrency((float)$r['total_amount'])) . '</td>'
                . '<td class="text-center">'  . htmlspecialchars(pret_statusLabel((string)$r['status'])) . '</td>'
                . '<td>'                      . htmlspecialchars($r['created_by_name']) . '</td>'
                . '</tr>';
        }
        }
        echo '</tbody></table>';

    // ---- SUPPLIERS ----
    } elseif ($dataType === 'suppliers') {
        echo '<h2>' . L('suppliers_report', 'Suppliers Report') . '</h2>';
        echo '<div class="info">' . L('printed_at', 'Printed at') . ': ' . date('d/m/Y H:i:s') . '</div>';
        echo '<table><thead><tr>';
        echo '<th>' . L('col_no', 'No') . '</th>';
        echo '<th>' . L('supplier_name', 'Name') . '</th>';
        echo '<th>' . L('contact_person', 'Contact Person') . '</th>';
        echo '<th>' . L('phone', 'Phone') . '</th>';
        echo '<th>' . L('email', 'Email') . '</th>';
        echo '<th>' . L('address', 'Address') . '</th>';
        echo '<th>' . L('tax_number', 'Tax Number') . '</th>';
        echo '<th>' . L('postal_code', 'Postal Code') . '</th>';
        echo '<th>' . L('website', 'Website') . '</th>';
        echo '<th>' . L('payment_terms', 'Payment Terms') . '</th>';
        echo '<th>' . L('bank_name', 'Bank Name') . '</th>';
        echo '<th>' . L('bank_account', 'Bank Account') . '</th>';
        echo '<th>' . L('account_name', 'Account Name') . '</th>';
        echo '<th>' . L('credit_limit', 'Credit Limit') . '</th>';
        echo '<th>' . L('rating', 'Rating') . '</th>';
        echo '<th>' . L('status', 'Status') . '</th>';
        echo '</tr></thead><tbody>';
        $no = 1;
        while ($r = $result_suppliers->fetch_assoc()) {
            echo '<tr>'
                . '<td class="text-center">'  . $no++ . '</td>'
                . '<td>'                       . htmlspecialchars($r['name']) . '</td>'
                . '<td>'                       . htmlspecialchars($r['contact_person'] ?? '-') . '</td>'
                . '<td>'                       . htmlspecialchars($r['phone'] ?? '-') . '</td>'
                . '<td>'                       . htmlspecialchars($r['email'] ?? '-') . '</td>'
                . '<td>'                       . htmlspecialchars($r['address'] ?? '-') . '</td>'
                . '<td>'                       . htmlspecialchars($r['tax_number'] ?? '-') . '</td>'
                . '<td>'                       . htmlspecialchars($r['postal_code'] ?? '-') . '</td>'
                . '<td>'                       . htmlspecialchars($r['website'] ?? '-') . '</td>'
                . '<td>'                       . htmlspecialchars($r['payment_terms'] ?? '-') . '</td>'
                . '<td>'                       . htmlspecialchars($r['bank_name'] ?? '-') . '</td>'
                . '<td>'                       . htmlspecialchars($r['bank_account'] ?? '-') . '</td>'
                . '<td>'                       . htmlspecialchars($r['account_name'] ?? '-') . '</td>'
                . '<td class="text-right">'   . number_format(floatval($r['credit_limit']), 2, '.', ',') . '</td>'
                . '<td class="text-center">'  . htmlspecialchars($r['rating']) . '</td>'
                . '<td class="text-center">'  . ((int)$r['status'] === 1 ? L('active', 'Active') : L('inactive', 'Inactive')) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';

    // ---- CATEGORIES ----
    } elseif ($dataType === 'categories') {
        echo '<h2>' . L('categories_report', 'Categories Report') . '</h2>';
        echo '<div class="info">' . L('printed_at', 'Printed at') . ': ' . date('d/m/Y H:i:s') . '</div>';
        echo '<table><thead><tr>';
        echo '<th>' . L('col_no', 'No') . '</th>';
        echo '<th>' . L('name', 'Name') . '</th>';
        echo '<th>' . L('description', 'Description') . '</th>';
        echo '</tr></thead><tbody>';
        $no = 1;
        while ($r = $result_categories->fetch_assoc()) {
            echo '<tr>'
                . '<td class="text-center">' . $no++ . '</td>'
                . '<td>' . htmlspecialchars($r['name']) . '</td>'
                . '<td>' . nl2br(htmlspecialchars($r['description'] ?? '-')) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';

    // ---- UNITS ----
    } elseif ($dataType === 'units') {
        echo '<h2>' . L('units_report', 'Units Report') . '</h2>';
        echo '<div class="info">' . L('printed_at', 'Printed at') . ': ' . date('d/m/Y H:i:s') . '</div>';
        echo '<table><thead><tr>';
        echo '<th>' . L('col_no', 'No') . '</th>';
        echo '<th>' . L('name', 'Name') . '</th>';
        echo '<th>' . L('symbol', 'Symbol') . '</th>';
        echo '<th>' . L('description', 'Description') . '</th>';
        echo '</tr></thead><tbody>';
        $no = 1;
        while ($r = $result_units->fetch_assoc()) {
            echo '<tr>'
                . '<td class="text-center">' . $no++ . '</td>'
                . '<td>' . htmlspecialchars($r['name']) . '</td>'
                . '<td class="text-center">' . htmlspecialchars($r['symbol'] ?? '-') . '</td>'
                . '<td>' . nl2br(htmlspecialchars($r['description'] ?? '-')) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    }

    // ---- PURCHASE VIEW (single purchase) — format identik dengan purchases ----
    if ($dataType === 'purchase_view') {
        $pv = $pv_data['purchase'];

        echo '<h2>' . L('purchase_view_report', 'Purchase Detail Report') . ' #' . (int)$pv['id'] . '</h2>';

        echo '<div class="info">' . L('printed_at', 'Printed at') . ': ' . date('d/m/Y H:i:s') . '</div>';

        // Table header — identik dengan purchases
        echo '<table><thead><tr>';
        echo '<th>' . L('col_no',         'No')             . '</th>';
        echo '<th>' . L('input_date',     'Input Date')     . '</th>';
        echo '<th>' . L('invoice_date',   'Invoice Date')   . '</th>';
        echo '<th>' . L('item_name',      'Item Name')      . '</th>';
        echo '<th>' . L('category',       'Category')       . '</th>';
        echo '<th>' . L('supplier',       'Supplier')       . '</th>';
        echo '<th>' . L('quantity',       'Qty')            . '</th>';
        echo '<th>' . L('unit',           'Unit')           . '</th>';
        echo '<th>' . L('unit_price',     'Unit Price')     . '</th>';
        echo '<th>' . L('discount',       'Discount')       . '</th>';
        echo '<th>' . L('price_rounding', 'Price Rounding') . '</th>';
        echo '<th>' . L('tax',            'Tax')            . '</th>';
        echo '<th>' . L('total_price',    'Total Price')    . '</th>';
        echo '<th>' . L('payment_method', 'Payment Method') . '</th>';
        echo '<th>' . L('payment_by',     'Payment By')     . '</th>';
        echo '</tr></thead><tbody>';

        // Baris per item — identik dengan purchases
        _getCurCache();
        $no          = 1;
        $grand_total = 0.0;

        // [BARU] Penyesuaian level NOTA untuk satu nota ini. Nilainya sudah
        // tersedia di $pv (pf_getPurchaseData) sehingga tidak perlu query lagi.
        $agg_disc  = (float)($pv['overall_discount']       ?? 0);
        $agg_round = (float)($pv['overall_price_rounding'] ?? 0);
        $agg_tax   = (float)($pv['tax']                    ?? 0);

        // [BARU] Tax dicetak sekali per nota — sama seperti dataset 'purchases'.
        $seen_tax_pid = [];

        while ($stmt_purchases->fetch()) {
            $pm      = trim($_p_pmeth ?? ''); if (!$pm) $pm = 'Cash';
            $sym     = trim($_p_usym ?? $_p_uname ?? '');
            // [DIUBAH] Quantity dipecah menjadi dua kolom terpisah: Qty (angka
            // murni) dan Unit (simbol satuan, jatuh ke nama satuan bila simbol
            // kosong) — sebelumnya digabung dalam satu sel "5 Kg".
            $qty     = formatQty($_p_qty);
            $unit    = $sym !== '' ? $sym : '-';
            $_pid    = (int)$_p_pid;
            if (isset($seen_tax_pid[$_pid])) {
                $tax_cell = '-';
            } else {
                $seen_tax_pid[$_pid] = true;
                $tax_cell = fmtNum((float)$_p_tax);
            }
            $idate   = $_p_idate   ? date('d/m/Y', strtotime($_p_idate))   : '-';
            $invdate = $_p_invdate ? date('d/m/Y', strtotime($_p_invdate)) : '-';
            echo '<tr>'
                . '<td class="text-center">'  . $no++                               . '</td>'
                . '<td class="text-center">'  . $idate                              . '</td>'
                . '<td class="text-center">'  . $invdate                            . '</td>'
                . '<td>'                       . htmlspecialchars($_p_item)          . '</td>'
                . '<td>'                       . htmlspecialchars($_p_cat ?? '-')    . '</td>'
                . '<td>'                       . htmlspecialchars($_p_sup ?? '-')    . '</td>'
                . '<td class="text-right">'   . htmlspecialchars($qty)              . '</td>'
                . '<td class="text-center">'  . htmlspecialchars($unit)             . '</td>'
                // [DIUBAH] fmtNum() menggantikan curCell(): tidak ada tabel mini
                // di dalam sel, sehingga kolom simbol (Rp) tidak lagi terbentuk,
                // dan angkanya tanpa pemisah ribuan.
                . '<td class="text-right">'   . fmtNum((float)$_p_uprice)          . '</td>'
                . '<td class="text-right">'   . fmtNum((float)$_p_disc)            . '</td>'
                . '<td class="text-right">'   . fmtNum((float)$_p_round)           . '</td>'
                . '<td class="text-right">'   . $tax_cell                          . '</td>'
                . '<td class="text-right">'   . fmtNum((float)$_p_total)           . '</td>'
                . '<td class="text-center">'  . htmlspecialchars($pm)              . '</td>'
                . '<td>'                       . htmlspecialchars($_p_pby ?? '-')   . '</td>'
                . '</tr>';
            $grand_total += (float)$_p_total;
        }
        $stmt_purchases->close();

        // Grand total — baris ringkasan identik dengan dataset 'purchases'.
        // [DIUBAH] Total kolom 15, nilai di kolom ke-13 (Total Price):
        // label colspan 12, sisanya colspan 2.
        $final_total = $grand_total - $agg_disc + $agg_round + $agg_tax;

        echo '<tr class="total-row">'
            . '<td colspan="12" class="text-right">' . L('subtotal', 'Subtotal') . ':</td>'
            . '<td class="text-right">'               . fmtNum($grand_total) . '</td>'
            . '<td colspan="2"></td>'
            . '</tr>';
        echo '<tr class="total-row">'
            . '<td colspan="12" class="text-right">' . L('overall_discount', 'Overall Discount') . ':</td>'
            . '<td class="text-right">'               . fmtNum(-$agg_disc) . '</td>'
            . '<td colspan="2"></td>'
            . '</tr>';
        echo '<tr class="total-row">'
            . '<td colspan="12" class="text-right">' . L('purchase_overall_price_rounding', 'Overall Price Rounding') . ':</td>'
            . '<td class="text-right">'               . fmtNum($agg_round) . '</td>'
            . '<td colspan="2"></td>'
            . '</tr>';
        echo '<tr class="total-row">'
            . '<td colspan="12" class="text-right">' . L('tax', 'Tax') . ':</td>'
            . '<td class="text-right">'               . fmtNum($agg_tax) . '</td>'
            . '<td colspan="2"></td>'
            . '</tr>';
        echo '<tr class="total-row">'
            . '<td colspan="12" class="text-right">' . L('grand_total', 'Grand Total') . ':</td>'
            . '<td class="text-right">'               . fmtNum($final_total) . '</td>'
            . '<td colspan="2"></td>'
            . '</tr>';
        echo '</tbody></table>';

        // [BARU] Catatan cara perhitungan — ditulis dengan bahasa sederhana
        // supaya pembaca yang tidak terbiasa dengan istilah akuntansi pun
        // langsung paham dari mana angka Grand Total berasal. Memakai class
        // .filter-info yang SUDAH ADA, jadi tidak ada CSS baru.
        echo '<div class="filter-info"><strong>' . L('export_calc_note_title', 'How this total is calculated') . '</strong><ul>';
        echo '<li>' . L('export_calc_note_subtotal', 'Subtotal is the sum of every Total Price in the item rows above.') . '</li>';
        echo '<li>' . L('export_calc_note_discount', 'Overall Discount is a discount given for the whole invoice, not per item. It is written as a minus because it reduces the total.') . '</li>';
        echo '<li>' . L('export_calc_note_rounding', 'Overall Price Rounding is a rounding adjustment applied to the whole invoice. It can be plus or minus.') . '</li>';
        echo '<li>' . L('export_calc_note_tax', 'Tax is charged per invoice, so it is shown only once on the first item row of each invoice. A row marked "-" is not tax free; its tax was already counted on the first row of that invoice.') . '</li>';
        echo '<li>' . L('export_calc_note_formula', 'Grand Total = Subtotal - Overall Discount + Overall Price Rounding + Tax.') . '</li>';
        echo '<li>' . L('export_calc_note_number', 'All amounts are written as plain numbers without thousand separators so you can add them up directly in Excel.') . '</li>';
        echo '<li>' . L('export_calc_note_match', 'This Grand Total matches the Total column on the Purchase Invoice page and the dashboard figure for the same date range.') . '</li>';
        echo '</ul></div>';

    }

    echo '</body></html>';
    logActivity('export_excel', 'Exported ' . $dataType . ' data to Excel');

// ═══════════════════════════════════════════════════════════════════════════
//  CABANG PDF SUDAH DIHAPUS
//
//  Blok `elseif ($type === 'pdf')` sepanjang ±366 baris (memuat Dompdf,
//  menyusun HTML, dan mencetak berkas PDF) dihapus karena SELURUH tombol
//  Export PDF yang memanggilnya sudah tidak ada lagi:
//      purchases_invoice.php, purchase_view.php, goods_receipt_view.php,
//      returns.php, suppliers.php, categories.php, units.php
//  Tidak ada satu pun halaman yang memanggil endpoint ini dengan type=pdf.
//
//  CATATAN PENTING: folder assets/vendor/dompdf/ TETAP HARUS ADA.
//  Dompdf masih dipakai 12 berkas lain di luar modul ini — antara lain
//  payroll_slip_pdf.php, payroll_report.php, stock_opname_export_pdf.php,
//  export_inventory*.php, po_export_pdf.php, dan export_dreport.php.
//  JANGAN menghapus foldernya.
//
//  Permintaan dengan type selain 'excel' kini tidak menghasilkan apa pun
//  (tidak ada berkas terkirim) — perilaku yang sama seperti type tak dikenal
//  sebelum perubahan ini.
// ═══════════════════════════════════════════════════════════════════════════
}
