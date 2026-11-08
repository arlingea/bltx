<?php
/* Stanza guard baku Tahap 0.3 — lihat penjelasan dua-baris di
   settings/general.php. */
if (!function_exists('bltx_guard_fragment')) { http_response_code(403); exit('Direct access not permitted.'); }
bltx_guard_fragment();

// File: settings/settings_db_backup.php
// This file is included from settings/settings_gate.php - DO NOT access directly
// Tampilan (visibility tab ini) sudah digerbang oleh settings_gate.php lewat
// canAccessSettingsBackup(); unduhan sesungguhnya digerbang lagi secara
// independen di includes/export/backup_database.php (permission dari
// database, superadmin selalu full control).
?>

<div class="col-12 mb-4">

        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-database-down"></i> <?php echo bltx_e($LANG['backup_database']); ?>
                </div>
            </div>
            
            <div class="card-body">
                <p class="text-muted mb-3"><?php echo bltx_e($LANG['backup_database_info']); ?></p>
                <button class="btn btn-sm btn-warning" onclick="backupDatabase()" type="button">
                    <i class="bi bi-download"></i> <?php echo bltx_e($LANG['backup_database']); ?>
                </button>
            </div>
            
        </div>

</div>

<script>
function backupDatabase() {
    /* bltx_js() menggantikan addslashes(): addslashes hanya melindungi
       tanda kutip, tidak menutup <\/script> maupun konteks atribut. */
    Swal.fire({
        title:              <?php echo bltx_js($LANG['backup_database']); ?>,
        text:               <?php echo bltx_js($LANG['backup_database_confirm']); ?>,
        icon:               'warning',
        showCancelButton:   true,
        confirmButtonColor: '#d33',
        cancelButtonColor:  '#3085d6',
        confirmButtonText:  <?php echo bltx_js($LANG['yes']); ?>,
        cancelButtonText:   <?php echo bltx_js($LANG['cancel']); ?>,
        reverseButtons:     true
    }).then(function(result) {
        if (result.isConfirmed) {
            window.location.href = '<?php echo url('includes/export/backup_database.php'); ?>';

            // Notifikasi melayang (bukan modal SweetAlert2) — konsisten dengan
            // notifikasi di halaman-halaman lain. Konfirmasi di atas tetap
            // pakai SweetAlert2 karena itu dialog interaktif (perlu jawaban
            // ya/tidak), bukan notifikasi pasif.
            showFloatingAlert(
                <?php echo bltx_js($LANG['backup_success'] . ' — ' . $LANG['file_downloaded']); ?>,
                'success'
            );
        }
    });
}
</script>
