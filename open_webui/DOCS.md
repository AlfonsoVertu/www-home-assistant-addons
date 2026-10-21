# Open WebUI

L'interfaccia di chat di [Open WebUI](https://github.com/open-webui/open-webui) dentro Home Assistant, per parlare con i tuoi modelli: Ollama, llama.cpp/llama-swap o qualunque servizio compatibile OpenAI.

## Opzioni
| Opzione | A cosa serve |
|---|---|
| `ollama_base_url` | Indirizzo di Ollama, es. `http://192.0.2.10:11434` (vuoto = nessuno). |
| `openai_api_base_url` | Indirizzo di un servizio compatibile OpenAI, es. `http://192.0.2.10:8080/v1`. |
| `openai_api_key` | La chiave di quel servizio (se ne vuole una). |
| `enable_signup` | Se nuove persone possono registrarsi. Il **primo** account creato è l'amministratore: crealo subito, poi spegni questa opzione. |

## Uso
Avvia l'add-on e apri `http://homeassistant.local:8096/`. Al primo avvio scarica i suoi modelli interni (qualche minuto). I dati (chat, utenti, impostazioni) stanno nella cartella dell'add-on e finiscono nei backup di Home Assistant.

## Licenza
Open WebUI è di Open WebUI Inc. con la sua licenza (BSD-3 con clausola sul marchio): il nome e il logo di Open WebUI restano visibili. L'add-on usa l'immagine ufficiale senza modifiche.
