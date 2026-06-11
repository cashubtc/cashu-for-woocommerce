import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['tests/ts/**/*.test.ts'],
    // Reset localStorage / DOM between tests so cases stay isolated.
    restoreMocks: true,
    clearMocks: true,
    coverage: {
      provider: 'v8',
      include: ['src/ts/**'],
      reporter: ['text-summary', 'lcov'],
      reportsDirectory: 'coverage/ts',
    },
  },
});
