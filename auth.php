<?php
require_once __DIR__ . '/config.php';

// Obtener datos enviados por Icecast en el POST
$action = $_POST['action'] ?? '';
$user   = $_POST['user'] ?? '';
$pass   = $_POST['pass'] ?? '';
$mount  = trim($_POST['mount'] ?? '', '/');

// Solo procesar peticiones de publicación de señal (source)
if ($action === 'stream_auth') {
    $db = file_exists(DB_FILE) ? json_decode(file_get_contents(DB_FILE), true) : ['radios' => []];
    
    $autorizado = false;

    foreach ($db['radios'] as $r) {
        $r_mount = trim($r['mountpoint'] ?? '', '/');
        $r_pass  = decrypt_pass($r['encoder_pass_encrypted'] ?? '');

        if ($r_mount === $mount && $r_pass === $pass) {
            $autorizado = true;
            break;
        }
    }

    if ($autorizado) {
        header('icecast-auth-user: 1');
        header('icecast-auth-message: OK');
        exit;
    }
}

// Denegado
header('icecast-auth-user: 0');
header('icecast-auth-message: Acceso denegado');
exit;
