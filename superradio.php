<?php
session_start();
require_once __DIR__ . '/config.php';
$_LT_SA = login_texts_get();
$_LTS = $_LT_SA['superadmin'];
$_LT403SA = $_LT_SA['ip403'];

// 🌐 IP THROTTLE CHECK (ANTES DE CUALQUIER COSA): si IP bloqueada → 403, NI se muestra el login (igual DirectAdmin)
$_ip_chk_sa = sec_ip_check_can_login();
if (!$_ip_chk_sa['can']) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    $ip_ban_msg = $_ip_chk_sa['message'];
    $ip_ban_time = $_ip_chk_sa['time_left_txt'];
    $ip_ban_until = date('Y-m-d H:i:s', (int)$_ip_chk_sa['blocked_until_ts']);
    $ip_addr = htmlspecialchars($_ip_chk_sa['ip']);
    $note_lbl = htmlspecialchars($_LT403SA['note_label']);
    $title_403 = htmlspecialchars($_LT403SA['title']);
    $subtitle_403 = htmlspecialchars($_LT403SA['subtitle']);
    $lbl_ip = htmlspecialchars($_LT403SA['ip_label']);
    $lbl_tl = htmlspecialchars($_LT403SA['timeleft_label']);
    $lbl_un = htmlspecialchars($_LT403SA['until_label']);
    $f1 = htmlspecialchars($_LT403SA['footer1']);
    $f2 = htmlspecialchars($_LT403SA['footer2']);
    $fn = htmlspecialchars($_LT403SA['footer_note']);
    $fw = htmlspecialchars($_LT403SA['footer_word']);
    $manual_note = '';
    if (!empty($_ip_chk_sa['manual_comment'])) {
        $manual_note = '<div style="margin-top:14px; padding:12px 14px; background:#7f1d1d; border-left:4px solid #dc2626; border-radius:6px; font-size:0.9rem;">📝 '.$note_lbl.': <strong>'.htmlspecialchars($_ip_chk_sa['manual_comment']).'</strong></div>';
    }
    echo <<<HTMLIPBAN2
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Acceso Restringido · 403</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
body{background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 100%);display:flex;align-items:center;justify-content:center;padding:24px;color:#fff}
.card{background:#020617;border:1px solid #334155;border-radius:14px;max-width:520px;width:100%;padding:38px 34px;box-shadow:0 30px 80px rgba(0,0,0,.55)}
.ban-icon{width:68px;height:68px;border-radius:50%;background:#7f1d1d;display:flex;align-items:center;justify-content:center;font-size:2.4rem;margin:0 auto 22px;box-shadow:0 0 0 8px rgba(220,38,38,.09)}
h1{text-align:center;font-size:1.6rem;margin-bottom:6px;color:#fecaca}
.sub{text-align:center;color:#94a3b8;font-size:.9rem;margin-bottom:20px}
.msg{background:#111827;border:1px solid #374151;border-radius:8px;padding:16px 18px;color:#e5e7eb;font-size:.95rem;line-height:1.55}
.msg strong{color:#fecaca}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:20px}
.gd{background:#111827;border:1px solid #1f2937;border-radius:8px;padding:12px 14px}
.gd small{color:#94a3b8;display:block;font-size:.7rem;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.gd strong{color:#e5e7eb;font-size:.95rem;word-break:break-all}
.footer{margin-top:24px;text-align:center;color:#64748b;font-size:.78rem}
.footer span{color:#cbd5e1;font-weight:600}
</style></head><body>
<div class="card">
  <div class="ban-icon">🚫</div>
  <h1>{$title_403}</h1>
  <div class="sub">{$subtitle_403}</div>
  <div class="msg">{$ip_ban_msg}</div>
  {$manual_note}
  <div class="grid">
    <div class="gd"><small>{$lbl_ip}</small><strong>{$ip_addr}</strong></div>
    <div class="gd"><small>{$lbl_tl}</small><strong>{$ip_ban_time}</strong></div>
    <div class="gd" style="grid-column:1 / -1;"><small>{$lbl_un}</small><strong>{$ip_ban_until}</strong></div>
  </div>
  <div class="footer">{$f1} · {$f2} · {$fn} <span>{$fw}</span></div>
</div></body></html>
HTMLIPBAN2;
    exit;
}

$db_file = DB_FILE;
$db = file_exists($db_file) ? json_decode(file_get_contents($db_file), true) : ['radios' => [], 'usuarios' => []];

// --- LOGIN SUPERADMIN ---
$superadmin_user = $db['superadmin']['usuario'] ?? '';
$superadmin_hash = $db['superadmin']['password_hash'] ?? '';
// ¿Ya existe superadmin? Si NO → primera instalación: el primer visitante lo crea (estilo AzureCast)
$sa_configured = ($superadmin_user !== '' && $superadmin_hash !== '');

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php'); // entrada única de login
    exit;
}

$login_error = '';
$login_warn = '';

// --- PRIMERA INSTALACIÓN: CREAR SUPERADMIN (solo mientras NO exista ninguno) ---
if (!$sa_configured && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'superadmin_setup') {
    $u = trim($_POST['usuario'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    $em = trim($_POST['email'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $u)) {
        $login_error = "❌ El usuario debe tener entre 3 y 32 caracteres. Permitidos: letras, números, punto, guion y guion bajo.";
    } elseif ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
        $login_error = "❌ El email no es válido.";
    } elseif (strlen($p) < 8) {
        $login_error = "❌ La contraseña debe tener al menos 8 caracteres.";
    } elseif ($p !== $p2) {
        $login_error = "❌ Las contraseñas no coinciden.";
    } else {
        $db['superadmin'] = [
            'usuario'       => $u,
            'email'         => $em,
            'password_hash' => password_hash($p, PASSWORD_DEFAULT),
            'created_at'    => date('Y-m-d H:i:s'),
        ];
        file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $_SESSION['superadmin_auth'] = true;
        header('Location: superradio.php');
        exit;
    }
}

// --- ENTRADA ÚNICA: si ya hay superadmin, el login vive en index.php (raíz) ---
// (el asistente de primera instalación de arriba NO redirige: !$sa_configured)
if ($sa_configured && empty($_SESSION['superadmin_auth'])) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'superadmin_login') {
    $u = trim($_POST['usuario'] ?? '');
    $p = $_POST['password'] ?? '';
    // 1) Anti-bruteforce: mismo throttle pero key "s:username" para diferenciar de clientes
    $sec_key = 's:' . $u;
    $chk = sec_check_can_login($sec_key);
    if (!$chk['can']) {
        $login_error = $chk['message'];
        sec_ip_record_fail();
    } else {
        if ($u === $superadmin_user && !empty($superadmin_hash) && password_verify($p, $superadmin_hash)) {
            // LIMPIAR intentos al acertar
            sec_clear_throttle($sec_key);
            $_SESSION['superadmin_auth'] = true;
            header('Location: superradio.php');
            exit;
        } else {
            // FAIL -> registrar en usuario Y EN IP
            sec_ip_record_fail();
            $fail = sec_record_fail($sec_key);
            if ($fail['just_blocked']) {
                $login_error = "❌ Credenciales inválidas. Además tu usuario está bloqueado {$fail['time_txt']} por superar el límite de intentos. Se desbloquea automaticamente.";
            } else {
                $chk2 = sec_check_can_login($sec_key);
                $left = (int)$chk2['attempts_left'];
                if ($left <= 2) {
                    $login_error = "❌ Credenciales de superadministrador inválidas.";
                    $login_warn  = "⚠️ Te quedan {$left} intento(s) antes de bloquear el acceso de superadministrador 1 hora.";
                } else {
                    $login_error = "❌ Credenciales de superadministrador inválidas.";
                }
            }
        }
    }
}

$setup_mode = !$sa_configured;
if (empty($_SESSION['superadmin_auth'])):
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $setup_mode ? 'SuperRadio · Primera instalación' : 'SuperRadio · Superadministración' ?></title>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            min-height: 100vh;
            background: #111827;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            color: #111827;
            display: flex; align-items: center; justify-content: center;
        }
        body { padding: 20px; }
        .login-shell {
            width: 100%; max-width: 920px;
            display: grid; grid-template-columns: 1fr 1fr;
            background: #ffffff; border-radius: 20px; overflow: hidden;
            box-shadow: 0 30px 80px rgba(0,0,0,0.55); min-height: 520px;
        }
        .login-form-side {
            padding: 56px 50px; display: flex; flex-direction: column; justify-content: center;
        }
        .login-form-side h1 { margin: 0 0 6px 0; font-size: 1.85rem; font-weight: 800; letter-spacing: -0.02em; color: #111827; }
        .login-form-side .subtitle { margin: 0 0 26px 0; color: #6b7280; font-size: 0.95rem; }
        .tag-admin {
            display: inline-flex; align-items: center; gap: 6px;
            background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa;
            font-weight: 700; border-radius: 999px; padding: 4px 12px;
            font-size: 0.78rem; letter-spacing: 0.04em; text-transform: uppercase;
            margin-bottom: 18px; align-self: flex-start;
        }
        .field { margin-bottom: 18px; display: flex; flex-direction: column; }
        .field label { font-size: 0.92rem; font-weight: 600; color: #1f2937; margin-bottom: 8px; }
        .field .input-wrap {
            display: flex; align-items: stretch;
            border: 1px solid #d1d5db; border-radius: 6px; overflow: hidden; background: #fff;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .field .input-wrap:focus-within { border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,0.18); }
        .field input {
            flex: 1; border: 0; outline: 0; padding: 11px 14px;
            font-size: 0.95rem; color: #111827; background: transparent; font-family: inherit;
        }
        .field .toggle-pwd {
            background: #f9fafb; border: 0; border-left: 1px solid #e5e7eb;
            padding: 0 18px; font-size: 0.9rem; color: #374151; font-weight: 600; cursor: pointer;
        }
        .field .toggle-pwd:hover { background: #f3f4f6; }
        .btn-primary-login {
            margin-top: 8px; background: #7c3aed; border: none; border-radius: 8px;
            padding: 13px 16px; color: #fff; font-size: 1rem; font-weight: 800; letter-spacing: 0.02em;
            cursor: pointer; transition: background 0.15s, transform 0.05s;
        }
        .btn-primary-login:hover { background: #6d28d9; }
        .btn-primary-login:active { transform: translateY(1px); }

        .alert-box { border-radius: 8px; padding: 11px 14px; margin-bottom: 18px; font-size: 0.88rem; line-height: 1.35; }
        .alert-box.danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert-box.warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

        .footer-copyright { margin-top: 30px; color: #6b7280; font-size: 0.82rem; line-height: 1.55; }

        .login-brand-side {
            background: #7c3aed;
            color: #fff; padding: 56px 48px;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-align: center; gap: 26px; position: relative; overflow: hidden;
        }
        .login-brand-side::before {
            content: ""; position: absolute; inset: -20% -20% auto auto;
            width: 420px; height: 420px; background: radial-gradient(circle, rgba(255,255,255,0.2), transparent 62%);
            border-radius: 50%; pointer-events: none;
        }
        .login-brand-side::after {
            content: ""; position: absolute; inset: auto auto -30% -10%;
            width: 360px; height: 360px; background: radial-gradient(circle, rgba(255,255,255,0.08), transparent 62%);
            border-radius: 50%; pointer-events: none;
        }
        .login-brand-side > * { position: relative; z-index: 1; }
        .login-brand-logo { font-size: 2.45rem; font-weight: 900; letter-spacing: -0.01em; line-height: 1; }
        .login-brand-sub  { color: rgba(255,255,255,0.9); font-weight: 700; font-size: 1.1rem; }
        .login-brand-desc { color: rgba(255,255,255,0.88); font-size: 0.96rem; line-height: 1.55; max-width: 320px; margin: 0; }
        .chips { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; }
        .chip  {
            background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.25);
            padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700;
        }
        @media (max-width: 768px) {
            .login-shell { grid-template-columns: 1fr; min-height: auto; border-radius: 16px; }
            .login-brand-side { padding: 40px 28px 36px; order: -1; min-height: 230px; gap: 18px; }
            .login-form-side { padding: 34px 26px 40px; }
            .login-form-side h1 { font-size: 1.6rem; }
            .login-brand-logo { font-size: 2.1rem; }
        }
    </style>
</head>
<body>
    <?php
        if ($setup_mode) {
            // ---- PRIMERA INSTALACIÓN: textos propios del alta inicial ----
            $tag_adm = '⚡ Primera instalación';
            $ft   = 'Configura tu Superadmin';
            $fs   = 'Este panel aún no tiene administrador. Crea el usuario y la contraseña del superadministrador para activar el sistema.';
            $lu   = 'Usuario Superadmin';
            $lp   = 'Contraseña';
            $lp2  = 'Repite la contraseña';
            $bsub = 'CREAR SUPERADMIN E INGRESAR';
            $ph_u = '';
            $ph_p = '';
            $copy = '© ' . date('Y') . ' SuperRadio · Instalación inicial: el primer visitante crea el superadmin.';
            $bl = 'SUPERRADIO';
            $bt = 'Primera instalación';
            $bd = 'Bienvenido. Configura el acceso de superadministrador para empezar a gestionar emisoras, clientes y seguridad del sistema.';
            $bc = 'Autohospedado  ·  Icecast + Liquidsoap  ·  Seguro';
        } else {
            $tag_adm = htmlspecialchars($_LTS['tag_admin']);
            $ft   = htmlspecialchars($_LTS['form_title']);
            $fs   = htmlspecialchars($_LTS['form_sub']);
            $lu   = htmlspecialchars($_LTS['lbl_user']);
            $lp   = htmlspecialchars($_LTS['lbl_pwd']);
            $lp2  = '';
            $bsub = htmlspecialchars($_LTS['btn_submit']);
            $ph_u = htmlspecialchars($superadmin_user);
            $ph_p = htmlspecialchars('Tu '.$_LTS['lbl_pwd']);
            $copy = str_replace('{YEAR}', (string)date('Y'), htmlspecialchars($_LTS['copyright']));
            $bl = htmlspecialchars($_LTS['brand_logo']);
            $bt = htmlspecialchars($_LTS['brand_tagline']);
            $bd = htmlspecialchars($_LTS['brand_desc']);
            $bc = htmlspecialchars($_LTS['brand_chips']);
        }
        $bon  = json_encode($_LTS['btn_toggle_pwd_on']);
        $boff = json_encode($_LTS['btn_toggle_pwd_off']);
        $bon_txt = htmlspecialchars($_LTS['btn_toggle_pwd_on']);
        // Chips separados por 3 espacios o por ·
        $chips_html = '';
        $tmp = preg_split('/\s{3,}|·/', $bc);
        $tmp = array_values(array_filter(array_map('trim', $tmp)));
        foreach ($tmp as $c) if ($c !== '') $chips_html .= '<span class="chip">'.htmlspecialchars($c).'</span>';
    ?>
    <div class="login-shell">
        <div class="login-form-side">
            <span class="tag-admin"><?= $tag_adm ?></span>
            <h1><?= $ft ?></h1>
            <p class="subtitle"><?= $fs ?></p>

            <?php if ($login_error): ?>
                <div class="alert-box danger"><?= htmlspecialchars($login_error) ?></div>
            <?php endif; ?>
            <?php if ($login_warn): ?>
                <div class="alert-box warn"><?= htmlspecialchars($login_warn) ?></div>
            <?php endif; ?>

            <?php if ($setup_mode): ?>
            <!-- Formulario de PRIMERA INSTALACIÓN -->
            <form method="POST" autocomplete="on" novalidate>
                <input type="hidden" name="action" value="superadmin_setup">
                <div class="field">
                    <label for="sa_user"><?= $lu ?></label>
                    <div class="input-wrap">
                        <input id="sa_user" type="text" name="usuario" placeholder="<?= $ph_u ?>" required autocomplete="username">
                    </div>
                </div>
                <div class="field">
                    <label for="sa_email">Email (opcional, para contacto / remitente de correos)</label>
                    <div class="input-wrap">
                        <input id="sa_email" type="email" name="email" placeholder="email@tuemail.com" autocomplete="email">
                    </div>
                </div>
                <div class="field">
                    <label for="sa_pass"><?= $lp ?></label>
                    <div class="input-wrap">
                        <input id="sa_pass" type="password" name="password" required autocomplete="new-password" placeholder="<?= $ph_p ?>">
                    </div>
                </div>
                <div class="field">
                    <label for="sa_pass2"><?= $lp2 ?></label>
                    <div class="input-wrap">
                        <input id="sa_pass2" type="password" name="password2" required autocomplete="new-password" placeholder="<?= $ph_p ?>">
                    </div>
                </div>
                <button type="submit" class="btn-primary-login"><?= $bsub ?></button>
            </form>
            <p style="margin-top:16px; font-size:0.8rem; color:#6b7280; line-height:1.5;">🔒 Este paso solo está disponible mientras el sistema no tenga superadmin. Si ya configuraste el panel, la pantalla de acceso normal reemplaza a esta.</p>
            <?php else: ?>
            <form method="POST" autocomplete="on" novalidate>
                <input type="hidden" name="action" value="superadmin_login">
                <div class="field">
                    <label for="sa_user"><?= $lu ?></label>
                    <div class="input-wrap">
                        <input id="sa_user" type="text" name="usuario" value="<?= htmlspecialchars($superadmin_user) ?>" required autocomplete="username">
                    </div>
                </div>
                <div class="field">
                    <label for="sa_pass"><?= $lp ?></label>
                    <div class="input-wrap">
                        <input id="sa_pass" type="password" name="password" required autocomplete="current-password" placeholder="<?= $ph_p ?>">
                        <button type="button" class="toggle-pwd" id="togPwdSA"><?= $bon_txt ?></button>
                        <script>
                        (function(){
                          var i=document.getElementById('sa_pass');
                          var b=document.getElementById('togPwdSA');
                          var on=<?= $bon ?>; var off=<?= $boff ?>;
                          b.addEventListener('click', function(){
                            i.type=(i.type==='password')?'text':'password';
                            b.textContent=(i.type==='password')?on:off;
                          });
                        })();
                        </script>
                    </div>
                </div>
                <button type="submit" class="btn-primary-login"><?= $bsub ?></button>
            </form>
            <?php endif; ?>

            <div class="footer-copyright"><?= $copy ?></div>
        </div>

        <div class="login-brand-side">
            <div class="login-brand-logo"><?= $bl ?></div>
            <div class="login-brand-sub"><?= $bt ?></div>
            <p class="login-brand-desc"><?= $bd ?></p>
            <div class="chips"><?= $chips_html ?></div>
        </div>
    </div>
</body>
</html>
<?php
exit;
endif;

// --- FUNCIONES DE GESTIÓN DE PUERTOS DJ ---
// ============================================================
//  is_port_system_in_use(int $port): ¿Puerto está EN USO REALMENTE POR EL SISTEMA?
//
//  Problema resuelto: puertos_usados_radios() solo comprueba la BASE DE DATOS.
//  Pero hubo un bug GRAVE (prueba2 404 / Address already in use / E-1 Output 12):
//  PID 183048 de MILIMONRADIO tenía ABIERTO (en uso por proceso vivo) el puerto
//  8006 que correspondía a pruebados, aunque en la DB figuraba como "libre".
//  Entonces Liquidsoap intentaba bind() el puerto y moría inmediatamente, por lo
//  que output.icecast NUNCA se conectaba a Icecast → mount /pruebados NUNCA
//  existía → 404 eterno / BUTT E-1 Error -1 output 12.
//
//  Solución: ss -ltnpH sport = :$puerto devuelve NO VACÍO si el puerto está
//  actualmente LISTEN en el kernel (proceso vivo lo tiene abierto), INCLUSO si
//  ese proceso NO pertenece a ninguna radio de la database.json (huérfanos,
//  colisiones antiguas, etc).
// ============================================================
function is_port_system_in_use($port) {
    $port = (int)$port;
    if ($port <= 0) return false;
    $chk = @shell_exec("ss -ltnpH sport = :" . $port . " 2>/dev/null");
    if (!empty($chk)) return true;
    $chk2 = @shell_exec("netstat -tlnp 2>/dev/null | awk '{print $4}' | grep -E '[:\\.]" . (int)$port . "$' 2>/dev/null");
    if (!empty($chk2)) return true;
    $chk3 = @shell_exec("fuser " . (int)$port . "/tcp 2>&1");
    if (!empty($chk3)) return true;
    return false;
}
function get_pid_using_port($port) {
    $port = (int)$port;
    if ($port <= 0) return null;
    $chk = @shell_exec("ss -ltnpH sport = :" . $port . " 2>/dev/null");
    if (!empty($chk) && preg_match('/pid=(\d+)/', (string)$chk, $mm)) return (int)$mm[1];
    return null;
}
// ============================================================
//  BUG LS 2.0.2: input.harbor(port=N) reserva 2 PUERTOS (N Y N+1).
//  Por tanto: comprobar que un puerto esté "libre" = comprobar N Y N+1.
//  Asignación: N, N+2, N+4,... (impares 8005, 8007, 8009,...) nunca consecutivos.
// ============================================================
function is_port_pair_in_use($port) {
    $p0 = (int)$port;
    $p1 = $p0 + 1;
    $usado0 = is_port_system_in_use($p0);
    $usado1 = is_port_system_in_use($p1);
    if ($usado0 || $usado1) return true;
    return false;
}
function puertos_usados_radios($radios, $except_key = null) {
    $usados = [];
    foreach (($radios ?? []) as $k => $r) {
        if ($except_key !== null && $k === $except_key) continue;
        $p = (int)($r['dj_port'] ?? 0);
        if ($p >= 1024 && $p <= 65535) {
            $usados[] = $p;
            $usados[] = $p + 1;
        }
    }
    return $usados;
}
function siguiente_puerto_libre($radios, $except_key = null, $base = 8005) {
    $usados_db = puertos_usados_radios($radios, $except_key);
    if (($base % 2) === 0) $base++;
    $p = $base;
    $max_attempts = 2000;
    $tried = 0;
    while (true) {
        $tried++;
        if ($tried > $max_attempts) { break; }
        $p1 = $p + 1;
        if (in_array($p, $usados_db, true))  { $p += 2; continue; }
        if (in_array($p1, $usados_db, true)) { $p += 2; continue; }
        if (is_port_pair_in_use($p)) { $p += 2; continue; }
        break;
    }
    return $p;
}

// --- MIGRACIÓN AUTOMÁTICA: asignar dj_port a radios que no tienen o tienen duplicados ---
$needs_migrate = false;
$port_usage = [];
foreach (($db['radios'] ?? []) as $k => $r) {
    $p = (int)($r['dj_port'] ?? 0);
    if ($p < 1024 || $p > 65535) {
        $port_usage['missing'][] = $k;
        $needs_migrate = true;
    } else {
        $port_usage[$p][] = $k;
        if (count($port_usage[$p]) > 1) $needs_migrate = true;
    }
}
if ($needs_migrate) {
    foreach (($port_usage['missing'] ?? []) as $k) {
        $nuevo = siguiente_puerto_libre($db['radios'], $k);
        $db['radios'][$k]['dj_port'] = $nuevo;
    }
    foreach ($port_usage as $p => $keys) {
        if ($p === 'missing') continue;
        if (count($keys) > 1) {
            array_shift($keys);
            foreach ($keys as $k) {
                $nuevo = siguiente_puerto_libre($db['radios'], $k);
                $db['radios'][$k]['dj_port'] = $nuevo;
            }
        }
    }
    file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// --- MIGRACIÓN AUTOMÁTICA BITRATE POR DEFECTO 128 kbps (nuevo campo) ---
$ALLOWED_BITRATES = [64, 96, 128, 192, 256, 320];
$needs_bitrate_migrate = false;
foreach (($db['radios'] ?? []) as $k => $r) {
    $b = (int)($r['bitrate'] ?? 0);
    if (!in_array($b, $ALLOWED_BITRATES, true)) {
        $db['radios'][$k]['bitrate'] = 128;
        $needs_bitrate_migrate = true;
    }
}
if ($needs_bitrate_migrate) {
    file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$puerto_sugerido = siguiente_puerto_libre($db['radios']);

// --- PROCESAMIENTO DE ACCIONES ---
$msg = '';
$msg_type = '';
$_port_usage_viz = [];
foreach (($db['radios'] ?? []) as $_k => $_r) {
    $_p = (int)($_r['dj_port'] ?? 0);
    if ($_p >= 1024 && $_p <= 65535) $_port_usage_viz[$_p][] = $_k;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CREAR RADIO
    if ($action === 'create_radio') {
        $nombre = trim($_POST['nombre_emisora'] ?? '');
        $mount = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['mountpoint'] ?? ''));
        $pass = trim($_POST['encoder_pass'] ?? '');
        $dj_port = (int)($_POST['dj_port'] ?? 0);
        $modo_radio = in_array(($_POST['modo_radio'] ?? 'autodj'), ['autodj', 'directa'], true) ? $_POST['modo_radio'] : 'autodj';
        $quota_mb = (int)($_POST['quota_mb'] ?? 0);
        if ($quota_mb <= 0) $quota_mb = ($modo_radio === 'directa') ? 0 : 2048;
        // ===== BITRATE (calidad MP3) =====
        $ALLOWED_BITRATES_CREATE = [64, 96, 128, 192, 256, 320];
        $bitrate = (int)($_POST['bitrate'] ?? 128);
        if (!in_array($bitrate, $ALLOWED_BITRATES_CREATE, true)) $bitrate = 128;

        // ===== Fondo Loop Oculto (SOLO Modo Directa, admin solo) =====
        $fondo_oculto_op = ($_POST['fondo_oculto_op'] ?? 'silencio') === 'ruta' ? 'ruta' : 'silencio';
        $directa_fondo_oculto_path = '';
        if ($modo_radio === 'directa' && $fondo_oculto_op === 'ruta') {
            $directa_fondo_oculto_path = trim((string)($_POST['directa_fondo_oculto_path'] ?? ''));
            // Saneamiento de ruta: quitar ../ traversals, no permitir rutas relativas
            if ($directa_fondo_oculto_path !== '') {
                if (strpos($directa_fondo_oculto_path, '..') !== false) $directa_fondo_oculto_path = '';
                if ($directa_fondo_oculto_path !== '' && $directa_fondo_oculto_path[0] !== '/') $directa_fondo_oculto_path = '';
            }
        }
        $puerto_ok = true;

        if ($nombre && $mount && $pass) {
            $existe = false;
            foreach (($db['radios'] ?? []) as $r) {
                if (strtolower($r['mountpoint'] ?? '') === strtolower($mount)) { $existe = true; break; }
            }
            if ($existe) {
                $msg = "Ya existe una emisora con el mountpoint '{$mount}'.";
                $msg_type = "danger";
                $puerto_ok = false;
            } else {
                if ($dj_port <= 0) {
                    $dj_port = siguiente_puerto_libre($db['radios']);
                } else {
                    if ($dj_port < 1024 || $dj_port > 65535) {
                        $msg = "El puerto DJ debe estar entre 1024 y 65535.";
                        $msg_type = "danger";
                        $puerto_ok = false;
                    } else {
                        $usados = puertos_usados_radios($db['radios']);
                        if (in_array($dj_port, $usados, true)) {
                            $recom = siguiente_puerto_libre($db['radios']);
                            $msg = "El puerto DJ {$dj_port} (o el puerto siguiente {$dj_port} + 1) ya está ocupado por otra emisora. Liquidsoap reserva 2 puertos seguidos (N y N+1). Prueba con el puerto {$recom} (confirmado libre para pareja).";
                            $msg_type = "danger";
                            $puerto_ok = false;
                        } elseif (is_port_pair_in_use($dj_port)) {
                            $pid_bad_p0 = get_pid_using_port($dj_port);
                            $pid_bad_p1 = get_pid_using_port($dj_port + 1);
                            $recom = siguiente_puerto_libre($db['radios']);
                            $usado_p0 = is_port_system_in_use($dj_port);
                            $usado_p1 = is_port_system_in_use($dj_port + 1);
                            $pid_txt = '';
                            if ($usado_p0) { $pid_txt .= " · Puerto {$dj_port}"; if ($pid_bad_p0) $pid_txt .= " (PID=$pid_bad_p0)"; }
                            if ($usado_p1) { $pid_txt .= " · Puerto " . ($dj_port + 1); if ($pid_bad_p1) $pid_txt .= " (PID=$pid_bad_p1)"; }
                            $msg = "⚠️ PAREJA DE PUERTOS DJ NO DISPONIBLE. Liquidsoap 2.0.2 reserva 2 puertos seguidos (N y N+1). El puesto {$dj_port} y/o el " . ($dj_port + 1) . " están en uso{$pid_txt}. Aunque no esté asignado en la Base de Datos, hay un proceso vivo mantiene la pareja abierta → Liquidsoap se caería con 'Address already in use' y la radio daría 404 o 0 conexión DJ. Prueba con el puerto {$recom} (confirmado pareja libre).";
                            $msg_type = "danger";
                            $puerto_ok = false;
                        }
                    }
                }
            }
            if ($puerto_ok) {
                $radio_id = uniqid('rad_');
                $db['radios'][$radio_id] = [
                    'id' => $radio_id,
                    'nombre_emisora' => $nombre,
                    'mountpoint' => $mount,
                    'encoder_pass_encrypted' => encrypt_pass($pass),
                    'dj_port' => $dj_port,
                    'modo_radio' => $modo_radio,
                    'quota_mb' => $quota_mb,
                    'bitrate' => $bitrate,
                    'directa_fondo_oculto_path' => $directa_fondo_oculto_path,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                $base_new = "/var/media/radios/{$mount}";
                if (!is_dir($base_new)) @mkdir($base_new, 0775, true);
                // Solo crear carpetas si es MODO AUTODJ (radio directa no necesita espacio de archivos)
                if ($modo_radio === 'autodj') {
                    // IMPORTANTE: NO creamos carpeta "General" aquí.
                    //  - El "Playlist General" existe en programacion.json pero el usuario decide
                    //    qué carpetas PROPIAS mete ahí (Ajustes AutoDJ → Playlists → Playlist General).
                    //  - Carpetas de música las crea 100% el cliente desde Musicateca (Rancheras, Salsa, Cumbia...).
                    $def_folders = ['Anuncios', 'HORAS', 'Mantenimientos'];
                    foreach ($def_folders as $df) {
                        $df_path = $base_new . '/' . $df;
                        if (!is_dir($df_path)) @mkdir($df_path, 0775, true);
                    }
                    $prog_file = $base_new . '/programacion.json';
                    if (!file_exists($prog_file)) {
                        $default_prog = [
                            'timezone'         => 'America/Costa_Rica',
                            'default_playlist' => 'general',
                            'playlists'        => [
                                // El Playlist General por defecto = VACÍO.
                                // El superadmin / cliente mete carpetas desde Playlists.
                                // (Radio emite safety_silence hasta que el usuario agregue contenido.)
                                'general' => ['tipo' => 'carpetas', 'items' => []]
                            ],
                            'schedule'         => [],
                            'ads'              => [],
                            'time_voice'       => ['enabled' => false, 'folder' => 'HORAS']
                        ];
                        @file_put_contents($prog_file, json_encode($default_prog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    }
                }
                file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $msg = "Emisora '{$nombre}' creada exitosamente (Modo: " . strtoupper($modo_radio) . " · Puerto DJ: {$dj_port} · Bitrate: {$bitrate} kbps).";
                $msg_type = "success";
            }
        } else {
            $msg = "Todos los campos de la emisora son obligatorios.";
            $msg_type = "danger";
        }
    }

    // ACTUALIZAR EMISORA (editar existente)
    if ($action === 'update_radio') {
        $radio_key = trim($_POST['radio_key'] ?? '');
        if (isset($db['radios'][$radio_key])) {
            $old_data = $db['radios'][$radio_key];
            $old_mount = preg_replace('/[^a-zA-Z0-9_-]/', '', $old_data['mountpoint'] ?? '');
            $nombre = trim($_POST['nombre_emisora'] ?? '');
            $new_mount = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['mountpoint'] ?? ''));
            $new_pass = trim($_POST['encoder_pass'] ?? '');
            $dj_port = (int)($_POST['dj_port'] ?? 0);
            $modo_radio = in_array(($_POST['modo_radio'] ?? 'autodj'), ['autodj', 'directa'], true) ? $_POST['modo_radio'] : 'autodj';
            $quota_mb = (int)($_POST['quota_mb'] ?? -1);
            // ===== BITRATE EDITAR =====
            $ALLOWED_BITRATES_UPD = [64, 96, 128, 192, 256, 320];
            $bitrate_upd = (int)($_POST['bitrate'] ?? 0);
            if (!in_array($bitrate_upd, $ALLOWED_BITRATES_UPD, true)) $bitrate_upd = (int)($old_data['bitrate'] ?? 128);

            // ===== Fondo Loop Oculto EDITAR (SOLO Modo Directa · superadmin) =====
            $fondo_oculto_op_edit = ($_POST['fondo_oculto_op'] ?? 'silencio') === 'ruta' ? 'ruta' : 'silencio';
            $directa_fondo_oculto_path_edit = isset($db['radios'][$radio_key]['directa_fondo_oculto_path']) ? $db['radios'][$radio_key]['directa_fondo_oculto_path'] : '';
            if ($modo_radio === 'directa') {
                if ($fondo_oculto_op_edit === 'silencio') {
                    $directa_fondo_oculto_path_edit = '';
                } else {
                    $path_input = trim((string)($_POST['directa_fondo_oculto_path'] ?? ''));
                    if ($path_input !== '') {
                        if (strpos($path_input, '..') === false && $path_input[0] === '/') {
                            $directa_fondo_oculto_path_edit = $path_input;
                        }
                    }
                }
            } else {
                // Modo autodj, no usa fondo oculto → limpiar
                $directa_fondo_oculto_path_edit = '';
            }

            $valid = true;
            if ($nombre && $new_mount) {
                // Validar que el nuevo mountpoint no exista en OTRA emisora
                foreach (($db['radios'] ?? []) as $ok => $or) {
                    if ($ok === $radio_key) continue;
                    if (strtolower($or['mountpoint'] ?? '') === strtolower($new_mount)) {
                        $msg = "Ya existe otra emisora con el mountpoint '{$new_mount}'.";
                        $msg_type = "danger";
                        $valid = false;
                        break;
                    }
                }
                // Validar puerto DJ
                if ($valid && $dj_port > 0) {
                    if ($dj_port < 1024 || $dj_port > 65535) {
                        $msg = "El puerto DJ debe estar entre 1024 y 65535.";
                        $msg_type = "danger";
                        $valid = false;
                    } else {
                        $usados = puertos_usados_radios($db['radios'], $radio_key);
                        if (in_array($dj_port, $usados, true)) {
                            $recom = siguiente_puerto_libre($db['radios'], $radio_key);
                            $msg = "El puerto DJ {$dj_port} (o el siguiente {$dj_port} + 1) ya está ocupado por otra emisora. Liquidsoap reserva 2 puertos seguidos (N y N+1). Prueba con el puerto {$recom} (confirmado libre para pareja).";
                            $msg_type = "danger";
                            $valid = false;
                        } elseif (is_port_pair_in_use($dj_port)) {
                            $pid_bad_p0 = get_pid_using_port($dj_port);
                            $pid_bad_p1 = get_pid_using_port($dj_port + 1);
                            $recom = siguiente_puerto_libre($db['radios'], $radio_key);
                            $usado_p0 = is_port_system_in_use($dj_port);
                            $usado_p1 = is_port_system_in_use($dj_port + 1);
                            $pid_txt = '';
                            if ($usado_p0) { $pid_txt .= " · Puerto {$dj_port}"; if ($pid_bad_p0) $pid_txt .= " (PID=$pid_bad_p0)"; }
                            if ($usado_p1) { $pid_txt .= " · Puerto " . ($dj_port + 1); if ($pid_bad_p1) $pid_txt .= " (PID=$pid_bad_p1)"; }
                            $msg = "⚠️ PAREJA DE PUERTOS DJ NO DISPONIBLE. Liquidsoap 2.0.2 reserva 2 puertos seguidos (N y N+1). El puerto {$dj_port} y/o el " . ($dj_port + 1) . " están en uso{$pid_txt}. Aunque no esté asignado en la Base de Datos, hay un proceso vivo mantiene la pareja abierta → Liquidsoap se caería con 'Address already in use' y la radio daría 404 o 0 conexión DJ. Prueba con el puerto {$recom} (confirmado pareja libre).";
                            $msg_type = "danger";
                            $valid = false;
                        }
                    }
                }
                if ($valid) {
                    $db['radios'][$radio_key]['nombre_emisora'] = $nombre;
                    $db['radios'][$radio_key]['mountpoint'] = $new_mount;
                    $db['radios'][$radio_key]['modo_radio'] = $modo_radio;
                    if ($quota_mb >= 0) $db['radios'][$radio_key]['quota_mb'] = $quota_mb;
                    $db['radios'][$radio_key]['bitrate'] = $bitrate_upd;
                    $db['radios'][$radio_key]['directa_fondo_oculto_path'] = $directa_fondo_oculto_path_edit;
                    if ($new_pass) $db['radios'][$radio_key]['encoder_pass_encrypted'] = encrypt_pass($new_pass);
                    if ($dj_port > 0) $db['radios'][$radio_key]['dj_port'] = $dj_port;

                    // Si cambió el mountpoint → RENOMBRAR la carpeta de medios para no perder datos
                    if ($old_mount !== $new_mount && $old_mount !== '' && $new_mount !== '') {
                        $old_dir = "/var/media/radios/{$old_mount}";
                        $new_dir = "/var/media/radios/{$new_mount}";
                        if (is_dir($old_dir) && !is_dir($new_dir)) {
                            @rename($old_dir, $new_dir);
                        } else {
                            // Si la carpeta nueva ya existe o la vieja no, crearla para que no falle
                            if (!is_dir($new_dir)) @mkdir($new_dir, 0775, true);
                        }
                    }

                    file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $msg = "Emisora '{$nombre}' actualizada correctamente." . ($old_mount !== $new_mount ? " (Mount renombrado de /{$old_mount} a /{$new_mount})" : '');
                    $msg_type = "success";
                }
            } else {
                $msg = "El nombre y el mountpoint no pueden estar vacíos.";
                $msg_type = "danger";
            }
        }
    }

    // ELIMINAR RADIO — BORRADO TOTAL (proceso + archivos físicos + BD)
    if (!function_exists('sp_delete_dir_recursive')) {
        function sp_delete_dir_recursive($dir) {
            if (!is_dir($dir)) { if (is_file($dir) || is_link($dir)) @unlink($dir); return; }
            $items = @scandir($dir) ?: [];
            foreach ($items as $it) {
                if ($it === '.' || $it === '..') continue;
                $p = $dir . '/' . $it;
                if (is_dir($p)) { sp_delete_dir_recursive($p); @rmdir($p); }
                elseif (is_file($p) || is_link($p)) { @unlink($p); }
                else { @unlink($p); } // sockets/otros (p.ej. liq.sock)
            }
        }
    }
    if ($action === 'delete_radio') {
        $del_id = $_POST['radio_id'] ?? '';
        if (isset($db['radios'][$del_id])) {
            $mount_to_del = preg_replace('/[^a-zA-Z0-9_-]/', '', $db['radios'][$del_id]['mountpoint'] ?? '');
            $radio_dir = '';
            if ($mount_to_del !== '') {
                $candidate = '/var/media/radios/' . $mount_to_del;
                $rp = @realpath($candidate);
                if ($rp !== false && str_starts_with($rp, '/var/media/radios/')) {
                    $radio_dir = $rp;
                }
                // Detener Liquidsoap de la emisora (por pid y por cmdline del autodj.liq)
                $pid_f = $candidate . '/autodj.pid';
                if (is_file($pid_f)) {
                    $pid = trim((string)@file_get_contents($pid_f));
                    if ($pid !== '' && is_numeric($pid)) { @exec('kill -9 ' . (int)$pid . ' 2>/dev/null'); }
                }
                if (function_exists('shell_exec')) {
                    @shell_exec('pkill -9 -f ' . escapeshellarg($candidate . '/autodj.liq') . ' 2>/dev/null');
                }
                usleep(250000);
            }
            // Borrar físicamente TODA la carpeta de la emisora (audio, config, estado, logs)
            if ($radio_dir !== '') { sp_delete_dir_recursive($radio_dir); @rmdir($radio_dir); }
            // Quitar referencias de la emisora en los usuarios
            foreach (($db['usuarios'] ?? []) as $uk => $u) {
                if (!is_array($u)) continue;
                if (($u['radio_id'] ?? '') === $del_id) unset($db['usuarios'][$uk]['radio_id']);
                if (is_array($u['radio_ids'] ?? null)) {
                    $db['usuarios'][$uk]['radio_ids'] = array_values(array_filter($u['radio_ids'], fn($x) => $x !== $del_id));
                }
            }
            unset($db['radios'][$del_id]);
            file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $msg = "Emisora eliminada por completo (BD, proceso Liquidsoap y archivos de /var/media/radios).";
            $msg_type = "success";
        }
    }

    // CREAR CLIENTE (con múltiples radios)
    if ($action === 'create_user') {
        $nombre = trim($_POST['nombre_completo'] ?? '');
        $user = trim($_POST['usuario'] ?? '');
        $pass = trim($_POST['password'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $radio_ids = $_POST['radio_ids'] ?? [];
        $radio_ids = is_array($radio_ids) ? array_values(array_filter($radio_ids, fn($x) => !empty($x))) : [];
        $primary_radio = $radio_ids[0] ?? '';

        if ($nombre && $user && $pass && !empty($radio_ids)) {
            if (($db['superadmin']['usuario'] ?? '') === $user) {
                $msg = "Ese nombre de usuario pertenece al superadmin. Elige otro.";
                $msg_type = "danger";
            } elseif (isset($db['usuarios'][$user])) {
                $msg = "El usuario '{$user}' ya existe.";
                $msg_type = "danger";
            } else {
                $db['usuarios'][$user] = [
                    'id' => uniqid('usr_'),
                    'nombre_completo' => $nombre,
                    'usuario' => $user,
                    'email' => $email,
                    'telefono' => $telefono,
                    'website' => $website,
                    'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                    'radio_id' => $primary_radio,
                    'radio_ids' => $radio_ids,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $msg = "Cliente '{$user}' creado y asignado a " . count($radio_ids) . " emisora(s).";
                $msg_type = "success";
            }
        } else {
            $msg = "Completa nombre, usuario, contraseña y al menos 1 emisora.";
            $msg_type = "danger";
        }
    }

    // ACTUALIZAR CLIENTE (editar asignación de radios)
    if ($action === 'update_user') {
        $user_key = trim($_POST['user_key'] ?? '');
        if (isset($db['usuarios'][$user_key])) {
            $nombre = trim($_POST['nombre_completo'] ?? '');
            $new_pass = trim($_POST['password'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $radio_ids = $_POST['radio_ids'] ?? [];
            $radio_ids = is_array($radio_ids) ? array_values(array_filter($radio_ids, fn($x) => !empty($x))) : [];
            $primary_radio = $radio_ids[0] ?? ($db['usuarios'][$user_key]['radio_id'] ?? '');

            if ($nombre) $db['usuarios'][$user_key]['nombre_completo'] = $nombre;
            if ($email !== null) $db['usuarios'][$user_key]['email'] = $email;
            if ($telefono !== null) $db['usuarios'][$user_key]['telefono'] = $telefono;
            if ($website !== null) $db['usuarios'][$user_key]['website'] = $website;
            if ($new_pass) $db['usuarios'][$user_key]['password_hash'] = password_hash($new_pass, PASSWORD_DEFAULT);
            if (!empty($radio_ids)) {
                $db['usuarios'][$user_key]['radio_id'] = $primary_radio;
                $db['usuarios'][$user_key]['radio_ids'] = $radio_ids;
            }
            file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $msg = "Cliente '{$user_key}' actualizado correctamente.";
            $msg_type = "success";
        }
    }

    // ELIMINAR CLIENTE
    if ($action === 'delete_user') {
        $user_key = trim($_POST['user_key'] ?? '');
        if (isset($db['usuarios'][$user_key])) {
            unset($db['usuarios'][$user_key]);
            file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            // también limpiar estado security al borrar usuario
            sec_clear_throttle('u:' . $user_key);
            $msg = "Cliente eliminado correctamente.";
            $msg_type = "success";
        }
    }

    // DESBLOQUEAR USUARIO (1 por vez, o por key directa)
    if ($action === 'unblock_user') {
        $sec_key = trim($_POST['sec_key'] ?? '');
        if ($sec_key !== '') {
            sec_clear_throttle($sec_key);
            $msg = "🔓 Usuario desbloqueado correctamente (clave: {$sec_key}). Se borra el nivel, intentos y bloqueo.";
            $msg_type = "success";
        }
    }

    // DESBLOQUEAR TODOS los bloqueados (acción masiva dashboard/seguridad)
    if ($action === 'unblock_all_users') {
        $all = sec_get_all_throttles();
        $n = 0;
        foreach ($all as $row) {
            if ($row['blocked'] || (int)$row['attempts_count'] > 0 || (int)$row['level'] > 0) {
                sec_clear_throttle($row['key']);
                $n++;
            }
        }
        $msg = "🔓 Se limpiaron {$n} registros de seguridad (bloqueos, intentos fallidos y nivel escalado).";
        $msg_type = "success";
    }

    // ==========================================================
    // NUEVOS HANDLERS IP THROTTLE
    // ==========================================================
    // Desbloquear IP (individual)
    if ($action === 'unblock_ip') {
        $ipKey = trim($_POST['sec_key'] ?? '');
        if (strncmp($ipKey, 'ip:', 3) === 0) {
            sec_ip_clear_throttle($ipKey);
            $msg = "🔓 IP " . htmlspecialchars(substr($ipKey,3)) . " desbloqueada. Se limpiaron intentos y nivel.";
            $msg_type = "success";
        }
    }
    // Limpiar TODAS las IPs
    if ($action === 'unblock_all_ips') {
        $all = sec_ip_get_all();
        $n = 0;
        foreach ($all as $row) {
            if ($row['blocked'] || (int)$row['attempts_count'] > 0 || (int)$row['level'] > 0) {
                sec_ip_clear_throttle($row['key']);
                $n++;
            }
        }
        $msg = "🔓 Se limpiaron {$n} registros de IP (bloqueos/fallidas/nivel).";
        $msg_type = "success";
    }
    // Banear IP MANUALMENTE (por X horas)
    if ($action === 'ban_ip_manual') {
        $ip = trim($_POST['ip_manual'] ?? '');
        $hrs = (int)($_POST['duracion_horas'] ?? 1);
        $comment = trim($_POST['comentario_manual'] ?? '');
        $ipOK = filter_var($ip, FILTER_VALIDATE_IP) !== false;
        if ($hrs < 1) $hrs = 1;
        if ($hrs > 8760) $hrs = 8760; // max 1 año
        if ($ipOK) {
            sec_ip_record_fail($hrs, $comment, 'ip:' . $ip);
            $msg = "🚫 IP " . htmlspecialchars($ip) . " bloqueada MANUALMENTE por {$hrs} hora(s).";
            $msg_type = "success";
        } else {
            $msg = "❌ Dirección IP inválida: " . htmlspecialchars($ip);
            $msg_type = "danger";
        }
    }
    // ✏️ GUARDAR TEXTOS PERSONALIZADOS LOGIN
    if ($action === 'save_login_texts') {
        $data = [
            'cliente'    => is_array($_POST['cliente']    ?? null) ? $_POST['cliente']    : [],
            'superadmin' => is_array($_POST['superadmin'] ?? null) ? $_POST['superadmin'] : [],
            'ip403'      => is_array($_POST['ip403']      ?? null) ? $_POST['ip403']      : [],
        ];
        login_texts_save($data);
        $msg = "✏️ Textos personalizados guardados correctamente. Ver cambios con Ctrl+Shift+R en index.php o superradio.php.";
        $msg_type = "success";
    }
    // ↩️ RESTAURAR TEXTOS POR DEFECTO
    if ($action === 'reset_login_texts') {
        login_texts_reset();
        $msg = "↩️ Textos de login restaurados a valores POR DEFECTO.";
        $msg_type = "success";
    }

    // 👤 ACTUALIZAR CUENTA DEL SUPERADMIN (usuario / contraseña de acceso)
    if ($action === 'sa_update_account') {
        $cur = (string)($_POST['cur_password'] ?? '');
        $newu = trim($_POST['nuevo_usuario'] ?? '');
        $newp = (string)($_POST['new_password'] ?? '');
        $newp2 = (string)($_POST['new_password2'] ?? '');
        $old_hash = $db['superadmin']['password_hash'] ?? '';
        $do_user = ($newu !== '' && $newu !== ($db['superadmin']['usuario'] ?? ''));
        $do_pass = ($newp !== '');
        if (empty($old_hash) || !password_verify($cur, $old_hash)) {
            $msg = "❌ La contraseña actual no es correcta. No se aplicó ningún cambio.";
            $msg_type = "danger";
        } elseif ($do_user && !preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $newu)) {
            $msg = "❌ El nuevo usuario debe tener entre 3 y 32 caracteres. Permitidos: letras, números, punto, guion y guion bajo.";
            $msg_type = "danger";
        } elseif ($do_user && isset($db['usuarios'][$newu])) {
            $msg = "❌ Ese usuario ya lo usa un cliente. Elige otro.";
            $msg_type = "danger";
        } elseif ($do_pass && strlen($newp) < 8) {
            $msg = "❌ La nueva contraseña debe tener al menos 8 caracteres.";
            $msg_type = "danger";
        } elseif ($do_pass && $newp !== $newp2) {
            $msg = "❌ Las contraseñas nuevas no coinciden.";
            $msg_type = "danger";
        } elseif (!$do_user && !$do_pass) {
            $msg = "⚠️ Escribe un cambio (usuario o contraseña nueva) para guardar.";
            $msg_type = "danger";
        } else {
            $cambios = [];
            if ($do_user) {
                sec_clear_throttle('s:' . ($db['superadmin']['usuario'] ?? $newu)); // limpiar intentos del usuario viejo
                $db['superadmin']['usuario'] = $newu;
                sec_clear_throttle('s:' . $newu);
                $superadmin_user = $newu;
                $cambios[] = 'usuario';
            }
            if ($do_pass) {
                $db['superadmin']['password_hash'] = password_hash($newp, PASSWORD_DEFAULT);
                $cambios[] = 'contraseña';
            }
            $db['superadmin']['updated_at'] = date('Y-m-d H:i:s');
            file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $txt = count($cambios) === 1 ? 'se cambió el ' . $cambios[0] : 'se cambiaron: ' . implode(', ', $cambios);
            $msg = "✅ Cuenta actualizada correctamente (" . $txt . ").";
            $msg_type = "success";
        }
    }

    // 🏢 GUARDAR DATOS DEL NEGOCIO / CONTACTO (marca + datos de contacto)
    if ($action === 'save_negocio') {
        $nb = trim(substr((string)($_POST['negocio_nombre'] ?? ''), 0, 60));
        $em = trim(substr((string)($_POST['negocio_email'] ?? ''), 0, 120));
        $wa = trim(substr((string)($_POST['negocio_whatsapp'] ?? ''), 0, 24));
        $pa = trim(substr((string)($_POST['negocio_pais'] ?? ''), 0, 60));
        $pr = trim(substr((string)($_POST['negocio_provincia'] ?? ''), 0, 60));
        $di = trim(substr((string)($_POST['negocio_direccion'] ?? ''), 0, 160));
        if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL) === false) {
            $msg = "❌ El email del negocio no es válido.";
            $msg_type = 'danger';
        } else {
            $db['negocio'] = [
                'nombre'     => $nb,
                'email'      => $em,
                'whatsapp'   => $wa,
                'pais'       => $pa,
                'provincia'  => $pr,
                'direccion'  => $di,
            ];
            file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $msg = "✅ Datos del negocio/contacto guardados (se usan en remitente y plantillas de correo).";
            $msg_type = 'success';
        }
    }

    // ✉️ GUARDAR CONFIGURACIÓN SMTP
    if ($action === 'save_smtp_config') {
        $cfg = sp_smtp_cfg_load($db);
        $cfg['host'] = sp_mail_sanitize_addr($_POST['host'] ?? '');
        $cfg['port'] = max(1, (int)($_POST['port'] ?? 587));
        $cfg['secure'] = in_array(($_POST['secure'] ?? ''), ['ssl', 'tls', ''], true) ? (string)($_POST['secure'] ?? '') : 'tls';
        $cfg['username'] = sp_mail_sanitize_addr($_POST['username'] ?? '');
        $cfg['from_name'] = trim(str_replace(["\r", "\n"], '', (string)($_POST['from_name'] ?? '')));
        $cfg['from_email'] = sp_mail_sanitize_addr($_POST['from_email'] ?? '');
        $pass_nueva = (string)($_POST['smtp_pass'] ?? '');
        $pass_rep = (string)($_POST['smtp_pass2'] ?? '');
        $err_cfg = '';
        if ($cfg['host'] === '') {
            $err_cfg = 'Indica el servidor SMTP (host).';
        } elseif ($cfg['from_email'] !== '' && !filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL)) {
            $err_cfg = 'El email del remitente no es válido.';
        } elseif ($pass_nueva !== '' && strlen($pass_nueva) < 6) {
            $err_cfg = 'La contraseña SMTP debe tener al menos 6 caracteres.';
        } elseif ($pass_nueva !== '' && $pass_nueva !== $pass_rep) {
            $err_cfg = 'Las contraseñas SMTP no coinciden.';
        }
        if ($err_cfg !== '') {
            $msg = "❌ " . $err_cfg;
            $msg_type = 'danger';
        } else {
            if ($pass_nueva !== '') $cfg['enc_password'] = encrypt_pass($pass_nueva);
            $db['smtp'] = $cfg;
            file_put_contents($db_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $msg = "✅ Configuración SMTP guardada (contraseña: " . ($pass_nueva !== '' ? 'actualizada' : 'sin cambios') . ").";
            $msg_type = 'success';
        }
    }

    // ✉️ CORREO DE PRUEBA (al email del negocio / superadmin)
    if ($action === 'test_smtp_mail') {
        $cfg = sp_smtp_cfg_load($db);
        $_neg_email = trim($db['negocio']['email'] ?? '');
        $dest = $_neg_email !== '' ? $_neg_email : trim($db['superadmin']['email'] ?? '');
        if (filter_var($dest, FILTER_VALIDATE_EMAIL) === false) {
            $msg = "❌ Configura primero el email en Mi Cuenta → Datos del negocio para recibir la prueba.";
            $msg_type = 'danger';
        } else {
            if ($cfg['from_email'] === '') $cfg['from_email'] = $dest;
            $_neg_n = trim($db['negocio']['nombre'] ?? '') !== '' ? trim($db['negocio']['nombre']) : 'SuperRadio';
            if ($cfg['from_name'] === '') $cfg['from_name'] = $_neg_n;
            $r = sp_smtp_send_msg($cfg, $dest, 'Prueba de correo — SuperRadio', "Este es un correo de prueba.\nSi lo recibís, la configuración SMTP del panel funciona correctamente.", $emerr);
            if ($r) {
                $msg = "✅ Correo de prueba enviado a {$dest}.";
                $msg_type = 'success';
            } else {
                $msg = "❌ No se pudo enviar: {$emerr}";
                $msg_type = 'danger';
            }
        }
    }

    // ✉️ ENVIAR MENSAJE A CLIENTE(S) — con plantillas {nombre} {usuario} {emisora} {email} {fecha}
    if ($action === 'send_client_mail') {
        $destino = trim($_POST['destino'] ?? '');
        $asunto = trim($_POST['asunto'] ?? '');
        $cuerpo = trim($_POST['mensaje'] ?? '');
        $cfg = sp_smtp_cfg_load($db);
        $_neg_email = trim($db['negocio']['email'] ?? '');
        $sa_email = sp_mail_sanitize_addr($_neg_email !== '' ? $_neg_email : ($db['superadmin']['email'] ?? ''));
        if ($cfg['from_email'] === '') $cfg['from_email'] = $sa_email;
        $_neg_nom = trim($db['negocio']['nombre'] ?? '') !== '' ? trim($db['negocio']['nombre']) : 'SuperRadio';
        $_neg_wa2 = trim($db['negocio']['whatsapp'] ?? '');
        if ($cfg['from_name'] === '') $cfg['from_name'] = $_neg_nom;
        $errs = [];
        if ($asunto === '') $errs[] = 'El asunto es obligatorio.';
        if ($cuerpo === '') $errs[] = 'El mensaje es obligatorio.';
        if (filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL) === false) $errs[] = 'Falta un email de remitente: configúralo en Correo (Remitente) o en Mi Cuenta.';
        $list = [];
        if (!$errs) {
            if ($destino === '__all__') {
                foreach (($db['usuarios'] ?? []) as $uk => $u) {
                    $e = sp_mail_sanitize_addr($u['email'] ?? '');
                    if (filter_var($e, FILTER_VALIDATE_EMAIL) !== false) $list[$uk] = ['email' => $e, 'data' => $u];
                }
            } elseif ($destino !== '' && isset($db['usuarios'][$destino])) {
                $e = sp_mail_sanitize_addr($db['usuarios'][$destino]['email'] ?? '');
                if (filter_var($e, FILTER_VALIDATE_EMAIL) !== false) $list[$destino] = ['email' => $e, 'data' => $db['usuarios'][$destino]];
                else $errs[] = "El cliente elegido no tiene un email válido.";
            } else {
                $errs[] = 'Elige un destinatario válido.';
            }
        }
        if ($errs) {
            $msg = "❌ " . implode(' ', $errs);
            $msg_type = 'danger';
        } elseif (!$list) {
            $msg = "⚠️ Ningún cliente tiene email configurado para enviar.";
            $msg_type = 'danger';
        } else {
            $ok_n = 0;
            $fail_n = [];
            foreach ($list as $uk => $item) {
                $u = $item['data'];
                $e = $item['email'];
                // Personalizar texto por cliente
                $nombre = trim($u['nombre_completo'] ?? $u['nombre'] ?? $uk);
                $radio_id = $u['radio_id'] ?? ($u['radio_ids'][0] ?? '');
                $emisora = !empty($radio_id) && !empty($db['radios'][$radio_id]['nombre_emisora']) ? $db['radios'][$radio_id]['nombre_emisora'] : '';
                $map = [
                    '{nombre}'   => $nombre,
                    '{usuario}'  => $uk,
                    '{email}'    => $e,
                    '{emisora}'  => $emisora,
                    '{fecha}'    => date('d/m/Y'),
                    '{negocio}'  => $_neg_nom,
                    '{whatsapp}' => $_neg_wa2,
                ];
                $asunto_to = strtr($asunto, $map);
                $cuerpo_to = strtr($cuerpo, $map);
                $r = sp_smtp_send_msg($cfg, $e, $asunto_to, $cuerpo_to, $emerr);
                if ($r) $ok_n++;
                else $fail_n[] = $uk . ' (' . $e . '): ' . $emerr;
            }
            if ($ok_n === count($list)) {
                $msg = "✅ Mensaje enviado a {$ok_n} destinatario(s).";
                $msg_type = 'success';
            } else {
                $msg = "Enviados {$ok_n} de " . count($list) . " destinatario(s). Fallos: " . implode(' | ', $fail_n);
                $msg_type = 'danger';
            }
        }
    }
}

// --- NAVEGACIÓN DE VISTAS ---
$view = $_GET['view'] ?? 'dashboard';
if (!in_array($view, ['dashboard', 'radios', 'clientes', 'seguridad', 'correo', 'cuenta'], true)) $view = 'dashboard';

// --- RECALCULAR USO DE PUERTOS DESPUÉS DE CAMBIOS ---
$_port_usage_viz = [];
foreach (($db['radios'] ?? []) as $_k => $_r) {
    $_p = (int)($_r['dj_port'] ?? 0);
    if ($_p >= 1024 && $_p <= 65535) $_port_usage_viz[$_p][] = $_k;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SuperRadio · Superadministración</title>
    <link rel="stylesheet" href="assets/css/panel.css">
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #060b17; color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; overflow-y: auto; }
        .admin-wrap { display: flex; min-height: 100vh; }
        .admin-sidebar {
            width: 260px; background: #0c1527; border-right: 1px solid #1e293b;
            display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh;
            overflow-y: auto;
        }
        .admin-brand { padding: 20px; border-bottom: 1px solid #1e293b; display: flex; align-items: center; gap: 12px; }
        .admin-brand .icon { font-size: 1.8rem; }
        .admin-brand .title { font-size: 1.05rem; font-weight: bold; color: #38bdf8; line-height: 1.1; display: block; }
        .admin-brand .subtitle { font-size: 0.72rem; color: #94a3b8; display: block; margin-top: 2px; }

        .admin-nav { flex: 1; padding: 16px 10px; display: flex; flex-direction: column; gap: 4px; }
        .nav-item {
            display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 6px;
            color: #cbd5e1; cursor: pointer; font-size: 0.88rem; text-decoration: none; transition: all 0.15s;
            border: 1px solid transparent;
        }
        .nav-item:hover { background: #111e38; color: #fff; }
        .nav-item.active { background: #0b1c38; color: #38bdf8; border-color: #1e40af; font-weight: bold; }
        .nav-item .ico { width: 20px; text-align: center; font-size: 1rem; }
        .nav-item .count { margin-left: auto; background: #1e293b; color: #94a3b8; padding: 2px 8px; border-radius: 10px; font-size: 0.72rem; font-weight: bold; }
        .nav-item.active .count { background: #075985; color: #bae6fd; }

        .nav-section-title { color: #64748b; font-size: 0.7rem; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; padding: 16px 14px 6px; }

        .admin-sidebar-actions { padding: 14px; border-top: 1px solid #1e293b; display: flex; flex-direction: column; gap: 8px; }
        .btn { padding: 9px 14px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 0.85rem; }
        .btn-primary { background: #0284c7; color: #fff; }
        .btn-primary:hover { background: #0369a1; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-warning { background: #d97706; color: #fff; }
        .btn-warning:hover { background: #b45309; }
        .btn-sm { padding: 5px 10px; font-size: 0.75rem; }

        .admin-content { flex: 1; display: flex; flex-direction: column; }
        .admin-topbar {
            background: #0c1527; border-bottom: 1px solid #1e293b; padding: 14px 28px;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
        }
        .admin-topbar h1 { margin: 0; font-size: 1.2rem; color: #fff; display: flex; align-items: center; gap: 8px; }
        .admin-topbar h1 .dot { width: 10px; height: 10px; border-radius: 50%; background: #10b981; display: inline-block; }
        .admin-topbar .meta { display: flex; align-items: center; gap: 12px; }
        .admin-topbar .chip { background: #111e38; color: #94a3b8; font-size: 0.78rem; padding: 5px 10px; border-radius: 6px; }
        .view-content { padding: 28px; flex: 1; }

        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; color: #fff; font-size: 0.9rem; border-left: 4px solid; }
        .alert-success { background: #064e3b; border-color: #10b981; }
        .alert-danger { background: #7f1d1d; border-color: #dc2626; }

        .grid-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .stat-card { display: flex; align-items: center; gap: 14px; background: #0c1527; padding: 18px; border-radius: 8px; border: 1px solid #1e293b; }
        .stat-icon { font-size: 2rem; }
        .stat-label { color: #94a3b8; font-size: 0.75rem; font-weight: bold; display: block; text-transform: uppercase; letter-spacing: 0.3px; }
        .stat-value { font-size: 1.4rem; color: #fff; font-weight: bold; display: block; }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .page-header h2 { margin: 0; font-size: 1.3rem; color: #fff; display: flex; align-items: center; gap: 8px; }
        .page-header .sub { font-size: 0.8rem; color: #94a3b8; display: block; margin-top: 3px; font-weight: normal; }

        .card { background: #0c1527; padding: 22px; border-radius: 8px; border: 1px solid #1e293b; margin-bottom: 22px; }
        .card h4 { margin: 0 0 18px 0; color: #38bdf8; font-size: 1.05rem; }
        .card h4.green { color: #4ade80; }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .mb-3 { margin-bottom: 14px; }
        label.form-label { color: #94a3b8; font-size: 0.78rem; font-weight: bold; display: block; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.3px; }
        .form-control { width: 100%; padding: 10px 12px; background: #060b17; border: 1px solid #334155; color: #fff; border-radius: 6px; font-size: 0.9rem; box-sizing: border-box; font-family: inherit; }
        .form-control:focus { outline: none; border-color: #38bdf8; box-shadow: 0 0 0 2px rgba(56,189,248,0.15); }
        select[multiple].form-control { min-height: 110px; }
        .radio-group { display: flex; gap: 10px; flex-wrap: wrap; }
        .radio-option {
            flex: 1; min-width: 200px; background: #060b17; border: 2px solid #334155; border-radius: 8px; padding: 14px;
            cursor: pointer; transition: all 0.15s; position: relative;
        }
        .radio-option input { position: absolute; opacity: 0; pointer-events: none; }
        .radio-option:hover { border-color: #475569; }
        .radio-option:has(input:checked) { border-color: #38bdf8; background: #0b1c38; }
        .radio-option .opt-title { font-weight: bold; color: #fff; display: flex; align-items: center; gap: 8px; font-size: 0.95rem; }
        .radio-option .opt-desc { color: #94a3b8; font-size: 0.78rem; margin-top: 4px; }

        .tbl-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem; }
        thead tr { border-bottom: 2px solid #1e293b; color: #94a3b8; }
        th { padding: 12px 12px; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.5px; font-weight: bold; }
        td { padding: 12px 12px; border-bottom: 1px solid #111e38; color: #e2e8f0; vertical-align: middle; }
        .mono { font-family: "SFMono-Regular", Menlo, Monaco, Consolas, monospace; }
        .text-sky { color: #38bdf8; }
        .text-green { color: #4ade80; }
        .text-muted { color: #94a3b8; font-size: 0.8rem; }
        .actions { display: flex; gap: 5px; flex-wrap: wrap; }
        .tag { display: inline-block; padding: 3px 8px; border-radius: 4px; background: #1e293b; color: #cbd5e1; font-size: 0.72rem; margin: 2px 4px 2px 0; font-weight: 500; }
        .tag.primary { background: #075985; color: #bae6fd; }
        .tag.green { background: #065f46; color: #6ee7b7; }
        .tag.amber { background: #78350f; color: #fcd34d; }
        .tag.red { background: #7f1d1d; color: #fecaca; }

        .row-form { display: none; background: #060b17; border-radius: 6px; padding: 18px; margin-top: 12px; border: 1px solid #1e293b; }
        .row-form.active {
            display: block;
            /* Fix scroll: formulario MUY largo no dejaba bajar.
               - Altura máxima razonable (ventana - topbar - margen)
               - Scroll INTERNO con estilo para llegar al botón Guardar / Borrar
               - Sin depender del scroll general del documento, que fallaba por estar en <td> */
            max-height: calc(100vh - 150px);
            overflow-y: auto;
            overflow-x: hidden;
            position: relative;
        }
        /* Scrollbar visible para formularios largos (Editar emisora) */
        .row-form.active::-webkit-scrollbar { width: 10px; }
        .row-form.active::-webkit-scrollbar-track { background: #0c1527; }
        .row-form.active::-webkit-scrollbar-thumb { background: #1e40af; border-radius: 10px; }
        .row-form.active::-webkit-scrollbar-thumb:hover { background: #2563eb; }

        .empty-state { text-align: center; padding: 36px 16px; color: #94a3b8; }
        .empty-state .ico { font-size: 3rem; display: block; margin-bottom: 10px; opacity: 0.5; }

        /* ===== TARJETAS DE ESTADÍSTICAS VPS (estilo panel servidor) ===== */
        .vps-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }
        .vps-card {
            background: #0c1527;
            border: 1px solid #1e293b;
            border-radius: 14px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            transition: border-color 0.2s, transform 0.15s;
        }
        .vps-card:hover {
            border-color: #334155;
            transform: translateY(-2px);
        }
        .vps-card-head {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .vps-card-icon {
            width: 62px;
            height: 62px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            flex-shrink: 0;
        }
        .vps-card-icon.cpu   { background: rgba(56,189,248,0.12); color: #38bdf8; }
        .vps-card-icon.ram   { background: rgba(168,85,247,0.12); color: #a855f7; }
        .vps-card-icon.net   { background: rgba(16,185,129,0.12); color: #10b981; }
        .vps-card-icon.listeners { background: rgba(244,63,94,0.12); color: #f43f5e; }

        .vps-card-titles { flex: 1; display: flex; flex-direction: column; gap: 4px; }
        .vps-card-label {
            color: #94a3b8;
            font-size: 0.85rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .vps-card-value {
            color: #fff;
            font-size: 2rem;
            font-weight: 900;
            font-family: "SFMono-Regular", Menlo, Consolas, monospace;
            line-height: 1;
        }
        .vps-card-value.small { font-size: 1.4rem; font-weight: 800; }
        .vps-card-bar {
            margin-top: auto;
            width: 100%;
            height: 8px;
            background: #111e38;
            border-radius: 100px;
            overflow: hidden;
        }
        .vps-card-bar-fill {
            height: 100%;
            border-radius: 100px;
            transition: width 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            width: 0%;
        }
        .vps-card-bar-fill.cpu    { background: linear-gradient(90deg, #0ea5e9, #38bdf8); }
        .vps-card-bar-fill.ram    { background: linear-gradient(90deg, #7c3aed, #a855f7); }
        .vps-card-bar-fill.net    { background: linear-gradient(90deg, #059669, #10b981); }
        .vps-card-bar-fill.listeners { background: linear-gradient(90deg, #e11d48, #f43f5e); }

        .vps-card-sub {
            color: #cbd5e1;
            font-size: 0.9rem;
            font-family: "SFMono-Regular", Menlo, Consolas, monospace;
            margin-top: 4px;
        }
        .vps-card-sub .rx { color: #6ee7b7; font-weight: bold; }
        .vps-card-sub .tx { color: #86efac; font-weight: bold; }

        /* ===== RESPONSIVE ADMIN: sidebar tipo drawer ===== */
        .admin-nav-toggle, .admin-scrim { display: none; }
        .admin-nav-toggle svg { display: block; }
        @media (max-width: 992px) {
            .admin-wrap { display: block; }
            .admin-sidebar {
                position: fixed; top: 0; bottom: 0; left: 0;
                width: min(280px, 86vw); height: 100vh;
                z-index: 1200; transform: translateX(-105%); transition: transform 0.25s ease;
            }
            .admin-sidebar.open { transform: none; box-shadow: 12px 0 40px rgba(0, 0, 0, 0.5); }
            .admin-scrim {
                display: block; position: fixed; inset: 0; z-index: 1150;
                background: rgba(2, 6, 17, 0.62); opacity: 0; visibility: hidden;
                transition: opacity 0.25s ease, visibility 0.25s ease;
            }
            .admin-scrim.show { opacity: 1; visibility: visible; }
            .admin-nav-toggle {
                display: inline-flex; align-items: center; justify-content: center;
                width: 40px; height: 40px; flex: 0 0 auto; cursor: pointer;
                background: transparent; border: 1px solid #1e293b; color: #fff; padding: 0;
            }
            .admin-nav-toggle:hover { background: #111e38; }
            .admin-content { width: 100%; }
            .admin-topbar { padding: 10px 14px; }
            .admin-topbar h1 { margin-right: auto; }
            .admin-topbar h1 .sub { display: none; }
            .view-content { padding: 16px 14px; }
        }
        @media (max-width: 600px) {
            .admin-topbar .meta .chip:nth-child(n+2) { display: none; }
        }

        /* ===== ESTÉTICA MINIMAL ADMIN: esquinas rectas (igual que la cabina) =====
           Solo los botones de acción conservan el redondeo. */
        .admin-wrap * { border-radius: 0 !important; }
        .admin-wrap .btn, .admin-wrap a[class*="btn"], .admin-wrap button[class*="btn"],
        .admin-wrap input[type="submit"], .admin-wrap input[type="button"] { border-radius: 6px !important; }
        /* Excepción: el punto de estado de la barra superior sigue siendo círculo */
        .admin-wrap .dot { border-radius: 50% !important; }
    </style>
</head>
<body>

<div class="admin-wrap">
    <div class="admin-scrim" id="adminScrim"></div>

    <!-- SIDEBAR -->
    <aside class="admin-sidebar">
        <div class="admin-brand">
            <div class="icon">⚙️</div>
            <div>
                <span class="title">SuperRadio Admin</span>
                <span class="subtitle">Super Panel de Control</span>
            </div>
        </div>

        <nav class="admin-nav">
            <div class="nav-section-title">General</div>
            <a class="nav-item <?= ($view === 'dashboard') ? 'active' : '' ?>" href="superradio.php?view=dashboard">
                <span class="ico">📊</span> Dashboard
                <span class="count"><?= count($db['radios']) + count($db['usuarios']) ?></span>
            </a>
            <a class="nav-item <?= ($view === 'cuenta') ? 'active' : '' ?>" href="superradio.php?view=cuenta">
                <span class="ico">👤</span> Mi Cuenta
            </a>

            <div class="nav-section-title">Gestión</div>
            <a class="nav-item <?= ($view === 'radios') ? 'active' : '' ?>" href="superradio.php?view=radios">
                <span class="ico">📻</span> Emisoras
                <span class="count"><?= count($db['radios'] ?? []) ?></span>
            </a>
            <a class="nav-item <?= ($view === 'clientes') ? 'active' : '' ?>" href="superradio.php?view=clientes">
                <span class="ico">👥</span> Clientes
                <span class="count"><?= count($db['usuarios'] ?? []) ?></span>
            </a>
            <?php
                $_sec_all = sec_get_all_throttles();
                $_sec_all_ips = sec_ip_get_all();
                $_sec_bloq_now = 0;
                foreach ($_sec_all as $_r) if (!empty($_r['blocked'])) $_sec_bloq_now++;
                foreach ($_sec_all_ips as $_r) if (!empty($_r['blocked'])) $_sec_bloq_now++;
            ?>
            <a class="nav-item <?= ($view === 'seguridad') ? 'active' : '' ?>" href="superradio.php?view=seguridad">
                <span class="ico">🔐</span> Seguridad
                <?php if ($_sec_bloq_now > 0): ?><span class="count" style="background:#7f1d1d; color:#fecaca;"><?= $_sec_bloq_now ?> 🔒</span><?php endif; ?>
            </a>
            <a class="nav-item <?= ($view === 'correo') ? 'active' : '' ?>" href="superradio.php?view=correo">
                <span class="ico">✉️</span> Correo
            </a>
        </nav>

        <div class="admin-sidebar-actions">
            <a href="?logout=1" class="btn btn-danger btn-sm" style="width:100%;">🚪 Cerrar Sesión</a>
        </div>
    </aside>

    <!-- CONTENIDO -->
    <div class="admin-content">
        <div class="admin-topbar">
            <button type="button" class="admin-nav-toggle" id="adminNavToggle" aria-label="Abrir menú" aria-expanded="false">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>
            <h1>
                <span class="dot"></span>
                <?php if ($view === 'dashboard'): ?>
                    Panel de Control
                    <span class="sub" style="font-weight:normal; font-size:0.82rem; color:#94a3b8; margin-left:8px;">Vista general del sistema</span>
                <?php elseif ($view === 'cuenta'): ?>
                    Mi Cuenta
                    <span class="sub" style="font-weight:normal; font-size:0.82rem; color:#94a3b8; margin-left:8px;">Cambiar usuario y contraseña del superadmin</span>
                <?php elseif ($view === 'radios'): ?>
                    Gestión de Emisoras
                    <span class="sub" style="font-weight:normal; font-size:0.82rem; color:#94a3b8; margin-left:8px;">Crear, editar y eliminar emisoras</span>
                <?php elseif ($view === 'seguridad'): ?>
                    Seguridad y Bloqueos
                    <span class="sub" style="font-weight:normal; font-size:0.82rem; color:#94a3b8; margin-left:8px;">Anti-bruteforce: intentos fallidos y bloqueos de login</span>
                <?php elseif ($view === 'correo'): ?>
                    Correo / Mensajes
                    <span class="sub" style="font-weight:normal; font-size:0.82rem; color:#94a3b8; margin-left:8px;">Configuración SMTP y envío de mensajes a clientes</span>
                <?php else: ?>
                    Gestión de Clientes
                    <span class="sub" style="font-weight:normal; font-size:0.82rem; color:#94a3b8; margin-left:8px;">Usuarios y asignación de emisoras</span>
                <?php endif; ?>
            </h1>
            <div class="meta">
                <span class="chip">
                    👤 Superadmin: <strong style="color:#fff;"><?= htmlspecialchars($superadmin_user) ?></strong>
                </span>
                <span class="chip">
                    📡 CPU: <strong style="color:#fff;" id="stat-cpu">0%</strong>
                </span>
                <span class="chip">
                    👥 Oyentes: <strong style="color:#fff;" id="stat-listeners">0</strong>
                </span>
            </div>
        </div>

        <div class="view-content">
            <?php if ($msg): ?>
                <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>

            <!-- VISTA: DASHBOARD -->
            <?php if ($view === 'dashboard'): ?>
                <!-- ===== TARJETAS VPS EN VIVO ===== -->
                <div class="vps-stats-grid">
                    <!-- CPU -->
                    <div class="vps-card">
                        <div class="vps-card-head">
                            <div class="vps-card-icon cpu">
                                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M15 2v2M15 20v2M2 15h2M20 15h2M9 2v2M9 20v2M2 9h2M20 9h2"/></svg>
                            </div>
                            <div class="vps-card-titles">
                                <span class="vps-card-label">Uso de CPU</span>
                                <span class="vps-card-value" id="vps-cpu-val">0%</span>
                            </div>
                        </div>
                        <div class="vps-card-bar">
                            <div class="vps-card-bar-fill cpu" id="vps-cpu-bar"></div>
                        </div>
                    </div>

                    <!-- RAM -->
                    <div class="vps-card">
                        <div class="vps-card-head">
                            <div class="vps-card-icon ram">
                                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="8" width="20" height="8" rx="2"/><path d="M6 12h.01M10 12h.01M14 12h.01M18 12h.01"/></svg>
                            </div>
                            <div class="vps-card-titles">
                                <span class="vps-card-label">Memoria RAM</span>
                                <span class="vps-card-value small" id="vps-ram-val">0 / 0 MB (0%)</span>
                            </div>
                        </div>
                        <div class="vps-card-bar">
                            <div class="vps-card-bar-fill ram" id="vps-ram-bar"></div>
                        </div>
                    </div>

                    <!-- TRÁFICO -->
                    <div class="vps-card">
                        <div class="vps-card-head">
                            <div class="vps-card-icon net">
                                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12h-4l-3 9L9 3l-3 9H2"/></svg>
                            </div>
                            <div class="vps-card-titles">
                                <span class="vps-card-label">Tráfico Total (TX/RX)</span>
                                <div class="vps-card-sub" style="margin-top:6px;">
                                    <span class="rx" id="vps-rx-val">↓ 0.0 MB</span>
                                    <span style="color:#475569; margin:0 6px;">|</span>
                                    <span class="tx" id="vps-tx-val">↑ 0.0 MB</span>
                                </div>
                            </div>
                        </div>
                        <div class="vps-card-bar">
                            <div class="vps-card-bar-fill net" id="vps-net-bar" style="width:40%;"></div>
                        </div>
                    </div>

                    <!-- OYENTES -->
                    <div class="vps-card">
                        <div class="vps-card-head">
                            <div class="vps-card-icon listeners">
                                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>
                            </div>
                            <div class="vps-card-titles">
                                <span class="vps-card-label">Oyentes / Conectados</span>
                                <span class="vps-card-value" id="vps-listeners-val">0 activos</span>
                            </div>
                        </div>
                        <div class="vps-card-bar">
                            <div class="vps-card-bar-fill listeners" id="vps-listeners-bar"></div>
                        </div>
                    </div>
                </div>

                <div class="grid-stats">
                    <div class="stat-card">
                        <div class="stat-icon">📻</div>
                        <div>
                            <span class="stat-label">Emisoras Registradas</span>
                            <strong class="stat-value text-sky"><?= count($db['radios'] ?? []) ?></strong>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🎚️</div>
                        <div>
                            <span class="stat-label">Modo AutoDJ</span>
                            <strong class="stat-value text-green"><?= count(array_filter($db['radios'] ?? [], fn($r) => ($r['modo_radio'] ?? 'autodj') === 'autodj')) ?></strong>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🎙️</div>
                        <div>
                            <span class="stat-label">Modo Directa</span>
                            <strong class="stat-value" style="color:#f59e0b;"><?= count(array_filter($db['radios'] ?? [], fn($r) => ($r['modo_radio'] ?? 'autodj') === 'directa')) ?></strong>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div>
                            <span class="stat-label">Clientes Activos</span>
                            <strong class="stat-value text-green"><?= count($db['usuarios'] ?? []) ?></strong>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📦</div>
                        <div>
                            <span class="stat-label">Asignaciones Radio-Cliente</span>
                            <strong class="stat-value" style="color:#38bdf8;">
                                <?php
                                $total_asignaciones = 0;
                                foreach (($db['usuarios'] ?? []) as $u) {
                                    if (is_array($u['radio_ids'] ?? null)) {
                                        $total_asignaciones += count($u['radio_ids']);
                                    } elseif (!empty($u['radio_id'])) {
                                        $total_asignaciones += 1;
                                    }
                                }
                                echo (int)$total_asignaciones;
                                ?>
                            </strong>
                        </div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap:22px;">
                    <div class="card">
                        <h4>📻 Últimas Emisoras Creadas</h4>
                        <?php if (empty($db['radios'])): ?>
                            <div class="empty-state"><span class="ico">📻</span>Aún no hay emisoras creadas. Crea la primera desde el menú Emisoras.</div>
                        <?php else:
                            $sorted_radios = $db['radios'];
                            usort($sorted_radios, fn($a,$b) => strtotime($b['created_at'] ?? $b['fecha_creacion'] ?? 0) - strtotime($a['created_at'] ?? $a['fecha_creacion'] ?? 0));
                            $sorted_radios = array_slice($sorted_radios, 0, 6);
                        ?>
                        <div class="tbl-wrap">
                            <table>
                                <thead><tr><th>Nombre</th><th>Mount</th><th>Modo</th><th>Puerto</th></tr></thead>
                                <tbody>
                                    <?php foreach ($sorted_radios as $r):
                                        $modo = $r['modo_radio'] ?? 'autodj';
                                        $p = (int)($r['dj_port'] ?? 0);
                                        $dup = ($p >= 1024 && count($_port_usage_viz[$p] ?? []) > 1);
                                    ?>
                                    <tr>
                                        <td style="font-weight:bold;"><?= htmlspecialchars($r['nombre_emisora'] ?? '--') ?></td>
                                        <td class="mono text-green">/<?= htmlspecialchars($r['mountpoint'] ?? '') ?></td>
                                        <td>
                                            <span class="tag <?= $modo === 'directa' ? 'amber' : 'green' ?>">
                                                <?= $modo === 'directa' ? '🎙️ DIRECTA' : '🎚️ AUTODJ' ?>
                                            </span>
                                        </td>
                                        <td class="mono">
                                            <?= $p ?>
                                            <?php if ($dup): ?><span class="tag red" title="Puerto duplicado">⚠️</span><?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="text-align:right; margin-top:14px;">
                            <a href="superradio.php?view=radios" class="btn btn-primary btn-sm">Gestionar todas →</a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <h4>👥 Últimos Clientes Creados</h4>
                        <?php if (empty($db['usuarios'])): ?>
                            <div class="empty-state"><span class="ico">👥</span>Aún no hay clientes registrados.</div>
                        <?php else:
                            $sorted_u = $db['usuarios'];
                            usort($sorted_u, fn($a,$b) => strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0));
                            $sorted_u = array_slice($sorted_u, 0, 6);
                        ?>
                        <div class="tbl-wrap">
                            <table>
                                <thead><tr><th>Cliente</th><th>Usuario</th><th>Emisoras</th></tr></thead>
                                <tbody>
                                    <?php foreach ($sorted_u as $ukey => $u):
                                        $rids = $u['radio_ids'] ?? (!empty($u['radio_id']) ? [$u['radio_id']] : []);
                                        $radio_names = [];
                                        foreach ($rids as $rid) $radio_names[] = ($db['radios'][$rid]['nombre_emisora'] ?? 'N/A');
                                    ?>
                                    <tr>
                                        <td style="font-weight:bold;"><?= htmlspecialchars($u['nombre_completo'] ?? $u['nombre'] ?? '--') ?></td>
                                        <td class="mono text-sky"><?= htmlspecialchars($u['usuario'] ?? $ukey) ?></td>
                                        <td><?= count($radio_names) > 0
                                            ? '<span class="tag primary">' . htmlspecialchars($radio_names[0]) . '</span>' . (count($radio_names) > 1 ? ' <span class="tag">+' . (count($radio_names)-1) . '</span>' : '')
                                            : '<span class="text-muted">Sin asignar</span>' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="text-align:right; margin-top:14px;">
                            <a href="superradio.php?view=clientes" class="btn btn-primary btn-sm">Gestionar todos →</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- VISTA: MI CUENTA (superadmin) -->
            <?php if ($view === 'cuenta'): ?>
                <div class="page-header">
                    <h2>👤 Mi Cuenta · Superadmin
                        <span class="sub">Cambia el usuario o la contraseña con la que entras al panel</span>
                    </h2>
                </div>
                <div class="card" style="max-width:680px;">
                    <h4>Acceso del Superadmin</h4>
                    <div class="grid-2 mb-3">
                        <div>
                            <label class="form-label">Usuario actual</label>
                            <input class="form-control" type="text" value="<?= htmlspecialchars($db['superadmin']['usuario'] ?? '') ?>" disabled>
                        </div>
                        <div>
                            <label class="form-label">Creado el</label>
                            <input class="form-control" type="text" value="<?= htmlspecialchars($db['superadmin']['created_at'] ?? '—') ?>" disabled>
                        </div>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="sa_update_account">
                        <div class="mb-3">
                            <label class="form-label">Nuevo usuario (vacío = mantener el actual)</label>
                            <input class="form-control" type="text" name="nuevo_usuario" value="" autocomplete="username">
                        </div>
                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">Contraseña actual (obligatoria para guardar)</label>
                                <input class="form-control" type="password" name="cur_password" required autocomplete="current-password">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Nueva contraseña (mín. 8, vacío = no cambiar)</label>
                                <input class="form-control" type="password" name="new_password" autocomplete="new-password">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Repite la nueva contraseña</label>
                            <input class="form-control" type="password" name="new_password2" autocomplete="new-password">
                        </div>
                        <button type="submit" class="btn btn-primary">💾 Guardar cambios</button>
                    </form>
                    <p class="text-muted" style="margin-top:14px;">Después de cambiar tu contraseña la sesión actual sigue activa; la próxima vez deberás entrar con los datos nuevos.</p>
                </div>

                <div class="card" style="max-width:680px;">
                    <h4>Datos del negocio / contacto</h4>
                    <p class="text-muted" style="margin-top:-6px; margin-bottom:16px;">Identifican a tu empresa: nombre, email, WhatsApp y ubicación. Se usan como remitente y en las plantillas de correo. Ej.: negocio "Radios CR".</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_negocio">
                        <div class="mb-3">
                            <label class="form-label">Nombre del negocio</label>
                            <input class="form-control" type="text" name="negocio_nombre" value="<?= htmlspecialchars(trim($db['negocio']['nombre'] ?? '')) ?>" maxlength="60">
                        </div>
                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input class="form-control" type="email" name="negocio_email" value="<?= htmlspecialchars(trim($db['negocio']['email'] ?? '')) ?>" autocomplete="email" placeholder="email@tuemail.com">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">WhatsApp (con código de país)</label>
                                <input class="form-control" type="text" name="negocio_whatsapp" value="<?= htmlspecialchars(trim($db['negocio']['whatsapp'] ?? '')) ?>" maxlength="24">
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">País</label>
                                <input class="form-control" type="text" name="negocio_pais" value="<?= htmlspecialchars(trim($db['negocio']['pais'] ?? '')) ?>" maxlength="60">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Provincia</label>
                                <input class="form-control" type="text" name="negocio_provincia" value="<?= htmlspecialchars(trim($db['negocio']['provincia'] ?? '')) ?>" maxlength="60">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Dirección</label>
                            <input class="form-control" type="text" name="negocio_direccion" value="<?= htmlspecialchars(trim($db['negocio']['direccion'] ?? '')) ?>" maxlength="160">
                        </div>
                        <button type="submit" class="btn btn-primary">Guardar datos del negocio</button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- VISTA: CORREO -->
            <?php if ($view === 'correo'):
                $_corr_cfg = sp_smtp_cfg_load($db);
                $_corr_has_smtp = ($_corr_cfg['host'] !== '');
                $_neg = (array)($db['negocio'] ?? []);
                $_neg_nombre = trim($_neg['nombre'] ?? '') !== '' ? trim($_neg['nombre']) : 'SuperRadio';
                $_neg_email_now = trim($_neg['email'] ?? '') !== '' ? trim($_neg['email']) : trim($db['superadmin']['email'] ?? '');
                $_sa_email_now = $_neg_email_now;
                $_neg_wa = trim($_neg['whatsapp'] ?? '');
                $_neg_wa_line = $_neg_wa !== '' ? "\nWhatsApp: {whatsapp}" : '';
            ?>
                <div class="page-header">
                    <h2>✉️ Correo / Mensajes
                        <span class="sub">Configura el envío (SMTP) y escribe a tus clientes</span>
                    </h2>
                </div>

                <?php if (!$_corr_has_smtp): ?>
                    <div class="alert" style="background:#7f1d1d; border-color:#dc2626;">Aún no configuraste el servidor SMTP: completá la configuración de abajo para poder enviar correos.</div>
                <?php endif; ?>

                <div class="card">
                    <h4>Configuración SMTP</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_smtp_config">
                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">Servidor SMTP (host)</label>
                                <input class="form-control" type="text" name="host" value="<?= htmlspecialchars($_corr_cfg['host']) ?>" placeholder="smtp.dominio.com">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Puerto</label>
                                <input class="form-control" type="number" name="port" value="<?= (int)$_corr_cfg['port'] ?>" min="1" max="65535">
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">Seguridad</label>
                                <select class="form-control" name="secure">
                                    <option value="tls" <?= $_corr_cfg['secure'] === 'tls' ? 'selected' : '' ?>>STARTTLS (recomendado, ej. 587)</option>
                                    <option value="ssl" <?= $_corr_cfg['secure'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS directo (ej. 465)</option>
                                    <option value="" <?= $_corr_cfg['secure'] === '' ? 'selected' : '' ?>>Sin cifrado</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Usuario (solo si requiere autenticación)</label>
                                <input class="form-control" type="text" name="username" value="<?= htmlspecialchars($_corr_cfg['username']) ?>" autocomplete="off" placeholder="email@tuemail.com">
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">Contraseña SMTP (vacío = no cambiar)</label>
                                <input class="form-control" type="password" name="smtp_pass" autocomplete="new-password" placeholder="••••••••">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Repetir contraseña</label>
                                <input class="form-control" type="password" name="smtp_pass2" autocomplete="new-password">
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">Remitente: nombre</label>
                                <input class="form-control" type="text" name="from_name" value="<?= htmlspecialchars($_corr_cfg['from_name']) ?>" placeholder="SuperRadio">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Remitente: email</label>
                                <input class="form-control" type="email" name="from_email" value="<?= htmlspecialchars($_corr_cfg['from_email']) ?>" placeholder="email@tuemail.com">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Guardar configuración</button>
                    </form>
                    <form method="POST" style="margin-top:14px;">
                        <input type="hidden" name="action" value="test_smtp_mail">
                        <button type="submit" class="btn btn-success btn-sm" <?= $_sa_email_now === '' ? 'disabled' : '' ?>>Enviar correo de prueba<?= $_sa_email_now !== '' ? ' a ' . htmlspecialchars($_sa_email_now) : ' (configurá tu email en Mi Cuenta)' ?></button>
                    </form>
                    <?php if ($_corr_cfg['from_email'] === '' && $_sa_email_now !== ''): ?>
                        <p class="text-muted" style="margin-top:10px;">Sin remitente propio, los correos saldrán desde el email del negocio (<?= htmlspecialchars($_sa_email_now) ?>).</p>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h4>Enviar mensaje a clientes</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="send_client_mail">
                        <div class="mb-3">
                            <label class="form-label">Destinatario</label>
                            <select class="form-control" name="destino" required>
                                <option value="">— Elegir —</option>
                                <option value="__all__">Todos los clientes con email</option>
                                <?php foreach (($db['usuarios'] ?? []) as $_cuk => $_cu):
                                    $_cue = trim($_cu['email'] ?? '');
                                    if (filter_var($_cue, FILTER_VALIDATE_EMAIL) === false) continue;
                                ?>
                                <option value="<?= htmlspecialchars($_cuk) ?>"><?= htmlspecialchars(($_cu['nombre_completo'] ?? $_cu['nombre'] ?? $_cuk)) ?> (<?= htmlspecialchars($_cuk) ?>) — <?= htmlspecialchars($_cue) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php
                                $_corr_sin_email = 0;
                                foreach (($db['usuarios'] ?? []) as $_cu) if (filter_var(trim($_cu['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) $_corr_sin_email++;
                                if ($_corr_sin_email > 0): ?><p class="text-muted" style="margin-top:6px;"><?= (int)$_corr_sin_email ?> cliente(s) no tienen email y no aparecen en la lista.</p><?php endif;
                            ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Plantilla rápida</label>
                            <select class="form-control" id="tmpl-mail" onchange="applyMailTemplate(this.value)">
                                <option value="">— Escribir manualmente —</option>
                                <option value="rec_pago">Recordatorio de pago / renovación</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Asunto</label>
                            <input class="form-control" type="text" name="asunto" id="mail-subject" required placeholder="Asunto del mensaje">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mensaje</label>
                            <textarea class="form-control" name="mensaje" id="mail-body" rows="8" required placeholder="Escribe el mensaje para tus clientes..."></textarea>
                            <p class="text-muted" style="margin-top:6px;">Variables que se reemplazan por cada cliente al enviar: {nombre}, {usuario}, {emisora}, {email}, {fecha}. También {negocio} (tu marca) y {whatsapp} (si lo configuraste en Mi Cuenta).</p>
                        </div>
                        <button type="submit" class="btn btn-primary">Enviar mensaje</button>
                    </form>
                    <script>
                    (function () {
                        window.applyMailTemplate = function (tpl) {
                            var subject = document.getElementById('mail-subject');
                            var body = document.getElementById('mail-body');
                            if (!subject || !body) return;
                            if (tpl === 'rec_pago') {
                                subject.value = 'Recordatorio de renovación — {nombre}';
                                body.value = 'Hola {nombre},\n\nTe escribimos para recordarte que el servicio de tu radio debe renovarse para seguir al aire sin interrupciones.\n\nTu usuario: {usuario}\nTu emisora: {emisora}\n\nSi ya realizaste el pago, ignora este mensaje. Si necesitás ayuda con la renovación, respondé este correo o escribinos.' + <?= json_encode($_neg_wa_line) ?> + '\n\nSaludos,\nEquipo {negocio}';
                            } else {
                                subject.value = '';
                                body.value = '';
                            }
                        };
                    })();
                    </script>
                </div>
            <?php endif; ?>

            <!-- VISTA: EMISORAS -->
            <?php if ($view === 'radios'): ?>
                <div class="page-header">
                    <h2>
                        📻 Gestión de Emisoras
                        <span class="sub">Administra todas las emisoras del sistema</span>
                    </h2>
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span class="chip">Siguiente puerto libre sugerido: <strong style="color:#4ade80;"><?= (int)$puerto_sugerido ?></strong></span>
                        <button type="button" class="btn btn-success btn-sm" id="btn-toggle-create-radio" onclick="toggleForm('create-radio-form', this, '➕ Crear Radio', '➖ Cancelar')">
                            <span class="txt-btn">➕ Crear Radio</span>
                        </button>
                    </div>
                </div>

                <div class="card" id="create-radio-form" style="display:none;">
                    <h4 class="green">➕ Nueva Emisora</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_radio">
                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">Nombre de la Emisora *</label>
                                <input type="text" name="nombre_emisora" placeholder="Radio Aurora" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Mountpoint (solo letras/números) *</label>
                                <input type="text" name="mountpoint" placeholder="aurora" class="form-control" required pattern="[a-zA-Z0-9_-]+" title="Solo letras, números, guiones y guiones bajos.">
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">Contraseña Encoder (BUTT / OBS) *</label>
                                <input type="text" name="encoder_pass" placeholder="Clave segura para el encoder" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Puerto DJ (dejar vacío = auto: <?= (int)$puerto_sugerido ?>)</label>
                                <input type="number" name="dj_port" placeholder="<?= (int)$puerto_sugerido ?> (recomendado)" class="form-control" min="1024" max="65535">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Modo de la Emisora *</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="modo_radio" value="autodj" checked>
                                    <span class="opt-title">🎚️ AutoDJ (con espacio para archivos)</span>
                                    <span class="opt-desc">Manejo completo de música, playlists, parrilla, anuncios, voz de hora y AutoDJ 24/7. Necesita espacio en disco.</span>
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="modo_radio" value="directa">
                                    <span class="opt-title">🎙️ Radio Directa (solo DJ en vivo)</span>
                                    <span class="opt-desc">Solo conexión en vivo por BUTT/OBS/Mixxx. NO crea carpetas de música, NO hay AutoDJ. Ideal para locutor en directo.</span>
                                </label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Cuota de Espacio (MB) · 0 = ilimitado</label>
                            <input type="number" name="quota_mb" placeholder="2048 (2GB por defecto en AutoDJ)" class="form-control" min="0">
                            <small class="text-muted">Para modo Directa: 0 recomendado (no necesita espacio). Para AutoDJ: 1024-5120 MB recomendado.</small>
                        </div>

                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">🎚️ Calidad / Bitrate MP3 (kbps)</label>
                                <select name="bitrate" class="form-control" required>
                                    <option value="64">64 kbps · Básico (ahorra ancho de banda · radios habladas)</option>
                                    <option value="96">96 kbps · Estándar Bajo (móviles, conexiones lentas)</option>
                                    <option value="128" selected>128 kbps · Estándar (recomendado por defecto)</option>
                                    <option value="192">192 kbps · Alta calidad (estudio profesional)</option>
                                    <option value="256">256 kbps · Muy alta calidad (poca diferencia perceptible)</option>
                                    <option value="320">320 kbps · Calidad Máxima (CD · doble de ancho de banda)</option>
                                </select>
                                <small class="text-muted">El bitrate determina la calidad sonora del stream público y el consumo de ancho de banda. ⚠️ 320kbps x oyente = 144 MB/h. El cliente DJ lo ve en su panel.</small>
                            </div>
                        </div>

                        <div class="mb-3" style="background:#082f49; border:1px solid #0369a1; border-radius:8px; padding:12px 14px;">
                            <label class="form-label" style="color:#bae6fd; display:flex; align-items:center; gap:6px;">
                                🔒 Fondo Loop Oculto (SOLO Modo Directa · SUPERADMIN)
                                <span class="tag amber" style="font-size:0.7rem;">Cliente no ve esta config</span>
                            </label>
                            <label class="radio-option" style="display:flex; align-items:center; gap:8px; padding:6px 4px 4px 4px; margin-bottom:8px;">
                                <input type="radio" name="fondo_oculto_op" value="silencio" checked style="width:18px; height:18px;">
                                <span><strong style="color:#fff;">Silencio nativo (default)</strong><br><small class="text-muted">blank() de Liquidsoap - sin archivos, sin rutas, indestructible. Recomendado.</small></span>
                            </label>
                            <label class="radio-option" style="display:flex; align-items:center; gap:8px; padding:4px;">
                                <input type="radio" name="fondo_oculto_op" value="ruta" style="width:18px; height:18px;">
                                <span style="flex:1;"><strong style="color:#fff;">Ruta MP3 o Carpeta (fondo musical)</strong><br>
                                <input type="text" name="directa_fondo_oculto_path" placeholder="Ej. /var/media/radios/_fondo_admin/cortinilla_radio.mp3  o  /var/media/radios/_fondo_admin/carpeta_fondo/" class="form-control" style="margin-top:6px;"></span>
                            </label>
                            <small class="text-muted" style="display:block; margin-top:8px;">
                                ⚠️ Importante: Si eliges ruta, antes Sube los archivos MP3 al VPS vía SSH/SFTP en una carpeta dedicada (ej. <code>/var/media/radios/_fondo_admin/</code>). Chown <code>www-data:www-data</code> y chmod 775. El usuario CLIENTE NUNCA verá estos archivos ni esta opción.
                            </small>
                        </div>

                        <button type="submit" class="btn btn-success" style="padding:12px 24px; font-size:0.95rem;">💾 Crear Emisora</button>
                    </form>
                </div>

                <div class="card">
                    <h4>📋 Lista de Emisoras</h4>
                    <?php if (empty($db['radios'])): ?>
                        <div class="empty-state"><span class="ico">📻</span>Aún no hay emisoras registradas. Crea la primera arriba ☝️</div>
                    <?php else: ?>
                    <div class="tbl-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Emisora</th>
                                    <th>Mount</th>
                                    <th>Modo</th>
                                    <th>Puerto DJ</th>
                                    <th>🎚️ Bitrate</th>
                                    <th>Encoder Pass</th>
                                    <th>Cuota</th>
                                    <th>Creada</th>
                                    <th style="text-align:right;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($db['radios'] as $rid => $r):
                                    $modo = $r['modo_radio'] ?? 'autodj';
                                    $p = (int)($r['dj_port'] ?? 0);
                                    $dup = ($p >= 1024 && count($_port_usage_viz[$p] ?? []) > 1);
                                    $quota = (int)($r['quota_mb'] ?? 0);
                                    $pwd = !empty($r['encoder_pass_encrypted']) ? decrypt_pass($r['encoder_pass_encrypted']) : ($r['encoder_pass'] ?? '--');
                                    $bitrate_show = (int)($r['bitrate'] ?? 128);
                                    if ($bitrate_show >= 256)      { $br_tag_class = 'purple'; }
                                    elseif ($bitrate_show >= 192)  { $br_tag_class = 'green'; }
                                    elseif ($bitrate_show >= 128)  { $br_tag_class = 'blue'; }
                                    elseif ($bitrate_show >= 96)   { $br_tag_class = 'amber'; }
                                    else                            { $br_tag_class = 'gray'; }
                                ?>
                                <tr>
                                    <td style="font-weight:bold; min-width:180px;"><?= htmlspecialchars($r['nombre_emisora'] ?? '--') ?></td>
                                    <td class="mono text-green">/<?= htmlspecialchars($r['mountpoint'] ?? '--') ?></td>
                                    <td>
                                        <span class="tag <?= $modo === 'directa' ? 'amber' : 'green' ?>">
                                            <?= $modo === 'directa' ? '🎙️ DIRECTA' : '🎚️ AUTODJ' ?>
                                        </span>
                                    </td>
                                    <td class="mono">
                                        <strong><?= $p > 0 ? $p : '❌' ?></strong>
                                        <?php if ($dup): ?><span class="tag red" title="⚠️ Puerto duplicado - corregir">⚠️ DUP</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="tag <?= $br_tag_class ?>" title="Calidad de reproducción MP3 del stream público">🎚️ <?= $bitrate_show ?> kbps</span>
                                    </td>
                                    <td class="mono text-muted" title="<?= htmlspecialchars($pwd) ?>"><?= substr(htmlspecialchars($pwd), 0, 10) ?><?= strlen($pwd) > 10 ? '...' : '' ?></td>
                                    <td class="mono">
                                        <?= $quota === 0 ? '<span class="tag green">∞</span>' : $quota . ' MB' ?>
                                    </td>
                                    <td class="text-muted"><?= htmlspecialchars($r['created_at'] ?? $r['fecha_creacion'] ?? '--') ?></td>
                                    <td class="actions" style="justify-content:flex-end;">
                                        <a href="panel.php?mount=<?= urlencode($r['mountpoint'] ?? '') ?>" class="btn btn-primary btn-sm" target="_blank" title="Abrir panel cliente">🎙️ Panel</a>
                                        <button type="button" class="btn btn-warning btn-sm" title="Editar esta emisora" onclick="(function(elId){ var el=document.getElementById(elId); el.classList.toggle('active'); if(el.classList.contains('active')){ try{ el.scrollIntoView({behavior:'smooth', block:'start', inline:'nearest'}); }catch(e){} window.scrollBy(0, -120); el.focus ? el.focus({preventScroll:true}) : null; } })('edit-radio-<?= htmlspecialchars($rid) ?>');">✏️ Editar</button>
                                        <form method="POST" onsubmit="return confirm('¿Eliminar la emisora <?= htmlspecialchars($r['nombre_emisora'] ?? $r['mountpoint'] ?? '') ?>?\n\n⚠️ Se borrarán TODOS los archivos de la emisora en /var/media/radios (música, config, estado, logs) y se detendrá su proceso. Esta acción es IRREVERSIBLE.')" style="margin:0;">
                                            <input type="hidden" name="action" value="delete_radio">
                                            <input type="hidden" name="radio_id" value="<?= htmlspecialchars($rid) ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Eliminar esta emisora">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                                <tr style="background:transparent;">
                                    <td colspan="9" style="padding:0 8px 10px; border:none;">
                                        <div class="row-form" id="edit-radio-<?= htmlspecialchars($rid) ?>">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="update_radio">
                                                <input type="hidden" name="radio_key" value="<?= htmlspecialchars($rid) ?>">
                                                <div class="grid-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">Nombre Emisora</label>
                                                        <input type="text" name="nombre_emisora" value="<?= htmlspecialchars($r['nombre_emisora'] ?? '') ?>" class="form-control" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Mountpoint (⚠️ si lo cambias, se renombra la carpeta /var/media/radios/ automáticamente)</label>
                                                        <input type="text" name="mountpoint" value="<?= htmlspecialchars($r['mountpoint'] ?? '') ?>" class="form-control" required pattern="[a-zA-Z0-9_-]+" title="Solo letras, números, guiones y guiones bajos.">
                                                    </div>
                                                </div>
                                                <div class="grid-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">Nueva Contraseña Encoder (dejar vacío = NO cambiar)</label>
                                                        <input type="text" name="encoder_pass" placeholder="••••••••••••••••" class="form-control">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Puerto DJ (actual: <strong><?= (int)$p ?></strong>)</label>
                                                        <input type="number" name="dj_port" value="<?= (int)$p ?>" class="form-control" min="1024" max="65535">
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Modo de la Emisora</label>
                                                    <div class="radio-group">
                                                        <label class="radio-option">
                                                            <input type="radio" name="modo_radio" value="autodj" <?= (($r['modo_radio'] ?? 'autodj') === 'autodj') ? 'checked' : '' ?>>
                                                            <span class="opt-title">🎚️ AutoDJ (con espacio para archivos)</span>
                                                            <span class="opt-desc">Musicoteca, playlists, parrilla, anuncios, voz de hora y AutoDJ 24/7.</span>
                                                        </label>
                                                        <label class="radio-option">
                                                            <input type="radio" name="modo_radio" value="directa" <?= (($r['modo_radio'] ?? 'autodj') === 'directa') ? 'checked' : '' ?>>
                                                            <span class="opt-title">🎙️ Radio Directa (solo DJ en vivo)</span>
                                                            <span class="opt-desc">Solo conexión BUTT/OBS/Mixxx. No hay AutoDJ ni carpetas predefinidas.</span>
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Cuota de Espacio (MB) · 0 = ilimitado</label>
                                                    <input type="number" name="quota_mb" value="<?= (int)($r['quota_mb'] ?? 0) ?>" class="form-control" min="0">
                                                </div>

                                                <div class="grid-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">🎚️ Calidad / Bitrate MP3 (kbps)</label>
                                                        <select name="bitrate" class="form-control" required>
<?php
    $curBr = (int)($r['bitrate'] ?? 128);
    $brOpts = [
        64  => '64 kbps · Básico (ahorra ancho de banda)',
        96  => '96 kbps · Estándar Bajo (conexiones lentas)',
        128 => '128 kbps · Estándar (recomendado por defecto)',
        192 => '192 kbps · Alta calidad (estudio profesional)',
        256 => '256 kbps · Muy alta calidad',
        320 => '320 kbps · Calidad Máxima (CD · doble ancho de banda)',
    ];
    foreach ($brOpts as $bVal => $bTxt) {
        $sel = ((int)$curBr === (int)$bVal) ? ' selected' : '';
        echo "                                                            <option value=\"{$bVal}\"{$sel}>{$bTxt}</option>\n";
    }
?>
                                                        </select>
                                                        <small class="text-muted">Al cambiar el bitrate, hay que pulsar ▶️ Reiniciar Radio para que el encoder regenerado tome la nueva calidad.</small>
                                                    </div>
                                                </div>

                                                <div class="mb-3" style="background:#082f49; border:1px solid #0369a1; border-radius:8px; padding:12px 14px;">
                                                    <label class="form-label" style="color:#bae6fd; display:flex; align-items:center; gap:6px;">
                                                        🔒 Fondo Loop Oculto (SOLO Modo Directa · SUPERADMIN)
                                                        <span class="tag amber" style="font-size:0.7rem;">Cliente no ve</span>
                                                    </label>
                                                    <?php
                                                        $r_fondo_path = $r['directa_fondo_oculto_path'] ?? '';
                                                        $r_fondo_op = ($r_fondo_path === '') ? 'silencio' : 'ruta';
                                                    ?>
                                                    <label class="radio-option" style="display:flex; align-items:center; gap:8px; padding:6px 4px 4px 4px; margin-bottom:8px;">
                                                        <input type="radio" name="fondo_oculto_op" value="silencio" <?= $r_fondo_op === 'silencio' ? 'checked' : '' ?> style="width:18px; height:18px;">
                                                        <span><strong style="color:#fff;">Silencio nativo (blank())</strong><br><small class="text-muted">Sin archivos. Valor por defecto y más estable.</small></span>
                                                    </label>
                                                    <label class="radio-option" style="display:flex; align-items:center; gap:8px; padding:4px;">
                                                        <input type="radio" name="fondo_oculto_op" value="ruta" <?= $r_fondo_op === 'ruta' ? 'checked' : '' ?> style="width:18px; height:18px;">
                                                        <span style="flex:1;"><strong style="color:#fff;">Ruta MP3 o Carpeta (fondo musical)</strong><br>
                                                        <input type="text" name="directa_fondo_oculto_path" value="<?= htmlspecialchars($r_fondo_path) ?>" placeholder="Ej. /var/media/radios/_fondo_admin/cortinilla_radio.mp3" class="form-control" style="margin-top:6px;"></span>
                                                    </label>
                                                </div>
                                                <div style="display:flex; justify-content:flex-end; gap:8px;">
                                                    <button type="button" class="btn btn-sm" style="background:#334155; color:#fff;" onclick="this.closest('.row-form').classList.remove('active')">Cancelar</button>
                                                    <button type="submit" class="btn btn-success btn-sm">💾 Guardar Cambios</button>
                                                </div>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- VISTA: CLIENTES -->
            <?php if ($view === 'clientes'): ?>
                <div class="page-header">
                    <h2>
                        👥 Gestión de Clientes
                        <span class="sub">Crea usuarios y asigna emisoras (una o varias por cliente)</span>
                    </h2>
                    <div>
                        <button type="button" class="btn btn-success btn-sm" id="btn-toggle-create-user" onclick="toggleForm('create-user-form', this, '➕ Crear Cliente', '➖ Cancelar')">
                            <span class="txt-btn">➕ Crear Cliente</span>
                        </button>
                    </div>
                </div>

                <div class="card" id="create-user-form" style="display:none;">
                    <h4 class="green">➕ Nuevo Cliente</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_user">
                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">Nombre Completo *</label>
                                <input type="text" name="nombre_completo" placeholder="Ej. Juan Pérez Gómez" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Usuario Login *</label>
                                <input type="text" name="usuario" class="form-control" required pattern="[a-zA-Z0-9_.@-]+" title="Solo letras, números y _ . @ -">
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">Contraseña Panel *</label>
                                <input type="text" name="password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" placeholder="email@tuemail.com" class="form-control">
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="mb-3">
                                <label class="form-label">Teléfono</label>
                                <input type="text" name="telefono" placeholder="+506 6000 0000" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Website / Red Social</label>
                                <input type="text" name="website" placeholder="https://dominio.com" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                📻 Emisoras Asignadas a este cliente *
                                <small class="text-muted" style="font-weight:normal; text-transform:none; letter-spacing:0; margin-left:6px;">(Mantén Ctrl/Cmd para varias. La PRIMERA seleccionada = emisora principal)</small>
                            </label>
                            <?php if (empty($db['radios'])): ?>
                                <div class="alert alert-danger" style="margin-bottom:0;">
                                    ⚠️ No hay emisoras creadas todavía. Crea primero una emisora en <a href="superradio.php?view=radios" style="color:#fecaca; font-weight:bold;">Gestión de Emisoras</a>.
                                </div>
                            <?php else: ?>
                                <select name="radio_ids[]" multiple class="form-control" size="5" required>
                                    <?php foreach ($db['radios'] as $rid => $r):
                                        $modo = $r['modo_radio'] ?? 'autodj';
                                        $modo_txt = $modo === 'directa' ? ' [🎙️ Directa]' : ' [🎚️ AutoDJ]';
                                    ?>
                                        <option value="<?= htmlspecialchars($rid) ?>">
                                            📻 <?= htmlspecialchars($r['nombre_emisora']) ?>  —  /<?= htmlspecialchars($r['mountpoint']) ?><?= $modo_txt ?>  ·  Puerto <?= (int)($r['dj_port'] ?? 0) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">💡 Consejo: Si el cliente debe tener acceso a TODAS las emisoras, selecciona todas las opciones.</small>
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-success" style="padding:12px 24px; font-size:0.95rem;" <?= empty($db['radios']) ? 'disabled' : '' ?>>💾 Crear Cliente</button>
                    </form>
                </div>

                <div class="card">
                    <h4>📋 Lista de Clientes</h4>
                    <?php if (empty($db['usuarios'])): ?>
                        <div class="empty-state"><span class="ico">👥</span>Aún no hay clientes. Crea el primero arriba ☝️</div>
                    <?php else: ?>
                    <div class="tbl-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Usuario</th>
                                    <th>Contacto</th>
                                    <th>Emisoras Asignadas</th>
                                    <th>🔐 Estado Login</th>
                                    <th>Creado</th>
                                    <th style="text-align:right;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($db['usuarios'] as $ukey => $u):
                                    $radio_ids = $u['radio_ids'] ?? (!empty($u['radio_id']) ? [$u['radio_id']] : []);
                                    $tags = '';
                                    $primary_id = $u['radio_id'] ?? ($radio_ids[0] ?? null);
                                    foreach ($radio_ids as $rid) {
                                        $r = $db['radios'][$rid] ?? null;
                                        $is_primary = ($rid === $primary_id);
                                        if ($r) {
                                            $tags .= '<span class="tag ' . ($is_primary ? 'primary' : '') . '" title="' . ($is_primary ? 'Emisora principal' : 'Emisora secundaria') . '">'
                                                  . ($is_primary ? '⭐ ' : '')
                                                  . htmlspecialchars($r['nombre_emisora'])
                                                  . '</span>';
                                        } else {
                                            $tags .= '<span class="tag red">❌ Eliminada</span>';
                                        }
                                    }
                                    // Estado seguridad cliente:
                                    $_sec_st_client = sec_get_user_state('u:' . $ukey);
                                    $_blocked_c = !empty($_sec_st_client['blocked_until']) && ((int)$_sec_st_client['blocked_until'] > time());
                                    $_lvl_c = (int)($_sec_st_client['level'] ?? 0);
                                    $_att_c = (int)($_sec_st_client['attempts_count'] ?? 0);
                                ?>
                                <tr>
                                    <td style="font-weight:bold; min-width:170px;"><?= htmlspecialchars($u['nombre_completo'] ?? $u['nombre'] ?? '--') ?></td>
                                    <td class="mono text-sky">@<?= htmlspecialchars($u['usuario'] ?? $ukey) ?></td>
                                    <td style="min-width:180px;">
                                        <?php if (!empty($u['email'])): ?><div class="text-muted">📧 <?= htmlspecialchars($u['email']) ?></div><?php endif; ?>
                                        <?php if (!empty($u['telefono'])): ?><div class="text-muted">📱 <?= htmlspecialchars($u['telefono']) ?></div><?php endif; ?>
                                        <?php if (!empty($u['website'])): ?><div class="text-muted">🌐 <a href="<?= htmlspecialchars($u['website']) ?>" target="_blank" style="color:#38bdf8; text-decoration:none;">Web</a></div><?php endif; ?>
                                        <?php if (empty($u['email']) && empty($u['telefono']) && empty($u['website'])): ?><div class="text-muted">— Sin datos de contacto —</div><?php endif; ?>
                                    </td>
                                    <td style="min-width:250px;">
                                        <?= count($radio_ids) > 0 ? $tags : '<span class="text-muted">⚠️ Sin emisoras asignadas</span>' ?>
                                        <div class="text-muted" style="margin-top:3px; font-size:0.75rem;">Total: <strong><?= count($radio_ids) ?></strong> emisora(s) · Principal: <?= $primary_id ? htmlspecialchars($db['radios'][$primary_id]['nombre_emisora'] ?? 'N/A') : 'Ninguna' ?></div>
                                    </td>
                                    <td style="min-width:170px;">
                                        <?php if ($_blocked_c): ?>
                                            <div class="tag red">🔒 BLOQUEADO</div>
                                            <div class="text-muted" style="margin-top:4px; font-size:0.75rem;">
                                                Hasta: <strong style="color:#fecaca;"><?= date('Y-m-d H:i:s', (int)$_sec_st_client['blocked_until']) ?></strong>
                                                <br>Nivel: <?= $_lvl_c >= 2 ? '48h' : ($_lvl_c === 1 ? '24h' : '1h') ?>
                                            </div>
                                            <form method="POST" onsubmit="return confirm('¿Desbloquear al cliente <?= htmlspecialchars($ukey) ?>?');" style="margin-top:6px;">
                                                <input type="hidden" name="action" value="unblock_user">
                                                <input type="hidden" name="sec_key" value="u:<?= htmlspecialchars($ukey) ?>">
                                                <button type="submit" class="btn btn-warning btn-sm" title="Quitar bloqueo, nivel e intentos">🔓 Desbloquear</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="tag green">✅ Sin bloqueo</span>
                                            <?php if ($_lvl_c > 0 || $_att_c > 0): ?>
                                                <div class="text-muted" style="margin-top:4px; font-size:0.75rem;">
                                                    <?php if ($_att_c > 0): ?>⚠️ Fallidas recientes: <strong style="color:#fcd34d"><?= $_att_c ?></strong><?php endif; ?>
                                                    <?php if ($_lvl_c > 0): ?>&nbsp;· Nivel: <strong style="color:#fb7185"><?= $_lvl_c >= 2 ? '48h' : '24h' ?></strong><?php endif; ?>
                                                </div>
                                                <form method="POST" onsubmit="return confirm('¿Limpiar registro de seguridad de <?= htmlspecialchars($ukey) ?>?');" style="margin-top:6px;">
                                                    <input type="hidden" name="action" value="unblock_user">
                                                    <input type="hidden" name="sec_key" value="u:<?= htmlspecialchars($ukey) ?>">
                                                    <button type="submit" class="btn btn-sm" style="background:#475569; color:#fff;">🧹 Limpiar</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted"><?= htmlspecialchars($u['created_at'] ?? '--') ?></td>
                                    <td class="actions" style="justify-content:flex-end;">
                                        <?php if (!empty($primary_id) && !empty($db['radios'][$primary_id])): ?>
                                            <a href="panel.php?mount=<?= urlencode($db['radios'][$primary_id]['mountpoint'] ?? '') ?>" class="btn btn-primary btn-sm" target="_blank" title="Abrir panel principal del cliente">🎙️</a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-warning btn-sm" title="Editar este cliente" onclick="document.getElementById('edit-<?= htmlspecialchars($ukey) ?>').classList.toggle('active');">✏️ Editar</button>
                                        <form method="POST" onsubmit="return confirm('¿Eliminar definitivamente al cliente <?= htmlspecialchars($ukey) ?>?')" style="margin:0;">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_key" value="<?= htmlspecialchars($ukey) ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Eliminar cliente">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                                <tr style="background:transparent;">
                                    <td colspan="7" style="padding:0 8px 8px; border:none;">
                                        <div class="row-form" id="edit-<?= htmlspecialchars($ukey) ?>">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="update_user">
                                                <input type="hidden" name="user_key" value="<?= htmlspecialchars($ukey) ?>">
                                                <div class="grid-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">Nombre Completo</label>
                                                        <input type="text" name="nombre_completo" value="<?= htmlspecialchars($u['nombre_completo'] ?? $u['nombre'] ?? '') ?>" class="form-control" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Nueva Contraseña (vacío = no cambiar)</label>
                                                        <input type="text" name="password" placeholder="••••••••••" class="form-control">
                                                    </div>
                                                </div>
                                                <div class="grid-2">
                                                    <div class="mb-3">
                                                        <label class="form-label">Email</label>
                                                        <input type="email" name="email" value="<?= htmlspecialchars($u['email'] ?? '') ?>" class="form-control">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Teléfono</label>
                                                        <input type="text" name="telefono" value="<?= htmlspecialchars($u['telefono'] ?? '') ?>" class="form-control">
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Website</label>
                                                    <input type="text" name="website" value="<?= htmlspecialchars($u['website'] ?? '') ?>" class="form-control">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">📻 Emisoras Asignadas (Ctrl/Cmd para múltiple)</label>
                                                    <select name="radio_ids[]" multiple class="form-control" size="4">
                                                        <?php foreach ($db['radios'] as $rid => $r): ?>
                                                            <option value="<?= htmlspecialchars($rid) ?>" <?= in_array($rid, $radio_ids, true) ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($r['nombre_emisora']) ?> (/<?= htmlspecialchars($r['mountpoint']) ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div style="display:flex; justify-content:flex-end; gap:8px;">
                                                    <button type="button" class="btn btn-sm" style="background:#334155; color:#fff;" onclick="this.closest('.row-form').classList.remove('active')">Cancelar</button>
                                                    <button type="submit" class="btn btn-success btn-sm">💾 Guardar Cambios</button>
                                                </div>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- VISTA: SEGURIDAD -->
            <?php if ($view === 'seguridad'):
                $tab_seg = $_GET['tab'] ?? 'bloqueos';
                if (!in_array($tab_seg, ['bloqueos','textos'], true)) $tab_seg = 'bloqueos';

                $_sec_all_data = sec_get_all_throttles();
                $_ip_all_data  = sec_ip_get_all();
                $_s_bloq = 0; $_s_fall = 0; $_s_lvl = 0; $_s_tot = count($_sec_all_data);
                $_i_bloq = 0; $_i_fall = 0; $_i_lvl = 0; $_i_tot = count($_ip_all_data);
                foreach ($_sec_all_data as $_r) {
                    if (!empty($_r['blocked'])) $_s_bloq++;
                    if ($_r['attempts_count'] > 0) $_s_fall++;
                    if ($_r['level'] >= 1) $_s_lvl++;
                }
                foreach ($_ip_all_data as $_r) {
                    if (!empty($_r['blocked'])) $_i_bloq++;
                    if ($_r['attempts_count'] > 0) $_i_fall++;
                    if ($_r['level'] >= 1) $_i_lvl++;
                }
                $_bloq_tot = $_s_bloq + $_i_bloq;
                $_LT_CFG = login_texts_get();
            ?>
                <style>
                    .tab-group {
                        display: flex;
                        gap: 6px;
                        border-bottom: 1px solid #334155;
                        margin: 6px 0 22px 0;
                    }
                    .tab-btn {
                        padding: 10px 18px;
                        background: transparent;
                        border: 0;
                        color: #94a3b8;
                        font-weight: 600;
                        font-size: 0.92rem;
                        cursor: pointer;
                        border-bottom: 3px solid transparent;
                        margin-bottom: -1px;
                        border-radius: 6px 6px 0 0;
                        transition: all 0.15s ease;
                    }
                    .tab-btn:hover { color: #e2e8f0; background: rgba(255,255,255,0.03); }
                    .tab-btn.active {
                        color: #fff;
                        background: rgba(124,58,237,0.12);
                        border-bottom-color: #a78bfa;
                    }
                    .grid-2cols {
                        display: grid;
                        grid-template-columns: 1.2fr 0.8fr;
                        gap: 20px;
                        align-items: start;
                    }
                    @media (max-width: 960px) { .grid-2cols { grid-template-columns: 1fr; } }
                    .card.card-header-blue   > h4 { color: #3b82f6 !important; border-left:3px solid #3b82f6; padding-left:10px; margin-left:-2px; }
                    .card.card-header-purple > h4 { color: #a78bfa !important; border-left:3px solid #a78bfa; padding-left:10px; margin-left:-2px; }
                    .card.card-header-red    > h4 { color: #f87171 !important; border-left:3px solid #f87171; padding-left:10px; margin-left:-2px; }
                    .minipreview-title {
                        font-size: 0.78rem; color:#94a3b8; text-transform: uppercase;
                        letter-spacing:.4px; font-weight: 700; margin: 0 0 10px 0;
                    }
                    .miniprev-shell {
                        width:100%; max-width: 420px; margin:0 auto 24px auto;
                        display: grid; grid-template-columns: 1fr 1fr;
                        background:#fff; border-radius:10px; overflow:hidden;
                        box-shadow:0 6px 24px rgba(0,0,0,0.4); min-height:140px;
                    }
                    .miniprev-form { padding:10px; }
                    .miniprev-form .mf-title { font-weight: 800; color:#111827; font-size:.72rem; }
                    .miniprev-form .mf-sub { font-size:.58rem; color:#6b7280; margin-bottom:6px; }
                    .miniprev-form .mf-box { height:10px; background:#f3f4f6; border-radius:3px; margin:4px 0; }
                    .miniprev-form .mf-btn {
                        margin-top:6px; height:14px; border-radius:4px; color:#fff;
                        font-size:.6rem; font-weight:700; display:flex; align-items:center; justify-content:center;
                        letter-spacing:.5px;
                    }
                    .miniprev-brand {
                        color:#fff; display:flex; align-items:center; justify-content:center;
                        flex-direction:column; text-align:center; padding:10px 8px; gap:4px;
                    }
                    .miniprev-brand .mb-logo { font-weight:900; font-size:.78rem; line-height:1; }
                    .miniprev-brand .mb-sub { font-weight:700; font-size:.6rem; }
                    .miniprev-brand .mb-desc { font-size:.52rem; opacity:.92; line-height:1.3; max-width:140px; }
                </style>

                <div class="page-header">
                    <h2>
                        🔐 Seguridad y Bloqueos
                        <span class="sub">Anti-bruteforce por USUARIO + por IP (como DirectAdmin)</span>
                    </h2>
                    <?php if ($tab_seg === 'bloqueos'): ?>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <form method="POST" onsubmit="return confirm('Limpiar TODOS los registros de USUARIOS (bloqueos/fallidas/niveles)? No afecta IPs.');" style="margin:0;">
                            <input type="hidden" name="action" value="unblock_all_users">
                            <button type="submit" class="btn btn-sm" style="background:#7f1d1d; color:#fff;">
                                🧹 Limpiar USUARIOS
                            </button>
                        </form>
                        <form method="POST" onsubmit="return confirm('Limpiar TODOS los registros de IPs (bloqueos/fallidas/niveles)? No afecta usuarios.');" style="margin:0;">
                            <input type="hidden" name="action" value="unblock_all_ips">
                            <button type="submit" class="btn btn-sm" style="background:#991b1b; color:#fff;">
                                🧹 Limpiar IPs
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                    <?php if ($tab_seg === 'textos'): ?>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button form="frmLoginTexts" type="submit" class="btn btn-sm" style="background:#16a34a; color:#fff;">💾 Guardar Cambios</button>
                        <form method="POST" onsubmit="return confirm('↩️ Restaurar TODOS los textos a valores POR DEFECTO? (perderás los textos personalizados)');" style="margin:0;">
                            <input type="hidden" name="action" value="reset_login_texts">
                            <button type="submit" class="btn btn-sm" style="background:#475569; color:#fff;">↩️ Restaurar Defaults</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="tab-group" role="tablist">
                    <a href="superradio.php?view=seguridad&tab=bloqueos" class="tab-btn <?= ($tab_seg==='bloqueos'?'active':'') ?>">🛡️ Registros y Bloqueos</a>
                    <a href="superradio.php?view=seguridad&tab=textos" class="tab-btn <?= ($tab_seg==='textos'?'active':'') ?>">✏️ Personalizar Textos Login</a>
                </div>

                <?php if ($tab_seg === 'bloqueos'): ?>
                    <!-- Stats 5 tarjetas -->
                    <div class="grid-stats">
                        <div class="stat-card">
                            <div class="stat-icon">🚫</div>
                            <div>
                                <span class="stat-label">TOTAL Bloqueados</span>
                                <strong class="stat-value" style="color:<?= $_bloq_tot > 0 ? '#f87171' : '#4ade80' ?>;"><?= $_bloq_tot ?></strong>
                                <div style="font-size:0.7rem; color:#94a3b8;"><?= $_s_bloq ?> usuarios · <?= $_i_bloq ?> IPs</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">👤</div>
                            <div>
                                <span class="stat-label">Usuarios Bloqueados</span>
                                <strong class="stat-value" style="color:<?= $_s_bloq > 0 ? '#f87171' : '#4ade80' ?>;"><?= $_s_bloq ?></strong>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">🌐</div>
                            <div>
                                <span class="stat-label">IPs Bloqueadas</span>
                                <strong class="stat-value" style="color:<?= $_i_bloq > 0 ? '#f87171' : '#4ade80' ?>;"><?= $_i_bloq ?></strong>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">⚠️</div>
                            <div>
                                <span class="stat-label">Fallidas Recientes</span>
                                <strong class="stat-value" style="color:<?= ($_s_fall+$_i_fall) > 0 ? '#fcd34d' : '#fff' ?>;"><?= $_s_fall+$_i_fall ?></strong>
                                <div style="font-size:0.7rem; color:#94a3b8;">Usuarios:<?= $_s_fall ?> · IPs:<?= $_i_fall ?></div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">🛡️</div>
                            <div>
                                <span class="stat-label">Escalada ≥24h</span>
                                <strong class="stat-value" style="color:<?= ($_s_lvl+$_i_lvl) > 0 ? '#fb7185' : '#fff' ?>;"><?= $_s_lvl+$_i_lvl ?></strong>
                                <div style="font-size:0.7rem; color:#94a3b8;">Sgte fallo = 24h o 48h</div>
                            </div>
                        </div>
                    </div>

                    <!-- Card informativa -->
                    <div class="card">
                        <h4>ℹ️ Cómo funciona el sistema anti-bruteforce (2 niveles)</h4>
                        <small class="text-muted" style="line-height:1.75;">
                            <strong>Nivel 1 · Bloqueo por USUARIO:</strong><br>
                            • Usuario nuevo: 3 fallidas → 1h bloqueo. Si tras expirar vuelve a fallar 1 vez → 24h. Siguiente → 48h.<br>
                            • Fallos caducan a las 2 horas. Al acertar → todo reseteado.<br><br>
                            <strong style="color:#f97316;">Nivel 2 · Bloqueo por IP (como DirectAdmin):</strong><br>
                            • 15 fallidas TOTALES en 1 HORA (cualquier combinación de usuarios desde la misma IP) → IP BLOQUEADA 1h.<br>
                            • Tras expirar: 1+ fallida nueva → 24h. Siguiente → 48h.<br>
                            • Al bloquear IP, al atacante <strong>NO LE SALE NINGÚN FORMULARIO DE LOGIN</strong> → devuelve <code style="background:#111827; padding:2px 6px; border-radius:4px;">HTTP 403 Forbidden</code> con pantalla IP bloqueada (igual que DirectAdmin/Cloudflare).<br>
                            • Los intentos caducan en 1 hora.
                        </small>
                    </div>

                    <!-- Formulario BANEAR IP MANUALMENTE -->
                    <div class="card">
                        <h4 style="color:#fb923c;">✋ Banear IP MANUALMENTE</h4>
                        <form method="POST" onsubmit="return confirm('Confirmas bloquear esta IP manualmente?');" style="display:grid; grid-template-columns:1fr 120px 1fr auto; gap:10px; align-items:end; flex-wrap:wrap;">
                            <input type="hidden" name="action" value="ban_ip_manual">
                            <div>
                                <label class="form-label" style="font-size:0.82rem; color:#cbd5e1; margin-bottom:5px; display:block;">Dirección IP</label>
                                <input type="text" name="ip_manual" class="form-control" required
                                    pattern="^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$|^([0-9a-fA-F:]+)$"
                                    title="Dirección IPv4 o IPv6 válida">
                            </div>
                            <div>
                                <label class="form-label" style="font-size:0.82rem; color:#cbd5e1; margin-bottom:5px; display:block;">Duración</label>
                                <select name="duracion_horas" class="form-control" style="padding:10px 12px;" required>
                                    <option value="1">1 hora</option>
                                    <option value="6">6 horas</option>
                                    <option value="24" selected>24 horas</option>
                                    <option value="48">48 horas</option>
                                    <option value="168">7 días</option>
                                    <option value="720">30 días</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" style="font-size:0.82rem; color:#cbd5e1; margin-bottom:5px; display:block;">Motivo (opcional)</label>
                                <input type="text" name="comentario_manual" class="form-control" maxlength="200">
                            </div>
                            <button type="submit" class="btn btn-danger" style="padding:10px 16px;">🚫 Bloquear IP</button>
                        </form>
                    </div>

                    <!-- TABLA 1: USUARIOS -->
                    <div class="card">
                        <h4>👤 Registros de Seguridad · USUARIOS (clientes + superadmin)</h4>
                        <?php if ($_s_tot === 0): ?>
                            <div style="text-align:center; padding:28px 12px; color:#94a3b8;">
                                <div style="font-size:2.2rem; margin-bottom:8px;">✅</div>
                                <strong style="color:#cbd5e1;">Sin usuarios bloqueados ni fallidas</strong>
                            </div>
                        <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Tipo Login</th>
                                        <th>Usuario</th>
                                        <th>Estado</th>
                                        <th>Fallidas (&lt;2h)</th>
                                        <th>Nivel Escalada</th>
                                        <th>Último Bloqueo</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($_sec_all_data as $_sr):
                                        $_lvl = (int)$_sr['level'];
                                        $_att = (int)$_sr['attempts_count'];
                                        $_blk = !empty($_sr['blocked']);
                                        $_lvl_txt = $_lvl >= 2 ? '48h' : ($_lvl === 1 ? '24h' : '1h');
                                        $_lvl_color = $_lvl >= 2 ? '#fb7185' : ($_lvl === 1 ? '#f97316' : '#60a5fa');
                                        $_last = (int)$_sr['last_block_at'];
                                        $_tipo_tag = $_sr['type'] === 'Superadmin'
                                            ? '<span class="tag" style="background:#7c3aed; color:#ede9fe;">⚙️ Superadmin</span>'
                                            : '<span class="tag" style="background:#2563eb; color:#dbeafe;">👥 Cliente</span>';
                                    ?>
                                    <tr>
                                        <td><?= $_tipo_tag ?></td>
                                        <td style="font-weight:600; color:#fff;"><?= htmlspecialchars($_sr['uname']) ?></td>
                                        <td>
                                            <?php if ($_blk): ?>
                                                <span class="tag red">🔒 BLOQUEADO</span>
                                                <div class="text-muted" style="margin-top:4px; font-size:0.75rem;">
                                                    Hasta: <strong style="color:#fecaca;"><?= date('Y-m-d H:i:s', (int)$_sr['blocked_until_ts']) ?></strong>
                                                </div>
                                            <?php else: ?>
                                                <span class="tag green">✅ Libre</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($_att > 0): ?>
                                                <strong style="color:#fcd34d;"><?= $_att ?></strong>
                                                <div class="text-muted" style="font-size:0.7rem;">últimas 2h</div>
                                            <?php else: ?>
                                                <span class="text-muted">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($_lvl > 0): ?>
                                                <strong style="color:<?= $_lvl_color ?>;"><?= $_lvl_txt ?></strong>
                                                <div class="text-muted" style="font-size:0.7rem;">Nivel <?= $_lvl ?> · bloqueo <?= $_lvl_txt ?> sig. fallo</div>
                                            <?php else: ?>
                                                <span class="text-muted">1h (estándar)</span>
                                                <div class="text-muted" style="font-size:0.7rem;">3 fallos = 1h</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted">
                                            <?php if ($_last > 0): ?>
                                                <?= date('Y-m-d H:i:s', $_last) ?>
                                            <?php else: ?>
                                                <em>— nunca —</em>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions" style="justify-content:flex-start;">
                                            <?php if ($_blk || $_lvl > 0 || $_att > 0): ?>
                                                <form method="POST" onsubmit="return confirm('<?= $_blk ? 'Desbloquear' : 'Limpiar registro de seguridad de' ?> <?= htmlspecialchars($_sr['uname']) ?> (<?= htmlspecialchars($_sr['type']) ?>)?');" style="margin:0;">
                                                    <input type="hidden" name="action" value="unblock_user">
                                                    <input type="hidden" name="sec_key" value="<?= htmlspecialchars($_sr['key']) ?>">
                                                    <?php if ($_blk): ?>
                                                        <button type="submit" class="btn btn-warning btn-sm" title="Quitar bloqueo inmediatamente + limpiar intentos y nivel">🔓 Desbloquear</button>
                                                    <?php else: ?>
                                                        <button type="submit" class="btn btn-sm" style="background:#475569; color:#fff;" title="Limpiar intentos fallidos + resetear nivel a 0">🧹 Limpiar</button>
                                                    <?php endif; ?>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size:0.8rem;">— OK —</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- TABLA 2: IPs -->
                    <div class="card">
                        <h4 style="color:#38bdf8;">🌐 Registros de Seguridad · IPs</h4>
                        <?php if ($_i_tot === 0): ?>
                            <div style="text-align:center; padding:28px 12px; color:#94a3b8;">
                                <div style="font-size:2.2rem; margin-bottom:8px;">✅</div>
                                <strong style="color:#cbd5e1;">Sin IPs bloqueadas ni fallidas recientes</strong>
                            </div>
                        <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>IP</th>
                                        <th>Estado</th>
                                        <th>Fallidas (&lt;1h)</th>
                                        <th>Nivel Escalada</th>
                                        <th>Último Bloqueo</th>
                                        <th>Comentario Admin</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($_ip_all_data as $_ipr):
                                        $_lvl = (int)$_ipr['level'];
                                        $_att = (int)$_ipr['attempts_count'];
                                        $_blk = !empty($_ipr['blocked']);
                                        $_lvl_txt = $_lvl >= 2 ? '48h' : ($_lvl === 1 ? '24h' : '1h');
                                        $_lvl_color = $_lvl >= 2 ? '#fb7185' : ($_lvl === 1 ? '#f97316' : '#60a5fa');
                                        $_last = (int)$_ipr['last_block_at'];
                                        $_mc = trim($_ipr['manual_comment'] ?? '');
                                    ?>
                                    <tr>
                                        <td>
                                            <code style="background:#111827; border:1px solid #334155; padding:3px 8px; border-radius:5px; color:#22d3ee; font-weight:700;"><?= htmlspecialchars($_ipr['ip']) ?></code>
                                        </td>
                                        <td>
                                            <?php if ($_blk): ?>
                                                <span class="tag red">🚫 BLOQUEADA</span>
                                                <div class="text-muted" style="margin-top:4px; font-size:0.75rem;">
                                                    Hasta: <strong style="color:#fecaca;"><?= date('Y-m-d H:i:s', (int)$_ipr['blocked_until_ts']) ?></strong>
                                                </div>
                                            <?php else: ?>
                                                <span class="tag green">✅ Libre</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($_att > 0): ?>
                                                <strong style="color:#fcd34d;"><?= $_att ?></strong>
                                                <div class="text-muted" style="font-size:0.7rem;">< 1 hora (umbral=15)</div>
                                            <?php else: ?>
                                                <span class="text-muted">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($_lvl > 0): ?>
                                                <strong style="color:<?= $_lvl_color ?>;"><?= $_lvl_txt ?></strong>
                                                <div class="text-muted" style="font-size:0.7rem;">Nivel <?= $_lvl ?> · sig. falla = <?= $_lvl_txt ?></div>
                                            <?php else: ?>
                                                <span class="text-muted">1h (estándar)</span>
                                                <div class="text-muted" style="font-size:0.7rem;">15 fallos = 1h</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted">
                                            <?php if ($_last > 0): ?>
                                                <?= date('Y-m-d H:i:s', $_last) ?>
                                            <?php else: ?>
                                                <em>— nunca —</em>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted" style="font-size:0.82rem;">
                                            <?php if (!empty($_mc)): ?>
                                                <span style="background:#7f1d1d; color:#fecaca; padding:3px 8px; border-radius:4px; border-left:3px solid #dc2626;">📝 <?= htmlspecialchars($_mc) ?></span>
                                            <?php else: ?>
                                                <em>— —</em>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions" style="justify-content:flex-start;">
                                            <?php if ($_blk || $_lvl > 0 || $_att > 0): ?>
                                                <form method="POST" onsubmit="return confirm('<?= $_blk ? 'Desbloquear IP' : 'Limpiar registro de IP' ?> <?= htmlspecialchars($_ipr['ip']) ?>?');" style="margin:0;">
                                                    <input type="hidden" name="action" value="unblock_ip">
                                                    <input type="hidden" name="sec_key" value="<?= htmlspecialchars($_ipr['key']) ?>">
                                                    <?php if ($_blk): ?>
                                                        <button type="submit" class="btn btn-warning btn-sm">🔓 Desbloquear</button>
                                                    <?php else: ?>
                                                        <button type="submit" class="btn btn-sm" style="background:#475569; color:#fff;">🧹 Limpiar</button>
                                                    <?php endif; ?>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size:0.8rem;">— OK —</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($tab_seg === 'textos'): ?>

                    <div class="grid-2cols">
                        <!-- COLUMNA IZQUIERDA: FORMULARIOS EDICIÓN -->
                        <div style="display:flex; flex-direction:column; gap:16px;">
                            <form id="frmLoginTexts" method="POST" action="superradio.php?view=seguridad&tab=textos" style="display:flex; flex-direction:column; gap:16px;">
                                <input type="hidden" name="action" value="save_login_texts">

                                <!-- CARD 1: CLIENTE -->
                                <div class="card card-header-blue">
                                    <h4>👥 Login Cliente · Panel Emisora (index.php) — AZUL</h4>
                                    <?php
                                        $s = 'cliente';
                                        $m = $_LT_CFG[$s];
                                        $labels = [
                                            'form_title'=>'Título principal (arriba)',
                                            'form_sub'=>'Subtítulo',
                                            'lbl_user'=>'Label campo Usuario',
                                            'lbl_pwd'=>'Label campo Contraseña',
                                            'btn_toggle_pwd_on'=>'Botón mostrar contraseña (cuando está oculta)',
                                            'btn_toggle_pwd_off'=>'Botón ocultar contraseña (cuando es visible)',
                                            'btn_submit'=>'Botón ENVIAR formulario (uppercase)',
                                            'copyright'=>'Texto copyright inferior (puedes usar {YEAR})',
                                            'brand_logo'=>'Logo grande lado AZUL',
                                            'brand_welcome'=>'Título Bienvenida lado AZUL',
                                            'brand_desc'=>'Descripción lado AZUL',
                                            'btn_contacto_txt'=>'Texto botón CONTACTO lado AZUL',
                                            'btn_contacto_url'=>'URL botón CONTACTO (ej: https://wa.me/506)',
                                        ];
                                    ?>
                                    <div class="grid-stats" style="grid-template-columns:repeat(2, 1fr); gap:10px 14px;">
                                        <?php foreach ($labels as $k=>$lbl): ?>
                                            <div style="margin:0;">
                                                <label class="form-label" style="font-size:0.8rem; color:#cbd5e1; margin-bottom:5px; display:block;"><?= $lbl ?></label>
                                                <?php if (strpos($k, 'desc') !== false || $k === 'copyright' || $k === 'form_sub'): ?>
                                                    <textarea name="<?= $s ?>[<?= $k ?>]" class="form-control" rows="2" maxlength="400" style="resize:vertical; min-height:42px; padding:9px 12px; line-height:1.45;"><?= htmlspecialchars($m[$k]) ?></textarea>
                                                <?php elseif ($k === 'btn_contacto_url'): ?>
                                                    <input type="url" name="<?= $s ?>[<?= $k ?>]" class="form-control" value="<?= htmlspecialchars($m[$k]) ?>" maxlength="1000" placeholder="https://...">
                                                <?php else: ?>
                                                    <input type="text" name="<?= $s ?>[<?= $k ?>]" class="form-control" value="<?= htmlspecialchars($m[$k]) ?>" maxlength="<?= ($k==='form_title'||$k==='brand_logo'||$k==='btn_submit'?80:180) ?>">
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- CARD 2: SUPERADMIN -->
                                <div class="card card-header-purple">
                                    <h4>⚙️ Login Superadmin · SuperRadio (superradio.php) — MORADO</h4>
                                    <?php
                                        $s = 'superadmin';
                                        $m = $_LT_CFG[$s];
                                        $labels = [
                                            'tag_admin' => 'Tag naranja superior (identifica Superadmin)',
                                            'form_title' => 'Título principal formulario',
                                            'form_sub' => 'Subtítulo formulario',
                                            'lbl_user' => 'Label campo Usuario',
                                            'lbl_pwd' => 'Label campo Contraseña',
                                            'btn_toggle_pwd_on' => 'Botón mostrar contraseña (oculta)',
                                            'btn_toggle_pwd_off' => 'Botón ocultar contraseña (visible)',
                                            'btn_submit' => 'Botón ENVIAR formulario (uppercase)',
                                            'copyright' => 'Copyright inferior ({YEAR} se sustituye por año actual)',
                                            'brand_logo' => 'Logo grande lado MORADO',
                                            'brand_tagline' => 'Tagline pequeña lado MORADO',
                                            'brand_desc' => 'Descripción larga lado MORADO',
                                            'brand_chips' => 'Chips (separar con 3 espacios o · )',
                                        ];
                                    ?>
                                    <div class="grid-stats" style="grid-template-columns:repeat(2, 1fr); gap:10px 14px;">
                                        <?php foreach ($labels as $k=>$lbl): ?>
                                            <div style="margin:0;">
                                                <label class="form-label" style="font-size:0.8rem; color:#cbd5e1; margin-bottom:5px; display:block;"><?= $lbl ?></label>
                                                <?php if (strpos($k, 'desc') !== false || $k === 'copyright' || $k === 'form_sub' || $k === 'brand_tagline' || $k === 'brand_chips'): ?>
                                                    <textarea name="<?= $s ?>[<?= $k ?>]" class="form-control" rows="2" maxlength="400" style="resize:vertical; min-height:42px; padding:9px 12px; line-height:1.45;"><?= htmlspecialchars($m[$k]) ?></textarea>
                                                <?php else: ?>
                                                    <input type="text" name="<?= $s ?>[<?= $k ?>]" class="form-control" value="<?= htmlspecialchars($m[$k]) ?>" maxlength="<?= ($k==='form_title'||$k==='brand_logo'||$k==='btn_submit'?80:180) ?>">
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- CARD 3: 403 IP -->
                                <div class="card card-header-red">
                                    <h4>🚫 Pantalla 403 · IP Bloqueada (ambos paneles) — ROJA</h4>
                                    <?php
                                        $s = 'ip403';
                                        $m = $_LT_CFG[$s];
                                        $labels = [
                                            'title' => 'Título grande (código 403)',
                                            'subtitle' => 'Subtítulo pequeño',
                                            'ip_label' => 'Label campo: Tu IP',
                                            'timeleft_label' => 'Label campo: Tiempo Restante',
                                            'until_label' => 'Label campo: Bloqueado Hasta',
                                            'note_label' => 'Label Nota del admin (ban manual)',
                                            'footer1' => 'Nombre App pie',
                                            'footer2' => 'Nombre Módulo pie',
                                            'footer_note' => 'Frase pie (antes de soporte)',
                                            'footer_word' => 'Palabra "soporte" (en negrita)',
                                        ];
                                    ?>
                                    <div class="grid-stats" style="grid-template-columns:repeat(2, 1fr); gap:10px 14px;">
                                        <?php foreach ($labels as $k=>$lbl): ?>
                                            <div style="margin:0;">
                                                <label class="form-label" style="font-size:0.8rem; color:#cbd5e1; margin-bottom:5px; display:block;"><?= $lbl ?></label>
                                                <input type="text" name="<?= $s ?>[<?= $k ?>]" class="form-control" value="<?= htmlspecialchars($m[$k]) ?>" maxlength="180">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- COLUMNA DERECHA: VISTA PREVIA MINIATURA (LIVE con JS) -->
                        <div style="position:sticky; top:90px; display:flex; flex-direction:column; gap:16px;">
                            <div class="card">
                                <h4 style="color:#22d3ee; margin-top:2px;">👁️ Vista Previa MINIATURA (en vivo)</h4>
                                <small class="text-muted">
                                    Los textos cambian a medida que escribes en los inputs. Para ver los <strong>cambios REALES</strong> en la página de login guarda y luego haz <code style="background:#111827; padding:2px 6px; border-radius:4px;">Ctrl+Shift+R</code> en index.php o superradio.php.
                                </small>
                            </div>

                            <div class="card">
                                <p class="minipreview-title">Login Cliente · AZUL</p>
                                <div class="miniprev-shell" id="miniC">
                                    <div class="miniprev-form">
                                        <div class="mf-title" data-bind="cliente[form_title]">Iniciar sesión</div>
                                        <div class="mf-sub" data-bind="cliente[form_sub]">Ingresa con tu usuario y contraseña</div>
                                        <div class="mf-box"></div>
                                        <div class="mf-box"></div>
                                        <div class="mf-btn" style="background:#2563eb;" data-bind="cliente[btn_submit]">INGRESAR</div>
                                    </div>
                                    <div class="miniprev-brand" style="background:#2f2bf6;">
                                        <div class="mb-logo" data-bind="cliente[brand_logo]">RADIOS CR</div>
                                        <div class="mb-sub" data-bind="cliente[brand_welcome]">Bienvenidos</div>
                                        <div class="mb-desc" data-bind="cliente[brand_desc]">Panel Admin</div>
                                    </div>
                                </div>

                                <p class="minipreview-title" style="margin-top:22px;">Login Superadmin · MORADO</p>
                                <div class="miniprev-shell" id="miniS">
                                    <div class="miniprev-form">
                                        <div class="mf-title" data-bind="superadmin[form_title]">Acceso Superadmin</div>
                                        <div class="mf-sub" data-bind="superadmin[form_sub]">Entrada al panel global</div>
                                        <div class="mf-box"></div>
                                        <div class="mf-box"></div>
                                        <div class="mf-btn" style="background:#7c3aed;" data-bind="superadmin[btn_submit]">ENTRAR AL PANEL</div>
                                    </div>
                                    <div class="miniprev-brand" style="background:#7c3aed;">
                                        <div class="mb-logo" data-bind="superadmin[brand_logo]">SUPERRADIO</div>
                                        <div class="mb-sub" data-bind="superadmin[brand_tagline]">Panel Global</div>
                                        <div class="mb-desc" data-bind="superadmin[brand_desc]">Gestiona emisoras y clientes</div>
                                    </div>
                                </div>

                                <p class="minipreview-title" style="margin-top:22px;">Pantalla 403 IP</p>
                                <div id="mini403" style="background:#020617; border:1px solid #334155; border-radius:10px; padding:14px; color:#fff; text-align:center;">
                                    <div style="width:34px; height:34px; border-radius:50%; background:#7f1d1d; display:flex; align-items:center; justify-content:center; margin:0 auto 6px; font-size:1.1rem;">🚫</div>
                                    <div style="font-weight:800; color:#fecaca; font-size:.74rem; margin-bottom:3px;" data-bind="ip403[title]">Dirección IP Bloqueada</div>
                                    <div style="font-size:.6rem; color:#94a3b8; margin-bottom:10px;" data-bind="ip403[subtitle]">Acceso denegado temporalmente</div>
                                    <div style="background:#111827; border:1px solid #1f2937; border-radius:6px; padding:6px; color:#cbd5e1; font-size:.56rem; line-height:1.4;">
                                        Texto bloqueo dinámico... (IP, tiempo, fecha)
                                    </div>
                                    <div style="margin-top:10px; color:#64748b; font-size:.56rem;" data-bind="ip403[footer1]">SuperRadio</div>
                                </div>
                            </div>

                            <div class="card">
                                <h4>⚠️ Buenas prácticas</h4>
                                <small class="text-muted" style="line-height:1.7;">
                                    • Campos <code>URL</code> deben empezar por https:// (ej: WhatsApp).<br>
                                    • Copyright: puedes usar el literal <code>{YEAR}</code> y será sustituido automáticamente por el año actual (2026).<br>
                                    • Los textos se guardan sin HTML; si necesitas emojis usa el teclado emojis (Win+. en Windows, Cmd+Ctrl+Espacio en Mac).<br>
                                    • La pantalla 403 se usa <strong>TANTO para index.php como superradio.php</strong>. El sistema muestra el baneo IGUAL desde ambas URL, así el atacante no puede distinguirlas.
                                </small>
                            </div>
                        </div>
                    </div>

                    <script>
                    // 👉 Vista previa en vivo miniatura: lee inputs[name=seccion[clave]] y actualiza [data-bind]
                    (function(){
                        const bindMap = {};
                        document.querySelectorAll('[data-bind]').forEach(el => {
                            const k = el.getAttribute('data-bind');
                            (bindMap[k] = bindMap[k] || []).push(el);
                        });
                        function setVal(sec, key, val) {
                            const arr = bindMap[sec+'['+key+']'] || [];
                            arr.forEach(el => el.textContent = val);
                        }
                        document.querySelectorAll('#frmLoginTexts input, #frmLoginTexts textarea').forEach(inp => {
                            const m = inp.name.match(/^(\w+)\[(\w+)\]$/);
                            if (!m) return;
                            inp.addEventListener('input', () => setVal(m[1], m[2], inp.value || ''));
                            // Set inicial
                            setVal(m[1], m[2], inp.value || '');
                        });
                    })();
                    </script>

                <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>
</div>

<script>
function toggleForm(formId, btnEl, txtShow, txtHide) {
    const form = document.getElementById(formId);
    if (!form) return;
    const isHidden = form.style.display === 'none' || form.style.display === '';
    form.style.display = isHidden ? 'block' : 'none';

    const defaultShow = '➕ Mostrar formulario';
    const defaultHide = '➖ Ocultar formulario';
    const labelShow = (typeof txtShow === 'string' && txtShow.length) ? txtShow : defaultShow;
    const labelHide = (typeof txtHide === 'string' && txtHide.length) ? txtHide : defaultHide;

    if (btnEl) {
        const target = btnEl.querySelector('.txt-btn') ? btnEl.querySelector('.txt-btn') : btnEl;
        target.innerText = isHidden ? labelHide : labelShow;
    }

    if (isHidden) {
        form.style.transition = 'border-color 0.2s ease';
        form.style.borderColor = '#10b981';
        setTimeout(() => { form.style.borderColor = '#1e293b'; }, 1500);
    }
}
async function updateAdminStats() {
    try {
        const res = await fetch('server_stats.php');
        if (!res.ok) return;
        const data = await res.json();

        // Chips del topbar
        const elCpu = document.getElementById('stat-cpu');
        if (elCpu) elCpu.innerText = (data.cpu ?? 0) + '%';
        const elLis = document.getElementById('stat-listeners');
        if (elLis) elLis.innerText = (data.listeners ?? 0);

        // --- Tarjetas VPS grandes ---
        // CPU
        const cpu = Math.max(0, Math.min(100, Number(data.cpu ?? 0)));
        const cpuVal = document.getElementById('vps-cpu-val');
        const cpuBar = document.getElementById('vps-cpu-bar');
        if (cpuVal) cpuVal.innerText = cpu + '%';
        if (cpuBar) cpuBar.style.width = cpu + '%';

        // RAM
        const ramUsed    = Number(data.ram_used ?? 0);
        const ramTotal   = Number(data.ram_total ?? 0);
        const ramPercent = ramTotal > 0 ? Math.round((ramUsed / ramTotal) * 100) : 0;
        const ramSafe = Math.max(0, Math.min(100, ramPercent));
        const ramVal = document.getElementById('vps-ram-val');
        const ramBar = document.getElementById('vps-ram-bar');
        if (ramVal) ramVal.innerText = ramUsed.toLocaleString() + ' / ' + ramTotal.toLocaleString() + ' MB (' + ramSafe + '%)';
        if (ramBar) ramBar.style.width = ramSafe + '%';

        // Tráfico
        const rx = Number(data.net_rx ?? 0);
        const tx = Number(data.net_tx ?? 0);
        const rxEl = document.getElementById('vps-rx-val');
        const txEl = document.getElementById('vps-tx-val');
        if (rxEl) rxEl.innerText = '↓ ' + rx.toFixed(2) + ' MB';
        if (txEl) txEl.innerText = '↑ ' + tx.toFixed(2) + ' MB';
        // Barra "relativa" al mayor valor de ambos para que se vea movimiento
        const netSum = rx + tx;
        const netBar = document.getElementById('vps-net-bar');
        if (netBar) {
            let pctNet = 0;
            if (netSum > 0) {
                // Se normaliza contra 2GB total de tráfico para que haya barra visible
                pctNet = Math.max(5, Math.min(100, Math.round((netSum / 2048) * 100)));
            }
            netBar.style.width = pctNet + '%';
        }

        // Oyentes
        const listeners = Number(data.listeners ?? 0);
        const lisVal = document.getElementById('vps-listeners-val');
        const lisBar = document.getElementById('vps-listeners-bar');
        if (lisVal) lisVal.innerText = listeners.toLocaleString() + ' activos';
        if (lisBar) {
            // Normalizamos contra 500 oyentes = 100%
            const pct = Math.max(0, Math.min(100, Math.round((listeners / 500) * 100)));
            lisBar.style.width = pct + '%';
        }
    } catch (e) {}
}
setInterval(updateAdminStats, 5000);
updateAdminStats();
</script>

<!-- Drawer móvil: hamburguesa + overlay -->
<script>
(function () {
    'use strict';
    var toggle = document.getElementById('adminNavToggle');
    var side = document.querySelector('.admin-sidebar');
    var scrim = document.getElementById('adminScrim');
    var mq = window.matchMedia('(max-width: 992px)');
    function setOpen(open) {
        if (!side) return;
        side.classList.toggle('open', open);
        if (scrim) scrim.classList.toggle('show', open);
        if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.style.overflow = open ? 'hidden' : '';
    }
    if (toggle) toggle.addEventListener('click', function () {
        setOpen(!side.classList.contains('open'));
    });
    if (scrim) scrim.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setOpen(false);
    });
    mq.addEventListener('change', function (ev) {
        if (!ev.matches) setOpen(false);
    });
})();
</script>
</body>
</html>
