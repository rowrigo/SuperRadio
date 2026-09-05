<?php
$user_data = $db['usuarios'][$_SESSION['cliente_user']] ?? [];
$radio_ids = $user_data['radio_ids'] ?? (!empty($user_data['radio_id']) ? [$user_data['radio_id']] : (!empty($_SESSION['radio_id']) ? [$_SESSION['radio_id']] : []));
$user_radios = [];
foreach (($db['radios'] ?? []) as $rid => $r) {
    if (in_array($rid, $radio_ids, true)) {
        $user_radios[$rid] = $r;
    }
}
// Si por alguna razón está vacío, al menos la sesión actual
if (empty($user_radios) && !empty($_SESSION['radio_id']) && !empty($db['radios'][$_SESSION['radio_id']])) {
    $user_radios[$_SESSION['radio_id']] = $db['radios'][$_SESSION['radio_id']];
}
?>

<?php if (count($user_radios) > 1): ?>
<div style="padding: 10px 15px; border-bottom: 1px solid #1e293b;">
    <label style="color: #94a3b8; font-size: 0.72rem; font-weight: bold; display: block; margin-bottom: 6px; text-transform: uppercase;">
        📻 Cambiar Emisora
    </label>
    <select onchange="window.location.href='panel.php?view=<?= htmlspecialchars($_GET['view'] ?? 'live') ?>&mount=' + encodeURIComponent(this.value);" 
            style="width: 100%; background: #060b17; color: #38bdf8; border: 1px solid #334155; padding: 7px 10px; border-radius: 6px; font-size: 0.85rem; font-weight: bold; cursor: pointer;">
        <?php foreach ($user_radios as $rid => $r): 
            $m = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $r['mountpoint'] ?? '')));
        ?>
            <option value="<?= htmlspecialchars($m) ?>" <?= ($m === $mount_clean) ? 'selected' : '' ?>>
                <?= htmlspecialchars($r['nombre_emisora']) ?> (/<?= htmlspecialchars($m) ?>)
            </option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>
