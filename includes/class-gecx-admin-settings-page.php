<?php
/**
 * Copyright 2026 Google LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Gemini Enterprise for CX settings page
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * The plugin's settings page under WooCommerce: its menu entry, settings,
 * assets and AJAX actions, and the activation redirect and notice that lead
 * to it.
 */
class GECX_Admin_Settings_Page {

    /**
     * Path to the main plugin file.
     */
    private string $plugin_file;

    /**
     * The settings page hook suffix.
     */
    private string $settings_page_hook = '';

    /**
     * Syncs agent state when the settings page loads.
     */
    private GECX_Admin_Console_Sync $console_sync;

    /**
     * Constructor.
     *
     * @param string                  $plugin_file  Main plugin file.
     * @param GECX_Admin_Console_Sync $console_sync Syncs agent state when the page loads.
     */
    public function __construct( string $plugin_file, GECX_Admin_Console_Sync $console_sync ) {
        $this->plugin_file  = $plugin_file;
        $this->console_sync = $console_sync;
    }

    /**
     * Registers this component's hooks.
     */
    public function register_hooks(): void {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_init', [ $this, 'redirect_on_activation' ] );
        add_action( 'admin_notices', [ $this, 'show_activation_notice' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Register plugin action link next to Deactivate.
        add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file ), [ $this, 'add_plugin_action_links' ] );

        // Register AJAX handlers for saving agent details and embed state.
        add_action( 'wp_ajax_gecx_save_button_config', [ $this, 'ajax_save_button_config' ] );
        add_action( 'wp_ajax_gecx_toggle_app_embed', [ $this, 'ajax_toggle_app_embed' ] );
        add_action( 'wp_ajax_gecx_toggle_pdp_prompts', [ $this, 'ajax_toggle_pdp_prompts' ] );
        add_action( 'wp_ajax_gecx_dismiss_notice', [ $this, 'ajax_dismiss_notice' ] );
    }

    /**
     * Enqueue admin JS/CSS and localize parameters.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_admin_assets( string $hook ): void {
        $is_settings_page = ( $hook === $this->settings_page_hook );
        $show_notice = ! get_option( 'gecx_dismiss_activation_notice', false ) && empty( get_option( 'gecx_agent_name' ) );

        // Only load if we are on the settings page or if the notice is visible.
        if ( ! $is_settings_page && ! $show_notice ) {
            return;
        }

        // No literal fallback, matching GECX_Storefront::enqueue_storefront_assets().
        // gecx-agent.php defines GECX_VERSION before this class is loaded, so an
        // undefined constant means something is very wrong. null rather than ''
        // because WordPress substitutes its own core version for an empty
        // string, and a stale literal here would be one more version
        // declaration to keep in step with the four that check-version.php
        // already enforces.
        $admin_js_ver = defined( 'GECX_VERSION' ) ? GECX_VERSION : null;

        wp_register_style( 'gecx-admin-css', false, [], $admin_js_ver );
        wp_enqueue_style( 'gecx-admin-css' );
        wp_add_inline_style(
            'gecx-admin-css',
            '.gecx-admin-wrap { max-width: 800px; margin: 25px auto; } .gecx-admin-wrap .card { max-width: 100% !important; width: 100% !important; box-sizing: border-box; }'
            . ' .gecx-info-tip { position: relative; display: inline-block; vertical-align: middle; margin-left: 4px; color: #2271b1; cursor: help; }'
            . ' .gecx-info-tip .dashicons { font-size: 18px; width: 18px; height: 18px; }'
            . ' .gecx-info-tip:focus { outline: 2px solid #2271b1; outline-offset: 1px; border-radius: 50%; }'
            . ' .gecx-info-tip__text { visibility: hidden; opacity: 0; position: absolute; z-index: 100; left: 50%; bottom: calc(100% + 8px); transform: translateX(-50%); width: 280px; padding: 8px 10px; background: #1d2327; color: #fff; border-radius: 4px; font-size: 12px; font-weight: normal; line-height: 1.5; text-align: left; transition: opacity 0.15s; }'
            . ' .gecx-info-tip__text::after { content: ""; position: absolute; top: 100%; left: 50%; margin-left: -5px; border: 5px solid transparent; border-top-color: #1d2327; }'
            . ' .gecx-info-tip__text code { background: rgba(255, 255, 255, 0.15); color: #fff; }'
            . ' .gecx-info-tip:hover .gecx-info-tip__text, .gecx-info-tip:focus .gecx-info-tip__text { visibility: visible; opacity: 1; }'
        );

        wp_enqueue_script(
            'gecx-admin-js',
            plugins_url( 'assets/js/admin.js', $this->plugin_file ),
            [ 'jquery' ],
            $admin_js_ver,
            true
        );

        wp_set_script_translations(
            'gecx-admin-js',
            'gemini-enterprise-for-cx'
        );

        wp_localize_script( 'gecx-admin-js', 'gecx_admin_params', [
            'save_nonce'           => wp_create_nonce( 'gecx_save_agent_nonce' ),
            'dismiss_nonce'        => wp_create_nonce( 'gecx_dismiss_notice_nonce' ),
            'statusActive'         => __( 'Connection Status: Active', 'gemini-enterprise-for-cx' ),
            'statusInactive'       => __( 'Connection Status: Inactive', 'gemini-enterprise-for-cx' ),
            'errorToggleWidget'    => __( 'Failed to update storefront chat widget status. Please try again.', 'gemini-enterprise-for-cx' ),
            'errorTogglePrompts'   => __( 'Failed to update suggested prompts status. Please try again.', 'gemini-enterprise-for-cx' ),
            'confirmDisconnect'    => __( 'Are you sure you want to disconnect this Gemini agent from your store?', 'gemini-enterprise-for-cx' ),
            'disconnecting'        => __( 'Disconnecting...', 'gemini-enterprise-for-cx' ),
            'disconnectLabel'      => __( 'Disconnect Agent', 'gemini-enterprise-for-cx' ),
            'errorDisconnect'      => __( 'Failed to disconnect agent. Please try again.', 'gemini-enterprise-for-cx' ),
            'errorDisconnectAjax'  => __( 'Error disconnecting agent.', 'gemini-enterprise-for-cx' ),
        ] );
    }

    /**
     * Redirect user to onboarding settings page immediately upon plugin activation.
     *
     * The flag is consumed only by a request that can act on it. admin_init
     * fires for admin-ajax.php too, so Heartbeat - which a logged-in admin
     * screen starts within seconds of activation - used to reach this first,
     * delete the flag and return, leaving the admin on the plugins list with
     * onboarding never shown. Intermittent by nature, since it depends on
     * which request arrives first.
     */
    public function redirect_on_activation(): void {
        if ( ! get_option( 'gecx_do_activation_redirect', false ) ) {
            return;
        }

        // Leave the flag set: this request cannot redirect, but the page load
        // that follows it can.
        if ( wp_doing_ajax() ) {
            return;
        }

        // Bulk activation does consume the flag. Redirecting away from a
        // multi-plugin activation is hostile, and leaving the flag set would
        // fire the redirect on whatever admin page the user opened next.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['activate-multi'] ) ) {
            delete_option( 'gecx_do_activation_redirect' );
            return;
        }

        delete_option( 'gecx_do_activation_redirect' );
        wp_safe_redirect( admin_url( 'admin.php?page=gemini-enterprise-for-cx' ) );
        exit;
    }

    /**
     * Add Settings link next to Deactivate on plugins list page.
     *
     * @param array $links Array of plugin action links.
     * @return array Modified links array.
     */
    public function add_plugin_action_links( array $links ): array {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=gemini-enterprise-for-cx' ) ) . '">' . esc_html__( 'Settings', 'gemini-enterprise-for-cx' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Add GECX settings page (submenu page under WooCommerce Marketing).
     */
    public function add_settings_page(): void {
        $this->settings_page_hook = (string) add_submenu_page(
            'woocommerce-marketing',
            __( 'Gemini Enterprise for CX', 'gemini-enterprise-for-cx' ),
            __( 'Gemini Enterprise for CX', 'gemini-enterprise-for-cx' ),
            'manage_options',
            'gemini-enterprise-for-cx',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Register option setting in WordPress database.
     */
    public function register_settings(): void {
        register_setting( 'gecx_agent_group', 'gecx_agent_name', [
            'sanitize_callback' => [ GECX_Admin::class, 'sanitize_agent_name' ],
        ] );
        register_setting( 'gecx_agent_group', 'gecx_agent_enabled', [
            'sanitize_callback' => 'absint',
        ] );
        register_setting( 'gecx_agent_group', 'gecx_pdp_prompts_enabled', [
            'sanitize_callback' => 'absint',
        ] );
        register_setting( 'gecx_agent_group', 'gecx_button_placement', [
            'sanitize_callback' => [ GECX_Storefront::class, 'sanitize_button_placement' ],
        ] );
        register_setting( 'gecx_agent_group', 'gecx_nav_menu_target', [
            'sanitize_callback' => [ GECX_Storefront::class, 'sanitize_nav_menu_target' ],
        ] );
        register_setting( 'gecx_agent_group', 'gecx_floating_position', [
            'sanitize_callback' => [ GECX_Storefront::class, 'sanitize_floating_position' ],
        ] );
        register_setting( 'gecx_agent_group', 'gecx_button_display_style', [
            'sanitize_callback' => [ GECX_Storefront::class, 'sanitize_button_display_style' ],
        ] );
        register_setting( 'gecx_agent_group', 'gecx_button_label', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( 'gecx_agent_group', 'gecx_button_short_label', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( 'gecx_agent_group', 'gecx_button_enable_shimmer', [
            'sanitize_callback' => 'absint',
        ] );
        register_setting( 'gecx_agent_group', 'gecx_defer_widget_until_interaction', [
            'sanitize_callback' => 'absint',
        ] );
    }

    /**
     * Render the native settings page.
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gemini-enterprise-for-cx' ), 403 );
        }

        $current_agent = (string) get_option( 'gecx_agent_name', '' );

        // Re-sync the binding status against the backend on GET render.
        // SyncState only reconciles local mirror options against the authoritative
        // store-signed JWT response from Google Cloud (adopting or unlinking the
        // binding Google already holds for this store's RSA keypair); no caller-
        // controlled input is read from $_GET, and claim_sync_window() throttles
        // requests to once per SYNC_THROTTLE_SECONDS.
        $this->console_sync->sync_agent_state( $current_agent );
        // Reload agent in case the sync adopted or cleared the binding.
        $current_agent = (string) get_option( 'gecx_agent_name', '' );

        $token_broker          = (string) get_option( 'gecx_token_broker_name', '' );
        $embed_enabled         = (bool) get_option( 'gecx_agent_enabled', 0 );
        $pdp_prompts_enabled   = (bool) get_option( 'gecx_pdp_prompts_enabled', 1 );
        $button_placement      = (string) get_option( 'gecx_button_placement', 'nav_menu' );
        $nav_menu_target       = GECX_Storefront::sanitize_nav_menu_target( get_option( 'gecx_nav_menu_target', '' ) );
        $nav_menu_locations    = function_exists( 'get_registered_nav_menus' ) ? (array) get_registered_nav_menus() : [];
        $nav_menus             = function_exists( 'wp_get_nav_menus' ) ? (array) wp_get_nav_menus() : [];
        $floating_position     = (string) get_option( 'gecx_floating_position', 'bottom_center' );
        $display_style         = (string) get_option( 'gecx_button_display_style', 'responsive' );
        $button_label          = (string) get_option( 'gecx_button_label', '' );
        $button_short_label    = (string) get_option( 'gecx_button_short_label', '' );
        $button_enable_shimmer = (bool) get_option( 'gecx_button_enable_shimmer', 1 );

        $console_base  = GECX_Auth::get_console_base_url();
        $console_ready = '' !== $console_base;

        // SyncState can report that Google can no longer use this store's
        // credentials. Treat that as unauthorized so the merchant is offered
        // the authorize step again instead of a dead end.
        $is_authorized  = (bool) get_option( GECX_Admin::AUTH_COMPLETE_OPTION, false ) && ! get_option( GECX_Admin::STORE_AUTH_INVALID_OPTION, false );
        $rest_api_ready = GECX_Admin::is_standard_rest_api_enabled();
        // The wc-auth callback_url receives the consumer key and secret, so the
        // authorize and connect buttons stay disabled unless the console base
        // URL is on the allowlist.
        $connect_ready = $rest_api_ready && $console_ready;

        $oauth_url = '';
        if ( $console_ready ) {
            $oauth_return_url = add_query_arg(
                [
                    'authorized' => '1',
                ],
                admin_url( 'admin.php?page=gemini-enterprise-for-cx' )
            );
            $oauth_callback_url = $console_base . GECX_Admin_Connection::CONSOLE_WOO_AUTH_WEBHOOK_PATH;
            $oauth_url = add_query_arg(
                [
                    'app_name'     => rawurlencode( GECX_Auth::WC_AUTH_APP_NAME ),
                    'scope'        => 'read_write',
                    'user_id'      => rawurlencode( home_url() ),
                    'return_url'   => rawurlencode( $oauth_return_url ),
                    'callback_url' => rawurlencode( $oauth_callback_url ),
                ],
                home_url( '/wc-auth/v1/authorize' )
            );
        }
        ?>
        <div class="wrap gecx-admin-wrap">
            <h1><?php esc_html_e( 'Gemini Enterprise for CX', 'gemini-enterprise-for-cx' ); ?></h1>

            <div id="gecx-admin-notices"></div>

            <?php settings_errors( 'gecx_messages' ); ?>

            <?php if ( ! $console_ready ) : ?>
                <div class="notice notice-error">
                    <p>
                        <?php esc_html_e( 'The configured Google Cloud console address is not allowed, so this store cannot be authorized or connected. Remove the gecx_console_base_url override.', 'gemini-enterprise-for-cx' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( ! $rest_api_ready ) : ?>
                <div class="notice notice-error">
                    <p>
                        <?php
                        echo wp_kses_post(
                            sprintf(
                                /* translators: %s: URL to the WordPress Permalinks settings page. */
                                __( '<strong>Permalinks configuration required:</strong> Gemini Enterprise for CX requires pretty permalinks and the default <code>/wp-json</code> REST API prefix. Please enable a non-Plain structure in <a href="%s">Settings &gt; Permalinks</a> and ensure no filter overrides <code>rest_url_prefix</code>.', 'gemini-enterprise-for-cx' ),
                                esc_url( admin_url( 'options-permalink.php' ) )
                            )
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            $error_notice = get_transient( 'gecx_admin_notice_error' );
            if ( ! empty( $error_notice ) ) :
                delete_transient( 'gecx_admin_notice_error' );
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html( $error_notice ); ?></p>
                </div>
            <?php endif; ?>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( isset( $_GET['connected'] ) ) :
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Successfully connected to Google Gemini Enterprise for CX!', 'gemini-enterprise-for-cx' ); ?></p>
                </div>
            <?php endif; ?>

            <?php
            // Store authorization gates everything below it: without usable
            // WooCommerce API keys the agent cannot read the catalog, so an
            // invalidated authorization sends the merchant back to step 1 even
            // when an agent is still saved.
            ?>
            <?php if ( ! $is_authorized || empty( $current_agent ) ) : ?>
                <?php if ( ! $is_authorized ) : ?>
                    <div class="card" style="margin-top: 20px; padding: 28px 32px;">
                        <h2><?php esc_html_e( 'Step 1: Authorize WooCommerce API', 'gemini-enterprise-for-cx' ); ?></h2>
                        <p style="font-size: 14px; line-height: 1.6; color: #50575e;">
                            <?php esc_html_e( 'Grant Gemini Enterprise for CX permission to read your store catalog and assist shoppers.', 'gemini-enterprise-for-cx' ); ?>
                        </p>

                        <div style="margin: 24px 0;">
                            <?php if ( $connect_ready ) : ?>
                                <a href="<?php echo esc_url( $oauth_url ); ?>"
                                    id="gecx-authorize-btn"
                                    class="button button-primary button-hero"
                                    style="display: inline-flex; align-items: center; gap: 8px;">
                                    <?php esc_html_e( 'Authorize Store', 'gemini-enterprise-for-cx' ); ?>
                                </a>
                            <?php else : ?>
                                <button type="button"
                                    id="gecx-authorize-btn"
                                    class="button button-primary button-hero"
                                    disabled="disabled"
                                    aria-disabled="true"
                                    style="display: inline-flex; align-items: center; gap: 8px;">
                                    <?php esc_html_e( 'Authorize Store', 'gemini-enterprise-for-cx' ); ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <p class="description">
                            <?php esc_html_e( 'Clicking above prompts you to approve WooCommerce API keys for your store.', 'gemini-enterprise-for-cx' ); ?>
                        </p>
                    </div>
                <?php else : ?>
                    <div class="card" style="margin-top: 20px; padding: 28px 32px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; color: #007017; font-weight: 600;">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <span><?php esc_html_e( 'Store Authorization Complete', 'gemini-enterprise-for-cx' ); ?></span>
                        </div>
                        <h2><?php esc_html_e( 'Step 2: Connect Your Store to Google Cloud', 'gemini-enterprise-for-cx' ); ?></h2>
                        <p style="font-size: 14px; line-height: 1.6; color: #50575e;">
                            <?php esc_html_e( 'Drive sales with an AI agent powered by Google Gemini that understands your store products and brand. Authenticate with Google to configure and activate your storefront agent.', 'gemini-enterprise-for-cx' ); ?>
                        </p>

                        <div style="margin: 24px 0; display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                            <?php if ( $connect_ready ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0; display: inline-flex;">
                                    <input type="hidden" name="action" value="gecx_connect_agent" />
                                    <?php wp_nonce_field( 'gecx_connect_agent_action', 'gecx_connect_nonce' ); ?>
                                    <button type="submit"
                                        id="gecx-connect-btn"
                                        class="button button-primary button-hero"
                                        style="display: inline-flex; align-items: center; gap: 8px;">
                                        <?php esc_html_e( 'Connect with Google Cloud', 'gemini-enterprise-for-cx' ); ?>
                                    </button>
                                </form>
                                <a href="<?php echo esc_url( $oauth_url ); ?>"
                                    id="gecx-reauthorize-btn"
                                    class="button button-secondary">
                                    <?php esc_html_e( 'Re-authorize Store', 'gemini-enterprise-for-cx' ); ?>
                                </a>
                            <?php else : ?>
                                <button type="button"
                                    id="gecx-connect-btn"
                                    class="button button-primary button-hero"
                                    disabled="disabled"
                                    aria-disabled="true"
                                    style="display: inline-flex; align-items: center; gap: 8px;">
                                    <?php esc_html_e( 'Connect with Google Cloud', 'gemini-enterprise-for-cx' ); ?>
                                </button>
                                <button type="button"
                                    id="gecx-reauthorize-btn"
                                    class="button button-secondary"
                                    disabled="disabled"
                                    aria-disabled="true">
                                    <?php esc_html_e( 'Re-authorize Store', 'gemini-enterprise-for-cx' ); ?>
                                </button>
                            <?php endif; ?>
                        </div>

                        <p class="description">
                            <?php esc_html_e( 'Clicking above redirects you to Google Cloud Console to configure your agent. After signing in and selecting your agent, you will be redirected back here.', 'gemini-enterprise-for-cx' ); ?>
                        </p>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <div class="card" style="margin-top: 20px; padding: 28px 32px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                        <h2 style="margin: 0; display: flex; align-items: center; gap: 8px;">
                            <span id="gecx-status-indicator" style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: <?php echo $embed_enabled ? '#46b450' : '#787c82'; ?>;"></span>
                            <span id="gecx-status-text">
                                <?php echo $embed_enabled ? esc_html__( 'Connection Status: Active', 'gemini-enterprise-for-cx' ) : esc_html__( 'Connection Status: Inactive', 'gemini-enterprise-for-cx' ); ?>
                            </span>
                        </h2>
                        <a href="<?php echo esc_url( $console_ready ? $console_base : GECX_Auth::DEFAULT_CONSOLE_BASE_URL ); ?>" target="_blank" class="button button-secondary">
                            <?php esc_html_e( 'Open Google Cloud Console', 'gemini-enterprise-for-cx' ); ?>
                        </a>
                    </div>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Active Agent', 'gemini-enterprise-for-cx' ); ?></th>
                            <td>
                                <code style="word-break: break-all;"><?php echo esc_html( $current_agent ); ?></code>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Storefront Chat Widget', 'gemini-enterprise-for-cx' ); ?></th>
                            <td>
                                <label for="gecx_agent_enabled">
                                    <input type="checkbox"
                                           id="gecx_agent_enabled"
                                           name="gecx_agent_enabled"
                                           value="1"
                                           <?php checked( $embed_enabled ); ?> />
                                    <?php esc_html_e( 'Enable chat widget on customer storefront', 'gemini-enterprise-for-cx' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Suggested Prompts', 'gemini-enterprise-for-cx' ); ?></th>
                            <td>
                                <label for="gecx_pdp_prompts_enabled">
                                    <input type="checkbox"
                                           id="gecx_pdp_prompts_enabled"
                                           name="gecx_pdp_prompts_enabled"
                                           value="1"
                                           <?php checked( $pdp_prompts_enabled ); ?>
                                           <?php disabled( ! $embed_enabled ); ?> />
                                    <?php esc_html_e( 'Show AI-generated suggested questions on product detail pages', 'gemini-enterprise-for-cx' ); ?>
                                </label>
                                <span id="gecx-prompts-manual-tip"
                                      class="gecx-info-tip"
                                      tabindex="0"
                                      aria-label="<?php esc_attr_e( 'Adding prompts yourself', 'gemini-enterprise-for-cx' ); ?>"
                                      aria-describedby="gecx-prompts-manual-tip-text">
                                    <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                                    <span id="gecx-prompts-manual-tip-text" class="gecx-info-tip__text" role="tooltip">
                                        <?php
                                        printf(
                                            /* translators: 1: shortcode, 2: block name. */
                                            esc_html__( 'To show prompts somewhere else, use the %1$s shortcode or the %2$s block, for example in the Single Product template. Turn this setting off if you only want prompts where you place them.', 'gemini-enterprise-for-cx' ),
                                            '<code>[gecx_suggested_prompts]</code>',
                                            '<code>' . esc_html__( 'Gemini Enterprise for CX Suggested Prompts', 'gemini-enterprise-for-cx' ) . '</code>'
                                        );
                                        ?>
                                    </span>
                                </span>
                            </td>
                        </tr>
                    </table>

                    <hr style="margin: 30px 0 20px 0; border: 0; border-top: 1px solid #dcdcde;" />

                    <h3 style="margin-bottom: 8px;">
                        <?php esc_html_e( 'Launcher Placement & Appearance', 'gemini-enterprise-for-cx' ); ?>
                        <span id="gecx-button-config-saved" style="display: none; font-size: 13px; font-weight: normal; color: #46b450; margin-left: 10px;">
                            ✓ <?php esc_html_e( 'Saved', 'gemini-enterprise-for-cx' ); ?>
                        </span>
                    </h3>
                    <p class="description" style="margin-bottom: 16px;">
                        <?php esc_html_e( 'Configure where and how the agent launcher button appears on your storefront.', 'gemini-enterprise-for-cx' ); ?>
                    </p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Launcher Placement', 'gemini-enterprise-for-cx' ); ?></th>
                            <td>
                                <fieldset>
                                    <label style="display: block; margin-bottom: 10px;">
                                        <input type="radio"
                                               name="gecx_button_placement"
                                               value="nav_menu"
                                               <?php checked( $button_placement, 'nav_menu' ); ?> />
                                        <strong><?php esc_html_e( 'Top navigation menu', 'gemini-enterprise-for-cx' ); ?></strong>
                                        <br><span class="description" style="margin-left: 20px;"><?php esc_html_e( 'Part of your primary header navigation bar.', 'gemini-enterprise-for-cx' ); ?></span>
                                    </label>
                                    <label style="display: block; margin-bottom: 10px;">
                                        <input type="radio"
                                               name="gecx_button_placement"
                                               value="floating"
                                               <?php checked( $button_placement, 'floating' ); ?> />
                                        <strong><?php esc_html_e( 'Floating bubble', 'gemini-enterprise-for-cx' ); ?></strong>
                                        <br><span class="description" style="margin-left: 20px;"><?php esc_html_e( 'Fixed position floating over pages.', 'gemini-enterprise-for-cx' ); ?></span>
                                    </label>
                                    <label style="display: inline-block;">
                                        <input type="radio"
                                               name="gecx_button_placement"
                                               value="manual"
                                               <?php checked( $button_placement, 'manual' ); ?> />
                                        <strong><?php esc_html_e( 'Manual', 'gemini-enterprise-for-cx' ); ?></strong>
                                    </label>
                                    <span id="gecx-manual-placement-tip"
                                          class="gecx-info-tip"
                                          tabindex="0"
                                          aria-label="<?php esc_attr_e( 'Placing the launcher yourself', 'gemini-enterprise-for-cx' ); ?>"
                                          aria-describedby="gecx-manual-placement-tip-text"
                                          style="<?php echo 'manual' === $button_placement ? '' : 'display: none;'; ?>">
                                        <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                                        <span id="gecx-manual-placement-tip-text" class="gecx-info-tip__text" role="tooltip">
                                            <?php
                                            printf(
                                                /* translators: 1: shortcode, 2: block name. */
                                                esc_html__( 'Place the launcher yourself with the %1$s shortcode or the %2$s block, for example in a page builder header.', 'gemini-enterprise-for-cx' ),
                                                '<code>[gecx_agent_button]</code>',
                                                '<code>' . esc_html__( 'Gemini Enterprise for CX Launcher', 'gemini-enterprise-for-cx' ) . '</code>'
                                            );
                                            ?>
                                        </span>
                                    </span>
                                </fieldset>
                            </td>
                        </tr>
                        <tr id="gecx_nav_menu_target_row" style="<?php echo 'nav_menu' === $button_placement ? '' : 'display: none;'; ?>">
                            <th scope="row">
                                <label for="gecx_nav_menu_target"><?php esc_html_e( 'Menu', 'gemini-enterprise-for-cx' ); ?></label>
                            </th>
                            <td>
                                <select id="gecx_nav_menu_target" name="gecx_nav_menu_target">
                                    <option value="" <?php selected( $nav_menu_target, '' ); ?>><?php esc_html_e( 'Automatic (header and mobile menus)', 'gemini-enterprise-for-cx' ); ?></option>
                                    <?php if ( ! empty( $nav_menu_locations ) ) : ?>
                                        <optgroup label="<?php esc_attr_e( 'Theme menu locations', 'gemini-enterprise-for-cx' ); ?>">
                                            <?php foreach ( $nav_menu_locations as $location_slug => $location_label ) : ?>
                                                <option value="<?php echo esc_attr( 'location:' . $location_slug ); ?>" <?php selected( $nav_menu_target, 'location:' . $location_slug ); ?>><?php echo esc_html( (string) $location_label ); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $nav_menus ) ) : ?>
                                        <optgroup label="<?php esc_attr_e( 'Menus (including page builder menus)', 'gemini-enterprise-for-cx' ); ?>">
                                            <?php foreach ( $nav_menus as $nav_menu ) : ?>
                                                <?php if ( is_object( $nav_menu ) && isset( $nav_menu->term_id, $nav_menu->name ) ) : ?>
                                                    <option value="<?php echo esc_attr( 'menu:' . (int) $nav_menu->term_id ); ?>" <?php selected( $nav_menu_target, 'menu:' . (int) $nav_menu->term_id ); ?>><?php echo esc_html( (string) $nav_menu->name ); ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Choose a menu if the launcher does not appear in your header automatically.', 'gemini-enterprise-for-cx' ); ?></p>
                            </td>
                        </tr>
                        <tr id="gecx_floating_position_row" style="<?php echo 'floating' === $button_placement ? '' : 'display: none;'; ?>">
                            <th scope="row">
                                <label for="gecx_button_floating_position"><?php esc_html_e( 'Floating Position', 'gemini-enterprise-for-cx' ); ?></label>
                            </th>
                            <td>
                                <select id="gecx_button_floating_position" name="gecx_button_floating_position">
                                    <option value="bottom_center" <?php selected( $floating_position, 'bottom_center' ); ?>><?php esc_html_e( 'Bottom Center', 'gemini-enterprise-for-cx' ); ?></option>
                                    <option value="bottom_left" <?php selected( $floating_position, 'bottom_left' ); ?>><?php esc_html_e( 'Bottom Left', 'gemini-enterprise-for-cx' ); ?></option>
                                    <option value="bottom_right" <?php selected( $floating_position, 'bottom_right' ); ?>><?php esc_html_e( 'Bottom Right', 'gemini-enterprise-for-cx' ); ?></option>
                                    <option value="center_left" <?php selected( $floating_position, 'center_left' ); ?>><?php esc_html_e( 'Middle Left', 'gemini-enterprise-for-cx' ); ?></option>
                                    <option value="center_right" <?php selected( $floating_position, 'center_right' ); ?>><?php esc_html_e( 'Middle Right', 'gemini-enterprise-for-cx' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="gecx_button_display_style"><?php esc_html_e( 'Button Style & Size', 'gemini-enterprise-for-cx' ); ?></label>
                            </th>
                            <td>
                                <select id="gecx_button_display_style" name="gecx_button_display_style">
                                    <option value="responsive" <?php selected( $display_style, 'responsive' ); ?>><?php esc_html_e( 'Responsive (Adapts to screen width)', 'gemini-enterprise-for-cx' ); ?></option>
                                    <option value="icon-and-label" <?php selected( $display_style, 'icon-and-label' ); ?>><?php esc_html_e( 'Icon and label (Always full size)', 'gemini-enterprise-for-cx' ); ?></option>
                                    <option value="icon-only" <?php selected( $display_style, 'icon-only' ); ?>><?php esc_html_e( 'Icon only (Compact circular bubble)', 'gemini-enterprise-for-cx' ); ?></option>
                                    <option value="label-only" <?php selected( $display_style, 'label-only' ); ?>><?php esc_html_e( 'Label only (Text button)', 'gemini-enterprise-for-cx' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="gecx_button_label"><?php esc_html_e( 'Button Label (Full)', 'gemini-enterprise-for-cx' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       id="gecx_button_label"
                                       name="gecx_button_label"
                                       value="<?php echo esc_attr( $button_label ); ?>"
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e( 'Chat with Agent', 'gemini-enterprise-for-cx' ); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="gecx_button_short_label"><?php esc_html_e( 'Short Label (Small Screens)', 'gemini-enterprise-for-cx' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       id="gecx_button_short_label"
                                       name="gecx_button_short_label"
                                       value="<?php echo esc_attr( $button_short_label ); ?>"
                                       class="regular-text"
                                       style="width: 140px; max-width: 100%;"
                                       placeholder="<?php esc_attr_e( 'Shop', 'gemini-enterprise-for-cx' ); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Shimmer Animation', 'gemini-enterprise-for-cx' ); ?></th>
                            <td>
                                <label for="gecx_button_enable_shimmer">
                                    <input type="checkbox"
                                           id="gecx_button_enable_shimmer"
                                           name="gecx_button_enable_shimmer"
                                           value="1"
                                           <?php checked( $button_enable_shimmer ); ?> />
                                    <?php esc_html_e( 'Show subtle animated shimmer effect on button', 'gemini-enterprise-for-cx' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #dcdcde; display: flex; justify-content: flex-end;">
                        <button type="button" id="gecx-unlink-agent-btn" class="button button-link-delete" style="color: #b32d2e;">
                            <?php esc_html_e( 'Disconnect Agent', 'gemini-enterprise-for-cx' ); ?>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX handler to save storefront button placement, size, and styling options.
     */
    public function ajax_save_button_config(): void {
        if ( false === check_ajax_referer( 'gecx_save_agent_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce', 'gemini-enterprise-for-cx' ) ], 403 );
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'gemini-enterprise-for-cx' ) ], 403 );
            return;
        }

        $placement      = GECX_Storefront::sanitize_button_placement( isset( $_POST['placement'] ) ? sanitize_text_field( wp_unslash( $_POST['placement'] ) ) : null );
        $floating_pos   = GECX_Storefront::sanitize_floating_position( isset( $_POST['floating_position'] ) ? sanitize_text_field( wp_unslash( $_POST['floating_position'] ) ) : null );
        $display_style  = GECX_Storefront::sanitize_button_display_style( isset( $_POST['display_style'] ) ? sanitize_text_field( wp_unslash( $_POST['display_style'] ) ) : null );
        $label          = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
        $short_label    = isset( $_POST['short_label'] ) ? sanitize_text_field( wp_unslash( $_POST['short_label'] ) ) : '';
        $enable_shimmer = isset( $_POST['enable_shimmer'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enable_shimmer'] ) ) ? 1 : 0;
        $menu_target    = GECX_Storefront::sanitize_nav_menu_target( isset( $_POST['nav_menu_target'] ) ? sanitize_text_field( wp_unslash( $_POST['nav_menu_target'] ) ) : '' );

        update_option( 'gecx_button_placement', $placement );
        update_option( 'gecx_floating_position', $floating_pos );
        update_option( 'gecx_button_display_style', $display_style );
        update_option( 'gecx_button_label', $label );
        update_option( 'gecx_button_short_label', $short_label );
        update_option( 'gecx_button_enable_shimmer', $enable_shimmer );
        update_option( 'gecx_nav_menu_target', $menu_target );

        wp_send_json_success();
    }

    /**
     * AJAX handler to toggle the storefront chat widget embed state.
     */
    public function ajax_toggle_app_embed(): void {
        if ( false === check_ajax_referer( 'gecx_save_agent_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce', 'gemini-enterprise-for-cx' ) ], 403 );
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'gemini-enterprise-for-cx' ) ], 403 );
            return;
        }

        $enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) ? 1 : 0;
        update_option( 'gecx_agent_enabled', $enabled );
        if ( 1 === $enabled ) {
            delete_option( GECX_Admin::MERCHANT_DISABLED_OPTION );
        } else {
            update_option( GECX_Admin::MERCHANT_DISABLED_OPTION, 1, false );
        }
        if ( class_exists( 'GECX_Rest_Order_Webhook' ) ) {
            GECX_Rest_Order_Webhook::set_order_webhook_status( 1 === $enabled ? 'active' : 'paused' );
        }
        wp_send_json_success( [ 'enabled' => $enabled ] );
    }

    /**
     * AJAX handler to toggle the suggested PDP prompts state.
     */
    public function ajax_toggle_pdp_prompts(): void {
        if ( false === check_ajax_referer( 'gecx_save_agent_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce', 'gemini-enterprise-for-cx' ) ], 403 );
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'gemini-enterprise-for-cx' ) ], 403 );
            return;
        }

        $enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) ? 1 : 0;
        update_option( 'gecx_pdp_prompts_enabled', $enabled );
        wp_send_json_success( [ 'enabled' => $enabled ] );
    }

    /**
     * Show site-wide admin notice if plugin is activated but setup is incomplete.
     */
    public function show_activation_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( get_option( 'gecx_dismiss_activation_notice', false ) ) {
            return;
        }
        if ( ! empty( get_option( 'gecx_agent_name' ) ) ) {
            return;
        }

        // Do not display the notice on the GECX settings page.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['page'] ) && 'gemini-enterprise-for-cx' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
            return;
        }
        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && ! empty( $this->settings_page_hook ) && $screen->id === $this->settings_page_hook ) {
                return;
            }
        }
        if ( ! empty( $this->settings_page_hook ) && isset( $GLOBALS['hook_suffix'] ) && $GLOBALS['hook_suffix'] === $this->settings_page_hook ) {
            return;
        }

        $settings_url = admin_url( 'admin.php?page=gemini-enterprise-for-cx' );
        ?>
        <div class="notice notice-info is-dismissible gecx-activation-notice">
            <p>
                <?php
                echo wp_kses_post( sprintf(
                    /* translators: %s: URL to the settings page */
                    __( 'Thanks for installing Gemini Enterprise for CX! <a href="%s">Complete the setup</a> to start delivering better shopping experiences to your customers.', 'gemini-enterprise-for-cx' ),
                    esc_url( $settings_url )
                ) );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * AJAX handler to dismiss the activation notice.
     */
    public function ajax_dismiss_notice(): void {
        if ( false === check_ajax_referer( 'gecx_dismiss_notice_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce', 'gemini-enterprise-for-cx' ) ], 403 );
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'gemini-enterprise-for-cx' ) ], 403 );
            return;
        }
        update_option( 'gecx_dismiss_activation_notice', true );
        wp_send_json_success();
    }
}
