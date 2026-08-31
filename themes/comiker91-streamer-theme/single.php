<?php get_header(); while(have_posts()): the_post(); ?>
<article class="single-article" itemscope itemtype="https://schema.org/Article">
  <nav class="c91-breadcrumbs shell narrow" aria-label="Breadcrumb">
    <ol>
      <li><a href="<?php echo esc_url(home_url('/')); ?>">Home</a><span aria-hidden="true">›</span></li>
      <li><a href="<?php echo esc_url(home_url('/news/')); ?>">News</a><span aria-hidden="true">›</span></li>
      <li><span aria-current="page"><?php the_title(); ?></span></li>
    </ol>
  </nav>
  <section class="page-hero article-hero">
    <div class="shell narrow">
      <span class="eyebrow">NEWS • <time datetime="<?php echo esc_attr(get_the_date(DATE_W3C)); ?>" itemprop="datePublished"><?php echo esc_html(get_the_date('d.m.Y')); ?></time> • <?php echo esc_html(c91_reading_time()); ?> MIN. LESEZEIT</span>
      <h1 itemprop="headline"><?php the_title(); ?></h1>
      <?php if (has_excerpt()): ?>
        <p class="article-lead" itemprop="description"><?php echo esc_html(get_the_excerpt()); ?></p>
      <?php endif; ?>
      <p class="article-author">Von <span itemprop="author" itemscope itemtype="https://schema.org/Person"><span itemprop="name"><?php echo esc_html(get_the_author()); ?></span></span></p>
    </div>
  </section>

  <?php if(has_post_thumbnail()): ?>
    <div class="shell narrow hero-image article-featured-image" itemprop="image"><?php the_post_thumbnail('full', ['loading'=>'eager']); ?></div>
  <?php endif; ?>

  <section class="section article-section">
    <div class="shell narrow">
      <div class="content-card prose article-content" itemprop="articleBody">
        <?php the_content(); ?>
      </div>
      <div class="article-back">
        <a class="btn ghost" href="<?php echo esc_url(home_url('/news/')); ?>">← Weitere News</a>
      </div>
    </div>
  </section>
</article>
<?php endwhile; get_footer(); ?>