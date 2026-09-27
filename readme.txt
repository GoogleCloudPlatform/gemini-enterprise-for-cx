=== Gemini Enterprise for CX ===
Contributors: google
Tags: woocommerce, marketing, ai, agent, gecx
Requires at least: 6.2
WC requires at least: 7.1
WC tested up to: 11.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.24
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Drive sales with an AI agent that's already an expert on your brand and products. Go live instantly on your WooCommerce storefront.

== Description ==

Gemini Enterprise for CX integrates your WooCommerce store with Google's Gemini Enterprise for CX (GECX). It embeds a chat widget on your storefront, allowing customers to interact with an AI agent that can answer questions about products, store policies, and assist with adding items to the cart.

This plugin relies on the Gemini Enterprise for CX Software as a Service (SaaS) provided by Google Cloud. Connecting to this service requires a Google account.

== 3rd Party Services, External Assets, Privacy & Terms of Service ==

This plugin connects to 3rd-party services provided by Google to function, and loads one externally hosted script from Google rather than bundling it:

1. **Gemini Enterprise for CX Service & Onboarding Console**
   * **Service Provider:** Google LLC
   * **Endpoints:** `https://gecx.cloud.google.com`
   * **Purpose:** Merchant account authentication, store onboarding, agent configuration, and secure exchange of WooCommerce REST API credentials for authenticating to admin apis.
   * **Data Transmitted:** Store URL, admin authorization tokens, and product/catalog metadata.
   * **Account Requirement:** A Google account with access to Gemini Enterprise for CX.

2. **Storefront Chat Widget Client SDK (externally hosted script)**
   * **Service Provider:** Google LLC
   * **External source:** `https://www.gstatic.com/gecx/chat-widget/woocommerce-chat-widget.js`
   * **This file is NOT bundled with the plugin.** It is loaded at runtime from Google's servers at the URL above, directly by the visitor's browser. It is the only externally hosted asset this plugin loads; the widget stylesheet is bundled with the plugin (`assets/css/theme.css`) and is not fetched remotely.
   * **Purpose:** Delivers the client runtime required to render the interactive chat widget and product suggestion pills on the customer-facing storefront, and to communicate with the GECX service.
   * **When it loads:** Never by default. It is enqueued on storefront pages only after a store administrator has connected the store to Google Cloud, linked an agent, and enabled the agent in the plugin settings. If either condition is unmet, the script is not requested at all.
   * **Why it is hosted externally:** It is the official client runtime for the Gemini Enterprise for CX SaaS platform, versioned and released with that service rather than with this plugin. It speaks directly to the service's streaming, session and agent protocols, which change far more often than a WordPress plugin release cycle. A copy frozen inside the plugin would break storefronts for any merchant who did not update the plugin in step with every service change.
   * **Data Transmitted:** Standard HTTP request headers (IP address, User-Agent) when the browser fetches the script. Customer chat messages and product browsing context are processed by GECX during live chat interactions.
   * **Terms of Service:** https://cloud.google.com/terms
   * **Privacy Policy:** https://policies.google.com/privacy

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

= Does the plugin load any files from outside the plugin directory? =
Yes, one: `https://www.gstatic.com/gecx/chat-widget/woocommerce-chat-widget.js`, the chat widget client SDK, served by Google. It is not bundled with the plugin and is fetched by the visitor's browser at runtime, and only on storefront pages of stores that have connected an agent and enabled it. Everything else the plugin loads ships inside the plugin, including the widget stylesheet (`assets/css/theme.css`). See the 3rd Party Services and External Assets section above for the full disclosure.

= What external endpoints and CDNs are called? =
* `https://gecx.cloud.google.com` is used in the admin settings dashboard to manage your GECX agent and connect store APIs.
* `https://www.gstatic.com/gecx/chat-widget/woocommerce-chat-widget.js` is loaded on the customer storefront to provide the chat widget client SDK.

= What data is sent to Google? =
During merchant setup, store URL and WooCommerce API credentials are authenticated. During storefront usage, customer chat queries and viewed product context are processed to return relevant answers. Standard request headers (such as IP address) are processed by Google's infrastructure in accordance with the Google Privacy Policy.

= Where does the launcher appear, and what if my theme hides it? =
With the Top navigation menu placement, the launcher joins your header menu and your theme's mobile menu, and moves beside the menu (hamburger) button whenever your theme shows one. If it cannot find a place on screen, it appears as a floating button instead.

If it lands in the wrong menu, choose one under Launcher Placement > Menu. Menus built with page builders such as Elementor appear there too. To place it yourself, choose Manual and add the `[gecx_agent_button]` shortcode or the Gemini Enterprise for CX Launcher block where you want it, for example in a header template.

Themes that replace page content without a full page load can call `window.gecxInit()` afterwards. The plugin also watches for content added after the page loads.

= The floating button covers part of my theme. =
The floating button moves up to clear bars your theme fixes to the bottom of the screen. To add more space, set the `--gecx-floating-extra-offset` CSS variable, for example `:root { --gecx-floating-extra-offset: 24px; }`.

= Does the plugin work with caching and optimization plugins? =
Yes. The launcher scripts are kept out of the JavaScript delay features of WP Rocket, LiteSpeed Cache and Cloudflare Rocket Loader automatically. With other optimization plugins, exclude `storefront.js`, `gstatic.com/gecx/` and `gecxStorefrontConfig` from delaying. To opt out of the automatic exclusions, return false from the `gecx_exclude_from_js_delay` filter.

= Are AMP pages, headless storefronts and custom page templates supported? =
AMP pages cannot run the chat widget, so the plugin outputs nothing on them. Headless storefronts served from a different origin are not supported, because the plugin only hands a shopper's session credentials to pages on the store's own origin. The launcher and widget load in the page footer, so custom page templates must call `wp_footer()`, as WordPress requires.

= How do I keep my theme's cart in step with the agent? =
After the agent changes the cart, the plugin refreshes WooCommerce cart fragments, updates the WooCommerce Blocks cart data store, and dispatches `wc-blocks_added_to_cart` or `wc-blocks_removed_from_cart`. Themes and side-cart plugins that need more can listen for the `gecx:cart-updated` event on `document.body`. Its `detail` carries `change` (`added`, `removed` or `updated`), `itemsCount`, `previousItemsCount` and the Store API `cart`.

These filters adjust the behavior:

* `gecx_enqueue_cart_fragments`: whether to load `wc-cart-fragments`. Defaults to true on classic themes and false on block themes.
* `gecx_cart_badge_selectors`: CSS selectors of cart badges to write the item count into when the Blocks cart data store is not on the page. Only list elements whose whole text is the number.
* `gecx_cart_refresh_native_events`: whether to dispatch the WooCommerce Blocks events. Default true.
* `gecx_cart_refresh_legacy_events`: whether to trigger the jQuery `added_to_cart` and `removed_from_cart` events in place of the native ones. Default false, because many themes open a side cart on them.

= Do I need an account to use this plugin? =
Yes. You need a Google account with access to Gemini Enterprise for CX to configure and activate the agent on your store.

== Screenshots ==

1. Step 1: Authorize WooCommerce API permissions for the agent.
2. Step 2: Connect your store to Google Cloud and link your agent.
3. Manage agent connection status, storefront launcher placement, button style, and suggested prompts.

== Changelog ==

The complete release history is kept in changelog.txt at the plugin root.

= 0.3.24 =
* Decide between the in-menu launcher and the mobile header launcher from whether the theme's hamburger is visible, not from fixed 600px and 768px breakpoints, so themes that switch at 921px or 1024px (Astra, Kadence, Divi, OceanWP) and block navigation with the overlay always on no longer lose the launcher.
* Recognize more hamburgers (links and ARIA buttons in the header, Flatsome, Avada, OceanWP, Blocksy, Woodmart), and show a floating launcher whenever no other launcher is on screen.
* Place the launcher in desktop and mobile drawer menus separately, add common theme location names (`main_menu`, `main_navigation`, `menu_1`, `handheld`, `mobile_menu` and others), cover the page list WordPress shows when no menu is assigned, and choose the main header menu over top bar menus when placing from JavaScript.
* Add a Menu setting to pick a theme location or a menu, which also reaches page builder menus rendered without a location, and a Manual placement with a `[gecx_agent_button]` shortcode and a Gemini Enterprise for CX Launcher block.
* Style the menu launcher with zero-specificity rules and no inline styles, so vertical, off-canvas and mega menus keep their own layout; remove launcher items that end up in footers; and insert into a block navigation's own list instead of after its last list.
* Place product prompts after the main add-to-cart form rather than a sticky add-to-cart bar, quick view or related product, add Bricks, Avada and Oxygen targets, and place them on pages that embed a product with `[product_page]` or the Single Product block.
* Re-run placement when content is added after load (late headers, page transitions, infinite scroll) and expose `window.gecxInit()` for themes that swap content themselves.
* Lift floating launchers above bars themes fix to the bottom of the screen and clear the device safe area; merchants can add spacing with the `--gecx-floating-extra-offset` CSS variable. Narrow fixed headers while the chat panel pushes the page aside.
* Add a Match theme styles setting that takes the chat widget's primary color, font and dark mode from the theme.
* Output nothing on AMP pages, and keep the launcher scripts out of WP Rocket, LiteSpeed Cache and Cloudflare Rocket Loader JavaScript delay (filter: `gecx_exclude_from_js_delay`).
* Fix the Defer widget until interaction setting, which never deferred because WordPress localizes `false` as an empty string.
* Add browser tests: placement fixtures modeled on theme markup, and a wp-env theme matrix covering Storefront, Astra, Kadence, OceanWP and Twenty Twenty-Five.

= 0.3.23 =
* Refresh every cart surface once when the agent changes the cart. `chat-messenger-update-cart` is handled a single time even when it reaches both `document` and `window`, the cart is read from the Store API on every theme, and a stale nonce is replaced and the read retried once after a 401 or 403.
* Dispatch the WooCommerce Blocks `wc-blocks_added_to_cart` or `wc-blocks_removed_from_cart` event, chosen by whether the item count went up or down, so the block mini-cart refreshes its cart. The jQuery `added_to_cart` event, which many themes answer by opening a side cart, is no longer triggered unless the `gecx_cart_refresh_legacy_events` filter returns true. When it does, `added_to_cart` or `removed_from_cart` is triggered in place of the native event, which the block mini-cart derives from it.
* Always trigger `wc_fragment_refresh`, and enqueue `wc-cart-fragments` on classic themes (filterable with `gecx_enqueue_cart_fragments`), so classic header cart counts update without a reload.
* Refresh the browser's `woocommerce_items_in_cart` and `woocommerce_cart_hash` cookies when it reads its own cart, so full-page caches stop serving cached pages with an empty cart and cart fragments stop reusing a stale header.
* Write the item count into cart badges only when the WooCommerce Blocks cart data store is not on the page, using selectors from the new `gecx_cart_badge_selectors` filter.
* Refresh classic cart and checkout forms only when they are on the page, and never reload the checkout page.
* Dispatch a documented `gecx:cart-updated` event on `document.body` after each agent cart change.

= 0.3.22 =
* Pass `admin_jwt` in the URL fragment (`#admin_jwt=`) instead of the query string when redirecting to the Google Cloud onboarding console, so the token is not sent to the console server, written to its access logs, or leaked via `Referer`.
* Send `admin_jwt` to the SyncState and UnlinkAgent console endpoints in an `Authorization: Bearer` header instead of the JSON request body, so the token is not captured by anything that logs or persists request bodies.

= 0.3.21 =
* Revoke the WooCommerce REST API keys issued through `/wc-auth/v1/authorize` on uninstall. Keys are matched on the plugin's wc-auth description and `read_write` permission. Unlinking an agent keeps the keys and store authorization, so the merchant returns to Step 2.
* Respect merchant intent in SyncState. An explicit unlink sets `gecx_merchant_unlinked`, which stops SyncState from adopting a reported agent until the backend first reports no link, the merchant completes the connect flow, or an agent is linked via `link-agent`. Credential errors are still reported while it is set. Switching the widget off sets `gecx_merchant_disabled`, and an adopted binding then leaves the widget and order webhook off.
* Send store-signed JWTs, the connect redirect, webhook deliveries and the uninstall notification only to an `https` origin on an allowed console host (`gecx.cloud.google.com`, extendable with the `GECX_CONSOLE_ALLOWED_HOSTS` constant). A refused `gecx_console_base_url` fails closed, and unlink then completes locally. `GECX_Rest_API::ensure_order_webhook()` no longer accepts a delivery URL.
* Notify Google on uninstall only when the store was connected (linked agent, order webhook, or completed authorization), not merely because activation generated a keypair.
* Write a `gecx_session_id` session row only for an HMAC-verified WooCommerce session cookie that matches the session, a verified Cart-Token, or a logged-in user.

= 0.3.20 =
* Accept `omnichannelSessions` resource names alongside `commerceSessions` when saving sessions and gating order webhook delivery.
* Expose `_gecx_session_id` on WooCommerce HPOS REST order responses via `woocommerce_rest_prepare_shop_order_object`, and read per-product suggested prompts overrides through `WC_Product::get_meta()` with `get_post_meta()` fallback.
* Parse the numeric timestamp prefix of `gecx_last_sync_attempt` explicitly with `strtok()`, and harden `uninstall.php` session cleanup with table-existence checks and keyset pagination.
* Require pretty permalinks and the default `/wp-json` REST API prefix on the settings screen before enabling store authorization and Google Cloud connection.

= 0.3.19 =
* Restrict `/wp-json/gecx/v1/auth-context` to `POST`. `GET` was registered only for widget bundles that predate the switch to `POST`, and a `GET` response can be served from a CDN edge configured to cache everything, handing one shopper's nonce and customer JWT to the next.
* Stop accepting `Sec-Fetch-Site: none` on `/wp-json/gecx/v1/auth-context`. A browser only sends `none` for a user-initiated load with no initiator document, which a `POST`-only route cannot receive, so the header is now required to say `same-origin`.
* Require a WordPress-resolved `rest_route` (`$GLOBALS['wp']->query_vars['rest_route']`) in `GECX_Auth::is_request_to_route()` instead of inferring the route from unparsed superglobals (`$_POST`, `$_GET`, `$_SERVER['REQUEST_URI']`) before `WP::parse_request()` has run. WooCommerce's `WC_REST_Authentication::authentication_fallback()` re-triggers user determination inside `WP_REST_Server::serve_request()` after `WP::parse_request()` has populated `query_vars['rest_route']` on both pretty and plain permalinks.
* Document the chat widget client SDK as an externally hosted script in readme.txt, covering its source URL, the fact that it is not bundled, the conditions under which it loads, and why it is not shipped with the plugin.

= 0.3.18 =
* Support Cart-Token capture and session rebind on plain-permalink WordPress stores.
* Soft-ignore invalid or expired cart tokens during session save while strictly blocking privileged endpoints under cart-token authentication.
* Cache guest JWTs using public key fingerprinting and single-pass keypair retrieval to avoid redundant asymmetric decryption.
* Improve uninstall cleanup by bounding session table sweeps and ensuring clean termination even with corrupt session data.
* Update WordPress.org directory assets and screenshot documentation.

= 0.3.17 =
* Add Bottom Right, Bottom Left, and Middle Left options for the floating launcher position.

= 0.3.16 =
* Add `gecx_should_load_widget` filter, `gecx_defer_widget_until_interaction` option, and `window.gecxLoadWidget` / `gecx:consent-granted` API for consent gating and interaction-deferred widget script loading.
* Mint the OAuth state and `admin_jwt` on demand via `admin-post.php?action=gecx_connect_agent` POST instead of during settings page GET rendering.
* Enforce subdirectory multisite `Referer` and blog-membership isolation on `/wp-json/gecx/v1/auth-context`, serve a guest identity (rather than a 403) when the `Referer` names no subsite path, and gate widened WooCommerce API key capabilities until `rest_pre_dispatch`.
* Persist uncookied guest `gecx_session_id` bindings via a first-party HttpOnly cookie without invalidating `wp_rest` nonces, and refresh cart/checkout surfaces without full-page reloads when WooCommerce Blocks or jQuery is available.
* Schedule version-upgrade SyncState reconciliation asynchronously via Action Scheduler or WP-Cron (with synchronous fallback under `DISABLE_WP_CRON`), and clear the scheduled hook on deactivation and uninstall.
* Load the plugin text domain and register script translations, write the product prompt override through the WooCommerce product CRUD, declare argument schemas on the plugin's REST routes, and honour `prefers-reduced-motion`.

= 0.3.15 =
* Avoid forcing guest WooCommerce session cookies on non-mutating REST requests and empty guest carts, preventing guest nonce invalidation on account and registration pages.
* Bridge Store API `Cart-Token` to browser cookie when non-empty guest carts are fetched, and forward `Cart-Token` from storefront fetch calls to `/gecx/v1/session` for order attribution.
* Restrict the session cookie bridge to WooCommerce guest session keys, so a `Cart-Token` minted before the shopper logged out can no longer write a numeric customer ID that WooCommerce would reject and destroy that user's saved cart over.
* Use core-compatible HMAC MD5 hash and double-pipe delimiter for guest session cookies.

= 0.3.14 =
* Resolve the Store API cart endpoint from `rest_url()` instead of assuming `/wp-json/`, so the storefront script reaches the cart on subdirectory installs, plain permalinks, and stores with a renamed REST prefix.
* Decide whether to reload after an agent cart update with `is_cart()` and `is_checkout()` rather than matching `/cart` anywhere in the path, which reloaded product pages such as `/product/cartridge-filter/` and missed localized cart slugs.
* Validate the agent resource name and token broker reported by SyncState against the same allowlist the link endpoint applies before storing either.
* Resolve the REST route from the request URI without `sanitize_text_field()`, so a double-encoded separator can no longer be stripped into a route name the plugin would act on.
* Send the SyncState and unlink requests with `wp_safe_remote_post()` and no redirect following, so the store-signed admin JWT in the body cannot be handed to a redirect target.

= 0.3.13 =
* Reference the chat widget script by its full URL in the readme, replacing a bare `www.gstatic.com` origin that returned a 404.
* Localize the "Settings" plugin action link and the suggested-prompts placeholder.
* Resolve the REST route and the `Origin`, `Referer` and `Sec-Fetch-Site` headers without `sanitize_text_field()`, so the plugin matches the same bytes WordPress dispatches and compares the origin actually sent.

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

Releases before 0.3.10 are listed in changelog.txt at the plugin root.
