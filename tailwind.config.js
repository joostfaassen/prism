/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './templates/**/*.html.twig',
        './assets/**/*.js',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['"Space Grotesk"', 'system-ui', '-apple-system', 'sans-serif'],
                mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
            },
        },
    },
    plugins: [],
};
