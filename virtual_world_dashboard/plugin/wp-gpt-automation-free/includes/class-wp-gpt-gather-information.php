<?php

namespace WP_GPT_Automation_Pro;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class WP_GPT_Gather_Information {
    const ROUTE = '/gather-information-youtube-google-serp-keywrd-analisys-image-link/';

    const ACTION_SERP_ANALYSIS   = 'serpapi-serp-analysis';
    const ACTION_KEYWORD_ANALYSIS = 'serpapi-keyword-analysis';
    const ACTION_YOUTUBE_TRANSCRIPTION = 'youtube-transcription';
    const ACTION_SEARCH_YOUTUBE = 'search-youtube-video';
    const ACTION_EXTRACT_IMAGES = 'extract-image-links';
    const ACTION_SEO_GOOGLE_SEARCH     = 'seo_analisys_serpapi_google_search';
    const ACTION_SEO_GOOGLE_IMAGES     = 'seo_analisys_serpapi_google_images';
    const ACTION_SEO_GOOGLE_NEWS       = 'seo_analisys_serpapi_google_news';
    const ACTION_SEO_GOOGLE_TRENDS     = 'seo_analisys_serpapi_google_trends';
    const ACTION_SEO_GOOGLE_SHOPPING   = 'seo_analisys_serpapi_google_shopping';
    const ACTION_SEO_GOOGLE_SCHOLAR    = 'seo_analisys_serpapi_google_scholar';
    const ACTION_SEO_GOOGLE_MAPS       = 'seo_analisys_serpapi_google_maps';
    const ACTION_SEO_GOOGLE_OVERVIEW   = 'seo_analisys_serpapi_google_ai_overview';
    const ACTION_GOOGLE_RELATED_QUESTIONS = 'google_related_questions_api';
    const ACTION_GOOGLE_AUTOCOMPLETE      = 'google_autocomplete_api';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_rest_route']);
    }

    public static function register_rest_route() {
        register_rest_route(
            'wp-gpt/v1',
            self::ROUTE,
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'handle_request'],
                'permission_callback' => ['WP_GPT_Automation_Pro\\WP_GPT_API', 'validate_request_with_user_code_and_bearer'],
                'args'                => [
                    'action' => [
                        'required'          => true,
                        'validate_callback' => function ($param) {
                            if (!is_string($param) || $param === '') {
                                return false;
                            }

                            $allowed_actions = [
                                self::ACTION_SERP_ANALYSIS,
                                self::ACTION_KEYWORD_ANALYSIS,
                                self::ACTION_YOUTUBE_TRANSCRIPTION,
                                self::ACTION_SEARCH_YOUTUBE,
                                self::ACTION_EXTRACT_IMAGES,
                                self::ACTION_SEO_GOOGLE_SEARCH,
                                self::ACTION_SEO_GOOGLE_IMAGES,
                                self::ACTION_SEO_GOOGLE_NEWS,
                                self::ACTION_SEO_GOOGLE_TRENDS,
                                self::ACTION_SEO_GOOGLE_SHOPPING,
                                self::ACTION_SEO_GOOGLE_SCHOLAR,
                                self::ACTION_SEO_GOOGLE_MAPS,
                                self::ACTION_SEO_GOOGLE_OVERVIEW,
                                self::ACTION_GOOGLE_RELATED_QUESTIONS,
                                self::ACTION_GOOGLE_AUTOCOMPLETE,
                            ];

                            return in_array(sanitize_key($param), $allowed_actions, true);
                        },
                    ],
                    'keyword' => [
                        'required'          => false,
                        'validate_callback' => function ($param, WP_REST_Request $request) {
                            $action = sanitize_key((string) $request->get_param('action'));
                            if (in_array($action, [self::ACTION_SERP_ANALYSIS, self::ACTION_KEYWORD_ANALYSIS, self::ACTION_EXTRACT_IMAGES], true)) {
                                return is_string($param) && $param !== '';
                            }

                            return true;
                        },
                    ],
                    'hl' => [
                        'required'          => false,
                        'validate_callback' => function ($param, WP_REST_Request $request) {
                            $action = sanitize_key((string) $request->get_param('action'));
                            if ($action !== self::ACTION_SERP_ANALYSIS) {
                                return true;
                            }

                            return is_string($param) && preg_match('/^[a-z]{2}$/', $param);
                        },
                    ],
                    'gl' => [
                        'required'          => false,
                        'validate_callback' => function ($param, WP_REST_Request $request) {
                            $action = sanitize_key((string) $request->get_param('action'));
                            if ($action !== self::ACTION_SERP_ANALYSIS) {
                                return true;
                            }

                            return is_string($param) && preg_match('/^[A-Za-z]{2}$/', $param);
                        },
                    ],
                    'chunk_index' => [
                        'required'          => false,
                        'validate_callback' => function ($param, WP_REST_Request $request) {
                            $action = sanitize_key((string) $request->get_param('action'));
                            if (!in_array($action, [self::ACTION_SERP_ANALYSIS, self::ACTION_YOUTUBE_TRANSCRIPTION], true)) {
                                return true;
                            }

                            return is_numeric($param) && intval($param) >= 0;
                        },
                    ],
                    'chunk_size' => [
                        'required'          => false,
                        'validate_callback' => function ($param, WP_REST_Request $request) {
                            $action = sanitize_key((string) $request->get_param('action'));
                            if ($action !== self::ACTION_SERP_ANALYSIS) {
                                return true;
                            }

                            return is_numeric($param) && intval($param) > 0;
                        },
                    ],
                    'video_url' => [
                        'required'          => false,
                        'validate_callback' => function ($param, WP_REST_Request $request) {
                            $action = sanitize_key((string) $request->get_param('action'));
                            if ($action !== self::ACTION_YOUTUBE_TRANSCRIPTION) {
                                return true;
                            }

                            return is_string($param) && filter_var($param, FILTER_VALIDATE_URL);
                        },
                    ],
                    'query' => [
                        'required'          => false,
                        'validate_callback' => function ($param, WP_REST_Request $request) {
                            $action = sanitize_key((string) $request->get_param('action'));
                            if (!in_array($action, [self::ACTION_SEARCH_YOUTUBE, self::ACTION_GOOGLE_RELATED_QUESTIONS, self::ACTION_GOOGLE_AUTOCOMPLETE], true)) {
                                return true;
                            }

                            return is_string($param) && $param !== '';
                        },
                    ],
                    'language' => [
                        'required'          => false,
                        'validate_callback' => function ($param) {
                            if ($param === null) {
                                return true;
                            }

                            return is_string($param) && $param !== '';
                        },
                    ],
                    'country' => [
                        'required'          => false,
                        'validate_callback' => function ($param) {
                            if ($param === null) {
                                return true;
                            }

                            return is_string($param) && $param !== '';
                        },
                    ],
                    'save_transient' => [
                        'required'          => false,
                        'validate_callback' => function ($param, WP_REST_Request $request) {
                            $action = sanitize_key((string) $request->get_param('action'));
                            if (!in_array($action, [self::ACTION_GOOGLE_RELATED_QUESTIONS, self::ACTION_GOOGLE_AUTOCOMPLETE], true)) {
                                return true;
                            }

                            return self::is_boolean_like($param);
                        },
                    ],
                    'user_code' => [
                        'required'          => false,
                        'validate_callback' => function ($param) {
                            return is_string($param) || is_null($param);
                        },
                    ],
                ],
            ]
        );
    }

    public static function handle_request(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (!is_array($params) || empty($params)) {
            $body_params = $request->get_body_params();
            if (is_array($body_params) && !empty($body_params)) {
                $params = $body_params;
            } else {
                $params = $request->get_params();
            }
        }

        if (!is_array($params)) {
            $params = [];
        }

        $raw_action = isset($params['action']) ? $params['action'] : $request->get_param('action');
        $action     = is_string($raw_action) ? sanitize_key($raw_action) : '';
        if ($action === '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Parametro action mancante o non valido.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $save_transient = self::should_save_transient($params, $request);

        switch ($action) {
            case self::ACTION_SERP_ANALYSIS:
                $result = self::handle_post_serpapi_serp_analysis($params);
                break;
            case self::ACTION_KEYWORD_ANALYSIS:
                $result = self::handle_post_serpapi_keyword_analysis($params);
                break;
            case self::ACTION_YOUTUBE_TRANSCRIPTION:
                $result = self::handle_post_youtube_transcription($params);
                break;
            case self::ACTION_SEARCH_YOUTUBE:
                $result = self::handle_post_search_youtube_video($params);
                break;
            case self::ACTION_EXTRACT_IMAGES:
                $result = self::handle_post_extract_image_links($params);
                break;
            case self::ACTION_SEO_GOOGLE_SEARCH:
                $result = self::handle_post_google_search($params);
                break;
            case self::ACTION_SEO_GOOGLE_IMAGES:
                $result = self::handle_post_google_images($params);
                break;
            case self::ACTION_SEO_GOOGLE_NEWS:
                $result = self::handle_post_google_news($params);
                break;
            case self::ACTION_SEO_GOOGLE_TRENDS:
                $result = self::handle_post_google_trends($params);
                break;
            case self::ACTION_SEO_GOOGLE_SHOPPING:
                $result = self::handle_post_google_shopping($params);
                break;
            case self::ACTION_SEO_GOOGLE_SCHOLAR:
                $result = self::handle_post_google_scholar($params);
                break;
            case self::ACTION_SEO_GOOGLE_MAPS:
                $result = self::handle_post_google_maps($params);
                break;
            case self::ACTION_SEO_GOOGLE_OVERVIEW:
                $result = self::handle_post_google_ai_overview($params);
                break;
            case self::ACTION_GOOGLE_RELATED_QUESTIONS:
                $result = self::handle_google_related_questions_api($params, $save_transient);
                break;
            case self::ACTION_GOOGLE_AUTOCOMPLETE:
                $result = self::handle_google_autocomplete_api($params, $save_transient);
                break;
            default:
                $result = new WP_Error(
                    'invalid_action',
                    __('Azione richiesta non supportata.', 'wp-gpt-automation-pro'),
                    ['status' => 400]
                );
                break;
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    private static function handle_post_serpapi_serp_analysis(array $params)
    {
        $keyword = isset($params['keyword']) ? sanitize_text_field($params['keyword']) : '';
        if ($keyword === '') {
            return new WP_Error(
                'missing_keyword',
                __('Parametro keyword obbligatorio per l\'analisi SERP.', 'wp-gpt-automation-pro'),
                ['status' => 400]
            );
        }

        $hl = isset($params['hl']) ? sanitize_text_field($params['hl']) : 'it';
        if (!preg_match('/^[a-z]{2}$/', $hl)) {
            $hl = 'it';
        }

        $gl = isset($params['gl']) ? sanitize_text_field($params['gl']) : 'it';
        if (!preg_match('/^[A-Za-z]{2}$/', $gl)) {
            $gl = 'it';
        }

        $chunk_index = isset($params['chunk_index']) ? max(0, intval($params['chunk_index'])) : 0;
        $chunk_size  = isset($params['chunk_size']) ? intval($params['chunk_size']) : 10;
        if ($chunk_size <= 0) {
            $chunk_size = 10;
        }

        $serpapi_key = get_option('wp_gpt_serpapi_key');
        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Chiave API SerpAPI non configurata.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $transient_key = 'wp_gpt_serpapi_serp_analysis_' . md5($keyword . $chunk_index);
        $cached_data    = get_transient($transient_key);

        if ($cached_data !== false) {
            return new WP_REST_Response([
                'success' => true,
                'cached'  => true,
                'data'    => $cached_data,
            ], 200);
        }

        $start = $chunk_index * $chunk_size;
        $url   = sprintf(
            'https://serpapi.com/search.json?q=%s&hl=%s&gl=%s&start=%d&num=%d&api_key=%s',
            urlencode($keyword),
            rawurlencode($hl),
            rawurlencode($gl),
            $start,
            $chunk_size,
            rawurlencode($serpapi_key)
        );

        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Errore nella chiamata a SerpAPI: %s', 'wp-gpt-automation-pro'),
                    $response->get_error_message()
                ),
            ], 500);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['organic_results'])) {
            $chunked_results = array_slice($data['organic_results'], 0, $chunk_size);
            set_transient($transient_key, $chunked_results, 24 * HOUR_IN_SECONDS);

            return new WP_REST_Response([
                'success' => true,
                'cached'  => false,
                'data'    => $chunked_results,
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'message' => __('Nessun risultato trovato.', 'wp-gpt-automation-pro'),
        ], 404);
    }

    private static function handle_post_serpapi_keyword_analysis(array $params)
    {
        $keyword = isset($params['keyword']) ? sanitize_text_field($params['keyword']) : '';
        if ($keyword === '') {
            return new WP_Error(
                'missing_keyword',
                __('Parametro keyword obbligatorio per l\'analisi keyword.', 'wp-gpt-automation-pro'),
                ['status' => 400]
            );
        }

        $serpapi_key = get_option('wp_gpt_serpapi_key');
        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Chiave API SerpAPI non configurata.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $transient_key = 'wp_gpt_serpapi_analysis_' . md5($keyword);
        $cached_data    = get_transient($transient_key);

        if ($cached_data !== false) {
            return new WP_REST_Response([
                'success' => true,
                'cached'  => true,
                'data'    => $cached_data,
            ], 200);
        }

        $url      = sprintf(
            'https://serpapi.com/search.json?q=%s&location=Italy&hl=it&gl=it&api_key=%s',
            urlencode($keyword),
            rawurlencode($serpapi_key)
        );
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Errore nella chiamata a SerpAPI: %s', 'wp-gpt-automation-pro'),
                    $response->get_error_message()
                ),
            ], 500);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        $analysis_data = WP_GPT_Serpapi::analyze_serp_data($data);
        set_transient($transient_key, $analysis_data, 24 * HOUR_IN_SECONDS);

        return new WP_REST_Response([
            'success' => true,
            'cached'  => false,
            'data'    => $analysis_data,
        ], 200);
    }

    private static function handle_post_youtube_transcription(array $params)
    {
        global $wpdb;

        $log        = [];
        $video_url  = isset($params['video_url']) ? esc_url_raw($params['video_url']) : '';
        $chunk_index = isset($params['chunk_index']) ? intval($params['chunk_index']) : 0;

        if ($video_url === '') {
            $log[] = __('Errore: URL del video non fornito nella richiesta.', 'wp-gpt-automation-pro');

            return new WP_REST_Response([
                'error' => __('URL del video non fornito.', 'wp-gpt-automation-pro'),
                'log'   => $log,
            ], 400);
        }

        $log[] = sprintf(__('URL del video fornito: %s', 'wp-gpt-automation-pro'), $video_url);
        $log[] = sprintf(__('Chunk richiesto: %d', 'wp-gpt-automation-pro'), $chunk_index);

        $transcriptions_table = $wpdb->prefix . 'youtube_transcriptions';
        $transcription_record  = $wpdb->get_row(
            $wpdb->prepare("SELECT transcription FROM {$transcriptions_table} WHERE video_url = %s", $video_url)
        );

        if ($transcription_record && !empty($transcription_record->transcription)) {
            $log[] = __('Trascrizione trovata nel database. Recupero chunk richiesto...', 'wp-gpt-automation-pro');

            return self::format_transcription_chunk_response($transcription_record->transcription, $chunk_index, $log);
        }

        $log[] = __('Trascrizione non presente nel database, avvio richiesta all\'API.', 'wp-gpt-automation-pro');

        $api_key = get_option('wp_gpt_youtube_transcription_api_key');
        if (empty($api_key)) {
            $log[] = __('Errore: Chiave API di trascrizione non trovata nelle impostazioni.', 'wp-gpt-automation-pro');

            return new WP_REST_Response([
                'error' => __('Chiave API non configurata. Impostare la chiave API nelle impostazioni del plugin.', 'wp-gpt-automation-pro'),
                'log'   => $log,
            ], 500);
        }

        $log[] = __('Chiave API di trascrizione recuperata correttamente.', 'wp-gpt-automation-pro');

        $api_url      = 'https://youtube-transcripts.p.rapidapi.com/youtube/transcript';
        $query_string = http_build_query([
            'url'       => $video_url,
            'chunkSize' => 500,
        ]);
        $log[] = sprintf(__('URL di richiesta configurato: %s?%s', 'wp-gpt-automation-pro'), $api_url, $query_string);

        $headers = [
            'x-rapidapi-host: youtube-transcripts.p.rapidapi.com',
            'x-rapidapi-key: ' . $api_key,
        ];
        $log[] = sprintf(__('Intestazioni HTTP impostate: %s', 'wp-gpt-automation-pro'), wp_json_encode($headers));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . '?' . $query_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $log[] = sprintf(__('Errore nella richiesta cURL: %s', 'wp-gpt-automation-pro'), $error);

            return new WP_REST_Response([
                'error'   => __('Errore nella richiesta al servizio di trascrizione.', 'wp-gpt-automation-pro'),
                'details' => $error,
                'log'     => $log,
            ], 500);
        }

        $log[] = __('Risposta API ricevuta con successo.', 'wp-gpt-automation-pro');

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_error = json_last_error_msg();
            $log[]      = sprintf(__('Errore nel decodificare la risposta JSON: %s', 'wp-gpt-automation-pro'), $json_error);

            return new WP_REST_Response([
                'error'   => __('Errore nel decodificare la risposta JSON dal servizio di trascrizione.', 'wp-gpt-automation-pro'),
                'details' => $json_error,
                'log'     => $log,
            ], 500);
        }

        $log[] = sprintf(__('Risposta JSON decodificata con successo: %s', 'wp-gpt-automation-pro'), wp_json_encode($data));

        if (!isset($data['content']) || !isset($data['lang'])) {
            $log[] = sprintf(
                __('Errore: Dati mancanti nella risposta dell\'API di trascrizione. Risposta completa: %s', 'wp-gpt-automation-pro'),
                wp_json_encode($data)
            );

            return new WP_REST_Response([
                'error' => __('Dati mancanti nella risposta dall\'API di trascrizione.', 'wp-gpt-automation-pro'),
                'log'   => $log,
            ], 500);
        }

        $transcription_content = '';
        foreach ($data['content'] as $segment) {
            if (isset($segment['text'])) {
                $transcription_content .= $segment['text'] . ' ';
            }
        }

        $log[] = sprintf(__('Trascrizione ottenuta: %d caratteri.', 'wp-gpt-automation-pro'), strlen($transcription_content));

        $wpdb->insert(
            $transcriptions_table,
            [
                'video_url'     => $video_url,
                'transcription' => $transcription_content,
            ],
            ['%s', '%s']
        );

        $log[] = __('Trascrizione salvata nel database correttamente.', 'wp-gpt-automation-pro');

        return self::format_transcription_chunk_response($transcription_content, $chunk_index, $log);
    }

    private static function handle_post_search_youtube_video(array $params)
    {
        $query = isset($params['query']) ? sanitize_text_field($params['query']) : '';
        if ($query === '') {
            return new WP_Error(
                'missing_query',
                __('Parametro query obbligatorio per la ricerca su YouTube.', 'wp-gpt-automation-pro'),
                ['status' => 400]
            );
        }

        $youtube_api_key = get_option('wp_gpt_youtube_api_key');
        if (empty($youtube_api_key)) {
            return new WP_REST_Response([
                'error' => __('Chiave API di YouTube non configurata.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $request_args = [
            'timeout'     => 15,
            'redirection' => 5,
            'headers'     => [
                'X-RapidAPI-Host' => 'yt-api.p.rapidapi.com',
                'X-RapidAPI-Key'  => $youtube_api_key,
            ],
        ];

        $search_url          = 'https://yt-api.p.rapidapi.com/search?query=' . urlencode($query);
        $search_transient_key = 'wp_gpt_youtube_search_' . md5($query);
        $search_data          = get_transient($search_transient_key);

        if ($search_data === false) {
            $search_response = wp_remote_get($search_url, $request_args);

            if (is_wp_error($search_response)) {
                return new WP_REST_Response([
                    'error' => sprintf(
                        /* translators: %s: error message */
                        __('Impossibile recuperare i dati dalla YouTube API: %s', 'wp-gpt-automation-pro'),
                        $search_response->get_error_message()
                    ),
                ], 500);
            }

            $search_body = wp_remote_retrieve_body($search_response);
            $search_data = json_decode($search_body, true);

            if ($search_data !== null) {
                set_transient($search_transient_key, $search_data, MINUTE_IN_SECONDS * 10);
            }
        }

        $search_data = is_array($search_data) ? $search_data : null;
        if ($search_data === null) {
            return new WP_REST_Response([
                'error' => __('Risposta JSON non valida dalla YouTube API.', 'wp-gpt-automation-pro'),
            ], 500);
        }

        if (!isset($search_data['data'])) {
            return new WP_REST_Response([
                'error' => __('Nessun dato trovato nella risposta della ricerca.', 'wp-gpt-automation-pro'),
            ], 404);
        }

        $video_ids = [];
        foreach ($search_data['data'] as $key => $video) {
            if ($key >= 5) {
                break;
            }

            if (isset($video['videoId'])) {
                $video_ids[] = $video['videoId'];
            }
        }

        if (empty($video_ids)) {
            return new WP_REST_Response([
                'error' => __('Nessun video valido trovato nella risposta della ricerca.', 'wp-gpt-automation-pro'),
            ], 404);
        }

        $videos_info = [];

        foreach ($video_ids as $video_id) {
            $dl_url            = 'https://yt-api.p.rapidapi.com/dl?id=' . urlencode($video_id);
            $dl_transient_key  = 'wp_gpt_youtube_dl_' . md5($video_id);
            $dl_data           = get_transient($dl_transient_key);

            if ($dl_data === false) {
                $dl_response = wp_remote_get($dl_url, $request_args);

                if (is_wp_error($dl_response)) {
                    continue;
                }

                $dl_body = wp_remote_retrieve_body($dl_response);
                $dl_data = json_decode($dl_body, true);

                if ($dl_data !== null) {
                    set_transient($dl_transient_key, $dl_data, MINUTE_IN_SECONDS * 10);
                }
            }

            if ($dl_data === null) {
                continue;
            }

            if (!isset($dl_data['formats'])) {
                continue;
            }

            $max_format = null;
            $max_value  = 0;

            foreach ($dl_data['formats'] as $format) {
                if (isset($format['bitrate']) && $format['bitrate'] > $max_value && isset($format['url'])) {
                    $max_value  = $format['bitrate'];
                    $max_format = $format;
                }
            }

            if ($max_format && isset($max_format['url'])) {
                $videos_info[] = [
                    'videoId'     => $video_id,
                    'title'       => $dl_data['title'] ?? __('Titolo non disponibile', 'wp-gpt-automation-pro'),
                    'description' => $dl_data['description'] ?? __('Descrizione non disponibile', 'wp-gpt-automation-pro'),
                    'video_url'   => $max_format['url'],
                ];
            }
        }

        if (empty($videos_info)) {
            return new WP_REST_Response([
                'error' => __('Nessun video valido trovato.', 'wp-gpt-automation-pro'),
            ], 404);
        }

        return new WP_REST_Response($videos_info, 200);
    }

    private static function handle_post_extract_image_links(array $params)
    {
        $keyword = isset($params['keyword']) ? sanitize_text_field($params['keyword']) : '';
        if ($keyword === '') {
            return new WP_Error(
                'missing_keyword',
                __('Parametro keyword obbligatorio per l\'estrazione delle immagini.', 'wp-gpt-automation-pro'),
                ['status' => 400]
            );
        }

        $normalized_keyword = preg_replace('/[^a-zA-Z0-9]/', '_', $keyword);
        $encoded_keyword    = urlencode($normalized_keyword);

        $search_url = "https://commons.wikimedia.org/w/api.php?action=query&list=allimages&aiprefix={$encoded_keyword}&ailimit=10&format=json";

        $response = WP_GPT_API::get_html_with_user_agent($search_url);
        if ($response === false) {
            return new WP_REST_Response([
                'error' => __('Errore durante il recupero della pagina', 'wp-gpt-automation-pro'),
            ], 500);
        }

        $data        = json_decode($response, true);
        $image_links = [];

        if (isset($data['query']['allimages'])) {
            foreach ($data['query']['allimages'] as $image) {
                if (isset($image['url'])) {
                    $image_links[] = $image['url'];
                }
            }
        }

        if (empty($image_links)) {
            return new WP_REST_Response([
                'error' => __('Nessuna immagine trovata', 'wp-gpt-automation-pro'),
            ], 404);
        }

        return new WP_REST_Response([
            'image_links' => $image_links,
        ], 200);
    }

    private static function handle_post_google_search(array $params)
    {
        $serpapi_key = get_option('wp_gpt_serpapi_key');
        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Chiave API SerpAPI non configurata.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $query_args = ['engine' => 'google'];

        if (!empty($params['keyword'])) {
            $query_args['q'] = sanitize_text_field($params['keyword']);
        }
        if (!empty($params['language'])) {
            $query_args['hl'] = sanitize_text_field($params['language']);
        }
        if (!empty($params['country'])) {
            $query_args['gl'] = sanitize_text_field($params['country']);
        }
        if (!empty($params['location'])) {
            $query_args['location'] = sanitize_text_field($params['location']);
        }
        if (!empty($params['results_per_page'])) {
            $query_args['num'] = intval($params['results_per_page']);
        }
        if (!empty($params['page_number'])) {
            $query_args['start'] = intval($params['page_number']);
        }
        if (!empty($params['safe_search'])) {
            $query_args['safe'] = sanitize_text_field($params['safe_search']);
        }
        if (!empty($params['device'])) {
            $query_args['device'] = sanitize_text_field($params['device']);
        }
        if (!empty($params['time_filter'])) {
            $query_args['tbs'] = sanitize_text_field($params['time_filter']);
        }
        if (!empty($params['api_domain'])) {
            $query_args['google_domain'] = sanitize_text_field($params['api_domain']);
        }
        if (!empty($params['output_format'])) {
            $query_args['output'] = sanitize_text_field($params['output_format']);
        }

        $query_args['api_key'] = $serpapi_key;

        $url      = 'https://serpapi.com/search.json?' . http_build_query($query_args);
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Errore nella chiamata a SerpAPI: %s', 'wp-gpt-automation-pro'),
                    $response->get_error_message()
                ),
            ], 500);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Risposta non valida da SerpAPI.', 'wp-gpt-automation-pro'),
            ], 500);
        }

        if (isset($data['error'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => sanitize_text_field((string) $data['error']),
            ], 500);
        }

        $allowed_fields    = [
            'position',
            'title',
            'product_link',
            'product_id',
            'price',
            'extracted_price',
            'source',
            'source_icon',
            'rating',
            'reviews',
            'thumbnail',
            'immersive_product_page_token',
            'serpapi_product_api',
        ];
        $shopping_results  = [];
        $raw_results       = isset($data['shopping_results']) && is_array($data['shopping_results']) ? $data['shopping_results'] : [];

        foreach ($raw_results as $item) {
            if (!is_array($item)) {
                continue;
            }

            $filtered_item = [];

            foreach ($allowed_fields as $field) {
                $filtered_item[$field] = null;
            }

            if (isset($item['position']) && is_numeric($item['position'])) {
                $filtered_item['position'] = (int) $item['position'];
            }
            if (isset($item['title'])) {
                $filtered_item['title'] = sanitize_text_field((string) $item['title']);
            }
            if (isset($item['product_link'])) {
                $filtered_item['product_link'] = esc_url_raw($item['product_link']);
            }
            if (isset($item['product_id'])) {
                $filtered_item['product_id'] = sanitize_text_field((string) $item['product_id']);
            }
            if (isset($item['price'])) {
                $filtered_item['price'] = sanitize_text_field((string) $item['price']);
            }
            if (isset($item['extracted_price']) && is_numeric($item['extracted_price'])) {
                $filtered_item['extracted_price'] = (float) $item['extracted_price'];
            }
            if (isset($item['source'])) {
                $filtered_item['source'] = sanitize_text_field((string) $item['source']);
            }
            if (isset($item['source_icon'])) {
                $filtered_item['source_icon'] = esc_url_raw($item['source_icon']);
            }
            if (isset($item['rating']) && is_numeric($item['rating'])) {
                $filtered_item['rating'] = (float) $item['rating'];
            }
            if (isset($item['reviews']) && is_numeric($item['reviews'])) {
                $filtered_item['reviews'] = (int) $item['reviews'];
            }
            if (isset($item['thumbnail'])) {
                $filtered_item['thumbnail'] = esc_url_raw($item['thumbnail']);
            }
            if (isset($item['immersive_product_page_token'])) {
                $filtered_item['immersive_product_page_token'] = sanitize_text_field((string) $item['immersive_product_page_token']);
            }
            if (isset($item['serpapi_product_api'])) {
                $filtered_item['serpapi_product_api'] = esc_url_raw($item['serpapi_product_api']);
            }

            $shopping_results[] = $filtered_item;
        }

        return new WP_REST_Response([
            'success'          => true,
            'shopping_results' => $shopping_results,
        ], 200);
    }

    private static function handle_post_google_images(array $params)
    {
        $serpapi_key = get_option('wp_gpt_serpapi_key');
        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Chiave API SerpAPI non configurata.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $query_args = ['engine' => 'google_images'];

        if (!empty($params['keyword'])) {
            $query_args['q'] = sanitize_text_field($params['keyword']);
        }
        if (!empty($params['page_number'])) {
            $query_args['ijn'] = intval($params['page_number']);
        }
        if (!empty($params['language'])) {
            $query_args['hl'] = sanitize_text_field($params['language']);
        }
        if (!empty($params['country'])) {
            $query_args['gl'] = sanitize_text_field($params['country']);
        }
        if (!empty($params['location'])) {
            $query_args['location'] = sanitize_text_field($params['location']);
        }
        if (!empty($params['results_per_page'])) {
            $query_args['num'] = intval($params['results_per_page']);
        }
        if (!empty($params['time_filter'])) {
            $query_args['tbs'] = sanitize_text_field($params['time_filter']);
        }
        if (!empty($params['safe_search'])) {
            $query_args['safe'] = sanitize_text_field($params['safe_search']);
        }
        if (!empty($params['device'])) {
            $query_args['device'] = sanitize_text_field($params['device']);
        }

        $query_args['api_key'] = $serpapi_key;

        return self::execute_serpapi_request($query_args);
    }

    /**
     * Gestione Google Related Questions API (People Also Ask).
     */
    private static function handle_google_related_questions_api(array $params, bool $save_transient)
    {
        $serpapi_key = get_option('wp_gpt_serpapi_key');
        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Chiave API SerpAPI non configurata.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $query = isset($params['query']) ? sanitize_text_field((string) $params['query']) : '';
        if ($query === '') {
            return new WP_Error(
                'missing_query',
                __('Parametro query obbligatorio per google_related_questions_api.', 'wp-gpt-automation-pro'),
                ['status' => 400]
            );
        }

        $language = isset($params['language']) ? sanitize_text_field((string) $params['language']) : '';
        if ($language === '') {
            return new WP_Error(
                'missing_language',
                __('Parametro language obbligatorio per google_related_questions_api.', 'wp-gpt-automation-pro'),
                ['status' => 400]
            );
        }

        $country = isset($params['country']) ? sanitize_text_field((string) $params['country']) : '';
        if ($country === '') {
            return new WP_Error(
                'missing_country',
                __('Parametro country obbligatorio per google_related_questions_api.', 'wp-gpt-automation-pro'),
                ['status' => 400]
            );
        }

        $request_params = [
            'engine'  => 'google',
            'q'       => $query,
            'api_key' => $serpapi_key,
            'hl'      => $language,
            'gl'      => $country,
        ];

        if (!empty($params['next_page_token'])) {
            $request_params['next_page_token'] = sanitize_text_field((string) $params['next_page_token']);
        }

        $results = self::perform_serpapi_json_request($request_params);

        if (is_wp_error($results)) {
            return $results;
        }

        if ($save_transient) {
            $key = 'serpapi_related_questions_' . md5(wp_json_encode($request_params));
            set_transient($key, $results, DAY_IN_SECONDS);

            return [
                'transient_key' => $key,
                'data'          => $results,
            ];
        }

        return $results;
    }

    /**
     * Gestione Google Autocomplete API.
     */
        private static function handle_google_autocomplete_api(array $params, bool $save_transient)
        {
            $serpapi_key = get_option('wp_gpt_serpapi_key');
            if (empty($serpapi_key)) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => __('Chiave API SerpAPI non configurata.', 'wp-gpt-automation-pro'),
                ], 400);
            }

            $query = isset($params['query']) ? sanitize_text_field((string) $params['query']) : '';
            if ($query === '') {
                return new WP_Error(
                    'missing_query',
                    __('Parametro query obbligatorio per google_autocomplete_api.', 'wp-gpt-automation-pro'),
                    ['status' => 400]
                );
            }

            $request_params = [
                'engine'  => 'google_autocomplete',
                'q'       => $query,
                'api_key' => $serpapi_key,
            ];

            if (!empty($params['language'])) {
                $request_params['hl'] = sanitize_text_field((string) $params['language']);
            }

            if (!empty($params['country'])) {
                $request_params['gl'] = sanitize_text_field((string) $params['country']);
            }

            $results = self::perform_serpapi_json_request($request_params);

            if (is_wp_error($results)) {
                return $results;
            }

            if ($save_transient) {
                $key = 'serpapi_autocomplete_' . md5(wp_json_encode($request_params));
                set_transient($key, $results, DAY_IN_SECONDS);

                return [
                    'transient_key' => $key,
                    'data'          => $results,
                ];
            }

            return $results;
        }

    private static function handle_post_google_news(array $params)
    {
        $serpapi_key = get_option('wp_gpt_serpapi_key');
        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Chiave API SerpAPI non configurata.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $query_args = ['engine' => 'google_news'];

        if (!empty($params['keyword'])) {
            $query_args['q'] = sanitize_text_field($params['keyword']);
        }
        if (!empty($params['results_per_page'])) {
            $query_args['num'] = intval($params['results_per_page']);
        }
        if (!empty($params['page_number'])) {
            $query_args['start'] = intval($params['page_number']);
        }
        if (!empty($params['language'])) {
            $query_args['hl'] = sanitize_text_field($params['language']);
        }
        if (!empty($params['country'])) {
            $query_args['gl'] = sanitize_text_field($params['country']);
        }
        if (!empty($params['location'])) {
            $query_args['location'] = sanitize_text_field($params['location']);
        }
        if (!empty($params['time_filter'])) {
            $query_args['tbs'] = sanitize_text_field($params['time_filter']);
        }
        if (!empty($params['safe_search'])) {
            $query_args['safe'] = sanitize_text_field($params['safe_search']);
        }

        $query_args['api_key'] = $serpapi_key;

        return self::execute_serpapi_request($query_args);
    }

    private static function handle_post_google_trends(array $params)
    {
        $serpapi_key = get_option('wp_gpt_serpapi_key');
        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Chiave API SerpAPI non configurata.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $query_args = ['engine' => 'google_trends'];

        if (!empty($params['trend_type'])) {
            $query_args['trend_type'] = sanitize_text_field($params['trend_type']);
        }
        if (!empty($params['keyword'])) {
            $query_args['q'] = sanitize_text_field($params['keyword']);
        }
        if (!empty($params['geo'])) {
            $query_args['geo'] = sanitize_text_field($params['geo']);
        }
        if (!empty($params['category'])) {
            $query_args['cat'] = sanitize_text_field($params['category']);
        }
        if (!empty($params['date_range'])) {
            $query_args['date'] = sanitize_text_field($params['date_range']);
        }

        $query_args['api_key'] = $serpapi_key;

        return self::execute_serpapi_request($query_args);
    }

    private static function handle_post_google_shopping(array $params)
    {
        $serpapi_key = get_option('wp_gpt_serpapi_key');
        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Chiave API SerpAPI non configurata.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $query_args = ['engine' => 'google_shopping'];

        if (!empty($params['keyword'])) {
            $query_args['q'] = sanitize_text_field($params['keyword']);
        }
        if (!empty($params['results_per_page'])) {
            $query_args['num'] = intval($params['results_per_page']);
        }
        if (!empty($params['page_number'])) {
            $query_args['start'] = intval($params['page_number']);
        }
        if (!empty($params['language'])) {
            $query_args['hl'] = sanitize_text_field($params['language']);
        }
        if (!empty($params['country'])) {
            $query_args['gl'] = sanitize_text_field($params['country']);
        }
        if (!empty($params['location'])) {
            $query_args['location'] = sanitize_text_field($params['location']);
        }
        if (!empty($params['shopping_condition'])) {
            $query_args['tbs'] = sanitize_text_field($params['shopping_condition']);
        }
        if (!empty($params['safe_search'])) {
            $query_args['safe'] = sanitize_text_field($params['safe_search']);
        }
        if (!empty($params['device'])) {
            $query_args['device'] = sanitize_text_field($params['device']);
        }

        $query_args['api_key'] = $serpapi_key;

        return self::execute_serpapi_request($query_args);
    }

    private static function handle_post_google_scholar(array $params)
    {
        $serpapi_key = get_option('wp_gpt_serpapi_key');
        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Chiave API SerpAPI non configurata.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $query_args = ['engine' => 'google_scholar'];

        if (!empty($params['keyword'])) {
            $query_args['q'] = sanitize_text_field($params['keyword']);
        }
        if (!empty($params['results_per_page'])) {
            $query_args['num'] = intval($params['results_per_page']);
        }
        if (!empty($params['page_number'])) {
            $query_args['start'] = intval($params['page_number']);
        }
        if (!empty($params['language'])) {
            $query_args['hl'] = sanitize_text_field($params['language']);
        }
        if (!empty($params['country'])) {
            $query_args['gl'] = sanitize_text_field($params['country']);
        }
        if (!empty($params['cite_id'])) {
            $query_args['cite_id'] = sanitize_text_field($params['cite_id']);
        }
        if (!empty($params['cluster_id'])) {
            $query_args['cluster_id'] = sanitize_text_field($params['cluster_id']);
        }
        if (!empty($params['author_id'])) {
            $query_args['author_id'] = sanitize_text_field($params['author_id']);
        }

        $query_args['api_key'] = $serpapi_key;

        return self::execute_serpapi_request($query_args);
    }

    private static function handle_post_google_maps(array $params)
    {
        $serpapi_key = get_option('wp_gpt_serpapi_key');
        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Chiave API SerpAPI non configurata.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $query_args = ['engine' => 'google_maps'];

        if (!empty($params['query'])) {
            $query_args['q'] = sanitize_text_field($params['query']);
        }
        if (!empty($params['type'])) {
            $query_args['type'] = sanitize_text_field($params['type']);
        }
        if (!empty($params['latitude']) && !empty($params['longitude'])) {
            $query_args['ll'] = sanitize_text_field($params['latitude']) . ',' . sanitize_text_field($params['longitude']);
        }
        if (!empty($params['location'])) {
            $query_args['location'] = sanitize_text_field($params['location']);
        }
        if (!empty($params['zoom_level'])) {
            $query_args['zoom'] = intval($params['zoom_level']);
        }
        if (!empty($params['data_type'])) {
            $query_args['data'] = sanitize_text_field($params['data_type']);
        }
        if (!empty($params['language'])) {
            $query_args['hl'] = sanitize_text_field($params['language']);
        }
        if (!empty($params['country'])) {
            $query_args['gl'] = sanitize_text_field($params['country']);
        }

        $query_args['api_key'] = $serpapi_key;

        return self::execute_serpapi_request($query_args);
    }

    private static function handle_post_google_ai_overview(array $params)
    {
        $serpapi_key = get_option('wp_gpt_serpapi_key');
        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Chiave API SerpAPI non configurata.', 'wp-gpt-automation-pro'),
            ], 400);
        }

        $query_args = ['engine' => 'google_ai_overview'];

        if (!empty($params['keyword'])) {
            $query_args['q'] = sanitize_text_field($params['keyword']);
        }
        if (!empty($params['language'])) {
            $query_args['hl'] = sanitize_text_field($params['language']);
        }
        if (!empty($params['country'])) {
            $query_args['gl'] = sanitize_text_field($params['country']);
        }
        if (!empty($params['location'])) {
            $query_args['location'] = sanitize_text_field($params['location']);
        }
        if (!empty($params['results_per_page'])) {
            $query_args['num'] = intval($params['results_per_page']);
        }
        if (!empty($params['page_number'])) {
            $query_args['start'] = intval($params['page_number']);
        }

        $query_args['api_key'] = $serpapi_key;

        return self::execute_serpapi_request($query_args);
    }

    private static function execute_serpapi_request(array $query_args)
    {
        $url      = 'https://serpapi.com/search.json?' . http_build_query($query_args);
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Errore nella chiamata a SerpAPI: %s', 'wp-gpt-automation-pro'),
                    $response->get_error_message()
                ),
            ], 500);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return new WP_REST_Response([
            'success' => true,
            'params'  => $query_args,
            'data'    => $data,
        ], 200);
    }

    private static function perform_serpapi_json_request(array $query_args)
    {
        $url      = 'https://serpapi.com/search.json?' . http_build_query($query_args);
$response = wp_remote_get($url, ['timeout' => 20]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'serpapi_request_error',
                sprintf(
                    /* translators: %s: error message */
                    __('Errore nella chiamata a SerpAPI: %s', 'wp-gpt-automation-pro'),
                    $response->get_error_message()
                ),
                ['status' => 500]
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'serpapi_invalid_response',
                __('Risposta non valida da SerpAPI.', 'wp-gpt-automation-pro'),
                ['status' => 500]
            );
        }

        return $data;
    }

    private static function should_save_transient(array $params, WP_REST_Request $request)
    {
        if (array_key_exists('save_transient', $params)) {
            return rest_sanitize_boolean($params['save_transient']);
        }

        $raw = $request->get_param('save_transient');
        if ($raw !== null) {
            return rest_sanitize_boolean($raw);
        }

        return false;
    }

    private static function is_boolean_like($value)
    {
        if (is_bool($value)) {
            return true;
        }

        if (is_string($value)) {
            $value = strtolower($value);

            return in_array($value, ['1', '0', 'true', 'false', 'yes', 'no'], true);
        }

        return in_array($value, [0, 1], true);
    }

    private static function format_transcription_chunk_response(string $transcription, int $chunk_index, array $log)
    {
        $chunk_size    = defined('WP_GPT_CHUNK_SIZE') ? WP_GPT_CHUNK_SIZE : 2000;
        $total_length  = strlen($transcription);
        $total_chunks  = $chunk_size > 0 ? (int) ceil($total_length / $chunk_size) : 0;

        $log[] = sprintf(__('Lunghezza totale della trascrizione: %d caratteri.', 'wp-gpt-automation-pro'), $total_length);
        $log[] = sprintf(__('Numero totale di chunk: %d.', 'wp-gpt-automation-pro'), $total_chunks);

        if ($chunk_index < 0) {
            $chunk_index = 0;
        } elseif ($total_chunks > 0 && $chunk_index >= $total_chunks) {
            $chunk_index = $total_chunks - 1;
        }

        $start         = $chunk_index * $chunk_size;
        $chunk_content = substr($transcription, $start, $chunk_size);

        $log[] = sprintf(__('Restituito chunk %1$d su %2$d chunk totali.', 'wp-gpt-automation-pro'), $chunk_index, max($total_chunks, 1));

        return new WP_REST_Response([
            'chunk_content' => $chunk_content,
            'chunk_index'   => $chunk_index,
            'total_chunks'  => $total_chunks,
            'total_length'  => $total_length,
            'max_chunks'    => $total_chunks,
            'log'           => $log,
        ], 200);
    }
}
