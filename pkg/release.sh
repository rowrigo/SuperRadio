#!/usr/bin/env bash
# ============================================================
# release.sh — Publica una Release de SuperRadio en GitHub.
#
# Hace todo el flujo de una Release nueva:
#   1. Verifica que el clon esté limpio y sincronizado con origin/main.
#   2. Genera el paquete instalable superradio-package-<fecha>.tar.gz.
#   3. Comprueba que el paquete NO lleve database.json ni config.local.php.
#   4. Crea el tag/Release (p. ej. v1.1) sobre main y sube el paquete como asset.
#
# Uso (desde un clon limpio del repo, con el código ya pusheado a main):
#   bash pkg/release.sh v1.1
#
# Requisitos:
#   - Token GitHub (classic) con scope "repo" (se pide por teclado).
#   - El código a publicar ya debe estar commiteado y pusheado a main.
# ============================================================
set -Eeuo pipefail

TAG="${1:-}"
[ -n "$TAG" ] || { echo "Uso: bash pkg/release.sh v1.1"; exit 1; }
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || { echo "Etiqueta no válida (formato v1.1): $TAG"; exit 1; }

cd "$(dirname "$0")/.."   # raíz del repo
REPO="rowrigo/SuperRadio"
DATE="$(date +%Y%m%d)"
PKG="superradio-package-${DATE}.tar.gz"

log() { echo -e "\e[1;32m[+] $*\e[0m"; }
warn() { echo -e "\e[1;33m[!] $*\e[0m"; }
die() { echo -e "\e[1;31m[ERROR] $*\e[0m" >&2; exit 1; }

# ---------- Preflight ----------
REMOTE="$(git remote get-url origin 2>/dev/null || true)"
case "$REMOTE" in
  *rowrigo/SuperRadio*) ;;
  *) die "Este repo no apunta a rowrigo/SuperRadio (origin=$REMOTE). Usa el clon de publicación." ;;
esac

[ -z "$(git status --porcelain)" ] || die "El árbol tiene cambios sin commitear. Publica primero el código a main."
LOCAL_HEAD="$(git rev-parse HEAD)"
REMOTE_HEAD="$(git rev-parse origin/main 2>/dev/null || true)"
[ "$LOCAL_HEAD" = "$REMOTE_HEAD" ] || die "main local ($LOCAL_HEAD) no está sincronizado con origin/main ($REMOTE_HEAD). Haz git push primero."

log "Release $TAG sobre main ($LOCAL_HEAD) en $REPO"

# ---------- Paquete ----------
log "Generando paquete de instalación..."
bash pkg/make_package.sh "$DATE"
[ -f "$PKG" ] || die "No se generó $PKG"
if tar -tzf "$PKG" | grep -Eq '(^|/)database\.json$|(^|/)config\.local\.php$'; then
  die "El paquete incluye database.json o config.local.php. Abortando."
fi
PKG_SIZE="$(stat -c%s "$PKG")"
PKG_SHA="$(sha256sum "$PKG" | cut -d' ' -f1)"
log "Paquete OK: $PKG ($PKG_SIZE bytes, sha256 $PKG_SHA)"

# ---------- Token ----------
read -rsp "Pega tu GitHub token (scope repo) y presiona Enter: " TOK
echo
[ -n "$TOK" ] || die "Token vacío."
curl -fsS -H "Authorization: Bearer $TOK" https://api.github.com/user -o /tmp/ghu.json || die "Token inválido."
ACCT=$(php -r '$j=json_decode(file_get_contents("/tmp/ghu.json"), true); echo $j["login"] ?? "";')
log "Cuenta: $ACCT"
[ "$ACCT" = "rowrigo" ] || warn "El token es de '$ACCT', no de rowrigo. ¿Seguro?"

# ---------- Cuerpo de la Release ----------
BODY_FILE=/tmp/release_body.md
cat > "$BODY_FILE" <<EOF
Release **$TAG** de SuperRadio: panel autohospedado para automatizar emisoras de radio (AutoDJ, cabina DJ y pagina publica). PHP + Icecast + Liquidsoap.

## Instalar en un VPS (Ubuntu 22.04)

Descarga el paquete, extrae y ejecuta el instalador (despliega el codigo en \`/var/www/radiopanel\`):

\`\`\`bash
wget https://github.com/rowrigo/SuperRadio/releases/download/$TAG/$PKG
tar -xzf $PKG
sudo ./pkg/install.sh --domain=radio.tudominio.com --email=tu@correo.com
\`\`\`

Despues abre \`https://radio.tudominio.com/\` y crea tu superadmin en el primer acceso.

Paquete: \`$PKG\` ($PKG_SIZE bytes) — sha256 \`$PKG_SHA\`
EOF

PAYLOAD=$(php -r 'echo json_encode(["tag_name"=>$argv[1],"target_commitish"=>"main","name"=>"SuperRadio ".$argv[1],"body"=>file_get_contents($argv[2])]);' "$TAG" "$BODY_FILE")

# ---------- Crear Release ----------
log "Creando Release (tag $TAG sobre main)..."
if ! curl -fsS -X POST -H "Authorization: Bearer $TOK" \
  -H "Accept: application/vnd.github+json" \
  "https://api.github.com/repos/$REPO/releases" -d "$PAYLOAD" -o /tmp/ghrel.json; then
  die "No se pudo crear la Release (ver /tmp/ghrel.json). Si el tag $TAG ya existe, usa otra versión."
fi
REL_ID=$(php -r '$j=json_decode(file_get_contents("/tmp/ghrel.json"), true); echo $j["id"] ?? "";')
[ -n "$REL_ID" ] || die "No se obtuvo id de Release (ver /tmp/ghrel.json)."

# ---------- Subir asset ----------
log "Subiendo asset ($PKG)..."
if ! curl -fsS -X POST -H "Authorization: Bearer $TOK" \
  -H "Content-Type: application/gzip" \
  --data-binary "@$PKG" \
  "https://uploads.github.com/repos/$REPO/releases/$REL_ID/assets?name=$PKG" \
  -o /tmp/ghasset.json; then
  die "Falló la subida del asset (ver /tmp/ghasset.json)."
fi
URL=$(php -r '$j=json_decode(file_get_contents("/tmp/ghasset.json"), true); echo $j["browser_download_url"] ?? "";')
unset TOK

echo
log "Release publicada: https://github.com/$REPO/releases/tag/$TAG"
[ -n "$URL" ] && echo "  Asset : $URL" && echo "  sha256: $PKG_SHA"
