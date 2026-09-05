// --- WRAPPER FETCH: agrega mount automáticamente a todas las llamadas al API ---
const _origFetch = window.fetch.bind(window);
window.fetch = function (url, opts = {}) {
    try {
        if (typeof url === 'string' && url.includes('autodj_api.php') && typeof currentMount !== 'undefined') {
            const mount = encodeURIComponent(currentMount);
            if (opts && opts.body instanceof FormData && !opts.body.has('mount')) {
                opts.body.append('mount', currentMount);
            } else {
                const sep = url.includes('?') ? '&' : '?';
                if (!url.includes('mount=') && !url.includes('mount%3D')) {
                    url = url + sep + 'mount=' + mount;
                }
            }
        }
    } catch (e) {}
    return _origFetch(url, opts);
};

// --- UTILIDAD UI: Actualiza estado (sidebar + topbar) desde un objeto {running,icecast:{online,listeners}} ---
function applyRadioStatusUi(st) {
    const running = !!(st && st.running);
    const online = !!(st && st.icecast && st.icecast.online);
    const listeners = (st && st.icecast && typeof st.icecast.listeners === 'number') ? st.icecast.listeners : 0;

    // 1) Sidebar inferior (autodj 24/7 o cabina vivo modo directa)
    const statusText = document.getElementById('side-autodj-status');
    const btnStart = document.getElementById('side-btn-start');
    const btnStop = document.getElementById('side-btn-stop');

    if (running) {
        if (statusText) {
            statusText.innerText = '● Transmitiendo';
            statusText.style.color = '#4ade80';
        }
        if (btnStart) btnStart.style.display = 'none';
        if (btnStop) { btnStop.style.display = 'flex'; btnStop.disabled = false; btnStop.style.opacity = '1'; }
    } else {
        if (statusText) {
            statusText.innerText = '● Detenido';
            statusText.style.color = '#ef4444';
        }
        if (btnStart) { btnStart.style.display = 'flex'; btnStart.disabled = false; btnStart.style.opacity = '1'; }
        if (btnStop) btnStop.style.display = 'none';
    }

    // 2) Badge superior topbar (EN VIVO / DESCONECTADO) y oyentes
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

function setSidebarBusy(texto) {
    const statusText = document.getElementById('side-autodj-status');
    const btnStart = document.getElementById('side-btn-start');
    const btnStop = document.getElementById('side-btn-stop');
    if (statusText) {
        statusText.innerText = texto || '● Procesando...';
        statusText.style.color = '#facc15';
    }
    if (btnStart) { btnStart.disabled = true; btnStart.style.opacity = '0.5'; }
    if (btnStop)  { btnStop.disabled = true; btnStop.style.opacity = '0.5'; }
}

// --- ESTADO GLOBAL ---
let appData = { 
    timezone: 'America/Costa_Rica',
    default_playlist: 'general',
    folders: [], 
    playlists: { general: { tipo: 'carpetas', items: [] } }, 
    schedule: [], 
    ads: [],
    time_voice: { enabled: false, folder: '' },
    running: false,
    icecast: { online: false, listeners: 0 }
};
let selectedFolder = null;
let curPl = 'general';
let currentActiveDay = 7;

// --- NAVEGACIÓN DE VISTAS ---
function switchView(viewId, btn) {
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    
    const target = document.getElementById(viewId);
    if (target) target.classList.add('active');
    if (btn) btn.classList.add('active');

    if (viewId === 'view-playlists' && typeof refreshPlaylistsUI === 'function') refreshPlaylistsUI();
    if (viewId === 'view-reloj' && typeof renderScheduleView === 'function') renderScheduleView();
    if (viewId === 'view-anuncios' && typeof renderAdsView === 'function') renderAdsView();
    if (viewId === 'view-ajustes' && typeof populateAjustesUI === 'function') populateAjustesUI();
    if (viewId === 'view-musicateca' && typeof renderMusicateca === 'function') {
        renderMusicateca();
        if (typeof __renderStorageAllWidgets === 'function') __renderStorageAllWidgets();
    }
}

// --- UTILIDADES DE ARCHIVO Y TIEMPO ---
function formatTime(totalSec) {
    if (!totalSec || isNaN(totalSec)) return "00m 00s";
    totalSec = Math.round(totalSec);
    const hours = Math.floor(totalSec / 3600);
    const mins = Math.floor((totalSec % 3600) / 60);
    const secs = totalSec % 60;
    if (hours > 0) return `${hours}h ${mins.toString().padStart(2, '0')}m ${secs.toString().padStart(2, '0')}s`;
    return `${mins}m ${secs.toString().padStart(2, '0')}s`;
}

function getFileInfo(relPath) {
    if (!relPath) return { name: 'Archivo desconocido', size: '', duration_str: '--:--', duration_sec: 0 };
    
    let folderName = "";
    let fileName = relPath;

    if (relPath.includes('/')) {
        const parts = relPath.split('/');
        folderName = parts[0];
        fileName = parts.slice(1).join('/');
    }

    const folder = (appData.folders || []).find(f => f.name === folderName);
    if (folder && Array.isArray(folder.files)) {
        const file = folder.files.find(f => f.name === fileName);
        if (file) return file;
    }

    for (const f of (appData.folders || [])) {
        if (Array.isArray(f.files)) {
            const found = f.files.find(x => x.name === fileName || x.name === relPath);
            if (found) return found;
        }
    }

    return { name: fileName, size: '', duration_str: '--:--', duration_sec: 0 };
}

// --- PERSISTENCIA EN SERVIDOR ---
async function persistToServer(showAlert = false) {
    try {
        const res = await fetch('autodj_api.php?action=save_data', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                timezone: appData.timezone,
                default_playlist: appData.default_playlist || 'general',
                playlists: appData.playlists, 
                schedule: appData.schedule,
                ads: appData.ads || [],
                time_voice: appData.time_voice || { enabled: false, folder: '' },
                intercalators: Array.isArray(appData.intercalators) ? appData.intercalators : []
            })
        });
        const json = await res.json();
        if (showAlert && json.success) {
            alert("✓ Cambios guardados correctamente en el servidor.\n\n" +
                (Array.isArray(json.warnings) && json.warnings.length ?
                    "⚠️ Avisos:\n- " + json.warnings.slice(0, 5).join("\n- ") +
                    (json.warnings.length > 5 ? `\n... y ${json.warnings.length - 5} más.` : '')
                    : ""));
        }
        if (showAlert && !json.success && json.error) alert("❌ Error al guardar:\n" + json.error);
        if (Array.isArray(json.warnings) && json.warnings.length) {
            console.warn("Warnings save_data:", json.warnings);
        }
        if (json.liq_check && typeof json.liq_check.ok === 'boolean' && !json.liq_check.ok) {
            alert("⚠️ El script Liquidsoap tiene un error (AutoDJ NO arrancará hasta solucionarlo):\n\n" +
                (json.liq_check.stdout || json.liq_check.stderr || "Error desconocido"));
        }
    } catch(e) {
        console.error("Error al persistir:", e);
    }
}

// --- CARGA DE DATOS INICIAL ---
async function loadData() {
    try {
        const res = await fetch(`autodj_api.php?action=load_all&_t=${Date.now()}`);
        const json = await res.json();
        appData.folders = json.folders || [];
        appData.playlists = (json.data && json.data.playlists) ? json.data.playlists : { general: { tipo: 'carpetas', items: [] } };
        appData.schedule = (json.data && json.data.schedule) ? json.data.schedule : [];
        appData.ads = (json.data && json.data.ads) ? json.data.ads : [];
        appData.timezone = (json.data && json.data.timezone) ? json.data.timezone : 'America/Costa_Rica';
        appData.default_playlist = (json.data && json.data.default_playlist) ? json.data.default_playlist : 'general';
        appData.time_voice = (json.data && json.data.time_voice) ? json.data.time_voice : { enabled: false, folder: '' };
        appData.intercalators = (json.data && Array.isArray(json.data.intercalators)) ? json.data.intercalators : [];
        appData.running = !!(json.running || false);
        appData.icecast = (json.icecast && typeof json.icecast === 'object') ? json.icecast : { online: false, listeners: 0 };
        appData.storage = (json.storage && typeof json.storage === 'object') ? json.storage : null;

        applyRadioStatusUi({ running: appData.running, icecast: appData.icecast });

        if (typeof renderMusicateca === 'function') renderMusicateca();
        if (typeof refreshPlaylistsUI === 'function') refreshPlaylistsUI();
        if (typeof renderScheduleView === 'function') renderScheduleView();
        if (typeof renderAdsView === 'function') renderAdsView();
        if (typeof populateAjustesUI === 'function') populateAjustesUI();
        if (typeof updateServerClock === 'function') updateServerClock();
        if (typeof __renderStorageAllWidgets === 'function') __renderStorageAllWidgets();
    } catch(e) {
        console.error("Error al cargar datos globales:", e);
    }
}

// --- CONTROL AUTO DJ: ÚNICA implementación ---
async function toggleAutoDJ(cmd) {
    const cmd_safe = String(cmd || 'restart').toLowerCase();
    if (!['start', 'stop', 'restart'].includes(cmd_safe)) return;

    setSidebarBusy(
        cmd_safe === 'stop' ? '● Deteniendo...' :
        cmd_safe === 'start' ? '● Iniciando...' :
        '● Reiniciando...'
    );

    const labels = { start: 'Iniciar Emisión', stop: 'Detener AutoDJ', restart: 'Reiniciar AutoDJ' };
    try {
        const fd = new FormData();
        fd.append('action', 'toggle');
        fd.append('cmd', cmd_safe);
        const res = await fetch(`autodj_api.php?_t=${Date.now()}`, { method: 'POST', body: fd });
        const data = await res.json();

        if (data && typeof data === 'object') {
            applyRadioStatusUi({ running: !!(data.running || false), icecast: (data.icecast && typeof data.icecast === 'object') ? data.icecast : { online: false, listeners: 0 } });
        }

        if (!data || !data.success) {
            const err = (data && data.error) ? data.error :
                (data && data.liq_check && data.liq_check.stdout) ? data.liq_check.stdout :
                (data && data.start_info && data.start_info.info && data.start_info.info.stderr_last_lines) ?
                    data.start_info.info.stderr_last_lines.join("\n") :
                    `No se pudo completar la operación.`;
            alert("❌ Error al " + labels[cmd_safe] + ":\n\n" + String(err).slice(0, 1500));
        } else if (data.success && data.message) {
            // éxito silencioso; si no hay mensaje detallado, no alertar
        }

        // Si la acción fue restart/start, dar 1s más para que Icecast reporte mount y refrescar status una vez más
        if (cmd_safe !== 'stop') {
            setTimeout(async () => {
                try {
                    const r2 = await fetch(`autodj_api.php?action=status&_t=${Date.now()}`);
                    const j2 = await r2.json();
                    if (j2) applyRadioStatusUi({ running: !!(j2.running || false), icecast: (j2.icecast && typeof j2.icecast === 'object') ? j2.icecast : { online: false, listeners: 0 } });
                } catch (e) {}
            }, 2000);
        } else {
            setTimeout(loadData, 400);
        }
    } catch(e) {
        console.error("Error al controlar AutoDJ:", e);
        alert("❌ Hubo un error de conexión al " + labels[cmd_safe] + ".");
        setTimeout(loadData, 600);
    }
}

// Polling de seguridad: solo en caso de que live.js no esté cargado en esa vista
// (ej: otras páginas sin cabina-en-vivo), refrescar status cada 15s para alinear sidebar/topbar.
// live.js ya tiene polling cada 3s y no lo añadimos aquí para no sobrecargar.
(function __softPoll() {
    let cnt = 0;
    setInterval(async () => {
        cnt++;
        try {
            // Si live.js está presente, delegamos a su polling cada 3s. Salimos 2 de cada 3 llamadas para no sobrecargar.
            if (typeof updateTopStats === 'function' && cnt % 5 !== 0) return;
            const r = await fetch(`autodj_api.php?action=status&_t=${Date.now()}`);
            const j = await r.json();
            if (j && typeof j === 'object' && typeof applyRadioStatusUi === 'function') {
                applyRadioStatusUi({
                    running: !!(j.running || false),
                    icecast: (j.icecast && typeof j.icecast === 'object') ? j.icecast : { online: false, listeners: 0 }
                });
            }
        } catch (e) {}
    }, 15000);
})();

loadData();
