<?php
/**
 * La guida per collegare il proprio assistente, dentro l'area utente.
 *
 * PERCHE' NON BASTA QUELLA CHE C'E' GIA'. La pagina guida del plugin sta in
 * amministrazione, e descrive tutte le rotte: comprese quelle che a chi
 * pubblica sono vietate. Consegnarla cosi' com'e' significherebbe spiegare a
 * qualcuno come usare funzioni che gli risponderanno 403.
 *
 * COME SI TIENE ALLINEATA. L'elenco delle schede e lo schema da incollare in
 * ChatGPT non sono scritti qui a mano: nascono da WGA_Ambito::rotte_consentite(),
 * lo stesso elenco che la guardia usa per far passare o fermare le richieste.
 * Se domani si apre o si chiude una rotta, la guida cambia da sola - e non puo'
 * finire a descrivere un permesso che non esiste piu'.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WGA_Guida')) :

class WGA_Guida {

  const ENDPOINT = 'guida-gpt';

  public function init() {
    add_action('init', function () { add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES); });
    add_filter('query_vars', function ($v) { $v[] = self::ENDPOINT; return $v; });
    add_filter('woocommerce_account_menu_items', [__CLASS__, 'voce'], 27);
    add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'pagina']);
    // Sta accanto alle chiavi: si legge la guida e si va a generare il token
    // nella riga sotto, senza cercarla altrove.
    add_filter('www_pannello_sezioni', function ($s) {
      if (isset($s['api'])) $s['api']['voci'][] = self::ENDPOINT;
      else $s['api'] = ['titolo' => 'Chiavi API', 'ordine' => 61, 'voci' => [self::ENDPOINT]];
      return $s;
    }, 11);
  }

  /** Cosa fa ogni rotta, detto a chi pubblica e non a chi programma. */
  public static function schede(): array {
    return (array) apply_filters('wga_schede_guida', [
      'manage-post' => [
        'titolo' => 'Scrivere e modificare i tuoi articoli',
        'metodo' => 'POST',
        'cosa'   => 'Crea una bozza, la aggiorna, la pubblica. Vale solo sui contenuti di cui sei autore.',
      ],
      'get-posts' => [
        'titolo' => 'Rileggere i tuoi contenuti',
        'metodo' => 'GET',
        'cosa'   => 'Elenca i tuoi articoli, per farteli riassumere o riprendere.',
      ],
      'get-post' => [
        'titolo' => 'Aprire un tuo contenuto',
        'metodo' => 'GET',
        'cosa'   => 'Legge un singolo articolo tuo, testo compreso.',
      ],
      'manage-media' => [
        'titolo' => 'Immagini',
        'metodo' => 'POST',
        'cosa'   => 'Carica un\'immagine nella tua libreria e la mette in evidenza sull\'articolo.',
      ],
      'get-media' => [
        'titolo' => 'Le tue immagini',
        'metodo' => 'GET',
        'cosa'   => 'Elenca quello che hai gia\' caricato.',
      ],
      'manage-category' => [
        'titolo' => 'Categorie',
        'metodo' => 'POST',
        'cosa'   => 'Crea o assegna le categorie ai tuoi articoli.',
      ],
      'manage-tag' => [
        'titolo' => 'Etichette',
        'metodo' => 'POST',
        'cosa'   => 'Come sopra, per i tag.',
      ],
      'get-categories' => ['titolo' => 'Elenco categorie', 'metodo' => 'GET', 'cosa' => 'Per sapere quali esistono prima di assegnarle.'],
      'get-tags'       => ['titolo' => 'Elenco etichette', 'metodo' => 'GET', 'cosa' => 'Idem per i tag.'],
      'manage-share-post-on-social-network' => [
        'titolo' => 'Condividere sui social',
        'metodo' => 'POST',
        'cosa'   => 'Pubblica un tuo articolo sugli account che hai collegato nella sezione Social. Ogni condivisione scala dal tuo credito.',
      ],
      'manage-rankmath' => [
        'titolo' => 'Titolo e descrizione per i motori di ricerca',
        'metodo' => 'POST',
        'cosa'   => 'Imposta i campi SEO dei tuoi articoli.',
      ],
      'manage-creator-page' => [
        'titolo' => 'La tua pagina da creatore',
        'metodo' => 'POST',
        'cosa'   => 'Compone la tua pagina pubblica con gli shortcode disponibili. L\'azione "guida" ti spiega quali sono e come si combinano.',
      ],
      'placeholders' => [
        'titolo' => 'I tuoi segnaposto',
        'metodo' => 'GET e POST',
        'cosa'   => 'Testi ricorrenti tuoi (recapiti, firme) che l\'assistente sostituisce da solo. Nessuno vede i tuoi.',
      ],
      'manage-n8n' => [
        'titolo' => 'Le tue automazioni n8n',
        'metodo' => 'POST',
        'cosa'   => 'Elenca e fa partire i flussi del tuo n8n. Usa l\'indirizzo e la chiave che hai messo in Chiavi API: girano sul tuo, non sul nostro.',
      ],
      'humanizer' => [
        'titolo' => 'Riscrittura del testo',
        'metodo' => 'POST',
        'cosa'   => 'Riscrive un testo in forma piu\' naturale. Richiede la tua chiave HIX nella pagina Chiavi API.',
      ],
    ]);
  }

  /** Solo le schede delle rotte che la guardia lascia davvero passare. */
  public static function schede_attive(): array {
    if (!class_exists('WGA_Ambito')) return [];
    $aperte = WGA_Ambito::rotte_consentite();
    $fuori = [];
    foreach (self::schede() as $rotta => $s) {
      if (in_array($rotta, $aperte, true)) $fuori[$rotta] = $s;
    }
    return $fuori;
  }

  // ------------------------------------------------------------- lo schema

  /**
   * Lo schema da incollare in ChatGPT, ridotto alle rotte aperte.
   *
   * Si resta su OpenAPI 3.1 perche' e' quello che le Azioni dei GPT
   * accettano; il codice utente compare come parametro perche' e' li' che il
   * plugin lo cerca, mentre il token viaggia nell'intestazione.
   */
  public static function schema(): string {
    $base = rtrim(get_site_url(), '/');
    $percorsi = [];

    foreach (self::schede_attive() as $rotta => $s) {
      $voce = [];
      $comuni = [[
        'name' => 'user_code',
        'in' => 'query',
        'required' => true,
        'schema' => ['type' => 'string'],
        'description' => 'Il codice utente del giorno, che ricevi su Telegram.',
      ]];

      if (strpos($s['metodo'], 'GET') !== false) {
        $voce['get'] = [
          'operationId' => 'get_' . str_replace('-', '_', $rotta),
          'summary' => $s['titolo'],
          'description' => $s['cosa'],
          'parameters' => $comuni,
          'responses' => ['200' => ['description' => 'Esito']],
        ];
      }
      if (strpos($s['metodo'], 'POST') !== false) {
        $voce['post'] = [
          'operationId' => 'post_' . str_replace('-', '_', $rotta),
          'summary' => $s['titolo'],
          'description' => $s['cosa'],
          'requestBody' => [
            'required' => true,
            'content' => ['application/json' => ['schema' => [
              'type' => 'object',
              'properties' => ['user_code' => ['type' => 'string', 'description' => 'Il codice utente del giorno.']],
              'required' => ['user_code'],
              'additionalProperties' => true,
            ]]],
          ],
          'responses' => ['200' => ['description' => 'Esito']],
        ];
      }
      $percorsi['/wp-json/wp-gpt/v1/' . $rotta] = $voce;
    }

    $schema = [
      'openapi' => '3.1.0',
      'info' => [
        'title' => 'Le tue funzioni su ' . wp_specialchars_decode(get_bloginfo('name')),
        'description' => 'Funzioni disponibili al tuo account. Operano solo sui tuoi contenuti.',
        'version' => '1.0.0',
      ],
      'servers' => [['url' => $base]],
      'paths' => $percorsi,
      'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
      'security' => [['bearerAuth' => new stdClass()]],
    ];

    return wp_json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  // ------------------------------------------------------------------ pagina

  public static function voce($voci) {
    if (!class_exists('WGA_Credenziali') || !WGA_Credenziali::puo(get_current_user_id())) return $voci;
    $nuove = [];
    foreach ($voci as $k => $v) {
      if ('customer-logout' === $k) $nuove[self::ENDPOINT] = 'Guida assistente';
      $nuove[$k] = $v;
    }
    if (!isset($nuove[self::ENDPOINT])) $nuove[self::ENDPOINT] = 'Guida assistente';
    return $nuove;
  }

  public static function pagina() {
    $uid = get_current_user_id();
    if (!class_exists('WGA_Credenziali') || !WGA_Credenziali::puo($uid)) {
      echo '<p>Questa pagina e&rsquo; per chi pubblica.</p>';
      return;
    }

    $schede = self::schede_attive();
    $ha_token = WGA_Credenziali::ha_token($uid);

    echo '<h3>Collega il tuo assistente</h3>';
    echo '<p>Puoi far scrivere e pubblicare i tuoi contenuti a un assistente di ChatGPT. '
       . 'Lavora <strong>solo sulle tue cose</strong> e usa <strong>le tue chiavi</strong>: '
       . 'quello che consuma lo paghi tu al servizio, non passa dal portale.</p>';

    echo '<ol>';
    echo '<li>Vai in <a href="' . esc_url(wc_get_account_endpoint_url('chiavi-api')) . '">Chiavi API</a> '
       . 'e genera il token' . ($ha_token ? ' (ne hai gia&rsquo; uno)' : '') . '. Nella stessa pagina metti le tue chiavi dei servizi.</li>';
    echo '<li>In ChatGPT crea un GPT, apri <em>Configura &rarr; Azioni</em> e incolla lo schema qui sotto.</li>';
    echo '<li>Come autenticazione scegli <em>API Key &rarr; Bearer</em> e incolla il token.</li>';
    echo '<li>Il <strong>codice utente</strong> cambia ogni giorno e ti arriva su Telegram: vallo a prendere li&rsquo; e dallo all&rsquo;assistente quando te lo chiede.</li>';
    echo '</ol>';

    // Chi ha un solo posto libero per le intestazioni deve sapere che non e'
    // costretto a usarne due: il codice sta benissimo nel corpo.
    $intestazione = class_exists('WGA_Ambito') ? WGA_Ambito::nome_intestazione() : 'X-User-Code';
    echo '<p style="background:#f6f7f7;border-left:3px solid #999;padding:10px 14px">'
       . '<strong>Dove mettere il codice.</strong> Basta un posto solo: dentro i dati della richiesta, '
       . 'come campo <code>user_code</code> &mdash; ed &egrave; quello che fa lo schema qui sotto. '
       . 'In alternativa puoi mandarlo nell&rsquo;intestazione <code>' . esc_html($intestazione) . '</code>, '
       . 'ma non serve farlo in tutti e due i modi: se il tuo sistema permette una sola intestazione, '
       . 'usala per il token e lascia il codice nei dati.</p>';

    echo '<h4>Cosa puo&rsquo; fare per te</h4>';
    echo '<div class="wga-schede" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin:16px 0">';
    foreach ($schede as $rotta => $s) {
      echo '<div style="border:1px solid #e0e0e0;border-radius:8px;padding:14px">'
         . '<strong>' . esc_html($s['titolo']) . '</strong>'
         . '<p style="margin:6px 0 8px;font-size:.92em">' . esc_html($s['cosa']) . '</p>'
         . '<code style="font-size:.78em;color:#666">' . esc_html($s['metodo'] . ' /' . $rotta) . '</code>'
         . '</div>';
    }
    echo '</div>';

    echo '<p style="font-size:.92em;color:#666">Le altre funzioni del plugin restano al portale: '
       . 'con le tue chiavi rispondono che non sono disponibili.</p>';

    echo '<h4>Lo schema da incollare</h4>';
    echo '<textarea readonly rows="16" style="width:100%;font-family:monospace;font-size:.8em" '
       . 'onclick="this.select()">' . esc_textarea(self::schema()) . '</textarea>';
    echo '<p style="font-size:.9em;color:#666">Un clic dentro il riquadro lo seleziona tutto.</p>';

    self::campo_intestazione();
  }

  /**
   * Il nome dell'intestazione lo cambia solo chi amministra: vale per tutto il
   * sito, non per il singolo account.
   */
  private static function campo_intestazione() {
    if (!current_user_can('manage_options') || !class_exists('WGA_Ambito')) return;

    if (!empty($_POST['wga_intest_nonce']) && wp_verify_nonce($_POST['wga_intest_nonce'], 'wga_intest')) {
      $v = trim(preg_replace('/[^A-Za-z0-9\-_]/', '', (string) wp_unslash($_POST['wga_intest'] ?? '')));
      update_option(WGA_Ambito::INTESTAZIONE, $v !== '' ? $v : 'X-User-Code');
      echo '<div class="woocommerce-message"><p>Intestazione aggiornata.</p></div>';
    }

    echo '<h4>Nome dell&rsquo;intestazione (solo amministratori)</h4>';
    echo '<p style="font-size:.92em">Se un servizio impone un nome diverso, cambialo qui. '
       . 'Quella di sempre, <code>X-User-Code</code>, continua a funzionare comunque.</p>';
    echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
    wp_nonce_field('wga_intest', 'wga_intest_nonce');
    echo '<input type="text" name="wga_intest" value="' . esc_attr(WGA_Ambito::nome_intestazione()) . '" style="max-width:260px">';
    echo '<button type="submit" class="button">Salva</button>';
    echo '</form>';
  }
}

endif;
