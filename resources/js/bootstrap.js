globalThis._ = require('lodash');
globalThis.Vue = require('vue');
try {
    require('bootstrap');
} catch (e) {
    console.warn('bootstrap could not be loaded', e);
}

/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

globalThis.axios = require('axios');

globalThis.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
