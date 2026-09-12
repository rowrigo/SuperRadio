<div id="view-estadisticas" class="view">
    <div class="card" style="border:1px solid var(--border); border-radius:12px; background:var(--card-bg,#0d1526); padding:20px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
            <div>
                <h3 style="margin:0 0 4px 0; color:#fff;"><i class="fa-solid fa-chart-column" style="color:#38bdf8; margin-right:8px;"></i>Estadísticas del Stream</h3>
                <div style="font-size:0.8rem; color:var(--text-muted);">Conexiones a la señal de audio · Actualizado: <span id="est-asof">—</span></div>
            </div>
            <button type="button" class="btn btn-sm" id="est-refresh" onclick="renderEstadisticas(true)"
                    style="background:#0284c7; color:#fff; padding:8px 14px; border:none; border-radius:6px; font-weight:bold; cursor:pointer;">
                <i class="fa-solid fa-rotate-right" style="margin-right:5px;"></i>Actualizar
            </button>
        </div>

        <!-- Tarjetas: en línea ahora + contador de CONEXIONES por período -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr)); gap:12px; margin-bottom:18px;">
            <div class="est-card" data-p="online" style="background:#052e1a; border:1px solid #16a34a; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#86efac; font-weight:700;"><i class="fa-solid fa-tower-broadcast" style="margin-right:4px;"></i>En línea ahora</div>
                <div style="font-size:1.6rem; font-weight:800; color:#4ade80; margin:4px 0;" id="est-kpi-online">—</div>
            </div>
            <div class="est-card" data-p="hoy" style="background:#0b132b; border:1px solid #1e293b; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; font-weight:700;">Hoy</div>
                <div style="font-size:1.6rem; font-weight:800; color:#4ade80; margin:4px 0;" id="est-n-hoy">—</div>
            </div>
            <div class="est-card" data-p="ayer" style="background:#0b132b; border:1px solid #1e293b; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; font-weight:700;">Ayer</div>
                <div style="font-size:1.6rem; font-weight:800; color:#38bdf8; margin:4px 0;" id="est-n-ayer">—</div>
            </div>
            <div class="est-card" data-p="semana" style="background:#0b132b; border:1px solid #1e293b; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; font-weight:700;">7 días</div>
                <div style="font-size:1.6rem; font-weight:800; color:#c084fc; margin:4px 0;" id="est-n-semana">—</div>
            </div>
            <div class="est-card" data-p="mes" style="background:#0b132b; border:1px solid #1e293b; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; font-weight:700;">30 días</div>
                <div style="font-size:1.6rem; font-weight:800; color:#f59e0b; margin:4px 0;" id="est-n-mes">—</div>
            </div>
        </div>

        <!-- Desglose por país / dispositivo -->
        <div style="border-top:1px solid var(--border); padding-top:16px;">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                <strong style="color:#e2e8f0; font-size:0.95rem;"><i class="fa-solid fa-earth-americas" style="color:#4ade80; margin-right:6px;"></i>¿De dónde y con qué nos escuchan? <span id="est-kpi-period" style="color:#64748b; font-weight:600; font-size:0.8rem;"></span></strong>
                <div id="est-tabs" style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" class="est-tab" data-period="hoy" onclick="setEstPeriod('hoy',this)">Hoy</button>
                    <button type="button" class="est-tab" data-period="ayer" onclick="setEstPeriod('ayer',this)">Ayer</button>
                    <button type="button" class="est-tab" data-period="semana" onclick="setEstPeriod('semana',this)">7 días</button>
                    <button type="button" class="est-tab active" data-period="mes" onclick="setEstPeriod('mes',this)">30 días</button>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:18px;" class="est-cols">
                <div style="min-width:0;">
                    <div style="font-size:0.8rem; color:#94a3b8; font-weight:700; margin-bottom:8px;">🌍 Por país</div>
                    <div id="est-paises" style="display:flex; flex-direction:column; gap:8px;">
                        <div style="color:var(--text-muted); font-size:0.85rem;">Cargando…</div>
                    </div>
                </div>
                <div style="min-width:0;">
                    <div style="font-size:0.8rem; color:#94a3b8; font-weight:700; margin-bottom:8px;">📱 Por dispositivo</div>
                    <div id="est-dispositivos" style="display:flex; flex-direction:column; gap:8px;">
                        <div style="color:var(--text-muted); font-size:0.85rem;">Cargando…</div>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-top:16px; padding-top:12px; border-top:1px dashed #1e293b; font-size:0.72rem; color:#64748b;">
            <i class="fa-solid fa-shield-halved" style="margin-right:5px;"></i>
            Cada casilla cuenta las <strong>conexiones a la señal de audio</strong> del período (cada vez que un dispositivo conecta al
            enlace del stream, sin importar la IP ni cuántos equipos haya en la misma red). Si un reproductor se reconecta, cuenta como
            una conexión nueva. <strong>"En línea ahora"</strong> son los que están escuchando en este momento (dato en vivo de Icecast).
            No se guardan direcciones IP: solo un hash, país y dispositivo.
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- ESTADÍSTICAS DEL PLAYER (visitas a la página pública)         -->
    <!-- ============================================================= -->
    <div class="card" style="border:1px solid var(--border); border-radius:12px; background:var(--card-bg,#0d1526); padding:20px; margin-top:18px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
            <div>
                <h3 style="margin:0 0 4px 0; color:#fff;"><i class="fa-solid fa-globe" style="color:#34d399; margin-right:8px;"></i>Estadísticas del Player</h3>
                <div style="font-size:0.8rem; color:var(--text-muted);">Visitas a la página pública / reproductor · Actualizado: <span id="pst-asof">—</span></div>
            </div>
        </div>

        <!-- Tarjetas: en línea ahora + visitas por período -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr)); gap:12px; margin-bottom:18px;">
            <div class="pst-card" data-p="online" style="background:#052e1a; border:1px solid #16a34a; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#86efac; font-weight:700;"><i class="fa-solid fa-user-clock" style="margin-right:4px;"></i>En línea ahora</div>
                <div style="font-size:1.6rem; font-weight:800; color:#4ade80; margin:4px 0;" id="pst-kpi-online">—</div>
            </div>
            <div class="pst-card" data-p="hoy" style="background:#0b132b; border:1px solid #1e293b; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; font-weight:700;">Hoy</div>
                <div style="font-size:1.6rem; font-weight:800; color:#4ade80; margin:4px 0;" id="pst-n-hoy">—</div>
                <div id="pst-u-hoy" style="font-size:0.7rem; color:#64748b;">—</div>
            </div>
            <div class="pst-card" data-p="ayer" style="background:#0b132b; border:1px solid #1e293b; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; font-weight:700;">Ayer</div>
                <div style="font-size:1.6rem; font-weight:800; color:#38bdf8; margin:4px 0;" id="pst-n-ayer">—</div>
                <div id="pst-u-ayer" style="font-size:0.7rem; color:#64748b;">—</div>
            </div>
            <div class="pst-card" data-p="semana" style="background:#0b132b; border:1px solid #1e293b; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; font-weight:700;">7 días</div>
                <div style="font-size:1.6rem; font-weight:800; color:#c084fc; margin:4px 0;" id="pst-n-semana">—</div>
                <div id="pst-u-semana" style="font-size:0.7rem; color:#64748b;">—</div>
            </div>
            <div class="pst-card" data-p="mes" style="background:#0b132b; border:1px solid #1e293b; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; font-weight:700;">30 días</div>
                <div style="font-size:1.6rem; font-weight:800; color:#f59e0b; margin:4px 0;" id="pst-n-mes">—</div>
                <div id="pst-u-mes" style="font-size:0.7rem; color:#64748b;">—</div>
            </div>
        </div>

        <div style="padding-top:12px; border-top:1px dashed #1e293b; font-size:0.72rem; color:#64748b;">
            <i class="fa-solid fa-eye" style="margin-right:5px;"></i>
            Contador de <strong>visitas a la página pública</strong> (cada vez que alguien abre el reproductor). Es independiente de las
            <strong>conexiones al stream</strong> de arriba: aquí se cuenta la <em>página</em>, no la señal de audio. <strong>"En línea ahora"</strong>
            son las personas con la página abierta en este momento y <strong>"únicos"</strong> son visitantes distintos (por día). No se guardan IPs.
        </div>
    </div>
</div>

<style>
.est-tab {
    background: #0b132b; color: #94a3b8; border: 1px solid #1e293b; padding: 6px 12px;
    border-radius: 999px; font-size: 0.78rem; font-weight: 700; cursor: pointer; transition: all .15s;
}
.est-tab:hover { color:#fff; border-color:#334155; }
.est-tab.active { background:#0284c7; border-color:#0284c7; color:#fff; }
.est-row { background:#0b132b; border:1px solid #1e293b; border-radius:8px; padding:9px 12px; }
.est-row-head { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:6px; }
.est-row-name { font-size:0.88rem; font-weight:700; color:#e2e8f0; display:flex; align-items:center; gap:6px; min-width:0; }
.est-row-name .cc { font-size:0.62rem; background:#152138; color:#64748b; padding:2px 6px; border-radius:4px; font-weight:800; letter-spacing:.5px; }
.est-row-nums { font-size:0.8rem; color:#4ade80; font-weight:800; white-space:nowrap; }
.est-row-nums small { color:#64748b; font-weight:600; margin-left:6px; }
.est-bar { height:5px; background:#1e293b; border-radius:99px; overflow:hidden; }
.est-bar i { display:block; height:100%; background:linear-gradient(90deg,#0284c7,#4ade80); border-radius:99px; }
@media (max-width: 760px){ .est-cols { grid-template-columns: 1fr; } }
</style>
