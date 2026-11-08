<?php
/* =============================================================================
   settings/currency.php  —  FRAGMEN TAB dari settings/settings_gate.php

   RINGKASAN PERUBAHAN TAHAP 2
     1. Guard direct-access memakai stanza baku Tahap 0.3.
     2. Token CSRF pada keempat handler POST — sebelumnya nol.
     3. Update & Delete metode pembayaran TIDAK LAGI lewat fetch()+JSON.
        Keduanya kini POST biasa -> proses -> redirect, sama seperti Add
        yang memang sudah begitu. Tiga jalur simpan yang dulu berbeda-beda
        sekarang seragam.
     4. Notifikasi lewat toast melayang bltx_render_flash() (Tahap 0.4);
        window.confirm() bawaan browser diganti dialog konfirmasi SweetAlert2.
     5. Tabel memakai helper ui_table_* — init DataTables kedua di berkas ini
        DIHAPUS, karena includes/footer.php sudah punya satu-satunya init dan
        init ganda memicu peringatan "re-initialise".
     6. Nilai kurs divalidasi di sisi server.

   TIDAK diubah: struktur multi-currency tetap tiga mata uang (IDR/USD/CNY)
   dengan kurs dibaca dari tabel `settings`. Tidak ada tabel `currencies`.
   ========================================================================== */
if (!function_exists('bltx_guard_fragment')) { http_response_code(403); exit('Direct access not permitted.'); }
bltx_guard_fragment();

$__cur_self = url('settings/settings_gate.php?tab=currency');

/* ===============================
   SIMPAN PENGATURAN KURS
=============================== */
if (isset($_POST['save_currency'])) {
    bltx_csrf_require($__cur_self);

    if (!isSuperAdmin() && !checkPermission('settings_currency', 'can_update')) {
        bltx_flash_error($LANG['no_permission']);
        redirect($__cur_self);
    }

    /* Sebelumnya nilai $_POST diteruskan apa adanya ke updateSetting().
       Kurs nol atau huruf akan membuat setiap konversi mata uang di seluruh
       sistem menghasilkan angka salah, jadi ditolak di sini. */
    $usd = $_POST['usd_rate'] ?? '';
    $cny = $_POST['cny_rate'] ?? '';

    if (!is_numeric($usd) || (float)$usd <= 0 || !is_numeric($cny) || (float)$cny <= 0) {
        bltx_flash_error($LANG['invalid_rate'] ?? $LANG['error_occurred']);
        redirect($__cur_self);
    }

    updateSetting('usd_rate', (float)$usd);
    updateSetting('cny_rate', (float)$cny);

    logActivity($_SESSION['user_id'], 'update_settings',
        'Updated currency rates: USD=' . (float)$usd . ', CNY=' . (float)$cny);

    bltx_flash_success($LANG['settings_saved']);
    redirect($__cur_self);
}

/* ===============================
   CRUD METODE PEMBAYARAN
=============================== */

// TAMBAH
if (isset($_POST['add_payment_method'])) {
    bltx_csrf_require($__cur_self);

    if (!isSuperAdmin() && !checkPermission('settings_currency', 'can_create')) {
        bltx_flash_error($LANG['no_permission']);
        redirect($__cur_self);
    }

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        bltx_flash_error($LANG['name_required']);
        redirect($__cur_self);
    }

    $code   = trim($_POST['code'] ?? '');
    $active = (isset($_POST['is_active']) && $_POST['is_active'] == '1') ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO payment_methods (name, code, is_active) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $name, $code, $active);

    if ($stmt->execute()) {
        logActivity($_SESSION['user_id'], 'add_payment_method', 'Added payment method: ' . $name);
        bltx_flash_success($LANG['settings_saved']);
    } else {
        // Detail kesalahan database masuk log, bukan ke layar pengunjung.
        error_log('[bltx] add payment_method failed: ' . $stmt->error);
        bltx_flash_error($LANG['error_occurred']);
    }
    $stmt->close();
    redirect($__cur_self);
}

// UBAH  — dulu fetch()+JSON, sekarang POST biasa
if (isset($_POST['update_payment_method'])) {
    bltx_csrf_require($__cur_self);

    if (!isSuperAdmin() && !checkPermission('settings_currency', 'can_update')) {
        bltx_flash_error($LANG['no_permission']);
        redirect($__cur_self);
    }

    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');

    if ($id <= 0) {
        bltx_flash_error($LANG['error_occurred']);
        redirect($__cur_self);
    }
    if ($name === '') {
        bltx_flash_error($LANG['name_required']);
        redirect($__cur_self);
    }

    $code   = trim($_POST['code'] ?? '');
    $active = (isset($_POST['is_active']) && $_POST['is_active'] == '1') ? 1 : 0;

    $stmt = $conn->prepare("UPDATE payment_methods SET name=?, code=?, is_active=? WHERE id=?");
    $stmt->bind_param("ssii", $name, $code, $active, $id);

    if ($stmt->execute()) {
        logActivity($_SESSION['user_id'], 'update_payment_method',
            'Updated payment method ID: ' . $id . ' - ' . $name);
        bltx_flash_success($LANG['settings_saved']);
    } else {
        error_log('[bltx] update payment_method failed: ' . $stmt->error);
        bltx_flash_error($LANG['error_occurred']);
    }
    $stmt->close();
    redirect($__cur_self);
}

// HAPUS — dulu fetch()+JSON, sekarang POST biasa
if (isset($_POST['delete_payment_method'])) {
    bltx_csrf_require($__cur_self);

    if (!isSuperAdmin() && !checkPermission('settings_currency', 'can_delete')) {
        bltx_flash_error($LANG['no_permission']);
        redirect($__cur_self);
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        bltx_flash_error($LANG['error_occurred']);
        redirect($__cur_self);
    }

    // Ambil nama sebelum dihapus, untuk catatan aktivitas.
    // Dulu query ini dirangkai langsung ("WHERE id=$id"); aman karena $id
    // sudah di-cast (int), tetapi kini memakai prepared statement supaya
    // polanya seragam dengan seluruh berkas ini.
    $pm_name = 'ID: ' . $id;
    $q = $conn->prepare("SELECT name FROM payment_methods WHERE id = ? LIMIT 1");
    if ($q) {
        $q->bind_param("i", $id);
        $q->execute();
        $r = $q->get_result();
        if ($r && $r->num_rows > 0) {
            $pm_name = $r->fetch_assoc()['name'];
        }
        $q->close();
    }

    $stmt = $conn->prepare("DELETE FROM payment_methods WHERE id=?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        logActivity($_SESSION['user_id'], 'delete_payment_method', 'Deleted payment method: ' . $pm_name);
        bltx_flash_success($LANG['delete_success'] ?? $LANG['settings_saved']);
    } else {
        error_log('[bltx] delete payment_method failed: ' . $stmt->error);
        bltx_flash_error($LANG['error_occurred']);
    }
    $stmt->close();
    redirect($__cur_self);
}

/* ===============================
   AMBIL DATA METODE PEMBAYARAN
=============================== */
$payment_methods = $conn->query("SELECT * FROM payment_methods ORDER BY id DESC");

$__cur_can_create = isSuperAdmin() || checkPermission('settings_currency', 'can_create');
$__cur_can_update = isSuperAdmin() || checkPermission('settings_currency', 'can_update');
$__cur_can_delete = isSuperAdmin() || checkPermission('settings_currency', 'can_delete');
?>

<?php bltx_render_flash('currency'); ?>

<div class="row align-items-start">

    <!-- Pengaturan Kurs -->
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-currency-exchange"></i> <?php echo bltx_e($LANG['currency_settings']); ?>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="<?php echo bltx_e($__cur_self); ?>">
                    <?php bltx_csrf_field(); ?>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label"><?php echo bltx_e($LANG['usd_rate']); ?></label>
                            <input type="number" step="0.01" min="0.01" class="form-control form-control-sm"
                                   name="usd_rate" value="<?php echo bltx_e(getSetting('usd_rate')); ?>" required>
                            <small class="form-text text-muted">1 USD = ? IDR</small>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label"><?php echo bltx_e($LANG['cny_rate']); ?></label>
                            <input type="number" step="0.01" min="0.01" class="form-control form-control-sm"
                                   name="cny_rate" value="<?php echo bltx_e(getSetting('cny_rate')); ?>" required>
                            <small class="form-text text-muted">1 CNY = ? IDR</small>
                        </div>
                    </div>
                    <div class="text-end mt-2">
                        <?php if ($__cur_can_update): ?>
                        <button type="submit" name="save_currency" class="btn btn-primary btn-sm">
                            <i class="bi bi-save me-1"></i> <?php echo bltx_e($LANG['save']); ?>
                        </button>
                        <?php else: ?>
                        <span class="text-muted small"><i class="bi bi-lock me-1"></i> <?php echo bltx_e($LANG['no_permission']); ?></span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Metode Pembayaran -->
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-credit-card"></i> <?php echo bltx_e($LANG['payment_method']); ?>
                </div>
                <?php if ($__cur_can_create): ?>
                <div class="card-tools">
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                        <i class="bi bi-plus-circle"></i> <?php echo bltx_e($LANG['add']); ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php
                /* Tabel manual diganti helper ui_table_* (Tahap 0.5 / item 2).
                   Kelas "ui-datatable" dipasang otomatis oleh ui_table_open(),
                   dan includes/footer.php yang meng-inisialisasinya. */
                ui_table_open('paymentTable', [
                    'small'   => true,
                    'columns' => [
                        ['label' => $LANG['name']],
                        ['label' => $LANG['code']],
                        ['label' => $LANG['action'], 'align' => 'center', 'width' => 130],
                    ],
                ]);

                if ($payment_methods && $payment_methods->num_rows > 0):
                    while ($pm = $payment_methods->fetch_assoc()):
                ?>
                <tr>
                    <td>
                        <?php echo bltx_e($pm['name']); ?>
                        <?php if (!$pm['is_active']): ?>
                            <span class="badge bg-secondary ms-1"><?php echo bltx_e($LANG['inactive']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo bltx_e($pm['code'] ?? ''); ?></td>
                    <td class="text-center">
                        <?php if ($__cur_can_update): ?>
                        <button type="button"
                                class="btn btn-warning btn-sm edit-payment"
                                data-id="<?php echo (int)$pm['id']; ?>"
                                data-name="<?php echo bltx_e($pm['name']); ?>"
                                data-code="<?php echo bltx_e($pm['code'] ?? ''); ?>"
                                data-active="<?php echo (int)$pm['is_active']; ?>"
                                title="<?php echo bltx_e($LANG['edit']); ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php endif; ?>

                        <?php if ($__cur_can_delete): ?>
                        <button type="button"
                                class="btn btn-danger btn-sm delete-payment"
                                data-id="<?php echo (int)$pm['id']; ?>"
                                data-name="<?php echo bltx_e($pm['name']); ?>"
                                title="<?php echo bltx_e($LANG['delete']); ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>

                        <?php if (!$__cur_can_update && !$__cur_can_delete): ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
                    endwhile;
                else:
                    ui_table_empty(3, $LANG['no_data']);
                endif;
                ui_table_close();
                ?>
            </div>
        </div>
    </div>

</div>

<!-- Formulir tersembunyi untuk penghapusan: POST biasa, bukan fetch. -->
<?php if ($__cur_can_delete): ?>
<form id="deletePaymentForm" method="POST" action="<?php echo bltx_e($__cur_self); ?>" class="d-none">
    <?php bltx_csrf_field(); ?>
    <input type="hidden" name="delete_payment_method" value="1">
    <input type="hidden" name="id" id="delete_payment_id">
</form>
<?php endif; ?>

<!-- Modal Tambah -->
<div class="modal fade" id="addPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="<?php echo bltx_e($__cur_self); ?>">
            <?php bltx_csrf_field(); ?>
            <input type="hidden" name="add_payment_method" value="1">
            <div class="modal-header">
                <h5 class="modal-title mb-0">
                    <i class="bi bi-plus-circle-fill text-primary me-1"></i> <?php echo bltx_e($LANG['add_payment_method']); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label"><?php echo bltx_e($LANG['name']); ?></label>
                    <input type="text" class="form-control form-control-sm" name="name" id="add_name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php echo bltx_e($LANG['code']); ?></label>
                    <input type="text" class="form-control form-control-sm" name="code" id="add_code">
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="is_active" id="add_active" value="1" checked>
                    <label class="form-check-label"><?php echo bltx_e($LANG['active']); ?></label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo bltx_e($LANG['cancel']); ?>
                </button>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-check-circle"></i> <?php echo bltx_e($LANG['save']); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Ubah -->
<div class="modal fade" id="editPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="<?php echo bltx_e($__cur_self); ?>">
            <?php bltx_csrf_field(); ?>
            <input type="hidden" name="update_payment_method" value="1">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-header">
                <h5 class="modal-title mb-0">
                    <i class="bi bi-pencil-square text-warning me-1"></i> <?php echo bltx_e($LANG['edit_payment_method']); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label"><?php echo bltx_e($LANG['name']); ?></label>
                    <input type="text" class="form-control form-control-sm" name="name" id="edit_name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php echo bltx_e($LANG['code']); ?></label>
                    <input type="text" class="form-control form-control-sm" name="code" id="edit_code">
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="is_active" id="edit_active" value="1">
                    <label class="form-check-label"><?php echo bltx_e($LANG['active']); ?></label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> <?php echo bltx_e($LANG['cancel']); ?>
                </button>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-check-circle"></i> <?php echo bltx_e($LANG['update']); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    document.addEventListener('click', function (e) {

        // TOMBOL UBAH — isi modal, lalu tampilkan. Simpannya lewat POST biasa.
        var editBtn = e.target.closest('.edit-payment');
        if (editBtn) {
            e.preventDefault();
            document.getElementById('edit_id').value       = editBtn.getAttribute('data-id');
            document.getElementById('edit_name').value     = editBtn.getAttribute('data-name');
            document.getElementById('edit_code').value     = editBtn.getAttribute('data-code');
            document.getElementById('edit_active').checked = (editBtn.getAttribute('data-active') === '1');

            var modalEl  = document.getElementById('editPaymentModal');
            var modalObj = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modalObj.show();
            return;
        }

        // TOMBOL HAPUS — SweetAlert2 dipakai di sini karena ini memang dialog
        // konfirmasi ya/tidak, bukan notifikasi hasil. Hasilnya nanti tampil
        // sebagai toast setelah redirect.
        var delBtn = e.target.closest('.delete-payment');
        if (delBtn) {
            e.preventDefault();
            var id   = delBtn.getAttribute('data-id');
            var name = delBtn.getAttribute('data-name') || '';

            Swal.fire({
                title:              <?php echo bltx_js($LANG['are_you_sure'] ?? $LANG['confirm']); ?>,
                text:               <?php echo bltx_js($LANG['delete_payment_confirm']); ?> + (name ? ' (' + name + ')' : ''),
                icon:               'warning',
                showCancelButton:   true,
                confirmButtonColor: '#d33',
                cancelButtonColor:  '#3085d6',
                confirmButtonText:  <?php echo bltx_js($LANG['delete']); ?>,
                cancelButtonText:   <?php echo bltx_js($LANG['cancel']); ?>,
                reverseButtons:     true
            }).then(function (result) {
                if (result.isConfirmed) {
                    document.getElementById('delete_payment_id').value = id;
                    document.getElementById('deletePaymentForm').submit();
                }
            });
            return;
        }
    });

    /* Init DataTables yang dulu ada di sini DIHAPUS. includes/footer.php
       sudah meng-inisialisasi setiap tabel ber-kelas .ui-datatable, dan
       ui_table_open() memasang kelas itu. Init kedua akan memicu peringatan
       "Cannot reinitialise DataTable". */
});
</script>
