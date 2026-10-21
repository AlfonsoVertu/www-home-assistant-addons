<?php

namespace WP_GPT_Automation_Pro;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpGpt\AutomationPro\Memory\Memory_Manager;
use function sanitize_text_field;

/**
 * Class WP_GPT_V1_Route_Scheduler
 *
 * Scheduler avanzato per job asincroni, automazioni e polling condizionale
 * su tutte le REST route (V1 e V2) di WP GPT Automation Pro.
 *
 * Copia in /includes/class-wp-gpt-v1-route-scheduler.php
 * Includi dal file principale del plugin!
 */

if (!class_exists(__NAMESPACE__ . '\\WP_GPT_V1_Route_Scheduler')) {
    class WP_GPT_V1_Route_Scheduler {
    const ROUTE_PATH = 'route-scheduler';
    const LOG_DIR = WP_GPT_PLUGIN_DIR . 'logs/';
    const CRON_ACTIVITY_LOG = 'scheduler_cron_activity.log';
    public static $logging_enabled = true;
    private $table;
    private $last_execution_details = null;

    /**
     * Costruttore: crea tabella e log dir, registra la rotta REST.
     */
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'gpt_scheduler_jobs';

        if (!self::table_exists($this->table)) {
            self::maybe_create_table();
        }
        if (!\file_exists(self::LOG_DIR)) \mkdir(self::LOG_DIR, 0775, true);
        add_filter('cron_schedules', [__CLASS__, 'register_minute_interval']);
        add_action('rest_api_init', [$this, 'register_route']);
        // Attiva polling condition (eseguito ogni 60 secondi via WP Cron)
        add_action('wp_gpt_scheduler_condition_polling', [$this, 'poll_condition_jobs']);

        $has_active_jobs = $this->has_active_jobs();

        $this->ensure_condition_polling_event();

        if ($has_active_jobs) {
            $this->maybe_trigger_wp_cron();
        }
    }

    /**
     * Crea/aggiorna la tabella custom con dbDelta
     */
    public static function maybe_create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'gpt_scheduler_jobs';

        if (self::table_exists($table)) {
            return;
        }
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          job_key VARCHAR(128) NOT NULL,
          user_code VARCHAR(128) NOT NULL,
          definition LONGTEXT NOT NULL,
          status ENUM('pending','active','done','failed','disabled') DEFAULT 'pending',
          result LONGTEXT NULL,
          error LONGTEXT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          last_run DATETIME NULL,
          scheduled_for DATETIME NULL,
          PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private static function table_exists($table_name) {
        global $wpdb;

        $like = $wpdb->esc_like($table_name);
        $query = $wpdb->prepare('SHOW TABLES LIKE %s', $like);

        return $wpdb->get_var($query) === $table_name;
    }

    public static function register_minute_interval($schedules) {
        if (!isset($schedules['minute'])) {
            $schedules['minute'] = [
                'interval' => 60,
                'display'  => __('Ogni minuto', 'wp-gpt-automation-pro'),
            ];
        }

        return $schedules;
    }

    /**
     * Registra la rotta REST
     */
    public function register_route() {
        register_rest_route('wp-gpt/v1', '/' . self::ROUTE_PATH, [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_request'],
            'permission_callback' => ['WP_GPT_Automation_Pro\\WP_GPT_API', 'validate_request_with_user_code'],
            'args' => [
                'action'     => ['required' => true, 'type' => 'string'],
                'job_key'    => ['required' => false, 'type' => 'string'],
                'definition' => ['required' => false],
                'user_code'  => ['required' => true, 'type' => 'string'],
                'logging'    => ['required' => false, 'type' => 'boolean'],
            ],
        ]);
    }

    /**
     * Handler REST principale
     */
    public function handle_request($request) {
        global $wpdb;
        $action     = $request->get_param('action');
        $user_code  = $request->get_param('user_code');
        $job_key    = $request->get_param('job_key');
        if (is_string($job_key)) {
            $job_key = sanitize_text_field($job_key);
        } else {
            $job_key = '';
        }
        $definition = $request->get_param('definition');
        $logging    = $request->get_param('logging');
        $result     = ['success' => false, 'action' => $action];

        if (!is_null($logging)) self::$logging_enabled = (bool)$logging;

        switch ($action) {
            case 'add_job':
                if (!$job_key || !$definition) {
                    $result['error'] = 'job_key e definition obbligatori';
                    break;
                }
                $prepared_definition = $this->prepare_definition($definition, $user_code, $request);
                if (is_wp_error($prepared_definition)) {
                    $result['error'] = $prepared_definition->get_error_message();
                    break;
                }
                $scheduled_for = $this->calculate_initial_schedule($prepared_definition);
                $wpdb->insert($this->table, [
                    'job_key'       => $job_key,
                    'user_code'     => $user_code,
                    'definition'    => wp_json_encode($prepared_definition),
                    'status'        => 'active',
                    'created_at'    => current_time('mysql'),
                    'scheduled_for' => $scheduled_for,
                ]);
                $result['success'] = true;
                $result['job_id']  = $wpdb->insert_id;
                break;

            case 'remove_job':
                $wpdb->delete($this->table, ['job_key' => $job_key]);
                $result['success'] = true;
                break;

            case 'list_jobs':
                $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY created_at DESC", ARRAY_A);
                $result['success'] = true;
                $result['jobs']    = $rows;
                break;

            case 'run_job':
                $execution = $this->execute_job($job_key);
                if ($execution instanceof WP_Error) {
                    $result['error'] = $execution->get_error_message();
                    break;
                }
                $result = array_merge($result, $execution);
                break;

            case 'get_log':
                $logfile = self::LOG_DIR . "scheduler_{$job_key}.log";
                $result['success'] = true;
                $result['log']     = file_exists($logfile) ? file_get_contents($logfile) : '';
                break;

            case 'set_logging':
                if (is_null($logging)) {
                    $result['error'] = 'Parametro logging mancante';
                    break;
                }
                self::$logging_enabled = (bool)$logging;
                $result['success'] = true;
                $result['logging'] = self::$logging_enabled;
                break;

            default:
                $result['error'] = 'Azione non supportata';
        }

        $has_active_jobs = $this->has_active_jobs();

        $this->ensure_condition_polling_event();

        if ($has_active_jobs) {
            $this->maybe_trigger_wp_cron();
        }

        return rest_ensure_response($result);
    }

    /**
     * Logging su file
     */
    private function job_log($job_key, $msg) {
        if (!self::$logging_enabled) return;
        $logfile = self::LOG_DIR . "scheduler_{$job_key}.log";
        $timestamp = $this->format_wp_iso8601();
        file_put_contents($logfile, $timestamp . " $msg\n", FILE_APPEND);
    }

    /**
     * Logging sempre attivo per tracciare l'invocazione del cron.
     */
    private function cron_activity_log($action, array $context = []) {
        $logfile = self::LOG_DIR . self::CRON_ACTIVITY_LOG;
        $timestamp = $this->format_wp_iso8601();

        $entry = [
            'action'            => $action,
            'wp_cron_disabled'  => defined('DISABLE_WP_CRON') ? (bool) DISABLE_WP_CRON : false,
            'context'           => $context,
        ];

        \file_put_contents(
            $logfile,
            $timestamp . ' ' . \wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );
    }

    /**
     * Logging diagnostico per i controlli di pianificazione
     */
    private function scheduler_debug_log($job_key, $action, array $context = []) {
        if (!self::$logging_enabled) return;

        $logfile = self::LOG_DIR . 'scheduler_checks.log';
        $timestamp = $this->format_wp_iso8601();
        $entry = [
            'job_key' => $job_key,
            'action'  => $action,
            'data'    => $context,
        ];

        \file_put_contents(
            $logfile,
            $timestamp . ' ' . \wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );
    }

    /**
     * Esegue la rotta target, supporta tutte le REST route V1/V2, logging output
     */
    private function run_job_now($definition, $job_key, $fallback_user_code = null) {
        $this->last_execution_details = null;

        $route             = $definition['target_route'] ?? null;
        $payload           = $definition['payload'] ?? [];
        $headers_for_memory = [];

        if (!is_array($payload)) {
            $payload = [];
        }

        if (!isset($payload['user_code'])) {
            $user_code = $definition['user_code'] ?? $fallback_user_code;
            if ($user_code) {
                $payload['user_code'] = $user_code;
            }
        }

        if (!$route) {
            $this->job_log($job_key, "Errore: Nessuna rotta target definita");
            return ['error' => 'Nessuna rotta target definita'];
        }

        // SUPPORTO CONDIZIONE POLLING
        if (isset($definition['schedule']['type']) && $definition['schedule']['type'] === 'condition_polling') {
            if (!$this->check_condition_polling($definition['schedule'])) {
                $this->job_log($job_key, "Condizione polling non verificata, nessuna azione.");
                return ['info' => 'Condizione non soddisfatta, nessuna esecuzione'];
            }
        }

        // Recupera la callback REST registrata per la rotta (compatibile V1/V2)
        $callback = $this->get_route_callback($route);

        if (!$callback) {
            $this->job_log($job_key, "Errore: Callback non trovata per $route");
            return ['error' => 'Callback non trovata'];
        }

        try {
            // Simula una richiesta REST vera
            $normalized_route = '/' . ltrim($route, '/');
            $wp_rest_request  = new WP_REST_Request('POST', $normalized_route);
            foreach ($payload as $k => $v) {
                $wp_rest_request->set_param($k, $v);
            }

            if (!empty($payload)) {
                $encoded_payload = wp_json_encode($payload);
                if (false !== $encoded_payload) {
                    $wp_rest_request->set_body($encoded_payload);
                    $wp_rest_request->set_header('Content-Type', 'application/json');
                }
                $wp_rest_request->set_body_params($payload);
            }

            // PATCH: Inietta automaticamente il Bearer Token corrente da wp_options
            $bearer_token = get_option('wp_gpt_api_token');
            if (!$bearer_token) {
                $bearer_token = $this->extract_bearer_from_definition($definition);
            }
            if ($bearer_token) {
                $wp_rest_request->set_header('Authorization', 'Bearer ' . $bearer_token);
            }

            $headers_for_memory = $this->sanitize_headers_for_memory($wp_rest_request->get_headers());

            $response = call_user_func($callback, $wp_rest_request);

            $this->last_execution_details = [
                'job_key'  => $job_key,
                'route'    => ltrim($normalized_route, '/'),
                'payload'  => $payload,
                'headers'  => $headers_for_memory,
                'response' => $response,
            ];

            $this->job_log($job_key, "Output: " . substr(json_encode($response), 0, 1000));
            return $response;
        } catch (\Throwable $e) {
            $this->last_execution_details = [
                'job_key'  => $job_key,
                'route'    => is_string($route) ? ltrim($route, '/') : '',
                'payload'  => $payload,
                'headers'  => $headers_for_memory,
                'response' => ['error' => $e->getMessage()],
            ];

            $this->job_log($job_key, "Errore esecuzione callback: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    private function prepare_definition($definition, $user_code, $request) {
        $definition = $this->normalize_definition_input($definition);
        if (is_wp_error($definition)) {
            return $definition;
        }

        if (isset($definition['target_route']) && is_string($definition['target_route'])) {
            $definition['target_route'] = $this->normalize_target_route($definition['target_route']);
        }

        $validation = $this->validate_definition_structure($definition);
        if (is_wp_error($validation)) {
            return $validation;
        }

        if ($user_code) {
            if (!isset($definition['payload']['user_code']) || $definition['payload']['user_code'] !== $user_code) {
                $definition['payload']['user_code'] = $user_code;
            }

            if (!isset($definition['user_code']) || $definition['user_code'] !== $user_code) {
                $definition['user_code'] = $user_code;
            }
        }

        $auth_header = $request->get_header('authorization');
        if ($auth_header && !isset($definition['headers']['Authorization'])) {
            if (!isset($definition['headers']) || !is_array($definition['headers'])) {
                $definition['headers'] = [];
            }
            $definition['headers']['Authorization'] = $auth_header;
        }

        return $definition;
    }

    private function normalize_definition_input($definition) {
        if (is_string($definition)) {
            $decoded = json_decode($definition, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $definition = $decoded;
            }
        } elseif (is_object($definition)) {
            $definition = json_decode(wp_json_encode($definition), true);
        }

        if (!is_array($definition)) {
            return new WP_Error('invalid_definition', 'Definition deve essere un oggetto');
        }

        $payload = $definition['payload'] ?? [];
        $payload = $this->normalize_definition_subarray($payload, 'payload', []);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $definition['payload'] = $payload;

        if (isset($definition['headers'])) {
            $headers = $this->normalize_definition_subarray($definition['headers'], 'headers', []);
            if (is_wp_error($headers)) {
                return $headers;
            }
            $definition['headers'] = $headers;
        }

        if (isset($definition['schedule'])) {
            $schedule = $this->normalize_definition_subarray($definition['schedule'], 'schedule', []);
            if (is_wp_error($schedule)) {
                return $schedule;
            }
            $definition['schedule'] = $schedule;
        }

        return $definition;
    }

    private function normalize_definition_subarray($value, $field, $default = []) {
        if (is_null($value) || $value === '') {
            return $default;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            $converted = json_decode(wp_json_encode($value), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($converted)) {
                return $converted;
            }
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return new WP_Error('invalid_' . $field, sprintf('%s deve essere un oggetto o un array valido', ucfirst($field)));
    }

    private function normalize_target_route($route) {
        $route = trim((string) $route);

        if ($route === '') {
            return $route;
        }

        if (strpos($route, '://') !== false) {
            $parsed = wp_parse_url($route);
            if (!empty($parsed['path'])) {
                $route = $parsed['path'];
            }
        }

        $route = preg_replace('#^/?wp-json#i', '', $route);

        return '/' . ltrim($route, '/');
    }

    private function validate_definition_structure(array $definition) {
        if (empty($definition['target_route']) || !is_string($definition['target_route'])) {
            return new WP_Error('invalid_target_route', 'Il campo target_route deve essere una stringa non vuota');
        }

        if (strpos($definition['target_route'], '://') !== false) {
            return new WP_Error('invalid_target_route', 'target_route deve essere un percorso REST relativo (es. /wp-gpt/v1/rotta)');
        }

        $payload_validation = $this->validate_payload_structure($definition['payload']);
        if (is_wp_error($payload_validation)) {
            return $payload_validation;
        }

        if (!empty($definition['schedule'])) {
            $schedule_validation = $this->validate_schedule_structure($definition['schedule']);
            if (is_wp_error($schedule_validation)) {
                return $schedule_validation;
            }
        }

        if (isset($definition['headers']) && !is_array($definition['headers'])) {
            return new WP_Error('invalid_headers', 'Headers deve essere un oggetto o array valido');
        }

        return true;
    }

    private function validate_payload_structure(array $payload) {
        foreach ($payload as $key => $value) {
            if (!is_scalar($key) && !is_null($key)) {
                return new WP_Error('invalid_payload', 'Le chiavi del payload devono essere scalari');
            }

            if (is_resource($value)) {
                return new WP_Error('invalid_payload', 'Il payload contiene valori non serializzabili');
            }
        }

        return true;
    }

    private function validate_schedule_structure(array $schedule) {
        if (isset($schedule['type']) && !is_string($schedule['type'])) {
            return new WP_Error('invalid_schedule', 'Il tipo di schedule deve essere una stringa');
        }

        $allowed_types = apply_filters('wp_gpt_scheduler_allowed_schedule_types', ['once', 'interval', 'condition_polling']);
        if (isset($schedule['type']) && $schedule['type'] !== '' && !in_array($schedule['type'], $allowed_types, true)) {
            return new WP_Error('invalid_schedule', sprintf('Tipo di schedule "%s" non supportato', $schedule['type']));
        }

        foreach (['interval', 'delay'] as $numeric_key) {
            if (isset($schedule[$numeric_key]) && !is_numeric($schedule[$numeric_key])) {
                return new WP_Error('invalid_schedule', sprintf('Il campo %s deve essere numerico', $numeric_key));
            }
        }

        if (isset($schedule['timestamp']) && $schedule['timestamp'] !== '' && !$this->normalize_schedule_timestamp($schedule['timestamp'])) {
            return new WP_Error('invalid_schedule', 'Timestamp pianificato non valido');
        }

        foreach (['run_at', 'datetime', 'date', 'when', 'at'] as $alt_timestamp) {
            if (isset($schedule[$alt_timestamp]) && $schedule[$alt_timestamp] !== '' && !$this->normalize_schedule_timestamp($schedule[$alt_timestamp])) {
                return new WP_Error('invalid_schedule', sprintf('Il valore %s del schedule non è valido', $alt_timestamp));
            }
        }

        if (($schedule['type'] ?? '') === 'condition_polling') {
            if (empty($schedule['content_id']) || empty($schedule['field'])) {
                return new WP_Error('invalid_schedule', 'I job condition_polling richiedono content_id e field');
            }
        }

        return true;
    }

    /**
     * Esegue un job specifico immediatamente e aggiorna lo stato nel database.
     *
     * @param string $job_key
     * @return array|WP_Error
     */
    public function execute_job($job_key) {
        global $wpdb;

        if (!is_string($job_key) || $job_key === '') {
            return new WP_Error('invalid_job_key', __('Job key non valido', 'wp-gpt-automation-pro'));
        }

        $job_key = sanitize_text_field($job_key);

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE job_key=%s", $job_key), ARRAY_A);
        if (!$row) {
            return new WP_Error('job_not_found', __('Job non trovato', 'wp-gpt-automation-pro'));
        }

        $def = json_decode($row['definition'], true);
        if (!is_array($def)) {
            return new WP_Error('invalid_definition', __('Definizione del job non valida', 'wp-gpt-automation-pro'));
        }

        $output = $this->run_job_now($def, $job_key, $row['user_code'] ?? null);
        $status = 'done';
        $error_message = null;

        if ($output instanceof WP_Error) {
            $error_message = $output->get_error_message();
            $status = 'failed';
        } elseif (is_array($output) && isset($output['error'])) {
            $error_message = is_scalar($output['error']) ? (string) $output['error'] : wp_json_encode($output['error']);
            $status = 'failed';
        }

        $run_datetime  = current_time('mysql');
        $scheduled_for = $row['scheduled_for'];
        $is_repeating  = false;

        $updates = [
            'result'     => $this->serialize_job_output($output),
            'error'      => $error_message,
            'last_run'   => $run_datetime,
            'updated_at' => $run_datetime,
        ];

        $original_status = $row['status'];
        $original_schedule = $row['scheduled_for'];

        if (!empty($original_schedule)) {
            $updates['status'] = $original_status;
            $next_run = $original_schedule;
            $is_repeating = !empty($next_run);
        } else {
            $reference_timestamp = $this->parse_wp_datetime($row['scheduled_for']);
            if (is_null($reference_timestamp)) {
                $reference_timestamp = $this->get_current_wp_timestamp();
            }

            $next_run = $this->calculate_next_schedule($def, $reference_timestamp);
            $is_repeating = !is_null($next_run);

            $updates['status'] = $is_repeating ? 'active' : $status;
            $updates['scheduled_for'] = $next_run;
        }

        $wpdb->update($this->table, $updates, ['job_key' => $job_key]);

        $this->maybe_store_job_execution_in_memory(
            $row,
            $def,
            $output,
            $run_datetime,
            $scheduled_for,
            $status,
            $error_message,
            $is_repeating,
            true
        );

        return [
            'success'  => is_null($error_message),
            'output'   => $output,
            'status'   => $updates['status'],
            'last_run' => $run_datetime,
            'next_run' => $next_run,
            'error'    => $error_message,
        ];
    }

    private function extract_bearer_from_definition($definition) {
        $auth_header = '';
        if (isset($definition['headers']['Authorization'])) {
            $auth_header = $definition['headers']['Authorization'];
        } elseif (isset($definition['headers']['authorization'])) {
            $auth_header = $definition['headers']['authorization'];
        }

        if (is_string($auth_header) && stripos($auth_header, 'bearer ') === 0) {
            return trim(substr($auth_header, 7));
        }

        return '';
    }

    /**
     * Trova la callback PHP per qualsiasi rotta REST (V1/V2)
     */
    private function get_route_callback($route) {
        global $wp_rest_server;
        if (!$wp_rest_server) {
            $wp_rest_server = rest_get_server();
        }
        $routes = $wp_rest_server->get_routes();
        foreach ($routes as $pattern => $handlers) {
            // match sia /route che /route/ con o senza parametri
            if (!preg_match('#' . preg_quote($route, '#') . '(/|$)#', $pattern)) {
                continue;
            }

            if (!is_array($handlers)) {
                continue;
            }

            foreach ($handlers as $handler) {
                if (!is_array($handler) || !isset($handler['callback'])) {
                    continue;
                }

                return $handler['callback'];
            }
        }
        return null;
    }

    /**
     * POLLING: controlla tutti i job condition_polling attivi ogni minuto
     */
    public function poll_condition_jobs() {
        global $wpdb;

        $active_job_count = 0;
        $has_active_jobs  = $this->has_active_jobs($active_job_count);

        $this->ensure_condition_polling_event();

        $this->cron_activity_log('poll_condition_jobs_invoked', [
            'active_jobs' => $active_job_count,
        ]);

        if (!$has_active_jobs) {
            $this->cron_activity_log('poll_condition_jobs_no_active_jobs', [
                'active_jobs' => $active_job_count,
            ]);
            return;
        }

        $now = $this->get_current_wp_timestamp();
        $now_mysql = $this->format_wp_datetime($now);
        $batch_size = (int) apply_filters('wp_gpt_scheduler_polling_batch_size', 50);
        if ($batch_size <= 0) {
            $batch_size = 50;
        }
        $max_runtime = (float) apply_filters('wp_gpt_scheduler_polling_max_runtime', 20.0);
        $start_time = microtime(true);
        $last_id = 0;
        $processed_jobs = 0;
        $fetched_jobs = 0;
        $batches_processed = 0;
        $time_limit_reached = false;

        while (true) {
            if ($max_runtime > 0 && (microtime(true) - $start_time) >= $max_runtime) {
                $time_limit_reached = true;
                break;
            }

            $query = $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s AND id > %d ORDER BY id ASC LIMIT %d",
                'active',
                $last_id,
                $batch_size
            );
            $jobs = $wpdb->get_results($query, ARRAY_A);

            if (empty($jobs)) {
                break;
            }

            $batches_processed++;
            $fetched_jobs += count($jobs);

            foreach ($jobs as $job) {
                if ($max_runtime > 0 && (microtime(true) - $start_time) >= $max_runtime) {
                    $time_limit_reached = true;
                    break 2;
                }

                $last_id = max($last_id, (int) $job['id']);
                $def = json_decode($job['definition'], true);
                if (!is_array($def)) {
                    $this->scheduler_debug_log($job['job_key'], 'invalid_definition', [
                        'now_timestamp'      => $now,
                        'now_datetime'       => $now_mysql,
                        'definition_content' => $job['definition'],
                    ]);
                    continue;
                }

                $schedule = isset($def['schedule']) ? $def['schedule'] : [];
                if (is_string($schedule)) {
                    $decoded = json_decode($schedule, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $schedule = $decoded;
                    }
                }
                if (!is_array($schedule)) {
                    $schedule = [];
                }

                $base_debug = [
                    'now_timestamp'        => $now,
                    'now_datetime'         => $now_mysql,
                    'scheduled_for_raw'    => $job['scheduled_for'],
                    'last_run_raw'         => $job['last_run'],
                    'schedule'             => $schedule,
                    'interval_raw'         => isset($schedule['interval']) ? $schedule['interval'] : null,
                ];

                $scheduled_for_ts = $this->parse_wp_datetime($job['scheduled_for']);
                if ($scheduled_for_ts && $scheduled_for_ts > $now) {
                    $this->scheduler_debug_log($job['job_key'], 'scheduled_in_future', $base_debug + [
                        'scheduled_for_timestamp' => $scheduled_for_ts,
                    ]);
                    continue;
                }

                $interval = isset($schedule['interval']) ? (int) $schedule['interval'] : 0;
                if ($interval > 0) {
                    $last = $this->parse_wp_datetime($job['last_run']);
                    if ($last && ($now - $last) < $interval) {
                        $this->scheduler_debug_log($job['job_key'], 'interval_window_active', $base_debug + [
                            'scheduled_for_timestamp' => $scheduled_for_ts,
                            'interval'                => $interval,
                            'last_run_timestamp'      => $last,
                            'seconds_since_last_run'  => $now - $last,
                        ]);
                        continue;
                    }
                } elseif (is_null($scheduled_for_ts)) {
                    // Nessun intervallo o data pianificata: nulla da eseguire.
                    $this->scheduler_debug_log($job['job_key'], 'missing_schedule', $base_debug);
                    continue;
                }

                if (isset($schedule['type']) && $schedule['type'] === 'condition_polling') {
                    if (!$this->check_condition_polling($schedule)) {
                        $this->scheduler_debug_log($job['job_key'], 'condition_not_met', $base_debug + [
                            'scheduled_for_timestamp' => $scheduled_for_ts,
                            'interval'                => $interval,
                        ]);
                        $this->job_log($job['job_key'], "Condizione polling non verificata, nessuna azione.");
                        $reference_timestamp = $scheduled_for_ts ?? $now;
                        $next_run = $this->calculate_next_schedule($def, $reference_timestamp);
                        $wpdb->update($this->table, [
                            'scheduled_for' => $next_run,
                            'updated_at'    => $now_mysql,
                        ], ['job_key' => $job['job_key']]);
                        continue;
                    }
                }

                $this->scheduler_debug_log($job['job_key'], 'execute_job', $base_debug + [
                    'scheduled_for_timestamp' => $scheduled_for_ts,
                    'interval'                => $interval,
                ]);
                $output = $this->run_job_now($def, $job['job_key'], $job['user_code'] ?? null);
                $status = 'done';
                $error_message = null;

                if ($output instanceof WP_Error) {
                    $error_message = $output->get_error_message();
                    $status = 'failed';
                } elseif (is_array($output) && isset($output['error'])) {
                    $error_message = is_scalar($output['error']) ? (string) $output['error'] : wp_json_encode($output['error']);
                    $status = 'failed';
                }

                $reference_timestamp = $scheduled_for_ts ?? $now;
                $next_run = $this->calculate_next_schedule($def, $reference_timestamp);
                $is_repeating = !is_null($next_run);

                $this->scheduler_debug_log($job['job_key'], 'update_schedule', $base_debug + [
                    'scheduled_for_timestamp' => $scheduled_for_ts,
                    'interval'                => $interval,
                    'next_run'                => $next_run,
                    'execution_status'        => $status,
                    'has_error'               => !is_null($error_message),
                ]);
                $processed_jobs++;
                $wpdb->update($this->table, [
                    'status'        => $is_repeating ? 'active' : $status,
                    'result'        => $this->serialize_job_output($output),
                    'error'         => $error_message,
                    'last_run'      => $now_mysql,
                    'updated_at'    => $now_mysql,
                    'scheduled_for' => $next_run,
                ], ['job_key' => $job['job_key']]);

                $this->maybe_store_job_execution_in_memory(
                    $job,
                    $def,
                    $output,
                    $now_mysql,
                    $job['scheduled_for'],
                    $status,
                    $error_message,
                    $is_repeating,
                    false
                );
            }

        }

        $this->cron_activity_log('poll_condition_jobs_completed', [
            'active_jobs_at_start' => $active_job_count,
            'jobs_fetched'         => $fetched_jobs,
            'jobs_processed'       => $processed_jobs,
            'batches_processed'    => $batches_processed,
            'time_limit_reached'   => $time_limit_reached,
        ]);
    }

    private function calculate_initial_schedule($definition, $reference_timestamp = null) {
        if (is_null($reference_timestamp)) {
            $reference_timestamp = $this->get_current_wp_timestamp();
        }

        $timestamp = $this->determine_next_run_timestamp($definition, $reference_timestamp, true);

        return $this->format_schedule_for_storage($timestamp);
    }

    private function calculate_next_schedule($definition, $reference_timestamp = null) {
        if (is_null($reference_timestamp)) {
            $reference_timestamp = $this->get_current_wp_timestamp();
        }

        $timestamp = $this->determine_next_run_timestamp($definition, $reference_timestamp, false);

        return $this->format_schedule_for_storage($timestamp);
    }

    private function determine_next_run_timestamp($definition, $reference_timestamp, $allow_absolute = false) {
        $schedule = $this->normalize_schedule_definition($definition);
        if (is_null($schedule)) {
            return null;
        }

        if ($allow_absolute) {
            $absolute = $this->extract_absolute_timestamp($schedule);
            if ($absolute) {
                return $absolute;
            }
        }

        return $this->extract_repeating_timestamp($schedule, $reference_timestamp);
    }

    private function format_schedule_for_storage($timestamp) {
        if (is_null($timestamp)) {
            return null;
        }

        return $this->format_wp_datetime($timestamp);
    }

    private function normalize_schedule_definition($definition) {
        $schedule = $definition['schedule'] ?? null;
        if (is_string($schedule)) {
            $decoded = json_decode($schedule, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $schedule = $decoded;
            }
        }

        if (!is_array($schedule)) {
            return null;
        }

        return $schedule;
    }

    private function extract_absolute_timestamp(array $schedule) {
        if (isset($schedule['timestamp']) && $schedule['timestamp'] !== '') {
            $timestamp = $this->normalize_schedule_timestamp($schedule['timestamp']);
            if ($timestamp) {
                return $timestamp;
            }
        }

        foreach (['run_at', 'datetime', 'date', 'when', 'at'] as $key) {
            if (!empty($schedule[$key])) {
                $timestamp = $this->normalize_schedule_timestamp($schedule[$key]);
                if ($timestamp) {
                    return $timestamp;
                }
            }
        }

        return null;
    }

    private function extract_repeating_timestamp(array $schedule, $reference_timestamp) {
        foreach (['delay', 'interval'] as $key) {
            if (isset($schedule[$key]) && is_numeric($schedule[$key])) {
                $interval = (int)$schedule[$key];
                if ($interval > 0) {
                    return $reference_timestamp + $interval;
                }
            }
        }

        return null;
    }

    private function normalize_schedule_timestamp($value) {
        if (is_numeric($value)) {
            $timestamp = (int)$value;
            if ($timestamp > 1000000000000) {
                $timestamp = (int) round($timestamp / 1000);
            }
            return $timestamp > 0 ? $timestamp : null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }

            try {
                $timezone = $this->get_wp_timezone();
                $date = new \DateTime($value, $timezone);
                return $date->getTimestamp();
            } catch (\Exception $e) {
                $timestamp = strtotime($value);
                if ($timestamp !== false) {
                    return $timestamp;
                }
            }
        }

        return null;
    }


    private function get_current_wp_timestamp() {
        return current_time('timestamp', true);
    }

    private function get_wp_timezone() {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $timezone_string = get_option('timezone_string');
        if ($timezone_string) {
            try {
                return new \DateTimeZone($timezone_string);
            } catch (\Exception $e) {
                // Fallback gestito sotto.
            }
        }

        $offset = (float) get_option('gmt_offset', 0);
        $hours = (int) $offset;
        $minutes = abs($offset - $hours) * 60;
        $sign = $offset < 0 ? '-' : '+';
        $formatted_offset = sprintf('%s%02d:%02d', $sign, abs($hours), $minutes);

        return new \DateTimeZone($formatted_offset);
    }

    private function format_wp_datetime($timestamp = null) {
        if (is_null($timestamp)) {
            return current_time('mysql');
        }

        try {
            $date = new \DateTime('@' . (int) $timestamp);
            $date->setTimezone($this->get_wp_timezone());

            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return current_time('mysql');
        }
    }

    private function format_wp_iso8601($timestamp = null) {
        if (is_null($timestamp)) {
            $timestamp = $this->get_current_wp_timestamp();
        }

        return wp_date(DATE_ATOM, $timestamp, $this->get_wp_timezone());
    }

    private function parse_wp_datetime($datetime_string) {
        if (empty($datetime_string)) {
            return null;
        }

        $timezone = $this->get_wp_timezone();

        $formats = ['Y-m-d H:i:s', DATE_ATOM, DATE_RFC3339];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $datetime_string, $timezone);
            if ($date instanceof \DateTimeImmutable) {
                return $date->getTimestamp();
            }
        }

        try {
            $date = new \DateTimeImmutable($datetime_string, $timezone);
            return $date->getTimestamp();
        } catch (\Exception $e) {
            $timestamp = strtotime($datetime_string);
            return $timestamp ?: null;
        }
    }


    private function maybe_store_job_execution_in_memory(array $job, array $definition, $output, $executed_at_mysql, $scheduled_for, $status, $error_message, $is_repeating, $force_store = false) {
        if ($status !== 'done' || !is_null($error_message)) {
            return;
        }

        if (!$force_store && !$this->is_cron_context()) {
            return;
        }

        $user_code = isset($job['user_code']) ? sanitize_text_field($job['user_code']) : '';
        if ($user_code === '') {
            return;
        }

        if (!class_exists(Memory_Manager::class)) {
            return;
        }

        global $wp_gpt_memory_manager;

        if (!($wp_gpt_memory_manager instanceof Memory_Manager)) {
            $wp_gpt_memory_manager = new Memory_Manager();
        }

        $job_key = isset($job['job_key']) ? sanitize_text_field($job['job_key']) : '';
        $execution_details = $this->consume_last_execution_details($job_key);
        $target_route = '';
        if (isset($definition['target_route']) && is_string($definition['target_route'])) {
            $target_route = $definition['target_route'];
        }

        if (is_array($execution_details) && isset($execution_details['route']) && is_string($execution_details['route'])) {
            $target_route = $execution_details['route'];
        }

        $target_route = $this->normalize_route_for_memory($target_route, $job_key);

        $payload_source = $definition['payload'] ?? [];
        if (is_array($execution_details) && array_key_exists('payload', $execution_details)) {
            $payload_source = $execution_details['payload'];
        }

        $payload_for_memory  = $this->sanitize_payload_for_memory($payload_source);
        if ($payload_for_memory === null || $payload_for_memory === '') {
            $payload_for_memory = [];
        }

        $response_source = $output;
        if (is_array($execution_details) && array_key_exists('response', $execution_details)) {
            $response_source = $execution_details['response'];
        }

        $response_for_memory = $this->normalize_output_for_memory($response_source);
        if ($response_for_memory === null) {
            $response_for_memory = [];
        }
        $headers_source = $definition['headers'] ?? [];
        if (is_array($execution_details) && array_key_exists('headers', $execution_details)) {
            $headers_source = $execution_details['headers'];
        }

        $headers_for_memory = $this->sanitize_headers_for_memory($headers_source);
        if ($headers_for_memory === null || $headers_for_memory === '') {
            $headers_for_memory = [];
        }

        $schedule_meta        = $this->normalize_schedule_for_memory($definition['schedule'] ?? null);
        $definition_for_memory = $this->sanitize_definition_for_memory($definition);

        $goal_title = sprintf(
            __('Cron job %s completato', 'wp-gpt-automation-pro'),
            $job_key ? $job_key : __('sconosciuto', 'wp-gpt-automation-pro')
        );

        $schedule_created_at = $this->normalize_datetime_field_for_memory($job['created_at'] ?? null);
        $schedule_updated_at = $this->normalize_datetime_field_for_memory($job['updated_at'] ?? null);
        $last_run            = $this->normalize_datetime_field_for_memory($job['last_run'] ?? null);
        $scheduled_for_meta  = $this->normalize_datetime_field_for_memory($scheduled_for);
        $executed_at_meta    = $this->normalize_datetime_field_for_memory($executed_at_mysql);

        $schedule_snapshot = $this->build_schedule_snapshot_for_memory(
            $schedule_created_at,
            $schedule_updated_at,
            $scheduled_for_meta,
            $executed_at_meta,
            $last_run
        );

        $execution_snapshot = $this->build_execution_snapshot_for_memory($payload_for_memory, $response_for_memory, $headers_for_memory);

        $goal_meta = [
            'job_key'             => $job['job_key'] ?? $job_key,
            'target_route'        => $target_route,
            'scheduled_for'       => $scheduled_for_meta,
            'executed_at'         => $executed_at_meta,
            'schedule_created_at' => $schedule_created_at,
            'schedule_updated_at' => $schedule_updated_at,
            'last_run'            => $last_run,
        ];

        if (!is_null($schedule_meta)) {
            $goal_meta['schedule'] = $schedule_meta;
        }

        if (!empty($schedule_snapshot)) {
            $goal_meta['schedule_snapshot'] = $schedule_snapshot;
        }

        if (!empty($execution_snapshot)) {
            $goal_meta['last_execution'] = $execution_snapshot;
        }

        if (!empty($definition_for_memory)) {
            $goal_meta['definition'] = $definition_for_memory;
        }

        $goal_response = $wp_gpt_memory_manager->create_goal([
            'goal_title' => $goal_title,
            'goal_type'  => 'cron_job',
            'user_code'  => $user_code,
            'meta'       => $goal_meta,
        ]);

        if (!is_array($goal_response) || empty($goal_response['success']) || empty($goal_response['goal_id'])) {
            $error_detail = is_array($goal_response) && isset($goal_response['error'])
                ? $goal_response['error']
                : 'unknown_error';
            $this->job_log($job['job_key'] ?? 'system', 'Memoria cron: creazione goal fallita - ' . $error_detail);
            return;
        }

        $goal_id = (int) $goal_response['goal_id'];

        $step_meta = [
            'job_key'             => $job['job_key'] ?? $job_key,
            'target_route'        => $target_route,
            'payload'             => $payload_for_memory,
            'response'            => $response_for_memory,
            'scheduled_for'       => $scheduled_for_meta,
            'executed_at'         => $executed_at_meta,
            'schedule_created_at' => $schedule_created_at,
            'schedule_updated_at' => $schedule_updated_at,
            'last_run'            => $last_run,
        ];

        if (!empty($headers_for_memory)) {
            $step_meta['headers'] = $headers_for_memory;
        }

        if (!empty($definition_for_memory)) {
            $step_meta['definition'] = $definition_for_memory;
        }

        $step_meta['request'] = $this->build_request_snapshot_for_memory(
            $target_route,
            $payload_for_memory,
            $headers_for_memory,
            $definition_for_memory
        );

        if (!empty($schedule_snapshot)) {
            $step_meta['schedule'] = $schedule_snapshot;
        }

        if (!empty($execution_snapshot)) {
            $step_meta['execution'] = $execution_snapshot;
        }

        $step_label = sprintf(
            __('Esecuzione cron %s', 'wp-gpt-automation-pro'),
            $target_route
        );

        $step_response = $wp_gpt_memory_manager->add_step([
            'goal_id'    => $goal_id,
            'user_code'  => $user_code,
            'step_type'  => 'cron_execution',
            'step_label' => $step_label,
            'status'     => 'done',
            'meta'       => $step_meta,
        ]);

        if (!is_array($step_response) || empty($step_response['success'])) {
            $error_detail = is_array($step_response) && isset($step_response['error'])
                ? $step_response['error']
                : 'unknown_error';
            $this->job_log($job['job_key'] ?? 'system', 'Memoria cron: aggiunta step fallita - ' . $error_detail);
            return;
        }

        $wp_gpt_memory_manager->update_goal([
            'goal_id'   => $goal_id,
            'user_code' => $user_code,
            'status'    => 'done',
        ]);
    }

    private function consume_last_execution_details($job_key) {
        if (!is_array($this->last_execution_details)) {
            return null;
        }

        if (!isset($this->last_execution_details['job_key']) || $this->last_execution_details['job_key'] !== $job_key) {
            return null;
        }

        $details = $this->last_execution_details;
        $this->last_execution_details = null;

        return $details;
    }

    private function normalize_route_for_memory($route, $fallback) {
        if (is_string($route) && $route !== '') {
            return ltrim($route, '/');
        }

        return $fallback ?: 'cron-job';
    }

    private function sanitize_payload_for_memory($payload) {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        if (!is_array($payload)) {
            return $payload;
        }

        $normalized = json_decode(wp_json_encode($payload), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $payload;
        }

        return $this->remove_bearer_tokens($normalized);
    }

    private function remove_bearer_tokens($data) {
        if (is_array($data)) {
            $clean = [];
            foreach ($data as $key => $value) {
                $normalized_key = is_string($key) ? strtolower($key) : $key;

                if ($normalized_key === 'authorization') {
                    continue;
                }

                if (is_string($value) && stripos($value, 'bearer ') === 0) {
                    continue;
                }

                $clean[$key] = $this->remove_bearer_tokens($value);
            }

            return $clean;
        }

        if (is_string($data) && stripos($data, 'bearer ') === 0) {
            return '';
        }

        return $data;
    }

    private function sanitize_headers_for_memory($headers) {
        if ($headers instanceof WP_REST_Request) {
            $headers = $headers->get_headers();
        }

        if (is_string($headers)) {
            $decoded = json_decode($headers, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $headers = $decoded;
            }
        } elseif (is_object($headers)) {
            $headers = json_decode(wp_json_encode($headers), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $headers = (array) $headers;
            }
        }

        if (!is_array($headers)) {
            if (empty($headers)) {
                return [];
            }

            return $this->filter_memory_meta_values($this->remove_bearer_tokens((array) $headers), false);
        }

        $normalized = [];
        foreach ($headers as $key => $value) {
            if (is_array($value)) {
                $string_values = array_map([$this, 'stringify_header_value'], array_values($value));
                if (count($string_values) === 1) {
                    $value = $string_values[0];
                } else {
                    $value = $string_values;
                }
            } elseif (is_scalar($value) || is_null($value)) {
                $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            } else {
                $value = $this->normalize_output_for_memory($value);
            }

            $normalized[$key] = $value;
        }

        $normalized = $this->remove_bearer_tokens($normalized);

        return $this->filter_memory_meta_values($normalized, false);
    }

    private function stringify_header_value($value) {
        if (is_scalar($value) || is_null($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        if (is_object($value) || is_array($value)) {
            $encoded = wp_json_encode($value);
            if ($encoded !== false) {
                return $encoded;
            }
        }

        return (string) $value;
    }

    private function sanitize_definition_for_memory($definition) {
        if (is_string($definition)) {
            $decoded = json_decode($definition, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $definition = $decoded;
            }
        } elseif (is_object($definition)) {
            $definition = json_decode(wp_json_encode($definition), true);
        }

        if (!is_array($definition)) {
            return [];
        }

        if (isset($definition['payload'])) {
            $definition['payload'] = $this->sanitize_payload_for_memory($definition['payload']);
        }

        if (isset($definition['headers'])) {
            $definition['headers'] = $this->sanitize_headers_for_memory($definition['headers']);
        }

        if (isset($definition['schedule'])) {
            $definition['schedule'] = $this->normalize_schedule_for_memory($definition['schedule']);
        }

        return $this->filter_memory_meta_values($this->remove_bearer_tokens($definition), false);
    }

    private function build_request_snapshot_for_memory($endpoint, $parameters, $headers, array $definition_meta) {
        $snapshot = [
            'endpoint'   => $endpoint,
            'method'     => 'POST',
            'parameters' => $parameters,
        ];

        if (!empty($headers)) {
            $snapshot['headers'] = $headers;
        }

        $additional = $definition_meta;
        unset($additional['target_route'], $additional['payload'], $additional['headers']);

        if (!empty($additional)) {
            $snapshot['definition_parameters'] = $additional;
        }

        return $this->filter_memory_meta_values($snapshot, false);
    }

    private function normalize_output_for_memory($output) {
        if ($output instanceof WP_Error) {
            return [
                'error_code'    => $output->get_error_code(),
                'error_message' => $output->get_error_message(),
                'error_data'    => $output->get_error_data(),
            ];
        }

        if ($output instanceof WP_REST_Response) {
            return [
                'data'    => $output->get_data(),
                'status'  => $output->get_status(),
                'headers' => $output->get_headers(),
            ];
        }

        if (is_scalar($output) || is_null($output)) {
            return $output;
        }

        if (is_array($output)) {
            return $output;
        }

        if (is_object($output)) {
            if (method_exists($output, 'to_array')) {
                return $output->to_array();
            }

            $encoded = wp_json_encode($output);
            if (false !== $encoded) {
                $decoded = json_decode($encoded, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }

            return print_r($output, true);
        }

        $encoded = wp_json_encode($output);
        if (false === $encoded) {
            return $output;
        }

        $decoded = json_decode($encoded, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $encoded;
    }

    private function normalize_schedule_for_memory($schedule) {
        if (is_null($schedule) || $schedule === '') {
            return null;
        }

        if (is_string($schedule)) {
            $decoded = json_decode($schedule, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $schedule = $decoded;
            }
        }

        if (!is_array($schedule)) {
            return $schedule;
        }

        $normalized = json_decode(wp_json_encode($schedule), true);
        return json_last_error() === JSON_ERROR_NONE ? $normalized : $schedule;
    }

    private function normalize_datetime_field_for_memory($value) {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_numeric($value)) {
            $int_value = (int) $value;
            if ($int_value <= 0) {
                return null;
            }

            return gmdate('Y-m-d H:i:s', $int_value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || $trimmed === '0000-00-00 00:00:00') {
                return null;
            }

            return $trimmed;
        }

        return null;
    }

    private function build_schedule_snapshot_for_memory($created_at, $updated_at, $scheduled_for, $executed_at, $last_run) {
        $snapshot = [
            'created_at'    => $created_at,
            'updated_at'    => $updated_at,
            'scheduled_for' => $scheduled_for,
            'executed_at'   => $executed_at,
            'last_run'      => $last_run,
        ];

        return $this->filter_memory_meta_values($snapshot);
    }

    private function build_execution_snapshot_for_memory($payload, $response, $headers = []) {
        $snapshot = [
            'payload'  => $payload,
            'response' => $response,
        ];

        if (!is_null($headers)) {
            $snapshot['headers'] = $headers;
        }

        return $this->filter_memory_meta_values($snapshot, false);
    }

    private function filter_memory_meta_values(array $data, $drop_empty_arrays = true) {
        $clean = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $value = $this->filter_memory_meta_values($value, $drop_empty_arrays);

                if ($drop_empty_arrays && $value === []) {
                    continue;
                }
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function is_cron_context() {
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }

        return defined('DOING_CRON') && DOING_CRON;
    }


    /**
     * POLLING: verifica condizione su meta/field/post (esempio base per post meta)
     * Supporta operatori =, !=, >, <, >=, <=, contains
     */
    private function check_condition_polling($schedule) {
        $content_id   = $schedule['content_id']   ?? null;
        $field        = $schedule['field']        ?? null;
        $operator     = $schedule['operator']     ?? '=';
        $target_value = $schedule['value']        ?? null;
        $content_type = $schedule['content_type'] ?? 'post';

        if (!$content_id || !$field) return false;

        // Estendibile per altri content_type!
        if ($content_type === 'post') {
            $current_value = get_post_meta($content_id, $field, true);
        } else {
            // puoi aggiungere term meta, user meta, ecc
            $current_value = null;
        }

        switch ($operator) {
            case '=': $match = ($current_value == $target_value); break;
            case '!=': $match = ($current_value != $target_value); break;
            case '>': $match = ($current_value > $target_value); break;
            case '<': $match = ($current_value < $target_value); break;
            case '>=': $match = ($current_value >= $target_value); break;
            case '<=': $match = ($current_value <= $target_value); break;
            case 'contains': $match = (strpos($current_value, $target_value) !== false); break;
            default: $match = false;
        }
        return $match;
    }

    private function serialize_job_output($output) {
        if ($output instanceof WP_Error) {
            return $output->get_error_message();
        }

        if ($output instanceof WP_REST_Response) {
            $output = $output->get_data();
        }

        if (is_scalar($output) || is_null($output)) {
            return is_bool($output) ? ($output ? 'true' : 'false') : (string) $output;
        }

        $encoded = wp_json_encode($output);

        if (false === $encoded) {
            return print_r($output, true);
        }

        return $encoded;
    }

    private function maybe_trigger_wp_cron() {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            return;
        }

        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }

        if (!function_exists('site_url')) {
            return;
        }

        if (function_exists('spawn_cron')) {
            $spawned = spawn_cron();
            if ($spawned && !is_wp_error($spawned)) {
                return;
            }
        }

        $doing_wp_cron = sprintf('%.22F', microtime(true));
        $cron_url      = site_url('wp-cron.php?doing_wp_cron=' . $doing_wp_cron);

        $cron_request = apply_filters('cron_request', [
            'url'  => $cron_url,
            'key'  => $doing_wp_cron,
            'args' => [
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
                'body'      => [],
            ],
        ]);

        if (is_wp_error($cron_request)) {
            $this->job_log('system', 'Errore nell\'applicare cron_request: ' . $cron_request->get_error_message());
            return;
        }

        if (!is_array($cron_request) || empty($cron_request['url']) || empty($cron_request['args'])) {
            return;
        }

        $response = wp_remote_post($cron_request['url'], $cron_request['args']);

        if (is_wp_error($response)) {
            $this->job_log('system', 'Errore nell\'attivare WP-Cron: ' . $response->get_error_message());
        }
    }

    private function has_active_jobs(&$count = null) {
        global $wpdb;

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status='active'");

        return $count > 0;
    }

    private function ensure_condition_polling_event() {
        $event = function_exists('wp_get_scheduled_event')
            ? wp_get_scheduled_event('wp_gpt_scheduler_condition_polling')
            : null;

        if ($event && is_object($event)) {
            $schedule = isset($event->schedule) ? $event->schedule : '';
            if ($schedule === 'minute') {
                return;
            }

            if (isset($event->timestamp)) {
                wp_unschedule_event($event->timestamp, 'wp_gpt_scheduler_condition_polling');
            }
        } elseif (wp_next_scheduled('wp_gpt_scheduler_condition_polling')) {
            return;
        }

        // Usa l'ora WordPress, non UTC
        wp_schedule_event($this->get_current_wp_timestamp(), 'minute', 'wp_gpt_scheduler_condition_polling');
    }

    private function get_current_gmt_timestamp() {
        return current_time('timestamp', true);
    }
}

// Instanzia la classe e rendila accessibile globalmente.
global $wp_gpt_v1_route_scheduler;
if (!($wp_gpt_v1_route_scheduler instanceof WP_GPT_V1_Route_Scheduler)) {
    $wp_gpt_v1_route_scheduler = new WP_GPT_V1_Route_Scheduler();
}
} // end if class exists
