<?php
/**
 * Il codice nuovo, ogni ventiquattro ore, su Telegram.
 *
 * Si rinnova solo a chi ha davvero un token: rigenerare codici per account che
 * non li useranno mai significa mandare messaggi a gente che non ha chiesto
 * niente, e insegnare a ignorare quei messaggi proprio a chi invece li usa.
 *
 * Chi non ha collegato Telegram non riceve niente e il suo codice non cambia:
 * cambiarlo senza poterglielo dire vorrebbe dire romperlo ogni notte.
 */
if (!defined('ABSPATH')) exit;

class WGA_Consegna {

  const EVENTO = 'wga_rinnova_codici';

  public function init() {
    add_action(self::EVENTO, [__CLASS__, 'gira']);
    if (!wp_next_scheduled(self::EVENTO)) self::pianifica();
    add_action('admin_menu', [__CLASS__, 'menu'], 30);
  }

  public static function pianifica() {
    if (!wp_next_scheduled(self::EVENTO)) {
      wp_schedule_event(strtotime('tomorrow 5:10'), 'daily', self::EVENTO);
    }
  }

  public static function gira(): array {
    if (!class_exists('WGA_Credenziali') || !WGA_Credenziali::disponibile()) {
      return ['saltato' => 'Telegram non disponibile'];
    }

    $utenti = get_users(['meta_key' => WGA_Credenziali::IMPRONTA, 'fields' => 'ID']);
    $mandati = 0; $senza = 0;

    foreach ($utenti as $uid) {
      $uid = (int) $uid;
      $tid = WGA_Credenziali::telegram_di($uid);
      if (!$tid) { $senza++; continue; }        // niente Telegram: il codice resta quello

      $c = WGA_Credenziali::nuovo_codice($uid);
      $token = self::bot_per($uid);
      if ($token === '') { $senza++; continue; }

      WWW_TG_Api::messaggio($token, (string) $tid,
        'Il tuo codice utente di oggi',
        "Codice: " . $c . "\n\nVale ventiquattro ore. Va mandato insieme al token, "
        . "nell'intestazione X-User-Code. Se non hai chiesto tu queste chiavi, revocale "
        . "dalla tua area riservata.");
      $mandati++;
    }

    $esito = ['quando' => current_time('mysql'), 'mandati' => $mandati, 'senza_telegram' => $senza];
    update_option('wga_ultima_consegna', $esito, false);
    return $esito;
  }

  /**
   * Con quale bot si scrive a questa persona.
   *
   * Il suo, se e' un creatore che ne ha configurato uno; altrimenti quello del
   * portale. Un codice personale non passa dal bot di un altro creatore.
   */
  private static function bot_per(int $uid): string {
    if (!class_exists('WWW_TG_Entita')) return '';
    $sua = WWW_TG_Entita::conf($uid);
    if (!empty($sua['bot_privato'])) return (string) $sua['bot_privato'];
    $portale = WWW_TG_Entita::conf(0);
    return (string) ($portale['bot_privato'] ?: '');
  }

  // ------------------------------------------------------------------ admin

  public static function menu() {
    add_submenu_page('options-general.php', 'Chiavi API degli utenti', 'Chiavi API utenti',
      'manage_options', 'wga', [__CLASS__, 'pagina']);
  }

  public static function pagina() {
    if (!current_user_can('manage_options')) wp_die('No');
    echo '<div class="wrap"><h1>Chiavi API degli utenti</h1>';

    if (!WGA_Credenziali::disponibile()) {
      echo '<div class="notice notice-warning"><p>Il plugin Telegram non e&rsquo; attivo: senza, '
         . 'il codice utente non avrebbe come arrivare a nessuno, e la funzione resta spenta.</p></div>';
    }

    if (!empty($_POST['wga_ora']) && check_admin_referer('wga_ora')) {
      $e = self::gira();
      printf('<div class="notice notice-info"><p>Codici mandati: %d &middot; senza Telegram: %d</p></div>',
        (int) ($e['mandati'] ?? 0), (int) ($e['senza_telegram'] ?? 0));
    }

    $u = get_users(['meta_key' => WGA_Credenziali::IMPRONTA, 'fields' => 'ID']);
    printf('<p>Account con chiavi personali: <strong>%d</strong>. Il token del portale, quello '
      . 'unico di sempre, continua a funzionare come prima.</p>', count($u));

    echo '<table class="widefat striped"><thead><tr><th>Account</th><th>Codice</th>'
       . '<th>Telegram</th></tr></thead><tbody>';
    foreach ($u as $id) {
      $id = (int) $id;
      printf('<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
        esc_html(get_the_author_meta('display_name', $id)),
        esc_html(WGA_Credenziali::scade_fra($id)),
        WGA_Credenziali::telegram_di($id) ? 'collegato' : '<em>non collegato</em>');
    }
    if (!$u) echo '<tr><td colspan="3">Nessuno, per ora.</td></tr>';
    echo '</tbody></table>';

    echo '<form method="post" style="margin-top:14px">';
    wp_nonce_field('wga_ora');
    echo '<button class="button" name="wga_ora" value="1">Rinnova e manda adesso</button></form>';

    $reg = get_option('wga_registro', []);
    if (is_array($reg) && $reg) {
      echo '<h2>Ultime richieste firmate</h2><table class="widefat striped"><tbody>';
      foreach (array_slice($reg, 0, 20) as $r) {
        printf('<tr><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
          esc_html((string) $r['quando']),
          esc_html(get_the_author_meta('display_name', (int) $r['utente'])),
          esc_html((string) $r['esito']), esc_html((string) $r['rotta']));
      }
      echo '</tbody></table>';
    }
    echo '</div>';
  }
}
