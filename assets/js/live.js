function copyStreamUrl() {
    const el = document.getElementById('stream-url-text');
    if (!el) return;
    navigator.clipboard.writeText(el.innerText).then(() => {
        alert("✓ URL de Streaming copiada al portapapeles.");
    });
}

function updateServerClock() {
    try {
        const clockElements = document.querySelectorAll('.live-clock, #server-clock');
        if (clockElements.length === 0) return;
        const now = new Date();
        const tz = (appData && appData.timezone) ? appData.timezone : 'America/Costa_Rica';
        let timeStr = "";
        try {
            timeStr = now.toLocaleTimeString('es-CR', { timeZone: tz, hour12: false });
        } catch (e) {
            timeStr = now.toLocaleTimeString('es-CR', { hour12: false });
        }
        clockElements.forEach(el => { el.innerText = timeStr; });
    } catch(e) {
        console.warn("updateServerClock error:", e);
    }
}

(function __ensureClockInterval() {
    try {
        if (window.__live_clock_started) return;
        window.__live_clock_started = true;

        const start = () => {
            updateServerClock();
            setInterval(updateServerClock, 1000);
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }
    } catch(e) {
        console.warn("__ensureClockInterval error:", e);
    }
})();

// ======================================================================
// WIDGET: renderStorageWidget(data.storage, "storage-live-widget")
//         renderStorageWidget(data.storage, "storage-musicateca-widget")
// Dibuja: Barra de color, texto usado / cuota / libre y porcentaje.
// Colores: < 60% verde, 60-85% amarillo/naranja, > 85% rojo.
// ======================================================================
function __storageBarColor(pct, unlimited) {
    if (unlimited) return "linear-gradient(90deg,#0284c7,#38bdf8)";
    if (pct >= 85) return "linear-gradient(90deg,#dc2626,#f97316)";
    if (pct >= 60) return "linear-gradient(90deg,#d97706,#facc15)";
    return "linear-gradient(90deg,#22c55e,#84cc16)";
}
function renderStorageWidget(storage, rootId) {
    try {
        const root = document.getElementById(rootId);
        if (!root) return;
        if (!storage || typeof storage !== 'object') {
            root.innerHTML = '<div style="color:#f87171; font-size:0.85rem;">No hay datos de espacio disponibles.</div>';
            return;
        }
        const unlimited = !!storage.unlimited;
        const pct = Math.max(0, Math.min(100, Number(storage.percent || 0)));
        const pctStr = pct.toFixed(1).replace(/\.0$/, '') + '%';
        const barColor = __storageBarColor(pct, unlimited);

        if (rootId === 'storage-live-widget') {
            // Vista grande (inicio): 4 columnas Usado / Cuota / Libre / % barra
            const filas = `
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-bottom:12px;">
                <div style="background:#060b17; padding:10px 12px; border-radius:6px; border:1px solid #1e293b;">
                    <small style="color:#94a3b8; font-size:0.7rem; font-weight:bold; display:block;">USADO</small>
                    <strong style="color:#f1f5f9; font-size:1.05rem; font-family:monospace;">${storage.used_h || '0 B'}</strong>
                </div>
                <div style="background:#060b17; padding:10px 12px; border-radius:6px; border:1px solid #1e293b;">
                    <small style="color:#94a3b8; font-size:0.7rem; font-weight:bold; display:block;">ASIGNADO</small>
                    <strong style="color:${unlimited ? '#38bdf8' : '#f1f5f9'}; font-size:1.05rem; font-family:monospace;">${storage.quota_h || '∞'}</strong>
                </div>
                <div style="background:#060b17; padding:10px 12px; border-radius:6px; border:1px solid #1e293b;">
                    <small style="color:#94a3b8; font-size:0.7rem; font-weight:bold; display:block;">LIBRE</small>
                    <strong style="color:#4ade80; font-size:1.05rem; font-family:monospace;">${storage.free_h || '0 B'}</strong>
                </div>
                <div style="background:#060b17; padding:10px 12px; border-radius:6px; border:1px solid #1e293b;">
                    <small style="color:#94a3b8; font-size:0.7rem; font-weight:bold; display:block;">PORCENTAJE</small>
                    <strong style="color:#f1f5f9; font-size:1.05rem; font-family:monospace;">${pctStr}</strong>
                </div>
            </div>
            <div style="width:100%; background:#060b17; border-radius:6px; height:14px; overflow:hidden; border:1px solid #1e293b; margin-bottom:6px;">
                <div style="width:${pctStr}; height:100%; background:${barColor}; transition:width 0.5s ease;"></div>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; color:#94a3b8; flex-wrap:wrap; gap:6px;">
                ${unlimited ? `<span>Cuota: <strong style="color:#38bdf8;">∞ Ilimitado</strong> (según disco físico del servidor)</span>` : `<span>Límite: <strong style="color:#cbd5e1;">${storage.quota_h}</strong>. Si necesitas más espacio, contacta con el administrador.</span>`}
                ${(storage.disk_total_h && storage.disk_free_h) ? `<span>Disco servidor: <strong>${storage.disk_total_h}</strong> · Libre: <strong style="color:#4ade80;">${storage.disk_free_h}</strong></span>` : ''}
            </div>`;
            root.innerHTML = filas;
        } else {
            // Vista compacta (musicateca): 1 linea texto + barra
            const summaryHtml = `
            <strong style="color:#f1f5f9;">${storage.used_h || '0 B'}</strong>
            <span style="color:#64748b; margin:0 4px;">/</span>
            <strong style="color:${unlimited ? '#38bdf8' : '#f1f5f9'};">${storage.quota_h || '∞'}</strong>
            <span style="color:#94a3b8; margin-left:10px;">(libre: <span style="color:#4ade80;">${storage.free_h || '0 B'}</span> · <span style="font-weight:bold;">${pctStr}</span> usado)</span>
            `;
            const summaryEl = document.getElementById(rootId + '-summary');
            const barEl = document.getElementById(rootId + '-bar');
            if (summaryEl && barEl) {
                summaryEl.innerHTML = summaryHtml;
                barEl.style.width = pctStr;
                barEl.style.background = barColor;
            } else {
                // Fallback a prueba de balas: reescribir todo el contenedor con la estructura completa
                root.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:8px;">
                    <small style="color:#94a3b8; font-size:0.75rem; font-weight:bold; letter-spacing:0.5px;">ESPACIO ASIGNADO:</small>
                    <div style="font-size:0.85rem; color:var(--text-muted);">${summaryHtml}</div>
                </div>
                <div style="width:100%; background:#060b17; border-radius:6px; height:12px; overflow:hidden; border:1px solid #1e293b;">
                    <div style="width:${pctStr}; height:100%; background:${barColor}; transition:width 0.4s ease;"></div>
                </div>
                `;
            }
        }
    } catch(e) {
        console.warn("renderStorageWidget error:", e);
    }
}

function __renderStorageAllWidgets() {
    const storage = (typeof appData !== 'undefined' && appData && appData.storage) ? appData.storage : null;
    if (!storage) return;
    if (document.getElementById('storage-live-widget')) renderStorageWidget(storage, 'storage-live-widget');
    if (document.getElementById('storage-musicateca-widget')) renderStorageWidget(storage, 'storage-musicateca-widget');
}

(function __ensureStorageInterval() {
    try {
        if (window.__live_storage_started) return;
        window.__live_storage_started = true;

        const start = () => {
            __renderStorageAllWidgets();
            setInterval(__renderStorageAllWidgets, 10000);
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }
    } catch(e) {
        console.warn("__ensureStorageInterval error:", e);
    }
})();

async function updateLiveHistory() {
    if (typeof currentMount === 'undefined') return;
    try {
        const res = await fetch(`history_api.php?mount=${currentMount}`);
        const data = await res.json();

        const currentSongEl = document.getElementById('live-current-song');
        const playlistBadgeEl = document.getElementById('live-playlist-badge');
        const songTimeEl = document.getElementById('live-song-time');

        if (data.current) {
            if (currentSongEl) currentSongEl.innerText = data.current.title;
            if (playlistBadgeEl) playlistBadgeEl.innerText = `Playlist: ${data.current.playlist}`;
            if (songTimeEl) songTimeEl.innerText = `Inició: ${data.current.time}`;
        }
    } catch (e) {}
}

let isFetchingStats = false;

async function updateTopStats() {
    if (isFetchingStats) return; // Evita peticiones simultáneas cruzadas
    isFetchingStats = true;

    try {
        // Usar fuente de estado UNIFICADA desde autodj_api.php?action=status
        // (incluye icecast.online y icecast.listeners, con fallback si Icecast timeout)
        const res = await fetch(`autodj_api.php?action=status&_t=${Date.now()}`);
        const data = await res.json();
        if (!data || typeof data !== 'object') throw new Error("status vacío");

        const running = !!(data.running || false);
        const online = !!(data && data.icecast && data.icecast.online);
        const listeners = (data && data.icecast && typeof data.icecast.listeners === 'number') ? data.icecast.listeners : 0;

        // Actualizar appData global
        if (typeof appData !== 'undefined') {
            appData.running = running;
            appData.icecast = { online, listeners };
        }

        // 1. Estado Superior + 2. Conteo de Oyentes + 3. Sidebar inferior
        if (typeof applyRadioStatusUi === 'function') {
            applyRadioStatusUi({ running, icecast: { online, listeners } });
        } else {
            // Fallback retrocompat si no está core.js cargado (solo actualizar badge topbar)
            const badge = document.getElementById('top-status-badge') || document.querySelector('.badge-live, .badge-off');
            if (badge) {
                if (online) {
                    badge.className = 'badge-live';
                    badge.style.background = '#065f46';
                    badge.style.color = '#4ade80';
                    badge.innerText = '● EN VIVO';
                } else {
                    badge.className = 'badge-off';
                    badge.style.background = '#450a0a';
                    badge.style.color = '#f87171';
                    badge.innerText = '● DESCONECTADO';
                }
            }
            const listenersEl = document.getElementById('top-listeners');
            if (listenersEl) listenersEl.innerText = String(listeners);
        }
    } catch(e) {
        console.error("Error consultando stats (status unificado):", e);
    } finally {
        isFetchingStats = false;
    }
}

// Intervalo controlado: stats cada 3s (UI), history cada 4s
setInterval(updateTopStats, 3000);
setInterval(updateLiveHistory, 4000);
updateLiveHistory();

// Eliminar intervalo duplicado de 10s de core.js si live.js está presente para no sobrecargar
// (core.js lo agrega como seguro por si no hay live.js cargado; si llegamos aquí, live.js sí está)
// No lo quitamos: el intervalo 10s es benigno y garantiza alineación si por algún motivo updateTopStats salta.
updateTopStats();
