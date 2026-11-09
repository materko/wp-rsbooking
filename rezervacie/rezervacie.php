<?php
/**
 * Plugin Name: Rezervácie
 * Description: Online rezervačný systém – služby, pracovníci, prenájmy, online formulár, kalendár, e-maily, storno, čakacia listina, zľavové kódy, poukážky a permanentky, QR a online platby, hodnotenia, vernostný program, štatistiky.
 * Version: 0.3.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: Salonik AKTA
 * Text Domain: rezervacie
 */

defined('ABSPATH') || exit;

define('RZV_VERSION', '0.3.0');
define('RZV_DB_VERSION', 3);
define('RZV_FILE', __FILE__);
define('RZV_DIR', plugin_dir_path(__FILE__));
define('RZV_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function ($class) {
    if (strpos($class, 'Rzv\\') !== 0) {
        return;
    }
    $rel  = strtolower(str_replace(['Rzv\\', '_'], ['', '-'], $class));
    $rel  = str_replace('\\', '/', $rel);
    $parts = explode('/', $rel);
    $last  = array_pop($parts);
    $path  = RZV_DIR . 'includes/' . ($parts ? implode('/', $parts) . '/' : '') . 'class-' . $last . '.php';
    if (is_readable($path)) {
        require $path;
    }
});

add_filter('cron_schedules', function ($s) {
    $s['rzv_quarter'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Každých 15 minút'];
    return $s;
});

register_activation_hook(__FILE__, ['Rzv\\Install', 'activate']);
register_deactivation_hook(__FILE__, ['Rzv\\Install', 'deactivate']);

add_action('plugins_loaded', function () {
    Rzv\Plugin::instance();
});
// upgrade až na init – vytváranie stránok potrebuje inicializovaný $wp_rewrite
add_action('init', ['Rzv\\Install', 'maybe_upgrade'], 1);
