<?php
namespace WP_GPT_Automation_Pro;

class WP_GPT_SSH_Admin {
    const OPTION_GROUP = 'wp_gpt_ssh_connections';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function add_menu() {
        if (!WP_GPT_Menu_Visibility::is_page_enabled(WP_GPT_Menu_Visibility::MENU_AUTOMATION_PRO, 'wp-gpt-ssh-connections')) {
            return;
        }

        add_submenu_page(
            'wp-gpt-automation-pro',
            __('SSH Connections', 'wp-gpt-automation-pro'),
            __('SSH Connections', 'wp-gpt-automation-pro'),
            'manage_options',
            'wp-gpt-ssh-connections',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting(self::OPTION_GROUP, 'wp_gpt_ssh_host');
        register_setting(self::OPTION_GROUP, 'wp_gpt_ssh_port');
        register_setting(self::OPTION_GROUP, 'wp_gpt_ssh_username');
        register_setting(self::OPTION_GROUP, 'wp_gpt_ssh_password');
        register_setting(self::OPTION_GROUP, 'wp_gpt_ssh_passive_enabled');

        add_settings_section(
            'wp_gpt_ssh_section',
            __('Impostazioni connessione SSH', 'wp-gpt-automation-pro'),
            null,
            self::OPTION_GROUP
        );

        add_settings_field(
            'wp_gpt_ssh_host',
            __('Hostname', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_host_field'],
            self::OPTION_GROUP,
            'wp_gpt_ssh_section'
        );

        add_settings_field(
            'wp_gpt_ssh_port',
            __('Porta', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_port_field'],
            self::OPTION_GROUP,
            'wp_gpt_ssh_section'
        );

        add_settings_field(
            'wp_gpt_ssh_username',
            __('Username', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_username_field'],
            self::OPTION_GROUP,
            'wp_gpt_ssh_section'
        );

        add_settings_field(
            'wp_gpt_ssh_password',
            __('Password', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_password_field'],
            self::OPTION_GROUP,
            'wp_gpt_ssh_section'
        );

        add_settings_field(
            'wp_gpt_ssh_passive_enabled',
            __('Attiva connessione passiva', 'wp-gpt-automation-pro'),
            [__CLASS__, 'render_passive_field'],
            self::OPTION_GROUP,
            'wp_gpt_ssh_section'
        );
    }

    public static function render_host_field() {
        $value = esc_attr(get_option('wp_gpt_ssh_host', ''));
        echo '<input type="text" name="wp_gpt_ssh_host" value="' . $value . '" class="regular-text" />';
    }

    public static function render_port_field() {
        $value = esc_attr(get_option('wp_gpt_ssh_port', '22'));
        echo '<input type="number" name="wp_gpt_ssh_port" value="' . $value . '" class="small-text" />';
    }

    public static function render_username_field() {
        $value = esc_attr(get_option('wp_gpt_ssh_username', ''));
        echo '<input type="text" name="wp_gpt_ssh_username" value="' . $value . '" class="regular-text" />';
    }

    public static function render_password_field() {
        $value = esc_attr(get_option('wp_gpt_ssh_password', ''));
        echo '<input type="password" name="wp_gpt_ssh_password" value="' . $value . '" class="regular-text" autocomplete="off" />';
    }

    public static function render_passive_field() {
        $checked = checked(1, get_option('wp_gpt_ssh_passive_enabled', 0), false);
        echo '<label><input type="checkbox" name="wp_gpt_ssh_passive_enabled" value="1" ' . $checked . ' /> ' . esc_html__('Usa le credenziali salvate se i parametri sono assenti nella richiesta.', 'wp-gpt-automation-pro') . '</label>';
    }

    public static function render_page() {
        echo '<div class="wrap wp-gpt-admin-page">';
        echo '<div class="wp-gpt-card">';
        echo '<div class="wp-gpt-card-header">';
        echo '<h1>' . esc_html__('SSH Connections', 'wp-gpt-automation-pro') . '</h1>';
        echo '<p>' . esc_html__('Configura le credenziali SSH da utilizzare nelle automazioni e nelle azioni remote.', 'wp-gpt-automation-pro') . '</p>';
        echo '</div>';
        echo '<div class="wp-gpt-card-body">';
        echo '<form method="post" action="options.php" class="wp-gpt-card-form">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::OPTION_GROUP);
        submit_button();
        echo '</form>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
}

WP_GPT_SSH_Admin::init();
