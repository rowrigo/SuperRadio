<?php
// =============================================================
// Helpers compartidos de almacenamiento / cuota de disco.
// Los usa autodj_api.php (widgets del panel cliente) y
// superradio.php (columna Usado/Restante de la tabla de Emisoras).
// Todas las funciones van guardadas con function_exists() para que
// incluirlo más de una vez nunca provoque redefiniciones.
// =============================================================

// Formatea bytes a unidades legibles (KB/MB/GB/TB) con 2 decimales
if (!function_exists('storage_format_bytes')) {
function storage_format_bytes($bytes, $precision = 2) {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $pow = (int)floor(log($bytes, 1024));
    if ($pow >= count($units)) $pow = count($units) - 1;
    $val = $bytes / pow(1024, $pow);
    return number_format($val, $precision, '.', ',') . ' ' . $units[$pow];
}
}

// Calcula el espacio usado por un directorio de forma recursiva, con caché en disco
// TTL por defecto 120s para no martillear el HDD del VPS en cada carga
if (!function_exists('storage_dir_used_cached')) {
function storage_dir_used_cached($base_dir, $cache_path, $ttl_seconds = 120) {
    $cache_path = (string)$cache_path;
    $base_dir = rtrim((string)$base_dir, '/\\');
    if ($base_dir === '' || !is_dir($base_dir)) return 0.0;

    // 1) Intentar caché válido
    if ($cache_path !== '') {
        $cache_dir = dirname($cache_path);
        if (!is_dir($cache_dir)) {
            @mkdir($cache_dir, 0775, true);
            @chmod($cache_dir, 0775);
            if (function_exists('chown')) {
                $owner = @get_current_user();
                if ($owner && $owner !== '') @chown($cache_dir, $owner);
            }
        }
        if (is_dir($cache_dir) && is_file($cache_path)) {
            $age = time() - @filemtime($cache_path);
            if ($age >= 0 && $age <= $ttl_seconds) {
                $raw = @file_get_contents($cache_path);
                if ($raw !== false && $raw !== '') {
                    $arr = @json_decode($raw, true);
                    if (is_array($arr) && isset($arr['used_bytes'])) {
                        return (float)$arr['used_bytes'];
                    }
                }
            }
        }
    }

    // 2) Calcular a mano de forma segura
    $total = 0.0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base_dir, RecursiveDirectoryIterator::SKIP_DOTS
                | RecursiveDirectoryIterator::CURRENT_AS_FILEINFO
                | RecursiveDirectoryIterator::KEY_AS_PATHNAME),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $path => $fi) {
            if ($fi === null) continue;
            if ($fi->isFile()) {
                $s = @$fi->getSize();
                if ($s !== false && $s >= 0) $total += (float)$s;
            }
        }
    } catch (\Throwable $e) {
        try {
            // Fallback: escaneo simple de 1 nivel
            foreach ((@scandir($base_dir) ?: []) as $it2) {
                if ($it2 === '.' || $it2 === '..') continue;
                $p = $base_dir . '/' . $it2;
                if (is_file($p)) {
                    $s = @filesize($p);
                    if ($s !== false && $s >= 0) $total += (float)$s;
                }
            }
        } catch (\Throwable $e2) { $total = 0.0; }
    }

    // 3) Guardar caché
    if ($cache_path !== '' && $total > 0) {
        $cache_dir = dirname($cache_path);
        if (!is_dir($cache_dir)) {
            @mkdir($cache_dir, 0775, true);
            @chmod($cache_dir, 0775);
        }
        if (is_dir($cache_dir)) {
            @file_put_contents($cache_path, json_encode([
                'used_bytes' => $total,
                'computed_at' => date('Y-m-d H:i:s'),
                'base_dir' => $base_dir,
            ], JSON_UNESCAPED_UNICODE));
            @chmod($cache_path, 0664);
        }
    }

    return $total;
}
}
