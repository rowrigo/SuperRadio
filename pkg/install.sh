#!/usr/bin/env bash
# ============================================================
# install.sh — Instalador de radiopanel (SuperRadio) en Ubuntu 22.04
#
# Uso:
#   sudo ./pkg/install.sh --domain=radio.midominio.com --email=tu@correo.com
#
# Opciones:
#   --domain=DOMINIO     Dominio/subdominio que usará este VPS (obligatorio)
#   --email=CORREO       Email para Let's Encrypt (si se omite: admin@DOMINIO)
#   --admin-user=USER    Pre-crear superadmin (OPCIONAL: si lo omites, el primer
#                        acceso web al panel crea el superadmin — estilo AzureCast)
#   --admin-pass=PASS    Contraseña del superadmin pre-creado (con --admin-user;
#                        si omites la pass, se pide por teclado)
#   --php-version=8.1    Versión PHP (default 8.1)
#   --src=RUTA           Raíz del código (default: carpeta padre de pkg/)
#   --no-ssl             No emitir certificado (deja el sitio en HTTP)
#   --no-restart         No reiniciar servicios al final
#   --help
# ============================================================
set -Eeuo pipefail

DOMAIN=""; EMAIL=""; ADMIN_USER=""; ADMIN_PASS=""; PHP_VER="8.1"; SRC=""
NO_SSL=0; NO_RESTART=0; APP_DIR="/var/www/radiopanel"

usage() { sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; }

while [ $# -gt 0 ]; do
  case "$1" in
    --domain=*) DOMAIN="${1#*=}" ;;
    --email=*) EMAIL="${1#*=}" ;;
    --admin-user=*) ADMIN_USER="${1#*=}" ;;
    --admin-pass=*) ADMIN_PASS="${1#*=}" ;;
    --php-version=*) PHP_VER="${1#*=}" ;;
    --src=*) SRC="${1#*=}" ;;
    --no-ssl) NO_SSL=1 ;;
    --no-restart) NO_RESTART=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Opción desconocida: $1"; usage; exit 1 ;;
  esac
  shift
done

log() { echo -e "\e[1;32m[+] $*\e[0m"; }
warn() { echo -e "\e[1;33m[!] $*\e[0m"; }
die() { echo -e "\e[1;31m[ERROR] $*\e[0m" >&2; exit 1; }

# ---------- Preflight ----------
[ "$(id -u)" -eq 0 ] || die "Ejecuta como root: sudo $0 --domain=..."
[ -z "$DOMAIN" ] && { echo "Falta --domain="; usage; exit 1; }
DOMAIN="$(printf '%s' "$DOMAIN" | tr '[:upper:]' '[:lower:]')"
[[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || die "Dominio no válido: $DOMAIN"
[ -z "$EMAIL" ] && EMAIL="admin@$DOMAIN"
[ -z "$SRC" ] && SRC="$(cd "$(dirname "$0")/.." && pwd)"
[ -d "$SRC" ] || die "No encuentro el código en $SRC"
[ -f "$SRC/index.php" ] || die "$SRC no parece la raíz del proyecto (falta index.php)"

log "Dominio: $DOMAIN | Fuente: $SRC | Destino: $APP_DIR"

# ---------- SO ----------
if [ -f /etc/os-release ]; then
  . /etc/os-release
  [ "$ID" = "ubuntu" ] || warn "Sistema no Ubuntu ($PRETTY_NAME); prueba igual bajo tu responsabilidad."
fi

# Puertos en uso (aviso, no bloquea por si es re-ejecución)
for p in 80 443 8000; do
  if command -v ss >/dev/null 2>&1 && ss -ltn 2>/dev/null | grep -q ":$p "; then
    warn "Puerto $p ya está escuchando (puede ser este mismo panel en re-ejecución)."
  fi
done

# ---------- Paquetes ----------
export DEBIAN_FRONTEND=noninteractive
log "Instalando dependencias del sistema..."
apt-get update -y
echo "icecast2 icecast2/icecast-setup boolean false" | debconf-set-selections
apt-get install -y nginx \
  php${PHP_VER}-cli php${PHP_VER}-fpm php${PHP_VER}-curl php${PHP_VER}-mbstring \
  php${PHP_VER}-intl php${PHP_VER}-opcache php${PHP_VER}-xml \
  icecast2 liquidsoap ffmpeg iproute2 net-tools psmisc procps curl ca-certificates
if [ "$NO_SSL" -eq 0 ]; then
  apt-get install -y certbot python3-certbot-nginx || warn "No se pudo instalar certbot; repite SSL luego."
fi

# Liquidsoap: avisar si la versión de apt no es 2.0.x
if command -v liquidsoap >/dev/null 2>&1; then
  LSV="$(liquidsoap --version 2>/dev/null | head -1 || true)"
  case "$LSV" in
    2.0*) log "Liquidsoap OK: $LSV" ;;
    *) warn "Liquidsoap detectado: ${LSV:-desconocido} (el panel está pensado para 2.0.2). Si falla, instala el .deb 2.0.2 manualmente." ;;
  esac
else
  warn "No se encontró liquidsoap tras el apt."
fi

# ---------- Copiar código ----------
log "Copiando código a $APP_DIR ..."
mkdir -p "$APP_DIR"
( cd "$SRC" && tar -cf - \
    --exclude='database.json' --exclude='config.local.php' --exclude='./pkg' --exclude='./.git' \
    --exclude='./.mimocode' --exclude='*.save' --exclude='*.log' . ) | ( cd "$APP_DIR" && tar -xf - )
chown -R www-data:www-data "$APP_DIR"
chmod 0664 "$APP_DIR/.user.ini" 2>/dev/null || true

log "Comprobando sintaxis PHP..."
while IFS= read -r f; do
  php -l "$f" >/dev/null 2>&1 || die "Error de sintaxis en $f"
done < <(find "$APP_DIR" -name '*.php' -not -path '*/pkg/*')

# ---------- Secretos ----------
ENCRYPT_KEY="$(openssl rand -hex 24)"
DEPLOY_TOKEN="$(openssl rand -hex 16)"
SOURCE_PASS="$(openssl rand -base64 12 | tr -dc 'A-Za-z0-9' | head -c 16)"
RELAY_PASS="$(openssl rand -base64 12 | tr -dc 'A-Za-z0-9' | head -c 16)"
ADMIN_ICEPASS="$(openssl rand -base64 12 | tr -dc 'A-Za-z0-9' | head -c 16)"

TPL="$SRC/pkg/tpl"

render() { # render <tpl> <destino>
  sed -e "s|{{DOMAIN}}|$DOMAIN|g" \
      -e "s|{{ADMIN_EMAIL}}|$EMAIL|g" \
      -e "s|{{ENCRYPT_KEY}}|$ENCRYPT_KEY|g" \
      -e "s|{{DEPLOY_TOKEN}}|$DEPLOY_TOKEN|g" \
      -e "s|{{SOURCE_PASS}}|$SOURCE_PASS|g" \
      -e "s|{{RELAY_PASS}}|$RELAY_PASS|g" \
      -e "s|{{ADMIN_PASS}}|$ADMIN_ICEPASS|g" \
      -e "s|{{APP_DIR}}|$APP_DIR|g" \
      -e "s|{{PHP_SOCKET}}|$PHP_SOCKET|g" \
      "$1" > "$2"
}

# ---------- config.local.php ----------
log "Escribiendo config.local.php (dominio + secretos)..."
render "$TPL/config.local.php.tpl" "$APP_DIR/config.local.php"
chown www-data:www-data "$APP_DIR/config.local.php"
chmod 0640 "$APP_DIR/config.local.php"

# ---------- Directorios / permisos ----------
log "Creando directorios de estado y permisos..."
mkdir -p /var/media/radios/_panel_security /data
chown -R www-data:www-data /var/media /data
chmod -R 0775 /var/media/radios
chmod 0775 /data

# ---------- database.json limpio ----------
if [ ! -f "$APP_DIR/database.json" ]; then
  if [ -n "$ADMIN_USER" ] || [ -n "$ADMIN_PASS" ]; then
    # Alta PRE-configurada (opcional): solo si el instalador recibe --admin-user / --admin-pass
    [ -n "$ADMIN_USER" ] || die "Si usas --admin-pass=... también indica --admin-user=..."
    if [ -z "$ADMIN_PASS" ]; then
      read -rsp "Contraseña para superadmin '$ADMIN_USER': " ADMIN_PASS; echo
      [ -n "$ADMIN_PASS" ] || die "Contraseña vacía."
    fi
    log "Creando database.json limpio (superadmin pre-creado: $ADMIN_USER)..."
    php -r '
      $u=$argv[1]; $h=password_hash($argv[2], PASSWORD_DEFAULT);
      echo json_encode([
        "superadmin"=>["usuario"=>$u,"email"=>"","password_hash"=>$h,"created_at"=>date("Y-m-d H:i:s")],
        "usuarios"=>new stdClass(),
        "radios"=>new stdClass()
      ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    ' "$ADMIN_USER" "$ADMIN_PASS" > "$APP_DIR/database.json"
    chown www-data:www-data "$APP_DIR/database.json"
    chmod 0664 "$APP_DIR/database.json"
  else
    # Sin superadmin: el PRIMER acceso web al panel muestra el alta inicial
    # (estilo AzureCast) y quien la complete queda como superadministrador.
    log "Creando database.json limpio (SIN superadmin → alta en el primer acceso web)..."
    printf '{\n    "superadmin": {\n        "usuario": "",\n        "email": "",\n        "password_hash": ""\n    },\n    "usuarios": {},\n    "radios": {}\n}\n' > "$APP_DIR/database.json"
    chown www-data:www-data "$APP_DIR/database.json"
    chmod 0664 "$APP_DIR/database.json"
  fi
else
  log "database.json ya existe en destino; se conserva."
fi

# ---------- Socket PHP-FPM ----------
PHP_SOCKET=""
for s in "/run/php/php${PHP_VER}-fpm.sock" "/run/php/php-fpm.sock"; do
  [ -S "$s" ] && PHP_SOCKET="$s" && break
done
[ -z "$PHP_SOCKET" ] && PHP_SOCKET="/run/php/php${PHP_VER}-fpm.sock"
log "Socket PHP-FPM: $PHP_SOCKET"

# ---------- nginx ----------
log "Configurando nginx (sitio: radiopanel)..."
NGINX_TPL="$TPL/nginx-radiopanel-http.tpl"   # siempre HTTP primero; certbot añade SSL
render "$NGINX_TPL" /etc/nginx/sites-available/radiopanel
[ -f /etc/nginx/sites-enabled/default ] && rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/radiopanel /etc/nginx/sites-enabled/radiopanel
nginx -t
if [ "$NO_RESTART" -eq 0 ]; then systemctl enable --now php${PHP_VER}-fpm nginx; fi

# ---------- Icecast ----------
log "Configurando Icecast (hostname/credenciales nuevas)..."
[ -f /etc/icecast2/icecast.xml ] && cp -a /etc/icecast2/icecast.xml /etc/icecast2/icecast.xml.orig.$(date +%Y%m%d_%H%M%S) 2>/dev/null || true
render "$TPL/icecast.xml.tpl" /etc/icecast2/icecast.xml
chown root:icecast /etc/icecast2/icecast.xml 2>/dev/null || chown root:root /etc/icecast2/icecast.xml
chmod 0640 /etc/icecast2/icecast.xml
if [ "$NO_RESTART" -eq 0 ]; then systemctl enable --now icecast2; fi

# ---------- SSL (certbot) ----------
if [ "$NO_SSL" -eq 0 ] && command -v certbot >/dev/null 2>&1; then
  log "Emitiendo certificado Let's Encrypt para $DOMAIN (requiere que el DNS ya apunte)..."
  if certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --non-interactive --redirect -q; then
    log "HTTPS activo."
  else
    warn "certbot falló (¿DNS apuntando?). El panel queda en HTTP; repite luego: certbot --nginx -d $DOMAIN --redirect"
  fi
elif [ "$NO_RESTART" -eq 0 ]; then
  nginx -s reload || true
fi

# ---------- Verificación ----------
log "Verificación final..."
nginx -t
php -l "$APP_DIR/config.php" >/dev/null && echo "  config.php OK"
curl -s -o /dev/null -w "  Panel HTTP  : %{http_code}\n" "http://127.0.0.1/index.php" || true
curl -s -o /dev/null -w "  HTTPS ($DOMAIN): %{http_code}\n" "https://$DOMAIN/index.php" 2>/dev/null || warn "HTTPS aún no responde (¿DNS/cert?)."

echo
log "Instalación completada."
echo "  Panel admin : https://$DOMAIN/superradio.php"
if [ -n "$ADMIN_USER" ]; then
  echo "  Usuario     : $ADMIN_USER"
else
  echo "  PRIMER ACCESO: abre el panel y crea tu SUPERADMIN (usuario + contraseña)."
  echo "                Nadie puede entrar hasta completar ese alta inicial."
fi
echo "  Stream      : https://$DOMAIN/<mount>  (crea una radio desde el panel)"
echo "  Recordatorio: abre en el firewall/VPS los puertos 80, 443, 8000 (y el rango DJ 8005+)."
echo "  Actualizar código: usar deploy_update.php (.sck) con el DEPLOY_TOKEN de config.local.php"
