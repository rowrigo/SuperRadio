<?php
/**
 * Dominios propios por radio — helpers.
 *
 * Cada radio creada en el panel puede tener un dominio propio (ej. milimon.com)
 * que al abrirse muestra su landing pública en la raíz. SSL: Cloudflare Flexible
 * (el origen solo recibe HTTP:80; NO se emiten certificados por dominio).
 *
 * Config global en database.json → clave top-level 'dominios':
 *   "dominios": { "milimon.com": { "radio_id": "rad_...", "www": true,
 *                                   "creado": "...", "actualizado": "..." } }
 */

if (!function_exists('sp_dominio_normalize')) {

function sp_dominio_normalize($raw) {
    $d = strtolower(trim((string)$raw));
    if ($d === '') return '';
    $d = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $d);       // quitar scheme
    $d = preg_replace('/[\/?#].*$/', '', $d);                      // quitar ruta/query
    $d = preg_replace('/:\d+$/', '', $d);                          // quitar puerto
    $d = trim($d, '.');
    $labels = explode('.', $d);
    if (count($labels) > 2 && strtolower($labels[0]) === 'www') {
        array_shift($labels);                                      // normalizar sin www
    }
    $d = implode('.', $labels);
    if (function_exists('mb_strtolower')) $d = mb_strtolower($d, 'UTF-8');
    return preg_replace('/\s+/', '', $d);
}

function sp_dominio_formato_ok($dom) {
    $dom = rtrim((string)$dom, '.');
    return (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+$/i', $dom)
        && strlen($dom) <= 253;
}

/** Dominios reservados / del propio operador (no se pueden mapear). */
function sp_dominio_bloqueado($dom) {
    $d = strtolower(rtrim((string)$dom, '.'));
    if ($d === '' || $d === 'localhost' || $d === '127.0.0.1' || $d === '81.17.100.208') return true;
    $sufijos = ['.rowrigo.com', '.radioscr.com'];
    foreach ($sufijos as $s) {
        if ($d === ltrim($s, '.') || str_ends_with($d, $s)) return true;
    }
    return false;
}

/**
 * Valida y normaliza un dominio para mapearlo a una radio.
 * @return string dominio canónico ('' si inválido; $err con motivo)
 */
function sp_dominio_validar($raw, $radio_id, $db, &$err) {
    $err = '';
    $dom = sp_dominio_normalize($raw);
    if ($dom === '') { $err = 'Escribe un dominio.'; return ''; }
    if (!sp_dominio_formato_ok($dom)) { $err = "El dominio '{$dom}' no tiene un formato válido (ej. milimon.com)."; return ''; }
    if (sp_dominio_bloqueado($dom)) { $err = "El dominio '{$dom}' está reservado por el sistema."; return ''; }
    if (!isset($db['radios'][$radio_id])) { $err = 'Selecciona una radio válida.'; return ''; }
    foreach (($db['dominios'] ?? []) as $canon => $map) {
        if ($canon === $dom) {
            if (($map['radio_id'] ?? '') === $radio_id) continue; // re-guardar el mismo = actualizar
            $err = "El dominio '{$dom}' ya está asignado a otra radio.";
            return '';
        }
    }
    return $dom;
}

/** Renderiza la config nginx (conf.d) para todos los dominios mapeados. */
function sp_dominios_nginx_conf($db) {
    $out = "# AUTOGENERADO por SuperRadio (dominios) — NO EDITAR. Se regenera desde database.json.\n";
    $out .= "# Uso: panel superadmin → Emisoras → 'Dominios propios'. SSL: Cloudflare Flexible (origen HTTP:80).\n\n";
    $radios = $db['radios'] ?? [];
    $doms = $db['dominios'] ?? [];
    if (!is_array($radios)) $radios = [];
    if (!is_array($doms)) $doms = [];
    $emitidos = 0;
    ksort($doms);
    foreach ($doms as $canon => $map) {
        if (!is_array($map) || !sp_dominio_formato_ok($canon) || sp_dominio_bloqueado($canon)) continue;
        $rid = (string)($map['radio_id'] ?? '');
        if (!isset($radios[$rid])) continue; // radio borrada → se ignora (y el wrapper la limpia al regenerar)
        $mount = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($radios[$rid]['mountpoint'] ?? ''));
        if ($mount === '') continue;
        $dom = strtolower($canon);
        $out .= "server {\n";
        $out .= "    listen 80;\n";
        $out .= "    server_name {$dom} www.{$dom};\n";
        $out .= "    root /var/www/radiopanel;\n";
        $out .= "    index index.php;\n";
        $out .= "    location ~* \\.json\$  { deny all; return 404; }\n";
        $out .= "    location ~ /\\.ht     { deny all; }\n";
        $out .= "    # Landing pública de la radio en la raíz (rewrite interno, sin redirección visible)\n";
        $out .= "    location = /          { rewrite ^ /radio_page.php?mount={$mount} last; }\n";
        $out .= "    location ~ \\.php\$ {\n";
        $out .= "        include snippets/fastcgi-php.conf;\n";
        $out .= "        fastcgi_pass unix:/run/php/php8.1-fpm.sock;\n";
        $out .= "        fastcgi_param PHP_VALUE \"upload_max_filesize=500M \\n post_max_size=500M \\n memory_limit=512M \\n max_execution_time=300 \\n max_input_time=300\";\n";
        $out .= "        fastcgi_read_timeout 300; fastcgi_send_timeout 300; fastcgi_connect_timeout 300;\n";
        $out .= "    }\n";
        $out .= "    # Audio /<mount> → Icecast\n";
        $out .= "    location ~ ^/([a-zA-Z0-9_-]+)\$ {\n";
        $out .= "        proxy_pass http://127.0.0.1:8000/\$1;\n";
        $out .= "        proxy_set_header Host \$host; proxy_buffering off; tcp_nodelay on;\n";
        $out .= "    }\n";
        $out .= "    # Límites de conexiones simultáneas por radio (auto-generado; vacío si no hay límites)\n";
        $out .= "    include /etc/nginx/radiopanel-limits/locations/*.conf;\n";
        $out .= "    location /            { try_files \$uri \$uri/ =404; }\n";
        $out .= "}\n\n";
        $emitidos++;
    }
    if ($emitidos === 0) {
        $out .= "# (sin dominios mapeados)\n";
    }
    return $out;
}

/**
 * Regenera la config nginx vía el wrapper root (sudoers NOPASSWD).
 * Reintenta una vez tras 3 s (el storage del VPS puede fallar de forma transitoria, EROFS puntual).
 * Si ambos intentos fallan (o proc_open falla), deja una línea en /var/log/radiopanel/dominios.log.
 * @param string $ctx descripción de la acción que disparó la regeneración (para el log).
 * @return array ['ok'=>bool,'output'=>string]
 */
function sp_dominios_regenerate($ctx = '') {
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'output' => "proc_open no está disponible en este servidor."];
    }
    $cmd = ['sudo', '-n', '/usr/local/sbin/radiopanel-dominios-update'];
    $fallos = [];
    for ($i = 0; $i < 2; $i++) {
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            $msg = "No se pudo ejecutar la regeneración de nginx (¿falta el permiso sudo del sistema?).";
            sp_dominios_log_fallo($ctx, [$msg]);
            return ['ok' => false, 'output' => $msg];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        $out_txt = trim((string)$stdout . "\n" . (string)$stderr);
        if ($code === 0) {
            return ['ok' => true, 'output' => $out_txt !== '' ? $out_txt : "(sin salida, código 0)"];
        }
        $fallos[] = $out_txt !== '' ? $out_txt : "(sin salida, código {$code})";
        if ($i === 0) sleep(3); // espera antes del reintento
    }
    sp_dominios_log_fallo($ctx, $fallos);
    return ['ok' => false, 'output' => end($fallos)];
}

/**
 * Escribe en /var/log/radiopanel/dominios.log un fallo de regeneración con diagnóstico
 * del montaje de / (rw/ro) y espacio libre, para detectar si el EROFS recurrente vuelve.
 */
function sp_dominios_log_fallo($ctx, array $fallos) {
    $flags = '?';
    $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES);
    if (is_array($mounts)) {
        foreach ($mounts as $__line) {
            $__p = preg_split('/\s+/', trim($__line));
            if (($__p[1] ?? '') === '/') { $flags = (string)($__p[3] ?? '?'); break; }
        }
    }
    $libres = @disk_free_space('/');
    $extra = '/ montado ' . $flags . ($libres !== false ? ', libres ' . number_format($libres / 1073741824, 1) . 'G' : '');
    $detalle = array_map(function ($t) {
        $t = trim((string)$t);
        $t = preg_replace('/\s+/', ' ', $t);
        return strlen($t) > 300 ? substr($t, 0, 300) . '…' : $t;
    }, $fallos);
    $linea = date('Y-m-d H:i:s') . ' | ' . ($ctx !== '' ? $ctx : '-') . ' | ' . $extra . ' | ' . implode(' || ', $detalle) . "\n";
    @file_put_contents('/var/log/radiopanel/dominios.log', $linea, FILE_APPEND | LOCK_EX);
}

} // fin guard
