/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Environment setup for e2e tests running against WordPress + WooCommerce in wp-env.
 */
process.env.WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

const { test: setup } = require('@wordpress/e2e-test-utils-playwright');

setup('setup test environment', async ({ requestUtils, admin, page }) => {
	// 1. Authenticate admin user and save storage-state to disk for all test workers.
	await requestUtils.setupRest();

	// 2. Ensure pretty permalinks are enabled so /wp-json/ and product URLs resolve.
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

	// 3. Disable WooCommerce "Coming Soon" mode so storefront is public.
	try {
		await requestUtils.rest({
			path: '/wp/v2/settings',
			method: 'POST',
			data: { woocommerce_coming_soon: 'no' },
		});
	} catch {
		// Setting may not be in core settings schema on all versions.
	}

	// 4. Configure plugin settings via the Admin UI.
	await admin.visitAdminPage('admin.php?page=gecx-settings');
	const agentInput = page.locator('input[name="gecx_agent_name"]');
	if (await agentInput.isVisible()) {
		await agentInput.fill('projects/123/locations/global/agents/e2e');
		const enabledCheckbox = page.locator('input[name="gecx_agent_enabled"]');
		if (!(await enabledCheckbox.isChecked())) {
			await enabledCheckbox.check();
		}
		const pdpCheckbox = page.locator('input[name="gecx_pdp_prompts_enabled"]');
		if (await pdpCheckbox.isVisible() && !(await pdpCheckbox.isChecked())) {
			await pdpCheckbox.check();
		}
		const placementSelect = page.locator('select[name="gecx_button_placement"]');
		if (await placementSelect.isVisible()) {
			await placementSelect.selectOption('nav_menu');
		}
		await page.locator('button[type="submit"], input[type="submit"]').click();
		await page.waitForLoadState('networkidle');
	}

	// 5. Ensure E2E Mug product exists for single product page tests.
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

	// 6. Ensure E2E Main menu exists and assign it to available header locations.
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
