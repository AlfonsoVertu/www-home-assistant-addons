<?php if (!defined('ABSPATH')) exit; get_header(); ?>
<?php if (have_posts()): while (have_posts()): the_post(); ?>
  <article class="vwg-card">
    <h1><?php the_title(); ?></h1>
    <div class="vwg-content"><?php the_content(); ?></div>
  </article>
<?php endwhile; else: ?>
  <div class="vwg-card"><p><?php esc_html_e('Nessun contenuto.', 'vwg-dashboard'); ?></p></div>
<?php endif; get_footer(); ?>
