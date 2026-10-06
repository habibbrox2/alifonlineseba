/* Copies frontend JS sources + vendored libs into public/assets (build-time only). */
const fs = require('fs');
const path = require('path');

const root = __dirname + '/..';
const dest = root + '/public/assets';

function ensure(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

// Application JS (as ES modules) — served with type=module in templates.
ensure(dest + '/js');
fs.copyFileSync(root + '/resources/js/app.js', dest + '/js/app.js');
fs.copyFileSync(root + '/resources/js/dashboard.js', dest + '/js/dashboard.js');
fs.copyFileSync(root + '/resources/js/service-history.js', dest + '/js/service-history.js');
fs.copyFileSync(root + '/resources/js/icon-picker.js', dest + '/js/icon-picker.js');
fs.copyFileSync(root + '/resources/js/field-sorter.js', dest + '/js/field-sorter.js');
fs.copyFileSync(root + '/resources/js/date-field.js', dest + '/js/date-field.js');
fs.copyFileSync(root + '/resources/js/app-install.js', dest + '/js/app-install.js');
fs.copyFileSync(root + '/resources/js/push-subscribe.js', dest + '/js/push-subscribe.js');
fs.copyFileSync(root + '/resources/js/push-prompt.js', dest + '/js/push-prompt.js');

// public/push-sw.js is NOT copied: the Push API requires the worker script to
// be served from the web root so its scope covers every route, and that file is
// a source file rather than a build artefact.

// Alpine.js (vendored, no CDN dependency at runtime).
const alpineSrc = root + '/node_modules/alpinejs/dist/cdn.min.js';
if (fs.existsSync(alpineSrc)) {
  fs.copyFileSync(alpineSrc, dest + '/js/alpine.min.js');
  console.log('Copied alpine.min.js');
} else {
  console.warn('alpinejs not installed — run: npm i -D alpinejs');
}

console.log('JS assets synced to public/assets');
