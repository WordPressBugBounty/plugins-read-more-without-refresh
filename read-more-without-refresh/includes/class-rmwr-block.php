<?php
/**
 * Gutenberg blocks.
 *
 * v5 introduces the InnerBlocks-powered block "rmwr/read-more": the hidden
 * area is a full block canvas, so users can hide ANYTHING - images,
 * galleries, columns, embeds, other plugins' blocks - not just rich text.
 *
 * The legacy v4 block (read-more-without-refresh-pro/read-more-block,
 * RichText-only) stays registered so existing posts keep rendering.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Block {

    public function __construct() {
        add_action('init', array($this, 'register_blocks'));
    }

    public function register_blocks() {
        if (!function_exists('register_block_type')) {
            return;
        }

        $shared_attributes = array(
            'instanceId'        => array('type' => 'string', 'default' => ''),
            'openText'          => array('type' => 'string', 'default' => ''),
            'closeText'         => array('type' => 'string', 'default' => ''),
            'animation'         => array('type' => 'string', 'default' => ''),
            'animationDuration' => array('type' => 'number', 'default' => 0),
            'customClass'       => array('type' => 'string', 'default' => ''),
            'mode'              => array('type' => 'string', 'default' => 'normal'),
            'accordionId'       => array('type' => 'string', 'default' => ''),
            'question'          => array('type' => 'string', 'default' => ''),
            'template'          => array('type' => 'string', 'default' => ''),
            'icon'              => array('type' => 'string', 'default' => ''),
        );

        // v5 InnerBlocks block.
        register_block_type('rmwr/read-more', array(
            'api_version'     => 2,
            'render_callback' => array($this, 'render_inner_blocks_block'),
            'attributes'      => $shared_attributes,
        ));

        // Legacy v4 block (RichText content attribute).
        register_block_type('read-more-without-refresh-pro/read-more-block', array(
            'render_callback' => array($this, 'render_legacy_block'),
            'attributes'      => array_merge($shared_attributes, array(
                'content' => array('type' => 'string', 'default' => ''),
            )),
        ));
    }

    /**
     * Map block attributes onto shortcode attributes.
     *
     * @param array $attributes Block attributes.
     * @return array
     */
    private function to_shortcode_atts($attributes) {
        return array(
            'id'           => $attributes['instanceId'] ?? '',
            'open'         => $attributes['openText'] ?? '',
            'close'        => $attributes['closeText'] ?? '',
            'class'        => $attributes['customClass'] ?? '',
            'animation'    => $attributes['animation'] ?? '',
            'duration'     => !empty($attributes['animationDuration']) ? (string) $attributes['animationDuration'] : '',
            'mode'         => $attributes['mode'] ?? 'normal',
            'accordion_id' => $attributes['accordionId'] ?? '',
            'question'     => $attributes['question'] ?? '',
            'template'     => $attributes['template'] ?? '',
            'icon'         => $attributes['icon'] ?? '',
        );
    }

    /**
     * Render the v5 InnerBlocks block: $content is the rendered inner block
     * HTML (already sanitized by the editor on save).
     */
    public function render_inner_blocks_block($attributes, $content) {
        if ('' === trim(wp_strip_all_tags((string) $content, true))
            && false === strpos((string) $content, '<img')
            && false === strpos((string) $content, '<iframe')) {
            return '';
        }

        $shortcode = RMWR_Pro::get_instance()->modules['shortcode'];

        return $shortcode->render_internal($this->to_shortcode_atts($attributes), $content);
    }

    /**
     * Render the legacy v4 block (content lives in an attribute).
     */
    public function render_legacy_block($attributes, $content) {
        $shortcode = RMWR_Pro::get_instance()->modules['shortcode'];

        return $shortcode->render($this->to_shortcode_atts($attributes), $attributes['content'] ?? '');
    }
}
