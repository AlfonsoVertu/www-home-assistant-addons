FROM python:3.12-slim

# uvicorn "puro" (asyncio/h11, tutto Python) invece di [standard]: evita uvloop/httptools
# (estensioni C senza wheel su armv7/i386) così l'add-on compila su tutte le arch dichiarate
RUN pip install --no-cache-dir fastapi uvicorn astronomy-engine python-multipart websockets

# librerie 3D incluse nell'immagine (offline):
# model-viewer per l'anteprima del singolo mobile; three.js 0.160 a moduli per la casa 3D (0.26: la stessa versione del
# gioco Another Wilde Web, così il tuo avatar entra nella stessa scena) coi moduli aggiuntivi che servono a casa e avatar
RUN mkdir -p /vendor/jsm/loaders /vendor/jsm/controls /vendor/jsm/exporters /vendor/jsm/utils && python -c "\
import urllib.request as u; \
J='https://cdn.jsdelivr.net/npm/three@0.160.0/'; \
f={'model-viewer.min.js':'https://unpkg.com/@google/model-viewer@3.5.0/dist/model-viewer.min.js', \
'three.module.js':J+'build/three.module.js', \
'jsm/loaders/GLTFLoader.js':J+'examples/jsm/loaders/GLTFLoader.js', \
'jsm/controls/OrbitControls.js':J+'examples/jsm/controls/OrbitControls.js', \
'jsm/exporters/GLTFExporter.js':J+'examples/jsm/exporters/GLTFExporter.js', \
'jsm/utils/BufferGeometryUtils.js':J+'examples/jsm/utils/BufferGeometryUtils.js', \
'jsm/utils/TextureUtils.js':J+'examples/jsm/utils/TextureUtils.js', \
'jsm/utils/SkeletonUtils.js':J+'examples/jsm/utils/SkeletonUtils.js'}; \
[u.urlretrieve(url,'/vendor/'+n) for n,url in f.items()]"

COPY app.py /app.py
COPY avatar.js /avatar.js
COPY domotica.py /domotica.py
COPY domotica.js /domotica.js
COPY ritratto.js /ritratto.js
COPY eclipse_card.js /eclipse_card.js

EXPOSE 8099
CMD ["python", "/app.py"]
