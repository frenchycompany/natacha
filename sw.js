const CACHE_NAME = 'natacha-v2';
const STATIC_CACHE = 'natacha-static-v2';

const APP_SHELL = [
  '/natacha/manifest.json',
  '/natacha/offline.html',
  '/natacha/assets/icons/icon-192x192.png',
  '/natacha/assets/icons/icon-512x512.png'
];

// Install: cache essentials
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL))
  );
  self.skipWaiting();
});

// Activate: clean old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((k) => k !== CACHE_NAME && k !== STATIC_CACHE)
          .map((k) => caches.delete(k))
      )
    )
  );
  self.clients.claim();
});

// Fetch strategy
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Skip non-GET
  if (event.request.method !== 'GET') return;

  // Skip API/AJAX calls (JSON responses)
  if (url.pathname.includes('/api/') || event.request.headers.get('Accept')?.includes('application/json')) return;

  // Static assets: cache-first
  if (url.pathname.match(/\.(woff2?|ttf|otf|eot|svg|png|jpg|jpeg|gif|webp|ico|css|js)$/)) {
    event.respondWith(
      caches.open(STATIC_CACHE).then((cache) =>
        cache.match(event.request).then((cached) => {
          if (cached) return cached;
          return fetch(event.request).then((resp) => {
            if (resp.ok) cache.put(event.request, resp.clone());
            return resp;
          });
        })
      )
    );
    return;
  }

  // PHP pages: network-first, fallback to cache, then offline page
  if (url.pathname.match(/\.php$/)) {
    event.respondWith(
      fetch(event.request)
        .then((resp) => {
          const clone = resp.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
          return resp;
        })
        .catch(() =>
          caches.match(event.request).then((cached) =>
            cached || caches.match('/natacha/offline.html')
          )
        )
    );
    return;
  }
});
