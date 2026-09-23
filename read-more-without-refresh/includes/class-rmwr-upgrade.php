<?php
/**
 * Custom "Get Pro" upgrade page.
 *
 * Replaces reliance on the default Freemius pricing widget's look with our own
 * branded pricing table. Each CTA opens the Freemius checkout for the matching
 * plan via the SDK's own checkout_url(), so payment AND license activation are
 * handled by Freemius exactly as its native pricing page: on return, the SDK
 * syncs the license, rmwr_is_premium() flips to true, and every Pro module
 * unlocks automatically. We only change the presentation, never the licensing.
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Upgrade {

    /**
     * Freemius plan + pricing ids per tier. Verify against your Freemius setup
     * with tools/freemius `node freemius-plans.mjs list`; overridable via the
     * 'rmwr_upgrade_plan_map' filter if you restructure the plans.
     */
    private static function plan_map() {
        return apply_filters('rmwr_upgrade_plan_map', array(
            // One plan (55901); the pricing_id selects the license quantity
            // (1 / 5 / 25 sites) and billing_cycle selects annual vs lifetime.
            'single'     => array('plan' => 55901, 'pricing' => 73622, 'name' => 'Single Site', 'tag' => 'For one project you care about',       'sites' => 'Installation in 1 website',   'yearly' => '$1.99', 'lifetime' => '$99'),
            'five'       => array('plan' => 55901, 'pricing' => 90650, 'name' => '5 Sites',     'tag' => 'The sweet spot for freelancers & studios', 'sites' => 'Installation in 5 websites', 'yearly' => '$4.99', 'lifetime' => '$149'),
            'twentyfive' => array('plan' => 55901, 'pricing' => 90652, 'name' => '25 Sites',    'tag' => 'For agencies running at scale',        'sites' => 'Installation in 25 websites', 'yearly' => '$9.99', 'lifetime' => '$199'),
        ));
    }

    /** Shared Pro feature list (all tiers). */
    private static function features() {
        return array(
            'AI Answer Optimizer (llms.txt + IndexNow)',
            'Engagement events to GA4 / Meta / Google Ads',
            'Content Locker + lead capture',
            'Teaser Paywall + AI Summaries',
            'Sitewide Auto-Apply (+ WooCommerce)',
            'Full analytics + engagement heatmap + CSV',
            'A/B testing + reusable content blocks',
            'Elementor + Gutenberg InnerBlocks',
            'Priority support',
        );
    }

    public function __construct() {
        add_action('admin_menu', array($this, 'register_menu'), 30);
        // Redirect the default Freemius pricing page to our branded page.
        add_action('admin_init', array($this, 'redirect_pricing'));
        // Hide the page's own menu link (kept accessible - see method).
        add_action('admin_head', array($this, 'hide_menu_link'));
    }

    public function register_menu() {
        // Only register the upsell page while unlicensed.
        if (function_exists('rmwr_is_premium') && rmwr_is_premium()) {
            return;
        }
        add_submenu_page(
            'read_more_without_refresh',
            __('Get Pro', 'rmwr'),
            __('Get Pro', 'rmwr'),
            'manage_options',
            'rmwr-get-pro',
            array($this, 'render')
        );
    }

    /**
     * Hide the "Get Pro" submenu link without unregistering the page.
     *
     * Removed on admin_head - AFTER WordPress runs the page-access permission
     * check in admin.php (which needs the submenu entry to resolve the page
     * hook) but BEFORE the sidebar is rendered in menu-header.php. Removing it
     * earlier (during admin_menu) breaks get_admin_page_parent() and triggers
     * "Sorry, you are not allowed to access this page". So the page stays
     * reachable by URL/redirect while its own menu link never appears - the
     * Freemius "Upgrade" item redirects here, giving one upgrade entry point.
     */
    public function hide_menu_link() {
        remove_submenu_page('read_more_without_refresh', 'rmwr-get-pro');
    }

    /**
     * Send the default Freemius pricing page to our branded page.
     *
     * Only plain, unlicensed visits are redirected - including the Freemius
     * "Start Trial" menu link, which the SDK builds as just &trial=true. Real
     * checkout requests always carry checkout, plan_id and pricing_id, so those
     * stay on the Freemius pricing page and its checkout runs untouched - that
     * flow is what activates the license on return.
     */
    public function redirect_pricing() {
        if (empty($_GET['page'])) {
            return;
        }
        $page = sanitize_key(wp_unslash($_GET['page']));
        if ('read_more_without_refresh-pricing' !== $page) {
            return;
        }
        foreach (array('checkout', 'plan_id', 'pricing_id') as $checkout_param) {
            if (isset($_GET[$checkout_param])) {
                return;
            }
        }
        if (function_exists('rmwr_is_premium') && rmwr_is_premium()) {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=rmwr-get-pro'));
        exit;
    }

    /**
     * Build the checkout URLs per tier + billing cycle using the Freemius SDK.
     * checkout_url() returns the pricing page in checkout mode for that plan;
     * Freemius opens the checkout, takes payment, then redirects back and the
     * SDK activates the license on this install.
     *
     * @return array
     */
    private static function checkout_urls() {
        $fs  = function_exists('rmwr_fs') ? rmwr_fs() : null;
        $map = array();
        foreach (self::plan_map() as $key => $tier) {
            $map[$key] = array('yearly' => '', 'lifetime' => '');
            if ($fs && method_exists($fs, 'checkout_url')) {
                $extra = array('plan_id' => $tier['plan'], 'pricing_id' => $tier['pricing']);
                $map[$key]['yearly']   = $fs->checkout_url('annual', true, $extra);
                $map[$key]['lifetime'] = $fs->checkout_url('lifetime', false, $extra);
            }
        }
        return $map;
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'rmwr'));
        }

        $tiers    = self::plan_map();
        $urls     = self::checkout_urls();
        $features = self::features();
        $order    = array('single', 'five', 'twentyfive');

        echo self::styles(); // phpcs:ignore WordPress.Security.EscapeOutput
        ?>
        <div class="rmwr-up" data-billing="yearly">
            <header class="rmwr-up-head">
                <span class="rmwr-up-eyebrow"><span class="rmwr-up-spark"></span><?php esc_html_e('Read More Without Refresh - Pro', 'rmwr'); ?></span>
                <h1><?php esc_html_e('Unlock the', 'rmwr'); ?> <span class="rmwr-up-grad"><?php esc_html_e('full toolkit', 'rmwr'); ?></span> <?php esc_html_e('for every site you run', 'rmwr'); ?></h1>
                <p class="rmwr-up-sub"><?php esc_html_e('One upgrade, every Pro feature. Choose the plan that matches how many sites you manage.', 'rmwr'); ?></p>
                <div class="rmwr-up-toggle-row">
                    <div class="rmwr-up-seg" role="group" aria-label="<?php esc_attr_e('Billing period', 'rmwr'); ?>">
                        <span class="rmwr-up-seg-thumb" aria-hidden="true"></span>
                        <button type="button" data-mode="yearly" class="is-active" aria-pressed="true"><?php esc_html_e('Yearly', 'rmwr'); ?></button>
                        <button type="button" data-mode="lifetime" aria-pressed="false"><?php esc_html_e('Lifetime', 'rmwr'); ?></button>
                    </div>
                </div>
            </header>

            <section class="rmwr-up-grid">
                <?php foreach ($order as $key) :
                    $tier = $tiers[$key];
                    $feat = 'five' === $key ? ' feat' : '';
                    ?>
                    <article class="rmwr-up-card<?php echo esc_attr($feat); ?>">
                        <?php if ('five' === $key) : ?><span class="rmwr-up-badge"><?php esc_html_e('Most popular', 'rmwr'); ?></span><?php endif; ?>
                        <h2 class="rmwr-up-plan-name"><?php echo esc_html($tier['name']); ?></h2>
                        <p class="rmwr-up-plan-tag"><?php echo esc_html($tier['tag']); ?></p>

                        <div class="rmwr-up-price-block">
                            <div class="rmwr-up-price">
                                <span class="rmwr-up-amount" data-yearly="<?php echo esc_attr($tier['yearly']); ?>" data-lifetime="<?php echo esc_attr($tier['lifetime']); ?>"><?php echo esc_html($tier['yearly']); ?></span>
                                <span class="rmwr-up-unit">/ <?php esc_html_e('mo', 'rmwr'); ?></span>
                            </div>
                            <div class="rmwr-up-billed" data-yearly="<?php esc_attr_e('Billed annually', 'rmwr'); ?>" data-lifetime="<?php esc_attr_e('Billed once', 'rmwr'); ?>"><?php esc_html_e('Billed annually', 'rmwr'); ?></div>
                            <div class="rmwr-up-reassure" data-yearly="<?php esc_attr_e('Renews yearly, cancel anytime', 'rmwr'); ?>" data-lifetime="<?php esc_attr_e('One-time payment, yours forever', 'rmwr'); ?>"><?php esc_html_e('Renews yearly, cancel anytime', 'rmwr'); ?></div>
                        </div>

                        <a class="rmwr-up-cta" data-plan="<?php echo esc_attr($key); ?>" href="<?php echo esc_url($urls[$key]['yearly']); ?>" data-yearly-url="<?php echo esc_url($urls[$key]['yearly']); ?>" data-lifetime-url="<?php echo esc_url($urls[$key]['lifetime']); ?>"><?php esc_html_e('Start free trial', 'rmwr'); ?></a>
                        <p class="rmwr-up-trial"><?php esc_html_e('3-day free trial - no charge today', 'rmwr'); ?></p>

                        <ul class="rmwr-up-features">
                            <li class="lead"><span class="rmwr-up-check"><svg viewBox="0 0 12 12" aria-hidden="true"><path d="M2 6.2 4.7 9 10 3"/></svg></span><?php echo esc_html($tier['sites']); ?></li>
                            <?php foreach ($features as $f) : ?>
                                <li><span class="rmwr-up-check"><svg viewBox="0 0 12 12" aria-hidden="true"><path d="M2 6.2 4.7 9 10 3"/></svg></span><?php echo esc_html($f); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="rmwr-up-allin"><span class="rmwr-up-dot"></span><?php esc_html_e('All 20 Pro tools included', 'rmwr'); ?></p>
                    </article>
                <?php endforeach; ?>
            </section>

            <footer class="rmwr-up-trust">
                <div class="rmwr-up-trust-lines">
                    <span><span class="rmwr-up-ic" aria-hidden="true">&#9679;</span><?php esc_html_e('Secure payments by Freemius', 'rmwr'); ?></span>
                    <span class="rmwr-up-trust-dot" aria-hidden="true"></span>
                    <span><span class="rmwr-up-ic" aria-hidden="true">&#9679;</span><?php esc_html_e('14-day money-back guarantee', 'rmwr'); ?></span>
                </div>
                <div class="rmwr-up-pays">
                    <span class="rmwr-up-pay visa"><span class="mk"></span>VISA</span>
                    <span class="rmwr-up-pay mc"><span class="mk"></span>Mastercard</span>
                    <span class="rmwr-up-pay amex"><span class="mk"></span>Amex</span>
                    <span class="rmwr-up-pay pp"><span class="mk"></span>PayPal</span>
                    <span class="rmwr-up-pay mcafee"><span class="mk"></span>McAfee Secure</span>
                    <span class="rmwr-up-pay cf"><span class="mk"></span>Cloudflare</span>
                </div>
            </footer>
        </div>
        <script>
        (function () {
            var root = document.querySelector('.rmwr-up');
            if (!root) { return; }
            var buttons = root.querySelectorAll('.rmwr-up-seg button[data-mode]');
            var swaps = root.querySelectorAll('[data-yearly][data-lifetime]');
            var ctas = root.querySelectorAll('.rmwr-up-cta[data-plan]');

            function applyMode(mode) {
                if (mode !== 'yearly' && mode !== 'lifetime') { mode = 'yearly'; }
                root.setAttribute('data-billing', mode);
                for (var i = 0; i < swaps.length; i++) {
                    var next = swaps[i].getAttribute('data-' + mode);
                    if (next !== null) { swaps[i].textContent = next; }
                }
                for (var j = 0; j < buttons.length; j++) {
                    var on = buttons[j].getAttribute('data-mode') === mode;
                    buttons[j].classList.toggle('is-active', on);
                    buttons[j].setAttribute('aria-pressed', on ? 'true' : 'false');
                }
                // Point each CTA at the checkout URL for the selected billing cycle.
                for (var c = 0; c < ctas.length; c++) {
                    var url = ctas[c].getAttribute('data-' + mode + '-url');
                    if (url) { ctas[c].setAttribute('href', url); }
                }
            }
            for (var k = 0; k < buttons.length; k++) {
                buttons[k].addEventListener('click', function () { applyMode(this.getAttribute('data-mode')); });
            }
            applyMode('yearly');
        })();
        </script>
        <?php
    }

    private static function styles() {
        return <<<'CSS'
<style>
.rmwr-up{
  --accent:#7c3aed;--accent-dark:#6d28d9;--accent-tint:rgba(124,58,237,.10);
  --ink:#211c33;--ink-soft:#4a4460;--muted:#7b7492;--line:#ece7f6;--card:#fff;
  --dark-1:#171128;--dark-2:#241738;--dark-ink:#f6f3fe;--dark-soft:#cfc6ea;--dark-muted:#a79cca;--dark-line:rgba(255,255,255,.12);
  --font-display:"Bricolage Grotesque",ui-serif,Georgia,serif;--font-body:"Hanken Grotesk","Segoe UI",Tahoma,sans-serif;
  --radius:26px;--radius-sm:14px;
  font-family:var(--font-body);color:var(--ink);line-height:1.6;letter-spacing:.012em;word-spacing:.045em;
  margin:-10px -20px -10px -22px;padding:clamp(30px,5vw,70px) 0 clamp(46px,6vw,80px);
  background:radial-gradient(120% 90% at 12% -8%,rgba(124,58,237,.10),transparent 46%),radial-gradient(120% 90% at 92% 4%,rgba(124,58,237,.08),transparent 44%),linear-gradient(178deg,#f8f6fe 0%,#f5f2fc 40%,#efe9fb 100%);
  min-height:calc(100vh - 32px);-webkit-font-smoothing:antialiased;
}
.rmwr-up *{box-sizing:border-box}
.rmwr-up-head{text-align:center;max-width:660px;margin:0 auto clamp(28px,4vw,44px)}
.rmwr-up-eyebrow{display:inline-flex;align-items:center;gap:9px;font-size:.74rem;font-weight:600;letter-spacing:.18em;word-spacing:.1em;text-transform:uppercase;color:var(--accent-dark);background:var(--accent-tint);padding:8px 16px;border-radius:999px;border:1px solid rgba(124,58,237,.16)}
.rmwr-up-spark{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 4px var(--accent-tint)}
.rmwr-up h1{font-family:var(--font-display);font-weight:700;font-size:clamp(2rem,4.6vw,3.15rem);line-height:1.06;letter-spacing:-.008em;margin:22px 0 0;color:var(--ink)}
.rmwr-up-grad{background:linear-gradient(96deg,var(--accent) 8%,#a06bf5 92%);-webkit-background-clip:text;background-clip:text;color:transparent}
.rmwr-up-sub{margin:16px auto 0;max-width:520px;font-size:1.04rem;color:var(--ink-soft);word-spacing:.06em}
.rmwr-up-toggle-row{display:flex;justify-content:center;margin:clamp(24px,3.2vw,34px) 0 4px}
.rmwr-up-seg{position:relative;display:inline-flex;background:#fff;border:1px solid var(--line);border-radius:999px;padding:6px;box-shadow:0 2px 8px rgba(37,24,67,.05);gap:2px}
.rmwr-up-seg-thumb{position:absolute;top:6px;left:6px;bottom:6px;width:calc(50% - 6px);border-radius:999px;background:linear-gradient(180deg,var(--accent),var(--accent-dark));box-shadow:0 8px 18px -8px rgba(124,58,237,.7);transition:transform .34s cubic-bezier(.65,.05,.2,1);z-index:0}
.rmwr-up[data-billing="lifetime"] .rmwr-up-seg-thumb{transform:translateX(100%)}
.rmwr-up-seg button{position:relative;z-index:1;appearance:none;border:0;background:transparent;cursor:pointer;font-family:var(--font-body);font-size:.95rem;font-weight:600;letter-spacing:.05em;color:var(--ink-soft);padding:11px 30px;border-radius:999px;transition:color .28s ease;min-width:118px}
.rmwr-up-seg button.is-active{color:#fff}
.rmwr-up-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:clamp(18px,2.2vw,30px);align-items:start;width:70%;max-width:1240px;margin:clamp(32px,4vw,50px) auto 0}
.rmwr-up-card{position:relative;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:34px 30px 32px;box-shadow:0 18px 44px -22px rgba(46,28,86,.34),0 4px 14px -8px rgba(46,28,86,.16);display:flex;flex-direction:column;opacity:0;transform:translateY(24px);animation:rmwrUpRise .7s cubic-bezier(.2,.7,.2,1) forwards;transition:transform .3s ease,box-shadow .3s ease}
.rmwr-up-card:nth-child(1){animation-delay:.08s}
.rmwr-up-card:nth-child(2){animation-delay:.2s}
.rmwr-up-card:nth-child(3){animation-delay:.32s}
.rmwr-up-card:not(.feat):hover{transform:translateY(-6px);box-shadow:0 26px 56px -26px rgba(46,28,86,.4)}
@keyframes rmwrUpRise{to{opacity:1;transform:translateY(0)}}
.rmwr-up-card.feat{background:radial-gradient(120% 80% at 50% -6%,rgba(124,58,237,.42),transparent 58%),linear-gradient(170deg,var(--dark-2),var(--dark-1));border:1px solid rgba(124,58,237,.32);color:var(--dark-ink);box-shadow:0 40px 80px -34px rgba(58,26,120,.62),0 0 0 1px rgba(124,58,237,.28),0 0 60px -12px rgba(124,58,237,.45);transform:translateY(-16px);padding-top:40px;z-index:2}
.rmwr-up-card.feat:hover{transform:translateY(-24px)}
.rmwr-up-badge{position:absolute;top:-13px;left:50%;transform:translateX(-50%);font-size:.7rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#fff;background:linear-gradient(180deg,var(--accent),var(--accent-dark));padding:7px 16px;border-radius:999px;white-space:nowrap;box-shadow:0 10px 22px -10px rgba(124,58,237,.8)}
.rmwr-up-plan-name{font-family:var(--font-display);font-weight:600;font-size:1.32rem;margin:0;color:var(--ink)}
.rmwr-up-card.feat .rmwr-up-plan-name{color:#fff}
.rmwr-up-plan-tag{margin:6px 0 0;font-size:.9rem;color:var(--muted);word-spacing:.06em}
.rmwr-up-card.feat .rmwr-up-plan-tag{color:var(--dark-muted)}
.rmwr-up-price-block{margin:30px 0 34px;padding:30px 0 34px;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
.rmwr-up-card.feat .rmwr-up-price-block{border-color:var(--dark-line)}
.rmwr-up-price{display:flex;align-items:baseline;gap:9px;line-height:1}
.rmwr-up-amount{font-family:var(--font-display);font-weight:700;font-size:3.3rem;letter-spacing:-.01em;color:var(--ink)}
.rmwr-up-card.feat .rmwr-up-amount{color:#fff}
.rmwr-up-unit{font-size:1.02rem;font-weight:600;color:var(--muted)}
.rmwr-up-card.feat .rmwr-up-unit{color:var(--dark-soft)}
.rmwr-up[data-billing="lifetime"] .rmwr-up-unit{display:none}
.rmwr-up-billed{margin-top:20px;font-size:1rem;font-weight:600;color:var(--ink-soft)}
.rmwr-up-card.feat .rmwr-up-billed{color:var(--dark-soft)}
.rmwr-up-reassure{margin-top:12px;font-size:.85rem;color:var(--muted);word-spacing:.07em}
.rmwr-up-card.feat .rmwr-up-reassure{color:var(--dark-muted)}
.rmwr-up-cta{display:block;text-align:center;text-decoration:none;cursor:pointer;width:100%;font-family:var(--font-body);font-size:1.02rem;font-weight:700;letter-spacing:.06em;word-spacing:.12em;color:#fff;background:linear-gradient(180deg,var(--accent),var(--accent-dark));border:1px solid rgba(255,255,255,.14);border-radius:var(--radius-sm);padding:16px 20px;box-shadow:0 14px 28px -14px rgba(124,58,237,.72);transition:transform .2s ease,box-shadow .2s ease,filter .2s ease}
.rmwr-up-cta:hover{transform:translateY(-2px);box-shadow:0 20px 36px -14px rgba(124,58,237,.85);filter:brightness(1.04);color:#fff}
.rmwr-up-card.feat .rmwr-up-cta{background:linear-gradient(180deg,#fff,#efe9ff);color:var(--accent-dark);border:1px solid rgba(255,255,255,.6);box-shadow:0 16px 34px -14px rgba(0,0,0,.55)}
.rmwr-up-card.feat .rmwr-up-cta:hover{color:var(--accent-dark)}
.rmwr-up-trial{margin:14px 0 0;text-align:center;font-size:.8rem;color:var(--muted)}
.rmwr-up-card.feat .rmwr-up-trial{color:var(--dark-muted)}
.rmwr-up-features{list-style:none;margin:30px 0 0;padding:0;display:flex;flex-direction:column;gap:14px}
.rmwr-up-features li{display:flex;align-items:flex-start;gap:11px;font-size:.92rem;color:var(--ink-soft)}
.rmwr-up-card.feat .rmwr-up-features li{color:var(--dark-soft)}
.rmwr-up-features li.lead{font-weight:700;color:var(--ink)}
.rmwr-up-card.feat .rmwr-up-features li.lead{color:#fff}
.rmwr-up-check{flex:0 0 auto;margin-top:1px;width:20px;height:20px;border-radius:50%;background:var(--accent-tint);display:inline-flex;align-items:center;justify-content:center}
.rmwr-up-check svg{width:11px;height:11px}
.rmwr-up-check svg path{stroke:var(--accent-dark);stroke-width:2.6;fill:none;stroke-linecap:round;stroke-linejoin:round}
.rmwr-up-card.feat .rmwr-up-check{background:rgba(124,58,237,.34)}
.rmwr-up-card.feat .rmwr-up-check svg path{stroke:#d8c8ff}
.rmwr-up-allin{margin-top:22px;padding-top:18px;border-top:1px dashed var(--line);font-size:.88rem;font-weight:600;color:var(--accent-dark);display:flex;align-items:center;gap:9px}
.rmwr-up-card.feat .rmwr-up-allin{border-color:var(--dark-line);color:#cbb6ff}
.rmwr-up-dot{width:7px;height:7px;border-radius:50%;background:currentColor;box-shadow:0 0 0 4px var(--accent-tint)}
.rmwr-up-trust{margin-top:clamp(36px,5vw,54px);text-align:center}
.rmwr-up-trust-lines{display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:12px 22px;color:var(--ink-soft);font-size:.92rem;font-weight:600}
.rmwr-up-ic{color:var(--accent-dark);margin-right:7px}
.rmwr-up-trust-dot{width:5px;height:5px;border-radius:50%;background:var(--muted);opacity:.5}
.rmwr-up-pays{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin-top:20px}
.rmwr-up-pay{font-size:.72rem;font-weight:700;letter-spacing:.08em;color:var(--ink-soft);background:#fff;border:1px solid var(--line);padding:8px 14px;border-radius:10px;box-shadow:0 2px 8px rgba(37,24,67,.05);display:inline-flex;align-items:center;gap:7px}
.rmwr-up-pay .mk{width:8px;height:8px;border-radius:2px;background:var(--accent);opacity:.85}
.rmwr-up-pay.visa .mk{background:#1a1f71}
.rmwr-up-pay.mc .mk{background:linear-gradient(90deg,#eb001b 50%,#f79e1b 50%);border-radius:50%}
.rmwr-up-pay.amex .mk{background:#2e77bb}
.rmwr-up-pay.pp .mk{background:#009cde}
.rmwr-up-pay.mcafee .mk{background:#c01818;border-radius:50%}
.rmwr-up-pay.cf .mk{background:#f6821f}
@media (max-width:900px){.rmwr-up{padding-left:clamp(16px,4vw,32px);padding-right:clamp(16px,4vw,32px)}.rmwr-up-grid{grid-template-columns:1fr;width:100%;max-width:520px;margin-left:auto;margin-right:auto;gap:22px}.rmwr-up-card.feat{transform:none;order:-1}.rmwr-up-card.feat:hover{transform:translateY(-6px)}.rmwr-up-card{animation-delay:0s !important}}
@media (prefers-reduced-motion:reduce){.rmwr-up-card{animation:none;opacity:1;transform:none}.rmwr-up-seg-thumb,.rmwr-up-cta,.rmwr-up-card{transition:none}}
</style>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400..800&family=Hanken+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
CSS;
    }
}
