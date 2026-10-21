<?php

namespace WP_GPT_Automation_Pro;

use WP_Error;

class Token_Validator {
    /**
     * Validate a bearer token against the static token and, if needed, the OAuth fallback.
     *
     * @param string $token Raw bearer token without the "Bearer " prefix.
     * @param array  $args  Optional arguments: error_code, error_message, status, error_data.
     *
     * @return true|WP_Error
     */
    public static function validate_bearer_token( $token, array $args = [] ) {
        $defaults = [
            'error_code'    => 'unauthorized',
            'error_message' => \__( 'Token non valido', 'wp-gpt-automation-pro' ),
            'status'        => 401,
            'error_data'    => [],
        ];

        $args = array_merge( $defaults, $args );

        if ( empty( $token ) ) {
            return self::build_error( $args );
        }

        $saved_token = get_option( 'wp_gpt_api_token' );
        if ( ! empty( $saved_token ) ) {
            $matches = function_exists( 'hash_equals' ) ? hash_equals( $saved_token, $token ) : ( $saved_token === $token );
            if ( $matches ) {
                return true;
            }
        }

        $oauth_class = __NAMESPACE__ . '\\WP_GPT_OpenAI_OAuth';

        if ( class_exists( $oauth_class ) && method_exists( $oauth_class, 'validate_access_token' ) ) {
            $oauth_result = WP_GPT_OpenAI_OAuth::validate_access_token( $token );

            if ( ! is_wp_error( $oauth_result ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'Token validato tramite fallback OAuth.' );
                }
                return true;
            }

            if ( is_wp_error( $oauth_result ) ) {
                $args['error_data']['oauth_error'] = $oauth_result->get_error_message();
            }
        }

        return self::build_error( $args );
    }

    /**
     * Helper to create a WP_Error instance with merged data.
     *
     * @param array $args Error arguments.
     *
     * @return WP_Error
     */
    private static function build_error( array $args ) {
        $data = array_merge( [ 'status' => $args['status'] ], $args['error_data'] );

        return new WP_Error( $args['error_code'], $args['error_message'], $data );
    }
}
