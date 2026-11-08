<?php
/* ═══════════════════════════════════════════════════════════════════════
   FRAGMEN TAB "onboarding" — bagian dari payroll/payroll_gate.php

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
        payrollRedirectLegacy('onboarding');
    }
    define('PAYROLL_LEGACY_POST', true);
}
require_once __DIR__ . '/../includes/hris_functions.php';

requirePayrollModule('recruitment');


$cfg = [
    'module'   => 'recruitment',
    'table'    => 'onboarding_tasks',
    'tab'      => 'onboarding',
    'title'    => $LANG['onboarding_tasks'],
    'icon'     => 'bi-list-check',
    'order'    => 'sort_order ASC',
    'intro'    => $LANG['intro_onboarding'],
    'snapshot' => [],
    'fields'   => [
        'task_name' => [ 'label' => $LANG['name'], 'type' => 'text', 'required' => true, 'list' => true, 'col' => 8 ],
        'sort_order' => [ 'label' => $LANG['sort_order'], 'type' => 'number', 'list' => true, 'col' => 4 ],
        'description' => [ 'label' => $LANG['description'], 'type' => 'textarea', 'col' => 12 ],
        'is_active' => [ 'label' => $LANG['status'], 'type' => 'checkbox', 'list' => true, 'col' => 6 ],
    ],
];

hrisCrudHandle($cfg);

if (defined('PAYROLL_LEGACY_POST')) { payrollRedirectLegacy('onboarding'); }

hrisCrudRender($cfg);
