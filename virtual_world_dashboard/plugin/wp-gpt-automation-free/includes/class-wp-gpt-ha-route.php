<?php

namespace WP_GPT_Automation_Pro;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Nuova rotta REST dedicata alla gestione di Home Assistant.
 * 
 * Endpoint: POST /wp-json/wp-gpt/v1/ha
 * 
 * Autenticazione: stessa di WP_GPT_API (Bearer token + user_code).
 * 
 * Azioni supportate nel body JSON:
 *  - action=discover  → Elenco entità HA paginato (filtrabili per tipo)
 *  - action=reload    → Ricarica configurazioni HA (reload_core_config)
 *  - action=execute   → Esegue un service HA (es. script.turn_on)
 */
class WP_GPT_HA_Route
{
    const NAMESPACE = 'wp-gpt/v1';
    const ROUTE     = '/ha';

    public static function init(): void
    {
        // La rotta non si registra piu' da sola: si entra da
        // manage-casahomeassistant-domoticandashboard-videocam, che
        // smista qui secondo l'action. Il gestore resta, la porta e' una.
        // add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    // Il metodo che registrava la rotta non c'e' piu': si entra da
    // manage-casahomeassistant-domoticandashboard-videocam. Lasciarlo qui
    // spento avrebbe fatto contare una rotta che non esiste a chi cerca
    // register_rest_route nel sorgente.


    public static function handle_request(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_body_params();
        }

        $action = sanitize_text_field($params['action'] ?? '');

        switch ($action) {
            case 'discover':
                return self::action_discover($params);
            case 'reload':
                return self::action_reload();
            case 'execute':
                return self::action_execute($params);
            case 'send-message-home-assistant-agent':
                return self::action_send_message_agent($params);
            default:
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'action non valida o mancante. Azioni supportate: discover, reload, execute, send-message-home-assistant-agent.',
                ], 400);
        }
    }

    /**
     * action=discover
     * 
     * Restituisce le entità di Home Assistant, filtrate per tipo e paginate.
     * 
     * Parametri opzionali:
     *   - type: string  (entities|scripts|automations|assistants|all)  default: all
     *   - page: int     default: 1
     *   - per_page: int default: 20
     */
    private static function action_discover(array $params): WP_REST_Response
    {
        $type      = sanitize_text_field($params['type'] ?? 'all');
        $page      = max(1, absint($params['page'] ?? 1));
        $per_page  = min(100, max(1, absint($params['per_page'] ?? 20)));
        $entities  = !empty($params['entities']) ? explode(',', sanitize_text_field($params['entities'])) : [];
        $limit     = isset($params['limit']) ? intval($params['limit']) : 0;

        // Se abbiamo una lista di entità specifica e un limite > 0, possiamo usare direttamente la logica di VWGK_HA_API
        // se il type è compatibile, ma per mantenere la retrocompatibilità della rotta REST (che ha i suoi prefissi)
        // applichiamo i filtri qui.

        $prefix_map = [
            'scripts'     => ['script.'],
            'automations' => ['automation.'],
            'assistants'  => ['conversation.', 'assist_satellite.', 'stt.', 'tts.'],
            'entities'    => [],  // handled below with exclusions
            'all'         => [], // no filter
        ];

        // Richiesta a Home Assistant
        $ha_response = self::ha_request('GET', '/states');

        if (is_wp_error($ha_response)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $ha_response->get_error_message(),
            ], 502);
        }

        if ($ha_response['status_code'] !== 200 || !is_array($ha_response['body'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Risposta non valida da Home Assistant (HTTP ' . $ha_response['status_code'] . ').',
                'raw'     => $ha_response['raw_body'] ?? '',
            ], 502);
        }

        $all_states = $ha_response['body'];
        $filtered   = [];

        // Prefissi dedicati agli shortcode "specifici" — esclusi dal type=entities
        $dedicated_prefixes = ['script.', 'automation.', 'conversation.', 'assist_satellite.', 'stt.', 'tts.'];

        foreach ($all_states as $state) {
            $entity_id = $state['entity_id'] ?? '';

            // Se c'è una whitelist di entità, controlliamo prima quella
            if (!empty($entities)) {
                if (!in_array($entity_id, $entities, true)) {
                    continue;
                }
            }

            if ($type === 'all') {
                $filtered[] = $state;
                continue;
            }

            if ($type === 'entities') {
                // general entities: all EXCEPT dedicated prefixes (unless explicitly in whitelist)
                if (empty($entities)) {
                    $skip = false;
                    foreach ($dedicated_prefixes as $dp) {
                        if (strpos($entity_id, $dp) === 0) {
                            $skip = true;
                            break;
                        }
                    }
                    if ($skip) {
                        continue;
                    }
                }
                $filtered[] = $state;
                continue;
            }

            $allowed = $prefix_map[$type] ?? [];
            foreach ($allowed as $pref) {
                if (strpos($entity_id, $pref) === 0) {
                    $filtered[] = $state;
                    break;
                }
            }
        }

        // Ordina per entity_id
        usort($filtered, fn($a, $b) => strcmp($a['entity_id'], $b['entity_id']));

        // Applica limite "globale" se specificato (prima della paginazione della rotta REST)
        if ($limit > 0) {
            $filtered = array_slice($filtered, 0, $limit);
        }

        $total    = count($filtered);
        $offset   = ($page - 1) * $per_page;
        $paginated = array_slice($filtered, $offset, $per_page);

        return new WP_REST_Response([
            'success'    => true,
            'action'     => 'discover',
            'type'       => $type,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per_page,
            'total_pages'=> (int) ceil($total / $per_page),
            'entities'   => $paginated,
        ], 200);
    }

    /**
     * action=reload
     * 
     * Forza un ricaricamento della configurazione core di Home Assistant.
     * Chiama POST /api/services/homeassistant/reload_core_config
     */
    private static function action_reload(): WP_REST_Response
    {
        $result = self::ha_request('POST', '/services/homeassistant/reload_core_config', []);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 502);
        }

        $success = $result['status_code'] >= 200 && $result['status_code'] < 300;

        return new WP_REST_Response([
            'success'     => $success,
            'action'      => 'reload',
            'ha_status'   => $result['status_code'],
        ], $success ? 200 : 502);
    }

    /**
     * action=send-message-home-assistant-agent
     * 
     * Invia un messaggio a un agente di conversazione specifico.
     */
    private static function action_send_message_agent(array $params): WP_REST_Response
    {
        $text            = sanitize_text_field($params['text'] ?? '');
        $agent_id        = sanitize_text_field($params['agent_id'] ?? '');
        $conversation_id = sanitize_text_field($params['conversation_id'] ?? '');

        if (empty($text)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Il parametro text è obbligatorio.',
            ], 400);
        }

        // Usiamo la logica già presente nel plugin tramite VWGK_HA_API
        if (class_exists('WP_GPT_Automation_Pro\VWGK_HA_API')) {
             $res = \WP_GPT_Automation_Pro\VWGK_HA_API::send_conversation($text, $agent_id, $conversation_id);
        } elseif (class_exists('VWGK_HA_API')) {
             $res = \VWGK_HA_API::send_conversation($text, $agent_id, $conversation_id);
        } else {
             return new WP_REST_Response([
                 'success' => false,
                 'message' => 'Classe VWGK_HA_API non trovata.',
             ], 500);
        }

        if (!$res['success']) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $res['message'],
            ], 502);
        }

        return new WP_REST_Response([
            'success'  => true,
            'action'   => 'send-message-home-assistant-agent',
            'response' => $res['response'],
        ], 200);
    }

    /**
     * action=execute
     * 
     * Esegue un service su un'entità specifica di Home Assistant.
     * 
     * Parametri obbligatori:
     *   - domain:    string  (es. script, automation, light)
     *   - service:   string  (es. turn_on, turn_off, trigger)
     * 
     * Parametri opzionali:
     *   - entity_id: string  (es. script.buongiorno)
     *   - data:      object  (payload aggiuntivo da passare al servizio)
     */
    private static function action_execute(array $params): WP_REST_Response
    {
        $domain  = sanitize_text_field($params['domain'] ?? '');
        $service = sanitize_text_field($params['service'] ?? '');

        if (empty($domain) || empty($service)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'I parametri domain e service sono obbligatori per action=execute.',
            ], 400);
        }

        $payload = [];
        if (!empty($params['entity_id'])) {
            $payload['entity_id'] = sanitize_text_field($params['entity_id']);
        }
        if (!empty($params['data']) && is_array($params['data'])) {
            $payload = array_merge($payload, $params['data']);
        }

        $result = self::ha_request('POST', '/services/' . rawurlencode($domain) . '/' . rawurlencode($service), $payload);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 502);
        }

        $success = $result['status_code'] >= 200 && $result['status_code'] < 300;

        return new WP_REST_Response([
            'success'        => $success,
            'action'         => 'execute',
            'called_domain'  => $domain,
            'called_service' => $service,
            'ha_status'      => $result['status_code'],
            'ha_response'    => $result['body'],
        ], $success ? 200 : 502);
    }

    /**
     * Eseguie una chiamata verso l'API di Home Assistant
     * usando la configurazione salvata nei settings di VWGK.
     */
    private static function ha_request(string $method, string $path, ?array $body = null)
    {
        $mode = get_option('vwgk_ha_auth_mode', 'supervisor_proxy');

        if ($mode === 'supervisor_proxy') {
            $token = getenv('SUPERVISOR_TOKEN');
            $url   = 'http://supervisor/core/api' . $path;
        } else {
            $token = (string) get_option('vwgk_ha_long_lived_token', '');
            $base  = rtrim((string) get_option('vwgk_ha_url', 'http://homeassistant:8123'), '/');
            $url   = $base . '/api' . $path;
        }

        if (empty($token)) {
            return new WP_Error('vwgk_missing_token', 'Token Home Assistant non configurato.', ['status' => 500]);
        }

        $args = [
            'method'  => strtoupper($method),
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'status_code' => wp_remote_retrieve_response_code($response),
            'body'        => json_decode(wp_remote_retrieve_body($response), true),
            'raw_body'    => wp_remote_retrieve_body($response),
            'url'         => $url,
        ];
    }
}
