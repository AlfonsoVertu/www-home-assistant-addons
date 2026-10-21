<?php
/**
 * Le credenziali di ogni account: un token e un codice.
 *
 * Il token si conserva come impronta, non in chiaro: se un domani il database
 * finisce dove non deve, le chiavi degli utenti non sono dentro. Il valore
 * vero si mostra una volta sola, al momento in cui si crea, e da li' in poi
 * non lo sa piu' nessuno - nemmeno noi. Chi lo perde se lo rifa'.
 *
 * Il codice utente invece sta in chiaro: dura ventiquattro ore, viene
 * rispedito ogni giorno, e doverlo confrontare velocemente a ogni richiesta
 * conta piu' del vantaggio di nasconderlo.
 */
if (!defined('ABSPATH')) exit;

class WGA_Credenziali {

  const IMPRONTA = '_wga_token';        // hash del token
  const CODICE   = '_wga_codice';
  const SCADENZA = '_wga_codice_fino';
  const ENDPOINT = 'chiavi-api';

  public function init() {
    add_action('init', function () { add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES); });
    add_filter('query_vars', function ($v) { $v[] = self::ENDPOINT; return $v; });
    add_filter('woocommerce_account_menu_items', [__CLASS__, 'voce'], 26);
    add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'pagina']);
    add_filter('www_pannello_sezioni', function ($s) {
      $s['api'] = ['titolo' => 'Chiavi API', 'ordine' => 61, 'voci' => [self::ENDPOINT]];
      return $s;
    });
  }

  /** Vero se c'e' il plugin Telegram: senza, il codice non arriverebbe a nessuno. */
  public static function disponibile(): bool {
    // TELEGRAM SERVE A CONSEGNARE IL CODICE, non a far esistere le credenziali.
    // Il codice utente cambia ogni giorno e senza Telegram non arriverebbe a
    // nessuno: da qui la dipendenza. Ma quando il codice lo si consegna in un
    // altro modo - per esempio configurandolo a mano nell'app - la dipendenza
    // non serve, e senza questa opzione un sito senza Telegram non puo' avere
    // credenziali per utente nemmeno volendo. Predefinita spenta: chi non la
    // tocca si comporta esattamente come prima.
    if (get_option('wga_credenziali_personali')) return true;
    return class_exists('WWW_TG_Api') && class_exists('WWW_TG_DB');
  }

  public static function puo(int $uid): bool {
    if (!$uid) return false;
    if (user_can($uid, 'manage_options')) return true;
    if (class_exists('WWW_VT_Landing_Creatore')) return WWW_VT_Landing_Creatore::e_creatore($uid);
    return (bool) get_user_meta($uid, '_vt_creatore', true);
  }

  // ------------------------------------------------------------------ token

  /** Genera un token nuovo. Torna il valore in chiaro: e' l'unica volta che si vede. */
  public static function nuovo_token(int $uid): string {
    $t = 'wga_' . $uid . '_' . wp_generate_password(40, false, false);
    update_user_meta($uid, self::IMPRONTA, wp_hash_password($t));
    // Qui il token e' in chiaro per l'unica volta: se c'e' un accordo con sowai
    // e' adesso o mai piu'.
    if (class_exists('WGA_Sowai')) WGA_Sowai::avvisa($uid, '', $t);
    return $t;
  }

  public static function ha_token(int $uid): bool {
    return '' !== (string) get_user_meta($uid, self::IMPRONTA, true);
  }

  /**
   * Chi sta usando questo token.
   *
   * L'id sta scritto dentro il token stesso, cosi' si controlla una riga sola
   * invece di provare l'impronta di tutti gli utenti a ogni richiesta.
   */
  public static function utente_da_token(string $token): int {
    if (!preg_match('/^wga_(\d+)_/', $token, $m)) return 0;
    $uid = (int) $m[1];
    $impronta = (string) get_user_meta($uid, self::IMPRONTA, true);
    if ($impronta === '') return 0;
    return wp_check_password($token, $impronta) ? $uid : 0;
  }

  // ------------------------------------------------------------------ codice

  public static function nuovo_codice(int $uid): string {
    $c = strtoupper(wp_generate_password(10, false, false));
    update_user_meta($uid, self::CODICE, $c);
    update_user_meta($uid, self::SCADENZA, time() + DAY_IN_SECONDS);
    // Il codice cambia ogni giorno: chi si e' presentato con una stretta di
    // mano se lo sente dire, invece di trovare la porta chiusa domattina.
    if (class_exists('WGA_Sowai')) WGA_Sowai::avvisa($uid, $c, '');
    return $c;
  }

  public static function codice_valido(int $uid, string $codice): bool {
    $atteso = (string) get_user_meta($uid, self::CODICE, true);
    $fino = (int) get_user_meta($uid, self::SCADENZA, true);
    if ($atteso === '' || $codice === '') return false;
    if ($fino && $fino < time()) return false;
    return hash_equals($atteso, $codice);
  }

  public static function scade_fra(int $uid): string {
    $fino = (int) get_user_meta($uid, self::SCADENZA, true);
    if (!$fino) return 'nessun codice';
    if ($fino < time()) return 'scaduto';
    return 'fra ' . human_time_diff(time(), $fino);
  }

  // ------------------------------------------------------------------ pagina

  public static function voce($voci) {
    if (!self::disponibile() || !self::puo(get_current_user_id())) return $voci;
    $nuove = [];
    foreach ($voci as $k => $v) {
      if ('customer-logout' === $k) $nuove[self::ENDPOINT] = 'Chiavi API';
      $nuove[$k] = $v;
    }
    if (!isset($nuove[self::ENDPOINT])) $nuove[self::ENDPOINT] = 'Chiavi API';
    return $nuove;
  }

  public static function pagina() {
    $uid = get_current_user_id();
    if (!self::disponibile()) {
      echo '<p>Questa funzione richiede il collegamento a Telegram, che su questo sito non e&rsquo; '
         . 'attivo: il codice utente viaggia di li&rsquo;.</p>';
      return;
    }
    if (!self::puo($uid)) { echo '<p>Questa pagina e&rsquo; per chi pubblica.</p>'; return; }

    $ha_telegram = self::telegram_di($uid) > 0;

    if (!empty($_POST['wga_nonce']) && wp_verify_nonce($_POST['wga_nonce'], 'wga')) {
      if (!empty($_POST['wga_nuovo'])) {
        $t = self::nuovo_token($uid);
        $c = self::nuovo_codice($uid);
        echo '<div class="woocommerce-message"><p><strong>Ecco il tuo token. Copialo adesso: '
           . 'non potrai piu&rsquo; rivederlo.</strong></p>'
           . '<p><code style="word-break:break-all">' . esc_html($t) . '</code></p>'
           . '<p>Codice utente di oggi: <code>' . esc_html($c) . '</code></p></div>';
      }
      if (!empty($_POST['wga_chiavi']) && class_exists('WGA_Chiavi')) {
        $fatte = 0;
        foreach (WGA_Chiavi::nomi() as $nome) {
          if (!isset($_POST['k_' . $nome])) continue;
          $v = (string) wp_unslash($_POST['k_' . $nome]);
          // Un campo segreto lasciato vuoto non cancella: si sostituisce solo
          // scrivendoci dentro, se no bastava ricaricare per perdere tutto.
          if ($v === '' && WGA_Chiavi::ha($uid, $nome)) continue;
          WGA_Chiavi::scrivi($uid, $nome, $v);
          $fatte++;
        }
        printf('<div class="woocommerce-message">Chiavi aggiornate: %d.</div>', $fatte);
      }

      if (!empty($_POST['wga_revoca'])) {
        delete_user_meta($uid, self::IMPRONTA);
        delete_user_meta($uid, self::CODICE);
        delete_user_meta($uid, self::SCADENZA);
        echo '<div class="woocommerce-message">Chiavi revocate.</div>';
      }
    }

    printf('<p>Con queste due chiavi un programma esterno pu&ograve; lavorare sul sito <strong>al '
      . 'posto tuo e solo sulle tue cose</strong>: i tuoi contenuti, i tuoi prodotti, i tuoi '
      . 'commenti. Non vede e non tocca quelli degli altri.</p>');

    printf('<table class="woocommerce-orders-table shop_table"><tbody>'
      . '<tr><th>Token</th><td>%s</td></tr>'
      . '<tr><th>Codice utente</th><td>%s &middot; scade %s</td></tr>'
      . '<tr><th>Consegna del codice</th><td>%s</td></tr>'
      . '</tbody></table>',
      self::ha_token($uid) ? 'creato (non visibile)' : 'nessuno',
      self::ha_token($uid) ? 'rinnovato ogni 24 ore' : '&mdash;',
      esc_html(self::scade_fra($uid)),
      $ha_telegram ? 'Telegram collegato'
        : 'Telegram <strong>non</strong> collegato: scrivi al bot privato per riceverlo');

    echo '<form method="post" style="margin-top:16px">';
    wp_nonce_field('wga', 'wga_nonce');
    printf('<button class="button" type="submit" name="wga_nuovo" value="1">%s</button> ',
      self::ha_token($uid) ? 'Rigenera le chiavi' : 'Crea le chiavi');
    if (self::ha_token($uid)) {
      echo '<button class="button" type="submit" name="wga_revoca" value="1">Revoca</button>';
    }
    echo '</form>';

    echo '<p style="font-size:13px;opacity:.75;margin-top:14px">Le richieste vanno firmate cos&igrave;:<br>'
       . '<code>Authorization: Bearer &lt;token&gt;</code><br><code>X-User-Code: &lt;codice&gt;</code></p>';
  
    self::modulo_chiavi($uid);
  }

  /**
   * Le chiavi dei servizi, una serie per ciascuno.
   *
   * Il modulo si genera dalla struttura dichiarata in WGA_Chiavi: aggiungere un
   * servizio significa aggiungere una riga li', non toccare questa pagina.
   */
  private static function modulo_chiavi(int $uid) {
    if (!class_exists('WGA_Chiavi')) return;

    echo '<h3>Le tue chiavi dei servizi</h3>';
    echo '<p>Queste chiavi sono <strong>tue</strong>: quando le tue automazioni usano SerpApi, '
       . 'YouTube o gli altri servizi, il consumo viene addebitato sul tuo conto presso di loro. '
       . 'Le chiavi del portale non vengono mai usate al posto delle tue: se un campo resta '
       . 'vuoto, quella funzione non &egrave; disponibile.</p>';

    echo '<form method="post">';
    wp_nonce_field('wga', 'wga_nonce');

    foreach (WGA_Chiavi::gruppi() as $gruppo) {
      printf('<fieldset style="border:1px solid rgba(127,127,127,.3);border-radius:10px;'
        . 'padding:12px 16px;margin:0 0 16px"><legend>%s</legend>', esc_html($gruppo['titolo']));
      if (!empty($gruppo['nota'])) {
        printf('<p style="font-size:13px;opacity:.75;margin-top:0">%s</p>', esc_html($gruppo['nota']));
      }
      foreach ((array) $gruppo['campi'] as $nome => $campo) {
        $etichetta = $campo[0];
        $tipo = $campo[1];
        $valore = WGA_Chiavi::leggi($uid, $nome);
        printf('<p class="form-row"><label for="k_%s">%s</label><br>',
          esc_attr($nome), esc_html($etichetta));
        if ('segreto' === $tipo) {
          // Le chiavi non si rimostrano: si dice soltanto che ci sono.
          printf('<input type="password" id="k_%s" name="k_%s" style="width:100%%" '
            . 'autocomplete="new-password" placeholder="%s" value="">',
            esc_attr($nome), esc_attr($nome),
            $valore !== '' ? 'impostata - scrivi per sostituirla' : 'non impostata');
        } else {
          printf('<input type="text" id="k_%s" name="k_%s" style="width:100%%" value="%s">',
            esc_attr($nome), esc_attr($nome), esc_attr($valore));
        }
        echo '</p>';
      }
      echo '</fieldset>';
    }

    printf('<p><button class="button" type="submit" name="wga_chiavi" value="1">Salva le chiavi</button>'
      . ' <span style="font-size:13px;opacity:.7">compilate: %d su %d</span></p>',
      WGA_Chiavi::quante($uid), count(WGA_Chiavi::nomi()));
    echo '</form>';
  }

  /** L'identificativo Telegram di questo utente, se lo ha collegato. */
  public static function telegram_di(int $uid): int {
    if (!class_exists('WWW_TG_DB')) return 0;
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
      'SELECT telegram_id FROM ' . WWW_TG_DB::persone() . ' WHERE wp_user_id = %d LIMIT 1', $uid));
  }
}
