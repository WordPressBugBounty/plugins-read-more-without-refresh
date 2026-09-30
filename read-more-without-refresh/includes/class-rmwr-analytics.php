<?php
/**
 * Analytics v2: real click analytics in dedicated tables.
 *
 * Replaces the v4 approach (serialized arrays in wp_options written on every
 * pageview) with:
 *  - a custom events table written only when a visitor actually interacts,
 *  - a REST endpoint that works on cached pages (no nonce dependency),
 *  - per-post attribution (see WHICH page a click happened on),
 *  - a dashboard with date ranges, daily trend, per-post / per-instance /
 *    per-variant breakdowns and CSV exports.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Analytics {

    const EVENTS_TABLE = 'rmwr_events';
    const LEADS_TABLE  = 'rmwr_leads';

    /** Allowed event types. */
    const EVENTS = array('expand', 'unlock', 'cta', 'share');

    /** Requests allowed per IP per minute on the tracking endpoint. */
    const RATE_LIMIT = 30;

    /** Days of raw events kept before pruning (all-time totals live in an option). */
    const RETENTION_DAYS = 90;

    /** Option holding lifetime per-event counts, preserved across pruning. */
    const LIFETIME_OPTION = 'rmwr_lifetime_counts';

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_menu', array($this, 'register_menu'), 20);
        add_action('admin_init', array($this, 'handle_csv_export'));

        add_action('rmwr_prune_events', array($this, 'prune_events'));
        if (!wp_next_scheduled('rmwr_prune_events')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'rmwr_prune_events');
        }
    }

    /* ---------------------------------------------------------------------
     * Schema
     * ------------------------------------------------------------------ */

    public static function events_table() {
        global $wpdb;
        return $wpdb->prefix . self::EVENTS_TABLE;
    }

    public static function leads_table() {
        global $wpdb;
        return $wpdb->prefix . self::LEADS_TABLE;
    }

    /**
     * Create/upgrade both tables (activation + version upgrades).
     */
    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $events  = self::events_table();
        $leads   = self::leads_table();

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            instance_key varchar(191) NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event varchar(20) NOT NULL DEFAULT 'expand',
            variant varchar(100) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY instance_key (instance_key),
            KEY post_id (post_id),
            KEY event (event),
            KEY created_at (created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$leads} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(191) NOT NULL,
            instance_key varchar(191) NOT NULL DEFAULT '',
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            consent tinyint(1) NOT NULL DEFAULT 0,
            synced varchar(20) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY email (email),
            KEY created_at (created_at)
        ) {$charset};");

        // Seed lifetime counters from existing rows once, so all-time totals
        // survive later pruning (existing installs keep their full history).
        if (false === get_option(self::LIFETIME_OPTION, false)) {
            $seed = array();
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
            $rows = $wpdb->get_results("SELECT event, COUNT(*) AS c FROM {$events} GROUP BY event", ARRAY_A);
            foreach ((array) $rows as $row) {
                $seed[$row['event']] = (int) $row['c'];
            }
            add_option(self::LIFETIME_OPTION, $seed, '', false);
        }
    }

    /**
     * Drop both tables (called from uninstall.php when the admin opted in).
     */
    public static function drop_tables() {
        global $wpdb;
        wp_clear_scheduled_hook('rmwr_prune_events');
        delete_option(self::LIFETIME_OPTION);
        $wpdb->query('DROP TABLE IF EXISTS ' . self::events_table()); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DROP TABLE IF EXISTS ' . self::leads_table());  // phpcs:ignore WordPress.DB.PreparedSQL
    }

    /* ---------------------------------------------------------------------
     * Lifetime counters + retention (keep the events table bounded)
     * ------------------------------------------------------------------ */

    private static function bump_lifetime($event) {
        $counts = get_option(self::LIFETIME_OPTION, array());
        if (!is_array($counts)) {
            $counts = array();
        }
        $counts[$event] = (isset($counts[$event]) ? (int) $counts[$event] : 0) + 1;
        update_option(self::LIFETIME_OPTION, $counts, false);
    }

    /**
     * All-time count for an event, preserved even after old rows are pruned.
     *
     * @param string $event Event type.
     * @return int
     */
    public static function lifetime_count($event) {
        $counts = get_option(self::LIFETIME_OPTION, array());
        return (is_array($counts) && isset($counts[$event])) ? (int) $counts[$event] : 0;
    }

    /**
     * Delete raw events older than the retention window. Lifetime totals live
     * in an option, so pruning never loses the headline numbers.
     */
    public function prune_events() {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - self::RETENTION_DAYS * DAY_IN_SECONDS);
        $table  = self::events_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; value is prepared.
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
    }

    /* ---------------------------------------------------------------------
     * REST tracking
     * ------------------------------------------------------------------ */

    public function register_routes() {
        register_rest_route('rmwr/v1', '/track', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_track'),
            'permission_callback' => '__return_true', // Public by design: anonymous counters, no personal data.
            'args'                => array(
                'k' => array('type' => 'string', 'required' => true),
                'p' => array('type' => 'integer', 'default' => 0),
                'e' => array('type' => 'string', 'default' => 'expand'),
                'v' => array('type' => 'string', 'default' => ''),
            ),
        ));
    }

    /**
     * Record one interaction event.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle_track($request) {
        if ('1' !== get_option('rmwr_enable_analytics', '1')) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'disabled'), 200);
        }

        if (!$this->within_rate_limit()) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'rate_limited'), 429);
        }

        $key     = substr(sanitize_text_field((string) $request['k']), 0, 191);
        $event   = sanitize_text_field((string) $request['e']);
        $variant = substr(sanitize_text_field((string) $request['v']), 0, 100);
        $post_id = absint($request['p']);

        if ('' === $key || !in_array($event, self::EVENTS, true)) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'invalid'), 400);
        }

        global $wpdb;
        $wpdb->insert(
            self::events_table(),
            array(
                'instance_key' => $key,
                'post_id'      => $post_id,
                'event'        => $event,
                'variant'      => $variant,
                'created_at'   => current_time('mysql'),
            ),
            array('%s', '%d', '%s', '%s', '%s')
        );

        self::bump_lifetime($event);

        return new WP_REST_Response(array('ok' => true), 200);
    }

    /**
     * Store one captured lead (called by RMWR_Locker).
     *
     * @param string $email        Validated email.
     * @param string $instance_key Instance key.
     * @param int    $post_id      Post ID.
     * @param bool   $consent      Consent checkbox state.
     * @param string $synced       Provider slug the lead was pushed to ('' if local only).
     * @return int Lead row ID.
     */
    public static function store_lead($email, $instance_key, $post_id, $consent, $synced = '') {
        global $wpdb;
        $wpdb->insert(
            self::leads_table(),
            array(
                'email'        => substr(sanitize_email($email), 0, 191),
                'instance_key' => substr(sanitize_text_field($instance_key), 0, 191),
                'post_id'      => absint($post_id),
                'consent'      => $consent ? 1 : 0,
                'synced'       => substr(sanitize_text_field($synced), 0, 20),
                'created_at'   => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%d', '%s', '%s')
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Simple per-IP rate limit for the public endpoint.
     *
     * @return bool
     */
    private function within_rate_limit() {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $key = 'rmwr_rl_' . md5($ip);

        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            return false;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);

        return true;
    }

    /* ---------------------------------------------------------------------
     * Dashboard
     * ------------------------------------------------------------------ */

    public function register_menu() {
        add_submenu_page(
            'read_more_without_refresh',
            __('Analytics', 'rmwr'),
            __('Analytics', 'rmwr'),
            'manage_options',
            'rmwr-analytics',
            array($this, 'render_page')
        );
    }

    /**
     * Resolve the current date-range filter to a SQL WHERE fragment.
     *
     * @return array{0:string,1:string} [where_sql, active_range]
     */
    private function range_where() {
        $range   = isset($_GET['range']) ? sanitize_text_field(wp_unslash($_GET['range'])) : '30';
        $allowed = array('7', '30', '90', 'all');
        if (!in_array($range, $allowed, true)) {
            $range = '30';
        }

        if ('all' === $range) {
            return array('1=1', $range);
        }

        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', current_time('timestamp') - (DAY_IN_SECONDS * (int) $range));
        return array($wpdb->prepare('created_at >= %s', $since), $range);
    }

    /**
     * Free-tier teaser: the site's own real total-expand count, with the
     * detailed breakdown locked behind an upgrade CTA. Shown instead of the
     * full dashboard on unlicensed installs.
     */
    private function render_teaser() {
        global $wpdb;
        $events_table = self::events_table();
        $total  = self::lifetime_count('expand');
        $since  = gmdate('Y-m-d H:i:s', current_time('timestamp') - DAY_IN_SECONDS);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; value prepared.
        $last24 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events_table} WHERE event = 'expand' AND created_at >= %s", $since));
        $url    = function_exists('rmwr_upgrade_url') ? rmwr_upgrade_url() : 'https://shop.8web.gr/read-more-without-refresh-pro/';

        // Representative dummy data shown faded behind the lock, so the free
        // tier previews the real dashboard.
        $dummy_stats = array(
            array('📖', '1,284', __('Read More clicks', 'rmwr')),
            array('🔓', '342', __('Content unlocks', 'rmwr')),
            array('🎯', '96', __('CTA clicks', 'rmwr')),
            array('📧', '58', __('Leads captured', 'rmwr')),
        );
        $dummy_bars = array(30, 45, 38, 62, 55, 70, 48, 80, 66, 74, 90, 60, 52, 68, 84, 72, 58, 95, 78, 64, 70, 88, 54, 76, 82, 60, 92, 70, 86, 100);
        $dummy_rows = array(
            array(__('Pricing page', 'rmwr'), 128, '82%'),
            array(__('Product: Wireless Headphones', 'rmwr'), 96, '71%'),
            array(__('FAQ - Shipping & Returns', 'rmwr'), 74, '64%'),
            array(__('Blog: Ultimate Buying Guide', 'rmwr'), 51, '48%'),
        );
        ?>
        <div class="wrap rmwr-analytics-wrap">
            <div class="rmwr-header">
                <div class="rmwr-logo"><span class="dashicons dashicons-chart-bar"></span></div>
                <div class="rmwr-header-text">
                    <h1><?php esc_html_e('Analytics Dashboard', 'rmwr'); ?></h1>
                    <p class="rmwr-subtitle"><?php esc_html_e('See how your visitors engage with your hidden content.', 'rmwr'); ?></p>
                </div>
                <span class="rmwr-tier-badge rmwr-tier-pro">Pro</span>
            </div>

            <div class="rmwr-free-live" style="display:flex;gap:26px;flex-wrap:wrap;align-items:baseline;background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:16px 20px;margin-bottom:16px;">
                <div>
                    <div style="font-size:30px;font-weight:700;color:#7c3aed;line-height:1;"><?php echo esc_html(number_format_i18n($last24)); ?></div>
                    <div style="font-size:12px;color:#646970;margin-top:4px;"><?php esc_html_e('Read More expands in the last 24 hours', 'rmwr'); ?></div>
                </div>
                <div>
                    <div style="font-size:20px;font-weight:700;color:#1d2327;line-height:1;"><?php echo esc_html(number_format_i18n($total)); ?></div>
                    <div style="font-size:12px;color:#646970;margin-top:4px;"><?php esc_html_e('all time', 'rmwr'); ?></div>
                </div>
                <div style="margin-left:auto;font-size:12px;color:#646970;max-width:300px;">
                    <?php esc_html_e('This is your free 24-hour view. Pro unlocks full history, per-page and per-button breakdowns, an engagement heatmap and CSV export.', 'rmwr'); ?>
                </div>
            </div>

            <div class="rmwr-analytics-teaser">
                <div class="rmwr-teaser-dummy" aria-hidden="true">
                    <div class="rmwr-stats-grid">
                        <?php foreach ($dummy_stats as $stat) : ?>
                            <div class="rmwr-stat-card">
                                <div class="rmwr-stat-icon"><?php echo esc_html($stat[0]); ?></div>
                                <div>
                                    <div class="rmwr-stat-value"><?php echo esc_html($stat[1]); ?></div>
                                    <div class="rmwr-stat-label"><?php echo esc_html($stat[2]); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rmwr-analytics-section">
                        <h2><?php esc_html_e('Daily Read More clicks', 'rmwr'); ?></h2>
                        <div class="rmwr-chart">
                            <?php foreach ($dummy_bars as $h) : ?>
                                <div class="rmwr-chart-bar" style="height:<?php echo (int) $h; ?>%;"></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="rmwr-analytics-section">
                        <h2><?php esc_html_e('Top content by engagement', 'rmwr'); ?></h2>
                        <table class="wp-list-table widefat fixed striped">
                            <thead><tr><th><?php esc_html_e('Page / Button', 'rmwr'); ?></th><th><?php esc_html_e('Expands', 'rmwr'); ?></th><th><?php esc_html_e('Engagement rate', 'rmwr'); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ($dummy_rows as $row) : ?>
                                    <tr><td><?php echo esc_html($row[0]); ?></td><td><strong><?php echo esc_html($row[1]); ?></strong></td><td><?php echo esc_html($row[2]); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rmwr-teaser-lock">
                    <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                    <h2><?php esc_html_e('Unlock your Analytics with Pro', 'rmwr'); ?></h2>
                    <?php if ($total > 0) : ?>
                        <p>
                            <?php
                            printf(
                                /* translators: %s: number of expands, already wrapped in markup */
                                esc_html__('Your Read More buttons have already been expanded %s so far. Upgrade to see exactly which pages and buttons drive it.', 'rmwr'),
                                '<span class="rmwr-teaser-real">' . esc_html(number_format_i18n($total)) . ' ' . esc_html__('times', 'rmwr') . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput
                            );
                            ?>
                        </p>
                    <?php else : ?>
                        <p><?php esc_html_e('Per-page and per-button engagement, daily trend, date ranges, captured leads and CSV export - all cache-proof.', 'rmwr'); ?></p>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($url); ?>" class="button button-primary button-large"><?php esc_html_e('Upgrade to Pro', 'rmwr'); ?></a>
                    <p style="margin:12px 0 0;font-size:12px;color:#8c8f94;"><?php esc_html_e('Secure checkout right here in your dashboard.', 'rmwr'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'rmwr'));
        }

        // Free tier (and any build shipped without the Pro modules) shows the
        // teaser instead of the full dashboard.
        if (!rmwr_is_premium() || !class_exists('RMWR_AB_Testing')) {
            $this->render_teaser();
            return;
        }

        
    }

    /* ---------------------------------------------------------------------
     * CSV export
     * ------------------------------------------------------------------ */

    public function handle_csv_export() {
        if (!isset($_GET['page']) || 'rmwr-analytics' !== $_GET['page'] || !isset($_POST['rmwr_action'])) {
            return;
        }

        if (!isset($_POST['rmwr_export_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rmwr_export_nonce'])), 'rmwr_export_analytics')) {
            wp_die(esc_html__('Security check failed', 'rmwr'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'rmwr'));
        }

        $action = sanitize_text_field(wp_unslash($_POST['rmwr_action']));
        if ('export_events' === $action) {
            $this->export_events_csv();
        } elseif ('export_leads' === $action) {
            $this->export_leads_csv();
        }
    }

    
}
