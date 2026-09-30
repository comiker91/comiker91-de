<?php
if (!defined('ABSPATH')) {
    exit;
}

// Owner policy: this site does not accept WordPress comments, trackbacks or pingbacks.
add_action('init', static function (): void {
    update_option('default_comment_status', 'closed');
    update_option('default_ping_status', 'closed');
    update_option('default_pingback_flag', 0);

    foreach (get_post_types([], 'names') as $post_type) {
        remove_post_type_support($post_type, 'comments');
        remove_post_type_support($post_type, 'trackbacks');
    }

    if (get_option('comitement_comments_disabled_v1') !== '1') {
        global $wpdb;
        $wpdb->query(
            "UPDATE {$wpdb->posts}
             SET comment_status = 'closed', ping_status = 'closed', to_ping = ''
             WHERE comment_status <> 'closed' OR ping_status <> 'closed' OR to_ping <> ''"
        );
        update_option('comitement_comments_disabled_v1', '1', false);
    }
}, PHP_INT_MAX);

add_filter('comments_open', '__return_false', PHP_INT_MAX, 2);
add_filter('pings_open', '__return_false', PHP_INT_MAX, 2);

add_filter('xmlrpc_methods', static function (array $methods): array {
    unset($methods['pingback.ping'], $methods['pingback.extensions.getPingbacks']);
    return $methods;
}, PHP_INT_MAX);

add_filter('wp_headers', static function (array $headers): array {
    unset($headers['X-Pingback']);
    return $headers;
}, PHP_INT_MAX);

remove_action('wp_head', 'rsd_link');

add_action('admin_menu', static function (): void {
    remove_menu_page('edit-comments.php');
}, PHP_INT_MAX);

add_action('wp_before_admin_bar_render', static function (): void {
    if (isset($GLOBALS['wp_admin_bar'])) {
        $GLOBALS['wp_admin_bar']->remove_node('comments');
    }
}, PHP_INT_MAX);
