<aside class="sidebar" id="cabinaSidebar">
    <div class="brand">
        <div class="brand-logo">📻</div>
        <div>
            <h1 class="brand-title"><?= htmlspecialchars((string)($_SESSION['cliente_nombre'] ?? 'Usuario')) ?></h1>
            <div class="brand-mount">/<?= htmlspecialchars((string)($mount_clean ?? 'sin-mount')) ?></div>
        </div>
    </div>

    <?php include __DIR__ . '/radio_switcher.php';

    // Modo de radio actual: modo directa = solo Cabina Vivo visible
    $_modo_radio_actual = !empty($radio['modo_radio']) && in_array($radio['modo_radio'], ['autodj', 'directa'], true) ? $radio['modo_radio'] : 'autodj';
    $_es_modo_autodj = ($_modo_radio_actual !== 'directa');
    ?>

<nav class="nav-menu">
    <button class="nav-item active" onclick="switchView('view-live', this)">
        <span class="icon">📡</span> Cabina en Vivo
    </button>
<?php if ($_es_modo_autodj): ?>
    <button class="nav-item" onclick="switchView('view-musicateca', this)">
        <span class="icon">📁</span> Musicateca
    </button>
    <button class="nav-item" onclick="switchView('view-playlists', this)">
        <span class="icon">🎵</span> Playlists
    </button>
    <button class="nav-item" onclick="switchView('view-reloj', this)">
        <span class="icon">⏰</span> Programación
    </button>
    <button class="nav-item" onclick="switchView('view-anuncios', this)">
        <span class="icon">📢</span> Anuncios / Spots
    </button>
<?php endif; ?>
    <button class="nav-item" onclick="switchView('view-public-page', this)">
        <span class="icon">🌐</span> Página Pública
    </button>
<?php if ($_es_modo_autodj): ?>
    <button class="nav-item" onclick="switchView('view-ajustes', this)">
        <span class="icon">⚙️</span> Ajustes AutoDJ
    </button>
<?php endif; ?>
</nav>


   <!-- Bitrate Cliente: SOLO mostrar el numero N kbps. Nada más (sin barras / textos / descripciones) -->
   <?php $_bitrate_user = (int)($radio['bitrate'] ?? 128); ?>
   <div style="margin: 10px 15px 2px; padding: 10px 12px; background:#0f172a; border:1px solid #1e293b; border-radius: 8px;">
        <div style="font-size: 0.7rem; color:#94a3b8; text-transform: uppercase; letter-spacing: 0.5px; font-weight:600; margin-bottom:6px;">Bitrate</div>
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
            <div style="font-weight: 800; color:#f8fafc; font-size:1.1rem; letter-spacing:0.4px;"><?= (int)$_bitrate_user ?> kbps</div>
            <span style="background:#2563eb; color:#fff; font-size:0.7rem; font-weight:700; padding:3px 9px; border-radius:99px;">MP3</span>
        </div>
   </div>


   <div style="padding: 15px; border-top: 1px solid #1e293b; margin-top: auto;">
        <div id="side-autodj-status" style="font-size: 0.82rem; font-weight: bold; margin-bottom: 10px; color: #94a3b8;">
            ● Cargando...
        </div>

        <div style="display: flex; flex-direction: column; gap: 8px;">
            <button type="button"
                    id="side-btn-start"
                    onclick="toggleAutoDJ('start')"
                    style="background: linear-gradient(180deg, #16a34a, #15803d); color: #fff; border: none; padding: 10px 12px; border-radius: 6px; font-weight: bold; font-size: 0.88rem; cursor: pointer; align-items: center; justify-content: center; gap: 6px; width: 100%; transition: background 0.2s; display: flex; box-shadow: 0 4px 14px rgba(22,163,74,0.3);">
                ▶️ Iniciar Radio
            </button>
            <button type="button"
                    id="side-btn-stop"
                    onclick="toggleAutoDJ('stop')"
                    style="background: #dc2626; color: #fff; border: none; padding: 9px 12px; border-radius: 6px; font-weight: bold; font-size: 0.85rem; cursor: pointer; align-items: center; justify-content: center; gap: 6px; width: 100%; transition: background 0.2s; display: none;">
                ■ Apagar Radio
            </button>
        </div>
    </div>
</aside>
