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
 * Gemini Enterprise for CX shopper auth context
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Gives the storefront widget a REST nonce and a signed customer JWT (the
 * auth-context and refresh-token routes), only for same-origin requests.
 */
class GECX_Rest_Auth_Context {

    /**
     * Registers this component's hooks.
     */
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_refresh_token_rest_route' ] );
        add_action( 'rest_api_init', [ $this, 'register_auth_context_rest_route' ] );
        add_filter( 'rest_pre_serve_request', [ $this, 'suppress_cors_on_auth_context' ], 20, 4 );
    }

    /**
     * Register API Route to silently refresh customer JWT.
     */
    public function register_refresh_token_rest_route(): void {
        register_rest_route( 'gecx/v1', '/refresh-token', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'refresh_token_handler' ],
            'permission_callback' => [ 'GECX_Rest_Session_Attribution', 'check_session_permissions' ],
        ] );
    }

    /**
     * Handle the POST request to refresh customer JWT for active session.
     *
     * A missing secret is a 500 here, rather than a 200 carrying a null token
     * as on /gecx/v1/auth-context. Minting the JWT is the entire purpose of
     * this route, so there is no partial success to report; auth-context also
     * returns the nonce, which stays useful to the widget whether or not a JWT
     * could be signed.
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP_REST_Server route callback signature.
    public function refresh_token_handler( \WP_REST_Request $request ) {
        $customer_jwt = GECX_Auth::generate_customer_jwt();
        if ( empty( $customer_jwt ) ) {
            return new \WP_Error( 'jwt_generation_failed', __( 'Unable to generate customer JWT; secret is missing.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
        }
        return new \WP_REST_Response( [
            'success'      => true,
            'customer_jwt' => $customer_jwt,
        ], 200 );
    }

    /**
     * Register API Route to fetch fresh, dynamic auth context (nonce and customer JWT)
     * without caching.
     *
     * POST only. Response headers say no-store, but a CDN configured to "cache
     * everything" can still serve a GET response from the edge and hand one
     * shopper's nonce and JWT to another; POST is not cached by such rules. GET
     * was previously registered as well, solely so widget bundles predating the
     * switch to POST kept working. Those are no longer deployed, so the
     * cacheable method is gone.
     */
    public function register_auth_context_rest_route(): void {
        register_rest_route( 'gecx/v1', '/auth-context', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'auth_context_handler' ],
            'permission_callback' => [ $this, 'check_auth_context_permissions' ],
        ] );
    }

    /**
     * Enforces strict same-origin isolation on /gecx/v1/auth-context.
     *
     * Because this endpoint resolves the logged-in user from the WordPress
     * logged_in cookie without a prior X-WP-Nonce (in order to bootstrap the
     * nonce on cached storefront pages), cross-origin and cross-site reads
     * must be rejected so another origin cannot read the user's nonce or JWT.
     *
     * A request has to positively identify itself as same-origin. Fetch
     * Metadata, Origin and Referer are each consulted, and a request carrying
     * none of the three is refused rather than trusted: a cross-origin
     * `<script src>` under `Referrer-Policy: no-referrer` arrives with all
     * three absent, and so does a non-browser client replaying a stolen
     * cookie. Browsers too old to send Fetch Metadata still send Referer,
     * unless the store suppresses it site-wide.
     *
     * @param \WP_REST_Request $request REST request instance.
     * @return true|\WP_Error
     */
    public function check_auth_context_permissions( \WP_REST_Request $request ) {
        if ( GECX_Auth::is_cart_token_request() ) {
            return new \WP_Error( 'rest_forbidden', __( 'Unauthorized.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        $fetch_site = self::read_request_header( $request, 'Sec-Fetch-Site', 'HTTP_SEC_FETCH_SITE' );
        $origin     = self::read_request_header( $request, 'Origin', 'HTTP_ORIGIN' );
        $referer    = self::read_request_header( $request, 'Referer', 'HTTP_REFERER' );

        if ( '' === $fetch_site && '' === $origin && '' === $referer ) {
            return new \WP_Error( 'rest_forbidden', __( 'Request origin could not be verified.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        if ( '' !== $fetch_site ) {
            // Only same-origin is accepted. "none" means a user-initiated load
            // with no initiator document (address bar, bookmark); a browser
            // cannot produce one for a POST, so the sole remaining sender of
            // "none" here is a non-browser client setting the header itself
            // around a stolen cookie. It was accepted while GET was still
            // registered and is not accepted now.
            if ( 'same-origin' !== strtolower( trim( $fetch_site ) ) ) {
                return new \WP_Error( 'rest_forbidden', __( 'Cross-site requests are not permitted.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
            }
        }

        if ( '' !== $origin && ! self::is_same_origin( $origin ) ) {
            return new \WP_Error( 'rest_forbidden', __( 'Cross-origin requests are not permitted.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        // On multisite or subdirectory installs, Origin and Sec-Fetch-Site only
        // prove same-host/port, not same-subsite path (e.g. /site-b/ vs /site-a/).
        // Whenever Referer is present it must match the site path prefix, and on
        // multisite subdirectory stores Referer is required so a sibling subsite
        // cannot read this site's nonce or JWT.
        if ( '' !== $referer && ! self::is_same_origin( $referer, true ) ) {
            return new \WP_Error( 'rest_forbidden', __( 'Cross-origin requests are not permitted.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        if ( '' === $referer && self::is_multisite_subdirectory_install() ) {
            return new \WP_Error( 'rest_forbidden', __( 'Subdirectory multisite requests must include a same-site Referer.', 'gemini-enterprise-for-cx' ), [ 'status' => 403 ] );
        }

        return true;
    }

    /**
     * Whether the current site is a multisite install with a non-root home_url()
     * path (or running on multisite where sibling sites may share the same host).
     */
    private static function is_multisite_subdirectory_install(): bool {
        if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
            return false;
        }
        if ( defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL ) {
            return false;
        }
        return true;
    }

    /**
     * Whether the request's Referer pins it to one specific blog.
     *
     * On a subdirectory multisite every blog shares a host, so only the Referer
     * path separates them. `Referrer-Policy: strict-origin-when-cross-origin`
     * (the browser default) sends a bare `https://example.com/` for a
     * same-origin fetch from the root blog's own homepage, and a sibling subsite
     * asking for `referrerPolicy: 'origin'` produces the byte-identical header.
     * The two cannot be told apart, so the caller must not read a logged-in
     * identity out of such a request; `auth_context_handler()` downgrades it to
     * guest instead of refusing it, which keeps the root homepage working while
     * capping what a sibling subsite can obtain at a guest nonce.
     *
     * @param \WP_REST_Request $request REST request instance.
     * @return bool True when the blog is unambiguous.
     */
    private static function referer_identifies_current_site( \WP_REST_Request $request ): bool {
        if ( ! self::is_multisite_subdirectory_install() ) {
            return true;
        }

        $referer = self::read_request_header( $request, 'Referer', 'HTTP_REFERER' );
        if ( '' === $referer ) {
            return false;
        }

        $candidate = self::parse_origin( $referer );
        return null !== $candidate && '' !== $candidate['path'];
    }

    /**
     * Reads a request header, falling back to the raw $_SERVER entry.
     *
     * @param \WP_REST_Request $request     REST request instance.
     * @param string           $header_name Header name as sent on the wire.
     * @param string           $server_key  Matching $_SERVER key.
     * @return string Sanitized header value, or '' when the header is absent.
     */
    private static function read_request_header( \WP_REST_Request $request, string $header_name, string $server_key ): string {
        $value = (string) $request->get_header( $header_name );
        if ( '' === $value && isset( $_SERVER[ $server_key ] ) ) {
            // Not sanitized, so that this fallback answers with the same bytes
            // the get_header() path above returns. The callers parse the value
            // as a URL and compare host and port; sanitize_text_field() strips
            // percent-octets, which would let a header that is not same-origin
            // be reduced to one that is.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed as a URL by is_same_origin(), never output. See above.
            $value = (string) wp_unslash( $_SERVER[ $server_key ] );
        }
        return $value;
    }

    /**
     * Checks whether a candidate URL (Origin or Referer) addresses this store.
     *
     * Host and port are compared against both home_url() and site_url(),
     * which differ on the common install where WordPress itself lives in a
     * subdirectory of the storefront. When $check_path is true (used for
     * Referer headers), the candidate path must also fall under the allowed
     * origin's path prefix at a `/` boundary and, on multisite installs,
     * resolve via `get_site_by_path()` to `get_current_blog_id()` so a sibling
     * subsite at `https://example.com/site-b/` is rejected by both
     * `https://example.com/` (root) and `https://example.com/site-a/`.
     *
     * Scheme is deliberately not compared. Behind a TLS-terminating proxy or
     * a CDN doing flexible SSL, home_url() is routinely stored as http while
     * the browser reports an https Origin, and refusing that pairing takes the
     * widget offline on stores that are otherwise healthy. An http Origin on
     * the store's own host implies an active network attacker, who already
     * holds the cookie this check exists to protect. Schemes other than http
     * and https are still refused outright.
     *
     * @param string $candidate_url Candidate Origin or Referer URL.
     * @param bool   $check_path    Whether to require path prefix matching against the allowed URLs.
     * @return bool
     */
    private static function is_same_origin( string $candidate_url, bool $check_path = false ): bool {
        $candidate = self::parse_origin( $candidate_url );
        if ( null === $candidate ) {
            return false;
        }

        foreach ( self::get_allowed_origins() as $allowed ) {
            if ( $allowed['host'] !== $candidate['host'] || $allowed['port'] !== $candidate['port'] ) {
                continue;
            }

            if ( $check_path ) {
                $allowed_path   = $allowed['path'];
                $candidate_path = $candidate['path'];

                if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_by_path' ) && function_exists( 'get_current_blog_id' ) ) {
                    $lookup_path   = '' !== $candidate_path ? $candidate_path . '/' : '/';
                    $resolved_site = get_site_by_path( $candidate['host'], $lookup_path );
                    if ( ! is_object( $resolved_site ) || ! isset( $resolved_site->blog_id ) || (int) get_current_blog_id() !== (int) $resolved_site->blog_id ) {
                        continue;
                    }
                }

                if ( '' !== $allowed_path ) {
                    // An origin-only Referer (e.g. https://example.com/) has
                    // candidate_path === '' because Referrer-Policy: origin or
                    // strict-origin stripped the path. On single-site installs
                    // that still identifies the same origin; on multisite
                    // subdirectory installs the path must match the subsite.
                    if ( '' === $candidate_path && ! self::is_multisite_subdirectory_install() ) {
                        return true;
                    }
                    if ( $candidate_path !== $allowed_path && 0 !== strpos( $candidate_path, $allowed_path . '/' ) ) {
                        continue;
                    }
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Reduces a URL to its host, port, and normalized path prefix, collapsing
     * the scheme's default port to null so that https://example.com and
     * https://example.com:443 compare equal.
     *
     * @param string $url Candidate URL.
     * @return array|null Array with 'host', 'port', and 'path' keys, or null when the URL is unusable.
     */
    private static function parse_origin( string $url ): ?array {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return null;
        }

        $scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
        if ( '' !== $scheme && 'http' !== $scheme && 'https' !== $scheme ) {
            return null;
        }

        $port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
        if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
            $port = null;
        }

        $raw_path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
        $trimmed  = trim( $raw_path, '/' );
        $path     = '' !== $trimmed ? '/' . $trimmed : '';

        return [
            'host' => strtolower( (string) $parts['host'] ),
            'port' => $port,
            'path' => $path,
        ];
    }

    /**
     * Builds the set of origins permitted to read /gecx/v1/auth-context.
     *
     * @return array List of arrays with 'host' and 'port' keys.
     */
    private static function get_allowed_origins(): array {
        $urls = [];
        if ( function_exists( 'home_url' ) ) {
            $urls[] = (string) home_url();
        }
        if ( function_exists( 'site_url' ) ) {
            $urls[] = (string) site_url();
        }

        /**
         * Filters the URLs whose host and port may read the auth context.
         *
         * A store that serves the storefront from a domain WordPress does not
         * know about, such as a headless front end or a mapped domain, adds it
         * here.
         *
         * @param array $urls Allowed URLs.
         */
        if ( function_exists( 'apply_filters' ) ) {
            $urls = (array) apply_filters( 'gecx_auth_context_allowed_origins', $urls );
        }

        $allowed = [];
        foreach ( $urls as $url ) {
            $parsed = self::parse_origin( (string) $url );
            if ( null !== $parsed ) {
                $allowed[] = $parsed;
            }
        }

        return $allowed;
    }

    /**
     * Strips WordPress REST API CORS reflection headers on /gecx/v1/auth-context
     * so cross-origin documents cannot read the response even if preflighted.
     *
     * @param bool             $served  Whether the request has already been served.
     * @param \WP_HTTP_Response $result  Result to send to the client.
     * @param \WP_REST_Request  $request Request used to generate the response.
     * @param \WP_REST_Server   $server  Server instance.
     * @return bool
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- rest_pre_serve_request filter signature.
    public function suppress_cors_on_auth_context( $served, $result, $request, $server ) {
        if ( $request instanceof \WP_REST_Request && '/gecx/v1/auth-context' === $request->get_route() ) {
            if ( function_exists( 'header_remove' ) && ( ! function_exists( 'headers_sent' ) || ! headers_sent() ) ) {
                header_remove( 'Access-Control-Allow-Origin' );
                header_remove( 'Access-Control-Allow-Credentials' );
            }
        }
        return $served;
    }

    /**
     * Handle the GET request for dynamic auth context.
     *
     * WordPress's rest_cookie_check_errors() resets the active user to 0
     * when a REST request arrives without an X-WP-Nonce header. Because this
     * endpoint is what mints the fresh wp_rest nonce and customer JWT after a
     * cached page load, it resolves the logged-in user ID from the WordPress
     * logged_in cookie (after check_auth_context_permissions() has verified
     * same-origin isolation), supplies that user ID to wp_create_nonce() via
     * the core nonce_user_logged_out filter and directly to
     * GECX_Auth::generate_customer_jwt(), and never mutates global user state.
     *
     * The response always carries a nonce and a 'customer_jwt' key. That key
     * is null when no signing secret is configured, which is a 200 rather than
     * an error: the nonce alone still lets the widget reach the Store API, so
     * an unsigned store degrades to an anonymous shopper instead of a broken
     * widget. Callers must treat null as "no identity", never as a failure to
     * retry.
     *
     * @param \WP_REST_Request $request REST request instance.
     * @return \WP_REST_Response
     */
    public function auth_context_handler( \WP_REST_Request $request ): \WP_REST_Response {
        $current_user_id   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        $effective_user_id = $current_user_id;
        $nonce_user_filter = null;

        if ( 0 === $effective_user_id && function_exists( 'wp_validate_auth_cookie' ) ) {
            $cookie_user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );
            if ( $cookie_user_id > 0 ) {
                $effective_user_id = $cookie_user_id;
            }
        }

        // On multisite installs, the WordPress logged_in cookie is scoped to
        // the network root (`COOKIEPATH`), so a user authenticated on sibling
        // subsite B sends a valid cookie to subsite A. Require explicit blog
        // membership on the current blog before minting a user-bound nonce or
        // customer JWT; non-member network users are downgraded to guest (0).
        if ( $effective_user_id > 0 && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_user_member_of_blog' ) ) {
            $blog_id     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
            $is_super    = function_exists( 'is_super_admin' ) && is_super_admin( $effective_user_id );
            $is_member   = (bool) is_user_member_of_blog( $effective_user_id, $blog_id );
            if ( ! $is_member && ! $is_super ) {
                $effective_user_id = 0;
            }
        }

        // An origin-only Referer on a subdirectory multisite names the host but
        // no blog, so the root blog's own homepage and a sibling subsite asking
        // for `referrerPolicy: 'origin'` are indistinguishable. Serve a guest
        // nonce rather than a 403: the homepage keeps working for shoppers, and
        // the most a sibling subsite can extract is the guest identity it could
        // already mint for itself.
        if ( $effective_user_id > 0 && ! self::referer_identifies_current_site( $request ) ) {
            $effective_user_id = 0;
        }

        $temporarily_cleared_user = false;
        if ( 0 === $current_user_id && $effective_user_id > 0 && function_exists( 'add_filter' ) ) {
            $bound_user_id     = $effective_user_id;
            $nonce_user_filter = static function ( $uid, $action = -1 ) use ( $bound_user_id ) {
                return 'wp_rest' === (string) $action ? $bound_user_id : $uid;
            };
            add_filter( 'nonce_user_logged_out', $nonce_user_filter, 999, 2 );
        } elseif ( $current_user_id > 0 && 0 === $effective_user_id && function_exists( 'wp_set_current_user' ) ) {
            // phpcs:ignore Generic.PHP.ForbiddenFunctions.Discouraged, Generic.PHP.ForbiddenFunctions.Found -- Temporarily isolate non-member or untrusted multisite user as guest so wp_create_nonce() mints a logged-out guest nonce.
            wp_set_current_user( 0 );
            $temporarily_cleared_user = true;
        }

        try {
            if ( function_exists( 'nocache_headers' ) ) {
                nocache_headers();
            }

            $nonce        = function_exists( 'wp_create_nonce' ) ? (string) wp_create_nonce( 'wp_rest' ) : '';
            $customer_jwt = GECX_Auth::generate_customer_jwt( $effective_user_id );
        } finally {
            if ( null !== $nonce_user_filter && function_exists( 'remove_filter' ) ) {
                remove_filter( 'nonce_user_logged_out', $nonce_user_filter, 999 );
            }
            if ( $temporarily_cleared_user && function_exists( 'wp_set_current_user' ) ) {
                // phpcs:ignore Generic.PHP.ForbiddenFunctions.Discouraged, Generic.PHP.ForbiddenFunctions.Found -- Restore previously active user context after minting guest nonce.
                wp_set_current_user( $current_user_id );
            }
        }

        $response = new \WP_REST_Response( [
            'success'      => true,
            'nonce'        => $nonce,
            'customer_jwt' => $customer_jwt,
        ], 200 );

        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        $response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
        $response->header( 'Vary', 'Cookie, Origin' );

        return $response;
    }
}
