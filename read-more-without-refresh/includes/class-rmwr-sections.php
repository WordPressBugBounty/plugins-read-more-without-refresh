<?php
/**
 * Auto-Collapse Sections ("Wikipedia mode") + automatic Table of Contents.
 *
 * When enabled for a post type, every H2 (or H3) section of the content is
 * converted into an accessible collapsible section: the heading stays a real
 * heading element (SEO-safe) wrapping a toggle button, and the section body
 * collapses below it. Optionally a Table of Contents is injected at the top;
 * clicking a TOC entry (or arriving with a #hash deep link) expands the
 * right section and scrolls to it.
 *
 * All content stays in the initial HTML - nothing is removed - so search
 * engines index everything.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Sections {

    public function __construct() {
        add_filter('the_content', array($this, 'filter_content'), 99);
    }

    /**
     * Does the sections engine fire for this post?
     *
     * @param WP_Post $post Post object.
     * @return bool
     */
    public static function applies_to_post($post) {
        if (!$post instanceof WP_Post || '1' !== get_option('rmwr_sections_enable', '0')) {
            return false;
        }

        if (!class_exists('DOMDocument')) {
            return false; // php-xml missing on the server.
        }

        $types = (array) get_option('rmwr_sections_post_types', array());
        if (!in_array($post->post_type, $types, true)) {
            return false;
        }

        if ('1' === get_post_meta($post->ID, '_rmwr_sections_off', true)) {
            return false;
        }

        return true;
    }

    /**
     * Transform the content of enabled posts.
     *
     * @param string $content Rendered content.
     * @return string
     */
    public function filter_content($content) {
        if (is_admin() || is_feed() || !is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post = get_post();
        if (!self::applies_to_post($post)) {
            return $content;
        }

        $heading = 'h3' === get_option('rmwr_sections_heading', 'h2') ? 'h3' : 'h2';

        $transformed = $this->build_sections($content, $heading, (int) $post->ID);

        return null === $transformed ? $content : $transformed;
    }

    /**
     * Parse the HTML and rebuild it with collapsible sections + TOC.
     *
     * @param string $html    Content HTML.
     * @param string $heading 'h2' or 'h3'.
     * @param int    $post_id Post ID (for unique IDs).
     * @return string|null Null when there is nothing to do.
     */
    private function build_sections($html, $heading, $post_id) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>',
            defined('LIBXML_NOERROR') ? LIBXML_NOERROR : 0
        );
        libxml_clear_errors();

        if (!$loaded) {
            return null;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return null;
        }

        // Group top-level nodes: intro (before first heading) + sections.
        $intro    = array();
        $sections = array();
        $open     = null; // Index of the section currently being filled.

        foreach (iterator_to_array($body->childNodes) as $node) {
            $is_heading = XML_ELEMENT_NODE === $node->nodeType && strtolower($node->nodeName) === $heading;
            $title      = $is_heading ? trim($node->textContent) : '';

            if ($is_heading && '' !== $title) {
                $sections[] = array('title' => $title, 'nodes' => array());
                $open       = count($sections) - 1;
            } elseif (null === $open) {
                $intro[] = $node;
            } else {
                $sections[$open]['nodes'][] = $node;
            }
        }

        if (count($sections) < 2) {
            return null; // One section is not worth collapsing.
        }

        $first_open = '1' === get_option('rmwr_sections_first_open', '1');
        $show_toc   = '1' === get_option('rmwr_sections_toc', '1');

        RMWR_Assets::mark_needed();

        $out       = '';
        $toc_items = array();
        $used      = array();

        // Pre-compute slugs for TOC + anchors.
        foreach ($sections as $i => $section) {
            $slug = sanitize_title($section['title']);
            if ('' === $slug) {
                $slug = 'section-' . ($i + 1);
            }
            if (isset($used[$slug])) {
                $slug .= '-' . ($i + 1);
            }
            $used[$slug]          = true;
            $sections[$i]['slug'] = $slug;
            $toc_items[]          = array('title' => $section['title'], 'slug' => $slug);
        }

        if ($show_toc) {
            $out .= '<nav class="rmwr-toc" aria-label="' . esc_attr__('Table of contents', 'rmwr') . '">';
            $out .= '<p class="rmwr-toc-title">' . esc_html(apply_filters('rmwr_toc_title', __('Contents', 'rmwr'))) . '</p><ol>';
            foreach ($toc_items as $item) {
                $out .= '<li><a href="#rmwr-sec-' . esc_attr($item['slug']) . '">' . esc_html($item['title']) . '</a></li>';
            }
            $out .= '</ol></nav>';
        }

        foreach ($intro as $node) {
            $out .= $dom->saveHTML($node);
        }

        foreach ($sections as $i => $section) {
            $slug     = $section['slug'];
            $panel_id = 'rmwr-sec-panel-' . $post_id . '-' . ($i + 1);
            $is_open  = $first_open && 0 === $i;

            $body_html = '';
            foreach ($section['nodes'] as $node) {
                $body_html .= $dom->saveHTML($node);
            }

            $out .= sprintf(
                '<%1$s class="rmwr-sec-title" id="rmwr-sec-%2$s">' .
                '<button type="button" class="rmwr-sec-btn" aria-expanded="%3$s" aria-controls="%4$s">' .
                '<span class="rmwr-sec-text">%5$s</span><span class="rmwr-sec-icon" aria-hidden="true"></span>' .
                '</button></%1$s>' .
                '<div class="rmwr-sec-panel" id="%4$s"%6$s>%7$s</div>',
                $heading,
                esc_attr($slug),
                $is_open ? 'true' : 'false',
                esc_attr($panel_id),
                esc_html($section['title']),
                $is_open ? '' : ' hidden',
                $body_html
            );
        }

        return $out;
    }
}
