<?php
/**
 * Plugin Name: Read More Without Refresh
 * Version: 4.0.0
 * Plugin URI: https://en.wordpress.org/plugins/read-more-without-refresh/
 * Description: Boost your SEO without affecting user experience. A simple Javascript-based plugin to show/hide extra content on pages/posts/products and Custom Post Types.
 * Author: Eightweb Interactive
 * Author URI: https://8web.gr/en/
 * License: GPL2
 * Text Domain: rmwr
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('RMWR_VERSION', '4.0.0');
define('RMWR_URL', plugin_dir_url(__FILE__));
define('RMWR_PATH', plugin_dir_path(__FILE__));
define('RMWR_IS_PRO', false);

/**
 * Main Plugin Class (Free Version)
 */
class RMWR_Free {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Initialize plugin
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('init', array($this, 'register_shortcode'));
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Output dynamic CSS
        add_action('wp_head', array($this, 'output_dynamic_css'), 99);
        
        // Initialize settings
        $settings = new RMWR_Settings_Free();
        
        // Admin notice for Pro upgrade
        if (empty(get_option('rmwr-notice-dismissed-alert'))) {
            add_action('admin_notices', array($this, 'admin_notice_pro_upgrade'));
        }
        
        // AJAX handler for dismissing notice
        add_action('wp_ajax_dismiss_rmwr_notice', array($this, 'dismiss_notice'));
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('rmwr', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Register shortcode
     */
    public function register_shortcode() {
        add_shortcode('read', array($this, 'shortcode_handler'));
    }
    
    /**
     * Shortcode handler with improved security (Free version - supports free features only)
     */
    public function shortcode_handler($atts, $content = null) {
        // Parse and sanitize attributes (free version - accepts Pro attributes but ignores them)
        $atts = shortcode_atts(array(
            'class' => '',
            // Free features
            'smooth_scroll' => '',
            // Pro features (accepted but ignored in free version)
            'open' => '',
            'close' => '',
            'mode' => '',
            'animation' => '',
            'template' => '',
            'icon' => '',
            'lazy' => '',
        ), $atts, 'read');
        
        // Sanitize content
        $content = wp_kses_post($content);
        if (empty($content)) {
            return '';
        }
        
        // Get default texts (free version - static only, ignores open/close attributes)
        $open_text = esc_html(get_option('rm_text', 'Read More'));
        $close_text = esc_html(get_option('rl_text', 'Read Less'));
        
        // Generate unique ID
        $id = 'rmwr-' . uniqid();
        
        // Basic fade animation for free version (always enabled)
        $animation = 'fade';
        $duration = 300;
        
        // Get smooth scroll setting (free feature)
        $smooth_scroll_enabled = !empty($atts['smooth_scroll']) ? filter_var($atts['smooth_scroll'], FILTER_VALIDATE_BOOLEAN) : (get_option('rmwr_smooth_scroll_free', '1') === '1');
        $scroll_offset = 0; // Free version doesn't support offset
        
        // Get additional classes
        $custom_class = !empty($atts['class']) ? sanitize_html_class($atts['class']) : '';
        
        // Build wrapper classes
        $wrapper_classes = array('rmwr-wrapper');
        if ($custom_class) {
            $wrapper_classes[] = $custom_class;
        }
        
        // Build output
        ob_start();
        ?>
        <div class="<?php echo esc_attr(implode(' ', $wrapper_classes)); ?>" 
             data-id="<?php echo esc_attr($id); ?>"
             data-mode="normal"
             data-animation="<?php echo esc_attr($animation); ?>"
             data-duration="<?php echo esc_attr($duration); ?>"
             data-smooth-scroll="<?php echo $smooth_scroll_enabled ? 'true' : 'false'; ?>"
             data-scroll-offset="0">
            <button 
                type="button"
                class="read-link" 
                id="readlink<?php echo esc_attr($id); ?>"
                data-open-text="<?php echo esc_attr($open_text); ?>"
                data-close-text="<?php echo esc_attr($close_text); ?>"
                aria-expanded="false"
                aria-controls="read<?php echo esc_attr($id); ?>"
                aria-label="<?php echo esc_attr($open_text); ?>"
            >
                <span class="rmwr-text"><?php echo esc_html($open_text); ?></span>
            </button>
            <div 
                class="read_div" 
                id="read<?php echo esc_attr($id); ?>"
                aria-hidden="true"
                data-animation="<?php echo esc_attr($animation); ?>"
                data-duration="<?php echo esc_attr($duration); ?>"
                style="display: none;"
            >
                <?php echo do_shortcode($content); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_script(
            'rmwr-frontend',
            RMWR_URL . 'js/frontend.js',
            array(),
            RMWR_VERSION,
            true
        );
        
        // Localize script for free version features
        wp_localize_script('rmwr-frontend', 'rmwrSettings', array(
            'enableAnalytics' => false, // Free version doesn't have analytics
            'animationDefault' => 'fade',
            'printExpand' => get_option('rmwr_print_expand_free', '1') === '1',
            'loadingText' => __('Loading...', 'rmwr'),
        ));
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our settings page
        if ('toplevel_page_read_more_without_refresh' !== $hook) {
            return;
        }
        
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script(
            'rmwr-admin',
            RMWR_URL . 'js/main.js',
            array('wp-color-picker', 'jquery'),
            RMWR_VERSION,
            true
        );
        
        wp_enqueue_script(
            'rmwr-notice-update',
            RMWR_URL . 'js/notice-update.js',
            array('jquery'),
            RMWR_VERSION,
            true
        );
        
        // Add nonce and AJAX URL for notice dismissal
        wp_localize_script('rmwr-notice-update', 'rmwrNotice', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rmwr-dismiss-notice'),
        ));
        
        // Enqueue admin CSS for modern settings page
        wp_enqueue_style(
            'rmwr-admin',
            RMWR_URL . 'css/admin.css',
            array(),
            RMWR_VERSION
        );
    }
    
    /**
     * Output dynamic CSS
     */
    public function output_dynamic_css() {
        $font_weight = esc_html(get_option('rmwr_font_weight', 'normal'));
        $text_color = esc_html(get_option('rmwr_text_color', '#000000'));
        $hover_color = esc_html(get_option('rmwr_text_hover_color', '#191919'));
        $bg_color = esc_html(get_option('rmwr_background_color', '#ffffff'));
        $padding = esc_html(get_option('rmwr_padding', '0px'));
        $border_bottom = esc_html(get_option('rmwr_border_bottom', '1px'));
        $border_color = esc_html(get_option('rmwr_border_bottom_color', '#000000'));
        
        ?>
        <style type="text/css" id="rmwr-dynamic-css">
        .read-link {
            font-weight: <?php echo $font_weight; ?>;
            color: <?php echo $text_color; ?>;
            background: <?php echo $bg_color; ?>;
            padding: <?php echo $padding; ?>;
            border-bottom: <?php echo $border_bottom; ?> solid <?php echo $border_color; ?>;
            -webkit-box-shadow: none !important;
            box-shadow: none !important;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            border-top: none;
            border-left: none;
            border-right: none;
            transition: color 0.3s ease, background-color 0.3s ease;
        }
        
        .read-link:hover,
        .read-link:focus {
            color: <?php echo $hover_color; ?>;
            text-decoration: none;
            outline: 2px solid <?php echo $text_color; ?>;
            outline-offset: 2px;
        }
        
        .read-link:focus {
            outline: 2px solid <?php echo $text_color; ?>;
            outline-offset: 2px;
        }
        
        .read_div {
            margin-top: 10px;
        }
        
        .read_div[data-animation="fade"] {
            transition: opacity 0.3s ease;
        }
        
        /* Print optimization (Free feature) */
        <?php if (get_option('rmwr_print_expand_free', '1') === '1'): ?>
        @media print {
            .read_div[style*="display: none"] {
                display: block !important;
            }
            .read-link {
                display: none !important;
            }
        }
        <?php endif; ?>
        </style>
        <?php
    }
    
    /**
     * Admin notice for Pro upgrade
     */
    public function admin_notice_pro_upgrade() {
        ?>
        <div class="notice notice-info rmwr-notice is-dismissible">
            <p>
                <img src="https://ps.w.org/read-more-without-refresh/assets/icon-256x256.gif" width="64" style="float: left; margin-right: 15px;">
                <strong>
                    <a href="https://shop.8web.gr/product/read-more-without-refresh-pro/" target="_blank"><?php _e('Read More Without Refresh PRO', 'rmwr'); ?></a>
                </strong>
                <br>
                <?php _e('💁 Unlock <strong>Accordion/FAQ mode</strong>, <strong>20 button templates</strong>, <strong>9 animations</strong>, <strong>advanced analytics</strong>, <strong>lazy loading</strong>, <strong>conditional display</strong>, and <strong>30+ more premium features</strong>.', 'rmwr'); ?>
                <br>
                <?php _e('👉', 'rmwr'); ?> <a href="https://shop.8web.gr/product/read-more-without-refresh-pro/" target="_blank"><?php _e('Upgrade to Pro', 'rmwr'); ?></a> <?php _e('and unlock all premium features!', 'rmwr'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for dismissing notice
     */
    public function dismiss_notice() {
        check_ajax_referer('rmwr-dismiss-notice', 'nonce');
        update_option('rmwr-notice-dismissed-alert', 1);
        wp_send_json_success();
    }
}

/**
 * Settings Class (Free Version)
 */
class RMWR_Settings_Free {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'create_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    public function create_settings_page() {
        add_menu_page(
            __('Read More without Refresh', 'rmwr'),
            __('RMWR Settings', 'rmwr'),
            'manage_options',
            'read_more_without_refresh',
            array($this, 'render_settings_page'),
            'dashicons-text',
            100
        );
        
        // Add Analytics submenu (disabled/Pro only indicator)
        add_submenu_page(
            'read_more_without_refresh',
            __('Analytics (Pro)', 'rmwr'),
            __('Analytics', 'rmwr') . ' <span class="rmwr-pro-badge-small">PRO</span>',
            'manage_options',
            'rmwr-analytics-free',
            array($this, 'render_analytics_placeholder')
        );
    }
    
    /**
     * Render Analytics placeholder page (Pro feature indicator)
     */
    public function render_analytics_placeholder() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rmwr'));
        }
        ?>
        <div class="wrap rmwr-analytics-wrap">
            <div class="rmwr-header">
                <h1><?php _e('Analytics Dashboard', 'rmwr'); ?> <span class="rmwr-pro-badge-inline">PRO</span></h1>
                <p class="rmwr-subtitle"><?php _e('Track and analyze read more engagement - Pro Feature', 'rmwr'); ?></p>
            </div>
            
            <div class="rmwr-analytics-container" style="text-align: center; padding: 60px 20px;">
                <div style="max-width: 600px; margin: 0 auto; background: #fff; padding: 40px; border: 1px solid #c3c4c7; border-radius: 6px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2 style="color: #23282d; margin-bottom: 20px;"><?php _e('Analytics Dashboard is a Pro Feature', 'rmwr'); ?></h2>
                    <p style="font-size: 16px; color: #646970; margin-bottom: 30px;">
                        <?php _e('Get detailed insights into user engagement with the Pro version:', 'rmwr'); ?>
                    </p>
                    <ul style="text-align: left; max-width: 400px; margin: 0 auto 30px; list-style: none; padding: 0;">
                        <li style="padding: 10px 0; border-bottom: 1px solid #ddd;">✓ <?php _e('Total clicks tracking', 'rmwr'); ?></li>
                        <li style="padding: 10px 0; border-bottom: 1px solid #ddd;">✓ <?php _e('Most clicked instances', 'rmwr'); ?></li>
                        <li style="padding: 10px 0; border-bottom: 1px solid #ddd;">✓ <?php _e('Engagement rate statistics', 'rmwr'); ?></li>
                        <li style="padding: 10px 0; border-bottom: 1px solid #ddd;">✓ <?php _e('Click-through analytics', 'rmwr'); ?></li>
                        <li style="padding: 10px 0;">✓ <?php _e('Export data to CSV', 'rmwr'); ?></li>
                    </ul>
                    <a href="https://shop.8web.gr/product/read-more-without-refresh-pro/" target="_blank" class="button button-primary button-large" style="font-size: 16px; padding: 12px 30px;">
                        <?php _e('Upgrade to Pro', 'rmwr'); ?> →
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function register_settings() {
        // Register settings sections
        add_settings_section(
            'rmwr_basic_section',
            __('Basic Settings', 'rmwr'),
            array($this, 'section_callback'),
            'read_more_without_refresh'
        );
        
        add_settings_section(
            'rmwr_style_section',
            __('Style Settings', 'rmwr'),
            array($this, 'section_callback'),
            'read_more_without_refresh'
        );
        
        add_settings_section(
            'rmwr_pro_section',
            __('Pro Features', 'rmwr'),
            array($this, 'section_callback'),
            'read_more_without_refresh'
        );
        
        // Define fields
        $fields = $this->get_fields();
        
        foreach ($fields as $field) {
            // For Pro fields, add Pro badge to label
            $label = $field['label'];
            if (isset($field['is_pro']) && $field['is_pro']) {
                $label .= ' <span class="rmwr-pro-badge-inline">PRO</span>';
            }
            
            add_settings_field(
                $field['uid'],
                $label,
                array($this, 'field_callback'),
                'read_more_without_refresh',
                $field['section'],
                $field
            );
            
            // Create sanitization callback based on field type
            $sanitize_callback = function($value) use ($field) {
                return $this->sanitize_field_value($value, $field);
            };
            
            register_setting(
                'read_more_without_refresh',
                $field['uid'],
                array(
                    'type' => 'string',
                    'sanitize_callback' => $sanitize_callback,
                    'default' => $field['default'] ?? '',
                )
            );
        }
    }
    
    private function get_fields() {
        return array(
            // Basic Settings
            array(
                'uid' => 'rm_text',
                'label' => __('Read more text', 'rmwr'),
                'section' => 'rmwr_basic_section',
                'type' => 'text',
                'placeholder' => 'Read More',
                'default' => 'Read More',
                'helper' => __('Change the "Read More" button text globally.', 'rmwr'),
            ),
            array(
                'uid' => 'rl_text',
                'label' => __('Read less text', 'rmwr'),
                'section' => 'rmwr_basic_section',
                'type' => 'text',
                'default' => 'Read Less',
                'placeholder' => 'Read Less',
                'helper' => __('Change the "Read Less" button text globally.', 'rmwr'),
            ),
            array(
                'uid' => 'rl_pro_option',
                'label' => __('Enable dynamic texts', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_basic_section',
                'type' => 'checkbox',
                'default' => '',
                'helper' => __('Allow custom text in shortcode attributes: [read open="Show" close="Hide"] content [/read].', 'rmwr') . ' <strong>' . __('Available only in', 'rmwr') . ' <a href="https://shop.8web.gr/product/read-more-without-refresh-pro/" target="_blank" style="color:#d63638;">' . __('Pro version', 'rmwr') . '</a></strong>.',
                'is_pro' => true,
            ),
            
            // Style Settings
            array(
                'uid' => 'rmwr_background_color',
                'label' => __('Background color', 'rmwr'),
                'section' => 'rmwr_style_section',
                'type' => 'colorpicker',
                'default' => '#ffffff',
            ),
            array(
                'uid' => 'rmwr_text_color',
                'label' => __('Text color', 'rmwr'),
                'section' => 'rmwr_style_section',
                'type' => 'colorpicker',
                'default' => '#000000',
            ),
            array(
                'uid' => 'rmwr_text_hover_color',
                'label' => __('Text hover color', 'rmwr'),
                'section' => 'rmwr_style_section',
                'type' => 'colorpicker',
                'default' => '#191919',
            ),
            array(
                'uid' => 'rmwr_font_weight',
                'label' => __('Font weight', 'rmwr'),
                'section' => 'rmwr_style_section',
                'type' => 'text',
                'default' => 'normal',
                'placeholder' => 'normal',
                'helper' => __('Enter normal, bold, or numeric value (100-900)', 'rmwr'),
            ),
            array(
                'uid' => 'rmwr_padding',
                'label' => __('Padding', 'rmwr'),
                'section' => 'rmwr_style_section',
                'type' => 'text',
                'default' => '0px',
                'placeholder' => '5px 10px',
                'helper' => __('Enter padding value (e.g., 5px or 5px 10px)', 'rmwr'),
            ),
            array(
                'uid' => 'rmwr_border_bottom',
                'label' => __('Border bottom width', 'rmwr'),
                'section' => 'rmwr_style_section',
                'type' => 'text',
                'default' => '1px',
                'placeholder' => '1px',
                'helper' => __('Enter border width (e.g., 1px, 2px, or 0px to disable)', 'rmwr'),
            ),
            array(
                'uid' => 'rmwr_border_bottom_color',
                'label' => __('Border bottom color', 'rmwr'),
                'section' => 'rmwr_style_section',
                'type' => 'colorpicker',
                'default' => '#000000',
            ),
            
            // FREE Version Features
            array(
                'uid' => 'rmwr_smooth_scroll_free',
                'label' => __('Smooth scroll to content', 'rmwr'),
                'section' => 'rmwr_style_section',
                'type' => 'checkbox',
                'default' => '1',
                'helper' => __('Automatically scroll to expanded content (Free feature).', 'rmwr'),
            ),
            array(
                'uid' => 'rmwr_print_expand_free',
                'label' => __('Auto-expand on print', 'rmwr'),
                'section' => 'rmwr_style_section',
                'type' => 'checkbox',
                'default' => '1',
                'helper' => __('Automatically expand all content when printing (Free feature).', 'rmwr'),
            ),
            
            // Pro Features (shown but disabled in free version)
            array(
                'uid' => 'rmwr_font_size',
                'label' => __('Font size', 'rmwr'),
                'section' => 'rmwr_pro_section',
                'type' => 'text',
                'default' => '',
                'placeholder' => '16px',
                'helper' => __('Enter font size with unit (e.g., 16px, 1em)', 'rmwr'),
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_text_transform',
                'label' => __('Text transform', 'rmwr'),
                'section' => 'rmwr_pro_section',
                'type' => 'select',
                'default' => 'none',
                'options' => array(
                    'none' => __('None', 'rmwr'),
                    'uppercase' => __('Uppercase', 'rmwr'),
                    'lowercase' => __('Lowercase', 'rmwr'),
                    'capitalize' => __('Capitalize', 'rmwr'),
                ),
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_border_radius',
                'label' => __('Border radius', 'rmwr'),
                'section' => 'rmwr_pro_section',
                'type' => 'text',
                'default' => '0px',
                'placeholder' => '4px',
                'helper' => __('Enter border radius (e.g., 4px, 50% for round)', 'rmwr'),
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_animation',
                'label' => __('Default animation', 'rmwr'),
                'section' => 'rmwr_pro_section',
                'type' => 'select',
                'default' => 'none',
                'options' => array(
                    'none' => __('None', 'rmwr'),
                    'fade' => __('Fade', 'rmwr'),
                    'slide' => __('Slide', 'rmwr'),
                ),
                'helper' => __('Default animation type for read more content', 'rmwr'),
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_animation_duration',
                'label' => __('Animation duration (ms)', 'rmwr'),
                'section' => 'rmwr_pro_section',
                'type' => 'number',
                'default' => 300,
                'placeholder' => '300',
                'helper' => __('Animation duration in milliseconds', 'rmwr'),
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_icon',
                'label' => __('Icon type', 'rmwr'),
                'section' => 'rmwr_pro_section',
                'type' => 'select',
                'default' => '',
                'options' => array(
                    '' => __('None', 'rmwr'),
                    'arrow-down' => __('Arrow Down', 'rmwr'),
                    'arrow-up' => __('Arrow Up', 'rmwr'),
                    'chevron-down' => __('Chevron Down', 'rmwr'),
                    'chevron-up' => __('Chevron Up', 'rmwr'),
                    'plus' => __('Plus', 'rmwr'),
                    'minus' => __('Minus', 'rmwr'),
                ),
                'helper' => __('Add an icon to the read more button', 'rmwr'),
                'is_pro' => true,
            ),
            
            // Accordion/FAQ Mode (Pro)
            array(
                'uid' => 'rmwr_accordion_mode',
                'label' => __('Accordion/FAQ Mode', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'checkbox',
                'default' => '0',
                'helper' => __('Enable accordion mode: [read mode="accordion" accordion_id="faq1"] content [/read]. Auto-collapses other items in same group.', 'rmwr') . ' <strong>' . __('Available in', 'rmwr') . ' <a href="https://shop.8web.gr/product/read-more-without-refresh-pro/" target="_blank" style="color:#d63638;">' . __('Pro version', 'rmwr') . '</a></strong>.',
                'is_pro' => true,
            ),
            
            // Conditional Display (Pro)
            array(
                'uid' => 'rmwr_show_after',
                'label' => __('Show after (seconds)', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'number',
                'default' => '',
                'placeholder' => '5',
                'min' => 0,
                'max' => 60,
                'helper' => __('Show content after X seconds. Override: [read after="3seconds"]', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_scroll_triggered',
                'label' => __('Scroll-triggered expansion', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'checkbox',
                'default' => '0',
                'helper' => __('Auto-expand when user scrolls near content. Override: [read scroll="true"]', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_device_filter',
                'label' => __('Device filter', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'select',
                'default' => 'all',
                'options' => array(
                    'all' => __('All devices', 'rmwr'),
                    'mobile' => __('Mobile only', 'rmwr'),
                    'desktop' => __('Desktop only', 'rmwr'),
                ),
                'helper' => __('Show on specific devices. Override: [read device="mobile"]', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_role_restriction',
                'label' => __('User role restriction', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'text',
                'default' => '',
                'placeholder' => 'subscriber,editor',
                'helper' => __('Show only to specific user roles. Override: [read role="subscriber"]', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            
            // Advanced Features (Pro)
            array(
                'uid' => 'rmwr_lazy_load',
                'label' => __('Enable lazy loading', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'checkbox',
                'default' => '0',
                'helper' => __('Load content via AJAX when expanded. Improves page speed. Override: [read lazy="true"]', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_smooth_scroll',
                'label' => __('Smooth scroll to content', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'checkbox',
                'default' => '0',
                'helper' => __('Auto-scroll to expanded content. Override: [read smooth_scroll="true"]', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_scroll_offset',
                'label' => __('Scroll offset (pixels)', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'number',
                'default' => 0,
                'placeholder' => '0',
                'min' => 0,
                'max' => 500,
                'helper' => __('Offset when scrolling to content (for fixed headers)', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_print_expand',
                'label' => __('Auto-expand on print', 'rmwr'),
                'section' => 'rmwr_pro_section',
                'type' => 'checkbox',
                'default' => '0',
                'helper' => __('Automatically expand all content when printing', 'rmwr'),
                'is_pro' => true,
            ),
            
            // Button Templates (Pro)
            array(
                'uid' => 'rmwr_button_template',
                'label' => __('Button template', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'select',
                'default' => '',
                'options' => $this->get_template_options_free(),
                'helper' => __('Choose from 20+ pre-designed button styles. Override: [read template="modern-blue"]', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            
            // Enhanced Animations (Pro)
            array(
                'uid' => 'rmwr_animation_enhanced',
                'label' => __('Enhanced animations', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'select',
                'default' => 'none',
                'options' => array(
                    'none' => __('None', 'rmwr'),
                    'fade' => __('Fade', 'rmwr'),
                    'slide' => __('Slide', 'rmwr'),
                    'flip' => __('Flip', 'rmwr'),
                    'zoom' => __('Zoom', 'rmwr'),
                    'bounce' => __('Bounce', 'rmwr'),
                    'rotate' => __('Rotate', 'rmwr'),
                    'scale' => __('Scale', 'rmwr'),
                    'elastic' => __('Elastic', 'rmwr'),
                ),
                'helper' => __('Advanced animation types. Override: [read animation="bounce"]', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            
            // Icon Library (Pro)
            array(
                'uid' => 'rmwr_fontawesome_icon',
                'label' => __('Font Awesome icon', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'text',
                'default' => '',
                'placeholder' => 'fa-arrow-down',
                'helper' => __('Enter Font Awesome icon class (e.g., fa-arrow-down). <a href="https://fontawesome.com/icons" target="_blank">Browse icons</a>', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            
            // Gradient Background (Pro)
            array(
                'uid' => 'rmwr_background_gradient',
                'label' => __('Background gradient', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'text',
                'default' => '',
                'placeholder' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                'helper' => __('Enter CSS gradient. Leave empty to use solid color', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            
            // Typography (Pro)
            array(
                'uid' => 'rmwr_font_family',
                'label' => __('Font family', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'select',
                'default' => 'inherit',
                'options' => $this->get_font_options_free(),
                'helper' => __('Choose font family including Google Fonts', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_line_height',
                'label' => __('Line height', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'text',
                'default' => '',
                'placeholder' => '1.5',
                'helper' => __('Enter line height (e.g., 1.5, 24px)', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            array(
                'uid' => 'rmwr_letter_spacing',
                'label' => __('Letter spacing', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'text',
                'default' => '',
                'placeholder' => '0.5px',
                'helper' => __('Enter letter spacing (e.g., 0.5px, 1em)', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
            
            // Analytics (Pro)
            array(
                'uid' => 'rmwr_enable_analytics',
                'label' => __('Analytics dashboard', 'rmwr') . ' <span class="rmwr-pro-badge-inline">PRO</span>',
                'section' => 'rmwr_pro_section',
                'type' => 'checkbox',
                'default' => '0',
                'helper' => __('Track clicks and view analytics in Analytics submenu', 'rmwr') . ' <strong>' . __('Pro feature', 'rmwr') . '</strong>.',
                'is_pro' => true,
            ),
        );
    }
    
    /**
     * Get template options for free version (for display only)
     */
    private function get_template_options_free() {
        return array(
            '' => __('Default / None (Pro Required)', 'rmwr'),
            'modern-blue' => __('Modern Blue (Pro)', 'rmwr'),
            'classic-underline' => __('Classic Underline (Pro)', 'rmwr'),
            'rounded-gradient' => __('Rounded Gradient (Pro)', 'rmwr'),
            'minimalist' => __('Minimalist (Pro)', 'rmwr'),
            'bold-button' => __('Bold Button (Pro)', 'rmwr'),
            'soft-shadow' => __('Soft Shadow (Pro)', 'rmwr'),
            'outline-style' => __('Outline Style (Pro)', 'rmwr'),
            'filled-primary' => __('Filled Primary (Pro)', 'rmwr'),
            'ghost-button' => __('Ghost Button (Pro)', 'rmwr'),
            'pill-shape' => __('Pill Shape (Pro)', 'rmwr'),
            'flat-design' => __('Flat Design (Pro)', 'rmwr'),
            '3d-effect' => __('3D Effect (Pro)', 'rmwr'),
            'glassmorphism' => __('Glassmorphism (Pro)', 'rmwr'),
            'neon-glow' => __('Neon Glow (Pro)', 'rmwr'),
            'vintage-style' => __('Vintage Style (Pro)', 'rmwr'),
            'corporate-blue' => __('Corporate Blue (Pro)', 'rmwr'),
            'playful-yellow' => __('Playful Yellow (Pro)', 'rmwr'),
            'elegant-purple' => __('Elegant Purple (Pro)', 'rmwr'),
            'nature-green' => __('Nature Green (Pro)', 'rmwr'),
        );
    }
    
    /**
     * Get font options for free version (for display only)
     */
    private function get_font_options_free() {
        return array(
            'inherit' => __('Inherit from theme', 'rmwr'),
            'Arial' => __('Arial', 'rmwr'),
            'Helvetica' => __('Helvetica', 'rmwr'),
            'Roboto' => __('Roboto (Google) - Pro', 'rmwr'),
            'Open Sans' => __('Open Sans (Google) - Pro', 'rmwr'),
            'Lato' => __('Lato (Google) - Pro', 'rmwr'),
            'Montserrat' => __('Montserrat (Google) - Pro', 'rmwr'),
            'Poppins' => __('Poppins (Google) - Pro', 'rmwr'),
            'Raleway' => __('Raleway (Google) - Pro', 'rmwr'),
            'Oswald' => __('Oswald (Google) - Pro', 'rmwr'),
            'Source Sans Pro' => __('Source Sans Pro (Google) - Pro', 'rmwr'),
            'Playfair Display' => __('Playfair Display (Google) - Pro', 'rmwr'),
            'Merriweather' => __('Merriweather (Google) - Pro', 'rmwr'),
        );
    }
    
    public function section_callback($arguments) {
        switch ($arguments['id']) {
            case 'rmwr_basic_section':
                echo '<p class="description">' . __('Configure the basic text settings.', 'rmwr') . '</p>';
                break;
            case 'rmwr_style_section':
                echo '<p class="description">' . __('Customize the appearance of the read more/less buttons.', 'rmwr') . '</p>';
                break;
            case 'rmwr_pro_section':
                echo '<div class="rmwr-pro-section-header">';
                echo '<p class="description">' . __('Unlock these premium features with the Pro version:', 'rmwr') . '</p>';
                echo '<a href="https://shop.8web.gr/product/read-more-without-refresh-pro/" target="_blank" class="button button-primary rmwr-upgrade-button">' . __('Upgrade to Pro', 'rmwr') . ' →</a>';
                echo '</div>';
                break;
        }
    }
    
    public function field_callback($arguments) {
        $is_pro = isset($arguments['is_pro']) && $arguments['is_pro'];
        $value = get_option($arguments['uid']);
        if (false === $value) {
            $value = $arguments['default'] ?? '';
        }
        
        $sanitized_value = esc_attr($value);
        $disabled_attr = ($is_pro || $arguments['uid'] === 'rl_pro_option') ? 'disabled' : '';
        $field_wrapper_class = $is_pro ? 'rmwr-field-pro' : '';
        
        echo '<div class="rmwr-field-wrapper ' . esc_attr($field_wrapper_class) . '">';
        
        switch ($arguments['type']) {
            case 'text':
                printf(
                    '<input name="%1$s" id="%1$s" type="text" placeholder="%3$s" value="%4$s" class="regular-text" %5$s />',
                    esc_attr($arguments['uid']),
                    esc_attr($arguments['uid']),
                    esc_attr($arguments['placeholder'] ?? ''),
                    $sanitized_value,
                    $disabled_attr
                );
                break;
                
            case 'number':
                $min = isset($arguments['min']) ? 'min="' . esc_attr($arguments['min']) . '"' : '';
                $max = isset($arguments['max']) ? 'max="' . esc_attr($arguments['max']) . '"' : '';
                printf(
                    '<input name="%1$s" id="%1$s" type="number" placeholder="%3$s" value="%4$s" class="small-text" %5$s %6$s %7$s />',
                    esc_attr($arguments['uid']),
                    esc_attr($arguments['uid']),
                    esc_attr($arguments['placeholder'] ?? ''),
                    absint($sanitized_value),
                    $disabled_attr,
                    $min,
                    $max
                );
                break;
                
            case 'colorpicker':
                $color_value = $sanitized_value ?: ($arguments['default'] ?? '#ffffff');
                printf(
                    '<input name="%1$s" id="%1$s" type="text" class="cpa-color-picker" value="%2$s" %3$s />',
                    esc_attr($arguments['uid']),
                    esc_attr($color_value),
                    $disabled_attr
                );
                break;
                
            case 'select':
                $options = $arguments['options'] ?? array();
                echo '<select name="' . esc_attr($arguments['uid']) . '" id="' . esc_attr($arguments['uid']) . '" ' . $disabled_attr . '>';
                foreach ($options as $key => $option_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($key),
                        selected($value, $key, false),
                        esc_html($option_label)
                    );
                }
                echo '</select>';
                break;
                
            case 'checkbox':
                $checked = checked($value, '1', false);
                printf(
                    '<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s %4$s />',
                    esc_attr($arguments['uid']),
                    esc_attr($arguments['uid']),
                    $checked,
                    $disabled_attr
                );
                break;
        }
        
        if (!empty($arguments['helper'])) {
            $helper_text = $arguments['helper'];
            if ($is_pro && !strpos($helper_text, 'PRO') && !strpos($helper_text, 'Pro')) {
                $helper_text .= ' <strong>' . __('Available in', 'rmwr') . ' <a href="https://shop.8web.gr/product/read-more-without-refresh-pro/" target="_blank" style="color: #d63638;">' . __('Pro version', 'rmwr') . '</a></strong>.';
            }
            printf('<p class="description">%s</p>', wp_kses_post($helper_text));
        }
        
        echo '</div>';
    }
    
    public function sanitize_field_value($value, $field) {
        // Sanitize based on field type
        switch ($field['type']) {
            case 'colorpicker':
                return sanitize_hex_color($value) ?: $field['default'] ?? '#ffffff';
                
            case 'checkbox':
                return ($value === '1' || $value === 1) ? '1' : '';
                
            case 'number':
                return absint($value) ?: ($field['default'] ?? 0);
                
            case 'select':
                $options = $field['options'] ?? array();
                return isset($options[$value]) ? sanitize_text_field($value) : ($field['default'] ?? '');
                
            case 'text':
            default:
                return sanitize_text_field($value);
        }
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rmwr'));
        }
        
        ?>
        <div class="wrap rmwr-settings-wrap">
            <div class="rmwr-header">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <p class="rmwr-subtitle"><?php _e('Customize your Read More Without Refresh plugin settings', 'rmwr'); ?></p>
            </div>
            
            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']): ?>
                <div class="notice notice-success is-dismissible rmwr-notice">
                    <p><strong><?php _e('Settings saved successfully!', 'rmwr'); ?></strong></p>
                </div>
            <?php endif; ?>
            
            <div class="rmwr-settings-container">
                <div class="rmwr-main-content">
                    <form method="POST" action="options.php" class="rmwr-settings-form">
                        <?php
                        settings_fields('read_more_without_refresh');
                        
                        // Render sections with modern tabs
                        $this->render_sections_with_tabs();
                        
                        submit_button(__('Save Settings', 'rmwr'), 'primary large rmwr-save-button');
                        ?>
                    </form>
                </div>
                
                <div class="rmwr-sidebar">
                    <div class="rmwr-sidebar-widget rmwr-upgrade-box">
                        <div class="rmwr-upgrade-header">
                            <span class="dashicons dashicons-star-filled"></span>
                            <h3><?php _e('Upgrade to Pro', 'rmwr'); ?></h3>
                        </div>
                        <div class="rmwr-upgrade-content">
                            <p><?php _e('Unlock all premium features:', 'rmwr'); ?></p>
                            <ul class="rmwr-feature-list">
                                <li><?php _e('✓ Dynamic shortcode texts', 'rmwr'); ?></li>
                                <li><?php _e('✓ Google Analytics tracking', 'rmwr'); ?></li>
                                <li><?php _e('✓ Gutenberg block editor', 'rmwr'); ?></li>
                                <li><?php _e('✓ Advanced animations', 'rmwr'); ?></li>
                                <li><?php _e('✓ Icon support', 'rmwr'); ?></li>
                                <li><?php _e('✓ Enhanced styling options', 'rmwr'); ?></li>
                                <li><?php _e('✓ Priority support', 'rmwr'); ?></li>
                            </ul>
                            <a href="https://shop.8web.gr/product/read-more-without-refresh-pro/" target="_blank" class="button button-primary button-large rmwr-upgrade-button">
                                <?php _e('Get Pro Version', 'rmwr'); ?> →
                            </a>
                        </div>
                    </div>
                    
                    <div class="rmwr-sidebar-widget">
                        <h3><?php _e('Shortcode Usage', 'rmwr'); ?></h3>
                        <div class="rmwr-code-block">
                            <code>[read] Your hidden content here [/read]</code>
                        </div>
                        <p class="description"><?php _e('Use this shortcode anywhere in your posts, pages, or widgets.', 'rmwr'); ?></p>
                    </div>
                    
                    <div class="rmwr-sidebar-widget">
                        <h3><?php _e('Support Us', 'rmwr'); ?></h3>
                        <p><?php _e('Your donation helps us develop more features!', 'rmwr'); ?></p>
                        <a href="https://www.paypal.me/eightweb/20?message=Thanks+for+the+awesome+RMWR+plugin" target="_blank">
                            <img src="<?php echo esc_url(RMWR_URL . 'images/donate.png'); ?>" alt="<?php esc_attr_e('Donate', 'rmwr'); ?>" style="max-width: 100%; height: auto;">
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        (function($) {
            // Handle tab navigation
            $('.rmwr-tab').on('click', function(e) {
                e.preventDefault();
                var target = $(this).data('tab');
                
                // Update active tab
                $('.rmwr-tab').removeClass('active');
                $(this).addClass('active');
                
                // Show target section, hide others
                $('.rmwr-tab-content').removeClass('active');
                $('.rmwr-tab-content[data-tab="' + target + '"]').addClass('active');
            });
        })(jQuery);
        </script>
        <?php
    }
    
    private function render_sections_with_tabs() {
        $page = 'read_more_without_refresh';
        
        echo '<div class="rmwr-tabs-wrapper">';
        echo '<nav class="rmwr-tab-nav">';
        echo '<a href="#" class="rmwr-tab active" data-tab="basic">' . __('Basic', 'rmwr') . '</a>';
        echo '<a href="#" class="rmwr-tab" data-tab="style">' . __('Style', 'rmwr') . '</a>';
        echo '<a href="#" class="rmwr-tab" data-tab="pro">' . __('Pro Features', 'rmwr') . ' <span class="rmwr-pro-badge-small">PRO</span></a>';
        echo '</nav>';
        
        echo '<div class="rmwr-tabs-content-wrapper">';
        
        // Render all sections with tab classes
        global $wp_settings_sections, $wp_settings_fields;
        
        if (isset($wp_settings_sections[$page])) {
            foreach ((array) $wp_settings_sections[$page] as $section) {
                $section_id = $section['id'];
                $tab_class = '';
                
                if ($section_id === 'rmwr_basic_section') {
                    $tab_class = 'rmwr-tab-content active';
                    $data_tab = 'basic';
                } elseif ($section_id === 'rmwr_style_section') {
                    $tab_class = 'rmwr-tab-content';
                    $data_tab = 'style';
                } elseif ($section_id === 'rmwr_pro_section') {
                    $tab_class = 'rmwr-tab-content';
                    $data_tab = 'pro';
                } else {
                    $tab_class = 'rmwr-tab-content';
                    $data_tab = 'other';
                }
                
                echo '<div class="' . esc_attr($tab_class) . '" data-tab="' . esc_attr($data_tab) . '">';
                echo '<div class="rmwr-section">';
                
                // Render section callback (description)
                if ($section['callback']) {
                    call_user_func($section['callback'], $section);
                }
                
                // Render fields manually
                if (isset($wp_settings_fields[$page][$section_id])) {
                    echo '<table class="form-table" role="presentation">';
                    foreach ((array) $wp_settings_fields[$page][$section_id] as $field) {
                        echo '<tr>';
                        $class = '';
                        if (!empty($field['args']['class'])) {
                            $class = ' class="' . esc_attr($field['args']['class']) . '"';
                        }
                        echo '<th scope="row">' . $field['title'] . '</th>';
                        echo '<td' . $class . '>';
                        call_user_func($field['callback'], $field['args']);
                        echo '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                }
                
                echo '</div>';
                echo '</div>';
            }
        }
        
        echo '</div>';
        echo '</div>';
    }
}

// Initialize plugin
function rmwr_free_init() {
    return RMWR_Free::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'rmwr_free_init');
