<?php if (!defined('ABSPATH')) exit; get_header(); ?>
<?php while (have_posts()): the_post(); ?>
  <article class="vwg-card">
    <h1><?php the_title(); ?></h1>
    <?php $ecl = get_post_meta(get_the_ID(), '_vwgk_eclipse_note', true); if ($ecl): ?>
      <div class="vwg-card vwg-eclipse"><?php echo esc_html($ecl); ?></div>
    <?php endif; ?>
    <div class="vwg-content"><?php the_content(); ?></div>
  </article>
<?php endwhile; get_footer(); ?>
