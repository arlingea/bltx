<?php
if (!isset($conn)) {
    exit('Direct access not allowed');
}

if (!checkPermission('inventory', 'can_create')) {
    redirect('inventory/inventory_gate.php?tab=inventory_in');
}

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// DUPLICATE PREVENTION: Buat unique form token
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(16));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // Check CSRF
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = $lang['invalid_csrf'];
        goto end_post;
    }
    
    // DUPLICATE PREVENTION: Check form token
    if (
        empty($_POST['form_token']) ||
        empty($_SESSION['form_token']) ||
        $_POST['form_token'] !== $_SESSION['form_token']
    ) {
        $error = $lang['duplicate_submit'];
        goto end_post;
    }
    
    // HAPUS form token untuk prevent duplicate
    unset($_SESSION['form_token']);
    
    $inventory_id = intval($_POST['inventory_id']);
    $quantity = floatval($_POST['quantity']);
    $unit_id = !empty($_POST['unit_id']) ? intval($_POST['unit_id']) : NULL;
    $notes = sanitize($_POST['notes']);
    $dt = DateTime::createFromFormat('Y-m-d', trim($_POST['transaction_date']));
    $transaction_date = ($dt !== false) ? $dt->format('Y-m-d') : date('Y-m-d');
    
    if ($inventory_id <= 0 || $quantity <= 0) {
        $error = $lang['invalid_item_qty'];
        goto end_post;
    }

    // CEK ITEM
    $check = $conn->prepare("SELECT id, product_code, item_name, quantity, unit_id FROM inventory WHERE id = ?");
    $check->bind_param("i", $inventory_id);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows == 0) {
        $error = $lang['item_not_found'];
        $check->close();
        goto end_post;
    }
    
    $row = $result->fetch_assoc();
    $product_code = $row['product_code'];
    $item_name = $row['item_name'];
    $old_qty = $row['quantity'];
    $check->close();
    
    if (is_null($unit_id) && !empty($row['unit_id'])) {
        $unit_id = $row['unit_id'];
    }
    
    // Generate reference
    $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $user_stmt->bind_result($user_full_name);
    $user_stmt->fetch();
    $user_stmt->close();
    
    $name_prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $user_full_name), 0, 3));
    $reference_number = 'IN' . $name_prefix . '-WH' . date('YmdHis');
    
    // START TRANSACTION
    $conn->begin_transaction();
    
    try {
        
        // INSERT ke inventory_in
        /* created_by_name = SALINAN PERMANEN users.full_name saat baris
           ditulis. Tanpa foreign key, sehingga nama pencatat tetap terbaca
           setelah akun users-nya dihapus. */
        $ins = $conn->prepare("
            INSERT INTO inventory_in 
            (reference_number, inventory_id, quantity, unit_id, transaction_date, notes, created_by, created_by_name) 
            VALUES (?, ?, ?, ?, STR_TO_DATE(?, '%Y-%m-%d'), ?, ?, (SELECT full_name FROM users WHERE id = ?))
        ");
        
        // bind_param: s=string, i=int, d=decimal, i=int, s=string, s=string, i=int, i=int
        $ins->bind_param("sidsisii", 
            $reference_number,
            $inventory_id,
            $quantity,
            $unit_id,
            $transaction_date,
            $notes,
            $_SESSION['user_id'],
            $_SESSION['user_id']
        );
        
        if (!$ins->execute()) {
            throw new Exception("Gagal INSERT inventory_in: " . $ins->error);
        }
        
        if ($ins->affected_rows !== 1) {
            throw new Exception("INSERT gagal - affected rows: " . $ins->affected_rows);
        }
        
        $ins->close();
        
        // UPDATE inventory
        $upd = $conn->prepare("
            UPDATE inventory 
            SET quantity = quantity + ? 
            WHERE id = ? 
            LIMIT 1
        ");
        $upd->bind_param("di", $quantity, $inventory_id);
        
        if (!$upd->execute()) {
            throw new Exception("Gagal UPDATE inventory: " . $upd->error);
        }
        
        if ($upd->affected_rows !== 1) {
            throw new Exception("UPDATE gagal - affected rows: " . $upd->affected_rows);
        }
        
        $upd->close();
        
        // COMMIT
        $conn->commit();
        
        $new_qty = $old_qty + $quantity;
        
        $_SESSION['success'] = "Stock berhasil ditambahkan.";
        
        // REDIRECT
        header('Location: ' . url('inventory/inventory_gate.php?tab=inventory_in'), true, 303);
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        /* Pesan diterjemahkan; detail teknis TIDAK lagi ditampilkan ke pengguna.
   $e->getMessage() dari mysqli bisa memuat nama tabel, nama kolom, dan
   nama user database — itu keterangan yang berguna bagi penyerang dan
   tidak berarti apa-apa bagi pengguna. Detailnya masuk log. */
        error_log('[bltx] inventory_in_add gagal: ' . $e->getMessage());
        $error = $lang['transaction_save_failed'];
    }
}

end_post:

// Generate form token baru
$_SESSION['form_token'] = bin2hex(random_bytes(16));

// Generate reference untuk display
$user_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
$user_stmt->bind_param("i", $_SESSION['user_id']);
$user_stmt->execute();
$user_stmt->bind_result($user_full_name);
$user_stmt->fetch();
$user_stmt->close();

$name_prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $user_full_name), 0, 3));
$auto_reference = 'IN' . $name_prefix . '-WH' . date('YmdHis');

$items = $conn->query("SELECT i.id, i.product_code, i.item_name, i.quantity, i.unit_id, u.name as unit_name, u.symbol FROM inventory i LEFT JOIN units u ON i.unit_id = u.id ORDER BY i.item_name");
?>

<style>
.card { border-radius: 0.5rem; box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); border: 1px solid rgba(0,0,0,0.125); }
.card-body { padding: 1.5rem; }
.card-header { background-color: #fff; border-bottom: 1px solid rgba(0,0,0,0.125); padding: 1rem 1.5rem; }
h5 { font-size: 1.25rem; font-weight: 600; margin: 0; }
.form-label { font-weight: 500; margin-bottom: 0.5rem; font-size: 0.875rem; }
.form-control, .form-select { font-size: 0.9375rem; }
.btn-sm { padding: 0.375rem 0.75rem; font-size: 0.875rem; }
.info-display { padding: 0.5rem 0.75rem; background-color: #f8f9fa; border-radius: 0.25rem; border: 1px solid #dee2e6; font-weight: 500; }
.alert { border-radius: 0.5rem; border: none; }
@media (max-width: 768px) { .card-body { padding: 1rem; } .card-header { padding: 0.75rem 1rem; } h5 { font-size: 1.1rem; } }
</style>

<div class="card border-0 shadow-sm">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-box-arrow-in-down"></i> <?php echo $lang['add_stock']; ?></div>
    </div>
    <div class="card-body">
        
        <?php
        /* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4.
           Menggantikan dua alert inline yang mencetak $_SESSION MENTAH:
           echo $_SESSION['error'] tanpa escape apa pun. Aman hari
           ini semata-mata karena sanitize() masih meng-escape saat input —
           begitu Tahap 1 mencabutnya, baris itu menjadi lubang XSS.
           bltx_render_flash() selalu meng-escape saat keluar. */
        bltx_render_flash('invinadd');
        ?>
        
        <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <form method="POST" id="formAddStock">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="form_token" value="<?php echo $_SESSION['form_token']; ?>">
            
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><?php echo $lang['item']; ?> <span class="text-danger">*</span></label>
                    <select name="inventory_id" id="inventory_id" class="form-select" required>
                        <option value="">- <?php echo $lang['select_item']; ?> -</option>
                        <?php while ($item = $items->fetch_assoc()): ?>
                        <option value="<?php echo $item['id']; ?>"
                            data-code="<?php echo htmlspecialchars($item['product_code']); ?>"
                            data-unitid="<?php echo $item['unit_id']; ?>"
                            data-unitname="<?php echo htmlspecialchars($item['unit_name']); ?>"
                            data-stock="<?php echo $item['quantity']; ?>"
                            data-symbol="<?php echo htmlspecialchars($item['symbol']); ?>">
                            <?php echo htmlspecialchars($item['product_code'] . ' - ' . $item['item_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label"><?php echo $lang['date']; ?> <span class="text-danger">*</span></label>
                    <input type="date" name="transaction_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label"><?php echo $lang['quantity']; ?> <span class="text-danger">*</span></label>
                    <input type="number" name="quantity" id="quantity" step="0.01" class="form-control" required min="0.01" placeholder="0.00">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label"><?php echo $lang['unit']; ?></label>
                    <div id="unit_display" class="info-display text-primary">-</div>
                    <input type="hidden" name="unit_id" id="unit_id">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label"><?php echo $lang['current_stock']; ?></label>
                    <div id="current_stock" class="info-display text-success">-</div>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label"><?php echo $lang['reference_number']; ?></label>
                    <div class="info-display text-primary fw-bold"><?php echo $auto_reference; ?></div>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label"><?php echo $lang['notes']; ?></label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="<?php echo $lang['notes_placeholder']; ?>"></textarea>
                </div>
            </div>
            
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary btn-sm" id="btnSubmit">
                    <i class="bi bi-save"></i> <?php echo $lang['save']; ?>
                </button>
                <a href="<?php echo url('inventory/inventory_gate.php?tab=inventory_in'); ?>" class="btn btn-secondary btn-sm">
                    <i class="bi bi-x-circle"></i> <?php echo $lang['cancel']; ?>
                </a>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    'use strict';
    var formSubmitted = false;
    
    function init() {
        if (typeof jQuery === 'undefined') {
            setTimeout(init, 100);
            return;
        }
        
        jQuery(document).ready(function($) {
            $('#inventory_id').on('change', function() {
                var val = $(this).val();
                if (!val) {
                    $('#unit_id').val('');
                    $('#unit_display').text('-');
                    $('#current_stock').text('-');
                    return;
                }
                
                var opt = $(this).find('option:selected');
                var unitId = opt.data('unitid');
                var unitName = opt.data('unitname');
                var stock = opt.data('stock');
                var symbol = opt.data('symbol');
                
                $('#unit_id').val(unitId || '');
                
                if (unitName) {
                    var txt = symbol ? unitName + ' (' + symbol + ')' : unitName;
                    $('#unit_display').text(txt);
                } else {
                    $('#unit_display').text('-');
                }
                
                if (stock !== undefined && stock !== null) {
                    var st = symbol ? stock + ' ' + symbol : stock;
                    $('#current_stock').text(st);
                } else {
                    $('#current_stock').text('0');
                }
            });
            
            $('#formAddStock').on('submit', function(e) {
                if (formSubmitted) {
                    e.preventDefault();
                    alert('Form sudah di-submit! Mohon tunggu...');
                    return false;
                }
                formSubmitted = true;
                $('#btnSubmit').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Processing...');
            });
        });
    }
    init();
})();
</script>