import { definePreset } from '@primeuix/themes';
import Aura from '@primeuix/themes/aura';

/** WaKasir brand preset — extends PrimeNG Aura with WhatsApp-teal primary. */
export const WaKasirPreset = definePreset(Aura, {
  semantic: {
    primary: {
      50: '#e8f6f3',
      100: '#c5ebe3',
      200: '#9edfd2',
      300: '#6ecfb9',
      400: '#3fbaa3',
      500: '#128C7E',
      600: '#0f7569',
      700: '#0c5e54',
      800: '#094840',
      900: '#06332d',
      950: '#031a17',
    },
    colorScheme: {
      light: {
        surface: {
          0: '#ffffff',
          50: '#f8fafb',
          100: '#f1f5f4',
          200: '#e4ecea',
          300: '#d1ddd9',
          400: '#9eb5ae',
          500: '#6b8d84',
          600: '#4a6b62',
          700: '#354f48',
          800: '#243832',
          900: '#152220',
          950: '#0a1210',
        },
      },
    },
  },
});
