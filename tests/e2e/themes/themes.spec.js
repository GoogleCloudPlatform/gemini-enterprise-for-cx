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
		try {
			await requestUtils.rest({
				path: '/gecx/v1/link-agent',
				method: 'POST',
				data: {
					agent_name: 'projects/123/locations/global/agents/e2e',
				},
			});
		} catch {
			// Already linked.
		}

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

	test('places no price history link with the setting off, and one right after the price with it on', async ({ page, requestUtils }) => {
		await requestUtils.rest({
			path: '/wp/v2/settings',
			method: 'POST',
			data: { gecx_price_history_link_enabled: 0 },
		});
		await page.goto('/product/e2e-mug/');
		await settle(page);
		expect(await page.locator('gecx-price-history-link').count()).toBe(0);

		await requestUtils.rest({
			path: '/wp/v2/settings',
			method: 'POST',
			data: { gecx_price_history_link_enabled: 1 },
		});
		try {
			await page.goto('/product/e2e-mug/');
			await settle(page);
			const placement = await page.evaluate(() => {
				const links = document.querySelectorAll('gecx-price-history-link');
				const price = document.querySelector('.wp-block-woocommerce-product-price') ||
					document.querySelector('div.product .summary .price') ||
					document.querySelector('div.product .price') ||
					document.querySelector('.price');
				const form = document.querySelector('div.product form.cart') ||
					document.querySelector('.wp-block-woocommerce-add-to-cart-form form.cart') ||
					document.querySelector('form.cart');
				const marginVar = links[0] ? getComputedStyle(links[0]).getPropertyValue('--gecx-price-history-link-margin').trim() : '';
				return {
					count: links.length,
					afterPrice: !!(price && links[0] &&
						(price.compareDocumentPosition(links[0]) & Node.DOCUMENT_POSITION_FOLLOWING)),
					beforeForm: !!(form && links[0] &&
						(links[0].compareDocumentPosition(form) & Node.DOCUMENT_POSITION_FOLLOWING)),
					inFloatingBar: !!(links[0] && links[0].closest('[class*="sticky-add-to-cart"], .owp-floating-bar')),
					marginVar,
				};
			});
			expect(placement).toEqual({ count: 1, afterPrice: true, beforeForm: true, inFloatingBar: false, marginVar: '0' });
		} finally {
			await requestUtils.rest({
				path: '/wp/v2/settings',
				method: 'POST',
				data: { gecx_price_history_link_enabled: 0 },
			});
		}
	});

	test('keeps the price history link variant in step with the selected variation', async ({ page, requestUtils }) => {
		await requestUtils.rest({
			path: '/wp/v2/settings',
			method: 'POST',
			data: { gecx_price_history_link_enabled: 1 },
		});
		try {
			await page.goto('/product/e2e-variable-tee/');
			await settle(page);
			const link = page.locator('gecx-price-history-link');
			await expect(link).toHaveCount(1);
			await expect(link).not.toHaveAttribute('variant-id');

			const select = page.locator('div.product form.variations_form select').first();
			const variationId = page.locator('div.product form.variations_form input[name="variation_id"]').first();
			await select.selectOption({ label: 'Blue' });
			await expect(variationId).not.toHaveValue('');
			const selected = await variationId.inputValue();
			await expect(link).toHaveAttribute('variant-id', selected);

			await select.selectOption('');
			await expect(variationId).toHaveValue('');
			await expect(link).not.toHaveAttribute('variant-id');
		} finally {
			await requestUtils.rest({
				path: '/wp/v2/settings',
				method: 'POST',
				data: { gecx_price_history_link_enabled: 0 },
			});
		}
	});
});
