<?php
namespace WP_GPT_Automation_Pro;

class WP_GPT_Custom_Admin_Settings {

    public static function init() {
        // Aggiungi le azioni per la pagina di amministrazione e il registro delle impostazioni
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    // Metodo per la validazione silenziosa della licenza tramite il server di aggiornamento
    public static function validate_request_due(\WP_REST_Request $request) {
        $license_check = WP_GPT_License_Check::verify();
        if (is_wp_error($license_check)) {
            return $license_check;
        }
        $custom_header_check = validate_custom_security_header($request);
        if (is_wp_error($custom_header_check)) {
            return $custom_header_check;
        }
        $auth_header = get_bearer_token($request);

        if (defined('WP_DEBUG') && WP_DEBUG && $auth_header) {
            error_log('DEBUG: Authorization header received');
        }

        // Estrazione del token dall'header "Authorization"
        $received_token = $auth_header ? substr($auth_header, 7) : '';

        // Recupero dei dati di validazione salvati nel sistema
        $saved_token = get_option('wp_gpt_api_token');
        $saved_domain = self::get_formatted_domain(site_url());
        $saved_license_key = get_option('wp_gpt_license_key');

        // Log dettagliato per il debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DEBUG: Token e dominio pronti per la validazione');
        }

        // Controllo del token Bearer
        if (empty($received_token)) {
            error_log('ERRORE: Token mancante nella richiesta');
            return new \WP_Error(
                'unauthorized',
                __('Token mancante nella richiesta', 'wp-gpt-automation-pro'),
                [
                    'status' => 401,
                    'used_token' => $received_token,
                    'expected_token' => $saved_token,
                    'used_domain' => $saved_domain,
                    'license_key' => $saved_license_key
                ]
            );
        }

        $token_validation = Token_Validator::validate_bearer_token($received_token, [
            'error_message' => __('Token non valido', 'wp-gpt-automation-pro'),
            'error_data'    => [
                'used_token'     => $received_token,
                'expected_token' => $saved_token,
                'used_domain'    => $saved_domain,
                'license_key'    => $saved_license_key,
            ],
        ]);
        if (is_wp_error($token_validation)) {
            error_log('ERRORE: Token non valido. Accesso negato.');
            return $token_validation;
        }

        // Verifica del dominio attuale
        $current_domain = self::get_formatted_domain(site_url());
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DEBUG: Domain validation in progress');
        }

        if ($current_domain !== $saved_domain) {
            error_log('ERRORE: Dominio non valido. Accesso negato.');
            return new \WP_Error(
                'unauthorized',
                __('Dominio non valido', 'wp-gpt-automation-pro'),
                [
                    'status' => 403,
                    'used_domain' => $current_domain,
                    'expected_domain' => $saved_domain,
                    'used_token' => $received_token,
                    'license_key' => $saved_license_key
                ]
            );
        }

        // Verifica della licenza con il server esterno
        $server_validation = self::verify_license_with_server($saved_license_key, $saved_domain, 'PHP API automatic');
        if (is_wp_error($server_validation)) {
            error_log('ERRORE: Verifica della licenza con il server fallita: ' . $server_validation->get_error_message());
            return new \WP_Error(
                'unauthorized',
                __('Errore durante la verifica della licenza con il server.', 'wp-gpt-automation-pro'),
                [
                    'status' => 403,
                    'error_details' => $server_validation->get_error_message(),
                    'used_domain' => $current_domain,
                    'used_token' => $received_token,
                    'expected_domain' => $saved_domain,
                    'license_key' => $saved_license_key
                ]
            );
        }

        error_log('DEBUG: Richiesta validata con successo tramite token e licenza.');
        return true;
    }

    // Funzione per verificare la licenza con il server centrale
// Funzione per verificare la licenza con il server centrale
private static function verify_license_with_server($license_key, $domain, $request_type = 'unknown') {
    error_log('DEBUG: Inizio verifica licenza con server. Richiesta via: ' . $request_type);
    error_log('DEBUG: Licenza: ' . $license_key . ' - Dominio: ' . $domain);

    // Definisci l'URL dell'API del server di aggiornamento
    $api_url = 'https://workingwithweb.it/webagency/wp-json/plugin-updater/v1/check_version';
    
    // Log per la richiesta
    error_log('DEBUG: URL API: ' . $api_url);
    error_log('DEBUG: Corpo della richiesta: ' . json_encode(['license_key' => $license_key, 'domain' => $domain]));

    // Definisci gli argomenti per la richiesta wp_remote_post
    $args = array(
        'body' => json_encode(array(
            'license_key' => $license_key,
            'domain' => $domain
        )),
        'headers' => array(
            'Content-Type' => 'application/json',
            'User-Agent' => 'curl/7.68.0',  // Imposta lo stesso User-Agent usato da curl
        ),
        'timeout' => 30, // Timeout esteso per evitare interruzioni
        'sslverify' => true, // Assicurati che SSL sia verificato
        'redirection' => 5 // Segui fino a 5 reindirizzamenti
    );

    // Effettua la richiesta
    $response = wp_remote_post($api_url, $args);

    // Controlla se ci sono errori nella comunicazione con il server
    if (is_wp_error($response)) {
        error_log('ERRORE: Errore di comunicazione con il server di verifica: ' . $response->get_error_message());
        return new \WP_Error('server_error', __('Errore di comunicazione con il server di verifica.', 'wp-gpt-automation-pro'), ['status' => 500]);
    }

    // Recupera il corpo della risposta e il codice di stato HTTP
    $response_body = wp_remote_retrieve_body($response);
    $response_code = wp_remote_retrieve_response_code($response);

    // Log della risposta completa per debugging
    error_log('DEBUG: Codice di risposta HTTP: ' . $response_code);
    error_log('DEBUG: Risposta dal server: ' . $response_body);

    // Decodifica la risposta JSON
    $response_json = json_decode($response_body, true);

    // Verifica se la risposta contiene un errore (es. forbidden o file_not_found)
    if (isset($response_json['error'])) {
        error_log('ERRORE: Errore dal server: ' . $response_json['error']);
        return new \WP_Error('server_error', __('Errore dal server: ' . $response_json['error'], 'wp-gpt-automation-pro'), ['status' => $response_code]);
    }

    // Nuovo controllo: Verifica se è presente il campo 'new_version' come risposta
    if (isset($response_json['new_version'])) {
        error_log('DEBUG: Licenza valida. Il server conferma la versione: ' . $response_json['new_version']);
        return true; // La licenza è valida se riceviamo il numero di versione
    } else {
        error_log('ERRORE: Risposta inattesa dal server. Risposta completa: ' . $response_body);
        return new \WP_Error('unauthorized', __('Risposta inattesa dal server di verifica', 'wp-gpt-automation-pro'), ['status' => 403]);
    }
}

    


    // Helper per formattare il dominio nel formato richiesto
    private static function get_formatted_domain($url) {
        $parsed_url = parse_url($url);
        return isset($parsed_url['host']) ? $parsed_url['host'] : '';
    }

    // Metodo di validazione dell'user code
    public static function validate_user_code(\WP_REST_Request $request) {
        $license_check = WP_GPT_License_Check::verify();
        if (is_wp_error($license_check)) {
            return $license_check;
        }
        $custom_header_check = validate_custom_security_header($request);
        if (is_wp_error($custom_header_check)) {
            return $custom_header_check;
        }
        $params = $request->get_json_params();
        $user_code = isset($params['user_code']) ? sanitize_text_field($params['user_code']) : '';
        $saved_user_code = get_option('wp_gpt_user_code');

        $auth_header = get_bearer_token($request);
        $received_token = $auth_header ? substr($auth_header, 7) : '';
        $saved_token = get_option('wp_gpt_api_token');
        $saved_domain = self::get_formatted_domain(site_url());
        $saved_license_key = get_option('wp_gpt_license_key');

        // Logga sempre il tentativo di autenticazione
        WP_GPT_Auth_Log::log($received_token, $saved_token, $user_code, $saved_user_code, $request->get_params());

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DEBUG: User code provided for validation');
        }

        if ($user_code !== $saved_user_code) {
            error_log('ERRORE: User code non valido o mancante.');
            return new \WP_Error('unauthorized', __('User code non valido o mancante', 'wp-gpt-automation-pro'), ['status' => 401]);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DEBUG: Token and domain checked');
        }

        if (empty($received_token)) {
            error_log('ERRORE: Token non valido o mancante.');
            return new \WP_Error('unauthorized', __('Token non valido o mancante', 'wp-gpt-automation-pro'), ['status' => 401]);
        }

        $token_validation = Token_Validator::validate_bearer_token($received_token, [
            'error_message' => __('Token non valido o mancante', 'wp-gpt-automation-pro'),
        ]);
        if (is_wp_error($token_validation)) {
            error_log('ERRORE: Token non valido o mancante.');
            return $token_validation;
        }

        $current_domain = self::get_formatted_domain(site_url());
        if ($current_domain !== $saved_domain) {
            error_log('ERRORE: Dominio non valido.');
            return new \WP_Error('unauthorized', __('Dominio non valido', 'wp-gpt-automation-pro'), ['status' => 403]);
        }

        $server_validation = self::verify_license_with_server($saved_license_key, $saved_domain, 'validate_user_code');
        if (is_wp_error($server_validation)) {
            error_log('ERRORE: Verifica della licenza fallita durante la validazione dell\'user code.');
            return $server_validation;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DEBUG: Richiesta validata con successo tramite user code e token.');
        }
        return true;
    }

    // Funzioni per gestire la configurazione e il frontend
    public static function add_admin_menu() {
        add_options_page(
            __('Impostazioni Licenza GPT', 'wp-gpt-automation-pro'),
            __('Licenza GPT', 'wp-gpt-automation-pro'),
            'manage_options',
            'wp-gpt-license-settings',
            [__CLASS__, 'render_license_page']
        );
    }

    public static function register_settings() {
        register_setting('wp_gpt_license_settings', 'wp_gpt_license_key');

        add_settings_section('wp_gpt_license_section', __('Gestione Licenza GPT', 'wp-gpt-automation-pro'), null, 'wp_gpt-license-settings');

        add_settings_field(
            'wp_gpt_license_key',
            __('Chiave Licenza', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_license_key_field'],
            'wp_gpt-license-settings',
            'wp_gpt_license_section'
        );
    }

    public static function render_license_key_field() {
        $license_key = get_option('wp_gpt_license_key', '');
        echo '<input type="text" name="wp_gpt_license_key" value="' . esc_attr($license_key) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Inserisci la chiave di licenza fornita per attivare il plugin.', 'wp-gpt-automation-pro') . '</p>';
    }

    public static function render_license_page() {
        $nonce_action = 'wp_gpt_do_check_update';
        $check_url = wp_nonce_url(admin_url('options-general.php?page=wp-gpt-license-settings&wp_gpt_do_check_update=1'), $nonce_action);

        $checked = isset($_GET['wp_gpt_do_check_update']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', $nonce_action);
        $submitted_data = [];
        $response_data = [];
        $update_error = '';
        $download_link = '';

        if ($checked) {
            $license_key = get_option('wp_gpt_license_key', '');
            $domain = parse_url(site_url(), PHP_URL_HOST);
            $current_version = defined('WP_GPT_VERSION') ? WP_GPT_VERSION : '';

            $update_data = WP_GPT_License_Check::get_update_data();

            $submitted_data = [
                __('Licenza:', 'wp-gpt-automation-pro') => $license_key,
                __('Dominio:', 'wp-gpt-automation-pro') => $domain,
                __('Versione:', 'wp-gpt-automation-pro') => $current_version,
            ];

            if (is_wp_error($update_data)) {
                $update_error = $update_data->get_error_message();
            } else {
                if (!empty($update_data['expiry_date'])) {
                    $response_data[__('Scadenza licenza:', 'wp-gpt-automation-pro')] = $update_data['expiry_date'];
                }
                if (!empty($update_data['new_version'])) {
                    $response_data[__('Nuova versione disponibile:', 'wp-gpt-automation-pro')] = $update_data['new_version'];
                }
                if (!empty($update_data['package'])) {
                    $download_link = $update_data['package'];
                }
            }
        }

        echo '<div class="wrap wp-gpt-admin-page">';
        echo '<div class="wp-gpt-card">';
        echo '<div class="wp-gpt-card-header">';
        echo '<h1>' . esc_html__('Impostazioni Licenza GPT', 'wp-gpt-automation-pro') . '</h1>';
        echo '<p>' . esc_html__('Gestisci la tua chiave di licenza e verifica la disponibilità di aggiornamenti direttamente dal pannello.', 'wp-gpt-automation-pro') . '</p>';
        echo '</div>';
        echo '<div class="wp-gpt-card-body">';
        echo '<form method="post" action="options.php" class="wp-gpt-card-form">';
        settings_fields('wp_gpt_license_settings');
        do_settings_sections('wp_gpt-license-settings');
        submit_button();
        echo '</form>';
        echo '<div><a href="' . esc_url($check_url) . '" class="button button-secondary">' . esc_html__('Verifica aggiornamenti', 'wp-gpt-automation-pro') . '</a></div>';
        echo '</div>';
        echo '</div>';

        if ($checked) {
            echo '<div class="wp-gpt-card">';
            echo '<div class="wp-gpt-card-header">';
            echo '<h2>' . esc_html__('Risultati verifica licenza', 'wp-gpt-automation-pro') . '</h2>';
            echo '<p>' . esc_html__('Dettagli della richiesta inviata e risposta del server di licenza.', 'wp-gpt-automation-pro') . '</p>';
            echo '</div>';
            echo '<div class="wp-gpt-card-body">';
            if ($submitted_data) {
                echo '<h3>' . esc_html__('Dati inviati', 'wp-gpt-automation-pro') . '</h3>';
                echo '<ul class="wp-gpt-badge-list">';
                foreach ($submitted_data as $label => $value) {
                    echo '<li><span>' . esc_html($label) . '</span>' . esc_html($value) . '</li>';
                }
                echo '</ul>';
            }

            echo '<h3>' . esc_html__('Risposta server', 'wp-gpt-automation-pro') . '</h3>';
            if ($update_error) {
                echo '<p class="wp-gpt-empty">' . esc_html($update_error) . '</p>';
            } elseif ($response_data) {
                echo '<ul class="wp-gpt-badge-list">';
                foreach ($response_data as $label => $value) {
                    echo '<li><span>' . esc_html($label) . '</span>' . esc_html($value) . '</li>';
                }
                echo '</ul>';
            } else {
                echo '<p class="wp-gpt-empty">' . esc_html__('Nessuna informazione aggiuntiva ricevuta dal server.', 'wp-gpt-automation-pro') . '</p>';
            }

            if ($download_link) {
                echo '<div><a href="' . esc_url($download_link) . '" class="button button-primary" target="_blank" rel="noopener noreferrer">' . esc_html__('Scarica aggiornamento', 'wp-gpt-automation-pro') . '</a></div>';
            }
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
    }

}

// Inizializza la classe
WP_GPT_Custom_Admin_Settings::init();
?>
