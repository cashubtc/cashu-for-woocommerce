import { defineConfig } from 'vite';

export default defineConfig({
  resolve: {
    // Prioritize browser-friendly + ESM conditions for packages like @cashu/cashu-ts
    conditions: ['browser', 'module', 'import', 'default'],
  },

  // Dependency pre-bundling
  optimizeDeps: {
    include: ['@cashu/cashu-ts'],
  },

  build: {
    lib: {
      entry: 'src/ts/checkout.ts',
      name: 'CashuCheckout',
      formats: ['iife'],
      fileName: () => 'checkout.js',
    },
    outDir: 'assets/js/cashu',
    target: 'es2020',
    minify: 'oxc',
    sourcemap: true,

    // Resolve mixed ESM/CJS in deps
    commonjsOptions: {
      transformMixedEsModules: true,
      include: [/node_modules/],
    },

    rollupOptions: {
	  external: [],
	},
  },
});
