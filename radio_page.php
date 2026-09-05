<?php
/**
 * Página pública de radio — Diseño 1 sola columna (player centrado).
 *
 * Uso:
 *   radio_page.php?mount=milimonradio
 *
 * Configuración dinámica leída desde:
 *   <MEDIOS>/<mount>/.nextsong_state/page_config.json (subido desde el panel)
 *   - Logo / Fondo / Colores / Enlaces sociales / Título
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
$stats_url   = rp_abs_url('stats.php?mount=' . rawurlencode($mount) . '&json=1');
$logo_url   = rp_abs_url('autodj_api.php?action=serve_page_logo&mount=' . rawurlencode($mount));
$bg_url     = rp_abs_url('autodj_api.php?action=serve_page_bg&mount=' . rawurlencode($mount));
$def_cover  = rp_abs_url('autodj_api.php?action=serve_default_cover&mount=' . rawurlencode($mount));
$stream_url = rp_abs_url('/' . ltrim($mount, '/'));
$page_url   = rp_abs_url('radio_page.php?mount=' . rawurlencode($mount));

// === Compartir WhatsApp ==================
$wa_share = rp_abs_url('https://wa.me/?text=' . rawurlencode('Escucha ' . $display_title . ' en vivo: ' . $page_url));

// === Placeholder cover inicial por si no hay ==========
$placeholder_cover = $def_cover;
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
    display: flex; justify-content: center; align-items: center; padding: 20px;
    position: relative;
    min-height: 100vh;
    overflow-x: hidden;
}
.bg-layer {
    position: fixed; inset: 0; z-index: -2;
    <?php if ($has_bg_image): ?>
    background-image: url("<?= htmlspecialchars($bg_url) ?>");
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

.app-container {
    background: <?= $app_rgba ?>;
    width: 100%;
    max-width: 600px;
    border-radius: 24px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.55);
    border: 1px solid rgba(255, 255, 255, 0.08);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}

/* --- HEADER --- */
header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 28px;
    background: <?= $hdr_rgba ?>;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}
.station-brand {
    display: flex; align-items: center; gap: 12px;
}
.station-brand i { font-size: 1.5rem; color: <?= htmlspecialchars($accent) ?>; }
.station-brand h1 {
    font-size: 1.2rem; font-weight: 700; letter-spacing: 0.5px; margin: 0;
    color: #f8fafc;
    text-transform: uppercase;
}
.header-actions { display: flex; align-items: center; gap: 12px; }
.social-btn {
    display: flex; align-items: center; justify-content: center;
    width: 38px; height: 38px; border-radius: 50%;
    background: <?= $social_rgba ?>;
    color: #94a3b8;
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
main {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 28px;
}
@media (max-width: 768px) {
    body { padding: 12px 10px 24px; }
    header {
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 14px 16px;
        gap: 10px;
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
    width: 180px; height: 180px;
    border-radius: 16px;
    object-fit: cover;
    margin: 14px 0 16px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
    background: #334155;
    background-color: #334155;
}
.player-info {
    text-align: center; width: 100%;
    margin-bottom: 18px;
}
.player-station {
    font-size: 1.45rem;
    color: <?= htmlspecialchars($accent) ?>;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
    line-height: 1.2;
}
.player-song {
    font-size: 1.1rem;
    font-weight: bold;
    color: #ffffff;
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

/* Controles */
.volume-container {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    max-width: 260px;
    margin-bottom: 18px;
}
.volume-slider {
    flex: 1;
    accent-color: <?= htmlspecialchars($accent) ?>;
    cursor: pointer;
}
.btn-play {
    background: <?= htmlspecialchars($accent) ?>;
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
footer {
    text-align: center;
    padding: 16px;
    background: <?= $ftr_rgba ?>;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    font-size: 0.85rem;
    color: #64748b;
}
</style>
</head>
<body>
<div class="bg-layer" aria-hidden="true"></div>
<div class="overlay-layer" aria-hidden="true"></div>

<div class="app-container">

    <header>
        <div class="station-brand">
            <h1><?= htmlspecialchars($display_title) ?></h1>
        </div>

        <div class="header-actions">
            <?php if ($facebook_url !== ''): ?>
            <a href="<?= htmlspecialchars($facebook_url) ?>" target="_blank" rel="noopener" class="social-btn" title="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
            <?php endif; ?>
            <?php if ($website_url !== ''): ?>
            <a href="<?= htmlspecialchars($website_url) ?>" target="_blank" rel="noopener" class="social-btn" title="Sitio Web"><i class="fa-solid fa-globe"></i></a>
            <?php endif; ?>
            <?php if ($whatsapp_url !== ''): ?>
            <a href="<?= htmlspecialchars($whatsapp_url) ?>" target="_blank" rel="noopener" class="social-btn" title="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
            <?php endif; ?>
            <button id="share-btn" class="social-btn" title="Compartir"><i class="fa-solid fa-share-nodes"></i></button>
        </div>
    </header>

    <main>
        <section class="left-col">
            <span id="badge-live" class="badge-live"><i class="fa-solid fa-circle" style="font-size: 6px;"></i> <span id="badge-live-text">EN VIVO</span></span>

            <img id="current-cover" class="main-cover" src="<?= htmlspecialchars($placeholder_cover) ?>" alt="Carátula" onerror="this.onerror=null;this.src='<?= htmlspecialchars($def_cover) ?>';">

            <div class="player-info">
                <p class="player-station"><?= htmlspecialchars($display_title) ?></p>
                <h2 id="current-title" class="player-song">Cargando canción...</h2>
                <div id="player-loading" class="player-loading"><span>Cargando</span><span class="ldot"></span><span class="ldot"></span><span class="ldot"></span></div>
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
    </main>

    <footer>
        <p>Creada para amantes de la Radio</p>
    </footer>
</div>

<audio id="audio-stream" preload="none" crossorigin="anonymous" playsinline></audio>

<script>
(function(){
    const MOUNT       = <?= json_encode($mount, JSON_UNESCAPED_UNICODE) ?>;
    const STATION   = <?= json_encode($display_title, JSON_UNESCAPED_UNICODE) ?>;
    const STREAM_URL  = <?= json_encode($stream_url, JSON_UNESCAPED_UNICODE) ?>;
    const STATS_URL   = <?= json_encode($stats_url, JSON_UNESCAPED_UNICODE) ?>;
    const PAGE_URL    = <?= json_encode($page_url, JSON_UNESCAPED_UNICODE) ?>;
    const DEF_COVER_URL = <?= json_encode($def_cover, JSON_UNESCAPED_UNICODE) ?>;
    const REFRESH_INTERVAL_MS = 2000;

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

    function setAudioLoading(on) {
        if (loadingEl) loadingEl.hidden = !on;
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
                // 🎯 SINCRONIZACIÓN ICECAST ↔ HISTORY (TU ALGORITMO ORIGINAL)
                //    1. Toma "songtitle" REAL que envía Icecast en ese momento
                //       (de data.icecast_source.songtitle, fallback data.songtitle)
                //    2. Busca en history[] la fila cuyo title/artist-title coincida
                //    3. Si no encuentra → currentIndex = 1 (desfase buffer natural)
                //    4. nowPlayingData = history[currentIndex] (la QUE SUENA AHORA)
                // ===============================================================
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

                // ===============================================================
                // 🎧 CANCIÓN ACTUAL (la sincronizada con Icecast)
                // ===============================================================
                curTitle  = safeText(nowPlayingData ? (nowPlayingData.title || data.songtitle) : data.songtitle, 'Transmisión en Vivo');
                curCover  = resolveCover(nowPlayingData);
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

    // Primer fetch rápido
    setTimeout(updateMetadata, 300);
    setInterval(updateMetadata, REFRESH_INTERVAL_MS);

})();
</script>
</body>
</html>
