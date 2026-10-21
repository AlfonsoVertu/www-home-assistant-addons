<?php
/**
 * Plugin Name: VWGK Security Hardening
 * Plugin URI: https://virtualgate.workingwithweb.eu/
 * Description: Applica hardening WordPress noto al container Virtual World Gateway e documenta in una pagina di backend ogni mitigazione (cosa, come, perché, riferimenti CVE dove applicabile). NB: non sostituisce gli aggiornamenti di WordPress core — mitiga classi di attacco note e riduce la superficie.
 * Version: 1.0.0
 * Author: Working With Web
 * Text Domain: vwgk-security
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('DISALLOW_FILE_EDIT')) {
    // Blocca l'editor di temi/plugin (mitigazione post-compromissione)
    define('DISALLOW_FILE_EDIT', true);
}

class VWGK_Security
{
    const OPT_PREFIX = 'vwgks_';

    /** Catalogo mitigazioni: ognuna applicata solo se la sua opzione è attiva. */
    public static function catalog(): array
    {
        return [
            'xmlrpc' => [
                'title' => 'Disabilita XML-RPC',
                'cve'   => 'CVE-2013-0235 (pingback SSRF) + abuso system.multicall',
                'what'  => 'Spegne xmlrpc.php e la funzione pingback.',
                'how'   => 'Filtri xmlrpc_enabled/xmlrpc_methods e blocco pingback_ping; rimuove l\'header X-Pingback.',
                'why'   => 'XML-RPC è sfruttato per amplificazione DDoS via pingback (SSRF) e brute-force massivo con system.multicall.',
            ],
            'rest_users' => [
                'title' => 'Blocca enumerazione utenti via REST',
                'cve'   => 'Divulgazione username via /wp-json/wp/v2/users (WP ≥4.7) — hardening',
                'what'  => 'Nasconde l\'endpoint REST degli utenti ai non autenticati.',
                'how'   => 'Filtro rest_endpoints rimuove /wp/v2/users e /users/(?P<id>) se l\'utente non è loggato.',
                'why'   => 'L\'endpoint espone gli username reali, utili per attacchi di forza bruta mirati.',
            ],
            'author_enum' => [
                'title' => 'Blocca enumerazione autori (?author=N)',
                'cve'   => 'Author enumeration — hardening (nessun CVE core)',
                'what'  => 'Impedisce il redirect ?author=N -> /author/username che rivela lo username.',
                'how'   => 'Intercetta la query var author su richieste frontend non autenticate e risponde 403.',
                'why'   => 'Permette di mappare gli username del sito senza autenticazione.',
            ],
            'fingerprint' => [
                'title' => 'Rimuovi fingerprint di versione',
                'cve'   => 'Riduce il match automatico di exploit su versioni note — hardening',
                'what'  => 'Rimuove il meta generator e i ?ver= dagli asset.',
                'how'   => 'remove_action wp_head generator + filtri style_loader_src/script_loader_src.',
                'why'   => 'Conoscere la versione esatta facilita l\'uso di exploit specifici per quella release.',
            ],
            'headers' => [
                'title' => 'Header di sicurezza HTTP',
                'cve'   => 'Clickjacking / MIME sniffing — hardening',
                'what'  => 'Aggiunge X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.',
                'how'   => 'Action send_headers imposta gli header su ogni risposta frontend.',
                'why'   => 'Riduce clickjacking, sniffing dei tipi MIME e leak del referrer.',
            ],
            'login_errors' => [
                'title' => 'Messaggi di login generici',
                'cve'   => 'Username enumeration in login — hardening',
                'what'  => 'Uniforma i messaggi di errore del login.',
                'how'   => 'Filtro login_errors sostituisce il testo con un messaggio generico.',
                'why'   => 'I messaggi predefiniti rivelano se uno username esiste.',
            ],
            'disable_file_edit' => [
                'title' => 'Disabilita editor file di temi/plugin',
                'cve'   => 'Mitigazione post-compromissione — hardening (DISALLOW_FILE_EDIT)',
                'what'  => 'Rimuove l\'editor di codice dalla dashboard.',
                'how'   => 'Costante DISALLOW_FILE_EDIT definita dal plugin.',
                'why'   => 'Un admin compromesso non può iniettare codice PHP dal pannello.',
            ],
        ];
    }

    public static function is_on(string $id): bool
    {
        return get_option(self::OPT_PREFIX . $id, '1') === '1';
    }

    public static function activate(): void
    {
        foreach (array_keys(self::catalog()) as $id) {
            if (get_option(self::OPT_PREFIX . $id, null) === null) {
                add_option(self::OPT_PREFIX . $id, '1');
            }
        }
    }

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);

        if (self::is_on('xmlrpc')) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods', function ($m) { unset($m['pingback.ping'], $m['pingback.extensions.getPingbacks']); return $m; });
            add_filter('wp_headers', function ($h) { unset($h['X-Pingback']); return $h; });
            add_filter('bloginfo_url', function ($output, $show) { return $show === 'pingback_url' ? '' : $output; }, 10, 2);
        }

        if (self::is_on('rest_users')) {
            add_filter('rest_endpoints', function ($endpoints) {
                if (is_user_logged_in()) {
                    return $endpoints;
                }
                foreach (['/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)'] as $route) {
                    if (isset($endpoints[$route])) {
                        unset($endpoints[$route]);
                    }
                }
                return $endpoints;
            });
        }

        if (self::is_on('author_enum')) {
            add_action('template_redirect', function () {
                if (is_admin() || is_user_logged_in()) {
                    return;
                }
                if (!empty($_GET['author']) || is_author()) {
                    wp_die(__('Accesso non consentito.', 'vwgk-security'), '', ['response' => 403]);
                }
            }, 1);
        }

        if (self::is_on('fingerprint')) {
            remove_action('wp_head', 'wp_generator');
            add_filter('the_generator', '__return_empty_string');
            $strip = function ($src) {
                return $src ? remove_query_arg('ver', $src) : $src;
            };
            add_filter('style_loader_src', $strip, 9999);
            add_filter('script_loader_src', $strip, 9999);
        }

        if (self::is_on('headers')) {
            add_action('send_headers', function () {
                if (is_admin()) {
                    return;
                }
                header('X-Frame-Options: SAMEORIGIN');
                header('X-Content-Type-Options: nosniff');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
            });
        }

        if (self::is_on('login_errors')) {
            add_filter('login_errors', function () {
                return __('Credenziali non valide.', 'vwgk-security');
            });
        }
    }

    public static function register(): void
    {
        foreach (array_keys(self::catalog()) as $id) {
            register_setting('vwgks_settings', self::OPT_PREFIX . $id, [
                'sanitize_callback' => function ($v) { return (!empty($v) && $v !== '0') ? '1' : '0'; },
            ]);
        }
    }

    public static function menu(): void
    {
        add_menu_page(
            __('Sicurezza VWGK', 'vwgk-security'),
            __('Sicurezza', 'vwgk-security'),
            'manage_options',
            'vwgk-security',
            [__CLASS__, 'render'],
            'dashicons-shield',
            59
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $cat = self::catalog();
        $on = 0;
        foreach (array_keys($cat) as $id) {
            if (self::is_on($id)) {
                $on++;
            }
        }
        ?>
        <div class="wrap">
            <h1>🛡️ <?php esc_html_e('VWGK Security Hardening', 'vwgk-security'); ?></h1>
            <p><?php printf(esc_html__('Mitigazioni attive: %1$d / %2$d. Ogni voce spiega cosa risolve, come e perché.', 'vwgk-security'), (int) $on, count($cat)); ?></p>
            <div class="notice notice-warning inline"><p>
                <?php esc_html_e('Questo plugin riduce la superficie d\'attacco e mitiga classi note; NON sostituisce gli aggiornamenti di WordPress core, temi e plugin (i CVE numerati del core si chiudono aggiornando).', 'vwgk-security'); ?>
            </p></div>
            <form method="post" action="options.php">
                <?php settings_fields('vwgks_settings'); ?>
                <table class="widefat striped" style="max-width:1100px;margin-top:1rem;">
                    <thead><tr>
                        <th style="width:60px;"><?php esc_html_e('Attiva', 'vwgk-security'); ?></th>
                        <th><?php esc_html_e('Mitigazione', 'vwgk-security'); ?></th>
                        <th><?php esc_html_e('CVE / Riferimento', 'vwgk-security'); ?></th>
                        <th><?php esc_html_e('Cosa / Come / Perché', 'vwgk-security'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($cat as $id => $m): ?>
                        <tr>
                            <td><input type="checkbox" name="<?php echo esc_attr(self::OPT_PREFIX . $id); ?>" value="1" <?php checked(self::is_on($id)); ?> /></td>
                            <td><strong><?php echo esc_html($m['title']); ?></strong></td>
                            <td><code style="white-space:normal;"><?php echo esc_html($m['cve']); ?></code></td>
                            <td>
                                <div><strong><?php esc_html_e('Cosa:', 'vwgk-security'); ?></strong> <?php echo esc_html($m['what']); ?></div>
                                <div><strong><?php esc_html_e('Come:', 'vwgk-security'); ?></strong> <?php echo esc_html($m['how']); ?></div>
                                <div><strong><?php esc_html_e('Perché:', 'vwgk-security'); ?></strong> <?php echo esc_html($m['why']); ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button(__('Salva mitigazioni', 'vwgk-security')); ?>
            </form>
        </div>
        <?php
    }
}

register_activation_hook(__FILE__, ['VWGK_Security', 'activate']);
add_action('plugins_loaded', ['VWGK_Security', 'init']);
