<?php
// ===============================
// SAFETY BOOTSTRAP
// ===============================
if (!isset($conn)) {
    exit('Direct access not allowed');
}


// Permission
if (!checkPermission('inventory', 'can_update')) {
    redirect('dashboard.php');
}

/* ===============================
   GET ID
=============================== */
if (!isset($_GET['id'])) {
    redirect('?tab=inventory_data');
}

$id = intval($_GET['id']);
$error = '';

/* ===============================
   FORM PROCESS
=============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $product_code = sanitize($_POST['product_code']);
    $item_name    = sanitize($_POST['item_name']);
    $brand        = sanitize($_POST['brand']);
    $category_id  = !empty($_POST['category_id']) ? intval($_POST['category_id']) : NULL;
    $supplier_id  = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : NULL;
    $location_id  = !empty($_POST['location_id']) ? intval($_POST['location_id']) : NULL;
    $unit_id      = !empty($_POST['unit_id']) ? intval($_POST['unit_id']) : NULL;
    $description  = sanitize($_POST['description']);
    $qty_per_unit         = floatval($_POST['qty_per_unit'] ?? 0);
    $qty_per_unit_unit_id = !empty($_POST['qty_per_unit_unit_id']) ? intval($_POST['qty_per_unit_unit_id']) : NULL;
    $unit_price   = floatval($_POST['unit_price']);
    $min_stock    = floatval($_POST['min_stock']);

    // Get current image
    $stmt = $conn->prepare("SELECT item_image FROM inventory WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($current_image);
    $stmt->fetch();
    $stmt->close();

    $item_image = $current_image;

    // Remove image
    if (!empty($_POST['remove_image'])) {
        if ($current_image && file_exists(UPLOAD_PATH.$current_image)) {
            unlink(UPLOAD_PATH.$current_image);
        }
        $item_image = NULL;
    }

    // Upload new image
    if (!empty($_FILES['item_image']['name']) && $_FILES['item_image']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['item_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png'];

        if (in_array($ext,$allowed)) {
            $new = 'item_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['item_image']['tmp_name'], UPLOAD_PATH.$new)) {
                if ($current_image && file_exists(UPLOAD_PATH.$current_image)) {
                    unlink(UPLOAD_PATH.$current_image);
                }
                $item_image = $new;
            }
        }
    }

    // UPDATE (LOGIC AS-IS)
    $stmt = $conn->prepare("
        UPDATE inventory SET
        product_code=?, item_name=?, brand=?, category_id=?, supplier_id=?, location_id=?, unit_id=?,
        qty_per_unit=?, qty_per_unit_unit_id=?, description=?, unit_price=?, min_stock=?, item_image=?
        WHERE id=?
    ");
    $stmt->bind_param(
        "sssiiiidisddsi",
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
        $min_stock,
        $item_image,
        $id
    );

    if ($stmt->execute()) {
        logActivity('edit_inventory', "Edited inventory ID: $id");
        $_SESSION['success'] = $LANG['update_success'];
        redirect('?tab=inventory_data');
    } else {
        $error = $conn->error;
    }
}

/* ===============================
   LOAD DATA
=============================== */
$_ii = $conn->prepare("SELECT * FROM inventory WHERE id=?");
$_ii->bind_param('i', $id);
$_ii->execute();
$item = $_ii->get_result()->fetch_assoc();
$_ii->close();

$categories = $conn->query("SELECT id,name FROM categories WHERE is_active=1");
$suppliers  = $conn->query("SELECT id,name FROM suppliers WHERE is_active=1");
$locations  = $conn->query("SELECT id,location_code,location_name FROM warehouse_locations WHERE is_active=1");
$units      = $conn->query("SELECT id,name,symbol FROM units WHERE is_active=1");
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <div class="card-title"><i class="bi bi-pencil-square"></i> <?php echo $LANG['edit']; ?> <?php echo $LANG['inventory']; ?></div>
                </div>
                <div class="card-body">
                    <?php
                    /* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4.
                       Menggantikan dua alert inline yang mencetak $_SESSION MENTAH:
                       echo $_SESSION['error'] tanpa escape apa pun. Aman hari
                       ini semata-mata karena sanitize() masih meng-escape saat input —
                       begitu Tahap 1 mencabutnya, baris itu menjadi lubang XSS.
                       bltx_render_flash() selalu meng-escape saat keluar. */
                    bltx_render_flash('invedit');
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
                                    <input type="text" name="product_code" class="form-control" value="<?php echo htmlspecialchars($item['product_code']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['item_name']; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="item_name" class="form-control" value="<?php echo htmlspecialchars($item['item_name']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['brand']; ?></label>
                                    <input type="text" name="brand" class="form-control" value="<?php echo htmlspecialchars($item['brand'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['category']; ?></label>
                                    <select name="category_id" class="form-select">
                                        <option value="">- <?php echo $LANG['category']; ?> -</option>
                                        <?php 
                                        if ($categories->num_rows > 0) {
                                            $categories->data_seek(0);
                                        }
                                        while ($cat = $categories->fetch_assoc()): 
                                        ?>
                                        <option value="<?php echo $cat['id']; ?>" <?php echo ($item['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['supplier']; ?></label>
                                    <select name="supplier_id" class="form-select">
                                        <option value="">- <?php echo $LANG['supplier']; ?> -</option>
                                        <?php 
                                        if ($suppliers->num_rows > 0) {
                                            $suppliers->data_seek(0);
                                        }
                                        while ($sup = $suppliers->fetch_assoc()): 
                                        ?>
                                        <option value="<?php echo $sup['id']; ?>" <?php echo ($item['supplier_id'] == $sup['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sup['name']); ?>
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['location']; ?></label>
                                    <select name="location_id" class="form-select">
                                        <option value="">- <?php echo $LANG['location']; ?> -</option>
                                        <?php 
                                        if ($locations->num_rows > 0) {
                                            $locations->data_seek(0);
                                        }
                                        while ($loc = $locations->fetch_assoc()): 
                                        ?>
                                        <option value="<?php echo $loc['id']; ?>" <?php echo ($item['location_id'] == $loc['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($loc['location_code'] . ' - ' . $loc['location_name']); ?>
                                        </option>
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
                                        if ($units->num_rows > 0) { $units->data_seek(0); }
                                        while ($unit = $units->fetch_assoc()):
                                        ?>
                                        <option value="<?php echo $unit['id']; ?>" <?php echo ($item['unit_id'] == $unit['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($unit['name']); ?> (<?php echo htmlspecialchars($unit['symbol']); ?>)
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['qty_per_unit']; ?></label>
                                    <div class="input-group">
                                        <input type="number" name="qty_per_unit" step="0.01" class="form-control"
                                               value="<?php echo htmlspecialchars($item['qty_per_unit'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>"
                                               min="0" placeholder="<?php echo $LANG['qty_unit_hint']; ?>">
                                        <select name="qty_per_unit_unit_id" class="form-select" style="max-width:110px;">
                                            <option value="">-</option>
                                            <?php
                                            if ($units->num_rows > 0) { $units->data_seek(0); }
                                            while ($uqpu = $units->fetch_assoc()):
                                            ?>
                                            <option value="<?php echo $uqpu['id']; ?>" <?php echo (isset($item['qty_per_unit_unit_id']) && $item['qty_per_unit_unit_id'] == $uqpu['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($uqpu['symbol']); ?>
                                            </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <small class="text-muted"><?php echo $LANG['qty_unit_hint']; ?></small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['quantity']; ?> <span class="text-danger">*</span></label>
                                    <input type="number" name="quantity" step="0.01" class="form-control" value="<?php echo $item['quantity']; ?>" readonly>
                                    <small class="text-muted"><?php echo $LANG['use_inventory_in_out']; ?></small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['min_stock']; ?></label>
                                    <input type="number" name="min_stock" step="0.01" class="form-control" value="<?php echo $item['min_stock']; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['unit_price']; ?> <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo htmlspecialchars(getSetting('currency_symbol','Rp'),ENT_QUOTES,'UTF-8'); ?></span>
                                        <input type="text" id="unit_price_display" class="form-control" inputmode="numeric" autocomplete="off"
                                               value="<?php echo number_format((float)$item['unit_price'],0,'','.'); ?>"
                                               oninput="syncPriceInput(this,'unit_price_hidden')" required>
                                        <input type="hidden" name="unit_price" id="unit_price_hidden" value="<?php echo (float)$item['unit_price']; ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['item_image']; ?></label>
                                    <input type="file" name="item_image" class="form-control" accept="image/*">
                                    <small class="text-muted">Max 2MB (JPG/JPEG/PNG)</small>
                                    <?php if ($item['item_image']): ?>
                                    <div class="mt-2">
                                        <img src="<?php echo UPLOAD_URL . htmlspecialchars($item['item_image']); ?>" class="img-thumbnail" style="max-width: 150px;">
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" name="remove_image" id="remove_image" value="1">
                                            <label class="form-check-label" for="remove_image">
                                                <?php echo $LANG['remove_current_image']; ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $LANG['description']; ?></label>
                                    <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($item['description'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-warning">
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