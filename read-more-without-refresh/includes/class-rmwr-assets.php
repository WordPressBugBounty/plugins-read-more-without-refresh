<?php
/**
 * Conditional asset loading, dynamic CSS and GDPR-safe font handling.
 *
 * v4 loaded frontend JS, three stylesheets and the full Font Awesome CDN on
 * EVERY page. v5 loads assets only on pages that actually render an instance,
 * and external fonts can be disabled entirely (GDPR mode).
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Assets {

    /** @var bool Whether the current page needs frontend assets. */
    private static $needed = false;

    /** @var bool Whether Font Awesome is needed on the current page. */
    private static $fa_needed = false;

    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('wp', array($this, 'detect_early'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));
    }

    /**
     * Called by any module that renders an instance. Safe to call mid-page:
     * scripts print in the footer, and the stylesheet is enqueued early via
     * detect_early() for the common cases.
     */
    public static function mark_needed() {
        self::$needed = true;

        if (did_action('wp_enqueue_scripts')) {
            self::enqueue_frontend();
        }
    }

    /**
     * Request Font Awesome for this page (only loaded if the admin allows it).
     */
    public static function mark_fa_needed() {
        self::$fa_needed = true;

        if (did_action('wp_enqueue_scripts') && 'never' !== get_option('rmwr_load_fontawesome', 'auto')) {
            self::enqueue_fontawesome();
        }
    }

    /**
     * Early detection so styles land in <head> (no flash of unstyled content):
     * shortcode present in content, our block present, or a sitewide feature
     * (auto-apply / sections) is active for this post type.
     */
    public function detect_early() {
        if (is_admin()) {
            return;
        }

        $post = get_post();
        if ($post instanceof WP_Post) {
            if (has_shortcode((string) $post->post_content, 'read')
                || has_shortcode((string) $post->post_content, 'read_all')
                || has_block('rmwr/read-more', $post)
                || has_block('read-more-without-refresh-pro/read-more-block', $post)
                || RMWR_Auto_Apply::applies_to_post($post)
                || RMWR_Sections::applies_to_post($post)) {
                self::$needed = true;
            }
        }

        // Taxonomy descriptions (e.g. WooCommerce category SEO text).
        if ((is_category() || is_tag() || is_tax()) && RMWR_Auto_Apply::taxonomy_enabled()) {
            self::$needed = true;
        }
    }

    /**
     * Register everything; enqueue only when needed.
     */
    public function register_assets() {
        wp_register_script(
            'rmwr-frontend',
            RMWR_PRO_URL . 'js/frontend.js',
            array(),
            RMWR_PRO_VERSION,
            true
        );

        wp_register_style('rmwr-frontend', RMWR_PRO_URL . 'css/frontend.css', array(), RMWR_PRO_VERSION);
        wp_register_style('rmwr-icons', RMWR_PRO_URL . 'css/icons.css', array('rmwr-frontend'), RMWR_PRO_VERSION);
        wp_register_style('rmwr-templates', RMWR_PRO_URL . 'css/templates.css', array('rmwr-frontend'), RMWR_PRO_VERSION);
        wp_register_style(
            'rmwr-fontawesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
            array(),
            '6.4.0'
        );

        if (self::$needed) {
            self::enqueue_frontend();
        }

        // Global FA icon configured and FA allowed: load when instances exist.
        if ('always' === get_option('rmwr_load_fontawesome', 'auto')
            || (self::$needed && '' !== get_option('rmwr_fontawesome_icon', ''))) {
            self::enqueue_fontawesome();
        }
    }

    /**
     * Enqueue frontend bundle + dynamic CSS + settings payload.
     */
    private static function enqueue_frontend() {
        if (wp_script_is('rmwr-frontend', 'enqueued')) {
            return;
        }

        wp_enqueue_style('rmwr-frontend');
        wp_enqueue_style('rmwr-icons');
        wp_enqueue_style('rmwr-templates');
        wp_add_inline_style('rmwr-frontend', self::build_dynamic_css());

        // Google Fonts (skipped entirely in GDPR mode).
        $font_family = get_option('rmwr_font_family', 'inherit');
        if ('inherit' !== $font_family
            && '1' !== get_option('rmwr_disable_external_fonts', '0')
            && self::is_google_font($font_family)) {
            wp_enqueue_style('rmwr-google-font', self::google_font_url($font_family), array(), null);
        }

        wp_enqueue_script('rmwr-frontend');

        wp_localize_script('rmwr-frontend', 'rmwrSettings', array(
            'restUrl'       => esc_url_raw(rest_url('rmwr/v1')),
            'analytics'     => '1' === get_option('rmwr_enable_analytics', '1'),
            'printExpand'   => '1' === get_option('rmwr_print_expand', '1'),
            'remember'      => '1' === get_option('rmwr_remember_state', '0'),
            'deepLink'      => '1' === get_option('rmwr_deep_linking', '1'),
            'abVariants'    => RMWR_AB_Testing::get_variants(),
            'cta'           => RMWR_CTA::get_frontend_config(),
            'locker'        => RMWR_Locker::get_frontend_config(),
            'loadingText'   => __('Loading...', 'rmwr'),
            'debug'         => defined('WP_DEBUG') && WP_DEBUG,
        ));
    }

    private static function enqueue_fontawesome() {
        if ('1' === get_option('rmwr_disable_external_fonts', '0')) {
            return; // GDPR mode: no external CDN requests.
        }
        wp_enqueue_style('rmwr-fontawesome');
    }

    /**
     * Build the dynamic CSS from styling options. Attached to the frontend
     * stylesheet, so it only ships on pages that render an instance.
     *
     * @return string
     */
    private static function build_dynamic_css() {
        $font_weight    = self::css(get_option('rmwr_font_weight', 'normal'));
        $text_color     = self::css(get_option('rmwr_text_color', '#000000'));
        $hover_color    = self::css(get_option('rmwr_text_hover_color', '#191919'));
        $bg_color       = self::css(get_option('rmwr_background_color', '#ffffff'));
        $bg_gradient    = self::css(get_option('rmwr_background_gradient', ''));
        $padding        = self::css(get_option('rmwr_padding', '0px'));
        $border_bottom  = self::css(get_option('rmwr_border_bottom', '1px'));
        $border_color   = self::css(get_option('rmwr_border_bottom_color', '#000000'));
        $font_size      = self::css(get_option('rmwr_font_size', ''));
        $border_radius  = self::css(get_option('rmwr_border_radius', '0px'));
        $text_transform = self::css(get_option('rmwr_text_transform', 'none'));
        $font_family    = get_option('rmwr_font_family', 'inherit');
        $line_height    = self::css(get_option('rmwr_line_height', ''));
        $letter_spacing = self::css(get_option('rmwr_letter_spacing', ''));
        $print_expand   = '1' === get_option('rmwr_print_expand', '1');

        $background = '' !== $bg_gradient
            ? 'background: ' . $bg_gradient . ';'
            : 'background: ' . $bg_color . ';';

        $font_css = '';
        if ('inherit' !== $font_family) {
            $family   = self::css($font_family);
            $font_css = self::is_google_font($font_family)
                ? 'font-family: "' . $family . '", sans-serif;'
                : 'font-family: ' . $family . ';';
        }

        $css = '.read-link{'
            . 'font-weight:' . $font_weight . ';'
            . 'color:' . $text_color . ';'
            . $background
            . 'padding:' . $padding . ';'
            . 'border-bottom:' . $border_bottom . ' solid ' . $border_color . ';'
            . 'border-radius:' . $border_radius . ';'
            . 'text-transform:' . $text_transform . ';'
            . ($font_size ? 'font-size:' . $font_size . ';' : '')
            . $font_css
            . ($line_height ? 'line-height:' . $line_height . ';' : '')
            . ($letter_spacing ? 'letter-spacing:' . $letter_spacing . ';' : '')
            . '}'
            . '.read-link:hover,.read-link:focus{color:' . $hover_color . ';}'
            . '.read-link:focus-visible{outline:2px solid ' . $text_color . ';outline-offset:2px;}';

        if ($print_expand) {
            $css .= '@media print{.read_div{display:block!important;}.read-link,.rmwr-toggle-all{display:none!important;}}';
        }

        return $css;
    }

    /**
     * Sanitize a value for use inside a CSS declaration. Allows colors,
     * units, gradients and font names; strips anything that could escape the
     * declaration or pull remote resources.
     *
     * @param string $value Raw option value.
     * @return string
     */
    public static function css($value) {
        $value = (string) $value;
        $value = preg_replace('/[^a-zA-Z0-9#%.,()\s\'"+\/-]/', '', $value);
        $value = preg_replace('/(url\s*\(|expression|@import)/i', '', $value);
        return trim((string) $value);
    }

    private static function is_google_font($font_name) {
        $google_fonts = array(
            'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins',
            'Raleway', 'Oswald', 'Source Sans Pro', 'Playfair Display', 'Merriweather',
        );
        return in_array($font_name, $google_fonts, true);
    }

    private static function google_font_url($font_name) {
        return 'https://fonts.googleapis.com/css2?family=' . urlencode($font_name) . ':wght@400;500;600;700&display=swap';
    }

    /**
     * Admin assets (settings + analytics pages only).
     */
    public function enqueue_admin_assets($hook) {
        $our_pages = array(
            'toplevel_page_read_more_without_refresh',
            'rmwr-settings_page_rmwr-analytics',
            'read-more-without-refresh_page_rmwr-analytics',
        );
        $is_ours = in_array($hook, $our_pages, true) || (false !== strpos($hook, 'rmwr-analytics'));

        if (!$is_ours) {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script(
            'rmwr-admin',
            RMWR_PRO_URL . 'js/admin.js',
            array('wp-color-picker', 'jquery'),
            RMWR_PRO_VERSION,
            true
        );
        wp_localize_script('rmwr-admin', 'rmwrAdmin', array(
            'nonce'   => wp_create_nonce('rmwr-admin-nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'restUrl' => esc_url_raw(rest_url('rmwr/v1')),
            'restNonce' => wp_create_nonce('wp_rest'),
        ));

        wp_enqueue_style('rmwr-admin', RMWR_PRO_URL . 'css/admin.css', array(), RMWR_PRO_VERSION);
    }

    /**
     * Block editor assets.
     */
    public function enqueue_block_editor_assets() {
        wp_enqueue_script(
            'rmwr-block-editor',
            RMWR_PRO_URL . 'js/block-editor.js',
            array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'),
            RMWR_PRO_VERSION,
            true
        );

        wp_enqueue_style('rmwr-block-editor', RMWR_PRO_URL . 'css/block-editor.css', array('wp-edit-blocks'), RMWR_PRO_VERSION);

        wp_localize_script('rmwr-block-editor', 'rmwrBlockData', array(
            'defaultOpen'  => get_option('rm_text', 'Read More'),
            'defaultClose' => get_option('rl_text', 'Read Less'),
        ));

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('rmwr-block-editor', 'rmwr');
        }
    }
}
