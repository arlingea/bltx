<?php
/**
 * purchase_returns_function.php
 * File: public_html/bltx/includes/purchase_returns_function.php
 *
 * Helper functions untuk PURCHASE RETURN — FLOW BARU
 * (Purchase Order → Goods Receipt → Purchase Invoice → Purchase Return).
 *
 * Prefix fungsi : pret_    (pf_ = purchases, po_ = purchase orders,
 *                           gr_ = goods receipt, pret_ = purchase return)
 *
 * HUBUNGAN DENGAN RETURN EXISTING:
 *   Tabel `returns` yang lama TIDAK DISENTUH sama sekali dan tetap menjadi
 *   historical data. Halaman purchases/returns.php tetap menampilkannya persis
 *   seperti sebelumnya. Flow baru menyimpan datanya di tabel `purchase_returns`
 *   + `purchase_return_items` yang ditambahkan secara additive, karena flow
 *   baru memerlukan header/detail dan referensi PO/GR/Invoice yang tidak
 *   tertampung pada struktur lama.
 *
 * ATURAN MODUL INI:
 *   - TIDAK ADA update inventory / stock movement / stock balance.
 *   - Return tidak boleh melebihi qty yang tersedia untuk dikembalikan.
 *   - Return yang sudah approved/processed TIDAK BOLEH dihapus; gunakan
 *     pembatalan (status 'cancelled') supaya histori tetap utuh.
 *   - Identitas user di-snapshot (created_by_name, approved_by_name,
 *     cancelled_by_name) sehingga histori tidak pernah kehilangan nama.
 *
 * DO NOT access this file directly — must be included from an authenticated page.
 */

if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

if (!function_exists('pret_csrfToken')) {

/* ============================================================
   TABLE EXISTENCE
   ============================================================ */

function pret_tablesExist(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;

    $report_mode = mysqli_report(MYSQLI_REPORT_OFF);
    $ok = false;
    try {
        $res = @$conn->query("SHOW TABLES LIKE 'purchase_returns'");
        $ok  = $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        $ok = false;
    }
    mysqli_report($report_mode);
    return $cached = $ok;
}

/* ============================================================
   CSRF
   ============================================================ */

/* RONDE PENYATUAN CSRF — fungsi di bawah kini hanya MENERUSKAN.

   Kunci sesi lama 'pret_csrf_token' tidak dipakai lagi. Token, cara pembentukan,
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
function pret_csrfToken(): string {
    return bltx_csrf_token();
}

function pret_csrfVerify(string $token): bool {
    return bltx_csrf_valid($token);
}

function pret_csrfRegenerate(): string {
    /* Rotasi token DIHILANGKAN (lihat purchase_settings.php Tahap 2).
       Token kini dipakai bersama seluruh sistem; memutarnya di satu modul
       membatalkan formulir yang sedang terbuka di modul lain. Fungsi ini
       dipertahankan agar pemanggilnya tidak perlu diubah, dan mengembalikan
       token yang berlaku sekarang. */
    return bltx_csrf_token();
}

/* ============================================================
   USER IDENTITY SNAPSHOT
   ============================================================ */

/** Nama user login untuk di-snapshot ke transaksi (histori permanen). */
function pret_currentUserName(mysqli $conn): string {
    if (function_exists('gr_currentUserName')) {
        return gr_currentUserName($conn);
    }
    $name = trim((string)($_SESSION['full_name'] ?? ''));
    if ($name !== '') return $name;

    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        $stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && trim((string)$row['full_name']) !== '') {
                return trim((string)$row['full_name']);
            }
        }
    }
    return trim((string)($_SESSION['username'] ?? ''));
}

/* ============================================================
   NUMBER GENERATOR
   ============================================================ */

/**
 * Nomor Purchase Return.
 * Format : #PREFIX + YYYYMMDD + NNN   contoh  #RET20260822001
 *   - PREFIX dari setting "return_prefix" (Settings > General), default "RET"
 *   - sequence berjalan per BULAN kalender
 */
function pret_generateNumber(mysqli $conn): string {
    $prefix    = (string) getSetting('return_prefix', 'RET');
    $dateFull  = date('Ymd');
    $dateMonth = date('Ym');
    $next      = 1;

    if (pret_tablesExist($conn)) {
        $like = '#' . $prefix . $dateMonth . '%';
        $stmt = $conn->prepare(
            "SELECT return_number FROM purchase_returns
              WHERE return_number LIKE ?
              ORDER BY id DESC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $last = '';
            $stmt->bind_result($last);
            if ($stmt->fetch() && $last !== '') {
                $seq  = (int) substr($last, -3);
                $next = $seq + 1;
            }
            $stmt->close();
        }
    }

    return '#' . $prefix . $dateFull . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/* ============================================================
   STATUS HELPERS
   ============================================================ */

function pret_statusColor(string $status): string {
    switch ($status) {
        case 'approved':  return 'primary';
        case 'processed': return 'success';
        case 'rejected':  return 'danger';
        case 'cancelled': return 'secondary';
        default:          return 'warning'; // pending
    }
}

function pret_statusLabel(string $status): string {
    global $LANG;
    $map = [
        'pending'   => $LANG['pending'],
        'approved'  => $LANG['approved'],
        'rejected'  => $LANG['rejected'],
        'processed' => $LANG['pr_status_processed'],
        'cancelled' => $LANG['cancelled'],
    ];
    return $map[$status] ?? $status;
}

/** Status yang mengunci dokumen dari perubahan/penghapusan. */
function pret_isLocked(string $status): bool {
    return in_array($status, ['approved', 'processed', 'rejected', 'cancelled'], true);
}

/* ============================================================
   LIST + COUNT
   ============================================================ */

function pret_buildListWhere(array $f): array {
    $where  = ' WHERE 1=1';
    $types  = '';
    $params = [];

    // ── REPORT DATA SCOPE (Own Data Only) ─────────────────────────────────
    // Purchase Return memakai permission modul EXISTING 'returns', termasuk
    // untuk cakupan datanya — tidak ada modul permission kedua.
    if (function_exists('canViewAllPurchaseReturn')) {
        $scopeOwner = permScopeOwnerId(canViewAllPurchaseReturn());
        if ($scopeOwner !== null) {
            $where   .= ' AND pr.created_by = ?';
            $params[] = $scopeOwner;
            $types   .= 'i';
        }
    }

    $search = trim((string)($f['search'] ?? ''));
    if ($search !== '') {
        $where .= " AND (pr.return_number LIKE ? OR pr.po_number LIKE ? OR pr.gr_number LIKE ?
                         OR pr.supplier_name LIKE ? OR pr.created_by_name LIKE ?)";
        $like = '%' . $search . '%';
        for ($i = 0; $i < 5; $i++) { $params[] = $like; $types .= 's'; }
    }
    if (!empty($f['status'])) {
        $where   .= ' AND pr.status = ?';
        $params[] = (string)$f['status'];
        $types   .= 's';
    }
    if (!empty($f['supplier_id'])) {
        $where   .= ' AND pr.supplier_id = ?';
        $params[] = (int)$f['supplier_id'];
        $types   .= 'i';
    }
    if (!empty($f['from_date'])) {
        $where   .= ' AND pr.return_date >= ?';
        $params[] = (string)$f['from_date'];
        $types   .= 's';
    }
    if (!empty($f['to_date'])) {
        $where   .= ' AND pr.return_date <= ?';
        $params[] = (string)$f['to_date'];
        $types   .= 's';
    }
    return [$where, $types, $params];
}

function pret_getList(mysqli $conn, array $filters = [], int $limit = 25, int $offset = 0): array {
    if (!pret_tablesExist($conn)) return [];

    [$where, $types, $params] = pret_buildListWhere($filters);
    // Batas atas dinaikkan menjadi 5000 karena daftar Purchase Return dirender
    // dengan DataTables client-side (paginasi & pencarian dilakukan di browser,
    // sama seperti daftar Return lama). Batas ini tetap ada sebagai pengaman
    // agar query tidak pernah unbounded.
    $limit  = max(1, min(5000, $limit));
    $offset = max(0, $offset);

    $sql = "SELECT pr.id, pr.return_number, pr.po_id, pr.po_number, pr.gr_id, pr.gr_number,
                   pr.purchase_id, pr.supplier_id, pr.supplier_name, pr.return_date,
                   pr.status, pr.total_amount, pr.reason, pr.created_by_name, pr.created_at
              FROM purchase_returns pr"
         . $where
         . " ORDER BY pr.return_date DESC, pr.id DESC
             LIMIT ? OFFSET ?";

    $params[] = $limit;  $types .= 'i';
    $params[] = $offset; $types .= 'i';

    $rows = [];
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $rows;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function pret_countList(mysqli $conn, array $filters = []): int {
    if (!pret_tablesExist($conn)) return 0;

    [$where, $types, $params] = pret_buildListWhere($filters);
    $sql = "SELECT COUNT(*) AS c FROM purchase_returns pr" . $where;

    if ($params) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return 0;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $res = $conn->query($sql);
        $row = $res ? $res->fetch_assoc() : null;
    }
    return (int)($row['c'] ?? 0);
}

/* ============================================================
   SINGLE RECORD
   ============================================================ */

function pret_getOne(mysqli $conn, int $id): ?array {
    if (!pret_tablesExist($conn)) return null;

    $stmt = $conn->prepare(
        "SELECT pr.*, s.name AS supplier_current_name
           FROM purchase_returns pr
           LEFT JOIN suppliers s ON s.id = pr.supplier_id
          WHERE pr.id = ?"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $row['items'] = pret_getItems($conn, $id);
    return $row;
}

function pret_getItems(mysqli $conn, int $returnId): array {
    $stmt = $conn->prepare(
        "SELECT ri.*, u.name AS unit_name, u.symbol AS unit_symbol
           FROM purchase_return_items ri
           LEFT JOIN units u ON u.id = ri.unit_id
          WHERE ri.return_id = ?
          ORDER BY ri.id ASC"
    );
    $rows = [];
    if ($stmt) {
        $stmt->bind_param('i', $returnId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
    }
    return $rows;
}

/* ============================================================
   AVAILABLE-TO-RETURN CALCULATION
   ============================================================ */

/**
 * Qty yang SUDAH pernah dikembalikan per baris Goods Receipt.
 * Return berstatus 'rejected' dan 'cancelled' tidak dihitung karena tidak
 * pernah benar-benar terjadi.
 *
 * @return array [gr_item_id => float]
 */
function pret_returnedByGrItem(mysqli $conn, int $grId, int $excludeReturnId = 0): array {
    $out = [];
    if (!pret_tablesExist($conn) || $grId <= 0) return $out;

    $sql = "SELECT ri.gr_item_id, SUM(ri.quantity) AS qty
              FROM purchase_return_items ri
              JOIN purchase_returns pr ON pr.id = ri.return_id
             WHERE pr.gr_id = ?
               AND pr.status NOT IN ('rejected','cancelled')
               AND ri.gr_item_id IS NOT NULL";
    $types  = 'i';
    $params = [$grId];
    if ($excludeReturnId > 0) {
        $sql .= " AND pr.id <> ?";
        $types   .= 'i';
        $params[] = $excludeReturnId;
    }
    $sql .= " GROUP BY ri.gr_item_id";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return $out;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $out[(int)$r['gr_item_id']] = (float)$r['qty'];
    $stmt->close();
    return $out;
}

/**
 * Baris Goods Receipt beserta sisa yang masih boleh dikembalikan.
 *   available = accepted_quantity - sudah_dikembalikan
 */
function pret_availableFromGr(mysqli $conn, int $grId, int $excludeReturnId = 0): array {
    $out = [];
    if (!function_exists('gr_getItems') || $grId <= 0) return $out;

    $items    = gr_getItems($conn, $grId);
    $returned = pret_returnedByGrItem($conn, $grId, $excludeReturnId);

    foreach ($items as $it) {
        $accepted = (float)$it['accepted_quantity'];
        if ($accepted <= 0) continue;
        $already  = (float)($returned[(int)$it['id']] ?? 0);
        $avail    = $accepted - $already;
        $out[] = [
            'gr_item_id'   => (int)$it['id'],
            'po_item_id'   => $it['po_item_id'] !== null ? (int)$it['po_item_id'] : null,
            'inventory_id' => $it['inventory_id'] !== null ? (int)$it['inventory_id'] : null,
            'item_name'    => $it['item_name'],
            'unit_id'      => $it['unit_id'] !== null ? (int)$it['unit_id'] : null,
            'unit_symbol'  => $it['unit_symbol'] ?: ($it['unit_name'] ?: ''),
            'unit_price'   => (float)$it['unit_price'],
            'accepted'     => $accepted,
            'returned'     => $already,
            'available'    => $avail > 0 ? $avail : 0.0,
        ];
    }
    return $out;
}

/* ============================================================
   NORMALISASI ITEM DARI $_POST
   ============================================================ */

/**
 * @return array ['items' => array, 'error' => string|null]
 */
function pret_normalizePostedItems(array $post): array {
    $names   = $post['item_name']        ?? [];
    $grItems = $post['gr_item_id']       ?? [];
    $poItems = $post['po_item_id']       ?? [];
    $piItems = $post['purchase_item_id'] ?? [];
    $invIds  = $post['inventory_id']     ?? [];
    $qtys    = $post['quantity']         ?? [];
    $units   = $post['unit_id']          ?? [];
    $prices  = $post['unit_price']       ?? [];
    $reasons = $post['item_reason']      ?? [];

    if (!is_array($names)) {
        return ['items' => [], 'error' => 'pr_items_required'];
    }

    $items = [];
    $count = count($names);
    for ($i = 0; $i < $count; $i++) {
        $name = trim((string)($names[$i] ?? ''));
        if ($name === '') continue;

        $qty = (float)($qtys[$i] ?? 0);
        if ($qty <= 0) continue;   // baris yang tidak dikembalikan → dilewati

        $price = (float)($prices[$i] ?? 0);
        if ($price < 0) {
            return ['items' => [], 'error' => 'pr_negative_amount'];
        }

        $items[] = [
            'gr_item_id'       => (int)($grItems[$i] ?? 0) ?: null,
            'purchase_item_id' => (int)($piItems[$i] ?? 0) ?: null,
            'po_item_id'       => (int)($poItems[$i] ?? 0) ?: null,
            'inventory_id'     => (int)($invIds[$i] ?? 0) ?: null,
            'item_name'        => $name,
            'quantity'         => $qty,
            'unit_id'          => (int)($units[$i] ?? 0) ?: null,
            'unit_price'       => $price,
            'total_price'      => $qty * $price,
            'reason'           => trim((string)($reasons[$i] ?? '')) ?: null,
        ];
    }

    if (!$items) {
        return ['items' => [], 'error' => 'pr_items_required'];
    }
    return ['items' => $items, 'error' => null];
}

/**
 * Validasi qty return terhadap sisa yang tersedia pada Goods Receipt.
 * Return tidak boleh melebihi (accepted - sudah dikembalikan).
 *
 * @return string|null  message_key bila gagal
 */
function pret_validateAgainstGr(mysqli $conn, int $grId, array $items, int $excludeReturnId = 0): ?string {
    if ($grId <= 0) return null;

    $avail = [];
    foreach (pret_availableFromGr($conn, $grId, $excludeReturnId) as $row) {
        $avail[$row['gr_item_id']] = $row['available'];
    }

    foreach ($items as $it) {
        $grItemId = (int)($it['gr_item_id'] ?? 0);
        if ($grItemId <= 0) continue;
        if (!isset($avail[$grItemId])) {
            return 'pr_gr_item_mismatch';   // anti ID tampering
        }
        if ((float)$it['quantity'] > $avail[$grItemId] + 0.00001) {
            return 'pr_qty_exceeds_available';
        }
    }
    return null;
}

/* ============================================================
   CREATE
   ============================================================ */

function pret_create(mysqli $conn, array $post): array {
    if (!isLoggedIn())            return ['success' => false, 'message_key' => 'no_permission'];
    if (!canCreatePurchaseReturn())
                                  return ['success' => false, 'message_key' => 'no_permission'];
    if (!pret_csrfVerify((string)($post['csrf_token'] ?? '')))
                                  return ['success' => false, 'message_key' => 'invalid_csrf'];
    if (!pret_tablesExist($conn)) return ['success' => false, 'message_key' => 'pr_tables_missing'];

    $grId       = (int)($post['gr_id'] ?? 0);
    $purchaseId = (int)($post['purchase_id'] ?? 0);
    $returnDate = trim((string)($post['return_date'] ?? ''));
    $reason     = trim((string)($post['reason'] ?? ''));
    $notes      = trim((string)($post['notes'] ?? ''));

    if ($returnDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
        return ['success' => false, 'message_key' => 'pr_return_date_required'];
    }
    if ($reason === '') {
        return ['success' => false, 'message_key' => 'pr_reason_required'];
    }

    $norm = pret_normalizePostedItems($post);
    if ($norm['error'] !== null) {
        return ['success' => false, 'message_key' => $norm['error']];
    }
    $items = $norm['items'];

    // Header referensi diambil dari database, BUKAN dari form (anti tampering)
    $poId = null; $poNumber = null; $grNumber = null;
    $supplierId = null; $supplierName = null;

    if ($grId > 0) {
        if (!function_exists('gr_getOne')) {
            return ['success' => false, 'message_key' => 'pr_tables_missing'];
        }
        $gr = gr_getOne($conn, $grId);
        if (!$gr) return ['success' => false, 'message_key' => 'gr_not_found'];
        if ($gr['status'] !== 'received') {
            return ['success' => false, 'message_key' => 'pr_gr_not_received'];
        }
        $poId         = $gr['po_id'] !== null ? (int)$gr['po_id'] : null;
        $poNumber     = $gr['po_number'];
        $grNumber     = $gr['gr_number'];
        $supplierId   = $gr['supplier_id'] !== null ? (int)$gr['supplier_id'] : null;
        $supplierName = $gr['supplier_name'];

        $err = pret_validateAgainstGr($conn, $grId, $items, 0);
        if ($err !== null) return ['success' => false, 'message_key' => $err];
    }

    if ($purchaseId > 0) {
        $stmt = $conn->prepare(
            "SELECT p.id, p.supplier_id, p.po_id, p.gr_id, s.name AS supplier_name
               FROM purchases p
               LEFT JOIN suppliers s ON s.id = p.supplier_id
              WHERE p.id = ? LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $purchaseId);
            $stmt->execute();
            $pr = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$pr) return ['success' => false, 'message_key' => 'not_found'];
            if ($supplierId === null) {
                $supplierId   = $pr['supplier_id'] !== null ? (int)$pr['supplier_id'] : null;
                $supplierName = $pr['supplier_name'];
            }
            if ($poId === null && !empty($pr['po_id'])) $poId = (int)$pr['po_id'];
        }
    }

    if ($grId <= 0 && $purchaseId <= 0) {
        return ['success' => false, 'message_key' => 'pr_source_required'];
    }

    // Lengkapi nomor PO bila baru diketahui dari invoice
    if ($poId && $poNumber === null) {
        $stmt = $conn->prepare("SELECT po_number FROM purchase_orders WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $poId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $poNumber = $row['po_number'] ?? null;
        }
    }

    $total = 0.0;
    foreach ($items as $it) $total += (float)$it['total_price'];

    $userId   = (int)$_SESSION['user_id'];
    $userName = pret_currentUserName($conn);

    $conn->begin_transaction();
    try {
        $attempts = 0;
        $ok       = false;
        $retId    = 0;
        $retNo    = '';
        do {
            $retNo = pret_generateNumber($conn);
            $stmt  = $conn->prepare(
                "INSERT INTO purchase_returns
                    (return_number, po_id, po_number, gr_id, gr_number, purchase_id,
                     supplier_id, supplier_name, return_date, status, reason, notes,
                     total_amount, created_by, created_by_name)
                 VALUES (?,?,?,?,?,?, ?,?,?,'pending',?,?, ?,?,?)"
            );
            if (!$stmt) throw new Exception('db_error');

            $grIdParam = $grId > 0 ? $grId : null;
            $piParam   = $purchaseId > 0 ? $purchaseId : null;
            // 14 placeholder → 14 karakter tipe:
            //  s return_number | i po_id | s po_number | i gr_id | s gr_number | i purchase_id
            //  i supplier_id | s supplier_name | s return_date | s reason | s notes
            //  d total_amount | i created_by | s created_by_name
            $stmt->bind_param(
                'sisisiissssdis',
                $retNo, $poId, $poNumber, $grIdParam, $grNumber, $piParam,
                $supplierId, $supplierName, $returnDate, $reason, $notes,
                $total, $userId, $userName
            );
            $ok    = $stmt->execute();
            $isDup = (!$ok && (int)$conn->errno === 1062);
            if ($ok) $retId = (int)$stmt->insert_id;
            $stmt->close();
            $attempts++;
        } while ($isDup && $attempts < 3);

        if (!$ok) throw new Exception('pr_number_conflict');

        pret_insertItems($conn, $retId, $items);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message_key' => 'db_error'];
    }

    pret_csrfRegenerate();
    if (function_exists('logActivity')) {
        logActivity('purchase_return_create', "Purchase Return created: {$retNo}"
            . ($grNumber ? " (GR {$grNumber})" : ''));
    }
    return ['success' => true, 'message_key' => 'save_success', 'return_id' => $retId, 'return_number' => $retNo];
}

function pret_insertItems(mysqli $conn, int $returnId, array $items): void {
    $stmt = $conn->prepare(
        "INSERT INTO purchase_return_items
            (return_id, gr_item_id, purchase_item_id, po_item_id, inventory_id,
             item_name, quantity, unit_id, unit_price, total_price, reason)
         VALUES (?,?,?,?,?, ?,?,?,?,?,?)"
    );
    if (!$stmt) throw new Exception('db_error');

    foreach ($items as $it) {
        // 11 placeholder → i i i i i | s d i d d s
        $stmt->bind_param(
            'iiiiisdidds',
            $returnId,
            $it['gr_item_id'],
            $it['purchase_item_id'],
            $it['po_item_id'],
            $it['inventory_id'],
            $it['item_name'],
            $it['quantity'],
            $it['unit_id'],
            $it['unit_price'],
            $it['total_price'],
            $it['reason']
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('db_error');
        }
    }
    $stmt->close();
}

/* ============================================================
   UPDATE (hanya selama masih 'pending')
   ============================================================ */

function pret_update(mysqli $conn, int $returnId, array $post): array {
    if (!isLoggedIn())            return ['success' => false, 'message_key' => 'no_permission'];
    if (!canUpdatePurchaseReturn())
                                  return ['success' => false, 'message_key' => 'no_permission'];
    if (!pret_csrfVerify((string)($post['csrf_token'] ?? '')))
                                  return ['success' => false, 'message_key' => 'invalid_csrf'];
    if (!pret_tablesExist($conn)) return ['success' => false, 'message_key' => 'pr_tables_missing'];

    $existing = pret_getOne($conn, $returnId);
    if (!$existing) return ['success' => false, 'message_key' => 'pr_not_found'];
    if (pret_isLocked((string)$existing['status'])) {
        return ['success' => false, 'message_key' => 'pr_locked'];
    }

    $returnDate = trim((string)($post['return_date'] ?? ''));
    $reason     = trim((string)($post['reason'] ?? ''));
    $notes      = trim((string)($post['notes'] ?? ''));

    if ($returnDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
        return ['success' => false, 'message_key' => 'pr_return_date_required'];
    }
    if ($reason === '') {
        return ['success' => false, 'message_key' => 'pr_reason_required'];
    }

    $norm = pret_normalizePostedItems($post);
    if ($norm['error'] !== null) {
        return ['success' => false, 'message_key' => $norm['error']];
    }
    $items = $norm['items'];

    $grId = (int)($existing['gr_id'] ?? 0);
    if ($grId > 0) {
        $err = pret_validateAgainstGr($conn, $grId, $items, $returnId);
        if ($err !== null) return ['success' => false, 'message_key' => $err];
    }

    $total = 0.0;
    foreach ($items as $it) $total += (float)$it['total_price'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "UPDATE purchase_returns
                SET return_date = ?, reason = ?, notes = ?, total_amount = ?
              WHERE id = ? AND status = 'pending'"
        );
        if (!$stmt) throw new Exception('db_error');
        $stmt->bind_param('sssdi', $returnDate, $reason, $notes, $total, $returnId);
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('db_error'); }
        $stmt->close();

        $del = $conn->prepare("DELETE FROM purchase_return_items WHERE return_id = ?");
        if (!$del) throw new Exception('db_error');
        $del->bind_param('i', $returnId);
        $del->execute();
        $del->close();

        pret_insertItems($conn, $returnId, $items);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message_key' => 'db_error'];
    }

    pret_csrfRegenerate();
    if (function_exists('logActivity')) {
        logActivity('purchase_return_update', "Purchase Return updated: {$existing['return_number']}");
    }
    return ['success' => true, 'message_key' => 'save_success'];
}

/* ============================================================
   STATUS TRANSITION  (approve / reject / process / cancel)
   ============================================================ */

/**
 * Transisi status Purchase Return dengan whitelist ketat.
 *
 *   pending   → approved | rejected | cancelled
 *   approved  → processed | cancelled
 *   processed → (final)
 *   rejected  → (final)
 *   cancelled → (final)
 *
 * Tidak ada perubahan inventory pada transisi mana pun.
 */
function pret_setStatus(mysqli $conn, int $returnId, string $action, string $csrf): array {
    if (!isLoggedIn())            return ['success' => false, 'message_key' => 'no_permission'];
    if (!pret_csrfVerify($csrf))  return ['success' => false, 'message_key' => 'invalid_csrf'];
    if (!pret_tablesExist($conn)) return ['success' => false, 'message_key' => 'pr_tables_missing'];

    $allowed = [
        'pr_approve' => 'approved',
        'pr_reject'  => 'rejected',
        'pr_process' => 'processed',
        'pr_cancel'  => 'cancelled',
    ];
    if (!isset($allowed[$action])) {
        return ['success' => false, 'message_key' => 'pr_invalid_action'];
    }
    $target = $allowed[$action];

    if ($target === 'cancelled') {
        if (!canCancelPurchaseReturn()) return ['success' => false, 'message_key' => 'no_permission'];
    } else {
        if (!canApprovePurchaseReturn()) return ['success' => false, 'message_key' => 'no_permission'];
    }

    $stmt = $conn->prepare("SELECT return_number, status FROM purchase_returns WHERE id = ? LIMIT 1");
    if (!$stmt) return ['success' => false, 'message_key' => 'db_error'];
    $stmt->bind_param('i', $returnId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return ['success' => false, 'message_key' => 'pr_not_found'];

    $current = (string)$row['status'];
    $transitions = [
        'pending'  => ['approved', 'rejected', 'cancelled'],
        'approved' => ['processed', 'cancelled'],
    ];
    if (!isset($transitions[$current]) || !in_array($target, $transitions[$current], true)) {
        return ['success' => false, 'message_key' => 'pr_invalid_transition'];
    }

    $userId   = (int)$_SESSION['user_id'];
    $userName = pret_currentUserName($conn);

    if ($target === 'approved') {
        $upd = $conn->prepare(
            "UPDATE purchase_returns
                SET status = 'approved', approved_by = ?, approved_by_name = ?, approved_at = NOW()
              WHERE id = ? AND status = 'pending'"
        );
        if (!$upd) return ['success' => false, 'message_key' => 'db_error'];
        $upd->bind_param('isi', $userId, $userName, $returnId);
    } elseif ($target === 'cancelled') {
        $upd = $conn->prepare(
            "UPDATE purchase_returns
                SET status = 'cancelled', cancelled_by = ?, cancelled_by_name = ?, cancelled_at = NOW()
              WHERE id = ? AND status IN ('pending','approved')"
        );
        if (!$upd) return ['success' => false, 'message_key' => 'db_error'];
        $upd->bind_param('isi', $userId, $userName, $returnId);
    } elseif ($target === 'rejected') {
        $upd = $conn->prepare(
            "UPDATE purchase_returns
                SET status = 'rejected', approved_by = ?, approved_by_name = ?, approved_at = NOW()
              WHERE id = ? AND status = 'pending'"
        );
        if (!$upd) return ['success' => false, 'message_key' => 'db_error'];
        $upd->bind_param('isi', $userId, $userName, $returnId);
    } else { // processed
        $upd = $conn->prepare(
            "UPDATE purchase_returns SET status = 'processed' WHERE id = ? AND status = 'approved'"
        );
        if (!$upd) return ['success' => false, 'message_key' => 'db_error'];
        $upd->bind_param('i', $returnId);
    }

    $okExec = $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();
    if (!$okExec || $affected < 1) {
        return ['success' => false, 'message_key' => 'pr_invalid_transition'];
    }

    pret_csrfRegenerate();
    if (function_exists('logActivity')) {
        logActivity('purchase_return_' . $target,
            "Purchase Return {$row['return_number']}: {$current} to {$target}");
    }
    return ['success' => true, 'message_key' => 'pr_status_changed'];
}

/* ============================================================
   DELETE (hanya untuk dokumen yang belum berjalan)
   ============================================================ */

/**
 * Hapus Purchase Return.
 * HANYA diizinkan selama status masih 'pending'. Dokumen yang sudah
 * approved / processed / rejected / cancelled TIDAK BOLEH dihapus supaya
 * histori tetap utuh — gunakan pembatalan.
 */
function pret_delete(mysqli $conn, int $returnId, string $csrf): array {
    if (!isLoggedIn())            return ['success' => false, 'message_key' => 'no_permission'];
    if (!canDeletePurchaseReturn())
                                  return ['success' => false, 'message_key' => 'no_permission'];
    if (!pret_csrfVerify($csrf))  return ['success' => false, 'message_key' => 'invalid_csrf'];
    if (!pret_tablesExist($conn)) return ['success' => false, 'message_key' => 'pr_tables_missing'];

    $stmt = $conn->prepare("SELECT return_number, status FROM purchase_returns WHERE id = ? LIMIT 1");
    if (!$stmt) return ['success' => false, 'message_key' => 'db_error'];
    $stmt->bind_param('i', $returnId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return ['success' => false, 'message_key' => 'pr_not_found'];
    if ((string)$row['status'] !== 'pending') {
        return ['success' => false, 'message_key' => 'pr_delete_forbidden'];
    }

    $conn->begin_transaction();
    try {
        $d1 = $conn->prepare("DELETE FROM purchase_return_items WHERE return_id = ?");
        if (!$d1) throw new Exception('db_error');
        $d1->bind_param('i', $returnId); $d1->execute(); $d1->close();

        $d2 = $conn->prepare("DELETE FROM purchase_returns WHERE id = ? AND status = 'pending'");
        if (!$d2) throw new Exception('db_error');
        $d2->bind_param('i', $returnId); $d2->execute(); $d2->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message_key' => 'db_error'];
    }

    pret_csrfRegenerate();
    if (function_exists('logActivity')) {
        logActivity('purchase_return_delete', "Purchase Return deleted: {$row['return_number']}");
    }
    return ['success' => true, 'message_key' => 'delete_success'];
}

/* ============================================================
   TRACEABILITY
   ============================================================ */

/** Purchase Return (flow baru) milik satu PO — untuk PO View. */
function pret_getByPo(mysqli $conn, int $poId): array {
    $rows = [];
    if (!pret_tablesExist($conn) || $poId <= 0) return $rows;
    $stmt = $conn->prepare(
        "SELECT id, return_number, gr_number, return_date, status, total_amount, created_by_name
           FROM purchase_returns
          WHERE po_id = ?
          ORDER BY return_date ASC, id ASC"
    );
    if (!$stmt) return $rows;
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

/** Purchase Return milik satu Goods Receipt — untuk GR View. */
function pret_getByGr(mysqli $conn, int $grId): array {
    $rows = [];
    if (!pret_tablesExist($conn) || $grId <= 0) return $rows;
    $stmt = $conn->prepare(
        "SELECT id, return_number, return_date, status, total_amount, created_by_name
           FROM purchase_returns
          WHERE gr_id = ?
          ORDER BY return_date ASC, id ASC"
    );
    if (!$stmt) return $rows;
    $stmt->bind_param('i', $grId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

/* ── REPORT DATA SCOPE : record-level guard ─────────────────────────────── */
function pret_scopeAllows(?array $pr): bool {
    if (!$pr) return false;
    if (!function_exists('canViewAllPurchaseReturn')) return true;
    return permScopeAllowsRecord(canViewAllPurchaseReturn(), $pr['created_by'] ?? 0);
}

} // end if (!function_exists('pret_csrfToken'))