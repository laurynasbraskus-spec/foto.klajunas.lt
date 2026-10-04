const VERSION = 'foto-cache-v3';
const API_CACHE = `${VERSION}-api`;
const IMAGE_CACHE = `${VERSION}-images`;
const STATIC_CACHE = `${VERSION}-static`;

const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/favicon.ico',
  '/site.webmanifest',
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(cache => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(key => !key.startsWith(VERSION)).map(key => caches.delete(key))
    )).then(() => self.clients.claim())
  );
});

function isSameOriginGet(request) {
  return request.method === 'GET' && new URL(request.url).origin === location.origin;
}

function isGalleryApi(url) {
  return url.pathname.endsWith('/b2-gallery.php') || url.pathname.endsWith('/meta.php');
}

function isDerivativeImage(url) {
  return url.pathname.endsWith('/img.php');
}

function isStaticAsset(url) {
  return url.pathname === '/' ||
    url.pathname.endsWith('/index.html') ||
    url.pathname.endsWith('/favicon.ico') ||
    url.pathname.endsWith('/site.webmanifest');
}

// Privataus albumo atsakymai ir nuotraukos ateina su 'private' / 'no-store'.
// Ju i irenginio talpykla nededam: kitaip po atsijungimo (ar bendrame
// kompiuteryje) jie liktu matomi.
function storable(response) {
  const cc = (response.headers.get('Cache-Control') || '').toLowerCase();
  return response.ok && !cc.includes('no-store') && !cc.includes('private');
}

async function networkFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  try {
    const response = await fetch(request);
    if (storable(response)) {
      await cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await cache.match(request);
    if (cached) return cached;
    throw error;
  }
}

async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) return cached;

  const response = await fetch(request);
  if (storable(response)) {
    await cache.put(request, response.clone());
  }
  return response;
}

self.addEventListener('fetch', event => {
  const request = event.request;
  if (!isSameOriginGet(request)) return;

  const url = new URL(request.url);

  if (isDerivativeImage(url)) {
    event.respondWith(cacheFirst(request, IMAGE_CACHE));
    return;
  }

  if (isGalleryApi(url)) {
    event.respondWith(networkFirst(request, API_CACHE));
    return;
  }

  if (isStaticAsset(url)) {
    event.respondWith(networkFirst(request, STATIC_CACHE));
  }
});
