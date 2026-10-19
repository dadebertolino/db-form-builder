/**
 * DB Form Builder — service worker della web app (2.21.0).
 *
 * Servito da /dbfb-app/sw.js, che antepone self.DBFB_SW = {VERSION, SHELL, ASSETS}.
 *
 * In cache SOLO l'interfaccia:
 *  - asset statici versionati (CSS, JS, icone): cache-first;
 *  - la shell HTML, che non contiene dati personali: network-first, con la
 *    copia in cache usata solo offline.
 * Tutto il resto (API REST, admin-ajax, allegati, login) passa dalla rete
 * senza essere salvato: le risposte ai form non restano mai sul dispositivo.
 *
 * 2.22.0: mostra le notifiche push e al clic apre (o porta in primo piano)
 * l'app sulla vista indicata.
 */
/* global self, caches, fetch, Response, URL */
(function () {
    'use strict';

    var CFG = self.DBFB_SW;
    var CACHE = 'dbfb-app-' + CFG.VERSION;
    var SHELL = new URL(CFG.SHELL, self.location.href).href;
    var ASSETS = CFG.ASSETS.map(function (u) { return new URL(u, self.location.href).href; });

    self.addEventListener('install', function (event) {
        event.waitUntil(
            caches.open(CACHE)
                .then(function (cache) { return cache.addAll(ASSETS); })
                .then(function () { return self.skipWaiting(); })
        );
    });

    self.addEventListener('activate', function (event) {
        event.waitUntil(
            caches.keys().then(function (keys) {
                return Promise.all(keys.filter(function (k) {
                    return k.indexOf('dbfb-app-') === 0 && k !== CACHE;
                }).map(function (k) { return caches.delete(k); }));
            }).then(function () { return self.clients.claim(); })
        );
    });

    self.addEventListener('fetch', function (event) {
        var req = event.request;
        if (req.method !== 'GET') return;

        if (req.mode === 'navigate') {
            var url = new URL(req.url);
            url.hash = '';
            if (url.href !== SHELL) return;
            event.respondWith(
                fetch(req).then(function (res) {
                    // Solo la shell vera (non un redirect al login).
                    if (res.ok && !res.redirected && res.type === 'basic') {
                        var copy = res.clone();
                        caches.open(CACHE).then(function (cache) { cache.put(SHELL, copy); });
                    }
                    return res;
                }).catch(function () {
                    return caches.match(SHELL).then(function (cached) {
                        return cached || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
                    });
                })
            );
            return;
        }

        if (ASSETS.indexOf(req.url) !== -1) {
            event.respondWith(
                caches.match(req).then(function (cached) { return cached || fetch(req); })
            );
        }
        // Ogni altra richiesta: nessun respondWith, nessuna cache.
    });

    // ---- Notifiche push (2.22.0) ----
    // Il payload (cifrato dal server) contiene solo titolo, testo generico,
    // link e tag: nessun dato delle risposte.
    self.addEventListener('push', function (event) {
        var data = {};
        try { data = event.data ? event.data.json() : {}; } catch (e) { data = {}; }
        var url = new URL(data.url || SHELL, self.location.href);
        // Solo link all'app di questo sito.
        if (url.origin !== self.location.origin) url = new URL(SHELL);
        var options = {
            body: data.body || '',
            tag: data.tag || 'dbfb',
            renotify: true,
            icon: ASSETS.filter(function (u) { return u.indexOf('icon-192') !== -1; })[0],
            data: { url: url.href }
        };
        var badge = self.navigator && 'setAppBadge' in self.navigator ? self.navigator.setAppBadge().catch(function () {}) : Promise.resolve();
        event.waitUntil(Promise.all([self.registration.showNotification(data.title || 'Nuova risposta', options), badge]));
    });

    self.addEventListener('notificationclick', function (event) {
        event.notification.close();
        var target = (event.notification.data && event.notification.data.url) || SHELL;
        event.waitUntil(
            self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
                for (var i = 0; i < list.length; i++) {
                    var client = list[i];
                    if (client.url.indexOf(SHELL) === 0 && 'focus' in client) {
                        return client.focus().then(function (c) { return c && 'navigate' in c ? c.navigate(target) : c; });
                    }
                }
                return self.clients.openWindow(target);
            })
        );
    });
}());
