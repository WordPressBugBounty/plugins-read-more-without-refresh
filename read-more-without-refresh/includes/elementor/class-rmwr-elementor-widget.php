<?php
/**
 * Native Elementor widget: "Read More Toggle".
 *
 * Drag the widget anywhere, type the hidden content in a WYSIWYG control,
 * pick texts/animation/template/mode - live preview included. Rendering
 * goes through the same shortcode pipeline as everything else, so all Pro
 * behavior (analytics, A/B, CTA, schema) applies automatically.
 *
 * Loaded only when Elementor is active (see RMWR_Pro).
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Elementor_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'rmwr-read-more';
    }

    public function get_title() {
        return __('Read More Toggle', 'rmwr');
    }

    public function get_icon() {
        return 'eicon-toggle';
    }

    public function get_categories() {
        return array('general');
    }

    public function get_keywords() {
        return array('read more', 'toggle', 'expand', 'collapse', 'accordion', 'rmwr');
    }

    protected function register_controls() {

        $this->start_controls_section('section_content', array(
            'label' => __('Content', 'rmwr'),
        ));

        $this->add_control('hidden_content', array(
            'label'   => __('Hidden content', 'rmwr'),
            'type'    => \Elementor\Controls_Manager::WYSIWYG,
            'default' => __('Your hidden content here...', 'rmwr'),
        ));

        $this->add_control('open_text', array(
            'label'       => __('Read More text', 'rmwr'),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'placeholder' => get_option('rm_text', 'Read More'),
        ));

        $this->add_control('close_text', array(
            'label'       => __('Read Less text', 'rmwr'),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'placeholder' => get_option('rl_text', 'Read Less'),
        ));

        $this->add_control('instance_id', array(
            'label'       => __('Instance ID (analytics)', 'rmwr'),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'description' => __('Optional custom ID to identify this toggle in the Analytics dashboard.', 'rmwr'),
        ));

        $this->end_controls_section();

        $this->start_controls_section('section_behavior', array(
            'label' => __('Behavior', 'rmwr'),
        ));

        $this->add_control('mode', array(
            'label'   => __('Mode', 'rmwr'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'normal',
            'options' => array(
                'normal'    => __('Normal toggle', 'rmwr'),
                'accordion' => __('Accordion (one open at a time)', 'rmwr'),
            ),
        ));

        $this->add_control('accordion_id', array(
            'label'     => __('Accordion group', 'rmwr'),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'condition' => array('mode' => 'accordion'),
        ));

        $this->add_control('question', array(
            'label'       => __('FAQ question (schema)', 'rmwr'),
            'type'        => \Elementor\Controls_Manager::TEXT,
            'description' => __('Adds this Q&A pair to the page FAQPage schema (accordion mode).', 'rmwr'),
            'condition'   => array('mode' => 'accordion'),
        ));

        $this->add_control('animation', array(
            'label'   => __('Animation', 'rmwr'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '',
            'options' => array(
                ''        => __('Global default', 'rmwr'),
                'none'    => __('None', 'rmwr'),
                'fade'    => __('Fade', 'rmwr'),
                'slide'   => __('Slide', 'rmwr'),
                'flip'    => __('Flip', 'rmwr'),
                'zoom'    => __('Zoom', 'rmwr'),
                'bounce'  => __('Bounce', 'rmwr'),
                'rotate'  => __('Rotate', 'rmwr'),
                'scale'   => __('Scale', 'rmwr'),
                'elastic' => __('Elastic', 'rmwr'),
            ),
        ));

        $this->add_control('duration', array(
            'label'   => __('Animation duration (ms)', 'rmwr'),
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'default' => '',
            'min'     => 0,
            'max'     => 3000,
        ));

        $this->end_controls_section();

        $this->start_controls_section('section_style', array(
            'label' => __('Button style', 'rmwr'),
        ));

        $this->add_control('template', array(
            'label'   => __('Button template', 'rmwr'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => '',
            'options' => array(
                ''                  => __('Global default', 'rmwr'),
                'modern-blue'       => __('Modern Blue', 'rmwr'),
                'classic-underline' => __('Classic Underline', 'rmwr'),
                'rounded-gradient'  => __('Rounded Gradient', 'rmwr'),
                'minimalist'        => __('Minimalist', 'rmwr'),
                'bold-button'       => __('Bold Button', 'rmwr'),
                'soft-shadow'       => __('Soft Shadow', 'rmwr'),
                'outline-style'     => __('Outline Style', 'rmwr'),
                'filled-primary'    => __('Filled Primary', 'rmwr'),
                'ghost-button'      => __('Ghost Button', 'rmwr'),
                'pill-shape'        => __('Pill Shape', 'rmwr'),
                'flat-design'       => __('Flat Design', 'rmwr'),
                '3d-effect'         => __('3D Effect', 'rmwr'),
                'glassmorphism'     => __('Glassmorphism', 'rmwr'),
                'neon-glow'         => __('Neon Glow', 'rmwr'),
                'vintage-style'     => __('Vintage Style', 'rmwr'),
                'corporate-blue'    => __('Corporate Blue', 'rmwr'),
                'playful-yellow'    => __('Playful Yellow', 'rmwr'),
                'elegant-purple'    => __('Elegant Purple', 'rmwr'),
                'nature-green'      => __('Nature Green', 'rmwr'),
            ),
        ));

        $this->add_control('css_class', array(
            'label' => __('Extra CSS class', 'rmwr'),
            'type'  => \Elementor\Controls_Manager::TEXT,
        ));

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();

        $atts = array(
            'id'           => (string) ($settings['instance_id'] ?? ''),
            'open'         => (string) ($settings['open_text'] ?? ''),
            'close'        => (string) ($settings['close_text'] ?? ''),
            'class'        => (string) ($settings['css_class'] ?? ''),
            'mode'         => (string) ($settings['mode'] ?? 'normal'),
            'accordion_id' => (string) ($settings['accordion_id'] ?? ''),
            'question'     => (string) ($settings['question'] ?? ''),
            'animation'    => (string) ($settings['animation'] ?? ''),
            'duration'     => (string) ($settings['duration'] ?? ''),
            'template'     => (string) ($settings['template'] ?? ''),
        );

        $shortcode = RMWR_Pro::get_instance()->modules['shortcode'];

        // In the editor preview, render expanded so the user sees the content.
        $output = $shortcode->render($atts, (string) ($settings['hidden_content'] ?? ''));

        if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
            $output = str_replace('style="display: none;"', 'style=""', $output);
        }

        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }
}
