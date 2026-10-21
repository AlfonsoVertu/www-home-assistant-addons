<?php
namespace WP_GPT_Automation_Pro;

class WP_GPT_Auth_Log_Admin {
    const NONCE_ACTION = 'wp_gpt_auth_log';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
    }

    public static function add_menu() {
        if (!WP_GPT_Menu_Visibility::is_page_enabled(WP_GPT_Menu_Visibility::MENU_AUTOMATION_PRO, 'wp-gpt-auth-logs')) {
            return;
        }

        add_submenu_page(
            'wp-gpt-automation-pro',
            __('Auth Logs', 'wp-gpt-automation-pro'),
            __('Auth Logs', 'wp-gpt-automation-pro'),
            'manage_options',
            'wp-gpt-auth-logs',
            [__CLASS__, 'render_page']
        );
    }

    public static function enqueue_scripts($hook) {
        if ($hook !== 'wp-gpt-automation-pro_page_wp-gpt-auth-logs') {
            return;
        }
        wp_enqueue_script('wp-gpt-auth-log-admin', WP_GPT_PLUGIN_URL . 'assets/auth-log-admin.js', ['jquery'], WP_GPT_VERSION, true);
        wp_localize_script('wp-gpt-auth-log-admin', 'WpGptAuthLog', [
            'deleteConfirm' => __('Are you sure?', 'wp-gpt-automation-pro'),
        ]);
    }

    private static function verify_nonce() {
        check_admin_referer(self::NONCE_ACTION, 'wp_gpt_auth_log_nonce');
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'gpt_auth_logs';

        // Handle single deletion
        if (isset($_GET['delete'])) {
            check_admin_referer(self::NONCE_ACTION);
            $id = intval($_GET['delete']);
            $wpdb->delete($table, ['id' => $id], ['%d']);
            wp_safe_redirect(remove_query_arg(['delete', '_wpnonce']));
            exit;
        }

        // Handle bulk deletion
        if (isset($_POST['delete_selected']) && !empty($_POST['log_ids'])) {
            self::verify_nonce();
            $ids = array_map('intval', (array) $_POST['log_ids']);
            foreach ($ids as $id) {
                $wpdb->delete($table, ['id' => $id], ['%d']);
            }
            echo '<div class="updated notice"><p>' . esc_html__('Logs deleted.', 'wp-gpt-automation-pro') . '</p></div>';
        }

        $per_page = 10;
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($paged - 1) * $per_page;

        $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY time DESC LIMIT %d OFFSET %d", $per_page, $offset));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

        echo '<div class="wrap wp-gpt-admin-page">';
        echo '<div class="wp-gpt-card">';
        echo '<div class="wp-gpt-card-header">';
        echo '<h1>' . esc_html__('Auth Logs', 'wp-gpt-automation-pro') . '</h1>';
        echo '<p>' . esc_html__('Consulta i tentativi di autenticazione e individua rapidamente eventuali anomalie.', 'wp-gpt-automation-pro') . '</p>';
        echo '</div>';
        echo '<div class="wp-gpt-card-body">';

        // Debug section removed in Free version to avoid missing class dependency.

        if ($logs) {
            echo '<form method="post" class="wp-gpt-card-form">';
            wp_nonce_field(self::NONCE_ACTION, 'wp_gpt_auth_log_nonce');
            echo '<div class="wp-gpt-table-wrapper">';
            echo '<table class="widefat striped">';
            echo '<thead><tr><th style="width:20px"><input type="checkbox" class="wp-gpt-toggle-all"></th><th>ID</th><th>' . esc_html__('Time', 'wp-gpt-automation-pro') . '</th><th>' . esc_html__('Bearer Received', 'wp-gpt-automation-pro') . '</th><th>' . esc_html__('Bearer Valid', 'wp-gpt-automation-pro') . '</th><th>' . esc_html__('User Code Received', 'wp-gpt-automation-pro') . '</th><th>' . esc_html__('User Code Valid', 'wp-gpt-automation-pro') . '</th><th>' . esc_html__('Params', 'wp-gpt-automation-pro') . '</th><th>' . esc_html__('Actions', 'wp-gpt-automation-pro') . '</th></tr></thead><tbody>';
            foreach ($logs as $log) {
                $del_link = wp_nonce_url(add_query_arg('delete', $log->id), self::NONCE_ACTION);
                $params = is_string($log->params) ? mb_substr($log->params, 0, 100) : '';
                echo '<tr>';
                echo '<td><input type="checkbox" name="log_ids[]" value="' . esc_attr($log->id) . '"></td>';
                echo '<td>' . esc_html($log->id) . '</td>';
                echo '<td>' . esc_html($log->time) . '</td>';
                echo '<td>' . esc_html($log->bearer_received) . '</td>';
                echo '<td>' . esc_html($log->bearer_valid) . '</td>';
                echo '<td>' . esc_html($log->user_code_received) . '</td>';
                echo '<td>' . esc_html($log->user_code_valid) . '</td>';
                echo '<td>' . esc_html($params) . '</td>';
                echo '<td><a href="' . esc_url($del_link) . '" class="button auth-log-delete">' . esc_html__('Delete', 'wp-gpt-automation-pro') . '</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
            submit_button(__('Delete selected', 'wp-gpt-automation-pro'), 'delete', 'delete_selected', false);
            echo '</form>';
            $pagination = paginate_links([
                'base'   => add_query_arg('paged', '%#%'),
                'format' => '',
                'current'=> $paged,
                'total'  => $total_pages
            ]);
            if ($pagination) {
                echo '<div class="wp-gpt-pagination">' . $pagination . '</div>';
            }
        } else {
            echo '<p class="wp-gpt-empty">' . esc_html__('No logs found.', 'wp-gpt-automation-pro') . '</p>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
}
