function refreshPlaylistsUI() {
    const selPl = document.getElementById('sel-playlist');
    if (!selPl) return;
    
    if (!appData.playlists || Object.keys(appData.playlists).length === 0) {
        appData.playlists = { general: { tipo: 'carpetas', items: [] } };
    }

    if (!appData.playlists[curPl]) {
        curPl = Object.keys(appData.playlists)[0] || 'general';
    }

    selPl.innerHTML = '';
    Object.keys(appData.playlists).forEach(p => {
        const plInfo = appData.playlists[p] || {};
        const pType = plInfo.tipo === 'archivos' ? '🎵' : '📁';
        selPl.innerHTML += `<option value="${p}" ${p === curPl ? 'selected' : ''}>${pType} ${p}</option>`;
    });

    const selFld = document.getElementById('sel-folder');
    const selFldFiles = document.getElementById('sel-folder-files');
    
    if (selFld) {
        selFld.innerHTML = '<option value="">Elegir carpeta...</option>';
        (appData.folders || []).forEach(f => {
            selFld.innerHTML += `<option value="${f.name}">📁 ${f.name} (${f.count} temas)</option>`;
        });
    }

    if (selFldFiles) {
        const prevVal = selFldFiles.value;
        selFldFiles.innerHTML = '<option value="">Filtrar carpeta...</option>';
        (appData.folders || []).forEach(f => {
            selFldFiles.innerHTML += `<option value="${f.name}">📁 ${f.name} (${f.count} temas)</option>`;
        });
        if (prevVal) selFldFiles.value = prevVal;
    }

    loadEditor();
}

async function crearPlaylist() {
    const input = document.getElementById('new-pl-name');
    const typeSelect = document.getElementById('new-pl-type');
    if (!input || !typeSelect) return;
    
    const type = typeSelect.value;
    const name = input.value.replace(/[^a-zA-Z0-9_-]/g, '').trim();
    if (!name) return alert("Ingresa un nombre válido.");
    if (appData.playlists[name]) return alert("Ya existe un playlist con ese nombre.");
    
    appData.playlists[name] = { tipo: type, items: [], allow_repeat: false, repeat_every_n_songs: 0 };
    curPl = name;
    input.value = '';
    
    refreshPlaylistsUI();
    await persistToServer(false);
}

async function eliminarPlaylistActual() {
    if (curPl === 'general' || curPl === (appData.default_playlist || 'general')) {
        return alert("No puedes eliminar el playlist base por defecto.");
    }
    if (!confirm(`¿Eliminar el playlist "${curPl}"?`)) return;
    
    delete appData.playlists[curPl];
    
    if (appData.schedule) {
        appData.schedule = appData.schedule.filter(s => s.playlist !== curPl);
    }
    if (appData.ads) {
        appData.ads = appData.ads.filter(a => a.playlist !== curPl);
    }

    curPl = appData.default_playlist || 'general';
    refreshPlaylistsUI();
    await persistToServer(true);
}

// --- VARIABLES DRAG AND DROP ---
let draggedItemIndex = null;

function handleDragStart(e) {
    draggedItemIndex = Number(this.dataset.index);
    this.style.opacity = '0.4';
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', draggedItemIndex);
}

function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    this.style.borderTop = '2px solid #38bdf8';
}

function handleDragLeave() {
    this.style.borderTop = '1px solid var(--border)';
}

function handleDragEnd() {
    this.style.opacity = '1';
    document.querySelectorAll('.draggable-row').forEach(row => {
        row.style.borderTop = '1px solid var(--border)';
    });
}

async function handleDrop(e) {
    e.preventDefault();
    this.style.borderTop = '1px solid var(--border)';
    const targetIndex = Number(this.dataset.index);

    if (draggedItemIndex === null || draggedItemIndex === targetIndex) return;

    const items = appData.playlists[curPl]?.items;
    if (!Array.isArray(items)) return;

    // Reordenar array
    const movedItem = items.splice(draggedItemIndex, 1)[0];
    items.splice(targetIndex, 0, movedItem);

    loadEditor();
    await persistToServer(false);
}

function loadEditor() {
    const selPl = document.getElementById('sel-playlist');
    if (selPl && selPl.value) {
        curPl = selPl.value;
    }

    if (!appData.playlists[curPl]) {
        appData.playlists[curPl] = { tipo: 'carpetas', items: [], allow_repeat: false, repeat_every_n_songs: 0 };
    }

    const plData = appData.playlists[curPl];
    if (!Array.isArray(plData.items)) plData.items = [];
    if (typeof plData.allow_repeat !== 'boolean') plData.allow_repeat = !!plData.allow_repeat;
    if (!Number.isFinite(plData.repeat_every_n_songs)) plData.repeat_every_n_songs = Math.max(0, parseInt(plData.repeat_every_n_songs, 10) || 0);

    const badge = document.getElementById('ed-type-badge');
    const panelFolder = document.getElementById('panel-add-folder');
    const panelFile = document.getElementById('panel-add-file');
    const btnDel = document.getElementById('btn-del-playlist');
    const durBox = document.getElementById('ed-duration-box');
    const repeatCard = document.getElementById('ed-repeat-card');
    const allowRepeatEl = document.getElementById('ed-allow-repeat');
    const repeatEveryWrap = document.getElementById('ed-repeat-every-wrap');
    const repeatEveryEl = document.getElementById('ed-repeat-every');

    if (repeatCard) repeatCard.style.display = 'block';
    if (allowRepeatEl) {
        allowRepeatEl.checked = !!plData.allow_repeat;
        allowRepeatEl.onchange = async function () {
            if (!appData.playlists[curPl]) return;
            appData.playlists[curPl].allow_repeat = !!this.checked;
            if (repeatEveryWrap) repeatEveryWrap.style.display = appData.playlists[curPl].allow_repeat ? 'block' : 'none';
            await persistToServer(false);
        };
    }
    if (repeatEveryWrap) repeatEveryWrap.style.display = !!plData.allow_repeat ? 'block' : 'none';
    if (repeatEveryEl) {
        repeatEveryEl.value = String(Math.max(0, Math.min(100, parseInt(plData.repeat_every_n_songs, 10) || 0)));
        repeatEveryEl.onchange = async function () {
            if (!appData.playlists[curPl]) return;
            var v = Math.max(0, Math.min(100, parseInt(this.value, 10) || 0));
            this.value = String(v);
            appData.playlists[curPl].repeat_every_n_songs = v;
            await persistToServer(false);
        };
    }

    if (btnDel) {
        btnDel.style.display = (curPl === 'general' || curPl === (appData.default_playlist || 'general')) ? 'none' : 'inline-block';
    }

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

    if (plData.items.length === 0) {
        list.innerHTML = '<div style="color:var(--text-muted); padding:16px; text-align:center; background:#0b132b; border-radius:6px; border:1px solid var(--border);">Este playlist no tiene elementos asignados todavía.</div>';
        if (plData.tipo === 'archivos') {
            const totDur = document.getElementById('ed-total-duration');
            const totCnt = document.getElementById('ed-total-count');
            if (totDur) totDur.innerText = '00m 00s';
            if (totCnt) totCnt.innerText = '(0 canciones)';
        }
        return;
    }

    plData.items.forEach((item, idx) => {
        const row = document.createElement('div');
        row.className = 'draggable-row';
        row.dataset.index = idx;
        row.draggable = true;
        row.style.cssText = "display:flex; justify-content:space-between; align-items:center; background:#0b132b; padding:10px 14px; border:1px solid var(--border); border-radius:6px; margin-bottom:6px; cursor:grab; transition:all 0.15s ease;";

        // Eventos Drag & Drop
        row.addEventListener('dragstart', handleDragStart);
        row.addEventListener('dragover', handleDragOver);
        row.addEventListener('dragleave', handleDragLeave);
        row.addEventListener('drop', handleDrop);
        row.addEventListener('dragend', handleDragEnd);

        if (plData.tipo === 'archivos') {
            const info = getFileInfo(item);
            totalSec += (info.duration_sec || 0);

            row.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:70%;">
                    <span style="color:var(--text-muted); font-size:1.1rem; user-select:none;">☰</span>
                    <strong style="color:#38bdf8;">#${idx+1}</strong>
                    <span title="${info.name || item}">🎵 ${info.name || item}</span>
                    <small style="color:#4ade80; font-family:monospace; font-weight:bold;">[${info.duration_str || '--:--'}]</small>
                </div>
                <div style="display:flex; gap:4px; align-items:center;" onclick="event.stopPropagation();">
                    <button class="btn btn-sm" style="padding:4px 8px;" onclick="moveItem(${idx}, -1)" ${idx === 0 ? 'disabled' : ''}>▲</button>
                    <button class="btn btn-sm" style="padding:4px 8px;" onclick="moveItem(${idx}, 1)" ${idx === plData.items.length - 1 ? 'disabled' : ''}>▼</button>
                    <button class="btn btn-danger btn-sm" style="padding:4px 8px;" onclick="remItem(${idx})">✖</button>
                </div>`;
        } else {
            row.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="color:var(--text-muted); font-size:1.1rem; user-select:none;">☰</span>
                    <strong style="color:#38bdf8;">Paso ${idx+1}:</strong>
                    <span>📁 ${item}</span>
                </div>
                <div style="display:flex; gap:4px; align-items:center;" onclick="event.stopPropagation();">
                    <button class="btn btn-sm" style="padding:4px 8px;" onclick="moveItem(${idx}, -1)" ${idx === 0 ? 'disabled' : ''}>▲</button>
                    <button class="btn btn-sm" style="padding:4px 8px;" onclick="moveItem(${idx}, 1)" ${idx === plData.items.length - 1 ? 'disabled' : ''}>▼</button>
                    <button class="btn btn-danger btn-sm" style="padding:4px 8px;" onclick="remItem(${idx})">✖</button>
                </div>`;
        }

        list.appendChild(row);
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
    const currentSelectedSong = selFile.value;
    selFile.innerHTML = '<option value="">Selecciona canción...</option>';

    if (!folderName) return;

    const folderObj = (appData.folders || []).find(f => f.name === folderName);
    if (folderObj && Array.isArray(folderObj.files)) {
        folderObj.files.forEach(file => {
            const relVal = `${folderObj.name}/${file.name}`;
            selFile.innerHTML += `<option value="${relVal}">${file.name} [${file.duration_str || '--:--'}]</option>`;
        });
    }

    if (currentSelectedSong) {
        selFile.value = currentSelectedSong;
    }
}

async function addFolder() {
    const selFolder = document.getElementById('sel-folder');
    if (selFolder && selFolder.value) {
        if (!appData.playlists[curPl]) appData.playlists[curPl] = { tipo: 'carpetas', items: [], allow_repeat: false, repeat_every_n_songs: 0 };
        if (!Array.isArray(appData.playlists[curPl].items)) appData.playlists[curPl].items = [];
        
        appData.playlists[curPl].items.push(selFolder.value);
        selFolder.value = '';
        loadEditor();
        await persistToServer(false);
    }
}

async function addSpecificFile() {
    const selFile = document.getElementById('sel-specific-file');
    if (!selFile || !selFile.value) {
        return alert("Primero selecciona una canción del menú desplegable.");
    }

    if (!appData.playlists[curPl]) appData.playlists[curPl] = { tipo: 'archivos', items: [], allow_repeat: false, repeat_every_n_songs: 0 };
    if (!Array.isArray(appData.playlists[curPl].items)) appData.playlists[curPl].items = [];
    
    appData.playlists[curPl].items.push(selFile.value);
    
    loadEditor();
    await persistToServer(false);
}

async function remItem(idx) {
    if (appData.playlists[curPl] && Array.isArray(appData.playlists[curPl].items)) {
        appData.playlists[curPl].items.splice(idx, 1);
        loadEditor();
        await persistToServer(false);
    }
}

async function moveItem(idx, dir) {
    const items = appData.playlists[curPl]?.items;
    if (!Array.isArray(items)) return;
    
    const newIdx = idx + dir;
    if (newIdx < 0 || newIdx >= items.length) return;
    
    const temp = items[idx];
    items[idx] = items[newIdx];
    items[newIdx] = temp;
    
    loadEditor();
    await persistToServer(false);
}
