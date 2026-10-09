/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * End-to-end tests for the plugin admin settings page.
 */
const { test, expect } = require('@wordpress/e2e-test-utils-playwright');

test.describe('Admin Settings', () => {
	test('renders settings page without errors', async ({ admin, page }) => {
		await admin.visitAdminPage('admin.php?page=gecx-settings');

		await expect(page.locator('h1')).toContainText('Gemini Enterprise for CX');
		await expect(page.locator('input[name="gecx_agent_name"]')).toBeVisible();
		await expect(page.locator('input[name="gecx_agent_enabled"]')).toBeAttached();
		await expect(page.locator('select[name="gecx_button_placement"]')).toBeVisible();
	});

	test('saves settings successfully and displays confirmation', async ({ admin, page }) => {
		await admin.visitAdminPage('admin.php?page=gecx-settings');

		const agentInput = page.locator('input[name="gecx_agent_name"]');
		await agentInput.fill('projects/123/locations/global/agents/e2e');

		const enabledCheckbox = page.locator('input[name="gecx_agent_enabled"]');
		if (!(await enabledCheckbox.isChecked())) {
			await enabledCheckbox.check();
		}

		await page.locator('button[type="submit"], input[type="submit"]').click();
		await page.waitForLoadState('networkidle');

		await expect(page.locator('.notice-success, #setting-error-settings_updated')).toBeVisible();
		await expect(agentInput).toHaveValue('projects/123/locations/global/agents/e2e');
	});
});

