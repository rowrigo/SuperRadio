<?php
session_start();
$db_file = 'database.json';

// Cargar la base de datos
$db = json_decode(file_get_contents($db_file), true);

// Manejo de sesión de Admin
if (isset($_POST['login'])) {
    if ($_POST['password'] === $db['admin']['password']) {
        $_SESSION['admin_logged'] = true;
    } else {
        $error = "Contraseña incorrecta";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

// Proceso para agregar nueva estación
if (isset($_POST['nueva_estacion']) && isset($_SESSION['admin_logged'])) {
    $mount = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($_POST['mountpoint'])); // Limpiar input
    
    $db['estaciones'][$mount] = [
        "nombre_dj" => htmlspecialchars($_POST['nombre_dj']),
        "panel_pass" => $_POST['panel_pass'],
        "encoder_pass" => $_POST['encoder_pass']
    ];
    
    // Guardar en el JSON
    file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT));
    $success = "Estación /{$mount} creada con éxito.";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Panel de Administración</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: white; padding: 20px; }
        .card { background: #1e293b; padding: 20px; border-radius: 8px; max-width: 500px; margin: auto; }
        input, button { display: block; width: 100%; margin-bottom: 10px; padding: 10px; box-sizing: border-box; }
        button { background: #0284c7; color: white; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <?php if (!isset($_SESSION['admin_logged'])): ?>
            <h2>Login Admin</h2>
            <?php if (isset($error)) echo "<p style='color:red;'>$error</p>"; ?>
            <form method="POST">
                <input type="password" name="password" placeholder="Contraseña de Admin" required>
                <button type="submit" name="login">Entrar</button>
            </form>
        <?php else: ?>
            <h2>Panel de Administración <a href="?logout=1" style="font-size:12px; color:red; float:right;">Salir</a></h2>
            
            <?php if (isset($success)) echo "<p style='color:lightgreen;'>$success</p>"; ?>
            
            <h3>Crear Nueva Emisora/DJ</h3>
            <form method="POST">
                <input type="text" name="nombre_dj" placeholder="Nombre de la Emisora (Ej. Radio Caribe)" required>
                <input type="text" name="mountpoint" placeholder="Punto de Montaje (Ej. principal)" required>
                <input type="text" name="panel_pass" placeholder="Contraseña para el Panel del DJ" required>
                <input type="text" name="encoder_pass" placeholder="Contraseña para el Encoder (BUTT/OBS)" required>
                <button type="submit" name="nueva_estacion">Guardar Estación</button>
            </form>

            <hr>
            <h3>Estaciones Activas</h3>
            <ul>
                <?php foreach ($db['estaciones'] as $mount => $datos): ?>
                    <li><strong>/<?= $mount ?></strong> - <?= $datos['nombre_dj'] ?> (Pass Encoder: <?= $datos['encoder_pass'] ?>)</li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</body>
</html>
