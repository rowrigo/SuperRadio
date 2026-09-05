<?php
define('ROOT_RADIOPANEL', __DIR__);
require_once ROOT_RADIOPANEL . '/config.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$mount = '';
$req_mode = 'any';
$probe_only = 0;
if (!empty($argv) && is_array($argv)) {
    foreach ($argv as $a) {
        if (preg_match('/^--mount=(.+)$/i', $a, $m)) { $mount = $m[1]; continue; }
        if (preg_match('/^mount=(.+)$/i', $a, $m))   { $mount = $m[1]; continue; }
        if (preg_match('/^--mode=(.+)$/i', $a, $m))  { $req_mode = strtolower(trim($m[1])); continue; }
        if (preg_match('/^mode=(.+)$/i', $a, $m))    { $req_mode = strtolower(trim($m[1])); continue; }
        if (preg_match('/^--probe(?:=(.+))?$/i', $a, $m))  { $probe_only = intval($m[1] ?? 1); continue; }
        if (preg_match('/^probe=(.+)$/i', $a, $m))    { $probe_only = intval($m[1]); continue; }
    }
}
if ($mount === '') {
    $mount = $_GET['mount'] ?? $_POST['mount'] ?? $_REQUEST['mount'] ?? '';
    $req_mode = strtolower(trim($_GET['mode'] ?? $_POST['mode'] ?? $_REQUEST['mode'] ?? $req_mode));
    $probe_only = intval($_GET['probe'] ?? $_POST['probe'] ?? $_REQUEST['probe'] ?? $probe_only);
}
$mount = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$mount)));
if (!in_array($req_mode, ['any', 'inmediato', 'insertado', 'normal'], true)) $req_mode = 'any';
if ($mount === '') { if (PHP_SAPI !== 'cli') http_response_code(400); echo "ERR missing mount\n"; exit(1); }

$db = file_exists(DB_FILE) ? (json_decode(@file_get_contents(DB_FILE), true) ?: []) : [];
$radio = null;
$radio_id_key = null;
foreach ($db['radios'] ?? [] as $k => $r) {
    $m_clean = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $r['mountpoint'] ?? '')));
    if ($m_clean === $mount) { $radio = $r; $radio_id_key = $k; break; }
}
if (!$radio) { if (PHP_SAPI !== 'cli') http_response_code(404); echo "ERR radio_not_found mount={$mount}\n"; exit(1); }

$base_dir = $radio['media_dir'] ?? ("/var/media/radios/{$mount}");
if (!is_dir($base_dir)) { if (PHP_SAPI !== 'cli') http_response_code(500); echo "ERR media_dir_not_exists {$base_dir}\n"; exit(1); }

$data_file = "{$base_dir}/programacion.json";
$default_data = [
    'timezone'         => 'America/Costa_Rica',
    'default_playlist' => 'general',
    'playlists'        => [],
    'schedule'         => [],
    'ads'              => [],
    'time_voice'       => ['enabled' => false, 'folder' => ''],
];
$app_data = file_exists($data_file) ? (json_decode(@file_get_contents($data_file), true) ?: $default_data) : $default_data;
$app_data = array_replace_recursive($default_data, $app_data);

$tz = $app_data['timezone'] ?? 'America/Costa_Rica';
if ($tz === '' || !is_string($tz)) $tz = 'America/Costa_Rica';
@date_default_timezone_set($tz);
$dt_zone = @timezone_open($tz);
if (!$dt_zone) $dt_zone = @timezone_open('America/Costa_Rica');
$dt = new DateTime('now', $dt_zone ?: new DateTimeZone('America/Costa_Rica'));
$dow_no = (int)$dt->format('N');
$h   = (int)$dt->format('G');
$m   = (int)$dt->format('i');
$s   = (int)$dt->format('s');
$hm  = $h * 60 + $m;
$sec_of_day = $hm * 60 + $s;

$NS_STATE_DIR = "{$base_dir}/.nextsong_state";
@mkdir($NS_STATE_DIR, 0775, true);
if (!is_dir($NS_STATE_DIR)) $NS_STATE_DIR = sys_get_temp_dir();
$NS_LAST_PLAYED = "{$NS_STATE_DIR}/last_played.json";
$NS_COUNTER    = "{$NS_STATE_DIR}/counter.json";
$NS_LAST_PLAYED_INM = "{$NS_STATE_DIR}/last_played_inm.json";
$NS_LAST_PLAYED_INS = "{$NS_STATE_DIR}/last_played_ins.json";
$NS_ID3_CACHE_DIR   = "{$NS_STATE_DIR}/id3_cache";
$NS_CURRENT_FILE    = "{$NS_STATE_DIR}/current_song.json";
$NS_HISTORY_FILE    = "{$NS_STATE_DIR}/history.json";
$NS_DEFAULT_COVER   = "{$NS_STATE_DIR}/default_cover.jpg";
@mkdir($NS_ID3_CACHE_DIR, 0775, true);

// ---------- HELPERS METADATOS (ID3v2 puro PHP + iTunes API + fallback filename) ----------

function ns_unpack_syncint($raw4) {
    $b = array_values(unpack('C*', $raw4));
    if (count($b) < 4) return 0;
    return ($b[1] << 21) | ($b[2] << 14) | ($b[3] << 7) | $b[4];
}

function ns_iconv($s, $fromEnc, $toEnc = 'UTF-8') {
    $s = (string)$s;
    if ($s === '') return '';
    $from = strtoupper((string)$fromEnc);
    $to   = strtoupper((string)$toEnc);
    if ($from === $to) return $s;
    if (function_exists('iconv')) {
        $map = [
            'UTF-16'   => 'UTF-16LE',
            'UTF16'    => 'UTF-16LE',
            'ISO-8859-1' => 'ISO-8859-1',
            'LATIN1'   => 'ISO-8859-1',
            'UTF8'     => 'UTF-8',
        ];
        $f = $map[$from] ?? $from;
        $t = $map[$to]   ?? $to;
        try {
            $r = @iconv($f, $t . '//IGNORE', $s);
            if ($r !== false && $r !== null) return (string)$r;
        } catch (\Throwable $e) { /* fallthrough */ }
    }
    if ($from === 'ISO-8859-1' && $to === 'UTF-8') {
        return function_exists('utf8_encode') ? @utf8_encode($s) : $s;
    }
    if ($from === 'UTF-8' && $to === 'ISO-8859-1') {
        return function_exists('utf8_decode') ? @utf8_decode($s) : $s;
    }
    if ($from === 'UTF-16LE' && $to === 'UTF-8') {
        $out = ''; $i = 0; $n = strlen($s);
        while ($i + 1 < $n) {
            $cp = ord($s[$i]) | (ord($s[$i+1]) << 8); $i += 2;
            if (($cp & 0xFC00) === 0xD800 && $i + 1 < $n) {
                $lo = ord($s[$i]) | (ord($s[$i+1]) << 8); $i += 2;
                $cp = 0x10000 + (($cp & 0x3FF) << 10) + ($lo & 0x3FF);
            }
            if ($cp < 0x80) $out .= chr($cp);
            elseif ($cp < 0x800) $out .= chr(0xC0 | ($cp>>6)) . chr(0x80 | ($cp&0x3F));
            elseif ($cp < 0x10000) $out .= chr(0xE0 | ($cp>>12)) . chr(0x80 | (($cp>>6)&0x3F)) . chr(0x80 | ($cp&0x3F));
            else $out .= chr(0xF0 | ($cp>>18)) . chr(0x80 | (($cp>>12)&0x3F)) . chr(0x80 | (($cp>>6)&0x3F)) . chr(0x80 | ($cp&0x3F));
        }
        return $out;
    }
    if ($from === 'UTF-16BE' && $to === 'UTF-8') {
        $out = ''; $i = 0; $n = strlen($s);
        while ($i + 1 < $n) {
            $cp = (ord($s[$i]) << 8) | ord($s[$i+1]); $i += 2;
            if (($cp & 0xFC00) === 0xD800 && $i + 1 < $n) {
                $lo = (ord($s[$i]) << 8) | ord($s[$i+1]); $i += 2;
                $cp = 0x10000 + (($cp & 0x3FF) << 10) + ($lo & 0x3FF);
            }
            if ($cp < 0x80) $out .= chr($cp);
            elseif ($cp < 0x800) $out .= chr(0xC0 | ($cp>>6)) . chr(0x80 | ($cp&0x3F));
            elseif ($cp < 0x10000) $out .= chr(0xE0 | ($cp>>12)) . chr(0x80 | (($cp>>6)&0x3F)) . chr(0x80 | ($cp&0x3F));
            else $out .= chr(0xF0 | ($cp>>18)) . chr(0x80 | (($cp>>12)&0x3F)) . chr(0x80 | (($cp>>6)&0x3F)) . chr(0x80 | ($cp&0x3F));
        }
        return $out;
    }
    return (string)$s;
}
function ns_is_valid_utf8($s) {
    $s = (string)$s;
    if ($s === '') return true;
    return (bool)preg_match('//u', $s);
}
function ns_dec_enc($s, $default_encoding = 'UTF-8') {
    static $fromMap = ['UTF-16LE', 'UTF-16BE', 'UTF-16', 'UTF-8', 'ISO-8859-1'];
    $enc = $default_encoding;
    foreach ($fromMap as $e) {
        $d = ns_iconv($s, $e, 'UTF-8');
        if ($d !== '' && ns_is_valid_utf8($d)) { $enc = $e; break; }
    }
    $out = ns_iconv($s, $enc, 'UTF-8');
    if ($out === '') $out = (string)$s;
    return rtrim($out, "\0");
}

function ns_read_id3v2(string $mp3, string $cover_dir, string $cover_name): array {
    $out = ['title' => '', 'artist' => '', 'album' => '', 'cover' => null, 'has_cover' => false];
    $fp = @fopen($mp3, 'rb');
    if (!$fp) return $out;
    $head = @fread($fp, 10);
    if ($head === false || strlen($head) < 10 || substr($head, 0, 3) !== 'ID3') { @fclose($fp); return $out; }
    $major = ord($head[3]);
    if ($major < 2 || $major > 4) { @fclose($fp); return $out; }
    $size = ns_unpack_syncint(substr($head, 6, 4));
    if ($size <= 0 || $size > 20 * 1024 * 1024) { @fclose($fp); return $out; }
    $tags = @fread($fp, $size);
    @fclose($fp);
    if ($tags === false || strlen($tags) < 10) return $out;
    $p = 0;
    $len = strlen($tags);
    $framePrefixLen = ($major === 4 || $major === 3) ? 10 : 6;
    $extractedTitle = null; $extractedArtist = null; $extractedAlbum = null; $apicExtracted = null;
    while ($p + $framePrefixLen <= $len) {
        $id = substr($tags, $p, ($major === 2 ? 3 : 4));
        if ($id === false || trim($id) === '') break;
        $frameLen = 0;
        if ($major === 2) { $frameLen = (ord($tags[$p+3]) << 16) | (ord($tags[$p+4]) << 8) | ord($tags[$p+5]); }
        else { $frameLen = ns_unpack_syncint_32(substr($tags, $p + 4, 4)); }
        if ($frameLen <= 0) { $p += $framePrefixLen; continue; }
        if ($p + $framePrefixLen + $frameLen > $len) break;
        $flags = $major === 2 ? 0 : unpack('n', substr($tags, $p + 8, 2))[1];
        $compressed = ($major !== 2) && (($flags & 0x0080) !== 0);
        $data = substr($tags, $p + $framePrefixLen, $frameLen);
        if ($compressed) {
            $realLen = ns_unpack_syncint_32(substr($data, 0, 4));
            $data = substr($data, 4);
            if (function_exists('zlib_uncompress')) { $u = @zlib_uncompress($data, $realLen > 0 ? $realLen : null); if ($u !== false) $data = $u; }
        }
        $knownId = $id;
        if ($major === 2) {
            $map2 = ['TT2'=>'TIT2','TP1'=>'TPE1','TAL'=>'TALB','PIC'=>'APIC','TYE'=>'TYER','COM'=>'COMM'];
            $knownId = $map2[$id] ?? $id;
        }
        if ($knownId === 'TIT2' && $extractedTitle === null)   { $extractedTitle  = ns_extract_text($data, $major); }
        if ($knownId === 'TPE1' && $extractedArtist === null)  { $extractedArtist = ns_extract_text($data, $major); }
        if ($knownId === 'TALB' && $extractedAlbum === null)   { $extractedAlbum  = ns_extract_text($data, $major); }
        if ($knownId === 'APIC' && $apicExtracted === null) {
            $pic = ns_extract_apic($data, $major);
            if ($pic !== null) $apicExtracted = $pic;
        }
        $p += $framePrefixLen + $frameLen;
    }
    if ($extractedTitle  !== null) $out['title']  = trim($extractedTitle);
    if ($extractedArtist !== null) $out['artist'] = trim($extractedArtist);
    if ($extractedAlbum  !== null) $out['album']  = trim($extractedAlbum);
    if ($apicExtracted !== null && is_array($apicExtracted) && !empty($apicExtracted['data'])) {
        $ext = 'jpg';
        $mime = strtolower($apicExtracted['mime'] ?? 'image/jpeg');
        if (strpos($mime, 'png') !== false) $ext = 'png';
        elseif (strpos($mime, 'gif') !== false) $ext = 'gif';
        elseif (strpos($mime, 'webp') !== false) $ext = 'webp';
        $coverFile = rtrim($cover_dir, '/\\') . '/' . $cover_name . '.' . $ext;
        $wrote = @file_put_contents($coverFile, $apicExtracted['data']);
        if ($wrote !== false) { $out['cover'] = basename($coverFile); $out['has_cover'] = true; }
    }
    return $out;
}
function ns_unpack_syncint_32($raw4) {
    $b = array_values(unpack('C*', $raw4));
    if (count($b) < 4) return 0;
    if (($b[1] & 0x80) === 0) {
        return ($b[1] << 24) | ($b[2] << 16) | ($b[3] << 8) | $b[4];
    }
    return ($b[1] << 21) | ($b[2] << 14) | ($b[3] << 7) | $b[4];
}
function ns_extract_text($data, $major) {
    if ($data === '' || $data === false) return null;
    $encByte = ord($data[0]);
    $encodings = [0 => 'ISO-8859-1', 1 => 'UTF-16LE', 2 => 'UTF-16BE', 3 => 'UTF-8'];
    $enc = $encodings[$encByte] ?? 'UTF-8';
    $body = substr($data, 1);
    if ($encByte === 1 && substr($body, 0, 2) === "\xFF\xFE") $body = substr($body, 2);
    if ($encByte === 2 && substr($body, 0, 2) === "\xFE\xFF") $body = substr($body, 2);
    $out = ns_iconv($body, $enc, 'UTF-8');
    $out = ns_clean_tag_string($out);
    $s = trim(rtrim($out, "\0"));
    return $s === '' ? null : $s;
}

function ns_clean_tag_string($s) {
    $s = (string)$s;
    if ($s === '') return '';

    if (!ns_is_valid_utf8($s)) {
        $t = ns_iconv($s, 'ISO-8859-1', 'UTF-8');
        if (ns_is_valid_utf8($t) && $t !== '') $s = $t;
    }

    $s = str_replace(["\0", "\xFF\xFE", "\xFE\xFF", "\xEF\xBB\xBF"], ' ', $s);
    if (str_starts_with($s, "\xFF\xFE") || str_starts_with($s, "\xFE\xFF")) $s = substr($s, 2);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);

    $frames = '(?:TRCK|TPE1|TCON|TLEN|TALB|TIT2|TYER|COMM|USLT|WXXX|TCOP|TPUB|TENC|TOPE|TCOM|TEXT|TLAN|TPE2|TPE3|TPE4|TPOS|TDRC|TDOR|TORY|APIC|PIC|GEOB|PRIV|RVA2|EQU2|RVRB|IPLS|MCDI|TKEY|TMOO|TOAL|TOFN|TOLY|TOWN|TPA|TPB|TRDA|TRSN|TRSO|TSIZ|TSRC|TSS1|TXXX|UFID|USER|WCOP|WOAF|WOAR|WOAS|WORS|WPAY|WPUB|SEEK|ASPI|BIN|MLLT|POSS|RBUF|SYLT|SYTC)';
    $s = preg_replace('/\b' . $frames . '\b/u', '', $s);
    $s = preg_replace('/' . $frames . '[\x00-\x1F]{1,8}/u', '', $s);
    $s = preg_replace('/' . $frames . '[A-Z]{3,}/u', '', $s);

    $clean = '';
    $n = strlen($s);
    $i = 0;
    while ($i < $n) {
        $o = ord($s[$i]);
        if ($o < 0x80) {
            if ($o === 9 || ($o >= 32 && $o <= 126)) $clean .= $s[$i];
            $i++;
            continue;
        }
        $seq = 1;
        if (($o & 0xE0) === 0xC0) $seq = 2;
        elseif (($o & 0xF0) === 0xE0) $seq = 3;
        elseif (($o & 0xF8) === 0xF0) $seq = 4;
        else { $i++; continue; }
        if ($i + $seq > $n) { $i++; continue; }
        $chunk = substr($s, $i, $seq);
        if (!preg_match('//u', $chunk)) { $i++; continue; }
        $cp = 0;
        if ($seq === 2) $cp = (($o & 0x1F) << 6) | (ord($s[$i+1]) & 0x3F);
        elseif ($seq === 3) $cp = (($o & 0x0F) << 12) | ((ord($s[$i+1]) & 0x3F) << 6) | (ord($s[$i+2]) & 0x3F);
        else $cp = (($o & 0x07) << 18) | ((ord($s[$i+1]) & 0x3F) << 12) | ((ord($s[$i+2]) & 0x3F) << 6) | (ord($s[$i+3]) & 0x3F);

        $ok = false;
        if ($cp < 0x80) $ok = false;
        elseif ($cp >= 0xA0 && $cp <= 0x24FF) $ok = true;
        elseif ($cp >= 0x2500 && $cp <= 0x27BF) $ok = true;
        elseif ($cp >= 0x2C00 && $cp <= 0x2E5F) $ok = true;
        elseif ($cp >= 0x3000 && $cp <= 0x303F) $ok = true;
        elseif ($cp >= 0x3040 && $cp <= 0x9FFF) $ok = true;
        elseif ($cp >= 0xAC00 && $cp <= 0xD7AF) $ok = true;
        elseif ($cp >= 0xE000 && $cp <= 0xF8FF) $ok = true;
        elseif ($cp >= 0xF900 && $cp <= 0xFAFF) $ok = true;
        elseif ($cp >= 0xFB00 && $cp <= 0xFDFF) $ok = true;
        elseif ($cp >= 0xFE30 && $cp <= 0xFE4F) $ok = true;
        elseif ($cp >= 0x1F000 && $cp <= 0x1FFFF) $ok = true;

        if ($ok) $clean .= $chunk;
        $i += $seq;
    }

    $clean = preg_replace('/[ \t]{2,}/u', ' ', $clean);
    $clean = preg_replace('/\s+([-\/])\s+/u', '$1', $clean);
    $clean = preg_replace('/\s{2,}/u', ' ', $clean);
    $clean = trim($clean);

    if ($clean === '' && trim($s) !== '') {
        $fallback = preg_replace('/[\x00-\x1F\x7F\x80-\xFF]/u', '', $s);
        $fallback = trim(preg_replace('/\s{2,}/u', ' ', $fallback));
        if ($fallback !== '') return $fallback;
    }

    return $clean;
}
function ns_extract_apic($data, $major) {
    if ($data === '' || $data === false) return null;
    $encByte = ord($data[0]);
    $p = 1;
    if ($major === 2) {
        $mime = 'image/' . strtolower($data[$p] . $data[$p+1] . $data[$p+2]);
        $p += 3;
        if ($mime === 'image/jpg') $mime = 'image/jpeg';
    } else {
        $mime = '';
        while ($p < strlen($data)) { $c = $data[$p]; $p++; if ($c === "\0") break; $mime .= $c; }
    }
    if ($p >= strlen($data)) return null;
    $picType = ord($data[$p]); $p++;
    $desc = '';
    if ($encByte === 0 || $encByte === 3) { while ($p < strlen($data)) { $c = $data[$p]; $p++; if ($c === "\0") break; $desc .= $c; } }
    else {
        while ($p + 1 < strlen($data)) { $w = $data[$p] . $data[$p+1]; $p += 2; if ($w === "\0\0") break; $desc .= $w; }
    }
    $picData = substr($data, $p);
    if ($picData === false || $picData === '') return null;
    return ['mime' => $mime, 'type' => $picType, 'desc' => $desc, 'data' => $picData];
}

function ns_filename_parse($path): array {
    $base = basename($path);
    $base = preg_replace('/\.(mp3|m4a|ogg|wav|flac|aac)$/i', '', $base);
    if ($base === null || $base === '') return ['artist' => '', 'title' => $path];
    $base = preg_replace('/^\s*\d+\s*[\.\-]\s*/', '', $base);
    $parts = preg_split('/\s+[-–—]\s+/', $base, 2);
    if ($parts !== false && count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
        return ['artist' => trim($parts[0]), 'title' => trim($parts[1])];
    }
    return ['artist' => '', 'title' => trim($base)];
}

function ns_itunes_search(string $artist, string $title, int $timeout_ms = 2200): ?array {
    $term = trim(trim($artist) . ' ' . trim($title));
    if ($term === '') return null;
    $url = 'https://itunes.apple.com/search?media=music&entity=song&limit=1&term=' . rawurlencode($term);
    $ctx = stream_context_create([
        'http' => [
            'timeout'  => max(1.0, (float)$timeout_ms / 1000.0),
            'method'   => 'GET',
            'header'   => "User-Agent: SuperRadio-AutoDJ/1.0\r\nAccept: application/json\r\n",
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || trim($raw) === '') return null;
    $j = @json_decode($raw, true);
    if (!is_array($j) || empty($j['resultCount']) || empty($j['results']) || !is_array($j['results'])) return null;
    $r = $j['results'][0] ?? null;
    if (!is_array($r)) return null;
    $art = $r['artworkUrl100'] ?? null;
    $highRes = null;
    if (is_string($art) && $art !== '') {
        $highRes = str_replace('/100x100bb.jpg', '/600x600bb.jpg', $art);
        if (strpos($highRes, '/600x600') === false && strpos($highRes, '/100x100') !== false) {
            $highRes = preg_replace('#/100x100[^/]*\.jpg#', '/600x600bb.jpg', $art);
        }
    }
    return [
        'artist'   => (string)($r['artistName'] ?? $artist),
        'title'    => (string)($r['trackName']  ?? $title),
        'album'    => (string)($r['collectionName'] ?? ''),
        'artwork_low'  => $art,
        'artwork_high' => $highRes,
        'genre'    => (string)($r['primaryGenreName'] ?? ''),
    ];
}

function ns_download_cover_curl_or_fgc(string $url, string $dest_file): bool {
    if ($url === '' || $dest_file === '') return false;
    $timeout = 5;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout + 3,
            CURLOPT_FAILONERROR    => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'SuperRadio-AutoDJ/1.0',
        ]);
        $content = curl_exec($ch);
        $code = 0;
        $ctype = '';
        if ($content !== false) {
            $info = curl_getinfo($ch);
            $code = (int)($info['http_code'] ?? 0);
            $ctype = (string)($info['content_type'] ?? '');
        }
        curl_close($ch);
        if ($code >= 200 && $code < 400 && is_string($content) && $content !== '' && strpos($ctype, 'image') !== false) {
            $ok = @file_put_contents($dest_file, $content);
            return $ok !== false && $ok > 0;
        }
    }
    $ctx = stream_context_create([
        'http' => [
            'timeout'  => $timeout,
            'method'   => 'GET',
            'header'   => "User-Agent: SuperRadio-AutoDJ/1.0\r\nAccept: image/*\r\n",
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $img = @file_get_contents($url, false, $ctx);
    if ($img === false || !is_string($img) || strlen($img) < 256) return false;
    $ok = @file_put_contents($dest_file, $img);
    return $ok !== false && $ok > 0;
}

function ns_get_fallback_cover_url($mount, $NS_STATE_DIR, $NS_DEFAULT_COVER, bool $forJson): string {
    $url = 'autodj_api.php?action=serve_default_cover&mount=' . rawurlencode($mount);
    if (is_file($NS_DEFAULT_COVER)) { $url .= '&t=' . @filemtime($NS_DEFAULT_COVER); }
    if ($forJson && !preg_match('#^https?://#i', $url)) {
        if (!empty($_SERVER['REQUEST_SCHEME']) && !empty($_SERVER['HTTP_HOST'])) {
            $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($url, '/');
        }
    }
    return $url;
}

function ns_metadata_fallback_chain($fullpath, $mount, $base_dir, $NS_ID3_CACHE_DIR, $NS_STATE_DIR, $NS_DEFAULT_COVER, $app_data, $radio): array {
    $isSilence = false;
    $abs = (string)$fullpath;
    if (stripos($abs, '/silence') !== false || stripos($abs, '/blank.') !== false || preg_match('#silence\.mp3$#i', $abs)) { $isSilence = true; }
    $pathKey = sha1($abs);
    $cache_file = rtrim($NS_ID3_CACHE_DIR, '/\\') . '/' . $pathKey . '.json';
    $usedCached = false;
    $cached = null;
    if (is_file($cache_file) && (time() - @filemtime($cache_file)) < 86400 * 30) {
        $j = @json_decode(@file_get_contents($cache_file), true);
        if (is_array($j)) { $cached = $j; $usedCached = true; }
    }
    $final = null;
    if (!$isSilence && is_file($abs)) {
        if ($usedCached && is_array($cached)) {
            $final = $cached;
        } else {
            $localId3 = ns_read_id3v2($abs, $NS_ID3_CACHE_DIR, $pathKey);
            $parsedFn = ns_filename_parse($abs);
            $artistCandidate = trim($localId3['artist'] ?? '') !== '' ? trim($localId3['artist']) : trim($parsedFn['artist'] ?? '');
            $titleCandidate  = trim($localId3['title']  ?? '') !== '' ? trim($localId3['title'])  : trim($parsedFn['title']  ?? basename($abs));
            $albumCandidate  = trim($localId3['album']  ?? '');
            $coverLocalRelative = null;
            if (!empty($localId3['has_cover']) && !empty($localId3['cover'])) {
                $coverLocalRelative = $localId3['cover'];
            }
            $itunes = null;
            if (empty($coverLocalRelative) || $artistCandidate === '' || $titleCandidate === '' || $albumCandidate === '') {
                $itunes = ns_itunes_search($artistCandidate, $titleCandidate, 1800);
            }
            if (is_array($itunes)) {
                if ($artistCandidate === '') $artistCandidate = trim($itunes['artist'] ?? '');
                if ($titleCandidate  === '') $titleCandidate  = trim($itunes['title']  ?? '');
                if ($albumCandidate  === '' && !empty($itunes['album'])) $albumCandidate = trim($itunes['album']);
                if (empty($coverLocalRelative) && !empty($itunes['artwork_high'])) {
                    $ext = 'jpg';
                    $url = (string)$itunes['artwork_high'];
                    $destFile = rtrim($NS_ID3_CACHE_DIR, '/\\') . '/' . $pathKey . '.' . $ext;
                    if (!is_file($destFile)) {
                        $ok = ns_download_cover_curl_or_fgc($url, $destFile);
                        if ($ok) { $coverLocalRelative = basename($destFile); }
                    } else {
                        $coverLocalRelative = basename($destFile);
                    }
                    if (empty($coverLocalRelative) && !empty($itunes['artwork_low'])) {
                        $destLow = rtrim($NS_ID3_CACHE_DIR, '/\\') . '/' . $pathKey . '_low.jpg';
                        if (!is_file($destLow) && ns_download_cover_curl_or_fgc((string)$itunes['artwork_low'], $destLow)) {
                            $coverLocalRelative = basename($destLow);
                        }
                    }
                }
            }
            $final = [
                'title'       => $titleCandidate  !== '' ? $titleCandidate  : basename($abs),
                'artist'      => $artistCandidate !== '' ? $artistCandidate : '',
                'album'       => $albumCandidate,
                'local_cover' => $coverLocalRelative,
                'from'        => [
                    'id3'    => !empty($localId3) && (trim($localId3['artist'].$localId3['title']) !== ''),
                    'itunes' => is_array($itunes),
                    'fn'     => true,
                ],
            ];
            @file_put_contents($cache_file, json_encode($final, JSON_UNESCAPED_UNICODE));
        }
    }
    if ($final === null || !is_array($final)) {
        $final = [
            'title'  => $isSilence ? 'Silencio (AutoDJ sin música)' : (basename($abs) !== '' ? basename($abs) : 'Sin transmisión'),
            'artist' => '',
            'album'  => '',
            'local_cover' => null,
            'from'   => ['silence' => $isSilence],
        ];
    }
    $final['path'] = $abs;
    $final['filesize_mb'] = @is_file($abs) ? round(max(0.0, (@filesize($abs) ?: 0) / (1024 * 1024)), 2) : 0.0;
    $coverPublic = '';
    if (!empty($final['local_cover'])) {
        $coverAbs = rtrim($NS_ID3_CACHE_DIR, '/\\') . '/' . basename($final['local_cover']);
        if (is_file($coverAbs)) {
            $url = 'autodj_api.php?action=serve_cached_cover&mount=' . rawurlencode($mount) . '&f=' . rawurlencode(basename($final['local_cover'])) . '&t=' . @filemtime($coverAbs);
            if (PHP_SAPI === 'cli' || !empty($_SERVER['REQUEST_SCHEME'])) {
                if (!empty($_SERVER['REQUEST_SCHEME']) && !empty($_SERVER['HTTP_HOST'])) {
                    $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($url, '/');
                }
            }
            $coverPublic = $url;
        }
    }
    if ($coverPublic === '') {
        $coverPublic = ns_get_fallback_cover_url($mount, $NS_STATE_DIR, $NS_DEFAULT_COVER, PHP_SAPI !== 'cli' && !empty($_SERVER['REQUEST_SCHEME']));
    }
    $final['cover_url'] = $coverPublic;
    return $final;
}
// ---------- FIN HELPERS METADATOS ----------

function ns_time_range_matches($ranges, $h, $m) {
    $cur = $h * 60 + $m;
    foreach ($ranges as $r) {
        list($hs, $ms, $he, $me) = $r;
        $a = $hs * 60 + $ms;
        $b = $he * 60 + $me;
        if ($a === $b) continue;
        if ($a < $b) {
            if ($cur >= $a && $cur < $b) return true;
        } else {
            if ($cur >= $a || $cur < $b) return true;
        }
    }
    return false;
}

function ns_parse_hm($str, &$hh, &$mm) {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim((string)$str), $mm2)) return false;
    $hh = (int)$mm2[1]; $mm = (int)$mm2[2];
    return true;
}

function ns_get_ranges($sched) {
    $r = [];
    $hs = $ms = $he = $me = 0;
    if (!ns_parse_hm($sched['start'] ?? '00:00', $hs, $ms)) return [];
    if (!ns_parse_hm($sched['end']   ?? '00:00', $he, $me)) return [];
    $a = $hs * 60 + $ms;
    $b = $he * 60 + $me;
    if ($a === $b) { $me = $ms + 2; if ($me >= 60) { $me -= 60; $he = $hs + 1; if ($he >= 24) $he = 23; } }
    $a2 = $hs * 60 + $ms; $b2 = $he * 60 + $me;
    if ($b2 <= $a2) {
        $r[] = [$hs, $ms, 23, 59];
        $r[] = [0, 0, $he, $me];
    } else {
        $r[] = [$hs, $ms, $he, $me];
    }
    return $r;
}

function ns_expand_files($base_dir, $items, $tipo) {
    $out = [];
    if ($tipo === 'carpetas') {
        foreach ((array)$items as $f_name) {
            $safe = trim((string)$f_name);
            if ($safe === '' || $safe === '.' || $safe === '..') continue;
            $dir = $base_dir . '/' . $safe;
            if (!is_dir($dir)) continue;
            $raw = @scandir($dir);
            if (!is_array($raw)) continue;
            sort($raw);
            foreach ($raw as $fn) {
                if ($fn === '.' || $fn === '..') continue;
                $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                if ($ext !== 'mp3' && $ext !== 'm4a' && $ext !== 'ogg' && $ext !== 'wav' && $ext !== 'flac') continue;
                $fp = $dir . '/' . $fn;
                if (is_file($fp)) $out[] = $fp;
            }
        }
    } else {
        foreach ((array)$items as $rel) {
            $rel = trim((string)$rel);
            if ($rel === '') continue;
            $fp = $base_dir . '/' . $rel;
            if (is_file($fp)) $out[] = $fp;
        }
    }
    return $out;
}

function ns_load_files_for_playlist($app_data, $pl_name, $base_dir) {
    $pl = $app_data['playlists'][$pl_name] ?? null;
    if (!$pl) return [];
    $tipo = $pl['tipo'] ?? 'carpetas';
    $items = $pl['items'] ?? [];
    if ($tipo === 'carpetas' && $pl_name === 'general') {
        $srcs = [];
        foreach ((array)$items as $folder) {
            $safe = trim((string)$folder);
            if ($safe === '') continue;
            $srcs = array_merge($srcs, ns_expand_files($base_dir, [$safe], 'carpetas'));
        }
        return $srcs;
    }
    return ns_expand_files($base_dir, $items, $tipo);
}

function ns_pick_one($files, $pl_name, $avoid_last = []) {
    if (empty($files)) return null;
    $n = count($files);
    if ($n === 1) return $files[0];
    $avoid_map = [];
    foreach ((array)$avoid_last as $p) {
        if (is_string($p) && $p !== '') $avoid_map[$p] = true;
    }
    $candidates = [];
    for ($i = 0; $i < $n; $i++) {
        if (!isset($avoid_map[$files[$i]])) $candidates[] = $files[$i];
    }
    if (empty($candidates)) $candidates = $files;
    $idx = random_int(0, count($candidates) - 1);
    return $candidates[$idx];
}

// =====================================================================
// 🔁 SISTEMA SECUENCIAL (por tipo de playlist):
//   - tipo="archivos"  -> secuencia EXACTA #1 -> #2 -> ... -> #N -> #1 (loop)
//                         Respalda el orden que tú armaste a mano en el panel.
//                         Nunca hace shuffle.
//   - tipo="carpetas"  -> secuencia por PASOS (Paso 1 carpeta -> Paso 2 carpeta -> ...).
//                         Dentro de cada paso/carpeta, 1 canción ALEATORIA (anti-repeat
//                         si allow_repeat=false). Cuando acaba todos los pasos, vuelve a Paso 1.
//   - "general" con tipo carpetas -> mantiene backward: merge todas las carpetas +
//                         elección aleatoria (es el playlist rotable general, no es
//                         una presentación ordenada por pasos).
//
//   Estado guardado en .nextsong_state/seq_idx.json:
//     { "archivos_idx": { "Solobolerosprograma": 2, ... },
//       "carpetas_idx": { "MiProgramaCarpetas": 1, ... },
//       "items_len":    { "Solobolerosprograma": 13, ... },
//       "last_pl":      "Solobolerosprograma" }
//   Si items_len[pl] != actual count(items) => clamp idx.
//   Si la playlist cambia (entra un scheduled nuevo), se reset idx a 0 para que
//   empiece por el PRIMER paso / PRIMER archivo cada vez que arranca el bloque horario.
// =====================================================================
function ns_seq_state_path($NS_STATE_DIR): string {
    return rtrim($NS_STATE_DIR, '/\\') . '/seq_idx.json';
}

function ns_seq_state_load($NS_STATE_DIR): array {
    $f = ns_seq_state_path($NS_STATE_DIR);
    $st = ['archivos_idx' => [], 'carpetas_idx' => [], 'items_len' => [], 'seq_end_flag' => [], 'last_pl' => null];
    if (is_file($f)) {
        $raw = @json_decode(@file_get_contents($f), true);
        if (is_array($raw)) {
            foreach ($st as $k => $v) {
                if (isset($raw[$k]) && is_array($v)) {
                    if (is_array($raw[$k])) $st[$k] = $raw[$k];
                } elseif (isset($raw[$k])) {
                    $st[$k] = $raw[$k];
                }
            }
        }
    }
    return $st;
}

function ns_seq_state_save($NS_STATE_DIR, $st): void {
    $f = ns_seq_state_path($NS_STATE_DIR);
    @file_put_contents($f, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @chmod($f, 0664);
}

function ns_seq_clamp(&$st, $pl_name, $key_idx, $key_len, $actual_count): void {
    if ($actual_count <= 0) {
        $st[$key_idx][$pl_name] = 0;
        $st[$key_len][$pl_name] = 0;
        return;
    }
    $old_count = (int)($st[$key_len][$pl_name] ?? 0);
    $prev_idx   = (int)($st[$key_idx][$pl_name] ?? 0);
    // Si la cantidad de items cambió (usuario reorganizó playlist o añadió/borró) -> clamp
    if ($old_count !== $actual_count) {
        if ($prev_idx >= $actual_count) $prev_idx = 0;
    }
    if ($prev_idx < 0) $prev_idx = 0;
    if ($prev_idx >= $actual_count) $prev_idx = $prev_idx % $actual_count;
    $st[$key_idx][$pl_name] = $prev_idx;
    $st[$key_len][$pl_name] = $actual_count;
}

/**
 * Devuelve [$file, $pl_name_real] o [null, null]. $seq_st se modifica por referencia.
 */
function ns_pick_seq_playlist(&$seq_st, $app_data, $pl_name, $base_dir, $avoid_last = []) {
    $pl = $app_data['playlists'][$pl_name] ?? null;
    if (!$pl) return [null, null];
    $tipo = $pl['tipo'] ?? 'carpetas';
    $items = $pl['items'] ?? [];
    $allowRepeat = !empty($pl['allow_repeat']);
    $count_items = is_array($items) ? count($items) : 0;
    if ($count_items <= 0) return [null, null];

    // Playlist tipo "archivos" = secuencia EXACTA que el usuario armó arrastrando items.
    // Este modo se usa para "programas tipo RadioBOSS" (Presentacion #1, #2 cancion, #3 sello,
    // #4 cancion, #5 sello, etc). El usuario mete SELLOS REPETIDOS a propósito (mismo MP3 varias veces).
    // => IGNORAR anti-repeat en este modo AUTOMÁTICAMENTE (equivalente a allow_repeat=true),
    //    así el usuario NO tiene que recordar marcar la casilla. El orden siempre gana.
    if ($tipo === 'archivos') {
        $allowRepeat = true;
    }

    // Playlist "general" = backward compat: siempre aleatorio mezclando todas carpetas
    // (no es una secuencia de presentación, es la rotación general)
    if ($tipo === 'carpetas' && $pl_name === 'general') {
        $files = ns_load_files_for_playlist($app_data, $pl_name, $base_dir);
        $avoid = $allowRepeat ? [] : (is_array($avoid_last) ? array_slice($avoid_last, -8) : []);
        $picked = ns_pick_one($files, $pl_name, $avoid);
        return [$picked, $pl_name];
    }

    // Reset secuencia si la playlist actual es DIFERENTE a la última llamada
    // (cada vez que entra un scheduled nuevo, empieza desde el paso 1 / canción 1)
    $lastPl = array_key_exists('last_pl', $seq_st) ? $seq_st['last_pl'] : null;
    if (!is_string($lastPl) || $lastPl !== $pl_name) {
        $seq_st['archivos_idx'][$pl_name] = 0;
        $seq_st['carpetas_idx'][$pl_name] = 0;
        $seq_st['seq_end_flag'][$pl_name] = false;
    }
    $seq_st['last_pl'] = $pl_name;

    // Flag "secuencia ya acabada" (solo aplica a modo archivos secuencial scheduled).
    // Si está a true, NO devolvemos nada => pasa al siguiente try (general o siguiente scheduled).
    // ¡Así NUNCA se repite el loop de un bloque programado!
    if ($tipo === 'archivos' && !empty($seq_st['seq_end_flag']) && !empty($seq_st['seq_end_flag'][$pl_name])) {
        return [null, null];
    }

    if ($tipo === 'archivos') {
        // -----------------------------------------------------------------
        // TIPO ARCHIVOS (secuencial): respeta ORDEN EXACTO del usuario.
        // Ej: #1 Presentación 30min → #2 Alci Acosta → #3 Alfonso Ortiz...
        // Sin shuffle nunca. idx++ en cada llamada.
        // REGLA USUARIO: al ACABAR la ÚLTIMA canción => NO loop, pasar al general.
        // -----------------------------------------------------------------
        ns_seq_clamp($seq_st, $pl_name, 'archivos_idx', 'items_len', $count_items);
        $idx = (int)($seq_st['archivos_idx'][$pl_name] ?? 0);
        if ($idx >= $count_items) $idx = 0;
        $isLastItem = ($idx === ($count_items - 1));
        $rel = $items[$idx] ?? '';
        if (!is_string($rel) || $rel === '') return [null, null];
        $abs = rtrim($base_dir, '/\\') . '/' . $rel;
        // Si NO es el último: avanzar idx normal para la próxima llamada.
        // Si SÍ es el último: NO avanzamos idx (se queda en count), y marcamos FLAG.
        if (!$isLastItem) {
            $seq_st['archivos_idx'][$pl_name] = ($idx + 1);
        } else {
            $seq_st['archivos_idx'][$pl_name] = $count_items; // fuera de rango intencional
            $seq_st['seq_end_flag'][$pl_name] = true;         // proxima llamada => [null, null]
        }
        if (is_file($abs)) return [$abs, $pl_name];
        // Archivo movido/borrado: pasar al siguiente sin marcar error
        if (!$isLastItem) $seq_st['archivos_idx'][$pl_name] = ($idx + 1);
        return [null, null];
    }

    // tipo === 'carpetas' (no general)
    // -----------------------------------------------------------------
    // TIPO CARPETAS (secuencial por pasos): Paso1(carpeta A) → Paso2(carpeta B) → ...
    // En CADA paso/paso toma 1 canción ALEATORIA DENTRO de la carpeta actual.
    // Luego avanza al SIGUIENTE paso para la próxima llamada.
    // -----------------------------------------------------------------
    ns_seq_clamp($seq_st, $pl_name, 'carpetas_idx', 'items_len', $count_items);
    $idx = (int)($seq_st['carpetas_idx'][$pl_name] ?? 0);
    if ($idx >= $count_items) $idx = 0;
    $folder = trim((string)($items[$idx] ?? ''));
    $tried = 0;
    $picked = null;
    while ($tried < $count_items && $folder !== '' && $picked === null) {
        $files_in_step = ns_expand_files($base_dir, [$folder], 'carpetas');
        if (!empty($files_in_step)) {
            $avoid = $allowRepeat ? [] : (is_array($avoid_last) ? array_slice($avoid_last, -8) : []);
            $picked = ns_pick_one($files_in_step, $pl_name, $avoid);
        }
        if ($picked !== null) break;
        $idx = ($idx + 1) % $count_items;
        $folder = trim((string)($items[$idx] ?? ''));
        $tried++;
    }
    $next_idx = ($idx + 1) % $count_items;
    $seq_st['carpetas_idx'][$pl_name] = $next_idx;
    if ($picked !== null && is_file($picked)) return [$picked, $pl_name];
    // Fallback final: todo el playlist mezclado aleatorio
    $fallbackFiles = ns_load_files_for_playlist($app_data, $pl_name, $base_dir);
    if (empty($fallbackFiles)) return [null, null];
    $avoid = $allowRepeat ? [] : (is_array($avoid_last) ? array_slice($avoid_last, -8) : []);
    $p = ns_pick_one($fallbackFiles, $pl_name, $avoid);
    return [$p, $pl_name];
}

$selected_playlist = $app_data['default_playlist'] ?? 'general';
$selected_mode = 'default';

$schedule = $app_data['schedule'] ?? [];
$best_score = -1;
$best_mode = 'default';

foreach ($schedule as $sched) {
    $pl_name = $sched['playlist'] ?? null;
    if (!$pl_name || !isset($app_data['playlists'][$pl_name])) continue;
    $days = !empty($sched['days']) ? array_values(array_map('intval', $sched['days'])) : [1,2,3,4,5,6,7];
    if (!in_array($dow_no, $days, true)) continue;
    $ranges = ns_get_ranges($sched);
    if (!ns_time_range_matches($ranges, $h, $m)) continue;
    $mode = $sched['mode'] ?? 'inmediato';
    // Solo la llamada ESPECIAL req_mode=inmediato (skip/forzar entrada) filtra estrictamente.
    // La llamada NORMAL (request.dynamic siempre --mode=insertado) acepta AMBOS modos
    // (inmediato = el usuario quiere que entre a la hora exacta; insertado = al final de canción actual).
    if ($req_mode === 'inmediato' && $mode !== 'inmediato') continue;
    $hs = $ms = 0;
    ns_parse_hm($sched['start'] ?? '00:00', $hs, $ms);
    $dist = abs($hm - ($hs * 60 + $ms));
    $score = 100000 - $dist + ($mode === 'inmediato' ? 10 : 0);
    if ($score > $best_score) {
        $best_score = $score;
        $selected_playlist = $pl_name;
        $selected_mode = $mode;
    }
}

if ($req_mode === 'inmediato' && $selected_mode !== 'inmediato') {
    if ($probe_only) {
        if (PHP_SAPI !== 'cli') http_response_code(204);
        exit(1);
    }
    if (PHP_SAPI !== 'cli') http_response_code(204);
    echo "NO_INMEDIATO_ACTIVE\n";
    exit(1);
}
if ($probe_only) {
    $ok = true;
    if ($req_mode === 'inmediato' && $selected_mode !== 'inmediato') $ok = false;
    if ($req_mode === 'insertado' && !in_array($selected_mode, ['insertado','inmediato','default'], true)) $ok = false;
    if (PHP_SAPI !== 'cli') http_response_code($ok ? 200 : 204);
    echo $ok ? "1\n" : "0\n";
    exit($ok ? 0 : 1);
}

$try = [$selected_playlist];
if ($selected_playlist !== ($app_data['default_playlist'] ?? 'general')) {
    $try[] = $app_data['default_playlist'] ?? 'general';
}

$picked_file = null;
$picked_pl = null;
$last_path = $NS_LAST_PLAYED;
if ($req_mode === 'inmediato') $last_path = $NS_LAST_PLAYED_INM;
elseif ($req_mode === 'insertado') $last_path = $NS_LAST_PLAYED_INS;
$last = [];
if (file_exists($last_path)) {
    $lj = @json_decode(@file_get_contents($last_path), true);
    if (is_array($lj)) $last = array_values($lj);
}

// ======================================================================
// 🔁 SISTEMA DE INTERCALADORES (estilo Centova / Azurecast):
//    $app_data['intercalators'] = [
//        [ 'id'=>unico, 'folder'=>'Mantenimientos', 'type'=>'songs',   'value'=>3 ]   // cada 3 canciones
//        [ 'id'=>unico, 'folder'=>'Comerciales',   'type'=>'minutes', 'value'=>15 ]  // cada 15 min
//    ]
//   => NO hay que crear playlists de spots. Intercaladores = carpetas DIRECTAS, y el "general" es 100% musica.
// ======================================================================
$NS_PL_COUNTER_FILE = "{$NS_STATE_DIR}/pl_rotation_counter.json";
$rotation = ['music_since_last_spot' => 0, 'last_played_pl' => null];
if (file_exists($NS_PL_COUNTER_FILE)) {
    $rot_raw = @json_decode(@file_get_contents($NS_PL_COUNTER_FILE), true);
    if (is_array($rot_raw)) { $rotation = array_replace($rotation, $rot_raw); }
}
$NS_INTERCAL_STATE_FILE = "{$NS_STATE_DIR}/intercalators_state.json";
$intState = [];
if (file_exists($NS_INTERCAL_STATE_FILE)) {
    $tmp = @json_decode(@file_get_contents($NS_INTERCAL_STATE_FILE), true);
    if (is_array($tmp)) $intState = $tmp;
}
// Estado secuencial (índices por playlist tipo archivos / carpetas)
$seq_st = ns_seq_state_load($NS_STATE_DIR);
$def_pl_name = $app_data['default_playlist'] ?? 'general';
$spot_override_pl = null;
$spot_override_folder = null;
$spot_override_files = null;
$spot_override_block_id = null;   // si != null: estamos sacando una canción de un BLOQUE whole_folder_seq
$spot_override_block_is_last = false;  // true si es la última canción del bloque (para reset contadores después)
$now_ts = time();
$scheduled_pl = is_string($selected_playlist) && $selected_playlist !== '' ? $selected_playlist : $def_pl_name;
$is_default_scheduled = ($scheduled_pl === $def_pl_name);

// 🚨 PRIORIDAD SOBERANA PROGRAMACIÓN (schedule): si ENTRA un programa programado (no default)
//    y hay CUALQUIER bloque de intercalador whole_folder_seq ACTIVO EN MEDIO -> lo CANCELAMOS.
//    Los programas programados siempre suenan LIMPIOS (o solo intercalan apply_mode=always, que se evalúa después).
if (!$is_default_scheduled) {
    foreach ($intState as $__k => &$__v) {
        if (is_array($__v) && !empty($__v['block_active'])) {
            $__v['block_active'] = false;
            $__v['block_idx']    = 0;
            if (isset($__v['block_files'])) unset($__v['block_files']);
        }
    }
    unset($__v);
}

// ============================================================
// 📢 MOTOR PAUTA COMERCIAL / ANUNCIOS (modo suave: entra cuando
//    termina la canción actual, NO corta). ads[] = [{playlist,
//    hours[], minutes[], days[]}]: en cada slot (día, hora:min)
//    se reproduce UNA vez toda la playlist de spots, empezando en
//    el siguiente corte de canción. Aplica tanto en autodj como
//    durante bloques programados (la capa que esté sonando).
// ============================================================
$NS_ADS_STATE_FILE = "{$NS_STATE_DIR}/ads_state.json";
$ads_state = ['active' => null, 'consumed_epoch' => 0];
if (file_exists($NS_ADS_STATE_FILE)) {
    $tmp = @json_decode(@file_get_contents($NS_ADS_STATE_FILE), true);
    if (is_array($tmp)) $ads_state = array_replace($ads_state, $tmp);
}
$ads_state_dirty = false;
$ad_file = null;
$ad_pl = null;

// 1) Seguir un slot de anuncios ya activo (varias canciones seguidas)
$ads_active = (is_array($ads_state['active'] ?? null)) ? $ads_state['active'] : null;
if (is_array($ads_active) && is_array($ads_active['files'] ?? null) && !empty($ads_active['files'])) {
    $__af = $ads_active['files'];
    $__ai = max(0, (int)($ads_active['idx'] ?? 0));
    if ($__ai < count($__af)) {
        $ad_file = $__af[$__ai];
        $ad_pl   = (string)($ads_active['playlist'] ?? '');
        $ads_state['active']['idx'] = $__ai + 1;
        if ($__ai + 1 >= count($__af)) {
            $ads_state['consumed_epoch'] = max(0, (int)($ads_active['slot_epoch'] ?? 0));
            $ads_state['active'] = null;
        }
        $ads_state_dirty = true;
    } else {
        $ads_state['active'] = null;
        $ads_state_dirty = true;
    }
}

// 2) Buscar el slot pendiente MÁS RECIENTE (hora:min de HOY, día válido,
//    playlist existente) que aún no se haya consumido, y activarlo.
if ($ad_file === null && empty($ads_state['active'])) {
    $consumed_epoch = max(0, (int)($ads_state['consumed_epoch'] ?? 0));
    $best_slot = null;
    $today_ymd = $dt->format('Y-m-d');
    foreach (($app_data['ads'] ?? []) as $ad) {
        if (!is_array($ad)) continue;
        $pl = trim((string)($ad['playlist'] ?? ''));
        if ($pl === '' || !isset($app_data['playlists'][$pl])) continue;
        $days = !empty($ad['days']) ? array_values(array_map('intval', (array)$ad['days'])) : [1,2,3,4,5,6,7];
        if (!in_array($dow_no, $days, true)) continue;
        $hours = array_values(array_map('intval', (array)($ad['hours'] ?? [])));
        $minutes = array_values(array_map('intval', (array)($ad['minutes'] ?? [])));
        if (empty($hours) || empty($minutes)) continue;
        foreach ($hours as $hh) {
            foreach ($minutes as $mm) {
                $epoch = @mktime((int)$hh, (int)$mm, 0, (int)$dt->format('n'), (int)$dt->format('j'), (int)$dt->format('Y'));
                if ($epoch === false || $epoch <= 0) continue;
                if ($epoch > $now_ts || $epoch <= $consumed_epoch) continue;
                if ($best_slot === null || $epoch > $best_slot['epoch']) {
                    $best_slot = ['epoch' => $epoch, 'pl' => $pl, 'key' => $today_ymd . ' ' . sprintf('%02d:%02d', (int)$hh, (int)$mm)];
                }
            }
        }
    }
    if ($best_slot !== null) {
        $ad_files = ns_load_files_for_playlist($app_data, $best_slot['pl'], $base_dir);
        if (!empty($ad_files)) {
            sort($ad_files, SORT_NATURAL | SORT_FLAG_CASE);
            $ad_files = array_values($ad_files);
            $ad_file = $ad_files[0];
            $ad_pl   = $best_slot['pl'];
            $ads_state['active'] = [
                'playlist'   => $best_slot['pl'],
                'files'      => $ad_files,
                'idx'        => 1,
                'slot_epoch' => $best_slot['epoch'],
                'slot_key'   => $best_slot['key'],
            ];
        } else {
            // playlist sin archivos: no repetir intentos por el mismo slot
            $ads_state['consumed_epoch'] = $best_slot['epoch'];
        }
        $ads_state_dirty = true;
    }
}

// 3) Si toca anuncio, tiene prioridad total sobre música/intercaladores.
if ($ad_file !== null && is_file($ad_file)) {
    $picked_file = $ad_file;
    $picked_pl   = ($ad_pl !== '' && $ad_pl !== null) ? $ad_pl : 'pauta';
}
if ($ads_state_dirty) {
    @file_put_contents($NS_ADS_STATE_FILE, json_encode($ads_state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// Prioridad 1: intercaladores globales (carpetas directas, estilo Centova)
foreach (($app_data['intercalators'] ?? []) as $__int) {
    if (!is_array($__int)) continue;
    $_apply = ($__int['apply_mode'] ?? 'default_only') === 'always' ? 'always' : 'default_only';
    if ($_apply === 'default_only' && !$is_default_scheduled) continue; // <-- NUEVA CONDICIÓN: no intercalar en playlists programados (Top 10, especiales)
    $__folder = trim((string)($__int['folder'] ?? ''));
    if ($__folder === '') continue;
    $__id  = (string)($__int['id'] ?? ('_'.$__folder));
    $__type = ($__int['type'] ?? 'songs') === 'minutes' ? 'minutes' : 'songs';
    $__play = ($__int['play_mode'] ?? 'single_random') === 'whole_folder_seq' ? 'whole_folder_seq' : 'single_random';
    $__value = max(1, (int)($__int['value'] ?? ($__type === 'songs' ? 3 : 10)));
    if (!isset($intState[$__id]) || !is_array($intState[$__id])) {
        $intState[$__id] = [
            'songs_since' => 0,
            'last_played_at' => 0,
            'last_played_path' => '',
            'block_active' => false,
            'block_idx'    => 0,
        ];
    }
    $__st = &$intState[$__id];
    // 🚨 PRIMERO: Bloque whole_folder_seq ACTIVO en curso? -> tiene PRIORIDAD ABSOLUTA sobre trigger (seguimos sacando el bloque hasta que acabe)
    if (!empty($__st['block_active']) && is_array($__st['block_files'] ?? null) && !empty($__st['block_files'])) {
        $spot_override_folder   = $__folder;
        $spot_override_block_id = $__id;
        // calcular si es última para resetear después
        $_idx = (int)($__st['block_idx'] ?? 0);
        if ($_idx < 0) $_idx = 0;
        $count = count($__st['block_files']);
        if ($_idx >= $count) {
            // por seguridad, resetear bloque roto
            $__st['block_active'] = false;
            $__st['block_idx'] = 0;
            unset($__st['block_files']);
        } else {
            if ($_idx === ($count - 1)) $spot_override_block_is_last = true;
            $spot_override_files = [$__st['block_files'][$_idx]];
            // marcar para reset de la regla
            $__st['__just_triggered'] = $__id;
            // avanzar índice en memoria (se persiste al final si se usa)
            $__st['block_idx'] = $_idx + 1;
            if ($_idx + 1 >= $count) {
                $__st['block_active'] = false;
                $__st['block_idx']    = 0;
                unset($__st['block_files']);
            }
            break;
        }
    }
    $__files = ns_expand_files($base_dir, [$__folder], 'carpetas');
    if (empty($__files)) continue;
    $__triggered = false;
    if ($__type === 'songs') {
        $__st['songs_since'] = (int)($__st['songs_since'] ?? 0);
        if ($__st['songs_since'] >= $__value) $__triggered = true;
    } else {
        $__st['last_played_at'] = (int)($__st['last_played_at'] ?? 0);
        if ($__st['last_played_at'] > 0 && ($now_ts - $__st['last_played_at']) >= ($__value * 60)) {
            $__triggered = true;
        } elseif ($__st['last_played_at'] === 0) {
            $__st['last_played_at'] = $now_ts;
        }
    }
    if ($__triggered) {
        if ($__play === 'whole_folder_seq') {
            // 🎬 EMPEZAR BLOQUE NUEVO: orden alfabético natural (msg1.msg, msg2.mp3, msg10.mp3 => natsort ok)
            sort($__files, SORT_NATURAL | SORT_FLAG_CASE);
            $__st['block_active'] = true;
            $__st['block_files']  = array_values($__files);
            $__st['block_idx']    = 0;
            // sacar la 1ª canción inmediatamente
            $count = count($__st['block_files']);
            $spot_override_folder   = $__folder;
            $spot_override_block_id = $__id;
            if ($count === 1) $spot_override_block_is_last = true; // carpeta con 1 archivo = única y última a la vez
            $spot_override_files = [$__st['block_files'][0]];
            $__st['block_idx'] = 1;
            if (1 >= $count) {
                $__st['block_active'] = false;
                $__st['block_idx']    = 0;
                unset($__st['block_files']);
            }
        } else {
            // single_random (default, backward compat): 1 canción aleatoria
            $spot_override_folder = $__folder;
            $spot_override_files = $__files;
            $spot_override_pl = null;
        }
        // marcar estado para reset después de elegir
        $intState[$__id]['__just_triggered'] = $__id;
        break;
    }
}

// Prioridad 2 (backward compat): playlist repetible con repeat_every_n_songs (mantener antiguos users)
if ($spot_override_folder === null) {
    foreach (($app_data['playlists'] ?? []) as $__pl_n => $__pl_cfg) {
        if (!is_array($__pl_cfg)) continue;
        $__every = (int)($__pl_cfg['repeat_every_n_songs'] ?? 0);
        if ($__every <= 0) continue;
        $__allowRepeat = !empty($__pl_cfg['allow_repeat']);
        if (!$__allowRepeat) continue;
        $__files = ns_load_files_for_playlist($app_data, $__pl_n, $base_dir);
        if (empty($__files)) continue;
        $__musicCount = (int)($rotation['music_since_last_spot'] ?? 0);
        if ($__musicCount >= $__every) {
            $spot_override_pl = $__pl_n;
            break;
        }
    }
}

if ($spot_override_folder !== null && is_array($spot_override_files) && !empty($spot_override_files)) {
    if ($spot_override_block_id !== null) {
        // whole_folder_seq: spot_override_files ya trae exactamente 1 archivo (la actual del bloque)
        $p = $spot_override_files[0];
    } else {
        $p = ns_pick_one($spot_override_files, 'intercalator:'.$spot_override_folder, []);
    }
    if ($p && is_file($p)) {
        $picked_file = $p;
        $picked_pl = '@INTERCALATOR::'.$spot_override_folder;
    }
}

if ($picked_file === null && $spot_override_pl !== null) {
    // Spots backward compat (repeat_every_n_songs): usamos secuencia si es tipo archivos / carpetas
    list($p, $pPl) = ns_pick_seq_playlist($seq_st, $app_data, $spot_override_pl, $base_dir, []);
    if ($p && is_file($p)) {
        $picked_file = $p;
        $picked_pl = $spot_override_pl;
    }
}

if ($picked_file === null) {
    // Música NORMAL: secuencia estricta por tipo de playlist
    foreach ($try as $pl_name) {
        $plCfg = $app_data['playlists'][$pl_name] ?? [];
        $allowRepeat = !empty($plCfg['allow_repeat']);
        $avoidArr = $allowRepeat ? [] : array_slice($last, -8);
        list($p, $pPl) = ns_pick_seq_playlist($seq_st, $app_data, $pl_name, $base_dir, $avoidArr);
        if ($p && is_file($p)) {
            $picked_file = $p;
            $picked_pl = $pl_name;
            break;
        }
    }
}

// Actualizar contadores: intercaladores globales (carpetas) y backward-compat playlists repetibles.
if ($picked_pl !== null) {
    $was_intercalator = (is_string($picked_pl) && strpos($picked_pl, '@INTERCALATOR::') === 0);
    $is_block_mid = ($was_intercalator && $spot_override_block_id !== null && $spot_override_block_is_last === false);
    if ($was_intercalator) {
        // Reset songs_since (TODOS los type=songs) + last_played_at (TODOS) del que se disparó
        // → PERO: si estamos en MITAD de un bloque whole_folder_seq, NO reseteamos NADA. El bloque
        //    sigue en curso; resetear los contadores SOLO cuando saquemos la ÚLTIMA canción del bloque.
        if (!$is_block_mid) {
            $triggered_id = null;
            foreach ($intState as $__k => &$__v) {
                if (!empty($__v['__just_triggered'])) { $triggered_id = (string)$__v['__just_triggered']; unset($__v['__just_triggered']); }
            }
            unset($__v);
            foreach ($app_data['intercalators'] ?? [] as $__int) {
                if (!is_array($__int)) continue;
                $__folder = trim((string)($__int['folder'] ?? ''));
                $__id  = (string)($__int['id'] ?? ('_'.$__folder));
                $__type = ($__int['type'] ?? 'songs') === 'minutes' ? 'minutes' : 'songs';
                if ($__type === 'songs') {
                    if (isset($intState[$__id])) $intState[$__id]['songs_since'] = 0;
                }
                if ($triggered_id !== null && $__id === $triggered_id && $__type === 'minutes') {
                    if (isset($intState[$__id])) $intState[$__id]['last_played_at'] = $now_ts;
                }
            }
            // Reset global counter (backward compat)
            $rotation['music_since_last_spot'] = 0;
        } else {
            // 🧹 limpiar flag __just_triggered sin resets (para no arrastrar)
            foreach ($intState as $__k => &$__v) {
                if (is_array($__v) && isset($__v['__just_triggered'])) unset($__v['__just_triggered']);
            }
            unset($__v);
        }
    } else {
        $cfg = $app_data['playlists'][$picked_pl] ?? [];
        $isRepeatable = !empty($cfg['allow_repeat']);
        if ($isRepeatable) {
            $rotation['music_since_last_spot'] = 0;
        } else {
            if (count($try) > 0 && in_array($picked_pl, $try, true)) {
                $rotation['music_since_last_spot'] = ((int)($rotation['music_since_last_spot'] ?? 0)) + 1;
            }
            // Incrementar songs_since para TODOS los intercaladores globales tipo "songs"
            foreach (($app_data['intercalators'] ?? []) as $__int) {
                if (!is_array($__int)) continue;
                if (($__int['type'] ?? 'songs') !== 'songs') continue;
                $__folder = trim((string)($__int['folder'] ?? ''));
                if ($__folder === '') continue;
                $__id = (string)($__int['id'] ?? ('_'.$__folder));
                if (!isset($intState[$__id])) $intState[$__id] = ['songs_since'=>0,'last_played_at'=>0,'last_played_path'=>'','block_active'=>false,'block_idx'=>0];
                $intState[$__id]['songs_since'] = ((int)($intState[$__id]['songs_since'] ?? 0)) + 1;
            }
        }
    }
    $rotation['last_played_pl'] = $picked_pl;
    if (is_string($picked_file) && $picked_file !== '') {
        foreach ($intState as &$__v) {
            if (is_array($__v)) $__v['last_played_path'] = $picked_file;
        }
        unset($__v);
    }
    @file_put_contents($NS_PL_COUNTER_FILE, json_encode($rotation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @file_put_contents($NS_INTERCAL_STATE_FILE, json_encode($intState, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    // Guardar estado secuencial (índices archivos / carpetas por playlist)
    ns_seq_state_save($NS_STATE_DIR, $seq_st);
}

if (!$picked_file) {
    if ($req_mode !== 'inmediato') {
        $silence = "/usr/share/icecast2/web/silence.mp3";
        if (is_file($silence)) $picked_file = $silence;
    }
}

if ($picked_file) {
    array_unshift($last, $picked_file);
    $last = array_slice(array_values(array_unique($last)), 0, 16);
    @file_put_contents($last_path, json_encode($last, JSON_UNESCAPED_UNICODE));

    $meta = null;
    $meta_err = null;
    try {
        $meta = ns_metadata_fallback_chain($picked_file, $mount, $base_dir, $NS_ID3_CACHE_DIR, $NS_STATE_DIR, $NS_DEFAULT_COVER, $app_data, $radio);
    } catch (\Throwable $e) {
        $meta_err = $e->getMessage();
    }
    if (!is_array($meta)) {
        $fnParsed = ns_filename_parse($picked_file);
        $defCover = ns_get_fallback_cover_url($mount, $NS_STATE_DIR, $NS_DEFAULT_COVER, true);
        $meta = [
            'title'       => !empty($fnParsed['title']) ? $fnParsed['title'] : basename($picked_file),
            'artist'      => $fnParsed['artist'] ?? '',
            'album'       => '',
            'cover_url'   => $defCover,
            'filesize_mb' => is_file($picked_file) ? round(@filesize($picked_file) / 1048576, 2) : 0.0,
            'from'        => ['fn' => true, 'error_fallback' => true],
        ];
    }
    $real_pl_final = is_string($picked_pl) && $picked_pl !== '' ? $picked_pl : (is_string($selected_playlist) && $selected_playlist !== '' ? $selected_playlist : $def_pl_name);
    // El título que se guarda (página/panel/historial) debe coincidir con lo
    // que Liquidsoap envía al stream. Si el nombre del archivo trae " - " lo
    // mostramos completo (ej: "CRISTIAN CASTRO - ME ENAMORO") aunque los tags
    // ID3 estén mal. Sin " - " (o silence.mp3) se mantiene el comportamiento
    // anterior basado en metadata/ID3.
    $isSilenceFile = (basename((string)$picked_file) === 'silence.mp3');
    $fnStemTitle   = $isSilenceFile ? '' : preg_replace('/\.[^.]+$/', '', (string)basename((string)$picked_file));
    $hasDashName   = (!$isSilenceFile && is_string($fnStemTitle) && strpos($fnStemTitle, ' - ') !== false);
    $metaFallbackTitle = !empty($meta['title']) ? $meta['title'] : (ns_filename_parse($picked_file)['title'] ?? basename($picked_file));
    $now = [
        'mount'        => $mount,
        'radio_id'     => $radio['id'] ?? null,
        'title'        => $hasDashName ? (string)$fnStemTitle : $metaFallbackTitle,
        'artist'       => $hasDashName ? '' : ($meta['artist'] ?? ''),
        'album'        => $meta['album']  ?? '',
        'cover_url'    => !empty($meta['cover_url']) ? $meta['cover_url'] : ns_get_fallback_cover_url($mount, $NS_STATE_DIR, $NS_DEFAULT_COVER, true),
        'filesize_mb'  => $meta['filesize_mb'] ?? (is_file($picked_file) ? round(@filesize($picked_file) / 1048576, 2) : 0.0),
        'path'         => $picked_file,
        'playlist'     => $real_pl_final,
        'scheduled_pl' => is_string($scheduled_pl) && $scheduled_pl !== '' ? $scheduled_pl : $def_pl_name,
        'scheduled_mode' => is_string($selected_mode) && $selected_mode !== '' ? $selected_mode : 'default',
        'is_fallback'  => ($real_pl_final !== (is_string($scheduled_pl) && $scheduled_pl !== '' ? $scheduled_pl : $def_pl_name)),
        'mode'         => $selected_mode,
        'req_mode'     => $req_mode,
        'started_at'   => date('c'),
        'started_ts'   => time(),
        'from'         => $meta['from'] ?? ['fn' => true],
    ];
    if ($meta_err !== null && !isset($now['from']['error'])) {
        $now['from']['error'] = $meta_err;
    }
    @file_put_contents($NS_CURRENT_FILE, json_encode($now, JSON_UNESCAPED_UNICODE));

    $hist = [];
    if (file_exists($NS_HISTORY_FILE)) {
        $hj = @json_decode(@file_get_contents($NS_HISTORY_FILE), true);
        if (is_array($hj)) $hist = $hj;
    }
    $histEntry = $now;
    unset($histEntry['path']);

    $dup = false;
    $ns_norm = function($s) {
        $s = (string)$s;
        $s = preg_replace('/[\s\-_]+/u', ' ', $s);
        $s = preg_replace('/[^a-zA-Z0-9áéíóúüñ¿¡ÁÉÍÓÚÜÑ\s]/u', '', $s);
        $s = trim((string)$s);
        if ($s === '') return '';
        $low = function_exists('mb_strtolower') ? @mb_strtolower($s, 'UTF-8') : false;
        if ($low === false || $low === null) {
            $low = strtr($s,
                'ABCDEFGHIJKLMNOPQRSTUVWXYZÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝ',
                'abcdefghijklmnopqrstuvwxyzàáâãäåæçèéêëìíîïðñòóôõöøùúûüý'
            );
        }
        $s = $low;
        if (function_exists('iconv')) {
            $s2 = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$s);
            if ($s2 !== false && $s2 !== null && $s2 !== '') $s = (string)$s2;
        }
        return trim((string)$s);
    };
    $cur_sha = sha1((string)$picked_file);
    $cur_normTitle = $ns_norm($histEntry['title'] ?? '');
    $cur_normArtist = $ns_norm($histEntry['artist'] ?? '');
    $curTs = (int)($histEntry['started_ts'] ?? 0);
    foreach ($hist as $hIdx => $h) {
        if (!is_array($h)) continue;
        $hTs = (int)($h['started_ts'] ?? 0);
        $within = $hTs > 0 && $curTs > 0 && abs($curTs - $hTs) < 45;
        $hSha = '';
        if (!empty($h['path'])) $hSha = sha1((string)$h['path']);
        $shaEq = ($cur_sha !== '' && $hSha !== '' && $cur_sha === $hSha);
        $tEq = ($cur_normTitle !== '' && $cur_normTitle === $ns_norm($h['title'] ?? ''));
        $aEq = ($cur_normArtist !== '' && $cur_normArtist === $ns_norm($h['artist'] ?? ''));
        $bothBlankArtist = ($cur_normArtist === '' && $ns_norm($h['artist'] ?? '') === '');
        $titleMatch = $shaEq || ($tEq && ($aEq || $bothBlankArtist));
        if ($within && $titleMatch) { $dup = true; break; }
        if ($shaEq || ($tEq && $aEq)) { $dup = true; break; }
    }
    if (!$dup) {
        array_unshift($hist, $histEntry);
        $hist = array_slice($hist, 0, 5);
        @file_put_contents($NS_HISTORY_FILE, json_encode($hist, JSON_UNESCAPED_UNICODE));
    }
}

$ctr = file_exists($NS_COUNTER) ? (@json_decode(@file_get_contents($NS_COUNTER), true) ?: []) : [];
$ctr_key = $picked_pl ?: $selected_playlist;
$ctr[$ctr_key] = ($ctr[$ctr_key] ?? 0) + 1;
$ctr['_last_pl'] = $selected_playlist;
$ctr['_mode'] = $selected_mode;
$ctr['_req_mode'] = $req_mode;
$ctr['_local_time'] = $dt->format('c');
$ctr['_tz'] = $tz;
$ctr['_ts_utc'] = gmdate('c');
@file_put_contents($NS_COUNTER, json_encode($ctr, JSON_UNESCAPED_UNICODE));

if (!$picked_file) {
    if (PHP_SAPI !== 'cli') http_response_code(500);
    echo "ERR no_valid_file_found mount={$mount} mode={$req_mode}\n";
    exit(1);
}
echo $picked_file . "\n";
exit(0);
