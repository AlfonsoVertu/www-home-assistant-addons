# Le rotte di WP GPT Automation Free

> Generato da `tools/genera-readme.php`. Non si modifica a mano: si rigenera.

Ogni rotta e' un tool, e ogni tool contiene piu' operazioni. L'operazione si
sceglie con il parametro `action`. Non esiste una rotta per operazione.

Su ogni rotta si puo' chiedere la documentazione senza uscire dalla rotta:

- `action: documentazione` — tutta la rotta, a pagine
- `action: documentazione_<nome-operazione>` — una operazione sola

Ogni chiamata vuole il **bearer token** nell'intestazione e **user_code** nel corpo.

## Indice

- [gather-information-youtube-google-serp-keywrd-analisys-image-link](#gather-information-youtube-google-serp-keywrd-analisys-image-link) — 14 operazioni
- [goal](#goal) — 18 operazioni
- [manage-casahomeassistant-domoticandashboard-videocam](#manage-casahomeassistant-domoticandashboard-videocam) — 19 operazioni
- [manage-file](#manage-file) — 3 operazioni
- [manage-page-with-user-code](#manage-page-with-user-code) — 9 operazioni
- [route-scheduler](#route-scheduler) — 6 operazioni
- [ssh-connection](#ssh-connection) — 4 operazioni
- [ssh-connection-advanced-command](#ssh-connection-advanced-command) — 11 operazioni
- [tools-manifest](#tools-manifest) — 5 operazioni
- [webpage-link-and-twitter-scraping](#webpage-link-and-twitter-scraping) — 10 operazioni

## gather-information-youtube-google-serp-keywrd-analisys-image-link

`POST /wp-json/wp-gpt/v1/gather-information-youtube-google-serp-keywrd-analisys-image-link`

Cerca informazioni fuori dal sito: risultati di Google, notizie, immagini, tendenze, mappe, video di YouTube e trascrizioni.

### `youtube-transcription`

Il testo parlato di un video di YouTube, a pezzi. Serve per leggere un video senza guardarlo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `video_url` | string | si | Indirizzo del video. |
| `chunk_index` | integer | no | Quale pezzo della trascrizione leggere, da 0. Le trascrizioni lunghe non entrano in una risposta sola. |

### `search-youtube-video`

Cerca video su YouTube.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `query` | string | si | Cosa cercare. Qui il parametro si chiama query, non keyword. |

### `extract-image-links`

Trova indirizzi di immagini a partire da una ricerca.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `keyword` | string | si | Le parole da cercare. |
| `language` | string | no | Lingua dei risultati, per esempio it. Cambia cosa si trova, non solo come e scritto. |
| `country` | string | no | Paese da cui si cerca, per esempio it: i risultati di Google cambiano parecchio da un paese all altro. |
| `location` | string | no | Citta o zona da cui simulare la ricerca, per i risultati locali. |

### `serpapi-serp-analysis`

Analizza i risultati di Google per una parola chiave: cosa esce, in che ordine, da quali siti.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `keyword` | string | si | Le parole da cercare. |
| `hl` | string | no | Lingua dell interfaccia di ricerca, due lettere. |
| `gl` | string | no | Paese da cui si cerca, due lettere. |
| `chunk_index` | integer | no | Quale pezzo dei risultati leggere, da 0. |
| `chunk_size` | integer | no | Quanti risultati per pezzo. |

### `seo_analisys_serpapi_google_search`

I risultati di una ricerca su Google, come li vedrebbe una persona dal paese e dal dispositivo indicati.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `keyword` | string | si | Le parole da cercare. |
| `language` | string | no | Lingua dei risultati, per esempio it. Cambia cosa si trova, non solo come e scritto. |
| `country` | string | no | Paese da cui si cerca, per esempio it: i risultati di Google cambiano parecchio da un paese all altro. |
| `location` | string | no | Citta o zona da cui simulare la ricerca, per i risultati locali. |
| `page_number` | integer | no | Pagina dei risultati, da 1. |
| `results_per_page` | integer | no | Quanti risultati per pagina. |
| `device` | string | no | Se cercare come da telefono o da computer: i risultati differiscono. |
| `safe_search` | string | no | Filtro dei contenuti per adulti. |
| `time_filter` | string | no | Limita ai risultati di un periodo, per esempio l ultimo mese. |
| `output_format` | string | no | In che forma restituire i risultati. |
| `api_domain` | string | no | Quale dominio di Google interrogare, per esempio google.it. |

### `seo_analisys_serpapi_google_images`

I risultati di Google Immagini.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `keyword` | string | si | Le parole da cercare. |
| `language` | string | no | Lingua dei risultati, per esempio it. Cambia cosa si trova, non solo come e scritto. |
| `country` | string | no | Paese da cui si cerca, per esempio it: i risultati di Google cambiano parecchio da un paese all altro. |
| `location` | string | no | Citta o zona da cui simulare la ricerca, per i risultati locali. |
| `page_number` | integer | no | Pagina dei risultati, da 1. |
| `results_per_page` | integer | no | Quanti risultati per pagina. |
| `device` | string | no | Se cercare come da telefono o da computer: i risultati differiscono. |
| `safe_search` | string | no | Filtro dei contenuti per adulti. |
| `time_filter` | string | no | Limita ai risultati di un periodo, per esempio l ultimo mese. |
| `query` | string | no | Nome alternativo di keyword. |

### `seo_analisys_serpapi_google_news`

Le notizie di Google su un argomento.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `keyword` | string | si | Le parole da cercare. |
| `language` | string | no | Lingua dei risultati, per esempio it. Cambia cosa si trova, non solo come e scritto. |
| `country` | string | no | Paese da cui si cerca, per esempio it: i risultati di Google cambiano parecchio da un paese all altro. |
| `location` | string | no | Citta o zona da cui simulare la ricerca, per i risultati locali. |
| `page_number` | integer | no | Pagina dei risultati, da 1. |
| `results_per_page` | integer | no | Quanti risultati per pagina. |
| `safe_search` | string | no | Filtro dei contenuti per adulti. |
| `time_filter` | string | no | Limita ai risultati di un periodo, per esempio l ultimo mese. |
| `category` | string | no | Categoria di notizie. |
| `geo` | string | no | Zona geografica delle notizie. |
| `trend_type` | string | no | Tipo di andamento da guardare. |

### `seo_analisys_serpapi_google_trends`

Le tendenze di ricerca: come cambia nel tempo l interesse per un argomento.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `keyword` | string | si | Le parole da cercare. |
| `language` | string | no | Lingua dei risultati, per esempio it. Cambia cosa si trova, non solo come e scritto. |
| `country` | string | no | Paese da cui si cerca, per esempio it: i risultati di Google cambiano parecchio da un paese all altro. |
| `location` | string | no | Citta o zona da cui simulare la ricerca, per i risultati locali. |
| `page_number` | integer | no | Pagina dei risultati, da 1. |
| `results_per_page` | integer | no | Quanti risultati per pagina. |
| `date_range` | string | no | Periodo da osservare. |
| `geo` | string | no | Zona geografica. |
| `category` | string | no | Categoria merceologica. |
| `trend_type` | string | no | Tipo di andamento. |
| `shopping_condition` | string | no | Condizione del prodotto, per le tendenze di acquisto. |

### `seo_analisys_serpapi_google_shopping`

I prodotti che Google Shopping mostra per una ricerca: utile per guardare i prezzi della concorrenza.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `keyword` | string | si | Le parole da cercare. |
| `language` | string | no | Lingua dei risultati, per esempio it. Cambia cosa si trova, non solo come e scritto. |
| `country` | string | no | Paese da cui si cerca, per esempio it: i risultati di Google cambiano parecchio da un paese all altro. |
| `location` | string | no | Citta o zona da cui simulare la ricerca, per i risultati locali. |
| `page_number` | integer | no | Pagina dei risultati, da 1. |
| `results_per_page` | integer | no | Quanti risultati per pagina. |
| `device` | string | no | Se cercare come da telefono o da computer: i risultati differiscono. |
| `safe_search` | string | no | Filtro dei contenuti per adulti. |
| `shopping_condition` | string | no | Nuovo o usato. |

### `seo_analisys_serpapi_google_scholar`

I risultati accademici di Google Scholar.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `keyword` | string | si | Le parole da cercare. |
| `language` | string | no | Lingua dei risultati, per esempio it. Cambia cosa si trova, non solo come e scritto. |
| `country` | string | no | Paese da cui si cerca, per esempio it: i risultati di Google cambiano parecchio da un paese all altro. |
| `location` | string | no | Citta o zona da cui simulare la ricerca, per i risultati locali. |
| `page_number` | integer | no | Pagina dei risultati, da 1. |
| `results_per_page` | integer | no | Quanti risultati per pagina. |
| `query` | string | no | Nome alternativo di keyword. |
| `author_id` | string | no | Cerca i lavori di un autore preciso. |
| `cite_id` | string | no | Le citazioni di un lavoro preciso. |
| `cluster_id` | string | no | Le versioni di uno stesso lavoro. |
| `type` | string | no | Che genere di ricerca fare. |
| `latitude` | string | no | Latitudine. |
| `longitude` | string | no | Longitudine. |

### `seo_analisys_serpapi_google_maps`

I risultati di Google Maps: attivita, indirizzi, recensioni.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `keyword` | string | si | Le parole da cercare. |
| `language` | string | no | Lingua dei risultati, per esempio it. Cambia cosa si trova, non solo come e scritto. |
| `country` | string | no | Paese da cui si cerca, per esempio it: i risultati di Google cambiano parecchio da un paese all altro. |
| `location` | string | no | Citta o zona da cui simulare la ricerca, per i risultati locali. |
| `query` | string | no | Nome alternativo di keyword. |
| `latitude` | string | no | Latitudine del punto da cui cercare. |
| `longitude` | string | no | Longitudine del punto da cui cercare. |
| `zoom_level` | string | no | Quanto stretta e la zona: cambia quante attivita si vedono. |
| `data_type` | string | no | Che dati chiedere della scheda. |
| `type` | string | no | Tipo di ricerca sulla mappa. |

### `seo_analisys_serpapi_google_ai_overview`

Il riassunto generato da Google in cima ai risultati, quando c e.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `keyword` | string | si | Le parole da cercare. |
| `language` | string | no | Lingua dei risultati, per esempio it. Cambia cosa si trova, non solo come e scritto. |
| `country` | string | no | Paese da cui si cerca, per esempio it: i risultati di Google cambiano parecchio da un paese all altro. |
| `location` | string | no | Citta o zona da cui simulare la ricerca, per i risultati locali. |
| `page_number` | integer | no | Pagina dei risultati, da 1. |
| `results_per_page` | integer | no | Quanti risultati per pagina. |

### `google_related_questions_api`

Le domande correlate che Google mostra sotto i risultati: sono le domande che le persone fanno davvero su quell argomento.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `query` | string | si | Da quale ricerca partire. |
| `language` | string | no | Lingua dei risultati, per esempio it. Cambia cosa si trova, non solo come e scritto. |
| `country` | string | no | Paese da cui si cerca, per esempio it: i risultati di Google cambiano parecchio da un paese all altro. |
| `next_page_token` | string | no | Il segnaposto restituito dalla chiamata precedente, per avere le domande successive. |

### `google_autocomplete_api`

I completamenti che Google propone mentre si digita: dicono come le persone cercano davvero una cosa.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `keyword` | string | si | Le parole da cercare. |
| `query` | string | no | Nome alternativo di keyword. |
| `language` | string | no | Lingua dei risultati, per esempio it. Cambia cosa si trova, non solo come e scritto. |
| `country` | string | no | Paese da cui si cerca, per esempio it: i risultati di Google cambiano parecchio da un paese all altro. |
| `results_per_page` | integer | no | Quanti risultati per pagina. |

## goal

`POST /wp-json/wp-gpt/v1/memory/goal`

Tiene gli obiettivi e i passi per arrivarci: li crea, ne segue lo stato e dice a che punto sono.

### `list_goals`

Elenca gli obiettivi. Da chiamare per prima: le altre action partono da un id.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `limit` | integer | no | Quanti risultati per volta. |
| `offset` | integer | no | Da quale risultato cominciare, per scorrere le pagine. |
| `status` | string | no | Restringe a uno stato. |
| `include_deleted` | boolean | no | Se mostrare anche quelli messi da parte con delete. |

### `get_goal`

Legge un obiettivo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `goal_id` | integer | si | Id dell obiettivo, preso da list_goals. |

### `create_goal`

Crea un obiettivo nuovo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `goal_title` | string | si | Come si chiama l obiettivo. |
| `goal_type` | string | no | Di che genere e l obiettivo. |
| `keyword` | string | no | Una parola per ritrovarlo. |
| `meta` | object | no | Dati in piu da tenere insieme all obiettivo. |

### `update_goal`

Cambia un obiettivo esistente.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `goal_id` | integer | si | Id dell obiettivo, preso da list_goals. |
| `goal_title` | string | no | Come si chiama l obiettivo. |
| `goal_type` | string | no | Di che genere e l obiettivo. |
| `keyword` | string | no | Una parola per ritrovarlo. |
| `meta` | object | no | Dati in piu da tenere insieme all obiettivo. |
| `status` | string | no | A che punto e l obiettivo. |

### `delete_goal`

Mette da parte un obiettivo. Si puo ancora rivedere chiedendo include_deleted a list_goals.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `goal_id` | integer | si | Id dell obiettivo, preso da list_goals. |

### `hard_delete_goal`

ATTENZIONE: cancella davvero un obiettivo. Non si recupera.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `goal_id` | integer | si | Id dell obiettivo, preso da list_goals. |

### `delete_all_goals`

ATTENZIONE: toglie tutti gli obiettivi in un colpo. Con hard vero non si recupera piu niente.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `hard` | boolean | no | Vero per cancellare davvero invece di mettere da parte. |

### `get_status`

Dice a che punto e un obiettivo, guardando i suoi passi.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `goal_id` | integer | si | Id dell obiettivo, preso da list_goals. |

### `list_steps`

Elenca i passi di un obiettivo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `goal_id` | integer | si | Id dell obiettivo, preso da list_goals. |
| `limit` | integer | no | Quanti risultati per volta. |
| `offset` | integer | no | Da quale risultato cominciare, per scorrere le pagine. |
| `status` | string | no | Restringe a uno stato. |
| `include_deleted` | boolean | no | Se mostrare anche quelli messi da parte con delete. |

### `get_step`

Legge un passo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `step_id` | integer | si | Id del passo, preso da list_steps. |

### `add_step`

Aggiunge un passo a un obiettivo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `goal_id` | integer | si | Id dell obiettivo, preso da list_goals. |
| `step_label` | string | si | Cosa dice il passo. |
| `step_type` | string | no | Di che genere e il passo. |
| `status` | string | no | A che punto e il passo. |
| `meta` | object | no | Dati in piu da tenere insieme al passo. |

### `update_step`

Cambia un passo: e cosi che si segna fatto quello che e fatto.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `step_id` | integer | si | Id del passo, preso da list_steps. |
| `step_label` | string | no | Il testo nuovo del passo. |
| `step_type` | string | no | Il genere del passo. |
| `status` | string | no | Lo stato nuovo. |
| `meta` | object | no | Dati in piu. |

### `delete_step`

Mette da parte un passo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `step_id` | integer | si | Id del passo, preso da list_steps. |

### `hard_delete_step`

ATTENZIONE: cancella davvero un passo. Non si recupera.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `step_id` | integer | si | Id del passo, preso da list_steps. |

### `delete_all_steps`

ATTENZIONE: toglie tutti i passi di un obiettivo in un colpo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `goal_id` | integer | si | Id dell obiettivo, preso da list_goals. |
| `hard` | boolean | no | Vero per cancellare davvero invece di mettere da parte. |

### `hard_delete_steps_by_goal`

ATTENZIONE: cancella davvero tutti i passi di un obiettivo. Non si recuperano.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `goal_id` | integer | si | Id dell obiettivo, preso da list_goals. |

### `get_chunked_response`

Legge un pezzo di una risposta messa da parte perche troppo lunga.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `chunk_key` | string | si | La chiave restituita dalla risposta troncata. |
| `chunk_index` | integer | no | Quale pezzo leggere, da 0. |

### `delete_chunk`

Butta via una risposta messa da parte, per liberare spazio.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `chunk_key` | string | si | La chiave della risposta da buttare. |

## manage-casahomeassistant-domoticandashboard-videocam

`POST /wp-json/wp-gpt/v1/manage-casahomeassistant-domoticandashboard-videocam`

Legge e comanda l'impianto di Home Assistant: entita', scene, script, automazioni, telecamere, cronologia e calendari.

### `get-entities`

Elenca tutte le entita' dell'impianto, a pagine.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `page` | integer | no | Pagina da leggere, a partire da 1. |
| `limit` | integer | no | Quanti risultati per pagina. |

### `search`

Cerca un'entita' per nome: confronta sia l'entity_id sia il nome amichevole, su tutti i domini. E' il modo giusto di trovare "il condizionatore del soggiorno" senza leggersi tutto.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `query` | string | si | Pezzo di nome da cercare, anche parziale. |
| `page` | integer | no | Pagina da leggere, a partire da 1. |
| `limit` | integer | no | Quanti risultati per pagina. |

### `control-entity`

ESEGUE un comando sull'impianto: accende, spegne, imposta. Tutto cio' che non e' un parametro di sistema viene passato al servizio come dato.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `domain` | string | si | Il dominio del servizio: light, switch, climate, media_player, cover, scene, script... |
| `service` | string | si | Il servizio da chiamare: turn_on, turn_off, toggle, set_temperature... |
| `entity_id` | string | no | L'entita' su cui agire. Si ricava con search. |
| `payload` | object | no | Dati aggiuntivi per il servizio. |

### `get-script`

Elenca gli script.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `page` | integer | no | Pagina da leggere, a partire da 1. |
| `limit` | integer | no | Quanti risultati per pagina. |

### `get-automation`

Elenca le automazioni.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `page` | integer | no | Pagina da leggere, a partire da 1. |
| `limit` | integer | no | Quanti risultati per pagina. |

### `get-scene`

Elenca le scene.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `page` | integer | no | Pagina da leggere, a partire da 1. |
| `limit` | integer | no | Quanti risultati per pagina. |

### `get-camera-list`

Elenca le telecamere.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `page` | integer | no | Pagina da leggere, a partire da 1. |
| `limit` | integer | no | Quanti risultati per pagina. |

### `get-camera-screen`

Cattura un fermo immagine da una telecamera e lo salva come file su WordPress, restituendone l'indirizzo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `entity_id` | string | si | La telecamera, es. camera.ingresso. |

### `get-assistant-list`

Elenca gli assistenti conversazionali configurati.

Nessun parametro oltre ad `action`.

### `send-command-to-assistant`

Manda una frase all'assistente di Home Assistant e ne riporta la risposta.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `text` | string | si | La frase da dire all'assistente. |
| `agent_id` | string | no | Quale assistente, se ce n'e' piu' d'uno. |

### `get-history`

La cronologia degli stati di un'entita' in un periodo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `entity_id` | string | si | L'entita' di cui leggere la storia. |
| `start` | string | no | Inizio del periodo, in formato ISO 8601. |
| `end` | string | no | Fine del periodo, in formato ISO 8601. |

### `get-logbook`

Il diario degli eventi dell'impianto.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `entity_id` | string | no | Limita il diario a una sola entita'. |
| `start` | string | no | Inizio del periodo, in formato ISO 8601. |
| `end` | string | no | Fine del periodo, in formato ISO 8601. |

### `get-calendars`

Elenca i calendari collegati.

Nessun parametro oltre ad `action`.

### `get-calendar-events`

Gli appuntamenti di un calendario in un periodo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `entity_id` | string | si | Il calendario, es. calendar.famiglia. |
| `start` | string | no | Inizio del periodo, in formato ISO 8601. |
| `end` | string | no | Fine del periodo, in formato ISO 8601. |

### `get-shortcode`

Restituisce lo shortcode con cui mostrare un'entita' dentro una pagina del sito.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `entity_id` | string | si | L'entita' da mostrare. |
| `columns` | integer | no | Su quante colonne disporla. |

### `discover`

Elenca quello che Home Assistant conosce, con lo stato di ogni cosa. Da chiamare per prima: da qui si prendono gli identificativi che servono a execute.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `type` | string | no | Che genere di cose elencare: all tutto, entities i dispositivi, scripts gli script, automations le automazioni, assistants gli assistenti vocali. |
| `entities` | string | no | Restringe a identificativi precisi, separati da virgola. |
| `page` | integer | no | Quale pagina di risultati, da 1. |
| `per_page` | integer | no | Quanti risultati per pagina, al massimo 100. |
| `limit` | integer | no | Tetto complessivo ai risultati, indipendente dalle pagine. |

### `execute`

ATTENZIONE: fa succedere qualcosa nella casa vera. Chiama un servizio di Home Assistant, per esempio accendere una luce o aprire una serranda.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `domain` | string | si | Il genere di cosa da comandare, per esempio light o switch. E la prima parte dell entity_id, quella prima del punto. |
| `service` | string | si | Cosa fargli fare, per esempio turn_on o turn_off. |
| `entity_id` | string | no | Su quale cosa preciso agire, come lo restituisce discover. Senza, il comando vale per tutto il dominio: e il modo di spegnere per sbaglio ogni luce della casa. |
| `data` | object | no | Dettagli in piu per il servizio, per esempio la luminosita o il colore. |

### `send-message-home-assistant-agent`

Scrive una frase all assistente vocale di Home Assistant, come se gliela si dicesse a voce, e riporta la sua risposta. Quello che dice puo far agire la casa.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `text` | string | si | La frase da dire allassistente. |
| `agent_id` | string | no | Quale assistente, se ce ne e piu di uno. Si trovano con discover chiedendo type assistants. |
| `conversation_id` | string | no | Il filo di una conversazione gia cominciata, per farsi capire quando la frase si riferisce a quella prima. |

### `reload`

Rilegge la configurazione del collegamento a Home Assistant. Da usare dopo averla cambiata, oppure quando le altre action falliscono tutte allo stesso modo.

Nessun parametro oltre ad `action`.

## manage-file

`POST /wp-json/wp-gpt/v1/manage-file`

Manda file e immagini al sito, in un colpo solo o a pezzi quando sono troppo grandi per una chiamata sola.

### `send-file`

Carica sul sito i file che l assistente ha gia a disposizione.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `openaiFileIdRefs` | array | si | I riferimenti ai file da caricare, almeno uno. |

### `send-image-base-64`

Carica una immagine mandandone il contenuto codificato dentro la chiamata. Va bene per le immagini piccole: se la chiamata diventa troppo grande serve send-image-base-64-in-chunk.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `content_base64` | string | si | Il contenuto dell immagine codificato in base64. |
| `filename` | string | si | Il nome del file, estensione compresa. |
| `mime_type` | string | si | Che tipo di file e. Solo immagini: png, jpeg, jpg, webp o gif. Qualunque altro tipo viene rifiutato. |
| `title` | string | no | Il titolo con cui l immagine entra nella libreria del sito. |
| `alt` | string | no | La descrizione per chi non vede l immagine. Serve a chi usa un lettore di schermo, e i motori di ricerca la leggono. |
| `caption` | string | no | La didascalia mostrata sotto l immagine. |
| `description` | string | no | Una descrizione piu lunga, nella libreria del sito. |

### `send-image-base-64-in-chunk`

Carica una immagine grande mandandola a pezzi. I pezzi si mandano uno dopo l altro con lo stesso upload_id, e l immagine viene messa insieme solo quando arriva lultimo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `chunk_base64` | string | si | Questo pezzo dell immagine, codificato in base64. |
| `chunk_index` | integer | si | Quale pezzo e questo, da 0. |
| `total_chunks` | integer | si | Quanti pezzi in tutto. |
| `upload_id` | string | no | Il codice che tiene insieme i pezzi della stessa immagine. Va ripetuto uguale a ogni pezzo, altrimenti i pezzi non si ritrovano fra loro. |
| `is_last_chunk` | boolean | no | Vero sull ultimo pezzo: e quello che fa mettere insieme l immagine. |
| `filename` | string | si | Il nome del file, estensione compresa. |
| `mime_type` | string | si | Che tipo di file e. Solo immagini: png, jpeg, jpg, webp o gif. Qualunque altro tipo viene rifiutato. |
| `title` | string | no | Il titolo con cui l immagine entra nella libreria del sito. |
| `alt` | string | no | La descrizione per chi non vede l immagine. Serve a chi usa un lettore di schermo, e i motori di ricerca la leggono. |
| `caption` | string | no | La didascalia mostrata sotto l immagine. |
| `description` | string | no | Una descrizione piu lunga, nella libreria del sito. |

## manage-page-with-user-code

`POST /wp-json/wp-gpt/v1/manage-page-with-user-code`

Crea e modifica le pagine del sito, ne governa il modello di impaginazione, i menu e quale pagina fa da pagina iniziale.

### `retrieve`

Legge una pagina. Da fare prima di modificarla, se non si sa gia cosa contiene.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `page_id` | integer | si | Id della pagina. |
| `chunk_index` | integer | no | Quale pezzo leggere, per le pagine troppo lunghe per stare in una risposta sola. |

### `create-page`

Crea una pagina nuova. Con status publish e subito visibile a chiunque visiti il sito.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `title` | string | no | Il titolo della pagina. |
| `content` | string | no | Il contenuto della pagina, in HTML. |
| `excerpt` | string | no | Il riassunto della pagina. |
| `status` | string | no | Se pubblicarla o tenerla in bozza: publish la rende visibile a chiunque visiti il sito. |
| `slug` | string | no | Il pezzo finale dell indirizzo. Cambiandolo su una pagina gia pubblicata, i collegamenti vecchi smettono di funzionare. |
| `featured_image` | string | no | L immagine di copertina. |
| `seo_title` | string | no | Il titolo mostrato nei risultati di ricerca. |
| `meta_description` | string | no | Il riassunto mostrato nei risultati di ricerca. |
| `focus_keywords` | string | no | Le parole su cui la pagina punta a farsi trovare. |

### `update-page`

ATTENZIONE: sostituisce il contenuto della pagina per intero. Quello che cera prima non e recuperabile: conviene leggerla con retrieve.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `id` | integer | si | Id della pagina. |
| `title` | string | no | Il titolo della pagina. |
| `content` | string | no | Il contenuto della pagina, in HTML. |
| `excerpt` | string | no | Il riassunto della pagina. |
| `status` | string | no | Se pubblicarla o tenerla in bozza: publish la rende visibile a chiunque visiti il sito. |
| `slug` | string | no | Il pezzo finale dell indirizzo. Cambiandolo su una pagina gia pubblicata, i collegamenti vecchi smettono di funzionare. |
| `featured_image` | string | no | L immagine di copertina. |
| `seo_title` | string | no | Il titolo mostrato nei risultati di ricerca. |
| `meta_description` | string | no | Il riassunto mostrato nei risultati di ricerca. |
| `focus_keywords` | string | no | Le parole su cui la pagina punta a farsi trovare. |

### `get-homepage`

Dice quale pagina fa da pagina iniziale del sito.

Nessun parametro oltre ad `action`.

### `set-homepage`

ATTENZIONE: decide quale pagina vedono i visitatori arrivando sul sito. Cambia la prima cosa che vede chiunque.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `page_id` | integer | si | Id della pagina. |

### `view-menu`

Mostra il menu legato a una pagina. Da chiamare prima di update-menu: quella riscrive tutto e conviene sapere cosa cera.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `page_id` | integer | si | Id della pagina. |

### `update-menu`

ATTENZIONE: riscrive un menu del sito. Il menu si vede su tutte le pagine, non solo su questa, e la struttura di prima non e recuperabile.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `page_id` | integer | si | Id della pagina. |
| `menu_data` | object | si | La struttura nuova del menu, che prende il posto di quella di prima. Conviene ricavarla da view-menu e cambiarne solo quello che serve. |

### `get-template`

Dice con quale modello di impaginazione viene mostrata una pagina.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `page_id` | integer | si | Id della pagina. |

### `manage-template`

Cambia il modello di impaginazione di una pagina: cambia come la pagina si vede, non cosa contiene.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `page_id` | integer | si | Id della pagina. |
| `template_name` | string | si | Il modello da usare, fra quelli che il tema mette a disposizione. Un nome che il tema non conosce non funziona. |

## route-scheduler

`POST /wp-json/wp-gpt/v1/route-scheduler`

Programma chiamate ad altre rotte di questo sito perche avvengano piu tardi, una volta sola o a ripetizione, e ne segue lesito.

### `list_jobs`

Elenca i lavori programmati con il loro stato. Da chiamare per prima: le altre action partono da una chiave.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `page` | integer | no | Quale pagina di risultati, da 1. |
| `per_page` | integer | no | Quanti lavori per pagina, al massimo 100. |
| `status` | string | no | Restringe a uno stato, per esempio active. |
| `search` | string | no | Cerca nel nome del lavoro e dentro la sua definizione, quindi trova anche per rotta chiamata. |
| `job_key` | string | no | Restringe a un lavoro solo. |

### `add_job`

Programma una chiamata. Il lavoro conserva le credenziali di chi lo crea per potersi presentare piu tardi con la stessa identita.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `job_key` | string | si | La chiave del lavoro, quella scelta con add_job. Serve a ritrovarlo: conviene un nome che dica cosa fa. |
| `definition` | object | si | Cosa fare: target_route, obbligatorio, il percorso REST relativo da chiamare, per esempio /wp-gpt/v1/rotta - un indirizzo completo viene rifiutato; payload, i parametri da mandare; schedule, quando e ogni quanto; headers, intestazioni in piu. |

### `remove_job`

Toglie un lavoro programmato: smette di partire e le credenziali che aveva conservato spariscono con lui.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `job_key` | string | si | La chiave del lavoro, quella scelta con add_job. Serve a ritrovarlo: conviene un nome che dica cosa fa. |

### `run_job`

Esegue subito un lavoro programmato, senza aspettare il suo momento. La chiamata avviene davvero, con tutti i suoi effetti: non e una prova a vuoto.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `job_key` | string | si | La chiave del lavoro, quella scelta con add_job. Serve a ritrovarlo: conviene un nome che dica cosa fa. |

### `get_log`

Legge il registro di un lavoro: quando e partito e come e finito. Le righe piu recenti per prime.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `job_key` | string | si | La chiave del lavoro, quella scelta con add_job. Serve a ritrovarlo: conviene un nome che dica cosa fa. |
| `page` | integer | no | Quale pagina di righe, da 1. |
| `per_page` | integer | no | Quante righe per pagina, al massimo 500. |
| `search` | string | no | Tiene solo le righe che contengono questo testo. Maiuscole e minuscole non contano. |

### `set_logging`

Accende o spegne la scrittura del registro dei lavori. Spento, get_log non avra piu niente di nuovo da mostrare.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `logging` | boolean | si | Vero per scrivere il registro, falso per smettere. |

## ssh-connection

`POST /wp-json/wp-gpt/v1/ssh-connection`

Si collega a un server via SSH ed esegue un comando, restituendo quello che il server risponde.

### `ssh-connection`

Apre la connessione ed esegue il comando indicato. Il risultato e l uscita del comando, come si vedrebbe in un terminale.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `host` | string | si | Indirizzo del server a cui collegarsi. |
| `port` | integer | si | Porta SSH del server, di solito 22. |
| `username` | string | si | Utente con cui entrare. |
| `password` | string | no | Password dell utente. Alternativa a private_key: meglio la chiave, cosi la password non viaggia nella chiamata. |
| `private_key` | string | no | Chiave privata per entrare senza password. |
| `private_key_passphrase` | string | no | Frase che sblocca la chiave privata, se ne ha una. |
| `command` | string | si | Il comando da eseguire sul server. |

### `get_ssh_connection_response_list_in_chunk`

Elenca le risposte messe da parte perche troppo lunghe per una sola chiamata.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `transient_key` | string | no | La chiave della risposta da riprendere. |
| `chunk_index` | integer | no | Quale pezzo dell elenco leggere, da 0. |

### `get_ssh_connection_single_response_in_chunk`

Legge un pezzo di una risposta lunga messa da parte.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `transient_key` | string | si | La chiave della risposta, restituita dalla chiamata che era stata troncata. |
| `chunk_index` | integer | si | Quale pezzo leggere, da 0. |

### `delete_ssh_connection_transients`

Butta via le risposte messe da parte, per liberare spazio.

Nessun parametro oltre ad `action`.

## ssh-connection-advanced-command

`POST /wp-json/wp-gpt/v1/ssh-connection-advanced-command`

Lavora sui file di un server via SSH: legge, scrive, carica, cambia permessi e proprietario, esegue script.

### `list-directory`

Elenca il contenuto di una cartella del server.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `host` | string | si | Indirizzo del server a cui collegarsi. |
| `port` | integer | si | Porta SSH del server, di solito 22. |
| `username` | string | si | Utente con cui entrare. |
| `password` | string | no | Password dell utente. Alternativa a private_key: meglio la chiave, cosi la password non viaggia nella chiamata. |
| `private_key` | string | no | Chiave privata per entrare senza password. |
| `private_key_passphrase` | string | no | Frase che sblocca la chiave privata, se ne ha una. |
| `directory` | string | si | La cartella da guardare, con il percorso completo. |

### `read-file`

Legge un file del server.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `host` | string | si | Indirizzo del server a cui collegarsi. |
| `port` | integer | si | Porta SSH del server, di solito 22. |
| `username` | string | si | Utente con cui entrare. |
| `password` | string | no | Password dell utente. Alternativa a private_key: meglio la chiave, cosi la password non viaggia nella chiamata. |
| `private_key` | string | no | Chiave privata per entrare senza password. |
| `private_key_passphrase` | string | no | Frase che sblocca la chiave privata, se ne ha una. |
| `filepath` | string | si | Percorso completo del file. |

### `create-file`

Crea un file col contenuto indicato. Se il file esiste gia, il contenuto di prima viene sostituito.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `host` | string | si | Indirizzo del server a cui collegarsi. |
| `port` | integer | si | Porta SSH del server, di solito 22. |
| `username` | string | si | Utente con cui entrare. |
| `password` | string | no | Password dell utente. Alternativa a private_key: meglio la chiave, cosi la password non viaggia nella chiamata. |
| `private_key` | string | no | Chiave privata per entrare senza password. |
| `private_key_passphrase` | string | no | Frase che sblocca la chiave privata, se ne ha una. |
| `filepath` | string | si | Dove creare il file. |
| `content` | string | si | Cosa scriverci dentro. |

### `append-to-file`

Aggiunge in fondo a un file, senza toccare quello che c e gia. E la scelta prudente quando il file esiste.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `host` | string | si | Indirizzo del server a cui collegarsi. |
| `port` | integer | si | Porta SSH del server, di solito 22. |
| `username` | string | si | Utente con cui entrare. |
| `password` | string | no | Password dell utente. Alternativa a private_key: meglio la chiave, cosi la password non viaggia nella chiamata. |
| `private_key` | string | no | Chiave privata per entrare senza password. |
| `private_key_passphrase` | string | no | Frase che sblocca la chiave privata, se ne ha una. |
| `filepath` | string | si | Il file. |
| `content` | string | si | Cosa aggiungere. |

### `delete-file`

ATTENZIONE: cancella un file dal server. Non passa da un cestino.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `host` | string | si | Indirizzo del server a cui collegarsi. |
| `port` | integer | si | Porta SSH del server, di solito 22. |
| `username` | string | si | Utente con cui entrare. |
| `password` | string | no | Password dell utente. Alternativa a private_key: meglio la chiave, cosi la password non viaggia nella chiamata. |
| `private_key` | string | no | Chiave privata per entrare senza password. |
| `private_key_passphrase` | string | no | Frase che sblocca la chiave privata, se ne ha una. |
| `filepath` | string | si | Il file da cancellare. |

### `upload-file-to-path`

Carica un file sul server nel percorso indicato.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `host` | string | si | Indirizzo del server a cui collegarsi. |
| `port` | integer | si | Porta SSH del server, di solito 22. |
| `username` | string | si | Utente con cui entrare. |
| `password` | string | no | Password dell utente. Alternativa a private_key: meglio la chiave, cosi la password non viaggia nella chiamata. |
| `private_key` | string | no | Chiave privata per entrare senza password. |
| `private_key_passphrase` | string | no | Frase che sblocca la chiave privata, se ne ha una. |
| `filepath` | string | si | Dove metterlo. |
| `content` | string | no | Il contenuto da caricare. |

### `create-script-sh`

Crea uno script di shell sul server. Non lo esegue: per quello serve execute-script.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `host` | string | si | Indirizzo del server a cui collegarsi. |
| `port` | integer | si | Porta SSH del server, di solito 22. |
| `username` | string | si | Utente con cui entrare. |
| `password` | string | no | Password dell utente. Alternativa a private_key: meglio la chiave, cosi la password non viaggia nella chiamata. |
| `private_key` | string | no | Chiave privata per entrare senza password. |
| `private_key_passphrase` | string | no | Frase che sblocca la chiave privata, se ne ha una. |
| `filepath` | string | si | Dove creare lo script. |
| `content` | string | si | Il contenuto dello script. |

### `execute-script`

ATTENZIONE: esegue uno script sul server. Fa tutto quello che lo script dice di fare, con i permessi dell utente indicato.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `host` | string | si | Indirizzo del server a cui collegarsi. |
| `port` | integer | si | Porta SSH del server, di solito 22. |
| `username` | string | si | Utente con cui entrare. |
| `password` | string | no | Password dell utente. Alternativa a private_key: meglio la chiave, cosi la password non viaggia nella chiamata. |
| `private_key` | string | no | Chiave privata per entrare senza password. |
| `private_key_passphrase` | string | no | Frase che sblocca la chiave privata, se ne ha una. |
| `filepath` | string | si | Lo script da eseguire. |

### `custom-command`

ATTENZIONE: esegue un comando qualsiasi sul server. E una shell a tutti gli effetti.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `host` | string | si | Indirizzo del server a cui collegarsi. |
| `port` | integer | si | Porta SSH del server, di solito 22. |
| `username` | string | si | Utente con cui entrare. |
| `password` | string | no | Password dell utente. Alternativa a private_key: meglio la chiave, cosi la password non viaggia nella chiamata. |
| `private_key` | string | no | Chiave privata per entrare senza password. |
| `private_key_passphrase` | string | no | Frase che sblocca la chiave privata, se ne ha una. |
| `command` | string | si | Il comando da eseguire. |

### `chmod`

Cambia i permessi di un file. Permessi troppo aperti su un server esposto sono un problema di sicurezza, non di comodita.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `host` | string | si | Indirizzo del server a cui collegarsi. |
| `port` | integer | si | Porta SSH del server, di solito 22. |
| `username` | string | si | Utente con cui entrare. |
| `password` | string | no | Password dell utente. Alternativa a private_key: meglio la chiave, cosi la password non viaggia nella chiamata. |
| `private_key` | string | no | Chiave privata per entrare senza password. |
| `private_key_passphrase` | string | no | Frase che sblocca la chiave privata, se ne ha una. |
| `filepath` | string | si | Il file. |
| `permissions` | string | si | I permessi da mettere, nella forma numerica come 644. |

### `chown`

Cambia proprietario e gruppo di un file.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `host` | string | si | Indirizzo del server a cui collegarsi. |
| `port` | integer | si | Porta SSH del server, di solito 22. |
| `username` | string | si | Utente con cui entrare. |
| `password` | string | no | Password dell utente. Alternativa a private_key: meglio la chiave, cosi la password non viaggia nella chiamata. |
| `private_key` | string | no | Chiave privata per entrare senza password. |
| `private_key_passphrase` | string | no | Frase che sblocca la chiave privata, se ne ha una. |
| `filepath` | string | si | Il file. |
| `owner` | string | no | Il nuovo proprietario. |
| `group` | string | no | Il nuovo gruppo. |

## tools-manifest

`POST /wp-json/wp-gpt/v1/tools-manifest`

L'attrezzo per orientarsi: elenca i tool di questo sito, le action di ognuno e i parametri di ogni action.

### `lista-dei-tool-che-contengono-le-action`

Elenca i tool disponibili alle tue credenziali, con le action di ognuno.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `cerca` | string | no | Filtra per nome o descrizione. Accetta un pezzo di parola, e perdona una lettera fuori posto. |
| `pagina` | integer | no | Pagina da leggere, a partire da 1. |
| `per_pagina` | integer | no | Quanti tool per pagina. |

### `scopri-parametri-dei-tool`

Apre un tool: cosa fa, quali action ha e cosa fa ognuna.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `tool` | string | si | Il nome del tool, che e' il nome della sua rotta. |

### `scopri-parametri-di-un-action-in-un-tool`

Apre una singola action: parametri obbligatori, opzionali e valori ammessi.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `tool` | string | si | Il nome del tool. |
| `nome_action` | string | si | L'action da aprire. |

### `schema`

Restituisce l'OpenAPI, di un tool o di tutti insieme.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `tool` | string | no | Limita lo schema a un tool solo. |

### `stretta-di-mano`

Lascia a questo sito il tuo indirizzo, cosi' ti avvisa da solo quando le sue credenziali cambiano, invece di lasciarti trovare una porta chiusa.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `odoo_url` | string | si | L'indirizzo a cui questo sito ti richiamera'. |
| `odoo_token` | string | si | Il token con cui ti riconoscerai. |
| `odoo_user_code` | string | si | Il tuo codice utente. |
| `riferimento` | string | no | Un'etichetta tua, per ritrovare l'accordo. |

## webpage-link-and-twitter-scraping

`POST /wp-json/wp-gpt/v1/webpage-link-and-twitter-scraping`

Legge una pagina web e ne estrae parti - immagini, intestazioni, email, testo - oppure legge un profilo o un tweet di Twitter.

### `get-images`

Gli indirizzi di tutte le immagini della pagina.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `link` | string | si | Indirizzo completo della pagina da leggere, con http o https. |

### `get-meta-tags`

I meta tag della pagina: titolo, descrizione, open graph.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `link` | string | si | Indirizzo completo della pagina da leggere, con http o https. |

### `get-headers`

Le intestazioni della pagina, dal titolo ai sottotitoli.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `link` | string | si | Indirizzo completo della pagina da leggere, con http o https. |

### `extract-emails`

Gli indirizzi di posta scritti nella pagina.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `link` | string | si | Indirizzo completo della pagina da leggere, con http o https. |

### `get-div-ids`

Gli id dei blocchi della pagina: servono a mirare una zona precisa con find-expression-in-div.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `link` | string | si | Indirizzo completo della pagina da leggere, con http o https. |

### `get-div-classes`

Le classi dei blocchi della pagina, per lo stesso scopo degli id.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `link` | string | si | Indirizzo completo della pagina da leggere, con http o https. |

### `find-expression-in-div`

Cerca un'espressione dentro un blocco della pagina.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `link` | string | si | Indirizzo completo della pagina da leggere, con http o https. |
| `expression` | string | si | Il testo o l'espressione da cercare. |

### `get-text-chunks`

Il testo della pagina a pezzi: per pagine lunghe che non starebbero in una risposta sola.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `link` | string | si | Indirizzo completo della pagina da leggere, con http o https. |
| `chunk_index` | integer | no | Quale pezzo del risultato leggere quando e' troppo lungo per una risposta sola. Si parte da 0. |

### `get-html-chunks`

L'HTML della pagina a pezzi, quando serve il codice e non solo il testo.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `link` | string | si | Indirizzo completo della pagina da leggere, con http o https. |
| `chunk_index` | integer | no | Quale pezzo del risultato leggere quando e' troppo lungo per una risposta sola. Si parte da 0. |

### `scrape-twitter-profile-or-tweet`

Legge un profilo Twitter, oppure un singolo tweet con i suoi commenti se si passa anche tweet_id.

| parametro | tipo | obbligatorio | a cosa serve |
|---|---|---|---|
| `username` | string | si | Il nome utente, senza la chiocciola. |
| `tweet_id` | string | no | L'id di un tweet: senza, si legge il profilo. |
| `comments_limit` | integer | no | Quanti commenti leggere al massimo. |

