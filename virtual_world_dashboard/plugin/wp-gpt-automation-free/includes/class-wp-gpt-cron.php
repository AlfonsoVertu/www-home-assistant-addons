<?php

namespace WP_GPT_Automation_Pro;

use WP_Error;
use WP_GPT_Automation_Pro\WP_GPT_User_Helper;

class WP_GPT_Cron {

    /**
     * Numero massimo di task da processare per batch.
     */
    const DEFAULT_BATCH_SIZE = 20;

    public static function init() {
        // Hook per il job del cron
        add_action('wp_gpt_cron_job', [__CLASS__, 'process_queue']);
    }

    /**
     * Processa i task in coda.
     */
    public static function process_queue() {
        WP_GPT_Queue::maybe_create_tables();

        $queue_table = WP_GPT_Queue::get_queue_table_name();
        if (!WP_GPT_Queue::table_exists($queue_table)) {
            return;
        }

        $batch_size = self::DEFAULT_BATCH_SIZE;
        if (function_exists('apply_filters')) {
            $batch_size = absint(apply_filters('wp_gpt_queue_batch_size', self::DEFAULT_BATCH_SIZE));
        }

        if ($batch_size <= 0) {
            $batch_size = self::DEFAULT_BATCH_SIZE;
        }

        $tasks = WP_GPT_Queue::get_pending_tasks($batch_size);

        if (empty($tasks)) {
            return;
        }

        $last_processed_id = 0;

        foreach ($tasks as $task) {
            // Decodifica i dati del task
            $task_data = maybe_unserialize($task->task_data);

            // Log start of task processing
            self::log_task_action($task->id, 'Processing started');

            // Switch in base al tipo di task
            switch ($task->task_type) {
                case 'create_post':
                    $result = self::handle_create_post($task_data);
                    break;

                case 'update_post':
                    $result = self::handle_update_post($task_data);
                    break;

                case 'send_email':
                    $result = self::handle_send_email($task_data);
                    break;

                case 'seo_optimize':
                    $result = self::handle_seo_optimize($task_data);
                    break;

                default:
                    $result = new WP_Error('unknown_task', __('Tipo di task sconosciuto', 'wp-gpt-automation-pro'));
                    break;
            }

            // Aggiorna lo stato del task in base al risultato
            if (is_wp_error($result)) {
                WP_GPT_Queue::update_task_status($task->id, 'failed');
                error_log('Errore nel processare il task ID: ' . $task->id . '. Messaggio: ' . $result->get_error_message());
                self::log_task_action($task->id, 'Processing failed: ' . $result->get_error_message());
            } else {
                WP_GPT_Queue::update_task_status($task->id, 'completed');
                self::log_task_action($task->id, 'Processing completed successfully');
            }

            $last_processed_id = max($last_processed_id, intval($task->id));
        }

        if ($last_processed_id > 0) {
            $has_more = !empty(WP_GPT_Queue::get_pending_tasks(1, $last_processed_id));

            if ($has_more && function_exists('wp_schedule_single_event')) {
                $delay = 30;

                if (function_exists('apply_filters')) {
                    $delay = absint(apply_filters('wp_gpt_queue_reschedule_delay', $delay));
                }

                $next_run = time() + $delay;
                $next_scheduled = function_exists('wp_next_scheduled') ? wp_next_scheduled('wp_gpt_cron_job') : false;

                if (!$next_scheduled || $next_scheduled > $next_run) {
                    wp_schedule_single_event($next_run, 'wp_gpt_cron_job');
                }
            }
        }
    }

    private static function handle_create_post($task_data) {
        if (!isset($task_data['title'], $task_data['content'])) {
            return new WP_Error('missing_data', __('Dati mancanti per la creazione del post.', 'wp-gpt-automation-pro'));
        }

        $author_id = isset($task_data['author']) ? intval($task_data['author']) : 0;
        $author_is_valid = $author_id > 0;

        if ($author_is_valid && function_exists('get_user_by')) {
            $author_is_valid = (bool) get_user_by('id', $author_id);
        }

        $lowest_id = WP_GPT_User_Helper::get_lowest_admin_id();

        if (is_wp_error($lowest_id)) {
            error_log('Errore nel recuperare l\'amministratore predefinito: ' . $lowest_id->get_error_message());
            $lowest_id = 0;
        }

        if (!$author_is_valid) {
            $author_id = $lowest_id > 0 ? $lowest_id : get_current_user_id();
        }

        if ($author_id <= 0) {
            $author_id = get_current_user_id();
        }

        $meta_input = [];

        if (isset($task_data['meta_input']) && is_array($task_data['meta_input'])) {
            $meta_input = $task_data['meta_input'];
        }

        $meta_input['wp_gpt_skipper_id'] = $lowest_id;

        $post_args = [
            'post_title'    => sanitize_text_field($task_data['title']),
            'post_content'  => wp_kses_post($task_data['content']),
            'post_status'   => isset($task_data['status']) && in_array($task_data['status'], ['publish', 'draft']) ? $task_data['status'] : 'draft',
            'post_author'   => $author_id,
            'post_category' => isset($task_data['categories']) ? array_map('intval', $task_data['categories']) : [],
            'meta_input'    => $meta_input,
        ];

        $post_id = wp_insert_post($post_args);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Gestione SEO se presente
        if (isset($task_data['seo'])) {
            WP_GPT_SEO::optimize_post($post_id, $task_data['seo']);
        }

        // Imposta immagine in evidenza se presente
        if (isset($task_data['featured_image'])) {
            self::set_featured_image($post_id, $task_data['featured_image']);
        }

        return true;
    }

    private static function handle_update_post($task_data) {
        if (!isset($task_data['id'])) {
            return new WP_Error('missing_data', __('ID del post mancante.', 'wp-gpt-automation-pro'));
        }

        $post_id = intval($task_data['id']);
        if (!get_post($post_id)) {
            return new WP_Error('post_not_found', __('Post non trovato.', 'wp-gpt-automation-pro'));
        }

        $post_args = [
            'ID'            => $post_id,
            'post_title'    => isset($task_data['title']) ? sanitize_text_field($task_data['title']) : '',
            'post_content'  => isset($task_data['content']) ? wp_kses_post($task_data['content']) : '',
            'post_status'   => isset($task_data['status']) && in_array($task_data['status'], ['publish', 'draft']) ? $task_data['status'] : 'draft',
            'post_category' => isset($task_data['categories']) ? array_map('intval', $task_data['categories']) : [],
        ];

        $updated_post_id = wp_update_post($post_args);

        if (is_wp_error($updated_post_id)) {
            return $updated_post_id;
        }

        // Gestione SEO se presente
        if (isset($task_data['seo'])) {
            WP_GPT_SEO::optimize_post($updated_post_id, $task_data['seo']);
        }

        // Imposta immagine in evidenza se presente
        if (isset($task_data['featured_image'])) {
            self::set_featured_image($updated_post_id, $task_data['featured_image']);
        }

        return true;
    }

    private static function handle_send_email($task_data) {
        if (!isset($task_data['to'], $task_data['subject'], $task_data['message'])) {
            return new WP_Error('missing_data', __('Dati mancanti per inviare l\'email.', 'wp-gpt-automation-pro'));
        }

        $to = sanitize_email($task_data['to']);
        $subject = sanitize_text_field($task_data['subject']);
        $message = wp_kses_post($task_data['message']);

        if (!is_email($to)) {
            return new WP_Error('invalid_email', __('Indirizzo email non valido.', 'wp-gpt-automation-pro'));
        }

        wp_mail($to, $subject, $message);

        return true;
    }

    private static function handle_seo_optimize($task_data) {
        if (!isset($task_data['post_id'])) {
            return new WP_Error('missing_data', __('ID del post mancante.', 'wp-gpt-automation-pro'));
        }

        $post_id = intval($task_data['post_id']);
        if (!get_post($post_id)) {
            return new WP_Error('post_not_found', __('Post non trovato.', 'wp-gpt-automation-pro'));
        }

        WP_GPT_SEO::optimize_post($post_id, $task_data['seo']);

        return true;
    }

    private static function set_featured_image($post_id, $image_url) {
        $image_id = media_sideload_image($image_url, $post_id, null, 'id');
        if (!is_wp_error($image_id)) {
            set_post_thumbnail($post_id, $image_id);
        }
    }

    private static function log_task_action($task_id, $message) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gpt_queue_log';
        $wpdb->insert($table_name, [
            'task_id' => intval($task_id),
            'message' => sanitize_text_field($message),
            'timestamp' => current_time('mysql'),
        ]);
    }
}

WP_GPT_Cron::init();
