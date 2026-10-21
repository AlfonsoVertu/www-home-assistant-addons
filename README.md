<p align="center">
  <img src="www_logo.png" width="80" alt="Working With Web" />
</p>

# Working With Web — add-on per Home Assistant

Gli add-on che usiamo nelle case con Home Assistant, pronti da aggiungere al tuo.

## Come si aggiunge
Home Assistant › Impostazioni › Add-on › Store degli add-on › ⋮ › Repository, e incolla:

```
https://github.com/AlfonsoVertu/www-home-assistant-addons
```

Oppure usa il browser **Another Wilde Web** ([workingwithweb.eu/another-wilde-web](https://workingwithweb.eu/another-wilde-web/)): cerca Home Assistant nella rete e installa gli add-on da solo.

## Gli add-on
| Add-on | Cosa fa |
|---|---|
| [Virtual World Dashboard](virtual_world_dashboard/DOCS.md) | WordPress con il tema domotica di Working With Web e **WP GPT Automation Free**: la dashboard di casa e le API per comandarla dagli assistenti (SoWay Mobile, FroWay, il browser Another Wilde Web). Usa il MariaDB ufficiale di Home Assistant. |
| [Eclipse Finder](eclipse_finder/README.md) | La casa viva: trova i dispositivi, li racconta e dà alla casa un avatar che parla (anche il tuo personaggio del gioco Another Wilde Web). |
| [Nginx Proxy Manager](nginx_proxy_manager/DOCS.md) | Proxy inverso coi certificati Let's Encrypt, per dare un nome https ai servizi di casa. |
| [Nextcloud](nextcloud/DOCS.md) | Il tuo cloud di file, già collegato a EuroOffice per aprire e scrivere documenti. |
| [EuroOffice](eurooffice/DOCS.md) | Il server dei documenti (basato su ONLYOFFICE) per scrivere insieme testi, fogli e presentazioni. |
| [BentoPDF](bentopdf/DOCS.md) | Tutti gli strumenti PDF nel browser; i file non lasciano il tuo dispositivo. |
| [Open WebUI](open_webui/DOCS.md) | La chat coi modelli di intelligenza artificiale (Ollama, llama.cpp, servizi compatibili OpenAI). |

Gli add-on si costruiscono sul tuo Home Assistant dal loro Dockerfile: quello che installi è esattamente il codice di questo repository.

## Licenze
Ogni add-on segue la licenza del programma che contiene: Nextcloud (AGPL-3.0), ONLYOFFICE/EuroOffice (AGPL-3.0), Nginx Proxy Manager (MIT), BentoPDF (AGPL-3.0), Open WebUI (la sua licenza, col marchio visibile), WordPress (GPL-2.0+). **WP GPT Automation Free**, il tema e i plugin di Working With Web sono di Alfonso Vertucci — Working With Web: liberi da usare in Home Assistant, vedi le note nei loro file.

---
Fatto da [Working With Web](https://workingwithweb.it/webagency/).
