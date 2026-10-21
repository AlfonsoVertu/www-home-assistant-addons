<?php
/**
 * Il rinnovo delle credenziali, e come le due parti se lo dicono.
 *
 * IL PROBLEMA, VISTO SUCCEDERE. Il 14 agosto il codice utente di un sito e'
 * stato ruotato. L'avviso alla controparte e' andato in timeout perche' dall'
 * altra parte non c'era nessuno, e non e' stato ritentato: il sito ha
 * continuato a rispondere 401 a chi usava il codice vecchio, e il collegamento
 * e' morto in silenzio - proprio la cosa che l'avviso doveva impedire. Da li'
 * le tre regole che questo file mette per iscritto.
 *
 * 1. COMANDA UNA PARTE SOLA. Le credenziali sono di questo sito: e' lui che le
 *    ruota. La controparte puo' CHIEDERE una rotazione, mai eseguirla. Se
 *    potessero ruotare entrambe, esisterebbe il momento in cui l'hanno fatto
 *    insieme e ognuna crede valida la propria: a quel punto non e' il codice
 *    vecchio a essere rifiutato, sono tutti e due, e non c'e' piu' un modo di
 *    rimettersi d'accordo dall'interno.
 *
 * 2. IL VECCHIO NON MUORE SUBITO. Dopo un cambio la credenziale precedente
 *    resta buona per una finestra breve. E' la rete sotto l'avviso: se
 *    l'annuncio si perde - rete giu', controparte spenta - c'e' comunque un
 *    intervallo in cui le chiamate passano e il rinnovo puo' essere chiesto.
 *    Senza, un pacchetto perso costa il collegamento.
 *
 * 3. UN AVVISO CHE FALLISCE NON E' UN AVVISO DATO. Se non arriva, resta scritto
 *    che e' in sospeso e si riprova - a tempo e alla prima visita in bacheca,
 *    perche' su molti siti WP-Cron gira solo quando qualcuno passa. E al nuovo
 *    tentativo la credenziale si RILEGGE: conservarla vorrebbe dire spedire un
 *    valore gia' scaduto.
 *
 * QUATTRO CREDENZIALI, QUATTRO CHIAVI DI RECUPERO. Codice utente, bearer,
 * header custom e parametro custom si rinnovano ognuno per conto suo, e ognuno
 * ha il suo token di rinnovo. Sono separati di proposito: se salta l'header non
 * si deve rifare anche il bearer, e soprattutto chi tiene una chiave di
 * recupero non deve poter rimettere in gioco le altre tre.
 *
 * IL TOKEN DI RINNOVO SI CONSUMA. Usato una volta, viene sostituito e il nuovo
 * torna nella stessa risposta. Cosi' se qualcuno lo intercetta ha in mano una
 * chiave gia' bruciata, e la controparte legittima se ne accorge al tentativo
 * successivo invece che mai.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WGA_Rinnovo')) :

class WGA_Rinnovo {

  /** Le quattro credenziali, col nome con cui la controparte le chiama. */
  const GENERI = [
    'user_code'    => 'usercode_refresh_token',
    'bearer'       => 'token_bearer_refresh_code',
    'header'       => 'x_custom_header_refresh_token',
    'parametro'    => 'x_custom_parameter_refresh_token',
  ];

  /** Quanto resta buona la credenziale vecchia dopo un cambio. */
  const GRAZIA = 15 * MINUTE_IN_SECONDS;

  const META_RINNOVI = '_wga_rinnovi';     // le chiavi di recupero di una persona
  const OPZ_RINNOVI  = 'wga_rinnovi';      // quelle del portale
  const META_SCADUTE = '_wga_in_grazia';   // i valori vecchi ancora tollerati
  const OPZ_SCADUTE  = 'wga_in_grazia';
  const OPZ_SOSPESI  = 'wga_avvisi_sospesi';

  // ------------------------------------------------------------ la grazia

  /**
   * Il valore vecchio vale ancora?
   *
   * Va chiamata SOLO dopo che il confronto col valore corrente e' fallito: e'
   * una tolleranza, non una seconda password. Chi passa di qui viene segnato,
   * cosi' nel diario si vede che qualcuno sta ancora usando il vecchio - ed e'
   * il segnale che l'avviso non e' arrivato.
   */
  public static function in_grazia(string $genere, string $valore, int $uid = 0): bool {
    if ('' === $valore) return false;
    $tutte = self::leggi(self::META_SCADUTE, self::OPZ_SCADUTE, $uid);
    $voce = $tutte[$genere] ?? null;
    if (!is_array($voce) || empty($voce['valore'])) return false;
    if (time() > (int) ($voce['fino'] ?? 0)) return false;
    $uguale = function_exists('hash_equals')
      ? hash_equals((string) $voce['valore'], $valore)
      : ((string) $voce['valore'] === $valore);
    if ($uguale) self::annota($uid, "credenziale $genere accettata in grazia: l'avviso non e' arrivato");
    return $uguale;
  }

  /** Mette in grazia il valore che sta per essere sostituito. */
  public static function metti_in_grazia(string $genere, string $vecchio, int $uid = 0) {
    if ('' === $vecchio) return;
    $tutte = self::leggi(self::META_SCADUTE, self::OPZ_SCADUTE, $uid);
    $tutte[$genere] = ['valore' => $vecchio, 'fino' => time() + self::GRAZIA];
    self::scrivi(self::META_SCADUTE, self::OPZ_SCADUTE, $tutte, $uid);
  }

  // --------------------------------------------------- le chiavi di recupero

  /**
   * Crea (o rifa') la chiave di recupero di una credenziale e la restituisce.
   *
   * SI SALVA IN CHIARO, ED E' UNA SCELTA. Con l'impronta sarebbe piu' al
   * sicuro se qualcuno rubasse il database - ma non sarebbe leggibile da
   * nessuno, nemmeno dal proprietario del sito, e qui la regola e' che le
   * credenziali si vedano in bacheca come si sono sempre viste. Un valore che
   * l'amministratore non puo' rileggere e' un valore che, se la controparte lo
   * perde, non si rimette a posto se non buttando via il collegamento.
   * L'esposizione e' la stessa che hanno gia' token e codice utente.
   */
  public static function nuova_chiave(string $genere, int $uid = 0): string {
    if (!isset(self::GENERI[$genere])) return '';
    $chiave = wp_generate_password(48, false, false);
    $tutte = self::leggi(self::META_RINNOVI, self::OPZ_RINNOVI, $uid);
    $tutte[$genere] = ['valore' => $chiave, 'quando' => current_time('mysql')];
    self::scrivi(self::META_RINNOVI, self::OPZ_RINNOVI, $tutte, $uid);
    return $chiave;
  }

  /** La chiave presentata e' quella giusta per questo genere? */
  public static function chiave_valida(string $genere, string $chiave, int $uid = 0): bool {
    if ('' === $chiave || !isset(self::GENERI[$genere])) return false;
    $tutte = self::leggi(self::META_RINNOVI, self::OPZ_RINNOVI, $uid);
    $attesa = (string) ($tutte[$genere]['valore'] ?? '');
    if ('' === $attesa) return false;
    // confronto a tempo costante: con == la lunghezza della parte uguale si
    // misura dal tempo di risposta, e una chiave si indovina un pezzo per volta
    return function_exists('hash_equals') ? hash_equals($attesa, $chiave) : ($attesa === $chiave);
  }

  /**
   * Le chiavi di rinnovo cosi' come sono, per mostrarle in bacheca.
   *
   * Restituisce anche i generi che non hanno ancora una chiave: in una tabella
   * di credenziali una riga mancante e' un'informazione - dice che quel canale
   * non e' mai stato preparato - mentre una riga assente sembra un errore di
   * disegno e non si va a cercare.
   */
  public static function chiavi(int $uid = 0): array {
    $tutte = self::leggi(self::META_RINNOVI, self::OPZ_RINNOVI, $uid);
    $fuori = [];
    foreach (self::GENERI as $genere => $nome) {
      $fuori[$genere] = [
        'nome' => $nome,
        'valore' => (string) ($tutte[$genere]['valore'] ?? ''),
        'quando' => (string) ($tutte[$genere]['quando'] ?? ''),
      ];
    }
    return $fuori;
  }

  // ------------------------------------------------------- gli avvisi sospesi

  /**
   * Segna che un avviso non e' arrivato, cosi' qualcuno riprovera'.
   *
   * NON SI CONSERVA IL VALORE, solo che genere di credenziale era: al prossimo
   * tentativo si rilegge quella corrente. Conservarla significherebbe, dopo due
   * rotazioni ravvicinate, spedire con convinzione un valore gia' morto.
   */
  public static function sospendi(int $uid, string $genere) {
    $sospesi = get_option(self::OPZ_SOSPESI);
    $sospesi = is_array($sospesi) ? $sospesi : [];
    $sospesi[$uid . '|' . $genere] = ['uid' => $uid, 'genere' => $genere,
                                      'dal' => time(), 'tentativi' => 0];
    update_option(self::OPZ_SOSPESI, $sospesi, false);
    self::annota($uid, "avviso di $genere non riuscito: resta in sospeso, si riprova");
  }

  public static function risolto(int $uid, string $genere) {
    $sospesi = get_option(self::OPZ_SOSPESI);
    if (!is_array($sospesi)) return;
    unset($sospesi[$uid . '|' . $genere]);
    update_option(self::OPZ_SOSPESI, $sospesi, false);
  }

  /**
   * Riprova tutti gli avvisi rimasti indietro.
   *
   * DUE OCCASIONI, non una: l'ora di WP-Cron e la prima apertura della
   * bacheca. Su molti siti WP-Cron gira solo quando qualcuno visita, e un sito
   * poco visitato e' esattamente quello dove un collegamento morto resta morto
   * per giorni senza che nessuno lo sappia.
   */
  public static function riprova_sospesi() {
    $sospesi = get_option(self::OPZ_SOSPESI);
    if (!is_array($sospesi) || !$sospesi) return;
    foreach ($sospesi as $id => $v) {
      $uid = (int) ($v['uid'] ?? 0);
      $genere = (string) ($v['genere'] ?? '');
      if (!isset(self::GENERI[$genere])) { unset($sospesi[$id]); continue; }
      // si rilegge adesso: il valore di allora potrebbe non essere piu' quello
      $ok = self::annuncia($uid, $genere, self::valore_corrente($genere, $uid), true);
      if ($ok) { unset($sospesi[$id]); continue; }
      $sospesi[$id]['tentativi'] = (int) ($v['tentativi'] ?? 0) + 1;
    }
    update_option(self::OPZ_SOSPESI, $sospesi, false);
  }

  // ----------------------------------------------------------- l'annuncio

  /**
   * Dice alla controparte che una credenziale e' cambiata.
   *
   * Il bearer non si puo' rispedire - di lui resta solo l'impronta, e in chiaro
   * passa una volta sola - quindi per quello si annuncia il CAMBIO e si lascia
   * che venga richiesto con la sua chiave di recupero. Per gli altri tre il
   * valore nuovo viaggia insieme all'annuncio.
   */
  public static function annuncia(int $uid, string $genere, string $valore, bool $ritento = false): bool {
    if (!class_exists('WGA_Sowai')) return false;
    $accordo = WGA_Sowai::accordo($uid);
    if (!$accordo || empty($accordo['url'])) return false;

    $corpo = [
      'evento' => 'credenziale-cambiata',
      'genere' => $genere,
      'riferimento' => $accordo['riferimento'] ?? '',
      'sito' => rtrim(get_site_url(), '/'),
      'utente' => $uid,
      'quando' => current_time('mysql'),
      'user_code' => $accordo['codice'] ?? '',
      'grazia_fino' => gmdate('c', time() + self::GRAZIA),
    ];
    if ('bearer' !== $genere && '' !== $valore) $corpo['valore_nuovo'] = $valore;

    $esito = wp_remote_post(rtrim($accordo['url'], '/'), [
      'timeout' => 20,
      'headers' => ['Authorization' => 'Bearer ' . ($accordo['token'] ?? ''),
                    'Content-Type' => 'application/json'],
      'body' => wp_json_encode(['params' => $corpo]),
    ]);

    $riuscito = !is_wp_error($esito) && (int) wp_remote_retrieve_response_code($esito) < 400;
    if ($riuscito) { self::risolto($uid, $genere); return true; }
    if (!$ritento) self::sospendi($uid, $genere);
    return false;
  }

  // ------------------------------------------------- quello che chiede l'altro

  /**
   * La controparte ha perso una credenziale e la richiede con la sua chiave.
   *
   * RISPONDE ANCHE SE LA CHIAVE E' SBAGLIATA, sempre nello stesso tempo e con
   * lo stesso errore: un "genere sconosciuto" distinto da "chiave errata"
   * direbbe a chi prova quali generi esistono.
   */
  public static function consegna(string $genere, string $chiave, int $uid = 0): array {
    if (!isset(self::GENERI[$genere]) || !self::chiave_valida($genere, $chiave, $uid)) {
      self::annota($uid, "richiesta di rinnovo respinta ($genere)");
      return ['errore' => 'Chiave di rinnovo non valida.'];
    }
    $valore = self::valore_corrente($genere, $uid);
    if ('bearer' === $genere) {
      // di lui non abbiamo il valore: si puo' solo rifarlo, e questo e' un
      // gesto che deve restare in mano al sito
      self::annota($uid, 'chiesto il bearer: si puo\' solo rigenerare dalla bacheca');
      return ['errore' => 'Il bearer non si puo\' rileggere: va rigenerato dal sito.',
              'come' => 'Chiedi action=rinnovo-richiesta con genere=bearer.'];
    }
    self::annota($uid, "consegnata la credenziale $genere su richiesta");
    return [
      'genere' => $genere,
      'valore' => $valore,
      // la chiave usata e' bruciata: se ne consegna una nuova nella stessa
      // risposta, cosi' non esiste il momento in cui la controparte non ne ha
      'chiave_nuova' => self::nuova_chiave($genere, $uid),
      'nome_chiave' => self::GENERI[$genere],
    ];
  }

  /**
   * La controparte CHIEDE che una credenziale venga ruotata.
   *
   * Chiedere si', fare no: la rotazione la esegue il sito. Qui si prende nota e
   * si dice di si' o di no - eseguirla su richiesta esterna vorrebbe dire dare
   * a chi ha una chiave sola il potere di cambiare le altre.
   */
  public static function richiesta_cambio(string $genere, string $chiave, int $uid = 0): array {
    if (!isset(self::GENERI[$genere]) || !self::chiave_valida($genere, $chiave, $uid)) {
      return ['errore' => 'Chiave di rinnovo non valida.'];
    }
    $coda = get_option('wga_rinnovi_chiesti');
    $coda = is_array($coda) ? $coda : [];
    $coda[$uid . '|' . $genere] = ['uid' => $uid, 'genere' => $genere, 'quando' => time()];
    update_option('wga_rinnovi_chiesti', $coda, false);
    self::annota($uid, "la controparte ha chiesto la rotazione di $genere");
    return ['richiesta' => 'presa in carico', 'genere' => $genere,
            'chi_decide' => 'il sito: la rotazione non si esegue su richiesta esterna',
            'cosa_succede' => 'Quando avviene, arriva l\'annuncio all\'indirizzo dell\'accordo.'];
  }

  // ------------------------------------------------------------- utilita'

  private static function valore_corrente(string $genere, int $uid) {
    switch ($genere) {
      case 'user_code':
        return $uid ? (string) get_user_meta($uid, 'wp_gpt_user_code', true)
                    : (string) get_option('wp_gpt_user_code');
      case 'header':    return (string) get_option('wp_gpt_custom_header_value');
      case 'parametro': return (string) get_option('wp_gpt_custom_param_value');
      default:          return '';   // il bearer non si rilegge
    }
  }

  private static function leggi(string $meta, string $opzione, int $uid): array {
    $v = $uid ? get_user_meta($uid, $meta, true) : get_option($opzione);
    return is_array($v) ? $v : [];
  }

  private static function scrivi(string $meta, string $opzione, array $dati, int $uid) {
    if ($uid) update_user_meta($uid, $meta, $dati);
    else update_option($opzione, $dati, false);
  }

  private static function annota(int $uid, string $testo) {
    if (!$uid || !class_exists('WGA_Sowai')) return;
    $storico = get_user_meta($uid, '_wga_sowai_diario', true);
    $storico = is_array($storico) ? $storico : [];
    array_unshift($storico, ['quando' => current_time('mysql'), 'cosa' => $testo]);
    update_user_meta($uid, '_wga_sowai_diario', array_slice($storico, 0, 20));
  }
}

endif;
