<?php
/**
 * The [read] shortcode renderer - the heart of the plugin.
 *
 * Also used programmatically by the Gutenberg block, the Elementor widget,
 * the Auto-Apply engine and the Sections engine, so every feature shares a
 * single rendering pipeline.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Shortcode {

    /** @var array Per-post render counters used to build stable instance keys. */
    private static $counters = array();

    /** @var bool Skip kses for trusted internal callers (auto-apply/sections). */
    private static $skip_kses = false;

    /** @var string|null Free tier: the first accordion group id allowed on the page (taste-then-wall). */
    private static $free_accordion_group = null;

    public function __construct() {
        add_action('init', array($this, 'register_shortcodes'));
    }

    public function register_shortcodes() {
        add_shortcode('read', array($this, 'render'));
        add_shortcode('read_all', array($this, 'render_toggle_all'));
    }

    /**
     * Render one Read More instance.
     *
     * @param array       $atts    Shortcode attributes.
     * @param string|null $content Enclosed content.
     * @return string
     */
    public function render($atts, $content = null) {
        $atts = shortcode_atts(array(
            // Basic
            'id'            => '',
            'open'          => '',
            'close'         => '',
            'class'         => '',
            // Mode
            'mode'          => 'normal',
            'accordion_id'  => '',
            // Animation
            'animation'     => '',
            'duration'      => '',
            // Styling
            'icon'          => '',
            'fa_icon'       => '',
            'template'      => '',
            // Conditional display
            'after'         => '',
            'scroll'        => '',
            'device'        => '',
            'role'          => '',
            // Behavior
            'lazy'          => '',
            'smooth_scroll' => '',
            'scroll_offset' => '',
            // SEO
            'question'      => '',
            // Truncate mode (v5)
            'limit'         => '',
            'unit'          => 'words',
            // Content Locker (v5)
            'lock'          => '',
            // Teaser Paywall (v5)
            'paywall'       => '',
            'paywall_mode'  => 'soft',
            'teaser'        => '',
            // Engaged-user CTA (v5)
            'cta'           => '',
            // Global content blocks (v5)
            'block'         => '',
            // AI summary (v5)
            'ai_summary'    => '',
        ), $atts, 'read');

        // Free-tier gate: neutralize every premium attribute so the SAME code
        // renders a plain, SEO-safe toggle on unlicensed sites. Premium modules
        // are also not loaded (see RMWR_Pro) - this is defense in depth, and it
        // keeps the free wordpress.org build honest. Free keeps: id, class,
        // smooth_scroll, scroll_offset, and the global Read More / Read Less
        // texts. A basic fade is the only animation the free tier allows.
        if (!rmwr_is_premium()) {
            // Free tier gets a TASTE of the Pro toolkit, then a wall: one button
            // template (modern-blue), the fade and slide animations, and the
            // first accordion group on the page. Everything else is neutralized
            // so the SAME code renders a plain, SEO-safe toggle.
            foreach (array(
                'open', 'close', 'icon', 'fa_icon', 'after', 'scroll', 'device',
                'role', 'lazy', 'limit', 'lock', 'paywall', 'cta', 'block', 'ai_summary',
            ) as $premium_att) {
                $atts[$premium_att] = '';
            }

            // One free button template.
            $atts['template'] = ('modern-blue' === $atts['template']) ? 'modern-blue' : '';

            // A couple of free animations.
            if (!in_array($atts['animation'], array('', 'none', 'fade', 'slide'), true)) {
                $atts['animation'] = 'fade';
            }

            // First accordion group on the page is free; later ones fall back
            // to a normal toggle.
            if ('accordion' === $atts['mode']) {
                $group = '' !== $atts['accordion_id'] ? $atts['accordion_id'] : 'default';
                if (null === self::$free_accordion_group) {
                    self::$free_accordion_group = $group;
                }
                if (self::$free_accordion_group !== $group) {
                    $atts['mode']         = 'normal';
                    $atts['accordion_id'] = '';
                    $atts['question']     = '';
                }
            } else {
                $atts['question'] = '';
            }
        }

        // Global content block: pull reusable content by slug/ID.
        if (!empty($atts['block'])) {
            $block_content = RMWR_Global_Blocks::get_content($atts['block']);
            if ('' !== $block_content) {
                $content = $block_content;
            }
        }

        if (!self::$skip_kses) {
            $content = self::kses_content((string) $content);
        }
        if ('' === trim((string) $content)) {
            return '';
        }

        // Server-side conditional display (roles). Device/time rules run in JS.
        if (!$this->passes_role_restriction($atts['role'])) {
            return '';
        }

        $post_id = get_the_ID() ? (int) get_the_ID() : 0;
        $key     = $this->build_instance_key($atts['id'], $post_id, $content);
        $id      = 'rmwr-' . $key;

        // Teaser Paywall: visitors without access get the fade-out teaser
        // instead of a working toggle.
        if (!empty($atts['paywall']) && !RMWR_Paywall::user_has_access($atts['paywall'])) {
            RMWR_Assets::mark_needed();
            return RMWR_Paywall::render_teaser($content, $atts, $key, $post_id);
        }

        // Button texts
        $enable_dynamic_text = get_option('rl_pro_option', '1');
        if ('1' === $enable_dynamic_text) {
            $open_text  = !empty($atts['open']) ? $atts['open'] : get_option('rm_text', 'Read More');
            $close_text = !empty($atts['close']) ? $atts['close'] : get_option('rl_text', 'Read Less');
        } else {
            $open_text  = get_option('rm_text', 'Read More');
            $close_text = get_option('rl_text', 'Read Less');
        }
        $uses_default_open = empty($atts['open']) || '1' !== $enable_dynamic_text;

        // Mode
        $mode         = !empty($atts['mode']) ? sanitize_text_field($atts['mode']) : 'normal';
        $accordion_id = !empty($atts['accordion_id']) ? sanitize_title($atts['accordion_id']) : '';
        if ('accordion' === $mode && '' === $accordion_id) {
            $accordion_id = 'accordion-' . ($post_id ? 'p' . $post_id : 'g');
        }

        // Animation
        $animation = !empty($atts['animation']) ? sanitize_text_field($atts['animation']) : get_option('rmwr_animation', 'none');
        $duration  = !empty($atts['duration']) ? absint($atts['duration']) : absint(get_option('rmwr_animation_duration', 300));

        // Conditional display defaults
        $show_after      = !empty($atts['after']) ? sanitize_text_field($atts['after']) : get_option('rmwr_show_after', '');
        $scroll_trigger  = '' !== $atts['scroll'] ? filter_var($atts['scroll'], FILTER_VALIDATE_BOOLEAN) : ('1' === get_option('rmwr_scroll_triggered', '0'));
        $device_filter   = !empty($atts['device']) ? sanitize_text_field($atts['device']) : get_option('rmwr_device_filter', 'all');

        // Behavior
        $lazy_load     = '' !== $atts['lazy'] ? filter_var($atts['lazy'], FILTER_VALIDATE_BOOLEAN) : ('1' === get_option('rmwr_lazy_load', '0'));
        $smooth_scroll = '' !== $atts['smooth_scroll'] ? filter_var($atts['smooth_scroll'], FILTER_VALIDATE_BOOLEAN) : ('1' === get_option('rmwr_smooth_scroll', '1'));
        $scroll_offset = '' !== $atts['scroll_offset'] ? absint($atts['scroll_offset']) : absint(get_option('rmwr_scroll_offset', 0));

        // Styling
        $custom_class = !empty($atts['class']) ? sanitize_html_class($atts['class']) : '';
        $icon         = !empty($atts['icon']) ? sanitize_text_field($atts['icon']) : get_option('rmwr_icon', '');
        $fa_icon      = !empty($atts['fa_icon']) ? sanitize_text_field($atts['fa_icon']) : get_option('rmwr_fontawesome_icon', '');
        if ('' === $icon && '' !== $fa_icon) {
            $icon = $fa_icon;
        }
        $template       = !empty($atts['template']) ? sanitize_text_field($atts['template']) : get_option('rmwr_button_template', '');
        $template_class = $template ? 'rmwr-template-' . sanitize_html_class($template) : '';

        // Content Locker
        $lock = '';
        if (!empty($atts['lock'])) {
            $lock_raw = strtolower(sanitize_text_field($atts['lock']));
            if (in_array($lock_raw, array('true', '1', 'email'), true)) {
                $lock = 'email';
            } elseif ('share' === $lock_raw) {
                $lock = 'share';
            }
        }

        // Engaged-user CTA
        $show_cta = RMWR_CTA::should_show($atts['cta']);

        // Truncate mode: split content into a visible teaser + hidden rest.
        $visible_teaser = '';
        $limit          = absint($atts['limit']);
        if ($limit > 0) {
            $unit = ('paragraphs' === strtolower($atts['unit'])) ? 'paragraphs' : 'words';
            list($visible_teaser, $rest) = self::split_html($content, $limit, $unit);
            if ('' === trim(wp_strip_all_tags($rest))) {
                // Content shorter than the limit: nothing to hide.
                return do_shortcode($content);
            }
            $content = $rest;
        }

        // FAQ schema (accordion mode): collect for a single aggregated
        // FAQPage JSON-LD block printed once in the footer.
        if ('accordion' === $mode && !empty($atts['question'])) {
            RMWR_Schema::add_faq($atts['question'], wp_strip_all_tags($content));
        }

        // AI Summary box (uses the post-level cached summary)
        $ai_box = '';
        if (filter_var($atts['ai_summary'], FILTER_VALIDATE_BOOLEAN) && $post_id) {
            $ai_box = RMWR_AI_Summary::render_summary_box($post_id);
        }

        // Wrapper classes
        $wrapper_classes = array('rmwr-wrapper');
        if ($custom_class) {
            $wrapper_classes[] = $custom_class;
        }
        if ($template_class) {
            $wrapper_classes[] = $template_class;
        }
        if ('accordion' === $mode) {
            $wrapper_classes[] = 'rmwr-accordion-item';
        }
        if ($lock) {
            $wrapper_classes[] = 'rmwr-locked';
        }

        // Data attributes consumed by frontend.js
        $data_attrs = array(
            'key'           => $key,
            'post'          => $post_id,
            'animation'     => $animation,
            'duration'      => $duration,
            'mode'          => $mode,
            'lazy'          => $lazy_load ? 'true' : 'false',
            'smooth-scroll' => $smooth_scroll ? 'true' : 'false',
            'scroll-offset' => $scroll_offset,
        );
        if ('accordion' === $mode && $accordion_id) {
            $data_attrs['accordion-id'] = $accordion_id;
        }
        if ($show_after) {
            $data_attrs['show-after'] = $show_after;
        }
        if ($scroll_trigger) {
            $data_attrs['scroll'] = 'true';
        }
        if ('all' !== $device_filter && $device_filter) {
            $data_attrs['device'] = $device_filter;
        }
        if ($lock) {
            $data_attrs['lock'] = $lock;
        }
        if ($show_cta) {
            $data_attrs['cta'] = '1';
        }

        // Make sure frontend assets are loaded for this page.
        RMWR_Assets::mark_needed();
        if (0 === strpos($icon, 'fa-')) {
            RMWR_Assets::mark_fa_needed();
        }

        $inner = do_shortcode($content);

        ob_start();

        if ('' !== $ai_box) {
            echo $ai_box; // Already escaped by RMWR_AI_Summary.
        }

        if ('' !== $visible_teaser) {
            ?>
            <div class="rmwr-teaser"><?php echo do_shortcode($visible_teaser); ?><span class="rmwr-ellipsis" data-for="<?php echo esc_attr($id); ?>">&hellip;</span></div>
            <?php
        }
        ?>
        <div class="<?php echo esc_attr(implode(' ', $wrapper_classes)); ?>"
             data-id="<?php echo esc_attr($id); ?>"
             <?php foreach ($data_attrs as $attr_key => $attr_value) : ?>
             data-<?php echo esc_attr($attr_key); ?>="<?php echo esc_attr($attr_value); ?>"
             <?php endforeach; ?>>
            <button
                type="button"
                class="read-link <?php echo esc_attr($template_class); ?>"
                id="readlink<?php echo esc_attr($id); ?>"
                data-open-text="<?php echo esc_attr($open_text); ?>"
                data-close-text="<?php echo esc_attr($close_text); ?>"
                <?php if ($uses_default_open && RMWR_AB_Testing::is_enabled()) : ?>
                data-ab="1"
                <?php endif; ?>
                aria-expanded="false"
                aria-controls="read<?php echo esc_attr($id); ?>"
            >
                <?php if ($icon) : ?>
                    <?php if (0 === strpos($icon, 'fa-')) : ?>
                        <i class="fa <?php echo esc_attr($icon); ?>" aria-hidden="true"></i>
                    <?php else : ?>
                        <span class="rmwr-icon rmwr-icon-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                    <?php endif; ?>
                <?php endif; ?>
                <span class="rmwr-text"><?php echo esc_html($open_text); ?></span>
            </button>
            <div
                class="read_div"
                id="read<?php echo esc_attr($id); ?>"
                aria-hidden="true"
                style="display: none;"
            >
                <?php if ($lazy_load) : ?>
                    <template class="rmwr-tpl"><?php echo $inner; ?></template>
                <?php else : ?>
                    <?php echo $inner; ?>
                <?php endif; ?>
            </div>
            <?php if ('email' === $lock) : ?>
                <?php echo RMWR_Locker::render_email_form_template($key, $post_id); ?>
            <?php elseif ('share' === $lock) : ?>
                <?php echo RMWR_Locker::render_share_template($key, $post_id); ?>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render an instance for trusted internal content that already went
     * through the_content filters (auto-apply, sections). Skips kses so that
     * markup added by other plugins (embeds, galleries) survives.
     *
     * @param array  $atts    Attribute overrides.
     * @param string $content Trusted HTML.
     * @return string
     */
    public function render_internal($atts, $content) {
        self::$skip_kses = true;
        $output          = $this->render($atts, $content);
        self::$skip_kses = false;

        return $output;
    }

    /**
     * Render the [read_all] Expand All / Collapse All button.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render_toggle_all($atts) {
        $atts = shortcode_atts(array(
            'group' => '',
            'open'  => __('Expand all', 'rmwr'),
            'close' => __('Collapse all', 'rmwr'),
            'class' => '',
        ), $atts, 'read_all');

        RMWR_Assets::mark_needed();

        return sprintf(
            '<button type="button" class="rmwr-toggle-all %1$s" data-group="%2$s" data-open-text="%3$s" data-close-text="%4$s">%5$s</button>',
            esc_attr(sanitize_html_class($atts['class'])),
            esc_attr(sanitize_title($atts['group'])),
            esc_attr($atts['open']),
            esc_attr($atts['close']),
            esc_html($atts['open'])
        );
    }

    /**
     * Build a stable instance key.
     *
     * Custom IDs win. Otherwise the key derives from the post ID plus the
     * render order inside that post ("p123-2" = second instance in post 123),
     * which stays identical across pageviews so analytics can aggregate.
     *
     * @param string $custom_id Custom ID from the shortcode.
     * @param int    $post_id   Current post ID (0 outside the loop).
     * @param string $content   Instance content (fallback entropy).
     * @return string
     */
    private function build_instance_key($custom_id, $post_id, $content) {
        if ('' !== $custom_id) {
            $key = sanitize_html_class($custom_id);
            if ('' !== $key) {
                return $key;
            }
        }

        $bucket = $post_id > 0 ? 'p' . $post_id : 'g' . substr(md5((string) wp_strip_all_tags($content)), 0, 6);
        if (!isset(self::$counters[$bucket])) {
            self::$counters[$bucket] = 0;
        }
        self::$counters[$bucket]++;

        return $bucket . '-' . self::$counters[$bucket];
    }

    /**
     * Role restriction check.
     *
     * Accepts a comma-separated role list. The special value "guest" matches
     * logged-out visitors. When a restriction is set, everyone outside the
     * list (including guests, which was a v4 bypass bug) is denied.
     *
     * @param string $role_restriction Comma-separated roles.
     * @return bool
     */
    private function passes_role_restriction($role_restriction) {
        $role_restriction = !empty($role_restriction)
            ? sanitize_text_field($role_restriction)
            : get_option('rmwr_role_restriction', '');

        if ('' === $role_restriction) {
            return true;
        }

        $allowed_roles = array_map('trim', explode(',', strtolower($role_restriction)));

        if (!is_user_logged_in()) {
            return in_array('guest', $allowed_roles, true);
        }

        $user = wp_get_current_user();
        foreach ((array) $user->roles as $role) {
            if (in_array(strtolower($role), $allowed_roles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize hidden content. Same policy as post content, plus iframes so
     * that embeds (YouTube, maps) survive - a common v4 complaint.
     *
     * @param string $content Raw content.
     * @return string
     */
    public static function kses_content($content) {
        $allowed = wp_kses_allowed_html('post');
        $allowed['iframe'] = array(
            'src'             => true,
            'width'           => true,
            'height'          => true,
            'frameborder'     => true,
            'allow'           => true,
            'allowfullscreen' => true,
            'loading'         => true,
            'title'           => true,
            'referrerpolicy'  => true,
            'style'           => true,
            'class'           => true,
            'id'              => true,
        );

        /**
         * Filter the HTML allowed inside [read] content.
         *
         * @param array $allowed wp_kses allowed-tags array.
         */
        $allowed = apply_filters('rmwr_allowed_html', $allowed);

        return wp_kses($content, $allowed);
    }

    /**
     * Split HTML into a visible part and a hidden remainder without breaking
     * markup. Used by the truncate mode and the Auto-Apply engine.
     *
     * @param string $html  Sanitized HTML.
     * @param int    $limit Number of words/paragraphs to keep visible.
     * @param string $unit  'words' or 'paragraphs'.
     * @return array{0:string,1:string} [visible, hidden]
     */
    public static function split_html($html, $limit, $unit = 'words') {
        $limit = max(1, (int) $limit);

        if ('paragraphs' === $unit) {
            $parts   = preg_split('/(<\/p>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
            $visible = '';
            $count   = 0;
            $index   = 0;
            $total   = count($parts);
            for (; $index < $total; $index++) {
                $visible .= $parts[$index];
                if (0 === strcasecmp($parts[$index], '</p>')) {
                    $count++;
                    if ($count >= $limit) {
                        $index++;
                        break;
                    }
                }
            }
            $hidden = implode('', array_slice($parts, $index));

            if ('' === trim(wp_strip_all_tags($hidden))) {
                return array($html, '');
            }

            return array(force_balance_tags($visible), force_balance_tags($hidden));
        }

        // Word mode: tokenize into tags and text, count words only in text.
        $tokens  = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $visible = '';
        $hidden  = '';
        $count   = 0;
        $done    = false;

        foreach ($tokens as $token) {
            if ($done) {
                $hidden .= $token;
                continue;
            }
            if ('' !== $token && '<' === $token[0]) {
                $visible .= $token;
                continue;
            }
            $pieces = preg_split('/(\s+)/u', $token, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($pieces as $piece) {
                if ($done) {
                    $hidden .= $piece;
                    continue;
                }
                if ('' === trim($piece)) {
                    $visible .= $piece;
                    continue;
                }
                $visible .= $piece;
                $count++;
                if ($count >= $limit) {
                    $done = true;
                }
            }
        }

        if (!$done || '' === trim(wp_strip_all_tags($hidden))) {
            return array($html, '');
        }

        return array(force_balance_tags($visible), force_balance_tags($hidden));
    }
}
