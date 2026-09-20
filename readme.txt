=== Gemini Enterprise for CX ===
Contributors: google
Tags: woocommerce, marketing, ai, agent, gecx
Requires at least: 6.2
WC requires at least: 7.1
WC tested up to: 11.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.12
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Drive sales with an AI agent that's already an expert on your brand and products. Go live instantly on your WooCommerce storefront.

== Description ==

Gemini Enterprise for CX integrates your WooCommerce store with Google's Gemini Enterprise for CX (GECX). It embeds a chat widget on your storefront, allowing customers to interact with an AI agent that can answer questions about products, store policies, and assist with adding items to the cart.

This plugin relies on the Gemini Enterprise for CX Software as a Service (SaaS) provided by Google Cloud. Connecting to this service requires a Google account.

== 3rd Party Services, Privacy & Terms of Service ==

This plugin connects to 3rd-party services provided by Google to function:

1. **Gemini Enterprise for CX Service & Onboarding Console**
   * **Service Provider:** Google LLC
   * **Endpoints:** `https://gecx.cloud.google.com`
   * **Purpose:** Merchant account authentication, store onboarding, agent configuration, and secure exchange of WooCommerce REST API credentials for authenticating to admin apis.
   * **Data Transmitted:** Store URL, admin authorization tokens, and product/catalog metadata.
   * **Account Requirement:** A Google account with access to Gemini Enterprise for CX.

2. **Storefront Chat Widget Client SDK**
   * **Service Provider:** Google LLC
   * **Endpoints:**
     * `https://www.gstatic.com/gecx/chat-widget/woocommerce-chat-widget.js`
   * **Purpose:** Delivers the client runtime required to render the interactive chat widget and product suggestion pills on the customer-facing storefront, and to communicate with the GECX service. The widget stylesheet is bundled with the plugin (`assets/css/theme.css`) and is not fetched remotely.
   * **Data Transmitted:** Standard HTTP request headers (IP address, User-Agent) when loading static assets. Customer chat messages and product browsing context are processed by GECX during live chat interactions.

**Terms & Policies:**
* GECX Console: https://gecx.cloud.google.com
* Google Terms of Service: https://policies.google.com/terms
* Google Cloud Terms: https://cloud.google.com/terms
* Google Privacy Policy: https://policies.google.com/privacy

== Installation ==

= Minimum Requirements =

* WordPress 6.2 or greater
* WooCommerce 7.1 or greater
* PHP version 7.4 or greater

= Automatic installation =

Log in to your WordPress dashboard, navigate to the Plugins menu and click Add New. Search for "Gemini Enterprise for CX" and click Install Now, then Activate.

= Manual installation =

Upload the plugin folder to the `/wp-content/plugins/` directory, then activate the plugin through the 'Plugins' screen in WordPress.

== FAQ ==

= Does this plugin rely on an external SaaS service? =
Yes. The plugin connects your WooCommerce store to Google's Gemini Enterprise for CX platform to power the AI storefront agent.

= What external endpoints and CDNs are called? =
* `https://gecx.cloud.google.com` is used in the admin settings dashboard to manage your GECX agent and connect store APIs.
* `https://www.gstatic.com` is used on the customer storefront to load the chat widget client SDK (`woocommerce-chat-widget.js`). The widget stylesheet is served from the plugin directory, not from a remote origin.

= What data is sent to Google? =
During merchant setup, store URL and WooCommerce API credentials are authenticated. During storefront usage, customer chat queries and viewed product context are processed to return relevant answers. Standard request headers (such as IP address) are processed by Google's infrastructure in accordance with the Google Privacy Policy.

= Do I need an account to use this plugin? =
Yes. You need a Google account with access to Gemini Enterprise for CX to configure and activate the agent on your store.

== Changelog ==

The complete release history is kept in changelog.txt at the plugin root.

= 0.3.12 =
* Resolve the logged-in user on `/wp-json/gecx/v1/auth-context` without calling `wp_set_current_user()`, passing the validated cookie user ID directly to `GECX_Auth::generate_customer_jwt()` and to `wp_create_nonce()` via the core `nonce_user_logged_out` filter.

= 0.3.11 =
* Reconcile store state with the SyncState API on `admin_init` when the installed plugin version changes, so the backend records the new version on the first administrator page load after an upgrade without waiting out the sync throttle window.
* Keep the recorded plugin version pending when an upgrade sync fails, while rate-limiting retries by the sync throttle window.
* Queue notices raised by an `admin_init` upgrade sync and display them on `admin_notices` for administrators.

= 0.3.10 =
* Stop rendering `wp-nonce` and `customer-jwt` attributes into storefront HTML, so full-page caches can no longer serve one shopper's credentials to the next.
* Serve fresh per-shopper auth context (`nonce` and `customer_jwt`) from `/wp-json/gecx/v1/auth-context` (`POST` and `GET`) with `no-store` cache headers, `Access-Control-Allow-Origin` suppression, and strict same-origin enforcement across `Sec-Fetch-Site`, `Origin`, and `Referer`.
* Emit `rest-url` on `<gecx-woocommerce-chat-widget>` from `rest_url()` so the widget resolves WordPress REST routes on subdirectory installs, plain permalinks, and custom REST prefixes.

= 0.3.9 =
* Sign customer and admin JWTs exclusively with the store's RSA private key (RS256). A store that cannot produce a usable keypair now mints no token and logs an error instead of falling back to the retired `gecx_api_secret` shared secret.
* Remove the legacy `POST /wp-json/gecx/v1/secret` route and the `gecx_api_secret` filter. Order-created webhook registration and credential refresh now run solely through `POST /wp-json/gecx/v1/webhooks/order-created`, and webhook HMAC signatures are derived only from the webhook's own consumer secret.
* Continue deleting `gecx_api_secret` on unlink and on uninstall so stores upgrading from earlier releases clear the stale option.

= 0.3.8 =
* Remove the `Cart-Token` from the sub-response `headers` that a `/wc/store/v1/batch` response repeats inside its JSON body. WordPress builds an envelope for each sub-response and puts its headers in the body as data, so the session credential WooCommerce issued for each cart sub-request was still leaving the store in the response payload, after 0.3.6 put it in the response header and 0.3.7 stopped mirroring it as `id`. Only `Cart-Token` is removed; every other sub-response header stays.

Releases before 0.3.8 are listed in changelog.txt at the plugin root.
