<?php
// ═══════════════════════════════════════════════════════════════════════════
//  SUPPLIERS — dual-mode (pola sama persis dengan goods_receipt.php)
//   • Standalone : /purchases/suppliers.php                    (halaman penuh)
//   • Partial    : di-include oleh pr_dahsboard_gate.php    (tab=suppliers)
//
//  Saat menjadi partial, header dan footer TIDAK di-include lagi karena hub
//  sudah mencetaknya; kalau tidak, halaman akan tercetak dua kali.
// ═══════════════════════════════════════════════════════════════════════════
$__suppliers_included_as_partial = defined('APP_NAME') && isset($LANG, $conn);

if (!$__suppliers_included_as_partial) {
    require_once '../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';

    // Tidak boleh diakses langsung tanpa login.
    if (!isLoggedIn()) redirect(url('index.php'));

    // GERBANG Purchase juga berlaku pada akses langsung, bukan cuma lewat hub.
    if (!canAccessPurchaseGate()) redirectNoAccess();

    $page_title = $LANG['suppliers'];
    include '../includes/header.php';
}

// ── Daftar bank untuk dropdown Bank Name ─────────────────────────────────
// Daftarnya berada di berkas terpisah supaya bisa diperbarui tanpa menyentuh
// berkas ini. require_once bersifat idempoten, aman dipanggil di kedua mode.
require_once __DIR__ . '/../includes/banks_id.php';

/**
 * Cetak satu dropdown bank yang bisa dicari.
 *
 * Dipakai DUA KALI (modal Add dan modal Edit) dengan markup yang sama persis,
 * jadi kalau tampilannya diubah cukup di satu tempat.
 *
 * Nilai yang dikirim ke server tetap lewat <input type="hidden" name="bank_name">,
 * berisi NAMA BANK sebagai teks — sama seperti input teks sebelumnya. Karena
 * itu proses INSERT/UPDATE di bagian atas berkas ini TIDAK perlu diubah sama
 * sekali, dan kolom suppliers.bank_name tetap varchar(100) apa adanya.
 *
 * @param string $idPrefix  'add' atau 'edit' (untuk id elemen)
 * @param string $current   nilai tersimpan saat ini (boleh kosong)
 */
function sup_renderBankPicker(string $idPrefix, string $current = ''): void
{
    global $LANG;
    $banks   = bank_getList();
    $logoMap = bank_getLogoMap();
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    // Pola penjaga yang sama dengan settings/purchase_settings.php: pakai
    // mb_strtolower bila ekstensi mbstring tersedia, kalau tidak jatuh ke
    // strtolower biasa. Seluruh nama bank memang ASCII, jadi hasilnya sama.
    $lc = static fn($v) => function_exists('mb_strtolower')
        ? mb_strtolower((string)$v, 'UTF-8')
        : strtolower((string)$v);

    // Cari apakah nilai tersimpan cocok dengan salah satu bank di daftar.
    $matched = null;
    foreach ($banks as $b) {
        if (strcasecmp($b['name'], $current) === 0) { $matched = $b; break; }
    }
    ?>
    <div class="bank-picker" id="<?= $h($idPrefix) ?>_bank_picker">
        <input type="hidden" name="bank_name" id="<?= $h($idPrefix) ?>_bank_name" value="<?= $h($current) ?>">

        <button type="button" class="form-control bank-picker-toggle" aria-haspopup="listbox" aria-expanded="false">
            <span class="bank-picker-current">
                <?php if ($matched): ?>
                    <?php if (isset($logoMap[$matched['code']])): ?>
                        <img class="bank-logo" src="<?= $h($logoMap[$matched['code']]) ?>" alt="">
                    <?php else: ?>
                        <span class="bank-avatar" style="background:<?= $h($matched['color']) ?>"><?= $h($matched['short']) ?></span>
                    <?php endif; ?>
                    <span class="bank-picker-label"><?= $h($matched['name']) ?></span>
                <?php elseif ($current !== ''): ?>
                    <span class="bank-avatar bank-avatar-plain"><i class="bi bi-bank"></i></span>
                    <span class="bank-picker-label"><?= $h($current) ?></span>
                <?php else: ?>
                    <span class="bank-avatar bank-avatar-plain"><i class="bi bi-bank"></i></span>
                    <span class="bank-picker-label text-muted"><?= $h($LANG['bank_select']) ?></span>
                <?php endif; ?>
            </span>
            <i class="bi bi-chevron-down bank-picker-caret"></i>
        </button>

        <div class="bank-picker-menu" role="listbox">
            <div class="bank-picker-search">
                <i class="bi bi-search"></i>
                <input type="text" class="form-control form-control-sm" autocomplete="off"
                       placeholder="<?= $h($LANG['bank_search']) ?>">
            </div>

            <div class="bank-picker-list">
                <?php
                // Nilai lama yang TIDAK ada di daftar bank (misalnya "QRIS-Based"
                // atau "BCA (Virtual Account)") sengaja ditampilkan sebagai
                // pilihan pertama supaya tidak hilang saat supplier disunting.
                if ($current !== '' && !$matched): ?>
                <div class="bank-picker-item is-selected" role="option"
                     data-value="<?= $h($current) ?>"
                     data-search="<?= $h($lc($current)) ?>"
                     data-avatar="plain">
                    <span class="bank-avatar bank-avatar-plain"><i class="bi bi-bank"></i></span>
                    <span class="bank-picker-name"><?= $h($current) ?></span>
                </div>
                <?php endif; ?>

                <div class="bank-picker-item<?= $current === '' ? ' is-selected' : '' ?>" role="option"
                     data-value="" data-search="" data-avatar="plain">
                    <span class="bank-avatar bank-avatar-plain"><i class="bi bi-dash"></i></span>
                    <span class="bank-picker-name text-muted"><?= $h($LANG['bank_none']) ?></span>
                </div>

                <?php foreach ($banks as $b):
                    $hasLogo = isset($logoMap[$b['code']]);
                    // Pencarian mencakup nama DAN singkatan, jadi mengetik
                    // "bca" maupun "central asia" sama-sama ketemu.
                    $needle  = $lc($b['name'] . ' ' . $b['short'] . ' ' . $b['code']);
                    $isSel   = $matched && $matched['code'] === $b['code'];
                ?>
                <div class="bank-picker-item<?= $isSel ? ' is-selected' : '' ?>" role="option"
                     data-value="<?= $h($b['name']) ?>"
                     data-search="<?= $h($needle) ?>"
                     data-avatar="<?= $hasLogo ? 'logo' : 'text' ?>"
                     data-logo="<?= $hasLogo ? $h($logoMap[$b['code']]) : '' ?>"
                     data-short="<?= $h($b['short']) ?>"
                     data-color="<?= $h($b['color']) ?>">
                    <?php if ($hasLogo): ?>
                        <img class="bank-logo" src="<?= $h($logoMap[$b['code']]) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="bank-avatar" style="background:<?= $h($b['color']) ?>"><?= $h($b['short']) ?></span>
                    <?php endif; ?>
                    <span class="bank-picker-name"><?= $h($b['name']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="bank-picker-empty d-none"><?= $h($LANG['no_data']) ?></div>
        </div>
    </div>
    <?php
}

if (!checkPermission('suppliers', 'can_access')) {
    if ($__suppliers_included_as_partial) {
        echo '<div class="alert alert-danger"><i class="bi bi-shield-exclamation"></i> '
           . htmlspecialchars($LANG['no_permission']) . '</div>';
        return;
    }
    redirectNoAccess();
}

// Tujuan redirect setelah CRUD: saat partial kembali ke tab-nya di hub,
// saat standalone kembali ke halaman ini sendiri.
$suppliers_self_url = $__suppliers_included_as_partial
    ? url('purchases/pr_dahsboard_gate.php?tab=suppliers')
    : url('purchases/suppliers.php');

/* =========================
   CRUD
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {

        if ($_POST['action'] === 'add' && checkPermission('suppliers', 'can_create')) {

            $stmt = $conn->prepare("INSERT INTO suppliers (name, contact_person, phone, email, address, tax_number, postal_code, website, payment_terms, bank_name, bank_account, account_name, credit_limit, rating, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param(
                "ssssssssssssdii",
                $_POST['name'],
                $_POST['contact_person'],
                $_POST['phone'],
                $_POST['email'],
                $_POST['address'],
                $_POST['tax_number'],
                $_POST['postal_code'],
                $_POST['website'],
                $_POST['payment_terms'],
                $_POST['bank_name'],
                $_POST['bank_account'],
                $_POST['account_name'],
                $_POST['credit_limit'],
                $_POST['rating'],
                $_POST['status']
            );
            $stmt->execute();

            $_SESSION['success'] = $LANG['save_success'];
        }

        if ($_POST['action'] === 'edit' && checkPermission('suppliers', 'can_update')) {

            $stmt = $conn->prepare("UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=?, tax_number=?, postal_code=?, website=?, payment_terms=?, bank_name=?, bank_account=?, account_name=?, credit_limit=?, rating=?, status=? WHERE id=?");
            $stmt->bind_param(
                "ssssssssssssdiii",
                $_POST['name'],
                $_POST['contact_person'],
                $_POST['phone'],
                $_POST['email'],
                $_POST['address'],
                $_POST['tax_number'],
                $_POST['postal_code'],
                $_POST['website'],
                $_POST['payment_terms'],
                $_POST['bank_name'],
                $_POST['bank_account'],
                $_POST['account_name'],
                $_POST['credit_limit'],
                $_POST['rating'],
                $_POST['status'],
                $_POST['id']
            );
            $stmt->execute();

            $_SESSION['success'] = $LANG['update_success'];
        }

        redirect($suppliers_self_url);
    }
}

// ── HAPUS: POST + token CSRF ──────────────────────────────────────────────
// Sebelumnya penghapusan dilakukan lewat tautan GET ?delete=<id> tanpa token.
// Cara itu berbahaya: sebuah <img src="...?delete=5"> di halaman mana pun —
// bahkan di situs lain — akan menghapus data begitu halaman dibuka, tanpa
// klik sama sekali. Peramban dan pemindai tautan juga bisa memicunya.
// Sekarang penghapusan hanya menerima POST dan wajib menyertakan token.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'delete'
    && checkPermission('suppliers', 'can_delete')) {

    if (!pf_csrfValid($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = $LANG['invalid_csrf'];
        redirect($suppliers_self_url);
    }

    $del_id = (int)($_POST['id'] ?? 0);
    if ($del_id > 0) {
        $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
        $stmt->bind_param("i", $del_id);
        $stmt->execute();
        $stmt->close();
        if (function_exists('logActivity')) {
            logActivity('delete_supplier', 'Deleted supplier ID: ' . $del_id);
        }
        $_SESSION['success'] = $LANG['delete_success'];
    }
    redirect($suppliers_self_url);
}

// ── CSRF ──────────────────────────────────────────────────────────────────
// Memakai pf_csrfToken() / pf_csrfValid() yang SUDAH ADA di
// includes/purchases_function.php — tidak membuat mekanisme token baru,
// sehingga tidak ada dua sistem CSRF yang berjalan berdampingan.
require_once __DIR__ . '/../includes/purchases_function.php';
$csrf_token = pf_csrfToken();

$result = $conn->query("SELECT * FROM suppliers ORDER BY name");
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
       seperti ' " & tampil apa adanya, bukan menjadi &amp; dan sejenisnya.
       ══════════════════════════════════════════════════════════════════ */
    ?>
    <?php
    /* Toast melayang (A4.1) lewat helper terpusat Tahap 0.4 — menggantikan
       dua blok alert dan satu blok <script> yang isinya identik dengan
       berkas lain. Kunci sesi tidak diubah, hanya cara merendernya. */
    bltx_render_flash('suppliers');
    ?>

    <div class="col-12 mb-4">
        <div class="card">
            <!-- Header disesuaikan dengan flexbox -->
            <div class="card-header">
                <div class="card-title">
                    <i class="bi bi-people-fill me-1"></i> <?= $LANG['supplier_list'] ?>
                </div>
                <div class="card-tools d-flex gap-1">
                    <a href="<?= url('includes/export/export_purchases.php?type=excel&data=suppliers') ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-file-earmark-excel"></i> <?= $LANG['export_excel'] ?>
                    </a>
                    <?php if (checkPermission('suppliers', 'can_create')): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-plus-circle"></i> <?= $LANG['add'] ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card-body">
                <?php
                // Tabel disesuaikan dengan format template activity.php (striped, hover, small, datatable)
                ui_table_open('supplierTable', [
                    'columns' => [
                        ['label' => $LANG['supplier_name']],
                        ['label' => $LANG['contact_person']],
                        ['label' => $LANG['phone']],
                        ['label' => $LANG['email']],
                        ['label' => $LANG['payment_terms']],
                        ['label' => $LANG['bank_info']],
                        ['label' => $LANG['credit_limit']],
                        ['label' => $LANG['rating']],
                        ['label' => $LANG['status']],
                        ['label' => $LANG['actions'], 'width' => 120, 'align' => 'center'],
                    ],
                    'striped'   => true,
                    'hover'     => true,
                    'small'     => true,
                    'datatable' => true,
                ]);

                if ($result && $result->num_rows > 0):
                    while ($row = $result->fetch_assoc()):
                ?>
                        <tr>
                            <td><?= htmlspecialchars($row['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></td>
                            <td><?= $row['contact_person'] ? htmlspecialchars($row['contact_person'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '-' ?></td>
                            <td><?= $row['phone'] ? htmlspecialchars($row['phone'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '-' ?></td>
                            <td><?= $row['email'] ? htmlspecialchars($row['email'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '-' ?></td>
                            <td><?= $row['payment_terms'] ? htmlspecialchars($row['payment_terms'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '-' ?></td>
                            <td>
                                <?php
                                $bank_parts = array_filter([
                                    $row['bank_name']    ? htmlspecialchars($row['bank_name'],    ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
                                    $row['bank_account'] ? htmlspecialchars($row['bank_account'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
                                    $row['account_name'] ? htmlspecialchars($row['account_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
                                ]);
                                echo $bank_parts ? implode('<br>', $bank_parts) : '-';
                                ?>
                            </td>
                            <td><?= $row['credit_limit'] ? number_format($row['credit_limit'], 2, '.', ',') : '0.00' ?></td>
                            <td><?= str_repeat('&#9733;', (int)$row['rating']) . str_repeat('&#9734;', max(0, 5 - (int)$row['rating'])) ?></td>
                            <td class="text-center">
                                <?php if ((int)$row['status'] === 1): ?>
                                    <span class="badge bg-success"><?= $LANG['active'] ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?= $LANG['inactive'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <!-- Dibungkus d-flex agar rapi sejajar -->
                                <div class="d-flex justify-content-center gap-1">
                                    <?php if (checkPermission('suppliers', 'can_update')): ?>
                                    <button type="button"
                                        class="btn btn-sm btn-warning"
                                        onclick='editSupplier(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="<?= htmlspecialchars($LANG['edit'] ?? 'Edit') ?>">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <?php endif; ?>

                                    <?php if (checkPermission('suppliers', 'can_delete')): ?>
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
                    ui_table_empty(10, $LANG['no_data'] ?? 'No data found');
                endif;

                ui_table_close();
                ?>
            </div>
        </div>
    </div>
</div>

<!-- ========================= ADD MODAL ========================= -->
<?php if (checkPermission('suppliers', 'can_create')): ?>
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" id="addForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="add">

                <div class="modal-header py-2">
                    <h5 class="modal-title mb-0 d-flex align-items-center" id="addModalLabel">
                        <i class="bi bi-plus-circle me-2 text-primary"></i>
                        <?= $LANG['add'] . ' ' . $LANG['supplier'] ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body" style="max-height: 65vh; overflow-y: auto;">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['supplier_name'] ?> *</label>
                            <input class="form-control" name="name" required autocomplete="organization">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['contact_person'] ?></label>
                            <input class="form-control" name="contact_person" autocomplete="name">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['phone'] ?></label>
                            <input class="form-control" name="phone" inputmode="tel" autocomplete="tel">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['email'] ?></label>
                            <input type="email" class="form-control" name="email" inputmode="email" autocomplete="email">
                        </div>

                        <div class="col-12">
                            <label class="form-label"><?= $LANG['address'] ?></label>
                            <textarea class="form-control" name="address" rows="2" autocomplete="street-address"></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['postal_code'] ?></label>
                            <input class="form-control" name="postal_code" inputmode="numeric" autocomplete="postal-code">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['tax_number'] ?></label>
                            <input class="form-control" name="tax_number">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['website'] ?></label>
                            <input type="url" class="form-control" name="website" placeholder="https://" inputmode="url">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['payment_terms'] ?></label>
                            <input class="form-control" name="payment_terms" placeholder="<?= htmlspecialchars($LANG['eg_payment_terms']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= $LANG['bank_name'] ?></label>
                            <?php sup_renderBankPicker('add'); ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= $LANG['bank_account'] ?></label>
                            <input class="form-control" name="bank_account" inputmode="numeric">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= $LANG['account_name'] ?></label>
                            <input class="form-control" name="account_name">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= $LANG['credit_limit'] ?></label>
                            <input type="number" step="0.01" min="0" class="form-control" name="credit_limit" value="0" inputmode="decimal">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= $LANG['rating'] ?> (0-5)</label>
                            <select class="form-select" name="rating">
                                <option value="0">0 - No Rating</option>
                                <option value="1">1 &#9733;</option>
                                <option value="2">2 &#9733;&#9733;</option>
                                <option value="3">3 &#9733;&#9733;&#9733;</option>
                                <option value="4">4 &#9733;&#9733;&#9733;&#9733;</option>
                                <option value="5">5 &#9733;&#9733;&#9733;&#9733;&#9733;</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= $LANG['status'] ?></label>
                            <select class="form-select" name="status">
                                <option value="1"><?= $LANG['active'] ?></option>
                                <option value="0"><?= $LANG['inactive'] ?></option>
                            </select>
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= $LANG['cancel'] ?></button>
                    <button type="submit" class="btn btn-primary btn-sm" id="addSubmitBtn">
                        <span class="submit-label"><i class="bi bi-save me-1"></i> <?= $LANG['save'] ?></span>
                        <span class="submit-spinner d-none">
                            <span class="spinner-border spinner-border-sm me-1"></span><?= $LANG['save'] ?>...
                        </span>
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========================= EDIT MODAL ========================= -->
<?php if (checkPermission('suppliers', 'can_update')): ?>
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">

            <form method="POST" id="editForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">

                <div class="modal-header py-2">
                    <h5 class="modal-title mb-0 d-flex align-items-center" id="editModalLabel">
                        <i class="bi bi-pencil-square me-2 text-warning"></i>
                        <?= $LANG['edit'] . ' ' . $LANG['supplier'] ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body" style="max-height: 65vh; overflow-y: auto;">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['supplier_name'] ?> *</label>
                            <input class="form-control" name="name" id="edit_name" required autocomplete="organization">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['contact_person'] ?></label>
                            <input class="form-control" name="contact_person" id="edit_contact_person" autocomplete="name">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['phone'] ?></label>
                            <input class="form-control" name="phone" id="edit_phone" inputmode="tel" autocomplete="tel">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['email'] ?></label>
                            <input class="form-control" name="email" id="edit_email" inputmode="email" autocomplete="email">
                        </div>

                        <div class="col-12">
                            <label class="form-label"><?= $LANG['address'] ?></label>
                            <textarea class="form-control" name="address" id="edit_address" rows="2" autocomplete="street-address"></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['postal_code'] ?></label>
                            <input class="form-control" name="postal_code" id="edit_postal_code" inputmode="numeric" autocomplete="postal-code">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['tax_number'] ?></label>
                            <input class="form-control" name="tax_number" id="edit_tax_number">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['website'] ?></label>
                            <input type="url" class="form-control" name="website" id="edit_website" placeholder="https://" inputmode="url">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= $LANG['payment_terms'] ?></label>
                            <input class="form-control" name="payment_terms" id="edit_payment_terms" placeholder="<?= htmlspecialchars($LANG['eg_payment_terms']) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= $LANG['bank_name'] ?></label>
                            <?php sup_renderBankPicker('edit'); ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= $LANG['bank_account'] ?></label>
                            <input class="form-control" name="bank_account" id="edit_bank_account" inputmode="numeric">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= $LANG['account_name'] ?></label>
                            <input class="form-control" name="account_name" id="edit_account_name">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= $LANG['credit_limit'] ?></label>
                            <input type="number" step="0.01" min="0" class="form-control" name="credit_limit" id="edit_credit_limit" inputmode="decimal">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= $LANG['rating'] ?> (0-5)</label>
                            <select class="form-select" name="rating" id="edit_rating">
                                <option value="0">0 - No Rating</option>
                                <option value="1">1 &#9733;</option>
                                <option value="2">2 &#9733;&#9733;</option>
                                <option value="3">3 &#9733;&#9733;&#9733;</option>
                                <option value="4">4 &#9733;&#9733;&#9733;&#9733;</option>
                                <option value="5">5 &#9733;&#9733;&#9733;&#9733;&#9733;</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?= $LANG['status'] ?></label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="1"><?= $LANG['active'] ?></option>
                                <option value="0"><?= $LANG['inactive'] ?></option>
                            </select>
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= $LANG['cancel'] ?></button>
                    <button type="submit" class="btn btn-primary btn-sm" id="editSubmitBtn">
                        <span class="submit-label"><i class="bi bi-save me-1"></i> <?= $LANG['update'] ?></span>
                        <span class="submit-spinner d-none">
                            <span class="spinner-border spinner-border-sm me-1"></span><?= $LANG['update'] ?>...
                        </span>
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========================= JS ========================= -->
<?php if (checkPermission('suppliers', 'can_update')): ?>
<style>
/* ══════════════════════════════════════════════════════════════════════════
   DROPDOWN BANK — dibangun dari Bootstrap + JS biasa

   SENGAJA TIDAK memakai library pihak ketiga (Select2/Choices.js), supaya
   tidak ada aset vendor baru yang harus diunggah dan halaman tidak bertambah
   berat. Seluruh gayanya memakai variabel warna Bootstrap agar mode gelap
   ikut benar tanpa aturan tambahan.
   ══════════════════════════════════════════════════════════════════════ */
.bank-picker { position:relative; }
.bank-picker-toggle {
    display:flex; align-items:center; justify-content:space-between; gap:.5rem;
    width:100%; text-align:left; cursor:pointer; overflow:hidden;
}
.bank-picker-current { display:flex; align-items:center; gap:.5rem; min-width:0; }
.bank-picker-label   { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.bank-picker-caret   { flex-shrink:0; font-size:.75rem; opacity:.6; transition:transform .15s ease; }
.bank-picker.is-open .bank-picker-caret { transform:rotate(180deg); }

/* Avatar teks: dipakai untuk bank yang berkas logonya belum tersedia. */
.bank-avatar {
    flex-shrink:0; display:inline-flex; align-items:center; justify-content:center;
    width:34px; height:22px; border-radius:3px; color:#fff;
    font-size:.62rem; font-weight:700; letter-spacing:.02em; line-height:1;
}
.bank-avatar-plain { background:transparent; color:var(--bs-secondary-color,#6c757d); font-size:.9rem; }
/* Logo asli: object-fit contain menjaga rasio, gambar tidak pernah gepeng. */
.bank-logo { flex-shrink:0; width:34px; height:22px; object-fit:contain; }

.bank-picker-menu {
    display:none; position:absolute; z-index:1060; top:calc(100% + 2px); left:0; right:0;
    background:var(--bs-body-bg,#fff); border:1px solid var(--bs-border-color,#dee2e6);
    border-radius:.375rem; box-shadow:0 .5rem 1rem rgba(0,0,0,.15); overflow:hidden;
}
.bank-picker.is-open .bank-picker-menu { display:block; }
.bank-picker-search {
    position:relative; padding:.5rem; border-bottom:1px solid var(--bs-border-color,#dee2e6);
}
.bank-picker-search .bi-search {
    position:absolute; left:1rem; top:50%; transform:translateY(-50%);
    font-size:.8rem; opacity:.5; pointer-events:none;
}
.bank-picker-search .form-control { padding-left:1.9rem; }
.bank-picker-list  { max-height:260px; overflow-y:auto; }
.bank-picker-item  {
    display:flex; align-items:center; gap:.5rem; padding:.4rem .75rem;
    cursor:pointer; font-size:.875rem;
}
.bank-picker-item:hover,
.bank-picker-item.is-active   { background:var(--bs-tertiary-bg,#f8f9fa); }
.bank-picker-item.is-selected { font-weight:600; }
.bank-picker-name  { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.bank-picker-empty { padding:.75rem; font-size:.85rem; text-align:center; color:var(--bs-secondary-color,#6c757d); }
</style>

<script>
/* ══════════════════════════════════════════════════════════════════════════
   DROPDOWN BANK — perilaku

   Nilai yang benar-benar dikirim ke server ada di <input type="hidden"
   name="bank_name">. Tombol dan daftar hanyalah lapisan tampilan, sehingga
   proses simpan di sisi PHP tidak berubah sedikit pun.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
    "use strict";

    function closeAll(except) {
        document.querySelectorAll('.bank-picker.is-open').forEach(function (p) {
            if (p !== except) p.classList.remove('is-open');
        });
    }

    /* Perbarui tampilan tombol mengikuti satu baris pilihan. */
    function paintToggle(picker, item) {
        var cur = picker.querySelector('.bank-picker-current');
        if (!cur) return;
        var kind  = item.getAttribute('data-avatar');
        var label = item.querySelector('.bank-picker-name');
        var text  = label ? label.textContent : '';
        var muted = item.getAttribute('data-value') === '';
        var head;

        if (kind === 'logo') {
            head = '<img class="bank-logo" src="' + item.getAttribute('data-logo') + '" alt="">';
        } else if (kind === 'text') {
            head = '<span class="bank-avatar" style="background:' + item.getAttribute('data-color') + '">'
                 + item.getAttribute('data-short') + '</span>';
        } else {
            head = '<span class="bank-avatar bank-avatar-plain"><i class="bi bi-bank"></i></span>';
        }
        cur.innerHTML = head + '<span class="bank-picker-label' + (muted ? ' text-muted' : '') + '"></span>';
        cur.querySelector('.bank-picker-label').textContent = text;
    }

    function select(picker, item) {
        var hidden = picker.querySelector('input[type="hidden"]');
        if (hidden) hidden.value = item.getAttribute('data-value') || '';
        picker.querySelectorAll('.bank-picker-item.is-selected')
              .forEach(function (el) { el.classList.remove('is-selected'); });
        item.classList.add('is-selected');
        paintToggle(picker, item);
        picker.classList.remove('is-open');
    }

    function filter(picker, q) {
        q = (q || '').trim().toLowerCase();
        var shown = 0;
        picker.querySelectorAll('.bank-picker-item').forEach(function (it) {
            var hay = it.getAttribute('data-search') || '';
            /* Baris "(none)" punya data-search kosong: selalu ditampilkan saat
               kotak cari kosong, disembunyikan begitu pengguna mengetik. */
            var ok = q === '' ? true : hay.indexOf(q) !== -1;
            it.classList.toggle('d-none', !ok);
            if (ok) shown++;
        });
        var empty = picker.querySelector('.bank-picker-empty');
        if (empty) empty.classList.toggle('d-none', shown > 0);
    }

    document.addEventListener('click', function (e) {
        var toggle = e.target.closest ? e.target.closest('.bank-picker-toggle') : null;
        if (toggle) {
            var picker = toggle.closest('.bank-picker');
            var open   = picker.classList.contains('is-open');
            closeAll(picker);
            picker.classList.toggle('is-open', !open);
            toggle.setAttribute('aria-expanded', String(!open));
            if (!open) {
                var box = picker.querySelector('.bank-picker-search input');
                if (box) { box.value = ''; filter(picker, ''); setTimeout(function () { box.focus(); }, 10); }
            }
            return;
        }
        var item = e.target.closest ? e.target.closest('.bank-picker-item') : null;
        if (item) { select(item.closest('.bank-picker'), item); return; }
        if (!(e.target.closest && e.target.closest('.bank-picker'))) closeAll(null);
    });

    document.addEventListener('input', function (e) {
        var box = e.target.closest ? e.target.closest('.bank-picker-search input') : null;
        if (box) filter(box.closest('.bank-picker'), box.value);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAll(null);
    });

    /* Dipakai editSupplier(): mengisi dropdown dari data baris tabel.
       Kalau nilainya tidak ada di daftar bank (contoh nyata di data Anda:
       "QRIS-Based" dan "BCA (Virtual Account)"), nilai itu TIDAK dibuang —
       ia disisipkan sebagai pilihan sementara dan tetap terpilih, sehingga
       menyunting supplier tidak pernah menghapus isian lamanya. */
    window.bankPickerSet = function (prefix, value) {
        var picker = document.getElementById(prefix + '_bank_picker');
        if (!picker) return;
        value = value || '';

        var match = null;
        picker.querySelectorAll('.bank-picker-item').forEach(function (it) {
            if (!match && it.getAttribute('data-value') === value) match = it;
        });

        if (!match && value !== '') {
            var custom = document.createElement('div');
            custom.className = 'bank-picker-item';
            custom.setAttribute('role', 'option');
            custom.setAttribute('data-value', value);
            custom.setAttribute('data-search', value.toLowerCase());
            custom.setAttribute('data-avatar', 'plain');
            custom.innerHTML = '<span class="bank-avatar bank-avatar-plain"><i class="bi bi-bank"></i></span>'
                             + '<span class="bank-picker-name"></span>';
            custom.querySelector('.bank-picker-name').textContent = value;
            var list = picker.querySelector('.bank-picker-list');
            list.insertBefore(custom, list.firstChild);
            match = custom;
        }
        if (!match) match = picker.querySelector('.bank-picker-item[data-value=""]');
        if (match) select(picker, match);
    };
}());
</script>

<script>
function editSupplier(d) {
    document.getElementById('edit_id').value             = d.id;
    document.getElementById('edit_name').value           = d.name;
    document.getElementById('edit_contact_person').value = d.contact_person;
    document.getElementById('edit_phone').value          = d.phone;
    document.getElementById('edit_email').value          = d.email;
    document.getElementById('edit_address').value        = d.address;
    document.getElementById('edit_tax_number').value     = d.tax_number     ?? '';
    document.getElementById('edit_postal_code').value    = d.postal_code    ?? '';
    document.getElementById('edit_website').value        = d.website        ?? '';
    document.getElementById('edit_payment_terms').value  = d.payment_terms  ?? '';
    /* Bank Name kini dropdown, jadi diisi lewat API-nya agar logo/avatar dan
       teks tombol ikut diperbarui. Nilai lama yang tidak ada di daftar bank
       tetap dipertahankan apa adanya (lihat bankPickerSet). */
    bankPickerSet('edit', d.bank_name ?? '');
    document.getElementById('edit_bank_account').value   = d.bank_account   ?? '';
    document.getElementById('edit_account_name').value   = d.account_name   ?? '';
    document.getElementById('edit_credit_limit').value   = d.credit_limit   ?? '0';
    document.getElementById('edit_rating').value         = d.rating         ?? '0';
    document.getElementById('edit_status').value         = d.status         ?? '1';

    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>
<?php endif; ?>

<script>
// Prevent double-submit: tampilkan spinner saat form disubmit
(function () {
    function bindSubmitSpinner(formId, btnId) {
        var form = document.getElementById(formId);
        var btn  = document.getElementById(btnId);
        if (!form || !btn) return;
        form.addEventListener('submit', function () {
            if (!form.checkValidity()) return;
            btn.disabled = true;
            btn.querySelector('.submit-label').classList.add('d-none');
            btn.querySelector('.submit-spinner').classList.remove('d-none');
        });
    }
    bindSubmitSpinner('addForm',  'addSubmitBtn');
    bindSubmitSpinner('editForm', 'editSubmitBtn');

    // Reset spinner saat modal ditutup (validasi gagal atau re-open)
    ['addModal', 'editModal'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('hidden.bs.modal', function () {
            var btn = el.querySelector('[id$="SubmitBtn"]');
            if (!btn) return;
            btn.disabled = false;
            btn.querySelector('.submit-label').classList.remove('d-none');
            btn.querySelector('.submit-spinner').classList.add('d-none');
        });
    });
})();
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

<?php if (!$__suppliers_included_as_partial) include '../includes/footer.php'; ?>