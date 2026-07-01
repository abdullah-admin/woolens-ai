<?php
/**
 * Plugin Name:       WooLens AI
 * Plugin URI:        https://woolensai.site
 * Description:       WooLens AI analyzes your WooCommerce product image and automatically writes the title and description for you. No typing. No ChatGPT tabs. Just click and done.
 * Version:           1.4.1
 * Author:            WooLens AI
 * License:           GPL-2.0+
 * Text Domain:       woolens-ai
 * Requires Plugins:  woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WOOLENS_VERSION',  '1.4.1' );
define( 'WOOLENS_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WOOLENS_URL',      plugin_dir_url( __FILE__ ) );
define( 'WOOLENS_BASENAME', plugin_basename( __FILE__ ) );

/* ── Vision model (single model for all plans) ────────────────────── */
define( 'WOOLENS_FREE_MODEL',  'gemini-3.1-flash-lite' );
define( 'WOOLENS_SERVER_URL',  'https://woolensai.site' );

/* ── Auto-load ────────────────────────────────────────────────────── */
require_once WOOLENS_DIR . 'includes/class-rate-limiter.php';
require_once WOOLENS_DIR . 'includes/class-ai-client.php';
require_once WOOLENS_DIR . 'includes/class-updater.php';
require_once WOOLENS_DIR . 'admin/class-settings-page.php';
require_once WOOLENS_DIR . 'admin/class-product-editor.php';
require_once WOOLENS_DIR . 'admin/class-bulk-generator.php';
require_once WOOLENS_DIR . 'admin/class-bulk-products.php';
require_once WOOLENS_DIR . 'admin/class-deactivation.php';

/* ── Boot ─────────────────────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>
                <strong>WooLens AI</strong> requires WooCommerce to be active.
            </p></div>';
        } );
        return;
    }
    WOOLENS_Settings_Page::init();
    WOOLENS_Product_Editor::init();
    WOOLENS_Bulk_Generator::init();
    WOOLENS_Bulk_Products::init();
    WOOLENS_Updater::init();
    WOOLENS_Deactivation::init();
} );

/* ── Activation ───────────────────────────────────────────────────── */
register_activation_hook( __FILE__, function () {
    global $wpdb;
    $table   = $wpdb->prefix . 'woolens_usage';
    $charset = $wpdb->get_charset_collate();
    $sql     = "CREATE TABLE IF NOT EXISTS $table (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     BIGINT UNSIGNED NOT NULL,
        used_date   DATE NOT NULL,
        count       INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY user_date (user_id, used_date)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    add_option( 'woolens_version', WOOLENS_VERSION );
} );
