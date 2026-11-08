<?php
/*
 * Added Items Functions Library
 * Version: 1.0.0
 * Functions library for in_items.php (Added Items module).
 * Loaded via require_once from inventory/in_items.php.
 *
 * Developer: AG +62 823 6334 9011
 */

if (!defined('BASE_PATH')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access not permitted.');
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Sanitize a single input value.
 *
 * PERUBAHAN PENTING — htmlspecialchars() dan real_escape_string() DIHAPUS.
 *
 * Seluruh nilai yang melewati fungsi ini berakhir di bind_param() pada
 * prepared statement atau dipakai sebagai kunci array session. Menambahkan
 * real_escape_string() di atas prepared statement adalah ESCAPING GANDA:
 * tanda petik tersimpan sebagai \' dan bukan '. Sementara htmlspecialchars()
 * membuat & tersimpan sebagai &amp; dan ' sebagai &#039; — persis penyebab
 * munculnya "&amp;" pada tampilan.
 *
 * Keamanan tidak berkurang: perlindungan SQL injection datang dari prepared
 * statement + bind_param, dan perlindungan XSS dilakukan saat MENCETAK
 * (escHtml di sisi JavaScript, htmlspecialchars di sisi PHP). Data disimpan
 * dalam bentuk aslinya; escaping dilakukan di titik keluaran.
 *
 * Akhir baris juga diseragamkan menjadi LF supaya catatan yang mengandung
 * Enter tidak menyisakan "\r" saat dirender.
 */
function ai_sanitize($data) {
    if (is_array($data)) {
        return array_map('ai_sanitize', $data);
    }
    $data = (string) $data;
    // Buang byte NULL dan karakter kontrol yang tidak pernah sah dalam masukan
    // teks, TETAPI pertahankan tab (\t), CR (\r), dan LF (\n).
    $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
    $data = str_replace(["\r\n", "\r"], "\n", $data);
    return trim($data);
}

/**
 * Ambil string bahasa dengan aman.
 * Halaman ini mendukung multi bahasa TANPA fallback language: berkas bahasa
 * yang aktif dipakai apa adanya. Helper ini hanya melindungi dari PHP warning
 * bila sebuah kunci belum ada.
 */
function ai_lang(string $key): string {
    global $lang, $LANG;
    if (isset($lang[$key])) return (string) $lang[$key];
    if (isset($LANG[$key])) return (string) $LANG[$key];
    return $key;
}

/**
 * Presisi kuantitas.
 * Kolom `inventory`.`quantity` bertipe DECIMAL(10,2), jadi satuan terkecil
 * yang bisa disimpan adalah 0.01. Seluruh perhitungan dibulatkan ke presisi
 * itu supaya galat floating point tidak pernah menggeser saldo stok.
 */
if (!defined('AI_QTY_DECIMALS')) define('AI_QTY_DECIMALS', 2);
if (!defined('AI_QTY_STEP'))     define('AI_QTY_STEP', 0.01);

function ai_qty($value): float {
    return round((float) $value, AI_QTY_DECIMALS);
}

/** Format kuantitas untuk pesan: buang nol di belakang koma (0.50 → 0.5). */
function ai_qty_str($value): string {
    $s = number_format(ai_qty($value), AI_QTY_DECIMALS, '.', '');
    if (strpos($s, '.') !== false) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    return $s === '' ? '0' : $s;
}

/**
 * Output a JSON response and terminate.
 */
function ai_json($success, $data = null, $message = '') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'data'    => $data,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Generate reference number: IN + 3-char name + SC + YmdHis
 */
function ai_generateReference($full_name) {
    $name_code = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $full_name), 0, 3));
    if (strlen($name_code) < 3) {
        $name_code = str_pad($name_code, 3, 'X');
    }
    return 'IN' . $name_code . '-SC' . date('YmdHis');
}

// ============================================================================
// AJAX DISPATCHER
// ============================================================================

/**
 * Central dispatcher for all AJAX actions.
 * Called from in_items.php when $_POST['ajax'] is set.
 */
function ai_dispatch_ajax() {
    global $conn, $lang;

    if (!isLoggedIn()) {
        ai_json(false, null, ai_lang('no_permission'));
    }

    // Gerbang halaman: seluruh endpoint hanya boleh dipanggil oleh akun yang
    // memang berhak membuka modul Added Items. Dibaca lewat helper terpusat di
    // includes/global_permission.php — LANGSUNG dari tabel `permissions` dan
    // sekaligus menegakkan gerbang induk modul 'inventory'. Modul izinnya
    // TETAP 'in_items' yang sudah ada, jadi tidak ada permission ganda.
    if (!canAccessInItems()) {
        ai_json(false, null, ai_lang('no_permission'));
    }

    // Permission check for write actions
    $write_actions = ['add_to_cart', 'update_cart', 'remove_from_cart', 'clear_cart', 'submit_in'];
    $action = isset($_POST['ajax']) ? trim($_POST['ajax']) : '';

    if (in_array($action, $write_actions, true) && !canCreateInItems()) {
        ai_json(false, null, ai_lang('no_permission'));
    }
    if (!in_array($action, $write_actions, true) && !canReadInItems()) {
        ai_json(false, null, ai_lang('no_permission'));
    }

    try {
        switch ($action) {

            // ── GET ITEM BY CODE ──────────────────────────────────────────
            case 'get_item':
                ai_action_get_item();
                break;

            // ── ADD TO CART ───────────────────────────────────────────────
            case 'add_to_cart':
                ai_action_add_to_cart();
                break;

            // ── UPDATE CART QUANTITY ──────────────────────────────────────
            case 'update_cart':
                ai_action_update_cart();
                break;

            // ── REMOVE FROM CART ──────────────────────────────────────────
            case 'remove_from_cart':
                ai_action_remove_from_cart();
                break;

            // ── CLEAR CART ────────────────────────────────────────────────
            case 'clear_cart':
                ai_action_clear_cart();
                break;

            // ── GET CART ──────────────────────────────────────────────────
            case 'get_cart':
                ai_action_get_cart();
                break;

            // ── SUBMIT INVENTORY IN ───────────────────────────────────────
            case 'submit_in':
                ai_action_submit_in();
                break;

            // ── GET HISTORY (DataTables server-side) ──────────────────────
            case 'get_history':
                ai_action_get_history();
                break;

            default:
                ai_json(false, null, 'Unknown action');
        }
    } catch (Exception $e) {
        error_log('ai_dispatch_ajax error: ' . $e->getMessage());
        ai_json(false, null, 'Server error');
    }
}

// ============================================================================
// ACTION HANDLERS
// ============================================================================

function ai_action_get_item() {
    global $conn, $lang;

    $code = ai_sanitize($_POST['code'] ?? '');
    if ($code === '') {
        ai_json(false, null, ai_lang('enter_code'));
    }

    // is_active ikut dibaca: barang nonaktif tidak boleh ditambah stoknya.
    $stmt = $conn->prepare("
        SELECT i.id, i.product_code, i.item_name, i.quantity, i.unit_id, i.item_image,
               i.is_active, u.symbol AS unit_symbol
        FROM inventory i
        LEFT JOIN units u ON i.unit_id = u.id
        WHERE i.product_code = ?
        LIMIT 1
    ");
    if (!$stmt) {
        ai_json(false, null, ai_lang('error'));
    }

    $stmt->bind_param('s', $code);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        ai_json(false, null, ai_lang('item_not_found'));
    }

    if ((int)$row['is_active'] !== 1) {
        ai_json(false, null, ai_lang('item_inactive_cannot_add'));
    }

    ai_json(true, [
        'id'           => (int)$row['id'],
        'product_code' => $row['product_code'],
        'item_name'    => $row['item_name'],
        'quantity'     => ai_qty($row['quantity']),
        'unit_id'      => $row['unit_id'],
        'item_image'   => $row['item_image'],
        'unit_symbol'  => $row['unit_symbol'] ?? '',
        'qty_step'     => AI_QTY_STEP,
    ]);
}

function ai_action_add_to_cart() {
    global $conn, $lang;

    $code = ai_sanitize($_POST['code'] ?? '');
    $qty  = ai_qty($_POST['quantity'] ?? 0);

    if ($code === '') {
        ai_json(false, null, ai_lang('enter_code'));
    }
    if ($qty <= 0) {
        ai_json(false, null, ai_lang('quantity_greater_than_zero'));
    }

    // is_active ikut dibaca: barang nonaktif tidak boleh masuk keranjang.
    $stmt = $conn->prepare("
        SELECT i.id, i.product_code, i.item_name, i.quantity, i.unit_id, i.item_image,
               i.is_active, u.symbol AS unit_symbol
        FROM inventory i
        LEFT JOIN units u ON i.unit_id = u.id
        WHERE i.product_code = ?
        LIMIT 1
    ");
    if (!$stmt) {
        ai_json(false, null, ai_lang('error'));
    }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        ai_json(false, null, ai_lang('item_not_found'));
    }

    if ((int)$row['is_active'] !== 1) {
        ai_json(false, null, ai_lang('item_inactive_cannot_add'));
    }

    if (isset($_SESSION['in_cart'][$code])) {
        $_SESSION['in_cart'][$code]['quantity'] = ai_qty($_SESSION['in_cart'][$code]['quantity'] + $qty);
        $_SESSION['in_cart'][$code]['stock']    = ai_qty($row['quantity']);
    } else {
        $_SESSION['in_cart'][$code] = [
            'id'           => (int)$row['id'],
            'product_code' => $row['product_code'],
            'item_name'    => $row['item_name'],
            'quantity'     => $qty,
            'unit_id'      => $row['unit_id'],
            'unit_symbol'  => $row['unit_symbol'] ?? '',
            'item_image'   => $row['item_image'],
            'stock'        => ai_qty($row['quantity']),
        ];
    }

    ai_json(true, $_SESSION['in_cart']);
}

function ai_action_update_cart() {
    global $conn;

    $code = ai_sanitize($_POST['code'] ?? '');
    $qty  = ai_qty($_POST['quantity'] ?? 0);

    if (!isset($_SESSION['in_cart'][$code])) {
        ai_json(false, null, ai_lang('item_not_found'));
    }

    // Status aktif dibaca ULANG dari database, bukan dari session yang bisa
    // saja sudah usang (barang dinonaktifkan sejak masuk keranjang).
    $inventory_id = (int) $_SESSION['in_cart'][$code]['id'];
    if ($stmt = $conn->prepare("SELECT quantity, is_active FROM inventory WHERE id = ? LIMIT 1")) {
        $stmt->bind_param('i', $inventory_id);
        $stmt->execute();
        $stmt->bind_result($stock_quantity, $is_active);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found) {
            unset($_SESSION['in_cart'][$code]);
            ai_json(false, $_SESSION['in_cart'], ai_lang('item_not_found'));
        }
        if ((int) $is_active !== 1) {
            // Barang yang sudah dinonaktifkan dikeluarkan dari keranjang supaya
            // tidak bisa ikut tersimpan saat transaksi dikirim.
            unset($_SESSION['in_cart'][$code]);
            ai_json(false, $_SESSION['in_cart'], ai_lang('item_inactive_cannot_add'));
        }
        $_SESSION['in_cart'][$code]['stock'] = ai_qty($stock_quantity);
    }

    if ($qty <= 0) {
        unset($_SESSION['in_cart'][$code]);
    } else {
        $_SESSION['in_cart'][$code]['quantity'] = $qty;
    }

    ai_json(true, $_SESSION['in_cart']);
}

function ai_action_remove_from_cart() {
    $code = ai_sanitize($_POST['code'] ?? '');
    if (isset($_SESSION['in_cart'][$code])) {
        unset($_SESSION['in_cart'][$code]);
    }
    ai_json(true, $_SESSION['in_cart']);
}

function ai_action_clear_cart() {
    $_SESSION['in_cart'] = [];
    ai_json(true, []);
}

function ai_action_get_cart() {
    ai_json(true, $_SESSION['in_cart'] ?? []);
}

function ai_action_submit_in() {
    global $conn, $lang;

    if (empty($_SESSION['in_cart'])) {
        ai_json(false, null, ai_lang('cart_empty'));
    }

    $reference        = ai_sanitize($_POST['reference'] ?? '');
    $notes            = ai_sanitize($_POST['notes'] ?? '');
    $transaction_date = date('Y-m-d');
    $created_by       = (int)$_SESSION['user_id'];

    if ($reference === '') {
        ai_json(false, null, ai_lang('reference_required'));
    }

    $conn->begin_transaction();

    try {
        $count     = 0;
        $log_lines = [];

        // Statement untuk mengunci baris dan memeriksa status aktif secara live.
        // Pemeriksaan dilakukan DI DALAM transaksi dengan FOR UPDATE supaya
        // barang yang dinonaktifkan TEPAT sebelum tombol simpan ditekan tetap
        // tidak bisa lolos, dan supaya saldo stok tidak balapan dengan
        // transaksi lain yang berjalan bersamaan.
        $stmt_chk = $conn->prepare(
            "SELECT quantity, is_active, item_name FROM inventory WHERE id = ? FOR UPDATE"
        );
        if (!$stmt_chk) {
            throw new Exception(ai_lang('error'));
        }

        // Prepare statements once outside loop
        /* created_by_name = SALINAN PERMANEN users.full_name saat baris
           ditulis, tanpa foreign key, sehingga tidak ikut hilang saat akun
           users dihapus. */
        $stmt_in  = $conn->prepare(
            "INSERT INTO inventory_in
             (inventory_id, quantity, unit_id, reference_number, notes, transaction_date, created_by, created_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, (SELECT full_name FROM users WHERE id = ?))"
        );
        $stmt_upd = $conn->prepare(
            "UPDATE inventory SET quantity = quantity + ? WHERE id = ?"
        );

        if (!$stmt_in || !$stmt_upd) {
            throw new Exception(ai_lang('error'));
        }

        foreach ($_SESSION['in_cart'] as $code => $item) {
            $inventory_id = (int)$item['id'];
            $quantity     = ai_qty($item['quantity']);
            $unit_id      = !empty($item['unit_id']) ? (int)$item['unit_id'] : null;

            if ($quantity <= 0) {
                throw new Exception(ai_lang('quantity_greater_than_zero'));
            }

            // Kunci baris dan periksa status aktif + saldo stok saat ini.
            $stmt_chk->bind_param('i', $inventory_id);
            if (!$stmt_chk->execute()) {
                throw new Exception(ai_lang('error'));
            }
            $stmt_chk->bind_result($stock_before, $is_active, $db_item_name);
            $found = $stmt_chk->fetch();
            $stmt_chk->free_result();

            if (!$found) {
                throw new Exception(ai_lang('item_not_found') . ': ' . $code);
            }
            if ((int) $is_active !== 1) {
                // Barang nonaktif TIDAK boleh ditambah stoknya. Seluruh
                // transaksi dibatalkan (rollback) supaya keranjang tidak
                // tersimpan sebagian.
                throw new Exception(ai_lang('item_inactive_cannot_add') . ': ' . $db_item_name);
            }

            $stock_before = ai_qty($stock_before);
            $stock_after  = ai_qty($stock_before + $quantity);

            $stmt_in->bind_param('idisssii',
                $inventory_id,
                $quantity,
                $unit_id,
                $reference,
                $notes,
                $transaction_date,
                $created_by,
                $created_by      // -> created_by_name (snapshot permanen)
            );

            if (!$stmt_in->execute()) {
                throw new Exception(ai_lang('failed_to_save_transaction'));
            }

            $stmt_upd->bind_param('di', $quantity, $inventory_id);
            if (!$stmt_upd->execute()) {
                throw new Exception(ai_lang('failed_to_save_transaction'));
            }

            $log_lines[] = $code . ' (' . $db_item_name . ') +'
                         . ai_qty_str($quantity)
                         . ($item['unit_symbol'] ? ' ' . $item['unit_symbol'] : '')
                         . ' [' . ai_qty_str($stock_before) . ' -> ' . ai_qty_str($stock_after) . ']';

            $count++;
        }

        $stmt_chk->close();
        $stmt_in->close();
        $stmt_upd->close();
        $conn->commit();

        // Log aktivitas LENGKAP: setiap baris barang dicatat beserta kuantitas
        // yang ditambahkan dan saldo stok sebelum → sesudah, bukan hanya
        // jumlah barisnya. Dengan begitu setiap perubahan stok dapat
        // ditelusuri sepenuhnya dari tabel activity_logs.
        logActivity(
            'INVENTORY_IN',
            'Ref: ' . $reference
            . ' | Items: ' . $count
            . ' | ' . implode('; ', $log_lines)
            . ($notes !== '' ? ' | Notes: ' . $notes : '')
        );

        $_SESSION['in_cart'] = [];

        ai_json(true, ['count' => $count, 'reference' => $reference], ai_lang('save_success'));

    } catch (Exception $e) {
        $conn->rollback();
        error_log('ai_action_submit_in error: ' . $e->getMessage());
        ai_json(false, null, $e->getMessage());
    }
}

function ai_action_get_history() {
    global $conn;

    $draw   = (int)($_POST['draw']   ?? 1);
    $start  = max(0, (int)($_POST['start']  ?? 0));
    $length = (int)($_POST['length'] ?? 25);

    // Batas halaman dijaga agar permintaan tidak bisa memaksa server merender
    // seluruh tabel sekaligus. -1 dari DataTables berarti "semua"; di sini
    // diterjemahkan menjadi batas atas yang wajar, bukan tanpa batas.
    if ($length <= 0 || $length > 1000) $length = 1000;

    // Only show records for the logged-in user
    $uid = (int)$_SESSION['user_id'];

    // Search value
    $search = ai_sanitize($_POST['search']['value'] ?? '');

    // Count total for current user.
    // SELURUH nilai dikirim lewat bind_param — termasuk $uid, yang sebelumnya
    // disisipkan langsung ke string SQL pada cabang pencarian.
    if ($search !== '') {
        $like = '%' . $search . '%';
        $count_sql = "
            SELECT COUNT(*) AS total
            FROM inventory_in ii
            LEFT JOIN inventory i ON ii.inventory_id = i.id
            WHERE ii.created_by = ?
              AND (i.product_code LIKE ? OR i.item_name LIKE ? OR ii.reference_number LIKE ?)
        ";
        $cs = $conn->prepare($count_sql);
        $cs->bind_param('isss', $uid, $like, $like, $like);
    } else {
        $cs = $conn->prepare("SELECT COUNT(*) AS total FROM inventory_in WHERE created_by = ?");
        $cs->bind_param('i', $uid);
    }

    $cs->execute();
    $cr    = $cs->get_result();
    $total = (int)($cr->fetch_assoc()['total'] ?? 0);
    $cs->close();

    // Fetch data
    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $conn->prepare("
            SELECT DATE_FORMAT(ii.created_at, '%Y-%m-%d %H:%i') AS date_time,
                   i.product_code, i.item_name,
                   ii.quantity, u.symbol AS unit_symbol,
                   ii.reference_number
            FROM inventory_in ii
            LEFT JOIN inventory i ON ii.inventory_id = i.id
            LEFT JOIN units u ON ii.unit_id = u.id
            WHERE ii.created_by = ?
              AND (i.product_code LIKE ? OR i.item_name LIKE ? OR ii.reference_number LIKE ?)
            ORDER BY ii.created_at DESC
            LIMIT ?, ?
        ");
        $stmt->bind_param('isssii', $uid, $like, $like, $like, $start, $length);
    } else {
        $stmt = $conn->prepare("
            SELECT DATE_FORMAT(ii.created_at, '%Y-%m-%d %H:%i') AS date_time,
                   i.product_code, i.item_name,
                   ii.quantity, u.symbol AS unit_symbol,
                   ii.reference_number
            FROM inventory_in ii
            LEFT JOIN inventory i ON ii.inventory_id = i.id
            LEFT JOIN units u ON ii.unit_id = u.id
            WHERE ii.created_by = ?
            ORDER BY ii.created_at DESC
            LIMIT ?, ?
        ");
        $stmt->bind_param('iii', $uid, $start, $length);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $qty = floatval($row['quantity']);
        $qty_fmt = ($qty == floor($qty))
            ? number_format($qty, 0)
            : rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
        $qty_display = $qty_fmt . ($row['unit_symbol'] ? ' ' . $row['unit_symbol'] : '');

        $data[] = [
            'date_time'        => $row['date_time']        ?? '-',
            'product_code'     => $row['product_code']     ?? '-',
            'item_name'        => $row['item_name']        ?? '-',
            'quantity_display' => $qty_display,
            'reference_number' => $row['reference_number'] ?? '-',
        ];
    }

    $stmt->close();

    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $total,
        'recordsFiltered' => $total,
        'data'            => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}