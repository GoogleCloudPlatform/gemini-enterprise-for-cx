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
        add_action( 'admin_notices', [ $this, 'show_activation_notice' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Register product settings override hooks.
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_product_prompts_override_field' ] );
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
            if ( ! defined( 'GECX_TESTING' ) || ! GECX_TESTING ) {
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
        if ( ! defined( 'GECX_TESTING' ) || ! GECX_TESTING ) {
            exit;
        }
        return;
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

        $admin_js_ver = defined( 'GECX_VERSION' ) ? GECX_VERSION : '0.3.5';

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

        wp_localize_script( 'gecx-admin-js', 'gecx_admin_params', [
            'save_nonce'     => wp_create_nonce( 'gecx_save_agent_nonce' ),
            'dismiss_nonce'  => wp_create_nonce( 'gecx_dismiss_notice_nonce' ),
            'statusActive'   => __( 'Connection Status: Active', 'gemini-enterprise-for-cx' ),
            'statusInactive' => __( 'Connection Status: Inactive', 'gemini-enterprise-for-cx' ),
        ] );
    }

    /**
     * Redirect user to onboarding settings page immediately upon plugin activation.
     */
    public function redirect_on_activation(): void {
        if ( get_option( 'gecx_do_activation_redirect', false ) ) {
            delete_option( 'gecx_do_activation_redirect' );
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( ! isset( $_GET['activate-multi'] ) && ! wp_doing_ajax() ) {
                wp_safe_redirect( admin_url( 'admin.php?page=gemini-enterprise-for-cx' ) );
                exit;
            }
        }
    }

    /**
     * Add Settings link next to Deactivate on plugins list page.
     *
     * @param array $links Array of plugin action links.
     * @return array Modified links array.
     */
    public function add_plugin_action_links( array $links ): array {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=gemini-enterprise-for-cx' ) ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Add GECX settings page (submenu page under WooCommerce Marketing).
     */
    public function add_settings_page(): void {
        $this->settings_page_hook = (string) add_submenu_page(
            'woocommerce-marketing',
            'Gemini Enterprise for CX',
            'Gemini Enterprise for CX',
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
            'sanitize_callback' => 'sanitize_text_field',
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
    }

    /**
     * Get console base URL.
     */
    private function get_console_base_url(): string {
        $console_base_option = get_option( 'gecx_console_base_url', 'https://gecx.cloud.google.com' );
        return (string) apply_filters( 'gecx_console_base_url', (string) $console_base_option );
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

        $oauth_state = wp_generate_password( 32, false );
        set_transient( 'gecx_oauth_state_' . $oauth_state, 1, 15 * MINUTE_IN_SECONDS );

        $states = (array) get_option( 'gecx_pending_oauth_states', [] );
        $now    = time();
        $states = array_filter(
            $states,
            function( $exp ) use ( $now ) {
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

        $has_store_credentials = ! empty( get_option( 'gecx_webhook_id' ) ) || ! empty( get_option( 'gecx_api_secret', '' ) );
        // SyncState can report that Google can no longer use this store's
        // credentials. Treat that as unauthorized so the merchant is offered
        // the authorize step again instead of a dead end.
        $is_authorized = $has_store_credentials && ! get_option( self::STORE_AUTH_INVALID_OPTION, false );
        $admin_jwt     = $is_authorized ? GECX_Auth::generate_admin_jwt() : '';

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

        $connect_url = add_query_arg(
            $connect_params,
            $console_base . self::CONSOLE_APP_PATH
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
                            <a href="<?php echo esc_url( $connect_url ); ?>"
                                id="gecx-connect-btn"
                                class="button button-primary button-hero"
                                style="display: inline-flex; align-items: center; gap: 8px;">
                                <?php esc_html_e( 'Connect with Google Cloud', 'gemini-enterprise-for-cx' ); ?>
                            </a>
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
                                    <option value="bottom_center" <?php selected( $floating_position, 'bottom_center' ); ?>><?php esc_html_e( 'Bottom Center (Default)', 'gemini-enterprise-for-cx' ); ?></option>
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
        $floating_pos   = isset( $_POST['floating_position'] ) && in_array( $_POST['floating_position'], [ 'bottom_center', 'center_right' ], true ) ? sanitize_text_field( wp_unslash( $_POST['floating_position'] ) ) : 'bottom_center';
        $allowed_styles = [ 'responsive', 'icon-and-label', 'icon-only', 'label-only' ];
        $display_style  = isset( $_POST['display_style'] ) && in_array( $_POST['display_style'], $allowed_styles, true ) ? sanitize_text_field( wp_unslash( $_POST['display_style'] ) ) : 'responsive';
        $label          = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
        $short_label    = isset( $_POST['short_label'] ) ? sanitize_text_field( wp_unslash( $_POST['short_label'] ) ) : '';
        $enable_shimmer = isset( $_POST['enable_shimmer'] ) && $_POST['enable_shimmer'] === '1' ? 1 : 0;

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

        $enabled = isset( $_POST['enabled'] ) && $_POST['enabled'] === '1' ? 1 : 0;
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

        $enabled = isset( $_POST['enabled'] ) && $_POST['enabled'] === '1' ? 1 : 0;
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
     * @return bool True when this request owns the window and should sync.
     */
    private function claim_sync_window(): bool {
        $now = time();

        // Autoload is off: this option is only read on the settings page.
        if ( add_option( self::SYNC_THROTTLE_OPTION, (string) $now, '', false ) ) {
            return true;
        }

        $last = (int) get_option( self::SYNC_THROTTLE_OPTION, 0 );
        if ( $now - $last < self::SYNC_THROTTLE_SECONDS ) {
            return false;
        }

        // The window has elapsed. This refresh is not atomic, so two requests
        // racing on the same expiry can both sync once; the endpoint is
        // idempotent and the throttle then applies again.
        update_option( self::SYNC_THROTTLE_OPTION, (string) $now, false );
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
     * @param string $current_agent Currently configured agent resource name.
     * @return string The status reported by the backend, or '' when no usable
     *                response was obtained.
     */
    private function sync_agent_state( string $current_agent ): string {
        $auth_complete = (bool) get_option( self::AUTH_COMPLETE_OPTION, false );
        if ( ! $auth_complete ) {
            $has_existing_state = '' !== $current_agent
                || ! empty( get_option( 'gecx_api_secret', '' ) )
                || ! empty( get_option( 'gecx_webhook_id' ) );
            if ( $has_existing_state ) {
                update_option( self::AUTH_COMPLETE_OPTION, 1, 'no' );
                $auth_complete = true;
            }
        }
        if ( ! $auth_complete ) {
            $this->log_sync( 'skipped, store has not completed authorization' );
            return '';
        }

        $admin_jwt = GECX_Auth::generate_admin_jwt();
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

        if ( ! $this->claim_sync_window() ) {
            return '';
        }

        // NOTE: admin_jwt is intentionally sent in the JSON body rather than an
        // Authorization header. The endpoint deserializes the body directly into
        // the SyncWooCommerceStateRequest proto, whose admin_jwt field is
        // required; it does not read Authorization. That header is also reserved
        // for Google account auth on this route, so sending a store-signed JWT
        // there would be rejected before reaching the handler.
        $response = wp_remote_post(
            $console_base . self::CONSOLE_SYNC_STATE_PATH,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'    => wp_json_encode(
                    [
                        'admin_jwt'         => $admin_jwt,
                        'expected_agent_id' => $current_agent,
                    ]
                ),
                'timeout' => 3,
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
            add_settings_error(
                'gecx_messages',
                'gecx_sync_jwt_invalid',
                __(
                    'Google could not verify the identity of this store. Authorize your store again to restore the assistant.',
                    'gemini-enterprise-for-cx'
                ),
                'warning'
            );
            return;
        }

        if ( 'WOOCOMMERCE_SYNC_STATUS_WOOCOMMERCE_API_KEYS_INVALID' === $status ) {
            update_option( self::STORE_AUTH_INVALID_OPTION, 1 );
            add_settings_error(
                'gecx_messages',
                'gecx_sync_api_keys_invalid',
                __(
                    'Google can no longer read your store catalog with the saved WooCommerce API keys. Authorize your store again to issue new keys.',
                    'gemini-enterprise-for-cx'
                ),
                'warning'
            );
            return;
        }

        if ( 'WOOCOMMERCE_SYNC_STATUS_SYNCED' === $status ) {
            // Whatever the backend objected to before is resolved.
            delete_option( self::STORE_AUTH_INVALID_OPTION );
            return;
        }

        if ( 'WOOCOMMERCE_SYNC_STATUS_LINK_REQUIRED' !== $status ) {
            return;
        }

        // LINK_REQUIRED only means the local binding disagrees with the
        // backend, not that the store is unlinked. The backend reports the
        // agent it actually holds, so adopt it when there is one.
        $actual = (string) ( $data['actualLinkedAgentId'] ?? $data['actual_linked_agent_id'] ?? '' );
        $actual_broker = (string) ( $data['tokenBrokerName'] ?? $data['token_broker_name'] ?? '' );

        if ( '' !== $actual ) {
            $current_broker = (string) get_option( 'gecx_token_broker_name', '' );
            if ( $actual === $current_agent && $actual_broker === $current_broker ) {
                return;
            }

            update_option( 'gecx_agent_name', $actual );
            if ( '' !== $actual_broker ) {
                update_option( 'gecx_token_broker_name', $actual_broker );
            }

            add_settings_error(
                'gecx_messages',
                'gecx_agent_adopted',
                __(
                    'This store is connected to a different agent than the one saved here, so the saved agent was updated to match Google.',
                    'gemini-enterprise-for-cx'
                ),
                'warning'
            );
            return;
        }

        if ( '' === $current_agent ) {
            return;
        }

        // The backend holds no link for this store. Clear the binding; the
        // merchant's appearance settings survive so a re-link keeps them.
        $this->unlink_agent_internal();
        $this->clear_sync_window();

        add_settings_error(
            'gecx_messages',
            'gecx_agent_unlinked',
            __(
                'The agent was automatically unlinked because Google no longer has a connection for this store. Please reconnect your store to restore the assistant.',
                'gemini-enterprise-for-cx'
            ),
            'warning'
        );
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

        $response = wp_remote_post(
            $console_base . self::CONSOLE_UNLINK_AGENT_PATH,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'    => wp_json_encode(
                    [
                        'agent_id'  => $agent_id,
                        'admin_jwt' => $admin_jwt,
                    ]
                ),
                'timeout' => 3,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $this->log_sync( 'unlink transport error: ' . $response->get_error_code() );
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        // 404 means Google has no installation to unlink, which is the state
        // this call is trying to reach. Treat it as done rather than stranding
        // the merchant on a link only WordPress still believes in.
        if ( 404 === $code ) {
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
        $settings_url = admin_url( 'admin.php?page=gemini-enterprise-for-cx' );
        ?>
        <div class="notice notice-info is-dismissible gecx-activation-notice">
            <p>
                <?php
                echo wp_kses_post( sprintf(
                    /* translators: %s: URL to the settings page */
                    __( 'Thanks for installing Gemini Enterprise for CX! Please finalize your plugin by completing the <a href="%s">settings page</a>.', 'gemini-enterprise-for-cx' ),
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
            'placeholder' => "What is the return policy?\nIs this machine washable?\nCompare with similar items",
            'desc_tip'    => true,
            'description' => __( 'Enter one prompt per line to override AI-generated prompts for this product.', 'gemini-enterprise-for-cx' ),
        ] );
        echo '</div>';
    }

    /**
     * Save the product suggested prompts override custom field.
     *
     * @param int $post_id The product post ID.
     */
    public function save_product_prompts_override_field( int $post_id ): void {
        if ( ! isset( $_POST['gecx_prompts_override_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gecx_prompts_override_nonce'] ) ), 'gecx_save_prompts_override' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $override = isset( $_POST['_gecx_suggested_prompts_override'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_gecx_suggested_prompts_override'] ) ) : '';
        if ( ! empty( $override ) ) {
            update_post_meta( $post_id, '_gecx_suggested_prompts_override', $override );
        } else {
            delete_post_meta( $post_id, '_gecx_suggested_prompts_override' );
        }
    }
}
