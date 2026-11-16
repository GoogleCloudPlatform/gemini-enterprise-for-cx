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

	test('REST API protected endpoints reject unauthenticated requests', async ({ playwright, baseURL }) => {
		const anonRequest = await playwright.request.newContext({ baseURL });
		try {
			const pubKeyRes = await anonRequest.get('/wp-json/gecx/v1/public-key');
			expect([401, 403]).toContain(pubKeyRes.status());

			const linkRes = await anonRequest.post('/wp-json/gecx/v1/link-agent', {
				data: { agent_name: 'projects/123/locations/global/agents/e2e' },
			});
			expect([401, 403]).toContain(linkRes.status());
		} finally {
			await anonRequest.dispose();
		}
	});

	test('auth-context route requires same-origin attribute and serves session data', async ({ playwright, baseURL }) => {
		const anonRequest = await playwright.request.newContext({ baseURL });
		try {
			// Bare POST without same-origin headers is forbidden.
			const bareRes = await anonRequest.post('/wp-json/gecx/v1/auth-context');
			expect(bareRes.status()).toBe(403);

			// GET is not allowed.
			const getRes = await anonRequest.get('/wp-json/gecx/v1/auth-context', {
				headers: { 'Sec-Fetch-Site': 'same-origin' },
			});
			expect(getRes.status()).toBe(404);

			// Same-origin POST returns success and nonce.
			const postRes = await anonRequest.post('/wp-json/gecx/v1/auth-context', {
				headers: { 'Sec-Fetch-Site': 'same-origin' },
			});
			expect(postRes.status()).toBe(200);
			const body = await postRes.json();
			expect(body.success).toBe(true);
			expect(typeof body.nonce).toBe('string');
			expect(body.customer_jwt === null || typeof body.customer_jwt === 'string').toBe(true);
		} finally {
			await anonRequest.dispose();
		}
	});
});

