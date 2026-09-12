<?php
/**
 * CLI (root): instala los límites de conexiones simultáneas por stream /<mount>.
 *  - Escribe /etc/nginx/conf.d/radiopanel-limits.conf  (zona compartida + status)
 *  - Regenera /etc/nginx/radiopanel-limits/locations/*.conf  (un `location = /mount` por
 *    radio con límite fijo; la zona cuenta conexiones activas por $uri).
 *  - max_listeners > 0 en database.json → se escribe su archivo; 0/ausente → ilimitado.
 * Mantiene copia previa en locations.prev para que el wrapper pueda revertir si nginx -t falla.
 */
require_once __DIR__ . '/config.php';

$BASE  = '/etc/nginx/radiopanel-limits';
$LDIR  = $BASE . '/locations';
$LDIR_NEW = $BASE . '/locations.new';
$LDIR_PREV = $BASE . '/locations.prev';
$ZONE_FILE = '/etc/nginx/conf.d/radiopanel-limits.conf';

function lim_fail($msg) { fwrite(STDERR, $msg . "\n"); exit(1); }

$raw = @file_get_contents(DB_FILE);
$db = ($raw !== false) ? @json_decode($raw, true) : [];
if (!is_array($db)) lim_fail('database.json no legible o JSON inválido');

$entries = [];
foreach (($db['radios'] ?? []) as $r) {
    if (!is_array($r)) continue;
    $mount = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $r['mountpoint'] ?? '')));
    if ($mount === '') continue;
    $n = (int)($r['max_listeners'] ?? 0);
    if ($n < 0) $n = 0;
    if ($n > 100000) $n = 100000;
    if ($n > 0) $entries[$mount] = $n;
}
ksort($entries);

$zone  = "# Auto-generado por radiopanel-limits-update - NO editar a mano.\n";
$zone .= "# Zona compartida: cuenta conexiones activas simultaneas por \$uri (cada /mount).\n";
$zone .= "limit_conn_zone \$uri zone=radiopanel_mounts:20m;\n";
$zone .= "limit_conn_status 503;\n";
$zone .= "limit_conn_log_level notice;\n";

// 1) Zona base
$tmp = $ZONE_FILE . '.tmp';
if (@file_put_contents($tmp, $zone) === false) lim_fail("no se pudo escribir {$tmp}");
if (!@rename($tmp, $ZONE_FILE)) lim_fail("no se pudo mover {$ZONE_FILE}");

// 2) Snippets de ubicaciones por radio
if (!is_dir($BASE)) { if (!@mkdir($BASE, 0755, true)) lim_fail("no se pudo crear {$BASE}"); }
// Rotar: prev <- actual ; actual <- new
if (is_dir($LDIR_PREV)) { @exec('rm -rf ' . escapeshellarg($LDIR_PREV)); }
if (is_dir($LDIR)) { if (!@rename($LDIR, $LDIR_PREV)) lim_fail("no se pudo rotar {$LDIR}"); }
if (!@mkdir($LDIR, 0755, true)) lim_fail("no se pudo crear {$LDIR}");

foreach ($entries as $mount => $n) {
    $snip  = "# Limite {$n} conexiones simultaneas para /{$mount} (auto-generado).\n";
    $snip .= "location = /{$mount} {\n";
    $snip .= "    limit_conn radiopanel_mounts {$n};\n";
    $snip .= "    proxy_pass http://127.0.0.1:8000/{$mount};\n";
    $snip .= "    proxy_set_header Host \$host;\n";
    $snip .= "    proxy_buffering off;\n";
    $snip .= "    tcp_nodelay on;\n";
    $snip .= "}\n";
    if (@file_put_contents($LDIR . '/' . $mount . '.conf', $snip) === false) lim_fail("no se pudo escribir snippet {$mount}");
}
if (is_dir($LDIR_NEW)) @exec('rm -rf ' . escapeshellarg($LDIR_NEW)); // limpieza de estados raros

echo 'zonas=' . count($entries) . "\n";
exit(0);
