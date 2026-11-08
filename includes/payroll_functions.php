<?php
// ============================================================
// File: includes/payroll_functions.php
// Centralized Payroll & HRIS helper functions
// ============================================================

/**
 * Legacy compat — kept with function_exists guard.
 * New code should use checkPermission() or requireXxxAccess() from global_permission.php.
 */
if (!function_exists('isPayrollAccess')) {
    function isPayrollAccess(): bool {
        if (!isLoggedIn()) return false;
        return checkPermission('payroll', 'can_access');
    }
}
/* Mesin HRIS (Modul 1-10). Dimuat di sini agar perhitungan PPh21 progresif
   tersedia bagi seluruh pemanggil payroll_functions.php. require_once aman
   dipanggil berulang, dan hris_functions.php sendiri sudah menjaga diri
   dengan function_exists() sehingga tidak pernah bentrok. */
require_once __DIR__ . '/hris_functions.php';

if (!function_exists('requirePayrollAccess')) {
    function requirePayrollAccess(): void {
        if (!isLoggedIn()) redirect(url('index.php'));
        if (!checkPermission('payroll', 'can_access')) redirectNoAccess();
    }
}

/* ============================================================
   OUTPUT HELPERS
   ============================================================ */

/**
 * Safe HTML output — prevent XSS, preserve special chars correctly.
 * Use this instead of echo $var directly.
 */
if (!function_exists('safeOutput')) {
    function safeOutput($str): string {
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Return month name string (English).
 */
if (!function_exists('getMonthName')) {
    function getMonthName(int $month): string {
        $names = [
            1=>'January',2=>'February',3=>'March',4=>'April',
            5=>'May',6=>'June',7=>'July',8=>'August',
            9=>'September',10=>'October',11=>'November',12=>'December'
        ];
        return $names[$month] ?? '';
    }
}

/* ============================================================
   EMPLOYEE FUNCTIONS
   ============================================================ */

/**
 * Generate unique employee code:
 *   ARWS-{YYMM birth_date}-{YYMMDD join_date}-{NNN sequential}
 *
 * Example: ARWS-0311-251108-001  (born 2003-11, joined 2025-11-08, 1st of that combo)
 *
 * The sequential number is scoped to the same (birth YYMM + join YYMMDD) tuple
 * so that codes remain meaningful even when many employees share the same
 * birth-month-and-join-date combination.
 *
 * @param mysqli  $conn
 * @param string  $birth_date  Y-m-d  (employee birth date)
 * @param string  $join_date   Y-m-d  (employee join date)
 * @return string              The new unique employee_code
 */
function generateEmployeeCode($conn, string $birth_date = '', string $join_date = ''): string {
    // Backward-compat: if no dates supplied, fall back to legacy EMP#### scheme
    if ($birth_date === '' || $join_date === '') {
        $stmt = $conn->prepare(
            "SELECT MAX(CAST(SUBSTRING(employee_code, 4) AS UNSIGNED)) AS max_num
             FROM employees WHERE employee_code LIKE 'EMP%'"
        );
        $stmt->execute();
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $next = ($row['max_num'] ?? 0) + 1;
        return 'EMP' . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    // Validate / parse the two dates
    $bt = strtotime($birth_date);
    $jt = strtotime($join_date);
    if (!$bt) $bt = time();
    if (!$jt) $jt = time();
    $birthYYMM   = date('ym', $bt);          // 2-digit year + 2-digit month
    $joinYYMMDD  = date('ymd', $jt);         // 2-digit year + 2-digit month + day

    $prefix = "ARWS-{$birthYYMM}-{$joinYYMMDD}-";

    // Find the next sequential number for this exact prefix
    $like = $prefix . '%';
    $stmt = $conn->prepare(
        "SELECT MAX(CAST(SUBSTRING(employee_code, ?) AS UNSIGNED)) AS max_num
         FROM employees WHERE employee_code LIKE ?"
    );
    $startPos = strlen($prefix) + 1;   // SQL SUBSTRING is 1-indexed
    $stmt->bind_param('is', $startPos, $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $next = ((int)($row['max_num'] ?? 0)) + 1;

    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/**
 * Get all active employees with their primary shift info.
 * Uses INDEX on employee_status for fast fetch.
 */
function getActiveEmployees($conn): array {
    $stmt = $conn->prepare(
        "SELECT e.id, e.employee_code, e.full_name, e.nick_name, e.department,
                e.department_id, e.position, e.position_id, e.shift_id,
                e.employee_status, e.employment_type, e.basic_salary, e.photo,
                es.name AS shift_name, es.start_time AS shift_start, es.end_time AS shift_end
         FROM employees e
         LEFT JOIN employee_shifts es ON e.shift_id = es.id
         WHERE e.employee_status = 'active'
         ORDER BY e.full_name ASC"
    );
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

/**
 * Get single employee by ID with full shift info.
 */
function getEmployee($conn, int $id): ?array {
    $stmt = $conn->prepare(
        "SELECT e.*, es.name AS shift_name, es.start_time AS shift_start_time,
                es.end_time AS shift_end_time, es.cross_midnight, es.color AS shift_color
         FROM employees e
         LEFT JOIN employee_shifts es ON e.shift_id = es.id
         WHERE e.id = ?"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* ============================================================
   UPLOAD HELPERS
   ============================================================ */

/**
 * Upload employee photo — returns ['filename'=>string] or ['error'=>string].
 */
function uploadEmployeePhoto(array $file): array {
    $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    if ($file['size'] > 5 * 1024 * 1024) return ['error' => 'File too large (max 5MB)'];
    if (!in_array($file['type'], $allowed, true)) return ['error' => 'Only JPG/JPEG/PNG/WEBP allowed'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'photo_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest     = __DIR__ . '/../uploads/employee/employee_photos/' . $filename;
    if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
    if (move_uploaded_file($file['tmp_name'], $dest)) return ['filename' => $filename];
    return ['error' => 'Upload failed'];
}

/**
 * Upload employee CV / document — returns ['filename'=>string] or ['error'=>string].
 */
function uploadEmployeeDocument(array $file): array {
    $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
    if ($file['size'] > 5 * 1024 * 1024) return ['error' => 'File too large (max 5MB)'];
    if (!in_array($file['type'], $allowed, true)) return ['error' => 'Only JPG/JPEG/PNG/PDF allowed'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'cv_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest     = __DIR__ . '/../uploads/employee/employee_document/' . $filename;
    if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
    if (move_uploaded_file($file['tmp_name'], $dest)) return ['filename' => $filename];
    return ['error' => 'Upload failed'];
}

/**
 * Upload general employee attachment (training, warning, document).
 */
function uploadEmployeeAttachment(array $file, string $subDir = 'attachments'): array {
    $allowed = ['image/jpeg','image/jpg','image/png','application/pdf'];
    if ($file['size'] > 10 * 1024 * 1024) return ['error' => 'File too large (max 10MB)'];
    if (!in_array($file['type'], $allowed, true)) return ['error' => 'Only JPG/PNG/PDF allowed'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = $subDir . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest     = __DIR__ . '/../uploads/employee/' . $subDir . '/' . $filename;
    if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
    if (move_uploaded_file($file['tmp_name'], $dest)) return ['filename' => $filename];
    return ['error' => 'Upload failed'];
}

/**
 * Upload attendance check-in/out photo — returns ['filename'=>string] or ['error'=>string].
 */
function uploadAttendancePhoto(array $file): array {
    $allowed = ['image/jpeg', 'image/jpg', 'image/png'];
    if ($file['size'] > 5 * 1024 * 1024) return ['error' => 'File too large (max 5MB)'];
    if (!in_array($file['type'], $allowed, true)) return ['error' => 'Only JPG/JPEG/PNG allowed'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'abs_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest     = __DIR__ . '/../uploads/employee/absence/' . $filename;
    if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
    if (move_uploaded_file($file['tmp_name'], $dest)) return ['filename' => $filename];
    return ['error' => 'Upload failed'];
}

/* ============================================================
   SHIFT FUNCTIONS
   ============================================================ */

/**
 * Get all active shifts ordered by start_time.
 */
function getActiveShifts($conn): array {
    $stmt = $conn->prepare(
        "SELECT id, name, code, start_time, end_time, break_minutes,
                cross_midnight, max_late_minutes, color, description, is_active
         FROM employee_shifts
         WHERE is_active = 1
         ORDER BY start_time ASC, name ASC"
    );
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

/**
 * Get shift schedule for an employee on a specific date.
 * Resolves: date-specific schedule first, then falls back to employee's default shift_id.
 *
 * @return array|null  shift record or null
 */
function getEmployeeShiftForDate($conn, int $employeeId, string $date): ?array {
    // 1. Check date-specific schedule — LEFT JOIN so is_off rows are included even when shift_id is NULL/0
    $stmt = $conn->prepare(
        "SELECT ess.shift_id, ess.is_off,
                es.name, es.start_time, es.end_time,
                es.break_minutes, es.cross_midnight, es.max_late_minutes, es.color
         FROM employee_shift_schedules ess
         LEFT JOIN employee_shifts es ON es.id = ess.shift_id AND ess.is_off = 0
         WHERE ess.employee_id = ? AND ess.schedule_date = ?
         LIMIT 1"
    );
    $stmt->bind_param('is', $employeeId, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return $row;

    // 2. Fall back to employee's default shift_id
    $stmt2 = $conn->prepare(
        "SELECT es.id AS shift_id, 0 AS is_off, es.name, es.start_time, es.end_time,
                es.break_minutes, es.cross_midnight, es.max_late_minutes, es.color
         FROM employees e
         JOIN employee_shifts es ON e.shift_id = es.id
         WHERE e.id = ? AND e.shift_id IS NOT NULL
         LIMIT 1"
    );
    $stmt2->bind_param('i', $employeeId);
    $stmt2->execute();
    $row2 = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    return $row2 ?: null;
}

/**
 * Get all shift schedules for an employee within a date range.
 * Returns array indexed by date string 'Y-m-d'.
 */
function getEmployeeScheduleForRange($conn, int $employeeId, string $startDate, string $endDate): array {
    $stmt = $conn->prepare(
        "SELECT ess.schedule_date, ess.shift_id, ess.is_off,
                es.name AS shift_name, es.start_time, es.end_time, es.color
         FROM employee_shift_schedules ess
         LEFT JOIN employee_shifts es ON es.id = ess.shift_id AND ess.is_off = 0
         WHERE ess.employee_id = ? AND ess.schedule_date BETWEEN ? AND ?
         ORDER BY ess.schedule_date ASC"
    );
    $stmt->bind_param('iss', $employeeId, $startDate, $endDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $indexed = [];
    foreach ($rows as $r) {
        $indexed[$r['schedule_date']] = $r;
    }
    return $indexed;
}

/**
 * Save shift schedule entry for an employee on a date.
 * Upserts: INSERT or UPDATE on duplicate employee_id+schedule_date.
 */
function saveEmployeeShiftSchedule($conn, int $employeeId, string $date, int $shiftId, int $isOff, ?string $notes, int $userId): array {
    // When is_off=1 the shift_id is irrelevant — store NULL to avoid FK violation
    $shiftIdOrNull = $isOff ? null : ($shiftId ?: null);
    /* created_by_name = SALINAN PERMANEN users.full_name saat jadwal
       ditulis. Kolom ini tanpa foreign key sehingga tidak ikut hilang saat
       akun users dihapus. Sengaja TIDAK ikut di-update pada duplicate key,
       supaya identitas pembuat pertama tetap terjaga. */
    $stmt = $conn->prepare(
        "INSERT INTO employee_shift_schedules
             (employee_id, schedule_date, shift_id, is_off, notes, created_by, created_by_name)
         VALUES (?, ?, ?, ?, ?, ?, (SELECT full_name FROM users WHERE id = ?))
         ON DUPLICATE KEY UPDATE
             shift_id = VALUES(shift_id),
             is_off   = VALUES(is_off),
             notes    = VALUES(notes)"
    );
    $stmt->bind_param('isiiisi', $employeeId, $date, $shiftIdOrNull, $isOff, $notes, $userId, $userId);
    if ($stmt->execute()) {
        $stmt->close();
        return ['success' => true];
    }
    $err = $conn->error;
    $stmt->close();
    return ['success' => false, 'message' => 'DB error: ' . $err];
}

/**
 * Delete shift schedule entry for an employee on a date.
 */
function deleteEmployeeShiftSchedule($conn, int $employeeId, string $date): bool {
    $stmt = $conn->prepare("DELETE FROM employee_shift_schedules WHERE employee_id = ? AND schedule_date = ?");
    $stmt->bind_param('is', $employeeId, $date);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Toggle shift active status.
 */
function shiftToggleStatus($conn, int $id, int $newStatus): array {
    $stmt = $conn->prepare("UPDATE employee_shifts SET is_active = ? WHERE id = ?");
    $stmt->bind_param('ii', $newStatus, $id);
    if ($stmt->execute()) {
        $stmt->close();
        logActivity('shift_toggle', "Shift ID $id set to " . ($newStatus ? 'active' : 'inactive'));
        return ['success' => true];
    }
    $err = $conn->error;
    $stmt->close();
    return ['success' => false, 'message' => $err];
}

/**
 * Delete shift — if linked to employees/attendance, sets inactive instead.
 */
function shiftDelete($conn, int $id, array $LANG): array {
    // Check linked employees
    /* Hanya karyawan berstatus AKTIF yang dihitung sebagai pemakai shift.
       Karyawan nonaktif / resign / diberhentikan tidak lagi menahan sebuah
       shift, sehingga shift yang benar-benar sudah tidak terpakai bisa
       dirapikan tanpa harus mengubah data karyawan lamanya lebih dulu.
       Baris karyawan itu sendiri TIDAK diubah dan TIDAK dihapus. */
    $stE = $conn->prepare("SELECT COUNT(*) AS c FROM employees WHERE shift_id = ? AND employee_status = 'active'");
    $stE->bind_param('i', $id);
    $stE->execute();
    $cE = (int)$stE->get_result()->fetch_assoc()['c'];
    $stE->close();

    // Check linked attendance
    $stA = $conn->prepare("SELECT COUNT(*) AS c FROM attendance WHERE shift_id = ?");
    $stA->bind_param('i', $id);
    $stA->execute();
    $cA = (int)$stA->get_result()->fetch_assoc()['c'];
    $stA->close();

    // Check schedules
    $stS = $conn->prepare("SELECT COUNT(*) AS c FROM employee_shift_schedules WHERE shift_id = ?");
    $stS->bind_param('i', $id);
    $stS->execute();
    $cS = (int)$stS->get_result()->fetch_assoc()['c'];
    $stS->close();

    if ($cE > 0 || $cA > 0 || $cS > 0) {
        // Set inactive
        $stU = $conn->prepare("UPDATE employee_shifts SET is_active = 0 WHERE id = ?");
        $stU->bind_param('i', $id);
        $stU->execute();
        $stU->close();
        logActivity('shift_deactivated', "Shift ID $id deactivated (has linked data)");
        return ['success' => true, 'action' => 'inactive', 'message' => $LANG['shift_set_inactive']];
    }

    $stD = $conn->prepare("DELETE FROM employee_shifts WHERE id = ?");
    $stD->bind_param('i', $id);
    if ($stD->execute() && $stD->affected_rows > 0) {
        $stD->close();
        logActivity('shift_deleted', "Shift ID $id permanently deleted");
        return ['success' => true, 'action' => 'deleted', 'message' => $LANG['shift_deleted']];
    }
    $err = $conn->error;
    $stD->close();
    return ['success' => false, 'message' => $err ?: ($LANG['error'])];
}

/**
 * Save (add or edit) a shift.
 * Validates uniqueness of name and code.
 */
function shiftSave($conn, string $action, array $data, array $LANG): array {
    $name         = trim($data['name']            ?? '');
    $code         = trim($data['code']            ?? '');
    $startTime    = trim($data['start_time']      ?? '');
    $endTime      = trim($data['end_time']        ?? '');
    $breakMin     = max(0, (int)($data['break_minutes']    ?? 0));
    $crossMid     = isset($data['cross_midnight']) ? 1 : 0;
    $maxLate      = max(0, (int)($data['max_late_minutes'] ?? 15));
    $color        = preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color'] ?? '') ? $data['color'] : '#6c757d';
    $description  = trim($data['description']     ?? '');
    $isActive     = isset($data['is_active']) ? 1 : 0;
    $editId       = (int)($data['edit_id']        ?? 0);

    if (!$name) return ['success' => false, 'message' => $LANG['shift_name_required']];
    if (!$code) return ['success' => false, 'message' => $LANG['shift_code_required']];

    // Uniqueness check for name
    $stN = $conn->prepare("SELECT id FROM employee_shifts WHERE name = ? AND id != ?");
    $stN->bind_param('si', $name, $editId);
    $stN->execute();
    if ($stN->get_result()->num_rows > 0) {
        $stN->close();
        return ['success' => false, 'message' => $LANG['shift_name_exists']];
    }
    $stN->close();

    // Uniqueness check for code
    $stC = $conn->prepare("SELECT id FROM employee_shifts WHERE code = ? AND id != ?");
    $stC->bind_param('si', $code, $editId);
    $stC->execute();
    if ($stC->get_result()->num_rows > 0) {
        $stC->close();
        return ['success' => false, 'message' => $LANG['shift_code_exists']];
    }
    $stC->close();

    $startN = $startTime ?: null;
    $endN   = $endTime   ?: null;
    $descN  = $description ?: null;

    if ($action === 'add') {
        $st = $conn->prepare(
            "INSERT INTO employee_shifts
                 (name, code, start_time, end_time, break_minutes, cross_midnight,
                  max_late_minutes, color, description, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $st->bind_param('ssssiiiisi', $name, $code, $startN, $endN, $breakMin, $crossMid, $maxLate, $color, $descN, $isActive);
        if ($st->execute()) {
            $st->close();
            logActivity('shift_add', "Added shift: $name");
            return ['success' => true, 'message' => $LANG['shift_added']];
        }
        $err = $conn->error;
        $st->close();
        return ['success' => false, 'message' => $err];
    }

    // edit
    $st = $conn->prepare(
        "UPDATE employee_shifts SET
             name=?, code=?, start_time=?, end_time=?, break_minutes=?, cross_midnight=?,
             max_late_minutes=?, color=?, description=?, is_active=?
         WHERE id=?"
    );
    $st->bind_param('ssssiiiisii', $name, $code, $startN, $endN, $breakMin, $crossMid, $maxLate, $color, $descN, $isActive, $editId);
    if ($st->execute()) {
        $st->close();
        logActivity('shift_edit', "Updated shift ID $editId: $name");
        return ['success' => true, 'message' => $LANG['shift_updated']];
    }
    $err = $conn->error;
    $st->close();
    return ['success' => false, 'message' => $err];
}

/* ============================================================
   ATTENDANCE FUNCTIONS
   ============================================================ */

/**
 * Get attendance record for an employee on a specific date.
 */
function getAttendance($conn, int $employee_id, string $date): ?array {
    $stmt = $conn->prepare(
        "SELECT a.*, es.name AS shift_name, es.start_time AS shift_start,
                es.cross_midnight, es.max_late_minutes
         FROM attendance a
         LEFT JOIN employee_shifts es ON a.shift_id = es.id
         WHERE a.employee_id = ? AND a.attendance_date = ?
         LIMIT 1"
    );
    $stmt->bind_param('is', $employee_id, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Calculate late minutes from shift start vs actual check-in.
 * Supports cross-midnight shifts.
 * Returns 0 if on time or missing data.
 *
 * @param string $shiftStart  HH:MM or HH:MM:SS
 * @param string $checkIn     HH:MM or HH:MM:SS
 * @param int    $maxLate     tolerance minutes (default 0)
 */
function calculateLateMinutes(string $shiftStart, string $checkIn, int $maxLate = 0): int {
    if (!$shiftStart || !$checkIn) return 0;
    // Normalize to HH:MM only
    $start = strtotime('1970-01-01 ' . $shiftStart);
    $cin   = strtotime('1970-01-01 ' . $checkIn);
    if ($start === false || $cin === false) return 0;
    $diff  = (int)(($cin - $start) / 60);
    if ($diff <= $maxLate) return 0;
    return max(0, $diff - $maxLate);
}

/**
 * Get attendance summary for an employee over a date range.
 */
function getAttendanceSummary($conn, int $employee_id, string $start_date, string $end_date): array {
    $stmt = $conn->prepare(
        "SELECT
            COUNT(*)                                      AS total_records,
            SUM(status = 'present')                       AS present_days,
            SUM(status = 'absent')                        AS absent_days,
            SUM(status = 'permission')                    AS permission_days,
            SUM(status = 'sick')                          AS sick_days,
            SUM(status = 'holiday')                       AS holiday_days,
            SUM(status = 'leave')                         AS leave_days,
            COALESCE(SUM(late_minutes), 0)                AS late_minutes_total,
            COALESCE(SUM(overtime_hours), 0)              AS overtime_hours_total,
            COUNT(CASE WHEN late_minutes > 0 THEN 1 END)  AS late_count
         FROM attendance
         WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?"
    );
    $stmt->bind_param('iss', $employee_id, $start_date, $end_date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?? [];
}

/* ============================================================
   HOLIDAYS
   ============================================================ */

/**
 * Get all holidays within a date range.
 * Returns array indexed by date string.
 */
function getHolidaysInRange($conn, string $startDate, string $endDate): array {
    $stmt = $conn->prepare(
        "SELECT holiday_date, name, is_national
         FROM holidays
         WHERE holiday_date BETWEEN ? AND ?
         ORDER BY holiday_date ASC"
    );
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $indexed = [];
    foreach ($rows as $r) {
        $indexed[$r['holiday_date']] = $r;
    }
    return $indexed;
}

/**
 * Check if a date is a holiday.
 */
function isHoliday($conn, string $date): bool {
    $stmt = $conn->prepare("SELECT id FROM holidays WHERE holiday_date = ? LIMIT 1");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $has = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $has;
}

/* ============================================================
   PAYROLL CALCULATION FUNCTIONS
   ============================================================ */

/**
 * Get overtime/tax/BPJS settings from DB.
 */
function getOvertimeSettings($conn): array {
    $stmt = $conn->prepare("SELECT * FROM overtime_settings WHERE id = 1 LIMIT 1");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return $row;
    return [
        'multiplier_hour_1'            => 1.5,
        'multiplier_hour_2plus'        => 2.0,
        'late_deduction_per_minute'    => 0,
        'bpjs_kesehatan_rate'          => 1.0,
        'bpjs_ketenagakerjaan_rate'    => 2.0,
        'pph21_rate'                   => 5.0,
        'ptkp_monthly'                 => 500000,
    ];
}

/**
 * Calculate overtime pay.
 * First hour at multiplier_hour_1×, subsequent at multiplier_hour_2plus×.
 * Standard divisor: 173 working hours per month.
 */
function calculateOvertimeAmount(float $basicSalary, float $overtimeHours, array $settings): float {
    if ($overtimeHours <= 0 || $basicSalary <= 0) return 0.0;
    $hourly   = $basicSalary / 173;
    $first    = min(1.0, $overtimeHours);
    $rest     = max(0.0, $overtimeHours - 1.0);
    return round(
        ($first * (float)$settings['multiplier_hour_1'] * $hourly) +
        ($rest  * (float)$settings['multiplier_hour_2plus'] * $hourly),
        2
    );
}

/**
 * Calculate working days in period (Mon–Sat by default, excluding holidays).
 */
function getWorkingDaysInPeriod(string $start, string $end, array $holidays = []): int {
    $days  = 0;
    $cur   = new DateTime($start);
    $last  = new DateTime($end);
    while ($cur <= $last) {
        $dow  = (int)$cur->format('N'); // 1=Mon … 7=Sun
        $ds   = $cur->format('Y-m-d');
        if ($dow < 7 && !isset($holidays[$ds])) {
            $days++;
        }
        $cur->modify('+1 day');
    }
    return $days;
}

/**
 * Calculate payroll for a single employee within a period.
 * Returns array ready for savePayroll().
 */
function calculatePayroll($conn, int $employee_id, int $period_id): array {
    // Load period
    $stP = $conn->prepare("SELECT * FROM payroll_periods WHERE id = ? LIMIT 1");
    $stP->bind_param('i', $period_id);
    $stP->execute();
    $period = $stP->get_result()->fetch_assoc();
    $stP->close();
    if (!$period) return ['success' => false, 'message' => 'Period not found'];

    // Load employee
    $emp = getEmployee($conn, $employee_id);
    if (!$emp) return ['success' => false, 'message' => 'Employee not found'];

    $settings   = getOvertimeSettings($conn);
    $startDate  = $period['start_date'];
    $endDate    = $period['end_date'];
    $holidays   = getHolidaysInRange($conn, $startDate, $endDate);
    $workingDays = getWorkingDaysInPeriod($startDate, $endDate, $holidays);

    // Attendance summary
    $att = getAttendanceSummary($conn, $employee_id, $startDate, $endDate);

    $presentDays    = (int)($att['present_days']        ?? 0);
    $absentDays     = (int)($att['absent_days']         ?? 0);
    $lateCount      = (int)($att['late_count']          ?? 0);
    $lateMinsTotal  = (int)($att['late_minutes_total']  ?? 0);
    $overtimeHours  = (float)($att['overtime_hours_total'] ?? 0);

    $basicSalary = (float)$emp['basic_salary'];

    // Load per-employee allowances
    $stA = $conn->prepare(
        "SELECT component_key, amount FROM employee_allowances WHERE employee_id = ?"
    );
    $stA->bind_param('i', $employee_id);
    $stA->execute();
    $allowanceRows = $stA->get_result()->fetch_all(MYSQLI_ASSOC);
    $stA->close();
    $ea = [];
    foreach ($allowanceRows as $ar) {
        $ea[$ar['component_key']] = (float)$ar['amount'];
    }

    $mealAllowance      = $ea['meal_allowance']      ?? 0.0;
    $transportAllowance = $ea['transport_allowance'] ?? 0.0;
    $bonus              = $ea['bonus']               ?? 0.0;
    $otherAllowance     = $ea['other_allowance']     ?? 0.0;
    $overtimeAmount     = calculateOvertimeAmount($basicSalary, $overtimeHours, $settings);

    $grossSalary = $basicSalary + $mealAllowance + $transportAllowance + $bonus + $otherAllowance + $overtimeAmount;

    // Deductions
    $absenceDeduction   = $workingDays > 0 ? round(($basicSalary / $workingDays) * $absentDays, 2) : 0.0;
    $lateDeduction      = round($lateMinsTotal * (float)$settings['late_deduction_per_minute'], 2);
    $bpjsKesehatan      = round($basicSalary * ((float)$settings['bpjs_kesehatan_rate'] / 100), 2);
    $bpjsKetker         = round($basicSalary * ((float)$settings['bpjs_ketenagakerjaan_rate'] / 100), 2);

    /* ── PPh21 ─────────────────────────────────────────────────────────
       Diutamakan tarif PROGRESIF BERJENJANG dari tabel tax_brackets +
       ptkp_status (Modul 4). Perhitungannya: bruto disetahunkan, dikurangi
       PTKP sesuai status karyawan, lalu dilapisi tarif berjenjang, dan
       hasilnya dibagi 12.

       Bila tabel tarif KOSONG atau migrasi belum dijalankan,
       hrisCalcPPh21Progressive() mengembalikan null dan perhitungan
       JATUH KEMBALI ke tarif datar lama persis seperti sebelumnya —
       sehingga tidak ada payroll yang berubah nilainya tanpa Anda
       mengisi tabel tarif lebih dulu. */
    $incomeTax = null;
    if (function_exists('hrisCalcPPh21Progressive')) {
        $ptkpCode = 'TK/0';
        if (function_exists('hrisHasColumn') && hrisHasColumn('employees', 'ptkp_code')) {
            $stP = $conn->prepare("SELECT ptkp_code FROM employees WHERE id = ? LIMIT 1");
            if ($stP) {
                $stP->bind_param('i', $employee_id);
                $stP->execute();
                $rp = $stP->get_result()->fetch_assoc();
                $stP->close();
                if (!empty($rp['ptkp_code'])) $ptkpCode = (string)$rp['ptkp_code'];
            }
        }
        $incomeTax = hrisCalcPPh21Progressive((float)$grossSalary, $ptkpCode);
    }
    if ($incomeTax === null) {
        // Cadangan: tarif datar lama, tidak diubah sedikit pun.
        $ptkp          = (float)$settings['ptkp_monthly'];
        $taxableIncome = max(0, $grossSalary - $ptkp);
        $incomeTax     = round($taxableIncome * ((float)$settings['pph21_rate'] / 100), 2);
    }

    // Kasbon deduction — sum approved cash advances not yet paid off
    $stK = $conn->prepare(
        "SELECT COALESCE(SUM(monthly_deduction), 0) AS total
         FROM cash_advances
         WHERE employee_id = ? AND status = 'approved'"
    );
    $stK->bind_param('i', $employee_id);
    $stK->execute();
    $kasbonDeduction = (float)($stK->get_result()->fetch_assoc()['total'] ?? 0);
    $stK->close();

    $otherDeduction  = $ea['other_deduction'] ?? 0.0;
    $totalDeduction  = $absenceDeduction + $lateDeduction + $bpjsKesehatan + $bpjsKetker + $incomeTax + $kasbonDeduction + $otherDeduction;
    $netSalary       = max(0, $grossSalary - $totalDeduction);

    return [
        'success'                       => true,
        'period_id'                     => $period_id,
        'employee_id'                   => $employee_id,
        'basic_salary'                  => $basicSalary,
        'meal_allowance'                => $mealAllowance,
        'transport_allowance'           => $transportAllowance,
        'overtime_hours'                => $overtimeHours,
        'overtime_amount'               => $overtimeAmount,
        'bonus'                         => $bonus,
        'other_allowance'               => $otherAllowance,
        'gross_salary'                  => $grossSalary,
        'working_days'                  => $workingDays,
        'present_days'                  => $presentDays,
        'absent_days'                   => $absentDays,
        'late_count'                    => $lateCount,
        'late_minutes_total'            => $lateMinsTotal,
        'absence_deduction'             => $absenceDeduction,
        'late_deduction'                => $lateDeduction,
        'bpjs_kesehatan_deduction'      => $bpjsKesehatan,
        'bpjs_ketenagakerjaan_deduction'=> $bpjsKetker,
        'income_tax'                    => $incomeTax,
        'kasbon_deduction'              => $kasbonDeduction,
        'other_deduction'               => $otherDeduction,
        'total_deduction'               => $totalDeduction,
        'net_salary'                    => $netSalary,
    ];
}

/**
 * Save (upsert) payroll record.
 */
function savePayroll($conn, array $data, int $user_id): array {
    // Check existing
    $stChk = $conn->prepare("SELECT id FROM payroll WHERE period_id = ? AND employee_id = ? LIMIT 1");
    $stChk->bind_param('ii', $data['period_id'], $data['employee_id']);
    $stChk->execute();
    $existing = $stChk->get_result()->fetch_assoc();
    $stChk->close();

    if ($existing) {
        $st = $conn->prepare(
            "UPDATE payroll SET
                basic_salary=?, meal_allowance=?, transport_allowance=?,
                overtime_hours=?, overtime_amount=?, bonus=?, other_allowance=?, gross_salary=?,
                working_days=?, present_days=?, absent_days=?, late_count=?, late_minutes_total=?,
                absence_deduction=?, late_deduction=?, bpjs_kesehatan_deduction=?,
                bpjs_ketenagakerjaan_deduction=?, income_tax=?, kasbon_deduction=?,
                other_deduction=?, total_deduction=?, net_salary=?
             WHERE id=?"
        );
        $st->bind_param(
            'ddddddddiiiiidddddddddi',
            $data['basic_salary'], $data['meal_allowance'], $data['transport_allowance'],
            $data['overtime_hours'], $data['overtime_amount'], $data['bonus'], $data['other_allowance'], $data['gross_salary'],
            $data['working_days'], $data['present_days'], $data['absent_days'], $data['late_count'], $data['late_minutes_total'],
            $data['absence_deduction'], $data['late_deduction'], $data['bpjs_kesehatan_deduction'],
            $data['bpjs_ketenagakerjaan_deduction'], $data['income_tax'], $data['kasbon_deduction'],
            $data['other_deduction'], $data['total_deduction'], $data['net_salary'],
            $existing['id']
        );
        if ($st->execute()) { $st->close(); return ['success' => true]; }
        $err = $conn->error; $st->close();
        return ['success' => false, 'message' => $err];
    }

    $st = $conn->prepare(
        "INSERT INTO payroll
            (period_id, employee_id, basic_salary, meal_allowance, transport_allowance,
             overtime_hours, overtime_amount, bonus, other_allowance, gross_salary,
             working_days, present_days, absent_days, late_count, late_minutes_total,
             absence_deduction, late_deduction, bpjs_kesehatan_deduction,
             bpjs_ketenagakerjaan_deduction, income_tax, kasbon_deduction,
             other_deduction, total_deduction, net_salary, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft')"
    );
    $st->bind_param(
        'iiddddddddiiiiiddddddddd',
        $data['period_id'], $data['employee_id'],
        $data['basic_salary'], $data['meal_allowance'], $data['transport_allowance'],
        $data['overtime_hours'], $data['overtime_amount'], $data['bonus'], $data['other_allowance'], $data['gross_salary'],
        $data['working_days'], $data['present_days'], $data['absent_days'], $data['late_count'], $data['late_minutes_total'],
        $data['absence_deduction'], $data['late_deduction'], $data['bpjs_kesehatan_deduction'],
        $data['bpjs_ketenagakerjaan_deduction'], $data['income_tax'], $data['kasbon_deduction'],
        $data['other_deduction'], $data['total_deduction'], $data['net_salary']
    );
    if ($st->execute()) { $st->close(); return ['success' => true]; }
    $err = $conn->error; $st->close();
    return ['success' => false, 'message' => $err];
}

/**
 * Get full payroll detail with employee and attendance.
 */
function getPayrollDetail($conn, int $payroll_id): ?array {
    $stP = $conn->prepare(
        "SELECT pr.*, pp.period_name, pp.period_year, pp.period_month, pp.start_date, pp.end_date
         FROM payroll pr
         JOIN payroll_periods pp ON pr.period_id = pp.id
         WHERE pr.id = ? LIMIT 1"
    );
    $stP->bind_param('i', $payroll_id);
    $stP->execute();
    $payroll = $stP->get_result()->fetch_assoc();
    $stP->close();
    if (!$payroll) return null;

    $employee = getEmployee($conn, (int)$payroll['employee_id']);

    // Per-employee allowances
    $stA = $conn->prepare(
        "SELECT ea.*, sc.component_name, sc.component_type
         FROM employee_allowances ea
         JOIN employee_salary_components sc ON ea.component_key = sc.component_key
         WHERE ea.employee_id = ?
         ORDER BY sc.sort_order"
    );
    $stA->bind_param('i', (int)$payroll['employee_id']);
    $stA->execute();
    $allowances = $stA->get_result()->fetch_all(MYSQLI_ASSOC);
    $stA->close();

    // Attendance summary for this period
    $att = getAttendanceSummary($conn, (int)$payroll['employee_id'], $payroll['start_date'], $payroll['end_date']);

    return [
        'payroll'    => $payroll,
        'employee'   => $employee,
        'allowances' => $allowances,
        'attendance' => $att,
    ];
}

/**
 * Send payslip email (basic implementation — configure SMTP externally).
 */
function sendPayslipEmail($conn, int $payroll_id): array {
    $data = getPayrollDetail($conn, $payroll_id);
    if (!$data) return ['success' => false, 'message' => 'Payroll not found'];
    $emp = $data['employee'];
    if (empty($emp['email'])) return ['success' => false, 'message' => 'Employee has no email'];
    $siteName  = defined('APP_NAME') ? APP_NAME : 'Company';
    $body      = buildPayslipEmailBody($data, $siteName);
    $subject   = "Payslip – " . getMonthName((int)$data['payroll']['period_month']) . ' ' . $data['payroll']['period_year'];
    $headers   = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (mail($emp['email'], $subject, $body, $headers)) {
        logActivity('payslip_email', "Sent payslip to " . $emp['email']);
        return ['success' => true, 'message' => 'Email sent to ' . $emp['email']];
    }
    return ['success' => false, 'message' => 'Failed to send email'];
}

/**
 * Build HTML body for payslip email.
 */
function buildPayslipEmailBody(array $data, string $siteName): string {
    $p   = $data['payroll'];
    $e   = $data['employee'];
    $per = getMonthName((int)$p['period_month']) . ' ' . $p['period_year'];
    $net = 'Rp ' . number_format((float)$p['net_salary'], 0, ',', '.');
    return <<<HTML
<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;max-width:600px;margin:auto">
<h2 style="background:#1a56db;color:#fff;padding:16px 24px;border-radius:8px 8px 0 0;margin:0">
  {$siteName} — Payslip {$per}</h2>
<div style="padding:24px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px">
  <p>Dear <strong>{$e['full_name']}</strong>,</p>
  <p>Please find your payslip for <strong>{$per}</strong>.</p>
  <table style="width:100%;border-collapse:collapse;margin:16px 0">
    <tr><td style="padding:8px;border-bottom:1px solid #e2e8f0">Basic Salary</td>
        <td style="padding:8px;border-bottom:1px solid #e2e8f0;text-align:right">Rp {$p['basic_salary']}</td></tr>
    <tr><td style="padding:8px;border-bottom:1px solid #e2e8f0">Total Deduction</td>
        <td style="padding:8px;border-bottom:1px solid #e2e8f0;text-align:right;color:#e53e3e">-Rp {$p['total_deduction']}</td></tr>
    <tr style="font-weight:bold"><td style="padding:8px">Net Salary</td>
        <td style="padding:8px;text-align:right;color:#276749">{$net}</td></tr>
  </table>
  <p style="color:#718096;font-size:0.85em">This is an automated email. Please do not reply.</p>
</div></body></html>
HTML;
}

/* ============================================================
   DEPARTMENT, POSITION, RELIGION — CRUD FUNCTIONS
   ============================================================ */

function departmentToggleStatus($conn, int $id, int $newStatus): array {
    $st = $conn->prepare("UPDATE departments SET is_active = ? WHERE id = ?");
    $st->bind_param('ii', $newStatus, $id);
    if ($st->execute()) { $st->close(); logActivity('dept_toggle', "Department ID $id set " . ($newStatus ? 'active' : 'inactive')); return ['success' => true]; }
    $err = $conn->error; $st->close();
    return ['success' => false, 'message' => $err];
}

function departmentDelete($conn, int $id, array $LANG): array {
    $stE = $conn->prepare("SELECT COUNT(*) AS c FROM employees WHERE department_id = ?");
    $stE->bind_param('i', $id); $stE->execute();
    $c = (int)$stE->get_result()->fetch_assoc()['c']; $stE->close();
    if ($c > 0) {
        $stU = $conn->prepare("UPDATE departments SET is_active = 0 WHERE id = ?");
        $stU->bind_param('i', $id); $stU->execute(); $stU->close();
        logActivity('dept_deactivated', "Department ID $id deactivated (in use)");
        return ['success' => true, 'action' => 'inactive', 'message' => $LANG['dept_set_inactive']];
    }
    $stD = $conn->prepare("DELETE FROM departments WHERE id = ?");
    $stD->bind_param('i', $id);
    if ($stD->execute() && $stD->affected_rows > 0) {
        $stD->close(); logActivity('dept_deleted', "Department ID $id deleted");
        return ['success' => true, 'action' => 'deleted', 'message' => $LANG['delete_success']];
    }
    $err = $conn->error; $stD->close();
    return ['success' => false, 'message' => $err];
}

function departmentAddOrEdit($conn, string $action, array $data, array $LANG): array {
    $name   = trim($data['name']        ?? '');
    $desc   = trim($data['description'] ?? '');
    $active = isset($data['is_active']) ? 1 : 0;
    $id     = (int)($data['edit_id']   ?? 0);
    if (!$name) return ['success' => false, 'message' => $LANG['department_name'] . ' is required'];

    // Uniqueness
    $stN = $conn->prepare("SELECT id FROM departments WHERE name = ? AND id != ?");
    $stN->bind_param('si', $name, $id); $stN->execute();
    if ($stN->get_result()->num_rows > 0) { $stN->close(); return ['success' => false, 'message' => 'Department name already exists']; }
    $stN->close();

    if ($action === 'add') {
        $st = $conn->prepare("INSERT INTO departments (name, description, is_active) VALUES (?,?,?)");
        $st->bind_param('ssi', $name, $desc, $active);
    } else {
        $st = $conn->prepare("UPDATE departments SET name=?, description=?, is_active=? WHERE id=?");
        $st->bind_param('ssii', $name, $desc, $active, $id);
    }
    if ($st->execute()) { $st->close(); logActivity('dept_' . $action, ($action === 'add' ? 'Added' : 'Updated') . " department: $name"); return ['success' => true, 'message' => $LANG['save_success']]; }
    $err = $conn->error; $st->close();
    return ['success' => false, 'message' => $err];
}

function positionToggleStatus($conn, int $id, int $newStatus): array {
    $st = $conn->prepare("UPDATE positions SET is_active = ? WHERE id = ?");
    $st->bind_param('ii', $newStatus, $id);
    if ($st->execute()) { $st->close(); return ['success' => true]; }
    $err = $conn->error; $st->close();
    return ['success' => false, 'message' => $err];
}

function positionDelete($conn, int $id, array $LANG): array {
    $stE = $conn->prepare("SELECT COUNT(*) AS c FROM employees WHERE position_id = ?");
    $stE->bind_param('i', $id); $stE->execute();
    $c = (int)$stE->get_result()->fetch_assoc()['c']; $stE->close();
    if ($c > 0) {
        $stU = $conn->prepare("UPDATE positions SET is_active = 0 WHERE id = ?");
        $stU->bind_param('i', $id); $stU->execute(); $stU->close();
        return ['success' => true, 'action' => 'inactive', 'message' => $LANG['pos_set_inactive']];
    }
    $stD = $conn->prepare("DELETE FROM positions WHERE id = ?");
    $stD->bind_param('i', $id);
    if ($stD->execute() && $stD->affected_rows > 0) { $stD->close(); return ['success' => true, 'action' => 'deleted', 'message' => $LANG['delete_success']]; }
    $err = $conn->error; $stD->close();
    return ['success' => false, 'message' => $err];
}

function positionAddOrEdit($conn, string $action, array $data, array $LANG): array {
    $name     = trim($data['name']          ?? '');
    $deptId   = !empty($data['department_id']) ? (int)$data['department_id'] : null;
    $desc     = trim($data['description']   ?? '');
    $active   = isset($data['is_active']) ? 1 : 0;
    $id       = (int)($data['edit_id']     ?? 0);
    if (!$name) return ['success' => false, 'message' => 'Position name is required'];

    $stN = $conn->prepare("SELECT id FROM positions WHERE name = ? AND id != ?");
    $stN->bind_param('si', $name, $id); $stN->execute();
    if ($stN->get_result()->num_rows > 0) { $stN->close(); return ['success' => false, 'message' => 'Position name already exists']; }
    $stN->close();

    if ($action === 'add') {
        $st = $conn->prepare("INSERT INTO positions (name, department_id, description, is_active) VALUES (?,?,?,?)");
        $st->bind_param('sisi', $name, $deptId, $desc, $active);
    } else {
        $st = $conn->prepare("UPDATE positions SET name=?, department_id=?, description=?, is_active=? WHERE id=?");
        $st->bind_param('sisii', $name, $deptId, $desc, $active, $id);
    }
    if ($st->execute()) { $st->close(); return ['success' => true, 'message' => $LANG['save_success']]; }
    $err = $conn->error; $st->close();
    return ['success' => false, 'message' => $err];
}

function religionToggleStatus($conn, int $id, int $newStatus): array {
    $st = $conn->prepare("UPDATE religions SET is_active = ? WHERE id = ?");
    $st->bind_param('ii', $newStatus, $id);
    if ($st->execute()) { $st->close(); return ['success' => true]; }
    $err = $conn->error; $st->close();
    return ['success' => false, 'message' => $err];
}

function religionDelete($conn, int $id, array $LANG): array {
    $stE = $conn->prepare("SELECT COUNT(*) AS c FROM employees WHERE religion_id = ?");
    $stE->bind_param('i', $id); $stE->execute();
    $c = (int)$stE->get_result()->fetch_assoc()['c']; $stE->close();
    if ($c > 0) {
        $stU = $conn->prepare("UPDATE religions SET is_active = 0 WHERE id = ?");
        $stU->bind_param('i', $id); $stU->execute(); $stU->close();
        return ['success' => true, 'action' => 'inactive', 'message' => $LANG['religion_set_inactive']];
    }
    $stD = $conn->prepare("DELETE FROM religions WHERE id = ?");
    $stD->bind_param('i', $id);
    if ($stD->execute() && $stD->affected_rows > 0) { $stD->close(); return ['success' => true, 'action' => 'deleted', 'message' => $LANG['religion_deleted']]; }
    $err = $conn->error; $stD->close();
    return ['success' => false, 'message' => $err];
}

function religionAddOrEdit($conn, string $action, array $data, array $LANG): array {
    $name   = trim($data['name']        ?? '');
    $active = isset($data['is_active']) ? 1 : 0;
    $id     = (int)($data['edit_id']   ?? 0);
    if (!$name) return ['success' => false, 'message' => $LANG['religion_name_required']];
    $stN = $conn->prepare("SELECT id FROM religions WHERE name = ? AND id != ?");
    $stN->bind_param('si', $name, $id); $stN->execute();
    if ($stN->get_result()->num_rows > 0) { $stN->close(); return ['success' => false, 'message' => $LANG['religion_name_exists']]; }
    $stN->close();
    if ($action === 'add') {
        $st = $conn->prepare("INSERT INTO religions (name, is_active) VALUES (?,?)");
        $st->bind_param('si', $name, $active);
    } else {
        $st = $conn->prepare("UPDATE religions SET name=?, is_active=? WHERE id=?");
        $st->bind_param('sii', $name, $active, $id);
    }
    if ($st->execute()) { $st->close(); return ['success' => true, 'message' => $action === 'add' ? ($LANG['religion_added']) : ($LANG['religion_updated'])]; }
    $err = $conn->error; $st->close();
    return ['success' => false, 'message' => $err];
}

/* ============================================================
   CASH ADVANCE FUNCTIONS
   ============================================================ */

function cashAdvanceGetList($conn, int $filterEmp = 0, string $filterStatus = '', string $search = '', int $limit = 10, int $offset = 0): array {
    $where = '1=1'; $params = []; $types = '';
    if ($filterEmp > 0)       { $where .= ' AND ca.employee_id = ?';     $params[] = $filterEmp;    $types .= 'i'; }
    if ($filterStatus !== '')  { $where .= ' AND ca.status = ?';          $params[] = $filterStatus; $types .= 's'; }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where .= ' AND (e.full_name LIKE ? OR e.employee_code LIKE ?)';
        $params[] = $like; $params[] = $like; $types .= 'ss';
    }

    $stCount = $conn->prepare("SELECT COUNT(*) FROM cash_advances ca JOIN employees e ON ca.employee_id = e.id WHERE $where");
    if ($params) $stCount->bind_param($types, ...$params);
    $stCount->execute(); $stCount->bind_result($total); $stCount->fetch(); $stCount->close();

    $stData = $conn->prepare(
        "SELECT ca.id, ca.employee_id, ca.request_date, ca.amount, ca.repayment_months,
                ca.monthly_deduction, ca.remaining_balance, ca.reason, ca.status,
                ca.approved_by, ca.approved_at, ca.notes, ca.created_at,
                e.full_name, e.employee_code,
                ca.approved_by_name
         FROM cash_advances ca
         JOIN employees e ON ca.employee_id = e.id
         WHERE $where
         ORDER BY ca.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $allParams = array_merge($params, [$limit, $offset]);
    $stData->bind_param($types . 'ii', ...$allParams);
    $stData->execute();
    $data = $stData->get_result()->fetch_all(MYSQLI_ASSOC);
    $stData->close();

    return ['data' => $data, 'total' => (int)$total];
}

function cashAdvanceSave($conn, array $postData, array $LANG): array {
    $id          = (int)($postData['id']               ?? 0);
    $empId       = (int)($postData['employee_id']      ?? 0);
    $reqDate     = trim($postData['request_date']      ?? date('Y-m-d'));
    $amount      = (float)($postData['amount']         ?? 0);
    $repayMonths = max(1, (int)($postData['repayment_months'] ?? 1));
    $monthly     = round($amount / $repayMonths, 2);
    $reason      = trim($postData['reason']            ?? '');
    $notes       = trim($postData['notes']             ?? '');
    $status      = $postData['status']                 ?? 'pending';

    $allowedStatus = ['pending', 'approved', 'rejected', 'paid_off'];
    if (!in_array($status, $allowedStatus, true)) $status = 'pending';

    if ($empId <= 0)  return ['success' => false, 'message' => $LANG['employee_required']];
    if ($amount <= 0) return ['success' => false, 'message' => $LANG['amount_required']];

    if ($id > 0) {
        $st = $conn->prepare("UPDATE cash_advances SET employee_id=?, request_date=?, amount=?, repayment_months=?, monthly_deduction=?, reason=?, notes=?, status=? WHERE id=?");
        $st->bind_param('isdidsssi', $empId, $reqDate, $amount, $repayMonths, $monthly, $reason, $notes, $status, $id);
        if ($st->execute()) { $st->close(); logActivity('kasbon_edit', "Updated cash advance ID: $id"); return ['success' => true, 'message' => $LANG['save_success']]; }
        $err = $conn->error; $st->close();
        return ['success' => false, 'message' => 'DB error: ' . $err];
    }

    $remaining = $amount;
    $st = $conn->prepare("INSERT INTO cash_advances (employee_id, request_date, amount, repayment_months, monthly_deduction, remaining_balance, reason, notes, status) VALUES (?,?,?,?,?,?,?,?,?)");
    $st->bind_param('isdiddsss', $empId, $reqDate, $amount, $repayMonths, $monthly, $remaining, $reason, $notes, $status);
    if ($st->execute()) { $st->close(); logActivity('kasbon_add', "Added cash advance for emp ID: $empId"); return ['success' => true, 'message' => $LANG['save_success']]; }
    $err = $conn->error; $st->close();
    return ['success' => false, 'message' => 'DB error: ' . $err];
}

function cashAdvanceApprove($conn, int $id, int $userId, array $LANG): array {
    /* *_name = SALINAN PERMANEN users.full_name saat baris ditulis; tanpa
       foreign key sehingga tidak hilang saat akun users dihapus. */
    $st = $conn->prepare(
        "UPDATE cash_advances
            SET status='approved',
                approved_by=?,
                approved_by_name=(SELECT full_name FROM users WHERE id = ?),
                approved_at=NOW()
          WHERE id=? AND status='pending'"
    );
    $st->bind_param('iii', $userId, $userId, $id);
    if ($st->execute() && $st->affected_rows > 0) { $st->close(); logActivity('kasbon_approve', "Approved cash advance ID: $id"); return ['success' => true, 'message' => $LANG['save_success']]; }
    $st->close();
    return ['success' => false, 'message' => $LANG['ca_already_processed']];
}

function cashAdvanceDelete($conn, int $id, array $LANG): array {
    $st = $conn->prepare("DELETE FROM cash_advances WHERE id=? AND status='pending'");
    $st->bind_param('i', $id);
    if ($st->execute() && $st->affected_rows > 0) { $st->close(); logActivity('kasbon_delete', "Deleted cash advance ID: $id"); return ['success' => true, 'message' => $LANG['delete_success']]; }
    $st->close();
    return ['success' => false, 'message' => $LANG['ca_cannot_delete']];
}

/* ============================================================
   LEAVE REQUEST FUNCTIONS
   ============================================================ */

function leaveRequestGetList($conn, int $filterEmp = 0, string $filterStatus = '', string $filterType = '', string $filterMonth = '', string $search = '', int $limit = 10, int $offset = 0): array {
    $where = '1=1'; $params = []; $types = '';
    if ($filterEmp > 0)       { $where .= ' AND lr.employee_id = ?';                        $params[] = $filterEmp;    $types .= 'i'; }
    if ($filterStatus !== '')  { $where .= ' AND lr.status = ?';                             $params[] = $filterStatus; $types .= 's'; }
    if ($filterType   !== '')  { $where .= ' AND lr.leave_type = ?';                         $params[] = $filterType;   $types .= 's'; }
    if ($filterMonth  !== '')  { $where .= ' AND DATE_FORMAT(lr.start_date, "%Y-%m") = ?';   $params[] = $filterMonth;  $types .= 's'; }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where .= ' AND (e.full_name LIKE ? OR e.employee_code LIKE ?)';
        $params[] = $like; $params[] = $like; $types .= 'ss';
    }

    $stCount = $conn->prepare("SELECT COUNT(*) FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id WHERE $where");
    if ($params) $stCount->bind_param($types, ...$params);
    $stCount->execute(); $stCount->bind_result($total); $stCount->fetch(); $stCount->close();

    $stData = $conn->prepare(
        "SELECT lr.id, lr.employee_id, lr.leave_type, lr.start_date, lr.end_date,
                lr.total_days, lr.reason, lr.status, lr.approved_by, lr.approved_at,
                lr.rejection_reason, lr.created_at,
                e.full_name, e.employee_code,
                lr.approved_by_name
         FROM leave_requests lr
         JOIN employees e  ON lr.employee_id = e.id
         WHERE $where
         ORDER BY lr.created_at DESC, lr.id DESC
         LIMIT ? OFFSET ?"
    );
    $allParams = array_merge($params, [$limit, $offset]);
    $stData->bind_param($types . 'ii', ...$allParams);
    $stData->execute();
    $data = $stData->get_result()->fetch_all(MYSQLI_ASSOC);
    $stData->close();

    return ['data' => $data, 'total' => (int)$total];
}

function leaveRequestSave($conn, array $postData, array $LANG): array {
    $id     = (int)($postData['id']          ?? 0);
    $empId  = (int)($postData['employee_id'] ?? 0);
    $type   = trim($postData['leave_type']   ?? 'annual');
    $start  = trim($postData['start_date']   ?? '');
    $end    = trim($postData['end_date']     ?? '');
    $reason = trim($postData['reason']       ?? '');
    $status = trim($postData['status']       ?? 'pending');

    $allowedTypes  = ['annual','sick','personal','maternity','paternity','other'];
    $allowedStatus = ['pending','approved','rejected'];
    if (!in_array($type,   $allowedTypes,  true)) $type   = 'annual';
    if (!in_array($status, $allowedStatus, true)) $status = 'pending';

    if ($empId <= 0)   return ['success' => false, 'message' => $LANG['lr_employee_required']];
    if ($start === '') return ['success' => false, 'message' => $LANG['lr_start_required']];
    if ($end   === '') return ['success' => false, 'message' => $LANG['lr_end_required']];
    if ($end < $start) return ['success' => false, 'message' => $LANG['lr_end_before_start']];

    $d1        = new DateTime($start);
    $d2        = new DateTime($end);
    $totalDays = (int)$d1->diff($d2)->days + 1;

    if ($id > 0) {
        $st = $conn->prepare("UPDATE leave_requests SET employee_id=?, leave_type=?, start_date=?, end_date=?, total_days=?, reason=?, status=? WHERE id=?");
        $st->bind_param('isssissi', $empId, $type, $start, $end, $totalDays, $reason, $status, $id);
        if ($st->execute()) { $st->close(); logActivity('leave_edit', "Updated leave request ID: $id"); return ['success' => true, 'message' => $LANG['lr_updated']]; }
        $err = $conn->error; $st->close();
        return ['success' => false, 'message' => 'DB error: ' . $err];
    }

    $st = $conn->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, total_days, reason, status) VALUES (?,?,?,?,?,?,?)");
    $st->bind_param('isssiis', $empId, $type, $start, $end, $totalDays, $reason, $status);
    if ($st->execute()) { $st->close(); logActivity('leave_add', "Added leave request for emp ID: $empId"); return ['success' => true, 'message' => $LANG['lr_added']]; }
    $err = $conn->error; $st->close();
    return ['success' => false, 'message' => 'DB error: ' . $err];
}

function leaveRequestApprove($conn, int $id, int $userId, array $LANG): array {
    /* *_name = SALINAN PERMANEN users.full_name saat baris ditulis; tanpa
       foreign key sehingga tidak hilang saat akun users dihapus. */
    $st = $conn->prepare(
        "UPDATE leave_requests
            SET status='approved',
                approved_by=?,
                approved_by_name=(SELECT full_name FROM users WHERE id = ?),
                approved_at=NOW()
          WHERE id=? AND status='pending'"
    );
    $st->bind_param('iii', $userId, $userId, $id);
    if ($st->execute() && $st->affected_rows > 0) { $st->close(); logActivity('leave_approve', "Approved leave ID: $id"); return ['success' => true, 'message' => $LANG['lr_approve_success']]; }
    $st->close();
    return ['success' => false, 'message' => $LANG['error']];
}

function leaveRequestReject($conn, int $id, int $userId, string $rejectionReason, array $LANG): array {
    $rejectionReason = trim($rejectionReason);
    /* *_name = SALINAN PERMANEN users.full_name saat baris ditulis; tanpa
       foreign key sehingga tidak hilang saat akun users dihapus. */
    $st = $conn->prepare(
        "UPDATE leave_requests
            SET status='rejected',
                approved_by=?,
                approved_by_name=(SELECT full_name FROM users WHERE id = ?),
                approved_at=NOW(),
                rejection_reason=?
          WHERE id=? AND status='pending'"
    );
    $st->bind_param('iisi', $userId, $userId, $rejectionReason, $id);
    if ($st->execute() && $st->affected_rows > 0) { $st->close(); logActivity('leave_reject', "Rejected leave ID: $id"); return ['success' => true, 'message' => $LANG['lr_reject_success']]; }
    $st->close();
    return ['success' => false, 'message' => $LANG['error']];
}

function leaveRequestDelete($conn, int $id, array $LANG): array {
    $st = $conn->prepare("DELETE FROM leave_requests WHERE id=? AND status='pending'");
    $st->bind_param('i', $id);
    if ($st->execute() && $st->affected_rows > 0) { $st->close(); logActivity('leave_delete', "Deleted leave ID: $id"); return ['success' => true, 'message' => $LANG['lr_deleted']]; }
    $st->close();
    return ['success' => false, 'message' => $LANG['lr_cannot_delete']];
}

/* ============================================================
   LOGACTIVITY FALLBACK (guard — main definition is in config.php)
   ============================================================ */
if (!function_exists('logActivity')) {
    function logActivity($action, $description = null): void {}
}
