<?php
// =============================================================
// config.local.php — Generado por pkg/install.sh en cada VPS.
// Sobrescribe secretos y dominio SIN tocar config.php.
// (Este archivo está protegido por deploy_update.php)
// =============================================================

define('ENCRYPT_KEY', '{{ENCRYPT_KEY}}');       // AES-256-CBC (clave del encoder)
define('ENCRYPT_METHOD', 'AES-256-CBC');
define('DEPLOY_TOKEN', '{{DEPLOY_TOKEN}}');     // token de deploy_update.php
define('STREAM_HOST', '{{STREAM_HOST}}');       // dominio público del VPS (sin http://)
