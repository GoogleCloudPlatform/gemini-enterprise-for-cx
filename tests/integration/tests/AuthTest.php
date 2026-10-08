<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Auth Test Suite for Gemini Enterprise for Customer Experience (GECX)
 *
 * @package GECX
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

use Google\Gemini_Enterprise_For_CX\Auth;
use ReflectionMethod;
use stdClass;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

class AuthTest extends TestCase {

    private Auth $auth;
    private array $unknown_issuer_reports = [];

    public function set_up(): void {
        parent::set_up();
        $_SERVER['REQUEST_URI']        = '/wp-json/wc/store/v1/cart';
        $this->auth                    = new Auth();
        $this->unknown_issuer_reports = [];

        add_action(
            'gecx_cart_token_unknown_issuer',
            function ( $reported ) {
                $this->unknown_issuer_reports[] = $reported;
            }
        );

        // Token subjects used across these tests must resolve to real,
        // unprivileged shoppers. A token naming a user who does not exist is
        // refused outright, which would mask the behaviour under test.
        $this->ensure_user_with_id( 123, 'shopper123', 'customer' );
        $this->ensure_user_with_id( 456, 'shopper456', 'customer' );
    }

    public function test_bypass_if_no_token(): void {
        unset( $_SERVER['HTTP_CART_TOKEN'] );
        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    public function test_authenticate_valid_token(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123 );
        $this->assertEquals( 123, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    public function test_authenticate_via_cart_token_plain_permalink(): void {
        $_SERVER['REQUEST_URI']     = '/index.php?rest_route=/wc/store/v1/cart';
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $this->assertEquals( 456, $this->auth->authenticate_via_cart_token( 0 ) );

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=/wp/v2/posts';
        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * A cart token is a shopper session handle. It must not authenticate on this
     * plugin's own routes, which carry administrative capability.
     */
    public function test_cart_token_is_rejected_on_gecx_routes(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );

        foreach ( [ '/wp-json/gecx/v1/link-agent', '/wp-json/gecx/v1/session', '/wp-json/gecx/v1/public-key' ] as $path ) {
            $_SERVER['REQUEST_URI'] = $path;
            $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ), $path );
        }

        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=/gecx/v1/public-key';
        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * WordPress dispatches on 'rest_route' when it is present, so a path that
     * merely looks like the Store API must not win over it.
     */
    public function test_cart_token_rejected_when_rest_route_spoofs_store_api_path(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );

        $spoofs = [
            '/wc/store/v1/?rest_route=/gecx/v1/public-key',
            '/wp-json/wc/store/v1/cart?rest_route=/gecx/v1/public-key',
            '/wc/store/v1/cart/?rest_route=/wp/v2/users',
        ];
        foreach ( $spoofs as $uri ) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ), $uri );
        }
    }

    /**
     * ?rest_route[]=... parses to an array rather than a string. It must be
     * refused without emitting an array-to-string conversion warning.
     */
    public function test_cart_token_rejected_when_rest_route_is_an_array(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['REQUEST_URI']     = '/wc/store/v1/cart?rest_route[]=/wc/store/v1/cart';

        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * WP::parse_request() reads $_POST before $_GET and before the rewritten
     * path, so a form-encoded rest_route body parameter decides the dispatched
     * route. A Store API path with a POSTed rest_route naming something else
     * must not authenticate.
     */
    public function test_cart_token_rejected_when_post_rest_route_overrides_path(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['REQUEST_URI']     = '/wp-json/wc/store/v1/cart';
        $_POST['rest_route']        = '/wp/v2/users/me';

        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * $_POST also outranks $_GET, so a Store API value in the query string
     * cannot rescue a body parameter naming another route.
     */
    public function test_cart_token_rejected_when_post_rest_route_overrides_get(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['REQUEST_URI']     = '/index.php?rest_route=/wc/store/v1/cart';
        $_GET['rest_route']         = '/wc/store/v1/cart';
        $_POST['rest_route']        = '/wp/v2/users/me';

        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * Once WordPress has resolved the route, that is the authoritative answer
     * and the request URI is irrelevant.
     */
    public function test_resolved_query_var_outranks_request_uri(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['REQUEST_URI']     = '/wp-json/wc/store/v1/cart';

        $GLOBALS['wp']             = new stdClass();
        $GLOBALS['wp']->query_vars = [ 'rest_route' => '/gecx/v1/public-key' ];
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );

        $GLOBALS['wp']->query_vars = [ 'rest_route' => '/wc/store/v1/cart' ];
        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );

        // No unset() here: setUp() clears $GLOBALS['wp'], so a failure above
        // cannot leak resolved-route state into the next test.
    }

    /**
     * An empty resolved route means WordPress resolved no REST route at all,
     * so it is authoritative too and must not fall through to the path.
     */
    public function test_empty_resolved_query_var_does_not_fall_through(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['REQUEST_URI']     = '/wp-json/wc/store/v1/cart';

        $GLOBALS['wp']             = new stdClass();
        $GLOBALS['wp']->query_vars = [ 'rest_route' => '' ];

        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * WP::parse_request() reads WP::$extra_query_vars before $_POST, so a
     * caller that has already named a route there decides the dispatch.
     */
    public function test_extra_query_vars_outrank_post(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['REQUEST_URI']     = '/wp-json/wc/store/v1/cart';
        $_POST['rest_route']        = '/wc/store/v1/cart';

        $GLOBALS['wp']                   = new stdClass();
        $GLOBALS['wp']->extra_query_vars = [ 'rest_route' => '/gecx/v1/public-key' ];
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );

        $GLOBALS['wp']->extra_query_vars = [ 'rest_route' => '/wc/store/v1/cart' ];
        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * The rejection cases above would all pass against a check that never
     * authenticates anything, so each fallback tier needs a case that does.
     */
    public function test_post_rest_route_naming_store_api_authenticates(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['REQUEST_URI']     = '/';
        $_POST['rest_route']        = '/wc/store/v1/cart';

        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    public function test_get_rest_route_naming_store_api_authenticates(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['REQUEST_URI']     = '/';
        $_GET['rest_route']         = '/wc/store/v1/cart';

        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * With plain permalinks WordPress routes on the query string alone, so a
     * /wp-json path there does not make the request a REST request.
     */
    public function test_store_api_path_is_ignored_with_plain_permalinks(): void {
        update_option( 'permalink_structure', '' );
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['REQUEST_URI']     = '/wp-json/wc/store/v1/cart';

        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * rest_api_register_rewrites() adds both ^wp-json/ and ^index.php/wp-json/
     * for every non-plain structure, PATHINFO included, so on those sites both
     * paths are REST requests and must authenticate.
     */
    public function test_store_api_path_authenticates_with_pathinfo_permalinks(): void {
        update_option( 'permalink_structure', '/index.php/%postname%/' );
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );

        $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );

        $_SERVER['REQUEST_URI'] = '/index.php/wp-json/wc/store/v1/cart';
        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * rest_url() produces /index.php/wp-json/ under PATHINFO permalinks, and the
     * index-prefixed rule exists under every non-plain structure.
     */
    public function test_index_prefixed_store_api_path_authenticates_with_pretty_permalinks(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['REQUEST_URI']     = '/index.php/wp-json/wc/store/v1/cart/add-item';

        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );

        $_SERVER['REQUEST_URI'] = '/index.php/wp-json/wp/v2/users';
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    public function test_index_prefixed_store_api_path_is_ignored_with_plain_permalinks(): void {
        update_option( 'permalink_structure', '' );
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['REQUEST_URI']     = '/index.php/wp-json/wc/store/v1/cart';

        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * The guard above must not cost plain-permalink stores the Store API: they
     * reach it through ?rest_route=, which is resolved before the path.
     */
    public function test_rest_route_query_still_authenticates_with_plain_permalinks(): void {
        update_option( 'permalink_structure', '' );
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['REQUEST_URI']     = '/index.php?rest_route=/wc/store/v1/cart';
        $_GET['rest_route']         = '/wc/store/v1/cart';

        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * Whatever route the pre-parse inference guessed, a request WordPress
     * resolves to an ordinary page must not keep the token's user.
     */
    public function test_cart_token_user_is_dropped_when_request_is_not_store_api(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        wp_set_current_user( $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertSame( 456, get_current_user_id() );

        $wp             = new stdClass();
        $wp->query_vars = [ 'page_id' => '7' ];
        $this->auth->drop_cart_token_user_outside_store_api( $wp );

        $this->assertSame( 0, get_current_user_id() );
        $this->assertTrue( Auth::is_cart_token_request() );
    }

    /**
     * Store API routes other than cart and batch are not a cart token's to
     * reach, and a request WordPress resolved to no route at all is a page.
     */
    public function test_cart_token_user_is_dropped_for_non_cart_or_empty_routes(): void {
        foreach ( [ '/wc/store/v1/checkout', '/wc/store/v1/order/42', '' ] as $route ) {
            Auth::reset_cart_token_state();
            $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
            wp_set_current_user( $this->auth->authenticate_via_cart_token( 0 ) );
            $this->assertSame( 456, get_current_user_id() );

            $wp             = new stdClass();
            $wp->query_vars = [ 'rest_route' => $route ];
            $this->auth->drop_cart_token_user_outside_store_api( $wp );

            $this->assertSame( 0, get_current_user_id(), "route '{$route}'" );
        }
    }

    /**
     * A later determine_current_user callback may replace the token's user.
     * The backstop only undoes the cart-token login, not someone else's.
     */
    public function test_backstop_leaves_a_user_that_replaced_the_token_user(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $this->auth->authenticate_via_cart_token( 0 );
        wp_set_current_user( 123 );

        $wp             = new stdClass();
        $wp->query_vars = [ 'page_id' => '7' ];
        $this->auth->drop_cart_token_user_outside_store_api( $wp );

        $this->assertSame( 123, get_current_user_id() );
        $this->assertTrue( Auth::is_cart_token_request() );
    }

    public function test_cart_token_user_is_kept_when_request_is_store_api(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        wp_set_current_user( $this->auth->authenticate_via_cart_token( 0 ) );

        $wp             = new stdClass();
        $wp->query_vars = [ 'rest_route' => '/wc/store/v1/cart/add-item' ];
        $this->auth->drop_cart_token_user_outside_store_api( $wp );

        $this->assertSame( 456, get_current_user_id() );
    }

    /**
     * Only a cart-token login is undone. A shopper logged in by cookie keeps
     * their session on every page.
     */
    public function test_cookie_user_is_untouched_by_the_parse_request_backstop(): void {
        wp_set_current_user( 123 );

        $wp             = new stdClass();
        $wp->query_vars = [ 'page_id' => '7' ];
        $this->auth->drop_cart_token_user_outside_store_api( $wp );

        $this->assertSame( 123, get_current_user_id() );
    }

    public function test_parse_request_backstop_runs_before_rest_dispatch(): void {
        $priority = has_action( 'parse_request', [ $this->auth, 'drop_cart_token_user_outside_store_api' ] );

        // rest_api_loaded() is on parse_request at 10 and exits after dispatch.
        $this->assertNotFalse( $priority );
        $this->assertLessThan( 10, $priority );
    }

    /**
     * admin-ajax.php loads WordPress without calling wp(), so parse_request()
     * never runs, no REST route is dispatched, and 'rest_pre_dispatch' never
     * fires. A rest_route parameter there names nothing, and honouring it
     * would run the wp_ajax_{$action} handler as the token's shopper.
     */
    public function test_rest_route_on_a_non_front_controller_entry_point_is_ignored(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['SCRIPT_NAME']     = '/wp-admin/admin-ajax.php';
        $_SERVER['REQUEST_URI']     = '/wp-admin/admin-ajax.php?action=x&rest_route=/wc/store/v1/cart';
        $_GET['rest_route']         = '/wc/store/v1/cart';

        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );

        // Same for a POSTed parameter and for a path that looks like the
        // Store API on an entry point that will never route it.
        unset( $_GET['rest_route'] );
        $_POST['rest_route'] = '/wc/store/v1/cart';
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );

        unset( $_POST['rest_route'] );
        $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * The gate must not reach a request WordPress already routed: a resolved
     * query var can only exist because parse_request() ran.
     */
    public function test_resolved_query_var_is_honoured_regardless_of_entry_point(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['SCRIPT_NAME']     = '/wp-admin/admin-ajax.php';

        $GLOBALS['wp']             = new stdClass();
        $GLOBALS['wp']->query_vars = [ 'rest_route' => '/wc/store/v1/cart' ];

        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * A subdirectory install reports the front controller inside the
     * subdirectory, which is still index.php.
     */
    public function test_front_controller_gate_allows_a_subdirectory_install(): void {
        add_filter( 'home_url', static fn() => 'https://example.org/blog' );
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['SCRIPT_NAME']     = '/blog/index.php';
        $_SERVER['REQUEST_URI']     = '/blog/wp-json/wc/store/v1/cart';

        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * (int) '1e3' is 1000 and (string) true is '1', so a subject that is not
     * a plain digit string would otherwise name a different user than it
     * appears to, or any user at all.
     */
    public function test_non_integral_user_id_is_refused(): void {
        foreach ( [ '1e3', true, ' 1', '01e2' ] as $subject ) {
            $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( $subject );
            $this->assertSame(
                0,
                $this->auth->authenticate_via_cart_token( 0 ),
                var_export( $subject, true )
            );
        }
    }

    /**
     * A refusal must be observable without WP_DEBUG, and must say which of the
     * two failure modes occurred rather than naming a capability the user may
     * not hold.
     */
    public function test_refusal_reason_distinguishes_unresolved_user_from_capability(): void {
        $refused_actions = [];
        add_action(
            'gecx_cart_token_refused',
            static function ( ...$args ) use ( &$refused_actions ) {
                $refused_actions[] = $args;
            },
            10,
            3
        );

        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 99999 );
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertSame(
            [ [ 99999, Auth::REFUSAL_CODE_USER_UNRESOLVED, null ] ],
            $refused_actions
        );

        $refused_actions = [];
        $admin_id        = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( $admin_id );
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertSame(
            [ [ $admin_id, Auth::REFUSAL_CODE_CAPABILITY_HELD, 'manage_options' ] ],
            $refused_actions
        );
    }

    /**
     * 'edit_posts' is held by contributors, so a store that also runs a blog
     * can widen who the agent serves. Nothing else in the list moves with it.
     */
    public function test_privileged_caps_filter_can_narrow_the_list(): void {
        $user_id = $this->factory()->user->create( [ 'role' => 'contributor' ] );
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( $user_id );

        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );

        add_filter(
            'gecx_cart_token_privileged_caps',
            static function ( array $caps ): array {
                return array_values( array_diff( $caps, [ 'edit_posts' ] ) );
            }
        );

        $this->assertSame( $user_id, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * An empty or malformed filter result restores the defaults rather than
     * disabling the check.
     */
    public function test_privileged_caps_filter_cannot_empty_the_list(): void {
        $user_id = $this->factory()->user->create( [ 'role' => 'contributor' ] );
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( $user_id );

        add_filter(
            'gecx_cart_token_privileged_caps',
            static function (): array {
                return [];
            }
        );

        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * The flag describes how this request was authenticated. A request that
     * already has a user was not authenticated by a cart token, even if one is
     * present on it.
     */
    public function test_flag_stays_false_when_user_is_already_authenticated(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );

        $this->assertSame( 123, $this->auth->authenticate_via_cart_token( 123 ) );
        $this->assertFalse( Auth::is_cart_token_request() );
    }

    /**
     * The dispatch-path backstop: whatever the route looked like when
     * 'determine_current_user' ran, a cart-token request that WordPress
     * resolves to a non-Store-API route is refused.
     */
    public function test_dispatch_backstop_refuses_cart_token_off_store_api(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertTrue( Auth::is_cart_token_request() );

        $request = new WP_REST_Request();
        $request->set_route( '/wp/v2/users/me' );

        $result = $this->auth->block_cart_token_off_store_api( null, null, $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rest_forbidden', $result->get_error_code() );
    }

    public function test_dispatch_backstop_allows_cart_token_on_store_api(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );

        $request = new WP_REST_Request();
        $request->set_route( '/wc/store/v1/cart/add-item' );

        $this->assertNull( $this->auth->block_cart_token_off_store_api( null, null, $request ) );
    }

    /**
     * A cookie-authenticated operator never raises the flag, so the backstop
     * must not touch their request.
     */
    public function test_dispatch_backstop_ignores_requests_without_a_cart_token(): void {
        $admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin_id );

        $request = new WP_REST_Request();
        $request->set_route( '/gecx/v1/public-key' );

        $this->assertNull( $this->auth->block_cart_token_off_store_api( null, null, $request ) );
    }

    /**
     * A token outlives the account it names. If the user has since been
     * deleted, the capability check cannot answer, so it must fail closed.
     */
    public function test_cart_token_for_deleted_user_does_not_authenticate(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 99999 );

        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertFalse( Auth::is_cart_token_request() );
    }

    /**
     * A bare '/wc/store/' substring is not enough; the namespace must be
     * versioned, so an attacker-chosen path segment cannot satisfy the check.
     */
    public function test_cart_token_requires_versioned_store_api_namespace(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );

        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/public-key/wc/store/';
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );

        // The versioned form, embedded mid-path. The route WordPress resolves
        // here is '/gecx/v1/public-key/wc/store/v1/x', not a Store API route, so a
        // substring match anywhere in the path is not good enough either.
        $_SERVER['REQUEST_URI'] = '/wp-json/gecx/v1/public-key/wc/store/v1/x';
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * The REST prefix is stripped the way WordPress strips it, so a
     * subdirectory install resolves the same route a root install does.
     */
    public function test_store_api_path_is_matched_in_a_subdirectory_install(): void {
        add_filter( 'home_url', static fn() => 'https://example.org/blog/shop' );
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $_SERVER['SCRIPT_NAME']     = '/blog/shop/index.php';
        $_SERVER['REQUEST_URI']     = '/blog/shop/wp-json/wc/store/v1/cart';

        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * Sites may rename the REST prefix. A path carrying the default prefix is
     * then not a REST request at all.
     */
    public function test_store_api_path_honours_a_renamed_rest_prefix(): void {
        add_filter( 'rest_url_prefix', static fn() => 'api' );
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );

        $_SERVER['REQUEST_URI'] = '/api/wc/store/v1/cart';
        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );

        $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * Prevents privilege escalation: a stolen administrator cart token must not
     * resolve to that administrator.
     *
     * The administrator is registered as a user rather than as the current
     * user, because the scenario is a stolen token presented by someone who is
     * not logged in as anyone.
     */
    public function test_cart_token_never_resolves_to_privileged_user(): void {
        $admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        $admin    = get_userdata( $admin_id );

        // Pins the capability: if roles stopped mapping to capabilities,
        // the assertions below would pass for the wrong reason.
        $this->assertTrue( user_can( $admin, 'manage_options' ) );

        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( $admin_id );
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertFalse( Auth::is_cart_token_request() );
    }

    public function test_cart_token_never_resolves_to_shop_manager(): void {
        $manager_id = $this->factory()->user->create( [ 'role' => 'shop_manager' ] );
        $manager    = get_userdata( $manager_id );

        $this->assertTrue( user_can( $manager, 'manage_woocommerce' ) );

        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( $manager_id );
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertFalse( Auth::is_cart_token_request() );
    }

    public function test_reject_token_with_foreign_issuer(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123, 3600, wp_salt(), true, 'some-other-issuer' );
        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    /**
     * WooCommerce 7.1 through 9.9 stamp the minting route's namespace as the
     * issuer; 10.0 and later stamp 'store-api'. Both have to authenticate, or
     * the plugin silently degrades every logged-in shopper to a guest on one
     * side of that boundary.
     */
    public function test_accept_token_with_pre_10_namespace_issuer(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123, 3600, wp_salt(), true, 'wc/store/v1' );
        $this->assertSame( 123, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertTrue( Auth::is_cart_token_request() );
    }

    /**
     * RoutesController registers every Store API route twice before 10.0,
     * under 'wc/store' as well as 'wc/store/v1', so a token minted from the
     * unversioned legacy alias names the alias. Where the token was minted is
     * independent of where it is presented, which the scoping check still
     * restricts to a versioned route.
     */
    public function test_accept_token_issued_by_unversioned_store_alias(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123, 3600, wp_salt(), true, 'wc/store' );
        $this->assertSame( 123, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertTrue( Auth::is_cart_token_request() );
    }

    /**
     * 'wc/private' is a sibling Store API namespace whose only route mints no
     * cart token, so a token claiming it did not come from WooCommerce.
     */
    public function test_reject_token_issued_by_private_namespace(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123, 3600, wp_salt(), true, 'wc/private' );
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertFalse( Auth::is_cart_token_request() );
    }

    /**
     * WooCommerce below 7.1 stamps no issuer. Refusing those tokens is what
     * the 'WC requires at least' header rests on.
     */
    public function test_reject_token_with_no_issuer_claim(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123, 3600, wp_salt(), true, null );
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertFalse( Auth::is_cart_token_request() );
        $this->assertSame( [ '(absent)' ], $this->unknown_issuer_reports() );
    }

    /**
     * An unrecognised issuer is otherwise the only silent refusal, and it is
     * the one that fires when WooCommerce changes the claim again.
     */
    public function test_unknown_issuer_is_reported_with_its_value(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123, 3600, wp_salt(), true, 'wc/private' );
        $this->auth->authenticate_via_cart_token( 0 );
        $this->assertSame( [ 'wc/private' ], $this->unknown_issuer_reports() );
    }

    /**
     * The report sits behind hash_equals(), so a token nobody signed cannot
     * reach it. Otherwise the log is writable by anyone with the endpoint.
     */
    public function test_unknown_issuer_not_reported_for_unsigned_token(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123, 3600, wp_salt(), false, 'whatever' );
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertSame( [], $this->unknown_issuer_reports() );
    }

    /**
     * An accepted issuer must not report anything.
     */
    public function test_known_issuer_is_not_reported(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123, 3600, wp_salt(), true, 'wc/store' );
        $this->assertSame( 123, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertSame( [], $this->unknown_issuer_reports() );
    }

    /**
     * Issuers passed to 'gecx_cart_token_unknown_issuer' this request.
     *
     * @return array<int, string>
     */
    private function unknown_issuer_reports(): array {
        return $this->unknown_issuer_reports;
    }

    /**
     * Guests carry a random session hash rather than a numeric user ID.
     */
    public function test_guest_session_hash_does_not_authenticate(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 't_a1b2c3d4e5f60718293a4b5c6d7e8f90' );
        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertFalse( Auth::is_cart_token_request() );
    }

    public function test_successful_cart_token_auth_sets_request_flag(): void {
        $this->assertFalse( Auth::is_cart_token_request() );
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123 );
        $this->assertEquals( 123, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertTrue( Auth::is_cart_token_request() );
    }

    public function test_reject_expired_token(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123, -10 ); // expired
        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    public function test_reject_invalid_signature_salt(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123, 3600, 'wrong_salt' );
        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    public function test_reject_manipulated_signature(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123, 3600, wp_salt(), false );
        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    public function test_reject_malformed_token(): void {
        $_SERVER['HTTP_CART_TOKEN'] = 'malformed.token.value';
        $this->assertEquals( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    public function test_generate_customer_jwt_generates_guest_jwt_when_logged_out(): void {
        wp_set_current_user( 0 );

        $jwt = Auth::generate_customer_jwt();
        $this->assertNotNull( $jwt );

        $pub_key = Auth::get_public_key();
        $this->assertNotNull( $pub_key );
        $this->assertTrue( $this->verify_rs256_jwt( $jwt, $pub_key ) );

        $payload = $this->decode_jwt_payload( $jwt );
        $this->assertNotNull( $payload );
        $this->assertEquals( 0, $payload['user_id'] );
        $this->assertEquals( '', $payload['user_email'] );
        $this->assertFalse( $payload['is_admin'] );
        $this->assertEquals( wp_parse_url( home_url(), PHP_URL_HOST ), $payload['iss'] );
        $this->assertEquals( 'gecx.cloud.google.com', $payload['aud'] );
        $this->assertTrue( isset( $payload['exp'] ) && $payload['exp'] > time() );
    }

    public function test_generate_customer_jwt_returns_null_when_no_secret_and_no_keypair(): void {
        delete_option( 'gecx_api_secret' );
        delete_option( 'gecx_keypair' );
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );
        add_filter( 'salt', '__return_empty_string' );

        $this->assertNull( Auth::generate_customer_jwt() );
    }

    public function test_generate_and_verify_customer_jwt_success(): void {
        $user_id = $this->factory()->user->create( [ 'role' => 'customer' ] );
        $user    = get_userdata( $user_id );
        wp_set_current_user( $user_id );

        $jwt = Auth::generate_customer_jwt();
        $this->assertNotNull( $jwt );

        $pub_key = Auth::get_public_key();
        $this->assertNotNull( $pub_key );
        $this->assertTrue( $this->verify_rs256_jwt( $jwt, $pub_key ) );

        $payload = $this->decode_jwt_payload( $jwt );
        $this->assertNotNull( $payload );
        $this->assertEquals( $user_id, $payload['user_id'] );
        $this->assertEquals( $user->user_email, $payload['user_email'] );
        $this->assertFalse( $payload['is_admin'] );
        $this->assertEquals( wp_parse_url( home_url(), PHP_URL_HOST ), $payload['iss'] );
        $this->assertTrue( isset( $payload['exp'] ) && $payload['exp'] > time() );
    }

    public function test_generate_customer_jwt_never_claims_admin_for_administrator(): void {
        $admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        $admin    = get_userdata( $admin_id );
        wp_set_current_user( $admin_id );

        $jwt = Auth::generate_customer_jwt();
        $this->assertNotNull( $jwt );

        $pub_key = Auth::get_public_key();
        $this->assertNotNull( $pub_key );
        $this->assertTrue( $this->verify_rs256_jwt( $jwt, $pub_key ) );

        $payload = $this->decode_jwt_payload( $jwt );
        $this->assertNotNull( $payload );
        $this->assertEquals( $admin_id, $payload['user_id'] );
        $this->assertEquals( $admin->user_email, $payload['user_email'] );
        $this->assertFalse( $payload['is_admin'] );
        $this->assertEquals( wp_parse_url( home_url(), PHP_URL_HOST ), $payload['iss'] );
    }

    public function test_generate_admin_jwt_success(): void {
        $admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        $admin    = get_userdata( $admin_id );
        wp_set_current_user( $admin_id );

        $jwt = Auth::generate_admin_jwt();
        $this->assertNotNull( $jwt );

        $pub_key = Auth::get_public_key();
        $this->assertNotNull( $pub_key );
        $this->assertTrue( $this->verify_rs256_jwt( $jwt, $pub_key ) );

        $payload = $this->decode_jwt_payload( $jwt );
        $this->assertNotNull( $payload );
        $this->assertEquals( $admin_id, $payload['user_id'] );
        $this->assertEquals( $admin->user_email, $payload['user_email'] );
        $this->assertTrue( $payload['is_admin'] );
        $this->assertEquals( wp_parse_url( home_url(), PHP_URL_HOST ), $payload['iss'] );
        $this->assertTrue( isset( $payload['iat'] ) && isset( $payload['exp'] ) );
        $this->assertEquals( $payload['iat'] + 3600, $payload['exp'] );
    }

    public function test_generate_admin_jwt_custom_expiration(): void {
        $admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin_id );

        $jwt = Auth::generate_admin_jwt( $admin_id, 600, 'custom_admin@example.org' );
        $this->assertNotNull( $jwt );

        $pub_key = Auth::get_public_key();
        $this->assertNotNull( $pub_key );
        $this->assertTrue( $this->verify_rs256_jwt( $jwt, $pub_key ) );

        $payload = $this->decode_jwt_payload( $jwt );
        $this->assertNotNull( $payload );
        $this->assertEquals( 'custom_admin@example.org', $payload['user_email'] );
        $this->assertEquals( $payload['iat'] + 600, $payload['exp'] );
        $this->assertTrue( $payload['is_admin'] );
    }

    public function test_generate_jwt_returns_null_when_no_private_key(): void {
        delete_option( 'gecx_keypair' );
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );
        add_filter( 'salt', '__return_empty_string' );

        // A store that once held the retired shared secret must not fall back
        // to signing with it.
        update_option( 'gecx_api_secret', 'legacy_shared_secret_456' );

        $this->assertNull( Auth::generate_customer_jwt() );

        delete_option( 'gecx_api_secret' );
    }

    public function test_corrupt_private_key_is_replaced_and_signing_recovers_to_rs256(): void {
        $user_id = $this->factory()->user->create( [ 'role' => 'customer' ] );
        wp_set_current_user( $user_id );

        // Encrypt an invalid PEM string as the private key. It decrypts fine but
        // will not sign, which is the corrupt-key case rather than salt rotation.
        $corrupt_pem = '-----BEGIN RSA PRIVATE KEY----- INVALID NOT A REAL KEY -----END RSA PRIVATE KEY-----';
        $encrypted   = Auth::encrypt_private_key( $corrupt_pem );
        update_option( 'gecx_public_key', '-----BEGIN PUBLIC KEY-----\nMIIB...\n-----END PUBLIC KEY-----' );
        update_option( 'gecx_private_key', $encrypted );

        // A store still holding the retired shared secret must not sign with it.
        update_option( 'gecx_api_secret', 'legacy_shared_secret_789' );

        $jwt = Auth::generate_customer_jwt();
        $this->assertNotNull( $jwt );

        // Falling back to HS256 here would be pointless: a backend holding this
        // store's RSA public key rejects it. Replacing the key is what restores
        // signing, so the token must come back RS256.
        $parts  = explode( '.', $jwt );
        $header = json_decode( $this->base64_url_decode( $parts[0] ), true );
        $this->assertEquals( 'RS256', $header['alg'] );

        $payload = $this->decode_jwt_payload( $jwt );
        $this->assertEquals( $user_id, $payload['user_id'] );

        // The corrupt key is gone, not left in place to fail again next time.
        $this->assertTrue( $encrypted !== get_option( 'gecx_private_key' ) );
        $this->assertTrue( false === strpos( (string) get_option( 'gecx_public_key' ), 'MIIB...' ) );

        delete_option( 'gecx_api_secret' );
    }

    public function test_half_keypair_is_not_discarded_while_lock_is_held(): void {
        // Simulate Worker A currently holding gecx_keypair_lock after writing gecx_public_key
        // and right before writing gecx_private_key.
        update_option( 'gecx_public_key', '-----BEGIN PUBLIC KEY-----\nWORKER_A_KEY\n-----END PUBLIC KEY-----' );
        delete_option( 'gecx_private_key' );
        add_option( 'gecx_keypair_lock', time(), '', 'no' );

        $result = Auth::get_or_generate_keypair();
        $this->assertNull( $result );
        // Worker B must NOT have deleted Worker A's gecx_public_key while the lock was held.
        $this->assertSame(
            '-----BEGIN PUBLIC KEY-----\nWORKER_A_KEY\n-----END PUBLIC KEY-----',
            get_option( 'gecx_public_key' )
        );
        delete_option( 'gecx_keypair_lock' );
    }

    public function test_generate_admin_jwt_returns_null_when_no_user(): void {
        $this->assertNull( Auth::generate_admin_jwt() );
        $this->assertNull( Auth::generate_admin_jwt( 0 ) );
        $this->assertNull( Auth::generate_admin_jwt( -1 ) );
    }

    public function test_generate_customer_jwt_explicit_zero_forces_guest_when_logged_in(): void {
        wp_set_current_user( 1 );

        $jwt = Auth::generate_customer_jwt( 0 );
        $this->assertNotNull( $jwt );

        $payload = $this->decode_jwt_payload( $jwt );
        $this->assertNotNull( $payload );
        $this->assertEquals( 0, $payload['user_id'] );
        $this->assertEquals( '', $payload['user_email'] );
        $this->assertFalse( $payload['is_admin'] );
    }

    public function test_generate_customer_jwt_negative_id_normalizes_to_guest(): void {
        wp_set_current_user( 0 );

        $jwt = Auth::generate_customer_jwt( -5 );
        $this->assertNotNull( $jwt );

        $payload = $this->decode_jwt_payload( $jwt );
        $this->assertNotNull( $payload );
        $this->assertEquals( 0, $payload['user_id'] );
        $this->assertEquals( '', $payload['user_email'] );
        $this->assertFalse( $payload['is_admin'] );
    }

    public function test_generate_customer_jwt_with_explicit_email_populates_user_and_roles(): void {
        wp_set_current_user( 1 );

        $jwt = Auth::generate_customer_jwt( 1, 3600, 'custom@example.com' );
        $this->assertNotNull( $jwt );

        $payload = $this->decode_jwt_payload( $jwt );
        $this->assertNotNull( $payload );
        $this->assertEquals( 1, $payload['user_id'] );
        $this->assertEquals( 'custom@example.com', $payload['user_email'] );
        $this->assertFalse( $payload['is_admin'] );
    }

    public function test_generate_customer_jwt_never_claims_admin_for_explicit_admin_id(): void {
        $admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        $admin    = get_userdata( $admin_id );

        $jwt = Auth::generate_customer_jwt( $admin_id );
        $this->assertNotNull( $jwt );

        $payload = $this->decode_jwt_payload( $jwt );
        $this->assertNotNull( $payload );
        $this->assertEquals( $admin_id, $payload['user_id'] );
        // Resolved through get_userdata(), not wp_get_current_user(): the ID
        // passed is not the current user's.
        $this->assertEquals( $admin->user_email, $payload['user_email'] );
        $this->assertFalse( $payload['is_admin'] );
    }

    public function test_customer_jwt_payload_is_not_filterable(): void {
        $user_id = $this->factory()->user->create( [ 'role' => 'customer' ] );
        $user    = get_userdata( $user_id );
        wp_set_current_user( $user_id );

        add_filter(
            'gecx_customer_jwt_payload',
            static function ( $payload ) {
                $payload['is_admin']     = true;
                $payload['user_id']      = 1;
                $payload['user_email']   = 'admin@example.org';
                $payload['iss']          = 'attacker.example';
                $payload['aud']          = 'attacker.example';
                $payload['exp']          = time() + 31536000;
                $payload['loyalty_tier'] = 'gold';
                return $payload;
            }
        );

        $jwt = Auth::generate_customer_jwt();
        $this->assertNotNull( $jwt );

        $payload = $this->decode_jwt_payload( $jwt );
        $this->assertNotNull( $payload );
        $this->assertFalse( $payload['is_admin'] );
        $this->assertEquals( $user_id, $payload['user_id'] );
        $this->assertEquals( $user->user_email, $payload['user_email'] );
        $this->assertEquals( wp_parse_url( home_url(), PHP_URL_HOST ), $payload['iss'] );
        $this->assertEquals( 'gecx.cloud.google.com', $payload['aud'] );
        $this->assertEquals( $payload['iat'] + 3600, $payload['exp'] );
        // The hook is gone rather than constrained, so a filter's own claims do
        // not reach the token either.
        $this->assertArrayNotHasKey( 'loyalty_tier', $payload );
    }

    public function test_admin_jwt_payload_is_not_filterable(): void {
        $admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
        $admin    = get_userdata( $admin_id );
        wp_set_current_user( $admin_id );

        add_filter(
            'gecx_admin_jwt_payload',
            static function ( $payload ) {
                $payload['user_id']      = 1;
                $payload['user_email']   = 'attacker@example.org';
                $payload['iss']          = 'attacker.example';
                $payload['aud']          = 'attacker.example';
                $payload['exp']          = time() + 31536000;
                $payload['loyalty_tier'] = 'gold';
                return $payload;
            }
        );

        $payload = $this->decode_jwt_payload( (string) Auth::generate_admin_jwt() );
        $this->assertNotNull( $payload );
        $this->assertTrue( $payload['is_admin'] );
        $this->assertEquals( $admin_id, $payload['user_id'] );
        $this->assertEquals( $admin->user_email, $payload['user_email'] );
        $this->assertEquals( wp_parse_url( home_url(), PHP_URL_HOST ), $payload['iss'] );
        $this->assertEquals( 'gecx.cloud.google.com', $payload['aud'] );
        // The admin TTL is 3600 seconds and nothing downstream caps it, so the
        // token must not be able to outlive it.
        $this->assertEquals( $payload['iat'] + 3600, $payload['exp'] );
        $this->assertArrayNotHasKey( 'loyalty_tier', $payload );
    }

    public function test_admin_jwt_role_separation(): void {
        $this->ensure_user_with_id( 42, 'customer42', 'customer', 'customer@example.com' );
        wp_set_current_user( 42 );

        $cust_jwt = Auth::generate_customer_jwt();
        $this->assertNotNull( $cust_jwt );
        $cust_payload = $this->decode_jwt_payload( $cust_jwt );
        $this->assertFalse( $cust_payload['is_admin'] );

        // Customer user should be rejected from obtaining an admin JWT
        $non_admin_jwt = Auth::generate_admin_jwt( 42, 300, 'customer@example.com' );
        $this->assertNull( $non_admin_jwt );

        // Admin user should successfully obtain an admin JWT
        wp_set_current_user( 1 );
        $admin_jwt = Auth::generate_admin_jwt( 1, 300, 'admin@example.com' );
        $this->assertNotNull( $admin_jwt );
        $admin_payload = $this->decode_jwt_payload( $admin_jwt );
        $this->assertTrue( $admin_payload['is_admin'] );
    }

    public function test_get_or_generate_keypair_generates_valid_rsa_keypair(): void {
        delete_option( 'gecx_keypair' );
        delete_option( 'gecx_keypair' );
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );

        $keypair = Auth::get_or_generate_keypair();
        $this->assertNotNull( $keypair );
        $this->assertTrue( is_array( $keypair ) );
        $this->assertFalse( empty( $keypair['public_key'] ) );
        $this->assertFalse( empty( $keypair['private_key'] ) );
        $this->assertStringContainsString( 'BEGIN PUBLIC KEY', $keypair['public_key'] );
        $this->assertStringContainsString( 'BEGIN PRIVATE KEY', $keypair['private_key'] );

        // Both halves live in one option so that a write can never land half
        // applied. Nothing may be left behind in the pre-0.3.15 options.
        $record = get_option( 'gecx_keypair' );
        $this->assertTrue( is_array( $record ) );
        $this->assertEquals( 1, $record['version'] );
        $this->assertEquals( $keypair['public_key'], $record['public_key'] );
        $stored_priv = $record['private_key'];
        $this->assertTrue( is_array( $stored_priv ) );
        $this->assertEquals( 1, $stored_priv['version'] );
        $this->assertFalse( empty( $stored_priv['iv'] ) );
        $this->assertFalse( empty( $stored_priv['ciphertext'] ) );
        $this->assertFalse( empty( $stored_priv['tag'] ) );
        $this->assertFalse( get_option( 'gecx_public_key' ) );
        $this->assertFalse( get_option( 'gecx_private_key' ) );
    }

    public function test_encrypt_and_decrypt_private_key_roundtrip(): void {
        $keypair = Auth::get_or_generate_keypair();
        $this->assertNotNull( $keypair );

        $encrypted = Auth::encrypt_private_key( $keypair['private_key'] );
        $this->assertNotNull( $encrypted );
        $this->assertTrue( is_array( $encrypted ) );

        $decrypted = Auth::decrypt_private_key( $encrypted );
        $this->assertEquals( $keypair['private_key'], $decrypted );
    }

    public function test_get_public_key_and_private_key_persistence(): void {
        delete_option( 'gecx_keypair' );
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );

        $pub = Auth::get_public_key();
        $priv = Auth::get_private_key();

        $this->assertNotNull( $pub );
        $this->assertNotNull( $priv );
        $this->assertStringContainsString( 'BEGIN PUBLIC KEY', $pub );
        $this->assertStringContainsString( 'BEGIN PRIVATE KEY', $priv );

        // Second fetch returns identical persisted keys without regenerating
        $this->assertEquals( $pub, Auth::get_public_key() );
        $this->assertEquals( $priv, Auth::get_private_key() );
    }

    public function test_get_encryption_key_returns_null_when_salts_empty(): void {
        add_filter( 'salt', '__return_empty_string' );
        $this->assertNull( Auth::get_encryption_key() );
        $this->assertNull( Auth::encrypt_private_key( 'dummy_key' ) );
        $this->assertNull( Auth::decrypt_private_key( [
            'version'    => 1,
            'iv'         => base64_encode( '123456789012' ),
            'ciphertext' => base64_encode( 'ciphertext' ),
            'tag'        => base64_encode( '1234567890123456' ),
        ] ) );
    }

    public function test_decrypt_private_key_gcm_auth_failure_on_tampered_ciphertext(): void {
        $keypair   = Auth::get_or_generate_keypair();
        $encrypted = Auth::encrypt_private_key( $keypair['private_key'] );
        $this->assertNotNull( $encrypted );

        $raw_ciphertext    = base64_decode( $encrypted['ciphertext'] );
        $tampered_raw      = $raw_ciphertext ^ "\xFF";
        $encrypted['ciphertext'] = base64_encode( $tampered_raw );

        $this->assertNull( Auth::decrypt_private_key( $encrypted ) );
    }

    public function test_decrypt_private_key_gcm_auth_failure_on_tampered_tag(): void {
        $keypair   = Auth::get_or_generate_keypair();
        $encrypted = Auth::encrypt_private_key( $keypair['private_key'] );
        $this->assertNotNull( $encrypted );

        $raw_tag        = base64_decode( $encrypted['tag'] );
        $tampered_tag   = $raw_tag ^ "\x01";
        $encrypted['tag'] = base64_encode( $tampered_tag );

        $this->assertNull( Auth::decrypt_private_key( $encrypted ) );
    }

    public function test_decrypt_private_key_gcm_auth_failure_on_tampered_iv(): void {
        $keypair   = Auth::get_or_generate_keypair();
        $encrypted = Auth::encrypt_private_key( $keypair['private_key'] );
        $this->assertNotNull( $encrypted );

        $raw_iv        = base64_decode( $encrypted['iv'] );
        $tampered_iv   = $raw_iv ^ "\x02";
        $encrypted['iv'] = base64_encode( $tampered_iv );

        $this->assertNull( Auth::decrypt_private_key( $encrypted ) );
    }

    public function test_decrypt_private_key_rejects_invalid_array_structure(): void {
        $this->assertNull( Auth::decrypt_private_key( [] ) );
        $this->assertNull( Auth::decrypt_private_key( [ 'iv' => 'abc' ] ) );
        $this->assertNull( Auth::decrypt_private_key( [ 'iv' => '', 'ciphertext' => 'abc', 'tag' => 'def' ] ) );
        $this->assertNull( Auth::decrypt_private_key( [ 'iv' => 123, 'ciphertext' => 'abc', 'tag' => 'def' ] ) );
    }

    public function test_salt_rotation_replaces_the_unreadable_keypair(): void {
        delete_option( 'gecx_keypair' );

        $keypair         = Auth::get_or_generate_keypair();
        $original_pub    = $keypair['public_key'];
        $original_record = get_option( 'gecx_keypair' );

        // Simulate salt rotation: changing the salt causes decryption to fail.
        add_filter( 'salt', static fn() => 'new_rotated_salt' );

        // Nothing can recover the old private key, so the store has to be given
        // a working one instead of being left unable to sign anything.
        $recovered = Auth::get_or_generate_keypair();
        $this->assertNotNull( $recovered );
        $this->assertFalse( empty( $recovered['public_key'] ) );
        $this->assertFalse( empty( $recovered['private_key'] ) );
        $this->assertTrue( null !== Auth::get_private_key() );
        $this->assertTrue( null !== Auth::get_public_key() );

        // The dead keypair is gone, replaced rather than kept alongside.
        $new_record = get_option( 'gecx_keypair' );
        $this->assertTrue( is_array( $new_record ) );
        $this->assertTrue( $original_pub !== $new_record['public_key'] );
        $this->assertTrue( $original_record !== $new_record );
    }

    public function test_desynchronized_public_or_private_key_is_regenerated(): void {
        delete_option( 'gecx_keypair' );
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );

        // Case 1: Only public key exists. The matching private key cannot be
        // derived from it, so the orphan is discarded and a pair is generated.
        update_option( 'gecx_public_key', '-----BEGIN PUBLIC KEY-----\nMIIB...\n-----END PUBLIC KEY-----' );
        $keypair = Auth::get_or_generate_keypair();
        $this->assertNotNull( $keypair );
        $this->assertFalse( empty( $keypair['private_key'] ) );
        $this->assertTrue( false === strpos( (string) get_option( 'gecx_public_key' ), 'MIIB...' ) );

        // Case 2: Only private key exists.
        delete_option( 'gecx_keypair' );
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );
        update_option( 'gecx_private_key', [ 'version' => 1, 'iv' => 'abc', 'ciphertext' => 'def', 'tag' => 'ghi' ] );
        $keypair = Auth::get_or_generate_keypair();
        $this->assertNotNull( $keypair );
        $this->assertFalse( empty( $keypair['public_key'] ) );
        $this->assertTrue( null !== Auth::get_private_key() );
    }

    public function test_pre_0315_two_option_keypair_is_folded_into_one_record(): void {
        // Produce a real pair, then put the store back into the old layout.
        $original = Auth::get_or_generate_keypair();
        $this->assertNotNull( $original );
        $encrypted = Auth::encrypt_private_key( $original['private_key'] );
        delete_option( 'gecx_keypair' );
        update_option( 'gecx_public_key', $original['public_key'] );
        update_option( 'gecx_private_key', $encrypted );

        $read = Auth::get_or_generate_keypair();

        // The existing key is adopted, not thrown away: regenerating here would
        // invalidate every signature the agent has already been handed.
        $this->assertEquals( $original['public_key'], $read['public_key'] );
        $this->assertEquals( $original['private_key'], $read['private_key'] );

        $record = get_option( 'gecx_keypair' );
        $this->assertTrue( is_array( $record ) );
        $this->assertEquals( $original['public_key'], $record['public_key'] );
        $this->assertEquals( $encrypted, $record['private_key'] );

        // The old layout is cleared so there is exactly one source of truth.
        $this->assertFalse( get_option( 'gecx_public_key' ) );
        $this->assertFalse( get_option( 'gecx_private_key' ) );
    }

    public function test_migrated_keypair_still_signs_and_verifies(): void {
        $original = Auth::get_or_generate_keypair();
        $encrypted = Auth::encrypt_private_key( $original['private_key'] );
        delete_option( 'gecx_keypair' );
        update_option( 'gecx_public_key', $original['public_key'] );
        update_option( 'gecx_private_key', $encrypted );

        $private_key = Auth::get_private_key();
        $public_key  = Auth::get_public_key();
        $this->assertNotNull( $private_key );
        $this->assertNotNull( $public_key );

        $signature = '';
        $this->assertTrue( openssl_sign( 'payload', $signature, $private_key, OPENSSL_ALGO_SHA256 ) );
        $this->assertEquals( 1, openssl_verify( 'payload', $signature, $public_key, OPENSSL_ALGO_SHA256 ) );
    }

    public function test_unreadable_record_does_not_resurrect_the_legacy_keypair(): void {
        $superseded = Auth::get_or_generate_keypair();
        $this->assertNotNull( $superseded );
        $superseded_encrypted = Auth::encrypt_private_key( $superseded['private_key'] );

        // A store mid-way through an interrupted migration: the current record
        // is unreadable and the pair it replaced is still sitting there.
        update_option(
            'gecx_keypair',
            [
                'version'     => 1,
                'public_key'  => '-----BEGIN PUBLIC KEY-----\nCURRENT\n-----END PUBLIC KEY-----',
                'private_key' => [ 'version' => 1, 'iv' => 'abc', 'ciphertext' => 'def', 'tag' => 'ghi' ],
            ]
        );
        update_option( 'gecx_public_key', $superseded['public_key'] );
        update_option( 'gecx_private_key', $superseded_encrypted );

        $keypair = Auth::get_or_generate_keypair();

        // Adopting the old pair would silently roll the store back to a key the
        // agent was already told to stop trusting, so a fresh one is generated.
        $this->assertNotNull( $keypair );
        $this->assertTrue( $superseded['public_key'] !== $keypair['public_key'] );
        $this->assertTrue( false === strpos( $keypair['public_key'], 'CURRENT' ) );
        $this->assertFalse( get_option( 'gecx_public_key' ) );
        $this->assertFalse( get_option( 'gecx_private_key' ) );
    }

    public function test_stale_lock_is_broken_and_allows_generation(): void {
        delete_option( 'gecx_keypair' );
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );

        // Simulate stale lock created 60 seconds ago
        update_option( 'gecx_keypair_lock', time() - 60 );

        $keypair = Auth::get_or_generate_keypair();
        $this->assertNotNull( $keypair );
        $this->assertTrue( is_array( $keypair ) );
        $this->assertFalse( empty( $keypair['public_key'] ) );
        $this->assertFalse( empty( $keypair['private_key'] ) );
        $this->assertFalse( get_option( 'gecx_keypair_lock' ) );
    }

    public function test_encryption_key_is_hkdf_sha256_over_the_salts(): void {
        add_filter( 'salt', static fn() => 'a_salt' );

        $this->assertEquals(
            hash_hkdf( 'sha256', 'a_salt', 32, 'gecx_private_key_encryption', 'a_salt' ),
            Auth::get_encryption_key()
        );
        $this->assertEquals( 32, strlen( (string) Auth::get_encryption_key() ) );
    }

    public function test_route_prefix_mid_path_and_double_slash_host_rejected(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );

        // Prefix appearing mid-path
        $_SERVER['REQUEST_URI'] = '/other/wp-json/wc/store/v1/cart';
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );

        // Leading double-slash protocol-relative / host confusion
        $_SERVER['REQUEST_URI'] = '//evil.com/wp-json/wc/store/v1/cart';
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    public function test_block_cart_token_off_store_api_with_non_request_returns_forbidden(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $this->auth->authenticate_via_cart_token( 0 );

        $result = $this->auth->block_cart_token_off_store_api( null, [], null );
        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'rest_forbidden', $result->get_error_code() );
        $this->assertSame( 'Sorry, you are not allowed to do that.', $result->get_error_message() );
        $this->assertSame( 403, $result->get_error_data()['status'] );
    }

    public function test_cart_token_rejected_when_query_vars_rest_route_is_an_array(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $GLOBALS['wp']              = new stdClass();
        $GLOBALS['wp']->query_vars  = [ 'rest_route' => [ '/wc/store/v1/cart' ] ];

        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    public function test_non_integral_and_invalid_exp_claims_are_rejected(): void {
        $invalid_exps = [
            '1.5e9',
            '1e9',
            [ 'nested' ],
            null,
            (object) [ 'exp' => time() + 3600 ],
            time() - 1,
            time(), // Expired at exact second
        ];

        foreach ( $invalid_exps as $exp ) {
            $payload = [
                'sub' => 456,
                'iss' => 'woocommerce/store-api',
                'exp' => $exp,
            ];
            $_SERVER['HTTP_CART_TOKEN'] = $this->mint_token_with_payload( $payload );
            $this->assertSame(
                0,
                $this->auth->authenticate_via_cart_token( 0 ),
                'Failed asserting rejection for exp: ' . var_export( $exp, true )
            );
        }
    }

    public function test_reset_cart_token_state_clears_flag(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );
        $this->assertTrue( Auth::is_cart_token_request() );

        Auth::reset_cart_token_state();
        $this->assertFalse( Auth::is_cart_token_request() );
    }

    public function test_non_front_controller_index_php_is_rejected(): void {
        $_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'wp-content/plugins/gecx/index.php';
        $_SERVER['REQUEST_URI']     = '/wp-json/wc/store/v1/cart';
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );

        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    public function test_reentrant_user_has_cap_filter_does_not_loop(): void {
        add_filter(
            'user_has_cap',
            function ( $allcaps ) {
                wp_get_current_user();
                return $allcaps;
            }
        );

        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 456 );
        $this->assertSame( 456, $this->auth->authenticate_via_cart_token( 0 ) );
    }

    public function test_unknown_issuer_logging_is_throttled_by_transient(): void {
        $payload = [
            'sub' => 456,
            'iss' => 'https://unknown.issuer.example.com',
            'exp' => time() + 3600,
        ];
        $token = $this->mint_token_with_payload( $payload );

        // First verification: fires action and sets throttling transient
        $this->unknown_issuer_reports = [];
        $_SERVER['HTTP_CART_TOKEN']   = $token;
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );

        $this->assertCount( 1, $this->unknown_issuer_reports );
        $this->assertTrue( ! empty( get_transient( 'gecx_unknown_iss_' . md5( 'https://unknown.issuer.example.com' ) ) ) );

        // Second verification: fires action, but error log is throttled by transient
        $this->unknown_issuer_reports = [];
        $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ) );

        $this->assertCount( 1, $this->unknown_issuer_reports );
    }

    /**
     * Cart-Token authentication must only resolve WordPress user identity on
     * cart and batch routes (/wc/store/v1/cart, /wc/store/v1/cart/*, /wc/store/v1/batch).
     * Order, checkout, and catalog endpoints must remain anonymous so a stolen
     * or guest cart token cannot enumerate orders or bypass guest order verification.
     */
    public function test_cart_token_only_authenticates_cart_and_batch_routes(): void {
        $_SERVER['HTTP_CART_TOKEN'] = $this->generate_jwt( 123 );

        $allowed = [
            '/wp-json/wc/store/v1/cart',
            '/wp-json/wc/store/v1/cart/add-item',
            '/wp-json/wc/store/v1/cart/update-item',
            '/wp-json/wc/store/v1/cart/remove-item',
            '/wp-json/wc/store/v1/batch',
        ];
        foreach ( $allowed as $uri ) {
            Auth::reset_cart_token_state();
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertSame( 123, $this->auth->authenticate_via_cart_token( 0 ), 'Expected acceptance on ' . $uri );
        }

        $rejected = [
            '/wp-json/wc/store/v1/order/999',
            '/wp-json/wc/store/v1/checkout',
            '/wp-json/wc/store/v1/checkout/999',
            '/wp-json/wc/store/v1/products',
            '/wp-json/wc/store/v1/products/42',
            '/wp-json/wc/store/v1/cart-extensions',
        ];
        foreach ( $rejected as $uri ) {
            Auth::reset_cart_token_state();
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertSame( 0, $this->auth->authenticate_via_cart_token( 0 ), 'Expected rejection on ' . $uri );
        }

        // Verify that if a top-level /wc/store/v1/batch request authenticates
        // via Cart-Token, any sub-request dispatched to /order/* or /checkout/*
        // is blocked with 403 by block_cart_token_off_store_api().
        Auth::reset_cart_token_state();
        $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/batch';
        $this->assertSame( 123, $this->auth->authenticate_via_cart_token( 0 ) );

        foreach ( [ '/wc/store/v1/order/999', '/wc/store/v1/checkout', '/wc/store/v1/checkout/999' ] as $sub_route ) {
            $sub_request = new WP_REST_Request();
            $sub_request->set_route( $sub_route );
            $dispatch_result = $this->auth->block_cart_token_off_store_api( null, null, $sub_request );
            $this->assertInstanceOf( WP_Error::class, $dispatch_result, 'Expected 403 block on sub-request ' . $sub_route );
            $this->assertSame( 'rest_forbidden', $dispatch_result->get_error_code() );
        }
    }

    /**
     * @dataProvider keypair_lock_liveness_cases
     *
     * @param int|null $lock_age_seconds How long ago the lock was taken, null for no lock,
     *                                   or a negative value for a corrupt zero timestamp.
     */
    public function test_keypair_lock_holder_is_live( ?int $lock_age_seconds, bool $expected, string $why ): void {
        if ( null === $lock_age_seconds ) {
            delete_option( 'gecx_keypair_lock' );
        } elseif ( $lock_age_seconds < 0 ) {
            update_option( 'gecx_keypair_lock', 0 );
        } else {
            // Resolved here rather than in the provider: providers run before
            // the suite does, so a timestamp built there would have aged by the
            // time this assertion runs and a case one second inside the TTL
            // would drift outside it.
            update_option( 'gecx_keypair_lock', time() - $lock_age_seconds );
        }

        $method = new ReflectionMethod( Auth::class, 'keypair_lock_holder_is_live' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }

        $this->assertSame( $expected, $method->invoke( null ), $why );
    }

    public function keypair_lock_liveness_cases(): array {
        return [
            'no lock at all' => [
                null,
                false,
                'Nothing holds the lock, so there is nobody to wait for.',
            ],
            'lock taken just now' => [
                0,
                true,
                'A fresh holder is still plausibly generating a keypair.',
            ],
            'lock taken one second inside the TTL' => [
                29,
                true,
                'Still inside the window acquire_keypair_lock() honours.',
            ],
            'lock older than the TTL' => [
                31,
                false,
                'Past the TTL the holder is treated as dead, and acquire_keypair_lock() would reclaim it.',
            ],
            'lock with a corrupt timestamp' => [
                -1,
                false,
                'A zero timestamp cannot be aged, so it must not hold waiters for the full two seconds.',
            ],
        ];
    }

    /**
     * The wait is a ceiling, not a cost. Both callers are reachable from
     * shopper traffic and the loop is a blocking usleep(), so a waiter must not
     * sit through the whole thing once the lock says nobody is coming.
     */
    public function test_wait_for_concurrent_keypair_gives_up_when_no_holder_is_coming(): void {
        delete_option( 'gecx_keypair' );
        delete_option( 'gecx_public_key' );
        delete_option( 'gecx_private_key' );

        // A lock row that exists, so acquire_keypair_lock() cannot take it, but
        // whose timestamp is unusable, so no holder can be inferred from it.
        update_option( 'gecx_keypair_lock', 0 );

        $method = new ReflectionMethod( Auth::class, 'wait_for_concurrent_keypair' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }

        $started = microtime( true );
        $result  = $method->invoke( null );
        $elapsed = microtime( true ) - $started;

        $this->assertNull( $result, 'No keypair was published, so the wait has nothing to return.' );
        $this->assertLessThan(
            1.0,
            $elapsed,
            sprintf( 'Expected the wait to abandon after roughly one poll, but it took %.3fs of the 2s ceiling.', $elapsed )
        );
    }

    /**
     * The converse: a live holder still gets waited on, and the keypair it
     * publishes is picked up rather than regenerated.
     */
    public function test_wait_for_concurrent_keypair_returns_a_keypair_published_under_a_live_lock(): void {
        update_option( 'gecx_keypair_lock', time() );

        $generate = new ReflectionMethod( Auth::class, 'generate_and_store_keypair' );
        if ( PHP_VERSION_ID < 80100 ) {
            $generate->setAccessible( true );
        }
        $published = $generate->invoke( null );
        $this->assertNotNull( $published, 'Test setup failed: could not generate a keypair to publish.' );

        $method = new ReflectionMethod( Auth::class, 'wait_for_concurrent_keypair' );
        if ( PHP_VERSION_ID < 80100 ) {
            $method->setAccessible( true );
        }
        $result = $method->invoke( null );

        $this->assertNotNull( $result );
        $this->assertSame( $published['public_key'], $result['public_key'] );
    }

    public function test_generate_existing_rs256_admin_jwt_falls_back_to_lowest_id_administrator_in_cli_context(): void {
        Auth::get_or_generate_keypair();
        wp_set_current_user( 0 );

        $admins = get_users(
            [
                'role'    => 'administrator',
                'number'  => 1,
                'orderby' => 'ID',
                'order'   => 'ASC',
            ]
        );
        $first_admin    = reset( $admins );
        $first_admin_id = (int) ( is_object( $first_admin ) ? $first_admin->ID : $first_admin );
        $first_user     = get_userdata( $first_admin_id );

        if ( ! defined( 'WP_CLI' ) && ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            $this->assertNull( Auth::generate_existing_rs256_admin_jwt() );
        }

        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }

        $jwt = Auth::generate_existing_rs256_admin_jwt();
        $this->assertNotNull( $jwt );

        $parts   = explode( '.', $jwt );
        $payload = json_decode( $this->base64_url_decode( $parts[1] ), true );
        $this->assertSame( $first_admin_id, $payload['user_id'] );
        $this->assertSame( $first_user->user_email, $payload['user_email'] );
        $this->assertTrue( $payload['is_admin'] );
    }

    public function test_generate_jwt_caches_guest_token(): void {
        wp_set_current_user( 0 );
        delete_transient( Auth::GUEST_JWT_CACHE_TRANSIENT );

        $first  = Auth::generate_customer_jwt();
        $second = Auth::generate_customer_jwt();

        $this->assertNotNull( $first );
        $this->assertSame( $first, $second );
        $cached = get_transient( Auth::GUEST_JWT_CACHE_TRANSIENT );
        $this->assertIsArray( $cached );
        $this->assertSame( $first, $cached['jwt'] );
    }
}
