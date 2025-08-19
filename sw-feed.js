// BitStream Feed Service Worker - PWA Support for Main Feed
const CACHE_NAME = 'bitstream-feed-v2.0.4';
const ASSETS_TO_CACHE = [
  '/bitstream/',
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
          // Only clean up our own caches, not the QuickPost ones
          if (cacheName.startsWith('bitstream-feed-') && cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  // Handle BitStream feed requests specifically - avoid QuickPost conflicts
  if (event.request.url.includes('/bitstream/') && 
      !event.request.url.includes('bitstream_quick_post') &&
      !event.request.url.includes('bitstream_quickpost') &&
      (event.request.url.match(/\/bitstream\/?/) || 
       event.request.url.includes('/wp-content/plugins/bitstream/') ||
       event.request.url.includes('/wp-admin/admin-ajax.php'))) {
    
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
                  // Cache feed pages and assets
                  if (event.request.url.includes('/bitstream/') || 
                      event.request.url.includes('/wp-content/plugins/bitstream/')) {
                    cache.put(event.request, responseToCache);
                  }
                });
              return response;
            });
        })
        .catch(() => {
          // Offline fallback for feed navigation
          if (event.request.mode === 'navigate' && 
              event.request.url.includes('/bitstream/') && 
              !event.request.url.includes('/quickbit/')) {
            return caches.match('/bitstream/');
          }
        })
    );
  }
  // Let other requests pass through normally
});
