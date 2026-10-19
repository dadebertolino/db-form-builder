/**
 * DB Form Builder — web app delle risposte (2.21.0).
 *
 * JavaScript senza build e senza dipendenze. Router su hash:
 *   #/            elenco dei form
 *   #/f/{id}      risposte di un form (0 = tutti), ?status=new|read|archived
 *   #/s/{id}      dettaglio di una risposta
 *
 * I dati arrivano solo dalle API dbfb/v1 (cookie + nonce wp_rest) e non
 * vengono salvati sul dispositivo: in localStorage resta solo la scelta del
 * tema. Il DOM si costruisce con textContent, mai con innerHTML.
 */
(function () {
    'use strict';

    var configEl = document.getElementById('dbfb-app-config');
    if (!configEl) return;
    var C = JSON.parse(configEl.textContent);
    var S = C.strings;

    var main = document.getElementById('app-main');
    var live = document.getElementById('app-live');
    var backBtn = document.getElementById('app-back');
    var titleEl = document.getElementById('app-title');
    var themeBtn = document.getElementById('app-theme');
    var logoutLink = document.getElementById('app-logout');
    var userEl = document.getElementById('app-user');
    var offlineBanner = document.getElementById('app-offline');

    var nonce = '';
    var renderToken = 0;
    // Titoli dei form, per l'intestazione delle liste filtrate.
    var formTitles = {};

    // =========================================================
    // Utilità
    // =========================================================

    function h(tag, attrs) {
        var el = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                var v = attrs[k];
                if (v === null || v === undefined || v === false) return;
                if (k === 'text') el.textContent = v;
                else if (k === 'class') el.className = v;
                else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2), v);
                else el.setAttribute(k, v === true ? '' : v);
            });
        }
        for (var i = 2; i < arguments.length; i++) {
            var child = arguments[i];
            if (child === null || child === undefined || child === false) continue;
            if (Array.isArray(child)) {
                child.forEach(function (c) { if (c) el.appendChild(c); });
            } else {
                el.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
            }
        }
        return el;
    }

    function sprintf(str, n) {
        return String(str).replace('%d', String(n));
    }

    function announce(msg) {
        live.textContent = '';
        window.setTimeout(function () { live.textContent = msg; }, 50);
    }

    // submitted_at arriva come "Y-m-d H:i:s" (orologio del database, come
    // nell'admin): lo riformattiamo senza conversioni di fuso.
    function formatDate(value) {
        var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ''));
        return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : String(value || '');
    }

    function statusLabel(status) {
        return { 'new': S.statusNew, read: S.statusRead, archived: S.statusArchived }[status] || status;
    }

    function chip(status) {
        return h('span', { 'class': 'chip ' + status, text: statusLabel(status) });
    }

    function AuthError(status) { this.status = status; }
    function ApiError(status, code) { this.status = status; this.code = code; }

    // =========================================================
    // API
    // =========================================================

    function endpoint(path, params) {
        var u = new URL(C.restRoot, window.location.href);
        if (u.searchParams.has('rest_route')) {
            // Permalink semplici: ?rest_route=/dbfb/v1/...
            u.searchParams.set('rest_route', u.searchParams.get('rest_route').replace(/\/?$/, '/') + path);
        } else {
            u.pathname = u.pathname.replace(/\/?$/, '/') + path;
        }
        Object.keys(params || {}).forEach(function (k) {
            if (params[k] !== '' && params[k] !== null && params[k] !== undefined) u.searchParams.set(k, params[k]);
        });
        return u.toString();
    }

    function fetchNonce() {
        return fetch(C.nonceUrl, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
            return r.text().then(function (t) {
                t = (t || '').trim();
                if (!r.ok || !/^[a-f0-9]{6,}$/i.test(t)) throw new AuthError(401);
                nonce = t;
                return t;
            });
        });
    }

    function api(path, options, retried) {
        options = options || {};
        var ready = nonce ? Promise.resolve(nonce) : fetchNonce();
        return ready.then(function () {
            var headers = { 'X-WP-Nonce': nonce, 'Accept': 'application/json' };
            if (options.body) headers['Content-Type'] = 'application/json';
            return fetch(endpoint(path, options.params), {
                method: options.method || 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: headers,
                body: options.body ? JSON.stringify(options.body) : undefined
            });
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                if (r.ok) return { data: data, headers: r.headers };
                var code = data && data.code;
                // Nonce scaduto (sessione ancora valida): uno nuovo e si riprova una volta.
                if (code === 'rest_cookie_invalid_nonce' && !retried) {
                    nonce = '';
                    return api(path, options, true);
                }
                if (r.status === 401 || code === 'rest_cookie_invalid_nonce') throw new AuthError(401);
                if (r.status === 403) throw new AuthError(403);
                throw new ApiError(r.status, code);
            });
        });
    }

    // =========================================================
    // Viste
    // =========================================================

    function setView(opts, nodes) {
        titleEl.textContent = opts.barTitle || S.appTitle;
        backBtn.hidden = !opts.back;
        backBtn.dataset.target = opts.back || '';
        main.textContent = '';
        nodes.forEach(function (n) { if (n) main.appendChild(n); });
        main.setAttribute('aria-busy', 'false');
        document.title = (opts.docTitle ? opts.docTitle + ' — ' : '') + S.appTitle + ' — ' + C.siteName;
        // Focus sul titolo della vista (WCAG 2.4.3).
        var heading = main.querySelector('h1');
        if (heading && opts.focus !== false) heading.focus();
    }

    function loadingView() {
        main.setAttribute('aria-busy', 'true');
        main.textContent = '';
        main.appendChild(h('p', { 'class': 'muted', text: S.loading }));
    }

    function errorView(err, retry) {
        if (err instanceof AuthError && err.status === 401) {
            setView({}, [
                h('h1', { tabindex: '-1', text: S.sessionExpired }),
                h('div', { 'class': 'state' }, h('a', { 'class': 'btn primary', href: C.loginUrl, text: S.login }))
            ]);
            return;
        }
        var msg = S.error;
        if (err instanceof AuthError && err.status === 403) msg = S.forbidden;
        else if (err instanceof ApiError && err.status === 404) msg = S.notFound;
        else if (err instanceof ApiError && err.status === 429) msg = S.rateLimited;
        else if (!navigator.onLine) msg = S.offline;
        else if (err instanceof TypeError) msg = S.unreachable;
        var onHome = parseHash().seg.length === 0;
        setView({ back: onHome ? '' : '#/' }, [
            h('h1', { tabindex: '-1', 'class': 'error', text: msg }),
            retry ? h('div', { 'class': 'state' }, h('button', { type: 'button', 'class': 'btn', text: S.retry, onclick: retry })) : null
        ]);
    }

    // ---- Elenco dei form ----
    function viewForms(token) {
        return api('forms').then(function (res) {
            if (token !== renderToken) return;
            var forms = res.data || [];
            var totalNew = 0;
            var items = forms.map(function (f) {
                formTitles[f.id] = f.title;
                totalNew += f['new'];
                return h('li', { 'class': f['new'] ? 'is-new' : '' },
                    h('a', { href: '#/f/' + f.id + (f['new'] ? '?status=new' : '') },
                        h('span', { 'class': 'main' },
                            h('span', { 'class': 'title', text: f.title }),
                            h('span', { 'class': 'meta', text: S.total + ': ' + f.total })
                        ),
                        f['new'] ? h('span', { 'class': 'badge', text: sprintf(S.newCount, f['new']) }) : null
                    )
                );
            });
            var all = h('ul', { 'class': 'list spaced' },
                h('li', { 'class': totalNew ? 'is-new' : '' },
                    h('a', { href: '#/f/0' + (totalNew ? '?status=new' : '') },
                        h('span', { 'class': 'main' }, h('span', { 'class': 'title', text: S.allSubmissions })),
                        totalNew ? h('span', { 'class': 'badge', text: sprintf(S.newCount, totalNew) }) : null
                    )
                )
            );
            setView({}, [
                h('h1', { tabindex: '-1', text: S.allForms }),
                h('p', { 'class': 'subtitle', text: C.siteName }),
                forms.length ? all : null,
                forms.length ? h('ul', { 'class': 'list' }, items) : h('div', { 'class': 'state' }, h('p', { text: S.noForms }))
            ]);
        });
    }

    // ---- Risposte di un form ----
    function viewSubmissions(token, formId, status) {
        var page = 1;
        var list = h('ul', { 'class': 'list' });
        var more = h('div', { 'class': 'more' });
        var title = formId ? (formTitles[formId] || '') : S.allSubmissions;

        function tabs() {
            var defs = [['', S.tabAll], ['new', S.tabNew], ['read', S.tabRead], ['archived', S.tabArchived]];
            return h('nav', { 'class': 'tabs', 'aria-label': S.filterStatus }, defs.map(function (d) {
                return h('a', {
                    href: '#/f/' + formId + (d[0] ? '?status=' + d[0] : ''),
                    'aria-current': d[0] === status ? 'page' : null,
                    text: d[1]
                });
            }));
        }

        function item(s) {
            return h('li', { 'class': s.status === 'new' ? 'is-new' : '' },
                h('a', { href: '#/s/' + s.id },
                    h('span', { 'class': 'main' },
                        h('span', { 'class': 'title', text: s.preview || sprintf(S.submission, s.id) }),
                        h('span', { 'class': 'meta', text: formatDate(s.submitted_at) + (formId ? '' : ' · ' + s.form_title) })
                    ),
                    chip(s.status)
                )
            );
        }

        function load() {
            return api('submissions', { params: { form_id: formId, status: status, page: page, per_page: C.perPage } }).then(function (res) {
                if (token !== renderToken) return null;
                var rows = res.data || [];
                rows.forEach(function (s) {
                    if (s.form_id && !formTitles[s.form_id]) formTitles[s.form_id] = s.form_title;
                    list.appendChild(item(s));
                });
                if (!title && rows.length) title = rows[0].form_title;
                var pages = parseInt(res.headers.get('X-WP-TotalPages') || '1', 10);
                more.textContent = '';
                if (page < pages) {
                    more.appendChild(h('button', {
                        type: 'button', 'class': 'btn', text: S.loadMore,
                        onclick: function (e) {
                            var btn = e.currentTarget;
                            btn.disabled = true;
                            page++;
                            var first = list.children.length;
                            load().then(function () {
                                // Il focus passa alla prima risposta caricata.
                                var a = list.children[first] && list.children[first].querySelector('a');
                                if (a) a.focus();
                            }).catch(function (err) { errorView(err); });
                        }
                    }));
                }
                return rows;
            });
        }

        return load().then(function (rows) {
            if (rows === null) return;
            title = title || S.appTitle;
            setView({ back: '#/', barTitle: title, docTitle: title }, [
                h('h1', { tabindex: '-1', text: title }),
                tabs(),
                rows.length ? list : h('div', { 'class': 'state' }, h('p', { text: S.noSubmissions })),
                more
            ]);
        });
    }

    // ---- Dettaglio ----
    function viewSubmission(token, id) {
        return api('submissions/' + id).then(function (res) {
            if (token !== renderToken) return;
            var s = res.data;
            var back = '#/f/' + s.form_id;
            var statusHolder = h('span', null, chip(s.status));
            var actions = h('div', { 'class': 'actions' });

            function setStatus(next) {
                return api('submissions/' + s.id, { method: 'PATCH', body: { status: next } }).then(function (r) {
                    s.status = r.data.status;
                    statusHolder.textContent = '';
                    statusHolder.appendChild(chip(s.status));
                    renderActions();
                });
            }

            function actionButton(label, next) {
                return h('button', {
                    type: 'button', 'class': 'btn', text: label,
                    onclick: function (e) {
                        var btn = e.currentTarget;
                        btn.disabled = true;
                        setStatus(next).then(function () {
                            announce(S.statusChanged + ' ' + statusLabel(s.status));
                            var first = actions.querySelector('button');
                            if (first) first.focus();
                        }).catch(function (err) {
                            btn.disabled = false;
                            if (err instanceof AuthError) errorView(err); else announce(S.error);
                        });
                    }
                });
            }

            function renderActions() {
                actions.textContent = '';
                if (s.status === 'archived') {
                    actions.appendChild(actionButton(S.restore, 'read'));
                } else {
                    actions.appendChild(actionButton(S.archive, 'archived'));
                }
                if (s.status !== 'new') actions.appendChild(actionButton(S.markNew, 'new'));
                if (s.status === 'new') actions.appendChild(actionButton(S.markRead, 'read'));
            }

            var hasFiles = false;
            var fields = (s.fields || []).map(function (f) {
                var dd;
                if (f.type === 'file') {
                    hasFiles = hasFiles || (Array.isArray(f.value) && f.value.length > 0);
                    dd = h('dd', { 'class': 'files' }, (f.value || []).map(function (file) {
                        return file.url
                            ? h('div', null, h('a', { href: file.url, rel: 'noopener', download: file.name, text: '📎 ' + file.name }))
                            : h('div', { text: '📎 ' + file.name });
                    }));
                } else if (Array.isArray(f.value)) {
                    dd = h('dd', null, f.value.length ? h('ul', null, f.value.map(function (v) { return h('li', { text: v }); })) : h('span', { 'class': 'muted', text: S.empty }));
                } else {
                    dd = h('dd', f.value === '' ? { 'class': 'muted', text: S.empty } : { text: f.value });
                }
                return h('div', null, h('dt', { text: f.label || f.id }), dd);
            });

            var extra = [];
            if (s.gdpr_consent) {
                extra.push(h('div', null, h('dt', { text: S.consent }), h('dd', {
                    text: s.gdpr_consent.given ? S.consentGiven + ' · ' + formatDate(s.gdpr_consent.timestamp) : S.consentNone
                })));
            }
            (s.consents || []).forEach(function (c) {
                extra.push(h('div', null, h('dt', { text: S.optionalConsents + ': ' + c.key }), h('dd', {
                    text: c.text + ' — ' + (!c.given ? S.no : (c.revoked_at ? S.revoked + ' ' + formatDate(c.revoked_at) : S.yes))
                })));
            });
            var attribution = s.attribution || {};
            var attrKeys = Object.keys(attribution);
            if (attrKeys.length) {
                extra.push(h('div', null, h('dt', { text: S.attribution }), h('dd', {
                    text: attrKeys.map(function (k) { return k + ': ' + attribution[k]; }).join('\n')
                })));
            }

            renderActions();
            var title = sprintf(S.submission, s.id);
            setView({ back: back, barTitle: s.form_title, docTitle: title }, [
                h('h1', { tabindex: '-1', text: title }),
                h('p', { 'class': 'subtitle' }, s.form_title + ' · ' + formatDate(s.submitted_at) + ' · ', statusHolder),
                actions,
                h('div', { 'class': 'card' }, h('dl', { 'class': 'fields' }, fields.concat(extra))),
                hasFiles ? h('p', { 'class': 'note', text: S.linkExpires }) : null
            ]);

            // Aperta = letta (come nell'admin). Le archiviate restano archiviate.
            if (s.status === 'new') {
                setStatus('read').catch(function () { /* resta "nuova": si riprova alla prossima apertura */ });
            }
        });
    }

    // =========================================================
    // Router
    // =========================================================

    function parseHash() {
        var raw = (window.location.hash || '#/').slice(1);
        var parts = raw.split('?');
        var params = new URLSearchParams(parts[1] || '');
        var seg = parts[0].split('/').filter(Boolean);
        return { seg: seg, params: params };
    }

    function render() {
        var token = ++renderToken;
        var route = parseHash();
        var seg = route.seg;
        var run;
        loadingView();

        if (seg[0] === 'f' && /^\d+$/.test(seg[1] || '')) {
            var status = route.params.get('status') || '';
            if (['', 'new', 'read', 'archived'].indexOf(status) === -1) status = '';
            run = function () { return viewSubmissions(token, parseInt(seg[1], 10), status); };
        } else if (seg[0] === 's' && /^\d+$/.test(seg[1] || '')) {
            run = function () { return viewSubmission(token, parseInt(seg[1], 10)); };
        } else {
            run = function () { return viewForms(token); };
        }

        run().catch(function (err) {
            if (token !== renderToken) return;
            errorView(err, render);
        });
    }

    // =========================================================
    // Barra, tema, stato della rete
    // =========================================================

    backBtn.addEventListener('click', function () {
        window.location.hash = backBtn.dataset.target || '#/';
    });

    function storedTheme() {
        try { return window.localStorage.getItem('dbfb-app-theme') || ''; } catch (e) { return ''; }
    }

    function applyTheme(theme) {
        var dark = theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
        if (theme) document.documentElement.setAttribute('data-theme', theme);
        else document.documentElement.removeAttribute('data-theme');
        themeBtn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }

    themeBtn.addEventListener('click', function () {
        var next = themeBtn.getAttribute('aria-pressed') === 'true' ? 'light' : 'dark';
        try { window.localStorage.setItem('dbfb-app-theme', next); } catch (e) { /* solo per questa sessione */ }
        applyTheme(next);
    });
    applyTheme(storedTheme());

    function updateOnline() {
        offlineBanner.hidden = navigator.onLine;
    }
    window.addEventListener('online', function () {
        updateOnline();
        if (logoutLink.hidden) loadMe();
        render();
    });
    window.addEventListener('offline', updateOnline);
    updateOnline();

    window.addEventListener('hashchange', render);

    // Utente e link di logout (con nonce di sessione: mai nella shell in cache).
    function loadMe() {
        api('me').then(function (res) {
            userEl.textContent = res.data.name || '';
            if (res.data.logout_url) {
                logoutLink.href = res.data.logout_url;
                logoutLink.hidden = false;
            }
        }).catch(function () { /* gestito dalla vista; si riprova al ritorno online */ });
    }
    loadMe();

    if (C.swUrl && 'serviceWorker' in navigator) {
        navigator.serviceWorker.register(C.swUrl, { scope: C.swScope }).catch(function () { /* l'app funziona anche senza */ });
    }

    render();
}());
