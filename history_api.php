<?php
require_once __DIR__ . '/config.php';

$mount = preg_replace('/[^a-zA-Z0-9_-]/', '', $_REQUEST['mount'] ?? '');
if (empty($mount)) {
    echo json_encode(['current' => null, 'recent' => []]);
    exit;
}

$base_dir = "/var/media/radios/{$mount}";
$history_file = "{$base_dir}/history.json";
$prog_file    = "{$base_dir}/programacion.json";

// 1. Consultar estado en vivo de Icecast
$current_song = "AutoDJ Transmitiendo";
$icecast_json = @file_get_contents("http://127.0.0.1:8000/status-json.xsl");

if ($icecast_json) {
    $data = json_decode($icecast_json, true);
    $sources = $data['icestats']['source'] ?? [];
    if (isset($sources['mount'])) $sources = [$sources];

    foreach ($sources as $s) {
        if (trim($s['mount'] ?? '', '/') === $mount) {
            if (!empty($s['title'])) {
                $current_song = $s['title'];
            } elseif (!empty($s['yp_currently_playing'])) {
                $current_song = $s['yp_currently_playing'];
            }
            break;
        }
    }
}

// 2. Determinar qué Playlist está activo según día y hora local
$current_playlist = 'general';
if (file_exists($prog_file)) {
    $prog = json_decode(file_get_contents($prog_file), true) ?: [];
    $tz = $prog['timezone'] ?? 'America/Costa_Rica';
    date_default_timezone_set($tz);
    
    $now_time = date('H:i');
    $current_day_num = (int)date('N'); // 1 = Lunes, 7 = Domingo

    if (!empty($prog['schedule'])) {
        foreach ($prog['schedule'] as $sched) {
            $days = !empty($sched['days']) ? $sched['days'] : [1,2,3,4,5,6,7];
            
            // Verificar si el día actual está en los días programados
            if (in_array($current_day_num, $days)) {
                if ($now_time >= $sched['start'] && $now_time < $sched['end']) {
                    $current_playlist = $sched['playlist'];
                    break;
                }
            }
        }
    }
}

// 3. Gestionar Historial en disco
$history = file_exists($history_file) ? (json_decode(file_get_contents($history_file), true) ?: []) : [];

if ($current_song !== "AutoDJ Transmitiendo") {
    if (empty($history) || ($history[0]['title'] ?? '') !== $current_song) {
        array_unshift($history, [
            'title'     => $current_song,
            'playlist'  => $current_playlist,
            'time'      => date('H:i:s')
        ]);
        $history = array_slice($history, 0, 10);
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

header('Content-Type: application/json');
echo json_encode([
    'current' => [
        'title'    => $current_song,
        'playlist' => $current_playlist,
        'time'     => $history[0]['time'] ?? date('H:i:s')
    ],
    'recent'  => array_slice($history, 1, 5)
]);
