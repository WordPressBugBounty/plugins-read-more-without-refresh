<?php
/**
 * Core plugin orchestrator: wires all modules together.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Pro {

    private static $instance = null;

    /** @var array Instantiated feature modules, keyed by slug. */
    public $modules = array();

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->maybe_upgrade();

        add_action('init', array($this, 'load_textdomain'));

        // Core modules - always active (free + premium tiers).
        // Single-codebase freemium: this same code ships as the free
        // wordpress.org build and the premium build. rmwr_is_premium() (the
        // Freemius license gate) decides which features actually run, so a
        // fresh unlicensed install behaves as the free tier.
        $this->modules['shortcode'] = new RMWR_Shortcode();
        $this->modules['assets']    = new RMWR_Assets();
        $this->modules['schema']    = new RMWR_Schema();
        $this->modules['settings']  = new RMWR_Settings_Pro();
        $this->modules['block']     = new RMWR_Block();
        // Analytics stays on for both tiers: the free tier counts expands
        // (cache-safe) and shows the teaser; premium shows the full dashboard.
        $this->modules['analytics'] = new RMWR_Analytics();
        // Admin-only promotion: dashboard stats widget (both tiers) and the
        // free-tier upgrade notice. Its upsell parts self-gate on the license.
        if (is_admin()) {
            $this->modules['promote'] = new RMWR_Promote();
            // Custom branded pricing/upgrade page. Self-gates: only registers
            // its menu while unlicensed. CTAs open the Freemius checkout.
            $this->modules['upgrade'] = new RMWR_Upgrade();
        }

        // Premium-only modules - loaded only when the license unlocks them, so
        // their admin pages, meta boxes and REST endpoints never exist on a
        // free install. Their features are additionally gated at the shortcode
        // level (see RMWR_Shortcode) as defense in depth.
        if (rmwr_is_premium()) {
            $this->modules['aeo']           = new RMWR_AEO();
            $this->modules['ab']            = new RMWR_AB_Testing();
            $this->modules['auto_apply']    = new RMWR_Auto_Apply();
            $this->modules['sections']      = new RMWR_Sections();
            $this->modules['locker']        = new RMWR_Locker();
            $this->modules['paywall']       = new RMWR_Paywall();
            $this->modules['ai']            = new RMWR_AI_Summary();
            $this->modules['cta']           = new RMWR_CTA();
            $this->modules['global_blocks'] = new RMWR_Global_Blocks();
            $this->modules['import_export'] = new RMWR_Import_Export();

            // Elementor widget (loaded only when Elementor is also active).
            add_action('elementor/widgets/register', array($this, 'register_elementor_widget'));
        }

        // Licensing + automatic updates are handled by Freemius (see
        // includes/rmwr-freemius.php), which boots from the main plugin file.
    }

    /**
     * Run one-time upgrade tasks when the plugin version changes.
     */
    private function maybe_upgrade() {
        $installed = get_option('rmwr_db_version', '');
        if ($installed === RMWR_PRO_VERSION) {
            return;
        }

        RMWR_Analytics::install_tables();

        // v4.0 wrote a new "instance" row into wp_options on every pageview
        // (auto-generated uniqid keys), which grows without bound. The data is
        // unusable by design, so drop it on upgrade.
        delete_option('rmwr_instances');

        // Grandfather existing sites when the Style tab moves behind Pro: any
        // site that already customized its button keeps full styling forever.
        // This only runs on an UPGRADE (maybe_upgrade returns early on a fresh
        // install, whose db_version already matches), so new installs are not
        // grandfathered and get the theme-inherited look on the free tier.
        if ('' === get_option('rmwr_style_grandfathered', '')) {
            update_option(
                'rmwr_style_grandfathered',
                RMWR_Settings_Pro::has_custom_styles() ? '1' : '0',
                false
            );
        }

        update_option('rmwr_db_version', RMWR_PRO_VERSION, false);
    }

    public function load_textdomain() {
        load_plugin_textdomain('rmwr', false, dirname(RMWR_PRO_BASENAME) . '/languages');
    }

    public function register_elementor_widget($widgets_manager) {
        require_once RMWR_PRO_PATH . 'includes/elementor/class-rmwr-elementor-widget.php';
        $widgets_manager->register(new RMWR_Elementor_Widget());
    }
}
