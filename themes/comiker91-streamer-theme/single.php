<?php get_header(); while(have_posts()): the_post(); ?>
<article class="single-article">
  <section class="page-hero article-hero">
    <div class="shell narrow">
      <span class="eyebrow">NEWS • <?php echo esc_html(get_the_date('d.m.Y')); ?> • <?php echo esc_html(c91_reading_time()); ?> MIN. LESEZEIT</span>
      <h1><?php the_title(); ?></h1>
      <?php if (has_excerpt()): ?>
        <p class="article-lead"><?php echo esc_html(get_the_excerpt()); ?></p>
      <?php endif; ?>
    </div>
  </section>

  <?php if(has_post_thumbnail()): ?>
    <div class="shell narrow hero-image article-featured-image"><?php the_post_thumbnail('full'); ?></div>
  <?php endif; ?>

  <section class="section article-section">
    <div class="shell narrow">
      <div class="content-card prose article-content">
        <?php the_content(); ?>
      </div>
      <div class="article-back">
        <a class="btn ghost" href="<?php echo esc_url(home_url('/news/')); ?>">← Weitere News</a>
      </div>
    </div>
  </section>
</article>
<?php endwhile; get_footer(); ?>
