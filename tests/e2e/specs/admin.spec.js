/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * End-to-end tests for the plugin admin settings page.
 */
const { test, expect } = require('@wordpress/e2e-test-utils-playwright');

test.describe('Admin Settings', () => {
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
	});

	test('renders settings page without errors', async ({ admin, page }) => {
		await admin.visitAdminPage('admin.php?page=gemini-enterprise-for-cx');

		await expect(page.locator('h1')).toContainText('Gemini Enterprise for CX');
		await expect(page.locator('#gecx-status-indicator')).toBeVisible();
		await expect(page.locator('#gecx_agent_enabled')).toBeAttached();
		await expect(page.locator('input[name="gecx_button_placement"]')).toHaveCount(3);
	});

	test('toggles storefront chat widget setting', async ({ admin, page }) => {
		await admin.visitAdminPage('admin.php?page=gemini-enterprise-for-cx');

		const enabledCheckbox = page.locator('#gecx_agent_enabled');
		await expect(enabledCheckbox).toBeVisible();

		const wasChecked = await enabledCheckbox.isChecked();
		if (wasChecked) {
			await enabledCheckbox.uncheck();
			await expect(page.locator('#gecx-status-text')).toContainText('Inactive');
			await enabledCheckbox.check();
			await expect(page.locator('#gecx-status-text')).toContainText('Active');
		} else {
			await enabledCheckbox.check();
			await expect(page.locator('#gecx-status-text')).toContainText('Active');
		}
	});

	test('switches button placement and reveals placement-specific rows', async ({ admin, page }) => {
		await admin.visitAdminPage('admin.php?page=gemini-enterprise-for-cx');

		const floatingRadio = page.locator('input[name="gecx_button_placement"][value="floating"]');
		const navMenuRadio = page.locator('input[name="gecx_button_placement"][value="nav_menu"]');

		await floatingRadio.check();
		await expect(page.locator('#gecx_floating_position_row')).toBeVisible();
		await expect(page.locator('#gecx_nav_menu_target_row')).toBeHidden();

		await navMenuRadio.check();
		await expect(page.locator('#gecx_nav_menu_target_row')).toBeVisible();
		await expect(page.locator('#gecx_floating_position_row')).toBeHidden();
	});
});
