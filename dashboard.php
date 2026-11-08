<?php
require_once 'includes/config.php';

// ════════════════════════════════════════════════════════════════════════════
// DIRECT ACCESS GUARD — blokir jika config.php gagal di-load
// ════════════════════════════════════════════════════════════════════════════
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ════════════════════════════════════════════════════════════════════════════
// PERMISSION CHECK — sebelum include header untuk mencegah redirect loop
// ════════════════════════════════════════════════════════════════════════════
if (!isLoggedIn()) { redirect(url('index.php')); exit; }

// Cek permission dashboard dari DB (superadmin selalu lolos via _canAccess)
if (!_canAccess('dashboard', 'can_access')) {
    redirectNoAccess();
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// AJAX MODE DETECTION — when ?ajax=kpi, we skip header.php (no HTML output)
// and emit JSON instead. Computed lower in the file.
// ════════════════════════════════════════════════════════════════════════════
$_is_ajax = (isset($_GET['ajax']) && $_GET['ajax'] === 'kpi');

$page_title = $LANG['dashboard'];
if (!$_is_ajax) {
    include 'includes/header.php';
}

// ════════════════════════════════════════════════════════════════════════════
// FILTERS — month/year only (payment_by filter removed per request)
// ════════════════════════════════════════════════════════════════════════════
$filter_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$filter_year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($filter_month < 1 || $filter_month > 12) $filter_month = (int)date('m');
if ($filter_year  < 2020 || $filter_year > 2030) $filter_year = (int)date('Y');

$date_from   = sprintf('%04d-%02d-01', $filter_year, $filter_month);
$date_to     = date('Y-m-t', strtotime($date_from));
$month_label = date('F Y', strtotime($date_from));

$today            = date('Y-m-d');
$yesterday        = date('Y-m-d', strtotime('-1 day'));
$week_start       = date('Y-m-d', strtotime('monday this week'));
$last_week_start  = date('Y-m-d', strtotime('monday last week'));
$last_week_end    = date('Y-m-d', strtotime('sunday last week'));
$month_start      = date('Y-m-01');
$last_month_start = date('Y-m-01', strtotime('first day of last month'));
$last_month_end   = date('Y-m-t',  strtotime('last day of last month'));
$year_start       = date('Y-01-01');
$last_year_start  = date('Y-01-01', strtotime('-1 year'));
$last_year_end    = date('Y-12-31', strtotime('-1 year'));

// ════════════════════════════════════════════════════════════════════════════
// HELPERS — MySQLi prepared statements ($conn from config.php)
// ════════════════════════════════════════════════════════════════════════════

/** Return single scalar value. */
function dbVal($conn, string $sql, string $types = '', array $params = []): float {
    $stmt = $conn->prepare($sql);
    if ($types && $params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->bind_result($val);
    $stmt->fetch();
    $stmt->close();
    return $val ? (float)$val : 0.0;
}

/** Return all rows as associative array. */
function dbAll($conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if ($types && $params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

/** Format quantity: remove trailing zeros after decimal (e.g. 1.00→1, 1.10→1.1, 1.01→1.01). */
function fmtQty(float $val): string {
    return rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.');
}

// ════════════════════════════════════════════════════════════════════════════
// KPI — fixed ranges (today / week / month / year)
// REAL purchase total = SUM(items.total_price) − overall_discount + overall_price_rounding + tax
// Each query filters by date in WHERE before joining items (uses idx_input_date if present).
// ════════════════════════════════════════════════════════════════════════════

/** Compute SUM of per-purchase grand totals for a given date range.
 *  Pushes the WHERE into the outer query so we don't scan all purchases.
 *  Uses LEFT JOIN + GROUP BY p.id so MySQL can use the index on p.input_date. */
function dashGrandTotal(mysqli $conn, string $whereClause, string $types, array $params): float {
    $sql = "SELECT
              COALESCE(SUM(t.gt), 0)
            FROM (
              SELECT
                p.id,
                COALESCE((SELECT SUM(pi.total_price) FROM purchase_items pi WHERE pi.purchase_id = p.id), 0)
                - p.overall_discount + p.overall_price_rounding + p.tax AS gt
              FROM purchases p
              WHERE $whereClause
            ) t";
    return dbVal($conn, $sql, $types, $params);
}

$kpi_today      = dashGrandTotal($conn, "DATE(p.input_date) = ?",                's',  [$today]);
$kpi_yesterday  = dashGrandTotal($conn, "DATE(p.input_date) = ?",                's',  [$yesterday]);
$kpi_last_week  = dashGrandTotal($conn, "DATE(p.input_date) BETWEEN ? AND ?",   'ss', [$last_week_start, $last_week_end]);
$kpi_weekly     = dashGrandTotal($conn, "DATE(p.input_date) >= ?",               's',  [$week_start]);
$kpi_last_month = dashGrandTotal($conn, "DATE(p.input_date) BETWEEN ? AND ?",   'ss', [$last_month_start, $last_month_end]);
$kpi_monthly    = dashGrandTotal($conn, "DATE(p.input_date) >= ?",               's',  [$month_start]);
$kpi_yearly     = dashGrandTotal($conn, "DATE(p.input_date) >= ?",               's',  [$year_start]);
$kpi_last_year  = dashGrandTotal($conn, "DATE(p.input_date) BETWEEN ? AND ?",   'ss', [$last_year_start, $last_year_end]);

$txn_today      = (int)dbVal($conn, "SELECT COUNT(*) FROM purchases WHERE DATE(input_date)=?",               's',  [$today]);
$txn_yesterday  = (int)dbVal($conn, "SELECT COUNT(*) FROM purchases WHERE DATE(input_date)=?",               's',  [$yesterday]);
$txn_last_week  = (int)dbVal($conn, "SELECT COUNT(*) FROM purchases WHERE DATE(input_date) BETWEEN ? AND ?", 'ss', [$last_week_start, $last_week_end]);
$txn_weekly     = (int)dbVal($conn, "SELECT COUNT(*) FROM purchases WHERE DATE(input_date)>=?",               's',  [$week_start]);
$txn_last_month = (int)dbVal($conn, "SELECT COUNT(*) FROM purchases WHERE DATE(input_date) BETWEEN ? AND ?", 'ss', [$last_month_start, $last_month_end]);
$txn_monthly    = (int)dbVal($conn, "SELECT COUNT(*) FROM purchases WHERE DATE(input_date)>=?",               's',  [$month_start]);
$txn_yearly     = (int)dbVal($conn, "SELECT COUNT(*) FROM purchases WHERE DATE(input_date)>=?",               's',  [$year_start]);
$txn_last_year  = (int)dbVal($conn, "SELECT COUNT(*) FROM purchases WHERE DATE(input_date) BETWEEN ? AND ?", 'ss', [$last_year_start, $last_year_end]);

// KPI — Inventory
$inv_total_sku = (int)dbVal($conn, "SELECT COUNT(*) FROM inventory WHERE is_active = 1");
$inv_below_min = (int)dbVal($conn, "SELECT COUNT(*) FROM inventory WHERE is_active = 1 AND quantity < min_stock");
$inv_total_val = dbVal($conn,      "SELECT SUM(quantity * unit_price) FROM inventory");
$inv_in_month  = dbVal($conn,      "SELECT SUM(quantity) FROM inventory_in  WHERE transaction_date BETWEEN ? AND ?", 'ss', [$date_from, $date_to]);
$inv_out_month = dbVal($conn,      "SELECT SUM(quantity) FROM inventory_out WHERE transaction_date BETWEEN ? AND ?", 'ss', [$date_from, $date_to]);

// ════════════════════════════════════════════════════════════════════════════
// ANGKA RINGKAS UNTUK KARTU KPI
//
// formatCurrency() menghasilkan teks penuh seperti "Rp 1.115.288.135" — 17
// karakter. AdminLTE memberi .small-box h3 ukuran 2.2rem dengan white-space
// nowrap, sehingga angka sepanjang itu meluber di kolom col-xl-3. Helper ini
// memendekkannya; angka penuh tetap disediakan lewat atribut title.
//
// MODE PENGELOMPOKAN DIBACA DARI BERKAS BAHASA, bukan dideteksi dari sesi.
// Bahasa Mandarin mengelompokkan angka per 万 (10^4) dan 亿 (10^8), bukan per
// ribu/juta/miliar seperti Barat — "百万261,5" benar secara harfiah tapi tidak
// wajar dibaca. Dengan $LANG['num_compact_mode'] tiap bahasa menentukan
// aturannya sendiri dan fungsi ini tidak perlu tahu bahasa apa yang aktif.
// ════════════════════════════════════════════════════════════════════════════
function dashCompactMoney(float $amount): string {
    global $LANG;

    $currency = $_SESSION['currency'] ?? getSetting('currency', 'IDR');
    switch ($currency) {
        case 'USD':
            $val = $amount / max(floatval(getSetting('usd_rate', 15000)), 1);
            $sym = '$';
            break;
        case 'CNY':
            $val = $amount / max(floatval(getSetting('cny_rate', 2100)), 1);
            $sym = '¥';
            break;
        default:
            $val = $amount;
            $sym = 'Rp ';
            break;
    }

    $neg = $val < 0;
    $val = abs($val);

    if (($LANG['num_compact_mode'] ?? 'west') === 'cjk') {
        $tingkat = [
            [100000000.0, $LANG['num_yi']],
            [10000.0,     $LANG['num_wan']],
        ];
    } else {
        $tingkat = [
            [1000000000.0, $LANG['num_billion']],
            [1000000.0,    $LANG['num_million']],
            [1000.0,       $LANG['num_thousand']],
        ];
    }

    foreach ($tingkat as [$batas, $sufiks]) {
        if ($val >= $batas) {
            $n = $val / $batas;
            // Satu desimal di bawah 100, bulat di atasnya — menjaga lebar teks
            // tetap pendek tanpa kehilangan ketelitian yang berarti.
            $teks = number_format($n, $n < 100 ? 1 : 0, ',', '.');
            // Buang desimal nol yang mubazir: "90,0 万" -> "90 万", "1,0 M" -> "1 M".
            if (substr($teks, -2) === ',0') $teks = substr($teks, 0, -2);
            return ($neg ? '-' : '') . $sym . $teks . ' ' . $sufiks;
        }
    }

    $dec = ($currency === 'IDR') ? 0 : 2;
    return ($neg ? '-' : '') . $sym . number_format($val, $dec, ',', '.');
}

// ════════════════════════════════════════════════════════════════════════════
// DELTA ANTAR PERIODE
//
// Dipakai menggantikan empat kartu "periode sebelumnya" yang dulu berdiri
// sendiri: nilainya kini menempel sebagai sub-baris pada kartu periode
// berjalan, sehingga perbandingannya eksplisit dan tidak perlu dibaca
// silang antar baris.
//
// SENGAJA TIDAK DIWARNAI hijau/merah. Angka ini BELANJA, bukan pendapatan —
// naik belum tentu buruk (bisa penumpukan stok), turun belum tentu baik.
// Pewarnaan akan menyiratkan penilaian yang tidak dimiliki datanya.
// ════════════════════════════════════════════════════════════════════════════
function dashDeltaPct(float $now, float $prev): ?float {
    if ($prev <= 0) return null;           // pembagian nol / tidak ada pembanding
    return (($now - $prev) / $prev) * 100;
}

function dashDeltaText(?float $pct, string $periodLabel): string {
    global $LANG;
    if ($pct === null) return '';
    $panah = $pct > 0 ? '▲' : ($pct < 0 ? '▼' : '•');
    return $panah . ' ' . number_format(abs($pct), 1, ',', '.') . '% '
         . $LANG['compared_to'] . ' ' . $periodLabel;
}

$delta_today   = dashDeltaText(dashDeltaPct($kpi_today,   $kpi_yesterday),  $LANG['yesterday']);
$delta_weekly  = dashDeltaText(dashDeltaPct($kpi_weekly,  $kpi_last_week),  $LANG['last_week']);
$delta_monthly = dashDeltaText(dashDeltaPct($kpi_monthly, $kpi_last_month), $LANG['last_month']);
$delta_yearly  = dashDeltaText(dashDeltaPct($kpi_yearly,  $kpi_last_year),  $LANG['last_year']);

// ════════════════════════════════════════════════════════════════════════════
// PENDAPATAN (laporan kasir) — untuk grafik belanja vs pendapatan
//
// IZIN: modul dreport punya cakupan "Full Data vs Data Sendiri" lewat kolom
// permissions.can_view_all. Pengguna yang dibatasi ke laporannya sendiri tidak
// boleh melihat pendapatan seluruh perusahaan, jadi seluruh elemen pendapatan
// TIDAK DIRENDER untuk mereka — bukan sekadar disembunyikan.
// _permCanViewAll() mengembalikan true bila belum diatur, sehingga gerbang ini
// tidak menutup akses pengguna lama yang permissionnya belum disentuh.
//
// ATURAN SHIFT TERAKHIR: system_revenue adalah angka berjalan KUMULATIF yang
// dibaca kasir dari mesin POS, bukan nilai per shift. Total satu hari karena
// itu diambil dari shift TERAKHIR hari itu saja (start_time lalu id), bukan
// dijumlah seluruh shift. Reduksinya dilakukan DI PHP, bukan SQL, karena
// logika yang sama sudah tervalidasi di includes/export/export_dreport.php dan
// menghindari ketergantungan pada window function (butuh MariaDB 10.2+).
// ════════════════════════════════════════════════════════════════════════════
$dashCanRevenue   = checkPermission('dreport', 'can_access') && _permCanViewAll('dreport');
$revenue_by_month = [];    // 'YYYY-MM' => total
$revenue_period   = 0.0;   // total periode yang sedang dipilih

if ($dashCanRevenue) {
    $rev_rows = dbAll($conn,
        "SELECT dr.id,
                dr.report_date,
                dr.system_revenue,
                COALESCE(es.start_time,'99:99:99') AS start_time
         FROM manual_daily_reports dr
         LEFT JOIN employee_shifts es ON es.id = dr.shift_id
         WHERE dr.report_date >= ?",
        's', [$year_start]);

    $rev_best = [];   // report_date => ['t'=>start_time, 'i'=>id, 'v'=>nilai]
    foreach ($rev_rows as $r) {
        $day = (string)$r['report_date'];
        if ($day === '') continue;
        $t = (string)$r['start_time'];
        $i = (int)$r['id'];
        // Perbandingan EKSPLISIT per elemen, bukan mengandalkan perbandingan
        // array PHP — salah sedikit di sini menghasilkan angka yang salah
        // tanpa satu pun pesan error.
        $lebihAkhir = !isset($rev_best[$day])
            || strcmp($t, $rev_best[$day]['t']) > 0
            || ($t === $rev_best[$day]['t'] && $i > $rev_best[$day]['i']);
        if ($lebihAkhir) {
            $rev_best[$day] = ['t' => $t, 'i' => $i, 'v' => (float)$r['system_revenue']];
        }
    }

    foreach ($rev_best as $day => $best) {
        $mk = substr($day, 0, 7);
        $revenue_by_month[$mk] = ($revenue_by_month[$mk] ?? 0) + $best['v'];
        if ($day >= $date_from && $day <= $date_to) $revenue_period += $best['v'];
    }
}

// Rasio belanja terhadap pendapatan untuk PERIODE YANG DIPILIH FILTER.
// Sengaja tidak memakai $kpi_monthly: variabel itu berarti "bulan berjalan
// sampai hari ini", sedangkan kartu ini mengikuti bulan/tahun yang dipilih di
// filter atas ($date_from..$date_to). Keduanya berbeda begitu pengguna memilih
// bulan selain bulan ini.
$spend_period = dashGrandTotal($conn, "DATE(p.input_date) BETWEEN ? AND ?", 'ss', [$date_from, $date_to]);
$spend_ratio  = ($dashCanRevenue && $revenue_period > 0)
    ? ($spend_period / $revenue_period * 100)
    : null;

// ════════════════════════════════════════════════════════════════════════════
// AJAX endpoint — live KPI refresh (used by dashboard polling)
// Returns lightweight JSON, skips heavy chart / table queries below.
// ════════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode([
        'ok'        => true,
        'updated_at'=> date('d M Y H:i:s'),
        'kpi' => [
            // Nilai KPI dikirim dalam bentuk RINGKAS agar sama dengan yang
            // dirender saat halaman dimuat; angka penuhnya menyusul di
            // 'titles' di bawah supaya atribut title tidak jadi basi setelah
            // penyegaran.
            'kpi_today'      => dashCompactMoney($kpi_today),
            'kpi_weekly'     => dashCompactMoney($kpi_weekly),
            'kpi_monthly'    => dashCompactMoney($kpi_monthly),
            'kpi_yearly'     => dashCompactMoney($kpi_yearly),
            'inv_total_val'  => dashCompactMoney($inv_total_val),
            // Delta antar periode — menggantikan empat kartu lama.
            'delta_today'    => $delta_today,
            'delta_weekly'   => $delta_weekly,
            'delta_monthly'  => $delta_monthly,
            'delta_yearly'   => $delta_yearly,
            'inv_total_sku'  => number_format($inv_total_sku),
            'inv_below_min'  => number_format($inv_below_min),
            'inv_in_month'   => number_format($inv_in_month, 0),
            'inv_out_month'  => number_format($inv_out_month, 0),
            // Transaction counts
            'txn_today'      => (string)$txn_today,
            'txn_yesterday'  => (string)$txn_yesterday,
            'txn_last_week'  => (string)$txn_last_week,
            'txn_weekly'     => (string)$txn_weekly,
            'txn_last_month' => (string)$txn_last_month,
            'txn_monthly'    => (string)$txn_monthly,
            'txn_yearly'     => (string)$txn_yearly,
            'txn_last_year'  => (string)$txn_last_year,
        ],
        // Angka penuh untuk atribut title. Dipisah dari 'kpi' supaya loop
        // pembaruan yang sudah ada tidak ikut menuliskannya sebagai teks sel.
        'titles' => [
            'kpi_today'     => formatCurrency($kpi_today),
            'kpi_weekly'    => formatCurrency($kpi_weekly),
            'kpi_monthly'   => formatCurrency($kpi_monthly),
            'kpi_yearly'    => formatCurrency($kpi_yearly),
            'inv_total_val' => formatCurrency($inv_total_val),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// CHART DATA — based on selected month filter
// ════════════════════════════════════════════════════════════════════════════

// A. Daily purchase spend — selected month (real grand_total per purchase)
$daily_raw = dbAll($conn,
    "SELECT DAY(p.input_date) AS day,
            SUM(
                COALESCE((SELECT SUM(pi.total_price) FROM purchase_items pi WHERE pi.purchase_id = p.id), 0)
                - p.overall_discount + p.overall_price_rounding + p.tax
            ) AS total
     FROM purchases p
     WHERE DATE(p.input_date) BETWEEN ? AND ?
     GROUP BY DAY(p.input_date) ORDER BY DAY(p.input_date)",
    'ss', [$date_from, $date_to]);
$days_in_month   = (int)date('t', strtotime($date_from));
$daily_map       = [];
foreach ($daily_raw as $r) $daily_map[(int)$r['day']] = (float)$r['total'];
$chart_days      = range(1, $days_in_month);
$chart_daily_val = array_map(fn($d) => $daily_map[$d] ?? 0, $chart_days);

// B. Monthly trend — current year (real grand_total per purchase)
$monthly_data = [];
for ($m = 1; $m <= 12; $m++) {
    $ym    = date('Y') . '-' . sprintf('%02d', $m);
    $total = dbVal($conn,
        "SELECT SUM(
            COALESCE((SELECT SUM(pi.total_price) FROM purchase_items pi WHERE pi.purchase_id = p.id), 0)
            - p.overall_discount + p.overall_price_rounding + p.tax
         ) FROM purchases p WHERE DATE_FORMAT(p.input_date,'%Y-%m')=?",
        's', [$ym]);
    $monthly_data[] = ['month' => date('M Y', strtotime($ym . '-01')), 'total' => $total];
}

// C. Category spend — selected month (name resolved via JOIN)
$category_data_month = dbAll($conn,
    "SELECT COALESCE(c.name,'Uncategorized') AS name, SUM(pi.total_price) AS total
     FROM purchase_items pi LEFT JOIN categories c ON pi.category_id=c.id
     JOIN purchases p ON pi.purchase_id=p.id
     WHERE DATE(p.input_date) BETWEEN ? AND ?
     GROUP BY pi.category_id, c.name ORDER BY total DESC",
    'ss', [$date_from, $date_to]);

// D. Category spend — current year (name resolved via JOIN)
$category_data_year = dbAll($conn,
    "SELECT COALESCE(c.name,'Uncategorized') AS name, SUM(pi.total_price) AS total
     FROM purchase_items pi LEFT JOIN categories c ON pi.category_id=c.id
     JOIN purchases p ON pi.purchase_id=p.id
     WHERE DATE(p.input_date)>=?
     GROUP BY pi.category_id, c.name ORDER BY total DESC",
    's', [$year_start]);



// G. Payment method (resolved: payment_methods.name via safe JOIN) — selected month, for donut chart
$pay_method_month = dbAll($conn,
    "SELECT
         CASE
             WHEN p.payment_method REGEXP '^[0-9]+$'
                  THEN COALESCE(pm.name, p.payment_method, 'Unknown')
             ELSE COALESCE(p.payment_method,'Unknown')
         END AS method,
         COUNT(*) AS cnt
     FROM purchases p
     LEFT JOIN payment_methods pm ON p.payment_method REGEXP '^[0-9]+$'
                                  AND pm.id=CAST(p.payment_method AS UNSIGNED)
     WHERE DATE(p.input_date) BETWEEN ? AND ?
     GROUP BY p.payment_method, pm.name ORDER BY cnt DESC",
    'ss', [$date_from, $date_to]);

// H. Top 8 suppliers (resolved: suppliers.name via JOIN) — selected month
$top_suppliers = dbAll($conn,
    "SELECT COALESCE(s.name,'Unknown') AS name, SUM(pi.total_price) AS total
     FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id
     JOIN purchase_items pi ON pi.purchase_id=p.id
     WHERE DATE(p.input_date) BETWEEN ? AND ?
     GROUP BY p.supplier_id, s.name ORDER BY total DESC LIMIT 10",
    'ss', [$date_from, $date_to]);

// I. Top 10 most purchased items (units.symbol resolved via JOIN) — selected month
$top_items = dbAll($conn,
    "SELECT pi.item_name,
            COALESCE(u.name,'') AS unit_name,
            COALESCE(u.symbol,'') AS unit_symbol,
            COALESCE(c.name,'Uncategorized') AS category_name,
            SUM(pi.quantity) AS total_qty,
            SUM(pi.total_price) AS total_spend,
            COUNT(*) AS freq
     FROM purchases p
     JOIN purchase_items pi ON pi.purchase_id=p.id
     LEFT JOIN units u ON u.id=pi.unit_id
     LEFT JOIN categories c ON c.id=pi.category_id
     WHERE DATE(p.input_date) BETWEEN ? AND ?
     GROUP BY pi.item_name, pi.unit_id, pi.category_id ORDER BY total_spend DESC LIMIT 10",
    'ss', [$date_from, $date_to]);

// J. Low stock items (category & unit names resolved via JOIN)
$low_stock = dbAll($conn,
    "SELECT i.product_code,
            i.item_name,
            i.quantity,
            i.min_stock,
            COALESCE(c.name,'—') AS category_name,
            COALESCE(u.name,'') AS unit_name,
            COALESCE(u.symbol,'') AS unit_symbol,
            COALESCE(wl.location_name,'—') AS location_name
     FROM inventory i
     LEFT JOIN categories c ON c.id=i.category_id
     LEFT JOIN units u ON u.id=i.unit_id
     LEFT JOIN warehouse_locations wl ON wl.id=i.location_id
     WHERE i.is_active = 1
       AND i.quantity < i.min_stock
     ORDER BY (i.quantity - i.min_stock) ASC LIMIT 20");

// K. Inventory IN/OUT daily — selected month
$io_raw = dbAll($conn,
    "SELECT 'in' AS type, DAY(transaction_date) AS day, SUM(quantity) AS qty
     FROM inventory_in WHERE transaction_date BETWEEN ? AND ? GROUP BY DAY(transaction_date)
     UNION ALL
     SELECT 'out', DAY(transaction_date), SUM(quantity)
     FROM inventory_out WHERE transaction_date BETWEEN ? AND ? GROUP BY DAY(transaction_date)",
    'ssss', [$date_from, $date_to, $date_from, $date_to]);
$io_in  = array_fill(0, $days_in_month, 0);
$io_out = array_fill(0, $days_in_month, 0);
foreach ($io_raw as $r) {
    $idx = (int)$r['day'] - 1;
    if ($r['type'] === 'in') $io_in[$idx]  = (float)$r['qty'];
    else                     $io_out[$idx] = (float)$r['qty'];
}

// L. Inventory SKU count per category (category name resolved)
$inv_by_cat = dbAll($conn,
    "SELECT COALESCE(c.name,'Uncategorized') AS cat, COUNT(*) AS cnt
     FROM inventory i LEFT JOIN categories c ON c.id=i.category_id
     GROUP BY i.category_id, c.name ORDER BY cnt DESC LIMIT 10");

// M. Recent 15 transactions — selected month
//    All FK fields resolved to human-readable names:
//    - supplier_id       → suppliers.name
//    - purchase_staff_id → users.full_name
//    - payment_by        → users.full_name if numeric ID, else use raw string value
//    - payment_method    → payment_methods.name if numeric ID, else raw string
$recent_txn = dbAll($conn,
    "SELECT p.id,
            p.invoice_date,
            p.input_date,
            COALESCE(s.name,'—') AS supplier_name,
            CASE
                WHEN p.purchase_staff_id REGEXP '^[0-9]+$'
                     THEN COALESCE(u_staff.full_name, p.purchase_staff_id, '—')
                ELSE COALESCE(NULLIF(p.purchase_staff_id,''),'—')
            END AS staff_name,
            CASE
                WHEN p.payment_by REGEXP '^[0-9]+$'
                     THEN COALESCE(u_payer.full_name, p.payment_by, '—')
                ELSE COALESCE(p.payment_by,'—')
            END AS payer_name,
            CASE
                WHEN p.payment_method REGEXP '^[0-9]+$'
                     THEN COALESCE(pm.name, p.payment_method, '—')
                ELSE COALESCE(p.payment_method,'—')
            END AS pay_method_name,
            (SUM(pi.total_price) - MAX(p.overall_discount) + MAX(p.overall_price_rounding) + MAX(p.tax)) AS total,
            COUNT(pi.id) AS item_count
     FROM purchases p
     LEFT JOIN suppliers s ON s.id=p.supplier_id
     LEFT JOIN users u_staff ON p.purchase_staff_id REGEXP '^[0-9]+$'
                             AND u_staff.id=CAST(p.purchase_staff_id AS UNSIGNED)
     LEFT JOIN users u_payer ON p.payment_by REGEXP '^[0-9]+$'
                             AND u_payer.id=CAST(p.payment_by AS UNSIGNED)
     LEFT JOIN payment_methods pm ON p.payment_method REGEXP '^[0-9]+$'
                                  AND pm.id=CAST(p.payment_method AS UNSIGNED)
     LEFT JOIN purchase_items pi ON pi.purchase_id=p.id
     WHERE DATE(p.input_date) BETWEEN ? AND ?
     GROUP BY p.id ORDER BY p.invoice_date DESC, p.id DESC LIMIT 15",
    'ss', [$date_from, $date_to]);



?>

<!-- Chart.js — LOCAL (no CDN) -->
<script src="<?= url('assets/vendor/chart/chart.umd.min.js') ?>"></script>
<script src="<?= url('assets/vendor/chart/chartjs-plugin-datalabels.min.js') ?>"></script>

<style>
/* ═════════════════════════════════════════════════════════════════════════
   DASHBOARD — mengikuti AdminLTE 4.9.1

   Blok ini sebelumnya ±242 baris karena MENULIS ULANG komponen yang sudah
   tersedia di assets/vendor/adminlte/css/adminlte.min.css — .small-box,
   .small-box-icon, .card — memakai penamaan AdminLTE 3 (.icon), lalu
   menambal dark mode sendiri dengan sekitar 60 warna heksadesimal yang
   dipatok mati (#1b2735, #2b3a4a, #e6edf3, ...).

   Sekarang komponen AdminLTE dipakai apa adanya. Yang tersisa hanya tiga
   kelompok, masing-masing dengan alasan yang jelas:

     (a) satu override yang DISENGAJA — ikon KPI tetap tampil di ponsel;
     (b) koordinasi jarak dengan gutter grid;
     (c) komponen bespoke yang tidak punya padanan di AdminLTE, diwarnai
         lewat variabel tema sehingga dark mode berjalan sendiri tanpa
         satu pun blok [data-bs-theme="dark"].
   ═════════════════════════════════════════════════════════════════════════ */

/* ───── (a) OVERRIDE DISENGAJA ─────────────────────────────────────────────
   AdminLTE 4 menyembunyikan ikon small-box di bawah 576px dan merata-
   tengahkan teksnya:
       @media (max-width:575.98px){
           .small-box{text-align:center}
           .small-box .small-box-icon{display:none}
       }
   Perilaku itu dibatalkan di sini secara sadar: dashboard ini sering dibuka
   dari ponsel dan ikon adalah pembeda antar kartu yang paling cepat dibaca.
   Ikonnya diperkecil dan digeser ke tengah vertikal supaya tetap muat. */
@media (max-width: 575.98px) {
    #dashboardRoot .small-box { text-align: left; }
    #dashboardRoot .small-box .small-box-icon {
        display: block;
        top: 50%;
        height: auto;
        font-size: 2.5rem;
        transform: translateY(-50%);
    }
    #dashboardRoot .small-box > .inner { padding-right: 3.25rem; }
}

/* ───── (b) KOORDINASI JARAK ───────────────────────────────────────────────
   Baris memakai gutter g-4. Komponen AdminLTE membawa margin-bottom sendiri
   (.small-box 1.25rem, .info-box 1rem), yang akan menggandakan jarak antar
   baris. Tinggi disamakan agar kartu dalam satu baris rata bawah. */
#dashboardRoot .row > [class*="col-"] { display: flex; }
#dashboardRoot .row > [class*="col-"] > * { width: 100%; }
#dashboardRoot .small-box,
#dashboardRoot .info-box,
#dashboardRoot .card { margin-bottom: 0; }

/* AdminLTE memberi .small-box display:block. Di sini kartu satu baris
   disamakan tingginya, sehingga tanpa kolom flex bagian .small-box-footer
   akan menggantung tepat di bawah teks dan menyisakan ruang kosong di dasar
   kartu yang lebih pendek. Kolom flex membuat footer menempel di dasar. */
#dashboardRoot .small-box { display: flex; flex-direction: column; }
#dashboardRoot .small-box > .inner { flex: 1 1 auto; }

/* Aksen ikon pada judul kartu — memakai variabel, bukan warna tetap. */
#dashboardRoot .card > .card-header .card-title i {
    color: var(--bs-primary);
    margin-right: .35rem;
}

/* Nilai KPI berubah halus saat disegarkan, tanpa berkedip. */
[data-kpi] { transition: color .25s ease, opacity .25s ease; }

/* ───── (c) KOMPONEN BESPOKE ───────────────────────────────────────────────
   Tidak ada padanannya di AdminLTE 4. Seluruh warnanya memakai variabel
   Bootstrap 5.3 (--bs-*-rgb, --bs-tertiary-bg, --bs-*-text-emphasis) yang
   sudah sadar tema, sehingga dark mode tidak perlu blok override sendiri. */

/* Wadah grafik — tinggi tetap agar tata letak tidak melompat saat render. */
.chart-area       { position: relative; height: 280px; }
.chart-area-tall  { position: relative; height: 320px; }
.chart-area-short { position: relative; height: 220px; }

/* Tabel transaksi terakhir */
.dash-table tbody tr { transition: background-color .12s ease; }
.dash-table tbody tr:hover { background-color: rgba(var(--bs-primary-rgb), .06); }
.dash-table .currency { font-variant-numeric: tabular-nums; }

/* Daftar batang ringkas (top supplier, top item, dsb.) */
.barlist { margin: 0; padding: 0; list-style: none; }
.barlist li {
    position: relative;
    padding: .6rem .75rem;
    margin-bottom: .35rem;
    border-radius: var(--bs-border-radius);
    background: var(--bs-tertiary-bg);
    overflow: hidden;
}
.barlist li:last-child { margin-bottom: 0; }
.barlist li .bar-fill {
    position: absolute; left: 0; top: 0; bottom: 0;
    background: linear-gradient(90deg,
                rgba(var(--bs-primary-rgb), .18),
                rgba(var(--bs-primary-rgb), .05));
    z-index: 0;
}
.barlist li .bar-content {
    position: relative; z-index: 1;
    display: flex; justify-content: space-between; align-items: center;
    gap: .5rem;
}
.barlist li .bar-name {
    font-weight: 500;
    text-overflow: ellipsis; white-space: nowrap; overflow: hidden;
    max-width: 65%;
}
.barlist li .bar-value { font-weight: 600; font-variant-numeric: tabular-nums; }

/* Indikator penyegaran langsung */
.live-indicator {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .72rem; padding: .15rem .55rem;
    border-radius: 999px;
    background: rgba(var(--bs-success-rgb), .12);
    color: var(--bs-success-text-emphasis);
    border: 1px solid rgba(var(--bs-success-rgb), .3);
}
.live-indicator .dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--bs-success);
    animation: livepulse 2s infinite;
}
.live-indicator.is-loading {
    background: rgba(var(--bs-warning-rgb), .12);
    border-color: rgba(var(--bs-warning-rgb), .3);
    color: var(--bs-warning-text-emphasis);
}
.live-indicator.is-loading .dot { background: var(--bs-warning); animation: none; }
.live-indicator.is-error {
    background: rgba(var(--bs-danger-rgb), .12);
    border-color: rgba(var(--bs-danger-rgb), .3);
    color: var(--bs-danger-text-emphasis);
}
.live-indicator.is-error .dot { background: var(--bs-danger); animation: none; }
@keyframes livepulse {
    0%   { box-shadow: 0 0 0 0 rgba(var(--bs-success-rgb), .5); }
    70%  { box-shadow: 0 0 0 8px rgba(var(--bs-success-rgb), 0); }
    100% { box-shadow: 0 0 0 0 rgba(var(--bs-success-rgb), 0); }
}

/* Kedip singkat saat sebuah nilai KPI baru saja berubah.
   .info-box ikut disebut karena empat kartu periode sebelumnya kini memakai
   komponen itu; tanpa ini efeknya diam-diam mati di keempat kartu tersebut.
   Bingkai akhir dikembalikan ke bayangan bawaan AdminLTE, bukan bayangan
   buatan sendiri, supaya kartu tidak berubah rupa setelah animasi. */
#dashboardRoot .small-box.just-updated,
#dashboardRoot .info-box.just-updated { animation: kpiFlash .8s ease; }
@keyframes kpiFlash {
    0%, 30% { box-shadow: 0 0 0 3px rgba(var(--bs-success-rgb), .45); }
    100%    { box-shadow: 0 0 1px rgba(var(--bs-body-color-rgb), .125),
                          0 1px 3px rgba(var(--bs-body-color-rgb), .2); }
}
</style>

<div class="container-fluid px-3 px-md-4 py-3" id="dashboardRoot">

    <!-- ═══════ PAGE HEADER + PERIOD FILTER ═════════════════════════════ -->
    <div class="page-title-bar">
        <div>
            <h2 class="page-title-text"><i class="bi bi-speedometer2"></i><?= $LANG['dashboard'] ?></h2>
            <div class="page-subtitle">
                <span class="live-indicator" id="liveIndicator">
                    <span class="dot"></span>
                    <span id="liveStatusText"><?= htmlspecialchars($LANG['live']) ?></span>
                </span>
                <span class="ms-2"><?= htmlspecialchars($LANG['last_updated']) ?>:
                    <span id="liveUpdatedAt"><?= date('d M Y H:i:s') ?></span>
                </span>
            </div>
        </div>
        <form method="GET" class="page-actions align-items-center">
            <label class="m-0 small text-muted"><i class="bi bi-calendar-event"></i> <?= $LANG['period_label'] ?>:</label>
            <select name="month" class="form-select form-select-sm" style="width:auto;">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $filter_month ? 'selected' : '' ?>>
                    <?= date('F', mktime(0,0,0,$m,1)) ?>
                </option>
                <?php endfor; ?>
            </select>
            <input type="number" name="year" value="<?= $filter_year ?>" min="2020" max="2030"
                   class="form-control form-control-sm" style="width:90px;">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-funnel"></i> <?= $LANG['apply'] ?>
            </button>
        </form>
    </div>

    <!-- ═══════ ROW 1: CURRENT PERIOD KPI (4 cards — Today/Week/Month/Year) ═════════ -->
    <div class="row g-4 mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="small-box text-bg-info h-100">
                <div class="inner">
                    <h3 data-kpi="kpi_today" title="<?= htmlspecialchars(formatCurrency($kpi_today)) ?>"><?= htmlspecialchars(dashCompactMoney($kpi_today)) ?></h3>
                    <p>
                        <?= htmlspecialchars($LANG['today']) ?>
                        &middot; <span data-kpi="txn_today"><?= $txn_today ?></span>
                        <?= htmlspecialchars($LANG['transactions']) ?>
                        <small data-kpi="delta_today"><?= htmlspecialchars($delta_today) ?></small>
                    </p>
                </div>
                <i class="bi bi-calendar-check small-box-icon"></i>
                <a class="small-box-footer" href="<?= url('purchases/pr_dahsboard_gate.php') ?>?tab=invoice&amp;from_date=<?= $today ?>&amp;to_date=<?= $today ?>">
                    <?= htmlspecialchars($LANG['view_all']) ?> <i class="bi bi-arrow-right-circle ms-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="small-box text-bg-primary h-100">
                <div class="inner">
                    <h3 data-kpi="kpi_weekly" title="<?= htmlspecialchars(formatCurrency($kpi_weekly)) ?>"><?= htmlspecialchars(dashCompactMoney($kpi_weekly)) ?></h3>
                    <p>
                        <?= htmlspecialchars($LANG['this_week']) ?>
                        &middot; <span data-kpi="txn_weekly"><?= $txn_weekly ?></span>
                        <?= htmlspecialchars($LANG['transactions']) ?>
                        <small data-kpi="delta_weekly"><?= htmlspecialchars($delta_weekly) ?></small>
                    </p>
                </div>
                <i class="bi bi-calendar2-week small-box-icon"></i>
                <a class="small-box-footer" href="<?= url('purchases/pr_dahsboard_gate.php') ?>?tab=invoice&amp;from_date=<?= $week_start ?>&amp;to_date=<?= $today ?>">
                    <?= htmlspecialchars($LANG['view_all']) ?> <i class="bi bi-arrow-right-circle ms-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="small-box text-bg-success h-100">
                <div class="inner">
                    <h3 data-kpi="kpi_monthly" title="<?= htmlspecialchars(formatCurrency($kpi_monthly)) ?>"><?= htmlspecialchars(dashCompactMoney($kpi_monthly)) ?></h3>
                    <p>
                        <?= htmlspecialchars($LANG['this_month']) ?>
                        &middot; <span data-kpi="txn_monthly"><?= $txn_monthly ?></span>
                        <?= htmlspecialchars($LANG['transactions']) ?>
                        <small data-kpi="delta_monthly"><?= htmlspecialchars($delta_monthly) ?></small>
                    </p>
                </div>
                <i class="bi bi-calendar-month small-box-icon"></i>
                <a class="small-box-footer" href="<?= url('purchases/pr_dahsboard_gate.php') ?>?tab=invoice&amp;from_date=<?= $month_start ?>&amp;to_date=<?= $today ?>">
                    <?= htmlspecialchars($LANG['view_all']) ?> <i class="bi bi-arrow-right-circle ms-1"></i>
                </a>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="small-box text-bg-secondary h-100">
                <div class="inner">
                    <h3 data-kpi="kpi_yearly" title="<?= htmlspecialchars(formatCurrency($kpi_yearly)) ?>"><?= htmlspecialchars(dashCompactMoney($kpi_yearly)) ?></h3>
                    <p>
                        <?= htmlspecialchars($LANG['this_year']) ?>
                        &middot; <span data-kpi="txn_yearly"><?= $txn_yearly ?></span>
                        <?= htmlspecialchars($LANG['transactions']) ?>
                        <small data-kpi="delta_yearly"><?= htmlspecialchars($delta_yearly) ?></small>
                    </p>
                </div>
                <i class="bi bi-calendar3 small-box-icon"></i>
                <a class="small-box-footer" href="<?= url('purchases/pr_dahsboard_gate.php') ?>?tab=invoice&amp;from_date=<?= $year_start ?>&amp;to_date=<?= $today ?>">
                    <?= htmlspecialchars($LANG['view_all']) ?> <i class="bi bi-arrow-right-circle ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- ═══════ ROW 2: INVENTORY KPI (5 cards) ═══════════════════════════ -->
    <div class="row g-4 mb-3">
        <div class="col-xl col-md-4 col-6">
            <div class="small-box text-bg-warning">
                <div class="inner">
                    <h3 data-kpi="inv_total_val" title="<?= htmlspecialchars(formatCurrency($inv_total_val)) ?>"><?= htmlspecialchars(dashCompactMoney($inv_total_val)) ?></h3>
                    <p><?= htmlspecialchars($LANG['inventory_value']) ?></p>
                </div>
                <i class="bi bi-cash-coin small-box-icon"></i>
            </div>
        </div>
        <div class="col-xl col-md-4 col-6">
            <div class="small-box text-bg-primary">
                <div class="inner">
                    <h3 data-kpi="inv_total_sku"><?= number_format($inv_total_sku) ?></h3>
                    <p><?= htmlspecialchars($LANG['total_sku']) ?></p>
                </div>
                <i class="bi bi-boxes small-box-icon"></i>
            </div>
        </div>
        <div class="col-xl col-md-4 col-6">
            <div class="small-box <?= $inv_below_min > 0 ? 'text-bg-danger' : 'text-bg-success' ?>">
                <div class="inner">
                    <h3 data-kpi="inv_below_min"><?= number_format($inv_below_min) ?></h3>
                    <p><?= htmlspecialchars($LANG['below_min_stock']) ?></p>
                </div>
                <i class="bi bi-exclamation-triangle small-box-icon"></i>
            </div>
        </div>
        <div class="col-xl col-md-6 col-6">
            <div class="small-box text-bg-info">
                <div class="inner">
                    <h3 data-kpi="inv_in_month"><?= number_format($inv_in_month, 0) ?></h3>
                    <p><?= htmlspecialchars($LANG['inventory_in_month']) ?></p>
                </div>
                <i class="bi bi-arrow-down-circle small-box-icon"></i>
            </div>
        </div>
        <div class="col-xl col-md-6 col-6">
            <div class="small-box text-bg-secondary">
                <div class="inner">
                    <h3 data-kpi="inv_out_month"><?= number_format($inv_out_month, 0) ?></h3>
                    <p><?= htmlspecialchars($LANG['inventory_out_month']) ?></p>
                </div>
                <i class="bi bi-arrow-up-circle small-box-icon"></i>
            </div>
        </div>
    </div>

    <?php if ($dashCanRevenue): ?>
    <!-- ═══════ BELANJA vs PENDAPATAN ════════════════════════════════════
         Satu-satunya tempat di dashboard yang menyandingkan pengeluaran
         (tabel purchases) dengan pemasukan (manual_daily_reports). Tanpa ini
         rasio seperti Juli 2026 — belanja 120,7% dari pendapatan — tidak
         pernah terlihat dari halaman mana pun.

         Hanya dirender untuk pengguna yang berhak atas data dreport dengan
         cakupan penuh; lihat $dashCanRevenue di bagian atas berkas.
         ══════════════════════════════════════════════════════════════════ -->
    <div class="row g-4 mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="bi bi-graph-up-arrow"></i>
                        <?= htmlspecialchars($LANG['spend_vs_revenue']) ?>
                        <?php if ($spend_ratio !== null): ?>
                        <small class="text-muted ms-1">
                            <?= htmlspecialchars($LANG['spend_ratio']) ?>:
                            <?= htmlspecialchars(number_format($spend_ratio, 1, ',', '.')) ?>%
                            &middot; <?= htmlspecialchars($month_label) ?>
                        </small>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($revenue_by_month)): ?>
                    <div class="chart-area-tall"><canvas id="chartSpendRevenue"></canvas></div>
                    <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-graph-up-arrow fs-1 d-block mb-2 opacity-50"></i>
                        <?= htmlspecialchars($LANG['no_data']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══════ ROW 3: TREND CHARTS (Daily + Monthly) ═══════════════════ -->
    <div class="row g-4 mb-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="bi bi-graph-up-arrow"></i>
                        <?= htmlspecialchars($LANG['daily_purchase_trend']) ?>
                        <small class="text-muted ms-1"><?= htmlspecialchars($month_label) ?></small>
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-area-tall"><canvas id="chartDaily"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="bi bi-bar-chart-line"></i>
                        <?= htmlspecialchars($LANG['monthly_purchase_trend']) ?>
                        <small class="text-muted ms-1"><?= date('Y') ?></small>
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-area-tall"><canvas id="chartMonthly"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════ ROW 4: CATEGORY + PAYMENT METHOD DONUTS ═════════════════ -->
    <div class="row g-4 mb-3">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="bi bi-pie-chart"></i>
                        <?= htmlspecialchars($LANG['by_category']) ?>
                    </h3>
                    <?php if (!empty($category_data_year)): ?>
                    <div class="card-tools">
                        <?php
                        /* Kedua dataset sudah dikirim ke JS saat halaman dimuat,
                           jadi pengalihan ini murni sisi klien — tanpa fetch,
                           tanpa muat ulang. $category_data_year sebelumnya
                           dihitung tiap muat halaman tetapi tidak pernah
                           dirender; sekarang ia terpakai. */
                        ?>
                        <div class="btn-group btn-group-sm" role="group" id="catScopeToggle">
                            <button type="button" class="btn btn-primary" data-scope="month">
                                <?= htmlspecialchars($LANG['this_month']) ?>
                            </button>
                            <button type="button" class="btn btn-secondary" data-scope="year">
                                <?= htmlspecialchars($LANG['this_year']) ?>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($category_data_month)): ?>
                    <div class="chart-area"><canvas id="chartCategory"></canvas></div>
                    <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-pie-chart fs-1 d-block mb-2 opacity-50"></i>
                        <?= htmlspecialchars($LANG['no_data']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="bi bi-credit-card"></i>
                        <?= htmlspecialchars($LANG['by_payment_method']) ?>
                    </h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($pay_method_month)): ?>
                    <div class="chart-area"><canvas id="chartPayMethod"></canvas></div>
                    <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-credit-card fs-1 d-block mb-2 opacity-50"></i>
                        <?= htmlspecialchars($LANG['no_data']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="bi bi-people"></i>
                        <?= htmlspecialchars($LANG['top_suppliers']) ?>
                    </h3>
                </div>
                <div class="card-body" style="max-height:340px; overflow-y:auto;">
                    <?php if (!empty($top_suppliers)):
                        $max_supplier = max(array_column($top_suppliers, 'total')) ?: 1;
                    ?>
                    <ul class="barlist">
                        <?php foreach ($top_suppliers as $sup):
                            $pct = ($sup['total'] / $max_supplier) * 100;
                        ?>
                        <li>
                            <span class="bar-fill" style="width: <?= $pct ?>%"></span>
                            <div class="bar-content">
                                <span class="bar-name" title="<?= htmlspecialchars($sup['name']) ?>">
                                    <?= htmlspecialchars($sup['name']) ?>
                                </span>
                                <span class="bar-value"><?= formatCurrency($sup['total']) ?></span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-people fs-1 d-block mb-2 opacity-50"></i>
                        <?= htmlspecialchars($LANG['no_data']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════ ROW 5: INVENTORY IN/OUT + INVENTORY BY CATEGORY ═════════ -->
    <div class="row g-4 mb-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="bi bi-arrow-left-right"></i>
                        <?= htmlspecialchars($LANG['inventory_in_out']) ?>
                        <small class="text-muted ms-1"><?= htmlspecialchars($month_label) ?></small>
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-area"><canvas id="chartInOut"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="bi bi-collection"></i>
                        <?= htmlspecialchars($LANG['inventory_by_category']) ?>
                    </h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($inv_by_cat)): ?>
                    <div class="chart-area"><canvas id="chartInvCat"></canvas></div>
                    <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-collection fs-1 d-block mb-2 opacity-50"></i>
                        <?= htmlspecialchars($LANG['no_data']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════ ROW 6: TOP ITEMS + LOW STOCK ═════════════════════════════ -->
    <div class="row g-4 mb-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="bi bi-trophy"></i>
                        <?= htmlspecialchars($LANG['top_items']) ?>
                        <small class="text-muted ms-1"><?= htmlspecialchars($month_label) ?></small>
                    </h3>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($top_items)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm dash-table mb-0">
                            <thead>
                                <tr>
                                    <th style="width:40px;">#</th>
                                    <th><?= htmlspecialchars($LANG['item_name']) ?></th>
                                    <th><?= htmlspecialchars($LANG['category']) ?></th>
                                    <th class="text-end"><?= htmlspecialchars($LANG['quantity']) ?></th>
                                    <th class="text-end"><?= htmlspecialchars($LANG['total']) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 0; foreach ($top_items as $it): $i++; ?>
                                <tr>
                                    <td class="text-muted"><?= $i ?></td>
                                    <td>
                                        <span class="fw-semibold"><?= htmlspecialchars($it['item_name']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($it['category_name']) ?></span>
                                    </td>
                                    <td class="text-end currency">
                                        <?= fmtQty((float)$it['total_qty']) ?>
                                        <small class="text-muted"><?= htmlspecialchars($it['unit_symbol'] ?: $it['unit_name']) ?></small>
                                    </td>
                                    <td class="text-end currency fw-semibold"><?= formatCurrency($it['total_spend']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-trophy fs-1 d-block mb-2 opacity-50"></i>
                        <?= htmlspecialchars($LANG['no_data']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-<?= !empty($low_stock) ? 'danger' : 'success' ?>">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="bi bi-exclamation-triangle text-<?= !empty($low_stock) ? 'danger' : 'success' ?>"></i>
                        <?= htmlspecialchars($LANG['low_stock_items']) ?>
                        <?php if (!empty($low_stock)): ?>
                            <span class="badge bg-danger ms-1"><?= count($low_stock) ?></span>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="card-body p-0" style="max-height:340px; overflow-y:auto;">
                    <?php if (!empty($low_stock)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm dash-table mb-0">
                            <thead>
                                <tr>
                                    <th><?= htmlspecialchars($LANG['item_name']) ?></th>
                                    <th class="text-end"><?= htmlspecialchars($LANG['quantity']) ?></th>
                                    <th class="text-end"><?= htmlspecialchars($LANG['min_stock']) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($low_stock as $ls): ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold"><?= htmlspecialchars($ls['item_name']) ?></span>
                                        <div class="small text-muted">
                                            <?= htmlspecialchars($ls['category_name']) ?>
                                            <?php if ($ls['location_name'] !== '—'): ?>
                                                · <?= htmlspecialchars($ls['location_name']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end currency text-danger fw-bold">
                                        <?= fmtQty((float)$ls['quantity']) ?>
                                    </td>
                                    <td class="text-end currency text-muted">
                                        <?= fmtQty((float)$ls['min_stock']) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5 text-success">
                        <i class="bi bi-check-circle-fill fs-1 d-block mb-2"></i>
                        <?= htmlspecialchars($LANG['all_stock_ok']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════ ROW 7: RECENT TRANSACTIONS ═══════════════════════════════ -->
    <div class="row g-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="bi bi-clock-history"></i>
                        <?= htmlspecialchars($LANG['recent_transactions']) ?>
                        <small class="text-muted ms-1"><?= htmlspecialchars($month_label) ?></small>
                    </h3>
                    <?php if (purchaseModuleAllowed('purchases')): ?>
                    <div class="card-tools">
                        <?php
                        /* purchases/purchases.php SUDAH DIHAPUS — pr_dahsboard_gate.php
                           kini satu-satunya hub Purchase, dan seluruh tautan lama ke
                           berkas itu sudah dialihkan ke sini. Dashboard terlewat pada
                           pembersihan tersebut, sehingga tombol ini menghasilkan 404.

                           Rentangnya mengikuti periode yang sedang ditampilkan kartu
                           ini ($date_from..$date_to, sama dengan query $recent_txn),
                           supaya "View All" benar-benar berarti seluruh transaksi
                           periode itu — bukan seluruh transaksi yang pernah ada. */
                        ?>
                        <a href="<?= url('purchases/pr_dahsboard_gate.php') ?>?tab=invoice&amp;from_date=<?= $date_from ?>&amp;to_date=<?= $date_to ?>"
                           class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-list-ul"></i>
                            <?= htmlspecialchars($LANG['view_all']) ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($recent_txn)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover dash-table mb-0">
                            <thead>
                                <tr>
                                    <th><?= htmlspecialchars($LANG['invoice_date']) ?></th>
                                    <th><?= htmlspecialchars($LANG['supplier']) ?></th>
                                    <th><?= htmlspecialchars($LANG['staff']) ?></th>
                                    <th><?= htmlspecialchars($LANG['payment_method']) ?></th>
                                    <th class="text-center"><?= htmlspecialchars($LANG['items']) ?></th>
                                    <th class="text-end"><?= htmlspecialchars($LANG['total']) ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_txn as $tx): ?>
                                <tr>
                                    <td>
                                        <div><?= htmlspecialchars(date('d M Y', strtotime($tx['invoice_date']))) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars(date('H:i', strtotime($tx['input_date']))) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($tx['supplier_name']) ?></td>
                                    <td>
                                        <i class="bi bi-person-circle text-muted"></i>
                                        <?= htmlspecialchars($tx['staff_name']) ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?= htmlspecialchars($tx['pay_method_name']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary"><?= (int)$tx['item_count'] ?></span>
                                    </td>
                                    <td class="text-end currency fw-semibold"><?= formatCurrency($tx['total']) ?></td>
                                    <td>
                                        <?php if (purchaseModuleAllowed('purchases')): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-info btn-view-purchase"
                                                data-id="<?= (int)$tx['id'] ?>"
                                                title="<?= htmlspecialchars($LANG['view']) ?>">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                        <?= htmlspecialchars($LANG['no_transactions_period']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<?php if (purchaseModuleAllowed('purchases')): ?>
<!-- ═══════════════════════════════════════════════════════════════════════
     PURCHASE VIEW MODAL (AJAX)

     Isinya diambil dari purchases/purchase_view.php?id=N&modal=1 — berkas itu
     sudah punya mode modal sendiri (baris 349) yang mengembalikan fragmen HTML
     tanpa kerangka halaman, dan punya penjaga izin sendiri yang membalas
     fragmen 403 bila user tidak berhak. Pola ini disalin dari
     purchases/purchases_invoice.php, bukan dibuat baru.

     Tanpa tombol Export: tombol itu memanggil pvModalExport() yang membaca
     window._pvLang, dan menyuntikkan objek itu hanya demi satu tombol
     menambah ketergantungan tanpa manfaat jelas di halaman ini.
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="dashPurchaseViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title mb-0 d-flex align-items-center">
                    <i class="bi bi-eye me-2 text-primary"></i><?= htmlspecialchars($LANG['view'] . ' ' . $LANG['purchase']) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars($LANG['close']) ?>"></button>
            </div>
            <div class="modal-body p-0">
                <div id="dashPvLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden"><?= htmlspecialchars($LANG['loading']) ?></span>
                    </div>
                    <p class="mt-2 text-muted"><?= htmlspecialchars($LANG['loading']) ?></p>
                </div>
                <div id="dashPvContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i><?= htmlspecialchars($LANG['close']) ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
/* ═══════════════════════════════════════════════════════════════════════
   PEMICU MODAL PURCHASE VIEW

   Ditulis VANILLA, bukan jQuery. includes/footer.php — yang memuat jQuery
   DAN bootstrap.bundle — di-include di baris terakhir berkas ini, sesudah
   seluruh skrip halaman. Menyalin pola $(document).on(...) dari
   purchases_invoice.php ke sini akan langsung gagal: "$ is not defined".

   Objek bootstrap.Modal karena itu juga dibuat MALAS di dalam handler, bukan
   saat skrip dibaca — pada saat itu bootstrap belum ada. Saat pengguna benar-
   benar mengklik, footer sudah dimuat.

   Delegasi dipasang di document supaya tetap bekerja setelah tabel digambar
   ulang oleh penyegaran KPI.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';
    var modalEl = document.getElementById('dashPurchaseViewModal');
    if (!modalEl) return;

    var loadingEl = document.getElementById('dashPvLoading');
    var contentEl = document.getElementById('dashPvContent');
    var viewUrl   = <?= json_encode(url('purchases/purchase_view.php')) ?>;
    var loadError = <?= json_encode((string)$LANG['load_error'], JSON_UNESCAPED_UNICODE) ?>;
    var modal     = null;

    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('.btn-view-purchase') : null;
        if (!btn) return;
        var id = btn.getAttribute('data-id');
        if (!id) return;

        if (!modal) modal = new bootstrap.Modal(modalEl);
        loadingEl.style.display = 'block';
        contentEl.innerHTML     = '';
        modal.show();

        fetch(viewUrl + '?id=' + encodeURIComponent(id) + '&modal=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(function (html) {
            loadingEl.style.display = 'none';
            contentEl.innerHTML     = html;
            /* innerHTML tidak pernah menjalankan tag skrip. Fragmen
               purchase_view membawa skripnya sendiri, jadi harus disalin ulang
               agar dieksekusi — sama seperti di purchases_invoice.php. */
            contentEl.querySelectorAll('script').forEach(function (el) {
                var ns = document.createElement('script');
                if (el.src) { ns.src = el.src; } else { ns.textContent = el.textContent; }
                document.head.appendChild(ns);
            });
        })
        .catch(function () {
            loadingEl.style.display = 'none';
            contentEl.innerHTML     = '<div class="alert alert-danger m-3">' + loadError + '</div>';
        });
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        contentEl.innerHTML     = '';
        loadingEl.style.display = 'block';
    });
})();
</script>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════
     CHART RENDERING — Chart.js
     ═══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    if (typeof Chart === 'undefined') return;

    // Theme-aware colors
    var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    var GRID   = isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
    var TEXT   = isDark ? '#cbd5e1'              : '#475569';

    Chart.defaults.font.family  = 'Source Sans 3, system-ui, sans-serif';
    Chart.defaults.font.size    = 11;
    Chart.defaults.color        = TEXT;
    Chart.defaults.plugins.legend.labels.color = TEXT;

    // Palette
    var PAL = ['#0d6efd', '#198754', '#fd7e14', '#dc3545', '#6610f2',
               '#0dcaf0', '#ffc107', '#20c997', '#6c757d', '#d63384'];

    function fmt(n) {
        if (n >= 1e9) return (n/1e9).toFixed(1) + 'B';
        if (n >= 1e6) return (n/1e6).toFixed(1) + 'M';
        if (n >= 1e3) return (n/1e3).toFixed(1) + 'K';
        return Math.round(n).toLocaleString();
    }

    // ─── Chart 1: Daily Purchase Trend (line+area) ─────────────────────
    var daily = document.getElementById('chartDaily');
    if (daily) {
        new Chart(daily, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_days) ?>,
                datasets: [{
                    label: '<?= addslashes($LANG['purchases']) ?>',
                    data: <?= json_encode($chart_daily_val) ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13,110,253,.15)',
                    borderWidth: 2,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#0d6efd',
                    fill: true,
                    tension: 0.35
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: { label: function (c) { return fmt(c.parsed.y); } }
                    }
                },
                scales: {
                    x: { grid: { color: GRID } },
                    y: { grid: { color: GRID }, ticks: { callback: fmt } }
                }
            }
        });
    }

    // ─── Chart 2: Monthly Trend (bar) ──────────────────────────────────
    var monthly = document.getElementById('chartMonthly');
    if (monthly) {
        new Chart(monthly, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($monthly_data, 'month')) ?>,
                datasets: [{
                    label: '<?= addslashes($LANG['monthly_purchase_trend']) ?>',
                    data:  <?= json_encode(array_map(fn($r) => (float)$r['total'], $monthly_data)) ?>,
                    backgroundColor: 'rgba(13,110,253,.7)',
                    borderRadius: 4,
                    maxBarThickness: 32
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (c) { return fmt(c.parsed.y); } } }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: GRID }, ticks: { callback: fmt } }
                }
            }
        });
    }

    // ─── Chart 3: Category Donut ───────────────────────────────────────
    var cat = document.getElementById('chartCategory');
    if (cat) {
        /* Dua dataset dikirim sekaligus saat halaman dimuat, sehingga
           pengalihan Bulan/Tahun tidak memerlukan permintaan jaringan. */
        var CAT_DATA = {
            month: {
                labels: <?= json_encode(array_column($category_data_month, 'name')) ?>,
                values: <?= json_encode(array_map(fn($r) => (float)$r['total'], $category_data_month)) ?>
            },
            year: {
                labels: <?= json_encode(array_column($category_data_year, 'name')) ?>,
                values: <?= json_encode(array_map(fn($r) => (float)$r['total'], $category_data_year)) ?>
            }
        };
        var catChart = new Chart(cat, {
            type: 'doughnut',
            data: {
                labels: CAT_DATA.month.labels,
                datasets: [{
                    data: CAT_DATA.month.values,
                    backgroundColor: PAL,
                    borderWidth: 2,
                    borderColor: isDark ? '#1b2735' : '#fff'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 8 } },
                    tooltip: { callbacks: { label: function (c) { return c.label + ': ' + fmt(c.parsed); } } }
                }
            }
        });

        var catToggle = document.getElementById('catScopeToggle');
        if (catToggle) {
            catToggle.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('button[data-scope]') : null;
                if (!btn) return;
                var d = CAT_DATA[btn.getAttribute('data-scope')];
                if (!d) return;
                /* Tombol aktif dibedakan dengan btn-primary vs btn-secondary —
                   keduanya SOLID, mengikuti konvensi tombol sistem ini. */
                catToggle.querySelectorAll('button[data-scope]').forEach(function (b) {
                    b.classList.remove('btn-primary', 'btn-secondary');
                    b.classList.add(b === btn ? 'btn-primary' : 'btn-secondary');
                });
                catChart.data.labels = d.labels;
                catChart.data.datasets[0].data = d.values;
                catChart.update();
            });
        }
    }

    /* ── Belanja vs Pendapatan ─────────────────────────────────────────────
       Hanya ada bila pengguna berhak atas data pendapatan; kanvasnya memang
       tidak dirender untuk yang lain, jadi pengecekan null sudah cukup. */
    var sr = document.getElementById('chartSpendRevenue');
    if (sr) {
        new Chart(sr, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($monthly_data, 'month')) ?>,
                datasets: [
                    {
                        label: <?= json_encode((string)$LANG['purchases'], JSON_UNESCAPED_UNICODE) ?>,
                        data: <?= json_encode(array_map(fn($r) => (float)$r['total'], $monthly_data)) ?>,
                        backgroundColor: PAL[0]
                    },
                    {
                        label: <?= json_encode((string)$LANG['revenue'], JSON_UNESCAPED_UNICODE) ?>,
                        data: <?= json_encode(array_map(
                                    fn($m) => (float)($revenue_by_month[date('Y') . '-' . sprintf('%02d', $m)] ?? 0),
                                    range(1, 12))) ?>,
                        backgroundColor: PAL[2]
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 8 } },
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + fmt(c.parsed.y); } } }
                },
                scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return fmt(v); } } } }
            }
        });
    }

    // ─── Chart 4: Payment Method Donut ─────────────────────────────────
    var pay = document.getElementById('chartPayMethod');
    if (pay) {
        new Chart(pay, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($pay_method_month, 'method')) ?>,
                datasets: [{
                    data: <?= json_encode(array_map(fn($r) => (int)$r['cnt'], $pay_method_month)) ?>,
                    backgroundColor: PAL,
                    borderWidth: 2,
                    borderColor: isDark ? '#1b2735' : '#fff'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 8 } }
                }
            }
        });
    }

    // ─── Chart 5: Inventory IN/OUT (grouped bars) ──────────────────────
    var io = document.getElementById('chartInOut');
    if (io) {
        new Chart(io, {
            type: 'bar',
            data: {
                labels: <?= json_encode(range(1, $days_in_month)) ?>,
                datasets: [
                    {
                        label: '<?= addslashes($LANG['in']) ?>',
                        data: <?= json_encode($io_in) ?>,
                        backgroundColor: 'rgba(25,135,84,.7)',
                        borderRadius: 3,
                        maxBarThickness: 14
                    },
                    {
                        label: '<?= addslashes($LANG['out']) ?>',
                        data: <?= json_encode($io_out) ?>,
                        backgroundColor: 'rgba(220,53,69,.7)',
                        borderRadius: 3,
                        maxBarThickness: 14
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: GRID } }
                }
            }
        });
    }

    // ─── Chart 6: Inventory by Category (horizontal bar) ───────────────
    var ic = document.getElementById('chartInvCat');
    if (ic) {
        new Chart(ic, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($inv_by_cat, 'cat')) ?>,
                datasets: [{
                    label: '<?= addslashes($LANG['total_sku']) ?>',
                    data:  <?= json_encode(array_map(fn($r) => (int)$r['cnt'], $inv_by_cat)) ?>,
                    backgroundColor: 'rgba(13,110,253,.7)',
                    borderRadius: 3
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: GRID } },
                    y: { grid: { display: false } }
                }
            }
        });
    }
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     LIVE KPI REFRESH — polls ?ajax=kpi every 60s, updates DOM in place.
     Pauses when tab is hidden. Degrades gracefully on network errors.
     ═══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';
    var REFRESH_MS = 60000;
    var indicator = document.getElementById('liveIndicator');
    var statusEl  = document.getElementById('liveStatusText');
    var timeEl    = document.getElementById('liveUpdatedAt');
    if (!indicator || !statusEl || !timeEl) return;

    function ajaxUrl() {
        var u = new URL(window.location.href);
        u.searchParams.set('ajax', 'kpi');
        return u.toString();
    }

    function setStatus(state, label) {
        indicator.classList.remove('is-loading', 'is-error');
        if (state === 'loading') indicator.classList.add('is-loading');
        if (state === 'error')   indicator.classList.add('is-error');
        statusEl.textContent = label;
    }

    function updateKpi(key, value) {
        var nodes = document.querySelectorAll('[data-kpi="' + key + '"]');
        nodes.forEach(function (n) {
            if (n.textContent !== value) {
                n.textContent = value;
                // '.info-box' ikut disebut: empat kartu periode sebelumnya
                // kini memakai komponen itu, dan tanpa ini closest() akan
                // mengembalikan null sehingga efek kedipnya diam-diam mati.
                var card = n.closest('.small-box, .info-box');
                if (card) {
                    card.classList.remove('just-updated');
                    void card.offsetWidth;  // restart animation
                    card.classList.add('just-updated');
                }
            }
        });
    }

    function refresh() {
        if (document.hidden) return;
        setStatus('loading', '<?= addslashes($LANG['refreshing']) ?>');
        fetch(ajaxUrl(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) throw 0; return r.json(); })
            .then(function (data) {
                if (!data || !data.ok || !data.kpi) throw 0;
                Object.keys(data.kpi).forEach(function (k) {
                    updateKpi(k, String(data.kpi[k]));
                });
                /* Angka penuh pada atribut title ikut disegarkan. Tanpa ini
                   title tetap memegang nilai saat halaman dimuat, sehingga
                   tooltip dan angka ringkas di kartu bisa berbeda. */
                if (data.titles) {
                    Object.keys(data.titles).forEach(function (k) {
                        document.querySelectorAll('[data-kpi="' + k + '"]').forEach(function (n) {
                            n.setAttribute('title', String(data.titles[k]));
                        });
                    });
                }
                if (data.updated_at) timeEl.textContent = data.updated_at;
                setStatus('ok', '<?= addslashes($LANG['live']) ?>');
            })
            .catch(function () {
                setStatus('error', '<?= addslashes($LANG['offline']) ?>');
            });
    }

    setTimeout(refresh, REFRESH_MS);
    setInterval(refresh, REFRESH_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})();
</script>

<?php include 'includes/footer.php'; ?>
