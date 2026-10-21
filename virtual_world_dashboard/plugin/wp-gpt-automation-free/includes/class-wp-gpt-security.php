<?php

namespace WP_GPT_Automation_Pro;

use WP_Error;

class WP_GPT_Security {

    public static function verify_request($request) {
        $custom_header_check = validate_custom_security_header($request);
        if (is_wp_error($custom_header_check)) {
            return $custom_header_check;
        }

        $auth_header = get_bearer_token($request);

        error_log('Verifying request...');

        if (empty($auth_header)) {
            error_log('Token missing');
            return new WP_Error('unauthorized', __('Token mancante', 'wp-gpt-automation-pro'), ['status' => 401]);
        }

        $token = substr($auth_header, 7);
        $token_validation = Token_Validator::validate_bearer_token($token);
        if (is_wp_error($token_validation)) {
            error_log('Token non valido');
            return $token_validation;
        }

        error_log('Request verified successfully');
        return true;
    }
}
