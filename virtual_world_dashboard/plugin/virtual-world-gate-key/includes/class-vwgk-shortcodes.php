<?php

if (!defined('ABSPATH')) {
    exit;
}

class VWGK_Shortcodes
{
    public static function init(): void
    {
        add_shortcode('vwgk_ha_entities', [__CLASS__, 'render_entities']);
        add_shortcode('vwgk_ha_scripts', [__CLASS__, 'render_scripts']);
        add_shortcode('vwgk_ha_automations', [__CLASS__, 'render_automations']);
        add_shortcode('vwgk_ha_assistants', [__CLASS__, 'render_assistants']);
        add_shortcode('vwgk_ha_chat', [__CLASS__, 'render_chat']);
        add_shortcode('vwgk_ha_card', [__CLASS__, 'render_ha_card']);
        add_shortcode('ha_ac_card', [__CLASS__, 'render_ha_card']); // alias for compatibility

        add_action('wp_ajax_vwgk_refresh_entities', [__CLASS__, 'ajax_refresh_entities']);
        add_action('wp_ajax_vwgk_refresh_scripts', [__CLASS__, 'ajax_refresh_scripts']);
        add_action('wp_ajax_vwgk_refresh_automations', [__CLASS__, 'ajax_refresh_automations']);
        add_action('wp_ajax_vwgk_refresh_assistants', [__CLASS__, 'ajax_refresh_assistants']);
        add_action('wp_ajax_vwgk_chat_send', [__CLASS__, 'ajax_chat_send']);
        add_action('wp_ajax_vwgk_ha_execute', [__CLASS__, 'ajax_execute_service']);
        add_action('wp_ajax_vwgk_camera_proxy', [__CLASS__, 'ajax_camera_proxy']);
        add_action('wp_ajax_nopriv_vwgk_camera_proxy', [__CLASS__, 'ajax_camera_proxy']);

        add_action('wp_footer', [__CLASS__, 'render_footer_modal']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    
    private static $needs_modal = false;

    public static function render_footer_modal() {
        if (!self::$needs_modal) return;
        ?>
        <!-- Global Modal for Technical Details -->
        <div id="vwgk-ha-modal-overlay" class="vwgk-ha-modal-hidden">
            <div class="vwgk-ha-modal-content">
                <div class="vwgk-ha-modal-header">
                    <h4 id="vwgk-ha-modal-title">Dettagli Tecnici</h4>
                    <button id="vwgk-ha-modal-close">&times;</button>
                </div>
                <div class="vwgk-ha-modal-body">
                    <table class="vwgk-ha-details-table">
                        <thead>
                            <tr><th>Attributo</th><th>Valore</th></tr>
                        </thead>
                        <tbody id="vwgk-ha-modal-data">
                            <!-- Popolato via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    public static function maybe_create_default_templates(): void
    {
        if (!is_admin()) return;
        
        // 1. Crea lo snippet Shortcoder "ha_ac_card"
        $template_name = 'ha_ac_card';
        $sc_post_type = defined('SC_POST_TYPE') ? SC_POST_TYPE : 'shortcoder';
        
        // Forza post type se non definito (Shortcoder potrebbe non essere ancora caricato)
        if ($sc_post_type === 'shortcoder' && !post_type_exists('shortcoder')) {
            $sc_post_type = 'shortcoder'; // In teoria è questo il default
        }

        $existing_template = get_page_by_path($template_name, OBJECT, $sc_post_type);

        if (!$existing_template) {
            $html = "<div class='vwgk-ha-card climate-card' data-id='{{entity_id}}' data-domain='climate' data-state='{{state}}'><div class='vwgk-ha-card-content'><div class='vwgk-ha-card-icon'>{{icon}}</div><div class='vwgk-ha-card-info'><div class='vwgk-ha-card-name'>{{name}}</div><div class='vwgk-ha-card-state'>{{state}} - <span class='vwgk-ha-temp-value'>{{temp}}</span>°C</div></div></div><div class='vwgk-ha-card-controls'><div class='vwgk-ha-temp-ctrl'><button class='vwgk-ha-btn-circle' data-action='set_temp' data-step='-0.5'>-</button><span class='vwgk-ha-target-label'>Target: <span class='vwgk-ha-temp-value'>{{target_temp}}</span>°C</span><button class='vwgk-ha-btn-circle' data-action='set_temp' data-step='0.5'>+</button></div><div class='vwgk-ha-mode-ctrl'><button class='vwgk-ha-btn-mode' data-action='set_mode' data-mode='cool'>❄️</button><button class='vwgk-ha-btn-mode' data-action='set_mode' data-mode='heat'>🔥</button><button class='vwgk-ha-btn-mode' data-action='set_mode' data-mode='off'>⭕</button></div></div></div><style>.climate-card { background: linear-gradient(135deg, #2c3e50, #000000); color: white; border-radius: 15px; padding: 15px; margin-bottom: 15px; }.vwgk-ha-card-controls { margin-top: 15px; display: flex; flex-direction: column; gap: 10px; }.vwgk-ha-temp-ctrl { display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.1); padding: 8px; border-radius: 10px; }.vwgk-ha-btn-circle { width: 30px; height: 30px; border-radius: 50%; border: none; background: #3498db; color: white; cursor: pointer; font-size: 18px; line-height: 1; }.vwgk-ha-btn-circle:hover { background: #2980b9; }.vwgk-ha-mode-ctrl { display: flex; gap: 5px; justify-content: center; }.vwgk-ha-btn-mode { padding: 5px 10px; border-radius: 5px; border: 1px solid rgba(255,255,255,0.2); background: transparent; color: white; cursor: pointer; }.vwgk-ha-btn-mode:hover { background: rgba(255,255,255,0.1); }</style>";
            
            wp_insert_post([
                'post_title'   => 'HA AC Card',
                'post_name'    => $template_name,
                'post_content' => $html,
                'post_status'  => 'publish',
                'post_type'    => $sc_post_type
            ]);
        }

        // 2. Crea la pagina "Controllo Condizionatori"
        $page_slug = 'controllo-condizionatori';
        $existing_page = get_page_by_path($page_slug);

        if (!$existing_page) {
            $content = '[vwgk_ha_entities template="ha_ac_card" entities="climate." columns="2"]';
            wp_insert_post([
                'post_title'   => 'Controllo Condizionatori',
                'post_name'    => $page_slug,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_type'    => 'page'
            ]);
        }
    }

    public static function enqueue_assets(): void
    {
        // Enqueue only if we are on a page with shortcodes or template, but for now we enqueue globally if needed
        // Or we register it and enqueue it inside the shortcode.
        wp_register_style('vwgk-ha-styles', VWGK_URL . 'assets/vwgk-ha.css', [], VWGK_VERSION);
        wp_register_script('vwgk-ha-scripts', VWGK_URL . 'assets/vwgk-ha.js', ['jquery'], VWGK_VERSION, true);

        wp_localize_script('vwgk-ha-scripts', 'vwgkHaParams', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('vwgk_ha_nonce')
        ]);
    }

    private static function check_auth()
    {
        if (!is_user_logged_in()) {
            auth_redirect();
            exit;
        }
    }

    private static function parse_ha_atts($atts)
    {
        return shortcode_atts([
            'entities' => '',
            'limit'    => '0',
            'columns'  => '1',
            'template' => '' // Nome dello shortcode Shortcoder da usare come template
        ], $atts);
    }

    public static function render_entities($atts)
    {
        self::check_auth();
        self::$needs_modal = true;
        wp_enqueue_style('vwgk-ha-styles');
        wp_enqueue_script('vwgk-ha-scripts');
        $a = self::parse_ha_atts($atts);

        ob_start();
        ?>
        <div class="vwgk-ha-container" id="vwgk-ha-entities-container" style="--ha-cols: <?php echo esc_attr($a['columns']); ?>;">
            <h3>Entità Home Assistant</h3>
            <button class="vwgk-ha-refresh-btn" data-action="vwgk_refresh_entities">Aggiorna Dati</button>
            <div class="vwgk-ha-grid vwgk-ha-loading" id="vwgk-ha-entities-list" 
                 data-entities="<?php echo esc_attr($a['entities']); ?>" 
                 data-limit="<?php echo esc_attr($a['limit']); ?>" 
                 data-columns="<?php echo esc_attr($a['columns']); ?>"
                 data-template="<?php echo esc_attr($a['template']); ?>">
                <!-- Verrà popolato via JS -->
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_scripts($atts)
    {
        self::check_auth();
        wp_enqueue_style('vwgk-ha-styles');
        wp_enqueue_script('vwgk-ha-scripts');
        $a = self::parse_ha_atts($atts);

        ob_start();
        ?>
        <div class="vwgk-ha-container" id="vwgk-ha-scripts-container" style="--ha-cols: <?php echo esc_attr($a['columns']); ?>;">
            <h3>Script</h3>
            <button class="vwgk-ha-refresh-btn" data-action="vwgk_refresh_scripts">Aggiorna Dati</button>
            <div class="vwgk-ha-grid vwgk-ha-loading" id="vwgk-ha-scripts-list" 
                 data-entities="<?php echo esc_attr($a['entities']); ?>" 
                 data-limit="<?php echo esc_attr($a['limit']); ?>" 
                 data-columns="<?php echo esc_attr($a['columns']); ?>"
                 data-template="<?php echo esc_attr($a['template']); ?>"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_automations($atts)
    {
        self::check_auth();
        wp_enqueue_style('vwgk-ha-styles');
        wp_enqueue_script('vwgk-ha-scripts');
        $a = self::parse_ha_atts($atts);

        ob_start();
        ?>
        <div class="vwgk-ha-container" id="vwgk-ha-automations-container" style="--ha-cols: <?php echo esc_attr($a['columns']); ?>;">
            <h3>Automazioni</h3>
            <button class="vwgk-ha-refresh-btn" data-action="vwgk_refresh_automations">Aggiorna Dati</button>
            <div class="vwgk-ha-grid vwgk-ha-loading" id="vwgk-ha-automations-list" 
                 data-entities="<?php echo esc_attr($a['entities']); ?>" 
                 data-limit="<?php echo esc_attr($a['limit']); ?>" 
                 data-columns="<?php echo esc_attr($a['columns']); ?>"
                 data-template="<?php echo esc_attr($a['template']); ?>"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_assistants($atts)
    {
        self::check_auth();
        wp_enqueue_style('vwgk-ha-styles');
        wp_enqueue_script('vwgk-ha-scripts');
        $a = self::parse_ha_atts($atts);

        ob_start();
        ?>
        <div class="vwgk-ha-container" id="vwgk-ha-assistants-container" style="--ha-cols: <?php echo esc_attr($a['columns']); ?>;">
            <h3>Assistenti / Pipeline</h3>
            <button class="vwgk-ha-refresh-btn" data-action="vwgk_refresh_assistants">Aggiorna Dati</button>
            <div class="vwgk-ha-grid vwgk-ha-loading" id="vwgk-ha-assistants-list" 
                 data-entities="<?php echo esc_attr($a['entities']); ?>" 
                 data-limit="<?php echo esc_attr($a['limit']); ?>" 
                 data-columns="<?php echo esc_attr($a['columns']); ?>"
                 data-template="<?php echo esc_attr($a['template']); ?>"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_chat($atts)
    {
        self::check_auth();
        wp_enqueue_style('vwgk-ha-styles');
        wp_enqueue_script('vwgk-ha-scripts');

        $a = shortcode_atts([
            'agent_id' => ''
        ], $atts);

        ob_start();
        ?>
        <div class="vwgk-ha-container vwgk-ha-chat-wrapper" id="vwgk-ha-chat-container">
            <h3>Assistente Home Assistant</h3>
            <div class="vwgk-ha-chat-box" id="vwgk-ha-chat-box"></div>
            <div class="vwgk-ha-chat-input-wrapper">
                <input type="text" id="vwgk-ha-chat-input" placeholder="Scrivi un messaggio..." />
                <input type="hidden" id="vwgk-ha-chat-agent-id" value="<?php echo esc_attr($a['agent_id']); ?>" />
                <button type="button" id="vwgk-ha-chat-send">Invia</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * [vwgk_ha_card] — renders a single climate/entity card.
     *
     * Attributes:
     *   entity_id  — HA entity ID (e.g. climate.bagno)
     *   name       — Display name override
     *   icon       — Emoji or text icon override (HEAT, COOL, FAN, AUTO, or any emoji)
     *   mode       — Default hvac_mode to display (optional, overrides HA state)
     *
     * Example: [vwgk_ha_card entity_id='climate.bagno' name='Bagno' icon='🔥']
     */
    public static function render_ha_card($atts)
    {
        self::check_auth();
        self::$needs_modal = true;
        wp_enqueue_style('vwgk-ha-styles');
        wp_enqueue_script('vwgk-ha-scripts');

        $a = shortcode_atts([
            'entity_id' => '',
            'name'      => '',
            'icon'      => '',
            'mode'      => '',
            // DOVE STA NELLA PAGINA, come numero e non come posizione nel testo.
            //
            // A COSA SERVE. Chi trascina una card in cima non deve riscrivere
            // il contenuto della pagina rimescolando gli shortcode: cambia un
            // numero, e basta. E' anche l'unico modo perche' una rigenerazione
            // non spazzi via l'ordine scelto a mano - i numeri si rileggono,
            // le posizioni nel testo no.
            //
            // Il numero diventa la proprieta' CSS "order": e' il browser a
            // rimettere in fila, senza codice che sposti nodi.
            'ordine'    => '',
        ], $atts);

        $entity = VWGK_HA_API::get_state(esc_attr($a['entity_id']));
        if (empty($entity) || !is_array($entity)) {
            $entity = [
                'entity_id' => $a['entity_id'],
                'state' => 'Caricamento...',
                'attributes' => []
            ]; // Fallback placeholder se non è tra le entità cachate
        }
        
        // Passa le opzioni per forzare eventuale nome, icona o mode
        $options = [
            'icon' => $a['icon'],
            'mode' => $a['mode']
        ];
        if (!empty($a['name'])) {
            $entity['attributes']['friendly_name'] = $a['name'];
        }

        ob_start();
        echo VWGK_HA_Card_Factory::render_entity($entity, $options);
        $html = ob_get_clean();

        // Senza ordine si lascia com'e': una card in mezzo a un testo non deve
        // ritrovarsi dentro un contenitore che non ha chiesto.
        if ($a['ordine'] === '' || !is_numeric($a['ordine'])) {
            return $html;
        }
        return '<div class="vwgk-ordinata" style="order:' . (int) $a['ordine'] . '"'
            . ' data-ordine="' . (int) $a['ordine'] . '"'
            . ' data-entity="' . esc_attr((string) $a['entity_id']) . '">' . $html . '</div>';
    }

    // --- AJAX Handlers ---

    private static function check_ajax()
    {
        check_ajax_referer('vwgk_ha_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error('Non autorizzato');
        }
    }

    public static function ajax_refresh_entities()
    {
        self::check_ajax();
        $entities_list = !empty($_POST['entities']) ? explode(',', sanitize_text_field($_POST['entities'])) : [];
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 0;
        $template = !empty($_POST['template']) ? sanitize_text_field($_POST['template']) : '';
        $entities = VWGK_HA_API::get_general_entities($entities_list, $limit);
        wp_send_json_success(self::build_html_list($entities, $template));
    }

    public static function ajax_refresh_scripts()
    {
        self::check_ajax();
        $entities_list = !empty($_POST['entities']) ? explode(',', sanitize_text_field($_POST['entities'])) : [];
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 0;
        $template = !empty($_POST['template']) ? sanitize_text_field($_POST['template']) : '';
        $entities = VWGK_HA_API::get_scripts($entities_list, $limit);
        wp_send_json_success(self::build_html_list($entities, $template));
    }

    public static function ajax_refresh_automations()
    {
        self::check_ajax();
        $entities_list = !empty($_POST['entities']) ? explode(',', sanitize_text_field($_POST['entities'])) : [];
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 0;
        $template = !empty($_POST['template']) ? sanitize_text_field($_POST['template']) : '';
        $entities = VWGK_HA_API::get_automations($entities_list, $limit);
        wp_send_json_success(self::build_html_list($entities, $template));
    }

    public static function ajax_refresh_assistants()
    {
        self::check_ajax();
        $entities_list = !empty($_POST['entities']) ? explode(',', sanitize_text_field($_POST['entities'])) : [];
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 0;
        $template = !empty($_POST['template']) ? sanitize_text_field($_POST['template']) : '';
        $entities = VWGK_HA_API::get_assistants($entities_list, $limit);
        wp_send_json_success(self::build_html_list($entities, $template));
    }

    public static function ajax_execute_service()
    {
        self::check_ajax();
        $entity_id = sanitize_text_field($_POST['entity_id'] ?? '');
        $state     = sanitize_text_field($_POST['state'] ?? ''); // 'on', 'off', or empty for toggle/trigger

        if (empty($entity_id)) {
            wp_send_json_error('Entity ID mancante');
        }

        $parts = explode('.', $entity_id);
        $domain = $parts[0];
        $service = '';

        // Se lo state è già un servizio specifico di dominio (es. 'set_temperature', 'set_cover_position', 'open_cover')
        $known_services = [
            'set_temperature', 'set_preset_mode', 'set_hvac_mode', 
            'set_cover_position', 'open_cover', 'close_cover', 'stop_cover',
            'media_play', 'media_pause', 'media_next_track', 'media_previous_track', 'volume_set',
            'lock', 'unlock'
        ];
        
        if (in_array($state, $known_services)) {
            $service = $state;
        } elseif (in_array($domain, ['light', 'switch', 'fan', 'input_boolean', 'climate'])) {
            $service = ($state === 'on') ? 'turn_on' : (($state === 'off') ? 'turn_off' : 'toggle');
        } elseif ($domain === 'script') {
            $service = 'turn_on';
        } elseif ($domain === 'automation') {
            $service = 'trigger';
        } else {
            // Default generic fallback
            $service = !empty($state) ? 'turn_' . $state : 'toggle';
        }

        // Recupera le restanti opzioni (come la luminosità, posizione, temperatura) passate in POST proxyandole a HA
        $payload = isset($_POST['payload']) ? (array)$_POST['payload'] : [];
        $payload = map_deep($payload, 'sanitize_text_field'); // Sanitizza tutti i campi nell'array n-dimensionale
        
        $request_body = array_merge(['entity_id' => $entity_id], $payload);
        $response = VWGK_HA_Client::request('POST', "/services/$domain/$service", $request_body);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        wp_send_json_success($response['body']);
    }

    public static function ajax_chat_send()
    {
        self::check_ajax();
        $text = sanitize_text_field($_POST['text'] ?? '');
        $agent_id = sanitize_text_field($_POST['agent_id'] ?? '');
        $conversation_id = sanitize_text_field($_POST['conversation_id'] ?? '');

        if (empty($text)) {
            wp_send_json_error('Testo vuoto');
        }

        $res = VWGK_HA_API::send_conversation($text, $agent_id, $conversation_id);

        if (!$res['success']) {
            wp_send_json_error($res['message']);
        }

        wp_send_json_success($res['response']);
    }

    private static function build_html_list(array $entities, string $template_name = ''): string
    {
        if (empty($entities)) {
            return '<p>Nessun elemento trovato.</p>';
        }

        $template_content = '';
        if (!empty($template_name)) {
            $sc_post_type = defined('SC_POST_TYPE') ? SC_POST_TYPE : 'shortcoder';
            $page = get_page_by_path($template_name, OBJECT, $sc_post_type);
            if ($page) {
                $template_content = $page->post_content;
            }
        }

        $html = '';
        foreach ($entities as $e) {
            $options = ['template_content' => $template_content];
            $html .= VWGK_HA_Card_Factory::render_entity($e, $options);
        }
        return $html;
    }

    /**
     * Proxy per le immagini delle telecamere di Home Assistant.
     * Necessario per bypassare problemi di certificati self-signed o cross-origin nel browser.
     */
    public static function ajax_camera_proxy()
    {
        // Verifica nonce per sicurezza
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'vwgk_ha_nonce')) {
            status_header(403);
            exit('Nonce non valido.');
        }

        $entity_id = sanitize_text_field($_GET['entity_id'] ?? '');
        if (empty($entity_id)) {
            status_header(400);
            exit('Entity ID mancante.');
        }

        $endpoint = "/camera_proxy/$entity_id";
        $response = VWGK_HA_Client::request('GET', $endpoint);

        if (is_wp_error($response) || $response['status_code'] !== 200) {
            status_header($response['status_code'] ?? 500);
            exit('Impossibile recuperare lo stream della camera.');
        }

        // Passa gli header del contenuto (solitamente image/jpeg)
        $content_type = $response['headers']['content-type'] ?? 'image/jpeg';
        header("Content-Type: $content_type");
        echo $response['body'];
        exit;
    }
}
