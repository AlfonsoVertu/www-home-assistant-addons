<?php

if (!defined('ABSPATH')) {
    exit;
}

class VWGK_HA_API
{
    /**
     * Recupera tutte le entità o le filtra per array di prefissi o stringa.
     */
    public static function get_states(array $allowed_prefixes = [], array $excluded_prefixes = [], array $allowed_entities = [], int $limit = 0): array
    {
        $states = [];
        $response = VWGK_HA_Client::request('GET', '/states');

        if (!is_wp_error($response) && $response['status_code'] === 200 && is_array($response['body'])) {
            $states = $response['body'];
            // Salva nel transient (scade in 5 minuti, ma serve per accesso rapido)
            set_transient('vwgk_ha_entities_cache', $states, 300);
        } else {
            // Se HA non risponde o c'è errore, ripiega sul transient
            $cached = get_transient('vwgk_ha_entities_cache');
            if (is_array($cached) && !empty($cached)) {
                $states = $cached;
            } else {
                $error_msg = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . ($response['status_code'] ?? 'Unknown');
                return [
                    [
                        'entity_id' => 'error.api',
                        'state' => 'error',
                        'attributes' => [
                            'friendly_name' => 'Error: ' . $error_msg
                        ]
                    ]
                ];
            }
        }
        $filtered = [];
        $hidden_entities = get_option('vwgk_hidden_entities', []);

        foreach ($states as $state) {
            $entity_id = $state['entity_id'] ?? '';
            
            // Filtro globale blacklist: rimuove l'entità a meno che non sia specificatamente forzata via whitelist.
            if (empty($allowed_entities) && in_array($entity_id, $hidden_entities, true)) {
                continue;
            }
            
            // Applica whitelist entità precise
            if (!empty($allowed_entities)) {
                if (!in_array($entity_id, $allowed_entities, true)) {
                    continue; // salta se non è nell'elenco richiesto
                }
            } else {
                // Applica esclusioni (solo se non c'è una whitelist precisa)
                if (!empty($excluded_prefixes)) {
                    $skip = false;
                    foreach ($excluded_prefixes as $exc) {
                        if (strpos($entity_id, $exc) === 0) {
                            $skip = true;
                            break;
                        }
                    }
                    if ($skip) {
                        continue;
                    }
                }

                // Applica inclusioni prefissi
                if (!empty($allowed_prefixes)) {
                    $match = false;
                    foreach ($allowed_prefixes as $pref) {
                        if (strpos($entity_id, $pref) === 0) {
                            $match = true;
                            break;
                        }
                    }
                    if (!$match) {
                        continue;
                    }
                }
            }

            $filtered[] = $state;
        }

        // Ordina alfabeticamente per friendly_name
        usort($filtered, function($a, $b) {
            $nameA = $a['attributes']['friendly_name'] ?? $a['entity_id'];
            $nameB = $b['attributes']['friendly_name'] ?? $b['entity_id'];
            return strcasecmp($nameA, $nameB);
        });

        if ($limit > 0) {
            $filtered = array_slice($filtered, 0, $limit);
        }

        return $filtered;
    }

    public static function get_state(string $entity_id): array
    {
        $entities = self::get_states([], [], [$entity_id]);
        return (!empty($entities)) ? $entities[0] : [];
    }

    public static function get_general_entities(array $allowed_entities = [], int $limit = 0): array
    {
        return self::get_states(
            [],
            ['script.', 'automation.', 'conversation.', 'stt.', 'tts.', 'wake_word.'],
            $allowed_entities,
            $limit
        );
    }

    public static function get_scripts(array $allowed_entities = [], int $limit = 0): array
    {
        return self::get_states(['script.'], [], $allowed_entities, $limit);
    }

    public static function get_automations(array $allowed_entities = [], int $limit = 0): array
    {
        return self::get_states(['automation.'], [], $allowed_entities, $limit);
    }

    public static function get_assistants(array $allowed_entities = [], int $limit = 0): array
    {
        return self::get_states(['conversation.', 'assist_satellite.', 'stt.', 'tts.'], [], $allowed_entities, $limit);
    }

    public static function send_conversation_message($text, $agent_id = null, $conversation_id = null): array
    {
        $payload = [
            'text' => sanitize_text_field($text)
        ];

        if (!empty($agent_id)) {
            $payload['agent_id'] = sanitize_text_field($agent_id);
        }

        if (!empty($conversation_id)) {
            $payload['conversation_id'] = sanitize_text_field($conversation_id);
        }

        $response = VWGK_HA_Client::request('POST', '/conversation/process', $payload);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message()
            ];
        }

        if ($response['status_code'] !== 200) {
            return [
                'success' => false,
                'message' => 'HTTP ' . $response['status_code']
            ];
        }

        return [
            'success'  => true,
            'response' => $response['body']
        ];
    }

    public static function get_cameras(array $allowed_entities = [], int $limit = 0): array
    {
        return self::get_states(['camera.'], [], $allowed_entities, $limit);
    }

    public static function get_history(string $timestamp, string $entity_id = ''): array
    {
        $path = '/history/period/' . $timestamp;
        if (!empty($entity_id)) {
            $path .= '?filter_entity_id=' . $entity_id;
        }
        $response = VWGK_HA_Client::request('GET', $path);
        return (!is_wp_error($response) && $response['status_code'] === 200) ? $response['body'] : [];
    }

    public static function get_logbook(string $timestamp): array
    {
        $response = VWGK_HA_Client::request('GET', '/logbook/' . $timestamp);
        return (!is_wp_error($response) && $response['status_code'] === 200) ? $response['body'] : [];
    }

    public static function get_calendars(): array
    {
        $response = VWGK_HA_Client::request('GET', '/calendars');
        return (!is_wp_error($response) && $response['status_code'] === 200) ? $response['body'] : [];
    }

    public static function get_calendar_events(string $entity_id, string $start, string $end): array
    {
        $path = '/calendars/' . $entity_id . '?start=' . $start . '&end=' . $end;
        $response = VWGK_HA_Client::request('GET', $path);
        return (!is_wp_error($response) && $response['status_code'] === 200) ? $response['body'] : [];
    }
}
