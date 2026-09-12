# 🎙️ Instalar SuperRadio en un VPS nuevo — guía para principiantes

Esta guía te lleva **de cero a tu radio sonando en internet**. No hace falta que
sepas Linux: solo **copiar y pegar** los comandos en el orden en que aparecen.

Tiempo aproximado: **20–30 minutos** (más lo que tarde el DNS, que a veces hay
que esperar).

> ¿En qué VPS puedes hacerlo? En **cualquier VPS nuevo** con **Ubuntu 22.04**
> (recomendado) y al menos **2 GB de RAM** y **20 GB de disco**. El instalador
> prepara todo lo demás solo.
>
> ⚠️ Hazlo en un VPS **vacío o de pruebas**. El instalador instala programas de
> sistema y configura nginx e Icecast: no lo lances en un servidor que ya use
> esos servicios para otra cosa.

---

## 📖 Mini-diccionario (para que no te pierdas)

| Palabra | Qué significa |
|---|---|
| **VPS** | Un ordenador alquilado en internet, siempre encendido, donde vivirá tu radio. |
| **IP** | El "número de teléfono" de tu VPS. Te lo da tu proveedor (algo como `203.0.113.10`). |
| **SSH** | Cómo entras a tu VPS desde tu ordenador para darle órdenes. |
| **Dominio / subdominio** | El nombre bonito de tu web, ej. `radio.tudominio.com`. |
| **DNS** | La "agenda" que dice en internet a qué IP corresponde tu dominio. |
| **Icecast / Liquidsoap** | Los motores que emiten el audio (van incluidos, no los tocas). |
| **Mount** | El nombre corto de una emisora, ej. `prueba` → suena en `https://tudominio.com/prueba`. |
| **Superadmin** | Tu usuario de dueño del panel. Se crea en el primer acceso. |

---

## ✅ Antes de empezar, ten a mano

1. **Un VPS nuevo con Ubuntu 22.04** y su **dirección IP**.
2. **La contraseña de root** del VPS (te la dio tu proveedor por correo).
3. **Un dominio o subdominio** (opcional pero muy recomendado, porque permite
   HTTPS). Ejemplo: `radio.tudominio.com`.
   - Si **no** tienes dominio todavía, puedes seguir la guía igual y usar
     `--no-ssl` (el Paso 5 te lo explica). Accederás por `http://` en vez de
     `https://`.

---

## 🧭 Resumen del camino (lo que vas a hacer)

```
1. Conectarte al VPS por SSH          →  Paso 1
2. Apuntar tu dominio al VPS (DNS)    →  Paso 2
3. Abrir los puertos necesarios       →  Paso 3
4. Descargar el proyecto de GitHub    →  Paso 4
5. Ejecutar el instalador             →  Paso 5
6. Crear tu superadmin                →  Paso 6
7. Crear tu primera emisora           →  Paso 7
8. Subir música y comprobar que suena →  Paso 8
```

---

## Paso 1 — Conéctate a tu VPS (SSH)

Abre una **terminal** en tu ordenador:

- **Windows:** menú Inicio → escribe **PowerShell** → Enter.
- **Mac:** abre **Terminal** (Launchpad → Otros → Terminal).
- **Linux:** abre tu terminal.

Escribe esto, cambiando `TU_IP` por la IP de tu VPS, y pulsa Enter:

```bash
ssh root@TU_IP
```

- La **primera vez** te preguntará algo como
  `Are you sure you want to continue connecting (yes/no)?` → escribe **`yes`** y Enter.
- Luego pide la **contraseña de root** → pégala (⚠️ **no se ve nada mientras la
  escribes, es normal**) y Enter.

Si todo va bien, verás algo como:

```
root@mi-vps:~#
```

¡Ya estás dentro de tu VPS! 🎉 **Todos los comandos de los siguientes pasos se
escriben ahí dentro**, no en tu ordenador.

> 💡 Truco: si quieres, actualiza primero el sistema con
> `apt-get update && apt-get upgrade -y` (tarda un poco; es opcional).

---

## Paso 2 — Apunta tu dominio al VPS (DNS)

Si vas a usar HTTPS (recomendado), **haz esto ahora**, porque el certificado
necesita que el dominio ya apunte al VPS.

1. Entra en la web de **donde compraste tu dominio** (GoDaddy, Namecheap,
   Cloudflare, IONOS, …).
2. Busca la sección **DNS** / **Zona DNS** / **Registros DNS**.
3. Añade un registro **tipo `A`**:

   | Campo | Valor |
   |---|---|
   | Tipo | `A` |
   | Nombre / Host | `radio` (o el subdominio que quieras; si usas el dominio pelado, pon `@`) |
   | Valor / Destino | **la IP de tu VPS** |
   | TTL | el que venga por defecto (Auto/3600) |

4. **Guarda** y espera de **5 minutos a 1 hora** (a veces menos).

Comprueba desde el VPS que ya apunta bien (cambia el dominio por el tuyo):

```bash
ping -c 3 radio.tudominio.com
```

Debe responder desde **la IP de tu VPS**. Si no responde todavía, espera un
poco y repite. *Si no tienes dominio, salta al Paso 3.*

---

## Paso 3 — Abre los puertos (firewall)

Tu VPS tiene que dejar pasar estas conexiones. Si usas **ufw** (el cortafuegos
típico de Ubuntu), ejecuta dentro del VPS:

```bash
ufw allow 22/tcp       # SSH (¡no lo cierres o te quedas fuera!)
ufw allow 80/tcp       # web (http)
ufw allow 443/tcp      # web segura (https)
ufw allow 8000/tcp     # Icecast (los oyentes escuchan aquí)
ufw allow 8005:8105/tcp   # DJ en vivo: cada emisora usa un puerto desde 8005
ufw --force enable
```

> El rango `8005:8105` da para unas **100 emisoras**. Si vas a crear más,
> amplíalo (p. ej. `8005:8505`).

> ⚠️ Además, muchos proveedores (Hetzner, OVH, DigitalOcean, etc.) tienen su
> propio **firewall en el panel web**. Si al final no carga la página, revisa
> ahí que **80, 443 y 8000** estén permitidos.

---

## Paso 4 — Descarga el proyecto desde GitHub

Instala Git (una herramienta para descargar el proyecto) y clona el repositorio:

```bash
apt-get update
apt-get install -y git
git clone https://github.com/rowrigo/SuperRadio.git /root/superradio-src
```

Entra en la carpeta descargada:

```bash
cd /root/superradio-src
ls
```

Deberías ver ficheros como `index.php`, `superradio.php`, `views/` y la carpeta
`pkg/` (que contiene el instalador).

> 💡 **¿Por qué en `/root/superradio-src` y no en otro sitio?** Porque el
> instalador **copia el código** a `/var/www/radiopanel`. Descárgalo aparte para
> no pisarte a ti mismo.

---

## Paso 5 — Ejecuta el instalador (aquí se hace casi todo solo)

Sigue dentro de `/root/superradio-src` (si te perdiste: `cd /root/superradio-src`).

**Opción A — con HTTPS automático** (si ya hiciste el Paso 2 y el DNS responde):

```bash
./pkg/install.sh --domain=radio.tudominio.com --email=tu@correo.com
```

**Opción B — probar primero sin HTTPS** (si aún no tienes dominio o el DNS no
apunta): añade `--no-ssl`.

```bash
./pkg/install.sh --domain=radio.tudominio.com --no-ssl
```

Cambia `radio.tudominio.com` por tu dominio y `tu@correo.com` por tu correo real.

> 👤 Estás conectado como **root**, así que no hace falta `sudo`. Si usas un
> usuario normal, ejecuta `sudo ./pkg/install.sh --domain=...`.

### Qué va a hacer el instalador (no tienes que hacer nada)

1. **Instala los programas**: nginx, PHP 8.1, Icecast 2, Liquidsoap, ffmpeg,
   certbot y utilidades. *(Tarda unos minutos y llena la pantalla de texto:
   es normal.)*
2. **Copia el código** a `/var/www/radiopanel` (sin tus datos ni secretos).
3. **Genera secretos nuevos** y escribe `config.local.php` para este VPS.
4. **Configura nginx**, Icecast y los permisos.
5. Crea un `database.json` **limpio** (sin radios ni usuarios).
6. Instala el **AutoDJ 24/7** como servicio del sistema con watchdog: si una
   emisora se cae o el VPS se reinicia, **se vuelve a levantar sola**.
7. Arranca php-fpm, nginx e Icecast.
8. Emite el **certificado HTTPS** (si no usaste `--no-ssl`).

Al terminar verás algo así:

```
[+] Instalación completada.
  Panel admin : https://radio.tudominio.com/superradio.php
  PRIMER ACCESO: abre el panel y crea tu SUPERADMIN (usuario + contraseña).
  Stream      : https://radio.tudominio.com/<mount>
```

Otras opciones útiles (por si las necesitas):

| Opción | Para qué sirve |
|---|---|
| `--admin-user=NOMBRE --admin-pass=CLAVE` | Dejar el superadmin creado ya (si no, se crea en el Paso 6). |
| `--no-ssl` | Instalar sin HTTPS (para probar antes de que el DNS apunte). |
| `--no-restart` | No reiniciar los servicios al final. |

---

## Paso 6 — Crea tu superadmin (¡hazlo ya!)

La instalación **NO trae usuario**. Eso es a propósito: el **primer visitante**
que rellene el alta se convierte en **superadministrador**. Así que hazlo tú
**ahora mismo**.

1. En tu navegador, abre:

   ```
   https://radio.tudominio.com/superradio.php
   ```

   *(si usaste `--no-ssl`, usa `http://`)*
2. Verás la pantalla **"Primera instalación"**.
3. Escribe el **usuario** y una **contraseña** (mínimo **8 caracteres**) y guarda.
4. Entrarás directo al panel y ya quedará activado.

> ⚠️ **Importante:** si no lo haces tú, lo hará quien entre primero en esa URL.
> No tardes en crearlo.

**A partir de ahora hay un único login en la raíz:** `https://radio.tudominio.com/`.
Entran ahí tanto tú (superadmin → panel global) como tus clientes/DJ (→ su cabina).

---

## Paso 7 — Crea tu primera emisora

1. Dentro del panel, busca la sección de **Emisoras / Clientes** y **crea una**.
2. Dale un **mount** (el nombre corto que irá en la URL). Por ejemplo: `prueba`.
3. El panel arranca sola la emisora. Su audio quedará en:

   ```
   https://radio.tudominio.com/prueba
   ```

---

## Paso 8 — Sube música y comprueba que suena

1. En el panel, abre la **Musicateca** de tu emisora y **sube archivos de audio**
   (mp3, etc.).
2. Crea/edita una **playlist** y añade la música (o usa el AutoDJ por carpetas).
3. Abre `https://radio.tudominio.com/prueba` en el navegador o en VLC → deberías
   escuchar tu radio. 🎵
4. Opcional: personaliza el reproductor público desde **Página Pública →
   Personalizar**.

---

## 🔍 ¿Cómo sé que todo está bien? (comprobaciones)

Pega estos comandos dentro del VPS:

```bash
# El panel responde
curl -I https://radio.tudominio.com/index.php          # espera: 200 (o 302)

# Icecast está vivo
curl -s http://127.0.0.1:8000/status-json.xsl | head

# Servicios principales activos
systemctl status nginx php8.1-fpm icecast2

# Tu emisora (cambia <mount> por el nombre que le pusiste)
systemctl status radiopanel-autodj@<mount>

# El vigilante que re-arranca las emisoras
systemctl status radiopanel-autodj-watchdog.timer
```

---

## 🛠️ Problemas frecuentes (y su solución)

**El certificado HTTPS falló (certbot)**
Casi siempre es que el **DNS todavía no apunta** al VPS (Paso 2). Espera y repite:

```bash
certbot --nginx -d radio.tudominio.com --redirect
```

**La página no carga**
- Revisa nginx: `systemctl status nginx` y `nginx -t`.
- Revisa el firewall (Paso 3), incluido el del panel de tu proveedor.

**Una emisora no suena**
Mira **su servicio**, no un proceso suelto:

```bash
systemctl status radiopanel-autodj@<mount>
journalctl -u radiopanel-autodj@<mount> -n 50
tail -f /var/media/radios/<mount>/liquidsoap.log
```

El watchdog la re-arranca solo en ~2 minutos; tras un reinicio del VPS tarda ~90 s.

**Dice que Liquidsoap no es la versión esperada**
El panel está pensado para **Liquidsoap 2.0.2**. Si `apt` instaló otra versión y
algo no monta, instala el `.deb` de 2.0.2 a mano y fíjalo con
`apt-mark hold liquidsoap`.

**«Permission denied» al ejecutar el instalador**
Asegúrate de estar en la carpeta del proyecto (`cd /root/superradio-src`) y de
escribir `./pkg/install.sh`. Si hace falta: `chmod +x pkg/install.sh`.

---

## 🔄 Actualizar el código más adelante

⚠️ **NO vuelvas a ejecutar `install.sh` en un VPS ya instalado.** Regeneraría
`config.local.php`, cambiaría la clave de cifrado y **dejaría ilegibles las
contraseñas de tus encoders**.

Para actualizar solo el código hay dos vías:

- **Desde el panel**, con `deploy_update.php` (paquete `.sck`) y el
  `DEPLOY_TOKEN` que está en `config.local.php`. Es lo pensado para producción.
- **A mano**, copiando solo el código nuevo encima de `/var/www/radiopanel`
  **sin tocar** `config.local.php` ni `database.json`.

Están **protegidos** y nunca se sobrescriben: `database.json` y `config.local.php`.

---

## 🔒 Seguridad (lee esto una vez)

- `config.local.php` guarda las claves de **este** VPS (`ENCRYPT_KEY`,
  `DEPLOY_TOKEN`). **No lo compartas, no lo subas a GitHub, no lo pases por FTP.**
- Si algún día **importas un `database.json` de otra instalación**, debes usar la
  **misma `ENCRYPT_KEY`** original en `config.local.php`; si no, las contraseñas
  de los encoders no se podrán descifrar.
- Nunca subas al repositorio: `database.json`, `config.local.php`, logs ni
  archivos `.save`.

---

## 📚 Para seguir leyendo

- `INSTALAR-VPS.md` — guía **técnica** completa (todas las opciones y detalles).
- `pkg/README.md` — cómo generar el **paquete** instalable y publicar Releases.
- `README.md` — qué es SuperRadio y qué hace.
