<?php
/* =============================================================================
   settings/activity.php  —  FRAGMEN TAB dari settings/settings_gate.php

   RINGKASAN PERUBAHAN TAHAP 2
     1. Guard direct-access memakai stanza baku Tahap 0.3, kini juga
        memastikan fragmen ini dipanggil oleh SETTINGS_GATE.
     2. Pembentukan token CSRF dan TIGA pemeriksaan hash_equals yang ditulis
        tangan diganti helper bltx_csrf_*. Token dan cara bandingnya SAMA
        PERSIS ($_SESSION['csrf_token'] + hash_equals) — yang hilang hanya
        pengulangan kodenya.
     3. Penghapusan satu baris log tidak lagi lewat $.ajax + endpoint JSON;
        sekarang POST biasa. Endpoint JSON di berkas ini hilang seluruhnya.
     4. Notifikasi lewat bltx_render_flash(); dua blok toast manual (±32
        baris) dan jalur ?success=1/?error=invalid_csrf tidak dipakai lagi.
     5. Init DataTables kedua DIHAPUS. Satu-satunya init ada di
        includes/footer.php; urutan kolom dan panjang halaman kini
        disampaikan lewat opsi ui_table_open() dari Tahap 0.5.
     6. window.confirm() diganti dialog konfirmasi SweetAlert2.

   BUG YANG IKUT TERTUTUP: tombol "Hapus Semua" dulu memakai id
   deleteAllLogsBtn SEKALIGUS kelas .delete-log, sehingga satu klik memicu
   dua penangan — dialog konfirmasi muncul dua kali dan permintaan kedua
   mengirim log_id kosong. Kelas .delete-log dilepas dari tombol itu.
   ========================================================================== */
if (!function_exists('bltx_guard_fragment')) { http_response_code(403); exit('Direct access not permitted.'); }
bltx_guard_fragment('SETTINGS_GATE');

require_once __DIR__ . '/../includes/ui_table.php';

$__act_self = url('settings/settings_gate.php?tab=activity');

$__act_can_update = isSuperAdmin() || checkPermission('settings_activity', 'can_update');
$__act_can_delete = isSuperAdmin() || checkPermission('settings_activity', 'can_delete');

/* ===============================
   SIMPAN PENGATURAN LOG
=============================== */
if (isset($_POST['save_activity'])) {
    bltx_csrf_require($__act_self);

    if (!$__act_can_update) {
        bltx_flash_error($LANG['no_permission']);
        redirect($__act_self);
    }

    $log_enabled     = isset($_POST['activity_log_enabled']) ? '1' : '0';
    $retention       = max(1, min(365,  (int)($_POST['activity_log_retention'] ?? 30)));
    $session_timeout = max(5, min(1440, (int)($_POST['session_timeout'] ?? 30)));

    updateSetting('activity_log_enabled',   $log_enabled);
    updateSetting('activity_log_retention', (string)$retention);
    updateSetting('session_timeout',        (string)$session_timeout);

    logActivity($_SESSION['user_id'], 'update_settings', 'Updated activity settings');

    bltx_flash_success($LANG['settings_saved']);
    redirect($__act_self);
}

/* ===============================
   HAPUS SEMUA LOG
=============================== */
if (isset($_POST['delete_all_logs'])) {
    bltx_csrf_require($__act_self);

    if (!$__act_can_delete) {
        bltx_flash_error($LANG['no_permission']);
        redirect($__act_self);
    }

    $conn->query("DELETE FROM activity_logs");
    logActivity($_SESSION['user_id'], 'delete_all_logs', 'Deleted all activity logs');

    bltx_flash_success($LANG['delete_success']);
    redirect($__act_self);
}

/* ===============================
   HAPUS SATU LOG — dulu $.ajax + JSON
=============================== */
if (isset($_POST['delete_log'])) {
    bltx_csrf_require($__act_self);

    if (!$__act_can_delete) {
        bltx_flash_error($LANG['no_permission']);
        redirect($__act_self);
    }

    $id = (int)($_POST['log_id'] ?? 0);
    if ($id <= 0) {
        bltx_flash_error($LANG['error_occurred']);
        redirect($__act_self);
    }

    $stmt = $conn->prepare("DELETE FROM activity_logs WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        logActivity($_SESSION['user_id'], 'delete_log', 'Deleted activity log ID: ' . $id);
        bltx_flash_success($LANG['delete_success']);
    } else {
        error_log('[bltx] delete activity log failed: ' . $stmt->error);
        bltx_flash_error($LANG['error_occurred']);
    }
    $stmt->close();
    redirect($__act_self);
}

/* ===============================
   AMBIL DATA LOG
=============================== */
/* Nama pelaku dibaca LANGSUNG dari kolom permanen activity_logs.user_name
   yang sudah diisi logActivity() di includes/config.php pada saat log dibuat.
   Sebelumnya baris ini mengambil nama lewat JOIN ke tabel `users`, sehingga
   seluruh riwayat milik akun yang dihapus berubah menjadi "System" padahal
   nama aslinya sudah tersimpan rapi di barisnya sendiri. Alias `full_name`
   dipertahankan supaya tidak ada baris tampilan yang perlu diubah. */
$logs_result = $conn->query("
    SELECT l.*, l.user_name AS full_name
    FROM activity_logs l
    ORDER BY l.created_at DESC
");
?>

<?php bltx_render_flash('activity'); ?>

<div class="row align-items-start">

    <!-- Pengaturan Log -->
    <div class="col-lg-5 mb-4">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-shield-check"></i> <?php echo bltx_e($LANG['activity_log']); ?>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="<?php echo bltx_e($__act_self); ?>">
                    <?php bltx_csrf_field(); ?>

                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" name="activity_log_enabled"
                               id="activity_log_enabled" value="1"
                               <?php echo getSetting('activity_log_enabled') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="activity_log_enabled">
                            <?php echo bltx_e($LANG['enable_activity_log']); ?>
                        </label>
                    </div>

                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label"><?php echo bltx_e($LANG['activity_log_retention']); ?></label>
                            <input type="number" class="form-control" name="activity_log_retention"
                                   value="<?php echo bltx_e(getSetting('activity_log_retention')); ?>" min="1" max="365">
                            <small class="form-text text-muted"><?php echo bltx_e($LANG['days_to_keep_logs']); ?></small>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label"><?php echo bltx_e($LANG['session_timeout']); ?></label>
                            <input type="number" class="form-control" name="session_timeout"
                                   value="<?php echo bltx_e(getSetting('session_timeout')); ?>" min="5" max="1440">
                            <small class="form-text text-muted"><?php echo bltx_e($LANG['minutes_of_inactivity']); ?></small>
                        </div>
                    </div>

                    <div class="text-end">
                        <?php if ($__act_can_update): ?>
                        <button type="submit" name="save_activity" class="btn btn-primary btn-sm">
                            <i class="bi bi-save me-1"></i> <?php echo bltx_e($LANG['save']); ?>
                        </button>
                        <?php else: ?>
                        <span class="text-muted small"><i class="bi bi-lock"></i> <?php echo bltx_e($LANG['no_permission']); ?></span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Daftar Log -->
    <div class="col-lg-7 mb-4">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-clock-history"></i> <?php echo bltx_e($LANG['recent_activity_logs']); ?>
                </div>
                <?php if ($__act_can_delete): ?>
                <div class="card-tools">
                    <!-- Kelas .delete-log SENGAJA tidak dipasang di sini: dulu
                         tombol ini memicu penangan hapus-satu sekaligus. -->
                    <button type="button" class="btn btn-danger btn-sm" id="deleteAllLogsBtn">
                        <i class="bi bi-trash"></i> <?php echo bltx_e($LANG['delete_all']); ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php
                /* Urutan dan panjang halaman disampaikan sebagai opsi, bukan
                   sebagai init DataTables kedua di dalam <script>. */
                ui_table_open('logsTable', [
                    'columns' => [
                        ['label' => $LANG['user']],
                        ['label' => $LANG['action']],
                        ['label' => $LANG['date']],
                        ['label' => $LANG['action'], 'width' => 60, 'align' => 'center'],
                    ],
                    'small'       => true,
                    'page_length' => 25,
                    'order'       => [2, 'desc'],
                ]);

                if ($logs_result && $logs_result->num_rows > 0):
                    while ($log = $logs_result->fetch_assoc()):
                        $timestamp = strtotime($log['created_at']);
                ?>
                    <tr>
                        <td><?php echo bltx_e($log['full_name'] ?? 'System'); ?></td>
                        <td><?php echo bltx_e($log['description'] ?? ''); ?></td>
                        <td data-order="<?php echo (int)$timestamp; ?>"><?php echo date('d-m-Y H:i:s', $timestamp); ?></td>
                        <td class="text-center">
                            <?php if ($__act_can_delete): ?>
                            <button type="button" class="btn btn-danger btn-sm delete-log"
                                    data-log-id="<?php echo (int)$log['id']; ?>"
                                    title="<?php echo bltx_e($LANG['delete']); ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php
                    endwhile;
                else:
                    ui_table_empty(4, $LANG['no_logs_found'], 'inbox');
                endif;
                ui_table_close();
                ?>
            </div>
        </div>
    </div>

</div>

<?php if ($__act_can_delete): ?>
<!-- Formulir tersembunyi: hapus semua & hapus satu, keduanya POST + token. -->
<form id="logActionForm" method="POST" action="<?php echo bltx_e($__act_self); ?>" class="d-none">
    <?php bltx_csrf_field(); ?>
    <input type="hidden" name="delete_all_logs" id="log_delete_all" value="">
    <input type="hidden" name="delete_log"      id="log_delete_one" value="">
    <input type="hidden" name="log_id"          id="log_delete_id"  value="">
</form>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    var form = document.getElementById('logActionForm');
    if (!form) return;   // pengguna tanpa izin hapus: tidak ada apa pun untuk dipasang

    function confirmThen(message, onYes) {
        Swal.fire({
            title:              <?php echo bltx_js($LANG['are_you_sure']); ?>,
            text:               message,
            icon:               'warning',
            showCancelButton:   true,
            confirmButtonColor: '#d33',
            cancelButtonColor:  '#3085d6',
            confirmButtonText:  <?php echo bltx_js($LANG['delete']); ?>,
            cancelButtonText:   <?php echo bltx_js($LANG['cancel']); ?>,
            reverseButtons:     true
        }).then(function (r) { if (r.isConfirmed) { onYes(); } });
    }

    // HAPUS SEMUA
    var allBtn = document.getElementById('deleteAllLogsBtn');
    if (allBtn) {
        allBtn.addEventListener('click', function () {
            confirmThen(<?php echo bltx_js($LANG['delete_all_logs_confirm']); ?>, function () {
                document.getElementById('log_delete_all').value = '1';
                document.getElementById('log_delete_one').value = '';
                form.submit();
            });
        });
    }

    // HAPUS SATU — POST biasa, bukan AJAX.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.delete-log');
        if (!btn) return;
        e.preventDefault();
        var id = btn.getAttribute('data-log-id');
        confirmThen(<?php echo bltx_js($LANG['delete_log_confirm']); ?>, function () {
            document.getElementById('log_delete_all').value = '';
            document.getElementById('log_delete_one').value = '1';
            document.getElementById('log_delete_id').value  = id;
            form.submit();
        });
    });

    /* Init DataTables yang dulu ada di sini DIHAPUS — includes/footer.php
       sudah meng-inisialisasi setiap tabel ber-kelas .ui-datatable, dan
       ui_table_open() memasang kelas itu. */
});
</script>
