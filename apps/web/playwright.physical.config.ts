import { defineConfig } from '@playwright/test'

// P0.7 Physical Locations suite: the four QA viewports, evidence under docs/reviews/evidence/P0.7.
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: 'physical.spec.ts',
  timeout: 90_000,
  expect: { timeout: 10_000 },
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['json', { outputFile: '../../docs/reviews/evidence/P0.7/playwright-results.json' }]],
  use: { baseURL: 'http://127.0.0.1:14173', browserName: 'chromium', headless: true, trace: 'retain-on-failure', screenshot: 'only-on-failure', locale: 'pt-AO', timezoneId: 'Africa/Luanda' },
  projects: [
    { name: 'desktop-1440x900', use: { viewport: { width: 1440, height: 900 } } },
    { name: 'laptop-1366x768', use: { viewport: { width: 1366, height: 768 } } },
    { name: 'tablet-768x1024', use: { viewport: { width: 768, height: 1024 } } },
    { name: 'mobile-390x844', use: { viewport: { width: 390, height: 844 } } },
  ],
})
