<?php
/*
 * Take Items — Functions Library
 * File   : includes/take_items_functions.php
 * Loaded by inventory/take_items.php
 *
 * Security: direct access blocked below.
 * All functions are AJAX handlers + helpers for the Take Items module.
 */

// ============================================================================
// BLOCK DIRECT ACCESS
// ============================================================================
if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ============================================================================
// HELPER: Sanitize input (take_items scope)
//
// PERUBAHAN PENTING — kenapa htmlspecialchars() dan real_escape_string()
// DIHAPUS dari sini:
//
//   Seluruh nilai yang melewati fungsi ini berakhir di bind_param() pada
//   prepared statement, atau dipakai sebagai kunci array session, atau
//   di-escape ulang secara eksplisit untuk klausa LIKE. Menambahkan
//   real_escape_string() di atas prepared statement adalah ESCAPING GANDA:
//   tanda petik tersimpan di database sebagai \' dan bukan '. Sementara
//   htmlspecialchars() membuat & tersimpan sebagai &amp; dan ' sebagai
//   &#039; — persis penyebab munculnya "&amp;" pada tampilan.
//
//   Keamanan tidak berkurang sedikit pun: perlindungan SQL injection
//   datang dari prepared statement + bind_param (nilai tidak pernah
//   disisipkan ke string SQL), dan perlindungan XSS dilakukan saat
//   MENCETAK — escHtml() di sisi JavaScript dan htmlspecialchars() di sisi
//   PHP. Menyimpan data dalam bentuk aslinya adalah yang benar; escaping
//   dilakukan di titik keluaran, bukan di titik masuk.
//
//   Normalisasi baris juga dilakukan di sini: CRLF (\r\n) dan CR (\r)
//   diseragamkan menjadi LF (\n) supaya catatan yang mengandung Enter
//   tidak lagi menampilkan sisa "\r" saat dirender.
// ============================================================================
if (!function_exists('ti_sanitize')) {
    function ti_sanitize($data) {
        if (is_array($data)) {
            return array_map('ti_sanitize', $data);
        }
        $data = (string) $data;
        // Buang byte NULL dan karakter kontrol yang tidak pernah sah dalam
        // masukan teks, TETAPI pertahankan tab (\t), CR (\r), dan LF (\n).
        $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
        // Seragamkan akhir baris menjadi LF.
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        return trim($data);
    }
}

// ============================================================================
// HELPER: Ambil string bahasa dengan aman
// Halaman ini mendukung multi bahasa TANPA fallback language: berkas bahasa
// yang aktif dipakai apa adanya. Helper ini hanya melindungi dari PHP warning
// bila sebuah kunci belum ada, dan mengembalikan kuncinya sendiri supaya
// kunci yang hilang langsung kelihatan saat pengujian.
// ============================================================================
if (!function_exists('ti_lang')) {
    function ti_lang(string $key): string {
        global $lang, $LANG;
        if (isset($lang[$key]))  return (string) $lang[$key];
        if (isset($LANG[$key]))  return (string) $LANG[$key];
        return $key;
    }
}

// ============================================================================
// HELPER: Presisi kuantitas
//
// Kolom `inventory`.`quantity` bertipe DECIMAL(10,2), jadi satuan terkecil
// yang bisa disimpan adalah 0.01. Seluruh perbandingan stok memakai nilai
// yang sudah dibulatkan ke 2 desimal supaya galat pembulatan floating point
// (mis. 0.1 + 0.2 = 0.30000000000000004) tidak pernah membuat pengambilan
// stok pecahan ditolak dengan alasan "stok tidak cukup" secara keliru.
// ============================================================================
if (!defined('TI_QTY_DECIMALS')) define('TI_QTY_DECIMALS', 2);
if (!defined('TI_QTY_STEP'))     define('TI_QTY_STEP', 0.01);

if (!function_exists('ti_qty')) {
    function ti_qty($value): float {
        return round((float) $value, TI_QTY_DECIMALS);
    }
}

/** Format kuantitas untuk pesan: buang nol di belakang koma (0.50 → 0.5). */
if (!function_exists('ti_qty_str')) {
    function ti_qty_str($value): string {
        $s = number_format(ti_qty($value), TI_QTY_DECIMALS, '.', '');
        if (strpos($s, '.') !== false) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return $s === '' ? '0' : $s;
    }
}

// ============================================================================
// HELPER: JSON response — clears buffer, sets header, outputs JSON, exits
// ============================================================================
if (!function_exists('ti_json')) {
    function ti_json($success, $data = null, $message = '') {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $success,
            'data'    => $data,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ============================================================================
// HELPER: Log take-items activity (uses core logActivity if available)
// ============================================================================
if (!function_exists('ti_log')) {
    function ti_log($action, $details = '') {
        global $conn;
        if (!isLoggedIn()) return;

        $setting = getSetting('activity_log_enabled');
        if ($setting != '1') return;

        try {
            $user_id   = (int) $_SESSION['user_id'];
            $ip        = $_SERVER['REMOTE_ADDR']     ?? '0.0.0.0';
            $ua        = isset($_SERVER['HTTP_USER_AGENT'])
                         ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255)
                         : '';

            $check = $conn->query("SHOW TABLES LIKE 'activity_logs'");
            if ($check && $check->num_rows > 0) {
                $stmt = $conn->prepare(
                    "INSERT INTO activity_logs
                         (user_id, action, description, ip_address, user_agent)
                     VALUES (?, ?, ?, ?, ?)"
                );
                if ($stmt) {
                    $stmt->bind_param('issss', $user_id, $action, $details, $ip, $ua);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        } catch (Exception $e) {
            error_log('ti_log error: ' . $e->getMessage());
        }
    }
}

// ============================================================================
// AJAX: Get item by product_code (SKU)
// POST ajax=get_item  code=<SKU>
// ============================================================================
function ti_ajax_get_item() {
    global $conn;

    $code = ti_sanitize($_POST['code'] ?? '');
    if ($code === '') {
        ti_json(false, null, ti_lang('enter_code'));
    }

    // PERUBAHAN — syarat "AND i.quantity > 0" DIHAPUS dari klausa WHERE.
    //
    // Dulu barang bersisa 0.5 kg tetap lolos filter itu, TETAPI barang yang
    // benar-benar habis dan barang yang tidak ada sama-sama menghasilkan satu
    // pesan "not found or out of stock" sehingga penyebabnya tidak terbaca.
    // Sekarang barangnya diambil dulu, lalu status dinilai satu per satu:
    // tidak ada / tidak aktif / stok habis — masing-masing pesannya sendiri.
    //
    // Kolom is_active ikut dibaca supaya barang nonaktif dapat ditolak.
    $stmt = $conn->prepare("
        SELECT i.id, i.product_code, i.item_name, i.quantity,
               i.unit_id, i.item_image, i.is_active,
               u.symbol AS unit_symbol
        FROM inventory i
        LEFT JOIN units u ON i.unit_id = u.id
        WHERE i.product_code = ?
        LIMIT 1
    ");
    if (!$stmt) {
        ti_json(false, null, ti_lang('error'));
    }

    $stmt->bind_param('s', $code);
    $stmt->execute();
    $stmt->bind_result($id, $product_code, $item_name, $quantity, $unit_id, $item_image, $is_active, $unit_symbol);

    if (!$stmt->fetch()) {
        $stmt->close();
        ti_json(false, null, ti_lang('item_not_found'));
    }
    $stmt->close();

    // Barang nonaktif (inventory.is_active = 0) tidak boleh diambil.
    if ((int) $is_active !== 1) {
        ti_json(false, null, ti_lang('item_inactive_cannot_take'));
    }

    $available = ti_qty($quantity);
    if ($available <= 0) {
        ti_json(false, null, ti_lang('out_of_stock'));
    }

    ti_json(true, [
        'id'           => (int) $id,
        'product_code' => $product_code,
        'item_name'    => $item_name,
        'quantity'     => $available,
        'unit_id'      => $unit_id,
        'item_image'   => $item_image,
        'unit_symbol'  => $unit_symbol,
        // Dipakai sisi klien untuk menentukan kuantitas awal yang masuk akal:
        // min(1, stok tersedia). Tanpa ini klien selalu mengirim 1 sehingga
        // barang bersisa di bawah 1 selalu ditolak "stok tidak cukup".
        'qty_step'     => TI_QTY_STEP,
        'suggest_qty'  => ($available < 1 ? $available : 1.0),
    ]);
}

// ============================================================================
// AJAX: Add item to session cart
// POST ajax=add_to_cart  code=<SKU>  quantity=<float>
// ============================================================================
function ti_ajax_add_to_cart() {
    global $conn;

    $code     = ti_sanitize($_POST['code'] ?? '');
    $quantity = ti_qty($_POST['quantity'] ?? 0);

    if ($code === '') {
        ti_json(false, null, ti_lang('enter_code'));
    }
    if ($quantity <= 0) {
        ti_json(false, null, ti_lang('quantity_greater_than_zero'));
    }

    // is_active ikut dibaca: barang nonaktif tidak boleh masuk keranjang.
    $stmt = $conn->prepare("
        SELECT i.id, i.product_code, i.item_name,
               i.unit_id, i.quantity, i.item_image, i.is_active,
               u.symbol
        FROM inventory i
        LEFT JOIN units u ON i.unit_id = u.id
        WHERE i.product_code = ?
        LIMIT 1
    ");
    if (!$stmt) {
        ti_json(false, null, ti_lang('error'));
    }

    $stmt->bind_param('s', $code);
    $stmt->execute();
    $stmt->bind_result($id, $product_code, $item_name, $unit_id, $stock_quantity, $item_image, $is_active, $unit_symbol);

    if (!$stmt->fetch()) {
        $stmt->close();
        ti_json(false, null, ti_lang('item_not_found'));
    }
    $stmt->close();

    if ((int) $is_active !== 1) {
        ti_json(false, null, ti_lang('item_inactive_cannot_take'));
    }

    $available_stock = ti_qty($stock_quantity);
    if ($available_stock <= 0) {
        ti_json(false, null, ti_lang('out_of_stock'));
    }

    // Kuantitas yang sudah ada di keranjang untuk kode yang sama.
    $cart_qty  = isset($_SESSION['take_cart'][$code])
                 ? ti_qty($_SESSION['take_cart'][$code]['quantity'])
                 : 0.0;
    $total_qty = ti_qty($cart_qty + $quantity);

    // PERBAIKAN INTI — pengambilan barang berjumlah kecil.
    //
    // Sebelumnya permintaan yang melebihi stok langsung DITOLAK. Karena
    // sisi klien selalu mengirim quantity = 1, barang bersisa 0.5 kg selalu
    // gagal dan memunculkan pesan "stok tidak cukup" walaupun stoknya ADA.
    //
    // Sekarang: bila permintaan melebihi stok TETAPI masih ada stok tersisa,
    // kuantitas DIBATASI ke sisa stok (clamp), bukan ditolak. Penolakan hanya
    // terjadi kalau memang tidak ada sisa sama sekali — dan penolakan itu
    // ditangani di pemeriksaan $available_stock <= 0 di atas.
    //
    // Perbandingan memakai nilai yang sudah dibulatkan 2 desimal, sama dengan
    // presisi kolom DECIMAL(10,2) di database.
    $clamped = false;
    if ($total_qty > $available_stock) {
        $total_qty = $available_stock;
        $clamped   = true;
    }

    if (isset($_SESSION['take_cart'][$code])) {
        $_SESSION['take_cart'][$code]['quantity'] = $total_qty;
        $_SESSION['take_cart'][$code]['stock']    = $available_stock;
    } else {
        $_SESSION['take_cart'][$code] = [
            'id'           => (int) $id,
            'product_code' => $product_code,
            'item_name'    => $item_name,
            'unit_id'      => $unit_id,
            'unit_symbol'  => $unit_symbol ? $unit_symbol : '',
            'quantity'     => $total_qty,
            'stock'        => $available_stock,
            'item_image'   => $item_image,
        ];
    }

    // Berhasil, tetapi pengguna tetap diberi tahu bila kuantitasnya dibatasi
    // ke sisa stok — supaya tidak ada perubahan diam-diam.
    $message = $clamped
        ? ti_lang('qty_limited_to_stock') . ': ' . ti_qty_str($available_stock)
          . ($unit_symbol ? ' ' . $unit_symbol : '')
        : '';

    ti_json(true, $_SESSION['take_cart'], $message);
}

// ============================================================================
// AJAX: Update quantity for a cart item
// POST ajax=update_cart_quantity  code=<SKU>  quantity=<float>
// ============================================================================
function ti_ajax_update_cart_quantity() {
    global $conn;

    $code     = ti_sanitize($_POST['code'] ?? '');
    $quantity = ti_qty($_POST['quantity'] ?? 0);

    if ($code === '') {
        ti_json(false, null, ti_lang('enter_code'));
    }
    if ($quantity <= 0) {
        ti_json(false, null, ti_lang('quantity_greater_than_zero'));
    }
    if (!isset($_SESSION['take_cart'][$code])) {
        ti_json(false, null, ti_lang('item_not_found'));
    }

    // Stok dan status aktif dibaca ULANG dari database, bukan dari nilai yang
    // tersimpan di session. Session bisa saja sudah usang (barang dinonaktifkan
    // atau stoknya berubah oleh pengguna lain sejak dimasukkan ke keranjang).
    $inventory_id = (int) $_SESSION['take_cart'][$code]['id'];
    $stmt = $conn->prepare("SELECT quantity, is_active FROM inventory WHERE id = ? LIMIT 1");
    if (!$stmt) {
        ti_json(false, null, ti_lang('error'));
    }
    $stmt->bind_param('i', $inventory_id);
    $stmt->execute();
    $stmt->bind_result($stock_quantity, $is_active);
    if (!$stmt->fetch()) {
        $stmt->close();
        ti_json(false, null, ti_lang('item_not_found'));
    }
    $stmt->close();

    if ((int) $is_active !== 1) {
        // Barang yang sudah dinonaktifkan dikeluarkan dari keranjang, supaya
        // tidak bisa ikut terkirim saat transaksi disimpan.
        unset($_SESSION['take_cart'][$code]);
        ti_json(false, $_SESSION['take_cart'], ti_lang('item_inactive_cannot_take'));
    }

    $available_stock = ti_qty($stock_quantity);
    $_SESSION['take_cart'][$code]['stock'] = $available_stock;

    if ($available_stock <= 0) {
        unset($_SESSION['take_cart'][$code]);
        ti_json(false, $_SESSION['take_cart'], ti_lang('out_of_stock'));
    }

    // Sama seperti add_to_cart: kuantitas DIBATASI ke sisa stok, tidak ditolak,
    // sehingga sisa pecahan (mis. 0.25 kg) selalu bisa diambil sampai habis.
    $clamped = false;
    if ($quantity > $available_stock) {
        $quantity = $available_stock;
        $clamped  = true;
    }

    $_SESSION['take_cart'][$code]['quantity'] = $quantity;

    $unit_symbol = (string) ($_SESSION['take_cart'][$code]['unit_symbol'] ?? '');
    $message = $clamped
        ? ti_lang('qty_limited_to_stock') . ': ' . ti_qty_str($available_stock)
          . ($unit_symbol !== '' ? ' ' . $unit_symbol : '')
        : '';

    ti_json(true, $_SESSION['take_cart'], $message);
}

// ============================================================================
// AJAX: Remove one item from cart
// POST ajax=remove_from_cart  code=<SKU>
// ============================================================================
function ti_ajax_remove_from_cart() {
    $code = ti_sanitize($_POST['code'] ?? '');

    if ($code === '') {
        ti_json(false, null, ti_lang('enter_code'));
    }

    if (isset($_SESSION['take_cart'][$code])) {
        unset($_SESSION['take_cart'][$code]);
        ti_json(true, $_SESSION['take_cart']);
    } else {
        ti_json(false, null, ti_lang('item_not_found'));
    }
}

// ============================================================================
// AJAX: Clear entire cart
// POST ajax=clear_cart
// ============================================================================
function ti_ajax_clear_cart() {
    $_SESSION['take_cart'] = [];
    ti_json(true, []);
}

// ============================================================================
// AJAX: Get current cart contents
// POST ajax=get_cart
// ============================================================================
function ti_ajax_get_cart() {
    ti_json(true, $_SESSION['take_cart']);
}

// ============================================================================
// AJAX: Submit transaction — deduct stock + insert inventory_out rows
// POST ajax=submit_taking  reference=<string>  notes=<string>
// ============================================================================
function ti_ajax_submit_taking() {
    global $conn;

    if (empty($_SESSION['take_cart'])) {
        ti_json(false, null, ti_lang('cart_empty'));
    }

    $reference = ti_sanitize($_POST['reference'] ?? '');
    $notes     = ti_sanitize($_POST['notes']     ?? '');

    if ($reference === '') {
        ti_json(false, null, ti_lang('reference_required'));
    }

    // --- Verify permission to create ---
    // Dibaca lewat helper terpusat di includes/global_permission.php, yang
    // membaca LANGSUNG dari tabel `permissions` dan sekaligus menegakkan
    // gerbang induk modul 'inventory'. Sebelumnya memakai _canAccess()
    // (cache session) sehingga pencabutan izin baru berlaku setelah user
    // logout lalu login lagi.
    if (!canCreateTakeItems()) {
        ti_json(false, null, ti_lang('no_permission'));
    }

    $conn->begin_transaction();

    try {
        $user_id          = (int) $_SESSION['user_id'];
        $transaction_date = date('Y-m-d H:i:s');
        $items_processed  = [];

        foreach ($_SESSION['take_cart'] as $code => $item) {
            $inventory_id = (int) $item['id'];

            // Kunci baris dan verifikasi stok + status aktif secara live.
            // is_active ikut dikunci di sini supaya barang yang dinonaktifkan
            // TEPAT sebelum tombol simpan ditekan tetap tidak bisa lolos.
            $stmt = $conn->prepare(
                "SELECT quantity, is_active FROM inventory WHERE id = ? FOR UPDATE"
            );
            if (!$stmt) throw new Exception(ti_lang('error'));

            $stmt->bind_param('i', $inventory_id);
            $stmt->execute();
            $stmt->bind_result($current_stock, $current_active);

            if (!$stmt->fetch()) {
                $stmt->close();
                throw new Exception(ti_lang('item_not_found') . ': ' . $code);
            }
            $current_stock  = ti_qty($current_stock);
            $current_active = (int) $current_active;
            $stmt->close();

            if ($current_active !== 1) {
                throw new Exception(ti_lang('item_inactive_cannot_take') . ': ' . $item['item_name']);
            }

            // Perbandingan memakai nilai yang sudah dibulatkan 2 desimal —
            // presisi yang sama dengan kolom DECIMAL(10,2) — sehingga
            // pengambilan pecahan kecil tidak tertolak karena galat float.
            $take_qty = ti_qty($item['quantity']);
            if ($take_qty <= 0) {
                throw new Exception(ti_lang('quantity_greater_than_zero'));
            }
            if ($current_stock < $take_qty) {
                throw new Exception(
                    ti_lang('insufficient_stock') . ': ' . $item['item_name']
                    . ' (' . ti_qty_str($current_stock) . ')'
                );
            }

            // Deduct stock
            $new_qty = ti_qty($current_stock - $take_qty);
            $stmt = $conn->prepare(
                "UPDATE inventory SET quantity = ? WHERE id = ?"
            );
            if (!$stmt) throw new Exception('Database error');

            $stmt->bind_param('di', $new_qty, $inventory_id);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to update inventory');
            }
            $stmt->close();

            // Record outgoing transaction
            /* Kolom taken_by_name & created_by_name adalah SALINAN PERMANEN
               users.full_name pada saat baris ini ditulis. Kolom itu tidak
               punya foreign key, jadi namanya tidak pernah hilang walaupun
               akun users-nya dihapus. Pola ini sama persis dengan yang sudah
               dipakai stock_opnames & goods_receipts. */
            $stmt = $conn->prepare("
                INSERT INTO inventory_out
                    (inventory_id, transaction_date, quantity, unit_id,
                     taken_by, taken_by_name, reference_number, notes,
                     created_by, created_by_name)
                VALUES (?, ?, ?, ?, ?,
                        (SELECT full_name FROM users WHERE id = ?),
                        ?, ?, ?,
                        (SELECT full_name FROM users WHERE id = ?))
            ");
            if (!$stmt) throw new Exception(ti_lang('error'));

            // Nilai yang SAMA dengan yang dipotong dari stok — tidak dihitung
            // ulang dari session, supaya catatan keluar dan saldo stok tidak
            // pernah bisa berbeda satu sama lain.
            $item_qty = $take_qty;
            $unit_id  = (int)   $item['unit_id'];

            $stmt->bind_param(
                'isdiiissii',
                $inventory_id,
                $transaction_date,
                $item_qty,
                $unit_id,
                $user_id,          // taken_by
                $user_id,          // -> taken_by_name
                $reference,
                $notes,
                $user_id,          // created_by
                $user_id           // -> created_by_name
            );
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception(ti_lang('failed_to_save_transaction'));
            }
            $stmt->close();

            $items_processed[] = [
                'code'      => $code,
                'name'      => $item['item_name'],
                'quantity'  => $take_qty,
                'unit'      => $item['unit_symbol'],
                'stock_before' => $current_stock,
                'stock_after'  => $new_qty,
            ];
        }

        $conn->commit();

        // Log aktivitas LENGKAP: setiap baris barang dicatat beserta kuantitas
        // yang diambil dan saldo stok sebelum → sesudah, bukan hanya jumlah
        // barisnya. Dengan begitu setiap perubahan stok dapat ditelusuri
        // sepenuhnya dari tabel activity_logs.
        $log_lines = [];
        foreach ($items_processed as $p) {
            $log_lines[] = $p['code'] . ' (' . $p['name'] . ') '
                         . ti_qty_str($p['quantity']) . ($p['unit'] ? ' ' . $p['unit'] : '')
                         . ' [' . ti_qty_str($p['stock_before']) . ' -> ' . ti_qty_str($p['stock_after']) . ']';
        }
        ti_log(
            'TAKE_ITEMS',
            'Ref: ' . $reference
            . ' | Items: ' . count($items_processed)
            . ' | ' . implode('; ', $log_lines)
            . ($notes !== '' ? ' | Notes: ' . $notes : '')
        );

        $_SESSION['take_cart'] = [];

        ti_json(true, [
            'reference' => $reference,
            'items'     => $items_processed,
            'count'     => count($items_processed),
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log('ti_ajax_submit_taking error: ' . $e->getMessage());
        ti_json(false, null, $e->getMessage());
    }
}

// ============================================================================
// AJAX: Get history (DataTables server-side)
// POST ajax=get_history  start=<int>  length=<int>  search[value]=<string>
// ============================================================================
function ti_ajax_get_history() {
    global $conn;

    $start        = isset($_POST['start'])  ? max(0, (int) $_POST['start'])  : 0;
    $length       = isset($_POST['length']) ? (int) $_POST['length']         : 25;
    $search_value = isset($_POST['search']['value'])
                    ? ti_sanitize($_POST['search']['value'])
                    : '';
    $draw         = isset($_POST['draw'])   ? (int) $_POST['draw']           : 1;
    $user_id      = (int) $_SESSION['user_id'];

    // Batas halaman dijaga agar permintaan tidak bisa memaksa server merender
    // seluruh tabel sekaligus. -1 dari DataTables berarti "semua"; di sini
    // diterjemahkan menjadi batas atas yang wajar, bukan tanpa batas.
    if ($length <= 0 || $length > 1000) $length = 1000;

    // Always filter by the logged-in user — every role sees only their own records.
    // SELURUH nilai kini dikirim lewat bind_param (prepared statement), termasuk
    // LIMIT dan pola LIKE — tidak ada lagi nilai yang disisipkan ke string SQL.
    $where  = "WHERE io.taken_by = ?";
    $types  = 'i';
    $params = [$user_id];

    if ($search_value !== '') {
        $like   = '%' . $search_value . '%';
        $where .= " AND (i.product_code LIKE ?
                      OR i.item_name    LIKE ?
                      OR io.reference_number LIKE ?)";
        $types   .= 'sss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    // Total records
    $total     = 0;
    $count_sql = "SELECT COUNT(*) AS total
                  FROM inventory_out io
                  LEFT JOIN inventory i ON io.inventory_id = i.id
                  {$where}";
    if ($cs = $conn->prepare($count_sql)) {
        $cs->bind_param($types, ...$params);
        $cs->execute();
        $cr = $cs->get_result();
        if ($cr && ($crow = $cr->fetch_assoc())) {
            $total = (int) $crow['total'];
        }
        $cs->close();
    }

    // Paginated records
    $sql = "
        SELECT
            DATE_FORMAT(io.created_at, '%d-%m-%Y %H:%i') AS transaction_date,
            COALESCE(i.product_code, '-')                 AS product_code,
            COALESCE(i.item_name, '-')                    AS item_name,
            CAST(io.quantity AS DECIMAL(10,4))            AS quantity,
            COALESCE(u.symbol, '')                        AS unit_symbol,
            COALESCE(io.reference_number, '-')            AS reference_number,
            COALESCE(io.notes, '')                        AS notes
        FROM inventory_out io
        LEFT JOIN inventory i ON io.inventory_id = i.id
        LEFT JOIN units u     ON io.unit_id = u.id
        {$where}
        ORDER BY io.created_at DESC
        LIMIT ?, ?
    ";

    $data       = [];
    $rowTypes   = $types . 'ii';
    $rowParams  = $params;
    $rowParams[] = $start;
    $rowParams[] = $length;

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param($rowTypes, ...$rowParams);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }
        $stmt->close();
    }

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

// ============================================================================
// DISPATCHER — called from take_items.php when POST ajax=* is received
// ============================================================================
function ti_dispatch_ajax() {
    if (!isset($_POST['ajax'])) return false;
    if (!isLoggedIn())          return false;

    // Ensure cart session key exists
    if (!isset($_SESSION['take_cart'])) {
        $_SESSION['take_cart'] = [];
    }

    // Gerbang halaman: seluruh endpoint hanya boleh dipanggil oleh akun yang
    // memang berhak membuka modul Take Items. Dibaca lewat helper terpusat di
    // includes/global_permission.php (DB-langsung + gerbang induk 'inventory').
    if (!canAccessTakeItems()) {
        ti_json(false, null, ti_lang('no_permission'));
    }

    $action = ti_sanitize($_POST['ajax']);

    switch ($action) {
        case 'get_item':
            canReadTakeItems() ? ti_ajax_get_item() : ti_json(false, null, ti_lang('no_permission'));
            break;

        case 'add_to_cart':
            canReadTakeItems() ? ti_ajax_add_to_cart() : ti_json(false, null, ti_lang('no_permission'));
            break;

        case 'update_cart_quantity':
            canReadTakeItems() ? ti_ajax_update_cart_quantity() : ti_json(false, null, ti_lang('no_permission'));
            break;

        case 'remove_from_cart':
            canReadTakeItems() ? ti_ajax_remove_from_cart() : ti_json(false, null, ti_lang('no_permission'));
            break;

        case 'clear_cart':
            canReadTakeItems() ? ti_ajax_clear_cart() : ti_json(false, null, ti_lang('no_permission'));
            break;

        case 'get_cart':
            ti_ajax_get_cart();
            break;

        case 'submit_taking':
            canCreateTakeItems() ? ti_ajax_submit_taking() : ti_json(false, null, ti_lang('no_permission'));
            break;

        case 'get_history':
            canReadTakeItems() ? ti_ajax_get_history() : ti_json(false, null, ti_lang('no_permission'));
            break;

        default:
            ti_json(false, null, ti_lang('error'));
    }

    return true; // dispatched
}