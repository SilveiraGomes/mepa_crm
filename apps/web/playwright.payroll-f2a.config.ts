import { defineConfig } from '@playwright/test'

// P0.10-F2A RH / Folha Salarial suite + the F1D, F1C and F1B suites as Finance UI regressions, on the four QA viewports
// (Chromium managed by Playwright). Evidence under docs/reviews/evidence/P0.10-F2A (the Finance suites write into
// P010_F1D_EVIDENCE_DIR / P010_F1C_EVIDENCE_DIR / P010_UI_EVIDENCE_DIR, set by the runner to sub-folders of it).
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: ['payroll-f2a.spec.ts', 'finance-reporting.spec.ts', 'finance-core.spec.ts', 'finance.spec.ts'],
  timeout: 180_000,
  expect: { timeout: 30_000 },
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['json', { outputFile: '../../docs/reviews/evidence/P0.10-F2A/playwright-results.json' }]],
  use: { baseURL: 'http://127.0.0.1:14173', browserName: 'chromium', headless: true, trace: 'retain-on-failure', screenshot: 'only-on-failure', locale: 'pt-AO', timezoneId: 'Africa/Luanda', acceptDownloads: true },
  projects: [
    { name: 'desktop-1440x900', use: { viewport: { width: 1440, height: 900 } } },
    { name: 'laptop-1366x768', use: { viewport: { width: 1366, height: 768 } } },
    { name: 'tablet-768x1024', use: { viewport: { width: 768, height: 1024 } } },
    { name: 'mobile-390x844', use: { viewport: { width: 390, height: 844 } } },
  ],
})
