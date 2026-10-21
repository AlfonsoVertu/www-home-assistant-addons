<?php
/**
 * Una rotta sola per leggere una pagina web o un profilo Twitter.
 *
 * PERCHE' INSIEME. Erano due rotte monouso, link-scraping e scrape-twitter, che
 * fanno la stessa cosa da due parti diverse: prendono un indirizzo e ne tirano
 * fuori qualcosa. Tenerle separate obbligava chi le usa a sapere in anticipo
 * quale delle due gli serviva, che e' proprio il sapere che un assistente non
 * ha quando comincia.
 *
 * PERCHE' NON RISCRIVO LA LOGICA. Le due funzioni fanno il loro lavoro e sono
 * in produzione su siti veri: riscriverle per cambiare il modo in cui si
 * chiamano vorrebbe dire rischiare un guasto vero per un miglioramento di
 * forma. Qui si aggiunge una porta nuova nel nostro formato e si delega a loro.
 *
 * L'ANALISI DIVENTA UNA ACTION. La vecchia rotta sceglieva con un parametro suo,
 * analysis_type, che nessuno poteva indovinare: nove valori dichiarati solo
 * dentro uno switch. Adesso sono action, quindi si scoprono con
 * documentazione_<nome> come tutte le altre.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WGA_Scraping_Pagina_E_Twitter')) :

class WGA_Scraping_Pagina_E_Twitter {

  const ROTTA = '/webpage-link-and-twitter-scraping';

  /** action -> il valore che la vecchia funzione si aspetta in analysis_type. */
  const ANALISI = [
    'get-div-ids'             => 'get_div_ids',
    'get-div-classes'         => 'get_div_classes',
    'find-expression-in-div'  => 'find_expression_in_div',
    'get-images'              => 'get_images',
    'get-meta-tags'           => 'get_meta_tags',
    'get-headers'             => 'get_headers',
    'extract-emails'          => 'extract_emails',
    'get-text-chunks'         => 'get_text_chunks',
    'get-html-chunks'         => 'get_html_chunks',
  ];

  const AZIONE_TWITTER = 'scrape-twitter-profile-or-tweet';

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'registra']);
  }

  public static function registra() {
    register_rest_route('wp-gpt/v1', self::ROTTA, [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'rispondi'],
      // la stessa guardia delle rotte che sostituisce: chi passava di la'
      // passa di qua con le stesse credenziali
      'permission_callback' => ['WP_GPT_Automation_Pro\WP_GPT_API',
                                'validate_request_with_user_code_and_bearer'],
    ]);
  }

  public static function rispondi(WP_REST_Request $richiesta) {
    $azione = strtolower(trim((string) $richiesta->get_param('action')));

    if (isset(self::ANALISI[$azione])) {
      // si rimette il parametro che la vecchia funzione cerca, e si delega
      $richiesta->set_param('analysis_type', self::ANALISI[$azione]);
      return \WP_GPT_Automation_Pro\WP_GPT_API::link_scraping($richiesta);
    }

    if (self::AZIONE_TWITTER === $azione) {
      return \WP_GPT_Automation_Pro\WP_GPT_API::scrape_twitter_data($richiesta);
    }

    // NON UN ERRORE SECCO: la porta insegna com'e' fatta. E' la stessa
    // spiegazione che ricevono tutte le altre rotte quando le si sbaglia.
    if (class_exists('WGA_Azioni')) {
      $spiegazione = WGA_Azioni::spiega(trim(self::ROTTA, '/'),
                                        ['action' => $azione]);
      if ($spiegazione) return new WP_REST_Response($spiegazione, 400);
    }
    return new WP_REST_Response([
      'errore' => 'Parametro action mancante o non riconosciuto.',
      'action_disponibili' => array_merge(array_keys(self::ANALISI),
                                          [self::AZIONE_TWITTER]),
    ], 400);
  }
}

endif;
