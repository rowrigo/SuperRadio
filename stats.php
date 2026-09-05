<?php
/**
 * Entrypoint público estilo Centova Cast:
 *   URL: /stats?mount=milimonradio&json=1
 *   Reenvía internamente al endpoint estándar action=stats de autodj_api.php
 *
 * Formato de salida: Mismos campos que el ejemplo del usuario
 *   milimonradio-rowrigos.radioca.st/stats?json=1
 *   (currentlisteners, peaklisteners, servertitle, songtitle, bitrate, etc.)
 *   + extras Azurecast (now_playing.song.* / station.* / listeners.*)
 *   + live_mode / live_banner_text "Radio en vivo" / current / history(5)
 */
$mount = isset($_GET['mount']) ? (string)$_GET['mount'] : (isset($_POST['mount']) ? (string)$_POST['mount'] : '');

$_GET['action']     = 'stats';
$_REQUEST['action'] = 'stats';
if ($mount !== '') {
    $_GET['mount']     = $mount;
    $_REQUEST['mount'] = $mount;
}

require __DIR__ . '/autodj_api.php';
