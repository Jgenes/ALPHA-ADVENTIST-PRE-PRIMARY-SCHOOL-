'use strict';
// No authenticated page, API response, form submission, media or document is
// stored offline. This allowlist is deliberately not a wildcard runtime cache.
const CACHE = 'alpha-public-static-v2';
const ASSETS = ['/offline', '/img/logo-96.png', '/img/icon-192.png', '/img/icon-512.png', '/fonts/fonts.css', '/fonts/Inter-400-normal.woff2', '/fonts/Inter-600-normal.woff2'];
const PRIVATE = /^\/(api(?:\/|$)|portal(?:\/|$)|admin(?:\/|$)|media(?:\/|$)|downloads\/)/;
self.addEventListener('install', event => { event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(ASSETS)).then(() => self.skipWaiting())); });
self.addEventListener('activate', event => { event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('alpha-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin || PRIVATE.test(url.pathname)) return;
  if (ASSETS.includes(url.pathname) && !url.search) {
    event.respondWith(caches.match(request).then(cached => cached || fetch(request)));
    return;
  }
  if (request.mode === 'navigate') event.respondWith(fetch(request).catch(() => caches.match('/offline')));
});
