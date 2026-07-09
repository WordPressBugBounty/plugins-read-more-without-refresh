<?php
/**
 * AI Summaries (TL;DR mode).
 *
 * Generates a 2-3 sentence summary of a post with the site owner's own AI
 * API key (OpenAI, Anthropic Claude or Google Gemini), caches it in post
 * meta and shows it in a styled "TL;DR" box above a collapsed Read More
 * instance ([read ai_summary="true"]).
 *
 * Generation happens once per post (on demand from the editor meta box, or
 * automatically on publish when enabled) - visitors NEVER trigger API calls,
 * so there is zero per-pageview cost and no frontend latency.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_AI_Summary {

    const META_KEY = '_rmwr_ai_summary';

    /** Max characters of post content sent to the model. */
    const INPUT_LIMIT = 12000;

    public function __construct() {
        add_action('add_meta_boxes', array($this, 'register_meta_box'));
        add_action('save_post', array($this, 'save_meta_box'), 10, 1);
        add_action('wp_ajax_rmwr_generate_summary', array($this, 'ajax_generate'));
        add_action('transition_post_status', array($this, 'maybe_auto_generate'), 10, 3);
        add_action('rmwr_ai_generate_event', array($this, 'generate_event'));
    }

    /* ---------------------------------------------------------------------
     * Frontend
     * ------------------------------------------------------------------ */

    /**
     * Get the cached summary for a post.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    public static function get_summary($post_id) {
        return trim((string) get_post_meta((int) $post_id, self::META_KEY, true));
    }

    /**
     * Styled TL;DR box (empty string when no summary exists).
     *
     * @param int $post_id Post ID.
     * @return string
     */
    public static function render_summary_box($post_id) {
        $summary = self::get_summary($post_id);
        if ('' === $summary) {
            return '';
        }

        $label = get_option('rmwr_ai_label', __('TL;DR', 'rmwr'));

        return sprintf(
            '<div class="rmwr-ai-summary"><span class="rmwr-ai-label">%s</span><p class="rmwr-ai-text">%s</p></div>',
            esc_html($label),
            esc_html($summary)
        );
    }

    /* ---------------------------------------------------------------------
     * Editor meta box
     * ------------------------------------------------------------------ */

    public function register_meta_box() {
        if ('' === trim((string) get_option('rmwr_ai_api_key', ''))) {
            return; // Feature not configured.
        }

        $types = get_post_types(array('public' => true));
        unset($types['attachment']);

        add_meta_box(
            'rmwr-ai-summary',
            __('AI Summary (Read More Pro)', 'rmwr'),
            array($this, 'render_meta_box'),
            array_values($types),
            'normal',
            'default'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('rmwr_ai_meta', 'rmwr_ai_nonce');
        $summary = self::get_summary($post->ID);
        $ajax_nonce = wp_create_nonce('rmwr_generate_summary');
        ?>
        <p class="description">
            <?php esc_html_e('Shown as a TL;DR box by [read ai_summary="true"]. Generate it with your configured AI provider, or write/edit it manually.', 'rmwr'); ?>
        </p>
        <textarea name="rmwr_ai_summary" id="rmwr-ai-summary-field" rows="3" style="width:100%;"><?php echo esc_textarea($summary); ?></textarea>
        <p>
            <button type="button" class="button" id="rmwr-ai-generate" data-post="<?php echo esc_attr($post->ID); ?>">
                <?php esc_html_e('Generate with AI', 'rmwr'); ?>
            </button>
            <span id="rmwr-ai-status" style="margin-left:8px;"></span>
        </p>
        <script>
        (function() {
            var btn = document.getElementById('rmwr-ai-generate');
            if (!btn) { return; }
            btn.addEventListener('click', function() {
                var status = document.getElementById('rmwr-ai-status');
                var field = document.getElementById('rmwr-ai-summary-field');
                btn.disabled = true;
                status.textContent = '<?php echo esc_js(__('Generating...', 'rmwr')); ?>';
                var params = new URLSearchParams();
                params.append('action', 'rmwr_generate_summary');
                params.append('post_id', btn.getAttribute('data-post'));
                params.append('nonce', '<?php echo esc_js($ajax_nonce); ?>');
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: params.toString() })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    if (data && data.success) {
                        field.value = data.data.summary;
                        status.textContent = '<?php echo esc_js(__('Done. Remember to save the post.', 'rmwr')); ?>';
                    } else {
                        status.textContent = (data && data.data && data.data.message) ? data.data.message : '<?php echo esc_js(__('Generation failed.', 'rmwr')); ?>';
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    status.textContent = '<?php echo esc_js(__('Generation failed.', 'rmwr')); ?>';
                });
            });
        })();
        </script>
        <?php
    }

    public function save_meta_box($post_id) {
        if (!isset($_POST['rmwr_ai_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rmwr_ai_nonce'])), 'rmwr_ai_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['rmwr_ai_summary'])) {
            $summary = sanitize_textarea_field(wp_unslash($_POST['rmwr_ai_summary']));
            if ('' === trim($summary)) {
                delete_post_meta($post_id, self::META_KEY);
            } else {
                update_post_meta($post_id, self::META_KEY, $summary);
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Generation
     * ------------------------------------------------------------------ */

    public function ajax_generate() {
        check_ajax_referer('rmwr_generate_summary', 'nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Permission denied.', 'rmwr')));
        }

        $summary = $this->generate_for_post($post_id);
        if (is_wp_error($summary)) {
            wp_send_json_error(array('message' => $summary->get_error_message()));
        }

        update_post_meta($post_id, self::META_KEY, $summary);
        wp_send_json_success(array('summary' => $summary));
    }

    /**
     * Auto-generate on first publish (async, so saving stays fast).
     */
    public function maybe_auto_generate($new_status, $old_status, $post) {
        if ('publish' !== $new_status || 'publish' === $old_status) {
            return;
        }
        if ('1' !== get_option('rmwr_ai_auto', '0') || '' !== self::get_summary($post->ID)) {
            return;
        }
        if (!wp_next_scheduled('rmwr_ai_generate_event', array($post->ID))) {
            wp_schedule_single_event(time() + 15, 'rmwr_ai_generate_event', array($post->ID));
        }
    }

    public function generate_event($post_id) {
        $summary = $this->generate_for_post((int) $post_id);
        if (!is_wp_error($summary) && '' !== $summary) {
            update_post_meta((int) $post_id, self::META_KEY, $summary);
        }
    }

    /**
     * Call the configured provider and return a plain-text summary.
     *
     * @param int $post_id Post ID.
     * @return string|WP_Error
     */
    public function generate_for_post($post_id) {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return new WP_Error('rmwr_ai', __('Post not found.', 'rmwr'));
        }

        $api_key = trim((string) get_option('rmwr_ai_api_key', ''));
        if ('' === $api_key) {
            return new WP_Error('rmwr_ai', __('No AI API key configured in RMWR Settings.', 'rmwr'));
        }

        $text = trim(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)));
        if ('' === $text) {
            return new WP_Error('rmwr_ai', __('The post has no text content to summarize.', 'rmwr'));
        }
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, self::INPUT_LIMIT);
        } else {
            $text = substr($text, 0, self::INPUT_LIMIT);
        }

        $prompt = "Summarize the following article in 2-3 short sentences for a TL;DR box. "
            . "Write in the SAME LANGUAGE as the article. Return only the summary text, no headings, no quotes.\n\n"
            . 'Title: ' . $post->post_title . "\n\n" . $text;

        $provider = get_option('rmwr_ai_provider', 'openai');

        switch ($provider) {
            case 'anthropic':
                $summary = $this->call_anthropic($api_key, $prompt);
                break;
            case 'gemini':
                $summary = $this->call_gemini($api_key, $prompt);
                break;
            case 'openai':
            default:
                $summary = $this->call_openai($api_key, $prompt);
                break;
        }

        if (is_wp_error($summary)) {
            return $summary;
        }

        $summary = sanitize_textarea_field(trim($summary));
        if ('' === $summary) {
            return new WP_Error('rmwr_ai', __('The AI provider returned an empty summary.', 'rmwr'));
        }

        return $summary;
    }

    private function call_openai($api_key, $prompt) {
        $model = get_option('rmwr_ai_model', '') ?: 'gpt-4o-mini';

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode(array(
                'model'      => $model,
                'max_tokens' => 300,
                'messages'   => array(
                    array('role' => 'user', 'content' => $prompt),
                ),
            )),
        ));

        $data = $this->parse_response($response, 'OpenAI');
        if (is_wp_error($data)) {
            return $data;
        }

        return isset($data['choices'][0]['message']['content'])
            ? (string) $data['choices'][0]['message']['content']
            : new WP_Error('rmwr_ai', __('Unexpected OpenAI response format.', 'rmwr'));
    }

    private function call_anthropic($api_key, $prompt) {
        $model = get_option('rmwr_ai_model', '') ?: 'claude-haiku-4-5';

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 45,
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ),
            'body'    => wp_json_encode(array(
                'model'      => $model,
                'max_tokens' => 300,
                'messages'   => array(
                    array('role' => 'user', 'content' => $prompt),
                ),
            )),
        ));

        $data = $this->parse_response($response, 'Anthropic');
        if (is_wp_error($data)) {
            return $data;
        }

        return isset($data['content'][0]['text'])
            ? (string) $data['content'][0]['text']
            : new WP_Error('rmwr_ai', __('Unexpected Anthropic response format.', 'rmwr'));
    }

    private function call_gemini($api_key, $prompt) {
        $model = get_option('rmwr_ai_model', '') ?: 'gemini-2.0-flash';
        $url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($api_key);

        $response = wp_remote_post($url, array(
            'timeout' => 45,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'contents' => array(
                    array('parts' => array(array('text' => $prompt))),
                ),
            )),
        ));

        $data = $this->parse_response($response, 'Gemini');
        if (is_wp_error($data)) {
            return $data;
        }

        return isset($data['candidates'][0]['content']['parts'][0]['text'])
            ? (string) $data['candidates'][0]['content']['parts'][0]['text']
            : new WP_Error('rmwr_ai', __('Unexpected Gemini response format.', 'rmwr'));
    }

    /**
     * Shared response handling.
     *
     * @param array|WP_Error $response wp_remote_post result.
     * @param string         $provider Provider label for error messages.
     * @return array|WP_Error Decoded JSON.
     */
    private function parse_response($response, $provider) {
        if (is_wp_error($response)) {
            return new WP_Error('rmwr_ai', sprintf(
                /* translators: 1: provider, 2: error message */
                __('%1$s request failed: %2$s', 'rmwr'),
                $provider,
                $response->get_error_message()
            ));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $detail = '';
            if (is_array($data)) {
                $detail = isset($data['error']['message']) ? $data['error']['message'] : wp_json_encode($data);
            }
            return new WP_Error('rmwr_ai', sprintf(
                /* translators: 1: provider, 2: HTTP status, 3: error detail */
                __('%1$s returned HTTP %2$d: %3$s', 'rmwr'),
                $provider,
                $code,
                $detail
            ));
        }

        return is_array($data) ? $data : new WP_Error('rmwr_ai', __('Invalid JSON from provider.', 'rmwr'));
    }
}
