const CACHE_NAME = 'natacha-v4';
const STATIC_CACHE = 'natacha-static-v4';

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

// Pages that must NEVER be cached (session/security-sensitive)
const NO_CACHE = [
  'coffre_fort.php', 'galerie.php', 'coffre_fort_viewer.php',
  'app_lock.php', 'login.php', 'logout'
];

// Fetch strategy
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Skip non-GET
  if (event.request.method !== 'GET') return;

  // Skip API/AJAX calls
  if (url.pathname.includes('/api/')) return;

  // Security-sensitive pages: ALWAYS network, never cache, no fallback
  if (NO_CACHE.some((p) => url.pathname.includes(p))) {
    event.respondWith(fetch(event.request));
    return;
  }

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

// ═══ PUSH NOTIFICATIONS ═══
self.addEventListener('push', (event) => {
  let data = { title: 'Natacha', body: 'Nouveau message', url: '/natacha/couple.php' };
  try {
    data = event.data.json();
  } catch (e) {
    data.body = event.data ? event.data.text() : data.body;
  }

  const options = {
    body: data.body,
    icon: data.icon || '/natacha/assets/icons/icon-192x192.png',
    badge: data.badge || '/natacha/assets/icons/icon-96x96.png',
    tag: data.tag || 'natacha-notification',
    renotify: true,
    vibrate: [200, 100, 200],
    data: { url: data.url || '/natacha/couple.php' }
  };

  event.waitUntil(
    self.registration.showNotification(data.title, options)
  );
});

// Click on notification → open the app
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/natacha/couple.php';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      for (const client of windowClients) {
        if (client.url.includes('/natacha/') && 'focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      return clients.openWindow(url);
    })
  );
});
