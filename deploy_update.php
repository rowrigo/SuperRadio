<?php
header('Content-Type: application/json; charset=utf-8');

// ============================================================
// TOKEN DE DEPLOY
// Default = valor histórico de config.php. Si existe
// config.local.php (creado por pkg/install.sh por VPS) se usa
// el DEPLOY_TOKEN propio de ese VPS.
// ============================================================
$DEPLOY_TOKEN = 'scr_deploy_ca601bb45bf46e6cfd46';
if (is_file(__DIR__ . '/config.local.php')) {
    include __DIR__ . '/config.local.php';
    if (defined('DEPLOY_TOKEN') && DEPLOY_TOKEN !== '') $DEPLOY_TOKEN = DEPLOY_TOKEN;
}
// ============================================================

if (($_POST['token'] ?? '') !== $DEPLOY_TOKEN) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$DEPLOY_MAGIC = 'SCRDEPLOYv1';
$ALLOWED_EXT = ['php','js','css','html','svg','png','jpg','jpeg','gif','ico','map','woff','woff2','ttf','md','txt','json'];
$PROTECTED_FILES = ['database.json','deploy_update.php','config.local.php'];
$TARGET_DIR = realpath(__DIR__);

function d_str_starts_with($h, $n) { return $n === '' || strncmp($h, $n, strlen($n)) === 0; }
function d_str_ends_with($h, $n) { return $n === '' || substr($h, -strlen($n)) === $n; }
function d_str_contains($h, $n) { return $n === '' || strpos($h, $n) !== false; }
function d_basename($p) { $p = str_replace('\\','/',(string)$p); $i=strrpos($p,'/'); return $i===false?$p:substr($p,$i+1); }
function d_dirname($p) { $p = str_replace('\\','/',(string)$p); $i=strrpos($p,'/'); return $i===false?'.':($i===0?'/':substr($p,0,$i)); }
function d_pathinfo_ext($p) { $b = d_basename($p); $i = strrpos($b,'.'); return $i===false?'':strtolower(substr($b,$i+1)); }
function d_rrmdir($dir) {
    if (!is_dir($dir)) return;
    $items = @scandir($dir); if (!$items) return;
    $items = array_diff($items, ['.','..']);
    foreach ($items as $it) {
        $pp = $dir . '/' . $it;
        if (is_dir($pp)) d_rrmdir($pp); else @unlink($pp);
    }
    @rmdir($dir);
}

if (!isset($_FILES['update']) || $_FILES['update']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No se recibió paquete .sck o error al subir (error=' . ($_FILES['update']['error'] ?? 'n/a') . ')'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pack_path = $_FILES['update']['tmp_name'];
$fp = @fopen($pack_path, 'rb');
if (!$fp) {
    echo json_encode(['success' => false, 'error' => 'No se pudo abrir paquete recibido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$read_exact = function($n) use ($fp) {
    $got = '';
    while ($n > 0) {
        $chunk = fread($fp, $n);
        if ($chunk === false || $chunk === '') return false;
        $got .= $chunk;
        $n -= strlen($chunk);
    }
    return $got;
};

$magic = $read_exact(strlen($DEPLOY_MAGIC));
if ($magic !== $DEPLOY_MAGIC) {
    fclose($fp);
    echo json_encode(['success' => false, 'error' => 'Formato de paquete inválido (no SCRDEPLOYv1). Usa el .sck generado por deploy_prepare.php'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hlen_raw = $read_exact(4);
if ($hlen_raw === false || strlen($hlen_raw) !== 4) { fclose($fp); echo json_encode(['success'=>false,'error'=>'Paquete corrupto (H1)'], JSON_UNESCAPED_UNICODE); exit; }
$hlen_arr = unpack('Nlen', $hlen_raw);
$hlen = (int)($hlen_arr['len'] ?? 0);
if ($hlen <= 0 || $hlen > 16 * 1024 * 1024) { fclose($fp); echo json_encode(['success'=>false,'error'=>'Header length inválido: '.$hlen], JSON_UNESCAPED_UNICODE); exit; }
$header_raw = $read_exact($hlen);
if ($header_raw === false || strlen($header_raw) !== $hlen) { fclose($fp); echo json_encode(['success'=>false,'error'=>'No se pudo leer header JSON'], JSON_UNESCAPED_UNICODE); exit; }
$header = @json_decode($header_raw, true);
if (!is_array($header) || !isset($header['entries']) || !is_array($header['entries'])) { fclose($fp); echo json_encode(['success'=>false,'error'=>'Header JSON corrupto'], JSON_UNESCAPED_UNICODE); exit; }

$entries_clean = [];
foreach ($header['entries'] as $e) {
    if (!is_array($e) || empty($e['name']) || !isset($e['size'])) continue;
    $name = str_replace('\\', '/', (string)$e['name']);
    if (d_str_starts_with($name, '/') || d_str_contains($name, '/../') || d_str_starts_with($name, '../')) continue;
    if (d_str_starts_with($name, '__MACOSX')) continue;
    $base = d_basename($name);
    if (d_str_starts_with($base, '.')) continue;
    $ext = d_pathinfo_ext($base);
    if (!in_array($ext, $ALLOWED_EXT, true)) continue;
    if (in_array($base, $PROTECTED_FILES, true)) continue;
    $sz = (int)$e['size'];
    if ($sz < 0 || $sz > 80 * 1024 * 1024) continue;
    $entries_clean[] = ['name' => $name, 'size' => $sz, 'sha1' => isset($e['sha1']) ? (string)$e['sha1'] : null];
}
usort($entries_clean, function($a,$b){ return strcmp($a['name'],$b['name']); });

if (empty($entries_clean)) {
    fclose($fp);
    echo json_encode(['success' => false, 'error' => 'Paquete sin archivos válidos. Ext permitidas: ' . implode(', ', $ALLOWED_EXT) . '. Protegidos: ' . implode(', ', $PROTECTED_FILES)], JSON_UNESCAPED_UNICODE);
    exit;
}

$extract_dir = sys_get_temp_dir() . '/deploy_' . bin2hex(pack('Nn', mt_rand(), mt_rand(0,65535)));
@mkdir($extract_dir, 0775, true);
$files_to_copy = [];
$extract_errors = [];
foreach ($entries_clean as $e) {
    if ($e['size'] > 0) {
        $data = $read_exact($e['size']);
        if ($data === false || strlen($data) !== $e['size']) { $extract_errors[] = $e['name'] . ' (short read)'; continue; }
    } else {
        $data = '';
    }
    if (!empty($e['sha1']) && sha1($data) !== $e['sha1']) { $extract_errors[] = $e['name'] . ' (sha1 mismatch)'; continue; }
    $rel_dir = ltrim(d_dirname($e['name']), '/.');
    $local_dir = $extract_dir . ($rel_dir ? ('/' . $rel_dir) : '');
    if (!is_dir($local_dir)) @mkdir($local_dir, 0775, true);
    $local_path = $extract_dir . '/' . $e['name'];
    $wr = @file_put_contents($local_path, $data);
    if ($wr === false) { $extract_errors[] = $e['name'] . ' (no write tmp)'; continue; }
    @chmod($local_path, 0664);
    $files_to_copy[] = $e['name'];
}
fclose($fp);

if (!empty($extract_errors)) {
    d_rrmdir($extract_dir);
    echo json_encode(['success'=>false, 'error'=>'Errores al extraer paquete: '.implode('; ', $extract_errors)], JSON_UNESCAPED_UNICODE);
    exit;
}

$syntax_errors = [];
$syntax_check_disabled = false;
$syntax_check_warning = null;

function d_find_php_cli() {
    $candidates = [
        '/usr/bin/php',
        '/usr/bin/php8.1',
        '/usr/bin/php8.2',
        '/usr/bin/php8.0',
        '/usr/bin/php7.4',
        '/usr/local/bin/php',
        PHP_BINARY,
        'php',
    ];
    foreach ($candidates as $bin) {
        $out = []; $code = 0;
        $esc = escapeshellcmd($bin);
        @exec($esc . ' -r "echo PHP_VERSION;" 2>&1', $out, $code);
        if ($code === 0 && !empty($out) && preg_match('/^[0-9]+\.[0-9]+/', trim(implode('', $out)))) {
            // Confirm it supports -l with a syntax check on a temp file
            $tmp = tempnam(sys_get_temp_dir(), 'phplint_');
            @file_put_contents($tmp, '<?php $x=1;');
            $code2 = 1;
            @exec($esc . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out2, $code2);
            @unlink($tmp);
            if ($code2 === 0) return $bin;
        }
    }
    return false;
}

$php_cli_bin = d_find_php_cli();
if (!$php_cli_bin) {
    $syntax_check_disabled = true;
    $syntax_check_warning = 'No se encontró binario PHP CLI compatible con -l (PHP_BINARY=' . PHP_BINARY . '). Se omite comprobación sintáctica pero se continúa con el deploy. Verifica sintaxis manualmente si hay errores post-deploy.';
} else {
    foreach ($files_to_copy as $f) {
        $ext = d_pathinfo_ext($f);
        if ($ext !== 'php') continue;
        $local = $extract_dir . '/' . $f;
        if (!file_exists($local)) continue;
        $synt_out = []; $synt_code = 0;
        exec($php_cli_bin . ' -l ' . escapeshellarg($local) . ' 2>&1', $synt_out, $synt_code);
        if ($synt_code !== 0) {
            $out_str = trim(implode("\n", $synt_out));
            if (d_str_contains($out_str, 'Usage:') && d_str_contains($out_str, 'php-fpm')) {
                continue;
            }
            if (preg_match('/No syntax errors detected/i', $out_str)) {
                continue;
            }
            $syntax_errors[] = ['file' => $f, 'output' => $out_str];
        }
    }
}

if (!empty($syntax_errors)) {
    d_rrmdir($extract_dir);
    echo json_encode([
        'success' => false,
        'error' => 'Algunos archivos PHP tienen errores de sintaxis (ninguno fue reemplazado).',
        'syntax_errors' => $syntax_errors,
        'php_cli_used' => $php_cli_bin,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$backup_dir = $TARGET_DIR . '/_deploy_backup_' . date('Ymd_His');
$backups_created = [];
$replaced = [];
$copied = [];
foreach ($files_to_copy as $f) {
    $src = $extract_dir . '/' . $f;
    if (!file_exists($src)) continue;
    $dst = $TARGET_DIR . '/' . $f;
    $dst_parent = dirname($dst);
    if (!is_dir($dst_parent)) @mkdir($dst_parent, 0775, true);
    if (file_exists($dst)) {
        $rel_dir = ltrim(d_dirname($f), '/.');
        $b_dir = $backup_dir . ($rel_dir ? ('/' . $rel_dir) : '');
        if (!is_dir($b_dir)) @mkdir($b_dir, 0775, true);
        $b_dst = $b_dir . '/' . d_basename($f);
        if (@copy($dst, $b_dst)) $backups_created[] = $f;
        if (@unlink($dst)) $replaced[] = $f;
        else $copied[] = ['file'=>$f,'error'=>'no se pudo borrar viejo'];
    }
    if (@copy($src, $dst)) {
        @chmod($dst, 0664);
        if (!in_array($f, $replaced, true)) $copied[] = $f;
    } else {
        $copied[] = ['file'=>$f,'error'=>'no se pudo escribir destino'];
    }
}
d_rrmdir($extract_dir);

$summary_extra = [];
if ($syntax_check_disabled) $summary_extra['syntax_check'] = 'disabled';
if ($syntax_check_warning) $summary_extra['syntax_check_warning'] = $syntax_check_warning;
if ($php_cli_bin) $summary_extra['php_cli_bin'] = $php_cli_bin;

echo json_encode([
    'success' => true,
    'summary' => array_merge([
        'total_archivos_en_paquete' => count($entries_clean),
        'reemplazados' => count($replaced),
        'nuevos' => count($files_to_copy) - count($replaced),
        'backup_creado' => is_dir($backup_dir) ? basename($backup_dir) : null,
        'backup_cantidad_archivos' => count($backups_created),
    ], $summary_extra),
    'archivos' => $files_to_copy,
], JSON_UNESCAPED_UNICODE);
