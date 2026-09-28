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
     * Menus the launcher was placed in during the request, keyed by
     * nav_menu_injection_key().
     *
     * @var array<string, bool>
     */
    protected array $nav_injected_menus = [];

    /**
     * Track whether suggested prompts have already been injected for the request.
     */
    protected bool $pdp_prompts_injected = false;

    /**
     * Allowed button placements.
     */
    public const ALLOWED_BUTTON_PLACEMENTS = [ 'nav_menu', 'floating', 'manual' ];

    /**
     * Default button placement.
     */
    public const DEFAULT_BUTTON_PLACEMENT = 'nav_menu';

    /**
     * Allowed button display styles.
     */
    public const ALLOWED_DISPLAY_STYLES = [ 'responsive', 'icon-and-label', 'icon-only', 'label-only' ];

    /**
     * Default button display style.
     */
    public const DEFAULT_DISPLAY_STYLE = 'responsive';

    /**
     * Allowed floating positions.
     */
    public const ALLOWED_FLOATING_POSITIONS = [ 'bottom_center', 'bottom_left', 'bottom_right', 'center_left', 'center_right' ];

    /**
     * Default floating position.
     */
    public const DEFAULT_FLOATING_POSITION = 'bottom_center';

    /**
     * Cart count badges of the WooCommerce block mini-cart.
     */
    public const DEFAULT_CART_BADGE_SELECTORS = [
        '.wc-block-mini-cart__badge',
        '.wc-block-components-mini-cart__badge',
    ];

    /**
     * Sanitize button placement to an allowed key.
     *
     * @param mixed $placement Button placement input.
     * @return string Sanitized placement key.
     */
    public static function sanitize_button_placement( $placement ): string {
        if ( is_string( $placement ) && in_array( $placement, self::ALLOWED_BUTTON_PLACEMENTS, true ) ) {
            return $placement;
        }
        return self::DEFAULT_BUTTON_PLACEMENT;
    }

    /**
     * Sanitize button display style to an allowed key.
     *
     * @param mixed $style Button display style input.
     * @return string Sanitized display style key.
     */
    public static function sanitize_button_display_style( $style ): string {
        if ( is_string( $style ) && in_array( $style, self::ALLOWED_DISPLAY_STYLES, true ) ) {
            return $style;
        }
        return self::DEFAULT_DISPLAY_STYLE;
    }

    /**
     * Sanitize floating position to an allowed key.
     *
     * @param mixed $position Floating position input.
     * @return string Sanitized position key.
     */
    public static function sanitize_floating_position( $position ): string {
        if ( is_string( $position ) && in_array( $position, self::ALLOWED_FLOATING_POSITIONS, true ) ) {
            return $position;
        }
        return self::DEFAULT_FLOATING_POSITION;
    }

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
        // Priority 20: WooCommerce registers wc-cart-fragments at priority 10.
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_cart_fragments' ], 20 );
        add_action( 'wp_footer', [ $this, 'inject_chat_widget' ] );
        add_filter( 'wp_nav_menu_items', [ $this, 'inject_nav_menu_agent_button' ], 10, 2 );
        add_filter( 'wp_page_menu', [ $this, 'inject_page_menu_agent_button' ], 10, 2 );
        add_filter( 'render_block_core/navigation', [ $this, 'inject_block_navigation_agent_button' ], 10, 2 );

        $prompts_hook     = apply_filters( 'gecx_suggested_prompts_hook', 'woocommerce_single_product_summary' );
        $prompts_priority = apply_filters( 'gecx_suggested_prompts_priority', 35 );
        add_action( $prompts_hook, [ $this, 'inject_suggested_prompts' ], $prompts_priority );

        if ( apply_filters( 'gecx_enable_secondary_pdp_hook', false ) ) {
            add_action( 'woocommerce_after_add_to_cart_form', [ $this, 'inject_suggested_prompts' ], 20 );
        }

        // After the add-to-cart form, or the details tabs below it when a
        // template has no form. Not after the product summary: block themes
        // such as Twenty Twenty-Five render it above the form, so it would
        // put the prompts between the price and the add-to-cart button.
        add_filter( 'render_block_woocommerce/add-to-cart-form', [ $this, 'inject_block_suggested_prompts' ], 10, 2 );
        add_filter( 'render_block_woocommerce/single-product-details', [ $this, 'inject_block_suggested_prompts' ], 10, 2 );
        add_shortcode( 'gecx_suggested_prompts', [ $this, 'render_suggested_prompts_shortcode' ] );
        add_shortcode( 'gecx_agent_button', [ $this, 'render_agent_button_shortcode' ] );

        // Keep "delay JavaScript" optimizers from holding the launcher back.
        add_filter( 'script_loader_tag', [ $this, 'exclude_script_from_optimizers' ], 10, 2 );
        add_filter( 'wp_inline_script_attributes', [ $this, 'exclude_inline_config_from_optimizers' ] );
        add_filter( 'rocket_delay_js_exclusions', [ $this, 'add_wp_rocket_delay_exclusions' ] );
        add_action( 'init', [ $this, 'register_blocks' ] );
    }

    /**
     * Script handles that must run on page load for the launcher to render.
     */
    private const UNDELAYED_SCRIPT_HANDLES = [ 'gecx-storefront-js', 'gecx-widget-script' ];

    /**
     * Attributes that opt a script out of delaying, deferring and combining by
     * LiteSpeed Cache (data-no-optimize, data-no-defer) and Cloudflare Rocket
     * Loader (data-cfasync).
     */
    private const OPTIMIZER_OPT_OUT_ATTRIBUTES = [
        'data-cfasync'     => 'false',
        'data-no-optimize' => '1',
        'data-no-defer'    => '1',
    ];

    /**
     * Whether the launcher scripts are kept out of "delay JavaScript until
     * interaction" optimizations.
     *
     * Delayed, the launcher stays an empty, undefined custom element until
     * the shopper happens to move the mouse or scroll. A merchant who wants
     * the widget to load on interaction has the plugin's own setting for it,
     * which still shows the launcher.
     */
    private function should_exclude_from_optimizers(): bool {
        /**
         * Filters whether the launcher scripts are excluded from JavaScript
         * delay, defer and combine optimizations.
         *
         * @param bool $exclude Default true.
         */
        return (bool) apply_filters( 'gecx_exclude_from_js_delay', true );
    }

    /**
     * Adds optimizer opt-out attributes to the launcher script tags.
     *
     * @param string $tag    Script tag markup.
     * @param string $handle Script handle.
     * @return string Script tag markup.
     */
    public function exclude_script_from_optimizers( string $tag, string $handle = '' ): string {
        if ( ! in_array( $handle, self::UNDELAYED_SCRIPT_HANDLES, true ) || ! $this->should_exclude_from_optimizers() ) {
            return $tag;
        }
        $attributes = '';
        foreach ( self::OPTIMIZER_OPT_OUT_ATTRIBUTES as $name => $value ) {
            $attributes .= ' ' . $name . '="' . $value . '"';
        }
        return (string) preg_replace( '/<script\b(?![^>]*\bdata-no-optimize=)/i', '<script' . $attributes, $tag );
    }

    /**
     * Adds optimizer opt-out attributes to the inline script carrying
     * gecxStorefrontConfig, without which storefront.js has nothing to place.
     *
     * @param array $attributes Inline script tag attributes.
     * @return array Inline script tag attributes.
     */
    public function exclude_inline_config_from_optimizers( $attributes ) {
        if ( ! is_array( $attributes ) || ! isset( $attributes['id'] ) || 'gecx-storefront-js-js-extra' !== $attributes['id'] ) {
            return $attributes;
        }
        return $this->should_exclude_from_optimizers() ? array_merge( $attributes, self::OPTIMIZER_OPT_OUT_ATTRIBUTES ) : $attributes;
    }

    /**
     * Excludes the launcher scripts from WP Rocket's "Delay JavaScript
     * execution".
     *
     * @param array $exclusions Patterns matched against script sources and inline script content.
     * @return array Patterns.
     */
    public function add_wp_rocket_delay_exclusions( $exclusions ) {
        if ( ! is_array( $exclusions ) || ! $this->should_exclude_from_optimizers() ) {
            return $exclusions;
        }
        $storefront_path = (string) wp_parse_url( plugins_url( 'assets/js/storefront.js', $this->plugin_file ), PHP_URL_PATH );
        $widget_host     = (string) wp_parse_url( (string) $this->resolve_widget_urls()['script'], PHP_URL_HOST );
        $widget_path     = (string) wp_parse_url( (string) $this->resolve_widget_urls()['script'], PHP_URL_PATH );
        foreach ( [ $storefront_path, $widget_host . $widget_path, 'gecxStorefrontConfig' ] as $pattern ) {
            if ( '' !== $pattern && ! in_array( $pattern, $exclusions, true ) ) {
                $exclusions[] = $pattern;
            }
        }
        return $exclusions;
    }

    /**
     * Renders the launcher wherever a merchant places it: in a page builder
     * header, a block theme's header template part, or a widget area.
     *
     * This is how themes and builders the automatic placement cannot reach
     * are served, and it pairs with the "manual" placement setting, which
     * turns the automatic placement off.
     *
     * @return string Launcher markup, or '' when the widget is not active.
     */
    public function render_agent_button_shortcode(): string {
        if ( is_admin() || $this->is_amp_request() || ! $this->is_widget_enabled() || ! $this->get_active_agent_name() ) {
            return '';
        }
        return '<span class="gecx-agent-button-slot">' . $this->get_agent_button_html() . '</span>';
    }

    /**
     * Registers the plugin's blocks, the block editor counterparts of the
     * [gecx_agent_button] and [gecx_suggested_prompts] shortcodes.
     *
     * Both are rendered on the server. In the editor they show placeholders:
     * the launcher and prompts are custom elements defined by the
     * Google-hosted widget bundle, which the editor does not load.
     */
    public function register_blocks(): void {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }
        wp_register_script(
            'gecx-editor-blocks',
            plugins_url( 'assets/js/editor-blocks.js', $this->plugin_file ),
            [ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ],
            defined( 'GECX_VERSION' ) ? GECX_VERSION : null,
            true
        );
        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations(
                'gecx-editor-blocks',
                'gemini-enterprise-for-cx',
                plugin_dir_path( $this->plugin_file ) . 'languages'
            );
        }
        register_block_type(
            'gecx/agent-button',
            [
                'api_version'     => 2,
                'title'           => __( 'Gemini Enterprise for CX Launcher', 'gemini-enterprise-for-cx' ),
                'category'        => 'widgets',
                'editor_script'   => 'gecx-editor-blocks',
                'render_callback' => [ $this, 'render_agent_button_shortcode' ],
                'supports'        => [
                    'html'     => false,
                    'multiple' => false,
                ],
            ]
        );
        register_block_type(
            'gecx/suggested-prompts',
            [
                'api_version'     => 2,
                'title'           => __( 'Gemini Enterprise for CX Suggested Prompts', 'gemini-enterprise-for-cx' ),
                'category'        => 'widgets',
                'editor_script'   => 'gecx-editor-blocks',
                'attributes'      => [
                    'productId' => [
                        'type'    => 'number',
                        'default' => 0,
                    ],
                ],
                'uses_context'    => [ 'postId' ],
                'render_callback' => [ $this, 'render_suggested_prompts_block' ],
                'supports'        => [
                    'html'     => false,
                    'multiple' => false,
                ],
            ]
        );
    }

    /**
     * Renders the gecx/suggested-prompts block.
     *
     * Uses the block's Product ID setting when one is set. Otherwise the
     * product comes from the block context (the Single Product block and
     * template) or from the product page being viewed.
     *
     * @param array $attributes Block attributes.
     * @param string $content    Block content (unused; the block is dynamic).
     * @param mixed  $block      WP_Block instance.
     * @return string Prompts markup, or '' when there is no product.
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- render_callback signature.
    public function render_suggested_prompts_block( $attributes = [], $content = '', $block = null ): string {
        $product_id = is_array( $attributes ) && isset( $attributes['productId'] ) ? absint( $attributes['productId'] ) : 0;
        if ( 0 === $product_id && is_object( $block ) && isset( $block->context['postId'] ) ) {
            $context_id = absint( $block->context['postId'] );
            if ( $context_id > 0 && function_exists( 'wc_get_product' ) && wc_get_product( $context_id ) ) {
                $product_id = $context_id;
            }
        }
        return $this->render_suggested_prompts_shortcode( $product_id > 0 ? [ 'id' => $product_id ] : [] );
    }

    /**
     * Enqueue storefront styles and scripts.
     */
    public function enqueue_storefront_assets(): void {
        // wp_enqueue_scripts also fires for requests that render no storefront
        // page, and nothing downstream of this point is meaningful for them.
        if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || $this->is_amp_request() ) {
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
            // Which of the in-menu item and the mobile header button shows is
            // decided in storefront.js from whether the theme's hamburger is
            // actually visible. Themes switch to it anywhere between 600px
            // and 1024px, so no fixed breakpoint here can be right for all of
            // them. The menu item's own layout uses :where() so it has no
            // specificity and the theme's menu styles, vertical ones included,
            // win over it.
            'footer .gecx-nav-menu-item, .site-footer .gecx-nav-menu-item, [role="contentinfo"] .gecx-nav-menu-item, .wp-block-template-part:has(footer) .gecx-nav-menu-item, #colophon .gecx-nav-menu-item, .elementor-location-footer .gecx-nav-menu-item { display: none !important; }' .
            ' :where(.gecx-nav-menu-item) { display: flex; align-items: center; justify-content: center; align-self: center; height: auto; margin: 0 4px; list-style: none; }' .
            ' .gecx-nav-menu-item gecx-agent-button { display: inline-flex; align-items: center; vertical-align: middle; }' .
            ' .wp-block-navigation__responsive-container.is-menu-open .gecx-nav-menu-item { display: none !important; }' .
            ' .gecx-mobile-header-button { display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; margin: 0 6px; align-self: center; height: auto; line-height: normal; }' .
            ' .gecx-mobile-header-button gecx-agent-button { display: inline-flex; align-items: center; vertical-align: middle; }' .
            ' .gecx-mobile-header-button.gecx-mobile-header-button--floating { position: fixed; bottom: calc(20px + var(--gecx-floating-offset, 0px) + var(--gecx-floating-extra-offset, 0px) + env(safe-area-inset-bottom, 0px)); right: 20px; z-index: 99999; margin: 0; height: auto; }' .
            ' body.rtl .gecx-mobile-header-button.gecx-mobile-header-button--floating { right: auto; left: 20px; }' .
            ' chat-messenger.slide-over { position: fixed !important; }' .
            ' .gecx-floating-button-container { transition: transform 0.5s cubic-bezier(0.32, 0.72, 0, 1); }' .
            ' body.gecx-chat-no-transition .gecx-floating-button-container { transition: none !important; }' .
            ' @media (prefers-reduced-motion: reduce) { .gecx-floating-button-container { transition: none !important; } }' .
            ' body.gecx-chat-open .gecx-floating-button-container--center-right, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container--center-right,' .
            ' body.gecx-chat-open .gecx-floating-button-container--bottom-right, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container--bottom-right { display: none !important; }' .
            ' @media (min-width: 600px) and (max-width: 839.98px) {' .
            '   body.gecx-chat-open .gecx-floating-button-container--bottom-center, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container--bottom-center {' .
            '     transform: translateX(calc(-50% - var(--gecx-chat-panel-width, 360px) / 2)) !important;' .
            '   }' .
            ' }' .
            ' @media (min-width: 840px) {' .
            '   body.gecx-chat-open .gecx-floating-button-container--bottom-center, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container--bottom-center {' .
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

        $defer_until_interaction = (bool) get_option( 'gecx_defer_widget_until_interaction', false );
        /**
         * Filters whether the Google-hosted chat widget bundle should be
         * enqueued immediately on page load.
         *
         * Consent management platforms (WP Consent API, Cookiebot, Complianz)
         * or stores opting into interaction-triggered loading can return false
         * here and trigger loading later via `window.gecxLoadWidget()`, the
         * `gecx:consent-granted` DOM event, or shopper interaction with a
         * launcher/prompt element.
         *
         * @param bool $should_load Whether to enqueue the widget bundle immediately.
         */
        $should_load_widget = (bool) apply_filters( 'gecx_should_load_widget', ! $defer_until_interaction );

        if ( $should_load_widget ) {
            wp_enqueue_script( 'gecx-widget-script', $urls['script'], [], $version, true );
        }

        wp_enqueue_script(
            'gecx-storefront-js',
            plugins_url( 'assets/js/storefront.js', $this->plugin_file ),
            [],
            $version,
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations(
                'gecx-storefront-js',
                'gemini-enterprise-for-cx',
                plugin_dir_path( $this->plugin_file ) . 'languages'
            );
        }

        $is_cart     = function_exists( 'is_cart' ) && is_cart();
        $is_checkout = function_exists( 'is_checkout' ) && is_checkout();
        $placement   = (string) get_option( 'gecx_button_placement', 'nav_menu' );
        $config      = [
            'placement'        => $placement,
            'buttonHtml'       => $this->get_agent_button_html(),
            'isWidgetEnabled'  => $enabled,
            'widgetScriptUrl'  => esc_url_raw( (string) $urls['script'] ),
            'shouldLoadWidget' => $should_load_widget,
            // Resolved here rather than assembled in the browser. rest_url()
            // accounts for subdirectory installs, a custom rest_url_prefix and
            // the plain-permalink ?rest_route= form, none of which the script
            // can infer from window.location.
            'cartRestUrl'      => esc_url_raw( rest_url( 'wc/store/v1/cart' ) ),
            // The nonce itself is deliberately not localized here. This config
            // is rendered into the page body, and storefront HTML is cached by
            // WP Rocket, LiteSpeed and Cloudflare, so a nonce baked in at
            // render time is stale within 12-24 hours and the Store API answers
            // 403. The script fetches a fresh one from this route instead,
            // which is uncacheable by construction (nocache_headers() plus
            // Cache-Control: no-store). Same approach the chat widget bundle
            // takes.
            'authContextUrl'   => esc_url_raw( rest_url( 'gecx/v1/auth-context' ) ),
            'sessionUrl'       => esc_url_raw( rest_url( 'gecx/v1/session' ) ),
            // WooCommerce resolves these from the store's configured page IDs,
            // so a store using localized slugs such as /panier still answers
            // correctly, and a product whose slug merely starts with "cart"
            // no longer does.
            'isCart'           => $is_cart,
            'isCheckout'       => $is_checkout,
            'isCartOrCheckout' => $is_cart || $is_checkout,
            // Nested so the booleans keep their type: wp_localize_script()
            // casts top-level scalars to strings, and false becomes "".
            'cartRefresh'      => self::get_cart_refresh_config(),
        ];

        if ( $this->is_pdp_prompts_auto_inject_enabled() ) {
            $is_product_page = is_product();
            // Also true for a page embedding a product with [product_page] or
            // the Single Product block, which is_product() does not report.
            $config['isPdp'] = $is_product_page || $this->page_embeds_single_product();
            // Localized on every page so storefront.js can also place prompts
            // for a product loaded without a full page load. Only a product
            // page knows which product's prompt overrides to use.
            $config['pdpPromptsHtml'] = $this->get_suggested_prompts_html( $is_product_page ? self::resolve_current_product_id() : 0 );
        }

        wp_localize_script( 'gecx-storefront-js', 'gecxStorefrontConfig', $config );
    }

    /**
     * Enqueues WooCommerce's cart fragments script on classic themes.
     *
     * Classic theme headers (Storefront, Astra, Flatsome, the Elementor and
     * Divi menu carts and others) only redraw their cart count when
     * wc-cart-fragments answers `wc_fragment_refresh`, which storefront.js
     * triggers after the agent changes the cart. Since WooCommerce 7.8 that
     * script is no longer loaded on every page by default, so a theme that
     * does not enqueue it itself would keep showing the old count.
     *
     * Block themes are skipped: their mini-cart block reads the wc/store/cart
     * data store, and the script would pull in jQuery for nothing.
     */
    public function enqueue_cart_fragments(): void {
        if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || $this->is_amp_request() ) {
            return;
        }
        if ( ! $this->get_active_agent_name() || ! $this->is_widget_enabled() ) {
            return;
        }

        $is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

        /**
         * Filters whether the plugin enqueues wc-cart-fragments so classic
         * theme cart counts update after the agent changes the cart.
         *
         * @param bool $enqueue Defaults to true on classic themes and false on block themes.
         */
        if ( ! apply_filters( 'gecx_enqueue_cart_fragments', ! $is_block_theme ) ) {
            return;
        }

        if ( wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
            wp_enqueue_script( 'wc-cart-fragments' );
        }
    }

    /**
     * Settings storefront.js uses to refresh cart surfaces after the agent
     * changes the cart.
     *
     * @return array{nativeEvents: bool, legacyEvents: bool, badgeSelectors: string[]}
     */
    public static function get_cart_refresh_config(): array {
        /**
         * Filters the selectors of cart count badges storefront.js writes the
         * new item count into when the WooCommerce Blocks cart data store is
         * not on the page.
         *
         * Only add elements whose whole text is the bare count. Counts
         * rendered as text such as "3 items" are refreshed through cart
         * fragments instead.
         *
         * @param string[] $selectors CSS selectors.
         */
        $selectors = apply_filters( 'gecx_cart_badge_selectors', self::DEFAULT_CART_BADGE_SELECTORS );
        $selectors = is_array( $selectors ) ? $selectors : self::DEFAULT_CART_BADGE_SELECTORS;
        $selectors = array_values(
            array_filter(
                array_map(
                    static function ( $selector ): string {
                        // JSON-encoded into the page and only ever passed to
                        // querySelectorAll(), which rejects invalid selectors.
                        return is_string( $selector ) ? trim( $selector ) : '';
                    },
                    $selectors
                )
            )
        );

        return [
            /**
             * Filters whether storefront.js dispatches the WooCommerce Blocks
             * wc-blocks_added_to_cart and wc-blocks_removed_from_cart events
             * after the agent changes the cart. The block mini-cart refreshes
             * its badge on them.
             *
             * @param bool $enabled Default true.
             */
            'nativeEvents'   => (bool) apply_filters( 'gecx_cart_refresh_native_events', true ),
            /**
             * Filters whether storefront.js triggers the jQuery
             * added_to_cart and removed_from_cart events instead of the
             * native ones. Off by default: many themes open a side cart on
             * them, and their handlers expect arguments only a real
             * add-to-cart click provides. The block mini-cart translates
             * them into the native events itself.
             *
             * @param bool $enabled Default false.
             */
            'legacyEvents'   => (bool) apply_filters( 'gecx_cart_refresh_legacy_events', false ),
            'badgeSelectors' => $selectors,
        ];
    }

    /**
     * Theme locations the launcher is injected into automatically.
     *
     * Includes the drawer and handheld locations themes render their mobile
     * menu into: storefront.js hides the in-menu item whenever the theme's
     * hamburger is visible, so the copy there never shows beside the mobile
     * header button. Utility locations such as 'top' and 'footer' are
     * deliberately absent.
     */
    public const DEFAULT_NAV_MENU_LOCATIONS = [
        'primary',
        'main',
        'main-menu',
        'main_menu',
        'main_nav',
        'main_navigation',
        'header',
        'header-menu',
        'header_menu',
        'menu-1',
        'menu_1',
        'primary-menu',
        'primary_menu',
        'primary_navigation',
        'nav-menu',
        'expanded',
        'mobile',
        'mobile-menu',
        'mobile_menu',
        'handheld',
    ];

    /**
     * Sanitizes the launcher menu target option.
     *
     * '' means automatic. 'location:<slug>' names a registered theme location
     * and 'menu:<id>' a navigation menu, which is how menus rendered by page
     * builders without a theme location are reached.
     *
     * @param mixed $target Raw option value.
     * @return string Sanitized target.
     */
    public static function sanitize_nav_menu_target( $target ): string {
        if ( ! is_string( $target ) ) {
            return '';
        }
        if ( 1 === preg_match( '/^location:[A-Za-z0-9_\-]+$/', $target ) ) {
            return $target;
        }
        if ( 1 === preg_match( '/^menu:[1-9][0-9]*$/', $target ) ) {
            return $target;
        }
        return '';
    }

    /**
     * Whether the launcher should be placed automatically in navigation menus.
     */
    private function should_inject_into_menus(): bool {
        if ( is_admin() || $this->is_amp_request() || ! $this->is_widget_enabled() || ! $this->get_active_agent_name() ) {
            return false;
        }
        return 'nav_menu' === self::sanitize_button_placement( get_option( 'gecx_button_placement', self::DEFAULT_BUTTON_PLACEMENT ) );
    }

    /**
     * Resolves which menu a wp_nav_menu() or wp_page_menu() call renders, and
     * whether the launcher belongs in it.
     *
     * @param mixed $args Menu arguments, as an object (wp_nav_menu) or array (wp_page_menu).
     * @return string Key identifying the menu for once-only injection, or ''
     *                when the launcher does not belong in it.
     */
    private function nav_menu_injection_key( $args ): string {
        $args     = is_object( $args ) ? get_object_vars( $args ) : ( is_array( $args ) ? $args : [] );
        $location = isset( $args['theme_location'] ) && is_string( $args['theme_location'] ) ? $args['theme_location'] : '';
        $target   = self::sanitize_nav_menu_target( get_option( 'gecx_nav_menu_target', '' ) );

        if ( 0 === strpos( $target, 'location:' ) ) {
            return ( '' !== $location && substr( $target, 9 ) === $location ) ? 'location:' . $location : '';
        }

        if ( 0 === strpos( $target, 'menu:' ) ) {
            $menu_id = $this->resolve_nav_menu_id( $args['menu'] ?? null, $location );
            return ( $menu_id > 0 && 'menu:' . $menu_id === $target ) ? $target : '';
        }

        $locations = apply_filters( 'gecx_nav_menu_locations', self::DEFAULT_NAV_MENU_LOCATIONS );
        if ( '' !== $location && is_array( $locations ) && in_array( $location, $locations, true ) ) {
            return 'location:' . $location;
        }
        if ( apply_filters( 'gecx_inject_in_all_menus', false ) ) {
            return '' !== $location ? 'location:' . $location : 'menu:' . $this->resolve_nav_menu_id( $args['menu'] ?? null, '' );
        }
        return '';
    }

    /**
     * ID of the navigation menu a menu call renders.
     *
     * @param mixed  $menu     The 'menu' argument: a term, ID, slug or name.
     * @param string $location The 'theme_location' argument.
     */
    private function resolve_nav_menu_id( $menu, string $location ): int {
        if ( ! empty( $menu ) && function_exists( 'wp_get_nav_menu_object' ) ) {
            $object = wp_get_nav_menu_object( $menu );
            if ( $object && isset( $object->term_id ) ) {
                return (int) $object->term_id;
            }
        }
        if ( '' !== $location && function_exists( 'get_nav_menu_locations' ) ) {
            $locations = get_nav_menu_locations();
            if ( isset( $locations[ $location ] ) ) {
                return (int) $locations[ $location ];
            }
        }
        return 0;
    }

    /**
     * Whether the launcher was already placed in this menu during the request.
     *
     * Tracked per menu rather than per request: themes such as Storefront,
     * Astra, Kadence and OceanWP render the desktop menu and the mobile drawer
     * menu from separate locations, and each needs its own copy.
     *
     * @param string $key Menu key from nav_menu_injection_key().
     */
    private function claim_nav_menu( string $key ): bool {
        if ( isset( $this->nav_injected_menus[ $key ] ) && ! apply_filters( 'gecx_allow_multiple_nav_injections', false ) ) {
            return false;
        }
        $this->nav_injected_menus[ $key ] = true;
        $this->nav_injected               = true;
        return true;
    }

    /**
     * Inserts markup just before the closing tag of the first matching list.
     *
     * The list is closed where its own nesting says it is, so markup never
     * lands inside a submenu or after the list, whatever the menu contains.
     *
     * @param string $html       Rendered markup.
     * @param string $item       Markup to insert.
     * @param string $list_class Class the list must carry, or '' for the first list.
     * @return string|null Updated markup, or null when no such list exists.
     */
    public static function insert_into_list( string $html, string $item, string $list_class = '' ): ?string {
        $pattern = '' === $list_class
            ? '/<ul\b[^>]*>/i'
            : '/<ul\b[^>]*\bclass\s*=\s*(["\'])(?:[^"\']*\s)?' . preg_quote( $list_class, '/' ) . '(?:\s[^"\']*)?\1[^>]*>/i';
        if ( 1 !== preg_match( $pattern, $html, $match, PREG_OFFSET_CAPTURE ) ) {
            return null;
        }
        if ( ! preg_match_all( '/<(\/?)ul\b[^>]*>/i', $html, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, (int) $match[0][1] ) ) {
            return null;
        }
        $depth = 0;
        foreach ( $tags as $tag ) {
            $depth += '' === $tag[1][0] ? 1 : -1;
            if ( 0 === $depth ) {
                return substr( $html, 0, (int) $tag[0][1] ) . $item . substr( $html, (int) $tag[0][1] );
            }
        }
        return null;
    }

    /**
     * Inject agent button into WordPress navigation menus (Classic Themes).
     *
     * @param string $items HTML list items for the menu.
     * @param mixed  $args  Menu args object/array.
     * @return string Modified HTML menu items.
     */
    public function inject_nav_menu_agent_button( string $items, $args = null ): string {
        if ( ! $this->should_inject_into_menus() ) {
            return $items;
        }

        $key = $this->nav_menu_injection_key( $args );
        if ( '' === $key || ! $this->claim_nav_menu( $key ) ) {
            return $items;
        }

        return $items . '<li class="menu-item gecx-nav-menu-item">' . $this->get_agent_button_html() . '</li>';
    }

    /**
     * Inject agent button into the page list WordPress falls back to when a
     * theme location has no menu assigned.
     *
     * wp_nav_menu() hands that case to wp_page_menu(), which never applies
     * wp_nav_menu_items, so fresh installs and stores that never built a menu
     * would otherwise get no launcher from the server.
     *
     * @param string $menu Rendered page menu.
     * @param array  $args Page menu arguments, including those of the wp_nav_menu() call.
     * @return string Modified page menu.
     */
    public function inject_page_menu_agent_button( string $menu, $args = [] ): string {
        if ( ! $this->should_inject_into_menus() ) {
            return $menu;
        }

        $key = $this->nav_menu_injection_key( is_array( $args ) ? $args : [] );
        if ( '' === $key || 0 === strpos( $key, 'menu:' ) || isset( $this->nav_injected_menus[ $key ] ) ) {
            return $menu;
        }

        $updated = self::insert_into_list( $menu, '<li class="page_item gecx-nav-menu-item">' . $this->get_agent_button_html() . '</li>' );
        if ( null === $updated || ! $this->claim_nav_menu( $key ) ) {
            return $menu;
        }
        return $updated;
    }

    /**
     * Inject agent button into Gutenberg Navigation Block (FSE / Block Themes).
     *
     * @param string $block_content Rendered block HTML.
     * @param array  $block         Block data array.
     * @return string Modified block HTML.
     */
    public function inject_block_navigation_agent_button( string $block_content, array $block = [] ): string {
        if ( ! $this->should_inject_into_menus() ) {
            return $block_content;
        }

        // A merchant who named a classic menu wants it there, not here.
        if ( '' !== self::sanitize_nav_menu_target( get_option( 'gecx_nav_menu_target', '' ) ) ) {
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

        // Only into the navigation's own top-level list. A navigation holding
        // no list, such as one with only a logo or search block, is left to
        // storefront.js rather than given a list item outside any list.
        $updated = self::insert_into_list(
            $block_content,
            '<li class="wp-block-navigation-item gecx-nav-menu-item">' . $this->get_agent_button_html() . '</li>',
            'wp-block-navigation__container'
        );
        if ( null === $updated ) {
            return $block_content;
        }

        $this->nav_injected = true;
        return $updated;
    }

    /**
     * Inject the chat widget custom element into the footer.
     */
    public function inject_chat_widget(): void {
        if ( is_admin() || $this->is_amp_request() ) {
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
        $floating_pos  = self::sanitize_floating_position( get_option( 'gecx_floating_position', self::DEFAULT_FLOATING_POSITION ) );
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
    /**
     * Whether this request renders an AMP page.
     *
     * AMP pages cannot run the widget's JavaScript, and the AMP plugin strips
     * the custom elements and reports them as validation errors, so the
     * plugin stays off them entirely.
     */
    protected function is_amp_request(): bool {
        $is_amp = false;
        if ( function_exists( 'did_action' ) && did_action( 'parse_query' ) ) {
            if ( function_exists( 'amp_is_request' ) ) {
                $is_amp = (bool) amp_is_request();
            } elseif ( function_exists( 'is_amp_endpoint' ) ) {
                $is_amp = (bool) is_amp_endpoint();
            }
        }
        /**
         * Filters whether the current request renders an AMP page, on which
         * the plugin outputs nothing.
         *
         * @param bool $is_amp Whether an AMP plugin reports an AMP request.
         */
        return (bool) apply_filters( 'gecx_is_amp_request', $is_amp );
    }

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

        // On block themes WooCommerce's template compatibility layer fires
        // third-party woocommerce_single_product_summary callbacks just
        // before the excerpt block, whatever their priority, which puts the
        // prompts above the add-to-cart button. There the add-to-cart block
        // filter places them instead, and storefront.js covers a block theme
        // still using the classic template.
        if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
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
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress render_block filter callback signature.
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
        return $this->is_widget_enabled() && (bool) $this->get_active_agent_name() && ! $this->is_amp_request();
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
     * Whether the current page embeds a single product through the
     * [product_page] shortcode or the WooCommerce Single Product block.
     */
    protected function page_embeds_single_product(): bool {
        if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
            return false;
        }
        $post = function_exists( 'get_post' ) ? get_post() : null;
        if ( ! $post || ! isset( $post->post_content ) ) {
            return false;
        }
        $content = (string) $post->post_content;
        return ( function_exists( 'has_shortcode' ) && has_shortcode( $content, 'product_page' ) )
            || ( function_exists( 'has_block' ) && has_block( 'woocommerce/single-product', $post ) );
    }

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

    /**
     * Get suggested prompts HTML markup for a product.
     *
     * @param int $product_id Product ID.
     * @return string HTML output.
     */
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
            $product  = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
            $override = ( $product && method_exists( $product, 'get_meta' ) )
                ? $product->get_meta( '_gecx_suggested_prompts_override', true )
                : get_post_meta( $product_id, '_gecx_suggested_prompts_override', true );
            if ( '' === (string) $override && function_exists( 'get_post_meta' ) ) {
                $override = get_post_meta( $product_id, '_gecx_suggested_prompts_override', true );
            }
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
    public function get_floating_container_style( string $position ): string {
        $position   = self::sanitize_floating_position( $position );
        $is_rtl     = function_exists( 'is_rtl' ) && is_rtl();
        $right_side = $is_rtl ? 'left' : 'right';
        $left_side  = $is_rtl ? 'right' : 'left';

        // Bottom offsets add the height of any bar the theme fixes to the
        // bottom of the viewport (--gecx-floating-offset, measured by
        // storefront.js), spacing the merchant adds themselves
        // (--gecx-floating-extra-offset), and the device's safe area.
        $bottom = static function ( int $base ): string {
            return 'bottom: calc(' . $base . 'px + var(--gecx-floating-offset, 0px) + var(--gecx-floating-extra-offset, 0px) + env(safe-area-inset-bottom, 0px));';
        };

        switch ( $position ) {
            case 'center_left':
                return 'position: fixed; top: 50%; ' . $left_side . ': 0; transform: translateY(-50%); z-index: 999999;';
            case 'center_right':
                return 'position: fixed; top: 50%; ' . $right_side . ': 0; transform: translateY(-50%); z-index: 999999;';
            case 'bottom_right':
                return 'position: fixed; ' . $bottom( 20 ) . ' ' . $right_side . ': 20px; z-index: 999999;';
            case 'bottom_left':
                return 'position: fixed; ' . $bottom( 20 ) . ' ' . $left_side . ': 20px; z-index: 999999;';
            case 'bottom_center':
            default:
                return 'position: fixed; ' . $bottom( 24 ) . ' left: 50%; transform: translateX(-50%); z-index: 999999;';
        }
    }

    /**
     * Render the agent button custom element HTML.
     *
     * @param array $overrides Attribute overrides.
     * @return string HTML output.
     */
    public function get_agent_button_html( array $overrides = [] ): string {
        $display_style       = self::sanitize_button_display_style( get_option( 'gecx_button_display_style', self::DEFAULT_DISPLAY_STYLE ) );
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
