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
  // The markup is a nested object, which a shallow copy would still share
  // with the global. Copy it too so it cannot be changed after load.
  copy.markup = Object.assign({}, (raw.markup && typeof raw.markup === 'object') ? raw.markup : {});
  if (typeof Object.freeze === 'function') {
    try {
      if (raw.markup && typeof raw.markup === 'object') {
        Object.freeze(raw.markup);
      }
      Object.freeze(copy.markup);
      Object.freeze(raw);
      Object.freeze(copy);
    } catch (err) {
      // Ignore freeze failure on non-extensible host objects.
    }
  }
  return copy;
})();

/**
 * Returns a piece of widget markup from the config: 'button', 'prompts' or
 * 'productPrompts'.
 * @param {?Object} cfg
 * @param {string} key
 * @return {string}
 */
function gecxConfigMarkup(cfg, key) {
  const markup = cfg && cfg.markup;
  return (markup && typeof markup[key] === 'string') ? markup[key] : '';
}

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
 * The Store API cart endpoint under a URL no other request uses.
 *
 * A page cache such as Batcache can answer a GET that carries no cookies from
 * a copy it cached by URL, whatever Cart-Token the request sends. The cart
 * read after an agent cart change would then not bind a browser without a
 * WooCommerce session to the agent's cart, and the cart it loads into the
 * page could be another shopper's. The Store API ignores the extra query
 * parameter, as it does the one the agent adds to its own cart reads.
 * @return {string}
 */
function gecxUncachedCartRestUrl() {
  const url = gecxCartRestUrl();
  // Plain permalinks put the route in the query: ?rest_route=/wc/store/...
  const separator = url.indexOf('?') === -1 ? '?' : '&';
  return url + separator + '_nocache=' + Date.now().toString(36) +
      Math.random().toString(36).slice(2, 8);
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
 * The GECX cart session REST endpoint for this store.
 * @return {string}
 */
function gecxCartSessionRestUrl() {
  const config = gecxGetStorefrontConfig();
  if (config && config.cartSessionUrl) {
    return config.cartSessionUrl;
  }
  return gecxSessionRestUrl().replace(/\/session(\b|\/|$)/, '/cart-session$1');
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
            gecxOnCartTokenObserved();
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
 * The /cart-session route's answer for each cart session asked about on this
 * page view, keyed by gecxCartTokenSession(): in flight, or settled with
 * 'agent' or 'existing', which hold for the rest of the page view. Any other
 * answer, and a failure, is dropped so that the next chat open or agent
 * change asks again.
 * @type {!Object<string, !Promise<string>>}
 */
const gecxCartSessionBindings = Object.create(null);

/** @type {boolean} Whether the route said this store's sessions cannot be bound. */
let gecxCartSessionBindingUnsupported = false;

/** @type {boolean} Whether a chat-open bind is waiting for the agent's cart token. */
let gecxCartSessionBindPending = false;

/** @type {boolean} Whether the chat was open at the last visibility check. */
let gecxChatWasOpen = false;

/**
 * The claims in a Cart-Token's payload, or null when they cannot be read.
 *
 * Not verified, which only the server can do. They are only used to skip a
 * remembered token that has expired and to tell which cart session a token
 * is for.
 * @param {string} token
 * @return {?Object}
 */
function gecxCartTokenClaims(token) {
  if (!gecxIsValidCartTokenFormat(token) || typeof atob !== 'function') {
    return null;
  }
  try {
    const payload = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
    const claims = JSON.parse(atob(payload + '==='.slice((payload.length + 3) % 4)));
    return (claims && typeof claims === 'object') ? claims : null;
  } catch (err) {
    return null;
  }
}

/**
 * The cart session a Cart-Token is for: its user_id claim, or the token
 * itself when that cannot be read.
 *
 * The route mints a new token for the agent's cart when it binds the browser
 * to it, so two tokens can be for the same cart session.
 * @param {string} token
 * @return {string}
 */
function gecxCartTokenSession(token) {
  const claims = gecxCartTokenClaims(token);
  const session = claims ? claims.user_id : null;
  if ((typeof session === 'string' || typeof session === 'number') && session !== '') {
    return 'session:' + session;
  }
  return 'token:' + token;
}

/**
 * Whether a Cart-Token's exp claim has passed.
 * @param {string} token
 * @return {boolean}
 */
function gecxIsExpiredCartToken(token) {
  const claims = gecxCartTokenClaims(token);
  return !!claims && typeof claims.exp === 'number' && claims.exp * 1000 <= Date.now();
}

/**
 * The cart token the chat widget resumed for the current chat session, from
 * localStorage, or ''.
 *
 * On a later page view the widget puts the agent back on the cart it last
 * synced for the chat session (gecx_session_cart) without reading it from the
 * Store API, so that token never passes through this script's fetch
 * interceptor. The cart the widget does read on such a page view is a new,
 * empty one for a browser without a WooCommerce session.
 *
 * A token whose exp has passed is skipped: the route cannot bind it, and it
 * would otherwise take the place of a fresh one for the whole page view.
 * @return {string}
 */
function gecxResumedAgentCartToken() {
  try {
    const sessionId = window.localStorage.getItem('gecx_session_id');
    const remembered = JSON.parse(window.localStorage.getItem('gecx_session_cart') || 'null');
    if (sessionId && remembered && remembered.sessionId === sessionId &&
        gecxIsValidCartTokenFormat(remembered.cartId) &&
        !gecxIsExpiredCartToken(remembered.cartId)) {
      return remembered.cartId;
    }
  } catch (err) {
    // Unavailable or malformed storage: fall back to the observed token.
  }
  return '';
}

/**
 * Whether the chat panel is open, by the gecx-chat-open class the widget puts
 * on document.body from its own visibility state.
 *
 * The chat-messenger element is not a reliable signal here: it can be in the
 * page without its messenger-hidden class while the widget is still starting,
 * and binding then would set a session cookie for a visitor who never opened
 * the chat.
 * @return {boolean}
 */
function gecxIsChatOpen() {
  return !!document.body && document.body.classList.contains('gecx-chat-open');
}

/**
 * Puts the browser on the agent's cart when the chat opens.
 *
 * A guest who has not added anything to their cart has no WooCommerce session
 * cookie, so the agent can hold a cart the browser is not bound to, most often
 * one the widget resumed from an earlier page view. The /cart-session route
 * binds the browser to that cart once it holds something and the browser's
 * own cart is empty. Done when the chat opens, not on page load: once a
 * session cookie is set, full-page caches stop serving that visitor cached
 * pages.
 *
 * Uses the cart token the widget resumed, or else the one its cart read
 * revealed, waiting for that read if needed. When the browser is put on the
 * agent's cart, reads that cart and brings the page's cart surfaces up to
 * date, as after an agent change. When there is nothing to bind yet, the next
 * chat open tries again.
 */
function gecxBindCartSession() {
  if (gecxCartSessionBindingUnsupported) {
    return;
  }
  const agentToken = gecxResumedAgentCartToken() || gecxLatestCartToken;
  if (!agentToken) {
    gecxCartSessionBindPending = true;
    return;
  }
  gecxCartSessionBindPending = false;
  if (gecxCartSessionBindings[gecxCartTokenSession(agentToken)]) {
    // Already asked, or being asked, about this cart session.
    return;
  }

  gecxRequestCartSessionBinding(agentToken)
      .then(function(binding) {
        if (binding === 'agent') {
          gecxRebindSessionOnCartUpdate();
          // The shopper only opened the chat, so nothing may open a drawer
          // over it.
          gecxRefreshCartSurfaces(agentToken, null, null, Promise.resolve(), false);
        }
      }, function(err) {
        gecxReportError('cart session binding', err);
      });
}

/**
 * Puts the browser on the agent's cart after the agent changed it.
 *
 * The cart read that follows an agent change binds a browser without a
 * WooCommerce session cookie, but not one that has a session of its own,
 * however empty: WooCommerce starts one for a visitor who opens the cart or
 * checkout page, and for one whose browser merely prefetches it. Whatever the
 * agent added then stays in a cart the shopper never sees. The /cart-session
 * route binds a browser whose own cart is empty, cookie or not, and leaves one
 * whose cart holds items to the chat widget, which adopts it after copying the
 * agent's lines into it.
 *
 * Asks once per cart session: an 'agent' or 'existing' answer holds for the
 * rest of the page view, so later changes to the same cart are read without
 * waiting for the route. Takes over a chat-open bind that is waiting for the
 * agent's cart token. Never rejects, so the cart is read whatever happens
 * here.
 * @param {string} agentToken Cart-Token of the cart the agent changed, or ''.
 * @return {!Promise<void>}
 */
function gecxBindCartSessionAfterAgentChange(agentToken) {
  if (!agentToken || gecxCartSessionBindingUnsupported) {
    return Promise.resolve();
  }
  gecxCartSessionBindPending = false;
  return gecxRequestCartSessionBinding(agentToken)
      .then(function() {}, function(err) {
        gecxReportError('cart session binding', err);
      });
}

/**
 * Asks the /cart-session route to put the browser on the agent's cart, and
 * announces each answer with a gecx:cart-session-bound event on
 * document.body.
 *
 * Returns the request already made for the same cart session while it is in
 * flight or once it answered 'agent' or 'existing'. With 'agent' the route
 * sends a token for the agent's cart in a Cart-Token response header, never
 * in the body: tools that record response bodies, such as session replay,
 * would keep a copy. 'unsupported' stops all binding for the page view.
 * @param {string} agentToken Cart-Token of the agent's cart.
 * @return {!Promise<string>} The route's answer: 'agent', 'existing',
 *     'deferred' or 'unsupported'.
 */
function gecxRequestCartSessionBinding(agentToken) {
  const session = gecxCartTokenSession(agentToken);
  if (gecxCartSessionBindings[session]) {
    return gecxCartSessionBindings[session];
  }
  const request = gecxResolveRestNonce()
      .then(function(nonce) {
        if (!nonce) {
          throw new Error('no REST nonce');
        }
        return fetch(gecxCartSessionRestUrl(), {
          method: 'POST',
          credentials: 'include',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': nonce
          },
          body: JSON.stringify({cart_token: agentToken})
        });
      })
      .then(function(res) {
        if (res.status === 401 || res.status === 403) {
          // Most likely a stale nonce; fetch a fresh one next time.
          gecxRestNoncePromise = null;
        }
        if (!res.ok) {
          throw new Error('cart-session responded ' + res.status);
        }
        const cartToken = (res.headers && typeof res.headers.get === 'function') ?
            (res.headers.get('Cart-Token') || '') :
            '';
        return res.json().then(function(data) {
          const binding = (data && typeof data.binding === 'string') ? data.binding : '';
          if (binding === 'agent' && gecxIsValidCartTokenFormat(cartToken)) {
            gecxLatestCartToken = cartToken;
          }
          if (binding === 'unsupported') {
            gecxCartSessionBindingUnsupported = true;
          }
          gecxDispatchBodyEvent('gecx:cart-session-bound', {binding: binding});
          return binding;
        });
      });

  const forget = function() {
    if (gecxCartSessionBindings[session] === request) {
      delete gecxCartSessionBindings[session];
    }
  };
  gecxCartSessionBindings[session] = request;
  request.then(function(binding) {
    if (binding !== 'agent' && binding !== 'existing') {
      forget();
    }
  }, forget);
  return request;
}

/** Runs a chat-open bind that was waiting for the agent's cart token. */
function gecxOnCartTokenObserved() {
  if (gecxCartSessionBindPending && gecxChatWasOpen) {
    gecxBindCartSession();
  }
}

/**
 * Binds the cart when the chat goes from closed to open.
 *
 * Only on that change: the body class mutations that also call this fire for
 * every class a theme or the widget toggles while the chat stays open, and a
 * bind that answered 'deferred' or failed is meant to be retried on the next
 * open, not on each of them.
 * @param {?Event=} e chat-messenger-visibility-changed, whose detail.hidden
 *     is the widget's own visibility state, or nothing for a body class change.
 */
function gecxHandleChatVisibilityForCart(e) {
  const detail = e && e.detail;
  const isOpen = (detail && typeof detail.hidden === 'boolean') ? !detail.hidden : gecxIsChatOpen();
  const opened = isOpen && !gecxChatWasOpen;
  gecxChatWasOpen = isOpen;
  if (opened) {
    gecxBindCartSession();
  } else if (!isOpen) {
    gecxCartSessionBindPending = false;
  }
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
          return fetch(gecxUncachedCartRestUrl(), {
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
 *
 * Added items are announced as a removal too when they may not open a drawer
 * or side cart.
 * @param {string} change 'added', 'removed' or 'updated'.
 * @param {boolean} preserveCartData Whether the data store already holds the new cart.
 * @param {?Object} cart
 * @param {?number} itemsCount
 * @param {?number} previousItemsCount
 * @param {boolean} allowOpen Whether added items may open a drawer or side cart.
 */
function gecxAnnounceCartChange(change, preserveCartData, cart, itemsCount, previousItemsCount, allowOpen) {
  const settings = gecxCartRefreshConfig();
  const sendLegacy = settings.legacyEvents && window.jQuery &&
      window.jQuery(document.body).trigger;
  const announceAdded = allowOpen && change === 'added';

  if (sendLegacy) {
    window.jQuery(document.body).trigger(announceAdded ? 'added_to_cart' : 'removed_from_cart');
  } else if (settings.nativeEvents) {
    gecxDispatchBodyEvent(
        announceAdded ? 'wc-blocks_added_to_cart' : 'wc-blocks_removed_from_cart',
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

  // Read once the binding has settled, so that the cart read, the surfaces
  // refreshed from it and the gecx:cart-updated event all describe the cart
  // the browser ends up on. The GECX session is re-bound only then too: a
  // response that refreshes the browser's old WooCommerce session cookie
  // would undo the binding if it arrived after it.
  const bound = gecxBindCartSessionAfterAgentChange(cartId).then(function() {
    gecxRebindSessionOnCartUpdate();
  });
  gecxRefreshCartSurfaces(cartId, directCart, eventCount, bound, true);
}

/**
 * Reads the cart the browser is on, brings every cart surface on the page up
 * to date with it, and announces the change.
 *
 * The Blocks cart data store is marked stale at once, and given the cart the
 * widget sent, if any. The cart is read once `ready` settles.
 * @param {string} cartToken Cart-Token to read the cart with, or ''.
 * @param {?Object} directCart Cart the chat widget sent, or null.
 * @param {?number} eventCount Items count the chat widget sent, or null.
 * @param {!Promise<void>} ready Settles, never rejecting, when the cart can
 *     be read.
 * @param {boolean} allowOpen Whether added items may open a drawer or side
 *     cart.
 */
function gecxRefreshCartSurfaces(cartToken, directCart, eventCount, ready, allowOpen) {
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
    // only now, after the binding and the cart read: for a shopper whose
    // browser was not on the agent's cart, those are what hand the agent's
    // cart session to the browser, and fragments requested before them
    // describe an empty cart. A no-op on pages without wc-cart-fragments.
    if (window.jQuery && window.jQuery(document.body).trigger) {
      window.jQuery(document.body).trigger('wc_fragment_refresh');
    }
    gecxRefreshCartOrCheckoutSurface(storeUpdated);
    gecxAnnounceCartChange(
        gecxClassifyCartChange(previousCount, count), storeUpdated, cart || directCart,
        count, previousCount, allowOpen);
  };

  ready
      .then(function() {
        return gecxFetchCart(cartToken);
      })
      .then(finish, function(err) {
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
 * @param {boolean=} ignoreOwnVisibility Judge visibility by the parent, so an
 *     element that hides itself (a launcher the widget has not revealed yet)
 *     still counts when the page around it is visible.
 * @return {boolean}
 */
function gecxIsOnScreen(el, ignoreOwnVisibility) {
  if (!el || typeof el.getClientRects !== 'function' ||
      typeof window.getComputedStyle !== 'function') {
    return false;
  }
  const rects = el.getClientRects();
  if (!rects || !rects.length) {
    return false;
  }
  const style = window.getComputedStyle(el);
  if (style.display === 'none') {
    return false;
  }
  const visibilityStyle = ignoreOwnVisibility && el.parentElement ?
      window.getComputedStyle(el.parentElement) : style;
  if (visibilityStyle.visibility === 'hidden') {
    return false;
  }
  const viewportWidth = window.innerWidth ||
      (document.documentElement && document.documentElement.clientWidth) || 0;
  let onScreen = false;
  for (let i = 0; i < rects.length; i++) {
    if (rects[i].right > 0 && (!viewportWidth || rects[i].left < viewportWidth)) {
      onScreen = true;
      break;
    }
  }
  return onScreen && !gecxIsClippedAway(el);
}

/**
 * Whether an ancestor that clips its overflow hides the element entirely,
 * as collapsed menus do (Storefront's handheld menu is `max-height: 0;
 * overflow: hidden`). Such an element has a size and a position but can't
 * be seen.
 * @param {!Element} el
 * @return {boolean}
 */
function gecxIsClippedAway(el) {
  const box = el.getBoundingClientRect();
  let ancestor = el.parentElement;
  for (let depth = 0; ancestor && ancestor !== document.body && depth < 12; depth++) {
    const style = window.getComputedStyle(ancestor);
    if (style.overflowX !== 'visible' || style.overflowY !== 'visible') {
      const clip = ancestor.getBoundingClientRect();
      if (box.right <= clip.left || box.left >= clip.right ||
          box.bottom <= clip.top || box.top >= clip.bottom) {
        return true;
      }
    }
    ancestor = ancestor.parentElement;
  }
  return false;
}

/**
 * Whether the chat widget itself is hiding a launcher. The widget hides
 * every launcher on the page while it decides whether to show them, for
 * example on a first visit before its enablement state is cached, and
 * shows them again afterwards.
 * @param {!Element} button
 * @return {boolean}
 */
function gecxIsHiddenByWidget(button) {
  return button.classList.contains('gecx-hidden') ||
      button.hasAttribute('data-gecx-mobile-hidden');
}

/**
 * Whether the widget has not revealed this launcher yet. Until it knows the
 * agent is enabled, every launcher is laid out but hidden (visibility: hidden,
 * opacity: 0), and they are all revealed together by the `visible` class. The
 * persistent entry point manages its own visibility and is never held back.
 * @param {!Element} button
 * @return {boolean}
 */
function gecxIsAwaitingReveal(button) {
  return !button.classList.contains('visible') &&
      button.getAttribute('entry-type') !== 'persistent';
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
  // Accessible toggles inside semantic or builder headers
  'header button[aria-label*="menu" i]',
  'header button[aria-controls*="nav" i]',
  'header button[aria-controls*="menu" i]',
  'header a[aria-label*="menu" i]',
  'header [role="button"][aria-label*="menu" i]',
  '[data-elementor-type="header"] button[aria-label*="menu" i]',
  '.elementor-location-header button[aria-label*="menu" i]',
  '[data-elementor-type="header"] [role="button"][aria-label*="menu" i]',
  '.elementor-location-header [role="button"][aria-label*="menu" i]',
  // Frameworks and popular themes (Bootstrap, Astra, GeneratePress,
  // Storefront, Kadence, Neve, Elementor, Max Mega Menu, Divi)
  '.navbar-toggle',
  '.navbar-toggler',
  '.off-canvas-toggle',
  '.menu-toggle',
  '.menu-toggle-open',
  '.mobile-menu-toggle',
  '.elementor-menu-toggle',
  '.mega-menu-toggle .mega-toggle-animated',
  '.mega-menu-toggle button',
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
      'header .cart-contents',
      '[data-elementor-type="header"] .elementor-menu-cart__toggle_button',
      '.elementor-location-header .elementor-menu-cart__toggle_button'
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
  const tagLower = expectedTagName.toLowerCase();
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
  const btnEl = gecxCreateSafeWidgetElement(gecxConfigMarkup(cfg, 'button'), 'gecx-agent-button');
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
 * Selectors for top-level navigation menu lists inside semantic and page
 * builder headers (including Elementor Theme Builder and Max Mega Menu).
 * @const {string}
 */
const GECX_HEADER_MENU_LIST_SELECTORS =
    'header nav ul, [data-elementor-type="header"] nav ul, .elementor-location-header nav ul, ' +
    'nav.main-navigation ul, nav.primary-navigation ul, nav.elementor-nav-menu--main ul, #site-navigation ul, ' +
    'header .wp-block-navigation__container, [data-elementor-type="header"] .wp-block-navigation__container, .elementor-location-header .wp-block-navigation__container, ' +
    'header .nav-menu, [data-elementor-type="header"] .nav-menu, .elementor-location-header .nav-menu, ' +
    'header ul.menu, [data-elementor-type="header"] ul.menu, .elementor-location-header ul.menu, ' +
    '.mega-menu-wrap > ul.mega-menu, ul.max-mega-menu';

/**
 * Selectors for header columns that may be too narrow to hold both a mobile
 * hamburger toggle and an inline launcher pill.
 * @const {string}
 */
const GECX_MOBILE_HEADER_COLUMN_SELECTORS =
    '.elementor-column, .e-con, [class*="col-"]';

/**
 * Selectors for full-width header sections or containers that can hold a
 * centered launcher row when a narrow column cannot fit it inline.
 * @const {string}
 */
const GECX_MOBILE_HEADER_ROW_CONTAINER_SELECTORS =
    '.elementor-top-section, .e-con-full, header, [data-elementor-type="header"], .elementor-location-header, .site-header, #masthead, [role="banner"]';

/**
 * Maximum column width (as a fraction of the viewport width) considered a
 * narrow multi-column mobile header cell.
 * @const {number}
 */
const GECX_NARROW_HEADER_COLUMN_MAX_VIEWPORT_RATIO = 0.5;

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
      GECX_HEADER_MENU_LIST_SELECTORS,
      function(list) {
        if (list.closest('.sub-menu, .children, .dropdown-menu, .mega-sub-menu, .elementor-nav-menu--dropdown') ||
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
  if (!cfg || !cfg.isWidgetEnabled || cfg.placement !== 'nav_menu' || !gecxConfigMarkup(cfg, 'button')) {
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
 * Header section or container that can hold a full-width centered launcher row
 * when the mobile hamburger sits inside a column too narrow for both.
 * @param {!Element} anchor
 * @return {?Element}
 */
function gecxFindMobileHeaderRowContainer(anchor) {
  if (typeof anchor.closest !== 'function') {
    return null;
  }
  const col = anchor.closest(GECX_MOBILE_HEADER_COLUMN_SELECTORS);
  if (!col || typeof col.getBoundingClientRect !== 'function' ||
      typeof anchor.getBoundingClientRect !== 'function') {
    return null;
  }
  const viewportWidth = window.innerWidth || 0;
  const colRect = col.getBoundingClientRect();
  if (colRect.width <= 0 || !viewportWidth ||
      colRect.width >= viewportWidth * GECX_NARROW_HEADER_COLUMN_MAX_VIEWPORT_RATIO) {
    return null;
  }
  // A column narrower than the toggle plus a collapsed pill (~56px) cannot fit
  // both side by side without overflowing into adjacent header columns.
  const anchorRect = anchor.getBoundingClientRect();
  if (colRect.width - anchorRect.width >= 56) {
    return null;
  }
  return anchor.closest(GECX_MOBILE_HEADER_ROW_CONTAINER_SELECTORS);
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
      mobileButton.style.removeProperty('display');
      let rowContainer = gecxFindMobileHeaderRowContainer(anchor);
      if (!rowContainer) {
        mobileButton.classList.remove('gecx-mobile-header-button--row');
        if (mobileButton.nextSibling !== anchor) {
          anchor.parentNode.insertBefore(mobileButton, anchor);
        }
        // If the rendered button still overflows a narrow column, move it to
        // its own centered row in the header.
        const col = typeof anchor.closest === 'function' ?
            anchor.closest(GECX_MOBILE_HEADER_COLUMN_SELECTORS) : null;
        if (col && typeof col.getBoundingClientRect === 'function' &&
            typeof mobileButton.getBoundingClientRect === 'function') {
          const colRect = col.getBoundingClientRect();
          const btnRect = mobileButton.getBoundingClientRect();
          const anchorRect = anchor.getBoundingClientRect();
          if (colRect.width > 0 &&
              colRect.width < (window.innerWidth || 0) * GECX_NARROW_HEADER_COLUMN_MAX_VIEWPORT_RATIO &&
              btnRect.width > 0 &&
              (Math.max(btnRect.right, anchorRect.right) > colRect.right + 4 ||
               Math.min(btnRect.left, anchorRect.left) < colRect.left - 4)) {
            rowContainer = anchor.closest(GECX_MOBILE_HEADER_ROW_CONTAINER_SELECTORS);
          }
        }
      }
      if (rowContainer) {
        mobileButton.classList.add('gecx-mobile-header-button--row');
        if (mobileButton.parentNode !== rowContainer) {
          rowContainer.appendChild(mobileButton);
        }
      }
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
  let hiddenByWidget = false;
  gecxEach('gecx-agent-button', function(button) {
    if (fallback && fallback.contains(button)) {
      return;
    }
    if (gecxIsHiddenByWidget(button)) {
      hiddenByWidget = true;
    } else if (!visible && gecxIsOnScreen(button, gecxIsAwaitingReveal(button))) {
      // A launcher in place but not yet revealed counts: it is revealed with
      // the fallback, which would otherwise flash beside it until the next
      // placement pass hides the fallback again.
      visible = true;
    }
  });

  // While the widget is hiding launchers it hides a fallback too, and the
  // launcher it hid comes back on its own. Adding a fallback then would leave
  // two launchers once the widget shows them again.
  if (visible || hiddenByWidget) {
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
 * The URL, without its fragment, that the server rendered this page and its
 * config for.
 * @const {string}
 */
const gecxRenderedUrl = (typeof window !== 'undefined' && window.location) ?
    String(window.location.href).split('#')[0] : '';

/**
 * Whether the page is still the one the config was rendered for. Themes that
 * switch pages without a full page load (swup, barba, PJAX, Turbo) change the
 * URL but keep the first page's config, so anything in it about that page's
 * product is stale once this is false.
 * @return {boolean}
 */
function gecxOnRenderedPage() {
  return String(window.location.href).split('#')[0] === gecxRenderedUrl;
}

/**
 * Whether the page shows a single product, including pages loaded without a
 * full page load and products embedded with [product_page].
 * @param {!Object} cfg
 * @return {boolean}
 */
function gecxPageShowsProduct(cfg) {
  if (cfg.isPdp && gecxOnRenderedPage()) {
    return true;
  }
  return !!((document.body && document.body.classList &&
             document.body.classList.contains('single-product')) ||
            document.querySelector('.wp-block-woocommerce-single-product'));
}

// Client-side DOM check for PDP suggested prompts placement (Page builders & non-standard templates fallback).
function initPdpPlacement() {
  const cfg = gecxGetStorefrontConfig();
  if (!cfg || !cfg.isWidgetEnabled || !gecxConfigMarkup(cfg, 'prompts') || !gecxPageShowsProduct(cfg)) {
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

  // The product page's own markup carries that product's prompt overrides,
  // so it is only right on the page it was rendered for.
  const productPrompts = gecxConfigMarkup(cfg, 'productPrompts');
  const promptsHtml = (productPrompts && gecxOnRenderedPage()) ?
      productPrompts : gecxConfigMarkup(cfg, 'prompts');
  const promptsEl = gecxCreateSafeWidgetElement(promptsHtml, 'gecx-suggested-prompts');
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
 * Fixed third-party corner widgets (chat bubbles, SMS launchers, store badges)
 * that sit at the bottom edge of the viewport and would otherwise cover a
 * bottom-corner floating or fallback launcher. The Google Merchant Center
 * store widget mounts its wrapper at 0x0 and sizes it once its iframe loads.
 * Its wrapper is a direct child of <body>, which the offset pass scans anyway;
 * it is listed so it is still found if a theme or optimizer re-parents it.
 * @const {string}
 */
const GECX_CORNER_WIDGET_SELECTORS =
    '#podium-bubble, #podium-website-widget [style*="fixed"], #intercom-container, .intercom-lightweight-app-launcher, #tidio-chat, #tidio-chat-iframe, .crisp-client, #hubspot-messages-iframe-container, #gorgias-chat-container, #launcher, #chat-widget-container, #google-merchantwidget-iframe-wrapper';

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

/** @type {?number} */
let gecxFloatingOffsetTimer = null;

/**
 * Re-measures the floating offset shortly, coalescing bursts of changes.
 * Only the offset is recomputed, not the whole placement pass.
 */
function gecxScheduleFloatingOffset() {
  clearTimeout(gecxFloatingOffsetTimer);
  gecxFloatingOffsetTimer = setTimeout(updateFloatingOffset, 100);
}

// observe() on an element already observed re-delivers an initial
// notification, which would re-run the measurement forever. Watched elements
// are remembered here so each is observed once.
/** @type {?WeakSet<!Element>} */
const gecxWatchedFixedElements = typeof WeakSet !== 'undefined' ? new WeakSet() : null;

/**
 * Whether fixed elements can be watched for size changes. Without it, the
 * content observer re-measures on attribute changes of <body> children alone.
 * @const {boolean}
 */
const gecxCanWatchSize = typeof ResizeObserver !== 'undefined' && !!gecxWatchedFixedElements;

/** @type {?ResizeObserver} */
let gecxFixedResizeObserver = null;

/**
 * Re-measures the floating offset when a fixed element changes size.
 *
 * Third-party widgets often mount empty and size themselves after their
 * iframe loads. Adding them triggers a placement pass while they are still
 * 0x0, and the later resize can come from a class or stylesheet the content
 * observer never sees. Without this, a widget like the Merchant Center store
 * widget covers the launcher until the window resizes.
 * @param {!Element} el
 */
function gecxWatchFixedElementSize(el) {
  if (!gecxCanWatchSize || gecxWatchedFixedElements.has(el)) {
    return;
  }
  if (!gecxFixedResizeObserver) {
    gecxFixedResizeObserver = new ResizeObserver(function(entries) {
      // Let go of removed elements so pages that swap content don't keep
      // them alive. One that is attached again is watched again.
      for (let i = 0; i < entries.length; i++) {
        const target = entries[i].target;
        if (!target.isConnected) {
          gecxFixedResizeObserver.unobserve(target);
          gecxWatchedFixedElements.delete(target);
        }
      }
      gecxScheduleFloatingOffset();
    });
  }
  gecxWatchedFixedElements.add(el);
  // The first notification arrives for every newly watched element, so each
  // costs one extra, idempotent re-measure.
  gecxFixedResizeObserver.observe(el);
}

/**
 * Lifts floating launchers above bars the theme fixes to the bottom of the
 * viewport (handheld footer bars, sticky add-to-cart bars, mobile toolbars)
 * and fixed third-party corner widgets (such as Podium or Intercom) occupying
 * the same bottom corner.
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
  let hasCornerLauncher = false;
  let cornerLeft = 0;
  let cornerRight = 0;
  gecxEach(
      '.gecx-floating-button-container--bottom-right, .gecx-floating-button-container--bottom-left, .gecx-launcher-fallback',
      function(launcher) {
        if (hasCornerLauncher || window.getComputedStyle(launcher).display === 'none') {
          return;
        }
        hasCornerLauncher = true;
        const isLeft = launcher.classList.contains('gecx-floating-button-container--bottom-left') ||
            (launcher.classList.contains('gecx-launcher-fallback') && document.body.classList.contains('rtl'));
        cornerLeft = isLeft ? 0 : Math.max(0, viewportWidth - 200);
        cornerRight = isLeft ? Math.min(viewportWidth, 200) : viewportWidth;
        if (typeof launcher.getBoundingClientRect === 'function') {
          const r = launcher.getBoundingClientRect();
          if (r.width > 0) {
            cornerLeft = Math.min(cornerLeft, r.left);
            cornerRight = Math.max(cornerRight, r.right);
          }
        }
      });

  let offset = 0;
  const consider = function(el) {
    if (gecxIsOwnElement(el) || typeof el.getBoundingClientRect !== 'function') {
      return;
    }
    const style = window.getComputedStyle(el);
    if (style.position !== 'fixed') {
      return;
    }
    // Watched before the size checks: a widget that mounts at 0x0 or hidden
    // and grows later (the Merchant Center store widget) must be measured
    // again once it has a size.
    gecxWatchFixedElementSize(el);
    if (style.display === 'none' || style.visibility === 'hidden') {
      return;
    }
    const rect = el.getBoundingClientRect();
    if (rect.height > 0 && rect.height < viewportHeight * 0.4 &&
        rect.width > viewportWidth * 0.5 && rect.bottom >= viewportHeight - 2 &&
        rect.top > viewportHeight * 0.5) {
      offset = Math.max(offset, Math.ceil(viewportHeight - rect.top));
    } else if (hasCornerLauncher && rect.height > 0 && rect.height <= 140 &&
        rect.width > 0 && rect.width <= 260 &&
        rect.bottom >= viewportHeight - 32 && rect.top >= viewportHeight - 160 &&
        rect.left < cornerRight && rect.right > cornerLeft) {
      offset = Math.max(offset, Math.ceil(viewportHeight - rect.top));
    }
  };
  const children = document.body.children || [];
  for (let i = 0; i < children.length; i++) {
    consider(children[i]);
    if (children[i].firstElementChild) {
      consider(children[i].firstElementChild);
    }
  }
  gecxEach(GECX_BOTTOM_BAR_SELECTORS + ', ' + GECX_CORNER_WIDGET_SELECTORS, consider);

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
    // The WordPress admin bar stays full width: the chat panel starts below
    // it, so narrowing it would leave a blank strip above the panel.
    if (found.indexOf(el) !== -1 || gecxIsOwnElement(el) ||
        typeof el.getBoundingClientRect !== 'function' ||
        el.id === 'wpadminbar' || (typeof el.closest === 'function' && el.closest('#wpadminbar'))) {
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
window.addEventListener('chat-messenger-visibility-changed', gecxHandleChatVisibilityForCart);
document.addEventListener('chat-messenger-visibility-changed', gecxHandleChatVisibilityForCart);

if (typeof MutationObserver !== 'undefined' && document.body) {
  const chatObserver = new MutationObserver(function(mutations) {
    for (let i = 0; i < mutations.length; i++) {
      if (mutations[i].attributeName === 'class') {
        updateFloatingWidgetCentering();
        gecxHandleChatVisibilityForCart();
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
      // The widget showing or hiding a launcher changes whether the page
      // needs the fallback.
      if (mutations[i].type === 'attributes') {
        if (mutations[i].target.tagName === 'GECX-AGENT-BUTTON') {
          gecxScheduleReinit();
          return;
        }
        // A widget can be shown, hidden or moved without changing size, which
        // the ResizeObserver doesn't report. Re-measure when a child of <body>
        // or a watched fixed element changes; without ResizeObserver this also
        // catches widgets sizing themselves through their style attribute.
        const target = mutations[i].target;
        if (!gecxIsOwnElement(target) && (target.parentElement === document.body ||
            (gecxWatchedFixedElements && gecxWatchedFixedElements.has(target)))) {
          gecxScheduleFloatingOffset();
        }
        continue;
      }
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
  contentObserver.observe(document.body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['hidden', 'class', 'style']
  });
}
})();
