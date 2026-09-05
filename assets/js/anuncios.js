let currentAdDay = 7; // Domingo por defecto

function initAdsGrid() {
    const gridHours = document.getElementById('grid-ad-hours');
    const gridMinutes = document.getElementById('grid-ad-minutes');

    if (gridHours && gridHours.children.length === 0) {
        for (let h = 0; h < 24; h++) {
            const hStr = h.toString().padStart(2, '0');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-grid-unit btn-ad-hour';
            btn.dataset.hour = hStr;
            btn.innerText = hStr;
            btn.onclick = () => btn.classList.toggle('active');
            gridHours.appendChild(btn);
        }
    }

    if (gridMinutes && gridMinutes.children.length === 0) {
        // Minutos de 5 en 5 (00, 05, 10, ... 55)
        for (let m = 0; m < 60; m += 5) {
            const mStr = m.toString().padStart(2, '0');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-grid-unit btn-ad-minute';
            btn.dataset.minute = mStr;
            btn.innerText = `:${mStr}`;
            btn.onclick = () => btn.classList.toggle('active');
            gridMinutes.appendChild(btn);
        }
    }
}

function selectAdDayTab(dayNum, btn) {
    currentAdDay = parseInt(dayNum);
    document.querySelectorAll('.ad-day-tab').forEach(t => t.classList.remove('active'));
    if (btn) btn.classList.add('active');
    renderAdsView();
}

function renderAdsView() {
    const list = document.getElementById('ads-day-list');
    if (!list) return;
    list.innerHTML = '';

    const ads = appData.ads || [];
    const dayAds = ads.filter(a => {
        const days = a.days || [1,2,3,4,5,6,7];
        return days.includes(currentAdDay);
    });

    if (dayAds.length === 0) {
        list.innerHTML = '<div style="color:var(--text-muted); padding:16px; text-align:center;">No hay pauta publicitaria programada para este día.</div>';
        return;
    }

    const dayLabels = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

    dayAds.forEach(a => {
        const realIdx = appData.ads.indexOf(a);
        const hoursList = (a.hours || []).join(', ');
        const minsList = (a.minutes || []).map(m => `:${m}`).join(', ');
        const daysBadges = (a.days || [1,2,3,4,5,6,7]).map(d => `<strong style="color:#f59e0b; margin-right:2px;">${dayLabels[d-1]}</strong>`).join(' ');

        list.innerHTML += `
        <div style="background:#080e1e; border:1px solid var(--border); border-radius:6px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div>
                <div style="font-size:0.95rem; font-weight:bold; color:#fff; margin-bottom:4px;">
                    Campaña: <span style="color:#38bdf8;">${a.playlist}</span>
                </div>
                <div style="font-size:0.8rem; color:var(--text-muted); line-height:1.4;">
                    <div>Horas activas: <strong style="color:#4ade80;">${hoursList}</strong></div>
                    <div>Minutos: <strong style="color:#38bdf8;">${minsList}</strong></div>
                    <div>Días: ${daysBadges}</div>
                </div>
            </div>
            <div style="display:flex; gap:6px;">
                <button class="btn btn-sm" style="background:#0284c7;" onclick="editAdItem(${realIdx})">✏️</button>
                <button class="btn btn-danger btn-sm" onclick="deleteAdItem(${realIdx})">✖</button>
            </div>
        </div>`;
    });
}

function openAdsModal(editIndex = null) {
    initAdsGrid();
    const modal = document.getElementById('modal-ad');
    const selPl = document.getElementById('modal-ad-playlist');
    if (!modal || !selPl) return;

    selPl.innerHTML = '';
    Object.keys(appData.playlists).forEach(p => {
        const pType = appData.playlists[p].tipo === 'archivos' ? '🎵' : '📁';
        selPl.innerHTML += `<option value="${p}">${pType} ${p}</option>`;
    });

    if (selPl.options.length === 0) {
        return alert("Primero debes crear un playlist con tus spots o cuñas (en la pestaña Playlists).");
    }

    // Limpiar botones
    document.querySelectorAll('.btn-ad-hour').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.btn-ad-minute').forEach(b => b.classList.remove('active'));

    if (editIndex !== null) {
        const item = appData.ads[editIndex];
        document.getElementById('modal-ad-id').value = editIndex;
        selPl.value = item.playlist;

        (item.hours || []).forEach(h => {
            const btn = document.querySelector(`.btn-ad-hour[data-hour="${h}"]`);
            if (btn) btn.classList.add('active');
        });

        (item.minutes || []).forEach(m => {
            const btn = document.querySelector(`.btn-ad-minute[data-minute="${m}"]`);
            if (btn) btn.classList.add('active');
        });

        const days = item.days || [1,2,3,4,5,6,7];
        document.querySelectorAll('.btn-ad-day').forEach(btn => {
            const d = parseInt(btn.dataset.d);
            btn.classList.toggle('active', days.includes(d));
        });
        document.getElementById('modal-ad-all-days').checked = (days.length === 7);
    } else {
        document.getElementById('modal-ad-id').value = "";
        // Activar por defecto horas laborales y minutos :00, :30
        ['08','09','10','11','12','13','14','15','16','17','18'].forEach(h => {
            const b = document.querySelector(`.btn-ad-hour[data-hour="${h}"]`);
            if (b) b.classList.add('active');
        });
        ['00', '30'].forEach(m => {
            const b = document.querySelector(`.btn-ad-minute[data-minute="${m}"]`);
            if (b) b.classList.add('active');
        });
        document.querySelectorAll('.btn-ad-day').forEach(btn => btn.classList.add('active'));
        document.getElementById('modal-ad-all-days').checked = true;
    }

    modal.style.display = 'flex';
}

function closeAdsModal() {
    const modal = document.getElementById('modal-ad');
    if (modal) modal.style.display = 'none';
}

function toggleAllAdHours(isChecked) {
    document.querySelectorAll('.btn-ad-hour').forEach(b => b.classList.toggle('active', isChecked));
}

function toggleAllAdMinutes(isChecked) {
    document.querySelectorAll('.btn-ad-minute').forEach(b => {
        const m = b.dataset.minute;
        if (['00', '15', '30', '45'].includes(m)) {
            b.classList.toggle('active', isChecked);
        }
    });
}

function toggleAdDayBtn(btn) {
    btn.classList.toggle('active');
    const totalActive = document.querySelectorAll('.btn-ad-day.active').length;
    document.getElementById('modal-ad-all-days').checked = (totalActive === 7);
}

function toggleAllAdDays(isChecked) {
    document.querySelectorAll('.btn-ad-day').forEach(btn => btn.classList.toggle('active', isChecked));
}

async function submitAdModal() {
    const idVal = document.getElementById('modal-ad-id').value;
    const pl = document.getElementById('modal-ad-playlist').value;

    const selHours = [];
    document.querySelectorAll('.btn-ad-hour.active').forEach(b => selHours.push(b.dataset.hour));

    const selMinutes = [];
    document.querySelectorAll('.btn-ad-minute.active').forEach(b => selMinutes.push(b.dataset.minute));

    const selDays = [];
    document.querySelectorAll('.btn-ad-day.active').forEach(b => selDays.push(parseInt(b.dataset.d)));

    if (selHours.length === 0) return alert("Debes seleccionar al menos una hora.");
    if (selMinutes.length === 0) return alert("Debes seleccionar al menos un minuto.");
    if (selDays.length === 0) return alert("Debes seleccionar al menos un día.");

    const payload = {
        playlist: pl,
        hours: selHours,
        minutes: selMinutes,
        days: selDays
    };

    if (!appData.ads) appData.ads = [];

    if (idVal !== "") {
        appData.ads[parseInt(idVal)] = payload;
    } else {
        appData.ads.push(payload);
    }

    closeAdsModal();
    renderAdsView();
    await persistToServer(true);
}

function editAdItem(idx) {
    openAdsModal(idx);
}

async function deleteAdItem(idx) {
    if (!confirm("¿Eliminar esta pauta comercial?")) return;
    appData.ads.splice(idx, 1);
    renderAdsView();
    await persistToServer();
}
