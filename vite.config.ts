import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The dashboard is served from the plugin's build/ directory. WordPress
// constructs asset URLs from manifest.json, so relative asset paths are used.
export default defineConfig({
  base: './',
  plugins: [react()],
  build: {
    outDir: 'build',
    emptyOutDir: true,
    manifest: 'manifest.json',
    sourcemap: false,
    rollupOptions: {
      input: {
        dashboard: 'src/app/index.tsx',
        authors: 'src/authors/index.tsx',
      },
      output: {
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },
});
