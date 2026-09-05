<div id="view-anuncios" class="view">
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
            <div>
                <h3 style="margin:0 0 4px 0;">Pauta Comercial y Anuncios</h3>
                <p style="color:var(--text-muted); margin:0; font-size:0.85rem;">Inserta spots, cuñas y menciones sin cortar canciones abruptamente (modo suave insertado).</p>
            </div>
            <button class="btn" onclick="openAdsModal()" style="background:#0284c7; font-weight:bold; font-size:0.9rem;">+ Programar Anuncios</button>
        </div>

        <!-- Barra de Días (Lunes a Domingo) -->
        <div style="display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; border-bottom:1px solid var(--border); padding-bottom:12px;">
            <button class="ad-day-tab active" data-day="1" onclick="selectAdDayTab(1, this)">Lunes</button>
            <button class="ad-day-tab" data-day="2" onclick="selectAdDayTab(2, this)">Martes</button>
            <button class="ad-day-tab" data-day="3" onclick="selectAdDayTab(3, this)">Miércoles</button>
            <button class="ad-day-tab" data-day="4" onclick="selectAdDayTab(4, this)">Jueves</button>
            <button class="ad-day-tab" data-day="5" onclick="selectAdDayTab(5, this)">Viernes</button>
            <button class="ad-day-tab" data-day="6" onclick="selectAdDayTab(6, this)">Sábado</button>
            <button class="ad-day-tab" data-day="7" onclick="selectAdDayTab(7, this)">Domingo</button>
        </div>

        <!-- Lista de Pautas del Día -->
        <div style="border:1px solid var(--border); border-radius:8px; overflow:hidden;">
            <div style="display:flex; justify-content:space-between; padding:12px 16px; font-weight:bold; font-size:0.8rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid var(--border);">
                <span>Campaña / Playlist de Anuncios</span>
                <span>Acciones</span>
            </div>
            <div id="ads-day-list" style="padding:10px; display:flex; flex-direction:column; gap:8px;"></div>
        </div>

        <div style="text-align:right; margin-top:20px;">
            <button class="btn btn-success" onclick="persistToServer(true)">Guardar y Aplicar Pauta</button>
        </div>
    </div>
</div>

<!-- MODAL: AGREGAR / EDITAR ANUNCIO -->
<div id="modal-ad" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center; padding:15px; box-sizing:border-box;">
    <div style="border:1px solid var(--border); border-radius:12px; width:100%; max-width:650px; max-height:92vh; overflow-y:auto; padding:24px; box-sizing:border-box;">
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:10px;">
            <h4 style="margin:0; font-size:1.1rem; color:#38bdf8;">Configurar Pauta Publicitaria</h4>
            <button onclick="closeAdsModal()" style="background:transparent; border:none; color:var(--text-muted); font-size:1.4rem; cursor:pointer;">&times;</button>
        </div>

        <form id="form-ad" onsubmit="event.preventDefault(); submitAdModal();">
            <input type="hidden" id="modal-ad-id" value="">

            <!-- Selector de Playlist de Anuncios -->
            <div style="margin-bottom:16px;">
                <label style="font-size:0.8rem; color:var(--text-muted); display:block; margin-bottom:6px; font-weight:bold;">PLAYLIST DE SPOTS / CUÑAS:</label>
                <select id="modal-ad-playlist" style="width:100%; padding:8px; border-radius:6px; border:1px solid var(--border); color:#fff;" required></select>
            </div>

            <!-- Matriz de Selección de Horas (00 a 23) -->
            <div style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label style="font-size:0.8rem; color:var(--text-muted); font-weight:bold;">HORAS DE EMISIÓN:</label>
                    <label style="font-size:0.75rem; color:#38bdf8; cursor:pointer;">
                        <input type="checkbox" id="chk-all-hours" onchange="toggleAllAdHours(this.checked)"> Seleccionar Todas
                    </label>
                </div>
                <div id="grid-ad-hours" style="display:grid; grid-template-columns:repeat(8, 1fr); gap:4px;"></div>
            </div>

            <!-- Matriz de Selección de Minutos (00 a 55 de 5 en 5 + personalizados) -->
            <div style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label style="font-size:0.8rem; color:var(--text-muted); font-weight:bold;">MINUTOS EXACTOS:</label>
                    <label style="font-size:0.75rem; color:#38bdf8; cursor:pointer;">
                        <input type="checkbox" id="chk-all-minutes" onchange="toggleAllAdMinutes(this.checked)"> Cada 15 min (:00, :15, :30, :45)
                    </label>
                </div>
                <div id="grid-ad-minutes" style="display:grid; grid-template-columns:repeat(6, 1fr); gap:4px;"></div>
            </div>

            <!-- Selector de Días -->
            <div style="margin-bottom:20px;">
                <label style="font-size:0.8rem; color:var(--text-muted); display:block; margin-bottom:8px; font-weight:bold;">DÍAS DE TRANSMISIÓN:</label>
                <div style="display:flex; gap:6px; margin-bottom:8px;">
                    <button type="button" class="btn-ad-day active" data-d="1" onclick="toggleAdDayBtn(this)">L</button>
                    <button type="button" class="btn-ad-day active" data-d="2" onclick="toggleAdDayBtn(this)">M</button>
                    <button type="button" class="btn-ad-day active" data-d="3" onclick="toggleAdDayBtn(this)">X</button>
                    <button type="button" class="btn-ad-day active" data-d="4" onclick="toggleAdDayBtn(this)">J</button>
                    <button type="button" class="btn-ad-day active" data-d="5" onclick="toggleAdDayBtn(this)">V</button>
                    <button type="button" class="btn-ad-day active" data-d="6" onclick="toggleAdDayBtn(this)">S</button>
                    <button type="button" class="btn-ad-day active" data-d="7" onclick="toggleAdDayBtn(this)">D</button>
                </div>
                <label style="font-size:0.8rem; color:var(--text-muted); cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" id="modal-ad-all-days" checked onchange="toggleAllAdDays(this.checked)"> Seleccionar todos los días
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn" style="background:#334155;" onclick="closeAdsModal()">Cancelar</button>
                <button type="submit" class="btn btn-success">Guardar Pauta</button>
            </div>
        </form>
    </div>
</div>

<style>
.ad-day-tab {
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
.ad-day-tab:hover { background: rgba(255,255,255,0.05); color: #fff; }
.ad-day-tab.active { background: #0284c7; color: #fff; }

.btn-grid-unit {
    background: #0b132b;
    border: 1px solid var(--border);
    color: var(--text-muted);
    padding: 6px 2px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: bold;
    cursor: pointer;
    text-align: center;
    transition: all 0.15s;
}
.btn-grid-unit.active {
    background: #0284c7;
    color: #fff;
    border-color: #38bdf8;
}

.btn-ad-day {
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
.btn-ad-day.active {
    background: #0284c7;
    color: #fff;
    border-color: #38bdf8;
}
</style>
