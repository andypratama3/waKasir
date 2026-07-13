/**
 * WaKasir brand color tokens.
 * Single source of truth for all programmatic color usage in TypeScript
 * (Chart.js, dynamic styles that can't use CSS variables).
 *
 * For CSS/SCSS usage: always prefer CSS custom properties (--color-primary, etc.)
 * defined in styles.scss. Use these tokens ONLY when CSS vars are not supported
 * (e.g. Chart.js dataset colors, alpha calculations).
 */
export const BRAND = {
  primary:      '#128C7E',
  primaryDark:  '#075E54',
  primaryLight: '#25D366',
  primaryAlpha: (opacity: number) => `rgba(18,140,126,${opacity})`,

  success: '#22c55e',
  warning: '#f59e0b',
  danger:  '#ef4444',
  info:    '#3b82f6',

  /**
   * Chart palette — sequential, high contrast for data visualization.
   * Ordered by visual weight / importance.
   */
  chartPalette: [
    '#128C7E',  // brand green — primary series
    '#25D366',  // WA green — secondary series
    '#0ea5e9',  // sky — tertiary
    '#8b5cf6',  // violet
    '#f59e0b',  // amber
    '#ef4444',  // red
  ],
} as const;
