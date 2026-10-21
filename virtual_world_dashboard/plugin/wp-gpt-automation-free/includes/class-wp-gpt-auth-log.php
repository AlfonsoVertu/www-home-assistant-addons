<?php
namespace WP_GPT_Automation_Pro;

class WP_GPT_Auth_Log {
    public static function log($bearer_received, $bearer_valid, $user_code_received, $user_code_valid, $params) {
        global $wpdb;
        try {
            $table = $wpdb->prefix . 'gpt_auth_logs';
            $data = [
                'time' => current_time('mysql'),
                'bearer_received' => $bearer_received,
                'bearer_valid' => $bearer_valid,
                'user_code_received' => $user_code_received,
                'user_code_valid' => $user_code_valid,
                'params' => wp_json_encode(self::truncate_params($params)),
            ];
            $wpdb->insert($table, $data);
            do_action('wp_gpt_auth_log_inserted', $data);
        } catch (\Throwable $e) {
            error_log('[WP_GPT] Failed to insert auth log: ' . $e->getMessage());
        }
    }

    private static function truncate_params($params) {
        $truncated = [];
        if (!is_array($params)) {
            return $truncated;
        }
        foreach ($params as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = wp_json_encode($value);
            } else {
                $value = (string) $value;
            }
            if ($key !== 'user_code' && stripos($key, 'token') === false && stripos($key, 'bearer') === false) {
                $value = substr($value, 0, 30);
            }
            $truncated[$key] = $value;
        }
        return $truncated;
    }
}
