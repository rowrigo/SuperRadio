<div id="view-ajustes" class="view">
    <div class="card">
        <h3 style="margin: 0 0 20px 0;">Configuración General del AutoDJ</h3>

        <form id="form-ajustes" onsubmit="event.preventDefault(); saveAjustes();">
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: bold; margin-bottom: 6px; font-size: 0.85rem; color: #38bdf8;">ZONA HORARIA DEL SERVIDOR:</label>
                <select id="set-timezone" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border); color: #fff;">
                    <option value="America/Costa_Rica">America/Costa_Rica (UTC -6)</option>
                    <option value="America/Mexico_City">America/Mexico_City (UTC -6)</option>
                    <option value="America/Bogota">America/Bogota (UTC -5)</option>
                    <option value="America/New_York">America/New_York (UTC -5 / -4)</option>
                    <option value="America/Argentina/Buenos_Aires">America/Buenos_Aires (UTC -3)</option>
                    <option value="Europe/Madrid">Europe/Madrid (UTC +1 / +2)</option>
                </select>
            </div>

            <div style="margin-bottom: 24px;">
                <label style="display: block; font-weight: bold; margin-bottom: 6px; font-size: 0.85rem; color: #38bdf8;">PLAYLIST BASE POR DEFECTO (24/7):</label>
                <select id="set-default-playlist" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border); color: #fff;" required></select>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--border); margin: 24px 0;">

            <div id="card-intercalators" style="border:1px solid rgba(34,197,94,0.25); border-radius:10px; padding:16px 18px 20px; margin-bottom:24px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:14px;">
                    <div style="flex:1; min-width:300px;">
                        <h4 style="margin:0; font-size:0.98rem; color:#4ade80;"><i class="fa-solid fa-layer-group" style="margin-right:6px;"></i>Intercaladores</h4>
                    </div>
                </div>

                <div style="border:1px solid var(--border); border-radius:8px; padding:12px 14px; margin-bottom:14px;">
                    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
                        <div style="flex:1 1 220px; min-width:200px;">
                            <label style="display:block; font-weight:bold; font-size:0.78rem; color:var(--text-muted); margin-bottom:5px;">CARPETA:</label>
                            <select id="int-add-folder" style="width:100%; padding:8px 10px; border-radius:6px; border:1px solid var(--border); color:#fff;"></select>
                        </div>
                        <div style="flex:0 0 160px; min-width:140px;">
                            <label style="display:block; font-weight:bold; font-size:0.78rem; color:var(--text-muted); margin-bottom:5px;">FRECUENCIA:</label>
                            <select id="int-add-type" onchange="onIntTypeChanged(this.value)" style="width:100%; padding:8px 10px; border-radius:6px; border:1px solid var(--border); color:#fff;">
                                <option value="songs">Cada N canciones</option>
                                <option value="minutes">Cada N minutos</option>
                            </select>
                        </div>
                        <div style="flex:0 0 240px; min-width:200px;">
                            <label style="display:block; font-weight:bold; font-size:0.78rem; color:var(--text-muted); margin-bottom:5px;">MODO:</label>
                            <select id="int-add-play-mode" style="width:100%; padding:8px 10px; border-radius:6px; border:1px solid var(--border); color:#fff;">
                                <option value="single_random">1 canción aleatoria</option>
                                <option value="whole_folder_seq">Carpeta completa orden</option>
                            </select>
                        </div>
                        <div style="flex:0 0 140px; min-width:120px;">
                            <label id="int-add-value-label" style="display:block; font-weight:bold; font-size:0.78rem; color:var(--text-muted); margin-bottom:5px;">Valor N:</label>
                            <input type="number" id="int-add-value" value="3" min="1" max="240" step="1"
                                   style="width:100%; padding:8px 10px; border-radius:6px; border:1px solid var(--border); color:#fff; font-weight:bold;">
                        </div>
                        <div style="flex:0 0 auto; align-self:stretch; display:flex; align-items:flex-end;">
                            <button type="button" class="btn btn-success" onclick="addIntercalator()" style="padding:8px 16px; height:38px;">
                                <i class="fa-solid fa-plus" style="margin-right:4px;"></i> Añadir
                            </button>
                        </div>
                    </div>
                </div>

                <div id="int-list" style="display:flex; flex-direction:column; gap:8px;"></div>
                <div id="int-empty" style="display:none; padding:14px; text-align:center; border:1px dashed rgba(148,163,184,0.3); border-radius:8px; font-size:0.82rem; color:var(--text-muted);">
                    No hay reglas.
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--border); margin: 24px 0;">

            <div style="border: 1px solid var(--border); border-radius: 8px; padding: 16px; margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <strong style="color: #4ade80; font-size: 0.95rem;">Locución Automática de la Hora (:00)</strong>
                    <label style="position: relative; display: inline-block; width: 48px; height: 24px;">
                        <input type="checkbox" id="set-time-voice-enabled" style="opacity: 0; width: 0; height: 0;" onchange="toggleTimeVoiceOptions(this.checked)">
                        <span style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #334155; transition: .3s; border-radius: 24px;" id="slider-voice"></span>
                    </label>
                </div>

                <div id="box-time-voice-config" style="display: none; border-top: 1px solid var(--border); padding-top: 12px; margin-top: 10px;">
                    <label style="display: block; font-weight: bold; margin-bottom: 6px; font-size: 0.8rem; color: var(--text-muted);">CARPETA AUDIOS HORA:</label>
                    <select id="set-time-voice-folder" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid var(--border); color: #fff;"></select>
                </div>
            </div>

            <div style="text-align: right;">
                <button type="submit" class="btn btn-success" style="padding: 10px 24px; font-size: 0.95rem;">Guardar Ajustes Generales</button>
            </div>
        </form>
    </div>
</div>

<style>
#set-time-voice-enabled:checked + #slider-voice {
    background-color: #0284c7;
}
#set-time-voice-enabled:checked + #slider-voice:before {
    transform: translateX(24px);
}
#slider-voice:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}
</style>
