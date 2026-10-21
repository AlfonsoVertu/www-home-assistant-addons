#!/bin/bash
# ===========================================================
# EuroOffice Document Server - Home Assistant Add-on
# This script configures JWT from HA options then starts
# the original EuroOffice entrypoint
# ===========================================================
set -e

CONFIG_PATH=/data/options.json

# Read options from Home Assistant
JWT_ENABLED=$(cat $CONFIG_PATH | grep '"jwt_enabled"' | grep -o 'true\|false' | head -1)
JWT_SECRET=$(cat $CONFIG_PATH | grep '"jwt_secret"' | sed 's/.*"jwt_secret": *"\([^"]*\)".*/\1/')
EXAMPLE_ENABLED=$(cat $CONFIG_PATH | grep '"example_enabled"' | grep -o 'true\|false' | head -1)

echo "[EuroOffice] Configuring JWT (enabled=$JWT_ENABLED)..."

python3 - <<PYEOF
import json

local_json = '/etc/onlyoffice/documentserver/local.json'
try:
    with open(local_json, 'r') as f:
        data = json.load(f)
except:
    data = {}

jwt_enabled = '${JWT_ENABLED}' == 'true'
jwt_secret = '${JWT_SECRET}'

data.setdefault('services', {}).setdefault('CoAuthoring', {})
data['services']['CoAuthoring'].setdefault('token', {}).setdefault('enable', {})
data['services']['CoAuthoring']['token']['enable']['request'] = {'inbox': jwt_enabled, 'outbox': jwt_enabled}
data['services']['CoAuthoring']['token']['enable']['browser'] = jwt_enabled

data['services']['CoAuthoring'].setdefault('secret', {})
for k in ['inbox', 'outbox', 'browser', 'session']:
    data['services']['CoAuthoring']['secret'].setdefault(k, {})['string'] = jwt_secret

with open(local_json, 'w') as f:
    json.dump(data, f, indent=2)
print('[EuroOffice] Document Server JWT configured.')
PYEOF

if [ "${EXAMPLE_ENABLED}" == "true" ]; then
    echo "[EuroOffice] Configuring Example App JWT..."
    python3 - <<PYEOF2
import json

example_json = '/etc/onlyoffice/documentserver-example/default.json'
try:
    with open(example_json, 'r') as f:
        data = json.load(f)
except:
    data = {'server': {'token': {}}}

data.setdefault('server', {}).setdefault('token', {})
data['server']['token']['enable'] = '${JWT_ENABLED}' == 'true'
data['server']['token']['secret'] = '${JWT_SECRET}'

with open(example_json, 'w') as f:
    json.dump(data, f, indent=2)
print('[EuroOffice] Example App JWT configured.')
PYEOF2
fi

echo "[EuroOffice] Starting EuroOffice Document Server..."
exec /entrypoint.sh
