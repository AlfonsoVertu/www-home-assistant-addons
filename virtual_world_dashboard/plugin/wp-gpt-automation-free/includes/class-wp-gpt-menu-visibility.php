<?php
namespace WP_GPT_Automation_Pro;

if (!defined('ABSPATH')) {
    exit;
}

class WP_GPT_Menu_Visibility {
    public const OPTION_KEY = 'wp_gpt_menu_visibility';
    public const MENU_AUTOMATION_PRO = 'wp-gpt-automation-pro';
    public const MENU_SECRET_ADMIN   = 'wp-gpt-schema-secret-admin';

    private const MENU_PAGES = [
        self::MENU_AUTOMATION_PRO => [
            'wp-gpt-email-test' => [
                'path'    => '/admin.php?page=wp-gpt-email-test',
                'label'   => 'Email Tester',
                'default' => true,
            ],
            'wp-gpt-email-multiple' => [
                'path'    => '/admin.php?page=wp-gpt-email-multiple',
                'label'   => 'Email Multiplo',
                'default' => false,
            ],
            'wp-gpt-queue' => [
                'path'    => '/admin.php?page=wp-gpt-queue',
                'label'   => 'Gestione Coda',
                'default' => false,
            ],
            'wp-gpt-auth-logs' => [
                'path'    => '/admin.php?page=wp-gpt-auth-logs',
                'label'   => 'Auth Logs',
                'default' => false,
            ],
            'wp-gpt-email-ssh-transients' => [
                'path'    => '/admin.php?page=wp-gpt-email-ssh-transients',
                'label'   => 'Email & SSH Transients',
                'default' => false,
            ],
            'wp-gpt-memory-chunks' => [
                'path'    => '/admin.php?page=wp-gpt-memory-chunks',
                'label'   => 'Memory Chunks',
                'default' => false,
            ],
            'wp-gpt-ssh-connections' => [
                'path'    => '/admin.php?page=wp-gpt-ssh-connections',
                'label'   => 'SSH Connections',
                'default' => false,
            ],
            'wp-gpt-transcriptions' => [
                'path'    => '/admin.php?page=wp-gpt-transcriptions',
                'label'   => 'Trascrizioni YouTube',
                'default' => false,
            ],
            'wp-gpt-memory-manager' => [
                'path'    => '/admin.php?page=wp-gpt-memory-manager',
                'label'   => 'Memory Manager',
                'default' => true,
            ],
            'wp-gpt-placeholders' => [
                'path'    => '/admin.php?page=wp-gpt-placeholders',
                'label'   => 'Placeholders',
                'default' => true,
            ],
            'wp-gpt-n8n-connector' => [
                'path'    => '/admin.php?page=wp-gpt-n8n-connector',
                'label'   => 'n8n Connector',
                'default' => true,
            ],
        ],
        self::MENU_SECRET_ADMIN => [
            'wp-gpt-vector-files' => [
                'path'    => '/admin.php?page=wp-gpt-vector-files',
                'label'   => 'Vector Store Files',
                'default' => false,
            ],
            'wp-gpt-mcp-admin' => [
                'path'    => '/admin.php?page=wp-gpt-mcp-admin',
                'label'   => 'MCP Server',
                'default' => false,
            ],
            'wp-gpt-openai-oauth' => [
                'path'    => '/admin.php?page=wp-gpt-openai-oauth',
                'label'   => 'OpenAI OAuth',
                'default' => false,
            ],
            'wp-gpt-v2-routes' => [
                'path'    => '/admin.php?page=wp-gpt-v2-routes',
                'label'   => 'v2 Routes',
                'default' => false,
            ],
            'wp-gpt-v2-monitor' => [
                'path'    => '/admin.php?page=wp-gpt-v2-monitor',
                'label'   => 'v2 Output Monitor',
                'default' => false,
            ],
            'wp-gpt-swagger-ui' => [
                'path'    => '/admin.php?page=wp-gpt-swagger-ui',
                'label'   => 'API Docs',
                'default' => false,
            ],
        ],
    ];

    public static function get_menu_pages($menu_slug) {
        return self::MENU_PAGES[$menu_slug] ?? [];
    }

    public static function get_default_visibility() {
        $defaults = [];

        foreach (self::MENU_PAGES as $menu_slug => $pages) {
            $defaults[$menu_slug] = [];
            foreach ($pages as $page_slug => $config) {
                $defaults[$menu_slug][$page_slug] = !empty($config['default']);
            }
        }

        return $defaults;
    }

    public static function get_visibility() {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $defaults = self::get_default_visibility();

        foreach ($stored as $menu_slug => $pages) {
            if (!isset($defaults[$menu_slug]) || !is_array($pages)) {
                continue;
            }

            foreach ($pages as $page_slug => $enabled) {
                if (!array_key_exists($page_slug, $defaults[$menu_slug])) {
                    continue;
                }

                $defaults[$menu_slug][$page_slug] = (bool) $enabled;
            }
        }

        return $defaults;
    }

    public static function get_menu_visibility($menu_slug) {
        $visibility = self::get_visibility();
        return $visibility[$menu_slug] ?? [];
    }

    public static function is_page_enabled($menu_slug, $page_slug) {
        $visibility = self::get_visibility();
        return isset($visibility[$menu_slug][$page_slug]) ? (bool) $visibility[$menu_slug][$page_slug] : false;
    }

    public static function save_menu_visibility($menu_slug, array $enabled_pages) {
        $visibility = self::get_visibility();

        if (!isset($visibility[$menu_slug])) {
            return false;
        }

        $enabled_pages = array_map('sanitize_text_field', $enabled_pages);

        foreach ($visibility[$menu_slug] as $page_slug => $current) {
            $visibility[$menu_slug][$page_slug] = in_array($page_slug, $enabled_pages, true);
        }

        update_option(self::OPTION_KEY, $visibility);

        return true;
    }
}
