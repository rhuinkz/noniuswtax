const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '/suite/specs',
  testMatch: '**/*.spec.js',
  timeout: 90000,
  expect: { timeout: 15000 },
  workers: 1,
  retries: 0,
  fullyParallel: false,
  reporter: [
    ['list'],
    ['html', { outputFolder: '/suite/output/html', open: 'never' }],
    ['json', { outputFile: '/suite/output/results.json' }]
  ],
  outputDir: '/suite/output/artifacts',
  use: {
    ...devices['Desktop Chrome'],
    baseURL: process.env.BASE_URL || 'http://web',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure'
  }
});
