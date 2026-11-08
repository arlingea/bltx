<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "payroll_periods" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('payroll_periods');
    }
    define('PAYROLL_LEGACY_POST', true);
}
?>
<?php
// File: payroll/payroll_periods.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';
requirePayrollPeriodsAccess();

$page_title = $LANG['payroll_periods'];

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = '<div class="alert alert-danger">' . htmlspecialchars($LANG['invalid_csrf']) . '</div>';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $canSave = $id ? canUpdatePayrollPeriod() : canCreatePayrollPeriod();
            if (!$canSave) { $message = '<div class="alert alert-danger">' . ($LANG['no_permission']) . '</div>'; } else {
            $year  = (int)$_POST['period_year'];
            $month = (int)$_POST['period_month'];
            $name  = trim($_POST['period_name'] ?? '');
            $start = $_POST['start_date'] ?? '';
            $end   = $_POST['end_date'] ?? '';
            $pay   = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;
            $notes = trim($_POST['notes'] ?? '');
            if (!$name) $name = getMonthName($month) . ' ' . $year;

            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $stmt = $conn->prepare("UPDATE payroll_periods SET period_name=?, period_year=?, period_month=?, start_date=?, end_date=?, payment_date=?, notes=? WHERE id=?");
                $stmt->bind_param('ssissssi', $name, $year, $month, $start, $end, $pay, $notes, $id);
            } else {
                /* *_name = SALINAN PERMANEN users.full_name saat baris ditulis; kolom ini
                   tanpa foreign key sehingga namanya tidak hilang saat akun users dihapus. */
                $stmt = $conn->prepare("INSERT INTO payroll_periods (period_name, period_year, period_month, start_date, end_date, payment_date, notes, created_by, created_by_name) VALUES (?,?,?,?,?,?,?,?,(SELECT full_name FROM users WHERE id = ?))");
                $stmt->bind_param('ssissssii', $name, $year, $month, $start, $end, $pay, $notes, $_SESSION['user_id'], $_SESSION['user_id']);
            }
            if ($stmt->execute()) {
                logActivity('payroll_period', ($id ? 'Updated' : 'Created') . " payroll period: $name");
                $message = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>' . ($LANG['save_success']) . '</div>';
            } else {
                $message = '<div class="alert alert-danger">' . $conn->error . '</div>';
            }
            } // end canSave
        }

        if ($action === 'delete') {
            if (!canDeletePayrollPeriod()) { echo json_encode(['success'=>false,'message'=>$LANG['no_permission']]); exit; }
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("DELETE FROM payroll_periods WHERE id = ? AND status = 'draft'");
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute() && $stmt->affected_rows > 0;
            echo json_encode(['success'=>$ok,'message'=>$ok?'':'Cannot delete non-draft period']); exit;
        }

        if ($action === 'calculate') {
            if (!canUpdatePayrollPeriod()) { $message = '<div class="alert alert-danger">' . ($LANG['no_permission']) . '</div>'; } else {
            header('Location: ' . url('payroll/payroll_calculate.php?period_id=' . (int)$_POST['id']));
            exit;
            } // end canCalc
        }
    }
}

$periods = $conn->query("SELECT pp.*, COUNT(pr.id) AS payroll_count, COALESCE(SUM(pr.net_salary),0) AS total_net 
    FROM payroll_periods pp LEFT JOIN payroll pr ON pr.period_id = pp.id 
    GROUP BY pp.id ORDER BY pp.period_year DESC, pp.period_month DESC")->fetch_all(MYSQLI_ASSOC);

/* header.php TIDAK di-include di sini — sudah dicetak payroll_gate.php. */
/* Jaring pengaman: bila sebuah POST lama sampai ke titik ini tanpa lebih dulu
   exit (mis. validasi gagal lalu jatuh ke render), halaman TIDAK boleh
   dicetak tanpa header. Alihkan saja ke tab hub-nya. */
if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('payroll_periods'); }
?>
<?= $message ?>

<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-calendar-month-fill"></i><?= $LANG['payroll_periods'] ?></h2>
    <div class="page-actions">
        <a href="<?= payrollGateUrl('dashboard') ?>" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i> <?= $LANG['back'] ?></a>
        <button class="btn btn-sm btn-primary" id="btnAddPeriod"><i class="bi bi-plus-circle"></i> <?= $LANG['add_period'] ?></button>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= $LANG['no'] ?></th>
                        <th><?= $LANG['period'] ?></th>
                        <th><?= $LANG['start_date'] ?></th>
                        <th><?= $LANG['end_date'] ?></th>
                        <th><?= $LANG['payment_date'] ?></th>
                        <th><?= htmlspecialchars($LANG['employees']) ?></th>
                        <th><?= htmlspecialchars($LANG['total_net']) ?></th>
                        <th><?= $LANG['status'] ?></th>
                        <th><?= $LANG['actions'] ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($periods)): ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted"><?= $LANG['no_data'] ?></td></tr>
                <?php else: $i=1; foreach ($periods as $p): 
                    $sc = ['draft'=>'secondary','calculated'=>'info','paid'=>'success','cancelled'=>'danger'][$p['status']] ?? 'secondary';
                ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><div class="fw-semibold"><?= safeOutput($p['period_name']) ?></div></td>
                        <td><?= date('d M Y', strtotime($p['start_date'])) ?></td>
                        <td><?= date('d M Y', strtotime($p['end_date'])) ?></td>
                        <td><?= $p['payment_date'] ? date('d M Y', strtotime($p['payment_date'])) : '-' ?></td>
                        <td><span class="badge bg-primary"><?= $p['payroll_count'] ?></span></td>
                        <td><?= formatCurrency($p['total_net']) ?></td>
                        <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($p['status']) ?></span></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= payrollGateUrl('payroll_list') . '&period_id=' . $p['id'] ?>" class="btn btn-sm btn-info" title="<?= htmlspecialchars($LANG['view_payroll'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-list-ul"></i></a>
                                <?php if ($p['status'] === 'draft'): ?>
                                <a href="<?= url('payroll/payroll_calculate.php?period_id=' . $p['id']) ?>" class="btn btn-sm btn-success" title="<?= htmlspecialchars($LANG['calculate'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-calculator"></i></a>
                                <button class="btn btn-sm btn-primary btn-edit-period"
                                    data-id="<?= $p['id'] ?>"
                                    data-name="<?= safeOutput($p['period_name']) ?>"
                                    data-year="<?= $p['period_year'] ?>"
                                    data-month="<?= $p['period_month'] ?>"
                                    data-start="<?= $p['start_date'] ?>"
                                    data-end="<?= $p['end_date'] ?>"
                                    data-pay="<?= $p['payment_date'] ?>"
                                    data-notes="<?= safeOutput($p['notes']??'') ?>"
                                    title="<?= htmlspecialchars($LANG['edit'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-sm btn-danger btn-del-period" data-id="<?= $p['id'] ?>" title="<?= htmlspecialchars($LANG['delete'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="periodModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="periodModalTitle"><i class="bi bi-calendar-plus me-2"></i><?= htmlspecialchars($LANG['payroll_period']) ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="periodId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Year *</label>
                            <input type="number" name="period_year" id="pYear" class="form-control" value="<?= date('Y') ?>" min="2020" max="2099" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Month *</label>
                            <select name="period_month" id="pMonth" class="form-select" required>
                                <?php for ($m=1; $m<=12; $m++): ?>
                                <option value="<?= $m ?>" <?= ($m == date('n')) ? 'selected' : '' ?>><?= getMonthName($m) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?= htmlspecialchars($LANG['period_name']) ?></label>
                            <input type="text" name="period_name" id="pName" class="form-control" placeholder="<?= htmlspecialchars($LANG['eg_payroll_period']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Start Date *</label>
                            <input type="date" name="start_date" id="pStart" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">End Date *</label>
                            <input type="date" name="end_date" id="pEnd" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= htmlspecialchars($LANG['payment_date']) ?></label>
                            <input type="date" name="payment_date" id="pPay" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?= htmlspecialchars($LANG['notes']) ?></label>
                            <textarea name="notes" id="pNotes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?= htmlspecialchars($LANG['cancel']) ?></button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> <?= htmlspecialchars($LANG['save']) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var csrf = '<?= $_SESSION['csrf_token'] ?>';
var pModal = new bootstrap.Modal(document.getElementById('periodModal'));

function autoFillDates() {
    var y = document.getElementById('pYear').value;
    var m = document.getElementById('pMonth').value;
    if (y && m) {
        var mm = m.toString().padStart(2,'0');
        var lastDay = new Date(y, m, 0).getDate();
        document.getElementById('pStart').value = y+'-'+mm+'-01';
        document.getElementById('pEnd').value = y+'-'+mm+'-'+lastDay.toString().padStart(2,'0');
        document.getElementById('pPay').value = y+'-'+mm+'-'+lastDay.toString().padStart(2,'0');
        var months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
        if (!document.getElementById('pName').value || document.getElementById('pName').dataset.auto) {
            document.getElementById('pName').value = months[parseInt(m)] + ' ' + y;
            document.getElementById('pName').dataset.auto = '1';
        }
    }
}

var btnAddPeriod = document.getElementById('btnAddPeriod');
if (btnAddPeriod) {
    btnAddPeriod.addEventListener('click', function() {
        document.getElementById('periodModalTitle').innerHTML = '<i class=\'bi bi-calendar-plus me-2\'></i>' + <?= json_encode((string)$LANG['add'], JSON_UNESCAPED_UNICODE) ?> + ' ' + <?= json_encode((string)$LANG['payroll_period'], JSON_UNESCAPED_UNICODE) ?>;
        document.getElementById('periodId').value = '';
        document.getElementById('pName').value = '';
        document.getElementById('pName').dataset.auto = '';
        document.getElementById('pYear').value = '<?= date('Y') ?>';
        document.getElementById('pMonth').value = '<?= date('n') ?>';
        autoFillDates();
        pModal.show();
    });
}

document.getElementById('pYear').addEventListener('change', autoFillDates);
document.getElementById('pMonth').addEventListener('change', autoFillDates);
document.getElementById('pName').addEventListener('input', function() { this.dataset.auto = ''; });

document.querySelectorAll('.btn-edit-period').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('periodModalTitle').innerHTML = '<i class=\'bi bi-pencil me-2\'></i>' + <?= json_encode((string)$LANG['edit'], JSON_UNESCAPED_UNICODE) ?> + ' ' + <?= json_encode((string)$LANG['payroll_period'], JSON_UNESCAPED_UNICODE) ?>;
        document.getElementById('periodId').value = this.dataset.id;
        document.getElementById('pName').value = this.dataset.name;
        document.getElementById('pName').dataset.auto = '';
        document.getElementById('pYear').value = this.dataset.year;
        document.getElementById('pMonth').value = this.dataset.month;
        document.getElementById('pStart').value = this.dataset.start;
        document.getElementById('pEnd').value = this.dataset.end;
        document.getElementById('pPay').value = this.dataset.pay;
        document.getElementById('pNotes').value = this.dataset.notes;
        pModal.show();
    });
});

document.querySelectorAll('.btn-del-period').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = this.dataset.id;
        Swal.fire({ title: '<?= $LANG['are_you_sure'] ?>', text: 'Delete this period?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' })
        .then(function(r) {
            if (r.isConfirmed) {
                var fd = new FormData();
                fd.append('action','delete'); fd.append('id',id); fd.append('csrf_token',csrf);
                fetch('<?= url('payroll/payroll_periods.php') ?>', { method:'POST', body:fd })
                    .then(r => r.json()).then(function(res) {
                        if (res.success) Swal.fire('Deleted!','','success').then(()=>location.reload());
                        else Swal.fire('Error', res.message, 'error');
                    });
            }
        });
    });
});
</script>

<?php /* footer.php TIDAK di-include di sini — dicetak payroll_gate.php setelah fragmen ini. */ ?>