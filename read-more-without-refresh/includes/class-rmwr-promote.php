<?php
/**
 * Admin promotion: a Dashboard stats widget and a one-time upgrade notice.
 *
 * Puts the plugin's real value (how often readers expand content) and the Pro
 * offer where site owners actually look - the WordPress Dashboard and admin
 * notices - instead of only in a settings sidebar they rarely revisit. All
 * upsell parts are free-tier only.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Promote {

    /** User meta flag: the upgrade notice was dismissed by this user. */
    const NOTICE_META = 'rmwr_promo_dismissed';

    /** Only show the notice once the plugin has recorded some real usage. */
    const MIN_EXPANDS = 20;

    /** User meta flag: the "what's new" notice was dismissed by this user. */
    const WHATSNEW_META = 'rmwr_whatsnew_420_dismissed';

    public function __construct() {
        add_action('wp_dashboard_setup', array($this, 'register_widget'));
        add_action('admin_notices', array($this, 'maybe_whats_new'));
        add_action('admin_notices', array($this, 'maybe_render_notice'));
        add_action('admin_init', array($this, 'handle_dismiss'));
    }

    /* ---------------------------------------------------------------------
     * Stats (reads the analytics events the plugin already records)
     * ------------------------------------------------------------------ */

    /**
     * Count "expand" events, optionally since a datetime (local mysql format).
     *
     * @param string $since Optional 'Y-m-d H:i:s' lower bound.
     * @return int
     */
    private static function expands($since = '') {
        if (!class_exists('RMWR_Analytics')) {
            return 0;
        }

        // All-time: read the lifetime counter so the number is correct even
        // after old raw rows are pruned. Windowed: query the events table.
        if ('' === $since) {
            return RMWR_Analytics::lifetime_count('expand');
        }

        global $wpdb;
        $table = RMWR_Analytics::events_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; value is prepared.
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event = 'expand' AND created_at >= %s", $since));
        return null === $count ? 0 : (int) $count;
    }

    /** Local 'Y-m-d H:i:s' N hours ago (matches how created_at is stored). */
    private static function since_hours($hours) {
        return gmdate('Y-m-d H:i:s', current_time('timestamp') - ((int) $hours * HOUR_IN_SECONDS));
    }

    private static function is_pro() {
        return function_exists('rmwr_is_premium') && rmwr_is_premium();
    }

    private static function upgrade_url() {
        return function_exists('rmwr_upgrade_url')
            ? rmwr_upgrade_url()
            : 'https://shop.8web.gr/read-more-without-refresh-pro/';
    }

    /* ---------------------------------------------------------------------
     * Dashboard widget (both tiers - stats always useful)
     * ------------------------------------------------------------------ */

    public function register_widget() {
        if (!current_user_can('edit_posts')) {
            return;
        }
        wp_add_dashboard_widget(
            'rmwr_dashboard_widget',
            __('Read More Without Refresh', 'rmwr'),
            array($this, 'render_widget')
        );
    }

    public function render_widget() {
        $total  = self::expands();
        $day    = self::expands(self::since_hours(24));
        $is_pro = self::is_pro();
        echo self::styles(); // phpcs:ignore WordPress.Security.EscapeOutput
        ?>
        <div class="rmwr-promo rmwr-promo-widget">
            <div class="rmwr-promo-stats">
                <div class="rmwr-promo-stat">
                    <span class="rmwr-promo-num" data-to="<?php echo esc_attr($total); ?>">0</span>
                    <span class="rmwr-promo-lbl"><?php esc_html_e('Read More expands', 'rmwr'); ?></span>
                </div>
                <div class="rmwr-promo-stat rmwr-promo-stat-sm">
                    <span class="rmwr-promo-num" data-to="<?php echo esc_attr($day); ?>">0</span>
                    <span class="rmwr-promo-lbl"><?php esc_html_e('in the last 24 hours', 'rmwr'); ?></span>
                </div>
            </div>

            <?php if (0 === $total) : ?>
                <p class="rmwr-promo-empty">
                    <?php esc_html_e('Add a [read] block or shortcode to a page to start tracking reader engagement.', 'rmwr'); ?>
                </p>
            <?php endif; ?>

            <?php if ($is_pro) : ?>
                <a class="rmwr-promo-link" href="<?php echo esc_url(admin_url('admin.php?page=rmwr-analytics')); ?>">
                    <?php esc_html_e('View full analytics', 'rmwr'); ?> &rarr;
                </a>
            <?php else : ?>
                <div class="rmwr-promo-cta-box">
                    <p><?php esc_html_e('See which pages and buttons drive these expands, capture readers as email leads, and add FAQ schema - with Pro.', 'rmwr'); ?></p>
                    <a class="rmwr-promo-btn" href="<?php echo esc_url(self::upgrade_url()); ?>">
                        <?php esc_html_e('Start your 3-day free trial', 'rmwr'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <script>
        (function(){
            var els = document.querySelectorAll('#rmwr_dashboard_widget .rmwr-promo-num');
            Array.prototype.forEach.call(els, function(el){
                var to = parseInt(el.getAttribute('data-to'), 10) || 0, cur = 0,
                    step = Math.max(1, Math.ceil(to / 40));
                if (to === 0) { el.textContent = '0'; return; }
                var t = setInterval(function(){
                    cur += step;
                    if (cur >= to) { cur = to; clearInterval(t); }
                    el.textContent = cur.toLocaleString();
                }, 20);
            });
        })();
        </script>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Upgrade notice (free tier only, after real usage, dismissible)
     * ------------------------------------------------------------------ */

    public function maybe_render_notice() {
        if (!current_user_can('manage_options') || self::is_pro()) {
            return;
        }
        if (get_user_meta(get_current_user_id(), self::NOTICE_META, true)) {
            return;
        }
        if (!$this->on_allowed_screen()) {
            return;
        }
        $total = self::expands();
        if ($total < self::MIN_EXPANDS) {
            return; // Not enough real usage yet - do not nag.
        }

        $dismiss = wp_nonce_url(add_query_arg('rmwr_dismiss_promo', '1'), 'rmwr_dismiss_promo');
        echo self::styles(); // phpcs:ignore WordPress.Security.EscapeOutput
        ?>
        <div class="rmwr-promo rmwr-promo-notice notice">
            <a class="rmwr-promo-x" href="<?php echo esc_url($dismiss); ?>" aria-label="<?php esc_attr_e('Dismiss', 'rmwr'); ?>">&times;</a>
            <span class="rmwr-promo-logo dashicons dashicons-text"></span>
            <div class="rmwr-promo-body">
                <strong><?php
                    printf(
                        /* translators: %s: formatted number of expands */
                        esc_html__('Your readers have expanded content %s times with Read More Without Refresh.', 'rmwr'),
                        '<span class="rmwr-promo-hl">' . esc_html(number_format_i18n($total)) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput
                    );
                ?></strong>
                <span><?php esc_html_e('See exactly which pages drive it, capture those readers as leads, and add FAQ schema - with Pro.', 'rmwr'); ?></span>
            </div>
            <a class="rmwr-promo-btn" href="<?php echo esc_url(self::upgrade_url()); ?>">
                <?php esc_html_e('Start your 3-day free trial', 'rmwr'); ?>
            </a>
        </div>
        <?php
    }

    private function on_allowed_screen() {
        if (!function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }
        $id = (string) $screen->id;
        // Dashboard and Plugins only - our own settings/analytics pages already
        // carry the upgrade card, so a notice there just crowds the screen.
        return in_array($id, array('dashboard', 'plugins'), true);
    }

    public function handle_dismiss() {
        if (isset($_GET['rmwr_dismiss_promo'])) {
            check_admin_referer('rmwr_dismiss_promo');
            update_user_meta(get_current_user_id(), self::NOTICE_META, 1);
            wp_safe_redirect(remove_query_arg(array('rmwr_dismiss_promo', '_wpnonce')));
            exit;
        }
        if (isset($_GET['rmwr_dismiss_whatsnew'])) {
            check_admin_referer('rmwr_dismiss_whatsnew');
            update_user_meta(get_current_user_id(), self::WHATSNEW_META, 1);
            wp_safe_redirect(remove_query_arg(array('rmwr_dismiss_whatsnew', '_wpnonce')));
            exit;
        }
    }

    /* ---------------------------------------------------------------------
     * One-time "What's new" notice (both tiers, dismissible)
     * ------------------------------------------------------------------ */

    public function maybe_whats_new() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (get_user_meta(get_current_user_id(), self::WHATSNEW_META, true)) {
            return;
        }
        // Only on the Dashboard and Plugins list. Our own settings/analytics
        // pages already sell Pro, so showing it there just crowds the page.
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, array('dashboard', 'plugins'), true)) {
            return;
        }

        $dismiss = wp_nonce_url(add_query_arg('rmwr_dismiss_whatsnew', '1'), 'rmwr_dismiss_whatsnew');
        echo self::styles(); // phpcs:ignore WordPress.Security.EscapeOutput
        ?>
        <div class="rmwr-promo rmwr-promo-notice notice">
            <a class="rmwr-promo-x" href="<?php echo esc_url($dismiss); ?>" aria-label="<?php esc_attr_e('Dismiss', 'rmwr'); ?>">&times;</a>
            <span class="rmwr-promo-logo dashicons dashicons-megaphone"></span>
            <div class="rmwr-promo-body">
                <strong><?php esc_html_e('New in Read More Without Refresh', 'rmwr'); ?></strong>
                <span><?php esc_html_e('AI Answer Optimizer (llms.txt + IndexNow), engagement events to GA4 / Meta / Google Ads, bulk paragraph auto-collapse, a reading-progress bar, and an engagement heatmap.', 'rmwr'); ?></span>
            </div>
            <?php if (!self::is_pro()) : ?>
                <a class="rmwr-promo-btn" href="<?php echo esc_url(self::upgrade_url()); ?>"><?php esc_html_e('See what Pro unlocks', 'rmwr'); ?></a>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Shared, self-contained styles (Dashboard/notices do not load admin.css)
     * ------------------------------------------------------------------ */

    private static function styles() {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;
        return '<style>
        .rmwr-promo{--a:#7c3aed;--ad:#6d28d9;--soft:rgba(124,58,237,.12);--r:10px}
        .rmwr-promo-widget .rmwr-promo-stats{display:flex;gap:22px;align-items:baseline;flex-wrap:wrap}
        .rmwr-promo-stat{display:flex;flex-direction:column}
        .rmwr-promo-num{font-size:34px;font-weight:700;line-height:1;color:var(--a)}
        .rmwr-promo-stat-sm .rmwr-promo-num{font-size:20px;color:#1d2327}
        .rmwr-promo-lbl{font-size:12px;color:#646970;margin-top:4px}
        .rmwr-promo-empty{color:#646970;margin:12px 0 0}
        .rmwr-promo-link{display:inline-block;margin-top:14px;color:var(--ad);font-weight:600;text-decoration:none}
        .rmwr-promo-cta-box{margin-top:16px;padding:14px;background:var(--soft);border-radius:var(--r)}
        .rmwr-promo-cta-box p{margin:0 0 10px;color:#1d2327}
        .rmwr-promo-btn{display:inline-block;background:var(--a);color:#fff!important;font-weight:600;
            padding:9px 18px;border-radius:8px;text-decoration:none!important;box-shadow:none;border:0;
            transition:background .15s,transform .15s;white-space:nowrap}
        .rmwr-promo-btn:hover,.rmwr-promo-btn:focus{background:var(--ad);color:#fff!important;text-decoration:none!important;transform:translateY(-1px);box-shadow:0 2px 8px rgba(124,58,237,.35)}
        .rmwr-promo-notice{position:relative;display:flex;gap:14px;align-items:center;flex-wrap:wrap;
            border:1px solid #e6dcff;border-left:4px solid var(--a);border-radius:var(--r);
            background:#fff;padding:14px 40px 14px 16px;margin:14px 0;
            animation:rmwrSlide .35s ease both}
        @keyframes rmwrSlide{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
        .rmwr-promo-logo{color:var(--a);font-size:24px;width:24px;height:24px}
        .rmwr-promo-body{flex:1 1 260px;display:flex;flex-direction:column;gap:2px}
        .rmwr-promo-body strong{font-size:14px;color:#1d2327}
        .rmwr-promo-body span{font-size:13px;color:#646970}
        .rmwr-promo-hl{color:var(--a)}
        .rmwr-promo-x{position:absolute;top:10px;right:12px;color:#a7aaad!important;text-decoration:none!important;
            font-size:18px;line-height:20px;width:22px;height:22px;text-align:center;border-radius:4px}
        .rmwr-promo-x:hover,.rmwr-promo-x:focus{color:#50575e!important;background:rgba(0,0,0,.06);text-decoration:none!important}
        </style>';
    }
}
