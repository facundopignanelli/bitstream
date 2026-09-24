// BitStream Service Worker - PWA Support
const CACHE_NAME = 'bitstream-v3.4.4';

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

const PLUGIN_PATH = (function() {
  if (typeof BITSTREAM_PLUGIN_URL !== 'undefined') {
    try {
      const p = new URL(BITSTREAM_PLUGIN_URL, self.location.origin).pathname;
      return p.endsWith('/') ? p : p + '/';
    } catch (e) {}
  }
  return SUB_PATH + 'wp-content/plugins/bitstream/';
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
  PLUGIN_PATH + 'assets/css/bitstream.css',
  PLUGIN_PATH + 'assets/js/bitstream.js',
  PLUGIN_PATH + 'manifest.json',
  PLUGIN_PATH + 'assets/images/logo_192.png',
  PLUGIN_PATH + 'assets/images/logo_512.png',
  PLUGIN_PATH + 'assets/images/bitstream.svg',
  PLUGIN_PATH + 'assets/images/logo_nightly_192.png',
  PLUGIN_PATH + 'assets/images/logo_nightly_512.png',
  PLUGIN_PATH + 'assets/images/bitstream-nightly.svg',
  PLUGIN_PATH + 'assets/images/new-bit-192.png',
  PLUGIN_PATH + 'assets/images/new-rebit-192.png'
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
        .catch(() => {
          return caches.match(FEED_PATH).then(cachedFeed => {
            if (cachedFeed) {
              return cachedFeed;
            }
            return new Response(
              '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BitStream</title><style>body{font-family:system-ui,-apple-system,sans-serif;background:#0d1117;color:#e2e8f0;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;padding:20px;text-align:center;box-sizing:border-box}h1{font-size:1.4rem;margin:0 0 0.5rem 0;color:#fff}p{color:#94a3b8;font-size:0.95rem;line-height:1.5;margin:0 0 1.5rem 0}button{background:#044389;color:#fff;border:none;padding:12px 28px;border-radius:12px;font-size:1rem;font-weight:600;cursor:pointer}</style></head><body><div><h1>Cannot Connect to BitStream</h1><p>Please check your connection or wait a moment for DNS propagation.</p><button onclick="window.location.reload()">Retry</button></div></body></html>',
              {
                status: 503,
                statusText: 'Service Unavailable',
                headers: { 'Content-Type': 'text/html; charset=utf-8' }
              }
            );
          });
        })
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
          // If request is CORS and cached response is opaque, do not return cached response.
          // Opaque responses are not allowed to be returned to CORS requests and cause network failures.
          if (cachedResponse && !(event.request.mode === 'cors' && cachedResponse.type === 'opaque')) {
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
        .catch(err => {
          console.warn('BitStream SW: Static asset fetch/cache failure:', err);
          // Return a fresh fallback response or a temporary error instead of letting promise reject
          return new Response('Network error occurred during service worker fetch.', { status: 480, statusText: 'Service Worker Network Error' });
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
            return caches.match(FEED_PATH).then(cachedFeed => {
              if (cachedFeed) {
                return cachedFeed;
              }

              return new Response(
                '<!doctype html><html><head><meta charset="utf-8"><title>BitStream</title></head><body><p>BitStream is offline.</p></body></html>',
                {
                  status: 503,
                  statusText: 'Service Unavailable',
                  headers: { 'Content-Type': 'text/html; charset=utf-8' }
                }
              );
            });
          }

          return new Response('BitStream request failed.', {
            status: 503,
            statusText: 'Service Unavailable',
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
          });
        })
    );
  }
  // Let other requests pass through normally
});

// Handle share target POST requests by saving payload to IndexedDB and redirecting
async function handleShareTargetPost(request) {
  try {
    const reqUrl = request.url;
    const reqMethod = request.method;
    const contentType = request.headers.get('content-type') || '';
    const contentLength = request.headers.get('content-length') || '';

    console.log('BitStream SW: handleShareTargetPost processing share target POST', {
      url: reqUrl,
      method: reqMethod,
      contentType: contentType,
      contentLength: contentLength
    });

    let formData = null;
    let parseError = null;

    // Clone request before reading formData so original request remains untouched for network fallback
    try {
      const reqClone = request.clone();
      formData = await reqClone.formData();
    } catch (err) {
      parseError = err.message || String(err);
      console.warn('BitStream SW: reqClone.formData() failed, trying request directly:', err);
      try {
        formData = await request.formData();
      } catch (directErr) {
        parseError = directErr.message || String(directErr);
      }
    }
    
    // Extract text/media values
    const title = (formData && typeof formData.get === 'function' ? formData.get('title') : '') || '';
    const text = (formData && typeof formData.get === 'function' ? formData.get('text') : '') || '';
    const url = (formData && typeof formData.get === 'function' ? formData.get('url') : '') || '';
    
    // Collect all candidate files from any field
    const fileCandidates = [];
    const pushCandidate = (item) => {
      if (!item) return;
      // Plain text strings are not files
      if (typeof item === 'string') return;
      // Blobs, Files, or file-like objects in Chromium
      if (typeof item === 'object') {
        if (!fileCandidates.includes(item)) {
          fileCandidates.push(item);
        }
      }
    };

    const debugInfo = {
      url: reqUrl,
      method: reqMethod,
      contentType: contentType,
      contentLength: contentLength,
      parseError: parseError,
      entryCount: 0,
      keys: [],
      fieldSummaries: []
    };

    if (formData && typeof formData.entries === 'function') {
      try {
        for (const [key, val] of formData.entries()) {
          debugInfo.entryCount++;
          debugInfo.keys.push(key);
          const isStr = typeof val === 'string';
          debugInfo.fieldSummaries.push({
            key: key,
            isString: isStr,
            type: typeof val,
            valType: val ? val.type : undefined,
            valName: val ? val.name : undefined,
            valSize: val ? val.size : undefined
          });

          if (!isStr) {
            pushCandidate(val);
          }
        }
      } catch (entriesErr) {
        debugInfo.entriesError = entriesErr.message || String(entriesErr);
      }

      const fieldNames = ['media', 'media[]', 'files', 'files[]', 'file', 'video', 'videos', 'image', 'images', 'attachment', 'attachments'];
      for (const name of fieldNames) {
        try {
          const list = formData.getAll(name);
          if (Array.isArray(list)) {
            list.forEach(pushCandidate);
          }
        } catch (_) {}
      }
    }

    // NETWORK FALLBACK:
    // If local Service Worker formData has 0 files, forward the untouched POST request directly to the server.
    // The server's BitStream_PWA_Manager::handle_media_share() processes $_FILES, uploads the media attachment to WordPress,
    // and redirects to the composer feed with media_ids.
    if (fileCandidates.length === 0) {
      console.log('BitStream SW: No files found in local formData. Forwarding POST request to network server for server-side processing...', {
        contentType,
        contentLength,
        reqUrl
      });
      try {
        const netResponse = await fetch(request);
        console.log('BitStream SW: Network share response received:', netResponse.status, netResponse.url, netResponse.redirected);
        if (netResponse.redirected && netResponse.url) {
          return Response.redirect(netResponse.url, 303);
        }
        if (netResponse.status >= 300 && netResponse.status < 400) {
          const loc = netResponse.headers.get('Location');
          if (loc) return Response.redirect(loc, 303);
        }
        return netResponse;
      } catch (netErr) {
        console.warn('BitStream SW: Network share fallback failed (possibly offline):', netErr);
        debugInfo.netFallbackError = netErr.message || String(netErr);
      }
    }

    const mediaFiles = [];
    for (const file of fileCandidates) {
      let detachedBlob = null;
      try {
        if (typeof file.slice === 'function') {
          detachedBlob = (typeof file.size === 'number' && file.size > 0)
            ? file.slice(0, file.size, file.type || 'video/mp4')
            : file.slice();
        }
      } catch (_) {}

      const effectiveBlob = detachedBlob || file;

      mediaFiles.push({
        blob: effectiveBlob,
        file: file,
        name: file.name || ('shared-video-' + Date.now() + '.mp4'),
        type: file.type || (effectiveBlob && effectiveBlob.type) || 'video/mp4',
        size: typeof file.size === 'number' ? file.size : 0,
        lastModified: file.lastModified || Date.now()
      });
    }

    console.log('BitStream SW: Collected media files for share:', mediaFiles.length, debugInfo);
    
    const sharedId = 'share-' + Date.now() + '-' + Math.random().toString(36).slice(2, 9);
    
    // Save to IndexedDB with robust completion handling and immediate db.close()
    await new Promise((resolve, reject) => {
      try {
        const dbRequest = indexedDB.open('bitstream-pwa-share-db', 2);
        dbRequest.onupgradeneeded = (event) => {
          try {
            const db = event.target.result;
            if (!db.objectStoreNames.contains('shared-payloads')) {
              db.createObjectStore('shared-payloads');
            }
          } catch (upgradeErr) {
            reject(upgradeErr);
          }
        };
        dbRequest.onblocked = () => {
          console.warn('BitStream SW: DB open blocked, falling back to unversioned open');
          try {
            const fallbackReq = indexedDB.open('bitstream-pwa-share-db');
            fallbackReq.onsuccess = (ev) => doSave(ev.target.result);
            fallbackReq.onerror = (ev) => reject(ev.target.error || fallbackReq.error);
          } catch (fbErr) {
            reject(fbErr);
          }
        };
        dbRequest.onerror = (event) => reject(event.target.error || dbRequest.error);
        dbRequest.onsuccess = (event) => doSave(event.target.result);

        function doSave(db) {
          try {
            db.onversionchange = () => { try { db.close(); } catch (_) {} };
            if (!db.objectStoreNames.contains('shared-payloads')) {
              try { db.close(); } catch (_) {}
              reject(new Error('shared-payloads store missing'));
              return;
            }
            const transaction = db.transaction('shared-payloads', 'readwrite');
            const store = transaction.objectStore('shared-payloads');
            store.clear();
            
            const payload = {
              title: title,
              text: text,
              url: url,
              mediaFiles: mediaFiles,
              debugInfo: debugInfo,
              timestamp: Date.now()
            };
            
            try {
              store.put(payload, sharedId);
            } catch (putErr) {
              console.warn('BitStream SW: store.put with raw handles failed, trying blob-only payload', putErr);
              const safeMediaFiles = mediaFiles.map(mf => ({
                blob: mf.blob,
                name: mf.name,
                type: mf.type,
                size: mf.size,
                lastModified: mf.lastModified
              }));
              payload.mediaFiles = safeMediaFiles;
              store.put(payload, sharedId);
            }

            transaction.oncomplete = () => {
              try { db.close(); } catch (_) {}
              resolve();
            };
            transaction.onerror = (e) => {
              try { db.close(); } catch (_) {}
              reject(e.target.error || transaction.error);
            };
            transaction.onabort = (e) => {
              try { db.close(); } catch (_) {}
              reject(e.target.error || transaction.error);
            };
          } catch (txErr) {
            try { db.close(); } catch (_) {}
            reject(txErr);
          }
        }
      } catch (openErr) {
        reject(openErr);
      }
    });
    
    // Redirect directly to the feed page with share parameters
    const targetPath = (typeof FEED_PATH !== 'undefined' && FEED_PATH) ? FEED_PATH : '/bitstream/';
    const redirectUrl = new URL(targetPath, self.location.origin);
    redirectUrl.search = `?composer_tab=bit&share_target=1&shared_id=${sharedId}`;
    
    console.log('BitStream SW: Stored share payload, redirecting to:', redirectUrl.toString());
    return Response.redirect(redirectUrl.toString(), 303);
  } catch (error) {
    console.error('BitStream SW: Share target handling error:', error);
    // Redirect to composer with error indicator rather than crashing
    const targetPath = (typeof FEED_PATH !== 'undefined' && FEED_PATH) ? FEED_PATH : '/bitstream/';
    const redirectUrl = new URL(targetPath, self.location.origin);
    redirectUrl.search = `?composer_tab=bit&share_error=${encodeURIComponent(error.message || 'storage_failed')}`;
    return Response.redirect(redirectUrl.toString(), 303);
  }
}

// Push notification listener (payload-free style)
self.addEventListener('push', event => {
  const ajaxUrl = typeof BITSTREAM_AJAX_URL !== 'undefined' ? BITSTREAM_AJAX_URL : '/wp-admin/admin-ajax.php';
  
  const fallbackLogo = (typeof BITSTREAM_IS_NIGHTLY !== 'undefined' && BITSTREAM_IS_NIGHTLY)
    ? (PLUGIN_PATH + 'assets/images/logo_nightly_192.png')
    : (PLUGIN_PATH + 'assets/images/logo_192.png');
  const fallbackTitle = (typeof BITSTREAM_IS_NIGHTLY !== 'undefined' && BITSTREAM_IS_NIGHTLY)
    ? 'BitStream Nightly'
    : 'New BitStream Post';

  event.waitUntil(
    fetch(`${ajaxUrl}?action=bitstream_get_latest_notification`)
      .then(response => response.json())
      .then(data => {
        const title = data.title || fallbackTitle;
        const options = {
          body: data.body || 'A new bit has been posted!',
          icon: data.icon || fallbackLogo,
          badge: data.badge || fallbackLogo,
          image: data.image || undefined,
          data: {
            url: data.url || siteUrl
          }
        };
        return self.registration.showNotification(title, options);
      })
      .catch(err => {
        console.error('BitStream SW: Push fetch failed:', err);
        return self.registration.showNotification(fallbackTitle, {
          body: 'A new post is available on BitStream.',
          icon: fallbackLogo,
          badge: fallbackLogo,
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
