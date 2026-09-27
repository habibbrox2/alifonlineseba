/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './resources/views/**/*.twig',
    './resources/js/**/*.js',
    './src/**/*.php',
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          50: '#ecfdf5',
          100: '#d1fae5',
          200: '#a7f3d0',
          300: '#6ee7b7',
          400: '#34d399',
          500: '#10b981',
          600: '#0d8f68',
          700: '#0b6e51',
          800: '#0a5540',
          900: '#083f31',
        },
        accent: {
          50: '#fffbeb',
          100: '#fef3c7',
          200: '#fde68a',
          300: '#fcd34d',
          400: '#fbbf24',
          500: '#f5b301',
          600: '#d99a00',
          700: '#b87f00',
        },
        success: { 50: '#ecfdf5', 100: '#d1fae5', 200: '#a7f3d0', 600: '#059669', 700: '#047857' },
        warning: { 50: '#fffbeb', 100: '#fef3c7', 200: '#fde68a', 600: '#d97706', 700: '#b45309' },
        danger: { 50: '#fef2f2', 100: '#fee2e2', 200: '#fecaca', 600: '#dc2626', 700: '#b91c1c' },
        info: { 50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 600: '#2563eb', 700: '#1d4ed8' },
        surface: {
          50: '#f8faf9',
          100: '#f4f7f6',
          200: '#e8eeec',
        },
      },
      boxShadow: {
        sm: '0 1px 2px 0 rgb(8 63 49 / 0.05)',
        DEFAULT: '0 2px 8px -1px rgb(8 63 49 / 0.08), 0 1px 2px -1px rgb(8 63 49 / 0.06)',
        md: '0 6px 20px -3px rgb(8 63 49 / 0.12), 0 2px 6px -2px rgb(8 63 49 / 0.06)',
        lg: '0 12px 32px -6px rgb(8 63 49 / 0.16)',
      },
      borderRadius: {
        xl: '0.875rem',
        '2xl': '1.125rem',
      },
    },
  },
  plugins: [],
};
