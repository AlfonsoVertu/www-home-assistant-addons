import os, json, uuid, time, threading, urllib.request, urllib.error, hmac
import datetime as dt
from fastapi import FastAPI, UploadFile, File, HTTPException, Request, Header
from fastapi.responses import HTMLResponse, FileResponse, JSONResponse, Response
import uvicorn
import astronomy

def addon_options():
    try:
        return json.load(open("/data/options.json"))
    except Exception:
        return {}

# Chiave per l'export protetto verso client esterni (es. WordPress/VWGK).
# Impostala nelle opzioni dell'add-on (api_key). Vuota = export disabilitato.
API_KEY = str(addon_options().get("api_key") or os.environ.get("VWGK_API_KEY") or "")

# QUANTO E' GRANDE LA TELA SU CUI SI DISEGNA LA CASA.
#
# Si dichiara in BLOCCHI, non in punti, perche' e' cosi' che la si guarda: la
# griglia ha il passo di 50 e le stanze ci si agganciano. Chiedere "quanto
# grande" in punti vorrebbe dire far fare a chi configura una moltiplicazione
# per sapere quanti quadretti avra'.
#
# Il valore di partenza - 22 x 15 - e' due blocchi piu' largo e piu' alto del
# vecchio 1000x640: quello stava stretto appena una casa aveva un balcone o un
# corridoio lungo, e non c'era modo di allargarlo se non modificando il codice.
BLOCCO = 50
COLONNE = max(10, min(60, int(addon_options().get("griglia_colonne") or 22)))
RIGHE   = max(8,  min(40, int(addon_options().get("griglia_righe")   or 15)))
PIANO_W = COLONNE * BLOCCO
PIANO_H = RIGHE * BLOCCO

DATA = "/data"; PHOTOS = os.path.join(DATA, "photos"); MODELS = os.path.join(DATA, "models"); PLAN_FILE = os.path.join(DATA, "plan.json")
os.makedirs(PHOTOS, exist_ok=True); os.makedirs(MODELS, exist_ok=True)
VENDOR = "/vendor"  # libreria model-viewer inclusa nell'immagine (offline)
app = FastAPI()

def ha_config():
    token = os.environ.get("SUPERVISOR_TOKEN", "")
    try:
        req = urllib.request.Request("http://supervisor/core/api/config", headers={"Authorization": f"Bearer {token}"})
        with urllib.request.urlopen(req, timeout=10) as r:
            return json.load(r)
    except Exception:
        return {}

def get_location():
    c = ha_config()
    return {"latitude": c.get("latitude"), "longitude": c.get("longitude"),
            "elevation": c.get("elevation") or 0, "time_zone": c.get("time_zone") or "UTC",
            "location_name": c.get("location_name") or "Casa"}

def horiz(body, t, obs):
    eq = astronomy.Equator(body, t, obs, True, True)
    h = astronomy.Horizon(t, obs, eq.ra, eq.dec, astronomy.Refraction.Normal)
    return h.azimuth, h.altitude

def kname(k): return str(k).split(".")[-1]
def compass16(az):
    d = ["N","NNE","NE","ENE","E","ESE","SE","SSE","S","SSO","SO","OSO","O","ONO","NO","NNO"]
    return d[int((az % 360)/22.5 + 0.5) % 16]

def next_solar(obs):
    e = astronomy.SearchLocalSolarEclipse(astronomy.Time.Now(), obs)
    az, _ = horiz(astronomy.Body.Sun, e.peak.time, obs)
    return {"type":"Eclissi Solare","body":"sun","kind":kname(e.kind),
            "obscuration":round((e.obscuration or 0)*100,1),
            "peak_utc":e.peak.time.Utc().replace(tzinfo=dt.timezone.utc).isoformat(),
            "azimuth":round(az,1),"compass":compass16(az),"altitude":round(e.peak.altitude,1),
            "visible":e.peak.altitude>0}

def next_lunar(obs):
    e = astronomy.SearchLunarEclipse(astronomy.Time.Now())
    az, alt = horiz(astronomy.Body.Moon, e.peak, obs)
    return {"type":"Eclissi Lunare","body":"moon","kind":kname(e.kind),
            "obscuration":round((getattr(e,"obscuration",0) or 0)*100,1),
            "peak_utc":e.peak.Utc().replace(tzinfo=dt.timezone.utc).isoformat(),
            "azimuth":round(az,1),"compass":compass16(az),"altitude":round(alt,1),
            "visible":alt>0}

def load_plan():
    try: return json.load(open(PLAN_FILE))
    except Exception: return {"rooms": []}
def save_plan(p): json.dump(p, open(PLAN_FILE,"w"), indent=2)

@app.get("/api/config")
def api_config(): return get_location()

@app.get("/api/eclipse")
def api_eclipse():
    loc = get_location()
    if loc["latitude"] is None: raise HTTPException(400,"Coordinate casa non impostate in HA")
    obs = astronomy.Observer(loc["latitude"], loc["longitude"], loc["elevation"])
    out = {"location":loc,"solar":None,"lunar":None}
    for k,fn in (("solar",next_solar),("lunar",next_lunar)):
        try: out[k]=fn(obs)
        except Exception as ex: out[k]={"error":str(ex)}
    return out

@app.get("/api/plan")
def api_get_plan(): return load_plan()

@app.post("/api/plan")
async def api_save_plan(req: Request):
    body = await req.json()
    if not isinstance(body, dict) or "rooms" not in body: raise HTTPException(400,"formato non valido")
    save_plan(body); return {"ok": True}

@app.get("/api/export")
def api_export(x_api_key: str = Header(default="")):
    """Export protetto (X-API-Key) di planimetria + eclissi per client esterni (WordPress/VWGK)."""
    if not API_KEY:
        raise HTTPException(403, "export disabilitato: imposta 'api_key' nelle opzioni dell'add-on")
    if not hmac.compare_digest(x_api_key or "", API_KEY):
        raise HTTPException(401, "api key non valida")
    out = {"plan": load_plan(), "generated_utc": dt.datetime.now(dt.timezone.utc).isoformat()}
    try:
        loc = get_location()
        if loc.get("latitude") is not None:
            out["location"] = loc
            obs = astronomy.Observer(loc["latitude"], loc["longitude"], loc["elevation"])
            out["eclipse"] = {}
            for k, fn in (("solar", next_solar), ("lunar", next_lunar)):
                try: out["eclipse"][k] = fn(obs)
                except Exception as ex: out["eclipse"][k] = {"error": str(ex)}
    except Exception as ex:
        out["location_error"] = str(ex)
    return out

@app.post("/api/photo")
async def api_photo(file: UploadFile = File(...)):
    ext = os.path.splitext(file.filename or "")[1].lower()
    if ext not in (".jpg",".jpeg",".png",".webp"): ext=".jpg"
    fn = uuid.uuid4().hex[:12]+ext
    with open(os.path.join(PHOTOS, fn),"wb") as f: f.write(await file.read())
    return {"photo": fn}

@app.get("/photos/{fn}")
def get_photo(fn: str):
    p = os.path.join(PHOTOS, os.path.basename(fn))
    if not os.path.exists(p): raise HTTPException(404,"not found")
    return FileResponse(p)

@app.post("/api/model")
async def api_model(file: UploadFile = File(...)):
    ext = os.path.splitext(file.filename or "")[1].lower()
    if ext not in (".glb",".gltf"): ext=".glb"
    fn = uuid.uuid4().hex[:12]+ext
    with open(os.path.join(MODELS, fn),"wb") as f: f.write(await file.read())
    return {"model": fn}

@app.get("/models/{fn}")
def get_model(fn: str):
    p = os.path.join(MODELS, os.path.basename(fn))
    if not os.path.exists(p): raise HTTPException(404,"not found")
    return FileResponse(p)

@app.get("/vendor/{fn:path}")
def get_vendor(fn: str):
    # (0.26) anche le sottocartelle di three (jsm/loaders/...), mai fuori da /vendor
    if not fn.endswith(".js") or ".." in fn or fn.startswith("/"): raise HTTPException(404,"not found")
    p = os.path.normpath(os.path.join(VENDOR, fn))
    if not p.startswith(VENDOR + os.sep): raise HTTPException(404,"not found")
    if not os.path.exists(p): raise HTTPException(404,"libreria non inclusa nel build")
    return FileResponse(p, media_type="text/javascript")

# ---------------------------------------------------------------- il gioco
#
# IL TUO AVATAR DI ANOTHER WILDE WEB NELLA CASA (0.26, 13 ottobre 2026).
#
# Si entra con l'account del gioco (nome e password di Minecraft). La password
# passa una volta sola al portale del gioco e NON si salva: si tiene solo il
# gettone che il portale restituisce (30 giorni), in /data/gioco.json.
#
# Il personaggio (corpo Nuian, viso, capelli, colori, costume) e il codice che lo
# anima stanno sul portale del gioco: i modelli sono quelli di ArcheAge, per uso
# personale, e NON possono stare in questo repository. Il browser li chiede a
# Eclipse (gioco/, aspetti/, modelli/) ed Eclipse li prende dal portale col
# gettone, da server a server: niente cookie fra siti diversi, niente gettone
# nel browser. I file grossi (modelli, texture) restano in una cache su disco.
#
# Questi indirizzi rispondono SOLO a chi arriva dall'ingress di Home Assistant
# (cioè a chi è già entrato in HA): mai a /embed o a chi conosce l'api_key.

GIOCO_URL = str(addon_options().get("gioco_url") or "https://gioca.workingwithweb.eu").rstrip("/")
GIOCO_FILE = os.path.join(DATA, "gioco.json")
GIOCO_CACHE = os.path.join(DATA, "gioco_cache")
os.makedirs(GIOCO_CACHE, exist_ok=True)
INGRESS = {"172.30.32.2", "127.0.0.1", "::1"}
TIPI_GIOCO = {".js": "text/javascript", ".mjs": "text/javascript", ".json": "application/json", ".glb": "model/gltf-binary",
              ".png": "image/png", ".jpg": "image/jpeg", ".ktx2": "image/ktx2", ".wasm": "application/wasm", ".ogg": "audio/ogg"}

def _solo_ingress(request: Request):
    if (request.client.host if request.client else "") not in INGRESS:
        raise HTTPException(403, "solo dall'interfaccia di Home Assistant")

GIOCO_NOME = str(addon_options().get("gioco_nome") or "").strip()
GIOCO_PASSWORD = str(addon_options().get("gioco_password") or "")
_ultimo_tentativo = [0.0]

def gioco_entra(nome, password):
    """Entra nel gioco: (ok, nome o errore). Salva solo il gettone."""
    st, _, dati = gioco_chiama("/portale/esterno/accesso", {"nome": str(nome or "")[:16], "password": str(password or "")[:64], "da": "eclipse"})
    try: r = json.loads(dati)
    except Exception: r = {"ok": False, "errore": "Risposta strana dal portale (" + str(st) + ")."}
    if st != 200 or not r.get("ok") or not r.get("jwt"):
        return False, r.get("errore") or "Accesso non riuscito."
    json.dump({"nome": r["nome"], "jwt": r["jwt"], "scade": r.get("scade", 0)}, open(GIOCO_FILE, "w"))
    os.chmod(GIOCO_FILE, 0o600)
    return True, r["nome"]

def gioco_stato():
    try:
        g = json.load(open(GIOCO_FILE))
        if g.get("jwt") and g.get("scade", 0) > dt.datetime.now().timestamp() + 3600:
            return g
    except Exception:
        pass
    # (0.26) l'account nelle opzioni dell'add-on: si entra da soli, al massimo un tentativo ogni 5 minuti (il portale
    # conta i tentativi sbagliati e blocca)
    if GIOCO_NOME and GIOCO_PASSWORD and dt.datetime.now().timestamp() - _ultimo_tentativo[0] > 300:
        _ultimo_tentativo[0] = dt.datetime.now().timestamp()
        ok, _ = gioco_entra(GIOCO_NOME, GIOCO_PASSWORD)
        if ok:
            try: return json.load(open(GIOCO_FILE))
            except Exception: return None
    return None

def gioco_chiama(percorso, corpo=None, jwt=None, timeout=25):
    """(stato, intestazioni, dati) dal portale del gioco."""
    # i modelli con le texture normali (png/jpg), non KTX2: la casa non ha il lettore KTX2 del gioco
    h = {"User-Agent": "EclipseFinder/0.26", "Cookie": "aww_senzaktx2=1"}
    if corpo is not None: h["Content-Type"] = "application/json"
    if jwt: h["Authorization"] = "Bearer " + jwt
    req = urllib.request.Request(GIOCO_URL + percorso, data=None if corpo is None else json.dumps(corpo).encode(), headers=h,
                                 method="POST" if corpo is not None else "GET")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, dict(r.headers), r.read()
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers or {}), e.read() if hasattr(e, "read") else b""
    except Exception as e:
        return 502, {}, json.dumps({"ok": False, "errore": "Il portale del gioco non risponde: " + str(e)[:120]}).encode()

@app.get("/api/gioco/stato")
def api_gioco_stato(request: Request):
    _solo_ingress(request)
    g = gioco_stato()
    return {"collegato": bool(g), "nome": g and g.get("nome"), "scade": g and g.get("scade"), "portale": GIOCO_URL}

@app.post("/api/gioco/accesso")
async def api_gioco_accesso(request: Request):
    _solo_ingress(request)
    d = await request.json()
    ok, esito = gioco_entra(d.get("nome"), d.get("password"))
    if not ok: return JSONResponse({"ok": False, "errore": esito}, status_code=401)
    return {"ok": True, "nome": esito}

@app.post("/api/gioco/esci")
def api_gioco_esci(request: Request):
    _solo_ingress(request)
    try: os.remove(GIOCO_FILE)
    except FileNotFoundError: pass
    return {"ok": True}

@app.get("/api/gioco/avatar")
def api_gioco_avatar(request: Request):
    _solo_ingress(request)
    g = gioco_stato()
    if not g: raise HTTPException(401, "entra prima con l'account del gioco")
    st, _, dati = gioco_chiama("/portale/esterno/avatar", jwt=g["jwt"])
    if st == 401:
        api_gioco_esci(request)
        raise HTTPException(401, "il gettone del gioco è scaduto: entra di nuovo")
    return JSONResponse(json.loads(dati or b"{}"), status_code=st)

# (0.29) LA CASA NEL GIOCO. «Manda al gioco» porta la planimetria di questa casa al portale del gioco, col gettone
# dell'account collegato: nel gioco l'Architetto (vicino all'Employment Agency) ne fa una pergamena che la costruisce
# sul lotto del giocatore, con i solai a mezze lastre (massimo 112x112 blocchi, 64 di altezza, 1 blocco = 50 cm).
@app.post("/api/gioco/manda_casa")
def api_gioco_manda_casa(request: Request):
    _solo_ingress(request)
    g = gioco_stato()
    if not g: raise HTTPException(401, "entra prima con l'account del gioco")
    piano = load_plan()
    if not piano.get("rooms"): raise HTTPException(400, "la planimetria non ha stanze")
    st, _, dati = gioco_chiama("/portale/esterno/casa", {"plan": piano}, jwt=g["jwt"], timeout=40)
    if st == 401:
        api_gioco_esci(request)
        raise HTTPException(401, "il gettone del gioco è scaduto: entra di nuovo")
    try: r = json.loads(dati or b"{}")
    except Exception: r = {"ok": False, "errore": "Risposta strana dal portale (" + str(st) + ")."}
    return JSONResponse(r, status_code=st)

# (0.29) IL PANNELLO DEL PERSONAGGIO. Il gioco vero gira in una finestra di Chrome (gioca.workingwithweb.eu); da qui
# si vedono lo stato del personaggio e si comanda il suo RobotMODE: accendi, spegni, ordini dalla chat di Eclipse
def _portale_gioco(request: Request, percorso: str, corpo=None):
    _solo_ingress(request)
    g = gioco_stato()
    if not g: raise HTTPException(401, "entra prima con l'account del gioco")
    st, _, dati = gioco_chiama(percorso, corpo, jwt=g["jwt"], timeout=15)
    if st == 401:
        api_gioco_esci(request)
        raise HTTPException(401, "il gettone del gioco è scaduto: entra di nuovo")
    try: r = json.loads(dati or b"{}")
    except Exception: r = {"ok": False, "errore": "Risposta strana dal portale (" + str(st) + ")."}
    return JSONResponse(r, status_code=st)

# (0.30) IL MIO AVATAR COME COSTUME: il file (.glb o .vrm, al massimo 15 MB) va al portale del gioco col gettone
# dell'account; lì lo guarda un amministratore e, approvato, si compra dal Costume Tailor (30.000 Cadde)
@app.post("/api/gioco/costume")
async def api_gioco_costume(request: Request):
    _solo_ingress(request)
    g = gioco_stato()
    if not g: raise HTTPException(401, "entra prima con l'account del gioco")
    dati = await request.body()
    if len(dati) > 15 * 1024 * 1024: raise HTTPException(413, "il file è più grande di 15 MB")
    if dati[:4] != b"glTF": raise HTTPException(400, "serve un file .glb o .vrm")
    nome = "".join(c for c in str(request.headers.get("x-nome-file") or "") if c.isalnum() or c in "._- ")[:80]
    req = urllib.request.Request(GIOCO_URL + "/portale/esterno/costume", data=dati, method="POST",
                                 headers={"User-Agent": "EclipseFinder/0.30", "Content-Type": "application/octet-stream", "Authorization": "Bearer " + g["jwt"], "X-Nome-File": nome})
    try:
        with urllib.request.urlopen(req, timeout=120) as r: st, corpo = r.status, r.read()
    except urllib.error.HTTPError as e: st, corpo = e.code, e.read()
    except Exception as e: st, corpo = 502, json.dumps({"ok": False, "errore": "Il portale del gioco non risponde: " + str(e)[:120]}).encode()
    if st == 401:
        api_gioco_esci(request)
        raise HTTPException(401, "il gettone del gioco è scaduto: entra di nuovo")
    try: r = json.loads(corpo or b"{}")
    except Exception: r = {"ok": False, "errore": "Risposta strana dal portale (" + str(st) + ")."}
    return JSONResponse(r, status_code=st)

@app.get("/api/gioco/costume")
def api_gioco_costume_stato(request: Request):
    return _portale_gioco(request, "/portale/esterno/costume")

@app.get("/api/gioco/personaggio")
def api_gioco_personaggio(request: Request):
    return _portale_gioco(request, "/portale/esterno/personaggio")

@app.post("/api/gioco/robot")
async def api_gioco_robot(request: Request):
    d = await request.json()
    az = str(d.get("azione") or "")
    if az not in ("accendi", "spegni", "ordine"): raise HTTPException(400, "azioni: accendi, spegni, ordine")
    return _portale_gioco(request, "/portale/esterno/robot", {"azione": az, "testo": str(d.get("testo") or "")[:1000]})

@app.get("/api/gioco/robot/risposte")
def api_gioco_robot_risposte(request: Request, dopo: int = 0):
    return _portale_gioco(request, "/portale/esterno/robot/risposte?dopo=" + str(max(0, int(dopo))))

def _dal_portale(request: Request, percorso: str, cache: bool):
    _solo_ingress(request)
    if ".." in percorso or percorso.startswith("/") or not all(c.isalnum() or c in "/._-" for c in percorso):
        raise HTTPException(400, "percorso non valido")
    est = os.path.splitext(percorso)[1].lower()
    if est not in TIPI_GIOCO: raise HTTPException(404, "tipo di file non previsto")
    g = gioco_stato()
    if not g: raise HTTPException(401, "entra prima con l'account del gioco")
    f = os.path.join(GIOCO_CACHE, percorso.replace("/", "__"))
    if cache and os.path.exists(f) and dt.datetime.now().timestamp() - os.path.getmtime(f) < 7 * 86400:
        return FileResponse(f, media_type=TIPI_GIOCO[est], headers={"Cache-Control": "private, max-age=86400"})
    st, _, dati = gioco_chiama("/" + percorso, jwt=g["jwt"], timeout=60)
    if st == 401:
        api_gioco_esci(request)
        raise HTTPException(401, "il gettone del gioco è scaduto: entra di nuovo")
    if st != 200: raise HTTPException(st, "il portale del gioco ha risposto " + str(st))
    if cache:
        tmp = f + ".tmp"
        open(tmp, "wb").write(dati); os.replace(tmp, f)
    return Response(content=dati, media_type=TIPI_GIOCO[est], headers={"Cache-Control": "private, max-age=" + ("86400" if cache else "60")})

# il codice del personaggio (moduli del gioco: corpo Nuian, animazioni): sempre fresco
@app.get("/gioco/{percorso:path}")
def gioco_moduli(percorso: str, request: Request): return _dal_portale(request, percorso, cache=False)

# i modelli e le texture: dalla cache, se ci sono
@app.get("/aspetti/{percorso:path}")
def gioco_aspetti(percorso: str, request: Request): return _dal_portale(request, "aspetti/" + percorso, cache=True)

@app.get("/modelli/{percorso:path}")
def gioco_modelli(percorso: str, request: Request): return _dal_portale(request, "modelli/" + percorso, cache=True)

# l'avatar nella casa: modulo nostro (MIT), servito con l'add-on
@app.get("/avatar.js")
def avatar_js(): return FileResponse("/avatar.js", media_type="text/javascript", headers={"Cache-Control": "no-cache"})

# ---------------------------------------------------------------- casa viva
#
# (0.27) GEMELLO, ABITUDINI, SCENE, VOLUME, ALLARME: la logica sta in domotica.py, qui solo gli indirizzi. Tutti solo
# dall'ingress (come il gioco). Quello che CAMBIA la casa (eseguire, applicare una scena, creare un'automazione,
# provare una luce) vuole "conferma": true nel corpo: la pagina lo chiede con un dialogo, un errore di clic non basta.
import domotica

CASA_VIVA = addon_options().get("casa_viva", True) is not False
VOLUME_OK = bool(addon_options().get("volume_automatico", False))
ALLARME_OK = bool(addon_options().get("allarme_automatico", False))

def _casa(request: Request):
    _solo_ingress(request)
    if not CASA_VIVA: raise HTTPException(403, "Casa viva è spenta nelle opzioni dell'add-on (casa_viva)")

def _confermato(d):
    if d.get("conferma") is not True: raise HTTPException(400, "serve la conferma")

@app.on_event("startup")
def _avvia_guardiano():
    if CASA_VIVA:
        domotica.PERMESSI.update({"volume": VOLUME_OK, "allarme": ALLARME_OK})
        domotica.avvia()

@app.get("/api/casa/gemello")
def casa_gemello(request: Request):
    _casa(request); return {"stanze": domotica.gemello(load_plan())}

# (0.31) PRESENZA (persone, tracker di stanza, movimento) e RIEPILOGO della casa in frasi
@app.get("/api/casa/presenza")
def casa_presenza(request: Request):
    _casa(request); return domotica.presenza(load_plan())

@app.get("/api/casa/riepilogo")
def casa_riepilogo(request: Request):
    _casa(request); return domotica.riepilogo(load_plan())

# (0.31) LA CARD DELLA DASHBOARD: tutto quello che la card mostra in una chiamata sola (la card la ripete ogni 10 s)
@app.get("/api/casa/card")
def casa_card(request: Request):
    _casa(request); piano = load_plan(); d = domotica.leggi(); g = domotica.guardiano
    al = g.allarme
    return {"stanze": domotica.gemello(piano), "presenza": domotica.presenza(piano), "riepilogo": domotica.riepilogo(piano),
            "scene": [{"id": k, "nome": v.get("nome"), "stanza": v.get("stanza")} for k, v in d["scene"].items()],
            "scene_casa": [{"id": k, "nome": v.get("nome")} for k, v in d["scene_casa"].items()],
            "allarme": ({k: al[k] for k in ("cosa", "stanza", "frase", "prova")} if al else None),
            "automatismi": {"volume": bool(d["volume"].get("attivo") and domotica.PERMESSI["volume"]),
                            "allarme": bool(d["allarme"].get("attivo") and domotica.PERMESSI["allarme"]),
                            "vuota": bool((d.get("vuota") or {}).get("attivo"))},
            "registro": g.registro[-6:]}

@app.get("/api/casa/legami")
def casa_legami(request: Request):
    _casa(request)
    st = domotica.stati(); d = domotica.leggi(); piano = load_plan()
    ent = [{"entity_id": e, "nome": s["attributes"].get("friendly_name", e), "stato": s["state"], "classe": domotica.classe(s)}
           for e, s in st.items() if domotica.da_stanza(e, s)]
    ent.sort(key=lambda x: x["entity_id"])
    stanze = [{"id": r.get("id"), "nome": r.get("name") or r.get("id"), "piano": r.get("piano")} for r in domotica.tutte_le_stanze(piano)]
    return {"legami": d["legami"], "entita": ent, "stanze": stanze}

@app.post("/api/casa/legami")
async def casa_lega(request: Request):
    _casa(request); b = await request.json(); d = domotica.leggi()
    for x in b.get("lista") or [b]:
        eid = str(x.get("entity_id") or "")
        if not eid: continue
        if x.get("stanza"): d["legami"][eid] = {"stanza": x["stanza"], "mobile": x.get("mobile")}
        else: d["legami"].pop(eid, None)
    domotica.scrivi(d); return {"ok": True, "legami": len(d["legami"])}

@app.get("/api/casa/proposte_legami")
def casa_proposte(request: Request):
    _casa(request); return {"proposte": domotica.proponi_legami(load_plan())}

@app.post("/api/casa/simula")
async def casa_simula(request: Request):
    _casa(request); b = await request.json(); return {"esiti": domotica.simula(b.get("azioni") or [])}

@app.post("/api/casa/esegui")
async def casa_esegui(request: Request):
    _casa(request); b = await request.json(); _confermato(b); return {"esiti": domotica.esegui(b.get("azioni") or [])}

@app.get("/api/casa/scene")
def casa_scene(request: Request):
    _casa(request); d = domotica.leggi(); return {"scene": d["scene"], "scene_casa": d["scene_casa"]}

@app.post("/api/casa/scene")
async def casa_scatta(request: Request):
    _casa(request); b = await request.json()
    sid, n = domotica.scatta_scena(str(b.get("nome") or "Scena")[:40], b.get("stanza")); return {"ok": True, "id": sid, "entita": n}

@app.post("/api/casa/scene_casa")
async def casa_fondi(request: Request):
    _casa(request); b = await request.json(); d = domotica.leggi(); sid = uuid.uuid4().hex[:10]
    d["scene_casa"][sid] = {"nome": str(b.get("nome") or "Scena di casa")[:40], "scene": [s for s in b.get("scene") or [] if s in d["scene"]]}
    domotica.scrivi(d); return {"ok": True, "id": sid}

@app.post("/api/casa/scene/{sid}/applica")
async def casa_applica(sid: str, request: Request):
    _casa(request); b = await request.json(); d = domotica.leggi()
    if sid in d["scene"]: az = domotica.azioni_scena(d["scene"][sid]["entita"])
    elif sid in d["scene_casa"]: az = domotica.azioni_scena_casa(sid)
    else: raise HTTPException(404, "scena sconosciuta")
    if b.get("solo_simula"): return {"esiti": domotica.simula(az)}
    _confermato(b); return {"esiti": domotica.esegui(az)}

@app.delete("/api/casa/scene/{sid}")
def casa_togli_scena(sid: str, request: Request):
    _casa(request); d = domotica.leggi(); d["scene"].pop(sid, None); d["scene_casa"].pop(sid, None)
    for sc in d["scene_casa"].values(): sc["scene"] = [x for x in sc["scene"] if x != sid]
    domotica.scrivi(d); return {"ok": True}

@app.get("/api/casa/abitudini")
def casa_abitudini(request: Request, giorni: int = 14):
    _casa(request); loc = get_location()
    return {"proposte": domotica.abitudini(max(7, min(30, giorni)), loc.get("latitude"), loc.get("longitude"), loc.get("time_zone") or "UTC")}

@app.post("/api/casa/abitudini/crea")
async def casa_crea_abitudine(request: Request):
    _casa(request); b = await request.json(); _confermato(b)
    try: aid = domotica.crea_automazione(b["proposta"], b.get("nome"))
    except (ValueError, KeyError) as e: raise HTTPException(400, str(e))
    d = domotica.leggi(); d["abitudini_scartate"].append(b["proposta"].get("id")); domotica.scrivi(d)
    return {"ok": True, "automazione": aid}

@app.post("/api/casa/abitudini/scarta")
async def casa_scarta(request: Request):
    _casa(request); b = await request.json(); d = domotica.leggi()
    if b.get("id") and b["id"] not in d["abitudini_scartate"]: d["abitudini_scartate"].append(b["id"])
    domotica.scrivi(d); return {"ok": True}

@app.get("/api/casa/impostazioni")
def casa_imp(request: Request):
    _casa(request); d = domotica.leggi()
    return {"volume": d["volume"], "allarme": d["allarme"], "regole": d["regole"], "vuota": d.get("vuota") or {}, "permessi": domotica.PERMESSI}

@app.post("/api/casa/impostazioni")
async def casa_imp_salva(request: Request):
    _casa(request); b = await request.json(); d = domotica.leggi()
    for k in ("volume", "allarme", "regole", "vuota"):
        if isinstance(b.get(k), dict): d[k].update(b[k])
    domotica.scrivi(d); return {"ok": True}

@app.get("/api/casa/allarme")
def casa_allarme(request: Request):
    _casa(request); g = domotica.guardiano; a = g.allarme
    info = None
    if a:
        info = {k: a[k] for k in ("cosa", "stanza", "frase", "prova", "inizio")}; info["luci"] = len(a["luci"])
    return {"attivo": bool(a), "allarme": info, "registro": g.registro[-20:], "abbassati": g.abbassati}

@app.post("/api/casa/allarme/prova")
async def casa_allarme_prova(request: Request):
    # a secco: si prepara tutto (frase, stanza, luci) senza toccare luci, casse o notifiche, e si ferma subito
    _casa(request); b = await request.json(); d = domotica.leggi(); st = domotica.stati()
    sens = b.get("sensore") or ((d["allarme"].get("sensori") or [None])[0])
    g = domotica.guardiano
    g.scatta(sens or "", st, d, prova=True); a = g.allarme; g.ferma("fine della prova")
    return {"frase": a["frase"], "stanza": a["stanza"], "luci": list(a["luci"].keys()), "casse": d["allarme"].get("casse"), "notifiche": d["allarme"].get("notifiche")}

@app.post("/api/casa/allarme/prova_luce")
async def casa_allarme_luce(request: Request):
    # una sola luce, 6 secondi di rosso lento, poi com'era: per vedere l'effetto senza svegliare la casa
    _casa(request); b = await request.json(); _confermato(b); eid = str(b.get("entity_id") or "")
    if not eid.startswith("light."): raise HTTPException(400, "serve una luce")
    s = domotica.stati().get(eid)
    if not s: raise HTTPException(404, "luce sconosciuta")
    def giro():
        try:
            for i in range(6):
                domotica.servizio("light", "turn_on", {"entity_id": eid, "rgb_color": [255, 0, 0], "brightness": 255 if i % 2 == 0 else 40, "transition": 0.8}); time.sleep(1)
        finally:
            domotica.ripristina_luce(eid, s)
    threading.Thread(target=giro, daemon=True).start()
    return {"ok": True}

@app.post("/api/casa/allarme/ferma")
def casa_allarme_ferma(request: Request):
    _casa(request); domotica.guardiano.ferma("annullato dalla pagina"); return {"ok": True}

@app.get("/domotica.js")
def domotica_js(): return FileResponse("/domotica.js", media_type="text/javascript", headers={"Cache-Control": "no-cache"})

# ---------------------------------------------------------------- il mio avatar (parlare)
#
# (0.28) PARLARE COL PROPRIO AVATAR. La pagina «🧍 Il mio avatar» (ritratto.js) mostra il personaggio del gioco da
# vicino e ci si parla, per scritto o a voce. Le risposte le dà l'AGENTE DI CONVERSAZIONE di Home Assistant (Assist,
# o quello scelto in avatar_agente): così l'avatar può anche comandare la casa, con le regole e i permessi di HA. Il
# filo del discorso è il conversation_id che HA restituisce. La VOCE: il TTS di HA (avatar_tts, o il primo che
# risponde), scaricato QUI e dato al browser: suona solo nella pagina, mai sugli altoparlanti di casa.
AVATAR_AGENTE = str(addon_options().get("avatar_agente") or "").strip()
AVATAR_TTS = str(addon_options().get("avatar_tts") or "").strip()
_tts_buono = [AVATAR_TTS] if AVATAR_TTS else []
_audio = {}                    # id → (byte, tipo, quando): le ultime risposte, per 10 minuti

@app.post("/api/avatar/parla")
async def avatar_parla(request: Request):
    _casa(request); b = await request.json()
    testo = str(b.get("testo") or "").strip()[:500]
    if not testo: raise HTTPException(400, "niente da dire")
    corpo = {"text": testo, "language": "it"}
    if b.get("conversation_id"): corpo["conversation_id"] = str(b["conversation_id"])[:80]
    if AVATAR_AGENTE: corpo["agent_id"] = AVATAR_AGENTE
    try:
        r = domotica.ha("POST", "/conversation/process", corpo, timeout=60)
    except Exception as e:
        raise HTTPException(502, "l'agente di conversazione non risponde: " + str(e)[:120])
    resp = (r or {}).get("response") or {}
    parla = ((resp.get("speech") or {}).get("plain") or {}).get("speech") or ""
    return {"risposta": parla, "tipo": resp.get("response_type"), "conversation_id": r.get("conversation_id"),
            "continua": bool(r.get("continue_conversation"))}

@app.post("/api/avatar/voce")
async def avatar_voce(request: Request):
    # la risposta detta: il primo motore TTS che risponde (quello delle opzioni, poi gli altri di HA); con l'italiano se
    # lo sa, se no con la lingua sua. Niente voce = la pagina usa la sintesi del browser
    _casa(request); b = await request.json()
    testo = str(b.get("testo") or "").strip()[:800]
    if not testo: raise HTTPException(400, "niente da dire")
    candidati = list(_tts_buono)
    try:
        candidati += [e for e in domotica.stati() if e.startswith("tts.") and e not in candidati]
    except Exception:
        pass
    for eng in candidati[:6]:
        for lingua in ("it", None):
            try:
                d = {"engine_id": eng, "message": testo}
                if lingua: d["language"] = lingua
                r = domotica.ha("POST", "/tts_get_url", d, timeout=60)
                percorso = r.get("path") or ("/api/" + r["url"].split("/api/", 1)[1])
                req = urllib.request.Request("http://supervisor/core" + percorso, headers={"Authorization": "Bearer " + os.environ.get("SUPERVISOR_TOKEN", "")})
                with urllib.request.urlopen(req, timeout=60) as x:
                    dati, tipo = x.read(), x.headers.get("Content-Type") or "audio/mpeg"
                if len(dati) < 500: continue
                if eng not in _tts_buono: _tts_buono.insert(0, eng)
                ora = time.time()
                for k in [k for k, v in _audio.items() if ora - v[2] > 600]: _audio.pop(k, None)
                aid = uuid.uuid4().hex
                _audio[aid] = (dati, tipo, ora)
                return {"url": "api/avatar/audio/" + aid, "motore": eng}
            except Exception:
                continue
    return {"url": None, "motore": None}

@app.get("/api/avatar/audio/{aid}")
def avatar_audio(aid: str, request: Request):
    _casa(request); v = _audio.get(aid)
    if not v: raise HTTPException(404, "audio scaduto")
    return Response(content=v[0], media_type=v[1], headers={"Cache-Control": "private, max-age=600"})

@app.get("/ritratto.js")
def ritratto_js(): return FileResponse("/ritratto.js", media_type="text/javascript", headers={"Cache-Control": "no-cache"})

# (0.31) IL RITRATTO DA SOLO: la pagina che la card della dashboard mette in un riquadro (attraverso l'ingress)
RITRATTO_HTML = """<!doctype html><html lang="it"><head>
<script type="importmap">{"imports":{"three":"./vendor/three.module.js","three/addons/":"./vendor/jsm/"}}</script>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Il mio avatar</title><style>
:root{--bg:#0d1117;--card:#161b22;--bd:#30363d;--fg:#e6edf3;--mut:#8b949e;--acc:#f0a500;--ok:#3fb950;--bad:#f85149}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:transparent;color:var(--fg);padding:6px}
.mut{color:var(--mut);font-size:.85rem}.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
button{background:var(--acc);color:#111;border:0;border-radius:8px;padding:7px 10px;font-weight:700;cursor:pointer;font-size:.8rem}
button.sec{background:#30363d;color:var(--fg)}input{background:#0d1117;color:var(--fg);border:1px solid var(--bd);border-radius:8px;padding:7px}
.bad{color:var(--bad)}a{color:var(--acc)}
</style></head><body><div id="c"></div>
<script type="module">import { apriRitratto } from './ritratto.js'; apriRitratto(document.getElementById('c'));</script></body></html>"""

@app.get("/ritratto", response_class=HTMLResponse)
def ritratto_pagina(request: Request):
    _solo_ingress(request); return HTMLResponse(RITRATTO_HTML)

@app.get("/eclipse-casa-viva-card.js")
def card_js(): return FileResponse("/eclipse_card.js", media_type="text/javascript", headers={"Cache-Control": "no-cache"})

# (0.31) LA CARD IN DASHBOARD: all'avvio l'add-on copia la card in /config/www/eclipse/ (servita da HA come
# /local/eclipse/...) e la registra fra le risorse di Lovelace (websocket di HA col gettone del Supervisor). Se la
# dashboard è in modalità YAML le risorse non si registrano da qui: la pagina lo dice e mostra la riga da aggiungere
CARD_VERSIONE = "0.31.2"
CARD_URL = "/local/eclipse/eclipse-casa-viva-card.js?v=" + CARD_VERSIONE
CARD_STATO = {"copiata": False, "risorsa": None, "errore": None}

def _installa_card():
    import shutil, asyncio
    try:
        dest = "/homeassistant/www/eclipse"
        if os.path.isdir("/homeassistant"):
            os.makedirs(dest, exist_ok=True)
            shutil.copyfile("/eclipse_card.js", dest + "/eclipse-casa-viva-card.js")
            CARD_STATO["copiata"] = True
        else:
            CARD_STATO["errore"] = "la cartella di configurazione di HA non è montata"
            return
    except Exception as e:
        CARD_STATO["errore"] = "copia: " + str(e)[:120]; return
    try:
        import websockets  # noqa
    except Exception:
        websockets = None
    async def registra():
        import websockets as ws
        tok = os.environ.get("SUPERVISOR_TOKEN", "")
        async with ws.connect("ws://supervisor/core/websocket", max_size=8_000_000) as c:
            await c.recv()
            await c.send(json.dumps({"type": "auth", "access_token": tok})); r = json.loads(await c.recv())
            if r.get("type") != "auth_ok": raise RuntimeError("websocket di HA: " + str(r.get("type")))
            await c.send(json.dumps({"id": 1, "type": "lovelace/resources"})); r = json.loads(await c.recv())
            if not r.get("success"): raise RuntimeError("le risorse di Lovelace non si leggono (dashboard in YAML?)")
            vecchie = [x for x in r.get("result") or [] if "/local/eclipse/eclipse-casa-viva-card.js" in x.get("url", "")]
            if vecchie and vecchie[0].get("url") == CARD_URL:
                return "già registrata"
            if vecchie:
                await c.send(json.dumps({"id": 2, "type": "lovelace/resources/update", "resource_id": vecchie[0]["id"], "res_type": "module", "url": CARD_URL}))
            else:
                await c.send(json.dumps({"id": 2, "type": "lovelace/resources/create", "res_type": "module", "url": CARD_URL}))
            r = json.loads(await c.recv())
            if not r.get("success"): raise RuntimeError("registrazione: " + str(r.get("error")))
            return "aggiornata" if vecchie else "registrata"
    try:
        CARD_STATO["risorsa"] = asyncio.run(registra())
    except Exception as e:
        CARD_STATO["errore"] = str(e)[:200]

@app.on_event("startup")
def _avvia_card():
    import threading
    threading.Thread(target=_installa_card, daemon=True).start()

@app.get("/api/card/stato")
def card_stato(request: Request):
    _solo_ingress(request); return {**CARD_STATO, "url": CARD_URL}

def pagina() -> str:
    """La pagina con dentro le misure vere della tela.

    LE MISURE NON POSSONO STARE SOLO NEL JAVASCRIPT: la viewBox dell'SVG e'
    attributo HTML, e il browser la legge prima che qualunque script parta. Si
    scrivono qui, una volta, e il resto del disegno le rilegge dalle due
    costanti - cosi' non c'e' un numero della tela sparso in otto punti che
    prima o poi divergono."""
    misure = ('<script>const PW=' + str(PIANO_W) + ',PH=' + str(PIANO_H)
              + ',BLOCCO=' + str(BLOCCO) + ';</script>')
    return (HTML
            .replace('${PW}', str(PIANO_W))
            .replace('${PH}', str(PIANO_H), 1)
            .replace('</head>', misure + '</head>', 1))


@app.get("/", response_class=HTMLResponse)
def index(): return pagina()

# ---------------------------------------------------------------- incorpora
#
# LA CASA NON SI RIDISEGNA ALTROVE, SI INCORPORA.
#
# WordPress ha bisogno della planimetria in cima a ogni pagina-stanza. La
# strada breve sarebbe rifare il disegno di la' partendo dal JSON: due
# renderer per la stessa casa, che il giorno dopo mostrano cose diverse -
# uno sa dei poligoni e l'altro no, uno disegna le finestre e l'altro le
# dimentica. Qui invece si serve LA STESSA pagina, con gli attrezzi
# dell'editor nascosti: quello che vedi incorporato e' esattamente quello
# che vedi qui, per costruzione.
#
# LA CHIAVE VA NELL'INDIRIZZO, e non e' pigrizia: un <iframe> non puo'
# mandare intestazioni. E' lo stesso motivo per cui esiste gia' l'export con
# X-API-Key ma serve un'altra porta per chi incorpora.

def _chiave_ok(chiave: str) -> bool:
    if not API_KEY:
        raise HTTPException(403, "incorporazione disabilitata: imposta 'api_key' nelle opzioni dell'add-on")
    if not hmac.compare_digest(chiave or "", API_KEY):
        raise HTTPException(401, "api key non valida")
    return True


@app.get("/api/rooms")
def api_rooms(key: str = "", x_api_key: str = Header(default="")):
    """Le stanze configurate, in forma corta: serve a chi deve solo sapere
    QUALI ci sono - per esempio per creare una pagina per ognuna - senza
    scaricarsi tutta la planimetria con mobili, foto e modelli."""
    _chiave_ok(key or x_api_key)
    piano = load_plan()
    fuori = []
    for r in piano.get("rooms", []):
        if r.get("poly"):
            xs = [p[0] for p in r["poly"]]; ys = [p[1] for p in r["poly"]]
            box = {"x": min(xs), "y": min(ys), "w": max(xs) - min(xs), "h": max(ys) - min(ys)}
        else:
            box = {"x": r.get("x", 0), "y": r.get("y", 0), "w": r.get("w", 0), "h": r.get("h", 0)}
        fuori.append({
            "id": r.get("id", ""),
            "nome": r.get("name", ""),
            "riquadro": box,
            "disegnata": bool(r.get("poly")),
            "finestre": len(r.get("windows") or []),
        })
    return {"stanze": fuori, "quante": len(fuori)}


@app.get("/embed", response_class=HTMLResponse)
def embed(key: str = "", room: str = ""):
    """La planimetria da mettere dentro una pagina, senza l'editor intorno."""
    _chiave_ok(key)
    # SOLA LETTURA, ED E' LA META' CHE CONTA. Senza, da una pagina di
    # WordPress si potrebbe spostare una stanza per sbaglio con un dito, e la
    # casa di tutti cambierebbe da un posto che sembrava una figura.
    stile = """<style id="vwgk-embed">
      body > h1, body > h2, body > #loc, body > #eclipse { display: none !important; }
      body { padding: 0 !important; margin: 0 !important; background: transparent !important; }
      .card { border: 0 !important; margin: 0 !important; padding: 0 !important; background: transparent !important; }
      .card > .row, #sel, #collWarn { display: none !important; }
      #planwrap svg { pointer-events: none !important; }
    </style>"""
    marca = '<script>window.VWGK_EMBED=' + json.dumps({"room": room}) + ';</script>'
    return pagina().replace("</head>", stile + marca + "</head>", 1)

HTML = r"""<!doctype html><html lang="it"><head>
<script type="importmap">{"imports":{"three":"./vendor/three.module.js","three/addons/":"./vendor/jsm/"}}</script>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Eclipse Finder</title><style>
:root{--bg:#0d1117;--card:#161b22;--bd:#30363d;--fg:#e6edf3;--mut:#8b949e;--acc:#f0a500;--ok:#3fb950;--bad:#f85149;
--d-int:#58a6ff;--d-bal:#3fb950;--d-est:#d29922;--d-main:#f85149}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:var(--bg);color:var(--fg);padding:12px;max-width:960px;margin:auto}
h1{font-size:1.35rem;margin:.2rem 0 .6rem}h2{font-size:1rem;margin:1rem 0 .5rem;color:var(--acc)}
.card{background:var(--card);border:1px solid var(--bd);border-radius:12px;padding:12px;margin-bottom:10px}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.badge{padding:2px 8px;border-radius:20px;font-size:.72rem;font-weight:600}
.ok{background:rgba(63,185,80,.15);color:var(--ok)}.bad{background:rgba(248,81,73,.15);color:var(--bad)}
.mut{color:var(--mut);font-size:.85rem}small{color:var(--mut)}
button{background:var(--acc);color:#111;border:0;border-radius:8px;padding:9px 12px;font-weight:700;cursor:pointer;font-size:.85rem}
button.sec{background:transparent;color:var(--fg);border:1px solid var(--bd)}button:disabled{opacity:.4}
input,select{background:#0d1117;border:1px solid var(--bd);color:var(--fg);border-radius:8px;padding:9px;font-size:.9rem}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}@media(max-width:560px){.grid{grid-template-columns:1fr}}
.big{font-size:1.4rem;font-weight:800}.dir{font-size:1.9rem;font-weight:800;color:var(--acc)}
#planwrap{position:relative;background:#0b0f14;border:1px solid var(--bd);border-radius:10px;overflow:hidden;touch-action:none}
svg text{user-select:none}
.viewwrap{position:relative;display:inline-block;max-width:100%;margin-top:8px}
.viewwrap img{max-width:100%;border-radius:8px;display:block}
.sun{position:absolute;width:42px;height:42px;transform:translate(-50%,-50%);pointer-events:none;filter:drop-shadow(0 0 6px #000)}
.step{display:none}.step.on{display:block}
.livehdg{font-size:2.2rem;font-weight:800;color:var(--acc);text-align:center}
.legend span{display:inline-flex;align-items:center;gap:4px;margin-right:10px;font-size:.78rem}
.dot{width:11px;height:11px;border-radius:3px;display:inline-block}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;padding:14px;z-index:9}
.modal.on{display:flex}.modalcard{background:var(--card);border:1px solid var(--bd);border-radius:12px;padding:16px;max-width:460px;width:100%;max-height:90vh;overflow:auto}
model-viewer{--poster-color:transparent}
</style>
<script type="module" src="vendor/model-viewer.min.js"></script>
</head><body>
<h1>🌒 Eclipse Finder</h1>
<div id="loc" class="mut">Carico…</div>
<h2>Prossima eclissi</h2>
<div id="eclipse" class="grid"></div>

<h2>Planimetria di casa</h2>
<div class="card">
  <div class="row" style="justify-content:space-between">
    <div class="row">
      <button onclick="startAddRoom()">➕ Stanza</button>
      <button class="sec" onclick="startDrawRoom()">✏️ Disegna</button>
      <button class="sec" id="addWinBtn" onclick="startAddWin()">🪟 Finestra</button>
      <button class="sec" id="addDoorBtn" onclick="startAddDoor()">🚪 Porta</button>
      <button class="sec" onclick="startAddFurn()">🛋️ Mobile</button>
      <button class="sec" onclick="openHouse3D()">🏠 Casa 3D</button>
      <button class="sec" onclick="toggleExport()">⬇️ Esporta</button>
    </div>
    <span class="mut" id="mode"></span>
  </div>
  <div class="legend" style="margin:8px 0">
    <span>🏠 interna</span><span>🌿 balcone</span><span>☀️ terrazzo</span><span>🌳 giardino</span><span>🪜 vano scale</span>
    <span>·</span><span>🪟 finestra</span><span>👁️ vista</span><span>🛋️ mobile</span>
    <span>·</span><span class="mut">Porte:</span>
    <span><span class="dot" style="background:var(--d-int)"></span>int</span>
    <span><span class="dot" style="background:var(--d-bal)"></span>balc</span>
    <span><span class="dot" style="background:var(--d-est)"></span>est</span>
    <span><span class="dot" style="background:var(--d-main)"></span>princ</span>
  </div>
  <div class="row" style="margin:4px 0"><span class="mut">🧱 Altezza piano (3D):</span><input id="floorH" type="number" min="80" max="600" onchange="setFloorHeight(this.value)" style="width:80px"><button class="sec" onclick="autoStackLevels()">⭘ Impila livelli</button><span class="mut">imposta la quota Z delle stanze impilate (soppalchi/piani)</span></div>
  <div class="row" style="margin:4px 0;flex-wrap:wrap"><span class="mut">🏢 Piani:</span><span id="pianiBtn" class="row" style="gap:4px;flex-wrap:wrap"></span><button class="sec" onclick="aggiungiPiano()">＋ piano</button><button class="sec" onclick="togliPiano()">－ piano</button><button class="sec" onclick="questoMio()">★ questo è il mio</button><button class="sec" onclick="clonaMio()">⧉ clona il mio su tutti i piani</button></div>
  <div class="mut" id="pianiNota" style="margin:0 0 4px">Contati dal basso, piano terra = 1. Un piano senza planimetria è uguale al mio. Le scale sono lo spazio comune: uguali su tutti i piani.</div>
  <input id="pianiEd" type="hidden"><input id="pianoMio" type="hidden">
  <!-- I BOTTONI NON SI SCRIVONO QUI. Erano un elenco a mano gemello della
       tendina di modifica, ed e' gia' successo che un tipo nuovo finisse solo
       da una parte. Li riempie renderRoomPicker() dall'elenco unico. -->
  <div id="roomPicker" class="row" style="display:none;margin:6px 0"></div>
  <div id="drawBtns" class="row" style="display:none;margin:6px 0">
    <span class="mut">Disegno stanza — tocca i vertici:</span>
    <button onclick="finishDraw()">✔️ Chiudi forma</button>
    <button class="sec" onclick="undoDraw()">↶ Annulla punto</button>
    <button class="sec" onclick="cancelDraw()">✖️ Esci</button>
  </div>
  <div id="doorPicker" class="row" style="display:none;margin:6px 0">
    <span class="mut">Tipo porta:</span>
    <button class="sec" onclick="pickDoor('interna')">🚪 interna</button>
    <button class="sec" onclick="pickDoor('balcone')">🌿 balcone</button>
    <button class="sec" onclick="pickDoor('esterna')">🚶 esterna</button>
    <button class="sec" onclick="pickDoor('principale')">🏠 principale</button>
  </div>
  <div id="winPicker" class="row" style="display:none;margin:6px 0">
    <span class="mut">Tipo finestra:</span>
    <button class="sec" onclick="pickWin('finestra')">🪟 finestra</button>
    <button class="sec" onclick="pickWin('portafinestra')">🚪️ portafinestra</button>
    <button class="sec" onclick="pickWin('alta')">⬆️ alta</button>
    <span class="mut">·</span>
    <button class="sec" onclick="$('#winPicker').style.display='none';startAddWindow()">📷 con foto (eclissi)</button>
  </div>
  <div id="furnPicker" class="row" style="display:none;margin:6px 0"></div>
  <div id="exportPicker" class="row" style="display:none;margin:6px 0">
    <span class="mut">Esporta planimetria 2D:</span>
    <button class="sec" onclick="exportPNG()">🖼️ PNG</button>
    <button class="sec" onclick="exportSVG()">▦ SVG</button>
    <span class="mut">·</span>
    <span class="mut">🧭 nord</span>
    <input id="nordDeg" type="number" min="0" max="359" step="5" style="width:70px"
           onchange="setNord(this.value)" title="Di quanto e' girato il disegno rispetto al nord vero">
    <button class="sec" onclick="setNord(nord()+90)" title="Gira il nord di un quarto">↻ nord 90°</button>
    <button class="sec" onclick="attaccaStanze()" title="Allinea le stanze disegnate a mano e mostra le mura">🧲 Attacca stanze</button>
    <button class="sec" onclick="ruotaTutto()" title="Gira tutta la casa di un quarto, lasciando ferma la geografia">⟳ gira la casa</button>
    <span class="mut">· il 3D (.glb) si esporta dalla vista 🏠 Casa 3D</span>
  </div>
  <div id="planwrap"><svg id="plan" width="100%" viewBox="0 0 ${PW} ${PH}" style="display:block"></svg></div>
  <div id="sel" class="mut" style="margin-top:8px">Tocca una stanza per selezionarla. Trascina per spostare, maniglia ◢ per ridimensionare. Stanze disegnate: trascina i vertici ◆. Usa ✏️ Disegna per forme non rettangolari.</div>
  <div id="collWarn" style="margin-top:4px"></div>
</div>

<div id="roomPanel"></div>
<div id="furnPanel"></div>

<h2>Casa viva</h2>
<div class="card" id="casaViva"><div class="mut">Carico lo stato della casa…</div></div>

<!-- MODAL: nuovo template mobile -->
<div id="tplModal" class="modal"><div class="modalcard">
  <b>Nuovo template mobile</b>
  <div class="mut" style="margin:8px 0">Nome e dimensioni dei lati (unità del piano). Lo costruisci una volta e poi lo piazzi quante volte vuoi.</div>
  <input id="tplName" placeholder="Es. Scrivania / Armadio 3 ante" style="width:100%">
  <div class="row" style="margin-top:8px"><input id="tplW" type="number" min="10" placeholder="larghezza" style="flex:1"><input id="tplH" type="number" min="10" placeholder="profondità" style="flex:1"></div>
  <div class="row" style="margin-top:8px"><label class="sec" style="padding:9px;border:1px solid var(--bd);border-radius:8px;cursor:pointer">🧊 Modello 3D (.glb) opz.<input id="tplModel" type="file" accept=".glb,.gltf,model/gltf-binary" style="display:none" onchange="tplModelPick(this)"></label><span id="tplModelName" class="mut"></span></div>
  <div class="mut" style="margin-top:4px">Esporta da Blender in glTF 2.0 (.glb), senza compressione Draco per il 3D offline.</div>
  <div class="row" style="justify-content:flex-end;margin-top:10px"><button class="sec" onclick="closeTpl()">Annulla</button><button onclick="saveTpl()">💾 Salva template</button></div>
</div></div>

<!-- MODAL: casa in 3D -->
<div id="house3dModal" class="modal"><div class="modalcard" style="max-width:960px;width:96vw">
  <div class="row" style="justify-content:space-between;flex-wrap:nowrap">
    <b>🏠 Casa in 3D</b>
    <div class="row"><span class="mut">Muri</span><input id="h3h" type="range" min="150" max="400" value="250" oninput="setH3Height(this.value)" style="width:100px"><label class="mut" style="display:flex;gap:4px;align-items:center"><input type="checkbox" id="h3tutto" onchange="buildHouse3D()">🏢 tutto il palazzo</label><button class="sec" id="h3avatar" onclick="avatarCommuta()" title="Entra con l'account del gioco e cammina nella casa col tuo personaggio">🧍 Il mio avatar</button><button class="sec" onclick="giocoEsci()" title="Scollega account del gioco (per entrare con un altro)">⏻ Esci dal gioco</button><button class="sec" onclick="mandaCasaAlGioco()" title="Porta questa planimetria nel gioco: l'Architetto ne fa una pergamena che costruisce la casa sul tuo lotto">🏗️ Manda al gioco</button><button class="sec" onclick="exportGLB()">⬇️ .glb</button><button class="sec" onclick="closeHouse3D()">✖️ Chiudi</button></div>
  </div>
  <div style="position:relative"><div id="house3dBox" style="margin-top:8px;height:72vh;background:#0b0f14;border-radius:8px;overflow:hidden"></div>
  <form id="giocoLogin" onsubmit="giocoAccedi(event)" style="display:none;position:absolute;top:18px;right:12px;width:250px;padding:12px;border-radius:12px;background:rgba(22,27,34,.82);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.18)">
    <b>Another Wilde Web</b><div class="mut" style="font-size:12px;margin:4px 0 8px">Entra col tuo account del gioco (nome e password di Minecraft). La password non si salva.</div>
    <input id="giocoNome" placeholder="Nome di gioco" autocomplete="username" maxlength="16" style="width:100%;margin-bottom:6px"><input id="giocoPw" type="password" placeholder="Password" autocomplete="current-password" maxlength="64" style="width:100%;margin-bottom:8px">
    <div class="row" style="justify-content:space-between"><button type="submit">Entra</button><button type="button" class="sec" onclick="giocoChiudi()">Annulla</button></div><div id="giocoErr" class="bad" style="font-size:12px;margin-top:6px"></div>
  </form></div>
  <div id="house3dInfo" class="mut" style="margin-top:6px"></div>
</div></div>

<!-- MODAL: vista 3D a schermo intero -->
<div id="modelModal" class="modal"><div class="modalcard" style="max-width:680px">
  <div class="row" style="justify-content:space-between"><b>🧊 Vista 3D</b><button class="sec" onclick="closeModel3D()">✖️ Chiudi</button></div>
  <div id="model3dBox" style="margin-top:8px"></div>
</div></div>

<!-- MODAL: aggiungi finestra -->
<div id="muriModal" class="modal"><div class="modalcard" style="max-width:720px;width:96vw">
  <div class="row" style="justify-content:space-between"><b>🧱 Mura</b>
    <button class="sec" onclick="$('#muriModal').classList.remove('on')">✖️</button></div>
  <div id="muriEsito" class="mut" style="margin:6px 0"></div>
  <div class="row" style="margin:6px 0"><span class="mut">Spessore a tutte:</span>
    <input id="muroTutti" type="number" min="2" max="60" step="1" style="width:80px" placeholder="8">
    <button class="sec" onclick="spessoreATutti()">Applica</button></div>
  <div id="muriLista"></div>
</div></div>
<div id="winModal" class="modal"><div class="modalcard">
  <b id="winTitle">Nuova finestra</b>
  <div class="step on" data-s="1"><div class="mut" style="margin:8px 0">In quale stanza?</div>
    <select id="wRoom" style="width:100%" onchange="updateWinLabels()"></select>
    <div class="row" style="margin-top:8px"><input id="wRoomNew" placeholder="…oppure crea nuova stanza (interna)" style="flex:1" oninput="updateWinLabels()"></div>
    <div class="row" style="justify-content:flex-end;margin-top:10px"><button class="sec" onclick="closeWin()">Annulla</button><button onclick="winStep(2)">Avanti →</button></div>
  </div>
  <div class="step" data-s="2"><div class="mut" style="margin:8px 0" id="wNameLbl">Nome finestra</div>
    <input id="wName" placeholder="Es. Finestra grande / Portafinestra" style="width:100%">
    <div class="row" style="justify-content:space-between;margin-top:10px"><button class="sec" onclick="winStep(1)">←</button><button onclick="winStep(3)">Avanti →</button></div>
  </div>
  <div class="step" data-s="3"><div class="mut" style="margin:8px 0" id="wDirLbl">Direzione (punta verso la finestra)</div>
    <div class="mut" style="margin:-4px 0 8px">Tieni il telefono come per fotografare la vista: catturo direzione <b>e</b> inclinazione.</div>
    <div class="livehdg" id="wLive">—°</div><div class="mut" style="text-align:center" id="wLivec"></div>
    <div class="mut" style="text-align:center">inclinazione (centro foto): <b id="wLiveAlt">—°</b></div>
    <div class="row" style="margin-top:8px"><button class="sec" onclick="enableCompass()">🧭 Bussola</button><button onclick="lockHeading()">🔒 Blocca mira</button></div>
    <div class="mut" style="text-align:center;margin:8px 0">— oppure a mano —</div>
    <div class="row"><input id="wDeg" type="number" min="0" max="360" placeholder="direzione 0–360 (N0 E90 S180 O270)" style="flex:1"></div>
    <div class="row" style="margin-top:6px"><input id="wAlt" type="number" min="-80" max="80" placeholder="altezza centro (° opz, 0=orizzonte)" style="flex:1"><button class="sec" onclick="useManual()">Usa</button></div>
    <div id="wcerr" class="mut"></div>
    <div class="row" style="justify-content:space-between;margin-top:10px"><button class="sec" onclick="winStep(2)">←</button><button id="wS3" disabled onclick="winStep(4)">Avanti →</button></div>
  </div>
  <div class="step" data-s="4"><div class="mut" style="margin:8px 0">Foto della vista (opzionale)</div>
    <label class="sec" style="padding:10px;border:1px solid var(--bd);border-radius:8px;cursor:pointer;display:inline-block">📷 Scatta/scegli<input id="wPhoto" type="file" accept="image/*" capture="environment" style="display:none" onchange="wPhotoPick(this)"></label>
    <span id="wPname" class="mut"></span><div id="wPrev"></div>
    <div class="row" style="margin-top:8px"><span class="mut">Campo visivo orizz.:</span><input id="wFov" type="number" min="20" max="140" value="66" style="width:80px"><span class="mut">°</span></div>
    <div id="wFovHint" class="mut" style="margin-top:4px"></div>
    <div class="row" style="justify-content:space-between;margin-top:10px"><button class="sec" onclick="winStep(3)">←</button><button id="wSave" onclick="saveWindow()">💾 Salva finestra</button></div>
  </div>
</div></div>

<script>
const $=s=>document.querySelector(s);
let ECL=null, PLAN={rooms:[]}, LIVE=null, LOCKED=null, WPHOTO=null, LIVEPITCH=null, LOCKEDALT=null, TPLMODEL=null;
let WEDIT=null; // finestra gia' piazzata a cui si sta agganciando la foto
let MODE=null; // 'door:<type>' | 'draw' | 'furn:<tpl>' | placement
let SEL=null;  // selected room id
let SELF=null; // selected furniture id
let DRAWPTS=[]; // vertici della stanza in disegno
const DEFAULT_TEMPLATES=[
  {id:'t_arm',name:'Armadio',w:110,h:55},
  {id:'t_single',name:'Letto singolo',w:90,h:200},
  {id:'t_double',name:'Letto matrimoniale',w:160,h:200},
];
const DCOL={interna:'var(--d-int)',balcone:'var(--d-bal)',esterna:'var(--d-est)',principale:'var(--d-main)'};
const ROOM_EMO={interna:'🏠',balcone:'🌿',terrazzo:'☀️',giardino:'🌳',scale:'🪜',pergola:'⛺'};
// I tipi di stanza, una volta sola: li leggono sia la tendina che modifica sia
// i bottoni che creano.
const TIPI_STANZA=[['interna','🏠 interna'],['balcone','🌿 balcone'],
  ['terrazzo','☀️ terrazzo'],['giardino','🌳 giardino'],['scale','🪜 vano scale esterno'],['scale_interne','🪜 vano scale interno / pianerottolo'],
  // LA PERGOLA E' ESTERNA MA E' CHIUSA. Un balcone in 3D prende ringhiere e
  // resta scoperto; una pergola - o veranda - e' un volume vero costruito
  // sopra al balcone: quattro muri e un tetto, con porte e finestre che ci si
  // possono mettere come in una stanza qualsiasi. Resta esterna perche' per
  // l'eclissi conta cosi': ci si sta fuori, e il cielo lo si vede da li'.
  ['pergola','⛺ pergola / veranda']];
function chiusaMaEsterna(r){return r&&r.subtype==='pergola'}
function isExt(r){return r&&r.kind==='esterna'}
function roomEmo(r){return ROOM_EMO[r.subtype]||(isExt(r)?'🌿':'🏠')}
function viewIcon(r){return isExt(r)?'👁️':'🪟'}
function viewNoun(r){return isExt(r)?'punto di osservazione':'finestra'}
function fmtLocal(iso){try{return new Date(iso).toLocaleString('it-IT',{dateStyle:'medium',timeStyle:'short'})}catch(e){return iso}}
function angdiff(a,b){return ((a-b+540)%360)-180}
// ---------------------------------------------------------------- il nord
//
// IL DISEGNO E LA GEOGRAFIA SONO DUE COSE DIVERSE, e finora coincidevano per
// forza: il nord era in alto e basta. Ma una casa la si disegna come viene
// comoda sullo schermo - larga invece che alta, girata per starci dentro - e
// quel disegno non ha nessun motivo di essere orientato come la realta'.
//
// PLAN.nord dice di quanto il disegno e' girato rispetto al nord vero. Le
// finestre continuano a portare l'azimut misurato; qui si somma, e ovunque si
// confronti col sole si usa azReale(). Cosi' si puo' raddrizzare la mappa
// senza rimisurare una finestra per una.
function nord(){return ((PLAN&&PLAN.nord)||0)}
// LA REGOLA DEI DUE ANGOLI, che finora era applicata a meta.
//
// L'angolo DEL DISEGNO e' quello che si vede sullo schermo, contato
// dall'alto. L'angolo VERO e' la direzione geografica. Fra i due c'e' il
// nord: vero = disegno + nord.
//
// Dentro la pianta ogni cosa e' salvata come angolo DEL DISEGNO, perche'
// e' li' che vive: una finestra guarda il suo muro, e non deve muoversi
// quando dico dove sta il nord - la casa non ha ruotato, l'ho solo
// orientata. L'eclissi invece arriva dal cielo gia' in angolo VERO, e per
// disegnarla va tolto il nord.
//
// Era proprio questo il difetto: la freccia dell'eclissi restava ferma
// mentre giravo la bussola, e le frecce delle finestre si muovevano. Le
// due cose sbagliate erano la stessa, presa dai due versi opposti.
function azReale(w){return ((( (w&&w.azimuth||0) + nord()) % 360) + 360) % 360}
function azDisegno(vero){return (((vero - nord()) % 360) + 360) % 360}
// GIRA TUTTA LA CASA, LASCIANDO FERMA LA GEOGRAFIA.
//
// A COSA SERVE. Una casa disegnata alta e stretta sta scomoda su uno schermo
// largo: girarla di un quarto la fa entrare. Ma girare il disegno senza
// toccare altro sposterebbe il sole - le finestre continuerebbero a dire
// "sud" mentre sullo schermo guardano da un'altra parte.
//
// PER QUESTO SI GIRA ANCHE IL NORD, dell'angolo opposto: il disegno cambia,
// la realta' no. Chi vuole cambiare DAVVERO l'orientamento geografico usa il
// campo del nord, che e' un'altra cosa e sta li' accanto.
//
// A QUARTI E NON A GRADI LIBERI: una stanza rettangolare girata di 37 gradi
// non e' piu' un rettangolo, e andrebbe convertita in poligono perdendo le
// maniglie di ridimensionamento. Un quarto per volta le lascia rettangoli.
function ruotaTutto(){
  const cx=PW/2, cy=PH/2;
  const gira=(x,y)=>[cx+(cy-y), cy-(cx-x)];   // -90 gradi attorno al centro
  for(const r of (PLAN.rooms||[])){
    if(r.poly&&r.poly.length){ r.poly=r.poly.map(([x,y])=>gira(x,y).map(Math.round)); }
    else {
      const [nx,ny]=gira(r.x, r.y+r.h);       // l'angolo che diventa il nuovo alto-sinistra
      const w=r.w, h=r.h;
      r.x=Math.round(nx); r.y=Math.round(ny); r.w=h; r.h=w;
    }
    for(const w of (r.windows||[])){ if(w.x!=null&&w.y!=null){const [a,b]=gira(w.x,w.y); w.x=Math.round(a); w.y=Math.round(b);} }
    for(const d of (r.doors||[])){ if(d.x!=null&&d.y!=null){const [a,b]=gira(d.x,d.y); d.x=Math.round(a); d.y=Math.round(b);} }
    clampRoom(r);
  }
  for(const f of (PLAN.furniture||[])){
    const [a,b]=gira(f.x,f.y); f.x=Math.round(a); f.y=Math.round(b);
    if(f.rot!=null) f.rot=((f.rot-90)%360+360)%360; else f.rot=270;
    clampFurn(f);
  }
  // Gli affacci sono angoli del disegno, e il disegno ha girato: girano con
  // lui. Senza questo, ogni finestra della casa cambiava direzione vera.
  for(const r of (PLAN.rooms||[])) for(const w of (r.windows||[]))
    if(w.azimuth!=null) w.azimuth=(((w.azimuth-90)%360)+360)%360;
  // il disegno e' girato di -90: il nord si gira di +90, e la geografia resta
  PLAN.nord=((nord()+90)%360+360)%360;
  render(); savePlan(); if(H3.run) buildHouse3D();
}

function setNord(v){PLAN.nord=(((+v||0)%360)+360)%360;render();savePlan();
  if(typeof buildHouse3D==='function'&&H3.run)buildHouse3D();}

function compass16(az){const d=["N","NNE","NE","ENE","E","ESE","SE","SSE","S","SSO","SO","OSO","O","ONO","NO","NNO"];return d[Math.round((az%360)/22.5)%16]}
function polar(cx,cy,r,az){const a=(az-90)*Math.PI/180;return [cx+r*Math.cos(a),cy+r*Math.sin(a)]}
function uid(){return Math.random().toString(36).slice(2,9)}

let saveT=null;
function savePlan(){clearTimeout(saveT);saveT=setTimeout(()=>fetch('api/plan',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(perSalvare())}),400)}

async function boot(){
  const d=await (await fetch('api/eclipse')).json();
  if(d.detail){$('#loc').innerHTML='<span class="bad">'+d.detail+'</span>'}else{ECL=d;
    $('#loc').innerHTML='📍 '+d.location.location_name+' — '+d.location.latitude.toFixed(4)+', '+d.location.longitude.toFixed(4);
    const box=$('#eclipse');box.innerHTML='';
    for(const k of['solar','lunar']){const e=d[k];const c=document.createElement('div');c.className='card';
      if(!e||e.error){c.innerHTML='<b>'+(k=='solar'?'Solare':'Lunare')+'</b><div class="mut">'+(e?e.error:'n/d')+'</div>';box.appendChild(c);continue}
      c.innerHTML=`<div class="row" style="justify-content:space-between"><b>${e.type}</b><span class="badge ${e.visible?'ok':'bad'}">${e.visible?'Visibile':'No'}</span></div>
      <div style="margin:4px 0"><span class="badge" style="background:rgba(240,165,0,.15);color:var(--acc)">${e.kind}</span> <span class="mut">· ${e.obscuration}%</span></div>
      <div class="big">${fmtLocal(e.peak_utc)}</div>
      <div class="row" style="margin-top:4px"><div><div class="dir">${e.compass}</div><small>az ${e.azimuth}°</small></div>
      <div style="margin-left:auto;text-align:right"><div class="big">${e.altitude}°</div><small>altezza</small></div></div>`;box.appendChild(c);}
  }
  PLAN=await (await fetch('api/plan')).json(); if(!PLAN.rooms)PLAN={rooms:[]};
  if(!PLAN.templates)PLAN.templates=DEFAULT_TEMPLATES.slice();
  if(!PLAN.furniture)PLAN.furniture=[];
  if(PLAN.floorHeight==null)PLAN.floorHeight=250;
  $('#floorH').value=PLAN.floorHeight;$('#h3h').value=PLAN.floorHeight;
  if(PLAN.piani==null)PLAN.piani=1;if(PLAN.pianoMio==null)PLAN.pianoMio=1;
  if(!PLAN.planimetrie)PLAN.planimetrie={};delete PLAN.planimetrie[PLAN.pianoMio];
  ATTIVO=PLAN.pianoMio;disegnaPiani();
  {const nd=$('#nordDeg'); if(nd) nd.value=nord();}
  PLAN.rooms.forEach(clampRoom); savePlan(); // recupera stanze finite fuori dal piano
  render();
}

// ---------- Planimetria ----------
const SVG=$('#plan');
function clampRoom(r){
  if(r.poly&&r.poly.length){r.poly=r.poly.map(pt=>[Math.max(0,Math.min(PW,Math.round(pt[0]))),Math.max(0,Math.min(PH,Math.round(pt[1])))]);return}
  r.w=Math.max(60,Math.min(PW,r.w));r.h=Math.max(50,Math.min(PH,r.h));r.x=Math.max(0,Math.min(PW-r.w,Math.round(r.x)));r.y=Math.max(0,Math.min(PH-r.h,Math.round(r.y)));
}
function roomBBox(r){if(r.poly&&r.poly.length){const xs=r.poly.map(p=>p[0]),ys=r.poly.map(p=>p[1]);const x=Math.min(...xs),y=Math.min(...ys);return{x,y,w:Math.max(...xs)-x,h:Math.max(...ys)-y}}return{x:r.x,y:r.y,w:r.w,h:r.h}}
function roomCenter(r){const b=roomBBox(r);return{x:Math.round(b.x+b.w/2),y:Math.round(b.y+b.h/2)}}
function pointInRoom(r,p){
  if(r.poly&&r.poly.length>=3){let inside=false,q=r.poly;for(let i=0,j=q.length-1;i<q.length;j=i++){const xi=q[i][0],yi=q[i][1],xj=q[j][0],yj=q[j][1];if(((yi>p.y)!==(yj>p.y))&&(p.x<(xj-xi)*(p.y-yi)/(yj-yi)+xi))inside=!inside}return inside}
  return p.x>=r.x&&p.x<=r.x+r.w&&p.y>=r.y&&p.y<=r.y+r.h;
}
function svgPt(evt){const r=SVG.getBoundingClientRect();const x=(evt.clientX-r.left)/r.width*PW;const y=(evt.clientY-r.top)/r.height*PH;return {x,y}}
function eclArrow(){ // freccia direzione eclissi dal centro
  let e=null;for(const k of['solar','lunar']){if(ECL&&ECL[k]&&ECL[k].visible){e=ECL[k];break}}
  if(!e)return'';const [x,y]=polar(500,320,240,azDisegno(e.azimuth));
  return `<line x1="500" y1="320" x2="${x}" y2="${y}" stroke="${e.body=='sun'?'#ffd24a':'#cfd8e3'}" stroke-width="3" stroke-dasharray="8 6" marker-end="url(#arrow)"/>
   <circle cx="${x}" cy="${y}" r="12" fill="${e.body=='sun'?'#ffd24a':'#cfd8e3'}" stroke="#f0a500"/>
   <text x="${x}" y="${y-18}" fill="var(--acc)" font-size="16" text-anchor="middle">🌒 ${e.compass}</text>`;
}
// ---- geometria per collisioni / livelli ----
function pinp(pt,poly){let inside=false;for(let i=0,j=poly.length-1;i<poly.length;j=i++){const xi=poly[i][0],yi=poly[i][1],xj=poly[j][0],yj=poly[j][1];if(((yi>pt[1])!==(yj>pt[1]))&&(pt[0]<(xj-xi)*(pt[1]-yi)/(yj-yi)+xi))inside=!inside}return inside}
function segInt(a,b,c,d){const o=(p,q,r)=>(q[1]-p[1])*(r[0]-q[0])-(q[0]-p[0])*(r[1]-q[1]);const d1=o(c,d,a),d2=o(c,d,b),d3=o(a,b,c),d4=o(a,b,d);return ((d1>0)!==(d2>0))&&((d3>0)!==(d4>0));}
function polyInt(A,B){for(const p of A)if(pinp(p,B))return true;for(const p of B)if(pinp(p,A))return true;for(let i=0;i<A.length;i++){const a=A[i],b=A[(i+1)%A.length];for(let j=0;j<B.length;j++){const c=B[j],e=B[(j+1)%B.length];if(segInt(a,b,c,e))return true}}return false;}
function polyContains(A,B){return B.every(p=>pinp(p,A));}
function polyArea(P){let s=0;for(let i=0;i<P.length;i++){const a=P[i],b=P[(i+1)%P.length];s+=a[0]*b[1]-b[0]*a[1]}return Math.abs(s)/2;}
function roomZ(r){const base=r.base||0;return [base, base+(r.height!=null?r.height:(PLAN.floorHeight||250))];}
function zOverlap(a,b){return a[0]<b[1]-1 && b[0]<a[1]-1;}
function furnCorners(f){const a=(f.rot||0)*Math.PI/180,ca=Math.cos(a),sa=Math.sin(a),hw=f.w/2,hh=f.h/2;return [[-hw,-hh],[hw,-hh],[hw,hh],[-hw,hh]].map(([x,y])=>[f.x+x*ca-y*sa, f.y+x*sa+y*ca]);}
function satOverlap(P,Q){for(const poly of [P,Q]){for(let i=0;i<poly.length;i++){const a=poly[i],b=poly[(i+1)%poly.length];const nx=-(b[1]-a[1]),ny=b[0]-a[0];let mnP=1e9,mxP=-1e9,mnQ=1e9,mxQ=-1e9;for(const p of P){const d=p[0]*nx+p[1]*ny;if(d<mnP)mnP=d;if(d>mxP)mxP=d}for(const q of Q){const d=q[0]*nx+q[1]*ny;if(d<mnQ)mnQ=d;if(d>mxQ)mxQ=d}if(mxP<mnQ||mxQ<mnP)return false;}}return true;}
function roomOfFurn(f){for(let i=PLAN.rooms.length-1;i>=0;i--){if(pointInRoom(PLAN.rooms[i],{x:f.x,y:f.y}))return PLAN.rooms[i]}return null;}
let COLL={rooms:new Set(),furn:new Set()};
function recomputeCollisions(){const rs=new Set(),fs=new Set();const rooms=PLAN.rooms||[];
  // Due stanze che hanno un muro in comune si TOCCANO, non si sovrappongono: con i lati che coincidono
  // esattamente (planimetria raddrizzata) i vertici cadono sul bordo dell'altra e il controllo li contava
  // "dentro", e tutta la casa diventava rossa. Ora si cerca un pezzo di pavimento in comune: punti a passo
  // di 4 (mai sulle linee intere, dove stanno i muri) nel riquadro comune, dentro tutte e due le stanze.
  // Una sovrapposizione di meno di 4 unita' non si vede, ed e' giusto cosi'.
  const bb=P=>{let a=1e9,b=1e9,c=-1e9,d=-1e9;for(const [x,y] of P){a=Math.min(a,x);b=Math.min(b,y);c=Math.max(c,x);d=Math.max(d,y)}return [a,b,c,d];};
  const sovrapposte=(A,B)=>{const a=bb(A),b=bb(B),x0=Math.max(a[0],b[0]),y0=Math.max(a[1],b[1]),x1=Math.min(a[2],b[2]),y1=Math.min(a[3],b[3]);
    if(x1-x0<2||y1-y0<2)return false;
    for(let x=Math.floor(x0)+1.5;x<x1;x+=4)for(let y=Math.floor(y0)+1.5;y<y1;y+=4)if(pinp([x,y],A)&&pinp([x,y],B))return true;
    return false;};
  for(let i=0;i<rooms.length;i++)for(let j=i+1;j<rooms.length;j++){if(zOverlap(roomZ(rooms[i]),roomZ(rooms[j]))&&sovrapposte(roomPts(rooms[i]),roomPts(rooms[j]))){rs.add(rooms[i].id);rs.add(rooms[j].id)}}
  const furn=PLAN.furniture||[];const info=furn.map(f=>{const rm=roomOfFurn(f);return {f,c:furnCorners(f),base:rm?(rm.base||0):0,room:rm};});
  for(const it of info){if(it.room){const poly=roomPts(it.room);if(!it.c.every(p=>pinp(p,poly)))fs.add(it.f.id);}}
  for(let i=0;i<info.length;i++)for(let j=i+1;j<info.length;j++){if(Math.abs(info[i].base-info[j].base)<1&&satOverlap(info[i].c,info[j].c)){fs.add(info[i].f.id);fs.add(info[j].f.id)}}
  COLL={rooms:rs,furn:fs};
}
function render(){
  recomputeCollisions();
  let s=`<defs><marker id="arrow" markerWidth="10" markerHeight="10" refX="7" refY="3" orient="auto"><path d="M0,0 L7,3 L0,6 Z" fill="#f0a500"/></marker></defs>`;
  // griglia
  for(let i=0;i<=PW;i+=50)s+=`<line x1="${i}" y1="0" x2="${i}" y2="${PH}" stroke="#161b22"/>`;
  for(let i=0;i<=PH;i+=50)s+=`<line x1="0" y1="${i}" x2="${PW}" y2="${i}" stroke="#161b22"/>`;
  // rosa dei venti (Nord)
  // LA ROSA GIRA COL NORD, e non e' decorazione: se il disegno e' girato
  // rispetto alla realta', una N sempre in alto direbbe una cosa falsa.
  // Gira insieme alla freccia dell'eclissi, che e' l'altra cosa sulla pianta
  // che viene dal cielo e non dal disegno. Le finestre no: quelle stanno sul
  // loro muro e col nord non si spostano.
  //
  // SI PUO' TOCCARE: un tocco la gira di quindici gradi. Per un valore
  // preciso c'e' il campo qui sopra; questo serve a raddrizzarla guardandola,
  // che e' il modo in cui uno capisce se e' giusta.
  s+=`<g transform="translate(60,60) rotate(${-nord()})" style="cursor:grab">
    <circle r="34" fill="rgba(13,17,23,.6)" stroke="#30363d"/>
    <line x1="0" y1="0" x2="0" y2="-30" stroke="var(--acc)" stroke-width="2" marker-end="url(#arrow)"/>
    <text x="0" y="-38" fill="var(--acc)" font-size="14" text-anchor="middle">N</text>
    <circle r="34" fill="transparent" stroke="transparent"/>
    <title>Nord a ${nord()}° — afferrala e girala per dire da che parte e' il nord vero</title></g>`;
  // stanze
  for(const r of PLAN.rooms){
    const coll=COLL.rooms.has(r.id);const ext=isExt(r);
    const mia=eLaChiesta(r), altrui=stanzaChiesta()&&!mia;
    const selcol=coll?'var(--bad)':(mia?'var(--acc)':(r.id===SEL?'var(--acc)':'#3b4552'));
    const fill=mia?'rgba(240,165,0,.18)'
              :(coll?'rgba(248,81,73,.10)':(ext?'rgba(63,185,80,.09)':'rgba(88,166,255,.08)'));
    const dash=ext?' stroke-dasharray="8 5"':'';const b=roomBBox(r);
    const sw=mia?4:((r.id===SEL||coll)?3:1.5);
    // le altre restano visibili ma spente: servono a capire dove sta questa,
    // cancellarle vorrebbe dire mostrare una stanza sospesa nel vuoto.
    s+=`<g data-room="${r.id}"${altrui?' opacity="0.28"':''}>`;
    if(r.poly&&r.poly.length>=3){const pts=r.poly.map(p=>p[0]+','+p[1]).join(' ');
      s+=`<polygon points="${pts}" fill="${fill}" stroke="${selcol}" stroke-width="${sw}"${dash}/>`;}
    else s+=`<rect x="${b.x}" y="${b.y}" width="${b.w}" height="${b.h}" rx="6" fill="${fill}" stroke="${selcol}" stroke-width="${sw}"${dash}/>`;
    s+=`<text x="${b.x+8}" y="${b.y+20}" fill="var(--fg)" font-size="15" font-weight="700">${roomEmo(r)} ${escapeHtml(r.name||'Stanza')}${r.base?(' <tspan fill="var(--mut)" font-size="12">Z'+r.base+'</tspan>'):''}</text>`;
    // porte
    for(const d of (r.doors||[])) s+=`<rect x="${d.x-7}" y="${d.y-7}" width="14" height="14" rx="3" fill="${DCOL[d.type]||'#888'}" stroke="#0b0f14"/>`;
    // finestre / punti di osservazione (marker + freccia azimut)
    const c=roomCenter(r);
    for(const w of (r.windows||[])){const wx=w.x!=null?w.x:c.x, wy=w.y!=null?w.y:c.y;
      const [ax,ay]=polar(wx,wy,26,w.azimuth||0); // gia in angolo del disegno
      const inview=eclInView(w);
      s+=`<line x1="${wx}" y1="${wy}" x2="${ax}" y2="${ay}" stroke="${inview?'#ffd24a':'#8b949e'}" stroke-width="2" marker-end="url(#arrow)"/>`;
      if(ext)s+=`<rect x="${wx-6}" y="${wy-6}" width="12" height="12" transform="rotate(45 ${wx} ${wy})" fill="#3fb950" stroke="${inview?'#f0a500':'#0b0f14'}" stroke-width="2"/>`;
      else s+=`<circle cx="${wx}" cy="${wy}" r="6" fill="#e6edf3" stroke="${inview?'#f0a500':'#30363d'}" stroke-width="2"/>`;}
    // maniglie: vertici (poligono) o resize (rettangolo)
    if(r.id===SEL){
      if(r.poly&&r.poly.length)for(let vi=0;vi<r.poly.length;vi++){const v=r.poly[vi];
        s+=`<rect data-vert="${r.id}:${vi}" x="${v[0]-7}" y="${v[1]-7}" width="14" height="14" fill="var(--acc)" rx="3" transform="rotate(45 ${v[0]} ${v[1]})" style="cursor:move"/>`;}
      else s+=`<rect data-resize="${r.id}" x="${b.x+b.w-9}" y="${b.y+b.h-9}" width="18" height="18" fill="var(--acc)" rx="3" style="cursor:nwse-resize"/>`;
    }
    s+=`</g>`;
  }
  // MURI TOLTI: un varco tratteggiato. E con una stanza scelta, ogni suo pezzo
  // di muro si tocca per toglierlo o rimetterlo.
  for(const k in (PLAN.muriTolti||{})){const [a,b]=k.split('#').map(t=>t.split(',').map(Number));
    s+=`<line x1="${a[0]}" y1="${a[1]}" x2="${b[0]}" y2="${b[1]}" stroke="#0b0f14" stroke-width="5"/><line x1="${a[0]}" y1="${a[1]}" x2="${b[0]}" y2="${b[1]}" stroke="var(--acc)" stroke-width="2" stroke-dasharray="3 5"/>`;}
  const rs=PLAN.rooms.find(x=>x.id===SEL);
  if(rs&&!MODE)for(const [a,b] of latiDi(rs)){const k=chiaveMuro(a,b),t=muroTolto(a,b),mx=(a[0]+b[0])/2,my=(a[1]+b[1])/2;
    s+=`<line data-muro="${k}" x1="${a[0]}" y1="${a[1]}" x2="${b[0]}" y2="${b[1]}" stroke="transparent" stroke-width="12" style="cursor:pointer"><title>${t?'Rimetti':'Togli'} questo pezzo di muro</title></line>`;
    s+=`<circle data-muro="${k}" cx="${mx}" cy="${my}" r="8" fill="${t?'var(--acc)':'#0b0f14'}" stroke="var(--acc)" stroke-width="1.5" style="cursor:pointer"/><text x="${mx}" y="${my+4}" font-size="10" text-anchor="middle" fill="${t?'#0b0f14':'var(--acc)'}" style="pointer-events:none">${t?'↩':'✂'}</text>`;}
  // mobili
  for(const f of (PLAN.furniture||[])){const sel=f.id===SELF;const stroke=COLL.furn.has(f.id)?'var(--bad)':(sel?'var(--acc)':'#8b949e');
    s+=`<g transform="rotate(${f.rot||0} ${f.x} ${f.y})">
      <rect x="${f.x-f.w/2}" y="${f.y-f.h/2}" width="${f.w}" height="${f.h}" rx="4" fill="rgba(139,148,158,.20)" stroke="${stroke}" stroke-width="${sel?2.5:1.5}"/>
      <text x="${f.x}" y="${f.y}" fill="var(--fg)" font-size="12" text-anchor="middle" dominant-baseline="middle">${escapeHtml(f.name||'')}</text>`;
    if(sel){
      s+=`<rect data-furnrz="${f.id}" x="${f.x+f.w/2-7}" y="${f.y+f.h/2-7}" width="14" height="14" fill="var(--acc)" rx="2" style="cursor:nwse-resize"/>`;
      s+=`<line x1="${f.x}" y1="${f.y-f.h/2}" x2="${f.x}" y2="${f.y-f.h/2-22}" stroke="var(--acc)" stroke-width="1.5"/><circle data-furnrot="${f.id}" cx="${f.x}" cy="${f.y-f.h/2-22}" r="7" fill="var(--acc)" style="cursor:grab"/>`;
    }
    s+=`</g>`;
  }
  // forma in disegno
  if(MODE==='draw'&&DRAWPTS.length){const pts=DRAWPTS.map(p=>p[0]+','+p[1]).join(' ');
    s+=`<polyline points="${pts}" fill="rgba(240,165,0,.10)" stroke="var(--acc)" stroke-width="2" stroke-dasharray="6 4"/>`;
    for(const v of DRAWPTS)s+=`<circle cx="${v[0]}" cy="${v[1]}" r="5" fill="var(--acc)"/>`;}
  s+=eclArrow();
  SVG.innerHTML=s;
  const cw=$('#collWarn');if(cw){const nr=COLL.rooms.size,nf=COLL.furn.size;
    cw.innerHTML=(nr||nf)?('<span class="badge bad">⚠️ '+(nr?nr+' stanze sovrapposte (stesso livello) ':'')+(nf?(nr?'· ':'')+nf+' mobili fuori dai muri / compenetrati':'')+'</span>'):'';}
  renderRoomPanel();renderFurnPanel();
}
function clampFurn(f){f.x=Math.max(0,Math.min(PW,Math.round(f.x)));f.y=Math.max(0,Math.min(PH,Math.round(f.y)));}
function furnHit(f,p){const a=-(f.rot||0)*Math.PI/180,dx=p.x-f.x,dy=p.y-f.y;const lx=dx*Math.cos(a)-dy*Math.sin(a),ly=dx*Math.sin(a)+dy*Math.cos(a);return Math.abs(lx)<=f.w/2&&Math.abs(ly)<=f.h/2;}
function escapeHtml(t){return (t+'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function eclInView(w){for(const k of['solar','lunar']){const e=ECL&&ECL[k];if(!e||!e.visible)continue;if(Math.abs(angdiff(e.azimuth,azReale(w)))<=(w.photo_fov||66)/2)return e}return null}

// ---- interazione: drag / resize / place / select ----
let drag=null;
SVG.addEventListener('pointerdown',ev=>{
  const p=svgPt(ev);
  if(MODE==='draw'){DRAWPTS.push([Math.round(p.x),Math.round(p.y)]);render();return}
  if(MODE){placeAt(p);return}
  {const t=ev.target&&ev.target.getAttribute&&ev.target.getAttribute('data-muro');if(t){togliMuro(t);return}}
  // LA BUSSOLA SI AFFERRA E SI GIRA, liberamente.
  //
  // NON GIRA LA CASA: dichiara dove sta il nord vero. E' la differenza che
  // conta - la casa resta disegnata come vuoi vederla tu, con qualunque lato
  // in alto, e il nord glielo dici puntandolo. L'alto dello schermo non e'
  // il nord, e non deve esserlo: e' solo il verso da cui ti fa comodo
  // guardare la pianta.
  //
  // A UN GRADO ALLA VOLTA perche' una casa non e' quasi mai allineata ai
  // punti cardinali, e un allineamento a scatti sbaglierebbe di quel poco
  // che poi sposta l'ora del sole su una finestra.
  if(Math.hypot(p.x-60,p.y-60)<40){drag={mode:'nord'};SVG.setPointerCapture(ev.pointerId);return}
  const vt=ev.target.getAttribute&&ev.target.getAttribute('data-vert');
  if(vt){const a=vt.split(':');drag={mode:'vertmove',id:a[0],vi:+a[1]};SVG.setPointerCapture(ev.pointerId);return}
  const rz=ev.target.getAttribute&&ev.target.getAttribute('data-resize');
  if(rz){drag={id:rz,mode:'resize'};SVG.setPointerCapture(ev.pointerId);return}
  const fz=ev.target.getAttribute&&ev.target.getAttribute('data-furnrz');
  if(fz){SELF=fz;SEL=null;drag={mode:'furnresize',id:fz};SVG.setPointerCapture(ev.pointerId);render();return}
  const fr=ev.target.getAttribute&&ev.target.getAttribute('data-furnrot');
  if(fr){SELF=fr;SEL=null;drag={mode:'furnrot',id:fr};SVG.setPointerCapture(ev.pointerId);render();return}
  // hit-test finestre/porte (trascinabili) prima delle stanze
  for(const r of PLAN.rooms){const c=roomCenter(r);
    for(const w of (r.windows||[])){const wx=w.x!=null?w.x:c.x, wy=w.y!=null?w.y:c.y;
      if(Math.hypot(p.x-wx,p.y-wy)<14){SEL=r.id;SELF=null;drag={mode:'winmove',roomId:r.id,eid:w.id};SVG.setPointerCapture(ev.pointerId);render();return}}
    for(const d of (r.doors||[])){if(Math.hypot(p.x-d.x,p.y-d.y)<12){SEL=r.id;SELF=null;drag={mode:'doormove',roomId:r.id,eid:d.id};SVG.setPointerCapture(ev.pointerId);render();return}}
  }
  // mobili (sopra le stanze)
  for(let i=(PLAN.furniture||[]).length-1;i>=0;i--){const f=PLAN.furniture[i];if(furnHit(f,p)){SELF=f.id;SEL=null;drag={mode:'furnmove',id:f.id,dx:p.x-f.x,dy:p.y-f.y};SVG.setPointerCapture(ev.pointerId);render();return}}
  // trova stanza sotto il punto (dall'alto)
  let hit=null;for(let i=PLAN.rooms.length-1;i>=0;i--){if(pointInRoom(PLAN.rooms[i],p)){hit=PLAN.rooms[i];break}}
  if(hit){SEL=hit.id;SELF=null;
    if(hit.poly&&hit.poly.length)drag={id:hit.id,mode:'polymove',sx:p.x,sy:p.y,orig:hit.poly.map(v=>[v[0],v[1]])};
    else drag={id:hit.id,mode:'move',dx:p.x-hit.x,dy:p.y-hit.y};
    SVG.setPointerCapture(ev.pointerId);render();}
  else{SEL=null;SELF=null;render();}
});
SVG.addEventListener('pointermove',ev=>{if(!drag)return;const p=svgPt(ev);
  if(drag.mode==='nord'){const dx=p.x-60,dy=p.y-60;
    if(Math.hypot(dx,dy)>6){ // troppo vicino al centro l'angolo salta
      PLAN.nord=Math.round(((-Math.atan2(dx,-dy)*180/Math.PI)%360+360)%360);
      const nd=$('#nordDeg'); if(nd) nd.value=nord();
      render();}
    return}
  if(drag.mode==='winmove'){const r=PLAN.rooms.find(x=>x.id===drag.roomId);const w=r&&r.windows.find(x=>x.id===drag.eid);if(w){w.x=Math.round(p.x);w.y=Math.round(p.y);render()}return}
  if(drag.mode==='doormove'){const r=PLAN.rooms.find(x=>x.id===drag.roomId);const d=r&&r.doors.find(x=>x.id===drag.eid);if(d){d.x=Math.round(p.x);d.y=Math.round(p.y);render()}return}
  if(drag.mode==='vertmove'){const r=PLAN.rooms.find(x=>x.id===drag.id);if(r&&r.poly){r.poly[drag.vi]=[Math.round(p.x),Math.round(p.y)];clampRoom(r);render()}return}
  if(drag.mode==='polymove'){const r=PLAN.rooms.find(x=>x.id===drag.id);if(r&&r.poly){const dx=p.x-drag.sx,dy=p.y-drag.sy;r.poly=drag.orig.map(v=>[Math.round(v[0]+dx),Math.round(v[1]+dy)]);clampRoom(r);render()}return}
  if(drag.mode==='furnmove'){const f=PLAN.furniture.find(x=>x.id===drag.id);if(f){f.x=p.x-drag.dx;f.y=p.y-drag.dy;clampFurn(f);render()}return}
  if(drag.mode==='furnrot'){const f=PLAN.furniture.find(x=>x.id===drag.id);if(f){let ang=Math.atan2(p.y-f.y,p.x-f.x)*180/Math.PI+90;f.rot=Math.round(((ang%360)+360)%360);render()}return}
  if(drag.mode==='furnresize'){const f=PLAN.furniture.find(x=>x.id===drag.id);if(f){const a=-(f.rot||0)*Math.PI/180,dx=p.x-f.x,dy=p.y-f.y;const lx=Math.abs(dx*Math.cos(a)-dy*Math.sin(a)),ly=Math.abs(dx*Math.sin(a)+dy*Math.cos(a));f.w=Math.max(15,Math.round(lx*2));f.h=Math.max(15,Math.round(ly*2));render()}return}
  const r=PLAN.rooms.find(x=>x.id===drag.id);if(!r)return;
  if(drag.mode==='move'){r.x=Math.round(p.x-drag.dx);r.y=Math.round(p.y-drag.dy)}
  else if(drag.mode==='resize'){r.w=Math.max(60,Math.round(p.x-r.x));r.h=Math.max(50,Math.round(p.y-r.y))}
  clampRoom(r);render();});
SVG.addEventListener('pointerup',ev=>{if(drag){drag=null;savePlan()}});

function placeAt(p){
  if(MODE.startsWith('furn:')){const tid=MODE.split(':')[1];const t=(PLAN.templates||[]).find(x=>x.id===tid);
    PLAN.furniture=PLAN.furniture||[];const f={id:uid(),tpl:tid,name:t?t.name:'Mobile',w:t?t.w:80,h:t?t.h:80,rot:0,x:Math.round(p.x),y:Math.round(p.y)};
    clampFurn(f);PLAN.furniture.push(f);SELF=f.id;SEL=null;setMode(null);render();savePlan();return;}
  const r=PLAN.rooms.find(x=>x.id===SEL);
  if(!r){setMode(null);alert('Seleziona prima una stanza');return}
  if(MODE.startsWith('door:')){r.doors=r.doors||[];r.doors.push({id:uid(),type:MODE.split(':')[1],x:Math.round(p.x),y:Math.round(p.y)});}
  if(MODE.startsWith('win:')){r.windows=r.windows||[];const t=MODE.split(':')[1];
    const x=Math.round(p.x), y=Math.round(p.y);
    r.windows.push({id:uid(),tipo:t,name:t+' '+((r.windows.length||0)+1),
      azimuth:azimutDalMuro(r,x,y),alt_min:0,x:x,y:y});}
  setMode(null);render();savePlan();
}
// ---- mobili: libreria template + istanze ----
function renderFurnPicker(){let h='<span class="mut">Mobile:</span>';
  for(const t of (PLAN.templates||[]))h+=`<button class="sec" onclick="pickFurn('${t.id}')">${escapeHtml(t.name)} <small>${t.w}×${t.h}${t.model?' 🧊':''}</small></button>`;
  h+='<button onclick="openTpl()">➕ Nuovo template</button>';$('#furnPicker').innerHTML=h;}
function startAddFurn(){setMode(null);renderFurnPicker();$('#furnPicker').style.display='flex';}
function pickFurn(id){$('#furnPicker').style.display='none';setMode('furn:'+id);}
function openTpl(){$('#furnPicker').style.display='none';$('#tplName').value='';$('#tplW').value='';$('#tplH').value='';TPLMODEL=null;$('#tplModel').value='';$('#tplModelName').textContent='';$('#tplModal').classList.add('on');}
function closeTpl(){$('#tplModal').classList.remove('on');}
function tplModelPick(inp){TPLMODEL=inp.files[0]||null;$('#tplModelName').textContent=TPLMODEL?('✓ '+TPLMODEL.name):'';}
async function uploadModel(file){const fd=new FormData();fd.append('file',file);return (await (await fetch('api/model',{method:'POST',body:fd})).json()).model;}
async function saveTpl(){const n=$('#tplName').value.trim();const w=parseInt($('#tplW').value),h=parseInt($('#tplH').value);
  if(!n||!(w>0)||!(h>0)){alert('Servono nome e dimensioni valide');return}
  let model=null;if(TPLMODEL){try{model=await uploadModel(TPLMODEL)}catch(e){alert('Upload modello fallito');return}}
  PLAN.templates=PLAN.templates||[];const t={id:uid(),name:n,w,h};if(model)t.model=model;PLAN.templates.push(t);
  savePlan();closeTpl();renderFurnPicker();$('#furnPicker').style.display='flex';}
async function addTplModel(tplId,inp){if(!inp.files[0])return;let m;try{m=await uploadModel(inp.files[0])}catch(e){alert('Upload fallito');return}
  const t=(PLAN.templates||[]).find(x=>x.id===tplId);if(t){t.model=m;savePlan();render()}}
window.delTplModel=(tplId)=>{const t=(PLAN.templates||[]).find(x=>x.id===tplId);if(t&&confirm('Rimuovere il modello 3D dal template?')){delete t.model;savePlan();render()}};
function openModel3D(fn){$('#model3dBox').innerHTML=`<model-viewer src="models/${fn}" camera-controls auto-rotate style="width:100%;height:70vh;background:#0b0f14;border-radius:8px"></model-viewer>`;$('#modelModal').classList.add('on');}
function closeModel3D(){$('#modelModal').classList.remove('on');$('#model3dBox').innerHTML='';}
function renderFurnPanel(){const box=$('#furnPanel');const f=(PLAN.furniture||[]).find(x=>x.id===SELF);if(!f){box.innerHTML='';return}
  const t=(PLAN.templates||[]).find(x=>x.id===f.tpl);const model=t&&t.model;
  let m3d='';
  if(model)m3d=`<div style="margin-top:8px"><model-viewer src="models/${model}" camera-controls auto-rotate disable-tap style="width:100%;height:200px;background:#0b0f14;border-radius:8px"></model-viewer>
      <div class="row" style="margin-top:6px"><button class="sec" onclick="openModel3D('${model}')">🧊 Vista 3D</button><button class="sec" onclick="delTplModel('${f.tpl}')">Rimuovi 3D</button></div></div>`;
  else if(t)m3d=`<div class="row" style="margin-top:8px"><label class="sec" style="padding:9px;border:1px solid var(--bd);border-radius:8px;cursor:pointer">🧊 Aggiungi modello 3D (.glb)<input type="file" accept=".glb,.gltf" style="display:none" onchange="addTplModel('${f.tpl}',this)"></label></div><div class="mut" style="margin-top:4px">si applica al template «${escapeHtml(t.name)}»</div>`;
  box.innerHTML=`<div class="card"><div class="row" style="justify-content:space-between"><b>🛋️ Mobile</b><button class="sec" onclick="delFurn('${f.id}')">🗑️</button></div>
    <div class="row" style="margin-top:8px"><span class="mut">Nome</span><input value="${escapeHtml(f.name||'')}" onchange="setFurn('${f.id}','name',this.value)" style="flex:1;min-width:120px"></div>
    <div class="row" style="margin-top:6px"><span class="mut">L</span><input type="number" value="${f.w}" onchange="setFurn('${f.id}','w',this.value)" style="width:76px"><span class="mut">P</span><input type="number" value="${f.h}" onchange="setFurn('${f.id}','h',this.value)" style="width:76px"><span class="mut">Rot°</span><input type="number" value="${f.rot||0}" onchange="setFurn('${f.id}','rot',this.value)" style="width:76px"></div>
    ${m3d}
    <div class="row" style="margin-top:8px"><button class="sec" onclick="furnToTpl('${f.id}')">⭐ Salva come template</button></div></div>`;}
window.setFurn=(id,k,v)=>{const f=PLAN.furniture.find(x=>x.id===id);if(!f)return;
  if(k==='name')f.name=v;else{let n=parseInt(v);if(!isNaN(n)){if(k==='rot')f.rot=((n%360)+360)%360;else f[k]=Math.max(10,n)}}
  render();savePlan();};
window.delFurn=(id)=>{PLAN.furniture=PLAN.furniture.filter(x=>x.id!==id);SELF=null;render();savePlan();};
window.furnToTpl=(id)=>{const f=PLAN.furniture.find(x=>x.id===id);if(!f)return;const n=prompt('Nome del template',f.name||'Mobile');if(!n)return;
  const src=(PLAN.templates||[]).find(x=>x.id===f.tpl);const nt={id:uid(),name:n.trim(),w:f.w,h:f.h};if(src&&src.model)nt.model=src.model;
  PLAN.templates=PLAN.templates||[];PLAN.templates.push(nt);savePlan();alert('Template salvato: '+n.trim());};
// PIAZZARE UNA FINESTRA PRIMA DI SAPERNE L'AFFACCIO.
//
// Finora l'unico modo di fare una finestra era la procedura della foto: si
// doveva gia' sapere dove guarda e avere lo scatto in mano, e quello che
// usciva nasceva al centro della stanza, non sul muro. Chi voleva solo
// disegnare la casa - dove sono le finestre, come si aprono - non aveva
// nessuna strada.
//
// Ora ci sono due fasi e la prima puo' stare da sola: si piazza la finestra
// sul muro, e in 3D si vede subito, aperta. La foto e la prospettiva
// dell'eclissi si agganciano dopo, con il bottone sulla scheda, a una
// finestra che esiste gia'.
function startAddWin(){setMode(null);$('#doorPicker').style.display='none';$('#winPicker').style.display='flex';}
function pickWin(t){$('#winPicker').style.display='none';setMode('win:'+t);}

// DA CHE PARTE GUARDA, indovinato dal muro piu' vicino.
//
// Una finestra guarda fuori, e "fuori" e' la parte opposta al centro della
// stanza: la normale del muro basta a dirlo. E' una stima, e la si puo'
// correggere con la procedura della foto - ma e' molto meglio di zero, che
// vorrebbe dire dire a tutte le finestre della casa che guardano a nord.
function azimutDalMuro(r,x,y){
  const pts=roomPts(r); let bi=0,bd=1e9;
  for(let i=0;i<pts.length;i++){const pr=projOnSeg([x,y],pts[i],pts[(i+1)%pts.length]);
    if(pr.dist<bd){bd=pr.dist;bi=i}}
  const a=pts[bi], b=pts[(bi+1)%pts.length];
  const L=Math.hypot(b[0]-a[0],b[1]-a[1])||1;
  let nx=-(b[1]-a[1])/L, ny=(b[0]-a[0])/L;      // normale al muro
  const c=roomCenter(r);
  if((c.x-x)*nx+(c.y-y)*ny > 0){nx=-nx;ny=-ny}  // tenuta quella che va verso fuori
  // e' gia' un angolo del disegno: il muro sta sul disegno.
  return Math.round(((Math.atan2(nx,-ny)*180/Math.PI)%360+360)%360);
}

function startAddDoor(){
  if(!SEL){alert('Seleziona prima una stanza');return}
  $('#doorPicker').style.display='flex';
}
function pickDoor(t){$('#doorPicker').style.display='none';setMode('door:'+t);}
function setMode(m){MODE=m;
  let t='';if(m&&m.startsWith('furn:'))t='Tocca sul piano per posizionare il mobile';else if(m)t='Tocca sul piano per posizionare: '+m.replace('door:','porta ');
  $('#mode').textContent=t;
  if(!m){$('#winPicker').style.display='none';$('#doorPicker').style.display='none';$('#roomPicker').style.display='none';$('#drawBtns').style.display='none';$('#furnPicker').style.display='none'}}

// ---- disegno stanza poligonale ----
function startDrawRoom(){setMode(null);MODE='draw';DRAWPTS=[];SEL=null;$('#roomPicker').style.display='none';$('#doorPicker').style.display='none';$('#drawBtns').style.display='flex';$('#mode').textContent='Disegna: tocca i vertici, poi "Chiudi forma"';render();}
function undoDraw(){if(MODE==='draw'&&DRAWPTS.length){DRAWPTS.pop();render()}}
function cancelDraw(){MODE=null;DRAWPTS=[];$('#drawBtns').style.display='none';$('#mode').textContent='';render();}
function finishDraw(){if(DRAWPTS.length<3){alert('Servono almeno 3 vertici');return}
  const r={id:uid(),name:'Stanza '+(PLAN.rooms.length+1),kind:'interna',subtype:null,poly:DRAWPTS.slice(),windows:[],doors:[]};
  clampRoom(r);PLAN.rooms.push(r);SEL=r.id;MODE=null;DRAWPTS=[];$('#drawBtns').style.display='none';$('#mode').textContent='';render();savePlan();}

function renderRoomPicker(){
  $('#roomPicker').innerHTML='<span class="mut">Tipo stanza:</span>'+
    TIPI_STANZA.map(([v,l])=>`<button class="sec" onclick="pickRoom('${v}')">${l}</button>`).join('');
}
function startAddRoom(){setMode(null);$('#doorPicker').style.display='none';renderRoomPicker();$('#roomPicker').style.display='flex';}
function pickRoom(t){$('#roomPicker').style.display='none';
  const [kind,sub]=tipoDa(t);
  addRoom(null,kind,sub);
}
// I nomi che si leggono, quando il sottotipo da solo suonerebbe monco:
// "Scale 1" non dice niente, "Vano scale 1" si.
const ROOM_NOME={scale:'Vano scale'};
function cap(s){return s?s.charAt(0).toUpperCase()+s.slice(1):s}
function addRoom(name,kind,sub){
  kind=kind||'interna';sub=sub||null;
  const etichetta=(ROOM_NOME[sub]||cap(sub));
  const def=name||(kind==='interna'?('Stanza '+(PLAN.rooms.length+1)):(etichetta+' '+(PLAN.rooms.length+1)));
  const r={id:uid(),name:def,kind:kind,subtype:sub,x:120+PLAN.rooms.length*30,y:120+PLAN.rooms.length*24,w:220,h:160,windows:[],doors:[]};
  PLAN.rooms.push(r);SEL=r.id;render();savePlan();return r;
}
// IL VANO SCALE PUO' ESSERE DENTRO O FUORI. Un pianerottolo condominiale e'
// chiuso, ha finestre e muri come una stanza; una scala esterna no. Il tipo
// «scale_interne» resta una stanza interna col sottotipo scale: le finestre si
// mettono, e la clonazione dei piani lo riconosce come spazio comune.
function tipoDa(v){return v==='interna'?['interna',null]:v==='scale_interne'?['interna','scale']:['esterna',v];}
function tipoDi(r){return isExt(r)?(r.subtype||'balcone'):(r.subtype==='scale'?'scale_interne':'interna');}
window.setRoomKind=(id,v)=>{const r=PLAN.rooms.find(x=>x.id===id);if(!r)return;
  const [k,sb]=tipoDa(v);r.kind=k;r.subtype=sb;render();savePlan();};
// ---------- Piani dell'edificio ----------
// PLAN.rooms e PLAN.furniture sono il piano che si sta disegnando (ATTIVO); gli
// altri aspettano in PLAN.planimetrie["n"]. Sul server pero' PLAN.rooms e' sempre
// il MIO appartamento (lo leggono l'export, WordPress e il comando /casa di
// Minecraft): ci pensa perSalvare(). Un piano senza planimetria e' uguale al mio.
// Le scale sono lo spazio comune: un piano nuovo parte con le scale del mio.
let ATTIVO=1;
function mieStanze(){return ATTIVO===(PLAN.pianoMio||1)?PLAN.rooms:((PLAN.planimetrie||{})[PLAN.pianoMio]||{}).rooms||[]}
function perSalvare(){
  const p=Object.assign({},PLAN), pl=Object.assign({},PLAN.planimetrie||{});
  pl[ATTIVO]={rooms:PLAN.rooms,furniture:PLAN.furniture||[]};
  const mio=pl[PLAN.pianoMio||1]||{rooms:[],furniture:[]};delete pl[PLAN.pianoMio||1];
  for(const k of Object.keys(pl)) if(!pl[k]||!(pl[k].rooms||[]).length) delete pl[k];
  p.rooms=mio.rooms;p.furniture=mio.furniture||[];p.planimetrie=pl;return p;
}
function disegnaPiani(){
  const box=$('#pianiBtn');if(!box)return;box.innerHTML='';
  for(let n=1;n<=(PLAN.piani||1);n++){
    const b=document.createElement('button');
    const proprio=n===(PLAN.pianoMio||1)||(((PLAN.planimetrie||{})[n]||{}).rooms||[]).length||(n===ATTIVO&&PLAN.rooms.length);
    b.textContent=(n===(PLAN.pianoMio||1)?'★ ':'')+n+(proprio?'':' ≡');
    b.title=n===1?'piano terra':(proprio?'piano '+n:'piano '+n+': uguale al mio');
    if(n!==ATTIVO)b.className='sec';
    b.onclick=()=>vaiPiano(n);box.appendChild(b);
  }
  $('#pianiEd').value=PLAN.piani;$('#pianoMio').value=PLAN.pianoMio;
  const nota=$('#pianiNota');
  if(nota)nota.textContent=ATTIVO===(PLAN.pianoMio||1)?'Stai disegnando il tuo appartamento (piano '+ATTIVO+'). Piano terra = 1. Un piano senza planimetria (≡) è uguale al tuo; le scale sono lo spazio comune.'
    :'Stai disegnando il piano '+ATTIVO+(PLAN.rooms.length?'':' — è vuoto, quindi è uguale al tuo: disegnalo o usa «clona»')+'.';
}
window.vaiPiano=(n)=>{
  if(!PLAN.planimetrie)PLAN.planimetrie={};
  PLAN.planimetrie[ATTIVO]={rooms:PLAN.rooms,furniture:PLAN.furniture||[]};
  ATTIVO=n;const q=PLAN.planimetrie[n];delete PLAN.planimetrie[n];
  PLAN.rooms=q&&q.rooms?q.rooms:[];PLAN.furniture=q&&q.furniture?q.furniture:[];
  if(!PLAN.rooms.length&&n!==(PLAN.pianoMio||1))
    PLAN.rooms=JSON.parse(JSON.stringify(mieStanze().filter(r=>r.subtype==='scale')));
  SEL=null;SELF=null;render();disegnaPiani();if(typeof buildHouse3D==='function'&&H3.run)buildHouse3D();
};
window.aggiungiPiano=()=>{PLAN.piani=Math.min(60,(PLAN.piani||1)+1);disegnaPiani();savePlan();};
window.togliPiano=()=>{const n=PLAN.piani||1;
  if(n<=1||n===(PLAN.pianoMio||1)||n===ATTIVO){alert('Non si toglie il piano più alto se è il tuo o quello che stai disegnando.');return}
  if(PLAN.planimetrie)delete PLAN.planimetrie[n];PLAN.piani=n-1;disegnaPiani();savePlan();};
window.questoMio=()=>{PLAN.pianoMio=ATTIVO;disegnaPiani();savePlan();};
window.clonaMio=()=>{
  if(!confirm('Copio la planimetria del tuo appartamento (stanze, porte, finestre; i mobili no) su tutti gli altri '+((PLAN.piani||1)-1)+' piani, sostituendo quello che c\'è. Procedo?'))return;
  const mie=JSON.parse(JSON.stringify(mieStanze()));
  if(!PLAN.planimetrie)PLAN.planimetrie={};
  for(let n=1;n<=(PLAN.piani||1);n++){if(n===(PLAN.pianoMio||1))continue;
    const copia={rooms:JSON.parse(JSON.stringify(mie)),furniture:[]};
    if(n===ATTIVO){PLAN.rooms=copia.rooms;PLAN.furniture=[];}else PLAN.planimetrie[n]=copia;}
  render();disegnaPiani();savePlan();if(typeof buildHouse3D==='function'&&H3.run)buildHouse3D();
};
window.setFloorHeight=(v)=>{let n=parseInt(v);if(isNaN(n))return;PLAN.floorHeight=Math.max(80,Math.min(600,n));
  $('#floorH').value=PLAN.floorHeight;$('#h3h').value=Math.max(150,Math.min(400,PLAN.floorHeight));savePlan();if(H3.run)buildHouse3D();};
window.setRoomHeight=(id,v)=>{const r=PLAN.rooms.find(x=>x.id===id);if(!r)return;
  let n=parseInt(v);if(v===''||isNaN(n)){delete r.height;}else{r.height=Math.max(40,n);}
  savePlan();render();if(H3.run)buildHouse3D();};
window.setRoomBase=(id,v)=>{const r=PLAN.rooms.find(x=>x.id===id);if(!r)return;
  let n=parseInt(v);if(v===''||isNaN(n)||n===0){delete r.base;}else{r.base=Math.max(0,n);}
  savePlan();render();if(H3.run)buildHouse3D();};
function autoStackLevels(){const rooms=(PLAN.rooms||[]).slice().sort((a,b)=>polyArea(roomPts(b))-polyArea(roomPts(a)));
  let changed=0;
  for(const s of rooms){ // trova la stanza più piccola che contiene 's' e stagli sopra
    let host=null,hostArea=1e18;
    for(const L of rooms){if(L===s)continue;const aL=polyArea(roomPts(L)),aS=polyArea(roomPts(s));if(aL<=aS)continue;
      if(polyContains(roomPts(L),roomPts(s))&&aL<hostArea){host=L;hostArea=aL;}}
    const nb=host?((host.base||0)+(host.height!=null?host.height:(PLAN.floorHeight||250))):0;
    const cur=s.base||0;if(nb!==cur){if(nb===0)delete s.base;else s.base=nb;changed++;}
  }
  savePlan();render();if(H3.run)buildHouse3D();
  alert(changed?('Livelli impilati: '+changed+' stanze riposizionate in Z.'):'Nessuna stanza da impilare (nessun perimetro contenuto in un altro).');
}

function renderRoomPanel(){
  const box=$('#roomPanel');const r=PLAN.rooms.find(x=>x.id===SEL);
  const awb=$('#addWinBtn');
  if(!r){box.innerHTML='';if(awb){awb.innerHTML='🪟 Finestra'}return}
  if(awb)awb.innerHTML=viewIcon(r)+' '+(isExt(r)?'Vista':'Finestra');
  const kv=tipoDi(r);
  // L'ELENCO DEI TIPI STA IN UN POSTO SOLO.
  //
  // Era scritto due volte - nei bottoni che creano e in questa tendina che
  // modifica - e aggiungendo il vano scale solo alla creazione si otteneva
  // proprio il difetto peggiore: un tipo che si poteva assegnare a una stanza
  // nuova e non a una che c'era gia'. Chi avesse aperto una stanza esistente
  // per cambiarla se lo sarebbe visto sparire dalla tendina.
  const opts=TIPI_STANZA
    .map(([v,l])=>`<option value="${v}"${v===kv?' selected':''}>${l}</option>`).join('');
  let wins='';
  for(const w of (r.windows||[])){const e=eclInView(w);
    wins+=`<div class="card" style="margin:6px 0">
      <div class="row" style="justify-content:space-between"><b>${viewIcon(r)} ${escapeHtml(w.name)}</b><span class="mut">${compass16(azReale(w))} · ${azReale(w)}°</span></div>
      <div>${e?'<span class="badge ok">'+e.type.split(' ')[1]+' visibile da qui</span>':'<span class="mut">nessuna eclissi da questo affaccio</span>'}</div>
      <div id="v_${w.id}"></div>
      <div class="row" style="margin-top:6px">
        <button class="sec" onclick="startAddWindow('${w.id}')">📷 ${w.photo?'rifai':'aggancia'} foto e direzione</button>
        <button class="sec" onclick="delWin('${r.id}','${w.id}')">🗑️</button></div></div>`;
  }
  box.innerHTML=`<div class="card"><div class="row" style="justify-content:space-between">
      <input value="${escapeHtml(r.name)}" onchange="renRoom('${r.id}',this.value)" style="flex:1;min-width:140px">
      <button class="sec" onclick="delRoom('${r.id}')">🗑️ Stanza</button></div>
    <div class="row" style="margin-top:8px"><span class="mut">Tipo:</span>
      <select onchange="setRoomKind('${r.id}',this.value)">${opts}</select></div>
    <div class="row" style="margin-top:6px"><span class="mut">Altezza 3D</span><input type="number" min="40" placeholder="piano (${PLAN.floorHeight||250})" value="${r.height!=null?r.height:''}" onchange="setRoomHeight('${r.id}',this.value)" style="width:90px"><span class="mut">Base Z</span><input type="number" placeholder="0" value="${r.base||''}" onchange="setRoomBase('${r.id}',this.value)" style="width:90px"></div>
    <div class="mut" style="margin-top:2px">Altezza vuoto = piano · Base Z = quota del pavimento (per soppalchi/piani superiori)</div>
    <div class="mut" style="margin-top:6px">Porte: ${(r.doors||[]).map(d=>d.type).join(', ')||'nessuna'}</div>
    <div style="margin-top:6px">${wins||'<span class="mut">Nessun'+(isExt(r)?' punto di osservazione':'a finestra')+' in questa stanza.</span>'}</div></div>`;
  for(const w of (r.windows||[])) if(w.photo) drawView(w);
}
function drawView(w){const el=document.getElementById('v_'+w.id);if(!el)return;
  el.innerHTML=`<div class="viewwrap"><img src="photos/${w.photo}?v=${Date.now()}" onload="placeSun('${w.id}',this)"><div id="sun_${w.id}" class="sun" style="display:none">${sunSVG()}</div></div><small id="hint_${w.id}"></small>`;}
function sunSVG(){return `<svg viewBox="0 0 42 42"><circle cx="21" cy="21" r="17" fill="#ffd24a" stroke="#f0a500" stroke-width="2"/><circle cx="28" cy="16" r="15" fill="rgba(13,17,23,.82)"/></svg>`}
window.placeSun=(wid,img)=>{let w=null;for(const r of PLAN.rooms){const f=(r.windows||[]).find(x=>x.id===wid);if(f){w=f;break}}
  const e=w&&eclInView(w);const sun=document.getElementById('sun_'+wid),hint=document.getElementById('hint_'+wid);
  if(!e){if(hint)hint.textContent='Nessuna eclissi visibile da qui.';return}
  const paz=w.photo_az!=null?w.photo_az:w.azimuth, fov=w.photo_fov||66, dh=angdiff(e.azimuth,paz);
  if(Math.abs(dh)>fov/2){sun.style.display='none';hint.textContent=`Fuori inquadratura: ruota verso ${e.compass}.`;return}
  const fovV=fov*(img.clientHeight/img.clientWidth), cAlt=(w.photo_alt!=null?w.photo_alt:15);
  const x=0.5+dh/fov, y=Math.max(0,Math.min(1,0.5-(e.altitude-cAlt)/fovV));
  sun.style.left=(x*100)+'%';sun.style.top=(y*100)+'%';sun.style.display='block';
  hint.textContent=`${e.type} · ${e.compass} ${e.azimuth}° · alt ${e.altitude}° (stimato)`;
};
window.renRoom=(id,v)=>{const r=PLAN.rooms.find(x=>x.id===id);if(r){r.name=v;render();savePlan()}};
window.delRoom=(id)=>{if(confirm('Eliminare la stanza?')){PLAN.rooms=PLAN.rooms.filter(x=>x.id!==id);SEL=null;render();savePlan()}};
window.delWin=(rid,wid)=>{const r=PLAN.rooms.find(x=>x.id===rid);if(r){r.windows=r.windows.filter(x=>x.id!==wid);render();savePlan()}};

// ---------- Wizard finestra ----------
function startAddWindow(wid){
  WEDIT=wid||null;
  LOCKED=null;LOCKEDALT=null;LIVEPITCH=null;WPHOTO=null;$('#wS3').disabled=true;$('#wName').value='';$('#wDeg').value='';$('#wAlt').value='';$('#wPname').textContent='';$('#wPrev').innerHTML='';$('#wLive').textContent='—°';$('#wLivec').textContent='';$('#wLiveAlt').textContent='—°';$('#wFov').value=66;$('#wFovHint').textContent='';
  const sel=$('#wRoom');sel.innerHTML='';for(const r of PLAN.rooms){const o=document.createElement('option');o.value=r.id;o.textContent=r.name;sel.appendChild(o)}
  if(SEL)sel.value=SEL;$('#wRoomNew').value='';
  // AGGANCIO: la finestra c'e' gia', quindi la stanza non si sceglie piu' e il
  // nome arriva compilato. Restano da prendere solo la direzione e la foto,
  // che sono le uniche cose che la procedura sa fare e il piazzamento no.
  if(WEDIT){const rr=PLAN.rooms.find(x=>(x.windows||[]).some(w=>w.id===WEDIT));
    const ww=rr&&rr.windows.find(w=>w.id===WEDIT);
    if(ww){sel.value=rr.id;sel.disabled=true;$('#wRoomNew').disabled=true;
      $('#wName').value=ww.name||'';}}
  else {sel.disabled=false;$('#wRoomNew').disabled=false;}
  updateWinLabels();winStep(1);$('#winModal').classList.add('on');
}
function curWinRoom(){if($('#wRoomNew').value.trim())return{kind:'interna'};return PLAN.rooms.find(x=>x.id===$('#wRoom').value)||{kind:'interna'}}
function updateWinLabels(){const ext=curWinRoom().kind==='esterna';
  $('#winTitle').textContent=ext?'Nuovo punto di osservazione':'Nuova finestra';
  $('#wNameLbl').textContent=ext?'Nome del punto':'Nome finestra';
  $('#wName').placeholder=ext?'Es. Angolo balcone / Ringhiera SO':'Es. Finestra grande / Portafinestra';
  $('#wDirLbl').textContent=ext?'Direzione (punta verso la vista / orizzonte)':'Direzione (punta verso la finestra)';
  $('#wSave').textContent=ext?'💾 Salva punto':'💾 Salva finestra';
}
function closeWin(){$('#winModal').classList.remove('on')}
function winStep(n){document.querySelectorAll('#winModal .step').forEach(s=>s.classList.toggle('on',+s.dataset.s===n));
  if(n===3)$('#wLivec').textContent=LOCKED!=null?compass16(LOCKED)+' '+LOCKED+'°':'';}
// elevazione dell'asse fotocamera (−z del device) dal suolo, valida in qualsiasi orientamento (portrait/landscape)
function camAlt(beta,gamma){const b=beta*Math.PI/180,g=gamma*Math.PI/180;let z=-Math.cos(b)*Math.cos(g);z=Math.max(-1,Math.min(1,z));return Math.round(Math.asin(z)*180/Math.PI);}
function orient(ev){let h=null;if(ev.webkitCompassHeading!=null)h=ev.webkitCompassHeading;else if(ev.absolute&&ev.alpha!=null)h=(360-ev.alpha)%360;
  if(h!=null){LIVE=Math.round(h);$('#wLive').textContent=LIVE+'°';$('#wLivec').textContent=compass16(LIVE)}
  if(ev.beta!=null){const a=(ev.gamma!=null)?camAlt(ev.beta,ev.gamma):Math.round(ev.beta-90);LIVEPITCH=Math.max(-80,Math.min(80,a));const el=$('#wLiveAlt');if(el)el.textContent=LIVEPITCH+'°'}}
async function enableCompass(){$('#wcerr').textContent='';try{
  if(typeof DeviceOrientationEvent!=='undefined'&&DeviceOrientationEvent.requestPermission){const p=await DeviceOrientationEvent.requestPermission();if(p!=='granted'){$('#wcerr').innerHTML='<span class="bad">Permesso negato</span>';return}}
  addEventListener('deviceorientationabsolute',orient,true);addEventListener('deviceorientation',orient,true);$('#wLivec').textContent='muovi a "8"…';
}catch(e){$('#wcerr').innerHTML='<span class="bad">Bussola solo su HTTPS</span>'}}
function lockHeading(){if(LIVE==null){alert('Attiva la bussola');return}LOCKED=LIVE;LOCKEDALT=(LIVEPITCH!=null?LIVEPITCH:0);$('#wLive').textContent=LOCKED+'° 🔒';$('#wLivec').textContent=compass16(LOCKED)+' · centro '+LOCKEDALT+'°';$('#wS3').disabled=false}
function useManual(){let v=parseFloat($('#wDeg').value);if(isNaN(v)){alert('Inserisci la direzione 0-360');return}LOCKED=((v%360)+360)%360;let a=parseFloat($('#wAlt').value);LOCKEDALT=isNaN(a)?0:Math.max(-80,Math.min(80,a));$('#wLive').textContent=LOCKED+'° ✍️';$('#wLivec').textContent=compass16(LOCKED)+' (manuale) · centro '+LOCKEDALT+'°';$('#wS3').disabled=false}
function readF35(buf){try{
  const dv=new DataView(buf);if(dv.getUint16(0)!==0xFFD8)return null;
  let off=2;const len=dv.byteLength;
  while(off+4<=len){
    if(dv.getUint8(off)!==0xFF)return null;const marker=dv.getUint8(off+1);
    if(marker===0xDA)return null; // inizio dati immagine
    if(marker===0x01||(marker>=0xD0&&marker<=0xD9)){off+=2;continue}
    const size=dv.getUint16(off+2);
    if(marker===0xE1){const app1=off+4;
      if(app1+6<=len&&dv.getUint32(app1)===0x45786966){ // "Exif"
        const tiff=app1+6;const little=dv.getUint16(tiff)===0x4949;
        const rd16=o=>dv.getUint16(o,little),rd32=o=>dv.getUint32(o,little);
        const ifd0=tiff+rd32(tiff+4);
        const find=(ifd,tag)=>{const n=rd16(ifd);for(let i=0;i<n;i++){const e=ifd+2+i*12;if(rd16(e)===tag)return e}return -1};
        const ep=find(ifd0,0x8769);if(ep<0)return null;
        const exif=tiff+rd32(ep+8);const te=find(exif,0xA405);if(te<0)return null; // FocalLengthIn35mmFilm
        const val=rd16(te+2)===3?rd16(te+8):rd32(te+8);return(val>0&&val<300)?val:null;
      }}
    off+=2+size;
  }
  return null;
}catch(e){return null}}
function wPhotoPick(inp){if(!inp.files[0])return;WPHOTO=inp.files[0];$('#wPname').textContent='✓';
  const url=URL.createObjectURL(WPHOTO);$('#wPrev').innerHTML='<div class="viewwrap"><img src="'+url+'"></div>';
  const img=new Image();img.onload=()=>{const land=img.naturalWidth>=img.naturalHeight;
    (WPHOTO.arrayBuffer?WPHOTO.arrayBuffer():Promise.reject()).then(buf=>{const f35=readF35(buf);
      if(f35){const sw=land?36:24;const fov=Math.round(2*Math.atan(sw/(2*f35))*180/Math.PI);
        $('#wFov').value=fov;$('#wFovHint').innerHTML='<span class="mut">FOV stimato da EXIF ('+f35+'mm eq, '+(land?'orizz.':'vert.')+'): '+fov+'° — correggibile</span>';}
      else $('#wFovHint').innerHTML='<span class="mut">EXIF assente: valore tipico 66° (o correggi a mano)</span>';
    }).catch(()=>{$('#wFovHint').innerHTML='<span class="mut">EXIF non leggibile: usa 66° o correggi a mano</span>'});
  };img.src=url;}
async function saveWindow(){
  let roomId=$('#wRoom').value;const newName=$('#wRoomNew').value.trim();
  if(newName){const r=addRoom(newName);roomId=r.id}
  const room=PLAN.rooms.find(x=>x.id===roomId);
  if(!room){alert('Scegli o crea una stanza');return}
  if(!$('#wName').value.trim()||LOCKED==null){alert('Manca nome o direzione');return}
  let photo=null;if(WPHOTO){const fd=new FormData();fd.append('file',WPHOTO);photo=(await (await fetch('api/photo',{method:'POST',body:fd})).json()).photo}
  room.windows=room.windows||[];
  const c=roomCenter(room);const fov=Math.max(20,Math.min(140,parseFloat($('#wFov').value)||66));
  // La bussola del telefono misura la direzione VERA. Dentro la pianta si
  // salva in angolo del disegno, se no una finestra presa col telefono e una
  // messa a mano sullo stesso muro finirebbero a puntare da due parti.
  const dati={name:$('#wName').value.trim(),azimuth:azDisegno(LOCKED),photo_az:LOCKED,photo_fov:fov,
    photo_alt:(LOCKEDALT!=null?LOCKEDALT:0)};
  const gia=WEDIT&&room.windows.find(w=>w.id===WEDIT);
  if(gia){
    // NON SI SOVRASCRIVE LA POSIZIONE: la finestra e' gia' sul suo muro, messa
    // a mano. E non si cancella la foto vecchia se stavolta non se ne carica
    // una: si stava correggendo la direzione, non buttando via lo scatto.
    Object.assign(gia,dati); if(photo) gia.photo=photo;
  } else {
    room.windows.push(Object.assign({id:uid(),photo:photo,alt_min:0,x:c.x,y:c.y},dati));
  }
  WEDIT=null;
  SEL=roomId;closeWin();render();savePlan();
}
// ================= CASA IN 3D (three.js) =================
let THREEREADY=false, H3={}, HHEIGHT=250;
const CX3=500, CZ3=320, WT3_DEF=8, DW3=46, WW3=66, SILL3=90, WHEAD3=210; // costanti piano→3D
// Le quote delle finestre per tipo: una portafinestra parte da terra, una
// finestra alta sta sopra la testa. Senza distinzione erano tutte lo stesso
// buco a mezza altezza, e in 3D una portafinestra sembrava una finestra.
const QUOTE_FIN={finestra:[90,210], portafinestra:[0,210], alta:[150,225]};
const DH3=200, ANTA_T=5, ANTA_APERTA=68; // porta: altezza, spessore dell'anta, quanto e' aperta (gradi)
function loadScript(src){return new Promise((res,rej)=>{const s=document.createElement('script');s.src=src;s.onload=res;s.onerror=()=>rej(new Error(src));document.head.appendChild(s)})}
// (0.26) three 0.160 a moduli, la stessa versione del gioco: il tuo avatar entra nella stessa scena della casa.
// Il codice della casa continua a usare THREE.xxx: qui si mette insieme l'oggetto globale coi caricatori
async function ensureThree(){if(THREEREADY)return;const T=await import('three');
  const [{GLTFLoader},{OrbitControls},{GLTFExporter}]=await Promise.all([import('three/addons/loaders/GLTFLoader.js'),import('three/addons/controls/OrbitControls.js'),import('three/addons/exporters/GLTFExporter.js')]);
  window.THREE=Object.assign({},T,{GLTFLoader,OrbitControls,GLTFExporter});THREEREADY=true;}
// ==================== ATTACCA STANZE ====================
//
// Una stanza disegnata a mano non combacia mai: il muro fra due camere e'
// due muri a tre pixel di distanza, e in mezzo resta una fessura. In 3D si
// vede, e soprattutto falsa le distanze - che e' il motivo per cui questo
// modello esiste.
//
// COME SI ATTACCA. Non a coppie di stanze, ma per coordinate: si prendono
// tutte le x della casa, si raggruppano quelle che stanno entro la
// tolleranza, e a ognuna si da' il valore medio del gruppo. Poi le y. Due
// muri che erano a 3 pixel diventano lo stesso muro, esattamente, e restano
// tali anche se le stanze coinvolte sono tre o quattro - cosa che
// l'allineamento a coppie sbaglia, perche' ogni coppia tira da una parte.
//
// SOLO SUGLI ASSI, per scelta: quasi tutte le case sono ortogonali, e un
// muro storto allineato per coordinate si spezzerebbe. I vertici di un
// poligono obliquo restano dove sono.
const TOLL_ATTACCO=12; // quanto lontani possono essere due muri per essere lo stesso

function gruppiDiCoordinate(valori,toll){
  const ord=valori.slice().sort((a,b)=>a-b), gruppi=[];
  for(const v of ord){
    const g=gruppi[gruppi.length-1];
    if(g && v-g[g.length-1]<=toll) g.push(v); else gruppi.push([v]);
  }
  const mappa=new Map();
  for(const g of gruppi){const m=Math.round(g.reduce((a,b)=>a+b,0)/g.length);for(const v of g)mappa.set(v,m);}
  return mappa;
}

function attaccaStanze(){
  const xs=[],ys=[];
  for(const r of (PLAN.rooms||[])){
    if(r.poly&&r.poly.length){for(const v of r.poly){xs.push(v[0]);ys.push(v[1])}}
    else {xs.push(r.x,r.x+r.w);ys.push(r.y,r.y+r.h)}
  }
  if(!xs.length){alert("Non ci sono ancora stanze");return}
  const mx=gruppiDiCoordinate(xs,TOLL_ATTACCO), my=gruppiDiCoordinate(ys,TOLL_ATTACCO);
  let spostati=0;
  const sx=v=>{const n=mx.get(v); if(n!==v)spostati++; return n!=null?n:v};
  const sy=v=>{const n=my.get(v); if(n!==v)spostati++; return n!=null?n:v};
  for(const r of (PLAN.rooms||[])){
    if(r.poly&&r.poly.length){ r.poly=r.poly.map(v=>[sx(v[0]),sy(v[1])]); }
    else { const x2=sx(r.x+r.w), y2=sy(r.y+r.h);
      r.x=sx(r.x); r.y=sy(r.y);
      r.w=Math.max(20,x2-r.x); r.h=Math.max(20,y2-r.y); }
  }
  render(); savePlan(); if(H3.run) buildHouse3D();
  apriMura(spostati);
}

// ==================== L'ELENCO DELLE MURA ====================
//
// UN MURO UNA VOLTA SOLA. Il muro fra cucina e soggiorno appartiene a tutte
// e due: elencandolo per stanza comparirebbe due volte, e chi gli assegna
// uno spessore lo farebbe due volte con due valori diversi senza sapere
// quale vince. Si raggruppa per il muro, e le stanze diventano una sua
// proprieta' - chi ci confina - invece che il contrario.
// I MURI SI SPEZZANO DOVE CI ARRIVA UN'ALTRA STANZA. Il lato in fondo al
// soggiorno e il lato del balcone stanno sulla stessa linea ma non sono lo
// stesso segmento: il balcone corre anche davanti alla cucina. Presi interi
// erano due muri diversi, uno sopra l'altro, e la porta bucava solo quello
// della stanza a cui apparteneva: dall'altra parte restava il muro pieno.
// Spezzati nei vertici delle altre stanze, i pezzi coincidono, il muro si
// costruisce una volta e la porta lo buca da tutte e due le parti. Ed e' su
// questi pezzi che si toglie un muro (PLAN.muriTolti).
function verticiAllaQuota(base){const V=[];for(const o of (PLAN.rooms||[]))if(Math.abs((o.base||0)-base)<4)for(const v of roomPts(o))V.push(v);return V;}
function latiDi(r){const pts=roomPts(r),V=verticiAllaQuota(r.base||0),out=[];
  for(let i=0;i<pts.length;i++){const a=pts[i],b=pts[(i+1)%pts.length];const L=Math.hypot(b[0]-a[0],b[1]-a[1]);if(L<1)continue;
    const ts=[];for(const v of V){const pr=projOnSeg(v,a,b);if(pr.dist<1.5&&pr.d>2&&pr.d<L-2)ts.push(pr.d)}
    ts.sort((x,y)=>x-y);const ux=(b[0]-a[0])/L,uy=(b[1]-a[1])/L;let prev=a,pd=0;
    for(const t of ts){if(t-pd<2)continue;const q=[Math.round(a[0]+ux*t),Math.round(a[1]+uy*t)];out.push([prev,q]);prev=q;pd=t;}
    out.push([prev,b]);}
  return out;}
function muroTolto(a,b){return !!(PLAN.muriTolti||{})[chiaveMuro(a,b)];}
window.togliMuro=(k)=>{PLAN.muriTolti=PLAN.muriTolti||{};if(PLAN.muriTolti[k])delete PLAN.muriTolti[k];else PLAN.muriTolti[k]=true;
  savePlan();render();if($('#muriModal').classList.contains('on'))apriMura(null);if(H3.run)buildHouse3D();};
function chiaveMuro(a,b){
  const k=q=>Math.round(q[0])+','+Math.round(q[1]);
  return [k(a),k(b)].sort().join('#');
}
function elencaMura(){
  const m=new Map();
  for(const r of (PLAN.rooms||[])){
    for(const [a,b] of latiDi(r)){
      const L=Math.hypot(b[0]-a[0],b[1]-a[1]); if(L<2) continue;
      const k=chiaveMuro(a,b);
      if(!m.has(k)) m.set(k,{k:k,len:Math.round(L),stanze:[]});
      const e=m.get(k); if(!e.stanze.includes(r.name)) e.stanze.push(r.name);
    }
  }
  // prima le divisorie: sono quelle su cui si ragiona, le esterne vengono dopo
  return [...m.values()].sort((a,b)=>(b.stanze.length-a.stanze.length)||(b.len-a.len));
}
function spessoreMuro(k){const M=PLAN.muri||{};let s=M[k];
  if(!(s>0)){const [a,b]=k.split('#').map(t=>t.split(',').map(Number));
    for(const kk in M){const [c,d]=kk.split('#').map(t=>t.split(',').map(Number));
      if(projOnSeg(a,c,d).dist<1.5&&projOnSeg(b,c,d).dist<1.5){s=M[kk];break}}}
  return (s>0?s:(PLAN.muroDefault||WT3_DEF));}
function setSpessoreMuro(k,v){PLAN.muri=PLAN.muri||{};
  const n=Math.max(2,Math.min(60,Math.round(+v||0)));
  PLAN.muri[k]=n; savePlan(); if(H3.run) buildHouse3D();}
function spessoreATutti(){
  const v=Math.max(2,Math.min(60,Math.round(parseFloat($('#muroTutti').value)||0)));
  if(!v){alert('Scrivi uno spessore');return}
  PLAN.muroDefault=v; PLAN.muri={}; // uno spessore unico: le eccezioni si rifanno dopo
  savePlan(); apriMura(null); if(H3.run) buildHouse3D();
}
function apriMura(spostati){
  const mura=elencaMura();
  const div=mura.filter(x=>x.stanze.length>1), est=mura.filter(x=>x.stanze.length<2);
  $('#muriEsito').innerHTML = (spostati==null?'':
      (spostati?`✔️ Allineati <b>${spostati}</b> lati.`:"✔️ Era tutto gia allineato.")+' ')
    + `<b>${div.length}</b> mura di separazione, <b>${est.length}</b> esterne. `
    + "Ogni muro compare una volta sola anche se divide due stanze.";
  const riga=(w,tag)=>`<div class="row" style="justify-content:space-between;margin:4px 0">
      <div><b>${tag}</b> <span class="mut">${w.stanze.map(escapeHtml).join(' ↔ ')}</span></div>
      <div class="row"><span class="mut">lungo ${w.len}</span>
        <input type="number" min="2" max="60" value="${spessoreMuro(w.k)}" style="width:72px"
               onchange="setSpessoreMuro('${w.k}',this.value)">
        <button class="sec" onclick="togliMuro('${w.k}')">${(PLAN.muriTolti||{})[w.k]?'↩️ rimetti':'🚫 togli'}</button></div></div>`;
  $('#muriLista').innerHTML =
      (div.length?'<div class="mut" style="margin-top:8px">Di separazione</div>'+div.map(w=>riga(w,'‖')).join(''):'')
    + (est.length?'<div class="mut" style="margin-top:10px">Esterne</div>'+est.map(w=>riga(w,'▭')).join(''):'');
  $('#muriModal').classList.add('on');
}

// LA STANZA CHIESTA DALLA PAGINA CHE CI INCORPORA.
//
// /embed?room=... arrivava fin qui dentro window.VWGK_EMBED e poi non lo
// guardava nessuno: la pagina della camera da letto mostrava la casa intera
// senza dire quale pezzo fosse la camera da letto, e per il lettore era una
// figura in cima e basta.
//
// SI ACCETTA ANCHE IL NOME, non solo l id: chi incorpora e WordPress, che le
// stanze di Home Assistant le associa per nome. Chiedere che conosca gli id
// interni di questo add-on vorrebbe dire tenere allineate due liste a mano.
function stanzaChiesta(){const e=(window.VWGK_EMBED||{}).room; return e?String(e).trim():''}
function eLaChiesta(r){const q=stanzaChiesta(); if(!q) return false;
  return r.id===q || String(r.name||'').trim().toLowerCase()===q.toLowerCase();}

function roomPts(r){return (r.poly&&r.poly.length>=3)?r.poly:[[r.x,r.y],[r.x+r.w,r.y],[r.x+r.w,r.y+r.h],[r.x,r.y+r.h]];}
function projOnSeg(p,a,b){const dx=b[0]-a[0],dy=b[1]-a[1],L2=dx*dx+dy*dy||1;let t=((p[0]-a[0])*dx+(p[1]-a[1])*dy)/L2;t=Math.max(0,Math.min(1,t));const px=a[0]+t*dx,py=a[1]+t*dy;return {d:t*Math.sqrt(L2),len:Math.sqrt(L2),dist:Math.hypot(p[0]-px,p[1]-py)};}
async function openHouse3D(){$('#house3dModal').classList.add('on');const box=$('#house3dBox');box.innerHTML='<div class="mut" style="padding:20px">Carico il motore 3D…</div>';
  try{await ensureThree()}catch(e){box.innerHTML='<span class="bad" style="padding:20px;display:block">Impossibile caricare il motore 3D (vendor mancante).</span>';return}
  buildHouse3D();}
function closeHouse3D(){if(H3.avatar){try{H3.avatar.togli()}catch(e){}}H3.run=false;if(H3.animId)cancelAnimationFrame(H3.animId);if(H3.onResize)removeEventListener('resize',H3.onResize);if(H3.renderer){try{H3.renderer.dispose();H3.renderer.forceContextLoss&&H3.renderer.forceContextLoss()}catch(e){}}H3={};$('#house3dBox').innerHTML='';$('#house3dModal').classList.remove('on');}
window.setH3Height=(v)=>{PLAN.floorHeight=Math.max(80,Math.min(600,+v));$('#floorH').value=PLAN.floorHeight;savePlan();if(H3.run)buildHouse3D();};
function buildHouse3D(){
  H3.run=false;if(H3.animId)cancelAnimationFrame(H3.animId);
  const box=$('#house3dBox');box.innerHTML='';
  const W=box.clientWidth||800, Hh=box.clientHeight||500;
  const scene=new THREE.Scene();scene.background=new THREE.Color(0x0b0f14);
  let grp=new THREE.Group();scene.add(grp);const radice=grp;
  scene.add(new THREE.HemisphereLight(0xffffff,0x30363d,0.95));
  const dl=new THREE.DirectionalLight(0xffffff,0.7);dl.position.set(250,450,180);scene.add(dl);
  grp.add(new THREE.GridHelper(1200,24,0x223047,0x1a2130));
  const wallMat=new THREE.MeshStandardMaterial({color:0x9aa4b0,roughness:.95,metalness:0,side:THREE.DoubleSide});
  const H=PLAN.floorHeight||250;
  // LO SPESSORE ARRIVA DAL MURO, non e' piu' uno solo per tutta la casa: un
  // divisorio di cartongesso e un muro portante non misurano uguale, e le
  // distanze fra le stanze dipendono da quale dei due c'e' in mezzo.
  // IL TETTO (0.25). PLAN.tetto = {lato, piano, celle:[[x,y,z0,z1],...]}: una cella per colonna, z in cm sopra il
  // pavimento del piano «piano». Nella mansarda i muri non passano il tetto: sotto le falde si tagliano alla sua quota.
  let TETTO_CLIP=null;
  function clipTetto(){const T=PLAN.tetto;if(!T||!(T.celle||[]).length)return null;const L=T.lato||50,m=new Map();
    for(const [x,y,z0] of T.celle){const k=Math.floor(x/L)+','+Math.floor(y/L);m.set(k,Math.min(m.has(k)?m.get(k):1e9,z0));}
    return (x,y)=>{const v=m.get(Math.floor(x/L)+','+Math.floor(y/L));return v==null?Infinity:v;};}
  function disegnaTetto(){const T=PLAN.tetto;if(!T||!(T.celle||[]).length)return;const L=T.lato||50;
    const mat=new THREE.MeshStandardMaterial({color:0x8b4a2b,roughness:.9,metalness:0});
    for(const [x,y,z0,z1] of T.celle){const m=new THREE.Mesh(new THREE.BoxGeometry(L,Math.max(1,z1-z0),L),mat);
      m.position.set(x-CX3,(z0+z1)/2,y-CZ3);grp.add(m);}}
  function wallBox(p0,p1,y0,y1,sp){
    if(TETTO_CLIP){const n=Math.max(1,Math.ceil(Math.hypot(p1[0]-p0[0],p1[1]-p0[1])/25));const clip=TETTO_CLIP;TETTO_CLIP=null;
      try{for(let i=0;i<n;i++){const a=[p0[0]+(p1[0]-p0[0])*i/n,p0[1]+(p1[1]-p0[1])*i/n],b=[p0[0]+(p1[0]-p0[0])*(i+1)/n,p0[1]+(p1[1]-p0[1])*(i+1)/n];
        const top=Math.min(y1,clip((a[0]+b[0])/2,(a[1]+b[1])/2));if(top-y0>0.6)wallBox(a,b,y0,top,sp);}}finally{TETTO_CLIP=clip;}return;}
    const ax=p0[0]-CX3,az=p0[1]-CZ3,bx=p1[0]-CX3,bz=p1[1]-CZ3;const len=Math.hypot(bx-ax,bz-az);if(len<0.6||y1-y0<0.6)return;
    const m=new THREE.Mesh(new THREE.BoxGeometry(len,y1-y0,sp||WT3_DEF),wallMat);m.position.set((ax+bx)/2,(y0+y1)/2,(az+bz)/2);m.rotation.y=-Math.atan2(bz-az,bx-ax);grp.add(m);}

  // LA RINGHIERA, DOVE UN BALCONE NON TOCCA LA CASA.
  //
  // Un balcone chiuso da quattro muri non e' un balcone: e' uno sgabuzzino. E
  // nel 3D di questa casa non e' un dettaglio estetico - la casa serve a
  // capire da dove si vedra' l'eclissi, e un muro pieno dove c'e' una
  // ringhiera nasconde proprio il cielo che si sta cercando.
  //
  // SI COSTRUISCE A ELEMENTI, non come un muro basso: un corrimano in cima,
  // un correntino a meta' e i montanti ogni quaranta. Un parapetto pieno
  // riproporrebbe lo stesso problema in piccolo, perche' fino a un metro il
  // cielo lo toglie lo stesso.
  const RING_H=100, RING_S=40, RING_T=4;
  const ringMat=new THREE.MeshStandardMaterial({color:0x8b949e,roughness:.6,metalness:.3});
  function ringhiera(p0,p1,y0){
    const ax=p0[0]-CX3, az=p0[1]-CZ3, bx=p1[0]-CX3, bz=p1[1]-CZ3;
    const len=Math.hypot(bx-ax,bz-az); if(len<0.6) return;
    const ang=-Math.atan2(bz-az,bx-ax);
    const barra=(y,alt)=>{const m=new THREE.Mesh(new THREE.BoxGeometry(len,alt,RING_T),ringMat);
      m.position.set((ax+bx)/2,y,(az+bz)/2);m.rotation.y=ang;grp.add(m);};
    barra(y0+RING_H, RING_T);          // corrimano
    barra(y0+RING_H*0.55, RING_T*0.7); // correntino
    const ux=(bx-ax)/len, uz=(bz-az)/len;
    for(let d=RING_S/2; d<len; d+=RING_S){
      const m=new THREE.Mesh(new THREE.BoxGeometry(RING_T,RING_H,RING_T),ringMat);
      m.position.set(ax+ux*d, y0+RING_H/2, az+uz*d); m.rotation.y=ang; grp.add(m);
    }
  }

  // IL LATO TOCCA LA CASA? Non basta che due stanze condividano lo STESSO
  // L'ANTA DELLA PORTA, DISEGNATA APERTA.
  //
  // Prima qui c'era solo il buco. Un vano a tutta altezza senza niente
  // dentro non si legge come una porta: sembra un muro che manca, e in una
  // casa vista da fuori era impossibile dire dove si entra.
  //
  // APERTA E NON CHIUSA per una ragione pratica: chiusa nasconderebbe la
  // stanza dietro, e questo modello serve a guardarci dentro. Aperta dice
  // dov'e' il passaggio e da che parte si spinge, senza chiudere la vista.
  //
  // DA CHE PARTE SI APRE: verso il centro della stanza, che e' il verso
  // giusto quasi sempre e comunque quello che non fa sparire l'anta dentro
  // al muro. Il cardine sta sul primo dei due stipiti.
  const ANTA_COL={interna:0xb08968, principale:0x6e4b3a, esterna:0x7d8590, balcone:0x9ecbff, vetro:0xa5d8ff};
  function anta(q0,q1,y0,alt,cen,tipo){
    const len=Math.hypot(q1[0]-q0[0],q1[1]-q0[1]); if(len<4) return;
    const ux=(q1[0]-q0[0])/len, uy=(q1[1]-q0[1])/len;
    const rad=ANTA_APERTA*Math.PI/180;
    // due versi possibili: si tiene quello che porta l'anta verso il centro
    const prova=(sg)=>{const c=Math.cos(sg*rad), sn=Math.sin(sg*rad);
      return [q0[0]+(ux*c-uy*sn)*len, q0[1]+(ux*sn+uy*c)*len];};
    const A=prova(1), B=prova(-1);
    const dist=(e)=>Math.hypot((q0[0]+e[0])/2-cen.x,(q0[1]+e[1])/2-cen.y);
    const fine = dist(A)<=dist(B) ? A : B;
    const vetro = (tipo==='balcone'||tipo==='vetro');
    const mat=new THREE.MeshStandardMaterial({color:ANTA_COL[tipo]||0xb08968,
      roughness:vetro?.15:.8, metalness:vetro?.1:0,
      transparent:vetro, opacity:vetro?.45:1, side:THREE.DoubleSide});
    const m=new THREE.Mesh(new THREE.BoxGeometry(len,alt,ANTA_T),mat);
    m.position.set((q0[0]+fine[0])/2-CX3, y0+alt/2, (q0[1]+fine[1])/2-CZ3);
    m.rotation.y=-Math.atan2(fine[1]-q0[1], fine[0]-q0[0]);
    grp.add(m);
    // la maniglia, sul lato libero: e' il dettaglio che fa capire il verso
    const mm=new THREE.Mesh(new THREE.SphereGeometry(4,8,6),
      new THREE.MeshStandardMaterial({color:0xd7dce3,roughness:.3,metalness:.6}));
    mm.position.set(q0[0]+(fine[0]-q0[0])*0.86-CX3, y0+alt*0.5, q0[1]+(fine[1]-q0[1])*0.86-CZ3);
    grp.add(mm);
  }

  // spigolo: un balcone si appoggia a un pezzo del muro, non a tutto. Si
  // guarda se il punto di mezzo del lato sfiora il perimetro di un'altra
  // stanza - con qualche unita' di tolleranza, perche' i due disegni non
  // combaciano mai al punto esatto.
  function attaccatoACasa(a,b,mia){
    const mx=(a[0]+b[0])/2, my=(a[1]+b[1])/2;
    for(const o of (PLAN.rooms||[])){
      if(o===mia) continue;
      const q=roomPts(o);
      for(let i=0;i<q.length;i++){
        const pr=projOnSeg([mx,my], q[i], q[(i+1)%q.length]);
        if(pr.dist<8) return true;
      }
    }
    return false;
  }
  let nRooms=0,nWin=0,nDoor=0,nFurn=0,nLoft=0;
  let builtEdges; // dedup muri condivisi tra stanze adiacenti (per piano)
  // UN PIANO: le stanze e i mobili di PLAN.rooms/PLAN.furniture, nel gruppo grp
  function disegnaPiano(){ builtEdges=new Set();
  const ekp=p=>Math.round(p[0]/3)+'_'+Math.round(p[1]/3);
  const edgeKey=(a,b,base)=>Math.round(base/4)+'|'+[ekp(a),ekp(b)].sort().join('#');
  // LE APERTURE APPARTENGONO AL MURO, NON ALLA STANZA.
  //
  // Il muro fra due stanze si costruisce una volta sola: la seconda stanza lo
  // trova gia' fatto e lo salta. Ma le porte le teneva ognuna per se', quindi
  // una porta della stanza che arrivava seconda non bucava niente - spariva,
  // e in una casa dove quasi ogni porta e' su un muro condiviso sparivano
  // quasi tutte. Non si vedeva come un errore: si vedeva come un muro.
  //
  // Percio' prima si raccolgono tutte le aperture della casa e si attaccano
  // al muro, con la loro posizione sul piano; poi chi costruisce quel muro le
  // riproietta sopra. Chi lo costruisce non conta piu'.
  const APERTURE=new Map();
  for(const r of (PLAN.rooms||[])){
    const pts=roomPts(r), base=r.base||0, cen=roomCenter(r);
    const es=latiDi(r);
    const attacca=(pt,tipo,st)=>{
      let bi=0,bd=1e9; es.forEach((e,i)=>{const pr=projOnSeg(pt,e[0],e[1]);if(pr.dist<bd){bd=pr.dist;bi=i}});
      const k=edgeKey(es[bi][0],es[bi][1],base);
      if(!APERTURE.has(k))APERTURE.set(k,[]);
      APERTURE.get(k).push({pt:pt,type:tipo,st:st,cen:cen});
    };
    for(const d of (r.doors||[])) attacca([d.x,d.y],'door',d.type);
    for(const w of (r.windows||[])) attacca([w.x!=null?w.x:cen.x, w.y!=null?w.y:cen.y],'window',w.tipo||'finestra');
  }
  for(const r of (PLAN.rooms||[])){nRooms++;const pts=roomPts(r);const ext=isExt(r);
    const base=r.base||0; // quota Z del pavimento
    const rh=Math.max(60, r.height!=null?r.height:H); // altezza muri della stanza
    // pavimento (alla quota base)
    const shape=new THREE.Shape(pts.map(p=>new THREE.Vector2(p[0]-CX3,-(p[1]-CZ3))));
    const fgeo=new THREE.ShapeGeometry(shape);fgeo.rotateX(-Math.PI/2);
    const fmesh=new THREE.Mesh(fgeo,new THREE.MeshStandardMaterial({color:ext?0x1c3b2a:0x18314f,roughness:1,side:THREE.DoubleSide}));fmesh.position.y=base;grp.add(fmesh);
    // IL TETTO DELLA PERGOLA. Senza, e' una scatola aperta in cima e in 3D
    // sembra un recinto: e' proprio la copertura che la distingue da un
    // balcone. Traslucida, perche' una pergola la luce la fa passare.
    if(chiusaMaEsterna(r)){
      const tg=new THREE.ShapeGeometry(new THREE.Shape(pts.map(q=>new THREE.Vector2(q[0]-CX3,-(q[1]-CZ3)))));
      tg.rotateX(-Math.PI/2);
      const tm=new THREE.Mesh(tg,new THREE.MeshStandardMaterial({color:0x8b6f47,roughness:.9,
        transparent:true,opacity:.72,side:THREE.DoubleSide}));
      tm.position.y=base+rh; grp.add(tm);
    }
    const es=latiDi(r);
    const cen=roomCenter(r);
    nDoor+=(r.doors||[]).length; nWin+=(r.windows||[]).length;
    // muri con vani (dalla quota base a base+rh); salta i muri già costruiti da una stanza adiacente
    es.forEach((e,i)=>{const a=e[0],b=e[1];const L=Math.hypot(b[0]-a[0],b[1]-a[1]);if(L<1)return;
      const key=edgeKey(a,b,base);if(builtEdges.has(key))return;builtEdges.add(key);
      if(muroTolto(a,b))return; // tolto a mano: qui si passa (es. pianerottolo -> rampa)
      const spess=spessoreMuro(chiaveMuro(a,b));
      // Un balcone ha muro solo dove si appoggia alla casa: sul resto, ringhiera.
      // La pergola e' esterna ma chiusa: muri veri su tutti i lati, come una
      // stanza. Il balcone no - li' dove non tocca la casa va la ringhiera.
      if(r.subtype==='balcone' && !attaccatoACasa(a,b,r)){ ringhiera(a,b,base); return; }
      const ux=(b[0]-a[0])/L,uy=(b[1]-a[1])/L;const pAt=dd=>[a[0]+ux*dd,a[1]+uy*dd];
      // riproiettate sul muro che si sta costruendo: la stessa apertura vista
      // dall'altra stanza avrebbe il muro percorso al contrario.
      const ops=(APERTURE.get(key)||[]).map(o=>{const pr=projOnSeg(o.pt,a,b);
        return {d:pr.d,type:o.type,st:o.st,cen:o.cen};}).sort((x,y)=>x.d-y.d);let cur=0;
      for(const op of ops){const half=(op.type==='window'?WW3:DW3)/2;const s=op.d-half,e2=op.d+half;
        if(s>cur)wallBox(pAt(cur),pAt(Math.max(cur,s)),base,base+rh,spess);
        const q0=pAt(Math.max(0,s)),q1=pAt(Math.min(L,e2));
        if(op.type==='window'){
          const qq=QUOTE_FIN[op.st]||[SILL3,WHEAD3];
          const sill=Math.min(qq[0],rh), head=Math.min(qq[1],rh);
          if(sill>0) wallBox(q0,q1,base,base+sill,spess);
          if(head<rh) wallBox(q0,q1,base+head,base+rh,spess);
          // L'ANTA, aperta come quella delle porte e per lo stesso motivo:
          // un vetro chiuso davanti al vano non si distingue da un muro, e
          // qui il punto e' proprio vedere da dove si guarda fuori.
          if(head>sill) anta(q0,q1,base+sill,head-sill,op.cen||cen,'vetro');
        }
        else{
          // L'ARCHITRAVE. Senza, il vano arriva al soffitto e il muro sopra
          // sparisce: da fuori si vede la stanza attraverso una fessura alta
          // tutto il piano, che nessuna casa ha.
          const alt=Math.min(DH3,rh);
          if(alt<rh) wallBox(q0,q1,base+alt,base+rh,spess);
          anta(q0,q1,base,alt,op.cen||cen,op.st);
        }
        cur=Math.max(cur,e2);}
      if(cur<L)wallBox(pAt(cur),pAt(L),base,base+rh,spess);
    });
    // soppalco: soletta all'altezza stanza SOLO se nessuna stanza è già impilata sopra
    const hasUpper=(PLAN.rooms||[]).some(o=>o!==r && Math.abs((o.base||0)-(base+rh))<6 && polyInt(roomPts(o),pts));
    if(r.height!=null && r.height<H-1 && !hasUpper){nLoft++;
      const lgeo=new THREE.ShapeGeometry(new THREE.Shape(pts.map(p=>new THREE.Vector2(p[0]-CX3,-(p[1]-CZ3)))));lgeo.rotateX(-Math.PI/2);
      const slab=new THREE.Mesh(lgeo,new THREE.MeshStandardMaterial({color:0xd29922,transparent:true,opacity:0.5,roughness:1,side:THREE.DoubleSide}));
      slab.position.y=base+rh;grp.add(slab);}
  }
  // mobili con modello .glb (gli altri sono nascosti per scelta), alla quota della stanza che li contiene
  const loader=new THREE.GLTFLoader();
  for(const f of (PLAN.furniture||[])){const t=(PLAN.templates||[]).find(x=>x.id===f.tpl);if(!t||!t.model)continue;nFurn++;
    const fr=roomOfFurn(f);const fb=fr?(fr.base||0):0;
    loader.load('models/'+t.model,(gltf)=>{const obj=gltf.scene;const b=new THREE.Box3().setFromObject(obj);const sz=b.getSize(new THREE.Vector3());
      const sc=Math.min((f.w||t.w)/(sz.x||1),(f.h||t.h)/(sz.z||1));obj.scale.setScalar(sc);
      const b2=new THREE.Box3().setFromObject(obj);const c2=b2.getCenter(new THREE.Vector3());
      obj.position.x-=c2.x;obj.position.z-=c2.z;obj.position.y-=b2.min.y;
      const piv=new THREE.Group();piv.add(obj);piv.position.set(f.x-CX3,fb,f.y-CZ3);piv.rotation.y=-(f.rot||0)*Math.PI/180;grp.add(piv);
    },undefined,(err)=>{console.warn('glb load fail',t.model,err)});
  }
  }
  // TUTTO IL PALAZZO (0.24): i piani uno sopra l'altro, ognuno con la sua planimetria; quelli che non ce
  // l'hanno sono uguali al mio appartamento. Senza la spunta si vede solo il piano che si sta disegnando.
  const tutto=!!($('#h3tutto')&&$('#h3tutto').checked), nPiani=Math.max(1,PLAN.piani||1);
  const pianoTetto=(PLAN.tetto&&PLAN.tetto.piano)||0;
  const conTetto=(n)=>{if(n!==pianoTetto){disegnaPiano();return;}TETTO_CLIP=clipTetto();try{disegnaPiano();}finally{TETTO_CLIP=null;}disegnaTetto();};
  if(!tutto) conTetto(ATTIVO);
  else{
    const salvaR=PLAN.rooms, salvaF=PLAN.furniture, mio=PLAN.pianoMio||1, pl=PLAN.planimetrie||{};
    const mioR=ATTIVO===mio?salvaR:((pl[mio]||{}).rooms||salvaR), mioF=ATTIVO===mio?salvaF:((pl[mio]||{}).furniture||[]);
    for(let n=1;n<=nPiani;n++){
      let r, f;
      if(n===ATTIVO){ r=salvaR; f=salvaF; }
      else if(n===mio){ r=mioR; f=mioF; }
      else if(((pl[n]||{}).rooms||[]).length){ r=pl[n].rooms; f=pl[n].furniture||[]; }
      else { r=mioR; f=[]; }
      grp=new THREE.Group(); grp.position.y=(n-1)*(H+20); radice.add(grp);
      PLAN.rooms=r; PLAN.furniture=f;
      try{ conTetto(n); } finally { PLAN.rooms=salvaR; PLAN.furniture=salvaF; }
    }
    grp=radice;
  }
  // camera + controlli
  const cam=new THREE.PerspectiveCamera(55,W/Hh,1,9000);cam.position.set(0,H*2.6+(tutto?(nPiani-1)*H:0),760+(tutto?nPiani*120:0));
  const rnd=new THREE.WebGLRenderer({antialias:true});rnd.setPixelRatio(Math.min(devicePixelRatio,2));rnd.setSize(W,Hh);box.appendChild(rnd.domElement);
  const ctrl=new THREE.OrbitControls(cam,rnd.domElement);ctrl.target.set(0,tutto?(nPiani-1)*(H+20)/2:40,0);ctrl.enableDamping=true;ctrl.update();
  const onResize=()=>{const w2=box.clientWidth,h2=box.clientHeight;if(!w2||!h2)return;cam.aspect=w2/h2;cam.updateProjectionMatrix();rnd.setSize(w2,h2)};
  addEventListener('resize',onResize);
  $('#house3dInfo').textContent=`${nRooms} stanze · ${nWin} finestre · ${nDoor} porte · ${nFurn} mobili 3D`+(nLoft?` · ${nLoft} con soppalco`:'')+((nFurn===0)?' — nessun mobile ha un modello .glb':'');
  // (0.26) i muri (per non passarci attraverso) e il punto di partenza dell'avatar: il centro della stanza più grande
  // del mio appartamento, alla sua quota (con tutto il palazzo, al mio piano)
  const muri=[];radice.traverse(o=>{if(o.isMesh&&o.material===wallMat)muri.push(o)});
  const mio=PLAN.pianoMio||1, stanze=(tutto||ATTIVO===mio)?mieStanze():PLAN.rooms;
  let grande=null,area=-1;for(const r of (stanze||[])){const p=roomPts(r);let a=0;for(let i=0;i<p.length;i++){const q=p[(i+1)%p.length];a+=p[i][0]*q[1]-q[0]*p[i][1]}a=Math.abs(a)/2;if(a>area){area=a;grande=r}}
  let px=0,pz=0;if(grande){const p=roomPts(grande);for(const q of p){px+=q[0];pz+=q[1]}px=px/p.length-CX3;pz=pz/p.length-CZ3}
  const pianoY=(tutto?(mio-1)*(H+20):0)+((grande&&grande.base)||0);
  rnd.useLegacyLights=true;   // le luci della casa come con la versione di prima di three
  H3={run:true,renderer:rnd,controls:ctrl,onResize,scene,grp:radice,cam,muri,partenza:{x:px,z:pz},pianoY,avatar:null};
  if(AVATAR_VOLUTO)avatarAvvia();
  gemello3D(radice,tutto,H);
  let prima=performance.now();
  (function loop(){if(!H3.run)return;H3.animId=requestAnimationFrame(loop);const ora=performance.now(),dt=(ora-prima)/1000;prima=ora;
    if(H3.avatar)try{H3.avatar.aggiorna(dt)}catch(e){console.warn('avatar',e)}
    ctrl.update();rnd.render(scene,cam)})();
}
// (0.26) IL TUO AVATAR DEL GIOCO: si entra con l'account di Another Wilde Web, poi WASD/frecce per camminare, Maiusc
// per correre, il mouse per girare attorno (avatar.js). Resta acceso alle riaperture finché non lo spegni
let AVATAR_VOLUTO=false;try{AVATAR_VOLUTO=localStorage.getItem('eclipse:avatar')==='1'}catch(e){}
function avatarSegna(v){AVATAR_VOLUTO=v;try{localStorage.setItem('eclipse:avatar',v?'1':'0')}catch(e){}const b=$('#h3avatar');if(b)b.classList.toggle('on',v)}
function giocoChiudi(){$('#giocoLogin').style.display='none'}
async function avatarCommuta(){if(H3.avatar){H3.avatar.togli();H3.avatar=null;avatarSegna(false);$('#house3dInfo').textContent='Avatar tolto.';return}
  const st=await fetch('api/gioco/stato').then(r=>r.json()).catch(()=>({}));
  if(!st.collegato){$('#giocoLogin').style.display='block';$('#giocoNome').focus();return}
  avatarSegna(true);avatarAvvia();}
async function giocoAccedi(e){e.preventDefault();$('#giocoErr').textContent='';
  const r=await fetch('api/gioco/accesso',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({nome:$('#giocoNome').value.trim(),password:$('#giocoPw').value})}).then(r=>r.json()).catch(()=>({ok:false,errore:'Eclipse non risponde.'}));
  $('#giocoPw').value='';
  if(!r.ok){$('#giocoErr').textContent=r.errore||'Accesso non riuscito.';return}
  giocoChiudi();avatarSegna(true);avatarAvvia();}
// (0.29) la planimetria nel gioco: il portale la passa all'Architetto, che ne fa una pergamena
// (0.31) scollega account del gioco: si cancella il gettone, poi si rientra con nome e password
async function giocoEsci(){if(!confirm('Scollegare account del gioco da Eclipse?'))return;await fetch('api/gioco/esci',{method:'POST'}).catch(()=>{});if(H3.avatar){H3.avatar.togli();H3.avatar=null}avatarSegna(false);$('#house3dInfo').textContent='Account del gioco scollegato: con Il mio avatar si entra di nuovo.';}
async function mandaCasaAlGioco(){const info=$('#house3dInfo');
  const st=await fetch('api/gioco/stato').then(r=>r.json()).catch(()=>({}));
  if(!st.collegato){$('#giocoLogin').style.display='block';$('#giocoNome').focus();info.textContent='Entra con il tuo account del gioco, poi premi di nuovo «Manda al gioco».';return}
  info.textContent='Mando la casa al gioco…';
  const r=await fetch('api/gioco/manda_casa',{method:'POST'}).then(async x=>{try{return await x.json()}catch(e){return {ok:false,errore:'Eclipse ha risposto '+x.status}}}).catch(()=>({ok:false,errore:'Eclipse non risponde.'}));
  info.textContent=r.ok?(r.messaggio||'Casa mandata al gioco.'):('Casa non mandata: '+(r.errore||r.detail||'errore'));}
async function avatarAvvia(){if(!H3.run||H3.avatar||H3.caricaAvatar)return;H3.caricaAvatar=true;const info=$('#house3dInfo');info.textContent='Carico il tuo personaggio dal gioco…';
  try{const {creaAvatar}=await import('./avatar.js');
    const av=await creaAvatar({scena:H3.scene,radice:H3.grp,muri:H3.muri,partenza:H3.partenza,piano:H3.pianoY,telecamera:H3.cam,controlli:H3.controls,avviso:t=>console.warn('avatar',t)});
    if(!H3.run){av.togli();return}H3.avatar=av;info.textContent=av.nome+': WASD o frecce per camminare, Maiusc per correre, il mouse per girare attorno.';}
  catch(e){info.textContent='Avatar non disponibile: '+e.message;if(/entra|gettone|401/.test(e.message)){avatarSegna(false);$('#giocoLogin').style.display='block'}}
  finally{H3.caricaAvatar=false}}
// (0.27) IL GEMELLO NELLA CASA 3D: sopra ogni stanza un cartellino con lo stato vivo (luci accese, qualcuno, aperture,
// temperatura) e una luce calda dove le luci sono accese. Si rilegge ogni 10 s finché la vista è aperta. Le stanze degli
// altri piani si vedono solo con «tutto il palazzo»
function gemello3D(radice,tutto,H){
  const g=new THREE.Group();g.name='gemello';radice.add(g);H3.gemello=g;
  const stanzeDi={};const mio=PLAN.pianoMio||1;
  for(const r of (PLAN.rooms||[]))stanzeDi[r.id]={r,piano:mio};
  for(const [n,p] of Object.entries(PLAN.planimetrie||{}))for(const r of (p.rooms||[]))if(!stanzeDi[r.id])stanzeDi[r.id]={r,piano:+n};
  const cartello=(testo)=>{const c=document.createElement('canvas');c.width=512;c.height=96;const x=c.getContext('2d');
    x.fillStyle='rgba(13,17,23,.78)';x.beginPath();x.roundRect(4,8,504,80,22);x.fill();x.strokeStyle='rgba(240,165,0,.8)';x.lineWidth=3;x.stroke();
    x.fillStyle='#e6edf3';x.font='600 34px system-ui,sans-serif';x.textAlign='center';x.textBaseline='middle';x.fillText(testo,256,50);
    const t=new THREE.CanvasTexture(c);const sp=new THREE.Sprite(new THREE.SpriteMaterial({map:t,depthTest:false,transparent:true}));sp.scale.set(180,34,1);sp.renderOrder=20;return sp;};
  async function giro(){
    if(!H3.run||H3.gemello!==g)return;
    let st;try{st=(await fetch('api/casa/gemello').then(r=>r.ok?r.json():null))}catch(e){st=null}
    if(!st){setTimeout(giro,30000);return}
    g.clear();
    for(const z of st.stanze){const v=stanzeDi[z.id];if(!v)continue;
      if(!tutto&&v.piano!==ATTIVO)continue;
      const bits=[z.luci?('💡'+z.luci_accese+'/'+z.luci):'',z.presenza?'🧍':'',z.aperture.length?('🪟'+z.aperture.length):'',z.temperatura!=null?('🌡'+z.temperatura+'°'):'',z.media.length?'🎵':''].filter(Boolean);
      if(!bits.length)continue;
      const p=roomPts(v.r);let cx=0,cz=0;for(const q of p){cx+=q[0];cz+=q[1]}cx=cx/p.length-CX3;cz=cz/p.length-CZ3;
      const y=(tutto?(v.piano-1)*(H+20):0)+((v.r.base)||0);
      const sp=cartello(z.nome+'  '+bits.join(' '));sp.position.set(cx,y+H+40,cz);g.add(sp);
      if(z.luci_accese>0){const l=new THREE.PointLight(0xffc27a,1.2,520,1.6);l.position.set(cx,y+H-25,cz);g.add(l);}}
    setTimeout(giro,10000);}
  giro();}
function exportGLB(){if(!H3.grp){alert('Apri prima la vista 3D');return}
  try{const ex=new THREE.GLTFExporter();ex.parse(H3.grp,(res)=>{downloadBlob(new Blob([res],{type:'model/gltf-binary'}),'casa.glb');},{binary:true});}
  catch(e){alert('Export 3D non riuscito');}
}

// ================= EXPORT 2D (PNG / SVG) =================
const EXPORT_VARS='--bg:#0d1117;--card:#161b22;--bd:#30363d;--fg:#e6edf3;--mut:#8b949e;--acc:#f0a500;--ok:#3fb950;--bad:#f85149;--d-int:#58a6ff;--d-bal:#3fb950;--d-est:#d29922;--d-main:#f85149';
function toggleExport(){const el=$('#exportPicker');el.style.display=el.style.display==='flex'?'none':'flex';}
function downloadBlob(blob,name){const u=URL.createObjectURL(blob);const a=document.createElement('a');a.href=u;a.download=name;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(u),3000);}
function planSVGString(){const clone=SVG.cloneNode(true);
  clone.setAttribute('xmlns','http://www.w3.org/2000/svg');clone.setAttribute('width',String(PW));clone.setAttribute('height',String(PH));clone.removeAttribute('style');
  clone.setAttribute('style',EXPORT_VARS+';background:#0b0f14');
  return '<?xml version="1.0" encoding="UTF-8"?>\n'+new XMLSerializer().serializeToString(clone);}
function exportSVG(){downloadBlob(new Blob([planSVGString()],{type:'image/svg+xml'}),'planimetria.svg');}
function exportPNG(){const data=planSVGString();const img=new Image();
  img.onload=()=>{const c=document.createElement('canvas');c.width=2000;c.height=1280;const ctx=c.getContext('2d');ctx.fillStyle='#0b0f14';ctx.fillRect(0,0,c.width,c.height);ctx.drawImage(img,0,0,c.width,c.height);
    c.toBlob(b=>{if(b)downloadBlob(b,'planimetria.png');else alert('PNG non riuscito')},'image/png');};
  img.onerror=()=>alert('PNG non riuscito');
  img.src='data:image/svg+xml;base64,'+btoa(unescape(encodeURIComponent(data)));}
boot();
</script>
<script src="domotica.js"></script></body></html>"""

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8099)
