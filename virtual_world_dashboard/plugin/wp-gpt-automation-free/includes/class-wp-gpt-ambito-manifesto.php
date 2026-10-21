<?php
/**
 * La rotta di servizio: quali funzioni ha questo sito, e come si usano.
 *
 * A COSA SERVE. Chi vuole pilotare questo sito da fuori - sowai, un GPT, un
 * altro portale - ha tre domande, e finora doveva indovinare le risposte:
 * "le credenziali che ho sono buone?", "quali funzioni mi danno?", "che
 * parametri vuole questa funzione?". La prima si scopriva provando una
 * chiamata a caso; le altre due leggendo un elenco scritto a mano da qualcuno,
 * mesi prima, altrove.
 *
 * PERCHE' UN ELENCO SCRITTO A MANO NON REGGE. Ne esistevano due, uno nel
 * plugin e uno dentro Odoo: coprivano 41 e 27 rotte su 95, e non erano
 * d'accordo fra loro. Non e' colpa di chi li ha scritti - una copia ferma
 * invecchia dal giorno dopo. Qui non si scrive niente: le rotte si chiedono a
 * WordPress, e i parametri di ognuna si leggono da come la rotta stessa si e'
 * dichiarata quando e' stata registrata. Se domani cambia un parametro, la
 * risposta cambia con lui.
 *
 * DUE DOMANDE, COME GLI EXTRA TOOL. "lista" da' l'elenco corto, "dettaglio"
 * apre un tool alla volta. E' lo stesso passo che sowai fa gia' con i suoi
 * extra tool, e serve a non rovesciare addosso a un assistente novantacinque
 * schede quando gliene serve una.
 *
 * L'ELENCO E' PERSONALE. A chi firma con le proprie chiavi si risponde con le
 * sole rotte che la guardia gli lascia passare: quindici, non novantacinque.
 * Cosi' nessuno si costruisce un assistente attorno a funzioni che gli
 * risponderebbero 403.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WGA_Manifesto')) :

class WGA_Manifesto {

  /** Rotte di servizio: non sono funzioni da dare a un assistente. */
  const SERVIZIO = ['verify-license', 'download-update', 'log-attempt', 'tools-manifest'];

  public function init() {
    add_action('rest_api_init', [__CLASS__, 'registra']);
  }

  public static function registra() {
    register_rest_route('wp-gpt/v1', '/tools-manifest', [
      'methods'  => ['GET', 'POST'],
      'callback' => [__CLASS__, 'rispondi'],
      // Lo stesso controllo di tutte le altre rotte: se le credenziali non
      // valgono qui non valgono da nessuna parte, ed e' proprio la risposta
      // che chi si collega sta cercando.
      'permission_callback' => ['WP_GPT_Automation_Pro\WP_GPT_API', 'validate_request_with_user_code'],
      'args' => [
        'action' => [
          'type' => 'string',
          'required' => false,
          'description' => 'lista-dei-tool-che-contengono-le-action (predefinito), '
                         . 'scopri-parametri-dei-tool, scopri-parametri-di-un-action-in-un-tool, '
                         . 'schema, stretta-di-mano.',
        ],
        'tool' => [
          'type' => 'string',
          'required' => false,
          'description' => 'Nome del tool, obbligatorio con scopri-parametri-dei-tool.',
        ],
        'nome_action' => [
          'type' => 'string',
          'required' => false,
          'description' => 'Action da aprire, con scopri-parametri-di-un-action-in-un-tool.',
        ],
        'cerca' => [
          'type' => 'string',
          'required' => false,
          'description' => 'Filtra l\'elenco: anche parziale, o con una lettera fuori posto.',
        ],
        'pagina' => ['type' => 'integer', 'required' => false, 'description' => 'Pagina, da 1.'],
        'per_pagina' => ['type' => 'integer', 'required' => false, 'description' => 'Quanti per pagina.'],
      ],
    ]);
  }

  public static function rispondi(WP_REST_Request $richiesta) {
    $azione = strtolower(trim((string) $richiesta->get_param('action'))) ?: 'lista';
    $tool = self::tool_visibili();

    // IL NOME NUOVO E IL VECCHIO PORTANO ALLO STESSO POSTO. "lista" e' quello
    // che chiamano tutti gli integratori di oggi: rinominarlo e basta li
    // romperebbe tutti nello stesso istante, per un nome piu' chiaro.
    if (in_array($azione, ['lista', 'lista-dei-tool-che-contengono-le-action'], true)
        && class_exists('WGA_Azioni')) {
      return new WP_REST_Response(array_merge(
        self::intestazione(),
        WGA_Azioni::elenco(
          (string) $richiesta->get_param('cerca'),
          max(1, (int) ($richiesta->get_param('pagina') ?: 1)),
          max(1, (int) ($richiesta->get_param('per_pagina') ?: 20))
        )
      ), 200);
    }

    // Le due porte per scendere: dal tool alle sue action, e da un'action ai
    // suoi parametri. Sono separate perche' sono due domande diverse, e chi fa
    // la prima non deve ricevere addosso anche la risposta alla seconda.
    if (in_array($azione, ['scopri-parametri-di-un-action-in-un-tool', 'dettaglio-action'], true)
        && class_exists('WGA_Azioni')) {
      $nome = (string) $richiesta->get_param('tool');
      $qual = (string) $richiesta->get_param('nome_action');
      if ('' === $nome || '' === $qual) {
        return new WP_REST_Response(['errore' => 'Servono tool e nome_action.',
          'come_si_esplora' => WGA_Azioni::BUSSOLA], 400);
      }
      if (!WGA_Azioni::consentito($nome)) {
        return new WP_REST_Response(['errore' => 'Questo tool esiste ma non e\' fra quelli '
          . 'concessi alle tue credenziali.', 'tool' => WGA_Azioni::nome_pulito($nome)], 403);
      }
      $def = WGA_Azioni::azione($nome, $qual);
      if (!$def) {
        return new WP_REST_Response(WGA_Azioni::spiega($nome, ['action' => $qual])
          ?: ['errore' => 'Tool o action sconosciuti.',
              'come_si_esplora' => WGA_Azioni::BUSSOLA], 404);
      }
      return new WP_REST_Response([
        'tool' => WGA_Azioni::nome_pulito($nome),
        'action' => $qual,
        'cosa_fa' => $def['descrizione'] ?? '',
        'parametri' => $def['parametri'] ?? [],
        'come_si_esplora' => WGA_Azioni::BUSSOLA,
      ], 200);
    }

    switch ($azione) {
      case 'scopri-parametri-dei-tool':
      case 'dettaglio':
        $nome = trim((string) $richiesta->get_param('tool'));
        if ('' === $nome) {
          return new WP_Error('tool_mancante', 'Indica il tool con il parametro "tool".', ['status' => 400]);
        }
        foreach ($tool as $t) {
          if ($t['nome'] !== $nome && $t['percorso'] !== $nome) continue;
          $scheda = self::dettaglio($t);
          // LE ACTION DAL REGISTRO, quando ci sono. L'introspezione da' il
          // guscio della rotta; qui dentro c'e' quello che il guscio non puo'
          // dire - le azioni e i parametri di ognuna.
          if (class_exists('WGA_Azioni')) {
            $reg = WGA_Azioni::tool($nome);
            if (!empty($reg['azioni'])) {
              $scheda['descrizione'] = WGA_Azioni::descrizione_corta(
                $reg['descrizione'] ?? ($scheda['descrizione'] ?? ''));
              $scheda['action'] = array_map(function ($a) {
                return $a['descrizione'] ?? '';
              }, $reg['azioni']);
              $scheda['parametri_per_lo_schema'] = WGA_Azioni::parametri_uniti($nome);
              $scheda['come_scendere'] = 'Per i parametri di UNA action chiedi '
                . 'action=scopri-parametri-di-un-action-in-un-tool con tool=' . $nome
                . ' e nome_action=<action>.';
            }
          }
          return new WP_REST_Response($scheda, 200);
        }
        // ESISTE MA NON TI E' CONCESSO va detto, perche' "non esiste" manda a
        // cercare un refuso che non c'e'.
        if (class_exists('WGA_Azioni') && WGA_Azioni::tool($nome)) {
          return new WP_Error('tool_vietato',
            'Questo tool esiste ma non e\' fra quelli concessi alle tue credenziali.',
            ['status' => 403]);
        }
        return new WP_Error('tool_sconosciuto',
          'Nessun tool con questo nome fra quelli disponibili alle tue credenziali.',
          ['status' => 404, 'disponibili' => wp_list_pluck($tool, 'nome')]);

      case 'istruzioni':
      case 'come-si-usa':
        // LE ISTRUZIONI VIVONO QUI, NON NELLE APP CHE CHIAMANO.
        //
        // Le stesse righe erano scritte a mano dentro l'app Android, nella
        // persona della chat di casa. Due copie della stessa cosa divergono
        // sempre: si aggiunge un'operazione al plugin e l'assistente sul
        // telefono non sa che esiste, perche' la sua copia e' ferma al
        // giorno in cui e' stata compilata. Chiedendole qui, chi collega un
        // sito le prende aggiornate e non deve ricompilare niente.
        // Il generatore lo carica solo la guida del pannello, quando disegna
        // lo step 4: da una rotta non e' mai in memoria. Si richiede qui.
        if (!class_exists('WP_GPT_Automation_Pro\Istruzioni_Personalizzate_GPTS')
            && defined('WP_GPT_PLUGIN_DIR')) {
          $f = WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-generate-instructions.php';
          if (file_exists($f)) require_once $f;
        }
        if (!class_exists('WP_GPT_Automation_Pro\Istruzioni_Personalizzate_GPTS')) {
          return new WP_Error('non_disponibile',
            'Il generatore di istruzioni non risulta installato su questo sito.',
            ['status' => 501]);
        }
        // Questo file e' globale, il generatore no: senza il nome per intero
        // PHP lo cerca nello spazio globale e non lo trova. E' lo stesso
        // inciampo della riga 99 del file principale.
        $generatore = new \WP_GPT_Automation_Pro\Istruzioni_Personalizzate_GPTS();
        return new WP_REST_Response(array_merge(self::intestazione(), [
          'istruzioni'  => $generatore->generate_instructions(),
          'a_cosa_serve' => 'Da dare al modello come istruzioni di sistema: '
                          . 'dice quali tool esistono su questo sito, come si '
                          . 'chiedono le schede e come si costruisce una dashboard.',
        ]), 200);

      case 'stretta-di-mano':
      case 'handshake':
        if (!class_exists('WGA_Sowai')) {
          return new WP_Error('non_disponibile', 'Stretta di mano non disponibile su questo sito.',
                              ['status' => 501]);
        }
        $parametri = $richiesta->get_json_params();
        if (!is_array($parametri)) $parametri = [];
        foreach (['odoo_url', 'odoo_token', 'odoo_user_code', 'riferimento'] as $x) {
          if (!isset($parametri[$x]) && null !== $richiesta->get_param($x)) {
            $parametri[$x] = $richiesta->get_param($x);
          }
        }
        $esito = WGA_Sowai::stringi($parametri);
        if (isset($esito['errore'])) {
          return new WP_Error('stretta_incompleta', $esito['errore'], ['status' => 400]);
        }
        return new WP_REST_Response(array_merge(self::intestazione(), $esito), 200);

      case 'schema':
        return new WP_REST_Response([
          'sito' => self::sito(),
          'casa' => self::casa(),
          'schema' => json_decode(self::schema($tool), true),
        ], 200);

      default:
        return new WP_REST_Response(array_merge(self::intestazione(), [
          'conteggio' => count($tool),
          'tool' => array_map(function ($t) {
            return [
              'nome' => $t['nome'],
              'metodi' => $t['metodi'],
              'percorso' => $t['percorso'],
              'titolo' => $t['titolo'],
            ];
          }, $tool),
          // La stretta di mano si offre qui, alla prima richiesta: e' il momento
          // in cui chi si collega ha in mano tutto quello che serve per farla.
          'stretta_di_mano' => class_exists('WGA_Sowai') ? [
            'come' => 'action=stretta-di-mano con odoo_url, odoo_token, odoo_user_code, riferimento',
            'a_cosa_serve' => 'Da quel momento questo sito ti avvisa da solo quando il codice '
                            . 'utente o il token cambiano, invece di lasciarti scoprire una porta chiusa.',
            'gia_fatta' => (bool) WGA_Sowai::accordo(
              (class_exists('WGA_Ambito') && WGA_Ambito::personale()) ? (int) WGA_Ambito::utente() : 0),
          ] : null,
          'social' => self::social(),
          'come_proseguire' => 'Chiedi action=scopri-parametri-dei-tool con tool=<nome> per parametri e uso. '
                             . 'action=schema restituisce l\'OpenAPI di tutti insieme.',
        ]), 200);
    }
  }


  /**
   * L'area da creatore di questo sito, come la vede chi sta chiedendo.
   *
   * PERCHE' LA DICHIARA IL SITO E NON L'APP. Ogni sito della rete chiama la sua
   * area in modo suo, e le versioni del plugin non sono le stesse dappertutto.
   * Se il nome e le funzioni stessero nell'app, aggiungere un sito alla rete
   * sarebbe una versione nuova dell'app da far aggiornare a tutti, e un sito
   * indietro mostrerebbe pulsanti che non funzionano. Dicendolo qui, l'app non
   * conosce nessun sito: disegna quello che le viene detto.
   *
   * SOLO PER UNA PERSONA. Le chiavi da amministratore sono quelle del PORTALE:
   * chi le usa amministra il sito, non ci pubblica come autore. Un diario, "i
   * miei post" e una bacheca intestati a una chiave d'amministrazione non
   * vogliono dire niente, perche' non c'e' nessun "mio". Quindi qui torna null,
   * e l'app non mostra niente.
   *
   * `puo` E' L'ELENCO DELLE PARTI ACCESE, e va tenuto onesto: dichiarare una
   * parte che questo sito non sa servire significa mandare l'utente contro una
   * rotta che risponde male - e sembrera' rotta l'app, non assente la funzione.
   */
  private static function social() {
    if (!class_exists('WGA_Ambito') || !WGA_Ambito::personale()) return null;
    $uid = (int) WGA_Ambito::utente();
    if ($uid <= 0) return null;

    $etichetta = trim((string) get_option('wga_social_etichetta', ''));
    if ('' === $etichetta) return null;   // non configurata: questo sito non ha area

    $u = get_userdata($uid);
    $modello = (string) get_option('wga_social_diario', '/creatore/%s');
    $diario = sprintf($modello, $u ? $u->user_nicename : '');

    // Le parti si accendono dalle opzioni: un sito puo' avere il diario e i
    // post e non ancora la bacheca, che e' la situazione di oggi.
    $puo = array();
    foreach (array('diario', 'post', 'bacheca') as $parte) {
      if (get_option('wga_social_' . $parte, $parte === 'bacheca' ? 0 : 1)) $puo[] = $parte;
    }

    // IL LOGO LO DA' IL SITO, come l'etichetta e per lo stesso motivo:
    // l'app non deve conoscere nessun sito. Si prende l'icona del sito -
    // quella che WordPress usa gia' per la scheda del browser - e in
    // mancanza il logo del tema. Se non c'e' ne' l'una ne' l'altro si
    // manda stringa vuota, e l'app disegna l'iniziale: meglio una lettera
    // che un riquadro rotto.
    $logo = function_exists('get_site_icon_url') ? (string) get_site_icon_url(192) : '';
    if ('' === $logo && function_exists('get_theme_mod')) {
      $lid = (int) get_theme_mod('custom_logo');
      if ($lid) {
        $src = wp_get_attachment_image_src($lid, 'medium');
        if (!empty($src[0])) $logo = (string) $src[0];
      }
    }

    return array(
      'attivo' => true,
      'etichetta' => $etichetta,
      'logo' => $logo,
      'diario' => $diario,
      'puo' => $puo,
      'strumenti' => array_values((array) apply_filters('wga_social_strumenti', array(
        'manage-articles-with-user-code',
        'manage-creator-page',
        'manage-share-post-on-social-network',
        'placeholders',
      ))),
      'servizi' => self::servizi($uid),
    );
  }

  /**
   * I servizi a pagamento del sito, e come stanno per CHI STA CHIEDENDO.
   *
   * PERCHE' COMPAIONO ANCHE QUANDO NON SI HANNO. Un servizio che non hai e'
   * invisibile, quindi non esiste, quindi non lo compri mai: e' il modo piu'
   * efficace di non vendere niente. Qui invece si dichiarano sempre tutti e
   * due, con scritto se sono attivi e - quando non lo sono - dove si comprano.
   * L'app ci mette una voce di menu, e chi la tocca finisce sulla pagina del
   * prodotto invece che davanti a un buco.
   *
   * CHI DECIDE SE SONO ATTIVI NON E' QUESTO PLUGIN. La risposta la sa gia'
   * `WWW_VT_Landing_Creatore::diritti()`, che guarda le licenze vere: e' la
   * stessa funzione che accende le sezioni sul sito. Riscrivere qui quel
   * controllo vorrebbe dire due verita' che col tempo si separano, e il giorno
   * che si separano l'app promette una pagina che il sito rifiuta.
   *
   * LE PAGINE SONO ENDPOINT DI WOOCOMMERCE: si chiedono a lui invece di
   * comporle a mano, perche' la base dell'area riservata cambia da sito a sito
   * ("mio-account", "my-account") e indovinarla e' un 404 silenzioso.
   */
  private static function servizi(int $uid): array {
    if (!class_exists('WWW_VT_Landing_Creatore')) return array();
    $d = WWW_VT_Landing_Creatore::diritti($uid);

    $conta = function ($chiave_licenza, $slug_ripiego) {
      // IL PRODOTTO SI TROVA DALLA MAPPA LICENZE, non indovinando lo slug.
      //
      // Qui c'era scritto 'videotickets': sembrava ragionevole, ed era
      // sbagliato - il prodotto vero si chiama 'www-video-tickets'. Uno slug
      // scritto a mano regge solo finche' nessuno lo controlla, e il giorno
      // che il titolo del prodotto cambia (o il sito ne aveva gia' uno suo
      // con un nome diverso) torna a essere sbagliato senza che niente lo dica.
      //
      // `wp_gpt_wc_license_settings` invece e' la stessa mappa che gia' usa
      // WWW_VT_Landing_Creatore::tipi_attivi() per sapere chi ha comprato
      // cosa: e' la fonte vera, aggiornata sito per sito quando il prodotto
      // viene creato. Lo slug resta solo come ripiego, per un sito su cui
      // quella mappa non e' ancora stata compilata.
      $lic = get_option('wp_gpt_wc_license_settings', array());
      $id = (int) explode(',', (string) ($lic[$chiave_licenza] ?? ''))[0];
      if ($id && function_exists('get_permalink')) {
        $url = get_permalink($id);
        if ($url) return $url;
      }
      $p = get_page_by_path($slug_ripiego, OBJECT, 'product');
      return $p ? get_permalink($p->ID) : '';
    };
    $conto = function ($endpoint) {
      return function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url($endpoint) : '';
    };

    $fuori = array(
      array(
        'chiave' => 'pagamenti',
        'etichetta' => 'Pagamenti',
        'a_cosa_serve' => 'Incassa dal tuo sito con Stripe: crea i link di pagamento e li gestisci da qui.',
        'attivo' => !empty($d['offerte']),
        'pagine' => !empty($d['offerte']) ? array(
          array('nome' => 'I miei link', 'url' => $conto('servicepay-links')),
          array('nome' => 'Metodi di pagamento', 'url' => $conto('servicepay-payment-methods')),
          array('nome' => 'Buoni', 'url' => $conto('servicepay-coupons')),
        ) : array(),
        'url_acquisto' => !empty($d['offerte']) ? '' : $conta('servicepay_product_ids', 'servicepay'),
      ),
      array(
        'chiave' => 'video',
        'etichetta' => 'Video',
        'a_cosa_serve' => 'Carica i tuoi video e mettili a noleggio sul sito.',
        'attivo' => !empty($d['video']),
        'pagine' => !empty($d['video']) ? array(
          array('nome' => 'I miei contenuti', 'url' => $conto('i-miei-contenuti')),
          array('nome' => 'Andamento', 'url' => $conto('andamento')),
        ) : array(),
        'url_acquisto' => !empty($d['video']) ? '' : $conta('videotickets_product_ids', 'www-video-tickets'),
      ),
    );

    // Le pagine senza indirizzo si tolgono: WooCommerce puo' non esserci, o
    // un endpoint puo' essere stato rinominato. Una voce che porta a niente e'
    // peggio di una voce che manca.
    foreach ($fuori as &$s) {
      $s['pagine'] = array_values(array_filter($s['pagine'], function ($p) {
        return !empty($p['url']);
      }));
    }
    unset($s);
    return apply_filters('wga_social_servizi', $fuori, $uid);
  }

  // ------------------------------------------------------------ chi sei, dove sei

  private static function sito(): array {
    return [
      'nome' => wp_specialchars_decode(get_bloginfo('name')),
      'url'  => rtrim(get_site_url(), '/'),
      'rest' => rtrim(get_site_url(), '/') . '/wp-json/wp-gpt',
      'versione_plugin' => defined('WP_GPT_VERSION') ? WP_GPT_VERSION : '',
    ];
  }

  /**
   * Se questo sito e' una CASA, e come si chiama.
   *
   * L'app lo chiede da sempre e finora non lo trovava: cercava il blocco `casa`
   * nel manifesto, nessuno lo scriveva, e ripiegava sulla strada sbagliata -
   * cercare fra i tool un nome che contenesse "homeassistant". Ma quella rotta
   * fa parte del plugin, quindi c'e' su OGNI sito che lo installa: risultavano
   * case anche i siti che non hanno una lampadina da accendere.
   *
   * IL SEGNALE E' LA CONFIGURAZIONE, NON LA CAPACITA'. Avere la rotta vuol dire
   * saper parlare con Home Assistant; essere una casa vuol dire che qualcuno ha
   * detto a questo sito dove sta il suo impianto. Sono due cose diverse, e
   * confonderle e' pericoloso: un sito pubblico puo' installare il gateway, ma
   * non deve mai comparire fra le case di nessuno.
   *
   * SI GUARDA IL TOKEN, NON L'INDIRIZZO. L'indirizzo ha un valore di ripiego
   * (http://homeassistant:8123), quindi risponde anche su un sito dove nessuno
   * ha configurato niente. Il token no: quello o lo si incolla o non c'e'.
   */
  private static function casa(): array {
    $token = trim((string) get_option('vwgk_ha_long_lived_token', ''));
    $attiva = ('' !== $token);

    $blocco = ['attiva' => $attiva];

    if ($attiva) {
      // Il nome che il sito dice di avere: l'app lo mostra sotto l'icona della
      // casetta, dove il nome della connessione scritto dall'utente ci sta male.
      $blocco['nome'] = wp_specialchars_decode(get_bloginfo('name'));
    }

    return $blocco;
  }

  /**
   * Chi sta chiamando, e cosa comporta.
   *
   * La differenza fra le due risposte non e' di grado ma di natura: chi firma
   * col portale amministra il sito, chi firma con le proprie chiavi lavora
   * dentro un recinto. Dirlo esplicitamente evita che un assistente scriva
   * istruzioni da amministratore per conto di un creatore, e poi si scontri
   * con una fila di 403 senza capire perche'.
   */
  private static function ruolo(): array {
    $personale = class_exists('WGA_Ambito') && WGA_Ambito::personale();
    if (!$personale) {
      return [
        'nome' => 'superadmin',
        'cosa_puoi_fare' => 'Amministri il sito: tutte le rotte del plugin, su qualsiasi '
                          . 'contenuto e qualsiasi autore.',
        'limiti' => 'Nessuno imposto dal plugin. Le chiavi sono quelle del portale, quindi '
                  . 'i servizi esterni (SerpApi, YouTube, posta, n8n) li paga il portale.',
      ];
    }
    return [
      'nome' => 'creatore',
      'utente' => WGA_Ambito::utente(),
      'cosa_puoi_fare' => 'Lavori sui tuoi contenuti: i tuoi articoli, le tue immagini, i tuoi '
                        . 'prodotti, la tua pagina da creatore, i tuoi segnaposto, le '
                        . 'condivisioni dai tuoi account social.',
      'limiti' => 'Le rotte fuori dal tuo elenco rispondono 403 e qui non compaiono nemmeno. '
                . 'Sui contenuti di altri autori la risposta e 403 anche su una rotta aperta. '
                . 'Gli ordini che vedi sono solo i tuoi. I servizi esterni usano le TUE chiavi, '
                . 'impostate nella pagina Chiavi API: se mancano, quelle funzioni dicono che la '
                . 'chiave manca e non ripiegano su quelle del portale.',
    ];
  }

  private static function intestazione(): array {
    $personale = class_exists('WGA_Ambito') && WGA_Ambito::personale();
    $uid = $personale ? WGA_Ambito::utente() : 0;
    $u = $uid ? get_userdata($uid) : null;

    return [
      'autorizzato' => true,
      'sito' => self::sito(),
      'casa' => self::casa(),
      'identita' => [
        'tipo'   => $personale ? 'persona' : 'portale',
        'utente' => $uid,
        'nome'   => $u ? $u->display_name : 'chiave del portale',
      ],
      'ruolo' => self::ruolo(),
      // L'AREA DA CREATORE VA DETTA IN OGNI RISPOSTA, non in una sola.
      //
      // Stava solo nel ramo `default` dello switch, che nessuno raggiunge:
      // senza `action` si finisce in 'lista' (intercettato prima dello
      // switch), e un'action inventata viene respinta dal registro. Quindi
      // il blocco `social` non arrivava a NESSUN chiamante - e l'app, che
      // chiede il manifesto senza action, concludeva che il sito non ha
      // un'area da creatore e non disegnava ne' diario ne' bacheca.
      // Qui invece siamo nell'intestazione, che e' unita a ogni risposta.
      'social' => self::social(),
      // Il nome dell'intestazione del codice e' configurabile: indovinarlo non
      // si puo', quindi si dice.
      'come_autenticare' => [
        'bearer' => 'Authorization: Bearer <token>',
        'intestazione_codice' => class_exists('WGA_Ambito') ? WGA_Ambito::nome_intestazione() : 'X-User-Code',
        'codice_nel_corpo' => 'user_code',
        'nota' => 'Basta uno fra l\'intestazione e il campo user_code nel corpo o in query. '
                . 'Il codice cambia ogni giorno.',
      ],
    ];
  }

  // ------------------------------------------------------------------ i tool

  /** Quelli che queste credenziali possono davvero usare. */
  private static function tool_visibili(): array {
    $personale = class_exists('WGA_Ambito') && WGA_Ambito::personale();
    return $personale ? self::tool_personali() : self::tool_del_portale();
  }

  /**
   * I nomi dei tool che chi sta chiamando puo' davvero usare.
   *
   * Serve fuori di qui: anche l'elenco delle action e la documentazione devono
   * rispettare gli stessi permessi, o si finisce per descrivere in dettaglio
   * funzioni che a chi legge risponderebbero 403 - e a spiegargli per bene una
   * porta chiusa.
   */
  public static function consentiti(): array {
    $fuori = [];
    foreach (self::tool_visibili() as $chiave => $t) {
      $nome = is_array($t) ? ($t['nome'] ?? ($t['percorso'] ?? '')) : (string) $t;
      if ('' === $nome && is_string($chiave)) $nome = $chiave;
      if ('' === $nome) continue;
      if (false !== strpos($nome, '/')) $nome = substr($nome, strrpos($nome, '/') + 1);
      $fuori[] = $nome;
    }
    // L'INDICE E' SEMPRE CONCESSO A SE STESSO.
    //
    // tools-manifest sta in SERVIZIO per non elencarsi fra i tool - giusto,
    // e' l'indice. Ma i permessi si ricavano da questo stesso elenco, e cosi'
    // l'indice risultava non concesso: chiedergli `documentazione` o
    // `istruzioni` rispondeva "non e' fra quelli concessi alle tue
    // credenziali". Chi ha codice utente e token puo' interrogare la casa:
    // deve poter chiedere anche COSA c'e' da interrogare, o si ritrova una
    // porta aperta senza cartello.
    $fuori[] = 'tools-manifest';

    return array_values(array_unique($fuori));
  }

  /**
   * A chi firma come persona: le rotte che la guardia gli lascia passare.
   *
   * NON si parte dalle schede della guida. Si parte dalle rotte che il sito ha
   * davvero registrato e si chiede alla guardia, una per una, se passerebbero.
   * E' la stessa funzione che decide quando arriva la richiesta vera: cosi'
   * l'elenco non e' una lista che somiglia ai permessi, e' i permessi.
   * Le schede della guida servono solo a dare un titolo leggibile a quello che
   * resta.
   */
  private static function tool_personali(): array {
    $schede = class_exists('WGA_Guida') ? WGA_Guida::schede_attive() : [];
    $fuori = [];

    foreach (self::rotte_registrate() as $percorso => $dati) {
      if (!class_exists('WGA_Ambito') || !WGA_Ambito::consentita('/wp-gpt' . $percorso)) continue;

      $scheda = null;
      foreach ($schede as $nome => $s) {
        if (strpos($percorso, '/' . $nome) !== false) { $scheda = $s; break; }
      }

      $fuori[] = [
        'nome' => $dati['nome'],
        'percorso' => $percorso,
        'metodi' => $dati['metodi'],
        'titolo' => $scheda ? $scheda['titolo'] : ucfirst(str_replace('-', ' ', $dati['nome'])),
        'cosa' => $scheda ? $scheda['cosa'] : 'Rotta disponibile alle tue chiavi. Chiedi il dettaglio.',
        'parametri' => $dati['parametri'],
      ];
    }
    return $fuori;
  }

  /** Alla chiave del portale: tutto quello che il plugin ha registrato. */
  private static function tool_del_portale(): array {
    $fuori = [];
    foreach (self::rotte_registrate() as $percorso => $dati) {
      $fuori[] = [
        'nome' => $dati['nome'],
        'percorso' => $percorso,
        'metodi' => $dati['metodi'],
        'titolo' => ucfirst(str_replace('-', ' ', $dati['nome'])),
        'cosa' => 'Rotta del plugin. Chiedi il dettaglio per i parametri.',
        'parametri' => $dati['parametri'],
      ];
    }
    return $fuori;
  }

  /**
   * Le rotte come WordPress le conosce, coi parametri dichiarati.
   *
   * I parametri non si inventano: register_rest_route li registra con tipo,
   * obbligatorieta' e descrizione, e sono quelli che il controllo della rotta
   * usera' davvero. Leggerli da li' significa che non possono divergere.
   */
  private static function rotte_registrate(): array {
    static $cache = null;
    if (null !== $cache) return $cache;

    $cache = [];
    foreach (rest_get_server()->get_routes() as $percorso => $gestori) {
      if (strpos($percorso, '/wp-gpt/v') !== 0) continue;
      if (preg_match('#/wp-gpt/v\d+$#', $percorso)) continue;      // l'indice dello spazio

      $nome = preg_replace('#^/wp-gpt/v\d+/#', '', $percorso);
      $secco = trim(preg_replace('#/\(\?P<.*$#', '', $nome), '/');
      if (in_array($secco, self::SERVIZIO, true)) continue;

      $metodi = [];
      $parametri = [];
      foreach ($gestori as $g) {
        foreach (array_keys((array) $g['methods']) as $m) $metodi[$m] = 1;
        foreach ((array) ($g['args'] ?? []) as $p => $d) {
          $parametri[$p] = [
            'nome' => $p,
            'tipo' => $d['type'] ?? 'string',
            'obbligatorio' => !empty($d['required']),
            'descrizione' => $d['description'] ?? '',
          ];
          if (!empty($d['enum'])) $parametri[$p]['valori'] = array_values((array) $d['enum']);
        }
      }

      $leggibile = preg_replace('#/\(\?P<([^>]+)>[^)]*\)#', '/{$1}', $percorso);
      $cache[preg_replace('#^/wp-gpt#', '', $leggibile)] = [
        'nome' => $secco,
        'metodi' => array_keys($metodi),
        'parametri' => array_values($parametri),
      ];
    }
    ksort($cache);
    return $cache;
  }

  // -------------------------------------------------------------- il dettaglio

  /**
   * Le descrizioni scritte a mano, per le rotte che non dichiarano i parametri.
   *
   * Meta' delle rotte del plugin legge il corpo direttamente, senza registrare
   * gli args: WordPress non sa dire cosa vogliono, e chiedere a lui darebbe una
   * scheda con un solo parametro. Il documento OpenAPI del plugin invece quelle
   * cose le dice - e' scritto a mano, quindi puo' invecchiare, ma per queste
   * rotte e' l'unica fonte che ci sia. Si usa come ripiego, mai al posto di
   * quello che la rotta dichiara di suo.
   */
  private static function documentazione(): array {
    static $doc = null;
    if (null !== $doc) return $doc;
    $doc = [];
    $file = WP_GPT_PLUGIN_DIR . 'data/openapi-descrizioni.json';
    if (file_exists($file)) {
      $letto = json_decode((string) file_get_contents($file), true);
      if (is_array($letto)) $doc = $letto;
    }
    return $doc;
  }

  /** La voce documentata per questo percorso, se c'e'. */
  private static function documentata(string $percorso, array $metodi): array {
    $doc = self::documentazione();
    foreach ([$percorso, $percorso . '/', rtrim($percorso, '/')] as $chiave) {
      if (!isset($doc[$chiave])) continue;
      foreach ($metodi as $m) {
        $m = strtolower($m);
        if (isset($doc[$chiave][$m])) return $doc[$chiave][$m];
      }
      return reset($doc[$chiave]) ?: [];
    }
    return [];
  }

  private static function dettaglio(array $t): array {
    $get = in_array('GET', $t['metodi'], true) || in_array('DELETE', $t['metodi'], true);
    $codice = class_exists('WGA_Ambito') ? WGA_Ambito::nome_intestazione() : 'X-User-Code';
    $base = rtrim(get_site_url(), '/') . '/wp-json/wp-gpt';

    $parametri = $t['parametri'];
    $fonte = $parametri ? 'dichiarati dalla rotta' : 'nessuno dichiarato';

    // Se la rotta non ha dichiarato niente, si guarda la documentazione.
    $doc = self::documentata($t['percorso'], $t['metodi']);
    if ($doc) {
      $noti = wp_list_pluck($parametri, 'nome');
      foreach ((array) ($doc['body']['properties'] ?? []) as $nome => $d) {
        if (in_array($nome, $noti, true)) continue;
        $voce = [
          'nome' => $nome,
          'tipo' => $d['type'] ?? 'string',
          'obbligatorio' => in_array($nome, (array) ($doc['body']['required'] ?? []), true),
          'descrizione' => $d['description'] ?? '',
        ];
        // L'elenco delle azioni e' la cosa piu' utile di tutte: e' li' che si
        // capisce cosa sa fare davvero una rotta che ne raccoglie venti.
        if (!empty($d['enum'])) $voce['valori'] = array_values((array) $d['enum']);
        $parametri[] = $voce;
      }
      foreach ((array) ($doc['parameters'] ?? []) as $d) {
        if (in_array($d['name'] ?? '', $noti, true)) continue;
        $parametri[] = [
          'nome' => $d['name'] ?? '', 'tipo' => $d['type'] ?? 'string',
          'obbligatorio' => !empty($d['required']), 'descrizione' => $d['description'] ?? '',
        ];
      }
      if (!empty($doc['description'])) $t['cosa'] = $doc['description'];
      if (!empty($doc['summary'])) $t['titolo'] = $doc['summary'];
      $fonte = $t['parametri'] ? 'dichiarati dalla rotta, completati dalla documentazione'
                               : 'dalla documentazione del plugin';
    }

    // Il codice utente non compare fra gli args delle rotte perche' lo legge il
    // controllo, non la rotta: va detto qui, o chi si collega non lo manda.
    $ha_codice = false;
    foreach ($parametri as $p) if ('user_code' === $p['nome']) $ha_codice = true;
    if (!$ha_codice) {
      array_unshift($parametri, [
        'nome' => 'user_code', 'tipo' => 'string', 'obbligatorio' => true,
        'descrizione' => 'Codice utente del giorno. In alternativa nell\'intestazione ' . $codice . '.',
      ]);
    }

    $ruolo = self::ruolo();

    return [
      'sito' => self::sito(),
      'casa' => self::casa(),
      'ruolo' => $ruolo,
      'tool' => [
        'nome' => $t['nome'],
        'titolo' => $t['titolo'],
        'cosa' => $t['cosa'],
        'metodi' => $t['metodi'],
        'url' => $base . $t['percorso'],
        'parametri' => $parametri,
        'parametri_da' => $fonte,
        'dove_vanno_i_parametri' => $get ? 'in query' : 'nel corpo, JSON',
        'esempio' => [
          'metodo' => $get ? 'GET' : 'POST',
          'url' => $base . $t['percorso'] . ($get ? '?user_code=<codice>' : ''),
          'intestazioni' => array_filter([
            'Authorization' => 'Bearer <token>',
            'Content-Type' => $get ? null : 'application/json',
          ]),
          'corpo' => $get ? null : array_merge(['user_code' => '<codice>'],
            self::esempio_corpo($parametri)),
        ],
        'note' => 'I segmenti fra graffe nell\'indirizzo vanno sostituiti con l\'identificativo. '
                . 'Vale il ruolo indicato qui sotto.',
        // La stessa rotta, usata da due ruoli diversi, e' due funzioni diverse:
        // dirlo qui vale piu' che ripeterlo in coda a ogni istruzione.
        'per_il_tuo_ruolo' => 'creatore' === $ruolo['nome']
          ? 'Sei un creatore (utente #' . (int) ($ruolo['utente'] ?? 0) . '): questa rotta '
            . 'agisce solo sui tuoi contenuti. Se passi l\'identificativo di un contenuto '
            . 'altrui ricevi 403, e gli elenchi arrivano gia\' filtrati sui tuoi. I servizi '
            . 'esterni usati qui pescano dalle tue chiavi.'
          : 'Sei il superadmin del sito: questa rotta agisce su qualsiasi contenuto e '
            . 'qualsiasi autore, senza filtri, e i servizi esterni usano le chiavi del portale.',
      ],
    ];
  }

  private static function esempio_corpo(array $parametri): array {
    $fuori = [];
    foreach ($parametri as $p) {
      if ('user_code' === $p['nome']) continue;
      if (empty($p['obbligatorio'])) continue;
      $fuori[$p['nome']] = isset($p['valori']) ? $p['valori'][0] : ('<' . $p['tipo'] . '>');
    }
    return $fuori;
  }

  // ------------------------------------------------------------------ schema

  /** Gli stessi tool in OpenAPI, per chi preferisce importarli tutti insieme. */
  private static function schema(array $tool): string {
    $percorsi = [];

    foreach ($tool as $t) {
      $voce = [];
      $id = preg_replace('/[^a-zA-Z0-9]+/', '_', trim($t['percorso'], '/'));

      foreach ($t['metodi'] as $m) {
        $m = strtolower($m);
        if (!in_array($m, ['get', 'post', 'put', 'patch', 'delete'], true)) continue;

        $op = [
          'operationId' => $m . '_' . $id,
          'summary' => $t['titolo'],
          'description' => $t['cosa'],
          'responses' => ['200' => ['description' => 'Esito']],
        ];

        if ('get' === $m || 'delete' === $m) {
          $par = [[
            'name' => 'user_code', 'in' => 'query', 'required' => true,
            'schema' => ['type' => 'string'], 'description' => 'Codice utente del giorno.',
          ]];
          foreach ($t['parametri'] as $p) {
            if ('user_code' === $p['nome']) continue;
            $par[] = [
              'name' => $p['nome'], 'in' => 'query', 'required' => !empty($p['obbligatorio']),
              'schema' => ['type' => $p['tipo'] ?: 'string'], 'description' => $p['descrizione'],
            ];
          }
          $op['parameters'] = $par;
        } else {
          $prop = ['user_code' => ['type' => 'string', 'description' => 'Codice utente del giorno.']];
          $obbl = ['user_code'];
          foreach ($t['parametri'] as $p) {
            if ('user_code' === $p['nome']) continue;
            $prop[$p['nome']] = array_filter([
              'type' => $p['tipo'] ?: 'string',
              'description' => $p['descrizione'],
              'enum' => $p['valori'] ?? null,
            ]);
            if (!empty($p['obbligatorio'])) $obbl[] = $p['nome'];
          }
          $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => [
            'type' => 'object', 'properties' => $prop, 'required' => $obbl,
            'additionalProperties' => true,
          ]]]];
        }
        $voce[$m] = $op;
      }
      if ($voce) $percorsi['/wp-json/wp-gpt' . $t['percorso']] = $voce;
    }

    return wp_json_encode([
      'openapi' => '3.1.0',
      'info' => [
        'title' => 'Funzioni di ' . wp_specialchars_decode(get_bloginfo('name')),
        'description' => 'Elenco generato dal sito: contiene le rotte registrate adesso, '
                       . 'ridotte a quelle che queste credenziali possono davvero usare.',
        'version' => defined('WP_GPT_VERSION') ? WP_GPT_VERSION : '1.0.0',
      ],
      'servers' => [['url' => rtrim(get_site_url(), '/')]],
      'paths' => $percorsi,
      'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
      'security' => [['bearerAuth' => new stdClass()]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }
}

endif;
