// WealthDash Service Worker — t369: PWA Offline Support + Push Notifications
// Version: v51 | 22 Apr 2026
'use strict';

const CACHE_VERSION   = 'v51';
const STATIC_CACHE    = `wealthdash-static-${CACHE_VERSION}`;
const DYNAMIC_CACHE   = `wealthdash-dynamic-${CACHE_VERSION}`;
const OFFLINE_URL     = '/wealthdash/offline.html';
const BASE            = '/wealthdash';

// ── Static assets to precache on install ─────────────────────────────────────
const PRECACHE_URLS = [
  `${BASE}/`,
  `${BASE}/offline.html`,
  `${BASE}/public/css/app.css`,
  `${BASE}/public/js/app.js`,
  `${BASE}/public/js/charts.js`,
  `${BASE}/public/manifest.json`,
  `${BASE}/public/img/icon-192.png`,
  `${BASE}/public/img/icon-512.png`,
];

// ── API paths → network-first (never cache-first) ────────────────────────────
const NETWORK_FIRST_PATTERNS = [
  /\/wealthdash\/api\//,
  /\/wealthdash\/templates\//,
];

// ── INSTALL: precache static shell ───────────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then(cache => {
      console.log('[SW] Precaching static shell');
      return Promise.allSettled(
        PRECACHE_URLS.map(url => cache.add(url).catch(err => {
          console.warn(`[SW] Failed to precache ${url}:`, err.message);
        }))
      );
    }).then(() => self.skipWaiting())
  );
});

// ── ACTIVATE: clean stale caches ─────────────────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(k => k.startsWith('wealthdash-') && k !== STATIC_CACHE && k !== DYNAMIC_CACHE)
          .map(k => {
            console.log('[SW] Deleting old cache:', k);
            return caches.delete(k);
          })
      )
    ).then(() => self.clients.claim())
  );
});

// ── FETCH: routing strategy ───────────────────────────────────────────────────
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  if (!url.pathname.startsWith('/wealthdash') && url.origin !== self.location.origin) return;
  if (request.method !== 'GET') return;

  const isNetworkFirst = NETWORK_FIRST_PATTERNS.some(p => p.test(url.pathname));

  if (isNetworkFirst) {
    event.respondWith(networkFirst(request));
  } else {
    event.respondWith(cacheFirst(request));
  }
});

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;
  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(STATIC_CACHE);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    if (request.mode === 'navigate') return caches.match(OFFLINE_URL);
    return new Response('Offline', { status: 503 });
  }
}

async function networkFirst(request) {
  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(DYNAMIC_CACHE);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    const cached = await caches.match(request);
    if (cached) return cached;
    if (request.mode === 'navigate') return caches.match(OFFLINE_URL);
    return new Response(
      JSON.stringify({ error: 'Offline', offline: true }),
      { status: 503, headers: { 'Content-Type': 'application/json' } }
    );
  }
}

// ── PUSH NOTIFICATIONS (t230) ─────────────────────────────────────────────────
self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; }
  catch { data = { title: 'WealthDash', body: event.data ? event.data.text() : '' }; }

  const title   = data.title || 'WealthDash';
  const options = {
    body    : data.body || 'You have a new notification',
    icon    : `${BASE}/public/img/icon-192.png`,
    badge   : `${BASE}/public/img/icon-192.png`,
    tag     : data.tag  || 'wealthdash-notif',
    renotify: !!data.tag,
    data    : { url: data.url || `${BASE}/` },
    actions : data.actions || [],
    vibrate : [200, 100, 200],
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const targetUrl = event.notification.data?.url || `${BASE}/`;
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
      for (const client of windowClients) {
        if (client.url === targetUrl && 'focus' in client) return client.focus();
      }
      return clients.openWindow(targetUrl);
    })
  );
});

// ── BACKGROUND SYNC ───────────────────────────────────────────────────────────
self.addEventListener('sync', event => {
  if (event.tag === 'wealthdash-sync') {
    event.waitUntil(syncOfflineQueue());
  }
});
async function syncOfflineQueue() {
  console.log('[SW] Background sync triggered — future: retry queued API calls');
}

// ── MESSAGE HANDLER ───────────────────────────────────────────────────────────
self.addEventListener('message', event => {
  const { type } = event.data || {};
  if (type === 'SKIP_WAITING') self.skipWaiting();
  if (type === 'CLEAR_CACHE') {
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k.startsWith('wealthdash-')).map(k => caches.delete(k))))
      .then(() => event.source?.postMessage({ type: 'CACHE_CLEARED' }));
  }
  if (type === 'GET_VERSION') event.source?.postMessage({ type: 'VERSION', version: CACHE_VERSION });
});
