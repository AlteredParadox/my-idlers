// ApexCharts for the Prometheus panels on the server detail page. Its own
// Vite entry rather than part of app.js: it is ~150 KB gzipped and only that
// one page draws charts, so the bundle is loaded there and nowhere else.
//
// Bundled instead of loaded from a CDN: the container's Content-Security-Policy
// is script-src 'self', which blocked the jsDelivr copy outright, and a
// third-party script with no integrity attribute is a supply-chain trust the
// app does not otherwise take. The inline chart code in show.blade.php uses
// the global, so export it there.
import ApexCharts from 'apexcharts';

window.ApexCharts = ApexCharts;
