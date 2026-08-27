<?php get_header(); while(have_posts()): the_post(); ?>
<section class="page-hero"><div class="shell narrow"><span class="eyebrow">COMIKER91</span><h1><?php the_title(); ?></h1></div></section>
<section class="section"><div class="shell narrow content-card prose"><?php the_content(); ?></div></section>
<?php endwhile; get_footer(); ?>
