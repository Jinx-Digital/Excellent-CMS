export default defineNuxtConfig({
  // SPA like Fixoo: the Yii3 API is the only backend, the admin app is static files.
  ssr: false,

  modules: ['@nuxt/ui', '@vueuse/nuxt', '@nuxtjs/i18n'],

  // Texts of the admin app: English, translated in i18n/locales/<code>.json. The choice is kept in a
  // cookie and sent to the API as Accept-Language (its messages follow it).
  i18n: {
    strategy: 'no_prefix',
    defaultLocale: 'en',
    locales: [
      { code: 'en', language: 'en-US', name: 'English', file: 'en.json' },
      { code: 'de', language: 'de-DE', name: 'Deutsch', file: 'de.json' }
    ],
    detectBrowserLanguage: { useCookie: true, cookieKey: 'cms_locale', redirectOn: 'root', fallbackLocale: 'en' },
    // A few texts of our own locale files contain <code> (API docs, shown with v-html)
    compilation: { strictMessage: false, escapeHtml: false }
  },

  css: ['~/assets/css/main.css'],

  app: {
    head: {
      htmlAttrs: { lang: 'en' },
      title: 'Excellent CMS',
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1, viewport-fit=cover' },
        { name: 'theme-color', content: '#059669' },
        // Installed as an app (PWA, see public/manifest.webmanifest and public/sw.js)
        { name: 'mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-title', content: 'Excellent' },
        { name: 'apple-mobile-web-app-status-bar-style', content: 'default' }
      ],
      link: [
        { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' },
        { rel: 'apple-touch-icon', href: '/apple-touch-icon.png' },
        { rel: 'manifest', href: '/manifest.webmanifest' }
      ]
    }
  },

  colorMode: {
    preference: 'system',
    fallback: 'light'
  },

  // App and API are served together from public/, the API lives under /api/v1.
  runtimeConfig: {
    public: {
      apiBaseUrl: '/api/v1'
    }
  },

  // Dev: forward /api and the uploaded files (/media) to `make serve` (port 8090; other target via NUXT_API_PROXY_TARGET).
  routeRules: {
    '/api/**': { proxy: `${process.env.NUXT_API_PROXY_TARGET || 'http://localhost:8090'}/api/**` },
    '/media/**': { proxy: `${process.env.NUXT_API_PROXY_TARGET || 'http://localhost:8090'}/media/**` }
  },

  // Dev: CodeMirror (loaded when an editor is shown) bundled when the server starts - found only later,
  // Vite bundles it anew and open tabs fail to load the old files
  vite: {
    optimizeDeps: {
      include: [
        'codemirror', '@codemirror/state', '@codemirror/view', '@codemirror/commands', '@codemirror/language',
        '@codemirror/lang-html', '@codemirror/lang-css', '@codemirror/lang-javascript', '@codemirror/lang-php',
        '@codemirror/lang-json', '@codemirror/lang-markdown', '@codemirror/lang-sql', '@codemirror/legacy-modes/mode/shell'
      ]
    }
  },

  devtools: { enabled: false },

  compatibilityDate: '2026-06-30'
})
