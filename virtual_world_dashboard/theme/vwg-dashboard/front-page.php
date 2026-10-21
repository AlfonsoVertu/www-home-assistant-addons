<?php if (!defined('ABSPATH')) exit; get_header(); ?>
<h1><?php esc_html_e('Dashboard casa', 'vwg-dashboard'); ?></h1>
<?php if (!is_user_logged_in()): ?>
  <div class="vwg-card vwg-locked">
    <p><?php esc_html_e('Area riservata. Effettua il login per vedere stanze e dispositivi.', 'vwg-dashboard'); ?></p>
    <a class="button" href="<?php echo esc_url(wp_login_url(home_url('/'))); ?>"><?php esc_html_e('Accedi', 'vwg-dashboard'); ?></a>
  </div>
<?php else:
  $tree = vwg_dashboard_tree();
  if (!$tree): ?>
    <div class="vwg-card"><p><?php esc_html_e('Nessuna stanza ancora. Vai nel plugin VWGK e genera le pagine da Home Assistant.', 'vwg-dashboard'); ?></p></div>
  <?php else:
    foreach ($tree as $floor): ?>
      <h2><?php echo esc_html($floor['title']); ?></h2>
      <div class="vwg-grid">
        <?php foreach ($floor['rooms'] as $room):
          $ecl = get_post_meta($room['id'], '_vwgk_eclipse_note', true);
          $cnt = get_post_meta($room['id'], '_vwgk_entities', true);
          $n = is_string($cnt) ? count((array) json_decode($cnt, true)) : 0; ?>
          <a class="vwg-tile" href="<?php echo esc_url(get_permalink($room['id'])); ?>">
            <span class="t-name"><?php echo esc_html($room['title']); ?></span>
            <span class="t-meta"><?php echo (int) $n; ?> <?php esc_html_e('dispositivi', 'vwg-dashboard'); ?><?php echo $ecl ? ' · 🌒' : ''; ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach;
  endif;
endif; ?>
<?php get_footer(); ?>
