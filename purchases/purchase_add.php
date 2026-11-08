<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/purchases_function.php';
require_once __DIR__ . '/../includes/global_permission.php';

// ── Direct-access guard ──────────────────────────────────────────────────────
requireLogin();
requirePurchaseCreate();
// GERBANG PURCHASE — modul 'purchase' harus aktif. Tanpa baris ini,
// mematikan gerbang tidak menutup halaman ini bila diketik langsung
// lewat URL. Superadmin selalu lolos di dalam _dbCan().
requirePurchaseModule('purchases');

$page_title = $LANG['add'] . ' ' . $LANG['purchases'];

// ── Currency config (for JS) ─────────────────────────────────────────────────
$currencyCode   = pf_getCurrencyCode();
$currencySymbol = pf_getCurrencySymbol();
$isIDR          = ($currencyCode === 'IDR');

// ── CSRF token ───────────────────────────────────────────────────────────────
$csrfToken = pf_csrfToken();

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = pf_addPurchase($conn, $_POST, $_FILES, (int)$_SESSION['user_id']);
    if ($result['success']) {
        $_SESSION['success'] = $LANG['save_success'];
        // Tab dicantumkan eksplisit agar setelah simpan tetap berada di tab
        // Purchase Invoice (default tab halaman hub adalah 'order').
        redirect(url('purchases/pr_dahsboard_gate.php?tab=invoice'));
    } else {
        $_SESSION['error'] = $LANG[$result['message_key']] ?? $result['message_key'];
        redirect(url('purchases/purchase_add.php'));
    }
}

// ── Fetch form data ──────────────────────────────────────────────────────────
$suppliers       = pf_fetchSuppliers($conn);
$categories      = pf_fetchCategories($conn);
$units           = pf_fetchUnits($conn);
$purchase_staffs = pf_fetchUsers($conn);
$payment_methods = pf_fetchPaymentMethods($conn);
$employee_shifts = pf_fetchShifts($conn);

// Pre-load arrays for multiple data_seek reuse
$staffArr = [];
while ($r = $purchase_staffs->fetch_assoc()) $staffArr[] = $r;

$catsArr = [];
while ($r = $categories->fetch_assoc()) $catsArr[] = $r;

$unitsArr = [];
while ($r = $units->fetch_assoc()) $unitsArr[] = $r;

// ── Flash messages ───────────────────────────────────────────────────────────
$flashError   = $_SESSION['error']   ?? ''; unset($_SESSION['error']);
$flashSuccess = $_SESSION['success'] ?? ''; unset($_SESSION['success']);

// ── extra_css ────────────────────────────────────────────────────────────────
$extra_css = '
<style>
/* ── Custom Item Column Sizing ──────────────────────────────── */
@media (min-width: 768px) {
    .col-item-name { flex: 2 1 200px !important; min-width: 200px !important; }
    .col-item-field { flex: 1 1 0px !important; min-width: 120px !important; }
    .col-item-action { flex: 0 0 70px !important; min-width: 70px !important; }
}
@media (max-width: 767px) {
    .col-item-name { flex: 1 1 100% !important; min-width: 200px !important; }
    .col-item-field { flex: 0 0 auto !important; min-width: 120px !important; }
    .col-item-action { flex: 0 0 auto !important; min-width: 70px !important; }
}
/* ── Currency input group ───────────────────────────────────── */
.input-currency .input-group-text {
    font-weight: 600;
    min-width: 38px;
    justify-content: center;
}
.input-currency input {
    text-align: right;
}
/* ── Item rows ──────────────────────────────────────────────── */
.item-row {
    background: var(--bs-body-bg, #fff);
    border: 1px solid var(--bs-border-color, #dee2e6) !important;
    transition: box-shadow .15s;
}
.item-row:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
}
.item-number-badge {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: .85rem;
    background: var(--bs-primary);
    color: #fff;
    flex-shrink: 0;
}
/* ── Document preview ───────────────────────────────────────── */
#documentPreview img {
    max-width: 150px;
    max-height: 150px;
    object-fit: contain;
    display: block;
    margin-top: 6px;
    border-radius: 6px;
    border: 1px solid var(--bs-border-color, #dee2e6);
}
/* ── Totals panel ───────────────────────────────────────────── */
.totals-panel {
    background: var(--bs-light, #f8f9fa);
    border-radius: 8px;
}
.totals-panel .total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
    border-bottom: 1px dashed var(--bs-border-color, #dee2e6);
}
.totals-panel .total-row:last-child {
    border-bottom: none;
    padding-top: 10px;
    margin-top: 4px;
    border-top: 2px solid var(--bs-border-color, #dee2e6);
}
.totals-panel .total-label { font-weight: 500; color: var(--bs-secondary-color, #6c757d); }
.totals-panel .total-value { font-weight: 600; font-size: 1rem; }
.totals-panel .total-value.grand { font-size: 1.25rem; color: var(--bs-primary); }

/* ── Custom Scrollbar for Item Row ──────────────────────────── */
.custom-scrollbar::-webkit-scrollbar { height: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: var(--bs-light, #f8f9fa); border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #adb5bd; border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #6c757d; }
</style>
';

include '../includes/header.php';
?>

<?php if ($flashError): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($flashSuccess): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Page header ─────────────────────────────────────────────────────────── -->
<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-plus-circle"></i><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></h2>
    <div class="page-actions">
        <a href="<?= url('purchases/pr_dahsboard_gate.php') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back'], ENT_QUOTES, 'UTF-8') ?>
        </a>
    </div>
</div>

<!-- ── Form ────────────────────────────────────────────────────────────────── -->
<form method="POST" enctype="multipart/form-data" id="purchaseForm" autocomplete="off" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="row g-3">
        <!-- ── Purchase Info ── -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <div class="card-title"><i class="bi bi-receipt"></i> <span><?= htmlspecialchars(($LANG['purchase']) . ' ' . $LANG['info'], ENT_QUOTES, 'UTF-8') ?></span></div>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-calendar-event me-1"></i><?= htmlspecialchars($LANG['input_date'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="input_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-file-earmark-text me-1"></i><?= htmlspecialchars($LANG['invoice_date'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($LANG['purchase_staff'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                        <select class="form-select" name="purchase_staff_id" required>
                            <option value="">-- <?= htmlspecialchars($LANG['select'], ENT_QUOTES, 'UTF-8') ?> --</option>
                            <?php foreach ($staffArr as $ps): ?>
                            <option value="<?= (int)$ps['id'] ?>"><?= htmlspecialchars($ps['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-building me-1"></i><?= htmlspecialchars($LANG['supplier'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                        <select class="form-select" name="supplier_id" required>
                            <option value="">-- <?= htmlspecialchars($LANG['select'], ENT_QUOTES, 'UTF-8') ?> --</option>
                            <?php while ($sup = $suppliers->fetch_assoc()): ?>
                            <option value="<?= (int)$sup['id'] ?>"><?= htmlspecialchars($sup['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endwhile; ?>
                        </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-credit-card me-1"></i><?= htmlspecialchars($LANG['payment_method'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                        <select class="form-select" name="payment_method" required>
                            <option value="">-- <?= htmlspecialchars($LANG['select'], ENT_QUOTES, 'UTF-8') ?> --</option>
                            <?php while ($pm = $payment_methods->fetch_assoc()): ?>
                            <option value="<?= (int)$pm['id'] ?>"><?= htmlspecialchars($pm['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Payer Info ── -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <div class="card-title"><i class="bi bi-person-check"></i> <span><?= htmlspecialchars($LANG['payer'] . ' ' . $LANG['info'], ENT_QUOTES, 'UTF-8') ?></span></div>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-person me-1"></i><?= htmlspecialchars($LANG['payment_by'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                        <select class="form-select" name="payment_by" required>
                            <option value="">-- <?= htmlspecialchars($LANG['select'], ENT_QUOTES, 'UTF-8') ?> --</option>
                            <?php foreach ($staffArr as $ps): ?>
                            <option value="<?= (int)$ps['id'] ?>"><?= htmlspecialchars($ps['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-person-check me-1"></i><?= htmlspecialchars($LANG['payer_name'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                        <select class="form-select" name="payer_name" required>
                            <option value="">-- <?= htmlspecialchars($LANG['select'], ENT_QUOTES, 'UTF-8') ?> --</option>
                            <?php foreach ($staffArr as $ps): ?>
                            <option value="<?= (int)$ps['id'] ?>"><?= htmlspecialchars($ps['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-clock me-1"></i><?= htmlspecialchars($LANG['payer_shift'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                        <select class="form-select" name="payer_shift" required>
                            <option value="">-- <?= htmlspecialchars($LANG['select'], ENT_QUOTES, 'UTF-8') ?> --</option>
                            <?php while ($shift = $employee_shifts->fetch_assoc()): ?>
                            <option value="<?= (int)$shift['id'] ?>"><?= htmlspecialchars($shift['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endwhile; ?>
                        </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-file-earmark-pdf me-1"></i><?= htmlspecialchars($LANG['document'], ENT_QUOTES, 'UTF-8') ?> <span class="text-muted">(Max 5MB)</span></label>
                        <input type="file" class="form-control" name="document" id="documentInput" accept="image/*,application/pdf">
                        <div id="documentPreview" class="mt-2"></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-sticky me-1"></i><?= htmlspecialchars($LANG['notes'], ENT_QUOTES, 'UTF-8') ?></label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Items ──────────────────────────────────────────────────────────── -->
    <div class="card mt-3">
        <div class="card-header">
                    <div class="card-title"><i class="bi bi-list-ul"></i> <span><?= htmlspecialchars($LANG['item'] . ' ' . $LANG['list'], ENT_QUOTES, 'UTF-8') ?></span></div>
                </div>
        <div class="card-body">
            <div id="itemsContainer">
                <!-- Items injected by JS / PHP template below -->
                <div class="item-row rounded p-3 mb-3" data-index="0">
                    <div class="d-flex align-items-start gap-3">
                        <div class="item-number-badge mt-1 flex-shrink-0"><span class="item-number">1</span></div>
                        <div class="flex-fill overflow-hidden">
                            <div class="d-flex flex-nowrap align-items-end gap-2 w-100 overflow-x-auto pb-2 custom-scrollbar">
                                <div class="col-item-name">
                                    <label class="form-label form-label-sm text-nowrap"><i class="bi bi-box me-1"></i><?= htmlspecialchars($LANG['item_name'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" name="items[0][item_name]" required>
                                </div>
                                <div class="col-item-field">
                                    <label class="form-label form-label-sm text-nowrap"><i class="bi bi-tag me-1"></i><?= htmlspecialchars($LANG['category'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm" name="items[0][category_id]" required>
                                        <option value="">-- <?= htmlspecialchars($LANG['select'], ENT_QUOTES, 'UTF-8') ?> --</option>
                                        <?php foreach ($catsArr as $cat): ?>
                                        <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-item-field">
                                    <label class="form-label form-label-sm text-nowrap"><i class="bi bi-rulers me-1"></i><?= htmlspecialchars($LANG['unit'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm" name="items[0][unit_id]" required>
                                        <option value="">-- <?= htmlspecialchars($LANG['select'], ENT_QUOTES, 'UTF-8') ?> --</option>
                                        <?php foreach ($unitsArr as $unit): ?>
                                        <option value="<?= (int)$unit['id'] ?>"><?= htmlspecialchars($unit['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($unit['symbol'], ENT_QUOTES, 'UTF-8') ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-item-field">
                                    <label class="form-label form-label-sm text-nowrap"><i class="bi bi-hash me-1"></i><?= htmlspecialchars($LANG['quantity'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0.01" class="form-control form-control-sm item-qty" name="items[0][quantity]" value="" required>
                                </div>
                                <div class="col-item-field">
                                    <label class="form-label form-label-sm text-nowrap"><i class="bi bi-currency-dollar me-1"></i><?= htmlspecialchars($LANG['unit_price'], ENT_QUOTES, 'UTF-8') ?> <span class="text-danger">*</span></label>
                                    <div class="input-group input-group-sm input-currency">
                                        <span class="input-group-text"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?></span>
                                        <input type="text" class="form-control item-currency-display" name="items[0][unit_price]"
                                               value="" inputmode="numeric"
                                               placeholder="0" autocomplete="off">
                                    </div>
                                </div>
                                <div class="col-item-field">
                                    <label class="form-label form-label-sm text-nowrap"><i class="bi bi-percent me-1"></i><?= htmlspecialchars($LANG['discount'], ENT_QUOTES, 'UTF-8') ?></label>
                                    <div class="input-group input-group-sm input-currency">
                                        <span class="input-group-text"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?></span>
                                        <input type="text" class="form-control item-currency-display" name="items[0][discount]"
                                               value="" inputmode="numeric"
                                               placeholder="0" autocomplete="off">
                                    </div>
                                </div>
                                <div class="col-item-field">
                                    <label class="form-label form-label-sm text-nowrap"><i class="bi bi-calculator me-1"></i><?= htmlspecialchars($LANG['price_rounding'], ENT_QUOTES, 'UTF-8') ?></label>
                                    <div class="input-group input-group-sm input-currency">
                                        <span class="input-group-text"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?></span>
                                        <input type="text" class="form-control item-currency-display" name="items[0][price_rounding]"
                                               value="" inputmode="numeric"
                                               placeholder="0" autocomplete="off">
                                    </div>
                                </div>
                                <div class="col-item-field" style="padding-bottom: 2px;">
                                    <label class="form-label form-label-sm text-nowrap"><i class="bi bi-cash-stack me-1"></i><?= htmlspecialchars($LANG['total_price'], ENT_QUOTES, 'UTF-8') ?></label>
                                    <div class="fw-bold text-success item-total fs-6"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?> 0</div>
                                </div>
                                <div class="col-item-action d-flex gap-1 align-items-end" style="padding-bottom: 2px;">
                                    <button type="button" class="btn btn-success btn-sm btn-add-item" title="<?= htmlspecialchars($LANG['add_item'], ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="bi bi-plus-circle"></i>
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm btn-remove-item" title="<?= htmlspecialchars($LANG['remove'], ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Totals panel ── -->
            <div class="totals-panel p-3 mt-3">
                <div class="total-row">
                    <span class="total-label"><i class="bi bi-list-check me-1"></i><?= htmlspecialchars($LANG['total'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="total-value" id="subtotalDisplay"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?> 0</span>
                </div>
                <div class="total-row">
                    <span class="total-label"><i class="bi bi-percent me-1"></i><?= htmlspecialchars($LANG['purchase_overall_discount'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span>
                        <div class="input-group input-group-sm input-currency" style="max-width:200px;margin-left:auto;">
                            <span class="input-group-text"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" class="form-control overall-currency-display" name="overall_discount"
                                   id="overallDiscountInput" value="" inputmode="numeric"
                                   placeholder="0" autocomplete="off">
                        </div>
                    </span>
                </div>
                <div class="total-row">
                    <span class="total-label"><i class="bi bi-calculator me-1"></i><?= htmlspecialchars($LANG['purchase_overall_price_rounding'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span>
                        <div class="input-group input-group-sm input-currency" style="max-width:200px;margin-left:auto;">
                            <span class="input-group-text"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" class="form-control overall-currency-display" name="overall_price_rounding"
                                   id="overallPriceRoundingInput" value="" inputmode="numeric"
                                   placeholder="0" autocomplete="off">
                        </div>
                    </span>
                </div>
                <div class="total-row">
                    <span class="total-label"><i class="bi bi-receipt me-1"></i><?= htmlspecialchars($LANG['purchase_tax'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span>
                        <div class="input-group input-group-sm input-currency" style="max-width:200px;margin-left:auto;">
                            <span class="input-group-text"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?></span>
                            <input type="text" class="form-control overall-currency-display" name="tax"
                                   id="taxInput" value="" inputmode="numeric"
                                   placeholder="0" autocomplete="off">
                        </div>
                    </span>
                </div>
                <div class="total-row">
                    <span class="total-label fw-bold fs-5"><i class="bi bi-cash-coin me-1"></i><?= htmlspecialchars($LANG['grand_total'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="total-value grand" id="grandTotalDisplay"><?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?> 0</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Submit ─────────────────────────────────────────────────────────── -->
    <div class="text-end mt-3 mb-4">
        <a href="<?= url('purchases/pr_dahsboard_gate.php') ?>" class="btn btn-sm btn-secondary me-2">
            <i class="bi bi-x-circle me-1"></i><?= htmlspecialchars($LANG['back'], ENT_QUOTES, 'UTF-8') ?>
        </a>
        <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-save me-1"></i><?= htmlspecialchars($LANG['save'], ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
</form>

<?php
// ── Prepare JSON data for JS ─────────────────────────────────────────────────
$catsJson = json_encode(array_map(function($c) { return ['id' => (int)$c['id'], 'name' => $c['name']]; }, $catsArr), JSON_UNESCAPED_UNICODE);
$unitsJson = json_encode(array_map(function($u) { return ['id' => (int)$u['id'], 'name' => $u['name'], 'symbol' => $u['symbol']]; }, $unitsArr), JSON_UNESCAPED_UNICODE);
$lang = [
    'select'           => $LANG['select'],
    'at_least_one_item'=> $LANG['purchase_at_least_one_item'],
    'file_size_error'  => $LANG['purchase_file_size_error'],
    'incomplete_form'  => $LANG['purchase_incomplete_form'],
    'add_item'         => $LANG['add_item'],
    'remove'           => $LANG['remove'],
    'item_name'        => $LANG['item_name'],
    'category'         => $LANG['category'],
    'unit'             => $LANG['unit'],
    'quantity'         => $LANG['quantity'],
    'unit_price'       => $LANG['unit_price'],
    'discount'         => $LANG['discount'],
    'price_rounding'   => $LANG['price_rounding'],
    'total_price'      => $LANG['total_price'],
];
$langJson = json_encode($lang, JSON_UNESCAPED_UNICODE | ENT_QUOTES);

$extra_js = '
<script>
(function() {
"use strict";

/* ── Config ─────────────────────────────────────────────────── */
var CURRENCY_CODE   = ' . json_encode($currencyCode) . ';
var CURRENCY_SYMBOL = ' . json_encode($currencySymbol) . ';
var IS_IDR          = ' . ($isIDR ? 'true' : 'false') . ';
var CATEGORIES      = ' . $catsJson . ';
var UNITS           = ' . $unitsJson . ';
var LANG            = ' . $langJson . ';

/* ── Number helpers ─────────────────────────────────────────── */
function parseAmount(str) {
    str = String(str || "0");
    if (IS_IDR) {
        str = str.replace(/\./g, "").replace(",", ".");
    } else {
        str = str.replace(/,/g, "");
    }
    return parseFloat(str) || 0;
}

function formatDisplay(num) {
    num = Math.abs(parseFloat(num) || 0);
    if (IS_IDR) {
        return Math.round(num).toLocaleString("id-ID");
    }
    return num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

function formatLabel(num) {
    return CURRENCY_SYMBOL + " " + formatDisplay(num);
}

function syncCurrencyInput(input) {
    var raw = input.value.replace(/[^0-9,\.]/g, "");
    if (raw === "") { input.value = ""; return; }
    if (IS_IDR) {
        raw = raw.replace(/\./g, "").replace(/,/g, "");
        if (raw === "") { input.value = "0"; return; }
        input.value = parseInt(raw, 10).toLocaleString("id-ID");
    } else {
        raw = raw.replace(/,/g, "");
        var parts = raw.split(".");
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        if (parts.length > 2) parts = [parts[0], parts[1]];
        input.value = parts.join(".");
    }
}

/* ── Totals ─────────────────────────────────────────────────── */
function calcItemTotal(row) {
    var qty       = parseFloat(row.querySelector(".item-qty").value) || 0;
    var unitPrice = parseAmount(row.querySelector("[name*=\'[unit_price]\']").value);
    var discount  = parseAmount(row.querySelector("[name*=\'[discount]\']").value);
    var rounding  = parseAmount(row.querySelector("[name*=\'[price_rounding]\']").value);
    var total     = (qty * unitPrice) - discount + rounding;
    row.querySelector(".item-total").textContent = CURRENCY_SYMBOL + " " + formatDisplay(total);
    return total;
}

function updateGrandTotal() {
    var subtotal = 0;
    document.querySelectorAll(".item-row").forEach(function(row) {
        subtotal += calcItemTotal(row);
    });
    var overallDiscount     = parseAmount(document.getElementById("overallDiscountInput").value);
    var overallPriceRounding = parseAmount(document.getElementById("overallPriceRoundingInput").value);
    var tax                 = parseAmount(document.getElementById("taxInput").value);
    var grandTotal          = subtotal - overallDiscount + overallPriceRounding + tax;

    document.getElementById("subtotalDisplay").textContent  = CURRENCY_SYMBOL + " " + formatDisplay(subtotal);
    document.getElementById("grandTotalDisplay").textContent = CURRENCY_SYMBOL + " " + formatDisplay(grandTotal);
}

/* ── Build item row HTML ────────────────────────────────────── */
function buildCategoryOptions(selectedId) {
    var html = \'<option value="">\' + LANG.select + \'</option>\';
    CATEGORIES.forEach(function(c) {
        html += \'<option value="\' + c.id + \'"\' + (c.id == selectedId ? " selected" : "") + \'>\' + c.name.replace(/</g,"&lt;").replace(/>/g,"&gt;") + \'</option>\';
    });
    return html;
}

function buildUnitOptions(selectedId) {
    var html = \'<option value="">\' + LANG.select + \'</option>\';
    UNITS.forEach(function(u) {
        html += \'<option value="\' + u.id + \'"\' + (u.id == selectedId ? " selected" : "") + \'>\' + u.name.replace(/</g,"&lt;") + \' (\' + u.symbol.replace(/</g,"&lt;") + \')</option>\';
    });
    return html;
}

function createItemRow(index, data) {
    data = data || {};
    var div = document.createElement("div");
    div.className = "item-row rounded p-3 mb-3";
    div.dataset.index = index;
    div.innerHTML = \'<div class="d-flex align-items-start gap-3">\' +
        \'<div class="item-number-badge mt-1 flex-shrink-0"><span class="item-number"></span></div>\' +
        \'<div class="flex-fill overflow-hidden"><div class="d-flex flex-nowrap align-items-end gap-2 w-100 overflow-x-auto pb-2 custom-scrollbar">\' +
            \'<div class="col-item-name">\' +
                \'<label class="form-label form-label-sm text-nowrap"><i class="bi bi-box me-1"></i>\' + LANG.item_name + \' <span class="text-danger">*</span></label>\' +
                \'<input type="text" class="form-control form-control-sm" name="items[\' + index + \'][item_name]" value="\' + esc(data.item_name || "") + \'" required>\' +
            \'</div>\' +
            \'<div class="col-item-field">\' +
                \'<label class="form-label form-label-sm text-nowrap"><i class="bi bi-tag me-1"></i>\' + LANG.category + \' <span class="text-danger">*</span></label>\' +
                \'<select class="form-select form-select-sm" name="items[\' + index + \'][category_id]" required>\' + buildCategoryOptions(data.category_id || 0) + \'</select>\' +
            \'</div>\' +
            \'<div class="col-item-field">\' +
                \'<label class="form-label form-label-sm text-nowrap"><i class="bi bi-rulers me-1"></i>\' + LANG.unit + \' <span class="text-danger">*</span></label>\' +
                \'<select class="form-select form-select-sm" name="items[\' + index + \'][unit_id]" required>\' + buildUnitOptions(data.unit_id || 0) + \'</select>\' +
            \'</div>\' +
            \'<div class="col-item-field">\' +
                \'<label class="form-label form-label-sm text-nowrap"><i class="bi bi-hash me-1"></i>\' + LANG.quantity + \' <span class="text-danger">*</span></label>\' +
                \'<input type="number" step="0.01" min="0.01" class="form-control form-control-sm item-qty" name="items[\' + index + \'][quantity]" value="\' + (data.quantity || "") + \'" required>\' +
            \'</div>\' +
            \'<div class="col-item-field">\' +
                \'<label class="form-label form-label-sm text-nowrap"><i class="bi bi-currency-dollar me-1"></i>\' + LANG.unit_price + \' <span class="text-danger">*</span></label>\' +
                \'<div class="input-group input-group-sm input-currency">\' +
                    \'<span class="input-group-text">\' + CURRENCY_SYMBOL + \'</span>\' +
                    \'<input type="text" class="form-control item-currency-display" name="items[\' + index + \'][unit_price]" value="\' + (data.unit_price ? formatDisplay(data.unit_price) : "") + \'" inputmode="numeric" placeholder="0" autocomplete="off">\' +
                \'</div>\' +
            \'</div>\' +
            \'<div class="col-item-field">\' +
                \'<label class="form-label form-label-sm text-nowrap"><i class="bi bi-percent me-1"></i>\' + LANG.discount + \'</label>\' +
                \'<div class="input-group input-group-sm input-currency">\' +
                    \'<span class="input-group-text">\' + CURRENCY_SYMBOL + \'</span>\' +
                    \'<input type="text" class="form-control item-currency-display" name="items[\' + index + \'][discount]" value="\' + formatDisplay(data.discount || 0) + \'" inputmode="numeric" placeholder="0" autocomplete="off">\' +
                \'</div>\' +
            \'</div>\' +
            \'<div class="col-item-field">\' +
                \'<label class="form-label form-label-sm text-nowrap"><i class="bi bi-calculator me-1"></i>\' + LANG.price_rounding + \'</label>\' +
                \'<div class="input-group input-group-sm input-currency">\' +
                    \'<span class="input-group-text">\' + CURRENCY_SYMBOL + \'</span>\' +
                    \'<input type="text" class="form-control item-currency-display" name="items[\' + index + \'][price_rounding]" value="\' + formatDisplay(data.price_rounding || 0) + \'" inputmode="numeric" placeholder="0" autocomplete="off">\' +
                \'</div>\' +
            \'</div>\' +
            \'<div class="col-item-field" style="padding-bottom: 2px;">\' +
                \'<label class="form-label form-label-sm text-nowrap"><i class="bi bi-cash-stack me-1"></i>\' + LANG.total_price + \'</label>\' +
                \'<div class="fw-bold text-success item-total fs-6">\' + CURRENCY_SYMBOL + \' 0</div>\' +
            \'</div>\' +
            \'<div class="col-item-action d-flex gap-1 align-items-end" style="padding-bottom: 2px;">\' +
                \'<button type="button" class="btn btn-success btn-sm btn-add-item" title="\' + LANG.add_item + \'"><i class="bi bi-plus-circle"></i></button>\' +
                \'<button type="button" class="btn btn-danger btn-sm btn-remove-item" title="\' + LANG.remove + \'"><i class="bi bi-trash"></i></button>\' +
            \'</div>\' +
        \'</div></div></div>\';
    return div;
}

function esc(str) {
    return String(str).replace(/&/g,"&amp;").replace(/"/g,"&quot;").replace(/</g,"&lt;").replace(/>/g,"&gt;");
}

/* ── Renumber ────────────────────────────────────────────────── */
function renumberItems() {
    document.querySelectorAll(".item-row .item-number").forEach(function(el, i) {
        el.textContent = i + 1;
    });
}

/* ── Add / Remove item (event delegation) ───────────────────── */
var itemCounter = 1;
document.getElementById("itemsContainer").addEventListener("click", function(e) {
    var addBtn = e.target.closest(".btn-add-item");
    if (addBtn) {
        itemCounter++;
        var newRow = createItemRow("new_" + itemCounter);
        // Insert after the row that contains the clicked button
        var currentRow = addBtn.closest(".item-row");
        if (currentRow && currentRow.nextSibling) {
            document.getElementById("itemsContainer").insertBefore(newRow, currentRow.nextSibling);
        } else {
            document.getElementById("itemsContainer").appendChild(newRow);
        }
        renumberItems();
        updateGrandTotal();
        return;
    }
    var btn = e.target.closest(".btn-remove-item");
    if (!btn) return;
    var rows = document.querySelectorAll(".item-row");
    if (rows.length <= 1) {
        Swal.fire({ icon: "warning", title: "", text: LANG.at_least_one_item, timer: 2500, showConfirmButton: false });
        return;
    }
    btn.closest(".item-row").remove();
    renumberItems();
    updateGrandTotal();
});

/* ── Currency input formatting ───────────────────────────────── */
document.addEventListener("input", function(e) {
    if (e.target.matches(".item-currency-display, .overall-currency-display")) {
        syncCurrencyInput(e.target);
        updateGrandTotal();
    }
    if (e.target.matches(".item-qty")) {
        updateGrandTotal();
    }
});

/* ── Document preview ────────────────────────────────────────── */
document.getElementById("documentInput").addEventListener("change", function() {
    var preview = document.getElementById("documentPreview");
    preview.innerHTML = "";
    if (!this.files || !this.files[0]) return;
    if (this.files[0].size > 5 * 1024 * 1024) {
        preview.innerHTML = \'<div class="alert alert-danger alert-sm py-1 mt-1">\' + LANG.file_size_error + \'</div>\';
        this.value = "";
        return;
    }
    var ext = this.files[0].name.split(".").pop().toLowerCase();
    if (["jpg","jpeg","png","gif"].indexOf(ext) !== -1) {
        var reader = new FileReader();
        reader.onload = function(ev) {
            var img = document.createElement("img");
            img.src = ev.target.result;
            preview.appendChild(img);
        };
        reader.readAsDataURL(this.files[0]);
    } else {
        preview.innerHTML = \'<span class="badge bg-secondary mt-1"><i class="bi bi-file-earmark-pdf me-1"></i>\' + esc(this.files[0].name) + \'</span>\';
    }
});

/* ── Form validation ─────────────────────────────────────────── */
document.getElementById("purchaseForm").addEventListener("submit", function(e) {
    var missing = [];
    this.querySelectorAll("select[required]").forEach(function(sel) {
        if (!sel.value) {
            sel.classList.add("is-invalid");
            var lbl = sel.closest(".mb-3, .col-md-3, .col-md-6, .col-12");
            if (lbl) {
                var l = lbl.querySelector("label");
                if (l) missing.push(l.textContent.replace(/\s*\*/g,"").trim());
            }
        } else {
            sel.classList.remove("is-invalid");
        }
    });
    this.querySelectorAll("input[required]").forEach(function(inp) {
        if (!inp.value || (inp.type === "number" && parseFloat(inp.value) <= 0)) {
            inp.classList.add("is-invalid");
        } else {
            inp.classList.remove("is-invalid");
        }
    });
    if (missing.length) {
        e.preventDefault();
        Swal.fire({ icon: "warning", title: LANG.incomplete_form, html: missing.join(", "), confirmButtonText: "OK" });
        window.scrollTo(0, 0);
        return false;
    }
});

/* ── Init ────────────────────────────────────────────────────── */
document.addEventListener("DOMContentLoaded", function() {
    renumberItems();
    updateGrandTotal();
});

})();
</script>
';
?>

<?php include '../includes/footer.php'; ?>