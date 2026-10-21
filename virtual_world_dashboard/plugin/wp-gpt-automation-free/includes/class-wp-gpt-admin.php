<?php

namespace WP_GPT_Automation_Pro;

use DateTimeZone;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class WP_GPT_Admin_Settings {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('network_admin_menu', [__CLASS__, 'add_network_admin_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
        add_action('rest_api_init', [__CLASS__, 'register_memory_manager_rest_routes']);
        \add_action('wp_ajax_regenerate_user_code', function() {
            if (!\current_user_can('manage_options')) {
                \wp_send_json_error('Non autorizzato');
            }
        
            $nonce = $_REQUEST['_ajax_nonce'] ?? $_REQUEST['_wpnonce'] ?? $_REQUEST['nonce'] ?? '';
            if (!\wp_verify_nonce($nonce, 'regenerate_token_nonce')) {
                \wp_send_json_error('Nonce non valido (user_code). Ricevuto: ' . $nonce);
            }
        
            $new_user_code = WP_GPT_Admin_Settings::regenerate_user_code();
            \wp_send_json_success($new_user_code);
        });
        
        
    }

    public static function add_admin_menu() {
        add_menu_page(
            __('www gpt automation', 'wp-gpt-automation-pro'),
            __('www gpt automation', 'wp-gpt-automation-pro'),
            'manage_options',
            'wp-gpt-automation-pro',
            [__CLASS__, 'render_admin_page'],
            WP_GPT_PLUGIN_URL . 'assets/wp-gpt-automation-menu.svg'
        );

        if (WP_GPT_Menu_Visibility::is_page_enabled(WP_GPT_Menu_Visibility::MENU_AUTOMATION_PRO, 'wp-gpt-queue')) {
            add_submenu_page(
                'wp-gpt-automation-pro',
                __('Coda GPT', 'wp-gpt-automation-pro'),
                __('Gestione Coda', 'wp-gpt-automation-pro'),
                'manage_options',
                'wp-gpt-queue',
                [__CLASS__, 'render_queue_page']
            );
        }
    }

    public static function add_network_admin_menu() {
        add_menu_page(
            __('www gpt automation (network)', 'wp-gpt-automation-pro'),
            __('www gpt automation', 'wp-gpt-automation-pro'),
            'manage_network_options',
            'wp-gpt-automation-pro-network',
            [__CLASS__, 'render_network_admin_page'],
            WP_GPT_PLUGIN_URL . 'assets/wp-gpt-automation-menu.svg'
        );
    }

    public static function register_settings() {
        register_setting('wp_gpt_settings', 'wp_gpt_api_token');
        register_setting('wp_gpt_settings', 'wp_gpt_openai_key');
        register_setting('wp_gpt_settings', 'wp_gpt_temperature');
        register_setting('wp_gpt_settings', 'wp_gpt_max_tokens');
        register_setting('wp_gpt_settings', 'wp_gpt_hix_api_key');
        register_setting('wp_gpt_settings', 'wp_gpt_hix_endpoint');
        register_setting('wp_gpt_settings', 'wp_gpt_hix_mode');
        register_setting('wp_gpt_settings', 'wp_gpt_hix_word_limit');
        register_setting('wp_gpt_settings', 'wp_gpt_openai_vector_store_id');
        register_setting('wp_gpt_settings', 'wp_gpt_mcp_server_label');
        register_setting(
            'wp_gpt_settings',
            'wp_gpt_enable_oauth_mcp_endpoints',
            [
                'type'              => 'boolean',
                'sanitize_callback' => [__CLASS__, 'sanitize_sensitive_endpoint_toggle'],
                'default'           => 0,
            ]
        );
        register_setting(
            'wp_gpt_settings',
            'wp_gpt_sensitive_endpoints_timeout_hours',
            [
                'type'              => 'integer',
                'sanitize_callback' => [__CLASS__, 'sanitize_sensitive_endpoint_timeout'],
                'default'           => 1,
            ]
        );
        // Registriamo le nuove impostazioni per Telegram
        register_setting('wp_gpt_settings', 'wp_gpt_telegram_bot_token');
        register_setting('wp_gpt_settings', 'wp_gpt_telegram_group_id');
        register_setting('wp_gpt_settings', 'minecraft_bearer_code');
        register_setting('wp_gpt_settings', 'minecraft_server_port');
        register_setting('wp_gpt_settings', 'minecraft_server_hostname');
register_setting('wp_gpt_settings', 'wp_gpt_usa_proxy');  // Flag per l'uso del proxy
register_setting('wp_gpt_settings', 'wp_gpt_proxy_endpoint'); // Endpoint del proxy
register_setting('wp_gpt_settings', 'wp_gpt_proxy_port');  // Porta del proxy
register_setting('wp_gpt_settings', 'wp_gpt_proxy_timeout');  // Timeout del proxy
register_setting('wp_gpt_settings', 'wp_gpt_proxy_protocol');  // Timeout del proxy
register_setting('wp_gpt_settings', 'wp_gpt_custom_user_agent'); // user_agent
register_setting('wp_gpt_settings', 'wp_gpt_usa_autenticazione');  // Flag per l'autenticazione del proxy
register_setting('wp_gpt_settings', 'wp_gpt_proxy_username');  // Username per il proxy
register_setting('wp_gpt_settings', 'wp_gpt_proxy_password');  // Password per il proxy
        add_settings_section('wp_gpt_main_section', __('Impostazioni API GPT', 'wp-gpt-automation-pro'), null, 'wp_gpt_automation_pro');
    // Aggiungi le nuove impostazioni per le API
    register_setting('wp_gpt_settings', 'wp_gpt_youtube_api_key');
    register_setting('wp_gpt_settings', 'wp_gpt_youtube_transcription_api_key');  // Nuova chiave per le trascrizioni YouTube



    register_setting('wp_gpt_settings', 'wp_gpt_wikipedia_api_key');
        register_setting('wp_gpt_settings', 'wp_gpt_encryption_key');
        register_setting('wp_gpt_settings', 'wp_gpt_key_regeneration_interval');
        register_setting('wp_gpt_settings', 'wp_gpt_user_code'); // Nuova impostazione per lo user_code
        register_setting(
            'wp_gpt_settings',
            'wp_gpt_user_code_admin_id',
            [
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 0,
            ]
        );
        register_setting(
            'wp_gpt_settings',
            'wp_gpt_custom_header_enforcement',
            [
                'type'              => 'boolean',
                'sanitize_callback' => [__CLASS__, 'sanitize_boolean_option'],
                'default'           => 0,
            ]
        );
        register_setting(
            'wp_gpt_settings',
            'wp_gpt_custom_header_key',
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_header_key'],
                'default'           => '',
            ]
        );
        register_setting(
            'wp_gpt_settings',
            'wp_gpt_custom_header_value',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );

    
    // Nuove sezioni e campi per la chiave di criptazione
    add_settings_section('wp_gpt_encryption_section', __('Impostazioni Criptazione', 'wp-gpt-automation-pro'), null, 'wp_gpt_automation_pro');
    // Aggiunta dei nuovi campi di input per Minecraft
    add_settings_field(
        'minecraft_bearer_code',
        __('Minecraft Bearer Code', 'wp-gpt-automation-pro'),
        [__CLASS__, 'render_minecraft_bearer_code_field'],
        'wp_gpt_automation_pro',
        'wp_gpt_main_section'
    );



    add_settings_field(
        'wp_gpt_key_regeneration_interval',
        __('Intervallo di Rigenerazione (in ore)', 'wp-gpt-automation-pro'),
        [__CLASS__, 'render_key_regeneration_interval_field'],
        'wp_gpt_automation_pro',
        'wp_gpt_encryption_section'
    );
            // Aggiungi il campo per lo user_code
            add_settings_field(
                'wp_gpt_user_code',
                __('Codice Utente GPT', 'wp-gpt-automation-pro'),
                [__CLASS__, 'render_user_code_field'],
                'wp_gpt_automation_pro',
                'wp_gpt_main_section'
            );
        add_settings_field(
            'wp_gpt_custom_header_enforcement',
            __('Richiedi header personalizzato', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_custom_header_toggle_field'],
            'wp_gpt_automation_pro',
            'wp_gpt_main_section'
        );
        add_settings_field(
            'wp_gpt_custom_header_key',
            __('Chiave header personalizzato', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_custom_header_key_field'],
            'wp_gpt_automation_pro',
            'wp_gpt_main_section'
        );
        add_settings_field(
            'wp_gpt_custom_header_value',
            __('Valore header personalizzato', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_custom_header_value_field'],
            'wp_gpt_automation_pro',
            'wp_gpt_main_section'
        );
        add_settings_field(
            'wp_gpt_enable_oauth_mcp_endpoints',
            __('Endpoint OAuth/MCP temporanei', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_disable_oauth_mcp_field'],
            'wp_gpt_automation_pro',
            'wp_gpt_main_section'
        );
    // Aggiungi le nuove sezioni e campi
    add_settings_section('wp_gpt_api_keys_section', __('Impostazioni Chiavi API', 'wp-gpt-automation-pro'), null, 'wp_gpt_automation_pro');

    add_settings_field(
        'wp_gpt_youtube_api_key',
        __('Chiave Rapid API YouTube', 'wp-gpt-automation-pro'),
        [__CLASS__, 'render_youtube_api_key_field'],
        'wp_gpt_automation_pro',
        'wp_gpt_api_keys_section'
    );

    add_settings_field(
        'wp_gpt_youtube_transcription_api_key',
        __('Chiave API per le Trascrizioni YouTube', 'wp-gpt-automation-pro'),
        [__CLASS__, 'render_youtube_transcription_api_key_field'],
        'wp_gpt_automation_pro',
        'wp_gpt_api_keys_section'
    );
    add_settings_field(
        'wp_gpt_wikipedia_api_key',
        __('Chiave API Wikipedia', 'wp-gpt-automation-pro'),
        [__CLASS__, 'render_wikipedia_api_key_field'],
        'wp_gpt_automation_pro',
        'wp_gpt_api_keys_section'
    );

        add_settings_field(
            'wp_gpt_api_token',
            __('Token API', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_api_token_field'],
            'wp_gpt_automation_pro',
            'wp_gpt_main_section'
        );

        add_settings_section('wp_hix_main_section', __('Impostazioni HIX AI', 'wp-gpt-automation-pro'), null, 'wp_gpt_automation_pro');

        add_settings_field(
            'wp_gpt_hix_api_key',
            __('API Key HIX', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_hix_api_key_field'],
            'wp_gpt_automation_pro',
            'wp_hix_main_section'
        );

        add_settings_field(
            'wp_gpt_hix_endpoint',
            __('Endpoint HIX', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_hix_endpoint_field'],
            'wp_gpt_automation_pro',
            'wp_hix_main_section'
        );

        add_settings_field(
            'wp_gpt_hix_mode',
            __('Modalità HIX', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_hix_mode_field'],
            'wp_gpt_automation_pro',
            'wp_hix_main_section'
        );

        add_settings_field(
            'wp_gpt_hix_word_limit',
            __('Limite Parole per Richiesta', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_hix_word_limit_field'],
            'wp_gpt_automation_pro',
            'wp_hix_main_section'
        );
        add_settings_field(
            'wp_gpt_telegram_bot_token',
            __('Telegram Bot Token', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_telegram_bot_token_field'],
            'wp_gpt_automation_pro',
            'wp_gpt_api_keys_section'
        );

        add_settings_field(
            'wp_gpt_telegram_group_id',
            __('Telegram Group ID', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_telegram_group_id_field'],
            'wp_gpt_automation_pro',
            'wp_gpt_api_keys_section'
        );


        add_settings_field(
            'minecraft_server_port',
            __('Minecraft Server Port', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_minecraft_server_port_field'],
            'wp_gpt_automation_pro',
            'wp_gpt_main_section'
        );
    
        add_settings_field(
            'minecraft_server_hostname',
            __('Minecraft Server Hostname', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_minecraft_server_hostname_field'],
            'wp_gpt_automation_pro',
            'wp_gpt_main_section'
        );
        add_settings_field(
            'wp_gpt_encryption_key',
            __('Chiave di Criptazione', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_encryption_key_field'],
            'wp_gpt_automation_pro',
            'wp_gpt_encryption_section'
        );
        // Aggiunta dei campi alla sezione principale delle impostazioni
add_settings_section('wp_gpt_proxy_section', __('Impostazioni Proxy', 'wp-gpt-automation-pro'), null, 'wp_gpt_automation_pro');
add_settings_field(
    'wp_gpt_custom_user_agent',
    __('Custom User Agent', 'wp-gpt-automation-pro'),
    [__CLASS__, 'render_custom_user_agent'],
    'wp_gpt_automation_pro',
    'wp_gpt_proxy_section'
);
// Aggiunta dei campi per le impostazioni del proxy e dell'autenticazione
add_settings_field(
    'wp_gpt_usa_proxy',
    __('Usa Proxy', 'wp-gpt-automation-pro'),
    [__CLASS__, 'render_usa_proxy_field'],
    'wp_gpt_automation_pro',
    'wp_gpt_proxy_section'
);
add_settings_field(
    'wp_gpt_proxy_protocol',
    __('Protocol Proxy', 'wp-gpt-automation-pro'),
    [__CLASS__, 'render_protocol_proxy_field'],
    'wp_gpt_automation_pro',
    'wp_gpt_proxy_section'
);
add_settings_field(
    'wp_gpt_proxy_endpoint',
    __('Endpoint Proxy', 'wp-gpt-automation-pro'),
    [__CLASS__, 'render_proxy_endpoint_field'],
    'wp_gpt_automation_pro',
    'wp_gpt_proxy_section'
);

add_settings_field(
    'wp_gpt_proxy_port',
    __('Porta Proxy', 'wp-gpt-automation-pro'),
    [__CLASS__, 'render_proxy_port_field'],
    'wp_gpt_automation_pro',
    'wp_gpt_proxy_section'
);

add_settings_field(
    'wp_gpt_proxy_timeout',
    __('Timeout Proxy (secondi)', 'wp-gpt-automation-pro'),
    [__CLASS__, 'render_proxy_timeout_field'],
    'wp_gpt_automation_pro',
    'wp_gpt_proxy_section'
);

add_settings_field(
    'wp_gpt_usa_autenticazione',
    __('Usa Autenticazione Proxy', 'wp-gpt-automation-pro'),
    [__CLASS__, 'render_usa_autenticazione_field'],
    'wp_gpt_automation_pro',
    'wp_gpt_proxy_section'
);

add_settings_field(
    'wp_gpt_proxy_username',
    __('Username Proxy', 'wp-gpt-automation-pro'),
    [__CLASS__, 'render_proxy_username_field'],
    'wp_gpt_automation_pro',
    'wp_gpt_proxy_section'
);


add_settings_field(
    'wp_gpt_proxy_password',
    __('Password Proxy', 'wp-gpt-automation-pro'),
    [__CLASS__, 'render_proxy_password_field'],
    'wp_gpt_automation_pro',
    'wp_gpt_proxy_section'
);

    }
    // Aggiunta dei metodi di rendering per i nuovi campi
public static function render_usa_proxy_field() {
    $usa_proxy = get_option('wp_gpt_usa_proxy');
    echo '<input type="checkbox" name="wp_gpt_usa_proxy" value="1" ' . checked(1, $usa_proxy, false) . ' />';
}

public static function render_proxy_endpoint_field() {
    $proxy_endpoint = get_option('wp_gpt_proxy_endpoint');
    echo '<input type="text" name="wp_gpt_proxy_endpoint" value="' . esc_attr($proxy_endpoint) . '" class="regular-text" />';
}
public static function render_custom_user_agent() {
    $custom_user_agent = get_option('wp_gpt_custom_user_agent');
    echo '<input type="text" name="wp_gpt_custom_user_agent" value="' . esc_attr($custom_user_agent) . '" class="regular-text" />';
}
public static function render_proxy_port_field() {
    $proxy_port = get_option('wp_gpt_proxy_port');
    echo '<input type="number" name="wp_gpt_proxy_port" value="' . esc_attr($proxy_port) . '" class="small-text" />';
}

public static function render_proxy_timeout_field() {
    // Recupera il timeout proxy con valore predefinito di 30 secondi se non è impostato
    $proxy_timeout = get_option('wp_gpt_proxy_timeout', 30);
    echo '<label for="wp_gpt_proxy_timeout">' . __('Timeout Proxy (secondi)', 'wp-gpt-automation-pro') . '</label>';
    echo '<input type="number" name="wp_gpt_proxy_timeout" id="wp_gpt_proxy_timeout" value="' . esc_attr($proxy_timeout) . '" class="small-text" />';
}

public static function render_usa_autenticazione_field() {
    $usa_autenticazione = get_option('wp_gpt_usa_autenticazione');
    echo '<input type="checkbox" name="wp_gpt_usa_autenticazione" value="1" ' . checked(1, $usa_autenticazione, false) . ' />';
}

public static function render_proxy_username_field() {
    $proxy_username = get_option('wp_gpt_proxy_username');
    echo '<input type="text" name="wp_gpt_proxy_username" value="' . esc_attr($proxy_username) . '" class="regular-text" />';
}

public static function render_proxy_password_field() {
    $proxy_password = get_option('wp_gpt_proxy_password');
    echo '<input type="password" name="wp_gpt_proxy_password" value="' . esc_attr($proxy_password) . '" class="regular-text" />';
}
public static function render_protocol_proxy_field() {
    // Recupera il protocollo proxy con valore predefinito 'http' se non è impostato
    $proxy_protocol = get_option('wp_gpt_proxy_protocol', 'http'); 
    echo '<label for="wp_gpt_proxy_protocol">' . __('Protocollo Proxy', 'wp-gpt-automation-pro') . '</label>';
    echo '<select name="wp_gpt_proxy_protocol" id="wp_gpt_proxy_protocol" class="regular-text">';
    echo '<option value="http"' . selected($proxy_protocol, 'http', false) . '>HTTP</option>';
    echo '<option value="https"' . selected($proxy_protocol, 'https', false) . '>HTTPS</option>';
    echo '<option value="socks5"' . selected($proxy_protocol, 'socks5', false) . '>SOCKS5</option>';
    echo '</select>';
}

    public static function regenerate_user_code() {
        $new_user_code = wp_generate_password(32, false);  // Genera un nuovo user code
        update_option('wp_gpt_user_code', $new_user_code);  // Salva il nuovo user code nel database
    
        // Invia una notifica via email all'admin
        $admin_email = get_option('admin_email');
        try {
            \wp_mail($admin_email, 'Codice Utente GPT Rigenerato', 'Il codice utente GPT è stato rigenerato con successo.');
        } catch (\Throwable $e) {
            error_log('WP_GPT_Admin_Settings: Error sending email: ' . $e->getMessage());
        }
    
        return $new_user_code;  // Ritorna il nuovo codice generato
    }


// Rendering dei campi per Minecraft
public static function render_minecraft_bearer_code_field() {
    $code = get_option('minecraft_bearer_code');
    echo '<input type="text" name="minecraft_bearer_code" value="' . esc_attr($code) . '" class="regular-text" />';
}

public static function render_minecraft_server_port_field() {
    $port = get_option('minecraft_server_port');
    echo '<input type="number" name="minecraft_server_port" value="' . esc_attr($port) . '" class="small-text" />';
}

public static function render_minecraft_server_hostname_field() {
    $hostname = get_option('minecraft_server_hostname');
    echo '<input type="text" name="minecraft_server_hostname" value="' . esc_attr($hostname) . '" class="regular-text" />';
}

    // Campo per la chiave API dedicata alle trascrizioni YouTube
public static function render_youtube_transcription_api_key_field() {
    $key = get_option('wp_gpt_youtube_transcription_api_key'); // Recupera la chiave di trascrizione
    echo '<input type="text" name="wp_gpt_youtube_transcription_api_key" value="' . esc_attr($key) . '" class="regular-text" />';
    $rapidapi_link_open   = '<a href="' . esc_url('https://rapidapi.com/') . '" target="_blank" rel="noopener noreferrer">';
    $rapidapi_link_close  = '</a>';
    $service_link_open    = '<a href="' . esc_url('https://rapidapi.com/8v2FWW4H6AmKw89/api/YouTube%20Transcripts') . '" target="_blank" rel="noopener noreferrer">';
    $service_link_close   = '</a>';
    $applications_open    = '<a href="' . esc_url('https://rapidapi.com/console/9722497/applications') . '" target="_blank" rel="noopener noreferrer">';
    $applications_close   = '</a>';

    $subscribe_description = sprintf(
        /* translators: 1: opening RapidAPI link, 2: closing RapidAPI link, 3: opening service link, 4: closing service link */
        __('Registra un account su %1$sRapidAPI%2$s. Apri la pagina del servizio %3$sYouTube Transcripts%4$s, clicca sul pulsante "Subscribe" in alto a destra e scegli il piano gratuito.', 'wp-gpt-automation-pro'),
        $rapidapi_link_open,
        $rapidapi_link_close,
        $service_link_open,
        $service_link_close
    );

    $applications_description = sprintf(
        /* translators: 1: opening RapidAPI applications link, 2: closing RapidAPI applications link */
        __('Dopo la sottoscrizione, clicca sull\'immagine del profilo in alto a destra, vai nella sezione %1$sApplications%2$s, crea una nuova application e copia la chiave API da incollare qui.', 'wp-gpt-automation-pro'),
        $applications_open,
        $applications_close
    );

    echo '<p class="description">' . wp_kses_post($subscribe_description) . '</p>';
    echo '<p class="description">' . wp_kses_post($applications_description) . '</p>';
}
    
    public static function render_user_code_field() {
        $user_code           = get_option('wp_gpt_user_code', '');  // Recupera lo user_code memorizzato
        $assigned_admin_id   = (int) get_option('wp_gpt_user_code_admin_id', 0);
        $admin_query         = new \WP_User_Query([
            'role__in' => ['administrator'],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ]);
        $administrators      = $admin_query->get_results();

        echo '<div class="wp-gpt-user-code-settings">';
        echo '<input type="text" name="wp_gpt_user_code" value="' . esc_attr($user_code) . '" class="regular-text wp-gpt-user-code-input" />';

        if (!empty($administrators)) {
            echo '<select name="wp_gpt_user_code_admin_id" class="wp-gpt-user-code-select">';
            echo '<option value="0"' . selected($assigned_admin_id, 0, false) . '>' . esc_html__('Seleziona amministratore', 'wp-gpt-automation-pro') . '</option>';

            foreach ($administrators as $admin) {
                $admin_id    = is_object($admin) && isset($admin->ID) ? (int) $admin->ID : (int) $admin;
                $label_parts = [];

                if (is_object($admin)) {
                    if (!empty($admin->display_name)) {
                        $label_parts[] = $admin->display_name;
                    } elseif (!empty($admin->user_login)) {
                        $label_parts[] = $admin->user_login;
                    }

                    if (!empty($admin->user_email)) {
                        $label_parts[] = $admin->user_email;
                    }
                }

                if (empty($label_parts)) {
                    $label_parts[] = sprintf(esc_html__('Amministratore #%d', 'wp-gpt-automation-pro'), $admin_id);
                }

                $label = implode(' – ', array_map('esc_html', $label_parts));

                echo '<option value="' . esc_attr($admin_id) . '"' . selected($assigned_admin_id, $admin_id, false) . '>' . $label . '</option>';
            }

            echo '</select>';
        }

        echo '</div>';

        echo '<p class="description">' . esc_html__('Codice utente GPT associato all\'account.', 'wp-gpt-automation-pro') . '</p>';

        if (!empty($administrators)) {
            echo '<p class="description">' . esc_html__('Seleziona l\'amministratore che dovrà essere associato al codice utente durante le operazioni automatiche.', 'wp-gpt-automation-pro') . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('Nessun amministratore disponibile. Crea un nuovo account amministratore per associarlo al codice utente.', 'wp-gpt-automation-pro') . '</p>';
        }

        echo '<p><button type="button" class="button" id="regenerate-user-code">' . esc_html__('Rigenera Codice Utente', 'wp-gpt-automation-pro') . '</button></p>';
    }
    
    
    public static function render_encryption_key_field() {
        $key = get_option('wp_gpt_encryption_key');
        echo '<input type="text" name="wp_gpt_encryption_key" value="' . esc_attr($key) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('La chiave di criptazione utilizzata per criptare i dati.', 'wp-gpt-automation-pro') . '</p>';
    }
    
    public static function render_key_regeneration_interval_field() {
        $interval = get_option('wp_gpt_key_regeneration_interval', 24); // Default a 24 ore
        echo '<input type="number" name="wp_gpt_key_regeneration_interval" value="' . esc_attr($interval) . '" class="small-text" />';
        echo '<p class="description">' . esc_html__('Imposta l\'intervallo di tempo in ore per la rigenerazione automatica della chiave di criptazione.', 'wp-gpt-automation-pro') . '</p>';
    }
    

public static function render_youtube_api_key_field() {
    $key = get_option('wp_gpt_youtube_api_key');
    echo '<input type="text" name="wp_gpt_youtube_api_key" value="' . esc_attr($key) . '" class="regular-text" />';
    $rapidapi_link_open   = '<a href="' . esc_url('https://rapidapi.com/') . '" target="_blank" rel="noopener noreferrer">';
    $rapidapi_link_close  = '</a>';
    $service_link_open    = '<a href="' . esc_url('https://rapidapi.com/ytjar/api/yt-api') . '" target="_blank" rel="noopener noreferrer">';
    $service_link_close   = '</a>';
    $applications_open    = '<a href="' . esc_url('https://rapidapi.com/console/9722497/applications') . '" target="_blank" rel="noopener noreferrer">';
    $applications_close   = '</a>';

    $subscribe_description = sprintf(
        /* translators: 1: opening RapidAPI link, 2: closing RapidAPI link, 3: opening service link, 4: closing service link */
        __('Registra un account su %1$sRapidAPI%2$s. Apri la pagina del servizio %3$sYT API%4$s, clicca sul pulsante "Subscribe" in alto a destra e scegli il piano gratuito.', 'wp-gpt-automation-pro'),
        $rapidapi_link_open,
        $rapidapi_link_close,
        $service_link_open,
        $service_link_close
    );

    $applications_description = sprintf(
        /* translators: 1: opening RapidAPI applications link, 2: closing RapidAPI applications link */
        __('Dopo la sottoscrizione, clicca sull\'immagine del profilo in alto a destra, vai nella sezione %1$sApplications%2$s, crea una nuova application e copia la chiave API da incollare qui.', 'wp-gpt-automation-pro'),
        $applications_open,
        $applications_close
    );

    echo '<p class="description">' . wp_kses_post($subscribe_description) . '</p>';
    echo '<p class="description">' . wp_kses_post($applications_description) . '</p>';
}

public static function render_wikipedia_api_key_field() {
    $key = get_option('wp_gpt_wikipedia_api_key');
    echo '<input type="text" name="wp_gpt_wikipedia_api_key" value="' . esc_attr($key) . '" class="regular-text" />';
}
public static function render_admin_page() {
        if (
            isset($_POST['wp_gpt_menu_visibility_action'])
            && 'save' === $_POST['wp_gpt_menu_visibility_action']
        ) {
            check_admin_referer('wp_gpt_menu_visibility');

            $submitted = isset($_POST['wp_gpt_visible_pages'])
                ? array_map('sanitize_text_field', (array) wp_unslash($_POST['wp_gpt_visible_pages']))
                : [];

            WP_GPT_Menu_Visibility::save_menu_visibility(WP_GPT_Menu_Visibility::MENU_AUTOMATION_PRO, $submitted);

            add_settings_error(
                'wp_gpt_menu_visibility',
                'menu_visibility_saved',
                __('Visibilità delle pagine aggiornata.', 'wp-gpt-automation-pro'),
                'updated'
            );
        }

        $menu_pages      = WP_GPT_Menu_Visibility::get_menu_pages(WP_GPT_Menu_Visibility::MENU_AUTOMATION_PRO);
        $menu_visibility = WP_GPT_Menu_Visibility::get_menu_visibility(WP_GPT_Menu_Visibility::MENU_AUTOMATION_PRO);

        echo '<div class="wrap wp-gpt-admin-page">';

        echo '<div class="wp-gpt-card">';
        echo '<div class="wp-gpt-card-header">';
        echo '<h1>' . esc_html__('GPT Automation Pro', 'wp-gpt-automation-pro') . '</h1>';
        echo '<p>' . esc_html__('Configura le API, le chiavi di accesso e le integrazioni principali del plugin da un\'unica schermata.', 'wp-gpt-automation-pro') . '</p>';
        echo '</div>';
        echo '<div class="wp-gpt-card-body">';
        echo '<form method="post" action="options.php" class="wp-gpt-card-form">';
        settings_fields('wp_gpt_settings');
        do_settings_sections('wp_gpt_automation_pro');
        submit_button();
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<div class="wp-gpt-card">';
        echo '<div class="wp-gpt-card-header">';
        echo '<h2>' . esc_html__('Gestione pagine secondarie', 'wp-gpt-automation-pro') . '</h2>';
        echo '<p>' . esc_html__('Attiva o disattiva la visualizzazione delle pagine secondarie nel menu principale del plugin.', 'wp-gpt-automation-pro') . '</p>';
        echo '</div>';
        echo '<div class="wp-gpt-card-body">';
        settings_errors('wp_gpt_menu_visibility');
        echo '<form method="post" class="wp-gpt-card-form">';
        wp_nonce_field('wp_gpt_menu_visibility');
        echo '<input type="hidden" name="wp_gpt_menu_visibility_action" value="save">';
        echo '<table class="form-table">';
        foreach ($menu_pages as $slug => $data) {
            $checked = !empty($menu_visibility[$slug]);
            echo '<tr>';
            $label = isset($data['label'])
                ? esc_html__($data['label'], 'wp-gpt-automation-pro')
                : esc_html($slug);
            $path  = isset($data['path']) ? $data['path'] : '';
            echo '<th scope="row">' . $label;
            if ($path) {
                echo '<br><code>' . esc_html($path) . '</code>';
            }
            echo '</th>';
            echo '<td>';
            echo '<label>';
            echo '<input type="checkbox" name="wp_gpt_visible_pages[]" value="' . esc_attr($slug) . '"' . checked($checked, true, false) . '> ';
            echo esc_html__('Visualizza nel menu', 'wp-gpt-automation-pro');
            echo '</label>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
        submit_button(__('Salva visibilità', 'wp-gpt-automation-pro'));
        echo '</form>';
        echo '<p class="description">' . esc_html__('Alcune pagine fondamentali, come le impostazioni principali e la guida agli schema, rimangono sempre disponibili.', 'wp-gpt-automation-pro') . '</p>';
        echo '</div>';
        echo '</div>';

        global $wpdb;
        $table = $wpdb->prefix . 'gpt_placeholders';
        $placeholders = $wpdb->get_results("SELECT fake_value, context FROM $table", ARRAY_A);

        echo '<div class="wp-gpt-card">';
        echo '<div class="wp-gpt-card-header">';
        echo '<h2>' . esc_html__('Placeholders disponibili', 'wp-gpt-automation-pro') . '</h2>';
        echo '<p>' . esc_html__('Inserisci questi placeholder nelle tue azioni per personalizzare le risposte automatiche.', 'wp-gpt-automation-pro') . '</p>';
        echo '</div>';
        echo '<div class="wp-gpt-card-body">';
        if ($placeholders) {
            echo '<ul class="wp-gpt-badge-list">';
            foreach ($placeholders as $ph) {
                $ctx = $ph['context'] ? '<span>' . esc_html($ph['context']) . '</span>' : '';
                echo '<li><code>' . esc_html($ph['fake_value']) . '</code>' . $ctx . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="wp-gpt-empty">' . esc_html__('Nessun placeholder definito.', 'wp-gpt-automation-pro') . '</p>';
        }
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    public static function render_custom_header_toggle_field() {
        $enabled = (bool) get_option('wp_gpt_custom_header_enforcement', false);

        echo '<label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<input type="checkbox" name="wp_gpt_custom_header_enforcement" value="1"' . checked($enabled, true, false) . ' /> ';
        echo esc_html__('Richiedi un header personalizzato oltre al token Bearer per le API.', 'wp-gpt-automation-pro');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Se attivato, tutte le richieste REST devono includere l\'intestazione personalizzata configurata qui sotto.', 'wp-gpt-automation-pro') . '</p>';
    }

    public static function render_custom_header_key_field() {
        $header_key = get_option('wp_gpt_custom_header_key', '');
        echo '<input type="text" name="wp_gpt_custom_header_key" value="' . esc_attr($header_key) . '" class="regular-text" placeholder="X-Custom-Auth" />';
        echo '<p class="description">' . esc_html__('Nome dell\'header HTTP da richiedere quando il controllo è attivo.', 'wp-gpt-automation-pro') . '</p>';
    }

    public static function render_custom_header_value_field() {
        $header_value = get_option('wp_gpt_custom_header_value', '');
        echo '<input type="text" name="wp_gpt_custom_header_value" value="' . esc_attr($header_value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Valore esatto che il middleware deve inviare per l\'header personalizzato.', 'wp-gpt-automation-pro') . '</p>';
    }

    public static function render_network_admin_page() {
        echo '<div class="wrap wp-gpt-admin-page">';
        echo '<div class="wp-gpt-card">';
        echo '<div class="wp-gpt-card-header">';
        echo '<h1>' . esc_html__('GPT Automation Pro (Network)', 'wp-gpt-automation-pro') . '</h1>';
        echo '<p>' . esc_html__('Gestisci le impostazioni condivise per tutti i siti della rete WordPress.', 'wp-gpt-automation-pro') . '</p>';
        echo '</div>';
        echo '<div class="wp-gpt-card-body">';
        echo '<form method="post" action="edit.php?action=wp_gpt_automation_pro_network_options" class="wp-gpt-card-form">';
        settings_fields('wp_gpt_network_settings');
        do_settings_sections('wp_gpt_automation_pro_network');
        submit_button();
        echo '</form>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    public static function render_telegram_bot_token_field() {
        $token = get_option('wp_gpt_telegram_bot_token');
        echo '<input type="text" name="wp_gpt_telegram_bot_token" value="' . esc_attr($token) . '" class="regular-text" />';
    }

    public static function render_telegram_group_id_field() {
        $group_id = get_option('wp_gpt_telegram_group_id');
        echo '<input type="text" name="wp_gpt_telegram_group_id" value="' . esc_attr($group_id) . '" class="regular-text" />';
    }
    public static function render_queue_page() {
        global $wpdb;
        echo '<div class="wrap wp-gpt-admin-page">';

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non hai i permessi per accedere a questa pagina.', 'wp-gpt-automation-pro'));
        }

        $scheduler_table = $wpdb->prefix . 'gpt_scheduler_jobs';
        $notice_message  = '';
        $notice_class    = 'notice-success';

        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['wp_gpt_manage_queue_nonce'])) {
            if (!check_admin_referer('wp_gpt_manage_queue', 'wp_gpt_manage_queue_nonce')) {
                $notice_message = esc_html__('Impossibile verificare la richiesta. Riprova.', 'wp-gpt-automation-pro');
                $notice_class   = 'notice-error';
            } else {
                $deleted_ids = [];

                if (isset($_POST['wp_gpt_run_job'])) {
                    $job_key = isset($_POST['wp_gpt_run_job']) ? sanitize_text_field(wp_unslash($_POST['wp_gpt_run_job'])) : '';

                    if ($job_key !== '') {
                        global $wp_gpt_v1_route_scheduler;
                        if (!($wp_gpt_v1_route_scheduler instanceof WP_GPT_V1_Route_Scheduler)) {
                            $wp_gpt_v1_route_scheduler = new WP_GPT_V1_Route_Scheduler();
                        }

                        $execution = $wp_gpt_v1_route_scheduler->execute_job($job_key);

                        if (is_wp_error($execution)) {
                            $notice_message = $execution->get_error_message();
                            $notice_class   = 'notice-error';
                        } else {
                            $last_run = isset($execution['last_run']) ? sanitize_text_field($execution['last_run']) : '-';
                            if (!empty($execution['error'])) {
                                $notice_message = sprintf(
                                    /* translators: 1: job key, 2: error message */
                                    __('Errore durante l\'esecuzione del job %1$s: %2$s', 'wp-gpt-automation-pro'),
                                    $job_key,
                                    sanitize_text_field($execution['error'])
                                );
                                $notice_class = 'notice-error';
                            } else {
                                $notice_message = sprintf(
                                    /* translators: 1: job key, 2: last run datetime */
                                    __('Job %1$s eseguito con successo. Ultima esecuzione: %2$s.', 'wp-gpt-automation-pro'),
                                    $job_key,
                                    $last_run
                                );
                                $notice_class = 'notice-success';
                            }
                        }
                    } else {
                        $notice_message = __('Impossibile eseguire il job: chiave non valida.', 'wp-gpt-automation-pro');
                        $notice_class   = 'notice-error';
                    }
                } else {
                    if (isset($_POST['wp_gpt_delete_single'])) {
                        $job_id = absint($_POST['wp_gpt_delete_single']);
                        if ($job_id > 0) {
                            $deleted = $wpdb->delete($scheduler_table, ['id' => $job_id], ['%d']);
                            if (false !== $deleted && $deleted > 0) {
                                $deleted_ids[] = $job_id;
                            }
                        }
                    }

                    if (isset($_POST['wp_gpt_delete_selected'])) {
                        $job_ids = isset($_POST['job_ids']) ? array_map('absint', (array) $_POST['job_ids']) : [];
                        $job_ids = array_filter($job_ids);

                        if (!empty($job_ids)) {
                            foreach ($job_ids as $job_id) {
                                $deleted = $wpdb->delete($scheduler_table, ['id' => $job_id], ['%d']);
                                if (false !== $deleted && $deleted > 0) {
                                    $deleted_ids[] = $job_id;
                                }
                            }
                        }
                    }

                    if (!empty($deleted_ids)) {
                        $count = count($deleted_ids);
                        /* translators: %s: number of deleted jobs */
                        $notice_message = sprintf(_n('%s job eliminato.', '%s job eliminati.', $count, 'wp-gpt-automation-pro'), number_format_i18n($count));
                        $notice_class   = 'notice-success';
                    } elseif (isset($_POST['wp_gpt_delete_single']) || isset($_POST['wp_gpt_delete_selected'])) {
                        $notice_message = esc_html__('Nessun job eliminato.', 'wp-gpt-automation-pro');
                        $notice_class   = 'notice-warning';
                    }
                }
            }
        }

        $per_page     = apply_filters('wp_gpt_queue_jobs_per_page', 20);
        $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $total_items  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $scheduler_table");
        $total_pages  = max(1, (int) ceil($total_items / $per_page));
        if ($current_page > $total_pages) {
            $current_page = $total_pages;
        }
        $offset = ($current_page - 1) * $per_page;

        $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $scheduler_table ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset));

        $job_ids = $jobs ? array_map('intval', wp_list_pluck($jobs, 'id')) : [];
        if (wp_script_is('wp-gpt-admin-js', 'enqueued')) {
            $pagination_payload = [
                'jobIds'      => $job_ids,
                'currentPage' => $current_page,
                'totalPages'  => $total_pages,
            ];
            wp_localize_script('wp-gpt-admin-js', 'wpGPTAdminQueue', $pagination_payload);
        }

        $base_url = remove_query_arg('paged');
        $pagination_links = paginate_links([
            'base'      => $base_url ? add_query_arg('paged', '%#%', $base_url) : add_query_arg('paged', '%#%'),
            'format'    => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total'     => $total_pages,
            'current'   => $current_page,
            'type'      => 'array',
            'add_args'  => false,
        ]);

        $pagination_html = '';
        if (!empty($pagination_links)) {
            $pagination_html = '<div class="tablenav-pages">' . implode('', array_map('wp_kses_post', $pagination_links)) . '</div>';
        }

        echo '<div class="wp-gpt-card">';
        echo '<div class="wp-gpt-card-header">';
        echo '<h1>' . esc_html__('Gestione Coda GPT', 'wp-gpt-automation-pro') . '</h1>';
        if (function_exists('current_datetime')) {
            $current_datetime = current_datetime();
            $current_time_display = $current_datetime->format('Y-m-d H:i:s');
            $timezone_object = $current_datetime->getTimezone();
        } else {
            $timezone_object = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $current_time_display = current_time('Y-m-d H:i:s');
        }

        $tz_string = '';
        if ($timezone_object instanceof DateTimeZone) {
            $tz_string = $timezone_object->getName();
        }

        if (empty($tz_string) || preg_match('/^[+\-]/', $tz_string)) {
            $tz_string = function_exists('wp_timezone_string') ? wp_timezone_string() : get_option('timezone_string');
        }

        if (empty($tz_string)) {
            $offset = (float) get_option('gmt_offset', 0);
            $tz_string = 'UTC' . (0 !== $offset ? sprintf('%+g', $offset) : '');
        }

        echo '<p>' . sprintf(
            esc_html__('Controlla le esecuzioni automatiche e gestisci le operazioni pianificate. Ora WordPress: %1$s (%2$s).', 'wp-gpt-automation-pro'),
            esc_html($current_time_display),
            esc_html($tz_string)
        ) . '</p>';
        echo '</div>';
        echo '<div class="wp-gpt-card-body">';
        echo '<div class="notice notice-info wp-gpt-current-time"><p><strong>' . esc_html__('Ora attuale', 'wp-gpt-automation-pro') . '</strong>: ' . esc_html($current_time_display) . ' <span class="description">(' . esc_html($tz_string) . ')</span></p></div>';

        if (!empty($notice_message)) {
            echo '<div class="notice ' . esc_attr($notice_class) . '"><p>' . esc_html($notice_message) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('wp_gpt_manage_queue', 'wp_gpt_manage_queue_nonce');

        if ($jobs) {
            echo '<div class="wp-gpt-table-wrapper">';
            echo '<div class="tablenav top">';
            if (!empty($pagination_html)) {
                echo $pagination_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            echo '<div class="alignleft actions bulkactions">';
            submit_button(__('Elimina selezionati', 'wp-gpt-automation-pro'), 'delete', 'wp_gpt_delete_selected', false);
            echo '</div>';
            echo '<br class="clear" />';
            echo '</div>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><td id="cb" class="manage-column column-cb check-column"><input type="checkbox" id="wp-gpt-select-all" /></td><th>' . esc_html__('Job Key', 'wp-gpt-automation-pro') . '</th><th>' . esc_html__('Payload', 'wp-gpt-automation-pro') . '</th><th>' . esc_html__('Stato', 'wp-gpt-automation-pro') . '</th><th>' . esc_html__('Creato il', 'wp-gpt-automation-pro') . '</th><th>' . esc_html__('Ultima esecuzione', 'wp-gpt-automation-pro') . '</th><th>' . esc_html__('Prossima esecuzione', 'wp-gpt-automation-pro') . '</th><th>' . esc_html__('Azioni', 'wp-gpt-automation-pro') . '</th></tr></thead>';
            echo '<tbody>';
            foreach ($jobs as $job) {
                $payload_markup = '<span class="wp-gpt-empty">-</span>';
                $definition = json_decode($job->definition, true);
                if (is_array($definition)) {
                    $display_payload = [];

                    if (!empty($definition['target_route'])) {
                        $display_payload['target_route'] = sanitize_text_field($definition['target_route']);
                    }

                    $authorization_header = '';
                    if (isset($definition['headers']) && is_array($definition['headers'])) {
                        foreach (['Authorization', 'authorization'] as $auth_key) {
                            if (!empty($definition['headers'][$auth_key]) && is_string($definition['headers'][$auth_key])) {
                                $authorization_header = $definition['headers'][$auth_key];
                                break;
                            }
                        }
                    }

                    $bearer_token = get_option('wp_gpt_api_token');
                    if (empty($bearer_token) && $authorization_header) {
                        if (preg_match('/Bearer\s+(.*)$/i', $authorization_header, $matches)) {
                            $bearer_token = $matches[1];
                        }
                    }

                    if (!empty($bearer_token)) {
                        $display_payload['bearer_token'] = sanitize_text_field($bearer_token);
                    }

                    if (isset($definition['payload'])) {
                        $payload_body = $definition['payload'];
                        if (!is_array($payload_body)) {
                            $payload_body = json_decode(wp_json_encode($payload_body), true);
                        }
                        if (is_array($payload_body)) {
                            $display_payload['body'] = $payload_body;
                        }
                    }

                    if (!empty($display_payload)) {
                        $encoded_payload = wp_json_encode($display_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        if (false !== $encoded_payload) {
                            $payload_markup = '<pre class="wp-gpt-payload">' . esc_html($encoded_payload) . '</pre>';
                        } else {
                            $payload_markup = '<pre class="wp-gpt-payload">' . esc_html(print_r($display_payload, true)) . '</pre>';
                        }
                    }
                }

                echo '<tr data-job-id="' . esc_attr($job->id) . '">';
                echo '<th scope="row" class="check-column"><input type="checkbox" name="job_ids[]" value="' . esc_attr($job->id) . '" /></th>';
                echo '<td>' . esc_html($job->job_key) . '</td>';
                echo '<td>' . $payload_markup . '</td>';
                echo '<td>' . esc_html($job->status) . '</td>';
                echo '<td>' . esc_html($job->created_at) . '</td>';
                echo '<td>' . esc_html($job->last_run) . '</td>';
                echo '<td>' . esc_html($job->scheduled_for ? $job->scheduled_for : '-') . '</td>';
                echo '<td><div class="wp-gpt-flex-row">';
                echo '<button type="submit" class="button button-secondary" name="wp_gpt_run_job" value="' . esc_attr($job->job_key) . '">' . esc_html__('Esegui', 'wp-gpt-automation-pro') . '</button>';
                echo '<button type="submit" class="button button-danger" name="wp_gpt_delete_single" value="' . esc_attr($job->id) . '">' . esc_html__('Elimina', 'wp-gpt-automation-pro') . '</button>';
                echo '</div></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<div class="tablenav bottom">';
            if (!empty($pagination_html)) {
                echo $pagination_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            echo '<div class="alignleft actions bulkactions">';
            submit_button(__('Elimina selezionati', 'wp-gpt-automation-pro'), 'delete', 'wp_gpt_delete_selected', false);
            echo '</div>';
            echo '<br class="clear" />';
            echo '</div>';
            echo '</div>';
        } else {
            echo '<p class="wp-gpt-empty">' . esc_html__('Nessun job pianificato.', 'wp-gpt-automation-pro') . '</p>';
        }
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    public static function render_api_token_field() {
        $token = get_option('wp_gpt_api_token');
        echo '<input type="text" name="wp_gpt_api_token" value="' . esc_attr($token) . '" class="regular-text" readonly />';
        echo '<p><button type="button" class="button" id="regenerate-token">' . esc_html__('Rigenera Token', 'wp-gpt-automation-pro') . '</button></p>';
    }

    public static function render_openai_key_field() {
        $key = get_option('wp_gpt_openai_key');
        echo '<input type="text" name="wp_gpt_openai_key" value="' . esc_attr($key) . '" class="regular-text" />';
    }

    public static function render_temperature_field() {
        $temperature = get_option('wp_gpt_temperature', '0.7');
        echo '<input type="number" step="0.1" min="0" max="1" name="wp_gpt_temperature" value="' . esc_attr($temperature) . '" class="small-text" />';
    }

    public static function render_max_tokens_field() {
        $max_tokens = get_option('wp_gpt_max_tokens', '100');
        echo '<input type="number" name="wp_gpt_max_tokens" value="' . esc_attr($max_tokens) . '" class="small-text" />';
    }

    public static function render_openai_vector_store_id_field() {
        $value = get_option('wp_gpt_openai_vector_store_id');
        echo '<input type="text" name="wp_gpt_openai_vector_store_id" value="' . esc_attr($value) . '" class="regular-text" />';
    }

    public static function render_mcp_server_label_field() {
        $value = get_option('wp_gpt_mcp_server_label');
        echo '<input type="text" name="wp_gpt_mcp_server_label" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('Opzionale: URL REST di un server MCP esterno per la registrazione automatica di OpenAPI.', 'wp-gpt-automation-pro') . '</p>';
    }

    public static function render_mcp_ws_url_field() {
        $value = get_option('wp_gpt_mcp_ws_url', '');
        echo '<input type="text" name="wp_gpt_mcp_ws_url" value="' . esc_attr($value) . '" class="regular-text" placeholder="wss://example.com:8901/mcp" />';
        echo '<p class="description">' . esc_html__('URL pubblico del proxy WebSocket MCP (deve iniziare con wss://). Avvia lo script PHP tools/mcp-ws-server.php per esporre il listener.', 'wp-gpt-automation-pro') . '</p>';
    }

    public static function render_hix_api_key_field() {
        $api_key = get_option('wp_gpt_hix_api_key');
        echo '<input type="text" name="wp_gpt_hix_api_key" value="' . esc_attr($api_key) . '" class="regular-text" />';
    }

    public static function render_hix_endpoint_field() {
        $endpoint = get_option('wp_gpt_hix_endpoint', 'https://bypass.hix.ai/humanize-ai');
        echo '<input type="text" name="wp_gpt_hix_endpoint" value="' . esc_attr($endpoint) . '" class="regular-text" />';
    }

    public static function render_hix_mode_field() {
        $mode = get_option('wp_gpt_hix_mode', 'balanced');
        echo '<select name="wp_gpt_hix_mode" class="small-text">';
        echo '<option value="fast"' . selected($mode, 'fast', false) . '>Fast</option>';
        echo '<option value="balanced"' . selected($mode, 'balanced', false) . '>Balanced</option>';
        echo '<option value="aggressive"' . selected($mode, 'aggressive', false) . '>Aggressive</option>';
        echo '</select>';
    }

    public static function render_hix_word_limit_field() {
        $word_limit = get_option('wp_gpt_hix_word_limit', 2000);
        echo '<input type="number" name="wp_gpt_hix_word_limit" value="' . esc_attr($word_limit) . '" class="small-text" />';
    }

    public static function register_memory_manager_rest_routes() {
        register_rest_route(
            'wp-gpt/v1',
            '/memory/admin/delete-goals',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_delete_memory_goals'],
                'permission_callback' => [__CLASS__, 'rest_permission_manage_memory_requests'],
                'args'                => [
                    'goal_ids' => [
                        'required' => true,
                        'type'     => 'array',
                    ],
                ],
            ]
        );

        register_rest_route(
            'wp-gpt/v1',
            '/memory/admin/delete-steps',
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_delete_memory_steps'],
                'permission_callback' => [__CLASS__, 'rest_permission_manage_memory_requests'],
                'args'                => [
                    'step_ids' => [
                        'required' => true,
                        'type'     => 'array',
                    ],
                ],
            ]
        );
    }

    public static function rest_permission_manage_memory_requests($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', __('Non autorizzato.', 'wp-gpt-automation-pro'), ['status' => 403]);
        }

        $nonce = $request->get_param('_wpnonce');
        if (empty($nonce)) {
            $nonce = $request->get_param('nonce');
        }
        if (empty($nonce)) {
            $nonce = $request->get_header('X-WP-Nonce');
        }

        if (empty($nonce)) {
            return new WP_Error('rest_nonce_missing', __('Nonce mancante.', 'wp-gpt-automation-pro'), ['status' => 403]);
        }

        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('rest_invalid_nonce', __('Nonce non valido.', 'wp-gpt-automation-pro'), ['status' => 403]);
        }

        return true;
    }

    public static function rest_delete_memory_goals(WP_REST_Request $request) {
        global $wpdb;

        $goal_ids = self::sanitize_id_list($request->get_param('goal_ids'));
        if (empty($goal_ids)) {
            return new WP_Error('rest_invalid_goal_ids', __('Nessun goal valido specificato.', 'wp-gpt-automation-pro'), ['status' => 400]);
        }

        $goals_table = $wpdb->prefix . 'gpt_memory_goals';
        $steps_table = $wpdb->prefix . 'gpt_memory_steps';

        $placeholders = implode(', ', array_fill(0, count($goal_ids), '%d'));

        $deleted_steps = $wpdb->query($wpdb->prepare("DELETE FROM {$steps_table} WHERE goal_id IN ($placeholders)", $goal_ids));
        if (false === $deleted_steps) {
            return new WP_Error('rest_delete_steps_failed', __('Errore durante la cancellazione degli step collegati.', 'wp-gpt-automation-pro'), ['status' => 500]);
        }

        $deleted_goals = $wpdb->query($wpdb->prepare("DELETE FROM {$goals_table} WHERE id IN ($placeholders)", $goal_ids));
        if (false === $deleted_goals) {
            return new WP_Error('rest_delete_goals_failed', __('Errore durante la cancellazione dei goal.', 'wp-gpt-automation-pro'), ['status' => 500]);
        }

        return new WP_REST_Response(
            [
                'success'       => true,
                'deleted_goals' => (int) $deleted_goals,
                'deleted_steps' => (int) $deleted_steps,
                'goal_ids'      => $goal_ids,
            ]
        );
    }

    public static function rest_delete_memory_steps(WP_REST_Request $request) {
        global $wpdb;

        $step_ids = self::sanitize_id_list($request->get_param('step_ids'));
        if (empty($step_ids)) {
            return new WP_Error('rest_invalid_step_ids', __('Nessuno step valido specificato.', 'wp-gpt-automation-pro'), ['status' => 400]);
        }

        $steps_table = $wpdb->prefix . 'gpt_memory_steps';
        $placeholders = implode(', ', array_fill(0, count($step_ids), '%d'));

        $deleted_steps = $wpdb->query($wpdb->prepare("DELETE FROM {$steps_table} WHERE id IN ($placeholders)", $step_ids));
        if (false === $deleted_steps) {
            return new WP_Error('rest_delete_steps_failed', __('Errore durante la cancellazione degli step.', 'wp-gpt-automation-pro'), ['status' => 500]);
        }

        return new WP_REST_Response(
            [
                'success'       => true,
                'deleted_steps' => (int) $deleted_steps,
                'step_ids'      => $step_ids,
            ]
        );
    }

    private static function sanitize_id_list($ids) {
        if (empty($ids)) {
            return [];
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $sanitized = array_map('absint', $ids);
        $sanitized = array_filter($sanitized, function($value) {
            return $value > 0;
        });

        return array_values(array_unique($sanitized));
    }

    public static function regenerate_token() {
        $new_token = wp_generate_password(32, false);
        update_option('wp_gpt_api_token', $new_token);

        // Invia notifica via email quando il token viene rigenerato
        $admin_email = get_option('admin_email');
        wp_mail($admin_email, 'API Token Rigenerato', 'Il token API è stato rigenerato correttamente.');

        return $new_token;
    }

    public static function enqueue_admin_scripts($hook) {
        if (false === strpos($hook, 'wp-gpt')) {
            return;
        }

        wp_enqueue_style('wp-gpt-admin-styles', WP_GPT_PLUGIN_URL . 'assets/admin-styles.css', [], WP_GPT_VERSION);

        if (wp_style_is('wp-gpt-admin-styles', 'enqueued') && class_exists(__NAMESPACE__ . '\\WP_GPT_Schema_Secret_Admin')) {
            $custom_css = WP_GPT_Schema_Secret_Admin::get_gradient_css_variables();
            if (!empty($custom_css)) {
                wp_add_inline_style('wp-gpt-admin-styles', $custom_css);
            }
        }

        if ($hook === 'toplevel_page_wp-gpt-automation-pro' || $hook === 'wp-gpt-automation-pro_page_wp-gpt-queue') {
            wp_enqueue_script('wp-gpt-admin-js', WP_GPT_PLUGIN_URL . 'assets/admin.js', ['jquery'], WP_GPT_VERSION, true);
            wp_localize_script('wp-gpt-admin-js', 'wpGPTAdmin', [
                'ajaxUrl'     => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('regenerate_token_nonce'),
                'restUrl'     => rest_url('wp-gpt/v1/route-scheduler'),
                'userCode'    => get_option('wp_gpt_user_code'),
                'bearerToken' => get_option('wp_gpt_api_token'),
            ]);
        }
    }
    public static function render_disable_oauth_mcp_field() {
        $enabled       = (bool) get_option('wp_gpt_enable_oauth_mcp_endpoints', 0);
        $timeout_hours = (int) get_option('wp_gpt_sensitive_endpoints_timeout_hours', 1);
        $expires_at    = (int) get_option('wp_gpt_sensitive_endpoints_expires_at', 0);

        echo '<div class="wp-gpt-sensitive-endpoints-field">';
        echo '<label class="wp-gpt-sensitive-endpoints-toggle">';
        echo '<input type="checkbox" id="wp-gpt-enable-sensitive-endpoints" name="wp_gpt_enable_oauth_mcp_endpoints" value="1" ' . checked($enabled, true, false) . ' /> ';
        echo esc_html__('Attiva temporaneamente le integrazioni OAuth/OpenAI e il server MCP integrato (operazione rischiosa).', 'wp-gpt-automation-pro');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Lasciando il toggle disattivato le rotte rimangono spente. Se le attivi dovrai impostare un limite temporale (max 24h) dopo il quale verranno disattivate automaticamente.', 'wp-gpt-automation-pro') . '</p>';

        $wrapper_style = $enabled ? '' : 'style="display:none;"';
        echo '<div class="wp-gpt-sensitive-timeout" ' . $wrapper_style . '>';
        echo '<label for="wp-gpt-sensitive-timeout-input">' . esc_html__('Disattiva automaticamente dopo (ore)', 'wp-gpt-automation-pro') . '</label> ';
        echo '<input type="number" id="wp-gpt-sensitive-timeout-input" name="wp_gpt_sensitive_endpoints_timeout_hours" min="1" max="24" value="' . esc_attr($timeout_hours) . '" class="small-text" />';
        echo '</div>';

        if ($enabled && $expires_at > time()) {
            $formatted = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $expires_at);
            echo '<p class="description wp-gpt-sensitive-expiration">' . sprintf(esc_html__('Le integrazioni verranno disattivate automaticamente il %s.', 'wp-gpt-automation-pro'), esc_html($formatted)) . '</p>';
        }

        echo '<script>document.addEventListener("DOMContentLoaded",function(){const checkbox=document.getElementById("wp-gpt-enable-sensitive-endpoints");const timeout=document.querySelector(".wp-gpt-sensitive-timeout");if(!checkbox||!timeout){return;}const toggleVisibility=()=>{timeout.style.display=checkbox.checked?"block":"none";};checkbox.addEventListener("change",toggleVisibility);toggleVisibility();});</script>';
        echo '</div>';
    }

    public static function sanitize_sensitive_endpoint_toggle($value) {
        $enabled = empty($value) ? 0 : 1;

        if (!$enabled) {
            update_option('wp_gpt_sensitive_endpoints_expires_at', 0);
            return 0;
        }

        $raw_hours = isset($_POST['wp_gpt_sensitive_endpoints_timeout_hours']) ? wp_unslash($_POST['wp_gpt_sensitive_endpoints_timeout_hours']) : get_option('wp_gpt_sensitive_endpoints_timeout_hours', 1);
        $hours     = self::sanitize_sensitive_endpoint_timeout($raw_hours);

        update_option('wp_gpt_sensitive_endpoints_timeout_hours', $hours);
        update_option('wp_gpt_sensitive_endpoints_expires_at', time() + ($hours * HOUR_IN_SECONDS));

        return 1;
    }

    public static function sanitize_sensitive_endpoint_timeout($value) {
        $value = (int) $value;

        if ($value < 1) {
            $value = 1;
        }

        if ($value > 24) {
            $value = 24;
        }

        return $value;
    }

    public static function sanitize_boolean_option($value) {
        return empty($value) ? 0 : 1;
    }

    public static function sanitize_header_key($value) {
        $sanitized = sanitize_text_field($value);
        $sanitized = preg_replace('/[^A-Za-z0-9\-]/', '', $sanitized);

        return $sanitized;
    }
}

\add_action('wp_ajax_regenerate_token', function() {
    if (!\current_user_can('manage_options')) {
        \wp_send_json_error('Non autorizzato');
    }

    $nonce = $_REQUEST['_ajax_nonce'] ?? $_REQUEST['_wpnonce'] ?? $_REQUEST['nonce'] ?? '';
    if (!\wp_verify_nonce($nonce, 'regenerate_token_nonce')) {
        \wp_send_json_error('Nonce non valido (token). Ricevuto: ' . $nonce);
    }

    $new_token = WP_GPT_Admin_Settings::regenerate_token();
    \wp_send_json_success($new_token);
});

\add_action('wp_ajax_regenerate_key', function() {
    if (!\current_user_can('manage_options')) {
        \wp_send_json_error('Non autorizzato');
    }

    $nonce = $_REQUEST['_ajax_nonce'] ?? $_REQUEST['_wpnonce'] ?? $_REQUEST['nonce'] ?? '';
    if (!\wp_verify_nonce($nonce, 'regenerate_token_nonce')) {
        \wp_send_json_error('Nonce non valido (key). Ricevuto: ' . $nonce);
    }

    $new_key = \wp_generate_password(64, true, true);
    \update_option('wp_gpt_encryption_key', $new_key);
    \wp_send_json_success(['key' => $new_key]);
});

\add_action('wp_ajax_regenerate_key', function() {
    if (!\current_user_can('manage_options')) {
        \wp_send_json_error('Non autorizzato');
    }

    \check_ajax_referer('regenerate_token_nonce');

    $new_key = \wp_generate_password(64, true, true);
    \update_option('wp_gpt_encryption_key', $new_key);
    \wp_send_json_success(['key' => $new_key]);
});

WP_GPT_Admin_Settings::init();
