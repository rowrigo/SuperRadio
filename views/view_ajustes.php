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

            <div style="border:1px solid rgba(168,85,247,0.35); border-radius:10px; padding:16px 18px 20px; margin-bottom:24px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:6px;">
                    <div style="flex:1; min-width:300px;">
                        <h4 style="margin:0; font-size:0.98rem; color:#c084fc;"><i class="fa-solid fa-wave-square" style="margin-right:6px;"></i>Crossfade entre canciones</h4>
                        <p style="margin:6px 0 0; font-size:0.78rem; color:var(--text-muted); line-height:1.5;">
                            Encadena el final de una pista con el inicio de la siguiente (fade-out + fade-in simultáneos),
                            eliminando los espacios entre canciones. Pon <strong style="color:#cbd5e1;">0</strong> para desactivar.
                            Se aplica a todo el flujo (música, anuncios y cortinillas). Requiere <strong style="color:#cbd5e1;">Reiniciar AutoDJ</strong> para aplicarse.
                        </p>
                    </div>
                </div>

                <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
                    <div style="flex:1 1 200px; min-width:170px;">
                        <label style="display:block; font-weight:bold; font-size:0.78rem; color:var(--text-muted); margin-bottom:5px;">FADE-IN de la siguiente (seg):</label>
                        <input type="number" id="set-xfade-in" value="0" min="0" max="5" step="0.5"
                               list="dl-xfade"
                               style="width:100%; padding:8px 10px; border-radius:6px; border:1px solid var(--border); color:#fff; font-weight:bold; background:#0b132b;">
                    </div>
                    <div style="flex:1 1 200px; min-width:170px;">
                        <label style="display:block; font-weight:bold; font-size:0.78rem; color:var(--text-muted); margin-bottom:5px;">FADE-OUT de la actual (seg):</label>
                        <input type="number" id="set-xfade-out" value="0" min="0" max="5" step="0.5"
                               list="dl-xfade"
                               style="width:100%; padding:8px 10px; border-radius:6px; border:1px solid var(--border); color:#fff; font-weight:bold; background:#0b132b;">
                    </div>
                    <div style="flex:0 0 auto; font-size:0.75rem; color:var(--text-muted); padding-bottom:8px;">
                        <datalist id="dl-xfade">
                            <option value="0"><option value="0.5"><option value="1"><option value="1.5">
                            <option value="2"><option value="2.5"><option value="3">
                        </datalist>
                        <i class="fa-solid fa-circle-info" style="margin-right:4px;"></i>0 = desactivado · valores: 0.5, 1, 1.5… (máx 5)
                    </div>
                </div>
            </div>

            <div style="border:1px solid rgba(56,189,248,0.35); border-radius:10px; padding:16px 18px 20px; margin-bottom:24px;">
                <h4 style="margin:0; font-size:0.98rem; color:#38bdf8;"><i class="fa-solid fa-eye-slash" style="margin-right:6px;"></i>Nombres ocultos en el reproductor</h4>
                <p style="margin:6px 0 12px; font-size:0.78rem; color:var(--text-muted); line-height:1.5;">
                    Para las pistas que salgan de estas carpetas <strong style="color:#cbd5e1;">no se mostrará el nombre del archivo</strong>
                    en el reproductor: se mostrará el nombre de la emisora. Útil para sellos, cortinillas y locuciones de la hora
                    (marcar la carpeta de locuciones oculta también su anuncio).
                    Requiere <strong style="color:#cbd5e1;">Reiniciar AutoDJ</strong> para aplicarse.
                </p>
                <div id="hide-title-list" style="display:flex; flex-wrap:wrap; gap:10px 22px; padding:10px 12px; border:1px solid var(--border); border-radius:8px;">
                    <span style="font-size:0.82rem; color:var(--text-muted);">Cargando carpetas…</span>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--border); margin: 24px 0;">

            <!-- CONTROL DE REPETICIÓN (por playlist): anti-repetición e intercalado cada N -->
            <div id="card-repeat" style="border:1px solid rgba(56,189,248,0.25); border-radius:10px; padding:16px 18px 20px; margin-bottom:24px;">
                <h4 style="margin:0 0 6px; font-size:0.98rem; color:#38bdf8;"><i class="fa-solid fa-repeat" style="margin-right:6px;"></i>Control de Repetición</h4>
                <p style="margin:0 0 14px; font-size:0.78rem; color:var(--text-muted); line-height:1.5;">
                    Por defecto el AutoDJ <strong style="color:#cbd5e1;">no repite</strong> una canción hasta que pasan las últimas 8 pistas.
                    Si marcas <strong style="color:#cbd5e1;">Permitir repetición</strong>, esta playlist pierde esa anti-repetición y sus
                    canciones pueden volver a sonar de inmediato (útil para spots, comerciales, cuñas o IDs de emisora).
                    <strong>Repetir cada N</strong> es el sistema antiguo de spots: intercala la playlist cada N canciones de música;
                    hoy suele reemplazarse por los <strong>Intercaladores</strong>.
                    <strong style="color:#cbd5e1;">No requiere reiniciar el AutoDJ.</strong>
                </p>

                <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:14px;">
                    <div style="flex:1 1 260px; min-width:220px;">
                        <label style="display:block; font-weight:bold; font-size:0.78rem; color:var(--text-muted); margin-bottom:5px;">PLAYLIST:</label>
                        <select id="rep-playlist" onchange="repOnPlaylistChange()" style="width:100%; padding:8px 10px; border-radius:6px; border:1px solid var(--border); color:#fff;"></select>
                    </div>
                    <div id="rep-tipo-badge" style="font-size:0.75rem; font-weight:bold; padding:5px 10px; border-radius:10px;"></div>
                </div>

                <div id="rep-controls">
                    <label style="display:flex; align-items:center; gap:8px; font-size:0.88rem; cursor:pointer; margin-bottom:10px;">
                        <input type="checkbox" id="rep-allow" style="width:18px; height:18px; accent-color:#38bdf8;">
                        <strong style="color:#cbd5e1;">Permitir repetición en esta playlist (Spots / Comerciales / Cuñas)</strong>
                    </label>
                    <div id="rep-every-wrap" style="border-radius:8px; padding:10px 14px; border:1px solid rgba(255,255,255,0.06);">
                        <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
                            <label style="font-weight:bold; font-size:0.82rem; color:#cbd5e1; white-space:nowrap;">Repetir cada</label>
                            <input type="number" id="rep-every" min="0" max="100" step="1" value="0" list="dl-repeat"
                                   style="width:80px; padding:6px 8px; border-radius:6px; color:#fff; border:1px solid var(--border); font-weight:bold; text-align:center;">
                            <span style="font-size:0.82rem; color:var(--text-muted);">canciones musicales</span>
                            <datalist id="dl-repeat">
                                <option value="1"><option value="2"><option value="3"><option value="4">
                                <option value="5"><option value="8"><option value="10"><option value="15">
                            </datalist>
                        </div>
                    </div>
                </div>

                <div id="rep-note" style="display:none; margin-top:10px; font-size:0.78rem; line-height:1.5;"></div>
            </div>

            <div style="text-align: right;">
                <button type="submit" class="btn btn-success" style="padding: 10px 24px; font-size: 0.95rem;">Guardar Ajustes Generales</button>
            </div>
        </form>
    </div>
</div>
