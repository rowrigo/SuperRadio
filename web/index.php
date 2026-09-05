<?php
require_once __DIR__ . '/../config.php';

$path_raw = $_SERVER['REQUEST_URI'] ?? '';
$q_pos = strpos($path_raw, '?');
$path = $q_pos !== false ? substr($path_raw, 0, $q_pos) : $path_raw;
$path = rtrim($path, '/');
$mount = '';
if (preg_match('#/web/([a-zA-Z0-9_-]+)$#i', $path, $m)) {
    $mount = $m[1];
}
if ($mount === '' && isset($_GET['mount'])) {
    $mount = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['mount']);
}
if ($mount === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Uso: https://stream.radioscr.com/web/NOMBRE_RADIO\n";
    echo "Ejemplo: https://stream.radioscr.com/web/milimonradio\n";
    exit;
}
$_GET['mount'] = $mount;
$_REQUEST['mount'] = $mount;
include __DIR__ . '/../radio_page.php';
