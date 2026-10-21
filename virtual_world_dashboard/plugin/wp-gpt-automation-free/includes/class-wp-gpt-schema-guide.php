<?php

namespace WP_GPT_Automation_Pro;

class WP_GPT_Schema_Guide {

    public static function init() {
        // Inizializza la pagina di guida nel menu di amministrazione
        add_action('admin_menu', [__CLASS__, 'add_schema_guide_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
        add_action('wp_ajax_wp_gpt_fetch_update_package', [__CLASS__, 'ajax_fetch_update_package']);
    }

    public static function add_schema_guide_menu() {
        // Aggiunge una sottovoce di menu alla pagina del plugin GPT Automation Pro
        add_submenu_page(
            'wp-gpt-automation-pro',
            __('Guida alla Configurazione GPTS', 'wp-gpt-automation-pro'),
            __('Guida GPTS', 'wp-gpt-automation-pro'),
            'manage_options',
            'wp-gpt-schema-guide',
            [__CLASS__, 'render_schema_guide_page']
        );
    }

    public static function ajax_fetch_update_package() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Non hai i permessi necessari per eseguire questa operazione.', 'wp-gpt-automation-pro'),
            ], 403);
        }

        check_ajax_referer('wp_gpt_fetch_update_package', 'nonce');

        $update_data = WP_GPT_License_Check::get_update_data();

        if (is_wp_error($update_data)) {
            wp_send_json_error([
                'message' => $update_data->get_error_message(),
            ]);
        }

        if (empty($update_data['package'])) {
            wp_send_json_error([
                'message' => __('Nessun pacchetto aggiornato disponibile: verifica la licenza e riprova più tardi.', 'wp-gpt-automation-pro'),
            ]);
        }

        $response = [
            'package' => esc_url_raw($update_data['package']),
            'message' => __('Licenza verificata: il download verrà avviato in una nuova scheda.', 'wp-gpt-automation-pro'),
        ];

        if (!empty($update_data['expiry_date'])) {
            $response['expiryDate'] = $update_data['expiry_date'];
        }

        if (!empty($update_data['new_version'])) {
            $response['newVersion'] = $update_data['new_version'];
        }

        wp_send_json_success($response);
    }

    private static function get_docs_download_list() {
        $docs_dir = WP_GPT_PLUGIN_DIR . 'docs';
        $docs_url = WP_GPT_PLUGIN_URL . 'docs/';

        if (!is_dir($docs_dir)) {
            return '';
        }

        /*
         * Si offre quello che serve a chi USA questa edizione, non tutto quello
         * che c'e' dentro docs/. La cartella e' ereditata dalla Pro: dentro
         * c'era il manuale della PRO - che descrive rotte che qui non
         * esistono - tre copie vecchie dello schema, e mezzo mega di appunti
         * interni. Un utente che scarica il manuale sbagliato cerca
         * manage-articles, non lo trova, e non capisce se sbaglia lui.
         */
        $ammessi = [
            'README_wp_gpt_automation_free.md' => __('Le rotte di questa edizione: operazioni e parametri.', 'wp-gpt-automation-pro'),
            'memory-manager.md'                => __('Come funzionano obiettivi e passi persistenti.', 'wp-gpt-automation-pro'),
            'www-media-uploader.crx'           => __('L estensione SoWay per il browser: si installa da Chrome, pagina Estensioni, trascinandoci sopra questo file.', 'wp-gpt-automation-pro'),
        ];

        $files = [];
        foreach ($ammessi as $nome => $spiegazione) {
            if (is_file($docs_dir . '/' . $nome)) $files[$nome] = $spiegazione;
        }

        if (empty($files)) {
            return '';
        }

        $output = '<ul class="wp-gpt-docs">';
        foreach ($files as $file => $spiegazione) {
            $output .= sprintf(
                '<li><a href="%s" download>%s</a></li>',
                esc_url($docs_url . rawurlencode($file)),
                esc_html($file)
            );
        }
        $output .= '</ul>';

        return $output;
    }

    /**
     * Parse YAML file containing API paths and return an array of operations.
     * Each item contains: key (path|method), operationId and snippet.
     *
     * @param string $file Absolute path to the YAML file.
     * @return array
     */
    public static function load_yaml_operations($file) {
        if (!file_exists($file)) {
            return [];
        }

        $operations = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return [];
        }

        $in_paths = false;
        $current_path = '';
        $current_method = '';
        $buffer = [];
        $operation_id = '';
        $current_summary = '';
        $current_description = '';
        $collecting_description = false;

        foreach ($lines as $line) {
            if (!$in_paths) {
                if (preg_match('/^\s*paths:\s*$/', $line)) {
                    $in_paths = true;
                }
                continue;
            }

            if (preg_match('/^\s{2}[^\s][^:]*:\s*$/', $line)) {
                if ($current_path && $current_method) {
                    $operations[] = [
                        'key' => $current_path . '|' . $current_method,
                        'operationId' => $operation_id,
                        'snippet' => implode("\n", $buffer),
                        'summary' => $current_summary,
                        'description' => trim($current_description),
                    ];
                    $buffer = [];
                    $current_method = '';
                    $operation_id = '';
                    $current_summary = '';
                    $current_description = '';
                    $collecting_description = false;
                }
                $current_path = trim($line, " :");
                $buffer[] = $line;
                continue;
            }

            if (preg_match('/^\s{4}([a-zA-Z0-9_]+):/', $line, $m)) {
                $http_method = strtolower($m[1]);
                $valid_methods = ['get', 'post', 'put', 'delete', 'patch', 'options', 'head', 'trace'];

                if (in_array($http_method, $valid_methods, true)) {
                    if ($current_method) {
                        $operations[] = [
                            'key' => $current_path . '|' . $current_method,
                            'operationId' => $operation_id,
                            'snippet' => implode("\n", $buffer),
                            'summary' => $current_summary,
                            'description' => trim($current_description),
                        ];
                        $buffer = [];
                        $operation_id = '';
                        $current_summary = '';
                        $current_description = '';
                        $collecting_description = false;
                    }
                    $current_method = $http_method;
                    $buffer[] = $line;
                    continue;
                }
            }

            if ($current_method) {
                $buffer[] = $line;
                if ($collecting_description) {
                    if (preg_match('/^\s{8,}(.*)$/', $line, $desc_line)) {
                        $desc_content = trim($desc_line[1]);
                        if ('' !== $desc_content) {
                            $current_description .= ('' !== $current_description ? "\n" : '') . $desc_content;
                        }
                        continue;
                    }
                    $collecting_description = false;
                }
                if (preg_match('/operationId:\s*(.+)/', $line, $m)) {
                    $operation_id = trim($m[1], "'\" ");
                }
                if (preg_match('/^\s{6}summary:\s*(.+)/', $line, $m)) {
                    $current_summary = trim($m[1], "'\" ");
                }
                if (preg_match('/^\s{6}description:\s*(.+)/', $line, $m)) {
                    $desc_value = trim($m[1]);
                    if (in_array($desc_value, ['>', '|', '|-', '>-', '|+'], true)) {
                        $collecting_description = true;
                        $current_description = '';
                    } else {
                        $current_description = trim($desc_value, "'\" ");
                        $collecting_description = false;
                    }
                }
            }
        }

        if ($current_path && $current_method) {
            $operations[] = [
                'key' => $current_path . '|' . $current_method,
                'operationId' => $operation_id,
                'snippet' => implode("\n", $buffer),
                'summary' => $current_summary,
                'description' => trim($current_description),
            ];
        }

        return $operations;
    }

    private static function normalize_manage_file_operation(array $operation) {
        if (empty($operation['operationId']) || 'manageFile' !== $operation['operationId']) {
            return $operation;
        }

        $operation['summary'] = __('Carica file nella libreria media di WordPress', 'wp-gpt-automation-pro');
        $operation['description'] = __('Supporta le azioni send-file, send-image-base-64 e send-image-base-64-in-chunk.', 'wp-gpt-automation-pro');
        $operation['snippet'] = "  /v1/manage-file/:
    post:
      operationId: manageFile
      summary: Carica file nella libreria media di WordPress
      description: >
        Supporta tre azioni: `send-file` (download da OpenAI refs),
        `send-image-base-64` (upload diretto base64) e
        `send-image-base-64-in-chunk` (upload base64 chunked con transient).
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required:
              - user_code
              - action
              properties:
                user_code:
                  type: string
                  description: Codice utente per la validazione.
                action:
                  type: string
                  description: Azione da eseguire.
                  enum:
                  - send-file
                  - send-image-base-64
                  - send-image-base-64-in-chunk
                openaiFileIdRefs:
                  type: array
                  description: Richiesto con `send-file`.
                  items:
                    type: object
                    properties:
                      id: { type: string }
                      name: { type: string }
                      mime_type: { type: string }
                      download_link: { type: string, format: uri }
                content_base64:
                  type: string
                  description: Base64 completa immagine (richiesto con `send-image-base-64`).
                filename:
                  type: string
                mime_type:
                  type: string
                  enum: [image/png, image/jpeg, image/jpg, image/webp, image/gif]
                chunk_base64:
                  type: string
                  description: Chunk base64 (richiesto con `send-image-base-64-in-chunk`).
                chunk_index:
                  type: integer
                total_chunks:
                  type: integer
                upload_id:
                  type: string
                is_last_chunk:
                  type: boolean
                title: { type: string }
                alt: { type: string }
                caption: { type: string }
                description: { type: string }
              oneOf:
              - properties: { action: { const: send-file } }
                required: [openaiFileIdRefs]
              - properties: { action: { const: send-image-base-64 } }
                required: [content_base64, filename, mime_type]
              - properties: { action: { const: send-image-base-64-in-chunk } }
                required: [filename, mime_type, chunk_base64, chunk_index, total_chunks]
      responses:
        '200':
          description: Esito upload media.
          content:
            application/json:
              schema:
                type: object
                properties:
                  action: { type: string }
                  status: { type: string, example: in_progress }
                  upload_id: { type: string }
                  received_chunk_index: { type: integer }
                  total_chunks: { type: integer }
                  files:
                    type: array
                    items:
                      type: object
                      properties:
                        id: { type: integer }
                        url: { type: string, format: uri }
                        name: { type: string }
                        openai_file_id: { type: string }
        '400':
          description: Parametri non validi o mancanti
        '410':
          description: Upload chunk non trovato o scaduto
        '500':
          description: Errore nel salvataggio dei file in WordPress";

        return $operation;
    }

    public static function render_schema_guide_page() {
        // Ottieni l'URL del sito corrente per generare lo schema corretto
        $site_url  = site_url();
        $home_url  = untrailingslashit(home_url());
        $site_host = wp_parse_url($home_url, PHP_URL_HOST);
        if (empty($site_host)) {
            $site_host = $home_url;
        }

        $api_token             = get_option('wp_gpt_api_token', '');
        $download_nonce        = wp_create_nonce('wp_gpt_fetch_update_package');
        $prompts_tools_zip_url = WP_GPT_PLUGIN_URL . 'docs/www-wp-prompts-tools.zip';
        $home_url_link         = sprintf(
            '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
            esc_url($home_url),
            esc_html($home_url)
        );

        ob_start();
        \WP_GPT_Automation_Pro\WP_GPT_Generate_Schema::output_schema_yaml();
        $schema = ob_get_clean();

        // Genera le istruzioni personalizzate
        // Le istruzioni vere. Qui prima c'era "passa alla Pro per averle":
        // chi installava la free si ritrovava un GPT senza istruzioni, cioe' un
        // GPT che non sa cosa ha in mano. Le due edizioni fanno due mestieri
        // diversi, non la stessa cosa a meta'.
        require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-generate-instructions.php';
        $generatore   = new Istruzioni_Personalizzate_GPTS();
        $instructions = $generatore->generate_instructions();

        // Carica le operazioni dal file YAML se presente
        /*
         * La fonte e' openapi.yml, generato dal registro delle action.
         *
         * Prima era docs/rotte_normalizzate_senza_duplicati.yml: un file scritto
         * a mano che nessuno aggiornava, e che nessuno sapeva fosse la fonte
         * vera dello step 6. Chi rigenerava lo schema non vedeva cambiare
         * niente in questa pagina e non poteva capire perche'. Stessa scelta
         * fatta nella Pro: un file in meno da tenere allineato a mano.
         */
        $yaml_file       = WP_GPT_PLUGIN_DIR . 'openapi.yml';
        $yaml_operations = self::load_yaml_operations($yaml_file);

        $operation_map = [];
        $operation_ids = [];

        foreach ($yaml_operations as $operation) {
            if (empty($operation['operationId'])) {
                continue;
            }

            $operation = self::normalize_manage_file_operation($operation);

            $operation_map[$operation['operationId']] = $operation;
            $operation_ids[] = $operation['operationId'];
        }

        $operation_ids = array_values(array_unique(array_filter($operation_ids)));
        $categories_config = WP_GPT_Guide_Categories::load_categories($operation_ids);

        $active_categories = [];

        foreach ($categories_config as $category) {
            if (empty($category['active'])) {
                continue;
            }

            $category_operations = [];

            if (!empty($category['operationIds']) && is_array($category['operationIds'])) {
                foreach ($category['operationIds'] as $operation_id) {
                    if (!isset($operation_map[$operation_id])) {
                        continue;
                    }

                    $operation = $operation_map[$operation_id];
                    $category_operations[] = $operation;
                }
            }

            $active_categories[] = [
                'name'       => $category['name'],
                'operations' => $category_operations,
            ];
        }

        // --- INIZIO INTEGRAZIONE HOME ASSISTANT (PREMIUM FULL) ---
        $ha_operation = [
            'operationId' => 'manageHomeAssistantDashboardAndEntities',
            'summary'     => __('Controllo Full Home Assistant (Premium Pro)', 'wp-gpt-automation-pro'),
            'description' => __('Integrazione domotica definitiva di livello Enterprise per l\'ecosistema WordPress. Questa operazione permette un controllo granulare, bidirezionale e sicuro su ogni aspetto di Home Assistant. Consente di interrogare stati in tempo reale, orchestrare automazioni complesse, gestire assistenti vocali HA Assist, catturare snapshot multimediali ad alta risoluzione dalle telecamere per analisi visiva, visionare lo storico dettagliato delle variazioni di stato dei sensori, consultare il logbook degli eventi di sistema e gestire integralmente calendari ed eventi pianificati. Progettata per massimizzare l\'autonomia decisionale dell\'agente IA in contesti smart home complessi.', 'wp-gpt-automation-pro'),
            'snippet'     => "  /manage-homeassistant-dashboard-and-entities:
    post:
      summary: Home Assistant Full Integration (Premium)
      operationId: manageHomeAssistantDashboardAndEntities
      description: >
        Punto di accesso universale per l'automazione domestica. Supporta azioni di listing paginato ('get-entities', 'get-camera-list', ecc.), 
        controllo diretto di entità tramite domain/service ('control-entity'), interazione vocale naturale ('send-command-to-assistant'), 
        multimedia evoluto con cattura e persistenza immagini su WordPress ('get-camera-screen') e analisi forense/temporale tramite 
        accesso a storico, logbook e calendari. Ogni chiamata richiede la validazione tramite Bearer Auth e 'user_code' di sicurezza.
      security:
        - bearerAuth: []
      tags:
        - Home Assistant
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [action, user_code]
              properties:
                action:
                  type: string
                  description: >
                    Specifica l'operazione di alto livello da eseguire all'interno del bridge Home Assistant. 
                    'get-entities', 'get-camera-list', 'get-script', 'get-automation', 'get-scene': recuperano elenchi strutturati e filtrati per dominio, utili per la navigazione iniziale della casa intelligente. 
                    'search': nuova azione fondamentale per individuare rapidamente dispositivi specifici tramite query testuale (es. cercare \"condizionatore\" in tutta la casa). 
                    'control-entity': invia comandi operativi diretti (richiede domain, service, entity_id e opzionalmente payload). 
                    'get-assistant-list' e 'send-command-to-assistant': abilitano l'interazione in linguaggio naturale con HA Assist. 
                    'get-camera-screen': esegue snapshot multimediali con persistenza su WordPress. 
                    'get-history', 'get-logbook', 'get-calendars', 'get-calendar-events': forniscono accesso ai dati temporali e alle pianificazioni.
                  enum: [get-entities, search, control-entity, get-shortcode, get-assistant-list, send-command-to-assistant, get-script, get-automation, get-scene, get-camera-list, get-camera-screen, get-history, get-logbook, get-calendars, get-calendar-events]
                user_code:
                  type: string
                  description: >
                    Il codice di sicurezza univoco e riservato configurato all'interno delle impostazioni globali del plugin WP-GPT Automation Pro nel pannello di controllo WordPress. 
                    Questo parametro agisce come una 'chiave di volta' per l'intera architettura di sicurezza del bridge domotico: deve essere obbligatoriamente fornito in ogni singola richiesta 
                    per validare l'autorizzazione fisica dell'agente ad agire sull'ambiente reale. Garantisce che, anche in caso di compromissione del token di sessione principale, 
                    solo chi dispone di questa chiave di secondo livello possa modificare lo stato dei dispositivi domestici, prevenendo accessi dannosi o accidentali.
                page:
                  type: integer
                  description: >
                    Identifica il numero della pagina corrente per le operazioni che restituiscono elenchi numerosi (listing). 
                    Fondamentale per le varie azioni 'get-*' come 'get-entities' o 'get-history', permette all'assistente IA di navigare 
                    tra centinaia di dispositivi o eventi in modo incrementale, evitando il caricamento di payload eccessivi che potrebbero 
                    causare timeout o errori di memoria nel processo di parsing della risposta JSON.
                limit:
                  type: integer
                  description: >
                    Definisce il numero massimo di risultati da includere in una singola risposta del server (default 10). 
                    È un parametro di controllo critico per limitare la verbosità delle risposte, specialmente quando si interrogano 
                    domini con molte entità (es. 'sensor') o quando si richiede lo storico degli stati, assicurando che l'IA 
                    riceva solo le informazioni strettamente necessarie per la sua analisi corrente.
                domain:
                  type: string
                  description: >
                    Il dominio di appartenenza dell'entità in Home Assistant (es. 'light', 'climate', 'switch', 'media_player'). 
                    Questo parametro è strettamente richiesto dall'azione 'control-entity' e serve a qualificare il tipo di dispositivo 
                    su cui si intende agire, permettendo al sistema di instradare correttamente il comando verso i servizi HA compatibili.
                service:
                  type: string
                  description: >
                    Il nome del servizio HA specifico da invocare sul dispositivo (es. 'turn_on', 'toggle', 'set_hvac_mode', 'play_media'). 
                    Utilizzato in combinazione con l'azione 'control-entity'. Definisce l'operazione fisica o logica che deve essere 
                    compiuta sull'oggetto domotico e deve essere sempre congruente con il dominio specificato nel parametro 'domain'.
                entity_id:
                  type: string
                  description: >
                    L'identificativo univoco globale del dispositivo nel sistema Home Assistant (che di solito segue il formato 'dominio.nome'). 
                    È un parametro cardine per quasi tutte le azioni operative: bersaglio per 'control-entity', sorgente per 'get-camera-screen', 
                    filtro obbligatorio per 'get-calendar-events' e filtro opzionale per 'get-history'. Assicura precisione assoluta nell'esecuzione del comando.
                text:
                  type: string
                  description: >
                    Il testo del comando vocale o la frase da processare tramite il motore di linguaggio naturale di Home Assistant Assist. 
                    Utilizzato esclusivamente con l'azione 'send-command-to-assistant'. Consente di simulare un'interazione umana 
                    con la casa (es. 'accendi tutto'), sfruttando le pipeline di conversazione configurate in HA per semplificare comandi complessi.
                agent_id:
                  type: string
                  description: >
                    Identificativo dell'agente conversazionale specifico (STT/Natural Language) da utilizzare per elaborare il comando 'text'. 
                    Opzionale nell'azione 'send-command-to-assistant' (il valore predefinito è solitamente l'agente standard di Home Assistant). 
                    Permette di scegliere tra diversi motori IA (es. Local Assist, OpenAI, ecc.) configurati sul server HA.
                timestamp:
                  type: string
                  description: >
                    Il punto di inizio temporale nel formato standard ISO8601 (es. '2023-10-01T15:00:00Z') da cui iniziare l'estrazione dei dati. 
                    Parametro obbligatorio per le azioni 'get-history' e 'get-logbook'. Definisce la finestra di osservazione per l'analisi 
                    degli eventi passati o delle variazioni di stato dei sensori domestici registrate nel database di Home Assistant.
                start:
                  type: string
                  description: >
                    Definisce la data e l'ora di inizio (formato ISO8601) per la ricerca di appuntamenti o eventi nei calendari domotici. 
                    Utilizzato nell'azione 'get-calendar-events'. Insieme al parametro 'end', permette di perimetrare esattamente 
                    il periodo di interesse (es. una giornata, una settimana) per la pianificazione automatica delle attività.
                end:
                  type: string
                  description: >
                    Definisce la data e l'ora di fine (formato ISO8601) per la ricerca di eventi nei calendari. 
                    Utilizzato nell'azione 'get-calendar-events'. Serve a chiudere la finestra temporale di interrogazione, garantendo 
                    che la risposta non includa eventi eccessivamente lontani nel tempo rispetto alla query dell'utente.
                query:
                  type: string
                  description: >
                    Parametro di ricerca testuale obbligatorio quando l'azione è impostata su 'search'. 
                    Consente di filtrare l'intero database di entità di Home Assistant (oltre 600 dispositivi) cercando corrispondenze 
                    sia nell'entity_id (es. 'light.soggiorno') che nel friendly_name (es. 'Luce Soggiorno'). 
                    È lo strumento principale per l'agente IA per risolvere ambiguità: se l'utente chiede di \"accendere l'AC in sala\", 
                    l'agente deve prima usare 'search' con query 'AC sala' o 'soggiorno' per ottenere l'ID esatto prima di impartire comandi.
                payload:
                  type: object
                  description: >
                    Contenitore flessibile di dati aggiuntivi richiesti dai servizi di Home Assistant durante l'azione 'control-entity'. 
                    Deve includere tutte le chiavi previste dalla documentazione ufficiale del servizio HA chiamato (es. service: 'set_temperature' 
                    richiede 'temperature: 25'). Esempi comuni: 'brightness_pct: 80' per le luci, 'hvac_mode: heat' per il clima, 
                    'rgb_color: [255, 100, 0]' per LED colorati. Permette di configurare parametri specifici che vanno oltre il semplice on/off, 
                    garantendo un controllo totale sulle sfumature operative di ogni singolo dispositivo domotico.
                entities:
                  type: string
                  description: >
                    Stringa contenente uno o più entity_id di Home Assistant, separati da virgole, da visualizzare all'interno del widget WordPress. 
                    Utilizzato esclusivamente con l'azione 'get-shortcode'. Questa funzionalità permette all'agente IA di generare istantaneamente 
                    un'interfaccia di monitoraggio visiva per l'utente finale, assemblando dispositivi correlati (es. 'climate.soggiorno,light.soggiorno') 
                    in uno shortcode [vwgk_ha_entities] che l'utente può visualizzare in un post o in una pagina generata dinamicamente.
                columns:
                  type: integer
                  description: >
                    Specifica il layout a griglia (numero di colonne) per la visualizzazione delle entità nello shortcode generato. 
                    Utilizzato con 'get-shortcode'. Supporta valori tipici da 1 a 4. Un valore di 1 è ideale per visualizzazioni mobile-first o 
                    sidebar strette, mentre 3 o 4 colonne sono indicati per dashboard panoramiche su desktop. Determina quanto densa e compatta 
                    sarà la rappresentazione grafica dei dispositivi scelti per l'interfaccia WordPress creata dall'agente.
      responses:
        '200':
          description: Operazione completata con successo. Il server restituisce un digest del risultato operato su Home Assistant.
          content:
            application/json:
              schema:
                type: object
                properties:
                  success: {type: boolean, description: 'Indica se la richiesta è stata recapitata e processata senza errori logici dal bridge.'}
                  entities: {type: array, description: 'Contiene l\'array delle entità e i loro stati attuali (per azioni di elenco).'}
                  data: {type: object, description: 'Rappresenta l\'oggetto dati grezzo restituito da HA (es. serie storica o lista eventi).'}
                  url: {type: string, description: 'Indirizzo URL dell\'immagine salvata permanentemente in WordPress dopo uno snapshot.'}
                  message: {type: string, description: 'Sintesi testuale dell\'esito dell\'operazione per log interno o feedback utente.'}
                  total_pages: {type: integer, description: 'Numero complessivo di pagine disponibili per la query corrente, utile per loop di navigazione.'}
        '401':
          description: >
            Autenticazione Fallita. Si verifica se il Token Bearer non è valido o se il parametro 'user_code' non corrisponde 
            alla chiave impostata nel plugin. La risposta include il codice d'errore 'unauthorized'.
          content:
            application/json:
              schema:
                type: object
                properties:
                  code: {type: string}
                  message: {type: string}
                  data: {type: object, properties: {status: {type: integer, example: 401}}}
        '500':
          description: >
            Errore Interno o del Bridge. Indica un problema di comunicazione tra WordPress e Home Assistant, oppure un fallimento 
            nell'invocazione di un servizio (es. entità non trovata o servizio inesistente). Restituisce dettagli nel parametro 'message'.
          content:
            application/json:
              schema:
                type: object
                properties:
                  code: {type: string}
                  message: {type: string}
                  data: {type: object, properties: {status: {type: integer, example: 500}}}
      tags:
        - Home Assistant",
            'key' => '/manage-homeassistant-dashboard-and-entities|post'
        ];

        // Aggiungiamo la categoria Home Assistant in cima se il plugin bridge è presente
        if (class_exists('VWGK_HA_Client')) {
            array_unshift($active_categories, [
                'name'       => '🏠 ' . __('Home Assistant Dashboard (Premium Full)', 'wp-gpt-automation-pro'),
                'operations' => [$ha_operation],
            ]);
        }
        // --- FINE INTEGRAZIONE HOME ASSISTANT ---
        $schema_paths_text = '';
        $schema_operations_map = [];
        $schema_operation_order = [];

        foreach ($active_categories as $category_data) {
            if (empty($category_data['operations'])) {
                continue;
            }

            foreach ($category_data['operations'] as $operation) {
                if (empty($operation['key'])) {
                    continue;
                }

                $operation_key = $operation['key'];

                if (!isset($schema_operations_map[$operation_key])) {
                    $schema_operations_map[$operation_key] = [
                        'operationId' => $operation['operationId'],
                        'snippet'     => isset($operation['snippet']) ? $operation['snippet'] : '',
                        'summary'     => isset($operation['summary']) ? $operation['summary'] : '',
                    ];

                    $schema_operation_order[] = $operation_key;
                    
                    if ($schema_paths_text !== '') {
                        $schema_paths_text .= "\n";
                    }
                    $schema_paths_text .= (isset($operation['snippet']) ? $operation['snippet'] : '');
                }
            }
        }

        $schema_base_url = untrailingslashit($site_url);

        $schema_base_url = untrailingslashit($site_url);

        $schema_header_lines = [
            'openapi: 3.1.0',
            'info:',
            '  version: 2.2.4',
            '  title: WordPress Automation Architect',
            '  description: API per automatizzare operazioni in WordPress utilizzando GPT.',
            '  contact:',
            '    name: Supporto',
            '    url: https://workingwithweb.it/webagency',
            'servers:',
            '  - url: ' . esc_url($schema_base_url) . '/wp-json/wp-gpt',
            'paths:',
        ];

        $schema_header_text = implode("\n", $schema_header_lines);


        $schema_snippet = $schema_header_text;

        if ('' !== $schema_paths_text) {
            $schema_snippet .= "\n" . $schema_paths_text;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Guida alla Configurazione GPTS', 'wp-gpt-automation-pro'); ?></h1>
            <p><?php esc_html_e('Segui questi semplici passaggi per configurare correttamente il tuo GPTS e utilizzarlo con il plugin WP GPT Automation Pro.', 'wp-gpt-automation-pro'); ?></p>

            <!-- Step 0 -->
            <div class="wp-gpt-box">
                <h2 class="accordion-toggle"><?php esc_html_e('Step 0: Estensione Chrome Prompt Tools', 'wp-gpt-automation-pro'); ?></h2>
                <div class="wp-gpt-content">
                    <p><?php esc_html_e('Prima di iniziare, scarica dal plugin il file ZIP dell\'estensione Chrome "www Prompts Tools" (cartella docs) e salvalo sul tuo computer.', 'wp-gpt-automation-pro'); ?></p>
                    <p>
                        <a href="<?php echo esc_url($prompts_tools_zip_url); ?>" download class="button button-primary"><?php esc_html_e('Scarica www-wp-prompts-tools.zip', 'wp-gpt-automation-pro'); ?></a>
                    </p>
                    <p><?php esc_html_e('Estrai il file ZIP in una cartella locale dedicata, senza rinominare o spostare i file interni.', 'wp-gpt-automation-pro'); ?></p>
                    <p>
                        <?php
                        echo wp_kses(
                            sprintf(
                                /* translators: %s: chrome extensions URL. */
                                __('Apri Chrome e vai all\'indirizzo %s.', 'wp-gpt-automation-pro'),
                                '<a href="chrome://extensions/">chrome://extensions/</a>'
                            ),
                            [
                                'a' => [
                                    'href' => [],
                                ],
                            ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e('Nella pagina Estensioni clicca il pulsante "Load unpacked" in alto a sinistra, poi seleziona la cartella estratta dal file ZIP.', 'wp-gpt-automation-pro'); ?></p>
                    <p class="description"><?php esc_html_e('Dopo il caricamento verifica che l\'estensione risulti attiva: se necessario abilitala con l\'interruttore dedicato.', 'wp-gpt-automation-pro'); ?></p>
                    <p><?php esc_html_e('La configurazione MCP sostituisce la creazione manuale delle azioni: una volta configurato il server MCP, potrai usare l\'app creata in ChatGPT sia nei GPTs che nelle chat normali.', 'wp-gpt-automation-pro'); ?></p>
                    <div class="wp-gpt-video-embed">
                        <iframe
                            width="560"
                            height="315"
                            src="https://www.youtube.com/embed/vau9KbgMZeQ"
                            title="<?php esc_attr_e('Video tutorial configurazione MCP', 'wp-gpt-automation-pro'); ?>"
                            frameborder="0"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            referrerpolicy="strict-origin-when-cross-origin"
                            allowfullscreen>
                        </iframe>
                    </div>
                </div>
            </div>

            <!-- Step 1 -->
            <div class="wp-gpt-box">
                <h2 class="accordion-toggle"><?php esc_html_e('Step 1: Aggiunta del Sito nel Pannello ChatGPT', 'wp-gpt-automation-pro'); ?></h2>
                <div class="wp-gpt-content">
                    <p><?php esc_html_e("Accedi a ChatGPT con un account Plus, clicca sull'icona del profilo in basso a sinistra, seleziona \"Impostazioni\" e apri la scheda \"Account\".", 'wp-gpt-automation-pro'); ?></p>
                    <p><?php esc_html_e("Nella sezione \"Profilo generatore GPT\" usa il menu a tendina \"Link\" per aggiungere l'indirizzo del tuo sito e avviare la procedura di verifica richiesta dalla piattaforma.", 'wp-gpt-automation-pro'); ?></p>
                    <p class="description"><?php esc_html_e("Immagine 1, 2 e 3: panoramica del profilo generatore GPT e dell'aggiunta del dominio.", 'wp-gpt-automation-pro'); ?></p>
                </div>
            </div>

            <!-- Step 2 -->
            <div class="wp-gpt-box">
                <h2 class="accordion-toggle"><?php esc_html_e('Step 2: Configurazione del Record TXT', 'wp-gpt-automation-pro'); ?></h2>
                <div class="wp-gpt-content">
                    <p><?php esc_html_e("Il record A collega il dominio all'indirizzo IP del server su cui è ospitato il sito, mentre il record TXT consente di inserire informazioni di verifica, come il codice fornito da ChatGPT per confermare la proprietà del dominio.", 'wp-gpt-automation-pro'); ?></p>
                    <p><?php esc_html_e("Configura questi record fuori da ChatGPT, nel pannello DNS del provider presso cui gestisci hosting o dominio: mantieni aggiornato il record A del sito e crea il record TXT con il valore ricevuto da OpenAI per completare la verifica.", 'wp-gpt-automation-pro'); ?></p>
                </div>
            </div>

            <!-- Step 3 -->
            <div class="wp-gpt-box">
                <h2 class="accordion-toggle"><?php esc_html_e('Step 3: Accesso alla Piattaforma di Creazione GPTS', 'wp-gpt-automation-pro'); ?></h2>
                <div class="wp-gpt-content">
                    <p><?php esc_html_e("Clicca sul pulsante seguente per aprire l'editor ufficiale di ChatGPT dedicato alla creazione dei GPT personalizzati.", 'wp-gpt-automation-pro'); ?></p>
                    <a href="https://chatgpt.com/gpts/editor" target="_blank" class="button button-primary"><?php esc_html_e('Vai alla Piattaforma GPTS', 'wp-gpt-automation-pro'); ?></a>
                    <p><?php esc_html_e("Quando l'editor si apre, assegna un nome breve e riconoscibile e prepara una descrizione chiara che spieghi in poche righe cosa farà il tuo GPT.", 'wp-gpt-automation-pro'); ?></p>
                    <p>
                        <?php
                        echo wp_kses(
                            sprintf(
                                /* translators: %s: current site URL. */
                                __('Se l\'editor mostra un dominio predefinito come libertaeazione.it, sostituiscilo con %s per mantenere il collegamento al tuo sito reale.', 'wp-gpt-automation-pro'),
                                $home_url_link
                            ),
                            [
                                'a' => [
                                    'href'   => [],
                                    'target' => [],
                                    'rel'    => [],
                                ],
                            ]
                        );
                        ?>
                    </p>
                    <?php
                    printf(
                        '<p>%s</p>',
                        esc_html(
                            sprintf(
                                /* translators: %s: home URL of the current site. */
                                __('Esempio pratico: "WP GPT Automation Pro" con descrizione "Automatizza la gestione dei contenuti e l\'ottimizzazione SEO su WordPress per %s".', 'wp-gpt-automation-pro'),
                                $home_url
                            )
                        )
                    );
                    ?>
                </div>
            </div>

            <!-- Step 4 -->
            <div class="wp-gpt-box">
                <h2 class="accordion-toggle"><?php esc_html_e('Step 4: Personalizzazione delle Istruzioni GPTS', 'wp-gpt-automation-pro'); ?></h2>
                <div class="wp-gpt-content">
                    <p><?php esc_html_e('Seleziona il blocco di testo riportato qui sotto e incollalo nel campo "Istruzioni" della scheda "Configura" dell\'editor GPTS.', 'wp-gpt-automation-pro'); ?></p>
                    <pre class="wp-gpt-pre"><code><?php echo esc_html($instructions); ?></code></pre>
                    <p class="description"><?php esc_html_e('Il testo è già ottimizzato per il dominio corrente: modifica solo gli elementi strettamente necessari.', 'wp-gpt-automation-pro'); ?></p>
                    <button class="button" onclick="copyCode(this.previousElementSibling)"><?php esc_html_e('Copia Istruzioni', 'wp-gpt-automation-pro'); ?></button>
                    <h3><?php esc_html_e('Documentazione Aggiuntiva', 'wp-gpt-automation-pro'); ?></h3>
                    <p><?php esc_html_e('Completa la configurazione allegando al tuo GPTS sia i documenti di supporto sia il pacchetto aggiornato del plugin.', 'wp-gpt-automation-pro'); ?></p>
                    <?php echo self::get_docs_download_list(); ?>
                    <div class="wp-gpt-download-update">
                        <button type="button"
                                class="button button-primary"
                                id="wp-gpt-download-package"
                                data-progress-text="<?php esc_attr_e('Verifica della licenza in corso…', 'wp-gpt-automation-pro'); ?>"
                                data-success-text="<?php esc_attr_e('Licenza verificata: download avviato.', 'wp-gpt-automation-pro'); ?>"
                                data-error-text="<?php esc_attr_e('Impossibile avviare il download: controlla la licenza e riprova.', 'wp-gpt-automation-pro'); ?>"
                                data-failure-text="<?php esc_attr_e('Si è verificato un errore di comunicazione con il server di licenze.', 'wp-gpt-automation-pro'); ?>">
                            <?php esc_html_e('Scarica aggiornamento', 'wp-gpt-automation-pro'); ?>
                        </button>
                        <p id="wp-gpt-download-status" class="description" aria-live="polite"></p>
                    </div>
                    <p class="description"><?php esc_html_e('Carica l\'archivio ZIP del plugin insieme ai documenti elencati nella sezione "Conoscenza" del GPTS per renderli disponibili durante le conversazioni.', 'wp-gpt-automation-pro'); ?></p>
                </div>
            </div>

            <!-- Step 5 -->
            <div class="wp-gpt-box">
                <h2 class="accordion-toggle"><?php esc_html_e('Step 5: Inserimento dell\'API Key nel Pannello di Creazione GPTS', 'wp-gpt-automation-pro'); ?></h2>
                <div class="wp-gpt-content">
                    <p><?php esc_html_e('Copia l\'API Key generata dal tuo sito WordPress e incollala nella sezione di autenticazione API del pannello di creazione GPTS senza modificarla.', 'wp-gpt-automation-pro'); ?></p>
                    <?php if ($api_token) : ?>
                        <div class="wp-gpt-token-copy">
                            <label for="wp-gpt-api-token"><strong><?php esc_html_e('Token API del plugin', 'wp-gpt-automation-pro'); ?></strong></label>
                            <div class="wp-gpt-token-copy-inner">
                                <input type="text"
                                       id="wp-gpt-api-token"
                                       class="regular-text"
                                       value="<?php echo esc_attr($api_token); ?>"
                                       readonly
                                       autocomplete="off"
                                       spellcheck="false" />
                                <button type="button"
                                        class="button"
                                        id="wp-gpt-copy-api-token"
                                        data-success-text="<?php esc_attr_e('API Key copiata negli appunti.', 'wp-gpt-automation-pro'); ?>"
                                        data-error-text="<?php esc_attr_e('Non è stato possibile copiare la chiave: copiala manualmente.', 'wp-gpt-automation-pro'); ?>"
                                        data-empty-text="<?php esc_attr_e('Nessuna API Key da copiare: genera prima il token nella pagina principale del plugin.', 'wp-gpt-automation-pro'); ?>">
                                    <?php esc_html_e('Copia API Key', 'wp-gpt-automation-pro'); ?>
                                </button>
                            </div>
                        </div>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e('Non è stata ancora generata alcuna API Key. Visita la pagina principale del plugin e crea il token prima di procedere.', 'wp-gpt-automation-pro'); ?></p>
                    <?php endif; ?>
                    <p id="wp-gpt-api-token-feedback" class="description" aria-live="polite"></p>
                    <p><?php esc_html_e('Per configurare l\'autenticazione: dalla scheda "Configura" clicca su "Crea nuova azione", apri la sezione "Autenticazione", seleziona "Chiave API" e imposta l\'opzione "Token Bearer".', 'wp-gpt-automation-pro'); ?></p>
                    <p class="description"><?php esc_html_e('Questo passaggio è fondamentale per permettere al GPT di comunicare in modo sicuro con il tuo sito WordPress.', 'wp-gpt-automation-pro'); ?></p>
                </div>
            </div>
            <!-- Step 6 -->
            <div class="wp-gpt-box">
                <h2 class="accordion-toggle"><?php esc_html_e('Step 6: Generazione Schema Personalizzato', 'wp-gpt-automation-pro'); ?></h2>
                <div class="wp-gpt-content">
                    <p><?php esc_html_e('Le categorie attive vengono gestite dalla pagina amministrativa riservata. Qui trovi soltanto le rotte approvate, organizzate in sola lettura per una consultazione rapida.', 'wp-gpt-automation-pro'); ?></p>
                    <p class="description"><?php esc_html_e('Per modificare le categorie o le operazioni attive utilizza la pagina protetta dedicata: da questo elenco puoi solo copiare gli snippet pronti all\'uso.', 'wp-gpt-automation-pro'); ?></p>

                    <?php if (!empty($active_categories)) : ?>
                        <div class="wp-gpt-schema-categories">
                            <?php foreach ($active_categories as $index => $category_data) :
                                $category_operations = isset($category_data['operations']) ? $category_data['operations'] : [];
                                $panel_id = 'wp-gpt-category-panel-' . $index;
                                $operations_count = count($category_operations);
                            ?>
                                <div class="wp-gpt-schema-category">
                                    <button type="button" class="wp-gpt-schema-category-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr($panel_id); ?>">
                                        <span class="wp-gpt-schema-category-name"><?php echo esc_html($category_data['name']); ?></span>
                                        <span class="wp-gpt-schema-category-count">
                                            <?php
                                            echo sprintf(
                                                /* translators: %d: number of operations */
                                                esc_html(_n('%d operazione', '%d operazioni', $operations_count, 'wp-gpt-automation-pro')),
                                                absint($operations_count)
                                            );
                                            ?>
                                        </span>
                                    </button>
                                    <div id="<?php echo esc_attr($panel_id); ?>" class="wp-gpt-schema-category-panel" hidden>
                                        <?php if ($category_operations) : ?>
                                            <?php foreach ($category_operations as $operation) :
                                                $summary = isset($operation['summary']) && '' !== $operation['summary'] ? $operation['summary'] : '';
                                                $description = isset($operation['description']) && '' !== $operation['description'] ? $operation['description'] : '';
                                                $description_text = $description ? $description : ($summary ? $summary : __('Nessuna descrizione disponibile per questa rotta.', 'wp-gpt-automation-pro'));
                                            ?>
                                                <div class="wp-gpt-route-card">
                                                    <div class="wp-gpt-route-header">
                                                        <div class="wp-gpt-route-info">
                                                            <span class="wp-gpt-route-operation"><?php echo esc_html($operation['operationId']); ?></span>
                                                            <?php if ($summary) : ?>
                                                                <p class="wp-gpt-route-summary"><?php echo esc_html($summary); ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                        <button type="button" class="wp-gpt-route-toggle" aria-expanded="false">
                                                            <span class="screen-reader-text"><?php esc_html_e('Mostra descrizione della rotta', 'wp-gpt-automation-pro'); ?></span>
                                                            <span class="wp-gpt-route-icon" aria-hidden="true"></span>
                                                        </button>
                                                    </div>
                                                    <div class="wp-gpt-route-body" hidden>
                                                        <p><?php echo nl2br(esc_html($description_text)); ?></p>
                                                        <pre class="wp-gpt-route-snippet"><code><?php echo esc_html($operation['snippet']); ?></code></pre>
                                                        <button type="button"
                                                                class="button wp-gpt-route-copy-snippet"
                                                                data-default-label="<?php esc_attr_e('Copia snippet della rotta', 'wp-gpt-automation-pro'); ?>"
                                                                data-copied-label="<?php esc_attr_e('Snippet copiato!', 'wp-gpt-automation-pro'); ?>"
                                                                data-success-text="<?php esc_attr_e('Snippet della rotta copiato negli appunti.', 'wp-gpt-automation-pro'); ?>"
                                                                data-error-text="<?php esc_attr_e('Impossibile copiare lo snippet della rotta.', 'wp-gpt-automation-pro'); ?>">
                                                            <?php esc_html_e('Copia snippet della rotta', 'wp-gpt-automation-pro'); ?>
                                                        </button>
                                                        <p class="wp-gpt-route-feedback" aria-live="polite"></p>
                                                    </div>
                                                    <div class="wp-gpt-route-actions">
                                                        <?php
                                                        $checkbox_id = 'wp-gpt-route-checkbox-' . sanitize_title($operation['operationId'] . '-' . $operation['key']);
                                                        ?>
                                                        <label class="wp-gpt-route-select" for="<?php echo esc_attr($checkbox_id); ?>">
                                                            <input type="checkbox"
                                                                   class="wp-gpt-route-checkbox"
                                                                   id="<?php echo esc_attr($checkbox_id); ?>"
                                                                   data-operation-key="<?php echo esc_attr($operation['key']); ?>" />
                                                            <span><?php esc_html_e('Includi nello schema', 'wp-gpt-automation-pro'); ?></span>
                                                        </label>
                                                        <button type="button" class="button wp-gpt-route-scroll">
                                                            <?php esc_html_e('Vai ai pulsanti di copia', 'wp-gpt-automation-pro'); ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <p class="wp-gpt-empty-category"><?php esc_html_e('Nessuna operazione è stata associata a questa categoria.', 'wp-gpt-automation-pro'); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <p class="wp-gpt-empty-category"><?php esc_html_e('Non ci sono categorie attive al momento.', 'wp-gpt-automation-pro'); ?></p>
                    <?php endif; ?>

                    <pre class="wp-gpt-pre"><code id="wp-gpt-schema-snippet"><?php echo esc_html($schema_snippet); ?></code></pre>
                    <div class="wp-gpt-schema-actions" id="wp-gpt-schema-actions">
                        <button type="button" class="button" id="wp-gpt-copy-schema" data-target="#wp-gpt-schema-snippet" onclick="copyCode(this)"><?php esc_html_e('Copia schema completo', 'wp-gpt-automation-pro'); ?></button>
                        <button type="button" class="button" id="wp-gpt-copy-paths"<?php echo '' === $schema_paths_text ? ' disabled' : ''; ?>><?php esc_html_e('Copia solo le rotte', 'wp-gpt-automation-pro'); ?></button>
                    </div>
                    <div class="wp-gpt-route-copy-buttons" id="wp-gpt-route-copy-buttons" aria-live="polite" hidden></div>
                </div>
            </div>

            <!-- Step 7 -->
            <div class="wp-gpt-box">
                <h2 class="accordion-toggle"><?php esc_html_e('Step 7: Configurazione della Privacy Policy', 'wp-gpt-automation-pro'); ?></h2>
                <div class="wp-gpt-content">
                    <p><?php esc_html_e('Crea sul tuo sito una pagina dedicata alla Privacy Policy, rendila pubblica e copia il relativo link.', 'wp-gpt-automation-pro'); ?></p>
                    <p><?php esc_html_e("Dopo aver inserito lo schema, aggiungi l'URL della Privacy Policy nel campo richiesto in fondo alla tab \"Schema\", subito sotto l'elenco delle azioni.", 'wp-gpt-automation-pro'); ?></p>
                </div>
            </div>

            <!-- Step 8 -->
            <div class="wp-gpt-box">
                <h2 class="accordion-toggle"><?php esc_html_e('Step 8: Creazione e Pubblicazione del GPTS', 'wp-gpt-automation-pro'); ?></h2>
                <div class="wp-gpt-content">
                    <p><?php esc_html_e('In alto a destra trovi il pulsante "Crea": usalo per salvare il tuo GPT. Dopo la prima pubblicazione il pulsante diventa "Aggiorna" per memorizzare le modifiche successive.', 'wp-gpt-automation-pro'); ?></p>
                    <p><?php esc_html_e('Accanto è presente il pulsante "Condividi": imposta la visibilità su "Solo io" oppure, al massimo, su "Chiunque abbia il link". Non pubblicare il GPT nello store pubblico.', 'wp-gpt-automation-pro'); ?></p>
                </div>
            </div>

            <h2><?php esc_html_e('Avvertenze', 'wp-gpt-automation-pro'); ?></h2>
            <ul>
                <li><?php esc_html_e('Se il dominio non è verificato, il GPTS non può essere salvato, a meno che non sia limitato al proprio account.', 'wp-gpt-automation-pro'); ?></li>
                <li><?php esc_html_e('Se manca la Privacy Policy, non è possibile pubblicare il GPTS.', 'wp-gpt-automation-pro'); ?></li>
                <li><?php esc_html_e('Se l\'API Key non viene generata e aggiunta, le richieste falliranno.', 'wp-gpt-automation-pro'); ?></li>
                <li><?php esc_html_e('Se il GPTS non ha titolo e descrizione, non può essere salvato.', 'wp-gpt-automation-pro'); ?></li>
            </ul>
        </div>

<script>
    function copyToClipboard(text, options) {
        options = options || {};

        var emptyMessage = options.emptyMessage || 'Nessun contenuto da copiare.';
        var unsupportedMessage = options.unsupportedMessage || 'Errore nella copia del codice: API degli appunti non disponibile.';
        var defaultSuccessMessage = options.successMessage || 'Codice copiato!';
        var defaultErrorMessage = options.errorMessage;

        if (!text) {
            if (typeof options.onError === 'function') {
                options.onError(new Error('empty'));
            } else {
                alert(emptyMessage);
            }
            return;
        }

        if (!navigator.clipboard || !navigator.clipboard.writeText) {
            if (typeof options.onError === 'function') {
                options.onError(new Error('unsupported'));
            } else {
                alert(unsupportedMessage);
            }
            return;
        }

        navigator.clipboard.writeText(text).then(function() {
            if (typeof options.onSuccess === 'function') {
                options.onSuccess();
            } else {
                alert(defaultSuccessMessage);
            }
        }).catch(function(err) {
            if (typeof options.onError === 'function') {
                options.onError(err);
            } else if (defaultErrorMessage) {
                alert(defaultErrorMessage);
            } else {
                alert('Errore nella copia del codice: ' + err);
            }
        });
    }

    function copyCode(element) {
        if (!element) {
            return;
        }

        var targetSelector = (typeof element.getAttribute === 'function') ? element.getAttribute('data-target') : null;
        var codeElement = null;

        if (targetSelector) {
            codeElement = document.querySelector(targetSelector);
        }

        if (!codeElement && element.tagName && element.tagName.toLowerCase() === 'pre') {
            codeElement = element.querySelector('code');
        }

        if (!codeElement) {
            var container = element.closest ? element.closest('.wp-gpt-box') : null;
            if (container) {
                codeElement = container.querySelector('.wp-gpt-pre code') || container.querySelector('code');
            }
        }

        if (!codeElement) {
            return;
        }

        copyToClipboard(codeElement.textContent.trim());
    }

    function copyRouteSnippet(button) {
        if (!button) {
            return;
        }

        var body = button.closest('.wp-gpt-route-body');
        if (!body) {
            return;
        }

        var codeElement = body.querySelector('.wp-gpt-route-snippet code');
        if (!codeElement) {
            return;
        }

        var feedback = body.querySelector('.wp-gpt-route-feedback');
        if (feedback) {
            feedback.textContent = '';
        }

        var defaultLabel = button.getAttribute('data-default-label') || button.textContent;
        var copiedLabel = button.getAttribute('data-copied-label');
        var successMessage = button.getAttribute('data-success-text');
        var errorMessage = button.getAttribute('data-error-text');

        copyToClipboard(codeElement.textContent, {
            emptyMessage: errorMessage || undefined,
            onSuccess: function() {
                if (copiedLabel) {
                    button.textContent = copiedLabel;
                    window.setTimeout(function() {
                        button.textContent = defaultLabel;
                    }, 2000);
                }

                if (feedback && successMessage) {
                    feedback.textContent = successMessage;
                } else if (!feedback && successMessage) {
                    alert(successMessage);
                }
            },
            onError: function(err) {
                if (feedback && errorMessage) {
                    feedback.textContent = errorMessage;
                } else if (errorMessage) {
                    alert(errorMessage);
                } else {
                    alert('Errore nella copia del codice: ' + err);
                }
            }
        });
    }

    var wpGptFetchUpdateNonce = <?php echo wp_json_encode($download_nonce); ?>;
    var wpGptSchemaBase = <?php echo wp_json_encode($schema_header_text); ?>;
    var wpGptSchemaOperations = <?php echo wp_json_encode($schema_operations_map); ?>;
    var wpGptSchemaOrder = <?php echo wp_json_encode($schema_operation_order); ?>;
    var wpGptSchemaPathsText = <?php echo wp_json_encode($schema_paths_text); ?>;
    var wpGptCopyRouteLabel = <?php echo wp_json_encode(__('Copia %s', 'wp-gpt-automation-pro')); ?>;

    function copySchemaPaths() {
        if (!wpGptSchemaPathsText) {
            alert('Non ci sono rotte disponibili da copiare.');
            return;
        }

        copyToClipboard(wpGptSchemaPathsText);
    }

        document.addEventListener('DOMContentLoaded', function() {
            // Aggiungi funzionalità di accordion a tutte le sezioni con class="wp-gpt-box"
            const accordions = document.querySelectorAll('.wp-gpt-box');
            accordions.forEach(accordion => {
                const heading = accordion.querySelector('h2');
                const content = heading ? heading.nextElementSibling : null;

                if (!heading || !content) {
                    return;
                }

                heading.addEventListener('click', function() {
                    heading.classList.toggle('active');
                    content.classList.toggle('active');
                    updateSchemaPreview();
                });
            });

            const routeToggles = document.querySelectorAll('.wp-gpt-route-toggle');
            routeToggles.forEach(function(button) {
                button.addEventListener('click', function() {
                    const card = this.closest('.wp-gpt-route-card');
                    if (!card) {
                        return;
                    }
                    const body = card.querySelector('.wp-gpt-route-body');
                    const isExpanded = this.getAttribute('aria-expanded') === 'true';
                    this.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                    card.classList.toggle('open', !isExpanded);
                    if (body) {
                        if (isExpanded) {
                            body.setAttribute('hidden', 'hidden');
                        } else {
                            body.removeAttribute('hidden');
                        }
                    }
                });
            });

            const routeCopyButtons = document.querySelectorAll('.wp-gpt-route-copy-snippet');
            routeCopyButtons.forEach(function(button) {
                if (!button.getAttribute('data-default-label')) {
                    button.setAttribute('data-default-label', button.textContent.trim());
                }

                button.addEventListener('click', function() {
                    copyRouteSnippet(button);
                });
            });

            const copyPathsButton = document.getElementById('wp-gpt-copy-paths');
            const routeCopyButtonsContainer = document.getElementById('wp-gpt-route-copy-buttons');
            if (copyPathsButton) {
                copyPathsButton.addEventListener('click', copySchemaPaths);
            }

            const schemaCodeElement = document.getElementById('wp-gpt-schema-snippet');
            if (schemaCodeElement && !schemaCodeElement.hasAttribute('data-base-schema')) {
                const initialSchemaContent = schemaCodeElement.textContent ? schemaCodeElement.textContent.replace(/\r\n/g, '\n') : '';
                schemaCodeElement.setAttribute('data-base-schema', initialSchemaContent);
            }
            const routeCheckboxes = document.querySelectorAll('.wp-gpt-route-checkbox');
            const checkboxMap = {};
            let schemaHighlightTimeout = null;
            let schemaActions = null;
            const copyRouteLabelTemplate = (typeof wpGptCopyRouteLabel === 'string' && wpGptCopyRouteLabel) ? wpGptCopyRouteLabel : 'Copia %s';

            let schemaOperations = (wpGptSchemaOperations && typeof wpGptSchemaOperations === 'object') ? wpGptSchemaOperations : {};
            if (schemaOperations !== wpGptSchemaOperations) {
                wpGptSchemaOperations = schemaOperations;
            }

            const schemaOrder = Array.isArray(wpGptSchemaOrder) ? wpGptSchemaOrder : [];

            let schemaBaseText = '';
            if (typeof wpGptSchemaBase === 'string') {
                schemaBaseText = wpGptSchemaBase.replace(/\r\n/g, '\n');
                if (schemaBaseText !== wpGptSchemaBase) {
                    wpGptSchemaBase = schemaBaseText;
                }
            }

            if (!schemaBaseText && schemaCodeElement) {
                const storedBaseSchema = schemaCodeElement.getAttribute('data-base-schema');
                if (storedBaseSchema !== null) {
                    schemaBaseText = storedBaseSchema;
                }
            }

            if (schemaCodeElement && schemaBaseText && schemaCodeElement.getAttribute('data-base-schema') !== schemaBaseText) {
                schemaCodeElement.setAttribute('data-base-schema', schemaBaseText);
            }

            function normalizeOperationSnippet(snippet) {
                if (typeof snippet !== 'string') {
                    return '';
                }

                let normalized = snippet.replace(/\r\n/g, '\n');
                normalized = normalized.replace(/[ \t]+$/gm, '');
                normalized = normalized.replace(/^\n+|\n+$/g, '');

                normalized = normalized.replace(/(^|\n)(\s*path:)\s*(\S.*)/g, function(_, prefix, label, value) {
                    const indentation = label.slice(0, -5);
                    const valueWithoutLeadingWhitespace = value.replace(/^\s*/, '');
                    return prefix + label + '\n' + indentation + valueWithoutLeadingWhitespace;
                });

                return normalized;
            }

            function ensureNormalizedOperation(operationKey, operationData) {
                if (!operationData || typeof operationKey !== 'string') {
                    return operationData || null;
                }

                const snippet = operationData.snippet;
                if (!snippet) {
                    return operationData;
                }

                const normalizedSnippet = normalizeOperationSnippet(snippet);
                if (!normalizedSnippet) {
                    const sanitizedData = Object.assign({}, operationData, { snippet: '' });
                    schemaOperations[operationKey] = sanitizedData;
                    return sanitizedData;
                }

                if (normalizedSnippet === snippet) {
                    return operationData;
                }

                const normalizedData = Object.assign({}, operationData, { snippet: normalizedSnippet });
                schemaOperations[operationKey] = normalizedData;

                return normalizedData;
            }

            Object.keys(schemaOperations).forEach(function(operationKey) {
                const operationData = schemaOperations[operationKey];
                if (!operationData) {
                    return;
                }
                const normalizedData = ensureNormalizedOperation(operationKey, operationData);
                if (normalizedData && normalizedData !== operationData) {
                    schemaOperations[operationKey] = normalizedData;
                }
            });

            function normalizeStepHeadingText(text) {
                if (typeof text !== 'string') {
                    return '';
                }

                return text.replace(/\s+/g, ' ').trim();
            }

            function findActiveStep6Box() {
                const stepBoxes = document.querySelectorAll('.wp-gpt-box');
                for (let index = 0; index < stepBoxes.length; index++) {
                    const box = stepBoxes[index];
                    if (!box) {
                        continue;
                    }

                    const heading = box.querySelector('h2.accordion-toggle');
                    if (!heading || heading.parentElement !== box) {
                        continue;
                    }

                    const headingText = normalizeStepHeadingText(heading.textContent);
                    if (headingText !== 'Step 6: Generazione Schema Personalizzato') {
                        continue;
                    }

                    if (!heading.classList.contains('active')) {
                        return null;
                    }

                    return {
                        box: box,
                        heading: heading,
                    };
                }

                return null;
            }

            function collectStep6SelectedOperations() {
                const step6Data = findActiveStep6Box();
                if (!step6Data || !step6Data.box) {
                    return {
                        isActive: false,
                        operations: [],
                        keys: [],
                    };
                }

                const categoriesWrappers = step6Data.box.querySelectorAll('.wp-gpt-schema-categories');
                if (!categoriesWrappers.length) {
                    return {
                        isActive: true,
                        operations: [],
                        keys: [],
                    };
                }

                const operations = [];
                const keys = [];
                const seenKeys = new Set();
                let fallbackIndex = 0;

                categoriesWrappers.forEach(function(wrapper) {
                    const categories = wrapper.querySelectorAll('.wp-gpt-schema-category');

                    const processCards = function(cards) {
                        cards.forEach(function(card) {
                            if (!card) {
                                return;
                            }

                            const checkbox = card.querySelector('.wp-gpt-route-checkbox');
                            if (!checkbox || !checkbox.checked) {
                                return;
                            }

                            const snippetElement = card.querySelector('.wp-gpt-route-snippet code');
                            if (!snippetElement || !snippetElement.textContent) {
                                return;
                            }

                            const rawSnippet = snippetElement.textContent;
                            const normalizedSnippet = normalizeOperationSnippet(rawSnippet);
                            if (!normalizedSnippet) {
                                return;
                            }

                            const operationKey = checkbox.getAttribute('data-operation-key') || '';
                            const labelElement = card.querySelector('.wp-gpt-route-operation');
                            const summaryElement = card.querySelector('.wp-gpt-route-summary');
                            const operationId = labelElement ? labelElement.textContent.trim() : operationKey;
                            const summary = summaryElement ? summaryElement.textContent.trim() : '';

                            let mapKey = operationKey;
                            if (!mapKey) {
                                mapKey = 'inline-operation-' + fallbackIndex;
                                fallbackIndex += 1;
                            }

                            if (seenKeys.has(mapKey)) {
                                return;
                            }

                            seenKeys.add(mapKey);
                            keys.push(mapKey);

                            const operationData = {
                                key: mapKey,
                                originalKey: operationKey,
                                operationId: operationId || mapKey,
                                snippet: normalizedSnippet,
                                summary: summary,
                            };

                            operations.push(operationData);

                            if (operationKey) {
                                schemaOperations[operationKey] = {
                                    operationId: operationData.operationId,
                                    snippet: normalizedSnippet,
                                    summary: summary,
                                };
                            }
                        });
                    };

                    if (!categories.length) {
                        processCards(wrapper.querySelectorAll('.wp-gpt-route-card'));
                        return;
                    }

                    categories.forEach(function(category) {
                        const cards = category.querySelectorAll('.wp-gpt-route-card');
                        processCards(cards);
                    });
                });

                return {
                    isActive: true,
                    operations: operations,
                    keys: keys,
                };
            }

            routeCheckboxes.forEach(function(checkbox) {
                const key = checkbox.getAttribute('data-operation-key');
                if (key) {
                    checkboxMap[key] = checkbox;
                }
            });

            function getOperationData(operationKey) {
                if (!operationKey) {
                    return null;
                }

                const hasOperation = Object.prototype.hasOwnProperty.call(schemaOperations, operationKey);
                if (hasOperation && schemaOperations[operationKey]) {
                    const normalized = ensureNormalizedOperation(operationKey, schemaOperations[operationKey]);
                    if (normalized) {
                        schemaOperations[operationKey] = normalized;
                        return normalized;
                    }
                    return schemaOperations[operationKey];
                }

                const checkbox = checkboxMap[operationKey];
                if (!checkbox) {
                    return hasOperation ? schemaOperations[operationKey] : null;
                }

                const card = checkbox.closest('.wp-gpt-route-card');
                if (!card) {
                    return hasOperation ? schemaOperations[operationKey] : null;
                }

                const snippetElement = card.querySelector('.wp-gpt-route-snippet code');
                const snippetText = snippetElement && snippetElement.textContent ? snippetElement.textContent : '';
                if (!snippetText) {
                    return hasOperation ? schemaOperations[operationKey] : null;
                }

                const operationLabel = card.querySelector('.wp-gpt-route-operation');
                const summaryElement = card.querySelector('.wp-gpt-route-summary');

                const fallbackData = {
                    operationId: operationLabel ? operationLabel.textContent.trim() : operationKey,
                    snippet: snippetText,
                    summary: summaryElement ? summaryElement.textContent.trim() : '',
                };

                const normalizedFallback = ensureNormalizedOperation(operationKey, fallbackData);
                const finalFallback = normalizedFallback || fallbackData;
                schemaOperations[operationKey] = finalFallback;

                return finalFallback;
            }

            function getSelectedOperationKeys() {
                const selectedKeys = [];

                if (schemaOrder.length) {
                    schemaOrder.forEach(function(operationKey) {
                        const checkbox = checkboxMap[operationKey];
                        if (checkbox && checkbox.checked) {
                            selectedKeys.push(operationKey);
                        }
                    });
                } else {
                    routeCheckboxes.forEach(function(checkbox) {
                        if (!checkbox.checked) {
                            return;
                        }

                        const operationKey = checkbox.getAttribute('data-operation-key');
                        if (operationKey) {
                            selectedKeys.push(operationKey);
                        }
                    });
                }

                return selectedKeys;
            }

            function renderRouteCopyButtons(selectedKeys, operationOverrides) {
                if (!routeCopyButtonsContainer) {
                    return;
                }

                routeCopyButtonsContainer.innerHTML = '';

                if (!Array.isArray(selectedKeys) || !selectedKeys.length) {
                    routeCopyButtonsContainer.setAttribute('hidden', 'hidden');
                    return;
                }

                routeCopyButtonsContainer.removeAttribute('hidden');

                let appendedButtons = 0;

                selectedKeys.forEach(function(operationKey) {
                    let operationData = null;
                    if (operationOverrides && Object.prototype.hasOwnProperty.call(operationOverrides, operationKey)) {
                        operationData = operationOverrides[operationKey];
                    } else {
                        operationData = getOperationData(operationKey);
                    }
                    if (!operationData || !operationData.snippet) {
                        return;
                    }

                    const label = operationData.operationId ? operationData.operationId : operationKey;
                    const buttonLabel = copyRouteLabelTemplate.replace('%s', label);

                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'button wp-gpt-copy-route';
                    button.textContent = buttonLabel;
                    button.setAttribute('aria-label', buttonLabel);
                    button.setAttribute('data-snippet', operationData.snippet);
                    if (operationData.summary) {
                        button.title = operationData.summary;
                    }

                    button.addEventListener('click', function() {
                        copyToClipboard(operationData.snippet);
                    });

                    routeCopyButtonsContainer.appendChild(button);
                    appendedButtons += 1;
                });

                if (!appendedButtons) {
                    routeCopyButtonsContainer.setAttribute('hidden', 'hidden');
                }
            }

            function updateSchemaPreview() {
                if (!schemaCodeElement) {
                    return;
                }

                const step6Selection = collectStep6SelectedOperations();
                let selectedOperations = [];
                let selectedKeys = [];
                let operationOverrides = null;

                if (step6Selection.isActive) {
                    selectedOperations = step6Selection.operations;
                    selectedKeys = step6Selection.keys;
                    operationOverrides = {};

                    selectedOperations.forEach(function(operation) {
                        if (!operation || !operation.key) {
                            return;
                        }

                        if (!Object.prototype.hasOwnProperty.call(operationOverrides, operation.key)) {
                            operationOverrides[operation.key] = {
                                operationId: operation.operationId,
                                snippet: operation.snippet,
                                summary: operation.summary,
                            };
                        }
                    });
                } else {
                    selectedKeys = getSelectedOperationKeys();
                    selectedOperations = [];

                    selectedKeys.forEach(function(operationKey) {
                        const operationData = getOperationData(operationKey);
                        if (!operationData || !operationData.snippet) {
                            return;
                        }

                        const normalizedSnippet = normalizeOperationSnippet(operationData.snippet);
                        const snippetToUse = normalizedSnippet || operationData.snippet;

                        if (normalizedSnippet && normalizedSnippet !== operationData.snippet) {
                            schemaOperations[operationKey] = Object.assign({}, operationData, { snippet: snippetToUse });
                        }

                        selectedOperations.push(Object.assign({}, operationData, {
                            key: operationKey,
                            snippet: snippetToUse,
                        }));
                    });
                }

                const selectedSnippets = selectedOperations
                    .map(function(operation) {
                        return operation.snippet;
                    })
                    .filter(function(snippet) {
                        return typeof snippet === 'string' && snippet;
                    });

                const pathsText = selectedSnippets.length ? selectedSnippets.join("\n") : '';
                const baseFromAttribute = schemaCodeElement.getAttribute('data-base-schema') || '';
                const baseForOutput = schemaBaseText || baseFromAttribute;
                const baseWithTrailingNewline = baseForOutput && !baseForOutput.endsWith('\n') ? baseForOutput + '\n' : baseForOutput;
                const fullSchema = pathsText ? baseWithTrailingNewline + pathsText : baseForOutput;

                schemaCodeElement.textContent = fullSchema;
                wpGptSchemaPathsText = pathsText;

                if (copyPathsButton) {
                    copyPathsButton.disabled = !pathsText;
                }

                renderRouteCopyButtons(selectedKeys, operationOverrides);

                if (schemaActions) {
                    schemaActions.classList.add('wp-gpt-schema-highlight');
                    if (schemaHighlightTimeout) {
                        window.clearTimeout(schemaHighlightTimeout);
                    }
                    schemaHighlightTimeout = window.setTimeout(function() {
                        schemaActions.classList.remove('wp-gpt-schema-highlight');
                    }, 1500);
                }
            }

            if (routeCheckboxes.length) {
                routeCheckboxes.forEach(function(checkbox) {
                    checkbox.addEventListener('change', updateSchemaPreview);
                });
            }

            updateSchemaPreview();

            const categoryToggles = document.querySelectorAll('.wp-gpt-schema-category-toggle');
            categoryToggles.forEach(function(toggle) {
                toggle.addEventListener('click', function() {
                    const expanded = this.getAttribute('aria-expanded') === 'true';
                    this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    const panelId = this.getAttribute('aria-controls');
                    if (!panelId) {
                        return;
                    }
                    const panel = document.getElementById(panelId);
                    if (!panel) {
                        return;
                    }
                    if (expanded) {
                        panel.setAttribute('hidden', 'hidden');
                    } else {
                        panel.removeAttribute('hidden');
                    }
                });
            });

            schemaActions = document.getElementById('wp-gpt-schema-actions');
            const routeScrollButtons = document.querySelectorAll('.wp-gpt-route-scroll');
            routeScrollButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    if (!schemaActions) {
                        return;
                    }

                    schemaActions.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    const primaryCopyButton = schemaActions.querySelector('button:not(:disabled)');
                    if (primaryCopyButton && typeof primaryCopyButton.focus === 'function') {
                        try {
                            primaryCopyButton.focus({ preventScroll: true });
                        } catch (focusError) {
                            primaryCopyButton.focus();
                        }
                    }
                    schemaActions.classList.add('wp-gpt-schema-highlight');
                    window.setTimeout(function() {
                        schemaActions.classList.remove('wp-gpt-schema-highlight');
                    }, 1200);
                });
            });

            const downloadButton = document.getElementById('wp-gpt-download-package');
            if (downloadButton && typeof ajaxurl !== 'undefined') {
                const statusElement = document.getElementById('wp-gpt-download-status');
                downloadButton.addEventListener('click', function() {
                    if (!wpGptFetchUpdateNonce) {
                        return;
                    }

                    if (statusElement) {
                        statusElement.textContent = downloadButton.getAttribute('data-progress-text');
                    }

                    downloadButton.disabled = true;

                    var params = new window.URLSearchParams();
                    params.append('action', 'wp_gpt_fetch_update_package');
                    params.append('nonce', wpGptFetchUpdateNonce);

                    fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: params.toString()
                    })
                        .then(function(response) {
                            return response.json();
                        })
                        .then(function(data) {
                            if (data && data.success && data.data && data.data.package) {
                                if (statusElement) {
                                    statusElement.textContent = data.data.message || downloadButton.getAttribute('data-success-text');
                                }
                                window.open(data.data.package, '_blank');
                            } else {
                                var message = data && data.data && data.data.message ? data.data.message : downloadButton.getAttribute('data-error-text');
                                if (statusElement) {
                                    statusElement.textContent = message;
                                } else {
                                    alert(message);
                                }
                            }
                        })
                        .catch(function() {
                            var fallbackMessage = downloadButton.getAttribute('data-failure-text');
                            if (statusElement) {
                                statusElement.textContent = fallbackMessage;
                            } else {
                                alert(fallbackMessage);
                            }
                        })
                        .finally(function() {
                            downloadButton.disabled = false;
                        });
                });
            }

            const apiTokenButton = document.getElementById('wp-gpt-copy-api-token');
            if (apiTokenButton) {
                apiTokenButton.addEventListener('click', function() {
                    const tokenInput = document.getElementById('wp-gpt-api-token');
                    const feedback = document.getElementById('wp-gpt-api-token-feedback');

                    if (feedback) {
                        feedback.textContent = '';
                    }

                    if (!tokenInput || !tokenInput.value) {
                        const emptyMessage = apiTokenButton.getAttribute('data-empty-text');
                        if (emptyMessage) {
                            if (feedback) {
                                feedback.textContent = emptyMessage;
                            } else {
                                alert(emptyMessage);
                            }
                        }
                        return;
                    }

                    if (!navigator.clipboard || !navigator.clipboard.writeText) {
                        const unsupportedMessage = apiTokenButton.getAttribute('data-error-text');
                        if (feedback) {
                            feedback.textContent = unsupportedMessage;
                        } else {
                            alert(unsupportedMessage);
                        }
                        return;
                    }

                    navigator.clipboard.writeText(tokenInput.value)
                        .then(function() {
                            const successMessage = apiTokenButton.getAttribute('data-success-text');
                            if (feedback) {
                                feedback.textContent = successMessage;
                            } else {
                                alert(successMessage);
                            }
                        })
                        .catch(function() {
                            const errorMessage = apiTokenButton.getAttribute('data-error-text');
                            if (feedback) {
                                feedback.textContent = errorMessage;
                            } else {
                                alert(errorMessage);
                            }
                        });
                });
            }
    });
</script>

<style>
    .wp-gpt-box {
        border: 1px solid #e5e7eb;
        padding: 0;
        margin-bottom: 24px;
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 15px 35px rgba(15, 23, 42, 0.08);
        overflow: hidden;
    }
    .wp-gpt-box .accordion-toggle {
        margin: 0;
        padding: 18px 24px;
        background: var(--wp-gpt-primary-gradient);
        color: #ffffff;
        font-size: 18px;
        letter-spacing: 0.02em;
        cursor: pointer;
        position: relative;
    }
    .wp-gpt-box .accordion-toggle::after {
        content: '\25BC';
        position: absolute;
        right: 24px;
        top: 50%;
        transform: translateY(-50%);
        transition: transform 0.3s ease;
    }
    .wp-gpt-box .accordion-toggle.active::after {
        transform: translateY(-50%) rotate(180deg);
    }
    .wp-gpt-content {
        display: none; /* Nascondi il contenuto all'inizio */
        padding: 24px;
        background: #f8fafc;
    }
    .wp-gpt-content.active {
        display: block; /* Mostra il contenuto quando attivo */
    }
    .wp-gpt-pre {
        background: #1f2937;
        color: #f9fafb;
        padding: 16px;
        border-radius: 12px;
        border: none;
        overflow-x: auto;
        font-family: 'Fira Code', monospace;
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.05);
    }
    .button {
        margin-top: 10px;
        border-radius: 999px;
        padding: 10px 24px;
        font-weight: 600;
    }
    .wp-gpt-docs {
        margin: 12px 0 0 24px;
        list-style: disc;
    }
    .wp-gpt-download-update {
        margin-top: 12px;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    .wp-gpt-token-copy {
        margin-top: 18px;
    }
    .wp-gpt-token-copy label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
    }
    .wp-gpt-token-copy-inner {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .wp-gpt-token-copy-inner input {
        max-width: 360px;
    }
    .wp-gpt-schema-form {
        display: grid;
        gap: 18px;
        margin: 18px 0;
    }
    .wp-gpt-route-card {
        background: linear-gradient(135deg, rgba(var(--wp-gpt-primary-color-start-rgb), 0.08), rgba(var(--wp-gpt-primary-color-end-rgb), 0.05));
        border: 1px solid rgba(99, 102, 241, 0.25);
        border-radius: 18px;
        padding: 18px 22px;
        box-shadow: 0 18px 30px rgba(76, 29, 149, 0.12);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .wp-gpt-route-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 22px 38px rgba(var(--wp-gpt-primary-color-start-rgb), 0.18);
    }
    .wp-gpt-route-card.open {
        transform: translateY(-3px);
        box-shadow: 0 25px 45px rgba(55, 48, 163, 0.2);
    }
    .wp-gpt-route-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
    }
    .wp-gpt-route-info {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .wp-gpt-route-label {
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 600;
        color: #1e1b4b;
        font-size: 15px;
    }
    .wp-gpt-route-label input[type="checkbox"] {
        width: 20px;
        height: 20px;
        accent-color: var(--wp-gpt-primary-color-start);
        border-radius: 6px;
        box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.9);
    }
    .wp-gpt-route-operation {
        font-size: 16px;
        letter-spacing: 0.01em;
    }
    .wp-gpt-route-summary {
        margin: 0;
        color: #4c1d95;
        font-size: 13px;
        font-weight: 500;
        background: rgba(255, 255, 255, 0.6);
        padding: 6px 12px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        box-shadow: inset 0 0 0 1px rgba(99, 102, 241, 0.2);
    }
    .wp-gpt-route-summary::before {
        content: '\2728';
        font-size: 12px;
    }
    .wp-gpt-route-toggle {
        background: var(--wp-gpt-primary-gradient);
        border: none;
        width: 42px;
        height: 42px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        cursor: pointer;
        box-shadow: 0 10px 25px rgba(76, 29, 149, 0.4);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        position: relative;
        color: #fff;
    }
    .wp-gpt-route-toggle:focus {
        outline: 2px solid rgba(var(--wp-gpt-primary-color-start-rgb), 0.45);
        outline-offset: 2px;
    }
    .wp-gpt-route-card.open .wp-gpt-route-toggle {
        transform: rotate(180deg);
        box-shadow: 0 14px 30px rgba(67, 56, 202, 0.45);
    }
    .wp-gpt-route-icon {
        width: 14px;
        height: 14px;
        position: relative;
        display: inline-block;
    }
    .wp-gpt-route-icon::before,
    .wp-gpt-route-icon::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 12px;
        height: 2px;
        background: currentColor;
        transform: translate(-50%, -50%);
        transition: transform 0.3s ease;
        border-radius: 2px;
    }
    .wp-gpt-route-icon::after {
        transform: translate(-50%, -50%) rotate(90deg);
    }
    .wp-gpt-route-card.open .wp-gpt-route-icon::after {
        transform: translate(-50%, -50%) rotate(90deg) scaleX(0);
    }
    .wp-gpt-route-body {
        margin-top: 16px;
        padding-top: 14px;
        border-top: 1px solid rgba(129, 140, 248, 0.35);
        color: #312e81;
        line-height: 1.6;
        font-size: 14px;
        background: rgba(255, 255, 255, 0.75);
        border-radius: 12px;
        padding: 16px;
        animation: wpGptFadeIn 0.3s ease;
    }
    .wp-gpt-route-snippet {
        margin: 12px 0;
        background: #111827;
        color: #f8fafc;
        padding: 14px 16px;
        border-radius: 12px;
        overflow-x: auto;
        font-family: 'Fira Code', monospace;
        box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.25);
        line-height: 1.5;
    }
    .wp-gpt-route-snippet code {
        white-space: pre;
        display: block;
    }
    .wp-gpt-route-actions {
        margin-top: 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }
    .wp-gpt-route-actions .button {
        margin-top: 0;
        margin-left: auto;
    }
    .wp-gpt-route-select {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        color: #312e81;
        cursor: pointer;
    }
    .wp-gpt-route-select input[type="checkbox"] {
        width: 18px;
        height: 18px;
        accent-color: var(--wp-gpt-primary-color-start);
        cursor: pointer;
    }
    .wp-gpt-route-scroll {
        background: #ffffff;
        border: 1px solid rgba(var(--wp-gpt-primary-color-start-rgb), 0.35);
        color: var(--wp-gpt-primary-color-start);
        box-shadow: 0 6px 16px rgba(var(--wp-gpt-primary-color-start-rgb), 0.18);
        transition: background 0.3s ease, color 0.3s ease, box-shadow 0.3s ease;
    }
    .wp-gpt-route-scroll:hover,
    .wp-gpt-route-scroll:focus {
        background: var(--wp-gpt-primary-gradient);
        color: #ffffff;
        box-shadow: 0 14px 30px rgba(var(--wp-gpt-primary-color-start-rgb), 0.32);
    }
    .wp-gpt-route-scroll:focus {
        outline: 2px solid rgba(var(--wp-gpt-primary-color-start-rgb), 0.45);
        outline-offset: 2px;
    }
    .wp-gpt-schema-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: center;
        margin-top: 16px;
    }
    .wp-gpt-schema-actions .button,
    .wp-gpt-route-copy-buttons .button {
        margin-top: 0;
    }
    .wp-gpt-schema-actions.wp-gpt-schema-highlight {
        outline: 3px solid rgba(var(--wp-gpt-primary-color-start-rgb), 0.35);
        outline-offset: 4px;
        border-radius: 14px;
    }
    .wp-gpt-schema-categories {
        display: grid;
        gap: 18px;
        margin-top: 20px;
        margin-bottom: 24px;
    }
    .wp-gpt-schema-category {
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        background: #ffffff;
        box-shadow: 0 15px 30px rgba(15, 23, 42, 0.08);
        overflow: hidden;
    }
    .wp-gpt-schema-category-toggle {
        width: 100%;
        border: none;
        background: linear-gradient(135deg, #312e81, #4338ca);
        color: #ffffff;
        padding: 18px 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        position: relative;
    }
    .wp-gpt-schema-category-toggle::after {
        content: '\25BC';
        position: absolute;
        right: 24px;
        top: 50%;
        transform: translateY(-50%);
        transition: transform 0.3s ease;
        font-size: 14px;
    }
    .wp-gpt-schema-category-toggle[aria-expanded="true"]::after {
        transform: translateY(-50%) rotate(180deg);
    }
    .wp-gpt-schema-category-toggle:focus {
        outline: 3px solid rgba(255, 255, 255, 0.45);
        outline-offset: -2px;
    }
    .wp-gpt-schema-category-count {
        background: rgba(255, 255, 255, 0.18);
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 12px;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }
    .wp-gpt-schema-category-panel {
        padding: 22px;
        background: #f8fafc;
    }
    .wp-gpt-route-copy-buttons {
        margin-top: 12px;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }
    .wp-gpt-route-copy-snippet {
        margin-top: 10px;
        background: var(--wp-gpt-primary-gradient);
        color: #ffffff;
        border: none;
        box-shadow: 0 12px 24px rgba(var(--wp-gpt-primary-color-start-rgb), 0.28);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .wp-gpt-route-copy-snippet:hover,
    .wp-gpt-route-copy-snippet:focus {
        transform: translateY(-1px);
        box-shadow: 0 18px 32px rgba(var(--wp-gpt-primary-color-start-rgb), 0.35);
    }
    .wp-gpt-route-copy-snippet:focus {
        outline: 2px solid rgba(255, 255, 255, 0.6);
        outline-offset: 2px;
    }
    .wp-gpt-copy-route {
        background: var(--wp-gpt-primary-gradient);
        color: #ffffff;
        border: none;
        box-shadow: 0 12px 24px rgba(var(--wp-gpt-primary-color-start-rgb), 0.28);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .wp-gpt-copy-route:hover,
    .wp-gpt-copy-route:focus {
        transform: translateY(-1px);
        box-shadow: 0 18px 32px rgba(var(--wp-gpt-primary-color-start-rgb), 0.35);
    }
    .wp-gpt-copy-route:focus {
        outline: 2px solid rgba(255, 255, 255, 0.6);
        outline-offset: 2px;
    }
    .wp-gpt-route-copy-placeholder {
        margin: 0;
        font-size: 13px;
        color: #475569;
        font-style: italic;
    }
    .wp-gpt-empty-category {
        margin: 0;
        font-size: 14px;
        color: #475569;
        font-style: italic;
    }
    .wp-gpt-route-feedback {
        margin: 8px 0 0;
        font-size: 13px;
        color: #312e81;
    }
    @keyframes wpGptFadeIn {
        from {
            opacity: 0;
            transform: translateY(-6px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    @media (max-width: 782px) {
        .wp-gpt-route-card {
            padding: 18px;
        }
        .wp-gpt-route-header {
            flex-direction: column;
            align-items: stretch;
            gap: 16px;
        }
        .wp-gpt-route-toggle {
            align-self: flex-end;
        }
        .wp-gpt-route-info {
            width: 100%;
        }
        .wp-gpt-route-summary {
            align-self: flex-start;
        }
        .wp-gpt-token-copy-inner {
            flex-direction: column;
            align-items: stretch;
        }
        .wp-gpt-token-copy-inner input {
            width: 100%;
        }
        .wp-gpt-schema-category-toggle {
            align-items: flex-start;
            gap: 10px;
        }
    }
</style>

        <?php
    }

    public static function enqueue_admin_scripts($hook) {
        if ($hook !== 'wp-gpt-automation-pro_page_wp-gpt-schema-guide') {
            return;
        }
        wp_enqueue_style('wp-gpt-admin-css', WP_GPT_PLUGIN_URL . 'assets/admin.css', [], WP_GPT_VERSION);
    }
}

WP_GPT_Schema_Guide::init();
