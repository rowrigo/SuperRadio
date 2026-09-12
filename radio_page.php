<?php
/**
 * Página pública de radio — App de 2 columnas:
 *   izquierda = reproductor (fijo, la música no se corta) | derecha = panel con páginas.
 * El menú del header (Home/Staff/Programación/Patrocinadores) y el del footer
 * (Nuestras Radios / Dónde Nos Puedes Escuchar / Términos) cambian la página del
 * panel derecho por JS (classList), SIN recargar → el <audio> nunca se recrea.
 *
 * Uso:
 *   radio_page.php?mount=milimonradio
 *
 * Configuración dinámica leída desde:
 *   <MEDIOS>/<mount>/.nextsong_state/page_config.json (subido desde el panel)
 *   - Logo / Fondo / Colores / Enlaces sociales / Título / Staff / Programación / Términos
 *
 * Datos de transmisión:
 *   Endpoint interno (ya existe en este mismo dominio):
 *     autodj_api.php?action=stats&mount=<mount>  (alias:  stats.php?mount=<mount>&json=1
 */

require_once __DIR__ . '/config.php';

// ========== Helpers URL absolutas y rutas
function rp_abs_url($path = '') {
    $is_ssl = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
                (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme  = $is_ssl ? 'https' : 'http';
    $host    = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'stream.radioscr.com';
    if ($path === '') return $scheme . '://' . $host . '/';
    if (preg_match('#^https?://#i', $path)) return $path;
    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

$mount = isset($_GET['mount']) ? trim((string)$_GET['mount']) : '';
if ($mount === '') { $mount = 'milimonradio'; }
$mount = preg_replace('#[^a-zA-Z0-9_\-]#', '', $mount);

// ==== Database radio (MISMA LOGICA QUE autodj_api.php)
$_db_raw = @file_get_contents(DB_FILE);
$db = ($_db_raw !== false) ? @json_decode($_db_raw, true) : [];
if (!is_array($db)) $db = [];
$mount_param = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9_-]/', '', $mount)));
$radio = null;
if ($mount_param !== '') {
    foreach (($db['radios'] ?? []) as $k => $r) {
        if (!is_array($r)) continue;
        $m_clean = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9_-]/', '', ($r['mountpoint'] ?? ''))));
        if ($m_clean === $mount_param || (string)$k === $mount_param || (string)$k === 'radio_' . $mount_param || (string)$k === 'rad_' . $mount_param) {
            $radio = $r;
            break;
        }
    }
}
if (!$radio && is_array($db['radios'] ?? null) && count($db['radios']) > 0) {
    $first_key = array_key_first($db['radios']);
    $radio = $db['radios'][$first_key];
}
if (!$radio) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Radio no encontrada: ' . htmlspecialchars($mount);
    exit(1);
}
$mount = trim((string)($radio['mountpoint'] ?? $mount), '/');
$base_dir = "/var/media/radios/{$mount}";
if (!is_dir($base_dir)) { @mkdir($base_dir, 0775, true); }
$ns_state_dir = rtrim($base_dir, '/') . '/.nextsong_state';
if (!is_dir($ns_state_dir)) { @mkdir($ns_state_dir, 0775, true); }

// === Helpers config página ===============
function rp_pg_default_config() {
    return [
        'title'                 => '',
        'subtitle'              => '',
        'accent_color'          => '#38bdf8',
        'primary_text_color'    => '#f8fafc',
        'bg_overlay_opacity'    => 65,
        'show_history'          => true,
        'history_count'         => 7,
        'show_share'            => true,
        'show_logo_when_cover'  => false,
        'show_staff'            => true,   // mostrar sección Staff en la página pública
        'show_programacion'     => true,   // mostrar sección Programación en la página pública
        // ==== NUEVOS COLORES DEFAULT (mismo look actual azul-oscuro glassmorphism):
        'bg_color_base'         => '#0b1226',
        'header_bg_color'       => '#111a2e',
        'main_bg_color'         => '#0f172a',
        'footer_bg_color'       => '#111a2e',
        // ==== NUEVAS OPACIDADES TRANSPARENCIA (default identico 0.92/0.88 para backward compat)
        'header_bg_opacity'     => 92,   // barra superior
        'main_bg_opacity'       => 88,   // cards interior + app glass
        'footer_bg_opacity'     => 92,   // pie pagina
        'website_url'           => '',
        'facebook_url'          => '',
        'whatsapp_url'          => '',
        'share_url'             => '',   // URL que comparte el botón "Compartir" (vacío = por defecto)
        // ==== CAMPOS LANDING (contacto / nosotros / más redes): ======
        'email_contacto'        => '',
        'nosotros'              => '',
        'nosotros_html'         => false,
        // ====== TÉRMINOS Y CONDICIONES (página propia del menú del pie): ======
        'terminos'              => '',
        'terminos_html'         => false,
        // ==== COLORES DE TEXTO POR ZONA (vacío = default del tema): =====
        'header_text_color'     => '',
        'header_icon_color'     => '',
        'footer_text_color'     => '',
        'app_text_color'        => '',
        'app_title_color'       => '',
        'app_subtitle_color'    => '',
        'c_label_color'         => '',
        'station_name_color'    => '',
        'song_title_color'      => '',
        'player_btn_color'      => '',   // botón Play/Pausa (vacío = color acento)
        'player_vol_color'      => '',   // barra de volumen (vacío = color acento)
        'store_btn_bg'          => '',   // fondo de botones App Store / Google Play
        'store_btn_text'        => '',   // letras de los botones de app
        'store_btn_icon'        => '',   // iconos de los botones de app
        'wa_btn_bg'             => '',   // fondo del botón WhatsApp
        'wa_btn_text'           => '',   // letras del botón WhatsApp
        'clock_time_color'      => '',   // letra de la hora del reloj (vacío = #ffffff)
        'clock_date_color'      => '',   // letra de la fecha (vacío = #94a3b8)
        'instagram_url'         => '',
        'tiktok_url'            => '',
        'youtube_url'           => '',
        'x_url'                 => '',
        // ==== DESCARGA DE LA APP (tiendas): ======
        'appstore_url'          => '',
        'playstore_url'         => '',
        // ==== STAFF (equipo) y PROGRAMACIÓN semanal (LUN..DOM) de la landing: ======
        'staff'                 => [],
        'programacion'          => [],
        // ==== SECCIONES VINCULADAS (patrocinadores / radios / escuchanos): ======
        'show_patrocinadores'   => false,
        'patrocinadores'        => [],
        'show_radios'           => false,
        'radios'                => [],
        'show_escuchanos'       => false,
        'escuchanos'            => [],
        // ==== PERSONALIZACIÓN POR SECCIÓN (vacío = color actual de la plantilla) =====
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
        // Opacidades del fondo de sección y de cards (5..100 %, se aplican a los colores que pongas arriba)
        'sec_staff_bg_opacity'          => 60,  'sec_staff_card_bg_opacity'          => 100,
        'sec_programacion_bg_opacity'   => 55,  'sec_programacion_card_bg_opacity'   => 100,
        'sec_patrocinadores_bg_opacity' => 55,  'sec_patrocinadores_card_bg_opacity' => 100,
        'sec_radios_bg_opacity'         => 55,  'sec_radios_card_bg_opacity'         => 100,
        'sec_escuchanos_bg_opacity'     => 50,  'sec_escuchanos_card_bg_opacity'     => 100,
    ];
}
function rp_pg_read_config($base_dir) {
    $def = rp_pg_default_config();
    $state_dir = rtrim($base_dir, '/') . '/.nextsong_state';
    if (!is_dir($state_dir)) return $def;
    $f   = $state_dir . '/page_config.json';
    if (is_file($f)) {
        $j = @json_decode(@file_get_contents($f), true);
        if (is_array($j)) return array_replace($def, $j);
    }
    return $def;
}

// === Datos página
$radio_name = !empty($radio['nombre_emisora']) ? trim((string)$radio['nombre_emisora']) : ucfirst($mount);
$cfg = rp_pg_read_config($base_dir);
if (!is_array($cfg)) $cfg = rp_pg_default_config();

$display_title = trim((string)$cfg['title']) !== '' ? trim((string)$cfg['title']) : $radio_name;
$subtitle      = trim((string)$cfg['subtitle']);
// === Zona horaria de la estación (reloj de la landing) ===
$radio_tz = 'America/Costa_Rica';
$__prog_file = rtrim($base_dir, '/') . '/programacion.json';
if (is_file($__prog_file)) {
    $__prog_data = @json_decode(@file_get_contents($__prog_file), true);
    if (is_array($__prog_data) && !empty($__prog_data['timezone']) && is_string($__prog_data['timezone'])) {
        $radio_tz = trim($__prog_data['timezone']);
    }
}
$accent     = (string)$cfg['accent_color'];
if ($accent === '') $accent = '#38bdf8';
$text_color = (string)$cfg['primary_text_color'];
if ($text_color === '') $text_color = '#f8fafc';
$overlay = (int)$cfg['bg_overlay_opacity'];
if ($overlay < 0) $overlay = 0; if ($overlay > 100) $overlay = 100;
$overlay_dec = number_format($overlay / 100, 2, '.', '');
// === Detectar si hay imagen de fondo SUBIDA (page_bg.jpg existe en filesystem)
$_pg_state_dir = rtrim($base_dir, '/') . '/.nextsong_state';
$_pg_bg_abs    = $_pg_state_dir . '/page_bg.jpg';
$has_bg_image  = is_file($_pg_bg_abs) && @filesize($_pg_bg_abs) > 0;
// === Calcular alpha HEX del overlay SIN offsets (proporcional EXACTO al slider)
// overlay=0  => transparente = '00'  (ver imagen pura sin capa color encima)
// overlay=50 => mitad          = '7F'
// overlay=100=> muy oscuro     = 'FF'  (casi todo color, tapando casi toda la imagen)
$_a = max(0, min(255, (int)round(255 * (float)$overlay_dec)));
$_alphaTop = $_a;
$_alphaMid = $_a;
$_alphaBot = $_a;
// Si NO hay imagen subida: necesitamos un minimo de color para que el texto se lea.
// Si el usuario puso overlay 0 y NO hay imagen, forzamos 204 (~80%) para que se vea el look oscuro.
if (!$has_bg_image && $_a < 204) { $_alphaTop = $_alphaMid = $_alphaBot = 204; }
$_haTop = str_pad(dechex($_alphaTop), 2, '0', STR_PAD_LEFT);
$_haMid = str_pad(dechex($_alphaMid), 2, '0', STR_PAD_LEFT);
$_haBot = str_pad(dechex($_alphaBot), 2, '0', STR_PAD_LEFT);
$show_share   = !empty($cfg['show_share']);
$website_url  = trim((string)($cfg['website_url'] ?? ''));
$facebook_url = trim((string)($cfg['facebook_url'] ?? ''));
$whatsapp_url = trim((string)($cfg['whatsapp_url'] ?? ''));
$email_contacto = trim((string)($cfg['email_contacto'] ?? ''));
$nosotros       = trim((string)($cfg['nosotros'] ?? ''));
$nosotros_html  = !empty($cfg['nosotros_html']);
$terminos       = trim((string)($cfg['terminos'] ?? ''));
$terminos_html  = !empty($cfg['terminos_html']);
$instagram_url  = trim((string)($cfg['instagram_url'] ?? ''));
$tiktok_url     = trim((string)($cfg['tiktok_url'] ?? ''));
$youtube_url    = trim((string)($cfg['youtube_url'] ?? ''));
$x_url          = trim((string)($cfg['x_url'] ?? ''));
$appstore_url   = trim((string)($cfg['appstore_url'] ?? ''));
$playstore_url  = trim((string)($cfg['playstore_url'] ?? ''));
// === Lista de redes/webs con URL configurada (orden: FB, IG, TikTok, YT, X, web, WA) ===
$social_links = [];
foreach ([
    [$facebook_url,  'fa-brands fa-facebook-f', 'Facebook'],
    [$instagram_url, 'fa-brands fa-instagram',  'Instagram'],
    [$tiktok_url,    'fa-brands fa-tiktok',     'TikTok'],
    [$youtube_url,   'fa-brands fa-youtube',    'YouTube'],
    [$x_url,         'fa-brands fa-x-twitter',  'X'],
    [$website_url,   'fa-solid fa-globe',       'Sitio Web'],
    [$whatsapp_url,  'fa-brands fa-whatsapp',   'WhatsApp'],
] as $__sl) {
    if ($__sl[0] !== '') $social_links[] = $__sl;
}
unset($__sl);
// === Texto visible en filas de contacto (evita mostrar URL cruda) ===
$web_display = ($website_url !== '') ? preg_replace('#^https?://#i', '', $website_url) : '';
$wa_display  = '';
if ($whatsapp_url !== '') {
    $wa_display = 'WhatsApp';
    if (preg_match('#wa\.me/([0-9+\-()\s]+)(?:\?|$)#i', $whatsapp_url, $__m) && trim($__m[1]) !== '') {
        $wa_display = trim($__m[1]);
    } elseif (preg_match('#[?&]phone=([0-9+\-()\s]+)#i', $whatsapp_url, $__m) && trim($__m[1]) !== '') {
        $wa_display = trim($__m[1]);
    }
    unset($__m);
}
// ==== NUEVOS COLORES (default match look actual si viene vacío):
function rp_safe_color($v, $default){
    $v = trim((string)$v);
    if ($v === '') return $default;
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v)) return $v;
    return $default;
}
$def_c = rp_pg_default_config();
$bg_color_base    = rp_safe_color($cfg['bg_color_base']    ?? '', $def_c['bg_color_base']);
$header_bg_color  = rp_safe_color($cfg['header_bg_color']  ?? '', $def_c['header_bg_color']);
$main_bg_color    = rp_safe_color($cfg['main_bg_color']    ?? '', $def_c['main_bg_color']);
$footer_bg_color  = rp_safe_color($cfg['footer_bg_color']  ?? '', $def_c['footer_bg_color']);
// ==== COLORES DE TEXTO POR ZONA (defaults = look actual del tema) ====
$header_text_color  = rp_safe_color($cfg['header_text_color']  ?? '', '#f8fafc');
$header_icon_color  = rp_safe_color($cfg['header_icon_color']  ?? '', '#94a3b8');
$footer_text_color  = rp_safe_color($cfg['footer_text_color']  ?? '', '#64748b');
$app_text_color     = rp_safe_color($cfg['app_text_color']     ?? '', '#e2e8f0');
$app_title_color    = rp_safe_color($cfg['app_title_color']    ?? '', $app_text_color);
$app_subtitle_color = rp_safe_color($cfg['app_subtitle_color'] ?? '', $accent);
$c_label_color      = rp_safe_color($cfg['c_label_color']      ?? '', '#94a3b8');
$station_name_color = rp_safe_color($cfg['station_name_color'] ?? '', $accent);
$song_title_color   = rp_safe_color($cfg['song_title_color']   ?? '', '#ffffff');
$player_btn_color   = rp_safe_color($cfg['player_btn_color'] ?? '', $accent);
$player_vol_color   = rp_safe_color($cfg['player_vol_color'] ?? '', $accent);
$store_btn_bg       = rp_safe_color($cfg['store_btn_bg'] ?? '', 'rgba(255, 255, 255, 0.05)');
$store_btn_text     = rp_safe_color($cfg['store_btn_text'] ?? '', '#ffffff');
$store_btn_icon     = rp_safe_color($cfg['store_btn_icon'] ?? '', $accent);
$wa_btn_bg          = rp_safe_color($cfg['wa_btn_bg'] ?? '', '#25d366');
$wa_btn_text        = rp_safe_color($cfg['wa_btn_text'] ?? '', '#ffffff');
$clock_time_color   = rp_safe_color($cfg['clock_time_color'] ?? '', '#ffffff');
$clock_date_color   = rp_safe_color($cfg['clock_date_color'] ?? '', '#94a3b8');
// ==== PERSONALIZACIÓN POR SECCIÓN (vacío = color actual de la plantilla) ====
function rp_sec_color($v, $default){ return rp_safe_color($v, $default); }
$__sec_names = ['staff', 'programacion', 'patrocinadores', 'radios', 'escuchanos'];
$__sec_defaults = [
    'staff'         => ['bg' => 'rgba(30, 41, 59, 0.60)', 'bg_opacity' => 60, 'title' => $app_title_color, 'card_bg' => '#000000', 'card_bg_opacity' => 100, 'card_border' => 'rgba(255, 255, 255, 0.18)', 'card_text' => '#ffffff', 'role' => $accent],
    'programacion'  => ['bg' => 'rgba(12, 74, 110, 0.55)', 'bg_opacity' => 55, 'title' => $app_title_color, 'card_bg' => '#000000', 'card_bg_opacity' => 100, 'card_border' => '', 'card_text' => '#ffffff'],
    'patrocinadores'=> ['bg' => 'rgba(88, 28, 135, 0.55)', 'bg_opacity' => 55, 'title' => $app_title_color, 'card_bg' => '#000000', 'card_bg_opacity' => 100, 'card_border' => 'rgba(255, 255, 255, 0.18)', 'card_text' => '#ffffff'],
    'radios'        => ['bg' => 'rgba(6, 95, 70, 0.55)', 'bg_opacity' => 55, 'title' => $app_title_color, 'card_bg' => '#000000', 'card_bg_opacity' => 100, 'card_border' => '#ffffff', 'card_text' => '#ffffff'],
    'escuchanos'    => ['bg' => 'rgba(190, 18, 60, 0.50)', 'bg_opacity' => 50, 'title' => $app_title_color, 'card_bg' => '#000000', 'card_bg_opacity' => 100, 'card_border' => '#ffffff', 'card_text' => '#ffffff'],
];
function rp_sec_hexa($hex, $opPct){
    $hex = rp_safe_color($hex, '');
    if ($hex === '') return '';
    return rp_hex_with_alpha($hex, max(0, min(1, (float)$opPct / 100)));
}
$sec_cfg = [];
foreach ($__sec_names as $__sk) {
    $__sd = $__sec_defaults[$__sk];
    $_op = (int)($cfg['sec_' . $__sk . '_bg_opacity'] ?? $__sd['bg_opacity']);
    if ($_op < 5) $_op = 5; if ($_op > 100) $_op = 100;
    $_cop = (int)($cfg['sec_' . $__sk . '_card_bg_opacity'] ?? $__sd['card_bg_opacity']);
    if ($_cop < 5) $_cop = 5; if ($_cop > 100) $_cop = 100;
    $_bgHex = rp_sec_hexa($cfg['sec_' . $__sk . '_bg'] ?? '', $_op);
    $_cBgHex = rp_sec_hexa($cfg['sec_' . $__sk . '_card_bg'] ?? '', $_cop);
    $sec_cfg[$__sk] = [
        'bg'          => ($_bgHex !== '') ? $_bgHex : $__sd['bg'],
        'bg_opacity'  => $_op,
        'title'       => rp_sec_color($cfg['sec_' . $__sk . '_title'] ?? '', $__sd['title']),
        'icon'        => isset($cfg['sec_' . $__sk . '_icon']) ? (bool)$cfg['sec_' . $__sk . '_icon'] : true,
        'card_bg'     => ($_cBgHex !== '') ? $_cBgHex : $__sd['card_bg'],
        'card_bg_opacity' => $_cop,
        'card_border' => rp_sec_color($cfg['sec_' . $__sk . '_card_border'] ?? '', $__sd['card_border']),
        'card_text'   => rp_sec_color($cfg['sec_' . $__sk . '_card_text'] ?? '', $__sd['card_text']),
    ];
    if ($__sk === 'staff') $sec_cfg[$__sk]['role'] = rp_sec_color($cfg['sec_staff_role'] ?? '', $__sd['role']);
}
unset($__sk, $__sd, $_op, $_cop, $_bgHex, $_cBgHex, $__sec_names);
// === Footer global (superadmin → database.json 'public_footer') ===
$_pf = is_array($db['public_footer'] ?? null) ? $db['public_footer'] : [];
$public_footer_text = trim((string)($_pf['texto'] ?? ''));
if ($public_footer_text === '') $public_footer_text = 'Creada por amantes de la Radio';
$public_footer_etq  = trim((string)($_pf['etiqueta'] ?? ''));
$public_footer_link = trim((string)($_pf['link_texto'] ?? ''));
$public_footer_url  = trim((string)($_pf['url'] ?? ''));
if ($public_footer_url !== '' && $public_footer_link === '') {
    // Fallback: si el admin no escribió texto de enlace, mostrar el dominio de la URL
    $_pf_host = parse_url($public_footer_url, PHP_URL_HOST);
    $public_footer_link = $_pf_host ?: $public_footer_url;
}
// ====== OPACIDADES TRANSPARENCIA (nuevas) — clamp 5..100 % =========
$_hdr_op = (int)($cfg['header_bg_opacity'] ?? $def_c['header_bg_opacity']);
if ($_hdr_op < 5) $_hdr_op = 5; if ($_hdr_op > 100) $_hdr_op = 100;
$_main_op = (int)($cfg['main_bg_opacity'] ?? $def_c['main_bg_opacity']);
if ($_main_op < 5) $_main_op = 5; if ($_main_op > 100) $_main_op = 100;
$_ftr_op = (int)($cfg['footer_bg_opacity'] ?? $def_c['footer_bg_opacity']);
if ($_ftr_op < 5) $_ftr_op = 5; if ($_ftr_op > 100) $_ftr_op = 100;
// Convertimos % a float 0..1 (dividir entre 100). cards left-col/card-box son +4% respecto main (igual que antes 0.92 vs 0.88)
$_hdr_a  = (float)($_hdr_op  / 100);
$_main_a = (float)($_main_op / 100);
$_cards_a = (float)(min(100, $_main_op + 4) / 100); // left-col / card-box (anteriormente +4)
$_app_a   = (float)($_main_op / 100);                 // app-container glass exterior
$_ftr_a  = (float)($_ftr_op  / 100);
$_soc_a  = (float)(min(100, $_hdr_op + 4) / 100);     // circulos iconos redes sociales

// === URLs dinámicas ===============
$api_base = rp_abs_url('autodj_api.php');
// ==== Cache-buster de assets subidos (page_bg/page_logo/default_cover se sirven con
// 'immutable, max-age=1 año': si la URL no cambia al re-subir, el navegador muestra
// la imagen vieja para siempre. El '&t=' = filemtime fuerza URL nueva por cada subida.
function rp_pg_asset_ver($abs) {
    return (is_file($abs) && @filesize($abs) > 0) ? '&t=' . @filemtime($abs) : '';
}
$stats_url   = rp_abs_url('stats.php?mount=' . rawurlencode($mount) . '&json=1');
$logo_url   = rp_abs_url('autodj_api.php?action=serve_page_logo&mount=' . rawurlencode($mount) . rp_pg_asset_ver($_pg_state_dir . '/page_logo.jpg'));
$bg_url     = rp_abs_url('autodj_api.php?action=serve_page_bg&mount=' . rawurlencode($mount) . rp_pg_asset_ver($_pg_state_dir . '/page_bg.jpg'));
$def_cover  = rp_abs_url('autodj_api.php?action=serve_default_cover&mount=' . rawurlencode($mount) . rp_pg_asset_ver($_pg_state_dir . '/default_cover.jpg'));
$stream_url = rp_abs_url('/' . ltrim($mount, '/'));
$page_url   = rp_abs_url('radio_page.php?mount=' . rawurlencode($mount));

// URL que comparte el botón "Compartir": si el panel define una (p. ej. el dominio propio),
// se usa esa; si no, la de por defecto (radio_page.php?mount=...).
$share_url = trim((string)($cfg['share_url'] ?? ''));
if ($share_url === '') $share_url = $page_url;

// === Compartir WhatsApp ==================
$wa_share = rp_abs_url('https://wa.me/?text=' . rawurlencode('Escucha ' . $display_title . ' en vivo: ' . $page_url));

// === Placeholder cover inicial por si no hay ==========
$placeholder_cover = $def_cover;

// === STAFF + PROGRAMACIÓN semanal (parseo defensivo de solo-lectura) ===
function rp_landing_clean($v, $max) {
    $v = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\r\n]+/u', ' ', (string)$v));
    if (function_exists('mb_strlen')) { if (mb_strlen($v, 'UTF-8') > $max) $v = mb_substr($v, 0, $max, 'UTF-8'); }
    elseif (strlen($v) > $max) { $v = substr($v, 0, $max); }
    return $v;
}
function rp_landing_initials($nombre) {
    $ini = '';
    foreach (array_slice(preg_split('/\s+/u', trim((string)$nombre)), 0, 2) as $w) {
        if ($w === '') continue;
        $ini .= function_exists('mb_substr') ? mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($w, 0, 1));
    }
    return $ini === '' ? '?' : $ini;
}
function rp_landing_parse_staff($arr) {
    $out = [];
    if (!is_array($arr)) return $out;
    foreach (array_slice($arr, 0, 12) as $m) {
        if (!is_array($m)) continue;
        $id = preg_match('/^[A-Za-z0-9]{6,32}$/', (string)($m['id'] ?? '')) ? (string)$m['id'] : '';
        $foto = ($id !== '' && (string)($m['foto'] ?? '') === $id . '.jpg') ? $id . '.jpg' : '';
        $nombre = rp_landing_clean($m['nombre'] ?? '', 80);
        $cargo  = rp_landing_clean($m['cargo']  ?? '', 60);
        $desc   = rp_landing_clean($m['desc']   ?? '', 200);
        if ($nombre === '' && $cargo === '' && $desc === '' && $foto === '') continue;
        $out[] = ['id' => $id, 'foto' => $foto, 'nombre' => $nombre, 'cargo' => $cargo, 'desc' => $desc];
    }
    return $out;
}
function rp_landing_parse_prog($arr) {
    $days = [1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => [], 7 => []];
    if (!is_array($arr)) return $days;
    foreach (array_slice($arr, 0, 60) as $b) {
        if (!is_array($b)) continue;
        // dias explícitos o legacy dia:n
        $dias = [];
        if (isset($b['dias']) && is_array($b['dias'])) {
            foreach ($b['dias'] as $dd) { $n = (int)$dd; if ($n >= 1 && $n <= 7 && !in_array($n, $dias, true)) $dias[] = $n; }
        } elseif (isset($b['dia'])) {
            $n = (int)$b['dia']; if ($n >= 1 && $n <= 7) $dias = [$n];
        }
        if (!$dias) continue;
        $ini = trim((string)($b['inicio'] ?? ''));
        $fin = trim((string)($b['fin'] ?? ''));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $ini) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $fin)) continue;
        if ($fin <= $ini) continue;
        $titulo = rp_landing_clean($b['titulo'] ?? '', 90);
        $conductor = rp_landing_clean($b['conductor'] ?? ($b['desc'] ?? ''), 160);
        if ($titulo === '') continue; // el título es obligatorio (el conductor es opcional)
        foreach ($dias as $dia) {
            $days[$dia][] = ['dia' => $dia, 'inicio' => $ini, 'fin' => $fin, 'titulo' => $titulo, 'conductor' => $conductor];
        }
    }
    foreach ($days as $d => $list) {
        usort($list, function ($a, $b) { return strcmp($a['inicio'], $b['inicio']); });
        $days[$d] = $list;
    }
    return $days;
}
$staff_items = rp_landing_parse_staff($cfg['staff'] ?? null);
if (!empty($staff_items)) {
    $staff_api = rp_abs_url('autodj_api.php?action=serve_staff_photo&mount=' . rawurlencode($mount));
    foreach ($staff_items as $i => $m) {
        $abs = $_pg_state_dir . '/staff/' . $m['id'] . '.jpg';
        $staff_items[$i]['photo_url'] = is_file($abs) ? $staff_api . '&id=' . rawurlencode($m['id']) . rp_pg_asset_ver($abs) : '';
        $staff_items[$i]['initial'] = rp_landing_initials($m['nombre']);
    }
    unset($i, $m);
}
$prog_days = rp_landing_parse_prog($cfg['programacion'] ?? null);

// ==== SECCIONES VINCULADAS: patrocinadores / radios / escuchanos ====
function rp_landing_parse_links($arr) {
    $out = [];
    if (!is_array($arr)) return $out;
    foreach (array_slice($arr, 0, 20) as $it) {
        if (!is_array($it)) continue;
        $id = preg_match('/^[A-Za-z0-9]{6,32}$/', (string)($it['id'] ?? '')) ? (string)$it['id'] : '';
        $logo = ($id !== '' && (string)($it['logo'] ?? '') === $id . '.jpg') ? $id . '.jpg' : '';
        $nombre = rp_landing_clean($it['nombre'] ?? '', 120);
        $link = trim((string)($it['link'] ?? ''));
        if ($nombre === '' && $link === '' && $logo === '') continue;
        $out[] = ['id' => $id, 'logo' => $logo, 'nombre' => $nombre, 'link' => $link];
    }
    return $out;
}
$patrocinadores = rp_landing_parse_links($cfg['patrocinadores'] ?? null);
$radios_links   = rp_landing_parse_links($cfg['radios'] ?? null);
$escuchanos     = rp_landing_parse_links($cfg['escuchanos'] ?? null);
$link_abs = $_pg_state_dir . '/staff/';
foreach ([&$patrocinadores, &$radios_links, &$escuchanos] as $__lk => &$__lst) {
    foreach ($__lst as $__i => $__it) {
        if ($__it['id'] !== '' && $__it['logo'] !== '' && is_file($link_abs . $__it['id'] . '.jpg')) {
            $__lst[$__i]['logo_url'] = rp_abs_url('autodj_api.php?action=serve_staff_photo&mount=' . rawurlencode($mount) . '&id=' . rawurlencode($__it['id']) . rp_pg_asset_ver($link_abs . $__it['id'] . '.jpg'));
        } else {
            $__lst[$__i]['logo_url'] = '';
        }
        $__lst[$__i]['initial'] = rp_landing_initials($__it['nombre']);
    }
    unset($__i, $__it);
}
unset($__lst, $__lk);
$link_sections = [];
if (!empty($cfg['show_patrocinadores']) && !empty($patrocinadores)) $link_sections[] = ['key' => 'patrocinadores', 'titulo' => 'Patrocinadores', 'icono' => 'fa-solid fa-handshake', 'items' => $patrocinadores];
if (!empty($cfg['show_radios']) && !empty($radios_links)) $link_sections[] = ['key' => 'radios', 'titulo' => 'Nuestras Radios', 'icono' => 'fa-solid fa-tower-broadcast', 'items' => $radios_links];
if (!empty($cfg['show_escuchanos']) && !empty($escuchanos)) $link_sections[] = ['key' => 'escuchanos', 'titulo' => 'Dónde Nos Puedes Escuchar', 'icono' => 'fa-solid fa-headphones', 'items' => $escuchanos];

// ==== GATES de secciones (única fuente de verdad: header, paneles y footer) ====
$__show_staff    = !empty($staff_items) && !empty($cfg['show_staff']);
$__show_prog     = false;
foreach ([1, 2, 3, 4, 5, 6, 7] as $__d) { if (!empty($prog_days[$__d])) { $__show_prog = true; break; } }
$__show_prog     = $__show_prog && !empty($cfg['show_programacion']);
$__show_terminos = ($terminos !== '');
$__lk            = array_column($link_sections, 'key');
$__show_patro    = in_array('patrocinadores', $__lk, true);
$__show_radios   = in_array('radios',        $__lk, true);
$__show_escucha  = in_array('escuchanos',    $__lk, true);
unset($__d, $__lk);

// ==== El HTML es 100% inline: sin esto un proxy/navegador puede servir el layout viejo tras un deploy ====
if (!headers_sent()) { header('Cache-Control: no-cache, must-revalidate'); }
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($display_title) ?> — En Vivo</title>
<meta name="description" content="<?= htmlspecialchars($display_title . ($subtitle !== '' ? ' — ' . $subtitle : '')) ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer">
<meta name="theme-color" content="<?= htmlspecialchars($accent) ?>">
<meta property="og:title" content="<?= htmlspecialchars($display_title) ?> — En Vivo">
<meta property="og:description" content="<?= htmlspecialchars($subtitle) ?>">
<meta property="og:image" content="<?= htmlspecialchars($logo_url) ?>">
<link rel="icon" type="image/jpeg" href="<?= htmlspecialchars($logo_url) ?>">
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body {
    margin: 0; padding: 0; min-height: 100vh;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: <?= $has_bg_image ? 'transparent' : htmlspecialchars($bg_color_base) ?>;
    color: <?= htmlspecialchars($text_color) ?>;
}
body {
    display: flex; flex-direction: column;
    margin: 0; padding: 0;
    min-height: 100vh;
    overflow-x: hidden;
}
.bg-layer {
    position: fixed; inset: 0; z-index: -2;
    <?php if ($has_bg_image): ?>
    /* OJO: NO usar htmlspecialchars aquí: dentro de <style> el navegador NO decodifica
       &amp; y la URL llegaría con 'amp;mount=...' → el servidor pierde el mount y sirve
       el fondo de la PRIMERA radio (fallback). Se escapa solo " y \ (CSS-safe). */
    background-image: url("<?= addcslashes($bg_url, "\"\\") ?>");
    background-size: cover; background-position: center; background-repeat: no-repeat;
    background-color: transparent;
    <?php else: ?>
    background-image: none;
    background-color: <?= htmlspecialchars($bg_color_base) ?>;
    <?php endif; ?>
}
.overlay-layer {
    position: fixed; inset: 0; z-index: -1;
    <?php if ($has_bg_image): ?>
    background:
        linear-gradient(180deg,
            <?= htmlspecialchars($bg_color_base) ?><?= $_haTop ?> 0%,
            <?= htmlspecialchars($bg_color_base) ?><?= $_haMid ?> 50%,
            <?= htmlspecialchars($bg_color_base) ?><?= $_haBot ?> 100%);
    <?php else: ?>
    background: transparent;
    <?php endif; ?>
}

/* === AJUSTES: colores rgba dinámicos === */
<?php
function rp_hex_with_alpha($hex, $alphaDecimal){
    $hex = ltrim(trim((string)$hex), '#');
    if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
    $hex6 = substr($hex, 0, 6);
    $a = max(0, min(255, (int)round(255 * (float)$alphaDecimal)));
    $ha = str_pad(dechex($a), 2, '0', STR_PAD_LEFT);
    return '#' . $hex6 . $ha;
}
$hdr_rgba = rp_hex_with_alpha($header_bg_color, $_hdr_a);
$main_rgba = rp_hex_with_alpha($main_bg_color, $_cards_a);
$app_rgba  = rp_hex_with_alpha($main_bg_color, $_app_a);
$ftr_rgba  = rp_hex_with_alpha($footer_bg_color, $_ftr_a);
$social_rgba = rp_hex_with_alpha($header_bg_color, $_soc_a);
?>

/* === LAYOUT LANDING (contenedor 90% ancho centrado) === */
.wrap {
    width: 90%;
    max-width: none;
    margin: 0 auto;
    padding: 0;
}
.site-header {
    background: <?= $hdr_rgba ?>;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}
.header-inner {
    display: flex; justify-content: space-between; align-items: center;
    gap: 12px;
    padding: 14px 0;
}
.site-container {
    width: 100%;
    max-width: none;
    margin: 0 auto;
    flex: 1 0 auto;
    padding: 0;
    display: flex;
    flex-direction: column;
}
.hero-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    flex: 1;
    min-width: 0;
    width: 100%;
}
.hero-player {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 100%;
    border-bottom: 2px solid <?= $main_rgba ?>;
}
.hero-player .left-col { max-width: none; }

/* --- HEADER --- */
.station-brand {
    display: flex; align-items: center; gap: 12px;
}
.station-brand i { font-size: 1.5rem; color: <?= htmlspecialchars($accent) ?>; }
.station-brand h1 {
    font-size: 1.2rem; font-weight: 700; letter-spacing: 0.5px; margin: 0;
    color: <?= htmlspecialchars($header_text_color) ?>;
    text-transform: uppercase;
}
.header-actions { display: flex; align-items: center; gap: 12px; }
.social-btn {
    display: flex; align-items: center; justify-content: center;
    width: 38px; height: 38px; border-radius: 50%;
    background: <?= $social_rgba ?>;
    color: <?= htmlspecialchars($header_icon_color) ?>;
    text-decoration: none;
    transition: all 0.2s ease;
    border: 1px solid rgba(255, 255, 255, 0.05);
    cursor: pointer;
    font-size: 0.95rem;
}
.social-btn:hover {
    color: #ffffff;
    background: <?= htmlspecialchars($accent) ?>;
    transform: translateY(-2px);
}

/* --- MAIN --- */

/* --- HERO COLUMNA DERECHA (info / contacto) --- */
.right-col {
    background: <?= $main_rgba ?>;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 0;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
    padding: 0;                        /* el padding lo aporta .rp-page */
    display: flex;
    flex-direction: column;
    justify-content: flex-start;       /* las páginas empiezan arriba */
    width: 100%;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}
.info-title {
    margin: 0;
    font-size: clamp(1.6rem, 3vw, 2.3rem);
    font-weight: 800;
    color: <?= htmlspecialchars($app_title_color) ?>;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    line-height: 1.15;
}
.info-sub {
    margin: 8px 0 0;
    font-size: 1.05rem;
    color: <?= htmlspecialchars($app_subtitle_color) ?>;
}
.station-clock {
    display: flex; flex-direction: column; gap: 2px;
    margin-top: 20px;
}
.clock-time {
    display: flex; align-items: center; gap: 10px;
    font-size: 1.9rem; font-weight: 800; color: <?= htmlspecialchars($clock_time_color) ?>;
    font-variant-numeric: tabular-nums; line-height: 1.1;
}
.clock-date {
    font-size: 0.85rem; color: <?= htmlspecialchars($clock_date_color) ?>;
    text-transform: capitalize; letter-spacing: 0.2px;
}
.contact-rows {
    display: flex; flex-direction: column; gap: 16px;
    margin-top: 22px; padding-top: 20px;
    border-top: 1px dashed rgba(255, 255, 255, 0.12);
}
.contact-row {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 12px 18px;
    align-items: baseline;
}
.contact-row.block {
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: flex-start;
}
.c-label {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 0.8rem; font-weight: 700; color: <?= htmlspecialchars($c_label_color) ?>;
    text-transform: uppercase; letter-spacing: 0.5px;
    white-space: nowrap;
}
.c-label i { width: 15px; text-align: center; color: <?= htmlspecialchars($accent) ?>; }
.c-value {
    font-size: 1rem; color: <?= htmlspecialchars($app_text_color) ?>;
    line-height: 1.5; word-break: break-word;
    text-decoration: none;
}
a.c-value:hover { color: <?= htmlspecialchars($accent) ?>; }
.c-value a { color: <?= htmlspecialchars($accent) ?>; text-decoration: underline; word-break: break-word; }
.contact-socials {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-top: 26px;
}
.cta-wa {
    display: inline-flex; align-items: center; gap: 10px;
    background: <?= htmlspecialchars($wa_btn_bg) ?>; color: <?= htmlspecialchars($wa_btn_text) ?>; font-weight: 700; font-size: 0.98rem;
    padding: 12px 22px; border-radius: 999px; text-decoration: none;
    transition: all 0.2s ease;
    box-shadow: 0 6px 18px rgba(37, 211, 102, 0.35);
}
.cta-wa:hover {
    color: <?= htmlspecialchars($wa_btn_text) ?>;
    filter: brightness(0.94);
    transform: translateY(-2px);
}
/* Descarga nuestra app (App Store / Google Play) */
.store-box {
    display: flex; flex-direction: column; align-items: center;
    gap: 12px; margin-top: 20px; padding-top: 18px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}
.store-label {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 0.85rem; font-weight: 800; letter-spacing: 0.4px; text-transform: uppercase;
    color: <?= htmlspecialchars($app_text_color) ?>;
}
.store-buttons {
    display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;
}
.store-btn {
    display: inline-flex; align-items: center; gap: 9px;
    padding: 10px 20px; border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.14);
    background: <?= htmlspecialchars($store_btn_bg) ?>;
    color: <?= htmlspecialchars($store_btn_text) ?>; font-size: 0.92rem; font-weight: 700;
    text-decoration: none; transition: all 0.15s ease;
}
.store-btn i { font-size: 1.2rem; color: <?= htmlspecialchars($store_btn_icon) ?>; }
.store-btn:hover {
    color: <?= htmlspecialchars($store_btn_text) ?>; border-color: <?= htmlspecialchars($accent) ?>;
    transform: translateY(-1px);
}

@media (max-width: 1024px) {
    .site-header { position: sticky; top: 0; z-index: 50; }   /* header sticky en móvil/tablet */
    .hero-grid { grid-template-columns: 1fr; flex: none; }
    .hero-player, .right-col { width: 100%; }
    .right-col { scroll-margin-top: 70px; }                   /* no quedar tapado por el header sticky */
}
@media (max-width: 640px) {
    .wrap, .site-container { width: 100%; }
    .wrap { padding: 0 14px; }
    .site-container { padding-left: 0; padding-right: 0; }
    .right-col { border-radius: 0; scroll-margin-top: 100px; }  /* header en 2 filas */
    .header-inner {
        flex-direction: column;
        justify-content: center;
        padding: 12px 0;
    }
    .station-brand { justify-content: center; text-align: center; }
    .header-actions {
        flex-wrap: wrap;
        justify-content: center;
        gap: 8px;
    }
    .station-brand h1 { font-size: 1.08rem; }
}

/* Reproductor */
.left-col {
    display: flex; flex-direction: column; align-items: center;
    padding: 24px;
    position: relative;
    min-width: 0;
    width: 100%;
    max-width: 480px;
}
.badge-live {
    position: absolute;
    top: 16px; right: 16px;
    background: #ef4444;
    color: white;
    font-size: 0.7rem;
    font-weight: bold;
    padding: 4px 10px;
    border-radius: 20px;
    display: none;
    align-items: center;
    gap: 5px;
    animation: pulse 1.6s infinite;
    letter-spacing: 0.4px;
}
.badge-live.on { display: inline-flex; }
@keyframes pulse {
    0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(239,68,68,0.55); }
    50% { opacity: 0.75; box-shadow: 0 0 0 6px rgba(239,68,68,0); }
}

.main-cover {
    width: clamp(210px, 58%, 340px);
    aspect-ratio: 1 / 1;
    height: auto;
    border-radius: 18px;
    object-fit: cover;
    margin: 14px 0 18px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
    background: #334155;
    background-color: #334155;
}
.player-info {
    text-align: center; width: 100%;
    margin-bottom: 18px;
}
.player-station {
    font-size: clamp(1.7rem, 4vw, 2.3rem);
    color: <?= htmlspecialchars($station_name_color) ?>;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
    line-height: 1.2;
}
.player-song {
    font-size: 1.1rem;
    font-weight: bold;
    color: <?= htmlspecialchars($song_title_color) ?>;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    word-break: break-word;
    margin: 0;
    line-height: 1.25;
    min-height: calc(1.1rem * 1.25 * 2);
}
.player-artist {
    font-size: 0.9rem;
    color: #94a3b8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 2px;
}
.player-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    font-size: 0.8rem;
    color: #94a3b8;
    margin-top: 6px;
    min-height: 1.3em;
}
.player-loading[hidden] { display: none; }
.player-loading .ldot {
    width: 4px; height: 4px;
    border-radius: 50%;
    background: <?= htmlspecialchars($accent) ?>;
    animation: blinkDot 1.2s infinite ease-in-out;
}
.player-loading .ldot:nth-child(2) { animation-delay: .2s; }
.player-loading .ldot:nth-child(3) { animation-delay: .4s; }
@keyframes blinkDot { 0%, 80%, 100% { opacity: .2; transform: scale(.8); } 40% { opacity: 1; transform: scale(1); } }

/* Ecualizador: se muestra mientras suena (tras terminar de cargar) */
.player-eq {
    display: flex;
    align-items: flex-end;
    justify-content: center;
    gap: 3px;
    height: 16px;
    margin-top: 8px;
}
.player-eq[hidden] { display: none; }
.player-eq span {
    width: 3px;
    height: 100%;
    border-radius: 2px;
    background: <?= htmlspecialchars($accent) ?>;
    animation: eqBar 1s infinite ease-in-out;
}
.player-eq span:nth-child(2) { animation-delay: .15s; }
.player-eq span:nth-child(3) { animation-delay: .3s; }
.player-eq span:nth-child(4) { animation-delay: .45s; }
.player-eq span:nth-child(5) { animation-delay: .6s; }
@keyframes eqBar {
    0%, 100% { height: 25%; }
    50% { height: 100%; }
}

/* Controles */
.volume-container {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    margin-bottom: 18px;
}
.volume-slider {
    flex: 1;
    accent-color: <?= htmlspecialchars($player_vol_color) ?>;
    cursor: pointer;
}
.btn-play {
    background: <?= htmlspecialchars($player_btn_color) ?>;
    border: none;
    width: 58px; height: 58px;
    border-radius: 50%;
    color: white;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
    transition: all 0.2s ease;
    box-shadow: 0 4px 16px rgba(2, 132, 199, 0.4);
}
.btn-play:hover {
    filter: brightness(0.92);
    transform: scale(1.06);
}
.spinner {
    width: 22px; height: 22px;
    border: 3px solid rgba(255, 255, 255, 0.3);
    border-top-color: #ffffff;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* --- FOOTER --- */
.site-footer {
    background: <?= $ftr_rgba ?>;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    font-size: 0.85rem;
    color: <?= htmlspecialchars($footer_text_color) ?>;
}
.site-footer p {
    margin: 0;
    text-align: right;
    padding: 0;                        /* el padding lo aporta .footer-inner */
}
.site-footer a {
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 3px;
}
.site-footer a:hover {
    color: <?= htmlspecialchars($accent) ?>;
}

/* ===== Secciones dentro del panel derecho (una página cada una; el fondo lo pone el panel) ===== */
.pg-cols-wrap {
    width: 100%;
    padding: 0;                        /* el padding lo aporta .rp-page */
}
.pg-cols-wrap > section {
    width: 100%;
    max-width: none;
    margin: 0;
}
/* Color de título por sección (el fondo de banda ya no se usa: panel único) */
.pg-cols-wrap-staff .pg-block-title          { color: <?= htmlspecialchars($sec_cfg['staff']['title']) ?>; }
.pg-cols-wrap-programacion .pg-block-title   { color: <?= htmlspecialchars($sec_cfg['programacion']['title']) ?>; }
.pg-cols-wrap-patrocinadores .pg-block-title { color: <?= htmlspecialchars($sec_cfg['patrocinadores']['title']) ?>; }
.pg-cols-wrap-radios .pg-block-title         { color: <?= htmlspecialchars($sec_cfg['radios']['title']) ?>; }
.pg-cols-wrap-escuchanos .pg-block-title     { color: <?= htmlspecialchars($sec_cfg['escuchanos']['title']) ?>; }
.pg-col { min-width: 0; }
.pg-block-title {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    margin: 0 0 clamp(12px, 2vw, 18px);
    color: <?= htmlspecialchars($app_title_color) ?>;
    font-size: clamp(1.15rem, 2vw, 1.35rem); font-weight: 800; letter-spacing: 0.4px; text-transform: uppercase;
    text-align: center;
}
.pg-block-title i { color: <?= htmlspecialchars($accent) ?>; }
/* Staff: una sola columna (listado vertical, filas sin fondo propio) */
.staff-grid {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.staff-card {
    display: flex; gap: 16px; align-items: center;
    padding: 14px 16px;
    border: 1px solid <?= htmlspecialchars($sec_cfg['staff']['card_border']) ?>;
    border-radius: 14px;
    background: <?= htmlspecialchars($sec_cfg['staff']['card_bg']) ?>;
}
.staff-photo-wrap {
    position: relative; flex: 0 0 auto;
    width: 96px; height: 96px;       /* avatar de listado */
    aspect-ratio: 1 / 1;
    border-radius: 14px; overflow: hidden;
}
.staff-avatar {
    display: flex; align-items: center; justify-content: center;
    width: 100%; height: 100%; border-radius: 14px;
    color: #fff; font-weight: 800; font-size: clamp(1.1rem, 2.4vw, 1.6rem);
    background: linear-gradient(135deg, <?= htmlspecialchars($accent) ?>, #0ea5e9);
}
.staff-photo {
    position: absolute; inset: 0;
    width: 100%; height: 100%; object-fit: cover; border-radius: 14px;
}
.staff-info { min-width: 0; }
.staff-info h3 { margin: 2px 0 0; color: <?= htmlspecialchars($sec_cfg['staff']['card_text']) ?>; font-size: clamp(1.25rem, 2vw, 1.5rem); }
.staff-role {
    margin: 2px 0 0; color: <?= htmlspecialchars($sec_cfg['staff']['role']) ?>;
    font-size: 0.85rem; font-weight: 700;
}
.staff-desc {
    margin: 5px 0 0; color: <?= htmlspecialchars($sec_cfg['staff']['card_text']) ?>;
    font-size: 0.9rem; line-height: 1.45;
}
/* Programación: pestañas subrayadas, bloques sin fondo */
.prog-tabs {
    display: flex; flex-wrap: wrap; justify-content: center; gap: 2px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    margin: 0 0 6px;
}
.prog-tab-btn {
    border: 0; background: transparent;
    color: <?= htmlspecialchars($app_text_color) ?>;
    padding: 9px 12px; margin: 0 2px -1px 0;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    font-size: 0.88rem; font-weight: 800; letter-spacing: 0.3px; text-transform: uppercase;
    transition: color 0.15s ease, border-color 0.15s ease;
}
.prog-tab-btn:hover { color: #fff; }
.prog-tab-btn.active { color: <?= htmlspecialchars($accent) ?>; border-bottom-color: <?= htmlspecialchars($accent) ?>; }
.prog-pane { display: none; }
.prog-pane.active { display: block; }
.prog-empty-day {
    padding: 14px 12px; text-align: center;
    color: <?= htmlspecialchars($c_label_color) ?>;
    font-size: 0.9rem;
    border: 1px dashed rgba(255, 255, 255, 0.12);
    border-radius: 10px;
}
.prog-blocks { display: flex; flex-direction: column; gap: 10px; }
.prog-block {
    display: flex; gap: 14px; align-items: center;
    padding: 14px 16px;
    background: <?= htmlspecialchars($sec_cfg['programacion']['card_bg']) ?>;
    <?php if ($sec_cfg['programacion']['card_border'] !== ''): ?>border: 1px solid <?= htmlspecialchars($sec_cfg['programacion']['card_border']) ?>;
    <?php endif; ?>border-radius: 10px;
    color: <?= htmlspecialchars($sec_cfg['programacion']['card_text']) ?>;
}
.prog-time {
    flex: 0 0 auto; min-width: 88px;
    color: <?= htmlspecialchars($sec_cfg['programacion']['card_text']) ?>;
    font-weight: 800; font-size: 0.85rem; font-variant-numeric: tabular-nums;
}
.prog-title {
    flex: 1 1 auto; min-width: 0;
    color: <?= htmlspecialchars($sec_cfg['programacion']['card_text']) ?>; font-size: 0.98rem; line-height: 1.35;
}
.prog-host {
    flex: 0 1 auto; margin-left: auto;
    color: <?= htmlspecialchars($sec_cfg['programacion']['card_text']) ?>;
    font-size: 0.88rem; line-height: 1.35;
    text-align: right; font-style: italic; opacity: 0.9;
}
@media (max-width: 640px) {
    .pg-block-title { font-size: 1.1rem; }
    .pg-cols-wrap { padding: 0; }   /* el padding lo aporta .rp-page */
    /* Staff en móvil: foto grande (casi todo el ancho) con nombre / puesto / descripción debajo */
    .staff-card { flex-direction: column; align-items: center; text-align: center; gap: 12px; }
    .staff-photo-wrap { width: 100%; height: auto; }
    .staff-avatar { font-size: clamp(2rem, 14vw, 3.6rem); }
    .staff-info h3 { font-size: 1.3rem; }
    .prog-block { flex-direction: column; gap: 6px; align-items: flex-start; }
    .prog-title { width: 100%; }
    .prog-host { margin-left: auto; width: 100%; }
}

/* ===== Secciones vinculadas: Patrocinadores / Nuestras Radios / Dónde escucharnos ===== */
.pg-links { min-width: 0; }
.links-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 12px;
}
.link-card {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px; border-radius: 14px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #ffffff; text-decoration: none;
    transition: all 0.15s ease;
    min-width: 0;
}
.link-card:hover { border-color: <?= htmlspecialchars($accent) ?>; transform: translateY(-1px); }
.link-logo-wrap {
    position: relative; flex: 0 0 auto;
    width: 46px; height: 46px; border-radius: 12px; overflow: hidden;
}
.link-logo-fb {
    display: flex; align-items: center; justify-content: center;
    width: 100%; height: 100%; border-radius: 12px;
    color: #fff; font-weight: 800; font-size: 1rem;
    background: linear-gradient(135deg, <?= htmlspecialchars($accent) ?>, #0ea5e9);
}
.link-logo {
    position: absolute; inset: 0;
    width: 100%; height: 100%; object-fit: cover; border-radius: 12px;
}
.link-name {
    flex: 1 1 auto; min-width: 0;
    font-size: 0.95rem; font-weight: 700;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.link-go {
    flex: 0 0 auto;
    color: <?= htmlspecialchars($accent) ?>;
    font-size: 0.85rem; opacity: 0.85;
}
/* Patrocinadores: card vertical con logo grande arriba y nombre abajo, 4 columnas (sin flecha) */
#pg-patrocinadores .links-grid {
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 14px;
}
#pg-patrocinadores .link-card {
    flex-direction: column;
    align-items: stretch;
    text-align: center;
    padding: 14px;
    gap: 12px;
    border: 1px solid <?= htmlspecialchars($sec_cfg['patrocinadores']['card_border']) ?>;
    background: <?= htmlspecialchars($sec_cfg['patrocinadores']['card_bg']) ?>;
    color: <?= htmlspecialchars($sec_cfg['patrocinadores']['card_text']) ?>;
}
#pg-patrocinadores .link-logo-wrap {
    width: 100%; height: auto; aspect-ratio: 1 / 1;   /* logo grande (~280px con 4 col en 1200px) */
    border-radius: 14px;
}
#pg-patrocinadores .link-logo,
#pg-patrocinadores .link-logo-fb { border-radius: 14px; }
#pg-patrocinadores .link-logo-fb { font-size: clamp(1.8rem, 3vw, 2.6rem); }
#pg-patrocinadores .link-name {
    white-space: normal; overflow: visible; text-overflow: clip;
    line-height: 1.25;
}
@media (max-width: 900px) {
    #pg-patrocinadores .links-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
/* Nuestras Radios y Dónde Nos Puedes Escuchar: cards horizontales con el grupo centrado */
#pg-radios .links-grid,
#pg-escuchanos .links-grid {
    display: flex; flex-wrap: wrap; justify-content: center;
    gap: 12px;
}
#pg-radios .link-card {
    width: 240px; max-width: 100%;
    border: 1px solid <?= htmlspecialchars($sec_cfg['radios']['card_border']) ?>;
    background: <?= htmlspecialchars($sec_cfg['radios']['card_bg']) ?>;
    color: <?= htmlspecialchars($sec_cfg['radios']['card_text']) ?>;
}
#pg-escuchanos .link-card {
    width: 240px; max-width: 100%;
    border: 1px solid <?= htmlspecialchars($sec_cfg['escuchanos']['card_border']) ?>;
    background: <?= htmlspecialchars($sec_cfg['escuchanos']['card_bg']) ?>;
    color: <?= htmlspecialchars($sec_cfg['escuchanos']['card_text']) ?>;
}
#pg-radios .link-go { color: <?= htmlspecialchars($sec_cfg['radios']['card_text']) ?>; }
#pg-escuchanos .link-go { color: <?= htmlspecialchars($sec_cfg['escuchanos']['card_text']) ?>; }

/* ===== LAYOUT: 2 columnas (player | panel); la página entera scrollea ===== */
.rp-pages-scroll { width: 100%; }
.rp-page { display: none; width: 100%; min-width: 0; padding: clamp(22px, 3.2vw, 40px); }
.rp-page.active { display: block; }
.rp-page-rich { color: <?= htmlspecialchars($app_text_color) ?>; font-size: 0.98rem; line-height: 1.6; word-break: break-word; }
.rp-page-rich a { color: <?= htmlspecialchars($accent) ?>; text-decoration: underline; }

/* Menú del header */
.station-brand h1 a.brand-link { color: inherit; text-decoration: none; }
.site-nav { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.nav-btn { background: transparent; border: 0; cursor: pointer; font: inherit; white-space: nowrap;
    color: <?= htmlspecialchars($header_text_color) ?>; font-size: 0.82rem; font-weight: 700;
    letter-spacing: 0.4px; text-transform: uppercase; padding: 8px 12px; border-radius: 999px;
    transition: all 0.18s ease; }
.nav-btn:hover  { color: #ffffff; background: rgba(255, 255, 255, 0.06); }
.nav-btn.active { color: <?= htmlspecialchars($accent) ?>; background: rgba(255, 255, 255, 0.08); }

/* Footer: menú a la izquierda · Soporte a la derecha */
.footer-inner { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px 20px; padding: 14px 20px; }
.footer-nav { display: flex; flex-wrap: wrap; justify-content: flex-start; gap: 6px 18px; }
.footer-nav-btn { background: none; border: 0; padding: 0; font: inherit; color: inherit; cursor: pointer;
    text-decoration: underline; text-underline-offset: 3px; }
.footer-nav-btn:hover, .footer-nav-btn.active { color: <?= htmlspecialchars($accent) ?>; }

/* Iconos sociales flotantes: vertical, pegado a la izquierda (5px), centrado */
.social-float { position: fixed; left: 5px; top: 50%; transform: translateY(-50%); z-index: 60;
    display: flex; flex-direction: column; gap: 10px; }

/* Escritorio: misma columna doble, pero SIN viewport fijo ni scroll interno.
   La página completa scrollea (evita el doble scroll player/info en monitores medianos). */
@media (min-width: 1025px) {
    .hero-player { border-bottom: 0; border-right: 1px solid rgba(255, 255, 255, 0.06); }
}
@media (max-width: 640px)  { .site-nav { justify-content: center; } }
</style>
</head>
<body>
<div class="bg-layer" aria-hidden="true"></div>
<div class="overlay-layer" aria-hidden="true"></div>

<header class="site-header">
    <div class="wrap header-inner">
        <div class="station-brand">
            <h1><a class="brand-link" href="#" data-rp-nav="home"><?= htmlspecialchars($display_title) ?></a></h1>
        </div>

        <nav class="header-actions site-nav" aria-label="Menú principal">
            <button type="button" class="nav-btn active" data-rp-nav="home">Home</button>
            <?php if ($__show_staff): ?>
            <button type="button" class="nav-btn" data-rp-nav="staff">Staff</button>
            <?php endif; ?>
            <?php if ($__show_prog): ?>
            <button type="button" class="nav-btn" data-rp-nav="programacion">Programación</button>
            <?php endif; ?>
            <?php if ($__show_patro): ?>
            <button type="button" class="nav-btn" data-rp-nav="patrocinadores">Patrocinadores</button>
            <?php endif; ?>
            <button id="share-btn" class="social-btn" type="button" title="Compartir"><i class="fa-solid fa-share-nodes"></i></button>
        </nav>
    </div>
</header>

<main class="site-container">
    <section class="hero-grid">

        <div class="hero-player">
            <section class="left-col">
                <span id="badge-live" class="badge-live"><i class="fa-solid fa-circle" style="font-size: 6px;"></i> <span id="badge-live-text">EN VIVO</span></span>

                <img id="current-cover" class="main-cover" src="<?= htmlspecialchars($placeholder_cover) ?>" alt="Carátula" onerror="this.onerror=null;this.src='<?= htmlspecialchars($def_cover) ?>';">

                <div class="player-info">
                    <p class="player-station"><?= htmlspecialchars($display_title) ?></p>
                    <h2 id="current-title" class="player-song">Cargando canción...</h2>
                    <div id="player-loading" class="player-loading"><span>Cargando</span><span class="ldot"></span><span class="ldot"></span><span class="ldot"></span></div>
                    <div id="player-eq" class="player-eq" aria-hidden="true" hidden><span></span><span></span><span></span><span></span><span></span></div>
                </div>

                <div class="volume-container">
                    <i class="fa-solid fa-volume-low" style="color: #94a3b8; font-size: 0.85rem;"></i>
                    <input type="range" id="volume-slider" class="volume-slider" min="0" max="1" step="0.05" value="0.85" aria-label="Volumen">
                    <i class="fa-solid fa-volume-high" style="color: #94a3b8; font-size: 0.85rem;"></i>
                </div>

                <button id="play-btn" class="btn-play" title="Reproducir / Pausar" aria-label="Reproducir / Pausar">
                    <span id="btn-icon"><i class="fa-solid fa-play"></i></span>
                </button>
            </section>
        </div>

        <aside class="right-col">
          <div class="rp-pages-scroll" id="rp-pages-scroll">

            <section class="rp-page active" id="rp-page-home" data-rp-pane="home">
            <h2 class="info-title"><?= htmlspecialchars($display_title) ?></h2>
            <?php if ($subtitle !== ''): ?>
            <p class="info-sub"><?= htmlspecialchars($subtitle) ?></p>
            <?php endif; ?>

            <div class="station-clock" role="timer" aria-live="off">
                <div class="clock-time"><span id="clock-time">--:--:--</span></div>
                <div class="clock-date" id="clock-date"></div>
            </div>

            <?php if ($email_contacto !== '' || $whatsapp_url !== '' || $website_url !== '' || $nosotros !== ''): ?>
            <div class="contact-rows">
                <?php if ($email_contacto !== ''): ?>
                <div class="contact-row">
                    <span class="c-label">Email:</span>
                    <a class="c-value" href="mailto:<?= htmlspecialchars($email_contacto) ?>"><?= htmlspecialchars($email_contacto) ?></a>
                </div>
                <?php endif; ?>
                <?php if ($whatsapp_url !== ''): ?>
                <div class="contact-row">
                    <span class="c-label">WhatsApp:</span>
                    <a class="c-value" href="<?= htmlspecialchars($whatsapp_url) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($wa_display) ?></a>
                </div>
                <?php endif; ?>
                <?php if ($website_url !== ''): ?>
                <div class="contact-row">
                    <span class="c-label">Página web:</span>
                    <a class="c-value" href="<?= htmlspecialchars($website_url) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($web_display) ?></a>
                </div>
                <?php endif; ?>
                <?php if ($nosotros !== ''): ?>
                <div class="contact-row block">
                    <span class="c-label">Nosotros:</span>
                    <span class="c-value"><?= $nosotros_html ? rp_sanitize_rich_text(nl2br($nosotros)) : nl2br(htmlspecialchars($nosotros)) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($whatsapp_url !== ''): ?>
            <div class="contact-socials">
                <a class="cta-wa" href="<?= htmlspecialchars($whatsapp_url) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp" style="font-size: 1.3rem;"></i> Escríbenos por WhatsApp</a>
            </div>
            <?php endif; ?>

            <?php if ($appstore_url !== '' || $playstore_url !== ''): ?>
            <div class="store-box">
                <span class="store-label">Descarga nuestra app</span>
                <div class="store-buttons">
                    <?php if ($appstore_url !== ''): ?>
                    <a class="store-btn" href="<?= htmlspecialchars($appstore_url) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-apple" aria-hidden="true"></i> App Store</a>
                    <?php endif; ?>
                    <?php if ($playstore_url !== ''): ?>
                    <a class="store-btn" href="<?= htmlspecialchars($playstore_url) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-google-play" aria-hidden="true"></i> Google Play</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            </section><!-- /#rp-page-home -->

    <?php if ($__show_staff): ?>
    <div class="pg-cols-wrap pg-cols-wrap-staff rp-page" id="rp-page-staff" data-rp-pane="staff">
        <section class="pg-col" id="pg-staff">
            <h2 class="pg-block-title"><?php if ($sec_cfg['staff']['icon']): ?><i class="fa-solid fa-people-group" aria-hidden="true"></i><?php endif; ?> Staff</h2>
            <div class="staff-grid">
            <?php foreach ($staff_items as $m): ?>
                <article class="staff-card">
                    <span class="staff-photo-wrap">
                        <span class="staff-avatar"><?= htmlspecialchars($m['initial']) ?></span>
                        <?php if ($m['photo_url'] !== ''): ?>
                        <img class="staff-photo" src="<?= htmlspecialchars($m['photo_url']) ?>" alt="Foto de <?= htmlspecialchars($m['nombre']) ?>" loading="lazy" onerror="this.remove()">
                        <?php endif; ?>
                    </span>
                    <div class="staff-info">
                        <h3><?= htmlspecialchars($m['nombre']) ?></h3>
                        <?php if ($m['cargo'] !== ''): ?><p class="staff-role"><?= htmlspecialchars($m['cargo']) ?></p><?php endif; ?>
                        <?php if ($m['desc'] !== ''): ?><p class="staff-desc"><?= htmlspecialchars($m['desc']) ?></p><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        </section>
    </div><!-- /pg-cols-wrap-staff -->
    <?php endif; ?>

    <?php if ($__show_prog): ?>
    <div class="pg-cols-wrap pg-cols-wrap-programacion rp-page" id="rp-page-programacion" data-rp-pane="programacion">
        <section class="pg-col" id="pg-prog">
            <h2 class="pg-block-title"><?php if ($sec_cfg['programacion']['icon']): ?><i class="fa-solid fa-calendar-days" aria-hidden="true"></i><?php endif; ?> Programación</h2>
            <?php $__daynames = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']; ?>
            <?php $__day_short = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']; ?>
            <div class="prog-tabs" role="tablist">
                <?php foreach ([1, 2, 3, 4, 5, 6, 7] as $__d): ?>
                <button type="button" class="prog-tab-btn" role="tab" data-day="<?= $__d ?>" title="<?= $__daynames[$__d - 1] ?>" onclick="rpProgTab(<?= $__d ?>, this);"><?= $__day_short[$__d - 1] ?></button>
                <?php endforeach; ?>
            </div>
            <?php foreach ([1, 2, 3, 4, 5, 6, 7] as $__d): ?>
            <div class="prog-pane" data-pane="<?= $__d ?>">
                <?php if (empty($prog_days[$__d])): ?>
                <div class="prog-empty-day">Sin programas este día</div>
                <?php else: ?>
                <div class="prog-blocks">
                <?php foreach ($prog_days[$__d] as $__b): ?>
                    <div class="prog-block">
                        <span class="prog-time"><?= htmlspecialchars($__b['inicio']) ?> – <?= htmlspecialchars($__b['fin']) ?></span>
                        <strong class="prog-title"><?= htmlspecialchars($__b['titulo']) ?></strong>
                        <?php if ($__b['conductor'] !== ''): ?><span class="prog-host"><?= htmlspecialchars($__b['conductor']) ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </section>
        <script>
        function rpProgTab(day, btn){
            const root = document.getElementById('pg-prog');
            if (!root) return;
            root.querySelectorAll('.prog-tab-btn').forEach(function(t){ t.classList.remove('active'); });
            root.querySelectorAll('.prog-pane').forEach(function(p){ p.classList.remove('active'); });
            if (btn) btn.classList.add('active');
            const pane = root.querySelector('.prog-pane[data-pane="' + day + '"]');
            if (pane) pane.classList.add('active');
        }
        (function(){
            const root = document.getElementById('pg-prog');
            if (!root) return;
            const btns = root.querySelectorAll('.prog-tab-btn');
            if (!btns.length) return;
            // Día actual del dispositivo (dom=0 → dia 7)
            const wd = new Date().getDay();
            const today = wd === 0 ? 7 : wd;
            let target = root.querySelector('.prog-tab-btn[data-day="' + today + '"]');
            if (!target) target = btns[0];
            rpProgTab(parseInt(target.getAttribute('data-day'), 10), target);
        })();
        </script>
    </div><!-- /pg-cols-wrap-programacion -->
        <?php endif; ?>

    <?php foreach ($link_sections as $__ls): ?>
    <div class="pg-cols-wrap pg-cols-wrap-<?= htmlspecialchars($__ls['key']) ?> rp-page" id="rp-page-<?= htmlspecialchars($__ls['key']) ?>" data-rp-pane="<?= htmlspecialchars($__ls['key']) ?>">
        <section class="pg-col pg-links" id="pg-<?= htmlspecialchars($__ls['key']) ?>">
            <h2 class="pg-block-title"><?php if ($sec_cfg[$__ls['key']]['icon']): ?><i class="<?= htmlspecialchars($__ls['icono']) ?>" aria-hidden="true"></i><?php endif; ?> <?= htmlspecialchars($__ls['titulo']) ?></h2>
            <div class="links-grid">
            <?php foreach ($__ls['items'] as $__it): ?>
                <a class="link-card" href="<?= htmlspecialchars($__it['link']) ?>" target="_blank" rel="noopener">
                    <span class="link-logo-wrap">
                        <span class="link-logo-fb"><?= htmlspecialchars($__it['initial']) ?></span>
                        <?php if ($__it['logo_url'] !== ''): ?>
                        <img class="link-logo" src="<?= htmlspecialchars($__it['logo_url']) ?>" alt="<?= htmlspecialchars($__it['nombre']) ?>" loading="lazy" onerror="this.remove()">
                        <?php endif; ?>
                    </span>
                    <span class="link-name"><?= htmlspecialchars($__it['nombre']) ?></span>
                    <?php if ($__ls['key'] !== 'patrocinadores'): ?>
                    <i class="fa-solid fa-arrow-up-right-from-square link-go" aria-hidden="true"></i>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
            </div>
        </section>
    </div><!-- /pg-cols-wrap -->
    <?php endforeach; ?>

    <?php if ($__show_terminos): ?>
    <section class="rp-page" id="rp-page-terminos" data-rp-pane="terminos">
        <div class="pg-col" id="pg-terminos">
            <h2 class="pg-block-title"><i class="fa-solid fa-file-contract" aria-hidden="true"></i> Términos y Condiciones</h2>
            <div class="rp-page-rich">
                <?= $terminos_html ? rp_sanitize_rich_text(nl2br($terminos)) : nl2br(htmlspecialchars($terminos)) ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

          </div><!-- /#rp-pages-scroll -->
        </aside>

    </section><!-- /.hero-grid -->

    <?php unset($__d, $__show_staff, $__show_prog, $__daynames, $__day_short, $__b, $__ls, $__it); ?>
</main>

<?php if (!empty($social_links)): ?>
<div class="social-float" aria-label="Redes sociales">
    <?php foreach ($social_links as $__sl): ?>
    <a href="<?= htmlspecialchars($__sl[0]) ?>" target="_blank" rel="noopener" class="social-btn" title="<?= htmlspecialchars($__sl[2]) ?>"><i class="<?= htmlspecialchars($__sl[1]) ?>"></i></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<footer class="site-footer">
    <div class="wrap footer-inner">
        <?php if ($__show_radios || $__show_escucha || $__show_terminos): ?>
        <nav class="footer-nav" aria-label="Menú del pie">
            <?php if ($__show_radios): ?>
            <button type="button" class="footer-nav-btn" data-rp-nav="radios">Nuestras Radios</button>
            <?php endif; ?>
            <?php if ($__show_escucha): ?>
            <button type="button" class="footer-nav-btn" data-rp-nav="escuchanos">Dónde Nos Puedes Escuchar</button>
            <?php endif; ?>
            <?php if ($__show_terminos): ?>
            <button type="button" class="footer-nav-btn" data-rp-nav="terminos">Términos y Condiciones</button>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

        <p><?= htmlspecialchars($public_footer_text) ?><?php if ($public_footer_url !== ''): ?> · <?php if ($public_footer_etq !== ''): ?><?= htmlspecialchars($public_footer_etq) ?> <?php endif; ?><a href="<?= htmlspecialchars($public_footer_url) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($public_footer_link) ?></a><?php endif; ?></p>
    </div>
</footer>

<script>
/* ===== Conmutación de páginas del panel derecho (solo classList: el audio NUNCA se recrea) ===== */
(function(){
    var PANE = '[data-rp-pane]', NAV = '[data-rp-nav]';
    function paneExists(k){ return !!document.querySelector(PANE + '[data-rp-pane="' + k + '"]'); }

    function rpShowPage(key, skipScroll){
        if (!paneExists(key)) key = 'home';            // sección vacía o enlace viejo → Home
        Array.prototype.forEach.call(document.querySelectorAll(PANE), function(p){
            p.classList.toggle('active', p.getAttribute('data-rp-pane') === key);
        });
        Array.prototype.forEach.call(document.querySelectorAll(NAV), function(n){
            var act = n.getAttribute('data-rp-nav') === key;
            n.classList.toggle('active', act);
            if (act) n.setAttribute('aria-current', 'page'); else n.removeAttribute('aria-current');
        });
        var sc = document.getElementById('rp-pages-scroll');
        if (sc) sc.scrollTop = 0;                      // cada página empieza arriba
        if (!skipScroll && window.matchMedia && window.matchMedia('(max-width: 1024px)').matches){
            var panel = document.querySelector('.right-col');
            if (panel && panel.scrollIntoView) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        try {
            history.replaceState(null, '', (key === 'home') ? (location.pathname + location.search) : ('#' + key));
        } catch (e) {}
    }
    window.rpShowPage = rpShowPage;

    document.addEventListener('click', function(ev){   // delegación: cubre header, marca y footer
        var el = ev.target;
        while (el && el !== document) {
            if (el.getAttribute && el.getAttribute('data-rp-nav')) {
                ev.preventDefault();
                rpShowPage(el.getAttribute('data-rp-nav'));
                return;
            }
            el = el.parentNode;
        }
    });

    var LEGACY = { 'pg-staff': 'staff', 'pg-prog': 'programacion', 'pg-patrocinadores': 'patrocinadores',
                   'pg-radios': 'radios', 'pg-escuchanos': 'escuchanos', 'pg-terminos': 'terminos' };
    function fromHash(){
        var h = (location.hash || '').replace(/^#/, '');
        if (!h) return 'home';
        if (LEGACY[h]) return LEGACY[h];
        return paneExists(h) ? h : 'home';
    }
    rpShowPage(fromHash(), true);                      // sin auto-scroll en la carga inicial
    window.addEventListener('hashchange', function(){ rpShowPage(fromHash(), true); });
})();
</script>

<audio id="audio-stream" preload="none" crossorigin="anonymous" playsinline></audio>

<script>
(function(){
    const MOUNT       = <?= json_encode($mount, JSON_UNESCAPED_UNICODE) ?>;
    const STATION   = <?= json_encode($display_title, JSON_UNESCAPED_UNICODE) ?>;
    const STREAM_URL  = <?= json_encode($stream_url, JSON_UNESCAPED_UNICODE) ?>;
    const STATS_URL   = <?= json_encode($stats_url, JSON_UNESCAPED_UNICODE) ?>;
    const PAGE_URL    = <?= json_encode($share_url, JSON_UNESCAPED_UNICODE) ?>;
    const DEF_COVER_URL = <?= json_encode($def_cover, JSON_UNESCAPED_UNICODE) ?>;
    const REFRESH_INTERVAL_MS = 2000;
    const STATION_TZ = <?= json_encode($radio_tz, JSON_UNESCAPED_UNICODE) ?>;

    const audio = document.getElementById('audio-stream');
    const playBtn = document.getElementById('play-btn');
    const btnIcon = document.getElementById('btn-icon');
    const volumeSlider = document.getElementById('volume-slider');
    const shareBtn = document.getElementById('share-btn');

    const badgeLiveEl = document.getElementById('badge-live');
    const badgeLiveTextEl = document.getElementById('badge-live-text');

    const currentTitleEl    = document.getElementById('current-title');
    const currentCoverEl   = document.getElementById('current-cover');
    const loadingEl        = document.getElementById('player-loading');
    const eqEl             = document.getElementById('player-eq');
    const clockTimeEl      = document.getElementById('clock-time');
    const clockDateEl      = document.getElementById('clock-date');

    function setAudioLoading(on) {
        if (loadingEl) loadingEl.hidden = !on;
        // Ecualizador: visible solo mientras suena sin buffering (tras el "Cargando")
        if (eqEl) eqEl.hidden = !isPlaying || on;
    }

    let isPlaying = false;
    let userToggled = false;
    audio.volume = parseFloat(volumeSlider.value) || 0.85;
    try {
        const saved = localStorage.getItem('rp_vol_' + MOUNT);
        if (saved !== null && saved !== '') {
            const v = Math.max(0, Math.min(1, parseFloat(saved) || 0.85));
            audio.volume = v; volumeSlider.value = String(v);
        }
    } catch (e) {}

    function setPlayingUI(playing, loading) {
        if (loading) {
            btnIcon.innerHTML = '<div class="spinner"></div>';
        } else if (playing) {
            btnIcon.innerHTML = '<i class="fa-solid fa-pause"></i>';
        } else {
            btnIcon.innerHTML = '<i class="fa-solid fa-play"></i>';
        }
    }

    playBtn.addEventListener('click', function(){
        userToggled = true;
        if (isPlaying) {
            audio.pause();
            try { audio.src = ''; } catch(e) {}
            isPlaying = false;
            setPlayingUI(false, false);
            playBtn.disabled = false;
        } else {
            setPlayingUI(false, true);
            playBtn.disabled = true;
            try {
                audio.src = STREAM_URL + (STREAM_URL.indexOf('?') >= 0 ? '&' : '?') + 'nocache=' + Date.now();
            } catch(e) {
                audio.src = STREAM_URL + '?nocache=' + Date.now();
            }
            var p = audio.play();
            if (p && typeof p.catch === 'function') {
                p.catch(function(err){
                    console.warn('play err', err);
                    setPlayingUI(false, false);
                    playBtn.disabled = false;
                    isPlaying = false;
                });
            }
        }
    });

    audio.addEventListener('playing', function(){
        setPlayingUI(true, false);
        playBtn.disabled = false;
        isPlaying = true;
        setAudioLoading(false); // el audio ya carga/empieza → se quita el texto
    });
    audio.addEventListener('pause', function(){
        setPlayingUI(false, false);
        playBtn.disabled = false;
        isPlaying = false;
        setAudioLoading(false);
    });
    audio.addEventListener('waiting', function(){
        if (isPlaying) setPlayingUI(true, true);
        setAudioLoading(true);
    });
    audio.addEventListener('loadstart', function(){
        setAudioLoading(true);
    });
    audio.addEventListener('error', function(){
        setPlayingUI(false, false);
        playBtn.disabled = false;
        isPlaying = false;
        setAudioLoading(false);
    });

    volumeSlider.addEventListener('input', function(e){
        var v = Math.max(0, Math.min(1, parseFloat(e.target.value) || 0));
        audio.volume = v;
        try { localStorage.setItem('rp_vol_' + MOUNT, String(v)); } catch(err){}
    });

    shareBtn.addEventListener('click', function(){
        var data = { title: STATION, text: '¡Escucha ' + STATION + ' en vivo!', url: PAGE_URL };
        try {
            if (navigator.share) {
                navigator.share(data).catch(function(){});
                return;
            }
        } catch(e) {}
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(PAGE_URL).then(function(){ flash('Enlace copiado ✔'); });
                return;
            }
        } catch(e) {}
        try {
            var ta = document.createElement('textarea');
            ta.value = PAGE_URL; ta.style.position='fixed'; ta.style.left='-99999px';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); flash('Enlace copiado ✔'); } catch(err) {}
            document.body.removeChild(ta);
        } catch(e) {}
    });

    function flash(msg){
        var f = document.getElementById('rp-flash');
        if (!f) {
            f = document.createElement('div'); f.id='rp-flash';
            Object.assign(f.style, {
                position:'fixed', bottom:'28px', left:'50%', transform:'translateX(-50%)',
                background:'rgba(0,0,0,0.85)', color:'#fff', padding:'10px 18px', borderRadius:'999px',
                fontSize:'0.9rem', zIndex:9999, fontWeight:'700',
                border:'1px solid rgba(255,255,255,0.2)',
                opacity:'0', transition:'opacity .3s'
            });
            document.body.appendChild(f);
        }
        f.textContent = msg;
        f.style.opacity = '1';
        clearTimeout(f._t);
        f._t = setTimeout(function(){ f.style.opacity='0'; }, 1800);
    }

    function resolveCover(song) {
        if (!song) return DEF_COVER_URL;
        var c = song.cover_url || song.cover || song.art || '';
        if (!c) return DEF_COVER_URL;
        return c;
    }
    function safeText(v, fallback) {
        v = String(v == null ? '' : v);
        v = v.replace(/\0/g, ' ').replace(/\uFFFE|\uFEFF/g, ' ');
        v = v.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '');
        var frames = 'TRCK|TPE1|TCON|TLEN|TALB|TIT2|TYER|COMM|USLT|WXXX|TCOP|TPUB|TENC|TOPE|TCOM|TEXT|TLAN|TPE2|TPE3|TPE4|TPOS|TDRC|TDOR|TORY|APIC|PIC|GEOB|PRIV|RVA2|EQU2|RVRB|IPLS|MCDI|TKEY|TMOO|TOAL|TOFN|TOLY|TOWN|TPA|TPB|TRDA|TRSN|TRSO|TSIZ|TSRC|TSS1|TXXX|UFID|USER|WCOP|WOAF|WOAR|WOAS|WORS|WPAY|WPUB|SEEK|ASPI|BIN|MLLT|POSS|RBUF|SYLT|SYTC';
        var re1 = new RegExp('\\b(?:' + frames + ')\\b', 'g');
        v = v.replace(re1, '');
        var re2 = new RegExp('(?:' + frames + ')[\\u0000-\\u001F]{1,8}', 'g');
        v = v.replace(re2, '');
        var re3 = new RegExp('(?:' + frames + ')[A-Z]{3,}', 'g');
        v = v.replace(re3, '');
        try {
            var bytes = [];
            for (var i = 0; i < v.length; i++) {
                var cc = v.charCodeAt(i);
                if (cc < 0x80) {
                    if (cc === 9 || (cc >= 32 && cc <= 126)) bytes.push(v.charAt(i));
                } else {
                    var ok = false;
                    if (cc >= 0xA0 && cc <= 0x24FF) ok = true;
                    else if (cc >= 0x2500 && cc <= 0x27BF) ok = true;
                    else if (cc >= 0x2C00 && cc <= 0x2E5F) ok = true;
                    else if (cc >= 0x3000 && cc <= 0x303F) ok = true;
                    else if (cc >= 0x3040 && cc <= 0x9FFF) ok = true;
                    else if (cc >= 0xAC00 && cc <= 0xD7AF) ok = true;
                    else if (cc >= 0xE000 && cc <= 0xF8FF) ok = true;
                    else if (cc >= 0xF900 && cc <= 0xFAFF) ok = true;
                    else if (cc >= 0xFB00 && cc <= 0xFDFF) ok = true;
                    else if (cc >= 0xFE30 && cc <= 0xFE4F) ok = true;
                    else if (cc >= 0x1F000 && cc <= 0x1FFFF) ok = true;
                    if (ok) bytes.push(v.charAt(i));
                }
            }
            v = bytes.join('');
        } catch (e) {}
        v = v.replace(/[ \t]{2,}/g, ' ').replace(/\s+([-\/])\s+/g, '$1').replace(/\s{2,}/g, ' ').trim();
        return v === '' ? (fallback == null ? '' : fallback) : v;
    }

    let lastCurTitle = '';
    let lastCurCover = '';
    let lastLiveMode = null;

    async function updateMetadata() {
        try {
            var url = STATS_URL + (STATS_URL.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now();
            var r = await fetch(url, { cache: 'no-store' });
            if (!r || !r.ok) throw new Error('HTTP ' + (r ? r.status : 'err'));
            var data = await r.json();
            if (!data || typeof data !== 'object') return;

            // ===============================================================
            // 🔴 LIVE BADGE (visible solo cuando live_mode === true)
            // ===============================================================
            var liveMode = data.live_mode === true;
            var liveText = safeText(data.live_banner_text, liveMode ? 'Radio en vivo' : '');
            if (lastLiveMode !== liveMode) {
                if (liveMode) {
                    if (badgeLiveEl) badgeLiveEl.classList.add('on');
                    if (badgeLiveTextEl) badgeLiveTextEl.textContent = liveText || 'EN VIVO';
                } else {
                    if (badgeLiveEl) badgeLiveEl.classList.remove('on');
                }
                lastLiveMode = liveMode;
            }

            // ===============================================================
            // 🎯 MODO DJ EN VIVO: lo que suena es la metadata que envía el DJ
            //    (songtitle REAL de Icecast). El history del autodj no aplica
            //    (la carátula se busca vía iTunes según la canción del DJ).
            // ===============================================================
            var realIcecastTitle = safeText(
                (data.icecast_source ? (data.icecast_source.songtitle || '') : '') ||
                data.songtitle ||
                ''
            ).toLowerCase();

            var curTitle, curCover;
            if (liveMode) {
                var liveTitleRaw = safeText(
                    data.songtitle ||
                    (data.icecast_source ? (data.icecast_source.songtitle || '') : '') ||
                    '',
                    ''
                );
                curTitle  = liveTitleRaw !== '' ? liveTitleRaw : 'Transmisión en Vivo';
                // Carátula de la canción del DJ (buscada vía iTunes) o la por defecto
                curCover  = safeText(data.live_cover || '', '') || DEF_COVER_URL;
            } else {
                // ===============================================================
                // 🎯 AUTODJ: MISMA VÍA QUE EL MODO EN VIVO. El nombre y la
                //    carátula salen de lo que el stream transmite ahora mismo
                //    (metadata Icecast → carátula vía iTunes, igual que cuando
                //    hay DJ en directa). El historial queda solo como respaldo.
                // ===============================================================
                var streamTitleRaw = safeText(
                    (data.icecast_source ? (data.icecast_source.songtitle || '') : '') ||
                    data.songtitle ||
                    '',
                    ''
                );
                var currentIndex = -1;
                if (Array.isArray(data.history) && data.history.length > 0) {
                    for (var ix = 0; ix < data.history.length; ix++) {
                        var it = data.history[ix];
                        var itemFull = (safeText(it.artist, '') + ' - ' + safeText(it.title, '')).toLowerCase();
                        var itemTitle = safeText(it.title, '').toLowerCase();
                        if (itemTitle !== '' &&
                            (realIcecastTitle.indexOf(itemTitle) !== -1 || itemFull.indexOf(realIcecastTitle) !== -1)) {
                            currentIndex = ix;
                            break;
                        }
                    }
                    if (currentIndex === -1) currentIndex = 1;
                }

                var nowPlayingData = (Array.isArray(data.history) && currentIndex >= 0 && data.history[currentIndex])
                    ? data.history[currentIndex]
                    : (data.current_song || data.current || null);

                curTitle = streamTitleRaw !== ''
                    ? streamTitleRaw
                    : safeText(nowPlayingData ? (nowPlayingData.title || data.songtitle) : data.songtitle, 'Transmisión en Vivo');
                // Carátula: la resuelta desde el stream/iTunes si está; si no,
                // la del historial (incrustada/programada) o la genérica.
                curCover = safeText(data.live_cover || '', '') || resolveCover(nowPlayingData);
            }

            if (curTitle !== lastCurTitle && currentTitleEl)    { currentTitleEl.textContent = curTitle; lastCurTitle = curTitle; }
            if (curCover !== lastCurCover && currentCoverEl) {
                currentCoverEl.onerror = function(){ this.onerror=null; this.src=DEF_COVER_URL; };
                currentCoverEl.src = curCover;
                lastCurCover = curCover;
            }

            // ===============================================================
            // 📱 MediaSession API (lockscreen / notificación móvil)
            // ===============================================================
            if ('mediaSession' in navigator) {
                try {
                    navigator.mediaSession.metadata = new MediaMetadata({
                        title: curTitle,
                        artist: STATION,
                        artwork: [{ src: curCover, sizes: '300x300', type: 'image/jpeg' }]
                    });
                } catch (e) {}
            }

        } catch (err) {
            console.warn('updateMetadata err', err);
        }
    }

    // ===============================================================
    // 🕐 RELOJ: hora/fecha en la zona horaria de la estación
    // ===============================================================
    function updateClock() {
        if (!clockTimeEl) return;
        var now = new Date();
        var tz  = STATION_TZ || 'America/Costa_Rica';
        try {
            var parts = new Intl.DateTimeFormat('es', { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).formatToParts(now);
            var p = {};
            for (var i = 0; i < parts.length; i++) p[parts[i].type] = parts[i].value;
            var timeStr = (p.hour || '00') + ':' + (p.minute || '00') + ':' + (p.second || '00');
            var dateStr = new Intl.DateTimeFormat('es', { timeZone: tz, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(now);
            dateStr = dateStr.charAt(0).toUpperCase() + dateStr.slice(1);
            if (clockTimeEl.textContent !== timeStr) clockTimeEl.textContent = timeStr;
            if (clockDateEl && clockDateEl.textContent !== dateStr) clockDateEl.textContent = dateStr;
        } catch (e) {
            var loc = now.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
            var locD = now.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
            locD = locD.charAt(0).toUpperCase() + locD.slice(1);
            if (clockTimeEl.textContent !== loc) clockTimeEl.textContent = loc;
            if (clockDateEl && clockDateEl.textContent !== locD) clockDateEl.textContent = locD;
        }
    }
    updateClock();
    setInterval(updateClock, 1000);

    // Primer fetch rápido
    setTimeout(updateMetadata, 300);
    setInterval(updateMetadata, REFRESH_INTERVAL_MS);

})();
</script>
</body>
</html>
