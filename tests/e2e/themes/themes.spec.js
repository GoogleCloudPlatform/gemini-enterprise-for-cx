/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The plugin on a real theme, run once per theme by
 * tests/e2e/bin/run-theme-matrix.sh, which activates the theme and sets
 * GECX_THEME.
 */
const { test, expect } = require('@playwright/test');
const { stubWidgetBundle, visibleLaunchers, settle } = require('../support/launchers');

const THEME = process.env.GECX_THEME || 'active theme';

test.describe(THEME, () => {
  test.beforeEach(async ({ page }) => {
    await stubWidgetBundle(page);
  });

  for (const width of [375, 800, 1280]) {
    test(`shows exactly one launcher, outside the footer, at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      await settle(page);
      const launchers = await visibleLaunchers(page);
      expect(launchers, JSON.stringify(launchers)).toHaveLength(1);
      expect(launchers[0].inFooter).toBe(false);
    });
  }

  test('places product prompts after the add-to-cart form', async ({ page }) => {
    await page.goto('/product/e2e-mug/');
    await settle(page);
    const placement = await page.evaluate(() => {
      const prompts = document.querySelectorAll('gecx-suggested-prompts');
      const form = document.querySelector('div.product form.cart, form.cart');
      return {
        count: prompts.length,
        afterForm: !!(form && prompts[0] &&
            (form.compareDocumentPosition(prompts[0]) & Node.DOCUMENT_POSITION_FOLLOWING)),
        inStickyBar: !!(prompts[0] && prompts[0].closest('[class*="sticky-add-to-cart"]')),
      };
    });
    expect(placement).toEqual({ count: 1, afterForm: true, inStickyBar: false });
  });
});
