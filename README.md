# Gemini Enterprise for CX - WooCommerce Plugin

Drive sales with an AI agent that's already an expert on your brand and products. Go live instantly on your WooCommerce storefront.

## Description

Gemini Enterprise for CX integrates your WooCommerce store with Google's Gemini Enterprise for CX (GECX). It embeds a chat widget on your storefront, allowing customers to interact with an AI agent that can answer questions about products, store policies, and assist with adding items to the cart.

This plugin connects to the Gemini Enterprise for CX Software as a Service (SaaS) provided by Google Cloud.

## Installation & Setup

1. Upload the `gemini-enterprise-for-cx` folder to your `/wp-content/plugins/` directory, or install via the WordPress Plugins menu.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Marketing > Gemini Enterprise for CX** to complete onboarding and connect your store.

## Requirements

* WordPress 6.2+
* WooCommerce 7.1+
* PHP 7.4+

## Developer notes

### Keeping a theme's cart in step with the agent

After the agent changes the cart, the plugin refreshes WooCommerce cart fragments, updates the WooCommerce Blocks cart data store, and dispatches `wc-blocks_added_to_cart` or `wc-blocks_removed_from_cart`. Themes and side-cart plugins that need more can listen for the `gecx:cart-updated` event on `document.body`. Its `detail` carries `change` (`added`, `removed` or `updated`), `itemsCount`, `previousItemsCount` and the Store API `cart`.

These filters adjust the behavior:

* `gecx_enqueue_cart_fragments`: whether to load `wc-cart-fragments`. Defaults to true on classic themes and false on block themes.
* `gecx_cart_badge_selectors`: CSS selectors of cart badges to write the item count into when the Blocks cart data store is not on the page. Only list elements whose whole text is the number.
* `gecx_cart_refresh_native_events`: whether to dispatch the WooCommerce Blocks events. Default true.
* `gecx_cart_refresh_legacy_events`: whether to trigger the jQuery `added_to_cart` and `removed_from_cart` events in place of the native ones. Default false, because many themes open a side cart on them.

### Putting the browser on the agent's cart

A guest who has not added anything to their cart has no WooCommerce session cookie, so the agent can hold a cart the browser is not bound to, for example one the chat widget resumed from an earlier page view. A guest can also have a session whose cart is empty, which WooCommerce starts when the cart or checkout page is opened or prefetched, and the agent's changes do not reach that browser either. When the chat opens, and after each change the agent makes to the cart, the storefront script calls `POST /wp-json/gecx/v1/cart-session` with the agent's cart token (`cart_token`). After an agent change, the script reads the cart and dispatches `gecx:cart-updated` only once the route has answered. The route needs the `wp_rest` nonce and a same-origin request. It binds the browser to the agent's cart only when that cart holds items and the browser's own cart is empty, since setting a session cookie turns off full-page caching for the visitor and changes the `woocommerce-*` nonces already on the page. It answers `binding`:

* `agent`: the browser now uses the agent's cart. A token for that cart comes in the response's `Cart-Token` header, never in its body.
* `existing`: the browser keeps its own cart: a logged-in shopper, a guest whose cart holds items (the chat widget adopts it by itself), or a browser already on the agent's cart.
* `deferred`: nothing to bind yet; the script tries again the next time the chat opens or the agent changes the cart.
* `unsupported`: the store's session handler is not one known to keep sessions in the WooCommerce sessions table, where the agent's cart is, so the browser cannot be bound; the script stops asking for the rest of the page view.

An `agent` or `existing` answer holds for the rest of the page view, so the script asks once per cart session and reads later changes to that cart straight away. When the chat opens and the browser is put on the agent's cart, the script reads it and refreshes the page's cart as it does after an agent change, but without the events that open a mini-cart drawer or side cart. After each answer, the script dispatches `gecx:cart-session-bound` on `document.body`, with `binding` in its `detail`.

## License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.
