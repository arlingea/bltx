<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/kpi_functions.php';

if (!isLoggedIn()) { redirect(url('index.php')); exit; }
if (!_canAccess('kpi', 'can_access')) { redirectNoAccess(); exit; }

$_can_create = _canAccess('kpi', 'can_create');
$_can_update = _canAccess('kpi', 'can_update');
$_can_delete = _canAccess('kpi', 'can_delete');

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && kpi_tablesExist($conn)) {
    if (!kpi_csrfVerify($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'msg' => $LANG['invalid_csrf']];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_indicator' && $_can_create) {
            $catId   = (int)($_POST['category_id'] ?? 0);
            $name    = trim($_POST['name'] ?? '');
            $weight  = (float)($_POST['weight'] ?? 10);
            $stype   = $_POST['scoring_type'] ?? 'rating';
            $target  = $_POST['target_value'] !== '' ? (float)$_POST['target_value'] : null;
            $unit    = trim($_POST['target_unit'] ?? '');
            $desc    = trim($_POST['description'] ?? '');
            if ($catId > 0 && $name !== '') {
                $st = $conn->prepare("INSERT INTO kpi_indicators (category_id, name, description, weight, target_value, target_unit, scoring_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $st->bind_param('issddss', $catId, $name, $desc, $weight, $target, $unit, $stype);
                $st->execute();
                $st->close();
                logActivity('kpi_indicator_create', "Added indicator: $name");
                $flash = ['type' => 'success', 'msg' => $LANG['kpi_indicator_added']];
            }
        }
        elseif ($action === 'delete_indicator' && $_can_delete) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $st = $conn->prepare("DELETE FROM kpi_indicators WHERE id = ?");
                $st->bind_param('i', $id);
                $st->execute();
                $st->close();
                logActivity('kpi_indicator_delete', "Deleted indicator ID: $id");
                $flash = ['type' => 'success', 'msg' => $LANG['kpi_indicator_deleted']];
            }
        }
        elseif ($action === 'add_category' && $_can_create) {
            $name   = trim($_POST['cat_name'] ?? '');
            $weight = (float)($_POST['cat_weight'] ?? 25);
            $desc   = trim($_POST['cat_description'] ?? '');
            $order  = (int)($_POST['cat_order'] ?? 0);
            if ($name !== '') {
                $st = $conn->prepare("INSERT INTO kpi_categories (name, description, weight, display_order) VALUES (?, ?, ?, ?)");
                $st->bind_param('ssdi', $name, $desc, $weight, $order);
                $st->execute();
                $st->close();
                logActivity('kpi_category_create', "Added category: $name");
                $flash = ['type' => 'success', 'msg' => $LANG['kpi_category_added']];
            }
        }
    }
}

$page_title = $LANG['kpi_manage_indicators'];
include __DIR__ . '/../includes/header.php';

$tablesExist = kpi_tablesExist($conn);
$indicators  = $tablesExist ? kpi_getIndicators($conn, false) : [];
$categories  = $tablesExist ? kpi_getCategories($conn, false) : [];
$csrf        = kpi_csrfToken();
?>
<div class="container-fluid">
    <div class="page-title-bar">
        <div>
            <h2 class="page-title-text"><i class="bi bi-list-check"></i><?= htmlspecialchars($LANG['kpi_manage_indicators']) ?></h2>
            <div class="page-subtitle"><?= htmlspecialchars($LANG['kpi_indicators_subtitle']) ?></div>
        </div>
        <div class="page-actions">
            <a href="<?= payrollGateUrl('kpi') ?>" class="btn btn-sm btn-secondary">
                <i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back']) ?>
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show">
        <?= htmlspecialchars($flash['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (!$tablesExist): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?= htmlspecialchars($LANG['kpi_tables_missing_msg']) ?>
    </div>
    <?php else: ?>

    <div class="row g-3">
        <!-- Categories -->
        <div class="col-md-5">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="bi bi-collection"></i> <?= htmlspecialchars($LANG['kpi_categories']) ?></div>
                    <?php if ($_can_create): ?>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal">
                        <i class="bi bi-plus-circle"></i> <?= htmlspecialchars($LANG['add']) ?>
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?= htmlspecialchars($LANG['name']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['weight']) ?></th>
                                <th class="text-center"><?= htmlspecialchars($LANG['order']) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $c): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($c['name']) ?></div>
                                    <?php if ($c['description']): ?>
                                        <small class="text-muted"><?= htmlspecialchars($c['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= number_format((float)$c['weight'], 2) ?>%</td>
                                <td class="text-center"><?= (int)$c['display_order'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($categories)): ?>
                            <tr><td colspan="3" class="text-center py-4 text-muted"><?= htmlspecialchars($LANG['no_data']) ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Indicators -->
        <div class="col-md-7">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="bi bi-bullseye"></i> <?= htmlspecialchars($LANG['kpi_indicators']) ?></div>
                    <?php if ($_can_create): ?>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#indicatorModal">
                        <i class="bi bi-plus-circle"></i> <?= htmlspecialchars($LANG['add']) ?>
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?= htmlspecialchars($LANG['category']) ?></th>
                                <th><?= htmlspecialchars($LANG['name']) ?></th>
                                <th class="text-end"><?= htmlspecialchars($LANG['weight']) ?></th>
                                <th class="text-center"><?= htmlspecialchars($LANG['type']) ?></th>
                                <?php if ($_can_delete): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($indicators as $ind): ?>
                            <tr>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($ind['category_name']) ?></span></td>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($ind['name']) ?></div>
                                    <?php if ($ind['description']): ?>
                                        <small class="text-muted"><?= htmlspecialchars($ind['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= number_format((float)$ind['weight'], 2) ?>%</td>
                                <td class="text-center"><small class="text-muted"><?= htmlspecialchars($ind['scoring_type']) ?></small></td>
                                <?php if ($_can_delete): ?>
                                <td>
                                    <form method="POST" class="d-inline" onsubmit="return confirm(<?php echo bltx_js($LANG['confirm_delete']) ?>);">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="delete_indicator">
                                        <input type="hidden" name="id" value="<?= (int)$ind['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($indicators)): ?>
                            <tr><td colspan="<?= $_can_delete ? 5 : 4 ?>" class="text-center py-4 text-muted"><?= htmlspecialchars($LANG['no_data']) ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Indicator Modal -->
    <?php if ($_can_create): ?>
    <div class="modal fade" id="indicatorModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="add_indicator">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> <?= htmlspecialchars($LANG['kpi_add_indicator']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['category']) ?> <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-select" required>
                                <option value=""><?= htmlspecialchars($LANG['select']) ?></option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['name']) ?> <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required maxlength="150">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['description']) ?></label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= htmlspecialchars($LANG['weight']) ?> <span class="text-danger">*</span></label>
                            <input type="number" name="weight" class="form-control" min="0" max="100" step="0.01" value="10" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= htmlspecialchars($LANG['kpi_scoring_type']) ?></label>
                            <select name="scoring_type" class="form-select">
                                <option value="rating"><?= htmlspecialchars($LANG['rating_1_5']) ?></option>
                                <option value="percentage">Percentage (0-100%)</option>
                                <option value="numeric"><?= htmlspecialchars($LANG['numeric_vs_target']) ?></option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= htmlspecialchars($LANG['kpi_target']) ?></label>
                            <input type="number" name="target_value" class="form-control" step="0.01">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['kpi_target_unit']) ?></label>
                            <input type="text" name="target_unit" class="form-control" maxlength="20" placeholder="<?= htmlspecialchars($LANG['eg_kpi_unit']) ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?= htmlspecialchars($LANG['cancel']) ?></button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> <?= htmlspecialchars($LANG['save']) ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Category Modal -->
    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="add_category">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> <?= htmlspecialchars($LANG['kpi_add_category']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars($LANG['name']) ?> <span class="text-danger">*</span></label>
                        <input type="text" name="cat_name" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars($LANG['description']) ?></label>
                        <textarea name="cat_description" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['weight']) ?></label>
                            <input type="number" name="cat_weight" class="form-control" min="0" max="100" step="0.01" value="25">
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['order']) ?></label>
                            <input type="number" name="cat_order" class="form-control" min="0" value="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?= htmlspecialchars($LANG['cancel']) ?></button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> <?= htmlspecialchars($LANG['save']) ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
