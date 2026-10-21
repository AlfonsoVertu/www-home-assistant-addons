<?php
/**
 * La porta: la documentazione si chiede sulla rotta stessa, su tutte le rotte.
 *
 * PERCHE' UN PUNTO SOLO. Le rotte sono un centinaio. Aggiungere la
 * documentazione a ciascuna vorrebbe dire cento modifiche, cento occasioni di
 * dimenticarsene, e una differenza di comportamento fra la rotta scritta ieri e
 * quella scritta l'anno scorso. Qui si aggancia una volta e vale per tutte -
 * comprese quelle che nessuno ha ancora scritto.
 *
 * DOVE SI AGGANCIA, E PERCHE' PROPRIO LI'. `rest_dispatch_request` scatta dopo
 * che il permesso e' stato verificato e prima che il gestore venga chiamato. E'
 * l'unico punto con tutte e due le proprieta' che servono: chi arriva qui ha
 * gia' dimostrato di avere credenziali valide - quindi non si documenta niente
 * a chi bussa senza chiave - e il gestore non e' ancora partito, quindi si puo'
 * rispondere al posto suo senza che abbia gia' fatto qualcosa.
 *
 * NON SI DOCUMENTA A CHI NON PUO' ENTRARE. Il controllo dei permessi resta
 * quello della rotta: qui si aggiunge solo la spiegazione a chi e' gia' dentro
 * e ha sbagliato la chiamata.
 *
 * SI TACE QUANDO NON C'E' NIENTE DA DIRE. Se il tool non e' nel registro, o se
 * la chiamata e' corretta, questa porta lascia passare e non tocca niente: una
 * rotta che funziona non deve cambiare comportamento perche' e' stato aggiunto
 * un aiuto per chi sbaglia.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WGA_Porta')) :

class WGA_Porta {

  public static function init() {
    add_filter('rest_dispatch_request', [__CLASS__, 'guarda'], 10, 4);
  }

  /**
   * @param mixed            $esito    null se nessuno ha ancora risposto.
   * @param WP_REST_Request  $richiesta
   * @param string           $percorso Il percorso della rotta, es. /wp-gpt/v1/manage-...
   */
  public static function guarda($esito, $richiesta, $percorso, $gestore) {
    // qualcun altro ha gia' risposto: non ci si mette in mezzo
    if (null !== $esito) return $esito;
    if (!is_string($percorso) || 0 !== strpos($percorso, '/wp-gpt/')) return $esito;
    if (!class_exists('WGA_Azioni')) return $esito;

    // ANCHE tools-manifest passa di qui. Escluderlo ne farebbe l'unico tool del
    // sito a comportarsi diversamente da come insegna a tutti gli altri, ed e'
    // il primo posto dove chi legge perderebbe fiducia nella regola. Le altre
    // rotte di servizio non sono nel registro, quindi spiega() tace da sola.
    $tool = WGA_Azioni::nome_pulito($percorso);

    $parametri = self::parametri($richiesta);
    $azione = isset($parametri['action']) ? (string) $parametri['action'] : '';

    // 1. "documentami TUTTA questa rotta", chiesto sulla rotta stessa.
    //    Vale per ogni tool senza doverlo scrivere in ogni tool: l'aggancio e'
    //    uno solo e passano di qui tutte le rotte /wp-gpt/, comprese quelle che
    //    verranno scritte domani.
    if (WGA_Azioni::doc_intera_chiesta($azione)) {
      $pagina = isset($parametri['pagina']) ? (int) $parametri['pagina'] : 1;
      // Si accetta anche il nome inglese: e' il primo che prova chi arriva da
      // uno schema OpenAPI, e negarglielo per una lettera sarebbe gratuito.
      if (!$pagina && isset($parametri['page'])) $pagina = (int) $parametri['page'];
      $per = isset($parametri['per_pagina']) ? (int) $parametri['per_pagina']
           : (isset($parametri['per_page']) ? (int) $parametri['per_page'] : 8);
      return new WP_REST_Response(
        WGA_Azioni::documentazione_rotta($tool, $pagina ?: 1, $per ?: 8), 200);
    }

    // 2. "documentami questa action", chiesto sulla rotta stessa
    $chiesta = WGA_Azioni::doc_chiesta($azione);
    if ('' !== $chiesta) {
      return new WP_REST_Response(WGA_Azioni::documentazione_di($tool, $chiesta), 200);
    }

    // 2. chiamata sbagliata: si risponde con la spiegazione invece che con un
    //    errore secco. Chi ha sbagliato e' esattamente chi ha bisogno di sapere.
    $spiegazione = WGA_Azioni::spiega($tool, $parametri);
    if (null === $spiegazione) return $esito;            // va bene cosi': passa

    $stato = isset($spiegazione['errore']) &&
             false !== strpos($spiegazione['errore'], 'non e\' fra quelli concessi') ? 403 : 400;
    return new WP_REST_Response($spiegazione, $stato);
  }

  /** I parametri, da dove capita che arrivino: corpo JSON, form, query. */
  private static function parametri($richiesta): array {
    $fuori = [];
    if (is_object($richiesta) && method_exists($richiesta, 'get_json_params')) {
      $j = $richiesta->get_json_params();
      if (is_array($j)) $fuori = $j;
    }
    if (is_object($richiesta) && method_exists($richiesta, 'get_body_params')) {
      $b = $richiesta->get_body_params();
      if (is_array($b)) $fuori = array_merge($b, $fuori);
    }
    if (is_object($richiesta) && method_exists($richiesta, 'get_query_params')) {
      $q = $richiesta->get_query_params();
      if (is_array($q)) $fuori = array_merge($q, $fuori);
    }
    return $fuori;
  }
}

endif;
