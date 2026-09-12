// --- ESTADÍSTICAS DEL STREAM (conexiones / países / duración / pico) ---
window.__estData = null;
window.__estPeriod = 'mes';

const EST_DV_ICON = { 0: '📱', 1: '📲', 2: '💻', 3: '🎛️', 4: '❔' };
const EST_PERIOD_LBL = { hoy: 'Hoy', ayer: 'Ayer', semana: '7 días', mes: '30 días' };

function estFmt(n) {
    n = parseInt(n || 0, 10);
    return n.toLocaleString('es');
}

function estFmtDur(sec) {
    sec = parseInt(sec || 0, 10);
    if (sec <= 0) return '—';
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    if (h > 0) return h + ' h ' + m + ' m';
    if (m > 0) return m + ' m ' + s + ' s';
    return s + ' s';
}

window.renderEstadisticas = async function () {
    const root = document.getElementById('view-estadisticas');
    if (!root) return;
    const paisesEl = document.getElementById('est-paises');
    const dispEl = document.getElementById('est-dispositivos');
    try {
        if (paisesEl) paisesEl.innerHTML = '<div style="color:var(--text-muted); font-size:0.85rem;">Actualizando…</div>';
        if (dispEl) dispEl.innerHTML = '';
        const res = await fetch(`autodj_api.php?action=get_listener_stats&_t=${Date.now()}`);
        const j = await res.json();
        if (!j || !j.success) {
            throw new Error((j && j.error) || 'Respuesta no válida');
        }
        window.__estData = j;
        const asof = document.getElementById('est-asof');
        if (asof) asof.textContent = j.as_of || '—';

        // Tarjetas resumen (conexiones + únicos/pico) para los 4 períodos
        for (const key of ['hoy', 'ayer', 'semana', 'mes']) {
            const p = j.periodos && j.periodos[key];
            const nEl = document.getElementById('est-n-' + key);
            const uEl = document.getElementById('est-u-' + key);
            if (p) {
                if (nEl) nEl.textContent = estFmt(p.total);
                if (uEl) uEl.textContent = estFmt(p.unicos) + ' únicos · pico ' + estFmt(p.pico);
            }
        }

        // Activar el último período y pintar el desglose
        const activeBtn = document.querySelector('#est-tabs .est-tab.active');
        window.__estPeriod = activeBtn ? activeBtn.dataset.period : 'mes';
        drawEstBreakdown(window.__estPeriod);
    } catch (e) {
        if (paisesEl) paisesEl.innerHTML = `<div style="color:#f87171; font-size:0.85rem;">Error al cargar estadísticas: ${String(e.message || e).slice(0, 200)}</div>`;
        if (dispEl) dispEl.innerHTML = '';
        const asof = document.getElementById('est-asof');
        if (asof) asof.textContent = '—';
    }
};

function estSetText(id, txt) {
    const el = document.getElementById(id);
    if (el) el.textContent = txt;
}

function drawEstBreakdown(period) {
    const data = window.__estData;
    const paisesEl = document.getElementById('est-paises');
    const dispEl = document.getElementById('est-dispositivos');
    if (!data || !data.periodos || !data.periodos[period]) return;
    const p = data.periodos[period];

    // KPIs del período activo
    estSetText('est-kpi-period', '(' + (EST_PERIOD_LBL[period] || '') + ')');
    estSetText('est-kpi-pico', estFmt(p.pico));
    estSetText('est-kpi-dur', estFmtDur(p.dur_total));
    estSetText('est-kpi-media', estFmtDur(p.dur_media));
    estSetText('est-kpi-unicos', estFmt(p.unicos));

    if (!paisesEl || !dispEl) return;
    if (!p.total) {
        paisesEl.innerHTML = '<div style="color:var(--text-muted); font-size:0.85rem;">Sin conexiones en este período. Los datos se registran desde que el stream recibe oyentes.</div>';
        dispEl.innerHTML = '';
        return;
    }

    // Países: top 15 + resto agrupado
    const paises = p.paises || [];
    const topPaises = paises.slice(0, 15);
    const restPaises = paises.slice(15).reduce((a, x) => a + x.c, 0);
    const maxP = Math.max(1, ...topPaises.map(x => x.c));
    paisesEl.innerHTML = '';
    topPaises.forEach((row, i) => {
        const bar = document.createElement('div');
        bar.className = 'est-row';
        bar.innerHTML = `
            <div class="est-row-head">
                <div class="est-row-name"><span style="color:#64748b; min-width:14px;">${i + 1}</span>${row.nombre}<span class="cc">${row.cc}</span></div>
                <div class="est-row-nums">${estFmt(row.c)} <small>conexiones · ${estFmt(row.u)} únicos</small></div>
            </div>
            <div class="est-bar"><i style="width:${Math.max(2, Math.round((row.c / maxP) * 100))}%"></i></div>`;
        paisesEl.appendChild(bar);
    });
    if (restPaises > 0) {
        const extra = document.createElement('div');
        extra.style.cssText = 'color:#64748b; font-size:0.75rem; padding:2px 2px;';
        extra.textContent = `… y ${estFmt(restPaises)} conexiones de ${paises.length - 15} países más.`;
        paisesEl.appendChild(extra);
    }
    if (!paises.length) paisesEl.innerHTML = '<div style="color:var(--text-muted); font-size:0.85rem;">Sin datos de país.</div>';

    // Dispositivos
    const dispositivos = p.dispositivos || [];
    const maxD = Math.max(1, ...dispositivos.map(x => x.c));
    dispEl.innerHTML = '';
    dispositivos.forEach(row => {
        const icon = EST_DV_ICON[row.dv] || '❔';
        const bar = document.createElement('div');
        bar.className = 'est-row';
        bar.innerHTML = `
            <div class="est-row-head">
                <div class="est-row-name"><span style="font-size:1rem;">${icon}</span>${row.nombre}</div>
                <div class="est-row-nums">${estFmt(row.c)} <small>conexiones · ${estFmt(row.u)} únicos</small></div>
            </div>
            <div class="est-bar"><i style="width:${Math.max(2, Math.round((row.c / maxD) * 100))}%"></i></div>`;
        dispEl.appendChild(bar);
    });
    if (!dispositivos.length) dispEl.innerHTML = '<div style="color:var(--text-muted); font-size:0.85rem;">Sin datos de dispositivo.</div>';
}

window.setEstPeriod = function (period, btn) {
    window.__estPeriod = period;
    document.querySelectorAll('#est-tabs .est-tab').forEach(t => t.classList.toggle('active', t === btn));
    drawEstBreakdown(period);
};
