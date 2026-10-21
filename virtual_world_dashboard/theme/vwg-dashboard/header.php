<?php if (!defined('ABSPATH')) exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<header class="vwg-header">
  <div class="brand"><?php bloginfo('name'); ?><small><?php esc_html_e('Home Assistant Dashboard', 'vwg-dashboard'); ?></small></div>
  <div class="vwg-user">
    <?php if (is_user_logged_in()): ?>
      <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>"><?php esc_html_e('Esci', 'vwg-dashboard'); ?></a>
    <?php else: ?>
      <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>"><?php esc_html_e('Accedi', 'vwg-dashboard'); ?></a>
    <?php endif; ?>
  </div>
</header>
<div class="vwg-layout">
  <nav class="vwg-nav"><?php vwg_dashboard_nav(); ?></nav>
  <main class="vwg-main">
