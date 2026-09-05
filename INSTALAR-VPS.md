# 📦 Instalar SuperRadio / radiopanel en un VPS nuevo (Ubuntu 22.04)

Guía paso a paso para dejar funcionando el panel en otro VPS con tu propio
**dominio o subdominio**. El instalador prepara todo: nginx, PHP 8.1, Icecast,
Liquidsoap, ffprobe, certbot (HTTPS), permisos y una instalación limpia.
El **superadmin NO viene predefinido**: el primer acceso al panel muestra un
alta inicial y quien la complete se convierte en superadministrador
(estilo AzureCast).

---

## 1. Requisitos

- VPS con **Ubuntu 22.04** (también sirve si tienes otro Ubuntu/Debian reciente, bajo tu responsabilidad).
- Acceso **root** o un usuario con **sudo**.
- Dominio o subdominio (opcional para HTTPS): si quieres certificado automático,
  el **DNS debe apuntar a la IP del VPS** antes de instalar.
- Puertos abiertos en el firewall del VPS: **80, 443, 8000** y el rango DJ **8005+**.

---

## 2. Generar el paquete (en el VPS/repo actual)

En la máquina donde tienes el proyecto:

```bash
cd /var/www/radiopanel
bash pkg/make_package.sh 20260904
```

Genera: `superradio-package-20260904.tar.gz`

> El paquete **no** incluye: música (`/var/media`), `database.json`,
> `config.local.php`, logs, `.git`, archivos `.save`/`__test*` ni secretos.

---

## 3. Subir y extraer en el VPS nuevo

Desde tu PC:

```bash
scp superradio-package-20260904.tar.gz usuario@IP_DEL_VPS:/tmp/
```

En el VPS:

```bash
ssh usuario@IP_DEL_VPS
cd /tmp
tar -xzf superradio-package-20260904.tar.gz
ls        # deberías ver: pkg/, index.php, superradio.php, views/, etc.
```

---

## 4. Instalar

```bash
# Opción A — HTTPS automático (recomendado; el DNS ya debe apuntar):
sudo ./pkg/install.sh --domain=radio.tudominio.com --email=tu@correo.com

# Opción B — probar primero sin HTTPS:
sudo ./pkg/install.sh --domain=radio.tudominio.com --no-ssl
```

Opciones útiles:

| Flag | Descripción |
|---|---|
| `--domain=…` | Dominio/subdominio del VPS (obligatorio) |
| `--email=…` | Correo para Let's Encrypt (default: `admin@dominio`) |
| `--admin-user=…` | Pre-crear superadmin (opcional; si se omite, el primer acceso web al panel crea el superadmin) |
| `--admin-pass=…` | Contraseña del superadmin pre-creado (con `--admin-user`; si se omite, se pide por teclado) |
| `--no-ssl` | Deja el sitio en HTTP (para probar antes del DNS) |
| `--no-restart` | No reinicia servicios al final |
| `--php-version=8.1` | Versión PHP (default 8.1) |

El instalador:

1. Instala dependencias: `nginx`, `php8.1-cli/fpm/curl/mbstring/intl/opcache/xml`,
   `icecast2`, `liquidsoap`, `ffmpeg` (ffprobe), utilidades (`ss`, `pkill`, …)
   y `certbot`.
2. Copia el código a `/var/www/radiopanel` (sin `database.json` ni `config.local.php`)
   y valida la sintaxis de todos los `.php`.
3. Genera secretos nuevos y escribe:
   - `config.local.php` → dominio + claves propias (no modifica `config.php`).
   - sitio nginx `radiopanel` → panel web + proxy de streams a Icecast (8000)
     + `auth.php` interno en `127.0.0.1:80`.
   - `/etc/icecast2/icecast.xml` → hostname y credenciales nuevas.
4. Crea permisos (`www-data`) y directorios de estado (`/var/media/radios`,
   `/data`).
5. Crea un `database.json` limpio. **Sin superadmin** (por defecto): el primer
   acceso web al panel mostrará el alta inicial. Solo si pasas
   `--admin-user` / `--admin-pass` lo deja pre-creado.
6. Arranca php-fpm, nginx e Icecast.
7. (Opción A) Emite el certificado **Let's Encrypt** con `certbot --nginx`.

---

## 5. Primer acceso: crear el Superadmin

1. Abre `https://radio.tudominio.com/superradio.php` (o `http://…` si usaste
   `--no-ssl`). Como la instalación no trae superadmin, verás la pantalla
   **"Primera instalación"**: escribe el **usuario** y la **contraseña**
   (mínimo 8 caracteres) del superadministrador.
2. Al guardar, entras directo al panel y el sistema queda activado. A partir de
   ese momento esa pantalla ya no aparece.

> ⚠️ Haz este alta inicial justo después de instalar: el primer visitante que
> complete el formulario queda como superadministrador (igual que AzureCast).

---

## 6. Login único (todos entran por la raíz)

Hay **una sola página de login**: `https://radio.tudominio.com/` (`index.php`).

- **Clientes/DJ** entran ahí con su usuario → cabina (`panel.php`).
- El **superadmin** entra también ahí con su usuario → panel global
  (`superradio.php`). Si alguien abre `/superradio.php` sin sesión, se le
  redirige automáticamente al login único (salvo que aún no exista superadmin:
  entonces muestra el alta inicial de la sección 5).

## 7. Cambiar usuario/contraseña del Superadmin

Dentro del panel admin (`superradio.php`), menú lateral **👤 Mi Cuenta**:

- Cambia el **usuario** (opcional) y la **contraseña** (mínimo 8 caracteres).
- Pide la **contraseña actual** para aplicar los cambios.
- La sesión activa no se cierra; el próximo login usa los datos nuevos.

El superadmin también puede resetear la contraseña de cada **cliente** desde
Emisoras/Clientes (editar cliente).

---

## 8. Verificar

1. Abre el login único: `https://radio.tudominio.com/` (o `http://…` si usaste
   `--no-ssl`) y entra con el superadmin que creaste.
3. Crea una radio (mount, p. ej. `prueba`) → Liquidsoap arranca solo.
4. El stream queda en `https://radio.tudominio.com/prueba`.
5. Sube música desde **Musicateca** y mira el player público
   (Página Pública → Personalizar).

Comprobaciones rápidas desde el VPS:

```bash
curl -I https://radio.tudominio.com/index.php        # 200
curl -s http://127.0.0.1:8000/status-json.xsl        # Icecast responde
systemctl status nginx php8.1-fpm icecast2           # activos
```

---

## 9. Solución de problemas

- **Liquidsoap no es 2.0.2**: apt de Ubuntu 22.04 puede dar otra versión.
  El panel usa sintaxis de LS 2.0.2. Instala el `.deb` de **2.0.2** manualmente y
  fíjalo: `apt-mark hold liquidsoap`. Si una radio no monta, revisa:
  `tail -f /var/media/radios/<mount>/liquidsoap.log`.
- **certbot falla**: casi siempre es que el **DNS todavía no apunta** al VPS.
  Repite luego: `sudo certbot --nginx -d radio.tudominio.com --redirect`.
- **Puertos ocupados**: el instalador avisa pero no bloquea. Asegúrate de tener
  libres 80/443/8000 (puede ser re-ejecución del propio panel).
- **Socket PHP**: detecta solo `/run/php/php8.1-fpm.sock` o `php-fpm.sock`;
  si usas otra versión de PHP pasa `--php-version=…`.

---

## 10. Actualizaciones futuras

Para actualizar solo el código se usa `deploy_update.php` (paquete `.sck`).
Están **protegidos** (no se sobrescriben): `database.json` y `config.local.php`.

El token de deploy de cada VPS es el `DEPLOY_TOKEN` dentro de su `config.local.php`.

---

## 11. Notas de seguridad

- Si algún día importas una `database.json` **vieja** (con radios), usa la misma
  `ENCRYPT_KEY` original en `config.local.php` (las contraseñas de encoders van
  cifradas con AES-256-CBC).
- Guarda `config.local.php` con permisos restringidos (el instalador lo deja en `0640`).
- No subas por FTP/SCP los secretos ni `database.json` del VPS de producción.
