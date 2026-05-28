// BitStream Service Worker - PWA Support
const CACHE_NAME = 'bitstream-v3.2.0';
const ASSETS_TO_CACHE = [
  '/bitstream/',
  '/bitstream/new-bit/',
  '/bitstream/new-rebit/',
  '/wp-content/plugins/bitstream/assets/css/bitstream.css',
  '/wp-content/plugins/bitstream/assets/js/bitstream.js',
  '/wp-content/plugins/bitstream/manifest.json',
  '/wp-content/plugins/bitstream/assets/images/logo_192.png',
  '/wp-content/plugins/bitstream/assets/images/logo_512.png',
  '/wp-content/plugins/bitstream/assets/images/bitstream.svg',
  '/wp-content/plugins/bitstream/assets/images/new-bit-192.png',
  '/wp-content/plugins/bitstream/assets/images/new-rebit-192.png'
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
  if (event.request.method === 'POST' && event.request.url.includes('/bitstream/new-bit/?share=1')) {
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
  if (event.request.mode === 'navigate' && event.request.url.includes('/bitstream/')) {
    event.respondWith(
      fetch(event.request)
        .then(response => {
          if (response && response.status === 200 && response.type === 'basic') {
            const responseToCache = response.clone();
            caches.open(CACHE_NAME).then(cache => {
              cache.put('/bitstream/', responseToCache);
            });
          }
          return response;
        })
        .catch(() => caches.match('/bitstream/'))
    );
    return;
  }
  
  // Handle BitStream requests specifically - avoid other plugin conflicts
  if ((event.request.url.includes('/bitstream/') || 
       event.request.url.includes('/wp-content/plugins/bitstream/')) && 
      !event.request.url.includes('/pup-coupons/') &&
      (event.request.url.match(/\/bitstream\/?/) || 
       (event.request.url.includes('/bitstream/new-bit/') ||
        event.request.url.includes('/bitstream/new-rebit/')) ||
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
                  // Cache plugin assets only (avoid caching dynamic HTML with nonces)
                  if (event.request.url.includes('/wp-content/plugins/bitstream/')) {
                    cache.put(event.request, responseToCache);
                  }
                });
              return response;
            });
        })
        .catch(() => {
          // Offline fallback for feed navigation
          if (event.request.mode === 'navigate' && 
              event.request.url.includes('/bitstream/')) {
            return caches.match('/bitstream/');
          }
        })
    );
  }
  // Let other requests pass through normally
});

// Handle share target POST requests with upload progress
async function handleShareTargetPost(request) {
  // Get the form data from the request
  const formData = await request.formData();
  
  // Open a client window to show progress
  const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  let progressWindow = null;
  
  // Try to focus an existing window or open a new one
  if (clients.length > 0) {
    progressWindow = clients[0];
    await progressWindow.focus();
  }
  
  // Send a message to the client to start showing progress
  if (progressWindow) {
    progressWindow.postMessage({
      type: 'UPLOAD_START',
      fileCount: formData.getAll('media[]').length
    });
  }
  
  // Perform the actual upload
  try {
    const response = await fetch(request.url, {
      method: 'POST',
      body: formData
    });
    
    // Follow the redirect
    if (response.redirected || response.status === 302) {
      const redirectUrl = response.url || response.headers.get('Location');
      
      if (progressWindow) {
        progressWindow.postMessage({
          type: 'UPLOAD_COMPLETE',
          redirectUrl: redirectUrl
        });
      }
      
      return Response.redirect(redirectUrl, 302);
    }
    
    return response;
  } catch (error) {
    console.error('BitStream SW: Upload error:', error);
    
    if (progressWindow) {
      progressWindow.postMessage({
        type: 'UPLOAD_ERROR',
        error: error.message
      });
    }
    
    return new Response('Upload failed', { status: 500 });
  }
}

// Push notification listener (payload-free style)
self.addEventListener('push', event => {
  const ajaxUrl = typeof BITSTREAM_AJAX_URL !== 'undefined' ? BITSTREAM_AJAX_URL : '/wp-admin/admin-ajax.php';
  const siteUrl = typeof BITSTREAM_SITE_URL !== 'undefined' ? BITSTREAM_SITE_URL : '/bitstream/';
  
  event.waitUntil(
    fetch(`${ajaxUrl}?action=bitstream_get_latest_notification`)
      .then(response => response.json())
      .then(data => {
        const title = data.title || 'New BitStream Post';
        const options = {
          body: data.body || 'A new bit has been posted!',
          icon: data.icon || '/wp-content/plugins/bitstream/assets/images/logo_192.png',
          badge: data.badge || '/wp-content/plugins/bitstream/assets/images/logo_192.png',
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
          icon: '/wp-content/plugins/bitstream/assets/images/logo_192.png',
          badge: '/wp-content/plugins/bitstream/assets/images/logo_192.png',
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
  const siteUrl = typeof BITSTREAM_SITE_URL !== 'undefined' ? BITSTREAM_SITE_URL : '/bitstream/';
  let targetUrl = event.notification.data && event.notification.data.url ? event.notification.data.url : siteUrl;
  
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(windowClients => {
        for (let i = 0; i < windowClients.length; i++) {
          const client = windowClients[i];
          if (client.url.includes('/bitstream/') && 'focus' in client) {
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
