#!/usr/bin/php
<?php
/**
 * Voz de hora (24h): devuelve la ruta absoluta del archivo de audio de la hora
 * actual (HH.mp3) UNA sola vez por hora. Liquidsoap la llama desde el script
 * generado (get_process_lines) cuando la ventana horaria está activa.
 *
 * Uso: php timevoice.php --mount=milimon
 *  - Imprime la ruta absoluta del archivo si corresponde, o nada.
 *  - Marker en {base_dir}/.nextsong_state/timevoice_last.json evita repetir el
 *    anuncio si Liquidsoap pide varias veces dentro de la misma ventana.
 *  - Nunca falla ni rompe el stream: ante cualquier error no imprime nada.
 */
if (PHP_SAPI !== 'cli') { exit(1); }

$args = $_SERVER['argv'] ?? [];
$mount = '';
for ($i = 1, $n = count($args); $i < $n; $i++) {
    if (preg_match('/^--mount=(.+)$/', (string)$args[$i], $m)) { $mount = $m[1]; break; }
}
$mount = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)$mount));
if ($mount === '') { exit(0); }

$base_dir = '/var/media/radios/' . $mount;
$data_file = "{$base_dir}/programacion.json";
if (!is_file($data_file)) { exit(0); }

$app_data = @json_decode(@file_get_contents($data_file), true);
if (!is_array($app_data)) { exit(0); }

$tv = $app_data['time_voice'] ?? ['enabled' => false, 'folder' => ''];
if (empty($tv['enabled']) || empty($tv['folder'])) { exit(0); }

// Carpeta dentro del base_dir (sin permitir salir de él).
$folder = trim((string)$tv['folder']);
if ($folder === '' || strpos($folder, '..') !== false) { exit(0); }
$voice_dir = rtrim($base_dir, '/') . '/' . trim($folder, '/');
if (!is_dir($voice_dir)) { exit(0); }

$tz = is_string($app_data['timezone'] ?? null) ? trim((string)$app_data['timezone']) : 'America/Costa_Rica';
if ($tz !== '' && $tz !== 'UTC' && preg_match('/^[a-zA-Z0-9_\/+\-]+$/', $tz)) {
    @date_default_timezone_set($tz);
}

// Solo entrega el archivo si estamos en la ventana corta tras la hora
// (≤10 s). Liquidsoap pre-bufferiza peticiones al arrancar/desactivarse:
// sin este guard, un archivo pedido p.ej. a las 11:42 quedaba en buffer
// y sonaba a las 12:00 (se oía "las 11 y después las 12"). Fuera de la
// ventana no imprime nada (el .liq rellena con silencio casi instantáneo).
$sec_of_hour = ((time() + (int)date('Z')) % 3600 + 3600) % 3600;
if ($sec_of_hour > 10) { exit(0); }

$h = (string)date('H');          // 00..23
$h2 = (string)(int)date('G');    // 0..23 sin cero inicial

$candidate = '';
foreach (["{$voice_dir}/{$h}.mp3", "{$voice_dir}/{$h2}.mp3"] as $f) {
    if (is_file($f) && is_readable($f)) { $candidate = $f; break; }
}
if ($candidate === '') { exit(0); }

$state_dir = "{$base_dir}/.nextsong_state";
if (!is_dir($state_dir)) { @mkdir($state_dir, 0775, true); }
$marker_file = rtrim($state_dir, '/') . '/timevoice_last.json';
$now = time();

$already = false;
if (is_file($marker_file)) {
    $mk = @json_decode(@file_get_contents($marker_file), true);
    if (is_array($mk)) {
        $mh = (int)($mk['h'] ?? -1);
        $mp = (int)($mk['played_at'] ?? 0);
        if ($mh === (int)$h && $mp > 0 && ($now - $mp) < 3600) { $already = true; }
    }
}
if ($already) { exit(0); }

// Marca como anunciada y devuelve la ruta (printf sin \r).
@file_put_contents($marker_file, json_encode(['h' => (int)$h, 'played_at' => $now], JSON_UNESCAPED_UNICODE));
echo $candidate . "\n";
exit(0);
