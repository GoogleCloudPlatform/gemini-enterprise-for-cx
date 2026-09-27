/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
const fs = require('fs');
const path = require('path');

const WIDGET_STUB = fs.readFileSync(path.join(__dirname, 'widget-stub.js'), 'utf8');

/**
 * Serves the widget stub in place of the Google-hosted bundle.
 * @param {import('@playwright/test').Page} page
 */
async function stubWidgetBundle(page) {
  await page.route('https://www.gstatic.com/gecx/**', (route) =>
    route.fulfill({ contentType: 'application/javascript', body: WIDGET_STUB }));
}

/**
 * Launchers a shopper can actually see: laid out with a size, not hidden or
 * transparent, and inside the viewport. Returns where each one sits.
 * @param {import('@playwright/test').Page} page
 * @return {!Promise<!Array<{container: string, inFooter: boolean}>>}
 */
async function visibleLaunchers(page) {
  return page.evaluate(() => Array.from(document.querySelectorAll('gecx-agent-button'))
      .filter((el) => {
        const rect = el.getBoundingClientRect();
        const visible = typeof el.checkVisibility === 'function' ?
            el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true }) : true;
        return visible && rect.width > 0 && rect.height > 0 && rect.right > 0 &&
            rect.left < window.innerWidth && rect.bottom > 0 && rect.top < window.innerHeight;
      })
      .map((el) => {
        const container = el.closest('.gecx-nav-menu-item, .gecx-mobile-header-button, .gecx-floating-button-container, .gecx-agent-button-slot');
        return {
          container: container ? container.className : '(none)',
          inFooter: !!el.closest('footer, [role="contentinfo"], .site-footer, #colophon'),
        };
      }));
}

/**
 * Waits for placement to settle: storefront.js reacts to DOM changes after a
 * 250ms debounce.
 * @param {import('@playwright/test').Page} page
 */
async function settle(page) {
  await page.waitForLoadState('load');
  await page.waitForTimeout(600);
}

module.exports = { stubWidgetBundle, visibleLaunchers, settle, WIDGET_STUB };
