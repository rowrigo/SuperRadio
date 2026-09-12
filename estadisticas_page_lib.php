<?php
/**
 * Estadísticas del PLAYER / PÁGINA PÚBLICA (visitas), separadas de las del STREAM.
 *
 * Qué se cuenta:
 *   - VISITA  = cada carga (GET) de radio_page.php por un humano (bot filtrado).
 *   - ÚNICO   = visitantes distintos por día (hash de IP, sin guardar la IP).
 *   - ONLINE  = visitantes con la página abierta ahora (latido desde el navegador).
 *
 * Fuente de datos: el propio servidor web (no logs). Se registra al servir la página
 * y el latido llega por autodj_api.php?action=page_ping.
 *
 * Almacenamiento (FUERA del webroot, /var/media/radios/_page_stats):
 *   daily/YYYY-MM-DD.json -> { "<mount>": { "visits": N, "uniques": ["<hash16>", ...] } }
 *        El día se calcula en la zona horaria de la radio (igual que el stream).
 *   presence.json         -> { "<mount>": { "<sid>": <unix ts> } }   (se poda solo)
 *   page.lock             -> exclusión mutua (visitas + presencia)
 *
 * Privacidad: NO se guardan IPs ni identificadores en claro; solo hashes truncados.
 */

require_once __DIR__ . '/estadisticas_lib.php'; // reutiliza est_read_json/est_write_json/est_tz_for_mount

if (!function_exists('esp_base_dir')) {

function esp_base_dir() {
    $d = '/var/media/radios/_page_stats';
    if (!is_dir($d)) { @mkdir($d, 0775, true); @chmod($d, 0775); }
    foreach (['daily'] as $sub) {
        if (!is_dir("{$d}/{$sub}")) { @mkdir("{$d}/{$sub}", 0775, true); }
    }
    return $d;
}

function esp_lock_path()     { return esp_base_dir() . '/page.lock'; }
function esp_presence_path() { return esp_base_dir() . '/presence.json'; }
function esp_day_path($d)    { return esp_base_dir() . '/daily/' . preg_replace('/[^0-9-]/', '', $d) . '.json'; }

/** Ventana (segundos) sin latido tras la cual un visitante deja de contar como "en línea". */
function esp_online_window() { return 120; }
/** Caducidad (segundos) de una entrada de presencia antes de podarla. */
function esp_presence_ttl()  { return 600; }

/** Fecha 'Y-m-d' en la zona horaria de la radio (locale de la emisora). */
function esp_today($tz) {
    try { return (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d'); }
    catch (\Throwable $x) { return gmdate('Y-m-d'); }
}

/** IP del visitante priorizando cabeceras del proxy; sin guardarla (solo se hashea). */
function esp_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (empty($_SERVER[$k])) continue;
        $v = trim(explode(',', (string)$_SERVER[$k])[0]);
        if ($v !== '') return $v;
    }
    return '';
}

/** Hash corto (16 hex) de una IP para contar únicos sin almacenar la IP. */
function esp_ip_hash($ip) {
    $ip = trim((string)$ip);
    if ($ip === '') return 'anon';
    return substr(sha1($ip), 0, 16);
}

function esp_with_lock(callable $fn) {
    $lock = @fopen(esp_lock_path(), 'c');
    if (!$lock) return $fn();
    flock($lock, LOCK_EX);
    try { return $fn(); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Registra una VISITA (carga de la página pública) para el mount indicado. */
function esp_record_visit($mount, $ip = '') {
    $mount = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$mount)));
    if ($mount === '') return false;
    $day = esp_today(est_tz_for_mount($mount));
    $pf  = esp_day_path($day);
    return (bool) esp_with_lock(function () use ($pf, $mount, $ip) {
        $j = est_read_json($pf, []);
        if (!isset($j[$mount]) || !is_array($j[$mount])) $j[$mount] = ['visits' => 0, 'uniques' => []];
        $j[$mount]['visits'] = (int)($j[$mount]['visits'] ?? 0) + 1;
        $u = isset($j[$mount]['uniques']) && is_array($j[$mount]['uniques']) ? $j[$mount]['uniques'] : [];
        $h = esp_ip_hash($ip);
        if (!in_array($h, $u, true)) $u[] = $h;
        $j[$mount]['uniques'] = array_slice($u, 0, 20000);
        est_write_json($pf, $j, false);
        esp_cleanup_old_days();
        return true;
    });
}

/** Latido de presencia: marca al visitante $sid como "en línea ahora" y poda los viejos. */
function esp_touch_presence($mount, $sid) {
    $mount = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$mount)));
    if ($mount === '' || $sid === '') return false;
    $now = time();
    $ttl = esp_presence_ttl();
    $pp  = esp_presence_path();
    return (bool) esp_with_lock(function () use ($pp, $mount, $sid, $now, $ttl) {
        $j = est_read_json($pp, []);
        foreach ($j as $m => $sids) {
            if (!is_array($sids)) { unset($j[$m]); continue; }
            foreach ($sids as $s => $t) { if ((int)$t < $now - $ttl) unset($j[$m][$s]); }
            if (empty($j[$m])) unset($j[$m]);
        }
        $j[$mount][$sid] = $now;
        est_write_json($pp, $j, false);
        return true;
    });
}

/** Visitantes con la página abierta ahora (latido dentro de la ventana de presencia). */
function esp_online($mount) {
    $mount = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$mount)));
    if ($mount === '') return 0;
    $j = est_read_json(esp_presence_path(), []);
    $sids = isset($j[$mount]) && is_array($j[$mount]) ? $j[$mount] : [];
    $cut = time() - esp_online_window();
    $n = 0;
    foreach ($sids as $s => $t) { if ((int)$t >= $cut) $n++; }
    return $n;
}

/** Borra días antiguos (>60) para que el histórico no crezca sin límite. */
function esp_cleanup_old_days() {
    if (random_int(1, 40) !== 1) return;
    $cut = strtotime('-60 days');
    foreach (glob(esp_base_dir() . '/daily/*.json') ?: [] as $df) {
        $base = basename($df, '.json');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $base) && strtotime($base) < $cut) @unlink($df);
    }
}

/** Suma visitas y únicos (unión) de una lista de días para un mount. */
function esp_sum_days($mount, $days) {
    $visits = 0; $uniques = [];
    foreach ($days as $day) {
        $f = esp_day_path($day);
        if (!is_file($f)) continue;
        $j = est_read_json($f, []);
        if (empty($j[$mount]) || !is_array($j[$mount])) continue;
        $visits += (int)($j[$mount]['visits'] ?? 0);
        foreach (($j[$mount]['uniques'] ?? []) as $h) { $uniques[$h] = true; }
    }
    return ['visits' => $visits, 'uniques' => count($uniques)];
}

/** Payload de períodos (hoy/ayer/semana/mes) + en línea ahora, para la vista del panel. */
function esp_periods_payload($mount) {
    $tz = est_tz_for_mount($mount);
    try { $now = new DateTime('now', new DateTimeZone($tz)); }
    catch (\Throwable $x) { $tz = 'America/Costa_Rica'; $now = new DateTime('now', new DateTimeZone($tz)); }

    $daysOf = function ($n) use ($now) {
        $out = [];
        $c = clone $now;
        for ($i = 0; $i < $n; $i++) { $out[] = $c->format('Y-m-d'); $c->modify('-1 day'); }
        return $out;
    };
    $build = function ($days) use ($mount) {
        $a = esp_sum_days($mount, $days);
        return ['total' => $a['visits'], 'unicos' => $a['uniques']];
    };

    $today     = $now->format('Y-m-d');
    $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');

    return [
        'mount'  => $mount,
        'as_of'  => $now->format('Y-m-d H:i:s'),
        'online' => esp_online($mount),
        'periodos' => [
            'hoy'    => $build([$today]),
            'ayer'   => $build([$yesterday]),
            'semana' => $build($daysOf(7)),
            'mes'    => $build($daysOf(30)),
        ],
    ];
}
}
