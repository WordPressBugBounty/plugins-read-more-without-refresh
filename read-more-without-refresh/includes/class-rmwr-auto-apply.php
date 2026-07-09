<?php
/**
 * Auto-Apply engine: add a Read More toggle to existing content with ZERO
 * shortcodes.
 *
 * Two targets:
 *  1. Singular content (posts, pages, products, any CPT): when the content
 *     is longer than the configured word threshold, the first N words stay
 *     visible and the rest collapses behind a Read More button.
 *  2. Taxonomy descriptions (categories, tags, WooCommerce product
 *     categories): long SEO text on archive pages collapses automatically -
 *     the classic "SEO text on shop category pages" use case.
 *
 * The hidden part stays in the initial HTML, so search engines index all of
 * it. A per-post "Disable Read More automation" checkbox (meta box) opts
 * individual posts out.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Auto_Apply {

    public function __construct() {
        add_filter('the_content', array($this, 'filter_content'), 98);
        add_filter('term_description', array($this, 'filter_term_description'), 98);

        add_action('add_meta_boxes', array($this, 'register_meta_box'));
        add_action('save_post', array($this, 'save_meta_box'));
    }

    /* ---------------------------------------------------------------------
     * Rules
     * ------------------------------------------------------------------ */

    /**
     * Does the auto-apply rule fire for this post?
     *
     * @param WP_Post $post Post object.
     * @return bool
     */
    public static function applies_to_post($post) {
        if (!$post instanceof WP_Post || '1' !== get_option('rmwr_auto_apply', '0')) {
            return false;
        }

        $types = (array) get_option('rmwr_auto_apply_post_types', array());
        if (!in_array($post->post_type, $types, true)) {
            return false;
        }

        // Manual shortcode/block use wins over automation.
        if (has_shortcode((string) $post->post_content, 'read')
            || has_block('rmwr/read-more', $post)
            || has_block('read-more-without-refresh-pro/read-more-block', $post)) {
            return false;
        }

        if ('1' === get_post_meta($post->ID, '_rmwr_auto_apply_off', true)) {
            return false;
        }

        // Sections mode takes precedence when both are enabled for a type.
        if (RMWR_Sections::applies_to_post($post)) {
            return false;
        }

        return self::word_count($post->post_content) > self::word_threshold();
    }

    /**
     * Is taxonomy-description automation on?
     *
     * @return bool
     */
    public static function taxonomy_enabled() {
        return '1' === get_option('rmwr_auto_apply', '0') && '1' === get_option('rmwr_auto_apply_tax', '0');
    }

    public static function word_threshold() {
        return max(10, absint(get_option('rmwr_auto_apply_words', 100)));
    }

    /**
     * Unicode-safe word count (str_word_count breaks on Greek/Cyrillic/CJK).
     *
     * @param string $html HTML or text.
     * @return int
     */
    public static function word_count($html) {
        $text = trim(wp_strip_all_tags((string) $html));
        if ('' === $text) {
            return 0;
        }
        $words = preg_split('/\s+/u', $text);
        return is_array($words) ? count($words) : 0;
    }

    /* ---------------------------------------------------------------------
     * Filters
     * ------------------------------------------------------------------ */

    /**
     * Collapse long singular content.
     *
     * @param string $content Rendered post content.
     * @return string
     */
    public function filter_content($content) {
        if (is_admin() || is_feed() || !is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post = get_post();
        if (!self::applies_to_post($post)) {
            return $content;
        }

        return $this->collapse($content, self::word_threshold());
    }

    /**
     * Collapse long taxonomy descriptions on archive pages.
     *
     * @param string $description Term description HTML.
     * @return string
     */
    public function filter_term_description($description) {
        if (is_admin() || !self::taxonomy_enabled()) {
            return $description;
        }

        if (!is_category() && !is_tag() && !is_tax()) {
            return $description;
        }

        $threshold = max(10, absint(get_option('rmwr_auto_apply_tax_words', get_option('rmwr_auto_apply_words', 100))));
        if (self::word_count($description) <= $threshold) {
            return $description;
        }

        return $this->collapse($description, $threshold);
    }

    /**
     * Split content at the threshold and wrap the remainder in a Read More
     * instance rendered through the shared shortcode pipeline.
     *
     * @param string $content   Full HTML.
     * @param int    $threshold Visible word count.
     * @return string
     */
    private function collapse($content, $threshold) {
        list($visible, $hidden) = RMWR_Shortcode::split_html($content, $threshold, 'words');

        if ('' === trim(wp_strip_all_tags($hidden))) {
            return $content;
        }

        $shortcode = RMWR_Pro::get_instance()->modules['shortcode'];
        $rendered  = $shortcode->render_internal(array(), $hidden);

        return $visible . '<span class="rmwr-ellipsis">&hellip;</span>' . $rendered;
    }

    /* ---------------------------------------------------------------------
     * Per-post opt-out meta box (shared with the Sections engine)
     * ------------------------------------------------------------------ */

    public function register_meta_box() {
        $types = array_unique(array_merge(
            (array) get_option('rmwr_auto_apply_post_types', array()),
            (array) get_option('rmwr_sections_post_types', array())
        ));

        if (empty($types)) {
            return;
        }

        add_meta_box(
            'rmwr-automation',
            __('Read More Automation', 'rmwr'),
            array($this, 'render_meta_box'),
            $types,
            'side',
            'default'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('rmwr_automation_meta', 'rmwr_automation_nonce');
        $auto_off     = '1' === get_post_meta($post->ID, '_rmwr_auto_apply_off', true);
        $sections_off = '1' === get_post_meta($post->ID, '_rmwr_sections_off', true);
        ?>
        <p>
            <label>
                <input type="checkbox" name="rmwr_auto_apply_off" value="1" <?php checked($auto_off); ?> />
                <?php esc_html_e('Disable auto Read More on this post', 'rmwr'); ?>
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="rmwr_sections_off" value="1" <?php checked($sections_off); ?> />
                <?php esc_html_e('Disable collapsible sections on this post', 'rmwr'); ?>
            </label>
        </p>
        <?php
    }

    public function save_meta_box($post_id) {
        if (!isset($_POST['rmwr_automation_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rmwr_automation_nonce'])), 'rmwr_automation_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['rmwr_auto_apply_off'])) {
            update_post_meta($post_id, '_rmwr_auto_apply_off', '1');
        } else {
            delete_post_meta($post_id, '_rmwr_auto_apply_off');
        }

        if (isset($_POST['rmwr_sections_off'])) {
            update_post_meta($post_id, '_rmwr_sections_off', '1');
        } else {
            delete_post_meta($post_id, '_rmwr_sections_off');
        }
    }
}
