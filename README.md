# SuperRadio

Panel autohospedado para automatizar emisoras de radio: **AutoDJ**, cabina de
**DJ en vivo** y **página pública** por emisora. PHP + Icecast + Liquidsoap,
sin framework ni base de datos externa (los datos viven en `database.json`).

- Radio multiusuario: superadmin gestiona emisoras y clientes; cada cliente/DJ
  tiene su cabina (`panel.php`).
- AutoDJ con playlists, programación por horas, cola de anuncios/pauta y voz de
  hora; o paso a **modo en vivo** cuando un DJ se conecta como fuente.
- Página pública reproducible y compartible por cada emisora (`radio_page.php`),
  con música, portada y configurador desde el panel.
- Login único en la raíz (`/`): clientes → su cabina; superadmin → panel global
  (`superradio.php`). La primera visita crea el superadmin (estilo AzureCast).
- Correo SMTP configurable desde el panel (recordatorios de pago, avisos) y
  datos del negocio en "Mi Cuenta".

## Requisitos

- VPS con **Ubuntu 22.04** (otros Ubuntu/Debian recientes, bajo tu
  responsabilidad) y acceso root/sudo.
- Dominio o subdominio apuntando al VPS (para HTTPS automático).
- Puertos abiertos en el firewall: **80, 443, 8000** y el rango DJ **8005+**.

## Instalar en un VPS

El instalador prepara todo (nginx, PHP-FPM, Icecast, Liquidsoap, ffmpeg,
certbot) y despliega el código en **`/var/www/radiopanel`**.

Opción A — desde la Release (paquete listo):

```bash
wget https://github.com/rowrigo/SuperRadio/releases/download/v1.0/superradio-package-20260904.tar.gz
tar -xzf superradio-package-20260904.tar.gz
sudo ./pkg/install.sh --domain=radio.tudominio.com --email=tu@correo.com
```

Opción B — desde el repositorio:

```bash
git clone https://github.com/rowrigo/SuperRadio.git /root/superradio-src
cd /root/superradio-src
sudo ./pkg/install.sh --domain=radio.tudominio.com --email=tu@correo.com
```

> Clona/fuente en una carpeta aparte (p. ej. `/root/superradio-src`): el
> instalador copia el código a `/var/www/radiopanel`. No lo ejecutes desde
> dentro de `/var/www/radiopanel`.

Después de instalar:

1. Abre `https://radio.tudominio.com/` — la primera visita muestra el alta
   inicial: crea tu **superadmin** (usuario + contraseña).
2. Desde el panel crea una emisora (mount) y sube música (Musicateca).
3. El stream queda en `https://radio.tudominio.com/<mount>`.

Flags útiles del instalador: `--no-ssl` (probar antes del DNS),
`--admin-user=... --admin-pass=...` (pre-crear el superadmin),
`--php-version=8.1`, `--no-restart`.

## Documentación

- `INSTALAR-VPS.md` — guía completa: requisitos, generación de paquete,
  instalación paso a paso, primer acceso, solución de problemas.
- `pkg/README.md` — cómo generar el paquete instalable
  (`bash pkg/make_package.sh <fecha>`) y publicar actualizaciones.

## Actualizar el código

En un VPS instalado, actualiza solo el código con `deploy_update.php`
(paquete `.sck`) o regenera el paquete con `pkg/make_package.sh`. Están
protegidos (no se sobrescriben): `database.json` y `config.local.php`.

## Notas de seguridad

- `config.local.php` (generado por el instalador) guarda `ENCRYPT_KEY` y
  `DEPLOY_TOKEN` por VPS; no lo compartas ni lo subas al repositorio.
- Si importas un `database.json` de otra instalación, usa la misma
  `ENCRYPT_KEY` original (las contraseñas de encoders van cifradas con
  AES-256-CBC).
- No subas al repositorio: `database.json`, `config.local.php`, logs ni
  archivos `.save` (el paquete ya los excluye).

## Licencia

[MIT](LICENSE) — Copyright (c) 2026 rowrigo
