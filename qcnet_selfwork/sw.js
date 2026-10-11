// sw.js — v42, PART 3 "PWA install support". Deliberately minimal: this
// app's content (messages, presence, rooms) is all live and dynamic, so
// aggressively caching API responses would show stale/wrong data. This
// service worker exists mainly to satisfy the browser's installability
// requirement (a registered SW with a fetch handler); it does a
// pass-through network fetch for everything and only opportunistically
// caches the static app shell (gateway.html) for a faster cold start —
// never api.php/auth.php/etc., which always go straight to the network.
//
// v42: shell changed from welcome.html to gateway.html. The app used to be
// QuickChat-only, so welcome.html was correctly "the" landing page and both
// this file and manifest.json's start_url pointed at it. Now that a portal
// gateway sits in front of both the dashboard and QuickChat, anything still
// pointing at welcome.html (this shell cache, the manifest start_url, or a
// stale index.html) skips the gateway entirely — which is exactly the bug
// this version fixes. The cache name below was also bumped (v41 -> v42) so
// the activate handler purges the old welcome.html-keyed cache below.

const SHELL_CACHE = 'quickchat-glass-shell-v43';
const SHELL_URL = './gateway.html';

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(SHELL_CACHE).then((cache) => cache.add(SHELL_URL)).catch(() => {})
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((names) =>
      Promise.all(names.filter((n) => n !== SHELL_CACHE).map((n) => caches.delete(n)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  const isShell = event.request.method === 'GET' && (url.pathname.endsWith('/index.php') || url.pathname.endsWith('/'));

  if (!isShell) return; // let every API/data/file request go straight to the network, untouched

  event.respondWith(
    fetch(event.request)
      .then((res) => {
        const copy = res.clone();
        caches.open(SHELL_CACHE).then((cache) => cache.put(SHELL_URL, copy)).catch(() => {});
        return res;
      })
      .catch(() => caches.match(SHELL_URL))
  );
});
