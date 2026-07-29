import { defineConfig } from 'vitepress'

// https://vitepress.dev/reference/site-config
export default defineConfig({
  title: 'Parakit',
  description: 'The payment kit for Kurdistan and Iraq — Laravel-native.',
  lang: 'en-US',

  // Project Pages site: https://shahrammebashar.github.io/parakit/
  base: '/parakit/',

  lastUpdated: true,
  cleanUrls: true,

  head: [['link', { rel: 'icon', href: '/parakit/favicon.ico' }]],

  markdown: {
    // `.env` snippets are tagged ```env — highlight them as ini.
    languageAlias: { env: 'ini' },
  },

  themeConfig: {
    nav: [
      { text: 'Guide', link: '/introduction', activeMatch: '^/(introduction|installation|configuration|guides)' },
      { text: 'Tutorials', link: '/tutorials/orders-and-wallet-deposits', activeMatch: '^/tutorials/' },
      { text: 'Gateways', link: '/gateways/fib', activeMatch: '^/gateways/' },
      { text: 'Reference', link: '/reference/commands', activeMatch: '^/reference/' },
      {
        text: 'v1.0',
        items: [
          { text: 'Changelog', link: 'https://github.com/ShahramMebashar/parakit/blob/main/CHANGELOG.md' },
          { text: 'Security policy', link: 'https://github.com/ShahramMebashar/parakit/blob/main/SECURITY.md' },
        ],
      },
    ],

    sidebar: [
      {
        text: 'Getting started',
        items: [
          { text: 'Introduction', link: '/introduction' },
          { text: 'Installation', link: '/installation' },
          { text: 'Configuration', link: '/configuration' },
        ],
      },
      {
        text: 'Core concepts',
        items: [
          { text: 'Charging a customer', link: '/guides/charging-a-customer' },
          { text: 'Handling webhooks', link: '/guides/handling-webhooks' },
          { text: 'Refunds', link: '/guides/refunds' },
          { text: 'Receipts', link: '/guides/receipts' },
          { text: 'Reliability', link: '/guides/reliability' },
        ],
      },
      {
        text: 'Tutorials',
        items: [
          { text: 'Orders and wallet deposits', link: '/tutorials/orders-and-wallet-deposits' },
        ],
      },
      {
        text: 'Gateways',
        items: [
          { text: 'FIB', link: '/gateways/fib' },
          { text: 'ZainCash', link: '/gateways/zaincash' },
          { text: 'Nass Pay', link: '/gateways/nass' },
          { text: 'Nass Wallet', link: '/gateways/nasswallet' },
          { text: 'FastPay', link: '/gateways/fastpay' },
          { text: 'QiCard', link: '/gateways/qicard' },
        ],
      },
      {
        text: 'Advanced',
        items: [
          { text: 'Multi-tenant merchants', link: '/guides/multi-tenant-merchants' },
          { text: 'Writing a custom gateway', link: '/guides/custom-gateway' },
          { text: 'Testing & sandbox', link: '/guides/testing-and-sandbox' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Artisan commands', link: '/reference/commands' },
          { text: 'Events', link: '/reference/events' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/ShahramMebashar/parakit' },
    ],

    search: { provider: 'local' },

    editLink: {
      pattern: 'https://github.com/ShahramMebashar/parakit/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'پارەکیت — Parakit',
    },
  },
})
