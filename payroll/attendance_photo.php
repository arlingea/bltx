<?php
// File: payroll/attendance_photo.php
// Stand-alone page for photo-based check-in/check-out
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global_permission.php';
require_once __DIR__ . '/../includes/payroll_functions.php';

// This page is accessible to logged-in users (not only manager/superadmin)
if (!isLoggedIn()) {
    header('Location: ' . url('index.php')); exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']); exit;
    }

    $empId   = (int)$_POST['employee_id'];
    $type    = $_POST['type'] ?? 'check_in'; // check_in or check_out
    $date    = date('Y-m-d');
    $time    = date('H:i:s');
    $lat     = floatval($_POST['lat'] ?? 0);
    $lng     = floatval($_POST['lng'] ?? 0);

    if (!$empId) { echo json_encode(['success'=>false,'message'=>'Employee required']); exit; }

    // Upload photo
    $photoFile = null;
    if (!empty($_FILES['photo']['name'])) {
        $up = uploadAttendancePhoto($_FILES['photo']);
        if (isset($up['error'])) { echo json_encode(['success'=>false,'message'=>$up['error']]); exit; }
        $photoFile = $up['filename'];
    }

    // Get employee shift
    $empRow = getEmployee($conn, $empId);
    $shiftId = $empRow['shift_id'] ?? null;

    if ($type === 'check_in') {
        // Calculate late
        $lateMin = 0;
        if ($shiftId) {
            $_sh_stmt = $conn->prepare("SELECT start_time FROM employee_shifts WHERE id = ?");
            $_sh_stmt->bind_param('i', $shiftId);
            $_sh_stmt->execute();
            $shiftRow = $_sh_stmt->get_result()->fetch_assoc();
            $_sh_stmt->close();
            if ($shiftRow) $lateMin = calculateLateMinutes($shiftRow['start_time'], $time);
        }
        // Upsert attendance
        /* *_name = SALINAN PERMANEN users.full_name saat baris ditulis; kolom ini
           tanpa foreign key sehingga namanya tidak hilang saat akun users dihapus. */
        $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, shift_id, check_in, check_in_photo, check_in_lat, check_in_lng, status, late_minutes, created_by, created_by_name)
            VALUES (?,?,?,?,?,?,?,'present',?,?,(SELECT full_name FROM users WHERE id = ?))
            ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), check_in_photo=VALUES(check_in_photo), check_in_lat=VALUES(check_in_lat), check_in_lng=VALUES(check_in_lng), status='present', late_minutes=VALUES(late_minutes)");
        $stmt->bind_param('iisisddiii', $empId, $date, $shiftId, $time, $photoFile, $lat, $lng, $lateMin, $_SESSION['user_id'], $_SESSION['user_id']);
        if ($stmt->execute()) {
            logActivity('check_in', "Employee $empId checked in at $time");
            echo json_encode(['success'=>true,'message'=>'Check-in recorded at ' . date('H:i'),'late'=>$lateMin]);
        } else {
            echo json_encode(['success'=>false,'message'=>$conn->error]);
        }
    } else {
        // Check out
        $stmt = $conn->prepare("UPDATE attendance SET check_out=?, check_out_photo=?, check_out_lat=?, check_out_lng=? WHERE employee_id=? AND attendance_date=?");
        $stmt->bind_param('ssddis', $time, $photoFile, $lat, $lng, $empId, $date);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            logActivity('check_out', "Employee $empId checked out at $time");
            echo json_encode(['success'=>true,'message'=>'Check-out recorded at ' . date('H:i')]);
        } else {
            echo json_encode(['success'=>false,'message'=>'No check-in record found for today. Please check-in first.']);
        }
    }
    exit;
}

// Get active employees
$employees = getActiveEmployees($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#667eea">
<title><?= htmlspecialchars($LANG['photo_attendance']) ?> | <?= getSetting('site_name', APP_NAME) ?></title>
<link href="<?= url('assets/vendor/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>">
<link rel="stylesheet" href="<?= url('assets/vendor/sweetalert2/sweetalert2.min.css') ?>">
<style>
:root { --primary: #667eea; }
body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; padding: 16px; }
.att-card { background: #fff; border-radius: 20px; padding: 28px; max-width: 480px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
.att-logo { text-align: center; margin-bottom: 20px; }
.att-logo h4 { color: var(--primary); font-weight: 700; }
#cameraWrap { position: relative; background: #000; border-radius: 12px; overflow: hidden; aspect-ratio: 4/3; }
#videoEl { width: 100%; height: 100%; object-fit: cover; display: block; }
#canvasEl { display: none; }
#photoPreview { width: 100%; height: 100%; object-fit: cover; display: none; border-radius: 12px; }
.overlay-info { position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(transparent, rgba(0,0,0,.7)); padding: 12px; color: #fff; font-size: 13px; }
.time-display { font-size: 28px; font-weight: 700; text-align: center; color: var(--primary); margin: 12px 0; }
.btn-cam { width: 64px; height: 64px; border-radius: 50%; border: 4px solid var(--primary); background: #fff; display: flex; align-items: center; justify-content: center; font-size: 28px; cursor: pointer; margin: 0 auto; transition: all .2s; }
.btn-cam:hover { background: var(--primary); color: #fff; }
</style>
</head>
<body>
<div class="att-card">
    <div class="att-logo">
        <i class="bi bi-camera-fill fs-1 text-primary"></i>
        <h4 class="mb-0"><?= getSetting('site_name', APP_NAME) ?></h4>
        <p class="text-muted small mb-0"><?= htmlspecialchars($LANG['photo_attendance']) ?></p>
    </div>

    <div class="time-display" id="clockDisplay"><?= date('H:i:s') ?></div>
    <div class="text-center text-muted small mb-3"><?= date('l, d F Y') ?></div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Select Employee *</label>
        <select id="selEmployee" class="form-select">
            <option value="">-- Select Your Name --</option>
            <?php foreach ($employees as $e): ?>
            <option value="<?= $e['id'] ?>"><?= safeOutput($e['full_name']) ?> (<?= safeOutput($e['employee_code']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label fw-semibold">Attendance Type *</label>
        <div class="d-flex gap-2">
            <div class="form-check flex-grow-1 border rounded p-2 cursor-pointer" id="ciLabel">
                <input class="form-check-input" type="radio" name="attType" id="rdCin" value="check_in" checked>
                <label class="form-check-label fw-semibold text-success" for="rdCin"><i class="bi bi-box-arrow-in-right me-1"></i><?= htmlspecialchars($LANG['check_in']) ?></label>
            </div>
            <div class="form-check flex-grow-1 border rounded p-2 cursor-pointer" id="coLabel">
                <input class="form-check-input" type="radio" name="attType" id="rdCout" value="check_out">
                <label class="form-check-label fw-semibold text-danger" for="rdCout"><i class="bi bi-box-arrow-right me-1"></i><?= htmlspecialchars($LANG['check_out']) ?></label>
            </div>
        </div>
    </div>

    <!-- Camera -->
    <div id="cameraWrap" class="mb-3">
        <video id="videoEl" autoplay playsinline></video>
        <canvas id="canvasEl"></canvas>
        <img id="photoPreview" src="" alt="Photo preview">
        <div class="overlay-info" id="cameraStatus"><?= htmlspecialchars($LANG['camera_loading']) ?></div>
    </div>

    <div class="d-flex gap-2 justify-content-center mb-3">
        <button class="btn btn-sm-cam" id="btnCapture" title="<?= htmlspecialchars($LANG['capture_photo'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-camera-fill"></i></button>
        <button class="btn btn-sm btn-secondary" id="btnRetake" style="display:none"><i class="bi bi-arrow-counterclockwise"></i> <?= htmlspecialchars($LANG['retake']) ?></button>
    </div>

    <button class="btn btn-primary w-100 btn-sm" id="btnSubmit" disabled>
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($LANG['submit_attendance']) ?>
    </button>

    <div class="text-center mt-3">
        <a href="<?= payrollGateUrl('dashboard') ?>" class="text-muted small"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($LANG['back_to_dashboard']) ?></a>
    </div>
</div>

<!-- PEMUATAN ULANG ASET DIHAPUS (poin 9).
     Baris <script src> di sini memuat lagi berkas yang SUDAH dimuat
     includes/footer.php. jQuery yang dimuat dua kali mengganti instans
     pertama beserta seluruh plugin yang terdaftar padanya — DataTables
     dan komponen Bootstrap bisa berhenti bekerja di halaman ini tanpa
     satu pun pesan error. Pola yang sama sudah ditutup di settings/users.php,
     settings/news.php, dan inventory/inventory_in.php. -->
<script>
// Clock
setInterval(function() {
    var now = new Date();
    document.getElementById('clockDisplay').textContent = now.toTimeString().substring(0,8);
}, 1000);

// Camera
var stream = null;
var capturedBlob = null;
var video = document.getElementById('videoEl');
var canvas = document.getElementById('canvasEl');
var preview = document.getElementById('photoPreview');
var cameraStatus = document.getElementById('cameraStatus');
var btnCapture = document.getElementById('btnCapture');
var btnRetake = document.getElementById('btnRetake');
var btnSubmit = document.getElementById('btnSubmit');

navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640, height: 480 } })
    .then(function(s) {
        stream = s;
        video.srcObject = s;
        cameraStatus.textContent = 'Camera ready - point camera at your face';
    })
    .catch(function(err) {
        cameraStatus.textContent = 'Camera not available. Upload photo manually.';
        document.getElementById('cameraWrap').innerHTML += '<div class="p-3"><input type="file" id="manualPhoto" class="form-control" accept=".jpg,.jpeg,.png"></div>';
    });

btnCapture.addEventListener('click', function() {
    if (!stream) return;
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    canvas.toBlob(function(blob) {
        capturedBlob = blob;
        preview.src = URL.createObjectURL(blob);
        preview.style.display = 'block';
        video.style.display = 'none';
        btnRetake.style.display = 'inline-block';
        btnCapture.style.display = 'none';
        cameraStatus.textContent = 'Photo captured!';
        checkReadyToSubmit();
    }, 'image/jpeg', 0.85);
});

btnRetake.addEventListener('click', function() {
    capturedBlob = null;
    preview.style.display = 'none';
    video.style.display = 'block';
    btnRetake.style.display = 'none';
    btnCapture.style.display = 'flex';
    cameraStatus.textContent = 'Camera ready';
    checkReadyToSubmit();
});

document.getElementById('selEmployee').addEventListener('change', checkReadyToSubmit);

function checkReadyToSubmit() {
    var emp = document.getElementById('selEmployee').value;
    var hasPhoto = capturedBlob !== null;
    btnSubmit.disabled = !(emp && hasPhoto);
}

// Geolocation
var userLat = 0, userLng = 0;
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(function(pos) {
        userLat = pos.coords.latitude;
        userLng = pos.coords.longitude;
    });
}

btnSubmit.addEventListener('click', function() {
    var empId = document.getElementById('selEmployee').value;
    var type  = document.querySelector('input[name="attType"]:checked').value;
    if (!empId) { Swal.fire('Error', 'Please select employee', 'error'); return; }
    if (!capturedBlob) { Swal.fire('Error', 'Please capture a photo first', 'error'); return; }

    btnSubmit.disabled = true;
    btnSubmit.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Submitting...';

    var fd = new FormData();
    fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
    fd.append('employee_id', empId);
    fd.append('type', type);
    fd.append('lat', userLat);
    fd.append('lng', userLng);
    fd.append('photo', capturedBlob, 'attendance_' + Date.now() + '.jpg');

    fetch('<?= url('payroll/attendance_photo.php') ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(function(res) {
            if (res.success) {
                var msg = res.message;
                if (res.late && res.late > 0) msg += '<br><span class="text-warning"><i class="bi bi-exclamation-triangle"></i> Late: ' + res.late + ' minutes</span>';
                Swal.fire({ icon: 'success', title: 'Success!', html: msg, confirmButtonText: 'Done' }).then(function() {
                    location.reload();
                });
            } else {
                Swal.fire('Error', res.message, 'error');
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = '<i class="bi bi-check-circle me-2"></i>' + <?= json_encode((string)$LANG['submit_attendance'], JSON_UNESCAPED_UNICODE) ?>;
            }
        })
        .catch(function() {
            Swal.fire('Error', 'Network error. Please try again.', 'error');
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<i class="bi bi-check-circle me-2"></i>' + <?= json_encode((string)$LANG['submit_attendance'], JSON_UNESCAPED_UNICODE) ?>;
        });
});
</script>
</body>
</html>
