<div id="view-estadisticas" class="view">
    <div class="card" style="border:1px solid var(--border); border-radius:12px; background:var(--card-bg,#0d1526); padding:20px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
            <div>
                <h3 style="margin:0 0 4px 0; color:#fff;"><i class="fa-solid fa-chart-column" style="color:#38bdf8; margin-right:8px;"></i>Estadísticas de Oyentes</h3>
                <div style="font-size:0.8rem; color:var(--text-muted);">Actualizado: <span id="est-asof">—</span></div>
            </div>
            <button type="button" class="btn btn-sm" id="est-refresh" onclick="renderEstadisticas(true)"
                    style="background:#0284c7; color:#fff; padding:8px 14px; border:none; border-radius:6px; font-weight:bold; cursor:pointer;">
                <i class="fa-solid fa-rotate-right" style="margin-right:5px;"></i>Actualizar
            </button>
        </div>

        <!-- Tarjetas resumen por período -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr)); gap:12px; margin-bottom:20px;">
            <div class="est-card" data-p="hoy" style="background:#0b132b; border:1px solid #1e293b; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; font-weight:700;">Hoy</div>
                <div style="font-size:1.6rem; font-weight:800; color:#4ade80; margin:4px 0;" id="est-n-hoy">—</div>
                <div style="font-size:0.75rem; color:#64748b;" id="est-u-hoy"></div>
            </div>
            <div class="est-card" data-p="ayer" style="background:#0b132b; border:1px solid #1e293b; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; font-weight:700;">Ayer</div>
                <div style="font-size:1.6rem; font-weight:800; color:#38bdf8; margin:4px 0;" id="est-n-ayer">—</div>
                <div style="font-size:0.75rem; color:#64748b;" id="est-u-ayer"></div>
            </div>
            <div class="est-card" data-p="semana" style="background:#0b132b; border:1px solid #1e293b; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; font-weight:700;">7 días</div>
                <div style="font-size:1.6rem; font-weight:800; color:#c084fc; margin:4px 0;" id="est-n-semana">—</div>
                <div style="font-size:0.75rem; color:#64748b;" id="est-u-semana"></div>
            </div>
            <div class="est-card" data-p="mes" style="background:#0b132b; border:1px solid #1e293b; border-radius:10px; padding:14px;">
                <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8; font-weight:700;">30 días</div>
                <div style="font-size:1.6rem; font-weight:800; color:#f59e0b; margin:4px 0;" id="est-n-mes">—</div>
                <div style="font-size:0.75rem; color:#64748b;" id="est-u-mes"></div>
            </div>
        </div>

        <!-- Desglose por país / dispositivo -->
        <div style="border-top:1px solid var(--border); padding-top:16px;">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                <strong style="color:#e2e8f0; font-size:0.95rem;"><i class="fa-solid fa-earth-americas" style="color:#4ade80; margin-right:6px;"></i>¿De dónde y con qué nos escuchan?</strong>
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
            Privacidad: no se guardan direcciones IP, solo país y tipo de dispositivo. "Conexión" = cada sesión de audio (al abrir el reproductor o reconectar); "únicos" = oyentes distintos por período.
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
