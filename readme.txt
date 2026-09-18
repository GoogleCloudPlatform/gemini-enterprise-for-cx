=== Gemini Enterprise for CX ===
Contributors: google
Tags: woocommerce, marketing, ai, agent, gecx
Requires at least: 6.2
WC requires at least: 7.1
WC tested up to: 11.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.5
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

* WordPress 5.0 or greater
* WooCommerce 5.0 or greater
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

= 0.3.5 =
* Restrict Cart-Token authentication strictly to Store API cart and batch endpoints (`/wc/store/v1/cart` and `/wc/store/v1/batch`), preventing order enumeration or guest order access via `/order/*` and `/checkout/*`.
* Accept a Cart-Token only on requests WordPress is actually about to dispatch to the WooCommerce Store API. The REST prefix must now sit at the site root rather than anywhere in the path, so a crafted path can no longer present a non-Store-API request as a Store API one.
* Refuse a Cart-Token on anything that is not the site's front controller, including admin, cron and AJAX requests, and on an `index.php` that is not WordPress's own.
* Reject tokens carrying a non-numeric or non-integer expiry, and treat the expiry second itself as expired.
* Report a refused Cart-Token with a stable machine-readable code and the capability that caused it, so a store can alert on refusals.
* Log an unrecognised token issuer at most once an hour per issuer instead of on every request.

= 0.3.4 =
* Receive the linked agent from Google Cloud over the store's own WooCommerce API credentials, on a new authenticated `POST /wp-json/gecx/v1/link-agent` route, instead of reading it out of the browser redirect back from the console.
* Stop persisting anything from the connection callback. The redirect now only consumes its one-time state and reports the outcome; it can no longer create, change, or clear the agent link.

= 0.3.3 =
* Bundle the storefront chat widget stylesheet (`assets/css/theme.css`) locally with the plugin to comply with WordPress.org Guideline 7, and load it via `plugins_url()`.

= 0.3.2 =
* Reconcile agent binding with the backend SyncState API when the settings page loads.
* Adopt the agent Google actually has linked when the locally saved agent differs, and clear the local binding only when Google has no link for the store.
* Warn the administrator and reopen the store authorization step when Google cannot verify the store identity or use the saved WooCommerce API keys.
* Keep appearance settings when the agent is unlinked, so re-linking does not lose customization.
* Throttle the reconciliation call to once every 10 minutes to protect settings page load time.

= 0.3.1 =
* Maintain relative centering for floating chat widget with page content when chat panel opens.
* Improve mobile header placement for topnav launcher with automatic candidate detection and floating action button fallback.
* Add dynamic viewport resize handling to maintain agent button visibility across responsive viewports.
* Customer JWTs no longer assert `is_admin`. The claim was previously derived from the shopper's own capabilities, so an administrator browsing their own storefront published an admin-flagged token into the page DOM for an hour.
* Removed the `gecx_customer_jwt_payload` and `gecx_admin_jwt_payload` filters. Any plugin on the store could reach them to have a claim signed with the store's own key. Removing them costs nothing: the agent backend reads a fixed set of claims and discards the rest, so a filter could never attach anything it would act on.
* Scope WooCommerce API key authentication to the route WordPress will dispatch rather than the request path. A request whose path named `/gecx/v1/secret` but whose `rest_route` named a core route previously authenticated that core route with the key.

= 0.3.0 =
* Restrict Cart-Token authentication to versioned WooCommerce Store API routes, matching WordPress rest_route dispatch precedence.
* Refuse to resolve a Cart-Token to any user holding administrative capabilities, and reject Cart-Token authenticated requests on plugin admin routes.
* Note: this includes `edit_posts`, which contributors and authors hold. On a site that runs a blog alongside the store, those shoppers keep their own cart, but the agent's requests are not authenticated as their account, so an order placed through the agent is not attached to it. Filter `gecx_cart_token_privileged_caps` to change the list.
* Pin the Store API issuer claim and verify the token signature before evaluating its claims. Both issuers WooCommerce has used are accepted (`wc/store` and `wc/store/v1` on 7.1-9.9, `store-api` on 10.0+). WooCommerce before 7.1 stamps no issuer at all and is no longer supported.
* Report refusals to `gecx_cart_token_refused`, and a signed token carrying an unrecognised issuer to `gecx_cart_token_unknown_issuer`, so neither can fail silently on a WooCommerce version that stamps something new.
* Refuse Cart-Token authenticated requests at REST dispatch when the resolved route is not a Store API route, rather than in individual permission callbacks.

= 0.2.9 =
* Refactor JWT signing to eliminate redundant header and signing input serialization.
* Add OpenSSL error logging on keypair generation and RS256 signing failures.
* Normalize REST API route parsing in WooCommerce custom endpoint authentication.

= 0.2.8 =
* Migrate webhook signing to use WooCommerce consumer_secret without storing shared secret in database.
* Expose dedicated POST /wp-json/gecx/v1/webhooks/order-created REST route.
* Validate webhook delivery URLs using wp_http_validate_url.
* Harden admin redirect parameter handling and REST authentication checks.

= 0.2.7 =
* Sign shopper customer JWTs and admin session JWTs using RS256 with the store's decrypted RSA private key.
* Gracefully fall back to HS256 HMAC signature if RSA private key is unavailable or signing fails.

= 0.2.6 =
* Generate 2048-bit RSA keypair on activation or lazy demand.
* Encrypt private key using AES-256-GCM derived from WordPress salts via HKDF-SHA256.
* Expose GET /wp-json/gecx/v1/public-key REST route with WooCommerce authentication.

= 0.2.5 =
* Fix OAuth return URL encoding by URL-encoding nested callback URLs to prevent query parameter splitting.
* Add dual-layer transient and persistent options storage for OAuth state tokens to ensure resilience with object caching.
* Center and widen the admin settings interface layout across screen sizes.

= 0.2.4 =
* Add support for Full-Site Editing (FSE) block themes via WooCommerce Single Product Gutenberg block filters.
* Add secondary hook and client-side DOM injection fallback for Page Builders (Elementor, Divi, Bricks, Oxygen).
* Add duplicate suppression to prevent multiple PDP prompt renders per request.
* Support queried object ID resolution outside the WordPress loop during asset enqueueing.

= 0.2.3 =
* Improve admin connection status page layout and eliminate text overflow.
* Add default placeholder labels for button configuration.
* Synchronize Suggested Prompts toggle dependency with Storefront Chat Widget state.
* Update Connection Status indicator and text on chat widget toggle.
* Fix raw API secret handling in webhook configuration.

= 0.2.2 =
* Add .gitattributes release packaging rules to exclude developer tools and tests from production archives.
* Add Changelog section to readme.txt for WordPress.org directory compliance.
* Add standard Requires at least and Requires PHP plugin header tags.

= 0.2.1 =
* Add launcher placement options (Navigation Menu vs Floating Bubble) and floating positions to native admin settings.
* Add launcher button style, custom full label, collapsed mobile short label, and shimmer animation toggles.
* Add AJAX save endpoint for button configuration with instant visual feedback.

= 0.2.0 =
* Replace iframe embed with native WordPress admin stepper onboarding flow and management dashboard.
* Add AJAX actions for manual config, chat widget toggle, PDP prompts toggle, and agent disconnect.
* Add OAuth linking redirect handler with transient state validation and token broker fallback.
