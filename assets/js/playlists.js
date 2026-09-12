// Item especial de playlist: "HORA" (hora hablada en vivo). next_song.php lo
// resuelve al llegar la playlist a ese punto (une HRS<HH> + MIN<MM>).
const PLAYLIST_HORA_TOKEN = '@HORA@';

// --- Editor: punto de inserción (ancla) y arrastre desde la biblioteca (columna derecha) ---
let edAnchorIndex = null;   // índice de la fila marcada con doble clic
let edAnchorPl    = null;   // playlist a la que pertenece el ancla (se limpia al cambiar de playlist)
let plDragPayload = null;   // {kind:'row'|'source', ...} distingue "reordenar" de "insertar desde la biblioteca"
let plBrowserBound = false; // evita enganchar dos veces los listeners de la biblioteca

function plEscape(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

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

    edAnchorIndex = null;
    edAnchorPl = null;
    bindBrowserColumn();
    loadEditor();

    // Mantener al día el selector de "Control de Repetición" (vive en Ajustes AutoDJ)
    if (typeof repRefreshPlaylistOptions === 'function') repRefreshPlaylistOptions();
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
    plDragPayload = { kind: 'row', index: draggedItemIndex };
    this.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', String(draggedItemIndex)); } catch (err) {}
}

function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = (plDragPayload && plDragPayload.kind === 'source') ? 'copy' : 'move';
    this.classList.add('drop-target');
}

function handleDragLeave() {
    this.classList.remove('drop-target');
}

function handleDragEnd() {
    plDragPayload = null;
    document.querySelectorAll('.draggable-row').forEach(row => {
        row.classList.remove('drop-target', 'dragging');
    });
    const edList = document.getElementById('ed-list');
    if (edList) edList.classList.remove('drop-target');
}

async function handleDrop(e) {
    e.preventDefault();
    e.stopPropagation();                 // que el contenedor #ed-list no reprocese el drop
    this.classList.remove('drop-target');
    const targetIndex = Number(this.dataset.index);

    // Arrastre desde la biblioteca (columna derecha):
    //   con ancla → debajo del ancla; sin ancla → en la fila donde se soltó.
    if (plDragPayload && plDragPayload.kind === 'source') {
        const value = plDragPayload.value;
        plDragPayload = null;
        await insertItems([value], (getAnchorIndex() !== null) ? undefined : targetIndex);
        return;
    }

    if (draggedItemIndex === null || draggedItemIndex === targetIndex) { plDragPayload = null; return; }

    const items = appData.playlists[curPl]?.items;
    if (!Array.isArray(items)) { plDragPayload = null; return; }

    // Reordenar array
    const movedItem = items.splice(draggedItemIndex, 1)[0];
    items.splice(targetIndex, 0, movedItem);
    remapAnchorAfterMove(draggedItemIndex, targetIndex);

    plDragPayload = null;
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

    // El ancla pertenece a UNA playlist: al cambiar de playlist (o de modo) se descarta.
    if (edAnchorPl !== curPl || !plData.items.length) { edAnchorIndex = null; edAnchorPl = null; }

    const badge = document.getElementById('ed-type-badge');
    const panelFolder = document.getElementById('panel-add-folder');
    const panelFile = document.getElementById('panel-add-file');
    const btnDel = document.getElementById('btn-del-playlist');
    const durBox = document.getElementById('ed-duration-box');

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
    const prevScroll = list.scrollTop;
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
        updateAnchorUi();
        renderBrowserColumn();
        return;
    }

    plData.items.forEach((item, idx) => {
        const row = document.createElement('div');
        row.className = 'draggable-row';
        row.dataset.index = idx;
        row.draggable = true;

        // Eventos Drag & Drop + doble clic para marcar el punto de inserción
        row.addEventListener('dragstart', handleDragStart);
        row.addEventListener('dragover', handleDragOver);
        row.addEventListener('dragleave', handleDragLeave);
        row.addEventListener('drop', handleDrop);
        row.addEventListener('dragend', handleDragEnd);
        row.addEventListener('dblclick', handleRowDblClick);

        // Item especial "HORA": fila propia, sin duración.
        if (item === PLAYLIST_HORA_TOKEN) {
            const prefix = (plData.tipo === 'archivos') ? ('#' + (idx + 1)) : ('Paso ' + (idx + 1) + ':');
            row.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:70%;">
                    <span style="color:var(--text-muted); font-size:1.1rem; user-select:none;">☰</span>
                    <strong style="color:#38bdf8;">${prefix}</strong>
                    <span style="color:#c4b5fd; font-weight:bold;">🕐 HORA (hora hablada en vivo)</span>
                </div>
                <div style="display:flex; gap:4px; align-items:center;" onclick="event.stopPropagation();">
                    <button class="btn btn-sm" style="padding:4px 8px;" onclick="moveItem(${idx}, -1)" ${idx === 0 ? 'disabled' : ''}>▲</button>
                    <button class="btn btn-sm" style="padding:4px 8px;" onclick="moveItem(${idx}, 1)" ${idx === plData.items.length - 1 ? 'disabled' : ''}>▼</button>
                    <button class="btn btn-danger btn-sm" style="padding:4px 8px;" onclick="remItem(${idx})">✖</button>
                </div>`;
            list.appendChild(row);
            return;
        }

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

    list.scrollTop = prevScroll;
    updateAnchorUi();
    renderBrowserColumn();
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

    renderBrowserColumn();
}

async function addFolder() {
    const selFolder = document.getElementById('sel-folder');
    if (selFolder && selFolder.value) {
        const value = selFolder.value;
        selFolder.value = '';
        await insertItems([value]);
    }
}

async function addSpecificFile() {
    const selFile = document.getElementById('sel-specific-file');
    if (!selFile || !selFile.value) {
        return alert("Primero selecciona una canción del menú desplegable.");
    }
    await insertItems([selFile.value]);
}

// Inserta el item especial "HORA" (hora hablada en vivo: une HRS<HH> + MIN<MM>
// / HRS<HH>_0 según la hora real de la radio). Lo resuelve next_song.php.
async function addHora() {
    await insertItems([PLAYLIST_HORA_TOKEN]);
}

async function remItem(idx) {
    if (appData.playlists[curPl] && Array.isArray(appData.playlists[curPl].items)) {
        const a = getAnchorIndex();
        if (a !== null) {
            if (a === idx) { edAnchorIndex = null; edAnchorPl = null; }
            else if (idx < a) { edAnchorIndex = a - 1; }
        }
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

    // El ancla acompaña al item movido
    const a = getAnchorIndex();
    if (a === idx) edAnchorIndex = newIdx;
    else if (a === newIdx) edAnchorIndex = idx;

    loadEditor();
    await persistToServer(false);
}

// ===== Punto de inserción (ancla fijada con doble clic) =====

function getAnchorIndex() {
    if (edAnchorPl !== curPl) return null;
    if (edAnchorIndex === null || edAnchorIndex < 0) return null;
    const items = appData.playlists[curPl]?.items;
    if (!Array.isArray(items) || edAnchorIndex >= items.length) return null;
    return edAnchorIndex;
}

function resolveInsertIndex(items) {
    const a = getAnchorIndex();
    return (a !== null) ? a + 1 : items.length;
}

function setAnchor(idx) {
    if (edAnchorPl === curPl && edAnchorIndex === idx) {
        edAnchorIndex = null;   // doble clic sobre la misma fila → quitar el ancla
        edAnchorPl = null;
    } else {
        edAnchorIndex = idx;
        edAnchorPl = curPl;
    }
    loadEditor();
}

function clearAnchor() {
    edAnchorIndex = null;
    edAnchorPl = null;
    loadEditor();
}

function handleRowDblClick(e) {
    if (e.target.closest && e.target.closest('button')) return;   // los botones ▲▼✖ no fijan ancla
    const idx = Number(this.dataset.index);
    if (!Number.isFinite(idx)) return;
    setAnchor(idx);
}

// Inserta uno o varios items (strings). Posición:
//   - con ancla  → justo debajo de la fila marcada (y el ancla avanza al último añadido);
//   - con explicitIndex (soltar arrastrando sobre una fila) → en esa posición;
//   - sin ancla  → al final (como antes).
async function insertItems(values, explicitIndex) {
    const vals = (Array.isArray(values) ? values : [values]).filter(v => typeof v === 'string' && v !== '');
    if (vals.length === 0) return;

    if (!appData.playlists[curPl]) appData.playlists[curPl] = { tipo: 'archivos', items: [], allow_repeat: false, repeat_every_n_songs: 0 };
    if (!Array.isArray(appData.playlists[curPl].items)) appData.playlists[curPl].items = [];

    const items = appData.playlists[curPl].items;
    const hadAnchor = getAnchorIndex() !== null;
    const at = Math.max(0, Math.min((explicitIndex != null) ? explicitIndex : resolveInsertIndex(items), items.length));
    items.splice(at, 0, ...vals);

    if (hadAnchor) {
        edAnchorIndex = at + vals.length - 1;   // seguir insertando debajo de lo último añadido
        edAnchorPl = curPl;
    }

    loadEditor();
    await persistToServer(false);
}

function remapAnchorAfterMove(from, to) {
    const a = getAnchorIndex();
    if (a === null) return;
    if (a === from) { edAnchorIndex = to; return; }
    if (from < a && to >= a) edAnchorIndex = a - 1;
    else if (from > a && to <= a) edAnchorIndex = a + 1;
}

function updateAnchorUi() {
    const bar = document.getElementById('ed-anchor-bar');
    const label = document.getElementById('ed-anchor-label');
    const list = document.getElementById('ed-list');
    const a = getAnchorIndex();

    if (list) list.querySelectorAll('.draggable-row').forEach(r => r.classList.remove('is-anchor'));

    if (a === null || !list) {
        if (bar) bar.style.display = 'none';
        return;
    }

    const row = list.querySelector('.draggable-row[data-index="' + a + '"]');
    if (row) row.classList.add('is-anchor');

    const plData = appData.playlists[curPl] || {};
    const item = Array.isArray(plData.items) ? plData.items[a] : '';
    let txt;
    if (item === PLAYLIST_HORA_TOKEN) txt = '🕐 HORA';
    else if (plData.tipo === 'archivos') txt = (typeof getFileInfo === 'function' ? ((getFileInfo(item) || {}).name || item) : item);
    else txt = '📁 ' + item;

    if (label) label.textContent = '#' + (a + 1) + ' (' + txt + ')';
    if (bar) bar.style.display = 'flex';
}

// ===== Columna derecha: biblioteca con arrastre + clic (+) para añadir =====

function renderBrowserColumn() {
    const listEl = document.getElementById('pl-browser-list');
    if (!listEl) return;
    const titleEl = document.getElementById('pl-browser-title');
    const countEl = document.getElementById('pl-browser-count');
    const hintEl = document.getElementById('pl-browser-hint');

    const plData = appData.playlists[curPl] || {};
    listEl.innerHTML = '';
    if (hintEl) hintEl.textContent = '';

    if (plData.tipo === 'archivos') {
        const selFolderFiles = document.getElementById('sel-folder-files');
        const folderName = selFolderFiles ? selFolderFiles.value : '';
        const folderObj = (appData.folders || []).find(f => f.name === folderName);

        if (!folderObj) {
            if (titleEl) titleEl.textContent = 'Archivos';
            if (countEl) countEl.textContent = '';
            if (hintEl) hintEl.textContent = 'Elige una carpeta en "Filtrar carpeta" para ver sus archivos y arrastrarlos aquí.';
            return;
        }

        const files = Array.isArray(folderObj.files) ? folderObj.files : [];
        if (titleEl) titleEl.textContent = '📁 ' + folderObj.name;
        if (countEl) countEl.textContent = files.length + ' archivos';
        if (files.length === 0) {
            listEl.innerHTML = '<li class="pl-browser-item is-empty">Carpeta vacía.</li>';
            return;
        }
        files.forEach(file => {
            const li = document.createElement('li');
            li.className = 'pl-browser-item';
            li.draggable = true;
            li.dataset.plValue = folderObj.name + '/' + file.name;
            li.innerHTML = '<span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">🎵 ' + plEscape(file.name) + '</span>' +
                '<span class="pl-browser-actions">' +
                '<small style="color:var(--text-muted); font-family:monospace;">' + plEscape(file.duration_str || '--:--') + '</small>' +
                '<button class="btn btn-sm" style="padding:2px 9px;" title="Añadir a la playlist">+</button>' +
                '</span>';
            listEl.appendChild(li);
        });
        return;
    }

    // Modo carpetas: se listan todas las carpetas para arrastrarlas a la rotación
    const folders = appData.folders || [];
    if (titleEl) titleEl.textContent = 'Carpetas';
    if (countEl) countEl.textContent = folders.length + ' carpetas';
    if (folders.length === 0) {
        listEl.innerHTML = '<li class="pl-browser-item is-empty">No hay carpetas.</li>';
        return;
    }
    folders.forEach(f => {
        const li = document.createElement('li');
        li.className = 'pl-browser-item';
        li.draggable = true;
        li.dataset.plValue = f.name;
        li.innerHTML = '<span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">📁 ' + plEscape(f.name) + '</span>' +
            '<span class="pl-browser-actions">' +
            '<small style="color:var(--text-muted);">' + (f.count || 0) + ' MP3</small>' +
            '<button class="btn btn-sm" style="padding:2px 9px;" title="Añadir a la playlist">+</button>' +
            '</span>';
        listEl.appendChild(li);
    });
}

function bindBrowserColumn() {
    if (plBrowserBound) return;
    const listEl = document.getElementById('pl-browser-list');
    if (!listEl) return;

    listEl.addEventListener('dragstart', function (e) {
        const item = e.target.closest && e.target.closest('.pl-browser-item');
        if (!item || item.classList.contains('is-empty') || !item.dataset.plValue) { e.preventDefault(); return; }
        plDragPayload = { kind: 'source', value: item.dataset.plValue };
        draggedItemIndex = null;
        try {
            e.dataTransfer.effectAllowed = 'copy';
            e.dataTransfer.setData('text/plain', item.dataset.plValue);
        } catch (err) {}
    });

    // Limpieza del feedback visual al terminar un arrastre desde la biblioteca
    listEl.addEventListener('dragend', function () {
        plDragPayload = null;
        const edList = document.getElementById('ed-list');
        if (edList) edList.classList.remove('drop-target');
        document.querySelectorAll('.draggable-row').forEach(r => r.classList.remove('drop-target', 'dragging'));
    });

    // Fallback táctil: el botón "+" de cada fila añade sin arrastrar
    listEl.addEventListener('click', function (e) {
        const btn = e.target.closest && e.target.closest('button');
        const item = e.target.closest && e.target.closest('.pl-browser-item');
        if (!btn || !item || item.classList.contains('is-empty') || !item.dataset.plValue) return;
        insertItems([item.dataset.plValue]);
    });

    // Permite soltar sobre la lista de la playlist, incluso cuando está vacía
    const edList = document.getElementById('ed-list');
    if (edList) {
        edList.addEventListener('dragover', function (e) {
            if (plDragPayload && plDragPayload.kind === 'source') {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                edList.classList.add('drop-target');
            }
        });
        edList.addEventListener('dragleave', function () { edList.classList.remove('drop-target'); });
        edList.addEventListener('drop', function (e) {
            edList.classList.remove('drop-target');
            if (!plDragPayload || plDragPayload.kind !== 'source') return;
            e.preventDefault();
            const value = plDragPayload.value;
            plDragPayload = null;
            insertItems([value]);
        });
    }

    plBrowserBound = true;
}
