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
        add_action('wp_footer', array($this, 'print_powered_by'), 99);
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
     * Footer credit. Printed once per page, only when a widget rendered.
     *
     * The HTML comment is always emitted (an invisible attribution backlink
     * pointing at the plugin's own directory listing, not at 8web). The
     * visible "Powered by" link is opt-in via a setting and off by default,
     * so it never appears on a site without the owner enabling it.
     */
    public function print_powered_by() {
        if (!self::$needed) {
            return;
        }

        echo "\n<!-- Read More Without Refresh - https://wordpress.org/plugins/read-more-without-refresh/ -->\n";

        if ('1' !== get_option('rmwr_show_powered_by', '0')) {
            return;
        }

        printf(
            '<div class="rmwr-powered-by" style="text-align:center;font-size:11px;opacity:.6;margin:8px 0;">%s <a href="%s" target="_blank" rel="noopener">%s</a></div>' . "\n",
            esc_html__('Powered by', 'rmwr'),
            'https://wordpress.org/plugins/read-more-without-refresh/',
            esc_html__('Read More Without Refresh', 'rmwr')
        );
    }

    /**
     * Ad / analytics conversion-event config passed to the frontend. Lets the
     * site fire a high-intent "reader engaged" event into GA4, Meta Pixel,
     * Google Ads or GTM's dataLayer whenever a visitor expands, unlocks or
     * converts - so engaged readers can be measured and retargeted.
     *
     * Premium feature: on the free tier (or when disabled) it returns a single
     * "off" flag and the frontend fires nothing.
     *
     * @return array
     */
    private static function ad_events_config() {
        $enabled = rmwr_is_premium() && '1' === get_option('rmwr_ad_events', '0');
        if (!$enabled) {
            return array('enabled' => false);
        }

        return array(
            'enabled'         => true,
            'ga4'             => '1' === get_option('rmwr_ad_ga4', '1'),
            'metaPixel'       => '1' === get_option('rmwr_ad_meta', '0'),
            'googleAds'       => '1' === get_option('rmwr_ad_google_ads', '0'),
            'googleAdsSendTo' => trim((string) get_option('rmwr_ad_google_ads_send_to', '')),
            'dataLayer'       => '1' === get_option('rmwr_ad_datalayer', '0'),
            'gaEventName'     => trim((string) get_option('rmwr_ad_ga_event_name', '')),
            'metaEventName'   => trim((string) get_option('rmwr_ad_meta_event_name', '')),
        );
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

        // Taxonomy descriptions: a [read]/[read_all] shortcode placed manually
        // in the term description, or the Pro auto-apply taxonomy feature.
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            $desc = ($term instanceof WP_Term) ? (string) $term->description : '';
            if (RMWR_Auto_Apply::taxonomy_enabled()
                || has_shortcode($desc, 'read')
                || has_shortcode($desc, 'read_all')) {
                self::$needed = true;
            }
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
            'adEvents'      => self::ad_events_config(),
            'readingProgress' => array(
                'bar'    => '1' === get_option('rmwr_reading_progress', '0'),
                'resume' => '1' === get_option('rmwr_reading_resume', '0'),
            ),
            'resumeText'    => __('Continue reading', 'rmwr'),
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
        // Free (non-grandfathered) sites inherit the theme's link style: we
        // emit only the accessibility outline and the print rule, no colors,
        // borders, fonts or spacing. Styling is a Pro feature.
        if (!rmwr_style_allowed()) {
            $css = '.read-link:focus-visible{outline:2px solid currentColor;outline-offset:2px;}';
            if ('1' === get_option('rmwr_print_expand', '1')) {
                $css .= '@media print{.read_div{display:block!important;}.read-link,.rmwr-toggle-all{display:none!important;}}';
            }
            return $css;
        }

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
     * Purple-branded CSS for the Freemius in-dashboard pricing table.
     * Targets the fs-* classes rendered by freemius-pricing.js.
     *
     * @return string
     */
    private static function fs_pricing_css() {
        // Colour only - no transforms, scaling or layout changes. Restyling the
        // layout of the hosted React pricing widget breaks its alignment (the
        // per-plan license selector shares a grid across columns), so we keep
        // Freemius' proven layout and only apply the brand purple.
        return <<<'CSS'
#fs_pricing img.fs-plugin-logo,#fs_pricing .fs-plugin-logo>img,#fs_pricing .fs-plugin-title-and-logo img{content:url('https://ps.w.org/read-more-without-refresh/assets/icon-256x256.gif?rev=2288266') !important;width:72px !important;height:72px !important;object-fit:contain !important}
#fs_pricing .fs-package-tab--selected{background:#7c3aed !important;color:#fff !important}
#fs_pricing .fs-packages{gap:26px !important;padding:10px 0 !important}
#fs_pricing .fs-package{border-radius:24px !important;overflow:hidden !important;border:1px solid #ececf3 !important;box-shadow:0 10px 34px rgba(17,24,39,.08) !important}
#fs_pricing .fs-plan-features svg,#fs_pricing .fs-plan-features .fs-icon,#fs_pricing .fs-support-and-main-features svg,#fs_pricing .fs-feature svg{color:#7c3aed !important;fill:#7c3aed !important}
#fs_pricing .fs-upgrade-button-container button,#fs_pricing .fs-upgrade-button-container a,#fs_pricing .fs-round-button{background:#7c3aed !important;color:#fff !important;border-color:#7c3aed !important;font-weight:700 !important;border-radius:12px !important}
#fs_pricing .fs-upgrade-button-container button:hover,#fs_pricing .fs-upgrade-button-container a:hover,#fs_pricing .fs-round-button:hover{background:#6d28d9 !important;border-color:#6d28d9 !important}
#fs_pricing .fs-button--outline{background:transparent !important;color:#7c3aed !important;border:2px solid #7c3aed !important}
#fs_pricing .fs-button--outline:hover{background:rgba(124,58,237,.08) !important}
#fs_pricing .fs-annual-discount,#fs_pricing .fs-license-quantity-discount{background:rgba(124,58,237,.12) !important;color:#6d28d9 !important}
#fs_pricing .fs-license-quantity-price{color:#6d28d9 !important;font-weight:700 !important}
#fs_pricing input[type="radio"],#fs_pricing input[type="checkbox"]{accent-color:#7c3aed}
#fs_pricing .fs-money-back-guarantee-title,#fs_pricing .fs-trial-message{color:#6d28d9 !important}
#fs_pricing .fs-testimonial-rating svg{color:#f0b849 !important;fill:#f0b849 !important}
CSS;
    }

    /**
     * Admin assets (settings + analytics pages only).
     */
    public function enqueue_admin_assets($hook) {
        // Freemius in-dashboard pricing page: the Freemius "Checkout Custom CSS"
        // only styles the hosted checkout, not this in-WP pricing table, so we
        // brand the plans table in our own purple from here.
        if (false !== strpos($hook, 'read_more_without_refresh') && false !== strpos($hook, 'pricing')) {
            wp_register_style('rmwr-fs-pricing', false, array(), RMWR_PRO_VERSION);
            wp_enqueue_style('rmwr-fs-pricing');
            wp_add_inline_style('rmwr-fs-pricing', self::fs_pricing_css());
            return;
        }

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
