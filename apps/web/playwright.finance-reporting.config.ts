import { defineConfig } from '@playwright/test'

// P0.10-F1D-E1 Finance reporting suite (dashboard, own / consolidated DRE and DOAF, control reports, periods, exports,
// contributions, own-only reader) + the F1C and F1B suites as regressions, on the four QA viewports. Evidence under
// docs/reviews/evidence/P0.10-F1D (the F1C / F1B suites write into P010_F1C_EVIDENCE_DIR / P010_UI_EVIDENCE_DIR, set by the
// runner to sub-folders of it). Same single-threaded E2E API as F1C: 30 s assertion timeout (F1C-T06).
export default defineConfig({
  testDir: './tests/e2e',
  testMatch: ['finance-reporting.spec.ts', 'finance-core.spec.ts', 'finance.spec.ts'],
  timeout: 180_000,
  expect: { timeout: 30_000 },
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['json', { outputFile: '../../docs/reviews/evidence/P0.10-F1D/playwright-results.json' }]],
  use: { baseURL: 'http://127.0.0.1:14173', browserName: 'chromium', headless: true, trace: 'retain-on-failure', screenshot: 'only-on-failure', locale: 'pt-AO', timezoneId: 'Africa/Luanda', acceptDownloads: true },
  projects: [
    { name: 'desktop-1440x900', use: { viewport: { width: 1440, height: 900 } } },
    { name: 'laptop-1366x768', use: { viewport: { width: 1366, height: 768 } } },
    { name: 'tablet-768x1024', use: { viewport: { width: 768, height: 1024 } } },
    { name: 'mobile-390x844', use: { viewport: { width: 390, height: 844 } } },
  ],
})
