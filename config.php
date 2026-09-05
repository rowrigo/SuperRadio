<?php
date_default_timezone_set('America/Costa_Rica');
define('DB_FILE', __DIR__ . '/database.json');

// =========================================================
// OVERRIDES POR VPS (config.local.php)
// El instalador pkg/install.sh crea este archivo en cada VPS
// con el DOMINIO y secretos propios. Si no existe, se usan los
// valores por defecto de abajo (comportamiento original).
// =========================================================
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

if (!defined('ENCRYPT_KEY'))    define('ENCRYPT_KEY', 'scr_radio_secret_key_2026_x89!'); // Clave de cifrado AES
if (!defined('ENCRYPT_METHOD')) define('ENCRYPT_METHOD', 'AES-256-CBC');
if (!defined('DEPLOY_TOKEN'))   define('DEPLOY_TOKEN', 'scr_deploy_ca601bb45bf46e6cfd46');

// =========================================
// CONFIGURACIÓN DE STREAM / VPS
// =========================================
// Dominio público del servidor Icecast (para reproductor, botón copiar, etc.)
// config.local.php puede sobreescribirlo por VPS (UN SOLO LUGAR por VPS).
if (!defined('STREAM_HOST')) define('STREAM_HOST', 'stream.radioscr.com');

// Ruta BASE del proyecto en el servidor (PARA VPS en raíz, dejar string vacío "")
// - Si tu panel está en:     stream.radioscr.com/panel.php     (raíz)    => define('BASE_PATH', '');
// - Si estuviera en subcarp:  stream.radioscr.com/radiopanel/panel.php   => define('BASE_PATH', '/radiopanel');
if (!defined('BASE_PATH')) define('BASE_PATH', '');

// Función para obtener la URL base del proyecto (con detección automática HTTP/HTTPS)
function project_base_url() {
    $base = BASE_PATH;
    if (PHP_SAPI !== 'cli') {
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' ? 'https' : $scheme;
        }
        $host = $_SERVER['HTTP_HOST'] ?? STREAM_HOST;
        return rtrim($scheme . '://' . $host . $base, '/');
    }
    return rtrim('https://' . STREAM_HOST . $base, '/');
}

// Función para encriptar la clave del encoder
function encrypt_pass($plain_text) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPT_METHOD));
    $encrypted = openssl_encrypt($plain_text, ENCRYPT_METHOD, ENCRYPT_KEY, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

// Función para desencriptar la clave del encoder
function decrypt_pass($encrypted_text) {
    $data = base64_decode($encrypted_text);
    if (strpos($data, '::') === false) return '';
    list($encrypted_data, $iv) = explode('::', $data, 2);
    return openssl_decrypt($encrypted_data, ENCRYPT_METHOD, ENCRYPT_KEY, 0, $iv);
}

// ==============================================================
// 🔐 LOGIN SECURITY THROTTLE (anti-bruteforce)
//    Bloqueo progresivo: 3 fallos = 1h. Si falla DESPUÉS de
//    expirar 1h otra vez => 24h. Mas repetidos = 48h.
//    Storage: JSON fuera del webroot (media folder no navegable)
//    Username keys: prefijos "u:" cliente panel, "s:" superadmin
// ==============================================================
function sec_throttle_path() {
    // Ruta FUERA de www para no ser descargable
    $dir = '/var/media/radios/_panel_security';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    // Fallback local para Windows/Laragon dev
    if (!is_writable($dir)) {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    }
    return $dir . '/login_throttle.json';
}

function sec_throttle_read() {
    $f = sec_throttle_path();
    if (!is_file($f)) { return []; }
    $d = @json_decode(@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function sec_throttle_write($data) {
    $f = sec_throttle_path();
    @file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @chmod($f, 0664);
}

// Devuelve el estado ACTUAL de un usuario (limpiando attempts caducados y bloqueos expirados)
function sec_get_user_state($key) {
    $all = sec_throttle_read();
    $st = $all[$key] ?? [
        'attempts'       => [],
        'attempts_count' => 0,
        'blocked_until'  => 0,
        'level'          => 0,
        'last_block_at'  => 0,
    ];
    $now = time();
    // 1) Limpiar bloqueos expirados (si pasó el tiempo)
    if (!empty($st['blocked_until']) && $now >= (int)$st['blocked_until']) {
        // Expiró: mantener level para ESACALADA (la regla 'si a la hora se equivoca otra vez' = level sube)
        $st['blocked_until'] = 0;
        // Reset attempts a 0 al acabar el bloqueo, para que si FALLA OTRA VEZ aunque sea 1 vez dispare el sgte level
        $st['attempts'] = [];
        $st['attempts_count'] = 0;
    }
    // 2) Limpiar attempts caducados de hace mas de 2 HORAS (para no contar fallos de ayer)
    if (!empty($st['attempts'])) {
        $cut = $now - 7200;
        $new = [];
        foreach ((array)$st['attempts'] as $ts) if ((int)$ts >= $cut) $new[] = (int)$ts;
        $st['attempts'] = array_values($new);
        $st['attempts_count'] = count($st['attempts']);
    }
    // Guardar limpio (auto-desbloqueo silencioso en la siguente llamada si venció)
    $all[$key] = $st;
    sec_throttle_write($all);
    return $st;
}

// Devuelve array: [can_login bool, message, attempts_left, blocked_until_ts, level, time_left_txt, blocked_now_bool, escalated_now_bool]
function sec_check_can_login($key) {
    $st  = sec_get_user_state($key);
    $now = time();
    $attempts_left = max(0, 3 - (int)$st['attempts_count']);
    $blocked_now = !empty($st['blocked_until']) && (int)$st['blocked_until'] > $now;
    if ($blocked_now) {
        $left = (int)$st['blocked_until'] - $now;
        if ($left < 60) $t = "{$left} segundos";
        elseif ($left < 3600) $t = (int)ceil($left/60)." minutos";
        else $t = (int)ceil($left/3600)." horas";
        $level = (int)$st['level'];
        $tipo = $level >= 2 ? '48 horas' : ($level === 1 ? '24 horas' : '1 hora');
        return [
            'can' => false,
            'message' => "🔒 Acceso bloqueado por demasiados intentos. Tiempo restante: {$t}. (Bloqueo por {$tipo}, se desbloquea automaticamente o pide a admin que libere tu usuario).",
            'attempts_left' => 0,
            'blocked_until_ts' => (int)$st['blocked_until'],
            'level' => $level,
            'time_left_txt' => $t,
            'blocked_now' => true,
        ];
    }
    return [
        'can' => true,
        'message' => '',
        'attempts_left' => $attempts_left,
        'blocked_until_ts' => 0,
        'level' => (int)$st['level'],
        'time_left_txt' => '',
        'blocked_now' => false,
    ];
}

// Grabar fallo y devuelve el NUEVO estado (por si acaba de bloquearlo ahora para el mensaje)
function sec_record_fail($key) {
    $all = sec_throttle_read();
    if (!isset($all[$key])) $all[$key] = ['attempts'=>[],'attempts_count'=>0,'blocked_until'=>0,'level'=>0,'last_block_at'=>0];
    $st = &$all[$key];
    $now = time();
    // 1) Añadir attempt
    $st['attempts'][] = $now;
    if (count($st['attempts']) > 100) $st['attempts'] = array_slice($st['attempts'], -100);
    $st['attempts_count'] = count($st['attempts']);

    $just_blocked = false;
    $time_txt = '';
    $hours_block = 1;

    // REGLA PROGRESIVA (cumpliendo usuario):
    //  - Si NO ESTABA bloqueado y NO hay level acumulado (0): 3 fallos => 1 HORA.
    //  - Si HAY level anterior (ya fue bloqueado antes) y SUMA 1 FALLO NUEVO (no necesita 3 de nuevo) => SIGUIENTE nivel:
    //      level 0 -> block 1h  (3 attempts)
    //      después expira y falla 1 vez más => level 1 -> 24 HORAS
    //      después expira y falla 1 vez más => level 2+ -> 48 HORAS
    // Y si alcanzan 3 de todos modos sin importar level, disparar inmediatamente bloqueo.

    $threshold = ( ((int)$st['level']) > 0 ) ? 1 : 3;
    if ((int)$st['attempts_count'] >= $threshold) {
        $just_blocked = true;
        $nextLevel = ((int)$st['level'] <= 0) ? 0 : (int)$st['level'];
        // Si ya venia con level >=0 y estamos aqui por threshold 1 (tras desbloqueo), subir de level:
        if ($threshold === 1) {
            $nextLevel = min(2, (int)$st['level'] + 1);
        }
        $st['level'] = $nextLevel;
        if ($nextLevel >= 2) $hours_block = 48;
        elseif ($nextLevel === 1) $hours_block = 24;
        else $hours_block = 1;
        $st['blocked_until'] = $now + ($hours_block * 3600);
        $st['last_block_at']  = $now;
        // Reset attempts cuando bloqueamos
        $st['attempts'] = [];
        $st['attempts_count'] = 0;
        if ($hours_block >= 24) $time_txt = "{$hours_block} horas";
        else $time_txt = "{$hours_block} hora";
    }
    sec_throttle_write($all);
    return [
        'just_blocked' => $just_blocked,
        'blocked_until_ts' => (int)($st['blocked_until'] ?? 0),
        'level' => (int)($st['level'] ?? 0),
        'attempts_left' => max(0, $threshold - count($st['attempts'] ?? [])),
        'hours_block' => $hours_block,
        'time_txt' => $time_txt,
    ];
}

function sec_clear_throttle($key) {
    $all = sec_throttle_read();
    if (isset($all[$key])) {
        $all[$key]['attempts'] = [];
        $all[$key]['attempts_count'] = 0;
        $all[$key]['blocked_until'] = 0;
        $all[$key]['level'] = 0;
        $all[$key]['last_block_at'] = 0;
        sec_throttle_write($all);
    }
}

// Devuelve TODOS los throttles ORDENADOS (sólo USUARIOS: u: y s:). Filtrado IP en función separada.
function sec_get_all_throttles() {
    $all = sec_throttle_read();
    $now = time();
    $rows = [];
    foreach ($all as $k => $st) {
        $prefix = substr($k,0,2);
        if ($prefix === 'ip') continue; // saltar IPs aqui, usan su propia funcion
        if (!empty($st['blocked_until']) && (int)$st['blocked_until'] <= $now) { $st['blocked_until']=0; $st['attempts']=[]; $st['attempts_count']=0; }
        if (!empty($st['attempts'])) {
            $cut = $now - 7200;
            $new = []; foreach ((array)$st['attempts'] as $ts) if ((int)$ts >= $cut) $new[]=(int)$ts;
            $st['attempts'] = $new; $st['attempts_count'] = count($new);
        }
        if (empty($st['blocked_until']) && (int)($st['attempts_count']) === 0 && (int)($st['level']) === 0 && (empty($st['last_block_at']) || ($now - (int)$st['last_block_at'] > 604800))) {
            continue;
        }
        if ($prefix === 'u:') { $type='Cliente (panel)'; $uname=substr($k,2); }
        elseif ($prefix === 's:') { $type='Superadmin'; $uname=substr($k,2); }
        else { $type='Otro'; $uname=$k; }
        $blocked = !empty($st['blocked_until']) && (int)$st['blocked_until']>$now;
        $rows[] = [
            'key' => $k, 'type' => $type, 'uname' => $uname,
            'attempts_count' => (int)($st['attempts_count'] ?? 0),
            'blocked_until_ts' => (int)($st['blocked_until'] ?? 0),
            'level' => (int)($st['level'] ?? 0),
            'last_block_at' => (int)($st['last_block_at'] ?? 0),
            'blocked' => $blocked,
        ];
    }
    usort($rows, fn($a,$b) => [($b['blocked']?1:0),$b['attempts_count'],$b['last_block_at']] <=> [($a['blocked']?1:0),$a['attempts_count'],$a['last_block_at']]);
    return $rows;
}

// ==============================================================
// 🌐 IP-BASED THROTTLE (bloqueo por IP como DirectAdmin)
//    Si una IP comete X fallos TOTALES (cualquier usuario) = baneo
//    15 fallos  en 1h   = 1h  ban  (level 0)
//    tras expirar 15+1  = 24h ban  (level 1)
//    tras expirar 15+1  = 48h ban  (level >=2)
//    Al bloquear IP: NI siquiera se renderiza el formulario login
// ==============================================================
function sec_get_real_ip() {
    $headers = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','HTTP_CLIENT_IP'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $list = array_map('trim', explode(',', $_SERVER[$h]));
            foreach ($list as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) return $ip;
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) return $ip;
            }
        }
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
}
function sec_ip_key() { return 'ip:' . sec_get_real_ip(); }

function sec_ip_get_state($ipKey=null) {
    if ($ipKey === null) $ipKey = sec_ip_key();
    $all = sec_throttle_read();
    $st = $all[$ipKey] ?? ['attempts'=>[],'attempts_count'=>0,'blocked_until'=>0,'level'=>0,'last_block_at'=>0,'manual_comment'=>''];
    $now = time();
    if (!empty($st['blocked_until']) && $now >= (int)$st['blocked_until']) {
        $st['blocked_until'] = 0;
        $st['attempts'] = [];
        $st['attempts_count'] = 0;
    }
    if (!empty($st['attempts'])) {
        $cut = $now - 3600; // ventana 1 HORA para contar fallos IP (mas agresivo que usuarios 2h)
        $new = []; foreach ((array)$st['attempts'] as $ts) if ((int)$ts >= $cut) $new[] = (int)$ts;
        $st['attempts'] = array_values($new);
        $st['attempts_count'] = count($st['attempts']);
    }
    $all[$ipKey] = $st;
    sec_throttle_write($all);
    return $st;
}

function sec_ip_check_can_login() {
    $st  = sec_ip_get_state();
    $now = time();
    $threshold = 15;
    $attempts_left = max(0, $threshold - (int)$st['attempts_count']);
    $blocked_now = !empty($st['blocked_until']) && (int)$st['blocked_until'] > $now;
    if ($blocked_now) {
        $left = (int)$st['blocked_until'] - $now;
        if ($left < 60) $t = "{$left} segundos";
        elseif ($left < 3600) $t = (int)ceil($left/60)." minutos";
        else $t = (int)ceil($left/3600)." horas";
        $level = (int)$st['level'];
        $tipo = $level >= 2 ? '48 horas' : ($level === 1 ? '24 horas' : '1 hora');
        return [
            'can' => false,
            'message' => "🚫 Tu direcci\u00f3n IP est\u00e1 bloqueada temporalmente por demasiados intentos fallidos desde esta red. Tiempo restante: {$t}. (Bloqueo por {$tipo}).",
            'attempts_left' => 0,
            'blocked_until_ts' => (int)$st['blocked_until'],
            'level' => $level,
            'time_left_txt' => $t,
            'blocked_now' => true,
            'ip' => sec_get_real_ip(),
            'manual_comment' => $st['manual_comment'] ?? '',
        ];
    }
    return [
        'can' => true, 'message' => '', 'attempts_left' => $attempts_left,
        'blocked_until_ts' => 0, 'level' => (int)$st['level'],
        'time_left_txt' => '', 'blocked_now' => false, 'ip' => sec_get_real_ip(),
    ];
}

// $manual_hours si >0: baneo manual (no suma attempts, solo pone blocked_until directly + comment)
function sec_ip_record_fail($manual_hours=0, $manual_comment='', $ipKey=null) {
    if ($ipKey === null) $ipKey = sec_ip_key();
    $all = sec_throttle_read();
    if (!isset($all[$ipKey])) $all[$ipKey] = ['attempts'=>[],'attempts_count'=>0,'blocked_until'=>0,'level'=>0,'last_block_at'=>0,'manual_comment'=>''];
    $st = &$all[$ipKey];
    $now = time();
    $just_blocked = false; $time_txt = ''; $hours_block = 1;
    $threshold = 15;

    if ($manual_hours > 0) {
        $just_blocked = true;
        $st['level'] = min(2, max(0, (int)$st['level']));
        $st['blocked_until'] = $now + ((int)$manual_hours * 3600);
        $st['last_block_at'] = $now;
        $st['attempts'] = []; $st['attempts_count'] = 0;
        if (!empty($manual_comment)) $st['manual_comment'] = substr($manual_comment,0,200);
        $time_txt = $manual_hours >= 24 ? "{$manual_hours} horas" : "{$manual_hours} hora";
    } else {
        $st['attempts'][] = $now;
        if (count($st['attempts']) > 200) $st['attempts'] = array_slice($st['attempts'], -200);
        $st['attempts_count'] = count($st['attempts']);
        $thr = ((int)$st['level']) > 0 ? 1 : $threshold;
        if ((int)$st['attempts_count'] >= $thr) {
            $just_blocked = true;
            $nextLevel = ((int)$st['level'] <= 0) ? 0 : (int)$st['level'];
            if ($thr === 1) $nextLevel = min(2, (int)$st['level'] + 1);
            $st['level'] = $nextLevel;
            if ($nextLevel >= 2) $hours_block = 48;
            elseif ($nextLevel === 1) $hours_block = 24;
            else $hours_block = 1;
            $st['blocked_until'] = $now + ($hours_block * 3600);
            $st['last_block_at'] = $now;
            $st['attempts'] = []; $st['attempts_count'] = 0;
            $time_txt = $hours_block >= 24 ? "{$hours_block} horas" : "{$hours_block} hora";
        }
    }
    sec_throttle_write($all);
    return [
        'just_blocked' => $just_blocked,
        'blocked_until_ts' => (int)($st['blocked_until'] ?? 0),
        'level' => (int)($st['level'] ?? 0),
        'attempts_left' => max(0, $threshold - count($st['attempts'] ?? [])),
        'hours_block' => $manual_hours > 0 ? $manual_hours : $hours_block,
        'time_txt' => $time_txt,
    ];
}

function sec_ip_clear_throttle($ipKey) {
    $all = sec_throttle_read();
    if (isset($all[$ipKey])) {
        $all[$ipKey]['attempts'] = [];
        $all[$ipKey]['attempts_count'] = 0;
        $all[$ipKey]['blocked_until'] = 0;
        $all[$ipKey]['level'] = 0;
        $all[$ipKey]['last_block_at'] = 0;
        $all[$ipKey]['manual_comment'] = '';
        sec_throttle_write($all);
    }
}

function sec_ip_get_all() {
    $all = sec_throttle_read();
    $now = time();
    $rows = [];
    foreach ($all as $k => $st) {
        if (strncmp($k, 'ip:', 3) !== 0) continue;
        if (!empty($st['blocked_until']) && (int)$st['blocked_until'] <= $now) { $st['blocked_until']=0; $st['attempts']=[]; $st['attempts_count']=0; }
        if (!empty($st['attempts'])) {
            $cut = $now - 3600;
            $new = []; foreach ((array)$st['attempts'] as $ts) if ((int)$ts >= $cut) $new[]=(int)$ts;
            $st['attempts'] = $new; $st['attempts_count'] = count($new);
        }
        if (empty($st['blocked_until']) && (int)($st['attempts_count']) === 0 && (int)($st['level']) === 0 && (empty($st['last_block_at']) || ($now - (int)$st['last_block_at'] > 604800))) {
            continue;
        }
        $ip = substr($k, 3);
        $blocked = !empty($st['blocked_until']) && (int)$st['blocked_until']>$now;
        $rows[] = [
            'key' => $k, 'ip' => $ip,
            'attempts_count' => (int)($st['attempts_count'] ?? 0),
            'blocked_until_ts' => (int)($st['blocked_until'] ?? 0),
            'level' => (int)($st['level'] ?? 0),
            'last_block_at' => (int)($st['last_block_at'] ?? 0),
            'blocked' => $blocked,
            'manual_comment' => $st['manual_comment'] ?? '',
        ];
    }
    usort($rows, fn($a,$b) => [($b['blocked']?1:0),$b['attempts_count'],$b['last_block_at']] <=> [($a['blocked']?1:0),$a['attempts_count'],$a['last_block_at']]);
    return $rows;
}

// ====================================================================
// ✏️ TEXTOS PERSONALIZABLES LOGIN / 403
//    Almacenamiento: /data/login_texts.json. Si NO existe → DEFAULTS.
//    Si falta algún campo → merge DEFAULTS para NO romper nada.
// ====================================================================
function login_texts_path() {
    $dir = rtrim(BASE_PATH, '/\\') . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir . DIRECTORY_SEPARATOR . 'login_texts.json';
}

function login_texts_defaults() {
    return [
        'cliente' => [
            'form_title'    => 'Iniciar sesión',
            'form_sub'      => 'Ingresar con tu usuario y contraseña',
            'lbl_user'      => 'Usuario',
            'lbl_pwd'       => 'Contraseña',
            'btn_toggle_pwd_on'  => 'Ver',
            'btn_toggle_pwd_off' => 'Ocultar',
            'btn_submit'    => 'INGRESAR',
            'copyright'     => 'Copyright © 2026 — Radios CR. Todos los derechos reservados',
            'brand_logo'    => 'RADIOS CR',
            'brand_welcome' => 'Bienvenidos',
            'brand_desc'    => "Bienvenido a nuestro Panel Admin, contáctenos si necesita soporte.",
            'btn_contacto_txt' => 'CONTACTO',
            'btn_contacto_url' => 'https://wa.me/506',
        ],
        'superadmin' => [
            'tag_admin'         => "\u{2699}\ufe0f Panel Superadmin",
            'form_title'        => 'Acceso Superadministrador',
            'form_sub'          => 'Entrada al panel global de SuperRadio',
            'lbl_user'          => 'Usuario',
            'lbl_pwd'           => 'Contraseña',
            'btn_toggle_pwd_on' => 'Ver',
            'btn_toggle_pwd_off'=> 'Ocultar',
            'btn_submit'        => 'ENTRAR AL PANEL',
            'brand_logo'        => 'SUPERRADIO',
            'brand_tagline'     => 'Panel Global de Emisoras',
            'brand_desc'        => 'Gestiona emisoras clientes, puertos, programación y seguridad desde un solo sitio.',
            'brand_chips'       => "\u{1f4fb} Emisoras   \u{1f465} Clientes   \u{1f510} Seguridad   \u{1f4ca} Reportes",
            'copyright'         => '© 2026 SuperRadio · Panel Superadmin · Todos los derechos reservados',
        ],
        'ip403' => [
            'title'         => 'Dirección IP Bloqueada',
            'subtitle'      => 'Acceso denegado temporalmente',
            'ip_label'      => 'Tu IP',
            'timeleft_label'=> 'Tiempo Restante',
            'until_label'   => 'Bloqueado Hasta',
            'note_label'    => 'Nota del admin',
            'footer1'       => 'SuperRadio',
            'footer2'       => 'Panel de Emisoras',
            'footer_note'   => 'Si crees que esto es un error contacta a',
            'footer_word'   => 'soporte',
        ],
    ];
}

function login_texts_get() {
    $def = login_texts_defaults();
    $p = login_texts_path();
    $usr = [];
    if (file_exists($p)) {
        $x = @json_decode(file_get_contents($p), true);
        if (is_array($x)) $usr = $x;
    }
    $out = [];
    foreach ($def as $sec => $map) {
        $out[$sec] = [];
        foreach ($map as $k => $v) {
            $custom = $usr[$sec][$k] ?? null;
            if (is_string($custom) && $custom !== '') {
                $out[$sec][$k] = $custom;
            } else {
                $out[$sec][$k] = $v;
            }
        }
    }
    return $out;
}

function login_texts_sanitize($arr) {
    $def = login_texts_defaults();
    $out = login_texts_defaults();
    foreach ($def as $sec => $map) {
        if (!is_array($arr[$sec] ?? null)) continue;
        foreach ($map as $k => $default) {
            $raw = $arr[$sec][$k] ?? null;
            if (!is_string($raw) || trim($raw) === '') {
                $out[$sec][$k] = $default;
                continue;
            }
            $max = 500;
            if ($k === 'brand_desc' || $k === 'brand_tagline' || $k === 'form_sub' || $k === 'copyright' || $k === 'brand_chips') $max = 400;
            elseif (strpos($k, '_url') !== false) $max = 1000;
            elseif ($k === 'form_title' || $k === 'brand_logo' || $k === 'btn_submit') $max = 80;
            else $max = 180;
            $clean = trim(strip_tags($raw));
            if (strlen($clean) > $max) $clean = substr($clean, 0, $max);
            $out[$sec][$k] = $clean === '' ? $default : $clean;
        }
    }
    return $out;
}

function login_texts_save($arr) {
    $clean = login_texts_sanitize($arr);
    $p = login_texts_path();
    @file_put_contents($p, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $clean;
}

function login_texts_reset() {
    $p = login_texts_path();
    if (file_exists($p)) { @unlink($p); }
}

// =============================================================
// CORREO SMTP (sin MTA local) — configuración + envío por socket
// La contraseña SMTP se guarda cifrada con ENCRYPT_KEY.
// =============================================================
function sp_smtp_cfg_defaults() {
    return ['host' => '', 'port' => 587, 'secure' => 'tls', 'username' => '', 'enc_password' => '', 'from_name' => '', 'from_email' => ''];
}

function sp_smtp_cfg_load($db) {
    $cfg = sp_smtp_cfg_defaults();
    foreach ((array)($db['smtp'] ?? []) as $k => $v) {
        if (array_key_exists($k, $cfg)) $cfg[$k] = $v;
    }
    return $cfg;
}

function sp_mail_sanitize_addr($v) {
    return str_replace(["\r", "\n", ','], '', trim((string)$v));
}

function sp_smtp_send_msg($cfg, $to, $subject, $body, &$err = '') {
    $err = '';
    $host = sp_mail_sanitize_addr($cfg['host'] ?? '');
    $port = max(1, (int)($cfg['port'] ?? 587));
    $secure = in_array(($cfg['secure'] ?? ''), ['ssl', 'tls'], true) ? $cfg['secure'] : '';
    $user = sp_mail_sanitize_addr($cfg['username'] ?? '');
    $pass = decrypt_pass($cfg['enc_password'] ?? '');
    $from_email = sp_mail_sanitize_addr($cfg['from_email'] ?? '');
    $from_name = trim(str_replace(["\r", "\n"], '', (string)($cfg['from_name'] ?? '')));

    if ($host === '') { $err = 'Falta el servidor SMTP (host). Configúralo en la sección Correo.'; return false; }
    if ($from_email === '' || !filter_var($from_email, FILTER_VALIDATE_EMAIL)) { $err = 'Falta un email de remitente válido (Correo → Remitente).'; return false; }
    $to = filter_var(sp_mail_sanitize_addr($to), FILTER_VALIDATE_EMAIL);
    if (!$to) { $err = 'El destinatario no tiene un email válido.'; return false; }

    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl' : 'tcp') . '://' . $host . ':' . $port, $eno, $estr, 15);
    if (!$fp) { $err = 'No se pudo conectar con el servidor SMTP (' . $eno . ': ' . $estr . ').'; return false; }
    stream_set_timeout($fp, 20);

    $cmd = function ($c) use ($fp) { fwrite($fp, $c . "\r\n"); };
    $read = function () use ($fp) {
        $out = '';
        while (true) {
            $line = fgets($fp, 512);
            if ($line === false) break;
            $out .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return $out;
    };
    $ok = function ($codes, $resp) {
        $code = substr($resp, 0, 3);
        return in_array($code, (array)$codes, true);
    };

    $r = $read();
    if (!$ok('220', $r)) { $err = 'El servidor SMTP no respondió correctamente: ' . trim($r); fclose($fp); return false; }
    $cmd('EHLO radiopanel.local');
    $r = $read();
    if (!$ok('250', $r)) { $err = 'Fallo en EHLO: ' . trim($r); fclose($fp); return false; }
    if ($secure === 'tls') {
        $cmd('STARTTLS');
        $r = $read();
        if (!$ok('220', $r)) { $err = 'El servidor rechazó STARTTLS: ' . trim($r); fclose($fp); return false; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { $err = 'No se pudo establecer la conexión segura (TLS).'; fclose($fp); return false; }
        $cmd('EHLO radiopanel.local');
        $r = $read();
        if (!$ok('250', $r)) { $err = 'Fallo en EHLO tras TLS: ' . trim($r); fclose($fp); return false; }
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN');
        $r = $read();
        if (!$ok('334', $r)) { $err = 'El servidor no acepta autenticación: ' . trim($r); fclose($fp); return false; }
        $cmd(base64_encode($user));
        $r = $read();
        if (!$ok('334', $r)) { $err = 'Usuario SMTP rechazado: ' . trim($r); fclose($fp); return false; }
        $cmd(base64_encode($pass));
        $r = $read();
        if (!$ok('235', $r)) { $err = 'Credenciales SMTP rechazadas: ' . trim($r); fclose($fp); return false; }
    }
    $cmd('MAIL FROM:<' . $from_email . '>');
    $r = $read();
    if (!$ok('250', $r)) { $err = 'MAIL FROM rechazado: ' . trim($r); fclose($fp); return false; }
    $cmd('RCPT TO:<' . $to . '>');
    $r = $read();
    if (!$ok(['250', '251'], $r)) { $err = 'Destinatario rechazado: ' . trim($r); fclose($fp); return false; }
    $cmd('DATA');
    $r = $read();
    if (!$ok('354', $r)) { $err = 'El servidor no aceptó DATA: ' . trim($r); fclose($fp); return false; }

    $from_hdr = $from_name !== '' ? $from_name . ' <' . $from_email . '>' : $from_email;
    $headers = "From: " . $from_hdr . "\r\n"
        . "To: <" . $to . ">\r\n"
        . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n";
    $payload = $headers . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    $cmd($payload);
    $cmd('.');
    $r = $read();
    if (!$ok('250', $r)) { $err = 'El servidor rechazó el mensaje: ' . trim($r); fclose($fp); return false; }
    $cmd('QUIT');
    fclose($fp);
    return true;
}
