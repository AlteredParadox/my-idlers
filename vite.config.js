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

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            refresh: true,
        }),
        copyFontAwesomeFonts(),
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
