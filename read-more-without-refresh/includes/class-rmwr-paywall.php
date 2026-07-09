<?php
/**
 * Teaser Paywall: the news-site "fade out + subscribe to continue" effect.
 *
 * Usage on any instance:
 *   [read paywall="loggedin"] ... [/read]          -> any logged-in user
 *   [read paywall="subscriber,customer"] ... [/read] -> specific roles
 *   [read paywall="loggedin" paywall_mode="hard" teaser="60"] ... [/read]
 *
 * Modes:
 *  - soft (default): the full content stays in the HTML (search engines
 *    index it), but is visually clamped with a fade-out overlay and a call
 *    to action. Ideal when SEO matters more than exclusivity.
 *  - hard: only the first N words are delivered to visitors without access;
 *    the rest never leaves the server. Ideal for genuinely premium content.
 *
 * Membership plugins (MemberPress, WooCommerce Memberships, PMPro & co.)
 * map their access rules onto WordPress roles/capabilities, so role-based
 * gating covers them; the rmwr_paywall_access filter allows custom logic.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Paywall {

    public function __construct() {
        // Rendering is driven by RMWR_Shortcode; nothing to hook.
    }

    /**
     * Does the current visitor pass the paywall rule?
     *
     * @param string $rule "true"/"loggedin" or a comma-separated role list.
     * @return bool
     */
    public static function user_has_access($rule) {
        $rule = strtolower(trim((string) $rule));

        if ('' === $rule || 'false' === $rule || '0' === $rule) {
            return true; // No paywall.
        }

        if (in_array($rule, array('true', '1', 'loggedin', 'logged-in'), true)) {
            $access = is_user_logged_in();
        } else {
            $access = false;
            if (is_user_logged_in()) {
                $allowed = array_map('trim', explode(',', $rule));
                $user    = wp_get_current_user();
                foreach ((array) $user->roles as $role) {
                    if (in_array(strtolower($role), $allowed, true)) {
                        $access = true;
                        break;
                    }
                }
            }
        }

        /**
         * Filter paywall access for custom membership logic.
         *
         * @param bool   $access Whether the visitor has access.
         * @param string $rule   The raw paywall rule string.
         */
        return (bool) apply_filters('rmwr_paywall_access', $access, $rule);
    }

    /**
     * Render the teaser markup for a visitor WITHOUT access.
     *
     * @param string $content Full sanitized content.
     * @param array  $atts    Shortcode attributes.
     * @param string $key     Instance key.
     * @param int    $post_id Post ID.
     * @return string
     */
    public static function render_teaser($content, $atts, $key, $post_id) {
        $mode = 'hard' === strtolower((string) $atts['paywall_mode']) ? 'hard' : 'soft';

        if ('hard' === $mode) {
            $words = absint($atts['teaser']);
            if ($words < 1) {
                $words = 60;
            }
            list($teaser_html) = RMWR_Shortcode::split_html($content, $words, 'words');
            $style = '';
        } else {
            $height = absint($atts['teaser']);
            if ($height < 1) {
                $height = absint(get_option('rmwr_paywall_teaser', 160));
            }
            $teaser_html = $content;
            $style       = ' style="max-height:' . $height . 'px;"';
        }

        $message = get_option('rmwr_paywall_message', __('The rest of this content is for members only.', 'rmwr'));
        $button  = get_option('rmwr_paywall_button', __('Continue reading', 'rmwr'));
        $url     = get_option('rmwr_paywall_url', '');
        if ('' === $url) {
            $url = wp_login_url(get_permalink($post_id) ?: home_url());
        }

        ob_start();
        ?>
        <div class="rmwr-paywall rmwr-paywall-<?php echo esc_attr($mode); ?>" data-key="<?php echo esc_attr($key); ?>" data-post="<?php echo esc_attr($post_id); ?>">
            <div class="rmwr-paywall-teaser"<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput -- built above from ints. ?>>
                <?php echo do_shortcode($teaser_html); ?>
                <div class="rmwr-paywall-fade" aria-hidden="true"></div>
            </div>
            <div class="rmwr-paywall-cta">
                <p class="rmwr-paywall-msg"><?php echo esc_html($message); ?></p>
                <a class="rmwr-paywall-btn read-link" href="<?php echo esc_url($url); ?>"><?php echo esc_html($button); ?></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
