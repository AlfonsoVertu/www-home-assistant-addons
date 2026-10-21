#!/usr/bin/with-contenv bash
# ===========================================================
# ONLYOFFICE / EuroOffice integration setup (runs at every start, in the background)
# - occ lives in /app/www/public (linuxserver-style image) and must run as the owner of config.php (abc), never as root
# - onlyoffice_url is the address the BROWSER uses: with Nextcloud on https it must be https too (mixed content)
# - onlyoffice_internal_url (optional) is the address Nextcloud itself uses, e.g. http://f3dcac16-eurooffice/
# - placeholder addresses (example.com) are skipped: the current settings are kept
# ===========================================================

CONFIG_PATH=/data/options.json
CONF_PHP="${NEXTCLOUD_CONFIG:-/data/config/www/nextcloud/config/config.php}"
APP_DIR=/app/www/public
[ -f "${APP_DIR}/occ" ] || APP_DIR=/data/config/www/nextcloud

OO_URL=$(jq -r '.onlyoffice_url // ""' "$CONFIG_PATH")
OO_INTERNAL=$(jq -r '.onlyoffice_internal_url // ""' "$CONFIG_PATH")
OO_JWT=$(jq -r '.onlyoffice_jwt // ""' "$CONFIG_PATH")

slash() { case "$1" in */) echo "$1" ;; "") echo "" ;; *) echo "$1/" ;; esac; }
OO_URL=$(slash "$OO_URL"); OO_INTERNAL=$(slash "$OO_INTERNAL")

setup() {
  local n=0
  until [ -f "$CONF_PHP" ] && grep -q "'installed' => true" "$CONF_PHP" 2>/dev/null; do
    n=$((n + 1)); [ $n -ge 90 ] && { echo "[99-onlyoffice-setup] Nextcloud not installed after 15 min: skipped"; return 0; }
    sleep 10
  done
  local owner; owner=$(stat -c %U "$CONF_PHP")
  occ() { s6-setuidgid "$owner" php "${APP_DIR}/occ" "$@"; }

  occ app:install onlyoffice >/dev/null 2>&1 || occ app:enable onlyoffice >/dev/null 2>&1 || true

  case "$OO_URL" in
    ""|*example.com*|*://eurooffice:8080/)
      echo "[99-onlyoffice-setup] onlyoffice_url not set: keeping $(occ config:app:get onlyoffice DocumentServerUrl 2>/dev/null)" ;;
    *)
      occ config:app:set onlyoffice DocumentServerUrl --value="$OO_URL" >/dev/null
      echo "[99-onlyoffice-setup] DocumentServerUrl = $OO_URL" ;;
  esac
  if [ -n "$OO_INTERNAL" ]; then
    occ config:app:set onlyoffice DocumentServerInternalUrl --value="$OO_INTERNAL" >/dev/null
    echo "[99-onlyoffice-setup] DocumentServerInternalUrl = $OO_INTERNAL"
  fi
  if [ -n "$OO_JWT" ] && [ "$OO_JWT" != "my_jwt_secret" ]; then
    occ config:app:set onlyoffice jwt_secret --value="$OO_JWT" >/dev/null
    occ config:app:set onlyoffice jwt_header --value="Authorization" >/dev/null
    echo "[99-onlyoffice-setup] JWT secret configured (not printed)"
  fi
  occ onlyoffice:documentserver --check 2>&1 | tail -1 | sed 's/^/[99-onlyoffice-setup] /'
}

# in the background: Nextcloud finishes starting meanwhile
setup &
exit 0
