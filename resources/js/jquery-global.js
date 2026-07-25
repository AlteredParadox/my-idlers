// jQuery, exposed globally BEFORE anything that expects to find it there.
//
// This lives in its own module on purpose. `import` statements are hoisted and
// run before any other statement in a module, so assigning the globals inline
// in app.js would happen AFTER `import 'datatables.net-bs5'` had already
// executed -- DataTables would not attach, $.fn.dataTable would be undefined,
// and every page with a table would throw on `$.fn.dataTable.ext`.
//
// Side-effect imports of sibling modules DO execute in source order, so
// importing this first gives DataTables (and the inline Blade scripts) the
// global they need.
import $ from 'jquery';

globalThis.jQuery = $;
globalThis.$ = $;

export default $;
