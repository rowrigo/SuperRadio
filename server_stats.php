<?php
session_start();
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

// 1. CPU
$load = sys_getloadavg();
$cores = (int)@shell_exec("nproc") ?: 1;
$cpu_percent = isset($load[0]) ? round(($load[0] / $cores) * 100) : 0;
if ($cpu_percent > 100) $cpu_percent = 100;

// 2. RAM
$ram_total = 0;
$ram_used = 0;
$ram_percent = 0;
$free_out = @shell_exec("free -m");
if ($free_out && preg_match('/Mem:\s+(\d+)\s+(\d+)/', $free_out, $m)) {
    $ram_total = (int)$m[1];
    $ram_used = (int)$m[2];
    $ram_percent = $ram_total > 0 ? round(($ram_used / $ram_total) * 100) : 0;
}

// 3. Tráfico de Red (TX / RX)
$rx_mb = 0;
$tx_mb = 0;
$net = @file_get_contents('/proc/net/dev');
if ($net) {
    preg_match_all('/([a-zA-Z0-9]+):\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $net, $matches);
    if (!empty($matches[2])) {
        foreach ($matches[1] as $idx => $iface) {
            if ($iface !== 'lo') {
                $rx_mb += round($matches[2][$idx] / 1048576, 1);
                $tx_mb += round($matches[3][$idx] / 1048576, 1);
            }
        }
    }
}

// 4. Oyentes Activos Totales en Icecast
$total_listeners = 0;
$ch = curl_init("http://127.0.0.1:8000/status-json.xsl");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 1);
$json_raw = curl_exec($ch);
curl_close($ch);

if ($json_raw) {
    $d = json_decode($json_raw, true);
    if (!empty($d['icestats']['source'])) {
        $sources = $d['icestats']['source'];
        if (isset($sources['listenurl']) || isset($sources['listeners'])) $sources = [$sources];
        foreach ($sources as $s) {
            $total_listeners += (int)($s['listeners'] ?? 0);
        }
    }
}

// 5. Total de Radios Registradas
$db_file = DB_FILE;
$db = file_exists($db_file) ? json_decode(file_get_contents($db_file), true) : ['radios' => []];
$total_radios = count($db['radios'] ?? []);

echo json_encode([
    'cpu' => $cpu_percent,
    'ram_used' => $ram_used,
    'ram_total' => $ram_total,
    'ram_percent' => $ram_percent,
    'net_rx' => $rx_mb,
    'net_tx' => $tx_mb,
    'listeners' => $total_listeners,
    'total_radios' => $total_radios
]);
