<?php
namespace WP_GPT_Automation_Pro;

use WP_Error;

require_once __DIR__ . '/class-license-firma.php';

class WP_GPT_License_Check {
    private static $secret_key = 'MIA_SUPER_SECRET_KEY_32_BYTE';
    private static $method = 'AES-256-CBC';
    // L'AUTORITA' E' WEBAGENCY, ED E' L'UNICA. Il ripiego su libertaeazione.it
    // e' stato tolto il 2026-08-30, dopo aver migrato qui tutte e 25 le sue
    // licenze: nessun sito resta scoperto. Un secondo gestore a cui credere
    // e' una seconda porta da cui puo' entrare una risposta falsa.
    private static $api_urls = [
        'https://workingwithweb.it/webagency/wp-json/wp-gpt/v1/verify-license',
    ];
    private static $transient_key = 'wp_gpt_license_valid';

    /**
     * Inizializza gli hook per il controllo degli aggiornamenti del plugin.
     */
    public static function init() {
        $plugin_file = plugin_basename(WP_GPT_PLUGIN_DIR . 'wp-gpt-automation-pro.php');

        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_for_update']);
        add_filter('plugins_api', [__CLASS__, 'plugin_info'], 20, 3);
        add_filter('plugin_action_links_' . $plugin_file, [__CLASS__, 'action_links']);
        add_action('admin_init', [__CLASS__, 'handle_manual_update_check']);
        add_filter('upgrader_pre_download', [__CLASS__, 'verify_before_download'], 10, 4);
        add_filter('http_request_args', [__CLASS__, 'custom_download_user_agent'], 10, 2);
    }

    private static function encrypt_with_iv($plaintext) {
        $iv = random_bytes(openssl_cipher_iv_length(self::$method));
        $ciphertext = openssl_encrypt($plaintext, self::$method, self::$secret_key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ciphertext);
    }

    private static function get_domain() {
        $host = parse_url(site_url(), PHP_URL_HOST);
        return $host ? $host : '';
    }

    private static function request_license_server($payload) {
        $last_error = null;

        // LA RISPOSTA VA FIRMATA, non solo letta. Prima bastava far
        // rispondere {"success":true} per avere una licenza valida: la
        // richiesta viaggiava cifrata, la risposta no. E la chiave AES qui
        // sopra sta dentro ogni copia venduta, quindi non prova nulla su
        // chi sta parlando. Vedi class-license-firma.php.
        $firma_disponibile = class_exists('\\WP_GPT_License_Firma');
        $nonce = $firma_disponibile ? \WP_GPT_License_Firma::nonce() : '';
        if ($nonce !== '') {
            $payload['nonce'] = $nonce;
        }
        $dominio = self::get_domain();

        foreach (self::$api_urls as $api_url) {
            $response = wp_remote_post($api_url, [
                'body'    => wp_json_encode($payload),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'lic-type'     => 'free',
                ],
                'timeout' => 20,
            ]);

            if (is_wp_error($response)) {
                $last_error = $response;
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);
            $body = json_decode($raw_body, true);

            if ($code === 200 && is_array($body) && isset($body['success']) && $body['success'] === true) {
                if ($firma_disponibile) {
                    $esito_firma = \WP_GPT_License_Firma::verifica($body, $dominio, $nonce);
                    if (!$esito_firma['ok']) {
                        if (\WP_GPT_License_Firma::obbligatoria()) {
                            $last_error = new WP_Error('unsigned_response', sprintf(
                                __('Risposta del server di licenza non attendibile: %s', 'wp-gpt-automation-pro'),
                                $esito_firma['motivo']
                            ), ['status' => $code, 'api_url' => $api_url]);
                            continue;
                        }
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('[WP_GPT_License_Check] firma non verificata da ' . $api_url
                                      . ': ' . $esito_firma['motivo']);
                        }
                    }
                }

                return [
                    'code' => $code,
                    'body' => $body,
                    'api_url' => $api_url,
                ];
            }

            if (!is_array($body)) {
                $last_error = new WP_Error('invalid_response', __('Risposta JSON non valida dal server di licenza', 'wp-gpt-automation-pro'), [
                    'status' => $code,
                    'api_url' => $api_url,
                    'body' => $raw_body,
                ]);
                continue;
            }

            $message = isset($body['message']) ? $body['message'] : __('Risposta non valida dal server di licenza', 'wp-gpt-automation-pro');
            $last_error = new WP_Error('invalid_response', $message, [
                'status' => $code,
                'api_url' => $api_url,
            ]);
        }

        return $last_error ?: new WP_Error('invalid_response', __('Risposta non valida dal server di licenza', 'wp-gpt-automation-pro'));
    }

    /**
     * Esegue la richiesta al server delle licenze e restituisce i dati di aggiornamento.
     *
     * @return array|WP_Error
     */
    private static function get_remote_data() {
        $license_key = get_option('wp_gpt_license_key');
        if (empty($license_key)) {
            return new WP_Error('missing_license', __('Licenza non configurata', 'wp-gpt-automation-pro'), ['status' => 403]);
        }

        $encrypted_license  = self::encrypt_with_iv($license_key);
        $encrypted_domain   = self::encrypt_with_iv(self::get_domain());
        
        $encrypted_lic_type  = self::encrypt_with_iv('free');
        $encrypted_user_code = self::encrypt_with_iv('....');
        $encrypted_api_token = self::encrypt_with_iv('....');

        $payload = [
            'license_key' => $encrypted_license,
            'domain'      => $encrypted_domain,
            'lic-type'    => $encrypted_lic_type,
            'user_code'   => $encrypted_user_code,
            'api_token'   => $encrypted_api_token,
            'version'     => defined('WP_GPT_VERSION') ? WP_GPT_VERSION : '',
        ];

        $remote_result = self::request_license_server($payload);
        if (is_wp_error($remote_result)) {
            return $remote_result;
        }

        $data = $remote_result['body'];
        unset($data['success'], $data['message']);
        return $data;
    }

    /**
     * Rende disponibili i dati di aggiornamento ad altre parti del plugin.
     *
     * @return array|WP_Error
     */
    public static function get_update_data() {
        return self::get_remote_data();
    }

    public static function verify() {
        // Check transient to avoid repetitive calls
        if (get_transient(self::$transient_key) === 'valid') {
            return true;
        }

        $license_key = get_option('wp_gpt_license_key');
        if (empty($license_key)) {
            return new WP_Error('missing_license', __('Licenza non configurata', 'wp-gpt-automation-pro'), ['status' => 403]);
        }

        $encrypted_license  = self::encrypt_with_iv($license_key);
        $encrypted_domain   = self::encrypt_with_iv(self::get_domain());

        $encrypted_lic_type  = self::encrypt_with_iv('free');
        $encrypted_user_code = self::encrypt_with_iv('....');
        $encrypted_api_token = self::encrypt_with_iv('....');

        $payload = [
            'license_key' => $encrypted_license,
            'domain'      => $encrypted_domain,
            'lic-type'    => $encrypted_lic_type,
            'user_code'   => $encrypted_user_code,
            'api_token'   => $encrypted_api_token,
            'version'     => defined('WP_GPT_VERSION') ? WP_GPT_VERSION : '',
        ];

        $remote_result = self::request_license_server($payload);
        if (is_wp_error($remote_result)) {
            delete_transient(self::$transient_key);
            $message = $remote_result->get_error_message();
            return new WP_Error('invalid_license', $message, ['status' => 403]);
        }

        set_transient(self::$transient_key, 'valid', DAY_IN_SECONDS);
        return true;
    }

    /**
     * Controlla se sono disponibili aggiornamenti per il plugin.
     *
     * @param object $transient
     * @return object
     */
    public static function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = self::get_remote_data();
        if (is_wp_error($remote) || empty($remote['new_version'])) {
            return $transient;
        }

        if (version_compare(WP_GPT_VERSION, $remote['new_version'], '>=')) {
            return $transient;
        }

        $plugin_file = plugin_basename(WP_GPT_PLUGIN_DIR . 'wp-gpt-automation-pro.php');
        $obj = new \stdClass();
        $obj->slug = 'wp-gpt-automation-pro';
        $obj->plugin = $plugin_file;
        $obj->new_version = $remote['new_version'];
        if (!empty($remote['package'])) {
            $obj->package = $remote['package'];
        }
        $obj->url = 'https://workingwithweb.it/webagency';
        $transient->response[$plugin_file] = $obj;

        return $transient;
    }

    /**
     * Aggiunge il link per il controllo manuale degli aggiornamenti.
     *
     * @param array $links
     * @return array
     */
    public static function action_links($links) {
        $url = wp_nonce_url(admin_url('plugins.php?wp_gpt_check_update=1'), 'wp_gpt_check_update');
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Check for updates', 'wp-gpt-automation-pro') . '</a>';
        return $links;
    }

    /**
     * Gestisce il controllo manuale degli aggiornamenti con verifica della licenza.
     */
    public static function handle_manual_update_check() {
        if (!current_user_can('update_plugins')) {
            return;
        }

        if (isset($_GET['wp_gpt_check_update']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wp_gpt_check_update')) {
            $verify = self::verify();
            if (is_wp_error($verify)) {
                add_action('admin_notices', function () use ($verify) {
                    echo '<div class="error"><p>' . esc_html($verify->get_error_message()) . '</p></div>';
                });
            } else {
                delete_site_transient('update_plugins');
                wp_update_plugins();
                add_action('admin_notices', function () {
                    echo '<div class="updated"><p>' . esc_html__('Aggiornamento verificato.', 'wp-gpt-automation-pro') . '</p></div>';
                });
            }
            $redirect = admin_url('plugins.php');
            if (isset($_GET['page']) && $_GET['page'] === 'wp-gpt-license-settings') {
                $redirect = admin_url('options-general.php?page=wp-gpt-license-settings');
            }
            wp_safe_redirect($redirect);
            exit;
        }
    }

    /**
     * Verifica la licenza prima del download dell'aggiornamento.
     *
     * @param bool   $reply       Risposta precedente.
     * @param string $package     URL del pacchetto.
     * @param object $upgrader    Istanza dell'upgrader.
     * @param array  $hook_extra  Informazioni extra sull'aggiornamento.
     * @return bool|WP_Error
     */
    public static function verify_before_download($reply, $package, $upgrader, $hook_extra) {
        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === plugin_basename(WP_GPT_PLUGIN_DIR . 'wp-gpt-automation-pro.php')) {
            $verify = self::verify();
            if (is_wp_error($verify)) {
                return $verify;
            }
        }
        return $reply;
    }

    /**
     * Imposta un User-Agent personalizzato per il download dell'aggiornamento.
     *
     * @param array  $args Argomenti della richiesta HTTP.
     * @param string $url  URL della richiesta.
     * @return array
     */
    public static function custom_download_user_agent($args, $url) {
        if (strpos($url, '/wp-json/wp-gpt/v1/download-update') !== false) {
            $args['user-agent'] = 'Mozilla/5.0 (compatible; WP-GPT-Automation-Pro; ' . home_url('/') . ')';
        }
        return $args;
    }

    /**
     * Fornisce le informazioni del plugin nella schermata degli aggiornamenti.
     *
     * @param false|object|array $res
     * @param string $action
     * @param object $args
     * @return object|false
     */
    public static function plugin_info($res, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'wp-gpt-automation-pro') {
            return $res;
        }

        $remote = self::get_remote_data();
        if (is_wp_error($remote)) {
            return $res;
        }

        $res = new \stdClass();
        $res->name = 'WP GPT Automation Pro';
        $res->slug = 'wp-gpt-automation-pro';
        $res->version = $remote['new_version'] ?? WP_GPT_VERSION;
        $res->download_link = $remote['package'] ?? '';
        $res->sections = [
            'description' => __('Plugin per gestire WordPress tramite GPT con API sicure.', 'wp-gpt-automation-pro'),
        ];

        return $res;
    }
}
