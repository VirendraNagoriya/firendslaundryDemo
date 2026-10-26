/* Laundry Friends service worker - offline shell + cached static assets */
var CACHE_NAME = 'lf-shell-v2';
var STATIC_ASSETS = [
    './',
    './index.php',
    './login.php',
    './manifest.webmanifest',
    './assets/css/app.css',
    './assets/js/app.js',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
    './assets/icons/icon-maskable-512.png'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.addAll(STATIC_ASSETS).catch(function () {
                return null;
            });
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (key) {
                return key === CACHE_NAME ? null : caches.delete(key);
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    var url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        var isPublicShell = /(\/|\/index\.php|\/login\.php)$/.test(url.pathname);
        event.respondWith(
            fetch(request).then(function (response) {
                if (isPublicShell && response && response.status === 200) {
                    var copy = response.clone();
                    caches.open(CACHE_NAME).then(function (cache) {
                        cache.put(request, copy);
                    });
                }
                return response;
            }).catch(function () {
                return caches.match(request).then(function (cached) {
                    return cached || caches.match('./index.php');
                });
            })
        );
        return;
    }

    event.respondWith(
        caches.match(request).then(function (cached) {
            if (cached) {
                return cached;
            }
            return fetch(request).then(function (response) {
                if (response && response.status === 200 && response.type === 'basic') {
                    var copy = response.clone();
                    caches.open(CACHE_NAME).then(function (cache) {
                        cache.put(request, copy);
                    });
                }
                return response;
            });
        })
    );
});
