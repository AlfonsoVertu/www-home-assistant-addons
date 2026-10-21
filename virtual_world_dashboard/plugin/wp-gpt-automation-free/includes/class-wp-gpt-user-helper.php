<?php

namespace WP_GPT_Automation_Pro;

use WP_Error;
use WP_User_Query;
use function get_option;
use function get_user_by;
use function function_exists;

class WP_GPT_User_Helper {
    /**
     * Retrieve the WordPress administrator configured for user code operations.
     *
     * @return int|WP_Error
     */
    public static function get_lowest_admin_id() {
        $assigned_admin_id = (int) get_option('wp_gpt_user_code_admin_id', 0);

        if ($assigned_admin_id <= 0) {
            // Fallback: cerca il primo amministratore disponibile se non ne è stato assegnato uno specifico.
            if (function_exists('get_users')) {
                $admins = get_users([
                    'role'    => 'administrator',
                    'number'  => 1,
                    'orderby' => 'ID',
                    'order'   => 'ASC',
                ]);
                if (!empty($admins) && is_array($admins)) {
                    $assigned_admin_id = (int) $admins[0]->ID;
                }
            }
        }

        if ($assigned_admin_id <= 0) {
            return new WP_Error(
                'wp_gpt_missing_user_code_admin',
                __('Nessun amministratore configurato per la gestione dei codici utente.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        if (!function_exists('get_user_by')) {
            return $assigned_admin_id;
        }

        $user = get_user_by('id', $assigned_admin_id);
        if ($user && is_object($user) && in_array('administrator', (array) ($user->roles ?? []), true)) {
            return (int) $user->ID;
        }

        return new WP_Error(
            'wp_gpt_invalid_user_code_admin',
            __('L\'amministratore configurato per la gestione dei codici utente non è valido.', 'wp-gpt-automation-pro'),
            ['status' => 500]
        );
    }
}
