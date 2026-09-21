<?php
/**
 * Settings page (Pro): seven tabs covering every feature, plus the license
 * and import/export sidebar.
 *
 * Field definitions stay declarative (uid/label/section/type/default) and
 * are registered through the WordPress Settings API with type-aware
 * sanitization.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Settings_Pro {

    public function __construct() {
        add_action('admin_menu', array($this, 'create_settings_page'));
        add_action('admin_menu', array($this, 'add_support_link'), 100);
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'render_notices'));
        // Keep our own screens free of unrelated plugins' admin nags.
        add_action('in_admin_header', array($this, 'suppress_foreign_notices'), 1000);
    }

    /**
     * Drop third-party admin notices on our own screens.
     *
     * WordPress relocates every admin notice to just under the first heading,
     * so unrelated plugins' "nags" pile into our header card. On the settings
     * page and the Get Pro page we clear all admin notices and re-add only our
     * own contextual ones (license / import results), which self-gate.
     */
    public function suppress_foreign_notices() {
        if (empty($_GET['page'])) {
            return;
        }
        $page = sanitize_key(wp_unslash($_GET['page']));
        if (!in_array($page, array('read_more_without_refresh', 'rmwr-get-pro'), true)) {
            return;
        }
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        remove_all_actions('user_admin_notices');
        add_action('admin_notices', array($this, 'render_notices'));
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

        // Explicit first submenu pointing back to the settings page. Without
        // it, the "Reusable Contents" CPT submenu (show_in_menu => this slug)
        // becomes the first submenu and WordPress makes the top-level link
        // point there, leaving the settings page (with all the tabs) with no
        // menu entry of its own. Position 0 keeps Settings first.
        add_submenu_page(
            'read_more_without_refresh',
            __('Read More without Refresh', 'rmwr'),
            __('Settings', 'rmwr'),
            'manage_options',
            'read_more_without_refresh',
            array($this, 'render_settings_page'),
            0
        );
    }

    /**
     * Add a "Support" submenu that links to the wordpress.org support forum
     * (opens in a new tab). The Freemius contact form is disabled in the
     * bootstrap.
     */
    public function add_support_link() {
        // When Freemius is active it already adds its own "Support Forum" link
        // to the same wordpress.org forum, so we skip ours to avoid a duplicate.
        // Only the direct (no-Freemius) edition needs this Support submenu.
        $fs = function_exists('rmwr_fs') ? rmwr_fs() : null;
        if ($fs) {
            return;
        }

        add_submenu_page(
            'read_more_without_refresh',
            __('Support', 'rmwr'),
            __('Support', 'rmwr'),
            'manage_options',
            'https://wordpress.org/support/plugin/read-more-without-refresh/'
        );

        // Open the external support link in a new tab.
        global $submenu;
        if (!empty($submenu['read_more_without_refresh'])) {
            foreach ($submenu['read_more_without_refresh'] as &$item) {
                if (isset($item[2]) && 'https://wordpress.org/support/plugin/read-more-without-refresh/' === $item[2]) {
                    $item[6] = 'rmwr-support-external';
                }
            }
            unset($item);
        }
        add_action('admin_footer', array($this, 'support_link_target_script'));
    }

    /**
     * Force the Support menu link to open in a new tab.
     */
    public function support_link_target_script() {
        ?>
        <script>(function(){var a=document.querySelector('#adminmenu a[href*="wordpress.org/support/plugin/read-more-without-refresh"]');if(a){a.target="_blank";a.rel="noopener";}})();</script>
        <?php
    }

    /**
     * Tab definitions: slug => [section_id, label, dashicon].
     *
     * @return array
     */
    private function tabs() {
        return array(
            'basic'    => array('rmwr_basic_section', __('Basic', 'rmwr'), 'admin-generic'),
            'style'    => array('rmwr_style_section', __('Style', 'rmwr'), 'admin-appearance'),
            'behavior' => array('rmwr_behavior_section', __('Behavior', 'rmwr'), 'performance'),
            'content'  => array('rmwr_content_section', __('Auto Content', 'rmwr'), 'media-document'),
            'growth'   => array('rmwr_growth_section', __('Growth', 'rmwr'), 'chart-bar'),
            'ai'       => array('rmwr_ai_section', __('AI', 'rmwr'), 'lightbulb'),
            'advanced' => array('rmwr_advanced_section', __('Advanced', 'rmwr'), 'admin-tools'),
            'howto'    => array('', __('How to use', 'rmwr'), 'editor-help'),
        );
    }

    public function register_settings() {
        foreach ($this->tabs() as $tab) {
            if ('' === $tab[0]) {
                continue; // Non-settings tab (e.g. How to use) has no section.
            }
            add_settings_section($tab[0], $tab[1], array($this, 'section_callback'), 'read_more_without_refresh');
        }

        foreach ($this->get_fields() as $field) {
            add_settings_field(
                $field['uid'],
                $field['label'],
                array($this, 'field_callback'),
                'read_more_without_refresh',
                $field['section'],
                $field
            );

            $sanitize_callback = function ($value) use ($field) {
                return $this->sanitize_field_value($value, $field);
            };

            register_setting('read_more_without_refresh', $field['uid'], array(
                'sanitize_callback' => $sanitize_callback,
                'default'           => $field['default'] ?? '',
            ));
        }
    }

    /**
     * Every configurable option, grouped by section.
     *
     * @return array
     */
    private function get_fields() {
        $fields = array();

        /* ----------------------------- Basic ----------------------------- */
        $fields[] = array('uid' => 'rm_text', 'label' => __('Read more text', 'rmwr'), 'section' => 'rmwr_basic_section', 'type' => 'text', 'default' => 'Read More', 'placeholder' => 'Read More',
            'helper' => __('Global "Read More" button text. Override per instance: [read open="Show"]', 'rmwr'));
        $fields[] = array('uid' => 'rl_text', 'label' => __('Read less text', 'rmwr'), 'section' => 'rmwr_basic_section', 'type' => 'text', 'default' => 'Read Less', 'placeholder' => 'Read Less',
            'helper' => __('Global "Read Less" button text. Override per instance: [read close="Hide"]', 'rmwr'));
        $fields[] = array('uid' => 'rl_pro_option', 'label' => __('Enable dynamic texts', 'rmwr'), 'section' => 'rmwr_basic_section', 'type' => 'checkbox', 'default' => '1',
            'helper' => __('Allow custom texts in shortcode attributes: [read open="Show" close="Hide"]', 'rmwr'));

        /* ----------------------------- Style ----------------------------- */
        $fields[] = array('uid' => 'rmwr_background_color', 'label' => __('Background color', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'colorpicker', 'default' => '#ffffff');
        $fields[] = array('uid' => 'rmwr_text_color', 'label' => __('Text color', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'colorpicker', 'default' => '#000000');
        $fields[] = array('uid' => 'rmwr_text_hover_color', 'label' => __('Text hover color', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'colorpicker', 'default' => '#191919');
        $fields[] = array('uid' => 'rmwr_font_weight', 'label' => __('Font weight', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'text', 'default' => 'normal', 'placeholder' => 'normal',
            'helper' => __('normal, bold, or 100-900', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_font_size', 'label' => __('Font size', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'text', 'default' => '', 'placeholder' => '16px');
        $fields[] = array('uid' => 'rmwr_text_transform', 'label' => __('Text transform', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'select', 'default' => 'none',
            'options' => array('none' => __('None', 'rmwr'), 'uppercase' => __('Uppercase', 'rmwr'), 'lowercase' => __('Lowercase', 'rmwr'), 'capitalize' => __('Capitalize', 'rmwr')));
        $fields[] = array('uid' => 'rmwr_padding', 'label' => __('Padding', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'text', 'default' => '0px', 'placeholder' => '5px 10px');
        $fields[] = array('uid' => 'rmwr_border_bottom', 'label' => __('Border bottom width', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'text', 'default' => '1px', 'placeholder' => '1px');
        $fields[] = array('uid' => 'rmwr_border_bottom_color', 'label' => __('Border bottom color', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'colorpicker', 'default' => '#000000');
        $fields[] = array('uid' => 'rmwr_border_radius', 'label' => __('Border radius', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'text', 'default' => '0px', 'placeholder' => '4px');
        $fields[] = array('uid' => 'rmwr_button_template', 'label' => __('Button template', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'select', 'default' => '',
            'options' => $this->get_template_options(),
            'helper' => __('20 pre-designed button styles. Override per instance: [read template="modern-blue"]', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_icon', 'label' => __('Icon type', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'select', 'default' => '',
            'options' => array('' => __('None', 'rmwr'), 'arrow-down' => __('Arrow Down', 'rmwr'), 'arrow-up' => __('Arrow Up', 'rmwr'), 'chevron-down' => __('Chevron Down', 'rmwr'), 'chevron-up' => __('Chevron Up', 'rmwr'), 'plus' => __('Plus', 'rmwr'), 'minus' => __('Minus', 'rmwr')));
        $fields[] = array('uid' => 'rmwr_fontawesome_icon', 'label' => __('Font Awesome icon', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'text', 'default' => '', 'placeholder' => 'fa-arrow-down',
            'helper' => __('Font Awesome class, e.g. fa-arrow-down. Loaded only when needed (see Advanced tab).', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_background_gradient', 'label' => __('Background gradient', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'text', 'default' => '',
            'placeholder' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)');
        $fields[] = array('uid' => 'rmwr_font_family', 'label' => __('Font family', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'select', 'default' => 'inherit', 'options' => $this->get_font_options());
        $fields[] = array('uid' => 'rmwr_line_height', 'label' => __('Line height', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'text', 'default' => '', 'placeholder' => '1.5');
        $fields[] = array('uid' => 'rmwr_letter_spacing', 'label' => __('Letter spacing', 'rmwr'), 'section' => 'rmwr_style_section', 'type' => 'text', 'default' => '', 'placeholder' => '0.5px');

        /* --------------------------- Behavior ---------------------------- */
        $fields[] = array('uid' => 'rmwr_animation', 'label' => __('Default animation', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'select', 'default' => 'none',
            'options' => $this->get_animation_options(),
            'helper' => __('Override per instance: [read animation="bounce"]', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_animation_duration', 'label' => __('Animation duration (ms)', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'number', 'default' => 300, 'placeholder' => '300');
        $fields[] = array('uid' => 'rmwr_smooth_scroll', 'label' => __('Smooth scroll to content', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'checkbox', 'default' => '1',
            'helper' => __('Scroll to the content after expanding. Override: [read smooth_scroll="false"]', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_scroll_offset', 'label' => __('Scroll offset (px)', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 500,
            'helper' => __('Useful with fixed headers.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_print_expand', 'label' => __('Auto-expand on print', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'checkbox', 'default' => '1');
        $fields[] = array('uid' => 'rmwr_lazy_load', 'label' => __('Lazy render hidden content', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Wraps hidden content in a <template> tag: images/iframes inside load only when expanded. Note: templates are not reliably indexed - keep OFF when the hidden text matters for SEO. Override: [read lazy="true"]', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_remember_state', 'label' => __('Remember state per visitor', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Re-opens the instances a visitor had expanded on their previous visit (localStorage).', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_deep_linking', 'label' => __('Deep linking', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'checkbox', 'default' => '1',
            'helper' => __('URLs with #rmwr-{id} auto-expand that instance and scroll to it. Great for support links and Google jump-to results.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_show_after', 'label' => __('Show after (seconds)', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'number', 'default' => '', 'min' => 0, 'max' => 60,
            'helper' => __('Reveal the whole widget after X seconds. Override: [read after="3seconds"]', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_scroll_triggered', 'label' => __('Scroll-triggered reveal', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Fade the widget in when it scrolls into view. Override: [read scroll="true"]', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_device_filter', 'label' => __('Device filter', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'select', 'default' => 'all',
            'options' => array('all' => __('All devices', 'rmwr'), 'mobile' => __('Mobile only', 'rmwr'), 'desktop' => __('Desktop only', 'rmwr')));
        $fields[] = array('uid' => 'rmwr_role_restriction', 'label' => __('User role restriction', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'text', 'default' => '', 'placeholder' => 'subscriber,editor',
            'helper' => __('Comma-separated roles allowed to see instances. Use "guest" for logged-out visitors. Override: [read role="subscriber"]', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_show_powered_by', 'label' => __('Show a "Powered by" link', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Adds a small, optional credit link under the last widget on a page. Off by default.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_reading_progress', 'label' => __('Reading progress bar', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('A thin bar at the top of the page showing how far the reader has scrolled. Color: CSS var --rmwr-progress-color.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_reading_resume', 'label' => __('"Continue reading" resume', 'rmwr'), 'section' => 'rmwr_behavior_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Remembers where each visitor stopped and offers a one-tap button to jump back on their next visit.', 'rmwr'));

        /* ------------------------- Auto Content -------------------------- */
        $fields[] = array('uid' => 'rmwr_auto_apply', 'label' => __('Enable Auto-Apply', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Automatically collapse long content behind a Read More button - no shortcode needed.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_auto_apply_post_types', 'label' => __('Auto-Apply post types', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'post_types', 'default' => array());
        $fields[] = array('uid' => 'rmwr_auto_apply_mode', 'label' => __('Collapse by', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'select', 'default' => 'words',
            'options' => array('words' => __('Word count', 'rmwr'), 'paragraphs' => __('Paragraphs (Ad-Inserter style)', 'rmwr'), 'auto' => __('Smart (auto cut point)', 'rmwr')),
            'helper' => __('Word count keeps the first N words visible. Paragraphs keeps the first N paragraphs and collapses the rest - e.g. keep 2, hide from the third onward. Smart cuts right before the first H2/H3 heading (intro stays, the article collapses), with no setup.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_auto_apply_words', 'label' => __('Visible words before collapse', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'number', 'default' => 100, 'min' => 10, 'max' => 5000,
            'helper' => __('Used when "Collapse by" is set to Word count.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_auto_apply_paragraphs', 'label' => __('Visible paragraphs before collapse', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'number', 'default' => 2, 'min' => 1, 'max' => 100,
            'helper' => __('Used when "Collapse by" is set to Paragraphs. 2 = keep the first two paragraphs, collapse the rest.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_related_enable', 'label' => __('Related posts inside Read More', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Append a short list of related posts inside the expanded content - internal links, more pageviews and dwell time. Applies to auto-collapsed posts.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_related_count', 'label' => __('Number of related posts', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 10);
        $fields[] = array('uid' => 'rmwr_auto_apply_tax', 'label' => __('Collapse taxonomy descriptions', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Category/tag/WooCommerce category descriptions on archive pages - keep long SEO text without hurting UX.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_auto_apply_tax_words', 'label' => __('Visible words (taxonomies)', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'number', 'default' => 60, 'min' => 10, 'max' => 5000);
        $fields[] = array('uid' => 'rmwr_sections_enable', 'label' => __('Enable Auto-Collapse Sections', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Wikipedia mode: every heading section becomes a collapsible accordion, with optional Table of Contents.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_sections_post_types', 'label' => __('Sections post types', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'post_types', 'default' => array());
        $fields[] = array('uid' => 'rmwr_sections_heading', 'label' => __('Section heading level', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'select', 'default' => 'h2',
            'options' => array('h2' => 'H2', 'h3' => 'H3'));
        $fields[] = array('uid' => 'rmwr_sections_toc', 'label' => __('Show Table of Contents', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'checkbox', 'default' => '1');
        $fields[] = array('uid' => 'rmwr_sections_first_open', 'label' => __('Keep first section open', 'rmwr'), 'section' => 'rmwr_content_section', 'type' => 'checkbox', 'default' => '1');

        /* ----------------------------- Growth ---------------------------- */
        $fields[] = array('uid' => 'rmwr_enable_analytics', 'label' => __('Enable analytics tracking', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'checkbox', 'default' => '1',
            'helper' => __('Anonymous interaction counters (no personal data). View in the Analytics submenu.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_ad_events', 'label' => __('Send engagement to ad platforms', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Fire a conversion event when a reader expands, unlocks or converts - so you can measure and retarget engaged readers. Requires your GA4 / Pixel / Ads tag to already be on the site.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_ad_ga4', 'label' => __('- Google Analytics 4', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'checkbox', 'default' => '1',
            'helper' => __('Sends a gtag event. Optional custom event name below.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_ad_ga_event_name', 'label' => __('- GA4 event name', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '', 'placeholder' => 'rmwr_expand');
        $fields[] = array('uid' => 'rmwr_ad_meta', 'label' => __('- Meta (Facebook) Pixel', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Sends a fbq trackCustom event. Optional custom event name below.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_ad_meta_event_name', 'label' => __('- Meta event name', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '', 'placeholder' => 'RMWR_Engaged');
        $fields[] = array('uid' => 'rmwr_ad_google_ads', 'label' => __('- Google Ads conversion', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Sends a Google Ads conversion. Paste the conversion "send_to" ID below.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_ad_google_ads_send_to', 'label' => __('- Google Ads send_to', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '', 'placeholder' => 'AW-123456789/AbC-D_efG-h12');
        $fields[] = array('uid' => 'rmwr_ad_datalayer', 'label' => __('- GTM dataLayer push', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Pushes an event to window.dataLayer for Google Tag Manager.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_aeo_enable', 'label' => __('AI Answer Optimizer (AEO)', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Help ChatGPT, Google AI Overviews and Perplexity find and cite your content. Your hidden Read More text is already in the HTML and fully crawlable.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_aeo_llms', 'label' => __('- Publish llms.txt', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'checkbox', 'default' => '1',
            'helper' => __('Serves a Markdown index of your pages and posts at /llms.txt, the convention AI crawlers look for.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_aeo_indexnow', 'label' => __('- IndexNow instant crawling', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('When you publish or update a post, instantly notify Bing, Yandex and other IndexNow engines to re-crawl it. Makes outbound requests to api.indexnow.org.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_ab_variants', 'label' => __('A/B test variants', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '',
            'placeholder' => __('Read More|Discover more|Keep reading', 'rmwr'),
            'helper' => __('2-5 button texts separated by "|". Leave empty to disable. Results appear in Analytics.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_locker_provider', 'label' => __('Content Locker: leads go to', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'select', 'default' => 'store',
            'options' => array('store' => __('Store locally only', 'rmwr'), 'mailchimp' => 'Mailchimp', 'brevo' => 'Brevo (Sendinblue)', 'mailpoet' => 'MailPoet', 'webhook' => __('Custom webhook', 'rmwr')),
            'helper' => __('Where captured emails are sent. They are always stored locally too (Analytics > Export leads). Used by [read lock="email"].', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_locker_title', 'label' => __('Locker title', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '', 'placeholder' => __('This content is locked', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_locker_message', 'label' => __('Locker message', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '', 'placeholder' => __('Enter your email to unlock the full content.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_locker_button', 'label' => __('Locker button text', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '', 'placeholder' => __('Unlock now', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_locker_privacy_text', 'label' => __('Locker consent text', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'textarea', 'default' => '',
            'helper' => __('Optional GDPR consent checkbox label (HTML links allowed). Leave empty for no checkbox.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_locker_mailchimp_key', 'label' => __('Mailchimp API key', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '');
        $fields[] = array('uid' => 'rmwr_locker_mailchimp_list', 'label' => __('Mailchimp audience ID', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '');
        $fields[] = array('uid' => 'rmwr_locker_brevo_key', 'label' => __('Brevo API key', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '');
        $fields[] = array('uid' => 'rmwr_locker_brevo_list', 'label' => __('Brevo list ID', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'number', 'default' => '');
        $fields[] = array('uid' => 'rmwr_locker_mailpoet_list', 'label' => __('MailPoet list ID', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'number', 'default' => '');
        $fields[] = array('uid' => 'rmwr_locker_webhook_url', 'label' => __('Webhook URL', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '', 'placeholder' => 'https://');
        $fields[] = array('uid' => 'rmwr_paywall_url', 'label' => __('Paywall: subscribe URL', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '', 'placeholder' => 'https://',
            'helper' => __('Where the "Continue reading" button sends visitors without access. Empty = login page. Used by [read paywall="..."].', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_paywall_message', 'label' => __('Paywall message', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '', 'placeholder' => __('The rest of this content is for members only.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_paywall_button', 'label' => __('Paywall button text', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'text', 'default' => '', 'placeholder' => __('Continue reading', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_paywall_teaser', 'label' => __('Paywall teaser height (px)', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'number', 'default' => 160, 'min' => 40, 'max' => 2000);
        $fields[] = array('uid' => 'rmwr_cta_enable', 'label' => __('Enable engaged-user CTA', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Show an offer right after a visitor expands content - the moment of maximum engagement.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_cta_mode', 'label' => __('CTA display mode', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'select', 'default' => 'all',
            'options' => array('all' => __('After every expanded instance', 'rmwr'), 'optin' => __('Only on instances with cta="true"', 'rmwr')));
        $fields[] = array('uid' => 'rmwr_cta_content', 'label' => __('CTA content', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'textarea', 'default' => '',
            'helper' => __('HTML and shortcodes allowed: newsletter form, coupon, product banner... Link clicks are tracked as CTA conversions.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_cta_delay', 'label' => __('CTA delay (seconds)', 'rmwr'), 'section' => 'rmwr_growth_section', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 60);

        /* ------------------------------- AI ------------------------------ */
        $fields[] = array('uid' => 'rmwr_ai_provider', 'label' => __('AI provider', 'rmwr'), 'section' => 'rmwr_ai_section', 'type' => 'select', 'default' => 'openai',
            'options' => array('openai' => 'OpenAI', 'anthropic' => 'Anthropic (Claude)', 'gemini' => 'Google Gemini'));
        $fields[] = array('uid' => 'rmwr_ai_api_key', 'label' => __('AI API key', 'rmwr'), 'section' => 'rmwr_ai_section', 'type' => 'text', 'default' => '',
            'helper' => __('Your own API key. Summaries are generated once per post in the editor - visitors never trigger API calls.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_ai_model', 'label' => __('Model (optional)', 'rmwr'), 'section' => 'rmwr_ai_section', 'type' => 'text', 'default' => '',
            'placeholder' => 'gpt-4o-mini / claude-haiku-4-5 / gemini-2.0-flash',
            'helper' => __('Leave empty for the provider default.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_ai_auto', 'label' => __('Auto-generate on publish', 'rmwr'), 'section' => 'rmwr_ai_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Generate a summary automatically the first time a post is published (runs in the background).', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_ai_label', 'label' => __('Summary box label', 'rmwr'), 'section' => 'rmwr_ai_section', 'type' => 'text', 'default' => '', 'placeholder' => 'TL;DR');

        /* ---------------------------- Advanced ---------------------------- */
        $fields[] = array('uid' => 'rmwr_load_fontawesome', 'label' => __('Font Awesome loading', 'rmwr'), 'section' => 'rmwr_advanced_section', 'type' => 'select', 'default' => 'auto',
            'options' => array('auto' => __('Auto (only when an fa- icon is used)', 'rmwr'), 'always' => __('Always', 'rmwr'), 'never' => __('Never', 'rmwr')),
            'helper' => __('v4 loaded the full Font Awesome CDN on every page; "Auto" loads it only where needed.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_disable_external_fonts', 'label' => __('GDPR mode: no external fonts/CDN', 'rmwr'), 'section' => 'rmwr_advanced_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('Skips Google Fonts and the Font Awesome CDN entirely - no visitor data ever reaches third-party servers.', 'rmwr'));
        $fields[] = array('uid' => 'rmwr_delete_data_on_uninstall', 'label' => __('Delete all data on uninstall', 'rmwr'), 'section' => 'rmwr_advanced_section', 'type' => 'checkbox', 'default' => '0',
            'helper' => __('When the plugin is deleted, also remove all settings, analytics tables and leads. Leave off to keep data for a future re-install.', 'rmwr'));

        return $fields;
    }

    private function get_animation_options() {
        return array(
            'none' => __('None', 'rmwr'), 'fade' => __('Fade', 'rmwr'), 'slide' => __('Slide', 'rmwr'),
            'flip' => __('Flip', 'rmwr'), 'zoom' => __('Zoom', 'rmwr'), 'bounce' => __('Bounce', 'rmwr'),
            'rotate' => __('Rotate', 'rmwr'), 'scale' => __('Scale', 'rmwr'), 'elastic' => __('Elastic', 'rmwr'),
        );
    }

    private function get_template_options() {
        return array(
            '' => __('Default / None', 'rmwr'),
            'modern-blue' => __('Modern Blue', 'rmwr'), 'classic-underline' => __('Classic Underline', 'rmwr'),
            'rounded-gradient' => __('Rounded Gradient', 'rmwr'), 'minimalist' => __('Minimalist', 'rmwr'),
            'bold-button' => __('Bold Button', 'rmwr'), 'soft-shadow' => __('Soft Shadow', 'rmwr'),
            'outline-style' => __('Outline Style', 'rmwr'), 'filled-primary' => __('Filled Primary', 'rmwr'),
            'ghost-button' => __('Ghost Button', 'rmwr'), 'pill-shape' => __('Pill Shape', 'rmwr'),
            'flat-design' => __('Flat Design', 'rmwr'), '3d-effect' => __('3D Effect', 'rmwr'),
            'glassmorphism' => __('Glassmorphism', 'rmwr'), 'neon-glow' => __('Neon Glow', 'rmwr'),
            'vintage-style' => __('Vintage Style', 'rmwr'), 'corporate-blue' => __('Corporate Blue', 'rmwr'),
            'playful-yellow' => __('Playful Yellow', 'rmwr'), 'elegant-purple' => __('Elegant Purple', 'rmwr'),
            'nature-green' => __('Nature Green', 'rmwr'),
        );
    }

    private function get_font_options() {
        return array(
            'inherit' => __('Inherit from theme', 'rmwr'),
            'Arial' => 'Arial', 'Helvetica' => 'Helvetica', 'Georgia' => 'Georgia',
            'Times New Roman' => 'Times New Roman', 'Verdana' => 'Verdana', 'Courier New' => 'Courier New',
            'Roboto' => 'Roboto (Google)', 'Open Sans' => 'Open Sans (Google)', 'Lato' => 'Lato (Google)',
            'Montserrat' => 'Montserrat (Google)', 'Poppins' => 'Poppins (Google)', 'Raleway' => 'Raleway (Google)',
            'Oswald' => 'Oswald (Google)', 'Source Sans Pro' => 'Source Sans Pro (Google)',
            'Playfair Display' => 'Playfair Display (Google)', 'Merriweather' => 'Merriweather (Google)',
        );
    }

    public function section_callback($arguments) {
        $descriptions = array(
            'rmwr_basic_section'    => __('Button texts and core behavior.', 'rmwr'),
            'rmwr_style_section'    => __('Appearance of the Read More/Less buttons.', 'rmwr'),
            'rmwr_behavior_section' => __('Animations, scrolling, visibility rules and state.', 'rmwr'),
            'rmwr_content_section'  => __('Sitewide automation: collapse long content and heading sections with zero shortcodes.', 'rmwr'),
            'rmwr_growth_section'   => __('Analytics, A/B testing, Content Locker, Teaser Paywall and the engaged-user CTA.', 'rmwr'),
            'rmwr_ai_section'       => __('AI-generated TL;DR summaries shown above collapsed content ([read ai_summary="true"]).', 'rmwr'),
            'rmwr_advanced_section' => __('Performance, privacy and data management.', 'rmwr'),
        );

        if (isset($descriptions[$arguments['id']])) {
            echo '<p class="description">' . esc_html($descriptions[$arguments['id']]) . '</p>';
        }
    }

    public function field_callback($arguments) {
        $value = get_option($arguments['uid']);
        if (false === $value) {
            $value = $arguments['default'] ?? '';
        }

        switch ($arguments['type']) {
            case 'text':
                printf(
                    '<input name="%1$s" id="%1$s" type="text" placeholder="%2$s" value="%3$s" class="regular-text" />',
                    esc_attr($arguments['uid']),
                    esc_attr($arguments['placeholder'] ?? ''),
                    esc_attr($value)
                );
                break;

            case 'textarea':
                printf(
                    '<textarea name="%1$s" id="%1$s" rows="4" class="large-text">%2$s</textarea>',
                    esc_attr($arguments['uid']),
                    esc_textarea($value)
                );
                break;

            case 'number':
                $min = isset($arguments['min']) ? ' min="' . esc_attr($arguments['min']) . '"' : '';
                $max = isset($arguments['max']) ? ' max="' . esc_attr($arguments['max']) . '"' : '';
                printf(
                    '<input name="%1$s" id="%1$s" type="number" placeholder="%2$s" value="%3$s" class="small-text"%4$s%5$s />',
                    esc_attr($arguments['uid']),
                    esc_attr($arguments['placeholder'] ?? ''),
                    esc_attr($value),
                    $min, // phpcs:ignore WordPress.Security.EscapeOutput
                    $max  // phpcs:ignore WordPress.Security.EscapeOutput
                );
                break;

            case 'colorpicker':
                printf(
                    '<input name="%1$s" id="%1$s" type="text" class="cpa-color-picker" value="%2$s" />',
                    esc_attr($arguments['uid']),
                    esc_attr($value ?: ($arguments['default'] ?? '#ffffff'))
                );
                break;

            case 'select':
                echo '<select name="' . esc_attr($arguments['uid']) . '" id="' . esc_attr($arguments['uid']) . '">';
                foreach (($arguments['options'] ?? array()) as $key => $label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($key),
                        selected($value, $key, false),
                        esc_html($label)
                    );
                }
                echo '</select>';
                break;

            case 'checkbox':
                printf(
                    '<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s />',
                    esc_attr($arguments['uid']),
                    checked($value, '1', false)
                );
                break;

            case 'post_types':
                $selected = is_array($value) ? $value : array();
                $types    = get_post_types(array('public' => true), 'objects');
                unset($types['attachment']);
                foreach ($types as $type) {
                    printf(
                        '<label style="display:inline-block;margin-right:14px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
                        esc_attr($arguments['uid']),
                        esc_attr($type->name),
                        checked(in_array($type->name, $selected, true), true, false),
                        esc_html($type->labels->singular_name)
                    );
                }
                break;
        }

        // The two-column layout renders the helper in the label column, so it
        // is suppressed here when that flag is set.
        if (!empty($arguments['helper']) && empty($this->skip_field_helper)) {
            printf('<p class="description">%s</p>', wp_kses_post($arguments['helper']));
        }
    }

    public function sanitize_field_value($value, $field) {
        switch ($field['type']) {
            case 'colorpicker':
                return sanitize_hex_color($value) ?: ($field['default'] ?? '#ffffff');

            case 'checkbox':
                return ('1' === $value || 1 === $value) ? '1' : '';

            case 'number':
                if ('' === $value || null === $value) {
                    return '';
                }
                $number = absint($value);
                if (isset($field['min'])) {
                    $number = max((int) $field['min'], $number);
                }
                if (isset($field['max'])) {
                    $number = min((int) $field['max'], $number);
                }
                return $number;

            case 'select':
                $options = $field['options'] ?? array();
                return isset($options[$value]) ? sanitize_text_field($value) : ($field['default'] ?? '');

            case 'textarea':
                return wp_kses_post((string) $value);

            case 'post_types':
                $valid = get_post_types(array('public' => true));
                return array_values(array_intersect(array_map('sanitize_key', (array) $value), array_keys($valid)));

            case 'text':
            default:
                return sanitize_text_field((string) $value);
        }
    }

    /* ---------------------------------------------------------------------
     * Page rendering
     * ------------------------------------------------------------------ */

    public function render_notices() {
        if (!isset($_GET['page'], $_GET['rmwr_notice']) || 'read_more_without_refresh' !== $_GET['page']) {
            return;
        }

        $messages = array(
            'license_valid'       => array('success', __('License activated - automatic updates are enabled.', 'rmwr')),
            'license_invalid'     => array('error', __('The license key is not valid for this product.', 'rmwr')),
            'license_expired'     => array('error', __('This license has expired. Renew it to keep receiving updates.', 'rmwr')),
            'license_unreachable' => array('warning', __('Could not reach the license server. Please try again later.', 'rmwr')),
            'license_empty'       => array('warning', __('Please enter a license key.', 'rmwr')),
            'license_deactivated' => array('success', __('License deactivated on this site.', 'rmwr')),
            'import_ok'           => array('success', __('Settings imported successfully.', 'rmwr')),
            'import_invalid'      => array('error', __('The uploaded file is not a valid settings export.', 'rmwr')),
            'import_nofile'       => array('warning', __('Please choose a settings file to import.', 'rmwr')),
            'import_empty'        => array('warning', __('No valid settings found in the file.', 'rmwr')),
        );

        $notice = sanitize_text_field(wp_unslash($_GET['rmwr_notice']));
        if (!isset($messages[$notice])) {
            return;
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($messages[$notice][0]),
            esc_html($messages[$notice][1])
        );
    }

    /**
     * Sidebar card. For paying customers it shows the license status and a
     * link to the Freemius Account screen; for the free tier it shows a rich
     * upgrade card. The Account link is only rendered when the site is
     * actually registered with Freemius, so it can never hit the "not allowed"
     * account page before opt-in.
     */
    private function render_license_pointer() {
        $fs         = function_exists('rmwr_fs') ? rmwr_fs() : null;
        $registered = $fs && method_exists($fs, 'is_registered') && $fs->is_registered();
        $trial      = $fs && method_exists($fs, 'is_trial') && $fs->is_trial();
        $account    = ($registered && method_exists($fs, 'get_account_url')) ? $fs->get_account_url() : '';
        $upgrade    = function_exists('rmwr_upgrade_url') ? rmwr_upgrade_url() : 'https://shop.8web.gr/read-more-without-refresh-pro/';

        if (rmwr_is_premium()) {
            ?>
            <div class="rmwr-sidebar-widget rmwr-license-card">
                <h3><?php esc_html_e('License & Updates', 'rmwr'); ?></h3>
                <div class="rmwr-license-status">
                    <span class="rmwr-status-dot rmwr-status-active"></span>
                    <span><?php echo $trial ? esc_html__('Trial active', 'rmwr') : esc_html__('Active - automatic updates enabled', 'rmwr'); ?></span>
                </div>
                <?php if ($account) : ?>
                    <a href="<?php echo esc_url($account); ?>" class="button button-primary"><?php esc_html_e('Manage license', 'rmwr'); ?></a>
                <?php endif; ?>
            </div>
            <?php
            return;
        }

        // Free tier: rich upgrade card.
        $features = array(
            __('Accordion / FAQ mode + schema', 'rmwr'),
            __('Content locker & lead capture', 'rmwr'),
            __('Teaser paywall for members', 'rmwr'),
            __('AI summaries & real analytics', 'rmwr'),
            __('Elementor widget + auto-apply', 'rmwr'),
        );
        ?>
        <div class="rmwr-sidebar-widget rmwr-upgrade-card">
            <h3><?php esc_html_e('Upgrade to Pro', 'rmwr'); ?></h3>
            <ul class="rmwr-feature-list">
                <?php foreach ($features as $feature) : ?>
                    <li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span><?php echo esc_html($feature); ?></span></li>
                <?php endforeach; ?>
            </ul>
            <a href="<?php echo esc_url($upgrade); ?>" class="rmwr-cta-btn"><?php esc_html_e('Start your 3-day free trial', 'rmwr'); ?></a>
            <p class="rmwr-cta-note"><?php esc_html_e('Free for 3 days, cancel anytime. Secure checkout in your dashboard, 14-day money-back.', 'rmwr'); ?></p>
            <?php if ($registered && $account) : ?>
                <p class="rmwr-cta-note"><a href="<?php echo esc_url($account); ?>"><?php esc_html_e('Already purchased? Manage license', 'rmwr'); ?></a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'rmwr'));
        }
        ?>
        <?php $is_premium_tier = rmwr_is_premium(); ?>
        <div class="wrap rmwr-settings-wrap">
            <?php // Notice anchor: any stray admin notice lands here, above the header card - never inside it. ?>
            <div class="wp-header-end"></div>
            <div class="rmwr-header">
                <div class="rmwr-logo"><span class="dashicons dashicons-editor-expand"></span></div>
                <div class="rmwr-header-text">
                    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                    <p class="rmwr-subtitle"><?php esc_html_e('Show and hide content without a page reload.', 'rmwr'); ?></p>
                </div>
                <span class="rmwr-tier-badge <?php echo $is_premium_tier ? 'rmwr-tier-pro' : 'rmwr-tier-free'; ?>">
                    <?php echo $is_premium_tier ? esc_html__('Pro', 'rmwr') : esc_html__('Free', 'rmwr'); ?>
                </span>
            </div>

            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']) : ?>
                <div class="notice notice-success is-dismissible rmwr-notice">
                    <p><strong><?php esc_html_e('Settings saved successfully!', 'rmwr'); ?></strong></p>
                </div>
            <?php endif; ?>

            <div class="rmwr-settings-container">
                <div class="rmwr-main-content">
                    <form method="POST" action="options.php" class="rmwr-settings-form">
                        <?php
                        settings_fields('read_more_without_refresh');
                        $this->render_sections_with_tabs();
                        submit_button(__('Save Settings', 'rmwr'), 'primary large rmwr-save-button');
                        ?>
                    </form>
                </div>

                <div class="rmwr-sidebar">
                    <?php $this->render_license_pointer(); ?>
                    <?php if (rmwr_is_premium()) { RMWR_Import_Export::render_tools_box(); } ?>

                    <div class="rmwr-sidebar-widget">
                        <h3><?php esc_html_e('Need a hand?', 'rmwr'); ?></h3>
                        <p><?php esc_html_e('See ready-to-copy shortcodes in the How to use tab.', 'rmwr'); ?></p>
                        <a href="#" class="button rmwr-goto-howto"><?php esc_html_e('Open How to use', 'rmwr'); ?></a>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function($) {
            function activate(target) {
                $('.rmwr-tab').removeClass('active');
                $('.rmwr-tab[data-tab="' + target + '"]').addClass('active');
                $('.rmwr-tab-content').removeClass('active');
                $('.rmwr-tab-content[data-tab="' + target + '"]').addClass('active');
            }
            $('.rmwr-tab').on('click', function(e) {
                e.preventDefault();
                activate($(this).data('tab'));
            });
            $('.rmwr-goto-howto').on('click', function(e) { e.preventDefault(); activate('howto'); });
            $(document).on('click', '.rmwr-copy-btn', function() {
                var btn = this, code = btn.getAttribute('data-code');
                var done = function() { btn.classList.add('copied'); btn.querySelector('.dashicons').className = 'dashicons dashicons-yes'; setTimeout(function(){ btn.classList.remove('copied'); btn.querySelector('.dashicons').className = 'dashicons dashicons-admin-page'; }, 1400); };
                if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(code).then(done).catch(done); }
                else { var t=document.createElement('textarea'); t.value=code; document.body.appendChild(t); t.select(); try{document.execCommand('copy');}catch(e){} document.body.removeChild(t); done(); }
            });
        })(jQuery);
        </script>
        <?php
    }

    /** When true, field_callback skips its own helper (rendered in the label). */
    private $skip_field_helper = false;

    /** Section ids that require a Pro license. */
    private function premium_sections() {
        return array('rmwr_behavior_section', 'rmwr_content_section', 'rmwr_growth_section', 'rmwr_ai_section');
    }

    /**
     * Whether a settings section should be shown locked (Pro preview).
     *
     * The Style section is special: it is Pro, but sites that already
     * customized their button before the change keep it unlocked
     * (grandfathered) via rmwr_style_allowed().
     *
     * @param string $section_id Section id.
     * @return bool
     */
    private function is_section_locked($section_id) {
        if ('rmwr_style_section' === $section_id) {
            return !rmwr_style_allowed();
        }
        return !rmwr_is_premium() && in_array($section_id, $this->premium_sections(), true);
    }

    /**
     * Has this site customized the button style (any Style option differs from
     * its default)? Used once, on upgrade, to grandfather existing designs.
     *
     * @return bool
     */
    public static function has_custom_styles() {
        $defaults = array(
            'rmwr_background_color'    => '#ffffff',
            'rmwr_text_color'          => '#000000',
            'rmwr_text_hover_color'    => '#191919',
            'rmwr_font_weight'         => 'normal',
            'rmwr_font_size'           => '',
            'rmwr_text_transform'      => 'none',
            'rmwr_padding'             => '0px',
            'rmwr_border_bottom'       => '1px',
            'rmwr_border_bottom_color' => '#000000',
            'rmwr_border_radius'       => '0px',
            'rmwr_button_template'     => '',
            'rmwr_icon'                => '',
            'rmwr_fontawesome_icon'    => '',
            'rmwr_background_gradient' => '',
            'rmwr_font_family'         => 'inherit',
            'rmwr_line_height'         => '',
            'rmwr_letter_spacing'      => '',
        );
        foreach ($defaults as $option => $default) {
            if ((string) get_option($option, $default) !== (string) $default) {
                return true;
            }
        }
        return false;
    }

    private function render_sections_with_tabs() {
        $page       = 'read_more_without_refresh';
        $is_premium = rmwr_is_premium();
        $premium    = $this->premium_sections();

        // section_id => [tab slug, label]
        $section_tabs = array();
        foreach ($this->tabs() as $slug => $tab) {
            $section_tabs[$tab[0]] = array($slug, $tab[1]);
        }

        echo '<div class="rmwr-tabs-wrapper">';
        echo '<nav class="rmwr-tab-nav">';
        $first = true;
        foreach ($this->tabs() as $slug => $tab) {
            $lock = $this->is_section_locked($tab[0])
                ? ' <span class="rmwr-pro-badge-small">PRO</span>'
                : '';
            $icon = isset($tab[2]) ? $tab[2] : 'admin-generic';
            printf(
                '<a href="#" class="rmwr-tab%s" data-tab="%s"><span class="dashicons dashicons-%s" aria-hidden="true"></span><span class="rmwr-tab-label">%s</span>%s</a>',
                $first ? ' active' : '',
                esc_attr($slug),
                esc_attr($icon),
                esc_html($tab[1]),
                $lock // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
            );
            $first = false;
        }
        echo '</nav>';

        echo '<div class="rmwr-tabs-content-wrapper">';

        global $wp_settings_sections, $wp_settings_fields;

        if (isset($wp_settings_sections[$page])) {
            $first = true;
            foreach ((array) $wp_settings_sections[$page] as $section) {
                $section_id = $section['id'];
                $tab_info   = isset($section_tabs[$section_id]) ? $section_tabs[$section_id] : array('other', '');
                $locked     = $this->is_section_locked($section_id);

                printf(
                    '<div class="rmwr-tab-content%s" data-tab="%s"><div class="rmwr-section%s">',
                    $first ? ' active' : '',
                    esc_attr($tab_info[0]),
                    $locked ? ' rmwr-section-locked' : ''
                );
                $first = false;

                if ($locked) {
                    // Free tier: show the real premium fields, faded and
                    // non-interactive, with a clear upgrade banner above and a
                    // faint lock watermark on top - so users can see exactly
                    // what Pro unlocks.
                    $this->render_upgrade_banner($tab_info[1]);
                    echo '<div class="rmwr-locked-fields" inert aria-hidden="true">';
                    if ($section['callback']) {
                        call_user_func($section['callback'], $section);
                    }
                    $this->render_fields_table($page, $section_id);
                    echo '</div>';
                    echo '<span class="rmwr-lock-watermark dashicons dashicons-lock" aria-hidden="true"></span>';
                } else {
                    if ($section['callback']) {
                        call_user_func($section['callback'], $section);
                    }
                    $this->render_fields_table($page, $section_id);
                }

                echo '</div></div>';
            }
        }

        // "How to use" is not a settings section - render it on its own tab.
        echo '<div class="rmwr-tab-content" data-tab="howto"><div class="rmwr-section">';
        $this->render_howto();
        echo '</div></div>';

        echo '</div>';
        echo '</div>';
    }

    /**
     * Render the polished "How to use" shortcode reference.
     */
    private function render_howto() {
        $cards = array(
            array('icon' => 'editor-expand', 'title' => __('Basic toggle', 'rmwr'), 'pro' => false,
                'desc' => __('Show and hide any content with a click, no page reload.', 'rmwr'),
                'code' => '[read open="Show more" close="Show less"]Your hidden content[/read]'),
            array('icon' => 'editor-contract', 'title' => __('Truncate long text', 'rmwr'), 'pro' => true,
                'desc' => __('Show the first N words and hide the rest automatically.', 'rmwr'),
                'code' => '[read limit="80"]Your full text here[/read]'),
            array('icon' => 'menu', 'title' => __('Accordion / FAQ', 'rmwr'), 'pro' => true,
                'desc' => __('Group items into an accordion and emit valid FAQ schema.', 'rmwr'),
                'code' => '[read mode="accordion" question="How long is shipping?"]2-4 days[/read]'),
            array('icon' => 'email', 'title' => __('Content locker', 'rmwr'), 'pro' => true,
                'desc' => __('Unlock content with an email or a social share - grow your list.', 'rmwr'),
                'code' => '[read lock="email"]Your bonus content[/read]'),
            array('icon' => 'lock', 'title' => __('Teaser paywall', 'rmwr'), 'pro' => true,
                'desc' => __('Fade-out teaser with a "continue reading" call to action for members.', 'rmwr'),
                'code' => '[read paywall="loggedin"]Members-only content[/read]'),
            array('icon' => 'admin-page', 'title' => __('Reusable block', 'rmwr'), 'pro' => true,
                'desc' => __('Write once, embed the same collapsible content anywhere.', 'rmwr'),
                'code' => '[read block="shipping-info"][/read]'),
            array('icon' => 'superhero-alt', 'title' => __('AI summary (TL;DR)', 'rmwr'), 'pro' => true,
                'desc' => __('Show an AI-generated summary above the collapsed content.', 'rmwr'),
                'code' => '[read ai_summary="true"]Your article[/read]'),
            array('icon' => 'controls-repeat', 'title' => __('Expand / collapse all', 'rmwr'), 'pro' => true,
                'desc' => __('A single button that opens or closes every item on the page.', 'rmwr'),
                'code' => '[read_all]'),

            // Settings-based features (no shortcode) - shown with where to find them.
            array('icon' => 'search', 'title' => __('Get found by AI (AEO)', 'rmwr'), 'pro' => true,
                'desc' => __('Publish an llms.txt index and ping IndexNow so ChatGPT, Google AI Overviews and Bing find and re-crawl your content fast.', 'rmwr'),
                'where' => __('Growth > AI Answer Optimizer', 'rmwr')),
            array('icon' => 'chart-line', 'title' => __('Engagement to ad platforms', 'rmwr'), 'pro' => true,
                'desc' => __('Fire a conversion into GA4, Meta Pixel, Google Ads or Google Tag Manager when readers expand, unlock or convert - then retarget them.', 'rmwr'),
                'where' => __('Growth > Send engagement to ad platforms', 'rmwr')),
            array('icon' => 'editor-ol', 'title' => __('Bulk auto-collapse', 'rmwr'), 'pro' => true,
                'desc' => __('Collapse long or old posts sitewide by word count, by paragraph (Ad-Inserter style), or with a smart cut point - zero shortcodes.', 'rmwr'),
                'where' => __('Auto Content > Enable Auto-Apply', 'rmwr')),
            array('icon' => 'admin-links', 'title' => __('Related posts inside Read More', 'rmwr'), 'pro' => true,
                'desc' => __('Append related posts inside the expanded content for internal links, more pageviews and dwell time.', 'rmwr'),
                'where' => __('Auto Content > Related posts inside Read More', 'rmwr')),
            array('icon' => 'chart-bar', 'title' => __('Reading progress + resume', 'rmwr'), 'pro' => false,
                'desc' => __('A slim progress bar plus a Continue Reading button that returns visitors to where they stopped.', 'rmwr'),
                'where' => __('Behavior > Reading progress bar', 'rmwr')),
            array('icon' => 'calendar-alt', 'title' => __('Engagement heatmap', 'rmwr'), 'pro' => true,
                'desc' => __('See which day and hour your readers expand content most, so you can time posts and campaigns.', 'rmwr'),
                'where' => __('Analytics dashboard', 'rmwr')),
        );
        ?>
        <p class="rmwr-howto-intro">
            <?php esc_html_e('Copy a shortcode snippet into any post, page or widget. Cards with a settings path are turned on under Settings. Items marked Pro require a license.', 'rmwr'); ?>
        </p>
        <div class="rmwr-howto-grid">
            <?php foreach ($cards as $card) : ?>
                <div class="rmwr-howto-card">
                    <div class="rmwr-howto-card-head">
                        <span class="rmwr-howto-card-icon"><span class="dashicons dashicons-<?php echo esc_attr($card['icon']); ?>" aria-hidden="true"></span></span>
                        <h3><?php echo esc_html($card['title']); ?></h3>
                        <?php if ($card['pro']) : ?><span class="rmwr-howto-card-badge">Pro</span><?php endif; ?>
                    </div>
                    <p><?php echo esc_html($card['desc']); ?></p>
                    <?php if (!empty($card['code'])) : ?>
                        <div class="rmwr-howto-code">
                            <code><?php echo esc_html($card['code']); ?></code>
                            <button type="button" class="rmwr-copy-btn" data-code="<?php echo esc_attr($card['code']); ?>" aria-label="<?php esc_attr_e('Copy', 'rmwr'); ?>">
                                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                            </button>
                        </div>
                    <?php elseif (!empty($card['where'])) : ?>
                        <div class="rmwr-howto-where" style="display:flex;align-items:center;gap:6px;font-size:12px;color:#50575e;background:#f0f0f1;border-radius:6px;padding:8px 10px;">
                            <span class="dashicons dashicons-admin-settings" aria-hidden="true" style="font-size:16px;width:16px;height:16px;"></span>
                            <span><?php echo esc_html($card['where']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render a section's fields as fancy two-column rows: label + description
     * on the left, control on the right.
     *
     * @param string $page       Settings page slug.
     * @param string $section_id Section id.
     */
    private function render_fields_table($page, $section_id) {
        global $wp_settings_fields;

        if (!isset($wp_settings_fields[$page][$section_id])) {
            return;
        }

        echo '<div class="rmwr-fields">';
        $this->skip_field_helper = true;
        foreach ((array) $wp_settings_fields[$page][$section_id] as $field) {
            $helper = isset($field['args']['helper']) ? $field['args']['helper'] : '';
            echo '<div class="rmwr-field-row">';
            echo '<div class="rmwr-field-label">';
            echo '<span class="rmwr-field-title">' . $field['title'] . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
            if ('' !== $helper) {
                echo '<p class="rmwr-field-desc">' . wp_kses_post($helper) . '</p>';
            }
            echo '</div>';
            echo '<div class="rmwr-field-control">';
            call_user_func($field['callback'], $field['args']);
            echo '</div>';
            echo '</div>';
        }
        $this->skip_field_helper = false;
        echo '</div>';
    }

    /**
     * Actionable upgrade banner shown above a locked premium section.
     *
     * @param string $label Section label.
     */
    private function render_upgrade_banner($label) {
        $url = function_exists('rmwr_upgrade_url') ? rmwr_upgrade_url() : 'https://shop.8web.gr/read-more-without-refresh-pro/';
        ?>
        <div class="rmwr-upgrade-banner">
            <span class="dashicons dashicons-lock" aria-hidden="true"></span>
            <div class="rmwr-upgrade-banner-text">
                <strong>
                    <?php
                    /* translators: %s: settings section name */
                    echo esc_html(sprintf(__('%s is a Pro feature', 'rmwr'), $label));
                    ?>
                </strong>
                <span><?php esc_html_e('Preview below. Upgrade to unlock and configure it.', 'rmwr'); ?></span>
            </div>
            <a href="<?php echo esc_url($url); ?>" class="rmwr-upgrade-banner-btn"><?php esc_html_e('Upgrade to Pro', 'rmwr'); ?></a>
        </div>
        <?php
    }
}
