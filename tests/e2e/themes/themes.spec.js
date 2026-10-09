/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * The plugin on real themes, testing launcher and prompt placement.
 */
const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const { stubWidgetBundle, visibleLaunchers, settle } = require('../support/launchers');

const THEME = process.env.GECX_THEME || '';

test.describe(THEME || 'active theme', () => {
	test.beforeAll(async ({ requestUtils }) => {
		if (THEME) {
			await requestUtils.activateTheme(THEME);
			// Assign main menu to theme locations if available
			try {
				const menus = await requestUtils.rest({ path: '/wp/v2/menus' });
				const menu = Array.isArray(menus) ? menus.find((m) => m.name === 'E2E Main') : null;
				if (menu && menu.id) {
					const locations = await requestUtils.rest({ path: '/wp/v2/menu-locations' });
					const targetLocations = Object.keys(locations).filter(
						(loc) => /(primary|main|menu[_-]1|header|mobile|handheld|expanded)/i.test(loc) &&
							!/(footer|social|top|secondary)/i.test(loc)
					);
					if (targetLocations.length > 0) {
						await requestUtils.rest({
							path: `/wp/v2/menus/${menu.id}`,
							method: 'POST',
							data: { locations: targetLocations },
						});
					}
				}
			} catch {
				// Block themes manage navigation via blocks
			}
		}
	});

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
