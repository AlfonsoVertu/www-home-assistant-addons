<?php

namespace WP_GPT_Automation_Pro;

class WP_GPT_Activation {
    private const DB_VERSION_OPTION = 'wp_gpt_queue_tables_version';

    public static function activate() {
        self::run_install_routines();
        self::schedule_events();
    }

    public static function maybe_run_db_upgrades() {
        $stored_version = get_option(self::DB_VERSION_OPTION);

        if (is_string($stored_version) && version_compare($stored_version, WP_GPT_VERSION, '>=')) {
            return;
        }

        self::run_install_routines();
    }

    private static function run_install_routines() {
        global $wpdb;

        // Ottieni il prefisso delle tabelle del database
        $charset_collate = $wpdb->get_charset_collate();

        // Crea la tabella per la gestione della coda
        $queue_table = $wpdb->prefix . 'gpt_queue';
        $sql_queue = "CREATE TABLE $queue_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            task_type varchar(50) NOT NULL,
            task_data longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY task_type (task_type),
            KEY status (status)
        ) $charset_collate;";

        $queue_log_table = $wpdb->prefix . 'gpt_queue_log';
        $sql_queue_log = "CREATE TABLE $queue_log_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id bigint(20) UNSIGNED NOT NULL,
            message varchar(255) NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY task_id (task_id)
        ) $charset_collate;";

        // Crea la tabella per lo user_code
        $user_code_table = $wpdb->prefix . 'gpt_user_codes';
        $sql_user_code = "CREATE TABLE $user_code_table (
            user_id bigint(20) NOT NULL,
            code varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (user_id)
        ) $charset_collate;";

        // Crea la tabella per le trascrizioni di YouTube
        $transcriptions_table = $wpdb->prefix . 'youtube_transcriptions';
        $sql_transcriptions = "CREATE TABLE $transcriptions_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            video_url varchar(255) NOT NULL,
            transcription longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY video_url (video_url)
        ) $charset_collate;";

        // Create placeholders and context tables
        $placeholders_table = $wpdb->prefix . 'gpt_placeholders';
        $sql_placeholders = "CREATE TABLE $placeholders_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            fake_value varchar(191) NOT NULL,
            real_value text NOT NULL,
            context varchar(50) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY fake_context (fake_value, context)
        ) $charset_collate;";

        $contexts_table = $wpdb->prefix . 'gpt_placeholder_contexts';
        $sql_contexts = "CREATE TABLE $contexts_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(50) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY name (name)
        ) $charset_collate;";

        $rel_table = $wpdb->prefix . 'gpt_placeholder_context_rel';
        $sql_rel = "CREATE TABLE $rel_table (
            placeholder_id mediumint(9) NOT NULL,
            context_id mediumint(9) NOT NULL,
            PRIMARY KEY (placeholder_id, context_id),
            KEY context_id (context_id)
        ) $charset_collate;";

        // Tabella per i log di autenticazione
        $auth_log_table = $wpdb->prefix . 'gpt_auth_logs';
        $sql_auth_log = "CREATE TABLE $auth_log_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            time datetime NOT NULL,
            bearer_received text NOT NULL,
            bearer_valid text NOT NULL,
            user_code_received text NOT NULL,
            user_code_valid text NOT NULL,
            params longtext NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Esegui le query SQL per creare le tabelle
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_queue);
        if (!empty($wpdb->last_error)) {
            error_log('[WP_GPT] Error creating gpt_queue table: ' . $wpdb->last_error);
        }

        dbDelta($sql_queue_log);
        if (!empty($wpdb->last_error)) {
            error_log('[WP_GPT] Error creating gpt_queue_log table: ' . $wpdb->last_error);
        }

        dbDelta($sql_user_code);
        if (!empty($wpdb->last_error)) {
            error_log('[WP_GPT] Error creating gpt_user_codes table: ' . $wpdb->last_error);
        }

        dbDelta($sql_transcriptions);
        if (!empty($wpdb->last_error)) {
            error_log('[WP_GPT] Error creating youtube_transcriptions table: ' . $wpdb->last_error);
        }

        dbDelta($sql_placeholders);
        if (!empty($wpdb->last_error)) {
            error_log('[WP_GPT] Error creating gpt_placeholders table: ' . $wpdb->last_error);
        }

        dbDelta($sql_contexts);
        if (!empty($wpdb->last_error)) {
            error_log('[WP_GPT] Error creating gpt_placeholder_contexts table: ' . $wpdb->last_error);
        }

        dbDelta($sql_rel);
        if (!empty($wpdb->last_error)) {
            error_log('[WP_GPT] Error creating gpt_placeholder_context_rel table: ' . $wpdb->last_error);
        }

        dbDelta($sql_auth_log);
        if (!empty($wpdb->last_error)) {
            error_log('[WP_GPT] Error creating gpt_auth_logs table: ' . $wpdb->last_error);
        }

        // Setup scheduler table
        try {
            \WP_GPT_V1_Route_Scheduler::maybe_create_table();
            if (!empty($wpdb->last_error)) {
                error_log('[WP_GPT] Error creating scheduler table: ' . $wpdb->last_error);
            }
        } catch (\Throwable $e) {
            error_log('[WP_GPT] Exception creating scheduler table: ' . $e->getMessage());
        }

        // Setup memory manager tables
        try {
            $memory = new \WpGpt\AutomationPro\Memory\Memory_Manager();
            $res = $memory->maybe_create_tables();
            if (is_array($res) && isset($res['success']) && !$res['success']) {
                error_log('[WP_GPT] Memory table setup error: ' . ($res['error'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            error_log('[WP_GPT] Exception creating memory tables: ' . $e->getMessage());
        }

        // Setup translation tables if enabled
        if (defined('WP_GPT_ENABLE_TRANSLATION_ROUTES') && WP_GPT_ENABLE_TRANSLATION_ROUTES) {
            $translation_file = WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-translation.php';
            if (file_exists($translation_file)) {
                require_once $translation_file;
                if (class_exists('WP_GPT_Translate')) {
                    try {
                        new \WP_GPT_Translate();
                        if (!empty($wpdb->last_error)) {
                            error_log('[WP_GPT] Error creating translation tables: ' . $wpdb->last_error);
                        }
                    } catch (\Throwable $e) {
                        error_log('[WP_GPT] Exception creating translation tables: ' . $e->getMessage());
                    }
                }
            }
        }

        update_option(self::DB_VERSION_OPTION, WP_GPT_VERSION);
    }

    private static function schedule_events() {
        if (!wp_next_scheduled('wp_gpt_cron_job')) {
            wp_schedule_event(time(), 'hourly', 'wp_gpt_cron_job');
        }
    }
}
