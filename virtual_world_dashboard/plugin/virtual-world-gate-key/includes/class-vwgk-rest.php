<?php

if (!defined('ABSPATH')) {
    exit;
}

class VWGK_REST
{
    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route('vwgk/v1', '/status', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'status'],
            'permission_callback' => [__CLASS__, 'authorize'],
        ]);

        register_rest_route('vwgk/v1', '/ha/state', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'ha_state'],
            'permission_callback' => [__CLASS__, 'authorize'],
            'args'                => [
                'entity_id' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ]);

        register_rest_route('vwgk/v1', '/ha/service', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'ha_service'],
            'permission_callback' => [__CLASS__, 'authorize'],
        ]);

        register_rest_route('vwgk/v1', '/wp-gpt/proxy', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'wp_gpt_proxy'],
            'permission_callback' => [__CLASS__, 'authorize'],
        ]);
    }

    public static function authorize(WP_REST_Request $request)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $provided = trim((string) $request->get_header('x-api-key'));
        $expected = trim((string) get_option('vwgk_api_key', ''));

        if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
            return true;
        }

        return new WP_Error('vwgk_forbidden', __('API key non valida.', 'virtual-world-gate-key'), ['status' => 403]);
    }

    public static function status(WP_REST_Request $request): WP_REST_Response
    {
        $summary = VWGK_HA_Client::connection_summary();

        return new WP_REST_Response([
            'success'           => true,
            'plugin'            => 'virtual-world-gate-key',
            'version'           => VWGK_VERSION,
            'home_assistant'    => $summary,
            'wp_gpt_connected'  => [
                'token_api_present' => !empty(get_option('wp_gpt_api_token', '')),
                'user_code_present' => !empty(get_option('wp_gpt_user_code', '')),
            ],
        ], 200);
    }

    public static function ha_state(WP_REST_Request $request)
    {
        $entity_id = sanitize_text_field((string) $request->get_param('entity_id'));
        $response = VWGK_HA_Client::request('GET', '/states/' . rawurlencode($entity_id));

        if (is_wp_error($response)) {
            return $response;
        }

        return new WP_REST_Response([
            'success'   => $response['status_code'] >= 200 && $response['status_code'] < 300,
            'entity_id' => $entity_id,
            'response'  => $response['body'],
            'status'    => $response['status_code'],
        ], $response['status_code']);
    }

    public static function ha_service(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        $domain = sanitize_text_field((string) ($data['domain'] ?? ''));
        $service = sanitize_text_field((string) ($data['service'] ?? ''));
        $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : [];

        if ($domain === '' || $service === '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'I parametri domain e service sono obbligatori.',
            ], 400);
        }

        $response = VWGK_HA_Client::request('POST', '/services/' . rawurlencode($domain) . '/' . rawurlencode($service), $payload);

        if (is_wp_error($response)) {
            return $response;
        }

        return new WP_REST_Response([
            'success'       => $response['status_code'] >= 200 && $response['status_code'] < 300,
            'called_domain' => $domain,
            'called_service'=> $service,
            'status'        => $response['status_code'],
            'response'      => $response['body'],
        ], $response['status_code']);
    }

    public static function wp_gpt_proxy(WP_REST_Request $request)
    {
        $data      = $request->get_json_params();
        $namespace = sanitize_text_field((string) ($data['namespace'] ?? 'v1'));
        $route     = '/' . ltrim(sanitize_text_field((string) ($data['route'] ?? '')), '/');
        $method    = strtoupper(sanitize_text_field((string) ($data['method'] ?? 'POST')));
        $payload   = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : [];

        if ($route === '/' || $route === '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'route obbligatoria.',
            ], 400);
        }

        $token_api = trim((string) get_option('wp_gpt_api_token', ''));
        $user_code = trim((string) get_option('wp_gpt_user_code', ''));

        if ($token_api === '' || $user_code === '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Credenziali WP GPT non configurate.',
            ], 500);
        }

        if (!isset($payload['user_code'])) {
            $payload['user_code'] = $user_code;
        }

        $internal = new WP_REST_Request($method, '/wp-gpt/' . $namespace . $route);
        $internal->set_header('Authorization', 'Bearer ' . $token_api);
        $internal->set_header('Content-Type', 'application/json');

        foreach ($payload as $key => $value) {
            $internal->set_param($key, $value);
        }

        $response = rest_do_request($internal);
        $server_response = rest_get_server()->response_to_data($response, false);

        return new WP_REST_Response([
            'success'     => !$response->is_error(),
            'forwarded_to'=> '/wp-gpt/' . $namespace . $route,
            'status'      => $response->get_status(),
            'data'        => $server_response,
        ], $response->get_status());
    }
}
