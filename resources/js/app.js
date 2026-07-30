// Stylesheets are imported here rather than linked separately in Blade: Vite
// emits them as one hashed CSS asset alongside this bundle, and @vite renders
// the <link> for it. Order matches the old link order -- Bootstrap and the
// themes first, then DataTables and local styles, then FontAwesome.
import '../css/fonts-ibm-plex.css';
import '../css/theme.css';
import '../css/app.css';
import '../sass/app.scss';

// Must come before datatables.net-bs5 and before any inline Blade script that
// uses $ -- see the note in jquery-global.js about import hoisting.
import './jquery-global.js';

import 'bootstrap';
import 'datatables.net-bs5';

import axios from 'axios';

// No Vue, deliberately. This app's only client state was a delete-confirm
// dialog, two <select>s that recompute a link, and a DNS autofill button -- but
// serving them meant shipping vue.esm-bundler.js (the build WITH the browser
// template compiler) and mounting it on each page's whole container. Vue then
// treated the server-rendered markup as a template, and since Blade's escaping
// leaves `{{ }}` untouched, any stored value -- a hostname, a provider name, an
// ipwhois.app field, a YABS-reported CPU model -- became an expression the
// runtime compiled and executed. Plain listeners have no such sink, and the CSP
// no longer needs 'unsafe-eval'.
import { initDeleteModal } from './delete-modal.js';

globalThis.axios = axios;

initDeleteModal();
