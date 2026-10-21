<?php
/*
Plugin Name: WP GPT Automation Free
Plugin URI: https://workingwithweb.it/webagency
Description: Plugin per gestire WordPress tramite GPT con API sicure. Versione Free - limitata alle funzioni base e integrazione Home Assistant.
Version: 1.3.2
Author: Alfonso Vertucci
Author URI: https://workingwithweb.it/webagency
License: Proprietary (Comodato d'uso gratuito controllato)
License URI: https://workingwithweb.it/webagency
Text Domain: wp-gpt-automation-free
Domain Path: /languages
Requires at least: 6.0
Requires PHP: 7.4
Update URI: https://workingwithweb.it/wp-gpt-automation-pro
*/

/**
 * WP GPT Automation Free è software proprietario fornito in comodato d'uso gratuito.
 * Le limitazioni di riproduzione restano identiche alla versione PRO.
 * © 2026 Alfonso Vertucci. Tutti i diritti riservati.
 */

namespace WP_GPT_Automation_Pro;

if (!defined('ABSPATH')) {
    exit; // Evita l'accesso diretto.
}

define('WP_GPT_VERSION', '1.3.2');
define('WP_GPT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_GPT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_GPT_CHUNK_SIZE', 2000);

// Funzioni di supporto base
require_once WP_GPT_PLUGIN_DIR . 'includes/functions-bearer-token.php';

// Classi base, sicurezza e routing core
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-activation.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-deactivation.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-license-check.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-menu-visibility.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-v1-route-scheduler.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-admin.php'; 
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-administrator.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-auth-log.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-auth-log-admin.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-token-validator.php';

// Memoria base
require_once WP_GPT_PLUGIN_DIR . 'includes/memory/class-wp-gpt-memory.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-memory-admin.php';

// API Core (Tagliate)
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-api.php';

// SSH base e avanzato
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ssh-connection.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ssh-admin.php';

// Integrazione Home Assistant: una porta sola, due gestori dietro.
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-homeassistant-dashboard-route.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ha-route.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-homeassistant.php';

// LE STANZE, I DISPOSITIVI E LE PAGINE: la rotta che dice com'e' fatta la casa.
// Completa quella dell'impianto, non la sostituisce: li' si accende e si
// spegne, qui si guarda dove stanno le cose e si costruiscono le pagine.
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-casa-ws.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-casa-stanze.php';
WP_GPT_Casa_Stanze::init();
// LA ROTTA STORICA DELLA CASA. `smista()` manda a WP_GPT_HA_Route solo
// discover/reload/execute/send-message; TUTTO IL RESTO - search,
// get-entities, control-entity - va a questa classe. Senza il require il
// plugin si attiva e poi muore di errore fatale alla prima ricerca.
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-homeassistant-dashboard-route.php';
WP_GPT_HomeAssistant::init();

// Cercare fuori: ricerca, video, immagini, e la lettura di pagine e post X.
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-serpapi.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-gather-information.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-webpage-and-twitter-scraping.php';
\WGA_Scraping_Pagina_E_Twitter::init();

// Guide e Schema UI
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-guide-categories.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-schema-guide.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-generate-schema.php';

// ---------------------------------------------------------------- ambito
// La scoperta delle rotte (tools-manifest) e la stretta di mano con SoWay.
// Senza queste, l'app SoWay chiede il manifesto, riceve un 404, e considera
// il sito "senza funzioni" pur avendone: e' esattamente cosa succedeva.
//
// L'ORDINE CONTA: 'ambito' usa credenziali e chiavi, il manifesto usa tutti.
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ambito-credenziali.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ambito-chiavi.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ambito.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ambito-consegna.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ambito-guida.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ambito-sowai.php';
// Il rinnovo delle credenziali si appoggia all'accordo: dopo, mai prima.
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ambito-rinnovo.php';
// LA PORTA DEL RINNOVO, non solo il meccanismo.
//
// WGA_Rinnovo c'era gia' - sa mettere in grazia il valore vecchio e produrre
// la chiave nuova - ma la ROTTA che lo espone no: la logica esisteva e non
// era raggiungibile da nessuno. Si vede dall'app: un collegamento a questo
// sito non riesce a procurarsi le chiavi di rinnovo, quindi quando il codice
// utente cambia resta fuori e serve una persona che riscriva le credenziali
// a mano. Le due righe che seguono sono quella porta.
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-rinnovo-rotta.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/azioni-rinnovo.php';
// Il registro delle action e la porta: la free risponde come la Pro, perche'
// una regola che vale solo su meta' dei siti non e' una regola.
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ambito-azioni.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/azioni-homeassistant.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/azioni-tools-manifest.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/azioni-casa-stanze.php';
// LE SCHEDE DELLE ALTRE ROTTE. Senza questi require i tool esistono ma
// rispondono "non ha action dichiarate" a documentazione: per l'AI e' come
// se non sapessero fare niente, e deve tirare a indovinare i parametri.
require_once WP_GPT_PLUGIN_DIR . 'includes/azioni-gather-information.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/azioni-goal.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/azioni-manage-file.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/azioni-manage-page.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/azioni-route-scheduler.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/azioni-ssh-connection.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/azioni-webpage-link-and-twitter-scraping.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ambito-porta.php';
\WGA_Porta::init();
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-ambito-manifesto.php';
require_once WP_GPT_PLUGIN_DIR . 'includes/class-wp-gpt-free-rotte.php';

// SENZA QUESTO I FILE SOPRA NON FANNO NIENTE: definiscono le classi e basta.
// E' l'init() che le aggancia a WordPress e registra tools-manifest.
//
// ATTENZIONE ALLA BARRA ROVESCIATA. Questo file dichiara
// "namespace WP_GPT_Automation_Pro": senza la barra davanti, "new WGA_Ambito"
// diventa WP_GPT_Automation_Pro\WGA_Ambito, che non esiste. Quando la classe
// non si trova parte l'autoloader, e uno di quelli registrati qui chiude il
// processo invece di lasciar cadere la cosa: il caricamento muore in silenzio,
// senza errore e senza log.
add_action('plugins_loaded', function () {
    \WGA_Chiavi::init();
    (new \WGA_Credenziali())->init();
    (new \WGA_Ambito())->init();
    (new \WGA_Consegna())->init();
    (new \WGA_Guida())->init();
    (new \WGA_Manifesto())->init();
    WP_GPT_Free_Rotte::init();   // il free espone solo domotica e memoria
}, 5);




// Attivazione e disattivazione del plugin.
register_activation_hook(__FILE__, ['WP_GPT_Automation_Pro\WP_GPT_Activation', 'activate']);
register_deactivation_hook(__FILE__, ['WP_GPT_Automation_Pro\WP_GPT_Deactivation', 'deactivate']);

// Inizializza il plugin.
function wp_gpt_automation_free_init() {
    WP_GPT_Activation::maybe_run_db_upgrades();

    // Inizializzazione Licenza Server
    WP_GPT_License_Check::init();

    // Inizializza API
    WP_GPT_API::init();
    
    // Inizializza Admin base e impostazioni di validazione
    WP_GPT_Admin_Settings::init();

    // Inizializzazione moduli permessi in Free
    WP_GPT_SSH_Admin::init();
    WP_GPT_Auth_Log_Admin::init();

    // Boot della Memoria base
    if (class_exists('\WpGpt\AutomationPro\Memory\Memory_Manager')) {
        global $wp_gpt_memory_manager;
        $wp_gpt_memory_manager = new \WpGpt\AutomationPro\Memory\Memory_Manager();
        WP_GPT_Memory_Admin::init();
    }

    // La rotta della casa la registra WP_GPT_HomeAssistant, una sola volta.
    // Qui prima si chiamava anche l'init() di WP_GPT_HomeAssistant_Dashboard_Route,
    // che ne registrava una seconda col nome vecchio: quella classe ora e' solo il
    // gestore a cui smista() passa le azioni, e di init() non ne ha piu'.

    // Inizializza Schema & Guide UI
    WP_GPT_Schema_Guide::init();
}
add_action('plugins_loaded', 'WP_GPT_Automation_Pro\wp_gpt_automation_free_init');

// Imposta un User-Agent personalizzato per le richieste HTTP del plugin.
add_filter('http_request_args', function ($args, $url) {
    if (strpos($url, 'wp-gpt') !== false) {
        $args['user-agent'] = 'Mozilla/5.0 (compatible; WP-GPT-Automation-Free; ' . home_url() . ')';
        if (isset($args['method']) && $args['method'] === 'HEAD') {
            $args['method'] = 'GET';
        }
    }
    return $args;
}, 10, 2);
