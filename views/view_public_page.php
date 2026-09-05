<?php
$_stream_host_pp = defined('STREAM_HOST') ? STREAM_HOST : 'stream.radioscr.com';
$_mount_view_pp = $mount_clean ?? ($radio['mountpoint'] ?? 'milimonradio');
$_pg_url_pretty = 'https://' . $_stream_host_pp . '/web/' . rawurlencode($_mount_view_pp);
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

    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; align-items:start;">

        <!-- COLUMNA IZQUIERDA: Formulario de configuración -->
        <div style="display:flex; flex-direction:column; gap:16px;">

            <!-- Card: Logo -->
            <div class="card p-4" style="border:1px solid #1e293b;">
                <h4 style="margin:0 0 12px 0; color:#38bdf8; font-size:1rem; display:flex; align-items:center; gap:8px;">
                    Logo de la Radio
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

            <!-- Card: Textos + Colores -->
            <div class="card p-4" style="border:1px solid #1e293b;">
                <h4 style="margin:0 0 12px 0; color:#38bdf8; font-size:1rem; display:flex; align-items:center; gap:8px;">
                    Título, Colores y Opciones
                </h4>
                <form onsubmit="event.preventDefault(); savePPConfig();" style="display:flex; flex-direction:column; gap:12px;">
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
                    <div style="border-top:1px dashed #1e293b; padding-top:12px; margin-top:8px;">
                        <div style="font-size:0.8rem; color:#cbd5e1; font-weight:700; margin-bottom:10px;">Fondos y Transparencias (0.10 = transparente … 0.90 = opaco)</div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                            <div>
                                <label style="display:block; font-size:0.78rem; color:var(--text-muted); font-weight:700; margin-bottom:4px;">Color encima del fondo/imagen:</label>
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
                            <div>
                                <label style="display:block; font-size:0.78rem; color:var(--text-muted); font-weight:700; margin-bottom:4px;">Color Fondo Cabecera:</label>
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
                                <label style="display:block; font-size:0.78rem; color:var(--text-muted); font-weight:700; margin-bottom:4px;">Color Fondo Contenedor (App / Player):</label>
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
                                <label style="display:block; font-size:0.78rem; color:var(--text-muted); font-weight:700; margin-bottom:4px;">Color Fondo Pie (Footer):</label>
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
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 8px; padding-top:4px;">
                        <label style="display:flex; align-items:center; gap:8px; border:1px solid #1e293b; padding:8px 10px; border-radius:6px; cursor:pointer;">
                            <input type="checkbox" id="pp-show-share" checked style="width:16px; height:16px; accent-color:#22c55e;">
                            <span style="font-size:0.82rem;">Mostrar botones de redes y compartir</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; border:1px solid #1e293b; padding:8px 10px; border-radius:6px; cursor:pointer;" title="Marca esto si prefieres que SIEMPRE se vea el logo, incluso cuando la canción tiene carátula">
                            <input type="checkbox" id="pp-logo-when-cover" style="width:16px; height:16px; accent-color:#22c55e;">
                            <span style="font-size:0.82rem;">Mostrar logo SIEMPRE (no reemplazar por carátula)</span>
                        </label>
                    </div>

                    <!-- NUEVA SECCION: Links Redes Sociales y Web -->
                    <div style="border-top:1px dashed #1e293b; padding-top: 12px; margin-top: 4px;">
                        <div style="font-size:0.78rem; color:var(--text-muted); font-weight:700; margin-bottom:10px;">Enlaces Redes / Sitio Web (aparecen como iconos en el player)</div>
                        <div style="display:grid; grid-template-columns: 1fr; gap:10px;">
                            <div>
                                <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">Página Web:</label>
                                <input type="url" id="pp-website" maxlength="300" placeholder="https://tuweb.com o tuweb.com" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">Facebook:</label>
                                <input type="url" id="pp-facebook" maxlength="300" placeholder="https://facebook.com/tupagina" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                            </div>
                            <div>
                                <label style="display:block; font-size:0.76rem; color:#cbd5e1; font-weight:600; margin-bottom:4px;">WhatsApp:</label>
                                <input type="text" id="pp-whatsapp" maxlength="200" placeholder="https://wa.me/50612345678 o solo numero: +506 1234-5678" style="width:100%; padding:10px; border-radius:6px; border:1px solid #1e293b; color:#fff;">
                            </div>
                        </div>
                    </div>

                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:6px; justify-content:flex-end;">
                        <button type="button" class="btn btn-info btn-sm" onclick="loadPPConfigFromServer();">Restablecer valores actuales</button>
                        <button type="submit" class="btn btn-success" id="pp-save-btn" style="padding:10px 20px;">Guardar Configuración</button>
                    </div>
                    <div id="pp-cfg-alert" style="display:none;" class="alert"></div>
                </form>
            </div>
        </div>

        <!-- COLUMNA DERECHA: Preview iframe -->
        <div style="display:flex; flex-direction:column; gap:10px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
                <h4 style="margin:0; color:#cbd5e1; font-size:1rem; display:flex; align-items:center; gap:8px;">
                    Vista Previa en Vivo
                </h4>
                <button type="button" class="btn btn-sm btn-primary" onclick="refreshPPIframe();">Refrescar</button>
            </div>
            <div style="border-radius:14px; overflow:hidden; border:2px solid #1e293b; background:#000; aspect-ratio: 9 / 16; max-height: 92vh; min-height: 560px; box-shadow:0 20px 60px rgba(0,0,0,0.6);">
                <iframe id="pp-iframe" src="<?= htmlspecialchars($_pg_url_direct) ?>" title="Vista previa página pública"
                    style="width:100%; height:100%; border:0; display:block; background:#000;"
                    loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                    sandbox="allow-same-origin allow-scripts allow-forms allow-popups"></iframe>
            </div>
            <div style="font-size:0.75rem; color:var(--text-muted); text-align:center;">
                La vista previa se refrescará automáticamente después de guardar logo, fondo o configuración.
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    const mount = (window.RADIO_CONFIG && window.RADIO_CONFIG.mount) ? window.RADIO_CONFIG.mount : "";
    const pageUrlPretty = <?= json_encode($_pg_url_pretty, JSON_UNESCAPED_UNICODE) ?>;
    const pageUrl = <?= json_encode($_pg_url_direct, JSON_UNESCAPED_UNICODE) ?>;
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
    const fWebsite = document.getElementById('pp-website');
    const fFacebook = document.getElementById('pp-facebook');
    const fWhatsapp = document.getElementById('pp-whatsapp');
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
    const iframe = document.getElementById('pp-iframe');

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
                    refreshPPIframe();
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
        if (fWebsite) fWebsite.value = String(cfg.website_url || '');
        if (fFacebook) fFacebook.value = String(cfg.facebook_url || '');
        if (fWhatsapp) fWhatsapp.value = String(cfg.whatsapp_url || '');
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
    }

    window.refreshPPIframe = function(){
        if (!iframe) return;
        const sep = (pageUrl.indexOf('?') >= 0 ? '&' : '?');
        iframe.src = pageUrl + sep + '_r=' + Date.now();
    };

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
            show_share: !!(fShowShare && fShowShare.checked),
            show_logo_when_cover: !!(fLogoWhenCover && fLogoWhenCover.checked),
            website_url: String((fWebsite && fWebsite.value) ? fWebsite.value.trim() : ''),
            facebook_url: String((fFacebook && fFacebook.value) ? fFacebook.value.trim() : ''),
            whatsapp_url: String((fWhatsapp && fWhatsapp.value) ? fWhatsapp.value.trim() : ''),
        };
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
                    showAlert(cfgAlert,'ok','¡Configuración guardada! Refrescando vista previa...');
                    if (j.config) applyCfgToForm(j.config);
                    refreshPPIframe();
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
</script>
