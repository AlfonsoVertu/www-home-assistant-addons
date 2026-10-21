<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * VWGK Dashboard Metabox
 *
 * Adds a WordPress meta box in the page editor right sidebar
 * to configure the VWGK HA Dashboard template options.
 * Config is stored as post_meta, NOT shown on the frontend.
 */
class VWGK_Dashboard_Metabox
{
    const META_LAYOUT = '_vwgk_dashboard_layout';
    const META_PANELS = '_vwgk_dashboard_panels';

    public static function init(): void
    {
        add_action('add_meta_boxes', [__CLASS__, 'register']);
        add_action('save_post_page', [__CLASS__, 'save'], 10, 2);
    }

    public static function register(): void
    {
        add_meta_box(
            'vwgk_dashboard_settings',
            '🏠 VWGK Dashboard Settings',
            [__CLASS__, 'render'],
            'page',
            'side',
            'default'
        );
    }

    public static function render(\WP_Post $post): void
    {
        $layout = get_post_meta($post->ID, self::META_LAYOUT, true) ?: 'grid';
        $panels = get_post_meta($post->ID, self::META_PANELS, true);

        $defaults = [
            'entities'    => ['visible' => true, 'order' => 1],
            'scripts'     => ['visible' => true, 'order' => 2],
            'automations' => ['visible' => true, 'order' => 3],
            'assistants'  => ['visible' => true, 'order' => 4],
            'chat'        => ['visible' => true, 'order' => 5],
        ];

        if (!is_array($panels)) {
            $panels = $defaults;
        } else {
            $panels = array_merge($defaults, $panels);
        }

        $labels = [
            'entities'    => 'Entità HA',
            'scripts'     => 'Script',
            'automations' => 'Automazioni',
            'assistants'  => 'Assistenti',
            'chat'        => 'Chat Assistente',
        ];

        wp_nonce_field('vwgk_dashboard_save', 'vwgk_dashboard_nonce');
        ?>
        <div style="font-size:12px;">
            <label style="display:block;margin-bottom:6px;font-weight:600;">Stile Layout</label>
            <select name="vwgk_layout" style="width:100%;margin-bottom:12px;">
                <option value="grid" <?php selected($layout, 'grid'); ?>>Griglia Responsiva</option>
                <option value="list" <?php selected($layout, 'list'); ?>>Lista Semplice</option>
            </select>

            <p style="font-weight:600;margin-bottom:4px;">Pannelli visibili</p>
            <p style="color:#555;margin-bottom:8px;font-size:11px;">✔ = visibile &nbsp;|&nbsp; # = ordine</p>

            <table style="width:100%;border-collapse:collapse;">
                <?php foreach ($labels as $key => $label): ?>
                <tr>
                    <td style="padding:3px 0;">
                        <input type="checkbox"
                               name="vwgk_panels[<?php echo esc_attr($key); ?>][visible]"
                               value="1"
                               <?php checked(!empty($panels[$key]['visible'])); ?> />
                    </td>
                    <td style="padding:3px 4px;">
                        <input type="number"
                               name="vwgk_panels[<?php echo esc_attr($key); ?>][order]"
                               value="<?php echo esc_attr($panels[$key]['order'] ?? 1); ?>"
                               min="1" max="10"
                               style="width:40px;" />
                    </td>
                    <td style="padding:3px 0;"><?php echo esc_html($label); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php
    }

    public static function save(int $post_id, \WP_Post $post): void
    {
        if (
            !isset($_POST['vwgk_dashboard_nonce']) ||
            !wp_verify_nonce($_POST['vwgk_dashboard_nonce'], 'vwgk_dashboard_save') ||
            defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ||
            !current_user_can('edit_page', $post_id)
        ) {
            return;
        }

        $layout = sanitize_text_field($_POST['vwgk_layout'] ?? 'grid');
        update_post_meta($post_id, self::META_LAYOUT, $layout);

        $panels_raw = $_POST['vwgk_panels'] ?? [];
        $panels_clean = [];
        $allowed = ['entities', 'scripts', 'automations', 'assistants', 'chat'];

        foreach ($allowed as $key) {
            $panels_clean[$key] = [
                'visible' => !empty($panels_raw[$key]['visible']),
                'order'   => isset($panels_raw[$key]['order']) ? max(1, (int) $panels_raw[$key]['order']) : 1,
            ];
        }

        update_post_meta($post_id, self::META_PANELS, $panels_clean);
    }
}
