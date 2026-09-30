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

## License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.
