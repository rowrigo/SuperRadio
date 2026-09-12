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

        <!-- ===== EDITOR EN 2 COLUMNAS: izquierda = playlist en construcción · derecha = biblioteca ===== -->
        <div class="playlist-editor-layout">

            <!-- COLUMNA IZQUIERDA: la playlist que se está armando -->
            <div class="pl-col-left">

                <!-- Panel de Inserción: MODO CARPETAS -->
                <div id="panel-add-folder" class="pl-add-bar" style="display:none;">
                    <select id="sel-folder" style="flex:1; min-width:200px; padding:8px; border-radius:6px; border:1px solid var(--border); color:#fff;"></select>
                    <button class="btn" style="background:#0284c7; font-weight:bold;" onclick="addFolder()">+ Asignar Carpeta</button>
                    <button class="btn" style="background:#7c3aed; font-weight:bold;" onclick="addHora()" title="Inserta la hora hablada en vivo (une HRS + MIN de la carpeta HORAS)">+ HORA</button>
                </div>

                <!-- Panel de Inserción: MODO ARCHIVOS -->
                <div id="panel-add-file" class="pl-add-bar" style="display:none;">
                    <select id="sel-folder-files" style="flex:1; min-width:180px; padding:8px; border-radius:6px; border:1px solid var(--border); color:#fff;" onchange="updateFilesDropdown()"></select>
                    <select id="sel-specific-file" style="flex:2; min-width:240px; padding:8px; border-radius:6px; border:1px solid var(--border); color:#fff;">
                        <option value="">Selecciona canción...</option>
                    </select>
                    <button class="btn" style="background:#0284c7; font-weight:bold;" onclick="addSpecificFile()">+ Agregar Canción</button>
                    <button class="btn" style="background:#7c3aed; font-weight:bold;" onclick="addHora()" title="Inserta la hora hablada en vivo (une HRS + MIN de la carpeta HORAS)">+ HORA</button>
                </div>

                <!-- Indicador del punto de inserción (se fija con DOBLE CLIC en una fila) -->
                <div id="ed-anchor-bar" class="pl-anchor-hint" style="display:none;">
                    <span>Inserciones debajo de <strong id="ed-anchor-label"></strong></span>
                    <button class="btn btn-sm" onclick="clearAnchor()">Quitar ancla</button>
                </div>

                <!-- Resumen de Duración (Modo Archivos) -->
                <div id="ed-duration-box" style="display:none; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:6px; border:1px solid var(--border); font-size:0.85rem;">
                    <div>
                        <span style="color:var(--text-muted);">Duración total estimada: </span>
                        <strong id="ed-total-duration" style="color:#4ade80; font-family:monospace; font-size:0.95rem;">00m 00s</strong>
                    </div>
                    <span id="ed-total-count" style="color:#38bdf8; font-weight:bold;">(0 canciones)</span>
                </div>

                <!-- Contenedor de Canciones / Pasos -->
                <div id="ed-list" class="pl-list"></div>
            </div>

            <!-- COLUMNA DERECHA: biblioteca (arrastra o haz clic en + para añadir) -->
            <aside class="pl-col-right music-panel">
                <div class="pl-browser-head">
                    <h4 id="pl-browser-title" style="margin:0;">Biblioteca</h4>
                    <span id="pl-browser-count" style="font-size:0.8rem; color:var(--text-muted);"></span>
                </div>
                <div id="pl-browser-hint" class="pl-anchor-hint"></div>
                <ul id="pl-browser-list" class="pl-browser-list"></ul>
            </aside>

        </div>

        <!-- Botón Guardar Cambios -->
        <div style="text-align:right; margin-top:20px; border-top:1px solid var(--border); padding-top:16px;">
            <button class="btn btn-success" onclick="persistToServer(true)" style="padding:10px 24px; font-size:0.95rem; font-weight:bold;">
                Guardar Cambios en Playlist
            </button>
        </div>
    </div>
</div>
