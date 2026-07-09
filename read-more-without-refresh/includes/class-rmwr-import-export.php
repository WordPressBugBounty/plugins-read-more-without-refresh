<?php
/**
 * Settings Import/Export.
 *
 * Exports every plugin option to a JSON file and imports it back on another
 * site - built for agencies configuring the plugin across many client
 * installs. License data is intentionally excluded (site-specific).
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Import_Export {

    /** Options that must never travel between sites. */
    const EXCLUDED = array('rmwr_license_key', 'rmwr_license_status', 'rmwr_license_expires', 'rmwr_db_version');

    public function __construct() {
        add_action('admin_post_rmwr_export_settings', array($this, 'handle_export'));
        add_action('admin_post_rmwr_import_settings', array($this, 'handle_import'));
    }

    /**
     * All exportable option names: the legacy three + everything rmwr_*.
     *
     * @return string[]
     */
    private function option_keys() {
        global $wpdb;

        $keys = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rmwr\\_%'"
        );
        $keys = array_merge(array('rm_text', 'rl_text', 'rl_pro_option'), (array) $keys);

        return array_values(array_diff(array_unique($keys), self::EXCLUDED));
    }

    public function handle_export() {
        $this->verify('rmwr_tools_action');

        $payload = array();
        foreach ($this->option_keys() as $key) {
            $value = get_option($key, null);
            if (null !== $value) {
                $payload[$key] = $value;
            }
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="rmwr-settings-' . gmdate('Y-m-d') . '.json"');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    public function handle_import() {
        $this->verify('rmwr_tools_action');

        if (empty($_FILES['rmwr_import_file']['tmp_name'])) {
            $this->back('import_nofile');
        }

        $raw  = file_get_contents(sanitize_text_field($_FILES['rmwr_import_file']['tmp_name'])); // phpcs:ignore
        $data = json_decode((string) $raw, true);

        if (!is_array($data)) {
            $this->back('import_invalid');
        }

        $imported = 0;
        foreach ($data as $key => $value) {
            // Only accept known plugin option names; never arbitrary keys.
            if (!preg_match('/^(rm_text|rl_text|rl_pro_option|rmwr_[a-z0-9_]+)$/', (string) $key)) {
                continue;
            }
            if (in_array($key, self::EXCLUDED, true)) {
                continue;
            }
            if (!is_scalar($value) && !is_array($value)) {
                continue;
            }
            if (is_array($value)) {
                $value = array_map('sanitize_text_field', array_map('strval', $value));
            }

            update_option($key, $value);
            $imported++;
        }

        $this->back($imported > 0 ? 'import_ok' : 'import_empty');
    }

    /**
     * Sidebar widget with the export/import forms.
     */
    public static function render_tools_box() {
        ?>
        <div class="rmwr-sidebar-widget">
            <h3><?php esc_html_e('Import / Export Settings', 'rmwr'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px;">
                <?php wp_nonce_field('rmwr_tools_action', 'rmwr_tools_nonce'); ?>
                <input type="hidden" name="action" value="rmwr_export_settings">
                <button type="submit" class="button"><?php esc_html_e('Export settings (JSON)', 'rmwr'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('rmwr_tools_action', 'rmwr_tools_nonce'); ?>
                <input type="hidden" name="action" value="rmwr_import_settings">
                <input type="file" name="rmwr_import_file" accept="application/json,.json" style="margin-bottom:8px;max-width:100%;" />
                <br>
                <button type="submit" class="button"><?php esc_html_e('Import settings', 'rmwr'); ?></button>
            </form>
            <p class="description" style="margin-top:8px;">
                <?php esc_html_e('License data is never exported. Importing overwrites current settings.', 'rmwr'); ?>
            </p>
        </div>
        <?php
    }

    private function verify($action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'rmwr'));
        }
        if (!isset($_POST['rmwr_tools_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rmwr_tools_nonce'])), $action)) {
            wp_die(esc_html__('Security check failed', 'rmwr'));
        }
    }

    private function back($notice) {
        wp_safe_redirect(add_query_arg(
            array('page' => 'read_more_without_refresh', 'rmwr_notice' => $notice),
            admin_url('admin.php')
        ));
        exit;
    }
}
