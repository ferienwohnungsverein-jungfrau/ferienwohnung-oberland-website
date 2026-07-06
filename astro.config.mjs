// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// https://astro.build/config
export default defineConfig({
  site: 'https://ferienwohnung-oberland.ch',
  integrations: [sitemap()],
  i18n: {
    defaultLocale: 'de',
    locales: ['de', 'en'],
    routing: {
      prefixDefaultLocale: false,
    },
  },
  redirects: {
    '/airbnb-initiative/': '/unser-vorschlag/',
    '/mitglied/': '/mitgliedschaft/',
    '/en/airbnb-initiative/': '/en/our-proposal/',
    '/en/mitglied/': '/en/membership/',
  },
});
