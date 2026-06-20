<?php
/**
 * Plugin Name:       Cloudflare Cache Purge
 * Plugin URI:        https://github.com/maciejbuchert/cloudflare-cache-purge-plugin
 * Description:       Precyzyjny purge cache Cloudflare per typ treści — dla setupów headless (WordPress + Next.js).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Maciej Buchert
 * License:           GPL-2.0-or-later
 * Text Domain:       cf-purge
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'CF_PURGE_VERSION', '1.0.0' );
define( 'CF_PURGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CF_PURGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CF_PURGE_PLUGIN_FILE', __FILE__ );

// Simple PSR-4-like autoloader registration.
spl_autoload_register( function ( string $class_name ): void {
    $prefix = 'CF_Purge_';
    if ( strpos( $class_name, $prefix ) !== 0 ) {
        return;
    }
    $slug      = strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) );
    $file_path = CF_PURGE_PLUGIN_DIR . 'includes/class-cf-purge-' . $slug . '.php';
    if ( is_readable( $file_path ) ) {
        require_once $file_path;
    }
} );

/**
 * Inicjalizacja pluginu po załadowaniu wszystkich pluginów.
 */
function cf_purge_init(): void {
    load_plugin_textdomain( 'cf-purge', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    CF_Purge_Plugin::get_instance()->init();
}
add_action( 'plugins_loaded', 'cf_purge_init' );
