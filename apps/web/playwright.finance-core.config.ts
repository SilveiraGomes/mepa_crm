import { defineConfig } from '@playwright/test'

// P0.10-F1C Finance Core suite (accounts, accrual, bank reconciliation, budget, closes) + the F1B transfer suite as a
// regression, on the four QA viewports. Evidence under docs/reviews/evidence/P0.10-F1C (the F1B suite writes into
// P010_UI_EVIDENCE_DIR, set by the runner to the f1b-regression sub-folder).
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: ['finance-core.spec.ts', 'finance.spec.ts'],
  timeout: 120_000,
  // The E2E API is PHP's single-threaded built-in server (no workers on Windows): after login several contexts are served
  // in series, so an assertion may need more than 10 s to see a page that is correct (F1C-T06). Assertions are unchanged.
  expect: { timeout: 30_000 },
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['json', { outputFile: '../../docs/reviews/evidence/P0.10-F1C/playwright-results.json' }]],
  use: { baseURL: 'http://127.0.0.1:14173', browserName: 'chromium', headless: true, trace: 'retain-on-failure', screenshot: 'only-on-failure', locale: 'pt-AO', timezoneId: 'Africa/Luanda' },
  projects: [
    { name: 'desktop-1440x900', use: { viewport: { width: 1440, height: 900 } } },
    { name: 'laptop-1366x768', use: { viewport: { width: 1366, height: 768 } } },
    { name: 'tablet-768x1024', use: { viewport: { width: 768, height: 1024 } } },
    { name: 'mobile-390x844', use: { viewport: { width: 390, height: 844 } } },
  ],
})
