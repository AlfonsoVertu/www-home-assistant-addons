# EuroOffice — ONLYOFFICE Document Server

<div align="center">
  <img src="https://raw.githubusercontent.com/AlfonsoVertu/www-home-assistant-addons/main/vwg_logo.png" width="80" />
  &nbsp;&nbsp;&nbsp;
  <img src="https://raw.githubusercontent.com/AlfonsoVertu/www-home-assistant-addons/main/www_logo.png" width="80" />
</div>

---

Server di documenti collaborativo basato su **ONLYOFFICE Document Server**. Permette la visualizzazione e modifica in tempo reale di documenti Word, Excel e PowerPoint direttamente nel browser. Si integra con Nextcloud per la modifica nativa dei file cloud.

---

## Configurazione dell'add-on

| Parametro | Descrizione |
|:---|:---|
| `jwt_enabled` | Abilita la firma JWT per proteggere le richieste. Raccomandato: `true`. |
| `jwt_secret` | Segreto condiviso tra Document Server e i client. Deve essere identico a quello configurato in Nextcloud (`onlyoffice_jwt`). |
| `example_enabled` | Abilita l'applicazione di esempio accessibile su porta 3000. Utile per test. Disabilita in produzione. |

**Porte esposte:**
| Porta | Utilizzo |
|:---|:---|
| `8080` | Document Server (endpoint principale per Nextcloud/client) |
| `3000` | App di esempio (solo se `example_enabled: true`) |

---

## Primo avvio

1. Imposta un `jwt_secret` sicuro (stringa casuale, almeno 32 caratteri).
2. Avvia l'add-on.
3. Configura Nextcloud con lo stesso `jwt_secret` nel campo `onlyoffice_jwt`.
4. URL da inserire in Nextcloud: `http://<ip-ha>:8080`

---

## Integrazione con Nextcloud

In Nextcloud vai su **Impostazioni → Amministrazione → ONLYOFFICE**:
- **Indirizzo Document Server**: `http://<ip-ha>:8080`
- **Chiave JWT**: stesso valore del `jwt_secret` di questo add-on
- **Indirizzo interno**: lascia vuoto se EuroOffice e Nextcloud sono sulla stessa rete HA

---

## Troubleshooting

**Errore "Security token is not valid"**
→ Il `jwt_secret` di EuroOffice e il `onlyoffice_jwt` di Nextcloud non corrispondono. Verifica che siano identici (rispetta maiuscole/minuscole e spazi).

**Documento non si apre su Nextcloud**
→ Controlla che la porta 8080 sia raggiungibile dall'host Nextcloud. Se entrambi gli add-on girano in HA, dovrebbe funzionare automaticamente.

**Add-on non si avvia**
→ Verifica nei log che la porta 8080 non sia già occupata da un altro servizio.

---

## Crediti

Integrazione sviluppata da [Alfonso Vertucci](https://workingwithweb.it/webagency/) — Working With Web.
Software core: **ONLYOFFICE** (open source) — [onlyoffice.com](https://www.onlyoffice.com)
Documentazione ufficiale: [api.onlyoffice.com](https://api.onlyoffice.com/editors/basic)

Progetto Virtual Gate: [virtualgate.workingwithweb.eu](https://virtualgate.workingwithweb.eu/)
