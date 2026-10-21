"""
Dice se un file e' uno schema OpenAPI che ChatGPT puo' accettare.

Non basta che sia YAML valido: un file con due volte la stessa chiave paths si
carica senza errori e perde meta' del contenuto in silenzio, ed e' esattamente
il difetto che aveva openapi.yml. Qui i doppioni sono un errore, non una svista.

  python valida-schema.py <file.yml> [operazioni_attese]
"""
import sys, yaml


class SenzaDoppioni(yaml.SafeLoader):
    """Un caricatore che si rifiuta di far sparire una chiave ripetuta."""


def mappa(loader, node, deep=False):
    viste = set()
    for chiave_node, _ in node.value:
        chiave = loader.construct_object(chiave_node, deep=deep)
        if chiave in viste:
            raise yaml.YAMLError(f"chiave ripetuta: {chiave!r} - il secondo blocco "
                                 f"cancella il primo senza dirlo")
        viste.add(chiave)
    return yaml.SafeLoader.construct_mapping(loader, node, deep)


SenzaDoppioni.add_constructor(
    yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG, mappa)

percorso = sys.argv[1]
attese = int(sys.argv[2]) if len(sys.argv) > 2 else None

try:
    doc = yaml.load(open(percorso, encoding="utf-8"), Loader=SenzaDoppioni)
except yaml.YAMLError as e:
    print(f"YAML non valido: {str(e)[:200]}")
    sys.exit(1)

if not isinstance(doc, dict):
    print("il file non e' un documento")
    sys.exit(1)

problemi = []
for chiave in ("openapi", "info", "paths"):
    if chiave not in doc:
        problemi.append(f"manca {chiave}")

percorsi = doc.get("paths") or {}
operazioni = 0
verbi = {"get", "post", "put", "delete", "patch", "options", "head", "trace"}

for p, corpo in percorsi.items():
    if not isinstance(corpo, dict):
        problemi.append(f"{p}: non ha operazioni")
        continue
    for verbo, op in corpo.items():
        if verbo not in verbi:
            continue
        operazioni += 1
        if not isinstance(op, dict):
            problemi.append(f"{p} {verbo}: operazione vuota")
            continue
        if not op.get("operationId"):
            problemi.append(f"{p} {verbo}: senza operationId")
        # I limiti dichiarati da OpenAI per le azioni dei GPT.
        for campo in ("summary", "description"):
            testo = op.get(campo) or ""
            if len(testo) > 300:
                problemi.append(f"{p} {verbo}: {campo} di {len(testo)} caratteri, il tetto e 300")
            if testo.endswith("…") or testo.endswith("..."):
                problemi.append(f"{p} {verbo}: {campo} troncato a meta")
        corpo_req = (op.get("requestBody") or {}).get("content") or {}
        for _, mezzo in corpo_req.items():
            prop = ((mezzo.get("schema") or {}).get("properties")) or {}
            for nome, definizione in prop.items():
                if not isinstance(definizione, dict):
                    continue
                d = definizione.get("description") or ""
                if len(d) > 700:
                    problemi.append(f"{p} {verbo} {nome}: descrizione di {len(d)} caratteri, il tetto e 700")
                # Un array deve dire di cosa e' fatto. ChatGPT lo rifiuta con
                # "array schema missing items", e il file intanto resta YAML
                # perfettamente valido: e' lo schema a essere incompleto.
                if definizione.get("type") == "array" and not definizione.get("items"):
                    problemi.append(f"{p} {verbo} {nome}: e un array senza items")
                # Una descrizione tagliata a meta' parola sembra un file
                # corrotto a chi la legge, e non dice quello che doveva dire.
                if d.endswith("…") or d.endswith("..."):
                    problemi.append(f"{p} {verbo} {nome}: descrizione troncata")

if attese is not None and operazioni != attese:
    problemi.append(f"operazioni trovate {operazioni}, attese {attese}")

# Il tetto che ChatGPT applica davvero. Provato incollando 31 operazioni
# nell'editor dei GPT: risponde "Le specifiche OpenAPI possono avere un massimo
# di 30 operazioni" e rifiuta l'intero schema. Con 30 lo accetta, e accetta
# anche centomila caratteri: quello che conta e' il numero, non la dimensione.
if operazioni > 30:
    problemi.append(f"{operazioni} operazioni: ChatGPT ne accetta al massimo 30 "
                    f"e rifiuta tutto lo schema")

if problemi:
    print("; ".join(problemi[:6]))
    sys.exit(1)

print(f"{len(percorsi)} percorsi, {operazioni} operazioni")
