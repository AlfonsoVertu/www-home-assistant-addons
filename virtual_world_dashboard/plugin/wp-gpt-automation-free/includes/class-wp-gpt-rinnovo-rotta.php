<?php
/**
 * La porta del rinnovo: come la controparte chiede una credenziale nuova.
 *
 * PERCHE' SERVE UNA ROTTA A PARTE. Tutte le altre rotte pretendono un codice
 * utente valido. Questa e' esattamente quella che serve QUANDO il codice non
 * vale piu': chiedere le credenziali usando le credenziali e' un cerchio, e
 * chi ci finisce dentro non ne esce - il collegamento resta morto e nessuno
 * capisce perche'. Qui si entra con la chiave di rinnovo e basta.
 *
 * LA CHIAVE DI RINNOVO NON E' UN LASCIAPASSARE. Apre solo queste tre
 * operazioni e ognuna per un genere solo: chi tiene la chiave del codice
 * utente non puo' toccare il bearer. E il bearer non si consegna mai da qui -
 * si rigenera dalla bacheca del sito, con una persona davanti.
 *
 * TRE USI, NON UNO. La chiave regge tre rotazioni prima di essere sostituita:
 * se la risposta che porta la chiave nuova si perde - rete che cade, telefono
 * che si spegne a meta' - la controparte puo' riprovare invece di restare
 * fuori per sempre. Vedi WGA_Rinnovo::USI_CHIAVE.
 */

namespace WP_GPT_Automation_Pro;

if (!defined('ABSPATH')) exit;

if (!class_exists('WP_GPT_Automation_Pro\WP_GPT_Rinnovo_Rotta')) :

class WP_GPT_Rinnovo_Rotta {

    const NAMESPACE = 'wp-gpt/v1';
    const ROUTE     = '/manage-credentials-rotation-refresh-token';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'registra']);
    }

    public static function registra() {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'smista'],
            // LA GUARDIA C'E', MA NON E' QUELLA DELLE ALTRE ROTTE.
            //
            // Le altre pretendono un codice utente valido, e qui sarebbe un
            // cerchio: questa e' la rotta che serve QUANDO il codice non vale
            // piu'. Pero' aperta a chiunque sarebbe peggio - uno sconosciuto
            // potrebbe far ruotare le credenziali di un sito e spegnere il
            // collegamento di chi ci lavora, senza rubare niente. Quindi
            // entra chi ha una chiave di rinnovo buona per il genere che
            // chiede - anche una gia' sostituita, se il sito la ricorda
            // ancora - oppure chi ha ancora un codice utente valido.
            'permission_callback' => [__CLASS__, 'puo_entrare'],
            'args' => [
                'action' => ['type' => 'string', 'required' => true,
                             'description' => 'chiedi-rotazione, riprendi-credenziale, stato.'],
                'genere' => ['type' => 'string', 'required' => false,
                             'description' => 'user_code, header o parametro.'],
                'chiave' => ['type' => 'string', 'required' => false,
                             'description' => 'La chiave di rinnovo di quel genere.'],
            ],
        ]);
    }

    /**
     * Chi bussa ha diritto di entrare?
     *
     * Il controllo vero sta in WGA_Rinnovo, che e' anche il posto dove le
     * chiavi vivono: qui si estraggono solo i due campi dalla richiesta. Se il
     * rinnovo non e' disponibile si nega - meglio una porta che non si apre di
     * una porta che si apre senza guardare.
     */
    public static function puo_entrare(\WP_REST_Request $richiesta) {
        if (!class_exists('WGA_Rinnovo')) return false;
        $p = $richiesta->get_json_params();
        if (!is_array($p)) $p = [];
        $genere = strtolower(trim((string) ($p['genere'] ?? $richiesta->get_param('genere'))));
        $chiave = (string) ($p['chiave'] ?? $richiesta->get_param('chiave'));
        $uid    = (int) ($p['utente'] ?? $richiesta->get_param('utente'));
        if (\WGA_Rinnovo::puo_entrare($genere, $chiave, $uid)) return true;
        return new \WP_Error('rinnovo_negato',
            'Serve una chiave di rinnovo valida per questo genere, oppure un codice utente ancora buono.',
            ['status' => 403]);
    }

    public static function smista(\WP_REST_Request $richiesta) {
        if (!class_exists('WGA_Rinnovo')) {
            return new \WP_REST_Response(
                ['errore' => 'Il rinnovo non e\' disponibile su questo sito.'], 501);
        }
        $p = $richiesta->get_json_params();
        if (!is_array($p)) $p = [];
        $azione = strtolower(trim((string) ($p['action'] ?? $richiesta->get_param('action'))));
        $genere = strtolower(trim((string) ($p['genere'] ?? $richiesta->get_param('genere'))));
        $chiave = (string) ($p['chiave'] ?? $richiesta->get_param('chiave'));
        $uid    = (int) ($p['utente'] ?? $richiesta->get_param('utente'));

        if (0 === strpos($azione, 'documentazione')) {
            return new \WP_REST_Response(self::documentazione($azione), 200);
        }

        switch ($azione) {
            case 'chiedi-rotazione':
                return self::rispondi(\WGA_Rinnovo::richiesta_cambio($genere, $chiave, $uid));

            case 'riprendi-credenziale':
                return self::rispondi(\WGA_Rinnovo::consegna($genere, $chiave, $uid));

            case 'stato':
                // Non dice MAI il valore: dice solo se la chiave regge ancora
                // e quanto. Chi ha sbagliato chiave riceve zero, che e' la
                // stessa risposta di una chiave finita - non si insegna a
                // nessuno quali generi esistono.
                if ('' === $genere || !\WGA_Rinnovo::chiave_valida($genere, $chiave, $uid)) {
                    return new \WP_REST_Response(
                        ['errore' => 'Chiave di rinnovo non valida.'], 403);
                }
                return new \WP_REST_Response([
                    'genere' => $genere,
                    'usi_residui' => \WGA_Rinnovo::usi_residui($genere, $uid),
                    'usi_massimi' => \WGA_Rinnovo::USI_CHIAVE,
                    'grazia_secondi' => \WGA_Rinnovo::GRAZIA,
                ], 200);
        }

        return new \WP_REST_Response([
            'errore' => 'Action sconosciuta.',
            'action_disponibili' => ['chiedi-rotazione', 'riprendi-credenziale', 'stato'],
        ], 400);
    }

    /**
     * Una chiave sbagliata e' 403, non 200 con dentro un errore.
     *
     * Chi automatizza guarda il codice HTTP prima del corpo: un rifiuto
     * mascherato da 200 lo fa proseguire come se avesse ottenuto qualcosa.
     */
    private static function rispondi(array $esito) {
        return new \WP_REST_Response($esito, isset($esito['errore']) ? 403 : 200);
    }

    private static function documentazione(string $azione): array {
        $schede = class_exists('WGA_Azioni')
            ? \WGA_Azioni::tool(ltrim(self::ROUTE, '/')) : [];
        if ('documentazione' === $azione) {
            return [
                'tool' => ltrim(self::ROUTE, '/'),
                'cosa_fa' => $schede['descrizione'] ?? '',
                'action_di_questa_pagina' => $schede['azioni'] ?? [],
            ];
        }
        $quale = substr($azione, strlen('documentazione_'));
        $def = $schede['azioni'][$quale] ?? null;
        return $def
            ? ['tool' => ltrim(self::ROUTE, '/'), 'action' => $quale] + $def
            : ['errore' => 'La operazione ' . $quale . ' non esiste in questo tool.'];
    }
}

endif;
