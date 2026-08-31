<?php
/**
 * Plugin Name: CM91 Security Hardening
 * Description: Kleine Security-Härtungen für comiker91.de. Deaktiviert XML-RPC, da der Endpunkt auf der Website nicht benötigt wird.
 * Version: 1.0.0
 * Author: Comitement
 */

if (!defined('ABSPATH')) {
    exit;
}

// Disable XML-RPC methods handled by WordPress.
add_filter('xmlrpc_enabled', '__return_false', PHP_INT_MAX);

// Remove the discovery header/link so clients and bots are not pointed at xmlrpc.php.
remove_action('wp_head', 'rsd_link');
add_filter('wp_headers', static function (array $headers): array {
    unset($headers['X-Pingback']);
    return $headers;
}, PHP_INT_MAX);

// Disable pingbacks as an additional XML-RPC-related attack surface.
add_filter('xmlrpc_methods', static function (array $methods): array {
    unset($methods['pingback.ping'], $methods['pingback.extensions.getPingbacks']);
    return $methods;
}, PHP_INT_MAX);
