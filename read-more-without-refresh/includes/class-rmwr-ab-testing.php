<?php
/**
 * A/B testing for the Read More button text.
 *
 * Admins define up to 5 text variants. Each visitor is randomly assigned one
 * variant (persisted in localStorage so the assignment is stable), the
 * variant is reported with every expand event, and the Analytics dashboard
 * shows clicks per variant with a one-click "apply winner" action.
 *
 * Because assignment is random and even, raw click counts are directly
 * comparable between variants without needing impression tracking.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_AB_Testing {

    const MAX_VARIANTS = 5;

    public function __construct() {
        add_action('admin_post_rmwr_ab_apply', array($this, 'handle_apply_winner'));
    }

    /**
     * A/B testing is active when at least two variants are configured.
     *
     * @return bool
     */
    public static function is_enabled() {
        return count(self::get_variants()) >= 2;
    }

    /**
     * Parse the configured variants ("Text A|Text B|Text C").
     *
     * @return string[]
     */
    public static function get_variants() {
        $raw = (string) get_option('rmwr_ab_variants', '');
        if ('' === trim($raw)) {
            return array();
        }

        $variants = array_filter(array_map('trim', explode('|', $raw)));

        return array_slice(array_values($variants), 0, self::MAX_VARIANTS);
    }

    /**
     * Render the variants table inside the Analytics dashboard.
     *
     * @param array $per_variant Rows {variant, c} from the events table.
     * @return string
     */
    public static function render_dashboard_section($per_variant) {
        if (!self::is_enabled() && empty($per_variant)) {
            return '';
        }

        ob_start();
        ?>
        <div class="rmwr-analytics-section">
            <h2><?php esc_html_e('A/B Testing - button text variants', 'rmwr'); ?></h2>
            <?php if (self::is_enabled()) : ?>
                <p class="description">
                    <?php esc_html_e('Visitors are split randomly and evenly between variants, so click counts are directly comparable. Apply the winner to make it the permanent button text.', 'rmwr'); ?>
                </p>
            <?php else : ?>
                <p class="description">
                    <?php esc_html_e('A/B testing is currently disabled (fewer than two variants configured in Settings). Historical results are shown below.', 'rmwr'); ?>
                </p>
            <?php endif; ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Variant', 'rmwr'); ?></th>
                        <th><?php esc_html_e('Read More clicks', 'rmwr'); ?></th>
                        <th><?php esc_html_e('Action', 'rmwr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rows = array();
                    foreach ((array) $per_variant as $row) {
                        $rows[$row->variant] = (int) $row->c;
                    }
                    foreach (self::get_variants() as $variant) {
                        if (!isset($rows[$variant])) {
                            $rows[$variant] = 0;
                        }
                    }
                    arsort($rows);
                    ?>
                    <?php if (!empty($rows)) : ?>
                        <?php foreach ($rows as $variant => $count) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($variant); ?></strong></td>
                                <td><?php echo esc_html(number_format_i18n($count)); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('rmwr_ab_apply', 'rmwr_ab_nonce'); ?>
                                        <input type="hidden" name="action" value="rmwr_ab_apply">
                                        <input type="hidden" name="variant" value="<?php echo esc_attr($variant); ?>">
                                        <button type="submit" class="button button-small">
                                            <?php esc_html_e('Use as permanent text', 'rmwr'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="3" style="text-align:center;padding:20px;"><?php esc_html_e('No variant data yet.', 'rmwr'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * "Use as permanent text": set rm_text to the winning variant and end the test.
     */
    public function handle_apply_winner() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'rmwr'));
        }

        if (!isset($_POST['rmwr_ab_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rmwr_ab_nonce'])), 'rmwr_ab_apply')) {
            wp_die(esc_html__('Security check failed', 'rmwr'));
        }

        $variant = isset($_POST['variant']) ? sanitize_text_field(wp_unslash($_POST['variant'])) : '';
        if ('' !== $variant) {
            update_option('rm_text', $variant);
            update_option('rmwr_ab_variants', ''); // End the experiment.
        }

        wp_safe_redirect(add_query_arg(array('page' => 'rmwr-analytics', 'ab_applied' => '1'), admin_url('admin.php')));
        exit;
    }
}
