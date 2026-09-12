<?php
/**
 * Estadísticas de audiencia del STREAM por radio (conexiones / países / duración / pico).
 *
 * Qué se cuenta: cada CONEXIÓN al stream (mount de Icecast), venga del reproductor
 * propio, de otro reproductor, de un dominio o directa a la IP:8000.
 *
 * Fuentes:
 *   - Icecast access.log  = fuente PRIMARIA de conexiones. Registra TODAS las conexiones
 *     al mount (las directas con la IP real y las que llegan vía nginx como 127.0.0.1)
 *     y, en el último campo, la DURACIÓN de la conexión en segundos.
 *   - nginx access logs   = solo para resolver el PAÍS de las conexiones que pasan por el
 *     proxy (Icecast las ve como 127.0.0.1). Se emparejan por (mount, timestamp), que
 *     coincide al segundo porque ambos registran el momento de inicio de la sesión.
 *
 * Almacenamiento (FUERA del webroot, /var/media/radios/_listener_stats):
 *   daily/YYYY-MM-DD.json  -> { "<mount>": [ {ts,dur,cc,h,dv} ... ] }
 *        ts  = inicio de la conexión (unix)
 *        dur = segundos conectado (0 = desconocida)
 *        cc  = país ISO-2 (-- local, ?? desconocido)
 *        h   = hash de la IP (sin IP cruda) para contar oyentes únicos
 *        dv  = tipo de dispositivo (0 móvil, 1 tablet, 2 escritorio, 3 reproductor, 4 otro)
 *   geo_cache.json         -> { sha1(IP): {cc, t} }
 *   state.json             -> cursores/tails por log + índice de IPs del proxy
 *   ingest.lock            -> exclusión mutua del ingest
 *
 * Privacidad: NO se guardan IPs en claro; solo un hash (h) y el código de país.
 */

if (!function_exists('est_base_dir')) {

function est_base_dir() {
    $d = '/var/media/radios/_listener_stats';
    if (!is_dir($d)) { @mkdir($d, 0775, true); @chmod($d, 0775); }
    foreach (['daily'] as $sub) {
        if (!is_dir("{$d}/{$sub}")) { @mkdir("{$d}/{$sub}", 0775, true); }
    }
    return $d;
}

function est_state_path() { return est_base_dir() . '/state.json'; }
function est_geo_path()   { return est_base_dir() . '/geo_cache.json'; }
function est_lock_path()  { return est_base_dir() . '/ingest.lock'; }
function est_day_path($d) { return est_base_dir() . '/daily/' . preg_replace('/[^0-9-]/', '', $d) . '.json'; }

/** Versión del formato de datos; al cambiar se reconstruye el histórico desde los logs. */
function est_data_version() { return 2; }

function est_read_json($path, $def = []) {
    if (!is_file($path)) return $def;
    $j = @json_decode(@file_get_contents($path), true);
    return is_array($j) ? $j : $def;
}

function est_write_json($path, $data, $pretty = true) {
    $flags = JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0);
    $ok = @file_put_contents($path, json_encode($data, $flags));
    if ($ok) @chmod($path, 0664);
    return (bool)$ok;
}

/** Mapas mount(s) conocidos (clave normalizada) desde database.json. */
function est_mounts_map() {
    if (!defined('DB_FILE')) { @require_once __DIR__ . '/config.php'; }
    $db = file_exists(DB_FILE) ? json_decode(file_get_contents(DB_FILE), true) : [];
    $map = [];
    foreach (($db['radios'] ?? []) as $r) {
        $m = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $r['mountpoint'] ?? '')));
        if ($m !== '') $map[$m] = true;
    }
    return $map;
}

/** Zona horaria de una radio (para agrupar "hoy/ayer/semana/mes" en hora local de la emisora). */
function est_tz_for_mount($mount) {
    $f = '/var/media/radios/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $mount) . '/programacion.json';
    $d = est_read_json($f, []);
    return !empty($d['timezone']) ? (string)$d['timezone'] : 'America/Costa_Rica';
}

/** Nombres de países en español (visualización); fallback = código. */
function est_country_es($cc) {
    $cc = strtoupper((string)$cc);
    $map = [
        'US'=>'Estados Unidos','MX'=>'México','CA'=>'Canadá','CR'=>'Costa Rica','PA'=>'Panamá','GT'=>'Guatemala',
        'HN'=>'Honduras','SV'=>'El Salvador','NI'=>'Nicaragua','BZ'=>'Belice','DO'=>'República Dominicana',
        'PR'=>'Puerto Rico','CU'=>'Cuba','JM'=>'Jamaica','HT'=>'Haití','CO'=>'Colombia','VE'=>'Venezuela',
        'EC'=>'Ecuador','PE'=>'Perú','BO'=>'Bolivia','CL'=>'Chile','AR'=>'Argentina','UY'=>'Uruguay','PY'=>'Paraguay',
        'BR'=>'Brasil','ES'=>'España','GB'=>'Reino Unido','FR'=>'Francia','DE'=>'Alemania','IT'=>'Italia',
        'PT'=>'Portugal','NL'=>'Países Bajos','BE'=>'Bélgica','CH'=>'Suiza','AT'=>'Austria','IE'=>'Irlanda',
        'SE'=>'Suecia','NO'=>'Noruega','DK'=>'Dinamarca','FI'=>'Finlandia','PL'=>'Polonia','CZ'=>'Chequia',
        'SK'=>'Eslovaquia','HU'=>'Hungría','RO'=>'Rumania','BG'=>'Bulgaria','GR'=>'Grecia','HR'=>'Croacia',
        'RS'=>'Serbia','UA'=>'Ucrania','RU'=>'Rusia','TR'=>'Turquía','IL'=>'Israel','AE'=>'Emiratos Árabes',
        'SA'=>'Arabia Saudita','QA'=>'Catar','KW'=>'Kuwait','IN'=>'India','PK'=>'Pakistán','BD'=>'Bangladés',
        'LK'=>'Sri Lanka','NP'=>'Nepal','TH'=>'Tailandia','VN'=>'Vietnam','PH'=>'Filipinas','ID'=>'Indonesia',
        'MY'=>'Malasia','SG'=>'Singapur','CN'=>'China','TW'=>'Taiwán','HK'=>'Hong Kong','JP'=>'Japón',
        'KR'=>'Corea del Sur','AU'=>'Australia','NZ'=>'Nueva Zelanda','ZA'=>'Sudáfrica','EG'=>'Egipto',
        'MA'=>'Marruecos','DZ'=>'Argelia','TN'=>'Túnez','NG'=>'Nigeria','KE'=>'Kenia','GH'=>'Ghana',
        '--'=>'Local / Red privada','??'=>'Desconocido',
    ];
    return $map[$cc] ?? $cc;
}

/** Clasificador de dispositivo a partir del User-Agent. Devuelve [codigo, etiqueta]. */
function est_device($ua) {
    $u = strtolower((string)$ua);
    if (strpos($u, 'ipad') !== false || strpos($u, 'tablet') !== false) return [1, 'Tablet'];
    if (strpos($u, 'iphone') !== false || strpos($u, 'ipod') !== false) return [0, 'Móvil (iOS)'];
    if (strpos($u, 'android') !== false) {
        return (strpos($u, 'mobile') !== false || strpos($u, 'phone') !== false) ? [0, 'Móvil (Android)'] : [1, 'Tablet'];
    }
    if (preg_match('/(windows phone|windows mobile|blackberry|opera mini|iemobile|symbian)/', $u)) return [0, 'Móvil'];
    if (strpos($u, 'windows nt') !== false || strpos($u, 'macintosh') !== false || strpos($u, 'x11') !== false || strpos($u, 'linux') !== false) {
        foreach (['vlc','libvlc','itunes','winamp','foobar2000','aimp','clementine','amarok','rhythmbox','mpv','mpc-hc','media player classic','gstreamer','sonos','xbmc','kodi','spotify'] as $tok) {
            if (strpos($u, $tok) !== false) return [3, 'Reproductor de escritorio'];
        }
        return [2, 'Escritorio'];
    }
    foreach (['vlc','libvlc','itunes','winamp','foobar2000','aimp','clementine','amarok','rhythmbox','mpv','gstreamer','sonos','xbmc','kodi','spotify','cfnetwork','okhttp','exoplayer'] as $tok) {
        if (strpos($u, $tok) !== false) return [3, 'Aplicación / Reproductor'];
    }
    return [4, 'Otro / Desconocido'];
}

/** ¿User-Agent de bot/sondeo/escaner? (no se cuenta como oyente) */
function est_is_bot_ua($ua) {
    $u = strtolower(trim((string)$ua));
    if ($u === '' || $u === '-') return true;
    if (preg_match('/(curl|wget|python|perl|ruby|java\/|okhttp|go-http-client|httpie|powershell|libwww|bot|spider|crawl|slurp|scrape|zgrab|masscan|nmap|nessus|headless|uptimerobot|pingdom|monitor|screenshot|feedfetcher|bingpreview|mediatools|facebookexternalhit|whatsapp|telegrambot|liquidsoap|ffmpeg|status-json)/i', $u)) return true;
    if (strpos($u, 'mozilla') === false && strpos($u, 'vlc') === false && strpos($u, 'itunes') === false && strpos($u, 'winamp') === false && strpos($u, 'cfnetwork') === false && strpos($u, 'exoplayer') === false) {
        if (!preg_match('/[a-z]{3,}/', $u)) return true;
    }
    return false;
}

function est_ip_is_local($ip) {
    $ip = trim((string)$ip);
    if ($ip === '' || $ip === '-' || $ip === '127.0.0.1' || $ip === '::1') return true;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return true;
    return false;
}

/**
 * Geo por IP: caché en disco clave = sha1(IP) (sin guardar la IP). Las que falten
 * se resuelven en lote contra ip-api.com (gratis, ~45 req/min; 1 petición por ≤100 IPs).
 * Entrada/salida: $ipsNeeded = [ip => cc] (las no resueltas quedan en '??').
 */
function est_geo_lookup_batch(&$ipsNeeded) {
    if (empty($ipsNeeded)) return;
    $gcache = est_read_json(est_geo_path(), []);
    $todo = [];
    foreach ($ipsNeeded as $ip => $v) {
        $k = sha1($ip);
        if (isset($gcache[$k]['cc'])) {
            $ipsNeeded[$ip] = $gcache[$k]['cc'];
        } else {
            $todo[$ip] = $k;
        }
    }
    if (empty($todo)) return;
    $now = time();
    $list = array_keys($todo);
    foreach (array_chunk($list, 100) as $chunk) {
        $body = json_encode(array_map(fn($i) => $i, $chunk));
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 6,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents('http://ip-api.com/batch?fields=status,query,countryCode', false, $ctx);
        $res = $raw ? json_decode($raw, true) : null;
        if (!is_array($res)) { usleep(200000); continue; }
        foreach ($res as $row) {
            if (!is_array($row) || ($row['status'] ?? '') !== 'success') continue;
            $q = (string)($row['query'] ?? '');
            if (!isset($todo[$q])) continue;
            $cc = strtoupper(substr((string)($row['countryCode'] ?? ''), 0, 2));
            $ipsNeeded[$q] = $cc !== '' ? $cc : '??';
            $gcache[$todo[$q]] = ['cc' => $cc !== '' ? $cc : '??', 't' => $now];
        }
    }
    foreach ($ipsNeeded as $ip => $cc) {
        if ($cc === 1) { $ipsNeeded[$ip] = '??'; }
    }
    est_write_json(est_geo_path(), $gcache);
}

/**
 * Parsea una línea de log (formato combined de nginx o de Icecast).
 * Icecast añade al final la duración de la conexión en segundos (campo opcional).
 * Devuelve ['mount','ip','ua','ts','dur'] o null si no es una conexión GET 2xx a un mount.
 */
function est_parse_line($line) {
    if (!preg_match('/^(\S+) - - \[([^\]]+)\] "([A-Z]+) (\S+)[^"]*" (\d{3}) \S+ "[^"]*" "([^"]*)"(?:\s+(\d+))?\s*$/', $line, $m)) return null;
    if ($m[3] !== 'GET') return null;
    $status = (int)$m[5];
    if ($status < 200 || $status >= 300) return null;
    $path = $m[4];
    $q = strpos($path, '?');
    if ($q !== false) $path = substr($path, 0, $q);
    if (!preg_match('#^/([a-zA-Z0-9_-]+)$#', $path, $pm)) return null;
    $ua = $m[6];
    if (est_is_bot_ua($ua)) return null;
    $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $m[2]);
    if (!$dt) return null;
    return [
        'mount' => strtolower($pm[1]),
        'ip'    => $m[1],
        'ua'    => $ua,
        'ts'    => $dt->getTimestamp(),
        'dur'   => isset($m[7]) ? (int)$m[7] : 0,
        'k'     => md5(rtrim($line)),
    ];
}

/** Consume un stream de líneas; acumula eventos nuevos (respetando tail por log). */
function est_consume_stream($handle, $mounts, $tail, &$tailRef, $events) {
    $skipAll = ($tail === null);
    while (($line = fgets($handle)) !== false) {
        $r = est_parse_line($line);
        if (!$r) continue;
        if (!isset($mounts[$r['mount']])) continue;
        if (!$skipAll && $r['ts'] <= $tail) continue;
        if ($tailRef === null || $r['ts'] > $tailRef) $tailRef = $r['ts'];
        $events[] = $r;
    }
    return $events;
}

/**
 * Lee un conjunto de logs (fichero actual + rotados) y devuelve los eventos nuevos.
 * - Rotados: se leen solo cuando cambia su mtime (o en un rebuild).
 * - Actual: incremental por cursor (ino+size) para no releerlo entero.
 * - $rebuild: ignora tails/cursores y lee todo lo disponible (reconstrucción).
 */
function est_collect_logs(array $logs, array $mounts, array &$src, $rebuild) {
    $events = [];
    if (!isset($src['tail']) || !is_array($src['tail'])) $src['tail'] = [];
    if (!isset($src['pos'])  || !is_array($src['pos']))  $src['pos']  = [];
    if (!isset($src['rot'])  || !is_array($src['rot']))  $src['rot']  = [];

    foreach ($logs as $p) {
        if (!is_file($p)) continue;
        $tail = $rebuild ? 0 : ($src['tail'][$p] ?? null);
        $tailRef = $tail;

        // 1) Rotados (.old de Icecast + .N / .N.gz de logrotate)
        $cands = ["{$p}.old"];
        for ($n = 1; $n <= 40; $n++) { $cands[] = "{$p}.{$n}"; $cands[] = "{$p}.{$n}.gz"; }
        foreach ($cands as $c) {
            if (!is_file($c)) continue;
            $mt = @filemtime($c);
            $seen = $src['rot'][$p][$c] ?? null;
            if (!$rebuild && $seen !== null && (int)$seen === (int)$mt) continue;
            $src['rot'][$p][$c] = (int)$mt;
            $gz = (substr($c, -3) === '.gz');
            $h = $gz ? @gzopen($c, 'rb') : @fopen($c, 'rb');
            if ($h) {
                $events = est_consume_stream($h, $mounts, $tail, $tailRef, $events);
                if ($gz) gzclose($h); else fclose($h);
            }
        }

        // 2) Fichero actual -> incremental por cursor (ino+size)
        $st = @stat($p);
        if ($st === false) continue;
        $ino = (int)$st['ino'];
        $size = (int)$st['size'];
        $offset = 0;
        if (!$rebuild) {
            $cur = $src['pos'][$p] ?? null;
            if (is_array($cur) && (int)($cur['ino'] ?? 0) === $ino && isset($cur['size']) && (int)$cur['size'] <= $size) {
                $offset = (int)$cur['size'];
            }
        }
        $h = @fopen($p, 'rb');
        if (!$h) continue;
        if ($offset > 0) fseek($h, $offset);
        $events = est_consume_stream($h, $mounts, $tail, $tailRef, $events);
        fclose($h);
        $src['tail'][$p] = $tailRef;
        $src['pos'][$p] = ['ino' => $ino, 'size' => $size, 'at' => time()];
    }
    return $events;
}

/** Clave de deduplicación de un evento (idempotencia del ingest). */
function est_evkey($ev) {
    if (!empty($ev['k'])) return $ev['k'];
    return md5(($ev['ts'] ?? 0) . '|' . ($ev['dur'] ?? 0) . '|' . ($ev['cc'] ?? '') . '|' . ($ev['h'] ?? '') . '|' . ($ev['dv'] ?? ''));
}

/**
 * Resuelve la IP real (vía índice nginx) de una conexión que Icecast registró como 127.0.0.1.
 * nginx suele registrar la petición ~1 s antes que Icecast, así que se busca en una ventana
 * de ±2 s, prefiriendo el mismo User-Agent y, en su defecto, el offset más cercano.
 */
function est_proxy_resolve($index, $mount, $ts, $ua) {
    $best = null; $bestOff = PHP_INT_MAX;
    foreach ([0, -1, 1, -2, 2] as $off) {
        $k = $mount . '|' . ($ts + $off);
        if (!isset($index[$k])) continue;
        $cand = $index[$k];
        if ($ua !== '' && isset($cand['ua']) && $cand['ua'] === $ua) return $cand;
        if (abs($off) < $bestOff) { $best = $cand; $bestOff = abs($off); }
    }
    return $best;
}

/** Migración: aparta el histórico derivado de nginx y marca la versión nueva. */
function est_migrate_if_needed(array $state, &$rebuild) {
    if (isset($state['v']) && (int)$state['v'] >= est_data_version()) return $state;
    $daily = est_base_dir() . '/daily';
    if (is_dir($daily) && (glob($daily . '/*.json') ?: [])) {
        @rename($daily, est_base_dir() . '/daily.pre-stream-' . date('Ymd-His'));
        @mkdir($daily, 0775, true);
    }
    $rebuild = true;
    return [];
}

/**
 * Ingest principal. Lee los logs del stream (Icecast) y usa nginx para el país de los
 * oyentes que pasan por el proxy. Idempotente y seguro en paralelo (flock).
 */
function est_ingest_run($rebuild = false) {
    $lock = fopen(est_lock_path(), 'c');
    if (!$lock) return ['ok' => false, 'error' => 'no lock file'];
    $got = false;
    for ($i = 0; $i < 100; $i++) {
        if (flock($lock, LOCK_EX | LOCK_NB)) { $got = true; break; }
        usleep(100000);
    }
    if (!$got) return ['ok' => false, 'error' => 'busy'];

    try {
        $mounts = est_mounts_map();
        if (empty($mounts)) return ['ok' => false, 'error' => 'sin radios'];

        $state = est_read_json(est_state_path(), []);
        $state = est_migrate_if_needed($state, $rebuild);

        // ---- 1) nginx: índice (mount|ts) => {h, cc} para resolver el país de las proxied ----
        $ngxLogs = ['/var/log/nginx/access.log', '/var/log/nginx/radiopanel_ssl_access.log', '/var/log/nginx/radiopanel_access.log'];
        if (!isset($state['ngx']) || !is_array($state['ngx'])) $state['ngx'] = [];
        $ngxEvents = est_collect_logs($ngxLogs, $mounts, $state['ngx'], $rebuild);

        $ngxIps = [];
        foreach ($ngxEvents as $e) { if (!est_ip_is_local($e['ip'])) $ngxIps[$e['ip']] = 1; }
        if (!empty($ngxIps)) est_geo_lookup_batch($ngxIps);

        $index = isset($state['proxy_index']) && is_array($state['proxy_index']) ? $state['proxy_index'] : [];
        foreach ($ngxEvents as $e) {
            if (est_ip_is_local($e['ip'])) continue;
            $index[$e['mount'] . '|' . $e['ts']] = [
                'h'  => substr(sha1($e['ip']), 0, 16),
                'cc' => $ngxIps[$e['ip']] ?? '??',
                'ua' => $e['ua'],
            ];
        }

        // ---- 2) Icecast: cada conexión al stream (con IP real y duración) ----
        $iceLogs = ['/var/log/icecast2/access.log'];
        if (!isset($state['ice']) || !is_array($state['ice'])) $state['ice'] = [];
        $iceEvents = est_collect_logs($iceLogs, $mounts, $state['ice'], $rebuild);

        $iceIps = [];
        foreach ($iceEvents as $e) { if (!est_ip_is_local($e['ip'])) $iceIps[$e['ip']] = 1; }
        if (!empty($iceIps)) est_geo_lookup_batch($iceIps);

        $byDay = [];
        foreach ($iceEvents as $e) {
            $dur = (int)($e['dur'] ?? 0);
            if (est_ip_is_local($e['ip'])) {
                $m = est_proxy_resolve($index, $e['mount'], $e['ts'], $e['ua']);
                if ($m !== null) { $cc = $m['cc']; $h = $m['h']; }
                else { $cc = '??'; $h = substr(sha1('L|' . $e['mount'] . '|' . $e['ts']), 0, 16); }
            } else {
                $cc = $iceIps[$e['ip']] ?? '??';
                $h  = substr(sha1($e['ip']), 0, 16);
            }
            $tz = est_tz_for_mount($e['mount']);
            try { $day = (new DateTime('@' . $e['ts']))->setTimezone(new DateTimeZone($tz))->format('Y-m-d'); }
            catch (\Throwable $x) { $day = gmdate('Y-m-d', $e['ts']); }
            list($dv) = est_device($e['ua']);
            $byDay[$day][$e['mount']][] = ['ts' => $e['ts'], 'dur' => $dur, 'cc' => $cc, 'h' => $h, 'dv' => $dv, 'k' => $e['k']];
        }

        // ---- 3) Escribir por día con deduplicación ----
        $written = 0;
        foreach ($byDay as $day => $mountsNew) {
            $pf = est_day_path($day);
            $cur = est_read_json($pf, []);
            foreach ($mountsNew as $mount => $evs) {
                if (!isset($cur[$mount]) || !is_array($cur[$mount])) $cur[$mount] = [];
                $seen = [];
                foreach ($cur[$mount] as $old) $seen[est_evkey($old)] = true;
                foreach ($evs as $ev) {
                    $k = est_evkey($ev);
                    if (isset($seen[$k])) continue;
                    $cur[$mount][] = $ev;
                    $seen[$k] = true;
                    $written++;
                }
            }
            est_write_json($pf, $cur, false); // compacto: los días pueden traer miles de eventos
        }

        // ---- 4) Estado: podar índice viejo y guardar ----
        $cutIdx = time() - 7 * 86400;
        foreach ($index as $k => $v) {
            $ts = (int)substr($k, (int)strrpos($k, '|') + 1);
            if ($ts < $cutIdx) unset($index[$k]);
        }
        $state['proxy_index'] = $index;
        $state['v'] = est_data_version();
        est_write_json(est_state_path(), $state);

        // ---- 5) Limpiar días viejos ----
        $cut = strtotime('-45 days');
        foreach (glob(est_base_dir() . '/daily/*.json') ?: [] as $df) {
            $base = basename($df, '.json');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $base) && strtotime($base) < $cut) @unlink($df);
        }

        return ['ok' => true, 'new' => $written, 'ngx' => count($ngxEvents), 'ice' => count($iceEvents), 'idx' => count($index), 'rebuild' => (bool)$rebuild];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Pico de conexiones simultáneas a partir de intervalos [inicio, duración]. */
function est_peak_concurrent(array $intervals) {
    $pts = [];
    foreach ($intervals as $iv) {
        $s = (int)($iv[0] ?? 0);
        if ($s <= 0) continue;
        $d = (int)($iv[1] ?? 0);
        $e = $s + ($d > 0 ? $d : 1);
        $pts[] = [$s, 1];
        $pts[] = [$e, -1];
    }
    if (!$pts) return 0;
    usort($pts, fn($a, $b) => $a[0] === $b[0] ? ($a[1] - $b[1]) : ($a[0] - $b[0]));
    $cur = 0; $peak = 0;
    foreach ($pts as $p) { $cur += $p[1]; if ($cur > $peak) $peak = $cur; }
    return $peak;
}

/** Suma un rango de días para un mount. $days = lista de 'Y-m-d' (en tz de la radio). */
function est_sum_days($mount, $days) {
    $agg = ['total' => 0, 'uniques' => [], 'countries' => [], 'devices' => [], 'dur_total' => 0, 'intervals' => []];
    foreach ($days as $day) {
        $f = est_day_path($day);
        if (!is_file($f)) continue;
        $j = est_read_json($f, []);
        if (empty($j[$mount]) || !is_array($j[$mount])) continue;
        foreach ($j[$mount] as $ev) {
            $h  = (string)($ev['h'] ?? '');
            if ($h === '') continue;
            $cc = (string)($ev['cc'] ?? '??');
            $dv = (int)($ev['dv'] ?? 4);
            $dur = (int)($ev['dur'] ?? 0);
            $ts  = (int)($ev['ts'] ?? 0);
            $agg['total']++;
            $agg['dur_total'] += $dur;
            $agg['uniques'][$h] = true;
            if (!isset($agg['countries'][$cc])) $agg['countries'][$cc] = ['c' => 0, 'u' => []];
            $agg['countries'][$cc]['c']++;
            $agg['countries'][$cc]['u'][$h] = true;
            if (!isset($agg['devices'][$dv])) $agg['devices'][$dv] = ['c' => 0, 'u' => []];
            $agg['devices'][$dv]['c']++;
            $agg['devices'][$dv]['u'][$h] = true;
            $agg['intervals'][] = [$ts, $dur];
        }
    }
    $agg['pico'] = est_peak_concurrent($agg['intervals']);
    return $agg;
}

/** Payload completo de períodos para la vista de una radio. */
function est_periods_payload($mount) {
    $tz = est_tz_for_mount($mount);
    try { $now = new DateTime('now', new DateTimeZone($tz)); }
    catch (\Throwable $x) { $tz = 'America/Costa_Rica'; $now = new DateTime('now', new DateTimeZone($tz)); }
    $today = $now->format('Y-m-d');

    $daysOf = function ($n) use ($now) {
        $out = [];
        $c = clone $now;
        for ($i = 0; $i < $n; $i++) { $out[] = $c->format('Y-m-d'); $c->modify('-1 day'); }
        return $out;
    };

    $build = function ($days) use ($mount) {
        $a = est_sum_days($mount, $days);
        $paises = [];
        foreach ($a['countries'] as $cc => $v) {
            $paises[] = ['cc' => $cc, 'nombre' => est_country_es($cc), 'c' => $v['c'], 'u' => count($v['u'])];
        }
        usort($paises, fn($x, $y) => $y['c'] <=> $x['c']);
        $labels = [0 => 'Móvil', 1 => 'Tablet', 2 => 'Escritorio', 3 => 'Aplicación / Reproductor', 4 => 'Otro'];
        $dispositivos = [];
        foreach ($a['devices'] as $dv => $v) {
            $dispositivos[] = ['dv' => $dv, 'nombre' => $labels[$dv] ?? 'Otro', 'c' => $v['c'], 'u' => count($v['u'])];
        }
        usort($dispositivos, fn($x, $y) => $y['c'] <=> $x['c']);
        return [
            'total'         => $a['total'],
            'unicos'        => count($a['uniques']),
            'pico'          => $a['pico'],
            'dur_total'     => $a['dur_total'],
            'dur_media'     => $a['total'] > 0 ? (int)round($a['dur_total'] / $a['total']) : 0,
            'paises'        => $paises,
            'dispositivos'  => $dispositivos,
        ];
    };

    $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
    return [
        'mount' => $mount,
        'as_of' => $now->format('Y-m-d H:i:s'),
        'periodos' => [
            'hoy'    => $build([$today]),
            'ayer'   => $build([$yesterday]),
            'semana' => $build($daysOf(7)),
            'mes'    => $build($daysOf(30)),
        ],
    ];
}
}
