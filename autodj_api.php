<?php
if (function_exists('opcache_reset')) { @opcache_reset(); }
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
session_start();
require_once __DIR__ . '/config.php';

// =============================================================
// 1) ACTION + WHITELIST (ENDPOINTS PÚBLICOS SIN LOGIN):
//    Los reproductores externos (web players personalizados, widgets)
//    consultan carátula / historial sin credenciales. Permitirlos SIEMPRE.
// =============================================================
$action = strtolower(trim((string)($_REQUEST['action'] ?? ($_POST['action'] ?? ($_GET['action'] ?? '')))));
$public_actions = ['get_now_playing', 'serve_default_cover', 'serve_cached_cover', 'serve_page_logo', 'serve_page_bg', 'serve_staff_photo', 'get_page_config', 'stats'];
$force_public_ok = in_array($action, $public_actions, true);

// =============================================================
// 2) RESOLVER RADIO + MOUNT + PATHS (necesarios en endpoints públicos):
//    Movemos aquí toda la lógica que estaba DEBAJO del bloque auth.
// =============================================================
$db = file_exists(DB_FILE) ? json_decode(file_get_contents(DB_FILE), true) : [];
$sess_radio_id = $_SESSION['radio_id'] ?? '';
$mount_param = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $_REQUEST['mount'] ?? '')));
$radio = null;
$pg_radio_key = '';
$pg_mount_ok = false; // true si el mount del REQUEST matcheó una radio (no el fallback)
if (!empty($mount_param)) {
    foreach ($db['radios'] ?? [] as $k => $r) {
        $m_clean = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $r['mountpoint'] ?? '')));
        if ($m_clean === $mount_param || $k === $mount_param || $k === 'radio_' . $mount_param || $k === 'rad_' . $mount_param) {
            $radio = $r;
            $pg_radio_key = (string)$k;
            $pg_mount_ok = true;
            break;
        }
    }
}
if (!$radio && !empty($sess_radio_id) && !empty($db['radios'][$sess_radio_id])) {
    $radio = $db['radios'][$sess_radio_id];
    $pg_radio_key = (string)$sess_radio_id;
}
if (!$radio && !empty($db['radios'])) {
    $first_key = array_key_first($db['radios']);
    $radio = $db['radios'][$first_key];
    $pg_radio_key = (string)$first_key;
}
if (!$radio) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Radio no encontrada']);
    exit;
}
$mount = trim($radio['mountpoint'], '/');
$encoder_pass = !empty($radio['encoder_pass_encrypted']) ? decrypt_pass($radio['encoder_pass_encrypted']) : ($radio['encoder_pass'] ?? '');
$base_dir = "/var/media/radios/{$mount}";
$data_file = "{$base_dir}/programacion.json";
$cache_file = "{$base_dir}/duration_cache.json";
$pid_file = "{$base_dir}/autodj.pid";
$liq_file = "{$base_dir}/autodj.liq";
if (!is_dir($base_dir)) @mkdir($base_dir, 0775, true);
$default_data = [
    'timezone'         => 'America/Costa_Rica',
    'default_playlist' => 'general',
    'playlists'        => [
        'general' => ['tipo' => 'carpetas', 'items' => []]
    ],
    'schedule'         => [],
    'ads'              => [],
    'crossfade'        => ['fade_in' => 0, 'fade_out' => 0],
    // Carpeta de los clips de la hora (los usa el item "@HORA@" de la playlist).
    'hora_folder'      => 'HORAS',
    // Carpetas cuyo NOMBRE no se muestra en el reproductor: en su lugar se
    // manda el nombre de la emisora (panel/web y stream).
    'hide_title_folders' => []
];
$GLOBALS['pid_file'] = $pid_file;

// =============================================================
// 3) AUTENTICACIÓN (ahora que ya tenemos radio resuelta):
// - Cliente logueado → OK
// - Superadmin logueado en superradio.php → OK
// - SOLO HAY 1 RADIO EN database.json → PÚBLICO
// - WHITELIST action pública (carátulas/historial) → OK aunque no login
// =============================================================
$auth_db = $db;
$auth_only_one_radio = is_array($auth_db['radios'] ?? null) && count($auth_db['radios']) === 1;
$auth_ok = false;
if (!empty($_SESSION['cliente_auth'])) $auth_ok = true;
if (!empty($_SESSION['superadmin_auth'])) $auth_ok = true;
if ($auth_only_one_radio) $auth_ok = true;
if ($force_public_ok) $auth_ok = true;
if (!$auth_ok) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado', 'hint_login_url' => 'index.php'], JSON_UNESCAPED_UNICODE);
    exit;
}
unset($auth_db, $auth_only_one_radio, $auth_ok, $force_public_ok);
// =============================================================
// HELPERS DE CODIFICACIÓN Y NORMALIZACIÓN DE NOMBRES (cross-platform)
// - Windows/Laragon: scandir() devuelve CP1252 / ISO-8859-1
// - Linux/VPS: scandir() devuelve UTF-8
// - Normaliza todo a UTF-8 válido y quita acentos/ñ para comparaciones seguras
// =============================================================
if (!function_exists('to_utf8_safe')) {
function to_utf8_safe($str) {
    if ($str === null || $str === '') return '';
    if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
        $enc = @mb_detect_encoding($str, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], true);
        if ($enc && strtoupper($enc) !== 'UTF-8') {
            $str = @mb_convert_encoding($str, 'UTF-8', $enc);
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

// Buscar un elemento dentro de items (playlists tipo carpetas/archivos) que
// coincida con un nombre físico, usando normalización segura.
// Devuelve el índice si lo encuentra, o -1.
function find_physical_match($name_physical, $items_array) {
    $norm_ph = normalize_name($name_physical);
    if ($norm_ph === '') return -1;
    foreach ($items_array as $idx => $it) {
        if (is_string($it)) {
            $norm_it = normalize_name($it);
            if ($norm_it !== '' && $norm_it === $norm_ph) return $idx;
            // Si es un path con barras (carpeta/archivo.mp3), chequear última parte
            if (strpos($it, '/') !== false || strpos($it, '\\') !== false) {
                $parts = preg_split('/[\\\\\\/]/', $it);
                $last = end($parts);
                if (normalize_name($last) === $norm_ph) return $idx;
            }
        }
    }
    return -1;
}

function generate_liq_code($app_data, $def_pl_name, $base_dir, $radio, $mount, $encoder_pass, &$valid_playlists) {
    // ===== OPCACHE INVALIDATE INDESTRUCTIBLE =====
    // Garantiza que generate_liq_code() use siempre el código más reciente,
    // incluso si PHP-FPM opcache guardó una versión vieja (modo directa
    // sin la línea dj_harbor = input.harbor( → 404 stream / colisión PID).
    if (function_exists('opcache_reset'))       { @opcache_reset(); }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(__FILE__, true);
        @opcache_invalidate(__DIR__ . '/config.php', true);
        @opcache_invalidate(__DIR__ . '/next_song.php', true);
    }
    clearstatcache(true);
    // ===== FIN OPCACHE INVALIDATE =====

    $liq_code  = "set(\"log.file.path\", \"{$base_dir}/liquidsoap.log\")\n";
    $liq_code .= "set(\"log.level\", 3)\n";
    $liq_code .= "set(\"server.socket\", true)\n";
    $liq_code .= "set(\"server.socket.path\", \"{$base_dir}/liq.sock\")\n";
    $liq_code .= "set(\"server.socket.permissions\", 0o660)\n";
    $liq_code .= "set(\"server.timeout\", 20)\n\n";

    $GLOBALS['__log_fns'] = [];
    $GLOBALS['__liq_warnings'] = [];
    $valid_playlists = [];

    $modo = !empty($radio['modo_radio']) && in_array($radio['modo_radio'], ['autodj', 'directa'], true) ? $radio['modo_radio'] : 'autodj';

    foreach (($app_data['playlists'] ?? []) as $p_name => $p_info) {
        $valid_playlists[$p_name] = true;
    }
    if (!isset($valid_playlists[$def_pl_name])) {
        $valid_playlists[$def_pl_name] = true;
    }

    $tz = !empty($app_data['timezone']) ? $app_data['timezone'] : 'America/Costa_Rica';

    // ===== CROSSFADE ENTRE PISTAS (estilo Centova) =====
    // Funde el final de la pista actual (fade_out) con el inicio de la siguiente
    // (fade_in) para quitar espacios entre canciones. Cada valor en segundos
    // (0.5, 1, 1.5...); 0 en AMBOS = desactivado (transición normal sin fundido).
    // Liquidsoap bufferiza max(fade_in,fade_out) s de cada lado para el cruce.
    $cf_cfg = (is_array($app_data['crossfade'] ?? null)) ? $app_data['crossfade'] : ['fade_in' => 0, 'fade_out' => 0];
    $cf_fade_in  = round(max(0, min(5.0, (float)($cf_cfg['fade_in']  ?? 0))) * 2) / 2;
    $cf_fade_out = round(max(0, min(5.0, (float)($cf_cfg['fade_out'] ?? 0))) * 2) / 2;
    $cf_dur = max($cf_fade_in, $cf_fade_out);
    $cf_on  = ($cf_dur > 0);
    $cf_fi_s = number_format($cf_fade_in,  1, '.', '');
    $cf_fo_s = number_format($cf_fade_out, 1, '.', '');
    $cf_du_s = number_format($cf_dur,      1, '.', '');

    $php_bin = '/usr/bin/php';
    $ns_php = __DIR__ . '/next_song.php';
    $liq_ns_php = str_replace('\\', '/', $ns_php);
    $ns_cmd_normal = "{$php_bin} {$liq_ns_php} --mount={$mount} --mode=insertado";
    $silence = "/usr/share/icecast2/web/silence.mp3";
    // Relleno casi instantáneo (0.16 s) para request.dynamic que pre-bufferiza:
    // evita que un "silencio largo" o un archivo de hora viejo retrase/ensucie
    // el anuncio/la programación al activarse la ventana.
    $short_silence = "{$base_dir}/.nextsong_state/short_silence.mp3";
    $blank_silence = is_file($short_silence) ? $short_silence : $silence;

    // Puerto DJ (Input Harbor) — mini servidor Icecast interno para BUTT/OBS/RadioBOSS
    $dj_port = !empty($radio['dj_port']) ? (int)$radio['dj_port'] : 8005;

    if ($modo === 'directa') {
        // ============================================================
        //  MODO DIRECTA [SCR-v3-FINAL-20260827-OPCACHE-MARK-1234567]
        //  2 NIVELES DE FONDO DISPONIBLES (blank() nativo / fondo_oculto admin)
        // ============================================================
        $fondo_oculto = !empty($radio['directa_fondo_oculto_path']) ? trim((string)$radio['directa_fondo_oculto_path']) : '';
        $fondo_tipo = 'blank';
        $fondo_mp3_path = '';
        $fondo_carpeta_path = '';
        if ($fondo_oculto !== '') {
            if (@is_file($fondo_oculto)) {
                $ext = strtolower(pathinfo($fondo_oculto, PATHINFO_EXTENSION));
                if (in_array($ext, ['mp3','aac','ogg','m4a','flac','wav'], true)) {
                    $fondo_tipo = 'mp3_file';
                    $fondo_mp3_path = $fondo_oculto;
                }
            } elseif (@is_dir($fondo_oculto)) {
                $fondo_tipo = 'folder';
                $fondo_carpeta_path = $fondo_oculto;
            }
        }

        $liq_code .= "# ============================================================\n";
        $liq_code .= "#  MODO DIRECTA\n";
        $liq_code .= "#  Fondo configurado (admin): tipo={$fondo_tipo}\n";
        if ($fondo_tipo === 'mp3_file')  $liq_code .= "#   · Fondo MP3 file: {$fondo_mp3_path}\n";
        if ($fondo_tipo === 'folder')    $liq_code .= "#   · Fondo carpeta:  {$fondo_carpeta_path}\n";
        if ($fondo_tipo === 'blank')     $liq_code .= "#   · Fondo default:  blank() nativo indestructible sin archivos\n";
        $liq_code .= "# ============================================================\n\n";

        // DJ vivo (SIN mksafe outer!)
        $liq_code .= "# Paso 1) DJ vivo ENTRADA (SIN mksafe outer, igual que Autodj)\n";
        $liq_code .= "set(\"harbor.bind_addrs\", [\"0.0.0.0\"])\n";
        $liq_code .= "dj_harbor = input.harbor(\n";
        $liq_code .= "  \"/live\",\n";
        $liq_code .= "  port={$dj_port},\n";
        $liq_code .= "  user=\"source\",\n";
        $liq_code .= "  password=\"{$encoder_pass}\",\n";
        $liq_code .= "  icy=true,\n";
        $liq_code .= "  buffer=2.0\n";
        $liq_code .= ")\n\n";

        // FONDO (nivel 2, lo que suena si DJ NO conecta)
        $liq_code .= "# Paso 2) FONDO de fondo (según config admin del radio)\n";
        if ($fondo_tipo === 'mp3_file') {
            // Loop único archivo MP3 sin parar
            $liq_code .= "# Admin puso 1 solo MP3 → LOOP INFINITO request.dynamic\n";
            $liq_code .= "def ns_get_fondo_mp3()\n";
            $liq_code .= "  request.create(" . json_encode($fondo_mp3_path, JSON_UNESCAPED_UNICODE) . ")\n";
            $liq_code .= "end\n";
            $liq_code .= "dyn_fondo = request.dynamic(id=\"dyn_fondo_oculto\", timeout=10.0, ns_get_fondo_mp3)\n";
            $liq_code .= "directa_fondo_stream = mksafe(dyn_fondo)\n\n";
        } elseif ($fondo_tipo === 'folder') {
            // Carpeta con MP3s: ls *.mp3 + random pick en cada request
            // Usamos bash para listar archivos MP3 dentro de la carpeta (y subcarpetas)
            $fondo_carpeta_path_safe = addslashes($fondo_carpeta_path);
            $liq_code .= "# Admin puso CARPETA → ls *.mp3 + pick aleatorio en cada canción\n";
            $liq_code .= "def ns_get_fondo_folder()\n";
            $liq_code .= "  cmd = \"find \\\"{$fondo_carpeta_path_safe}\\\" -type f \\\\( -iname '*.mp3' -o -iname '*.aac' -o -iname '*.ogg' -o -iname '*.m4a' \\\\) 2>/dev/null | shuf -n 1\"\n";
            $liq_code .= "  lines = get_process_lines(cmd)\n";
            $liq_code .= "  if list.length(lines) > 0 then\n";
            $liq_code .= "    f = string.trim(list.hd(lines))\n";
            $liq_code .= "    if (f != \"\" and file.exists(f)) then\n";
            $liq_code .= "      request.create(f)\n";
            $liq_code .= "    else\n";
            $liq_code .= "      request.create(\"/usr/share/icecast2/web/silence.mp3\")\n";
            $liq_code .= "    end\n";
            $liq_code .= "  else\n";
            $liq_code .= "    request.create(\"/usr/share/icecast2/web/silence.mp3\")\n";
            $liq_code .= "  end\n";
            $liq_code .= "end\n";
            $liq_code .= "dyn_fondo = request.dynamic(id=\"dyn_fondo_oculto\", timeout=15.0, ns_get_fondo_folder)\n";
            $liq_code .= "directa_fondo_stream = mksafe(dyn_fondo)\n\n";
        } else {
            // DEFAULT INDESTRUCTIBLE: blank() nativo
            $liq_code .= "# Sin config admin → blank() nativo indestructible silencio infinito\n";
            $liq_code .= "directa_fondo_stream = mksafe(blank())\n\n";
        }

        $liq_code .= "# Paso 3) FALLBACK IDÉNTICO AUTODJ: [DJ, FONDO]\n";
        $liq_code .= "radio_stream = fallback(track_sensitive=false, [dj_harbor, directa_fondo_stream])\n\n";
        $liq_code .= "final_stream = radio_stream\n\n";
    } else {
        // ============================================================
        //  MODO AUTODJ (default, 24/7)
        //  FALLBACK 3 NIVELES DE SEGURIDAD (NUNCA stream colgado / silencio muerto):
        //    NIVEL 1: dj_harbor (DJ vivo conectado BUTT — SIN mksafe!)
        //    NIVEL 2: autodj_safe = mksafe(request.dynamic(next_song.php))
        //    NIVEL 3: safety_silence = single(silence.mp3) — ÚLTIMA RED SIEMPRE
        //  track_sensitive=false → cortes <1s sin esperar fin de canción
        // ============================================================
        $liq_code .= "# ============================================================\n";
        $liq_code .= "#  MODO AUTODJ (24/7) — compatibilidad 100% LS 2.0.2\n";
        $liq_code .= "#  Toda la inteligencia está en PHP (next_song.php).\n";
        $liq_code .= "#  Liquidsoap = REPRODUCTOR TONTO: pide canción → la reproduce.\n";
        $liq_code .= "#  FALLBACK 3 NIVELES: DJ_harbor > AutoDJ(mksafe) > silence.mp3\n";
        $liq_code .= "# ============================================================\n\n";

        $liq_code .= "# ÚLTIMA RED DE SEGURIDAD NIVEL 3: silence.mp3 SIEMPRE DISPONIBLE\n";
        $liq_code .= "# Si next_song.php falla o playlist general está vacía, NUNCA se rompe el stream.\n";
        $liq_code .= "safety_silence = single(\"{$silence}\")\n\n";

        $liq_code .= "# 1) AutoDJ (NIVEL 2): request.dynamic ÚNICO (modo=insertado / normal).\n";
        $liq_code .= "#    next_song.php SIEMPRE devuelve una canción válida o silence.mp3.\n";
        $liq_code .= "def ns_get_next()\n";
        $liq_code .= "  lines = get_process_lines(\"TZ=\\\"{$tz}\\\" {$ns_cmd_normal}\")\n";
        $liq_code .= "  if list.length(lines) > 0 then\n";
        $liq_code .= "    first_line = string.trim(list.hd(lines))\n";
        $liq_code .= "    if (first_line != \"\" and file.exists(first_line)) then\n";
        $liq_code .= "      ignore(log(label=\"NEXT_OK\", level=4, first_line))\n";
        $liq_code .= "      request.create(first_line)\n";
        $liq_code .= "    else\n";
        $liq_code .= "      ignore(log(label=\"NEXT_BAD\", level=2, \"ruta no válida: [\" ^ first_line ^ \"]\"))\n";
        $liq_code .= "      request.create(\"{$silence}\")\n";
        $liq_code .= "    end\n";
        $liq_code .= "  else\n";
        $liq_code .= "    ignore(log(label=\"NEXT_EMPTY\", level=2, \"next_song.php vacío → silencio\"))\n";
        $liq_code .= "    request.create(\"{$silence}\")\n";
        $liq_code .= "  end\n";
        $liq_code .= "end\n";
        $liq_code .= "dyn_source = request.dynamic(id=\"dyn_source\", timeout=30.0, ns_get_next)\n";

        // ============================================================
        //  MODO AUTODJ — METADATA AL STREAM = NOMBRE DEL ARCHIVO
        //  El stream debe enviar el nombre real del archivo (ej:
        //  "CRISTIAN CASTRO - ME ENAMORO") aunque los tags ID3 del mp3
        //  estén vacíos o mal. Liquidsoap SIEMPRE inyecta la clave
        //  "filename" (ruta completa) en la metadata de cada canción.
        //  silence.mp3 (última red de seguridad) se deja intacto.
        // ============================================================
        // ============================================================
        //  CARPETAS CON NOMBRE OCULTO EN EL REPRODUCTOR
        //  Para las pistas que salgan de estas carpetas NO se manda el nombre
        //  del archivo: se manda el nombre de la emisora. Los fragmentos se
        //  comparan contra la ruta completa ("filename") de la metadata.
        //  El item @HORA@ se materializa en <estado>/hora_cache/, así que si se
        //  oculta la carpeta de locuciones su anuncio queda oculto también.
        //  Requiere REINICIAR el AutoDJ (es código del script, no datos).
        // ============================================================
        $_htf_folders = (isset($app_data['hide_title_folders']) && is_array($app_data['hide_title_folders'])) ? $app_data['hide_title_folders'] : [];
        $_htf_frags   = [];
        foreach ($_htf_folders as $_htf_name) {
            if (!is_string($_htf_name)) continue;
            $_htf_name = trim($_htf_name);
            if ($_htf_name === '') continue;
            $_htf_frags['/' . $_htf_name . '/'] = true;
        }
        $_htf_voice = trim((string)($app_data['hora_folder'] ?? ''));
        if ($_htf_voice === '') $_htf_voice = 'HORAS';
        foreach (array_keys($_htf_frags) as $_htf_frag) {
            if (strcasecmp(trim($_htf_frag, '/'), $_htf_voice) === 0) { $_htf_frags['/hora_cache/'] = true; break; }
        }
        $_htf_station = trim((string)($radio['nombre_emisora'] ?? ''));
        if ($_htf_station === '') $_htf_station = (string)$mount;
        $_htf_quote = function ($s) { return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$s) . '"'; };
        $liq_code .= "# Carpetas con nombre oculto: se manda el nombre de la emisora en su lugar\n";
        $liq_code .= "ns_hide_fragments = [" . implode(', ', array_map($_htf_quote, array_keys($_htf_frags))) . "]\n";
        $liq_code .= "ns_station_title = " . $_htf_quote($_htf_station) . "\n\n";
        $liq_code .= <<<'LIQ_META'
# ---------- Título al stream = nombre del archivo (sin extensión) ----------
def ns_meta_get(m, key) =
  ret = ref("")
  list.iter(fun (kv) -> if fst(kv) == key then ret := snd(kv) end, m)
  !ret
end
# ---------- Versión para MÚSICA: SIEMPRE genera título desde el archivo ----------
# Igual que la de arriba, pero si el nombre no trae " - " (p. ej.
# "JAMIE WALTERS- HOLD ON.mp3", "TOTO-99.mp3" o "ALL THE MAN THAT I NEED.mp3")
# envía el nombre COMPLETO del archivo como título. Así los reproductores
# SIEMPRE muestran el nombre de la canción aunque el MP3 no traiga ID3.
# ¿La ruta cae en una carpeta con nombre oculto? (ns_hide_fragments se genera
# arriba a partir de la config "hide_title_folders" de la radio).
def ns_path_is_hidden(f) =
  hit = ref(false)
  list.iter(fun (frag) -> if string.contains(substring=frag, f) then hit := true end, ns_hide_fragments)
  hit()
end
def ns_meta_title_music(m) =
  f = ns_meta_get(m, "filename")
  if ns_path_is_hidden(f) then
    [("title", ns_station_title)]
  elsif f == "" or string.contains(substring="silence", f) then
    m
  else
    base = list.hd(list.rev(string.split(separator="/", f)))
    parts = string.split(separator="\\.", base)
    stem = if list.length(parts) > 1 then
      string.concat(separator=".", list.rev(list.tl(list.rev(parts))))
    else
      base
    end
    sp = string.split(separator=" - ", stem)
    if list.length(sp) >= 2 then
      [("artist", string.trim(list.hd(sp))),
       ("title", string.trim(string.concat(separator=" - ", list.tl(sp))))]
    else
      [("title", string.trim(stem))]
    end
  end
end

LIQ_META;
        $liq_code .= "#    mksafe() solo aquí: protege si next_song.php falla totalmente\n";
        $liq_code .= "#    (pero el nivel 3 safety_silence lo cubre de todas formas)\n";
        $liq_code .= "autodj_music = map_metadata(update=false, ns_meta_title_music, dyn_source)\n";
        if ($cf_on) {
            // Crossfade: funde cada par de pistas consecutivas (música, anuncios,
            // cortinillas... todo el flujo del AutoDJ) sin dejar huecos.
            $liq_code .= "autodj_music = crossfade(id=\"xf_autodj\", duration={$cf_du_s}, fade_in={$cf_fi_s}, fade_out={$cf_fo_s}, smart=false, autodj_music)\n";
        }
        $liq_code .= "autodj_safe = mksafe(autodj_music)\n\n";

        $liq_code .= "# 2) DJ vivo NIVEL 1 (input.harbor). ¡¡SIN mksafe()!! — clave arquitectónica:\n";
        $liq_code .= "#    si DJ NO está conectado → harbor FALLA (no emite nada, no silencio válido).\n";
        $liq_code .= "#    fallback() detecta la fuente 1 fallida y salta a la 2 (AutoDJ).\n";
        $liq_code .= "set(\"harbor.bind_addrs\", [\"0.0.0.0\"])\n";
        $liq_code .= "dj_harbor = input.harbor(\n";
        $liq_code .= "  \"/live\",\n";
        $liq_code .= "  port={$dj_port},\n";
        $liq_code .= "  user=\"source\",\n";
        $liq_code .= "  password=\"{$encoder_pass}\",\n";
        $liq_code .= "  icy=true,\n";
        $liq_code .= "  buffer=2.0\n";
        $liq_code .= ")\n\n";

        // ============================================================
        // CAPAS DE FUENTES (sin DJ; el DJ se antepone al final):
        //   autodj_layer = AutoDJ 24/7 (mksafe) + red de silencio.
        //   sched_gate   = PROGRAMACIÓN "inmediato": al iniciar el bloque
        //                  (ej. 13:00:00) CORTA la canción que suena y
        //                  arranca el bloque al instante; al terminar el
        //                  rango deja acabar la última canción del bloque.
        //   music_layer  = sched_gate (si hay bloques) o autodj_layer.
        //   radio_duck   = voz de hora (ducking 18%) sobre music_layer.
        //   final_stream = fallback([dj_harbor, ...]).
        // ============================================================
        $liq_code .= "# 3) Capa música base: programación inmediata (si aplica) sobre AutoDJ.\n";
        $liq_code .= "autodj_layer = fallback(track_sensitive=false, [autodj_safe, safety_silence])\n\n";

        // ---------- PROGRAMACIÓN MODE=IMMEDIATO (entrada exacta cortando) ----------
        $music_src_name = 'autodj_layer';
        $immediato = [];
        foreach (($app_data['schedule'] ?? []) as $sched) {
            if (($sched['mode'] ?? 'inmediato') !== 'inmediato') continue;
            $pl = $sched['playlist'] ?? null;
            if ($pl === null || $pl === '' || !isset($app_data['playlists'][$pl])) continue;
            $days = !empty($sched['days']) ? array_values(array_map('intval', (array)$sched['days'])) : [1,2,3,4,5,6,7];
            $sp = explode(':', (string)($sched['start'] ?? '00:00'));
            $ep = explode(':', (string)($sched['end'] ?? '23:59'));
            $smin = ((int)($sp[0] ?? 0) * 60) + (int)($sp[1] ?? 0);
            $emin = ((int)($ep[0] ?? 23) * 60) + (int)($ep[1] ?? 59);
            // Comparación en SEGUNDOS del día (el predicado usa s = n mod 86400).
            $smin = $smin * 60;
            $emin = $emin * 60;
            if ($smin < 0) $smin = 0; if ($smin > 86399) $smin = 86399;
            if ($emin < 0) $emin = 0; if ($emin > 86399) $emin = 86399;
            $immediato[] = ['days' => $days, 'smin' => $smin, 'emin' => $emin, 'wrap' => ($emin <= $smin)];
        }
        if (!empty($immediato)) {
            try {
                $tz_off_sched = (int)(new DateTime('now', new DateTimeZone($tz)))->getOffset();
            } catch (\Throwable $e) { $tz_off_sched = -21600; }
            $liq_code .= "# --- sched_gate: reloj LS (seg del día s, día ISO 1..7 d) ---\n";
            $liq_code .= "def ns_sched_activa() =\n";
            $liq_code .= "  n = int_of_float(time()) + {$tz_off_sched}\n";
            $liq_code .= "  s = n mod 86400\n";
            $liq_code .= "  d = ((n / 86400) + 3) mod 7 + 1\n";
            $cond_lines = [];
            foreach ($immediato as $e) {
                $day_ok = [];
                foreach ($e['days'] as $dd) { $dd = (int)$dd; if ($dd >= 1 && $dd <= 7) $day_ok[] = "d == {$dd}"; }
                if (empty($day_ok)) continue;
                $day_str = count($day_ok) > 1 ? '(' . implode(' or ', $day_ok) . ')' : $day_ok[0];
                $range_str = $e['wrap']
                    ? "(s >= {$e['smin']} or s < {$e['emin']})"
                    : "(s >= {$e['smin']} and s < {$e['emin']})";
                $cond_lines[] = "  (" . $day_str . ") and " . $range_str;
            }
            if (!empty($cond_lines)) {
                $liq_code .= implode("\n  or\n", $cond_lines) . "\n";
                $liq_code .= "end\n\n";
                $liq_code .= <<<'LQ'
def ns_pick_sched()
  lines = get_process_lines("__PHPBIN__ __NEXTSONG__ --mount=__MOUNT__ --mode=inmediato")
  if list.length(lines) > 0 then
    f = string.trim(list.hd(lines))
    if f != "" and f != "NO_INMEDIATO_ACTIVE" and file.exists(f) then
      ignore(log(label="SCHED", level=3, "OK programacion: " ^ f))
      request.create(f)
    else
      ignore(log(label="SCHED", level=2, "programacion no activa -> vuelve autodj"))
      request.create("__SILENCE__")
    end
  else
    ignore(log(label="SCHED", level=2, "programacion vacia -> autodj"))
    request.create("__SILENCE__")
  end
end
src_sched = request.dynamic(id="sched_dyn", timeout=30.0, ns_pick_sched)
src_sched = map_metadata(update=false, ns_meta_title_music, src_sched)
__XF_SCHED__
sched_gate = switch(track_sensitive=true, [(ns_sched_activa, src_sched)])
music_layer = fallback(track_sensitive=false, [sched_gate, autodj_layer])

LQ;
                $liq_code = str_replace(
                    ['__PHPBIN__', '__NEXTSONG__', '__MOUNT__', '__SILENCE__', '__XF_SCHED__'],
                    [
                        '/usr/bin/php',
                        $liq_ns_php,
                        $mount,
                        $blank_silence,
                        $cf_on ? "src_sched = crossfade(id=\"xf_sched\", duration={$cf_du_s}, fade_in={$cf_fi_s}, fade_out={$cf_fo_s}, smart=false, src_sched)\n" : ''
                    ],
                    $liq_code
                );
                $music_src_name = 'music_layer';
            }
        }
        // La capa final antepone el DJ vivo al AutoDJ (música + programación).
        $liq_code .= "final_stream = fallback(track_sensitive=false, [dj_harbor, " . $music_src_name . "])\n\n";

    }

    $liq_code .= "stream = final_stream\n\n";

    // ====== BITRATE MP3 (desde configuración emisora) ======
    //   El superadmin configura en superradio.php crear/editar cada radio: 64,96,128,192,256,320 kbps
    //   Si no existe o valor inválido → fallback por defecto 128 kbps.
    $bitrate_allowed_mp3 = [64, 96, 128, 192, 256, 320];
    $bitrate_out = (int)($radio['bitrate'] ?? 128);
    if (!in_array($bitrate_out, $bitrate_allowed_mp3, true)) $bitrate_out = 128;

    $liq_code .= "output.icecast(\n";
    $liq_code .= "  %mp3(bitrate=" . (int)$bitrate_out . "),\n";
    $liq_code .= "  host=\"127.0.0.1\",\n";
    $liq_code .= "  port=8000,\n";
    $liq_code .= "  password=\"{$encoder_pass}\",\n";
    $liq_code .= "  mount=\"/{$mount}\",\n";
    $liq_code .= "  stream\n";
    $liq_code .= ")\n";

    return $liq_code;
}

function find_liquidsoap_binary() {
    $paths = ['/usr/bin/liquidsoap', '/usr/local/bin/liquidsoap', '/snap/bin/liquidsoap', '/usr/bin/liquidsoap1.4', '/usr/bin/liquidsoap2'];
    foreach ($paths as $p) {
        if (@is_executable($p)) return $p;
    }
    $which = trim(@shell_exec('command -v liquidsoap 2>/dev/null'));
    if ($which && @is_executable($which)) return $which;
    return 'liquidsoap';
}

function run_liquidsoap_check($liq_bin, $liq_file) {
    $liq_bin_safe = escapeshellcmd($liq_bin);
    $liq_file_safe = escapeshellarg($liq_file);
    $stdout_file = tempnam(sys_get_temp_dir(), 'liq_chk_out_');
    $stderr_file = tempnam(sys_get_temp_dir(), 'liq_chk_err_');
    $cmd = "{$liq_bin_safe} --check {$liq_file_safe} >{$stdout_file} 2>{$stderr_file}";
    exec($cmd, $_dummy, $exit_code);
    $stdout = @file_get_contents($stdout_file);
    $stderr = @file_get_contents($stderr_file);
    @unlink($stdout_file);
    @unlink($stderr_file);
    return [
        'ok'        => $exit_code === 0,
        'exit_code' => $exit_code,
        'stdout'    => $stdout ? trim($stdout) : '',
        'stderr'    => $stderr ? trim($stderr) : '',
    ];
}

// =====================================================================
// systemd: el AutoDJ corre como unidad radiopanel-autodj@<mount>, es decir
// FUERA del cgroup de php8.1-fpm. Así un reinicio/actualización de php-fpm
// o de libc ya NO mata el stream (incidente 11-sep-2026) y además la unidad
// tiene Restart=always (watchdog) + arranque tras reboot.
// Si la unidad no está instalada, se mantiene el método clásico (exec).
// =====================================================================
if (!function_exists('autodj_unit_name')) {
function autodj_unit_name($mount) {
    $m = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$mount));
    return $m !== '' ? 'radiopanel-autodj@' . $m : '';
}
}

if (!function_exists('autodj_systemd_available')) {
function autodj_systemd_available() {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    if (is_file('/etc/systemd/system/radiopanel-autodj@.service') && is_executable('/usr/bin/systemctl')) {
        // Si systemd responde siempre devuelve una línea (p. ej. "not-found")
        $probe = @shell_exec('/usr/bin/systemctl show -p LoadState --value radiopanel-autodj@selftest 2>/dev/null');
        $ok = (trim((string)$probe) !== '');
    }
    return $ok;
}
}

if (!function_exists('autodj_systemd_mainpid')) {
function autodj_systemd_mainpid($unit) {
    if ($unit === '') return 0;
    return (int)trim((string)@shell_exec('/usr/bin/systemctl show -p MainPID --value ' . escapeshellcmd($unit) . ' 2>/dev/null'));
}
}

// Mata el proceso previo registrado en autodj.pid. Solo se usa en el arranque
// CLÁSICO (exec). En modo systemd NO debe llamarse: "systemctl restart" ya
// detiene el proceso anterior, y matarlo por PID haría que Restart=always
// reviviera la unidad con un KILL espurio (doble arranque, visto 12-sep-2026).
if (!function_exists('autodj_kill_stale_pid')) {
function autodj_kill_stale_pid($pid_file) {
    if (!file_exists($pid_file)) return;
    $old_pid = (int)trim(@file_get_contents($pid_file));
    if ($old_pid > 1) {
        @exec("kill -9 {$old_pid} 2>/dev/null");
        @unlink($pid_file);
        usleep(150000);
    }
}
}

function start_autodj($data_file, $default_data, $base_dir, $radio, $mount, $encoder_pass, $pid_file, $liq_file) {
    // ===== OPCACHE INVALIDATE FORZADO (EVITA STALE generate_liq_code) =====
    if (function_exists('opcache_reset'))       { @opcache_reset(); }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(__FILE__, true);
        @opcache_invalidate(__DIR__ . '/config.php', true);
        @opcache_invalidate(__DIR__ . '/next_song.php', true);
    }
    clearstatcache(true);
    // ===== FIN OPCACHE INVALIDATE =====

    $app_data = file_exists($data_file) ? (json_decode(file_get_contents($data_file), true) ?: $default_data) : $default_data;
    $def_pl_name = $app_data['default_playlist'] ?? 'general';
    $valid_playlists = [];
    $liq_code = generate_liq_code($app_data, $def_pl_name, $base_dir, $radio, $mount, $encoder_pass, $valid_playlists);
    $write_ok = @file_put_contents($liq_file, $liq_code);
    @chmod($liq_file, 0664);
    $tz = preg_replace('/[^a-zA-Z0-9_\/]/', '', $app_data['timezone'] ?? 'America/Costa_Rica');

    $warnings = $GLOBALS['__liq_warnings'] ?? [];

    // ====== VALIDACIÓN MANUAL ANTES DE LIQ_CHECK ======
    $modo = !empty($radio['modo_radio']) && in_array($radio['modo_radio'], ['autodj', 'directa'], true) ? $radio['modo_radio'] : 'autodj';

    // ====================================================================
    //  ¡¡BUG GRAVE FIX EXTENDIDO BUG #2 LS 2.0.2!! COMPROBAR PUERTO DJ Y
    //  EL PUERTO SIGUIENTE (N+1) PORQUE input.harbor(port=N) EN LIQUIDSOAP
    //  2.0.2 RESERVA 2 PUERTOS CONSECUTIVOS TCP N Y N+1, aunque solo se
    //  declare uno. Verificado en netstat ss: milimonradio(port=8005) → abrió
    //  8005 (fd=19) Y 8006 (fd=22) MISMO PID. Si otra radio usa 8006 como
    //  dj_port → bind() colisiona → muere inmediatamente → mount /pruebados
    //  nunca existe en Icecast (404 eterno) BUTT E-1 output 12.
    //
    //  Comprobamos: $dj_port_check (N) y $dj_port_check + 1 (N+1).
    // ====================================================================
    $dj_port_check = (int)($radio['dj_port'] ?? 0);
    if ($dj_port_check >= 1024 && $dj_port_check <= 65535) {
        $puertos_probar = [$dj_port_check, $dj_port_check + 1];
        $mi_pid_guardado = null;
        if (file_exists($pid_file)) $mi_pid_guardado = (int)trim((string)@file_get_contents($pid_file));

        foreach ($puertos_probar as $p) {
            $port_sys_in_use_raw = false;
            $port_sys_in_use_by_pid = null;
            $chk = @shell_exec("ss -ltnpH sport = :" . $p . " 2>/dev/null");
            if (!empty($chk)) {
                $port_sys_in_use_raw = true;
                if (preg_match('/pid=(\d+)/', (string)$chk, $mm)) $port_sys_in_use_by_pid = (int)$mm[1];
            } else {
                $chk2 = @shell_exec("fuser " . (int)$p . "/tcp 2>&1");
                if (!empty($chk2)) $port_sys_in_use_raw = true;
            }
            if ($port_sys_in_use_raw) {
                $ok_porque_es_nuestro_mismo_pid = false;
                if ($mi_pid_guardado > 0 && $port_sys_in_use_by_pid !== null && $port_sys_in_use_by_pid === $mi_pid_guardado) {
                    $ok_porque_es_nuestro_mismo_pid = true;
                }
                if (!$ok_porque_es_nuestro_mismo_pid) {
                    $pid_inv_info = $port_sys_in_use_by_pid ? "PID $port_sys_in_use_by_pid" : "otro proceso del sistema";
                    $cual_puerto = ($p === $dj_port_check) ? "el puerto DJ principal {$p}" : "el puerto RESERVADO extra {$p} (N+1 que Liquidsoap abre automaticamente con puerto {$dj_port_check})";
                    $recom_port = $dj_port_check;
                    if (($recom_port % 2) === 0) $recom_port++; else $recom_port += 2;
                    $warnings[] = "🚨 COLISIÓN PAREJA DE PUERTOS DJ BUG LS 2.0.2: detectado {$cual_puerto} ESTÁ ABIERTO EN EL KERNEL por {$pid_inv_info} (proceso vivo), NO es tu radio {$mount}. Liquidsoap 2.0.2 input.harbor(port=N) SIEMPRE reserva 2 puertos seguidos (N y N+1), por eso la colisión aunque tu puerto asignado parezca 'libre'. Liquidsoap se caería al arrancar con 'Address already in use in bind()' y la radio daría 404 / BUTT/RadioBOSS E-1 Output12. SOLUCIÓN (elige 1): (A) en superradio.php editar esta radio y cambiar el puerto DJ a uno PAR-IMPAR consecutivo como {$recom_port} (saltando el puerto que colisiona), o (B) mata el proceso PID usurpador desde SSH: kill -9 " . ($port_sys_in_use_by_pid ?? '_PORT_HOLDER_') . " luego apaga la radio y vuelve a iniciarla.";
                }
            }
        }
    }
    // END Puerto pareja (N, N+1) colisión pre-check BUG LS 2.0.2

    if ($modo === 'autodj') {
        // Comprobar Playlist general VACÍA → warning, pero arrancar con silence.mp3 (nivel 3 seguridad)
        $general = $app_data['playlists']['general'] ?? ['tipo' => 'carpetas', 'items' => []];
        $items = $general['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            $warnings[] = "⚠️ Playlist general está VACÍA. Se emitirá silence.mp3 hasta que subas música y agregues carpetas/archivos al Playlist general. (Usa Musicateca + Playlists).";
        } else {
            // Contar cuántos ítems de la playlist general existen FÍSICAMENTE en el disco
            $real_count = 0;
            $tipo = !empty($general['tipo']) && $general['tipo'] === 'archivos' ? 'archivos' : 'carpetas';
            foreach ($items as $it) {
                if (!is_string($it) || $it === '') continue;
                $full = rtrim($base_dir, '/') . '/' . ltrim($it, '/\\');
                if ($tipo === 'carpetas') {
                    if (is_dir($full)) $real_count++;
                } else {
                    if (is_file($full)) $real_count++;
                }
            }
            if ($real_count === 0) {
                $warnings[] = "⚠️ Playlist general tiene ítems definidos, pero NINGUNO existe físicamente en el disco (carpetas borradas o con nombres cambiados). Se emitirá silence.mp3 hasta que corrijas el Playlist general.";
            }
        }
        // Quota 0 en modo AUTODJ también es un warning
        $quota = (float)($radio['quota_mb'] ?? 0);
        if ($quota <= 0) {
            $warnings[] = "⚠️ Esta radio está en modo AutoDJ pero su cuota de espacio es 0 MB. Comunícate con el administrador para asignarte espacio de almacenamiento y subir música.";
        }
    }

    $liq_bin = find_liquidsoap_binary();
    $info = [
        'liq_bin' => $liq_bin,
        'liq_bin_exists' => @file_exists($liq_bin),
        'liq_bin_executable' => @is_executable($liq_bin),
        'liq_file' => $liq_file,
        'liq_wrote_bytes' => $write_ok,
        'base_dir_writable' => @is_writable($base_dir),
        'warnings' => $warnings,
    ];

    $errors = [];
    if (!$info['liq_bin_executable']) $errors[] = 'Liquidsoap no está instalado o no es ejecutable (instalar con: apt-get install -y liquidsoap)';
    if (!$write_ok) $errors[] = 'No se pudo escribir autodj.liq en ' . $liq_file . ' (revisar permisos chown/chmod de ' . $base_dir . ')';

    if ($info['liq_bin_executable'] && $write_ok) {
        $check = run_liquidsoap_check($liq_bin, $liq_file);
        $info['liq_check'] = $check;
        if (!$check['ok']) {
            $msg_line = '';
            if (preg_match('/At (.+?), line (\d+) char (\d+)/s', $check['stdout'], $mm)) {
                $msg_line = " (línea {$mm[2]})";
            }
            $errors[] = 'Error en autodj.liq' . $msg_line . ': liquidsoap --check rechazó el script. El AutoDJ NO ha arrancado. Revisa programaciones/anuncios o borra el último elemento guardado. Detalle: ' . trim($check['stdout']);
        }
    }

    if (!empty($errors)) {
        $info['errors'] = $errors;
        return ['pid' => null, 'running' => false, 'info' => $info];
    }

    $old_sock = "{$base_dir}/liq.sock";
    @unlink($old_sock);

    $stdout_file = "{$base_dir}/liquidsoap_stdout.log";
    $stderr_file = "{$base_dir}/liquidsoap_stderr.log";

    $pid = null;
    if (autodj_systemd_available()) {
        // TZ de la emisora para la unidad (EnvironmentFile=autodj.env)
        @file_put_contents("{$base_dir}/autodj.env", "TZ=" . $tz . "\n");
        @chmod("{$base_dir}/autodj.env", 0664);
        $unit = autodj_unit_name($mount);
        $sd_out = [];
        $sd_rc = 0;
        @exec('/usr/bin/systemctl restart ' . escapeshellcmd($unit) . ' 2>&1', $sd_out, $sd_rc);
        // Marca de "debe estar al aire": la usa el watchdog para arrancarla tras
        // un reboot o si la unidad se cayó (no usamos systemctl enable por polkit).
        @file_put_contents("{$base_dir}/.autodj_enabled", date('c') . "\n");
        @chmod("{$base_dir}/.autodj_enabled", 0664);
        $exit_code = $sd_rc;
        $out = $sd_out;
        $info['autodj_backend'] = 'systemd';
        $info['systemd_unit'] = $unit;
        $info['systemd_rc'] = $sd_rc;
        $info['systemd_out'] = $sd_out;
        usleep(700000);
        $pid = autodj_systemd_mainpid($unit);
        if ($pid <= 0) {
            // La unidad no arrancó → fallback al método clásico
            $info['systemd_fallback'] = true;
            autodj_kill_stale_pid($pid_file);
            $cmd = sprintf(
                "TZ=%s %s %s >%s 2>%s & echo $!",
                escapeshellarg($tz),
                escapeshellcmd($liq_bin),
                escapeshellarg($liq_file),
                escapeshellarg($stdout_file),
                escapeshellarg($stderr_file)
            );
            exec($cmd, $out, $exit_code);
            $pid = !empty($out[0]) ? (int)$out[0] : null;
        }
    } else {
        autodj_kill_stale_pid($pid_file);
        $cmd = sprintf(
            "TZ=%s %s %s >%s 2>%s & echo $!",
            escapeshellarg($tz),
            escapeshellcmd($liq_bin),
            escapeshellarg($liq_file),
            escapeshellarg($stdout_file),
            escapeshellarg($stderr_file)
        );
        exec($cmd, $out, $exit_code);
        $pid = !empty($out[0]) ? (int)$out[0] : null;
    }
    if ($pid) @file_put_contents($pid_file, (string)$pid);

    usleep(800000);
    $alive = false;
    if ($pid) {
        if (file_exists("/proc/{$pid}")) $alive = true;
        elseif (function_exists('posix_kill')) $alive = @posix_kill($pid, 0);
        else $alive = (int)trim(@shell_exec("ps -p {$pid} -o pid= 2>/dev/null | wc -l")) > 0;
    }
    $info['cmd_exit_code'] = $exit_code;
    $info['stderr_last_lines'] = @file_exists($stderr_file) ? array_slice(array_filter(array_map('trim', @file($stderr_file))), -20) : [];
    $info['stdout_last_lines'] = @file_exists($stdout_file) ? array_slice(array_filter(array_map('trim', @file($stdout_file))), -10) : [];

    return [
        'pid' => $pid,
        'running' => $alive,
        'info' => $info,
    ];
}

function stop_autodj($pid_file) {
    $info = ['killed' => false, 'old_pid' => null];

    // Si la radio corre como unidad systemd, pararla por unidad (y deshabilitarla
    // para que no vuelva a arrancar sola tras un reboot).
    if (autodj_systemd_available()) {
        $unit = autodj_unit_name(basename(dirname($pid_file)));
        if ($unit !== '') {
            $state = trim((string)@shell_exec('/usr/bin/systemctl is-active ' . escapeshellcmd($unit) . ' 2>/dev/null'));
            if ($state === 'active' || $state === 'activating') {
                @exec('/usr/bin/systemctl stop ' . escapeshellcmd($unit) . ' 2>&1');
                @unlink(dirname($pid_file) . '/.autodj_enabled');
                @unlink($pid_file);
                return ['killed' => true, 'old_pid' => null, 'method' => 'systemd', 'unit' => $unit];
            }
        }
    }

    if (file_exists($pid_file)) {
        $old_pid = (int)trim(@file_get_contents($pid_file));
        $info['old_pid'] = $old_pid;
        if ($old_pid > 1) {
            @exec("kill -TERM {$old_pid} 2>/dev/null");
            usleep(300000);
            @exec("kill -9 {$old_pid} 2>/dev/null");
            $info['killed'] = true;
        }
        @unlink($pid_file);
    }
    return $info;
}

function reload_autodj_hot($pid_file) {
    if (!defined('SIGHUP'))     define('SIGHUP',     1);
    if (!defined('SIGTERM'))    define('SIGTERM',   15);
    if (!defined('SIGKILL'))    define('SIGKILL',    9);
    $info = [
        'attempted' => false,
        'signal' => 'SIGHUP',
        'pid_found' => false,
        'pid_alive_before' => false,
        'pid_alive_after'  => false,
        'pid' => null,
        'result' => null,
        'method' => null,
    ];
    if (!file_exists($pid_file)) {
        $info['result'] = 'pid_archivo_noexiste';
        return $info;
    }
    $pid = (int)trim(@file_get_contents($pid_file));
    $info['pid'] = $pid;
    if ($pid <= 1) {
        $info['result'] = 'pid_invalido';
        return $info;
    }
    $info['pid_found'] = true;
    $alive_before = false;
    if (file_exists("/proc/{$pid}")) $alive_before = true;
    elseif (function_exists('posix_kill')) $alive_before = @posix_kill($pid, 0);
    else $alive_before = (int)trim(@shell_exec("ps -p {$pid} -o pid= 2>/dev/null | wc -l")) > 0;
    $info['pid_alive_before'] = $alive_before;
    if (!$alive_before) {
        $info['result'] = 'proceso_no_activo';
        return $info;
    }
    $info['attempted'] = true;
    $ok = false;
    if (function_exists('posix_kill')) {
        $ok = @posix_kill($pid, SIGHUP);
        $info['method'] = 'posix_kill_SIGHUP';
    }
    if (!$ok) {
        @exec("kill -HUP {$pid} 2>/dev/null", $out, $exit_code);
        $ok = ($exit_code === 0);
        $info['method'] = 'exec_kill_HUP';
    }
    $info['signal_sent'] = $ok;
    usleep(400000);
    $alive_after = false;
    if (file_exists("/proc/{$pid}")) $alive_after = true;
    elseif (function_exists('posix_kill')) $alive_after = @posix_kill($pid, 0);
    else $alive_after = (int)trim(@shell_exec("ps -p {$pid} -o pid= 2>/dev/null | wc -l")) > 0;
    $info['pid_alive_after'] = $alive_after;
    if (!$ok) {
        $info['result'] = 'no_se_pudo_enviar_senal';
    } elseif (!$alive_after) {
        $info['result'] = 'proceso_murio_despues_sighup';
    } else {
        $info['result'] = 'ok_recargado';
    }
    return $info;
}

function send_liq_server_commands($sock_path, $commands, $timeout_us = 800000) {
    $res = [
        'socket_exists' => file_exists($sock_path),
        'socket_writable' => null,
        'commands' => [],
        'errors' => [],
        'method' => null,
    ];
    if (!$res['socket_exists']) {
        $res['errors'][] = 'socket_noexiste';
        return $res;
    }
    $sock = "unix://{$sock_path}";
    $ctx = stream_context_create();
    $fp = @stream_socket_client($sock, $errno, $errstr, 1.5, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $res['errors'][] = "connect_fail: {$errno} {$errstr}";
        if (function_exists('socket_create')) {
            $sock_raw = @socket_create(AF_UNIX, SOCK_STREAM, 0);
            if ($sock_raw && @socket_connect($sock_raw, $sock_path)) {
                $res['method'] = 'ext_socket_unix';
                socket_set_option($sock_raw, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 1, 'usec' => 200000]);
                socket_set_option($sock_raw, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 300000]);
                foreach ($commands as $idx => $cmd) {
                    $line = $cmd . "\n";
                    @socket_write($sock_raw, $line, strlen($line));
                    $out = '';
                    $end_at = microtime(true) + 0.6;
                    while (microtime(true) < $end_at) {
                        $chunk = @socket_read($sock_raw, 4096, PHP_NORMAL_READ);
                        if ($chunk === false || $chunk === '') break;
                        $out .= $chunk;
                        if (str_contains($out, "END\r\n") || str_contains($out, "\nEND\n")) break;
                    }
                    $res['commands'][$idx] = ['cmd' => $cmd, 'reply' => trim($out)];
                }
                @socket_write($sock_raw, "quit\n", 5);
                @socket_close($sock_raw);
                return $res;
            }
        }
        return $res;
    }
    $res['method'] = 'stream_socket_unix';
    $res['socket_writable'] = true;
    stream_set_timeout($fp, 1, 200000);
    foreach ($commands as $idx => $cmd) {
        $line = $cmd . "\n";
        @fwrite($fp, $line);
        @fflush($fp);
        $out = '';
        $end_at = microtime(true) + 0.6;
        while (!feof($fp) && microtime(true) < $end_at) {
            $chunk = @fgets($fp, 4096);
            if ($chunk === false || $chunk === '') break;
            $out .= $chunk;
            if (trim($chunk) === 'END') break;
        }
        $res['commands'][$idx] = ['cmd' => $cmd, 'reply' => trim($out)];
    }
    @fwrite($fp, "quit\n");
    @fclose($fp);
    return $res;
}

function playlist_reload_all($sock_path) {
    $pre = send_liq_server_commands($sock_path, ['vars', 'list']);
    $possible = [];
    foreach ($pre['commands'] as $c) {
        $reply = $c['reply'] ?? '';
        if (preg_match_all('/\b(play_\w+)\b/', $reply, $mm)) {
            foreach ($mm[1] as $m) $possible[$m] = true;
        }
        if (preg_match_all('/\b(src_\w+)\b/', $reply, $mm2)) {
            foreach ($mm2[1] as $m) $possible[$m] = true;
        }
    }
    $cmds = [];
    foreach (array_keys($possible) as $name) {
        $cmds[] = "playlist.reload {$name}";
    }
    if (empty($cmds)) {
        $cmds = ['playlist.reload'];
    }
    return send_liq_server_commands($sock_path, $cmds);
}

// ---------- HELPERS DE ALMACENAMIENTO / CUOTA DE DISCO ----------
// storage_format_bytes() + storage_dir_used_cached() viven en lib_storage.php
// (compartidos con superradio.php para no duplicar el escáner con caché).
require_once __DIR__ . '/lib_storage.php';

// Arma el objeto $storage completo usado por los widgets Inicio + Musicateca
if (!function_exists('storage_assemble')) {
function storage_assemble($base_dir, $quota_mb, $state_dir) {
    $base_dir = rtrim((string)$base_dir, '/\\');
    $quota_mb = (float)$quota_mb;
    $quota_bytes = $quota_mb > 0 ? $quota_mb * 1024 * 1024 : 0.0;
    $state_dir = rtrim((string)$state_dir, '/\\');
    if ($state_dir === '') $state_dir = $base_dir . '/.nextsong_state';
    $cache_path = $state_dir . '/disk_usage.json';

    $used_bytes = storage_dir_used_cached($base_dir, $cache_path, 120);

    // Disco físico real para el caso de cuota ilimitada (o fallback general)
    $disk_total = @disk_total_space($base_dir !== '' && is_dir($base_dir) ? $base_dir : '/');
    $disk_free  = @disk_free_space($base_dir !== '' && is_dir($base_dir) ? $base_dir : '/');
    if ($disk_total === false) $disk_total = 0.0;
    if ($disk_free  === false) $disk_free  = 0.0;

    $unlimited = $quota_bytes <= 0;

    if ($unlimited) {
        // Ilimitado: "asignado" = disco total físico, "libre" = libre real, porcentaje sobre total disco
        $quota_for_pct = (float)$disk_total > 0 ? (float)$disk_total : 1.0;
        $free_bytes = (float)$disk_free;
        $percent = (float)$disk_total > 0 ? min(100.0, ($used_bytes / (float)$disk_total) * 100.0) : 0.0;
        $quota_h = '∞ Ilimitado';
        $free_h  = (float)$disk_free > 0 ? storage_format_bytes($disk_free) . ' (según disco)' : '∞';
    } else {
        $free_bytes = max(0.0, $quota_bytes - $used_bytes);
        $percent = $quota_bytes > 0 ? min(100.0, ($used_bytes / $quota_bytes) * 100.0) : 0.0;
        $quota_h = storage_format_bytes($quota_bytes);
        $free_h  = storage_format_bytes($free_bytes);
    }

    return [
        'used_bytes'   => round($used_bytes, 2),
        'quota_bytes'  => round($quota_bytes, 2),
        'free_bytes'   => round($free_bytes, 2),
        'percent'      => round($percent, 2),
        'unlimited'    => $unlimited,
        'used_h'       => storage_format_bytes($used_bytes),
        'quota_h'      => $quota_h,
        'free_h'       => $free_h,
        'disk_total_h' => (float)$disk_total > 0 ? storage_format_bytes($disk_total) : '-',
        'disk_free_h'  => (float)$disk_free  > 0 ? storage_format_bytes($disk_free)  : '-',
        'quota_mb_raw' => $quota_mb,
    ];
}
}

$action = $_REQUEST['action'] ?? $_REQUEST['cmd'] ?? '';

// 1. CARGAR DATOS
if ($action === 'load_all') {
    $duration_cache = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];
    $cache_modified = false;
    $folders = [];

    // =========================================================
    // PASO 1: Escaneo de CARPETAS FÍSICAS en $base_dir (UTF-8 safe)
    // =========================================================
    $raw_items = @scandir($base_dir) ?: [];
    $items = [];
    foreach ($raw_items as $raw) {
        $safe = to_utf8_safe($raw);
        if ($safe !== '') $items[] = $safe;
    }
    unset($raw_items);

    // Ignorar estas carpetas del listado de Musicateca, pero si existen físicamente
    // sí las usamos para resolver rutas en playlists de tipo "carpetas" / anuncios.
    //   - .nextsong_state : carpeta INTERNA de estado de next_song.php (histórico, modo inmediato, etc.)
    //   - spod            : sistema de SPots de anuncio programado
    //   - Mantenimientos  : jingles / mantenimiento locutado administrador
    //     OJO: HORAS (locuciones de la hora) NO se ignora: debe verse en la Musicateca
    //     para subir ahí los clips HRS<HH>.mp3 / MIN<MM>.mp3 del item "HORA".
    $carpetas_ignorar_musicateca = ['.nextsong_state', 'spod', 'Mantenimientos'];

    // Diccionario nombre_normalizado => nombre_físico_real (para resolver cualquier desalineación)
    $normalize_map = [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        // Comprobar que exista realmente (por si convert_encoding cambió el nombre)
        $check_path = $base_dir . '/' . $item;
        if (!is_dir($check_path)) {
            // Intentar con el nombre raw original (sin conversión) como fallback
            $found = false;
            foreach ($raw_items_fb = (@scandir($base_dir) ?: []) as $fb) {
                if ($fb !== '.' && $fb !== '..' && is_dir($base_dir . '/' . $fb)) {
                    if (normalize_name($fb) === normalize_name($item)) {
                        $item = to_utf8_safe($fb);
                        $check_path = $base_dir . '/' . $item;
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) continue;
        }

        $norm = normalize_name($item);
        if ($norm !== '') $normalize_map[$norm] = $item;

        // SÍ excluir de Musicateca las carpetas reservadas
        $es_ignorar = false;
        foreach ($carpetas_ignorar_musicateca as $ign) {
            if (strcasecmp($item, $ign) === 0 || normalize_name($ign) === $norm) {
                $es_ignorar = true;
                break;
            }
        }
        if ($es_ignorar) continue;

        $full_path = $base_dir . '/' . $item;
        $raw_files = @scandir($full_path) ?: [];
        $files_in_dir = [];
        foreach ($raw_files as $rf) {
            $safe_f = to_utf8_safe($rf);
            if ($safe_f === '' || $safe_f === '.' || $safe_f === '..') continue;
            if (file_exists($full_path . '/' . $safe_f)) {
                $files_in_dir[] = $safe_f;
            } else {
                // Fallback: scanear directo con nombre raw
                if ($rf !== '.' && $rf !== '..' && file_exists($full_path . '/' . $rf)) {
                    $files_in_dir[] = to_utf8_safe($rf);
                }
            }
        }
        $mp3_list = [];
        $normalize_file_map = []; // norm => nombre físico real de archivo (dentro de esta carpeta)

        foreach ($files_in_dir as $f) {
            if (str_ends_with(strtolower($f), '.mp3')) {
                $bytes = @filesize($full_path . '/' . $f);
                if ($bytes === false && strpos($f, ' ') !== false) {
                    // fallback por encoding windows
                    $raw_fb2 = @scandir($full_path) ?: [];
                    foreach ($raw_fb2 as $rrf) {
                        if (to_utf8_safe($rrf) === $f && file_exists($full_path . '/' . $rrf)) {
                            $bytes = @filesize($full_path . '/' . $rrf);
                            break;
                        }
                    }
                }

                $norm_f = normalize_name($f);
                if ($norm_f !== '') $normalize_file_map[$norm_f] = $f;

                // Duración: probar primero con clave normalizada (antiguas caches con Ñ)
                $cache_key = "{$item}/{$f}";
                $norm_cache_key = normalize_name($item) . '/' . normalize_name($f);
                $sec = null;

                if (isset($duration_cache[$cache_key])) {
                    $sec = (int)$duration_cache[$cache_key];
                } else {
                    foreach ($duration_cache as $dk => $dv) {
                        if (normalize_name($dk) === $norm_cache_key) {
                            $sec = (int)$dv;
                            // Migrar a la clave nueva UTF-8 correcta
                            $duration_cache[$cache_key] = $sec;
                            $cache_modified = true;
                            break;
                        }
                    }
                }

                if ($sec === null) {
                    // Ejecutar ffprobe solo si realmente se puede
                    $probe_file = $full_path . '/' . $f;
                    if (!file_exists($probe_file)) {
                        $raw_fb3 = @scandir($full_path) ?: [];
                        foreach ($raw_fb3 as $rrf) {
                            if (to_utf8_safe($rrf) === $f && file_exists($full_path . '/' . $rrf)) {
                                $probe_file = $full_path . '/' . $rrf;
                                break;
                            }
                        }
                    }
                    if (file_exists($probe_file) && function_exists('shell_exec')) {
                        $cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($probe_file);
                        $raw_sec = @shell_exec($cmd);
                        $sec = $raw_sec ? round((float)$raw_sec) : 0;
                    } else {
                        $sec = 0;
                    }
                    $duration_cache[$cache_key] = $sec;
                    $cache_modified = true;
                }

                $size_mb = $bytes ? round($bytes / 1048576, 2) . ' MB' : '0 MB';
                $mins = floor($sec / 60);
                $rem_sec = $sec % 60;
                $duration_str = sprintf('%02d:%02d', $mins, $rem_sec);

                $mp3_list[] = [
                    'name'         => $f,
                    'size'         => $size_mb,
                    'duration_sec' => $sec,
                    'duration_str' => $duration_str,
                    '_norm'        => $norm_f
                ];
            }
        }
        // Asegurar orden alfabetico final
        usort($mp3_list, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        $folders[] = [
            'name'  => $item,
            'count' => count($mp3_list),
            'files' => array_map(fn($x) => ['name' => $x['name'], 'size' => $x['size'], 'duration_sec' => $x['duration_sec'], 'duration_str' => $x['duration_str']], $mp3_list),
            '_norm' => $norm,
            '_file_norm_map' => $normalize_file_map
        ];
    }

    // =========================================================
    // PASO 2: Leer programacion.json y SANEAR
    //         Reemplazar nombres de carpetas/archivos por el nombre FÍSICO real
    //         para que coincidan 100% con las carpetas del array $folders.
    // =========================================================
    $app_data = file_exists($data_file) ? (json_decode(file_get_contents($data_file), true) ?: $default_data) : $default_data;

    // Diccionarios planos para búsqueda instantánea
    $folder_by_norm = [];
    $file_by_folder_norm = []; // [carpeta_norm][archivo_norm] = nombre_real
    foreach ($folders as &$fentry) {
        $n = $fentry['_norm'] ?? normalize_name($fentry['name']);
        $folder_by_norm[$n] = &$fentry;
        $file_by_folder_norm[$n] = $fentry['_file_norm_map'] ?? [];
        unset($fentry['_norm'], $fentry['_file_norm_map']); // limpiar antes de enviar al cliente
    }
    unset($fentry);

    // Función helper local (con use) para traducir un path/nombre de carpeta al físico real
    $resolve_folder = function ($folder_name) use ($folder_by_norm, $normalize_map) {
        if (!is_string($folder_name) || $folder_name === '') return '';
        $folder_name = to_utf8_safe($folder_name);
        $norm = normalize_name($folder_name);
        // 1) Si está en el map del scandir
        if (isset($normalize_map[$norm])) return $normalize_map[$norm];
        // 2) Si coincide exactamente con alguna carpeta ya listada
        if (isset($folder_by_norm[$norm])) return $folder_by_norm[$norm]['name'];
        return $folder_name;
    };

    $resolve_file_in_folder = function ($rel_path_playlist) use ($folder_by_norm, $file_by_folder_norm, $resolve_folder) {
        if (!is_string($rel_path_playlist) || $rel_path_playlist === '') return '';
        $rel_path_playlist = to_utf8_safe($rel_path_playlist);
        // Separar carpeta / archivo
        $has_slash = (strpos($rel_path_playlist, '/') !== false || strpos($rel_path_playlist, '\\') !== false);
        if (!$has_slash) return $rel_path_playlist;
        $parts = preg_split('/[\\\\\\/]/', $rel_path_playlist);
        $parts = array_values(array_filter($parts, fn($x) => $x !== ''));
        if (count($parts) < 2) return $rel_path_playlist;
        $file_name = array_pop($parts);
        $folder_joined = implode('/', $parts);
        $real_folder = $resolve_folder($folder_joined);
        $folder_norm = normalize_name($real_folder);
        $file_norm = normalize_name($file_name);
        $real_file = $file_name;
        if (isset($file_by_folder_norm[$folder_norm][$file_norm])) {
            $real_file = $file_by_folder_norm[$folder_norm][$file_norm];
        }
        return $real_folder . '/' . $real_file;
    };

    // === Aplicar la corrección a TODOS los playlists ===
    if (is_array($app_data['playlists'] ?? null)) {
        foreach ($app_data['playlists'] as $pname => $pinfo) {
            if (!is_array($pinfo)) continue;
            if (!isset($pinfo['tipo']) || !is_string($pinfo['tipo'])) $app_data['playlists'][$pname]['tipo'] = 'carpetas';
            $app_data['playlists'][$pname]['allow_repeat']         = !empty($pinfo['allow_repeat']);
            $app_data['playlists'][$pname]['repeat_every_n_songs'] = max(0, min(100, (int)($pinfo['repeat_every_n_songs'] ?? 0)));
            $tipo = $app_data['playlists'][$pname]['tipo'];
            if (!isset($pinfo['items']) || !is_array($pinfo['items'])) {
                $app_data['playlists'][$pname]['items'] = [];
                continue;
            }
            $items_corrected = [];
            foreach ($pinfo['items'] as $idx => $it) {
                if (!is_string($it) || $it === '') continue;
                if ($tipo === 'carpetas') {
                    $res = $resolve_folder($it);
                    if ($res !== '') $items_corrected[] = $res;
                } else {
                    // tipo archivos -> path Carpeta/file.mp3
                    $res = $resolve_file_in_folder($it);
                    if ($res !== '') $items_corrected[] = $res;
                }
            }
            $app_data['playlists'][$pname]['items'] = $items_corrected;
        }
    }

    // Saneamiento genérico de default_data + defaults
    foreach ($app_data['playlists'] as $k => $v) {
        if (is_array($v)) {
            if (!isset($v['tipo']))                $app_data['playlists'][$k]['tipo'] = 'carpetas';
            if (!isset($v['items']))               $app_data['playlists'][$k]['items'] = [];
            if (!isset($v['allow_repeat']))        $app_data['playlists'][$k]['allow_repeat'] = false;
            if (!isset($v['repeat_every_n_songs']))$app_data['playlists'][$k]['repeat_every_n_songs'] = 0;
        }
    }
    if (!isset($app_data['playlists']['general'])) $app_data['playlists']['general'] = ['tipo' => 'carpetas', 'items' => []];
    if (!isset($app_data['schedule']) || !is_array($app_data['schedule'])) $app_data['schedule'] = [];
    if (!isset($app_data['ads']) || !is_array($app_data['ads'])) $app_data['ads'] = [];
    if (!isset($app_data['timezone'])) $app_data['timezone'] = 'America/Costa_Rica';
    if (!isset($app_data['default_playlist'])) $app_data['default_playlist'] = 'general';
    // Carpeta de los clips de la hora (default fijo HORAS) — la usa el item "@HORA@".
    if (!isset($app_data['hora_folder']) || !is_string($app_data['hora_folder'])) $app_data['hora_folder'] = 'HORAS';
    $app_data['hora_folder'] = trim($app_data['hora_folder']);
    if ($app_data['hora_folder'] === '' || strpos($app_data['hora_folder'], '..') !== false) $app_data['hora_folder'] = 'HORAS';
    unset($app_data['time_voice']);   // clave heredada de la voz de hora automática (retirada)
    // Carpetas con nombre oculto en el reproductor: solo nombres, sin rutas.
    if (!isset($app_data['hide_title_folders']) || !is_array($app_data['hide_title_folders'])) $app_data['hide_title_folders'] = [];
    $_hide_names = [];
    foreach ($app_data['hide_title_folders'] as $_hname) {
        if (!is_string($_hname)) continue;
        $_hname = trim($_hname);
        if ($_hname === '' || strpos($_hname, '/') !== false || strpos($_hname, '\\') !== false || strpos($_hname, '..') !== false) continue;
        $_hide_names[$_hname] = true;
    }
    $app_data['hide_title_folders'] = array_keys($_hide_names);
    if (!isset($app_data['intercalators']) || !is_array($app_data['intercalators'])) $app_data['intercalators'] = [];
    $_sanitized_intercalators = [];
    foreach ($app_data['intercalators'] as $_int) {
        if (!is_array($_int)) continue;
        $_folder = trim((string)($_int['folder'] ?? ''));
        if ($_folder === '') continue;
        $_folder = $resolve_folder($_folder);
        if ($_folder === '') continue;
        $_type   = ($_int['type'] ?? 'songs') === 'minutes' ? 'minutes' : 'songs';
        $_value  = max(1, (int)($_int['value'] ?? ($_type === 'songs' ? 3 : 10)));
        if ($_type === 'songs')   $_value = max(1, min(50, $_value));
        if ($_type === 'minutes') $_value = max(1, min(240, $_value));
        $_apply  = ($_int['apply_mode'] ?? 'default_only') === 'always' ? 'always' : 'default_only';
        $_play   = ($_int['play_mode'] ?? 'single_random') === 'whole_folder_seq' ? 'whole_folder_seq' : 'single_random';
        $_sanitized_intercalators[] = [
            'id'     => is_string($_int['id'] ?? null) && $_int['id'] !== '' ? (string)$_int['id'] : substr(sha1($_folder.'|'.$_type.'|'.$_value.'|'.microtime(true)),0,10),
            'folder' => $_folder,
            'type'   => $_type,
            'value'  => $_value,
            'apply_mode' => $_apply,
            'play_mode'  => $_play,
        ];
    }
    $app_data['intercalators'] = $_sanitized_intercalators;
    // Corregir hora_folder si la carpeta física real se llama distinta
    if (!empty($app_data['hora_folder'])) {
        $app_data['hora_folder'] = $resolve_folder($app_data['hora_folder']);
    }
    // Crossfade entre pistas: defaults + saneo (segundos, 0 en ambos = desactivado)
    if (!isset($app_data['crossfade']) || !is_array($app_data['crossfade'])) $app_data['crossfade'] = ['fade_in' => 0, 'fade_out' => 0];
    $app_data['crossfade'] = [
        'fade_in'  => round(max(0, min(5.0, (float)($app_data['crossfade']['fade_in']  ?? 0))) * 2) / 2,
        'fade_out' => round(max(0, min(5.0, (float)($app_data['crossfade']['fade_out'] ?? 0))) * 2) / 2,
    ];

    // =========================================================
    // PASO 3: Persistir cache de duraciones si fue modificada
    // =========================================================
    if ($cache_modified) {
        @file_put_contents($cache_file, json_encode($duration_cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // =========================================================
    // PASO 3.5: Espacio usado / cuota (Storage Dashboard & Musicateca)
    // =========================================================
    $quota_mb = (float)($radio['quota_mb'] ?? 0);
    $storage_state_dir = $base_dir . '/.nextsong_state';
    $storage = storage_assemble($base_dir, $quota_mb, $storage_state_dir);

    // =========================================================
    // PASO 4: Estado del AutoDJ y respuesta
    // =========================================================
    $st = radio_status_summary($mount, $pid_file);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'folders'   => $folders,
        'data'      => $app_data,
        'running'   => $st['running'],
        'icecast'   => [
            'online'    => $st['online'],
            'listeners' => $st['listeners'],
        ],
        'storage'   => $storage,
        'mount'     => $mount
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- HELPERS DE SANITIZACIÓN DE RUTAS (Musicoteca / Upload) ----------
// Sanitiza nombre carpeta: permite letras UTF8, números, espacio, guion, bajo, punto, coma, paréntesis, ampersand.
// Bloquea TODO lo que pueda ser path traversal, NUL, control, barras, ~, comillas.
if (!function_exists('sane_dir_name')) {
function sane_dir_name($raw_name) {
    $raw_name = trim((string)to_utf8_safe($raw_name));
    if ($raw_name === '' || $raw_name === '.' || $raw_name === '..') return '';
    if (str_starts_with($raw_name, '.')) return '';
    $raw_name = str_replace(["\x00", '\\', '/', ':', '*', '?', '"', '<', '>', '|', '~', '`', '$', chr(0x7f)], '', $raw_name);
    $raw_name = preg_replace('/[\x00-\x1F\x80-\x9F]/u', '', $raw_name);
    $raw_name = trim((string)$raw_name, " \t\n\r\0\x0B.");
    if ($raw_name === '' || $raw_name === '.' || $raw_name === '..') return '';
    if (strlen($raw_name) > 100) $raw_name = substr($raw_name, 0, 100);
    return $raw_name;
}
}
if (!function_exists('sane_file_name')) {
function sane_file_name($raw_name) {
    $raw_name = trim((string)to_utf8_safe($raw_name));
    if ($raw_name === '' || $raw_name === '.' || $raw_name === '..') return '';
    if (str_starts_with($raw_name, '.')) return '';
    $raw_name = str_replace(["\x00", '\\', '/', ':', '*', '?', '"', '<', '>', '|', '~', '`', '$', chr(0x7f)], '', $raw_name);
    $raw_name = preg_replace('/[\x00-\x1F\x80-\x9F]/u', '', $raw_name);
    $raw_name = trim((string)$raw_name, " \t\n\r\0\x0B.");
    if ($raw_name === '' || $raw_name === '.' || $raw_name === '..') return '';
    if (strlen($raw_name) > 200) $raw_name = substr($raw_name, 0, 200);
    return $raw_name;
}
}
// ---------- FIN HELPERS SANITIZACIÓN ----------

// 2. GESTIÓN DE ARCHIVOS Y CARPETAS
if ($action === 'create_folder') {
    $resp = ['success' => false];
    $raw = $_POST['folder_name'] ?? '';
    $folder_name = sane_dir_name($raw);
    // Nombres RESERVADOS (carpetas de sistema / internas) — el cliente NO puede crearlas ni borrarlas.
    $folders_reservados = ['.nextsong_state', 'horas', 'spod', 'mantenimientos', 'anuncios'];
    $norm_res = strtolower(normalize_name($folder_name));
    if ($folder_name === '') {
        $resp['error'] = "Nombre de carpeta no válido (solo letras, números, espacios, _ - . , () &). No puede empezar con punto ni ser vacío.";
        $resp['hint'] = "Ejemplo válido: Salsa 2025, Baladas-Clásicas, Música_(latino)";
    } elseif (in_array($norm_res, $folders_reservados, true)) {
        $resp['error'] = "El nombre \"{$folder_name}\" está RESERVADO por el sistema. Usa otro nombre para tu carpeta de música.";
        $resp['hint'] = "Carpetas reservadas: .nextsong_state (estado interno), HORAS (locuciones), Anuncios (spots), Mantenimientos (jingles).";
    } else {
        $target = $base_dir . '/' . $folder_name;
        if (is_dir($target)) {
            $resp['error'] = "Ya existe una carpeta llamada \"{$folder_name}\".";
        } else {
            $prev = @umask(0);
            $ok = @mkdir($target, 0775, true);
            @umask($prev);
            if ($ok) {
                @chmod($target, 0775);
                if (function_exists('posix_getpwnam')) {
                    $u = @posix_getpwnam('www-data');
                    if ($u) @chown($target, $u['name']);
                    $g = @posix_getgrnam('www-data');
                    if ($g) @chgrp($target, $g['name']);
                }
                clearstatcache(true, $target);
                if (is_dir($target)) {
                    $resp['success'] = true;
                    $resp['folder'] = $folder_name;
                } else {
                    $resp['error'] = "mkdir devolvió OK pero la carpeta no existe. Revisa permisos de escritura en {$base_dir} (debería ser www-data:www-data chmod 775).";
                }
            } else {
                $lasterr = error_get_last();
                $resp['error'] = "No se pudo crear la carpeta. Permisos denegados o ruta inválida en {$base_dir}.";
                if ($lasterr) $resp['detail'] = $lasterr['message'] ?? '';
                $resp['hint_perms'] = "En el VPS ejecuta como root: chown -R www-data:www-data /var/media/radios && chmod -R 775 /var/media/radios";
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Borrado físico recursivo (carpetas + subcarpetas) ----------
if (!function_exists('api_fs_delete_tree')) {
function api_fs_delete_tree($dir, &$deleted, &$errors) {
    $items = @scandir($dir) ?: [];
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $p = $dir . '/' . $it;
        if (is_dir($p)) {
            api_fs_delete_tree($p, $deleted, $errors);
            if (!@rmdir($p) && is_dir($p)) $errors[] = $p;
        } elseif (is_file($p) || is_link($p)) {
            if (@unlink($p)) { $deleted++; } else { $errors[] = $p; }
        }
    }
    // La carpeta raíz pasada también se elimina al terminar.
    if (!@rmdir($dir) && is_dir($dir)) $errors[] = $dir;
}
}

if ($action === 'delete_folder') {
    $resp = ['success' => false];
    $folder = sane_dir_name($_POST['folder'] ?? '');
    $reservados = ['.nextsong_state', 'horas', 'spod', 'mantenimientos', 'anuncios'];
    if ($folder === '') {
        $resp['error'] = "Carpeta no especificada o nombre no válido.";
    } else {
        // Resolver el nombre FÍSICO real (case/encoding) por si difiere del enviado.
        $target = $base_dir . '/' . $folder;
        if (!is_dir($target)) {
            $normTarget = normalize_name($folder);
            foreach ((@scandir($base_dir) ?: []) as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (!is_dir($base_dir . '/' . $entry)) continue;
                if (normalize_name($entry) === $normTarget) { $target = $base_dir . '/' . $entry; break; }
            }
        }
        $realName = basename($target);
        $es_reservada = false;
        foreach ($reservados as $r) {
            if (normalize_name($realName) === normalize_name($r)) { $es_reservada = true; break; }
        }
        if (!is_dir($target)) {
            $resp['error'] = "La carpeta {$folder} no existe.";
        } elseif ($es_reservada) {
            $resp['error'] = "La carpeta {$realName} es del sistema y no se puede borrar desde la Musicateca.";
        } else {
            // Proteger carpetas en uso por el sistema (clips de hora / intercaladores)
            $cfg_media = file_exists($data_file) ? (json_decode(@file_get_contents($data_file), true) ?: []) : [];
            $uso = '';
            $horaFolder = trim((string)($cfg_media['hora_folder'] ?? ''));
            if ($horaFolder === '') $horaFolder = 'HORAS';
            if (normalize_name($horaFolder) === normalize_name($realName)) $uso = 'los clips de la hora';
            if ($uso === '') {
                foreach (($cfg_media['intercalators'] ?? []) as $int) {
                    if (!is_array($int)) continue;
                    $f = trim((string)($int['folder'] ?? ''));
                    if ($f !== '' && normalize_name($f) === normalize_name($realName)) { $uso = 'un intercalador de anuncios'; break; }
                }
            }
            if ($uso !== '') {
                $resp['error'] = "La carpeta {$realName} está en uso por {$uso}. Desactívalo antes de borrarla.";
            } else {
                $deleted = 0; $errors = [];
                api_fs_delete_tree($target, $deleted, $errors);
                $still = is_dir($target);

                // Limpiar referencias en programacion.json (playlists que usen la carpeta)
                $cfg_changed = false;
                if (is_array($cfg_media)) {
                    $normReal = normalize_name($realName);
                    foreach (($cfg_media['playlists'] ?? []) as $plName => &$plCfg) {
                        if (!is_array($plCfg)) continue;
                        $items = $plCfg['items'] ?? [];
                        $tipo  = $plCfg['tipo'] ?? 'carpetas';
                        $newItems = [];
                        foreach ($items as $it) {
                            if (!is_string($it) || trim($it) === '') continue;
                            if ($tipo === 'carpetas') {
                                if (normalize_name(trim($it)) === $normReal) { $cfg_changed = true; continue; }
                                $newItems[] = $it;
                            } else {
                                // archivos: ruta "folder/archivo.mp3" o igual a la carpeta
                                $firstSeg = preg_split('#[/\\\\]#', trim($it), 2)[0];
                                if (normalize_name($firstSeg) === $normReal) { $cfg_changed = true; continue; }
                                $newItems[] = $it;
                            }
                        }
                        $plCfg['items'] = array_values($newItems);
                    }
                    unset($plCfg);
                    if ($cfg_changed) {
                        @file_put_contents($data_file, json_encode($cfg_media, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    }
                }

                // Limpiar caché de duración (claves que empiecen por la carpeta)
                if (file_exists($cache_file)) {
                    $duration_cache = (json_decode(@file_get_contents($cache_file), true) ?: []);
                    $cache_mod = false;
                    $normReal = normalize_name($realName);
                    foreach ($duration_cache as $k => $v) {
                        $firstSeg = preg_split('#[/\\\\]#', (string)$k, 2)[0];
                        if (normalize_name($firstSeg) === $normReal) { unset($duration_cache[$k]); $cache_mod = true; }
                    }
                    if ($cache_mod) {
                        @file_put_contents($cache_file, json_encode($duration_cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    }
                }
                // Invalidar caché de uso de disco
                @unlink($base_dir . '/.nextsong_state/disk_usage.json');

                if (!$still && empty($errors)) {
                    $resp['success'] = true;
                    $resp['deleted_mp3'] = $deleted;
                    $resp['folder'] = $realName;
                    $resp['config_cleaned'] = (bool)$cfg_changed;
                } else {
                    $resp['deleted_mp3'] = $deleted;
                    if ($still) {
                        $resp['error'] = "No se pudo borrar por completo la carpeta {$realName} (quedó contenido).";
                    } else {
                        $resp['error'] = "Borrado incompleto: " . implode(', ', array_slice($errors, 0, 5));
                    }
                    if (!empty($errors)) $resp['detail'] = array_slice($errors, 0, 5);
                }
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'upload_multiple') {
    $resp = ['success' => false, 'uploaded' => 0, 'skipped' => 0, 'total' => 0, 'errors' => []];
    $folder = sane_dir_name($_POST['folder'] ?? '');
    if ($folder === '') {
        $resp['error'] = "Selecciona primero una carpeta existente.";
    } else {
        $dest_dir = $base_dir . '/' . $folder;
        if (!is_dir($dest_dir)) {
            $resp['error'] = "La carpeta destino {$folder} no existe físicamente. Créala antes de subir.";
        } else {
            if (!isset($_FILES['files']) || !is_array($_FILES['files']['name'] ?? null)) {
                $resp['error'] = "No se recibieron archivos. Revisa el límite post_max_size / upload_max_filesize del PHP.ini.";
            } else {
                $total_files = count($_FILES['files']['name']);
                $resp['total'] = $total_files;
                for ($i = 0; $i < $total_files; $i++) {
                    $orig_name = basename((string)($_FILES['files']['name'][$i] ?? ''));
                    $error = (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                    $tmp_name = $_FILES['files']['tmp_name'][$i] ?? '';

                    if ($error !== UPLOAD_ERR_OK) {
                        $map = [
                            UPLOAD_ERR_INI_SIZE => "Archivo supera upload_max_filesize (php.ini)",
                            UPLOAD_ERR_FORM_SIZE => "Archivo supera MAX_FILE_SIZE del formulario",
                            UPLOAD_ERR_PARTIAL => "Subida se cortó a medias",
                            UPLOAD_ERR_NO_FILE => "Ningún archivo en este slot",
                            UPLOAD_ERR_NO_TMP_DIR => "Falta carpeta tmp PHP",
                            UPLOAD_ERR_CANT_WRITE => "No se puede escribir tmp (permisos)",
                            UPLOAD_ERR_EXTENSION => "Extensión PHP bloqueó la subida",
                        ];
                        $resp['skipped']++;
                        if ($error !== UPLOAD_ERR_NO_FILE) {
                            $resp['errors'][] = "{$orig_name}: " . ($map[$error] ?? "Error {$error}");
                        }
                        continue;
                    }

                    $file_name = sane_file_name($orig_name);
                    if ($file_name === '') {
                        $resp['skipped']++;
                        $resp['errors'][] = "{$orig_name}: nombre no válido tras sanitizar.";
                        continue;
                    }
                    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    if ($ext !== 'mp3') {
                        $resp['skipped']++;
                        $resp['errors'][] = "{$orig_name}: solo se aceptan archivos .mp3";
                        continue;
                    }
                    if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
                        $resp['skipped']++;
                        $resp['errors'][] = "{$orig_name}: no fue subido por POST válido.";
                        continue;
                    }
                    $dest_path = $dest_dir . '/' . $file_name;
                    $prev = @umask(0);
                    $ok = @move_uploaded_file($tmp_name, $dest_path);
                    @umask($prev);
                    if (!$ok) {
                        $resp['skipped']++;
                        $lasterr = error_get_last();
                        $resp['errors'][] = "{$orig_name}: move_uploaded_file falló. " . ($lasterr['message'] ?? '');
                        continue;
                    }
                    @chmod($dest_path, 0664);
                    if (function_exists('posix_getpwnam')) {
                        $u = @posix_getpwnam('www-data'); if ($u) @chown($dest_path, $u['name']);
                        $g = @posix_getgrnam('www-data'); if ($g) @chgrp($dest_path, $g['name']);
                    }
                    $resp['uploaded']++;
                }
                $resp['success'] = $resp['uploaded'] > 0 || $total_files === 0;
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete_file') {
    $resp = ['success' => false];
    $folder = sane_dir_name($_POST['folder'] ?? '');
    $file   = sane_file_name($_POST['file'] ?? '');
    if ($folder === '' || $file === '') {
        $resp['error'] = "Faltan datos de carpeta/archivo.";
    } else {
        $target = $base_dir . '/' . $folder . '/' . $file;
        if (!file_exists($target)) {
            $resp['error'] = "Archivo no existe: {$folder}/{$file}";
        } else {
            $ok = @unlink($target);
            if ($ok) {
                $resp['success'] = true;
                $duration_cache = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];
                $ck = "{$folder}/{$file}";
                if (isset($duration_cache[$ck])) { unset($duration_cache[$ck]); @file_put_contents($cache_file, json_encode($duration_cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); }
            } else {
                $lasterr = error_get_last();
                $resp['error'] = "No se pudo borrar: permisos denegados.";
                if ($lasterr) $resp['detail'] = $lasterr['message'] ?? '';
            }
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. GUARDAR CONFIGURACIÓN
if ($action === 'save_data') {
    $raw = json_decode(file_get_contents('php://input'), true);
    $current_saved = file_exists($data_file) ? (json_decode(file_get_contents($data_file), true) ?: $default_data) : $default_data;

    if (isset($raw['timezone']))         $current_saved['timezone']         = $raw['timezone'];
    if (isset($raw['default_playlist'])) $current_saved['default_playlist'] = $raw['default_playlist'];
    if (isset($raw['playlists']) && is_array($raw['playlists'])) {
        $sanitized_pls = [];
        foreach ($raw['playlists'] as $_pname => $_pinfo) {
            if (!is_array($_pinfo)) continue;
            $_tipo = isset($_pinfo['tipo']) && $_pinfo['tipo'] === 'archivos' ? 'archivos' : 'carpetas';
            $_items = isset($_pinfo['items']) && is_array($_pinfo['items']) ? array_values(array_filter($_pinfo['items'], 'is_string')) : [];
            $sanitized_pls[(string)$_pname] = [
                'tipo'                => $_tipo,
                'items'               => $_items,
                'allow_repeat'        => !empty($_pinfo['allow_repeat']),
                'repeat_every_n_songs' => max(0, min(100, (int)($_pinfo['repeat_every_n_songs'] ?? 0))),
            ];
        }
        $current_saved['playlists'] = $sanitized_pls;
    }
    if (isset($raw['schedule']) && is_array($raw['schedule'])) {
        $_sched = [];
        $__playlists_names = array_keys($current_saved['playlists'] ?? []);
        foreach ($raw['schedule'] as $s) {
            if (!is_array($s)) continue;
            $_pl = trim((string)($s['playlist'] ?? ''));
            if ($_pl === '') continue;
            if (!in_array($_pl, $__playlists_names, true) && $_pl !== ($current_saved['default_playlist'] ?? 'general')) continue;
            $_start = trim((string)($s['start'] ?? ''));
            $_end   = trim((string)($s['end'] ?? ''));
            if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $_start)) continue;
            if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $_end))   continue;
            $_days_raw = !empty($s['days']) ? (is_array($s['days']) ? $s['days'] : [$s['days']]) : [1,2,3,4,5,6,7];
            $_days = [];
            foreach ($_days_raw as $d) {
                $di = (int)$d;
                if ($di >= 1 && $di <= 7 && !in_array($di, $_days, true)) $_days[] = $di;
            }
            if (empty($_days)) $_days = [1,2,3,4,5,6,7];
            sort($_days);
            $_mode = ($s['mode'] ?? 'inmediato') === 'insertado' ? 'insertado' : 'inmediato';
            $_sched[] = [
                'playlist' => $_pl,
                'start' => $_start,
                'end'   => $_end,
                'days'  => $_days,
                'mode'  => $_mode,
            ];
        }
        $current_saved['schedule'] = $_sched;
    }
    if (isset($raw['ads']) && is_array($raw['ads'])) {
        $_ads = [];
        $__playlists_names_ads = array_keys($current_saved['playlists'] ?? []);
        foreach ($raw['ads'] as $a) {
            if (!is_array($a)) continue;
            $_ap = trim((string)($a['playlist'] ?? ''));
            if ($_ap === '' || !in_array($_ap, $__playlists_names_ads, true)) continue;
            $_hrs = [];
            foreach ((array)($a['hours'] ?? []) as $h) {
                $hs = trim((string)$h);
                if (preg_match('/^([01]?\d|2[0-3])$/', $hs)) $_hrs[] = $hs;
            }
            $_hrs = array_values(array_unique($_hrs));
            if (empty($_hrs)) continue;
            $_mins = [];
            foreach ((array)($a['minutes'] ?? []) as $mi) {
                $ms = trim((string)$mi);
                if (preg_match('/^[0-5]?\d$/', $ms)) $_mins[] = $ms;
            }
            $_mins = array_values(array_unique($_mins));
            if (empty($_mins)) continue;
            $_days_raw_ads = !empty($a['days']) ? (is_array($a['days']) ? $a['days'] : [$a['days']]) : [1,2,3,4,5,6,7];
            $_days_ads = [];
            foreach ($_days_raw_ads as $d) {
                $di = (int)$d;
                if ($di >= 1 && $di <= 7 && !in_array($di, $_days_ads, true)) $_days_ads[] = $di;
            }
            if (empty($_days_ads)) $_days_ads = [1,2,3,4,5,6,7];
            sort($_days_ads);
            $_ads[] = [
                'playlist' => $_ap,
                'hours' => $_hrs,
                'minutes' => $_mins,
                'days' => $_days_ads,
            ];
        }
        $current_saved['ads'] = $_ads;
    }
    if (isset($raw['hora_folder']) && is_string($raw['hora_folder'])) {
        $horaFolder = trim($raw['hora_folder']);
        if ($horaFolder === '' || strpos($horaFolder, '..') !== false) $horaFolder = 'HORAS';
        $current_saved['hora_folder'] = $horaFolder;
    }
    unset($current_saved['time_voice']);   // clave heredada de la voz de hora automática (retirada)
    if (isset($raw['hide_title_folders']) && is_array($raw['hide_title_folders'])) {
        $_hide_names = [];
        foreach ($raw['hide_title_folders'] as $_hname) {
            if (!is_string($_hname)) continue;
            $_hname = trim($_hname);
            if ($_hname === '' || strpos($_hname, '/') !== false || strpos($_hname, '\\') !== false || strpos($_hname, '..') !== false) continue;
            $_hide_names[$_hname] = true;
        }
        $current_saved['hide_title_folders'] = array_keys($_hide_names);
    }
    if (isset($raw['intercalators']) && is_array($raw['intercalators'])) {
        $_ints = [];
        foreach ($raw['intercalators'] as $_int) {
            if (!is_array($_int)) continue;
            $_folder = trim((string)($_int['folder'] ?? ''));
            if ($_folder === '') continue;
            $_type = ($_int['type'] ?? 'songs') === 'minutes' ? 'minutes' : 'songs';
            $_value = (int)($_int['value'] ?? 0);
            if ($_value <= 0) continue;
            if ($_type === 'songs')   $_value = max(1, min(50, $_value));
            if ($_type === 'minutes') $_value = max(1, min(240, $_value));
            $_apply = ($_int['apply_mode'] ?? 'default_only') === 'always' ? 'always' : 'default_only';
            $_play  = ($_int['play_mode'] ?? 'single_random') === 'whole_folder_seq' ? 'whole_folder_seq' : 'single_random';
            $_ints[] = [
                'id'     => is_string($_int['id'] ?? null) && $_int['id'] !== '' ? (string)$_int['id'] : substr(sha1($_folder.'|'.$_type.'|'.$_value.'|'.microtime(true)),0,10),
                'folder' => $_folder,
                'type'   => $_type,
                'value'  => $_value,
                'apply_mode' => $_apply,
                'play_mode'  => $_play,
            ];
        }
        $current_saved['intercalators'] = $_ints;
    }
    if (isset($raw['crossfade']) && is_array($raw['crossfade'])) {
        // Crossfade entre pistas: segundos de fade-in/fade-out (0 en ambos = desactivado).
        $current_saved['crossfade'] = [
            'fade_in'  => round(max(0, min(5.0, (float)($raw['crossfade']['fade_in']  ?? 0))) * 2) / 2,
            'fade_out' => round(max(0, min(5.0, (float)($raw['crossfade']['fade_out'] ?? 0))) * 2) / 2,
        ];
    }

    $write_ok = @file_put_contents($data_file, json_encode($current_saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $resp = ['success' => (bool)$write_ok];
    if (!$write_ok) {
        $resp['error'] = 'No se pudo escribir programacion.json (revisar permisos de escritura en ' . $base_dir . ')';
    } else {
        $def_pl_name = $current_saved['default_playlist'] ?? 'general';
        $_vp = [];
        $_liq = generate_liq_code($current_saved, $def_pl_name, $base_dir, $radio, $mount, $encoder_pass, $_vp);
        @file_put_contents($liq_file, $_liq);
        @chmod($liq_file, 0664);
        $resp['warnings'] = $GLOBALS['__liq_warnings'] ?? [];

        $liq_bin = find_liquidsoap_binary();
        if (@is_executable($liq_bin)) {
            $check = run_liquidsoap_check($liq_bin, $liq_file);
            $resp['liq_check'] = $check;
            if (!$check['ok']) {
                $resp['liq_error_preview'] = 'El script autodj.liq NO pasará liquidsoap --check. Si intentas arrancar el AutoDJ FALLARÁ. Detalle: ' . trim($check['stdout']);
            } else {
                $running_before = is_autodj_running($pid_file);
                $st_sum = radio_status_summary($mount, $pid_file);
                $resp['running'] = $st_sum['running'];
                $resp['icecast'] = ['online' => $st_sum['online'], 'listeners' => $st_sum['listeners']];
                if ($running_before) {
                    $sock_path = "{$base_dir}/liq.sock";
                    $resp['playlist_reload'] = playlist_reload_all($sock_path);
                    $resp['note'] = 'Cambios aplicados SIN cortar la emisión. El proceso AutoDJ NO se ha reiniciado; no necesita refrescar el navegador.';
                } else {
                    $resp['note'] = 'AutoDJ no estaba encendido. El nuevo script se usará al iniciar manualmente con "Iniciar AutoDJ".';
                }
            }
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: calcular running real (no solo pidfile)
function is_autodj_running($pid_file) {
    if (!file_exists($pid_file)) return false;
    $pid = (int)trim(@file_get_contents($pid_file));
    if (!$pid || $pid < 2) return false;
    if (file_exists("/proc/{$pid}")) return true;
    if (function_exists('posix_kill')) { $r = @posix_kill($pid, 0); if ($r !== null) return (bool)$r; }
    return (int)trim(@shell_exec("ps -p {$pid} -o pid= 2>/dev/null | wc -l")) > 0;
}

function get_icecast_mounts() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [
        '_server' => [
            'version' => '',
            'server_name' => 'Stream Server',
            'server_url'  => '',
            'admin'       => '',
            'host'        => '',
            'location'    => '',
            'listeners'   => 0,
            'sources'     => 0,
            'stats_connections' => 0,
        ],
        '_mounts' => [],
    ];
    $ch = @curl_init("http://127.0.0.1:8000/status-json.xsl");
    if (!$ch) return $cache;
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_FAILONERROR, false);
    $json_raw = @curl_exec($ch);
    $http_code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);
    if (!$json_raw || $http_code < 200 || $http_code >= 400) return $cache;
    $data = @json_decode($json_raw, true);
    if (empty($data['icestats'])) return $cache;
    $ic = $data['icestats'];
    $cache['_server']['version'] = (string)($ic['server_version'] ?? ($ic['version'] ?? ''));
    $cache['_server']['server_name'] = (string)($ic['server_name'] ?? 'Stream Server');
    $cache['_server']['server_url']  = (string)($ic['server_url']  ?? '');
    $cache['_server']['admin']       = (string)($ic['admin'] ?? '');
    $cache['_server']['host']        = (string)($ic['host'] ?? '');
    $cache['_server']['location']    = (string)($ic['location'] ?? '');
    $cache['_server']['listeners']   = (int)($ic['listeners'] ?? 0);
    $cache['_server']['sources']     = (int)($ic['sources'] ?? 0);
    $cache['_server']['stats_connections'] = (int)($ic['stats_connections'] ?? 0);
    if (empty($ic['source'])) return $cache;
    $src = $ic['source'];
    $sources = (isset($src['listenurl']) || isset($src['listeners'])) ? [$src] : array_values($src);
    foreach ($sources as $s) {
        $listenurl = (string)($s['listenurl'] ?? '');
        $direct_mount = trim((string)($s['mount'] ?? ''), '/');
        $path_mount = '';
        $pu = @parse_url($listenurl);
        if (!empty($pu['path'])) $path_mount = trim($pu['path'], '/');
        $mount_key = $direct_mount ?: $path_mount;
        if ($mount_key === '') continue;
        $audio_info = (string)($s['audio_info'] ?? '');
        $ai = [];
        foreach (explode(';', $audio_info) as $tok) {
            $kv = explode('=', $tok, 2);
            if (count($kv)===2) $ai[trim($kv[0])] = trim($kv[1]);
        }
        $bitrate_raw = (string)($s['bitrate'] ?? ($ai['bitrate'] ?? ''));
        if ($bitrate_raw !== '') {
            $br = (int)$bitrate_raw;
            if ($br > 0 && $br < 1000) $bitrate_raw = (string)$br;
        }
        $samplerate_raw = (string)($ai['samplerate'] ?? ($s['samplerate'] ?? ''));
        $channels_raw = (string)($ai['channels'] ?? ($s['channels'] ?? ''));
        $cache['_mounts'][$mount_key] = [
            'mount_key'   => $mount_key,
            'online'      => true,
            'listenurl'   => $listenurl,
            'listeners'   => (int)($s['listeners'] ?? 0),
            'peak_listeners' => (int)($s['peak_listeners'] ?? 0),
            'max_listeners'  => (int)($s['max_listeners'] ?? 0),
            'unique_listeners' => (int)($s['listener_peak'] ?? ($s['unique_listeners'] ?? 0)),
            'total_listeners'    => (int)($s['total_listeners_read'] ?? ($s['stream_listeners_read'] ?? 0)),
            'total_bytes_read'   => (int)($s['total_bytes_read'] ?? 0),
            'total_bytes_sent'   => (int)($s['total_bytes_sent'] ?? 0),
            'stream_start'       => (string)($s['stream_start'] ?? ''),
            'stream_start_iso8601' => (string)($s['stream_start_iso8601'] ?? ''),
            'server_url'   => (string)($s['server_url'] ?? ''),
            'server_title' => (string)($s['title'] ?? ($s['server_name'] ?? '')),
            'server_genre' => (string)($s['genre'] ?? ''),
            'server_genre2' => (string)($s['genre2'] ?? ''),
            'server_genre3' => (string)($s['genre3'] ?? ''),
            'server_genre4' => (string)($s['genre4'] ?? ''),
            'server_genre5' => (string)($s['genre5'] ?? ''),
            'songtitle'    => (string)($s['yp_currently_playing'] ?? ($s['title'] ?? '')),
            'dj'           => (string)($s['source'] ?? ($s['dj'] ?? 'source')),
            'songurl'      => (string)($s['url'] ?? ''),
            'streamhits'   => (int)($s['streamhits'] ?? 0),
            'backupstatus' => 0,
            'streamlisted' => (int)($s['streamlisted'] ?? 0),
            'streamlistederror' => (int)($s['streamlistederror'] ?? 0),
            'streampath'   => '/' . $mount_key,
            'bitrate'      => $bitrate_raw,
            'samplerate'   => $samplerate_raw,
            'channels'     => $channels_raw,
            'content'      => (string)($s['content-type'] ?? ($s['content_type'] ?? 'audio/mpeg')),
            'audio_info'   => $audio_info,
            'yp_currently_playing' => (string)($s['yp_currently_playing'] ?? ''),
            'listener_count' => (int)($s['listeners'] ?? 0),
            'outgoing_kbitrate' => (int)($s['outgoing_kbitrate'] ?? 0),
            'incoming_bitrate' => (int)($s['incoming_bitrate'] ?? 0),
        ];
    }
    return $cache;
}

function get_icecast_mount_data($mount) {
    $ice = get_icecast_mounts();
    $m = trim(strtolower($mount), '/');
    if (isset($ice['_mounts'][$m])) return $ice['_mounts'][$m];
    foreach ($ice['_mounts'] as $mk => $mv) {
        if (strtolower($mk) === $m) return $mv;
    }
    return null;
}

function get_icecast_server() {
    $ice = get_icecast_mounts();
    return $ice['_server'];
}

// =============================================================
// DETECCIÓN DJ EN VIVO (conectado al input.harbor de Liquidsoap)
//  - El DJ se conecta por TCP al puerto DJ (input.harbor /live).
//  - Mientras está conectado, `ss` muestra una conexión ESTAB
//    hacia ese puerto desde una IP remota (no 127.0.0.1).
//  - Sirve tanto en MODO AUTODJ (DJ salta por encima del autodj
//    vía fallback) como en MODO DIRECTA.
// =============================================================
function api_dj_harbor_connected($dj_port) {
    $dj_port = (int)$dj_port;
    if ($dj_port < 1024 || $dj_port > 65535) return false;
    $out = @shell_exec("ss -tn 2>/dev/null");
    if (!is_string($out) || trim($out) === '') return false;
    foreach (preg_split('/\R/', $out) as $line) {
        if (strpos($line, 'ESTAB') === false) continue;
        // formato: ESTAB 0 0 <ip_local>:<puerto> <ip_remota>:<puerto>
        if (preg_match('/\bESTAB\b.*\s+\[?[0-9a-fA-F:.]+\]?:' . $dj_port . '\s+\[?([0-9a-fA-F:.]+)\]?:\d+/', $line, $m)) {
            $peer = $m[1] ?? '';
            if ($peer !== '127.0.0.1' && $peer !== '::1' && $peer !== 'localhost') return true;
        }
    }
    return false;
}

function api_dj_port_for_mount($mount, $radio = null) {
    if (is_array($radio) && !empty($radio['dj_port'])) return (int)$radio['dj_port'];
    $db = file_exists(DB_FILE) ? (json_decode(@file_get_contents(DB_FILE), true) ?: []) : [];
    $needle = strtolower(trim($mount, '/'));
    foreach ($db['radios'] ?? [] as $r) {
        if (strtolower(trim((string)($r['mountpoint'] ?? ''), '/')) === $needle) {
            return (int)($r['dj_port'] ?? 0);
        }
    }
    return 0;
}

function api_dj_is_live($mount, $radio = null) {
    return api_dj_harbor_connected(api_dj_port_for_mount($mount, $radio));
}

// ——— Peak listeners persistente (simulado, ya que icecast reinicia peak al reconectar):
function api_stats_update_peak($mount, $listeners_now) {
    $state_dir = rp_dir_or_nextsong_state($mount);
    if (!$state_dir) return (int)$listeners_now;
    $pfx = $state_dir . '/.stats_peak_';
    $peak_file = $pfx . 'current.txt';
    $peak_hour_file = $pfx . 'hour.txt';
    $peak_daily_file = $pfx . 'daily.txt';
    $now_file  = $pfx . 'last_peak_ts.txt';
    $peak_all = (int)@file_get_contents($peak_file);
    if ($listeners_now > $peak_all) { @file_put_contents($peak_file, (string)$listeners_now); @chmod($peak_file, 0664); $peak_all = $listeners_now; }
    $ts = time();
    $hour_k = date('YmdH', $ts);
    $day_k  = date('Ymd', $ts);
    $last_hour_k = (string)@file_get_contents($pfx.'hour_k.txt');
    $last_day_k  = (string)@file_get_contents($pfx.'day_k.txt');
    if ($last_hour_k !== $hour_k) {
        @file_put_contents($pfx.'hour_k.txt', $hour_k); @chmod($pfx.'hour_k.txt', 0664);
        @file_put_contents($peak_hour_file, (string)$listeners_now); @chmod($peak_hour_file, 0664);
    } else {
        $ph = (int)@file_get_contents($peak_hour_file);
        if ($listeners_now > $ph) { @file_put_contents($peak_hour_file, (string)$listeners_now); @chmod($peak_hour_file, 0664); }
    }
    if ($last_day_k !== $day_k) {
        @file_put_contents($pfx.'day_k.txt', $day_k); @chmod($pfx.'day_k.txt', 0664);
        @file_put_contents($peak_daily_file, (string)$listeners_now); @chmod($peak_daily_file, 0664);
    } else {
        $pd = (int)@file_get_contents($peak_daily_file);
        if ($listeners_now > $pd) { @file_put_contents($peak_daily_file, (string)$listeners_now); @chmod($peak_daily_file, 0664); }
    }
    return (int)$peak_all;
}
// helper path para stats peak (si no existe estado de nextsong, usar base media dir)
if (!function_exists('rp_dir_or_nextsong_state')) {
    function rp_dir_or_nextsong_state($mount) {
        global $__media_base_for_stats;
        if (empty($__media_base_for_stats)) {
            $dir = dirname(__FILE__) . '/data/radios/';
            if (is_dir($dir)) $__media_base_for_stats = $dir;
            else { $__media_base_for_stats = false; }
            if (is_dir('/var/media/radios/' . $mount . '/.nextsong_state/')) {
                return '/var/media/radios/' . $mount . '/.nextsong_state/';
            }
            if ($__media_base_for_stats) {
                $cand = $__media_base_for_stats . $mount . '/.nextsong_state/';
                if (is_dir($cand) || @mkdir($cand, 0775, true)) return $cand;
            }
            return null;
        }
        if (is_dir('/var/media/radios/' . $mount . '/.nextsong_state/')) {
            return '/var/media/radios/' . $mount . '/.nextsong_state/';
        }
        if ($__media_base_for_stats) {
            $cand = $__media_base_for_stats . $mount . '/.nextsong_state/';
            if (is_dir($cand) || @mkdir($cand, 0775, true)) return $cand;
        }
        return null;
    }
}


function radio_status_summary($mount, $pid_file) {
    $pid_alive = is_autodj_running($pid_file);
    $ice = get_icecast_mounts();
    $m = null;
    $mk = strtolower(trim($mount, '/'));
    if (isset($ice['_mounts'][$mk])) $m = $ice['_mounts'][$mk];
    elseif (is_array($ice) && isset($ice[$mk])) { $m = $ice[$mk]; } // compat antigua
    $online = (bool)($m ? $m['online'] : false);
    $listeners = (int)($m ? ($m['listeners'] ?? 0) : 0);
    if (!$online && $pid_alive) {
        $online = true;
    }
    return [
        'running'   => $pid_alive,
        'online'    => $online,
        'listeners' => $listeners,
    ];
}

// 4. MOTOR LIQUIDSOAP
if ($action === 'toggle') {
    $cmd = $_POST['cmd'] ?? '';
    $response = ['success' => true, 'cmd' => $cmd];
    if ($cmd === 'start') {
        $st = start_autodj($data_file, $default_data, $base_dir, $radio, $mount, $encoder_pass, $pid_file, $liq_file);
        $response = array_merge($response, $st);
        $response['success'] = $st['running'];
        if (!$st['running'] && empty($st['info']['errors'])) {
            $response['error'] = 'Liquidsoap no pudo arrancar (revisa liquidsoap_stderr.log en la carpeta de la radio)';
        }
    } elseif ($cmd === 'stop') {
        $response['stop_info'] = stop_autodj($pid_file);
        usleep(200000);
    } elseif ($cmd === 'restart') {
        $response['stop_info'] = stop_autodj($pid_file);
        usleep(200000);
        $st = start_autodj($data_file, $default_data, $base_dir, $radio, $mount, $encoder_pass, $pid_file, $liq_file);
        $response = array_merge($response, $st);
        $response['success'] = $st['running'];
    }
    $st_sum = radio_status_summary($mount, $pid_file);
    $response['running'] = $st_sum['running'];
    $response['icecast'] = ['online' => $st_sum['online'], 'listeners' => $st_sum['listeners']];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. ACCIONES LEGACY DE CONTROL (compatibilidad con panel.php toggleAutoDJ)
if ($action === 'status') {
    $st = radio_status_summary($mount, $pid_file);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'running' => $st['running'],
        'icecast' => ['online' => $st['online'], 'listeners' => $st['listeners']],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_listener_stats') {
    // Estadísticas de oyentes de esta radio (países / dispositivos / conexiones por período).
    // Se ingieren los access logs de nginx (máx. 1 vez por minuto) y se devuelve el payload.
    require_once __DIR__ . '/estadisticas_lib.php';
    if (!function_exists('est_periods_payload')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Librería de estadísticas no disponible']);
        exit;
    }
    $st_ing = ['ok' => false, 'error' => 'no ingest'];
    $stf = est_state_path();
    if (!is_file($stf) || (time() - (int)@filemtime($stf)) > 60) {
        try { $st_ing = est_ingest_run(); } catch (\Throwable $e) { $st_ing = ['ok' => false, 'error' => $e->getMessage()]; }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(
        ['success' => true, 'ingested' => $st_ing],
        est_periods_payload($mount)
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'stop') {
    $stop = stop_autodj($pid_file);
    usleep(200000);
    $st = radio_status_summary($mount, $pid_file);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'AutoDJ detenido',
        'stop_info' => $stop,
        'running' => $st['running'],
        'icecast' => ['online' => $st['online'], 'listeners' => $st['listeners']],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'restart') {
    $stop = stop_autodj($pid_file);
    usleep(200000);
    $start = start_autodj($data_file, $default_data, $base_dir, $radio, $mount, $encoder_pass, $pid_file, $liq_file);
    $st = radio_status_summary($mount, $pid_file);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $start['running'],
        'message' => $start['running'] ? 'AutoDJ reiniciado OK' : 'Fallo al reiniciar',
        'stop_info' => $stop,
        'start_info' => $start,
        'running' => $st['running'],
        'icecast' => ['online' => $st['online'], 'listeners' => $st['listeners']],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'start') {
    $start = start_autodj($data_file, $default_data, $base_dir, $radio, $mount, $encoder_pass, $pid_file, $liq_file);
    $st = radio_status_summary($mount, $pid_file);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $start['running'],
        'message' => $start['running'] ? 'AutoDJ iniciado OK' : 'Fallo al iniciar',
        'start_info' => $start,
        'running' => $st['running'],
        'icecast' => ['online' => $st['online'], 'listeners' => $st['listeners']],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== METADATOS & CARÁTULAS ======

function api_np_state_dir($base_dir) {
    $d = "{$base_dir}/.nextsong_state";
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}
function api_np_default_cover_abs($base_dir) { return api_np_state_dir($base_dir) . '/default_cover.jpg'; }
function api_np_current_file($base_dir)   { return api_np_state_dir($base_dir) . '/current_song.json'; }
function api_np_history_file($base_dir)   { return api_np_state_dir($base_dir) . '/history.json'; }

function api_pg_config_file($base_dir)     { return api_np_state_dir($base_dir) . '/page_config.json'; }
function api_pg_logo_abs($base_dir)        { return api_np_state_dir($base_dir) . '/page_logo.jpg'; }
function api_pg_bg_abs($base_dir)          { return api_np_state_dir($base_dir) . '/page_bg.jpg'; }
function api_pg_default_config() {
    return [
        'title'              => null,      // null => usar nombre_emisora desde database.json
        'subtitle'           => 'En Vivo 24/7',
        'accent_color'       => '#22c55e', // verde radio por defecto (similar al ejemplo)
        'bg_overlay_opacity' => 75,        // 0-100
        'primary_text_color' => '#ffffff',
        'show_history'       => true,
        'history_count'      => 7,         // Nº canciones en historial player público (5/7/10/15/20)
        'show_share'         => true,
        'show_logo_when_cover' => false,   // si true, muestra logo SIEMPRE; si false, reemplaza logo por cover
        'show_staff'         => true,      // mostrar sección Staff en la página pública
        'show_programacion'  => true,      // mostrar sección Programación en la página pública
        // ====== NUEVOS COLORES: ======
        'bg_color_base'       => '#0b1226', // color base fondo (se ve si NO hay imagen de fondo subida, capa base)
        'header_bg_color'    => '#111a2e', // color fondo cabecera (barra superior logo+redes)
        'main_bg_color'      => '#0f172a', // color fondo de las cards (player+siguiente+historial)
        'footer_bg_color'    => '#111a2e', // color fondo pie ("creada para amantes..")
        // ====== NUEVAS OPACIDADES TRANSPARENCIA (rango 5..100 %, defaults igual que antes 0.92/0.88 => backward compat)
        'header_bg_opacity'  => 92,   // cabecera transparencia (antes 0.92 = 92%) — rango 5..100
        'main_bg_opacity'    => 88,   // cards interior (antes app-container 0.88 = 88%, cards 0.92 usan +4%) — rango 5..100
        'footer_bg_opacity'  => 92,   // footer (antes 0.92 = 92%) — rango 5..100
        'website_url'        => '',
        'facebook_url'       => '',
        'whatsapp_url'       => '',
        'share_url'          => '',   // URL que comparte el botón "Compartir" (vacío = la de por defecto)
        // ====== CAMPOS LANDING (contacto / nosotros / más redes): ======
        'email_contacto'     => '',
        'nosotros'           => '',
        'nosotros_html'      => false,
        // ====== TÉRMINOS Y CONDICIONES (página propia del menú del pie): ======
        'terminos'           => '',
        'terminos_html'      => false,
        // ====== COLORES DE TEXTO POR ZONA (vacío = default del tema): ======
        'header_text_color'  => '',
        'header_icon_color'  => '',
        'footer_text_color'  => '',
        'app_text_color'     => '',
        'app_title_color'    => '',
        'app_subtitle_color' => '',
        'c_label_color'      => '',
        'station_name_color' => '',
        'song_title_color'   => '',
        'player_btn_color'   => '',   // botón Play/Pausa (vacío = color acento)
        'player_vol_color'   => '',   // barra de volumen (vacío = color acento)
        'store_btn_bg'       => '',   // fondo de botones App Store / Google Play
        'store_btn_text'     => '',   // letras de los botones de app
        'store_btn_icon'     => '',   // iconos de los botones de app
        'wa_btn_bg'          => '',   // fondo del botón WhatsApp
        'wa_btn_text'        => '',   // letras del botón WhatsApp
        'clock_time_color'   => '',   // letra de la hora del reloj (vacío = #ffffff)
        'clock_date_color'   => '',   // letra de la fecha (vacío = #94a3b8)
        'instagram_url'      => '',
        'tiktok_url'         => '',
        'youtube_url'        => '',
        'x_url'              => '',
        // ====== DESCARGA DE LA APP (tiendas): ======
        'appstore_url'       => '',
        'playstore_url'      => '',
        // ====== STAFF (equipo) y PROGRAMACIÓN semanal (LUN..DOM) de la landing: ======
        'staff'              => [],
        'programacion'       => [],
        // ====== SECCIONES VINCULADAS (patrocinadores / radios / escuchanos): ======
        'show_patrocinadores'=> false,
        'patrocinadores'     => [],
        'show_radios'        => false,
        'radios'             => [],
        'show_escuchanos'    => false,
        'escuchanos'         => [],
        // ====== PERSONALIZACIÓN POR SECCIÓN (vacío = color actual de la plantilla) ======
        'sec_staff_bg'          => '', 'sec_staff_title'          => '', 'sec_staff_icon'          => true,
        'sec_staff_card_bg'     => '', 'sec_staff_card_border'    => '', 'sec_staff_card_text'     => '', 'sec_staff_role' => '',
        'sec_programacion_bg'   => '', 'sec_programacion_title'   => '', 'sec_programacion_icon'   => true,
        'sec_programacion_card_bg'  => '', 'sec_programacion_card_border' => '', 'sec_programacion_card_text' => '',
        'sec_patrocinadores_bg' => '', 'sec_patrocinadores_title' => '', 'sec_patrocinadores_icon' => true,
        'sec_patrocinadores_card_bg'  => '', 'sec_patrocinadores_card_border' => '', 'sec_patrocinadores_card_text' => '',
        'sec_radios_bg'         => '', 'sec_radios_title'         => '', 'sec_radios_icon'         => true,
        'sec_radios_card_bg'    => '', 'sec_radios_card_border'   => '', 'sec_radios_card_text'    => '',
        'sec_escuchanos_bg'     => '', 'sec_escuchanos_title'     => '', 'sec_escuchanos_icon'     => true,
        'sec_escuchanos_card_bg'    => '', 'sec_escuchanos_card_border' => '', 'sec_escuchanos_card_text' => '',
        // Opacidades del fondo de sección y de cards (5..100 %)
        'sec_staff_bg_opacity'          => 60,  'sec_staff_card_bg_opacity'          => 100,
        'sec_programacion_bg_opacity'   => 55,  'sec_programacion_card_bg_opacity'   => 100,
        'sec_patrocinadores_bg_opacity' => 55,  'sec_patrocinadores_card_bg_opacity' => 100,
        'sec_radios_bg_opacity'         => 55,  'sec_radios_card_bg_opacity'         => 100,
        'sec_escuchanos_bg_opacity'     => 50,  'sec_escuchanos_card_bg_opacity'     => 100,
        'meta'               => ['created_at' => null, 'updated_at' => null],
    ];
}
function api_pg_read_config($base_dir) {
    $cfg = api_pg_default_config();
    $f = api_pg_config_file($base_dir);
    if (is_file($f)) {
        $j = @json_decode(@file_get_contents($f), true);
        if (is_array($j)) {
            foreach ($cfg as $k => $v) {
                if (array_key_exists($k, $j)) { $cfg[$k] = $j[$k]; }
            }
            if (!is_array($cfg['meta'])) $cfg['meta'] = api_pg_default_config()['meta'];
        }
    }
    return $cfg;
}
function api_pg_logo_url($mount, $base_dir, $absolute=false) {
    $abs = api_pg_logo_abs($base_dir);
    $qs = '';
    if (is_file($abs)) $qs = '&t=' . @filemtime($abs);
    $rel = 'autodj_api.php?action=serve_page_logo&mount=' . rawurlencode($mount) . $qs;
    if ($absolute) return api_np_to_absolute_url($rel);
    return $rel;
}
function api_pg_bg_url($mount, $base_dir, $absolute=false) {
    $abs = api_pg_bg_abs($base_dir);
    $qs = '';
    if (is_file($abs)) $qs = '&t=' . @filemtime($abs);
    $rel = 'autodj_api.php?action=serve_page_bg&mount=' . rawurlencode($mount) . $qs;
    if ($absolute) return api_np_to_absolute_url($rel);
    return $rel;
}

// ======================================================================
// STAFF + PROGRAMACIÓN semanal (landing) — saneo y helpers
// ======================================================================
/** Texto plano de una línea (sin HTML): control chars + \r\n → espacio, colapsa espacios, trunca. */
function api_pg_clean_text($v, $max) {
    $v = (string)$v;
    $v = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\r\n]+/u', ' ', $v);
    $v = trim((string)preg_replace('/\s+/u', ' ', $v));
    if (function_exists('mb_strlen')) { if (mb_strlen($v, 'UTF-8') > $max) $v = mb_substr($v, 0, $max, 'UTF-8'); }
    elseif (strlen($v) > $max) { $v = substr($v, 0, $max); }
    return $v;
}
function api_pg_valid_hhmm($s) {
    return is_string($s) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $s) === 1;
}
/** Sanea el array staff (máx 12). Reemplazo total: la fuente de verdad es el editor. */
function api_pg_sanitize_staff($arr) {
    $out = [];
    if (!is_array($arr)) return $out;
    foreach (array_slice($arr, 0, 12) as $m) {
        if (!is_array($m)) continue;
        $nombre = api_pg_clean_text($m['nombre'] ?? '', 80);
        $cargo  = api_pg_clean_text($m['cargo']  ?? '', 60);
        $desc   = api_pg_clean_text($m['desc']   ?? '', 200);
        $id     = preg_match('/^[A-Za-z0-9]{6,32}$/', (string)($m['id'] ?? '')) ? (string)$m['id'] : '';
        $foto   = ($id !== '' && (string)($m['foto'] ?? '') === $id . '.jpg') ? $id . '.jpg' : '';
        if ($nombre === '' && $cargo === '' && $desc === '' && $foto === '') continue; // fila vacía
        $out[] = ['id' => $id, 'foto' => $foto, 'nombre' => $nombre, 'cargo' => $cargo, 'desc' => $desc];
    }
    return $out;
}
/**
 * Sanea la lista de programas semanales. Cada programa = {dias:[1..7] LUN..DOM,
 * inicio, fin (HH:MM con fin>inicio), titulo (req), conductor}. Acepta el formato
 * legacy {dia:n} (→ dias:[n]) y {desc} (→ conductor). Máx 60 programas.
 * Ordena por (primer día, inicio).
 */
function api_pg_sanitize_programacion($arr) {
    $out = [];
    if (!is_array($arr)) return $out;
    foreach (array_slice($arr, 0, 60) as $b) {
        if (!is_array($b)) continue;
        $dias = [];
        if (isset($b['dias']) && is_array($b['dias'])) {
            foreach ($b['dias'] as $dd) {
                $n = (int)$dd;
                if ($n >= 1 && $n <= 7 && !in_array($n, $dias, true)) $dias[] = $n;
            }
        } elseif (isset($b['dia'])) {
            $n = (int)$b['dia'];
            if ($n >= 1 && $n <= 7) $dias = [$n];
        }
        if (!$dias) continue;
        sort($dias);
        $ini = trim((string)($b['inicio'] ?? ''));
        $fin = trim((string)($b['fin'] ?? ''));
        if (!api_pg_valid_hhmm($ini) || !api_pg_valid_hhmm($fin)) continue;
        if ($fin <= $ini) continue;
        $titulo = api_pg_clean_text($b['titulo'] ?? '', 90);
        $conductor = api_pg_clean_text($b['conductor'] ?? ($b['desc'] ?? ''), 160);
        if ($titulo === '') continue; // el título es obligatorio (el conductor es opcional)
        $out[] = ['dias' => $dias, 'inicio' => $ini, 'fin' => $fin, 'titulo' => $titulo, 'conductor' => $conductor];
    }
    usort($out, function ($a, $b) {
        $ad = $a['dias'][0] ?? 8; $bd = $b['dias'][0] ?? 8;
        return [$ad, $a['inicio']] <=> [$bd, $b['inicio']];
    });
    return $out;
}
/**
 * Sanea listas de enlaces con logo (patrocinadores / radios / escuchanos).
 * Cada item = {id, logo ''|<id>.jpg, nombre≤120, link URL https}. Máx 20 por lista.
 */
function api_pg_sanitize_links($arr) {
    $out = [];
    if (!is_array($arr)) return $out;
    foreach (array_slice($arr, 0, 20) as $it) {
        if (!is_array($it)) continue;
        $id = preg_match('/^[A-Za-z0-9]{6,32}$/', (string)($it['id'] ?? '')) ? (string)$it['id'] : '';
        $logo = ($id !== '' && (string)($it['logo'] ?? '') === $id . '.jpg') ? $id . '.jpg' : '';
        $nombre = api_pg_clean_text($it['nombre'] ?? '', 120);
        $link = trim((string)($it['link'] ?? ''));
        if ($link !== '') {
            if (stripos($link, 'http://') !== 0 && stripos($link, 'https://') !== 0) $link = 'https://' . $link;
            $hp = @parse_url($link);
            if (!$hp || empty($hp['host'])) $link = '';
        }
        $link = api_pg_clean_text($link, 500);
        if ($nombre === '') continue; // nombre y enlace obligatorios si el elemento existe
        if ($link === '') continue;
        $out[] = ['id' => $id, 'logo' => $logo, 'nombre' => $nombre, 'link' => $link];
    }
    return $out;
}
/** Decora listas de enlaces con logo_set/logo_url por item (para el editor). */
function api_pg_decorate_links($cfg, $mount, $base_dir) {
    foreach (['patrocinadores', 'radios', 'escuchanos'] as $key) {
        if (!is_array($cfg[$key] ?? null)) continue;
        foreach ($cfg[$key] as $i => $it) {
            $abs = api_pg_staff_photo_abs($base_dir, $it['id'] ?? '');
            $set = ($abs !== '' && is_file($abs));
            $cfg[$key][$i]['logo_set'] = $set;
            $cfg[$key][$i]['logo_url'] = $set ? api_pg_staff_photo_url($mount, $base_dir, $it['id'], true) : '';
        }
    }
    return $cfg;
}
// --- Fotos de staff (ficheros <stateDir>/staff/<id>.jpg) ---
function api_pg_staff_dir($base_dir) {
    $d = api_np_state_dir($base_dir) . '/staff';
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}
function api_pg_staff_photo_abs($base_dir, $id) {
    $id = preg_replace('/[^A-Za-z0-9]/', '', (string)$id);
    return ($id === '') ? '' : api_pg_staff_dir($base_dir) . '/' . $id . '.jpg';
}
function api_pg_staff_photo_url($mount, $base_dir, $id, $absolute = false) {
    $abs = api_pg_staff_photo_abs($base_dir, $id);
    $qs  = (is_file($abs)) ? '&t=' . @filemtime($abs) : '';
    $rel = 'autodj_api.php?action=serve_staff_photo&mount=' . rawurlencode($mount) . '&id=' . rawurlencode($id) . $qs;
    return $absolute ? api_np_to_absolute_url($rel) : $rel;
}
/** Añade foto_set/foto_url por miembro (para el editor: previews). */
function api_pg_decorate_staff($cfg, $mount, $base_dir) {
    if (is_array($cfg['staff'] ?? null)) {
        foreach ($cfg['staff'] as $i => $m) {
            $abs = api_pg_staff_photo_abs($base_dir, $m['id'] ?? '');
            $set = ($abs !== '' && is_file($abs));
            $cfg['staff'][$i]['foto_set'] = $set;
            $cfg['staff'][$i]['foto_url'] = $set ? api_pg_staff_photo_url($mount, $base_dir, $m['id'], true) : '';
        }
    }
    return $cfg;
}
/** GC: borra jpg de staff/ no referenciados por el array staff guardado. */
function api_pg_gc_staff_photos($base_dir, $staff) {
    $dir = api_np_state_dir($base_dir) . '/staff';
    if (!is_dir($dir)) return;
    $ref = [];
    if (is_array($staff)) {
        foreach ($staff as $m) {
            if (!empty($m['foto'])) $ref[$m['foto']] = true;
        }
    }
    foreach (glob($dir . '/*.jpg') ?: [] as $f) {
        if (!isset($ref[basename($f)])) { @unlink($f); }
    }
}

function api_np_site_base() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $scheme = !empty($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME']
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    if ($host !== '') {
        $cached = $scheme . '://' . $host . '/';
    } else {
        $cached = null;
    }
    return $cached;
}
function api_np_to_absolute_url($relOrAbs) {
    $s = (string)$relOrAbs;
    if ($s === '' || $s === null) return $s;
    if (stripos($s, 'http://') === 0 || stripos($s, 'https://') === 0 || stripos($s, 'data:') === 0) {
        return $s;
    }
    $base = api_np_site_base();
    if ($base === null) return $s;
    $s = ltrim($s, '/');
    return $base . $s;
}
function api_np_default_cover_url($mount, $base_dir, $absolute = false) {
    $abs = api_np_default_cover_abs($base_dir);
    $qs = '';
    if (is_file($abs)) $qs = '&t=' . @filemtime($abs);
    $rel = 'autodj_api.php?action=serve_default_cover&mount=' . rawurlencode($mount) . $qs;
    if ($absolute) return api_np_to_absolute_url($rel);
    return $rel;
}

// ======================================================================
// 🧼 Cortafuegos limpiador basura ID3 / caracteres raros (igual que next_song.php ns_clean_tag_string)
// Nota: se define ANTES de api_get_now_playing_payload() porque esa función
// lo usa al sincronizar con el songtitle real de Icecast.
// ======================================================================
if (!function_exists('api_clean_tag_string')) {
    function api_clean_tag_string($s) {
        $s = (string)$s;
        if ($s === '') return '';
        if (!preg_match('//u', $s)) {
            if (function_exists('iconv')) { $t = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $s); if ($t !== '' && $t !== false && preg_match('//u', $t)) $s = $t; }
            elseif (function_exists('utf8_encode')) { $t = @utf8_encode($s); if ($t !== '' && preg_match('//u', $t)) $s = $t; }
        }
        $s = str_replace(["\0","\xFF\xFE","\xFE\xFF","\xEF\xBB\xBF"], ' ', $s);
        if (str_starts_with($s, "\xFF\xFE") || str_starts_with($s, "\xFE\xFF")) $s = substr($s, 2);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        $frames = '(?:TRCK|TPE1|TCON|TLEN|TALB|TIT2|TYER|COMM|USLT|WXXX|TCOP|TPUB|TENC|TOPE|TCOM|TEXT|TLAN|TPE2|TPE3|TPE4|TPOS|TDRC|TDOR|TORY|APIC|PIC|GEOB|PRIV|RVA2|EQU2|RVRB|IPLS|MCDI|TKEY|TMOO|TOAL|TOFN|TOLY|TOWN|TPA|TPB|TRDA|TRSN|TRSO|TSIZ|TSRC|TSS1|TXXX|UFID|USER|WCOP|WOAF|WOAR|WOAS|WORS|WPAY|WPUB|SEEK|ASPI|BIN|MLLT|POSS|RBUF|SYLT|SYTC)';
        $s = preg_replace('/\b' . $frames . '\b/u', '', $s);
        $s = preg_replace('/' . $frames . '[\x00-\x1F]{1,8}/u', '', $s);
        $s = preg_replace('/' . $frames . '[A-Z]{3,}/u', '', $s);
        $clean = ''; $n = strlen($s); $i = 0;
        while ($i < $n) {
            $o = ord($s[$i]);
            if ($o < 0x80) {
                if ($o === 9 || ($o >= 32 && $o <= 126)) $clean .= $s[$i];
                $i++; continue;
            }
            $seq = 1;
            if (($o & 0xE0) === 0xC0) $seq = 2;
            elseif (($o & 0xF0) === 0xE0) $seq = 3;
            elseif (($o & 0xF8) === 0xF0) $seq = 4;
            else { $i++; continue; }
            if ($i + $seq > $n) { $i++; continue; }
            $chunk = substr($s, $i, $seq);
            if (!preg_match('//u', $chunk)) { $i++; continue; }
            $cp = 0;
            if ($seq === 2) $cp = (($o & 0x1F) << 6) | (ord($s[$i+1]) & 0x3F);
            elseif ($seq === 3) $cp = (($o & 0x0F) << 12) | ((ord($s[$i+1]) & 0x3F) << 6) | (ord($s[$i+2]) & 0x3F);
            else $cp = (($o & 0x07) << 18) | ((ord($s[$i+1]) & 0x3F) << 12) | ((ord($s[$i+2]) & 0x3F) << 6) | (ord($s[$i+3]) & 0x3F);
            $ok = false;
            if ($cp >= 0xA0 && $cp <= 0x24FF) $ok = true;
            elseif ($cp >= 0x2500 && $cp <= 0x27BF) $ok = true;
            elseif ($cp >= 0x2C00 && $cp <= 0x2E5F) $ok = true;
            elseif ($cp >= 0x3000 && $cp <= 0x303F) $ok = true;
            elseif ($cp >= 0x3040 && $cp <= 0x9FFF) $ok = true;
            elseif ($cp >= 0xAC00 && $cp <= 0xD7AF) $ok = true;
            elseif ($cp >= 0xE000 && $cp <= 0xF8FF) $ok = true;
            elseif ($cp >= 0xF900 && $cp <= 0xFAFF) $ok = true;
            elseif ($cp >= 0xFB00 && $cp <= 0xFDFF) $ok = true;
            elseif ($cp >= 0xFE30 && $cp <= 0xFE4F) $ok = true;
            elseif ($cp >= 0x1F000 && $cp <= 0x1FFFF) $ok = true;
            if ($ok) $clean .= $chunk;
            $i += $seq;
        }
        $clean = preg_replace('/[ \t]{2,}/u', ' ', $clean);
        $clean = preg_replace('/\s+([-\/])\s+/u', '$1', $clean);
        $clean = preg_replace('/\s{2,}/u', ' ', $clean);
        $clean = trim($clean);
        if ($clean === '' && trim($s) !== '') {
            $fb = preg_replace('/[\x00-\x1F\x7F\x80-\xFF]/u', '', $s);
            $fb = trim(preg_replace('/\s{2,}/u', ' ', $fb));
            if ($fb !== '') return $fb;
        }
        return $clean;
    }
}

if (!function_exists('api_live_cover_for_title')) {
function api_live_cover_for_title($mount, $base_dir, $rawTitle) {
    $rawTitle = trim((string)$rawTitle);
    if ($rawTitle === '') return '';
    $stateDir = api_np_state_dir($base_dir);
    $cacheDir = $stateDir . '/id3_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $artist = ''; $title = $rawTitle;
    if (preg_match('/^(.*?)\s+-\s+(.+)$/u', $rawTitle, $mm)) { $artist = trim($mm[1]); $title = trim($mm[2]); }
    if ($title === '') return '';
    $key = sha1(strtolower(trim($artist) . '|' . trim($title)));
    $cacheFile = $cacheDir . '/live_' . $key . '.jpg';
    $serve = function () use ($mount, $cacheFile) {
        $t = is_file($cacheFile) ? @filemtime($cacheFile) : 0;
        return api_np_to_absolute_url('autodj_api.php?action=serve_cached_cover&mount=' . rawurlencode($mount) . '&f=' . rawurlencode(basename($cacheFile)) . '&t=' . $t);
    };
    if (is_file($cacheFile) && @filesize($cacheFile) > 200) return $serve();
    $markerFile = $cacheDir . '/live_task_' . $key . '.json';
    if (is_file($markerFile)) {
        $mt = @filemtime($markerFile);
        if ($mt !== false && (time() - $mt) < 180) return ''; // búsqueda en curso o fallo reciente
    }
    @file_put_contents($markerFile, json_encode(['at' => time()]));
    $art = '';
    $fallback_art = '';
    $term = rawurlencode(trim($artist . ' ' . $title));
    $ch = @curl_init('https://itunes.apple.com/search?term=' . $term . '&media=music&entity=song&limit=1');
    if ($ch) {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SuperRadio-AutoDJ/1.0');
        $resp = @curl_exec($ch);
        @curl_close($ch);
        if (is_string($resp) && $resp !== '') {
            $j = @json_decode($resp, true);
            if (is_array($j)) {
                $norm = function ($x) { return trim(preg_replace('/[^a-z0-9]+/u', ' ', strtolower((string)$x))); };
                $tq = $norm($title);
                $aq = $norm($artist);
                foreach (($j['results'] ?? []) as $r) {
                    if (!is_array($r)) continue;
                    $artwork = (string)($r['artworkUrl100'] ?? '');
                    if ($artwork === '') continue;
                    if ($fallback_art === '') $fallback_art = $artwork;
                    // Con "solo título" también se acepta el primero con arte, pero se
                    // prioriza un resultado cuyo nombre de canción coincida con el título.
                    $tN = $norm($r['trackName'] ?? '');
                    $aN = $norm(($r['artistName'] ?? '') . ' ' . ($r['collectionName'] ?? ''));
                    $titleOk = ($tq === '' || $tN === '' || strpos($tN, $tq) !== false || strpos($tq, $tN) !== false);
                    $artistOk = ($aq === '' || $aN === '' || strpos($aN, $aq) !== false || strpos($aq, $aN) !== false);
                    if ($titleOk && $artistOk) { $art = $artwork; break; }
                }
                if ($art === '') $art = $fallback_art;
            }
        }
    }
    if ($art !== '') {
        $art = str_replace('100x100', '300x300', $art);
        $ch2 = @curl_init($art);
        if ($ch2) {
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
            $img = @curl_exec($ch2);
            @curl_close($ch2);
            if (is_string($img) && strlen($img) > 200) {
                @file_put_contents($cacheFile, $img);
                @chmod($cacheFile, 0664);
                if (is_file($cacheFile) && @filesize($cacheFile) > 200) {
                    @unlink($markerFile);
                    // Poda básica: máx ~80 carátulas live
                    $lives = glob($cacheDir . '/live_*.jpg') ?: [];
                    if (count($lives) > 80) {
                        usort($lives, fn($a, $b) => @filemtime($a) - @filemtime($b));
                        foreach (array_slice($lives, 0, count($lives) - 60) as $old) { @unlink($old); }
                    }
                    return $serve();
                }
            }
        }
    }
    @file_put_contents($markerFile, json_encode(['at' => time()])); // reintentar en 3 min
    return '';
}
}

function api_get_now_playing_payload($mount, $base_dir, $radio = null) {
    $stateDir = api_np_state_dir($base_dir);
    $cfile = api_np_current_file($base_dir);
    $hfile = api_np_history_file($base_dir);
    $current = null;
    if (is_file($cfile)) {
        $j = @json_decode(@file_get_contents($cfile), true);
        if (is_array($j)) $current = $j;
    }
    $history = [];
    if (is_file($hfile)) {
        $j = @json_decode(@file_get_contents($hfile), true);
        if (is_array($j)) $history = array_values($j);
    }
    $defUrl = api_np_default_cover_url($mount, $base_dir, true);

    // ============ Leer config página PÚBLICA para HISTORY_COUNT personalizado de esta radio:
    $pgHistoryCount = 7;
    $pgCfgFile = api_pg_config_file($base_dir);
    if (is_file($pgCfgFile)) {
        $pgArr = @json_decode(@file_get_contents($pgCfgFile), true);
        if (is_array($pgArr) && isset($pgArr['history_count'])) {
            $hcTmp = (int)$pgArr['history_count'];
            if ($hcTmp < 1) $hcTmp = 7;
            if ($hcTmp > 20) $hcTmp = 20;
            $pgHistoryCount = $hcTmp;
        }
    }
    // Truncar a lo que pida la radio + margen *2 para findIndex/otros usos (stats, app externa, etc)
    $maxHistorySend = max(20, (int)($pgHistoryCount * 2));

    if ($current === null) {
        if ($radio && is_array($radio)) {
            $isLive = !empty($radio['modo']) && ($radio['modo'] === 'directa');
            $plDef = $isLive ? 'Modo Directa — Señal de espera lista para DJ en vivo' : 'AutoDJ — Cargando primera canción...';
            $current = [
                'mount' => $mount, 'title' => $plDef, 'artist' => '', 'album' => '',
                'cover_url' => $defUrl, 'playlist' => 'general', 'mode' => $isLive ? 'directa' : 'general',
                'started_at' => '', 'started_ts' => 0, 'loading' => true,
            ];
        } else {
            $current = [
                'mount' => $mount, 'title' => 'AutoDJ — Cargando primera canción...', 'artist' => '', 'album' => '',
                'cover_url' => $defUrl, 'playlist' => 'general', 'mode' => 'general',
                'started_at' => '', 'started_ts' => 0, 'loading' => true,
            ];
        }
    }
    if (empty($current['cover_url'])) $current['cover_url'] = $defUrl;
    else $current['cover_url'] = api_np_to_absolute_url($current['cover_url']);
    foreach ($history as $idx => $h) {
        if (empty($h['cover_url'])) $history[$idx]['cover_url'] = $defUrl;
        else $history[$idx]['cover_url'] = api_np_to_absolute_url($h['cover_url']);
    }
    $history = array_slice($history, 0, $maxHistorySend);
    $st_sum = function_exists('radio_status_summary') ? radio_status_summary($mount, $GLOBALS['pid_file'] ?? '') : ['running'=>false,'online'=>false,'listeners'=>0];
    $streamUrl = (function () use ($mount) {
        if (!empty($_SERVER['REQUEST_SCHEME']) && !empty($_SERVER['HTTP_HOST'])) {
            return $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($mount, '/');
        }
        return api_np_to_absolute_url('/' . ltrim($mount, '/'));
    })();

    // =========================================================
    // 🎯 playing = CANCIÓN REAL QUE SUENA AHORA MISMO EN EL STREAM ICECAST
    //    (history[0] = "la que ya sonó y la que OYES en el buffer ~30s").
    //    ROOT CAUSE del bug del badge: antes usábamos payload.current = la
    //    SIGUIENTE canción PREPARADA para encolar, NO la que está sonando.
    //    Solución = misma lógica de sincronización que usa radio_page.php:
    //    leemos songtitle que Icecast realmente está transmitiendo y
    //    buscamos la entry del historial que más se le parece. Si no hay
    //    match, fallback a history[0] (última que realmente se envió).
    // =========================================================
    $playing = null;
    $icecast_raw = null;
    $icecast_songtitle = '';
    $match_source_idx = -1;
    try {
        if (function_exists('get_icecast_mounts')) {
            $mountsAll = get_icecast_mounts();
            $mountsList = $mountsAll['_mounts'] ?? [];
            $searchMount = '/' . ltrim($mount, '/');
            foreach ($mountsList as $m) {
                // get_icecast_mounts() guarda la clave como 'mount_key'
                // (y 'streampath'); no existe 'name' aquí.
                $mkey = (string)($m['mount_key'] ?? ($m['name'] ?? ''));
                $mname = '/' . ltrim($mkey, '/');
                if ($mname === $searchMount) {
                    $icecast_raw = $m;
                    $icecast_songtitle = (string)($m['title'] ?? ($m['songtitle'] ?? ''));
                    break;
                }
            }
        }
    } catch (\Throwable $e) { $icecast_raw = null; }

    if (is_string($icecast_songtitle) && $icecast_songtitle !== '' && count($history) > 0) {
        $normIc = trim(preg_replace('/[^a-zA-Z0-9áéíóúüñ¿¡ÁÉÍÓÚÜÑ]/u', ' ', api_clean_tag_string($icecast_songtitle)));
        $normIc = preg_replace('/\s+/u', ' ', $normIc);
        if ($normIc !== '') {
            $bestScore = 0;
            foreach ($history as $hIdx => $h) {
                if (!is_array($h)) continue;
                $cand = trim((string)($h['title'] ?? '')) . ' ' . trim((string)($h['artist'] ?? ''));
                $normCand = preg_replace('/[^a-zA-Z0-9áéíóúüñ¿¡ÁÉÍÓÚÜÑ]/u', ' ', api_clean_tag_string($cand));
                $normCand = preg_replace('/\s+/u', ' ', $normCand);
                if ($normCand === '') continue;
                $score = 0;
                if (strcasecmp($normIc, $normCand) === 0) $score = 10000;
                else {
                    similar_text(strtolower($normIc), strtolower($normCand), $pct);
                    $score = (int)round($pct * 100);
                    if (stripos($normCand, $normIc) !== false || stripos($normIc, $normCand) !== false) $score += 3000;
                }
                if ($score > $bestScore && $score >= 2500) { $bestScore = $score; $match_source_idx = $hIdx; }
            }
            if ($match_source_idx >= 0 && isset($history[$match_source_idx]) && is_array($history[$match_source_idx])) {
                $playing = $history[$match_source_idx];
            }
        }
    }
    if ((!is_array($playing) || empty($playing)) && count($history) > 0 && is_array($history[0])) {
        $playing = $history[0];
    }
    if (!is_array($playing) || empty($playing)) {
        $playing = $current;
    }
    if (is_array($playing) && !isset($playing['icecast_title_match'])) {
        $playing['icecast_title_match'] = ($match_source_idx >= 0);
        if ($match_source_idx >= 0) $playing['history_match_index'] = $match_source_idx;
        if ($icecast_songtitle !== '') $playing['icecast_songtitle'] = $icecast_songtitle;
    }

    $is_live = api_dj_is_live($mount, $radio);
    $liveCoverUrl = '';
    $liveTitle = trim((string)$icecast_songtitle);
    if ($liveTitle !== '') {
        if ($is_live) {
            // 🟢 DJ EN VIVO: lo que suena es la metadata del DJ (songtitle real
            //    de Icecast); el history del autodj queda obsoleto mientras
            //    transmite. Carátula vía iTunes siempre (el DJ no manda arte).
            $playing = [
                'mount' => $mount,
                'title' => $liveTitle,
                'artist' => '',
                'album'  => '',
                'cover_url' => $defUrl,
                'playlist' => 'DJ EN VIVO',
                'mode' => 'dj',
                'started_at' => '',
                'started_ts' => 0,
                'from' => ['live' => true],
                'icecast_title_match' => true,
            ];
            $history = [];
            $needsStreamCover = true;
        } else {
            // 🤖 AUTODJ: misma vía que en vivo SOLO cuando la canción actual no
            //    trae carátula real (incrustada en el MP3 o ya resuelta al
            //    programarse). Así no pisamos arte correcto (p. ej. jingles).
            $pcv = is_array($playing) ? trim((string)($playing['cover_url'] ?? '')) : '';
            $needsStreamCover = ($pcv === '' || $pcv === $defUrl || stripos($pcv, 'serve_default_cover') !== false);
        }
        if ($needsStreamCover) {
            // Carátula vía iTunes según el nombre real del stream (cacheada por canción)
            $liveCoverUrl = api_live_cover_for_title($mount, $base_dir, $liveTitle);
            if ($liveCoverUrl !== '' && is_array($playing)) $playing['cover_url'] = $liveCoverUrl;
        }
    }

    $scheduled_pl_current = is_array($current) && isset($current['scheduled_pl']) && is_string($current['scheduled_pl']) ? $current['scheduled_pl'] : null;
    $real_playlist_now = is_array($playing) && isset($playing['playlist']) && is_string($playing['playlist']) ? $playing['playlist'] : null;
    $scheduled_mismatch = false;
    if (is_string($scheduled_pl_current) && $scheduled_pl_current !== '' && is_string($real_playlist_now) && $real_playlist_now !== '') {
        if (strpos($real_playlist_now, '@INTERCALATOR::') === false && $scheduled_pl_current !== $real_playlist_now) {
            $scheduled_mismatch = true;
        }
    }

    return [
        'mount'   => $mount,
        'radio_id'=> is_array($radio) ? ($radio['id'] ?? null) : null,
        'mode'    => is_array($radio) ? ($radio['modo'] ?? 'autodj') : 'autodj',
        'live_mode' => $is_live,
        'live_banner_text' => $is_live ? 'Radio en vivo' : '',
        'live_cover' => $liveCoverUrl,
        'stream_url' => $streamUrl,
        'default_cover_url' => $defUrl,
        'default_cover_set' => (is_file(api_np_default_cover_abs($base_dir)) || is_file(api_pg_logo_abs($base_dir))),
        'current' => $current,
        'playing' => $playing,
        'scheduled_pl_current' => $scheduled_pl_current,
        'scheduled_mismatch'  => $scheduled_mismatch,
        'icecast' => [
            'connected' => is_array($icecast_raw),
            'songtitle' => $icecast_songtitle,
            'listeners' => is_array($icecast_raw) ? (int)($icecast_raw['listeners'] ?? 0) : (int)($st_sum['listeners'] ?? 0),
            'listener_peak' => is_array($icecast_raw) ? (int)($icecast_raw['listener_peak'] ?? 0) : 0,
            'stream_started' => is_array($icecast_raw) ? (($icecast_raw['stream_started_iso'] ?? null) ?: ($icecast_raw['stream_start'] ?? '')) : '',
            'mount_info' => $icecast_raw,
        ],
        'history' => $history,
        'status'  => [
            'running' => (bool)($st_sum['running'] ?? false),
            'online'  => (bool)($st_sum['online']  ?? false),
            'listeners' => (int)($st_sum['listeners'] ?? 0),
        ],
    ];
}

if ($action === 'get_now_playing') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $payload = api_get_now_playing_payload($mount, $base_dir, $radio ?? null);
    $payload['server_time'] = date('c');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_stats_site_base_url_for_stats(){
    if (!empty($_SERVER['REQUEST_SCHEME']) && !empty($_SERVER['HTTP_HOST'])) {
        return rtrim($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'], '/');
    }
    return 'https://stream.radioscr.com';
}

function api_get_stats_payload($mount, $base_dir, $radio = null) {
    global $pid_file;
    $np = api_get_now_playing_payload($mount, $base_dir, $radio ?? null);
    $ice_server = get_icecast_server();
    $ice_mount  = get_icecast_mount_data($mount);
    $cur = $np['current'] ?? null;
    $current_listeners = (int)(($ice_mount['listeners'] ?? -1) != -1 ? $ice_mount['listeners'] : ($np['status']['listeners'] ?? 0));
    $peak = max(0, $current_listeners);
    $peak_all = api_stats_update_peak($mount, $peak);
    $peak_hour_f = null; $peak_day_f = null;
    $state_dir = function_exists('rp_dir_or_nextsong_state') ? rp_dir_or_nextsong_state($mount) : null;
    if ($state_dir) {
        $pfx = $state_dir . '/.stats_peak_';
        $peak_hour_f = (int)@file_get_contents($pfx . 'hour.txt');
        $peak_day_f  = (int)@file_get_contents($pfx . 'daily.txt');
    }
    $server_version = (string)($ice_server['version'] ?? '');
    if ($server_version === '') $server_version = phpversion();
    $bitrate = (string)($ice_mount['bitrate'] ?? (!empty($radio['bitrate']) ? (string)$radio['bitrate'] : '128'));
    if ($bitrate === '') { $bitrate = '128'; }
    $samplerate = (string)($ice_mount['samplerate'] ?? '44100');
    if ($samplerate === '') $samplerate = '44100';
    $server_genre = (string)($ice_mount['server_genre'] ?? (!empty($radio['server_genre']) ? (string)$radio['server_genre'] : ''));
    $server_url = (string)($ice_mount['server_url'] ?? (!empty($radio['website_url']) ? (string)$radio['website_url'] : (!empty($radio['facebook_url']) ? (string)$radio['facebook_url'] : '')));
    $server_title = (string)($ice_mount['server_title'] ?? (!empty($radio['nombre_emisora']) ? (string)$radio['nombre_emisora'] : $mount));
    $dj_source = (string)($ice_mount['dj'] ?? 'source');
    $dj_display = $dj_source;
    $is_live = false;
    $is_direct_mode = !empty($np['mode']) && $np['mode'] === 'directa';
    $dj_live = api_dj_is_live($mount, $radio);
    if ($is_direct_mode || (!empty($cur['mode']) && $cur['mode'] === 'directa')) {
        $is_live = true;
        if ($dj_display === '' || strtolower($dj_display) === 'source') {
            $dj_display = !empty($radio['nombre_emisora']) ? (string)$radio['nombre_emisora'] : 'DJ en Vivo';
        }
    } elseif ($dj_live) {
        // MODO AUTODJ + DJ conectado al harbor: el DJ tiene prioridad
        // (fallback nivel 1), así que la radio está "en vivo".
        $is_live = true;
        $dj_display = !empty($radio['nombre_emisora']) ? (string)$radio['nombre_emisora'] : 'DJ en Vivo';
    }
    $songtitle = '';
    if (is_array($cur) && !empty($cur['title'])) {
        $songtitle = trim((string)$cur['title']);
        if ($songtitle === '' && !empty($cur['artist'])) $songtitle = trim((string)$cur['artist']).' - '.trim((string)$cur['title']);
    }
    if ($songtitle === '') { $songtitle = (string)($ice_mount['songtitle'] ?? ''); }
    // El título REAL es siempre el que Icecast transmite por metadata
    // (AutoDJ envía "artist - title" desde el nombre del archivo; el DJ en
    // vivo envía su propia metadata). En ambos modos ese es lo que suena.
    $liveTitle = trim((string)($ice_mount['songtitle'] ?? ''));
    if ($liveTitle !== '') $songtitle = $liveTitle;
    $streampath = '/'.ltrim((string)($ice_mount['streampath'] ?? '/'.$mount), '/');
    $streamhits = (int)($ice_mount['streamhits'] ?? 0);
    $backupstatus = (int)($ice_mount['backupstatus'] ?? 0);
    $streamuptime = 0;
    if (!empty($ice_mount['stream_start'])) {
        $st = @strtotime((string)$ice_mount['stream_start']);
        if ($st > 0) { $streamuptime = max(0, (time() - $st)); }
    } elseif (!empty($ice_mount['stream_start_iso8601'])) {
        $st = @strtotime((string)$ice_mount['stream_start_iso8601']);
        if ($st > 0) $streamuptime = max(0, (time() - $st));
    }
    if ($streamuptime <= 0 && !empty($np['status']['running'])) {
        if (is_file($pid_file)) { $pm = @filemtime($pid_file); if ($pm > 0) $streamuptime = max(0, time() - $pm); }
    }
    $max_listeners = (int)($ice_mount['max_listeners'] ?? (!empty($radio['max_listeners']) ? (int)$radio['max_listeners'] : 500));
    if ($max_listeners <= 0) $max_listeners = 500;
    $peaklisteners = max($peak_all, (int)($ice_mount['peak_listeners'] ?? 0));
    $uniquelisteners = max((int)($ice_mount['unique_listeners'] ?? 0), (int)($peak_day_f ?? 0));
    $averagetime = (int)($ice_mount['total_bytes_read'] ?? 0);
    if ($current_listeners > 0 && $streamuptime > 0) { $averagetime = (int)round($streamuptime * 1.7); } else { $averagetime = 0; }
    $content_type = (string)($ice_mount['content'] ?? 'audio/mpeg');
    if ($content_type === '') $content_type = 'audio/mpeg';
    $payload = [
        'currentlisteners' => $current_listeners,
        'peaklisteners'    => $peaklisteners,
        'maxlisteners'     => $max_listeners,
        'uniquelisteners'  => $uniquelisteners,
        'averagetime'      => $averagetime,
        'servergenre'      => $server_genre,
        'servergenre2'     => (string)($ice_mount['server_genre2'] ?? ''),
        'servergenre3'     => (string)($ice_mount['server_genre3'] ?? ''),
        'servergenre4'     => (string)($ice_mount['server_genre4'] ?? ''),
        'servergenre5'     => (string)($ice_mount['server_genre5'] ?? ''),
        'serverurl'        => $server_url,
        'servertitle'      => $server_title,
        'songtitle'        => $songtitle,
        'dj'               => $dj_display,
        'songurl'          => (string)($ice_mount['songurl'] ?? ''),
        'streamhits'       => $streamhits,
        'streamstatus'     => (!empty($np['status']['online']) ? 1 : 0),
        'backupstatus'     => $backupstatus,
        'streamlisted'     => (int)($ice_mount['streamlisted'] ?? 0),
        'streamlistederror' => (int)($ice_mount['streamlistederror'] ?? 0),
        'streampath'       => $streampath,
        'streamuptime'     => $streamuptime,
        'bitrate'          => $bitrate,
        'samplerate'       => $samplerate,
        'content'          => $content_type,
        'version'          => $server_version,
        'mount'            => $mount,
        'radio_name'       => $server_title,
        'live_mode'        => $is_live,
        'live_banner_text' => $is_live ? 'Radio en vivo' : '',
        'live_cover'       => (string)($np['live_cover'] ?? ''),
        'mode_radio'      => (string)($np['mode'] ?? 'autodj'),
        'stream_url'       => (string)($np['stream_url'] ?? ''),
        'stats_url'       => api_stats_site_base_url_for_stats() . '/autodj_api.php?action=stats&mount='.rawurlencode($mount),
        'player_url'      => api_stats_site_base_url_for_stats() . '/radio_page.php?mount='.rawurlencode($mount),
        'player_url_pretty' => api_stats_site_base_url_for_stats() . '/web/'.$mount,
        'page_config_url'     => api_stats_site_base_url_for_stats() . '/autodj_api.php?action=get_page_config&mount='.rawurlencode($mount),
        'server_time'       => date('c'),
        'server_time_unix'  => time(),
        'server_host'       => !empty($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '',
        'peak_today'      => (int)($peak_day_f ?? 0),
        'peak_hour'        => (int)($peak_hour_f ?? 0),
        'peak_all_time'     => (int)$peak_all,
        'listeners_history' => null,
        'audio_info'       => (string)($ice_mount['audio_info'] ?? ''),
        'channels'         => (string)($ice_mount['channels'] ?? ''),
        'icecast_source' => $ice_mount,
        'current'         => $cur,
        'current_song'    => is_array($cur) ? [
            'title'   => api_clean_tag_string((string)($cur['title'] ?? '')),
            'artist'  => api_clean_tag_string((string)($cur['artist'] ?? '')),
            'album'   => api_clean_tag_string((string)($cur['album'] ?? '')),
            'cover'   => (string)($cur['cover_url'] ?? ''),
            'started_at' => (string)($cur['started_at'] ?? ''),
            'started_ts' => (int)($cur['started_ts'] ?? 0),
            'playlist' => (string)($cur['playlist'] ?? ''),
            'from'     => isset($cur['from']) ? $cur['from'] : null,
        ] : null,
        'history'         => array_values(array_map(static function($h) {
            if (is_array($h)) {
                $h['title'] = api_clean_tag_string((string)($h['title'] ?? ''));
                $h['artist'] = api_clean_tag_string((string)($h['artist'] ?? ''));
                $h['album'] = api_clean_tag_string((string)($h['album'] ?? ''));
            }
            return $h;
        }, (array)($np['history'] ?? []))),
        'history_count'   => count((array)($np['history'] ?? [])),
        'now_playing'     => is_array($cur) ? [
            'song' => [
                'title'  => api_clean_tag_string((string)($cur['title'] ?? '')),
                'artist' => api_clean_tag_string((string)($cur['artist'] ?? '')),
                'album'  => api_clean_tag_string((string)($cur['album'] ?? '')),
                'art'    => (string)($cur['cover_url'] ?? ''),
                'duration' => 0,
                'played_at' => !empty($cur['started_ts']) ? date('c', (int)$cur['started_ts']) : null,
                'duration_seconds' => 0,
                'playlist' => (string)($cur['playlist'] ?? ''),
            ],
            'shout'   => [],
            'is_live' => $is_live,
        ] : null,
        'listeners'       => ['current' => $current_listeners, 'peak' => $peaklisteners, 'unique' => $uniquelisteners, 'max' => $max_listeners],
        'station'         => [
            'name' => $server_title,
            'genre' => $server_genre,
            'url' => $server_url,
            'bitrate' => (int)$bitrate,
            'samplerate' => (int)$samplerate,
            'content_type' => $content_type,
        ],
    ];
    return $payload;
}

if ($action === 'stats') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (!empty($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') { http_response_code(204); exit; }
    $payload = api_get_stats_payload($mount, $base_dir, $radio ?? null);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'serve_default_cover') {
    $abs = api_np_default_cover_abs($base_dir);
    if (is_file($abs)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($abs); exit;
    }
    $logoAbs = api_pg_logo_abs($base_dir);
    if (is_file($logoAbs)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($logoAbs); exit;
    }
    $placeholder = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAAgACADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwC/45Q/7Cn3/AN6Q/8ApQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFAH//Z');
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    echo $placeholder; exit;
}

if ($action === 'serve_cached_cover') {
    $f = $_GET['f'] ?? '';
    $safe = basename((string)$f);
    if ($safe === '') { http_response_code(400); exit; }
    $abs = api_np_state_dir($base_dir) . '/id3_cache/' . $safe;
    if (!is_file($abs)) { $abs = api_np_default_cover_abs($base_dir); }
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $ct = 'image/jpeg';
    if ($ext === 'png') $ct = 'image/png';
    elseif ($ext === 'gif') $ct = 'image/gif';
    elseif ($ext === 'webp') $ct = 'image/webp';
    header('Content-Type: ' . $ct);
    header('Cache-Control: public, max-age=31536000, immutable');
    if (is_file($abs)) readfile($abs);
    else {
        $ph = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAAgACADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwC/45Q/7Cn3/AN6Q/8ApQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFAH//Z');
        header('Content-Type: image/jpeg');
        echo $ph;
    }
    exit;
}

if ($action === 'upload_default_cover') {
    if (empty($_SESSION['cliente_auth']) && empty($_SESSION['superadmin_auth'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado. Inicia sesión como cliente o superadmin.'], JSON_UNESCAPED_UNICODE); exit;
    }
    $resp = ['success' => false];
    $f = $_FILES['cover'] ?? null;
    if (!$f || !isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
        $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        $map = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede upload_max_filesize (php.ini).',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede MAX_FILE_SIZE del formulario.',
            UPLOAD_ERR_PARTIAL => 'Subida incompleta.',
            UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta tmp PHP.',
            UPLOAD_ERR_CANT_WRITE => 'No se puede escribir en disco.',
            UPLOAD_ERR_EXTENSION => 'Subida bloqueada por extensión PHP.',
        ];
        $resp['error'] = $map[$code] ?? 'Error desconocido al subir.';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit;
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) { $resp['error'] = 'Archivo demasiado grande. Máximo 5 MB.'; header('Content-Type: application/json; charset=utf-8'); echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit; }
    $name = (string)($f['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) { $resp['error'] = 'Formato no permitido. Usa JPG, PNG, GIF o WEBP.'; header('Content-Type: application/json; charset=utf-8'); echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit; }
    $tmp = $f['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) { $resp['error'] = 'No se recibió el archivo subido.'; header('Content-Type: application/json; charset=utf-8'); echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit; }
    $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo) {
        $mime = (string)@finfo_file($finfo, $tmp);
        @finfo_close($finfo);
        if (strpos($mime, 'image/') !== 0) { $resp['error'] = 'El archivo no es una imagen válida.'; header('Content-Type: application/json; charset=utf-8'); echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit; }
    }
    $stateDir = api_np_state_dir($base_dir);
    $target = $stateDir . '/default_cover.jpg';
    if (in_array($ext, ['png', 'gif', 'webp'], true) && function_exists('imagecreatefromstring')) {
        $srcImg = @imagecreatefromstring(@file_get_contents($tmp));
        if ($srcImg !== false) {
            $w = imagesx($srcImg); $h = imagesy($srcImg);
            $sizeMax = 800;
            if ($w > $sizeMax || $h > $sizeMax) {
                $ratio = min($sizeMax / $w, $sizeMax / $h);
                $nw = (int)round($w * $ratio); $nh = (int)round($h * $ratio);
                $dst = imagecreatetruecolor($nw, $nh);
                if ($dst !== false) {
                    imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    imagedestroy($srcImg); $srcImg = $dst;
                }
            }
            $okConv = @imagejpeg($srcImg, $target, 88);
            @imagedestroy($srcImg);
            if (!$okConv) {
                // fallback: copia directa sin convertir (PHP no GD)
                $ok = @copy($tmp, $target);
                if (!$ok) { $resp['error'] = 'No se pudo guardar la imagen en disco.'; header('Content-Type: application/json; charset=utf-8'); echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit; }
            }
        } else {
            $ok = @move_uploaded_file($tmp, $target);
            if (!$ok) { $resp['error'] = 'No se pudo mover el archivo subido (permisos?).'; header('Content-Type: application/json; charset=utf-8'); echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit; }
        }
    } else {
        $ok = @move_uploaded_file($tmp, $target);
        if (!$ok) { $resp['error'] = 'No se pudo mover el archivo subido (permisos?).'; header('Content-Type: application/json; charset=utf-8'); echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit; }
    }
    @chmod($target, 0664);
    $resp['success'] = true;
    $resp['default_cover_url'] = api_np_default_cover_url($mount, $base_dir, true);
    $resp['filesize_kb'] = round((@filesize($target) ?: 0) / 1024, 1);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'delete_default_cover') {
    if (empty($_SESSION['cliente_auth']) && empty($_SESSION['superadmin_auth'])) {
        header('Content-Type: application/json; charset=utf-8'); http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado.'], JSON_UNESCAPED_UNICODE); exit;
    }
    $abs = api_np_default_cover_abs($base_dir);
    $resp = ['success' => true, 'existed' => false];
    if (is_file($abs)) { $resp['existed'] = true; @unlink($abs); }
    $resp['default_cover_url'] = api_np_default_cover_url($mount, $base_dir, true);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'clear_now_playing') {
    if (empty($_SESSION['cliente_auth']) && empty($_SESSION['superadmin_auth'])) {
        header('Content-Type: application/json; charset=utf-8'); http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado.'], JSON_UNESCAPED_UNICODE); exit;
    }
    $resp = ['success' => true, 'mount' => $mount, 'cleared' => []];
    $cFile = api_np_current_file($base_dir);
    if (is_file($cFile)) { $resp['cleared'][] = 'current_song.json'; @unlink($cFile); }
    $hFile = api_np_history_file($base_dir);
    if (is_file($hFile)) { $resp['cleared'][] = 'history.json'; @unlink($hFile); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'serve_page_logo') {
    $abs = api_pg_logo_abs($base_dir);
    if (is_file($abs)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($abs); exit;
    }
    $logoBg = api_np_default_cover_abs($base_dir);
    if (is_file($logoBg)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($logoBg); exit;
    }
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=86400');
    $radioName = ($radio && is_array($radio) && !empty($radio['nombre_emisora'])) ? htmlspecialchars((string)$radio['nombre_emisora']) : strtoupper($mount);
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400"><defs><linearGradient id="g" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="#0f172a"/><stop offset="1" stop-color="#1e293b"/></linearGradient></defs><circle cx="200" cy="200" r="190" fill="url(#g)" stroke="#334155" stroke-width="8"/><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-weight="800" font-size="56" fill="#e2e8f0">'.htmlspecialchars(substr($radioName,0,12)).'</text></svg>';
    exit;
}

if ($action === 'serve_page_bg') {
    $abs = api_pg_bg_abs($base_dir);
    if (is_file($abs)) {
        $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false;
        $mime = 'image/jpeg';
        if ($finfo) { $m = (string)@finfo_file($finfo, $abs); if ($m !== '') $mime = $m; @finfo_close($finfo); }
        else {
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
            if (in_array($ext, ['png'], true)) $mime = 'image/png';
            elseif (in_array($ext, ['webp'], true)) $mime = 'image/webp';
            elseif (in_array($ext, ['gif'], true)) $mime = 'image/gif';
        }
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($abs); exit;
    }
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=86400');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1920 1080" preserveAspectRatio="xMidYMid slice"><defs><linearGradient id="g" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="#020617"/><stop offset="0.5" stop-color="#0f172a"/><stop offset="1" stop-color="#1e293b"/></linearGradient></defs><rect width="1920" height="1080" fill="url(#g)"/></svg>';
    exit;
}

if ($action === 'serve_staff_photo') {
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $id)) { http_response_code(400); exit; }
    $abs = api_pg_staff_photo_abs($base_dir, $id);
    if ($abs === '' || !is_file($abs)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($abs);
    exit;
}

/**
 * Las escrituras de la página pública (guardar/subir/borrar) DEBEN identificar su radio
 * con un mount válido. Sin esto, un request con mount perdido caía al fallback de
 * sesión/radio #1 y podía escribir la config de UNA radio en la carpeta de OTRA.
 */
function sp_pg_exigir_mount($mount_param, $pg_mount_ok) {
    if ($mount_param === '' || !$pg_mount_ok) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No se pudo identificar la radio (mount inválido). Recarga la página del panel.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($action === 'get_page_config') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
    $cfg = api_pg_read_config($base_dir);
    $cfg['logo_url'] = api_pg_logo_url($mount, $base_dir, true);
    $cfg['logo_set'] = is_file(api_pg_logo_abs($base_dir));
    $cfg['bg_url'] = api_pg_bg_url($mount, $base_dir, true);
    $cfg['bg_set'] = is_file(api_pg_bg_abs($base_dir));
    $cfg = api_pg_decorate_staff($cfg, $mount, $base_dir);
    $cfg = api_pg_decorate_links($cfg, $mount, $base_dir);
    $np = api_get_now_playing_payload($mount, $base_dir, $radio ?? null);
    $cfg['mount'] = $mount;
    $cfg['radio_name'] = ($radio && is_array($radio) && !empty($radio['nombre_emisora'])) ? (string)$radio['nombre_emisora'] : ucfirst($mount);
    if ($cfg['title'] === null || trim((string)$cfg['title']) === '') $cfg['title'] = $cfg['radio_name'];
    $cfg['stream_url'] = $np['stream_url'];
    $base = api_np_site_base();
    $cfg['page_url'] = $base ? ($base . ltrim('web/' . rawurlencode($mount), '/')) : '';
    $cfg['page_url_direct'] = $base ? ($base . ltrim('radio_page.php?mount=' . rawurlencode($mount), '/')) : '';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($cfg, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_page_config') {
    if (empty($_SESSION['cliente_auth']) && empty($_SESSION['superadmin_auth'])) {
        header('Content-Type: application/json; charset=utf-8'); http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado.'], JSON_UNESCAPED_UNICODE); exit;
    }
    sp_pg_exigir_mount($mount_param, $pg_mount_ok);
    $raw = file_get_contents('php://input');
    $in = $raw ? @json_decode($raw, true) : [];
    if (!is_array($in)) $in = [];
    $defaults = api_pg_default_config();
    $saved = api_pg_read_config($base_dir);
    $hexKeys = ['accent_color', 'primary_text_color', 'bg_color_base', 'header_bg_color', 'main_bg_color', 'footer_bg_color', 'header_text_color', 'header_icon_color', 'footer_text_color', 'app_text_color', 'app_title_color', 'app_subtitle_color', 'c_label_color', 'station_name_color', 'song_title_color',
        // Personalización por sección (colores)
        'sec_staff_bg', 'sec_staff_title', 'sec_staff_card_bg', 'sec_staff_card_border', 'sec_staff_card_text', 'sec_staff_role',
        'sec_programacion_bg', 'sec_programacion_title', 'sec_programacion_card_bg', 'sec_programacion_card_border', 'sec_programacion_card_text',
        'sec_patrocinadores_bg', 'sec_patrocinadores_title', 'sec_patrocinadores_card_bg', 'sec_patrocinadores_card_border', 'sec_patrocinadores_card_text',
        'sec_radios_bg', 'sec_radios_title', 'sec_radios_card_bg', 'sec_radios_card_border', 'sec_radios_card_text',
        'sec_escuchanos_bg', 'sec_escuchanos_title', 'sec_escuchanos_card_bg', 'sec_escuchanos_card_border', 'sec_escuchanos_card_text', 'player_btn_color', 'player_vol_color', 'store_btn_bg', 'store_btn_text', 'store_btn_icon', 'wa_btn_bg', 'wa_btn_text', 'clock_time_color', 'clock_date_color'];
    foreach ($defaults as $k => $def) {
        if ($k === 'meta') continue;
        if (array_key_exists($k, $in)) {
            if (in_array($k, $hexKeys, true) || $k === 'title' || $k === 'subtitle') {
                $v = trim((string)$in[$k]);
                if (in_array($k, $hexKeys, true)) {
                    if ($v !== '' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v)) $v = $saved[$k];
                }
                $saved[$k] = ($v === '' && $k === 'title') ? null : $v;
            } elseif ($k === 'bg_overlay_opacity') {
                $n = (int)$in[$k];
                if ($n < 0) $n = 0; if ($n > 100) $n = 100;
                $saved[$k] = $n;
            } elseif ($k === 'header_bg_opacity' || $k === 'main_bg_opacity' || $k === 'footer_bg_opacity') {
                $n = (int)$in[$k];
                if ($n < 5) $n = 5; if ($n > 100) $n = 100;
                $saved[$k] = $n;
            } elseif (str_ends_with($k, '_opacity')) {
                // Opacidades por sección (sec_<seccion>_bg_opacity / _card_bg_opacity)
                $n = (int)$in[$k];
                if ($n < 5) $n = 5; if ($n > 100) $n = 100;
                $saved[$k] = $n;
            } elseif ($k === 'history_count') {
                $n = (int)$in[$k];
                if ($n < 1) $n = 7; if ($n > 20) $n = 20;
                $saved[$k] = $n;
            } elseif ($k === 'show_history' || $k === 'show_share' || $k === 'show_logo_when_cover' || $k === 'nosotros_html' || $k === 'terminos_html' || $k === 'show_patrocinadores' || $k === 'show_radios' || $k === 'show_escuchanos' || $k === 'show_staff' || $k === 'show_programacion' || $k === 'sec_staff_icon' || $k === 'sec_programacion_icon' || $k === 'sec_patrocinadores_icon' || $k === 'sec_radios_icon' || $k === 'sec_escuchanos_icon') {
                $saved[$k] = !empty($in[$k]);
            } elseif ($k === 'website_url' || $k === 'facebook_url' || $k === 'whatsapp_url' || $k === 'share_url' || $k === 'instagram_url' || $k === 'tiktok_url' || $k === 'youtube_url' || $k === 'x_url' || $k === 'appstore_url' || $k === 'playstore_url') {
                $v = trim((string)$in[$k]);
                if ($v !== '') {
                    if ($k === 'whatsapp_url' && preg_match('/^\+?[0-9][0-9\s()\-]*$/', $v)) {
                        // Número suelto → enlace wa.me (se convierte ANTES de añadir esquema)
                        $num = preg_replace('/\D+/', '', $v);
                        $v = ($num !== '') ? 'https://wa.me/' . $num : '';
                    } else {
                        if (stripos($v, 'http://') !== 0 && stripos($v, 'https://') !== 0 && stripos($v, 'wa.me/') !== 0 && stripos($v, 'api.whatsapp.com/') !== 0) {
                            $v = 'https://' . $v;
                        }
                    }
                    $hp = @parse_url($v);
                    if (!$hp || empty($hp['host'])) { $v = ''; }
                }
                $saved[$k] = $v;
            } elseif ($k === 'email_contacto') {
                $v = trim((string)$in[$k]);
                if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $v = ''; }
                if (function_exists('mb_substr')) { if (mb_strlen($v) > 200) $v = mb_substr($v, 0, 200); }
                elseif (strlen($v) > 200) { $v = substr($v, 0, 200); }
                $saved[$k] = $v;
            } elseif ($k === 'nosotros') {
                $v = (string)$in[$k];
                if (!empty($in['nosotros_html'])) {
                    $v = rp_sanitize_rich_text($v);
                } else {
                    $v = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
                }
                if (function_exists('mb_substr')) { if (mb_strlen($v) > 500) $v = mb_substr($v, 0, 500); }
                elseif (strlen($v) > 500) { $v = substr($v, 0, 500); }
                $saved[$k] = $v;
            } elseif ($k === 'terminos') {
                $v = (string)$in[$k];
                if (!empty($in['terminos_html'])) {
                    $v = rp_sanitize_rich_text($v);
                } else {
                    $v = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
                }
                $max = 4000;
                if (function_exists('mb_substr')) { if (mb_strlen($v, 'UTF-8') > $max) $v = mb_substr($v, 0, $max, 'UTF-8'); }
                elseif (strlen($v) > $max) { $v = substr($v, 0, $max); }
                $saved[$k] = $v;
            } elseif ($k === 'staff') {
                $saved['staff'] = api_pg_sanitize_staff($in[$k]);
            } elseif ($k === 'programacion') {
                $saved['programacion'] = api_pg_sanitize_programacion($in[$k]);
            } elseif ($k === 'patrocinadores' || $k === 'radios' || $k === 'escuchanos') {
                $saved[$k] = api_pg_sanitize_links($in[$k]);
            } else {
                $saved[$k] = $in[$k];
            }
        }
    }
    // ==== Fotos de staff Y logos de secciones vinculadas: coherencia (referenciada sin fichero → '')
    //     + GC de huérfanas. Se ejecuta solo si el payload trae alguna de esas claves. ====
    $linkKeys = ['patrocinadores', 'radios', 'escuchanos'];
    if (array_key_exists('staff', $in) || array_intersect($linkKeys, array_keys($in))) {
        $ref = [];
        foreach ($saved['staff'] as $m) { if (!empty($m['foto'])) $ref[$m['foto']] = true; }
        foreach ($linkKeys as $lk) {
            foreach (($saved[$lk] ?? []) as $it) { if (!empty($it['logo'])) $ref[$it['logo']] = true; }
        }
        foreach ($saved['staff'] as $i => $m) {
            if (!empty($m['foto']) && !is_file(api_pg_staff_photo_abs($base_dir, $m['id']))) {
                $saved['staff'][$i]['foto'] = '';
            }
        }
        foreach ($linkKeys as $lk) {
            foreach (($saved[$lk] ?? []) as $i => $it) {
                if (!empty($it['logo']) && !is_file(api_pg_staff_photo_abs($base_dir, $it['id']))) {
                    $saved[$lk][$i]['logo'] = '';
                }
            }
        }
        // Borra jpg de staff/ no referenciados por staff ni por los logos de secciones
        $dir = api_np_state_dir($base_dir) . '/staff';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.jpg') ?: [] as $f) {
                if (!isset($ref[basename($f)])) { @unlink($f); }
            }
        }
    }
    $meta = is_array($saved['meta']) ? $saved['meta'] : [];
    if (empty($meta['created_at'])) $meta['created_at'] = date('c');
    $meta['updated_at'] = date('c');
    $saved['meta'] = $meta;
    $stateDir = api_np_state_dir($base_dir);
    if (!is_dir($stateDir)) { @mkdir($stateDir, 0775, true); }
    @file_put_contents(api_pg_config_file($base_dir), json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @chmod(api_pg_config_file($base_dir), 0664);
    $saved['logo_url'] = api_pg_logo_url($mount, $base_dir, true);
    $saved['logo_set'] = is_file(api_pg_logo_abs($base_dir));
    $saved['bg_url'] = api_pg_bg_url($mount, $base_dir, true);
    $saved['bg_set'] = is_file(api_pg_bg_abs($base_dir));
    $saved = api_pg_decorate_staff($saved, $mount, $base_dir);
    $saved = api_pg_decorate_links($saved, $mount, $base_dir);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'config' => $saved], JSON_UNESCAPED_UNICODE);
    exit;
}

$__pg_upload = function ($kind) use ($base_dir, $mount) {
    if ($kind === 'logo') {
        $targetAbs = api_pg_logo_abs($base_dir);
        $maxBytes = 5 * 1024 * 1024;
        $formName = 'logo';
        $sizeMax = 800;
    } else {
        $targetAbs = api_pg_bg_abs($base_dir);
        $maxBytes = 12 * 1024 * 1024;
        $formName = 'bg';
        $sizeMax = 2560;
    }
    $resp = ['success' => false];
    $f = $_FILES[$formName] ?? null;
    if (!$f || !isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
        $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        $map = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede upload_max_filesize (php.ini).',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede MAX_FILE_SIZE del formulario.',
            UPLOAD_ERR_PARTIAL => 'Subida incompleta.',
            UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta tmp PHP.',
            UPLOAD_ERR_CANT_WRITE => 'No se puede escribir en disco.',
            UPLOAD_ERR_EXTENSION => 'Subida bloqueada por extensión PHP.',
        ];
        $resp['error'] = $map[$code] ?? 'Error desconocido al subir.';
        return $resp;
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) { $resp['error'] = 'Archivo demasiado grande. Máximo ' . round($maxBytes/1024/1024,0) . ' MB.'; return $resp; }
    $name = (string)($f['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowedExts = ['jpg','jpeg','png','gif','webp'];
    if ($kind === 'bg') $allowedExts = array_merge($allowedExts, ['bmp']);
    if (!in_array($ext, $allowedExts, true)) { $resp['error'] = 'Formato no permitido. Usa JPG, PNG, GIF o WEBP.'; return $resp; }
    $tmp = $f['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) { $resp['error'] = 'No se recibió el archivo subido.'; return $resp; }
    $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo) {
        $mime = (string)@finfo_file($finfo, $tmp);
        @finfo_close($finfo);
        if (strpos($mime, 'image/') !== 0) { $resp['error'] = 'El archivo no es una imagen válida.'; return $resp; }
    }
    $stateDir = api_np_state_dir($base_dir);
    if (!is_dir($stateDir)) { @mkdir($stateDir, 0775, true); }
    tryConvSave:
    try {
        if (function_exists('imagecreatefromstring')) {
            $rawImg = @file_get_contents($tmp);
            if ($rawImg !== false && strlen($rawImg) > 0) {
                $srcImg = @imagecreatefromstring($rawImg);
                if ($srcImg !== false) {
                    $w = imagesx($srcImg); $h = imagesy($srcImg);
                    if ($w > $sizeMax || $h > $sizeMax) {
                        $ratio = min($sizeMax / $w, $sizeMax / $h);
                        $nw = (int)round($w * $ratio); $nh = (int)round($h * $ratio);
                        $dst = imagecreatetruecolor($nw, $nh);
                        if ($dst !== false) {
                            imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $nw, $nh, $w, $h);
                            imagedestroy($srcImg); $srcImg = $dst;
                        }
                    }
                    if ($kind === 'logo') {
                        $ok = @imagejpeg($srcImg, $targetAbs, 90);
                    } else {
                        $ok = @imagejpeg($srcImg, $targetAbs, 88);
                    }
                    imagedestroy($srcImg);
                    if (!$ok) { goto tryFallbackCopy; }
                    goto writeOK;
                }
            }
        }
    } catch (\Throwable $e) {}
    tryFallbackCopy:
    $ok = @move_uploaded_file($tmp, $targetAbs);
    if (!$ok) { $resp['error'] = 'No se pudo guardar el archivo en disco (permisos?).'; return $resp; }
    writeOK:
    @chmod($targetAbs, 0664);
    $resp['success'] = true;
    $resp['filesize_kb'] = round((@filesize($targetAbs) ?: 0) / 1024, 1);
    if ($kind === 'logo') $resp['logo_url'] = api_pg_logo_url($mount, $base_dir, true);
    else $resp['bg_url'] = api_pg_bg_url($mount, $base_dir, true);
    return $resp;
};

if ($action === 'upload_page_logo') {
    if (empty($_SESSION['cliente_auth']) && empty($_SESSION['superadmin_auth'])) {
        header('Content-Type: application/json; charset=utf-8'); http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado.'], JSON_UNESCAPED_UNICODE); exit;
    }
    sp_pg_exigir_mount($mount_param, $pg_mount_ok);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($__pg_upload('logo'), JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'upload_page_bg') {
    if (empty($_SESSION['cliente_auth']) && empty($_SESSION['superadmin_auth'])) {
        header('Content-Type: application/json; charset=utf-8'); http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado.'], JSON_UNESCAPED_UNICODE); exit;
    }
    sp_pg_exigir_mount($mount_param, $pg_mount_ok);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($__pg_upload('bg'), JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'delete_page_logo' || $action === 'delete_page_bg') {
    if (empty($_SESSION['cliente_auth']) && empty($_SESSION['superadmin_auth'])) {
        header('Content-Type: application/json; charset=utf-8'); http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado.'], JSON_UNESCAPED_UNICODE); exit;
    }
    sp_pg_exigir_mount($mount_param, $pg_mount_ok);
    $resp = ['success' => true, 'existed' => false];
    $abs = ($action === 'delete_page_logo') ? api_pg_logo_abs($base_dir) : api_pg_bg_abs($base_dir);
    if (is_file($abs)) { $resp['existed'] = true; @unlink($abs); }
    if ($action === 'delete_page_logo') $resp['logo_url'] = api_pg_logo_url($mount, $base_dir, true);
    else $resp['bg_url'] = api_pg_bg_url($mount, $base_dir, true);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit;
}
$__pg_staff_upload = function () use ($base_dir, $mount) {
    $resp = ['success' => false];
    $id = (string)($_REQUEST['id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $id)) { $resp['error'] = 'ID inválido.'; return $resp; }
    $f = $_FILES['foto'] ?? null;
    if (!$f || !isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
        $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        $map = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede upload_max_filesize (php.ini).',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede MAX_FILE_SIZE del formulario.',
            UPLOAD_ERR_PARTIAL => 'Subida incompleta.',
            UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta tmp PHP.',
            UPLOAD_ERR_CANT_WRITE => 'No se puede escribir en disco.',
            UPLOAD_ERR_EXTENSION => 'Subida bloqueada por extensión PHP.',
        ];
        $resp['error'] = $map[$code] ?? 'Error desconocido al subir.';
        return $resp;
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) { $resp['error'] = 'Archivo demasiado grande. Máximo 5 MB.'; return $resp; }
    $name = (string)($f['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) { $resp['error'] = 'Formato no permitido. Usa JPG, PNG, GIF o WEBP.'; return $resp; }
    $tmp = $f['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) { $resp['error'] = 'No se recibió el archivo subido.'; return $resp; }
    $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo) {
        $mime = (string)@finfo_file($finfo, $tmp);
        @finfo_close($finfo);
        if (strpos($mime, 'image/') !== 0) { $resp['error'] = 'El archivo no es una imagen válida.'; return $resp; }
    }
    $targetAbs = api_pg_staff_photo_abs($base_dir, $id);
    if ($targetAbs === '') { $resp['error'] = 'ID inválido.'; return $resp; }
    $sizeMax = 600;
    try {
        if (function_exists('imagecreatefromstring')) {
            $rawImg = @file_get_contents($tmp);
            if ($rawImg !== false && strlen($rawImg) > 0) {
                $srcImg = @imagecreatefromstring($rawImg);
                if ($srcImg !== false) {
                    $w = imagesx($srcImg); $h = imagesy($srcImg);
                    if ($w > $sizeMax || $h > $sizeMax) {
                        $ratio = min($sizeMax / $w, $sizeMax / $h);
                        $nw = (int)round($w * $ratio); $nh = (int)round($h * $ratio);
                        $dst = imagecreatetruecolor($nw, $nh);
                        if ($dst !== false) {
                            imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $nw, $nh, $w, $h);
                            imagedestroy($srcImg); $srcImg = $dst;
                        }
                    }
                    $ok = @imagejpeg($srcImg, $targetAbs, 88);
                    imagedestroy($srcImg);
                    if (!$ok) { goto stTryFallbackCopy; }
                    goto stWriteOK;
                }
            }
        }
    } catch (\Throwable $e) {}
    stTryFallbackCopy:
    $ok = @move_uploaded_file($tmp, $targetAbs);
    if (!$ok) { $resp['error'] = 'No se pudo guardar el archivo en disco (permisos?).'; return $resp; }
    stWriteOK:
    @chmod($targetAbs, 0664);
    $resp['success'] = true;
    $resp['id'] = $id;
    $resp['filesize_kb'] = round((@filesize($targetAbs) ?: 0) / 1024, 1);
    $resp['foto_url'] = api_pg_staff_photo_url($mount, $base_dir, $id, true);
    return $resp;
};

if ($action === 'upload_staff_photo') {
    if (empty($_SESSION['cliente_auth']) && empty($_SESSION['superadmin_auth'])) {
        header('Content-Type: application/json; charset=utf-8'); http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado.'], JSON_UNESCAPED_UNICODE); exit;
    }
    sp_pg_exigir_mount($mount_param, $pg_mount_ok);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($__pg_staff_upload(), JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'delete_staff_photo') {
    if (empty($_SESSION['cliente_auth']) && empty($_SESSION['superadmin_auth'])) {
        header('Content-Type: application/json; charset=utf-8'); http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autorizado.'], JSON_UNESCAPED_UNICODE); exit;
    }
    sp_pg_exigir_mount($mount_param, $pg_mount_ok);
    $resp = ['success' => true, 'existed' => false];
    $id = (string)($_REQUEST['id'] ?? '');
    $abs = api_pg_staff_photo_abs($base_dir, $id);
    if ($abs !== '' && is_file($abs)) { $resp['existed'] = true; @unlink($abs); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE); exit;
}

unset($__pg_upload, $__pg_staff_upload);

header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Acción no reconocida']);
