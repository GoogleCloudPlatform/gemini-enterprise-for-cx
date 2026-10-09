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
 * Gemini Enterprise for CX store connection
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Admin;

use Google\Gemini_Enterprise_For_CX\Admin;
use Google\Gemini_Enterprise_For_CX\Auth;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Connects the store to the Gemini Enterprise for CX console: the redirect
 * that starts linking an agent, and the callback the console returns to.
 */
class Connection {

    /**
     * Console path that receives the WooCommerce OAuth callback.
     */
    public const CONSOLE_WOO_AUTH_WEBHOOK_PATH = '/woocommerce/webhook/woo-auth';

    /**
     * Console path that hosts the agent connection UI.
     */
    private const CONSOLE_APP_PATH = '/woocommerce/app';

    /**
     * Registers this component's hooks.
     */
    public function register_hooks(): void {
        add_action( 'admin_init', [ $this, 'handle_connection_callback' ] );
        add_action( 'admin_post_gecx_connect_agent', [ $this, 'handle_connect_agent_redirect' ] );
    }

    /**
     * Handle return redirect from Google Cloud onboarding application.
     */
    public function handle_connection_callback(): void {
        // This is the return leg of an off-site redirect from the Google Cloud
        // onboarding console, so no nonce can survive the round trip. The
        // request is authorized instead by the administrator capability check
        // below and by the one-time state token, which is issued by this site
        // and validated against its transient/option record before anything
        // acts on the request. Every read below is sanitized at the point of
        // use.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['page'] ) || 'gemini-enterprise-for-cx' !== $_GET['page'] ) {
            return;
        }

        if ( ! isset( $_GET['gecx_action'] ) || 'linked' !== $_GET['gecx_action'] ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $state = '';
        if ( isset( $_GET['state'] ) ) {
            $state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
        } elseif ( isset( $_GET['oauth_state'] ) ) {
            $state = sanitize_text_field( wp_unslash( $_GET['oauth_state'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $transient_valid = ! empty( $state ) && false !== get_transient( 'gecx_oauth_state_' . $state );
        $states          = (array) get_option( 'gecx_pending_oauth_states', [] );
        $now             = time();
        $option_valid    = ! empty( $state ) && isset( $states[ $state ] ) && is_numeric( $states[ $state ] ) && (int) $states[ $state ] > $now;

        if ( ! $transient_valid && ! $option_valid ) {
            set_transient( 'gecx_admin_notice_error', __( 'Security validation failed: invalid or expired session state. Please try linking again.', 'gemini-enterprise-for-cx' ), 60 );
            wp_safe_redirect( admin_url( 'admin.php?page=gemini-enterprise-for-cx' ) );
            exit;
        }
        delete_transient( 'gecx_oauth_state_' . $state );
        if ( isset( $states[ $state ] ) ) {
            unset( $states[ $state ] );
        }
        // Prune expired states.
        $states = array_filter(
            $states,
            function( $exp ) use ( $now ) {
                return is_numeric( $exp ) && (int) $exp > $now;
            }
        );
        update_option( 'gecx_pending_oauth_states', $states, 'no' );
        update_option( Admin::AUTH_COMPLETE_OPTION, 1, 'no' );
        // The merchant just completed the connect flow, so reconciling with
        // Google is what they asked for.
        delete_option( Admin::MERCHANT_UNLINKED_OPTION );

        // Release the sync throttle window so the landing page reconciles
        // immediately with Cloud if the direct webhook has not arrived yet.
        Console_Sync::clear_sync_window();

        // Nothing is persisted from this request. Google Cloud records the link
        // against the merchant's project and reports it to gecx/v1/link-agent with the
        // store's own API credentials, so by the time the browser lands here the
        // agent name is already stored. agent_name and token_broker_name may be
        // present in the query string for the merchant's benefit; they are not
        // read, because this request cannot be authenticated -- the state that
        // got us here travelled off-site inside return_url.
        wp_safe_redirect( admin_url( 'admin.php?page=gemini-enterprise-for-cx&connected=1' ) );
        exit;
    }

    /**
     * Mint a fresh OAuth state + admin JWT and build the Google Cloud Console
     * connection URL only when the merchant clicks "Connect with Google Cloud".
     *
     * Keeping this out of `render_settings_page()` prevents every GET load of
     * the settings screen from writing transients/options and prevents the
     * short-lived `admin_jwt` from sitting in the rendered DOM `<a href>`.
     *
     * @return string Connect URL, or '' when the console base URL is refused.
     */
    public function build_connect_agent_url(): string {
        $console_base = Auth::get_console_base_url();
        if ( '' === $console_base ) {
            // Nothing is minted: neither the OAuth state nor the admin JWT may
            // be handed to a destination that is not an allowed console host.
            return '';
        }

        $oauth_state = wp_generate_password( 32, false );
        set_transient( 'gecx_oauth_state_' . $oauth_state, 1, 15 * MINUTE_IN_SECONDS );

        $states = (array) get_option( 'gecx_pending_oauth_states', [] );
        $now    = time();
        $states = array_filter(
            $states,
            static function( $exp ) use ( $now ): bool {
                return is_numeric( $exp ) && (int) $exp > $now;
            }
        );
        $states[ $oauth_state ] = $now + ( 15 * MINUTE_IN_SECONDS );
        update_option( 'gecx_pending_oauth_states', $states, 'no' );

        $return_url = add_query_arg(
            [
                'gecx_action' => 'linked',
                'state'       => $oauth_state,
            ],
            admin_url( 'admin.php?page=gemini-enterprise-for-cx' )
        );

        $is_authorized = (bool) get_option( Admin::AUTH_COMPLETE_OPTION, false ) && ! get_option( Admin::STORE_AUTH_INVALID_OPTION, false );
        $admin_jwt     = $is_authorized ? Auth::generate_admin_jwt() : '';

        // rawurlencode() is required here: WordPress core's add_query_arg()
        // delegates to _http_build_query(..., false), which does NOT URL-encode
        // parameter values (unlike PHP's http_build_query()). Without
        // rawurlencode(), the '&' separators inside $return_url are interpreted
        // as outer query parameters by the console URL parser, truncating
        // return_url and losing gecx_action and state.
        $connect_params = [
            'return_url' => rawurlencode( $return_url ),
        ];

        $connect_url = add_query_arg(
            $connect_params,
            $console_base . self::CONSOLE_APP_PATH
        );
        // admin_jwt goes in the fragment so browsers never send it to the
        // console server, write it to access logs, or leak it via Referer.
        // Appending '#...' is safe only while $connect_url has no fragment:
        // Auth::is_allowed_console_base_url() rejects bases with one,
        // CONSOLE_APP_PATH must not contain '#', and return_url is encoded.
        if ( ! empty( $admin_jwt ) ) {
            $connect_url .= '#admin_jwt=' . rawurlencode( $admin_jwt );
        }

        return $connect_url;
    }

    /**
     * Handles the POST submission from the "Connect with Google Cloud" button
     * (`admin-post.php?action=gecx_connect_agent`).
     */
    public function handle_connect_agent_redirect(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'gemini-enterprise-for-cx' ), 403 );
        }

        $nonce = isset( $_POST['gecx_connect_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['gecx_connect_nonce'] ) )
            : '';
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'gecx_connect_agent_action' ) ) {
            set_transient(
                'gecx_admin_notice_error',
                __( 'Security validation failed. Please try connecting again.', 'gemini-enterprise-for-cx' ),
                60
            );
            wp_safe_redirect( admin_url( 'admin.php?page=gemini-enterprise-for-cx' ) );
            exit;
        }

        if ( ! Admin::is_standard_rest_api_enabled() ) {
            set_transient(
                'gecx_admin_notice_error',
                __( 'Gemini Enterprise for CX requires pretty permalinks and the default /wp-json REST API prefix. Please enable pretty permalinks under Settings > Permalinks before connecting.', 'gemini-enterprise-for-cx' ),
                60
            );
            wp_safe_redirect( admin_url( 'admin.php?page=gemini-enterprise-for-cx' ) );
            exit;
        }

        nocache_headers();
        if ( ! headers_sent() ) {
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
            header( 'Referrer-Policy: no-referrer' );
        }

        $connect_url = $this->build_connect_agent_url();
        if ( '' === $connect_url ) {
            set_transient(
                'gecx_admin_notice_error',
                __( 'The configured Google Cloud console address is not allowed. Remove the gecx_console_base_url override and try again.', 'gemini-enterprise-for-cx' ),
                60
            );
            wp_safe_redirect( admin_url( 'admin.php?page=gemini-enterprise-for-cx' ) );
            exit;
        }
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirecting to the external Google Cloud Console URL on an allowlisted host (Auth::get_console_base_url()).
        wp_redirect( $connect_url, 302 );
        exit;
    }
}
