<?php
/**
 * inventory_view.php
 * File: inventory/inventory_view.php
 *
 * Strict Modal / AJAX mode only.
 * Direct standalone access is disabled and redirects to inventory list.
 * Permission: inventory → can_read / can_access
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';

// Strict check: Must be accessed via Modal / AJAX. Standalone access is blocked.
$is_modal = (
    (!empty($_GET['modal']) && $_GET['modal'] == '1') ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
     strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
);

if (!$is_modal) {
    redirect(url('inventory/inventory_gate.php?tab=inventory_data'));
    exit;
}

// Standard Permission Validation
if (!isLoggedIn()) {
    echo '<div class="alert alert-danger m-3"><i class="bi bi-lock me-2"></i>Session expired. Please login again.</div>';
    exit;
}

if (!checkPermission('inventory', 'can_read') && !checkPermission('inventory', 'can_access')) {
    echo '<div class="alert alert-danger m-3"><i class="bi bi-lock me-2"></i>Access denied. You do not have permission to view inventory.</div>';
    exit;
}

/* ===================== LOAD LANGUAGE ===================== */
if (!isset($LANG)) {
    $LANG = loadLanguage();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    echo '<div class="alert alert-warning m-3"><i class="bi bi-exclamation-triangle me-2"></i>' . ($LANG['invalid_id'] ?? 'Invalid inventory ID.') . '</div>';
    exit;
}

/* ===================== CURRENCY ===================== */
$currency_symbol  = getSetting('currency_symbol', 'Rp');
$current_currency = getSetting('currency', 'IDR');

/* ===================== INVENTORY DATA ===================== */
$stmt = $conn->prepare("
    SELECT
        i.id,
        i.product_code,
        i.item_name,
        i.brand,
        i.description,
        i.unit_price,
        i.quantity,
        i.min_stock,
        i.qty_per_unit,
        i.qty_per_unit_unit_id,
        i.item_image,
        i.created_at,
        i.updated_at,
        c.name          AS category_name,
        s.name          AS supplier_name,
        i.created_by_name,
        un.name         AS unit_name,
        un.symbol       AS unit_symbol,
        uqpu.name       AS qpu_unit_name,
        uqpu.symbol     AS qpu_unit_symbol,
        wl.location_code,
        wl.location_name,
        (i.quantity * i.unit_price) AS total_value
    FROM inventory i
    LEFT JOIN categories         c    ON i.category_id        = c.id
    LEFT JOIN suppliers          s    ON i.supplier_id        = s.id
    LEFT JOIN units              un   ON i.unit_id            = un.id
    LEFT JOIN warehouse_locations wl  ON i.location_id        = wl.id
    LEFT JOIN units              uqpu ON i.qty_per_unit_unit_id = uqpu.id
    WHERE i.id = ?
    LIMIT 1
");

if (!$stmt) {
    echo '<div class="alert alert-danger m-3">Database error.</div>';
    exit;
}

$stmt->bind_param("i", $id);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) {
    echo '<div class="alert alert-warning m-3"><i class="bi bi-exclamation-triangle me-2"></i>' . ($LANG['no_data'] ?? 'Data not found.') . '</div>';
    exit;
}

/* ===================== STATISTICS QUERIES ===================== */
$cnt_in = $conn->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(quantity),0) AS s FROM inventory_in WHERE inventory_id = ?");
$cnt_in->bind_param("i", $id);
$cnt_in->execute();
$r_in = $cnt_in->get_result()->fetch_assoc();
$cnt_in->close();

$cnt_out = $conn->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(quantity),0) AS s FROM inventory_out WHERE inventory_id = ?");
$cnt_out->bind_param("i", $id);
$cnt_out->execute();
$r_out = $cnt_out->get_result()->fetch_assoc();
$cnt_out->close();

/* ===================== INVENTORY IN HISTORY ===================== */
$stmt_in = $conn->prepare("
    SELECT
        ii.transaction_date,
        ii.quantity,
        ii.reference_number,
        ii.notes,
        ii.created_at,
        ii.created_by_name,
        un.symbol    AS unit_symbol,
        un.name      AS unit_name
    FROM inventory_in ii
    LEFT JOIN units un ON ii.unit_id    = un.id
    WHERE ii.inventory_id = ?
    ORDER BY ii.transaction_date DESC, ii.created_at DESC
    LIMIT 20
");
$stmt_in->bind_param("i", $id);
$stmt_in->execute();
$history_in  = $stmt_in->get_result();
$stmt_in->close();

/* ===================== INVENTORY OUT HISTORY ===================== */
$stmt_out = $conn->prepare("
    SELECT
        io.transaction_date,
        io.quantity,
        io.reference_number,
        io.notes,
        io.created_at,
        io.taken_by_name,
        io.created_by_name,
        un.symbol    AS unit_symbol,
        un.name      AS unit_name
    FROM inventory_out io
    LEFT JOIN units un ON io.unit_id    = un.id
    WHERE io.inventory_id = ?
    ORDER BY io.transaction_date DESC, io.created_at DESC
    LIMIT 20
");
$stmt_out->bind_param("i", $id);
$stmt_out->execute();
$history_out = $stmt_out->get_result();
$stmt_out->close();

/* ===================== HELPERS ===================== */
if (!function_exists('_fmtQty')) {
    function _fmtQty($qty) {
        $f   = (float)$qty;
        $str = number_format($f, 2, '.', '');
        $str = rtrim($str, '0');
        $str = rtrim($str, '.');
        return $str ?: '0';
    }
}

if (!function_exists('_fmtCurrency')) {
    function _fmtCurrency($amount, $symbol) {
        $formatted = number_format((float)$amount, 2, ',', '.');
        $formatted = preg_replace('/,0+$/', '', $formatted);
        $formatted = preg_replace('/,(\d)0$/', ',$1', $formatted);
        return $symbol . ' ' . $formatted;
    }
}

/* ===================== BUILD ITEM NAME WITH QTY_PER_UNIT ===================== */
$item_display_name = htmlspecialchars($item['item_name'] ?? '', ENT_QUOTES, 'UTF-8');
if (!empty($item['qty_per_unit']) && (float)$item['qty_per_unit'] > 0) {
    $qpu     = _fmtQty($item['qty_per_unit']);
    $qpu_sym = htmlspecialchars($item['qpu_unit_symbol'] ?? $item['qpu_unit_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $item_display_name .= ' <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 ms-1 py-0 px-1 fw-normal" style="font-size: 0.75rem;">' . $qpu . ($qpu_sym ? ' ' . $qpu_sym : '') . '</span>';
}

/* ===================== STOCK STATUS ===================== */
$stock_class = 'text-success fw-bold';
$stock_badge = '';
if ((float)$item['quantity'] <= 0) {
    $stock_class = 'text-danger fw-bold';
    $stock_badge = '<span class="badge bg-danger text-white ms-1 py-0 px-1" style="font-size:0.7rem;"><i class="bi bi-x-circle me-1"></i>' . ($LANG['out_of_stock'] ?? 'Out of Stock') . '</span>';
} elseif ((float)$item['quantity'] <= (float)$item['min_stock']) {
    $stock_class = 'text-danger fw-bold';
    $stock_badge = '<span class="badge bg-danger text-white ms-1 py-0 px-1" style="font-size:0.7rem;"><i class="bi bi-exclamation-triangle me-1"></i>' . ($LANG['low_stock'] ?? 'Low Stock') . '</span>';
} elseif ((float)$item['quantity'] <= ((float)$item['min_stock'] * 2)) {
    $stock_class = 'text-warning text-dark fw-bold';
    $stock_badge = '<span class="badge bg-warning text-dark ms-1 py-0 px-1" style="font-size:0.7rem;"><i class="bi bi-exclamation-circle me-1"></i>' . ($LANG['warning'] ?? 'Warning') . '</span>';
}
?>

<div class="p-1">

    <style>
    .inv-view-table th {
        width: 32%;
        font-weight: 600;
        color: #495057;
        background: #f8f9fa;
        border-right: 1px solid #dee2e6;
        vertical-align: middle;
        padding: 0.3rem 0.5rem;
        font-size: 0.825rem;
        line-height: 1.25;
    }
    .inv-view-table td {
        vertical-align: middle;
        padding: 0.3rem 0.5rem;
        word-break: break-word;
        font-size: 0.825rem;
        line-height: 1.25;
    }
    .inv-history-table th { 
        font-size: 0.775rem; 
        white-space: nowrap; 
        background-color: #f8f9fa;
        padding: 0.35rem 0.5rem;
    }
    .inv-history-table td { 
        font-size: 0.775rem; 
        padding: 0.35rem 0.5rem;
    }
    .item-img-container {
        background: #f8f9fa;
        border: 1px dashed #dee2e6;
        border-radius: 6px;
        min-height: 150px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .item-img-thumb {
        max-height: 160px;
        max-width: 100%;
        border-radius: 4px;
        object-fit: contain;
        cursor: zoom-in;
        transition: transform 0.2s ease-in-out;
    }
    .item-img-thumb:hover { transform: scale(1.03); }
    .table-scroll-container {
        max-height: 220px;
        overflow-y: auto;
    }

    /* CSS-Only Lightbox (100% Reliable, zero JS dependency for AJAX) */
    .inv-lightbox-toggle {
        display: none !important;
    }
    .inv-lightbox-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0, 0, 0, 0.85);
        z-index: 999999;
        align-items: center;
        justify-content: center;
        padding: 20px;
        cursor: pointer;
    }
    .inv-lightbox-toggle:checked ~ .inv-lightbox-overlay {
        display: flex !important;
    }
    .inv-lightbox-content {
        max-width: 90%;
        max-height: 90vh;
        text-align: center;
        cursor: default;
    }
    .inv-lightbox-content img {
        max-height: 80vh;
        max-width: 100%;
        object-fit: contain;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.5);
    }
    </style>

    <div class="row g-2">
        <!-- Left column: main details -->
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header">
                    <div class="card-title">
                        <i class="bi bi-info-circle-fill text-primary me-2"></i>
                        <span class="fw-semibold text-dark small"><?php echo $LANG['stock_details'] ?? 'Stock Details'; ?></span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-bordered mb-0 inv-view-table">
                        <tbody>
                            <tr>
                                <th><?php echo $LANG['product_code'] ?? 'Product Code'; ?></th>
                                <td><span class="badge bg-light text-dark border font-monospace py-0.5 px-1.5" style="font-size: 0.8rem;"><?php echo htmlspecialchars($item['product_code'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span></td>
                            </tr>
                            <tr>
                                <th><?php echo $LANG['item_name'] ?? 'Item Name'; ?></th>
                                <td><strong><?php echo $item_display_name; ?></strong></td>
                            </tr>
                            <?php if (!empty($item['brand'])): ?>
                            <tr>
                                <th><?php echo $LANG['brand'] ?? 'Brand'; ?></th>
                                <td><?php echo htmlspecialchars($item['brand'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th><?php echo $LANG['category'] ?? 'Category'; ?></th>
                                <td><?php echo htmlspecialchars($item['category_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <tr>
                                <th><?php echo $LANG['supplier'] ?? 'Supplier'; ?></th>
                                <td><?php echo htmlspecialchars($item['supplier_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <tr>
                                <th><?php echo $LANG['location'] ?? 'Location'; ?></th>
                                <td>
                                    <?php if (!empty($item['location_code'])): ?>
                                        <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 me-1 py-0 px-1" style="font-size: 0.75rem;"><?php echo htmlspecialchars($item['location_code'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="text-muted small"><?php echo htmlspecialchars($item['location_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo $LANG['unit'] ?? 'Unit'; ?></th>
                                <td>
                                    <?php
                                    $unit_str = htmlspecialchars($item['unit_name'] ?? '-', ENT_QUOTES, 'UTF-8');
                                    if (!empty($item['unit_symbol'])) {
                                        $unit_str .= ' <span class="text-muted">(' . htmlspecialchars($item['unit_symbol'], ENT_QUOTES, 'UTF-8') . ')</span>';
                                    }
                                    echo $unit_str;
                                    ?>
                                </td>
                            </tr>
                            <?php if (!empty($item['qty_per_unit']) && (float)$item['qty_per_unit'] > 0): ?>
                            <tr>
                                <th><?php echo $LANG['qty_per_unit'] ?? 'Qty per Unit'; ?></th>
                                <td>
                                    <strong><?php echo _fmtQty($item['qty_per_unit']); ?></strong>
                                    <?php
                                    $qpu_sym_display = $item['qpu_unit_symbol'] ?? $item['qpu_unit_name'] ?? '';
                                    if (!empty($qpu_sym_display)): ?>
                                        <span class="text-muted ms-1"><?php echo htmlspecialchars($qpu_sym_display, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th><?php echo $LANG['stock'] ?? 'Current Stock'; ?></th>
                                <td>
                                    <span class="<?php echo $stock_class; ?>">
                                        <?php echo _fmtQty($item['quantity']); ?>
                                        <?php if (!empty($item['unit_symbol'])): ?>
                                            <span class="text-muted fw-normal ms-0.5"><?php echo htmlspecialchars($item['unit_symbol'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <?php echo $stock_badge; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo $LANG['min_stock'] ?? 'Minimum Stock'; ?></th>
                                <td>
                                    <?php echo _fmtQty($item['min_stock']); ?>
                                    <?php if (!empty($item['unit_symbol'])): ?>
                                        <span class="text-muted ms-0.5"><?php echo htmlspecialchars($item['unit_symbol'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo $LANG['unit_price'] ?? 'Unit Price'; ?></th>
                                <td><?php echo _fmtCurrency($item['unit_price'], $currency_symbol); ?></td>
                            </tr>
                            <tr>
                                <th><?php echo $LANG['total_value'] ?? 'Total Value'; ?></th>
                                <td><strong class="text-primary"><?php echo _fmtCurrency($item['total_value'], $currency_symbol); ?></strong></td>
                            </tr>
                            <?php if (!empty($item['description'])): ?>
                            <tr>
                                <th><?php echo $LANG['description'] ?? 'Description'; ?></th>
                                <td><?php echo nl2br(htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8')); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th><?php echo $LANG['created_by'] ?? 'Created By'; ?></th>
                                <td><?php echo htmlspecialchars($item['created_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <tr>
                                <th><?php echo $LANG['created_at'] ?? 'Created At'; ?></th>
                                <td><?php echo date('d/m/Y H:i', strtotime($item['created_at'])); ?></td>
                            </tr>
                            <?php if (!empty($item['updated_at']) && $item['updated_at'] !== $item['created_at']): ?>
                            <tr>
                                <th><?php echo $LANG['updated_at'] ?? 'Updated At'; ?></th>
                                <td><?php echo date('d/m/Y H:i', strtotime($item['updated_at'])); ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right column: image + stats -->
        <div class="col-lg-4 d-flex flex-column gap-2">
            <!-- Item Image with CSS-Only Lightbox -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="bi bi-image-fill text-secondary me-2"></i>
                        <span class="fw-semibold text-dark small"><?php echo $LANG['item_image'] ?? 'Item Image'; ?></span>
                    </div>
                </div>
                <div class="card-body p-2 text-center">
                    <div class="item-img-container p-1">
                        <?php if (!empty($item['item_image'])): ?>
                            <!-- CSS-Only Lightbox Structure -->
                            <input type="checkbox" id="invImgZoom" class="inv-lightbox-toggle">
                            
                            <label for="invImgZoom" class="m-0 d-inline-block" style="cursor: zoom-in;" title="<?= htmlspecialchars($LANG['click_to_enlarge']) ?>">
                                <img src="<?php echo UPLOAD_URL . htmlspecialchars(ltrim($item['item_image'],'/'), ENT_QUOTES, 'UTF-8'); ?>"
                                     class="item-img-thumb"
                                     alt="<?php echo htmlspecialchars($item['item_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </label>

                            <div class="inv-lightbox-overlay" onclick="document.getElementById('invImgZoom').checked = false;">
                                <div class="inv-lightbox-content">
                                    <img src="<?php echo UPLOAD_URL . htmlspecialchars(ltrim($item['item_image'],'/'), ENT_QUOTES, 'UTF-8'); ?>" alt="Enlarged Image">
                                    <div class="mt-3">
                                        <label for="invImgZoom" class="btn btn-light btn-sm rounded-pill px-3 py-1 fw-semibold shadow" style="cursor: pointer;">
                                            <i class="bi bi-x-circle me-1"></i> <?php echo $LANG['close'] ?? 'Close'; ?>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="py-3 text-muted">
                                <i class="bi bi-image text-secondary" style="font-size: 2rem; opacity: .3;"></i>
                                <p class="small mt-1 mb-0"><?php echo $LANG['no_image'] ?? 'No image available'; ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="bi bi-bar-chart-line-fill text-success me-2"></i>
                        <span class="fw-semibold text-dark small"><?php echo $LANG['statistics'] ?? 'Statistics Summary'; ?></span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-bordered mb-0 inv-view-table">
                        <tbody>
                            <tr>
                                <th><?php echo $LANG['kpi_stock_in'] ?? 'Stock In'; ?></th>
                                <td>
                                    <span class="text-success fw-bold"><?php echo (int)$r_in['c']; ?> <?php echo $LANG['entries'] ?? 'entries'; ?></span>
                                    <div class="text-muted small" style="font-size:0.75rem;"><?php echo _fmtQty($r_in['s']); ?> <?php echo htmlspecialchars($item['unit_symbol'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                            </tr>
                            <tr>
                                <th><?php echo $LANG['kpi_stock_out'] ?? 'Stock Out'; ?></th>
                                <td>
                                    <span class="text-danger fw-bold"><?php echo (int)$r_out['c']; ?> <?php echo $LANG['entries'] ?? 'entries'; ?></span>
                                    <div class="text-muted small" style="font-size:0.75rem;"><?php echo _fmtQty($r_out['s']); ?> <?php echo htmlspecialchars($item['unit_symbol'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock In & Out Histories -->
    <div class="row g-2 mt-1">
        <!-- Stock In History -->
        <div class="col-12">
            <div class="card card-success card-outline">
                <div class="card-header">
                    <div class="card-title">
                        <i class="bi bi-arrow-down-circle-fill text-success me-2 fs-6"></i>
                        <span class="fw-bold text-success small"><?php echo $LANG['in_history'] ?? 'Stock In History'; ?></span>
                    </div>
                    <span class="badge bg-success text-white rounded-pill py-0.5 px-1.5" style="font-size:0.7rem;"><?php echo (int)$r_in['c']; ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if ($history_in && $history_in->num_rows > 0): ?>
                    <div class="table-responsive table-scroll-container">
                        <table class="table table-sm table-striped table-hover align-middle mb-0 inv-history-table">
                            <thead>
                                <tr>
                                    <th><?php echo $LANG['transaction_date'] ?? 'Date'; ?></th>
                                    <th><?php echo $LANG['quantity'] ?? 'Quantity'; ?></th>
                                    <th><?php echo $LANG['reference'] ?? 'Reference'; ?></th>
                                    <th><?php echo $LANG['notes'] ?? 'Notes'; ?></th>
                                    <th><?php echo $LANG['created_by'] ?? 'Created By'; ?></th>
                                    <th><?php echo $LANG['created_at'] ?? 'Created At'; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($in_row = $history_in->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($in_row['transaction_date'])); ?></td>
                                    <td><strong class="text-success">+<?php echo _fmtQty($in_row['quantity']); ?> <?php echo htmlspecialchars($in_row['unit_symbol'] ?? $in_row['unit_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td><small class="text-muted font-monospace"><?php echo htmlspecialchars($in_row['reference_number'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></small></td>
                                    <td><?php echo htmlspecialchars($in_row['notes'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($in_row['created_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($in_row['created_at'])); ?></small></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <p class="text-muted small p-2.5 mb-0 text-center" style="font-size:0.8rem;"><i class="bi bi-info-circle me-1"></i><?php echo $LANG['no_transactions'] ?? 'No stock in transactions recorded.'; ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Stock Out History -->
        <div class="col-12">
            <div class="card card-danger card-outline">
                <div class="card-header">
                    <div class="card-title">
                        <i class="bi bi-arrow-up-circle-fill text-danger me-2 fs-6"></i>
                        <span class="fw-bold text-danger small"><?php echo $LANG['out_history'] ?? 'Stock Out History'; ?></span>
                    </div>
                    <span class="badge bg-danger text-white rounded-pill py-0.5 px-1.5" style="font-size:0.7rem;"><?php echo (int)$r_out['c']; ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if ($history_out && $history_out->num_rows > 0): ?>
                    <div class="table-responsive table-scroll-container">
                        <table class="table table-sm table-striped table-hover align-middle mb-0 inv-history-table">
                            <thead>
                                <tr>
                                    <th><?php echo $LANG['transaction_date'] ?? 'Date'; ?></th>
                                    <th><?php echo $LANG['quantity'] ?? 'Quantity'; ?></th>
                                    <th><?php echo $LANG['reference'] ?? 'Reference'; ?></th>
                                    <th><?php echo $LANG['taken_by'] ?? 'Taken By'; ?></th>
                                    <th><?php echo $LANG['notes'] ?? 'Notes'; ?></th>
                                    <th><?php echo $LANG['created_at'] ?? 'Created At'; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($out_row = $history_out->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($out_row['transaction_date'])); ?></td>
                                    <td><strong class="text-danger">-<?php echo _fmtQty($out_row['quantity']); ?> <?php echo htmlspecialchars($out_row['unit_symbol'] ?? $out_row['unit_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td><small class="text-muted font-monospace"><?php echo htmlspecialchars($out_row['reference_number'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></small></td>
                                    <td><?php echo htmlspecialchars($out_row['taken_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($out_row['notes'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($out_row['created_at'])); ?></small></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <p class="text-muted small p-2.5 mb-0 text-center" style="font-size:0.8rem;"><i class="bi bi-info-circle me-1"></i><?php echo $LANG['no_transactions'] ?? 'No stock out transactions recorded.'; ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>