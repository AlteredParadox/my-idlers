// Stylesheets are imported here rather than linked separately in Blade: Vite
// emits them as one hashed CSS asset alongside this bundle, and @vite renders
// the <link> for it. Order matches the old link order -- Bootstrap and the
// themes first, then DataTables and local styles, then FontAwesome.
import '../css/theme.css';
import '../css/app.css';
import '../sass/app.scss';

// Must come before datatables.net-bs5 and before any inline Blade script that
// uses $ -- see the note in jquery-global.js about import hoisting.
import './jquery-global.js';

import 'bootstrap';
import 'datatables.net-bs5';

// esm-bundler, not the default entry: templates live in the Blade markup and
// are compiled in the browser, so we need the build that bundles the compiler.
// Vue 3 has no 'vue/dist/vue' entry at all.
import * as Vue from 'vue/dist/vue.esm-bundler.js';
import axios from 'axios';

globalThis.Vue = Vue;
globalThis.axios = axios;
