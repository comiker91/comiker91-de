<?php
if (!defined('ABSPATH')) exit;
get_header();

$paged = max(1, (int) get_query_var('paged'));
$category_slug = isset($_GET['thema']) ? sanitize_title(wp_unslash($_GET['thema'])) : '';
$search_term = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

$args = [
    'post_type'           => 'post',
    'post_status'         => 'publish',
    'posts_per_page'      => 7,
    'paged'               => $paged,
    'ignore_sticky_posts' => false,
];

if ($category_slug) {
    $args['category_name'] = $category_slug;
}
if ($search_term) {
    $args['s'] = $search_term;
}

$news = new WP_Query($args);
$categories = get_categories(['hide_empty' => true]);
?>

<section class="news-hub-hero">
  <div class="shell">
    <span class="eyebrow">COMIKER91 NEWS</span>
    <div class="news-hub-hero-row">
      <div>
        <h1>News, Streams & Gaming.</h1>
        <p>Updates von mir, Gaming-Themen, Stream-Neuigkeiten und alles, was rund um comiker91 gerade einen Blick wert ist.</p>
      </div>
      <a class="btn ghost news-twitch-link" href="<?php echo esc_url(get_theme_mod('c91_twitch_url','https://www.twitch.tv/comiker91')); ?>" target="_blank" rel="noopener noreferrer"><span class="pulse"></span> Twitch ansehen</a>
    </div>
  </div>
</section>

<section class="news-toolbar-section">
  <div class="shell">
    <div class="news-toolbar">
      <div class="news-category-pills" aria-label="News Kategorien">
        <a class="<?php echo !$category_slug ? 'active' : ''; ?>" href="<?php echo esc_url(home_url('/news/')); ?>">Alle</a>
        <?php foreach ($categories as $cat): ?>
          <a class="<?php echo $category_slug === $cat->slug ? 'active' : ''; ?>" href="<?php echo esc_url(add_query_arg('thema', $cat->slug, home_url('/news/'))); ?>"><?php echo esc_html($cat->name); ?></a>
        <?php endforeach; ?>
      </div>
      <form class="news-search" method="get" action="<?php echo esc_url(home_url('/news/')); ?>" role="search">
        <?php if ($category_slug): ?><input type="hidden" name="thema" value="<?php echo esc_attr($category_slug); ?>"><?php endif; ?>
        <input type="search" name="q" value="<?php echo esc_attr($search_term); ?>" placeholder="News durchsuchen …" aria-label="News durchsuchen">
        <button type="submit">Suchen</button>
      </form>
    </div>
  </div>
</section>

<section class="section news-hub-section">
  <div class="shell">
    <?php if ($news->have_posts()): ?>
      <?php
      $i = 0;
      while ($news->have_posts()): $news->the_post();
        $i++;
        if ($i === 1 && $paged === 1 && !$search_term): ?>
          <article class="news-featured">
            <a class="news-featured-media" href="<?php the_permalink(); ?>">
              <?php if (has_post_thumbnail()): the_post_thumbnail('large'); else: ?><span class="news-placeholder">C91 NEWS</span><?php endif; ?>
            </a>
            <div class="news-featured-copy">
              <div class="news-meta"><?php echo esc_html(get_the_date('d.m.Y')); ?> <span>•</span> <?php echo esc_html(c91_reading_time()); ?> Min. Lesezeit</div>
              <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
              <p><?php echo esc_html(wp_trim_words(get_the_excerpt() ?: wp_strip_all_tags(get_the_content()), 30)); ?></p>
              <a class="news-read-more" href="<?php the_permalink(); ?>">Beitrag lesen →</a>
            </div>
          </article>
          <div class="news-section-heading">
            <div><span class="eyebrow">MEHR AUS DEM FEED</span><h2>Weitere Beiträge</h2></div>
          </div>
          <div class="news-card-grid">
        <?php else: ?>
          <?php if ($i === 1): ?><div class="news-card-grid"><?php endif; ?>
          <article class="news-card">
            <a class="news-card-media" href="<?php the_permalink(); ?>">
              <?php if (has_post_thumbnail()): the_post_thumbnail('medium_large'); else: ?><span class="news-placeholder">NEWS</span><?php endif; ?>
            </a>
            <div class="news-card-body">
              <div class="news-meta"><?php echo esc_html(get_the_date('d.m.Y')); ?> <span>•</span> <?php echo esc_html(c91_reading_time()); ?> Min.</div>
              <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
              <p><?php echo esc_html(wp_trim_words(get_the_excerpt() ?: wp_strip_all_tags(get_the_content()), 18)); ?></p>
              <a class="news-read-more" href="<?php the_permalink(); ?>">Weiterlesen →</a>
            </div>
          </article>
        <?php endif;
      endwhile; ?>
      </div>

      <?php
      $pagination = paginate_links([
          'base'      => trailingslashit(home_url('/news/page/%#%/')),
          'format'    => '',
          'current'   => $paged,
          'total'     => max(1, (int) $news->max_num_pages),
          'prev_text' => '← Zurück',
          'next_text' => 'Weiter →',
          'type'      => 'list',
          'add_args'  => array_filter([
              'thema' => $category_slug ?: null,
              'q'     => $search_term ?: null,
          ]),
      ]);
      if ($pagination): ?>
        <nav class="news-pagination" aria-label="News Seiten"><?php echo wp_kses_post($pagination); ?></nav>
      <?php endif; ?>

    <?php else: ?>
      <div class="news-empty">
        <span class="eyebrow">NICHTS GEFUNDEN</span>
        <h2>Hier ist gerade noch nichts.</h2>
        <p>Probier eine andere Kategorie oder einen anderen Suchbegriff.</p>
        <a class="btn ghost" href="<?php echo esc_url(home_url('/news/')); ?>">Alle News anzeigen</a>
      </div>
    <?php endif; wp_reset_postdata(); ?>
  </div>
</section>

<?php get_footer(); ?>
