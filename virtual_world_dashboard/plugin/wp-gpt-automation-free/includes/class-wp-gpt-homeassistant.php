<?php
/**
 * Una sola porta verso Home Assistant.
 *
 * PERCHE' ERANO DUE. Il ponte generico - scopri cosa c'e', esegui un servizio,
 * parla all'assistente vocale - e' nato prima, sulla rotta /ha. La dashboard -
 * entita', script, scene, telecamere, storico, calendari - e' arrivata dopo,
 * su una rotta sua. Due rotte, due nomi, e nessuno dei due che dicesse tutto
 * quello che c'e' dentro: /ha e' troppo corto per farsi capire da un
 * assistente che sceglie gli strumenti leggendo i nomi, e
 * manage-homeassistant-dashboard-and-entities non nomina le telecamere che pure
 * governa.
 *
 * ORA E' UNA SOLA, e il nome dice cosa fa: domotica e videocamere. Diciannove
 * operazioni sotto un tool invece che spezzate fra due, che e' anche un tool in
 * meno da spendere contro il tetto delle trenta operazioni che ChatGPT accetta
 * in uno schema.
 *
 * QUESTA CLASSE NON FA NIENTE DA SOLA: smista. Il codice che parla davvero con
 * Home Assistant resta dov'e', nei due gestori di prima, che restano due perche'
 * fanno due mestieri diversi e riscriverli per fonderli sarebbe rischio senza
 * guadagno. Qui si guarda l'action e si consegna a chi la sa fare.
 */

namespace WP_GPT_Automation_Pro;

if (!defined('ABSPATH')) exit;

if (!class_exists('WP_GPT_Automation_Pro\WP_GPT_HomeAssistant')) :

class WP_GPT_HomeAssistant {

    const NAMESPACE = 'wp-gpt/v1';
    /*
     * Il nome e' lungo apposta. Chi lo legge e' un assistente che sceglie gli
     * strumenti scorrendo un elenco di nomi: "ha" non gli dice niente, e
     * "dashboard-and-entities" non nomina le telecamere che pure governa. Qui
     * ci sono tutte le parole che uno cercherebbe - casa, domotica, dashboard,
     * videocamere - perche' il nome e' il primo posto dove si guarda e spesso
     * l'unico.
     */
    const ROUTE     = '/manage-casahomeassistant-domoticandashboard-videocam';

    /**
     * Le action che sa fare il ponte generico. Tutte le altre vanno alla
     * dashboard.
     *
     * L'elenco e' scritto qui e non dedotto: sono quattro e cambiano di rado,
     * mentre la dashboard ne ha quindici e ne guadagnera' altre. Elencare il
     * gruppo piccolo e mandare il resto all'altro vuol dire che chi aggiunge una
     * action alla dashboard non deve ricordarsi di venire a toccare questo file.
     */
    const DEL_PONTE = ['discover', 'reload', 'execute', 'send-message-home-assistant-agent'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'registra']);
    }

    public static function registra() {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'smista'],
            'permission_callback' => ['WP_GPT_Automation_Pro\\WP_GPT_API', 'validate_request_with_user_code'],
        ]);
    }

    public static function smista(\WP_REST_Request $richiesta) {
        $parametri = $richiesta->get_json_params();
        if (!is_array($parametri)) $parametri = [];
        $azione = strtolower(trim((string) ($parametri['action'] ?? $richiesta->get_param('action'))));

        if (in_array($azione, self::DEL_PONTE, true)) {
            return WP_GPT_HA_Route::handle_request($richiesta);
        }

        return WP_GPT_HomeAssistant_Dashboard_Route::handle_request($richiesta);
    }
}

endif;
