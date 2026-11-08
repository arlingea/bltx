<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "salary_components" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('salary_components');
    }
    define('PAYROLL_LEGACY_POST', true);
}
require_once __DIR__ . '/../includes/hris_functions.php';

requirePayrollModule('salary_components');


$cfg = [
    'module'   => 'salary_components',
    'table'    => 'employee_salary_components',
    'tab'      => 'salary_components',
    'title'    => $LANG['salary_components'],
    'icon'     => 'bi-ui-checks-grid',
    'order'    => 'component_type ASC, sort_order ASC',
    'intro'    => $LANG['intro_salary_components'],
    'snapshot' => [],
    'fields'   => [
        'component_name' => [ 'label' => $LANG['name'], 'type' => 'text', 'required' => true, 'list' => true, 'col' => 6 ],
        'component_key' => [ 'label' => $LANG['code'], 'type' => 'text', 'required' => true, 'list' => true, 'col' => 6, 'hint' => $LANG['hint_component_key'] ],
        'component_type' => ['label' => $LANG['type'], 'type' => 'select', 'required' => true, 'list' => true, 'col' => 6,
                              'options' => ['earning' => $LANG['earnings'], 'deduction' => $LANG['deductions']]],
        'sort_order' => [ 'label' => $LANG['sort_order'], 'type' => 'number', 'list' => true, 'col' => 6 ],
        'is_taxable' => [ 'label' => $LANG['taxable'], 'type' => 'checkbox', 'list' => true, 'col' => 6 ],
        'is_active' => [ 'label' => $LANG['status'], 'type' => 'checkbox', 'list' => true, 'col' => 6 ],
    ],
];

hrisCrudHandle($cfg);

if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('salary_components'); }

hrisCrudRender($cfg);
