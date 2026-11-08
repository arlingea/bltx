<?php
// ═══════════════════════════════════════════════════════════════════════════
//  UNITS — dual-mode (pola sama persis dengan goods_receipt.php)
//   • Standalone : /purchases/units.php                    (halaman penuh)
//   • Partial    : di-include oleh pr_dahsboard_gate.php    (tab=units)
//
//  Saat menjadi partial, header dan footer TIDAK di-include lagi karena hub
//  sudah mencetaknya; kalau tidak, halaman akan tercetak dua kali.
// ═══════════════════════════════════════════════════════════════════════════
$__units_included_as_partial = defined('APP_NAME') && isset($LANG, $conn);

if (!$__units_included_as_partial) {
    require_once '../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';

    // Tidak boleh diakses langsung tanpa login.
    if (!isLoggedIn()) redirect(url('index.php'));

    // GERBANG Purchase juga berlaku pada akses langsung, bukan cuma lewat hub.
    if (!canAccessPurchaseGate()) redirectNoAccess();

    $page_title = $LANG['units'];
    include '../includes/header.php';
}

if (!checkPermission('units', 'can_access')) {
    if ($__units_included_as_partial) {
        echo '<div class="alert alert-danger"><i class="bi bi-shield-exclamation"></i> '
           . htmlspecialchars($LANG['no_permission']) . '</div>';
        return;
    }
    redirectNoAccess();
}

// Tujuan redirect setelah CRUD: saat partial kembali ke tab-nya di hub,
// saat standalone kembali ke halaman ini sendiri.
$units_self_url = $__units_included_as_partial
    ? url('purchases/pr_dahsboard_gate.php?tab=units')
    : url('purchases/units.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $name        = sanitize($_POST['name']);
    $symbol      = sanitize($_POST['symbol']);
    $description = sanitize($_POST['description']);

    if ($_POST['action'] === 'add' && checkPermission('units', 'can_create')) {

        $stmt = $conn->prepare(
            "INSERT INTO units (name, symbol, description) VALUES (?, ?, ?)"
        );
        $stmt->bind_param("sss", $name, $symbol, $description);
        $stmt->execute();

        $_SESSION['success'] = $LANG['save_success'];
    }

    elseif ($_POST['action'] === 'edit' && checkPermission('units', 'can_update')) {

        $id = (int) $_POST['id'];

        $stmt = $conn->prepare(
            "UPDATE units SET name=?, symbol=?, description=? WHERE id=?"
        );
        $stmt->bind_param("sssi", $name, $symbol, $description, $id);
        $stmt->execute();

        $_SESSION['success'] = $LANG['update_success'];
    }

    redirect($units_self_url);
    exit;
}

// ── HAPUS: POST + token CSRF ──────────────────────────────────────────────
// Alasan sama dengan suppliers.php: tautan GET ?delete=<id> tanpa token bisa
// dipicu oleh <img> atau prefetch peramban tanpa klik pengguna.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'delete'
    && checkPermission('units', 'can_delete')) {

    if (!pf_csrfValid($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = $LANG['invalid_csrf'];
        redirect($units_self_url);
    }

    $del_id = (int)($_POST['id'] ?? 0);
    if ($del_id > 0) {
        $stmt = $conn->prepare("DELETE FROM units WHERE id = ?");
        $stmt->bind_param("i", $del_id);
        $stmt->execute();
        $stmt->close();
        if (function_exists('logActivity')) {
            logActivity('delete_unit', 'Deleted unit ID: ' . $del_id);
        }
        $_SESSION['success'] = $LANG['delete_success'];
    }
    redirect($units_self_url);
}

// ── CSRF ──────────────────────────────────────────────────────────────────
// Memakai pf_csrfToken() / pf_csrfValid() yang SUDAH ADA di
// includes/purchases_function.php — tidak membuat mekanisme token baru,
// sehingga tidak ada dua sistem CSRF yang berjalan berdampingan.
require_once __DIR__ . '/../includes/purchases_function.php';
$csrf_token = pf_csrfToken();
?>

<!-- Container pembungkus utama -->
<div class="row align-items-start">

    <!-- Notifikasi Berhasil -->
    <?php
    /* ══════════════════════════════════════════════════════════════════════
       NOTIFIKASI MELAYANG (pola sama persis dengan purchases_invoice.php)
       position:fixed sehingga melayang di pojok kanan atas viewport dan
       TIDAK mendorong konten halaman ke bawah — inilah bedanya dengan blok
       alert lama yang menyisipkan baris baru di atas kartu.
       Murni HTML + CSS inline: kalau JavaScript mati sekalipun, notifikasi
       tetap terlihat dan tetap bisa ditutup manual lewat tombol silang.
       Skrip di bawahnya hanya untuk menghilangkan otomatis setelah 4 detik.
       Isi pesan di-escape dengan htmlspecialchars sehingga tanda baca
       seperti ' " & tampil apa adanya.
       ══════════════════════════════════════════════════════════════════ */
    ?>
    <?php
    /* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4 — menggantikan
       dua blok alert dan satu blok <script> yang isinya identik dengan
       berkas lain. Kunci sesi tidak diubah, hanya cara merendernya. */
    bltx_render_flash('units');
    ?>

    <div class="col-12 mb-4">
        <div class="card">
            <!-- Header disesuaikan dengan flexbox -->
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-rulers me-1"></i> <?php echo $LANG['unit_list']; ?>
                </div>
                <?php if (checkPermission('units', 'can_create')): ?>
                <div class="card-tools">
                    <?php
                /* Tombol ekspor memakai modul permission YANG SUDAH ADA
                   ('units' can_export) — tidak ada modul izin baru.
                   Endpoint export_purchases.php sudah diperluas untuk
                   menangani dataset ini. Gaya tombol mengikuti returns.php:
                   Excel = btn-success, PDF = btn-danger. */
                ?>
                <?php if (checkPermission('units', 'can_export')): ?>
                <a href="<?= url('includes/export/export_purchases.php?type=excel&data=units') ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-excel"></i> <?= htmlspecialchars($LANG['export_excel']) ?>
                </a>
                <?php endif; ?>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-plus-circle"></i> <?php echo $LANG['add']; ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="card-body">
                <?php
                // Tabel disesuaikan dengan format activity.php (striped, hover, small, datatable)
                ui_table_open('dataTable', [
                    'columns' => [
                        ['label' => $LANG['unit_name']],
                        ['label' => $LANG['symbol'],      'width' => 110],
                        ['label' => $LANG['description']],
                        ['label' => $LANG['actions'],     'width' => 130, 'align' => 'center'],
                    ],
                    'striped'   => true,
                    'hover'     => true,
                    'small'     => true,
                    'datatable' => true,
                ]);

                $result = $conn->query("SELECT * FROM units ORDER BY name");
                if ($result && $result->num_rows > 0):
                    while ($row = $result->fetch_assoc()):
                ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($row['symbol']); ?></span></td>
                            <td><?php echo htmlspecialchars($row['description'] ?? '-'); ?></td>
                            <td class="text-center">
                                <!-- Dibungkus d-flex agar rapi sejajar -->
                                <div class="d-flex justify-content-center gap-1">
                                    <?php if (checkPermission('units', 'can_update')): ?>
                                    <button class="btn btn-sm btn-warning"
                                        onclick='editUnit(<?php echo json_encode($row, JSON_HEX_APOS|JSON_HEX_QUOT); ?>)' title="<?= htmlspecialchars($LANG['edit']) ?>">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <?php endif; ?>

                                    <?php if (checkPermission('units', 'can_delete')): ?>
                                    <form method="POST" class="d-inline pur-confirm-form">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="<?= htmlspecialchars($LANG['delete']) ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                <?php
                    endwhile;
                else:
                    ui_table_empty(4, $LANG['no_data']);
                endif;

                ui_table_close();
                ?>
            </div>
        </div>
    </div>

</div>

<!-- ADD MODAL -->
<div class="modal fade" id="addModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="add">

                <div class="modal-header py-2">
                    <h5 class="modal-title mb-0 d-flex align-items-center">
                        <i class="bi bi-plus-circle me-2 text-primary"></i>
                        <?php echo $LANG['add'].' '.$LANG['unit']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['unit_name']; ?> *</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['symbol']; ?> *</label>
                        <input type="text" class="form-control" name="symbol" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['description']; ?></label>
                        <textarea class="form-control" name="description" rows="2"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <?php echo $LANG['cancel']; ?>
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save me-1"></i> <?php echo $LANG['save']; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<div class="modal fade" id="editModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">

                <div class="modal-header py-2">
                    <h5 class="modal-title mb-0 d-flex align-items-center">
                        <i class="bi bi-pencil-square me-2 text-warning"></i>
                        <?php echo $LANG['edit'].' '.$LANG['unit']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['unit_name']; ?> *</label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['symbol']; ?> *</label>
                        <input type="text" class="form-control" name="symbol" id="edit_symbol" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $LANG['description']; ?></label>
                        <textarea class="form-control" name="description" id="edit_description" rows="2"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <?php echo $LANG['cancel']; ?>
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save me-1"></i> <?php echo $LANG['update']; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editUnit(d) {
    $('#edit_id').val(d.id);
    $('#edit_name').val(d.name);
    $('#edit_symbol').val(d.symbol);
    $('#edit_description').val(d.description);
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>


<script>
/* Konfirmasi hapus memakai SweetAlert2 untuk form POST .pur-confirm-form.
   Menggantikan confirmDelete() lama yang mengarahkan ke tautan GET —
   penghapusan kini selalu lewat POST bertoken, jadi tidak bisa lagi dipicu
   sekadar dengan membuka sebuah URL. Pola dan teks tombolnya sama persis
   dengan confirmDelete() di includes/footer.php. */
document.querySelectorAll('.pur-confirm-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        Swal.fire({
            title: '<?= htmlspecialchars($LANG['confirm'], ENT_QUOTES) ?>',
            text:  '<?= htmlspecialchars($LANG['confirm_delete'], ENT_QUOTES) ?>',
            icon:  'warning',
            showCancelButton:   true,
            confirmButtonColor: '#d33',
            cancelButtonColor:  '#3085d6',
            confirmButtonText:  '<?= htmlspecialchars($LANG['yes'], ENT_QUOTES) ?>',
            cancelButtonText:   '<?= htmlspecialchars($LANG['cancel'], ENT_QUOTES) ?>',
            reverseButtons: true
        }).then(function (result) {
            if (result.isConfirmed) form.submit();
        });
    });
});
</script>

<?php if (!$__units_included_as_partial) include '../includes/footer.php'; ?>