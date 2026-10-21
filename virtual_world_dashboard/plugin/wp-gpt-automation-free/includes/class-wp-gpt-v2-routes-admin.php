<?php
namespace WP_GPT_Automation_Pro;

class WP_GPT_V2_Routes_Admin {
    const NONCE_ACTION = 'wp_gpt_v2_routes';
    const API_ROUTE_KEY = 'gestione_rotte_v2';

    /**
     * Endpoint REST determinato dinamicamente dal file routes-v2.json.
     * @var string
     */
    private static $api_route = 'wp-gpt/v2/gestione-rotte-v2';
    /**
     * Percorso REST per recuperare i chunk di una risposta lunga.
     * @var string
     */
    private static $chunk_route = 'wp-gpt/v2/get-v2-response-chunk';

    public static function init() {
        self::$api_route  = self::determine_api_route();
        self::$chunk_route = self::determine_chunk_route();
        add_action('admin_menu', [__CLASS__, 'add_menu']);
    }

    public static function add_menu() {
        if (!WP_GPT_Menu_Visibility::is_page_enabled(WP_GPT_Menu_Visibility::MENU_SECRET_ADMIN, 'wp-gpt-v2-routes')) {
            return;
        }

        add_submenu_page(
            'wp-gpt-automation-pro',
            __('v2 Routes', 'wp-gpt-automation-pro'),
            __('v2 Routes', 'wp-gpt-automation-pro'),
            'manage_options',
            'wp-gpt-v2-routes',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Rileva il percorso REST per gestire le rotte v2 leggendo routes-v2.json.
     */
    private static function determine_api_route() {
        $json_path = WP_GPT_PLUGIN_DIR . 'routes-v2.json';
        if (file_exists($json_path)) {
            $data = json_decode(file_get_contents($json_path), true);
            if (isset($data[self::API_ROUTE_KEY]['path'])) {
                return 'wp-gpt/v2/' . ltrim($data[self::API_ROUTE_KEY]['path'], '/');
            }
        }
        return 'wp-gpt/v2/gestione-rotte-v2';
    }

    private static function determine_chunk_route() {
        $json_path = WP_GPT_PLUGIN_DIR . 'routes-v2.json';
        if (file_exists($json_path)) {
            $data = json_decode(file_get_contents($json_path), true);
            if (isset($data['get_v2_response_chunk']['path'])) {
                return 'wp-gpt/v2/' . ltrim($data['get_v2_response_chunk']['path'], '/');
            }
        }
        return 'wp-gpt/v2/get-v2-response-chunk';
    }

    private static function api_request($action, $route_key = '', $definition = null) {
        $body = [
            'user_code' => get_option('wp_gpt_user_code'),
            'action'    => $action,
        ];

        if ($route_key) {
            $body['route_key'] = $route_key;
        }

        if (!is_null($definition)) {
            $body['definition'] = wp_json_encode($definition);
        }

        $response = wp_remote_post(rest_url(self::$api_route), [
            'headers' => [
                'Authorization' => 'Bearer ' . get_option('wp_gpt_api_token'),
            ],
            'body'    => $body,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (is_array($data) && isset($data['transient_key'], $data['total_chunks'])) {
            $full = $data['chunk_0'] ?? '';
            $total = (int) $data['total_chunks'];
            for ($i = 1; $i < $total; $i++) {
                $chunk_resp = wp_remote_post(rest_url(self::$chunk_route), [
                    'headers' => [
                        'Authorization' => 'Bearer ' . get_option('wp_gpt_api_token'),
                    ],
                    'body'    => [
                        'user_code'     => get_option('wp_gpt_user_code'),
                        'transient_key' => $data['transient_key'],
                        'chunk_index'   => $i,
                    ],
                    'timeout' => 20,
                ]);

                if (!is_wp_error($chunk_resp)) {
                    $chunk_data = json_decode(wp_remote_retrieve_body($chunk_resp), true);
                    if (isset($chunk_data['chunk'])) {
                        $full .= $chunk_data['chunk'];
                    }
                }
            }
            $data = json_decode($full, true);
        }

        return is_array($data) ? $data : [];
    }

    private static function get_routes() {
        $json_path = WP_GPT_PLUGIN_DIR . 'routes-v2.json';
        if (file_exists($json_path)) {
            $data = json_decode(file_get_contents($json_path), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return self::api_request('list');
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $routes   = self::get_routes();
        $message  = '';
        $edit_key = isset($_GET['edit']) ? sanitize_key($_GET['edit']) : '';
        $edit_route = $edit_key && isset($routes[$edit_key]) ? $routes[$edit_key] : null;

        if (isset($_GET['delete'])) {
            $key = sanitize_key($_GET['delete']);
            if ($key) {
                self::api_request('remove', $key);
                WP_GPT_V2_Route_Loader::reload_routes();
                $routes  = self::get_routes();
                $message = __('Route removed', 'wp-gpt-automation-pro');
            }
        }

        if (isset($_POST['wp_gpt_v2_routes_nonce'])) {
            check_admin_referer(self::NONCE_ACTION, 'wp_gpt_v2_routes_nonce');
            $key = sanitize_key($_POST['route_key'] ?? '');
            $path = sanitize_text_field($_POST['route_path'] ?? '');
            $method = sanitize_text_field($_POST['route_method'] ?? 'POST');
            $args = json_decode(stripslashes($_POST['route_args'] ?? ''), true);
            if (!is_array($args)) {
                $args = [];
            }
            $callback = wp_kses_post($_POST['route_callback'] ?? '');
            if ($key && $callback) {
                $definition = [
                    'path' => $path,
                    'method' => $method,
                    'args' => $args,
                    'callback_code' => $callback,
                ];
                $action = isset($routes[$key]) ? 'update' : 'add';
                self::api_request($action, $key, $definition);
                WP_GPT_V2_Route_Loader::reload_routes();
                $routes  = self::get_routes();
                $message = __('Route saved', 'wp-gpt-automation-pro');
            }
        }

        echo '<div class="wrap wp-gpt-admin-page">';
        echo '<div class="wp-gpt-card">';
        echo '<div class="wp-gpt-card-header">';
        echo '<h1>' . esc_html__('v2 Routes', 'wp-gpt-automation-pro') . '</h1>';
        echo '<p>' . esc_html__('Gestisci le rotte REST generate automaticamente per il tuo GPT personalizzato.', 'wp-gpt-automation-pro') . '</p>';
        echo '</div>';
        echo '<div class="wp-gpt-card-body">';
        if ($message) {
            echo '<div class="updated notice"><p>' . esc_html($message) . '</p></div>';
        }
        if ($routes) {
            echo '<div class="wp-gpt-table-wrapper">';
            echo '<table class="widefat striped"><thead><tr><th>Key</th><th>Path</th><th>Method</th><th>' . esc_html__('Actions', 'wp-gpt-automation-pro') . '</th></tr></thead><tbody>';
            foreach ($routes as $k => $r) {
                $del  = wp_nonce_url(add_query_arg(['delete' => $k]), 'delete_route_' . $k);
                $edit = add_query_arg(['edit' => $k]);
                echo '<tr>';
                echo '<td>' . esc_html($k) . '</td>';
                echo '<td>' . esc_html($r['path'] ?? '') . '</td>';
                echo '<td>' . esc_html($r['method'] ?? 'POST') . '</td>';
                echo '<td>';
                echo '<div class="wp-gpt-flex-row">';
                echo '<a href="' . esc_url($edit) . '" class="button">' . esc_html__('Edit', 'wp-gpt-automation-pro') . '</a>';
                echo '<a href="' . esc_url($del) . '" class="button">' . esc_html__('Delete', 'wp-gpt-automation-pro') . '</a>';
                echo '</div>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        } else {
            echo '<p class="wp-gpt-empty">' . esc_html__('No routes found.', 'wp-gpt-automation-pro') . '</p>';
        }
        echo '</div>';
        echo '</div>';

        echo '<div class="wp-gpt-card">';
        echo '<div class="wp-gpt-card-header">';
        echo '<h2>' . esc_html__('Add / Update Route', 'wp-gpt-automation-pro') . '</h2>';
        echo '<p>' . esc_html__("Definisci percorsi personalizzati per esporre nuove funzionalità tramite l'API v2.", 'wp-gpt-automation-pro') . '</p>';
        echo '</div>';
        echo '<div class="wp-gpt-card-body">';
        echo '<form method="post" class="wp-gpt-card-form">';
        wp_nonce_field(self::NONCE_ACTION, 'wp_gpt_v2_routes_nonce');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="route_key">' . esc_html__('Key', 'wp-gpt-automation-pro') . '</label></th>';
        echo '<td><input name="route_key" id="route_key" type="text" value="' . esc_attr($edit_key) . '" required></td></tr>';
        echo '<tr><th scope="row"><label for="route_path">' . esc_html__('Path', 'wp-gpt-automation-pro') . '</label></th>';
        echo '<td><input name="route_path" id="route_path" type="text" value="' . esc_attr($edit_route['path'] ?? '') . '"></td></tr>';
        echo '<tr><th scope="row"><label for="route_method">' . esc_html__('Method', 'wp-gpt-automation-pro') . '</label></th>';
        echo '<td><input name="route_method" id="route_method" type="text" value="' . esc_attr($edit_route['method'] ?? 'POST') . '"></td></tr>';
        echo '<tr><th scope="row"><label for="route_args">' . esc_html__('Args (JSON)', 'wp-gpt-automation-pro') . '</label></th>';
        echo '<td><textarea name="route_args" id="route_args" rows="5" class="code">' . esc_textarea(isset($edit_route['args']) ? wp_json_encode($edit_route['args'], JSON_PRETTY_PRINT) : '') . '</textarea></td></tr>';
        echo '<tr><th scope="row"><label for="route_callback">' . esc_html__('Callback Code', 'wp-gpt-automation-pro') . '</label></th>';
        echo '<td><textarea name="route_callback" id="route_callback" rows="10" class="code" required>' . esc_textarea($edit_route['callback_code'] ?? '') . '</textarea></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save Route', 'wp-gpt-automation-pro'));
        echo '</form>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
}

WP_GPT_V2_Routes_Admin::init();
