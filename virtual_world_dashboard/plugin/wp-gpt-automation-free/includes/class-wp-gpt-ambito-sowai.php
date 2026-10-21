<?php
/**
 * La stretta di mano con sowai, e l'avviso quando le credenziali cambiano.
 *
 * IL PROBLEMA. Il codice utente cambia ogni giorno. Chi si e' collegato ieri -
 * un GPT, sowai, un altro portale - stamattina trova una porta chiusa, e nessuno
 * gli ha detto perche'. La soluzione non e' allungare la scadenza: e' avvisare.
 *
 * COME FUNZIONA. Alla prima richiesta sowai chiama tools-manifest con
 * action=stretta-di-mano e lascia qui tre cose: il suo indirizzo, il suo token e
 * il suo codice utente. Da quel momento questo sito sa come richiamarlo. Quando
 * il codice o il token di qui cambiano, il sito bussa a sowai e glieli passa,
 * senza che nessuno debba accorgersene.
 *
 * DI CHI SONO LE CREDENZIALI. La stretta di mano e' intestata a chi la fa: se a
 * chiamare e' un creatore, l'accordo vale per lui e riguarda le sue chiavi; se e'
 * la chiave del portale, vale per il sito. Due creatori sullo stesso sito hanno
 * due accordi distinti, e nessuno dei due riceve le credenziali dell'altro.
 *
 * IL TOKEN SI VEDE UNA VOLTA SOLA. Di lui qui resta solo l'impronta, quindi si
 * puo' spedire solo nel momento in cui viene generato. Il codice invece si puo'
 * rispedire sempre. Chi riceve deve essere pronto a ricevere l'uno, l'altro o
 * entrambi.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WGA_Sowai')) :

class WGA_Sowai {

  const META    = '_wga_sowai';      // l'accordo di una persona
  const OPZIONE = 'wga_sowai';       // l'accordo del portale

  /** Registra l'accordo per chi sta chiamando adesso. */
  public static function stringi(array $dati): array {
    $url = esc_url_raw(trim((string) ($dati['odoo_url'] ?? '')));
    $token = trim((string) ($dati['odoo_token'] ?? ''));
    $codice = trim((string) ($dati['odoo_user_code'] ?? ''));
    $riferimento = sanitize_text_field((string) ($dati['riferimento'] ?? ''));

    if ('' === $url || '' === $token || '' === $codice) {
      return ['errore' => 'Servono odoo_url, odoo_token e odoo_user_code.'];
    }

    $accordo = [
      'url' => $url,
      'token' => $token,
      'codice' => $codice,
      'riferimento' => $riferimento,
      'quando' => current_time('mysql'),
    ];

    $uid = self::utente();
    if ($uid) update_user_meta($uid, self::META, $accordo);
    else update_option(self::OPZIONE, $accordo);

    return [
      'stretta' => 'fatta',
      'per' => $uid ? ('utente #' . $uid) : 'chiave del portale',
      'richiameremo' => $url,
      'riferimento' => $riferimento,
      'cosa_succede_adesso' => 'Quando il codice utente o il token di questo sito cambiano, '
                             . 'il sito chiama quell\'indirizzo e li consegna. Non serve piu\' '
                             . 'venire a chiedere.',
    ];
  }

  /** L'accordo valido per una persona, o quello del portale. */
  public static function accordo(int $uid = 0) {
    if ($uid) {
      $a = get_user_meta($uid, self::META, true);
      return is_array($a) ? $a : null;
    }
    $a = get_option(self::OPZIONE);
    return is_array($a) ? $a : null;
  }

  private static function utente(): int {
    return (class_exists('WGA_Ambito') && WGA_Ambito::personale()) ? (int) WGA_Ambito::utente() : 0;
  }

  /**
   * Avvisa sowai che qualcosa e' cambiato.
   *
   * Si spedisce quello che si ha: il token solo se ci e' appena passato per le
   * mani in chiaro, il codice sempre. Se non c'e' un accordo non succede niente
   * - questa funzione non deve mai essere il motivo per cui una rotazione di
   * credenziali fallisce.
   */
  public static function avvisa(int $uid, string $codice = '', string $token = '') {
    $accordo = self::accordo($uid);
    if (!$accordo || empty($accordo['url'])) return false;

    $corpo = array_filter([
      'riferimento' => $accordo['riferimento'] ?? '',
      'sito' => rtrim(get_site_url(), '/'),
      'utente' => $uid,
      'user_code_nuovo' => $codice,
      'token_nuovo' => $token,
      'quando' => current_time('mysql'),
      // Il codice di sowai serve a lui per riconoscerci: e' la sua chiave, non
      // la nostra, e viaggia come parametro perche' e' li' che lo cerca.
      'user_code' => $accordo['codice'] ?? '',
    ], function ($v) { return '' !== $v && null !== $v; });

    $esito = wp_remote_post(rtrim($accordo['url'], '/'), [
      'timeout' => 20,
      'headers' => [
        'Authorization' => 'Bearer ' . ($accordo['token'] ?? ''),
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode(['params' => $corpo]),
    ]);

    if (is_wp_error($esito)) {
      self::annota($uid, 'avviso non riuscito: ' . $esito->get_error_message());
      return false;
    }
    $codice_http = (int) wp_remote_retrieve_response_code($esito);
    self::annota($uid, 'avviso spedito, risposta ' . $codice_http);
    return $codice_http < 400;
  }

  private static function annota(int $uid, string $testo) {
    $storico = get_user_meta($uid, self::META . '_diario', true);
    $storico = is_array($storico) ? $storico : [];
    array_unshift($storico, ['quando' => current_time('mysql'), 'cosa' => $testo]);
    update_user_meta($uid, self::META . '_diario', array_slice($storico, 0, 20));
  }
}

endif;
