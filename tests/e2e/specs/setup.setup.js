/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Environment setup for e2e tests running against WordPress + WooCommerce in wp-env.
 */
const fs = require('fs');
const path = require('path');

process.env.WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';
process.env.STORAGE_STATE_PATH = process.env.STORAGE_STATE_PATH ||
	path.join(process.cwd(), 'artifacts/storage-states/admin.json');

const { test: setup } = require('@wordpress/e2e-test-utils-playwright');

setup('setup test environment', async ({ requestUtils, admin, page }) => {
	// 1. Authenticate browser session via standard login form.
	await page.goto('wp-login.php');
	const loginInput = page.locator('#user_login');
	if (await loginInput.isVisible()) {
		await loginInput.fill('admin');
		await page.locator('#user_pass').fill('password');
		await page.locator('#wp-submit').click();
		await page.waitForURL('**/wp-admin/**');
	}

	// 2. Discover REST API endpoint and obtain REST nonce.
	const { nonce, rootURL } = await requestUtils.setupRest();

	// 3. Save combined browser cookies and REST session state for all test workers.
	const browserState = await page.context().storageState();
	const mergedState = {
		...browserState,
		nonce,
		rootURL,
	};
	await fs.promises.mkdir(path.dirname(process.env.STORAGE_STATE_PATH), { recursive: true });
	await fs.promises.writeFile(
		process.env.STORAGE_STATE_PATH,
		JSON.stringify(mergedState, null, 2)
	);

	// 4. Ensure pretty permalinks are enabled so /wp-json/ and product URLs resolve.
	try {
		await admin.visitAdminPage('options-permalink.php');
		const postnameRadio = page.locator('input[value="/%postname%/"]');
		if (await postnameRadio.isVisible()) {
			await postnameRadio.check();
			await page.locator('#submit').click();
			await page.waitForLoadState('networkidle');
		}
	} catch {
		// Non-fatal if already configured.
	}

	// 5. Disable WooCommerce "Coming Soon" mode so storefront is public.
	try {
		await requestUtils.rest({
			path: '/wp/v2/settings',
			method: 'POST',
			data: { woocommerce_coming_soon: 'no' },
		});
	} catch {
		// Setting may not be in core settings schema on all versions.
	}

	// 6. Link agent to activate plugin features and storefront embed.
	try {
		await requestUtils.rest({
			path: '/gecx/v1/link-agent',
			method: 'POST',
			data: {
				agent_name: 'projects/123/locations/global/agents/e2e',
			},
		});
	} catch {
		// May already be linked.
	}

	try {
		await requestUtils.rest({
			path: '/wp/v2/settings',
			method: 'POST',
			data: {
				gecx_agent_enabled: 1,
				gecx_pdp_prompts_enabled: 1,
				gecx_button_placement: 'nav_menu',
			},
		});
	} catch {
		// Non-fatal if options already configured.
	}

	// 7. Ensure E2E Mug product exists for single product page tests.
	try {
		await requestUtils.rest({
			path: '/wc/v3/products',
			method: 'POST',
			data: {
				name: 'E2E Mug',
				slug: 'e2e-mug',
				type: 'simple',
				regular_price: '12',
				status: 'publish',
			},
		});
	} catch {
		try {
			await requestUtils.createPost({
				title: 'E2E Mug',
				slug: 'e2e-mug',
				type: 'product',
				status: 'publish',
			});
		} catch {
			// Ignored if already created.
		}
	}

	// 8. Ensure E2E Main menu exists and assign it to available header locations.
	try {
		const menus = await requestUtils.rest({ path: '/wp/v2/menus' }).catch(() => []);
		let menu = Array.isArray(menus) ? menus.find((m) => m.name === 'E2E Main') : null;
		if (!menu) {
			menu = await requestUtils.createClassicMenu('E2E Main');
		}
		if (menu && menu.id) {
			const locations = await requestUtils.rest({ path: '/wp/v2/menu-locations' }).catch(() => ({}));
			const targetLocations = Object.keys(locations).filter(
				(loc) => /(primary|main|menu[_-]1|header|mobile|handheld|expanded)/i.test(loc) &&
					!/(footer|social|top|secondary)/i.test(loc)
			);
			if (targetLocations.length > 0) {
				await requestUtils.rest({
					path: `/wp/v2/menus/${menu.id}`,
					method: 'POST',
					data: { locations: targetLocations },
				}).catch(() => {});
			}
		}
	} catch {
		// Block themes use navigation blocks rather than classic menus.
	}
});
