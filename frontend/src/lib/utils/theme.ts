import type { ThemeSettings } from '@/features/settings/types';

/**
 * Turns admin-defined colours into CSS custom properties.
 *
 * This is the mechanism that makes brand colours dynamic without a rebuild.
 * Tailwind utilities reference `hsl(var(--primary))`, and the values of those
 * variables are written into the document at request time from the settings
 * API — so changing a colour in the admin panel restyles the site with no
 * deploy, no Tailwind recompile, and no runtime CSS-in-JS.
 *
 * Colours are emitted as bare HSL triples ("221 83% 53%") rather than full
 * `hsl(...)` strings, which is what lets Tailwind compose them with an alpha
 * channel — `bg-primary/50` expands to `hsl(var(--primary) / 0.5)`.
 */

interface Hsl {
  h: number;
  s: number;
  l: number;
}

/** Parse #rgb, #rrggbb, or #rrggbbaa. Returns null on anything else. */
function parseHex(hex: string): { r: number; g: number; b: number } | null {
  const normalised = hex.trim().replace(/^#/, '');

  const expanded =
    normalised.length === 3
      ? normalised
          .split('')
          .map((char) => char + char)
          .join('')
      : normalised;

  if (!/^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/.test(expanded)) {
    return null;
  }

  return {
    r: parseInt(expanded.slice(0, 2), 16),
    g: parseInt(expanded.slice(2, 4), 16),
    b: parseInt(expanded.slice(4, 6), 16),
  };
}

function rgbToHsl(r: number, g: number, b: number): Hsl {
  const rn = r / 255;
  const gn = g / 255;
  const bn = b / 255;

  const max = Math.max(rn, gn, bn);
  const min = Math.min(rn, gn, bn);
  const delta = max - min;

  const l = (max + min) / 2;

  if (delta === 0) {
    return { h: 0, s: 0, l: Math.round(l * 100) };
  }

  const s = l > 0.5 ? delta / (2 - max - min) : delta / (max + min);

  let h: number;
  switch (max) {
    case rn:
      h = ((gn - bn) / delta + (gn < bn ? 6 : 0)) / 6;
      break;
    case gn:
      h = ((bn - rn) / delta + 2) / 6;
      break;
    default:
      h = ((rn - gn) / delta + 4) / 6;
  }

  return {
    h: Math.round(h * 360),
    s: Math.round(s * 100),
    l: Math.round(l * 100),
  };
}

/**
 * Re-serialise a hex colour from its parsed channel values.
 *
 * The return value is built from three integers, so it cannot carry anything
 * through from the admin's input — which is what makes it safe to inject into
 * the <style> block. Returns null for anything that does not parse.
 */
export function normaliseHex(hex: string | null | undefined): string | null {
  if (!hex) return null;

  const rgb = parseHex(hex);
  if (!rgb) return null;

  const channel = (value: number): string => value.toString(16).padStart(2, '0');

  return `#${channel(rgb.r)}${channel(rgb.g)}${channel(rgb.b)}`;
}

/** "#2563eb" -> "221 83% 53%", or null when the input is not a valid hex colour. */
export function hexToHslTriple(hex: string | null | undefined): string | null {
  if (!hex) return null;

  const rgb = parseHex(hex);
  if (!rgb) return null;

  const { h, s, l } = rgbToHsl(rgb.r, rgb.g, rgb.b);

  return `${h} ${s}% ${l}%`;
}

/**
 * Pick black or white text for a given background, using the WCAG relative
 * luminance formula. Without this, an admin choosing a pale brand colour would
 * get white-on-pale-yellow buttons that fail contrast requirements.
 */
export function readableForeground(hex: string | null | undefined): string {
  const rgb = parseHex(hex ?? '');
  if (!rgb) return '0 0% 100%';

  const channel = (value: number): number => {
    const c = value / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  };

  const luminance = 0.2126 * channel(rgb.r) + 0.7152 * channel(rgb.g) + 0.0722 * channel(rgb.b);

  // 0.179 is the luminance at which contrast against black and white is equal.
  return luminance > 0.179 ? '0 0% 9%' : '0 0% 100%';
}

/**
 * Build the CSS custom property declarations for the admin's theme.
 *
 * Returned as a plain string for injection into a <style> tag during server
 * rendering, so the correct colours are present in the initial HTML — a
 * client-side application would produce a visible flash of the default theme.
 */
export function buildThemeCss(theme: ThemeSettings | undefined): string {
  const variables: Record<string, string> = {};

  // An unset button colour inherits the primary colour rather than going
  // unstyled — mirrors the same rule in buildStoreConfig.
  const buttonColor = theme?.button_color ?? theme?.primary_color;

  const colorMap: Array<[string | null | undefined, string]> = [
    [theme?.primary_color, 'primary'],
    [theme?.secondary_color, 'secondary'],
    [theme?.accent_color, 'accent'],
    [theme?.background_color, 'background'],
    [theme?.foreground_color, 'foreground'],
    [buttonColor, 'button'],
    [theme?.destructive_color, 'destructive'],
  ];

  for (const [value, cssName] of colorMap) {
    const triple = hexToHslTriple(value);

    if (triple) {
      variables[`--${cssName}`] = triple;

      // Also emit the literal hex under the names the brief specifies, for
      // plain CSS that does not go through Tailwind's hsl(var(--x)) wrapper.
      // Safe to inject: the value is re-serialised from parsed integers below,
      // never echoed verbatim.
      const hex = normaliseHex(value);

      if (hex) {
        variables[`--${cssName}-color`] = hex;
      }
    }
  }

  // Paired foreground colours, so text on a coloured surface stays legible
  // whatever the administrator picks.
  for (const [value, cssName] of [
    [theme?.primary_color, 'primary'],
    [theme?.secondary_color, 'secondary'],
    [theme?.accent_color, 'accent'],
    [buttonColor, 'button'],
    [theme?.destructive_color, 'destructive'],
  ] as Array<[string | null | undefined, string]>) {
    if (value) {
      variables[`--${cssName}-foreground`] = readableForeground(value);
    }
  }

  // `--text-color` is the name the brief specifies for body text; `--foreground`
  // is what the Tailwind layer already consumes. Both point at the same value.
  const textHex = normaliseHex(theme?.foreground_color);

  if (textHex) {
    variables['--text-color'] = textHex;
  }

  // `radius` and `font_family` are free-text admin inputs, unlike the colours
  // which are reconstructed from parsed numbers. They are injected into a
  // <style> block, so they are validated against a strict allowlist here — a
  // value containing `}` could otherwise close the rule and inject arbitrary
  // CSS.
  if (theme?.radius && /^\d{1,3}(\.\d{1,3})?(px|rem|em|%)$/.test(theme.radius.trim())) {
    variables['--radius'] = theme.radius.trim();
  }

  if (theme?.font_family) {
    const family = theme.font_family.trim();

    // Letters, digits, spaces, and hyphens only — enough for every real font
    // family name and nothing that is meaningful as CSS syntax.
    if (/^[A-Za-z0-9 -]{1,64}$/.test(family)) {
      // Quoted so multi-word family names remain valid CSS.
      variables['--font-family-brand'] = `"${family}", ui-sans-serif, system-ui, sans-serif`;
    }
  }

  const declarations = Object.entries(variables)
    .map(([name, value]) => `${name}: ${value};`)
    .join('')
    .trim();

  return declarations ? `:root{${declarations}}` : '';
}
