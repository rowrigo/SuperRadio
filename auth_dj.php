#!/usr/bin/php
<?php
require_once __DIR__ . '/config.php';

$user = trim($argv[1] ?? '');
$pass = trim($argv[2] ?? '');
$mount = trim($argv[3] ?? '');

if (empty($user) || empty($pass)) {
    echo "false\n";
    exit(1);
}

$db_file = DB_FILE;
if (!file_exists($db_file)) {
    echo "false\n";
    exit(1);
}

$db = json_decode(file_get_contents($db_file), true);
$clients = !empty($db['clientes']) ? $db['clientes'] : (!empty($db['usuarios']) ? $db['usuarios'] : []);

// 1. Validar si es el dueño de la cuenta
if (isset($clients[$user])) {
    $c = $clients[$user];
    if (password_verify($pass, $c['password_hash'] ?? '')) {
        echo "true\n";
        exit(0);
    }
}

// 2. Validar si coincide con la clave directa del encoder de la radio
if (!empty($db['radios'])) {
    foreach ($db['radios'] as $r) {
        if (($r['mountpoint'] ?? '') === $mount || ($r['id'] ?? '') === 'rad_' . $mount) {
            $enc_pass = !empty($r['encoder_pass_encrypted']) ? decrypt_pass($r['encoder_pass_encrypted']) : ($r['encoder_pass'] ?? '');
            if (($user === 'source' || $user === $mount) && $pass === $enc_pass) {
                echo "true\n";
                exit(0);
            }
        }
    }
}

echo "false\n";
exit(1);
