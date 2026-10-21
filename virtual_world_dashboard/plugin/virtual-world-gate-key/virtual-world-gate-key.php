<?php
/**
 * Plugin Name: Virtual World Gate Key
 * Plugin URI: https://example.com/virtual-world-gate-key
 * Description: Middleware WordPress per Home Assistant e ponte verso WP GPT Automation Pro.
 * Version: 1.2.0
 * Author: Working With Web
 * Text Domain: virtual-world-gate-key
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VWGK_VERSION', '1.2.0');
define('VWGK_PATH', plugin_dir_path(__FILE__));
define('VWGK_URL', plugin_dir_url(__FILE__));
define('VWGK_PLUGIN_DIR', VWGK_PATH); // Fix for VWGK_Auth_Enforcer
define('VWGK_ASSETS_URL', VWGK_URL . 'assets/');

require_once VWGK_PATH . 'includes/class-vwgk-settings.php';
require_once VWGK_PATH . 'includes/class-vwgk-ha-client.php';
require_once VWGK_PATH . 'includes/class-vwgk-rest.php';
require_once VWGK_PATH . 'includes/class-vwgk-hardening.php';
require_once VWGK_PATH . 'includes/class-vwgk-ha-api.php';
require_once VWGK_PATH . 'includes/class-vwgk-ha-card-factory.php';
require_once VWGK_PATH . 'includes/class-vwgk-shortcodes.php';
require_once VWGK_PATH . 'includes/class-vwgk-auth-enforcer.php';
require_once VWGK_PATH . 'includes/class-vwgk-dashboard-metabox.php';
require_once VWGK_PATH . 'includes/class-vwgk-entity-manager.php';
// Il canale WebSocket: serve alle aree, che dal REST non si creano.
require_once VWGK_PATH . 'includes/class-vwgk-ha-ws.php';
require_once VWGK_PATH . 'includes/class-vwgk-ha-rooms.php';
require_once VWGK_PATH . 'includes/class-vwgk-cards.php';
require_once VWGK_PATH . 'includes/class-vwgk-rooms-rest.php';

register_activation_hook(__FILE__, ['VWGK_Settings', 'activate']);

add_action('plugins_loaded', function () {
    // NB: endpoint HA e long-lived token si configurano dai Settings del plugin
    // (nessuna credenziale hardcoded qui — vedi VWGK_Settings::render_page()).
    VWGK_Settings::init();
    VWGK_Hardening::init();
    VWGK_REST::init();
    VWGK_Shortcodes::init();
    VWGK_Auth_Enforcer::init();
    VWGK_Dashboard_Metabox::init();
    VWGK_Entity_Manager::init();
    VWGK_HA_Rooms::init();
    VWGK_Cards::init();
    VWGK_Rooms_Rest::init();
});
