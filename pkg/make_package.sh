#!/usr/bin/env bash
# ============================================================
# make_package.sh — Genera el paquete instalable de radiopanel
# Uso:  bash pkg/make_package.sh [version]
# Salida: superradio-package-<version>.tar.gz en la raíz del repo
# ============================================================
set -euo pipefail
cd "$(dirname "$0")/.."

VER="${1:-$(date +%Y%m%d)}"
OUT="superradio-package-${VER}.tar.gz"

rm -f "$OUT"

tar -czf "$OUT" \
  --exclude='.git' \
  --exclude='.mimocode' \
  --exclude='.aider*' \
  --exclude='*.save' \
  --exclude='*.save.*' \
  --exclude='*.log' \
  --exclude='auth_debug.log' \
  --exclude='_tmp*' \
  --exclude='__test*' \
  --exclude='_deploy_tracker*' \
  --exclude='database.json' \
  --exclude='config.local.php' \
  --exclude="$OUT" \
  .

echo "✔ Paquete creado: $OUT"
echo "  Subir a un VPS Ubuntu 22.04, extraer y ejecutar:"
echo "    sudo ./pkg/install.sh --domain=radio.midominio.com --email=tucorreo@dominio.com"
