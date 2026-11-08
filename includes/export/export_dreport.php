<?php
// File: bltx/includes/export/export_dreport.php
// Cashier Report — PDF Export via dompdf
// Access: superadmin full control; non-superadmin requires can_export permission on dreport module.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../manual_dreport_functions.php';
/* PRASYARAT PENYATUAN CSRF — lihat RENCANA-PENYATUAN.md.
   Berkas ini memakai fungsi CSRF modulnya sendiri; begitu fungsi itu
   diteruskan ke bltx_csrf_*, includes/global_permission.php harus termuat.

   DIMUAT SETELAH berkas fungsi modul, dan itu WAJIB. Kedua berkas sama-sama
   mendefinisikan requireYesterdayCashViewAccess(), tetapi versi di
   manual_dreport_functions.php TIDAK berpagar function_exists. Kalau
   global_permission.php dimuat lebih dulu, definisi keduanya bentrok dan PHP
   berhenti dengan 'Cannot redeclare' -> HTTP 500.

   Urutan ini juga MENJAGA PERILAKU LAMA: implementasi yang menang tetap milik
   manual_dreport_functions.php (memakai _getDreportPerm()), bukan milik
   global_permission.php (memakai checkPermission()). Keduanya tidak identik,
   jadi pemenangnya tidak boleh berubah.

   Urutan tidak mempengaruhi ketersediaan bltx_csrf_*: PHP menyelesaikan
   panggilan fungsi saat dijalankan, bukan saat di-include. */
require_once __DIR__ . '/../global_permission.php';

// ── Access control ──────────────────────────────────────────────────────────
requireDreportAccess();

if (!isSuperAdmin() && !checkPermission('dreport', 'can_export')) {
    http_response_code(403);
    exit('Access denied.');
}

// ── isDreportManager (defined in manual_dreport.php; re-declare here if missing) ──
if (!function_exists('isDreportManager')) {
    function isDreportManager(): bool {
        $perm = _getDreportPerm();
        return (bool)$perm['can_update'];
    }
}

// ── Language ────────────────────────────────────────────────────────────────
$LANG = loadLanguage($_SESSION['lang'] ?? 'en');

// ── Filters (same as manual_dreport.php) ────────────────────────────────────
$filterDateFrom = sanitize($_GET['date_from']    ?? '');
$filterDateTo   = sanitize($_GET['date_to']      ?? '');
$filterShift    = (int)($_GET['shift_id']         ?? 0);
$filterCashier  = sanitize($_GET['cashier_name'] ?? '');

/* ── Jenis keluaran ──────────────────────────────────────────────────────────
   BAKUNYA 'pdf', BUKAN 'excel'. Berbeda dari export_stock_opname.php dan itu
   disengaja: tombol Export PDF yang sudah ada TIDAK mengirim parameter type
   sama sekali, begitu pula seluruh URL yang sudah terlanjur di-bookmark.
   Baku 'excel' akan membuat semuanya diam-diam mengunduh .xls. */
$exportType = strtolower(trim((string)($_GET['type'] ?? 'pdf')));
if (!in_array($exportType, ['excel', 'pdf'], true)) {
    $exportType = 'pdf';
}

/* ── Mode "hanya shift terakhir" ─────────────────────────────────────────────
   Dikendalikan checkbox di samping tombol export pada manual_dreport.php.

   PENTING — checkbox ini HANYA mengatur BARIS MANA yang dicetak. Ia TIDAK
   mengubah cara Grand Total System Revenue dihitung: grand total selalu
   menjumlahkan shift terakhir tiap hari saja, di kedua mode. Akibatnya angka
   grand total identik baik checkbox dicentang maupun tidak; yang berbeda cuma
   tingkat rinciannya. Lihat blok $lastShiftIds di bawah. */
$lastShiftOnly = !empty($_GET['last_shift_only']);

$filters = [
    'date_from'    => $filterDateFrom,
    'date_to'      => $filterDateTo,
    'shift_id'     => $filterShift > 0 ? $filterShift : '',
    'cashier_name' => $filterCashier !== '' ? $filterCashier : '',
];

// Non-superadmin kasir: restrict to own name — identik dengan manual_dreport.php baris 83-85.
// isDreportKasir() = can_access && can_create && !can_delete.
// User dengan can_export saja (tanpa can_update) tetap bisa lihat semua data.
if (!isSuperAdmin() && isDreportKasir()) {
    $filters['restrict_cashier_name'] = $_SESSION['full_name'] ?? '';
}

$reports = getDreportList($filters);

// ── dreportGetViewScope (defined in manual_dreport.php; re-declare here if missing) ──
// [BARU] Sama persis dengan dreportGetViewScope() di manual_dreport.php — permission
// cakupan data (Full Data / Data Sendiri) untuk modul dreport, 3-state:
//   • 'full' → admin EKSPLISIT pilih Full Data → override restriksi Kasir di atas.
//   • 'own'  → admin EKSPLISIT pilih Data Sendiri → filter ke laporan milik sendiri.
//   • null   → BELUM diatur → ikut restriksi Kasir otomatis di atas, tidak berubah.
if (!function_exists('dreportGetViewScope')) {
    function dreportGetViewScope(): ?string {
        if (isSuperAdmin()) return 'full';

        global $conn;
        $uid  = (int)($_SESSION['user_id'] ?? 0);
        $role = $_SESSION['role'] ?? '';
        if (!$conn || !$uid) return null;

        $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM permissions WHERE user_id = ?");
        if (!$chk) return null;
        $chk->bind_param("i", $uid);
        $chk->execute();
        $has_custom = (int)$chk->get_result()->fetch_assoc()['cnt'];
        $chk->close();

        if ($has_custom > 0) {
            $stmt = $conn->prepare("SELECT can_view_all FROM permissions WHERE user_id = ? AND module = 'dreport'");
            if (!$stmt) return null;
            $stmt->bind_param("i", $uid);
        } else {
            $stmt = $conn->prepare("SELECT can_view_all FROM permissions WHERE user_id IS NULL AND role = ? AND module = 'dreport'");
            if (!$stmt) return null;
            $stmt->bind_param("s", $role);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || $row['can_view_all'] === null) return null;
        return ((int)$row['can_view_all'] === 1) ? 'full' : 'own';
    }
}

// [BARU] Terapkan permission cakupan data — identik logic-nya dengan manual_dreport.php,
// supaya hasil export PDF SELALU konsisten dengan apa yang tampil di tabel utama
// (kalau seorang user hanya boleh lihat data sendiri di layar, PDF yang ia export
// juga harus hanya berisi data sendiri).
if (!isSuperAdmin()) {
    $dreportScope = dreportGetViewScope();
    $expUid       = (int)($_SESSION['user_id'] ?? 0);

    if ($dreportScope === 'full' && isset($filters['restrict_cashier_name'])) {
        $filtersFullOverride = $filters;
        unset($filtersFullOverride['restrict_cashier_name']);
        $reports = getDreportList($filtersFullOverride);
    } elseif ($dreportScope === 'own') {
        // Sama seperti manual_dreport.php: created_by_user kini berisi NAMA,
        // jadi pencocokan "Data Sendiri" memakai nama, bukan ID.
        $expName = '';
        if ($expUid > 0) {
            $stmtOwn = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            if ($stmtOwn) {
                $stmtOwn->bind_param('i', $expUid);
                $stmtOwn->execute();
                $rowOwn = $stmtOwn->get_result()->fetch_assoc();
                $stmtOwn->close();
                if ($rowOwn) $expName = (string)$rowOwn['full_name'];
            }
        }
        $expKey  = mb_strtolower(trim($expName), 'UTF-8');
        $reports = array_values(array_filter($reports, function ($r) use ($expKey) {
            if ($expKey === '') return false;
            return mb_strtolower(trim((string)($r['created_by_user'] ?? '')), 'UTF-8') === $expKey;
        }));
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   SHIFT TERAKHIR PER HARI

   system_revenue BUKAN pendapatan per shift — ia angka berjalan KUMULATIF
   yang dibaca kasir dari mesin POS di akhir shiftnya. Jadi baris shift Evening
   sudah memuat angka shift Morning di dalamnya. Menjumlahkan seluruh baris
   membuat pendapatan terhitung berlipat.

   Urutan penentu shift terakhir: start_time ASC, lalu id ASC. Ini urutan yang
   sama persis dengan getDreportYesterdayCashMulti() skenario B di
   includes/manual_dreport_functions.php, supaya seluruh modul dreport sepakat
   soal apa arti "shift terakhir". Tie-break id penting: satu hari bisa punya
   dua baris pada shift yang sama.

   start_time DIBACA LANGSUNG DI SINI, bukan lewat getDreportShifts(), karena
   fungsi itu menyaring is_active = 1 — shift Night (id 4) berstatus non-aktif
   dan akan hilang dari peta, sehingga baris Night akan dianggap tak berwaktu.
   ═══════════════════════════════════════════════════════════════════════ */
$shiftStartMap = [];
$resShiftStart = $conn->query("SELECT id, start_time FROM employee_shifts");
if ($resShiftStart) {
    while ($rowShiftStart = $resShiftStart->fetch_assoc()) {
        $shiftStartMap[(int)$rowShiftStart['id']] = (string)($rowShiftStart['start_time'] ?? '');
    }
}

/**
 * Kunci urut sebuah laporan: [start_time, id].
 * Shift yang tidak dikenal / NULL memakai '99:99:99' sehingga jatuh di akhir —
 * konvensi yang sama dengan COALESCE(es.start_time, '99:99:99') di
 * manual_dreport_functions.php.
 */
function _dreportSortKey(array $r, array $map): array {
    $sid   = (int)($r['shift_id'] ?? 0);
    $start = $map[$sid] ?? '';
    if ($start === '') $start = '99:99:99';
    return [$start, (int)($r['id'] ?? 0)];
}

/**
 * Apakah $a lebih akhir daripada $b? Dibandingkan EKSPLISIT per elemen.
 * Sengaja tidak memakai operator '>' langsung atas array: semantik
 * perbandingan array PHP benar untuk kasus ini, tetapi cukup halus sehingga
 * kesalahan kecil akan menghasilkan Grand Total yang salah tanpa satu pun
 * pesan error. Angka uang tidak boleh bergantung pada kehalusan semacam itu.
 */
function _dreportKeyIsLater(array $a, array $b): bool {
    if ($a[0] !== $b[0]) {
        return strcmp($a[0], $b[0]) > 0;
    }
    return $a[1] > $b[1];
}

// Himpunan id baris yang mewakili shift terakhir hari itu.
// SELALU dihitung dari $reports SETELAH seluruh filter & permission diterapkan,
// supaya grand total hanya pernah menjumlah baris yang memang berhak dilihat
// pengguna ini — dan, saat filter shift dipakai, tetap dapat ditelusuri dari
// baris yang tampil.
$lastShiftIds  = [];
$perDayBest    = [];
foreach ($reports as $r) {
    $day = (string)($r['report_date'] ?? '');
    if ($day === '') continue;
    $key = _dreportSortKey($r, $shiftStartMap);
    if (!isset($perDayBest[$day]) || _dreportKeyIsLater($key, $perDayBest[$day]['key'])) {
        $perDayBest[$day] = ['key' => $key, 'id' => (int)$r['id']];
    }
}
foreach ($perDayBest as $best) {
    $lastShiftIds[$best['id']] = true;
}

// Mode checkbox: pangkas baris yang dicetak. Grand total TIDAK ikut berubah —
// ia memakai $lastShiftIds yang sudah dihitung di atas.
if ($lastShiftOnly) {
    $reports = array_values(array_filter($reports, function ($r) use ($lastShiftIds) {
        return isset($lastShiftIds[(int)$r['id']]);
    }));
}

// ── Shift name lookup ────────────────────────────────────────────────────────
$shifts     = getDreportShifts();
$shiftNames = [];
foreach ($shifts as $s) {
    $shiftNames[(int)$s['id']] = $s['name'];
}

// ── Money formatter (uses dreportFormatMoney → formatCurrency → multi-currency) ──
function _expFmt(float $v): string {
    return dreportFormatMoney($v);
}

// ── Split formatted money into [prefix, number] for aligned breakdown columns ──
// Handles: 'Rp 1.000' → ['Rp', '1.000'] | '$1.00' → ['$', '1.00'] | '¥1.00' → ['¥', '1.00']
function _expFmtParts(float $v): array {
    $str = dreportFormatMoney($v);
    // Match leading non-digit/non-minus characters as prefix (e.g. 'Rp ', '$', '¥')
    if (preg_match('/^([^0-9\-]+)(.+)$/u', $str, $m)) {
        return [rtrim($m[1]), $m[2]];
    }
    return ['', $str];
}

// ── Helper: per-report Yesterday Cash Total (same logic as getDreportHeaderYesterdayCash) ──
// = SUM(cash payment items of that report) + yesterday_cash_balance of that report
function _getReportYesterdayCashTotal(int $reportId, float $ycBalance): float {
    global $conn;
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(dri.amount), 0) AS cash_total
         FROM   manual_daily_report_items dri
         INNER JOIN payment_methods pm ON pm.id = dri.payment_method_id
         WHERE  dri.report_id = ?
           AND  (pm.code = 'Cash' OR pm.name = 'Cash')"
    );
    if (!$stmt) return $ycBalance;
    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cashItemsTotal = (float)($row['cash_total'] ?? 0);
    return $cashItemsTotal + $ycBalance;
}

// ── Helper: get summary image as base64 data URI for embedding in PDF ────────
function _getImageBase64(string $filename): string {
    if ($filename === '') return '';
    $dir  = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/bltx/uploads/manual_daily_reports/upload_dreport/';
    $path = $dir . $filename;
    if (!file_exists($path) || !is_readable($path)) return '';
    $mime = mime_content_type($path);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) return '';
    $data = base64_encode(file_get_contents($path));
    return 'data:' . $mime . ';base64,' . $data;
}

/* ── Filter label helpers ─────────────────────────────────────────────────────
   DIPINDAH NAIK dari bawah bootstrap dompdf. Isinya tidak diubah sebaris pun;
   hanya posisinya. Alasannya cabang Excel di bawah memakai label yang sama,
   dan cabang itu harus berjalan SEBELUM dompdf dimuat — memuat pustaka PDF
   untuk sebuah unduhan .xls hanya membuang memori. */
$labelDateRange = ($filterDateFrom && $filterDateTo)
    ? date('d/m/Y', strtotime($filterDateFrom)) . ' - ' . date('d/m/Y', strtotime($filterDateTo))
    : ($LANG['dreport_export_all_dates']);

$labelShift = ($filterShift > 0 && isset($shiftNames[$filterShift]))
    ? $shiftNames[$filterShift]
    : ($LANG['dreport_export_all_shifts']);

$labelCashier = ($filterCashier !== '')
    ? $filterCashier
    : ($LANG['dreport_export_all_cashiers']);

$generatedAt = date('d/m/Y H:i:s');

/* ═══════════════════════════════════════════════════════════════════════════
   EXPORT EXCEL  (?type=excel)

   Pola sama dengan includes/export/export_stock_opname.php: tabel HTML yang
   dikirim sebagai application/vnd.ms-excel, BUKAN .xlsx sungguhan —
   PhpSpreadsheet tidak terpasang di sistem ini.

   BOM UTF-8 WAJIB dicetak lebih dulu. Tanpa itu Excel membaca berkas sebagai
   ANSI dan seluruh nama shift berbahasa Mandarin ('Morning 早晨') berubah
   menjadi ????.

   ISI kolomnya sama dengan PDF. Dua hal SENGAJA berbeda:

   1. Kolom foto berisi TAUTAN URL, bukan gambar. Excel mengabaikan data URI
      base64 yang dipakai PDF, jadi thumbnail tidak mungkin ikut. URL-nya pasti
      hidup karena BASE_URL absolut dan berkas foto memang sudah dilayani lewat
      URL biasa — lightbox di manual_dreport.php memuatnya persis begitu.

   2. Tiga kolom uang ditulis sebagai ANGKA MENTAH, bukan teks berformat,
      supaya SUM() di Excel berfungsi. Tampilannya tetap berpemisah ribuan
      lewat mso-number-format. Kolom Payment Breakdown tetap teks karena berisi
      beberapa angka berlabel dengan jumlah metode pembayaran yang berubah-ubah.
   ═══════════════════════════════════════════════════════════════════════ */
if ($exportType === 'excel') {

    if (ob_get_length()) ob_end_clean();
    ini_set('memory_limit', '512M');

    // Escape keluaran HTML — ENT_QUOTES + UTF-8, sama dengan export_stock_opname.php
    $esc = function ($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    /* Angka mentah untuk Excel — tanpa simbol, tanpa pemisah ribuan.
       Nilai tetap mengikuti mata uang aktif persis seperti formatCurrency(),
       sehingga angka di .xls sama dengan angka di PDF:
           IDR -> 5746600      (0 desimal)
           USD -> 383.11       (2 desimal, titik = pemisah desimal)
           CNY -> 2736.48      (2 desimal)
       Kurs dibaca sekali, bukan di dalam loop. */
    $curCode = $_SESSION['currency'] ?? getSetting('currency', 'IDR');
    switch ($curCode) {
        case 'USD':
            $curRate = floatval(getSetting('usd_rate', 15000));
            $curDec  = 2;
            $curSym  = '$';
            break;
        case 'CNY':
            $curRate = floatval(getSetting('cny_rate', 2100));
            $curDec  = 2;
            $curSym  = '¥';
            break;
        default:
            $curRate = 1.0;
            $curDec  = 0;
            $curSym  = 'Rp';
            break;
    }
    if ($curRate <= 0) $curRate = 1.0;

    $numRaw = function (float $v) use ($curRate, $curDec): string {
        return number_format($v / $curRate, $curDec, '.', '');
    };
    /* Format tampilan Excel: isi sel tetap NUMBER, hanya pemisah ribuannya
       dirapikan. Yang disimpan di variabel hanya STRING STYLE-nya, bukan tag
       sel yang utuh — supaya tiap tag pembuka tetap muncul sebagai literal di
       tempat pemakaiannya dan pemeriksaan jumlah tag HTML tidak menghasilkan
       selisih palsu. */
    $msoNum    = ($curDec === 2) ? '#,##0.00' : '#,##0';
    $msoStyle  = 'mso-number-format:\'' . $msoNum . '\';';
    $msoStyleB = $msoStyle . 'font-weight:bold;';

    $stamp    = date('Ymd_His');
    $suffixXl = ($filterDateFrom && $filterDateTo)
        ? '_' . str_replace('-', '', $filterDateFrom) . '-' . str_replace('-', '', $filterDateTo)
        : '_all';
    $filenameXl = 'cashier_report' . $suffixXl . '_' . $stamp . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameXl . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // BOM UTF-8 — kunci agar Mandarin tidak jadi ????

    echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';

    $colsXl = [
        'No',
        $LANG['summary_photo'],
        $LANG['date'],
        $LANG['cashier_name'],
        $LANG['shifts'],
        $LANG['system_revenue'] . ' (' . $curSym . ')',
        $LANG['payment_breakdown'],
        $LANG['yesterday_cash_balance'] . ' (' . $curSym . ')',
        $LANG['yesterday_cash_total'] . ' (' . $curSym . ')',
        $LANG['notes_daily_report'],
    ];
    $colCountXl = count($colsXl);

    echo '<table border="1">';

    echo '<tr><th colspan="' . $colCountXl . '" style="text-align:center;font-weight:bold;font-size:16px;">'
       . $esc($LANG['cashier_report']) . '</th></tr>';

    // Baris filter — isinya sama dengan blok filter-info di PDF
    $filterBits = [];
    if ($filterDateFrom && $filterDateTo) {
        $filterBits[] = $LANG['dreport_export_date_range'] . ': ' . $labelDateRange;
    }
    if ($filterShift > 0) {
        $filterBits[] = $LANG['dreport_export_shift'] . ': ' . $labelShift;
    }
    if ($filterCashier !== '') {
        $filterBits[] = $LANG['dreport_export_cashier'] . ': ' . $labelCashier;
    }
    if ($lastShiftOnly) {
        $filterBits[] = $LANG['dreport_export_last_shift_only'];
    }
    if (empty($filterBits)) {
        $filterBits[] = $LANG['export_all_data'];
    }
    echo '<tr><th colspan="' . $colCountXl . '" style="text-align:center;font-size:12px;">'
       . $esc($LANG['dreport_export_filter_info'] . ': ' . implode('  |  ', $filterBits)) . '</th></tr>';
    echo '<tr><th colspan="' . $colCountXl . '" style="text-align:center;font-size:11px;">'
       . $esc($LANG['printed_at'] . ': ' . $generatedAt) . '</th></tr>';

    echo '<tr>';
    foreach ($colsXl as $c) {
        echo '<th style="font-weight:bold;">' . $esc($c) . '</th>';
    }
    echo '</tr>';

    if (empty($reports)) {
        echo '<tr><td colspan="' . $colCountXl . '" style="text-align:center;">'
           . $esc($LANG['dreport_export_no_data']) . '</td></tr>';
    } else {
        $noXl        = 1;
        $grandSysXl  = 0.0;
        $photoBaseXl = url('uploads/manual_daily_reports/upload_dreport/');

        foreach ($reports as $r) {
            $rIdXl  = (int)$r['id'];
            $dataXl = getDreportViewData($rIdXl);
            $itemsXl = $dataXl['items'] ?? [];

            $diffXl   = (float)($r['difference']    ?? 0);
            $sysRevXl = (float)($r['system_revenue'] ?? 0);
            $manualXl = (float)($r['total_manual']  ?? 0);
            $ycBalXl  = (float)($dataXl['report']['yesterday_cash_balance'] ?? 0);
            $notesXl  = trim($r['notes'] ?? $dataXl['report']['notes'] ?? '');
            $imgFileXl = $dataXl['report']['image_file'] ?? '';

            $ycTotalXl = _getReportYesterdayCashTotal($rIdXl, $ycBalXl);

            // Sama seperti cabang PDF: checkbox mati berarti seluruh baris
            // ikut dijumlah, persis perilaku versi sebelum perbaikan.
            $isCounted = !$lastShiftOnly || isset($lastShiftIds[$rIdXl]);
            if ($isCounted) {
                $grandSysXl += $sysRevXl;
            }

            $dateFmtXl = '-';
            if (!empty($r['report_date'])) {
                $dtXl = DateTime::createFromFormat('Y-m-d', $r['report_date']);
                $dateFmtXl = $dtXl ? $dtXl->format('d/m/Y') : $r['report_date'];
            }

            // Foto — tautan URL. Excel mengabaikan data URI, jadi tidak ada gambar.
            $photoXl = '-';
            if ($imgFileXl !== '') {
                $photoUrlXl = $photoBaseXl . rawurlencode($imgFileXl);
                $photoXl = '<a href="' . $esc($photoUrlXl) . '">' . $esc($imgFileXl) . '</a>';
            }

            /* Income & Expenses Breakdown — ISINYA DISAMAKAN DENGAN PDF.
               Di PDF kolom ini tabel bersarang 4 kolom (nama | : | simbol |
               angka). Tabel bersarang TIDAK bisa disalin apa adanya ke .xls:
               Excel meratakannya ke dalam grid induk dan merusak susunan
               seluruh baris. Karena itu isinya disusun sebagai baris-baris
               bertumpuk di dalam SATU sel — urutan, label, dan tanda +/- persis
               sama dengan PDF, satu laporan tetap satu baris spreadsheet
               sehingga urut/filter dan SUM() tetap berfungsi. */
            $bdXl = [];
            $bdXl[] = $LANG['system_revenue'] . ' : ' . _expFmt($sysRevXl);
            if (!empty($itemsXl)) {
                foreach ($itemsXl as $itXl) {
                    $bdXl[] = ($itXl['method_name'] ?? '-') . ' : ' . _expFmt((float)($itXl['amount'] ?? 0));
                }
            } else {
                // PDF menampilkan satu baris '-' saat tidak ada metode pembayaran.
                $bdXl[] = '-';
            }
            $bdXl[] = $LANG['total_manual'] . ' : ' . _expFmt($manualXl);
            $bdXl[] = $LANG['difference'] . ' : '
                    . ($diffXl > 0 ? '+' : ($diffXl < 0 ? '-' : '')) . _expFmt(abs($diffXl));

            echo '<tr>';
            echo '<td>' . $noXl++ . '</td>';
            echo '<td>' . $photoXl . '</td>';
            echo '<td>' . $esc($dateFmtXl) . '</td>';
            echo '<td>' . $esc($r['cashier_name'] ?? '-') . '</td>';
            echo '<td>' . $esc($r['shift_name'] ?? '-') . '</td>';
            /* Sel yang IKUT dijumlah ditebalkan. Di mode "apa adanya" tabelnya
               memuat semua baris sementara grand total hanya menjumlah shift
               terakhir — tanpa penanda ini, pembaca yang menjumlah kolom secara
               manual akan mendapat angka berbeda dan mengira ada kesalahan. */
            echo '<td style="' . (($lastShiftOnly && $isCounted) ? $msoStyleB : $msoStyle) . '">' . $esc($numRaw($sysRevXl)) . '</td>';
            /* Tiap baris di-escape SENDIRI lalu disambung dengan <br>; kalau
               seluruh string di-escape setelah disambung, tag <br>-nya ikut
               ter-escape dan yang muncul justru teks '&lt;br&gt;'.
               mso-data-placement:same-cell menahan Excel memecah isi ber-<br>
               menjadi beberapa baris terpisah. */
            echo '<td style="white-space:normal;mso-data-placement:same-cell;">'
               . implode('<br>', array_map($esc, $bdXl)) . '</td>';
            echo '<td style="' . $msoStyle . '">' . $esc($numRaw($ycBalXl)) . '</td>';
            echo '<td style="' . $msoStyle . '">' . $esc($numRaw($ycTotalXl)) . '</td>';
            echo '<td style="white-space:normal;">' . $esc($notesXl !== '' ? $notesXl : '-') . '</td>';
            echo '</tr>';
        }

        /* Grand Total ditulis sebagai ANGKA STATIS, bukan rumus =SUM() atas
           kolomnya. Di mode "apa adanya" kolom itu memuat semua baris
           sedangkan yang sah dijumlah hanya shift terakhir — rumus SUM hidup
           justru akan menghasilkan angka yang salah. */
        echo '<tr>';
        echo '<td colspan="5" style="text-align:right;font-weight:bold;">Grand Total:</td>';
        echo '<td style="' . $msoStyleB . '">' . $esc($numRaw($grandSysXl)) . '</td>';
        echo '<td colspan="4"></td>';
        echo '</tr>';

        if ($lastShiftOnly) {
            echo '<tr><td colspan="' . $colCountXl . '" style="font-size:10px;font-style:italic;">'
               . $esc($LANG['dreport_export_grand_total_note']) . '</td></tr>';
        }
    }

    echo '</table>';

    logActivity('export_excel', 'Exported cashier report data to Excel'
        . ($lastShiftOnly ? ' (last shift only)' : ''));
    exit;
}

// ── dompdf bootstrap ─────────────────────────────────────────────────────────
if (ob_get_length()) ob_end_clean();

ini_set('memory_limit', '512M');

$dompdfBase = BASE_PATH . '/assets/vendor/dompdf';
$autoload   = $dompdfBase . '/autoload.inc.php';

if (!file_exists($autoload)) {
    http_response_code(500);
    exit('dompdf library not found. Please install dompdf at: ' . htmlspecialchars($dompdfBase));
}

require_once $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

// ── Build HTML (matches export.php style) ─────────────────────────────────────
$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 12mm 10mm 14mm 10mm; }
    body { font-family: Arial, sans-serif; font-size: 9pt; margin: 0; padding: 0; }
    h2 { text-align: center; color: #333; margin-bottom: 5px; font-size: 13pt; }
    .filter-info {
        background-color: #f8f9fa;
        padding: 8px 10px;
        margin-bottom: 12px;
        border: 1px solid #dee2e6;
        border-radius: 4px;
    }
    .filter-info strong { display: block; margin-bottom: 4px; font-size: 9pt; }
    .filter-info ul { margin: 4px 0; padding-left: 18px; }
    .filter-info li { font-size: 8pt; margin-bottom: 2px; }
    table.main-tbl { width: 100%; border-collapse: collapse; margin-top: 8px; table-layout: fixed; }
    table.main-tbl th,
    table.main-tbl td { border: 1px solid #000; padding: 4px 5px; text-align: left; word-wrap: break-word; overflow-wrap: break-word; }
    table.main-tbl th {
        background-color: #4CAF50;
        color: white;
        font-weight: bold;
        text-align: center;
        font-size: 7pt;
    }
    table.main-tbl td { font-size: 6.5pt; vertical-align: middle; }
    .col-no      { width: 3%; }
    .col-photo   { width: 7%; }
    .col-date    { width: 7%; }
    .col-cashier { width: 9%; }
    .col-shift   { width: 7%; }
    .col-sysrev  { width: 8%; }
    .col-method  { width: 20%; }
    .col-ycbal   { width: 10%; }
    .col-yctotal { width: 10%; }
    .col-notes   { width: 19%; }
    .text-center  { text-align: center !important; }
    .text-right   { text-align: right !important; }
    .text-success { color: #198754; }
    .text-danger  { color: #dc3545; }
    .text-muted   { color: #6c757d; }
    .total-row    { background-color: #f0f0f0; font-weight: bold; }
    /* Baris yang ikut dijumlah ke Grand Total */
    .sysrev-counted { font-weight: bold; }
    .note-row td  { border: none; font-size: 6pt; font-style: italic; color: #6c757d; padding-top: 3px; }
    /* Photo cell */
    .photo-thumb { width: 100%; max-width: 60px; max-height: 50px; object-fit: contain; display: block; margin: 0 auto; }
    .photo-none  { color: #adb5bd; font-style: italic; font-size: 6pt; text-align: center; }
    /* Breakdown nested table — no outer border, columns flush */
    table.tbl-breakdown { width: 100%; border-collapse: collapse; }
    table.tbl-breakdown td { border: none; padding: 1px 2px; font-size: 6.5pt; vertical-align: top; }
    table.tbl-breakdown td.bd-name  { width: 38%; text-align: left; }
    table.tbl-breakdown td.bd-colon { width: 5%;  text-align: center; white-space: nowrap; }
    table.tbl-breakdown td.bd-curr  { width: 14%; text-align: left;  white-space: nowrap; }
    table.tbl-breakdown td.bd-val   { width: 43%; text-align: right; white-space: nowrap; }
    table.tbl-breakdown tr.bd-bold td { font-weight: bold; }
    table.tbl-breakdown tr.bd-sep td  { border-top: 1px solid #dee2e6; font-weight: bold; padding-top: 2px; }
    /* Money cell: currency prefix left-aligned, number right-aligned — for sysrev/ycbal/yctotal */
    table.tbl-money { width: 100%; border-collapse: collapse; }
    table.tbl-money td { border: none; padding: 0; font-size: 6.5pt; white-space: nowrap; vertical-align: middle; }
    table.tbl-money td.mc-curr { text-align: left; }
    table.tbl-money td.mc-val  { text-align: right; width: 100%; }
</style>
</head>
<body>';

// Title — uses $LANG['cashier_report'] which already exists in all language files
$html .= '<h2>' . htmlspecialchars($LANG['cashier_report']) . '</h2>';

// ── Filter section (always shown, matching export.php) ────────────────────────
$html .= '<div class="filter-info"><strong>' . htmlspecialchars($LANG['dreport_export_filter_info']) . ':</strong><ul>';
$has_any = false;
if ($filterDateFrom && $filterDateTo) {
    $html .= '<li>' . htmlspecialchars($LANG['dreport_export_date_range']) . ': '
           . htmlspecialchars($labelDateRange) . '</li>';
    $has_any = true;
}
if ($filterShift > 0) {
    $html .= '<li>' . htmlspecialchars($LANG['dreport_export_shift']) . ': '
           . htmlspecialchars($labelShift) . '</li>';
    $has_any = true;
}
if ($filterCashier !== '') {
    $html .= '<li>' . htmlspecialchars($LANG['dreport_export_cashier']) . ': '
           . htmlspecialchars($labelCashier) . '</li>';
    $has_any = true;
}
if ($lastShiftOnly) {
    $html .= '<li>' . htmlspecialchars($LANG['dreport_export_last_shift_only']) . '</li>';
    $has_any = true;
}
if (!$has_any) {
    $html .= '<li><em>' . htmlspecialchars($LANG['export_all_data']) . '</em></li>';
}
$html .= '</ul></div>';

// NOTE: "Generated on" line is intentionally removed as requested.

if (empty($reports)) {
    $html .= '<p style="text-align:center;color:#6c757d;padding:20px;font-style:italic;">'
           . htmlspecialchars($LANG['dreport_export_no_data'])
           . '</p>';
} else {
    // ── Table header ─────────────────────────────────────────────────────────
    $html .= '<table class="main-tbl">
        <thead><tr>
            <th class="col-no">No</th>
            <th class="col-photo">'   . htmlspecialchars($LANG['summary_photo'])           . '</th>
            <th class="col-date">'    . htmlspecialchars($LANG['date'])            . '</th>
            <th class="col-cashier">' . htmlspecialchars($LANG['cashier_name'])        . '</th>
            <th class="col-shift">'   . htmlspecialchars($LANG['shifts'])          . '</th>
            <th class="col-sysrev">'  . htmlspecialchars($LANG['system_revenue']) . '</th>
            <th class="col-method">'  . htmlspecialchars($LANG['payment_breakdown'])      . '</th>
            <th class="col-ycbal">'   . htmlspecialchars($LANG['yesterday_cash_balance'])   . '</th>
            <th class="col-yctotal">' . htmlspecialchars($LANG['yesterday_cash_total'])     . '</th>
            <th class="col-notes">'   . htmlspecialchars($LANG['notes_daily_report'])          . '</th>
        </tr></thead>
        <tbody>';

    $no          = 1;
    $grandSysRev = 0.0;
    $grandManual = 0.0;

    foreach ($reports as $r) {
        $rId   = (int)$r['id'];
        $data  = getDreportViewData($rId);
        $items = $data['items'] ?? [];

        $diff      = (float)($r['difference']          ?? 0);
        $sysRev    = (float)($r['system_revenue']       ?? 0);
        $manual    = (float)($r['total_manual']         ?? 0);
        $ycBalance = (float)($data['report']['yesterday_cash_balance'] ?? 0);
        $notes     = trim($r['notes'] ?? $data['report']['notes'] ?? '');
        $imgFile   = $data['report']['image_file'] ?? '';

        // Yesterday Cash Total: cashItemsTotal + ycBalance (same logic as header widget)
        $ycTotalComputed = _getReportYesterdayCashTotal($rId, $ycBalance);

        // Format date
        $dateFmt = '-';
        if (!empty($r['report_date'])) {
            $dt = DateTime::createFromFormat('Y-m-d', $r['report_date']);
            $dateFmt = $dt ? $dt->format('d/m/Y') : $r['report_date'];
        }

        // Diff styling
        $diffClass = $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : 'text-muted');
        $diffLabel = $diff > 0 ? '+' . _expFmt($diff) : _expFmt($diff);

        // Yesterday cash balance styling
        $ycBalClass = $ycBalance > 0 ? 'text-success' : ($ycBalance < 0 ? 'text-danger' : 'text-muted');

        // ── Summary photo ─────────────────────────────────────────────────────
        $photoHtml = '';
        if ($imgFile !== '') {
            $b64 = _getImageBase64($imgFile);
            if ($b64 !== '') {
                $photoHtml = '<img src="' . $b64 . '" class="photo-thumb" alt="">';
            } else {
                $photoHtml = '<div class="photo-none">n/a</div>';
            }
        } else {
            $photoHtml = '<div class="photo-none">-</div>';
        }

        // ── Payment breakdown: nested table for aligned name : currency amount ──
        // 4 columns: name | : | currency-prefix | number — all vertically aligned
        $breakdownHtml = '<table class="tbl-breakdown"><tbody>';

        // Row: System Revenue — bold (same weight as Manual Total)
        [$sCurr, $sVal] = _expFmtParts($sysRev);
        $breakdownHtml .= '<tr class="bd-bold">'
            . '<td class="bd-name">' . htmlspecialchars($LANG['system_revenue']) . '</td>'
            . '<td class="bd-colon">:</td>'
            . '<td class="bd-curr">' . htmlspecialchars($sCurr) . '</td>'
            . '<td class="bd-val">'  . htmlspecialchars($sVal)  . '</td>'
            . '</tr>';

        // Rows: payment method items
        if (!empty($items)) {
            foreach ($items as $it) {
                [$iCurr, $iVal] = _expFmtParts((float)($it['amount'] ?? 0));
                $breakdownHtml .= '<tr>'
                    . '<td class="bd-name">' . htmlspecialchars($it['method_name'] ?? '-') . '</td>'
                    . '<td class="bd-colon">:</td>'
                    . '<td class="bd-curr">' . htmlspecialchars($iCurr) . '</td>'
                    . '<td class="bd-val">'  . htmlspecialchars($iVal)  . '</td>'
                    . '</tr>';
            }
        } else {
            $breakdownHtml .= '<tr>'
                . '<td colspan="4" class="text-muted" style="text-align:center;font-style:italic;">-</td>'
                . '</tr>';
        }

        // Separator + Manual Total row (bold)
        [$mCurr, $mVal] = _expFmtParts($manual);
        $breakdownHtml .= '<tr class="bd-sep">'
            . '<td class="bd-name">' . htmlspecialchars($LANG['total_manual']) . '</td>'
            . '<td class="bd-colon">:</td>'
            . '<td class="bd-curr">' . htmlspecialchars($mCurr) . '</td>'
            . '<td class="bd-val">'  . htmlspecialchars($mVal)  . '</td>'
            . '</tr>';

        // Difference row (bold + colour)
        [$dCurr, $dVal] = _expFmtParts(abs($diff));
        $dValDisplay = ($diff > 0 ? '+' : ($diff < 0 ? '-' : '')) . $dVal;
        $breakdownHtml .= '<tr class="bd-sep">'
            . '<td class="bd-name ' . $diffClass . '">' . htmlspecialchars($LANG['difference']) . '</td>'
            . '<td class="bd-colon ' . $diffClass . '">:</td>'
            . '<td class="bd-curr ' . $diffClass . '">' . htmlspecialchars($dCurr) . '</td>'
            . '<td class="bd-val '  . $diffClass . '">' . htmlspecialchars($dValDisplay) . '</td>'
            . '</tr>';

        $breakdownHtml .= '</tbody></table>';

        // Notes: truncate for table readability (max 80 chars)
        $notesTrunc = $notes !== ''
            ? htmlspecialchars(mb_strlen($notes) > 80 ? mb_substr($notes, 0, 80) . '...' : $notes)
            : '<span class="text-muted">-</span>';

        [$srCurr, $srVal]  = _expFmtParts($sysRev);
        [$ybCurr, $ybVal]  = _expFmtParts(abs($ycBalance));
        [$ytCurr, $ytVal]  = _expFmtParts($ycTotalComputed);

        /* Checkbox mengendalikan SELURUH mode, bukan hanya baris yang dicetak.

           CHECKBOX MATI  → keluaran identik dengan versi sebelum perbaikan:
                            semua baris dicetak, Grand Total menjumlah SEMUA
                            baris, tanpa penebalan sel, tanpa baris catatan.
           CHECKBOX HIDUP → hanya baris shift terakhir, Grand Total dari baris
                            itu saja, sel ditebalkan, baris catatan muncul.

           Baris catatan wajib ikut mati saat checkbox mati: kalimatnya
           menyatakan Grand Total hanya menjumlah shift terakhir, dan itu jadi
           keterangan yang SALAH untuk keluaran keseluruhan. */
        $isCountedRow = !$lastShiftOnly || isset($lastShiftIds[$rId]);
        $srCellClass  = ($lastShiftOnly && $isCountedRow) ? ' class="sysrev-counted"' : '';

        $html .= '<tr>
            <td class="text-center">'  . $no++ . '</td>
            <td class="text-center">'  . $photoHtml . '</td>
            <td class="text-center">'  . htmlspecialchars($dateFmt) . '</td>
            <td>'                      . htmlspecialchars($r['cashier_name'] ?? '-') . '</td>
            <td class="text-center">'  . htmlspecialchars($r['shift_name'] ?? '-') . '</td>
            <td' . $srCellClass . '><table class="tbl-money"><tr><td class="mc-curr">' . htmlspecialchars($srCurr) . '</td><td class="mc-val">' . htmlspecialchars($srVal) . '</td></tr></table></td>
            <td>'                      . $breakdownHtml . '</td>
            <td class="' . $ycBalClass . '"><table class="tbl-money"><tr><td class="mc-curr">' . htmlspecialchars($ybCurr) . '</td><td class="mc-val">' . htmlspecialchars($ybVal) . '</td></tr></table></td>
            <td><table class="tbl-money"><tr><td class="mc-curr">' . htmlspecialchars($ytCurr) . '</td><td class="mc-val">' . htmlspecialchars($ytVal) . '</td></tr></table></td>
            <td>'                      . $notesTrunc . '</td>
        </tr>';

        if ($isCountedRow) {
            $grandSysRev += $sysRev;
        }
        $grandManual += $manual;
    }

    // Grand total row (matching export.php pattern)
    [$gtCurr, $gtVal] = _expFmtParts($grandSysRev);
    $html .= '<tr class="total-row">
        <td colspan="5" class="text-right">Grand Total:</td>
        <td><table class="tbl-money"><tr><td class="mc-curr">' . htmlspecialchars($gtCurr) . '</td><td class="mc-val">' . htmlspecialchars($gtVal) . '</td></tr></table></td>
        <td colspan="4"></td>
    </tr>';

    // Catatan aturan perhitungan — HANYA saat mode shift terakhir aktif.
    // Di mode keseluruhan, Grand Total memang menjumlah semua baris sehingga
    // catatan ini akan menyesatkan.
    if ($lastShiftOnly) {
        $html .= '<tr class="note-row">
            <td colspan="10">' . htmlspecialchars($LANG['dreport_export_grand_total_note']) . '</td>
        </tr>';
    }

    $html .= '</tbody></table>';
}

$html .= '</body></html>';

// ── dompdf render ─────────────────────────────────────────────────────────────
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Arial');
$options->set('isFontSubsettingEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// ── Footer via canvas (same as export.php) ────────────────────────────────────
$canvas           = $dompdf->getCanvas();
$font             = $dompdf->getFontMetrics()->getFont('Arial', 'normal');
$pageW            = $canvas->get_width();
$pageH            = $canvas->get_height();
$margin_lr_pt     = 10 * (72 / 25.4);   // 10mm → pt ≈ 28.35pt
$bottom_margin_pt = 14 * (72 / 25.4);   // 14mm → pt ≈ 39.69pt
$y_footer         = $pageH - $bottom_margin_pt + 5;

// Left: APP_NAME — sejajar tepi kiri tabel
$canvas->page_text($margin_lr_pt, $y_footer, APP_NAME, $font, 7, [0, 0, 0]);

// Center: Printed at | Right: Page X of Y — via page_script
$printed_at_text = 'Printed at: ' . $generatedAt;
$right_edge      = $pageW - $margin_lr_pt;
$font_size       = 7;
$canvas->page_script(function($pageNumber, $pageCount, $canvasObj, $fontMetrics)
    use ($font, $pageW, $margin_lr_pt, $right_edge, $y_footer, $font_size, $printed_at_text) {
        // Center: printed at
        $tw_center = $fontMetrics->getTextWidth($printed_at_text, $font, $font_size);
        $x_center  = ($pageW / 2) - ($tw_center / 2);
        $canvasObj->text($x_center, $y_footer, $printed_at_text, $font, $font_size, [0, 0, 0]);

        // Right: page number
        $text_page = "Page $pageNumber of $pageCount";
        $tw_right  = $fontMetrics->getTextWidth($text_page, $font, $font_size);
        $x_right   = $right_edge - $tw_right;
        $canvasObj->text($x_right, $y_footer, $text_page, $font, $font_size, [0, 0, 0]);
    }
);

// ── Filename ──────────────────────────────────────────────────────────────────
$suffix = ($filterDateFrom && $filterDateTo)
    ? '_' . str_replace('-', '', $filterDateFrom) . '-' . str_replace('-', '', $filterDateTo)
    : '_all';
$filename = 'cashier_report' . $suffix . '_' . date('Ymd_His') . '.pdf';

$dompdf->stream($filename, ['Attachment' => true]);

logActivity('export_pdf', 'Exported cashier report data to PDF');
exit;