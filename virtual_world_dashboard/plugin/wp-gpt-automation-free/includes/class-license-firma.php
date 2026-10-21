<?php
/**
 * Verifica la firma delle risposte del gestore licenze (lato plugin).
 *
 * PERCHE' ESISTE. La richiesta al gestore viaggia cifrata, ma la risposta
 * arrivava in chiaro e senza firma: bastava restituire {"success":true}
 * per ottenere una licenza valida. E la chiave AES che protegge la
 * richiesta e' la stessa dentro ogni copia venduta di ogni plugin, quindi
 * non prova niente su chi sta parlando.
 *
 * Qui si verifica una firma Ed25519 con la sola chiave PUBBLICA. Che sia
 * leggibile in questo file e' voluto: una pubblica verifica e non firma.
 * La privata sta solo sul gestore.
 *
 * SI ENTRA IN PUNTA DI PIEDI. Finche' non tutti i siti hanno un plugin
 * aggiornato, una firma mancante o sbagliata NON spegne la licenza: viene
 * annotata e si prosegue. Chi vuole il muro subito definisce
 * WP_GPT_LICENSE_FIRMA_OBBLIGATORIA a true. Il giorno in cui i plugin in
 * giro sono tutti aggiornati, quello diventa il comportamento normale.
 * Iniziare severi vorrebbe dire spegnere il pannello a chi ha pagato per
 * colpa di un nostro rilascio, che e' esattamente il danno che questa
 * classe dovrebbe prevenire.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_GPT_License_Firma' ) ) {
class WP_GPT_License_Firma {

    /**
     * Le chiavi pubbliche dei gestori, in base64. Si leggono nella
     * schermata "Firma delle risposte" di ciascun gestore.
     *
     * PERCHE' PIU' DI UNA. I gestori sono due - workingwithweb.it e il
     * ripiego su libertaeazione.it - e ognuno ha la sua coppia di chiavi.
     * L'alternativa sarebbe copiare la stessa chiave PRIVATA sui due
     * server: significherebbe moltiplicare i posti da cui puo' uscire il
     * segreto, per risparmiare tre righe qui. Con l'elenco, ogni gestore
     * tiene la sua privata e non si sposta niente.
     *
     * Una risposta e' buona se la verifica passa con ALMENO UNA. Non e'
     * un indebolimento: ogni chiave corrisponde a un gestore che
     * consideriamo nostro, ed e' esattamente la stessa fiducia che il
     * plugin gia' accorda ai due indirizzi che interroga.
     *
     * ELENCO VUOTO = verifica impossibile: in modo tollerante si lascia
     * passare, in modo obbligatorio si rifiuta.
     */
    const CHIAVI_PUBBLICHE = [
        'aFjIRTkh77/ij34LVi+ZJiF8YeyOrbQKTBAjcCHYrmI=',   // workingwithweb.it/webagency
        '',   // libertaeazione.it (ripiego)
    ];

    const ALGORITMO = 'ed25519';
    /** Quanto puo' essere vecchia una risposta: copre gli orologi storti. */
    const FINESTRA  = 86400;

    public static function obbligatoria() {
        $v = defined( 'WP_GPT_LICENSE_FIRMA_OBBLIGATORIA' ) && WP_GPT_LICENSE_FIRMA_OBBLIGATORIA;
        return (bool) apply_filters( 'wp_gpt_license_firma_obbligatoria', $v );
    }

    /** Un nonce da mandare nella richiesta: lega la risposta a QUESTA domanda. */
    public static function nonce() {
        return bin2hex( random_bytes( 16 ) );
    }

    /**
     * Esito: ['ok' =&gt; bool, 'motivo' =&gt; string].
     *
     * `ok` false vuol dire "questa risposta non e' fidata". Sta al
     * chiamante decidere se cio' basta a rifiutare, secondo obbligatoria().
     */
    public static function verifica( $corpo, $dominio, $nonce_atteso ) {
        if ( ! is_array( $corpo ) ) {
            return [ 'ok' => false, 'motivo' => 'risposta non interpretabile' ];
        }

        if ( empty( $corpo['sig'] ) ) {
            return [ 'ok' => false, 'motivo' => 'risposta senza firma' ];
        }
        if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
            return [ 'ok' => false, 'motivo' => 'questo PHP non sa verificare le firme' ];
        }
        $chiavi = self::chiavi();
        if ( ! $chiavi ) {
            return [ 'ok' => false, 'motivo' => 'nessuna chiave pubblica configurata' ];
        }
        if ( isset( $corpo['sig_alg'] ) && $corpo['sig_alg'] !== self::ALGORITMO ) {
            return [ 'ok' => false, 'motivo' => 'algoritmo di firma sconosciuto' ];
        }

        $firma = base64_decode( (string) $corpo['sig'], true );
        if ( $firma === false || strlen( $firma ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
            return [ 'ok' => false, 'motivo' => 'firma malformata' ];
        }

        $dati = $corpo;
        unset( $dati['sig'], $dati['sig_alg'] );
        $canonica = self::canonica( $dati );

        // Basta che UNA chiave riconosca la firma: i gestori sono due e
        // ognuno tiene la propria privata.
        $valida = false;
        foreach ( $chiavi as $pubblica ) {
            try {
                if ( sodium_crypto_sign_verify_detached( $firma, $canonica, $pubblica ) ) {
                    $valida = true;
                    break;
                }
            } catch ( Exception $e ) {
                continue;   // una chiave storta non deve fermare le altre
            }
        }
        if ( ! $valida ) {
            return [ 'ok' => false, 'motivo' => 'firma non valida' ];
        }

        // Da qui in poi il contenuto e' autentico: restano i controlli che
        // impediscono di RIGIOCARE una risposta autentica ma non nostra.
        $nonce_atteso = (string) $nonce_atteso;
        if ( $nonce_atteso !== '' ) {
            $ricevuto = isset( $corpo['sig_nonce'] ) ? (string) $corpo['sig_nonce'] : '';
            if ( ! hash_equals( $nonce_atteso, $ricevuto ) ) {
                return [ 'ok' => false, 'motivo' => 'risposta a una domanda diversa' ];
            }
        }

        $dominio = strtolower( (string) $dominio );
        if ( $dominio !== '' ) {
            $firmato = strtolower( isset( $corpo['sig_domain'] ) ? (string) $corpo['sig_domain'] : '' );
            if ( $firmato !== '' && ! hash_equals( $dominio, $firmato ) ) {
                return [ 'ok' => false, 'motivo' => 'risposta firmata per un altro dominio' ];
            }
        }

        $quando = isset( $corpo['sig_time'] ) ? (int) $corpo['sig_time'] : 0;
        if ( $quando > 0 && abs( time() - $quando ) > self::FINESTRA ) {
            return [ 'ok' => false, 'motivo' => 'risposta troppo vecchia' ];
        }

        return [ 'ok' => true, 'motivo' => '' ];
    }

    /**
     * Le chiavi pubbliche utilizzabili, gia' decodificate e scartando
     * quelle vuote o malformate. Un elenco con una sola chiave buona
     * funziona: non serve compilarle tutte.
     */
    private static function chiavi() {
        $fuori = [];
        foreach ( (array) self::CHIAVI_PUBBLICHE as $b64 ) {
            $b64 = trim( (string) $b64 );
            if ( $b64 === '' ) {
                continue;
            }
            $grezza = base64_decode( $b64, true );
            if ( $grezza !== false && strlen( $grezza ) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
                $fuori[] = $grezza;
            }
        }
        return $fuori;
    }

    /**
     * La stessa forma canonica del gestore: chiavi ordinate, niente fughe
     * di barre e unicode. Se le due non coincidono, nessuna firma torna.
     */
    public static function canonica( array $dati ) {
        $dati = self::ordina( $dati );
        return (string) json_encode( $dati, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    private static function ordina( $valore ) {
        if ( ! is_array( $valore ) ) {
            return $valore;
        }
        $fuori = [];
        foreach ( $valore as $k => $v ) {
            $fuori[ $k ] = self::ordina( $v );
        }
        if ( array_keys( $fuori ) !== range( 0, count( $fuori ) - 1 ) ) {
            ksort( $fuori );
        }
        return $fuori;
    }
}
}
