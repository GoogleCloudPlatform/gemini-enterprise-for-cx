/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * storefront.js placement against static pages modeled on the markup of real
 * themes, in a real browser. The plugin's own CSS and markup are printed by
 * tests/e2e/bin/print-storefront-assets.php, so a change to either is tested
 * as shipped.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { stubWidgetBundle, visibleLaunchers, settle } = require('../support/launchers');

const ROOT = path.join(__dirname, '..', '..', '..');
const assets = JSON.parse(execFileSync('php', [path.join(ROOT, 'tests/e2e/bin/print-storefront-assets.php')]).toString());
const STOREFRONT_JS = fs.existsSync(path.join(ROOT, 'build/scripts/storefront/index.min.js'))
  ? fs.readFileSync(path.join(ROOT, 'build/scripts/storefront/index.min.js'), 'utf8')
  : fs.readFileSync(path.join(ROOT, 'packages/storefront/src/index.js'), 'utf8');
const BUTTON = assets.buttonHtml;
const WIDGET_URL = 'https://www.gstatic.com/gecx/chat-widget/woocommerce-chat-widget.js';

/**
 * Opens a page built from theme markup, the plugin's CSS and storefront.js.
 */
async function openFixture(page, options) {
  const { width = 1280, height = 800, bodyClass = '', themeCss = '', body = '', config = {}, loadWidget = true } = options;
  await page.setViewportSize({ width, height });
  await stubWidgetBundle(page);
  const cfg = Object.assign({
    placement: 'nav_menu',
    isWidgetEnabled: true,
    markup: { button: BUTTON },
    widgetScriptUrl: WIDGET_URL,
    shouldLoadWidget: '1',
    cartRestUrl: 'https://store.test/wp-json/wc/store/v1/cart',
    authContextUrl: 'https://store.test/wp-json/gecx/v1/auth-context',
    sessionUrl: 'https://store.test/wp-json/gecx/v1/session',
  }, config);
  const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">
<style>${assets.css}</style><style>body{margin:0;font-family:sans-serif} ${themeCss}</style></head>
<body class="${bodyClass}">${body}
<script>window.gecxStorefrontConfig = ${JSON.stringify(cfg)};</script>
${loadWidget ? `<script src="${WIDGET_URL}"></script>` : ''}
<script src="https://store.test/storefront.js"></script></body></html>`;
  await page.route('https://store.test/**', (route) => {
    const url = route.request().url();
    if (url.endsWith('/storefront.js')) {
      return route.fulfill({ contentType: 'application/javascript', body: STOREFRONT_JS });
    }
    if (url.includes('/wp-json/')) {
      return route.fulfill({ contentType: 'application/json', body: '{}' });
    }
    return route.fulfill({ contentType: 'text/html', body: html });
  });
  await page.goto('https://store.test/');
  await settle(page);
}

const navItem = `<li class="menu-item gecx-nav-menu-item">${BUTTON}</li>`;

// Astra, Kadence, Divi and OceanWP switch to the hamburger well above 768px.
const breakpointTheme = {
  themeCss: `.inner{display:flex;align-items:center;gap:8px;padding:10px}
    .main-navigation ul{display:flex;list-style:none;margin:0;padding:0;gap:10px}
    .menu-toggle{display:none}
    @media (max-width:921px){.main-navigation{display:none}.menu-toggle{display:inline-block}}`,
  body: `<header id="masthead" class="site-header"><div class="inner"><a class="logo" href="#">Logo</a>
    <nav class="main-navigation"><ul class="menu"><li class="menu-item"><a href="#">Shop</a></li><li class="menu-item"><a href="#">About</a></li>${navItem}</ul></nav>
    <button class="menu-toggle" aria-controls="primary-menu" aria-expanded="false">Menu</button></div></header>
    <main><p>Content</p></main><footer class="site-footer">Footer</footer>`,
};

test.describe('hamburger breakpoints', () => {
  for (const [width, expected] of [[375, 'gecx-mobile-header-button'], [800, 'gecx-mobile-header-button'], [1280, 'gecx-nav-menu-item']]) {
    test(`exactly one launcher at ${width}px on a theme switching at 921px`, async ({ page }) => {
      await openFixture(page, { width, ...breakpointTheme });
      const launchers = await visibleLaunchers(page);
      expect(launchers).toHaveLength(1);
      expect(launchers[0].container).toContain(expected);
    });
  }

  test('follows the hamburger when the window is resized', async ({ page }) => {
    await openFixture(page, { width: 1280, ...breakpointTheme });
    await page.setViewportSize({ width: 800, height: 800 });
    await settle(page);
    const launchers = await visibleLaunchers(page);
    expect(launchers).toHaveLength(1);
    expect(launchers[0].container).toContain('gecx-mobile-header-button');
  });
});

test('block navigation with the overlay always on shows the launcher on desktop', async ({ page }) => {
  await openFixture(page, {
    width: 1280,
    themeCss: '.wp-block-navigation{display:flex;align-items:center} .wp-block-navigation__responsive-container{display:none}',
    body: `<header class="wp-block-template-part"><nav class="wp-block-navigation">
      <button class="wp-block-navigation__responsive-container-open" aria-label="Open menu">Menu</button>
      <div class="wp-block-navigation__responsive-container"><div class="wp-block-navigation__responsive-container-content">
      <ul class="wp-block-navigation__container"><li class="wp-block-navigation-item"><a href="#">Shop</a></li>
      <li class="wp-block-navigation-item gecx-nav-menu-item">${BUTTON}</li></ul></div></div></nav></header>`,
  });
  const launchers = await visibleLaunchers(page);
  expect(launchers).toHaveLength(1);
  expect(launchers[0].container).toContain('gecx-mobile-header-button');
});

test('recognizes a hamburger that is a link (Flatsome)', async ({ page }) => {
  await openFixture(page, {
    width: 375,
    themeCss: 'header{display:flex} .nav-main{display:none} .mobile-nav{display:flex;list-style:none}',
    body: `<header id="header"><a href="#">Logo</a><ul class="nav-main">${navItem}</ul>
      <ul class="mobile-nav"><li class="nav-icon"><a href="#" data-open="#main-menu">&#9776;</a></li></ul></header>`,
  });
  const launchers = await visibleLaunchers(page);
  expect(launchers).toHaveLength(1);
  expect(launchers[0].container).toContain('gecx-mobile-header-button');
});

test('falls back to a floating launcher when the hamburger is not recognized', async ({ page }) => {
  const theme = {
    themeCss: 'header{display:flex;gap:10px} nav ul{display:flex;list-style:none} @media (max-width:900px){nav{display:none}}',
    body: `<header><a href="#">Logo</a><nav><ul><li><a href="#">Shop</a></li>${navItem}</ul></nav><span class="burger-xyz">&#9776;</span></header>`,
  };
  await openFixture(page, { width: 375, ...theme });
  let launchers = await visibleLaunchers(page);
  expect(launchers).toHaveLength(1);
  expect(launchers[0].container).toContain('gecx-launcher-fallback');

  await page.setViewportSize({ width: 1280, height: 800 });
  await settle(page);
  launchers = await visibleLaunchers(page);
  expect(launchers).toHaveLength(1);
  expect(launchers[0].container).toContain('gecx-nav-menu-item');
});

test('adds no fallback while the widget is briefly hiding launchers on load', async ({ page }) => {
  // The widget hides every launcher until it knows its enablement state
  // (a first visit, before that state is cached) and then shows them again.
  await openFixture(page, {
    themeCss: 'nav ul{display:flex;list-style:none;gap:8px}',
    body: `<header><nav><ul><li><a href="#">Shop</a></li>${navItem}</ul></nav></header>
      <script>
        const buttons = () => document.querySelectorAll('gecx-agent-button');
        buttons().forEach((b) => { b.setAttribute('hidden', ''); b.classList.add('gecx-hidden'); b.setAttribute('data-gecx-mobile-hidden', ''); b.style.display = 'none'; });
        setTimeout(() => buttons().forEach((b) => { b.removeAttribute('hidden'); b.classList.remove('gecx-hidden'); b.removeAttribute('data-gecx-mobile-hidden'); b.style.display = ''; }), 400);
      </script>`,
  });
  await page.waitForTimeout(800);
  const launchers = await visibleLaunchers(page);
  expect(launchers).toHaveLength(1);
  expect(launchers[0].container).toContain('gecx-nav-menu-item');
});

test('shows no fallback alongside a placed launcher the widget has not revealed yet', async ({ page }) => {
  // Until the widget knows the agent is enabled, every launcher is laid out but
  // hidden (visibility: hidden, opacity: 0) and is revealed together with the
  // rest by gaining the `visible` class. A launcher in place but not yet
  // revealed must not count as missing, or the fallback is revealed with it
  // and flashes in the corner until the next placement pass hides it.
  await openFixture(page, {
    themeCss: `nav ul{display:flex;list-style:none;gap:8px}
      gecx-agent-button{visibility:hidden;opacity:0}
      gecx-agent-button.visible{visibility:visible;opacity:1}`,
    body: `<header><nav><ul><li><a href="#">Shop</a></li>${navItem}</ul></nav></header>
      <script>
        setTimeout(() => document.querySelectorAll('gecx-agent-button')
            .forEach((b) => b.classList.add('visible')), 1200);
      </script>`,
  });
  await page.waitForFunction(() => document.querySelector('gecx-agent-button.visible'));
  const launchers = await visibleLaunchers(page);
  expect(launchers).toHaveLength(1);
  expect(launchers[0].container).toContain('gecx-nav-menu-item');
});

test('still falls back when the only launcher not yet revealed is off screen', async ({ page }) => {
  // Not being revealed yet does not make a launcher count as placed: one
  // clipped inside a collapsed menu still needs the fallback.
  await openFixture(page, {
    themeCss: `.handheld ul{max-height:0;overflow:hidden;margin:0}
      gecx-agent-button{visibility:hidden;opacity:0}
      gecx-agent-button.visible{visibility:visible;opacity:1}`,
    body: `<header><div class="handheld"><ul><li><a href="#">Shop</a></li>${navItem}</ul></div></header>
      <script>
        setTimeout(() => document.querySelectorAll('gecx-agent-button')
            .forEach((b) => b.classList.add('visible')), 1200);
      </script>`,
  });
  await page.waitForFunction(() => document.querySelector('gecx-agent-button.visible'));
  await settle(page);
  const launchers = await visibleLaunchers(page);
  expect(launchers).toHaveLength(1);
  expect(launchers[0].container).toContain('gecx-launcher-fallback');
});

test('does not count a launcher clipped inside a collapsed menu as visible', async ({ page }) => {
  // Storefront's handheld menu is collapsed with max-height:0 and overflow
  // hidden; a launcher inside it has a size but can't be seen.
  await openFixture(page, {
    width: 1280,
    themeCss: '.handheld ul{max-height:0;overflow:hidden;margin:0}',
    body: `<header><div class="handheld"><ul><li><a href="#">Shop</a></li>${navItem}</ul></div></header>`,
  });
  const launchers = await visibleLaunchers(page);
  expect(launchers).toHaveLength(1);
  expect(launchers[0].container).toContain('gecx-launcher-fallback');
});

test('leaves vertical menu layout to the theme', async ({ page }) => {
  await openFixture(page, {
    themeCss: '.vmenu{list-style:none;width:200px} .vmenu li{display:block}',
    body: `<header><nav><ul class="vmenu"><li><a href="#">Shop</a></li>${navItem}</ul></nav></header>`,
  });
  expect(await page.locator('.gecx-nav-menu-item').evaluate((el) => getComputedStyle(el).display)).toBe('block');
});

test('removes launcher menu items from footers', async ({ page }) => {
  await openFixture(page, {
    body: `<header><nav><ul><li><a href="#">Shop</a></li>${navItem}</ul></nav></header>
      <div data-elementor-type="footer"><nav><ul><li class="menu-item gecx-nav-menu-item">${BUTTON}</li></ul></nav></div>`,
  });
  expect(await page.locator('[data-elementor-type="footer"] .gecx-nav-menu-item').count()).toBe(0);
  expect(await page.locator('header .gecx-nav-menu-item').count()).toBe(1);
});

test('joins the main menu rather than a top bar menu', async ({ page }) => {
  await openFixture(page, {
    themeCss: 'nav ul{display:flex;list-style:none;gap:8px}',
    body: `<header><nav class="top-bar"><ul id="top"><li><a href="#">Account</a></li><li><a href="#">EN</a></li></ul></nav>
      <nav class="primary"><ul id="main"><li><a href="#">Shop</a></li><li><a href="#">Men</a></li><li><a href="#">Women</a></li><li><a href="#">Sale</a></li><li><a href="#">About</a></li></ul></nav></header>`,
  });
  expect(await page.locator('#main > .gecx-nav-menu-item').count()).toBe(1);
  expect(await page.locator('#top .gecx-nav-menu-item').count()).toBe(0);
});

test('places product prompts after the main add-to-cart form, not a sticky bar', async ({ page }) => {
  await openFixture(page, {
    bodyClass: 'single-product',
    config: { markup: { button: BUTTON, prompts: assets.pdpPromptsHtml }, isPdp: false },
    body: `<div class="ast-sticky-add-to-cart"><div class="sticky-add-to-cart"><form class="cart"><button>Add</button></form></div></div>
      <main><div id="product-12" class="product type-product"><div class="summary entry-summary">
      <form class="cart" id="main-form"><button>Add to cart</button></form></div></div>
      <section class="related"><ul class="products"><li><form class="cart"><button>Add</button></form></li></ul></section></main>`,
  });
  expect(await page.locator('gecx-suggested-prompts').count()).toBe(1);
  expect(await page.locator('#main-form + gecx-suggested-prompts').count()).toBe(1);
});

test('uses no product\'s overrides after a page transition to another product', async ({ page }) => {
  const productPrompts = assets.pdpPromptsHtml.replace('<gecx-suggested-prompts', '<gecx-suggested-prompts static-prompts="[&quot;Is the tuna wild caught?&quot;]"');
  const productPage = (id) => `<main><div id="product-${id}" class="product type-product"><div class="summary entry-summary">
      <form class="cart" id="form-${id}"><button>Add to cart</button></form></div></div></main>`;
  await openFixture(page, {
    bodyClass: 'single-product',
    config: { markup: { button: BUTTON, prompts: assets.pdpPromptsHtml, productPrompts: productPrompts }, isPdp: true },
    // The server already placed this product's prompts.
    body: productPage(12).replace('</form>', '</form>' + productPrompts),
  });
  expect(await page.locator('#form-12 + gecx-suggested-prompts[static-prompts]').count()).toBe(1);

  // A swup-style transition to another product: new URL and content, same config.
  await page.evaluate((html) => {
    history.pushState({}, '', '/product/salmon/');
    document.querySelector('main').outerHTML = html;
    document.dispatchEvent(new Event('swup:page:view'));
  }, productPage(34));
  await settle(page);
  expect(await page.locator('#form-34 + gecx-suggested-prompts').count()).toBe(1);
  expect(await page.locator('gecx-suggested-prompts[static-prompts]').count()).toBe(0);

  // And on to a page that shows no product: isPdp described the first page only.
  await page.evaluate(() => {
    history.pushState({}, '', '/about/');
    document.body.classList.remove('single-product');
    document.querySelector('main').outerHTML = '<main><p>About us</p><form class="cart"><button>Newsletter</button></form></main>';
    document.dispatchEvent(new Event('swup:page:view'));
  });
  await settle(page);
  expect(await page.locator('gecx-suggested-prompts').count()).toBe(0);
});

test('places the launcher in a header rendered after load', async ({ page }) => {
  await openFixture(page, { body: '<main id="app"></main>' });
  await page.evaluate(() => {
    const header = document.createElement('header');
    header.innerHTML = '<nav><ul id="late"><li><a href="#">Shop</a></li></ul></nav>';
    document.body.insertBefore(header, document.body.firstChild);
  });
  await settle(page);
  expect(await page.locator('#late > .gecx-nav-menu-item').count()).toBe(1);
  expect(await page.evaluate(() => typeof window.gecxInit)).toBe('function');
});

test('lifts the floating launcher above a fixed bottom bar', async ({ page }) => {
  await openFixture(page, {
    width: 375,
    height: 700,
    config: { placement: 'floating' },
    themeCss: '.storefront-handheld-footer-bar{position:fixed;bottom:0;left:0;right:0;height:56px;background:#333}',
    body: `<main style="height:2000px">Content</main><div class="storefront-handheld-footer-bar"></div>
      <div class="gecx-floating-button-container gecx-floating-button-container--bottom-center" style="${assets.floatingStyles.bottom_center}">${BUTTON}</div>`,
  });
  const rect = await page.locator('.gecx-floating-button-container gecx-agent-button').boundingBox();
  expect(rect.y + rect.height).toBeLessThanOrEqual(700 - 56);
});

test('narrows fixed headers while the chat panel pushes the page', async ({ page }) => {
  await openFixture(page, {
    themeCss: '.sticky{position:fixed;top:0;left:0;width:100%;height:60px;background:#eee}',
    body: `<header class="sticky"><nav><ul><li>Shop</li>${navItem}</ul></nav></header><main style="margin-top:80px">Content</main>`,
  });
  await page.evaluate(() => {
    const messenger = document.createElement('chat-messenger');
    messenger.className = 'slide-over';
    document.body.appendChild(messenger);
    document.body.style.paddingRight = '400px';
    document.body.classList.add('gecx-chat-open');
    document.dispatchEvent(new CustomEvent('chat-messenger-visibility-changed'));
  });
  await expect.poll(() => page.locator('header.sticky').evaluate((el) => el.getBoundingClientRect().right)).toBeLessThanOrEqual(1280 - 400);

  await page.evaluate(() => {
    document.body.style.paddingRight = '';
    document.body.classList.remove('gecx-chat-open');
    document.querySelector('chat-messenger').classList.add('messenger-hidden');
    document.dispatchEvent(new CustomEvent('chat-messenger-visibility-changed'));
  });
  await expect.poll(() => page.locator('header.sticky').evaluate((el) => el.style.right)).toBe('');
});

test('leaves the WordPress admin bar full width while the chat panel pushes the page', async ({ page }) => {
  await openFixture(page, {
    bodyClass: 'admin-bar',
    themeCss: '#wpadminbar{position:fixed;top:0;left:0;width:100%;height:32px;background:#1d2327}' +
        '.sticky{position:fixed;top:32px;left:0;width:100%;height:60px;background:#eee}',
    body: `<div id="wpadminbar"><div class="quicklinks">Site</div></div>
      <header class="sticky"><nav><ul><li>Shop</li>${navItem}</ul></nav></header><main style="margin-top:112px">Content</main>`,
  });
  await page.evaluate(() => {
    const messenger = document.createElement('chat-messenger');
    messenger.className = 'slide-in';
    document.body.appendChild(messenger);
    document.body.style.paddingRight = '400px';
    document.body.classList.add('gecx-chat-open');
    document.dispatchEvent(new CustomEvent('chat-messenger-visibility-changed'));
  });
  // The theme's header still moves aside, so the admin bar has been considered.
  await expect.poll(() => page.locator('header.sticky').evaluate((el) => el.getBoundingClientRect().right)).toBeLessThanOrEqual(1280 - 400);
  expect(await page.locator('#wpadminbar').evaluate((el) => el.getBoundingClientRect().width)).toBe(1280);
  expect(await page.locator('#wpadminbar').getAttribute('style')).toBeNull();
});

test('deferred loading waits for interaction even though WordPress localizes false as ""', async ({ page }) => {
  let widgetRequests = 0;
  page.on('request', (request) => {
    if (request.url() === WIDGET_URL) {
      widgetRequests++;
    }
  });
  await openFixture(page, {
    loadWidget: false,
    config: { shouldLoadWidget: '' },
    body: `<header><nav><ul>${navItem}</ul></nav></header>`,
  });
  expect(widgetRequests).toBe(0);
  await page.dispatchEvent('.gecx-nav-menu-item', 'click');
  await expect.poll(() => widgetRequests).toBe(1);
});

test('manual placement adds no launcher of its own', async ({ page }) => {
  await openFixture(page, {
    config: { placement: 'manual' },
    body: '<header><nav><ul><li><a href="#">Shop</a></li></ul></nav></header>',
  });
  expect(await page.locator('gecx-agent-button').count()).toBe(0);
});

test.describe('cart session binding', () => {
  const b64url = (value) => Buffer.from(JSON.stringify(value)).toString('base64url');
  const cartToken = (session, n = 1) =>
    `${b64url({ typ: 'JWT', alg: 'HS256' })}.${b64url({ user_id: session, exp: 9999999999, iss: 'store-api', n })}.signature`;
  const agentToken = cartToken('t_agent');
  const boundToken = cartToken('t_agent', 2);

  /**
   * Answers the Store API cart read the widget makes with the given token,
   * and the auth-context and cart-session routes. Returns the cart-session
   * requests made.
   */
  async function stubCartRoutes(page, { binding = 'agent', cartReadToken = agentToken, status = 200 } = {}) {
    const bindRequests = [];
    bindRequests.nonceLookups = 0;
    await page.route('https://store.test/wp-json/wc/store/v1/cart', (route) => route.fulfill({
      contentType: 'application/json',
      headers: { 'Cart-Token': cartReadToken },
      body: '{"items":[],"items_count":0}',
    }));
    await page.route('https://store.test/wp-json/gecx/v1/auth-context', (route) => {
      bindRequests.nonceLookups++;
      return route.fulfill({ contentType: 'application/json', body: '{"nonce":"rest-nonce"}' });
    });
    await page.route('https://store.test/wp-json/gecx/v1/cart-session', (route) => {
      bindRequests.push({ body: route.request().postDataJSON(), nonce: route.request().headers()['x-wp-nonce'] });
      return route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify({ binding, cart_token: binding === 'agent' ? boundToken : '' }),
      });
    });
    await page.evaluate(() => {
      window.__bound = [];
      document.body.addEventListener('gecx:cart-session-bound', (e) => window.__bound.push(e.detail));
    });
    return bindRequests;
  }

  const readCart = (page) => page.evaluate(() => fetch('https://store.test/wp-json/wc/store/v1/cart'));
  const openChat = (page) => page.evaluate(() => document.body.classList.add('gecx-chat-open'));
  const closeChat = (page) => page.evaluate(() => document.body.classList.remove('gecx-chat-open'));
  const fixture = { body: `<header><nav><ul>${navItem}</ul></nav></header>` };

  test('binds the agent cart once when the chat opens and announces it', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page);
    await readCart(page);
    await settle(page);
    expect(bindRequests).toHaveLength(0);

    await openChat(page);
    await expect.poll(() => page.evaluate(() => window.__bound.length)).toBe(1);
    expect([...bindRequests]).toEqual([{ body: { cart_token: agentToken }, nonce: 'rest-nonce' }]);
    expect(await page.evaluate(() => window.__bound[0])).toEqual({ cartToken: boundToken, binding: 'agent' });

    await closeChat(page);
    await openChat(page);
    await settle(page);
    expect(bindRequests).toHaveLength(1);
  });

  test('waits for the agent cart token when the chat opens first', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page);
    await openChat(page);
    await settle(page);
    expect(bindRequests).toHaveLength(0);

    await readCart(page);
    await expect.poll(() => bindRequests.length).toBe(1);
  });

  test('binds the cart the widget resumed, not the one its cart read minted', async ({ page }) => {
    const resumedToken = cartToken('t_resumed_agent_cart');
    await page.addInitScript((token) => {
      window.localStorage.setItem('gecx_session_id', 'chat-session-1');
      window.localStorage.setItem('gecx_session_cart', JSON.stringify({ sessionId: 'chat-session-1', cartId: token }));
    }, resumedToken);
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page, { cartReadToken: cartToken('t_fresh_empty_cart') });
    await readCart(page);

    await openChat(page);
    await expect.poll(() => bindRequests.length).toBe(1);
    expect(bindRequests[0].body).toEqual({ cart_token: resumedToken });
  });

  test('ignores a resumed cart remembered for another chat session', async ({ page }) => {
    await page.addInitScript((token) => {
      window.localStorage.setItem('gecx_session_id', 'chat-session-2');
      window.localStorage.setItem('gecx_session_cart', JSON.stringify({ sessionId: 'chat-session-1', cartId: token }));
    }, cartToken('t_old_agent_cart'));
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page);
    await readCart(page);

    await openChat(page);
    await expect.poll(() => bindRequests.length).toBe(1);
    expect(bindRequests[0].body).toEqual({ cart_token: agentToken });
  });

  test('binds on the widget saying the chat opened, not on a messenger element appearing', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page);
    await readCart(page);
    // A starting widget can have its messenger in the page, not yet hidden.
    await page.evaluate(() => document.body.appendChild(document.createElement('chat-messenger')));
    await page.evaluate(() => document.body.classList.add('some-theme-class'));
    await settle(page);
    expect(bindRequests).toHaveLength(0);

    await page.evaluate(() => window.dispatchEvent(
        new CustomEvent('chat-messenger-visibility-changed', { detail: { hidden: false } })));
    await expect.poll(() => bindRequests.length).toBe(1);
  });

  test('tries again on the next chat open after a deferred binding', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page, { binding: 'deferred' });
    await readCart(page);
    await openChat(page);
    await expect.poll(() => page.evaluate(() => window.__bound.length)).toBe(1);
    expect(await page.evaluate(() => window.__bound[0])).toEqual({ cartToken: '', binding: 'deferred' });

    await closeChat(page);
    await openChat(page);
    await expect.poll(() => bindRequests.length).toBe(2);
  });

  for (const [label, options] of [['a deferred binding', { binding: 'deferred' }], ['a refused request', { status: 403 }]]) {
    test(`does not retry ${label} while the chat stays open`, async ({ page }) => {
      await openFixture(page, fixture);
      const bindRequests = await stubCartRoutes(page, options);
      await readCart(page);
      await openChat(page);
      await expect.poll(() => bindRequests.length).toBe(1);
      const nonceLookups = bindRequests.nonceLookups;

      // Themes and the widget toggle body classes while the chat is open.
      for (let i = 0; i < 5; i++) {
        await page.evaluate((n) => document.body.classList.toggle(`scrolled-${n}`), i);
      }
      await settle(page);
      expect(bindRequests).toHaveLength(1);
      expect(bindRequests.nonceLookups).toBe(nonceLookups);
    });
  }
});
