require('bootstrap')
// esm-bundler, not the default entry: templates live in the Blade markup and
// are compiled in the browser, so we need the build that bundles the compiler.
// Vue 3 has no 'vue/dist/vue' entry at all.
globalThis.Vue = require('vue/dist/vue.esm-bundler.js');
globalThis.axios = require('axios');
import $ from 'jquery';
globalThis.jQuery = $;
globalThis.$ = $;
require('datatables.net-bs5');
