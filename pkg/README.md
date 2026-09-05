# SuperRadio / radiopanel — Paquete de instalación para otro VPS

Este paquete incluye el panel completo (PHP + JS) y un instalador que prepara
**Ubuntu 22.04** con todas las dependencias (nginx, PHP 8.1, Icecast2,
Liquidsoap, ffprobe, certbot) y configura el **dominio/subdominio** que quieras
usar en ese VPS.

## 1) Generar el paquete (en este VPS / repo)

```bash
bash pkg/make_package.sh 20260904      # → superradio-package-20260904.tar.gz
```

Sube el `.tar.gz` al VPS nuevo y extráelo:

```bash
tar -xzf superradio-package-20260904.tar.gz
```

## 2) Instalar en el VPS nuevo (Ubuntu 22.04)

```bash
# Primero apunta el DNS del dominio al VPS (A/AAAA) si quieres HTTPS automático.
sudo ./pkg/install.sh --domain=radio.midominio.com --email=tucorreo@dominio.com
```

No se pide contraseña durante la instalación: el **superadmin se crea en el
primer acceso web** al panel (pantalla "Primera instalación"). Si prefieres
dejarlo pre-creado, pasa `--admin-user=...` y `--admin-pass=...`.
El instalador:

1. Instala: nginx, php8.1 (cli/fpm/curl/mbstring/intl/opcache/xml), icecast2,
   liquidsoap, ffmpeg (ffprobe), utilidades (`ss`, `pkill`, …), certbot.
   - Si apt no da Liquidsoap 2.0.x, instala el `.deb` de **2.0.2** manualmente
     (el panel usa sintaxis de LS 2.0.2) y fíjalo con `apt-mark hold`.
2. Copia el código a `/var/www/radiopanel` (sin `database.json` ni
   `config.local.php`) y hace `php -l` de todo.
3. Genera secretos nuevos y escribe:
   - `config.local.php` → dominio (`STREAM_HOST`) + claves propias (no toca `config.php`).
   - sitio nginx `radiopanel` → sirve el panel + proxy de streams a Icecast (8000) + auth.php interno en 127.0.0.1:80.
   - `/etc/icecast2/icecast.xml` → hostname/credenciales nuevas.
4. Crea permisos/directorios (`www-data`, `/var/media/radios`) y un
   `database.json` limpio. Sin flags de admin queda **sin superadmin**
   (el alta ocurre en el primer acceso web); con `--admin-user` /
   `--admin-pass` lo deja pre-creado. Luego arranca los servicios.
5. Emite el certificado **Let's Encrypt** para tu dominio (`certbot --nginx --redirect`).

Flags útiles: `--no-ssl` (deja HTTP para probar antes del DNS),
`--no-restart`, `--php-version=8.1`, `--src=...`.

## 3) Después de instalar

1. **Primer acceso (crea tu superadmin):** abre `https://dominio/superradio.php`.
   Como no hay superadmin, verás la pantalla "Primera instalación": define el
   **usuario** y **contraseña** (mínimo 8 caracteres) del superadministrador.
   Al guardar entras al panel y el sistema queda activado (estilo AzureCast).
   ⚠️ Hazlo justo tras instalar: el primer visitante que complete el alta queda
   como superadmin.
2. **Login único:** desde entonces hay UNA sola página de login en
   `https://dominio/` (`index.php`). Entran ahí tanto los clientes/DJ (→ cabina
   `panel.php`) como el superadmin (→ panel global `superradio.php`); abrir
   `/superradio.php` sin sesión redirige a esa página.
3. Dentro del panel, **👤 Mi Cuenta** permite cambiar el usuario o la contraseña
   del superadmin cuando quieras (pide la contraseña actual).
4. Crea una radio (mount) → el instalador arranca Liquidsoap; el stream queda en
   `https://dominio/<mount>`.
5. Sube música desde el panel (Musicateca) y personaliza el player (Página Pública).
6. Firewall del VPS: abre 80, 443, 8000 y el rango de puertos DJ (8005+).

## 4) Actualizaciones futuras de código

El proyecto usa `deploy_update.php` (paquete `.sck`) para actualizar sólo el
código. `database.json` y `config.local.php` están protegidos (no se pisan).
El token de deploy es el `DEPLOY_TOKEN` de `config.local.php` de cada VPS.

## Notas / riesgos

- Si algún día **importas** una `database.json` vieja con radios, debes usar la
  misma `ENCRYPT_KEY` original (las contraseñas de encoders van cifradas con
  AES-256-CBC). Edítala en `config.local.php`.
- No subas al paquete: `/var/media`, `database.json`, `*.log`, `.git`,
  archivos `.save`/`__test*` (ya los excluye `make_package.sh`).
- El instalador no copia música ni radios: es instalación limpia a propósito.
