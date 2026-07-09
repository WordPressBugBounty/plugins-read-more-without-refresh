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

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_menu', array($this, 'register_menu'), 20);
        add_action('admin_init', array($this, 'handle_csv_export'));
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
    }

    /**
     * Drop both tables (called from uninstall.php when the admin opted in).
     */
    public static function drop_tables() {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::events_table()); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DROP TABLE IF EXISTS ' . self::leads_table());  // phpcs:ignore WordPress.DB.PreparedSQL
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
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$events_table} WHERE event = 'expand'");
        $url   = function_exists('rmwr_upgrade_url') ? rmwr_upgrade_url() : 'https://shop.8web.gr/read-more-without-refresh-pro/';

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

        // Free tier: show the teaser instead of the full dashboard.
        if (!rmwr_is_premium()) {
            $this->render_teaser();
            return;
        }

        global $wpdb;
        $events_table = self::events_table();
        $leads_table  = self::leads_table();
        list($where, $range) = $this->range_where();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names + pre-prepared WHERE.
        $totals_raw = $wpdb->get_results("SELECT event, COUNT(*) AS c FROM {$events_table} WHERE {$where} GROUP BY event", OBJECT_K);
        $totals     = array();
        foreach (self::EVENTS as $event) {
            $totals[$event] = isset($totals_raw[$event]) ? (int) $totals_raw[$event]->c : 0;
        }

        $daily = $wpdb->get_results(
            "SELECT DATE(created_at) AS day, COUNT(*) AS c
             FROM {$events_table}
             WHERE event = 'expand' AND created_at >= '" . esc_sql(gmdate('Y-m-d', current_time('timestamp') - 29 * DAY_IN_SECONDS)) . "'
             GROUP BY DATE(created_at) ORDER BY day ASC"
        );

        $per_post = $wpdb->get_results(
            "SELECT post_id, COUNT(*) AS c, MAX(created_at) AS last_event
             FROM {$events_table} WHERE {$where} AND event = 'expand'
             GROUP BY post_id ORDER BY c DESC LIMIT 20"
        );

        $per_instance = $wpdb->get_results(
            "SELECT instance_key, post_id, COUNT(*) AS c, MAX(created_at) AS last_event
             FROM {$events_table} WHERE {$where} AND event = 'expand'
             GROUP BY instance_key, post_id ORDER BY c DESC LIMIT 20"
        );

        $per_variant = $wpdb->get_results(
            "SELECT variant, COUNT(*) AS c
             FROM {$events_table} WHERE {$where} AND event = 'expand' AND variant <> ''
             GROUP BY variant ORDER BY c DESC"
        );

        $lead_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$leads_table}");
        // phpcs:enable

        $max_daily = 0;
        foreach ($daily as $row) {
            $max_daily = max($max_daily, (int) $row->c);
        }
        $daily_map = array();
        foreach ($daily as $row) {
            $daily_map[$row->day] = (int) $row->c;
        }

        $base_url = admin_url('admin.php?page=rmwr-analytics');
        ?>
        <div class="wrap rmwr-analytics-wrap">
            <div class="rmwr-header">
                <div class="rmwr-logo"><span class="dashicons dashicons-chart-bar"></span></div>
                <div class="rmwr-header-text">
                    <h1><?php esc_html_e('Analytics Dashboard', 'rmwr'); ?></h1>
                    <p class="rmwr-subtitle"><?php esc_html_e('Track and analyze how visitors interact with your hidden content.', 'rmwr'); ?></p>
                </div>
                <span class="rmwr-tier-badge rmwr-tier-pro">Pro</span>
            </div>

            <ul class="subsubsub" style="margin-bottom:12px;">
                <?php
                $ranges = array(
                    '7'   => __('Last 7 days', 'rmwr'),
                    '30'  => __('Last 30 days', 'rmwr'),
                    '90'  => __('Last 90 days', 'rmwr'),
                    'all' => __('All time', 'rmwr'),
                );
                $links = array();
                foreach ($ranges as $slug => $label) {
                    $links[] = sprintf(
                        '<li><a href="%s" class="%s">%s</a></li>',
                        esc_url(add_query_arg('range', $slug, $base_url)),
                        $range === $slug ? 'current' : '',
                        esc_html($label)
                    );
                }
                echo implode(' | ', $links); // phpcs:ignore WordPress.Security.EscapeOutput
                ?>
            </ul>
            <div style="clear:both;"></div>

            <div class="rmwr-stats-grid">
                <div class="rmwr-stat-card">
                    <div class="rmwr-stat-icon">📖</div>
                    <div class="rmwr-stat-content">
                        <div class="rmwr-stat-value"><?php echo esc_html(number_format_i18n($totals['expand'])); ?></div>
                        <div class="rmwr-stat-label"><?php esc_html_e('Read More clicks', 'rmwr'); ?></div>
                    </div>
                </div>
                <div class="rmwr-stat-card">
                    <div class="rmwr-stat-icon">🔓</div>
                    <div class="rmwr-stat-content">
                        <div class="rmwr-stat-value"><?php echo esc_html(number_format_i18n($totals['unlock'] + $totals['share'])); ?></div>
                        <div class="rmwr-stat-label"><?php esc_html_e('Content unlocks', 'rmwr'); ?></div>
                    </div>
                </div>
                <div class="rmwr-stat-card">
                    <div class="rmwr-stat-icon">🎯</div>
                    <div class="rmwr-stat-content">
                        <div class="rmwr-stat-value"><?php echo esc_html(number_format_i18n($totals['cta'])); ?></div>
                        <div class="rmwr-stat-label"><?php esc_html_e('CTA clicks', 'rmwr'); ?></div>
                    </div>
                </div>
                <div class="rmwr-stat-card">
                    <div class="rmwr-stat-icon">📧</div>
                    <div class="rmwr-stat-content">
                        <div class="rmwr-stat-value"><?php echo esc_html(number_format_i18n($lead_count)); ?></div>
                        <div class="rmwr-stat-label"><?php esc_html_e('Leads captured (all time)', 'rmwr'); ?></div>
                    </div>
                </div>
            </div>

            <div class="rmwr-analytics-section">
                <h2><?php esc_html_e('Daily Read More clicks (last 30 days)', 'rmwr'); ?></h2>
                <?php if ($max_daily > 0) : ?>
                    <div class="rmwr-chart" role="img" aria-label="<?php esc_attr_e('Daily clicks bar chart', 'rmwr'); ?>">
                        <?php for ($i = 29; $i >= 0; $i--) :
                            $day   = gmdate('Y-m-d', current_time('timestamp') - $i * DAY_IN_SECONDS);
                            $count = isset($daily_map[$day]) ? $daily_map[$day] : 0;
                            $height = $max_daily > 0 ? max(2, round($count / $max_daily * 100)) : 2;
                            ?>
                            <div class="rmwr-chart-bar" style="height:<?php echo (int) $height; ?>%;" title="<?php echo esc_attr($day . ': ' . $count); ?>"></div>
                        <?php endfor; ?>
                    </div>
                <?php else : ?>
                    <p><?php esc_html_e('No clicks recorded yet. Data will appear as soon as visitors start interacting.', 'rmwr'); ?></p>
                <?php endif; ?>
            </div>

            <div class="rmwr-analytics-section">
                <h2><?php esc_html_e('Top content (by page)', 'rmwr'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Page / Post', 'rmwr'); ?></th>
                            <th><?php esc_html_e('Read More clicks', 'rmwr'); ?></th>
                            <th><?php esc_html_e('Last click', 'rmwr'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($per_post)) : ?>
                            <?php foreach ($per_post as $row) :
                                $title = $row->post_id ? get_the_title((int) $row->post_id) : '';
                                $title = $title ?: __('(outside a post: widget, template, etc.)', 'rmwr');
                                $link  = $row->post_id ? get_permalink((int) $row->post_id) : '';
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($link) : ?>
                                            <a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener"><?php echo esc_html($title); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html($title); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo esc_html(number_format_i18n((int) $row->c)); ?></strong></td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->last_event))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="3" style="text-align:center;padding:20px;"><?php esc_html_e('No data for this period.', 'rmwr'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="rmwr-analytics-section">
                <h2><?php esc_html_e('Top instances', 'rmwr'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Instance', 'rmwr'); ?></th>
                            <th><?php esc_html_e('Page', 'rmwr'); ?></th>
                            <th><?php esc_html_e('Clicks', 'rmwr'); ?></th>
                            <th><?php esc_html_e('Last click', 'rmwr'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($per_instance)) : ?>
                            <?php foreach ($per_instance as $row) : ?>
                                <tr>
                                    <td><code><?php echo esc_html($row->instance_key); ?></code></td>
                                    <td><?php echo esc_html($row->post_id ? get_the_title((int) $row->post_id) : '—'); ?></td>
                                    <td><strong><?php echo esc_html(number_format_i18n((int) $row->c)); ?></strong></td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($row->last_event))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="4" style="text-align:center;padding:20px;"><?php esc_html_e('No data for this period.', 'rmwr'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php echo RMWR_AB_Testing::render_dashboard_section($per_variant); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <div class="rmwr-analytics-section">
                <h2><?php esc_html_e('Export Data', 'rmwr'); ?></h2>
                <form method="post" action="" style="display:inline-block;margin-right:10px;">
                    <?php wp_nonce_field('rmwr_export_analytics', 'rmwr_export_nonce'); ?>
                    <input type="hidden" name="rmwr_action" value="export_events">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Export events (CSV)', 'rmwr'); ?></button>
                </form>
                <form method="post" action="" style="display:inline-block;">
                    <?php wp_nonce_field('rmwr_export_analytics', 'rmwr_export_nonce'); ?>
                    <input type="hidden" name="rmwr_action" value="export_leads">
                    <button type="submit" class="button"><?php esc_html_e('Export leads (CSV)', 'rmwr'); ?></button>
                </form>
            </div>
        </div>
        <?php
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

    private function export_events_csv() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results('SELECT instance_key, post_id, event, variant, created_at FROM ' . self::events_table() . ' ORDER BY created_at DESC LIMIT 50000', ARRAY_A);

        $this->send_csv(
            'rmwr-events-' . gmdate('Y-m-d') . '.csv',
            array(__('Instance', 'rmwr'), __('Post ID', 'rmwr'), __('Page', 'rmwr'), __('Event', 'rmwr'), __('Variant', 'rmwr'), __('Date', 'rmwr')),
            array_map(function ($row) {
                return array(
                    $row['instance_key'],
                    $row['post_id'],
                    $row['post_id'] ? get_the_title((int) $row['post_id']) : '',
                    $row['event'],
                    $row['variant'],
                    $row['created_at'],
                );
            }, $rows)
        );
    }

    private function export_leads_csv() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results('SELECT email, instance_key, post_id, consent, synced, created_at FROM ' . self::leads_table() . ' ORDER BY created_at DESC LIMIT 50000', ARRAY_A);

        $this->send_csv(
            'rmwr-leads-' . gmdate('Y-m-d') . '.csv',
            array(__('Email', 'rmwr'), __('Instance', 'rmwr'), __('Post ID', 'rmwr'), __('Consent', 'rmwr'), __('Synced to', 'rmwr'), __('Date', 'rmwr')),
            $rows
        );
    }

    /**
     * Stream a CSV download and exit.
     *
     * @param string $filename Download filename.
     * @param array  $headers  Column headers.
     * @param array  $rows     Data rows.
     */
    private function send_csv($filename, $headers, $rows) {
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel.
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, array_values((array) $row));
        }
        fclose($output);
        exit;
    }
}
