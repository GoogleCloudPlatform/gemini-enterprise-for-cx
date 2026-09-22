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
        $this->assertStringContainsString( '@media (max-width: 599.98px) { .wp-block-navigation .gecx-nav-menu-item { display: none !important; } }', $inline_styles );
        $this->assertStringContainsString( '@media (min-width: 600px) { .wp-block-navigation .gecx-mobile-header-button { display: none !important; } }', $inline_styles );
        $this->assertStringContainsString( '@media (max-width: 767.98px) { .main-navigation .gecx-nav-menu-item, .site-header nav .gecx-nav-menu-item { display: none !important; } }', $inline_styles );
        $this->assertStringContainsString( '@media (min-width: 768px) { .main-navigation .gecx-mobile-header-button, .site-header .gecx-mobile-header-button { display: none !important; } }', $inline_styles );
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
        $this->assertStringContainsString( 'position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);', $html_bottom );

        // Bottom right (20px offset to align with mobile floating button)
        update_option( 'gecx_floating_position', 'bottom_right' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_bottom_right = ob_get_clean();

        $this->assertStringContainsString( 'class="gecx-floating-button-container gecx-floating-button-container--bottom-right"', $html_bottom_right );
        $this->assertStringContainsString( 'position: fixed; bottom: 20px; right: 20px; z-index: 999999;', $html_bottom_right );

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
        $this->assertStringContainsString( 'position: fixed; bottom: 20px; left: 20px; z-index: 999999;', $html_bottom_left );

        // Unknown stored option falls back to bottom_center
        update_option( 'gecx_floating_position', 'unknown_invalid_position' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_unknown = ob_get_clean();

        $this->assertStringContainsString( 'class="gecx-floating-button-container gecx-floating-button-container--bottom-center"', $html_unknown );
        $this->assertStringContainsString( 'position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);', $html_unknown );

        // RTL locales mirror horizontal placement
        $GLOBALS['gecx_test_is_rtl'] = true;

        update_option( 'gecx_floating_position', 'bottom_right' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_rtl_bottom_right = ob_get_clean();
        $this->assertStringContainsString( 'position: fixed; bottom: 20px; left: 20px; z-index: 999999;', $html_rtl_bottom_right );

        update_option( 'gecx_floating_position', 'bottom_left' );
        ob_start();
        $storefront->inject_chat_widget();
        $html_rtl_bottom_left = ob_get_clean();
        $this->assertStringContainsString( 'position: fixed; bottom: 20px; right: 20px; z-index: 999999;', $html_rtl_bottom_left );

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
