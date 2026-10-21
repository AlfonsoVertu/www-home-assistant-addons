<?php
/**
 * Template Name: VWGK Home Assistant Dashboard
 *
 * Standalone page template for the VWGK interactive dashboard.
 * Does NOT depend on theme header.php / footer.php to avoid deprecation warnings.
 * Layout settings are read from post_meta (saved via the VWGK Dashboard meta box in the editor).
 */

if (!defined('ABSPATH')) {
    exit;
}

// Auth check — force login if not authenticated
if (!is_user_logged_in()) {
    auth_redirect();
    exit;
}

// Read dashboard config from post_meta
global $post;
$layout = 'grid';
$panels = [
    'entities'    => ['visible' => true, 'order' => 1],
    'scripts'     => ['visible' => true, 'order' => 2],
    'automations' => ['visible' => true, 'order' => 3],
    'assistants'  => ['visible' => true, 'order' => 4],
    'chat'        => ['visible' => true, 'order' => 5],
];

if ($post) {
    $saved_layout = get_post_meta($post->ID, '_vwgk_dashboard_layout', true);
    if ($saved_layout) $layout = $saved_layout;

    $saved_panels = get_post_meta($post->ID, '_vwgk_dashboard_panels', true);
    if (is_array($saved_panels)) {
        $panels = array_merge($panels, $saved_panels);
    }
}

// Sort panels by order
uasort($panels, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));
$visible_panels = array_filter($panels, fn($p) => !empty($p['visible']));

// Shortcodes to render for each panel slot
$panel_shortcodes = [
    'entities'    => '[vwgk_ha_entities]',
    'scripts'     => '[vwgk_ha_scripts]',
    'automations' => '[vwgk_ha_automations]',
    'assistants'  => '[vwgk_ha_assistants]',
    'chat'        => '[vwgk_ha_chat]',
];

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php wp_title('|', true, 'right'); bloginfo('name'); ?></title>
    <?php wp_head(); ?>
    <style>
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
        }
        .vwgk-page-header {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            color: #fff;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .vwgk-page-header h1 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
        }
        .vwgk-dashboard-wrap {
            max-width: 1200px;
            margin: 24px auto;
            padding: 0 16px;
        }
        .vwgk-dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
        }
        .vwgk-dashboard-list > * {
            margin-bottom: 20px;
        }
        .vwgk-page-footer {
            text-align: center;
            padding: 16px;
            color: #999;
            font-size: 12px;
        }
    </style>
</head>
<body <?php body_class('vwgk-dashboard-page'); ?>>

<header class="vwgk-page-header">
    <h1><?php 
        if ($post) {
            echo esc_html(get_the_title($post->ID));
        } else {
            bloginfo('name');
        }
    ?></h1>
    <span style="font-size:12px;opacity:0.7;"><?php bloginfo('name'); ?></span>
</header>

<div class="vwgk-dashboard-wrap">

    <?php
    // Render the page's own content (shortcodes in the editor body)
    if ($post) {
        setup_postdata($post);
        $content = get_the_content(null, false, $post);
        $content = apply_filters('the_content', $content);
        if (trim($content)) {
            echo '<div class="vwgk-page-content" style="margin-bottom:24px;">' . $content . '</div>';
        }
        wp_reset_postdata();
    }
    ?>

    <?php if (!empty($visible_panels)): ?>
    <div class="vwgk-dashboard-<?php echo esc_attr($layout); ?>">
        <?php foreach ($visible_panels as $panel_key => $panel_cfg):
            if (!isset($panel_shortcodes[$panel_key])) continue;
            echo do_shortcode($panel_shortcodes[$panel_key]);
        endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<footer class="vwgk-page-footer">
    <a href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a>
    &bull; Powered by VWGK
</footer>

<?php wp_footer(); ?>
</body>
</html>
