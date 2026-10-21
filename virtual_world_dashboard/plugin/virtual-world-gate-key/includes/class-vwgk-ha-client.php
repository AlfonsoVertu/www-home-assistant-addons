<?php

if (!defined('ABSPATH')) {
    exit;
}

class VWGK_HA_Client
{
    public static function connection_summary(): array
    {
        $mode = get_option('vwgk_ha_auth_mode', 'supervisor_proxy');
        if ($mode === 'supervisor_proxy') {
            return [
                'mode'          => 'supervisor_proxy',
                'base_url'      => 'http://supervisor/core/api',
                'token_present' => !empty(getenv('SUPERVISOR_TOKEN')),
            ];
        }

        $ha_url = rtrim((string) get_option('vwgk_ha_url', 'http://homeassistant:8123'), '/');

        return [
            'mode'          => 'long_lived_token',
            'base_url'      => $ha_url . '/api',
            'token_present' => (bool) get_option('vwgk_ha_long_lived_token', ''),
        ];
    }

    public static function request(string $method, string $path, ?array $body = null)
    {
        $mode = get_option('vwgk_ha_auth_mode', 'supervisor_proxy');

        if ($mode === 'supervisor_proxy') {
            $token = getenv('SUPERVISOR_TOKEN');
            $url = 'http://supervisor/core/api' . $path;
        } else {
            $token = (string) get_option('vwgk_ha_long_lived_token', '');
            $base = rtrim((string) get_option('vwgk_ha_url', 'http://homeassistant:8123'), '/');
            $url = $base . '/api' . $path;
        }

        if (empty($token)) {
            return new WP_Error('vwgk_missing_token', __('Token Home Assistant non configurato.', 'virtual-world-gate-key'), ['status' => 500]);
        }

        $args = [
            'method'  => strtoupper($method),
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        return [
            'status_code' => wp_remote_retrieve_response_code($response),
            'headers'     => wp_remote_retrieve_headers($response),
            'body'        => $decoded,
            'raw_body'    => wp_remote_retrieve_body($response),
            'url'         => $url,
        ];
    }
}
