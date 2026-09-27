<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Storefront Test Suite for Gemini Enterprise for Customer Experience (GECX)
 *
 * @package GECX
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-gecx-auth.php';
require_once dirname( __DIR__ ) . '/includes/class-gecx-storefront.php';

class StorefrontTest extends GECX_TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['gecx_test_is_product']        = false;
        $GLOBALS['gecx_test_the_id']            = 0;
        $GLOBALS['gecx_test_queried_object_id'] = 0;
    }

    public function test_storefront_never_renders_customer_jwt_or_wp_nonce_in_html(): void {
        $GLOBALS['gecx_test_current_user'] = new WP_User( 77, 'buyer@shop.test' );
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );

        ob_start();
        $storefront->inject_chat_widget();
        $html = ob_get_clean();

        $this->assertStringContainsString( '<gecx-woocommerce-chat-widget', $html );
        $this->assertStringNotContainsString( 'customer-jwt=', $html );
        $this->assertStringNotContainsString( 'wp-nonce=', $html );
    }

    public function test_storefront_omits_customer_jwt_and_wp_nonce_for_guests(): void {
        $GLOBALS['gecx_test_current_user'] = null;
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );

        ob_start();
        $storefront->inject_chat_widget();
        $html = ob_get_clean();

        $this->assertStringContainsString( '<gecx-woocommerce-chat-widget', $html );
        $this->assertStringNotContainsString( 'customer-jwt=', $html );
        $this->assertStringNotContainsString( 'wp-nonce=', $html );
    }

    /**
     * Sets up an enabled agent and renders the widget markup.
     *
     * @return string Rendered HTML.
     */
    private function render_widget_html(): string {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );

        ob_start();
        $storefront->inject_chat_widget();
        return (string) ob_get_clean();
    }

    public function test_storefront_passes_rest_root_to_widget(): void {
        $html = $this->render_widget_html();

        $this->assertStringContainsString( 'rest-url="https://example.com/wp-json/"', $html );
    }

    public function test_storefront_rest_url_follows_a_renamed_rest_prefix(): void {
        $GLOBALS['gecx_test_rest_url_prefix'] = 'api';

        $html = $this->render_widget_html();

        $this->assertStringContainsString( 'rest-url="https://example.com/api/"', $html );
    }

    public function test_storefront_rest_url_follows_plain_permalinks(): void {
        $GLOBALS['gecx_test_rest_plain_permalinks'] = true;

        $html = $this->render_widget_html();

        // On plain permalinks /wp-json/ does not resolve, so the widget must be
        // told to use the query-string form instead of assuming a path prefix.
        $this->assertStringContainsString( 'rest-url="https://example.com/?rest_route=/"', $html );
    }

    public function test_storefront_rest_url_follows_a_subdirectory_install(): void {
        $GLOBALS['gecx_test_home_url'] = 'https://example.com/shop';

        $html = $this->render_widget_html();

        $this->assertStringContainsString( 'rest-url="https://example.com/shop/wp-json/"', $html );
    }

    public function test_nav_menu_injection_primary_location(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_button_placement', 'nav_menu' );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $args = (object) [ 'theme_location' => 'primary' ];
        $items = '<li class="menu-item">Home</li>';
        $result = $storefront->inject_nav_menu_agent_button( $items, $args );

        $this->assertStringContainsString( 'gecx-agent-button', $result );
        $this->assertStringContainsString( 'gecx-nav-menu-item', $result );
    }

    public function test_nav_menu_injection_ignores_empty_or_footer_location(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_button_placement', 'nav_menu' );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $items = '<li class="menu-item">Privacy Policy</li>';

        // Empty location (e.g. unassigned or default footer menu)
        $empty_args = (object) [ 'theme_location' => '' ];
        $result_empty = $storefront->inject_nav_menu_agent_button( $items, $empty_args );
        $this->assertEquals( $items, $result_empty );

        // Null args
        $result_null = $storefront->inject_nav_menu_agent_button( $items, null );
        $this->assertEquals( $items, $result_null );

        // Top bar location (should be ignored to avoid injecting into utility headers)
        $top_args = (object) [ 'theme_location' => 'top' ];
        $result_top = $storefront->inject_nav_menu_agent_button( $items, $top_args );
        $this->assertEquals( $items, $result_top );

        // Explicit footer location
        $footer_args = (object) [ 'theme_location' => 'footer' ];
        $result_footer = $storefront->inject_nav_menu_agent_button( $items, $footer_args );
        $this->assertEquals( $items, $result_footer );

        // Subsequent main menu location should successfully inject
        $main_args = (object) [ 'theme_location' => 'main' ];
        $result_main = $storefront->inject_nav_menu_agent_button( $items, $main_args );
        $this->assertStringContainsString( 'gecx-agent-button', $result_main );
    }

    public function test_block_navigation_injection_header_block(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_button_placement', 'nav_menu' );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $block = [ 'blockName' => 'core/navigation', 'attrs' => [ 'ariaLabel' => 'Header' ] ];
        $content = '<nav class="wp-block-navigation"><ul class="wp-block-navigation__container"><li>Link</li></ul></nav>';
        $result = $storefront->inject_block_navigation_agent_button( $content, $block );

        $this->assertStringContainsString( 'gecx-agent-button', $result );
        $this->assertStringContainsString( 'gecx-nav-menu-item', $result );
    }

    public function test_block_navigation_injection_ignores_footer_block(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_button_placement', 'nav_menu' );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $block = [ 'blockName' => 'core/navigation', 'attrs' => [ 'ariaLabel' => 'Footer Navigation', 'className' => 'footer-nav' ] ];
        $content = '<nav class="wp-block-navigation"><ul class="wp-block-navigation__container"><li>Footer Link</li></ul></nav>';
        $result = $storefront->inject_block_navigation_agent_button( $content, $block );

        $this->assertEquals( $content, $result );
        $this->assertFalse( strpos( $result, 'gecx-agent-button' ) );
    }

    public function test_nav_menu_injection_only_once_per_request(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_button_placement', 'nav_menu' );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $args = (object) [ 'theme_location' => 'primary' ];
        $items_first = '<li class="menu-item">Header Menu</li>';
        $result_first = $storefront->inject_nav_menu_agent_button( $items_first, $args );
        $this->assertStringContainsString( 'gecx-agent-button', $result_first );

        // Second menu call on same request should be skipped
        $items_second = '<li class="menu-item">Secondary/Footer Menu</li>';
        $result_second = $storefront->inject_nav_menu_agent_button( $items_second, $args );
        $this->assertEquals( $items_second, $result_second );
        $this->assertFalse( strpos( $result_second, 'gecx-agent-button' ) );
    }

    public function test_block_navigation_injection_only_once_per_request(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_button_placement', 'nav_menu' );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $block = [ 'blockName' => 'core/navigation', 'attrs' => [ 'ariaLabel' => 'Header' ] ];
        $content_first = '<nav class="wp-block-navigation"><ul class="wp-block-navigation__container"><li>Header Link</li></ul></nav>';
        $result_first = $storefront->inject_block_navigation_agent_button( $content_first, $block );
        $this->assertStringContainsString( 'gecx-agent-button', $result_first );

        // Second block navigation call on same request should be skipped
        $content_second = '<nav class="wp-block-navigation"><ul class="wp-block-navigation__container"><li>Footer Link</li></ul></nav>';
        $result_second = $storefront->inject_block_navigation_agent_button( $content_second, $block );
        $this->assertEquals( $content_second, $result_second );
        $this->assertFalse( strpos( $result_second, 'gecx-agent-button' ) );
    }

    public function test_suggested_prompts_injection_on_product_page(): void {
        $GLOBALS['gecx_test_is_product'] = true;
        $GLOBALS['gecx_test_the_id']     = 101;
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_pdp_prompts_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );

        ob_start();
        $storefront->inject_suggested_prompts();
        $html = ob_get_clean();

        $this->assertStringContainsString( '<gecx-suggested-prompts', $html );
        $this->assertStringContainsString( 'chat-widget-selector="gecx-woocommerce-chat-widget"', $html );
    }

    public function test_suggested_prompts_block_injection(): void {
        $GLOBALS['gecx_test_is_product'] = true;
        $GLOBALS['gecx_test_the_id']     = 101;
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_pdp_prompts_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $block_html = '<div class="wp-block-woocommerce-add-to-cart-form"><button>Add to Cart</button></div>';

        $output = $storefront->inject_block_suggested_prompts( $block_html, [ 'blockName' => 'woocommerce/add-to-cart-form' ] );

        $this->assertStringContainsString( '<div class="wp-block-woocommerce-add-to-cart-form">', $output );
        $this->assertStringContainsString( '<gecx-suggested-prompts', $output );
    }

    public function test_suggested_prompts_duplicate_prevention(): void {
        $GLOBALS['gecx_test_is_product'] = true;
        $GLOBALS['gecx_test_the_id']     = 101;
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_pdp_prompts_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );

        ob_start();
        $storefront->inject_suggested_prompts();
        $first_html = ob_get_clean();
        $this->assertStringContainsString( '<gecx-suggested-prompts', $first_html );

        // Subsequent injection should be skipped
        ob_start();
        $storefront->inject_suggested_prompts();
        $second_html = ob_get_clean();
        $this->assertEquals( '', $second_html );

        // Block injection should also be skipped if already injected
        $block_html = '<div class="wp-block-woocommerce-add-to-cart-form">Cart</div>';
        $output     = $storefront->inject_block_suggested_prompts( $block_html );
        $this->assertEquals( $block_html, $output );
    }

    public function test_suggested_prompts_shortcode_rendering(): void {
        $GLOBALS['gecx_test_is_product'] = false;
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $html       = $storefront->render_suggested_prompts_shortcode( [ 'id' => 101 ] );

        $this->assertStringContainsString( '<gecx-suggested-prompts', $html );
    }

    public function test_suggested_prompts_shortcode_marks_injected_and_prevents_auto_injection(): void {
        $GLOBALS['gecx_test_is_product'] = true;
        $GLOBALS['gecx_test_the_id']     = 101;
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_pdp_prompts_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $shortcode_html = $storefront->render_suggested_prompts_shortcode();
        $this->assertStringContainsString( '<gecx-suggested-prompts', $shortcode_html );

        // Subsequent auto-injection hook should be skipped
        ob_start();
        $storefront->inject_suggested_prompts();
        $auto_html = ob_get_clean();
        $this->assertEquals( '', $auto_html );

        // Subsequent block filter should be skipped
        $block_html = '<div class="wp-block-woocommerce-add-to-cart-form">Cart</div>';
        $output     = $storefront->inject_block_suggested_prompts( $block_html );
        $this->assertEquals( $block_html, $output );
    }

    public function test_suggested_prompts_with_post_meta_override(): void {
        $GLOBALS['gecx_test_is_product'] = true;
        $GLOBALS['gecx_test_the_id']     = 101;
        $GLOBALS['gecx_test_post_meta'][101]['_gecx_suggested_prompts_override'] = "Is it waterproof?\nWhat sizes are available?";
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $html       = $storefront->get_suggested_prompts_html( 101 );

        $this->assertStringContainsString( 'static-prompts="[&quot;Is it waterproof?&quot;,&quot;What sizes are available?&quot;]"', $html );
    }

    public function test_suggested_prompts_resolves_queried_object_id_outside_loop(): void {
        $GLOBALS['gecx_test_is_product']          = true;
        $GLOBALS['gecx_test_the_id']              = 0;
        $GLOBALS['gecx_test_queried_object_id']   = 202;
        $GLOBALS['gecx_test_post_meta'][202]['_gecx_suggested_prompts_override'] = "Custom question outside loop?";
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $html       = $storefront->get_suggested_prompts_html();

        $this->assertStringContainsString( 'static-prompts="[&quot;Custom question outside loop?&quot;]"', $html );
    }

    public function test_suggested_prompts_block_injection_ignores_empty_content(): void {
        $GLOBALS['gecx_test_is_product'] = true;
        $GLOBALS['gecx_test_the_id']     = 101;
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_pdp_prompts_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );

        // Empty block content should return empty unmodified and not mark injected
        $empty_output = $storefront->inject_block_suggested_prompts( '   ' );
        $this->assertEquals( '   ', $empty_output );

        // Subsequent valid block should still be injected
        $block_html   = '<div class="wp-block-woocommerce-add-to-cart-form">Cart</div>';
        $valid_output = $storefront->inject_block_suggested_prompts( $block_html );
        $this->assertStringContainsString( '<gecx-suggested-prompts', $valid_output );
    }

    public function test_suggested_prompts_shortcode_works_when_auto_inject_disabled(): void {
        $GLOBALS['gecx_test_is_product'] = true;
        $GLOBALS['gecx_test_the_id']     = 101;
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_pdp_prompts_enabled', 0 ); // Auto-injection disabled

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $html       = $storefront->render_suggested_prompts_shortcode();

        $this->assertStringContainsString( '<gecx-suggested-prompts', $html );
    }

    public function test_storefront_assets_are_enqueued_for_a_page_render(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $storefront->enqueue_storefront_assets();

        $this->assertTrue( in_array( 'gecx-widget-style', $GLOBALS['gecx_test_enqueued_styles'], true ) );
        $this->assertTrue( in_array( 'gecx-widget-script', $GLOBALS['gecx_test_enqueued_scripts'], true ) );
    }

    /**
     * Localized config for a storefront page render.
     *
     * @return array<string, mixed>
     */
    private function localized_storefront_config(): array {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $storefront->enqueue_storefront_assets();

        return (array) ( $GLOBALS['gecx_test_localized_scripts']['gecx-storefront-js']['gecxStorefrontConfig'] ?? [] );
    }

    public function test_storefront_config_carries_the_store_api_cart_url(): void {
        $config = $this->localized_storefront_config();

        // The script used to hardcode /wp-json/wc/store/v1/cart, which 404s on
        // any store that is not at the domain root with the default prefix.
        $this->assertEquals(
            'https://example.com/wp-json/wc/store/v1/cart',
            $config['cartRestUrl']
        );
        $this->assertEquals(
            'https://example.com/wp-json/gecx/v1/auth-context',
            $config['authContextUrl']
        );
    }

    public function test_storefront_cart_url_follows_a_subdirectory_install(): void {
        $GLOBALS['gecx_test_home_url'] = 'https://example.com/shop';

        $config = $this->localized_storefront_config();

        $this->assertEquals(
            'https://example.com/shop/wp-json/wc/store/v1/cart',
            $config['cartRestUrl']
        );
        $this->assertEquals(
            'https://example.com/shop/wp-json/gecx/v1/auth-context',
            $config['authContextUrl']
        );
    }

    public function test_storefront_cart_url_follows_a_renamed_rest_prefix(): void {
        $GLOBALS['gecx_test_rest_url_prefix'] = 'api';

        $config = $this->localized_storefront_config();

        $this->assertEquals(
            'https://example.com/api/wc/store/v1/cart',
            $config['cartRestUrl']
        );
        $this->assertEquals(
            'https://example.com/api/gecx/v1/auth-context',
            $config['authContextUrl']
        );
    }

    public function test_storefront_config_reports_cart_and_checkout_pages(): void {
        // A product page, including one whose slug merely starts with "cart",
        // must not be reported: the script reloads the page when this is true,
        // which would interrupt the conversation.
        $this->assertFalse( $this->localized_storefront_config()['isCartOrCheckout'] );

        $GLOBALS['gecx_test_is_cart'] = true;
        $this->assertTrue( $this->localized_storefront_config()['isCartOrCheckout'] );

        $GLOBALS['gecx_test_is_cart']     = false;
        $GLOBALS['gecx_test_is_checkout'] = true;
        $this->assertTrue( $this->localized_storefront_config()['isCartOrCheckout'] );
    }


    public function test_storefront_assets_are_not_enqueued_off_a_page_render(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );

        // wp_enqueue_scripts fires on each of these, and none of them renders a
        // storefront page.
        $contexts = [
            'gecx_test_is_admin',
            'gecx_test_doing_ajax',
            'gecx_test_is_json_request',
            'gecx_test_is_feed',
        ];
        foreach ( $contexts as $context ) {
            $GLOBALS['gecx_test_enqueued_styles']  = [];
            $GLOBALS['gecx_test_enqueued_scripts'] = [];
            $GLOBALS[ $context ]                   = true;

            $storefront->enqueue_storefront_assets();

            $GLOBALS[ $context ] = false;

            $this->assertEquals( [], $GLOBALS['gecx_test_enqueued_styles'] );
            $this->assertEquals( [], $GLOBALS['gecx_test_enqueued_scripts'] );
        }
    }

    public function test_agent_button_short_label_falls_back_to_a_translated_shop(): void {
        delete_option( 'gecx_button_short_label' );
        $GLOBALS['gecx_test_translations']['Shop'] = 'Boutique';

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );

        $this->assertStringContainsString( 'short-label="Boutique"', $storefront->get_agent_button_html() );

        // Also when the option exists but is empty, which is what the settings
        // page writes when the field is cleared.
        update_option( 'gecx_button_short_label', '' );
        $this->assertStringContainsString( 'short-label="Boutique"', $storefront->get_agent_button_html() );
    }

    public function test_agent_button_short_label_prefers_the_configured_value(): void {
        update_option( 'gecx_button_short_label', 'Ask us' );
        $GLOBALS['gecx_test_translations']['Shop'] = 'Boutique';

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );

        $this->assertStringContainsString( 'short-label="Ask us"', $storefront->get_agent_button_html() );
    }

    public function test_enqueue_storefront_assets_includes_mobile_responsive_styles(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $storefront->enqueue_storefront_assets();

        $inline_styles = $GLOBALS['gecx_test_inline_styles']['gecx-widget-style'] ?? '';
        // Menu item versus mobile button is decided in storefront.js from the
        // theme's hamburger, not from breakpoints that only fit some themes.
        $this->assertStringNotContainsString( '@media (max-width: 767.98px) { .main-navigation .gecx-nav-menu-item', $inline_styles );
        $this->assertStringNotContainsString( '@media (min-width: 768px) { .main-navigation .gecx-mobile-header-button', $inline_styles );
        $this->assertStringNotContainsString( '@media (min-width: 600px) { .wp-block-navigation .gecx-mobile-header-button', $inline_styles );
        // Zero-specificity layout so vertical and off-canvas menus keep theirs.
        $this->assertStringContainsString( ' :where(.gecx-nav-menu-item) { display: flex;', $inline_styles );
        $this->assertStringNotContainsString( '.gecx-nav-menu-item { display: inline-flex !important', $inline_styles );
        $this->assertStringContainsString( '.gecx-mobile-header-button--floating', $inline_styles );
        $this->assertStringContainsString( 'chat-messenger.slide-over { position: fixed !important; }', $inline_styles );
        $this->assertStringNotContainsString( '.wp-block-navigation__container', $inline_styles );
        $this->assertStringNotContainsString( '.wp-block-woocommerce-customer-account', $inline_styles );
        $this->assertStringNotContainsString( '.wp-block-woocommerce-mini-cart', $inline_styles );
    }

    public function test_enqueue_storefront_assets_includes_relative_centering_styles(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $storefront->enqueue_storefront_assets();

        $inline_styles = $GLOBALS['gecx_test_inline_styles']['gecx-widget-style'] ?? '';
        $this->assertStringContainsString( '.gecx-floating-button-container { transition: transform 0.5s cubic-bezier(0.32, 0.72, 0, 1); }', $inline_styles );
        $this->assertStringContainsString( 'body.gecx-chat-no-transition .gecx-floating-button-container { transition: none !important; }', $inline_styles );
        $this->assertStringContainsString( 'body.gecx-chat-open .gecx-floating-button-container--center-right', $inline_styles );
        $this->assertStringContainsString( 'body.gecx-chat-open .gecx-floating-button-container--bottom-right', $inline_styles );
        $this->assertStringContainsString( 'body.gecx-chat-open .gecx-floating-button-container--bottom-center', $inline_styles );
        $this->assertStringNotContainsString( ':not(.gecx-floating-button-container--center-right)', $inline_styles );
        $this->assertStringContainsString( 'body.rtl .gecx-mobile-header-button.gecx-mobile-header-button--floating { right: auto; left: 20px; }', $inline_styles );
        $this->assertStringContainsString( 'var(--gecx-chat-panel-width, 360px)', $inline_styles );
        $this->assertStringContainsString( 'var(--gecx-chat-panel-width, clamp(0px, 412px, 50dvw))', $inline_styles );
        $this->assertStringContainsString( '@media (max-width: 599.98px)', $inline_styles );
    }

    public function test_inject_chat_widget_floating_placement_includes_position_modifier_classes(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_button_placement', 'floating' );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );

        // Default: bottom_center
        update_option( 'gecx_floating_position', 'bottom_center' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_bottom = ob_get_clean();

        $this->assertStringContainsString( 'class="gecx-floating-button-container gecx-floating-button-container--bottom-center"', $html_bottom );
        $this->assertStringContainsString( 'position: fixed; bottom: calc(24px + var(--gecx-floating-offset, 0px) + var(--gecx-floating-extra-offset, 0px) + env(safe-area-inset-bottom, 0px)); left: 50%; transform: translateX(-50%);', $html_bottom );

        // Bottom right (20px offset to align with mobile floating button)
        update_option( 'gecx_floating_position', 'bottom_right' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_bottom_right = ob_get_clean();

        $this->assertStringContainsString( 'class="gecx-floating-button-container gecx-floating-button-container--bottom-right"', $html_bottom_right );
        $this->assertStringContainsString( 'position: fixed; bottom: calc(20px + var(--gecx-floating-offset, 0px) + var(--gecx-floating-extra-offset, 0px) + env(safe-area-inset-bottom, 0px)); right: 20px; z-index: 999999;', $html_bottom_right );

        // Center right
        update_option( 'gecx_floating_position', 'center_right' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_right = ob_get_clean();

        $this->assertStringContainsString( 'class="gecx-floating-button-container gecx-floating-button-container--center-right"', $html_right );
        $this->assertStringContainsString( 'position: fixed; top: 50%; right: 0; transform: translateY(-50%);', $html_right );

        // Center left
        update_option( 'gecx_floating_position', 'center_left' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_center_left = ob_get_clean();

        $this->assertStringContainsString( 'class="gecx-floating-button-container gecx-floating-button-container--center-left"', $html_center_left );
        $this->assertStringContainsString( 'position: fixed; top: 50%; left: 0; transform: translateY(-50%);', $html_center_left );

        // Bottom left
        update_option( 'gecx_floating_position', 'bottom_left' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_bottom_left = ob_get_clean();

        $this->assertStringContainsString( 'class="gecx-floating-button-container gecx-floating-button-container--bottom-left"', $html_bottom_left );
        $this->assertStringContainsString( 'position: fixed; bottom: calc(20px + var(--gecx-floating-offset, 0px) + var(--gecx-floating-extra-offset, 0px) + env(safe-area-inset-bottom, 0px)); left: 20px; z-index: 999999;', $html_bottom_left );

        // Unknown stored option falls back to bottom_center
        update_option( 'gecx_floating_position', 'unknown_invalid_position' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_unknown = ob_get_clean();

        $this->assertStringContainsString( 'class="gecx-floating-button-container gecx-floating-button-container--bottom-center"', $html_unknown );
        $this->assertStringContainsString( 'position: fixed; bottom: calc(24px + var(--gecx-floating-offset, 0px) + var(--gecx-floating-extra-offset, 0px) + env(safe-area-inset-bottom, 0px)); left: 50%; transform: translateX(-50%);', $html_unknown );

        // RTL locales mirror horizontal placement
        $GLOBALS['gecx_test_is_rtl'] = true;

        update_option( 'gecx_floating_position', 'bottom_right' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_rtl_bottom_right = ob_get_clean();
        $this->assertStringContainsString( 'position: fixed; bottom: calc(20px + var(--gecx-floating-offset, 0px) + var(--gecx-floating-extra-offset, 0px) + env(safe-area-inset-bottom, 0px)); left: 20px; z-index: 999999;', $html_rtl_bottom_right );

        update_option( 'gecx_floating_position', 'bottom_left' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_rtl_bottom_left = ob_get_clean();
        $this->assertStringContainsString( 'position: fixed; bottom: calc(20px + var(--gecx-floating-offset, 0px) + var(--gecx-floating-extra-offset, 0px) + env(safe-area-inset-bottom, 0px)); right: 20px; z-index: 999999;', $html_rtl_bottom_left );

        update_option( 'gecx_floating_position', 'center_right' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_rtl_center_right = ob_get_clean();
        $this->assertStringContainsString( 'position: fixed; top: 50%; left: 0; transform: translateY(-50%); z-index: 999999;', $html_rtl_center_right );

        update_option( 'gecx_floating_position', 'center_left' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_rtl_center_left = ob_get_clean();
        $this->assertStringContainsString( 'position: fixed; top: 50%; right: 0; transform: translateY(-50%); z-index: 999999;', $html_rtl_center_left );

        $GLOBALS['gecx_test_is_rtl'] = false;
    }
    public function test_widget_stylesheet_is_served_from_the_plugin_directory(): void {
        $storefront = new StorefrontUrlProbe( dirname( __DIR__ ) . '/gecx-agent.php' );

        $urls = $storefront->widget_urls();

        $this->assertStringContainsString( 'assets/css/theme.css', $urls['style'] );
        $this->assertStringNotContainsString( 'gstatic.com', $urls['style'] );
        $this->assertFileExists( dirname( __DIR__ ) . '/assets/css/theme.css' );
    }

    public function test_widget_stylesheet_url_remains_filterable(): void {
        add_filter(
            'gecx_chat_widget_style_url',
            static function (): string {
                return 'https://cdn.example.test/custom-theme.css';
            }
        );

        $storefront = new StorefrontUrlProbe( dirname( __DIR__ ) . '/gecx-agent.php' );

        $this->assertSame(
            'https://cdn.example.test/custom-theme.css',
            $storefront->widget_urls()['style']
        );
    }

    public function test_widget_script_can_be_deferred_via_option_or_filter_until_consent_or_interaction(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_defer_widget_until_interaction', true );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $storefront->enqueue_storefront_assets();

        $this->assertFalse( in_array( 'gecx-widget-script', $GLOBALS['gecx_test_enqueued_scripts'], true ) );
        $this->assertTrue( in_array( 'gecx-storefront-js', $GLOBALS['gecx_test_enqueued_scripts'], true ) );

        $config = $GLOBALS['gecx_test_localized_scripts']['gecx-storefront-js']['gecxStorefrontConfig'] ?? [];
        $this->assertFalse( $config['shouldLoadWidget'] );
        $this->assertStringContainsString( 'woocommerce-chat-widget.js', $config['widgetScriptUrl'] );
    }

    public function test_inline_style_and_theme_css_respect_prefers_reduced_motion(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $storefront->enqueue_storefront_assets();

        $inline_css = $GLOBALS['gecx_test_inline_styles']['gecx-widget-style'] ?? '';
        $this->assertStringContainsString( 'prefers-reduced-motion: reduce', $inline_css );

        $theme_css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/theme.css' );
        $this->assertStringContainsString( 'prefers-reduced-motion: reduce', $theme_css );
        $this->assertStringNotContainsString( 'sourceMappingURL', $theme_css );
    }

    public function test_storefront_js_captures_cart_token_on_plain_permalink_stores(): void {
        $node = exec( 'which node' );
        if ( empty( $node ) ) {
            $this->markTestSkipped( 'Node.js is not available to test storefront.js runtime.' );
        }

        $script_path = dirname( __DIR__ ) . '/assets/js/storefront.js';
        $js_code     = file_get_contents( $script_path );
        $this->assertNotEmpty( $js_code );

        $test_runner = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const code = fs.readFileSync(process.argv[2], 'utf8');
let capturedFetchInit = null;
const validJwt = 'eyJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjoxMjN9.signature';
const sandbox = {
  window: {
    location: { origin: 'https://example.com' },
    addEventListener: () => {},
    gecxStorefrontConfig: {
      sessionUrl: 'https://example.com/?rest_route=/gecx/v1/session',
      cartRestUrl: 'https://example.com/?rest_route=/wc/store/v1/cart'
    },
    fetch: function(input, init) {
      capturedFetchInit = init;
      return Promise.resolve({
        headers: {
          get: (h) => (h.toLowerCase() === 'cart-token' ? validJwt : null)
        }
      });
    }
  },
  document: {
    readyState: 'loading',
    addEventListener: () => {},
    querySelector: () => null,
    querySelectorAll: () => []
  },
  URL: URL,
  RegExp: RegExp,
  console: console
};
vm.createContext(sandbox);
vm.runInContext(code, sandbox);

sandbox.window.fetch('https://example.com/?rest_route=/wc/store/v1/cart', {}).then(() => {
  return sandbox.window.fetch('https://example.com/?rest_route=/gecx/v1/session', {});
}).then(() => {
  const token = capturedFetchInit && capturedFetchInit.headers && capturedFetchInit.headers['Cart-Token'];
  if (token !== validJwt) {
    console.error('Mismatch: expected ' + validJwt + ' got ' + token);
    process.exit(1);
  }
  process.exit(0);
}).catch(err => {
  console.error(err);
  process.exit(1);
});
JS;

        $temp_runner = (string) tempnam( sys_get_temp_dir(), 'gecx_js_' );
        file_put_contents( $temp_runner, $test_runner );

        $cmd    = sprintf( '%s %s %s', escapeshellcmd( $node ), escapeshellarg( $temp_runner ), escapeshellarg( $script_path ) );
        $output = [];
        $status = 0;
        exec( $cmd, $output, $status );
        if ( file_exists( $temp_runner ) ) {
            unlink( $temp_runner );
        }

        $this->assertSame( 0, $status, 'Storefront.js failed to capture and attach Cart-Token on plain permalink store: ' . implode( "\n", $output ) );
    }

    public function test_storefront_js_freezes_config_and_rejects_tampered_widget_html(): void {
        $node = exec( 'which node' );
        if ( empty( $node ) ) {
            $this->markTestSkipped( 'Node.js is not available to test storefront.js runtime.' );
        }

        $script_path = dirname( __DIR__ ) . '/assets/js/storefront.js';
        $test_runner = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const code = fs.readFileSync(process.argv[2], 'utf8');

let domLoadedCallback = null;
let appendedNavItem = null;
const navUl = {
  children: [],
  parentElement: null,
  closest: () => null,
  appendChild: (child) => { appendedNavItem = child; }
};

function makeMockTemplateOrDiv(tag) {
  let rawHtml = '';
  const parseNode = (htmlStr) => {
    const hasForbidden = /<(script|iframe|object|embed|svg|img|link|style)\b/i.test(htmlStr);
    const match = htmlStr.match(/^<([a-z0-9-]+)([^>]*)><\/\1>$/i);
    return {
      querySelector: (sel) => {
        if (sel.indexOf('script') !== -1) {
          return hasForbidden ? {} : null;
        }
        if (!match || match[1].toLowerCase() !== sel.toLowerCase()) {
          return null;
        }
        const attrs = [];
        const attrRegex = /([a-zA-Z0-9_-]+)="([^"]*)"/g;
        let m;
        while ((m = attrRegex.exec(match[2])) !== null) {
          attrs.push({ name: m[1], value: m[2] });
        }
        return {
          children: [],
          attributes: attrs
        };
      }
    };
  };
  if (tag === 'template') {
    const tpl = {};
    Object.defineProperty(tpl, 'innerHTML', {
      set: (v) => { rawHtml = v; tpl.content = parseNode(v); },
      get: () => rawHtml
    });
    tpl.content = parseNode('');
    return tpl;
  }
  const el = {
    tagName: tag.toUpperCase(),
    className: '',
    style: {},
    attrs: {},
    children: [],
    setAttribute: function(k, v) { this.attrs[k] = v; },
    appendChild: function(c) { this.children.push(c); }
  };
  return el;
}

const initialConfig = {
  isWidgetEnabled: true,
  placement: 'nav_menu',
  buttonHtml: '<gecx-agent-button display-style="responsive" onclick="evil()"></gecx-agent-button>',
  sessionUrl: 'https://example.com/wp-json/gecx/v1/session'
};

const sandbox = {
  window: {
    location: { origin: 'https://example.com' },
    innerWidth: 1024,
    addEventListener: () => {},
    gecxStorefrontConfig: initialConfig,
    fetch: () => Promise.resolve({ headers: { get: () => null } })
  },
  document: {
    readyState: 'loading',
    addEventListener: (evt, cb) => {
      if (evt === 'DOMContentLoaded') {
        domLoadedCallback = cb;
      }
    },
    createElement: (tag) => makeMockTemplateOrDiv(tag),
    querySelector: () => null,
    querySelectorAll: (sel) => (sel.indexOf('header nav ul') !== -1 ? [navUl] : [])
  },
  Object: Object,
  URL: URL,
  RegExp: RegExp,
  console: console
};
vm.createContext(sandbox);
vm.runInContext(code, sandbox);

if (!Object.isFrozen(sandbox.window.gecxStorefrontConfig)) {
  console.error('Expected window.gecxStorefrontConfig to be frozen');
  process.exit(1);
}

// Attempt post-load replacement of window.gecxStorefrontConfig with malicious payload
sandbox.window.gecxStorefrontConfig = {
  isWidgetEnabled: true,
  placement: 'nav_menu',
  buttonHtml: '<script>alert(1)</script>'
};

if (typeof domLoadedCallback === 'function') {
  domLoadedCallback();
}

if (!appendedNavItem || appendedNavItem.children.length !== 1) {
  console.error('Expected safe button element to be appended from frozen initial config');
  process.exit(1);
}
const btn = appendedNavItem.children[0];
if (btn.tagName !== 'GECX-AGENT-BUTTON' || btn.attrs['display-style'] !== 'responsive' || btn.attrs['onclick']) {
  console.error('Unexpected button element or unstripped onclick attribute:', JSON.stringify(btn));
  process.exit(1);
}
process.exit(0);
JS;

        $temp_runner = (string) tempnam( sys_get_temp_dir(), 'gecx_js_freeze_' );
        file_put_contents( $temp_runner, $test_runner );

        $cmd    = sprintf( '%s %s %s', escapeshellcmd( $node ), escapeshellarg( $temp_runner ), escapeshellarg( $script_path ) );
        $output = [];
        $status = 0;
        exec( $cmd, $output, $status );
        if ( file_exists( $temp_runner ) ) {
            unlink( $temp_runner );
        }

        $this->assertSame( 0, $status, 'Storefront.js config freeze / safe element test failed: ' . implode( "\n", $output ) );
    }

    public function test_cart_fragments_are_enqueued_on_classic_themes(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );

        ( new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' ) )->enqueue_cart_fragments();

        $this->assertContains( 'wc-cart-fragments', $GLOBALS['gecx_test_enqueued_scripts'] );
    }

    public function test_cart_fragments_are_not_enqueued_on_block_themes(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        $GLOBALS['gecx_test_is_block_theme'] = true;

        ( new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' ) )->enqueue_cart_fragments();

        $this->assertNotContains( 'wc-cart-fragments', $GLOBALS['gecx_test_enqueued_scripts'] );
    }

    public function test_cart_fragments_enqueue_is_filterable(): void {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        add_filter( 'gecx_enqueue_cart_fragments', static function (): bool { return false; } );

        ( new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' ) )->enqueue_cart_fragments();

        $this->assertNotContains( 'wc-cart-fragments', $GLOBALS['gecx_test_enqueued_scripts'] );
    }

    public function test_cart_fragments_are_not_enqueued_without_a_linked_agent_or_when_unregistered(): void {
        update_option( 'gecx_agent_enabled', 1 );
        $storefront = new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
        $storefront->enqueue_cart_fragments();
        $this->assertNotContains( 'wc-cart-fragments', $GLOBALS['gecx_test_enqueued_scripts'] );

        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        $GLOBALS['gecx_test_registered_scripts'] = [];
        $storefront->enqueue_cart_fragments();
        $this->assertNotContains( 'wc-cart-fragments', $GLOBALS['gecx_test_enqueued_scripts'] );
    }

    public function test_storefront_config_carries_typed_cart_refresh_settings(): void {
        $config = $this->localized_storefront_config();

        $this->assertSame(
            [
                'nativeEvents'   => true,
                'legacyEvents'   => false,
                'badgeSelectors' => GECX_Storefront::DEFAULT_CART_BADGE_SELECTORS,
            ],
            $config['cartRefresh']
        );
    }

    public function test_cart_refresh_settings_are_filterable_and_badge_selectors_are_cleaned(): void {
        add_filter( 'gecx_cart_refresh_native_events', static function (): bool { return false; } );
        add_filter( 'gecx_cart_refresh_legacy_events', static function (): bool { return true; } );
        add_filter(
            'gecx_cart_badge_selectors',
            static function (): array {
                return [ ' .theme-cart-count ', '', 42, '.wc-block-mini-cart__badge' ];
            }
        );

        $this->assertSame(
            [
                'nativeEvents'   => false,
                'legacyEvents'   => true,
                'badgeSelectors' => [ '.theme-cart-count', '.wc-block-mini-cart__badge' ],
            ],
            GECX_Storefront::get_cart_refresh_config()
        );
    }

    /**
     * Runs storefront.js in a sandbox, fires chat-messenger-update-cart and
     * returns what the page observed, as decoded by the runner.
     *
     * @param array $scenario Options read by the runner; see the JS below.
     * @return array<string, mixed>
     */
    private function run_cart_update_scenario( array $scenario ): array {
        $node = exec( 'which node' );
        if ( empty( $node ) ) {
            $this->markTestSkipped( 'Node.js is not available to test storefront.js runtime.' );
        }

        $test_runner = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const code = fs.readFileSync(process.argv[2], 'utf8');
const scenario = JSON.parse(process.argv[3]);

const log = { fetches: [], jquery: [], events: [], received: [], reloads: 0, badges: {}, sequence: [] };
const listeners = { window: {}, document: {} };
const on = (target) => (name, cb) => { (listeners[target][name] = listeners[target][name] || []).push(cb); };

let cartResponses = scenario.cartResponses.slice();
function fakeFetch(url, init) {
  log.fetches.push({ url: url, method: (init && init.method) || 'GET', headers: (init && init.headers) || {} });
  if (url.indexOf('auth-context') !== -1) {
    const nonce = 'nonce-' + log.fetches.filter(f => f.url.indexOf('auth-context') !== -1).length;
    return Promise.resolve({ ok: true, status: 200, headers: { get: () => null }, json: () => Promise.resolve({ nonce: nonce }) });
  }
  log.sequence.push('cart read');
  const next = cartResponses.shift() || { status: 200, body: {} };
  return Promise.resolve({
    ok: next.status >= 200 && next.status < 300,
    status: next.status,
    headers: { get: () => null },
    json: () => Promise.resolve(next.body)
  });
}

const badgeEls = {};
(scenario.badges || []).forEach((sel) => {
  badgeEls[sel] = { textContent: '', style: {}, attrs: {}, setAttribute(k, v) { this.attrs[k] = v; }, removeAttribute(k) { delete this.attrs[k]; } };
});

class FakeCustomEvent { constructor(type, init) { this.type = type; this.detail = init && init.detail; } }

const window = {
  location: { origin: 'https://example.com', pathname: scenario.pathname || '/', reload: () => { log.reloads++; } },
  innerWidth: 1024,
  addEventListener: on('window'),
  gecxStorefrontConfig: Object.assign({
    isWidgetEnabled: true,
    placement: 'floating',
    cartRestUrl: 'https://example.com/wp-json/wc/store/v1/cart',
    authContextUrl: 'https://example.com/wp-json/gecx/v1/auth-context',
    sessionUrl: 'https://example.com/wp-json/gecx/v1/session'
  }, scenario.config || {}),
  fetch: fakeFetch
};

if (scenario.jquery) {
  window.jQuery = () => ({ trigger: (name) => { log.jquery.push(name); log.sequence.push(name); } });
}
if (scenario.store) {
  const storeState = { itemsCount: scenario.store.itemsCount };
  window.wp = { data: {
    select: (name) => name === 'wc/store/cart' ? { getCartData: () => ({ itemsCount: storeState.itemsCount }) } : undefined,
    dispatch: (name) => {
      if (name === 'wc/store/cart') {
        return { setIsCartDataStale: () => {}, receiveCart: (cart) => { log.received.push(cart); storeState.itemsCount = cart.items_count; } };
      }
      if (name === 'core/data') {
        return { invalidateResolution: () => {} };
      }
      return undefined;
    }
  } };
}

const document = {
  readyState: 'complete',
  addEventListener: on('document'),
  body: { classList: { contains: () => false }, dispatchEvent: (evt) => { log.events.push({ type: evt.type, detail: evt.detail }); } },
  documentElement: { style: { removeProperty: () => {}, setProperty: () => {} } },
  createElement: () => ({ style: {}, setAttribute() {}, appendChild() {} }),
  querySelector: (sel) => (scenario.presentSelectors || []).indexOf(sel) !== -1 ? {} : null,
  querySelectorAll: (sel) => badgeEls[sel] ? [badgeEls[sel]] : []
};

const sandbox = {
  window: window, document: document, fetch: fakeFetch, CustomEvent: FakeCustomEvent,
  MutationObserver: class { observe() {} }, URL: URL, console: { warn: () => {}, error: console.error },
  setTimeout: setTimeout, clearTimeout: clearTimeout
};
sandbox.window.getComputedStyle = () => ({ display: 'block', visibility: 'visible', paddingRight: '0' });
vm.createContext(sandbox);
vm.runInContext(code, sandbox);

const evt = { type: 'chat-messenger-update-cart', detail: scenario.detail || {} };
// A bubbling event reaches the document listener and then the window one.
(listeners.document['chat-messenger-update-cart'] || []).forEach((cb) => cb(evt));
(listeners.window['chat-messenger-update-cart'] || []).forEach((cb) => cb(evt));

setTimeout(() => {
  Object.keys(badgeEls).forEach((sel) => { log.badges[sel] = badgeEls[sel].textContent; });
  process.stdout.write(JSON.stringify(log));
}, 50);
JS;

        $temp_runner = (string) tempnam( sys_get_temp_dir(), 'gecx_js_cart_' );
        file_put_contents( $temp_runner, $test_runner );

        $cmd = sprintf(
            '%s %s %s %s',
            escapeshellcmd( $node ),
            escapeshellarg( $temp_runner ),
            escapeshellarg( dirname( __DIR__ ) . '/assets/js/storefront.js' ),
            escapeshellarg( (string) wp_json_encode( $scenario ) )
        );
        $output = [];
        $status = 0;
        exec( $cmd . ' 2>&1', $output, $status );
        unlink( $temp_runner );

        $this->assertSame( 0, $status, implode( "\n", $output ) );
        $log = json_decode( implode( "\n", $output ), true );
        $this->assertIsArray( $log, implode( "\n", $output ) );
        return $log;
    }

    /**
     * @param array<string, mixed> $log
     * @return string[]
     */
    private static function cart_fetch_urls( array $log ): array {
        return array_values(
            array_filter(
                array_column( $log['fetches'], 'url' ),
                static function ( string $url ): bool {
                    return false !== strpos( $url, 'wc/store/v1/cart' );
                }
            )
        );
    }

    /**
     * @param array<string, mixed> $log
     * @return string[]
     */
    private static function event_types( array $log ): array {
        return array_column( $log['events'], 'type' );
    }

    public function test_cart_update_on_a_classic_theme_refreshes_fragments_and_badges_once(): void {
        $log = $this->run_cart_update_scenario(
            [
                'jquery'        => true,
                'badges'        => [ '.wc-block-mini-cart__badge' ],
                'cartResponses' => [ [ 'status' => 200, 'body' => [ 'items_count' => 2 ] ] ],
            ]
        );

        // Handled once although the event reached both document and window.
        $this->assertCount( 1, self::cart_fetch_urls( $log ) );
        $this->assertSame( [ 'wc_fragment_refresh' ], $log['jquery'] );
        // After the cart read, which is what bridges the agent's cart
        // session to a browser that had no WooCommerce session cookie.
        $this->assertSame( [ 'cart read', 'wc_fragment_refresh' ], $log['sequence'] );
        $this->assertSame( '2', $log['badges']['.wc-block-mini-cart__badge'] );
        $this->assertSame( [ 'wc-blocks_added_to_cart', 'gecx:cart-updated' ], self::event_types( $log ) );
        $this->assertSame( [ 'preserveCartData' => false ], $log['events'][0]['detail'] );
        $this->assertSame( 'added', $log['events'][1]['detail']['change'] );
        $this->assertSame( 2, $log['events'][1]['detail']['itemsCount'] );
    }

    public function test_cart_update_with_the_blocks_store_receives_the_cart_and_reports_a_removal(): void {
        $log = $this->run_cart_update_scenario(
            [
                'store'         => [ 'itemsCount' => 3 ],
                'badges'        => [ '.wc-block-mini-cart__badge' ],
                'cartResponses' => [ [ 'status' => 200, 'body' => [ 'items_count' => 1 ] ] ],
            ]
        );

        $this->assertSame( [ [ 'items_count' => 1 ] ], $log['received'] );
        // The data store redraws the mini-cart, so the badge is left alone.
        $this->assertSame( '', $log['badges']['.wc-block-mini-cart__badge'] );
        $this->assertSame( 'wc-blocks_removed_from_cart', $log['events'][0]['type'] );
        $this->assertSame( [ 'preserveCartData' => true ], $log['events'][0]['detail'] );
        $this->assertSame( 3, $log['events'][1]['detail']['previousItemsCount'] );
        $this->assertSame( 'removed', $log['events'][1]['detail']['change'] );
    }

    public function test_cart_update_legacy_jquery_events_are_opt_in(): void {
        $default = $this->run_cart_update_scenario(
            [
                'jquery'        => true,
                'cartResponses' => [ [ 'status' => 200, 'body' => [ 'items_count' => 1 ] ] ],
            ]
        );
        $this->assertNotContains( 'added_to_cart', $default['jquery'] );

        $opted_in = $this->run_cart_update_scenario(
            [
                'jquery'        => true,
                'config'        => [ 'cartRefresh' => [ 'legacyEvents' => true ] ],
                'cartResponses' => [ [ 'status' => 200, 'body' => [ 'items_count' => 1 ] ] ],
            ]
        );
        $this->assertContains( 'added_to_cart', $opted_in['jquery'] );
        // The block mini-cart turns the jQuery event into the native one
        // itself, so sending both would refresh it twice.
        $this->assertSame( [ 'gecx:cart-updated' ], self::event_types( $opted_in ) );
    }

    public function test_cart_update_retries_once_with_a_fresh_nonce_after_a_403(): void {
        $log = $this->run_cart_update_scenario(
            [
                'cartResponses' => [
                    [ 'status' => 403, 'body' => [] ],
                    [ 'status' => 200, 'body' => [ 'items_count' => 4 ] ],
                ],
            ]
        );

        $cart_fetches = array_values(
            array_filter(
                $log['fetches'],
                static function ( array $fetch ): bool {
                    return false !== strpos( $fetch['url'], 'wc/store/v1/cart' );
                }
            )
        );
        $this->assertCount( 2, $cart_fetches );
        $this->assertSame( 'nonce-1', $cart_fetches[0]['headers']['X-WP-Nonce'] );
        $this->assertSame( 'nonce-2', $cart_fetches[1]['headers']['X-WP-Nonce'] );
        $this->assertSame( 4, $log['events'][1]['detail']['itemsCount'] );
    }

    public function test_cart_update_sends_the_widget_cart_token_with_the_cart_read(): void {
        $token = 'eyJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjoidF8xIn0.sig';
        $log   = $this->run_cart_update_scenario(
            [
                'detail'        => [ 'cartId' => $token ],
                'cartResponses' => [ [ 'status' => 200, 'body' => [ 'items_count' => 1 ] ] ],
            ]
        );

        $this->assertSame( $token, $log['fetches'][1]['headers']['Cart-Token'] );
    }

    public function test_cart_update_never_reloads_checkout(): void {
        $checkout = $this->run_cart_update_scenario(
            [
                'config'        => [ 'isCart' => false, 'isCheckout' => true, 'isCartOrCheckout' => true ],
                'cartResponses' => [ [ 'status' => 200, 'body' => [ 'items_count' => 1 ] ] ],
            ]
        );
        $this->assertSame( 0, $checkout['reloads'] );

        $cart = $this->run_cart_update_scenario(
            [
                'config'        => [ 'isCart' => true, 'isCheckout' => false, 'isCartOrCheckout' => true ],
                'cartResponses' => [ [ 'status' => 200, 'body' => [ 'items_count' => 1 ] ] ],
            ]
        );
        $this->assertSame( 1, $cart['reloads'] );
    }

    public function test_cart_update_only_refreshes_classic_forms_that_are_on_the_page(): void {
        $block_checkout = $this->run_cart_update_scenario(
            [
                'jquery'        => true,
                'config'        => [ 'isCart' => false, 'isCheckout' => true, 'isCartOrCheckout' => true ],
                'cartResponses' => [ [ 'status' => 200, 'body' => [ 'items_count' => 1 ] ] ],
            ]
        );
        $this->assertNotContains( 'update_checkout', $block_checkout['jquery'] );

        $classic_checkout = $this->run_cart_update_scenario(
            [
                'jquery'           => true,
                'presentSelectors' => [ 'form.checkout' ],
                'config'           => [ 'isCart' => false, 'isCheckout' => true, 'isCartOrCheckout' => true ],
                'cartResponses'    => [ [ 'status' => 200, 'body' => [ 'items_count' => 1 ] ] ],
            ]
        );
        $this->assertContains( 'update_checkout', $classic_checkout['jquery'] );
    }
    private function activate_widget( string $placement = 'nav_menu' ): GECX_Storefront {
        update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/agent-1' );
        update_option( 'gecx_agent_enabled', 1 );
        update_option( 'gecx_button_placement', $placement );
        return new GECX_Storefront( dirname( __DIR__ ) . '/gecx-agent.php' );
    }

    public function test_nav_menu_injection_reaches_desktop_and_mobile_menu_locations(): void {
        $storefront = $this->activate_widget();
        $items      = '<li class="menu-item">Home</li>';

        // Storefront renders its desktop menu at 'primary' and its drawer at
        // 'handheld'. Each needs its own launcher.
        $this->assertStringContainsString( 'gecx-agent-button', $storefront->inject_nav_menu_agent_button( $items, (object) [ 'theme_location' => 'primary' ] ) );
        $this->assertStringContainsString( 'gecx-agent-button', $storefront->inject_nav_menu_agent_button( $items, (object) [ 'theme_location' => 'handheld' ] ) );
        foreach ( [ 'main_menu', 'main_navigation', 'menu_1', 'mobile_menu' ] as $location ) {
            $this->assertStringContainsString( 'gecx-agent-button', $storefront->inject_nav_menu_agent_button( $items, (object) [ 'theme_location' => $location ] ), $location );
        }
    }

    public function test_nav_menu_item_carries_no_inline_layout(): void {
        $result = $this->activate_widget()->inject_nav_menu_agent_button( '', (object) [ 'theme_location' => 'primary' ] );

        $this->assertStringContainsString( '<li class="menu-item gecx-nav-menu-item">', $result );
        $this->assertStringNotContainsString( 'li class="menu-item gecx-nav-menu-item" style=', $result );
    }

    public function test_nav_menu_target_location_limits_injection_to_that_location(): void {
        $storefront = $this->activate_widget();
        update_option( 'gecx_nav_menu_target', 'location:top-bar' );

        $this->assertSame( '', $storefront->inject_nav_menu_agent_button( '', (object) [ 'theme_location' => 'primary' ] ) );
        $this->assertStringContainsString( 'gecx-agent-button', $storefront->inject_nav_menu_agent_button( '', (object) [ 'theme_location' => 'top-bar' ] ) );
    }

    public function test_nav_menu_target_menu_reaches_page_builder_menus_without_a_location(): void {
        $storefront                        = $this->activate_widget();
        $GLOBALS['gecx_test_nav_menus']    = [ (object) [ 'term_id' => 7, 'slug' => 'shop-header', 'name' => 'Shop header' ] ];
        update_option( 'gecx_nav_menu_target', 'menu:7' );

        // Elementor's Nav Menu widget passes the menu slug and no location.
        $this->assertStringContainsString( 'gecx-agent-button', $storefront->inject_nav_menu_agent_button( '', (object) [ 'theme_location' => '', 'menu' => 'shop-header' ] ) );
        $this->assertSame( '', $storefront->inject_nav_menu_agent_button( '', (object) [ 'theme_location' => '', 'menu' => 'other' ] ) );
    }

    public function test_nav_menu_target_menu_matches_the_menu_assigned_to_a_location(): void {
        $storefront                                  = $this->activate_widget();
        $GLOBALS['gecx_test_nav_menu_locations']     = [ 'primary' => 9 ];
        update_option( 'gecx_nav_menu_target', 'menu:9' );

        $this->assertStringContainsString( 'gecx-agent-button', $storefront->inject_nav_menu_agent_button( '', (object) [ 'theme_location' => 'primary' ] ) );
    }

    public function test_nav_menu_target_is_sanitized(): void {
        $this->assertSame( 'location:primary', GECX_Storefront::sanitize_nav_menu_target( 'location:primary' ) );
        $this->assertSame( 'menu:12', GECX_Storefront::sanitize_nav_menu_target( 'menu:12' ) );
        $this->assertSame( '', GECX_Storefront::sanitize_nav_menu_target( 'menu:0' ) );
        $this->assertSame( '', GECX_Storefront::sanitize_nav_menu_target( 'location:"><script>' ) );
        $this->assertSame( '', GECX_Storefront::sanitize_nav_menu_target( [ 'menu:1' ] ) );
    }

    public function test_page_menu_fallback_gets_the_launcher_inside_its_list(): void {
        $storefront = $this->activate_widget();
        $menu       = '<div class="menu"><ul><li class="page_item page_item_has_children"><a>Shop</a><ul class="children"><li>Sub</li></ul></li><li>About</li></ul></div>';

        $result = $storefront->inject_page_menu_agent_button( $menu, [ 'theme_location' => 'primary' ] );

        $this->assertStringContainsString( '<li>About</li><li class="page_item gecx-nav-menu-item">', $result );
        $this->assertStringEndsWith( '</li></ul></div>', $result );
        $this->assertSame( $menu, $storefront->inject_page_menu_agent_button( $menu, [ 'theme_location' => 'footer' ] ) );
    }

    public function test_insert_into_list_respects_nesting_and_class(): void {
        $html = '<nav><ul class="wp-block-navigation__container is-x"><li>A<ul class="sub"><li>B</li></ul></li></ul><ul class="other"><li>C</li></ul></nav>';

        $this->assertSame(
            '<nav><ul class="wp-block-navigation__container is-x"><li>A<ul class="sub"><li>B</li></ul></li>X</ul><ul class="other"><li>C</li></ul></nav>',
            GECX_Storefront::insert_into_list( $html, 'X', 'wp-block-navigation__container' )
        );
        $this->assertNull( GECX_Storefront::insert_into_list( '<nav><div>logo</div></nav>', 'X', 'wp-block-navigation__container' ) );
        $this->assertNull( GECX_Storefront::insert_into_list( '<ul class="wp-block-navigation__container-extra"><li></li></ul>', 'X', 'wp-block-navigation__container' ) );
    }

    public function test_block_navigation_injection_lands_in_its_own_list_not_the_last_one(): void {
        $storefront = $this->activate_widget();
        $content    = '<nav class="wp-block-navigation"><ul class="wp-block-navigation__container"><li>Link</li></ul><div class="wp-block-social-links"><ul><li>Social</li></ul></div></nav>';

        $result = $storefront->inject_block_navigation_agent_button( $content, [ 'attrs' => [] ] );

        $this->assertStringContainsString( '<li>Link</li><li class="wp-block-navigation-item gecx-nav-menu-item">', $result );
        $this->assertStringContainsString( '<ul><li>Social</li></ul>', $result );
    }

    public function test_block_navigation_without_a_list_is_left_to_the_script(): void {
        $content = '<nav class="wp-block-navigation"><div class="wp-block-search"></div></nav>';

        $this->assertSame( $content, $this->activate_widget()->inject_block_navigation_agent_button( $content, [ 'attrs' => [] ] ) );
    }

    public function test_block_navigation_is_skipped_when_a_classic_menu_is_targeted(): void {
        $storefront = $this->activate_widget();
        update_option( 'gecx_nav_menu_target', 'location:primary' );
        $content = '<nav class="wp-block-navigation"><ul class="wp-block-navigation__container"><li>Link</li></ul></nav>';

        $this->assertSame( $content, $storefront->inject_block_navigation_agent_button( $content, [ 'attrs' => [] ] ) );
    }

    public function test_manual_placement_disables_automatic_placement_and_enables_the_shortcode(): void {
        $storefront = $this->activate_widget( 'manual' );
        $this->assertSame( 'manual', GECX_Storefront::sanitize_button_placement( 'manual' ) );

        $this->assertSame( '', $storefront->inject_nav_menu_agent_button( '', (object) [ 'theme_location' => 'primary' ] ) );
        ob_start();
        $storefront->inject_chat_widget();
        $html = (string) ob_get_clean();
        $this->assertStringNotContainsString( 'gecx-floating-button-container', $html );
        $this->assertStringContainsString( '<gecx-woocommerce-chat-widget', $html );

        $this->assertStringContainsString( '<span class="gecx-agent-button-slot"><gecx-agent-button', $storefront->render_agent_button_shortcode() );
    }

    public function test_agent_button_shortcode_is_empty_while_the_widget_is_off(): void {
        $storefront = $this->activate_widget( 'manual' );
        update_option( 'gecx_agent_enabled', 0 );

        $this->assertSame( '', $storefront->render_agent_button_shortcode() );
    }

    public function test_agent_button_block_is_server_rendered(): void {
        $storefront = $this->activate_widget( 'manual' );
        $storefront->register_agent_button_block();

        $block = $GLOBALS['gecx_test_block_types']['gecx/agent-button'] ?? null;
        $this->assertIsArray( $block );
        $this->assertSame( 'gecx-agent-button-block', $block['editor_script'] );
        $this->assertSame( [ $storefront, 'render_agent_button_shortcode' ], $block['render_callback'] );
    }

    public function test_nothing_is_output_on_amp_pages(): void {
        $storefront                  = $this->activate_widget( 'floating' );
        $GLOBALS['gecx_test_is_amp'] = true;

        $storefront->enqueue_storefront_assets();
        $this->assertNotContains( 'gecx-storefront-js', $GLOBALS['gecx_test_enqueued_scripts'] );

        ob_start();
        $storefront->inject_chat_widget();
        $this->assertSame( '', (string) ob_get_clean() );

        $this->assertSame( '', $storefront->render_agent_button_shortcode() );
        update_option( 'gecx_button_placement', 'nav_menu' );
        $this->assertSame( '', $storefront->inject_nav_menu_agent_button( '', (object) [ 'theme_location' => 'primary' ] ) );
    }

    public function test_launcher_scripts_opt_out_of_js_delay_optimizers(): void {
        $storefront = $this->activate_widget();
        $tag        = '<script src="https://example.com/storefront.js" id="gecx-storefront-js-js"></script>';

        $result = $storefront->exclude_script_from_optimizers( $tag, 'gecx-storefront-js' );
        $this->assertStringContainsString( '<script data-cfasync="false" data-no-optimize="1" data-no-defer="1" src=', $result );
        $this->assertSame( $result, $storefront->exclude_script_from_optimizers( $result, 'gecx-storefront-js' ) );
        $this->assertSame( $tag, $storefront->exclude_script_from_optimizers( $tag, 'some-other-script' ) );

        $this->assertSame(
            [ 'id' => 'gecx-storefront-js-js-extra', 'data-cfasync' => 'false', 'data-no-optimize' => '1', 'data-no-defer' => '1' ],
            $storefront->exclude_inline_config_from_optimizers( [ 'id' => 'gecx-storefront-js-js-extra' ] )
        );
        $this->assertSame( [ 'id' => 'other' ], $storefront->exclude_inline_config_from_optimizers( [ 'id' => 'other' ] ) );

        $exclusions = $storefront->add_wp_rocket_delay_exclusions( [ 'jquery' ] );
        $this->assertContains( 'gecxStorefrontConfig', $exclusions );
        $this->assertContains( 'www.gstatic.com/gecx/chat-widget/woocommerce-chat-widget.js', $exclusions );
        $this->assertSame( 'jquery', $exclusions[0] );
    }

    public function test_js_delay_opt_out_can_be_turned_off(): void {
        $storefront = $this->activate_widget();
        add_filter( 'gecx_exclude_from_js_delay', static function (): bool { return false; } );
        $tag = '<script src="x.js"></script>';

        $this->assertSame( $tag, $storefront->exclude_script_from_optimizers( $tag, 'gecx-widget-script' ) );
        $this->assertSame( [ 'a' ], $storefront->add_wp_rocket_delay_exclusions( [ 'a' ] ) );
    }

    public function test_storefront_config_carries_typed_appearance_settings(): void {
        update_option( 'gecx_match_theme_styles', 1 );

        $this->assertSame( [ 'matchThemeStyles' => true ], $this->localized_storefront_config()['appearance'] );
    }

    public function test_prompts_markup_is_localized_off_product_pages_for_dynamically_loaded_products(): void {
        $config = $this->localized_storefront_config();

        $this->assertFalse( $config['isPdp'] );
        $this->assertStringContainsString( '<gecx-suggested-prompts', $config['pdpPromptsHtml'] );
    }

    public function test_pages_embedding_a_product_count_as_product_pages(): void {
        $GLOBALS['gecx_test_post'] = (object) [ 'post_content' => 'Intro [product_page id="12"]' ];
        $this->assertTrue( $this->localized_storefront_config()['isPdp'] );

        $GLOBALS['gecx_test_post'] = (object) [ 'post_content' => '<!-- wp:woocommerce/single-product {"productId":12} /-->' ];
        $this->assertTrue( $this->localized_storefront_config()['isPdp'] );
    }

    public function test_floating_positions_clear_bottom_bars_and_the_safe_area(): void {
        $style = $this->activate_widget( 'floating' )->get_floating_container_style( 'bottom_right' );

        $this->assertStringContainsString( 'var(--gecx-floating-offset, 0px)', $style );
        $this->assertStringContainsString( 'var(--gecx-floating-extra-offset, 0px)', $style );
        $this->assertStringContainsString( 'env(safe-area-inset-bottom, 0px)', $style );
    }
    public function test_classic_prompts_hook_is_skipped_on_block_themes(): void {
        $storefront                           = $this->activate_widget();
        $GLOBALS['gecx_test_is_product']      = true;
        $GLOBALS['gecx_test_is_block_theme']  = true;

        ob_start();
        $storefront->inject_suggested_prompts();
        $this->assertSame( '', (string) ob_get_clean() );

        // The add-to-cart block filter still places them.
        $this->assertStringContainsString( '<gecx-suggested-prompts', $storefront->inject_block_suggested_prompts( '<div class="wp-block-woocommerce-add-to-cart-form"></div>', [] ) );
    }
}

/**
 * Exposes the protected URL resolver so asset origins can be asserted.
 */
class StorefrontUrlProbe extends GECX_Storefront {

    /**
     * @return array{script: string, style: string}
     */
    public function widget_urls(): array {
        return $this->resolve_widget_urls();
    }
}

if ( php_sapi_name() === 'cli' ) {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $argv0 = isset( $_SERVER['argv'][0] ) ? sanitize_text_field( wp_unslash( $_SERVER['argv'][0] ) ) : '';
    if ( empty( $argv0 ) || basename( $argv0 ) === basename( __FILE__ ) ) {
        gecx_run_test_class( StorefrontTest::class );
    }
}
