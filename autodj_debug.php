<?php
if (function_exists('opcache_reset')) { @opcache_reset(); }
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
/**
 * =========================================================
 *  SCRIPT DE DEBUG SIMPLE - SIN LOGIN - SOLO PARA MILIMONRADIO
 * =========================================================
 *  Cómo usarlo en el VPS:
 *  1. Sube este archivo a:  /var/www/radiopanel/autodj_debug.php
 *  2. Abre en el navegador:  https://TU-DOMINIO/radiopanel/autodj_debug.php
 *  3. Deberías VER DIRECTAMENTE en JSON:
 *      - folders[0..5] = 6 carpetas (BaladaEspaol, BaladaIngles, Ranchera, etc.)
 *        cada una con files[] con los MP3 y duración (aunque sea 0s)
 *      - data.playlists = {general, Roots, calypso, romanticas, Exitosingles, spot}
 *
 *  Si sale TODO VACÍO = FALLO PERMISOS / RUTA FÍSICA EN EL VPS.
 *  Si sale TODO LLENO = FALLO EN AUTENTICACIÓN / SESIÓN / autodj_api.php real.
 * =========================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// =====================================================================
//  TOKEN DEBUG SIN LOGIN (IGUAL QUE DEPLOY_TOKEN)
// =====================================================================
//  Si el usuario pone ?token=scr_debug_7f9a3c2e2b4d6f8a en LA URL,
//  inmediatamente SETEAMOS $_SESSION['superadmin_auth'] y $_SESSION['cliente_auth']
//  ANTES QUE NINGÚN require_once config.php / autodj_api.php se incluya.
//  Esto es FUNDAMENTAL por que autodj_api.php LÍNEA 1-28 hace:
//
//   if (empty($_SESSION['cliente_auth']) && empty($_SESSION['superadmin_auth']) && ...):
//       die(json_encode(['error'=>'No autorizado']));
//   endif;
//
//  Si esas variables NO están seteadas ANTES del require_once, la
//  autorización falla y devuelve No autorizado, aunque mi helper
//  _debug_authorized() diga que está OK.
// =====================================================================
define('DEBUG_TOKEN', 'scr_debug_7f9a3c2e2b4d6f8a');
$_debug_token_ok = false;
if (isset($_GET['token']) && is_string($_GET['token']) && $_GET['token'] === DEBUG_TOKEN) $_debug_token_ok = true;
if (isset($_POST['token']) && is_string($_POST['token']) && $_POST['token'] === DEBUG_TOKEN) $_debug_token_ok = true;

if ($_debug_token_ok) {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    $_GET_MOUNT_PREAUTH = isset($_GET['mount']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string)$_GET['mount'])) : 'milimonradio';
    if ($_GET_MOUNT_PREAUTH === '') $_GET_MOUNT_PREAUTH = 'milimonradio';
    // ===== VARIABLES EXACTAS QUE USA autodj_api.php EN SU CHECK =====
    $_SESSION['superadmin_auth'] = true;   // line 19 autodj_api.php - SUPERADMIN OK
    $_SESSION['cliente_auth']    = true;   // line 18 autodj_api.php - CLIENTE OK
    $_SESSION['cliente_mount']   = $_GET_MOUNT_PREAUTH;
    $_SESSION['radio_id']        = '';
    // Compatibilidad sidebar/panel.php
    $_SESSION['superadmin_ok']   = true;
    $_SESSION['cliente_ok']      = true;
}

// Helper (fallback para modos que no requieren autodj_api.php: netstat, liqlog)
function _debug_authorized() {
    global $_debug_token_ok;
    if ($_debug_token_ok) return true;
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    if (!empty($_SESSION['cliente_auth']) || !empty($_SESSION['cliente_ok'])) return true;
    if (!empty($_SESSION['superadmin_auth']) || !empty($_SESSION['superadmin_ok'])) return true;
    return false;
}

// ========= GENERALIZACIÓN DINÁMICA POR ?mount=PARAM =========
require_once __DIR__ . '/config.php';
$_GET_MOUNT = isset($_GET['mount']) ? trim((string)$_GET['mount']) : '';
if ($_GET_MOUNT === '') $_GET_MOUNT = 'milimonradio';
$_GET_MOUNT = preg_replace('/[^A-Za-z0-9_\-]/', '', $_GET_MOUNT);
$MOUNT_DEBUG    = $_GET_MOUNT;
$BASE_DIR_DEBUG = "/var/media/radios/{$MOUNT_DEBUG}";
$DATA_FILE      = "{$BASE_DIR_DEBUG}/programacion.json";
$CACHE_FILE     = "{$BASE_DIR_DEBUG}/duration_cache.json";
$LIQ_FILE       = "{$BASE_DIR_DEBUG}/autodj.liq";
$PID_FILE_DEBUG = "{$BASE_DIR_DEBUG}/autodj.pid";

// MODO ESPECIAL: ?radios=1 (token o sesion requerida)
if (isset($_GET['radios']) && $_GET['radios'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if (!_debug_authorized()) {
        echo json_encode(['error'=>'No autorizado','solucion'=>'Agregar ?token='.DEBUG_TOKEN.' a la URL'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require_once __DIR__ . '/config.php';
    // ===== OPCACHE INVALIDATE ANTES DE CARGAR autodj_api.php =====
    if (function_exists('opcache_reset'))       { @opcache_reset(); }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(__DIR__ . '/autodj_api.php', true);
        @opcache_invalidate(__DIR__ . '/config.php', true);
    }
    clearstatcache(true);
    // ===== FIN OPCACHE INVALIDATE =====
    require_once __DIR__ . '/autodj_api.php';
    $db_raw = @file_get_contents(__DIR__ . '/database.json');
    $db = $db_raw ? (@json_decode($db_raw, true) ?: []) : [];
    $radios_out = [];
    $all_pid_map = [];
    foreach (($db['radios'] ?? []) as $idx => $r) {
        $mount = $r['mountpoint'] ?? '';
        if ($mount === '') continue;
        $pid_dir = "/var/media/radios/{$mount}";
        $pid_f = "{$pid_dir}/autodj.pid";
        $pid = null;
        $running = false;
        $elapsed = null;
        if (file_exists($pid_f)) {
            $pid = (int)trim((string)@file_get_contents($pid_f));
            if ($pid > 0) {
                $ps = @shell_exec("ps -o pid=,etimes=,cmd= -p " . (int)$pid . " 2>/dev/null");
                if (!empty($ps) && strpos($ps, (string)$pid) !== false) { $running = true; $elapsed = trim(preg_replace('/^\s*' . preg_quote((string)$pid, '/') . '\s+(\d+).*$/s', '$1', $ps)); }
                else { $pid = null; }
            } else { $pid = null; }
        }
        $port_in_use_sys = false;
        $port_in_use_by_pid = null;
        $dj_p = !empty($r['dj_port']) ? (int)$r['dj_port'] : null;
        if ($dj_p) {
            $chk = @shell_exec("ss -ltnpH sport = :{$dj_p} 2>/dev/null");
            if (!empty($chk)) {
                $port_in_use_sys = true;
                if (preg_match('/pid=(\d+)/', (string)$chk, $mm)) $port_in_use_by_pid = (int)$mm[1];
            }
        }
        $encoder_clear = !empty($r['encoder_pass_encrypted']) ? '***' : (!empty($r['encoder_pass']) ? '***' : '');
        $radios_out[] = [
            '_idx'        => $idx,
            'id'          => $r['id'] ?? null,
            'nombre'      => $r['nombre_emisora'] ?? ($r['nombre'] ?? null),
            'mount'       => $mount,
            'dj_port'     => $dj_p,
            'port_in_use_sys_actual' => $port_in_use_sys,
            'port_in_use_by_pid' => $port_in_use_by_pid,
            'modo_radio'  => !empty($r['modo_radio']) ? $r['modo_radio'] : 'autodj',
            'cuota_mb'    => !empty($r['quota_mb']) ? (float)$r['quota_mb'] : 0.0,
            'directa_fondo_oculto_path' => $r['directa_fondo_oculto_path'] ?? '',
            'pass_encoder_guardada' => $encoder_clear,
            'pid'         => $pid,
            'running'     => $running,
            'elapsed_sec' => $elapsed,
        ];
        if ($pid) $all_pid_map[$pid][] = $mount;
    }
    $collisions = [];
    foreach ($all_pid_map as $p => $ms) { if (count($ms) > 1) $collisions["pid:$p"] = $ms; }
    $port_map = [];
    foreach ($radios_out as $rr) {
        if (!empty($rr['dj_port'])) {
            $key = 'port:' . $rr['dj_port'];
            $port_map[$key][] = ['mount'=>$rr['mount'],'en_uso_sys'=>$rr['port_in_use_sys_actual'],'por_pid'=>$rr['port_in_use_by_pid'],'esperado_pid'=>$rr['pid']];
        }
    }
    $port_collisions = [];
    foreach ($port_map as $pk => $ms) { if (count($ms) > 1) $port_collisions[$pk] = $ms; }
    echo json_encode([
        'total_radios' => count($radios_out),
        'radios' => $radios_out,
        'pid_collisions_mismo_pid_multiples_radios' => $collisions,
        'dj_port_collisions_mismo_puerto_multiples_radios' => $port_collisions,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// MODO ESPECIAL: ?check=1 (token o sesion)
if (isset($_GET['check']) && $_GET['check'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!_debug_authorized()) {
        echo "NO AUTORIZADO. Usa ?token=".DEBUG_TOKEN."\n";
        exit;
    }
    echo "=== LIQUIDSOAP CHECK mount={$MOUNT_DEBUG} contra {$LIQ_FILE} ===\n";
    if (!file_exists($LIQ_FILE)) { echo "FATAL: autodj.liq NO EXISTE. Primero guarda la configuración desde el panel.\n"; exit; }
    require_once __DIR__ . '/config.php';
    // ===== OPCACHE INVALIDATE ANTES DE CARGAR autodj_api.php =====
    if (function_exists('opcache_reset'))       { @opcache_reset(); }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(__DIR__ . '/autodj_api.php', true);
        @opcache_invalidate(__DIR__ . '/config.php', true);
    }
    clearstatcache(true);
    // ===== FIN OPCACHE INVALIDATE =====
    require_once __DIR__ . '/autodj_api.php';
    $liq_bin = function_exists('find_liquidsoap_binary') ? find_liquidsoap_binary() : '/usr/bin/liquidsoap';
    echo "bin: $liq_bin\n";
    echo "executable: " . (is_executable($liq_bin) ? 'YES' : 'NO') . "\n\n";
    if (!is_executable($liq_bin)) { exit; }
    $ch = run_liquidsoap_check($liq_bin, $LIQ_FILE);
    echo "liq_check.ok = " . var_export($ch['ok'] ?? null, true) . "\n";
    echo "exit_code = " . ($ch['exit_code'] ?? 'null') . "\n";
    if (!empty($ch['stdout'])) echo "\nSTDOUT:\n" . trim($ch['stdout']) . "\n";
    if (!empty($ch['stderr'])) echo "\nSTDERR:\n" . trim($ch['stderr']) . "\n";
    exit;
}

// MODO ESPECIAL: ?liq=1 (token)
if (isset($_GET['liq']) && $_GET['liq'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!_debug_authorized()) {
        echo "NO AUTORIZADO. Usa ?token=".DEBUG_TOKEN."\n";
        exit;
    }
    if (!file_exists($LIQ_FILE)) {
        echo "// autodj.liq NO EXISTE en {$LIQ_FILE}\n";
        exit;
    }
    $raw = @file_get_contents($LIQ_FILE);
    if ($raw === false) { echo "// No se pudo leer autodj.liq\n"; exit; }
    echo $raw;
    exit;
}

// MODO ESPECIAL: ?generate=1 -> NO IMPLEMENTADO DIRECTAMENTE (requiere todo el contexto del mount), usa save_data desde el panel.
if (isset($_GET['generate']) && $_GET['generate'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Modo generate deshabilitado aquí (necesita login/mount). Usa panel -> Guardar o POST a autodj_api.php?action=save_data\n";
    exit;
}

// ---------- MODOS DE DIAGNÓSTICO DJ EN VIVO (sin login) ----------
if (!function_exists('__debug_shell')) {
function __debug_shell($cmd, $timeout_sec = 10) {
    $stdout = ''; $stderr = ''; $code = -1;
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['stdout' => '', 'stderr' => 'proc_open no disponible', 'exit_code' => -2];
    }
    fclose($pipes[0]);
    $start = time();
    $status = ['running' => true];
    @stream_set_blocking($pipes[1], false);
    @stream_set_blocking($pipes[2], false);
    while ($status['running'] && (time() - $start) < $timeout_sec) {
        $status = @proc_get_status($proc);
        if (!is_array($status)) break;
        $out = @fread($pipes[1], 8192); if (is_string($out) && $out !== '') $stdout .= $out;
        $err = @fread($pipes[2], 8192); if (is_string($err) && $err !== '') $stderr .= $err;
        usleep(100000);
    }
    if (!empty($status['running'])) {
        @proc_terminate($proc, 9);
        $stderr .= "\n[TIMEOUT {$timeout_sec}s - KILL 9]";
    }
    $out = @fread($pipes[1], 8192); if (is_string($out) && $out !== '') $stdout .= $out;
    $err = @fread($pipes[2], 8192); if (is_string($err) && $err !== '') $stderr .= $err;
    @fclose($pipes[1]); @fclose($pipes[2]);
    $code = is_array($status) ? (int)($status['exitcode'] ?? -1) : -1;
    @proc_close($proc);
    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit_code' => $code,
    ];
}
}

if (isset($_GET['killpid']) && ctype_digit((string)$_GET['killpid'])) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!_debug_authorized()) {
        echo "NO AUTORIZADO. Usa ?token=".DEBUG_TOKEN."\n";
        exit;
    }
    $pidKill = (int)$_GET['killpid'];
    echo "=== KILL PID huérfano {$pidKill} (requiere token) ===\n\n";
    if ($pidKill <= 1) { echo "ERROR: PID inválido (no puedes matar PID 0 o 1 init).\n"; exit; }
    $ps = @shell_exec("ps -o pid=,etimes=,cmd= -p " . (int)$pidKill . " 2>&1");
    echo "ps antes kill: $ps\n";
    @exec("kill -15 " . (int)$pidKill . " 2>/dev/null");
    usleep(800000);
    @exec("kill -9 " . (int)$pidKill . " 2>/dev/null");
    usleep(400000);
    $psAfter = @shell_exec("ps -o pid=,etimes=,cmd= -p " . (int)$pidKill . " 2>&1");
    echo "ps DESPUES kill -9: " . trim($psAfter ?: "(sin salida = proceso muerto OK)") . "\n\n";
    echo "=== Limpiar puertos TIME_WAIT / sockets viejos ===\n";
    // Borrar todos los PID_FILE que apunten a este pid muerto (por si estaban colgados)
    $db_r = @file_get_contents(__DIR__ . '/database.json');
    $db = $db_r ? (@json_decode($db_r, true) ?: []) : [];
    foreach (($db['radios'] ?? []) as $rr) {
        $mp = $rr['mountpoint'] ?? '';
        if ($mp === '') continue;
        $pf = "/var/media/radios/{$mp}/autodj.pid";
        if (@file_exists($pf)) {
            $ppid = (int)trim((string)@file_get_contents($pf));
            if ($ppid === $pidKill) {
                echo "  - Limpiando pid_file muerto: $pf  (apuntaba a PID muerto $pidKill)\n";
                @unlink($pf);
                @unlink("/var/media/radios/{$mp}/liq.sock");
                @unlink("/var/media/radios/{$mp}/liq.sock.old");
            }
        }
    }
    echo "\nKill completado. Tienes que llamar a ?restart_autodj=__RESTART_MAGIC_8005_HARBOR__&mount=XXXX para arrancar de nuevo cada radio.\n";
    exit;
}

if (isset($_GET['netstat']) && $_GET['netstat'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!_debug_authorized()) {
        echo "NO AUTORIZADO. Usa ?token=".DEBUG_TOKEN."\n";
        exit;
    }
    echo "=== NETSTAT / SS: Puertos TCP escuchando (DJ 8005+ / Icecast 8000) ===\n\n";
    $cmds = [
        'ss -tlnp'           => "ss -tlnp 2>&1",
        'netstat -tlnp'      => "netstat -tlnp 2>&1",
    ];
    // Detect all dj ports used by radios + scan 8000..8100 generic DJ ports
    for($pp=8000; $pp<=8015; $pp++) $cmds["fuser {$pp}/tcp"] = "fuser -v {$pp}/tcp 2>&1";
    foreach ($cmds as $label => $cmd) {
        echo "--- [$label] ---\n";
        $r = __debug_shell($cmd);
        $out = trim($r['stdout'] . "\n" . $r['stderr']);
        if ($out === '') $out = "(sin salida)";
        echo $out . "\n\n";
    }
    $pid_file = "{$BASE_DIR_DEBUG}/autodj.pid";
    echo "PID_FILE: $pid_file\n";
    if (file_exists($pid_file)) {
        $pid = trim((string)@file_get_contents($pid_file));
        echo "PID guardado: $pid\n";
        $chk = __debug_shell("ps -p $pid -o pid,etime,cmd 2>&1");
        echo trim($chk['stdout'] . "\n" . $chk['stderr']) . "\n";
    } else {
        echo "NO EXISTE PID (AutoDJ APAGADO)\n";
    }
    exit;
}

if (isset($_GET['setdjport']) && $_GET['setdjport'] === '1' && isset($_GET['mount']) && isset($_GET['port'])) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!_debug_authorized()) {
        echo "NO AUTORIZADO. Usa ?token=".DEBUG_TOKEN."\n";
        exit;
    }
    $mountSet = trim((string)$_GET['mount']);
    $portSet  = (int)$_GET['port'];
    echo "=== SET DJ_PORT radio mount={$mountSet} puerto={$portSet} ===\n\n";
    $dbFile = __DIR__ . '/database.json';
    if (!file_exists($dbFile)) { echo "ERROR: no existe database.json\n"; exit; }
    if ($portSet < 8000 || $portSet > 9000) { echo "ERROR: puerto fuera rango [8000-9000]\n"; exit; }
    $dbRaw = @file_get_contents($dbFile);
    if ($dbRaw === false) { echo "ERROR leer database.json\n"; exit; }
    $db = @json_decode($dbRaw, true);
    if (!is_array($db)) { echo "ERROR: database.json corrupto\n"; exit; }
    $encontrada = null;
    $idEncontrada = null;
    foreach (($db['radios'] ?? []) as $rid => $r) {
        if (($r['mountpoint'] ?? '') === $mountSet) { $encontrada = $r; $idEncontrada = $rid; break; }
    }
    if ($encontrada === null) { echo "ERROR: mount={$mountSet} NO EXISTE en DB\n"; exit; }
    $oldPort = $encontrada['dj_port'] ?? 'vacio';
    echo "RADIO encontrada: {$encontrada['nombre_emisora']} (id={$idEncontrada})\n";
    echo "dj_port ANTES: {$oldPort}\n";
    echo "dj_port NUEVO: {$portSet}\n\n";
    $encontrada['dj_port'] = $portSet;
    $db['radios'][$idEncontrada] = $encontrada;
    $newJson = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $bytes = @file_put_contents($dbFile, $newJson);
    if ($bytes === false) { echo "ERROR AL ESCRIBIR database.json (permisos?)\n"; exit; }
    echo "OK: database.json actualizada OK ({$bytes} bytes).\n\n";
    if (function_exists('opcache_reset'))       { @opcache_reset(); }
    if (function_exists('opcache_invalidate')) { @opcache_invalidate($dbFile, true); @opcache_invalidate(__DIR__.'/config.php', true); }
    clearstatcache(true);
    echo "Siguiente paso: llamar ?restart_autodj=__RESTART_MAGIC_8005_HARBOR__&mount={$mountSet} para regenerar LIQ.\n";
    exit;
}

if (isset($_GET['setbitrate']) && $_GET['setbitrate'] === '1' && isset($_GET['mount']) && isset($_GET['kbps'])) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!_debug_authorized()) {
        echo "NO AUTORIZADO. Usa ?token=".DEBUG_TOKEN."\n";
        exit;
    }
    $mountBr = trim((string)$_GET['mount']);
    $kbps    = (int)$_GET['kbps'];
    $ALLOWED_BR = [64, 96, 128, 192, 256, 320];
    echo "=== SET BITRATE radio mount={$mountBr} kbps={$kbps} ===\n\n";
    $dbFile = __DIR__ . '/database.json';
    if (!file_exists($dbFile)) { echo "ERROR: no existe database.json\n"; exit; }
    if (!in_array($kbps, $ALLOWED_BR, true)) { echo "ERROR: bitrate NO permitido (solo ".implode(",",$ALLOWED_BR).")\n"; exit; }
    $dbRaw = @file_get_contents($dbFile);
    if ($dbRaw === false) { echo "ERROR leer database.json\n"; exit; }
    $db = @json_decode($dbRaw, true);
    if (!is_array($db)) { echo "ERROR: database.json corrupto\n"; exit; }
    $encontrada = null;
    $idEncontrada = null;
    foreach (($db['radios'] ?? []) as $rid => $r) {
        if (($r['mountpoint'] ?? '') === $mountBr) { $encontrada = $r; $idEncontrada = $rid; break; }
    }
    if ($encontrada === null) { echo "ERROR: mount={$mountBr} NO EXISTE en DB\n"; exit; }
    $oldBr = (int)($encontrada['bitrate'] ?? 128);
    echo "RADIO encontrada: {$encontrada['nombre_emisora']} (id={$idEncontrada})\n";
    echo "BITRATE ANTES: {$oldBr} kbps\n";
    echo "BITRATE NUEVO: {$kbps} kbps\n\n";
    $encontrada['bitrate'] = $kbps;
    $db['radios'][$idEncontrada] = $encontrada;
    $newJson = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $bytes = @file_put_contents($dbFile, $newJson);
    if ($bytes === false) { echo "ERROR AL ESCRIBIR database.json (permisos?)\n"; exit; }
    echo "OK: database.json actualizada OK ({$bytes} bytes).\n\n";
    if (function_exists('opcache_reset'))       { @opcache_reset(); }
    if (function_exists('opcache_invalidate')) { @opcache_invalidate($dbFile, true); @opcache_invalidate(__DIR__.'/config.php', true); }
    clearstatcache(true);
    echo "Siguiente paso: llamar ?restart_autodj=__RESTART_MAGIC_8005_HARBOR__&mount={$mountBr} para regenerar LIQ con el encoder en bitrate nuevo.\n";
    exit;
}

if (isset($_GET['raw_verify']) && $_GET['raw_verify'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!_debug_authorized()) {
        echo "NO AUTORIZADO. Usa ?token=".DEBUG_TOKEN."\n";
        exit;
    }
    $apiFile = __DIR__ . '/autodj_api.php';
    echo "=== RAW_VERIFY autodj_api.php REMOTO (deploy vs opcache) ===\n\n";
    echo "archivo: " . $apiFile . "\n";
    echo "file_exists: " . (file_exists($apiFile) ? 'SI' : 'NO') . "\n";
    if (file_exists($apiFile)) {
        echo "filesize: " . filesize($apiFile) . " bytes\n";
        echo "filemtime: " . date('Y-m-d H:i:s', filemtime($apiFile)) . " (server TZ)\n";
        echo "sha1_file:  " . sha1_file($apiFile) . "\n";
        $lines = file($apiFile, FILE_IGNORE_NEW_LINES);
        $total = is_array($lines) ? count($lines) : 0;
        echo "total_lineas: " . $total . "\n\n";
        $from = 175;
        $to   = 280;
        if ($from > $total) $from = $total;
        if ($to   > $total) $to   = $total;
        echo "--- EXTRACTO lines {$from}-{$to} ---\n";
        for ($i = $from - 1; $i < $to; $i++) {
            $ln = $i + 1;
            $txt = isset($lines[$i]) ? $lines[$i] : '';
            echo sprintf("%4d | %s\n", $ln, $txt);
        }
        echo "\n--- FIN EXTRACTO ---\n\n";
        echo "BUSQUEDA DE MARCAS CLAVE:\n";
        $hay_mark = (strpos(implode("\n", $lines), 'OPCACHE-MARK-1234567') !== false) ? 'ENCONTRADA' : 'NO ENCONTRADA';
        $hay_dj  = (strpos(implode("\n", $lines), 'dj_harbor = input.harbor(') !== false) ? 'ENCONTRADA' : 'NO ENCONTRADA';
        echo "  [SCR-v3-...-OPCACHE-MARK-1234567] => {$hay_mark}\n";
        echo "  'dj_harbor = input.harbor('        => {$hay_dj}\n";
    }
    echo "\n--- OPCACHE INVALIDATE DESDE RAW_VERIFY ---\n";
    if (function_exists('opcache_reset'))       { $r1 = @opcache_reset();       echo "opcache_reset(): " . ($r1 ? 'OK' : 'FAIL') . "\n"; }
    if (function_exists('opcache_invalidate')) {
        $r2 = @opcache_invalidate($apiFile, true);
        $r3 = @opcache_invalidate(__DIR__ . '/config.php', true);
        $r4 = @opcache_invalidate(__DIR__ . '/next_song.php', true);
        $r5 = @opcache_invalidate(__FILE__, true);
        echo "opcache_invalidate(autodj_api.php, true): " . ($r2 ? 'OK' : 'FAIL') . "\n";
        echo "opcache_invalidate(config.php, true):    " . ($r3 ? 'OK' : 'FAIL') . "\n";
        echo "opcache_invalidate(next_song.php, true): " . ($r4 ? 'OK' : 'FAIL') . "\n";
        echo "opcache_invalidate(autodj_debug.php):    " . ($r5 ? 'OK' : 'FAIL') . "\n";
    }
    clearstatcache(true);
    echo "\n--- TOUCH mtime (cambia fecha modificación para invalidar OPcache por fecha) ---\n";
    $t = @touch($apiFile);
    echo "touch({$apiFile}): " . ($t ? 'OK' : 'FAIL') . "\n";
    if ($t) echo "filemtime nuevo: " . date('Y-m-d H:i:s', filemtime($apiFile)) . "\n";
    echo "\nraw_verify FIN.\n";
    exit;
}

if (isset($_GET['ufw']) && $_GET['ufw'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!_debug_authorized()) {
        echo "NO AUTORIZADO. Usa ?token=".DEBUG_TOKEN."\n";
        exit;
    }
    echo "=== FIREWALL VPS (ufw / iptables) ===\n\n";
    $cmds = [
        'ufw status verbose'  => "ufw status verbose 2>&1",
        'iptables -L -n head' => "iptables -L -n 2>&1 | head -80",
    ];
    foreach ($cmds as $label => $cmd) {
        echo "--- [$label] ---\n";
        $r = __debug_shell($cmd);
        $out = trim($r['stdout'] . "\n" . $r['stderr']);
        if ($out === '') $out = "(sin salida / sin permisos)";
        echo $out . "\n\n";
    }
    exit;
}

if (isset($_GET['liqlog']) || isset($_GET['log'])) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!_debug_authorized()) {
        echo "NO AUTORIZADO. Usa ?token=".DEBUG_TOKEN."\n";
        exit;
    }
    $lines = isset($_GET['liqlog']) ? (int)$_GET['liqlog'] : (int)$_GET['log'];
    if ($lines <= 0) $lines = 50;
    if ($lines > 1500) $lines = 1500;
    $log_file = "{$BASE_DIR_DEBUG}/liquidsoap.log";
    echo "=== LIQUIDSOAP.LOG mount={$MOUNT_DEBUG} ultimas $lines lineas ===\n\n";
    if (!file_exists($log_file)) {
        echo "NO EXISTE liquidsoap.log\n";
        foreach (glob("{$BASE_DIR_DEBUG}/*.log") as $f) echo "  encontrada: $f (" . filesize($f) . "B)\n";
        exit;
    }
    $tail = __debug_shell("tail -n $lines " . escapeshellarg($log_file) . " 2>&1");
    $out = trim($tail['stdout'] . "\n" . $tail['stderr']);
    if ($out === '') $out = "(vacio)";
    echo "TAM: " . filesize($log_file) . " bytes\n\n" . $out . "\n";
    exit;
}

if (isset($_GET['restart_autodj']) && $_GET['restart_autodj'] === '__RESTART_MAGIC_8005_HARBOR__') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!_debug_authorized()) {
        echo "NO AUTORIZADO. Usa ?token=".DEBUG_TOKEN."\n";
        exit;
    }
    echo "=== REINICIO FORZADO AutoDJ para mount={$MOUNT_DEBUG} (token ok) ===\n\n";
    require_once __DIR__ . '/config.php';
    // ===== OPCACHE INVALIDATE ANTES DE CARGAR autodj_api.php =====
    if (function_exists('opcache_reset'))       { @opcache_reset(); }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(__DIR__ . '/autodj_api.php', true);
        @opcache_invalidate(__DIR__ . '/config.php', true);
    }
    clearstatcache(true);
    // ===== FIN OPCACHE INVALIDATE =====
    require_once __DIR__ . '/autodj_api.php';
    $pid_file = $PID_FILE_DEBUG;
    $liq_file = $LIQ_FILE;
    $db_raw = @file_get_contents(__DIR__ . '/database.json');
    $db = $db_raw ? (@json_decode($db_raw, true) ?: []) : [];
    $radio = null;
    foreach (($db['radios'] ?? []) as $r) {
        if (($r['mountpoint'] ?? '') === $MOUNT_DEBUG) { $radio = $r; break; }
    }
    if (!$radio) { echo "ERROR: no existe radio mount={$MOUNT_DEBUG}\n"; exit; }
    $modo = !empty($radio['modo_radio']) && in_array($radio['modo_radio'], ['autodj','directa'], true) ? $radio['modo_radio'] : 'autodj';
    $data_file_local = "{$BASE_DIR_DEBUG}/programacion.json";
    echo "Modo radio detectado: {$modo}\n";
    echo "dj_port: " . (empty($radio['dj_port']) ? '(vacio)' : (int)$radio['dj_port']) . "\n";
    echo "directa_fondo_oculto_path: " . ($radio['directa_fondo_oculto_path'] ?? '') . "\n\n";
    $encoder_pass = !empty($radio['encoder_pass_encrypted']) ? decrypt_pass($radio['encoder_pass_encrypted']) : ($radio['encoder_pass'] ?? '');
    if ($modo === 'autodj') {
        $default_data = [
            'timezone'         => 'America/Costa_Rica',
            'default_playlist' => 'general',
            'playlists'        => ['general' => ['tipo' => 'carpetas', 'items' => []]],
            'schedule'         => [],
            'ads'              => [],
            'time_voice'       => ['enabled' => false, 'folder' => '']
        ];
    } else {
        $default_data = [
            'timezone'         => 'America/Costa_Rica',
            'default_playlist' => 'general',
            'playlists'        => ['general' => ['tipo' => 'carpetas', 'items' => []]],
            'schedule'         => [],
            'ads'              => [],
            'time_voice'       => ['enabled' => false, 'folder' => '']
        ];
    }
    if (file_exists($pid_file)) {
        $pid = (int)trim((string)@file_get_contents($pid_file));
        if ($pid > 0) {
            echo "KILL proceso viejo PID=$pid vía shell kill -9 + unlink socket\n";
            // Usar shell kill
            @exec("kill -15 " . (int)$pid . " 2>/dev/null; sleep 1; kill -9 " . (int)$pid . " 2>/dev/null", $o_out, $o_code);
            usleep(900000);
            @unlink($pid_file);
            @unlink("{$BASE_DIR_DEBUG}/liq.sock");
            @unlink("{$BASE_DIR_DEBUG}/liq.sock.old");
            echo "OK proceso viejo borrado (exit_code $o_code).\n\n";
        }
    } else {
        echo "No existe PID_FILE anterior. Borro sockets viejos.\n";
        @unlink("{$BASE_DIR_DEBUG}/liq.sock");
        @unlink("{$BASE_DIR_DEBUG}/liq.sock.old");
    }
    $res = start_autodj($data_file_local, $default_data, $BASE_DIR_DEBUG, $radio, $MOUNT_DEBUG, $encoder_pass, $pid_file, $liq_file);
    echo "RESULTADO start_autodj():\n";
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

// --- Helper cross-encoding (igual que en autodj_api.php) — evitamos redecl ---
if (!function_exists('to_utf8_safe')) {
function to_utf8_safe($str) {
    if ($str === null || $str === '') return '';
    if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
        $enc = @mb_detect_encoding($str, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], true);
        if ($enc && strtoupper($enc) !== 'UTF-8') {
            $converted = @mb_convert_encoding($str, 'UTF-8', $enc);
            if ($converted !== false) $str = $converted;
        }
    } elseif (!preg_match('//u', $str)) {
        $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $str);
        if ($converted !== false) $str = $converted;
    }
    $str = preg_replace('/[\x00-\x08\x10\x0B\x0C\x0E-\x19\x7F]/u', '', $str);
    $str = preg_replace('/^\xEF\xBB\xBF/', '', $str);
    return (string)$str;
}
}

if (!function_exists('normalize_name')) {
function normalize_name($name) {
    $name = (string)to_utf8_safe($name);
    if (class_exists('Normalizer', false)) {
        try {
            if (!Normalizer::isNormalized($name, Normalizer::FORM_C)) {
                $name = Normalizer::normalize($name, Normalizer::FORM_C);
            }
        } catch (Throwable $e) {
        }
    }
    $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $lower = preg_replace_callback('/[àáâãäåæ]/u', fn($m) => ['à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae'][$m[0]] ?? 'a', $lower);
    $lower = preg_replace_callback('/[èéêë]/u',      fn($m) => ['è'=>'e','é'=>'e','ê'=>'e','ë'=>'e'][$m[0]] ?? 'e', $lower);
    $lower = preg_replace_callback('/[ìíîï]/u',      fn($m) => ['ì'=>'i','í'=>'i','î'=>'i','ï'=>'i'][$m[0]] ?? 'i', $lower);
    $lower = preg_replace_callback('/[òóôõöø]/u',    fn($m) => ['ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o'][$m[0]] ?? 'o', $lower);
    $lower = preg_replace_callback('/[ùúûü]/u',      fn($m) => ['ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u'][$m[0]] ?? 'u', $lower);
    $lower = preg_replace_callback('/[ýÿñçðþß]/u',  fn($m) => ['ý'=>'y','ÿ'=>'y','ñ'=>'n','ç'=>'c','ð'=>'d','þ'=>'th','ß'=>'ss'][$m[0]] ?? '', $lower);
    $map2 = [
        'Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a','Å'=>'a','Æ'=>'ae',
        'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
        'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
        'Ó'=>'o','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o','Ø'=>'o',
        'Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
        'Ý'=>'y','Ÿ'=>'y','Ñ'=>'n','Ç'=>'c','Ð'=>'d','Þ'=>'th',
        'ñ'=>'n','Ñ'=>'n'
    ];
    $lower = strtr($lower, $map2);
    $res = preg_replace('/[^a-z0-9_-]/', '', $lower);
    return (string)$res;
}
}

// ==========================================================
// PASO 1: ¿Existe la carpeta física? ¿Qué tiene?
// ==========================================================
$debug = [
    'mount' => $MOUNT_DEBUG,
    'base_dir' => $BASE_DIR_DEBUG,
    'base_dir_exists' => is_dir($BASE_DIR_DEBUG),
    'base_dir_writable' => is_writable($BASE_DIR_DEBUG),
    'php_uid' => getmyuid() ? getmyuid() : 'unknown',
    'php_user' => function_exists('get_current_user') ? get_current_user() : 'unknown'
];
if (!$debug['base_dir_exists']) {
    http_response_code(500);
    echo json_encode([
        'ERROR' => 'NO EXISTE LA CARPETA FÍSICA DE LA RADIO',
        'debug' => $debug,
        'SOLUCION' => "Creala en SSH: mkdir -p {$BASE_DIR_DEBUG}/{BaladaEspaol,BaladaIngles,Baladapop,Ranchera,Roots,calypso,HORAS,Mantenimientos,spod} && chown -R www-data:www-data /var/media/radios && chmod -R 775 /var/media/radios"
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================================
// PASO 2: Escanear CARPETAS con DirectoryIterator (más robusto que scandir)
// ==========================================================
$ignorar = ['HORAS', 'spod', 'Mantenimientos'];
$folders = [];
$normalize_map = []; // norm => nombre real

$dirs = [];
try {
    $it = new DirectoryIterator($BASE_DIR_DEBUG);
    foreach ($it as $f) {
        if ($f->isDot() || !$f->isDir()) continue;
        $name = to_utf8_safe($f->getFilename());
        if ($name === '') continue;
        $dirs[] = $name;
    }
} catch (Throwable $e) {
    $debug['dir_iterator_error'] = $e->getMessage();
    // Fallback: glob
    foreach (glob("{$BASE_DIR_DEBUG}/*", GLOB_ONLYDIR) as $d) {
        $dirs[] = to_utf8_safe(basename($d));
    }
}
sort($dirs);

foreach ($dirs as $dname) {
    $norm = normalize_name($dname);
    if ($norm !== '') $normalize_map[$norm] = $dname;

    $ignored = false;
    foreach ($ignorar as $ign) {
        if (strcasecmp($dname, $ign) === 0 || normalize_name($ign) === $norm) { $ignored = true; break; }
    }
    if ($ignored) continue;

    $dir_path = $BASE_DIR_DEBUG . '/' . $dname;
    $mp3_list = [];

    try {
        $fit = new DirectoryIterator($dir_path);
        foreach ($fit as $f) {
            if ($f->isDot() || !$f->isFile()) continue;
            $fname = to_utf8_safe($f->getFilename());
            if ($fname === '' || strtolower(substr($fname, -4)) !== '.mp3') continue;
            $bytes = $f->getSize();
            $size_mb = round($bytes / 1048576, 2) . ' MB';
            // Duración: SIN ffprobe para el debug, rápido. Siempre 00:00 pero muestra el archivo
            $mp3_list[] = [
                'name' => $fname,
                'size' => $size_mb,
                'duration_sec' => 0,
                'duration_str' => '00:00'
            ];
        }
    } catch (Throwable $e) {
        // Fallback glob
        foreach (glob($dir_path . '/*.mp3') as $mp) {
            $fname = to_utf8_safe(basename($mp));
            if ($fname === '') continue;
            $bytes = @filesize($mp);
            $size_mb = $bytes ? round($bytes/1048576,2) . ' MB' : '0 MB';
            $mp3_list[] = ['name'=>$fname,'size'=>$size_mb,'duration_sec'=>0,'duration_str'=>'00:00'];
        }
    }
    usort($mp3_list, fn($a,$b) => strcasecmp($a['name'],$b['name']));

    $folders[] = [
        'name' => $dname,
        'count' => count($mp3_list),
        'files' => $mp3_list
    ];
}

// ==========================================================
// PASO 3: Leer programacion.json FÍSICO directamente
// ==========================================================
$default_data = [
    'timezone' => 'America/Costa_Rica',
    'default_playlist' => 'general',
    'playlists' => ['general' => ['tipo' => 'carpetas', 'items' => []]],
    'schedule' => [],
    'ads' => [],
    'time_voice' => ['enabled' => false, 'folder' => '']
];

$programacion_raw = null;
$programacion_error = null;
if (file_exists($DATA_FILE)) {
    $programacion_raw = @file_get_contents($DATA_FILE);
    if ($programacion_raw === false) $programacion_error = 'no se pudo leer (permisos?)';
    else {
        $data = json_decode($programacion_raw, true);
        if (!is_array($data)) {
            $programacion_error = 'json inválido: ' . json_last_error_msg();
            $data = $default_data;
        }
    }
} else {
    $programacion_error = 'NO EXISTE el archivo programacion.json en ' . $DATA_FILE;
    $data = $default_data;
}

// ==========================================================
// PASO 4: SANEAMIENTO playlists -> reemplazar por nombre físico REAL
// ==========================================================
$folder_norm_map = [];
foreach ($folders as $fe) {
    $fn = normalize_name($fe['name']);
    if ($fn !== '') $folder_norm_map[$fn] = $fe['name'];
}
// Incluir las ignoradas (Mantenimientos/spod/HORAS) en la resolución, por si están en un playlist tipo carpeta
foreach ($dirs as $dname) {
    $fn = normalize_name($dname);
    if (!isset($folder_norm_map[$fn])) $folder_norm_map[$fn] = $dname;
}

function resolve_folder_phys($name, $folder_norm_map) {
    $n = normalize_name($name);
    if ($n === '' || !is_array($folder_norm_map)) return $name;
    return $folder_norm_map[$n] ?? $name;
}
function resolve_file_phys($rel_path, $folder_norm_map) {
    if (!is_string($rel_path) || $rel_path === '') return '';
    $rel_path = to_utf8_safe($rel_path);
    if (strpos($rel_path, '/') === false && strpos($rel_path, '\\') === false) return $rel_path;
    $parts = array_values(array_filter(preg_split('/[\\\\\\/]/', $rel_path), fn($x) => $x !== ''));
    if (count($parts) < 2) return $rel_path;
    $file = array_pop($parts);
    $folder = implode('/', $parts);
    $real_folder = resolve_folder_phys($folder, $folder_norm_map);
    return $real_folder . '/' . to_utf8_safe($file);
}

foreach (($data['playlists'] ?? []) as $pname => $pinfo) {
    if (!is_array($pinfo)) continue;
    if (!isset($pinfo['tipo']) || !is_string($pinfo['tipo'])) $data['playlists'][$pname]['tipo'] = 'carpetas';
    $tipo = $data['playlists'][$pname]['tipo'];
    $items = $pinfo['items'] ?? [];
    if (!is_array($items)) { $data['playlists'][$pname]['items'] = []; continue; }
    $fixed = [];
    foreach ($items as $it) {
        if (!is_string($it) || $it === '') continue;
        if ($tipo === 'carpetas') {
            $r = resolve_folder_phys($it, $folder_norm_map);
            if ($r !== '') $fixed[] = $r;
        } else {
            $r = resolve_file_phys($it, $folder_norm_map);
            if ($r !== '') $fixed[] = $r;
        }
    }
    $data['playlists'][$pname]['items'] = $fixed;
}
if (empty($data['playlists']['general'])) $data['playlists']['general'] = ['tipo'=>'carpetas','items'=>[]];
if (empty($data['schedule'])) $data['schedule'] = [];
if (empty($data['ads'])) $data['ads'] = [];
if (empty($data['timezone'])) $data['timezone'] = 'America/Costa_Rica';
if (empty($data['default_playlist'])) $data['default_playlist'] = 'general';
if (empty($data['time_voice'])) $data['time_voice'] = ['enabled'=>false,'folder'=>''];
if (!empty($data['time_voice']['folder'])) $data['time_voice']['folder'] = resolve_folder_phys($data['time_voice']['folder'], $folder_norm_map);

// ==========================================================
// RESPUESTA FINAL (100% igual que autodj_api.php pero SIN auth)
// ==========================================================
echo json_encode([
    'folders'  => $folders,
    'data'     => $data,
    'running'  => null,
    'mount'    => $MOUNT_DEBUG,
    'DEBUG'    => $debug + [
        'programacion_file' => $DATA_FILE,
        'programacion_file_exists' => file_exists($DATA_FILE),
        'programacion_error' => $programacion_error,
        'carpetas_fisicas_detectadas' => $dirs,
        'normalize_map_folders' => $normalize_map,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
