// BitStream Service Worker - PWA Support
const CACHE_NAME = 'bitstream-v3.3.0';

const siteUrl = typeof BITSTREAM_SITE_URL !== 'undefined' ? BITSTREAM_SITE_URL : '/bitstream/';
const FEED_PATH = (function() {
  try {
    return new URL(siteUrl, self.location.origin).pathname;
  } catch (e) {
    return '/bitstream/';
  }
})();

const SUB_PATH = (function() {
  try {
    const path = new URL(siteUrl, self.location.origin).pathname;
    const idx = path.lastIndexOf('bitstream/');
    if (idx !== -1) {
      return path.substring(0, idx);
    }
    return '/';
  } catch (e) {
    return '/';
  }
})();

// Helper to check if request is for the main feed page
function isFeedPage(urlStr) {
  try {
    const requestUrl = new URL(urlStr);
    const feedUrl = new URL(siteUrl, self.location.origin);
    const reqPath = requestUrl.pathname.replace(/\/$/, '');
    const feedPath = feedUrl.pathname.replace(/\/$/, '');
    return reqPath === feedPath || reqPath === feedPath + '/index.php';
  } catch (e) {
    return false;
  }
}

const ASSETS_TO_CACHE = [
  FEED_PATH,
  FEED_PATH + 'new-bit/',
  FEED_PATH + 'new-rebit/',
  SUB_PATH + 'wp-content/plugins/bitstream/assets/css/bitstream.css',
  SUB_PATH + 'wp-content/plugins/bitstream/assets/js/bitstream.js',
  SUB_PATH + 'wp-content/plugins/bitstream/manifest.json',
  SUB_PATH + 'wp-content/plugins/bitstream/assets/images/logo_192.png',
  SUB_PATH + 'wp-content/plugins/bitstream/assets/images/logo_512.png',
  SUB_PATH + 'wp-content/plugins/bitstream/assets/images/bitstream.svg',
  SUB_PATH + 'wp-content/plugins/bitstream/assets/images/new-bit-192.png',
  SUB_PATH + 'wp-content/plugins/bitstream/assets/images/new-rebit-192.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        return Promise.all(
          ASSETS_TO_CACHE.map(url => {
            return cache.add(url).catch(err => {
              console.warn(`BitStream SW: Failed to cache ${url}:`, err);
            });
          })
        );
      })
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          // Clean up old cache versions
          if ((cacheName.startsWith('bitstream-') || cacheName.startsWith('bitstream-feed-')) && cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  // Log share target requests for debugging
  if (event.request.url.includes('/bitstream/new-bit/') || event.request.url.includes('/bitstream/new-rebit/')) {
    console.log('BitStream SW: Share target request detected:', event.request.method, event.request.url);
  }
  
  // Intercept POST requests to share target to show upload progress
  if (event.request.method === 'POST' && 
      (event.request.url.includes('/bitstream/new-bit/') || 
       event.request.url.endsWith('/bitstream/new-bit') || 
       event.request.url.includes('/bitstream/new-bit?'))) {
    console.log('BitStream SW: Intercepting share target POST to show progress');
    event.respondWith(handleShareTargetPost(event.request));
    return;
  }
  
  // Don't intercept other POST requests - let them pass through to the server
  if (event.request.method === 'POST') {
    console.log('BitStream SW: Allowing POST request to pass through:', event.request.url);
    return;
  }

  // Network-first for BitStream page navigations to avoid serving stale HTML with expired nonces
  if (event.request.mode === 'navigate' && isFeedPage(event.request.url)) {
    event.respondWith(
      fetch(event.request)
        .then(response => {
          if (response && response.status === 200 && response.type === 'basic') {
            const responseToCache = response.clone();
            caches.open(CACHE_NAME).then(cache => {
              cache.put(FEED_PATH, responseToCache);
            });
          }
          return response;
        })
        .catch(() => caches.match(FEED_PATH))
    );
    return;
  }
  
  // Cache fonts, FontAwesome CDN assets, and other common WordPress static resources (dashicons, etc.)
  const url = new URL(event.request.url);
  const isFont = event.request.destination === 'font' || url.pathname.match(/\.(?:woff2?|ttf|otf|eot)(?:\?|$)/i);
  const isFontAwesome = url.hostname.includes('fontawesome.com') || url.hostname.includes('use.fontawesome.com') || url.pathname.includes('font-awesome') || url.pathname.includes('fontawesome');
  const isStaticAsset = url.pathname.match(/\.(?:css|js)(?:\?|$)/i) && 
                        (url.pathname.includes('/wp-content/') || url.pathname.includes('/wp-includes/'));

  if (event.request.method === 'GET' && (isFont || isFontAwesome || isStaticAsset)) {
    event.respondWith(
      caches.match(event.request)
        .then(cachedResponse => {
          if (cachedResponse) {
            // For CSS/JS assets, update in background (Stale-While-Revalidate)
            if (!isFont) {
              fetch(event.request.clone())
                .then(networkResponse => {
                  if (networkResponse && (networkResponse.status === 200 || networkResponse.type === 'opaque' || networkResponse.type === 'cors')) {
                    caches.open(CACHE_NAME).then(cache => {
                      cache.put(event.request, networkResponse.clone());
                    });
                  }
                })
                .catch(() => {/* Ignore background fetch failures */});
            }
            return cachedResponse;
          }
          
          // Cache-First fallback to network
          return fetch(event.request.clone())
            .then(networkResponse => {
              if (networkResponse && (networkResponse.status === 200 || networkResponse.type === 'opaque' || networkResponse.type === 'cors')) {
                const responseToCache = networkResponse.clone();
                caches.open(CACHE_NAME).then(cache => {
                  cache.put(event.request, responseToCache);
                });
              }
              return networkResponse;
            });
        })
    );
    return;
  }
  
  const isPluginAsset = event.request.url.includes('/wp-content/plugins/bitstream/');
  const isAjax = event.request.url.includes('/wp-admin/admin-ajax.php');
  
  // Handle BitStream requests specifically - avoid other plugin conflicts
  if ((event.request.url.includes(FEED_PATH) || isPluginAsset) && 
      !event.request.url.includes('/pup-coupons/') &&
      (isFeedPage(event.request.url) || 
       (event.request.url.includes(FEED_PATH + 'new-bit/') ||
        event.request.url.includes(FEED_PATH + 'new-rebit/')) ||
       isPluginAsset ||
       isAjax)) {
    
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
                  // Cache plugin assets only (avoid caching dynamic HTML with nonces)
                  if (isPluginAsset) {
                    cache.put(event.request, responseToCache);
                  }
                });
              return response;
            });
        })
        .catch(() => {
          // Offline fallback for feed navigation
          if (event.request.mode === 'navigate' && isFeedPage(event.request.url)) {
            return caches.match(FEED_PATH);
          }
        })
    );
  }
  // Let other requests pass through normally
});

// Handle share target POST requests by saving payload to IndexedDB and redirecting
async function handleShareTargetPost(request) {
  try {
    const formData = await request.formData();
    
    // Extract text/media values
    const title = formData.get('title') || '';
    const text = formData.get('text') || '';
    const url = formData.get('url') || '';
    const mediaFiles = formData.getAll('media[]').concat(formData.getAll('media')).filter(val => val instanceof File || val instanceof Blob);
    
    const sharedId = 'share-' + Date.now() + '-' + Math.random().toString(36).slice(2, 9);
    
    // Save to IndexedDB
    await new Promise((resolve, reject) => {
      const dbRequest = indexedDB.open('bitstream-pwa-share-db', 1);
      dbRequest.onupgradeneeded = (event) => {
        const db = event.target.result;
        if (!db.objectStoreNames.contains('shared-payloads')) {
          db.createObjectStore('shared-payloads');
        }
      };
      dbRequest.onsuccess = (event) => {
        const db = event.target.result;
        const transaction = db.transaction('shared-payloads', 'readwrite');
        const store = transaction.objectStore('shared-payloads');
        
        // Clear old entries
        store.clear();
        
        const payload = {
          title: title,
          text: text,
          url: url,
          mediaFiles: mediaFiles,
          timestamp: Date.now()
        };
        
        const putRequest = store.put(payload, sharedId);
        putRequest.onsuccess = () => resolve();
        putRequest.onerror = (e) => reject(e.target.error);
      };
      dbRequest.onerror = (event) => reject(event.target.error);
    });
    
    // Redirect to the GET route with share parameters
    const redirectUrl = new URL(request.url);
    redirectUrl.search = `?share_target=1&shared_id=${sharedId}`;
    
    console.log('BitStream SW: Stored share payload, redirecting to:', redirectUrl.toString());
    return Response.redirect(redirectUrl.toString(), 303);
  } catch (error) {
    console.error('BitStream SW: Share target handling error, falling back to network fetch:', error);
    // Fall back to direct fetch of the POST request
    return fetch(request);
  }
}

// Push notification listener (payload-free style)
self.addEventListener('push', event => {
  const ajaxUrl = typeof BITSTREAM_AJAX_URL !== 'undefined' ? BITSTREAM_AJAX_URL : '/wp-admin/admin-ajax.php';
  
  event.waitUntil(
    fetch(`${ajaxUrl}?action=bitstream_get_latest_notification`)
      .then(response => response.json())
      .then(data => {
        const title = data.title || 'New BitStream Post';
        const options = {
          body: data.body || 'A new bit has been posted!',
          icon: data.icon || (SUB_PATH + 'wp-content/plugins/bitstream/assets/images/logo_192.png'),
          badge: data.badge || (SUB_PATH + 'wp-content/plugins/bitstream/assets/images/logo_192.png'),
          image: data.image || undefined,
          data: {
            url: data.url || siteUrl
          }
        };
        return self.registration.showNotification(title, options);
      })
      .catch(err => {
        console.error('BitStream SW: Push fetch failed:', err);
        return self.registration.showNotification('New BitStream Update', {
          body: 'A new post is available on BitStream.',
          icon: SUB_PATH + 'wp-content/plugins/bitstream/assets/images/logo_192.png',
          badge: SUB_PATH + 'wp-content/plugins/bitstream/assets/images/logo_192.png',
          data: {
            url: siteUrl
          }
        });
      })
  );
});

// Handle notification click to navigate/focus
self.addEventListener('notificationclick', event => {
  event.notification.close();
  let targetUrl = event.notification.data && event.notification.data.url ? event.notification.data.url : siteUrl;
  
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(windowClients => {
        for (let i = 0; i < windowClients.length; i++) {
          const client = windowClients[i];
          if (client.url.includes(FEED_PATH) && 'focus' in client) {
            if ('navigate' in client) {
              client.navigate(targetUrl);
            }
            return client.focus();
          }
        }
        if (self.clients.openWindow) {
          return self.clients.openWindow(targetUrl);
        }
      })
  );
});
