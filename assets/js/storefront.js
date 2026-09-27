/**
 * Copyright 2026 Google LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * @fileoverview Gemini Enterprise for CX Storefront JS
 * @suppress {missingProperties}
 */
(function() {
'use strict';

/** @type {string} Latest Store API Cart-Token observed from cart responses or events. */
let gecxLatestCartToken = '';

/**
 * Checks whether a candidate Cart-Token is a syntactically valid 3-part JWT.
 * @param {*} token
 * @return {boolean}
 */
function gecxIsValidCartTokenFormat(token) {
  return typeof token === 'string' &&
      token.length <= 4096 &&
      /^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/.test(token);
}

/**
 * Frozen snapshot of window.gecxStorefrontConfig captured at script evaluation
 * time so post-load mutations to the global object cannot alter REST URLs or
 * widget markup injected on DOMContentLoaded / resize.
 * @type {?Object}
 */
const gecxInitialStorefrontConfig = (function() {
  const raw = (typeof window !== 'undefined' && window.gecxStorefrontConfig) ?
      window.gecxStorefrontConfig :
      (typeof gecxStorefrontConfig !== 'undefined' ? gecxStorefrontConfig : null);
  if (!raw || typeof raw !== 'object') {
    return null;
  }
  const copy = Object.assign({}, raw);
  if (typeof Object.freeze === 'function') {
    try {
      Object.freeze(raw);
      Object.freeze(copy);
    } catch (err) {
      // Ignore freeze failure on non-extensible host objects.
    }
  }
  return copy;
})();

/**
 * Returns the frozen storefront configuration object.
 * @return {?Object}
 */
function gecxGetStorefrontConfig() {
  if (gecxInitialStorefrontConfig) {
    return gecxInitialStorefrontConfig;
  }
  if (typeof window !== 'undefined' && window.gecxStorefrontConfig) {
    return window.gecxStorefrontConfig;
  }
  if (typeof gecxStorefrontConfig !== 'undefined') {
    return gecxStorefrontConfig;
  }
  return null;
}

/**
 * The Store API cart endpoint for this store.
 *
 * Read from the localized config, which is built with rest_url() and so is
 * correct on subdirectory installs, plain permalinks, and stores that have
 * changed the REST url prefix. The literal is only a last resort for a page
 * that somehow loaded this script without its config.
 * @return {string}
 */
function gecxCartRestUrl() {
  const config = gecxGetStorefrontConfig();
  if (config && config.cartRestUrl) {
    return config.cartRestUrl;
  }
  return '/wp-json/wc/store/v1/cart';
}

/**
 * The GECX session REST endpoint for this store.
 * @return {string}
 */
function gecxSessionRestUrl() {
  const config = gecxGetStorefrontConfig();
  if (config && config.sessionUrl) {
    return config.sessionUrl;
  }
  if (config && config.authContextUrl) {
    return config.authContextUrl.replace(/\/auth-context(\b|\/|$)/, '/session$1');
  }
  return '/wp-json/gecx/v1/session';
}

/**
 * Checks whether a URL resolves to the current window's origin.
 * @param {string} candidateUrl
 * @return {boolean}
 */
function gecxIsSameOriginUrl(candidateUrl) {
  if (!candidateUrl || typeof candidateUrl !== 'string') {
    return false;
  }
  if (!window.location || !window.location.origin) {
    return candidateUrl.charAt(0) === '/' && candidateUrl.charAt(1) !== '/';
  }
  try {
    const resolved = new URL(candidateUrl, window.location.origin);
    return resolved.origin === window.location.origin;
  } catch (err) {
    return false;
  }
}

const gecxParsedTargetCache = {};

/**
 * Returns the lazily-parsed REST endpoint target for a configured URL string.
 * @param {string} configuredUrl
 * @return {?{origin: string, route: ?string, normPath: string}}
 */
function gecxGetParsedTarget(configuredUrl) {
  if (!configuredUrl || typeof configuredUrl !== 'string') {
    return null;
  }
  if (Object.prototype.hasOwnProperty.call(gecxParsedTargetCache, configuredUrl)) {
    return gecxParsedTargetCache[configuredUrl];
  }
  try {
    const baseOrigin = (window.location && window.location.origin) ?
        window.location.origin :
        'https://localhost';
    const target = new URL(configuredUrl, baseOrigin);
    const targetRoute = target.searchParams.get('rest_route');
    const parsed = {
      origin: target.origin,
      route: targetRoute ? targetRoute.replace(/\/+$/, '') : null,
      normPath: target.pathname.replace(/\/+$/, ''),
    };
    gecxParsedTargetCache[configuredUrl] = parsed;
    return parsed;
  } catch (err) {
    gecxParsedTargetCache[configuredUrl] = null;
    return null;
  }
}

/**
 * Checks whether a same-origin request URL targets a specific REST endpoint.
 * @param {string} candidateUrl
 * @param {string} configuredUrl
 * @param {!RegExp} fallbackPathRegex
 * @return {boolean}
 */
function gecxIsTargetEndpoint(candidateUrl, configuredUrl, fallbackPathRegex) {
  if (!gecxIsSameOriginUrl(candidateUrl)) {
    return false;
  }
  try {
    const baseOrigin = (window.location && window.location.origin) ?
        window.location.origin :
        'https://localhost';
    const candidate = new URL(candidateUrl, baseOrigin);
    if (configuredUrl) {
      const target = gecxGetParsedTarget(configuredUrl);
      if (target) {
        if (candidate.origin !== target.origin) {
          return false;
        }
        if (target.route !== null) {
          const candidateRoute = candidate.searchParams.get('rest_route') || '';
          return candidateRoute.replace(/\/+$/, '') === target.route;
        }
        const normCandidate = candidate.pathname.replace(/\/+$/, '');
        if (normCandidate === target.normPath || normCandidate.indexOf(target.normPath + '/') === 0) {
          return true;
        }
      }
    }
    return fallbackPathRegex.test(candidate.pathname);
  } catch (err) {
    return false;
  }
}

if (typeof window !== 'undefined' && typeof window.fetch === 'function') {
  const originalFetch = window.fetch;
  window.fetch = function(input, init) {
    const url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
    // Fast path: avoid URL parsing on requests that cannot be the session endpoint.
    if (gecxLatestCartToken &&
        url &&
        (url.indexOf('session') !== -1 || url.indexOf('gecx') !== -1) &&
        gecxIsTargetEndpoint(url, gecxSessionRestUrl(), /\/gecx\/v1\/session(\b|$)/)) {
      init = Object.assign({}, init);
      if (typeof Headers !== 'undefined') {
        const headers = new Headers(init.headers || {});
        if (!headers.has('Cart-Token')) {
          headers.set('Cart-Token', gecxLatestCartToken);
        }
        init.headers = headers;
      } else {
        const headers = Object.assign({}, init.headers || {});
        let hasToken = false;
        for (const key in headers) {
          if (Object.prototype.hasOwnProperty.call(headers, key) && key.toLowerCase() === 'cart-token') {
            hasToken = true;
            break;
          }
        }
        if (!hasToken) {
          headers['Cart-Token'] = gecxLatestCartToken;
        }
        init.headers = headers;
      }
    }
    return originalFetch.call(this, input, init).then(function(response) {
      try {
        if (response && response.headers &&
            gecxIsTargetEndpoint(url, gecxCartRestUrl(), /\/wc\/store\/v\d+\/cart(\b|\/|$)/)) {
          const token = (typeof response.headers.get === 'function') ?
              (response.headers.get('Cart-Token') || response.headers.get('cart-token')) :
              '';
          if (gecxIsValidCartTokenFormat(token)) {
            gecxLatestCartToken = token;
          }
        }
      } catch (err) {
        // Ignore header inspection errors on opaque responses.
      }
      return response;
    });
  };
}

/**
 * Whether the shopper is on the cart or checkout page, which are the only
 * pages whose server-rendered markup goes stale when the agent edits the cart.
 *
 * WordPress answers this with is_cart()/is_checkout(), which respect the
 * store's configured page IDs and therefore localized slugs such as /panier.
 * The fallback requires a whole path segment so that a product URL like
 * /product/cartridge-filter/ no longer triggers a reload mid-conversation.
 * @return {boolean}
 */
function gecxIsCartOrCheckout() {
  const config = gecxGetStorefrontConfig();
  if (config && typeof config.isCartOrCheckout !== 'undefined') {
    return !!config.isCartOrCheckout;
  }
  if (!window.location || !window.location.pathname) {
    return false;
  }
  return /(^|\/)(cart|checkout)(\/|$)/.test(window.location.pathname);
}

/**
 * Whether the shopper is on the checkout page.
 * @return {boolean}
 */
function gecxIsCheckout() {
  const config = gecxGetStorefrontConfig();
  if (config && typeof config.isCheckout !== 'undefined') {
    return !!config.isCheckout;
  }
  return !!(
      window.location && window.location.pathname &&
      /(^|\/)checkout(\/|$)/.test(window.location.pathname));
}

/**
 * Whether the shopper is on the cart page.
 * @return {boolean}
 */
function gecxIsCart() {
  const config = gecxGetStorefrontConfig();
  if (config && typeof config.isCart !== 'undefined') {
    return !!config.isCart;
  }
  return !!(
      window.location && window.location.pathname &&
      /(^|\/)cart(\/|$)/.test(window.location.pathname));
}

/**
 * Refreshes the server-rendered cart and checkout forms of classic themes.
 *
 * Only forms that are actually on the page are refreshed. `update_checkout`
 * and `wc_update_cart` mean nothing to block checkout and block cart, which
 * are refreshed through the wc/store/cart data store instead.
 *
 * A classic cart page without jQuery is reloaded as a last resort. Checkout
 * never is: a reload throws away everything the shopper has typed into it.
 * @param {boolean} hasBlockStore Whether the wc/store/cart data store received the new cart.
 */
function gecxRefreshCartOrCheckoutSurface(hasBlockStore) {
  if (!gecxIsCartOrCheckout()) {
    return;
  }
  if (window.jQuery && window.jQuery(document.body).trigger) {
    const body = window.jQuery(document.body);
    if (gecxIsCheckout() && document.querySelector('form.checkout')) {
      body.trigger('update_checkout');
    }
    if (gecxIsCart() && document.querySelector('.woocommerce-cart-form')) {
      body.trigger('wc_update_cart');
    }
    return;
  }
  if (!hasBlockStore && gecxIsCart() && !gecxIsCheckout() &&
      window.location && typeof window.location.reload === 'function') {
    window.location.reload();
  }
}

/**
 * Re-binds the active GECX session ID from localStorage to the WooCommerce
 * session once a cart mutation establishes a persistent session.
 */
function gecxRebindSessionOnCartUpdate() {
  let sessionId = '';
  try {
    sessionId = (window.localStorage && window.localStorage.getItem('gecx_session_id')) || '';
  } catch (err) {
    return;
  }
  if (!sessionId) {
    return;
  }
  const sessionUrl = gecxSessionRestUrl();

  gecxResolveRestNonce()
      .then(function(nonce) {
        if (!nonce) {
          return;
        }
        return fetch(sessionUrl, {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': nonce
          },
          body: JSON.stringify({session_id: sessionId})
        });
      })
      .catch(function(err) {
        gecxReportError('session rebind', err);
      });
}

/**
 * Reports a failure that would otherwise be invisible.
 *
 * Every fetch below used to end in an empty catch, so a cart that failed to
 * refresh looked identical to one that refreshed correctly, both to the
 * shopper and to anyone reading a console log afterwards.
 * @param {string} context What was being attempted.
 * @param {*} err The rejection value.
 */
function gecxReportError(context, err) {
  if (window.console && window.console.warn) {
    window.console.warn('[gecx] ' + context + ' failed:', err);
  }
}

/** @type {?Promise<string>} In-flight or settled nonce lookup for this page view. */
let gecxRestNoncePromise = null;

/**
 * Resolves a REST nonce valid for this shopper.
 *
 * Fetched rather than read from the page. Storefront HTML is cached, so any
 * nonce rendered into it expires while the cached copy is still being served,
 * which is what made the Store API answer 403 for logged-in shoppers. The
 * chat widget resolves its own nonce the same way.
 *
 * Caches the in-flight Promise so concurrent cart updates share one request,
 * and clears the cached Promise on failure so a subsequent cart update can
 * retry instead of being stuck with an empty nonce for the lifetime of the
 * page view.
 * @return {!Promise<string>}
 */
function gecxResolveRestNonce() {
  if (null !== gecxRestNoncePromise) {
    return gecxRestNoncePromise;
  }

  const config = gecxGetStorefrontConfig();
  const url = (config && config.authContextUrl) ? config.authContextUrl : '';
  if (!url) {
    gecxRestNoncePromise = Promise.resolve('');
    return gecxRestNoncePromise;
  }

  gecxRestNoncePromise =
      fetch(url, {
        method: 'POST',
        credentials: 'include',
        referrerPolicy: 'same-origin',
        headers: {'Content-Type': 'application/json'}
      })
          .then(function(res) {
            if (!res.ok) {
              throw new Error('auth-context responded ' + res.status);
            }
            return res.json();
          })
          .then(function(data) {
            return (data && data.nonce) ? data.nonce : '';
          })
          .catch(function(err) {
            gecxReportError('nonce lookup', err);
            gecxRestNoncePromise = null;
            return '';
          });

  return gecxRestNoncePromise;
}

/** @const {!Array<string>} Badge selectors used when the config names none. */
const GECX_DEFAULT_BADGE_SELECTORS = [
  '.wc-block-mini-cart__badge',
  '.wc-block-components-mini-cart__badge'
];

/**
 * Cart refresh settings from the localized config.
 *
 * They are nested under `cartRefresh` rather than set at the top level
 * because wp_localize_script() casts top-level scalars to strings, which
 * turns `false` into `""`. Nested values reach the page with their types.
 * @return {{nativeEvents: boolean, legacyEvents: boolean, badgeSelectors: !Array<string>}}
 */
function gecxCartRefreshConfig() {
  const config = gecxGetStorefrontConfig();
  const raw = (config && config.cartRefresh && typeof config.cartRefresh === 'object') ?
      config.cartRefresh :
      {};
  const selectors = Array.isArray(raw.badgeSelectors) ?
      raw.badgeSelectors.filter(function(selector) {
        return typeof selector === 'string' && selector !== '';
      }) :
      GECX_DEFAULT_BADGE_SELECTORS;
  return {
    nativeEvents: raw.nativeEvents !== false,
    legacyEvents: raw.legacyEvents === true,
    badgeSelectors: selectors
  };
}

/**
 * The wc/store/cart data store, when WooCommerce Blocks has registered it on
 * this page.
 *
 * wp.data being present says nothing about this store: classic themes load
 * wp.data for unrelated blocks, and the block mini-cart registers the cart
 * store lazily, on first interaction.
 * @return {?{select: !Object, dispatch: !Object}}
 */
function gecxGetCartDataStore() {
  const data = window.wp && window.wp.data;
  if (!data || typeof data.select !== 'function' || typeof data.dispatch !== 'function') {
    return null;
  }
  try {
    const select = data.select('wc/store/cart');
    const dispatch = data.dispatch('wc/store/cart');
    if (!select || !dispatch || typeof dispatch.receiveCart !== 'function') {
      return null;
    }
    return {select: select, dispatch: dispatch};
  } catch (err) {
    return null;
  }
}

/**
 * Number of items in a Store API cart response or data store cart.
 * @param {*} cart
 * @return {?number}
 */
function gecxCartItemsCount(cart) {
  if (!cart || typeof cart !== 'object') {
    return null;
  }
  const raw = (cart.items_count !== undefined) ? cart.items_count : cart.itemsCount;
  if (raw === undefined || raw === null || raw === '') {
    return null;
  }
  const count = Number(raw);
  return isNaN(count) ? null : count;
}

/** @type {?number} Items count after the last cart update this page handled. */
let gecxLastItemsCount = null;

/**
 * Items count before the update being handled, or null when unknown.
 * @param {?{select: !Object, dispatch: !Object}} store
 * @return {?number}
 */
function gecxPreviousItemsCount(store) {
  if (store && typeof store.select.getCartData === 'function') {
    try {
      const count = gecxCartItemsCount(store.select.getCartData());
      if (count !== null) {
        return count;
      }
    } catch (err) {
      // Fall through to the count this page last saw.
    }
  }
  return gecxLastItemsCount;
}

/**
 * Whether a cart update added items, removed them, or neither.
 * @param {?number} previous
 * @param {?number} current
 * @return {string} 'added', 'removed' or 'updated'.
 */
function gecxClassifyCartChange(previous, current) {
  if (current === null) {
    return 'updated';
  }
  if (previous === null) {
    return current > 0 ? 'added' : 'removed';
  }
  if (current > previous) {
    return 'added';
  }
  if (current < previous) {
    return 'removed';
  }
  return 'updated';
}

/**
 * Fetches the shopper's cart from the Store API.
 *
 * The request comes from the browser, with its WooCommerce session cookie,
 * which is what lets the server refresh the woocommerce_items_in_cart and
 * woocommerce_cart_hash cookies after the agent changed the cart from
 * elsewhere. A 401 or 403 usually means the nonce went stale during a long
 * chat, so a fresh one is fetched and the request retried once.
 * @param {string} cartToken Cart-Token supplied by the widget, or ''.
 * @return {!Promise<!Object>}
 */
function gecxFetchCart(cartToken) {
  const attempt = function(isRetry) {
    return gecxResolveRestNonce()
        .then(function(nonce) {
          // wp.apiFetch.nonceMiddleware is seeded with whatever nonce was
          // baked into cached storefront HTML. WooCommerce Blocks reads the
          // cart through it, so it has to carry the fresh nonce too.
          if (nonce && window.wp && window.wp.apiFetch &&
              window.wp.apiFetch.nonceMiddleware) {
            window.wp.apiFetch.nonceMiddleware.nonce = nonce;
          }
          const headers = {'Accept': 'application/json'};
          if (cartToken) {
            headers['Cart-Token'] = cartToken;
          }
          if (nonce) {
            headers['X-WP-Nonce'] = nonce;
          }
          return fetch(gecxCartRestUrl(), {
            method: 'GET',
            headers: headers,
            credentials: 'include'
          });
        })
        .then(function(res) {
          if (!isRetry && (res.status === 401 || res.status === 403)) {
            gecxRestNoncePromise = null;
            return attempt(true);
          }
          if (!res.ok) {
            throw new Error('cart responded ' + res.status);
          }
          return res.json();
        });
  };
  return attempt(false);
}

/**
 * Writes the items count into cart badges directly.
 *
 * Only used when the wc/store/cart data store is not on the page to do it.
 * Classic theme headers are refreshed through cart fragments instead, since
 * their count markup (such as "3 items") is not a bare number. Themes whose
 * badge is a bare number can add it with the gecx_cart_badge_selectors filter.
 * @param {?number} count
 */
function gecxUpdateCartBadges(count) {
  if (count === null) {
    return;
  }
  const badges = [];
  const selectors = gecxCartRefreshConfig().badgeSelectors;
  for (let i = 0; i < selectors.length; i++) {
    let matches = [];
    try {
      matches = document.querySelectorAll(selectors[i]);
    } catch (err) {
      continue;
    }
    for (let j = 0; j < matches.length; j++) {
      if (badges.indexOf(matches[j]) === -1) {
        badges.push(matches[j]);
      }
    }
  }

  if (!badges.length && count > 0) {
    // The block mini-cart omits its badge while the cart is empty.
    const wrapper = document.querySelector(
        '.wc-block-mini-cart__quantity-badge, .wc-block-mini-cart__button, .wc-block-components-mini-cart__button, .wc-block-mini-cart button');
    if (wrapper) {
      const badge = document.createElement('span');
      badge.className = 'wc-block-mini-cart__badge';
      wrapper.appendChild(badge);
      badges.push(badge);
    }
  }

  for (let i = 0; i < badges.length; i++) {
    badges[i].textContent = String(count);
    if (count > 0) {
      badges[i].removeAttribute('hidden');
      badges[i].style.display = '';
    } else {
      badges[i].setAttribute('hidden', '');
      badges[i].style.display = 'none';
    }
  }
}

/**
 * Dispatches a DOM event on document.body.
 * @param {string} name
 * @param {!Object} detail
 */
function gecxDispatchBodyEvent(name, detail) {
  if (typeof CustomEvent !== 'function' || !document.body) {
    return;
  }
  document.body.dispatchEvent(new CustomEvent(name, {
    bubbles: true,
    cancelable: true,
    detail: detail
  }));
}

/**
 * Tells the rest of the page the cart changed.
 *
 * The block mini-cart listens on document for wc-blocks_added_to_cart and
 * wc-blocks_removed_from_cart and refreshes its cart on either one. Only the
 * added event opens its drawer, and only when the merchant chose "Open
 * drawer" as its add-to-cart behavior. WooCommerce has no neutral "cart
 * changed" event, so a change that leaves the count where it was is
 * announced as a removal, which refreshes without opening anything.
 *
 * The jQuery added_to_cart and removed_from_cart events are opt-in through
 * the gecx_cart_refresh_legacy_events filter. Many themes open a side cart
 * on them, and their handlers expect the fragments, cart hash and button a
 * real add-to-cart click passes, none of which exist here. When they are
 * sent, the native events are not: the block mini-cart translates the jQuery
 * events into the native ones itself, and sending both would refresh twice.
 * @param {string} change 'added', 'removed' or 'updated'.
 * @param {boolean} preserveCartData Whether the data store already holds the new cart.
 * @param {?Object} cart
 * @param {?number} itemsCount
 * @param {?number} previousItemsCount
 */
function gecxAnnounceCartChange(change, preserveCartData, cart, itemsCount, previousItemsCount) {
  const settings = gecxCartRefreshConfig();
  const sendLegacy = settings.legacyEvents && window.jQuery &&
      window.jQuery(document.body).trigger;

  if (sendLegacy) {
    window.jQuery(document.body).trigger(change === 'added' ? 'added_to_cart' : 'removed_from_cart');
  } else if (settings.nativeEvents) {
    gecxDispatchBodyEvent(
        change === 'added' ? 'wc-blocks_added_to_cart' : 'wc-blocks_removed_from_cart',
        {preserveCartData: preserveCartData});
  }

  gecxDispatchBodyEvent('gecx:cart-updated', {
    change: change,
    itemsCount: itemsCount,
    previousItemsCount: previousItemsCount,
    cart: cart
  });
}

/**
 * Events already handled, so one event reaching both document and window
 * refreshes the cart once.
 * @type {?WeakSet<!Event>}
 */
const gecxHandledCartEvents = (typeof WeakSet === 'function') ? new WeakSet() : null;

/**
 * Brings every cart surface on the page up to date after the agent changed
 * the cart.
 * @param {!Event} e chat-messenger-update-cart event from the chat widget.
 */
function handleCartUpdate(e) {
  if (gecxHandledCartEvents) {
    if (gecxHandledCartEvents.has(e)) {
      return;
    }
    gecxHandledCartEvents.add(e);
  }

  const detail = e.detail || {};
  const rawCartId = detail.cartId || detail.cart_id || '';
  const cartId = gecxIsValidCartTokenFormat(rawCartId) ? rawCartId : '';
  if (cartId) {
    gecxLatestCartToken = cartId;
  }
  const directCart = (detail.cart && typeof detail.cart === 'object') ? detail.cart : null;
  const eventCount = (detail.totalQuantity !== undefined && detail.totalQuantity !== null &&
                      !isNaN(Number(detail.totalQuantity))) ?
      Number(detail.totalQuantity) :
      null;

  gecxRebindSessionOnCartUpdate();

  const store = gecxGetCartDataStore();
  const previousCount = gecxPreviousItemsCount(store);

  if (store) {
    try {
      if (typeof store.dispatch.setIsCartDataStale === 'function') {
        store.dispatch.setIsCartDataStale(true);
      }
      if (directCart) {
        store.dispatch.receiveCart(directCart);
      }
    } catch (err) {
      gecxReportError('cart store update', err);
    }
  }

  const finish = function(cart) {
    let storeUpdated = false;
    if (store && cart) {
      try {
        if (window.wp.data.dispatch('core/data') &&
            window.wp.data.dispatch('core/data').invalidateResolution) {
          window.wp.data.dispatch('core/data').invalidateResolution(
              'wc/store/cart', 'getCartData', []);
        }
        store.dispatch.receiveCart(cart);
        storeUpdated = true;
      } catch (err) {
        gecxReportError('cart store update', err);
      }
    }

    let count = gecxCartItemsCount(cart);
    if (count === null) {
      count = gecxCartItemsCount(directCart);
    }
    if (count === null) {
      count = eventCount;
    }
    if (count !== null) {
      gecxLastItemsCount = count;
    }

    if (!storeUpdated) {
      gecxUpdateCartBadges(count);
    }
    // Classic headers and mini-carts render from cart fragments. Refreshed
    // only now, after the cart read: for a shopper who had no WooCommerce
    // session cookie, that read is what hands the agent's cart session to
    // the browser, and fragments requested before it describe an empty
    // cart. A no-op on pages without wc-cart-fragments.
    if (window.jQuery && window.jQuery(document.body).trigger) {
      window.jQuery(document.body).trigger('wc_fragment_refresh');
    }
    gecxRefreshCartOrCheckoutSurface(storeUpdated);
    gecxAnnounceCartChange(
        gecxClassifyCartChange(previousCount, count), storeUpdated, cart || directCart,
        count, previousCount);
  };

  gecxFetchCart(cartId).then(finish, function(err) {
    gecxReportError('cart refresh', err);
    finish(null);
  });
}

// Helper to check if an element is visible in the viewport layout.
function isElementVisible(el) {
  if (!el || typeof window.getComputedStyle !== 'function') {
    return false;
  }
  const style = window.getComputedStyle(el);
  if (style.display === 'none' || style.visibility === 'hidden') {
    return false;
  }
  return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
}

/**
 * Whether an element is laid out, not visibility:hidden, and at least partly
 * inside the viewport horizontally. The last condition rules out off-canvas
 * drawers parked beside the viewport with a transform.
 * @param {?Element} el
 * @return {boolean}
 */
function gecxIsOnScreen(el) {
  if (!el || typeof el.getClientRects !== 'function' ||
      typeof window.getComputedStyle !== 'function') {
    return false;
  }
  const rects = el.getClientRects();
  if (!rects || !rects.length) {
    return false;
  }
  const style = window.getComputedStyle(el);
  if (style.display === 'none' || style.visibility === 'hidden') {
    return false;
  }
  const viewportWidth = window.innerWidth ||
      (document.documentElement && document.documentElement.clientWidth) || 0;
  for (let i = 0; i < rects.length; i++) {
    if (rects[i].right > 0 && (!viewportWidth || rects[i].left < viewportWidth)) {
      return true;
    }
  }
  return false;
}

/**
 * Calls fn for each element matching a selector, skipping invalid selectors.
 * @param {string} selector
 * @param {function(!Element)} fn
 * @param {(!Document|!Element)=} root
 */
function gecxEach(selector, fn, root) {
  let matches = [];
  try {
    matches = (root || document).querySelectorAll(selector);
  } catch (err) {
    return;
  }
  for (let i = 0; i < matches.length; i++) {
    fn(matches[i]);
  }
}

/** @const {string} Containers that are site footers. */
const GECX_FOOTER_SELECTOR =
    'footer, [role="contentinfo"], .site-footer, #colophon, .elementor-location-footer, [data-elementor-type="footer"]';

/**
 * Mobile navigation toggles, most specific first. Themes are named where
 * their toggle is not a <button> inside <header> labeled as a menu.
 * @const {!Array<string>}
 */
const GECX_MOBILE_TOGGLE_SELECTORS = [
  // Block themes (FSE)
  '.wp-block-navigation__responsive-container-open',
  // Accessible toggles
  'header button[aria-label*="menu" i]',
  'header button[aria-controls*="nav" i]',
  'header button[aria-controls*="menu" i]',
  'header a[aria-label*="menu" i]',
  'header [role="button"][aria-label*="menu" i]',
  // Frameworks and popular themes (Bootstrap, Astra, GeneratePress,
  // Storefront, Kadence, Neve, Elementor, Divi)
  '.navbar-toggle',
  '.navbar-toggler',
  '.off-canvas-toggle',
  '.menu-toggle',
  '.menu-toggle-open',
  '.mobile-menu-toggle',
  '.elementor-menu-toggle',
  '#et_mobile_nav_menu',
  '[data-toggle="offcanvas"]',
  // Flatsome
  '[data-open="#main-menu"]',
  // Avada
  '.awb-menu__m-toggle',
  '.fusion-mobile-nav-button',
  // OceanWP
  '.oceanwp-mobile-menu-icon a',
  // Blocksy
  '.ct-header-trigger',
  // Woodmart
  '.wd-header-mobile-nav a'
];

/**
 * The theme's mobile navigation toggle, when one is visible.
 * @return {?Element}
 */
function findMobileHeaderAnchor() {
  for (let i = 0; i < GECX_MOBILE_TOGGLE_SELECTORS.length; i++) {
    let found = null;
    gecxEach(GECX_MOBILE_TOGGLE_SELECTORS[i], function(el) {
      if (!found && !el.closest(GECX_FOOTER_SELECTOR) && isElementVisible(el) &&
          gecxIsOnScreen(el)) {
        found = el;
      }
    });
    if (found) {
      return found;
    }
  }

  // Cart icons are only an anchor on small screens. On wider ones they sit
  // beside the desktop menu in every standard theme.
  if (window.innerWidth < 768) {
    const cartSelectors = [
      'header .wc-block-mini-cart',
      'header .header-cart',
      'header .cart-contents'
    ];
    for (let i = 0; i < cartSelectors.length; i++) {
      let found = null;
      gecxEach(cartSelectors[i], function(el) {
        if (!found && isElementVisible(el)) {
          found = el;
        }
      });
      if (found) {
        return found;
      }
    }
  }

  return null;
}

/**
 * Safely parses localized widget HTML into a freshly constructed custom element
 * matching expectedTagName, copying only non-event-handler attributes and
 * rejecting markup with unexpected tags or child elements.
 * @param {string} html
 * @param {string} expectedTagName
 * @return {?Element}
 */
function gecxCreateSafeWidgetElement(html, expectedTagName) {
  if (!html || typeof html !== 'string' || !expectedTagName) {
    return null;
  }
  const tagLower = expectedTagName.toLowerCase();
  const tpl = document.createElement('template');
  let rootSearch = null;
  if (tpl && tpl.content) {
    tpl.innerHTML = html;
    rootSearch = tpl.content;
  } else {
    const scratch = document.createElement('div');
    scratch.innerHTML = html;
    rootSearch = scratch;
  }
  if (!rootSearch || typeof rootSearch.querySelector !== 'function') {
    return null;
  }
  if (rootSearch.querySelector('script, iframe, object, embed, svg, img, link, style')) {
    return null;
  }
  const parsed = rootSearch.querySelector(tagLower);
  if (!parsed || (parsed.children && parsed.children.length > 0)) {
    return null;
  }
  const safeEl = document.createElement(tagLower);
  const attrs = parsed.attributes || [];
  for (let i = 0; i < attrs.length; i++) {
    const attr = attrs[i];
    if (!attr || !attr.name) {
      continue;
    }
    const nameLower = attr.name.toLowerCase();
    if (nameLower.indexOf('on') === 0 || !/^[a-z0-9-]+$/.test(nameLower)) {
      continue;
    }
    safeEl.setAttribute(nameLower, attr.value);
  }
  return safeEl;
}

/**
 * Wraps the launcher in a container element built from the localized markup.
 * @param {string} tagName
 * @param {string} className
 * @return {?Element}
 */
function gecxCreateLauncherContainer(tagName, className) {
  const cfg = gecxGetStorefrontConfig();
  const btnEl = gecxCreateSafeWidgetElement(cfg && cfg.buttonHtml, 'gecx-agent-button');
  if (!btnEl) {
    return null;
  }
  const container = document.createElement(tagName);
  container.className = className;
  container.appendChild(btnEl);
  return container;
}

/**
 * Removes launcher menu items that ended up in a site footer, which the
 * server can only guess at for block and builder footers.
 */
function gecxRemoveFooterNavItems() {
  gecxEach('.gecx-nav-menu-item', function(item) {
    if (item.closest && item.closest(GECX_FOOTER_SELECTOR) && item.parentNode) {
      item.parentNode.removeChild(item);
    }
  });
}

/**
 * The header menu list most likely to be the main navigation: a top-level
 * list, on screen if possible, with the most items. The first match in
 * document order is often a top bar, account or language menu instead.
 * @return {?Element}
 */
function gecxFindHeaderMenuList() {
  let best = null;
  let bestScore = -1;
  gecxEach(
      'header nav ul, nav.main-navigation ul, nav.primary-navigation ul, #site-navigation ul, header .wp-block-navigation__container, header .nav-menu, header ul.menu',
      function(list) {
        if (list.closest('.sub-menu, .children, .dropdown-menu') ||
            (list.parentElement && list.parentElement.closest('li')) ||
            list.closest(GECX_FOOTER_SELECTOR)) {
          return;
        }
        let items = 0;
        const children = list.children || [];
        for (let i = 0; i < children.length; i++) {
          if (children[i].tagName === 'LI') {
            items++;
          }
        }
        const score = items + (gecxIsOnScreen(list) ? 1000 : 0);
        if (score > bestScore) {
          best = list;
          bestScore = score;
        }
      });
  return best;
}

// Client-side DOM check for desktop navigation placement.
function initNavPlacement() {
  const cfg = gecxGetStorefrontConfig();
  if (!cfg || !cfg.isWidgetEnabled || cfg.placement !== 'nav_menu' || !cfg.buttonHtml) {
    return;
  }
  if (document.querySelector('.gecx-nav-menu-item')) {
    return;
  }

  const navContainer = gecxFindHeaderMenuList();
  if (navContainer) {
    const li = gecxCreateLauncherContainer('li', 'menu-item gecx-nav-menu-item wp-block-navigation-item');
    if (li) {
      navContainer.appendChild(li);
    }
  }
}

/**
 * Shows the launcher beside the theme's hamburger while it is visible, and
 * in the menu otherwise.
 *
 * Decided from the hamburger itself rather than a breakpoint: themes switch
 * to it anywhere between 600px and 1024px, and block navigation can be set
 * to use it at every width.
 */
function initMobileHeaderPlacement() {
  const cfg = gecxGetStorefrontConfig();
  if (!cfg || !cfg.isWidgetEnabled || cfg.placement !== 'nav_menu') {
    return;
  }

  const anchor = findMobileHeaderAnchor();
  let mobileButton = document.querySelector(
      '.gecx-mobile-header-button:not(.gecx-mobile-header-button--floating)');
  const navItems = document.querySelectorAll('.gecx-nav-menu-item');

  if (anchor && anchor.parentNode) {
    if (!mobileButton) {
      mobileButton = gecxCreateLauncherContainer('div', 'gecx-mobile-header-button');
    }
    if (mobileButton) {
      if (mobileButton.nextSibling !== anchor) {
        anchor.parentNode.insertBefore(mobileButton, anchor);
      }
      mobileButton.style.removeProperty('display');
    }
    // The in-menu copies would only duplicate it inside the opened drawer.
    for (let i = 0; i < navItems.length; i++) {
      navItems[i].style.setProperty('display', 'none', 'important');
    }
  } else {
    if (mobileButton) {
      mobileButton.style.display = 'none';
    }
    for (let i = 0; i < navItems.length; i++) {
      navItems[i].style.removeProperty('display');
    }
  }

  gecxEnsureVisibleLauncher();
}

/**
 * Falls back to a floating launcher when no other one is on screen.
 *
 * Covers every theme the placement above cannot handle: a hamburger it does
 * not recognize, a desktop menu the theme hides on mobile, or a header with
 * no menu the launcher could join. Only for the menu placement; the floating
 * placement has its own button and the manual one is the merchant's call.
 */
function gecxEnsureVisibleLauncher() {
  const cfg = gecxGetStorefrontConfig();
  if (!cfg || !cfg.isWidgetEnabled || cfg.placement !== 'nav_menu' || !document.body) {
    return;
  }
  let fallback = document.querySelector('.gecx-launcher-fallback');
  let visible = false;
  gecxEach('gecx-agent-button', function(button) {
    if (!visible && !(fallback && fallback.contains(button)) && gecxIsOnScreen(button)) {
      visible = true;
    }
  });

  if (visible) {
    if (fallback) {
      fallback.style.display = 'none';
    }
    return;
  }
  if (!fallback) {
    fallback = gecxCreateLauncherContainer(
        'div', 'gecx-mobile-header-button gecx-mobile-header-button--floating gecx-launcher-fallback');
    if (!fallback) {
      return;
    }
    document.body.appendChild(fallback);
  }
  fallback.style.removeProperty('display');
}

/**
 * Add-to-cart forms and summaries the prompts are placed after, most
 * specific first. Page builders are named where they replace the standard
 * WooCommerce product template.
 * @const {!Array<string>}
 */
const GECX_PDP_TARGET_SELECTORS = [
  '.summary.entry-summary form.cart',
  '.single-product-summary form.cart',
  '.elementor-widget-woocommerce-product-add-to-cart',
  '.et_pb_wc_add_to_cart',
  '.wp-block-woocommerce-add-to-cart-form',
  '.brxe-product-add-to-cart',
  '.fusion-woo-cart',
  '.oxy-product-cart-button',
  'form.cart',
  '.summary.entry-summary',
  '.single-product-summary',
  '.wp-block-woocommerce-product-summary'
];

/**
 * Containers whose add-to-cart forms belong to something other than the main
 * product: sticky add-to-cart bars, quick views, related products, loops and
 * mini-carts.
 * @const {string}
 */
const GECX_PDP_EXCLUDED_CONTAINERS =
    '.sticky-add-to-cart, [class*="sticky-add-to-cart"], [class*="sticky_add_to_cart"], [id*="sticky-add-to-cart"], [class*="quick-view"], [class*="quickview"], .related, .upsells, .up-sells, .cross-sells, ul.products, .woocommerce-mini-cart';

/**
 * The element holding the main product, used to scope the prompt target
 * search. A selector list cannot express this: `.single-product` is a body
 * class, so a list containing it always resolves to <body>.
 * @return {!(Document|Element)}
 */
function gecxFindProductScope() {
  const scopes = ['div.product[id^="product-"]', '.wp-block-woocommerce-single-product', 'main', '#main'];
  for (let i = 0; i < scopes.length; i++) {
    let found = null;
    gecxEach(scopes[i], function(el) {
      if (!found && !el.closest(GECX_PDP_EXCLUDED_CONTAINERS)) {
        found = el;
      }
    });
    if (found) {
      return found;
    }
  }
  return document;
}

/**
 * Whether the page shows a single product, including pages loaded without a
 * full page load and products embedded with [product_page].
 * @param {!Object} cfg
 * @return {boolean}
 */
function gecxPageShowsProduct(cfg) {
  if (cfg.isPdp) {
    return true;
  }
  return !!((document.body && document.body.classList &&
             document.body.classList.contains('single-product')) ||
            document.querySelector('.wp-block-woocommerce-single-product'));
}

// Client-side DOM check for PDP suggested prompts placement (Page builders & non-standard templates fallback).
function initPdpPlacement() {
  const cfg = gecxGetStorefrontConfig();
  if (!cfg || !cfg.isWidgetEnabled || !cfg.pdpPromptsHtml || !gecxPageShowsProduct(cfg)) {
    return;
  }
  if (document.querySelector('gecx-suggested-prompts')) {
    return;
  }

  const scope = gecxFindProductScope();
  let pdpTarget = null;
  for (let i = 0; i < GECX_PDP_TARGET_SELECTORS.length && !pdpTarget; i++) {
    gecxEach(GECX_PDP_TARGET_SELECTORS[i], function(el) {
      if (!pdpTarget && !el.closest(GECX_PDP_EXCLUDED_CONTAINERS)) {
        pdpTarget = el;
      }
    }, scope);
  }
  if (!pdpTarget) {
    return;
  }

  const promptsEl = gecxCreateSafeWidgetElement(cfg.pdpPromptsHtml, 'gecx-suggested-prompts');
  if (promptsEl) {
    if (typeof pdpTarget.insertAdjacentElement === 'function') {
      pdpTarget.insertAdjacentElement('afterend', promptsEl);
    } else if (pdpTarget.parentNode) {
      pdpTarget.parentNode.insertBefore(promptsEl, pdpTarget.nextSibling);
    }
  }
}

/**
 * Known bars themes fix to the bottom of the viewport, in addition to the
 * direct children of <body> checked generically.
 * @const {string}
 */
const GECX_BOTTOM_BAR_SELECTORS =
    '.storefront-handheld-footer-bar, .ast-sticky-add-to-cart, .sticky-add-to-cart, [class*="sticky-add-to-cart"], .wd-toolbar, .elementor-sticky--active';

/**
 * Whether an element is ours or the chat widget's.
 * @param {!Element} el
 * @return {boolean}
 */
function gecxIsOwnElement(el) {
  const tag = (el.tagName || '').toLowerCase();
  if (tag === 'chat-messenger' || tag.indexOf('gecx-') === 0) {
    return true;
  }
  const className = typeof el.className === 'string' ? el.className : '';
  return /(^|\s)gecx-(nav-menu-item|mobile-header-button|floating-button-container|agent-button-slot)(\s|$)/.test(className);
}

/**
 * Lifts floating launchers above bars the theme fixes to the bottom of the
 * viewport: handheld footer bars, sticky add-to-cart bars, mobile toolbars.
 *
 * Sets --gecx-floating-offset on the root element, which the floating
 * positions add to their bottom offset along with the device's safe area.
 * Merchants can add their own spacing with --gecx-floating-extra-offset.
 */
function updateFloatingOffset() {
  if (!document.body || !document.documentElement ||
      typeof window.getComputedStyle !== 'function') {
    return;
  }
  const viewportHeight = window.innerHeight || 0;
  const viewportWidth = window.innerWidth || 0;
  if (!viewportHeight || !viewportWidth) {
    return;
  }
  let offset = 0;
  const consider = function(el) {
    if (gecxIsOwnElement(el) || typeof el.getBoundingClientRect !== 'function') {
      return;
    }
    const style = window.getComputedStyle(el);
    if (style.position !== 'fixed' || style.display === 'none' || style.visibility === 'hidden') {
      return;
    }
    const rect = el.getBoundingClientRect();
    if (rect.height > 0 && rect.height < viewportHeight * 0.4 &&
        rect.width > viewportWidth * 0.5 && rect.bottom >= viewportHeight - 2 &&
        rect.top > viewportHeight * 0.5) {
      offset = Math.max(offset, Math.ceil(viewportHeight - rect.top));
    }
  };
  const children = document.body.children || [];
  for (let i = 0; i < children.length; i++) {
    consider(children[i]);
  }
  gecxEach(GECX_BOTTOM_BAR_SELECTORS, consider);

  if (offset > 0) {
    document.documentElement.style.setProperty('--gecx-floating-offset', offset + 'px');
  } else {
    document.documentElement.style.removeProperty('--gecx-floating-offset');
  }
}

/**
 * Fixed full-width headers and bars that ignore the body padding the chat
 * panel adds when it slides in.
 * @return {!Array<!Element>}
 */
function gecxFindFixedFullWidthElements() {
  const found = [];
  if (typeof window.getComputedStyle !== 'function') {
    return found;
  }
  const viewportWidth = window.innerWidth || 0;
  const consider = function(el) {
    if (found.indexOf(el) !== -1 || gecxIsOwnElement(el) ||
        typeof el.getBoundingClientRect !== 'function') {
      return;
    }
    if (window.getComputedStyle(el).position !== 'fixed') {
      return;
    }
    if (el.getBoundingClientRect().width >= viewportWidth * 0.9) {
      found.push(el);
    }
  };
  const children = (document.body && document.body.children) || [];
  for (let i = 0; i < children.length; i++) {
    consider(children[i]);
  }
  gecxEach('header, .site-header, #masthead, .sticky-header, [class*="sticky-header"], .elementor-sticky--active, ' +
      GECX_BOTTOM_BAR_SELECTORS, consider);
  return found;
}

/**
 * Narrows fixed full-width headers and bars while the chat panel pushes the
 * page aside, and restores them when it closes.
 * @param {number} pushWidth Body padding the panel added, or 0 when closed.
 */
function gecxShiftFixedElementsForChat(pushWidth) {
  gecxEach('.gecx-shifted-for-chat', function(el) {
    el.style.right = el.getAttribute('data-gecx-right') || '';
    el.style.maxWidth = el.getAttribute('data-gecx-max-width') || '';
    el.removeAttribute('data-gecx-right');
    el.removeAttribute('data-gecx-max-width');
    el.classList.remove('gecx-shifted-for-chat');
  });
  if (pushWidth <= 0) {
    return;
  }
  const elements = gecxFindFixedFullWidthElements();
  for (let i = 0; i < elements.length; i++) {
    const el = elements[i];
    el.setAttribute('data-gecx-right', el.style.right || '');
    el.setAttribute('data-gecx-max-width', el.style.maxWidth || '');
    el.classList.add('gecx-shifted-for-chat');
    el.style.right = pushWidth + 'px';
    el.style.maxWidth = 'calc(100% - ' + pushWidth + 'px)';
  }
}

/** @type {?number} Pending re-measure once the panel's padding transition ends. */
let gecxCenteringFollowUp = null;

// Keep floating button centered relative to remaining page content when chat panel opens.
function updateFloatingWidgetCentering(isFollowUp) {
  if (!document.body || !document.documentElement) {
    return;
  }
  // The widget stylesheet animates the body padding over 0.4s, so what is
  // measured now is somewhere mid-transition. Measure again once it is done.
  if (isFollowUp !== true) {
    clearTimeout(gecxCenteringFollowUp);
    gecxCenteringFollowUp = setTimeout(function() {
      gecxCenteringFollowUp = null;
      updateFloatingWidgetCentering(true);
    }, 450);
  }
  const isChatOpen = document.body.classList.contains('gecx-chat-open') ||
      !!document.querySelector('chat-messenger:not(.messenger-hidden)');

  if (!isChatOpen) {
    document.documentElement.style.removeProperty('--gecx-chat-panel-width');
    gecxShiftFixedElementsForChat(0);
    return;
  }

  const bodyPadding = (typeof window.getComputedStyle === 'function') ?
      (parseFloat(window.getComputedStyle(document.body).paddingRight) || 0) :
      0;
  const chatMessenger = document.querySelector('chat-messenger');
  let panelWidth = 0;
  if (chatMessenger && chatMessenger.offsetWidth) {
    panelWidth = chatMessenger.offsetWidth;
  } else if (bodyPadding > 0) {
    panelWidth = bodyPadding;
  }

  if (panelWidth > 0 && panelWidth < window.innerWidth) {
    document.documentElement.style.setProperty('--gecx-chat-panel-width', panelWidth + 'px');
  }
  // Only a panel that slides in pads the body; one that slides over the page
  // leaves the layout alone and needs nothing moved.
  gecxShiftFixedElementsForChat(bodyPadding > 0 && bodyPadding < window.innerWidth ? bodyPadding : 0);
}

/**
 * Reads the theme's button colors and body font into the chat widget's
 * color and font variables, when the merchant enabled "Match theme styles".
 *
 * Read from the rendered page rather than theme.json so classic themes,
 * which have no theme.json, are covered too. The widget's variables inherit
 * into its shadow DOM from the root element.
 */
function gecxApplyThemeStyles() {
  const cfg = gecxGetStorefrontConfig();
  const appearance = (cfg && cfg.appearance && typeof cfg.appearance === 'object') ? cfg.appearance : {};
  if (appearance.matchThemeStyles !== true || !document.body || !document.documentElement ||
      typeof window.getComputedStyle !== 'function') {
    return;
  }
  const root = document.documentElement.style;
  const isTransparent = function(color) {
    return !color || color === 'transparent' || /rgba\([^)]*,\s*0\)$/.test(color);
  };

  let button = null;
  gecxEach('.single_add_to_cart_button, .wp-element-button, .wp-block-button__link, a.button, button.button, .button',
      function(el) {
        if (!button && !gecxIsOwnElement(el) && isElementVisible(el) &&
            !isTransparent(window.getComputedStyle(el).backgroundColor)) {
          button = el;
        }
      });
  if (button) {
    const buttonStyle = window.getComputedStyle(button);
    root.setProperty('--chat-messenger-color--primary', buttonStyle.backgroundColor);
    root.setProperty('--chat-messenger-internal-primary-color', buttonStyle.backgroundColor);
    if (!isTransparent(buttonStyle.color)) {
      root.setProperty('--chat-messenger-color--on-primary', buttonStyle.color);
    }
  }

  const bodyStyle = window.getComputedStyle(document.body);
  if (bodyStyle.fontFamily) {
    root.setProperty('--chat-messenger-font-family', bodyStyle.fontFamily);
  }

  // Dark mode: follow the page background rather than the OS setting, since
  // a light theme on a dark OS should keep a light widget.
  let background = bodyStyle.backgroundColor;
  if (isTransparent(background)) {
    background = window.getComputedStyle(document.documentElement).backgroundColor;
  }
  const rgb = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(background || '');
  const isDark = !!rgb &&
      (0.2126 * rgb[1] + 0.7152 * rgb[2] + 0.0722 * rgb[3]) / 255 < 0.4;
  gecxEach('chat-messenger', function(el) {
    if (isDark) {
      el.setAttribute('color-scheme', 'dark');
    } else if (el.getAttribute('color-scheme') === 'dark') {
      el.removeAttribute('color-scheme');
    }
  });
}

/** @type {?Promise<void>} In-flight or completed dynamic widget script load. */
let gecxWidgetScriptPromise = null;

/**
 * Dynamically loads the Google-hosted chat widget bundle when deferred by
 * consent gating (`gecx_should_load_widget` filter) or interaction-triggered
 * loading (`gecx_defer_widget_until_interaction` option).
 * @return {!Promise<void>}
 */
function gecxLoadWidget() {
  if (gecxWidgetScriptPromise) {
    return gecxWidgetScriptPromise;
  }
  if (window.customElements &&
      window.customElements.get('gecx-woocommerce-chat-widget')) {
    gecxWidgetScriptPromise = Promise.resolve();
    return gecxWidgetScriptPromise;
  }
  const config = gecxGetStorefrontConfig();
  const scriptUrl =
      (config && config.widgetScriptUrl) ? config.widgetScriptUrl : '';
  if (!scriptUrl) {
    gecxWidgetScriptPromise = Promise.resolve();
    return gecxWidgetScriptPromise;
  }

  gecxWidgetScriptPromise = new Promise(function(resolve, reject) {
    const existing = document.getElementById('gecx-widget-script-js') ||
        document.querySelector('script[src="' + scriptUrl + '"]');
    if (existing) {
      resolve();
      return;
    }
    const script = document.createElement('script');
    script.id = 'gecx-widget-script-js';
    script.src = scriptUrl;
    script.async = true;
    script.onload = function() {
      resolve();
    };
    script.onerror = function(err) {
      gecxWidgetScriptPromise = null;
      gecxReportError('widget script load', err);
      reject(err);
    };
    document.head.appendChild(script);
  });

  return gecxWidgetScriptPromise;
}

const gecxHandleLoadWidgetEvent = function() {
  gecxLoadWidget().catch(function(err) {
    gecxReportError('widget load', err);
  });
};
window.gecxLoadWidget = gecxLoadWidget;
window.addEventListener('gecx:consent-granted', gecxHandleLoadWidgetEvent);
document.addEventListener('gecx:consent-granted', gecxHandleLoadWidgetEvent);
window.addEventListener('gecx-load-widget', gecxHandleLoadWidgetEvent);
document.addEventListener('gecx-load-widget', gecxHandleLoadWidgetEvent);

function initDeferredWidgetListeners() {
  const config = gecxGetStorefrontConfig();
  // wp_localize_script() casts top-level scalars to strings, so the PHP false
  // arrives as "". Accept both, and "0", so deferred loading actually defers.
  const deferred = !!config && (config.shouldLoadWidget === false ||
      config.shouldLoadWidget === '' || config.shouldLoadWidget === '0');
  if (!deferred) {
    return;
  }
  const onDeferredInteraction = function(e) {
    const target = e.target && e.target.closest ?
        e.target.closest(
            'gecx-agent-button, gecx-suggested-prompts, .gecx-floating-button-container, .gecx-nav-menu-item, .gecx-mobile-header-button') :
        null;
    if (!target) {
      return;
    }
    if (e.type === 'keydown') {
      const isEnter = e.key === 'Enter';
      const isSpace = e.key === ' ' || e.key === 'Spacebar';
      if (!isEnter && !isSpace) {
        return;
      }
      if (isSpace && typeof e.preventDefault === 'function') {
        e.preventDefault();
      }
    }
    document.removeEventListener('click', onDeferredInteraction, true);
    document.removeEventListener('keydown', onDeferredInteraction, true);
    gecxLoadWidget()
        .then(function() {
          const btn = target.matches('gecx-agent-button') ?
              target :
              target.querySelector('gecx-agent-button');
          if (btn && typeof btn.click === 'function') {
            setTimeout(function() {
              btn.click();
            }, 50);
          }
        })
        .catch(function() {
          document.addEventListener('click', onDeferredInteraction, true);
          document.addEventListener('keydown', onDeferredInteraction, true);
        });
  };
  document.addEventListener('click', onDeferredInteraction, true);
  document.addEventListener('keydown', onDeferredInteraction, true);
}

/**
 * Everything that places or adjusts the launcher and prompts. Safe to run
 * repeatedly: each step only acts on what is missing or out of date.
 */
function gecxPlaceAll() {
  gecxRemoveFooterNavItems();
  initNavPlacement();
  initMobileHeaderPlacement();
  initPdpPlacement();
  updateFloatingOffset();
  updateFloatingWidgetCentering();
  gecxApplyThemeStyles();
}

function initStorefront() {
  gecxPlaceAll();
  initDeferredWidgetListeners();
}

/**
 * Re-runs placement for themes that replace page content without a full
 * page load (swup, barba, PJAX), render their header late, open quick views
 * or load products by infinite scroll. Themes can also call it directly.
 */
window.gecxInit = gecxPlaceAll;

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initStorefront);
} else {
  initStorefront();
}

let resizeTimer = null;
window.addEventListener('resize', function() {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(gecxPlaceAll, 150);
});

let gecxReinitTimer = null;
function gecxScheduleReinit() {
  clearTimeout(gecxReinitTimer);
  gecxReinitTimer = setTimeout(gecxPlaceAll, 250);
}

// Page transition libraries announce new content with their own events.
['swup:contentReplaced', 'swup:page:view', 'pjax:complete', 'pjax:end', 'turbo:load', 'turbolinks:load']
    .forEach(function(name) {
      document.addEventListener(name, gecxScheduleReinit);
    });

// Both targets, because the widget's event may or may not bubble. A bubbling
// event reaches both, and handleCartUpdate() handles it only once.
window.addEventListener('chat-messenger-update-cart', handleCartUpdate);
document.addEventListener('chat-messenger-update-cart', handleCartUpdate);

window.addEventListener('chat-messenger-visibility-changed', updateFloatingWidgetCentering);
document.addEventListener('chat-messenger-visibility-changed', updateFloatingWidgetCentering);

if (typeof MutationObserver !== 'undefined' && document.body) {
  const chatObserver = new MutationObserver(function(mutations) {
    for (let i = 0; i < mutations.length; i++) {
      if (mutations[i].attributeName === 'class') {
        updateFloatingWidgetCentering();
        break;
      }
    }
  });
  chatObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });

  // Content added after load: late headers, quick views, infinite scroll and
  // page transitions that fire no event. Our own insertions are ignored so
  // placement cannot trigger itself.
  const contentObserver = new MutationObserver(function(mutations) {
    for (let i = 0; i < mutations.length; i++) {
      const added = mutations[i].addedNodes || [];
      for (let j = 0; j < added.length; j++) {
        const node = added[j];
        if (node.nodeType === 1 && !gecxIsOwnElement(node) &&
            !(node.closest && node.closest('.gecx-nav-menu-item, .gecx-mobile-header-button, .gecx-floating-button-container'))) {
          gecxScheduleReinit();
          return;
        }
      }
    }
  });
  contentObserver.observe(document.body, { childList: true, subtree: true });
}
})();
