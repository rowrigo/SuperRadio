<?php
/**
 * Límite de conexiones simultáneas por radio (superadmin).
 * El campo vive en database.json (radios[].max_listeners, 0 = ilimitado) y se aplica
 * regenerando la config nginx vía el wrapper root radiopanel-limits-update (sudoers NOPASSWD).
 */

if (!function_exists('sp_limites_norm')) {

function sp_limites_norm($v) {
    $n = (int)$v;
    if ($n < 0) $n = 0;
    if ($n > 100000) $n = 100000;
    return $n;
}

function sp_limites_log_fallo($ctx, array $fallos) {
    $dir = '/var/log/radiopanel';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $line = '[' . date('c') . '] ' . ($ctx !== '' ? $ctx : 'limites') . "\n  - " . implode("\n  - ", $fallos) . "\n";
    @file_put_contents($dir . '/limites.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Regenera los límites de nginx vía sudo (wrapper sin argumentos). Reintenta 1 vez.
 * @return array ['ok'=>bool,'output'=>string]
 */
function sp_limites_regenerate($ctx = '') {
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'output' => 'proc_open no está disponible en este servidor.'];
    }
    $cmd = ['sudo', '-n', '/usr/local/sbin/radiopanel-limits-update'];
    $fallos = [];
    for ($i = 0; $i < 2; $i++) {
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            $msg = 'No se pudo ejecutar la regeneración de límites de nginx (¿falta el permiso sudo del sistema?).';
            sp_limites_log_fallo($ctx, [$msg]);
            return ['ok' => false, 'output' => $msg];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        $out_txt = trim((string)$stdout . "\n" . (string)$stderr);
        if ($code === 0) {
            return ['ok' => true, 'output' => $out_txt !== '' ? $out_txt : '(sin salida, código 0)'];
        }
        $fallos[] = $out_txt !== '' ? $out_txt : "(sin salida, código {$code})";
        if ($i === 0) sleep(2);
    }
    sp_limites_log_fallo($ctx, $fallos);
    return ['ok' => false, 'output' => end($fallos)];
}
}
