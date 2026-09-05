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

function populateAjustesUI() {
    const tzSel = document.getElementById('set-timezone');
    const plSel = document.getElementById('set-default-playlist');
    const voiceChk = document.getElementById('set-time-voice-enabled');
    const voiceFld = document.getElementById('set-time-voice-folder');
    const voiceBox = document.getElementById('box-time-voice-config');

    if (tzSel) tzSel.value = appData.timezone || 'America/Costa_Rica';

    if (plSel) {
        plSel.innerHTML = '';
        Object.keys(appData.playlists).forEach(p => {
            const pInfo = appData.playlists[p] || {};
            const icon = pInfo.tipo === 'archivos' ? '🎵' : '📁';
            plSel.innerHTML += `<option value="${p}" ${p === (appData.default_playlist || 'general') ? 'selected' : ''}>${icon} ${p}</option>`;
        });
    }

    const timeVoice = appData.time_voice || { enabled: false, folder: '' };
    if (voiceChk) {
        voiceChk.checked = !!timeVoice.enabled;
        if (voiceBox) voiceBox.style.display = voiceChk.checked ? 'block' : 'none';
    }

    if (voiceFld) {
        voiceFld.innerHTML = '<option value="">Selecciona la carpeta de audios...';
        (appData.folders || []).forEach(f => {
            voiceFld.innerHTML += `<option value="${f.name}" ${f.name === timeVoice.folder ? 'selected' : ''}>📁 ${f.name} (${f.count} audios)</option>`;
        });
    }

    renderIntercalators();
    const typeSel = document.getElementById('int-add-type');
    if (typeSel) onIntTypeChanged(typeSel.value);
}

function toggleTimeVoiceOptions(isChecked) {
    const box = document.getElementById('box-time-voice-config');
    if (box) box.style.display = isChecked ? 'block' : 'none';
}

async function saveAjustes() {
    const tz = document.getElementById('set-timezone').value;
    const defPl = document.getElementById('set-default-playlist').value;
    const voiceEnabled = document.getElementById('set-time-voice-enabled').checked;
    const voiceFolder = document.getElementById('set-time-voice-folder').value;

    if (voiceEnabled && !voiceFolder) {
        return alert("Debes seleccionar la carpeta donde tienes los audios de la hora (00.mp3 a 23.mp3).");
    }

    appData.timezone = tz;
    appData.default_playlist = defPl;
    appData.time_voice = {
        enabled: voiceEnabled,
        folder: voiceFolder
    };
    if (!Array.isArray(appData.intercalators)) appData.intercalators = [];

    await persistToServer(true);
    renderIntercalators();
}
