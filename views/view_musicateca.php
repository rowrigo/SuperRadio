<div id="view-musicateca" class="view">
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:15px;">
            <div>
                <h3>Musicateca</h3>
                <p style="color: var(--text-muted); margin: 0; font-size: 0.85rem;">Sube canciones organizadas por carpetas de géneros.</p>
            </div>
            <div style="display:flex; gap:8px;">
                <input type="text" id="new-folder-input" placeholder="Nombre nueva carpeta">
                <button class="btn btn-sm" onclick="createFolder()">+ Crear</button>
            </div>
        </div>

        <!-- WIDGET ESPACIO ASIGNADO (MUSICATECA) -->
        <div id="storage-musicateca-widget" style="border:1px solid #1e293b; border-radius:8px; padding:12px 16px; margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:8px;">
                <small style="color:#94a3b8; font-size:0.75rem; font-weight:bold; letter-spacing:0.5px;">ESPACIO ASIGNADO:</small>
                <div id="storage-musicateca-summary" style="font-size:0.85rem; color:var(--text-muted);">Cargando espacio...</div>
            </div>
            <div style="width:100%; background:#060b17; border-radius:6px; height:12px; overflow:hidden; border:1px solid #1e293b;">
                <div id="storage-musicateca-bar" style="width:0%; height:100%; background:#22c55e; transition:width 0.4s ease;"></div>
            </div>
        </div>

        <div class="music-layout">
            <div class="music-panel">
                <h4 style="margin-bottom:10px;">Carpetas</h4>
                <ul class="music-list" id="folders-list"></ul>
            </div>
            <div class="music-panel">
                <div class="dropzone" onclick="document.getElementById('multi-file-input').click()">
                    <div style="font-size: 1.3rem;">Subida Masiva de Canciones MP3</div>
                    <div style="color:var(--text-muted); font-size:0.8rem; margin-top:4px;">Haz clic aquí para seleccionar archivos</div>
                    <input type="file" id="multi-file-input" accept=".mp3" multiple style="display:none;" onchange="uploadMultipleFiles()">
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px;">
                    <h4 style="margin:0;" id="current-folder-title">Selecciona una carpeta</h4>
                    <span id="folder-count-badge" style="font-size:0.8rem; color:var(--text-muted);"></span>
                </div>
                <ul class="music-list" id="files-list"><li style="color:var(--text-muted); padding:10px;">Selecciona una carpeta izquierda.</li></ul>
            </div>
        </div>
    </div>
</div>
