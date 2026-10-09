/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Playwright browser tests for Gemini Enterprise for CX.
 *
 * - placement: fast storefront.js tests against static pages modeled on theme markup.
 *   Needs no WordPress and runs in seconds.
 * - e2e: end-to-end tests for admin settings, storefront, and REST API in wp-env.
 * - themes: tests against real themes in wp-env.
 */
const path = require('path');
const { defineConfig, devices } = require('@playwright/test');

const STORAGE_STATE_PATH = process.env.STORAGE_STATE_PATH ||
	path.join(process.cwd(), 'artifacts/storage-states/admin.json');

const WP_BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

module.exports = defineConfig({
	timeout: 30000,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['github'], ['list']] : 'list',
	use: {
		...devices['Desktop Chrome'],
		trace: 'retain-on-failure',
	},
	projects: [
		{
			name: 'placement',
			testDir: 'tests/e2e/placement',
		},
		{
			name: 'setup',
			testDir: 'tests/e2e/specs',
			testMatch: /.*\.setup\.js/,
			use: {
				baseURL: WP_BASE_URL,
			},
		},
		{
			name: 'e2e',
			testDir: 'tests/e2e/specs',
			testIgnore: /.*\.setup\.js/,
			dependencies: ['setup'],
			use: {
				baseURL: WP_BASE_URL,
				storageState: STORAGE_STATE_PATH,
			},
		},
		{
			name: 'themes',
			testDir: 'tests/e2e/themes',
			dependencies: ['setup'],
			use: {
				baseURL: WP_BASE_URL,
				storageState: STORAGE_STATE_PATH,
			},
		},
	],
});
