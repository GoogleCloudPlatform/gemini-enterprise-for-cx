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
 * Gemini Enterprise for CX agent state sync
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Admin;

use Google\Gemini_Enterprise_For_CX\Admin;
use Google\Gemini_Enterprise_For_CX\Auth;
use Google\Gemini_Enterprise_For_CX\REST\Order_Webhook;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Keeps the store's agent link in step with the Gemini Enterprise for CX
 * console: SyncState calls (throttled, and after a plugin update), the state
 * changes and admin notices they lead to, and unlinking the agent.
 */
class Console_Sync {

    /**
     * Console path that serves the SyncState reconciliation API.
     */
    private const CONSOLE_SYNC_STATE_PATH = '/woocommerce/webhook/sync-state';

    /**
     * Console path that releases this store's agent link on Google's side.
     */
    private const CONSOLE_UNLINK_AGENT_PATH = '/woocommerce/unlink-agent';

    /**
     * Minimum number of seconds between automatic agent state syncs.
     */
    private const SYNC_THROTTLE_SECONDS = 600;

    /**
     * Option holding the unix timestamp of the last attempted sync.
     */
    private const SYNC_THROTTLE_OPTION = 'gecx_sync_last_attempt';

    /**
     * Whether notices raised by a sync should be persisted for a later page
     * load instead of being rendered inline by settings_errors().
     */
    private bool $defer_notices = false;

    /**
     * Registers this component's hooks.
     */
    public function register_hooks(): void {
        add_action( 'admin_init', [ $this, 'maybe_sync_on_version_change' ] );
        add_action( Admin::VERSION_SYNC_CRON_HOOK, [ $this, 'run_scheduled_version_sync' ], 10, 1 );
        add_action( 'admin_notices', [ $this, 'show_pending_sync_notices' ] );
        add_action( 'wp_ajax_gecx_unlink_agent', [ $this, 'ajax_unlink_agent' ] );
    }

    /**
     * Trigger a SyncState reconciliation when the active plugin version changes.
     *
     * WordPress does not run register_activation_hook() during plugin upgrades.
     * Comparing GECX_VERSION against the stored gecx_plugin_version option on
     * admin_init schedules a single asynchronous WP-Cron event so the backend
     * refreshes the installation's recorded plugin version without blocking
     * unrelated admin page loads on an outbound HTTP call.
     *
     * The recorded version is only advanced once a sync actually succeeds, so a
     * backend that is unreachable during a rollout is retried on a later admin
     * page load rather than skipped until the next release.
     */
    public function maybe_sync_on_version_change(): void {
        if ( ! defined( 'GECX_VERSION' ) || '' === (string) GECX_VERSION ) {
            return;
        }

        // admin-ajax.php fires admin_init as well, so without this guard the
        // first Heartbeat tick after an upgrade would absorb the sync, put its
        // response time in front of that request, and discard anything the
        // backend reported.
        if ( wp_doing_ajax() ) {
            return;
        }

        $current_version  = (string) GECX_VERSION;
        $recorded_version = (string) get_option( Admin::PLUGIN_VERSION_OPTION, '' );
        if ( $recorded_version === $current_version ) {
            return;
        }

        $current_agent = (string) get_option( 'gecx_agent_name', '' );
        $auth_complete = (bool) get_option( Admin::AUTH_COMPLETE_OPTION, false );
        if ( ! $auth_complete && ! $this->has_existing_state( $current_agent ) ) {
            // There is nothing to reconcile yet. Record the version anyway so
            // the store's first authorization is not also treated as an
            // upgrade.
            update_option( Admin::PLUGIN_VERSION_OPTION, $current_version, false );
            return;
        }

        // Same bar as every other state changing path in this class: a sync can
        // adopt a different agent or unlink this store outright.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $last_stamp = (string) get_option( self::SYNC_THROTTLE_OPTION, '' );
        $suffix     = ':' . $current_version;
        $force      = substr( $last_stamp, -strlen( $suffix ) ) !== $suffix;
        if ( ! $force ) {
            $last_attempt = (int) strtok( $last_stamp, ':' );
            if ( $last_attempt > 0 && ( time() - $last_attempt ) < self::SYNC_THROTTLE_SECONDS ) {
                return;
            }
        }

        $admin_user_id = (int) get_current_user_id();
        if ( $admin_user_id > 0 ) {
            update_option( Admin::VERSION_SYNC_USER_OPTION, $admin_user_id, false );
        }

        // Prefer WooCommerce Action Scheduler when available so async version
        // sync still drains on admin requests even if DISABLE_WP_CRON is true.
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( Admin::VERSION_SYNC_CRON_HOOK, [], 'gecx' ) ) {
                as_enqueue_async_action( Admin::VERSION_SYNC_CRON_HOOK, [], 'gecx' );
            }
            return;
        }

        $cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        if ( ! $cron_disabled ) {
            if ( ! wp_next_scheduled( Admin::VERSION_SYNC_CRON_HOOK ) ) {
                wp_schedule_single_event( time(), Admin::VERSION_SYNC_CRON_HOOK );
            }
            return;
        }

        $this->run_scheduled_version_sync( $admin_user_id );
    }

    /**
     * Executes the version-upgrade SyncState reconciliation scheduled by
     * maybe_sync_on_version_change().
     *
     * @param int $user_id Optional administrator user ID captured when the event was scheduled.
     */
    public function run_scheduled_version_sync( int $user_id = 0 ): void {
        if ( ! defined( 'GECX_VERSION' ) || '' === (string) GECX_VERSION ) {
            return;
        }

        $current_version  = (string) GECX_VERSION;
        $recorded_version = (string) get_option( Admin::PLUGIN_VERSION_OPTION, '' );
        if ( $recorded_version === $current_version ) {
            return;
        }

        $current_agent = (string) get_option( 'gecx_agent_name', '' );
        $auth_complete = (bool) get_option( Admin::AUTH_COMPLETE_OPTION, false );
        if ( ! $auth_complete && ! $this->has_existing_state( $current_agent ) ) {
            update_option( Admin::PLUGIN_VERSION_OPTION, $current_version, false );
            delete_option( Admin::VERSION_SYNC_USER_OPTION );
            return;
        }

        if ( $user_id <= 0 ) {
            $user_id = (int) get_option( Admin::VERSION_SYNC_USER_OPTION, 0 );
        }

        $last_stamp = (string) get_option( self::SYNC_THROTTLE_OPTION, '' );
        $suffix     = ':' . $current_version;
        $force      = substr( $last_stamp, -strlen( $suffix ) ) !== $suffix;

        $this->defer_notices = true;
        try {
            $status = $this->sync_agent_state( $current_agent, $force, $user_id > 0 ? $user_id : null );
        } finally {
            $this->defer_notices = false;
        }

        if ( '' === $status ) {
            return;
        }

        update_option( Admin::PLUGIN_VERSION_OPTION, $current_version, false );
        delete_option( Admin::VERSION_SYNC_USER_OPTION );
    }

    /**
     * Whether this store still holds any trace of a link to Google.
     *
     * Recognizes installs that authorized before gecx_auth_complete existed;
     * they have no completion flag but must still reconcile.
     *
     * @param string $current_agent Currently configured agent resource name.
     */
    private function has_existing_state( string $current_agent ): bool {
        return '' !== $current_agent
            || ! empty( get_option( 'gecx_webhook_id' ) );
    }

    /**
     * Message and severity for a notice raised by a SyncState reconciliation.
     *
     * Deferred notices are stored by code rather than by text so the message is
     * always rendered in the locale of the request that displays it.
     *
     * @param string $code Notice code.
     * @return array{message: string, type: string}|null Null for unknown codes.
     */
    private function sync_notice( string $code ): ?array {
        switch ( $code ) {
            case 'gecx_sync_jwt_invalid':
                return [
                    'message' => __(
                        'Google could not verify the identity of this store. Authorize your store again to restore the assistant.',
                        'gemini-enterprise-for-cx'
                    ),
                    'type'    => 'warning',
                ];
            case 'gecx_sync_api_keys_invalid':
                return [
                    'message' => __(
                        'Google can no longer read your store catalog with the saved WooCommerce API keys. Authorize your store again to issue new keys.',
                        'gemini-enterprise-for-cx'
                    ),
                    'type'    => 'warning',
                ];
            case 'gecx_agent_adopted':
                return [
                    'message' => __(
                        'This store is connected to a different agent than the one saved here, so the saved agent was updated to match Google.',
                        'gemini-enterprise-for-cx'
                    ),
                    'type'    => 'warning',
                ];
            case 'gecx_agent_unlinked':
                return [
                    'message' => __(
                        'The agent was automatically unlinked because Google no longer has a connection for this store. Please reconnect your store to restore the assistant.',
                        'gemini-enterprise-for-cx'
                    ),
                    'type'    => 'warning',
                ];
        }

        return null;
    }

    /**
     * Raise a notice produced by a SyncState reconciliation.
     *
     * On the settings page settings_errors() renders these inline. A sync
     * started from admin_init runs on a screen that never calls it, so the code
     * is persisted instead and shown by show_pending_sync_notices().
     *
     * @param string $code Notice code understood by sync_notice().
     */
    private function add_sync_notice( string $code ): void {
        $notice = $this->sync_notice( $code );
        if ( null === $notice ) {
            return;
        }

        if ( ! $this->defer_notices ) {
            add_settings_error( 'gecx_messages', $code, $notice['message'], $notice['type'] );
            return;
        }

        $pending = get_option( Admin::PENDING_NOTICES_OPTION, [] );
        if ( ! is_array( $pending ) ) {
            $pending = [];
        }
        if ( in_array( $code, $pending, true ) ) {
            return;
        }

        $pending[] = $code;
        update_option( Admin::PENDING_NOTICES_OPTION, $pending, false );
    }

    /**
     * Render notices left behind by a sync that ran outside the settings page.
     */
    public function show_pending_sync_notices(): void {
        $pending = get_option( Admin::PENDING_NOTICES_OPTION, [] );
        if ( ! is_array( $pending ) || empty( $pending ) ) {
            return;
        }

        // Keep them queued until someone who can act on them is looking.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        delete_option( Admin::PENDING_NOTICES_OPTION );

        foreach ( $pending as $code ) {
            $notice = $this->sync_notice( (string) $code );
            if ( null === $notice ) {
                continue;
            }
            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr( $notice['type'] ),
                esc_html( $notice['message'] )
            );
        }
    }

    /**
     * Claim the sync throttle window for this request.
     *
     * render_settings_page() is a synchronous request, so an unhealthy backend
     * would otherwise add its full timeout to TTFB on every load. add_option()
     * is a single INSERT against a uniquely indexed column, so the first claim
     * is atomic even on installs without a persistent object cache (where
     * wp_cache_add() is only per-request and transients fall back to options
     * with a non-atomic read-then-write).
     *
     * @param bool $force Ignore an unexpired window. The window is still
     *                    stamped, so a forced sync does not leave the next
     *                    request free to sync again immediately.
     * @return bool True when this request owns the window and should sync.
     */
    private function claim_sync_window( bool $force = false ): bool {
        $now   = time();
        $stamp = defined( 'GECX_VERSION' ) && '' !== (string) GECX_VERSION
            ? $now . ':' . (string) GECX_VERSION
            : (string) $now;

        // Autoload is off: this option is only read on the settings page.
        if ( add_option( self::SYNC_THROTTLE_OPTION, $stamp, '', false ) ) {
            return true;
        }

        $last_stamp = (string) get_option( self::SYNC_THROTTLE_OPTION, '' );
        $last       = '' !== $last_stamp ? (int) strtok( $last_stamp, ':' ) : 0;
        if ( ! $force && $now - $last < self::SYNC_THROTTLE_SECONDS ) {
            return false;
        }

        // The window has elapsed, or a caller is forcing past it. This refresh
        // is not atomic, so two requests racing on the same expiry can both
        // sync once; the endpoint is idempotent and the throttle then applies
        // again.
        update_option( self::SYNC_THROTTLE_OPTION, $stamp, false );
        return true;
    }

    /**
     * Release the throttle window so the next page load syncs immediately.
     */
    public static function clear_sync_window(): void {
        delete_option( self::SYNC_THROTTLE_OPTION );
    }

    /**
     * Log a sync diagnostic when debug logging is enabled.
     *
     * Never pass the admin JWT or a raw response body to this method.
     *
     * @param string $message Diagnostic message.
     */
    private function log_sync( string $message ): void {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }
        Auth::log( 'sync-state: ' . $message, 'debug' );
    }

    /**
     * Decode a SyncState response body.
     *
     * The endpoint returns proto3 JSON behind the `)]}'` XSSI guard, which has
     * to be stripped before the remainder parses as JSON.
     *
     * @param string $body Raw response body.
     * @return array<string, mixed>|null Decoded object, or null if unusable.
     */
    private function decode_sync_response( string $body ): ?array {
        $body = ltrim( $body );
        if ( 0 === strpos( $body, ")]}'" ) ) {
            $body = (string) substr( $body, 4 );
        }

        $data = json_decode( $body, true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Read the sync status out of a decoded response.
     *
     * Accepts both the lowerCamelCase and the original proto field names, and
     * both the enum name and its numeric value, so the plugin does not depend
     * on which JSON printer the backend uses.
     *
     * @param array<string, mixed> $data Decoded response.
     * @return string Enum value name, or '' when absent or unrecognized.
     */
    private function normalize_sync_status( array $data ): string {
        $raw = $data['syncStatus'] ?? $data['sync_status'] ?? '';

        if ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) {
            $names = [
                0 => 'WOOCOMMERCE_SYNC_STATUS_UNSPECIFIED',
                1 => 'WOOCOMMERCE_SYNC_STATUS_SYNCED',
                2 => 'WOOCOMMERCE_SYNC_STATUS_JWT_AUTH_INVALID',
                3 => 'WOOCOMMERCE_SYNC_STATUS_WOOCOMMERCE_API_KEYS_INVALID',
                4 => 'WOOCOMMERCE_SYNC_STATUS_LINK_REQUIRED',
            ];
            return $names[ (int) $raw ] ?? '';
        }

        return is_string( $raw ) ? $raw : '';
    }

    /**
     * Reconcile the local agent binding with the backend via the SyncState API.
     *
     * Throttled. The window is claimed immediately before the HTTP call so a
     * local configuration problem does not consume it.
     *
     * @param string   $current_agent Currently configured agent resource name.
     * @param bool     $force         Sync even inside an unexpired throttle
     *                                window. Used after an upgrade, which must
     *                                reconcile promptly.
     * @param int|null $user_id       Optional administrator user ID for signing
     *                                when running outside a logged-in session
     *                                (e.g. WP-Cron).
     * @return string The status reported by the backend, or '' when no usable
     *                response was obtained.
     */
    public function sync_agent_state( string $current_agent, bool $force = false, ?int $user_id = null ): string {
        $auth_complete = (bool) get_option( Admin::AUTH_COMPLETE_OPTION, false );
        if ( ! $auth_complete && $this->has_existing_state( $current_agent ) ) {
            update_option( Admin::AUTH_COMPLETE_OPTION, 1, 'no' );
            $auth_complete = true;
        }

        if ( ! $auth_complete ) {
            $this->log_sync( 'skipped, store has not completed authorization' );
            return '';
        }

        $admin_jwt = Auth::generate_admin_jwt( $user_id )
            ?? Auth::generate_existing_rs256_admin_jwt( $user_id );
        if ( empty( $admin_jwt ) ) {
            $this->log_sync( 'skipped, admin JWT unavailable' );
            return '';
        }

        // get_console_base_url() returns '' unless the configured value is an
        // https origin on an allowed host. sslverify is intentionally left at
        // its default and must stay there.
        $console_base = Auth::get_console_base_url();
        if ( '' === $console_base ) {
            $this->log_sync( 'skipped, console base URL refused' );
            return '';
        }

        if ( ! $this->claim_sync_window( $force ) ) {
            return '';
        }

        // admin_jwt is sent as `Authorization: Bearer <token>` rather than in
        // the JSON body, so it is not captured by anything that logs or
        // persists request bodies. WooCommerceSyncStateAction reads the header
        // only when the body carries no admin_jwt.
        // wp_safe_remote_post() rather than wp_remote_post(): the destination
        // comes from an option and a filter, so it validates the resolved host
        // against the private and loopback ranges. redirection 0 because the
        // request carries a store-signed admin JWT and a 30x would hand it to
        // whatever host the redirect names, unvalidated.
        $response = wp_safe_remote_post(
            $console_base . self::CONSOLE_SYNC_STATE_PATH,
            [
                'headers'     => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $admin_jwt,
                ],
                'body'        => wp_json_encode(
                    [
                        'expected_agent_id' => $current_agent,
                    ]
                ),
                'timeout'     => 3,
                'redirection' => 0,
                // The handler reads a handful of short string fields, so
                // anything larger than this is not a response worth buffering.
                'limit_response_size' => 10240,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $this->log_sync( 'transport error: ' . $response->get_error_code() );
            return '';
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            $this->log_sync( 'unexpected HTTP status ' . $code );
            return '';
        }

        $data = $this->decode_sync_response( (string) wp_remote_retrieve_body( $response ) );
        if ( null === $data ) {
            $this->log_sync( 'HTTP 200 with a body that is not decodable JSON' );
            return '';
        }

        $status = $this->normalize_sync_status( $data );
        if ( '' === $status ) {
            $this->log_sync( 'HTTP 200 with no recognizable sync status' );
            return '';
        }

        $this->apply_sync_status( $status, $data, $current_agent );
        if ( defined( 'GECX_VERSION' ) && '' !== (string) GECX_VERSION ) {
            update_option( Admin::PLUGIN_VERSION_OPTION, (string) GECX_VERSION, false );
        }
        delete_option( Admin::VERSION_SYNC_USER_OPTION );
        return $status;
    }

    /**
     * Act on a reconciled SyncState response.
     *
     * @param string               $status        Normalized status enum name.
     * @param array<string, mixed> $data          Decoded response.
     * @param string               $current_agent Locally configured agent.
     */
    private function apply_sync_status( string $status, array $data, string $current_agent ): void {
        // The response echoes the shop the backend resolved from the JWT
        // issuer. Refuse to mutate local state if it is not this store.
        $shop_domain = (string) ( $data['shopDomain'] ?? $data['shop_domain'] ?? '' );
        $this_store  = Auth::get_sanitized_store_domain();
        if ( '' !== $shop_domain && '' !== $this_store
            && 0 !== strcasecmp( $shop_domain, $this_store ) ) {
            $this->log_sync( 'response shop domain does not match this store, ignoring' );
            return;
        }

        if ( 'WOOCOMMERCE_SYNC_STATUS_JWT_AUTH_INVALID' === $status ) {
            // Re-running the WooCommerce authorization issues fresh API keys,
            // which is also how Google re-reads this store's public key, so
            // send the merchant back to that step instead of only warning.
            update_option( Admin::STORE_AUTH_INVALID_OPTION, 1 );
            $this->add_sync_notice( 'gecx_sync_jwt_invalid' );
            return;
        }

        if ( 'WOOCOMMERCE_SYNC_STATUS_WOOCOMMERCE_API_KEYS_INVALID' === $status ) {
            update_option( Admin::STORE_AUTH_INVALID_OPTION, 1 );
            $this->add_sync_notice( 'gecx_sync_api_keys_invalid' );
            return;
        }

        $actual        = (string) ( $data['actualLinkedAgentId'] ?? $data['actual_linked_agent_id'] ?? '' );
        $actual_broker = (string) ( $data['tokenBrokerName'] ?? $data['token_broker_name'] ?? '' );

        // Same allowlist GECX_Rest_Console_API::link_agent_handler() applies to the
        // values a caller supplies, applied here to the values the backend
        // reports. Both end up in the same two options and in the widget's
        // agent-name and token-broker attributes, so both have to mean the
        // same thing. Validated raw and rejected, never sanitized into shape:
        // stripping characters would turn a name this store should refuse into
        // one it silently adopts.
        if ( '' !== $actual && ! preg_match( Auth::RESOURCE_NAME_PATTERN, $actual ) ) {
            $this->log_sync( 'response agent id is not a valid resource name, ignoring' );
            return;
        }
        if ( '' !== $actual_broker && ! preg_match( Auth::RESOURCE_NAME_PATTERN, $actual_broker ) ) {
            $this->log_sync( 'response token broker is not a valid resource name, ignoring' );
            return;
        }

        // Once the backend reports no linked agent, the unlink the merchant
        // asked for has landed on Google's side. Any agent reported after that
        // comes from a new LinkAgent call, which is the merchant linking again
        // from the console; LinkAgent pushes link-agent to this store only
        // best-effort and relies on SyncState when that push fails, so the
        // flag must not outlive the unlink it guards.
        if ( '' === $actual && Admin::is_merchant_unlinked()
            && in_array( $status, [ 'WOOCOMMERCE_SYNC_STATUS_SYNCED', 'WOOCOMMERCE_SYNC_STATUS_LINK_REQUIRED' ], true ) ) {
            delete_option( Admin::MERCHANT_UNLINKED_OPTION );
            $this->log_sync( 'backend confirms no linked agent, clearing unlink flag' );
        }

        if ( 'WOOCOMMERCE_SYNC_STATUS_SYNCED' === $status ) {
            // Whatever the backend objected to before is resolved.
            delete_option( Admin::STORE_AUTH_INVALID_OPTION );

            // SYNCED is decided by comparing the bare agent id, so a store that
            // was linked before the backend started returning canonical
            // resource names still matches here while holding the short form
            // locally. The backend reports the canonical name and the broker on
            // this path too, and a SYNCED store never reaches the adopt branch
            // below, so this is the only chance to pick them up.
            if ( Admin::is_merchant_unlinked() ) {
                return;
            }
            if ( '' !== $actual && $actual !== $current_agent ) {
                update_option( 'gecx_agent_name', $actual );
            }
            if ( '' !== $actual_broker ) {
                update_option( 'gecx_token_broker_name', $actual_broker );
            }
            return;
        }

        if ( 'WOOCOMMERCE_SYNC_STATUS_LINK_REQUIRED' !== $status ) {
            return;
        }

        // LINK_REQUIRED only means the local binding disagrees with the
        // backend, not that the store is unlinked. Reaching this point means
        // the backend already accepted the JWT and the API keys, so whatever it
        // objected to previously is resolved.
        delete_option( Admin::STORE_AUTH_INVALID_OPTION );

        // The merchant unlinked this store and the backend has not yet
        // confirmed it holds no link, so what it reports is the stale binding,
        // not consent to link again.
        if ( '' !== $actual && Admin::is_merchant_unlinked() ) {
            $this->log_sync( 'backend reports an agent the merchant unlinked, not adopting' );
            return;
        }

        // The backend reports the agent it actually holds, so adopt it when
        // there is one.
        if ( '' !== $actual ) {
            $current_broker    = (string) get_option( 'gecx_token_broker_name', '' );
            $current_enabled   = (int) get_option( 'gecx_agent_enabled', 0 );
            $merchant_disabled = (bool) get_option( Admin::MERCHANT_DISABLED_OPTION, false );
            if ( $actual === $current_agent && $actual_broker === $current_broker
                && ( 1 === $current_enabled || $merchant_disabled ) ) {
                return;
            }

            update_option( 'gecx_agent_name', $actual );

            // An automatic unlink disables the widget and pauses/deletes the
            // order webhook. Adopting a link the backend still holds has to
            // re-enable both, or the store looks connected in wp-admin while
            // the storefront stays dark and order attribution stops firing.
            // A merchant who switched the widget off keeps it off: the
            // backend's view of the binding is not consent to resume sending
            // orders.
            if ( ! $merchant_disabled ) {
                update_option( 'gecx_agent_enabled', 1 );
                Order_Webhook::set_order_webhook_status( 'active' );
            }
            delete_option( 'gecx_dismiss_activation_notice' );

            if ( '' !== $actual_broker ) {
                update_option( 'gecx_token_broker_name', $actual_broker );
            } else {
                // The broker belonged to the previous agent. Keeping it would
                // hand the storefront widget a token broker for an agent this
                // store is no longer linked to.
                delete_option( 'gecx_token_broker_name' );
            }

            // Only a genuine swap is worth warning about. Recovering a binding
            // the store had lost is not something the merchant did wrong.
            if ( '' !== $current_agent && $current_agent !== $actual ) {
                $this->add_sync_notice( 'gecx_agent_adopted' );
            }

            return;
        }

        if ( '' === $current_agent ) {
            return;
        }

        // The backend holds no link for this store. Clear the binding; the
        // merchant's appearance settings survive so a re-link keeps them.
        $this->unlink_agent_internal();
        self::clear_sync_window();

        $this->add_sync_notice( 'gecx_agent_unlinked' );
    }

    /**
     * Delete the locally stored agent binding while preserving store
     * authorization credentials so the merchant returns to Step 2 rather than
     * Step 1.
     *
     * The order webhook is paused rather than deleted so its stored
     * consumer_secret remains available and re-linking an agent in Step 2 can
     * immediately reactivate it. The merchant's appearance settings are also
     * left in place so a re-link does not lose their customization.
     */
    private function unlink_agent_internal(): void {
        Order_Webhook::set_order_webhook_status( 'paused' );
        // Legacy shared secret, retired in favour of the store's RSA keypair.
        // Still deleted so a store upgraded from an older version does not keep
        // the row around after disconnecting.
        delete_option( 'gecx_api_secret' );
        delete_option( Admin::STORE_AUTH_INVALID_OPTION );
        delete_option( 'gecx_agent_name' );
        delete_option( 'gecx_token_broker_name' );
        update_option( 'gecx_agent_enabled', 0 );

        // Always re-arm the activation notice. The store now has no agent, and
        // that notice is the only prompt shown outside the settings page.
        delete_option( 'gecx_dismiss_activation_notice' );
    }

    /**
     * Release this store's agent link on Google's side via POST /woocommerce/unlink-agent.
     *
     * When the console base URL is refused, nothing this store sends can
     * reach Google, so there is nothing to wait for: the unlink proceeds
     * locally and the unlinked flag keeps a later SyncState from undoing it.
     * A transport error, 5xx or missing admin JWT still fails the unlink so
     * the merchant can retry.
     *
     * @param string $agent_id Agent the store currently believes it is linked to.
     * @return bool True when Google confirms the store is no longer linked, or
     *              when the console base URL is refused.
     */
    private function unlink_agent_remotely( string $agent_id ): bool {
        $console_base = Auth::get_console_base_url();
        if ( '' === $console_base ) {
            $this->log_sync( 'unlink not sent, console base URL refused; unlinking locally' );
            return true;
        }

        $admin_jwt = Auth::generate_admin_jwt();
        if ( empty( $admin_jwt ) ) {
            $this->log_sync( 'unlink skipped, admin JWT unavailable' );
            return false;
        }

        // Same reasoning as sync_agent_state() above.
        $response = wp_safe_remote_post(
            $console_base . self::CONSOLE_UNLINK_AGENT_PATH,
            [
                'headers'     => [
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $admin_jwt,
                ],
                'body'        => wp_json_encode(
                    [
                        'agent_id' => $agent_id,
                    ]
                ),
                // Allow enough headroom for VerifyAdminJwtWithSelfHealing to
                // fetch /wp-json/gecx/v1/public-key if the store rotated keys.
                'timeout'     => 10,
                'redirection' => 0,
                // Same reasoning as sync_agent_state(): the response is a
                // short status document, so cap what will be buffered.
                'limit_response_size' => 10240,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $this->log_sync( 'unlink transport error: ' . $response->get_error_code() );
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        // 404 (NotFound: installation absent), 403 (PermissionDenied: store is
        // already linked to a different agent or already unlinked), and 400
        // (InvalidArgument: local gecx_agent_name references a stale project)
        // all confirm Google does not link this store to $agent_id. Treat them
        // as unlinked rather than stranding the merchant on a 502 error.
        if ( in_array( $code, [ 400, 403, 404 ], true ) ) {
            return true;
        }

        if ( $code < 200 || $code > 299 ) {
            $this->log_sync( 'unlink got unexpected HTTP status ' . $code );
            return false;
        }

        return true;
    }

    /**
     * AJAX handler to unlink the agent and reset store status.
     *
     * Store authorization and the WooCommerce API keys are kept, so the
     * merchant returns to Step 2 and can link an agent again without
     * re-authorizing. Unlike the automatic unlink in apply_sync_status(), this
     * records the merchant's intent so no later SyncState response can quietly
     * re-link the store.
     */
    public function ajax_unlink_agent(): void {
        if ( false === check_ajax_referer( 'gecx_save_agent_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce', 'gemini-enterprise-for-cx' ) ], 403 );
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'gemini-enterprise-for-cx' ) ], 403 );
            return;
        }

        $agent_id = (string) get_option( 'gecx_agent_name', '' );
        if ( '' !== $agent_id && ! $this->unlink_agent_remotely( $agent_id ) ) {
            wp_send_json_error(
                [
                    'message' => __(
                        'The agent could not be disconnected from Google. Nothing was changed. Please try again.',
                        'gemini-enterprise-for-cx'
                    ),
                ],
                502
            );
            return;
        }

        $this->unlink_agent_internal();
        delete_option( Admin::MERCHANT_DISABLED_OPTION );
        update_option( Admin::MERCHANT_UNLINKED_OPTION, 1, false );

        // Re-linking right after an unlink must reconcile immediately.
        self::clear_sync_window();
        wp_send_json_success();
    }
}
