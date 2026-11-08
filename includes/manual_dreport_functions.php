<?php
/**
 * manual_dreport_functions.php
 * Functions for Manual Daily Cashier Report
 * Location: public_html/bltx/arw_pos/manual_daily_reports/manual_dreport_functions.php
 *
 * DO NOT access this file directly.
 *
 */

if (!defined('APP_VERSION') && !isset($conn)) {
    http_response_code(403);
    exit('Forbidden');
}

// ============================================================
// ACCESS CONTROL — berbasis permission database (modul: dreport)
// Superadmin selalu full access tanpa query DB.
// ============================================================

/**
 * Helper internal: ambil permission dreport user dari DB.
 * Hasil di-cache di $_SESSION['_dreport_perm'] agar tidak query berulang.
 */
function _getDreportPerm(): array {
    if (isset($_SESSION['_dreport_perm'])) {
        return $_SESSION['_dreport_perm'];
    }

    $role = $_SESSION['role'] ?? '';
    $uid  = (int)($_SESSION['user_id'] ?? 0);

    // Superadmin: full access, tidak perlu query
    if ($role === 'superadmin') {
        $perm = ['can_create'=>1,'can_read'=>1,'can_update'=>1,'can_delete'=>1,'can_access'=>1];
        $_SESSION['_dreport_perm'] = $perm;
        return $perm;
    }

    $default = ['can_create'=>0,'can_read'=>0,'can_update'=>0,'can_delete'=>0,'can_access'=>0];

    global $conn;
    if (!$conn || !$uid) {
        $_SESSION['_dreport_perm'] = $default;
        return $default;
    }

    // Cek apakah ada permission custom per user
    $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM permissions WHERE user_id = ?");
    $chk->bind_param("i", $uid);
    $chk->execute();
    $has_custom = (int)$chk->get_result()->fetch_assoc()['cnt'];
    $chk->close();

    if ($has_custom > 0) {
        $stmt = $conn->prepare("SELECT can_create, can_read, can_update, can_delete, can_access FROM permissions WHERE user_id = ? AND module = 'dreport'");
        $stmt->bind_param("i", $uid);
    } else {
        $stmt = $conn->prepare("SELECT can_create, can_read, can_update, can_delete, can_access FROM permissions WHERE user_id IS NULL AND role = ? AND module = 'dreport'");
        $stmt->bind_param("s", $role);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $perm = $row ? [
        'can_create' => (int)$row['can_create'],
        'can_read'   => (int)$row['can_read'],
        'can_update' => (int)$row['can_update'],
        'can_delete' => (int)$row['can_delete'],
        'can_access' => (int)$row['can_access'],
    ] : $default;

    $_SESSION['_dreport_perm'] = $perm;
    return $perm;
}

/**
 * Require akses modul dreport (can_access = 1). Redirect jika tidak punya akses.
 */
function requireDreportAccess(): void {
    if (!isLoggedIn()) { redirect(url('index.php')); exit; }
    $perm = _getDreportPerm();
    if (!$perm['can_access']) {
        redirectNoAccess();
    }
}

/**
 * Return true jika user punya hak hapus laporan (can_delete = 1).
 * Superadmin selalu true.
 */
function isDreportSuperadmin(): bool {
    $perm = _getDreportPerm();
    return (bool)$perm['can_delete'];
}

/**
 * Return true jika user hanya punya akses baca/create tapi tidak delete/update penuh.
 * Digunakan untuk membatasi tampilan filter & tombol edit.
 */
function isDreportKasir(): bool {
    $perm = _getDreportPerm();
    // Kasir: bisa create & read, tapi tidak bisa delete
    return $perm['can_access'] && $perm['can_create'] && !$perm['can_delete'];
}


// ============================================================
// CSRF TOKEN
// ============================================================

/* RONDE PENYATUAN CSRF — fungsi di bawah kini hanya MENERUSKAN.

   Kunci sesi lama 'dreport_csrf' tidak dipakai lagi. Token, cara pembentukan,
   dan cara pembandingannya (hash_equals) sekarang satu untuk seluruh sistem:
   bltx_csrf_token() / bltx_csrf_valid() di includes/global_permission.php.

   Nama fungsi dan tanda tangannya SENGAJA tidak diubah, sehingga tidak satu
   pun pemanggil di seluruh modul ini perlu disentuh — pola yang sama dengan
   penyatuan _dbCan() di Tahap 0.1.

   Tidak ada jaring pengaman function_exists di sini, dan itu disengaja.
   Kalau global_permission.php suatu saat tidak termuat, fatal error yang
   nyaring jauh lebih baik daripada diam-diam kembali ke token lama —
   formulir akan dibentuk dengan satu token lalu diperiksa dengan token lain,
   dan gagalnya tanpa penjelasan. Seluruh jalur pemanggil sudah diverifikasi
   memuat global_permission.php pada ronde prasyarat. */
function generateCsrfToken(): string {
    return bltx_csrf_token();
}

function verifyCsrfToken(string $token): bool {
    return bltx_csrf_valid($token);
}

// ============================================================
// UPLOAD HELPERS — GENERIC MULTI-FOLDER
// ============================================================

/**
 * Resolve upload dir & URL for a given subfolder key.
 * Subfolders:
 *   'summary'       => upload_dreport/
 *   'yesterday_cash'=> upload_dreport_cash/
 *   'rpos'          => upload_dreport_rpos/
 */
function _dreportUploadConfig(string $type): array {
    $base = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/bltx/uploads/manual_daily_reports/';
    $map  = [
        'summary'        => ['dir' => $base . 'upload_dreport/',       'prefix' => 'dreport_'],
        'yesterday_cash' => ['dir' => $base . 'upload_dreport_cash/',  'prefix' => 'dreport_cash_'],
        'rpos'           => ['dir' => $base . 'upload_dreport_rpos/',  'prefix' => 'dreport_rpos_'],
    ];
    return $map[$type] ?? $map['summary'];
}

function _dreportUploadUrl(string $type): string {
    $map = [
        'summary'        => url('uploads/manual_daily_reports/upload_dreport/'),
        'yesterday_cash' => url('uploads/manual_daily_reports/upload_dreport_cash/'),
        'rpos'           => url('uploads/manual_daily_reports/upload_dreport_rpos/'),
    ];
    return $map[$type] ?? $map['summary'];
}

/**
 * Save uploaded image (from $_FILES entry) to the given subfolder.
 * Allowed: image/jpeg, image/png. Max 5MB.
 * Returns filename on success, empty string on failure.
 */
function saveDreportImageByType(array $file, string $type): string {
    $cfg = _dreportUploadConfig($type);
    $uploadDir = $cfg['dir'];
    $prefix    = $cfg['prefix'];

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $allowed  = ['image/jpeg', 'image/png'];
    $mimeType = mime_content_type($file['tmp_name']);

    if (!in_array($mimeType, $allowed, true)) {
        return '';
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return '';
    }

    $ext      = $mimeType === 'image/png' ? 'png' : 'jpg';
    $filename = $prefix . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        return '';
    }

    return $filename;
}

/**
 * Save camera base64 data URI to the given subfolder.
 * Allowed: image/jpeg, image/png. Max 5MB.
 */
function saveDreportCameraByType(string $dataUri, string $type): string {
    if (!preg_match('/^data:(image\/(jpeg|png));base64,(.+)$/s', $dataUri, $matches)) {
        return '';
    }

    $ext = $matches[2] === 'jpeg' ? 'jpg' : 'png';
    $raw = base64_decode($matches[3], true);

    if ($raw === false || strlen($raw) === 0) {
        return '';
    }

    if (strlen($raw) > 5 * 1024 * 1024) {
        return '';
    }

    $cfg = _dreportUploadConfig($type);
    $uploadDir = $cfg['dir'];
    $prefix    = $cfg['prefix'];

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = $prefix . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (file_put_contents($uploadDir . $filename, $raw) === false) {
        return '';
    }

    return $filename;
}

/**
 * Delete an image file from the given subfolder.
 */
function deleteDreportImageByType(string $filename, string $type): void {
    if ($filename === '') return;
    $cfg  = _dreportUploadConfig($type);
    $path = $cfg['dir'] . basename($filename);
    if (file_exists($path)) {
        unlink($path);
    }
}

/**
 * Return the public URL for a dreport image in given subfolder.
 */
function dreportImageUrlByType(string $filename, string $type): string {
    if ($filename === '') return '';
    return _dreportUploadUrl($type) . rawurlencode($filename);
}

// ============================================================
// BACKWARD-COMPATIBLE WRAPPERS (used by old code / main table)
// ============================================================

function saveDreportImage(array $file): string {
    return saveDreportImageByType($file, 'summary');
}

function saveDreportImageFromCamera(string $dataUri): string {
    return saveDreportCameraByType($dataUri, 'summary');
}

function deleteDreportImage(string $filename): void {
    deleteDreportImageByType($filename, 'summary');
}

function dreportImageUrl(string $filename): string {
    return dreportImageUrlByType($filename, 'summary');
}

// ============================================================
// DATABASE QUERIES
// ============================================================

/**
 * Fetch all active payment methods (from system master data).
 */
function getDreportPaymentMethods(): array {
    global $conn;
    $res = $conn->query("SELECT id, name, code FROM payment_methods WHERE is_active = 1 ORDER BY name ASC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Resolve or create a manual payment method entry in manual_payment_methods.
 */
function resolveOrCreateManualPaymentMethod(string $name): ?int {
    global $conn;
    $name = trim($name);
    if ($name === '') return null;

    $stmt = $conn->prepare(
        "SELECT id FROM manual_payment_methods WHERE LOWER(name) = LOWER(?) LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['id'];

    $stmt2 = $conn->prepare(
        "INSERT INTO manual_payment_methods (name) VALUES (?)"
    );
    if (!$stmt2) return null;
    $stmt2->bind_param('s', $name);
    if (!$stmt2->execute()) { $stmt2->close(); return null; }
    $newId = (int)$conn->insert_id;
    $stmt2->close();
    return $newId;
}

/**
 * Fetch all active shifts.
 */
function getDreportShifts(): array {
    global $conn;
    $res = $conn->query("SELECT id, name, code, start_time, end_time FROM employee_shifts WHERE is_active = 1 ORDER BY start_time ASC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Fetch cashier-role users only (used by kasir dropdown in add form and filter).
 */
function getDreportUsers(): array {
    global $conn;
    $res = $conn->query(
        "SELECT id, full_name, username FROM users
         WHERE is_active = 1
           AND role = 'cashier'
         ORDER BY full_name ASC"
    );
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Fetch ALL active users (all roles) — used by superadmin dropdowns (add/edit/filter).
 */
function getDreportAllUsers(): array {
    global $conn;
    $res = $conn->query(
        "SELECT id, full_name, username FROM users
         WHERE is_active = 1
           AND full_name IS NOT NULL AND full_name != ''
         ORDER BY full_name ASC"
    );
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Fetch system revenue (pendapatan sistem) for a given date and shift from purchases table.
 */
function getDreportSystemRevenue(string $date, int $shiftId): float {
    global $conn;
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(p.total_price), 0) AS total
         FROM purchases p
         WHERE DATE(p.input_date) = ?
           AND p.payer_shift = (SELECT code FROM employee_shifts WHERE id = ? LIMIT 1)"
    );
    if (!$stmt) return 0.0;
    $stmt->bind_param('si', $date, $shiftId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (float)($row['total'] ?? 0);
}

/**
 * Backward-compatible wrapper — returns the first entry from getDreportYesterdayCashMulti.
 * Existing callers outside add/edit pages continue to work unchanged.
 *
 * @param  string $today  'Y-m-d' of today.
 * @return array  Single entry with keys: total_cash, usage, balance, report_date.
 */
function getDreportYesterdayCash(string $today): array {
    $entries = getDreportYesterdayCashMulti($today);
    if (empty($entries)) {
        return ['total_cash' => 0.0, 'usage' => 0.0, 'balance' => 0.0, 'report_date' => ''];
    }
    $e = $entries[0];
    return [
        'total_cash'  => $e['total_cash'],
        'usage'       => $e['usage'],
        'balance'     => $e['balance'],
        'report_date' => $e['report_date'],
    ];
}

/**
 * Fetch yesterday-cash entries for add/edit form pre-fill.
 *
 * TWO SCENARIOS based on whether the latest data in DB is TODAY or a PAST DATE:
 *
 * A) DIFFERENT DAY (MAX report_date != $today):
 *    - Show ONLY the single last row of that past date (ORDER BY id DESC LIMIT 1).
 *    - Label: 'yesterday_cash_total' (plain, no shift name shown).
 *    - field_suffix = '', is_same_day = false.
 *
 * B) SAME DAY (MAX report_date == $today):
 *    - Show ALL rows for today, ordered by shift start_time ASC then id ASC.
 *    - Each entry shows its shift label: 'Yesterday Cash Total (Morning)', etc.
 *    - field_suffix = '_1','_2',...; is_same_day = true.
 *    - The LAST entry (is_last = true) drives the yesterday_cash_balance auto-calc:
 *      balance = last_entry.yesterday_cash_balance - yesterday_cash_usage (done in JS).
 *
 * All entries are read-only for non-superadmin; superadmin can edit freely.
 *
 * Each entry array:
 *  [
 *    'total_cash'   => float,    // yesterday_cash_balance of that DB row (the carried balance)
 *    'usage'        => float,    // yesterday_cash_usage of that DB row
 *    'balance'      => float,    // same as total_cash
 *    'report_date'  => string,   // 'Y-m-d'
 *    'shift_id'     => int|null,
 *    'shift_name'   => string,   // e.g. "Morning"; '' if none
 *    'label'        => string,   // 'yesterday_cash_total' | 'yesterday_cash_total_shift'
 *    'field_suffix' => string,   // '' (different-day) | '_1','_2',... (same-day)
 *    'is_same_day'  => bool,
 *    'is_last'      => bool,     // true only on the last entry → drives balance base
 *  ]
 *
 * @param  string $today  'Y-m-d' of today.
 * @return array          Array of entry arrays; empty if no data exists at all.
 */
function getDreportYesterdayCashMulti(string $today): array {
    global $conn;

    // Step 1: find the absolute latest report_date in the table (including today)
    $stmtMax = $conn->prepare(
        "SELECT MAX(report_date) AS latest_date FROM manual_daily_reports"
    );
    if (!$stmtMax) return [];
    $stmtMax->execute();
    $maxRow = $stmtMax->get_result()->fetch_assoc();
    $stmtMax->close();

    $latestDate = $maxRow['latest_date'] ?? null;
    if (!$latestDate) return [];

    $isSameDay = ($latestDate === $today);

    // Scenario A: different day — show ONLY the last-inserted row of that past date
    if (!$isSameDay) {
        $stmt = $conn->prepare(
            "SELECT dr.id,
                    dr.report_date,
                    dr.shift_id,
                    dr.yesterday_cash_balance,
                    dr.yesterday_cash_usage,
                    COALESCE(es.name, '') AS shift_name
             FROM   manual_daily_reports dr
             LEFT JOIN employee_shifts es ON es.id = dr.shift_id
             WHERE  dr.report_date = ?
             ORDER  BY dr.id DESC
             LIMIT  1"
        );
        if (!$stmt) return [];
        $stmt->bind_param('s', $latestDate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return [];

        // Poin 3: Jika telah berganti hari dari data terakhir di DB,
        // yesterday_cash_total = SUM(cash items dari laporan terakhir) + yesterday_cash_balance laporan terakhir.
        // Cash diidentifikasi dari payment_methods WHERE code = 'Cash' (atau name = 'Cash' sebagai fallback).
        $lastReportId       = (int)$row['id'];
        $ycBalance          = (float)$row['yesterday_cash_balance'];
        $cashItemsTotal     = 0.0;

        $stmtCash = $conn->prepare(
            "SELECT COALESCE(SUM(dri.amount), 0) AS cash_total
             FROM   manual_daily_report_items dri
             INNER JOIN payment_methods pm ON pm.id = dri.payment_method_id
             WHERE  dri.report_id = ?
               AND  (pm.code = 'Cash' OR pm.name = 'Cash')"
        );
        if ($stmtCash) {
            $stmtCash->bind_param('i', $lastReportId);
            $stmtCash->execute();
            $cashRow        = $stmtCash->get_result()->fetch_assoc();
            $cashItemsTotal = (float)($cashRow['cash_total'] ?? 0);
            $stmtCash->close();
        }

        $computedTotal = $cashItemsTotal + $ycBalance;

        return [[
            'total_cash'   => $computedTotal,
            'usage'        => (float)$row['yesterday_cash_usage'],
            'balance'      => $computedTotal,
            'report_date'  => (string)$row['report_date'],
            'shift_id'     => $row['shift_id'],
            'shift_name'   => trim((string)($row['shift_name'] ?? '')),
            'label'        => 'yesterday_cash_total',
            'field_suffix' => '',
            'is_same_day'  => false,
            'is_last'      => true,
        ]];
    }

    // Scenario B: same day — show ALL rows for today ordered by shift time then id
    $stmt = $conn->prepare(
        "SELECT dr.id,
                dr.report_date,
                dr.shift_id,
                dr.yesterday_cash_balance,
                dr.yesterday_cash_usage,
                COALESCE(es.name, '')               AS shift_name,
                COALESCE(es.start_time, '99:99:99') AS shift_start
         FROM   manual_daily_reports dr
         LEFT JOIN employee_shifts es ON es.id = dr.shift_id
         WHERE  dr.report_date = ?
         ORDER  BY es.start_time ASC, dr.id ASC"
    );
    if (!$stmt) return [];
    $stmt->bind_param('s', $latestDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rows)) return [];

    $total   = count($rows);
    $entries = [];
    $idx     = 0;

    foreach ($rows as $row) {
        $idx++;
        $shiftName = trim((string)($row['shift_name'] ?? ''));
        $label     = ($shiftName !== '') ? 'yesterday_cash_total_shift' : 'yesterday_cash_total';

        $entries[] = [
            'total_cash'   => (float)$row['yesterday_cash_balance'],
            'usage'        => (float)$row['yesterday_cash_usage'],
            'balance'      => (float)$row['yesterday_cash_balance'],
            'report_date'  => (string)$row['report_date'],
            'shift_id'     => $row['shift_id'],
            'shift_name'   => $shiftName,
            'label'        => $label,
            'field_suffix' => '_' . $idx,
            'is_same_day'  => true,
            'is_last'      => ($idx === $total),
        ];
    }

    return $entries;
}


/**
 * Fetch a single daily report by ID.
 */
function getDreportById(int $id): ?array {
    global $conn;
    $stmt = $conn->prepare(
        "SELECT dr.*, es.name AS shift_name, es.start_time, es.end_time
         FROM manual_daily_reports dr
         LEFT JOIN employee_shifts es ON es.id = dr.shift_id
         WHERE dr.id = ?"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/**
 * Fetch report items (payment breakdown) for a report.
 */
function getDreportItems(int $reportId): array {
    global $conn;
    $stmt = $conn->prepare(
        "SELECT dri.*,
                pm.name  AS method_name,
                mpm.name AS manual_method_name
         FROM manual_daily_report_items dri
         LEFT JOIN payment_methods        pm  ON pm.id  = dri.payment_method_id
         LEFT JOIN manual_payment_methods mpm ON mpm.id = dri.manual_payment_method_id
         WHERE dri.report_id = ?
         ORDER BY dri.id ASC"
    );
    if (!$stmt) return [];
    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['method_name'] = $row['method_name'] ?? $row['manual_method_name'] ?? '';
    }
    return $rows;
}

/**
 * Fetch optional items for a report (yesterday cash optional rows).
 */
function getDreportOptionalItems(int $reportId): array {
    global $conn;
    $stmt = $conn->prepare(
        "SELECT id, report_id, name, amount
         FROM manual_dreport_optional_items
         WHERE report_id = ?
         ORDER BY id ASC"
    );
    if (!$stmt) return [];
    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * List reports with optional filters. Returns array of rows.
 */
function getDreportList(array $filters = []): array {
    global $conn;

    $where  = ['1=1'];
    $params = [];
    $types  = '';

    if (!empty($filters['date_from'])) {
        $where[]  = 'dr.report_date >= ?';
        $params[] = $filters['date_from'];
        $types   .= 's';
    }
    if (!empty($filters['date_to'])) {
        $where[]  = 'dr.report_date <= ?';
        $params[] = $filters['date_to'];
        $types   .= 's';
    }
    if (!empty($filters['shift_id'])) {
        $where[]  = 'dr.shift_id = ?';
        $params[] = (int)$filters['shift_id'];
        $types   .= 'i';
    }
    if (!empty($filters['cashier_name'])) {
        $where[]  = 'dr.cashier_name LIKE ?';
        $params[] = '%' . $filters['cashier_name'] . '%';
        $types   .= 's';
    }
    // kasir can only see own reports (matched by cashier_name)
    if (!empty($filters['restrict_cashier_name'])) {
        $where[]  = 'dr.cashier_name = ?';
        $params[] = $filters['restrict_cashier_name'];
        $types   .= 's';
    }

    // created_by_user kini menyimpan NAMA secara langsung, bukan lagi FK ke
    // users. LEFT JOIN ke users DIHAPUS: selain tidak diperlukan lagi, ia
    // justru berbahaya — membandingkan u.id (angka) dengan sebuah nama akan
    // membuat MySQL memaksa nama menjadi 0 dan mencocokkannya ke ID 0.
    // Alias created_by_name DIPERTAHANKAN persis seperti sebelumnya supaya
    // seluruh kode tampilan yang membaca $row['created_by_name'] tidak perlu
    // diubah sedikit pun.
    $sql = "SELECT dr.*, es.name AS shift_name,
                   dr.created_by_user AS created_by_name
            FROM manual_daily_reports dr
            LEFT JOIN employee_shifts es ON es.id = dr.shift_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY dr.report_date DESC, dr.id DESC";

    if (empty($params)) {
        $res = $conn->query($sql);
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ============================================================
// CRUD OPERATIONS
// ============================================================

/**
 * Create a new daily report.
 * $data keys: report_date, cashier_name, shift_id, system_revenue, notes,
 *             image_file, yesterday_cash_total, yesterday_cash_usage,
 *             yesterday_cash_balance, yesterday_cash_optional, yesterday_cash_image,
 *             notes_cash, rpos_image
 * $items: array of ['payment_method_id' => int, 'amount' => float]
 * $optionalItems: array of ['name' => string, 'amount' => float]
 * Returns new record ID on success, 0 on failure.
 */
function createDreport(array $data, array $items, array $optionalItems = []): int {
    global $conn;

    $conn->begin_transaction();
    try {
        $totalManual = 0.0;
        foreach ($items as $item) {
            $totalManual += (float)($item['amount'] ?? 0);
        }
        $systemRevenue = (float)($data['system_revenue'] ?? 0);
        $difference    = $totalManual - $systemRevenue;

        $stmt = $conn->prepare(
            "INSERT INTO manual_daily_reports
             (report_date, cashier_name, shift_id, system_revenue, total_manual, difference,
              notes, image_file,
              yesterday_cash_total, yesterday_cash_usage, yesterday_cash_optional,
              yesterday_cash_balance,
              yesterday_cash_image, notes_cash, rpos_image,
              created_by_user, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) throw new \RuntimeException($conn->error);

        // NAMA pembuat, disimpan sebagai TEKS ke created_by_user.
        // Dibaca dari tabel users (bukan dari $_SESSION['full_name'], yang
        // bisa basi bila admin mengubah nama user di tengah sesi yang masih
        // hidup). Bila karena satu dan lain hal tidak ditemukan, cashier_name
        // dipakai sebagai cadangan — kolom itu memang sudah menyimpan
        // users.full_name secara langsung sejak awal.
        $userId              = (int)($_SESSION['user_id'] ?? 0);
        $creatorName         = '';
        if ($userId > 0) {
            $stmtU = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            if ($stmtU) {
                $stmtU->bind_param('i', $userId);
                $stmtU->execute();
                $rowU = $stmtU->get_result()->fetch_assoc();
                $stmtU->close();
                if ($rowU) $creatorName = (string)$rowU['full_name'];
            }
        }
        $cashierName         = $data['cashier_name']          ?? '';
        $shiftId             = (int)$data['shift_id'];
        $notes               = $data['notes']                 ?? '';
        $imgFile             = $data['image_file']            ?? '';
        $ycTotal             = (float)($data['yesterday_cash_total']    ?? 0);
        $ycUsage             = (float)($data['yesterday_cash_usage']    ?? 0);
        $ycOptional          = (float)($data['yesterday_cash_optional'] ?? 0);
        $ycBalance           = (float)($data['yesterday_cash_balance']  ?? 0);
        $ycImage             = $data['yesterday_cash_image']  ?? '';
        $notesCash           = $data['notes_cash']            ?? '';
        $rposImage           = $data['rpos_image']            ?? '';
        if ($creatorName === '') $creatorName = (string)$cashierName;

        $stmt->bind_param(
            'ssidddssddddssss',
            $data['report_date'],
            $cashierName,
            $shiftId,
            $systemRevenue,
            $totalManual,
            $difference,
            $notes,
            $imgFile,
            $ycTotal,
            $ycUsage,
            $ycOptional,
            $ycBalance,
            $ycImage,
            $notesCash,
            $rposImage,
            $creatorName
        );

        if (!$stmt->execute()) throw new \RuntimeException($stmt->error);
        $reportId = (int)$conn->insert_id;
        $stmt->close();

        if (!empty($items)) {
            $stmtItem = $conn->prepare(
                "INSERT INTO manual_daily_report_items
                 (report_id, payment_method_id, manual_payment_method_id, amount)
                 VALUES (?, ?, ?, ?)"
            );
            if (!$stmtItem) throw new \RuntimeException($conn->error);
            foreach ($items as $item) {
                $pmId       = null;
                $manualPmId = null;
                if (isset($item['payment_method_id']) && $item['payment_method_id'] > 0) {
                    $pmId = (int)$item['payment_method_id'];
                } else {
                    $manualPmId = resolveOrCreateManualPaymentMethod($item['payment_method_name'] ?? '');
                }
                $amount = (float)($item['amount'] ?? 0);
                $stmtItem->bind_param('iiid', $reportId, $pmId, $manualPmId, $amount);
                if (!$stmtItem->execute()) throw new \RuntimeException($stmtItem->error);
            }
            $stmtItem->close();
        }

        if (!empty($optionalItems)) {
            $stmtOpt = $conn->prepare(
                "INSERT INTO manual_dreport_optional_items (report_id, name, amount)
                 VALUES (?, ?, ?)"
            );
            if (!$stmtOpt) throw new \RuntimeException($conn->error);
            foreach ($optionalItems as $opt) {
                $optName   = substr(trim($opt['name'] ?? ''), 0, 255);
                $optAmount = (float)($opt['amount'] ?? 0);
                $stmtOpt->bind_param('isd', $reportId, $optName, $optAmount);
                if (!$stmtOpt->execute()) throw new \RuntimeException($stmtOpt->error);
            }
            $stmtOpt->close();
        }

        $conn->commit();
        if (function_exists('logActivity')) {
            logActivity($userId, 'create_dreport', "Created daily report ID $reportId for date {$data['report_date']}");
        }
        return $reportId;

    } catch (\Throwable $e) {
        $conn->rollback();
        error_log('createDreport error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Update existing daily report.
 * Handles optional image replacement for all 3 photo fields.
 */
function updateDreport(int $id, array $data, array $items, array $optionalItems = []): bool {
    global $conn;

    $conn->begin_transaction();
    try {
        $totalManual   = 0.0;
        foreach ($items as $item) {
            $totalManual += (float)($item['amount'] ?? 0);
        }
        $systemRevenue = (float)($data['system_revenue'] ?? 0);
        $difference    = $totalManual - $systemRevenue;

        $cashierName  = $data['cashier_name']  ?? '';
        $shiftId      = (int)$data['shift_id'];
        $notes        = $data['notes']         ?? '';
        $imgFile      = $data['image_file']    ?? null;
        $imgCleared   = !empty($data['image_cleared']);
        $ycTotal      = (float)($data['yesterday_cash_total']    ?? 0);
        $ycUsage      = (float)($data['yesterday_cash_usage']    ?? 0);
        $ycOptional   = (float)($data['yesterday_cash_optional'] ?? 0);
        $ycBalance    = (float)($data['yesterday_cash_balance']  ?? 0);
        $ycImage      = $data['yesterday_cash_image']  ?? null;
        $ycCleared    = !empty($data['yesterday_cash_image_cleared']);
        $notesCash    = $data['notes_cash']    ?? '';
        $rposImage    = $data['rpos_image']    ?? null;
        $rposCleared  = !empty($data['rpos_image_cleared']);

        // Build SET clause dynamically for image fields
        $setClauses = [
            'report_date=?', 'cashier_name=?', 'shift_id=?',
            'system_revenue=?', 'total_manual=?', 'difference=?',
            'notes=?',
            'yesterday_cash_total=?', 'yesterday_cash_usage=?',
            'yesterday_cash_optional=?', 'yesterday_cash_balance=?',
            'notes_cash=?',
            'updated_at=NOW()'
        ];
        $bindTypes  = 'ssidddsdddds';
        $bindValues = [
            $data['report_date'], $cashierName, $shiftId,
            $systemRevenue, $totalManual, $difference,
            $notes,
            $ycTotal, $ycUsage, $ycOptional, $ycBalance,
            $notesCash,
        ];

        // summary image
        if ($imgFile !== null && $imgFile !== '') {
            $setClauses[] = 'image_file=?';
            $bindTypes   .= 's';
            $bindValues[] = $imgFile;
        } elseif ($imgCleared) {
            $setClauses[] = 'image_file=?';
            $bindTypes   .= 's';
            $bindValues[] = '';
        }

        // yesterday cash image
        if ($ycImage !== null && $ycImage !== '') {
            $setClauses[] = 'yesterday_cash_image=?';
            $bindTypes   .= 's';
            $bindValues[] = $ycImage;
        } elseif ($ycCleared) {
            $setClauses[] = 'yesterday_cash_image=?';
            $bindTypes   .= 's';
            $bindValues[] = '';
        }

        // rpos image
        if ($rposImage !== null && $rposImage !== '') {
            $setClauses[] = 'rpos_image=?';
            $bindTypes   .= 's';
            $bindValues[] = $rposImage;
        } elseif ($rposCleared) {
            $setClauses[] = 'rpos_image=?';
            $bindTypes   .= 's';
            $bindValues[] = '';
        }

        $bindTypes   .= 'i';
        $bindValues[] = $id;

        $sql  = "UPDATE manual_daily_reports SET " . implode(', ', $setClauses) . " WHERE id=?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new \RuntimeException($conn->error);
        $stmt->bind_param($bindTypes, ...$bindValues);
        if (!$stmt->execute()) throw new \RuntimeException($stmt->error);
        $stmt->close();

        $delStmt = $conn->prepare("DELETE FROM manual_daily_report_items WHERE report_id = ?");
        $delStmt->bind_param('i', $id);
        $delStmt->execute();
        $delStmt->close();

        if (!empty($items)) {
            $stmtItem = $conn->prepare(
                "INSERT INTO manual_daily_report_items
                 (report_id, payment_method_id, manual_payment_method_id, amount)
                 VALUES (?, ?, ?, ?)"
            );
            foreach ($items as $item) {
                $pmId       = null;
                $manualPmId = null;
                if (isset($item['payment_method_id']) && $item['payment_method_id'] > 0) {
                    $pmId = (int)$item['payment_method_id'];
                } else {
                    $manualPmId = resolveOrCreateManualPaymentMethod($item['payment_method_name'] ?? '');
                }
                $amount = (float)($item['amount'] ?? 0);
                $stmtItem->bind_param('iiid', $id, $pmId, $manualPmId, $amount);
                if (!$stmtItem->execute()) throw new \RuntimeException($stmtItem->error);
            }
            $stmtItem->close();
        }

        // Replace optional items
        $delOpt = $conn->prepare("DELETE FROM manual_dreport_optional_items WHERE report_id = ?");
        $delOpt->bind_param('i', $id);
        $delOpt->execute();
        $delOpt->close();

        if (!empty($optionalItems)) {
            $stmtOpt = $conn->prepare(
                "INSERT INTO manual_dreport_optional_items (report_id, name, amount)
                 VALUES (?, ?, ?)"
            );
            if (!$stmtOpt) throw new \RuntimeException($conn->error);
            foreach ($optionalItems as $opt) {
                $optName   = substr(trim($opt['name'] ?? ''), 0, 255);
                $optAmount = (float)($opt['amount'] ?? 0);
                $stmtOpt->bind_param('isd', $id, $optName, $optAmount);
                if (!$stmtOpt->execute()) throw new \RuntimeException($stmtOpt->error);
            }
            $stmtOpt->close();
        }

        $conn->commit();
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if (function_exists('logActivity')) {
            logActivity($userId, 'update_dreport', "Updated daily report ID $id");
        }
        return true;

    } catch (\Throwable $e) {
        $conn->rollback();
        error_log('updateDreport error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Delete a daily report and its items. Also deletes all associated image files.
 */
function deleteDreport(int $id): bool {
    global $conn;

    $row = getDreportById($id);

    $conn->begin_transaction();
    try {
        $s1 = $conn->prepare("DELETE FROM manual_daily_report_items WHERE report_id = ?");
        $s1->bind_param('i', $id);
        $s1->execute();
        $s1->close();

        $s1b = $conn->prepare("DELETE FROM manual_dreport_optional_items WHERE report_id = ?");
        $s1b->bind_param('i', $id);
        $s1b->execute();
        $s1b->close();

        $s2 = $conn->prepare("DELETE FROM manual_daily_reports WHERE id = ?");
        $s2->bind_param('i', $id);
        if (!$s2->execute()) throw new \RuntimeException($s2->error);
        $s2->close();

        $conn->commit();

        if ($row) {
            if (!empty($row['image_file']))            deleteDreportImageByType($row['image_file'],            'summary');
            if (!empty($row['yesterday_cash_image']))  deleteDreportImageByType($row['yesterday_cash_image'],  'yesterday_cash');
            if (!empty($row['rpos_image']))            deleteDreportImageByType($row['rpos_image'],            'rpos');
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if (function_exists('logActivity')) {
            logActivity($userId, 'delete_dreport', "Deleted daily report ID $id");
        }
        return true;

    } catch (\Throwable $e) {
        $conn->rollback();
        error_log('deleteDreport error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get yesterday cash total for display in the list page header area.
 * Uses the same logic as getDreportYesterdayCashMulti():
 *   - If latest data is a PAST date → cash items total + yesterday_cash_balance of last row.
 *   - If latest data is TODAY → yesterday_cash_balance of the last shift row for today.
 * Returns float (the computed total_cash of the last entry), or 0.0 if no data.
 */
function getDreportHeaderYesterdayCash(): float {
    $entries = getDreportYesterdayCashMulti(date('Y-m-d'));
    if (empty($entries)) {
        return 0.0;
    }
    $last = $entries[count($entries) - 1];
    return (float)($last['total_cash'] ?? 0.0);
}

// ============================================================
// FORMAT HELPERS
// ============================================================

function dreportFormatMoney(float $amount): string {
    if (function_exists('formatCurrency')) {
        return formatCurrency($amount);
    }
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function dreportDiffClass(float $diff): string {
    if ($diff > 0) return 'text-success';
    if ($diff < 0) return 'text-danger';
    return 'text-muted';
}

function dreportDiffLabel(float $diff): string {
    if ($diff > 0) return '+' . dreportFormatMoney($diff);
    if ($diff < 0) return dreportFormatMoney($diff);
    return dreportFormatMoney(0);
}

/**
 * Fetch all data needed for view page (report + items + upload URLs).
 * Returns null if report not found.
 */
function getDreportViewData(int $id): ?array {
    $report = getDreportById($id);
    if (!$report) return null;

    $items = getDreportItems($id);

    return [
        'report' => $report,
        'items'  => $items,
        'urls'   => [
            'summary'        => _dreportUploadUrl('summary'),
            'yesterday_cash' => _dreportUploadUrl('yesterday_cash'),
            'rpos'           => _dreportUploadUrl('rpos'),
        ],
    ];
}

// ============================================================
// YESTERDAY CASH VIEW — manual_yesterdaycash_view.php
// ============================================================

/**
 * Require access for Yesterday Cash View page.
 * Same permission as dreport (can_access). Supports AJAX request check.
 */
function requireYesterdayCashViewAccess(): void {
    if (!isLoggedIn()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(403);
            echo '<div class="alert alert-danger m-3"><i class="bi bi-lock me-2"></i>Access denied.</div>';
            exit;
        }
        redirect(url('index.php'));
        exit;
    }
    $perm = _getDreportPerm();
    if (!$perm['can_access']) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            http_response_code(403);
            echo '<div class="alert alert-danger m-3"><i class="bi bi-lock me-2"></i>Access denied.</div>';
            exit;
        }
        redirectNoAccess();
    }
}

/**
 * Fetch yesterday cash detail data for the view modal.
 * Returns an array of entries with full detail:
 *   - report header: date, cashier_name, shift_name
 *   - yesterday_cash_total, yesterday_cash_usage, yesterday_cash_optional, yesterday_cash_balance
 *   - yesterday_cash_image
 *   - notes_cash
 *   - cash_items: payment items with method_name and amount
 *   - optional_items: array of optional cash items
 *   - upload_url_cash: URL for yesterday_cash images
 * Uses getDreportYesterdayCashMulti() logic to determine which entries to show.
 * Returns [] if no data.
 */
/**
 * Get yesterday_cash_balance of the last-inserted row (MAX id) for a given date.
 * "Nilai terakhir dari inputan terakhir" = saldo uang kas yang dibawa masuk
 * ke tanggal tersebut, diambil dari field yesterday_cash_balance baris terakhir.
 *
 * @param  string $refDate  'Y-m-d' tanggal referensi.
 * @return float            yesterday_cash_balance dari row MAX(id), 0.0 jika tidak ada data.
 */
function getDreportLastBalanceForDate(string $refDate): float {
    global $conn;

    $stmt = $conn->prepare(
        "SELECT yesterday_cash_balance
         FROM   manual_daily_reports
         WHERE  report_date = ?
         ORDER  BY id DESC
         LIMIT  1"
    );
    if (!$stmt) return 0.0;
    $stmt->bind_param('s', $refDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (float)$row['yesterday_cash_balance'] : 0.0;
}

/**
 * Get SUM of Cash payment items from the LAST-INSERTED row (MAX id) of a given date.
 * "Nilai terakhir dari inputan terakhir" = cash items dari baris dengan id tertinggi
 * pada tanggal tersebut, bukan SUM dari semua shift/baris tanggal itu.
 *
 * @param  string $refDate  'Y-m-d' tanggal referensi.
 * @return float            SUM(amount) metode Cash dari row MAX(id), 0.0 jika tidak ada.
 */
function getDreportSumCashForDate(string $refDate): float {
    global $conn;

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(dri.amount), 0) AS cash_total
         FROM   manual_daily_report_items dri
         INNER JOIN payment_methods pm ON pm.id = dri.payment_method_id
         WHERE  dri.report_id = (
                    SELECT MAX(id)
                    FROM   manual_daily_reports
                    WHERE  report_date = ?
                )
           AND  (pm.code = 'Cash' OR pm.name = 'Cash')"
    );
    if (!$stmt) return 0.0;
    $stmt->bind_param('s', $refDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float)($row['cash_total'] ?? 0);
}

/**
 * Get summary data for Yesterday Cash View modal.
 *
 * Definisi nilai (kondisi: hari berganti, today_cash = 0):
 *   - two_days_ago_total   = yesterday_cash_balance dari row MAX(id) tanggal ke-1 di DB
 *                            (saldo uang kas yang dibawa masuk ke tanggal ke-1)
 *   - yesterday_cash_total = SUM(cash items) dari row MAX(id) tanggal ke-1 di DB
 *                            (pemasukan cash aktual dari inputan terakhir hari kemarin)
 *   - today_cash_total     = SUM(cash items) report CURDATE(), 0 jika belum ada
 *   - grand_total          = two_days_ago_total + yesterday_cash_total + today_cash_total
 *
 * Returns a single array with:
 *   - has_data             : bool
 *   - yesterday_cash_total : float
 *   - yesterday_date       : string — 'Y-m-d' tanggal ke-1 di DB
 *   - today_cash_total     : float
 *   - today_date           : string — 'Y-m-d' CURDATE()
 *   - grand_total          : float
 *   - show_two_days_ago    : bool   — true hanya jika today_cash_total = 0
 *   - two_days_ago_total   : float
 *   - two_days_ago_date    : string — 'Y-m-d' tanggal ke-1 di DB (sumber saldo bawaan)
 */
function getDreportYesterdayCashSummary(): array {
    global $conn;

    $today = date('Y-m-d');

    $empty = [
        'has_data'             => false,
        'yesterday_cash_total' => 0.0,
        'yesterday_date'       => '',
        'today_cash_total'     => 0.0,
        'today_date'           => $today,
        'grand_total'          => 0.0,
        'show_two_days_ago'    => false,
        'two_days_ago_total'   => 0.0,
        'two_days_ago_date'    => '',
    ];

    // Step 1: ambil tanggal distinct terakhir ke-1 di DB.
    $stmtMax = $conn->prepare(
        "SELECT MAX(report_date) AS latest_date FROM manual_daily_reports"
    );
    if (!$stmtMax) return $empty;
    $stmtMax->execute();
    $maxRow = $stmtMax->get_result()->fetch_assoc();
    $stmtMax->close();

    $latestDate = $maxRow['latest_date'] ?? '';
    if ($latestDate === '') return $empty;

    // Step 2: two_days_ago_total = yesterday_cash_balance dari row MAX(id)
    //         tanggal ke-1 di DB (saldo uang kas yang dibawa masuk ke tanggal ke-1).
    $twoDaysAgoTotal = getDreportLastBalanceForDate($latestDate);

    // Step 3: yesterday_cash_total = SUM(cash items) semua report tanggal ke-1 di DB.
    //         Tidak termasuk balance — balance sudah dihitung terpisah sebagai 2 days ago.
    //         grand_total = yesterday_cash_total + two_days_ago_total
    //                     = SUM(cash tgl ke-1) + yesterday_cash_balance(row terakhir tgl ke-1)
    //                     = hasil getDreportHeaderYesterdayCash() (Scenario A).
    $ycTotal = getDreportSumCashForDate($latestDate);

    // Step 4: today_cash_total — SUM cash items dari row MAX(id) laporan hari ini (CURDATE()).
    //         Menggunakan getDreportSumCashForDate($today) agar konsisten: hanya mengambil
    //         data dari row terakhir (MAX id) tanggal hari ini, bukan SUM semua row.
    //         Jika belum ada laporan hari ini, hasilnya 0.
    $todayCashTotal = getDreportSumCashForDate($today);

    // Step 5: jika today_cash_total != 0 (sudah ada laporan hari ini setelah ganti hari),
    //         yesterday_cash_total = yesterday_cash_balance dari row terakhir (MAX id) hari ini.
    if ($todayCashTotal != 0.0) {
        $ycTotal = getDreportLastBalanceForDate($today);
    }

    // Step 6: show_two_days_ago — tampil hanya jika today_cash_total = 0.
    $showTwoDaysAgo = ($todayCashTotal == 0.0);

    // grand_total hanya menjumlahkan baris yang benar-benar ditampilkan di UI.
    // Jika show_two_days_ago=false (sudah ada laporan hari ini), two_days_ago
    // tidak ditampilkan sehingga tidak diikutkan dalam grand_total.
    $grandTotal = $showTwoDaysAgo
        ? $twoDaysAgoTotal + $ycTotal + $todayCashTotal
        : $ycTotal + $todayCashTotal;

    return [
        'has_data'             => true,
        'yesterday_cash_total' => $ycTotal,
        'yesterday_date'       => $latestDate,
        'today_cash_total'     => $todayCashTotal,
        'today_date'           => $today,
        'grand_total'          => $grandTotal,
        'show_two_days_ago'    => $showTwoDaysAgo,
        'two_days_ago_total'   => $twoDaysAgoTotal,
        'two_days_ago_date'    => $latestDate,
    ];
}