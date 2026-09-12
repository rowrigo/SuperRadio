<?php
/**
 * Estadísticas de oyentes por radio (países / dispositivos / conexiones).
 *
 * Fuente de datos: access logs de nginx (remote_addr REAL del oyente + User-Agent)
 * para peticiones GET 2xx a un mount de audio. El ingest es idempotente y se apoya en:
 *   - tail por log (último timestamp de evento ya ingerido) para no duplicar tras rotaciones
 *   - cursor (ino+size) para no releer el log actual entero en cada llamada
 *
 * Almacenamiento (FUERA del webroot, /var/media/radios/_listener_stats):
 *   daily/YYYY-MM-DD.json  -> { "<mount>": [ {h,cc,dv,ts} ... ] }   (h = hash de ip+ua, sin IPs crudas)
 *   geo_cache.json         -> { hash_sha1_de_IP: {cc, t} }          (sin IPs crudas)
 *   state.json             -> tail/pos por log
 *   ingest.lock            -> exclusión mutua del ingest
 *
 * Privacidad: NO se guardan IPs en claro; solo un hash (h) para deduplicar oyentes únicos,
 * el código de país (cc) y el tipo de dispositivo (dv).
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

function est_read_json($path, $def = []) {
    if (!is_file($path)) return $def;
    $j = @json_decode(@file_get_contents($path), true);
    return is_array($j) ? $j : $def;
}

function est_write_json($path, $data) {
    $ok = @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
        'AR'=>'Argentina','PY'=>'Paraguay','UY'=>'Uruguay','--'=>'Local / Red privada','??'=>'Desconocido',
    ];
    return $map[$cc] ?? $cc;
}

/** Clasificador de dispositivo a partir del User-Agent. Devuelve [codigo, etiqueta]. */
function est_device($ua) {
    $u = strtolower((string)$ua);
    if (strpos($u, 'ipad') !== false || strpos($u, 'tablet') !== false) return [1, 'Tablet'];
    if (strpos($u, 'iphone') !== false || strpos($u, 'ipod') !== false) return [0, 'Móvil (iOS)'];
    if (strpos($u, 'android') !== false) {
        // Distinguir móvil vs tablet Android por la marca "Mobile" o densidad
        return (strpos($u, 'mobile') !== false || strpos($u, 'phone') !== false) ? [0, 'Móvil (Android)'] : [1, 'Tablet'];
    }
    if (preg_match('/(windows phone|windows mobile|blackberry|opera mini|iemobile|symbian)/', $u)) return [0, 'Móvil'];
    if (strpos($u, 'windows nt') !== false || strpos($u, 'macintosh') !== false || strpos($u, 'x11') !== false || strpos($u, 'linux') !== false) {
        // Reproductores de escritorio (VLC, iTunes, Winamp...) vs navegador de escritorio
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
        // UA sin pinta de navegador/reproductor conocido
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
    // ip-api: máximo 100 por POST /batch
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
        if (!is_array($res)) { usleep(200000); continue; } // reintento simple (una vez por chunk)
        foreach ($res as $row) {
            if (!is_array($row) || ($row['status'] ?? '') !== 'success') continue;
            $q = (string)($row['query'] ?? '');
            if (!isset($todo[$q])) continue;
            $cc = strtoupper(substr((string)($row['countryCode'] ?? ''), 0, 2));
            $ipsNeeded[$q] = $cc !== '' ? $cc : '??';
            $gcache[$todo[$q]] = ['cc' => $cc !== '' ? $cc : '??', 't' => $now];
        }
    }
    // Las que no se pudieron resolver -> desconocidas
    foreach ($ipsNeeded as $ip => $cc) {
        if ($cc === 1) { $ipsNeeded[$ip] = '??'; }
    }
    est_write_json(est_geo_path(), $gcache);
}

/** Parsea una línea combined de nginx. Devuelve [ip, ua, ts] o null. */
function est_parse_line($line) {
    if (!preg_match('/^(\S+) - - \[([^\]]+)\] "([A-Z]+) (\S+)[^"]*" (\d{3}) \S+ "[^"]*" "([^"]*)"/', $line, $m)) return null;
    $method = $m[3];
    if ($method !== 'GET') return null;
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
    return ['mount' => strtolower($pm[1]), 'ip' => $m[1], 'ua' => $ua, 'ts' => $dt->getTimestamp()];
}

/** Consume un stream de líneas; devuelve eventos nuevos (respetando tail por log) y actualiza $tailRef. */
function est_consume_stream($handle, $mounts, $tail, &$tailRef, $events) {
    $skipAll = ($tail === null);
    while (($line = fgets($handle)) !== false) {
        $r = est_parse_line($line);
        if (!$r) continue;
        if (!isset($mounts[$r['mount']])) continue;
        if (!$skipAll && $r['ts'] <= $tail) continue; // ya ingerido (evita duplicados en rotaciones)
        if ($tailRef === null || $r['ts'] > $tailRef) $tailRef = $r['ts'];
        $events[] = $r;
    }
    return $events;
}

/**
 * Ingest principal: lee los logs de nginx (actuales + rotados) y acumula eventos por día.
 * Idempotente y seguro de ejecutar en paralelo (flock).
 */
function est_ingest_run() {
    $lock = fopen(est_lock_path(), 'c');
    if (!$lock) return ['ok' => false, 'error' => 'no lock file'];
    $got = false;
    for ($i = 0; $i < 100; $i++) { // espera hasta ~10 s
        if (flock($lock, LOCK_EX | LOCK_NB)) { $got = true; break; }
        usleep(100000);
    }
    if (!$got) return ['ok' => false, 'error' => 'busy'];

    try {
        $mounts = est_mounts_map();
        if (empty($mounts)) return ['ok' => false, 'error' => 'sin radios'];
        $state = est_read_json(est_state_path(), ['tail' => [], 'pos' => [], 'rot_seen' => []]);
        $tails = isset($state['tail']) && is_array($state['tail']) ? $state['tail'] : [];
        $pos   = isset($state['pos']) && is_array($state['pos']) ? $state['pos'] : [];
        $rotSeen = isset($state['rot_seen']) && is_array($state['rot_seen']) ? $state['rot_seen'] : [];
        $events = [];
        $newTails = [];
        $logs = ['/var/log/nginx/access.log', '/var/log/nginx/radiopanel_ssl_access.log', '/var/log/nginx/radiopanel_access.log'];

        foreach ($logs as $p) {
            if (!is_file($p)) continue;
            $tail = $tails[$p] ?? null;
            $tailRef = $tail;
            // 1) Rotados (de más viejo a más nuevo: .14.gz ... .1).
            //    Solo cambian cuando logrotate los renombra (~1 vez/día): si el mtime
            //    del más reciente (.1) no cambió desde la última pasada, se omiten.
            $mt1 = @filemtime("{$p}.1");
            if ($mt1 !== false && (int)($rotSeen[$p] ?? 0) === (int)$mt1) {
                $rotSeen[$p] = (int)$mt1; // sin cambios
            } else {
                for ($n = 40; $n >= 1; $n--) {
                    $f = "{$p}.{$n}";
                    $gz = $f . '.gz';
                    if (is_file($gz)) {
                        $h = @gzopen($gz, 'rb');
                        if ($h) { $events = est_consume_stream($h, $mounts, $tail, $tailRef, $events); gzclose($h); }
                    } elseif (is_file($f)) {
                        $h = @fopen($f, 'rb');
                        if ($h) { $events = est_consume_stream($h, $mounts, $tail, $tailRef, $events); fclose($h); }
                    }
                }
                if ($mt1 !== false) $rotSeen[$p] = (int)$mt1;
            }
            // 2) Log actual -> incremental por cursor (ino+size)
            $st = @stat($p);
            if ($st === false) continue;
            $ino = (int)$st['ino'];
            $size = (int)$st['size'];
            $cur = $pos[$p] ?? null;
            $offset = 0;
            if (is_array($cur) && (int)($cur['ino'] ?? 0) === $ino && isset($cur['size']) && (int)$cur['size'] <= $size) {
                $offset = (int)$cur['size'];
            }
            $h = @fopen($p, 'rb');
            if (!$h) continue;
            if ($offset > 0) fseek($h, $offset);
            $events = est_consume_stream($h, $mounts, $tail, $tailRef, $events);
            fclose($h);
            $newTails[$p] = $tailRef;
            $pos[$p] = ['ino' => $ino, 'size' => $size, 'at' => time()];
        }

        $newCount = count($events);
        if ($newCount > 0) {
            // Resolver países (solo IPs públicas; en memoria + caché por hash)
            $ips = [];
            foreach ($events as $e) {
                if (est_ip_is_local($e['ip'])) continue;
                $ips[$e['ip']] = 1; // marcador "pendiente"
            }
            if (!empty($ips)) est_geo_lookup_batch($ips);
            // Escribir eventos en su día local (zona horaria de la radio)
            $byDay = [];
            foreach ($events as $e) {
                $tz = est_tz_for_mount($e['mount']);
                try { $d = (new DateTime('@' . $e['ts']))->setTimezone(new DateTimeZone($tz))->format('Y-m-d'); }
                catch (\Throwable $x) { $d = gmdate('Y-m-d', $e['ts']); }
                $cc = '??';
                if (!est_ip_is_local($e['ip'])) { $cc = $ips[$e['ip']] ?? '??'; }
                else { $cc = '--'; }
                list($dv) = est_device($e['ua']);
                $h = substr(sha1($e['ip'] . '|' . $e['ua']), 0, 16);
                $byDay[$d][$e['mount']][] = ['h' => $h, 'cc' => $cc, 'dv' => $dv, 'ts' => $e['ts']];
            }
            foreach ($byDay as $day => $mountsNew) {
                $pf = est_day_path($day);
                $cur = est_read_json($pf, []);
                foreach ($mountsNew as $mount => $evs) {
                    if (!isset($cur[$mount]) || !is_array($cur[$mount])) $cur[$mount] = [];
                    $cur[$mount] = array_merge($cur[$mount], $evs);
                }
                est_write_json($pf, $cur);
            }
        }

        // Guardar estado y limpiar días viejos
        $state['tail'] = array_merge($tails, $newTails);
        $state['pos'] = $pos;
        $state['rot_seen'] = $rotSeen;
        est_write_json(est_state_path(), $state);

        $cut = strtotime('-45 days');
        foreach (glob(est_base_dir() . '/daily/*.json') ?: [] as $df) {
            $base = basename($df, '.json');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $base) && strtotime($base) < $cut) @unlink($df);
        }
        return ['ok' => true, 'new' => $newCount];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Suma un rango de días para un mount. $days = lista de 'Y-m-d' (en tz de la radio). */
function est_sum_days($mount, $days) {
    $agg = ['total' => 0, 'uniques' => [], 'countries' => [], 'devices' => []];
    foreach ($days as $day) {
        $f = est_day_path($day);
        if (!is_file($f)) continue;
        $j = est_read_json($f, []);
        if (empty($j[$mount]) || !is_array($j[$mount])) continue;
        foreach ($j[$mount] as $ev) {
            $h  = (string)($ev['h'] ?? '');
            $cc = (string)($ev['cc'] ?? '??');
            $dv = (int)($ev['dv'] ?? 4);
            if ($h === '') continue;
            $agg['total']++;
            $agg['uniques'][$h] = true;
            if (!isset($agg['countries'][$cc])) $agg['countries'][$cc] = ['c' => 0, 'u' => []];
            $agg['countries'][$cc]['c']++;
            $agg['countries'][$cc]['u'][$h] = true;
            if (!isset($agg['devices'][$dv])) $agg['devices'][$dv] = ['c' => 0, 'u' => []];
            $agg['devices'][$dv]['c']++;
            $agg['devices'][$dv]['u'][$h] = true;
        }
    }
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
        for ($i = 0; $i < $n; $i++) {
            $out[] = $c->format('Y-m-d');
            $c->modify('-1 day');
        }
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
        return ['total' => $a['total'], 'unicos' => count($a['uniques']), 'paises' => $paises, 'dispositivos' => $dispositivos];
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
