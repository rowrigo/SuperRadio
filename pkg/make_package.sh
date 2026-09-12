#!/usr/bin/env bash
# ============================================================
# make_package.sh — Genera el paquete instalable de radiopanel
# Uso:  bash pkg/make_package.sh [version] [--src=RUTA]
# Salida: superradio-package-<version>.tar.gz en la raíz del código
#
# Monta un staging con el código + pkg/ y empaqueta desde ahí, excluyendo
# secretos y, si el código vive en un HOME (p. ej. /var/www/radiopanel es el
# home de www-data), los ficheros del home (.ssh, .bash_history, ...).
# ============================================================
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"          # .../pkg
SRC="$(cd "$HERE/.." && pwd)"                  # raíz del código (padre de pkg/)
VER=""
for arg in "$@"; do
  case "$arg" in
    --src=*)   SRC="${arg#*=}" ;;
    -h|--help) sed -n '2,9p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)         VER="$arg" ;;
  esac
done
VER="${VER:-$(date +%Y%m%d)}"
[ -f "$SRC/index.php" ] || { echo "[ERROR] $SRC no parece la raíz del proyecto (falta index.php)"; exit 1; }

OUT="$SRC/superradio-package-${VER}.tar.gz"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# 1) Código: sin secretos, sin logs y sin el home de www-data (el docroot lo es)
tar -cf - -C "$SRC" \
  --exclude='./.ssh' --exclude='./.bash_history' --exclude='./.bashrc' \
  --exclude='./.cache' --exclude='./.config' --exclude='./.local' --exclude='./.npm' \
  --exclude='./.launchpadlib' --exclude='./.lesshst' --exclude='./.profile' \
  --exclude='./.wget-hsts' --exclude='./.mimocode' --exclude='./.git' \
  --exclude='./database.json' --exclude='./database.json.backup' \
  --exclude='./config.local.php' --exclude='*.log' \
  --exclude='./superradio-package-*.tar.gz' --exclude='./pkg' \
  . | tar -xf - -C "$STAGE"

# 2) Instalador + plantillas (pkg/)
cp -a "$HERE" "$STAGE/pkg"

# 3) Empaquetar
rm -f "$OUT"
tar -czf "$OUT" -C "$STAGE" .

echo "✔ Paquete creado: $OUT"
echo "  Subir a un VPS Ubuntu 22.04, extraer y ejecutar:"
echo "    sudo ./pkg/install.sh --domain=radio.midominio.com --email=tucorreo@dominio.com"
