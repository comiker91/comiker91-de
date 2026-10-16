<?php
/**
 * Pure PHP contract for comiker91 navigation, canonical, sitemap and news routing.
 * No WordPress database or external request required.
 */
error_reporting(E_ALL);
define('ABSPATH', __DIR__);
$GLOBALS['c91_test_context'] = [];
$GLOBALS['c91_test_hooks'] = [];
function add_action($hook, $callback, $priority = 10, $arity = 1) { $GLOBALS['c91_test_hooks'][$hook][] = $callback; }
function add_filter($hook, $callback, $priority = 10, $arity = 1) { $GLOBALS['c91_test_hooks'][$hook][] = $callback; }
function get_template_directory() { return dirname(__DIR__) . '/themes/comiker91-streamer-theme'; }
function get_query_var($name) { return $GLOBALS['c91_test_context'][$name] ?? 0; }
function home_url($path = '') { return 'https://comiker91.de' . $path; }
function get_theme_mod($name, $default = '') { return $default; }
function get_bloginfo($name) { return 'comiker91'; }
function is_admin() { return false; }
function is_feed() { return false; }
function is_robots() { return false; }
function is_front_page() { return (bool) ($GLOBALS['c91_test_context']['front'] ?? false); }
function is_singular($type = null) { return false; }
function is_post_type_archive($type = null) { return false; }
function is_category() { return (bool) ($GLOBALS['c91_test_context']['category'] ?? false); }
function is_tag() { return (bool) ($GLOBALS['c91_test_context']['tag'] ?? false); }
function is_author() { return (bool) ($GLOBALS['c91_test_context']['author'] ?? false); }
function is_tax($taxonomy) { return ($GLOBALS['c91_test_context']['tax'] ?? '') === $taxonomy; }
function is_page() { return (bool) ($GLOBALS['c91_test_context']['page'] ?? false); }
function is_search() { return false; }
function is_404() { return false; }
function get_queried_object_id() { return 222; }
function get_post_field($field, $id) { return $GLOBALS['c91_test_context']['page_slug'] ?? ''; }
function sanitize_title($value) { return strtolower(trim($value)); }
function sanitize_text_field($value) { return trim($value); }
function wp_unslash($value) { return stripslashes($value); }
$GLOBALS['c91_test_options'] = [];
$GLOBALS['c91_test_w3tc_flush_count'] = 0;
function get_option($name, $default = false) { return $GLOBALS['c91_test_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['c91_test_options'][$name] = $value; return true; }
function w3tc_flush_all() { $GLOBALS['c91_test_w3tc_flush_count']++; }
function assert_same($actual, $expected, $message) {
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL $message: " . var_export($actual, true) . " != " . var_export($expected, true) . "\n");
        exit(1);
    }
}
function assert_contains($haystack, $needle, $message) {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL $message: missing " . $needle . "\n");
        exit(1);
    }
}
require dirname(__DIR__) . '/themes/comiker91-streamer-theme/functions.php';
assert_same(in_array('c91_news_main_query', $GLOBALS['c91_test_hooks']['pre_get_posts'] ?? [], true), true, 'main query hook installed');
assert_same(in_array('c91_exclude_archive_sitemap', $GLOBALS['c91_test_hooks']['wpseo_sitemap_exclude_taxonomy'] ?? [], true), true, 'SEO hooks register before header');
assert_same(in_array('c91_flush_w3tc_on_theme_update', $GLOBALS['c91_test_hooks']['init'] ?? [], true), true, 'theme-version cache invalidation hook registered');
c91_flush_w3tc_on_theme_update();
assert_same($GLOBALS['c91_test_w3tc_flush_count'], 1, 'W3TC cache invalidated on theme change');
assert_same(get_option('c91_w3tc_last_flushed_theme'), C91_VERSION, 'cache version marker persisted');
c91_flush_w3tc_on_theme_update();
assert_same($GLOBALS['c91_test_w3tc_flush_count'], 1, 'same theme version never flushes again');
$GLOBALS['c91_test_options']['c91_w3tc_last_flushed_theme'] = 'previous-version';
c91_flush_w3tc_on_theme_update();
assert_same($GLOBALS['c91_test_w3tc_flush_count'], 2, 'next theme version flushes again');

$GLOBALS['c91_test_context'] = ['front' => true, 'c91_news' => 1, 'paged' => 1];
assert_same(c91_seo_canonical_url(), 'https://comiker91.de/news/', 'news canonical beats homepage detection');
assert_same(c91_news_yoast_title('Homepage'), 'News – comiker91', 'Yoast title for first news page');
assert_same(c91_news_yoast_description('By Comitement'), 'News, Gaming, Streams und Projekte von comiker91.', 'news description');
$GLOBALS['c91_test_context']['paged'] = 2;
assert_same(c91_seo_canonical_url(), 'https://comiker91.de/news/page/2/', 'news second page canonical');
assert_same(c91_news_yoast_canonical('https://comiker91.de/page/2/'), 'https://comiker91.de/news/page/2/', 'Yoast wrong canonical overridden');
assert_same(c91_news_yoast_title('Homepage'), 'News – Seite 2 – comiker91', 'Yoast paginated title');
assert_same(c91_news_yoast_description('By Comitement'), 'News, Gaming, Streams und Projekte von comiker91. Seite 2.', 'unique paginated description');

$_GET['thema'] = 'streaming';
assert_same(c91_seo_canonical_url(), 'https://comiker91.de/news/', 'filtered news canonicalizes to hub');
assert_same(c91_noindex_low_value_archive(), true, 'filtered news noindexed');
unset($_GET['thema']);
$GLOBALS['c91_test_context'] = ['tag' => true];
assert_same(c91_noindex_low_value_archive(), true, 'tags noindexed');
assert_same(c91_noindex_yoast_robots('index, follow'), 'noindex, follow', 'Yoast tags noindex');
assert_same(c91_seo_robots(['index' => true]), ['noindex' => true, 'follow' => true], 'WordPress robots fallback');
$GLOBALS['c91_test_context'] = ['category' => true];
assert_same(c91_noindex_low_value_archive(), true, 'categories noindexed');
$GLOBALS['c91_test_context'] = ['author' => true];
assert_same(c91_noindex_low_value_archive(), true, 'author archive noindexed');
$GLOBALS['c91_test_context'] = ['page' => true, 'page_slug' => '227-2'];
assert_same(c91_noindex_low_value_archive(), true, 'legacy 200-status placeholder noindexed');
$GLOBALS['c91_test_context'] = ['front' => true];
assert_same(c91_seo_canonical_url(), 'https://comiker91.de/', 'ordinary homepage unchanged');
assert_same(c91_noindex_low_value_archive(), false, 'homepage remains indexable');
assert_same(c91_news_yoast_canonical('original'), 'original', 'other pages canonical unchanged');

assert_same(c91_exclude_archive_sitemap(false, 'post_tag'), true, 'Yoast tag sitemap excluded');
assert_same(c91_exclude_archive_sitemap(false, 'category'), true, 'Yoast category sitemap excluded');
assert_same(c91_exclude_archive_sitemap(false, 'streamer_category'), true, 'Yoast streamer taxonomy excluded');
assert_same(c91_exclude_archive_sitemap(false, 'custom'), false, 'unrelated taxonomy preserved');
assert_same(c91_exclude_author_sitemap([(object) ['ID' => 1]]), [], 'Yoast redundant author sitemap excluded');
$placeholder = (object) ['post_type' => 'page', 'post_name' => '227-2'];
$normal = (object) ['post_type' => 'page', 'post_name' => 'kontakt'];
assert_same(c91_exclude_placeholder_page_sitemap(['loc' => 'x'], 'post', $placeholder), [], 'error placeholder omitted');
assert_same(c91_exclude_placeholder_page_sitemap(['loc' => 'x'], 'post', $normal), ['loc' => 'x'], 'other pages remain listed');

class C91TestMainQuery {
    public bool $is_home = true;
    public bool $is_main = true;
    public array $vars = [];
    public function __construct(array $vars) { $this->vars = $vars; }
    public function get($name) { return $this->vars[$name] ?? null; }
    public function set($name, $value) { $this->vars[$name] = $value; }
    public function is_main_query() { return $this->is_main; }
}
$q = new C91TestMainQuery(['c91_news' => 1]);
c91_news_main_query($q);
assert_same($q->get('posts_per_page'), 7, 'main query page size matches visible news');
assert_same($q->get('post_type'), 'post', 'main query only posts');
assert_same($q->get('post_status'), 'publish', 'only published articles');
assert_same($q->is_home, false, 'news not front page');
$_GET['thema'] = 'streaming';
$_GET['q'] = 'OBS';
$q = new C91TestMainQuery(['c91_news' => 1]);
c91_news_main_query($q);
assert_same($q->get('category_name'), 'streaming', 'news category retained');
assert_same($q->get('s'), 'OBS', 'news search retained');
unset($_GET['thema'], $_GET['q']);
$q = new C91TestMainQuery(['c91_news' => 0]);
c91_news_main_query($q);
assert_same($q->get('posts_per_page'), null, 'other queries untouched');

$base = dirname(__DIR__) . '/themes/comiker91-streamer-theme/';
$homepage = file_get_contents($base . 'front-page.php');
$archive = file_get_contents($base . 'archive-streamer.php');
$profile = file_get_contents($base . 'single-streamer.php');
$news = file_get_contents($base . 'news.php');
$header = file_get_contents($base . 'header.php');
assert_contains($homepage, 'Alle Streamer-Profile ansehen', 'homepage links to archive');
assert_contains($homepage, 'class="streamer-pick-name" href="<?php echo esc_url(get_permalink()); ?>"', 'home streamer links internal');
assert_contains($archive, '<h2><a href="<?php echo esc_url(get_permalink($streamer_post->ID)); ?>"', 'archive cards link to explicit internal profile IDs');
assert_contains($archive, 'c91_get_streamer_login($streamer_post->ID)', 'archive Twitch login bound to each profile');
assert_contains($archive, '$streamer_post->post_title', 'archive card title tied to current profile, not global loop state');
assert_same(str_contains($archive, '$posts = [];'), false, 'archive does not override WordPress global posts array');
assert_same(str_contains($archive, 'esc_url(get_permalink());'), false, 'archive never uses ambiguous global permalink');
assert_contains($profile, 'c91_breadcrumbs();', 'profile links back to archive');
assert_contains($news, '$news = $wp_query;', 'news uses main query to prevent last-page 404');
assert_same(str_contains($header, "require_once get_template_directory() . '/inc/seo.php'"), false, 'no late header SEO bootstrap');
echo "PASS: comiker91 SEO archive, canonical, paginator, sitemap and link contract\n";
