<?php
/**
 * AEO - AI Engine Optimization.
 *
 * Makes the site's content easy for AI answer engines (ChatGPT, Google AI
 * Overviews, Perplexity, ...) to find, parse and cite. Three parts:
 *
 *   1. FAQ / QA schema - handled by RMWR_Schema during rendering (accordion
 *      Q&A pairs become a single valid FAQPage). This module only exposes the
 *      toggle; the plugin's core promise (hidden content stays in the HTML,
 *      fully crawlable) already does the heavy lifting.
 *   2. llms.txt - a Markdown index of the site's key content served at
 *      /llms.txt, the emerging convention AI crawlers look for.
 *   3. IndexNow - instantly ping participating engines (Bing, Yandex and
 *      others) when content is published or updated, so re-crawls happen in
 *      minutes instead of days. Opt-in, since it makes outbound requests.
 *
 * Premium feature: the module is only instantiated on the premium tier.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_AEO {

    /** Virtual path (no leading slash) that serves the Markdown index. */
    const LLMS_PATH = 'llms.txt';

    /** Option holding the auto-generated IndexNow key. */
    const KEY_OPTION = 'rmwr_indexnow_key';

    /** Transient caching the generated llms.txt body. */
    const LLMS_CACHE = 'rmwr_llms_txt';

    public function __construct() {
        add_action('template_redirect', array($this, 'maybe_serve_virtual_file'));
        add_action('transition_post_status', array($this, 'on_transition'), 10, 3);
        add_action('save_post', array($this, 'flush_llms_cache'));
        add_action('deleted_post', array($this, 'flush_llms_cache'));
    }

    /* ---------------------------------------------------------------------
     * Feature flags
     * ------------------------------------------------------------------ */

    private static function enabled() {
        return '1' === get_option('rmwr_aeo_enable', '0');
    }

    private static function llms_enabled() {
        return self::enabled() && '1' === get_option('rmwr_aeo_llms', '1');
    }

    private static function indexnow_enabled() {
        return self::enabled() && '1' === get_option('rmwr_aeo_indexnow', '0');
    }

    /* ---------------------------------------------------------------------
     * Virtual files: /llms.txt and the IndexNow key file
     * ------------------------------------------------------------------ */

    /**
     * Serve /llms.txt or the IndexNow key verification file, if requested.
     */
    public function maybe_serve_virtual_file() {
        $uri  = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $path = trim($path, '/');

        if ('' === $path) {
            return;
        }

        if (self::llms_enabled() && self::LLMS_PATH === $path) {
            $this->serve_llms_txt();
        }

        // IndexNow ownership proof: the key served as plain text at /<key>.txt.
        if (self::indexnow_enabled()) {
            $key = self::key();
            if ('' !== $key && $key . '.txt' === $path) {
                header('Content-Type: text/plain; charset=utf-8');
                echo esc_html($key);
                exit;
            }
        }
    }

    /**
     * Build (and cache) the Markdown index and stream it.
     */
    private function serve_llms_txt() {
        $body = get_transient(self::LLMS_CACHE);
        if (false === $body) {
            $body = self::build_llms_txt();
            set_transient(self::LLMS_CACHE, $body, 12 * HOUR_IN_SECONDS);
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text Markdown, values escaped in the builder.
        exit;
    }

    /**
     * Generate the llms.txt Markdown: site name, summary, then a linked,
     * lightly-described list of pages and recent posts.
     *
     * @return string
     */
    public static function build_llms_txt() {
        $lines   = array();
        $lines[] = '# ' . self::clean(get_bloginfo('name'));
        $lines[] = '';

        $tagline = self::clean(get_bloginfo('description'));
        if ('' !== $tagline) {
            $lines[] = '> ' . $tagline;
            $lines[] = '';
        }

        $pages = self::section(__('Pages', 'rmwr'), 'page', 100);
        if ('' !== $pages) {
            $lines[] = $pages;
        }

        $posts = self::section(__('Posts', 'rmwr'), 'post', 200);
        if ('' !== $posts) {
            $lines[] = $posts;
        }

        return trim(implode("\n", $lines)) . "\n";
    }

    /**
     * One "## Heading" block listing published items of a post type.
     *
     * @param string $heading   Section heading.
     * @param string $post_type Post type slug.
     * @param int    $limit     Maximum items.
     * @return string
     */
    private static function section($heading, $post_type, $limit) {
        $items = get_posts(array(
            'post_type'        => $post_type,
            'post_status'      => 'publish',
            'numberposts'      => $limit,
            'orderby'          => 'modified',
            'order'            => 'DESC',
            'suppress_filters' => false,
        ));

        if (empty($items)) {
            return '';
        }

        $out = array('## ' . self::clean($heading), '');
        foreach ($items as $item) {
            $title = self::clean(get_the_title($item));
            $url   = get_permalink($item);
            if ('' === $title || !$url) {
                continue;
            }
            $line    = '- [' . $title . '](' . esc_url_raw($url) . ')';
            $excerpt = self::excerpt($item);
            if ('' !== $excerpt) {
                $line .= ': ' . $excerpt;
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * Short plain-text excerpt for the index (about 30 words).
     *
     * @param WP_Post $post Post object.
     * @return string
     */
    private static function excerpt($post) {
        $text = has_excerpt($post) ? $post->post_excerpt : $post->post_content;
        $text = self::clean(wp_strip_all_tags(strip_shortcodes((string) $text)));
        return wp_trim_words($text, 30, '');
    }

    /**
     * Collapse whitespace and strip characters that would break a Markdown
     * line (newlines, pipes, brackets).
     *
     * @param string $value Raw value.
     * @return string
     */
    private static function clean($value) {
        $value = preg_replace('/\s+/u', ' ', (string) $value);
        $value = str_replace(array('|', '[', ']'), array('-', '(', ')'), (string) $value);
        return trim($value);
    }

    public function flush_llms_cache() {
        delete_transient(self::LLMS_CACHE);
    }

    /* ---------------------------------------------------------------------
     * IndexNow
     * ------------------------------------------------------------------ */

    /**
     * Get (or lazily create) the site's IndexNow key.
     *
     * @return string 32-char hex-ish key, or '' if it cannot be created.
     */
    public static function key() {
        $key = (string) get_option(self::KEY_OPTION, '');
        if ('' === $key) {
            $key = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', wp_generate_password(32, false, false)));
            if (strlen($key) < 8) {
                return '';
            }
            update_option(self::KEY_OPTION, $key, false);
        }
        return $key;
    }

    /**
     * Ping IndexNow when a post becomes (or is updated while) published.
     *
     * @param string  $new_status New status.
     * @param string  $old_status Old status.
     * @param WP_Post $post       Post object.
     */
    public function on_transition($new_status, $old_status, $post) {
        if (!self::indexnow_enabled() || !$post instanceof WP_Post) {
            return;
        }
        if ('publish' !== $new_status) {
            return;
        }
        if (!in_array($post->post_type, array('post', 'page'), true) && !is_post_type_viewable($post->post_type)) {
            return;
        }

        $url = get_permalink($post);
        if ($url) {
            $this->ping_indexnow($url);
        }
    }

    /**
     * Submit a single URL to IndexNow. Fire-and-forget, non-blocking.
     *
     * @param string $url Absolute URL that changed.
     */
    private function ping_indexnow($url) {
        $key = self::key();
        if ('' === $key) {
            return;
        }

        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        if ('' === $host) {
            return;
        }

        $payload = array(
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => home_url('/' . $key . '.txt'),
            'urlList'     => array($url),
        );

        wp_remote_post('https://api.indexnow.org/indexnow', array(
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => array('Content-Type' => 'application/json; charset=utf-8'),
            'body'     => wp_json_encode($payload),
        ));
    }
}
