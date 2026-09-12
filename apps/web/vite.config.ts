import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['icons/mepa-icon.svg'],
      manifest: {
        name: 'MEPA Gestão',
        short_name: 'MEPA',
        description: 'Plataforma de gestão da Missão Evangélica Pentecostal de Angola',
        lang: 'pt-AO',
        start_url: '/',
        display: 'standalone',
        background_color: '#071f1a',
        theme_color: '#0b362b',
        icons: [{
          src: '/icons/mepa-icon.svg',
          sizes: 'any',
          type: 'image/svg+xml',
          purpose: 'any maskable',
        }],
      },
      workbox: { navigateFallback: '/index.html' },
    }),
  ],
})
