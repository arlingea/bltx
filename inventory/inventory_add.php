<?php
// ===============================
// SAFETY BOOTSTRAP (DO NOT TOUCH LOGIC)
// ===============================
if (!isset($conn)) {
    exit('Direct access not allowed');
}

// Permission
if (!checkPermission('inventory', 'can_create')) {
    redirect('dashboard.php');
}

/* ===============================
   FORM PROCESSING
=============================== */
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_code = sanitize($_POST['product_code']);
    $item_name    = sanitize($_POST['item_name']);
    $brand        = sanitize($_POST['brand']);
    $category_id  = !empty($_POST['category_id']) ? intval($_POST['category_id']) : NULL;
    $supplier_id  = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : NULL;
    $location_id  = !empty($_POST['location_id']) ? intval($_POST['location_id']) : NULL;
    $unit_id      = !empty($_POST['unit_id']) ? intval($_POST['unit_id']) : NULL;
    $qty_per_unit         = floatval($_POST['qty_per_unit'] ?? 0);
    $qty_per_unit_unit_id = !empty($_POST['qty_per_unit_unit_id']) ? intval($_POST['qty_per_unit_unit_id']) : NULL;
    $description  = sanitize($_POST['description']);
    $unit_price   = floatval($_POST['unit_price']);
    $quantity     = floatval($_POST['quantity']);
    $min_stock    = floatval($_POST['min_stock']);

    // Check product code
    $check = $conn->prepare("SELECT id FROM inventory WHERE product_code = ?");
    $check->bind_param("s", $product_code);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $error = $LANG['product_code_exists'];
    } else {

        // Image upload
        $item_image = NULL;
        if (!empty($_FILES['item_image']['name']) && $_FILES['item_image']['error'] === 0) {
            $allowed = ['jpg','jpeg','png'];
            $ext = strtolower(pathinfo($_FILES['item_image']['name'], PATHINFO_EXTENSION));

            if (in_array($ext, $allowed) && $_FILES['item_image']['size'] <= 2097152) {
                $new_name = 'item_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['item_image']['tmp_name'], UPLOAD_PATH . $new_name)) {
                    $item_image = $new_name;
                }
            }
        }

        // INSERT (LOGIC AS-IS)
        /* created_by_name = SALINAN PERMANEN users.full_name saat item
           dibuat. Tanpa foreign key, jadi nama pembuat item tidak hilang
           walaupun akun users-nya dihapus. */
        $stmt = $conn->prepare("
            INSERT INTO inventory 
            (product_code,item_name,brand,category_id,supplier_id,location_id,unit_id,qty_per_unit,qty_per_unit_unit_id,description,unit_price,quantity,min_stock,item_image,created_by,created_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,(SELECT full_name FROM users WHERE id = ?))
        ");
        $stmt->bind_param(
            "sssiiiidisdddsii",
            $product_code,
            $item_name,
            $brand,
            $category_id,
            $supplier_id,
            $location_id,
            $unit_id,
            $qty_per_unit,
            $qty_per_unit_unit_id,
            $description,
            $unit_price,
            $quantity,
            $min_stock,
            $item_image,
            $_SESSION['user_id'],
            $_SESSION['user_id']
        );

        if ($stmt->execute()) {
            logActivity('add_inventory', "Added inventory: $item_name");
            $_SESSION['success'] = $LANG['inventory_added'];
            redirect('?tab=inventory_data');
        } else {
            $error = $conn->error;
        }
    }
}

/* ===============================
   DROPDOWNS
=============================== */
$categories = $conn->query("SELECT id,name FROM categories WHERE is_active=1 ORDER BY name");
$suppliers  = $conn->query("SELECT id,name FROM suppliers WHERE is_active=1 ORDER BY name");
$locations  = $conn->query("SELECT id,location_code,location_name FROM warehouse_locations WHERE is_active=1 ORDER BY location_code");
$units      = $conn->query("SELECT id,name,symbol FROM units WHERE is_active=1 ORDER BY name");
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <div class="card-title"><i class="bi bi-plus-circle"></i> <?php echo $LANG['add_inventory']; ?></div>
                </div>
                <div class="card-body">
                    <?php
                    /* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4.
                       Menggantikan dua alert inline yang mencetak $_SESSION MENTAH:
                       echo $_SESSION['error'] tanpa escape apa pun. Aman hari
                       ini semata-mata karena sanitize() masih meng-escape saat input —
                       begitu Tahap 1 mencabutnya, baris itu menjadi lubang XSS.
                       bltx_render_flash() selalu meng-escape saat keluar. */
                    bltx_render_flash('invadd');
                    ?>
                    
                    <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data" id="inventoryForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['product_code']; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="product_code" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['item_name']; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="item_name" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['brand']; ?></label>
                                    <input type="text" name="brand" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['category']; ?></label>
                                    <select name="category_id" class="form-select">
                                        <option value="">- <?php echo $LANG['category']; ?> -</option>
                                        <?php while ($cat = $categories->fetch_assoc()): ?>
                                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['supplier']; ?></label>
                                    <select name="supplier_id" class="form-select">
                                        <option value="">- <?php echo $LANG['supplier']; ?> -</option>
                                        <?php while ($sup = $suppliers->fetch_assoc()): ?>
                                        <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['name']); ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['location']; ?></label>
                                    <select name="location_id" class="form-select">
                                        <option value="">- <?php echo $LANG['location']; ?> -</option>
                                        <?php while ($loc = $locations->fetch_assoc()): ?>
                                        <option value="<?php echo $loc['id']; ?>"><?php echo htmlspecialchars($loc['location_code'] . ' - ' . $loc['location_name']); ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['unit']; ?></label>
                                    <select name="unit_id" class="form-select">
                                        <option value="">- <?php echo $LANG['unit']; ?> -</option>
                                        <?php
                                        $units->data_seek(0);
                                        while ($unit = $units->fetch_assoc()): ?>
                                        <option value="<?php echo $unit['id']; ?>"><?php echo htmlspecialchars($unit['name']); ?> (<?php echo htmlspecialchars($unit['symbol']); ?>)</option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['qty_per_unit']; ?></label>
                                    <div class="input-group">
                                        <input type="number" name="qty_per_unit" step="0.01" class="form-control" value="0" min="0"
                                               placeholder="<?php echo $LANG['qty_unit_hint']; ?>">
                                        <select name="qty_per_unit_unit_id" class="form-select" style="max-width:110px;">
                                            <option value="">-</option>
                                            <?php
                                            $units->data_seek(0);
                                            while ($uqpu = $units->fetch_assoc()): ?>
                                            <option value="<?php echo $uqpu['id']; ?>"><?php echo htmlspecialchars($uqpu['symbol']); ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <small class="text-muted"><?php echo $LANG['qty_unit_hint']; ?></small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['quantity']; ?> <span class="text-danger">*</span></label>
                                    <input type="number" name="quantity" step="0.01" class="form-control" value="0" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['min_stock']; ?></label>
                                    <input type="number" name="min_stock" step="0.01" class="form-control" value="0">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['unit_price']; ?> <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo htmlspecialchars(getSetting('currency_symbol','Rp'),ENT_QUOTES,'UTF-8'); ?></span>
                                        <input type="text" id="unit_price_display" class="form-control" placeholder="0" inputmode="numeric" autocomplete="off"
                                               oninput="syncPriceInput(this,'unit_price_hidden')" required>
                                        <input type="hidden" name="unit_price" id="unit_price_hidden" value="0">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['item_image']; ?></label>
                                    <input type="file" name="item_image" class="form-control" accept="image/*">
                                    <small class="text-muted">Max 2MB (JPG/JPEG/PNG)</small>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['description']; ?></label>
                                    <textarea name="description" class="form-control" rows="3"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-save"></i> <?php echo $LANG['save']; ?>
                            </button>
                            <a href="?tab=inventory_data" class="btn btn-sm btn-secondary">
                                <i class="bi bi-x-circle"></i> <?php echo $LANG['cancel']; ?>
                            </a>
                        </div>
                    </form>
<script>
function syncPriceInput(el, hiddenId) {
    var raw = el.value.replace(/[^0-9]/g, '');
    if (raw === '') { el.value = ''; document.getElementById(hiddenId).value = 0; return; }
    var num = parseInt(raw, 10);
    el.value = num.toLocaleString('id-ID');
    document.getElementById(hiddenId).value = num;
}
</script>
                </div>
            </div>
        </div>
    </div>
</div>