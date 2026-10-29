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

test('places the launcher in an Elementor header with Max Mega Menu on desktop and mobile', async ({ page }) => {
  const megaMenuHeader = {
    themeCss: `.mega-menu{display:flex;list-style:none;gap:12px;margin:0;padding:0}
      .mega-menu-toggle{display:none}
      @media (max-width:768px){.mega-menu{display:none}.mega-menu-toggle{display:flex;align-items:center}}`,
    body: `<div data-elementor-type="header" class="elementor-location-header">
      <div id="mega-menu-wrap-primary-menu" class="mega-menu-wrap">
        <div class="mega-menu-toggle"><div class="mega-toggle-blocks-right">
          <button class="mega-toggle-animated" aria-label="Toggle Menu">Menu</button>
        </div></div>
        <ul id="mega-menu-primary-menu" class="mega-menu max-mega-menu">
          <li class="mega-menu-item"><a href="#">Shop</a><ul class="mega-sub-menu"><li><a href="#">Parts</a></li></ul></li>
          <li class="mega-menu-item"><a href="#">About</a></li>
        </ul>
      </div></div><main>Content</main>`,
  };

  await openFixture(page, { width: 1280, ...megaMenuHeader });
  let launchers = await visibleLaunchers(page);
  expect(launchers).toHaveLength(1);
  expect(launchers[0].container).toContain('gecx-nav-menu-item');
  expect(await page.locator('#mega-menu-primary-menu > .gecx-nav-menu-item').count()).toBe(1);

  await page.setViewportSize({ width: 375, height: 800 });
  await settle(page);
  launchers = await visibleLaunchers(page);
  expect(launchers).toHaveLength(1);
  expect(launchers[0].container).toContain('gecx-mobile-header-button');
  expect(launchers[0].container).not.toContain('gecx-launcher-fallback');
});

test('places the mobile header button in its own centered row when the hamburger sits in a narrow column', async ({ page }) => {
  await openFixture(page, {
    width: 375,
    height: 800,
    themeCss: `.elementor-container{display:flex;align-items:center;width:355px}
      .col-burger{width:57px;display:flex}
      .col-logo{width:192px;display:flex;justify-content:center}
      .col-cart{width:106px;display:flex;justify-content:flex-end}
      .mega-menu{display:none}
      .mega-menu-toggle{display:flex}
      .mega-toggle-animated{width:32px;height:32px}`,
    body: `<div data-elementor-type="header" class="elementor-location-header">
      <section class="elementor-top-section"><div class="elementor-container">
        <div class="elementor-column col-burger"><div class="mega-menu-wrap">
          <div class="mega-menu-toggle"><button class="mega-toggle-animated">M</button></div>
          <ul class="mega-menu max-mega-menu"><li class="mega-menu-item"><a href="#">Shop</a></li></ul>
        </div></div>
        <div class="elementor-column col-logo"><a id="logo" href="#">REV6</a></div>
        <div class="elementor-column col-cart"><a href="#">Cart</a></div>
      </div></section></div>`,
  });
  const launchers = await visibleLaunchers(page);
  expect(launchers).toHaveLength(1);
  expect(launchers[0].container).toContain('gecx-mobile-header-button--row');
  const burgerBox = await page.locator('.mega-toggle-animated').boundingBox();
  const logoBox = await page.locator('#logo').boundingBox();
  expect(burgerBox.x + burgerBox.width).toBeLessThanOrEqual(logoBox.x);
});

test('lifts a bottom-right fallback or floating launcher above a fixed corner widget', async ({ page }) => {
  await openFixture(page, {
    width: 375,
    height: 700,
    config: { placement: 'nav_menu' },
    themeCss: '#podium-bubble{position:fixed;bottom:0;right:0;width:160px;height:100px;border:0}',
    body: `<main style="height:1200px">Content</main>
      <div id="podium-website-widget"><iframe id="podium-bubble" title="Text us"></iframe></div>`,
  });
  const launchers = await visibleLaunchers(page);
  expect(launchers).toHaveLength(1);
  expect(launchers[0].container).toContain('gecx-launcher-fallback');
  const rect = await page.locator('.gecx-launcher-fallback gecx-agent-button').boundingBox();
  expect(rect.y + rect.height).toBeLessThanOrEqual(700 - 100);
});

test.describe('corner widget that sizes itself after load', () => {
  const MERCHANT_WIDGET_ID = 'google-merchantwidget-iframe-wrapper';
  const floatingBottomRight = `<main style="height:1200px">Content</main>
    <div class="gecx-floating-button-container gecx-floating-button-container--bottom-right" style="${assets.floatingStyles.bottom_right}">${BUTTON}</div>`;

  /**
   * Mounts a wrapper the way the Merchant Center store widget does: fixed,
   * appended to <body> at 0x0, and sized later by its iframe. `depth` nests it
   * in that many plain <div>s, as a theme or optimizer re-parenting it would.
   */
  async function mountEmptyWidget(page, position, { width = 0, height = 0, extraStyle = '', depth = 0 } = {}) {
    await page.evaluate(({ id, position, width, height, extraStyle, depth }) => {
      const wrapper = document.createElement('div');
      wrapper.id = id;
      wrapper.style.cssText = `position:fixed;${position};width:${width}px;height:${height}px;overflow:hidden;z-index:2147483647;${extraStyle}`;
      wrapper.innerHTML = '<iframe title="Store widget" style="width:100%;height:100%;border:0"></iframe>';
      let outer = wrapper;
      for (let i = 0; i < depth; i++) {
        const parent = document.createElement('div');
        parent.appendChild(outer);
        outer = parent;
      }
      document.body.appendChild(outer);
    }, { id: MERCHANT_WIDGET_ID, position, width, height, extraStyle, depth });
    await settle(page);
  }

  async function resizeWidget(page, width, height) {
    await page.evaluate(({ id, width, height }) => {
      const wrapper = document.getElementById(id);
      wrapper.style.width = `${width}px`;
      wrapper.style.height = `${height}px`;
    }, { id: MERCHANT_WIDGET_ID, width, height });
    await settle(page);
  }

  const floatingOffset = (page) => page.evaluate(() =>
    document.documentElement.style.getPropertyValue('--gecx-floating-offset'));

  async function expectLauncherAboveWidget(page, launcherSelector = '.gecx-floating-button-container gecx-agent-button') {
    const badge = await page.locator(`#${MERCHANT_WIDGET_ID}`).boundingBox();
    const launcher = await page.locator(launcherSelector).boundingBox();
    expect(launcher.y + launcher.height).toBeLessThanOrEqual(badge.y);
  }

  test('lifts the floating launcher once the widget grows', async ({ page }) => {
    await openFixture(page, { config: { placement: 'floating' }, body: floatingBottomRight });
    await mountEmptyWidget(page, 'right:20px;bottom:20px');
    expect(await floatingOffset(page)).toBe('');

    await resizeWidget(page, 84, 84);
    await expectLauncherAboveWidget(page);
  });

  test('lifts the floating launcher on mobile, where the widget sits flush right', async ({ page }) => {
    await openFixture(page, { width: 375, height: 700, config: { placement: 'floating' }, body: floatingBottomRight });
    await mountEmptyWidget(page, 'right:0;bottom:30px');
    expect(await floatingOffset(page)).toBe('');

    await resizeWidget(page, 84, 84);
    await expectLauncherAboveWidget(page);
  });

  test('lifts the fallback launcher once the widget grows', async ({ page }) => {
    await openFixture(page, {
      width: 375,
      height: 700,
      config: { placement: 'nav_menu' },
      body: '<main style="height:1200px">Content</main>',
    });
    const launchers = await visibleLaunchers(page);
    expect(launchers).toHaveLength(1);
    expect(launchers[0].container).toContain('gecx-launcher-fallback');
    await mountEmptyWidget(page, 'right:20px;bottom:20px');
    expect(await floatingOffset(page)).toBe('');

    await resizeWidget(page, 84, 84);
    await expectLauncherAboveWidget(page, '.gecx-launcher-fallback gecx-agent-button');
  });

  test('lifts the floating launcher when the widget is nested below <body>', async ({ page }) => {
    await openFixture(page, { config: { placement: 'floating' }, body: floatingBottomRight });
    await mountEmptyWidget(page, 'right:20px;bottom:20px', { depth: 2 });
    expect(await floatingOffset(page)).toBe('');

    await resizeWidget(page, 84, 84);
    await expectLauncherAboveWidget(page);
  });

  test('lifts the floating launcher without ResizeObserver', async ({ page }) => {
    await page.addInitScript(() => {
      delete window.ResizeObserver;
    });
    await openFixture(page, { config: { placement: 'floating' }, body: floatingBottomRight });
    expect(await page.evaluate(() => typeof window.ResizeObserver)).toBe('undefined');
    await mountEmptyWidget(page, 'right:20px;bottom:20px');
    expect(await floatingOffset(page)).toBe('');

    await resizeWidget(page, 84, 84);
    await expectLauncherAboveWidget(page);
  });

  for (const [hidden, shown] of [['visibility:hidden', 'visible'], ['display:none', 'block']]) {
    const property = hidden.split(':')[0];
    test(`lifts the floating launcher when a sized widget is shown after ${hidden}`, async ({ page }) => {
      await openFixture(page, { config: { placement: 'floating' }, body: floatingBottomRight });
      await mountEmptyWidget(page, 'right:20px;bottom:20px', { width: 84, height: 84, extraStyle: hidden });
      expect(await floatingOffset(page)).toBe('');

      await page.evaluate(({ id, property, shown }) => {
        document.getElementById(id).style.setProperty(property, shown);
      }, { id: MERCHANT_WIDGET_ID, property, shown });
      await settle(page);
      expect(await floatingOffset(page)).toBe('104px');
      await expectLauncherAboveWidget(page);
    });
  }

  test('drops the lift when the widget shrinks away again', async ({ page }) => {
    await openFixture(page, { config: { placement: 'floating' }, body: floatingBottomRight });
    await mountEmptyWidget(page, 'right:20px;bottom:20px');
    await resizeWidget(page, 84, 84);
    // 800px viewport - 20px bottom - 84px badge.
    expect(await floatingOffset(page)).toBe('104px');

    await resizeWidget(page, 0, 0);
    expect(await floatingOffset(page)).toBe('');
  });

  test('drops the lift when the widget is removed', async ({ page }) => {
    await openFixture(page, { config: { placement: 'floating' }, body: floatingBottomRight });
    await mountEmptyWidget(page, 'right:20px;bottom:20px');
    await resizeWidget(page, 84, 84);
    expect(await floatingOffset(page)).toBe('104px');

    await page.evaluate((id) => document.getElementById(id).remove(), MERCHANT_WIDGET_ID);
    await settle(page);
    expect(await floatingOffset(page)).toBe('');
  });

  test('does not chase the widget when it expands into a panel', async ({ page }) => {
    await openFixture(page, { config: { placement: 'floating' }, body: floatingBottomRight });
    await mountEmptyWidget(page, 'right:20px;bottom:20px');
    await resizeWidget(page, 84, 84);
    expect(await floatingOffset(page)).toBe('104px');

    // The corner size limits still apply after a resize: an open panel is
    // the widget's own overlay, not something to clear.
    await resizeWidget(page, 400, 600);
    expect(await floatingOffset(page)).toBe('');
  });

  test('lifts the floating launcher for a nested widget without ResizeObserver', async ({ page }) => {
    await page.addInitScript(() => { delete window.ResizeObserver; });
    await openFixture(page, { config: { placement: 'floating' }, body: floatingBottomRight });
    await mountEmptyWidget(page, 'right:20px;bottom:20px', { depth: 2 });
    expect(await floatingOffset(page)).toBe('');

    await resizeWidget(page, 84, 84);
    await expectLauncherAboveWidget(page);
  });

  test('drops the lift when the widget is removed without ResizeObserver', async ({ page }) => {
    await page.addInitScript(() => { delete window.ResizeObserver; });
    await openFixture(page, { config: { placement: 'floating' }, body: floatingBottomRight });
    await mountEmptyWidget(page, 'right:20px;bottom:20px');
    await resizeWidget(page, 84, 84);
    expect(await floatingOffset(page)).toBe('104px');

    await page.evaluate((id) => document.getElementById(id).remove(), MERCHANT_WIDGET_ID);
    await settle(page);
    expect(await floatingOffset(page)).toBe('');
  });

  for (const [what, remove] of [
    ['the nested widget itself', (id) => document.getElementById(id).remove()],
    ['an ancestor between it and <body>', (id) => document.getElementById(id).parentElement.remove()],
  ]) {
    test(`drops the lift when ${what} is removed without ResizeObserver`, async ({ page }) => {
      await page.addInitScript(() => { delete window.ResizeObserver; });
      await openFixture(page, { config: { placement: 'floating' }, body: floatingBottomRight });
      await mountEmptyWidget(page, 'right:20px;bottom:20px', { depth: 2 });
      await resizeWidget(page, 84, 84);
      expect(await floatingOffset(page)).toBe('104px');

      await page.evaluate(remove, MERCHANT_WIDGET_ID);
      await settle(page);
      expect(await floatingOffset(page)).toBe('');
    });
  }

  test('lifts the floating launcher while another element keeps changing', async ({ page }) => {
    await openFixture(page, {
      config: { placement: 'floating' },
      body: `${floatingBottomRight}<div id="ticker" style="position:fixed;top:0;left:0">News</div>`,
    });
    // A ticker restyling a child of <body> every 30ms, faster than the
    // re-measure's quiet time, for longer than the test waits.
    await page.evaluate(() => {
      const ticker = document.getElementById('ticker');
      let n = 0;
      window.__ticker = setInterval(() => { ticker.style.transform = `translateX(${n++ % 50}px)`; }, 30);
    });
    await mountEmptyWidget(page, 'right:20px;bottom:20px');
    await resizeWidget(page, 84, 84);
    expect(await page.evaluate(() => !!window.__ticker)).toBe(true);
    expect(await floatingOffset(page)).toBe('104px');
    await page.evaluate(() => clearInterval(window.__ticker));
  });
});

test('lifts the floating launcher above a nested sticky bar that becomes fixed after load', async ({ page }) => {
  await openFixture(page, {
    config: { placement: 'floating' },
    themeCss: '.elementor-sticky--active{position:fixed;bottom:0;left:0;right:0;height:56px;background:#333}',
    body: `<main style="height:1200px"><div><div id="late-bar">Add to cart</div></div></main>
      <div class="gecx-floating-button-container gecx-floating-button-container--bottom-right" style="${assets.floatingStyles.bottom_right}">${BUTTON}</div>`,
  });
  expect(await page.evaluate(() => document.documentElement.style.getPropertyValue('--gecx-floating-offset'))).toBe('');

  // Elementor adds this class on scroll, long after the first placement pass.
  await page.evaluate(() => document.getElementById('late-bar').classList.add('elementor-sticky--active'));
  await settle(page);
  expect(await page.evaluate(() => document.documentElement.style.getPropertyValue('--gecx-floating-offset'))).toBe('56px');
});

test('keeps the bottom bar lift while the chat panel narrows the bar', async ({ page }) => {
  await openFixture(page, {
    width: 650,
    height: 700,
    config: { placement: 'floating' },
    themeCss: '.storefront-handheld-footer-bar{position:fixed;bottom:0;left:0;right:0;height:56px;background:#333}',
    body: `<main style="height:1200px">Content</main><div class="storefront-handheld-footer-bar"></div>
      <div class="gecx-floating-button-container gecx-floating-button-container--bottom-center" style="${assets.floatingStyles.bottom_center}">${BUTTON}</div>`,
  });
  const offset = () => page.evaluate(() => document.documentElement.style.getPropertyValue('--gecx-floating-offset'));
  expect(await offset()).toBe('56px');

  // At 650px a 360px panel leaves the bar under half the viewport wide.
  await page.evaluate(() => {
    const messenger = document.createElement('chat-messenger');
    messenger.className = 'slide-over';
    document.body.appendChild(messenger);
    document.body.style.paddingRight = '360px';
    document.body.classList.add('gecx-chat-open');
    document.dispatchEvent(new CustomEvent('chat-messenger-visibility-changed'));
  });
  await expect.poll(() => page.locator('.storefront-handheld-footer-bar').evaluate((el) => el.classList.contains('gecx-shifted-for-chat'))).toBe(true);
  await settle(page);
  expect(await offset()).toBe('56px');
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
  const cartToken = (session, n = 1, exp = 9999999999) =>
    `${b64url({ typ: 'JWT', alg: 'HS256' })}.${b64url({ user_id: session, exp, iss: 'store-api', n })}.signature`;
  const agentToken = cartToken('t_agent');
  const boundToken = cartToken('t_agent', 2);

  /**
   * Answers the Store API cart read the widget makes with the given token,
   * and the auth-context, cart-session and session routes. Returns the
   * cart-session requests made, with the order of cart reads, cart-session
   * and session requests in `order`.
   */
  async function stubCartRoutes(page, { binding = 'agent', cartReadToken = agentToken, status = 200, itemsCount = 0 } = {}) {
    const bindRequests = [];
    bindRequests.nonceLookups = 0;
    bindRequests.order = [];
    bindRequests.cartReadUrls = [];
    bindRequests.cartReadTokens = [];
    bindRequests.sessionRebinds = [];
    // With or without a query string: the script adds a cache-buster.
    await page.route(/^https:\/\/store\.test\/wp-json\/wc\/store\/v1\/cart(\?.*)?$/, (route) => {
      bindRequests.order.push('cart');
      bindRequests.cartReadUrls.push(route.request().url());
      bindRequests.cartReadTokens.push(route.request().headers()['cart-token'] || '');
      return route.fulfill({
        contentType: 'application/json',
        headers: { 'Cart-Token': cartReadToken },
        body: JSON.stringify({ items: [], items_count: itemsCount }),
      });
    });
    await page.route('https://store.test/wp-json/gecx/v1/auth-context', (route) => {
      bindRequests.nonceLookups++;
      return route.fulfill({ contentType: 'application/json', body: '{"nonce":"rest-nonce"}' });
    });
    await page.route('https://store.test/wp-json/gecx/v1/cart-session', (route) => {
      bindRequests.order.push('cart-session');
      bindRequests.push({ body: route.request().postDataJSON(), nonce: route.request().headers()['x-wp-nonce'] });
      return route.fulfill({
        status,
        contentType: 'application/json',
        headers: binding === 'agent' ? { 'Cart-Token': boundToken } : {},
        body: JSON.stringify({ binding }),
      });
    });
    await page.route('https://store.test/wp-json/gecx/v1/session', (route) => {
      bindRequests.order.push('session');
      bindRequests.sessionRebinds.push({
        body: route.request().postDataJSON(),
        cartToken: route.request().headers()['cart-token'] || '',
      });
      return route.fulfill({ contentType: 'application/json', body: '{}' });
    });
    await page.evaluate(() => {
      window.__bound = [];
      window.__events = [];
      window.__cartEvents = [];
      document.body.addEventListener('gecx:cart-session-bound', (e) => {
        window.__bound.push(e.detail);
        window.__events.push(e.type);
      });
      document.body.addEventListener('gecx:cart-updated', (e) => window.__events.push(e.type));
      for (const name of ['wc-blocks_added_to_cart', 'wc-blocks_removed_from_cart']) {
        document.body.addEventListener(name, (e) => window.__cartEvents.push(e.type));
      }
    });
    return bindRequests;
  }

  const readCart = (page) => page.evaluate(() => fetch('https://store.test/wp-json/wc/store/v1/cart'));
  const openChat = (page) => page.evaluate(() => document.body.classList.add('gecx-chat-open'));
  const closeChat = (page) => page.evaluate(() => document.body.classList.remove('gecx-chat-open'));
  const agentChangesCart = (page, detail) => page.evaluate((d) => document.dispatchEvent(
      new CustomEvent('chat-messenger-update-cart', { detail: d })), detail);
  const bodyEvents = (page) => page.evaluate(() => window.__events);
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
    // The token the route sent for the agent's cart stays out of the DOM.
    expect(await page.evaluate(() => window.__bound[0])).toEqual({ binding: 'agent' });

    await closeChat(page);
    await openChat(page);
    await settle(page);
    expect(bindRequests).toHaveLength(1);
  });

  test('brings the page\'s cart up to date after binding on chat open, without opening a drawer', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page, { itemsCount: 1 });
    await readCart(page);
    await openChat(page);

    await expect.poll(() => bodyEvents(page)).toEqual(['gecx:cart-session-bound', 'gecx:cart-updated']);
    expect(bindRequests.order).toEqual(['cart', 'cart-session', 'cart']);
    expect(bindRequests.cartReadTokens).toEqual(['', agentToken]);
    // Refreshes the block mini-cart without the event that opens its drawer.
    expect(await page.evaluate(() => window.__cartEvents)).toEqual(['wc-blocks_removed_from_cart']);
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

  test('runs a waiting bind when the widget said the chat opened before the body class changed', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page);
    await page.evaluate(() => window.dispatchEvent(
        new CustomEvent('chat-messenger-visibility-changed', { detail: { hidden: false } })));
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

  test('skips a resumed cart whose token has expired', async ({ page }) => {
    await page.addInitScript((token) => {
      window.localStorage.setItem('gecx_session_id', 'chat-session-1');
      window.localStorage.setItem('gecx_session_cart', JSON.stringify({ sessionId: 'chat-session-1', cartId: token }));
    }, cartToken('t_expired_agent_cart', 1, 1000));
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page);
    await readCart(page);

    await openChat(page);
    await expect.poll(() => bindRequests.length).toBe(1);
    expect(bindRequests[0].body).toEqual({ cart_token: agentToken });
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
    expect(await page.evaluate(() => window.__bound[0])).toEqual({ binding: 'deferred' });

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

  test('binds the browser after an agent cart change, before reading the cart, once per cart session', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page);

    await agentChangesCart(page, { cartId: agentToken, totalQuantity: 1 });
    await expect.poll(() => bodyEvents(page)).toEqual(['gecx:cart-session-bound', 'gecx:cart-updated']);
    // The same cart session, under the token the route sent for it.
    await agentChangesCart(page, { cartId: boundToken, totalQuantity: 2 });
    await expect.poll(() => bodyEvents(page)).toHaveLength(3);
    // Another cart session, such as the shopper's own cart once the widget adopted it.
    const shopperToken = cartToken('t_shopper');
    await agentChangesCart(page, { cartId: shopperToken, totalQuantity: 3 });
    await expect.poll(() => bodyEvents(page)).toHaveLength(5);

    expect(bindRequests.map((request) => request.body)).toEqual([{ cart_token: agentToken }, { cart_token: shopperToken }]);
    expect(bindRequests.order).toEqual(['cart-session', 'cart', 'cart', 'cart-session', 'cart']);
    expect(await bodyEvents(page)).toEqual([
      'gecx:cart-session-bound', 'gecx:cart-updated', 'gecx:cart-updated', 'gecx:cart-session-bound', 'gecx:cart-updated',
    ]);
  });

  test('re-binds the chat session once, after the cart binding answered', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page);
    await page.evaluate(() => window.localStorage.setItem('gecx_session_id', 'chat-session-1'));

    await agentChangesCart(page, { cartId: agentToken, totalQuantity: 1 });
    await expect.poll(() => bodyEvents(page)).toHaveLength(2);
    await settle(page);

    // Sent with the token the route returned in its Cart-Token header.
    expect(bindRequests.sessionRebinds).toEqual([{ body: { session_id: 'chat-session-1' }, cartToken: boundToken }]);
    expect(bindRequests.order.indexOf('session')).toBeGreaterThan(bindRequests.order.indexOf('cart-session'));
  });

  test('stops binding for the page view once the route answers unsupported', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page, { binding: 'unsupported' });

    await agentChangesCart(page, { cartId: agentToken, totalQuantity: 1 });
    await expect.poll(() => bodyEvents(page)).toEqual(['gecx:cart-session-bound', 'gecx:cart-updated']);
    await agentChangesCart(page, { cartId: cartToken('t_shopper'), totalQuantity: 2 });
    await expect.poll(() => bodyEvents(page)).toHaveLength(3);
    await openChat(page);
    await settle(page);

    expect(bindRequests).toHaveLength(1);
    expect(bindRequests.order).toEqual(['cart-session', 'cart', 'cart']);
  });

  test('reads the cart past page caches after an agent cart change', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page);

    await agentChangesCart(page, { cartId: agentToken, totalQuantity: 1 });
    await agentChangesCart(page, { cartId: boundToken, totalQuantity: 2 });

    await expect.poll(() => bindRequests.cartReadUrls.length).toBe(2);
    const [firstUrl, secondUrl] = bindRequests.cartReadUrls;
    expect(firstUrl).toMatch(/\/wp-json\/wc\/store\/v1\/cart\?_nocache=[0-9a-z]+$/);
    expect(secondUrl).toMatch(/\/wp-json\/wc\/store\/v1\/cart\?_nocache=[0-9a-z]+$/);
    expect(secondUrl).not.toBe(firstUrl);
  });

  test('still reads the cart when binding after an agent change fails', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page, { status: 403 });

    await agentChangesCart(page, { cartId: agentToken, totalQuantity: 1 });

    await expect.poll(() => bodyEvents(page)).toEqual(['gecx:cart-updated']);
    expect(bindRequests.order).toEqual(['cart-session', 'cart']);
  });

  test('reads the cart without binding when an agent change names no cart', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page);

    await agentChangesCart(page, { totalQuantity: 1 });

    await expect.poll(() => bodyEvents(page)).toEqual(['gecx:cart-updated']);
    expect(bindRequests.order).toEqual(['cart']);
  });

  test('does not bind again when the chat opens after an agent change bound the cart', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page);
    await agentChangesCart(page, { cartId: agentToken, totalQuantity: 1 });
    await expect.poll(() => bodyEvents(page)).toHaveLength(2);

    await openChat(page);
    await settle(page);

    expect(bindRequests).toHaveLength(1);
  });

  test('takes over a chat-open bind that is waiting for the agent cart token', async ({ page }) => {
    await openFixture(page, fixture);
    const bindRequests = await stubCartRoutes(page);
    await openChat(page);
    await settle(page);
    expect(bindRequests).toHaveLength(0);

    await agentChangesCart(page, { cartId: agentToken, totalQuantity: 1 });
    await expect.poll(() => bodyEvents(page)).toHaveLength(2);
    await settle(page);

    expect(bindRequests).toHaveLength(1);
  });
});
