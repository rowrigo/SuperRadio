// --- ESTADÍSTICAS DEL STREAM (conexiones / países / duración / en línea) ---
window.__estData = null;
window.__estPeriod = 'mes';

const EST_DV_ICON = { 0: '📱', 1: '📲', 2: '💻', 3: '🎛️', 4: '❔' };
const EST_PERIOD_LBL = { hoy: 'Hoy', ayer: 'Ayer', semana: '7 días', mes: '30 días' };

function estFmt(n) {
    n = parseInt(n || 0, 10);
    return n.toLocaleString('es');
}

window.renderEstadisticas = async function () {
    const root = document.getElementById('view-estadisticas');
    if (!root) return;
    renderPageStats(); // estadísticas del player (visitas) — independiente del stream
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

        // En línea ahora (en vivo, no depende del período)
        estSetText('est-kpi-online', estFmt(j.online));

        // Tarjetas resumen: contador de CONEXIONES para los 4 períodos
        for (const key of ['hoy', 'ayer', 'semana', 'mes']) {
            const p = j.periodos && j.periodos[key];
            const nEl = document.getElementById('est-n-' + key);
            if (p && nEl) nEl.textContent = estFmt(p.total);
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

// --- ESTADÍSTICAS DEL PLAYER (visitas a la página pública / reproductor) ---
window.renderPageStats = async function () {
    const root = document.getElementById('pst-kpi-online');
    if (!root) return;
    try {
        const res = await fetch(`autodj_api.php?action=get_page_stats&_t=${Date.now()}`);
        const j = await res.json();
        if (!j || !j.success) return;
        const asof = document.getElementById('pst-asof');
        if (asof) asof.textContent = j.as_of || '—';

        estSetText('pst-kpi-online', estFmt(j.online));

        for (const key of ['hoy', 'ayer', 'semana', 'mes']) {
            const p = j.periodos && j.periodos[key];
            if (!p) continue;
            estSetText('pst-n-' + key, estFmt(p.total));
            estSetText('pst-u-' + key, estFmt(p.unicos) + ' únicos');
        }
    } catch (e) { /* el stream sigue mostrándose aunque fallen las del player */ }
};

function drawEstBreakdown(period) {
    const data = window.__estData;
    const paisesEl = document.getElementById('est-paises');
    const dispEl = document.getElementById('est-dispositivos');
    if (!data || !data.periodos || !data.periodos[period]) return;
    const p = data.periodos[period];

    // Etiqueta del período activo
    estSetText('est-kpi-period', '(' + (EST_PERIOD_LBL[period] || '') + ')');

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
                <div class="est-row-nums">${estFmt(row.c)} <small>conexiones</small></div>
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
                <div class="est-row-nums">${estFmt(row.c)} <small>conexiones</small></div>
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
