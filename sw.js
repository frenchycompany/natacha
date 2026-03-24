const CACHE_NAME = 'natacha-v1';
const STATIC_CACHE = 'natacha-static-v1';

const APP_SHELL = [
  '/natacha/dashboard.php',
  '/natacha/login.php',
  '/natacha/manifest.json',
  '/natacha/assets/icons/icon-192x192.svg',
  '/natacha/assets/icons/icon-512x512.svg'
];

const OFFLINE_PAGE = '/natacha/offline.html';

// Install: cache app shell and offline fallback
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll([...APP_SHELL, OFFLINE_PAGE]);
    })
  );
  self.skipWaiting();
});

// Activate: clean up old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys
          .filter((key) => key !== CACHE_NAME && key !== STATIC_CACHE)
          .map((key) => caches.delete(key))
      );
    })
  );
  self.clients.claim();
});

// Fetch strategy
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Skip non-GET requests
  if (event.request.method !== 'GET') return;

  // Static assets (fonts, icons, images): cache-first
  if (
    url.pathname.match(/\.(woff2?|ttf|otf|eot|svg|png|jpg|jpeg|gif|webp|ico)$/)
  ) {
    event.respondWith(
      caches.open(STATIC_CACHE).then((cache) => {
        return cache.match(event.request).then((cached) => {
          if (cached) return cached;
          return fetch(event.request).then((response) => {
            if (response.ok) {
              cache.put(event.request, response.clone());
            }
            return response;
          });
        });
      })
    );
    return;
  }

  // CSS/JS: cache-first with network update
  if (url.pathname.match(/\.(css|js)$/)) {
    event.respondWith(
      caches.open(STATIC_CACHE).then((cache) => {
        return cache.match(event.request).then((cached) => {
          const fetchPromise = fetch(event.request).then((response) => {
            if (response.ok) {
              cache.put(event.request, response.clone());
            }
            return response;
          });
          return cached || fetchPromise;
        });
      })
    );
    return;
  }

  // PHP pages: network-first with offline fallback
  if (url.pathname.match(/\.php$/)) {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, clone);
          });
          return response;
        })
        .catch(() => {
          return caches.match(event.request).then((cached) => {
            return cached || caches.match(OFFLINE_PAGE);
          });
        })
    );
    return;
  }
});
