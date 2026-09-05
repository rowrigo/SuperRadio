<div id="view-playlists" class="view">
    <div class="card">
        <!-- Encabezado y Creador de Playlist -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
            <div>
                <h3 style="margin:0 0 4px 0;">Constructor de Playlists</h3>
                <p style="color:var(--text-muted); margin:0; font-size:0.85rem;">Crea listas por rotación de carpetas o listas con secuencia exacta de canciones.</p>
            </div>
            
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="text" id="new-pl-name" style="padding:8px 12px; border-radius:6px; border:1px solid var(--border); color:#fff; font-size:0.85rem; width:170px;">
                <select id="new-pl-type" style="padding:8px 10px; border-radius:6px; border:1px solid var(--border); color:#fff; font-size:0.85rem;">
                    <option value="archivos">Por Archivos (Secuencia)</option>
                    <option value="carpetas">Por Carpetas (Rotación)</option>
                </select>
                <button class="btn" onclick="crearPlaylist()" style="background:#0284c7; font-weight:bold; font-size:0.85rem;">+ Crear</button>
            </div>
        </div>

        <!-- Barra de Selección de Playlist Activo -->
        <div style="padding:14px; border-radius:8px; border:1px solid var(--border); margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center; gap:10px; flex:1; min-width:260px;">
                <label style="font-weight:bold; font-size:0.85rem; color:#38bdf8;">PLAYLIST:</label>
                <select id="sel-playlist" onchange="loadEditor()" style="flex:1; padding:8px 12px; border-radius:6px; border:1px solid var(--border); color:#fff; font-size:0.9rem; font-weight:bold;"></select>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <span id="ed-type-badge" style="padding:4px 10px; border-radius:4px; font-size:0.75rem; font-weight:bold; color:#fff; background:#8b5cf6;">Modo</span>
                <button id="btn-del-playlist" class="btn btn-danger btn-sm" onclick="eliminarPlaylistActual()" style="display:none;">Eliminar Playlist</button>
            </div>
        </div>

        <!-- CONFIGURACIÓN DE REPETICIÓN POR PLAYLIST -->
        <div id="ed-repeat-card" style="display:none; border:1px solid rgba(56,189,248,0.25); border-radius:10px; padding:14px 18px; margin-bottom:18px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px;">
                <div style="flex:1; min-width:280px;">
                    <h4 style="margin:0 0 6px 0; font-size:0.95rem; color:#38bdf8;">Control de Repetición</h4>
                    <p style="margin:0 0 10px 0; font-size:0.78rem; color:var(--text-muted);">
                        Úsalo para <strong style="color:#f8fafc;">spots, comerciales, cuñas o IDs de emisora</strong>. Si lo marcas, esta playlist
                        <strong>no tendrá anti-repetición</strong> y podrá volver a sonar cuantas veces se necesite.
                    </p>
                    <label style="display:flex; align-items:center; gap:8px; font-size:0.88rem; cursor:pointer; margin-bottom:10px;">
                        <input type="checkbox" id="ed-allow-repeat" style="width:18px; height:18px; accent-color:#38bdf8;">
                        <strong style="color:#cbd5e1;">Permitir repetición en este playlist (Spots / Comerciales / Cuñas)</strong>
                    </label>
                    <div id="ed-repeat-every-wrap" style="display:none; border-radius:8px; padding:10px 14px; border:1px solid rgba(255,255,255,0.06);">
                        <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <label style="font-weight:bold; font-size:0.82rem; color:#cbd5e1; white-space:nowrap;">Repetir cada</label>
                                <input type="number" id="ed-repeat-every" min="1" max="100" step="1" value="3"
                                       style="width:72px; padding:6px 8px; border-radius:6px; color:#fff; border:1px solid var(--border); font-weight:bold; text-align:center;">
                                <span style="font-size:0.82rem; color:var(--text-muted);">canciones musicales</span>
                            </div>
                            <div style="font-size:0.75rem; color:var(--text-muted); line-height:1.4;">
                                <strong>Ejemplo:</strong> valor <span style="color:#4ade80; font-family:monospace; font-weight:bold;">3</span> = cada 3 canciones música inserta 1 spot/comercial. <strong>(0 = desactivado)</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel de Inserción: MODO CARPETAS -->
        <div id="panel-add-folder" style="display:none; gap:10px; margin-bottom:15px; flex-wrap:wrap;">
            <select id="sel-folder" style="flex:1; min-width:200px; padding:8px; border-radius:6px; border:1px solid var(--border); color:#fff;"></select>
            <button class="btn" style="background:#0284c7; font-weight:bold;" onclick="addFolder()">+ Asignar Carpeta</button>
        </div>

        <!-- Panel de Inserción: MODO ARCHIVOS -->
        <div id="panel-add-file" style="display:none; gap:10px; margin-bottom:15px; flex-wrap:wrap;">
            <select id="sel-folder-files" style="flex:1; min-width:180px; padding:8px; border-radius:6px; border:1px solid var(--border); color:#fff;" onchange="updateFilesDropdown()"></select>
            <select id="sel-specific-file" style="flex:2; min-width:240px; padding:8px; border-radius:6px; border:1px solid var(--border); color:#fff;">
                <option value="">Selecciona canción...</option>
            </select>
            <button class="btn" style="background:#0284c7; font-weight:bold;" onclick="addSpecificFile()">+ Agregar Canción</button>
        </div>

        <!-- Resumen de Duración (Modo Archivos) -->
        <div id="ed-duration-box" style="display:none; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:6px; border:1px solid var(--border); margin-bottom:12px; font-size:0.85rem;">
            <div>
                <span style="color:var(--text-muted);">Duración total estimada: </span>
                <strong id="ed-total-duration" style="color:#4ade80; font-family:monospace; font-size:0.95rem;">00m 00s</strong>
            </div>
            <span id="ed-total-count" style="color:#38bdf8; font-weight:bold;">(0 canciones)</span>
        </div>

        <!-- Contenedor de Canciones / Pasos -->
       <div id="ed-list" style="display:flex; flex-direction:column; gap:6px;"></div>

<!-- Resumen de Duración (Modo Archivos) -->
        <div id="ed-duration-box" style="display:none; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:6px; border:1px solid var(--border); margin-bottom:12px; font-size:0.85rem;">
            <div>
                <span style="color:var(--text-muted);">Duración total estimada: </span>
                <strong id="ed-total-duration" style="color:#4ade80; font-family:monospace; font-size:0.95rem;">00m 00s</strong>
            </div>
            <span id="ed-total-count" style="color:#38bdf8; font-weight:bold;">(0 canciones)</span>
        </div>

        <!-- Contenedor de Canciones / Pasos -->
        <div id="ed-list" style="display:flex; flex-direction:column; gap:6px; max-height:480px; overflow-y:auto; padding-right:4px;"></div>

        <!-- Botón Guardar Cambios -->
        <div style="text-align:right; margin-top:20px; border-top:1px solid var(--border); padding-top:16px;">
            <button class="btn btn-success" onclick="persistToServer(true)" style="padding:10px 24px; font-size:0.95rem; font-weight:bold;">
                Guardar Cambios en Playlist
            </button>
        </div>
    </div>
</div>
