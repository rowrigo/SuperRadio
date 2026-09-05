<?php
if ($argc < 4) exit;

$mount    = preg_replace('/[^a-zA-Z0-9_-]/', '', $argv[1]);
$playlist = trim($argv[2]);
$raw_meta = trim($argv[3]);

if (empty($mount)) exit;

$base_dir = "/var/media/radios/{$mount}";
$history_file = "{$base_dir}/history.json";

// Formatear nombre de canción
$song_name = pathinfo($raw_meta, PATHINFO_FILENAME);
$song_name = str_replace(['_', '-'], ' ', $song_name);
$song_name = preg_replace('/\s+/', ' ', trim($song_name));
if (empty($song_name)) $song_name = "Transmisión en Vivo";

$history = file_exists($history_file) ? (json_decode(file_get_contents($history_file), true) ?: []) : [];

// Registrar si cambió la canción
if (empty($history) || ($history[0]['title'] ?? '') !== $song_name) {
    array_unshift($history, [
        'title'     => $song_name,
        'playlist'  => $playlist,
        'time'      => date('H:i:s')
    ]);
    $history = array_slice($history, 0, 10);
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
