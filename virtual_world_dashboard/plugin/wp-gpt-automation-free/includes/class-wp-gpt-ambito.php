<?php
/**
 * Chi fa la richiesta, e fin dove puo' arrivare.
 *
 * DUE AGGANCI, NON DECINE. Le rotte di questo plugin sono tante e scritte in
 * file diversi, ma tutte chiedono a WordPress due cose: chi sei e cosa puoi
 * fare. Ci si mette li' in mezzo:
 *
 *   - determine_current_user: se la richiesta porta un token e un codice validi,
 *     da quel momento la richiesta E' quella persona. Non un amministratore
 *     travestito: proprio lei, con i suoi ruoli e i suoi limiti.
 *
 *   - map_meta_cap: la rete di sicurezza. Anche se una rotta dimenticasse di
 *     controllare i permessi, toccare un contenuto altrui viene negato qui.
 *
 * IL TOKEN DEL PORTALE RESTA COM'E'. Quello di adesso, unico e legato
 * all'amministratore, continua a funzionare: chi lo usa oggi non deve cambiare
 * niente. Le chiavi personali si aggiungono, non lo sostituiscono.
 */
if (!defined('ABSPATH')) exit;

class WGA_Ambito {

  private static $utente = 0;      // chi ha firmato questa richiesta, se qualcuno

  /**
   * Se chi ha firmato e' un amministratore, deciso UNA VOLTA al riconoscimento.
   *
   * Non si puo' chiedere con user_can() da dentro map_meta_cap: quella chiamata
   * fa scattare map_meta_cap, che richiama questo controllo, che richiama
   * user_can... e il processo muore per esaurimento dello stack, in silenzio.
   * Qui si guardano i ruoli, che sono un dato e non un permesso da calcolare.
   */
  private static $amministratore = false;

  public function init() {
    add_filter('determine_current_user', [__CLASS__, 'riconosci'], 20);
    add_filter('map_meta_cap', [__CLASS__, 'rete_di_sicurezza'], 10, 4);

    // Il validatore del plugin confronta il token con un'opzione. Quando la
    // richiesta e' firmata da una persona, gli si fa trovare il suo: cosi' le
    // rotte esistenti passano il controllo senza essere toccate.
    add_filter('pre_option_wp_gpt_api_token', [__CLASS__, 'token_di_turno']);

    // Stessa cosa per il codice utente: il validatore lo confronta con quello
    // del sito, e senza questa riga una chiave personale supera il controllo
    // del token e si ferma su quello del codice.
    add_filter('pre_option_wp_gpt_user_code', [__CLASS__, 'codice_di_turno']);

    // Gli elenchi tornano solo roba sua, anche dove la rotta non filtra.
    add_action('pre_get_posts', [__CLASS__, 'solo_suoi']);

    // Il cancello su ogni richiesta REST, prima che venga eseguita.
    // Prima delle autorizzazioni delle rotte, se no il codice arriverebbe tardi.
    add_filter('rest_pre_dispatch', [__CLASS__, 'codice_nei_parametri'], 5, 3);
    add_filter('rest_request_before_callbacks', [__CLASS__, 'guardia'], 10, 3);
  }

  // ------------------------------------------------------------ riconoscere

  private static function intestazione(string $nome): string {
    $k = 'HTTP_' . strtoupper(str_replace('-', '_', $nome));
    if (!empty($_SERVER[$k])) return trim((string) $_SERVER[$k]);
    if (function_exists('getallheaders')) {
      foreach ((array) getallheaders() as $n => $v) {
        if (strcasecmp($n, $nome) === 0) return trim((string) $v);
      }
    }
    return '';
  }

  /**
   * Come si chiama l'intestazione del codice utente.
   *
   * Si puo' cambiare perche' non tutti i sistemi che chiameranno queste rotte
   * permettono di scegliere il nome, e qualcuno ne consente una sola: chi si
   * trova in quel caso mette il codice nel corpo e l'intestazione non la usa.
   */
  const INTESTAZIONE = 'wga_intestazione_codice';

  public static function nome_intestazione(): string {
    $n = (string) apply_filters('wga_nome_intestazione_codice', get_option(self::INTESTAZIONE, 'X-User-Code'));
    $n = trim(preg_replace('/[^A-Za-z0-9\-_]/', '', $n));
    return $n !== '' ? $n : 'X-User-Code';
  }

  /**
   * Il codice utente, da dove capita che arrivi.
   *
   * L'ORDINE HA UN MOTIVO. Prima il corpo, perche' e' li' che lo mette chi
   * segue la guida e chi ha un solo posto libero per le intestazioni; poi
   * l'intestazione scelta; poi quella di sempre, per non spegnere di colpo le
   * integrazioni gia' in piedi. L'intestazione e' un di piu', non un obbligo:
   * basta uno dei tre.
   */
  public static function codice_dalla_richiesta(): string {
    // Nel corpo: il primo posto in cui guardare.
    $dal_corpo = self::dal_corpo();
    if ($dal_corpo !== '') return $dal_corpo;

    $scelta = self::nome_intestazione();
    $c = self::intestazione($scelta);
    if ($c !== '') return $c;

    if (strcasecmp($scelta, 'X-User-Code') !== 0) {
      $c = self::intestazione('X-User-Code');
      if ($c !== '') return $c;
    }
    return '';
  }

  /**
   * Il codice preso da quello che il chiamante ha spedito.
   *
   * Si legge a mano perche' qui siamo su determine_current_user: WordPress non
   * ha ancora costruito la richiesta REST, e un oggetto da cui chiedere il
   * parametro non esiste. Il risultato si tiene da parte, cosi' il corpo si
   * analizza una volta sola per richiesta.
   */
  private static function dal_corpo(): string {
    static $trovato = null;
    if ($trovato !== null) return $trovato;
    $trovato = '';

    // Si guardano $_GET e $_POST uno per uno invece di $_REQUEST: su alcuni
    // hosting request_order non comprende la G, e li' $_REQUEST non contiene i
    // parametri della query. E' successo davvero, e il sintomo era un 401 che
    // sembrava un token sbagliato mentre il token era giusto.
    foreach ([$_GET, $_POST] as $dove) {
      if (isset($dove['user_code']) && !is_array($dove['user_code'])) {
        $trovato = trim(sanitize_text_field(wp_unslash($dove['user_code'])));
        if ($trovato !== '') return $trovato;
      }
    }

    // Un corpo JSON non finisce in $_POST: va letto e interpretato.
    $grezzo = file_get_contents('php://input');
    if ($grezzo === false || $grezzo === '' || strlen($grezzo) > 2000000) return $trovato;

    $dati = json_decode($grezzo, true);
    if (is_array($dati) && isset($dati['user_code']) && !is_array($dati['user_code'])) {
      $trovato = trim(sanitize_text_field((string) $dati['user_code']));
    }
    return $trovato;
  }

  public static function credenziali(): array {
    $auth = self::intestazione('Authorization');
    $token = (stripos($auth, 'bearer ') === 0) ? trim(substr($auth, 7)) : '';
    return [$token, self::codice_dalla_richiesta()];
  }

  /**
   * Se il codice e' arrivato solo dall'intestazione, lo si mette fra i
   * parametri prima che le rotte decidano.
   *
   * Il controllo del plugin cerca "user_code" nei parametri: senza questo
   * passaggio, chi manda tutto nelle intestazioni supererebbe il nostro
   * riconoscimento e si fermerebbe subito dopo, con un messaggio che parla di
   * un codice che invece aveva mandato. Si aggiunge solo se manca: quello
   * scritto dal chiamante non si tocca.
   */
  public static function codice_nei_parametri($risultato, $server, $richiesta) {
    if (!self::$utente) return $risultato;
    if (!$richiesta instanceof WP_REST_Request) return $risultato;
    $gia = $richiesta->get_param('user_code');
    if (is_string($gia) && trim($gia) !== '') return $risultato;

    [, $codice] = self::credenziali();
    if ($codice !== '') $richiesta->set_param('user_code', $codice);
    return $risultato;
  }

  public static function riconosci($uid) {
    if ($uid) return $uid;                       // gia' riconosciuto: non ci si mette in mezzo
    if (!class_exists('WGA_Credenziali') || !WGA_Credenziali::disponibile()) return $uid;

    [$token, $codice] = self::credenziali();
    if ($token === '' || $codice === '') return $uid;

    $trovato = WGA_Credenziali::utente_da_token($token);
    if (!$trovato) return $uid;

    // Il token da solo non basta: senza il codice del giorno, una chiave
    // sfuggita resterebbe buona per sempre.
    if (!WGA_Credenziali::codice_valido($trovato, $codice)) {
      self::annota($trovato, 'codice scaduto o sbagliato');
      return $uid;
    }

    self::$utente = $trovato;

    // I ruoli, non i permessi: chiedere un permesso qui dentro innescherebbe
    // la ricorsione descritta sopra.
    $u = get_userdata($trovato);
    $ruoli = $u ? (array) $u->roles : [];
    self::$amministratore = in_array('administrator', $ruoli, true);

    self::annota($trovato, 'riconosciuto');
    return $trovato;
  }

  public static function utente(): int { return self::$utente; }

  /** Sta lavorando per conto di una persona, non con la chiave della casa. */
  /**
   * Solo per le prove, e solo da riga di comando.
   *
   * Serve a verificare i limiti senza dover coniare credenziali vere. Da web
   * non fa niente: un metodo pubblico che decide chi sei, raggiungibile da una
   * richiesta, sarebbe il buco piu' grande di tutti quelli che questo file
   * esiste per chiudere.
   */
  public static function _prova_utente(int $uid) {
    if (!defined('WP_CLI') || !WP_CLI) return;
    self::$utente = $uid;
    $u = $uid ? get_userdata($uid) : null;
    self::$amministratore = $u ? in_array('administrator', (array) $u->roles, true) : false;
  }

  public static function personale(): bool {
    return self::$utente > 0 && !self::$amministratore;
  }

  public static function token_di_turno($valore) {
    if (!self::$utente) return $valore;
    [$token] = self::credenziali();
    return $token !== '' ? $token : $valore;
  }

  public static function codice_di_turno($valore) {
    if (!self::$utente) return $valore;
    [, $codice] = self::credenziali();
    return $codice !== '' ? $codice : $valore;
  }

  // --------------------------------------------------------------- i limiti

  /**
   * Rete di sicurezza sui permessi.
   *
   * Non sostituisce i controlli delle rotte: li chiude. Se la richiesta e'
   * firmata da una persona, tutto cio' che riguarda un contenuto non suo viene
   * negato, qualunque cosa quella rotta creda di poter fare.
   */
  public static function rete_di_sicurezza($caps, $cap, $user_id, $args) {
    if (!self::personale() || (int) $user_id !== self::$utente) return $caps;

    $sui_contenuti = ['edit_post', 'delete_post', 'publish_post', 'edit_page', 'delete_page',
                      'edit_product', 'delete_product', 'read_post'];
    if (in_array($cap, $sui_contenuti, true) && !empty($args[0])) {
      $pid = (int) $args[0];
      $autore = (int) get_post_field('post_author', $pid);
      if ($autore && $autore !== self::$utente) return ['do_not_allow'];

      // Sulla roba sua, si': altrimenti la chiave non servirebbe a niente.
      //
      // I creatori hanno ruolo "cliente", che di suo non permette di modificare
      // alcun contenuto: pubblicano dai nostri moduli, che controllano la
      // proprieta' per conto loro. Senza questa riga la chiave personale
      // riuscirebbe solo a leggere, e la funzione sarebbe un guscio.
      // Si concede il minimo indispensabile - "read", che ha chiunque abbia un
      // conto - e solo dopo aver accertato che il contenuto e' suo.
      if ($autore === self::$utente) return ['read'];
    }

    // Le chiavi di casa non si prestano: chi firma come persona non amministra
    // il sito, non tocca utenti e non installa niente.
    $mai = ['manage_options', 'edit_users', 'delete_users', 'promote_users', 'install_plugins',
            'activate_plugins', 'edit_plugins', 'edit_themes', 'switch_themes', 'update_core',
            'export', 'import', 'edit_others_posts', 'delete_others_posts',
            'edit_others_pages', 'edit_others_products', 'manage_woocommerce'];
    if (in_array($cap, $mai, true)) return ['do_not_allow'];

    return $caps;
  }

  /** Gli elenchi: solo roba sua. */
  public static function solo_suoi($q) {
    if (!self::personale()) return;
    if (!defined('REST_REQUEST') || !REST_REQUEST) return;
    if (!$q instanceof WP_Query || $q->get('author')) return;
    $q->set('author', self::$utente);
  }


  // ------------------------------------------------------- la guardia vera

  /**
   * Il cancello: una richiesta firmata da una persona passa solo se la rotta
   * le e' consentita e se tocca roba sua.
   *
   * PERCHE' QUI E NON NEI CONTROLLI DELLE ROTTE. Nel plugin ci sono decine di
   * rotte, e tredici file su quattordici che modificano contenuti chiamano
   * wp_update_post o scrivono in SQL senza chiedere permessi a nessuno: quelle
   * funzioni eseguono e basta. Una rete di sicurezza sui permessi, li', non
   * scatta mai. Questo aggancio invece e' il punto da cui WordPress fa passare
   * OGNI richiesta REST prima di eseguirla - comprese quelle che verranno
   * aggiunte domani.
   *
   * SI NEGA PER PARTENZA. Le rotte consentite sono un elenco chiuso: tutto il
   * resto e' vietato a chi firma con chiavi personali. E' l'unico verso giusto
   * - se ci si dimentica di qualcosa, il risultato e' una funzione che non va,
   * non un creatore che cancella i contenuti di un altro.
   */
  public static function guardia($risposta, $handler, $richiesta) {
    if (!self::personale()) return $risposta;
    if (is_wp_error($risposta)) return $risposta;
    if (!$richiesta instanceof WP_REST_Request) return $risposta;

    $rotta = (string) $richiesta->get_route();

    if (!self::rotta_consentita($rotta)) {
      self::annota(self::$utente, 'rotta non consentita: ' . $rotta);
      return new WP_Error('wga_rotta_vietata',
        'Questa funzione non e\' disponibile con le chiavi personali.',
        ['status' => 403]);
    }

    $altrui = self::contenuto_altrui($richiesta);
    if ($altrui) {
      self::annota(self::$utente, 'contenuto altrui #' . $altrui . ' su ' . $rotta);
      return new WP_Error('wga_non_tuo',
        'Puoi operare solo sui tuoi contenuti.', ['status' => 403]);
    }

    return $risposta;
  }

  /**
   * Le rotte aperte a chi firma come persona.
   *
   * Si dichiarano per pezzo di indirizzo. L'elenco si allarga con il filtro
   * wga_rotte_consentite, cosi' chi conosce il prodotto puo' aggiungerne senza
   * toccare questo file.
   */
  public static function rotte_consentite(): array {
    return (array) apply_filters('wga_rotte_consentite', [
      // I NOMI SONO QUELLI VERI DELLE ROTTE. Prima qui c'erano nomi di comodo
      // - manage-post, manage-media, humanizer - che non corrispondevano a
      // nessuna rotta registrata: la guardia non li faceva passare perche' non
      // esistevano, e un creatore non riusciva a scrivere un articolo. Se si
      // aggiunge una voce, va confrontata con /wp-gpt/v1/tools-manifest.

      // scrivere e rileggere i propri contenuti
      'create-post',
      'create-post-with-user-code',
      'update-post',
      'manage-articles-with-user-code',
      'get-post',
      'search-posts',
      'read-post-in-chunks',
      'append-content',
      'replace-text',
      'replace-html',

      // immagini e video dentro i propri articoli
      'insert-image',
      'insert-embed-video',
      'extract-image-links',

      // categorie
      'get-categories',

      // presentazione ai motori di ricerca
      'manage-rank-math-setting',
      'seo-optimize',
      'seo/title',
      'seo/meta-description',
      'seo/slug',
      'seo/keywords',

      // riscrittura, con la chiave HIX della persona
      'humanize-text',

      // social, pagina da creatore, segnaposto, automazioni
      'manage-share-post-on-social-network',
      'manage-creator-page',
      'placeholders',
      'manage-n8n',            // le automazioni, ma sul n8n suo: vedi WGA_Chiavi
      'tools-manifest',        // di servizio: dice cosa puo' fare chi bussa

      // LA CASA E LA MEMORIA, CON TUTTI E DUE I NOMI DELLA ROTTA.
      //
      // Erano assenti, e con le chiavi personali rispondevano 403 - "questa
      // funzione non e' disponibile con le chiavi personali" - mentre col
      // token del portale passavano. La casa si comandava solo da
      // amministratore, e /memory/goal, che nasce apposta per il codice utente
      // (la sua guardia e' validate_request_with_user_code), era
      // irraggiungibile proprio a chi era fatto per usarla. La rotta della
      // memoria e' la stessa nelle due edizioni, e qui lo diventa anche il
      // permesso.
      //
      // I NOMI SONO DUE PERCHE' LA ROTTA E' STATA RINOMINATA e i siti
      // aggiornano quando aggiornano: qui si confronta per PEZZO di indirizzo,
      // quindi autorizzare il nome nuovo su un sito che registra ancora quello
      // vecchio non autorizza niente - il permesso c'e' scritto e non tocca
      // nessuna rotta. E' un errore che non da' segnale: nessun avviso, solo
      // un 403 che continua ad arrivare. Si tengono entrambi finche' in giro
      // esistono entrambi.
      'manage-homeassistant-dashboard-and-entities',            // dopo la rinomina
      'manage-casahomeassistant-domoticandashboard-videocam',   // il nome storico
      'manage-casa-stanze-dispositivi-e-pagine',
      'memory/goal',

      // NON si aggiunge 'memory/admin' e nemmeno le sue delete-goals /
      // delete-steps: quelle sono le API della backend. Vogliono un cookie di
      // sessione, il nonce e manage_options, cioe' i pulsanti del pannello.
      // Con un token non si possono chiamare in nessun caso, e metterle qui
      // non le renderebbe utilizzabili - le farebbe soltanto comparire nel
      // manifesto, promettendo due attrezzi che rifiutano sempre. Il confronto
      // per pezzo di indirizzo aiuta: 'memory/goal' non sfiora
      // '/memory/admin', ed e' voluto.
    ]);
  }

  /**
   * Le rotte dei servizi esterni: aperte solo a chi ha messo la SUA chiave.
   *
   * Senza questo, un creatore userebbe i servizi a pagamento del portale
   * semplicemente chiamandoli. Con la sua chiave invece paga lui, ed e' giusto
   * che possa farlo.
   */
  public static function rotte_con_chiave(): array {
    return (array) apply_filters('wga_rotte_con_chiave', [
      'serpapi-keyword-analysis' => 'wp_gpt_serpapi_key',
      'serpapi-serp-analysis' => 'wp_gpt_serpapi_key',
      'search-youtube-video' => 'wp_gpt_youtube_api_key',
      'youtube-transcription' => 'wp_gpt_youtube_transcription_api_key',
      'humanize-text' => 'wp_gpt_hix_api_key',
      'send-email' => 'wp_gpt_email_server',
    ]);
  }

  /**
   * Chiedere dal di fuori se una rotta passerebbe.
   *
   * Serve al manifesto: l'elenco dei tool non deve somigliare a quello che la
   * guardia lascia passare, deve essere *lo stesso*. Chiedendolo alla guardia
   * invece di ricostruirlo, i due non possono divergere.
   */
  public static function consentita(string $rotta): bool {
    return self::rotta_consentita($rotta);
  }

  private static function rotta_consentita(string $rotta): bool {
    // Fuori dal nostro spazio non ci si mette in mezzo: le rotte di WordPress
    // e degli altri plugin hanno i loro controlli.
    if (strpos($rotta, '/wp-gpt/') === false) return true;

    foreach (self::rotte_consentite() as $pezzo) {
      if ($pezzo !== '' && strpos($rotta, $pezzo) !== false) return true;
    }

    foreach (self::rotte_con_chiave() as $pezzo => $chiave) {
      if (strpos($rotta, $pezzo) === false) continue;
      if (class_exists('WGA_Chiavi') && WGA_Chiavi::ha(self::$utente, $chiave)) return true;
      return false;   // la rotta esiste per lui, ma senza la sua chiave no
    }

    return false;
  }

  /**
   * Il primo contenuto citato nella richiesta che non e' suo, se c'e'.
   *
   * Si guardano solo i parametri che indicano davvero un contenuto: "id" da
   * solo non basta, perche' in queste rotte puo' essere un identificativo di
   * rete sociale o di categoria, e negarlo bloccherebbe cose legittime.
   */
  private static function contenuto_altrui(WP_REST_Request $richiesta) {
    $campi = ['post_id', 'product_id', 'page_id', 'parent_post_id', 'post_ids', 'product_ids'];

    // "id" da solo di solito non dice niente: in queste rotte puo' essere una
    // categoria o una rete sociale, e negarlo bloccherebbe cose legittime. Ma
    // quando l'indirizzo parla di un prodotto, di un ordine o di un articolo,
    // allora "id" e' proprio quel contenuto - ed e' il modo con cui si
    // arriverebbe alla roba di un altro senza passare da post_id.
    $rotta = (string) $richiesta->get_route();
    if (preg_match('/(product|order|post|page)/i', $rotta)) $campi[] = 'id';

    $params = $richiesta->get_params();

    foreach ($params as $nome => $valore) {
      $interessa = in_array($nome, $campi, true) || substr($nome, -8) === '_post_id';
      if (!$interessa) continue;

      foreach ((array) $valore as $v) {
        $pid = (int) $v;
        if ($pid <= 0) continue;

        // Un ordine non ha un autore: ha un intestatario. Chiedergli
        // post_author darebbe zero, cioe' "va bene", ed e' proprio l'ordine di
        // un altro che non deve passare.
        if (function_exists('wc_get_order') && get_post_type($pid) === 'shop_order') {
          $ordine = wc_get_order($pid);
          if ($ordine && (int) $ordine->get_customer_id() !== self::$utente) return $pid;
          continue;
        }

        $autore = (int) get_post_field('post_author', $pid);
        if ($autore && $autore !== self::$utente) return $pid;
      }
    }
    return 0;
  }

  // ---------------------------------------------------------------- registro

  /**
   * Un rigo per ogni tentativo.
   *
   * Serve il giorno in cui qualcuno chiede "chi ha cancellato quel prodotto":
   * senza registro la risposta e' un'alzata di spalle.
   */
  private static function annota(int $uid, string $esito) {
    $r = get_option('wga_registro', []);
    if (!is_array($r)) $r = [];
    array_unshift($r, [
      'quando' => current_time('mysql'),
      'utente' => $uid,
      'esito' => $esito,
      'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : '',
      'rotta' => isset($_SERVER['REQUEST_URI'])
        ? sanitize_text_field(substr((string) $_SERVER['REQUEST_URI'], 0, 120)) : '',
    ]);
    update_option('wga_registro', array_slice($r, 0, 200), false);
  }
}
