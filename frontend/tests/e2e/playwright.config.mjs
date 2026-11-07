// Pruebas de interfaz de F11f (README › Comprobaciones):
//   cd frontend/tests/e2e · npm ci · npx playwright install chromium · npm test (run.mjs: ruta real en Windows)
// Dos tamaños: móvil 390×844 (modo campo, táctil) y escritorio 1280×800 (modo estudio). La API va simulada
// (mock-api.mjs); el frontend lo sirve server.mjs. Con el Service Worker activo: «sin red» es sin red de verdad.
import { defineConfig } from '@playwright/test';

const PORT = 4173;

export default defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.mjs$/,
  timeout: 60000,
  expect: { timeout: 10000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: `http://localhost:${PORT}`,
    locale: 'es-ES',
    timezoneId: 'Europe/Madrid',
    serviceWorkers: 'allow',
    geolocation: { latitude: 37.0702, longitude: -3.3201, accuracy: 8 },   // junto a la Pinguicula de la demo
    permissions: ['geolocation'],
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'movil', use: { browserName: 'chromium', viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, deviceScaleFactor: 2 } },
    { name: 'escritorio', use: { browserName: 'chromium', viewport: { width: 1280, height: 800 } } },
  ],
  webServer: {
    command: `node server.mjs ${PORT}`,
    url: `http://localhost:${PORT}/index.html`,
    reuseExistingServer: !process.env.CI,
  },
});
