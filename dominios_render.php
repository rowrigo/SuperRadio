<?php
/**
 * CLI: renderiza la config nginx de los dominios mapeados → stdout.
 * Lo ejecuta el wrapper root /usr/local/sbin/radiopanel-dominios-update.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib_dominios.php';

$_raw = @file_get_contents(DB_FILE);
$db = ($_raw !== false) ? @json_decode($_raw, true) : [];
if (!is_array($db)) {
    fwrite(STDERR, "database.json no legible o JSON inválido.\n");
    exit(1);
}
echo sp_dominios_nginx_conf($db);
exit(0);
