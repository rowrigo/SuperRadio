// --- ESTADO GLOBAL (SÓLO INICIALIZAR SI CORE.JS NO LO HA HECHO ANTES) ---
// panel.php CARGA core.js PRIMERO y luego main.js. SI REDECLARAMOS let appData -> SyntaxError "already declared".
window.appData = window.appData || { 
    timezone: 'America/Costa_Rica',
    default_playlist: 'general',
    folders: [], 
    playlists: { general: { tipo: 'carpetas', items: [] } }, 
    schedule: [], 
    ads: [],
    time_voice: { enabled: false, folder: '' },
    intercalators: [],
    running: false,
    icecast: { online: false, listeners: 0 }
};
if (typeof appData === 'undefined') { appData = window.appData; }
window.selectedFolder = window.selectedFolder ?? null;
if (typeof selectedFolder === 'undefined') { selectedFolder = window.selectedFolder; }
window.curPl = window.curPl ?? 'general';
if (typeof curPl === 'undefined') { curPl = window.curPl; }
window.currentActiveDay = window.currentActiveDay ?? 7;
if (typeof currentActiveDay === 'undefined') { currentActiveDay = window.currentActiveDay; }

// --- NAVEGACIÓN ENTRE VISTAS (SÓLO SI CORE.JS NO LA HA DEFINIDO) ---
window.switchView = window.switchView || function switchView(viewId, btn) {
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

    const target = document.getElementById(viewId);
    if (target) target.classList.add('active');
    if (btn) btn.classList.add('active');

    if (viewId === 'view-playlists' && typeof refreshPlaylistsUI === 'function') refreshPlaylistsUI();
    if (viewId === 'view-reloj' && typeof renderScheduleView === 'function') renderScheduleView();
    if (viewId === 'view-anuncios' && typeof renderAdsView === 'function') renderAdsView();
    if (viewId === 'view-ajustes' && typeof populateAjustesUI === 'function') populateAjustesUI();
    if (viewId === 'view-musicateca') { 
        if (typeof renderMusicateca === 'function') renderMusicateca();
        if (typeof __renderStorageAllWidgets === 'function') __renderStorageAllWidgets();
    }
};
if (typeof switchView === 'undefined') { switchView = window.switchView; }

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
    const parts = relPath.split('/');
    if (parts.length < 2) return { name: relPath, size: '', duration_str: '--:--', duration_sec: 0 };
    const folder = appData.folders.find(f => f.name === parts[0]);
    if (!folder || !folder.files) return { name: parts[1], size: '', duration_str: '--:--', duration_sec: 0 };
    const file = folder.files.find(f => f.name === parts[1]);
    return file || { name: parts[1], size: '', duration_str: '--:--', duration_sec: 0 };
}

// --- PERSISTENCIA EN SERVIDOR (AUTO-SAVE) ---
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
            alert("✓ Cambios guardados correctamente en el servidor.");
        }
    } catch(e) {
        console.error("Error al guardar:", e);
    }
}

// --- CARGA INICIAL (main.js: datos específicos musicateca/playlists + delega UI status a core.js applyRadioStatusUi) ---
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

        // Actualizar sidebar/topbar usando helper unificado de core.js
        if (typeof applyRadioStatusUi === 'function') {
            applyRadioStatusUi({ running: appData.running, icecast: appData.icecast });
        } else {
            // Fallback retrocompat
            const statusText = document.getElementById('side-autodj-status');
            const btnStart = document.getElementById('side-btn-start');
            const btnStop = document.getElementById('side-btn-stop');
            if (appData.running) {
                if (statusText) { statusText.innerText = '● Transmitiendo 24/7'; statusText.style.color = '#4ade80'; }
                if (btnStart) btnStart.innerText = '🔄 Reiniciar AutoDJ';
                if (btnStop) btnStop.style.display = 'block';
            } else {
                if (statusText) { statusText.innerText = '● Detenido'; statusText.style.color = '#ef4444'; }
                if (btnStart) btnStart.innerText = '▶ Iniciar Emisión';
                if (btnStop) btnStop.style.display = 'none';
            }
        }

        renderMusicateca();
        if (selectedFolder) {
            const fObj = appData.folders.find(f => f.name === selectedFolder);
            if (fObj) renderFiles(fObj);
        }
        if (typeof refreshPlaylistsUI === 'function') refreshPlaylistsUI();
        if (typeof renderScheduleView === 'function') renderScheduleView();
        if (typeof renderAdsView === 'function') renderAdsView();
        if (typeof populateAjustesUI === 'function') populateAjustesUI();
        if (typeof updateServerClock === 'function') updateServerClock();
        if (typeof __renderStorageAllWidgets === 'function') __renderStorageAllWidgets();
    } catch(e) {
        console.error("Error al cargar datos:", e);
    }
}

// --- MUSICATECA ---
function renderMusicateca() {
    const fList = document.getElementById('folders-list');
    if (!fList) return;
    fList.innerHTML = '';
    appData.folders.forEach(f => {
        const li = document.createElement('li');
        li.className = `folder-row ${selectedFolder === f.name ? 'active' : ''}`;
        li.innerHTML = `<span>📁 ${f.name}</span><span style="font-size:0.75rem;">${f.count} MP3</span>`;
        li.onclick = () => { selectedFolder = f.name; renderMusicateca(); renderFiles(f); };
        fList.appendChild(li);
    });
    if (typeof __renderStorageAllWidgets === 'function') __renderStorageAllWidgets();
}

function renderFiles(fObj) {
    const titleEl = document.getElementById('current-folder-title');
    const badgeEl = document.getElementById('folder-count-badge');
    const flList = document.getElementById('files-list');

    if (titleEl) titleEl.innerText = `📁 ${fObj.name}`;
    if (badgeEl) badgeEl.innerText = `${fObj.count} archivos`;
    if (!flList) return;

    flList.innerHTML = '';
    if(fObj.files && fObj.files.length > 0) {
        fObj.files.forEach(f => {
            flList.innerHTML += `
            <li class="file-row">
                <div>
                    <span>🎵 ${f.name}</span>
                    <small style="color:var(--text-muted); margin-left:8px;">[${f.duration_str}] (${f.size})</small>
                </div>
                <button class="btn btn-danger btn-sm" onclick="deleteFile('${fObj.name}', '${f.name}')">X</button>
            </li>`;
        });
    } else {
        flList.innerHTML = '<li style="color:var(--text-muted); padding:10px;">Carpeta vacía.</li>';
    }
}

async function createFolder() {
    const input = document.getElementById('new-folder-input');
    if (!input || !input.value) return;
    const form = new FormData();
    form.append('action', 'create_folder');
    form.append('folder_name', input.value);
    await fetch('autodj_api.php', { method: 'POST', body: form });
    selectedFolder = input.value;
    input.value = '';
    await loadData();
}

async function deleteFile(folder, file) {
    if(!confirm(`¿Eliminar ${file}?`)) return;
    const form = new FormData();
    form.append('action', 'delete_file');
    form.append('folder', folder);
    form.append('file', file);
    await fetch('autodj_api.php', { method: 'POST', body: form });
    await loadData();
}

function uploadMultipleFiles() {
    if (!selectedFolder) return alert("Selecciona una carpeta.");
    const input = document.getElementById('multi-file-input');
    if (!input || input.files.length === 0) return;

    const titleArea = document.getElementById('current-folder-title');
    const fileList = document.getElementById('files-list');
    if (titleArea) titleArea.innerHTML = `Subiendo ${input.files.length} archivo(s)... <span id="upload-percent" style="color: #4ade80;">0%</span>`;
    
    if (fileList) {
        fileList.innerHTML = `
        <li style="padding: 16px; background: #121c30; border: 1px solid #1e293b; border-radius: 8px; margin-bottom: 10px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.85rem; color: #38bdf8; font-weight: bold;">
                <span>Progreso de transferencia:</span>
            </div>
            <div style="width: 100%; background: #0b132b; border-radius: 6px; height: 16px; overflow: hidden; border: 1px solid #334155;">
                <div id="upload-bar" style="width: 0%; height: 100%; background: linear-gradient(90deg, #0284c7, #4ade80); transition: width 0.3s ease;"></div>
            </div>
        </li>`;
    }

    const form = new FormData();
    form.append('action', 'upload_multiple');
    form.append('folder', selectedFolder);
    for (let i = 0; i < input.files.length; i++) form.append('files[]', input.files[i]);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'autodj_api.php', true);
    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            const bar = document.getElementById('upload-bar');
            const pctText = document.getElementById('upload-percent');
            if (bar) bar.style.width = pct + '%';
            if (pctText) pctText.innerText = pct + '%';
        }
    };
    xhr.onload = async function() { input.value = ''; await loadData(); };
    xhr.onerror = function() { alert("Error de red"); input.value = ''; loadData(); };
    xhr.send(form);
}

// --- CONSTRUCTOR DE PLAYLISTS ---
function refreshPlaylistsUI() {
    const selPl = document.getElementById('sel-playlist');
    if (!selPl) return;
    selPl.innerHTML = '';
    
    Object.keys(appData.playlists).forEach(p => {
        const plInfo = appData.playlists[p] || {};
        const pType = plInfo.tipo === 'archivos' ? '🎵' : '📁';
        selPl.innerHTML += `<option value="${p}" ${p === curPl ? 'selected' : ''}>${pType} ${p}</option>`;
    });

    const selFld = document.getElementById('sel-folder');
    const selFldFiles = document.getElementById('sel-folder-files');
    if (selFld && selFldFiles) {
        selFld.innerHTML = '<option value="">Elegir carpeta...</option>';
        selFldFiles.innerHTML = '<option value="">Filtrar carpeta...</option>';
        appData.folders.forEach(f => {
            selFld.innerHTML += `<option value="${f.name}">📁 ${f.name} (${f.count} temas)</option>`;
            selFldFiles.innerHTML += `<option value="${f.name}">📁 ${f.name}</option>`;
        });
    }

    loadEditor();
}

async function crearPlaylist() {
    const input = document.getElementById('new-pl-name');
    const typeSelect = document.getElementById('new-pl-type');
    if (!input || !typeSelect) return;
    
    const type = typeSelect.value;
    const name = input.value.replace(/[^a-zA-Z0-9_]/g, '');
    if(!name || appData.playlists[name]) return;
    
    appData.playlists[name] = { tipo: type, items: [] };
    curPl = name;
    input.value = '';
    refreshPlaylistsUI();
    await persistToServer();
}

async function eliminarPlaylistActual() {
    if (curPl === 'general') return alert("No puedes eliminar el playlist general.");
    if (!confirm(`¿Eliminar el playlist "${curPl}"?`)) return;
    delete appData.playlists[curPl];
    appData.schedule = appData.schedule.filter(s => s.playlist !== curPl);
    curPl = 'general';
    refreshPlaylistsUI();
    await persistToServer();
}

function loadEditor() {
    const selPl = document.getElementById('sel-playlist');
    if (!selPl) return;
    curPl = selPl.value || 'general';
    const plData = appData.playlists[curPl] || { tipo: 'carpetas', items: [] };

    const edTitle = document.getElementById('ed-title');
    if (edTitle) edTitle.innerText = `Editando: ${curPl}`;
    
    const badge = document.getElementById('ed-type-badge');
    const panelFolder = document.getElementById('panel-add-folder');
    const panelFile = document.getElementById('panel-add-file');
    const btnDel = document.getElementById('btn-del-playlist');
    const durBox = document.getElementById('ed-duration-box');

    if (btnDel) btnDel.style.display = (curPl === 'general') ? 'none' : 'inline-block';

    if (plData.tipo === 'archivos') {
        if (badge) { badge.innerText = 'Modo: Archivos (Secuencia exacta)'; badge.style.background = '#8b5cf6'; }
        if (panelFolder) panelFolder.style.display = 'none';
        if (panelFile) panelFile.style.display = 'flex';
        if (durBox) durBox.style.display = 'flex';
        updateFilesDropdown();
    } else {
        if (badge) { badge.innerText = 'Modo: Carpetas (Rotación continua)'; badge.style.background = '#0284c7'; }
        if (panelFolder) panelFolder.style.display = 'flex';
        if (panelFile) panelFile.style.display = 'none';
        if (durBox) durBox.style.display = 'none';
    }

    const list = document.getElementById('ed-list');
    if (!list) return;
    list.innerHTML = '';
    
    let totalSec = 0;

    if (!plData.items || plData.items.length === 0) {
        list.innerHTML = '<div style="color:var(--text-muted); padding:10px;">Este playlist no tiene elementos asignados.</div>';
        if (plData.tipo === 'archivos') {
            const totDur = document.getElementById('ed-total-duration');
            const totCnt = document.getElementById('ed-total-count');
            if (totDur) totDur.innerText = '00m 00s';
            if (totCnt) totCnt.innerText = '(0 temas)';
        }
        return;
    }

    plData.items.forEach((item, idx) => {
        if (plData.tipo === 'archivos') {
            const info = getFileInfo(item);
            totalSec += (info.duration_sec || 0);

            list.innerHTML += `
            <div class="list-row">
                <div>
                    <strong style="color:#38bdf8; margin-right:8px;">#${idx+1}</strong>
                    <span>🎵 ${item}</span>
                    <small style="color:#4ade80; margin-left:8px; font-family:monospace; font-weight:bold;">[${info.duration_str || '--:--'}]</small>
                </div>
                <div>
                    <button class="btn btn-sm" onclick="moveItem(${idx}, -1)" ${idx === 0 ? 'disabled' : ''}>▲</button>
                    <button class="btn btn-sm" onclick="moveItem(${idx}, 1)" ${idx === plData.items.length - 1 ? 'disabled' : ''}>▼</button>
                    <button class="btn btn-danger btn-sm" onclick="remItem(${idx})">X</button>
                </div>
            </div>`;
        } else {
            list.innerHTML += `
            <div class="list-row">
                <span><strong style="color:#38bdf8; margin-right:10px;">Paso ${idx+1}:</strong> 📁 ${item}</span>
                <div>
                    <button class="btn btn-sm" onclick="moveItem(${idx}, -1)" ${idx === 0 ? 'disabled' : ''}>▲</button>
                    <button class="btn btn-sm" onclick="moveItem(${idx}, 1)" ${idx === plData.items.length - 1 ? 'disabled' : ''}>▼</button>
                    <button class="btn btn-danger btn-sm" onclick="remItem(${idx})">X</button>
                </div>
            </div>`;
        }
    });

    if (plData.tipo === 'archivos') {
        const totDur = document.getElementById('ed-total-duration');
        const totCnt = document.getElementById('ed-total-count');
        if (totDur) totDur.innerText = formatTime(totalSec);
        if (totCnt) totCnt.innerText = `(${plData.items.length} canciones)`;
    }
}

function updateFilesDropdown() {
    const selFolderFiles = document.getElementById('sel-folder-files');
    const selFile = document.getElementById('sel-specific-file');
    if (!selFolderFiles || !selFile) return;

    const folderName = selFolderFiles.value;
    selFile.innerHTML = '<option value="">Selecciona canción...</option>';

    const folderObj = appData.folders.find(f => f.name === folderName);
    if (folderObj && folderObj.files) {
        folderObj.files.forEach(file => {
            selFile.innerHTML += `<option value="${folderObj.name}/${file.name}">${file.name} [${file.duration_str}]</option>`;
        });
    }
}

async function addFolder() {
    const selFolder = document.getElementById('sel-folder');
    if (selFolder && selFolder.value) {
        if (!appData.playlists[curPl].items) appData.playlists[curPl].items = [];
        appData.playlists[curPl].items.push(selFolder.value);
        loadEditor();
        await persistToServer();
    }
}

async function addSpecificFile() {
    const selFile = document.getElementById('sel-specific-file');
    if (selFile && selFile.value) {
        if (!appData.playlists[curPl].items) appData.playlists[curPl].items = [];
        appData.playlists[curPl].items.push(selFile.value);
        loadEditor();
        await persistToServer();
    }
}

async function remItem(idx) {
    if (appData.playlists[curPl] && appData.playlists[curPl].items) {
        appData.playlists[curPl].items.splice(idx, 1);
        loadEditor();
        await persistToServer();
    }
}

async function moveItem(idx, dir) {
    const items = appData.playlists[curPl].items;
    const newIdx = idx + dir;
    if (newIdx < 0 || newIdx >= items.length) return;
    const temp = items[idx];
    items[idx] = items[newIdx];
    items[newIdx] = temp;
    loadEditor();
    await persistToServer();
}

// --- PROGRAMACIÓN SEMANAL ---
function selectDayTab(dayNum, btn) {
    currentActiveDay = parseInt(dayNum);
    document.querySelectorAll('.day-tab').forEach(t => t.classList.remove('active'));
    if (btn) {
        btn.classList.add('active');
    } else {
        const tabEl = document.querySelector(`.day-tab[data-day="${dayNum}"]`);
        if (tabEl) tabEl.classList.add('active');
    }
    renderScheduleView();
}

function renderScheduleView() {
    const list = document.getElementById('schedule-day-list');
    if (!list) return;
    list.innerHTML = '';

    const daySchedule = appData.schedule.filter(s => {
        const days = s.days || [1,2,3,4,5,6,7];
        return days.includes(currentActiveDay);
    });

    if (daySchedule.length === 0) {
        list.innerHTML = '<div style="color:var(--text-muted); padding:16px; text-align:center;">No hay bloques programados para este día. Sonará el playlist "general" 24/7.</div>';
        return;
    }

    const dayLabels = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

    daySchedule.forEach(s => {
        const realIdx = appData.schedule.indexOf(s);
        const isImmediate = (s.mode || 'inmediato') === 'inmediato';
        const modeBadge = isImmediate 
            ? '<span style="color:#ef4444; font-weight:bold;">Inmediato</span>'
            : '<span style="color:#38bdf8; font-weight:bold;">Insertado</span>';

        const daysBadges = (s.days || [1,2,3,4,5,6,7]).map(d => `<strong style="color:#f59e0b; margin-right:2px;">${dayLabels[d-1]}</strong>`).join(' ');

        list.innerHTML += `
        <div style="background:#080e1e; border:1px solid var(--border); border-radius:6px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div>
                <div style="font-size:0.95rem; font-weight:bold; color:#fff; margin-bottom:4px;">
                    Playlist: <span style="color:#38bdf8;">${s.playlist}</span>
                </div>
                <div style="font-size:0.8rem; color:var(--text-muted); line-height:1.4;">
                    <div>Hora: <strong style="color:#4ade80;">${s.start}</strong> a <strong style="color:#4ade80;">${s.end}</strong></div>
                    <div>Modo inicio: ${modeBadge}</div>
                    <div>Días: ${daysBadges}</div>
                </div>
            </div>
            <div style="display:flex; gap:6px;">
                <button class="btn btn-sm" style="background:#0284c7;" onclick="editScheduleItem(${realIdx})">✏️</button>
                <button class="btn btn-danger btn-sm" onclick="deleteScheduleItem(${realIdx})">✖</button>
            </div>
        </div>`;
    });

    if (typeof updateServerClock === 'function') updateServerClock();
}

function openScheduleModal(editIndex = null) {
    const modal = document.getElementById('modal-schedule');
    const selPl = document.getElementById('modal-sel-pl');
    if (!modal || !selPl) return;

    selPl.innerHTML = '';
    Object.keys(appData.playlists).forEach(p => {
        if (p !== 'general') {
            const pType = appData.playlists[p].tipo === 'archivos' ? '🎵' : '📁';
            selPl.innerHTML += `<option value="${p}">${pType} ${p}</option>`;
        }
    });

    if (selPl.options.length === 0) {
        return alert("Primero debes crear un playlist adicional (en la pestaña Playlists) para poder programarlo.");
    }

    if (editIndex !== null) {
        const item = appData.schedule[editIndex];
        document.getElementById('modal-sched-id').value = editIndex;
        selPl.value = item.playlist;
        document.getElementById('modal-time-start').value = item.start;
        document.getElementById('modal-time-end').value = item.end;
        
        const days = item.days || [1,2,3,4,5,6,7];
        document.querySelectorAll('.btn-day-toggle').forEach(btn => {
            const d = parseInt(btn.dataset.d);
            btn.classList.toggle('active', days.includes(d));
        });
        document.getElementById('modal-all-days').checked = (days.length === 7);

        const mode = item.mode || 'inmediato';
        const radioMode = document.querySelector(`input[name="modal_mode"][value="${mode}"]`);
        if (radioMode) radioMode.checked = true;
    } else {
        document.getElementById('modal-sched-id').value = "";
        document.getElementById('modal-time-start').value = "22:00";
        document.getElementById('modal-time-end').value = "23:00";
        document.querySelectorAll('.btn-day-toggle').forEach(btn => btn.classList.add('active'));
        document.getElementById('modal-all-days').checked = true;
        const defaultRadio = document.querySelector(`input[name="modal_mode"][value="inmediato"]`);
        if (defaultRadio) defaultRadio.checked = true;
    }

    modal.style.display = 'flex';
}

function closeScheduleModal() {
    const modal = document.getElementById('modal-schedule');
    if (modal) modal.style.display = 'none';
}

function toggleDayBtn(btn) {
    btn.classList.toggle('active');
    const totalActive = document.querySelectorAll('.btn-day-toggle.active').length;
    document.getElementById('modal-all-days').checked = (totalActive === 7);
}

function toggleAllDays(isChecked) {
    document.querySelectorAll('.btn-day-toggle').forEach(btn => {
        btn.classList.toggle('active', isChecked);
    });
}

async function submitScheduleModal() {
    const idVal = document.getElementById('modal-sched-id').value;
    const pl = document.getElementById('modal-sel-pl').value;
    const start = document.getElementById('modal-time-start').value;
    const end = document.getElementById('modal-time-end').value;
    const modeEl = document.querySelector('input[name="modal_mode"]:checked');
    const mode = modeEl ? modeEl.value : 'inmediato';

    const selectedDays = [];
    document.querySelectorAll('.btn-day-toggle.active').forEach(btn => {
        selectedDays.push(parseInt(btn.dataset.d));
    });

    if (selectedDays.length === 0) {
        return alert("Debes seleccionar al menos un día de la semana.");
    }

    const payload = {
        playlist: pl,
        start: start,
        end: end,
        days: selectedDays,
        mode: mode
    };

    if (idVal !== "") {
        appData.schedule[parseInt(idVal)] = payload;
    } else {
        appData.schedule.push(payload);
    }

    closeScheduleModal();
    renderScheduleView();
    await persistToServer(true);
}

function editScheduleItem(idx) {
    openScheduleModal(idx);
}

async function deleteScheduleItem(idx) {
    if (!confirm("¿Eliminar este bloque de programación?")) return;
    appData.schedule.splice(idx, 1);
    renderScheduleView();
    await persistToServer();
}

async function saveData() {
    await persistToServer(true);
}

// --- RELOJ Y STATS EN VIVO ---
function updateServerClock() {
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
}

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

        const historyListEl = document.getElementById('live-history-list');
        if (historyListEl && data.recent && data.recent.length > 0) {
            historyListEl.innerHTML = '';
            data.recent.forEach((item, index) => {
                const li = document.createElement('li');
                li.style.cssText = "background: #0b132b; padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;";
                li.innerHTML = `
                    <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 75%;">
                        <strong style="color: #38bdf8; margin-right: 6px;">#${index + 1}</strong>
                        <span>🎵 ${item.title}</span>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <span style="font-size: 0.75rem; color: var(--text-muted); background: #152138; padding: 2px 6px; border-radius: 4px;">${item.playlist}</span>
                        <small style="color: var(--text-muted); font-family: monospace;">${item.time}</small>
                    </div>
                `;
                historyListEl.appendChild(li);
            });
        }
    } catch (e) {}
}

async function __refreshStatsFromStatsEndpoint_NO_OP_if_live() {
    if (typeof currentMount === 'undefined') return;
    // NO ACTUALIZAMOS TOPBAR AQUI NUNCA. Live.js declara updateTopStats() con nombre identico
    // y hay shadowing: ambas funciones (main.js y live.js) con mismo nombre + setInterval 3s cada una
    // con fuentes diferentes (stats.php vs autodj_api action=status) → PISAN el DOM del badge
    // #top-status-badge y #top-listeners → FLICKER EN VIVO / DESCONECTADO constante.
    // Source of truth = live.js updateTopStats → action=status. Aquí no hacemos nada.
    try {
        return;
    } catch(e) {}
}

// INICIALIZAR
loadData();
setInterval(updateServerClock, 1000);
updateServerClock();

setInterval(updateLiveHistory, 4000);
updateLiveHistory();

// NO lanzamos setInterval(updateTopStats, 3000) ni llamada inicial desde main.js.
// Live.js YA DECLARA updateTopStats (fuente unificada action=status) con su propio
// setInterval 3s. Si main.js declarara también se produce shadowing del nombre y
// 2 actualizadores paralelos pisando el mismo DOM → flicker EN VIVO / DESCONECTADO.
