/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Browser tests for storefront placement.
 *
 * - placement: storefront.js against static pages modeled on theme markup.
 *   Needs no WordPress and runs in seconds.
 * - themes: the plugin on real themes in wp-env. See
 *   tests/e2e/bin/run-theme-matrix.sh.
 */
const { defineConfig, devices } = require('@playwright/test');

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
      name: 'themes',
      testDir: 'tests/e2e/themes',
      use: {
        baseURL: process.env.WP_BASE_URL || 'http://localhost:8898',
      },
    },
  ],
});
