<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "cash_advances" — bagian dari payroll/payroll_gate.php

   Berkas ini TIDAK lagi berdiri sendiri. Ia di-include oleh hub
   payroll_gate.php, yang sudah lebih dulu:
     - memeriksa login,
     - memeriksa gerbang induk 'payroll' dan izin modul tab ini,
     - mencetak includes/header.php,
     - dan akan mencetak includes/footer.php setelah fragmen ini selesai.

   Karena itu berkas ini TIDAK BOLEH lagi meng-include header.php maupun
   footer.php — kalau ikut mencetak, sidebar dan navbar akan tampil dua kali.

   URL LAMA TETAP HIDUP: bila berkas ini dibuka langsung (PAYROLL_GATE
   belum terdefinisi), permintaan dialihkan ke tab yang sesuai di hub,
   sehingga bookmark dan tautan lama tidak ada yang mati.

   Seluruh handler POST di bawah tetap berada di posisi semula dan tetap
   berfungsi: includes/config.php mengaktifkan ob_start() di baris 2 dan
   footer.php baru menutupnya dengan ob_end_flush(), sehingga redirect()
   tetap sah walaupun header sudah tercetak lebih dulu.
   ═══════════════════════════════════════════════════════════════════════ */
if (!defined('PAYROLL_GATE')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';

    /* PERMINTAAN POST TETAP DILAYANI DI SINI — JANGAN dialihkan.
       Seluruh form dan pemanggilan AJAX di berkas ini menembak alamatnya
       sendiri. Kalau POST ikut dialihkan ke hub, AJAX akan menerima HTML
       alih-alih JSON dan setiap tombol simpan/hapus akan diam saja.
       Handler POST di bawah berakhir dengan echo JSON + exit atau dengan
       redirect(), jadi tidak pernah sampai ke bagian HTML.

       PERMINTAAN GET dialihkan ke tab yang sesuai di hub, sehingga bookmark
       dan tautan lama tetap hidup dan tidak ada halaman kembar. */
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        payrollRedirectLegacy('cash_advances');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
// File: payroll/cash_advances.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

// Permission check — superadmin full control, others via permission system
if (!checkPermission('cash_advances', 'can_access')) {
    redirectNoAccess();
}

$page_title = $LANG['cash_advances'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ===============================
   AJAX: SAVE (add / edit)
=============================== */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save') {
    ob_clean();
    header('Content-Type: application/json');

    $canDo = checkPermission('cash_advances', isset($_POST['id']) && (int)$_POST['id'] > 0 ? 'can_update' : 'can_create');
    if (!$canDo) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
    }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => $LANG['invalid_csrf']]); exit;
    }

    $result = cashAdvanceSave($conn, $_POST, $LANG);
    echo json_encode($result);
    exit;
}

/* ===============================
   AJAX: APPROVE
=============================== */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'approve') {
    ob_clean();
    header('Content-Type: application/json');

    if (!checkPermission('cash_advances', 'can_update')) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
    }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => $LANG['invalid_csrf']]); exit;
    }

    $id     = (int)($_POST['id'] ?? 0);
    $userId = (int)$_SESSION['user_id'];
    $result = cashAdvanceApprove($conn, $id, $userId, $LANG);
    echo json_encode($result);
    exit;
}

/* ===============================
   AJAX: DELETE
=============================== */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete') {
    ob_clean();
    header('Content-Type: application/json');

    if (!checkPermission('cash_advances', 'can_delete')) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
    }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => $LANG['invalid_csrf']]); exit;
    }

    $id     = (int)($_POST['id'] ?? 0);
    $result = cashAdvanceDelete($conn, $id, $LANG);
    echo json_encode($result);
    exit;
}

/* ===============================
   QUERY DATA — server-side ready
=============================== */
$filterEmp    = (int)($_GET['employee_id'] ?? 0);
$filterStatus = $_GET['status']            ?? '';
$search       = trim($_GET['search']       ?? '');
$perPage      = max(5, (int)(getSetting('pagination', 10)));
$currentPage  = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($currentPage - 1) * $perPage;

$result   = cashAdvanceGetList($conn, $filterEmp, $filterStatus, $search, $perPage, $offset);
$advances = $result['data'];
$total    = $result['total'];
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

$employees = getActiveEmployees($conn);

/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('cash_advances'); }
?>

<link rel="stylesheet" href="<?= url('assets/vendor/datatables/dataTables.bootstrap5.min.css') ?>">
<!-- PEMUATAN ULANG ASET DIHAPUS (poin 9).
     Baris <script src> di sini memuat lagi berkas yang SUDAH dimuat
     includes/footer.php. jQuery yang dimuat dua kali mengganti instans
     pertama beserta seluruh plugin yang terdaftar padanya — DataTables
     dan komponen Bootstrap bisa berhenti bekerja di halaman ini tanpa
     satu pun pesan error. Pola yang sama sudah ditutup di settings/users.php,
     settings/news.php, dan inventory/inventory_in.php. -->

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="bi bi-cash-coin"></i><?= $LANG['cash_advances'] ?></div>
        <div class="card-tools">
            <a href="<?= payrollGateUrl('dashboard') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-arrow-left"></i><?= $LANG['back'] ?>
            </a>
            <?php if (checkPermission('cash_advances', 'can_create')): ?>
            <button type="button" class="btn btn-primary btn-sm" id="btnAdd">
                <i class="bi bi-plus-circle"></i><?= $LANG['add_cash_advance'] ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card-body border-bottom pb-3">
        <form method="GET" class="row g-2 align-items-end" id="filterForm">
            <div class="col-sm-4 col-md-3">
                <label class="form-label small fw-semibold mb-1"><?= $LANG['employee'] ?></label>
                <select name="employee_id" class="form-select form-select-sm">
                    <option value=""><?= $LANG['all'] ?></option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $filterEmp == $e['id'] ? 'selected' : '' ?>>
                        <?= safeOutput($e['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-4 col-md-2">
                <label class="form-label small fw-semibold mb-1"><?= $LANG['status'] ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value=""><?= $LANG['all'] ?></option>
                    <option value="pending"  <?= $filterStatus === 'pending'  ? 'selected' : '' ?>><?= $LANG['pending'] ?></option>
                    <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>><?= $LANG['approved'] ?></option>
                    <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>><?= $LANG['rejected'] ?></option>
                    <option value="paid_off" <?= $filterStatus === 'paid_off' ? 'selected' : '' ?>><?= $LANG['paid_off'] ?></option>
                </select>
            </div>
            <div class="col-sm-4 col-md-3">
                <label class="form-label small fw-semibold mb-1"><?= $LANG['search'] ?></label>
                <input type="text" name="search" class="form-control form-control-sm"
                       value="<?= safeOutput($search) ?>"
                       placeholder="<?= $LANG['search'] ?>...">
            </div>
            <div class="col-sm-4 col-md-2">
                <label class="form-label small fw-semibold mb-1"><?= $LANG['show'] ?></label>
                <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ([5, 10, 25, 50, 100] as $n): ?>
                    <option value="<?= $n ?>" <?= $perPage == $n ? 'selected' : '' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-8 col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                    <i class="bi bi-funnel me-1"></i><?= $LANG['filter'] ?>
                </button>
                <a href="<?= payrollGateUrl('cash_advances') ?>" class="btn btn-secondary btn-sm" title="<?= $LANG['reset'] ?>">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0" id="caTable">
                <thead class="table-light">
                    <tr>
                        <th width="50" class="text-center"><?= $LANG['col_no'] ?></th>
                        <th><?= $LANG['employee'] ?></th>
                        <th><?= $LANG['request_date'] ?></th>
                        <th><?= $LANG['request_amount'] ?></th>
                        <th><?= $LANG['repayment_months'] ?></th>
                        <th><?= $LANG['monthly_deduction'] ?></th>
                        <th><?= $LANG['remaining'] ?></th>
                        <th class="text-center"><?= $LANG['status'] ?></th>
                        <?php if (checkPermission('cash_advances', 'can_update') || checkPermission('cash_advances', 'can_delete')): ?>
                        <th class="text-center" width="120"><?= $LANG['actions'] ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($advances)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox me-1"></i><?= $LANG['no_data'] ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($advances as $i => $a):
                        $statusBadge = [
                            'pending'  => 'warning',
                            'approved' => 'success',
                            'rejected' => 'danger',
                            'paid_off' => 'secondary',
                        ][$a['status']] ?? 'secondary';
                        $statusLabel = $LANG[$a['status']] ?? ucfirst($a['status']);
                        $pct = $a['amount'] > 0
                            ? round(($a['amount'] - $a['remaining_balance']) / $a['amount'] * 100)
                            : 100;
                    ?>
                    <tr>
                        <td class="text-center"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold small"><?= safeOutput($a['full_name']) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= safeOutput($a['employee_code']) ?></div>
                        </td>
                        <td class="small"><?= date('d M Y', strtotime($a['request_date'])) ?></td>
                        <td class="fw-semibold small"><?= formatCurrency($a['amount']) ?></td>
                        <td class="text-center small"><?= (int)$a['repayment_months'] ?> bln</td>
                        <td class="small"><?= formatCurrency($a['monthly_deduction']) ?></td>
                        <td>
                            <div class="small mb-1"><?= formatCurrency($a['remaining_balance']) ?></div>
                            <div class="progress" style="height:5px;width:70px">
                                <div class="progress-bar bg-warning" style="width:<?= max(0, min(100, 100 - $pct)) ?>%"></div>
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $statusBadge ?>"><?= $statusLabel ?></span>
                        </td>
                        <?php if (checkPermission('cash_advances', 'can_update') || checkPermission('cash_advances', 'can_delete')): ?>
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <?php if ($a['status'] === 'pending' && checkPermission('cash_advances', 'can_update')): ?>
                                <button class="btn btn-sm btn-success btn-approve"
                                    data-id="<?= $a['id'] ?>"
                                    title="<?= $LANG['approve'] ?>">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                                <?php endif; ?>
                                <?php if (checkPermission('cash_advances', 'can_update')): ?>
                                <button class="btn btn-sm btn-primary btn-edit"
                                    data-id="<?= $a['id'] ?>"
                                    data-empid="<?= $a['employee_id'] ?>"
                                    data-date="<?= htmlspecialchars($a['request_date'], ENT_QUOTES) ?>"
                                    data-amount="<?= $a['amount'] ?>"
                                    data-months="<?= (int)$a['repayment_months'] ?>"
                                    data-reason="<?= htmlspecialchars($a['reason'] ?? '', ENT_QUOTES) ?>"
                                    data-notes="<?= htmlspecialchars($a['notes'] ?? '', ENT_QUOTES) ?>"
                                    data-status="<?= htmlspecialchars($a['status'], ENT_QUOTES) ?>"
                                    title="<?= $LANG['edit'] ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($a['status'] === 'pending' && checkPermission('cash_advances', 'can_delete')): ?>
                                <button class="btn btn-sm btn-danger btn-del"
                                    data-id="<?= $a['id'] ?>"
                                    title="<?= $LANG['delete'] ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination Footer -->
    <?php if ($total > 0): ?>
    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="small text-muted">
            <?= $LANG['showing'] ?>
            <?= $offset + 1 ?> <?= $LANG['to'] ?>
            <?= min($offset + $perPage, $total) ?> <?= $LANG['of'] ?>
            <?= $total ?> <?= $LANG['entries'] ?>
        </div>
        <?php if ($totalPages > 1): ?>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $qp = $_GET;
                // First
                $qp['page'] = 1;
                ?>
                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($qp) ?>"><?= $LANG['first'] ?></a>
                </li>
                <?php $qp['page'] = max(1, $currentPage - 1); ?>
                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($qp) ?>"><?= $LANG['previous'] ?></a>
                </li>
                <?php
                $rangeStart = max(1, $currentPage - 2);
                $rangeEnd   = min($totalPages, $currentPage + 2);
                for ($p = $rangeStart; $p <= $rangeEnd; $p++):
                    $qp['page'] = $p;
                ?>
                <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($qp) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <?php $qp['page'] = min($totalPages, $currentPage + 1); ?>
                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($qp) ?>"><?= $LANG['next'] ?></a>
                </li>
                <?php $qp['page'] = $totalPages; ?>
                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($qp) ?>"><?= $LANG['last'] ?></a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Modal: Add / Edit Cash Advance -->
<div class="modal fade" id="advanceModal" tabindex="-1" aria-labelledby="advanceModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="advanceModalTitle">
                    <i class="bi bi-cash-coin me-2"></i><?= $LANG['add_cash_advance'] ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="advId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            <?= $LANG['employee'] ?> <span class="text-danger">*</span>
                        </label>
                        <select id="advEmpId" class="form-select" required>
                            <option value="">-- <?= $LANG['select'] ?> --</option>
                            <?php foreach ($employees as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= safeOutput($e['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><?= $LANG['request_date'] ?></label>
                        <input type="date" id="advDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            <?= $LANG['request_amount'] ?> (Rp) <span class="text-danger">*</span>
                        </label>
                        <input type="number" id="advAmount" class="form-control" min="0" step="10000" placeholder="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold"><?= $LANG['repayment_months'] ?></label>
                        <input type="number" id="advMonths" class="form-control" min="1" max="24" value="3">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold"><?= $LANG['status'] ?></label>
                        <select id="advStatus" class="form-select">
                            <option value="pending"><?= $LANG['pending'] ?></option>
                            <option value="approved"><?= $LANG['approved'] ?></option>
                            <option value="rejected"><?= $LANG['rejected'] ?></option>
                            <option value="paid_off"><?= $LANG['paid_off'] ?></option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold"><?= $LANG['reason'] ?></label>
                        <input type="text" id="advReason" class="form-control" maxlength="255">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold"><?= $LANG['notes'] ?></label>
                        <textarea id="advNotes" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-info mb-0 py-2" id="advCalcInfo" style="display:none">
                            <i class="bi bi-info-circle me-1"></i>
                            <?= $LANG['ca_monthly_info'] ?>: <strong id="advCalcAmt">-</strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i><?= $LANG['cancel'] ?>
                </button>
                <button type="button" class="btn btn-sm btn-primary" id="btnSave">
                    <i class="bi bi-check-circle me-1"></i><?= $LANG['save'] ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var csrf  = '<?= $_SESSION['csrf_token'] ?>';
    var caUrl = '<?= url('payroll/cash_advances.php') ?>';
    /* Simbol mata uang yang sedang aktif di sesi (IDR/USD/CNY), dipakai oleh
       hitung-cepat cicilan di bawah supaya tidak lagi memaku 'Rp'. */
    var CA_CURRENCY_SYMBOL = <?= json_encode(
        (($_SESSION['currency'] ?? getSetting('currency', 'IDR')) === 'USD') ? '$'
        : ((($_SESSION['currency'] ?? getSetting('currency', 'IDR')) === 'CNY') ? '¥' : 'Rp'),
        JSON_UNESCAPED_UNICODE) ?>;
    var modal = new bootstrap.Modal(document.getElementById('advanceModal'));

    /* ── Calculate monthly deduction preview ───────────── */
    function calcMonthly() {
        var a = parseFloat(document.getElementById('advAmount').value) || 0;
        var n = parseInt(document.getElementById('advMonths').value)   || 1;
        if (a > 0 && n > 0) {
            var m = Math.round(a / n);
            /* Simbol mata uang diambil dari sesi aktif, bukan dipaku 'Rp', supaya
       hitung-cepat ini ikut berubah saat switcher mata uang diganti. */
    document.getElementById('advCalcAmt').textContent = CA_CURRENCY_SYMBOL + ' ' + m.toLocaleString('id-ID');
            document.getElementById('advCalcInfo').style.display = '';
        } else {
            document.getElementById('advCalcInfo').style.display = 'none';
        }
    }
    document.getElementById('advAmount').addEventListener('input', calcMonthly);
    document.getElementById('advMonths').addEventListener('input', calcMonthly);

    /* ── Open Add modal ────────────────────────────────── */
    document.getElementById('btnAdd') && document.getElementById('btnAdd').addEventListener('click', function () {
        document.getElementById('advanceModalTitle').innerHTML =
            '<i class="bi bi-plus-circle me-2"></i><?php echo substr(bltx_js($LANG['add_cash_advance']), 1, -1) ?>';
        document.getElementById('advId').value     = '';
        document.getElementById('advEmpId').value  = '';
        document.getElementById('advDate').value   = '<?= date('Y-m-d') ?>';
        document.getElementById('advAmount').value = '';
        document.getElementById('advMonths').value = '3';
        document.getElementById('advStatus').value = 'pending';
        document.getElementById('advReason').value = '';
        document.getElementById('advNotes').value  = '';
        document.getElementById('advCalcInfo').style.display = 'none';
        modal.show();
    });

    /* ── Open Edit modal ───────────────────────────────── */
    document.querySelectorAll('.btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('advanceModalTitle').innerHTML =
                '<i class="bi bi-pencil me-2"></i><?php echo substr(bltx_js($LANG['edit_cash_advance']), 1, -1) ?>';
            document.getElementById('advId').value     = this.dataset.id;
            document.getElementById('advEmpId').value  = this.dataset.empid;
            document.getElementById('advDate').value   = this.dataset.date;
            document.getElementById('advAmount').value = this.dataset.amount;
            document.getElementById('advMonths').value = this.dataset.months;
            document.getElementById('advStatus').value = this.dataset.status;
            document.getElementById('advReason').value = this.dataset.reason;
            document.getElementById('advNotes').value  = this.dataset.notes;
            calcMonthly();
            modal.show();
        });
    });

    /* ── Save ──────────────────────────────────────────── */
    document.getElementById('btnSave').addEventListener('click', function () {
        var empId  = document.getElementById('advEmpId').value;
        var amount = document.getElementById('advAmount').value;
        if (!empId) {
            Swal.fire(<?php echo bltx_js($LANG['error']) ?>, <?php echo bltx_js($LANG['employee_required']) ?>, 'error');
            return;
        }
        if (!amount || parseFloat(amount) <= 0) {
            Swal.fire(<?php echo bltx_js($LANG['error']) ?>, <?php echo bltx_js($LANG['amount_required']) ?>, 'error');
            return;
        }

        var fd = new FormData();
        fd.append('csrf_token',      csrf);
        fd.append('ajax_action',     'save');
        fd.append('id',              document.getElementById('advId').value);
        fd.append('employee_id',     empId);
        fd.append('request_date',    document.getElementById('advDate').value);
        fd.append('amount',          amount);
        fd.append('repayment_months', document.getElementById('advMonths').value);
        fd.append('status',          document.getElementById('advStatus').value);
        fd.append('reason',          document.getElementById('advReason').value);
        fd.append('notes',           document.getElementById('advNotes').value);

        fetch(caUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    modal.hide();
                    Swal.fire({
                        icon: 'success',
                        title: <?php echo bltx_js($LANG['success']) ?>,
                        text:  <?php echo bltx_js($LANG['save_success']) ?>,
                        timer: 1800, showConfirmButton: false
                    }).then(function () { location.reload(); });
                } else {
                    Swal.fire(<?php echo bltx_js($LANG['error']) ?>, res.message || <?php echo bltx_js($LANG['error']) ?>, 'error');
                }
            })
            .catch(function () {
                Swal.fire(<?php echo bltx_js($LANG['error']) ?>, <?php echo bltx_js($LANG['network_error']) ?>, 'error');
            });
    });

    /* ── Approve ───────────────────────────────────────── */
    document.querySelectorAll('.btn-approve').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.dataset.id;
            Swal.fire({
                title:              <?php echo bltx_js($LANG['confirm']) ?>,
                text:               <?php echo bltx_js($LANG['confirm_approve_ca']) ?>,
                icon:               'question',
                showCancelButton:   true,
                confirmButtonColor: '#198754',
                cancelButtonText:   <?php echo bltx_js($LANG['cancel']) ?>,
                confirmButtonText:  <?php echo bltx_js($LANG['approve']) ?>
            }).then(function (r) {
                if (!r.isConfirmed) return;
                var fd = new FormData();
                fd.append('csrf_token',  csrf);
                fd.append('ajax_action', 'approve');
                fd.append('id',          id);
                fetch(caUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            Swal.fire({
                                icon: 'success', title: <?php echo bltx_js($LANG['success']) ?>,
                                text: <?php echo bltx_js($LANG['ca_approved']) ?>,
                                timer: 1800, showConfirmButton: false
                            }).then(function () { location.reload(); });
                        } else {
                            Swal.fire(<?php echo bltx_js($LANG['error']) ?>, res.message, 'error');
                        }
                    });
            });
        });
    });

    /* ── Delete ────────────────────────────────────────── */
    document.querySelectorAll('.btn-del').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.dataset.id;
            Swal.fire({
                title:              <?php echo bltx_js($LANG['are_you_sure']) ?>,
                text:               <?php echo bltx_js($LANG['confirm_delete_ca']) ?>,
                icon:               'warning',
                showCancelButton:   true,
                confirmButtonColor: '#dc3545',
                cancelButtonText:   <?php echo bltx_js($LANG['cancel']) ?>,
                confirmButtonText:  <?php echo bltx_js($LANG['delete']) ?>
            }).then(function (r) {
                if (!r.isConfirmed) return;
                var fd = new FormData();
                fd.append('csrf_token',  csrf);
                fd.append('ajax_action', 'delete');
                fd.append('id',          id);
                fetch(caUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            Swal.fire({
                                icon: 'success', title: <?php echo bltx_js($LANG['success']) ?>,
                                text: <?php echo bltx_js($LANG['delete_success']) ?>,
                                timer: 1800, showConfirmButton: false
                            }).then(function () { location.reload(); });
                        } else {
                            Swal.fire(<?php echo bltx_js($LANG['error']) ?>, res.message, 'error');
                        }
                    });
            });
        });
    });

}());
</script>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>