<?php
/**
 * Gemini Enterprise for CX Storefront Handler
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class GECX_Storefront {

    /**
     * Path to the main plugin file.
     */
    private string $plugin_file;

    /**
     * Track whether the agent launcher has already been injected into navigation for the request.
     */
    protected bool $nav_injected = false;

    /**
     * Track whether suggested prompts have already been injected for the request.
     */
    protected bool $pdp_prompts_injected = false;

    /**
     * Constructor.
     *
     * @param string $plugin_file Path to the main plugin file.
     */
    public function __construct( string $plugin_file ) {
        $this->plugin_file = $plugin_file;
        $this->register_storefront_hooks();
    }

    /**
     * Register storefront hooks.
     */
    public function register_storefront_hooks(): void {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_storefront_assets' ] );
        add_action( 'wp_footer', [ $this, 'inject_chat_widget' ] );
        add_filter( 'wp_nav_menu_items', [ $this, 'inject_nav_menu_agent_button' ], 10, 2 );
        add_filter( 'render_block_core/navigation', [ $this, 'inject_block_navigation_agent_button' ], 10, 2 );

        $prompts_hook     = apply_filters( 'gecx_suggested_prompts_hook', 'woocommerce_single_product_summary' );
        $prompts_priority = apply_filters( 'gecx_suggested_prompts_priority', 35 );
        add_action( $prompts_hook, [ $this, 'inject_suggested_prompts' ], $prompts_priority );

        if ( apply_filters( 'gecx_enable_secondary_pdp_hook', false ) ) {
            add_action( 'woocommerce_after_add_to_cart_form', [ $this, 'inject_suggested_prompts' ], 20 );
        }

        add_filter( 'render_block_woocommerce/add-to-cart-form', [ $this, 'inject_block_suggested_prompts' ], 10, 2 );
        add_filter( 'render_block_woocommerce/single-product-details', [ $this, 'inject_block_suggested_prompts' ], 10, 2 );
        add_filter( 'render_block_woocommerce/product-summary', [ $this, 'inject_block_suggested_prompts' ], 10, 2 );
        add_shortcode( 'gecx_suggested_prompts', [ $this, 'render_suggested_prompts_shortcode' ] );
    }

    /**
     * Enqueue storefront styles and scripts.
     */
    public function enqueue_storefront_assets(): void {
        // wp_enqueue_scripts also fires for requests that render no storefront
        // page, and nothing downstream of this point is meaningful for them.
        if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() ) {
            return;
        }

        $agent_name = $this->get_active_agent_name();
        $enabled    = $this->is_widget_enabled();

        if ( ! $agent_name || ! $enabled ) {
            return;
        }

        $urls = $this->resolve_widget_urls();
        // No literal fallback. gecx-agent.php defines GECX_VERSION before this
        // class is loaded, so an undefined constant means something is very
        // wrong. null rather than '' because WordPress substitutes its own
        // core version for an empty string, and a stale literal here would be
        // one more version declaration to keep in step with the four that
        // check-version.php already enforces.
        $version = defined( 'GECX_VERSION' ) ? GECX_VERSION : null;

        wp_enqueue_style( 'gecx-widget-style', $urls['style'], [], $version );
        wp_add_inline_style(
            'gecx-widget-style',
            'footer .gecx-nav-menu-item, .site-footer .gecx-nav-menu-item, [role="contentinfo"] .gecx-nav-menu-item, .wp-block-template-part:has(footer) .gecx-nav-menu-item { display: none !important; }' .
            ' .gecx-nav-menu-item { display: inline-flex !important; align-items: center !important; justify-content: center !important; vertical-align: middle !important; align-self: center !important; height: auto !important; margin: 0 4px !important; }' .
            ' .gecx-nav-menu-item gecx-agent-button { display: inline-flex !important; align-items: center !important; vertical-align: middle !important; }' .
            ' .wp-block-navigation__responsive-container.is-menu-open .gecx-nav-menu-item { display: none !important; }' .
            ' @media (max-width: 599.98px) { .wp-block-navigation .gecx-nav-menu-item { display: none !important; } }' .
            ' @media (min-width: 600px) { .wp-block-navigation .gecx-mobile-header-button { display: none !important; } }' .
            ' @media (max-width: 767.98px) { .main-navigation .gecx-nav-menu-item, .site-header nav .gecx-nav-menu-item { display: none !important; } }' .
            ' @media (min-width: 768px) { .main-navigation .gecx-mobile-header-button, .site-header .gecx-mobile-header-button { display: none !important; } }' .
            ' .gecx-mobile-header-button { display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; margin: 0 6px; align-self: center; height: auto; line-height: normal; }' .
            ' .gecx-mobile-header-button gecx-agent-button { display: inline-flex; align-items: center; vertical-align: middle; }' .
            ' .gecx-mobile-header-button.gecx-mobile-header-button--floating { position: fixed; bottom: 20px; right: 20px; z-index: 99999; margin: 0; height: auto; }' .
            ' chat-messenger.slide-over { position: fixed !important; }' .
            ' .gecx-floating-button-container { transition: transform 0.5s cubic-bezier(0.32, 0.72, 0, 1); }' .
            ' body.gecx-chat-no-transition .gecx-floating-button-container { transition: none !important; }' .
            ' body.gecx-chat-open .gecx-floating-button-container--center-right, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container--center-right { display: none !important; }' .
            ' @media (min-width: 600px) and (max-width: 839.98px) {' .
            '   body.gecx-chat-open .gecx-floating-button-container--bottom-center, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container--bottom-center,' .
            '   body.gecx-chat-open .gecx-floating-button-container:not(.gecx-floating-button-container--center-right), body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container:not(.gecx-floating-button-container--center-right) {' .
            '     transform: translateX(calc(-50% - var(--gecx-chat-panel-width, 360px) / 2)) !important;' .
            '   }' .
            ' }' .
            ' @media (min-width: 840px) {' .
            '   body.gecx-chat-open .gecx-floating-button-container--bottom-center, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container--bottom-center,' .
            '   body.gecx-chat-open .gecx-floating-button-container:not(.gecx-floating-button-container--center-right), body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container:not(.gecx-floating-button-container--center-right) {' .
            '     transform: translateX(calc(-50% - var(--gecx-chat-panel-width, clamp(0px, 412px, 50dvw)) / 2)) !important;' .
            '   }' .
            ' }' .
            ' @media (max-width: 599.98px) {' .
            '   body.gecx-chat-open .gecx-floating-button-container, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container,' .
            '   body.gecx-chat-open .gecx-mobile-header-button--floating, body:has(chat-messenger:not(.messenger-hidden)) .gecx-mobile-header-button--floating {' .
            '     display: none !important;' .
            '   }' .
            ' }'
        );

        wp_enqueue_script( 'gecx-widget-script', $urls['script'], [], $version, true );

        wp_enqueue_script(
            'gecx-storefront-js',
            plugins_url( 'assets/js/storefront.js', $this->plugin_file ),
            [],
            $version,
            true
        );

        $placement = (string) get_option( 'gecx_button_placement', 'nav_menu' );
        $config    = [
            'placement'       => $placement,
            'buttonHtml'      => $this->get_agent_button_html(),
            'isWidgetEnabled' => $enabled,
            // Resolved here rather than assembled in the browser. rest_url()
            // accounts for subdirectory installs, a custom rest_url_prefix and
            // the plain-permalink ?rest_route= form, none of which the script
            // can infer from window.location.
            'cartRestUrl'     => esc_url_raw( rest_url( 'wc/store/v1/cart' ) ),
            // WooCommerce resolves these from the store's configured page IDs,
            // so a store using localized slugs such as /panier still answers
            // correctly, and a product whose slug merely starts with "cart"
            // no longer does.
            'isCartOrCheckout' => ( function_exists( 'is_cart' ) && is_cart() )
                || ( function_exists( 'is_checkout' ) && is_checkout() ),
        ];

        if ( is_product() && $this->is_pdp_prompts_auto_inject_enabled() ) {
            $product_id               = self::resolve_current_product_id();
            $config['isPdp']          = true;
            $config['pdpPromptsHtml'] = $this->get_suggested_prompts_html( $product_id );
        }

        wp_localize_script( 'gecx-storefront-js', 'gecxStorefrontConfig', $config );
    }

    /**
     * Inject agent button into WordPress primary navigation menu (Classic Themes).
     *
     * @param string $items HTML list items for the menu.
     * @param mixed  $args  Menu args object/array.
     * @return string Modified HTML menu items.
     */
    public function inject_nav_menu_agent_button( string $items, $args = null ): string {
        if ( is_admin() || ! $this->is_widget_enabled() || ! $this->get_active_agent_name() ) {
            return $items;
        }

        $placement = (string) get_option( 'gecx_button_placement', 'nav_menu' );
        if ( 'nav_menu' !== $placement ) {
            return $items;
        }

        if ( $this->nav_injected && ! apply_filters( 'gecx_allow_multiple_nav_injections', false ) ) {
            return $items;
        }

        $target_locations = apply_filters(
            'gecx_nav_menu_locations',
            [ 'primary', 'main', 'main-menu', 'header', 'header-menu', 'menu-1', 'primary-menu', 'main_nav', 'nav-menu' ]
        );
        $menu_location   = is_object( $args ) && isset( $args->theme_location ) ? (string) $args->theme_location : '';

        if ( ( ! empty( $menu_location ) && in_array( $menu_location, $target_locations, true ) ) || apply_filters( 'gecx_inject_in_all_menus', false ) ) {
            $button_html        = '<li class="menu-item gecx-nav-menu-item" style="display: inline-flex; align-items: center; justify-content: center; vertical-align: middle;">' . $this->get_agent_button_html() . '</li>';
            $items             .= $button_html;
            $this->nav_injected = true;
        }

        return $items;
    }

    /**
     * Inject agent button into Gutenberg Navigation Block (FSE / Block Themes).
     *
     * @param string $block_content Rendered block HTML.
     * @param array  $block         Block data array.
     * @return string Modified block HTML.
     */
    public function inject_block_navigation_agent_button( string $block_content, array $block = [] ): string {
        if ( is_admin() || ! $this->is_widget_enabled() || ! $this->get_active_agent_name() ) {
            return $block_content;
        }

        $placement = (string) get_option( 'gecx_button_placement', 'nav_menu' );
        if ( 'nav_menu' !== $placement ) {
            return $block_content;
        }

        if ( $this->nav_injected && ! apply_filters( 'gecx_allow_multiple_nav_injections', false ) ) {
            return $block_content;
        }

        // Avoid injecting into footer navigation blocks.
        if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
            $aria_label = isset( $block['attrs']['ariaLabel'] ) ? (string) $block['attrs']['ariaLabel'] : '';
            $class_name = isset( $block['attrs']['className'] ) ? (string) $block['attrs']['className'] : '';
            if ( false !== stripos( $aria_label, 'footer' ) || false !== stripos( $class_name, 'footer' ) ) {
                return $block_content;
            }
        }

        // Avoid duplicate injection if button already exists in this block content.
        if ( false !== strpos( $block_content, 'gecx-agent-button' ) ) {
            return $block_content;
        }

        $button_html = '<li class="wp-block-navigation-item gecx-nav-menu-item" style="display: inline-flex; align-items: center; justify-content: center; vertical-align: middle;">' . $this->get_agent_button_html() . '</li>';

        $last_ul_pos = strrpos( $block_content, '</ul>' );
        if ( false !== $last_ul_pos ) {
            $this->nav_injected = true;
            return substr_replace( $block_content, $button_html . '</ul>', $last_ul_pos, 5 );
        }

        $this->nav_injected = true;
        return $block_content . $button_html;
    }

    /**
     * Inject the chat widget custom element into the footer.
     */
    public function inject_chat_widget(): void {
        if ( is_admin() ) {
            return;
        }

        $agent_name = $this->get_active_agent_name();
        $enabled    = $this->is_widget_enabled();

        if ( ! $agent_name || ! $enabled ) {
            return;
        }

        $store_url     = esc_url( home_url() );
        $rest_url      = esc_url( rest_url() );
        $token_broker  = $this->get_token_broker();
        $placement     = (string) get_option( 'gecx_button_placement', 'nav_menu' );
        $floating_pos  = (string) get_option( 'gecx_floating_position', 'bottom_center' );
        ?>
        <?php if ( 'floating' === $placement ) : ?>
        <!-- Start Gemini Enterprise for CX Floating Button -->
        <div class="gecx-floating-button-container gecx-floating-button-container--<?php echo esc_attr( str_replace( '_', '-', $floating_pos ) ); ?>" style="<?php echo esc_attr( $this->get_floating_container_style( $floating_pos ) ); ?>">
            <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo $this->get_agent_button_html(); ?>
        </div>
        <!-- End Gemini Enterprise for CX Floating Button -->
        <?php endif; ?>

        <!-- Start Gemini Enterprise for CX WooCommerce Chat Widget -->
        <gecx-woocommerce-chat-widget
            agent-name="<?php echo esc_attr( $agent_name ); ?>"
            store-url="<?php echo esc_attr( $store_url ); ?>"
            rest-url="<?php echo esc_attr( $rest_url ); ?>"
            token-broker="<?php echo esc_attr( $token_broker ); ?>"
            environment="prod"
            hide-launcher
            <?php if ( is_customize_preview() ) : ?>
            preview="true"
            <?php endif; ?>>
        </gecx-woocommerce-chat-widget>
        <!-- End Gemini Enterprise for CX WooCommerce Chat Widget -->
        <?php
    }

    /**
     * Helper to get active agent name (handling filters).
     */
    protected function get_active_agent_name(): string {
        $agent_name = (string) get_option( 'gecx_agent_name', '' );
        if ( function_exists( 'apply_filters' ) ) {
            return (string) apply_filters( 'gecx_active_agent_name', $agent_name );
        }
        return $agent_name;
    }

    /**
     * Helper to check if widget is enabled.
     */
    protected function is_widget_enabled(): bool {
        $enabled = (bool) get_option( 'gecx_agent_enabled', 0 );
        if ( function_exists( 'apply_filters' ) ) {
            return (bool) apply_filters( 'gecx_is_widget_enabled', $enabled );
        }
        return $enabled;
    }

    /**
     * Helper to get token broker.
     */
    protected function get_token_broker(): string {
        $token_broker = (string) get_option( 'gecx_token_broker_name', '' );
        if ( function_exists( 'apply_filters' ) ) {
            return (string) apply_filters( 'gecx_token_broker_name', $token_broker );
        }
        return $token_broker;
    }

    /**
     * Resolve Google CDN hosted widget JS and CSS URLs.
     */
    protected function resolve_widget_urls(): array {
        return [
            // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Official Google CDN hosted web component script.
            'script' => apply_filters( 'gecx_chat_widget_script_url', 'https://www.gstatic.com/gecx/chat-widget/woocommerce-chat-widget.js' ),
            'style'  => apply_filters( 'gecx_chat_widget_style_url', plugins_url( 'assets/css/theme.css', $this->plugin_file ) ),
        ];
    }

    /**
     * Inject suggested prompts component on single product pages.
     */
    public function inject_suggested_prompts(): void {
        if ( is_admin() || ! is_product() || ! $this->is_pdp_prompts_auto_inject_enabled() ) {
            return;
        }

        if ( $this->pdp_prompts_injected && ! apply_filters( 'gecx_allow_multiple_pdp_prompt_injections', false ) ) {
            return;
        }

        $this->render_suggested_prompts( (int) get_the_ID() );
    }

    /**
     * Inject suggested prompts into WooCommerce Single Product Blocks (FSE / Block Themes).
     *
     * @param string $block_content Rendered block HTML.
     * @param array  $block         Block data array.
     * @return string Modified block HTML.
     */
    public function inject_block_suggested_prompts( string $block_content, array $block = [] ): string {
        if ( is_admin() || ! is_product() || ! $this->is_pdp_prompts_auto_inject_enabled() ) {
            return $block_content;
        }

        if ( empty( trim( $block_content ) ) ) {
            return $block_content;
        }

        if ( $this->pdp_prompts_injected && ! apply_filters( 'gecx_allow_multiple_pdp_prompt_injections', false ) ) {
            return $block_content;
        }

        if ( false !== strpos( $block_content, 'gecx-suggested-prompts' ) ) {
            return $block_content;
        }

        $product_id                 = self::resolve_current_product_id();
        $prompts_html               = $this->get_suggested_prompts_html( $product_id );
        $this->pdp_prompts_injected = true;

        return $block_content . $prompts_html;
    }

    /**
     * Render suggested prompts via shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_suggested_prompts_shortcode( array $atts = [] ): string {
        $product_id = 0;
        if ( isset( $atts['id'] ) ) {
            $product_id = absint( $atts['id'] );
        } elseif ( isset( $atts['product_id'] ) ) {
            $product_id = absint( $atts['product_id'] );
        } elseif ( is_product() ) {
            $product_id = self::resolve_current_product_id();
        }

        if ( ! $product_id ) {
             return '';
        }

        if ( ! $this->is_pdp_prompts_configured() ) {
            return '';
        }

        $html = $this->get_suggested_prompts_html( $product_id );
        if ( ! empty( $html ) ) {
            $this->pdp_prompts_injected = true;
        }

        return $html;
    }

    /**
     * Helper to check if PDP prompts are configured (widget enabled and agent set).
     */
    protected function is_pdp_prompts_configured(): bool {
        return $this->is_widget_enabled() && (bool) $this->get_active_agent_name();
    }

    /**
     * Helper to check if automatic injection is enabled.
     */
    protected function is_pdp_prompts_auto_inject_enabled(): bool {
        return (bool) get_option( 'gecx_pdp_prompts_enabled', 1 ) && $this->is_pdp_prompts_configured();
    }

    /**
     * Render the suggested prompts custom element.
     *
     * @param int $product_id Product ID.
     */
    protected function render_suggested_prompts( int $product_id = 0 ): void {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_suggested_prompts_html() returns properly escaped markup.
        echo $this->get_suggested_prompts_html( $product_id );
        $this->pdp_prompts_injected = true;
    }

    /**
     * Get suggested prompts HTML markup for a product.
     *
     * @param int $product_id Product ID.
     * @return string HTML output.
     */
    /**
     * Resolves the product ID of the product currently being displayed.
     *
     * get_the_ID() returns false outside the loop, which is the case while
     * enqueueing assets and on block themes, so fall back to the queried
     * object when the loop cannot answer.
     *
     * @return int Product ID, or 0 when none can be resolved.
     */
    private static function resolve_current_product_id(): int {
        $product_id = (int) get_the_ID();
        if ( $product_id > 0 ) {
            return $product_id;
        }

        if ( function_exists( 'get_queried_object_id' ) ) {
            return (int) get_queried_object_id();
        }

        return 0;
    }

    public function get_suggested_prompts_html( int $product_id = 0 ): string {
        if ( $product_id <= 0 && is_product() ) {
            $product_id = self::resolve_current_product_id();
        }

        $attrs = [
            'chat-widget-selector' => 'gecx-woocommerce-chat-widget',
            'direction'            => 'row',
            'show-input'           => true,
            'enable-shimmer'       => true,
            'branding'             => 'none',
        ];

        if ( is_customize_preview() ) {
            $attrs['preview'] = 'true';
        }

        if ( $product_id > 0 ) {
            $override = get_post_meta( $product_id, '_gecx_suggested_prompts_override', true );
            if ( ! empty( $override ) ) {
                $prompts_array = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $override ) ) );
                if ( ! empty( $prompts_array ) ) {
                    $attrs['static-prompts'] = wp_json_encode( array_values( $prompts_array ) );
                }
            }
        }

        $attrs = apply_filters( 'gecx_suggested_prompts_attributes', $attrs );

        $attrs_string = '';
        foreach ( $attrs as $key => $val ) {
            if ( true === $val || '' === $val ) {
                $attrs_string .= ' ' . esc_attr( (string) $key );
            } elseif ( false !== $val && null !== $val ) {
                $attrs_string .= ' ' . esc_attr( (string) $key ) . '="' . esc_attr( (string) $val ) . '"';
            }
        }

        return "\n<!-- Start Gemini Enterprise for CX WooCommerce Suggested Prompts -->\n" .
               '<gecx-suggested-prompts' . $attrs_string . ">\n</gecx-suggested-prompts>\n" .
               "<!-- End Gemini Enterprise for CX WooCommerce Suggested Prompts -->\n";
    }

    /**
     * Helper to get CSS style for the floating container position.
     *
     * @param string $position Floating position key.
     * @return string Inline CSS string.
     */
    protected function get_floating_container_style( string $position ): string {
        switch ( $position ) {
            case 'center_right':
                return 'position: fixed; top: 50%; right: 0; transform: translateY(-50%); z-index: 999999;';
            case 'bottom_center':
            default:
                return 'position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); z-index: 999999;';
        }
    }

    /**
     * Render the agent button custom element HTML.
     *
     * @param array $overrides Attribute overrides.
     * @return string HTML output.
     */
    public function get_agent_button_html( array $overrides = [] ): string {
        $display_style       = (string) get_option( 'gecx_button_display_style', 'responsive' );
        $label               = (string) get_option( 'gecx_button_label', '' );
        $default_short_label = __( 'Shop', 'gemini-enterprise-for-cx' );
        $short_label         = (string) get_option( 'gecx_button_short_label', $default_short_label );
        if ( empty( $short_label ) ) {
            $short_label = $default_short_label;
        }
        $enable_shimmer = (bool) get_option( 'gecx_button_enable_shimmer', 1 );

        $attrs = [
            'display-style'    => $display_style,
            'collapse-to'      => 'short-label',
            'preset-icon'      => 'button_magic',
            'enable-shimmer'   => $enable_shimmer ? 'true' : 'false',
            'hide-chat-bubble' => true,
        ];

        if ( ! empty( $label ) ) {
            $attrs['label'] = $label;
        }
        if ( ! empty( $short_label ) ) {
            $attrs['short-label'] = $short_label;
        }

        $attrs = array_merge( $attrs, $overrides );

        if ( is_customize_preview() ) {
            $attrs['preview'] = 'true';
        }

        $attrs = apply_filters( 'gecx_agent_button_attributes', $attrs );

        $attrs_string = '';
        foreach ( $attrs as $key => $val ) {
            if ( true === $val ) {
                $attrs_string .= ' ' . esc_attr( (string) $key );
            } elseif ( '' === $val ) {
                $attrs_string .= ' ' . esc_attr( (string) $key );
            } elseif ( false !== $val && null !== $val ) {
                $attrs_string .= ' ' . esc_attr( (string) $key ) . '="' . esc_attr( (string) $val ) . '"';
            }
        }

        return '<gecx-agent-button' . $attrs_string . '></gecx-agent-button>';
    }
}
