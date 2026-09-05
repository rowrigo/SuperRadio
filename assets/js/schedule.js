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
        // El modo Inmediato está FORZADO SIEMPRE (no hay opción Insertado en el UI).
        // Aseguramos que el hidden input modal_mode_force siempre traiga value=inmediato.
        const forceModeHidden = document.getElementById('modal_mode_force');
        if (forceModeHidden) forceModeHidden.value = 'inmediato';
    } else {
        document.getElementById('modal-sched-id').value = "";
        document.getElementById('modal-time-start').value = "22:00";
        document.getElementById('modal-time-end').value = "23:00";
        document.querySelectorAll('.btn-day-toggle').forEach(btn => btn.classList.add('active'));
        document.getElementById('modal-all-days').checked = true;
        // Valor por defecto hardcodeado: siempre inmediato
        const forceModeHidden = document.getElementById('modal_mode_force');
        if (forceModeHidden) forceModeHidden.value = 'inmediato';
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
    // MODO INMEDIATO FORZADO SIEMPRE (eliminada opción Insertado/Suave).
    // NO depender del valor del hidden input del formulario; hardcodearlo.
    const mode = 'inmediato';

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
