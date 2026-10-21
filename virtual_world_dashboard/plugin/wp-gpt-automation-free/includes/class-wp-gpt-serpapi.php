<?php
/**
 * Classe per la gestione delle rotte SerpAPI e delle impostazioni per l'analisi delle keyword.
 */

namespace WP_GPT_Automation_Pro;

use WP_REST_Request;
use WP_Error;
use WP_REST_Response;

class WP_GPT_Serpapi {

    /**
     * Inizializzazione della classe.
     */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    /**
     * Registra le rotte REST API.
     */
    public static function register_rest_routes() {
        // TOLTE serpapi-serp-analysis e serpapi-keyword-analysis: sono gia'
        // action di gather-information, con lo stesso identico nome e - per
        // serp-analysis - gli stessi cinque parametri. Come youtube: la
        // migrazione era fatta, mancava di chiudere la porta vecchia.
        // Le funzioni di questo file restano, inerti: toglierle e' un passo
        // a se', da fare quando si e' sicuri che nessuno le carichi altrove.
            // Seconda rotta per l'analisi SERP
    }

    /**
     * Callback per la validazione della richiesta.
     */

     public static function get_serp_analysis($request) {
        $keyword = sanitize_text_field($request->get_param('keyword'));
        $hl = sanitize_text_field($request->get_param('hl', 'it'));
        $gl = sanitize_text_field($request->get_param('gl', 'it'));
        $chunk_index = (int) $request->get_param('chunk_index', 0);
        $chunk_size = (int) $request->get_param('chunk_size', 10);
        $serpapi_key = get_option('wp_gpt_serpapi_key');
    
        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Chiave API SerpAPI non configurata.'
            ], 400);
        }
    
        // Cache basata su keyword e chunk index
        $transient_key = 'wp_gpt_serpapi_serp_analysis_' . md5($keyword . $chunk_index);
        $cached_data = get_transient($transient_key);
    
        if ($cached_data !== false) {
            return new WP_REST_Response([
                'success' => true,
                'cached' => true,
                'data' => $cached_data
            ], 200);
        }
    
        $start = $chunk_index * $chunk_size;
        $url = "https://serpapi.com/search.json?q=" . urlencode($keyword) . 
               "&hl=" . $hl . 
               "&gl=" . $gl . 
               "&start=" . $start . 
               "&num=" . $chunk_size . 
               "&api_key=" . $serpapi_key;

        $response = wp_remote_get($url);
    
        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Errore nella chiamata a SerpAPI: ' . $response->get_error_message()
            ], 500);
        }
    
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
    
        if (isset($data['organic_results'])) {
            $chunked_results = array_slice($data['organic_results'], 0, $chunk_size);
            set_transient($transient_key, $chunked_results, 24 * HOUR_IN_SECONDS);
    
            return new WP_REST_Response([
                'success' => true,
                'cached' => false,
                'data' => $chunked_results
            ], 200);
        }
    
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Nessun risultato trovato.'
        ], 404);
    }

    
    public static function validate_request_serpapi(WP_REST_Request $request) {
        $license_check = WP_GPT_License_Check::verify();
        if (is_wp_error($license_check)) {
            return $license_check;
        }
        // Reindirizza alla validazione comune
        return \WP_GPT_Automation_Pro\WP_GPT_API::validate_request($request);
    }

    /**
     * Registra le opzioni nella pagina delle impostazioni.
     */
    public static function register_settings() {
        register_setting('wp_gpt_settings', 'wp_gpt_serpapi_key');

        add_settings_section(
            'wp_gpt_serpapi_section',
            __('Impostazioni SerpAPI', 'wp-gpt-automation-pro'),
            null,
            'wp_gpt_automation_pro'
        );

        add_settings_field(
            'wp_gpt_serpapi_key',
            __('Chiave API SerpAPI', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_serpapi_key_field'],
            'wp_gpt_automation_pro',
            'wp_gpt_serpapi_section'
        );
    }

    /**
     * Rende il campo di input per la chiave SerpAPI.
     */
    public static function render_serpapi_key_field() {
        $key = get_option('wp_gpt_serpapi_key');
        echo '<input type="text" name="wp_gpt_serpapi_key" value="' . esc_attr($key) . '" class="regular-text" />';
        $serpapi_link_open  = '<a href="' . esc_url('https://serpapi.com/') . '" target="_blank" rel="noopener noreferrer">';
        $serpapi_link_close = '</a>';
        $manage_link_open   = '<a href="' . esc_url('https://serpapi.com/manage-api-key') . '" target="_blank" rel="noopener noreferrer">';
        $manage_link_close  = '</a>';

        $description = sprintf(
            /* translators: 1: opening SerpAPI site link, 2: closing SerpAPI site link, 3: opening manage API key link, 4: closing manage API key link */
            __('Registrati su %1$sSerpAPI%2$s, completa la verifica dell\'account e poi visita la pagina %3$sManage API Key%4$s per recuperare la tua chiave API da incollare qui.', 'wp-gpt-automation-pro'),
            $serpapi_link_open,
            $serpapi_link_close,
            $manage_link_open,
            $manage_link_close
        );

        echo '<p class="description">' . wp_kses_post($description) . '</p>';
    }

    /**
     * Aggiunge un menu di amministrazione.
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'wp-gpt-automation-pro',
            __('Impostazioni SerpAPI', 'wp-gpt-automation-pro'),
            __('SerpAPI', 'wp-gpt-automation-pro'),
            'manage_options',
            'wp-gpt-serpapi-settings',
            [__CLASS__, 'render_admin_page']
        );
    }

    /**
     * Visualizza la pagina di impostazioni.
     */
    public static function render_admin_page() {
        echo '<div class="wrap wp-gpt-admin-page">';
        echo '<div class="wp-gpt-card">';
        echo '<div class="wp-gpt-card-header">';
        echo '<h1>' . esc_html__('Impostazioni SerpAPI', 'wp-gpt-automation-pro') . '</h1>';
        echo '<p>' . esc_html__('Configura la chiave API SerpAPI per abilitare le analisi delle keyword e della SERP direttamente dal tuo GPT.', 'wp-gpt-automation-pro') . '</p>';
        echo '</div>';
        echo '<div class="wp-gpt-card-body">';
        echo '<form method="post" action="options.php" class="wp-gpt-card-form">';
        settings_fields('wp_gpt_settings');
        do_settings_sections('wp_gpt_automation_pro');
        submit_button();
        echo '</form>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Callback per ottenere l'analisi delle keyword dalla SERP.
     */
    public static function get_keyword_analysis($request) {
        $keyword = sanitize_text_field($request->get_param('keyword'));
        $serpapi_key = get_option('wp_gpt_serpapi_key');

        if (empty($serpapi_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Chiave API SerpAPI non configurata.'
            ], 400);
        }

        $transient_key = 'wp_gpt_serpapi_analysis_' . md5($keyword);
        $cached_data = get_transient($transient_key);

        if ($cached_data !== false) {
            return new WP_REST_Response([
                'success' => true,
                'cached' => true,
                'data' => $cached_data
            ], 200);
        }

        $response = wp_remote_get("https://serpapi.com/search.json?q=" . urlencode($keyword) . "&location=Italy&hl=it&gl=it&api_key=" . $serpapi_key);

        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Errore nella chiamata a SerpAPI: ' . $response->get_error_message()
            ], 500);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        $analysis_data = self::analyze_serp_data($data);
        set_transient($transient_key, $analysis_data, 24 * HOUR_IN_SECONDS);

        return new WP_REST_Response([
            'success' => true,
            'cached' => false,
            'data' => $analysis_data
        ], 200);
    }

    /**
     * Analizza i dati della SERP per estrarre le informazioni richieste.
     */
    public static function analyze_serp_data($serp_data) {
        $results = $serp_data['organic_results'] ?? [];
        $word_frequency = [];
        $bigram_frequency = [];
        $trigram_frequency = [];
        $context_words = [];

        foreach ($results as $result) {
            $snippet = $result['snippet'] ?? '';
            $title = $result['title'] ?? '';
            $text_content = $snippet . ' ' . $title;

            self::update_word_frequency($text_content, $word_frequency);
            self::update_ngram_frequency($text_content, $bigram_frequency, 2);
            self::update_ngram_frequency($text_content, $trigram_frequency, 3);

            if (strpos($text_content, $serp_data['search_metadata']['query']) !== false) {
                $context_words = self::extract_context_words($text_content, $serp_data['search_metadata']['query']);
            }
        }

        return [
            'top_words' => array_slice($word_frequency, 0, 10, true),
            'top_bigrams' => array_slice($bigram_frequency, 0, 10, true),
            'top_trigrams' => array_slice($trigram_frequency, 0, 10, true),
            'context_words' => $context_words,
            'total_results' => count($results)
        ];
    }  
/**
 * Aggiorna la frequenza delle singole parole.
 */
private static function update_word_frequency($text, &$frequency) {
    $words = str_word_count($text, 1);
    foreach ($words as $word) {
        $word = strtolower($word);
        if (isset($frequency[$word])) {
            $frequency[$word]++;
        } else {
            $frequency[$word] = 1;
        }
    }
}

/**
 * Aggiorna la frequenza degli n-grammi (bigrammi, trigrammi, ecc.).
 */
private static function update_ngram_frequency($text, &$frequency, $n) {
    $words = str_word_count($text, 1);
    for ($i = 0; $i <= count($words) - $n; $i++) {
        $ngram = implode(' ', array_slice($words, $i, $n));
        if (isset($frequency[$ngram])) {
            $frequency[$ngram]++;
        } else {
            $frequency[$ngram] = 1;
        }
    }
}

/**
 * Estrae le parole di contesto basate su una keyword specifica.
 */
private static function extract_context_words($text, $keyword) {
    $pattern = '/\b\w+\s+\b' . preg_quote($keyword, '/') . '\b\s+\w+/i';
    preg_match_all($pattern, $text, $matches);
    return array_count_values(array_map('strtolower', $matches[0] ?? []));
}


}