#!/usr/bin/env bash
# le opzioni dell'add-on (/data/options.json) → variabili di Open WebUI; i dati in /data
set -e
export DATA_DIR=/data
eval "$(python3 - <<'PY'
import json, shlex
o = json.load(open('/data/options.json'))
m = {'ollama_base_url': 'OLLAMA_BASE_URL', 'openai_api_base_url': 'OPENAI_API_BASE_URL', 'openai_api_key': 'OPENAI_API_KEY'}
for k, v in m.items():
    if o.get(k):
        print(f'export {v}={shlex.quote(str(o[k]))}')
print('export ENABLE_SIGNUP=' + ('true' if o.get('enable_signup', True) else 'false'))
PY
)"
# la chiave delle sessioni: generata una volta e tenuta in /data
if [ ! -s /data/.webui_secret_key ]; then
  python3 -c "import secrets; print(secrets.token_urlsafe(32))" > /data/.webui_secret_key
  chmod 600 /data/.webui_secret_key
fi
export WEBUI_SECRET_KEY="$(cat /data/.webui_secret_key)"
cd /app/backend
exec bash start.sh
