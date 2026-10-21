<?php

if (!defined('ABSPATH')) {
    exit;
}

class VWGK_Auth_Enforcer
{
    public static function init(): void
    {
        // Intercetta la richiesta prima della visualizzazione per forzare il login se ci sono shortcode sensibili
        add_action('template_redirect', [__CLASS__, 'enforce_auth_on_shortcodes']);

        // Registra il page template personalizzato nel frontend
        add_filter('theme_page_templates', [__CLASS__, 'register_ha_dashboard_template']);
        add_filter('template_include', [__CLASS__, 'load_ha_dashboard_template']);
    }

    public static function enforce_auth_on_shortcodes(): void
    {
        if (is_user_logged_in()) {
            return;
        }

        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        // Il content originale
        $content = $post->post_content;
        
        // Verifica se usa il template vwgk-ha-dashboard
        $template = get_post_meta($post->ID, '_wp_page_template', true);
        if ($template === 'vwgk-ha-dashboard') {
            auth_redirect();
            exit;
        }

        // Lista degli shortcode sensibili
        $sensitive_shortcodes = [
            'vwgk_ha_entities',
            'vwgk_ha_scripts',
            'vwgk_ha_automations',
            'vwgk_ha_assistants',
            'vwgk_ha_chat',
            'vwgk_room',
            'vwgk_card'
        ];

        foreach ($sensitive_shortcodes as $sc) {
            if (has_shortcode($content, $sc)) {
                auth_redirect();
                exit;
            }
        }
    }

    public static function register_ha_dashboard_template($templates)
    {
        $templates['vwgk-ha-dashboard'] = __('VWGK Home Assistant Dashboard', 'virtual-world-gate-key');
        return $templates;
    }

    public static function load_ha_dashboard_template($template)
    {
        global $post;

        if (!$post) {
            return $template;
        }

        $page_template = get_post_meta($post->ID, '_wp_page_template', true);
        $settings_page_id = (int) get_option('vwgk_ha_dashboard_page_id', 0);

        if ($page_template === 'vwgk-ha-dashboard' || ($settings_page_id > 0 && is_page($settings_page_id))) {
            // Usa path relativo al file corrente (includes/) per risalire alla root del plugin
            $plugin_root = plugin_dir_path(dirname(__FILE__));
            $plugin_template = $plugin_root . 'templates/ha-dashboard-template.php';
            if (file_exists($plugin_template)) {
                return $plugin_template;
            }
        }

        return $template;
    }
}
