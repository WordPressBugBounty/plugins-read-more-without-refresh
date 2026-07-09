<?php
/**
 * Global Content Blocks: define once, reuse everywhere.
 *
 * A private "Reusable Contents" post type holds content blocks (size guides,
 * shipping info, disclaimers, FAQ answers...). Any Read More instance can
 * pull one by slug or ID:
 *
 *   [read block="shipping-info" open="Shipping information"][/read]
 *
 * Edit the block once - every product/page using it updates instantly.
 * Built for agencies and WooCommerce stores that repeat the same collapsible
 * content on hundreds of pages.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Global_Blocks {

    const POST_TYPE = 'rmwr_block';

    /** @var array<string,string> Per-request content cache. */
    private static $cache = array();

    public function __construct() {
        add_action('init', array($this, 'register_post_type'));
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'add_shortcode_column'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'render_shortcode_column'), 10, 2);
    }

    public function register_post_type() {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name'          => __('Reusable Contents', 'rmwr'),
                'singular_name' => __('Reusable Content', 'rmwr'),
                'add_new_item'  => __('Add Reusable Content', 'rmwr'),
                'edit_item'     => __('Edit Reusable Content', 'rmwr'),
                'not_found'     => __('No reusable content found. Create one and embed it with [read block="slug"].', 'rmwr'),
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'read_more_without_refresh',
            'show_in_rest'        => true, // Gutenberg editing.
            'supports'            => array('title', 'editor', 'revisions'),
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'has_archive'         => false,
            'rewrite'             => false,
            'capability_type'     => 'page',
        ));
    }

    /**
     * Fetch reusable content by slug or numeric ID.
     *
     * @param string $ref Slug or ID.
     * @return string Raw post content ('' when not found).
     */
    public static function get_content($ref) {
        $ref = trim((string) $ref);
        if ('' === $ref) {
            return '';
        }

        if (isset(self::$cache[$ref])) {
            return self::$cache[$ref];
        }

        $post = null;
        if (is_numeric($ref)) {
            $candidate = get_post((int) $ref);
            if ($candidate instanceof WP_Post && self::POST_TYPE === $candidate->post_type) {
                $post = $candidate;
            }
        } else {
            $post = get_page_by_path(sanitize_title($ref), OBJECT, self::POST_TYPE);
        }

        $content = ($post instanceof WP_Post && 'publish' === $post->post_status)
            ? (string) $post->post_content
            : '';

        self::$cache[$ref] = $content;

        return $content;
    }

    /**
     * Show a ready-to-copy shortcode in the list table.
     */
    public function add_shortcode_column($columns) {
        $columns['rmwr_shortcode'] = __('Shortcode', 'rmwr');
        return $columns;
    }

    public function render_shortcode_column($column, $post_id) {
        if ('rmwr_shortcode' !== $column) {
            return;
        }
        $post = get_post($post_id);
        if ($post instanceof WP_Post) {
            printf('<code>[read block="%s"][/read]</code>', esc_html($post->post_name));
        }
    }
}
