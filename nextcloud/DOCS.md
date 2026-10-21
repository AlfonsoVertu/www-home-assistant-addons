# Nextcloud + ONLYOFFICE

<div align="center">
  <img src="https://raw.githubusercontent.com/AlfonsoVertu/www-home-assistant-addons/main/vwg_logo.png" width="80" />
  &nbsp;&nbsp;&nbsp;
  <img src="https://raw.githubusercontent.com/AlfonsoVertu/www-home-assistant-addons/main/www_logo.png" width="80" />
</div>

---

Nextcloud Hub self-hosted con **OCR integrato** e **connettore ONLYOFFICE pre-configurato**. Permette di archiviare file, condividerli e modificarli collaborativamente tramite EuroOffice senza uscire dalla propria infrastruttura.

---

## Configurazione dell'add-on

| Parametro | Descrizione |
|:---|:---|
| `onlyoffice_url` | Indirizzo di EuroOffice visto dal BROWSER. Con Nextcloud in https deve essere https (es. `https://office.example.com/`, dietro Nginx Proxy Manager verso la porta 8888 dell'add-on EuroOffice). Se resta `example.com` non si cambia niente. |
| `onlyoffice_internal_url` | (facoltativo) Indirizzo che usa Nextcloud per parlare con EuroOffice, es. `http://f3dcac16-eurooffice/`. |
| `onlyoffice_jwt` | Chiave JWT. Deve corrispondere al `jwt_secret` di EuroOffice. |
| `trusted_domains` | Domini o IP aggiuntivi da cui accedere a Nextcloud (separati da virgola). |
| `OCR` | Abilita il riconoscimento ottico dei caratteri sui file caricati. |
| `OCRLANG` | Lingua OCR (es. `ita` per italiano, `eng` per inglese, `fra` per francese). |
| `enable_thumbnails` | Genera anteprime per immagini e documenti. |
| `use_own_certs` | Usa certificati SSL personalizzati invece di quelli auto-generati. |
| `certfile` / `keyfile` | Percorso del certificato SSL (se `use_own_certs: true`). |
| `elasticsearch_server` | IP:porta di Elasticsearch per la ricerca full-text (es. `homeassistant.local:9200`). |
| `PUID` / `PGID` | ID utente/gruppo del processo. Default: `1000`. |

**Porte esposte:**
| Porta | Utilizzo |
|:---|:---|
| `8099` | Interfaccia web HTTPS |

---

## Primo avvio

1. Configura `onlyoffice_url` puntando all'add-on EuroOffice.
2. Imposta `onlyoffice_jwt` uguale al `jwt_secret` di EuroOffice.
3. Aggiungi l'IP del tuo Home Assistant ai `trusted_domains`.
4. Avvia l'add-on e accedi su `https://<ip-ha>:8099`.
5. Al primo accesso, crea l'account amministratore.
6. **L'app ONLYOFFICE viene auto-installata** alla prima partenza tramite lo script di inizializzazione incluso.

---

## Integrazione con EuroOffice

Dopo il primo accesso, vai in **Impostazioni → Amministrazione → ONLYOFFICE** per verificare la connessione. Se il jwt_secret è corretto, vedrai la spunta verde. Ora puoi aprire qualsiasi file `.docx`, `.xlsx`, `.pptx` direttamente nel browser.

---

## Troubleshooting

**Accesso negato / "Untrusted domain"**
→ Aggiungi il tuo IP o dominio al campo `trusted_domains` nella configurazione dell'add-on.

**ONLYOFFICE non disponibile in Nextcloud**
→ Verifica che EuroOffice sia in esecuzione e che `onlyoffice_url` e `onlyoffice_jwt` siano corretti.

**OCR non funziona**
→ Imposta `OCR: true` e specifica la lingua corretta in `OCRLANG` (usa codici ISO 639-3, es. `ita`).

**Nextcloud lento**
→ Assicurati di avere almeno 2 GB di RAM disponibili. Considera di disabilitare `enable_thumbnails` se le risorse sono limitate.

---

## Crediti

Integrazione e configurazione automatica ONLYOFFICE sviluppate da [Alfonso Vertucci](https://workingwithweb.it/webagency/) — Working With Web.
Software core: **Nextcloud Hub** — [nextcloud.com](https://nextcloud.com)
Documentazione ufficiale: [docs.nextcloud.com](https://docs.nextcloud.com/server/latest/admin_manual/)

Progetto Virtual Gate: [virtualgate.workingwithweb.eu](https://virtualgate.workingwithweb.eu/)
