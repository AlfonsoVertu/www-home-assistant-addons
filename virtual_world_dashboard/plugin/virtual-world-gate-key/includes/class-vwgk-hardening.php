<?php

if (!defined('ABSPATH')) {
    exit;
}

class VWGK_Hardening
{
    public static function init(): void
    {
        add_action('send_headers', [__CLASS__, 'send_noindex_headers']);
        add_action('template_redirect', [__CLASS__, 'force_login_only']);
        add_filter('login_redirect', [__CLASS__, 'login_redirect'], 10, 3);
        add_filter('xmlrpc_enabled', '__return_false');

        // Anti-indicizzazione / robots (quando vwgk_noindex_mode = 1)
        add_filter('robots_txt', [__CLASS__, 'robots_txt'], 99, 2);
        add_filter('wp_robots', [__CLASS__, 'wp_robots'], 99);
        add_action('init', [__CLASS__, 'enforce_blog_private']);

        add_action('do_feed', [__CLASS__, 'disable_feed'], 1);
        add_action('do_feed_rdf', [__CLASS__, 'disable_feed'], 1);
        add_action('do_feed_rss', [__CLASS__, 'disable_feed'], 1);
        add_action('do_feed_rss2', [__CLASS__, 'disable_feed'], 1);
        add_action('do_feed_atom', [__CLASS__, 'disable_feed'], 1);
    }

    private static function noindex_on(): bool
    {
        return get_option('vwgk_noindex_mode', '1') === '1';
    }

    public static function send_noindex_headers(): void
    {
        if (self::noindex_on()) {
            header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        }
    }

    /** robots.txt: blocca tutti i crawler. */
    public static function robots_txt($output, $public)
    {
        if (self::noindex_on()) {
            return "User-agent: *\nDisallow: /\n";
        }
        return $output;
    }

    /** meta robots: forza noindex,nofollow. */
    public static function wp_robots(array $robots): array
    {
        if (self::noindex_on()) {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
            unset($robots['max-image-preview'], $robots['max-snippet'], $robots['max-video-preview']);
        }
        return $robots;
    }

    /** Allinea l'opzione core "blog_public" (Impostazioni > Lettura) allo stato noindex. */
    public static function enforce_blog_private(): void
    {
        if (self::noindex_on() && get_option('blog_public') !== '0') {
            update_option('blog_public', '0');
        }
    }

    public static function force_login_only(): void
    {
        if (is_admin()) {
            return;
        }

        if (get_option('vwgk_login_only_mode', '1') !== '1') {
            return;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        $is_login_request = strpos($request_uri, 'wp-login.php') !== false;
        $is_rest_request = defined('REST_REQUEST') && REST_REQUEST;

        if (!is_user_logged_in() && !$is_login_request && !$is_rest_request) {
            wp_safe_redirect(wp_login_url());
            exit;
        }
    }

    public static function login_redirect($redirect_to, $requested_redirect_to, $user): string
    {
        return admin_url();
    }

    public static function disable_feed(): void
    {
        wp_die(__('Feed disabilitati su questo gateway.', 'virtual-world-gate-key'));
    }
}
