#!/usr/bin/env bash
#
# Copyright 2026 Google LLC
#
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Runtime integration tests executed against a real WordPress + WooCommerce
# install provisioned by @wordpress/env. These complement the stub suite in
# tests/, which cannot see anything that depends on WordPress actually
# resolving a route, issuing a cookie or writing a row.
#
# Covers:
#   1. Activation against the real WP/WC pair under test
#   2. REST route registration and permission callbacks over HTTP
#   3. Cart-Token authentication hooks and Store API scoping
#   4. Webhook lifecycle through WooCommerce's own WC_Webhook
#   5. WooCommerce session cookies the plugin sets and checks
#   6. Binding the browser to the agent's cart over HTTP
#   7. The uninstall sweep, run the way WordPress runs it
#

set -euo pipefail

readonly PLUGIN_SLUG='gemini-enterprise-for-cx'
readonly SITE_URL='http://localhost:8888'

# wp-env prints its own banner on stdout, so nothing here parses command
# output; every assertion is an exit status or an explicit marker echoed by
# the PHP under test.
wp() {
  npx --yes @wordpress/env run cli wp "$@"
}

# Asserts an HTTP status, printing the body when the assertion fails so the
# job log explains itself without a re-run.
assert_status() {
  local description="$1" expected="$2" actual="$3" body_file="$4"

  if [[ ",${expected}," != *",${actual},"* ]]; then
    echo "FAIL: ${description}: expected HTTP ${expected}, got ${actual}"
    if [[ -s "${body_file}" ]]; then
      cat "${body_file}"
      echo
    fi
    return 1
  fi
  echo "PASS: ${description} (HTTP ${actual})"
}

echo '================================================================='
echo '1. Environment and activation'
echo '================================================================='

wp core version
wp plugin activate woocommerce
wp plugin activate "${PLUGIN_SLUG}"
wp plugin is-active woocommerce
wp plugin is-active "${PLUGIN_SLUG}"

# The REST assertions below address /wp-json/, which only exists once the
# rewrite rules are pretty. A storefront is configured this way; the plain
# permalink path has its own dedicated coverage in the stub suite.
wp rewrite structure '/%postname%/' --hard
wp rewrite flush --hard

echo '================================================================='
echo '2. REST route registration and permission callbacks'
echo '================================================================='

# Registration is asserted against the REST server rather than over HTTP, so
# a missing route is not confused with a 404 from a rewrite problem.
wp eval '
  $routes = rest_get_server()->get_routes();
  $expected = [
      "/gecx/v1/session",
      "/gecx/v1/cart-session",
      "/gecx/v1/refresh-token",
      "/gecx/v1/auth-context",
      "/gecx/v1/webhooks/order-created",
      "/gecx/v1/link-agent",
      "/gecx/v1/public-key",
  ];
  foreach ( $expected as $route ) {
      if ( ! isset( $routes[ $route ] ) ) {
          fwrite( STDERR, "FAIL: REST route " . $route . " is not registered\n" );
          exit( 1 );
      }
  }
  echo "PASS: all " . count( $expected ) . " gecx/v1 routes registered.\n";
'

# /gecx/v1/auth-context reads the logged_in cookie without a prior nonce, so
# it has to refuse a request that cannot prove it is same-origin. A bare
# curl carries no Sec-Fetch-Site, Origin or Referer, which is exactly the
# shape that must be refused.
#
# The route is POST only. A GET is refused by routing before the permission
# callback is consulted, so these have to be POSTs to reach the gate at all.
status="$(curl -s -o /tmp/gecx_auth_bare.json -w '%{http_code}' -X POST "${SITE_URL}/wp-json/gecx/v1/auth-context")"
assert_status 'auth-context refuses an unattributable request' '403' "${status}" /tmp/gecx_auth_bare.json

status="$(curl -s -o /tmp/gecx_auth.json -w '%{http_code}' \
  -X POST \
  -H 'Sec-Fetch-Site: same-origin' \
  "${SITE_URL}/wp-json/gecx/v1/auth-context")"
assert_status 'auth-context serves a same-origin request' '200' "${status}" /tmp/gecx_auth.json

# A state-changing read of the session cookie must not be reachable by a
# method a cross-site context can issue without preflight.
status="$(curl -s -o /tmp/gecx_auth_get.json -w '%{http_code}' \
  -H 'Sec-Fetch-Site: same-origin' \
  "${SITE_URL}/wp-json/gecx/v1/auth-context")"
assert_status 'auth-context is not served over GET' '404' "${status}" /tmp/gecx_auth_get.json

for key in '"success"' '"nonce"' '"customer_jwt"'; do
  if ! grep -q "${key}" /tmp/gecx_auth.json; then
    echo "FAIL: ${key} missing from the auth-context response"
    cat /tmp/gecx_auth.json
    exit 1
  fi
done
echo 'PASS: auth-context returned success, nonce and customer_jwt.'

# The operator endpoints are capability-gated and must not answer an
# anonymous caller. agent_name is supplied because WordPress validates the
# registered args before it runs permission_callback: omitting it returns 400
# and the capability gate is never reached, so the assertion would prove
# nothing. The value is schema-valid and must still be refused.
status="$(curl -s -o /tmp/gecx_link.json -w '%{http_code}' \
  -X POST "${SITE_URL}/wp-json/gecx/v1/link-agent" \
  -d 'agent_name=projects/123/locations/global/agents/456')"
assert_status 'link-agent rejects an anonymous caller' '401,403' "${status}" /tmp/gecx_link.json

status="$(curl -s -o /tmp/gecx_pubkey.json -w '%{http_code}' \
  "${SITE_URL}/wp-json/gecx/v1/public-key")"
assert_status 'public-key rejects an anonymous caller' '401,403' "${status}" /tmp/gecx_pubkey.json

echo '================================================================='
echo '3. Cart-Token authentication hooks and Store API scoping'
echo '================================================================='

wp eval '
  if ( ! class_exists( \Google\Gemini_Enterprise_For_CX\Auth::class ) ) {
      fwrite( STDERR, "FAIL: Google\\Gemini_Enterprise_For_CX\\Auth was not loaded\n" );
      exit( 1 );
  }

  // Inspect the hooks the plugin actually registered. Constructing a second
  // Auth here would register the very callbacks being looked for and
  // the assertion would pass no matter what the plugin did.
  $registered = static function ( string $hook, string $method ): bool {
      global $wp_filter;
      if ( empty( $wp_filter[ $hook ] ) ) {
          return false;
      }
      foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
          foreach ( $callbacks as $callback ) {
              $fn = $callback["function"];
              if ( is_array( $fn ) && $fn[0] instanceof \Google\Gemini_Enterprise_For_CX\Auth && $method === $fn[1] ) {
                  return true;
              }
          }
      }
      return false;
  };

  if ( ! $registered( "determine_current_user", "authenticate_via_cart_token" ) ) {
      fwrite( STDERR, "FAIL: authenticate_via_cart_token is not on determine_current_user\n" );
      exit( 1 );
  }
  if ( ! $registered( "rest_pre_dispatch", "block_cart_token_off_store_api" ) ) {
      fwrite( STDERR, "FAIL: block_cart_token_off_store_api is not on rest_pre_dispatch\n" );
      exit( 1 );
  }

  // reset_cart_token_state() is inert outside the stub suite, so the static
  // is driven directly. newInstanceWithoutConstructor() keeps this from
  // adding a duplicate copy of both filters.
  $auth = ( new ReflectionClass( \Google\Gemini_Enterprise_For_CX\Auth::class ) )->newInstanceWithoutConstructor();
  $state = new ReflectionProperty( \Google\Gemini_Enterprise_For_CX\Auth::class, "authenticated_via_cart_token" );
  $state->setAccessible( true );
  $state->setValue( null, true );

  try {
      $blocked = $auth->block_cart_token_off_store_api( null, null, new WP_REST_Request( "GET", "/wp/v2/posts" ) );
      if ( ! is_wp_error( $blocked ) || "rest_forbidden" !== $blocked->get_error_code() ) {
          fwrite( STDERR, "FAIL: a cart token was allowed to reach /wp/v2/posts\n" );
          exit( 1 );
      }
      if ( 403 !== ( $blocked->get_error_data()["status"] ?? 0 ) ) {
          fwrite( STDERR, "FAIL: the refusal did not carry a 403\n" );
          exit( 1 );
      }

      foreach ( [ "/wc/store/v1/cart", "/wc/store/v1/cart/add-item", "/wc/store/v1/batch" ] as $route ) {
          $allowed = $auth->block_cart_token_off_store_api( null, null, new WP_REST_Request( "GET", $route ) );
          if ( is_wp_error( $allowed ) ) {
              fwrite( STDERR, "FAIL: a cart token was refused on " . $route . "\n" );
              exit( 1 );
          }
      }

      // Order and checkout are Store API but deliberately out of scope.
      foreach ( [ "/wc/store/v1/order/1", "/wc/store/v1/checkout" ] as $route ) {
          $refused = $auth->block_cart_token_off_store_api( null, null, new WP_REST_Request( "GET", $route ) );
          if ( ! is_wp_error( $refused ) ) {
              fwrite( STDERR, "FAIL: a cart token reached " . $route . "\n" );
              exit( 1 );
          }
      }
  } finally {
      $state->setValue( null, false );
  }

  echo "PASS: Cart-Token hooks registered and Store API scoping enforced.\n";
'

echo '================================================================='
echo '4. Webhook lifecycle through WooCommerce'
echo '================================================================='

wp eval '
  if ( ! class_exists( "WC_Webhook" ) ) {
      fwrite( STDERR, "FAIL: WC_Webhook is unavailable; WooCommerce did not load\n" );
      exit( 1 );
  }

  $webhook = new WC_Webhook();
  $webhook->set_name( "GECX Agent Order Created" );
  $webhook->set_topic( "order.created" );
  $webhook->set_delivery_url( "https://example.com/gecx/webhook" );
  $webhook->set_status( "active" );
  $webhook_id = $webhook->save();

  if ( empty( $webhook_id ) ) {
      fwrite( STDERR, "FAIL: the webhook did not save\n" );
      exit( 1 );
  }

  update_option( "gecx_webhook_id", $webhook_id );

  $stored = new WC_Webhook( $webhook_id );
  if ( "GECX Agent Order Created" !== $stored->get_name() || "order.created" !== $stored->get_topic() || "active" !== $stored->get_status() ) {
      fwrite( STDERR, "FAIL: the stored webhook does not read back as written\n" );
      exit( 1 );
  }

  echo "PASS: webhook " . $webhook_id . " created and read back from WooCommerce.\n";
'

echo '================================================================='
echo '5. WooCommerce session cookies'
echo '================================================================='

# How WooCommerce signs its session cookie differs between versions, and some
# accept no signature but their own: 10.0 to 11.0 use wp_fast_hash() wherever
# WordPress 6.8+ provides it. Only a real WC_Session_Handler can say which
# cookies the version under test accepts. WP-CLI cannot send a header, so
# woocommerce_set_cookie_enabled captures each cookie instead.
wp eval '
  $captured = [];
  add_filter(
      "woocommerce_set_cookie_enabled",
      static function ( $enabled, $name, $value ) use ( &$captured ) {
          $captured[] = [ $name, $value ];
          return false;
      },
      10,
      3
  );
  $cookie_name     = apply_filters( "woocommerce_cookie", "wp_woocommerce_session_" . COOKIEHASH );
  $session_cookies = static function () use ( &$captured, $cookie_name ): array {
      $values = [];
      foreach ( $captured as $cookie ) {
          if ( $cookie_name === $cookie[0] ) {
              $values[] = $cookie[1];
          }
      }
      return $values;
  };

  // The cookie the plugin sets to bridge a Cart-Token cart to a browser.
  $guest_key = "t_gecx_runtime_" . wp_generate_password( 12, false );
  $bridge    = new ReflectionMethod( \Google\Gemini_Enterprise_For_CX\REST\Cart_Session::class, "set_guest_session_cookie" );
  $bridge->setAccessible( true );
  $bridge->invoke( null, $guest_key );

  $bridged = $session_cookies();
  if ( 1 !== count( $bridged ) ) {
      fwrite( STDERR, "FAIL: set_guest_session_cookie() set " . count( $bridged ) . " session cookies, expected 1\n" );
      exit( 1 );
  }
  $_COOKIE[ $cookie_name ] = $bridged[0];
  $parsed                  = ( new WC_Session_Handler() )->get_session_cookie();
  if ( ! is_array( $parsed ) || $guest_key !== $parsed[0] ) {
      fwrite( STDERR, "FAIL: WooCommerce rejected the session cookie the plugin set: " . $bridged[0] . "\n" );
      exit( 1 );
  }
  echo "PASS: WooCommerce accepts the session cookie the plugin sets.\n";

  // A cookie WooCommerce issues for a session of its own.
  unset( $_COOKIE[ $cookie_name ] );
  $captured = [];
  $issuer   = new WC_Session_Handler();
  $issuer->init();
  $issuer->set_customer_session_cookie( true );
  $issued      = $session_cookies();
  $customer_id = (string) $issuer->get_customer_id();
  if ( 1 !== count( $issued ) || 0 !== strpos( $issued[0], $customer_id ) ) {
      fwrite( STDERR, "FAIL: WooCommerce did not issue a session cookie for " . $customer_id . "\n" );
      exit( 1 );
  }

  // Checked on StoreApi\SessionHandler, which a Store API request with a
  // Cart-Token runs on and which cannot check a cookie, and on the session
  // handler a browser request runs on.
  $request_session = WC()->session;
  $verify          = static function () {
      return \Google\Gemini_Enterprise_For_CX\REST\Cart_Session::get_verified_session_cookie_customer_id();
  };
  try {
      foreach (
          [
              "StoreApi\\SessionHandler" => ( new ReflectionClass( \Automattic\WooCommerce\StoreApi\SessionHandler::class ) )->newInstanceWithoutConstructor(),
              "WC_Session_Handler"       => $issuer,
          ] as $label => $session
      ) {
          WC()->session = $session;

          $_COOKIE[ $cookie_name ] = $issued[0];
          if ( $customer_id !== $verify() ) {
              fwrite( STDERR, "FAIL: on " . $label . ", the plugin rejected a session cookie WooCommerce issued\n" );
              exit( 1 );
          }

          // The same signature, presented for another session.
          $_COOKIE[ $cookie_name ] = "t_gecx_tampered" . substr( $issued[0], strlen( $customer_id ) );
          if ( "" !== $verify() ) {
              fwrite( STDERR, "FAIL: on " . $label . ", the plugin accepted a tampered session cookie\n" );
              exit( 1 );
          }
      }
  } finally {
      WC()->session = $request_session;
      unset( $_COOKIE[ $cookie_name ] );
  }
  echo "PASS: the plugin accepts the session cookie WooCommerce issues and rejects a tampered one.\n";
'

# A guest's own first add to cart, with no cookie and no Cart-Token:
# WooCommerce sets the session cookie on that response itself, and a second
# one from the plugin would be the cookie the browser keeps. The guest then
# has to find its cart on the next request.
product_id="$(wp eval '
  $product = new WC_Product_Simple();
  $product->set_name( "GECX session cookie check" );
  $product->set_regular_price( "10" );
  $product->set_status( "publish" );
  echo "GECX_PRODUCT_ID=" . $product->save() . "\n";
' | tr -d '\r' | sed -n 's/^GECX_PRODUCT_ID=//p')"
if [[ -z "${product_id}" ]]; then
  echo 'FAIL: the product for the add-to-cart check was not created'
  exit 1
fi

# The Store API nonce a cookieless guest gets with a cart read, which sets no
# cookie of its own.
curl -s -o /tmp/gecx_cart_before.json -D /tmp/gecx_cart_before_headers.txt "${SITE_URL}/wp-json/wc/store/v1/cart"
nonce="$(tr -d '\r' < /tmp/gecx_cart_before_headers.txt | sed -n 's/^[Nn]once: //p' | tail -n 1)"

rm -f /tmp/gecx_cookies.txt
status="$(curl -s -o /tmp/gecx_add_item.json -D /tmp/gecx_add_item_headers.txt -c /tmp/gecx_cookies.txt -w '%{http_code}' \
  -X POST "${SITE_URL}/wp-json/wc/store/v1/cart/add-item" \
  -H 'Content-Type: application/json' \
  -H "Nonce: ${nonce}" \
  --data "{\"id\":${product_id},\"quantity\":1}")"
assert_status 'a cookieless guest adds to the cart through the Store API' '200,201' "${status}" /tmp/gecx_add_item.json

# Counted by value: the same cookie sent twice still leaves one in the
# browser, two different ones leave whichever came last.
session_cookies="$(tr -d '\r' < /tmp/gecx_add_item_headers.txt \
  | awk 'tolower($0) ~ /^set-cookie: wp_woocommerce_session_/ { sub(/;.*/, ""); print }' \
  | sort -u | wc -l)"
if [[ "${session_cookies}" -ne 1 ]]; then
  echo "FAIL: the add to cart set ${session_cookies} different session cookies, expected 1"
  cat /tmp/gecx_add_item_headers.txt
  exit 1
fi
echo 'PASS: the add to cart set exactly one session cookie.'

status="$(curl -s -o /tmp/gecx_cart_after.json -b /tmp/gecx_cookies.txt -w '%{http_code}' "${SITE_URL}/wp-json/wc/store/v1/cart")"
assert_status 'the guest reads its cart with the cookie it was given' '200' "${status}" /tmp/gecx_cart_after.json
if ! grep -q '"items_count":1' /tmp/gecx_cart_after.json; then
  echo 'FAIL: WooCommerce did not accept the session cookie: the cart came back without the item'
  cat /tmp/gecx_cart_after.json
  echo
  exit 1
fi
echo 'PASS: WooCommerce accepted the session cookie, and the cart still holds the item.'

echo '================================================================='
echo '6. Binding the browser to the agent cart'
echo '================================================================='

# The /cart-session route over HTTP, against the real session handler: the
# browser binding the stub suite can only model. The agent's cart is filled
# the way the agent fills it, through the Store API with a Cart-Token and no
# cookies, and its reads carry a query parameter no other request uses, as
# the agent's own do, so a page cache cannot answer them.
agent_cart() {
  local headers_file="$1"
  curl -s -o /dev/null -D "${headers_file}" "${SITE_URL}/wp-json/wc/store/v1/cart?_nocache=$(date +%s%N)"
}
header_value() {
  tr -d '\r' < "$2" | awk -v name="$1" 'tolower($0) ~ "^" tolower(name) ": " { sub(/^[^:]*: /, ""); value = $0 } END { print value }'
}

agent_cart /tmp/gecx_agent_cart_headers.txt
agent_token="$(header_value 'Cart-Token' /tmp/gecx_agent_cart_headers.txt)"
agent_nonce="$(header_value 'Nonce' /tmp/gecx_agent_cart_headers.txt)"
status="$(curl -s -o /tmp/gecx_agent_add.json -w '%{http_code}' \
  -X POST "${SITE_URL}/wp-json/wc/store/v1/cart/add-item" \
  -H 'Content-Type: application/json' \
  -H "Cart-Token: ${agent_token}" \
  -H "Nonce: ${agent_nonce}" \
  --data "{\"id\":${product_id},\"quantity\":1}")"
assert_status 'the agent adds to its cart through the Store API' '200,201' "${status}" /tmp/gecx_agent_add.json

agent_key="$(wp eval '
  echo "GECX_AGENT_KEY=" . \Google\Gemini_Enterprise_For_CX\Auth::get_cart_token_customer_id( "'"${agent_token}"'" ) . "\n";
' | tr -d '\r' | sed -n 's/^GECX_AGENT_KEY=//p')"
if [[ "${agent_key}" != t_* ]]; then
  echo "FAIL: the agent cart token names no guest session: '${agent_key}'"
  exit 1
fi

# A browser that has never had a WooCommerce session asks with its wp_rest
# nonce, which it gets from auth-context like the storefront script does.
rm -f /tmp/gecx_browser_cookies.txt
curl -s -o /tmp/gecx_browser_auth.json -c /tmp/gecx_browser_cookies.txt \
  -X POST -H 'Sec-Fetch-Site: same-origin' "${SITE_URL}/wp-json/gecx/v1/auth-context"
rest_nonce="$(sed -n 's/.*"nonce":"\([^"]*\)".*/\1/p' /tmp/gecx_browser_auth.json)"
if [[ -z "${rest_nonce}" ]]; then
  echo 'FAIL: auth-context returned no nonce for the binding check'
  cat /tmp/gecx_browser_auth.json
  exit 1
fi

# The shared logged-out nonce is not enough on its own: without proof the
# request is same-origin, another site could bind a shopper's browser.
status="$(curl -s -o /tmp/gecx_bind_bare.json -w '%{http_code}' \
  -X POST "${SITE_URL}/wp-json/gecx/v1/cart-session" \
  -H 'Content-Type: application/json' \
  -H "X-WP-Nonce: ${rest_nonce}" \
  --data "{\"cart_token\":\"${agent_token}\"}")"
assert_status 'cart-session refuses a request that is not shown to be same-origin' '403' "${status}" /tmp/gecx_bind_bare.json

status="$(curl -s -o /tmp/gecx_bind.json -D /tmp/gecx_bind_headers.txt \
  -b /tmp/gecx_browser_cookies.txt -c /tmp/gecx_browser_cookies.txt -w '%{http_code}' \
  -X POST "${SITE_URL}/wp-json/gecx/v1/cart-session" \
  -H 'Content-Type: application/json' \
  -H 'Sec-Fetch-Site: same-origin' \
  -H "Origin: ${SITE_URL}" \
  -H "X-WP-Nonce: ${rest_nonce}" \
  --data "{\"cart_token\":\"${agent_token}\"}")"
assert_status 'cart-session binds a cookieless browser' '200' "${status}" /tmp/gecx_bind.json
if ! grep -q '"binding":"agent"' /tmp/gecx_bind.json; then
  echo 'FAIL: cart-session did not bind the browser to the agent cart'
  cat /tmp/gecx_bind.json
  echo
  exit 1
fi
bound_token="$(header_value 'Cart-Token' /tmp/gecx_bind_headers.txt)"
bound_key="$(wp eval '
  echo "GECX_BOUND_KEY=" . \Google\Gemini_Enterprise_For_CX\Auth::get_cart_token_customer_id( "'"${bound_token}"'" ) . "\n";
' | tr -d '\r' | sed -n 's/^GECX_BOUND_KEY=//p')"
if [[ "${bound_key}" != "${agent_key}" ]] || grep -q 'eyJ' /tmp/gecx_bind.json; then
  echo 'FAIL: the bound cart token must come only in the Cart-Token header, for the agent cart'
  cat /tmp/gecx_bind_headers.txt /tmp/gecx_bind.json
  exit 1
fi
if ! header_value 'Cache-Control' /tmp/gecx_bind_headers.txt | grep -q 'no-store'; then
  echo 'FAIL: the cart-session response is cacheable'
  cat /tmp/gecx_bind_headers.txt
  exit 1
fi

session_cookies="$(tr -d '\r' < /tmp/gecx_bind_headers.txt \
  | awk 'tolower($0) ~ /^set-cookie: wp_woocommerce_session_/ { sub(/^[^:]*: [^=]*=/, ""); sub(/;.*/, ""); print }' \
  | sort -u)"
if [[ "$(printf '%s\n' "${session_cookies}" | grep -c .)" -ne 1 ]] || [[ "${session_cookies}" != "${agent_key}"* ]]; then
  echo "FAIL: binding should set one session cookie for ${agent_key}, set: ${session_cookies}"
  exit 1
fi
echo 'PASS: cart-session bound the browser, set one session cookie for the agent cart and sent its token in a header only.'

# WooCommerce must accept that cookie: the browser now reads the agent's cart
# with nothing but its cookies.
status="$(curl -s -o /tmp/gecx_bound_cart.json -b /tmp/gecx_browser_cookies.txt -w '%{http_code}' \
  "${SITE_URL}/wp-json/wc/store/v1/cart?_nocache=$(date +%s%N)")"
assert_status 'the bound browser reads its cart with its cookie' '200' "${status}" /tmp/gecx_bound_cart.json
if ! grep -q '"items_count":1' /tmp/gecx_bound_cart.json; then
  echo 'FAIL: the bound browser does not see the agent cart'
  cat /tmp/gecx_bound_cart.json
  echo
  exit 1
fi
echo 'PASS: WooCommerce accepted the binding cookie, and the browser sees the agent cart.'

# The session row has to live as long as the cookie naming it, or
# cleanup_sessions() deletes the cart while the cookie still points at it.
cookie_expiration="$(printf '%s' "${session_cookies}" | sed 's/%7C/|/g; s/||/|/g' | cut -d'|' -f2)"
row_expiry="$(wp eval '
  global $wpdb;
  echo "GECX_ROW_EXPIRY=" . $wpdb->get_var( $wpdb->prepare( "SELECT session_expiry FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", "'"${agent_key}"'" ) ) . "\n";
' | tr -d '\r' | sed -n 's/^GECX_ROW_EXPIRY=//p')"
if [[ -z "${row_expiry}" ]] || [[ "${row_expiry}" -lt "${cookie_expiration}" ]]; then
  echo "FAIL: the bound session row expires at '${row_expiry}', before its cookie (${cookie_expiration})"
  exit 1
fi
echo 'PASS: the bound session row lives as long as its cookie.'

# An agent cart with nothing in it is not worth a session cookie: binding
# would turn page caching off for the visitor and change the nonces on the page.
agent_cart /tmp/gecx_empty_agent_headers.txt
empty_token="$(header_value 'Cart-Token' /tmp/gecx_empty_agent_headers.txt)"
rm -f /tmp/gecx_fresh_cookies.txt
status="$(curl -s -o /tmp/gecx_bind_empty.json -D /tmp/gecx_bind_empty_headers.txt -c /tmp/gecx_fresh_cookies.txt -w '%{http_code}' \
  -X POST "${SITE_URL}/wp-json/gecx/v1/cart-session" \
  -H 'Content-Type: application/json' \
  -H 'Sec-Fetch-Site: same-origin' \
  -H "Origin: ${SITE_URL}" \
  -H "X-WP-Nonce: ${rest_nonce}" \
  --data "{\"cart_token\":\"${empty_token}\"}")"
assert_status 'cart-session answers for an empty agent cart' '200' "${status}" /tmp/gecx_bind_empty.json
if ! grep -q '"binding":"deferred"' /tmp/gecx_bind_empty.json || grep -qi '^set-cookie: wp_woocommerce_session_' /tmp/gecx_bind_empty_headers.txt; then
  echo 'FAIL: an empty agent cart must be deferred, without a session cookie'
  cat /tmp/gecx_bind_empty_headers.txt /tmp/gecx_bind_empty.json
  exit 1
fi
echo 'PASS: an empty agent cart is deferred without a session cookie.'

echo '================================================================='
echo '7. Uninstall sweep'
echo '================================================================='

# uninstall.php is included the way WordPress includes it rather than run via
# `wp plugin uninstall`, which would delete the bind-mounted checkout the rest
# of the job is still reading from.
wp eval '
  update_option( "gecx_agent_name", "projects/123/locations/global/agents/456" );
  update_option( "gecx_auth_complete", 1 );
  update_option( "gecx_agent_enabled", "yes" );
  update_option( "gecx_pdp_prompts_enabled", "yes" );
  update_option( "gecx_button_placement", "floating" );
  update_option( "gecx_plugin_version", "0.0.0-test" );
  // Not an allowed console host, so uninstall.php sends no notification
  // and mints no JWT instead of reaching anything real.
  update_option( "gecx_console_base_url", "https://127.0.0.1" );
  set_transient( "gecx_admin_notice_error", "temporary error", 300 );
  set_transient( "gecx_guest_jwt_cache", "cached jwt", 300 );
  echo "Seeded options and transients.\n";
'

wp plugin deactivate "${PLUGIN_SLUG}"

wp eval '
  define( "WP_UNINSTALL_PLUGIN", "'"${PLUGIN_SLUG}"'/gecx-agent.php" );
  require WP_PLUGIN_DIR . "/'"${PLUGIN_SLUG}"'/uninstall.php";
  echo "uninstall.php completed.\n";
'

wp eval '
  $failures = [];

  $options = [
      "gecx_webhook_id",
      "gecx_agent_name",
      "gecx_auth_complete",
      "gecx_agent_enabled",
      "gecx_pdp_prompts_enabled",
      "gecx_button_placement",
      "gecx_plugin_version",
      "gecx_console_base_url",
      "gecx_keypair",
      "gecx_api_secret",
  ];
  foreach ( $options as $option ) {
      if ( false !== get_option( $option, false ) ) {
          $failures[] = "option " . $option;
      }
  }

  foreach ( [ "gecx_admin_notice_error", "gecx_guest_jwt_cache" ] as $transient ) {
      if ( false !== get_transient( $transient ) ) {
          $failures[] = "transient " . $transient;
      }
  }

  // The webhook row has to go too: left behind, it resumes firing the moment
  // WooCommerce is reactivated.
  if ( function_exists( "wc_get_webhooks" ) ) {
      foreach ( wc_get_webhooks( [ "status" => "any", "limit" => 50 ] ) as $webhook ) {
          if ( "GECX Agent Order Created" === $webhook->get_name() ) {
              $failures[] = "webhook " . $webhook->get_id();
          }
      }
  }

  if ( $failures ) {
      fwrite( STDERR, "FAIL: survived uninstall: " . implode( ", ", $failures ) . "\n" );
      exit( 1 );
  }

  echo "PASS: options, transients and the webhook were all removed.\n";
'

echo '================================================================='
echo 'All wp-env runtime integration tests passed.'
echo '================================================================='
