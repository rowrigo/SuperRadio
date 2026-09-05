<?php
session_start();
require_once __DIR__ . '/config.php';
$_LT = login_texts_get();
$_LTC = $_LT['cliente'];
$_LT403 = $_LT['ip403'];

// 🌐 IP THROTTLE CHECK (ANTES DE CUALQUIER COSA): si IP bloqueada → 403, NI se muestra el login (igual DirectAdmin)
$_ip_chk = sec_ip_check_can_login();
if (!$_ip_chk['can']) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    $ip_ban_msg = $_ip_chk['message'];
    $ip_ban_time = $_ip_chk['time_left_txt'];
    $ip_ban_until = date('Y-m-d H:i:s', (int)$_ip_chk['blocked_until_ts']);
    $ip_addr = htmlspecialchars($_ip_chk['ip']);
    $note_lbl = htmlspecialchars($_LT403['note_label']);
    $title_403 = htmlspecialchars($_LT403['title']);
    $subtitle_403 = htmlspecialchars($_LT403['subtitle']);
    $lbl_ip = htmlspecialchars($_LT403['ip_label']);
    $lbl_tl = htmlspecialchars($_LT403['timeleft_label']);
    $lbl_un = htmlspecialchars($_LT403['until_label']);
    $f1 = htmlspecialchars($_LT403['footer1']);
    $f2 = htmlspecialchars($_LT403['footer2']);
    $fn = htmlspecialchars($_LT403['footer_note']);
    $fw = htmlspecialchars($_LT403['footer_word']);
    $manual_note = '';
    if (!empty($_ip_chk['manual_comment'])) {
        $manual_note = '<div style="margin-top:14px; padding:12px 14px; background:#7f1d1d; border-left:4px solid #dc2626; border-radius:6px; font-size:0.9rem;">📝 '.$note_lbl.': <strong>'.htmlspecialchars($_ip_chk['manual_comment']).'</strong></div>';
    }
    echo <<<HTMLIPBAN
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
HTMLIPBAN;
    exit;
}

if (!empty($_SESSION['superadmin_auth'])) {
    header("Location: superradio.php");
    exit;
}
if (!empty($_SESSION['cliente_auth'])) {
    header("Location: panel.php");
    exit;
}

$error = '';
$warn_attempts_left = -1;
$warn_blocked_msg = '';

if (isset($_POST['btn_login'])) {
    $user = trim($_POST['usuario']);
    $pass = $_POST['password'];

    $db = file_exists(DB_FILE) ? json_decode(file_get_contents(DB_FILE), true) : ["usuarios" => []];

    // Registro de fallo común (mensajes genéricos para no revelar roles): usuario Y IP
    $registrar_fallo = function ($sec_key) use (&$error, &$warn_attempts_left) {
        sec_ip_record_fail();
        $fail = sec_record_fail($sec_key);
        if ($fail['just_blocked']) {
            $hrs_txt = $fail['time_txt'];
            $error = "❌ Usuario o contraseña inválidos. Además: 🔒 Has superado el límite de intentos y tu usuario ha sido bloqueado {$hrs_txt}. Pasado este tiempo se desbloquea automaticamente, o pide al superadministrador que te libere.";
        } else {
            $chk2 = sec_check_can_login($sec_key);
            $left = (int)$chk2['attempts_left'];
            $warn_attempts_left = $left;
            if ($left <= 2) {
                $error = "❌ Usuario o contraseña inválidos. <strong>⚠️ Te quedan {$left} intento(s)</strong> antes de que el usuario sea bloqueado 1 hora.";
            } else {
                $error = "❌ Usuario o contraseña inválidos.";
            }
        }
    };

    // Resolución de rol: cliente primero (si existe en 'usuarios'); superadmin solo si
    // el nombre coincide con database.json.superadmin y NO es un cliente.
    $es_cliente   = isset($db['usuarios'][$user]);
    $sa_user      = $db['superadmin']['usuario'] ?? '';
    $sa_configured = ($sa_user !== '' && !empty($db['superadmin']['password_hash'] ?? ''));
    $es_superadmin = (!$es_cliente && $sa_configured && $user === $sa_user);

    // 1) SEC CHECK: usuario en blacklist bloqueado? (store según rol: 'u:' cliente, 's:' superadmin)
    $sec_key = $es_superadmin ? 's:' . $user : 'u:' . $user;
    $chk = sec_check_can_login($sec_key);
    if (!$chk['can']) {
        $error = $chk['message'];
        // IP también suma fallo (aunque usuario ya estaba bloqueado de antes)
        sec_ip_record_fail();
    } else {
        if ($es_cliente && password_verify($pass, $db['usuarios'][$user]['password_hash'] ?? '')) {
            // Migración automática: si el usuario tiene radio_id pero no radio_ids, crear radio_ids a partir de radio_id
            $needs_save = false;
            if (empty($db['usuarios'][$user]['radio_ids']) && !empty($db['usuarios'][$user]['radio_id'])) {
                $db['usuarios'][$user]['radio_ids'] = [$db['usuarios'][$user]['radio_id']];
                $needs_save = true;
            }
            // Si no tiene ninguno, usar primera radio disponible
            if (empty($db['usuarios'][$user]['radio_id']) && empty($db['usuarios'][$user]['radio_ids']) && !empty($db['radios'])) {
                $first_key = array_key_first($db['radios']);
                $db['usuarios'][$user]['radio_id'] = $first_key;
                $db['usuarios'][$user]['radio_ids'] = [$first_key];
                $needs_save = true;
            }
            if ($needs_save) {
                file_put_contents(DB_FILE, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            // Asegurar que haya un radio_id principal en sesión
            $principal = $db['usuarios'][$user]['radio_ids'][0] ?? $db['usuarios'][$user]['radio_id'] ?? '';

            // LIMPIAR THROTTLE al acertar login
            sec_clear_throttle($sec_key);

            $_SESSION['cliente_auth'] = true;
            $_SESSION['cliente_user'] = $user;
            $_SESSION['cliente_nombre'] = $db['usuarios'][$user]['nombre_completo'] ?? $db['usuarios'][$user]['nombre'] ?? $user;
            $_SESSION['radio_id'] = $principal;
            header("Location: panel.php");
            exit;
        } elseif ($es_superadmin && password_verify($pass, $db['superadmin']['password_hash'] ?? '')) {
            // ---------- RUTA SUPERADMIN: mismo login → panel global ----------
            sec_clear_throttle($sec_key);
            $_SESSION['superadmin_auth'] = true;
            header("Location: superradio.php");
            exit;
        } else {
            // FAIL -> contabilizar en el store del rol intentado Y en IP
            $registrar_fallo($sec_key);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SuperRadio · Iniciar sesión</title>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            min-height: 100vh;
            background: #1f2937;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            color: #111827;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        body { padding: 20px; }

        .login-shell {
            width: 100%;
            max-width: 920px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: #ffffff;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.45);
            min-height: 520px;
        }

        /* ========== LADO IZQUIERDO: FORMULARIO (blanco, exactamente mockup) ========== */
        .login-form-side {
            padding: 56px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .login-form-side h1 {
            margin: 0 0 8px 0;
            font-size: 1.85rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: #111827;
        }
        .login-form-side .subtitle {
            margin: 0 0 32px 0;
            color: #6b7280;
            font-size: 0.98rem;
        }
        .field { margin-bottom: 20px; display: flex; flex-direction: column; }
        .field label {
            font-size: 0.92rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 8px;
        }
        .field .input-wrap {
            display: flex;
            align-items: stretch;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            overflow: hidden;
            background: #fff;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .field .input-wrap:focus-within {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .field input[type=text], .field input[type=password] {
            flex: 1;
            border: 0;
            outline: 0;
            padding: 11px 14px;
            font-size: 0.95rem;
            color: #111827;
            background: transparent;
            font-family: inherit;
        }
        .field .toggle-pwd {
            background: #f9fafb;
            border: 0;
            border-left: 1px solid #e5e7eb;
            padding: 0 18px;
            font-size: 0.9rem;
            color: #374151;
            font-weight: 600;
            cursor: pointer;
        }
        .field .toggle-pwd:hover { background: #f3f4f6; }

        .btn-primary-login {
            margin-top: 8px;
            background: #2563eb;
            border: none;
            border-radius: 8px;
            padding: 13px 16px;
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            cursor: pointer;
            transition: background 0.15s, transform 0.05s;
        }
        .btn-primary-login:hover { background: #1d4ed8; }
        .btn-primary-login:active { transform: translateY(1px); }

        .alert-box {
            border-radius: 8px;
            padding: 11px 14px;
            margin-bottom: 22px;
            font-size: 0.9rem;
            line-height: 1.35;
        }
        .alert-box.danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert-box.warn   { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

        .footer-copyright {
            margin-top: 34px;
            color: #6b7280;
            font-size: 0.82rem;
            line-height: 1.55;
        }

        /* ========== LADO DERECHO: BRANDING (azul oscuro mockup) ========== */
        .login-brand-side {
            background: #2f2bf6;
            color: #ffffff;
            padding: 56px 48px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 26px;
            position: relative;
            overflow: hidden;
        }
        .login-brand-side::before {
            content: "";
            position: absolute;
            inset: -20% -20% auto auto;
            width: 420px; height: 420px;
            background: radial-gradient(circle, rgba(255,255,255,0.16), transparent 60%);
            border-radius: 50%;
            pointer-events: none;
        }
        .login-brand-side::after {
            content: "";
            position: absolute;
            inset: auto auto -30% -10%;
            width: 360px; height: 360px;
            background: radial-gradient(circle, rgba(255,255,255,0.08), transparent 60%);
            border-radius: 50%;
            pointer-events: none;
        }
        .login-brand-side > * { position: relative; z-index: 1; }

        .login-brand-logo {
            font-size: 2.6rem;
            font-weight: 900;
            letter-spacing: -0.01em;
            line-height: 1;
        }
        .login-brand-welcome {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
        }
        .login-brand-desc {
            margin: 0;
            color: rgba(255,255,255,0.88);
            font-size: 0.98rem;
            line-height: 1.55;
            max-width: 320px;
        }
        .btn-outline-brand {
            margin-top: 8px;
            background: transparent;
            border: 1.5px solid rgba(255,255,255,0.65);
            border-radius: 8px;
            padding: 10px 28px;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s, border-color 0.15s;
        }
        .btn-outline-brand:hover {
            background: rgba(255,255,255,0.1);
            border-color: #ffffff;
        }

        /* ========== RESPONSIVE <768px = 1 SOLA COLUMNA (arriba AZUL branding, abajo el FORM) ========== */
        @media (max-width: 768px) {
            .login-shell {
                grid-template-columns: 1fr;
                min-height: auto;
                border-radius: 16px;
            }
            .login-brand-side { padding: 44px 28px 40px; min-height: 240px; order: -1; gap: 18px; }
            .login-form-side { padding: 36px 26px 40px; }
            .login-form-side h1 { font-size: 1.65rem; }
            .login-brand-logo { font-size: 2.25rem; }
        }
    </style>
</head>
<body>
    <div class="login-shell">
        <!-- ========================= LADO IZQUIERDO - FORMULARIO ========================= -->
        <div class="login-form-side">
            <h1><?= htmlspecialchars($_LTC['form_title']) ?></h1>
            <p class="subtitle"><?= htmlspecialchars($_LTC['form_sub']) ?></p>

            <?php if ($error): ?>
                <div class="alert-box <?= ($warn_attempts_left >= 0 && $warn_attempts_left <= 2) ? 'warn' : 'danger' ?>">
                    <?= $error ?>
                </div>
            <?php endif; ?>

            <?php
                $lbl_u = htmlspecialchars($_LTC['lbl_user']);
                $lbl_p = htmlspecialchars($_LTC['lbl_pwd']);
                $btn_on = json_encode($_LTC['btn_toggle_pwd_on']);
                $btn_off = json_encode($_LTC['btn_toggle_pwd_off']);
                $ph_u = htmlspecialchars('Tu '.$_LTC['lbl_user']);
                $ph_p = htmlspecialchars('Tu '.$_LTC['lbl_pwd']);
                $btn_sub = htmlspecialchars($_LTC['btn_submit']);
                $brand_logo = htmlspecialchars($_LTC['brand_logo']);
                $brand_welc = htmlspecialchars($_LTC['brand_welcome']);
                $brand_desc = htmlspecialchars($_LTC['brand_desc']);
                $btnc_txt = htmlspecialchars($_LTC['btn_contacto_txt']);
                $btnc_url = htmlspecialchars($_LTC['btn_contacto_url']);
                $copy = $_LTC['copyright'];
            ?>
            <form method="POST" autocomplete="on" novalidate>
                <div class="field">
                    <label for="usuario"><?= $lbl_u ?></label>
                    <div class="input-wrap">
                        <input id="usuario" type="text" name="usuario" value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>" required autocomplete="username" placeholder="<?= $ph_u ?>">
                    </div>
                </div>
                <div class="field">
                    <label for="password"><?= $lbl_p ?></label>
                    <div class="input-wrap">
                        <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="<?= $ph_p ?>">
                        <button type="button" class="toggle-pwd" id="togPwdC"><?= $btn_on ?></button>
                        <script>
                        (function(){
                          var i=document.getElementById('password');
                          var b=document.getElementById('togPwdC');
                          var on=<?= $btn_on ?>; var off=<?= $btn_off ?>;
                          b.addEventListener('click', function(){
                            i.type = (i.type === 'password') ? 'text' : 'password';
                            b.textContent = (i.type === 'password') ? on : off;
                          });
                        })();
                        </script>
                    </div>
                </div>

                <button type="submit" name="btn_login" class="btn-primary-login"><?= $btn_sub ?></button>
            </form>

            <div class="footer-copyright">
                <?= str_replace('{YEAR}', (string)date('Y'), htmlspecialchars($copy)) ?>
            </div>
        </div>

        <!-- ========================= LADO DERECHO - BRANDING AZUL ========================= -->
        <div class="login-brand-side">
            <div class="login-brand-logo"><?= $brand_logo ?></div>
            <p class="login-brand-welcome"><?= $brand_welc ?></p>
            <p class="login-brand-desc"><?= $brand_desc ?></p>
            <a href="<?= $btnc_url ?>" target="_blank" rel="noopener" class="btn-outline-brand"><?= $btnc_txt ?></a>
        </div>
    </div>
</body>
</html>
