<?php
$_stream_host_pp = defined('STREAM_HOST') ? STREAM_HOST : 'stream.radioscr.com';
$_mount_view_pp = $mount_clean ?? ($radio['mountpoint'] ?? 'milimonradio');
$_pg_url_direct = 'https://' . $_stream_host_pp . '/radio_page.php?mount=' . rawurlencode($_mount_view_pp);
$_np_state_dir = (isset($media_dir) ? $media_dir : ("/var/media/radios/{$_mount_view_pp}")) . '/.nextsong_state';
$_pg_logo_set = is_file($_np_state_dir . '/page_logo.jpg');
$_pg_bg_set = is_file($_np_state_dir . '/page_bg.jpg');
$_pg_defcover_set = (is_file($_np_state_dir . '/default_cover.jpg') || $_pg_logo_set);
$_pg_logo_preview = 'autodj_api.php?action=serve_page_logo&mount=' . rawurlencode($_mount_view_pp) . ($_pg_logo_set ? '&t=' . @filemtime($_np_state_dir . '/page_logo.jpg') : '');
$_pg_bg_preview = 'autodj_api.php?action=serve_page_bg&mount=' . rawurlencode($_mount_view_pp) . ($_pg_bg_set ? '&t=' . @filemtime($_np_state_dir . '/page_bg.jpg') : '');
$_pg_defcover_preview = 'autodj_api.php?action=serve_default_cover&mount=' . rawurlencode($_mount_view_pp) . ($_pg_defcover_set ? '&t=' . max(intval(@filemtime($_np_state_dir . '/default_cover.jpg')), intval(@filemtime($_np_state_dir . '/page_logo.jpg'))) : '');
?>
<div id="view-public-page" class="view">
    <style>
        .pp-tabs { display: flex; flex-wrap: wrap; gap: 8px; border-bottom: 1px solid #1e293b; padding-bottom: 10px; }
        .pp-tab {
            border: 1px solid #1e293b; background: #0f172a; color: #94a3b8;
            padding: 8px 16px; border-radius: 8px; cursor: pointer;
            font-size: 0.85rem; font-weight: 700; letter-spacing: 0.3px;
            transition: all 0.15s ease;
        }
        .pp-tab:hover { color: #fff; border-color: #38bdf8; }
        .pp-tab.active { background: #0c4a6e; border-color: #38bdf8; color: #e0f2fe; }
        .pp-pane { display: none; }
        .pp-pane.active { display: flex; flex-direction: column; gap: 16px; }
        /* ===== Filas dinámicas: Staff + Programación ===== */
        .pp-pane-dyn { display: flex; flex-direction: column; gap: 10px; }
        .pp-dyn-row { border: 1px solid #1e293b; border-radius: 12px; padding: 14px; background: #0d1526; display: flex; flex-direction: column; gap: 10px; }
        .pp-dyn-row-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .pp-dyn-photo-wrap { position: relative; width: 64px; height: 64px; border-radius: 50%; overflow: hidden; flex: 0 0 auto; }
        .pp-dyn-photo-avatar { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-weight: 800; font-size: 1.3rem; color: #fff; background: linear-gradient(135deg, #22c55e, #0ea5e9); }
        .pp-dyn-photo-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .pp-dyn-inputs { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; }
        .pp-dyn-row input[type="text"], .pp-dyn-row input[type="time"], .pp-dyn-row select {
            width: 100%; padding: 9px 10px; border-radius: 6px; border: 1px solid #1e293b;
            background: #0b1220; color: #fff; font-size: 0.9rem;
        }
        .pp-dyn-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        /* Lista de programas del editor */
        #pp-prog-list { display: flex; flex-direction: column; gap: 10px; }
        .pp-prog-item {
            border: 1px solid #1e293b; border-radius: 12px; padding: 12px 14px; background: #0d1526;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .pp-prog-item-main { flex: 1 1 260px; min-width: 0; }
        .pp-prog-item-name { font-weight: 800; color: #fff; font-size: 0.98rem; }
        .pp-prog-item-meta { font-size: 0.78rem; color: #94a3b8; margin-top: 3px; }
        .pp-prog-item-btns { display: flex; gap: 8px; }
        /* Sub-pestañas de la pestaña Secciones */
        .pp-subtab {
            border: 1px solid #1e293b; background: #0f172a; color: #94a3b8;
            padding: 6px 14px; border-radius: 8px; cursor: pointer;
            font-size: 0.8rem; font-weight: 700; transition: all 0.15s ease;
        }
        .pp-subtab:hover { color: #fff; border-color: #38bdf8; }
        .pp-subtab.active { background: #0c4a6e; border-color: #38bdf8; color: #e0f2fe; }
        .pp-links-pane { display: none; }
        .pp-links-pane.active { display: block; }
        /* Panes de las sub-pestañas de "Títulos y Colores" */
        .pp-style-pane { display: none; }
        .pp-style-pane.active { display: flex; flex-direction: column; gap: 12px; }
    </style>
    <div style="margin-bottom: 18px;">
        <h3 style="margin:0 0 4px 0;">Página Pública del Player</h3>
        <p style="color:var(--text-muted); margin:0; font-size:0.85rem;">
            Crea y personaliza una página web responsive para compartir tu radio. Sube logo, fondo y colores. Comparte el enlace directo.
        </p>
    </div>

    <!-- URL Box -->
    <div class="card p-4 mb-4" style="border: 1px solid #064e3b;">
        <div style="margin-bottom: 10px;">
            <div style="font-size:0.9rem; color:#a7f3d0; font-weight:700;">Enlace a tu Página Pública del Player</div>
            <div style="font-size:0.76rem; color:#6ee7b7; margin-top:2px;">Compártelo con tus oyentes.</div>
        </div>
        <div style="border:1px solid #064e3b; border-radius:8px; padding:10px 12px; margin-bottom: 12px;">
            <div style="color:#4ade80; font-family:monospace; font-size:0.96rem; word-break:break-all;"><?= htmlspecialchars($_pg_url_direct) ?></div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button class="btn btn-success btn-sm" type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($_pg_url_direct) ?>').then(function(){alert('¡URL copiada!');});">Copiar</button>
            <a class="btn btn-primary btn-sm" type="button" target="_blank" rel="noopener" href="<?= htmlspecialchars($_pg_url_direct) ?>" style="text-decoration:none;">Abrir</a>
        </div>
    </div>

    <div style="display:flex; flex-direction:column; gap:16px;">

        <!-- Formulario de configuración (ancho completo) -->
        <div style="display:flex; flex-direction:column; gap:16px;">

            <!-- TABS -->
            <div class="pp-tabs" role="tablist">
                <button type="button" class="pp-tab active" data-tab="pp-pane-assets" onclick="ppSwitchTab(this);">Logos y Fondo</button>
                <button type="button" class="pp-tab" data-tab="pp-pane-style" onclick="ppSwitchTab(this);">Títulos y Colores</button>
                <button type="button" class="pp-tab" data-tab="pp-pane-social" onclick="ppSwitchTab(this);">Redes Sociales</button>
                <button type="button" class="pp-tab" data-tab="pp-pane-about" onclick="ppSwitchTab(this);">Nosotros y Términos</button>
                <button type="button" class="pp-tab" data-tab="pp-pane-staff" onclick="ppSwitchTab(this);">Staff</button>
                <button type="button" class="pp-tab" data-tab="pp-pane-prog" onclick="ppSwitchTab(this);">Programación</button>
                <button type="button" class="pp-tab" data-tab="pp-pane-links" onclick="ppSwitchTab(this);">Secciones</button>
            </div>

            <form novalidate onsubmit="event.preventDefault(); savePPConfig();" style="display:flex; flex-direction:column; gap:16px;">

            <div class="pp-pane active" id="pp-pane-assets">

            <!-- Card: Logo -->
            <div class="card p-4" style="border:1px solid #1e293b;">
                <h4 style="margin:0 0 12px 0; color:#38bdf8; font-size:1rem; display:flex; align-items:center; gap:8px;">
                    Logo de la Radio
                    <span style="margin-left:auto; font-size:0.72rem; font-weight:700; color:#38bdf8; border:1px solid rgba(56,189,248,0.35); background:rgba(56,189,248,0.08); border-radius:999px; padding:2px 10px; white-space:nowrap;">512 × 512 px</span>
                </h4>
                <div style="display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap;">
                    <div style="flex:0 0 auto; display:flex; flex-direction:column; align-items:center; gap:8px;">
                        <label style="font-size:0.72rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Vista previa</label>
                        <img id="pp-logo-preview" src="<?= htmlspecialchars($_pg_logo_preview) ?>" alt="Logo"
                             onerror="this.onerror=null; this.style.opacity='0.3';"
                             style="width:140px; height:140px; border-radius:50%; object-fit:cover; border:3px solid #1e293b; background:#060b17;">
                        <span id="pp-logo-state" style="font-size:0.8rem; color:<?= !empty($_pg_logo_set) ? '#4ade80' : 'var(--text-muted)' ?>; font-weight:600;">
                            <?= !empty($_pg_logo_set) ? '✓ Logo activo' : 'Sin logo (se mostrará placeholder)' ?>
                        </span>
                    </div>
                    <div style="flex:1 1 220px; min-width:220px; display:flex; flex-direction:column; gap:10px;">
                        <input type="file" id="pp-logo-file" accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp" style="display:none;" onchange="onPPLogoFilePicked(event);">
                        <div>
                            <label style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; font-weight:700; margin-bottom:6px; display:block;">1) Elegir imagen</label>
                            <button type="button" class="btn btn-info" style="width:100%; justify-content:center;" onclick="document.getElementById('pp-logo-file').click();">Seleccionar Logo (JPG/PNG) · Max 5MB</button>
                            <span id="pp-logo-filename" style="font-size:0.85rem; color:#cbd5e1; display:block; margin-top:6px;">Ningún archivo seleccionado</span>
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" id="pp-logo-upload" class="btn btn-success" onclick="uploadPPLogo();" disabled style="flex:1 1 auto; justify-content:center;">Subir y Aplicar</button>
                            <button type="button" class="btn btn-danger" onclick="deletePPLogo();" style="flex:0 0 auto;">Eliminar</button>
                        </div>
                        <div id="pp-logo-alert" style="display:none;" class="alert"></div>
                    </div>
                </div>
            </div>

            <!-- Card: Fondo -->
            <div class="card p-4" style="border:1px solid #1e293b;">
                <h4 style="margin:0 0 12px 0; color:#38bdf8; font-size:1rem; display:flex; align-items:center; gap:8px;">
                    Imagen de Fondo
                    <span style="margin-left:auto; font-size:0.72rem; font-weight:700; color:#38bdf8; border:1px solid rgba(56,189,248,0.35); background:rgba(56,189,248,0.08); border-radius:999px; padding:2px 10px; white-space:nowrap;">1280 × 720 px</span>
                </h4>
                <div style="display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap;">
                    <div style="flex:0 0 auto; display:flex; flex-direction:column; align-items:center; gap:8px;">
                        <label style="font-size:0.72rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Vista previa</label>
                        <div style="width:170px; height:96px; border-radius:8px; border:2px solid #1e293b; overflow:hidden; background:#000;">
                            <img id="pp-bg-preview" src="<?= htmlspecialchars($_pg_bg_preview) ?>" alt="Fondo"
                                 onerror="this.onerror=null; this.style.opacity='0.3';"
                                 style="width:100%; height:100%; object-fit:cover; display:block;">
                        </div>
                        <span id="pp-bg-state" style="font-size:0.8rem; color:<?= !empty($_pg_bg_set) ? '#4ade80' : 'var(--text-muted)' ?>; font-weight:600;">
                            <?= !empty($_pg_bg_set) ? '✓ Fondo activo' : 'Sin fondo (degradado oscuro por defecto)' ?>
                        </span>
                    </div>
                    <div style="flex:1 1 220px; min-width:220px; display:flex; flex-direction:column; gap:10px;">
                        <input type="file" id="pp-bg-file" accept="image/*" style="display:none;" onchange="onPPBgFilePicked(event);">
                        <div>
                            <label style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; font-weight:700; margin-bottom:6px; display:block;">1) Elegir imagen</label>
                            <button type="button" class="btn btn-info" style="width:100%; justify-content:center;" onclick="document.getElementById('pp-bg-file').click();">Seleccionar Fondo · Max 12MB</button>
                            <span id="pp-bg-filename" style="font-size:0.85rem; color:#cbd5e1; display:block; margin-top:6px;">Ningún archivo seleccionado</span>
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" id="pp-bg-upload" class="btn btn-success" onclick="uploadPPBg();" disabled style="flex:1 1 auto; justify-content:center;">Subir y Aplicar</button>
                            <button type="button" class="btn btn-danger" onclick="deletePPBg();" style="flex:0 0 auto;">Eliminar</button>
                        </div>
                        <div id="pp-bg-alert" style="display:none;" class="alert"></div>
                    </div>
                </div>
            </div>

            <!-- Card: Carátula por Defecto (fallback canciones sin cover) -->
            <div class="card p-4" style="border:1px solid #1e293b;">
                <h4 style="margin:0 0 12px 0; color:#38bdf8; font-size:1rem; display:flex; align-items:center; gap:8px;">
                    Carátula por Defecto (fallback canciones)
                    <span style="margin-left:auto; font-size:0.72rem; font-weight:700; color:#38bdf8; border:1px solid rgba(56,189,248,0.35); background:rgba(56,189,248,0.08); border-radius:999px; padding:2px 10px; white-space:nowrap;">512 × 512 px</span>
                </h4>
                <p style="margin:0 0 12px 0; color:var(--text-muted); font-size:0.8rem; line-height:1.5;">
                    Imagen genérica que se mostrará <strong>cuando una canción no tenga carátula incrustada</strong> ni la encontremos en iTunes.
                    <br><strong style="color:#4ade80;">Optimización:</strong> si ya has subido un <em>Logo de la Radio</em> arriba y NO subes nada aquí,
                    el sistema usará automáticamente tu logo como fallback — no tienes que subir la misma imagen dos veces.
                </p>
                <div style="display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap;">
                    <div style="flex:0 0 auto; display:flex; flex-direction:column; align-items:center; gap:8px;">
                        <label style="font-size:0.72rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Vista previa</label>
                        <img id="pp-defcover-preview" src="<?= htmlspecialchars($_pg_defcover_preview) ?>" alt="Carátula por defecto"
                             onerror="this.onerror=null; this.style.opacity='0.3';"
                             style="width:140px; height:140px; border-radius:12px; object-fit:cover; border:3px solid #1e293b; background:#060b17;">
                        <span id="pp-defcover-state" style="font-size:0.8rem; color:<?= !empty($_pg_defcover_set) ? '#4ade80' : 'var(--text-muted)' ?>; font-weight:600;">
                            <?= !empty($_pg_defcover_set) ? (is_file($_np_state_dir.'/default_cover.jpg') ? '✓ Carátula custom activa' : '✓ Usando logo como fallback') : 'Placeholder gris (ninguna imagen)' ?>
                        </span>
                    </div>
                    <div style="flex:1 1 220px; min-width:220px; display:flex; flex-direction:column; gap:10px;">
                        <input type="file" id="pp-defcover-file" accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp" style="display:none;" onchange="onPPDefCoverFilePicked(event);">
                        <div>
                            <label style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; font-weight:700; margin-bottom:6px; display:block;">1) Elegir imagen</label>
                            <button type="button" class="btn btn-info" style="width:100%; justify-content:center;" onclick="document.getElementById('pp-defcover-file').click();">Seleccionar Carátula (JPG/PNG) · Max 5MB</button>
                            <span id="pp-defcover-filename" style="font-size:0.85rem; color:#cbd5e1; display:block; margin-top:6px;">Ningún archivo seleccionado</span>
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" id="pp-defcover-upload" class="btn btn-success" onclick="uploadPPDefCover();" disabled style="flex:1 1 auto; justify-content:center;">Subir y Aplicar</button>
                            <button type="button" class="btn btn-danger" onclick="deletePPDefCover();" style="flex:0 0 auto;">Eliminar</button>
                        </div>
                        <div id="pp-defcover-alert" style="display:none;" class="alert"></div>
                        <div style="font-size:0.75rem; color:var(--text-muted); line-height:1.5; border:1px dashed rgba(56,189,248,0.3); padding:8px 10px; border-radius:6px;">
                            <strong style="color:#38bdf8;">Resumen assets 100% unificados en esta pestaña:</strong>
                            <br><strong>Logo</strong> → círculo de la radio en player y anillo circular.
                            <br><strong>Fondo</strong> → fondo de pantalla del player público.
                            <br><strong>Carátula por Defecto</strong> → placeholder canciones sin carátula (fallback al Logo automáticamente si no subes nada).
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card: Opción logo / carátula -->
            <div class="card p-4" style="border:1px solid #1e293b;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;" title="Marca esto si prefieres que SIEMPRE se vea el logo, incluso cuando la canción tiene carátula">
                    <input type="checkbox" id="pp-logo-when-cover" style="width:16px; height:16px; accent-color:#22c55e;">
                    <span style="font-size:0.85rem;">Mostrar logo SIEMPRE (no reemplazar por carátula)</span>
                </label>
            </div>

            </div><!-- /pp-pane-assets -->

            <div class="pp-pane" id="pp-pane-style">

            <!-- Card: Textos + Colores -->
            <div class="card p-4" style="border:1px solid #1e293b; display:flex; flex-direction:column; gap:12px;">
                <h4 style="margin:0 0 12px 0; color:#38bdf8; font-size:1rem; display:flex; align-items:center; gap:8px;">
                    Título, Colores y Opciones
                </h4>
                <div class="pp-tabs" role="tablist" style="border-bottom:1px solid #1e293b; padding-bottom:8px; margin-bottom:12px;">
                    <button type="button" class="pp-subtab active" data-styletab="titulo" onclick="ppStyleTab(this);">Título y Acento</button>
                    <button type="button" class="pp-subtab" data-styletab="header" onclick="ppStyleTab(this);">Header</button>
                    <button type="button" class="pp-subtab" data-styletab="footer" onclick="ppStyleTab(this);">Footer</button>
                    <button type="button" class="pp-subtab" data-styletab="fondo" onclick="ppStyleTab(this);">Fondo</button>
                    <button type="button" class="pp-subtab" data-styletab="app" onclick="ppStyleTab(this);">App / Info</button>
                    <button type="button" class="pp-subtab" data-styletab="reproductor" onclick="ppStyleTab(this);">Reproductor</button>
                    <button type="button" class="pp-subtab" data-styletab="secciones" onclick="ppStyleTab(this);">Secciones</button>
                </div>
                    <div class="pp-style-pane active" id="pp-style-pane-titulo">
                    <div>
                        <label style="display:block; font-size:0.78rem; color:var(--text-muted); font-weight:700; margin-bottom:4px;">Título (dejalo vacío para usar el nombre de la emisora):</label>
                        <input type="text" id="pp-title" maxlength="80" placeholder="Ej. Milimon Radio Online" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.78rem; color:var(--text-muted); font-weight:700; margin-bottom:4px;">Subtítulo (bajo el nombre, ej. En Vivo 24/7):</label>
                        <input type="text" id="pp-subtitle" maxlength="60" placeholder="Ej. En Vivo 24/7" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.78rem; color:var(--text-muted); font-weight:700; margin-bottom:4px;">Color Acento (artista / anillo):</label>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <input type="color" id="pp-accent" value="#22c55e" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                            <input type="text" id="pp-accent-txt" maxlength="9" placeholder="#22c55e" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                        </div>
                    </div>
                    </div><!-- /pp-style-pane-titulo -->
                    <div class="pp-style-pane" id="pp-style-pane-header">
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap:14px;">
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de fondo de la barra:</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-bgcolor-header" value="#111a2e" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-bgcolor-header-txt" maxlength="9" placeholder="#111a2e" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                                <div style="margin-top:6px;">
                                    <label style="display:flex; justify-content:space-between; align-items:center; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:2px;">
                                        <span>Transparencia:</span><span id="pp-hdr-opacity-val" style="color:#22d3ee; font-family:monospace;">0.90</span>
                                    </label>
                                    <input type="range" id="pp-hdr-opacity" min="10" max="90" value="90" step="1" style="width:100%;" oninput="document.getElementById('pp-hdr-opacity-val').textContent = (this.value/100).toFixed(2);">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de letra (nombre de la radio):</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-hdr-text" value="#f8fafc" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-hdr-text-txt" maxlength="9" placeholder="#f8fafc" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de iconos (redes y compartir):</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-hdr-icon" value="#94a3b8" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-hdr-icon-txt" maxlength="9" placeholder="#94a3b8" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pp-style-pane" id="pp-style-pane-footer">
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap:14px;">
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de fondo del pie:</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-bgcolor-footer" value="#111a2e" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-bgcolor-footer-txt" maxlength="9" placeholder="#111a2e" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                                <div style="margin-top:6px;">
                                    <label style="display:flex; justify-content:space-between; align-items:center; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:2px;">
                                        <span>Transparencia:</span><span id="pp-ftr-opacity-val" style="color:#22d3ee; font-family:monospace;">0.90</span>
                                    </label>
                                    <input type="range" id="pp-ftr-opacity" min="10" max="90" value="90" step="1" style="width:100%;" oninput="document.getElementById('pp-ftr-opacity-val').textContent = (this.value/100).toFixed(2);">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de letra del pie:</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-ftr-text" value="#64748b" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-ftr-text-txt" maxlength="9" placeholder="#64748b" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pp-style-pane" id="pp-style-pane-fondo">
                        <div>
                            <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color encima del fondo/imagen:</label>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <input type="color" id="pp-bgcolor-base" value="#0b1226" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                <input type="text" id="pp-bgcolor-base-txt" maxlength="9" placeholder="#0b1226" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                            </div>
                            <div style="margin-top:6px;">
                                <label style="display:flex; justify-content:space-between; align-items:center; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:2px;">
                                    <span>Transparencia:</span><span id="pp-overlay-val" style="color:#22d3ee; font-family:monospace;">0.75</span>
                                </label>
                                <input type="range" id="pp-overlay" min="10" max="90" value="75" step="1" style="width:100%;" oninput="document.getElementById('pp-overlay-val').textContent = (this.value/100).toFixed(2);">
                            </div>
                        </div>
                    </div>

                    <div class="pp-style-pane" id="pp-style-pane-app">
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap:14px;">
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de fondo del contenedor:</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-bgcolor-main" value="#0f172a" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-bgcolor-main-txt" maxlength="9" placeholder="#0f172a" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                                <div style="margin-top:6px;">
                                    <label style="display:flex; justify-content:space-between; align-items:center; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:2px;">
                                        <span>Transparencia:</span><span id="pp-main-opacity-val" style="color:#22d3ee; font-family:monospace;">0.85</span>
                                    </label>
                                    <input type="range" id="pp-main-opacity" min="10" max="90" value="85" step="1" style="width:100%;" oninput="document.getElementById('pp-main-opacity-val').textContent = (this.value/100).toFixed(2);">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color del título (nombre grande):</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-app-title" value="#e2e8f0" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-app-title-txt" maxlength="9" placeholder="#e2e8f0" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color del subtítulo:</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-app-sub" value="#22c55e" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-app-sub-txt" maxlength="9" placeholder="#22c55e" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de letra (textos: Email / WhatsApp / Nosotros…):</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-app-text" value="#e2e8f0" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-app-text-txt" maxlength="9" placeholder="#e2e8f0" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de etiquetas (Email / WhatsApp / …):</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-label-color" value="#94a3b8" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-label-color-txt" maxlength="9" placeholder="#94a3b8" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Fondo de los botones de App (vacío = plantilla):</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-storebtn-bg" value="#ffffff" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-storebtn-bg-txt" maxlength="9" placeholder="#RRGGBB (vacío = plantilla)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de letras de los botones de App:</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-storebtn-text" value="#ffffff" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-storebtn-text-txt" maxlength="9" placeholder="#RRGGBB (vacío = plantilla)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de iconos de los botones de App:</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-storebtn-icon" value="#22c55e" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-storebtn-icon-txt" maxlength="9" placeholder="#RRGGBB (vacío = acento)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Fondo del botón WhatsApp (vacío = verde WhatsApp):</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-wabtn-bg" value="#25d366" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-wabtn-bg-txt" maxlength="9" placeholder="#RRGGBB (vacío = verde WA)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de letras del botón WhatsApp:</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-wabtn-text" value="#ffffff" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-wabtn-text-txt" maxlength="9" placeholder="#RRGGBB (vacío = plantilla)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de letra de la hora (reloj):</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-clock-time-color" value="#ffffff" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-clock-time-color-txt" maxlength="9" placeholder="#RRGGBB (vacío = plantilla)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de la fecha (reloj):</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-clock-date-color" value="#94a3b8" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-clock-date-color-txt" maxlength="9" placeholder="#RRGGBB (vacío = plantilla)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pp-style-pane" id="pp-style-pane-reproductor">
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap:14px;">
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color del nombre de la radio:</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-station-name" value="#22c55e" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-station-name-txt" maxlength="9" placeholder="#22c55e" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color del nombre de la canción:</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-song-color" value="#ffffff" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-song-color-txt" maxlength="9" placeholder="#ffffff" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color del botón Play / Pausa (vacío = acento):</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-play-btn-color" value="#22c55e" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-play-btn-color-txt" maxlength="9" placeholder="#RRGGBB (vacío = acento)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Color de la barra de volumen (vacío = acento):</label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="color" id="pp-vol-color" value="#22c55e" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                    <input type="text" id="pp-vol-color-txt" maxlength="9" placeholder="#RRGGBB (vacío = acento)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===== Personalización por SECCIÓN (Staff / Programación / enlaces) ===== -->
                    <?php $_secStyleGroups = [
                        ['key' => 'staff',         'label' => 'Staff'],
                        ['key' => 'programacion',  'label' => 'Programación'],
                        ['key' => 'patrocinadores','label' => 'Patrocinadores'],
                        ['key' => 'radios',        'label' => 'Nuestras Radios'],
                        ['key' => 'escuchanos',    'label' => 'Dónde Nos Puedes Escuchar'],
                    ];
                    $_secStyleBase = [
                        ['f' => 'title',        'l' => 'Color del título de la sección'],
                        ['f' => 'card_bg',      'l' => 'Color de fondo de las cards'],
                        ['f' => 'card_border',  'l' => 'Color del borde de las cards'],
                        ['f' => 'card_text',    'l' => 'Color de texto de las cards'],
                    ]; ?>
                    <div class="pp-style-pane" id="pp-style-pane-secciones">
                        <p style="margin:0 0 10px; font-size:0.75rem; color:var(--text-muted);">Deja un color vacío para usar el actual de la plantilla. El check "Icono" muestra u oculta el icono del título.</p>
                        <div class="pp-tabs" role="tablist" style="border-bottom:1px solid #1e293b; padding-bottom:8px; margin-bottom:12px;">
                            <?php foreach ($_secStyleGroups as $__sg): ?>
                            <button type="button" class="pp-subtab<?= $__sg === reset($_secStyleGroups) ? ' active' : '' ?>" data-sectab="<?= $__sg['key'] ?>" onclick="ppSecTab(this);"><?= htmlspecialchars($__sg['label']) ?></button>
                            <?php endforeach; ?>
                        </div>
                        <?php foreach ($_secStyleGroups as $__sg): $_k = $__sg['key']; $_fields = $_secStyleBase; if ($_k === 'staff') $_fields[] = ['f' => 'role', 'l' => 'Color del cargo (solo Staff)']; ?>
                        <div class="pp-links-pane<?= $__sg === reset($_secStyleGroups) ? ' active' : '' ?>" id="pp-sec-pane-<?= $_k ?>">
                            <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; color:#cbd5e1; cursor:pointer; margin-bottom:10px;" title="Muestra u oculta el icono del título de la sección">
                                <input type="checkbox" id="pp-sec-<?= $_k ?>-icon" checked style="width:15px; height:15px; accent-color:#22c55e;">
                                Icono en el título
                            </label>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px;">
                                <?php foreach ($_fields as $__f): ?>
                                <div>
                                    <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;"><?= htmlspecialchars($__f['l']) ?>:</label>
                                    <div style="display:flex; gap:6px; align-items:center;">
                                        <input type="color" id="pp-sec-<?= $_k ?>-<?= $__f['f'] ?>" value="#000000" style="width:42px; height:38px; border-radius:6px; border:1px solid #1e293b; padding:2px; cursor:pointer;">
                                        <input type="text" id="pp-sec-<?= $_k ?>-<?= $__f['f'] ?>-txt" maxlength="9" placeholder="#RRGGBB (vacío = plantilla)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #1e293b; color:#fff; font-family:monospace;">
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <div>
                                    <label style="display:block; font-size:0.76rem; color:var(--text-muted); font-weight:600; margin-bottom:4px;">Opacidad del fondo de las cards:</label>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <input type="range" id="pp-sec-<?= $_k ?>-cardbg-op" min="5" max="100" step="1" value="100" style="flex:1;" oninput="var v=Math.max(5,Math.min(100,parseInt(this.value,10)||100)); this.value=v; document.getElementById('pp-sec-<?= $_k ?>-cardbg-op-val').textContent = v + '%';">
                                        <span id="pp-sec-<?= $_k ?>-cardbg-op-val" style="color:#22d3ee; font-family:monospace; font-size:0.8rem; min-width:38px; text-align:right;">100%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; unset($__sg, $_k, $_fields, $__f, $_secStyleGroups, $_secStyleBase); ?>
                    </div>
                </div>
            </div><!-- /pp-pane-style -->

            <div class="pp-pane" id="pp-pane-social">
                <div class="card p-4" style="border:1px solid #1e293b; display:flex; flex-direction:column; gap:12px;">
                    <h4 style="margin:0 0 12px 0; color:#38bdf8; font-size:1rem; display:flex; align-items:center; gap:8px;">
                        Redes Sociales y Sitio Web
                    </h4>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:10px;">
                            <div>
                                <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">Página Web:</label>
                                <input type="url" id="pp-website" maxlength="300" placeholder="https://tuweb.com o tuweb.com" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">Facebook:</label>
                                <input type="url" id="pp-facebook" maxlength="300" placeholder="https://facebook.com/tupagina" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">Instagram:</label>
                                <input type="url" id="pp-instagram" maxlength="300" placeholder="https://instagram.com/tucuenta" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">TikTok:</label>
                                <input type="url" id="pp-tiktok" maxlength="300" placeholder="https://tiktok.com/@tucuenta" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">YouTube:</label>
                                <input type="url" id="pp-youtube" maxlength="300" placeholder="https://youtube.com/@tucanal" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">X (Twitter):</label>
                                <input type="url" id="pp-x" maxlength="300" placeholder="https://x.com/tucuenta" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">WhatsApp:</label>
                                <input type="text" id="pp-whatsapp" maxlength="200" placeholder="https://wa.me/50612345678 o solo numero: +506 1234-5678" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                            </div>
                        </div>

                    <div style="border-top:1px dashed #1e293b; padding-top:12px; margin-top:12px;">
                        <label style="display:flex; align-items:center; gap:8px; border:1px solid #1e293b; padding:8px 10px; border-radius:6px; cursor:pointer;">
                            <input type="checkbox" id="pp-show-share" checked style="width:16px; height:16px; accent-color:#22c55e;">
                            <span style="font-size:0.82rem;">Mostrar botones de redes y compartir</span>
                        </label>
                    </div>

                    <div style="border-top:1px dashed #1e293b; padding-top:12px; margin-top:12px;">
                        <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">Enlace a compartir (botón "Compartir" del encabezado):</label>
                        <input type="url" id="pp-share-url" maxlength="300" placeholder="https://tudominio.com  (vacío = enlace por defecto de la página)" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                        <div style="font-size:0.72rem; color:#94a3b8; margin-top:5px; line-height:1.5;">
                            Si lo dejas vacío se comparte el enlace por defecto (<code>radio_page.php?mount=…</code>). Escribe aquí tu dominio propio (p. ej. <code>https://tudominio.com</code>) para que el botón comparta esa URL.
                        </div>
                    </div>
                    </div>
                </div><!-- /pp-pane-social -->

            <div class="pp-pane" id="pp-pane-about">
                <div class="card p-4" style="border:1px solid #1e293b; display:flex; flex-direction:column; gap:12px;">
                    <h4 style="margin:0 0 12px 0; color:#38bdf8; font-size:1rem; display:flex; align-items:center; gap:8px;">
                        Nosotros y Contacto
                    </h4>
                    <div style="display:flex; flex-direction:column; gap:14px;">
                        <div>
                            <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">Email de contacto:</label>
                            <input type="email" id="pp-email" maxlength="200" placeholder="contacto@turadio.com" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                        </div>
                        <div>
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600;">Nosotros (descripción breve, máx 500 caracteres):</label>
                                <label style="display:inline-flex; align-items:center; gap:6px; margin-left:auto; font-size:0.78rem; color:#cbd5e1; cursor:pointer;" title="Permite usar etiquetas HTML básicas: b, i, a, p, br, ul/ol/li…">
                                    <input type="checkbox" id="pp-nosotros-html" style="width:16px; height:16px; accent-color:#22c55e;">
                                    <span>Permitir HTML</span>
                                </label>
                            </div>
                            <textarea id="pp-nosotros" maxlength="500" placeholder="Ej. Somos una radio online que transmite 24/7 música y entretenimiento..." style="width:100%; height:200px; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff; resize:vertical;"></textarea>
                            <div style="font-size:0.72rem; color:var(--text-muted); margin-top:5px; line-height:1.5;">
                                Etiquetas permitidas si marcas "Permitir HTML": <strong>&lt;b&gt;</strong>, <strong>&lt;i&gt;</strong>,
                                <strong>&lt;a href="https://…"&gt;</strong>, <strong>&lt;p&gt;</strong>, <strong>&lt;br&gt;</strong>,
                                <strong>&lt;ul&gt;/&lt;ol&gt;/&lt;li&gt;</strong>. Sin marcar, se muestra como texto plano.
                            </div>
                        </div>
                        <div style="border-top:1px solid #1e293b; padding-top:14px;">
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                                <i class="fa-solid fa-mobile-screen-button" style="color:#38bdf8;"></i>
                                <label style="margin:0; font-size:0.9rem; color:#cbd5e1; font-weight:700;">Descarga nuestra app</label>
                            </div>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:10px;">
                                <div>
                                    <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">App Store (link de la app):</label>
                                    <input type="url" id="pp-appstore" maxlength="500" placeholder="https://apps.apple.com/…" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">Google Play (link de la app):</label>
                                    <input type="url" id="pp-playstore" maxlength="500" placeholder="https://play.google.com/store/apps/…" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                                </div>
                            </div>
                            <div style="font-size:0.72rem; color:var(--text-muted); margin-top:6px;">
                                Se muestra en la columna de la derecha, debajo del botón de WhatsApp. Deja vacío para ocultar una tienda.
                            </div>
                        </div>
                        <div style="border-top:1px solid #1e293b; padding-top:14px;">
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                <i class="fa-solid fa-file-contract" style="color:#38bdf8;"></i>
                                <label style="margin:0; font-size:0.9rem; color:#cbd5e1; font-weight:700;">Términos y Condiciones</label>
                                <label style="margin-left:auto; display:inline-flex; align-items:center; gap:6px; font-size:0.78rem; color:#cbd5e1; cursor:pointer;" title="Permite usar etiquetas HTML básicas: b, i, a, p, br, ul/ol/li…">
                                    <input type="checkbox" id="pp-terminos-html" style="width:16px; height:16px; accent-color:#22c55e;">
                                    <span>Permitir HTML</span>
                                </label>
                            </div>
                            <textarea id="pp-terminos" maxlength="4000" placeholder="Ej. Al usar este sitio aceptas…" style="width:100%; height:180px; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff; resize:vertical;"></textarea>
                            <div style="font-size:0.72rem; color:var(--text-muted); margin-top:5px; line-height:1.5;">
                                Se muestra como una página propia del menú. El enlace <strong>Términos y Condiciones</strong> del pie solo aparece si este campo tiene texto.
                            </div>
                        </div>
                    </div>
                </div>
            </div><!-- /pp-pane-about -->

            <div class="pp-pane" id="pp-pane-staff">
                <div class="card p-4" style="border:1px solid #1e293b; display:flex; flex-direction:column; gap:12px;">
                    <div>
                        <h4 style="margin:0 0 4px 0; color:#38bdf8; font-size:1rem;">Equipo (Staff)</h4>
                        <div style="font-size:0.8rem; color:var(--text-muted); line-height:1.5;">
                            Se muestra en la página pública debajo del reproductor. La <strong>foto es opcional</strong> (sin foto se ve un avatar con las iniciales). Máximo 12 miembros.
                        </div>
                    </div>
                    <div>
                        <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; color:#cbd5e1; cursor:pointer;" title="Muestra u oculta la sección Staff en la página pública">
                            <input type="checkbox" id="pp-show-staff" checked style="width:15px; height:15px; accent-color:#22c55e;">
                            Mostrar sección en la página pública
                        </label>
                    </div>
                    <div style="display:flex; justify-content:flex-end;">
                        <button type="button" class="btn btn-info btn-sm" onclick="addStaffRow();">+ Añadir miembro</button>
                    </div>
                    <div id="pp-staff-list" class="pp-pane-dyn"></div>
                    <div id="pp-staff-alert" style="display:none;" class="alert"></div>
                </div>
            </div><!-- /pp-pane-staff -->

            <div class="pp-pane" id="pp-pane-prog">
                <div class="card p-4" style="border:1px solid #1e293b; display:flex; flex-direction:column; gap:12px;">
                    <div>
                        <h4 style="margin:0 0 4px 0; color:#38bdf8; font-size:1rem;">Programación semanal</h4>
                        <div style="font-size:0.8rem; color:var(--text-muted); line-height:1.5;">
                            Cada programa se guarda <strong>una sola vez</strong> marcando los días en que se emite (lunes a domingo). En la página pública se muestra con pestañas por día.
                        </div>
                    </div>
                    <div>
                        <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; color:#cbd5e1; cursor:pointer;" title="Muestra u oculta la sección Programación en la página pública">
                            <input type="checkbox" id="pp-show-programacion" checked style="width:15px; height:15px; accent-color:#22c55e;">
                            Mostrar sección en la página pública
                        </label>
                    </div>

                    <div id="pp-prog-editor" class="card" style="display:none; border:1px solid #38bdf8; padding:14px; flex-direction:column; gap:10px;">
                        <div style="font-size:0.85rem; color:#38bdf8; font-weight:800;" id="pp-prog-editor-title">Nuevo programa</div>
                        <div class="pp-dyn-inputs">
                            <input type="text" id="pp-prog-titulo" maxlength="90" placeholder="Nombre del programa *">
                            <input type="time" id="pp-prog-inicio" title="Hora inicio">
                            <input type="time" id="pp-prog-fin" title="Hora fin">
                        </div>
                        <input type="text" id="pp-prog-conductor" maxlength="160" placeholder="Conductor / presentador (opcional)">
                        <div style="font-size:0.78rem; color:var(--text-muted); font-weight:700;">Días en que se emite *</div>
                        <div id="pp-prog-dias" style="display:flex; gap:6px; flex-wrap:wrap;"></div>
                        <div style="display:flex; gap:8px; justify-content:flex-end;">
                            <button type="button" class="btn btn-info btn-sm" onclick="ppProgSave();">Guardar programa</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="ppProgCancel();">Cancelar</button>
                        </div>
                        <div id="pp-prog-editor-alert" style="display:none;" class="alert"></div>
                    </div>

                    <div style="display:flex; justify-content:flex-end;">
                        <button type="button" id="pp-prog-new" class="btn btn-success btn-sm" onclick="ppProgEdit(-1);">+ Añadir programa</button>
                    </div>
                    <div id="pp-prog-list"></div>
                    <div style="font-size:0.75rem; color:var(--text-muted);" id="pp-prog-empty">Sin programas todavía. Pulsa "+ Añadir programa".</div>
                    <div id="pp-prog-alert" style="display:none;" class="alert"></div>
                </div>
            </div><!-- /pp-pane-prog -->

            <div class="pp-pane" id="pp-pane-links">
                <?php $_pp_links_groups = [
                    ['key' => 'patrocinadores', 'label' => 'Patrocinadores', 'ph' => 'Nombre del anunciante', 'icono' => 'fa-solid fa-handshake'],
                    ['key' => 'radios',        'label' => 'Nuestras Radios',  'ph' => 'Nombre de la radio amiga', 'icono' => 'fa-solid fa-tower-broadcast'],
                    ['key' => 'escuchanos',    'label' => 'Dónde Nos Puedes Escuchar', 'ph' => 'Nombre (TuneIn, etc.)', 'icono' => 'fa-solid fa-headphones'],
                ]; ?>
                <div style="font-size:0.8rem; color:var(--text-muted); line-height:1.5;">
                    Cada elemento lleva <strong>logo (opcional), nombre y enlace</strong>. Activa el check para mostrar la sección.
                    En la página salen en orden: Patrocinadores, Nuestras Radios y Dónde Nos Puedes Escuchar.
                </div>
                <div class="pp-tabs" role="tablist">
                    <?php foreach ($_pp_links_groups as $__g): ?>
                    <button type="button" class="pp-subtab<?= $__g === reset($_pp_links_groups) ? ' active' : '' ?>" data-linktab="<?= $__g['key'] ?>" onclick="ppLinksTab(this);"><?= htmlspecialchars($__g['label']) ?></button>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($_pp_links_groups as $__g): $_k = $__g['key']; ?>
                <div class="pp-links-pane<?= $__g === reset($_pp_links_groups) ? ' active' : '' ?>" id="pp-links-pane-<?= $_k ?>">
                    <div class="card p-4" style="border:1px solid #1e293b; display:flex; flex-direction:column; gap:12px;">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <i class="<?= htmlspecialchars($__g['icono']) ?>" style="color:#38bdf8;"></i>
                            <h4 style="margin:0; color:#38bdf8; font-size:0.98rem;"><?= htmlspecialchars($__g['label']) ?></h4>
                            <label style="margin-left:auto; display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; color:#cbd5e1; cursor:pointer;" title="Muestra u oculta la sección en la página pública">
                                <input type="checkbox" id="pp-show-<?= $_k ?>" style="width:16px; height:16px; accent-color:#22c55e;">
                                Mostrar sección
                            </label>
                        </div>
                        <div id="pp-list-<?= $_k ?>" style="display:flex; flex-direction:column; gap:10px;"></div>
                        <div id="pp-empty-<?= $_k ?>" style="font-size:0.75rem; color:var(--text-muted);">Sin elementos todavía.</div>
                        <div style="display:flex; justify-content:flex-end;">
                            <button type="button" id="pp-add-<?= $_k ?>" class="btn btn-success btn-sm" onclick="addLinkItem('<?= $_k ?>');">+ Añadir</button>
                        </div>
                        <div id="pp-alert-<?= $_k ?>" style="display:none;" class="alert"></div>
                    </div>
                </div>
                <?php endforeach; unset($__g, $_k); ?>
            </div><!-- /pp-pane-links -->

                <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
                    <button type="button" class="btn btn-info btn-sm" onclick="loadPPConfigFromServer();">Restablecer valores actuales</button>
                    <button type="submit" class="btn btn-success" id="pp-save-btn" style="padding:10px 20px;">Guardar Configuración</button>
                </div>
                <div id="pp-cfg-alert" style="display:none;" class="alert"></div>
            </form>
        </div>

    </div>
</div>

<script>
(function(){
    // RADIO_CONFIG se define en panel.php DESPUÉS de incluir esta vista → puede no existir aún.
    // Si falta, tomar el mount del ?mount= de la URL (cada pestaña queda ligada a SU radio).
    const mount = (window.RADIO_CONFIG && window.RADIO_CONFIG.mount)
        ? window.RADIO_CONFIG.mount
        : (new URLSearchParams(window.location.search).get('mount') || "");
    const logoPrev = document.getElementById('pp-logo-preview');
    const logoState = document.getElementById('pp-logo-state');
    const logoFileInput = document.getElementById('pp-logo-file');
    const logoFilename = document.getElementById('pp-logo-filename');
    const logoUploadBtn = document.getElementById('pp-logo-upload');
    const logoAlert = document.getElementById('pp-logo-alert');

    const bgPrev = document.getElementById('pp-bg-preview');
    const bgState = document.getElementById('pp-bg-state');
    const bgFileInput = document.getElementById('pp-bg-file');
    const bgFilename = document.getElementById('pp-bg-filename');
    const bgUploadBtn = document.getElementById('pp-bg-upload');
    const bgAlert = document.getElementById('pp-bg-alert');

    const defCoverPrev = document.getElementById('pp-defcover-preview');
    const defCoverState = document.getElementById('pp-defcover-state');
    const defCoverFileInput = document.getElementById('pp-defcover-file');
    const defCoverFilename = document.getElementById('pp-defcover-filename');
    const defCoverUploadBtn = document.getElementById('pp-defcover-upload');
    const defCoverAlert = document.getElementById('pp-defcover-alert');

    const cfgAlert = document.getElementById('pp-cfg-alert');
    const fTitle = document.getElementById('pp-title');
    const fSubtitle = document.getElementById('pp-subtitle');
    const fAccent = document.getElementById('pp-accent');
    const fAccentTxt = document.getElementById('pp-accent-txt');
    const fTextColor = document.getElementById('pp-textcolor');
    const fTextColorTxt = document.getElementById('pp-textcolor-txt');
    const fOverlay = document.getElementById('pp-overlay');
    const fOverlayVal = document.getElementById('pp-overlay-val');
    const fShowShare = document.getElementById('pp-show-share');
    const fLogoWhenCover = document.getElementById('pp-logo-when-cover');
    const fShowStaff = document.getElementById('pp-show-staff');
    const fShowProg = document.getElementById('pp-show-programacion');
    const fWebsite = document.getElementById('pp-website');
    const fFacebook = document.getElementById('pp-facebook');
    const fWhatsapp = document.getElementById('pp-whatsapp');
    const fInstagram = document.getElementById('pp-instagram');
    const fTiktok = document.getElementById('pp-tiktok');
    const fYoutube = document.getElementById('pp-youtube');
    const fX = document.getElementById('pp-x');
    const fAppStore = document.getElementById('pp-appstore');
    const fPlayStore = document.getElementById('pp-playstore');
    const fShare = document.getElementById('pp-share-url');
    const fEmail = document.getElementById('pp-email');
    const fNosotros = document.getElementById('pp-nosotros');
    const fNosotrosHtml = document.getElementById('pp-nosotros-html');
    const fTerminos = document.getElementById('pp-terminos');
    const fTerminosHtml = document.getElementById('pp-terminos-html');
    const fBgColorBase = document.getElementById('pp-bgcolor-base');
    const fBgColorBaseTxt = document.getElementById('pp-bgcolor-base-txt');
    const fBgColorHeader = document.getElementById('pp-bgcolor-header');
    const fBgColorHeaderTxt = document.getElementById('pp-bgcolor-header-txt');
    const fBgColorMain = document.getElementById('pp-bgcolor-main');
    const fBgColorMainTxt = document.getElementById('pp-bgcolor-main-txt');
    const fBgColorFooter = document.getElementById('pp-bgcolor-footer');
    const fBgColorFooterTxt = document.getElementById('pp-bgcolor-footer-txt');
    const fHdrOpacity = document.getElementById('pp-hdr-opacity');
    const fHdrOpacityVal = document.getElementById('pp-hdr-opacity-val');
    const fMainOpacity = document.getElementById('pp-main-opacity');
    const fMainOpacityVal = document.getElementById('pp-main-opacity-val');
    const fFtrOpacity = document.getElementById('pp-ftr-opacity');
    const fFtrOpacityVal = document.getElementById('pp-ftr-opacity-val');
    const fHdrText = document.getElementById('pp-hdr-text');
    const fHdrTextTxt = document.getElementById('pp-hdr-text-txt');
    const fHdrIcon = document.getElementById('pp-hdr-icon');
    const fHdrIconTxt = document.getElementById('pp-hdr-icon-txt');
    const fFtrText = document.getElementById('pp-ftr-text');
    const fFtrTextTxt = document.getElementById('pp-ftr-text-txt');
    const fAppText = document.getElementById('pp-app-text');
    const fAppTextTxt = document.getElementById('pp-app-text-txt');
    const fAppTitle = document.getElementById('pp-app-title');
    const fAppTitleTxt = document.getElementById('pp-app-title-txt');
    const fAppSub = document.getElementById('pp-app-sub');
    const fAppSubTxt = document.getElementById('pp-app-sub-txt');
    const fLabelColor = document.getElementById('pp-label-color');
    const fLabelColorTxt = document.getElementById('pp-label-color-txt');
    const fStoreBtnBg = document.getElementById('pp-storebtn-bg');
    const fStoreBtnBgTxt = document.getElementById('pp-storebtn-bg-txt');
    const fStoreBtnText = document.getElementById('pp-storebtn-text');
    const fStoreBtnTextTxt = document.getElementById('pp-storebtn-text-txt');
    const fStoreBtnIcon = document.getElementById('pp-storebtn-icon');
    const fStoreBtnIconTxt = document.getElementById('pp-storebtn-icon-txt');
    const fWaBtnBg = document.getElementById('pp-wabtn-bg');
    const fWaBtnBgTxt = document.getElementById('pp-wabtn-bg-txt');
    const fWaBtnText = document.getElementById('pp-wabtn-text');
    const fWaBtnTextTxt = document.getElementById('pp-wabtn-text-txt');
    const fClockTime = document.getElementById('pp-clock-time-color');
    const fClockTimeTxt = document.getElementById('pp-clock-time-color-txt');
    const fClockDate = document.getElementById('pp-clock-date-color');
    const fClockDateTxt = document.getElementById('pp-clock-date-color-txt');
    const fStationName = document.getElementById('pp-station-name');
    const fStationNameTxt = document.getElementById('pp-station-name-txt');
    const fSongColor = document.getElementById('pp-song-color');
    const fSongColorTxt = document.getElementById('pp-song-color-txt');
    const fPlayBtn = document.getElementById('pp-play-btn-color');
    const fPlayBtnTxt = document.getElementById('pp-play-btn-color-txt');
    const fVolColor = document.getElementById('pp-vol-color');
    const fVolColorTxt = document.getElementById('pp-vol-color-txt');
    const staffList = document.getElementById('pp-staff-list');
    const progList = document.getElementById('pp-prog-list');
    const staffAlert = document.getElementById('pp-staff-alert');
    const progAlert = document.getElementById('pp-prog-alert');
    function escAttr(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function rowQ(r, f){ return r ? r.querySelector('[data-field="'+f+'"]') : null; }
    function initialsOf(name){
        const parts = String(name||'').trim().split(/\s+/);
        let out = '';
        for (let i = 0; i < Math.min(2, parts.length); i++){ if (parts[i]) out += parts[i][0].toUpperCase(); }
        return out || '?';
    }

    function showAlert(box, kind, msg) {
        if (!box) return;
        box.className = 'alert ' + (kind==='ok' ? 'alert-default-cover-ok' : 'alert-default-cover-err');
        box.style.display = 'block';
        box.textContent = msg;
        clearTimeout(box._t);
        box._t = setTimeout(function(){ box.style.display='none'; }, 6000);
    }
    function hexValid(h){ return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(String(h||'').trim()); }
    function linkColorPickers(pick, txt){
        if (pick && txt){
            pick.addEventListener('input', function(){ if(hexValid(pick.value)) txt.value = pick.value; });
            txt.addEventListener('input', function(){ if(hexValid(txt.value)) pick.value = txt.value; });
        }
    }
    linkColorPickers(fAccent, fAccentTxt);
    linkColorPickers(fTextColor, fTextColorTxt);
    linkColorPickers(fBgColorBase, fBgColorBaseTxt);
    linkColorPickers(fBgColorHeader, fBgColorHeaderTxt);
    linkColorPickers(fBgColorMain, fBgColorMainTxt);
    linkColorPickers(fBgColorFooter, fBgColorFooterTxt);
    // === COLORES POR ZONA (si no se sincronizan picker→texto, Guardar envía el texto
    // viejo y el color elegido con el selector nativo nunca se aplica) ===
    linkColorPickers(fHdrText, fHdrTextTxt);
    linkColorPickers(fHdrIcon, fHdrIconTxt);
    linkColorPickers(fFtrText, fFtrTextTxt);
    linkColorPickers(fAppText, fAppTextTxt);
    linkColorPickers(fAppTitle, fAppTitleTxt);
    linkColorPickers(fAppSub, fAppSubTxt);
    linkColorPickers(fLabelColor, fLabelColorTxt);
    linkColorPickers(fStoreBtnBg, fStoreBtnBgTxt);
    linkColorPickers(fStoreBtnText, fStoreBtnTextTxt);
    linkColorPickers(fStoreBtnIcon, fStoreBtnIconTxt);
    linkColorPickers(fWaBtnBg, fWaBtnBgTxt);
    linkColorPickers(fWaBtnText, fWaBtnTextTxt);
    linkColorPickers(fClockTime, fClockTimeTxt);
    linkColorPickers(fClockDate, fClockDateTxt);
    linkColorPickers(fStationName, fStationNameTxt);
    linkColorPickers(fSongColor, fSongColorTxt);
    linkColorPickers(fPlayBtn, fPlayBtnTxt);
    linkColorPickers(fVolColor, fVolColorTxt);

    // === SECCIONES: colores por sección (colores por defecto → vacío = plantilla) ===
    const SEC_STYLE_KEYS = ['staff', 'programacion', 'patrocinadores', 'radios', 'escuchanos'];
    const SEC_STYLE_FIELDS = {
        staff: ['title', 'card_bg', 'card_border', 'card_text', 'role'],
        programacion: ['title', 'card_bg', 'card_border', 'card_text'],
        patrocinadores: ['title', 'card_bg', 'card_border', 'card_text'],
        radios: ['title', 'card_bg', 'card_border', 'card_text'],
        escuchanos: ['title', 'card_bg', 'card_border', 'card_text']
    };
    function secSty(k, f){ return document.getElementById('pp-sec-' + k + '-' + f); }
    function secStyTxt(k, f){ return document.getElementById('pp-sec-' + k + '-' + f + '-txt'); }
    SEC_STYLE_KEYS.forEach(function(k){
        SEC_STYLE_FIELDS[k].forEach(function(f){ linkColorPickers(secSty(k, f), secStyTxt(k, f)); });
    });
    window.ppSecTab = function(btn){
        var key = btn.getAttribute('data-sectab');
        var root = document.getElementById('pp-pane-style');
        if (!root) return;
        root.querySelectorAll('.pp-subtab[data-sectab]').forEach(function(t){ t.classList.remove('active'); });
        btn.classList.add('active');
        root.querySelectorAll('.pp-links-pane').forEach(function(p){ p.classList.remove('active'); });
        var target = document.getElementById('pp-sec-pane-' + key);
        if (target) target.classList.add('active');
    };
    window.ppStyleTab = function(btn){
        var key = btn.getAttribute('data-styletab');
        var root = document.getElementById('pp-pane-style');
        if (!root) return;
        root.querySelectorAll('.pp-subtab[data-styletab]').forEach(function(t){ t.classList.remove('active'); });
        btn.classList.add('active');
        root.querySelectorAll('.pp-style-pane').forEach(function(p){ p.classList.remove('active'); });
        var target = document.getElementById('pp-style-pane-' + key);
        if (target) target.classList.add('active');
    };

    window.onPPLogoFilePicked = function(ev){
        if (!ev || !ev.target || !ev.target.files || !ev.target.files.length) {
            logoFilename.textContent = 'Ningún archivo seleccionado';
            logoUploadBtn.setAttribute('disabled','true'); return;
        }
        const f = ev.target.files[0];
        if (f.size > 5*1024*1024) { showAlert(logoAlert, 'err', 'Archivo demasiado grande (max 5 MB)'); logoUploadBtn.setAttribute('disabled','true'); return; }
        logoFilename.textContent = f.name + ' (' + Math.round((f.size||0)/1024) + ' KB)';
        logoUploadBtn.removeAttribute('disabled');
    };
    window.uploadPPLogo = function(){
        if (!logoFileInput || !logoFileInput.files || !logoFileInput.files.length){ showAlert(logoAlert,'err','Primero selecciona una imagen.'); return; }
        const f = logoFileInput.files[0];
        logoUploadBtn.setAttribute('disabled','true');
        const fd = new FormData();
        fd.append('logo', f);
        fd.append('action', 'upload_page_logo');
        fd.append('mount', mount);
        showAlert(logoAlert,'ok','Subiendo logo...');
        fetch('autodj_api.php', {method:'POST', credentials:'same-origin', body: fd})
            .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); })
            .then(function(res){
                const j = res && res.j ? res.j : null;
                if (j && j.success) {
                    showAlert(logoAlert, 'ok', '¡Logo actualizado correctamente! (' + (j.filesize_kb||0) + ' KB)');
                    refreshLogoBgPreview('logo');
                } else {
                    showAlert(logoAlert, 'err', j && j.error ? j.error : 'Error al guardar el logo.');
                    logoUploadBtn.removeAttribute('disabled');
                }
            })
            .catch(function(err){ showAlert(logoAlert,'err','Error de red: ' + (err && err.message ? err.message : err)); logoUploadBtn.removeAttribute('disabled'); });
    };
    window.deletePPLogo = function(){
        if (!confirm('¿Seguro que quieres eliminar el logo de la página pública? Volverá al placeholder.')) return;
        fetch('autodj_api.php?action=delete_page_logo&mount='+encodeURIComponent(mount), {credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (j && j.success) { showAlert(logoAlert,'ok','Logo eliminado.'); refreshLogoBgPreview('logo'); }
                else showAlert(logoAlert,'err', j && j.error ? j.error : 'Error al eliminar.');
            })
            .catch(function(err){ showAlert(logoAlert,'err','Error: '+err); });
    };

    window.onPPBgFilePicked = function(ev){
        if (!ev || !ev.target || !ev.target.files || !ev.target.files.length) {
            bgFilename.textContent = 'Ningún archivo seleccionado';
            bgUploadBtn.setAttribute('disabled','true'); return;
        }
        const f = ev.target.files[0];
        if (f.size > 12*1024*1024) { showAlert(bgAlert, 'err', 'Archivo demasiado grande (max 12 MB)'); bgUploadBtn.setAttribute('disabled','true'); return; }
        bgFilename.textContent = f.name + ' (' + Math.round((f.size||0)/1024) + ' KB)';
        bgUploadBtn.removeAttribute('disabled');
    };
    window.uploadPPBg = function(){
        if (!bgFileInput || !bgFileInput.files || !bgFileInput.files.length){ showAlert(bgAlert,'err','Primero selecciona una imagen de fondo.'); return; }
        const f = bgFileInput.files[0];
        bgUploadBtn.setAttribute('disabled','true');
        const fd = new FormData();
        fd.append('bg', f);
        fd.append('action', 'upload_page_bg');
        fd.append('mount', mount);
        showAlert(bgAlert,'ok','Subiendo fondo...');
        fetch('autodj_api.php', {method:'POST', credentials:'same-origin', body: fd})
            .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); })
            .then(function(res){
                const j = res && res.j ? res.j : null;
                if (j && j.success) {
                    showAlert(bgAlert, 'ok', '¡Fondo actualizado correctamente! (' + (j.filesize_kb||0) + ' KB)');
                    refreshLogoBgPreview('bg');
                } else {
                    showAlert(bgAlert, 'err', j && j.error ? j.error : 'Error al guardar el fondo.');
                    bgUploadBtn.removeAttribute('disabled');
                }
            })
            .catch(function(err){ showAlert(bgAlert,'err','Error de red: ' + (err && err.message ? err.message : err)); bgUploadBtn.removeAttribute('disabled'); });
    };
    window.deletePPBg = function(){
        if (!confirm('¿Seguro que quieres eliminar el fondo? Volverá al degradado oscuro por defecto.')) return;
        fetch('autodj_api.php?action=delete_page_bg&mount='+encodeURIComponent(mount), {credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (j && j.success) { showAlert(bgAlert,'ok','Fondo eliminado.'); refreshLogoBgPreview('bg'); }
                else showAlert(bgAlert,'err', j && j.error ? j.error : 'Error al eliminar.');
            })
            .catch(function(err){ showAlert(bgAlert,'err','Error: '+err); });
    };

    function refreshLogoBgPreview(which){
        const ts = Date.now();
        if ((!which || which==='logo') && logoPrev) {
            logoPrev.src = 'autodj_api.php?action=serve_page_logo&mount='+encodeURIComponent(mount)+'&_='+ts;
        }
        if ((!which || which==='bg') && bgPrev) {
            bgPrev.src = 'autodj_api.php?action=serve_page_bg&mount='+encodeURIComponent(mount)+'&_='+ts;
        }
        if ((!which || which==='defcover') && defCoverPrev) {
            defCoverPrev.src = 'autodj_api.php?action=serve_default_cover&mount='+encodeURIComponent(mount)+'&_='+ts;
        }
        setTimeout(function(){
            fetch('autodj_api.php?action=get_now_playing&mount='+encodeURIComponent(mount), {cache:'no-store'})
                .then(function(r){ return r && r.ok ? r.json() : null; })
                .then(function(jNow){
                    if (defCoverState && jNow) {
                        if (jNow.default_cover_set) {
                            if (jNow.default_cover_url && jNow.default_cover_url.indexOf('serve_page_logo') >= 0) {
                                defCoverState.textContent = '✓ Usando logo como fallback';
                            } else {
                                defCoverState.textContent = '✓ Carátula custom activa';
                            }
                            defCoverState.style.color='#4ade80';
                        } else {
                            defCoverState.textContent='Placeholder gris (ninguna imagen)';
                            defCoverState.style.color='';
                        }
                    }
                })
                .catch(function(){});
            fetch('autodj_api.php?action=get_page_config&mount='+encodeURIComponent(mount), {cache:'no-store'})
                .then(function(r){ return r && r.ok ? r.json() : null; })
                .then(function(j){
                    if (!j) return;
                    if (logoState) {
                        if (j.logo_set) { logoState.textContent='✓ Logo activo'; logoState.style.color='#4ade80'; }
                        else { logoState.textContent='Sin logo (placeholder gris)'; logoState.style.color=''; }
                    }
                    if (bgState) {
                        if (j.bg_set) { bgState.textContent='✓ Fondo activo'; bgState.style.color='#4ade80'; }
                        else { bgState.textContent='Sin fondo (degradado oscuro)'; bgState.style.color=''; }
                    }
                })
                .catch(function(){});
        }, 350);
    }

    window.onPPDefCoverFilePicked = function(ev){
        if (!ev || !ev.target || !ev.target.files || !ev.target.files.length) {
            defCoverFilename.textContent = 'Ningún archivo seleccionado';
            defCoverUploadBtn.setAttribute('disabled','true'); return;
        }
        const f = ev.target.files[0];
        if (f.size > 5*1024*1024) { showAlert(defCoverAlert, 'err', 'Archivo demasiado grande (max 5 MB)'); defCoverUploadBtn.setAttribute('disabled','true'); return; }
        defCoverFilename.textContent = f.name + ' (' + Math.round((f.size||0)/1024) + ' KB)';
        defCoverUploadBtn.removeAttribute('disabled');
    };
    window.uploadPPDefCover = function(){
        if (!defCoverFileInput || !defCoverFileInput.files || !defCoverFileInput.files.length){ showAlert(defCoverAlert,'err','Primero selecciona una imagen.'); return; }
        const f = defCoverFileInput.files[0];
        defCoverUploadBtn.setAttribute('disabled','true');
        const fd = new FormData();
        fd.append('cover', f);
        fd.append('action', 'upload_default_cover');
        fd.append('mount', mount);
        showAlert(defCoverAlert,'ok','Subiendo carátula por defecto...');
        fetch('autodj_api.php', {method:'POST', credentials:'same-origin', body: fd})
            .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); })
            .then(function(res){
                const j = res && res.j ? res.j : null;
                if (j && j.success) {
                    showAlert(defCoverAlert, 'ok', '¡Carátula por defecto actualizada! (' + (j.filesize_kb||0) + ' KB). La usarán canciones sin carátula ni resultado iTunes.');
                    refreshLogoBgPreview('defcover');
                } else {
                    showAlert(defCoverAlert, 'err', j && j.error ? j.error : 'Error al guardar la carátula.');
                    defCoverUploadBtn.removeAttribute('disabled');
                }
            })
            .catch(function(err){ showAlert(defCoverAlert,'err','Error de red: ' + (err && err.message ? err.message : err)); defCoverUploadBtn.removeAttribute('disabled'); });
    };
    window.deletePPDefCover = function(){
        if (!confirm('¿Seguro que quieres eliminar la carátula por defecto? Si tienes un Logo de Radio subido, se usará ése como fallback en su lugar.')) return;
        fetch('autodj_api.php?action=delete_default_cover&mount='+encodeURIComponent(mount), {credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (j && j.success) { showAlert(defCoverAlert,'ok','Carátula por defecto eliminada (' + (j.existed ? 'ahora usa el logo como fallback o placeholder' : 'no había carátula guardada') + ').'); refreshLogoBgPreview('defcover'); }
                else showAlert(defCoverAlert,'err', j && j.error ? j.error : 'Error al eliminar.');
            })
            .catch(function(err){ showAlert(defCoverAlert,'err','Error: '+err); });
    };

    // ================= STAFF + PROGRAMACIÓN: filas dinámicas =================
    function genId(){ return 'st' + Date.now().toString(36) + Math.random().toString(36).slice(2, 9); }

    function updatePhotoUI(r, hasImg, url){
        const img = rowQ(r, 'photo');
        const av = rowQ(r, 'avatar');
        const rm = r.querySelector('[data-role="remove-photo"]');
        if (img && av){
            if (hasImg && url){
                img.src = url; img.style.display = '';
                av.style.display = 'none';
            } else {
                img.removeAttribute('src'); img.style.display = 'none';
                av.style.display = '';
                const n = rowQ(r, 'nombre');
                av.textContent = n ? initialsOf(n.value) : '?';
            }
        }
        if (rm) rm.style.display = (hasImg && url) ? '' : 'none';
    }

    function wireRowEvents(r){
        const rem = r.querySelector('[data-role="remove"]');
        if (rem) rem.addEventListener('click', function(){ r.parentNode.removeChild(r); });
    }

    function buildStaffRow(data){
        data = data || {};
        const r = document.createElement('div');
        r.className = 'pp-dyn-row';
        r.setAttribute('data-kind', 'staff');
        r.setAttribute('data-id', data.id || genId());
        r.setAttribute('data-foto', data.foto || '');
        r.innerHTML =
            '<div class="pp-dyn-row-head">' +
                '<span class="pp-dyn-photo-wrap">' +
                    '<span class="pp-dyn-photo-avatar" data-field="avatar"></span>' +
                    '<img class="pp-dyn-photo-img" data-field="photo" alt="" style="display:none;">' +
                '</span>' +
                '<input type="text" data-field="nombre" maxlength="80" placeholder="Nombre completo *" value="' + escAttr(data.nombre) + '" style="flex:1 1 220px;">' +
                '<button type="button" class="btn btn-danger btn-sm" data-role="remove" title="Quitar miembro">&times;</button>' +
            '</div>' +
            '<div class="pp-dyn-inputs">' +
                '<input type="text" data-field="cargo" maxlength="60" placeholder="Cargo / rol (opcional)" value="' + escAttr(data.cargo) + '">' +
                '<input type="text" data-field="desc" maxlength="200" placeholder="Descripción corta (opcional)" value="' + escAttr(data.desc) + '">' +
            '</div>' +
            '<div class="pp-dyn-actions">' +
                '<input type="file" accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp" data-role="file" style="display:none;">' +
                '<button type="button" class="btn btn-info btn-sm" data-role="pick">Subir foto</button>' +
                '<button type="button" class="btn btn-danger btn-sm" data-role="remove-photo" style="display:none;">Quitar foto</button>' +
                '<span style="font-size:0.75rem; color:var(--text-muted);">Foto opcional · se muestra circular</span>' +
            '</div>';
        const pick = r.querySelector('[data-role="pick"]');
        const file = r.querySelector('[data-role="file"]');
        const rmPhoto = r.querySelector('[data-role="remove-photo"]');
        pick.addEventListener('click', function(){ file.click(); });
        file.addEventListener('change', function(){
            const f = file.files && file.files[0];
            if (!f) return;
            if (f.size > 5 * 1024 * 1024){ showAlert(staffAlert, 'err', 'La foto supera 5 MB.'); file.value = ''; return; }
            const fd = new FormData();
            fd.append('action', 'upload_staff_photo');
            fd.append('mount', mount);
            fd.append('id', r.getAttribute('data-id'));
            fd.append('foto', f);
            pick.setAttribute('disabled', 'true');
            showAlert(staffAlert, 'ok', 'Subiendo foto...');
            fetch('autodj_api.php', {method: 'POST', credentials: 'same-origin', body: fd})
                .then(function(res){ return res.json(); })
                .then(function(j){
                    pick.removeAttribute('disabled');
                    if (j && j.success){
                        r.setAttribute('data-foto', String(j.id) + '.jpg');
                        updatePhotoUI(r, true, j.foto_url);
                        showAlert(staffAlert, 'ok', 'Foto subida. Recuerda pulsar Guardar Configuración.');
                    } else {
                        showAlert(staffAlert, 'err', (j && j.error) ? j.error : 'Error al subir la foto.');
                    }
                    file.value = '';
                })
                .catch(function(err){ pick.removeAttribute('disabled'); file.value = ''; showAlert(staffAlert, 'err', 'Error de red: ' + (err && err.message ? err.message : err)); });
        });
        rmPhoto.addEventListener('click', function(){
            const id = r.getAttribute('data-id');
            fetch('autodj_api.php?action=delete_staff_photo&mount=' + encodeURIComponent(mount) + '&id=' + encodeURIComponent(id), {credentials: 'same-origin'})
                .then(function(res){ return res.json(); })
                .then(function(j){
                    r.setAttribute('data-foto', '');
                    updatePhotoUI(r, false, '');
                    showAlert(staffAlert, 'ok', (j && j.existed) ? 'Foto eliminada. Recuerda Guardar.' : 'No había foto.');
                })
                .catch(function(){ r.setAttribute('data-foto', ''); updatePhotoUI(r, false, ''); });
        });
        wireRowEvents(r);
        const nEl = rowQ(r, 'nombre');
        nEl.addEventListener('input', function(){
            const img = rowQ(r, 'photo');
            if (!img || !img.getAttribute('src')) rowQ(r, 'avatar').textContent = initialsOf(nEl.value);
        });
        if (data.foto_set || (data.foto && data.foto_url)){
            rowQ(r, 'avatar').textContent = initialsOf(data.nombre || '');
            updatePhotoUI(r, true, data.foto_url || '');
        } else {
            updatePhotoUI(r, false, '');
        }
        return r;
    }

    // ================= PROGRAMACIÓN: lista de programas (uno con varios días) =================
    const PROG_SHORT = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    let progState = [];
    let progEditIdx = -1;
    const peEditor = document.getElementById('pp-prog-editor');
    const peEditorTitle = document.getElementById('pp-prog-editor-title');
    const peEditorAlert = document.getElementById('pp-prog-editor-alert');
    const peTitulo = document.getElementById('pp-prog-titulo');
    const peConductor = document.getElementById('pp-prog-conductor');
    const peInicio = document.getElementById('pp-prog-inicio');
    const peFin = document.getElementById('pp-prog-fin');
    const peDias = document.getElementById('pp-prog-dias');
    const progEmpty = document.getElementById('pp-prog-empty');
    // Chips de días del editor (construidos una vez)
    (function(){
        if (!peDias) return;
        peDias.innerHTML = '';
        for (let i = 1; i <= 7; i++){
            const lab = document.createElement('label');
            lab.style.cssText = 'display:inline-flex; align-items:center; gap:4px; cursor:pointer; font-size:0.8rem; font-weight:600; color:#cbd5e1; border:1px solid #1e293b; border-radius:8px; padding:6px 9px; background:#0b1220;';
            const cb = document.createElement('input');
            cb.type = 'checkbox'; cb.className = 'pe-day-cb'; cb.value = String(i);
            cb.style.cssText = 'width:15px; height:15px; accent-color:#22c55e; margin:0;';
            lab.appendChild(cb);
            lab.appendChild(document.createTextNode(' ' + PROG_SHORT[i - 1]));
            peDias.appendChild(lab);
        }
    })();

    function ppProgOpenEditor(idx){
        if (!peEditor) return;
        progEditIdx = idx;
        const p = idx >= 0 && progState[idx] ? progState[idx] : null;
        peTitulo.value = p ? p.titulo : '';
        peConductor.value = p ? (p.conductor || '') : '';
        peInicio.value = p ? (p.inicio || '') : '';
        peFin.value = p ? (p.fin || '') : '';
        peEditor.querySelectorAll('.pe-day-cb').forEach(function(cb){
            cb.checked = p ? (p.dias || []).indexOf(parseInt(cb.value, 10)) >= 0 : false;
        });
        peEditorTitle.textContent = p ? 'Editar programa' : 'Nuevo programa';
        peEditor.style.display = 'flex';
        peEditorAlert.style.display = 'none';
    }
    window.ppProgEdit = function(idx){ ppProgOpenEditor(idx); };
    window.ppProgCancel = function(){
        if (peEditor){ peEditor.style.display = 'none'; peEditorAlert.style.display = 'none'; }
        progEditIdx = -1;
    };
    window.ppProgSave = function(){
        if (!peEditor) return;
        const titulo = peTitulo.value.trim();
        const conductor = peConductor.value.trim();
        const inicio = peInicio.value;
        const fin = peFin.value;
        const dias = Array.prototype.map.call(peEditor.querySelectorAll('.pe-day-cb:checked'), function(cb){ return parseInt(cb.value, 10); }).sort(function(a,b){ return a - b; });
        if (dias.length === 0){ peEditorAlert.textContent = 'Marca al menos un día.'; peEditorAlert.style.display = 'block'; return; }
        if (titulo === ''){ peEditorAlert.textContent = 'Escribe el nombre del programa.'; peEditorAlert.style.display = 'block'; return; }
        if (!inicio || !fin || fin <= inicio){ peEditorAlert.textContent = 'La hora de fin debe ser posterior a la de inicio.'; peEditorAlert.style.display = 'block'; return; }
        if (progState.length >= 60 && progEditIdx < 0){ peEditorAlert.textContent = 'Máximo 60 programas.'; peEditorAlert.style.display = 'block'; return; }
        peEditorAlert.style.display = 'none';
        const prog = { dias: dias, inicio: inicio, fin: fin, titulo: titulo, conductor: conductor };
        if (progEditIdx >= 0) progState[progEditIdx] = prog;
        else progState.push(prog);
        ppProgCancel();
        ppProgRender();
    };
    window.ppProgDelete = function(idx){
        if (idx < 0 || !progState[idx]) return;
        if (!confirm('¿Eliminar el programa "' + progState[idx].titulo + '"?')) return;
        progState.splice(idx, 1);
        ppProgRender();
    };
    function ppProgRender(){
        if (!progList) return;
        progList.innerHTML = '';
        if (progEmpty) progEmpty.style.display = progState.length ? 'none' : '';
        progState.forEach(function(p, idx){
            const item = document.createElement('div');
            item.className = 'pp-prog-item';
            const main = document.createElement('div');
            main.className = 'pp-prog-item-main';
            const name = document.createElement('div');
            name.className = 'pp-prog-item-name';
            name.textContent = p.titulo;
            const meta = document.createElement('div');
            meta.className = 'pp-prog-item-meta';
            const dayLbl = (p.dias || []).map(function(d){ return PROG_SHORT[d - 1]; }).join(' · ');
            meta.textContent = dayLbl + ' — ' + p.inicio + ' a ' + p.fin;
            main.appendChild(name); main.appendChild(meta);
            const btns = document.createElement('div');
            btns.className = 'pp-prog-item-btns';
            const ed = document.createElement('button');
            ed.type = 'button'; ed.className = 'btn btn-info btn-sm';
            ed.textContent = 'Editar';
            ed.addEventListener('click', function(){ ppProgOpenEditor(idx); });
            const del = document.createElement('button');
            del.type = 'button'; del.className = 'btn btn-danger btn-sm';
            del.textContent = 'Borrar';
            del.addEventListener('click', function(){ ppProgDelete(idx); });
            btns.appendChild(ed); btns.appendChild(del);
            item.appendChild(main); item.appendChild(btns);
            progList.appendChild(item);
        });
    }
    window.ppProgRender = ppProgRender;

    // ============ SECCIONES VINCULADAS: patrocinadores / radios / escuchanos ============
    const LINK_KEYS = ['patrocinadores', 'radios', 'escuchanos'];
    const LINK_LABELS = { patrocinadores: 'Patrocinadores', radios: 'Nuestras Radios', escuchanos: 'Dónde Nos Puedes Escuchar' };
    const linkState = {};
    LINK_KEYS.forEach(function(k){ linkState[k] = { show: false, items: [] }; });
    function linkBox(id){ return document.getElementById(id); }
    function buildLinkRow(k, data){
        data = data || {};
        const r = document.createElement('div');
        r.className = 'pp-dyn-row';
        r.setAttribute('data-kind', 'link');
        r.setAttribute('data-group', k);
        r.setAttribute('data-id', data.id || genId());
        r.setAttribute('data-logo', data.logo || '');
        r.innerHTML =
            '<div class="pp-dyn-row-head">' +
                '<span class="pp-dyn-photo-wrap" style="width:64px; height:64px; border-radius:12px;">' +
                    '<span class="pp-dyn-photo-avatar" data-field="avatar" style="border-radius:12px; font-size:1rem;"></span>' +
                    '<img class="pp-dyn-photo-img" data-field="photo" alt="" style="display:none; border-radius:12px;">' +
                '</span>' +
                '<div style="flex:1 1 260px; min-width:0; display:flex; flex-direction:column; gap:8px;">' +
                    '<input type="text" data-field="nombre" maxlength="120" placeholder="Nombre *" value="' + escAttr(data.nombre) + '">' +
                    '<input type="text" data-field="link" maxlength="500" placeholder="https://… (enlace, se abre en otra pestaña)" value="' + escAttr(data.link) + '">' +
                '</div>' +
                '<button type="button" class="btn btn-danger btn-sm" data-role="remove" title="Quitar">&times;</button>' +
            '</div>' +
            '<div class="pp-dyn-actions">' +
                '<input type="file" accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp" data-role="file" style="display:none;">' +
                '<button type="button" class="btn btn-info btn-sm" data-role="pick">Subir logo</button>' +
                '<button type="button" class="btn btn-danger btn-sm" data-role="remove-photo" style="display:none;">Quitar logo</button>' +
                '<span style="font-size:0.75rem; color:var(--text-muted);">Logo opcional</span>' +
            '</div>';
        const wrap = r.querySelector('.pp-dyn-photo-wrap');
        if (wrap) wrap.style.borderRadius = '12px';
        const av = rowQ(r, 'avatar');
        if (av) av.textContent = initialsOf(data.nombre);
        const pick = r.querySelector('[data-role="pick"]');
        const file = r.querySelector('[data-role="file"]');
        const rmPhoto = r.querySelector('[data-role="remove-photo"]');
        const alert = linkBox('pp-alert-' + k);
        function updPhotoUI(hasImg, url){
            const img = rowQ(r, 'photo');
            if (img){ if (hasImg && url){ img.src = url; img.style.display = ''; av.style.display = 'none'; } else { img.removeAttribute('src'); img.style.display = 'none'; av.style.display = ''; } }
            rmPhoto.style.display = (hasImg && url) ? '' : 'none';
        }
        function uploadLogo(fileInput){
            const f = fileInput.files && fileInput.files[0];
            if (!f) return;
            if (f.size > 5 * 1024 * 1024){ showAlert(alert, 'err', 'El logo supera 5 MB.'); fileInput.value=''; return; }
            const fd = new FormData();
            fd.append('action', 'upload_staff_photo');
            fd.append('mount', mount);
            fd.append('id', r.getAttribute('data-id'));
            fd.append('foto', f);
            pick.setAttribute('disabled', 'true');
            showAlert(alert, 'ok', 'Subiendo logo...');
            fetch('autodj_api.php', {method:'POST', credentials:'same-origin', body: fd})
                .then(function(res){ return res.json(); })
                .then(function(j){
                    pick.removeAttribute('disabled');
                    if (j && j.success){ r.setAttribute('data-logo', String(j.id) + '.jpg'); updPhotoUI(true, j.foto_url); showAlert(alert, 'ok', 'Logo subido. Recuerda Guardar Configuración.'); }
                    else showAlert(alert, 'err', (j && j.error) ? j.error : 'Error al subir el logo.');
                    fileInput.value = '';
                })
                .catch(function(err){ pick.removeAttribute('disabled'); fileInput.value=''; showAlert(alert, 'err', 'Error de red: ' + (err && err.message ? err.message : err)); });
        }
        pick.addEventListener('click', function(){ file.click(); });
        file.addEventListener('change', function(){ uploadLogo(file); });
        rmPhoto.addEventListener('click', function(){
            const id = r.getAttribute('data-id');
            fetch('autodj_api.php?action=delete_staff_photo&mount=' + encodeURIComponent(mount) + '&id=' + encodeURIComponent(id), {credentials:'same-origin'})
                .then(function(){ r.setAttribute('data-logo', ''); updPhotoUI(false, ''); })
                .catch(function(){ r.setAttribute('data-logo', ''); updPhotoUI(false, ''); });
        });
        r.querySelector('[data-role="remove"]').addEventListener('click', function(){ r.parentNode.removeChild(r); });
        const nEl = rowQ(r, 'nombre');
        nEl.addEventListener('input', function(){ const img = rowQ(r,'photo'); if (!img || !img.getAttribute('src')) av.textContent = initialsOf(nEl.value); });
        if (data.logo_set || (data.logo && data.logo_url)){
            av.textContent = initialsOf(data.nombre || '');
            updPhotoUI(true, data.logo_url || '');
        } else {
            updPhotoUI(false, '');
        }
        return r;
    }
    function addLinkItem(k, data){
        const list = linkBox('pp-list-' + k);
        if (!list) return;
        if (list.querySelectorAll('.pp-dyn-row').length >= 20){ showAlert(linkBox('pp-alert-' + k), 'err', 'Máximo 20 elementos.'); return; }
        list.appendChild(buildLinkRow(k, data));
        const empty = linkBox('pp-empty-' + k);
        if (empty) empty.style.display = 'none';
    }
    window.addLinkItem = addLinkItem;
    function ppLinksLoad(cfg){
        LINK_KEYS.forEach(function(k){
            const st = linkState[k];
            st.show = !!cfg['show_' + k];
            st.items = (cfg[k] || []).map(function(it){ return { id: it.id || '', logo: it.logo || '', nombre: it.nombre || '', link: it.link || '', logo_url: it.logo_url || '' }; });
            const cb = linkBox('pp-show-' + k);
            if (cb) cb.checked = st.show;
            const list = linkBox('pp-list-' + k);
            if (list) list.innerHTML = '';
            const empty = linkBox('pp-empty-' + k);
            if (empty) empty.style.display = st.items.length ? 'none' : '';
            st.items.forEach(function(it){ addLinkItem(k, Object.assign({ logo_set: !!it.logo }, it)); });
        });
    }
    function ppLinksPayload(payload){
        let ok = true;
        LINK_KEYS.forEach(function(k){
            const cb = linkBox('pp-show-' + k);
            const list = linkBox('pp-list-' + k);
            payload['show_' + k] = !!(cb && cb.checked);
            const rows = list ? Array.from(list.querySelectorAll('.pp-dyn-row')) : [];
            const items = [];
            for (let i = 0; i < rows.length; i++){
                const r = rows[i];
                const nombre = (rowQ(r,'nombre') ? rowQ(r,'nombre').value : '').trim();
                const link = (rowQ(r,'link') ? rowQ(r,'link').value : '').trim();
                const logo = r.getAttribute('data-logo') || '';
                if (nombre === '' && link === '' && logo === '') continue;
                if (nombre === ''){ showAlert(cfgAlert, 'err', 'Falta el nombre en ' + LINK_LABELS[k] + ' (elemento #' + (i + 1) + ').'); ok = false; return; }
                if (link === ''){ showAlert(cfgAlert, 'err', 'Falta el enlace en ' + LINK_LABELS[k] + ' (elemento #' + (i + 1) + ').'); ok = false; return; }
                items.push({ id: r.getAttribute('data-id') || '', logo: logo, nombre: nombre, link: link });
            }
            payload[k] = items;
        });
        return ok;
    }

    window.addStaffRow = function(data){
        if (!staffList) return;
        if (staffList.querySelectorAll('.pp-dyn-row').length >= 12){ showAlert(staffAlert, 'err', 'Máximo 12 miembros de staff.'); return; }
        staffList.appendChild(buildStaffRow(data));
    };

    function applyCfgToForm(cfg){
        if (!cfg) return;
        if (fTitle) fTitle.value = (cfg.title === null ? '' : String(cfg.title || ''));
        if (fSubtitle) fSubtitle.value = String(cfg.subtitle || '');
        const accent = String(cfg.accent_color || '#22c55e');
        if (hexValid(accent) && fAccent && fAccentTxt) { fAccent.value=accent; fAccentTxt.value=accent; }
        const tc = String(cfg.primary_text_color || '#ffffff');
        if (hexValid(tc) && fTextColor && fTextColorTxt) { fTextColor.value=tc; fTextColorTxt.value=tc; }
        const ov = Math.max(10, Math.min(90, parseInt(cfg.bg_overlay_opacity,10)||75));
        if (fOverlay) fOverlay.value = ov;
        if (fOverlayVal) fOverlayVal.textContent = (ov/100).toFixed(2);
        if (fShowShare) fShowShare.checked = !!cfg.show_share;
        if (fLogoWhenCover) fLogoWhenCover.checked = !!cfg.show_logo_when_cover;
        if (fShowStaff) fShowStaff.checked = cfg.show_staff !== false;
        if (fShowProg) fShowProg.checked = cfg.show_programacion !== false;
        if (fWebsite) fWebsite.value = String(cfg.website_url || '');
        if (fFacebook) fFacebook.value = String(cfg.facebook_url || '');
        if (fWhatsapp) fWhatsapp.value = String(cfg.whatsapp_url || '');
        if (fInstagram) fInstagram.value = String(cfg.instagram_url || '');
        if (fTiktok) fTiktok.value = String(cfg.tiktok_url || '');
        if (fYoutube) fYoutube.value = String(cfg.youtube_url || '');
        if (fX) fX.value = String(cfg.x_url || '');
        if (fAppStore) fAppStore.value = String(cfg.appstore_url || '');
        if (fPlayStore) fPlayStore.value = String(cfg.playstore_url || '');
        if (fShare) fShare.value = String(cfg.share_url || '');
        if (fEmail) fEmail.value = String(cfg.email_contacto || '');
        if (fNosotros) fNosotros.value = String(cfg.nosotros || '');
        if (fNosotrosHtml) fNosotrosHtml.checked = !!cfg.nosotros_html;
        if (fTerminos) fTerminos.value = String(cfg.terminos || '');
        if (fTerminosHtml) fTerminosHtml.checked = !!cfg.terminos_html;
        const bgBase = String(cfg.bg_color_base || '#0b1226');
        if (hexValid(bgBase) && fBgColorBase && fBgColorBaseTxt) { fBgColorBase.value=bgBase; fBgColorBaseTxt.value=bgBase; }
        const bgHeader = String(cfg.header_bg_color || '#111a2e');
        if (hexValid(bgHeader) && fBgColorHeader && fBgColorHeaderTxt) { fBgColorHeader.value=bgHeader; fBgColorHeaderTxt.value=bgHeader; }
        const bgMain = String(cfg.main_bg_color || '#0f172a');
        if (hexValid(bgMain) && fBgColorMain && fBgColorMainTxt) { fBgColorMain.value=bgMain; fBgColorMainTxt.value=bgMain; }
        const bgFooter = String(cfg.footer_bg_color || '#111a2e');
        if (hexValid(bgFooter) && fBgColorFooter && fBgColorFooterTxt) { fBgColorFooter.value=bgFooter; fBgColorFooterTxt.value=bgFooter; }
        // === TRANSPARENCIAS (0.10..0.90) ===
        const hdrOp = Math.max(10, Math.min(90, parseInt(cfg.header_bg_opacity, 10) || 90));
        if (fHdrOpacity) fHdrOpacity.value = hdrOp;
        if (fHdrOpacityVal) fHdrOpacityVal.textContent = (hdrOp/100).toFixed(2);
        const mainOp = Math.max(10, Math.min(90, parseInt(cfg.main_bg_opacity, 10) || 85));
        if (fMainOpacity) fMainOpacity.value = mainOp;
        if (fMainOpacityVal) fMainOpacityVal.textContent = (mainOp/100).toFixed(2);
        const ftrOp = Math.max(10, Math.min(90, parseInt(cfg.footer_bg_opacity, 10) || 90));
        if (fFtrOpacity) fFtrOpacity.value = ftrOp;
        if (fFtrOpacityVal) fFtrOpacityVal.textContent = (ftrOp/100).toFixed(2);
        // === COLORES POR ZONA ===
        const hdrTextC = String(cfg.header_text_color || '#f8fafc');
        if (hexValid(hdrTextC) && fHdrText && fHdrTextTxt) { fHdrText.value=hdrTextC; fHdrTextTxt.value=hdrTextC; }
        const hdrIconC = String(cfg.header_icon_color || '#94a3b8');
        if (hexValid(hdrIconC) && fHdrIcon && fHdrIconTxt) { fHdrIcon.value=hdrIconC; fHdrIconTxt.value=hdrIconC; }
        const ftrTextC = String(cfg.footer_text_color || '#64748b');
        if (hexValid(ftrTextC) && fFtrText && fFtrTextTxt) { fFtrText.value=ftrTextC; fFtrTextTxt.value=ftrTextC; }
        const appTextC = String(cfg.app_text_color || '#e2e8f0');
        if (hexValid(appTextC) && fAppText && fAppTextTxt) { fAppText.value=appTextC; fAppTextTxt.value=appTextC; }
        const appTitleC = String(cfg.app_title_color || '#e2e8f0');
        if (hexValid(appTitleC) && fAppTitle && fAppTitleTxt) { fAppTitle.value=appTitleC; fAppTitleTxt.value=appTitleC; }
        const appSubC = String(cfg.app_subtitle_color || '#22c55e');
        if (hexValid(appSubC) && fAppSub && fAppSubTxt) { fAppSub.value=appSubC; fAppSubTxt.value=appSubC; }
        const labelC = String(cfg.c_label_color || '#94a3b8');
        if (hexValid(labelC) && fLabelColor && fLabelColorTxt) { fLabelColor.value=labelC; fLabelColorTxt.value=labelC; }
        // Botones de App y WhatsApp: vacío = plantilla
        [['store_btn_bg', fStoreBtnBg, fStoreBtnBgTxt], ['store_btn_text', fStoreBtnText, fStoreBtnTextTxt], ['store_btn_icon', fStoreBtnIcon, fStoreBtnIconTxt], ['wa_btn_bg', fWaBtnBg, fWaBtnBgTxt], ['wa_btn_text', fWaBtnText, fWaBtnTextTxt], ['clock_time_color', fClockTime, fClockTimeTxt], ['clock_date_color', fClockDate, fClockDateTxt]].forEach(function(t){
            const v = String(cfg[t[0]] || '');
            if (t[1] && t[2]){ t[2].value = hexValid(v) ? v : ''; if (hexValid(v)) t[1].value = v; }
        });
        const stationC = String(cfg.station_name_color || '#22c55e');
        if (hexValid(stationC) && fStationName && fStationNameTxt) { fStationName.value=stationC; fStationNameTxt.value=stationC; }
        const songC = String(cfg.song_title_color || '#ffffff');
        if (hexValid(songC) && fSongColor && fSongColorTxt) { fSongColor.value=songC; fSongColorTxt.value=songC; }
        // Play/Pausa y volumen: vacío = usa el color acento
        const playBtnC = String(cfg.player_btn_color || '');
        if (fPlayBtn && fPlayBtnTxt){ fPlayBtnTxt.value = hexValid(playBtnC) ? playBtnC : ''; if (hexValid(playBtnC)) fPlayBtn.value = playBtnC; }
        const volC = String(cfg.player_vol_color || '');
        if (fVolColor && fVolColorTxt){ fVolColorTxt.value = hexValid(volC) ? volC : ''; if (hexValid(volC)) fVolColor.value = volC; }
        // Staff + Programación: reconstruir desde la config servida
        if (staffList){ staffList.innerHTML = ''; (cfg.staff || []).forEach(function(m){ addStaffRow(m); }); }
        if (Array.isArray(cfg.programacion)){
            // Fusiona duplicados del formato legacy (mismo nombre+horario+conductor → un solo programa con días unidos)
            const merged = {};
            cfg.programacion.forEach(function(b){
                let dias = (b && Array.isArray(b.dias)) ? b.dias.map(function(x){ return parseInt(x, 10); }).filter(function(x){ return x >= 1 && x <= 7; }) : [];
                if (dias.length === 0 && b && b.dia){ const n = parseInt(b.dia, 10); if (n >= 1 && n <= 7) dias = [n]; }
                const titulo = (b && b.titulo) || '';
                if (titulo === '' || dias.length === 0) return;
                const conductor = (b && (b.conductor || b.desc)) || '';
                const key = titulo + '|' + ((b && b.inicio) || '') + '|' + ((b && b.fin) || '') + '|' + conductor;
                if (!merged[key]) merged[key] = { dias: [], inicio: (b && b.inicio) || '', fin: (b && b.fin) || '', titulo: titulo, conductor: conductor };
                dias.forEach(function(d){ if (merged[key].dias.indexOf(d) < 0) merged[key].dias.push(d); });
                merged[key].dias.sort(function(a, b2){ return a - b2; });
            });
            progState = Object.keys(merged).map(function(k){ return merged[k]; });
        } else {
            progState = [];
        }
        ppProgRender();
        ppLinksLoad(cfg);
        // Colores por sección (vacío = plantilla)
        SEC_STYLE_KEYS.forEach(function(k){
            const icon = document.getElementById('pp-sec-' + k + '-icon');
            if (icon) icon.checked = cfg['sec_' + k + '_icon'] !== false;
            ['cardbg'].forEach(function(w){
                const op = document.getElementById('pp-sec-' + k + '-' + w + '-op');
                const opVal = document.getElementById('pp-sec-' + k + '-' + w + '-op-val');
                let ov = parseInt(cfg['sec_' + k + (w === 'bg' ? '_bg' : '_card_bg') + '_opacity'], 10);
                if (isNaN(ov)) ov = (w === 'bg') ? 60 : 100;
                ov = Math.max(5, Math.min(100, ov));
                if (op) op.value = ov;
                if (opVal) opVal.textContent = ov + '%';
            });
            SEC_STYLE_FIELDS[k].forEach(function(f){
                const t = secStyTxt(k, f);
                const p = secSty(k, f);
                const v = String(cfg['sec_' + k + '_' + f] || '');
                if (t) t.value = hexValid(v) ? v : '';
                if (p && hexValid(v)) p.value = v;
            });
        });
    }

    window.loadPPConfigFromServer = function(){
        fetch('autodj_api.php?action=get_page_config&mount='+encodeURIComponent(mount), {cache:'no-store'})
            .then(function(r){ return r && r.ok ? r.json() : null; })
            .then(function(j){ if(j) applyCfgToForm(j); })
            .catch(function(err){ showAlert(cfgAlert,'err','Error cargando config: '+(err&&err.message?err.message:err)); });
    };
    loadPPConfigFromServer();

    window.savePPConfig = function(){
        const saveBtn = document.getElementById('pp-save-btn');
        const payload = {
            title: (fTitle && fTitle.value.trim() === '') ? null : String((fTitle && fTitle.value) ? fTitle.value : null),
            subtitle: String((fSubtitle && fSubtitle.value) ? fSubtitle.value : ''),
            accent_color: hexValid(fAccentTxt.value) ? fAccentTxt.value : fAccent.value,
            bg_color_base: hexValid(fBgColorBaseTxt.value) ? fBgColorBaseTxt.value : fBgColorBase.value,
            header_bg_color: hexValid(fBgColorHeaderTxt.value) ? fBgColorHeaderTxt.value : fBgColorHeader.value,
            main_bg_color: hexValid(fBgColorMainTxt.value) ? fBgColorMainTxt.value : fBgColorMain.value,
            footer_bg_color: hexValid(fBgColorFooterTxt.value) ? fBgColorFooterTxt.value : fBgColorFooter.value,
            bg_overlay_opacity: Math.max(10, Math.min(90, parseInt(fOverlay.value,10)||75)),
            header_bg_opacity: Math.max(10, Math.min(90, parseInt((fHdrOpacity && fHdrOpacity.value) ? fHdrOpacity.value : '90', 10) || 90)),
            main_bg_opacity: Math.max(10, Math.min(90, parseInt((fMainOpacity && fMainOpacity.value) ? fMainOpacity.value : '85', 10) || 85)),
            footer_bg_opacity: Math.max(10, Math.min(90, parseInt((fFtrOpacity && fFtrOpacity.value) ? fFtrOpacity.value : '90', 10) || 90)),
            header_text_color: fHdrTextTxt ? (hexValid(fHdrTextTxt.value) ? fHdrTextTxt.value : fHdrText.value) : '',
            header_icon_color: fHdrIconTxt ? (hexValid(fHdrIconTxt.value) ? fHdrIconTxt.value : fHdrIcon.value) : '',
            footer_text_color: fFtrTextTxt ? (hexValid(fFtrTextTxt.value) ? fFtrTextTxt.value : fFtrText.value) : '',
            app_text_color: fAppTextTxt ? (hexValid(fAppTextTxt.value) ? fAppTextTxt.value : fAppText.value) : '',
            app_title_color: fAppTitleTxt ? (hexValid(fAppTitleTxt.value) ? fAppTitleTxt.value : fAppTitle.value) : '',
            app_subtitle_color: fAppSubTxt ? (hexValid(fAppSubTxt.value) ? fAppSubTxt.value : fAppSub.value) : '',
            c_label_color: fLabelColorTxt ? (hexValid(fLabelColorTxt.value) ? fLabelColorTxt.value : fLabelColor.value) : '',
            store_btn_bg: (fStoreBtnBgTxt && hexValid(fStoreBtnBgTxt.value)) ? fStoreBtnBgTxt.value.trim() : '',
            store_btn_text: (fStoreBtnTextTxt && hexValid(fStoreBtnTextTxt.value)) ? fStoreBtnTextTxt.value.trim() : '',
            store_btn_icon: (fStoreBtnIconTxt && hexValid(fStoreBtnIconTxt.value)) ? fStoreBtnIconTxt.value.trim() : '',
            wa_btn_bg: (fWaBtnBgTxt && hexValid(fWaBtnBgTxt.value)) ? fWaBtnBgTxt.value.trim() : '',
            wa_btn_text: (fWaBtnTextTxt && hexValid(fWaBtnTextTxt.value)) ? fWaBtnTextTxt.value.trim() : '',
            clock_time_color: (fClockTimeTxt && hexValid(fClockTimeTxt.value)) ? fClockTimeTxt.value.trim() : '',
            clock_date_color: (fClockDateTxt && hexValid(fClockDateTxt.value)) ? fClockDateTxt.value.trim() : '',
            station_name_color: fStationNameTxt ? (hexValid(fStationNameTxt.value) ? fStationNameTxt.value : fStationName.value) : '',
            song_title_color: fSongColorTxt ? (hexValid(fSongColorTxt.value) ? fSongColorTxt.value : fSongColor.value) : '',
            player_btn_color: (fPlayBtnTxt && hexValid(fPlayBtnTxt.value)) ? fPlayBtnTxt.value.trim() : '',
            player_vol_color: (fVolColorTxt && hexValid(fVolColorTxt.value)) ? fVolColorTxt.value.trim() : '',
            show_share: !!(fShowShare && fShowShare.checked),
            show_logo_when_cover: !!(fLogoWhenCover && fLogoWhenCover.checked),
            show_staff: !!(fShowStaff && fShowStaff.checked),
            show_programacion: !!(fShowProg && fShowProg.checked),
            website_url: String((fWebsite && fWebsite.value) ? fWebsite.value.trim() : ''),
            facebook_url: String((fFacebook && fFacebook.value) ? fFacebook.value.trim() : ''),
            whatsapp_url: String((fWhatsapp && fWhatsapp.value) ? fWhatsapp.value.trim() : ''),
            instagram_url: String((fInstagram && fInstagram.value) ? fInstagram.value.trim() : ''),
            tiktok_url: String((fTiktok && fTiktok.value) ? fTiktok.value.trim() : ''),
            youtube_url: String((fYoutube && fYoutube.value) ? fYoutube.value.trim() : ''),
            x_url: String((fX && fX.value) ? fX.value.trim() : ''),
            appstore_url: String((fAppStore && fAppStore.value) ? fAppStore.value.trim() : ''),
            playstore_url: String((fPlayStore && fPlayStore.value) ? fPlayStore.value.trim() : ''),
            share_url: String((fShare && fShare.value) ? fShare.value.trim() : ''),
            email_contacto: String((fEmail && fEmail.value) ? fEmail.value.trim() : ''),
            nosotros: String((fNosotros && fNosotros.value) ? fNosotros.value : ''),
            nosotros_html: !!(fNosotrosHtml && fNosotrosHtml.checked),
            terminos: String((fTerminos && fTerminos.value) ? fTerminos.value : ''),
            terminos_html: !!(fTerminosHtml && fTerminosHtml.checked),
            staff: staffList ? Array.from(staffList.querySelectorAll('.pp-dyn-row')).map(function(r){
                return {
                    id: r.getAttribute('data-id') || '',
                    foto: r.getAttribute('data-foto') || '',
                    nombre: (rowQ(r, 'nombre') ? rowQ(r, 'nombre').value : '').trim(),
                    cargo: (rowQ(r, 'cargo') ? rowQ(r, 'cargo').value : '').trim(),
                    desc: (rowQ(r, 'desc') ? rowQ(r, 'desc').value : '').trim()
                };
            }).filter(function(m){ return m.nombre !== '' || m.cargo !== '' || m.desc !== '' || m.foto !== ''; }) : [],
        };
        // Programación: la lista se edita/valida con el editor de programas (ppProgSave)
        payload.programacion = progState.map(function(p){ return { dias: p.dias.slice(), inicio: p.inicio, fin: p.fin, titulo: p.titulo, conductor: p.conductor }; });
        // Secciones vinculadas (patrocinadores / radios / escuchanos)
        if (!ppLinksPayload(payload)) return;
        // Colores por sección (vacío = plantilla; se envía solo si hay hex válido)
        SEC_STYLE_KEYS.forEach(function(k){
            const icon = document.getElementById('pp-sec-' + k + '-icon');
            payload['sec_' + k + '_icon'] = !!(icon && icon.checked);
            ['cardbg'].forEach(function(w){
                const op = document.getElementById('pp-sec-' + k + '-' + w + '-op');
                let ov = op ? parseInt(op.value, 10) : NaN;
                if (isNaN(ov)) ov = (w === 'bg') ? 60 : 100;
                ov = Math.max(5, Math.min(100, ov));
                payload['sec_' + k + (w === 'bg' ? '_bg' : '_card_bg') + '_opacity'] = ov;
            });
            SEC_STYLE_FIELDS[k].forEach(function(f){
                const t = secStyTxt(k, f);
                payload['sec_' + k + '_' + f] = (t && hexValid(t.value)) ? t.value.trim() : '';
            });
        });
        // === Validación mínima (staff) ===
        for (let i = 0; i < payload.staff.length; i++){
            const m = payload.staff[i];
            if (m.nombre === '' && (m.cargo !== '' || m.desc !== '' || m.foto !== '')){
                showAlert(cfgAlert, 'err', 'El nombre del miembro de Staff #' + (i + 1) + ' es obligatorio.'); return;
            }
        }
        if (saveBtn) saveBtn.setAttribute('disabled','true');
        showAlert(cfgAlert,'ok','Guardando configuración...');
        fetch('autodj_api.php?action=save_page_config&mount='+encodeURIComponent(mount), {
            method:'POST', credentials:'same-origin',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        })
            .then(function(r){ return r.json().then(function(j){ return {ok:r.ok, j:j}; }); })
            .then(function(res){
                const j = res && res.j ? res.j : null;
                if (j && j.success) {
                    showAlert(cfgAlert,'ok','¡Configuración guardada!');
                    if (j.config) applyCfgToForm(j.config);
                } else {
                    showAlert(cfgAlert,'err', j && j.error ? j.error : 'Error al guardar.');
                }
                if (saveBtn) saveBtn.removeAttribute('disabled');
            })
            .catch(function(err){
                showAlert(cfgAlert,'err','Error de red: ' + (err&&err.message?err.message:err));
                if (saveBtn) saveBtn.removeAttribute('disabled');
            });
    };
})();

window.ppLinksTab = function(btn){
    var key = btn.getAttribute('data-linktab');
    var root = document.getElementById('pp-pane-links');
    if (!root) return;
    var btns = root.querySelectorAll('.pp-subtab');
    for (var i = 0; i < btns.length; i++) btns[i].classList.remove('active');
    btn.classList.add('active');
    var panes = root.querySelectorAll('.pp-links-pane');
    for (var j = 0; j < panes.length; j++) panes[j].classList.remove('active');
    var target = document.getElementById('pp-links-pane-' + key);
    if (target) target.classList.add('active');
};

window.ppSwitchTab = function(btn){
    var tabName = btn.getAttribute('data-tab');
    var root = document.getElementById('view-public-page');
    if (!root) return;
    var tabs = root.querySelectorAll('.pp-tab');
    for (var i = 0; i < tabs.length; i++) tabs[i].classList.remove('active');
    btn.classList.add('active');
    var panes = root.querySelectorAll('.pp-pane');
    for (var j = 0; j < panes.length; j++) panes[j].classList.remove('active');
    var target = document.getElementById(tabName);
    if (target) target.classList.add('active');
};
</script>
