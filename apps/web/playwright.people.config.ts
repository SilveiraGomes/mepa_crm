import { defineConfig } from '@playwright/test'

// P0.5-I People / Families suite. Same browser settings as playwright.config.ts; separate evidence.
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: 'people.spec.ts',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['json', { outputFile: '../../docs/reviews/evidence/P0.5-I/playwright-results.json' }]],
  use: {
    baseURL: 'http://127.0.0.1:14173',
    browserName: 'chromium',
    headless: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    locale: 'pt-AO',
    timezoneId: 'Africa/Luanda',
  },
})
