<?php

/**
 * Freemius bootstrap (Pro / premium deployment).
 *
 * This replaces the old self-hosted license server. Freemius provides, out of
 * the box: in-dashboard checkout, license activation UI, automatic updates,
 * subscription/renewal handling and (as Merchant of Record) global tax/VAT.
 *
 * SETUP (see FREEMIUS-SETUP.md):
 *   1. Register ONE product on freemius.com. Deploy the free plugin as the
 *      free version and this plugin as its premium version - they share the
 *      same product ID + public key.
 *   2. Download the Freemius WordPress SDK and drop it in /freemius/.
 *   3. Fill RMWR_FS_ID and RMWR_FS_PUBLIC_KEY below (or define them in
 *      wp-config.php).
 *
 * Until the SDK is present AND the public key is set, rmwr_fs() returns null
 * and the plugin keeps working normally - it simply offers no license/update
 * UI yet. Nothing fatals.
 *
 * @package ReadMoreWithoutRefreshPro
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !function_exists( 'rmwr_fs' ) ) {
    // ONE Freemius product shared by the free and premium plugins - the
    // is_premium flag below distinguishes this (premium) deployment. Same ID
    // and public key as the free plugin. This is what enables the seamless
    // in-dashboard free -> pro upgrade. See FREEMIUS-SETUP.md.
    if ( !defined( 'RMWR_FS_ID' ) ) {
        define( 'RMWR_FS_ID', '33760' );
        // Freemius product ID (shared).
    }
    if ( !defined( 'RMWR_FS_PUBLIC_KEY' ) ) {
        define( 'RMWR_FS_PUBLIC_KEY', 'pk_d942c8e7647e7995673839915b2ca' );
        // Product public key, same in both plugins.
    }
    /**
     * Get the shared Freemius instance (or null when not configured yet).
     *
     * The free and premium plugins deliberately define the SAME rmwr_fs()
     * guarded by function_exists: when both are momentarily present during an
     * upgrade, Freemius' SDK elects the premium instance. This is the
     * documented Freemius "separate free/premium plugins" pattern.
     *
     * @return Freemius|null
     */
    function rmwr_fs() {
        global $rmwr_fs;
        if ( isset( $rmwr_fs ) ) {
            return ( $rmwr_fs instanceof \Freemius ? $rmwr_fs : null );
        }
        $sdk = __DIR__ . '/../freemius/start.php';
        if ( !file_exists( $sdk ) || 'pk_REPLACE_ME' === RMWR_FS_PUBLIC_KEY ) {
            $rmwr_fs = false;
            // Not configured yet; degrade gracefully.
            return null;
        }
        require_once $sdk;
        // Single-codebase freemium: one slug for both builds. We deliberately
        // do NOT set premium_slug/premium_suffix - the premium build lives in
        // the SAME folder (read-more-without-refresh) as the free build, and
        // Freemius swaps the files. A separate premium_slug made Freemius look
        // for a non-existent "-pro" plugin in premium mode and drop the menu.
        // This matches the Freemius product wizard's generated config.
        $rmwr_fs = fs_dynamic_init( array(
            'id'               => RMWR_FS_ID,
            'slug'             => 'read-more-without-refresh',
            'type'             => 'plugin',
            'public_key'       => RMWR_FS_PUBLIC_KEY,
            'is_premium'       => false,
            'has_addons'       => false,
            'has_paid_plans'   => true,
            'is_org_compliant' => true,
            'trial'            => array(
                'days'               => 7,
                'is_require_payment' => false,
            ),
            'menu'             => array(
                'slug'    => 'read_more_without_refresh',
                'account' => true,
                'support' => true,
                'pricing' => true,
                'contact' => false,
            ),
            'is_live'          => true,
        ) );
        /**
         * Fires once the Freemius instance is ready.
         *
         * @param Freemius $rmwr_fs
         */
        do_action( 'rmwr_fs_loaded' );
        return $rmwr_fs;
    }

    // Initialize immediately (Freemius must boot as early as possible).
    rmwr_fs();
}
if ( !function_exists( 'rmwr_upgrade_url' ) ) {
    /**
     * Best upgrade/checkout URL: the in-dashboard Freemius checkout when
     * configured, otherwise the shop landing page.
     *
     * @return string
     */
    function rmwr_upgrade_url() {
        $fs = ( function_exists( 'rmwr_fs' ) ? rmwr_fs() : null );
        if ( $fs && method_exists( $fs, 'get_upgrade_url' ) ) {
            return $fs->get_upgrade_url();
        }
        return 'https://shop.8web.gr/read-more-without-refresh-pro/';
    }

}
if ( !function_exists( 'rmwr_is_premium' ) ) {
    /**
     * Whether premium features are active for the current site.
     *
     * This is the single gate that turns the shared codebase into free vs pro:
     * true only when Freemius reports the premium code is usable (a paying
     * customer or an active trial). A fresh install with no license behaves as
     * the free tier, which is exactly what we want.
     *
     * The ONLY source of truth is the Freemius license state - there is no
     * code-level override, so nothing in wp-config.php or elsewhere can
     * unlock premium without a valid license. For QA, generate a test
     * license from the Freemius dashboard (localhost/.test installs do not
     * consume production activations).
     *
     * When Freemius is not configured/loaded, returns false (free tier).
     *
     * @return bool
     */
    function rmwr_is_premium() {
        $fs = ( function_exists( 'rmwr_fs' ) ? rmwr_fs() : null );
        return (bool) ($fs && method_exists( $fs, 'can_use_premium_code' ) && $fs->can_use_premium_code());
    }

}