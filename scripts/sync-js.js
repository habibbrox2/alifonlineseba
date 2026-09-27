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

// Alpine.js (vendored, no CDN dependency at runtime).
const alpineSrc = root + '/node_modules/alpinejs/dist/cdn.min.js';
if (fs.existsSync(alpineSrc)) {
  fs.copyFileSync(alpineSrc, dest + '/js/alpine.min.js');
  console.log('Copied alpine.min.js');
} else {
  console.warn('alpinejs not installed — run: npm i -D alpinejs');
}

console.log('JS assets synced to public/assets');
