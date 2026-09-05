function renderMusicateca() {
    const fList = document.getElementById('folders-list');
    if (!fList) return;
    fList.innerHTML = '';

    if (appData.folders.length === 0) {
        fList.innerHTML = '<li style="color:var(--text-muted); padding:10px;">No hay carpetas creadas.</li>';
        if (typeof __renderStorageAllWidgets === 'function') __renderStorageAllWidgets();
        return;
    }

    appData.folders.forEach(f => {
        const li = document.createElement('li');
        li.className = `folder-row ${selectedFolder === f.name ? 'active' : ''}`;
        li.style.cssText = "display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; cursor: pointer;";
        
        li.innerHTML = `
            <div style="display: flex; align-items: center; gap: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" onclick="selectFolderUI('${f.name}')">
                <span>📁 ${f.name}</span>
                <span style="font-size:0.75rem; color: var(--text-muted);">(${f.count} MP3)</span>
            </div>
            <button class="btn btn-danger btn-sm" title="Eliminar carpeta y sus canciones" onclick="event.stopPropagation(); deleteFolder('${f.name}')">✖</button>
        `;
        fList.appendChild(li);
    });
    if (typeof __renderStorageAllWidgets === 'function') __renderStorageAllWidgets();
}

function selectFolderUI(folderName) {
    selectedFolder = folderName;
    renderMusicateca();
    const fObj = appData.folders.find(f => f.name === folderName);
    if (fObj) renderFiles(fObj);
    if (typeof __renderStorageAllWidgets === 'function') __renderStorageAllWidgets();
}

function renderFiles(fObj) {
    const titleEl = document.getElementById('current-folder-title');
    const badgeEl = document.getElementById('folder-count-badge');
    const flList = document.getElementById('files-list');

    if (titleEl) titleEl.innerText = `📁 ${fObj.name}`;
    if (badgeEl) badgeEl.innerText = `${fObj.count} archivos`;
    if (!flList) return;

    flList.innerHTML = '';
    if (fObj.files && fObj.files.length > 0) {
        fObj.files.forEach(f => {
            flList.innerHTML += `
            <li class="file-row">
                <div>
                    <span>🎵 ${f.name}</span>
                    <small style="color:var(--text-muted); margin-left:8px;">[${f.duration_str}] (${f.size})</small>
                </div>
                <button class="btn btn-danger btn-sm" onclick="deleteFile('${fObj.name}', '${f.name}')">✖</button>
            </li>`;
        });
    } else {
        flList.innerHTML = '<li style="color:var(--text-muted); padding:10px;">Carpeta vacía.</li>';
    }
}

async function createFolder() {
    const input = document.getElementById('new-folder-input');
    if (!input || !input.value.trim()) return;
    const folderNameOrig = input.value.trim();
    const form = new FormData();
    form.append('action', 'create_folder');
    form.append('folder_name', folderNameOrig);
    try {
        const resp = await fetch('autodj_api.php', { method: 'POST', body: form });
        const data = await resp.json().catch(() => ({}));
        if (data && data.success === true) {
            const created = (data.folder || folderNameOrig);
            selectedFolder = created;
            input.value = '';
            if (typeof showToast === 'function') showToast(`Carpeta "${created}" creada correctamente.`, 'success');
            else alert(`✓ Carpeta "${created}" creada correctamente.`);
            await loadData();
        } else {
            const msg = (data && data.error) ? data.error : "No se pudo crear la carpeta (sin mensaje del servidor).";
            let full = "❌ Error al crear carpeta:\n" + msg;
            if (data && data.detail) full += "\nDetalle: " + data.detail;
            if (data && data.hint) full += "\nSugerencia: " + data.hint;
            if (data && data.hint_perms) full += "\n\n" + data.hint_perms;
            alert(full);
        }
    } catch (e) {
        alert("❌ Error de red al crear carpeta: " + e.message);
    }
}

async function deleteFolder(folderName) {
    if (!confirm(`¿Eliminar la carpeta "${folderName}" y TODOS los archivos MP3 dentro de ella?`)) return;

    const form = new FormData();
    form.append('action', 'delete_folder');
    form.append('folder', folderName);
    try {
        const resp = await fetch('autodj_api.php', { method: 'POST', body: form });
        const data = await resp.json().catch(() => ({}));
        if (data && data.success === true) {
            if (typeof showToast === 'function') showToast(`Carpeta "${folderName}" eliminada (${data.deleted_mp3 || 0} archivos borrados).`, 'success');
            else alert(`✓ Carpeta "${folderName}" eliminada.`);
        } else {
            const msg = (data && data.error) ? data.error : "No se pudo borrar la carpeta.";
            alert("❌ " + msg);
        }
    } catch (e) {
        alert("❌ Error de red al borrar carpeta: " + e.message);
    }

    if (selectedFolder === folderName) {
        selectedFolder = null;
        const titleEl = document.getElementById('current-folder-title');
        const badgeEl = document.getElementById('folder-count-badge');
        const flList = document.getElementById('files-list');
        if (titleEl) titleEl.innerText = 'Selecciona una carpeta';
        if (badgeEl) badgeEl.innerText = '0 archivos';
        if (flList) flList.innerHTML = '<li style="color:var(--text-muted); padding:10px;">Selecciona una carpeta del panel izquierdo.</li>';
    }

    await loadData();
}

async function deleteFile(folder, file) {
    if (!confirm(`¿Eliminar ${file}?`)) return;
    const form = new FormData();
    form.append('action', 'delete_file');
    form.append('folder', folder);
    form.append('file', file);
    try {
        const resp = await fetch('autodj_api.php', { method: 'POST', body: form });
        const data = await resp.json().catch(() => ({}));
        if (data && data.success === true) {
            if (typeof showToast === 'function') showToast(`${file} eliminado.`, 'success');
        } else {
            const msg = (data && data.error) ? data.error : "No se pudo borrar el archivo.";
            alert("❌ " + msg + (data && data.detail ? "\nDetalle: " + data.detail : ''));
        }
    } catch (e) {
        alert("❌ Error de red al borrar archivo: " + e.message);
    }
    await loadData();
}


function uploadMultipleFiles() {
    if (!selectedFolder) return alert("Selecciona primero una carpeta del listado izquierdo.");
    const input = document.getElementById('multi-file-input');
    if (!input || input.files.length === 0) return alert("Selecciona uno o más archivos MP3 para subir.");

    const titleArea = document.getElementById('current-folder-title');
    const fileList = document.getElementById('files-list');
    
    if (titleArea) {
        titleArea.innerHTML = `Subiendo ${input.files.length} archivo(s)... <span id="upload-percent" style="color: #4ade80;">0%</span>`;
    }
    
    if (fileList) {
        fileList.innerHTML = `
        <li style="padding: 16px; background: #121c30; border: 1px solid #1e293b; border-radius: 8px; margin-bottom: 10px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.85rem; color: #38bdf8; font-weight: bold;">
                <span>Progreso de transferencia:</span>
            </div>
            <div style="width: 100%; background: #0b132b; border-radius: 6px; height: 16px; overflow: hidden; border: 1px solid #334155;">
                <div id="upload-bar" style="width: 0%; height: 100%; background: linear-gradient(90deg, #0284c7, #4ade80); transition: width 0.3s ease;"></div>
            </div>
        </li>`;
    }

    const form = new FormData();
    form.append('action', 'upload_multiple');
    form.append('folder', selectedFolder);
    for (let i = 0; i < input.files.length; i++) {
        form.append('files[]', input.files[i]);
    }

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'autodj_api.php', true);
    
    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            const bar = document.getElementById('upload-bar');
            const pctText = document.getElementById('upload-percent');
            if (bar) bar.style.width = pct + '%';
            if (pctText) pctText.innerText = pct + '%';
        }
    };

    xhr.onload = async function() {
        input.value = '';
        let data = {};
        try { data = JSON.parse(xhr.responseText || '{}'); } catch(e) { data = { error: 'Respuesta no JSON del servidor: ' + (xhr.responseText || '').substring(0,100) }; }

        const ok = (data.success === true);
        const uploaded = Number(data.uploaded || 0);
        const skipped = Number(data.skipped || 0);
        const total = Number(data.total || 0);

        if (ok && !data.error && uploaded > 0) {
            let msg = `✓ ${uploaded} archivo(s) subidos correctamente en "${selectedFolder}" (total: ${total}, omitidos: ${skipped}).`;
            if (typeof showToast === 'function') showToast(msg, 'success');
            else alert(msg);
        } else {
            let msg = (data.error || "Error desconocido al subir.");
            if (uploaded > 0) msg += ` (${uploaded} OK, ${skipped} omitidos)`;
            let full = "❌ Error al subir:\n" + msg;
            if (Array.isArray(data.errors) && data.errors.length) {
                full += "\n\nDetalle por archivo:\n• " + data.errors.slice(0, 15).join("\n• ");
                if (data.errors.length > 15) full += `\n... y ${data.errors.length - 15} más.`;
            }
            alert(full);
        }

        // 1. Recargar datos del servidor
        await loadData();
        
        // 2. Restaurar la vista de archivos de la carpeta activa
        const currentFolderObj = (appData.folders || []).find(f => f.name === selectedFolder);
        if (currentFolderObj) {
            renderFiles(currentFolderObj);
        }
    };

    xhr.onerror = function() {
        alert("❌ Error de red durante la subida. Revisa que Internet esté conectado.");
        input.value = '';
        loadData();
    };

    xhr.send(form);
}
