function onIntTypeChanged(type) {
    const lbl = document.getElementById('int-add-value-label');
    const inp = document.getElementById('int-add-value');
    if (type === 'minutes') {
        if (lbl) lbl.textContent = '🔢 Minutos (N)';
        if (inp) { inp.min = 1; inp.max = 240; if ((parseInt(inp.value,10)||0) < 1 || parseInt(inp.value,10) > 240) inp.value = '15'; }
    } else {
        if (lbl) lbl.textContent = '🔢 Canciones (N)';
        if (inp) { inp.min = 1; inp.max = 50;  if ((parseInt(inp.value,10)||0) < 1 || parseInt(inp.value,10) > 50)  inp.value = '3'; }
    }
}

function renderIntercalators() {
    const list = document.getElementById('int-list');
    const empty = document.getElementById('int-empty');
    const folderSel = document.getElementById('int-add-folder');
    if (!list) return;
    if (!Array.isArray(appData.intercalators)) appData.intercalators = [];

    if (folderSel) {
        folderSel.innerHTML = '<option value="">-- Selecciona una carpeta --</option>';
        (appData.folders || []).forEach(f => {
            folderSel.innerHTML += `<option value="${f.name}">📁 ${f.name} (${f.count} audios)</option>`;
        });
    }

    list.innerHTML = '';
    if (appData.intercalators.length === 0) {
        if (empty) empty.style.display = 'block';
        return;
    }
    if (empty) empty.style.display = 'none';

    appData.intercalators.forEach((rule, idx) => {
        if (!rule || !rule.folder) return;
        const isSongs = rule.type !== 'minutes';
        const applyAlways = rule.apply_mode === 'always';
        const isWholeFolder = (rule.play_mode || 'single_random') === 'whole_folder_seq';
        const applyModeBadge = applyAlways
            ? `<span style="background:rgba(239,68,68,0.15); color:#ef4444; padding:3px 8px; border-radius:10px; font-size:0.72rem; font-weight:bold; margin-left:4px;"><i class="fa-solid fa-bullhorn" style="margin-right:4px;"></i>TODOS</span>`
            : `<span style="background:rgba(74,222,128,0.15); color:#4ade80; padding:3px 8px; border-radius:10px; font-size:0.72rem; font-weight:bold; margin-left:4px;"><i class="fa-solid fa-music" style="margin-right:4px;"></i>Solo General</span>`;
        const typeLabel = isSongs ? `<span style="background:rgba(56,189,248,0.15); color:#38bdf8; padding:3px 8px; border-radius:10px; font-size:0.75rem; font-weight:bold;"><i class="fa-solid fa-music" style="margin-right:4px;"></i>Canciones</span>`
                                  : `<span style="background:rgba(250,204,21,0.15); color:#facc15; padding:3px 8px; border-radius:10px; font-size:0.75rem; font-weight:bold;"><i class="fa-solid fa-clock" style="margin-right:4px;"></i>Minutos</span>`;
        const playModeBadge = isWholeFolder
            ? `<span style="background:rgba(168,85,247,0.15); color:#a855f7; padding:3px 8px; border-radius:10px; font-size:0.75rem; font-weight:bold;"><i class="fa-solid fa-book" style="margin-right:4px;"></i>Carpeta Completa en Orden</span>`
            : `<span style="background:rgba(34,197,94,0.15); color:#22c55e; padding:3px 8px; border-radius:10px; font-size:0.75rem; font-weight:bold;"><i class="fa-solid fa-dice" style="margin-right:4px;"></i>1 Canción Aleatoria</span>`;
        const maxVal = isSongs ? 50 : 240;
        const folderInfo = (appData.folders||[]).find(f => f && (f.name === rule.folder || f.path === rule.folder || f.name === String(rule.folder).split(/[\\/]/).filter(Boolean).pop()));
        const folderDisplay = folderInfo ? `${folderInfo.name} (${folderInfo.count} audios)` : String(rule.folder);
        const row = document.createElement('div');
        row.style.cssText = 'background:#0b132b; border:1px solid var(--border); border-radius:8px; padding:10px 12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;';
        row.innerHTML = `
            <div style="flex:1; min-width:240px;">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:6px;">
                    <strong style="color:#e2e8f0; font-size:0.88rem;"><i class="fa-solid fa-folder-open" style="color:#4ade80; margin-right:6px;"></i>${folderDisplay}</strong>
                    ${typeLabel}
                    ${playModeBadge}
                    ${applyModeBadge}
                </div>
                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-top:4px;">
                    <label style="font-size:0.78rem; color:var(--text-muted); font-weight:bold;">Frecuencia =</label>
                    <input type="number" min="1" max="${maxVal}" step="1" value="${Math.max(1, parseInt(rule.value,10)||1)}"
                           data-idx="${idx}" data-field="value"
                           onchange="updateIntField(${idx},'value',parseInt(this.value,10)||1)"
                           style="width:86px; padding:5px 8px; border-radius:6px; background:#121c30; color:#fff; border:1px solid var(--border); text-align:center; font-weight:bold;">
                    <span style="font-size:0.82rem; color:#cbd5e1;">${isSongs ? 'canciones musicales' : 'minutos transcurridos'}</span>
                    <div style="display:flex; align-items:center; gap:6px; background:rgba(15,23,42,0.6); padding:4px 8px; border-radius:6px; border:1px solid rgba(255,255,255,0.05);">
                        <span style="font-size:0.75rem; color:var(--text-muted); font-weight:bold;">Aplicar en:</span>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.75rem; color:#cbd5e1; cursor:pointer;">
                            <input type="radio" name="apply_${rule.id}" ${!applyAlways ? 'checked' : ''}
                                   onchange="updateIntField(${idx},'apply_mode','default_only')" style="accent-color:#4ade80;">
                            <strong style="color:#4ade80;">Solo General</strong>
                        </label>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.75rem; color:#cbd5e1; cursor:pointer;">
                            <input type="radio" name="apply_${rule.id}" ${applyAlways ? 'checked' : ''}
                                   onchange="updateIntField(${idx},'apply_mode','always')" style="accent-color:#ef4444;">
                            <strong style="color:#ef4444;">TODOS</strong>
                        </label>
                    </div>
                    <div style="display:flex; align-items:center; gap:6px; background:rgba(15,23,42,0.6); padding:4px 8px; border-radius:6px; border:1px solid rgba(255,255,255,0.05);">
                        <span style="font-size:0.75rem; color:var(--text-muted); font-weight:bold;">Mete:</span>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.75rem; color:#cbd5e1; cursor:pointer;">
                            <input type="radio" name="play_${rule.id}" ${!isWholeFolder ? 'checked' : ''}
                                   onchange="updateIntField(${idx},'play_mode','single_random')" style="accent-color:#22c55e;">
                            <strong style="color:#22c55e;">1 Canción</strong>
                        </label>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.75rem; color:#cbd5e1; cursor:pointer;">
                            <input type="radio" name="play_${rule.id}" ${isWholeFolder ? 'checked' : ''}
                                   onchange="updateIntField(${idx},'play_mode','whole_folder_seq')" style="accent-color:#a855f7;">
                            <strong style="color:#a855f7;">Carpeta Completa</strong>
                        </label>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-danger btn-sm" onclick="removeIntercalator(${idx})" style="padding:6px 10px;">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        `;
        list.appendChild(row);
    });
}

window.addIntercalator = function() {
    const f = document.getElementById('int-add-folder');
    const t = document.getElementById('int-add-type');
    const v = document.getElementById('int-add-value');
    const p = document.getElementById('int-add-play-mode');
    if (!f || !f.value) { alert('Selecciona una carpeta primero.'); return; }
    const type = (t && t.value === 'minutes') ? 'minutes' : 'songs';
    const playMode = (p && p.value === 'whole_folder_seq') ? 'whole_folder_seq' : 'single_random';
    let value = Math.max(1, parseInt(v ? v.value : '3', 10) || 1);
    if (type === 'songs')   value = Math.min(50, value);
    if (type === 'minutes') value = Math.min(240, value);
    if (!Array.isArray(appData.intercalators)) appData.intercalators = [];
    appData.intercalators.push({
        id: 'int_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2,7),
        folder: String(f.value),
        type: type,
        value: value,
        apply_mode: 'default_only',
        play_mode: playMode,
    });
    if (f) f.value = '';
    renderIntercalators();
};

window.updateIntField = function(idx, field, value) {
    if (!Array.isArray(appData.intercalators) || !appData.intercalators[idx]) return;
    if (field === 'value') {
        const isSongs = appData.intercalators[idx].type !== 'minutes';
        value = Math.max(1, parseInt(value,10) || 1);
        value = Math.min(isSongs ? 50 : 240, value);
    } else if (field === 'apply_mode') {
        value = String(value) === 'always' ? 'always' : 'default_only';
    } else if (field === 'play_mode') {
        value = String(value) === 'whole_folder_seq' ? 'whole_folder_seq' : 'single_random';
    }
    appData.intercalators[idx][field] = value;
    renderIntercalators();
};

window.removeIntercalator = function(idx) {
    if (!confirm('¿Eliminar esta regla de intercalado?')) return;
    if (!Array.isArray(appData.intercalators)) appData.intercalators = [];
    appData.intercalators.splice(idx, 1);
    renderIntercalators();
};

// ===== CONTROL DE REPETICIÓN (por playlist) =====
let repSelected = null;

function repRefreshPlaylistOptions() {
    const sel = document.getElementById('rep-playlist');
    if (!sel) return;
    if (repSelected === null || !appData.playlists[repSelected]) {
        repSelected = appData.default_playlist || Object.keys(appData.playlists)[0] || 'general';
    }
    sel.innerHTML = '';
    Object.keys(appData.playlists).forEach(p => {
        const pInfo = appData.playlists[p] || {};
        const icon = pInfo.tipo === 'archivos' ? '🎵' : '📁';
        sel.innerHTML += `<option value="${p}">${icon} ${p}</option>`;
    });
    if (appData.playlists[repSelected]) sel.value = repSelected;
}

function repOnPlaylistChange() {
    const sel = document.getElementById('rep-playlist');
    if (sel && sel.value) repSelected = sel.value;
    repRenderCard();
}

function repRenderCard() {
    const sel = document.getElementById('rep-playlist');
    if (!sel) return;
    if (sel.options.length === 0) repRefreshPlaylistOptions();

    const name = sel.value;
    if (!appData.playlists[name]) { repRefreshPlaylistOptions(); return; }
    repSelected = name;

    const pl = appData.playlists[name];
    if (typeof pl.allow_repeat !== 'boolean') pl.allow_repeat = !!pl.allow_repeat;
    if (!Number.isFinite(pl.repeat_every_n_songs)) pl.repeat_every_n_songs = 0;

    const isSeq = (pl.tipo === 'archivos');

    const badge = document.getElementById('rep-tipo-badge');
    if (badge) {
        badge.textContent = isSeq ? 'Modo: Archivos (Secuencia)' : 'Modo: Carpetas (Rotación)';
        badge.style.background = isSeq ? 'rgba(139,92,246,0.18)' : 'rgba(2,132,199,0.18)';
        badge.style.color = isSeq ? '#c4b5fd' : '#7dd3fc';
    }

    const allowEl = document.getElementById('rep-allow');
    const everyEl = document.getElementById('rep-every');
    const everyWrap = document.getElementById('rep-every-wrap');
    const controls = document.getElementById('rep-controls');
    const note = document.getElementById('rep-note');

    if (allowEl) {
        allowEl.checked = !!pl.allow_repeat;
        allowEl.disabled = isSeq;
        allowEl.onchange = function () {
            if (!appData.playlists[repSelected]) return;
            appData.playlists[repSelected].allow_repeat = !!this.checked;
            repRenderCard();
        };
    }
    if (everyEl) {
        everyEl.value = String(Math.max(0, Math.min(100, parseInt(pl.repeat_every_n_songs, 10) || 0)));
        everyEl.disabled = isSeq || !pl.allow_repeat;
        everyEl.onchange = function () {
            if (!appData.playlists[repSelected]) return;
            const v = Math.max(0, Math.min(100, parseInt(this.value, 10) || 0));
            this.value = String(v);
            appData.playlists[repSelected].repeat_every_n_songs = v;
        };
    }
    if (controls) controls.style.opacity = isSeq ? '0.55' : '1';
    if (everyWrap) everyWrap.style.opacity = (!isSeq && !pl.allow_repeat) ? '0.55' : '1';

    if (note) {
        if (isSeq) {
            note.style.display = 'block';
            note.style.color = '#fbbf24';
            note.textContent = "Esta playlist es de secuencia exacta (Archivos): el motor la trata como repetible automáticamente y 'Repetir cada N' no aplica. Cambia el tipo en Playlists si quieres configurarlo.";
        } else if (!pl.allow_repeat) {
            note.style.display = 'block';
            note.style.color = 'var(--text-muted)';
            note.textContent = "Marca 'Permitir repetición' para activar el intercalado cada N canciones.";
        } else {
            note.style.display = 'none';
            note.textContent = '';
        }
    }
}

function populateAjustesUI() {
    const tzSel = document.getElementById('set-timezone');
    const plSel = document.getElementById('set-default-playlist');

    if (tzSel) tzSel.value = appData.timezone || 'America/Costa_Rica';

    if (plSel) {
        plSel.innerHTML = '';
        Object.keys(appData.playlists).forEach(p => {
            const pInfo = appData.playlists[p] || {};
            const icon = pInfo.tipo === 'archivos' ? '🎵' : '📁';
            plSel.innerHTML += `<option value="${p}" ${p === (appData.default_playlist || 'general') ? 'selected' : ''}>${icon} ${p}</option>`;
        });
    }

    const xf = appData.crossfade || { fade_in: 0, fade_out: 0 };
    const xfIn = document.getElementById('set-xfade-in');
    const xfOut = document.getElementById('set-xfade-out');
    if (xfIn) xfIn.value = (typeof xf.fade_in === 'number' && isFinite(xf.fade_in)) ? xf.fade_in : 0;
    if (xfOut) xfOut.value = (typeof xf.fade_out === 'number' && isFinite(xf.fade_out)) ? xf.fade_out : 0;

    const hideBox = document.getElementById('hide-title-list');
    if (hideBox) {
        const hidden = Array.isArray(appData.hide_title_folders) ? appData.hide_title_folders : [];
        const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        const folders = appData.folders || [];
        hideBox.innerHTML = folders.length
            ? folders.map(f => `<label style="display:flex; align-items:center; gap:7px; font-size:0.82rem; cursor:pointer;">
                   <input type="checkbox" class="htf-chk" value="${esc(f.name)}"${hidden.includes(f.name) ? ' checked' : ''}>
                   <span>📁 ${esc(f.name)} <span style="color:var(--text-muted);">(${f.count})</span></span>
               </label>`).join('')
            : '<span style="font-size:0.82rem; color:var(--text-muted);">No hay carpetas en la Musicateca.</span>';
    }

    renderIntercalators();
    const typeSel = document.getElementById('int-add-type');
    if (typeSel) onIntTypeChanged(typeSel.value);

    repRefreshPlaylistOptions();
    repRenderCard();
}

async function saveAjustes() {
    const tz = document.getElementById('set-timezone').value;
    const defPl = document.getElementById('set-default-playlist').value;

    appData.timezone = tz;
    appData.default_playlist = defPl;

    const xfInEl = document.getElementById('set-xfade-in');
    const xfOutEl = document.getElementById('set-xfade-out');
    const clampXf = v => Math.round(Math.max(0, Math.min(5, (isFinite(parseFloat(v)) ? parseFloat(v) : 0))) * 2) / 2;
    appData.crossfade = {
        fade_in: clampXf(xfInEl ? xfInEl.value : 0),
        fade_out: clampXf(xfOutEl ? xfOutEl.value : 0)
    };

    if (!Array.isArray(appData.intercalators)) appData.intercalators = [];

    appData.hide_title_folders = Array.from(document.querySelectorAll('.htf-chk'))
        .filter(c => c.checked).map(c => c.value);

    await persistToServer(true);
    renderIntercalators();
    repRenderCard();
}
