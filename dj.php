<?php
session_start();
$db_file = 'database.json';
$db = json_decode(file_get_contents($db_file), true);

if (isset($_POST['login'])) {
    $mount = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($_POST['mountpoint']));
    $pass =$_POST['password'];

    if (isset($db['estaciones'][$mount]) &&$db['estaciones'][$mount]['panel_pass'] ===$pass) {
        $_SESSION['dj_mount'] =$mount;
    } else {
        $error = "Punto de montaje o contraseña incorrectos.";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: dj.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Panel de Locutor</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: white; padding: 20px; }
        .card { background: #1e293b; padding: 20px; border-radius: 8px; max-width: 500px; margin: auto; }
        input, button { display: block; width: 100%; margin-bottom: 10px; padding: 10px; box-sizing: border-box; }
        button { background: #166534; color: white; border: none; cursor: pointer; }
        .data { background: #0f172a; padding: 10px; border-radius: 4px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="card">
        <?php if (!isset($_SESSION['dj_mount'])): ?>
            <h2>Acceso a Cabina</h2>
            <?php if (isset($error)) echo "<p style='color:red;'>$error</p>"; ?>
            <form method="POST">
                <input type="text" name="mountpoint" placeholder="Punto de Montaje (ej. principal)" required>
                <input type="password" name="password" placeholder="Contraseña del Panel" required>
                <button type="submit" name="login">Ver mis datos</button>
            </form>
        <?php else: ?>
            <?php 
                $mount = $_SESSION['dj_mount'];$datos = $db['estaciones'][$mount];
            ?>
            <h2>Bienvenido, <?= $datos['nombre_dj'] ?> <a href="?logout=1" style="font-size:12px; color:red; float:right;">Salir</a></h2>
            <p>Configura tu encoder (BUTT, OBS, Mixxx) con los siguientes datos exactos:</p>
            <div class="data">
                <p><strong>Servidor/IP:</strong> stream.radioscr.com</p>
                <p><strong>Puerto:</strong> 8000</p>
                <p><strong>Punto de Montaje:</strong> /<?= $mount ?></p>
                <p><strong>Usuario:</strong> source (o dejar en blanco)</p>
                <p><strong>Contraseña:</strong> <?= $datos['encoder_pass'] ?></p>
            </div>
            <p style="margin-top:20px; color:#4ade80;">Tu enlace público para los oyentes es:<br>
            [https://stream.radioscr.com/](https://stream.radioscr.com/)<?= $mount ?></p>
        <?php endif; ?>
    </div>
</body>
</html>
