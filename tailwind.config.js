import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans:    ['Inter', ...defaultTheme.fontFamily.sans],
                mono:    ['DM Mono', ...defaultTheme.fontFamily.mono],
                display: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                nexus: {
                    sidebar: '#111827',
                    accent: '#4f46e5',
                    'accent-light': '#6366f1',
                    content: '#f3f4f6',
                },
                // 2026-08-25 — moved from a per-page `tailwind.config` object
                // in corex/properties/live-preview.blade.php's Tailwind CDN
                // <script> (the CDN's runtime JIT compiler read this at
                // request time; the real, build-time Tailwind here needs the
                // same tokens registered globally to compile the same
                // classes into app.css). Only that page uses these today.
                ink:                '#060a1c',
                'ink-soft':         '#0d1430',
                navy:               '#141a4d',
                marine:             '#3ba1e6',
                'brand-red':        '#df1f2c',
                'brand-red-bright': '#f5404d',
            },
        },
    },

    plugins: [forms],
};
