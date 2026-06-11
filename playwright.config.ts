import { defineConfig } from '@playwright/test';

// Live wp-env smoke tests. These drive a REAL store (wp-env behind a
// cloudflare tunnel) against a REAL mint, and block waiting for a human to
// pay the invoice — they are NOT part of `npm run check` and never run in
// CI. Usage:
//
//   CASHU_E2E_BASE_URL=https://<tunnel>.trycloudflare.com npx playwright test
//
// Artifacts (invoice, payment request, screenshots, order context) land in
// tests/e2e/artifacts/ so the operator can pay from a wallet while the
// headless page stays open and finishes the settlement leg.
export default defineConfig({
  testDir: 'tests/e2e',
  timeout: 30 * 60_000,
  expect: { timeout: 30_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  outputDir: 'test-results',
  use: {
    baseURL: process.env.CASHU_E2E_BASE_URL ?? 'http://localhost:8888',
    // Without these, Playwright actions wait FOREVER on a non-matching
    // selector (default actionTimeout is 0) and the spec hangs silently
    // until the test timeout instead of failing fast on the broken step.
    actionTimeout: 20_000,
    navigationTimeout: 90_000,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
});
