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
#   5. The uninstall sweep, run the way WordPress runs it
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
echo '5. Uninstall sweep'
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
