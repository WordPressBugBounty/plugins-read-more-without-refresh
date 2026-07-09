<?php
/**
 * Content Locker: turn hidden content into a lead magnet.
 *
 * Two lock modes on any [read] instance:
 *  - lock="email": the visitor unlocks the content by leaving their email
 *    address (optionally with an explicit consent checkbox). The lead is
 *    stored locally and can be pushed to Mailchimp, Brevo, MailPoet or any
 *    webhook.
 *  - lock="share": the visitor unlocks by sharing the page on a social
 *    network (Facebook, X, LinkedIn).
 *
 * This is a "soft" lock: the content stays in the page HTML so search
 * engines index it, but it is not readable until the visitor unlocks. The
 * unlock state persists per browser via localStorage.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Locker {

    /** Unlock submissions allowed per IP per minute. */
    const RATE_LIMIT = 10;

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /* ---------------------------------------------------------------------
     * Frontend markup + config
     * ------------------------------------------------------------------ */

    /**
     * Texts and behavior for the frontend script.
     *
     * @return array
     */
    public static function get_frontend_config() {
        return array(
            'title'        => get_option('rmwr_locker_title', __('This content is locked', 'rmwr')),
            'message'      => get_option('rmwr_locker_message', __('Enter your email to unlock the full content.', 'rmwr')),
            'button'       => get_option('rmwr_locker_button', __('Unlock now', 'rmwr')),
            'placeholder'  => __('Your email address', 'rmwr'),
            'shareMessage' => __('Share this page to unlock the content.', 'rmwr'),
            'invalidEmail' => __('Please enter a valid email address.', 'rmwr'),
            'genericError' => __('Something went wrong. Please try again.', 'rmwr'),
        );
    }

    /**
     * Hidden <template> with the email unlock form for one instance.
     *
     * @param string $key     Instance key.
     * @param int    $post_id Post ID.
     * @return string
     */
    public static function render_email_form_template($key, $post_id) {
        $privacy = get_option('rmwr_locker_privacy_text', '');

        ob_start();
        ?>
        <template class="rmwr-lock-tpl">
            <div class="rmwr-lock-box" role="region" aria-label="<?php esc_attr_e('Locked content', 'rmwr'); ?>">
                <p class="rmwr-lock-title"><?php echo esc_html(get_option('rmwr_locker_title', __('This content is locked', 'rmwr'))); ?></p>
                <p class="rmwr-lock-message"><?php echo esc_html(get_option('rmwr_locker_message', __('Enter your email to unlock the full content.', 'rmwr'))); ?></p>
                <form class="rmwr-lock-form" data-key="<?php echo esc_attr($key); ?>" data-post="<?php echo esc_attr($post_id); ?>">
                    <input type="email" name="rmwr_email" class="rmwr-lock-email" required
                           placeholder="<?php esc_attr_e('Your email address', 'rmwr'); ?>"
                           aria-label="<?php esc_attr_e('Email address', 'rmwr'); ?>" />
                    <?php if ('' !== $privacy) : ?>
                        <label class="rmwr-lock-consent">
                            <input type="checkbox" name="rmwr_consent" value="1" required />
                            <span><?php echo wp_kses_post($privacy); ?></span>
                        </label>
                    <?php endif; ?>
                    <button type="submit" class="rmwr-lock-submit read-link">
                        <?php echo esc_html(get_option('rmwr_locker_button', __('Unlock now', 'rmwr'))); ?>
                    </button>
                    <p class="rmwr-lock-error" role="alert" hidden></p>
                </form>
            </div>
        </template>
        <?php
        return ob_get_clean();
    }

    /**
     * Hidden <template> with the share-to-unlock buttons for one instance.
     *
     * @param string $key     Instance key.
     * @param int    $post_id Post ID.
     * @return string
     */
    public static function render_share_template($key, $post_id) {
        ob_start();
        ?>
        <template class="rmwr-lock-tpl">
            <div class="rmwr-lock-box" role="region" aria-label="<?php esc_attr_e('Locked content', 'rmwr'); ?>">
                <p class="rmwr-lock-title"><?php echo esc_html(get_option('rmwr_locker_title', __('This content is locked', 'rmwr'))); ?></p>
                <p class="rmwr-lock-message"><?php esc_html_e('Share this page to unlock the content.', 'rmwr'); ?></p>
                <div class="rmwr-share-buttons" data-key="<?php echo esc_attr($key); ?>" data-post="<?php echo esc_attr($post_id); ?>">
                    <button type="button" class="rmwr-share-btn" data-network="facebook">Facebook</button>
                    <button type="button" class="rmwr-share-btn" data-network="x">X</button>
                    <button type="button" class="rmwr-share-btn" data-network="linkedin">LinkedIn</button>
                </div>
            </div>
        </template>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------------
     * REST: unlock endpoint
     * ------------------------------------------------------------------ */

    public function register_routes() {
        register_rest_route('rmwr/v1', '/unlock', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_unlock'),
            'permission_callback' => '__return_true', // Public form; validated + rate limited below.
            'args'                => array(
                'email'   => array('type' => 'string', 'required' => true),
                'k'       => array('type' => 'string', 'default' => ''),
                'p'       => array('type' => 'integer', 'default' => 0),
                'consent' => array('type' => 'boolean', 'default' => false),
            ),
        ));
    }

    /**
     * Validate, store and sync one captured lead.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle_unlock($request) {
        if (!$this->within_rate_limit()) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'rate_limited'), 429);
        }

        $email = sanitize_email((string) $request['email']);
        if (!is_email($email)) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'invalid_email'), 400);
        }

        $key     = substr(sanitize_text_field((string) $request['k']), 0, 191);
        $post_id = absint($request['p']);
        $consent = (bool) $request['consent'];

        $provider = get_option('rmwr_locker_provider', 'store');
        $synced   = '';

        if ('store' !== $provider && '' !== $provider) {
            $synced = $this->sync_to_provider($provider, $email) ? $provider : '';
        }

        RMWR_Analytics::store_lead($email, $key, $post_id, $consent, $synced);

        /**
         * Fires after a lead was captured by the content locker.
         *
         * @param string $email   Lead email.
         * @param string $key     Instance key.
         * @param int    $post_id Post ID.
         */
        do_action('rmwr_lead_captured', $email, $key, $post_id);

        return new WP_REST_Response(array('ok' => true), 200);
    }

    /* ---------------------------------------------------------------------
     * Provider integrations
     * ------------------------------------------------------------------ */

    /**
     * Push the lead to the configured email marketing provider.
     *
     * @param string $provider mailchimp|brevo|mailpoet|webhook.
     * @param string $email    Lead email.
     * @return bool Whether the sync succeeded.
     */
    private function sync_to_provider($provider, $email) {
        switch ($provider) {
            case 'mailchimp':
                return $this->sync_mailchimp($email);
            case 'brevo':
                return $this->sync_brevo($email);
            case 'mailpoet':
                return $this->sync_mailpoet($email);
            case 'webhook':
                return $this->sync_webhook($email);
        }
        return false;
    }

    private function sync_mailchimp($email) {
        $api_key = trim((string) get_option('rmwr_locker_mailchimp_key', ''));
        $list_id = trim((string) get_option('rmwr_locker_mailchimp_list', ''));
        if ('' === $api_key || '' === $list_id || false === strpos($api_key, '-')) {
            return false;
        }

        $dc  = substr($api_key, strrpos($api_key, '-') + 1);
        $url = 'https://' . $dc . '.api.mailchimp.com/3.0/lists/' . rawurlencode($list_id) . '/members/' . md5(strtolower($email));

        $response = wp_remote_request($url, array(
            'method'  => 'PUT',
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode('rmwr:' . $api_key),
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode(array(
                'email_address' => $email,
                'status_if_new' => 'subscribed',
            )),
        ));

        return $this->remote_ok($response, 'mailchimp');
    }

    private function sync_brevo($email) {
        $api_key = trim((string) get_option('rmwr_locker_brevo_key', ''));
        $list_id = absint(get_option('rmwr_locker_brevo_list', 0));
        if ('' === $api_key) {
            return false;
        }

        $body = array('email' => $email, 'updateEnabled' => true);
        if ($list_id > 0) {
            $body['listIds'] = array($list_id);
        }

        $response = wp_remote_post('https://api.brevo.com/v3/contacts', array(
            'timeout' => 15,
            'headers' => array(
                'api-key'      => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode($body),
        ));

        return $this->remote_ok($response, 'brevo');
    }

    private function sync_mailpoet($email) {
        if (!class_exists('\MailPoet\API\API')) {
            return false;
        }

        try {
            $mailpoet = \MailPoet\API\API::MP('v1');
            $list_id  = absint(get_option('rmwr_locker_mailpoet_list', 0));
            $lists    = $list_id > 0 ? array($list_id) : array();
            $mailpoet->addSubscriber(array('email' => $email), $lists);
            return true;
        } catch (\Exception $exception) {
            // Already-subscribed errors are fine; everything else is logged.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('RMWR MailPoet sync: ' . $exception->getMessage());
            }
            return false !== stripos($exception->getMessage(), 'already');
        }
    }

    private function sync_webhook($email) {
        $url = esc_url_raw((string) get_option('rmwr_locker_webhook_url', ''));
        if ('' === $url) {
            return false;
        }

        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'email' => $email,
                'site'  => home_url(),
                'time'  => gmdate('c'),
            )),
        ));

        return $this->remote_ok($response, 'webhook');
    }

    /**
     * @param array|WP_Error $response wp_remote_* response.
     * @param string         $provider Provider slug for logging.
     * @return bool
     */
    private function remote_ok($response, $provider) {
        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('RMWR ' . $provider . ' sync failed: ' . $response->get_error_message());
            }
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('RMWR ' . $provider . ' sync HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
            }
            return false;
        }

        return true;
    }

    private function within_rate_limit() {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $key = 'rmwr_ul_' . md5($ip);

        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            return false;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);

        return true;
    }
}
