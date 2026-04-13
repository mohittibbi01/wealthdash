// WealthDash Service Worker — t369: PWA Offline Support
// public/sw.js
// TODO: implement caching strategy, offline fallback, push notification handler

const CACHE_NAME = 'wealthdash-v1';
const OFFLINE_URL = '/wealthdash/offline.html';

self.addEventListener('install', event => {
  console.log('[SW] Install — t369 stub');
  // TODO: precache static assets
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  console.log('[SW] Activate');
  // TODO: clean old caches
});

self.addEventListener('fetch', event => {
  // TODO: cache-first strategy for static, network-first for API
});

self.addEventListener('push', event => {
  // TODO: t230 push notification handler
  const data = event.data ? event.data.json() : {};
  self.registration.showNotification(data.title || 'WealthDash', {
    body: data.body || '',
    icon: '/wealthdash/public/img/icon-192.png',
  });
});