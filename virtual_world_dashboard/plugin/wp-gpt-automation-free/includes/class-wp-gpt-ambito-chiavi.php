<?php
/**
 * Le chiavi dei servizi esterni, una serie per ogni persona.
 *
 * IL PROBLEMA. Le rotte che parlano con SerpApi, YouTube, gli scraper e la
 * posta leggono opzioni del sito: sono le chiavi del portale. Finche' le usa
 * l'amministratore va bene, ma se le usa un creatore ogni sua chiamata la paga
 * il portale - e nessuno se ne accorge finche' non arriva la fattura.
 *
 * LA SOLUZIONE, IN UN PUNTO SOLO. Non si toccano le rotte: ci si mette fra
 * loro e il database, sul filtro con cui WordPress permette di intercettare la
 * lettura di un'opzione. Quando la richiesta e' firmata da una persona, al
 * posto della chiave del sito le si consegna la sua. Le decine di rotte
 * continuano a chiamare get_option come hanno sempre fatto.
 *
 * E SE NON L'HA MESSA? Non si ripiega sulla nostra: si consegna il vuoto, cosi'
 * il servizio risponde "chiave mancante" e quella persona capisce che deve
 * inserire la propria. Ripiegare in silenzio sulle chiavi di casa e' esattamente
 * il problema che questo file esiste per chiudere.
 *
 * L'AMMINISTRATORE NON PASSA DI QUI: continua a leggere le opzioni del sito,
 * perche' quelle sono davvero sue.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WGA_Chiavi')) :

class WGA_Chiavi {

  const PREFISSO = '_wga_';    // le chiavi personali stanno nel profilo utente

  /**
   * Le opzioni che diventano personali, raggruppate per come si presentano
   * nella pagina delle impostazioni.
   */
  public static function gruppi(): array {
    return (array) apply_filters('wga_chiavi_personali', [
      'servizi' => [
        'titolo' => 'Servizi esterni',
        'nota'   => 'Le chiavi dei servizi che usi tu. Ogni chiamata viene addebitata sul tuo conto presso quel servizio, non sul portale.',
        'campi'  => [
          'wp_gpt_serpapi_key' => ['Chiave SerpApi', 'segreto'],
          'wp_gpt_youtube_api_key' => ['Chiave API YouTube', 'segreto'],
          'wp_gpt_youtube_transcription_api_key' => ['Chiave per le trascrizioni YouTube', 'segreto'],
          'wp_gpt_openai_key' => ['Chiave OpenAI', 'segreto'],
          'wp_gpt_hix_api_key' => ['Chiave HIX (riscrittura testi)', 'segreto'],
          'wp_gpt_hix_endpoint' => ['Indirizzo del servizio HIX', 'testo'],
          'wp_gpt_hix_word_limit' => ['Limite di parole HIX', 'testo'],
        ],
      ],
      'posta' => [
        'titolo' => 'Posta in uscita',
        'nota'   => 'Se li lasci vuoti, le tue automazioni non mandano posta: le email del portale non partono mai a tuo nome.',
        'campi'  => [
          'wp_gpt_email_server' => ['Server di posta', 'testo'],
          'wp_gpt_email_port' => ['Porta', 'testo'],
          'wp_gpt_email_username' => ['Utente', 'testo'],
          'wp_gpt_email_password' => ['Password', 'segreto'],
          'wp_gpt_email_from' => ['Indirizzo mittente', 'testo'],
        ],
      ],
      'n8n' => [
        'titolo' => 'n8n (le tue automazioni)',
        'nota'   => 'Il tuo n8n, non quello del portale: i flussi che fai partire girano sul tuo, e nessuno vede i tuoi.',
        'campi'  => [
          'wp_gpt_n8n_url' => ['Indirizzo API di n8n (es. https://tuo-n8n.it/api/v1)', 'testo'],
          'wp_gpt_n8n_api_key' => ['Chiave API di n8n', 'segreto'],
          'wp_gpt_n8n_webhook_secret' => ['Segreto dei webhook', 'segreto'],
          'wp_gpt_n8n_workspace' => ['Workspace predefinita', 'testo'],
        ],
      ],

      'memoria' => [
        'titolo' => 'Memoria e segnaposto',
        'nota'   => 'Servono agli assistenti per ricordare il contesto e per i testi ricorrenti.',
        'campi'  => [
          'wp_gpt_openai_vector_store_id' => ['Archivio vettoriale OpenAI', 'testo'],
        ],
      ],
    ]);
  }

  /** Tutti i nomi delle opzioni che diventano personali. */
  public static function nomi(): array {
    $fuori = [];
    foreach (self::gruppi() as $g) {
      foreach (array_keys((array) $g['campi']) as $n) $fuori[] = $n;
    }
    return $fuori;
  }

  public static function init() {
    // Un filtro per opzione: e' il punto in cui WordPress chiede il valore, e
    // quindi l'unico che intercetta tutte le rotte insieme.
    foreach (self::nomi() as $nome) {
      add_filter('pre_option_' . $nome, function ($valore) use ($nome) {
        return self::forse_personale($valore, $nome);
      }, 5);
    }

    // n8n fa storia a se': non legge una chiave per volta, legge un'unica
    // opzione che dentro ha tutti i suoi campi. Qui la si ricompone con quelli
    // della persona, cosi' il connettore continua a chiamare get_option come
    // ha sempre fatto e parla col n8n giusto.
    add_filter('pre_option_wp_gpt_n8n_settings', [__CLASS__, 'n8n_personale'], 5);
  }

  /**
   * Le impostazioni n8n di chi sta facendo la richiesta.
   *
   * Se non ha messo indirizzo e chiave si consegna il vuoto, non le nostre: il
   * connettore rispondera' "configura n8n" e quella persona sapra' che deve
   * mettere le proprie. Ripiegare sul n8n del portale vorrebbe dire far girare
   * i suoi flussi a casa nostra.
   */
  public static function n8n_personale($valore) {
    if (!class_exists('WGA_Ambito') || !WGA_Ambito::personale()) return $valore;

    $uid = WGA_Ambito::utente();
    if (!$uid) return $valore;

    return [
      'n8n_url'           => self::leggi($uid, 'wp_gpt_n8n_url'),
      'api_key'           => self::leggi($uid, 'wp_gpt_n8n_api_key'),
      'webhook_secret'    => self::leggi($uid, 'wp_gpt_n8n_webhook_secret'),
      'default_workspace' => self::leggi($uid, 'wp_gpt_n8n_workspace'),
      'timeout'           => 20,
      'verify_ssl'        => true,
    ];
  }

  private static function forse_personale($valore, string $nome) {
    if (!class_exists('WGA_Ambito') || !WGA_Ambito::personale()) return $valore;

    $uid = WGA_Ambito::utente();
    if (!$uid) return $valore;

    $mia = self::leggi($uid, $nome);

    // Vuoto vuol dire vuoto: non si ricade sulla chiave del portale.
    return $mia;
  }

  // ------------------------------------------------------------- lettura e scrittura

  public static function leggi(int $uid, string $nome): string {
    return (string) get_user_meta($uid, self::PREFISSO . $nome, true);
  }

  public static function scrivi(int $uid, string $nome, string $valore) {
    if (!in_array($nome, self::nomi(), true)) return false;
    $valore = trim($valore);
    if ($valore === '') delete_user_meta($uid, self::PREFISSO . $nome);
    else update_user_meta($uid, self::PREFISSO . $nome, sanitize_text_field($valore));
    return true;
  }

  /** Quante ne ha compilate: serve a dire se una funzione gli e' aperta. */
  public static function quante(int $uid): int {
    $n = 0;
    foreach (self::nomi() as $x) if (self::leggi($uid, $x) !== '') $n++;
    return $n;
  }

  public static function ha(int $uid, string $nome): bool {
    return self::leggi($uid, $nome) !== '';
  }
}

endif;
