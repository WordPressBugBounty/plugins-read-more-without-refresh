<?php
/**
 * Engaged-User CTA Slot.
 *
 * A visitor who clicks "Read More" is engaged RIGHT NOW - the best moment to
 * show an offer. This module injects a configurable CTA box (any HTML or
 * shortcode: newsletter form, product banner, coupon, ad slot) right after
 * the content a visitor just expanded, optionally after a short delay.
 * Clicks on links/buttons inside the CTA are tracked as 'cta' events in the
 * Analytics dashboard, so the owner can measure conversion.
 *
 * Display modes:
 *  - all:    show after every expanded instance (default when enabled)
 *  - optin:  show only on instances with cta="true"
 * Individual instances can always opt out with cta="false".
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_CTA {

    public function __construct() {
        // Injection happens client-side; config ships via RMWR_Assets.
    }

    /**
     * Is the CTA feature enabled with actual content?
     *
     * @return bool
     */
    public static function is_enabled() {
        return '1' === get_option('rmwr_cta_enable', '0')
            && '' !== trim((string) get_option('rmwr_cta_content', ''));
    }

    /**
     * Should this instance show the CTA?
     *
     * @param string $attr Per-instance cta attribute ('', 'true', 'false').
     * @return bool
     */
    public static function should_show($attr) {
        if (!self::is_enabled()) {
            return false;
        }

        $attr = strtolower(trim((string) $attr));
        if (in_array($attr, array('false', '0', 'off'), true)) {
            return false;
        }
        if (in_array($attr, array('true', '1', 'on'), true)) {
            return true;
        }

        return 'all' === get_option('rmwr_cta_mode', 'all');
    }

    /**
     * CTA payload for the frontend script.
     *
     * @return array{html:string, delay:int}
     */
    public static function get_frontend_config() {
        if (!self::is_enabled()) {
            return array('html' => '', 'delay' => 0);
        }

        $html = do_shortcode(wp_kses_post((string) get_option('rmwr_cta_content', '')));

        return array(
            'html'  => $html,
            'delay' => absint(get_option('rmwr_cta_delay', 0)),
        );
    }
}
