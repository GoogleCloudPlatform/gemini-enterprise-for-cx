#!/usr/bin/env bash
#
# Copyright 2026 Google LLC
#
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Runtime integration tests executed inside the wp-env environment.
# Covers:
# 1. Plugin activation & status
# 2. REST route scoping & permission checks
# 3. Cart-Token authentication & route scoping
# 4. Webhook lifecycle in real WooCommerce runtime
# 5. Full uninstall sweep of database options, transients, and webhooks
#

set -euo pipefail

echo "================================================================="
echo "1. Verifying WordPress & WooCommerce environment and plugin activation"
echo "================================================================="

npx @wordpress/env run cli wp --info
npx @wordpress/env run cli wp plugin activate woocommerce
npx @wordpress/env run cli wp plugin activate gemini-enterprise-for-cx

npx @wordpress/env run cli wp plugin is-active woocommerce
npx @wordpress/env run cli wp plugin is-active gemini-enterprise-for-cx

echo "Plugin activated successfully."

echo "================================================================="
echo "2. Verifying REST route registration and scoping"
echo "================================================================="

# Wait briefly for web server readiness
sleep 2

# Test /gecx/v1/config endpoint
CONFIG_STATUS=$(curl -s -o /tmp/gecx_config.json -w "%{http_code}" http://localhost:8888/wp-json/gecx/v1/config)
if [[ "${CONFIG_STATUS}" != "200" ]]; then
  echo "FAIL: Expected HTTP 200 from /gecx/v1/config, got ${CONFIG_STATUS}"
  cat /tmp/gecx_config.json || true
  exit 1
fi
grep -q '"store_connected"' /tmp/gecx_config.json || {
  echo "FAIL: 'store_connected' missing from /gecx/v1/config response"
  cat /tmp/gecx_config.json
  exit 1
}
echo "PASS: /gecx/v1/config responded with valid configuration JSON."

# Test /gecx/v1/auth-context endpoint without session
AUTH_STATUS=$(curl -s -o /tmp/gecx_auth.json -w "%{http_code}" http://localhost:8888/wp-json/gecx/v1/auth-context)
if [[ "${AUTH_STATUS}" != "200" ]]; then
  echo "FAIL: Expected HTTP 200 from /gecx/v1/auth-context, got ${AUTH_STATUS}"
  cat /tmp/gecx_auth.json || true
  exit 1
fi
echo "PASS: /gecx/v1/auth-context responded 200 OK."

# Test /gecx/v1/link unauthenticated request rejection
LINK_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost:8888/wp-json/gecx/v1/link)
if [[ "${LINK_STATUS}" != "401" && "${LINK_STATUS}" != "403" ]]; then
  echo "FAIL: Expected HTTP 401 or 403 from unauthenticated POST /gecx/v1/link, got ${LINK_STATUS}"
  exit 1
fi
echo "PASS: /gecx/v1/link rejected unauthenticated request with HTTP ${LINK_STATUS}."

echo "================================================================="
echo "3. Verifying Cart-Token authentication & route scoping"
echo "================================================================="

npx @wordpress/env run cli wp eval '
  if ( ! class_exists( "GECX_Auth" ) ) {
    fwrite( STDERR, "FAIL: GECX_Auth class not found\n" );
    exit( 1 );
  }

  $auth = new GECX_Auth();

  // Verify determine_current_user and rest_pre_dispatch hooks are attached.
  if ( ! has_filter( "determine_current_user", [ $auth, "authenticate_via_cart_token" ] ) ) {
    fwrite( STDERR, "FAIL: determine_current_user hook missing\n" );
    exit( 1 );
  }

  if ( ! has_filter( "rest_pre_dispatch", [ $auth, "block_cart_token_off_store_api" ] ) ) {
    fwrite( STDERR, "FAIL: rest_pre_dispatch hook missing\n" );
    exit( 1 );
  }

  // Verify block_cart_token_off_store_api refuses non-Store API routes when authenticated via Cart-Token.
  $prop = new ReflectionProperty( "GECX_Auth", "authenticated_via_cart_token" );
  $prop->setAccessible( true );
  $prop->setValue( null, true );

  $req = new WP_REST_Request( "GET", "/wp/v2/posts" );
  $res = $auth->block_cart_token_off_store_api( null, null, $req );

  if ( ! is_wp_error( $res ) || "gecx_cart_token_scope_violation" !== $res->get_error_code() ) {
    fwrite( STDERR, "FAIL: block_cart_token_off_store_api did not block /wp/v2/posts\n" );
    exit( 1 );
  }

  // Verify it allows Store API routes (/wc/store/v1/cart).
  $req_store = new WP_REST_Request( "GET", "/wc/store/v1/cart" );
  $res_store = $auth->block_cart_token_off_store_api( null, null, $req_store );
  if ( is_wp_error( $res_store ) ) {
    fwrite( STDERR, "FAIL: block_cart_token_off_store_api unexpectedly blocked /wc/store/v1/cart\n" );
    exit( 1 );
  }

  $prop->setValue( null, false );
  echo "PASS: Cart-Token route scoping and off-store-api enforcement verified.\n";
'

echo "================================================================="
echo "4. Verifying Webhook lifecycle in WooCommerce runtime"
echo "================================================================="

npx @wordpress/env run cli wp eval '
  if ( ! class_exists( "WC_Webhook" ) ) {
    fwrite( STDERR, "FAIL: WC_Webhook class not found in WooCommerce runtime\n" );
    exit( 1 );
  }

  $webhook = new WC_Webhook();
  $webhook->set_name( "GECX Agent Order Created" );
  $webhook->set_topic( "order.created" );
  $webhook->set_delivery_url( "https://example.com/gecx/webhook" );
  $webhook->set_status( "active" );
  $webhook_id = $webhook->save();

  if ( empty( $webhook_id ) ) {
    fwrite( STDERR, "FAIL: Failed to save WooCommerce webhook\n" );
    exit( 1 );
  }

  update_option( "gecx_webhook_id", $webhook_id );

  $retrieved = new WC_Webhook( $webhook_id );
  if ( $retrieved->get_name() !== "GECX Agent Order Created" || "active" !== $retrieved->get_status() ) {
    fwrite( STDERR, "FAIL: Webhook validation failed after save\n" );
    exit( 1 );
  }

  echo "PASS: Webhook created, stored, and retrieved with ID: " . $webhook_id . "\n";
'

echo "================================================================="
echo "5. Verifying Uninstall sweep"
echo "================================================================="

# Populate representative settings and transients to test complete eradication
npx @wordpress/env run cli wp eval '
  update_option( "gecx_connection_status", "connected" );
  update_option( "gecx_agent_name", "projects/123/locations/global/agents/456" );
  update_option( "gecx_pdp_prompts_enabled", "yes" );
  update_option( "gecx_button_placement", "floating" );
  update_option( "gecx_keypair", [ "public" => "test_pub", "private" => "test_priv" ] );
  set_transient( "gecx_admin_notice_error", "temporary error", 300 );
  set_transient( "gecx_guest_jwt_cache", "cached_jwt", 300 );
  echo "Seed options and transients configured.\n";
'

# Run deactivation and uninstallation
npx @wordpress/env run cli wp plugin deactivate gemini-enterprise-for-cx
npx @wordpress/env run cli wp plugin uninstall gemini-enterprise-for-cx

# Verify database state after uninstall
npx @wordpress/env run cli wp eval '
  $critical_options = [
    "gecx_webhook_id",
    "gecx_connection_status",
    "gecx_agent_name",
    "gecx_pdp_prompts_enabled",
    "gecx_button_placement",
    "gecx_keypair",
  ];

  foreach ( $critical_options as $opt ) {
    if ( false !== get_option( $opt ) ) {
      fwrite( STDERR, "FAIL: Option " . $opt . " survived uninstall\n" );
      exit( 1 );
    }
  }

  if ( false !== get_transient( "gecx_admin_notice_error" ) ) {
    fwrite( STDERR, "FAIL: Transient gecx_admin_notice_error survived uninstall\n" );
    exit( 1 );
  }

  if ( false !== get_transient( "gecx_guest_jwt_cache" ) ) {
    fwrite( STDERR, "FAIL: Transient gecx_guest_jwt_cache survived uninstall\n" );
    exit( 1 );
  }

  echo "PASS: All GECX options and transients cleaned up successfully on uninstall.\n";
'

echo "================================================================="
echo "All wp-env runtime integration tests completed successfully!"
echo "================================================================="
