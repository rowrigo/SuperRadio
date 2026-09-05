<?php
$_stream_host = defined('STREAM_HOST') ? STREAM_HOST : 'stream.radioscr.com';
$_mount_view = $mount_clean ?? ($radio['mountpoint'] ?? 'milimonradio');
$_port_view  = !empty($dj_port) ? (int)$dj_port : 8000;
$_pass_view  = !empty($encoder_pass) ? $encoder_pass : '9876543210';
// Montaje DJ ENTRADA (Siempre /live en puerto dj_port - Puerto 8005 /live = ENTRADA DJ)
$_dj_mount  = 'live';
// URL PUBLICA de salida (Icecast 8000 /milimonradio - lo que oyen los oyentes)
$_stream_url = "https://{$_stream_host}/{$_mount_view}";

// MODO de radio actual (determinación de include panel.php ($radio)
$_vl_modo = !empty($radio['modo_radio']) && in_array($radio['modo_radio'], ['autodj','directa'], true) ? $radio['modo_radio'] : 'autodj';
$_vl_es_autodj = ($_vl_modo !== 'directa');
?>
<div id="view-live" class="view active">
    <!-- Header Cabina -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
        <div>
            <h3 style="margin:0 0 4px 0;">Cabina en Vivo</h3>
            <p style="color:var(--text-muted); margin:0; font-size:0.85rem;">Centro de control, monitoreo en tiempo real y transmisión.</p>
        </div>
        <div style="border:1px solid var(--border); border-radius:8px; padding:8px 16px; display:flex; align-items:center; gap:10px;">
            <div>
                <small style="color:var(--text-muted); display:block; font-size:0.7rem; font-weight:bold; letter-spacing:0.5px;">HORA OFICIAL:</small>
                <strong id="server-clock" class="live-clock" style="color:#38bdf8; font-size:1.1rem; font-family:monospace;">--:--:--</strong>
            </div>
        </div>
    </div>

<?php
$_np_init = (isset($GLOBALS['_storage_for_view']) && is_array($GLOBALS['_storage_for_view']) && isset($GLOBALS['_storage_for_view']['now_playing_initial']))
    ? $GLOBALS['_storage_for_view']['now_playing_initial']
    : null;
if (!$_np_init || !is_array($_np_init) || empty($_np_init)) {
    $_np_init = [
        'mount'             => $_mount_view,
        'mode'              => $_vl_modo,
        'stream_url'        => $_stream_url,
        'default_cover_url' => 'autodj_api.php?action=serve_default_cover&mount=' . rawurlencode($_mount_view),
        'default_cover_set' => false,
        'current'           => [
            'mount'  => $_mount_view,
            'title'  => $_vl_es_autodj ? 'AutoDJ — Cargando primera canción...' : 'Modo Directa — Señal lista para DJ en vivo',
            'artist' => '',
            'album'  => '',
            'cover_url' => 'autodj_api.php?action=serve_default_cover&mount=' . rawurlencode($_mount_view),
            'playlist' => 'general',
            'mode'     => $_vl_modo,
            'started_at' => '',
            'started_ts' => 0,
            'loading'  => true,
        ],
        'history' => [],
    ];
}
$_np_hist = array_values($_np_init['history'] ?? []);
function vl_fmt_time($_ts) {
    $_ts = (int)$_ts;
    if ($_ts <= 0) return '--:--:--';
    return @date('H:i:s', $_ts);
}
?>

<?php if ($_vl_es_autodj): ?>

    <!-- Reproductor Principal y Estado (SOLO Modo AUTODJ) -->
    <div class="card" style="margin-bottom:20px; border-left:4px solid #38bdf8;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
            <div>
                <small style="color:var(--text-muted); text-transform:uppercase; font-size:0.75rem; letter-spacing:1px; font-weight:bold;">SONANDO AHORA</small>
                <span id="live-playlist-badge" style="background:#0284c7; color:#fff; font-size:0.75rem; padding:2px 8px; border-radius:4px; margin-left:8px; font-weight:bold;">Playlist: general</span>
                <span id="live-scheduled-badge" style="display:none; font-size:0.75rem; padding:2px 8px; border-radius:4px; margin-left:6px; font-weight:bold;"></span>
                <h2 id="live-current-song" style="margin:8px 0 0 0; font-size:1.3rem; color:#fff;">AutoDJ Transmitiendo</h2>
            </div>
            <small id="live-song-time" style="color:var(--text-muted); font-family:monospace; font-size:0.85rem;">Inició: --:--:--</small>
        </div>
        <audio id="live-audio-player" controls style="width:100%; border-radius:30px; height:42px; outline:none;" src="<?= htmlspecialchars($_stream_url) ?>"></audio>
    </div>

    <!-- WIDGET ESPACIO EN DISCO (SOLO Modo AUTODJ) -->
    <div class="card p-4 mb-4" style="border:1px solid #1e293b; border-radius:8px;">
        <h4 style="margin:0 0 12px 0; color:#38bdf8; font-size:1.05rem; display:flex; align-items:center; gap:8px;">
            Espacio Almacenamiento
        </h4>
        <div id="storage-live-widget" style="width:100%;" data-storage-placeholder="Cargando espacio...">
            <div style="color:var(--text-muted); font-size:0.9rem;">Cargando espacio disponible...</div>
        </div>
    </div>

<?php else: ?>

    <!-- Reproductor Stream Pública (Modo DIRECTA - LIMPIO, sin textos técnicos) -->
    <div class="card" style="margin-bottom:20px; border-left:4px solid #38bdf8;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
            <div>
                <small style="color:var(--text-muted); text-transform:uppercase; font-size:0.75rem; letter-spacing:1px; font-weight:bold;">SEÑAL PUBLICA</small>
                <h2 id="live-current-song" style="margin:8px 0 0 0; font-size:1.3rem; color:#fff;">Radio en Vivo</h2>
            </div>
            <small id="live-song-time" style="color:var(--text-muted); font-family:monospace; font-size:0.85rem;">—</small>
        </div>
        <audio id="live-audio-player" controls style="width:100%; border-radius:30px; height:42px; outline:none;" src="<?= htmlspecialchars($_stream_url) ?>"></audio>
    </div>

<?php endif; ?>

<div class="card p-4 mb-4" style="border:1px solid #1e293b; border-radius:8px;">
    <h4 style="margin:0 0 16px 0; color:#38bdf8; font-size:1.05rem; display:flex; align-items:center; gap:8px;">
        Conexión en Vivo (BUTT / OBS / RadioDJ)
    </h4>
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:20px;">
        <div style="padding:14px; border-radius:8px; border:1px solid #1e293b;">
            <small style="color:#94a3b8; font-size:0.75rem; font-weight:bold; display:block; margin-bottom:6px;">SERVIDOR (HOST)</small>
            <strong style="color:#38bdf8; font-family:monospace; font-size:1rem;"><?= htmlspecialchars($_stream_host) ?></strong>
        </div>

        <div style="padding:14px; border-radius:8px; border:1px solid #1e293b;">
            <small style="color:#94a3b8; font-size:0.75rem; font-weight:bold; display:block; margin-bottom:6px;">PUERTO DJ EN VIVO</small>
            <div style="display:flex; align-items:baseline; gap:8px; flex-wrap:wrap;">
                <strong style="color:#4ade80; font-family:monospace; font-size:1.25rem;"><?= (int)$_port_view ?></strong>
                <span style="color:#fca5a5; font-family:monospace; font-size:0.8rem; padding:2px 8px; border:1px dashed #ef4444; border-radius:4px;">mount /<?= htmlspecialchars($_dj_mount) ?></span>
            </div>
        </div>

        <div style="padding:14px; border-radius:8px; border:1px solid #1e293b;">
            <small style="color:#94a3b8; font-size:0.75rem; font-weight:bold; display:block; margin-bottom:6px;">USUARIO</small>
            <strong style="color:#fff; font-family:monospace; font-size:1rem;">source</strong>
        </div>

        <div style="padding:14px; border-radius:8px; border:1px solid #1e293b;">
            <small style="color:#94a3b8; font-size:0.75rem; font-weight:bold; display:block; margin-bottom:6px;">CONTRASEÑA ENCODER</small>
            <strong style="color:#4ade80; font-family:monospace; font-size:1rem;"><?= htmlspecialchars($_pass_view) ?></strong>
        </div>

        <?php $_vl_bitrate = (int)($radio['bitrate'] ?? 128); ?>
        <div style="padding:14px; border-radius:8px; border:1px solid #1e293b;">
            <small style="color:#94a3b8; font-size:0.75rem; font-weight:bold; display:block; margin-bottom:8px; letter-spacing:0.3px;">Calidad</small>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <strong style="color:#60a5fa; font-family:monospace; font-size:1.25rem; letter-spacing:0.5px;"><?= (int)$_vl_bitrate ?> kbps</strong>
            </div>
        </div>
    </div>

    <!-- URL Pública (SIMPLIFICADA CLIENTE: sin texto técnico puertos) -->
    <div style="padding:12px 16px; border-radius:6px; border:1px solid #1e293b; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div style="font-size:0.85rem; color:#94a3b8;">
            <strong style="color:#e2e8f0;">Tu Señal Pública:</strong>
            <span style="color:#4ade80; font-family:monospace; margin-left:6px;"><?= htmlspecialchars($_stream_url) ?></span>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="button" class="btn btn-primary btn-sm" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($_stream_url) ?>'); alert('¡URL copiada al portapapeles!');">Copiar Stream</button>
            <a type="button" class="btn btn-success btn-sm" target="_blank" rel="noopener" href="<?= htmlspecialchars($_stream_url) ?>" style="text-decoration:none;">Abrir señal</a>
        </div>
    </div>

    <!-- PÁGINA PÚBLICA DEL PLAYER (NUEVO) -->
    <?php
    $_stream_host_pp = defined('STREAM_HOST') ? STREAM_HOST : 'stream.radioscr.com';
    $_pg_url_direct = 'https://' . $_stream_host_pp . '/radio_page.php?mount=' . rawurlencode($_mount_view);
    ?>
    <div style="padding:14px 16px; border-radius:10px; border:1px solid #064e3b; margin-top: 10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:12px; min-width:0; flex:1 1 420px;">
            <div style="min-width:0; flex:1 1 auto;">
                <div style="font-size:0.96rem; color:#a7f3d0; font-weight:700; margin-bottom:4px;">Tu Página Pública (Player Web)</div>
                <div style="font-size:0.8rem; color:#6ee7b7; margin-bottom:6px;">
                    Compártela con tus oyentes. Player responsive con logo, carátula, nombre artista, fondo y botón Whatsapp.
                </div>
                <div style="color:#4ade80; font-family:monospace; font-size:0.92rem; word-break:break-all;"><?= htmlspecialchars($_pg_url_direct) ?></div>
            </div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="button" class="btn btn-success btn-sm" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($_pg_url_direct) ?>').then(function(){alert('¡URL copiada al portapapeles!');});">Copiar</button>
            <a type="button" class="btn btn-primary btn-sm" target="_blank" rel="noopener" href="<?= htmlspecialchars($_pg_url_direct) ?>" style="text-decoration:none;">Abrir Previa</a>
            <button type="button" class="btn btn-info btn-sm" onclick="switchView('view-public-page');">Personalizar</button>
        </div>
    </div>
</div>





</div>

<script>
(function() {
    'use strict';
    const mount = (window.RADIO_CONFIG && window.RADIO_CONFIG.mount) ? String(window.RADIO_CONFIG.mount) : '';
    const $ = (sel) => document.querySelector(sel);
    const recentList = $('#recent-list');
    const historyUpdated = $('#np-history-updated');
    const liveCurrentSong = $('#live-current-song');
    const livePlaylistBadge = $('#live-playlist-badge');
    const liveScheduledBadge = $('#live-scheduled-badge');
    const liveSongTime = $('#live-song-time');
    const defaultCoverUrl = (window.RADIO_CONFIG && window.RADIO_CONFIG.now_playing_initial && window.RADIO_CONFIG.now_playing_initial.default_cover_url)
        ? String(window.RADIO_CONFIG.now_playing_initial.default_cover_url)
        : ('autodj_api.php?action=serve_default_cover&mount=' + encodeURIComponent(mount));

    function fmtTime(ts) {
        ts = parseInt(ts, 10) || 0;
        if (ts <= 0) return '--:--:--';
        const d = new Date(ts * 1000);
        const pad = (n) => String(n).padStart(2, '0');
        return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }
    function nowStr() {
        const d = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }
    function escapeHtml(txt) {
        if (txt === undefined || txt === null) return '';
        txt = String(txt);
        return txt.replace(/[&<>"']/g, (c) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[c]);
    }

    function applyCurrent(curr, schedInfo) {
        if (!curr || typeof curr !== 'object') return;
        schedInfo = schedInfo && typeof schedInfo === 'object' ? schedInfo : {};
        const title    = (curr.title    !== undefined && curr.title    !== null) ? String(curr.title)    : '';
        const pl       = (curr.playlist !== undefined && curr.playlist !== null) ? String(curr.playlist) : '';
        const ts       = parseInt(curr.started_ts, 10) || 0;
        const plSched  = (schedInfo.scheduled_pl_current !== undefined && schedInfo.scheduled_pl_current !== null) ? String(schedInfo.scheduled_pl_current) : '';
        const mismatch = !!schedInfo.mismatch;

        if (liveCurrentSong) { liveCurrentSong.textContent = title !== '' ? title : 'AutoDJ Transmitiendo'; }
        if (livePlaylistBadge && pl !== '') { livePlaylistBadge.textContent = 'Playlist: ' + pl; }
        else if (livePlaylistBadge && pl === '') { livePlaylistBadge.textContent = 'Playlist: general'; }
        if (liveSongTime && ts > 0) { liveSongTime.textContent = 'Inició: ' + fmtTime(ts); }
        else if (liveSongTime && ts <= 0) { liveSongTime.textContent = 'Inició: --:--:--'; }

        // Badge secundario: programado / en cola
        if (liveScheduledBadge) {
            if (plSched !== '') {
                if (mismatch && plSched !== pl) {
                    liveScheduledBadge.textContent = 'En cola: ' + plSched;
                    liveScheduledBadge.style.background = '#7c2d12';
                    liveScheduledBadge.style.color = '#fed7aa';
                    liveScheduledBadge.style.display = 'inline-block';
                } else if (plSched === pl) {
                    liveScheduledBadge.textContent = 'Programado';
                    liveScheduledBadge.style.background = '#064e3b';
                    liveScheduledBadge.style.color = '#a7f3d0';
                    liveScheduledBadge.style.display = 'inline-block';
                } else {
                    liveScheduledBadge.style.display = 'none';
                    liveScheduledBadge.textContent = '';
                }
            } else {
                liveScheduledBadge.style.display = 'none';
                liveScheduledBadge.textContent = '';
            }
        }
    }

    function applyHistory(history, initial) {
        if (!recentList) return;
        const list = Array.isArray(history) ? history : [];
        if (list.length === 0) {
            if (initial === true) return;
            recentList.innerHTML = '' +
                '<div role="listitem" style="display:flex; align-items:center; gap: 12px; padding: 12px 14px; border: 1px dashed #334155; border-radius: 8px; color: #94a3b8; font-size: 0.88rem;">' +
                    '<img src="'+escapeHtml(defaultCoverUrl)+'" alt="" onerror="this.onerror=null;this.src=\''+escapeHtml(defaultCoverUrl)+'\';" style="width: 44px; height: 44px; border-radius: 6px; object-fit: cover; flex: 0 0 auto;">' +
                    '<div style="flex: 1 1 auto;">' +
                        '<div style="font-weight: 600; color: #cbd5e1;">Aún no hay canciones reproducidas.</div>' +
                        '<div style="font-size: 0.78rem; color: #64748b; margin-top: 2px;">Las canciones sonadas aparecerán aquí automáticamente (historial de últimas 5).</div>' +
                    '</div>' +
                '</div>';
            return;
        }
        let html = '';
        for (let i = 0; i < list.length; i++) {
            const h = list[i]; if (!h || typeof h !== 'object') continue;
            const t = String(h.title || '');
            const a = String(h.artist || '');
            const c = String(h.cover_url || defaultCoverUrl);
            const ts = parseInt(h.started_ts, 10) || 0;
            html += '' +
                '<div role="listitem" style="display:flex; align-items:center; gap: 12px; padding: 10px 12px; border: 1px solid #1e293b; border-radius: 8px;">' +
                    '<img src="'+escapeHtml(c)+'" alt="" onerror="this.onerror=null;this.src=\''+escapeHtml(defaultCoverUrl)+'\';" style="width: 44px; height: 44px; border-radius: 6px; object-fit: cover; flex: 0 0 auto;">' +
                    '<div style="flex: 1 1 auto; min-width: 0;">' +
                        '<div style="color: #f1f5f9; font-weight: 600; font-size: 0.92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="'+escapeHtml(t)+'">'+escapeHtml(t)+'</div>' +
                        '<div style="color: #94a3b8; font-size: 0.78rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px;" title="'+escapeHtml(a)+'">'+
                            (a !== '' ? escapeHtml(a) : '<span style="color:#64748b;">Artista desconocido</span>') +
                        '</div>' +
                    '</div>' +
                    '<small style="color: var(--text-muted); font-family: monospace; font-size: 0.75rem; flex: 0 0 auto; padding-left: 8px;">'+fmtTime(ts)+'</small>' +
                '</div>';
        }
        recentList.innerHTML = html;
    }

    function applyPayload(payload) {
        if (!payload || typeof payload !== 'object') return;
        const realPlaying = (payload.playing && typeof payload.playing === 'object') ? payload.playing : (payload.current || null);
        const sched = {
            scheduled_pl_current: (payload.scheduled_pl_current !== undefined && payload.scheduled_pl_current !== null) ? String(payload.scheduled_pl_current) : '',
            mismatch: !!payload.scheduled_mismatch
        };
        applyCurrent(realPlaying, sched);
        applyHistory(payload.history || [], false);
        if (historyUpdated) { historyUpdated.textContent = 'Actualizado: ' + nowStr(); }
    }

    // Aplicar el payload inicial (server-side rendered, no espera polling)
    try {
        if (window.RADIO_CONFIG && window.RADIO_CONFIG.now_playing_initial) {
            applyPayload(window.RADIO_CONFIG.now_playing_initial);
        }
    } catch (e) { /* ignore */ }

    // Polling cada 8s (sin flooding, cache: no-store para no traer respuestas obsoletas)
    const POLL_MS = 3000;
    function poll() {
        if (!mount) return;
        fetch('autodj_api.php?action=get_now_playing&mount=' + encodeURIComponent(mount), {
            credentials: 'same-origin',
            cache: 'no-store',
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then((r) => {
            if (!r || !r.ok) { return null; }
            return r.json().catch(() => null);
        })
        .then((payload) => {
            if (payload) applyPayload(payload);
        })
        .catch(() => { /* ignore network errors */ })
        .finally(() => {
            setTimeout(poll, POLL_MS);
        });
    }
    setTimeout(poll, 1500);
})();
</script>
