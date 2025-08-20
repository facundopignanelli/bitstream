// BitStream Service Worker - Enhanced PWA Support
const CACHE_NAME = 'bitstream-v2.0.4';
const ASSETS_TO_CACHE = [
  '/?bitstream_quickpost=1',
  '/wp-content/plugins/bitstream/assets/css/bitstream.css',
  '/wp-content/plugins/bitstream/assets/js/bitstream.js'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(ASSETS_TO_CACHE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  // Only handle BitStream related requests within scope
  if (event.request.url.includes('/bitstream/') || 
      event.request.url.includes('bitstream_quick_post') || 
      event.request.url.includes('bitstream_quickpost') ||
      event.request.url.includes('/wp-content/plugins/bitstream/')) {
    event.respondWith(
      caches.match(event.request)
        .then(response => {
          if (response) {
            return response;
          }
          // Clone the request for fetch
          const fetchRequest = event.request.clone();
          return fetch(fetchRequest)
            .then(response => {
              // Only cache successful responses
              if (!response || response.status !== 200 || response.type !== 'basic') {
                return response;
              }
              // Clone the response for caching
              const responseToCache = response.clone();
              caches.open(CACHE_NAME)
                .then(cache => {
                  cache.put(event.request, responseToCache);
                });
              return response;
            });
        })
        .catch(() => {
          // Offline fallback only for BitStream navigation requests
          if (event.request.mode === 'navigate' && event.request.url.includes('/bitstream/')) {
            return caches.match('/bitstream/quickbit/');
          }
        })
    );
  }
  // For all other requests, let them pass through normally
});
