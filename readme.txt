=== Gemini Enterprise for CX ===
Contributors: google
Tags: woocommerce, ai, chatbot, ai agent, shopping assistant
Requires at least: 6.2
WC requires at least: 7.1
WC tested up to: 11.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI shopping assistant and chatbot for your WooCommerce store, from Google Cloud. Answers product questions, gives advice and adds items to the cart.

== Description ==

Gemini Enterprise for CX adds an AI shopping assistant to your WooCommerce store. Shoppers open a chat widget on your storefront and talk to an AI agent that knows your products and store policies, answers their questions and manages their cart. The agent runs on Google's Gemini Enterprise for CX (GECX) service.

The plugin is an interface to the Gemini Enterprise for CX software as a service (SaaS) provided by Google Cloud. Connecting a store requires a Google account with access to Gemini Enterprise for CX.

= What the agent does =

* Answers questions about your products and store policies, and checks stock before adding an item to the cart.
* Compares products and gives sizing advice in conversation.
* Adds and updates items in the shopper's cart. It does not apply discount codes.
* Answers "Where is my order?" questions.
* Keeps context across interactions, so it remembers a shopper's earlier product views, preferences and sizing details.

= Where shoppers find it =

* A launcher button in your header menu, a floating button, or wherever you place the `[gecx_agent_button]` shortcode or the launcher block.
* Suggested questions on product pages, placed automatically or with the `[gecx_suggested_prompts]` shortcode or block.

= Merchant console =

In the Gemini Enterprise for CX console you set the agent's greeting, tone and safety guardrails, test the agent before it goes live, measure its effect on conversion against your storefront traffic, and review trends in shopper conversations.

= Setup =

1. Activate the plugin and go to Marketing > Gemini Enterprise for CX.
2. Authorize the plugin to use the WooCommerce REST API.
3. Connect your store to Google Cloud, where the agent is built from your store's URL.
4. Customize and test the agent in the merchant console, then go live. The chat widget appears on your storefront once the agent is linked, and can be switched off on the plugin's settings page.

= Source code =

The plugin is developed on GitHub: https://github.com/GoogleCloudPlatform/gemini-enterprise-for-cx

== 3rd Party Services, External Assets, Privacy & Terms of Service ==

This plugin connects to 3rd-party services provided by Google to function, and loads one externally hosted script from Google rather than bundling it:

1. **Gemini Enterprise for CX Service & Onboarding Console**
   * **Service Provider:** Google LLC
   * **Endpoints:** `https://gecx.cloud.google.com`
   * **Purpose:** Merchant account authentication, store onboarding, agent configuration, and secure exchange of WooCommerce REST API credentials for authenticating to admin apis.
   * **Data Transmitted:** Store URL, admin authorization tokens, and product/catalog metadata. Admin authorization tokens are signed by the store and contain the store administrator's WordPress user ID and email address; they are sent when the store is connected, when its connection status is checked, when an agent is unlinked, and when the plugin is uninstalled from a connected store.
   * **Account Requirement:** A Google account with access to Gemini Enterprise for CX.
   * **Terms of Service:** https://cloud.google.com/terms and https://policies.google.com/terms
   * **Privacy Policy:** https://policies.google.com/privacy

2. **Storefront Chat Widget Client SDK (externally hosted script)**
   * **Service Provider:** Google LLC
   * **External source:** `https://www.gstatic.com/gecx/chat-widget/woocommerce-chat-widget.js`
   * **This file is NOT bundled with the plugin.** It is loaded at runtime from Google's servers at the URL above, directly by the visitor's browser. It is the only externally hosted asset this plugin loads; the widget stylesheet is bundled with the plugin (`assets/css/theme.css`) and is not fetched remotely.
   * **Purpose:** Delivers the client runtime required to render the interactive chat widget and product suggestion pills on the customer-facing storefront, and to communicate with the GECX service.
   * **When it loads:** Never by default. It is enqueued on storefront pages only after a store administrator has connected the store to Google Cloud, linked an agent, and enabled the agent in the plugin settings. If either condition is unmet, the script is not requested at all.
   * **Why it is hosted externally:** It is the official client runtime for the Gemini Enterprise for CX SaaS platform, versioned and released with that service rather than with this plugin. It speaks directly to the service's streaming, session and agent protocols, which change far more often than a WordPress plugin release cycle. A copy frozen inside the plugin would break storefronts for any merchant who did not update the plugin in step with every service change.
   * **Data Transmitted:** Standard HTTP request headers (IP address, User-Agent) when the browser fetches the script. Customer chat messages and product browsing context are processed by GECX during live chat interactions. For a shopper who is logged in to the store, the widget also sends GECX a token signed by the store that contains the shopper's WordPress user ID and email address; guests' tokens contain neither.
   * **Terms of Service:** https://cloud.google.com/terms
   * **Privacy Policy:** https://policies.google.com/privacy

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

= Are back-in-stock alerts available? =
Not yet. Back-in-stock alerts are planned for a future release of the service.

= Who can use Gemini Enterprise for CX? =
Access is currently by invitation. You can install the plugin without it, but connecting a store and linking an agent needs a Google account that has been given access.

= Does the plugin load any files from outside the plugin directory? =
Only the chat widget script from `www.gstatic.com`, and only on storefronts with a linked, enabled agent. The widget stylesheet ships with the plugin. See 3rd Party Services above.

= What data is sent to Google? =
The store URL and admin tokens during setup, and shoppers' chat messages and viewed products while they chat. The 3rd Party Services section above lists exactly what is sent and when.

= Where does the launcher appear, and what if my theme hides it? =
With the Top navigation menu placement, the launcher joins your header menu and your theme's mobile menu, and moves beside the menu (hamburger) button whenever your theme shows one. If it cannot find a place on screen, it appears as a floating button instead.

If it lands in the wrong menu, choose one under Launcher Placement > Menu. Menus built with page builders such as Elementor appear there too. To place it yourself, choose Manual and add the `[gecx_agent_button]` shortcode or the Gemini Enterprise for CX Launcher block where you want it, for example in a header template.

Themes that replace page content without a full page load can call `window.gecxInit()` afterwards. The plugin also watches for content added after the page loads.

= Can the chat widget match my theme's colors? =
The chat widget's colors, fonts and branding come from your agent's settings in the Gemini Enterprise for CX console, not from your WordPress theme. The widget applies that branding itself.

= The floating button covers part of my theme. =
The floating button moves up to clear bars your theme fixes to the bottom of the screen. To add more space, set the `--gecx-floating-extra-offset` CSS variable, for example `:root { --gecx-floating-extra-offset: 24px; }`.

= Does the plugin work with caching and optimization plugins? =
Yes. The launcher scripts are kept out of the JavaScript delay features of WP Rocket, LiteSpeed Cache and Cloudflare Rocket Loader automatically. With other optimization plugins, exclude `storefront.js`, `gstatic.com/gecx/` and `gecxStorefrontConfig` from delaying. To opt out of the automatic exclusions, return false from the `gecx_exclude_from_js_delay` filter.

= Are AMP pages, headless storefronts and custom page templates supported? =
AMP pages cannot run the chat widget, so the plugin outputs nothing on them. Headless storefronts served from a different origin are not supported, because the plugin only hands a shopper's session credentials to pages on the store's own origin. The launcher and widget load in the page footer, so custom page templates must call `wp_footer()`, as WordPress requires.

= How do I keep my theme's cart in step with the agent? =
After the agent changes the cart, the plugin refreshes WooCommerce cart fragments, updates the WooCommerce Blocks cart data store, and dispatches `wc-blocks_added_to_cart` or `wc-blocks_removed_from_cart`. Themes and side-cart plugins that need more can listen for the `gecx:cart-updated` event on `document.body` or use the filters documented in the [developer notes](https://github.com/GoogleCloudPlatform/gemini-enterprise-for-cx#developer-notes).

== Screenshots ==

1. Step 1: Authorize WooCommerce API permissions for the agent.
2. Step 2: Connect your store to Google Cloud and link your agent.
3. Manage agent connection status, storefront launcher placement, button style, and suggested prompts.

== Changelog ==

The complete release history is kept in changelog.txt at the plugin root.

= 1.0.1 =
* Admin JWTs now last 60 minutes instead of 5, so a merchant who leaves the Gemini Enterprise for CX console to build an agent can still link it when they return.

= 1.0.0 =
* First public release.
* Tighten how cart-token authentication decides that a request is for the WooCommerce Store API, and drop a cart-token login once WordPress has routed the request anywhere else.
