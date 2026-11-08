<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "tax_settings" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('tax_settings');
    }
    define('PAYROLL_LEGACY_POST', true);
}
require_once __DIR__ . '/../includes/hris_functions.php';

requirePayrollModule('tax_settings');


$cfg = [
    'module'   => 'tax_settings',
    'table'    => 'tax_brackets',
    'tab'      => 'tax_settings',
    'title'    => $LANG['tax_brackets'],
    'icon'     => 'bi-percent',
    'order'    => 'year DESC, sort_order ASC',
    'intro'    => $LANG['intro_tax_brackets'],
    'snapshot' => [],
    'fields'   => [
        'year' => [ 'label' => $LANG['year'], 'type' => 'number', 'required' => true, 'list' => true, 'col' => 4 ],
        'min_income' => [ 'label' => $LANG['min_income'], 'type' => 'money', 'required' => true, 'list' => true, 'col' => 4 ],
        'max_income' => [ 'label' => $LANG['max_income'], 'type' => 'money', 'list' => true, 'col' => 4, 'hint' => $LANG['hint_max_income_blank'] ],
        'rate_percent' => [ 'label' => $LANG['rate_percent'], 'type' => 'money', 'required' => true, 'list' => true, 'col' => 4 ],
        'sort_order' => [ 'label' => $LANG['sort_order'], 'type' => 'number', 'list' => true, 'col' => 4 ],
        'is_active' => [ 'label' => $LANG['status'], 'type' => 'checkbox', 'list' => true, 'col' => 4 ],
    ],
];

hrisCrudHandle($cfg);

if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('tax_settings'); }

hrisCrudRender($cfg);
