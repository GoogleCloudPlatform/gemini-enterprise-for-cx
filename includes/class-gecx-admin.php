<?php
/**
 * Gemini Enterprise for CX Admin Handler
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class GECX_Admin {

    /**
     * Console path that receives the WooCommerce OAuth callback.
     */
    private const CONSOLE_WOO_AUTH_WEBHOOK_PATH = '/woocommerce/webhook/woo-auth';

    /**
     * Console path that hosts the agent connection UI.
     */
    private const CONSOLE_APP_PATH = '/woocommerce/app';

    /**
     * Console path that serves the SyncState reconciliation API.
     */
    private const CONSOLE_SYNC_STATE_PATH = '/woocommerce/webhook/sync-state';

    /**
     * Console path that releases this store's agent link on Google's side.
     */
    private const CONSOLE_UNLINK_AGENT_PATH = '/woocommerce/unlink-agent';

    /**
     * Characters permitted in an agent resource name or token broker name.
     *
     * Duplicated from GECX_Rest_API rather than shared. The two classes load
     * independently and either can be the only one present, so a shared
     * constant would introduce a load-order dependency for a one-line regex.
     * Change both together.
     */
    private const RESOURCE_NAME_PATTERN = '/^[a-zA-Z0-9_\-\.\/]+$/';

    /**
     * Minimum number of seconds between automatic agent state syncs.
     */
    private const SYNC_THROTTLE_SECONDS = 600;

    /**
     * Option holding the unix timestamp of the last attempted sync.
     */
    private const SYNC_THROTTLE_OPTION = 'gecx_sync_last_attempt';

    /**
     * Option set when Google reports that it can no longer use this store's
     * credentials.
     *
     * The WooCommerce API keys live on the Google side, so there is nothing
     * local to revoke. This flag makes the settings page offer the authorize
     * step again and is cleared once the store is authorized. The order
     * webhook is left alone so re-authorizing reuses it instead of creating a
     * duplicate.
     */
    public const STORE_AUTH_INVALID_OPTION = 'gecx_store_auth_invalid';

    /**
     * Option set once the merchant has authorized or connected the store.
     *
     * Gating sync_agent_state() on this option ensures no outbound SyncState
     * calls or admin JWT transmissions occur before the merchant grants consent
     * on a fresh activation, while still allowing an already-authorized store
     * to recover its binding via SyncState if gecx_agent_name is deleted.
     */
    public const AUTH_COMPLETE_OPTION = 'gecx_auth_complete';

    /**
     * Option storing the last observed plugin version so upgrades trigger an
     * immediate SyncState reconciliation.
     */
    public const PLUGIN_VERSION_OPTION = 'gecx_plugin_version';

    /**
     * Option holding the codes of sync notices that were raised while no
     * screen was available to render them.
     */
    public const PENDING_NOTICES_OPTION = 'gecx_pending_sync_notices';

    /**
     * Action hook used by WP-Cron to execute version upgrade SyncState
     * reconciliation asynchronously without blocking admin_init.
     */
    public const VERSION_SYNC_CRON_HOOK = 'gecx_scheduled_version_sync';

    /**
     * Non-autoloaded option holding the administrator user ID that triggered
     * the pending version upgrade sync.
     */
    public const VERSION_SYNC_USER_OPTION = 'gecx_version_sync_user_id';

    /**
     * Triggered upon plugin activation.
     */
    public static function activate_plugin(): void {
        update_option( 'gecx_do_activation_redirect', true );
        delete_option( 'gecx_dismiss_activation_notice' );
        GECX_Auth::get_or_generate_keypair();
        if ( class_exists( 'GECX_Rest_API' ) ) {
            GECX_Rest_API::reconcile_webhook_on_activation();
        }
    }

    /**
     * Triggered upon plugin deactivation.
     * Pauses the order webhook so no orders are transmitted while deactivated.
     */
    public static function deactivate_plugin(): void {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( self::VERSION_SYNC_CRON_HOOK );
        }
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::VERSION_SYNC_CRON_HOOK, [], 'gecx' );
        }
        if ( class_exists( 'GECX_Rest_API' ) ) {
            GECX_Rest_API::set_order_webhook_status( 'paused' );
        }
    }

    /**
     * Path to the main plugin file.
     */
    private string $plugin_file;

    /**
     * The settings page hook suffix.
     */
    private string $settings_page_hook = '';

    /**
     * Whether notices raised by a sync should be persisted for a later page
     * load instead of being rendered inline by settings_errors().
     */
    private bool $defer_notices = false;

    /**
     * Product IDs already processed by save_product_prompts_override_field()
     * in the current request so dual WooCommerce save hooks write once.
     *
     * @var array<int, bool>
     */
    private array $saved_product_prompt_ids = [];

    /**
     * Constructor.
     *
     * @param string $plugin_file Path to the main plugin file.
     */
    public function __construct( string $plugin_file ) {
        $this->plugin_file = $plugin_file;

        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_init', [ $this, 'redirect_on_activation' ] );
        add_action( 'admin_init', [ $this, 'handle_connection_callback' ] );
        add_action( 'admin_init', [ $this, 'maybe_sync_on_version_change' ] );
        add_action( self::VERSION_SYNC_CRON_HOOK, [ $this, 'run_scheduled_version_sync' ], 10, 1 );
        add_action( 'admin_post_gecx_connect_agent', [ $this, 'handle_connect_agent_redirect' ] );
        add_action( 'admin_notices', [ $this, 'show_activation_notice' ] );
        add_action( 'admin_notices', [ $this, 'show_pending_sync_notices' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Register product settings override hooks (WooCommerce CRUD + legacy post meta fallback).
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_product_prompts_override_field' ] );
        add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_product_prompts_override_field' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_prompts_override_field' ] );

        // Register plugin action link next to Deactivate.
        add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file ), [ $this, 'add_plugin_action_links' ] );

        // Register AJAX handlers for saving agent details and embed state.
        add_action( 'wp_ajax_gecx_save_button_config', [ $this, 'ajax_save_button_config' ] );
        add_action( 'wp_ajax_gecx_toggle_app_embed', [ $this, 'ajax_toggle_app_embed' ] );
        add_action( 'wp_ajax_gecx_toggle_pdp_prompts', [ $this, 'ajax_toggle_pdp_prompts' ] );
        add_action( 'wp_ajax_gecx_unlink_agent', [ $this, 'ajax_unlink_agent' ] );
        add_action( 'wp_ajax_gecx_dismiss_notice', [ $this, 'ajax_dismiss_notice' ] );
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
        $recorded_version = (string) get_option( self::PLUGIN_VERSION_OPTION, '' );
        if ( $recorded_version === $current_version ) {
            return;
        }

        $current_agent = (string) get_option( 'gecx_agent_name', '' );
        $auth_complete = (bool) get_option( self::AUTH_COMPLETE_OPTION, false );
        if ( ! $auth_complete && ! $this->has_existing_state( $current_agent ) ) {
            // There is nothing to reconcile yet. Record the version anyway so
            // the store's first authorization is not also treated as an
            // upgrade.
            update_option( self::PLUGIN_VERSION_OPTION, $current_version, false );
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

        $admin_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $admin_user_id > 0 ) {
            update_option( self::VERSION_SYNC_USER_OPTION, $admin_user_id, false );
        }

        // Prefer WooCommerce Action Scheduler when available so async version
        // sync still drains on admin requests even if DISABLE_WP_CRON is true.
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::VERSION_SYNC_CRON_HOOK, [], 'gecx' ) ) {
                as_enqueue_async_action( self::VERSION_SYNC_CRON_HOOK, [], 'gecx' );
            }
            return;
        }

        $cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        if ( ! $cron_disabled && function_exists( 'wp_schedule_single_event' ) ) {
            if ( ! function_exists( 'wp_next_scheduled' ) || ! wp_next_scheduled( self::VERSION_SYNC_CRON_HOOK ) ) {
                wp_schedule_single_event( time(), self::VERSION_SYNC_CRON_HOOK );
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
        $recorded_version = (string) get_option( self::PLUGIN_VERSION_OPTION, '' );
        if ( $recorded_version === $current_version ) {
            return;
        }

        $current_agent = (string) get_option( 'gecx_agent_name', '' );
        $auth_complete = (bool) get_option( self::AUTH_COMPLETE_OPTION, false );
        if ( ! $auth_complete && ! $this->has_existing_state( $current_agent ) ) {
            update_option( self::PLUGIN_VERSION_OPTION, $current_version, false );
            delete_option( self::VERSION_SYNC_USER_OPTION );
            return;
        }

        if ( $user_id <= 0 ) {
            $user_id = (int) get_option( self::VERSION_SYNC_USER_OPTION, 0 );
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

        update_option( self::PLUGIN_VERSION_OPTION, $current_version, false );
        delete_option( self::VERSION_SYNC_USER_OPTION );
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

        $pending = get_option( self::PENDING_NOTICES_OPTION, [] );
        if ( ! is_array( $pending ) ) {
            $pending = [];
        }
        if ( in_array( $code, $pending, true ) ) {
            return;
        }

        $pending[] = $code;
        update_option( self::PENDING_NOTICES_OPTION, $pending, false );
    }

    /**
     * Render notices left behind by a sync that ran outside the settings page.
     */
    public function show_pending_sync_notices(): void {
        $pending = get_option( self::PENDING_NOTICES_OPTION, [] );
        if ( ! is_array( $pending ) || empty( $pending ) ) {
            return;
        }

        // Keep them queued until someone who can act on them is looking.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        delete_option( self::PENDING_NOTICES_OPTION );

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
            if ( ! defined( 'GECX_PHPUNIT_RUNNING' ) || ! GECX_PHPUNIT_RUNNING ) {
                exit;
            }
            return;
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
        update_option( self::AUTH_COMPLETE_OPTION, 1, 'no' );

        // Release the sync throttle window so the landing page reconciles
        // immediately with Cloud if the direct webhook has not arrived yet.
        $this->clear_sync_window();

        // Nothing is persisted from this request. Google Cloud records the link
        // against the merchant's project and reports it to gecx/v1/link-agent with the
        // store's own API credentials, so by the time the browser lands here the
        // agent name is already stored. agent_name and token_broker_name may be
        // present in the query string for the merchant's benefit; they are not
        // read, because this request cannot be authenticated -- the state that
        // got us here travelled off-site inside return_url.
        wp_safe_redirect( admin_url( 'admin.php?page=gemini-enterprise-for-cx&connected=1' ) );
        if ( ! defined( 'GECX_PHPUNIT_RUNNING' ) || ! GECX_PHPUNIT_RUNNING ) {
            exit;
        }
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
        );

        wp_enqueue_script(
            'gecx-admin-js',
            plugins_url( 'assets/js/admin.js', $this->plugin_file ),
            [ 'jquery' ],
            $admin_js_ver,
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations(
                'gecx-admin-js',
                'gemini-enterprise-for-cx',
                plugin_dir_path( $this->plugin_file ) . 'languages'
            );
        }

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
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( 'gecx_agent_group', 'gecx_agent_enabled', [
            'sanitize_callback' => 'absint',
        ] );
        register_setting( 'gecx_agent_group', 'gecx_pdp_prompts_enabled', [
            'sanitize_callback' => 'absint',
        ] );
        register_setting( 'gecx_agent_group', 'gecx_button_placement', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( 'gecx_agent_group', 'gecx_floating_position', [
            'sanitize_callback' => [ GECX_Storefront::class, 'sanitize_floating_position' ],
        ] );
        register_setting( 'gecx_agent_group', 'gecx_button_display_style', [
            'sanitize_callback' => 'sanitize_text_field',
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
     * Get console base URL.
     */
    private function get_console_base_url(): string {
        $console_base_option = get_option( 'gecx_console_base_url', 'https://gecx.cloud.google.com' );
        return (string) apply_filters( 'gecx_console_base_url', (string) $console_base_option );
    }

    /**
     * Mint a fresh OAuth state + admin JWT and build the Google Cloud Console
     * connection URL only when the merchant clicks "Connect with Google Cloud".
     *
     * Keeping this out of `render_settings_page()` prevents every GET load of
     * the settings screen from writing transients/options and prevents the
     * short-lived `admin_jwt` from sitting in the rendered DOM `<a href>`.
     */
    public function build_connect_agent_url(): string {
        $console_base = untrailingslashit( $this->get_console_base_url() );

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

        $has_store_credentials = ! empty( get_option( 'gecx_webhook_id' ) );
        $is_authorized         = $has_store_credentials && ! get_option( self::STORE_AUTH_INVALID_OPTION, false );
        $admin_jwt             = $is_authorized ? GECX_Auth::generate_admin_jwt() : '';

        // rawurlencode() is required here: WordPress core's add_query_arg()
        // delegates to _http_build_query(..., false), which does NOT URL-encode
        // parameter values (unlike PHP's http_build_query()). Without
        // rawurlencode(), the '&' separators inside $return_url are interpreted
        // as outer query parameters by the console URL parser, truncating
        // return_url and losing gecx_action and state.
        $connect_params = [
            'return_url' => rawurlencode( $return_url ),
        ];
        if ( ! empty( $admin_jwt ) ) {
            $connect_params['admin_jwt'] = rawurlencode( $admin_jwt );
        }

        return add_query_arg(
            $connect_params,
            $console_base . self::CONSOLE_APP_PATH
        );
    }

    /**
     * Handles the POST submission from the "Connect with Google Cloud" button
     * (`admin-post.php?action=gecx_connect_agent`).
     */
    public function handle_connect_agent_redirect(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            if ( function_exists( 'wp_die' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'gemini-enterprise-for-cx' ), 403 );
            }
            return;
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
            if ( ! defined( 'GECX_PHPUNIT_RUNNING' ) || ! GECX_PHPUNIT_RUNNING ) {
                exit;
            }
            return;
        }

        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        if ( ! function_exists( 'headers_sent' ) || ! headers_sent() ) {
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
            header( 'Referrer-Policy: no-referrer' );
        }

        $connect_url = $this->build_connect_agent_url();
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirecting to the external Google Cloud Console URL built from get_console_base_url().
        wp_redirect( $connect_url, 302 );
        if ( ! defined( 'GECX_PHPUNIT_RUNNING' ) || ! GECX_PHPUNIT_RUNNING ) {
            exit;
        }
    }

    /**
     * Render the native settings page.
     */
    public function render_settings_page(): void {
        $current_agent = (string) get_option( 'gecx_agent_name', '' );

        // Re-sync the binding status against the backend. This runs even when no
        // agent is configured locally: the backend reports LINK_REQUIRED with a
        // non-empty actual_linked_agent_id when it still holds a link, which is
        // how a store that has drifted recovers its binding.
        // Throttled so a slow backend cannot inflate TTFB on every page load.
        $this->sync_agent_state( $current_agent );
        // Reload agent in case the sync adopted or cleared the binding.
        $current_agent = (string) get_option( 'gecx_agent_name', '' );

        $token_broker          = (string) get_option( 'gecx_token_broker_name', '' );
        $embed_enabled         = (bool) get_option( 'gecx_agent_enabled', 0 );
        $pdp_prompts_enabled   = (bool) get_option( 'gecx_pdp_prompts_enabled', 1 );
        $button_placement      = (string) get_option( 'gecx_button_placement', 'nav_menu' );
        $floating_position     = (string) get_option( 'gecx_floating_position', 'bottom_center' );
        $display_style         = (string) get_option( 'gecx_button_display_style', 'responsive' );
        $button_label          = (string) get_option( 'gecx_button_label', '' );
        $button_short_label    = (string) get_option( 'gecx_button_short_label', '' );
        $button_enable_shimmer = (bool) get_option( 'gecx_button_enable_shimmer', 1 );

        $console_base = $this->get_console_base_url();
        $console_base = untrailingslashit( $console_base );

        $has_store_credentials = ! empty( get_option( 'gecx_webhook_id' ) );
        // SyncState can report that Google can no longer use this store's
        // credentials. Treat that as unauthorized so the merchant is offered
        // the authorize step again instead of a dead end.
        $is_authorized = $has_store_credentials && ! get_option( self::STORE_AUTH_INVALID_OPTION, false );

        $oauth_return_url = add_query_arg(
            [
                'authorized' => '1',
            ],
            admin_url( 'admin.php?page=gemini-enterprise-for-cx' )
        );
        $oauth_callback_url = $console_base . self::CONSOLE_WOO_AUTH_WEBHOOK_PATH;
        $oauth_url = add_query_arg(
            [
                'app_name'     => rawurlencode( 'Gemini Enterprise For CX' ),
                'scope'        => 'read_write',
                'user_id'      => rawurlencode( home_url() ),
                'return_url'   => rawurlencode( $oauth_return_url ),
                'callback_url' => rawurlencode( $oauth_callback_url ),
            ],
            home_url( '/wc-auth/v1/authorize' )
        );
        ?>
        <div class="wrap gecx-admin-wrap">
            <h1><?php esc_html_e( 'Gemini Enterprise for CX', 'gemini-enterprise-for-cx' ); ?></h1>

            <div id="gecx-admin-notices"></div>

            <?php settings_errors( 'gecx_messages' ); ?>

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
                            <a href="<?php echo esc_url( $oauth_url ); ?>"
                                id="gecx-authorize-btn"
                                class="button button-primary button-hero"
                                style="display: inline-flex; align-items: center; gap: 8px;">
                                <?php esc_html_e( 'Authorize Store', 'gemini-enterprise-for-cx' ); ?>
                            </a>
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
                        <a href="<?php echo esc_url( $console_base ); ?>" target="_blank" class="button button-secondary">
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
                                        <strong><?php esc_html_e( 'Top navigation menu (Recommended)', 'gemini-enterprise-for-cx' ); ?></strong>
                                        <br><span class="description" style="margin-left: 20px;"><?php esc_html_e( 'Part of your primary header navigation bar.', 'gemini-enterprise-for-cx' ); ?></span>
                                    </label>
                                    <label style="display: block;">
                                        <input type="radio"
                                               name="gecx_button_placement"
                                               value="floating"
                                               <?php checked( $button_placement, 'floating' ); ?> />
                                        <strong><?php esc_html_e( 'Floating bubble', 'gemini-enterprise-for-cx' ); ?></strong>
                                        <br><span class="description" style="margin-left: 20px;"><?php esc_html_e( 'Fixed position floating over pages.', 'gemini-enterprise-for-cx' ); ?></span>
                                    </label>
                                </fieldset>
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
        check_ajax_referer( 'gecx_save_agent_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $placement      = isset( $_POST['placement'] ) && in_array( $_POST['placement'], [ 'nav_menu', 'floating' ], true ) ? sanitize_text_field( wp_unslash( $_POST['placement'] ) ) : 'nav_menu';
        $floating_pos   = GECX_Storefront::sanitize_floating_position( isset( $_POST['floating_position'] ) ? sanitize_text_field( wp_unslash( $_POST['floating_position'] ) ) : null );
        $allowed_styles = [ 'responsive', 'icon-and-label', 'icon-only', 'label-only' ];
        $display_style  = isset( $_POST['display_style'] ) && in_array( $_POST['display_style'], $allowed_styles, true ) ? sanitize_text_field( wp_unslash( $_POST['display_style'] ) ) : 'responsive';
        $label          = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
        $short_label    = isset( $_POST['short_label'] ) ? sanitize_text_field( wp_unslash( $_POST['short_label'] ) ) : '';
        $enable_shimmer = isset( $_POST['enable_shimmer'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enable_shimmer'] ) ) ? 1 : 0;

        update_option( 'gecx_button_placement', $placement );
        update_option( 'gecx_floating_position', $floating_pos );
        update_option( 'gecx_button_display_style', $display_style );
        update_option( 'gecx_button_label', $label );
        update_option( 'gecx_button_short_label', $short_label );
        update_option( 'gecx_button_enable_shimmer', $enable_shimmer );

        wp_send_json_success();
    }

    /**
     * AJAX handler to toggle the storefront chat widget embed state.
     */
    public function ajax_toggle_app_embed(): void {
        check_ajax_referer( 'gecx_save_agent_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) ? 1 : 0;
        update_option( 'gecx_agent_enabled', $enabled );
        if ( class_exists( 'GECX_Rest_API' ) ) {
            GECX_Rest_API::set_order_webhook_status( 1 === $enabled ? 'active' : 'paused' );
        }
        wp_send_json_success( [ 'enabled' => $enabled ] );
    }

    /**
     * AJAX handler to toggle the suggested PDP prompts state.
     */
    public function ajax_toggle_pdp_prompts(): void {
        check_ajax_referer( 'gecx_save_agent_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) ? 1 : 0;
        update_option( 'gecx_pdp_prompts_enabled', $enabled );
        wp_send_json_success( [ 'enabled' => $enabled ] );
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

        $last = (int) get_option( self::SYNC_THROTTLE_OPTION, 0 );
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
    private function clear_sync_window(): void {
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
        GECX_Auth::log( 'sync-state: ' . $message, 'debug' );
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
    private function sync_agent_state( string $current_agent, bool $force = false, ?int $user_id = null ): string {
        $auth_complete = (bool) get_option( self::AUTH_COMPLETE_OPTION, false );
        if ( ! $auth_complete && $this->has_existing_state( $current_agent ) ) {
            update_option( self::AUTH_COMPLETE_OPTION, 1, 'no' );
            $auth_complete = true;
        }

        if ( ! $auth_complete ) {
            $this->log_sync( 'skipped, store has not completed authorization' );
            return '';
        }

        $admin_jwt = GECX_Auth::generate_admin_jwt( $user_id )
            ?? GECX_Auth::generate_existing_rs256_admin_jwt( $user_id );
        if ( empty( $admin_jwt ) ) {
            $this->log_sync( 'skipped, admin JWT unavailable' );
            return '';
        }

        $console_base = untrailingslashit( $this->get_console_base_url() );

        // get_console_base_url() runs the value through a filter, so any plugin
        // on the site can rewrite the destination. The body carries a
        // store-signed JWT, so refuse to send it over a non-TLS scheme.
        // sslverify is intentionally left at its default and must stay there.
        if ( 'https' !== wp_parse_url( $console_base, PHP_URL_SCHEME ) ) {
            $this->log_sync( 'skipped, console base URL is not https' );
            return '';
        }

        if ( ! $this->claim_sync_window( $force ) ) {
            return '';
        }

        // NOTE: admin_jwt is intentionally sent in the JSON body rather than an
        // Authorization header. The endpoint deserializes the body directly into
        // the SyncWooCommerceStateRequest proto, whose admin_jwt field is
        // required; it does not read Authorization. That header is also reserved
        // for Google account auth on this route, so sending a store-signed JWT
        // there would be rejected before reaching the handler.
        // wp_safe_remote_post() rather than wp_remote_post(): the destination
        // comes from an option and a filter, so it validates the resolved host
        // against the private and loopback ranges. redirection 0 because the
        // body carries a store-signed admin JWT and a 30x would hand it to
        // whatever host the redirect names, unvalidated.
        $response = wp_safe_remote_post(
            $console_base . self::CONSOLE_SYNC_STATE_PATH,
            [
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'        => wp_json_encode(
                    [
                        'admin_jwt'         => $admin_jwt,
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
            update_option( self::PLUGIN_VERSION_OPTION, (string) GECX_VERSION, false );
        }
        delete_option( self::VERSION_SYNC_USER_OPTION );
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
        $this_store  = GECX_Auth::get_sanitized_store_domain();
        if ( '' !== $shop_domain && '' !== $this_store
            && 0 !== strcasecmp( $shop_domain, $this_store ) ) {
            $this->log_sync( 'response shop domain does not match this store, ignoring' );
            return;
        }

        if ( 'WOOCOMMERCE_SYNC_STATUS_JWT_AUTH_INVALID' === $status ) {
            // Re-running the WooCommerce authorization issues fresh API keys,
            // which is also how Google re-reads this store's public key, so
            // send the merchant back to that step instead of only warning.
            update_option( self::STORE_AUTH_INVALID_OPTION, 1 );
            $this->add_sync_notice( 'gecx_sync_jwt_invalid' );
            return;
        }

        if ( 'WOOCOMMERCE_SYNC_STATUS_WOOCOMMERCE_API_KEYS_INVALID' === $status ) {
            update_option( self::STORE_AUTH_INVALID_OPTION, 1 );
            $this->add_sync_notice( 'gecx_sync_api_keys_invalid' );
            return;
        }

        $actual        = (string) ( $data['actualLinkedAgentId'] ?? $data['actual_linked_agent_id'] ?? '' );
        $actual_broker = (string) ( $data['tokenBrokerName'] ?? $data['token_broker_name'] ?? '' );

        // Same allowlist GECX_Rest_API::link_agent_handler() applies to the
        // values a caller supplies, applied here to the values the backend
        // reports. Both end up in the same two options and in the widget's
        // agent-name and token-broker attributes, so both have to mean the
        // same thing. Validated raw and rejected, never sanitized into shape:
        // stripping characters would turn a name this store should refuse into
        // one it silently adopts.
        if ( '' !== $actual && ! preg_match( self::RESOURCE_NAME_PATTERN, $actual ) ) {
            $this->log_sync( 'response agent id is not a valid resource name, ignoring' );
            return;
        }
        if ( '' !== $actual_broker && ! preg_match( self::RESOURCE_NAME_PATTERN, $actual_broker ) ) {
            $this->log_sync( 'response token broker is not a valid resource name, ignoring' );
            return;
        }

        if ( 'WOOCOMMERCE_SYNC_STATUS_SYNCED' === $status ) {
            // Whatever the backend objected to before is resolved.
            delete_option( self::STORE_AUTH_INVALID_OPTION );

            // SYNCED is decided by comparing the bare agent id, so a store that
            // was linked before the backend started returning canonical
            // resource names still matches here while holding the short form
            // locally. The backend reports the canonical name and the broker on
            // this path too, and a SYNCED store never reaches the adopt branch
            // below, so this is the only chance to pick them up.
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
        delete_option( self::STORE_AUTH_INVALID_OPTION );

        // The backend reports the agent it actually holds, so adopt it when
        // there is one.
        if ( '' !== $actual ) {
            $current_broker  = (string) get_option( 'gecx_token_broker_name', '' );
            $current_enabled = (int) get_option( 'gecx_agent_enabled', 0 );
            if ( $actual === $current_agent && $actual_broker === $current_broker && 1 === $current_enabled ) {
                return;
            }

            update_option( 'gecx_agent_name', $actual );

            // An automatic unlink disables the widget and pauses/deletes the
            // order webhook. Adopting a link the backend still holds has to
            // re-enable both, or the store looks connected in wp-admin while
            // the storefront stays dark and order attribution stops firing.
            update_option( 'gecx_agent_enabled', 1 );
            if ( class_exists( 'GECX_Rest_API' ) ) {
                GECX_Rest_API::set_order_webhook_status( 'active' );
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
        $this->clear_sync_window();

        $this->add_sync_notice( 'gecx_agent_unlinked' );
    }

    /**
     * Delete the locally stored agent binding.
     *
     * The merchant's appearance settings are deliberately left in place so a
     * re-link does not lose their customization.
     */
    private function unlink_agent_internal(): void {
        if ( class_exists( 'GECX_Rest_API' ) ) {
            GECX_Rest_API::delete_order_webhook();
        }
        // Legacy shared secret, retired in favour of the store's RSA keypair.
        // Still deleted so a store upgraded from an older version does not keep
        // the row around after disconnecting.
        delete_option( 'gecx_api_secret' );
        delete_option( self::STORE_AUTH_INVALID_OPTION );
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
     * @param string $agent_id Agent the store currently believes it is linked to.
     * @return bool True when Google confirms the store is no longer linked.
     */
    private function unlink_agent_remotely( string $agent_id ): bool {
        $admin_jwt = GECX_Auth::generate_admin_jwt();
        if ( empty( $admin_jwt ) ) {
            $this->log_sync( 'unlink skipped, admin JWT unavailable' );
            return false;
        }

        $console_base = untrailingslashit( $this->get_console_base_url() );

        // Same reasoning as sync_agent_state(): the destination is filterable,
        // and the body carries a store-signed JWT, so refuse a non-TLS scheme.
        if ( 'https' !== wp_parse_url( $console_base, PHP_URL_SCHEME ) ) {
            $this->log_sync( 'unlink skipped, console base URL is not https' );
            return false;
        }

        // Same reasoning as sync_agent_state() above.
        $response = wp_safe_remote_post(
            $console_base . self::CONSOLE_UNLINK_AGENT_PATH,
            [
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'        => wp_json_encode(
                    [
                        'agent_id'  => $agent_id,
                        'admin_jwt' => $admin_jwt,
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
        // already linked to a different agent or unlinked in Spanner), and 400
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
     */
    public function ajax_unlink_agent(): void {
        check_ajax_referer( 'gecx_save_agent_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
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
        // Re-linking right after an unlink must reconcile immediately.
        $this->clear_sync_window();
        wp_send_json_success();
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
        check_ajax_referer( 'gecx_dismiss_notice_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        update_option( 'gecx_dismiss_activation_notice', true );
        wp_send_json_success();
    }

    /**
     * Render a custom field for suggested prompts override on the product general edit tab.
     */
    public function add_product_prompts_override_field(): void {
        echo '<div class="options_group">';
        wp_nonce_field( 'gecx_save_prompts_override', 'gecx_prompts_override_nonce' );
        woocommerce_wp_textarea_input( [
            'id'          => '_gecx_suggested_prompts_override',
            'label'       => __( 'GECX Prompts Override', 'gemini-enterprise-for-cx' ),
            /* translators: Example prompts shown as placeholder text. One prompt per line; keep the newline separators. */
            'placeholder' => __( "What is the return policy?\nIs this machine washable?\nCompare with similar items", 'gemini-enterprise-for-cx' ),
            'desc_tip'    => true,
            'description' => __( 'Enter one prompt per line to override AI-generated prompts for this product.', 'gemini-enterprise-for-cx' ),
        ] );
        echo '</div>';
    }

    /**
     * Save the product suggested prompts override custom field via WooCommerce
     * product CRUD (`WC_Product::update_meta_data` / `delete_meta_data`) with a
     * post-meta fallback.
     *
     * @param mixed $product_or_id `WC_Product` object (from `woocommerce_admin_process_product_object`)
     *                             or integer product post ID (from `woocommerce_process_product_meta`).
     */
    public function save_product_prompts_override_field( $product_or_id ): void {
        if ( ! isset( $_POST['gecx_prompts_override_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gecx_prompts_override_nonce'] ) ), 'gecx_save_prompts_override' ) ) {
            return;
        }

        $product = null;
        $post_id = 0;
        if ( is_object( $product_or_id ) && method_exists( $product_or_id, 'get_id' ) ) {
            $product = $product_or_id;
            $post_id = (int) $product_or_id->get_id();
        } elseif ( is_numeric( $product_or_id ) ) {
            $post_id = (int) $product_or_id;
            if ( function_exists( 'wc_get_product' ) ) {
                $candidate = wc_get_product( $post_id );
                if ( is_object( $candidate ) ) {
                    $product = $candidate;
                }
            }
        }

        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( ! is_object( $product_or_id ) && ! empty( $this->saved_product_prompt_ids[ $post_id ] ) ) {
            return;
        }
        if ( is_object( $product_or_id ) ) {
            $this->saved_product_prompt_ids[ $post_id ] = true;
        }

        $override = isset( $_POST['_gecx_suggested_prompts_override'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_gecx_suggested_prompts_override'] ) ) : '';
        if ( null !== $product && method_exists( $product, 'update_meta_data' ) && method_exists( $product, 'delete_meta_data' ) ) {
            if ( ! empty( $override ) ) {
                $product->update_meta_data( '_gecx_suggested_prompts_override', $override );
            } else {
                $product->delete_meta_data( '_gecx_suggested_prompts_override' );
            }
            if ( ! is_object( $product_or_id ) && method_exists( $product, 'save' ) ) {
                $product->save();
            }
            return;
        }

        if ( ! empty( $override ) ) {
            update_post_meta( $post_id, '_gecx_suggested_prompts_override', $override );
        } else {
            delete_post_meta( $post_id, '_gecx_suggested_prompts_override' );
        }
    }
}
