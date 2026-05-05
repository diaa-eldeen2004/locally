import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

const cspMetaForBuild = () => ({
  name: 'csp-meta-build-only',
  apply: 'build' as const,
  transformIndexHtml(html: string) {
    const csp =
      "default-src 'self'; base-uri 'self'; form-action 'self'; object-src 'none'; frame-ancestors 'none'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; connect-src 'self'"
    const meta = `<meta http-equiv="Content-Security-Policy" content="${csp}">`
    return html.replace('</head>', `  ${meta}\n  </head>`)
  },
})

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), cspMetaForBuild()],
  server: {
    proxy: {
      // Phase 0: forward API to PHP (php -S 127.0.0.1:8080 -t public public/router.php)
      '/api': {
        target: 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
      // Serve uploaded media from backend public/uploads while developing frontend on :5173
      '/uploads': {
        target: 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
    },
  },
})
