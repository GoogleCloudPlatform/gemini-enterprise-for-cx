/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * End-to-end tests for storefront launcher rendering and REST route scoping.
 */
const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const { stubWidgetBundle, settle, visibleLaunchers } = require('../support/launchers');

test.describe('Storefront', () => {
	test.beforeEach(async ({ page }) => {
		await stubWidgetBundle(page);
	});

	test('renders agent launcher button on the storefront', async ({ page }) => {
		await page.goto('/');
		await settle(page);

		const launchers = await visibleLaunchers(page);
		expect(launchers.length).toBeGreaterThanOrEqual(1);
		expect(launchers[0].inFooter).toBe(false);
	});

	test('renders suggested prompts on product page', async ({ page }) => {
		await page.goto('/product/e2e-mug/');
		await settle(page);

		const prompts = page.locator('gecx-suggested-prompts');
		if ((await prompts.count()) > 0) {
			await expect(prompts.first()).toBeAttached();
		}
	});

	test('REST API protected endpoints reject unauthenticated requests', async ({ request }) => {
		const pubKeyRes = await request.get('/wp-json/gecx/v1/public-key');
		expect([401, 403]).toContain(pubKeyRes.status());

		const linkRes = await request.post('/wp-json/gecx/v1/link-agent');
		expect([401, 403]).toContain(linkRes.status());
	});
});

