/**
 * Tailwind CSS v4 is configured through CSS (`@theme` in globals.css) rather
 * than a tailwind.config.js — the PostCSS plugin is the only build-time wiring
 * required.
 */
const config = {
  plugins: {
    '@tailwindcss/postcss': {},
  },
};

export default config;
