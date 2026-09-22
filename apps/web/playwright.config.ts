import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 45_000,
  expect: { timeout: 10_000 },
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['json', { outputFile: '../../docs/reviews/evidence/P0.3.5-A4.2/playwright-results.json' }]],
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
