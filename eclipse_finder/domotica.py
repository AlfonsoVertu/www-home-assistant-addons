"""CASA VIVA (Eclipse Finder 0.27, 13 ottobre 2026). Licenza MIT.

Eclipse sa com'è fatta la casa: stanze, finestre, mobili, piani. Home Assistant sa cosa succede: luci, sensori,
casse, serrature. Questo modulo li mette insieme, e da lì vengono cinque cose che senza la pianta non si possono fare
bene:

  GEMELLO   ogni entità di HA legata a una stanza (e, se serve, a un mobile). Lo stato di ogni stanza è vivo (luci
            accese, porte e finestre aperte, presenza, temperatura); e ogni comando si può SIMULARE prima: le regole di
            sicurezza guardano la stanza vera («il clima del soggiorno con la finestra del soggiorno aperta», non «una
            finestra qualunque»).
  ABITUDINI dallo storico di HA (il registro: chi ha acceso cosa, e quando) si cercano le cose fatte a mano sempre
            alla stessa ora, nei feriali o nei fine settimana, o legate al tramonto; diventano proposte di automazione
            che si creano in HA solo con un clic esplicito.
  SCENE     una scena è la fotografia delle entità di UNA stanza; più scene di stanze diverse si FONDONO in una scena di
            casa («Sera» = soggiorno caldo + cucina spenta + camera soffusa). Si applicano passando dal gemello.
  VOLUME    mentre un altoparlante «di voce» parla (annunci, assistente vocale, satellite Assist), le altre casse si
            abbassano; a voce finita tornano al volume di prima, ma solo quelle che nel frattempo nessuno ha toccato.
  ALLARME   fumo, gas, acqua o monossido: le luci di casa lampeggiano lente di rosso (mezzo ciclo al secondo, sicuro per
            chi è sensibile alla luce), un annuncio dice la STANZA dal sensore e si ripete, le notifiche partono; poi le
            luci tornano esattamente com'erano. C'è la PROVA a secco (dice cosa farebbe, non tocca niente).

Tutto passa dall'API di Home Assistant con il gettone del Supervisor (homeassistant_api nel config dell'add-on). Le
impostazioni stanno in /data/domotica.json; niente segreti. Le pagine e gli indirizzi rispondono solo dall'ingress.
"""
import json, os, threading, time, urllib.request, urllib.error, urllib.parse, datetime as dt, statistics, re, uuid

DATA = "/data"
FILE = os.path.join(DATA, "domotica.json")
HA = "http://supervisor/core/api"
_blocco = threading.Lock()
# gli interruttori generali delle opzioni dell'add-on (config.yaml): se sono spenti, volume e allarme automatici non
# partono nemmeno se la pagina li accende (la pagina lo dice). Li imposta app.py all'avvio
PERMESSI = {"volume": False, "allarme": False}

# ------------------------------------------------------------------ impostazioni

PREDEFINITE = {
    "legami": {},            # entity_id → {"stanza": id, "mobile": id|None}
    "scene": {},             # id → {"nome", "stanza", "entita": {entity_id: {"state", "attributes"}}}
    "scene_casa": {},        # id → {"nome", "scene": [id scena stanza, ...]}
    "abitudini_scartate": [],
    "regole": {"blocca": True},   # True: le azioni pericolose non partono; False: si avvisa soltanto
    "volume": {"attivo": False, "voci": [], "casse": [], "quanto": 0.25},
    "allarme": {"attivo": False, "sensori": [], "luci": [], "casse": [], "tts": "", "notifiche": [], "secondi": 120},
    # (0.31) CASA VUOTA: quando nessuna persona di HA è in casa, una porta o una finestra che si apre o un movimento in una
    # stanza diventano un avviso (solo notifiche: non si tocca niente della casa). Spento di serie
    "vuota": {"attivo": False, "notifiche": [], "pausa_minuti": 10},
}


def leggi():
    try:
        d = json.load(open(FILE, encoding="utf-8"))
    except Exception:
        d = {}
    out = json.loads(json.dumps(PREDEFINITE))
    for k, v in d.items():
        if isinstance(v, dict) and isinstance(out.get(k), dict):
            out[k].update(v)
        else:
            out[k] = v
    return out


def scrivi(d):
    with _blocco:
        tmp = FILE + ".tmp"
        json.dump(d, open(tmp, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
        os.replace(tmp, FILE)


# ------------------------------------------------------------------ Home Assistant

def ha(metodo, percorso, corpo=None, timeout=20):
    token = os.environ.get("SUPERVISOR_TOKEN", "")
    req = urllib.request.Request(HA + percorso, data=None if corpo is None else json.dumps(corpo).encode(),
                                 headers={"Authorization": "Bearer " + token, "Content-Type": "application/json"}, method=metodo)
    with urllib.request.urlopen(req, timeout=timeout) as r:
        testo = r.read().decode("utf-8", "replace")
    try:
        return json.loads(testo)
    except ValueError:
        return testo


def stati():
    return {s["entity_id"]: s for s in ha("GET", "/states")}


def servizio(dominio, nome, dati):
    return ha("POST", f"/services/{dominio}/{nome}", dati)


def aree():
    """entity_id → nome dell'area di HA (in una sola chiamata, col motore dei template)."""
    t = "{% set ns = namespace(o={}) %}{% for s in states %}{% set a = area_name(s.entity_id) %}{% if a %}" \
        "{% set ns.o = dict(ns.o, **{s.entity_id: a}) %}{% endif %}{% endfor %}{{ ns.o | tojson }}"
    try:
        r = ha("POST", "/template", {"template": t})
        return r if isinstance(r, dict) else json.loads(r)
    except Exception:
        return {}


# i tipi di entità che interessano la casa (le altre, centinaia, non si legano alle stanze)
DOMINI_CASA = ("light", "switch", "cover", "climate", "fan", "media_player", "lock", "binary_sensor", "sensor",
               "camera", "alarm_control_panel", "valve", "vacuum", "humidifier", "water_heater", "assist_satellite")


# le classi di sensori che dicono qualcosa di una STANZA (le altre: statistiche di server, consumi di rete, versioni...
# finiscono nelle aree di HA per comodità ma non sono la stanza)
CLASSI_STANZA = {"temperature", "humidity", "illuminance", "carbon_dioxide", "pm25", "pm10", "volatile_organic_compounds",
                 "door", "window", "opening", "garage_door", "motion", "occupancy", "presence", "smoke", "gas", "moisture",
                 "carbon_monoxide", "safety", "heat", "vibration", "sound", "power", "energy"}


def da_stanza(eid, s):
    dom = eid.split(".")[0]
    if dom not in DOMINI_CASA or "browser_mod" in eid:
        return False
    if dom in ("sensor", "binary_sensor"):
        return classe(s) in CLASSI_STANZA
    return True


def _semplice(t):
    t = (t or "").lower()
    t = re.sub(r"[àáâ]", "a", t); t = re.sub(r"[èéê]", "e", t); t = re.sub(r"[ìí]", "i", t); t = re.sub(r"[òó]", "o", t); t = re.sub(r"[ùú]", "u", t)
    return re.sub(r"[^a-z0-9 ]", " ", t)


def proponi_legami(piano):
    """Per ogni entità della casa senza legame, la stanza più probabile: prima l'area di HA con lo stesso nome della
    stanza, poi il nome della stanza dentro il nome dell'entità («Luce Cucina» → Cucina). Solo proposte: si salvano
    quando l'utente le conferma."""
    d = leggi()
    stanze = [r for r in tutte_le_stanze(piano)]
    per_nome = {_semplice(r.get("name")).strip(): r for r in stanze if r.get("name")}
    ar = aree()
    out = []
    for eid, s in stati().items():
        if not da_stanza(eid, s) or eid in d["legami"]:
            continue
        nome = s.get("attributes", {}).get("friendly_name") or eid
        scelta, motivo = None, ""
        a = _semplice(ar.get(eid, "")).strip()
        if a and a in per_nome:
            scelta, motivo = per_nome[a], "area di HA"
        else:
            n = " " + _semplice(nome) + " "
            for k, r in sorted(per_nome.items(), key=lambda kv: -len(kv[0])):
                if k and (" " + k + " ") in n:
                    scelta, motivo = r, "nome"
                    break
        if scelta:
            out.append({"entity_id": eid, "nome": nome, "stanza": scelta.get("id"), "stanza_nome": scelta.get("name"), "perche": motivo})
    return out


def tutte_le_stanze(piano):
    """Le stanze di tutti i piani, ognuna col suo piano (la planimetria tiene il mio appartamento in rooms e gli altri
    piani in planimetrie)."""
    out = []
    mio = piano.get("pianoMio") or 1
    for r in piano.get("rooms", []):
        out.append({**r, "piano": mio})
    for n, p in (piano.get("planimetrie") or {}).items():
        for r in p.get("rooms", []):
            out.append({**r, "piano": int(n)})
    return out


# ------------------------------------------------------------------ gemello

ACCESI = {"on", "open", "opening", "playing", "unlocked", "heat", "cool", "heat_cool", "auto", "dry", "fan_only", "home", "detected"}


def classe(s):
    return (s.get("attributes") or {}).get("device_class") or ""


def gemello(piano):
    """Lo stato vivo di ogni stanza: le sue entità e un riassunto (luci accese, aperture aperte, presenza, temperatura,
    media che suonano)."""
    d = leggi(); st = stati()
    stanze = {r.get("id"): {"id": r.get("id"), "nome": r.get("name") or r.get("id"), "piano": r.get("piano"), "entita": [],
                            "luci": 0, "luci_accese": 0, "aperture": [], "presenza": False, "temperatura": None, "media": []}
              for r in tutte_le_stanze(piano)}
    for eid, l in d["legami"].items():
        r = stanze.get(l.get("stanza")); s = st.get(eid)
        if not r or not s:
            continue
        dom, stato, c = eid.split(".")[0], s.get("state"), classe(s)
        r["entita"].append({"entity_id": eid, "nome": s["attributes"].get("friendly_name", eid), "stato": stato, "classe": c, "mobile": l.get("mobile")})
        # una presa che porta una lampada («Lampada di legno» su una Sonoff) è una luce della stanza
        if dom == "light" or (dom == "switch" and re.search(r"lam.?pad|lamp|luc|light|abat", _semplice(s["attributes"].get("friendly_name", eid)))):
            r["luci"] += 1; r["luci_accese"] += stato == "on"
        elif dom == "binary_sensor" and c in ("door", "window", "opening", "garage_door") and stato == "on":
            r["aperture"].append(s["attributes"].get("friendly_name", eid))
        elif dom == "binary_sensor" and c in ("motion", "occupancy", "presence") and stato == "on":
            r["presenza"] = True
        elif dom == "sensor" and c == "temperature":
            try: r["temperatura"] = round(float(stato), 1)
            except ValueError: pass
        elif dom == "media_player" and stato == "playing":
            r["media"].append(s["attributes"].get("friendly_name", eid))
    return list(stanze.values())


def _stanza_di(eid, d):
    return (d["legami"].get(eid) or {}).get("stanza")


def simula(azioni, piano=None):
    """Prova le azioni su una copia dello stato attuale e controlla le regole. Ogni azione: {"entity_id", "servizio"
    (es. "light.turn_on"), "dati"}. Esito: per ogni azione «ok», «avviso» o «bloccata», con il perché. Le regole:
      - una porta, un cancello o una serratura che si aprono con l'allarme inserito: bloccata
      - una telecamera che si spegne con l'allarme inserito: bloccata
      - una valvola che si apre con la casa vuota (nessuno a casa): bloccata
      - il clima che si accende con una finestra aperta NELLA SUA STANZA: avviso (si sprecherebbe)
      - l'allarme che si inserisce con una porta o finestra aperta: avviso, con quali
      - la stessa entità comandata due volte in modo opposto nello stesso piano: bloccata la seconda"""
    d = leggi(); st = stati()
    allarme = [s for e, s in st.items() if e.startswith("alarm_control_panel.") and str(s.get("state", "")).startswith("armed")]
    a_casa = any(s.get("state") == "home" for e, s in st.items() if e.startswith("person."))
    aperte = {e: s for e, s in st.items() if e.startswith("binary_sensor.") and classe(s) in ("door", "window", "opening", "garage_door") and s.get("state") == "on"}
    gia = {}
    esiti = []
    for a in azioni:
        eid, srv = a.get("entity_id", ""), a.get("servizio", "")
        dom, cosa = (srv.split(".") + [""])[:2]
        esito, perche = "ok", ""
        if eid in gia and gia[eid] != cosa and {gia[eid], cosa} & {"turn_on", "open_cover", "unlock", "open_valve"} and {gia[eid], cosa} & {"turn_off", "close_cover", "lock", "close_valve"}:
            esito, perche = "bloccata", "la stessa entità è già comandata in modo opposto in questo piano"
        elif allarme and ((dom == "lock" and cosa in ("unlock", "open")) or (dom == "cover" and cosa == "open_cover" and classe(st.get(eid, {})) in ("door", "gate", "garage"))):
            esito, perche = "bloccata", "l'allarme è inserito: prima va disinserito"
        elif allarme and dom == "camera" and cosa == "turn_off":
            esito, perche = "bloccata", "con l'allarme inserito le telecamere restano accese"
        elif dom == "valve" and cosa == "open_valve" and not a_casa:
            esito, perche = "bloccata", "nessuno è a casa: una valvola aperta senza nessuno è un rischio"
        elif dom == "climate" and (cosa == "turn_on" or (cosa == "set_hvac_mode" and (a.get("dati") or {}).get("hvac_mode") not in (None, "off"))):
            stanza = _stanza_di(eid, d)
            nella = [s["attributes"].get("friendly_name", e) for e, s in aperte.items() if stanza and _stanza_di(e, d) == stanza]
            if nella:
                esito, perche = "avviso", "nella stessa stanza è aperto: " + ", ".join(nella)
        elif dom == "alarm_control_panel" and cosa.startswith("alarm_arm") and aperte:
            esito, perche = "avviso", "sono aperti: " + ", ".join(s["attributes"].get("friendly_name", e) for e, s in list(aperte.items())[:6])
        gia[eid] = cosa
        esiti.append({**a, "esito": esito, "perche": perche})
    return esiti


def esegui(azioni, forza=False):
    """Simula, poi esegue quello che si può: le bloccate restano ferme (a meno che le regole siano solo avvisi)."""
    d = leggi()
    esiti = simula(azioni)
    for e in esiti:
        if e["esito"] == "bloccata" and d["regole"].get("blocca", True) and not forza:
            e["eseguita"] = False
            continue
        dom, cosa = e["servizio"].split(".", 1)
        try:
            servizio(dom, cosa, {"entity_id": e["entity_id"], **(e.get("dati") or {})})
            e["eseguita"] = True
        except Exception as x:
            e["eseguita"] = False; e["errore"] = str(x)[:160]
    return esiti


# ------------------------------------------------------------------ scene

ATTRIBUTI_SCENA = {"light": ("brightness", "color_temp_kelvin", "rgb_color", "hs_color"), "cover": ("current_position",),
                   "climate": ("temperature", "hvac_mode"), "media_player": ("volume_level",), "fan": ("percentage",)}


def scatta_scena(nome, stanza):
    """La fotografia delle entità comandabili di una stanza, adesso."""
    d = leggi(); st = stati()
    foto = {}
    for eid, l in d["legami"].items():
        dom = eid.split(".")[0]
        if l.get("stanza") != stanza or dom not in ("light", "switch", "cover", "climate", "media_player", "fan") or eid not in st:
            continue
        s = st[eid]
        foto[eid] = {"state": s["state"], "attributes": {k: s["attributes"][k] for k in ATTRIBUTI_SCENA.get(dom, ()) if s["attributes"].get(k) is not None}}
    sid = uuid.uuid4().hex[:10]
    d["scene"][sid] = {"nome": nome, "stanza": stanza, "entita": foto}
    scrivi(d)
    return sid, len(foto)


def azioni_scena(foto):
    """Da una fotografia ai comandi che la ricreano."""
    az = []
    for eid, v in foto.items():
        dom, s, at = eid.split(".")[0], v.get("state"), v.get("attributes") or {}
        if dom == "light":
            if s == "on":
                dati = {k: at[k] for k in ("brightness", "color_temp_kelvin", "rgb_color") if k in at}
                az.append({"entity_id": eid, "servizio": "light.turn_on", "dati": dati})
            else:
                az.append({"entity_id": eid, "servizio": "light.turn_off"})
        elif dom in ("switch", "fan"):
            az.append({"entity_id": eid, "servizio": f"{dom}.turn_{'on' if s == 'on' else 'off'}"})
        elif dom == "cover" and "current_position" in at:
            az.append({"entity_id": eid, "servizio": "cover.set_cover_position", "dati": {"position": at["current_position"]}})
        elif dom == "climate":
            az.append({"entity_id": eid, "servizio": "climate.set_hvac_mode", "dati": {"hvac_mode": s}})
            if s != "off" and "temperature" in at:
                az.append({"entity_id": eid, "servizio": "climate.set_temperature", "dati": {"temperature": at["temperature"]}})
        elif dom == "media_player" and s in ("off", "idle", "paused") :
            pass                                         # i media non si accendono da una scena: solo il volume
        elif dom == "media_player" and "volume_level" in at:
            az.append({"entity_id": eid, "servizio": "media_player.volume_set", "dati": {"volume_level": at["volume_level"]}})
    return az


def azioni_scena_casa(sid):
    """La fusione: le scene delle stanze in ordine; se due toccano la stessa entità vince l'ultima (una sola azione
    per entità, così il gemello non la vede come comando contraddittorio)."""
    d = leggi(); per_entita = {}
    for s in d["scene_casa"].get(sid, {}).get("scene", []):
        sc = d["scene"].get(s)
        if not sc:
            continue
        for a in azioni_scena(sc["entita"]):
            per_entita.setdefault(a["entity_id"], []).append(a)
    out = []
    for lista in per_entita.values():
        out.extend(lista[-2:] if lista[-1]["servizio"] == "climate.set_temperature" else lista[-1:])
    return out


# ------------------------------------------------------------------ abitudini

DOMINI_ABITUDINI = ("light", "switch", "cover", "climate", "media_player", "lock", "fan")


def _tramonto(giorno, lat, lon):
    try:
        import astronomy
        oss = astronomy.Observer(lat, lon, 0)
        t = astronomy.SearchRiseSet(astronomy.Body.Sun, oss, astronomy.Direction.Set, astronomy.Time.Make(giorno.year, giorno.month, giorno.day, 0, 0, 0), 1)
        return t.Utc().replace(tzinfo=dt.timezone.utc) if t else None
    except Exception:
        return None


def abitudini(giorni=14, lat=None, lon=None, fuso="UTC"):
    """Le cose fatte A MANO (il registro di HA dice chi: c'è un utente, non un'automazione) sempre alla stessa ora.
    Per ogni (entità, nuovo stato): i minuti del giorno degli eventi; si cerca la finestra di 40 minuti con più giorni
    DIVERSI dentro. Se ci sono almeno 5 giorni (o 4 su 5 feriali, o 3 su 4 sabati e domeniche) è un'abitudine; la
    confidenza è la quota di giorni in cui è successo. In più: se gli orari seguono il tramonto meglio dell'orologio
    (meno sparsi rispetto al tramonto del giorno), l'abitudine è «al tramonto + N minuti»."""
    try:
        from zoneinfo import ZoneInfo
        zona = ZoneInfo(fuso)
    except Exception:
        zona = dt.timezone.utc
    fine = dt.datetime.now(dt.timezone.utc)
    inizio = fine - dt.timedelta(days=giorni)
    voci = ha("GET", f"/logbook/{inizio.isoformat()}?end_time={urllib.parse.quote(fine.isoformat())}", timeout=60)
    eventi = {}
    for v in voci if isinstance(voci, list) else []:
        eid = v.get("entity_id") or ""
        # «a mano» = non da un'automazione o da uno script. L'utente di HA non basta: interruttori a muro, Alexa,
        # Google e le app dei produttori cambiano le luci senza un utente di HA (nel registro non c'è), ed erano
        # proprio le abitudini vere. Le entità dei browser (browser_mod) non sono la casa
        if eid.split(".")[0] not in DOMINI_ABITUDINI or "browser_mod" in eid:
            continue
        if v.get("context_event_type") in ("automation_triggered", "script_started") or v.get("context_domain") in ("automation", "script"):
            continue
        quando = dt.datetime.fromisoformat(v["when"].replace("Z", "+00:00")).astimezone(zona)
        eventi.setdefault((eid, v.get("state")), []).append(quando)
    scartate = set(leggi().get("abitudini_scartate", []))
    out = []
    for (eid, stato), qq in eventi.items():
        # solo gli stati che diventano un comando (acceso/spento, aperto/chiuso, chiuso a chiave): «in pausa» o
        # «inattivo» di una TV sono passaggi, non scelte
        if stato not in ("on", "off", "open", "closed", "locked"):
            continue
        for tipo, filtro, minimo, totale in (("tutti i giorni", lambda q: True, 5, giorni),
                                             ("nei giorni feriali", lambda q: q.weekday() < 5, 4, giorni * 5 // 7),
                                             ("nel fine settimana", lambda q: q.weekday() >= 5, 3, max(2, giorni * 2 // 7))):
            q2 = [q for q in qq if filtro(q)]
            if len(q2) < minimo:
                continue
            minuti = sorted((q.hour * 60 + q.minute, q.date()) for q in q2)
            meglio = (0, 0, [])
            for m0, _ in minuti:
                dentro = [(m, g) for m, g in minuti if m0 <= m < m0 + 40]
                gg = {g for _, g in dentro}
                if len(gg) > meglio[0]:
                    meglio = (len(gg), m0, dentro)
            n, m0, dentro = meglio
            if n < minimo:
                continue
            orario = int(statistics.median(m for m, _ in dentro))
            conf = round(min(1.0, n / max(1, totale)), 2)
            proposta = {"entity_id": eid, "stato": stato, "quando": tipo, "ora": f"{orario // 60:02d}:{orario % 60:02d}",
                        "giorni": n, "confidenza": conf}
            # al tramonto? gli scarti dal tramonto di quel giorno contro quelli dall'orologio
            if lat is not None and lon is not None:
                rel = []
                for m, g in dentro:
                    tr = _tramonto(g, lat, lon)
                    if tr:
                        trl = tr.astimezone(zona)
                        rel.append(m - (trl.hour * 60 + trl.minute))
                if len(rel) >= minimo and statistics.pstdev(rel) + 5 < statistics.pstdev([m for m, _ in dentro]):
                    proposta["tramonto"] = int(statistics.median(rel))
            proposta["id"] = f"{eid}|{stato}|{tipo}"
            if proposta["id"] not in scartate:
                out.append(proposta)
            break                                        # tutti i giorni vince su feriali/fine settimana
    out.sort(key=lambda p: -p["confidenza"])
    return out


def crea_automazione(p, nome_amico=None):
    """L'abitudine accettata diventa un'automazione di HA (la crea l'API di configurazione, come l'editor di HA)."""
    eid, stato = p["entity_id"], p["stato"]
    dom = eid.split(".")[0]
    srv = {("light", "on"): "light.turn_on", ("light", "off"): "light.turn_off", ("switch", "on"): "switch.turn_on",
           ("switch", "off"): "switch.turn_off", ("fan", "on"): "fan.turn_on", ("fan", "off"): "fan.turn_off",
           ("cover", "open"): "cover.open_cover", ("cover", "closed"): "cover.close_cover", ("lock", "locked"): "lock.lock",
           ("media_player", "off"): "media_player.turn_off", ("media_player", "on"): "media_player.turn_on"}.get((dom, stato))
    if not srv:
        raise ValueError("questa abitudine non si traduce in un comando sicuro")
    if p.get("tramonto") is not None:
        m = p["tramonto"]
        trigger = [{"platform": "sun", "event": "sunset", "offset": f"{'-' if m < 0 else ''}00:{abs(m):02d}:00" if abs(m) < 60 else f"{'-' if m < 0 else ''}{abs(m) // 60:02d}:{abs(m) % 60:02d}:00"}]
    else:
        trigger = [{"platform": "time", "at": p["ora"] + ":00"}]
    condizioni = []
    if p["quando"] == "nei giorni feriali":
        condizioni.append({"condition": "time", "weekday": ["mon", "tue", "wed", "thu", "fri"]})
    elif p["quando"] == "nel fine settimana":
        condizioni.append({"condition": "time", "weekday": ["sat", "sun"]})
    # solo se c'è qualcuno a casa (zone.home conta le persone in casa): un'abitudine è di chi la vive
    condizioni.append({"condition": "numeric_state", "entity_id": "zone.home", "above": 0})
    aid = "eclipse_" + uuid.uuid4().hex[:10]
    cfg = {"id": aid, "alias": f"Eclipse · {nome_amico or eid} {stato} ({p['quando']})",
           "description": f"Abitudine riconosciuta da Eclipse Finder: {p['giorni']} giorni su {p['quando']}, confidenza {int(p['confidenza'] * 100)}%.",
           "trigger": trigger, "condition": condizioni, "action": [{"service": srv, "target": {"entity_id": eid}}], "mode": "single"}
    ha("POST", f"/config/automation/config/{aid}", cfg)
    try: servizio("automation", "reload", {})
    except Exception: pass
    return aid


# ------------------------------------------------------------------ il guardiano: volume e allarme

class Guardiano(threading.Thread):
    """Un giro ogni secondo: volume durante le voci, allarme rosso. Polling e non websocket: un secondo di ritardo
    basta, e niente connessioni appese da rifare."""

    def __init__(self):
        super().__init__(daemon=True)
        self.abbassati = {}          # entity_id → volume di prima (e quello messo da noi)
        self.voce_da = None          # quando la voce ha smesso (per aspettare 1,5 s)
        self.allarme = None          # {"perche", "stanza", "inizio", "luci": {eid: stato}, "prova": bool}
        self.registro = []           # ultime cose fatte (per la pagina)

    def nota(self, t):
        self.registro = (self.registro + [time.strftime("%H:%M:%S ") + t])[-40:]

    def run(self):
        while True:
            try:
                self.giro()
            except Exception as e:
                self.nota("errore: " + str(e)[:120])
                time.sleep(5)
            time.sleep(1)

    def giro(self):
        d = leggi()
        if (d.get("vuota") or {}).get("attivo"):
            try:
                self.vuota(d)
            except Exception as e:
                self.nota("casa vuota: " + str(e)[:100])
        v, a = dict(d["volume"]), dict(d["allarme"])
        v["attivo"] = v.get("attivo") and PERMESSI["volume"]
        a["attivo"] = a.get("attivo") and PERMESSI["allarme"]
        if not v.get("attivo") and not a.get("attivo") and not self.allarme and not self.abbassati:
            return
        st = stati()
        if v.get("attivo") or self.abbassati:
            self.volume(v, st)
        if a.get("attivo") and not self.allarme:
            for eid in a.get("sensori") or []:
                if st.get(eid, {}).get("state") == "on":
                    self.scatta(eid, st, d)
                    break
        if self.allarme:
            self.lampeggia(d, st)

    # ---- casa vuota (0.31): ogni 10 s; un avviso per entità al più ogni «pausa_minuti»
    def vuota(self, d):
        ora = time.time()
        if ora - getattr(self, "_vuota_t", 0) < 10:
            return
        self._vuota_t = ora
        st = stati()
        persone = [s for e, s in st.items() if e.startswith("person.")]
        if not persone or any(s.get("state") == "home" for s in persone):
            self._vuota_prima = {}
            return
        prima = getattr(self, "_vuota_prima", {})
        visti = getattr(self, "_vuota_avvisi", {})
        adesso = {}
        pf = os.path.join(DATA, "plan.json")
        piano = json.load(open(pf, encoding="utf-8")) if os.path.exists(pf) else {}
        nomi = {r.get("id"): r.get("name") for r in tutte_le_stanze(piano)}
        for eid, l in d["legami"].items():
            s = st.get(eid)
            if not s or not eid.startswith("binary_sensor.") or classe(s) not in ("door", "window", "opening", "garage_door", "motion", "occupancy"):
                continue
            adesso[eid] = s.get("state")
            if prima.get(eid) == "off" and s.get("state") == "on" and ora - visti.get(eid, 0) > 60 * float(d["vuota"].get("pausa_minuti") or 10):
                visti[eid] = ora
                cosa = "movimento" if classe(s) in ("motion", "occupancy") else "apertura"
                dove = nomi.get(l.get("stanza"))
                frase = "Casa vuota: " + cosa + " - " + s["attributes"].get("friendly_name", eid) + (" in " + dove if dove else "") + "."
                self.nota(frase)
                for n in d["vuota"].get("notifiche") or []:
                    try:
                        dom, srv = n.split(".", 1) if "." in n else ("notify", n)
                        servizio(dom, srv, {"title": "Eclipse: casa vuota", "message": frase})
                    except Exception as e:
                        self.nota("notifica " + n + ": " + str(e))
                try:
                    servizio("persistent_notification", "create", {"title": "Eclipse: casa vuota", "message": frase, "notification_id": "eclipse_vuota_" + eid.split(".")[1]})
                except Exception:
                    pass
        self._vuota_prima, self._vuota_avvisi = adesso, visti

    # ---- volume
    def volume(self, v, st):
        parla = any(st.get(e, {}).get("state") in ("playing", "responding", "listening", "processing", "on") for e in v.get("voci") or [])
        casse = [e for e in (v.get("casse") or []) if e not in (v.get("voci") or [])]
        if parla:
            self.voce_da = None
            for e in casse:
                s = st.get(e)
                if not s or e in self.abbassati or s.get("state") != "playing":
                    continue
                prima = s["attributes"].get("volume_level")
                if prima is None:
                    continue
                nuovo = round(prima * float(v.get("quanto") or 0.25), 3)
                servizio("media_player", "volume_set", {"entity_id": e, "volume_level": nuovo})
                self.abbassati[e] = {"prima": prima, "messo": nuovo}
                self.nota(f"voce: abbasso {e} {prima} → {nuovo}")
        elif self.abbassati:
            if self.voce_da is None:
                self.voce_da = time.time(); return
            if time.time() - self.voce_da < 1.5:
                return
            for e, x in list(self.abbassati.items()):
                ora = (st.get(e) or {}).get("attributes", {}).get("volume_level")
                # rimesso solo se nessuno l'ha toccato nel frattempo
                if ora is not None and abs(ora - x["messo"]) < 0.02:
                    servizio("media_player", "volume_set", {"entity_id": e, "volume_level": x["prima"]})
                    self.nota(f"voce finita: {e} torna a {x['prima']}")
                else:
                    self.nota(f"voce finita: {e} toccato a mano, lo lascio a {ora}")
                del self.abbassati[e]
            self.voce_da = None

    # ---- allarme
    def scatta(self, eid, st, d, prova=False):
        piano = json.load(open(os.path.join(DATA, "plan.json"), encoding="utf-8")) if os.path.exists(os.path.join(DATA, "plan.json")) else {}
        stanza = (d["legami"].get(eid) or {}).get("stanza")
        nome_st = next((r.get("name") for r in tutte_le_stanze(piano) if r.get("id") == stanza), None)
        c = classe(st.get(eid, {}))
        cosa = {"smoke": "fumo", "gas": "gas", "moisture": "acqua", "carbon_monoxide": "monossido di carbonio", "safety": "pericolo"}.get(c, "allarme")
        frase = f"Attenzione: {cosa}" + (f" in {nome_st}" if nome_st else "") + ". Uscite e controllate subito."
        luci = d["allarme"].get("luci") or [e for e in st if e.startswith("light.")]
        foto = {e: st[e] for e in luci if e in st}
        self.allarme = {"perche": eid, "cosa": cosa, "stanza": nome_st, "frase": frase, "inizio": time.time(), "luci": foto,
                        "prova": prova, "ultimo_annuncio": 0, "fase": False}
        self.nota(("PROVA allarme: " if prova else "ALLARME: ") + frase)
        if not prova:
            for n in d["allarme"].get("notifiche") or []:
                try:
                    dom, srv = n.split(".", 1) if "." in n else ("notify", n)
                    servizio(dom, srv, {"title": "🚨 Eclipse: " + cosa, "message": frase})
                except Exception as e:
                    self.nota(f"notifica {n}: {e}")

    def lampeggia(self, d, st):
        al, cfg = self.allarme, d["allarme"]
        if al.get("prova"):
            return                                       # la prova non tocca niente: la pagina mostra cosa farebbe
        if time.time() - al["inizio"] > float(cfg.get("secondi") or 120):
            self.ferma("tempo scaduto"); return
        al["fase"] = not al["fase"]
        luci = list(al["luci"].keys())
        if luci:
            if al["fase"]:
                servizio("light", "turn_on", {"entity_id": luci, "rgb_color": [255, 0, 0], "brightness": 255, "transition": 0.8})
            else:
                servizio("light", "turn_on", {"entity_id": luci, "rgb_color": [255, 0, 0], "brightness": 40, "transition": 0.8})
        if time.time() - al["ultimo_annuncio"] > 25 and cfg.get("tts") and cfg.get("casse"):
            al["ultimo_annuncio"] = time.time()
            try:
                servizio("tts", "speak", {"entity_id": cfg["tts"], "media_player_entity_id": cfg["casse"], "message": al["frase"]})
            except Exception as e:
                self.nota(f"annuncio: {e}")

    def ferma(self, perche="annullato"):
        al = self.allarme
        if not al:
            return
        self.allarme = None
        if not al.get("prova"):
            for e, f in al["luci"].items():
                try:
                    ripristina_luce(e, f)
                except Exception as x:
                    self.nota(f"ripristino {e}: {x}")
        self.nota(f"allarme finito ({perche}): luci com'erano")


def ripristina_luce(eid, s):
    """Una luce com'era (s = il suo stato di prima, come lo dà HA). Il colore solo nel suo modo: una luce che era a
    temperatura di colore torna a quella, non a un rgb che la farebbe cambiare modo."""
    if s.get("state") != "on":
        servizio("light", "turn_off", {"entity_id": eid}); return
    at = s.get("attributes") or {}
    dati = {"entity_id": eid}
    if at.get("brightness") is not None: dati["brightness"] = at["brightness"]
    modo = at.get("color_mode")
    if modo == "color_temp" and at.get("color_temp_kelvin"): dati["color_temp_kelvin"] = at["color_temp_kelvin"]
    elif modo in ("rgb", "rgbw", "rgbww", "hs", "xy") and at.get("rgb_color"): dati["rgb_color"] = at["rgb_color"]
    servizio("light", "turn_on", dati)


# ------------------------------------------------------------------ presenza (0.31)
# Nuxiva fonde volto, voce e tracker in una stima di presenza. In Home Assistant le fonti sono le persone (person.*), i
# loro tracker (device_tracker: GPS, router, e i tracker di stanza come Bermuda o ESPresense, che danno il nome di
# un'area) e i sensori di movimento/presenza legati alle stanze di Eclipse. Qui si mettono insieme: per ogni persona
# in casa la stanza più probabile e il perché (tracker di stanza > unico movimento recente > sconosciuta).

def _nome_stanza_simile(testo, stanze):
    t = _semplice(testo or "")
    if not t:
        return None
    for r in stanze:
        n = _semplice(r.get("name") or r.get("id") or "")
        if n and (n == t or n in t or t in n):
            return r
    return None


def presenza(piano):
    st = stati(); d = leggi(); stanze = tutte_le_stanze(piano)
    ora = dt.datetime.now(dt.timezone.utc)
    mosse = {}
    for eid, l in d["legami"].items():
        s = st.get(eid)
        if not s or not eid.startswith("binary_sensor.") or classe(s) not in ("motion", "occupancy", "presence"):
            continue
        try:
            quando = dt.datetime.fromisoformat(s.get("last_changed", "").replace("Z", "+00:00"))
        except ValueError:
            continue
        if s.get("state") == "on" or (ora - quando).total_seconds() < 600:
            r = l.get("stanza")
            if r and (r not in mosse or quando > mosse[r]):
                mosse[r] = quando
    persone = []
    for eid, s in st.items():
        if not eid.startswith("person."):
            continue
        at = s.get("attributes") or {}
        casa = s.get("state") == "home"
        stanza, perche = None, ""
        if casa:
            for tr in at.get("device_trackers") or []:
                ts = st.get(tr) or {}
                ta = ts.get("attributes") or {}
                cand = ta.get("area") or ta.get("room") or ts.get("state")
                r = _nome_stanza_simile(cand, stanze) if cand not in ("home", "not_home", None) else None
                if r:
                    stanza, perche = r, "tracker di stanza " + tr
                    break
        persone.append({"id": eid, "nome": at.get("friendly_name", eid), "in_casa": casa, "stato": s.get("state"),
                        "stanza": stanza and (stanza.get("name") or stanza.get("id")), "stanza_id": stanza and stanza.get("id"), "perche": perche})
    in_casa = [x for x in persone if x["in_casa"]]
    senza = [x for x in in_casa if not x["stanza_id"]]
    if len(senza) == 1 and mosse:
        rid = max(mosse, key=mosse.get)
        r = next((x for x in stanze if x.get("id") == rid), None)
        if r:
            senza[0].update({"stanza": r.get("name") or rid, "stanza_id": rid, "perche": "ultimo movimento"})
    return {"persone": persone, "nessuno_in_casa": bool(persone) and not in_casa,
            "movimento": {k: v.isoformat() for k, v in mosse.items()}}


def riepilogo(piano):
    """La casa in poche frasi (per la card, per l'avatar e per chi chiede «com'è la casa?»)."""
    g = gemello(piano); pr = presenza(piano); frasi = []
    dentro = [x for x in pr["persone"] if x["in_casa"]]
    if pr["persone"]:
        frasi.append("In casa non c'è nessuno." if not dentro else "In casa: " + ", ".join(x["nome"] + (" (" + x["stanza"] + ")" if x["stanza"] else "") for x in dentro) + ".")
    luci = [r["nome"] + " (" + str(r["luci_accese"]) + ")" for r in g if r["luci_accese"]]
    frasi.append("Luci accese: " + ", ".join(luci) + "." if luci else "Tutte le luci sono spente.")
    aperte = [a + " in " + r["nome"] for r in g for a in r["aperture"]]
    if aperte:
        frasi.append("Aperte: " + ", ".join(aperte) + ".")
    temp = [r["nome"] + " " + str(r["temperatura"]) + "°" for r in g if r["temperatura"] is not None]
    if temp:
        frasi.append("Temperature: " + ", ".join(temp) + ".")
    media = [m + " in " + r["nome"] for r in g for m in r["media"]]
    if media:
        frasi.append("Suona: " + ", ".join(media) + ".")
    if guardiano.allarme:
        frasi.insert(0, "ALLARME: " + guardiano.allarme.get("frase", ""))
    return {"testo": " ".join(frasi), "frasi": frasi}


guardiano = Guardiano()


def avvia():
    if not guardiano.is_alive():
        guardiano.start()

