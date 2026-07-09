<?php
/**
 * Plugin Name: Read More Without Refresh
 * Version: 4.1.0
 * Plugin URI: https://shop.8web.gr/read-more-without-refresh-pro/
 * Description: Boost your SEO without affecting user experience. Show/hide extra content on pages/posts/products and Custom Post Types - now with Content Locker, Teaser Paywall, AI Summaries, Auto-Apply rules, real Analytics, A/B testing and more.
 * Author: Eightweb Interactive
 * Author URI: https://8web.gr/en/
 * License: GPL2
 * Text Domain: rmwr
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('RMWR_PRO_VERSION', '4.1.0');
define('RMWR_PRO_FILE', __FILE__);
define('RMWR_PRO_BASENAME', plugin_basename(__FILE__));
define('RMWR_PRO_URL', plugin_dir_url(__FILE__));
define('RMWR_PRO_PATH', plugin_dir_path(__FILE__));
define('RMWR_PRO_IS_PRO', true);

// Freemius licensing + automatic updates (must boot as early as possible).
require_once RMWR_PRO_PATH . 'includes/rmwr-freemius.php';

// Core modules
require_once RMWR_PRO_PATH . 'includes/class-rmwr-plugin.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-shortcode.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-assets.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-schema.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-settings.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-block.php';

// Feature modules
require_once RMWR_PRO_PATH . 'includes/class-rmwr-analytics.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-ab-testing.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-auto-apply.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-sections.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-locker.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-paywall.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-ai-summary.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-cta.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-global-blocks.php';
require_once RMWR_PRO_PATH . 'includes/class-rmwr-import-export.php';

/**
 * Initialize plugin.
 */
function rmwr_pro_init() {
    return RMWR_Pro::get_instance();
}
add_action('plugins_loaded', 'rmwr_pro_init');

/**
 * Activation: create/upgrade database tables.
 */
function rmwr_pro_activate() {
    RMWR_Analytics::install_tables();
    update_option('rmwr_db_version', RMWR_PRO_VERSION, false);
}
register_activation_hook(__FILE__, 'rmwr_pro_activate');

/**
 * Uninstall cleanup.
 *
 * Freemius manages the uninstall process and forbids a standalone
 * uninstall.php, so the cleanup runs through its after_uninstall hook. By
 * default all data is kept (safe for accidental deletes / re-installs); when
 * the admin enabled "Delete all data on uninstall", everything is removed.
 */
function rmwr_after_uninstall() {
    if ('1' !== get_option('rmwr_delete_data_on_uninstall', '0')) {
        return; // Keep data unless explicitly opted in.
    }

    global $wpdb;

    // 1. Custom tables.
    require_once RMWR_PRO_PATH . 'includes/class-rmwr-analytics.php';
    RMWR_Analytics::drop_tables();

    // 2. Options (rmwr_* plus the legacy names).
    $option_names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rmwr\\_%'"); // phpcs:ignore WordPress.DB
    foreach ((array) $option_names as $option_name) {
        delete_option($option_name);
    }
    delete_option('rm_text');
    delete_option('rl_text');
    delete_option('rl_pro_option');

    // 3. Transients (rate limits, update cache).
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_rmwr\\_%' OR option_name LIKE '\\_transient\\_timeout\\_rmwr\\_%'"); // phpcs:ignore WordPress.DB

    // 4. Post meta written by the plugin.
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_rmwr\\_%'"); // phpcs:ignore WordPress.DB

    // 5. Reusable content blocks (CPT).
    $block_ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'rmwr_block')); // phpcs:ignore WordPress.DB
    foreach ((array) $block_ids as $block_id) {
        wp_delete_post((int) $block_id, true);
    }
}

if (function_exists('rmwr_fs') && rmwr_fs()) {
    rmwr_fs()->add_action('after_uninstall', 'rmwr_after_uninstall');
}
