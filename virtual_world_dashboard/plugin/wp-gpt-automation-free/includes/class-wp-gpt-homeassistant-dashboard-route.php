<?php
namespace WP_GPT_Automation_Pro;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller per la gestione delle entità e dashboard di Home Assistant via WP-GPT.
 * Esclusivo per la versione Home Assistant del plugin.
 */
class WP_GPT_HomeAssistant_Dashboard_Route {

    // QUESTA CLASSE NON REGISTRA PIU' NIENTE, ED E' VOLUTO.
    //
    // La rotta e' una sola: manage-casahomeassistant-domoticandashboard-videocam,
    // dichiarata da WP_GPT_HomeAssistant, che poi smista qui tutto cio' che non
    // e' discover/reload/execute/send-message. Tenendo anche l'init() di prima si
    // registrava una SECONDA rotta con il nome vecchio: viva, funzionante e senza
    // scheda - tools/rotte-senza-dichiarazione.php la segnalava come tale. Due
    // porte per la stessa stanza, e una delle due non sa spiegarsi.
    //
    // Qui resta solo il gestore.
    /**
     * Gestisce le richieste in arrivo.
     */
    public static function handle_request(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!$params) {
            $params = $request->get_body_params();
        }

        $action = isset($params['action']) ? sanitize_text_field($params['action']) : '';
        $page   = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $limit  = isset($params['limit']) ? max(1, intval($params['limit'])) : 10;

        if (empty($action)) {
            return self::error('missing_action', __('Parametro action obbligatorio.', 'wp-gpt-automation-pro'));
        }

        switch ($action) {
            case 'get-entities':
                return self::get_entities($page, $limit);
            case 'control-entity':
                return self::control_entity($params);
            case 'get-shortcode':
                return self::get_shortcode($params);
            
            // Nuove Action Richieste
            case 'get-assistant-list':
                return self::get_assistant_list();
            case 'send-command-to-assistant':
                return self::send_command_to_assistant($params);
            case 'get-script':
                return self::get_filtered_states('script.', $page, $limit);
            case 'get-automation':
                return self::get_filtered_states('automation.', $page, $limit);
            case 'get-scene':
                return self::get_filtered_states('scene.', $page, $limit);
            case 'get-camera-list':
                return self::get_filtered_states('camera.', $page, $limit);
            case 'get-camera-screen':
                return self::get_camera_screen($params);
            case 'get-history':
                return self::get_history($params);
            case 'get-logbook':
                return self::get_logbook($params);
            case 'get-calendars':
                return self::get_calendars();
            case 'get-calendar-events':
                return self::get_calendar_events($params);
            case 'search':
                return self::search_entities($params);
                
            default:
                return self::error('invalid_action', __('Azione richiesta non supportata.', 'wp-gpt-automation-pro'));
        }
    }

    /**
     * Recupera l'elenco delle entità da Home Assistant con paginazione.
     */
    private static function get_entities($page, $limit) {
        return self::get_filtered_states('', $page, $limit);
    }

    /**
     * Recupera stati filtrati per dominio con paginazione.
     */
    private static function get_filtered_states($prefix, $page, $limit) {
        if (!class_exists('\\VWGK_HA_API')) {
            return self::error('bridge_missing', __('Bridge Virtual World Gate Key non trovato.', 'wp-gpt-automation-pro'));
        }

        $allowed_prefixes = !empty($prefix) ? [$prefix] : [];
        $entities = \VWGK_HA_API::get_states($allowed_prefixes);

        // Se l'API restituisce un errore mascherato da entità
        if (isset($entities[0]['entity_id']) && $entities[0]['entity_id'] === 'error.api') {
            return self::error('ha_api_error', $entities[0]['attributes']['friendly_name'] ?? __('Errore API HA.', 'wp-gpt-automation-pro'));
        }

        $entities = array_values($entities);
        $total_entities = count($entities);
        $total_pages    = ceil($total_entities / $limit);
        $offset         = ($page - 1) * $limit;
        $paged_entities = array_slice($entities, $offset, $limit);

        return \rest_ensure_response([
            'success'     => true,
            'entities'    => $paged_entities,
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total_entities,
            'total_pages' => $total_pages,
            'message'     => sprintf(__('Recuperate %d entità.', 'wp-gpt-automation-pro'), count($paged_entities))
        ]);
    }

    /**
     * Recupera la lista degli assistenti conversazionali.
     */
    private static function get_assistant_list() {
        return self::proxy_ha_request('GET', '/conversation/agent/list');
    }

    /**
     * Invia un comando testuale a un assistente HA.
     */
    private static function send_command_to_assistant($params) {
        $text     = isset($params['text']) ? sanitize_text_field($params['text']) : '';
        $agent_id = isset($params['agent_id']) ? sanitize_text_field($params['agent_id']) : 'homeassistant';
        
        if (empty($text)) {
            return self::error('missing_text', __('Il testo del comando è obbligatorio.', 'wp-gpt-automation-pro'));
        }

        if (!class_exists('\\VWGK_HA_API')) {
            return self::error('bridge_missing', __('Bridge HA non trovato.', 'wp-gpt-automation-pro'));
        }

        $result = \VWGK_HA_API::send_conversation_message($text, $agent_id);
        return \rest_ensure_response($result);
    }

    /**
     * Cattura uno screenshot di una telecamera e lo salva su WordPress.
     */
    private static function get_camera_screen($params) {
        $entity_id = isset($params['entity_id']) ? sanitize_text_field($params['entity_id']) : '';
        if (empty($entity_id)) {
            return self::error('missing_id', __('ID telecamera obbligatorio.', 'wp-gpt-automation-pro'));
        }

        $response = \VWGK_HA_Client::request('GET', "/camera_proxy/$entity_id");
        if (is_wp_error($response)) {
            return $response;
        }

        if ($response['status_code'] !== 200 || empty($response['raw_body'])) {
            return self::error('ha_error', __('Impossibile recuperare l\'immagine dalla telecamera.', 'wp-gpt-automation-pro'));
        }

        // Determina l'estensione (default jpg)
        $ext = 'jpg';
        $content_type = isset($response['headers']['content-type']) ? $response['headers']['content-type'] : '';
        if (strpos($content_type, 'png') !== false) $ext = 'png';

        $filename = 'ha_camera_' . $entity_id . '_' . time() . '.' . $ext;
        $upload = wp_upload_bits($filename, null, $response['raw_body']);

        if ($upload['error']) {
            return self::error('upload_error', $upload['error']);
        }

        return \rest_ensure_response([
            'success'   => true,
            'url'       => $upload['url'],
            'file'      => $upload['file'],
            'entity_id' => $entity_id,
            'message'   => __('Screenshot salvato su WordPress.', 'wp-gpt-automation-pro')
        ]);
    }

    /**
     * Recupera lo storico degli stati.
     */
    private static function get_history($params) {
        $timestamp = isset($params['timestamp']) ? sanitize_text_field($params['timestamp']) : date('c', strtotime('-1 hour'));
        $entity_id = isset($params['entity_id']) ? sanitize_text_field($params['entity_id']) : '';
        
        if (!class_exists('\\VWGK_HA_API')) return self::error('bridge_missing', 'Bridge HA non trovato');
        
        return \rest_ensure_response([
            'success' => true,
            'data'    => \VWGK_HA_API::get_history($timestamp, $entity_id)
        ]);
    }

    private static function get_logbook($params) {
        $timestamp = isset($params['timestamp']) ? sanitize_text_field($params['timestamp']) : date('c', strtotime('-1 hour'));
        if (!class_exists('\\VWGK_HA_API')) return self::error('bridge_missing', 'Bridge HA non trovato');
        
        return \rest_ensure_response([
            'success' => true,
            'data'    => \VWGK_HA_API::get_logbook($timestamp)
        ]);
    }

    private static function get_calendars() {
        if (!class_exists('\\VWGK_HA_API')) return self::error('bridge_missing', 'Bridge HA non trovato');
        return \rest_ensure_response([
            'success' => true,
            'data'    => \VWGK_HA_API::get_calendars()
        ]);
    }

    private static function get_calendar_events($params) {
        $entity_id = isset($params['entity_id']) ? sanitize_text_field($params['entity_id']) : '';
        $start = isset($params['start']) ? sanitize_text_field($params['start']) : date('c');
        $end   = isset($params['end']) ? sanitize_text_field($params['end']) : date('c', strtotime('+1 week'));

        if (empty($entity_id)) {
            return self::error('missing_id', __('ID calendario obbligatorio.', 'wp-gpt-automation-pro'));
        }

        if (!class_exists('\\VWGK_HA_API')) return self::error('bridge_missing', 'Bridge HA non trovato');

        return \rest_ensure_response([
            'success' => true,
            'data'    => \VWGK_HA_API::get_calendar_events($entity_id, $start, $end)
        ]);
    }

    /**
     * Cerca entità specifiche in Home Assistant filtrando per query (ID o nome descrittivo).
     */
    private static function search_entities($params) {
        $query = isset($params['query']) ? sanitize_text_field($params['query']) : '';
        $limit = isset($params['limit']) ? max(1, intval($params['limit'])) : 20;

        if (empty($query)) {
            return self::error('missing_query', __('Parametro query obbligatorio per la ricerca.', 'wp-gpt-automation-pro'), 400);
        }

        if (!class_exists('\\VWGK_HA_API')) {
            return self::error('bridge_missing', __('Bridge HA non trovato.', 'wp-gpt-automation-pro'));
        }

        // Recupera tutti gli stati per la ricerca cross-dominio
        $entities = \VWGK_HA_API::get_states([]); 
        
        if (empty($entities) || (isset($entities[0]['entity_id']) && $entities[0]['entity_id'] === 'error.api')) {
            return self::error('ha_api_error', $entities[0]['attributes']['friendly_name'] ?? __('Errore API HA o nessuna entità trovata.', 'wp-gpt-automation-pro'));
        }

        $filtered = [];
        $query_lower = strtolower($query);

        foreach ($entities as $entity) {
            if (!is_array($entity) || !isset($entity['entity_id'])) continue;

            $friendly_name = isset($entity['attributes']['friendly_name']) ? strtolower($entity['attributes']['friendly_name']) : '';
            $id_lower = strtolower($entity['entity_id']);

            if (strpos($id_lower, $query_lower) !== false || strpos($friendly_name, $query_lower) !== false) {
                $filtered[] = $entity;
            }
        }

        $total_found = count($filtered);
        $results = array_slice($filtered, 0, $limit);

        return \rest_ensure_response([
            'success' => true,
            'entities' => $results,
            'total'    => $total_found,
            'limit'    => $limit,
            'query'    => $query,
            'message'  => sprintf(__('Trovate %d entità per "%s".', 'wp-gpt-automation-pro'), $total_found, $query)
        ]);
    }

    /**
     * Invia un comando di controllo a Home Assistant.
     */
    private static function control_entity($params) {
        if (!class_exists('VWGK_HA_Client')) {
            return self::error('bridge_missing', __('Bridge Virtual World Gate Key non trovato.', 'wp-gpt-automation-pro'));
        }

        $domain  = isset($params['domain']) ? sanitize_text_field($params['domain']) : '';
        $service = isset($params['service']) ? sanitize_text_field($params['service']) : '';
        
        $system_keys = ['action', 'user_code', 'page', 'limit', 'domain', 'service', 'entities', 'columns', 'text', 'agent_id', 'timestamp', 'entity_id', 'start', 'end', 'payload'];
        // (21/10/2026) 'payload' fra le chiavi di sistema: prima finiva anche lui dentro il payload, e Home Assistant
        // rispondeva 400 a ogni servizio con dati (set_hvac_mode, set_temperature) chiamato con {"payload":{...}}
        $payload = [];
        
        if (isset($params['payload']) && is_array($params['payload'])) {
            $payload = $params['payload'];
        }

        foreach ($params as $key => $value) {
            if (!in_array($key, $system_keys, true)) {
                $payload[$key] = $value;
            }
        }
        
        // Passa l'entity_id se presente come parametro root
        if (isset($params['entity_id'])) {
            $payload['entity_id'] = $params['entity_id'];
        }

        if (empty($domain) || empty($service)) {
            return self::error('missing_params', __('Dominio e servizio sono obbligatori.', 'wp-gpt-automation-pro'));
        }

        // L'ENTITA' DEVE ESISTERE, E VA VERIFICATO PRIMA. Home Assistant
        // accetta la chiamata a un servizio anche se il bersaglio non esiste:
        // risponde 200 con la lista vuota delle entita' cambiate. Guardando
        // solo il codice di stato si annunciava un successo mai avvenuto, ed e'
        // il motivo per cui l'assistente diceva "fatto" e la luce restava
        // spenta. Misurato il 2026-09-08 su light.questa_non_esiste_proprio:
        // {"success":true,"status":200,"data":[]}.
        if (!empty($payload['entity_id'])) {
            $ids = is_array($payload['entity_id']) ? $payload['entity_id'] : [$payload['entity_id']];
            foreach ($ids as $id) {
                $stato = \VWGK_HA_Client::request('GET', '/states/' . rawurlencode((string) $id));
                if (is_wp_error($stato)) {
                    return self::error('ha_unreachable', __('Home Assistant non risponde: impossibile verificare l\'entita\'.', 'wp-gpt-automation-pro'));
                }
                if ((int) $stato['status_code'] === 404) {
                    return self::error('entity_not_found', sprintf(
                        /* translators: %s: entity_id richiesto */
                        __('L\'entita\' "%s" non esiste in Home Assistant. Cerca il nome esatto con action=search prima di comandarla.', 'wp-gpt-automation-pro'),
                        (string) $id
                    ));
                }
            }
        }

        $risposta = self::proxy_ha_request('POST', "/services/$domain/$service", $payload);

        // 200 con lista vuota significa "nessuna entita' ha cambiato stato".
        // Non e' un errore - poteva essere gia' nello stato richiesto - ma
        // nemmeno il cambiamento che chi chiama credeva di aver ordinato, e
        // tacerlo e' esattamente il difetto che stiamo togliendo.
        if ($risposta instanceof \WP_REST_Response) {
            $d = $risposta->get_data();
            if (!empty($d['success']) && empty($d['data']) && !empty($payload['entity_id'])) {
                $d['nessun_cambiamento'] = true;
                $d['message'] = __('Home Assistant ha accettato il comando, ma nessuna entita\' ha cambiato stato: probabilmente era gia\' cosi\'.', 'wp-gpt-automation-pro');
                $risposta->set_data($d);
            }
        }

        return $risposta;
    }

    /**
     * Helper per proxy diretto ad HA.
     */
    private static function proxy_ha_request($method, $path, $body = null) {
        if (!class_exists('VWGK_HA_Client')) {
            return self::error('bridge_missing', __('Bridge Virtual World Gate Key non trovato.', 'wp-gpt-automation-pro'), 500);
        }

        $response = \VWGK_HA_Client::request($method, $path, $body);
        if (is_wp_error($response)) {
            $data = $response->get_error_data();
            if (!isset($data['status']) || empty($data['status'])) {
                return new WP_Error($response->get_error_code(), $response->get_error_message(), ['status' => 500]);
            }
            return $response;
        }

        $success = $response['status_code'] >= 200 && $response['status_code'] < 300;
        
        return \rest_ensure_response([
            'success' => $success,
            'status'  => $response['status_code'],
            'data'    => $response['body'],
            'message' => $success ? __('Operazione Home Assistant completata.', 'wp-gpt-automation-pro') : __('Errore nella risposta di Home Assistant.', 'wp-gpt-automation-pro'),
            'error_details' => !$success ? $response['body'] : null
        ]);
    }

    /**
     * Genera lo shortcode per visualizzare le entità.
     */
    private static function get_shortcode($params) {
        $entities_list = isset($params['entities']) ? sanitize_text_field($params['entities']) : '';
        $columns       = isset($params['columns']) ? intval($params['columns']) : 2;

        if (empty($entities_list)) {
            return self::error('missing_entities', __('Specificare almeno un\'ID entità.', 'wp-gpt-automation-pro'), 400);
        }

        $shortcode = sprintf('[vwgk_ha_entities entities="%s" columns="%d"]', $entities_list, $columns);

        return \rest_ensure_response([
            'success'   => true,
            'shortcode' => $shortcode,
            'message'   => __('Shortcode generato correttamente.', 'wp-gpt-automation-pro')
        ]);
    }

    /**
     * Helper per restituzione errori.
     */
    private static function error($code, $message, $status = 500) {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
