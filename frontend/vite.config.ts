import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      /*
       * Uploaded files — photos, company logos, invoice stamps.
       *
       * In production the docroot carries a /storage symlink into the app's
       * public disk (see deploy/cpanel/publish.sh), so photoUrl()'s
       * /storage/x.png is served as a plain file. In dev that path hit Vite,
       * which knows nothing about it and answered with index.html — so every
       * uploaded image was a broken one, on every machine, and a stamp that
       * prints perfectly well looked like it did not work at all.
       */
      '/storage': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
