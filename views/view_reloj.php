<div id="view-reloj" class="view">
    <div class="card">
        <!-- Encabezado -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
            <div>
                <h3 style="margin:0 0 4px 0;">Programación de Playlists</h3>
                <p style="color:var(--text-muted); margin:0; font-size:0.85rem;">
                    Organiza la parrilla semanal. El playlist "general" suena 24/7 como base.
                    &nbsp;·&nbsp; Hora oficial (actualiza cada 1s sin F5):
                    <strong class="live-clock" id="schedule-clock" style="color:#38bdf8; font-family:monospace;">--:--:--</strong>
                </p>
            </div>
            <div style="display:flex; gap:12px; align-items:center;">
                <button class="btn" onclick="openScheduleModal()" style="background:#0284c7; font-weight:bold; font-size:0.9rem;">+ Agregar Programación</button>
            </div>
        </div>

        <!-- Barra de Días (Lunes a Domingo) -->
        <div style="display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; border-bottom:1px solid var(--border); padding-bottom:12px;">
            <button class="day-tab active" data-day="1" onclick="selectDayTab(1, this)">Lunes</button>
            <button class="day-tab" data-day="2" onclick="selectDayTab(2, this)">Martes</button>
            <button class="day-tab" data-day="3" onclick="selectDayTab(3, this)">Miércoles</button>
            <button class="day-tab" data-day="4" onclick="selectDayTab(4, this)">Jueves</button>
            <button class="day-tab" data-day="5" onclick="selectDayTab(5, this)">Viernes</button>
            <button class="day-tab" data-day="6" onclick="selectDayTab(6, this)">Sábado</button>
            <button class="day-tab" data-day="7" onclick="selectDayTab(7, this)">Domingo</button>
        </div>

        <!-- Tabla / Lista de Eventos Programados del Día -->
        <div style="border:1px solid var(--border); border-radius:8px; overflow:hidden;">
            <div style="display:flex; justify-content:space-between; padding:12px 16px; font-weight:bold; font-size:0.8rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid var(--border);">
                <span>Detalle de Transmisión</span>
                <span>Acciones</span>
            </div>
            <div id="schedule-day-list" style="padding:10px; display:flex; flex-direction:column; gap:8px;"></div>
        </div>

        <div style="text-align:right; margin-top:20px;">
            <button class="btn btn-success" onclick="saveData()">Guardar y Aplicar al AutoDJ</button>
        </div>
    </div>
</div>

<!-- MODAL: AGREGAR / EDITAR PROGRAMACIÓN -->
<div id="modal-schedule" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center; padding:15px; box-sizing:border-box;">
    <div style="border:1px solid var(--border); border-radius:12px; width:100%; max-width:550px; max-height:92vh; overflow-y:auto; padding:24px; box-sizing:border-box;">
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:10px;">
            <h4 style="margin:0; font-size:1.1rem; color:#38bdf8;">Agregar Programación</h4>
            <button onclick="closeScheduleModal()" style="background:transparent; border:none; color:var(--text-muted); font-size:1.4rem; cursor:pointer;">&times;</button>
        </div>

        <form id="form-schedule" onsubmit="event.preventDefault(); var _prom = typeof submitScheduleModal === 'function' ? submitScheduleModal() : Promise.resolve(); if (_prom && typeof _prom.catch === 'function') _prom.catch(function(e){ console.error('submitScheduleModal error:', e); }); return false;">
            <input type="hidden" id="modal-sched-id" value="">

            <!-- Selector de Playlist -->
            <div style="margin-bottom:16px;">
                <label style="font-size:0.8rem; color:var(--text-muted); display:block; margin-bottom:6px; font-weight:bold;">PLAYLIST:</label>
                <select id="modal-sel-pl" style="width:100%;" required></select>
            </div>

            <!-- Horarios de Inicio y Fin -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
                <div>
                    <label style="font-size:0.8rem; color:var(--text-muted); display:block; margin-bottom:6px; font-weight:bold;">HORA INICIO:</label>
                    <input type="time" id="modal-time-start" style="width:100%;" required>
                </div>
                <div>
                    <label style="font-size:0.8rem; color:var(--text-muted); display:block; margin-bottom:6px; font-weight:bold;">HORA FIN:</label>
                    <input type="time" id="modal-time-end" style="width:100%;" required>
                </div>
            </div>

            <!-- Selector de Días -->
            <div style="margin-bottom:18px;">
                <label style="font-size:0.8rem; color:var(--text-muted); display:block; margin-bottom:8px; font-weight:bold;">DÍAS DE EMISIÓN:</label>
                <div style="display:flex; gap:6px; margin-bottom:8px;">
                    <button type="button" class="btn-day-toggle active" data-d="1" onclick="toggleDayBtn(this)">L</button>
                    <button type="button" class="btn-day-toggle active" data-d="2" onclick="toggleDayBtn(this)">M</button>
                    <button type="button" class="btn-day-toggle active" data-d="3" onclick="toggleDayBtn(this)">X</button>
                    <button type="button" class="btn-day-toggle active" data-d="4" onclick="toggleDayBtn(this)">J</button>
                    <button type="button" class="btn-day-toggle active" data-d="5" onclick="toggleDayBtn(this)">V</button>
                    <button type="button" class="btn-day-toggle active" data-d="6" onclick="toggleDayBtn(this)">S</button>
                    <button type="button" class="btn-day-toggle active" data-d="7" onclick="toggleDayBtn(this)">D</button>
                </div>
                <label style="font-size:0.8rem; color:var(--text-muted); cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" id="modal-all-days" checked onchange="toggleAllDays(this.checked)"> Seleccionar todos los días
                </label>
            </div>

            <!-- Modo de Inicio (FORZADO SIEMPRE: Inmediato / Corte) -->
            <div style="margin-bottom:24px; padding:14px; border-radius:8px; border:1px solid var(--border); border-left:4px solid #ef4444;">
                <label style="font-size:0.8rem; color:#ef4444; display:block; margin-bottom:10px; font-weight:bold;">MODO DE INICIO (Siempre Activo):</label>
                <div style="display:flex; flex-direction:column; gap:0;">
                    <div style="display:flex; align-items:flex-start; gap:8px; font-size:0.85rem;">
                        <div>
                            <strong style="color:#ef4444;">Inmediato (Corte Exacto):</strong> Corta la reproducción actual EXACTAMENTE a la hora en punto (máx. 5 seg.) e inicia el bloque programado.<br>
                            <span style="font-size:0.78rem; color:var(--text-muted);">Aplicado automáticamente a TODAS las programaciones. Ideal para noticias, programas fijos, Top 10, bloques que tienen que sonar SÍ o SÍ a la hora.</span>
                        </div>
                    </div>
                </div>
                <!-- Campo oculto: fuerza SIEMPRE mode=inmediato en el submit -->
                <input type="hidden" name="modal_mode" value="inmediato" id="modal_mode_force">
            </div>

            <!-- Botones de Acción -->
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn" style="background:#334155;" onclick="closeScheduleModal()">Cancelar</button>
                <button type="submit" class="btn btn-success">Guardar Programación</button>
            </div>
        </form>
    </div>
</div>

<style>
.day-tab {
    background: transparent;
    border: none;
    color: var(--text-muted);
    padding: 8px 16px;
    border-radius: 6px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.9rem;
}
.day-tab:hover { background: rgba(255,255,255,0.05); color: #fff; }
.day-tab.active { background: #0284c7; color: #fff; }

.btn-day-toggle {
    flex: 1;
    padding: 8px 0;
    border: 1px solid var(--border);
    background: #0b132b;
    color: var(--text-muted);
    border-radius: 6px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-day-toggle.active {
    background: #0284c7;
    color: #fff;
    border-color: #38bdf8;
}
</style>
