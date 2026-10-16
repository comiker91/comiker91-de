<?php
/**
 * Schema ownership contract. Run both:
 *   php tests/schema-ownership-contract.php fallback
 *   php tests/schema-ownership-contract.php yoast
 *
 * Pure PHP; no WordPress installation, production data or network required.
 */
error_reporting(E_ALL);
define('ABSPATH', __DIR__);
$mode = $argv[1] ?? '';
if (!in_array($mode, ['fallback', 'yoast'], true)) {
    fwrite(STDERR, "Usage: php tests/schema-ownership-contract.php fallback|yoast\n");
    exit(2);
}
if ($mode === 'yoast') define('WPSEO_VERSION', '26.0');

$GLOBALS['c91_schema_test_page'] = 'page';
$GLOBALS['c91_schema_test_hooks'] = [];
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['c91_schema_test_hooks'][$hook][] = $callback;
}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {}
function is_admin() { return false; }
function is_feed() { return false; }
function is_robots() { return false; }
function is_front_page() { return $GLOBALS['c91_schema_test_page'] === 'front'; }
function is_page() { return $GLOBALS['c91_schema_test_page'] === 'page'; }
function is_singular($type = null) {
    $page = $GLOBALS['c91_schema_test_page'];
    return in_array($page, ['page', 'post'], true) && ($type === null || $type === $page);
}
function is_post_type_archive($type = null) { return false; }
function home_url($path = '') { return 'https://comiker91.de' . $path; }
function get_query_var($key) { return 0; }
function get_post_ancestors($id) { return []; }
function get_the_ID() { return 42; }
function get_the_title($id = null) {
    return $GLOBALS['c91_schema_test_page'] === 'post'
        ? 'Streamer Equipment 2026' : 'ComischeCasinos';
}
function get_permalink($id = null) {
    return home_url($GLOBALS['c91_schema_test_page'] === 'post'
        ? '/streamer-equipment-2026/' : '/comischecasinos/');
}
function get_queried_object_id() { return 42; }
function get_theme_mod($key, $fallback = '') { return $fallback; }
function get_bloginfo($key) { return $key === 'language' ? 'de-DE' : 'comiker91'; }
function get_the_date($format, $id) { return '2026-10-16T10:00:00+00:00'; }
function get_the_modified_date($format, $id) { return '2026-10-16T11:00:00+00:00'; }
function has_post_thumbnail($id = null) { return false; }
function wp_json_encode($data, $options = 0) { return json_encode($data, $options | JSON_THROW_ON_ERROR); }
function c91_check($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}
require dirname(__DIR__) . '/themes/comiker91-streamer-theme/inc/seo.php';
c91_check(in_array('c91_schema_graph', $GLOBALS['c91_schema_test_hooks']['wp_head'] ?? [], true), 'Schema hook registered');

foreach (['front', 'page', 'post'] as $page) {
    $GLOBALS['c91_schema_test_page'] = $page;
    ob_start();
    c91_schema_graph();
    $html = ob_get_clean();
    if ($mode === 'yoast') {
        c91_check($html === '', "Yoast must exclusively own schema for $page");
        continue;
    }

    c91_check(substr_count($html, 'application/ld+json') === 1, "one fallback JSON-LD script for $page");
    c91_check((bool) preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match), "valid script for $page");
    $schema = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    c91_check($schema['@context'] === 'https://schema.org', "schema.org context on $page");
    $graph = $schema['@graph'];
    $types = array_column($graph, '@type');
    $expected = $page === 'front'
        ? ['WebSite', 'Person']
        : ($page === 'page'
            ? ['WebSite', 'Person', 'BreadcrumbList']
            : ['WebSite', 'Person', 'BreadcrumbList', 'Article']);
    c91_check($types === $expected, "fallback graph types and order for $page");
    c91_check(count(array_unique($types)) === count($types), "no duplicate fallback entity types for $page");

    if ($page !== 'front') {
        $breadcrumb = $graph[2];
        $path = $page === 'post' ? '/streamer-equipment-2026/' : '/comischecasinos/';
        c91_check($breadcrumb['@id'] === home_url($path) . '#breadcrumb', "breadcrumb ID for $page");
        $elements = $breadcrumb['itemListElement'];
        c91_check(count($elements) === ($page === 'post' ? 3 : 2), "breadcrumb length for $page");
        foreach ($elements as $position => $item) {
            c91_check($item['@type'] === 'ListItem', "breadcrumb ListItem for $page");
            c91_check($item['position'] === $position + 1, "sequential breadcrumb positions for $page");
            c91_check(!empty($item['item']), "breadcrumb item URL present for $page");
        }
        c91_check(end($elements)['item'] === home_url($path), "last breadcrumb URL for $page");
    }
}
echo "PASS: schema owner $mode; front, page, post and breadcrumb fallback\n";
