import { defineConfig } from '@playwright/test'

// P0.10-F1B Finance suite: the four QA viewports, evidence under docs/reviews/evidence/P0.10-F1B.
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: 'finance.spec.ts',
  timeout: 120_000,
  expect: { timeout: 10_000 },
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['json', { outputFile: '../../docs/reviews/evidence/P0.10-F1B/playwright-results.json' }]],
  use: { baseURL: 'http://127.0.0.1:14173', browserName: 'chromium', headless: true, trace: 'retain-on-failure', screenshot: 'only-on-failure', locale: 'pt-AO', timezoneId: 'Africa/Luanda' },
  projects: [
    { name: 'desktop-1440x900', use: { viewport: { width: 1440, height: 900 } } },
    { name: 'laptop-1366x768', use: { viewport: { width: 1366, height: 768 } } },
    { name: 'tablet-768x1024', use: { viewport: { width: 768, height: 1024 } } },
    { name: 'mobile-390x844', use: { viewport: { width: 390, height: 844 } } },
  ],
})
