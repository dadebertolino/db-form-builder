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
 *
 * 2.22.0: polling di GET /updates mentre l'app è in primo piano, badge
 * sull'icona (Badging API), notifiche push attivabili per dispositivo.
 * 2.23.0: con WooCommerce, #/o (ordini del negozio) e ordini del cliente
 * nel dettaglio di una risposta.
 * 2.24.0: #/s/{id}/ordine, ordine "in attesa di pagamento" da una risposta.
 * 2.25.0: #/impostazioni (notifiche, tema, account), invito alle notifiche in
 * home una sola volta, identità del sito nella barra.
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
    var logoEl = document.getElementById('app-logo');
    var settingsLink = document.getElementById('app-settings');
    var offlineBanner = document.getElementById('app-offline');

    var nonce = '';
    var renderToken = 0;
    // Render di aggiornamento (polling): niente "Caricamento…" né spostamento del focus.
    var quiet = false;
    // Dati di GET /me (notifiche, intervallo di polling).
    var me = null;
    var meResolve;
    // Si risolve con i dati di /me (o null se la chiamata fallisce).
    var meReady = new Promise(function (resolve) { meResolve = resolve; });
    var latestId = 0;
    var pollTimer = null;
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

    // %d / %s sequenziali, oppure %1$s, %2$s posizionali.
    function sprintf(str) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return String(str).replace(/%(?:(\d+)\$)?[sd]/g, function (m, pos) {
            var v = pos ? args[parseInt(pos, 10) - 1] : args[i++];
            return v === undefined ? '' : String(v);
        });
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
    function ApiError(status, code, message, data) { this.status = status; this.code = code; this.message = message || ''; this.data = data || {}; }

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
                throw new ApiError(r.status, code, data && data.message, data && data.data);
            });
        });
    }

    // =========================================================
    // Viste
    // =========================================================

    function setView(opts, nodes) {
        titleEl.textContent = opts.barTitle || C.appName;
        backBtn.hidden = !opts.back;
        // Logo del sito solo in home: nelle altre viste c'è il pulsante Indietro.
        logoEl.hidden = !!opts.back;
        // In Impostazioni l'ingranaggio porterebbe alla pagina stessa.
        settingsLink.hidden = !!opts.isSettings;
        backBtn.dataset.target = opts.back || '';
        main.textContent = '';
        nodes.forEach(function (n) { if (n) main.appendChild(n); });
        main.setAttribute('aria-busy', 'false');
        document.title = (opts.docTitle ? opts.docTitle + ' — ' : '') + C.appName;
        // Focus sul titolo della vista (WCAG 2.4.3).
        var heading = main.querySelector('h1');
        if (heading && opts.focus !== false && !quiet) heading.focus();
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
            var pushCard = pushInvite();
            var ordersEntry = (me && me.woo && me.woo.orders) ? h('ul', { 'class': 'list spaced' },
                h('li', null, h('a', { href: '#/o' },
                    h('span', { 'class': 'main' }, h('span', { 'class': 'title', text: S.storeOrders }))
                ))
            ) : null;
            setView({}, [
                h('h1', { tabindex: '-1', text: S.allForms }),
                h('p', { 'class': 'subtitle', text: C.siteName }),
                forms.length ? all : null,
                forms.length ? h('ul', { 'class': 'list spaced' }, items) : h('div', { 'class': 'state' }, h('p', { text: S.noForms })),
                ordersEntry,
                pushCard
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

            // 2.23.0: ordini dello stesso cliente (solo con WooCommerce e permessi sugli ordini).
            meReady.then(function (info) {
                if (token !== renderToken || !info || !info.woo || !info.woo.orders) return;
                main.appendChild(customerOrdersSection(s, token));
            });

            // Aperta = letta (come nell'admin). Le archiviate restano archiviate.
            if (s.status === 'new') {
                setStatus('read').catch(function () { /* resta "nuova": si riprova alla prossima apertura */ });
            }
        });
    }

    // ---- WooCommerce (2.23.0) ----
    function orderItem(o) {
        var meta = formatDate(o.date) + ' · ' + o.total + (o.customer ? ' · ' + o.customer : '');
        return h('li', null,
            h('a', { href: o.edit_url, target: '_blank', rel: 'noopener', 'aria-label': sprintf(S.orderNumber, o.number) + ' — ' + S.openInWoo },
                h('span', { 'class': 'main' },
                    h('span', { 'class': 'title', text: sprintf(S.orderNumber, o.number) + (o.linked ? ' · ' + S.linkedOrder : '') }),
                    h('span', { 'class': 'meta', text: meta })
                ),
                h('span', { 'class': 'chip order-' + o.status, text: o.status_label })
            )
        );
    }

    function viewOrders(token) {
        var page = 1;
        var list = h('ul', { 'class': 'list' });
        var more = h('div', { 'class': 'more' });
        function load() {
            return api('orders', { params: { page: page, per_page: C.perPage } }).then(function (res) {
                if (token !== renderToken) return null;
                (res.data || []).forEach(function (o) { list.appendChild(orderItem(o)); });
                var pages = parseInt(res.headers.get('X-WP-TotalPages') || '1', 10);
                more.textContent = '';
                if (page < pages) {
                    more.appendChild(h('button', {
                        type: 'button', 'class': 'btn', text: S.loadMore,
                        onclick: function (e) {
                            e.currentTarget.disabled = true;
                            page++;
                            var first = list.children.length;
                            load().then(function () {
                                var a = list.children[first] && list.children[first].querySelector('a');
                                if (a) a.focus();
                            }).catch(function (err) { errorView(err); });
                        }
                    }));
                }
                return res.data || [];
            });
        }
        return load().then(function (rows) {
            if (rows === null) return;
            setView({ back: '#/', barTitle: S.storeOrders, docTitle: S.storeOrders }, [
                h('h1', { tabindex: '-1', text: S.storeOrders }),
                rows.length ? list : h('div', { 'class': 'state' }, h('p', { text: S.noOrders })),
                more
            ]);
        });
    }

    function customerOrdersSection(s, token) {
        var section = h('section', { 'class': 'orders-section', 'aria-labelledby': 'orders-title', 'aria-busy': 'true' },
            h('h2', { id: 'orders-title', text: S.customerOrders }),
            h('p', { 'class': 'muted', text: S.loading })
        );
        api('submissions/' + s.id + '/orders').then(function (res) {
            if (token !== renderToken) return;
            var d = res.data;
            section.removeChild(section.lastChild);
            if (!d.has_email) {
                section.appendChild(h('p', { 'class': 'muted', text: S.noEmail }));
            } else {
                if (d.customer) section.appendChild(h('p', { 'class': 'muted', text: sprintf(S.registered, d.customer.name, d.customer.orders_count) }));
                section.appendChild(d.orders.length
                    ? h('ul', { 'class': 'list' }, d.orders.map(orderItem))
                    : h('p', { 'class': 'muted', text: S.noCustomerOrders }));
            }
            if (me && me.woo && me.woo.create) {
                section.appendChild(h('div', { 'class': 'actions' },
                    h('a', { 'class': 'btn primary', href: '#/s/' + s.id + '/ordine', text: S.createOrder })
                ));
            }
            section.setAttribute('aria-busy', 'false');
        }).catch(function () {
            section.lastChild.textContent = S.error;
            section.setAttribute('aria-busy', 'false');
        });
        return section;
    }

    // ---- Ordine da una risposta (2.24.0) ----
    function field(id, label, value, attrs) {
        attrs = attrs || {};
        var input = h(attrs.tag || 'input', Object.assign({ id: id, name: id, 'class': 'input' }, attrs.input || {}));
        if (attrs.tag === 'select') {
            Object.keys(attrs.options || {}).forEach(function (k) {
                input.appendChild(h('option', { value: k, selected: k === value ? true : null, text: attrs.options[k] }));
            });
        } else if (attrs.tag === 'textarea') {
            input.value = value || '';
        } else {
            input.value = value || '';
        }
        var labelEl = h('label', { 'for': id }, label, attrs.required ? h('span', { 'class': 'req', text: ' (' + S.required + ')' }) : null);
        var hint = attrs.hint ? h('p', { 'class': 'hint', id: id + '-hint', text: attrs.hint }) : null;
        if (hint) input.setAttribute('aria-describedby', id + '-hint');
        if (attrs.required) input.required = true;
        return h('div', { 'class': 'field' }, labelEl, input, hint, h('p', { 'class': 'field-error', id: id + '-error', hidden: true }));
    }

    function viewCreateOrder(token, id) {
        return api('submissions/' + id + '/order-draft').then(function (res) {
            if (token !== renderToken) return;
            var d = res.data;
            var b = d.billing;
            var back = '#/s/' + id;
            var form = h('form', { 'class': 'order-form', novalidate: true });
            var status = h('p', { 'class': 'form-status', role: 'status' });
            var submit = h('button', { type: 'submit', 'class': 'btn primary', text: S.createOrder });

            form.appendChild(h('fieldset', null,
                h('legend', { text: S.billing }),
                h('p', { 'class': 'hint', text: S.billingHint }),
                h('div', { 'class': 'grid' },
                    field('first_name', S.firstName, b.first_name, { input: { autocomplete: 'off' } }),
                    field('last_name', S.lastName, b.last_name, { input: { autocomplete: 'off' } }),
                    field('company', S.company, b.company, { input: { autocomplete: 'off' } }),
                    field('email', S.email, b.email, { input: { type: 'email', autocomplete: 'off', inputmode: 'email' } }),
                    field('phone', S.phone, b.phone, { input: { type: 'tel', autocomplete: 'off' } }),
                    field('address_1', S.address1, b.address_1, { input: { autocomplete: 'off' } }),
                    field('address_2', S.address2, b.address_2, { input: { autocomplete: 'off' } }),
                    field('city', S.city, b.city, { input: { autocomplete: 'off' } }),
                    field('postcode', S.postcode, b.postcode, { input: { autocomplete: 'off', inputmode: 'numeric' } }),
                    field('state', S.state, b.state, { input: { autocomplete: 'off' } }),
                    field('country', S.country, b.country, { tag: 'select', options: d.countries })
                )
            ));
            form.appendChild(h('fieldset', null,
                h('legend', { text: S.orderLine }),
                field('description', S.description, d.description, { required: true, input: { maxlength: '200' } }),
                field('amount', sprintf(S.amount, d.currency_symbol), '', {
                    required: true,
                    input: { inputmode: 'decimal', autocomplete: 'off' },
                    hint: d.tax_enabled ? (d.prices_include_tax ? S.taxIncluded : S.taxExcluded) : null
                }),
                field('note', S.internalNote, '', { tag: 'textarea', input: { rows: '3', maxlength: '2000' } })
            ));
            var sendBox = h('input', { type: 'checkbox', id: 'send_invoice', checked: b.email ? true : null });
            form.appendChild(h('div', { 'class': 'check' }, sendBox, h('label', { 'for': 'send_invoice', text: S.sendInvoice })));
            form.appendChild(h('div', { 'class': 'actions' }, submit, h('a', { 'class': 'btn', href: back, text: S.backToSubmission })));
            form.appendChild(status);

            function showError(fieldId, message) {
                var input = form.querySelector('#' + fieldId);
                var err = form.querySelector('#' + fieldId + '-error');
                if (input && err) {
                    err.textContent = message;
                    err.hidden = false;
                    input.setAttribute('aria-invalid', 'true');
                    input.setAttribute('aria-describedby', (input.getAttribute('aria-describedby') || '').replace(fieldId + '-error', '').trim() + ' ' + fieldId + '-error');
                    input.focus();
                } else {
                    status.textContent = message;
                }
            }

            function clearErrors() {
                Array.prototype.forEach.call(form.querySelectorAll('.field-error'), function (e) { e.hidden = true; e.textContent = ''; });
                Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid]'), function (e) { e.removeAttribute('aria-invalid'); });
                status.textContent = '';
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                clearErrors();
                var val = function (k) { return form.querySelector('#' + k).value.trim(); };
                if (!val('description')) { showError('description', S.required); return; }
                if (!val('amount')) { showError('amount', S.required); return; }
                var billing = {};
                ['first_name', 'last_name', 'company', 'email', 'phone', 'address_1', 'address_2', 'city', 'postcode', 'state', 'country'].forEach(function (k) { billing[k] = val(k); });
                submit.disabled = true;
                status.textContent = S.creating;
                api('submissions/' + id + '/orders', { method: 'POST', body: {
                    billing: billing, description: val('description'), amount: val('amount'), note: val('note'), send_invoice: sendBox.checked
                } }).then(function (r) {
                    if (token !== renderToken) return;
                    orderResultView(r.data, id);
                }).catch(function (err) {
                    submit.disabled = false;
                    status.textContent = '';
                    if (err instanceof AuthError) { errorView(err); return; }
                    var target = err.data && err.data.field ? err.data.field : (err.data && err.data.params ? Object.keys(err.data.params)[0] : '');
                    showError(target === 'billing' ? 'email' : target, err.message || S.error);
                });
            });

            setView({ back: back, barTitle: S.createOrderTitle, docTitle: S.createOrderTitle }, [
                h('h1', { tabindex: '-1', text: S.createOrderTitle }),
                h('p', { 'class': 'subtitle', text: sprintf(S.submission, id) }),
                d.linked_orders && d.linked_orders.length ? h('p', { 'class': 'banner', role: 'note', text: S.alreadyLinked }) : null,
                form
            ]);
        });
    }

    function orderResultView(o, submissionId) {
        var info = h('div', { 'class': 'card result' });
        var sentLine = h('p', { role: 'status' });
        function renderSent(sent) {
            sentLine.textContent = sent ? sprintf(S.invoiceSent, o.email) : (o.email ? S.invoiceNotSent : '');
        }
        if (o.payment_url) {
            var linkInput = h('input', { id: 'payment-url', 'class': 'input', readonly: true, value: o.payment_url });
            info.appendChild(h('div', { 'class': 'field' }, h('label', { 'for': 'payment-url', text: S.paymentLink }), linkInput));
            var actions = h('div', { 'class': 'actions' });
            actions.appendChild(h('button', { type: 'button', 'class': 'btn', text: S.copy, onclick: function () {
                var done = function () { announce(S.copied); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(o.payment_url).then(done, function () { linkInput.select(); });
                } else {
                    linkInput.select();
                }
            } }));
            if (!o.invoice_sent && o.email) {
                actions.appendChild(h('button', { type: 'button', 'class': 'btn', text: S.sendInvoiceNow, onclick: function (e) {
                    var btn = e.currentTarget;
                    btn.disabled = true;
                    api('orders/' + o.id + '/send-invoice', { method: 'POST' }).then(function (r) {
                        renderSent(r.data.invoice_sent);
                        if (r.data.invoice_sent) btn.remove(); else btn.disabled = false;
                    }, function (err) { btn.disabled = false; announce(err.message || S.error); });
                } }));
            }
            info.appendChild(actions);
        }
        if (o.invoice_sent || o.email) renderSent(o.invoice_sent);
        info.appendChild(sentLine);
        var title = sprintf(S.orderCreated, o.number);
        setView({ back: '#/s/' + submissionId, barTitle: title, docTitle: title }, [
            h('h1', { tabindex: '-1', text: title }),
            h('p', { 'class': 'subtitle', text: o.total + ' · ' + (o.needs_payment ? S.pendingPayment : o.status_label) }),
            info,
            h('div', { 'class': 'actions' },
                h('a', { 'class': 'btn', href: o.edit_url, target: '_blank', rel: 'noopener', text: S.openInWoo }),
                h('a', { 'class': 'btn', href: '#/s/' + submissionId, text: S.backToSubmission })
            )
        ]);
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

    function render(silent) {
        var token = ++renderToken;
        var route = parseHash();
        var seg = route.seg;
        var run;
        quiet = silent === true;
        if (!quiet) loadingView();

        if (seg[0] === 'f' && /^\d+$/.test(seg[1] || '')) {
            var status = route.params.get('status') || '';
            if (['', 'new', 'read', 'archived'].indexOf(status) === -1) status = '';
            run = function () { return viewSubmissions(token, parseInt(seg[1], 10), status); };
        } else if (seg[0] === 's' && /^\d+$/.test(seg[1] || '') && seg[2] === 'ordine') {
            run = function () { return viewCreateOrder(token, parseInt(seg[1], 10)); };
        } else if (seg[0] === 'impostazioni') {
            run = function () { return viewSettings(token); };
        } else if (seg[0] === 'o') {
            run = function () { return viewOrders(token); };
        } else if (seg[0] === 's' && /^\d+$/.test(seg[1] || '')) {
            run = function () { return viewSubmission(token, parseInt(seg[1], 10)); };
        } else {
            run = function () { return viewForms(token); };
        }

        run().then(function () { quiet = false; }, function (err) {
            quiet = false;
            if (token !== renderToken) return;
            errorView(err, render);
        });
    }

    // =========================================================
    // Aggiornamenti (polling) e badge
    // =========================================================

    function setBadge(n) {
        if (!('setAppBadge' in navigator)) return;
        var p = n > 0 ? navigator.setAppBadge(n) : navigator.clearAppBadge();
        if (p && p.catch) p.catch(function () { /* non supportato in questo contesto */ });
    }

    function onNewSubmissions() {
        announce(S.newArrived);
        var seg = parseHash().seg;
        if (seg.length === 0) {
            render(true);
        } else if (seg[0] === 'f' && !document.getElementById('app-new')) {
            // Nella lista non si sposta il contenuto: un pulsante per aggiornare.
            var heading = main.querySelector('h1');
            var btn = h('button', { type: 'button', 'class': 'btn primary new-btn', id: 'app-new', text: S.showNew, onclick: function () { render(); } });
            if (heading) heading.insertAdjacentElement('afterend', btn);
        }
    }

    function poll() {
        if (document.visibilityState !== 'visible' || !navigator.onLine) return;
        api('updates').then(function (res) {
            var d = res.data || {};
            setBadge(d.new_total || 0);
            if (latestId && d.latest_id > latestId) onNewSubmissions();
            latestId = Math.max(latestId, d.latest_id || 0);
        }).catch(function () { /* si riprova al prossimo giro */ });
    }

    function startPolling() {
        if (pollTimer) return;
        var seconds = (me && me.notifications && me.notifications.poll_interval) || 15;
        poll();
        pollTimer = window.setInterval(poll, seconds * 1000);
    }

    // In background il polling si ferma da solo (poll() esce); al ritorno
    // in primo piano si controlla subito.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') poll();
    });

    // =========================================================
    // Notifiche push
    // =========================================================

    function urlB64ToUint8Array(b64) {
        var pad = '='.repeat((4 - b64.length % 4) % 4);
        var raw = window.atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function pushSupported() {
        return !!(C.swUrl && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window);
    }

    function isIos() {
        return /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    function currentSubscription() {
        if (!pushSupported()) return Promise.resolve(null);
        return navigator.serviceWorker.getRegistration(C.swScope || undefined).then(function (reg) {
            return reg ? reg.pushManager.getSubscription() : null;
        });
    }

    function subscribePush() {
        var ask = Notification.requestPermission();
        return Promise.resolve(ask).then(function (perm) {
            if (perm !== 'granted') throw new Error('denied');
            return navigator.serviceWorker.ready;
        }).then(function (reg) {
            return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8Array(me.notifications.vapid_key) });
        }).then(function (sub) {
            var j = sub.toJSON();
            return api('push/subscription', { method: 'POST', body: { endpoint: j.endpoint, keys: j.keys } });
        });
    }

    function unsubscribePush() {
        return currentSubscription().then(function (sub) {
            if (!sub) return null;
            return api('push/subscription', { method: 'DELETE', body: { endpoint: sub.endpoint } })
                .catch(function () { /* la sottoscrizione si annulla comunque sul dispositivo */ })
                .then(function () { return sub.unsubscribe(); });
        });
    }

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    }

    function pushDismissed() {
        try { return window.localStorage.getItem('dbfb-app-push-later') === '1'; } catch (e) { return false; }
    }

    // ---- Invito in home (2.25.0): una sola volta, poi solo in Impostazioni ----
    function pushInvite() {
        var box = h('section', { 'class': 'card invite', 'aria-labelledby': 'invite-title', hidden: true });
        meReady.then(function (info) {
            if (!info || !info.notifications || !info.notifications.push || !pushSupported()) return null;
            if (Notification.permission !== 'default' || pushDismissed()) return null;
            return currentSubscription().then(function (sub) {
                if (sub) return;
                var enable = h('button', { type: 'button', 'class': 'btn primary', text: S.pushEnable });
                var later = h('button', { type: 'button', 'class': 'btn', text: S.notNow });
                box.appendChild(h('h2', { id: 'invite-title', text: S.inviteTitle }));
                box.appendChild(h('p', { text: S.inviteText }));
                box.appendChild(h('p', { 'class': 'hint', text: S.permissionHint }));
                box.appendChild(h('div', { 'class': 'actions' }, enable, later));
                box.hidden = false;

                enable.addEventListener('click', function () {
                    enable.disabled = true;
                    subscribePush().then(function () {
                        box.textContent = '';
                        var done = h('p', { role: 'status', tabindex: '-1', text: S.inviteDone });
                        box.appendChild(done);
                        done.focus();
                    }, function (err) {
                        enable.disabled = false;
                        if (err instanceof AuthError) { errorView(err); return; }
                        announce(err && err.message === 'denied' ? S.pushDenied : S.pushError);
                    });
                });
                later.addEventListener('click', function () {
                    try { window.localStorage.setItem('dbfb-app-push-later', '1'); } catch (e) { /* si richiederà alla prossima apertura */ }
                    box.remove();
                    announce(S.inviteLater);
                    var heading = main.querySelector('h1');
                    if (heading) heading.focus();
                });
            });
        }).catch(function () { /* nessun invito */ });
        return box;
    }

    // ---- Notifiche in Impostazioni: stato, istruzioni passo passo, azioni ----
    function steps(list) {
        return h('ol', { 'class': 'howto' }, list.map(function (t) { return h('li', { text: t }); }));
    }

    function renderPushSettings(body) {
        body.textContent = '';
        function para(text, cls) { body.appendChild(h('p', { 'class': cls || '', text: text })); }
        function busy(btn, fn) {
            return function () {
                btn.disabled = true;
                fn().then(function () {
                    renderPushSettings(body);
                    var first = body.querySelector('button');
                    if (first) first.focus();
                }, function (err) {
                    btn.disabled = false;
                    if (err instanceof AuthError) { errorView(err); return; }
                    announce(err && err.message === 'denied' ? S.pushDenied : S.pushError);
                    renderPushSettings(body);
                });
            };
        }

        if (!me || !me.notifications || !me.notifications.push) {
            para(S.pushSiteOff, 'muted');
            return;
        }
        if (!pushSupported()) {
            if (isIos() && !isStandalone()) {
                para(S.pushIosIntro);
                body.appendChild(steps(S.pushIosSteps));
            } else {
                para(S.pushUnsupported, 'muted');
            }
            return;
        }
        if (Notification.permission === 'denied') {
            para(S.pushBlocked);
            var how = isIos() ? S.unblockIos : (/Android/i.test(navigator.userAgent) ? (isStandalone() ? S.unblockAndroidApp : S.unblockAndroid) : S.unblockDesktop);
            body.appendChild(steps(how));
            body.appendChild(h('div', { 'class': 'actions' }, h('button', {
                type: 'button', 'class': 'btn', text: S.recheck,
                onclick: function () { renderPushSettings(body); announce(Notification.permission === 'denied' ? S.stillBlocked : S.pushOff); }
            })));
            return;
        }
        currentSubscription().then(function (sub) {
            var actions = h('div', { 'class': 'actions' });
            if (sub) {
                body.appendChild(h('p', { 'class': 'status-on' }, h('span', { 'class': 'dot', 'aria-hidden': 'true' }), S.pushOn));
                var test = h('button', { type: 'button', 'class': 'btn', text: S.pushTest });
                test.addEventListener('click', function () {
                    test.disabled = true;
                    api('push/test', { method: 'POST' }).then(function (r) {
                        announce(r.data.sent > 0 ? S.pushTestSent : S.pushTestFailed);
                    }, function () { announce(S.pushTestFailed); }).then(function () { test.disabled = false; });
                });
                var off = h('button', { type: 'button', 'class': 'btn', text: S.pushDisable });
                off.addEventListener('click', busy(off, unsubscribePush));
                actions.appendChild(test);
                actions.appendChild(off);
            } else {
                para(S.pushOff);
                para(S.permissionHint, 'hint');
                var on = h('button', { type: 'button', 'class': 'btn primary', text: S.pushEnable });
                on.addEventListener('click', busy(on, subscribePush));
                actions.appendChild(on);
            }
            body.appendChild(actions);
        }).catch(function () { para(S.pushUnsupported, 'muted'); });
    }

    // ---- Vista Impostazioni ----
    function viewSettings(token) {
        return meReady.then(function (info) {
            if (token !== renderToken) return;
            var pushBody = h('div');
            var section = function (id, title) {
                var children = Array.prototype.slice.call(arguments, 2);
                return h('section', { 'class': 'settings-section', 'aria-labelledby': id }, h('h2', { id: id, text: title }), children);
            };

            var current = storedTheme();
            var themeSet = h('fieldset', { 'class': 'choices' }, h('legend', { 'class': 'visually-hidden', text: S.theme }),
                [['', S.themeAuto], ['light', S.themeLight], ['dark', S.themeDark]].map(function (opt) {
                    var id = 'theme-' + (opt[0] || 'auto');
                    var input = h('input', { type: 'radio', name: 'theme', id: id, value: opt[0], checked: opt[0] === current ? true : null });
                    input.addEventListener('change', function () {
                        try {
                            if (opt[0]) window.localStorage.setItem('dbfb-app-theme', opt[0]);
                            else window.localStorage.removeItem('dbfb-app-theme');
                        } catch (e) { /* solo per questa sessione */ }
                        applyTheme(opt[0]);
                    });
                    return h('div', { 'class': 'choice' }, input, h('label', { 'for': id, text: opt[1] }));
                })
            );

            var logout = h('button', { type: 'button', 'class': 'btn', text: S.logout, onclick: function () { doLogout(); } });

            setView({ back: '#/', barTitle: S.settings, docTitle: S.settings, isSettings: true }, [
                h('h1', { tabindex: '-1', text: S.settings }),
                section('set-push', S.notifications, pushBody),
                section('set-theme', S.theme, themeSet),
                section('set-account', S.account,
                    info && info.name ? h('p', null, S.loggedAs + ' ', h('strong', { text: info.name })) : null,
                    h('div', { 'class': 'actions' }, logout)
                ),
                section('set-about', S.about,
                    h('dl', { 'class': 'about' },
                        h('dt', { text: S.appLabel }), h('dd', { text: C.appName }),
                        h('dt', { text: S.siteLabel }), h('dd', null, h('a', { href: C.siteUrl, rel: 'noopener', text: C.siteName })),
                        h('dt', { text: S.versionLabel }), h('dd', { text: C.version })
                    )
                )
            ]);
            renderPushSettings(pushBody);
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

    // '' = automatico (segue il sistema), 'light', 'dark'.
    function applyTheme(theme) {
        if (theme) document.documentElement.setAttribute('data-theme', theme);
        else document.documentElement.removeAttribute('data-theme');
    }
    applyTheme(storedTheme());

    function updateOnline() {
        offlineBanner.hidden = navigator.onLine;
    }
    window.addEventListener('online', function () {
        updateOnline();
        if (!me) loadMe();
        render();
    });
    window.addEventListener('offline', updateOnline);
    updateOnline();

    window.addEventListener('hashchange', render);

    // Utente e link di logout (con nonce di sessione: mai nella shell in cache).
    function loadMe() {
        api('me').then(function (res) {
            me = res.data;
            meResolve(me);
            // La home dipende da /me per la voce degli ordini: si aggiorna senza spostare il focus.
            if (me.woo && me.woo.orders && parseHash().seg.length === 0) render(true);
            startPolling();
        }).catch(function () { meResolve(null); /* gestito dalla vista; si riprova al ritorno online */ });
    }
    loadMe();

    // Logout: prima si revoca la sottoscrizione push di questo dispositivo.
    function doLogout() {
        var url = me && me.logout_url;
        var go = function () { setBadge(0); window.location.href = url || C.loginUrl; };
        unsubscribePush().then(go, go);
    }

    if (C.swUrl && 'serviceWorker' in navigator) {
        navigator.serviceWorker.register(C.swUrl, { scope: C.swScope }).catch(function () { /* l'app funziona anche senza */ });
    }

    render();
}());
