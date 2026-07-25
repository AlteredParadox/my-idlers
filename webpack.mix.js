const mix = require('laravel-mix');
const webpack = require('webpack');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel applications. By default, we are compiling the CSS
 | file for the application as well as bundling up all the JS files.
 |
 */

mix.js('resources/js/app.js', 'public/js')
    .postCss('resources/css/app.css', 'public/css')
    // One stylesheet for all three themes: light.css, dark.css and
    // neutral-dark.css collapsed into custom properties selected by data-theme
    // on <html>. See the header of theme.css.
    .postCss('resources/css/theme.css', 'public/css', [
        require('autoprefixer')({ overrideBrowserslist: ['last 2 versions'], grid: true })
    ])
    .sass('resources/sass/app.scss', 'public/css', {
        sassOptions: {
            quietDeps: true,
            silenceDeprecations: ['import', 'global-builtin', 'legacy-js-api']
        }
    })
    .options({
        processCssUrls: false
    })
    // woff2 only: FontAwesome 7 stopped shipping .ttf, and woff2 has been
    // supported by every browser we target for years. app.scss imports the
    // regular, solid and brands faces, so all three fonts must be copied --
    // brands was previously copied as .ttf only, leaving its woff2 to be
    // committed by hand.
    .copy('node_modules/@fortawesome/fontawesome-free/webfonts/fa-regular-400.woff2', 'public/webfonts/fa-regular-400.woff2')
    .copy('node_modules/@fortawesome/fontawesome-free/webfonts/fa-solid-900.woff2', 'public/webfonts/fa-solid-900.woff2')
    .copy('node_modules/@fortawesome/fontawesome-free/webfonts/fa-brands-400.woff2', 'public/webfonts/fa-brands-400.woff2');

mix.webpackConfig({
    stats: {
        children: false,
        warnings: false
    },
    plugins: [
        // Vue 3's esm-bundler build expects the bundler to define these. Without
        // them it still works, but logs a "feature flags not defined" warning on
        // every page load and cannot tree-shake the unused branches.
        new webpack.DefinePlugin({
            __VUE_OPTIONS_API__: 'true',          // the Blade instances are options API
            __VUE_PROD_DEVTOOLS__: 'false',
            __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false'
        })
    ]
});
