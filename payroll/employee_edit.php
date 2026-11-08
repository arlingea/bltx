<?php
// File: payroll/employee_edit.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';
requireLogin();
if (!canUpdateEmployee()) {
    redirectNoAccess();
}

$page_title = $LANG['edit_employee'];

$id = (int)($_GET['id'] ?? 0);
$emp = getEmployee($conn, $id);
if (!$emp) { header('Location: ' . payrollGateUrl('employees')); exit; }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$errors = [];
$data = $emp;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid CSRF token';
    } else {
        $fields = ['nik','npwp','bpjs_kesehatan','bpjs_ketenagakerjaan','full_name','nick_name',
            'birth_date','birth_place','gender','marital_status','blood_type','religion',
            'address','phone','emergency_phone','emergency_contact_name','email',
            'bank_name','bank_account_number','bank_account_name','medical_history',
            'reference','work_experience','department','position','join_date','end_date',
            'employment_type','employee_status','basic_salary','notes'];
        foreach ($fields as $f) { $data[$f] = $_POST[$f] ?? ''; }
        $data['shift_id']      = !empty($_POST['shift_id'])      ? (int)$_POST['shift_id']      : null;
        $data['department_id'] = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $data['position_id']   = !empty($_POST['position_id'])   ? (int)$_POST['position_id']   : null;
        $data['religion_id']   = !empty($_POST['religion_id'])   ? (int)$_POST['religion_id']   : null;

        // Resolve text values from IDs for legacy columns
        if ($data['department_id']) {
            $r = $conn->prepare("SELECT name FROM departments WHERE id=?");
            $r->bind_param('i', $data['department_id']); $r->execute();
            $r->bind_result($data['department']); $r->fetch(); $r->close();
        }
        if ($data['position_id']) {
            $r = $conn->prepare("SELECT name FROM positions WHERE id=?");
            $r->bind_param('i', $data['position_id']); $r->execute();
            $r->bind_result($data['position']); $r->fetch(); $r->close();
        }
        if ($data['religion_id']) {
            $r = $conn->prepare("SELECT name FROM religions WHERE id=?");
            $r->bind_param('i', $data['religion_id']); $r->execute();
            $r->bind_result($data['religion']); $r->fetch(); $r->close();
        }

        // DATE columns: empty string or 0000-00-00 must be NULL
        foreach (['birth_date','join_date','end_date'] as $df) {
            if (empty($data[$df]) || $data[$df] === '0000-00-00') $data[$df] = null;
        }
        // ENUM nullable: blood_type allows NULL, empty string is invalid
        if (empty($data['blood_type'])) $data['blood_type'] = null;
        // ENUM NOT NULL: fallback to default if invalid/empty value submitted
        if (!in_array($data['gender'], ['male','female'])) $data['gender'] = 'male';
        if (!in_array($data['marital_status'], ['single','married','divorced','widowed'])) $data['marital_status'] = 'single';
        if (!in_array($data['employment_type'], ['permanent','contract','part_time','probation'])) $data['employment_type'] = 'permanent';
        if (!in_array($data['employee_status'], ['active','inactive','resigned','terminated'])) $data['employee_status'] = 'active';
        // DECIMAL NOT NULL: ensure numeric value
        $data['basic_salary'] = is_numeric($data['basic_salary']) ? $data['basic_salary'] : 0;

        if (empty($data['full_name'])) $errors[] = 'Full name is required';

        // Handle photo
        $photoFile = $emp['photo'];
        if (!empty($_FILES['photo']['name'])) {
            $up = uploadEmployeePhoto($_FILES['photo']);
            if (isset($up['error'])) $errors[] = 'Photo: ' . $up['error'];
            else {
                // Delete old photo
                if ($emp['photo'] && file_exists(__DIR__ . '/../uploads/employee/employee_photos/' . $emp['photo'])) {
                    unlink(__DIR__ . '/../uploads/employee/employee_photos/' . $emp['photo']);
                }
                $photoFile = $up['filename'];
            }
        }
        // Handle CV
        $cvFile = $emp['cv_file'];
        if (!empty($_FILES['cv_file']['name'])) {
            $up = uploadEmployeeDocument($_FILES['cv_file']);
            if (isset($up['error'])) $errors[] = 'CV: ' . $up['error'];
            else {
                if ($emp['cv_file'] && file_exists(__DIR__ . '/../uploads/employee/employee_document/' . $emp['cv_file'])) {
                    unlink(__DIR__ . '/../uploads/employee/employee_document/' . $emp['cv_file']);
                }
                $cvFile = $up['filename'];
            }
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("UPDATE employees SET
                nik=?, npwp=?, bpjs_kesehatan=?, bpjs_ketenagakerjaan=?, full_name=?, nick_name=?,
                birth_date=?, birth_place=?, gender=?, marital_status=?, blood_type=?, religion=?,
                address=?, phone=?, emergency_phone=?, emergency_contact_name=?, email=?,
                bank_name=?, bank_account_number=?, bank_account_name=?, medical_history=?,
                reference=?, work_experience=?, department=?, position=?, join_date=?, end_date=?,
                shift_id=?, employment_type=?, employee_status=?, basic_salary=?, photo=?, cv_file=?, notes=?,
                department_id=?, position_id=?, religion_id=?
                WHERE id=?");
            $stmt->bind_param(
                'sssssssssssssssssssssssssssissdsssiiii',
                $data['nik'], $data['npwp'], $data['bpjs_kesehatan'], $data['bpjs_ketenagakerjaan'],
                $data['full_name'], $data['nick_name'], $data['birth_date'], $data['birth_place'],
                $data['gender'], $data['marital_status'], $data['blood_type'], $data['religion'],
                $data['address'], $data['phone'], $data['emergency_phone'], $data['emergency_contact_name'],
                $data['email'], $data['bank_name'], $data['bank_account_number'], $data['bank_account_name'],
                $data['medical_history'], $data['reference'], $data['work_experience'],
                $data['department'], $data['position'], $data['join_date'], $data['end_date'],
                $data['shift_id'], $data['employment_type'], $data['employee_status'],
                $data['basic_salary'], $photoFile, $cvFile, $data['notes'],
                $data['department_id'], $data['position_id'], $data['religion_id'], $id
            );
            if ($stmt->execute()) {
                logActivity('employee_edit', "Updated employee ID: $id, Name: {$data['full_name']}");
                header('Location: ' . url('payroll/employee_view.php?id=' . $id . '&updated=1'));
                exit;
            }
            $errors[] = 'Database error: ' . $conn->error;
        }
    }
}

$shifts      = $conn->query("SELECT id, name FROM employee_shifts WHERE is_active = 1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$departments = $conn->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$positions   = $conn->query("SELECT id, name FROM positions WHERE is_active = 1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$religions   = $conn->query("SELECT id, name FROM religions WHERE is_active = 1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
include __DIR__ . '/../includes/header.php';
?>
<!-- PEMUATAN ULANG ASET DIHAPUS (poin 9).
     Baris <script src> di sini memuat lagi berkas yang SUDAH dimuat
     includes/footer.php. jQuery yang dimuat dua kali mengganti instans
     pertama beserta seluruh plugin yang terdaftar padanya — DataTables
     dan komponen Bootstrap bisa berhenti bekerja di halaman ini tanpa
     satu pun pesan error. Pola yang sama sudah ditutup di settings/users.php,
     settings/news.php, dan inventory/inventory_in.php. -->
<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= safeOutput($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="page-title-bar">
    <h2 class="page-title-text"><i class="bi bi-pencil-square"></i><?= $LANG['edit_employee'] ?> — <?= safeOutput($data['full_name']) ?></h2>
    <div class="page-actions">
        <a href="<?= url('payroll/employee_view.php?id=' . $id) ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i> <?= $LANG['view'] ?></a>
        <a href="<?= payrollGateUrl('employees') ?>" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i> <?= $LANG['back'] ?></a>
    </div>
</div>

<form method="POST" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <!-- Employee Code (read-only) -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center gap-3">
                <!-- Photo preview -->
                <?php if ($data['photo'] && file_exists(__DIR__ . '/../uploads/employee/employee_photos/' . $data['photo'])): ?>
                    <img src="<?= url('uploads/employee/employee_photos/' . $data['photo']) ?>" class="rounded-circle" width="64" height="64" style="object-fit:cover">
                <?php else: ?>
                    <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white" style="width:64px;height:64px;font-size:24px"><?= strtoupper(substr($data['full_name'],0,1)) ?></div>
                <?php endif; ?>
                <div>
                    <div class="fw-bold fs-5"><?= safeOutput($data['full_name']) ?></div>
                    <div class="text-muted small">Employee Code: <span class="badge bg-secondary"><?= safeOutput($data['employee_code']) ?></span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Personal Information -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header"><i class="bi bi-person me-2"></i><?= $LANG['personal_information'] ?></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['full_name'] ?> <span class="text-danger">*</span></label><input type="text" name="full_name" class="form-control" value="<?= safeOutput($data['full_name']) ?>" required></div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['nick_name'] ?></label><input type="text" name="nick_name" class="form-control" value="<?= safeOutput($data['nick_name'] ?? '') ?>"></div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['nik'] ?></label><input type="text" name="nik" class="form-control" value="<?= safeOutput($data['nik'] ?? '') ?>" maxlength="20"></div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['npwp'] ?></label><input type="text" name="npwp" class="form-control" value="<?= safeOutput($data['npwp'] ?? '') ?>" maxlength="25"></div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['bpjs_kesehatan'] ?></label><input type="text" name="bpjs_kesehatan" class="form-control" value="<?= safeOutput($data['bpjs_kesehatan'] ?? '') ?>"></div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['bpjs_ketenagakerjaan'] ?></label><input type="text" name="bpjs_ketenagakerjaan" class="form-control" value="<?= safeOutput($data['bpjs_ketenagakerjaan'] ?? '') ?>"></div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['birth_date'] ?></label><input type="date" name="birth_date" class="form-control" value="<?= safeOutput($data['birth_date'] ?? '') ?>"></div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['birth_place'] ?></label><input type="text" name="birth_place" class="form-control" value="<?= safeOutput($data['birth_place'] ?? '') ?>"></div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label fw-semibold"><?= $LANG['gender'] ?></label>
                    <select name="gender" class="form-select">
                        <option value="male" <?= ($data['gender'] === 'male') ? 'selected' : '' ?>><?= htmlspecialchars($LANG['male']) ?></option>
                        <option value="female" <?= ($data['gender'] === 'female') ? 'selected' : '' ?>><?= htmlspecialchars($LANG['female']) ?></option>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label fw-semibold"><?= $LANG['marital_status'] ?></label>
                    <select name="marital_status" class="form-select">
                        <?php foreach (['single','married','divorced','widowed'] as $ms): ?>
                        <option value="<?= $ms ?>" <?= ($data['marital_status'] === $ms) ? 'selected' : '' ?>><?= ucfirst($ms) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label fw-semibold"><?= $LANG['blood_type'] ?></label>
                    <select name="blood_type" class="form-select">
                        <option value="">-- Select --</option>
                        <?php foreach (['A','B','AB','O','A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?>
                        <option value="<?= $bt ?>" <?= ($data['blood_type'] === $bt) ? 'selected' : '' ?>><?= $bt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label fw-semibold"><?= $LANG['religion'] ?></label>
                    <select name="religion_id" class="form-select">
                        <option value="">-- <?= $LANG['select'] ?> --</option>
                        <?php foreach ($religions as $rel): ?>
                        <option value="<?= $rel['id'] ?>" <?= (($data['religion_id'] ?? '') == $rel['id']) ? 'selected' : '' ?>><?= safeOutput($rel['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12"><label class="form-label fw-semibold"><?= $LANG['address'] ?></label><textarea name="address" class="form-control" rows="2"><?= safeOutput($data['address'] ?? '') ?></textarea></div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['phone'] ?></label><input type="text" name="phone" class="form-control" value="<?= safeOutput($data['phone'] ?? '') ?>"></div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['emergency_phone'] ?></label><input type="text" name="emergency_phone" class="form-control" value="<?= safeOutput($data['emergency_phone'] ?? '') ?>"></div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['emergency_contact_name'] ?></label><input type="text" name="emergency_contact_name" class="form-control" value="<?= safeOutput($data['emergency_contact_name'] ?? '') ?>"></div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['email'] ?></label><input type="email" name="email" class="form-control" value="<?= safeOutput($data['email'] ?? '') ?>"></div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label fw-semibold"><?= $LANG['photo'] ?></label>
                    <?php if ($data['photo'] && file_exists(__DIR__ . '/../uploads/employee/employee_photos/' . $data['photo'])): ?>
                        <div class="mb-1"><img src="<?= url('uploads/employee/employee_photos/' . $data['photo']) ?>" style="height:50px;border-radius:6px;object-fit:cover" loading="lazy"></div>
                    <?php endif; ?>
                    <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png">
                    <div class="form-text">JPG/JPEG/PNG, max 5MB. <?= $LANG['leave_blank'] ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Employment Information -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header"><i class="bi bi-briefcase me-2"></i><?= $LANG['employment_information'] ?></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <label class="form-label fw-semibold"><?= $LANG['department'] ?></label>
                    <select name="department_id" class="form-select">
                        <option value="">-- <?= $LANG['select'] ?> --</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= (($data['department_id'] ?? '') == $dept['id']) ? 'selected' : '' ?>><?= safeOutput($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label fw-semibold"><?= $LANG['position'] ?></label>
                    <select name="position_id" class="form-select">
                        <option value="">-- <?= $LANG['select'] ?> --</option>
                        <?php foreach ($positions as $pos): ?>
                        <option value="<?= $pos['id'] ?>" <?= (($data['position_id'] ?? '') == $pos['id']) ? 'selected' : '' ?>><?= safeOutput($pos['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label fw-semibold"><?= $LANG['shift'] ?></label>
                    <select name="shift_id" class="form-select">
                        <option value="">-- No Shift --</option>
                        <?php foreach ($shifts as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($data['shift_id'] == $s['id']) ? 'selected' : '' ?>><?= safeOutput($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['join_date'] ?></label><input type="date" name="join_date" class="form-control" value="<?= safeOutput($data['join_date'] ?? '') ?>"></div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['end_date'] ?></label><input type="date" name="end_date" class="form-control" value="<?= safeOutput($data['end_date'] ?? '') ?>"></div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label fw-semibold"><?= $LANG['employment_type'] ?></label>
                    <select name="employment_type" class="form-select">
                        <?php foreach (['permanent'=>'Permanent','contract'=>'Contract','part_time'=>'Part Time','probation'=>'Probation'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= ($data['employment_type'] === $v) ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label fw-semibold"><?= $LANG['employee_status'] ?></label>
                    <select name="employee_status" class="form-select">
                        <?php foreach (['active'=>'Active','inactive'=>'Inactive','resigned'=>'Resigned','terminated'=>'Terminated'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= ($data['employee_status'] === $v) ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4"><label class="form-label fw-semibold"><?= $LANG['basic_salary'] ?> (IDR)</label><input type="number" name="basic_salary" class="form-control" value="<?= safeOutput($data['basic_salary'] ?? '0') ?>" min="0" step="1000"></div>
            </div>
        </div>
    </div>

    <!-- Bank + Additional -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header"><i class="bi bi-bank me-2"></i><?= $LANG['bank_information'] ?></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label fw-semibold"><?= $LANG['bank_name'] ?></label><input type="text" name="bank_name" class="form-control" value="<?= safeOutput($data['bank_name'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label fw-semibold"><?= $LANG['bank_account'] ?></label><input type="text" name="bank_account_number" class="form-control" value="<?= safeOutput($data['bank_account_number'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label fw-semibold"><?= $LANG['account_name'] ?></label><input type="text" name="bank_account_name" class="form-control" value="<?= safeOutput($data['bank_account_name'] ?? '') ?>"></div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header"><i class="bi bi-info-circle me-2"></i><?= $LANG['additional_information'] ?></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label fw-semibold"><?= $LANG['medical_history'] ?></label><textarea name="medical_history" class="form-control" rows="3"><?= safeOutput($data['medical_history'] ?? '') ?></textarea></div>
                <div class="col-md-6"><label class="form-label fw-semibold"><?= $LANG['reference'] ?></label><textarea name="reference" class="form-control" rows="3"><?= safeOutput($data['reference'] ?? '') ?></textarea></div>
                <div class="col-12"><label class="form-label fw-semibold"><?= $LANG['work_experience'] ?></label><textarea name="work_experience" class="form-control" rows="4"><?= safeOutput($data['work_experience'] ?? '') ?></textarea></div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= $LANG['cv_document'] ?></label>
                    <?php if ($data['cv_file'] && file_exists(__DIR__ . '/../uploads/employee/employee_document/' . $data['cv_file'])): ?>
                        <div class="mb-1"><a href="<?= url('uploads/employee/employee_document/' . $data['cv_file']) ?>" target="_blank" class="btn btn-secondary btn-sm"><i class="bi bi-file-earmark-text"></i> <?= $LANG['view'] ?> CV</a></div>
                    <?php endif; ?>
                    <input type="file" name="cv_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                    <div class="form-text">JPG/JPEG/PNG/PDF, max 5MB. <?= $LANG['leave_blank'] ?></div>
                </div>
                <div class="col-md-6"><label class="form-label fw-semibold"><?= $LANG['notes'] ?></label><textarea name="notes" class="form-control" rows="3"><?= safeOutput($data['notes'] ?? '') ?></textarea></div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 justify-content-end pb-4">
        <a href="<?= payrollGateUrl('employees') ?>" class="btn btn-sm btn-secondary"><i class="bi bi-x-lg"></i> <?= $LANG['cancel'] ?></a>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> <?= $LANG['update'] ?></button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>