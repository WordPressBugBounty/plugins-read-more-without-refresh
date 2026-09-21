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

        // Paragraph and Smart (auto) modes both gate on paragraph count.
        if ('words' !== self::mode()) {
            return self::paragraph_count($post->post_content) > self::paragraph_threshold();
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
     * Collapse mode for singular content: 'words' (keep the first N words) or
     * 'paragraphs' (keep the first N paragraphs, Ad-Inserter style - e.g. keep
     * 2 visible and collapse everything from the third paragraph onward).
     *
     * @return string 'words'|'paragraphs'
     */
    public static function mode() {
        $mode = get_option('rmwr_auto_apply_mode', 'words');
        return in_array($mode, array('words', 'paragraphs', 'auto'), true) ? $mode : 'words';
    }

    /**
     * How many leading paragraphs stay visible in paragraph mode.
     *
     * @return int
     */
    public static function paragraph_threshold() {
        return max(1, absint(get_option('rmwr_auto_apply_paragraphs', 2)));
    }

    /**
     * Visible-content limit for the active mode (words or paragraphs).
     *
     * @return int
     */
    public static function current_limit() {
        return 'paragraphs' === self::mode() ? self::paragraph_threshold() : self::word_threshold();
    }

    /**
     * Count paragraphs in content. Handles both rendered HTML (<p> tags) and
     * raw post content (blocks separated by blank lines, which wpautop later
     * turns into paragraphs).
     *
     * @param string $content HTML or raw content.
     * @return int
     */
    public static function paragraph_count($content) {
        $text = trim((string) $content);
        if ('' === $text) {
            return 0;
        }

        $tags = preg_match_all('/<p[\s>]/i', $text);
        if ($tags) {
            return (int) $tags;
        }

        $blocks = preg_split('/\n\s*\n/', $text);
        if (!is_array($blocks)) {
            return 0;
        }
        $blocks = array_filter($blocks, static function ($block) {
            return '' !== trim(wp_strip_all_tags($block));
        });
        return count($blocks);
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

        return $this->collapse($content, self::current_limit(), self::mode(), $post);
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

        return $this->collapse($description, $threshold, 'words');
    }

    /**
     * Split content at the threshold and wrap the remainder in a Read More
     * instance rendered through the shared shortcode pipeline.
     *
     * @param string $content Full HTML.
     * @param int    $limit   Visible word or paragraph count.
     * @param string $unit    'words' or 'paragraphs'.
     * @return string
     */
    private function collapse($content, $limit, $unit = 'words', $post = null) {
        if ('auto' === $unit) {
            list($visible, $hidden) = self::smart_split($content);
        } else {
            list($visible, $hidden) = RMWR_Shortcode::split_html($content, $limit, $unit);
        }

        if ('' === trim(wp_strip_all_tags($hidden))) {
            return $content;
        }

        // Surface related posts inside the expanded area: internal links,
        // more pageviews and dwell time, only for real posts.
        if ($post instanceof WP_Post && self::related_enabled()) {
            $hidden .= self::related_html($post);
        }

        $shortcode = RMWR_Pro::get_instance()->modules['shortcode'];
        $rendered  = $shortcode->render_internal(array(), $hidden);

        return $visible . '<span class="rmwr-ellipsis">&hellip;</span>' . $rendered;
    }

    /**
     * Whether the "related posts inside Read More" feature is on (Pro).
     *
     * @return bool
     */
    private static function related_enabled() {
        return function_exists('rmwr_is_premium') && rmwr_is_premium()
            && '1' === get_option('rmwr_related_enable', '0');
    }

    /**
     * Build a small related-posts list (same category, most recent) to append
     * inside the collapsed content.
     *
     * @param WP_Post $post Current post.
     * @return string
     */
    private static function related_html($post) {
        $count = max(1, min(10, absint(get_option('rmwr_related_count', 3))));

        $args = array(
            'post_type'           => $post->post_type,
            'post_status'         => 'publish',
            'posts_per_page'      => $count,
            'post__not_in'        => array($post->ID),
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        );

        $terms = wp_get_post_terms($post->ID, 'category', array('fields' => 'ids'));
        if (!is_wp_error($terms) && !empty($terms)) {
            $args['category__in'] = $terms;
        }

        $query = new WP_Query($args);
        if (!$query->have_posts()) {
            return '';
        }

        $items = '';
        foreach ($query->posts as $related) {
            $items .= '<li><a href="' . esc_url(get_permalink($related)) . '">' . esc_html(get_the_title($related)) . '</a></li>';
        }
        wp_reset_postdata();

        return '<div class="rmwr-related"><strong>' . esc_html__('Related reading', 'rmwr') . '</strong><ul>' . $items . '</ul></div>';
    }

    /**
     * Smart cut point: keep everything before the first H2/H3 section heading
     * visible and collapse the rest (a natural "intro, then the article"
     * teaser). Falls back to the first two paragraphs when there is no early
     * heading. No AI call, no cost.
     *
     * @param string $content Rendered HTML.
     * @return array{0:string,1:string} [visible, hidden]
     */
    private static function smart_split($content) {
        if (preg_match('/<h[23][\s>]/i', $content, $m, PREG_OFFSET_CAPTURE)) {
            $pos     = (int) $m[0][1];
            $visible = substr($content, 0, $pos);
            $hidden  = substr($content, $pos);
            if ('' !== trim(wp_strip_all_tags($visible)) && '' !== trim(wp_strip_all_tags($hidden))) {
                return array(force_balance_tags($visible), force_balance_tags($hidden));
            }
        }

        return RMWR_Shortcode::split_html($content, 2, 'paragraphs');
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
