<?php
/* ─────────────────────────────────────────────────────────────────────────
 * /index.php — Public login entry point.
 * All login logic + view lives in /includes/login.php.
 * This file defines BLTX_LOGIN_ENTRY so the included file knows it was
 * reached through a legitimate URL request (not direct file access).
 * ───────────────────────────────────────────────────────────────────────── */

define('BLTX_LOGIN_ENTRY', true);
require_once __DIR__ . '/includes/login.php';
