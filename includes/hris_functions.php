<?php
/* =====================================================================
   includes/hris_functions.php

   MESIN BERSAMA UNTUK 10 MODUL HR & PAYROLL BARU

   Berkas ini sengaja dibuat sebagai SATU mesin generik, bukan sepuluh
   halaman yang menyalin logika yang sama. Alasannya:
     - satu perbaikan berlaku untuk seluruh modul,
     - tampilan, ukuran tombol, toast, dan modal otomatis seragam,
     - menambah modul baru di masa depan cukup menulis satu berkas tab
       tipis berisi konfigurasi, bukan 300 baris CRUD lagi.

   ISI:
     A. Pengaman & utilitas    — CSRF, sanitasi, toast, pencatatan log
     B. Mesin CRUD generik     — hrisCrudHandle() + hrisCrudRender()
     C. Modul 3  Approval      — hrisApprovalResolveSteps() dkk
     D. Modul 4  PPh21         — hrisCalcPPh21Progressive()
     E. Modul 8  Import absen  — hrisParseAttendanceCsv()
     F. Modul 9  Email slip    — hrisQueuePayslipEmail()
     G. Modul 10 Self-service  — hrisCurrentEmployee()

   ATURAN YANG DIPATUHI SELURUH BERKAS INI:
     - seluruh query memakai prepared statement, tanpa perkecualian
     - setiap tabel yang menyimpan users.id SELALU menulis kolom nama
       permanen (*_name) pada saat baris ditulis
     - tidak ada satu pun DELETE terhadap tabel di luar tabel modulnya
     - seluruh teks lewat $LANG, tidak ada string yang dipaku
     - seluruh nominal lewat formatCurrency(), tidak ada 'Rp' dipaku
   ===================================================================== */

if (!defined('APP_NAME')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

require_once __DIR__ . '/global_permission.php';

/* =====================================================================
   A. PENGAMAN & UTILITAS
   ===================================================================== */

/** Token CSRF halaman saat ini; dibuat sekali per sesi. */
if (!function_exists('hrisCsrfToken')) {
    function hrisCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/** Bandingkan token POST dengan token sesi memakai hash_equals. */
if (!function_exists('hrisCsrfValid')) {
    function hrisCsrfValid(): bool {
        $posted = $_POST['csrf_token'] ?? '';
        return $posted !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $posted);
    }
}

/**
 * Ambil nilai POST apa adanya, TANPA htmlspecialchars.
 *
 * Ini disengaja. Penyaringan dilakukan saat MENAMPILKAN (hrisOut), bukan
 * saat menyimpan, supaya tanda baca seperti ' " & < > dan baris baru
 * tersimpan utuh di database dan tidak pernah berubah menjadi &amp;
 * atau &#039; di dalam data.
 */
if (!function_exists('hrisIn')) {
    function hrisIn(string $key, string $default = ''): string {
        $v = $_POST[$key] ?? $default;
        if (!is_scalar($v)) return $default;
        // Normalkan CRLF -> LF supaya textarea tidak menyimpan \r\n mentah.
        return str_replace(["\r\n", "\r"], "\n", trim((string)$v));
    }
}

/** Cetak nilai ke HTML dengan aman; baris baru dipertahankan. */
if (!function_exists('hrisOut')) {
    function hrisOut($v, bool $nl2br = false): string {
        $s = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        return $nl2br ? nl2br($s) : $s;
    }
}

/** Catat aktivitas modul HRIS ke activity_logs dengan keterangan lengkap. */
if (!function_exists('hrisLog')) {
    function hrisLog(string $module, string $action, string $detail): void {
        if (function_exists('logActivity')) {
            logActivity($_SESSION['user_id'] ?? 0, $module . '_' . $action, $detail);
        }
    }
}

/** Nama lengkap user yang sedang login, untuk kolom snapshot *_name. */
if (!function_exists('hrisActorName')) {
    function hrisActorName(): string {
        global $conn;
        static $cached = null;
        if ($cached !== null) return $cached;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $cached = '';
        if ($uid > 0) {
            $st = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $uid);
                $st->execute();
                $r = $st->get_result()->fetch_assoc();
                $st->close();
                $cached = (string)($r['full_name'] ?? '');
            }
        }
        return $cached;
    }
}

/** Simpan pesan sukses/gagal untuk ditampilkan sebagai toast melayang. */
if (!function_exists('hrisFlash')) {
    function hrisFlash(string $type, string $message): void {
        $_SESSION[$type === 'success' ? 'success' : 'error'] = $message;
    }
}

/** Apakah tabel ini punya kolom tersebut? Dipakai agar berkas PHP aman
 *  diupload sebelum migrasi SQL dijalankan. */
if (!function_exists('hrisHasColumn')) {
    function hrisHasColumn(string $table, string $column): bool {
        global $conn;
        static $cache = [];
        $k = $table . '.' . $column;
        if (isset($cache[$k])) return $cache[$k];
        $res = @$conn->query(
            "SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE '"
            . $conn->real_escape_string($column) . "'"
        );
        $cache[$k] = ($res && $res->num_rows > 0);
        if ($res) $res->free();
        return $cache[$k];
    }
}

/** Apakah tabel ini sudah dibuat? */
if (!function_exists('hrisHasTable')) {
    function hrisHasTable(string $table): bool {
        global $conn;
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        $res = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        $cache[$table] = ($res && $res->num_rows > 0);
        if ($res) $res->free();
        return $cache[$table];
    }
}

/**
 * Peringatan seragam bila migrasi SQL belum dijalankan.
 * Halaman tetap terbuka dan tidak error — hanya memberi tahu apa yang kurang.
 */
if (!function_exists('hrisMigrationNotice')) {
    function hrisMigrationNotice(string $table): string {
        global $LANG;
        return '<div class="alert alert-warning d-flex align-items-start gap-2 mb-0">'
             . '<i class="bi bi-exclamation-triangle-fill mt-1"></i><div>'
             . '<strong>' . hrisOut($LANG['warning']) . '</strong><br>'
             . hrisOut($LANG['hris_table_missing']) . ' <code>' . hrisOut($table) . '</code>'
             . '</div></div>';
    }
}


/* =====================================================================
   B. MESIN CRUD GENERIK

   Satu konfigurasi menghasilkan: daftar + pencarian + DataTables +
   modal tambah + modal ubah + hapus berkonfirmasi + CSRF + toast +
   pencatatan log + gerbang izin per aksi.

   Bentuk konfigurasi:
   [
     'module'  => 'assets',                  // modul izin (sudah ada di DB)
     'table'   => 'employee_assets',
     'tab'     => 'assets',                  // tab hub untuk redirect balik
     'title'   => $LANG['assets'],
     'icon'    => 'bi-box-seam',
     'order'   => 'id DESC',
     'fields'  => [
        'asset_code' => [
            'label'    => $LANG['code'],
            'type'     => 'text',            // text|textarea|number|money|date|select|checkbox
            'required' => true,
            'list'     => true,              // tampil di kolom daftar
            'options'  => [...],             // untuk type=select
            'render'   => function($row){},  // penampil khusus (opsional)
        ],
     ],
     'snapshot' => ['created_by' => 'created_by_name'],  // kolom nama permanen
   ]
   ===================================================================== */

/** Daftar tipe kolom yang dikenal mesin. */
if (!function_exists('hrisFieldTypes')) {
    function hrisFieldTypes(): array {
        return ['text', 'textarea', 'number', 'money', 'date', 'select', 'checkbox', 'email', 'file'];
    }
}

/**
 * Tangani POST (add / edit / delete) untuk satu konfigurasi CRUD.
 * Dipanggil SEBELUM ada keluaran HTML apa pun oleh berkas tab.
 * Selalu berakhir dengan redirect, sehingga refresh tidak mengirim ulang.
 */
if (!function_exists('hrisCrudHandle')) {
    function hrisCrudHandle(array $cfg): void {
        global $conn, $LANG;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['hris_add', 'hris_edit', 'hris_delete'], true)) return;

        $table  = $cfg['table'];
        $module = $cfg['module'];
        $tab    = $cfg['tab'];

        // 1. CSRF
        if (!hrisCsrfValid()) {
            hrisFlash('error', $LANG['invalid_csrf']);
            redirect(payrollGateUrl($tab));
        }

        // 2. Gerbang izin per aksi — HTML yang disembunyikan bukan pengaman.
        $need = ['hris_add' => 'can_create', 'hris_edit' => 'can_update', 'hris_delete' => 'can_delete'];
        if (!payrollModuleAllowed($module) || !checkPermission($module, $need[$action])) {
            hrisFlash('error', $LANG['perm_denied_target']);
            redirect(payrollGateUrl($tab));
        }

        // 3. Hapus
        if ($action === 'hris_delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                hrisFlash('error', $LANG['delete_failed_generic']);
                redirect(payrollGateUrl($tab));
            }
            $st = $conn->prepare("DELETE FROM `{$table}` WHERE id = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $id);
                $ok = $st->execute() && $st->affected_rows === 1;
                $st->close();
                if ($ok) {
                    hrisLog($module, 'delete', "Deleted {$table} id {$id}");
                    hrisFlash('success', $LANG['delete_success']);
                } else {
                    hrisFlash('error', $LANG['delete_failed_generic']);
                }
            } else {
                hrisFlash('error', $LANG['delete_failed_generic']);
            }
            redirect(payrollGateUrl($tab));
        }

        // 4. Tambah / Ubah — susun kolom dari konfigurasi
        $cols = [];
        $vals = [];
        $types = '';
        foreach ($cfg['fields'] as $name => $f) {
            if (!empty($f['no_save'])) continue;
            $type = $f['type'] ?? 'text';

            if ($type === 'checkbox') {
                $v = isset($_POST[$name]) ? 1 : 0;
                $cols[] = $name; $vals[] = $v; $types .= 'i'; continue;
            }

            $raw = hrisIn($name);

            if (!empty($f['required']) && $raw === '') {
                hrisFlash('error', $LANG['please_complete_required_fields']);
                redirect(payrollGateUrl($tab));
            }

            if ($type === 'number') {
                $cols[] = $name; $vals[] = (int)$raw; $types .= 'i';
            } elseif ($type === 'money') {
                // Buang pemisah ribuan apa pun sebelum disimpan.
                $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));
                $cols[] = $name; $vals[] = (float)($clean === '' ? 0 : $clean); $types .= 'd';
            } elseif ($type === 'date') {
                $cols[] = $name; $vals[] = ($raw === '' ? null : $raw); $types .= 's';
            } else {
                $cols[] = $name; $vals[] = $raw; $types .= 's';
            }
        }

        // 5. Kolom nama permanen — diisi otomatis dari akun yang login.
        $snapshot = $cfg['snapshot'] ?? [];
        foreach ($snapshot as $idCol => $nameCol) {
            if (!hrisHasColumn($table, $nameCol)) continue;
            if ($action === 'hris_add') {
                $cols[] = $idCol;   $vals[] = (int)($_SESSION['user_id'] ?? 0); $types .= 'i';
                $cols[] = $nameCol; $vals[] = hrisActorName();                  $types .= 's';
            }
        }

        if (empty($cols)) {
            hrisFlash('error', $LANG['save_failed_generic']);
            redirect(payrollGateUrl($tab));
        }

        if ($action === 'hris_add') {
            $ph  = implode(',', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$ph})";
            $st  = $conn->prepare($sql);
            if (!$st) {
                hrisFlash('error', $LANG['save_failed_generic']);
                redirect(payrollGateUrl($tab));
            }
            $st->bind_param($types, ...$vals);
            $ok = $st->execute();
            $newId = $ok ? (int)$st->insert_id : 0;
            $st->close();
            if ($ok) {
                hrisLog($module, 'add', "Added {$table} id {$newId}");
                hrisFlash('success', $LANG['save_success']);
            } else {
                hrisFlash('error', $LANG['save_failed_generic']);
            }
            redirect(payrollGateUrl($tab));
        }

        // hris_edit
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            hrisFlash('error', $LANG['save_failed_generic']);
            redirect(payrollGateUrl($tab));
        }
        $set = implode(' = ?, ', $cols) . ' = ?';
        $sql = "UPDATE `{$table}` SET {$set} WHERE id = ? LIMIT 1";
        $st  = $conn->prepare($sql);
        if (!$st) {
            hrisFlash('error', $LANG['save_failed_generic']);
            redirect(payrollGateUrl($tab));
        }
        $vals[] = $id; $types .= 'i';
        $st->bind_param($types, ...$vals);
        $ok = $st->execute();
        $st->close();
        if ($ok) {
            hrisLog($module, 'edit', "Updated {$table} id {$id}");
            hrisFlash('success', $LANG['save_success']);
        } else {
            hrisFlash('error', $LANG['save_failed_generic']);
        }
        redirect(payrollGateUrl($tab));
    }
}

/** Ambil seluruh baris untuk daftar, dengan urutan dari konfigurasi. */
if (!function_exists('hrisCrudRows')) {
    function hrisCrudRows(array $cfg): array {
        global $conn;
        $table = $cfg['table'];
        if (!hrisHasTable($table)) return [];
        $order = $cfg['order'] ?? 'id DESC';
        // $order berasal dari konfigurasi di dalam kode, bukan dari input user.
        $res = @$conn->query("SELECT * FROM `{$table}` ORDER BY {$order}");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}

/** Tampilkan satu nilai kolom sesuai tipenya. */
if (!function_exists('hrisCellValue')) {
    function hrisCellValue(array $row, string $name, array $f) {
        if (isset($f['render']) && is_callable($f['render'])) {
            return $f['render']($row);
        }
        $v = $row[$name] ?? '';
        switch ($f['type'] ?? 'text') {
            case 'money':
                return formatCurrency((float)$v);
            case 'checkbox':
                return ((int)$v === 1)
                    ? '<span class="badge bg-success-subtle text-success">&#10003;</span>'
                    : '<span class="badge bg-secondary-subtle text-secondary">&ndash;</span>';
            case 'select':
                $opts = $f['options'] ?? [];
                return hrisOut($opts[$v] ?? $v);
            case 'textarea':
                return hrisOut($v, true);
            default:
                return hrisOut($v);
        }
    }
}

/**
 * Cetak seluruh antarmuka CRUD: kepala + tabel + modal tambah/ubah.
 * Dipanggil oleh berkas tab setelah hrisCrudHandle().
 */
if (!function_exists('hrisCrudRender')) {
    function hrisCrudRender(array $cfg): void {
        global $LANG;

        $module = $cfg['module'];
        $table  = $cfg['table'];
        $tab    = $cfg['tab'];
        $uid    = 'hris_' . preg_replace('/[^a-z0-9_]/i', '', $table);

        if (!hrisHasTable($table)) {
            echo hrisMigrationNotice($table);
            return;
        }

        $canCreate = checkPermission($module, 'can_create');
        $canUpdate = checkPermission($module, 'can_update');
        $canDelete = checkPermission($module, 'can_delete');

        $rows      = hrisCrudRows($cfg);
        $listCols  = array_filter($cfg['fields'], fn($f) => !empty($f['list']));
        $csrf      = hrisCsrfToken();
        $formUrl   = payrollGateUrl($tab);
        ?>
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span><i class="bi <?= hrisOut($cfg['icon'] ?? 'bi-table') ?> me-2"></i><?= hrisOut($cfg['title']) ?></span>
                <?php if ($canCreate): ?>
                <button type="button" class="btn btn-primary btn-sm"
                        data-bs-toggle="modal" data-bs-target="#<?= $uid ?>_add">
                    <i class="bi bi-plus-circle"></i> <?= hrisOut($LANG['add']) ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($cfg['intro'])): ?>
                    <p class="text-muted small mb-3"><?= hrisOut($cfg['intro']) ?></p>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="<?= $uid ?>_table">
                        <thead>
                            <tr>
                                <th style="width:48px">#</th>
                                <?php foreach ($listCols as $name => $f): ?>
                                    <th><?= hrisOut($f['label']) ?></th>
                                <?php endforeach; ?>
                                <?php if ($canUpdate || $canDelete): ?>
                                    <th style="width:110px"><?= hrisOut($LANG['actions']) ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <?php
                            /* BARIS PLACEHOLDER DIHAPUS — inilah penyebab
                               "DataTables warning: Incorrect column count".

                               Dua kesalahan sekaligus di baris lama:

                               1. colspan-nya count($listCols) + 2, padahal kolom
                                  "Actions" hanya dirender bila $canUpdate ATAU
                                  $canDelete benar. Untuk pengguna tanpa kedua izin
                                  itu, header punya N+1 kolom sementara baris ini
                                  mengaku N+2.

                               2. Lebih mendasar: DataTables menghitung jumlah <td>
                                  tiap baris di <tbody> dan membandingkannya dengan
                                  jumlah <th>. Satu baris bersel tunggal — berapa pun
                                  colspan-nya — tidak pernah cocok.

                               Pesan "tidak ada data" kini ditangani DataTables
                               sendiri lewat language.emptyTable di init bawah, yang
                               memang disediakan untuk keperluan ini. Tampilannya
                               bagi pengguna tetap sama. */
                            ?>
                        <?php else: $n = 0; foreach ($rows as $row): $n++; ?>
                            <tr>
                                <td class="text-muted"><?= $n ?></td>
                                <?php foreach ($listCols as $name => $f): ?>
                                    <td><?= hrisCellValue($row, $name, $f) ?></td>
                                <?php endforeach; ?>
                                <?php if ($canUpdate || $canDelete): ?>
                                <td>
                                    <?php if ($canUpdate): ?>
                                    <button type="button" class="btn btn-warning btn-sm"
                                            title="<?= hrisOut($LANG['edit']) ?>"
                                            data-bs-toggle="modal" data-bs-target="#<?= $uid ?>_edit"
                                            data-row='<?= hrisOut(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                    <button type="button" class="btn btn-danger btn-sm"
                                            title="<?= hrisOut($LANG['delete']) ?>"
                                            data-del-id="<?= (int)$row['id'] ?>"
                                            onclick="<?= $uid ?>_del(this.dataset.delId)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php /* ── Modal tambah & ubah ────────────────────────────────── */ ?>
        <?php foreach ([['add', $LANG['add'], $canCreate], ['edit', $LANG['edit'], $canUpdate]] as [$mode, $label, $allowed]): ?>
        <?php if (!$allowed) continue; ?>
        <div class="modal fade" id="<?= $uid ?>_<?= $mode ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <form method="POST" action="<?= hrisOut($formUrl) ?>" class="modal-content payroll-modal"
                      <?= !empty($cfg['upload']) ? 'enctype="multipart/form-data"' : '' ?>>
                    <input type="hidden" name="action" value="hris_<?= $mode ?>">
                    <input type="hidden" name="csrf_token" value="<?= hrisOut($csrf) ?>">
                    <?php if ($mode === 'edit'): ?>
                        <input type="hidden" name="id" id="<?= $uid ?>_edit_id">
                    <?php endif; ?>
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi <?= $mode === 'add' ? 'bi-plus-circle' : 'bi-pencil-square' ?> me-2"></i>
                            <?= hrisOut($label) ?> &mdash; <?= hrisOut($cfg['title']) ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="<?= hrisOut($LANG['cancel']) ?>"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                        <?php foreach ($cfg['fields'] as $name => $f): ?>
                            <?php if (!empty($f['no_form'])) continue; ?>
                            <div class="col-md-<?= (int)($f['col'] ?? 6) ?>">
                                <label class="form-label" for="<?= $uid ?>_<?= $mode ?>_<?= $name ?>">
                                    <?= hrisOut($f['label']) ?>
                                    <?php if (!empty($f['required'])): ?><span class="text-danger">*</span><?php endif; ?>
                                </label>
                                <?php
                                $fid   = $uid . '_' . $mode . '_' . $name;
                                $type  = $f['type'] ?? 'text';
                                $req   = !empty($f['required']) ? 'required' : '';
                                $cls   = 'form-control';
                                if ($type === 'select')   $cls = 'form-select';
                                if ($type === 'checkbox') $cls = 'form-check-input';
                                ?>
                                <?php if ($type === 'textarea'): ?>
                                    <textarea class="<?= $cls ?>" id="<?= $fid ?>" name="<?= $name ?>"
                                              rows="<?= (int)($f['rows'] ?? 3) ?>" <?= $req ?>
                                              data-hris-field="<?= $name ?>"></textarea>
                                <?php elseif ($type === 'select'): ?>
                                    <select class="<?= $cls ?>" id="<?= $fid ?>" name="<?= $name ?>" <?= $req ?>
                                            data-hris-field="<?= $name ?>">
                                        <option value=""><?= hrisOut($LANG['choose']) ?></option>
                                        <?php foreach (($f['options'] ?? []) as $ov => $ol): ?>
                                            <option value="<?= hrisOut($ov) ?>"><?= hrisOut($ol) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($type === 'checkbox'): ?>
                                    <div class="form-check mt-2">
                                        <input class="<?= $cls ?>" type="checkbox" value="1"
                                               id="<?= $fid ?>" name="<?= $name ?>" data-hris-field="<?= $name ?>">
                                        <label class="form-check-label small text-muted" for="<?= $fid ?>">
                                            <?= hrisOut($f['hint'] ?? $LANG['active']) ?>
                                        </label>
                                    </div>
                                <?php else: ?>
                                    <input type="<?= $type === 'money' ? 'text' : hrisOut($type) ?>"
                                           class="<?= $cls ?>" id="<?= $fid ?>" name="<?= $name ?>" <?= $req ?>
                                           <?= $type === 'money' ? 'inputmode="decimal"' : '' ?>
                                           data-hris-field="<?= $name ?>">
                                <?php endif; ?>
                                <?php if (!empty($f['hint']) && $type !== 'checkbox'): ?>
                                    <div class="form-text small"><?= hrisOut($f['hint']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-lg"></i> <?= hrisOut($LANG['cancel']) ?>
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> <?= hrisOut($LANG['save']) ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($canDelete): ?>
        <form method="POST" action="<?= hrisOut($formUrl) ?>" id="<?= $uid ?>_delform" class="d-none">
            <input type="hidden" name="action" value="hris_delete">
            <input type="hidden" name="csrf_token" value="<?= hrisOut($csrf) ?>">
            <input type="hidden" name="id" id="<?= $uid ?>_delid">
        </form>
        <?php endif; ?>

        <script>
        (function () {
            /* Mesin ini dimuat di dalam hub payroll_gate.php, sedangkan jQuery
               baru dimuat footer.php di akhir halaman. Karena itu seluruh
               pemasangan dilakukan setelah DOM siap dan setelah jQuery ada —
               pola yang sama dipakai halaman lain di sistem ini. */
            function ready(fn) {
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', fn);
                } else { fn(); }
            }
            var tries = 0;
            function boot() {
                if (typeof jQuery === 'undefined') {
                    if (++tries > 100) return;
                    setTimeout(boot, 50); return;
                }
                var $ = jQuery;

                /* Isi modal ubah dari data-row pada tombol yang diklik.
                   Nilai diambil lewat .val(), bukan disisipkan sebagai HTML,
                   sehingga tanda baca ' " & tampil apa adanya. */
                $('#<?= $uid ?>_edit').on('show.bs.modal', function (ev) {
                    var btn = ev.relatedTarget; if (!btn) return;
                    var data = {};
                    try { data = JSON.parse(btn.getAttribute('data-row') || '{}'); } catch (e) { return; }
                    $('#<?= $uid ?>_edit_id').val(data.id || '');
                    $(this).find('[data-hris-field]').each(function () {
                        var f = this.getAttribute('data-hris-field');
                        var v = (data[f] === null || typeof data[f] === 'undefined') ? '' : data[f];
                        if (this.type === 'checkbox') { this.checked = (String(v) === '1'); }
                        else { $(this).val(v); }
                    });
                });

                /* Bersihkan modal tambah setiap kali dibuka. */
                $('#<?= $uid ?>_add').on('show.bs.modal', function () {
                    this.querySelectorAll('form')[0] && this.querySelectorAll('form')[0].reset();
                });

                if ($.fn.DataTable) {
                    $('#<?= $uid ?>_table').DataTable({
                        pageLength: 25,
                        order: [],
                        columnDefs: [{ orderable: false, targets: -1 }],
                        language: {
                            search: '',
                            searchPlaceholder: <?= json_encode((string)$LANG['search'], JSON_UNESCAPED_UNICODE) ?>,
                            /* Menggantikan baris placeholder yang dihapus di atas. */
                            emptyTable: <?= json_encode((string)$LANG['no_data'], JSON_UNESCAPED_UNICODE) ?>,
                            zeroRecords: <?= json_encode((string)$LANG['no_data'], JSON_UNESCAPED_UNICODE) ?>
                        }
                    });
                }
            }
            ready(boot);
        })();

        function <?= $uid ?>_del(id) {
            Swal.fire({
                title: <?= json_encode((string)$LANG['confirm_delete'], JSON_UNESCAPED_UNICODE) ?>,
                text:  <?= json_encode((string)$LANG['action_cannot_be_undone'], JSON_UNESCAPED_UNICODE) ?>,
                icon: 'warning', showCancelButton: true,
                confirmButtonColor: '#dc3545', cancelButtonColor: '#6c757d',
                confirmButtonText: <?= json_encode((string)$LANG['delete'], JSON_UNESCAPED_UNICODE) ?>,
                cancelButtonText:  <?= json_encode((string)$LANG['cancel'], JSON_UNESCAPED_UNICODE) ?>,
                reverseButtons: true, focusCancel: true
            }).then(function (r) {
                if (r.isConfirmed) {
                    document.getElementById('<?= $uid ?>_delid').value = id;
                    document.getElementById('<?= $uid ?>_delform').submit();
                }
            });
        }
        </script>
        <?php
    }
}


/* =====================================================================
   C. MODUL 3 — MESIN APPROVAL BERTINGKAT
   ===================================================================== */

/** Daftar alur yang aktif. */
if (!function_exists('hrisApprovalFlows')) {
    function hrisApprovalFlows(): array {
        global $conn;
        if (!hrisHasTable('approval_flows')) return [];
        $res = @$conn->query("SELECT * FROM approval_flows WHERE is_active = 1 ORDER BY name ASC");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}

/**
 * Langkah-langkah yang BERLAKU untuk satu dokumen, sudah disaring
 * berdasarkan nominalnya. Nominal di bawah min_amount membuat langkah
 * itu dilewati, sehingga satu alur bisa punya cabang nominal.
 */
if (!function_exists('hrisApprovalResolveSteps')) {
    function hrisApprovalResolveSteps(string $flowKey, float $amount): array {
        global $conn;
        if (!hrisHasTable('approval_steps')) return [];
        $st = $conn->prepare(
            "SELECT s.*
               FROM approval_steps s
               JOIN approval_flows f ON f.id = s.flow_id
              WHERE f.flow_key = ? AND f.is_active = 1 AND s.is_active = 1
                AND s.min_amount <= ?
              ORDER BY s.step_order ASC"
        );
        if (!$st) return [];
        $st->bind_param('sd', $flowKey, $amount);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    }
}

/**
 * Buat permintaan persetujuan untuk satu dokumen.
 * Bila alurnya belum diatur, mengembalikan 0 dan pemanggilnya tetap
 * memakai alur lama satu langkah — dokumen lama tidak pernah macet.
 */
if (!function_exists('hrisApprovalCreate')) {
    function hrisApprovalCreate(string $flowKey, string $refTable, int $refId,
                                int $employeeId, string $employeeName, float $amount): int {
        global $conn;
        if (!hrisHasTable('approval_requests')) return 0;

        $steps = hrisApprovalResolveSteps($flowKey, $amount);
        if (empty($steps)) return 0;

        $total = count($steps);
        $uid   = (int)($_SESSION['user_id'] ?? 0);
        $uname = hrisActorName();

        $st = $conn->prepare(
            "INSERT INTO approval_requests
                 (flow_key, ref_table, ref_id, employee_id, employee_name, amount,
                  current_step, total_steps, status, created_by, created_by_name)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, 'pending', ?, ?)
             ON DUPLICATE KEY UPDATE
                  current_step = 1, total_steps = VALUES(total_steps), status = 'pending'"
        );
        if (!$st) return 0;
        $st->bind_param('ssiisdiis', $flowKey, $refTable, $refId, $employeeId, $employeeName,
                        $amount, $total, $uid, $uname);
        $ok = $st->execute();
        $id = $ok ? (int)$st->insert_id : 0;
        $st->close();

        if ($id > 0) {
            hrisApprovalLog($id, 1, 'submitted', '');
            hrisLog('approvals', 'submit', "Submitted {$refTable} #{$refId} to flow {$flowKey}");
        }
        return $id;
    }
}

/** Catat satu tindakan ke jejak permanen. */
if (!function_exists('hrisApprovalLog')) {
    function hrisApprovalLog(int $requestId, int $stepOrder, string $action, string $notes): void {
        global $conn;
        if (!hrisHasTable('approval_logs')) return;
        $st = $conn->prepare(
            "INSERT INTO approval_logs
                 (request_id, step_order, action, actor_user_id, actor_user_name, notes)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$st) return;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $un  = hrisActorName();
        $st->bind_param('iisiss', $requestId, $stepOrder, $action, $uid, $un, $notes);
        $st->execute();
        $st->close();
    }
}

/**
 * Bolehkah user yang login menyetujui langkah saat ini?
 *   manager  -> ia adalah atasan langsung pemohon
 *   position -> ia menjabat position_id langkah itu
 *   user     -> ia adalah user yang ditunjuk
 * Superadmin selalu boleh.
 */
if (!function_exists('hrisApprovalCanAct')) {
    function hrisApprovalCanAct(array $request): bool {
        global $conn;
        if (isSuperAdmin()) return true;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return false;

        $steps = hrisApprovalResolveSteps($request['flow_key'], (float)$request['amount']);
        $idx   = max(0, (int)$request['current_step'] - 1);
        if (!isset($steps[$idx])) return false;
        $step = $steps[$idx];

        if ($step['approver_type'] === 'user') {
            return (int)$step['user_id'] === $uid;
        }
        if ($step['approver_type'] === 'position') {
            $st = $conn->prepare(
                "SELECT 1 FROM employees WHERE user_id = ? AND position_id = ? LIMIT 1"
            );
            if (!$st) return false;
            $pid = (int)$step['position_id'];
            $st->bind_param('ii', $uid, $pid);
            $st->execute();
            $found = (bool)$st->get_result()->fetch_row();
            $st->close();
            return $found;
        }
        // manager
        $st = $conn->prepare(
            "SELECT 1
               FROM employees e
               JOIN employees m ON m.id = e.manager_id
              WHERE e.id = ? AND m.user_id = ? LIMIT 1"
        );
        if (!$st) return false;
        $eid = (int)$request['employee_id'];
        $st->bind_param('ii', $eid, $uid);
        $st->execute();
        $found = (bool)$st->get_result()->fetch_row();
        $st->close();
        return $found;
    }
}

/**
 * Setujui atau tolak langkah saat ini.
 * Menyetujui langkah terakhir menandai dokumen selesai; menolak
 * langsung menghentikan seluruh alur.
 */
if (!function_exists('hrisApprovalAct')) {
    function hrisApprovalAct(int $requestId, string $action, string $notes = ''): bool {
        global $conn;
        if (!in_array($action, ['approved', 'rejected'], true)) return false;
        if (!hrisHasTable('approval_requests')) return false;

        $conn->begin_transaction();
        try {
            $st = $conn->prepare("SELECT * FROM approval_requests WHERE id = ? FOR UPDATE");
            if (!$st) throw new RuntimeException('prepare_failed');
            $st->bind_param('i', $requestId);
            $st->execute();
            $req = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$req || $req['status'] !== 'pending') throw new RuntimeException('not_pending');
            if (!hrisApprovalCanAct($req))             throw new RuntimeException('not_authorised');

            $step = (int)$req['current_step'];

            if ($action === 'rejected') {
                $up = $conn->prepare("UPDATE approval_requests SET status = 'rejected' WHERE id = ?");
                if (!$up) throw new RuntimeException('prepare_failed');
                $up->bind_param('i', $requestId);
                $up->execute(); $up->close();
                hrisApprovalSyncDocument($req, 'rejected', $notes);
            } elseif ($step >= (int)$req['total_steps']) {
                $up = $conn->prepare("UPDATE approval_requests SET status = 'approved' WHERE id = ?");
                if (!$up) throw new RuntimeException('prepare_failed');
                $up->bind_param('i', $requestId);
                $up->execute(); $up->close();
                hrisApprovalSyncDocument($req, 'approved', $notes);
            } else {
                $next = $step + 1;
                $up = $conn->prepare("UPDATE approval_requests SET current_step = ? WHERE id = ?");
                if (!$up) throw new RuntimeException('prepare_failed');
                $up->bind_param('ii', $next, $requestId);
                $up->execute(); $up->close();
            }

            hrisApprovalLog($requestId, $step, $action, $notes);
            $conn->commit();
            hrisLog('approvals', $action, "Request #{$requestId} step {$step} {$action}");
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            return false;
        }
    }
}

/**
 * Teruskan hasil akhir ke dokumen aslinya (leave_requests / cash_advances),
 * memakai kolom status yang SUDAH ADA agar halaman lama tetap benar.
 * Hanya dua tabel itu yang boleh disentuh — daftarnya dikunci di sini.
 */
if (!function_exists('hrisApprovalSyncDocument')) {
    function hrisApprovalSyncDocument(array $req, string $finalStatus, string $notes): void {
        global $conn;
        $allowed = ['leave_requests', 'cash_advances'];
        $table   = (string)$req['ref_table'];
        if (!in_array($table, $allowed, true)) return;

        $refId = (int)$req['ref_id'];
        $uid   = (int)($_SESSION['user_id'] ?? 0);
        $uname = hrisActorName();

        if ($finalStatus === 'approved') {
            $sql = "UPDATE `{$table}`
                       SET status = 'approved', approved_by = ?, approved_by_name = ?, approved_at = NOW()
                     WHERE id = ? LIMIT 1";
            $st = $conn->prepare($sql);
            if (!$st) return;
            $st->bind_param('isi', $uid, $uname, $refId);
        } else {
            if ($table === 'leave_requests') {
                $sql = "UPDATE leave_requests
                           SET status = 'rejected', approved_by = ?, approved_by_name = ?,
                               approved_at = NOW(), rejection_reason = ?
                         WHERE id = ? LIMIT 1";
                $st = $conn->prepare($sql);
                if (!$st) return;
                $st->bind_param('issi', $uid, $uname, $notes, $refId);
            } else {
                $sql = "UPDATE cash_advances
                           SET status = 'rejected', approved_by = ?, approved_by_name = ?, approved_at = NOW()
                         WHERE id = ? LIMIT 1";
                $st = $conn->prepare($sql);
                if (!$st) return;
                $st->bind_param('isi', $uid, $uname, $refId);
            }
        }
        $st->execute();
        $st->close();
    }
}

/** Permintaan yang masih menunggu, untuk daftar di tab Approvals. */
if (!function_exists('hrisApprovalPending')) {
    function hrisApprovalPending(): array {
        global $conn;
        if (!hrisHasTable('approval_requests')) return [];
        $res = @$conn->query(
            "SELECT * FROM approval_requests WHERE status = 'pending' ORDER BY created_at DESC"
        );
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}


/* =====================================================================
   D. MODUL 4 — PPh21 PROGRESIF BERJENJANG
   ===================================================================== */

/** Lapisan tarif aktif untuk satu tahun; jatuh ke tahun terdekat bila kosong. */
if (!function_exists('hrisTaxBrackets')) {
    function hrisTaxBrackets(?int $year = null): array {
        global $conn;
        if (!hrisHasTable('tax_brackets')) return [];
        $year = $year ?: (int)date('Y');
        $st = $conn->prepare(
            "SELECT * FROM tax_brackets WHERE is_active = 1 AND year = ? ORDER BY sort_order ASC"
        );
        if (!$st) return [];
        $st->bind_param('i', $year);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        if (!empty($rows)) return $rows;

        // Tahun itu belum diatur — pakai tahun terbaru yang tersedia.
        $res = @$conn->query(
            "SELECT * FROM tax_brackets WHERE is_active = 1
              AND year = (SELECT MAX(year) FROM tax_brackets WHERE is_active = 1)
             ORDER BY sort_order ASC"
        );
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}

/** Nilai PTKP setahun untuk satu kode status. */
if (!function_exists('hrisPtkpAmount')) {
    function hrisPtkpAmount(string $code): float {
        global $conn;
        if (!hrisHasTable('ptkp_status')) return 0.0;
        $st = $conn->prepare("SELECT annual_amount FROM ptkp_status WHERE code = ? AND is_active = 1 LIMIT 1");
        if (!$st) return 0.0;
        $st->bind_param('s', $code);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        return (float)($r['annual_amount'] ?? 0);
    }
}

/**
 * Hitung PPh21 sebulan dengan tarif progresif berjenjang.
 *
 * Langkahnya:
 *   1. penghasilan bruto sebulan  -> disetahunkan (x12)
 *   2. dikurangi PTKP sesuai status karyawan       -> PKP setahun
 *   3. PKP dilapisi tarif berjenjang               -> pajak setahun
 *   4. dibagi 12                                   -> pajak sebulan
 *
 * PKP negatif menghasilkan pajak 0 — tidak pernah minus.
 * Bila tabel tarif kosong, fungsi mengembalikan NULL sehingga pemanggil
 * bisa jatuh kembali ke tarif datar lama tanpa kehilangan perhitungan.
 *
 * @return float|null pajak sebulan, atau null bila tarif belum diatur
 */
if (!function_exists('hrisCalcPPh21Progressive')) {
    function hrisCalcPPh21Progressive(float $monthlyGross, string $ptkpCode = 'TK/0', ?int $year = null): ?float {
        $brackets = hrisTaxBrackets($year);
        if (empty($brackets)) return null;

        $annualGross = $monthlyGross * 12;
        $ptkp        = hrisPtkpAmount($ptkpCode);
        $pkp         = $annualGross - $ptkp;
        if ($pkp <= 0) return 0.0;

        // Dibulatkan ke bawah ke ribuan penuh, praktik umum perhitungan PKP.
        $pkp = floor($pkp / 1000) * 1000;

        $tax       = 0.0;
        $remaining = $pkp;
        foreach ($brackets as $b) {
            $min = (float)$b['min_income'];
            $max = ($b['max_income'] === null) ? null : (float)$b['max_income'];
            if ($pkp <= $min) break;

            $upper = ($max === null) ? $pkp : min($pkp, $max);
            $slice = $upper - $min;
            if ($slice <= 0) continue;

            $tax += $slice * ((float)$b['rate_percent'] / 100);
            $remaining -= $slice;
            if ($remaining <= 0) break;
        }
        return round($tax / 12, 2);
    }
}


/* =====================================================================
   E. MODUL 8 — PARSER IMPORT MESIN ABSENSI
   ===================================================================== */

/** Format mesin yang dikenali beserta urutan kolomnya. */
if (!function_exists('hrisAttendanceFormats')) {
    function hrisAttendanceFormats(): array {
        return [
            // kunci        => [kode, tanggal, masuk, keluar]
            'generic_csv'  => [0, 1, 2, 3],
            'fingerspot'   => [0, 1, 2, 3],
            'solution'     => [1, 0, 2, 3],
            'zkteco'       => [0, 1, 2, 3],
        ];
    }
}

/**
 * Baca berkas CSV menjadi baris mentah.
 * TIDAK menyentuh tabel attendance sama sekali — hasilnya disimpan dulu
 * ke attendance_import_rows supaya bisa ditinjau sebelum diterapkan.
 */
if (!function_exists('hrisParseAttendanceCsv')) {
    function hrisParseAttendanceCsv(string $path, string $machineType): array {
        $formats = hrisAttendanceFormats();
        $map     = $formats[$machineType] ?? $formats['generic_csv'];
        $out     = [];
        if (!is_file($path)) return $out;

        $fh = @fopen($path, 'r');
        if (!$fh) return $out;

        $n = 0;
        while (($cols = fgetcsv($fh, 4096, ',')) !== false) {
            $n++;
            if ($n === 1) {
                // Lewati baris judul bila kolom pertamanya jelas bukan kode.
                $first = strtolower(trim((string)($cols[0] ?? '')));
                if ($first === '' || preg_match('/[a-z]{3,}/', $first)) continue;
            }
            $out[] = [
                'row_number'        => $n,
                'raw_employee_code' => trim((string)($cols[$map[0]] ?? '')),
                'raw_date'          => trim((string)($cols[$map[1]] ?? '')),
                'raw_check_in'      => trim((string)($cols[$map[2]] ?? '')),
                'raw_check_out'     => trim((string)($cols[$map[3]] ?? '')),
            ];
        }
        fclose($fh);
        return $out;
    }
}

/** Ubah tanggal apa adanya dari mesin menjadi Y-m-d, atau null bila gagal. */
if (!function_exists('hrisNormaliseDate')) {
    function hrisNormaliseDate(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') return null;
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'Y/m/d', 'Ymd'] as $fmt) {
            $d = DateTime::createFromFormat($fmt, $raw);
            if ($d && $d->format($fmt) === $raw) return $d->format('Y-m-d');
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}


/* =====================================================================
   F. MODUL 9 — ANTREAN EMAIL SLIP GAJI
   ===================================================================== */

/**
 * Catat satu slip gaji ke antrean pengiriman.
 *
 * Fungsi ini SENGAJA tidak memanggil mail() secara langsung. Pengiriman
 * sesungguhnya membutuhkan kredensial SMTP yang belum tersedia; sampai
 * itu diisi, slip masuk antrean berstatus 'queued' sehingga riwayatnya
 * tetap tercatat dan bisa dikirim ulang tanpa menebak.
 */
if (!function_exists('hrisQueuePayslipEmail')) {
    function hrisQueuePayslipEmail(int $payrollId, int $employeeId, string $employeeName,
                                   string $emailTo, string $subject): bool {
        global $conn;
        if (!hrisHasTable('payslip_email_log')) return false;
        $st = $conn->prepare(
            "INSERT INTO payslip_email_log
                 (payroll_id, employee_id, employee_name, email_to, subject, status, sent_by, sent_by_name)
             VALUES (?, ?, ?, ?, ?, 'queued', ?, ?)"
        );
        if (!$st) return false;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $un  = hrisActorName();
        $st->bind_param('iisssis', $payrollId, $employeeId, $employeeName, $emailTo, $subject, $uid, $un);
        $ok = $st->execute();
        $st->close();
        if ($ok) hrisLog('payslip_email', 'queue', "Queued payslip #{$payrollId} to {$emailTo}");
        return $ok;
    }
}


/* =====================================================================
   G. MODUL 10 — SELF-SERVICE KARYAWAN
   ===================================================================== */

/**
 * Baris employees milik user yang sedang login, atau null.
 * Seluruh halaman portal WAJIB memanggil ini dan membatasi datanya ke
 * employee_id yang dikembalikan — tidak pernah membaca $_GET['id'].
 */
if (!function_exists('hrisCurrentEmployee')) {
    function hrisCurrentEmployee(): ?array {
        global $conn;
        static $cached = false;
        if ($cached !== false) return $cached;
        $cached = null;

        if (!hrisHasColumn('employees', 'user_id')) return null;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return null;

        $st = $conn->prepare("SELECT * FROM employees WHERE user_id = ? LIMIT 1");
        if (!$st) return null;
        $st->bind_param('i', $uid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        $cached = $row ?: null;
        return $cached;
    }
}

/** Daftar karyawan aktif untuk dropdown — dipakai banyak modul baru. */
if (!function_exists('hrisActiveEmployeeOptions')) {
    function hrisActiveEmployeeOptions(): array {
        global $conn;
        $out = [];
        $res = @$conn->query(
            "SELECT id, employee_code, full_name
               FROM employees
              WHERE employee_status = 'active'
              ORDER BY full_name ASC"
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $out[$r['id']] = $r['full_name'] . ' (' . $r['employee_code'] . ')';
            }
            $res->free();
        }
        return $out;
    }
}

/** Dropdown umum dari satu tabel referensi (departments, positions, dst). */
if (!function_exists('hrisRefOptions')) {
    function hrisRefOptions(string $table, string $labelCol = 'name', string $activeCol = 'is_active'): array {
        global $conn;
        $out = [];
        if (!hrisHasTable($table)) return $out;
        $where = hrisHasColumn($table, $activeCol) ? "WHERE `{$activeCol}` = 1" : '';
        $res = @$conn->query("SELECT id, `{$labelCol}` AS label FROM `{$table}` {$where} ORDER BY `{$labelCol}` ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) $out[$r['id']] = $r['label'];
            $res->free();
        }
        return $out;
    }
}
