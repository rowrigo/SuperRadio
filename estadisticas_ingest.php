<?php
/**
 * Ingesta de estadísticas de audiencia del stream (CLI).
 *
 * Lee los logs del stream (Icecast como fuente de conexiones + nginx para el país de
 * los oyentes que pasan por el proxy) y actualiza las estadísticas por radio.
 * Pensado para ejecutarse desde un timer systemd cada pocos minutos.
 *
 * Uso:
 *   php estadisticas_ingest.php            # ingest incremental
 *   php estadisticas_ingest.php --rebuild  # reconstruye desde los logs disponibles
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI\n"); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/estadisticas_lib.php';

$rebuild = in_array('--rebuild', $argv ?? [], true);
$res = est_ingest_run($rebuild);

echo json_encode($res, JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(!empty($res['ok']) ? 0 : 1);
