<?php get_header(); ?>
<section class="page-hero"><div class="shell"><span class="eyebrow">COMIKER91</span><h1><?php if(is_home()) echo 'News'; else the_archive_title(); ?></h1></div></section>
<section class="section"><div class="shell post-grid">
<?php if(have_posts()): while(have_posts()): the_post(); ?><article class="post-card"><a href="<?php the_permalink(); ?>" class="post-image"><?php if(has_post_thumbnail()) the_post_thumbnail('large'); else echo '<span>NEWS</span>'; ?></a><div class="post-body"><span class="meta"><?php echo esc_html(get_the_date('d.m.Y')); ?></span><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><p><?php echo esc_html(wp_trim_words(get_the_excerpt(),28)); ?></p><a class="text-link" href="<?php the_permalink(); ?>">Weiterlesen →</a></div></article><?php endwhile; endif; ?>
</div><div class="shell pagination"><?php the_posts_pagination(); ?></div></section>
<?php get_footer(); ?>
