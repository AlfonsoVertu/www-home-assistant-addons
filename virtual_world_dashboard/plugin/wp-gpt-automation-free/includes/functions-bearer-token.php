<?php

namespace WP_GPT_Automation_Pro;

use WP_REST_Request;
use WP_Error;

if (!function_exists(__NAMESPACE__ . '\\get_authorization_header')) {
    /**
     * Retrieve the Authorization header from different server environments.
     *
     * @return string|null The Authorization header if available.
     */
    function get_authorization_header() {
        if (isset($_SERVER['Authorization'])) {
            return trim($_SERVER['Authorization']);
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['HTTP_AUTHORIZATION']);
        }
        if (function_exists('apache_request_headers')) {
            $request_headers = apache_request_headers();
            if (isset($request_headers['Authorization'])) {
                return trim($request_headers['Authorization']);
            }
            if (isset($request_headers['authorization'])) {
                return trim($request_headers['authorization']);
            }
        }
        return null;
    }
}

if (!function_exists(__NAMESPACE__ . '\\get_bearer_token')) {
    /**
     * Retrieve the Bearer token from the request or server headers.
     *
     * @param WP_REST_Request|null $request Optional REST request object.
     * @return string|null Authorization header containing the Bearer token or null if not found.
     */
    function get_bearer_token($request = null) {
        // 1. getallheaders()
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['Authorization']) && stripos($headers['Authorization'], 'Bearer ') === 0) {
                return trim($headers['Authorization']);
            }
        }

        // 2. Fallback with get_authorization_header()
        $auth_header = get_authorization_header();
        if ($auth_header && stripos($auth_header, 'Bearer ') === 0) {
            return $auth_header;
        }

        // 3. apache_request_headers() diretto
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization']) && stripos($headers['Authorization'], 'Bearer ') === 0) {
                return trim($headers['Authorization']);
            }
            if (isset($headers['authorization']) && stripos($headers['authorization'], 'Bearer ') === 0) {
                return trim($headers['authorization']);
            }
        }

        // 4. WP REST API
        if ($request instanceof WP_REST_Request) {
            $auth_header = $request->get_header('authorization');
            if ($auth_header && stripos($auth_header, 'Bearer ') === 0) {
                return $auth_header;
            }
        }

        return null;
    }
}

if (!function_exists(__NAMESPACE__ . '\\get_header_from_request')) {
    /**
     * Retrieve a specific header value from different sources.
     *
     * @param string               $header_key Header name to lookup.
     * @param WP_REST_Request|null $request    Optional REST request object.
     * @return string|null Header value if found.
     */
    function get_header_from_request($header_key, $request = null) {
        $normalized_key = strtolower($header_key);

        if ($request instanceof WP_REST_Request) {
            $header_value = $request->get_header($header_key);
            if (!empty($header_value)) {
                return $header_value;
            }

            $header_value = $request->get_header($normalized_key);
            if (!empty($header_value)) {
                return $header_value;
            }
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strtolower($key) === $normalized_key) {
                    return $value;
                }
            }
        }

        if (function_exists('apache_request_headers')) {
            $apache_headers = apache_request_headers();
            foreach ($apache_headers as $key => $value) {
                if (strtolower($key) === $normalized_key) {
                    return $value;
                }
            }
        }

        $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $header_key));
        if (isset($_SERVER[$server_key])) {
            return sanitize_text_field(wp_unslash($_SERVER[$server_key]));
        }

        return null;
    }
}

if (!function_exists(__NAMESPACE__ . '\\validate_custom_security_header')) {
    /**
     * Validate a custom security header when the feature is enabled.
     *
     * @param WP_REST_Request $request Current REST request.
     * @return true|WP_Error
     */
    function validate_custom_security_header($request) {
        $enabled = (bool) get_option('wp_gpt_custom_header_enforcement');
        if (!$enabled) {
            return true;
        }

        $header_key   = trim((string) get_option('wp_gpt_custom_header_key'));
        $header_value = (string) get_option('wp_gpt_custom_header_value');

        // Se la funzionalità è attiva ma la chiave/valore non sono configurati, ignora il controllo.
        if ($header_key === '' || $header_value === '') {
            return true;
        }

        $provided_value = get_header_from_request($header_key, $request);
        $matches        = $provided_value !== null && $provided_value !== ''
            ? (function_exists('hash_equals') ? hash_equals($header_value, $provided_value) : ($header_value === $provided_value))
            : false;

        if (!$matches) {
            return new WP_Error(
                'unauthorized',
                __('Header di sicurezza mancante o non valido.', 'wp-gpt-automation-pro'),
                ['status' => 401]
            );
        }

        return true;
    }
}

