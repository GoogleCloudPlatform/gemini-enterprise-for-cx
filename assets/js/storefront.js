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

if (typeof window !== 'undefined' && typeof window.fetch === 'function') {
  const originalFetch = window.fetch;
  window.fetch = function(input, init) {
    const url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
    if (gecxLatestCartToken && /\/gecx\/v1\/session(\b|$)/.test(url)) {
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
        if (response && response.headers && /\/wc\/store\/v\d+\/cart(\b|\/|$)/.test(url)) {
          const token = (typeof response.headers.get === 'function') ?
              (response.headers.get('Cart-Token') || response.headers.get('cart-token')) :
              '';
          if (token) {
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
 * The Store API cart endpoint for this store.
 *
 * Read from the localized config, which is built with rest_url() and so is
 * correct on subdirectory installs, plain permalinks, and stores that have
 * changed the REST url prefix. The literal is only a last resort for a page
 * that somehow loaded this script without its config.
 * @return {string}
 */
function gecxCartRestUrl() {
  const config = window.gecxStorefrontConfig;
  if (config && config.cartRestUrl) {
    return config.cartRestUrl;
  }
  return '/wp-json/wc/store/v1/cart';
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
  const config = window.gecxStorefrontConfig;
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
  const config = window.gecxStorefrontConfig;
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
  const config = window.gecxStorefrontConfig;
  if (config && typeof config.isCart !== 'undefined') {
    return !!config.isCart;
  }
  return !!(
      window.location && window.location.pathname &&
      /(^|\/)cart(\/|$)/.test(window.location.pathname));
}

/**
 * Refreshes classic or block cart/checkout surfaces without a full page reload
 * when jQuery or WooCommerce Blocks is available, falling back to a page
 * reload on classic cart/checkout pages that lack jQuery event handlers.
 * @param {boolean=} hasBlockStore Whether WooCommerce Blocks wp.data store handled the refresh.
 * @return {boolean} True when wc_fragment_refresh was already triggered.
 */
function gecxRefreshCartOrCheckoutSurface(hasBlockStore) {
  if (!gecxIsCartOrCheckout()) {
    return false;
  }
  if (window.jQuery && window.jQuery(document.body).trigger) {
    const body = window.jQuery(document.body);
    if (gecxIsCheckout()) {
      body.trigger('update_checkout');
    }
    if (gecxIsCart()) {
      body.trigger('wc_update_cart');
    }
    body.trigger('wc_fragment_refresh');
    return true;
  }
  if (!hasBlockStore && window.location && typeof window.location.reload === 'function') {
    window.location.reload();
  }
  return false;
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
  const config = window.gecxStorefrontConfig;
  const authContextUrl = (config && config.authContextUrl) ? config.authContextUrl : '';
  const sessionUrl = authContextUrl ?
      authContextUrl.replace(/\/auth-context\/?$/, '/session') :
      '/wp-json/gecx/v1/session';

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

  const config = window.gecxStorefrontConfig;
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

function handleCartUpdate(e) {
  const cartId = (e.detail && (e.detail.cartId || e.detail.cart_id)) ?
      (e.detail.cartId || e.detail.cart_id) :
      '';
  if (cartId) {
    gecxLatestCartToken = cartId;
  }
  const totalQuantity = (e.detail && e.detail.totalQuantity !== undefined) ?
      e.detail.totalQuantity :
      null;
  const directCart = (e.detail && e.detail.cart) ? e.detail.cart : null;
  let refreshedFragmentsSync = false;

  gecxRebindSessionOnCartUpdate();

  // 1. React/Gutenberg Block Cart Refresh (WooCommerce Blocks)
  if (window.wp && window.wp.data && window.wp.data.dispatch) {
    try {
      const coreStore = window.wp.data.dispatch('core/data');
      const cartStore = window.wp.data.dispatch('wc/store/cart');
      if (cartStore && cartStore.setIsCartDataStale) {
        cartStore.setIsCartDataStale(true);
      }
      if (directCart && cartStore && cartStore.receiveCart) {
        cartStore.receiveCart(directCart);
      }

      const headers = {};
      if (cartId) {
        headers['Cart-Token'] = cartId;
      }

      // Resolve a fresh REST nonce before both invalidateResolution() and
      // wp.apiFetch(). On cached storefront HTML, wp.apiFetch.nonceMiddleware
      // is seeded with whatever guest or expired nonce was baked into the
      // page at cache time, and wp-api-fetch is always loaded alongside
      // wc-blocks-data-store, so updating nonceMiddleware.nonce is required
      // to keep logged-in cart reads from failing with 403.
      gecxResolveRestNonce()
          .then(function(nonce) {
            if (nonce && window.wp.apiFetch &&
                window.wp.apiFetch.nonceMiddleware) {
              window.wp.apiFetch.nonceMiddleware.nonce = nonce;
            }

            const requestHeaders = Object.assign({}, headers);
            if (nonce) {
              requestHeaders['X-WP-Nonce'] = nonce;
            }

            if (window.wp.apiFetch) {
              return window.wp.apiFetch({
                path: '/wc/store/v1/cart',
                headers: requestHeaders,
                credentials: 'include'
              });
            }

            requestHeaders['Content-Type'] = 'application/json';
            return fetch(gecxCartRestUrl(), {
                     method: 'GET',
                     headers: requestHeaders,
                     credentials: 'include'
                   })
                .then(function(res) {
                  if (!res.ok) {
                    throw new Error('cart responded ' + res.status);
                  }
                  return res.json();
                });
          })
          .then(function(cart) {
            if (coreStore && coreStore.invalidateResolution) {
              coreStore.invalidateResolution(
                  'wc/store/cart', 'getCartData', []);
            }
            if (cartStore && cartStore.receiveCart) {
              cartStore.receiveCart(cart);
            }
            gecxRefreshCartOrCheckoutSurface(true);
          })
          .catch(function(err) {
            gecxReportError('cart refresh', err);
          });
    } catch (err) {
      gecxReportError('cart store update', err);
    }
  } else if (gecxIsCartOrCheckout()) {
    refreshedFragmentsSync = gecxRefreshCartOrCheckoutSurface(false);
  }

  // 2. Fallback to trigger jQuery fragments in case traditional theme fallback exists.
  if (window.jQuery && window.jQuery(document.body).trigger) {
    if (!refreshedFragmentsSync) {
      window.jQuery(document.body).trigger('wc_fragment_refresh');
    }
    window.jQuery(document.body).trigger('added_to_cart');
  }

  // 3. Fallback to update DOM badge count directly for classic/non-Gutenberg themes.
  if ((!window.wp || !window.wp.data) && totalQuantity !== null) {
    let badge = document.querySelector(
        '.wc-block-mini-cart__badge, .wc-block-components-mini-cart__badge');
    if (badge) {
      badge.textContent = totalQuantity;
      if (totalQuantity > 0) {
        badge.removeAttribute('hidden');
        badge.style.display = '';
      } else {
        badge.setAttribute('hidden', '');
        badge.style.display = 'none';
      }
    } else if (totalQuantity > 0) {
      const wrapper = document.querySelector(
          '.wc-block-mini-cart__quantity-badge, .wc-block-mini-cart__button, .wc-block-components-mini-cart__button, .wc-block-mini-cart button');
      if (wrapper) {
        badge = document.createElement('span');
        badge.className = 'wc-block-mini-cart__badge';
        badge.textContent = totalQuantity;
        wrapper.appendChild(badge);
      }
    } else if (badge && totalQuantity === 0) {
      badge.setAttribute('hidden', '');
      badge.style.display = 'none';
    }
  }
}

// Helper to check if an element is visible in the viewport layout.
function isElementVisible(el) {
  if (!el) {
    return false;
  }
  const style = window.getComputedStyle(el);
  if (style.display === 'none' || style.visibility === 'hidden') {
    return false;
  }
  return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
}

// Helper to locate candidate anchor for mobile header button.
function findMobileHeaderAnchor() {
  const toggleSelectors = [
    // Block Themes (FSE)
    '.wp-block-navigation__responsive-container-open',
    // Accessible ARIA toggles
    'header button[aria-label*="menu" i]',
    'header button[aria-controls*="nav" i]',
    'header button[aria-controls*="menu" i]',
    // Frameworks & Popular Themes (Bootstrap, Astra, GeneratePress, Elementor, Divi, Storefront)
    '.navbar-toggle',
    '.off-canvas-toggle',
    '.menu-toggle',
    '.mobile-menu-toggle',
    '.site-header .menu-toggle',
    '.elementor-menu-toggle',
    '#et_mobile_nav_menu',
    '[data-toggle="offcanvas"]'
  ];

  // 1. Inspect all matching mobile navigation toggle elements.
  for (let i = 0; i < toggleSelectors.length; i++) {
    const elements = document.querySelectorAll(toggleSelectors[i]);
    for (let j = 0; j < elements.length; j++) {
      if (isElementVisible(elements[j])) {
        return elements[j];
      }
    }
  }

  // 2. Only consider cart selectors on mobile viewports (< 768px).
  // On desktop viewports, cart elements are visible in standard themes and
  // must not be treated as mobile-only anchors.
  if (window.innerWidth < 768) {
    const cartSelectors = [
      'header .wc-block-mini-cart',
      'header .header-cart',
      'header .cart-contents'
    ];
    for (let i = 0; i < cartSelectors.length; i++) {
      const elements = document.querySelectorAll(cartSelectors[i]);
      for (let j = 0; j < elements.length; j++) {
        if (isElementVisible(elements[j])) {
          return elements[j];
        }
      }
    }
  }

  return null;
}

// Client-side DOM check for mobile header button placement.
function initMobileHeaderPlacement() {
  if (typeof gecxStorefrontConfig === 'undefined' || !gecxStorefrontConfig.isWidgetEnabled) {
    return;
  }
  if (gecxStorefrontConfig.placement !== 'nav_menu') {
    return;
  }

  const anchor = findMobileHeaderAnchor();
  const existing = document.querySelector('.gecx-mobile-header-button');
  const navItems = document.querySelectorAll('.gecx-nav-menu-item');

  if (anchor && anchor.parentNode) {
    // Mobile navigation toggle is VISIBLE! Show mobile header button next to toggle.
    if (existing) {
      existing.style.display = 'inline-flex';
      if (existing.classList.contains('gecx-mobile-header-button--floating')) {
        existing.classList.remove('gecx-mobile-header-button--floating');
        anchor.parentNode.insertBefore(existing, anchor);
      }
    } else {
      const container = document.createElement('div');
      container.className = 'gecx-mobile-header-button';
      container.innerHTML = gecxStorefrontConfig.buttonHtml;
      anchor.parentNode.insertBefore(container, anchor);
    }
    // Hide all in-menu items so they do not duplicate inside the opened drawer.
    for (let i = 0; i < navItems.length; i++) {
      navItems[i].style.setProperty('display', 'none', 'important');
    }
  } else {
    // Mobile toggle is NOT visible (expanded/desktop/tablet mode, e.g. iPad Mini 768px or desktop).
    if (existing && !existing.classList.contains('gecx-mobile-header-button--floating')) {
      existing.style.display = 'none';
    }
    // Ensure in-menu items are visible and vertically aligned.
    for (let i = 0; i < navItems.length; i++) {
      navItems[i].style.removeProperty('display');
      navItems[i].style.display = 'inline-flex';
    }
    if (window.innerWidth < 600 && !document.querySelector('header nav, nav.main-navigation, .wp-block-navigation')) {
      // Tier 3: Floating Action Button (FAB) safety net only on small screens without any navigation.
      if (!existing) {
        const container = document.createElement('div');
        container.className = 'gecx-mobile-header-button gecx-mobile-header-button--floating';
        container.innerHTML = gecxStorefrontConfig.buttonHtml;
        document.body.appendChild(container);
      }
    }
  }
}

// Client-side DOM check for desktop navigation placement.
function initNavPlacement() {
  if (typeof gecxStorefrontConfig === 'undefined' || !gecxStorefrontConfig.isWidgetEnabled) {
    return;
  }
  if (gecxStorefrontConfig.placement !== 'nav_menu') {
    return;
  }
  if (document.querySelector('.gecx-nav-menu-item')) {
    return;
  }

  // Desktop nav injection
  const navContainer = document.querySelector(
    'header nav ul, nav.main-navigation ul, nav.primary-navigation ul, #site-navigation ul, header .wp-block-navigation__container, header .nav-menu'
  );
  if (navContainer && gecxStorefrontConfig.buttonHtml) {
    const li = document.createElement('li');
    li.className = 'menu-item gecx-nav-menu-item wp-block-navigation-item';
    li.style.display = 'inline-flex';
    li.style.alignItems = 'center';
    li.style.justifyContent = 'center';
    li.style.verticalAlign = 'middle';
    li.innerHTML = gecxStorefrontConfig.buttonHtml;
    navContainer.appendChild(li);
  }
}

// Client-side DOM check for PDP suggested prompts placement (Page builders & non-standard templates fallback).
function initPdpPlacement() {
  if (typeof gecxStorefrontConfig === 'undefined' || !gecxStorefrontConfig.isWidgetEnabled) {
    return;
  }
  if (!gecxStorefrontConfig.isPdp || !gecxStorefrontConfig.pdpPromptsHtml) {
    return;
  }
  if (document.querySelector('gecx-suggested-prompts')) {
    return;
  }

  // Scope search to the main product container to avoid matching carousels, sidebars, or mini-carts.
  const productScope = document.querySelector('.product, .single-product, main, article') || document;
  const pdpTarget = productScope.querySelector(
    '.summary.entry-summary form.cart, .single-product-summary form.cart, form.cart, .elementor-widget-woocommerce-product-add-to-cart, .et_pb_wc_add_to_cart, .wp-block-woocommerce-add-to-cart-form, .summary.entry-summary, .single-product-summary'
  );
  if (pdpTarget) {
    pdpTarget.insertAdjacentHTML('afterend', gecxStorefrontConfig.pdpPromptsHtml);
  }
}

// Keep floating button centered relative to remaining page content when chat panel opens.
function updateFloatingWidgetCentering() {
  const container = document.querySelector('.gecx-floating-button-container');
  if (!container) {
    return;
  }
  const isChatOpen = document.body.classList.contains('gecx-chat-open') ||
      !!document.querySelector('chat-messenger:not(.messenger-hidden)');

  if (!isChatOpen) {
    document.documentElement.style.removeProperty('--gecx-chat-panel-width');
    return;
  }

  const chatMessenger = document.querySelector('chat-messenger');
  let panelWidth = 0;
  if (chatMessenger && chatMessenger.offsetWidth) {
    panelWidth = chatMessenger.offsetWidth;
  } else {
    const bodyPadding = parseFloat(window.getComputedStyle(document.body).paddingRight) || 0;
    if (bodyPadding > 0) {
      panelWidth = bodyPadding;
    }
  }

  if (panelWidth > 0 && panelWidth < window.innerWidth) {
    document.documentElement.style.setProperty('--gecx-chat-panel-width', panelWidth + 'px');
  }
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
  const config = window.gecxStorefrontConfig;
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

window.gecxLoadWidget = gecxLoadWidget;
window.addEventListener('gecx:consent-granted', gecxLoadWidget);
document.addEventListener('gecx:consent-granted', gecxLoadWidget);
window.addEventListener('gecx-load-widget', gecxLoadWidget);
document.addEventListener('gecx-load-widget', gecxLoadWidget);

function initDeferredWidgetListeners() {
  const config = window.gecxStorefrontConfig;
  if (!config || config.shouldLoadWidget !== false) {
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

function initStorefront() {
  initNavPlacement();
  initMobileHeaderPlacement();
  initPdpPlacement();
  updateFloatingWidgetCentering();
  initDeferredWidgetListeners();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initStorefront);
} else {
  initStorefront();
}

let resizeTimer = null;
window.addEventListener('resize', function() {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(function() {
    initNavPlacement();
    initMobileHeaderPlacement();
    updateFloatingWidgetCentering();
  }, 150);
});

window.addEventListener('chat-messenger-update-cart', handleCartUpdate);
document.addEventListener('chat-messenger-update-cart', handleCartUpdate);

window.addEventListener('chat-messenger-visibility-changed', updateFloatingWidgetCentering);
document.addEventListener('chat-messenger-visibility-changed', updateFloatingWidgetCentering);

if (typeof MutationObserver !== 'undefined') {
  const chatObserver = new MutationObserver(function(mutations) {
    for (let i = 0; i < mutations.length; i++) {
      if (mutations[i].attributeName === 'class') {
        updateFloatingWidgetCentering();
        break;
      }
    }
  });
  chatObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
}
})();
