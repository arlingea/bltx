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
        if ($action === 'add' && $_can_create) {
            $name      = trim($_POST['name'] ?? '');
            $ptype     = $_POST['period_type'] ?? 'quarterly';
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate   = trim($_POST['end_date'] ?? '');
            $status    = $_POST['status'] ?? 'draft';
            $notes     = trim($_POST['notes'] ?? '');
            if ($name && $startDate && $endDate) {
                $st = $conn->prepare("INSERT INTO kpi_periods (name, period_type, start_date, end_date, status, notes) VALUES (?, ?, ?, ?, ?, ?)");
                $st->bind_param('ssssss', $name, $ptype, $startDate, $endDate, $status, $notes);
                $st->execute();
                $st->close();
                logActivity('kpi_period_create', "Added KPI period: $name");
                $flash = ['type' => 'success', 'msg' => $LANG['kpi_period_added']];
            }
        }
        elseif ($action === 'delete' && $_can_delete) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $st = $conn->prepare("DELETE FROM kpi_periods WHERE id = ?");
                $st->bind_param('i', $id);
                $st->execute();
                $st->close();
                logActivity('kpi_period_delete', "Deleted period ID: $id");
                $flash = ['type' => 'success', 'msg' => $LANG['kpi_period_deleted']];
            }
        }
    }
}

$page_title = $LANG['kpi_manage_periods'];
include __DIR__ . '/../includes/header.php';

$tablesExist = kpi_tablesExist($conn);
$periods     = $tablesExist ? kpi_getPeriods($conn) : [];
$csrf        = kpi_csrfToken();
?>
<div class="container-fluid">

    <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show"><?= htmlspecialchars($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <?php if (!$tablesExist): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($LANG['kpi_tables_missing_msg']) ?></div>
    <?php else: ?>

    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="bi bi-calendar-range"></i><?= htmlspecialchars($LANG['kpi_manage_periods']) ?></div>
            <div class="card-tools">
                <a href="<?= payrollGateUrl('kpi') ?>" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> <?= htmlspecialchars($LANG['back']) ?></a>
                <?php if ($_can_create): ?>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#periodModal"><i class="bi bi-plus-circle"></i> <?= htmlspecialchars($LANG['kpi_add_period']) ?></button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= htmlspecialchars($LANG['name']) ?></th>
                        <th><?= htmlspecialchars($LANG['type']) ?></th>
                        <th><?= htmlspecialchars($LANG['start_date']) ?></th>
                        <th><?= htmlspecialchars($LANG['end_date']) ?></th>
                        <th><?= htmlspecialchars($LANG['status']) ?></th>
                        <?php if ($_can_delete): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($periods as $p):
                        $statusBg = ['draft'=>'secondary','active'=>'success','closed'=>'dark'][$p['status']] ?? 'secondary';
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                        <td><?= htmlspecialchars($p['period_type']) ?></td>
                        <td><?= htmlspecialchars(date('d M Y', strtotime($p['start_date']))) ?></td>
                        <td><?= htmlspecialchars(date('d M Y', strtotime($p['end_date']))) ?></td>
                        <td><span class="badge bg-<?= $statusBg ?>"><?= htmlspecialchars(ucfirst($p['status'])) ?></span></td>
                        <?php if ($_can_delete): ?>
                        <td class="text-end">
                            <form method="POST" class="d-inline" onsubmit="return confirm('<?= addslashes($LANG['confirm_delete']) ?>');">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($periods)): ?>
                    <tr><td colspan="<?= $_can_delete ? 6 : 5 ?>" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i><?= htmlspecialchars($LANG['no_data']) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($_can_create): ?>
    <div class="modal fade" id="periodModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> <?= htmlspecialchars($LANG['kpi_add_period']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['name']) ?> <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required maxlength="100" placeholder="<?= htmlspecialchars($LANG['eg_kpi_period']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['type']) ?></label>
                            <select name="period_type" class="form-select">
                                <option value="monthly"><?= htmlspecialchars($LANG['monthly']) ?></option>
                                <option value="quarterly" selected><?= htmlspecialchars($LANG['quarterly']) ?></option>
                                <option value="semester"><?= htmlspecialchars($LANG['semester']) ?></option>
                                <option value="annual"><?= htmlspecialchars($LANG['annual']) ?></option>
                                <option value="custom"><?= htmlspecialchars($LANG['custom']) ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['status']) ?></label>
                            <select name="status" class="form-select">
                                <option value="draft"><?= htmlspecialchars($LANG['draft']) ?></option>
                                <option value="active"><?= htmlspecialchars($LANG['active']) ?></option>
                                <option value="closed"><?= htmlspecialchars($LANG['closed']) ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['start_date']) ?> <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= htmlspecialchars($LANG['end_date']) ?> <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= htmlspecialchars($LANG['notes']) ?></label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
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
