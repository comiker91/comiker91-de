<?php
if (!defined('ABSPATH')) exit;

/** Technical SEO helpers for the comiker91 theme. */
function c91_seo_description() {
    if (is_front_page()) return get_theme_mod('c91_hero_text', 'Gaming, Streams, Videos, News und Community von comiker91.');
    if (is_singular()) {
        $post = get_queried_object();
        if ($post && has_excerpt($post)) return wp_strip_all_tags(get_the_excerpt($post));
        if ($post) return wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_content)), 28, '…');
    }
    if ((int) get_query_var('c91_news') === 1) return 'News, Gaming, Streams und Projekte von comiker91.';
    if (is_post_type_archive('streamer')) return 'Streamer und Creator aus der comiker91 Community.';
    return get_bloginfo('description');
}

function c91_seo_canonical_url() {
    if (is_front_page()) return home_url('/');
    if ((int) get_query_var('c91_news') === 1) {
        $paged = max(1, (int) get_query_var('paged'));
        return $paged > 1 ? home_url('/news/page/' . $paged . '/') : home_url('/news/');
    }
    if (is_singular()) return get_permalink();
    if (is_post_type_archive()) return get_post_type_archive_link(get_query_var('post_type'));
    return '';
}

function c91_seo_head() {
    if (is_admin() || is_feed() || is_robots()) return;
    $description = c91_seo_description();
    $canonical = c91_seo_canonical_url();
    if ($description) echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
    if ($canonical) echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";

    if (!is_front_page()) {
        $title = wp_get_document_title();
        echo '<meta property="og:type" content="' . (is_singular('post') ? 'article' : 'website') . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        if ($description) echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        if ($canonical) echo '<meta property="og:url" content="' . esc_url($canonical) . '">' . "\n";
        if (is_singular() && has_post_thumbnail()) {
            $image = get_the_post_thumbnail_url(get_queried_object_id(), 'full');
            if ($image) echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        }
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    }
}
add_action('wp_head', 'c91_seo_head', 4);

function c91_breadcrumb_items() {
    if (is_front_page()) return [];
    $items = [['name' => 'Home', 'url' => home_url('/')]];
    if ((int) get_query_var('c91_news') === 1) {
        $items[] = ['name' => 'News', 'url' => home_url('/news/')];
    } elseif (is_singular('post')) {
        $items[] = ['name' => 'News', 'url' => home_url('/news/')];
        $items[] = ['name' => get_the_title(), 'url' => get_permalink()];
    } elseif (is_singular('streamer')) {
        $items[] = ['name' => 'Streamer', 'url' => get_post_type_archive_link('streamer')];
        $items[] = ['name' => get_the_title(), 'url' => get_permalink()];
    } elseif (is_post_type_archive('streamer')) {
        $items[] = ['name' => 'Streamer', 'url' => get_post_type_archive_link('streamer')];
    } elseif (is_page()) {
        $ancestors = array_reverse(get_post_ancestors(get_the_ID()));
        foreach ($ancestors as $ancestor) $items[] = ['name' => get_the_title($ancestor), 'url' => get_permalink($ancestor)];
        $items[] = ['name' => get_the_title(), 'url' => get_permalink()];
    }
    return $items;
}

function c91_breadcrumbs() {
    $items = c91_breadcrumb_items();
    if (count($items) < 2) return;
    echo '<nav class="c91-breadcrumbs shell narrow" aria-label="Breadcrumb"><ol>';
    $last = count($items) - 1;
    foreach ($items as $i => $item) {
        echo '<li>';
        if ($i !== $last) echo '<a href="' . esc_url($item['url']) . '">' . esc_html($item['name']) . '</a><span aria-hidden="true">›</span>';
        else echo '<span aria-current="page">' . esc_html($item['name']) . '</span>';
        echo '</li>';
    }
    echo '</ol></nav>';
}

function c91_schema_graph() {
    if (is_admin()) return;
    $graph = [];
    $graph[] = [
        '@type' => 'WebSite', '@id' => home_url('/#website'), 'url' => home_url('/'),
        'name' => 'comiker91', 'description' => get_bloginfo('description'), 'inLanguage' => get_bloginfo('language')
    ];
    $graph[] = [
        '@type' => 'Person', '@id' => home_url('/#person'), 'name' => 'comiker91', 'url' => home_url('/'),
        'sameAs' => array_values(array_filter([
            get_theme_mod('c91_twitch_url', 'https://www.twitch.tv/comiker91'),
            get_theme_mod('c91_youtube_url', 'https://www.youtube.com/@comiker91'),
            get_theme_mod('c91_instagram_url', 'https://www.instagram.com/comiker91')
        ]))
    ];
    $crumbs = c91_breadcrumb_items();
    if (count($crumbs) > 1) {
        $list = [];
        foreach ($crumbs as $i => $item) $list[] = ['@type'=>'ListItem','position'=>$i+1,'name'=>$item['name'],'item'=>$item['url']];
        $graph[] = ['@type'=>'BreadcrumbList','@id'=>c91_seo_canonical_url().'#breadcrumb','itemListElement'=>$list];
    }
    if (is_singular('post')) {
        $post_id = get_queried_object_id();
        $article = [
            '@type'=>'Article','@id'=>get_permalink($post_id).'#article','mainEntityOfPage'=>get_permalink($post_id),
            'headline'=>get_the_title($post_id),'datePublished'=>get_the_date(DATE_W3C, $post_id),
            'dateModified'=>get_the_modified_date(DATE_W3C, $post_id),'author'=>['@id'=>home_url('/#person')],
            'publisher'=>['@id'=>home_url('/#person')],'inLanguage'=>get_bloginfo('language')
        ];
        if (has_post_thumbnail($post_id)) $article['image'] = [get_the_post_thumbnail_url($post_id, 'full')];
        $graph[] = $article;
    }
    echo '<script type="application/ld+json">' . wp_json_encode(['@context'=>'https://schema.org','@graph'=>$graph], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
add_action('wp_head', 'c91_schema_graph', 30);

function c91_seo_robots($robots) {
    if (is_search() || is_404()) {
        $robots['noindex'] = true;
        $robots['follow'] = true;
    }
    return $robots;
}
add_filter('wp_robots', 'c91_seo_robots');
