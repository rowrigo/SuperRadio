#!/bin/bash
# =====================================================================
# Watchdog del AutoDJ de SuperRadio.
#
# Red de seguridad (ademas del Restart=always de la unidad):
#  - Arranca la unidad de cada emisora marcada como "debe estar al aire"
#    (archivo .autodj_enabled que escribe el panel al arrancarla).
#  - Cubre el arranque tras reboot y el caso de unidad caida/parada.
#  - Si la unidad lleva >2 min activa pero Icecast NO tiene su fuente,
#    la reinicia (detecta cuelgues sin muerte del proceso).
#
# Lo ejecuta radiopanel-autodj-watchdog.timer cada 2 minutos.
# =====================================================================
set -u
LOG_TAG="radiopanel-watchdog"
RADIOS_DIR="/var/media/radios"
ICECAST_URL="http://127.0.0.1:8000/status-json.xsl"
STALE_SECS=120

SOURCES="$(curl -s --max-time 5 "$ICECAST_URL" 2>/dev/null || true)"
HAVE_ICECAST=0
[ -n "$SOURCES" ] && HAVE_ICECAST=1

now_us="$(awk '{printf "%d", $1*1000000}' /proc/uptime 2>/dev/null)"
[ -z "$now_us" ] && now_us=0

for flag in "$RADIOS_DIR"/*/.autodj_enabled; do
    [ -e "$flag" ] || continue
    dir="$(dirname "$flag")"
    mount="$(basename "$dir")"
    unit="radiopanel-autodj@${mount}.service"

    state="$(systemctl is-active "$unit" 2>/dev/null)"
    if [ "$state" != "active" ] && [ "$state" != "activating" ]; then
        logger -t "$LOG_TAG" "emisora $mount: unidad en estado '$state' -> arrancando"
        systemctl start "$unit"
        continue
    fi

    [ "$HAVE_ICECAST" -eq 1 ] || continue

    if printf '%s' "$SOURCES" | grep -q "/${mount}\""; then
        continue
    fi

    started_us="$(systemctl show -p ActiveEnterTimestampMonotonic --value "$unit" 2>/dev/null)"
    case "$started_us" in ''|*[!0-9]*) started_us=0 ;; esac
    age_s=$(( (now_us - started_us) / 1000000 ))
    if [ "$age_s" -gt "$STALE_SECS" ]; then
        logger -t "$LOG_TAG" "emisora $mount: unidad activa hace ${age_s}s pero sin fuente en Icecast -> reiniciando"
        systemctl restart "$unit"
    fi
done

exit 0
