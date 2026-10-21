# Virtual World Dashboard

<div align="center">
  <img src="https://raw.githubusercontent.com/AlfonsoVertu/www-home-assistant-addons/main/vwg_logo.png" width="80" />
  &nbsp;&nbsp;&nbsp;
  <img src="https://raw.githubusercontent.com/AlfonsoVertu/www-home-assistant-addons/main/www_logo.png" width="80" />
</div>

---

Un'installazione WordPress pre-configurata che funge da **gateway REST sicuro** tra sistemi AI (GPT Actions, automazioni) e il tuo Home Assistant.

Incluso il plugin **Virtual World Gate Key (VWGK)** che consente di:
- Salvare e gestire il Long-Lived Access Token di HA internamente
- Esporre rotte REST sicure (`/wp-json/vwgk/v1/...`) protette da API key
- Creare shortcode e card per visualizzare entità HA su pagine WordPress

---

## Configurazione dell'add-on

| Parametro | Descrizione |
|:---|:---|
| `db_host` | Host del database MariaDB. Con l'add-on ufficiale MariaDB usa `core-mariadb`. |
| `db_name` | Nome del database WordPress da creare. |
| `db_user` / `db_pass` | Credenziali utente del database. |
| `wp_domain` | Dominio o IP su cui sarà raggiungibile WordPress (es. `homeassistant.local`). |
| `wp_admin_user` / `wp_admin_password` | Credenziali amministratore WordPress. |
| `wp_admin_email` | Email dell'account admin. |
| `ha_auth_mode` | Come autenticarsi verso HA: `supervisor_proxy` (consigliato dentro HA) o `long_lived_token` (per accessi esterni). |
| `ha_url` | URL di Home Assistant. Usato solo in modalità `long_lived_token`. |
| `ha_long_lived_token` | Token HA a lunga durata. Generalo in HA → Profilo → Token di accesso. |
| `vwgk_api_key` | Chiave segreta da inserire nello header `x-api-key` nelle chiamate REST esterne (GPT Actions, etc). |

---

## Primo avvio

1. Assicurati che l'add-on **MariaDB** sia installato e in esecuzione.
2. Configura le opzioni sopra, poi avvia l'add-on.
3. WordPress viene installato automaticamente tramite WP-CLI al primo boot.
4. Accedi al pannello admin su `http://<ip-ha>:8081/wp-admin`.
5. Vai in **VWGK → Impostazioni** per configurare il token HA e l'API key del gateway.

---

## Utilizzo con GPT Actions

Crea in ChatGPT un'azione con endpoint base:
```
https://tuo-dominio.com/wp-json/vwgk/v1/
```
Header richiesto su ogni chiamata:
```
x-api-key: <vwgk_api_key>
```

Rotte principali:
- `GET /status` — verifica connessione
- `GET /ha/state?entity_id=light.soggiorno` — stato entità HA
- `POST /ha/service` — esegui un servizio HA

---

## Troubleshooting

**WordPress non si installa al boot**
→ Controlla che MariaDB sia attivo e che `db_host`, `db_user` e `db_pass` siano corretti nel log.

**Chiamate REST ritornano 401**
→ Verifica che l'header `x-api-key` corrisponda al valore salvato nelle impostazioni VWGK.

**HA non risponde tramite proxy**
→ In modalità `supervisor_proxy`, il gateway usa internamente `http://supervisor/core/api/`. Assicurati che il tuo add-on abbia `hassio_api: true` (già impostato).

---

## Crediti

Plugin **Virtual World Gate Key** e integrazione sviluppati da [Alfonso Vertucci](https://workingwithweb.it/webagency/) — Working With Web.

Versione completa con automazione AI avanzata: [WP GPT Automation Pro](https://workingwithweb.it/webagency/gestisci-wordpress-da-chatgpt-wp-gpt-automation-pro/)
Progetto Virtual Gate: [virtualgate.workingwithweb.eu](https://virtualgate.workingwithweb.eu/)

## Licenza di WP GPT Automation Free

Il plugin è gratuito ma vuole una licenza, legata al dominio del sito (`wp_domain`). Si ottiene così:

1. apri [WP GPT Automation Free](https://workingwithweb.it/webagency/prodotto/wp-gpt-automation-free/) su Working With Web e mettilo nel carrello: costa 0 €;
2. completa l'ordine (se non hai un account lo crei lì, con la tua email);
3. il sistema delle licenze crea la chiave appena l'ordine è completato: la trovi in [Il mio account › Le mie licenze](https://workingwithweb.it/webagency/mio-account/le-mie-licenze/) e nell'email dell'ordine;
4. copia la chiave nell'opzione `wp_gpt_license_key` di questo add-on e riavvialo: la licenza si lega da sola al primo sito che la usa (il dominio di `wp_domain`) e da lì vale solo per lui.

A ogni avvio l'add-on scrive la chiave nel sito: cambiarla dalla bacheca di WordPress non basta, va cambiata qui.
