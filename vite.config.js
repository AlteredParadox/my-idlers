import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { copyFileSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

// FontAwesome's stylesheet points at /webfonts (see resources/sass/app.scss), a
// stable path outside the hashed build directory, because two Blade layouts
// preload those files by name and the font-loading fallback constructs FontFace
// URLs from them. Copy them there on build so the source of truth stays the
// npm package rather than whatever happens to be committed.
const FA_WEBFONTS = ['fa-regular-400.woff2', 'fa-solid-900.woff2', 'fa-brands-400.woff2'];

function copyFontAwesomeFonts() {
    return {
        name: 'copy-fontawesome-webfonts',
        apply: 'build',
        closeBundle() {
            const from = resolve('node_modules/@fortawesome/fontawesome-free/webfonts');
            const to = resolve('public/webfonts');
            mkdirSync(to, { recursive: true });
            for (const f of FA_WEBFONTS) copyFileSync(resolve(from, f), resolve(to, f));
        },
    };
}

// IBM Plex backs the "Modern (Dark)" theme (see resources/css/fonts-ibm-plex.css).
// The @font-face rules are hand-written rather than imported from Fontsource so
// that only the subsets this app actually renders get shipped: Fontsource's own
// entrypoints pull in Cyrillic, Greek and Vietnamese as well, and its per-weight
// stylesheets also reference a .woff fallback no supported browser needs.
// Copied to /webfonts alongside FontAwesome for the same reason -- a stable path
// the hand-written stylesheet can name without going through the hashed build.
const PLEX_WEBFONTS = [
    ['@fontsource-variable/ibm-plex-sans/files', 'ibm-plex-sans-latin-wght-normal.woff2'],
    ['@fontsource-variable/ibm-plex-sans/files', 'ibm-plex-sans-latin-ext-wght-normal.woff2'],
    ['@fontsource/ibm-plex-mono/files', 'ibm-plex-mono-latin-400-normal.woff2'],
    ['@fontsource/ibm-plex-mono/files', 'ibm-plex-mono-latin-500-normal.woff2'],
    ['@fontsource/ibm-plex-mono/files', 'ibm-plex-mono-latin-600-normal.woff2'],
];

function copyIbmPlexFonts() {
    return {
        name: 'copy-ibm-plex-webfonts',
        apply: 'build',
        closeBundle() {
            const to = resolve('public/webfonts');
            mkdirSync(to, { recursive: true });
            for (const [dir, f] of PLEX_WEBFONTS) {
                copyFileSync(resolve('node_modules', dir, f), resolve(to, f));
            }
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            refresh: true,
        }),
        copyFontAwesomeFonts(),
        copyIbmPlexFonts(),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                // Lets the FontAwesome imports resolve without webpack's "~".
                loadPaths: ['node_modules'],
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'legacy-js-api'],
            },
        },
    },
    // Note: build.manifest is deliberately NOT set here. laravel-vite-plugin
    // points it at build/manifest.json, which is where Laravel's @vite looks;
    // setting `manifest: true` instead makes Vite write .vite/manifest.json and
    // every page 500s with "Vite manifest not found".
    //
    // Assets are committed and served directly (the Dockerfile runs no npm
    // step). Hashed filenames give cache-busting, and Blade resolves them
    // through the committed manifest.
    define: {
        // Vue 3's esm-bundler build expects the bundler to define these.
        __VUE_OPTIONS_API__: 'true',
        __VUE_PROD_DEVTOOLS__: 'false',
        __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
    },
});
