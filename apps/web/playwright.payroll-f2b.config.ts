import { defineConfig } from '@playwright/test'

// P0.10-F2B Folhas Salariais suite (+ the F2A, F1D, F1C and F1B suites as regressions) on the four QA viewports (Chromium
// managed by Playwright). The runner invokes it twice: --grep-invert "@enabled" (everything else) against the API with the
// shipped production flag (false), then --grep "@enabled" against an API process started with the flag on for that process only.
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: ['payroll-f2b.spec.ts', 'payroll-f2a.spec.ts', 'finance-reporting.spec.ts', 'finance-core.spec.ts', 'finance.spec.ts'],
  timeout: 240_000,
  expect: { timeout: 30_000 },
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['json', { outputFile: process.env.P010_F2B_PLAYWRIGHT_RESULTS ?? '../../docs/reviews/evidence/P0.10-F2B/playwright-results.json' }]],
  use: { baseURL: 'http://127.0.0.1:14173', browserName: 'chromium', headless: true, trace: 'retain-on-failure', screenshot: 'only-on-failure', locale: 'pt-AO', timezoneId: 'Africa/Luanda', acceptDownloads: true },
  projects: [
    { name: 'desktop-1440x900', use: { viewport: { width: 1440, height: 900 } } },
    { name: 'laptop-1366x768', use: { viewport: { width: 1366, height: 768 } } },
    { name: 'tablet-768x1024', use: { viewport: { width: 768, height: 1024 } } },
    { name: 'mobile-390x844', use: { viewport: { width: 390, height: 844 } } },
  ],
})
