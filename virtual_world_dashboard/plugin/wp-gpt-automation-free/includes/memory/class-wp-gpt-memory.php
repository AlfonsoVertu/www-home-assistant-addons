<?php
namespace WpGpt\AutomationPro\Memory;

/**
 * Memory_Manager - gestione obiettivi (goals) e step con funzioni CRUD, chunking, transient, pulizia e controllo avanzato
 */
class Memory_Manager {
    protected $wpdb;
    protected $goals_table;
    protected $steps_table;
    protected $chunk_prefix = 'wp_gpt_memory_chunk_';

public function __construct() {
    global $wpdb;
    $this->wpdb = $wpdb;
    $this->goals_table = $wpdb->prefix . 'gpt_memory_goals';
    $this->steps_table = $wpdb->prefix . 'gpt_memory_steps';

    // Controllo tabella ogni 2 ore
    if (false === get_transient('wp_gpt_memory_table_check')) {
        $this->maybe_create_tables();
        set_transient('wp_gpt_memory_table_check', time(), 2 * HOUR_IN_SECONDS);
    }

    // Registra la rotta REST solo una volta
    add_action('rest_api_init', array($this, 'register_memory_route'));
}

    // ----------- SETUP DB -----------

    public function maybe_create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset_collate = $this->wpdb->get_charset_collate();
        $goals_table = $this->goals_table;
        $steps_table = $this->steps_table;

        $sql_goals = "CREATE TABLE $goals_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            goal_title VARCHAR(255) NOT NULL,
            goal_type VARCHAR(60) NOT NULL,
            keyword VARCHAR(100) DEFAULT NULL,
            user_code VARCHAR(120) NOT NULL,
            status VARCHAR(40) NOT NULL,
            meta LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_code (user_code),
            KEY keyword (keyword)
        ) $charset_collate;";

        $sql_steps = "CREATE TABLE $steps_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            goal_id BIGINT(20) UNSIGNED NOT NULL,
            step_type VARCHAR(60) NOT NULL,
            step_label VARCHAR(255) NOT NULL,
            status VARCHAR(40) NOT NULL,
            meta LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY goal_id (goal_id)
        ) $charset_collate;";

        dbDelta($sql_goals);
        dbDelta($sql_steps);

        // Controllo creazione tabella
        $created = true;
        $errors = [];
        if ($this->wpdb->get_var("SHOW TABLES LIKE '$goals_table'") !== $goals_table) {
            $created = false;
            $errors[] = "Tabella goals non creata: $goals_table";
        }
        if ($this->wpdb->get_var("SHOW TABLES LIKE '$steps_table'") !== $steps_table) {
            $created = false;
            $errors[] = "Tabella steps non creata: $steps_table";
        }
        if (!$created) {
            error_log("WP-GPT-MEMORY: Errore creazione tabelle: " . implode(' | ', $errors));
            return array('success' => false, 'error' => implode(' | ', $errors));
        }
        return array('success' => true, 'msg' => 'Tables checked/created');
    }

    // ----------- UTILITY: PERMESSI & LOG -----------

    /** Controllo permessi utente: solo user_code corrispondente può agire */
    protected function check_user_permission($record, $user_code) {
        if (empty($record) || empty($user_code)) return false;
        return isset($record['user_code']) && $record['user_code'] === $user_code;
    }

    /** Log operazione su debug.log */
    protected function log_action($msg) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP-GPT-MEMORY] ' . $msg);
        }
    }

    protected function sanitize_ids($ids) {
        if (empty($ids)) {
            return [];
        }
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $sanitized = array_map('absint', $ids);
        return array_values(array_filter($sanitized));
    }

    protected function rest_error($code, $message, $status = 400) {
        return new \WP_Error($code, $message, ['status' => $status]);
    }

    protected function get_goal_row($goal_id) {
        if ($goal_id <= 0) {
            return null;
        }
        $sql = "SELECT * FROM {$this->goals_table} WHERE id = %d LIMIT 1";
        return $this->wpdb->get_row($this->wpdb->prepare($sql, $goal_id), ARRAY_A);
    }

    protected function get_step_with_goal($step_id) {
        if ($step_id <= 0) {
            return null;
        }
        $sql = "SELECT s.*, g.user_code FROM {$this->steps_table} AS s LEFT JOIN {$this->goals_table} AS g ON g.id = s.goal_id WHERE s.id = %d LIMIT 1";
        return $this->wpdb->get_row($this->wpdb->prepare($sql, $step_id), ARRAY_A);
    }


        public function register_memory_route() {
        register_rest_route(
            'wp-gpt/v1',
            '/memory/goal',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'memory_router'),
                'permission_callback' => array('WP_GPT_Automation_Pro\\WP_GPT_API', 'validate_request_with_user_code'),
            )
        );

        register_rest_route(
            'wp-gpt/v1',
            '/memory/admin',
            array(
                'methods'  => 'POST',
                'callback' => array($this, 'handle_admin_rest_request'),
                'permission_callback' => function () {
                    return \current_user_can('manage_options');
                },
            )
        );
    }
    /**
     * Router unico per tutte le azioni: passa 'action' nel body POST.
     */
    public function memory_router($request) {
        $params = $request->get_params();
        $action = isset($params['action']) ? $params['action'] : '';

        switch ($action) {
            case 'create_goal':
                return $this->create_goal($params);
            case 'get_goal':
                return $this->get_goal($params);
            case 'update_goal':
                return $this->update_goal($params);
            case 'delete_goal':
                return $this->delete_goal($params);
            case 'hard_delete_goal':
                return $this->hard_delete_goal($params);
            case 'list_goals':
                return $this->list_goals($params);
            case 'delete_all_goals':
                return $this->delete_all_goals($params, isset($params['hard']) ? (bool)$params['hard'] : false);
            case 'add_step':
                return $this->add_step($params);
            case 'get_step':
                return $this->get_step($params);
            case 'update_step':
                return $this->update_step($params);
            case 'delete_step':
                return $this->delete_step($params);
            case 'hard_delete_step':
                return $this->hard_delete_step($params);
            case 'list_steps':
                return $this->list_steps($params);
            case 'delete_all_steps':
                return $this->delete_all_steps($params, isset($params['hard']) ? (bool)$params['hard'] : false);
            case 'hard_delete_steps_by_goal':
                return $this->hard_delete_steps_by_goal($params);
            case 'get_status':
                return $this->get_status($params);
            case 'maybe_chunked_response':
                return $this->maybe_chunked_response($params);
            case 'get_chunked_response':
                // Serve chunk_key e chunk_index
                return $this->get_chunked_response(
                    isset($params['chunk_key']) ? $params['chunk_key'] : '',
                    isset($params['chunk_index']) ? intval($params['chunk_index']) : 0
                );
            case 'delete_chunk':
                return $this->delete_chunk(isset($params['chunk_key']) ? $params['chunk_key'] : '');
            case 'maybe_create_tables':
                return $this->maybe_create_tables();
            default:
                return array('success'=>false, 'error'=>'Action non riconosciuta', 'allowed_actions'=>[
                    'create_goal', 'get_goal', 'update_goal', 'delete_goal', 'hard_delete_goal',
                    'list_goals', 'delete_all_goals', 'add_step', 'get_step', 'update_step', 'delete_step', 'hard_delete_step',
                    'list_steps', 'delete_all_steps', 'hard_delete_steps_by_goal', 'get_status',
                    'maybe_chunked_response', 'get_chunked_response', 'delete_chunk', 'maybe_create_tables'
                ]);
        }
    }

    public function handle_admin_rest_request($request) {
        if (!\current_user_can('manage_options')) {
            return $this->rest_error('forbidden', __('Unauthorized request.', 'wp-gpt-automation-pro'), 403);
        }

        $nonce = $request->get_param('wp_gpt_memory_admin_nonce');
        if (empty($nonce)) {
            $nonce = $request->get_param('nonce');
        }
        if (empty($nonce) || !\wp_verify_nonce($nonce, 'wp_gpt_memory_admin')) {
            return $this->rest_error('invalid_nonce', __('Security check failed. Refresh the page and try again.', 'wp-gpt-automation-pro'), 403);
        }

        $action = sanitize_key($request->get_param('action'));
        if (empty($action)) {
            return $this->rest_error('missing_action', __('Missing action parameter.', 'wp-gpt-automation-pro'));
        }

        switch ($action) {
            case 'update_goal':
                $goal_id = absint($request->get_param('goal_id'));
                $goal_title = sanitize_text_field($request->get_param('goal_title'));
                if ($goal_id <= 0 || $goal_title === '') {
                    return $this->rest_error('invalid_goal', __('Missing goal information for update.', 'wp-gpt-automation-pro'));
                }
                $goal_row = $this->get_goal_row($goal_id);
                if (empty($goal_row)) {
                    return $this->rest_error('goal_not_found', __('Goal not found.', 'wp-gpt-automation-pro'), 404);
                }

                $result = $this->update_goal([
                    'goal_id'    => $goal_id,
                    'goal_title' => $goal_title,
                    'user_code'  => isset($goal_row['user_code']) ? $goal_row['user_code'] : '',
                    'bypass_permission' => true,
                ]);
                return \rest_ensure_response($result);

            case 'update_step':
                $step_id = absint($request->get_param('step_id'));
                $step_label = sanitize_text_field($request->get_param('step_label'));
                if ($step_id <= 0 || $step_label === '') {
                    return $this->rest_error('invalid_step', __('Missing step information for update.', 'wp-gpt-automation-pro'));
                }
                $step_row = $this->get_step_with_goal($step_id);
                if (empty($step_row)) {
                    return $this->rest_error('step_not_found', __('Step not found.', 'wp-gpt-automation-pro'), 404);
                }

                $result = $this->update_step([
                    'step_id'    => $step_id,
                    'step_label' => $step_label,
                    'user_code'  => isset($step_row['user_code']) ? $step_row['user_code'] : '',
                    'bypass_permission' => true,
                ]);
                return \rest_ensure_response($result);

            case 'delete':
                $goal_ids = $this->sanitize_ids($request->get_param('goal_ids'));
                $step_ids = $this->sanitize_ids($request->get_param('step_ids'));
                if (empty($goal_ids) && empty($step_ids)) {
                    return $this->rest_error('missing_ids', __('Select at least one goal or step to delete.', 'wp-gpt-automation-pro'));
                }

                $response = [
                    'success' => true,
                    'deleted' => [
                        'goals' => [],
                        'steps' => [],
                    ],
                    'errors' => [],
                ];

                foreach ($goal_ids as $goal_id) {
                    $goal_row = $this->get_goal_row($goal_id);
                    if (empty($goal_row)) {
                        $response['success'] = false;
                        $response['errors'][] = [
                            'type'    => 'goal',
                            'id'      => $goal_id,
                            'message' => __('Goal not found.', 'wp-gpt-automation-pro'),
                        ];
                        continue;
                    }
                    $delete = $this->delete_goal([
                        'goal_id'   => $goal_id,
                        'user_code' => isset($goal_row['user_code']) ? $goal_row['user_code'] : '',
                        'bypass_permission' => true,
                    ]);
                    if (empty($delete['success'])) {
                        $response['success'] = false;
                        $response['errors'][] = [
                            'type'    => 'goal',
                            'id'      => $goal_id,
                            'message' => isset($delete['error']) ? $delete['error'] : __('Unknown error.', 'wp-gpt-automation-pro'),
                        ];
                    } else {
                        $response['deleted']['goals'][] = $goal_id;
                    }
                }

                foreach ($step_ids as $step_id) {
                    $step_row = $this->get_step_with_goal($step_id);
                    if (empty($step_row)) {
                        $response['success'] = false;
                        $response['errors'][] = [
                            'type'    => 'step',
                            'id'      => $step_id,
                            'message' => __('Step not found.', 'wp-gpt-automation-pro'),
                        ];
                        continue;
                    }
                    $delete = $this->delete_step([
                        'step_id'   => $step_id,
                        'user_code' => isset($step_row['user_code']) ? $step_row['user_code'] : '',
                        'bypass_permission' => true,
                    ]);
                    if (empty($delete['success'])) {
                        $response['success'] = false;
                        $response['errors'][] = [
                            'type'    => 'step',
                            'id'      => $step_id,
                            'message' => isset($delete['error']) ? $delete['error'] : __('Unknown error.', 'wp-gpt-automation-pro'),
                        ];
                    } else {
                        $response['deleted']['steps'][] = $step_id;
                    }
                }

                return \rest_ensure_response($response);
        }

        return $this->rest_error('invalid_action', __('Action not supported.', 'wp-gpt-automation-pro'));
    }
    // ----------- GOALS CRUD + LIST + DELETE -----------

    public function create_goal($params) {
        $goal_title = isset($params['goal_title']) ? sanitize_text_field($params['goal_title']) : '';
        $goal_type  = isset($params['goal_type'])  ? sanitize_text_field($params['goal_type'])  : 'default';
        $keyword    = isset($params['keyword'])    ? sanitize_text_field($params['keyword'])    : '';
        $user_code  = isset($params['user_code'])  ? sanitize_text_field($params['user_code'])  : '';
        $meta       = isset($params['meta'])       ? maybe_serialize($params['meta'])           : '';
        $now = current_time('mysql');

        if (empty($goal_title) || empty($user_code)) {
            $this->log_action("Tentata creazione goal senza campi obbligatori.");
            return array('success' => false, 'error' => 'Missing required fields: goal_title or user_code');
        }

        $result = $this->wpdb->insert(
            $this->goals_table,
            array(
                'goal_title' => $goal_title,
                'goal_type'  => $goal_type,
                'keyword'    => $keyword,
                'user_code'  => $user_code,
                'status'     => 'in_progress',
                'meta'       => $meta,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s','%s','%s','%s','%s','%s','%s','%s')
        );
        if (!$result) {
            $this->log_action("Errore inserimento goal: " . $this->wpdb->last_error);
            return array('success' => false, 'error' => 'DB insert error');
        }
        $goal_id = $this->wpdb->insert_id;
        $this->log_action("Creato nuovo goal ID: $goal_id per user_code: $user_code");
        return array('success' => true, 'goal_id' => $goal_id);
    }

    public function get_goal($params) {
        $goal_id = isset($params["goal_id"]) ? intval($params["goal_id"]) : 0;
        $user_code = isset($params["user_code"]) ? sanitize_text_field($params["user_code"]) : '';
        $bypass_permission = !empty($params['bypass_permission']);
        if ($goal_id <= 0) {
            return array("success" => false, "error" => "Missing or invalid goal_id");
        }
        $sql = "SELECT * FROM " . $this->goals_table . " WHERE id = %d LIMIT 1";
        $row = $this->wpdb->get_row($this->wpdb->prepare($sql, $goal_id), ARRAY_A);
        if (!$row) return array("success" => false, "error" => "Goal not found");
        if (!$bypass_permission && !$this->check_user_permission($row, $user_code)) {
            $this->log_action("Tentativo accesso non autorizzato al goal $goal_id (user_code: $user_code)");
            return array("success" => false, "error" => "Permission denied");
        }
        $row["meta"] = maybe_unserialize($row["meta"]);
        return array("success" => true, "goal" => $row);
    }

    /**
     * Update step - ora include controllo permesso utente
     */
    public function update_step($params) {
        $step_id = isset($params['step_id']) ? intval($params['step_id']) : 0;
        $user_code = isset($params['user_code']) ? sanitize_text_field($params['user_code']) : '';
        $bypass_permission = !empty($params['bypass_permission']);
        if ($step_id <= 0) return array('success'=>false, 'error'=>'Missing step_id');
        // Recupera step e goal relativo per controllo permesso
        $sql = "SELECT * FROM {$this->steps_table} WHERE id=%d LIMIT 1";
        $step = $this->wpdb->get_row($this->wpdb->prepare($sql, $step_id), ARRAY_A);
        if (!$step) return array('success'=>false, 'error'=>'Step not found');
        $goal = $this->get_goal([
            'goal_id' => $step['goal_id'],
            'user_code' => $user_code,
            'bypass_permission' => $bypass_permission,
        ]);
        if (!$goal['success']) {
            return array('success'=>false, 'error'=> isset($goal['error']) ? $goal['error'] : 'Permission denied');
        }
        if ($bypass_permission && empty($user_code)) {
            $user_code = isset($goal['goal']['user_code']) ? $goal['goal']['user_code'] : '';
        }

        $fields = array(); $formats = array();
        if (isset($params['step_type']))  { $fields['step_type'] = sanitize_text_field($params['step_type']); $formats[] = '%s'; }
        if (isset($params['step_label'])) { $fields['step_label'] = sanitize_text_field($params['step_label']); $formats[] = '%s'; }
        if (isset($params['status']))     { $fields['status'] = sanitize_text_field($params['status']); $formats[] = '%s'; }
        if (isset($params['meta']))       { $fields['meta'] = maybe_serialize($params['meta']); $formats[] = '%s'; }
        if (empty($fields)) return array('success'=>false, 'error'=>'No fields to update');
        $fields['updated_at'] = current_time('mysql'); $formats[] = '%s';
        $result = $this->wpdb->update(
            $this->steps_table, $fields, array('id'=>$step_id), $formats, array('%d')
        );
        return ($result === false)
            ? array('success'=>false, 'error'=>'DB update error')
            : array('success'=>true, 'step_id'=>$step_id);
    }

    public function update_goal($params) {
        $goal_id = isset($params['goal_id']) ? intval($params['goal_id']) : 0;
        $user_code = isset($params["user_code"]) ? sanitize_text_field($params["user_code"]) : '';
        $bypass_permission = !empty($params['bypass_permission']);
        if ($goal_id <= 0) return array('success' => false, 'error' => 'Invalid goal_id');
        $row = $this->get_goal([
            'goal_id' => $goal_id,
            'user_code' => $user_code,
            'bypass_permission' => $bypass_permission,
        ]);
        if (!$row['success']) return $row;
        if ($bypass_permission && empty($user_code)) {
            $user_code = isset($row['goal']['user_code']) ? $row['goal']['user_code'] : '';
        }
        $fields = array(); $formats = array();
        if (isset($params['goal_title'])) { $fields['goal_title'] = sanitize_text_field($params['goal_title']); $formats[] = '%s'; }
        if (isset($params['goal_type']))  { $fields['goal_type']  = sanitize_text_field($params['goal_type']);  $formats[] = '%s'; }
        if (isset($params['keyword']))    { $fields['keyword']    = sanitize_text_field($params['keyword']);    $formats[] = '%s'; }
        if (isset($params['status']))     { $fields['status']     = sanitize_text_field($params['status']);     $formats[] = '%s'; }
        if (isset($params['meta']))       { $fields['meta']       = maybe_serialize($params['meta']);           $formats[] = '%s'; }
        if (empty($fields)) return array('success' => false, 'error' => 'No fields to update');
        $fields['updated_at'] = current_time('mysql'); $formats[] = '%s';

        $result = $this->wpdb->update(
            $this->goals_table, $fields, array('id' => $goal_id), $formats, array('%d')
        );
        if ($result === false) {
            $this->log_action("Errore update goal $goal_id: " . $this->wpdb->last_error);
            return array('success' => false, 'error' => 'DB update error');
        }
        $this->log_action("Goal $goal_id aggiornato (user_code: " . ($user_code !== '' ? $user_code : 'admin') . ")");
        return array('success' => true, 'goal_id' => $goal_id);
    }

    /** Soft delete (status=deleted) */
    public function delete_goal($params) {
        $goal_id = isset($params['goal_id']) ? intval($params['goal_id']) : 0;
        $user_code = isset($params["user_code"]) ? sanitize_text_field($params["user_code"]) : '';
        $bypass_permission = !empty($params['bypass_permission']);
        if ($goal_id <= 0) return array('success' => false, 'error' => 'Invalid goal_id');
        $row = $this->get_goal([
            'goal_id' => $goal_id,
            'user_code' => $user_code,
            'bypass_permission' => $bypass_permission,
        ]);
        if (!$row['success']) return $row;
        if ($bypass_permission && empty($user_code)) {
            $user_code = isset($row['goal']['user_code']) ? $row['goal']['user_code'] : '';
        }
        $result = $this->wpdb->update(
            $this->goals_table,
            array('status' => 'deleted', 'updated_at' => current_time('mysql')),
            array('id' => $goal_id),
            array('%s', '%s'),
            array('%d')
        );
        if ($result === false) {
            $this->log_action("Errore delete goal $goal_id: ".$this->wpdb->last_error);
            return array('success' => false, 'error' => 'DB delete error');
        }
        $this->log_action("Goal $goal_id soft-deleted (user_code: " . ($user_code !== '' ? $user_code : 'admin') . ")");
        return array('success' => true, 'goal_id' => $goal_id);
    }

    /** Hard delete goal + relativi step */
    public function hard_delete_goal($params) {
        $goal_id = isset($params['goal_id']) ? intval($params['goal_id']) : 0;
        $user_code = isset($params["user_code"]) ? sanitize_text_field($params["user_code"]) : '';
        if ($goal_id <= 0) return array('success' => false, 'error' => 'Invalid goal_id');
        $row = $this->get_goal(['goal_id' => $goal_id, 'user_code' => $user_code]);
        if (!$row['success']) return $row;
        $this->wpdb->delete($this->goals_table, array('id' => $goal_id), array('%d'));
        $this->wpdb->delete($this->steps_table, array('goal_id' => $goal_id), array('%d'));
        $this->log_action("Goal $goal_id e relativi step hard-deleted (user_code: $user_code)");
        return array('success' => true, 'goal_id' => $goal_id);
    }

    /** Lista goals, filtrabile per user_code o status, ora con limit/offset */
    public function list_goals($params = array()) {
        $where = 'WHERE 1=1';
        $args = array();
        if (!empty($params['user_code'])) {
            $where .= ' AND user_code = %s';
            $args[] = sanitize_text_field($params['user_code']);
        }

        $status = isset($params['status']) ? sanitize_text_field($params['status']) : '';
        $include_deleted = !empty($params['include_deleted']);
        if ($status !== '') {
            $where .= ' AND status = %s';
            $args[] = $status;
        } elseif (!$include_deleted) {
            $where .= ' AND status <> %s';
            $args[] = 'deleted';
        }

        $limit = !empty($params['limit']) ? intval($params['limit']) : 1000;
        $offset = !empty($params['offset']) ? intval($params['offset']) : 0;
        $sql = "SELECT * FROM " . $this->goals_table . " $where ORDER BY id DESC LIMIT %d OFFSET %d";
        $args[] = $limit;
        $args[] = $offset;
        $results = $this->wpdb->get_results($this->wpdb->prepare($sql, $args), ARRAY_A);
        foreach ($results as &$row) { $row['meta'] = maybe_unserialize($row['meta']); }
        return array('success' => true, 'goals' => $results);
    }

    /** Conta i goals per eventuale paginazione */
    public function count_goals($params = array()) {
        $where = 'WHERE 1=1';
        $args  = array();
        if (!empty($params['user_code'])) {
            $where .= ' AND user_code = %s';
            $args[] = sanitize_text_field($params['user_code']);
        }

        $status = isset($params['status']) ? sanitize_text_field($params['status']) : '';
        $include_deleted = !empty($params['include_deleted']);
        if ($status !== '') {
            $where .= ' AND status = %s';
            $args[] = $status;
        } elseif (!$include_deleted) {
            $where .= ' AND status <> %s';
            $args[] = 'deleted';
        }

        $sql = "SELECT COUNT(*) FROM " . $this->goals_table . " $where";
        return intval($this->wpdb->get_var($this->wpdb->prepare($sql, $args)));
    }

    /** Elimina tutti i goals (hard o soft) per user_code opzionale. */
    public function delete_all_goals($params = array(), $hard = false) {
        $where = '1=1';
        $args = array();
        if (!empty($params['user_code'])) {
            $where .= ' AND user_code = %s';
            $args[] = sanitize_text_field($params['user_code']);
        }
        $goals = $this->wpdb->get_results($this->wpdb->prepare("SELECT id FROM ".$this->goals_table." WHERE $where", $args), ARRAY_A);
        $goal_ids = array_column($goals, 'id');
        if ($hard) {
            foreach ($goal_ids as $gid) {
                $this->wpdb->delete($this->goals_table, array('id'=>$gid), array('%d'));
                $this->wpdb->delete($this->steps_table, array('goal_id'=>$gid), array('%d'));
            }
            $this->log_action("Hard delete massivo goals: ".implode(',', $goal_ids));
        } else {
            foreach ($goal_ids as $gid) {
                $this->wpdb->update($this->goals_table, array('status'=>'deleted','updated_at'=>current_time('mysql')), array('id'=>$gid), array('%s','%s'), array('%d'));
            }
            $this->log_action("Soft delete massivo goals: ".implode(',', $goal_ids));
        }
        return array('success'=>true, 'deleted_goal_ids'=>$goal_ids, 'hard'=>$hard);
    }

    // ----------- STEPS CRUD + LIST + DELETE -----------

    public function add_step($params) {
        $goal_id    = isset($params['goal_id'])    ? intval($params['goal_id'])    : 0;
        $step_type  = isset($params['step_type'])  ? sanitize_text_field($params['step_type']) : 'action';
        $step_label = isset($params['step_label']) ? sanitize_text_field($params['step_label']) : '';
        $status     = isset($params['status'])     ? sanitize_text_field($params['status'])     : 'pending';
        $meta       = isset($params['meta'])       ? maybe_serialize($params['meta'])           : '';
        $user_code  = isset($params['user_code'])  ? sanitize_text_field($params['user_code'])  : '';
        $now = current_time('mysql');
        if ($goal_id <= 0 || empty($step_label)) {
            $this->log_action("Tentativo aggiunta step senza goal_id o step_label.");
            return array('success' => false, 'error' => 'Missing required fields: goal_id or step_label');
        }
        // Permesso user_code per step: match user_code del goal!
        $goal = $this->get_goal(['goal_id'=>$goal_id, 'user_code'=>$user_code]);
        if (!$goal['success']) return $goal;

        $result = $this->wpdb->insert(
            $this->steps_table,
            array(
                'goal_id'    => $goal_id,
                'step_type'  => $step_type,
                'step_label' => $step_label,
                'status'     => $status,
                'meta'       => $meta,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d','%s','%s','%s','%s','%s','%s')
        );
        if (!$result) {
            $this->log_action("Errore inserimento step: ".$this->wpdb->last_error);
            return array('success' => false, 'error' => 'DB insert error');
        }
        $step_id = $this->wpdb->insert_id;
        $this->log_action("Aggiunto step $step_id a goal $goal_id");
        return array('success' => true, 'step_id' => $step_id);
    }

    public function get_step($params) {
        $step_id = isset($params['step_id']) ? intval($params['step_id']) : 0;
        if ($step_id <= 0) return array('success' => false, 'error' => 'Missing or invalid step_id');
        $sql = "SELECT * FROM " . $this->steps_table . " WHERE id = %d LIMIT 1";
        $row = $this->wpdb->get_row($this->wpdb->prepare($sql, $step_id), ARRAY_A);
        if (!$row) return array('success' => false, 'error' => 'Step not found');
        $row['meta'] = maybe_unserialize($row['meta']);
        return array('success' => true, 'step' => $row);
    }

    /** Lista tutti gli step di un goal, oppure tutti se goal_id = 0, ora con limit/offset */
    public function list_steps($params = array()) {
        $where = 'WHERE 1=1';
        $args = array();
        if (!empty($params['goal_id'])) {
            $where .= ' AND goal_id = %d';
            $args[] = intval($params['goal_id']);
        }

        $status = isset($params['status']) ? sanitize_text_field($params['status']) : '';
        $include_deleted = !empty($params['include_deleted']);
        if ($status !== '') {
            $where .= ' AND status = %s';
            $args[] = $status;
        } elseif (!$include_deleted) {
            $where .= ' AND status <> %s';
            $args[] = 'deleted';
        }

        $limit = !empty($params['limit']) ? intval($params['limit']) : 1000;
        $offset = !empty($params['offset']) ? intval($params['offset']) : 0;
        $sql = "SELECT * FROM " . $this->steps_table . " $where ORDER BY id ASC LIMIT %d OFFSET %d";
        $args[] = $limit;
        $args[] = $offset;
        $results = $this->wpdb->get_results($this->wpdb->prepare($sql, $args), ARRAY_A);
        foreach ($results as &$row) { $row['meta'] = maybe_unserialize($row['meta']); }
        return array('success' => true, 'steps' => $results);
    }

    /** Conta gli step (totali o per goal) per la paginazione */
    public function count_steps($params = array()) {
        $where = 'WHERE 1=1';
        $args  = array();
        if (!empty($params['goal_id'])) {
            $where .= ' AND goal_id = %d';
            $args[] = intval($params['goal_id']);
        }

        $status = isset($params['status']) ? sanitize_text_field($params['status']) : '';
        $include_deleted = !empty($params['include_deleted']);
        if ($status !== '') {
            $where .= ' AND status = %s';
            $args[] = $status;
        } elseif (!$include_deleted) {
            $where .= ' AND status <> %s';
            $args[] = 'deleted';
        }

        $sql = "SELECT COUNT(*) FROM " . $this->steps_table . " $where";
        return intval($this->wpdb->get_var($this->wpdb->prepare($sql, $args)));
    }

    /** Elimina tutti gli step di tutti i goals (per user_code opzionale, hard o soft) */
    public function delete_all_steps($params = array(), $hard = false) {
        $where = '1=1';
        $args = array();
        if (!empty($params['goal_id'])) {
            $where .= ' AND goal_id = %d';
            $args[] = intval($params['goal_id']);
        }
        // Volendo user_code: prendi i goals con quel user_code, poi tutti gli step dei goal_ids
        if (!empty($params['user_code'])) {
            $goal_results = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id FROM " . $this->goals_table . " WHERE user_code = %s", sanitize_text_field($params['user_code'])
            ), ARRAY_A);
            $goal_ids = array_column($goal_results, 'id');
            if (!empty($goal_ids)) {
                $where .= ' AND goal_id IN (' . implode(',', array_map('intval', $goal_ids)) . ')';
            }
        }
        $steps = $this->wpdb->get_results($this->wpdb->prepare("SELECT id FROM ".$this->steps_table." WHERE $where", $args), ARRAY_A);
        $step_ids = array_column($steps, 'id');
        if ($hard) {
            foreach ($step_ids as $sid) {
                $this->wpdb->delete($this->steps_table, array('id'=>$sid), array('%d'));
            }
            $this->log_action("Hard delete massivo steps: ".implode(',', $step_ids));
        } else {
            foreach ($step_ids as $sid) {
                $this->wpdb->update($this->steps_table, array('status'=>'deleted','updated_at'=>current_time('mysql')), array('id'=>$sid), array('%s','%s'), array('%d'));
            }
            $this->log_action("Soft delete massivo steps: ".implode(',', $step_ids));
        }
        return array('success'=>true, 'deleted_step_ids'=>$step_ids, 'hard'=>$hard);
    }

    /** Soft delete */
    public function delete_step($params) {
        $step_id = isset($params['step_id']) ? intval($params['step_id']) : 0;
        if ($step_id <= 0) return array('success' => false, 'error' => 'Invalid step_id');
        $result = $this->wpdb->update(
            $this->steps_table,
            array('status' => 'deleted', 'updated_at' => current_time('mysql')),
            array('id' => $step_id),
            array('%s', '%s'),
            array('%d')
        );
        if ($result === false) {
            $this->log_action("Errore delete step $step_id: ".$this->wpdb->last_error);
            return array('success' => false, 'error' => 'DB delete error');
        }
        $this->log_action("Step $step_id soft-deleted.");
        return array('success' => true, 'step_id' => $step_id);
    }

    /** Hard delete singolo step */
    public function hard_delete_step($params) {
        $step_id = isset($params['step_id']) ? intval($params['step_id']) : 0;
        if ($step_id <= 0) return array('success' => false, 'error' => 'Invalid step_id');
        $this->wpdb->delete($this->steps_table, array('id' => $step_id), array('%d'));
        $this->log_action("Step $step_id hard-deleted.");
        return array('success' => true, 'step_id' => $step_id);
    }

    /** Hard delete di tutti gli step di un goal */
    public function hard_delete_steps_by_goal($params) {
        $goal_id = isset($params['goal_id']) ? intval($params['goal_id']) : 0;
        if ($goal_id <= 0) return array('success' => false, 'error' => 'Invalid goal_id');
        $this->wpdb->delete($this->steps_table, array('goal_id' => $goal_id), array('%d'));
        $this->log_action("Tutti gli step hard-deleted per goal $goal_id.");
        return array('success' => true, 'goal_id' => $goal_id);
    }

    // ----------- STATUS/PROGRESS -----------

    /** Restituisce percentuale completamento e conteggi step per goal */
    public function get_status($params) {
        $goal_id = isset($params['goal_id']) ? intval($params['goal_id']) : 0;
        $user_code = isset($params['user_code']) ? sanitize_text_field($params['user_code']) : '';
        if ($goal_id <= 0) return array('success'=>false, 'error'=>'Missing or invalid goal_id');
        $goal = $this->get_goal(['goal_id'=>$goal_id, 'user_code'=>$user_code]);
        if (!$goal['success']) return $goal;

        $sql = "SELECT status, COUNT(*) as n FROM ".$this->steps_table." WHERE goal_id=%d GROUP BY status";
        $counts = $this->wpdb->get_results($this->wpdb->prepare($sql, $goal_id), ARRAY_A);

        $total = 0; $complete = 0; $pending = 0; $deleted = 0; $fail = 0;
        foreach ($counts as $row) {
            $total += intval($row['n']);
            switch ($row['status']) {
                case 'complete': $complete += intval($row['n']); break;
                case 'pending':  $pending  += intval($row['n']); break;
                case 'deleted':  $deleted  += intval($row['n']); break;
                case 'fail':     $fail     += intval($row['n']); break;
            }
        }
        $perc = $total > 0 ? round(100 * $complete / $total, 2) : 0;
        return array(
            'success'=>true,
            'goal_id'=>$goal_id,
            'total_steps'=>$total,
            'completed_steps'=>$complete,
            'pending_steps'=>$pending,
            'failed_steps'=>$fail,
            'deleted_steps'=>$deleted,
            'percent_completed'=>$perc
        );
    }

    // ----------- CHUNKING/TRANSIENT PER RISPOSTE LUNGHE -----------

    public function maybe_chunked_response($data) {
        $serialized = maybe_serialize($data);
        $max_len = 10000;
        $total_len = strlen($serialized);

        if ($total_len <= $max_len) {
            return array('success' => true, 'chunked' => false, 'data' => $data);
        }

        $chunks = str_split($serialized, $max_len);
        $chunk_key = $this->chunk_prefix . md5(uniqid(rand(), true));
        $num_chunks = count($chunks);

        set_transient($chunk_key, array(
            'chunks' => $chunks,
            'total' => $num_chunks,
            'created' => time()
        ), 24 * HOUR_IN_SECONDS);

        return array(
            'success' => true,
            'chunked' => true,
            'chunk_key' => $chunk_key,
            'total_chunks' => $num_chunks
        );
    }

    public function get_chunked_response($chunk_key, $chunk_index = 0) {
        $data = get_transient($chunk_key);
        if (!$data || !isset($data['chunks'][$chunk_index])) {
            return array('success' => false, 'error' => 'Chunk not found or expired');
        }
        return array(
            'success' => true,
            'chunk_index' => $chunk_index,
            'chunk' => $data['chunks'][$chunk_index],
            'total_chunks' => $data['total']
        );
    }

    public function delete_chunk($chunk_key) {
        delete_transient($chunk_key);
        return array('success' => true, 'deleted' => $chunk_key);
    }
}
