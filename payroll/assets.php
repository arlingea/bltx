<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "assets" — bagian dari payroll/payroll_gate.php

   Berkas ini TIPIS dengan sengaja: seluruh CRUD, CSRF, toast, DataTables,
   modal, dan gerbang izin per aksi ditangani mesin generik di
   includes/hris_functions.php. Yang ditulis di sini hanya KONFIGURASI
   kolomnya. Menambah modul serupa di masa depan cukup menyalin pola ini.

   URL lama tetap hidup: bila dibuka langsung (PAYROLL_GATE belum
   terdefinisi), GET dialihkan ke hub sedangkan POST tetap dilayani.
   ═══════════════════════════════════════════════════════════════════════ */
if (!defined('PAYROLL_GATE')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/global_permission.php';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        payrollRedirectLegacy('assets');
    }
    define('PAYROLL_LEGACY_POST', true);
}
require_once __DIR__ . '/../includes/hris_functions.php';

requirePayrollModule('assets');

$empOptions = hrisActiveEmployeeOptions();
$catOptions = hrisRefOptions('asset_categories');

$cfg = [
    'module'   => 'assets',
    'table'    => 'employee_assets',
    'tab'      => 'assets',
    'title'    => $LANG['assets'],
    'icon'     => 'bi-box-seam',
    'order'    => 'id DESC',
    'intro'    => $LANG['intro_assets'],
    'snapshot' => ['created_by' => 'created_by_name'],
    'fields'   => [
        'asset_code' => [ 'label' => $LANG['code'], 'type' => 'text', 'required' => true, 'list' => true, 'col' => 4 ],
        'asset_name' => [ 'label' => $LANG['name'], 'type' => 'text', 'required' => true, 'list' => true, 'col' => 8 ],
        'category_id' => ['label' => $LANG['category'], 'type' => 'select', 'list' => true, 'col' => 6, 'options' => $catOptions],
        'employee_id' => ['label' => $LANG['employee'], 'type' => 'select', 'list' => true, 'col' => 6, 'options' => $empOptions],
        'serial_number' => [ 'label' => $LANG['serial_number'], 'type' => 'text', 'col' => 6 ],
        'assigned_date' => [ 'label' => $LANG['assigned_date'], 'type' => 'date', 'list' => true, 'col' => 6 ],
        'returned_date' => [ 'label' => $LANG['returned_date'], 'type' => 'date', 'col' => 6 ],
        'purchase_value' => [ 'label' => $LANG['value'], 'type' => 'money', 'list' => true, 'col' => 6 ],
        'asset_condition' => ['label' => $LANG['condition'], 'type' => 'select', 'list' => true, 'col' => 6,
                              'options' => ['new' => $LANG['cond_new'], 'good' => $LANG['cond_good'], 'fair' => $LANG['cond_fair'],
                                            'damaged' => $LANG['cond_damaged'], 'lost' => $LANG['cond_lost']]],
        'status' => ['label' => $LANG['status'], 'type' => 'select', 'list' => true, 'col' => 6,
                              'options' => ['available' => $LANG['asset_available'], 'assigned' => $LANG['asset_assigned'],
                                            'returned' => $LANG['asset_returned'], 'written_off' => $LANG['asset_written_off']]],
        'notes' => [ 'label' => $LANG['notes'], 'type' => 'textarea', 'col' => 12 ],
    ],
];

hrisCrudHandle($cfg);

if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('assets'); }

hrisCrudRender($cfg);
