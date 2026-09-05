<?php
session_start();
require_once __DIR__ . '/config.php';

$is_superadmin = !empty($_SESSION['superadmin_auth']);
if (empty($_SESSION['cliente_auth']) && !$is_superadmin) { header("Location: index.php"); exit; }
if (isset($_GET['logout'])) { 
    $_SESSION = [];
    session_destroy(); 
    header("Location: " . ($is_superadmin ? "superradio.php" : "index.php")); 
    exit; 
}
// Si es superadmin, asegurarse de que las variables de sesión de cliente existan para evitar warnings
if ($is_superadmin && empty($_SESSION['cliente_user'])) {
    $_SESSION['cliente_user'] = 'superadmin';
    $_SESSION['cliente_nombre'] = 'Super Administrador';
}

$db = file_exists(DB_FILE) ? json_decode(file_get_contents(DB_FILE), true) : ["radios" => []];

// 1. PRIORIDAD MÁXIMA: parámetro ?mount= de la URL (cambia de emisora al vuelo)
$mount_param = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['mount'] ?? '')));
$radio = null;
$radio_key = null;

if (!empty($mount_param)) {
    foreach ($db['radios'] as $k => $r) {
        $r_mount = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $r['mountpoint'] ?? '')));
        if ($r_mount === $mount_param || $k === $mount_param || $k === 'radio_' . $mount_param || $k === 'rad_' . $mount_param) {
            $radio = $r;
            $radio_key = $k;
            break;
        }
    }
    if ($radio_key) {
        $_SESSION['radio_id'] = $radio_key;
    }
}

// 2. Si no hubo mount, usar la sesión
if (!$radio) {
    $radio_id = $_SESSION['radio_id'] ?? '';
    if (!empty($radio_id) && isset($db['radios'][$radio_id])) {
        $radio = $db['radios'][$radio_id];
        $radio_key = $radio_id;
    }
}

// 3. Si aún no hay radio, buscar por key/mount en sesión
if (!$radio && !empty($_SESSION['radio_id']) && !empty($db['radios'])) {
    $s = $_SESSION['radio_id'];
    foreach ($db['radios'] as $k => $r) {
        $r_mount = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $r['mountpoint'] ?? '')));
        if ($k === $s || $r_mount === $s || $k === 'rad_' . $s || $k === 'radio_' . $s) {
            $radio = $r;
            $radio_key = $k;
            $_SESSION['radio_id'] = $k;
            break;
        }
    }
}

// 4. Último fallback: primera radio disponible
if (!$radio && !empty($db['radios'])) {
    $first_key = array_key_first($db['radios']);
    $radio = $db['radios'][$first_key];
    $radio_key = $first_key;
    $_SESSION['radio_id'] = $first_key;
}

$encoder_pass = $radio ? (!empty($radio['encoder_pass_encrypted']) ? decrypt_pass($radio['encoder_pass_encrypted']) : ($radio['encoder_pass'] ?? '')) : '';
$mount_clean = $radio ? strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $radio['mountpoint'] ?? ''))) : 'milimonradio';
$dj_port = !empty($radio['dj_port']) ? (int)$radio['dj_port'] : 8005;

// Definir la ruta base de medios para las vistas
$media_dir = "/var/media/radios/{$mount_clean}";
?>


<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SuperRadio Studio - <?= $radio ? $radio['nombre_emisora'] : 'Cabina' ?></title>
    <link rel="stylesheet" href="assets/css/panel.css?v=<?= time() ?>">
</head>
<body>

<div class="app-container">
    <div class="app-scrim" id="appScrim"></div>

    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <main class="main-content">
        <?php include __DIR__ . '/views/topbar.php'; ?>

        <div class="content-area">
            <?php include __DIR__ . '/views/view_live.php'; ?>
            <?php include __DIR__ . '/views/view_musicateca.php'; ?>
            <?php include __DIR__ . '/views/view_playlists.php'; ?>
            <?php include __DIR__ . '/views/view_reloj.php'; ?>
            <?php include __DIR__ . '/views/view_anuncios.php'; ?>
            <?php include __DIR__ . '/views/view_ajustes.php'; ?>
            <?php include __DIR__ . '/views/view_public_page.php'; ?>
        </div>
    </main>
</div>

<script>
    <?php
        function np_read_json($path){ if(!is_file($path)) return null; $j=@json_decode(@file_get_contents($path),true); return is_array($j)?$j:null; }
        $np_state_dir = "{$media_dir}/.nextsong_state";
        $_np_current = np_read_json("{$np_state_dir}/current_song.json");
        $_np_history = np_read_json("{$np_state_dir}/history.json") ?: [];
        $_np_def_exists = (is_file("{$np_state_dir}/default_cover.jpg") || is_file("{$np_state_dir}/page_logo.jpg"));
        $_np_def_cover = "autodj_api.php?action=serve_default_cover&mount={$mount_clean}" . ($_np_def_exists ? "&default=1&t=".@filemtime("{$np_state_dir}/default_cover.jpg") : "");
        $is_directa = !empty($radio['modo']) && ($radio['modo'] === 'directa');
        if (!$_np_current) {
            $_np_current = [
                'mount'  => $mount_clean,
                'title'  => $is_directa ? 'Modo Directa — Señal lista para DJ en vivo' : 'AutoDJ — Cargando primera canción...',
                'artist' => '',
                'album'  => '',
                'cover_url' => $_np_def_cover,
                'playlist' => 'general',
                'mode'     => $is_directa ? 'directa' : 'general',
                'started_at' => '',
                'started_ts' => 0,
                'loading'  => true,
            ];
        } else if (empty($_np_current['cover_url'])) $_np_current['cover_url'] = $_np_def_cover;
        foreach ($_np_history as $_i => $_h) {
            if (empty($_h['cover_url'])) $_np_history[$_i]['cover_url'] = $_np_def_cover;
        }
        // Pasar a las vistas PHP (sidebar, view_live, view_ajustes... LO USA view_live.php para primer render sin polling)
        $GLOBALS['_storage_for_view'] = [
            'now_playing_initial' => [
                'mount'             => $mount_clean,
                'mode'              => $is_directa ? 'directa' : 'autodj',
                'stream_url'        => "/" . ltrim($mount_clean, "/"),
                'default_cover_url' => $_np_def_cover,
                'default_cover_set' => $_np_def_exists,
                'current'           => $_np_current,
                'history'           => array_values($_np_history),
            ]
        ];
    ?>
    const currentMount = "<?= $mount_clean ?>";
    window.RADIO_CONFIG = {
        mount: "<?= $mount_clean ?>",
        dj_port: <?= !empty($dj_port) ? (int)$dj_port : 8000 ?>,
        encoder_pass: <?= json_encode(!empty($encoder_pass) ? $encoder_pass : '') ?>,
        nombre_emisora: <?= json_encode(($radio['nombre_emisora']) ?? 'Radio') ?>,
        radio_id: "<?= $radio_key ?? ($_SESSION['radio_id'] ?? '') ?>",
        stream_host: <?= json_encode(defined('STREAM_HOST') ? STREAM_HOST : 'stream.radioscr.com') ?>,
        now_playing_initial: {
            mount: "<?= $mount_clean ?>",
            mode: "<?= $is_directa ? 'directa' : 'autodj' ?>",
            stream_url: "/" . ltrim($mount_clean, "/"),
            default_cover_url: <?= json_encode($_np_def_cover) ?>,
            default_cover_set: <?= $_np_def_exists ? 'true' : 'false' ?>,
            current: <?= json_encode($_np_current, JSON_UNESCAPED_UNICODE) ?>,
            history: <?= json_encode(array_values($_np_history), JSON_UNESCAPED_UNICODE) ?>,
        }
    };
</script>

<!-- Módulos JavaScript -->
<script src="assets/js/core.js?v=<?= time() ?>"></script>
<script src="assets/js/main.js?v=<?= time() ?>"></script>
<script src="assets/js/live.js?v=<?= time() ?>"></script>
<script src="assets/js/musicateca.js?v=<?= time() ?>"></script>
<script src="assets/js/playlists.js?v=<?= time() ?>"></script>
<script src="assets/js/schedule.js?v=<?= time() ?>"></script>
<script src="assets/js/anuncios.js?v=<?= time() ?>"></script>
<script src="assets/js/ajustes.js?v=<?= time() ?>"></script>

<!-- Drawer móvil: hamburguesa + overlay -->
<script>
(function () {
    'use strict';
    var toggle = document.getElementById('navToggle');
    var side = document.getElementById('cabinaSidebar');
    var scrim = document.getElementById('appScrim');
    var mq = window.matchMedia('(max-width: 992px)');
    function setOpen(open) {
        if (!side) return;
        side.classList.toggle('open', open);
        if (scrim) scrim.classList.toggle('show', open);
        if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.style.overflow = open ? 'hidden' : '';
    }
    if (toggle) toggle.addEventListener('click', function () {
        setOpen(!side.classList.contains('open'));
    });
    if (scrim) scrim.addEventListener('click', function () { setOpen(false); });
    // Cerrar al elegir una opción del menú lateral (vista, radio o acción)
    if (side) side.addEventListener('click', function (e) {
        if (e.target.closest('button, a')) setOpen(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setOpen(false);
    });
    // Al volver a escritorio, garantizar drawer cerrado y scroll normal
    mq.addEventListener('change', function (ev) {
        if (!ev.matches) setOpen(false);
    });
})();
</script>
</body>
</html>
