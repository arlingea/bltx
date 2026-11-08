<?php
/* ═════════════════════════════════════════════════════════════════════════
 * includes/kpi_functions.php
 * ────────────────────────────────────────────────────────────────────────
 * KPI Employee feature — helper functions.
 *
 * Tables expected (run migrations/2026_kpi_employee.sql first):
 *   - kpi_categories
 *   - kpi_indicators
 *   - kpi_periods
 *   - kpi_evaluations
 *   - kpi_scores
 *
 * The feature degrades gracefully if tables don't exist (see kpi_tablesExist).
 * ═════════════════════════════════════════════════════════════════════════ */

if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

/** Check if KPI tables exist — used to show "setup required" message. */
function kpi_tablesExist(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $res = @$conn->query("SHOW TABLES LIKE 'kpi_evaluations'");
        $cached = ($res && $res->num_rows > 0);
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/** CSRF token for KPI forms. */
/* RONDE PENYATUAN CSRF — fungsi di bawah kini hanya MENERUSKAN.

   Kunci sesi lama 'kpi_csrf' tidak dipakai lagi. Token, cara pembentukan,
   dan cara pembandingannya (hash_equals) sekarang satu untuk seluruh sistem:
   bltx_csrf_token() / bltx_csrf_valid() di includes/global_permission.php.

   Nama fungsi dan tanda tangannya SENGAJA tidak diubah, sehingga tidak satu
   pun pemanggil di seluruh modul ini perlu disentuh — pola yang sama dengan
   penyatuan _dbCan() di Tahap 0.1.

   Tidak ada jaring pengaman function_exists di sini, dan itu disengaja.
   Kalau global_permission.php suatu saat tidak termuat, fatal error yang
   nyaring jauh lebih baik daripada diam-diam kembali ke token lama —
   formulir akan dibentuk dengan satu token lalu diperiksa dengan token lain,
   dan gagalnya tanpa penjelasan. Seluruh jalur pemanggil sudah diverifikasi
   memuat global_permission.php pada ronde prasyarat. */
function kpi_csrfToken(): string {
    return bltx_csrf_token();
}
function kpi_csrfVerify(?string $token): bool {
    // !empty($token) dipertahankan: tanda tangannya menerima null.
    return !empty($token) && bltx_csrf_valid($token);
}

/** List active KPI indicators with category info. */
function kpi_getIndicators(mysqli $conn, bool $activeOnly = true): array {
    $sql = "SELECT i.id, i.name, i.description, i.weight, i.target_value, i.target_unit,
                   i.scoring_type, i.is_active,
                   c.id AS category_id, c.name AS category_name, c.display_order AS cat_order
            FROM kpi_indicators i
            JOIN kpi_categories c ON c.id = i.category_id";
    if ($activeOnly) $sql .= " WHERE i.is_active = 1 AND c.is_active = 1";
    $sql .= " ORDER BY c.display_order ASC, c.id ASC, i.id ASC";
    $res = $conn->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** List KPI categories. */
function kpi_getCategories(mysqli $conn, bool $activeOnly = true): array {
    $sql = "SELECT id, name, description, weight, display_order, is_active FROM kpi_categories";
    if ($activeOnly) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY display_order ASC, id ASC";
    $res = $conn->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** List KPI evaluation periods. */
function kpi_getPeriods(mysqli $conn, ?string $status = null): array {
    $sql = "SELECT id, name, period_type, start_date, end_date, status, notes FROM kpi_periods";
    if ($status) {
        $sql .= " WHERE status = ?";
        $st = $conn->prepare($sql . " ORDER BY start_date DESC");
        $st->bind_param('s', $status);
        $st->execute();
        $res = $st->get_result();
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    }
    $res = $conn->query($sql . " ORDER BY start_date DESC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** Get one period by ID. */
function kpi_getPeriod(mysqli $conn, int $id): ?array {
    $st = $conn->prepare("SELECT * FROM kpi_periods WHERE id = ? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** Get evaluations for a period, with employee info. */
function kpi_getEvaluations(mysqli $conn, int $periodId, ?int $employeeId = null): array {
    if ($employeeId) {
        $sql = "SELECT ev.*, e.full_name AS employee_name, e.employee_code AS nip, e.position_id,
                       p.name AS position_name, d.name AS department_name,
                       ev.evaluator_name
                FROM kpi_evaluations ev
                JOIN employees e ON e.id = ev.employee_id
                LEFT JOIN positions p ON p.id = e.position_id
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE ev.period_id = ? AND ev.employee_id = ?
                LIMIT 1";
        $st = $conn->prepare($sql);
        $st->bind_param('ii', $periodId, $employeeId);
    } else {
        $sql = "SELECT ev.*, e.full_name AS employee_name, e.employee_code AS nip, e.position_id,
                       p.name AS position_name, d.name AS department_name,
                       ev.evaluator_name
                FROM kpi_evaluations ev
                JOIN employees e ON e.id = ev.employee_id
                LEFT JOIN positions p ON p.id = e.position_id
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE ev.period_id = ?
                ORDER BY ev.total_score DESC, e.full_name ASC";
        $st = $conn->prepare($sql);
        $st->bind_param('i', $periodId);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Get individual scores for an evaluation. */
function kpi_getScores(mysqli $conn, int $evaluationId): array {
    $sql = "SELECT s.id, s.indicator_id, s.score, s.actual_value, s.notes,
                   i.name AS indicator_name, i.scoring_type, i.weight, i.target_value, i.target_unit,
                   c.name AS category_name, c.display_order AS cat_order
            FROM kpi_scores s
            JOIN kpi_indicators i ON i.id = s.indicator_id
            JOIN kpi_categories c ON c.id = i.category_id
            WHERE s.evaluation_id = ?
            ORDER BY c.display_order ASC, i.id ASC";
    $st = $conn->prepare($sql);
    $st->bind_param('i', $evaluationId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Calculate weighted total score from individual indicator scores.
 *  $scoreInputs is [indicator_id => 0-100 score]
 *  Returns weighted total (0-100). */
function kpi_calculateTotal(array $scoreInputs, array $indicators): float {
    $totalWeight = 0.0;
    $weightedSum = 0.0;
    foreach ($indicators as $ind) {
        $w = (float)$ind['weight'];
        $s = isset($scoreInputs[$ind['id']]) ? (float)$scoreInputs[$ind['id']] : 0.0;
        $s = max(0.0, min(100.0, $s));   // clamp
        $totalWeight += $w;
        $weightedSum += $s * $w;
    }
    if ($totalWeight <= 0) return 0.0;
    return round($weightedSum / $totalWeight, 2);
}

/** Letter grade from total score. */
function kpi_grade(float $score): string {
    if ($score >= 90) return 'A';
    if ($score >= 80) return 'B';
    if ($score >= 70) return 'C';
    if ($score >= 60) return 'D';
    return 'E';
}

/** Save (insert or update) an evaluation + its scores in one transaction.
 *  $scoreInputs = [indicator_id => ['score' => 0-100, 'actual_value' => optional, 'notes' => '']] */
function kpi_saveEvaluation(
    mysqli $conn,
    int $employeeId,
    int $periodId,
    int $evaluatorId,
    array $scoreInputs,
    string $comments = '',
    string $status = 'draft'
): array {
    $indicators = kpi_getIndicators($conn, true);
    if (empty($indicators)) {
        return ['success' => false, 'message' => 'No KPI indicators configured.'];
    }

    // Compute weighted total
    $scoresFlat = [];
    foreach ($scoreInputs as $iid => $row) {
        $scoresFlat[$iid] = is_array($row) ? (float)($row['score'] ?? 0) : (float)$row;
    }
    $total = kpi_calculateTotal($scoresFlat, $indicators);
    $grade = kpi_grade($total);

    $conn->begin_transaction();
    try {
        // Upsert evaluation
        $existing = $conn->prepare("SELECT id FROM kpi_evaluations WHERE employee_id = ? AND period_id = ? LIMIT 1");
        $existing->bind_param('ii', $employeeId, $periodId);
        $existing->execute();
        $row = $existing->get_result()->fetch_assoc();
        $existing->close();

        if ($row) {
            $evalId = (int)$row['id'];
            /* evaluator_name = SALINAN PERMANEN users.full_name saat evaluasi
               disimpan; kolom ini tanpa foreign key sehingga nama evaluator
               tidak hilang saat akun users-nya dihapus. */
            $up = $conn->prepare("UPDATE kpi_evaluations SET evaluator_id=?, evaluator_name=(SELECT full_name FROM users WHERE id = ?), total_score=?, grade=?, comments=?, status=?, evaluated_at=NOW() WHERE id=?");
            $up->bind_param('iidsssi', $evaluatorId, $evaluatorId, $total, $grade, $comments, $status, $evalId);
            $up->execute();
            $up->close();
        } else {
            $ins = $conn->prepare("INSERT INTO kpi_evaluations (employee_id, period_id, evaluator_id, evaluator_name, total_score, grade, comments, status, evaluated_at) VALUES (?, ?, ?, (SELECT full_name FROM users WHERE id = ?), ?, ?, ?, ?, NOW())");
            $ins->bind_param('iiiidsss', $employeeId, $periodId, $evaluatorId, $evaluatorId, $total, $grade, $comments, $status);
            $ins->execute();
            $evalId = $conn->insert_id;
            $ins->close();
        }

        // Replace scores
        $del = $conn->prepare("DELETE FROM kpi_scores WHERE evaluation_id = ?");
        $del->bind_param('i', $evalId);
        $del->execute();
        $del->close();

        $insScore = $conn->prepare("INSERT INTO kpi_scores (evaluation_id, indicator_id, score, actual_value, notes) VALUES (?, ?, ?, ?, ?)");
        foreach ($scoreInputs as $iid => $row) {
            $iidInt   = (int)$iid;
            $scoreVal = is_array($row) ? (float)($row['score'] ?? 0) : (float)$row;
            $actual   = is_array($row) ? (isset($row['actual_value']) && $row['actual_value'] !== '' ? (float)$row['actual_value'] : null) : null;
            $notes    = is_array($row) ? (string)($row['notes'] ?? '') : '';
            $insScore->bind_param('iidds', $evalId, $iidInt, $scoreVal, $actual, $notes);
            $insScore->execute();
        }
        $insScore->close();

        $conn->commit();
        return ['success' => true, 'evaluation_id' => $evalId, 'total_score' => $total, 'grade' => $grade];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
