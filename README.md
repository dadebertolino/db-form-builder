# DB Form Builder

Plugin WordPress per la creazione di form con drag & drop, logica condizionale, upload file, multi-step e webhook.

**Autore:** Davide Bertolino  
**Sito:** [https://www.davidebertolino.it](https://www.davidebertolino.it)  
**Email:** info@davidebertolino.it  
**Licenza:** GPL v2 or later

## Funzionalità

- **Form Builder drag & drop** — 15 tipi di elemento con riordino visuale
  - Input: Testo, Email, Textarea, Select, Checkbox, Radio, Telefono, Numero, Data, URL, Upload file, Campo nascosto (2.15.0+)
  - Contenuti: Testo/HTML statico, Immagine, Separatore
  - Struttura: Cambio pagina (multi-step)
- **Logica condizionale** — Mostra/nascondi campi in base alle risposte (8 operatori, AND/OR)
- **Precompilazione da URL (2.15.0+)** — I campi si compilano da un link (`?arrivo=2026-11-01`), compatibile con la cache di pagina
- **Provenienza delle campagne (2.17.0+)** — UTM letti dall'URL della pagina del form e salvati con la risposta, senza cookie né storage nel browser
- **Redirect ed eventi JS (2.15.0+)** — Dopo l'invio messaggio o redirect a una pagina del sito; eventi `dbfb:submitted` e `dbfb:prefilled` per il tracciamento delle conversioni
- **Upload file** — Drag & drop o click, estensioni configurabili, dimensione max per campo, file multipli, validazione client + server
- **Form multi-step** — Barra di progresso, navigazione avanti/indietro, validazione per step
- **Webhook** — POST JSON a URL esterno dopo ogni invio (compatibile Zapier, Make, n8n)
- **Protezione anti-spam** — Google reCAPTCHA v2/v3 (consent gate lato client, compatibile con la cache di pagina dalla 2.13.0) + Honeypot invisibile
- **Privacy by design (2.3.0+)**:
  - IP hashato SHA-256 di default (modalità configurabile: hash / nessuno / chiaro)
  - Retention automatica delle submission (cron giornaliero, default 365 giorni)
  - Right of erasure GDPR art. 17 (cancellazione singola/bulk/totale dalla UI)
  - Trust dei proxy header solo via filter esplicito (anti-spoofing)
  - Dichiarazione automatica dei trattamenti al DB SEO Manager
- **GDPR / Privacy** — Checkbox consenso obbligatorio con link alla Privacy Policy
- **Consensi facoltativi e revoca (2.16.0+)** — Caselle aggiuntive per marketing, newsletter e simili, mai obbligatorie; prova del consenso per voce e revoca dal link nell'email
- **Limite invii per IP** — Configurabile per form (max N invii in X minuti), funziona anche con IP hashato/non salvato
- **Email personalizzabili** — Conferma utente + notifica admin (più destinatari) con placeholder dinamici, inseribili con un clic dall'editor (2.12.0+)
- **Documenti nella conferma (2.14.0+)** — Uno o più file della Media Library (regolamento, brochure, modulo) inviati come link nell'email di conferma con il segnaposto `{documenti}`
- **Colori personalizzabili (2.12.0+)** — Sfondo, pulsanti e testo del form, globali o per singolo form, con contrasto WCAG AA garantito sui pulsanti e avviso sul testo
- **Gestione risposte** — Dettaglio modale, elimina singole/bulk/tutte, export CSV, allegati scaricabili solo dall'admin (2.13.0+), stato nuova/letta/archiviata con filtri e paginazione (2.19.0+)
- **Aggiornamenti automatici** — da GitHub Releases, direttamente in Plugin → Aggiornamenti (2.13.0+)
- **Duplica form** — Copia campi e impostazioni con un click
- **Esporta / importa form (2.18.0+)** — Un form come file `.json`, per passarlo da un sito di test alla produzione
- **Anteprima** — Visualizza il form nel builder prima di pubblicare
- **Template predefiniti** — 5 modelli pronti all'uso
- **Integrazione WordPress** — Shortcode, blocco Gutenberg, widget classico
- **Accessibilità WCAG 2.1 AA** — ARIA completo, focus management, contrasto, reduced motion, high contrast mode

## Privacy by design (2.3.0+)

Il Form Builder gestisce dati personali (le submission contengono PII per definizione: nome, email, messaggi, allegati). La 2.3.0 introduce un set di funzionalità che riducono i rischi GDPR:

### Modalità di salvataggio dell'IP

`Form Builder → Impostazioni → Privacy → Modalità salvataggio IP`. Tre opzioni:

- **`hashed` (default raccomandato):** SHA-256 con salt da `wp_salt('auth')`, irreversibile in pratica. Lo stesso visitatore produce sempre lo stesso hash → il rate limiting funziona correttamente. L'IP in chiaro non viene mai salvato a DB.
- **`none`:** nessun IP loggato. Il rate limiting continua a funzionare usando l'hash dell'IP come chiave del transient (mai persistito).
- **`full`:** IP in chiaro. Solo se hai un motivo legittimo e documentato. Sconsigliato.

Il cambio di modalità impatta solo le **nuove** submission. Quelle esistenti restano invariate.

### Retention automatica

`Form Builder → Impostazioni → Privacy → Retention submission`. Default 365 giorni. Un cron giornaliero (`dbfb_cleanup_submissions`) cancella tutto ciò che è più vecchio della soglia. Cap di sicurezza: 10000 righe per esecuzione (le rimanenti vengono cancellate il giorno dopo). Valore 0 = retention illimitata, sconsigliato per art. 5.1.e GDPR.

Pulsante **"Pulisci ora"** per esecuzione manuale immediata. Action hook `dbfb_cleanup_submissions_done($deleted, $days)` per integrazioni (notifica admin, log esterno, ecc.).

**Consensi facoltativi (2.16.0+):** quando la retention cancella una risposta, i suoi consensi facoltativi dati e non revocati **restano fino alla revoca** (prova del consenso per chi continua a usarlo, es. CRM o newsletter). Restano revocabili dal link nell'email e si trovano con le richieste DSAR. I consensi non dati seguono la risposta; un consenso revocato dopo la retention si conserva come prova della revoca per altri N giorni (la stessa soglia), poi viene cancellato.

### Right of erasure (art. 17)

Pagina submissions di un form: tre meccanismi:
1. **"Elimina"** singola riga (già nella 2.2.0)
2. **"Elimina selezionate"** dopo bulk-select (già nella 2.2.0)
3. **🗑️ "Cancella TUTTE (N)"** per cancellare in un click tutte le risposte del form (nuovo in 2.3.0). Conferma esplicita con il numero di righe.

In tutti e tre i casi, i file allegati vengono cancellati dal disco insieme alla riga DB (2.4.0+).

### Right of access + erasure via email (DSAR — art. 15 + 17)

A partire dalla 2.5.0, il Form Builder è integrato con la macchina nativa di WordPress per le DSAR. L'admin trova in `Strumenti → Esporta dati personali` e `Strumenti → Cancella dati personali` un'interfaccia che permette, data un'email:

- **Esporta dati**: WP genera uno ZIP scaricabile contenente tutte le submission che hanno quell'email come valore di un campo di tipo email. Include nome del form, data invio, valori dei campi, nomi degli allegati (i file stanno in un'area protetta: una copia si fornisce su richiesta), IP (rispetta storage mode, con indicazione "hash" o "in chiaro") e, dalla 2.13.0, la prova del consenso (testo, data, informativa, versione della policy).
- **Cancella dati**: WP cancella tutte le stesse submission insieme ai loro file allegati. Output: numero di submission rimosse + numero di file cancellati.

Il matching è esatto e case-insensitive: l'email deve corrispondere al valore di un campo `type=email` del form (non l'email che appare casualmente nel testo di un campo Messaggio).

Per form legacy con campi di tipo `text` usati come email, estendi i match via filter:

```php
add_filter('dbfb_dsar_email_field_ids', function($field_ids, $form_id, $form_fields) {
    foreach ($form_fields as $f) {
        if (($f['id'] ?? '') === 'mio_legacy_email_field') {
            $field_ids[] = 'mio_legacy_email_field';
        }
    }
    return $field_ids;
}, 10, 3);
```


### Trust dei proxy header

Per default `X-Forwarded-For`, `CF-Connecting-IP`, `X-Real-IP` sono ignorati per evitare IP spoofing. Per siti dietro proxy/CDN affidabili (Cloudflare, Varnish):

```php
add_filter('dbfb_trust_proxy_headers', '__return_true');
```

Stesso pattern di [DB Cookie Manager](https://github.com/dadebertolino/db-cookie-manager).

### Disinstallazione (2.3.1+)

WordPress non chiede conferma prima di chiamare `uninstall.php`, quindi il comportamento si decide in anticipo via `Form Builder → Impostazioni → Privacy → Cancellazione dati alla disinstallazione`.

**Soft (default, checkbox OFF):** alla disinstallazione vengono rimossi solo le option del plugin, i transient di rate limit, e lo scheduling del cron. Dalla 2.13.0 vengono tolti anche il cron delle webhook deliveries e i dispatch in coda. **Restano** la tabella delle submission, la tabella delle webhook deliveries, i form definiti (CPT `dbfb_form`) e gli allegati in `wp-content/uploads/dbfb/` (gli allegati non sono mai stati nella Media Library). Pensato per disinstallazioni temporanee — se reinstalli, ritrovi tutto. Dalla 2.16.0 restano anche la tabella `wp_dbfb_consents` e l'option `dbfb_consent_secret`: i link di revoca già inviati per email continuano a funzionare dopo una reinstallazione.

**Hard (checkbox ON):** alla disinstallazione viene fatto `DROP TABLE` di `wp_dbfb_submissions`, `wp_dbfb_webhook_deliveries` (2.13.0+) e `wp_dbfb_consents` (2.16.0+, insieme all'option `dbfb_consent_secret`), vengono cancellati tutti i post di tipo `dbfb_form` (con i relativi post meta), tutti i file allegati delle submission dal disco, le sottocartelle vuote in `wp-content/uploads/dbfb/`, i file di sicurezza (`.htaccess`, `index.php`) e le option del plugin. Operazione **irreversibile**. Quando attivi il checkbox, l'UI mostra un alert giallo che riepiloga cosa accadrà.

La disinstallazione funziona anche da WP-CLI (`wp plugin uninstall db-form-builder`): dalla 2.13.0 `uninstall.php` non richiede più un utente corrente.

## Integrazione ecosistema DB privacy

Il Form Builder fa parte dell'ecosistema privacy dei plugin DB (**DB Privacy Hub**, **DB Cookie Manager**, **DB SEO Manager**). Quando uno o più di questi plugin sono installati li sfrutta automaticamente, senza configurazione; da solo funziona comunque (ogni integrazione è opzionale).

| Capability | Stato | Dettaglio |
|---|---|---|
| Dati personali | Sì | Submission in `wp_dbfb_submissions`, allegati in `uploads/dbfb/`, payload in `wp_dbfb_webhook_deliveries` |
| Script di terze parti | Sì | Google reCAPTCHA (opzionale), caricato solo dopo il consenso |
| Consenso dell'utente | Sì | Checkbox GDPR per form, prova del consenso art. 7.1 |
| DSAR | Sì | Exporter + eraser (art. 15 e 17), marker `DBFB_DSAR_AVAILABLE` |
| Privacy Hub | Sì | `dbph_processing_register`, `dbph_user_data_exporters/erasers`, `dbph_consents_register` |

### Consent gate per reCAPTCHA (lato client dalla 2.13.0)

Il server emette sempre lo stesso markup, indipendente dal visitatore, quindi il form funziona anche con cache di pagina (WP Rocket, LiteSpeed Cache, Cloudflare APO). È `frontend.js` a decidere se caricare lo script Google `api.js`:

- **Nessun consent manager** (né DB Cookie Manager né WP Consent API), oppure gate disattivato via filter → lo script parte subito.
- **Consent manager presente** → lo script parte solo con il consenso alla categoria configurata (default `marketing`, filter `dbfb_recaptcha_category`). Il client usa `window.DBCM.hasConsent()` o `wp_has_consent()` e ascolta `dbcm:consent`, `dbcm:ready` e `wp_listen_for_consent_change`: quando il visitatore accetta, il widget compare **senza ricaricare la pagina**.
- **Senza consenso** al posto del widget compare un placeholder: "Per inviare il modulo accetta i cookie …, necessari alla verifica anti-spam", con il link alle preferenze se c'è il DB Cookie Manager.

**Scelta lato server:** se reCAPTCHA è attivo per il form il token è sempre richiesto. Un invio senza token viene rifiutato con il messaggio "Per inviare il modulo accetta i cookie necessari alla verifica anti-spam" (se il consent gate è attivo), coerente con il placeholder. Fino alla 2.12.0 gli invii senza token erano accettati con il solo rate limit: bastava che un bot omettesse il cookie di consenso per saltare la verifica. Chi preferisce non condizionare l'invio al consenso può impostare la categoria `functional` (reCAPTCHA sempre caricato) documentandolo nell'informativa, oppure disattivare reCAPTCHA sul form e affidarsi a honeypot + limite invii.

Compatibile con: **DB Cookie Manager 3.0.0+** (`dbcm:ready` usato se disponibile), qualsiasi plugin che esponga la WP Consent API (Cookiebot, Complianz, Real Cookie Banner).

Filter per casi avanzati:
```php
// Disabilitare il consent gate (admin scelta esplicita)
add_filter('dbfb_recaptcha_consent_required', '__return_false');

// Cambiare la categoria di consenso richiesta (default: 'marketing')
add_filter('dbfb_recaptcha_category', function() { return 'functional'; });
```

Quando **DB SEO Manager Hard Privacy** è attivo, reCAPTCHA è disattivato in modo incondizionato (coerente con il significato di "Hard Privacy").

### Dichiarazione trattamenti al registro privacy unificato

Quando **DB Privacy Hub 1.0.0+** è installato, il Form Builder dichiara automaticamente fino a 5 trattamenti nel pannello "Privacy → Registro trattamenti" e li propaga nella Privacy Policy generata. Per retrocompatibilità, lo stesso aggancio funziona anche con il vecchio **DB SEO Manager 1.2.x** (dalla 1.3.0 il SEO Manager non gestisce più il registro privacy: la responsabilità è migrata nell'Hub).

| ID | Quando appare |
|---|---|
| `dbfb_submissions` | almeno 1 form pubblicato |
| `dbfb_email_notifications` | almeno 1 form ha notifica admin o conferma utente |
| `dbfb_recaptcha` | reCAPTCHA configurato globalmente E almeno 1 form lo usa |
| `dbfb_webhooks` | almeno 1 form ha webhook attivo (con elenco host destinatari deduplicato) |
| `dbfb_marketing` | almeno 1 form pubblicato ha consensi facoltativi (2.16.0+): base giuridica 6.1.a, finalità configurate, conservazione fino alla revoca, host webhook dei form con consensi |

Le voci sono **dinamiche**: riflettono in tempo reale `ip_storage_mode`, `submissions_retention_days`, host webhook configurati. L'admin può copiare i testi nelle proprie informative privacy.

### DSAR routing via Privacy Hub

Quando il Privacy Hub è installato, le DSAR (richieste di accesso e cancellazione) del Form Builder vengono registrate via i filter `dbph_user_data_exporters` / `dbph_user_data_erasers`, non direttamente sui filter core di WordPress. Questo permette all'Hub di tracciare ogni richiesta nel suo "Storico DSAR" (tabella `wp_dbph_dsar_log`) con timestamp di richiesta, conferma e completamento.

Senza l'Hub, il Form Builder si registra direttamente sui filter core di WordPress (`wp_privacy_personal_data_exporters/erasers`), come faceva nella 2.8.0. Comportamento standalone identico, niente regressioni.

### Registro consensi unificato (sorgente `dbfb_form_consents`)

Dalla 2.11.0 ogni invio con checkbox GDPR attiva salva la prova del consenso (flag, testo mostrato, timestamp, URL informativa, ID versione della Privacy Policy dell'Hub). Tramite il filter `dbph_consents_register` il Form Builder registra la sorgente **`dbfb_form_consents`** nel "Registro consensi" del Privacy Hub, con callback di conteggio, consultazione ed export CSV (filtri per data e interessato; dalla 2.13.0 l'export rispetta il limite richiesto dall'Hub, fino a 50.000 righe). Solo le submission con `gdpr_consent_given = 1` compaiono nel registro; l'email dell'interessato è mascherata.

### Registro consensi: sorgente `dbfb_marketing_consents` (2.16.0+)

I consensi facoltativi compaiono nel "Registro consensi" del Privacy Hub come sorgente separata **`dbfb_marketing_consents`**: solo i consensi dati (`given = 1`), email mascherata, stato attivo/revocato in `extra` (con data e origine della revoca), filtri per data, interessato (con l'email completa la ricerca avviene sull'hash) e `consent_key`. La versione della Privacy Policy è quella in vigore al momento del consenso (`DBPH_Policy_Archive::get_current_version_id()`, `0` senza Hub).

Le richieste DSAR includono i consensi facoltativi: l'export li riporta in un gruppo "Consensi facoltativi" (chiave, testo, scelta, data, finalità, informativa, versione della policy, revoca), la cancellazione li elimina, compresi quelli rimasti dopo la retention della risposta.

### Marker `DBFB_DSAR_AVAILABLE`

Costante definita in `db-form-builder.php` (`define('DBFB_DSAR_AVAILABLE', true)`). Letta dal Privacy Hub per decidere se inserire la menzione "procedura DSAR semplificata via Strumenti → Esporta/Cancella dati personali" nella sezione "Diritti dell'interessato" della Privacy Policy generata.

## Web app delle risposte (2.21.0+)

Un'app installabile (PWA) per consultare le risposte da smartphone o PC, all'indirizzo `https://example.com/dbfb-app/`. Il link è anche in Form Builder → Web app.

- **Accesso:** login di WordPress. Possono entrare gli amministratori e gli utenti con il ruolo **Gestore form**, che dopo il login arrivano direttamente all'app. Dalla 2.24.0 il Gestore form non entra nel resto dell'amministrazione (viene riportato all'app) e non vede la barra di amministrazione sul sito; resta accessibile solo il suo profilo, per cambiare la password.
- **Viste:** elenco dei form con il numero di risposte nuove, risposte di un form con filtro per stato (nuove, lette, archiviate) e "Carica altre", dettaglio con campi, allegati, consenso e provenienza. Una risposta aperta diventa "letta"; dal dettaglio si può archiviare, ripristinare o rimettere tra le nuove.
- **Installazione:** Android (Chrome) → menu ⋮ → "Installa app"; iPhone e iPad (Safari) → Condividi → "Aggiungi alla schermata Home"; Chrome ed Edge su PC → icona nella barra degli indirizzi.
- **Tema chiaro e scuro** (automatico o scelto dall'icona nella barra), accessibilità WCAG 2.1 AA: navigazione da tastiera, focus sul titolo a ogni cambio di vista, annunci per gli screen reader, pulsanti da 44 px.
- **Nessuna build:** JavaScript e CSS senza dipendenze, in `assets/app/`.

**Privacy:** il service worker tiene in cache solo l'interfaccia (HTML, CSS, JavaScript, icone). La shell HTML non contiene dati personali né nonce: utente, form e risposte arrivano dalle API a ogni apertura e non vengono salvati sul dispositivo. Sul dispositivo resta solo la scelta del tema (localStorage). La shell non carica `wp_head()`, quindi nessuno script del tema o di altri plugin, e ha una Content Security Policy che consente script, stili e connessioni solo verso il sito.

### Aggiornamenti e notifiche (2.22.0+)

- **Aggiornamento automatico:** con l'app aperta e in primo piano, ogni 15 secondi (regolabile da 10 a 300) l'app chiede al sito solo l'id dell'ultima risposta e il numero di nuove (`GET /updates`). Nell'elenco dei form i conteggi si aggiornano da soli; in una lista compare il pulsante "Mostra le nuove risposte", così il contenuto non si sposta mentre lo leggi. In background il controllo si ferma.
- **Badge sull'icona** dell'app installata con il numero di risposte nuove, dove il sistema lo supporta (Badging API).
- **Notifiche push:** ogni utente le attiva dalla scheda "Notifiche" della web app, sul singolo dispositivo, e da lì può inviarsi una prova. Su iPhone e iPad servono iOS 16.4 o successivo e l'app aggiunta alla schermata Home. Uscendo dall'app la sottoscrizione del dispositivo viene revocata. Il Web Push è implementato nel plugin senza librerie esterne (VAPID + cifratura RFC 8291 con OpenSSL di PHP): niente Composer e niente estensioni gmp o bcmath.
- **ntfy e Telegram** come alternativa (Form Builder → Web app → Notifiche): utili dove il push non arriva, per esempio iOS senza app installata. Pulsante "Invia una notifica di prova".
- **Contenuto minimo:** "Nuova risposta", il titolo del form (disattivabile) e il link all'app. Mai i dati della risposta. Il payload push è cifrato per il singolo dispositivo; i push service ammessi sono solo quelli dei browser (Google, Mozilla, Apple, Microsoft; filter `dbfb_push_allowed_hosts`).
- L'invio parte da un evento cron avviato subito dopo il salvataggio, così chi compila il form non aspetta.
- I canali attivi sono dichiarati nel registro dei trattamenti del DB Privacy Hub (voce "Notifiche al personale sulle nuove risposte").

**Cache di pagina:** `/dbfb-app/` invia header `no-store` e definisce `DONOTCACHEPAGE` (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache). Con una CDN o una cache del server configurata a parte, escludi il percorso `/dbfb-app/`.

**Permalink:** con la struttura "Semplice" l'app funziona su `?dbfb_app=shell`, ma non si installa né parte offline (il service worker non viene registrato).

## WooCommerce (2.23.0+)

Con WooCommerce attivo la web app collega le risposte ai clienti del negozio. Il modulo (`inc/woo/`) si carica solo se WooCommerce è attivo ed è compatibile con l'archiviazione ordini ad alte prestazioni (HPOS) e con quella classica.

- **Cliente di una risposta:** l'email della risposta (il primo campo email valido, come per le richieste GDPR) confrontata con l'email di fatturazione degli ordini e con l'account del cliente registrato.
- **Nel dettaglio di una risposta:** "Ordini di questo cliente", con numero, data, totale e stato; ogni ordine si apre in WooCommerce.
- **"Ordini del negozio"** in home: gli ultimi ordini, con "Carica altre".
- **Permessi:** oltre all'accesso alle risposte serve la capability degli ordini di WooCommerce (`edit_shop_orders`, che hanno amministratore e Shop manager). Il ruolo "Gestore form" non vede gli ordini; per darglieli: `add_filter('dbfb_orders_capability', fn() => 'dbfb_view_submissions');` oppure aggiungi `edit_shop_orders` al ruolo.

### Ordine da una risposta (2.24.0+)

Pensato per i preventivi: dal dettaglio di una risposta, **"Crea ordine"**.

1. Il modulo arriva precompilato con i dati di fatturazione ricavati dalla risposta: email dell'interessato, telefono (campo "Telefono" o etichetta telefono/cellulare), nome e cognome (anche da un unico campo "Nome e cognome"), azienda/ragione sociale, indirizzo, città/comune, CAP, provincia (convertita in sigla) e paese del negozio. Si controllano e correggono prima di creare.
2. Una **riga personalizzata** (di default "Preventivo #ID — titolo del form") con l'**importo** inserito a mano: IVA inclusa o esclusa secondo l'impostazione del negozio, con le aliquote standard della sede. Accetta "1.250,50" e "1250.50".
3. L'ordine nasce **"In attesa di pagamento"**, collegato al cliente registrato se l'email corrisponde, con una nota che indica la risposta di origine e chi l'ha creato. Totale e righe restano modificabili in WooCommerce.
4. **Link di pagamento:** l'app lo mostra con il pulsante "Copia" e, se richiesto, WooCommerce invia al cliente l'email "Dettagli ordine" con il link. Se l'email non parte, l'app lo dice e si può riprovare.

Collegamento nei due sensi: meta dell'ordine `_dbfb_submission_id` e tabella `wp_dbfb_links`. Il collegamento si cancella con la risposta (eliminazione, retention, richiesta GDPR); l'ordine resta, con le regole di conservazione di WooCommerce. Gli ordini collegati compaiono nell'export GDPR della risposta. Serve `edit_shop_orders` (filter `dbfb_create_order_capability`).

## API REST (2.20.0+)

Namespace `dbfb/v1`, per la web app delle risposte e per client esterni.

| Metodo | Rotta | Uso |
|---|---|---|
| `GET` | `/wp-json/dbfb/v1/forms` | Form con totale e numero di risposte nuove |
| `GET` | `/wp-json/dbfb/v1/submissions` | Risposte, più recenti prima. Parametri: `form_id`, `status` (`new`, `read`, `archived`), `since_id`, `page`, `per_page` (max 100). Totali negli header `X-WP-Total` e `X-WP-TotalPages`. Ogni voce ha solo un'anteprima, non i dati completi |
| `GET` | `/wp-json/dbfb/v1/submissions/{id}` | Dettaglio: campi (con l'etichetta salvata al momento dell'invio), allegati, prova del consenso, consensi facoltativi, provenienza |
| `PATCH` | `/wp-json/dbfb/v1/submissions/{id}` | Cambio di stato: `{"status": "read"}` |
| `GET` | `/wp-json/dbfb/v1/stats` | Risposte per giorno e per form. Parametri: `days` (1–365, default 30), `form_id` |
| `GET` | `/wp-json/dbfb/v1/me` | Nome dell'utente e link di logout, per la web app (2.21.0+); dalla 2.22.0 anche la chiave VAPID e l'intervallo di aggiornamento |
| `GET` | `/wp-json/dbfb/v1/orders` | Ultimi ordini WooCommerce, paginati (2.23.0+, serve `edit_shop_orders`) |
| `GET` | `/wp-json/dbfb/v1/submissions/{id}/orders` | Ordini dello stesso cliente della risposta (2.23.0+, serve `edit_shop_orders`) |
| `GET` | `/wp-json/dbfb/v1/submissions/{id}/order-draft` | Dati di fatturazione ricavati dalla risposta e descrizione proposta (2.24.0+) |
| `POST` | `/wp-json/dbfb/v1/submissions/{id}/orders` | Crea l'ordine: `{"billing": {…}, "description": "…", "amount": "1.220,00", "note": "…", "send_invoice": true}` (2.24.0+) |
| `POST` | `/wp-json/dbfb/v1/orders/{id}/send-invoice` | Invia al cliente l'email con il link di pagamento di un ordine creato da una risposta (2.24.0+) |
| `GET` | `/wp-json/dbfb/v1/updates` | Id dell'ultima risposta e risposte nuove per form, per il polling (2.22.0+) |
| `POST` / `DELETE` | `/wp-json/dbfb/v1/push/subscription` | Registra o rimuove la sottoscrizione push del dispositivo: `{"endpoint": "…", "keys": {"p256dh": "…", "auth": "…"}}` (2.22.0+) |
| `POST` | `/wp-json/dbfb/v1/push/test` | Notifica di prova ai dispositivi dell'utente (2.22.0+) |

**Accesso:** serve la capability `dbfb_view_submissions`, che hanno l'amministratore e il nuovo ruolo **Gestore form**. Il ruolo non ha altri permessi: può consultare le risposte e cambiarne lo stato, ma non vede form, impostazioni, contenuti o utenti. Autenticazione standard di WordPress: cookie + nonce `wp_rest` dalle pagine del sito, [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) per i client esterni (Utenti → Profilo → Password applicazione).

```bash
curl -u "utente:xxxx xxxx xxxx xxxx xxxx xxxx" \
  "https://example.com/wp-json/dbfb/v1/submissions?status=new&per_page=10"
```

**Privacy e sicurezza:**
- header `Cache-Control: no-store` su tutte le risposte del namespace;
- niente IP nel dettaglio;
- allegati solo tramite link firmati che scadono dopo 15 minuti;
- rate limit per utente: 120 richieste al minuto, regolabile con i filter `dbfb_rest_rate_limit` e `dbfb_rest_rate_window`. Oltre il limite si riceve `429`.

**Nota:** alcuni plugin di sicurezza bloccano `/wp-json/` o disattivano le Application Passwords. Se le chiamate rispondono `401` o `404` con le credenziali giuste, controlla le loro impostazioni. Dalla 2.24.0 la pagina Form Builder → Web app lo verifica da sola (richiesta di prova alle API, ripetibile) e mostra un avviso se le API sono bloccate o le Application Passwords non sono disponibili.

`submitted_at` è nel formato `Y-m-d H:i:s` con l'orologio del database, lo stesso mostrato nell'admin.

## Filter pubblici

| Filter | Default | Uso |
|---|---|---|
| `dbfb_trust_proxy_headers` | `false` | Fidati di X-Forwarded-For/CF-Connecting-IP/X-Real-IP |
| `dbfb_recaptcha_consent_required` | `true` | Gate reCAPTCHA al consenso (false = scarica sempre) |
| `dbfb_recaptcha_category` | `'marketing'` | Categoria di consenso richiesta per reCAPTCHA |
| `dbfb_dsar_email_field_ids` | `[]` (auto) | Estende i campi considerati come "email" per il matching DSAR (2.5.0+) |
| `dbfb_submit_rate_limit` | `20` | Invii anonimi massimi per IP nella finestra, su tutti i form (2.13.0+, `0` = disattivato) |
| `dbfb_submit_rate_window` | `600` | Durata in secondi della finestra del limite precedente (2.13.0+) |
| `dbfb_download_capability` | `'manage_options'` | Capability richiesta per scaricare gli allegati (2.13.0+) |
| `dbfb_webhook_signed_file_links` | `true` | Link firmati per gli allegati nei webhook (2.13.0+, `false` = URL diretto) |
| `dbfb_webhook_file_link_ttl` | `604800` | Validità in secondi dei link firmati nei webhook (2.13.0+, default 7 giorni) |
| `dbfb_prefill_allowed_types` | testo, area testo, numero, data, URL, menu, scelta singola, checkbox | Tipi di campo precompilabili da URL (2.15.0+; file e layout mai, il campo nascosto sempre) |
| `dbfb_redirect_allow_external` | `false` | Consente il redirect dopo l'invio verso domini esterni; riceve `$url` e `$form_id` (2.15.0+) |
| `dbfb_attribution_keys` | `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content` | Parametri URL registrati come provenienza (2.17.0+). Le chiavi non `utm_*` partono dal browser solo con il consenso |
| `dbfb_attribution_consent_category` | `'marketing'` | Categoria di consenso richiesta per le chiavi non `utm_*` (2.17.0+) |
| `dbfb_revoke_rate_limit` | `20` | Richieste massime per IP alla pagina di revoca dei consensi nella finestra (2.16.0+, `0` = disattivato) |
| `dbfb_revoke_rate_window` | `600` | Durata in secondi della finestra del limite precedente (2.16.0+) |
| `dbfb_rest_rate_limit` | `120` | Richieste massime per utente alle API REST `dbfb/v1` nella finestra (2.20.0+, `0` = disattivato) |
| `dbfb_rest_rate_window` | `60` | Durata in secondi della finestra del limite precedente (2.20.0+) |
| `dbfb_orders_capability` | `'edit_shop_orders'` | Capability per vedere gli ordini WooCommerce nella web app (2.23.0+) |
| `dbfb_create_order_capability` | `'edit_shop_orders'` | Capability per creare ordini dalle risposte (2.24.0+) |
| `dbfb_order_billing_from_submission` | dati ricavati | Corregge la fatturazione proposta; riceve anche la risposta (2.24.0+) |
| `dbfb_rest_me` | dati di `GET /me` | Estende la risposta di `GET /me` (2.23.0+, usato dal modulo WooCommerce) |
| `dbfb_push_allowed_hosts` | FCM, Mozilla, Apple, Windows | Host dei push service a cui il sito invia notifiche (2.22.0+) |
| `dbfb_push_vapid_subject` | URL del sito (HTTPS) o `mailto:` dell'amministratore | Contatto VAPID comunicato ai push service (2.22.0+) |
| `dbfb_notification_message` | titolo, testo, link, tag | Messaggio delle notifiche; riceve anche `$form_id` (2.22.0+). Non inserire dati delle risposte |

Action:
- `dbfb_cleanup_submissions_done($deleted, $days)` — emessa dopo ogni esecuzione del cron retention.
- `dbfb_consent_revoked($consent_id, $consent_key, $submission_id)` — un consenso facoltativo è stato revocato, dal link email o da admin (2.16.0+). Per sincronizzare CRM e newsletter.
- `dbfb_order_created_from_submission($order, $submission_id)` — un ordine WooCommerce è stato creato da una risposta (2.24.0+).
- `dbfb_submission_saved($submission_id, $form_id, $form_data)` — una risposta è stata salvata, dopo email e webhook (2.19.0+). Per notifiche e integrazioni. `$form_data` contiene dati personali: non va scritto nei log.

## Installazione

1. Carica la cartella `db-form-builder` in `/wp-content/plugins/`
2. Attiva il plugin dal menu Plugin
3. Vai su "Form Builder" nel menu admin

## Configurazione

### reCAPTCHA
1. Vai su Form Builder > Impostazioni
2. Inserisci Site Key e Secret Key da [Google reCAPTCHA](https://www.google.com/recaptcha/admin)
3. Abilita il CAPTCHA singolarmente per ogni form

### Honeypot
Attivabile per form. Campo nascosto + verifica tempo di compilazione. Nessun impatto visivo.

### GDPR
Checkbox obbligatorio configurabile con testo e link alla Privacy Policy.

### Consensi facoltativi e revoca (2.16.0+)
Nel pannello "Consensi facoltativi" del form si aggiungono fino a 10 consensi separati da quello principale, ognuno con:
- **testo mostrato** nel form;
- **chiave** (es. `marketing`, `newsletter`): minuscole, numeri e `_`, ricavata dal testo se vuota. Identifica il consenso in CSV, webhook e registro: non cambiarla dopo i primi invii;
- **finalità** breve, usata nel registro trattamenti e nel CSV;
- **link all'informativa** (se vuoto: quello del form, poi quello globale di WordPress).

Nel form le caselle stanno in un gruppo "Consensi facoltativi" (fieldset e legend), **mai pre-spuntate e mai obbligatorie**: l'invio non dipende da queste (art. 7.4 GDPR). Il consenso GDPR principale resta separato e invariato.

**Prova del consenso.** Per ogni invio si registra in `wp_dbfb_consents` una riga per voce, **anche per i consensi non dati**: chiave, scelta, testo esatto mostrato, finalità, informativa, versione della Privacy Policy dell'Hub, data. L'interessato è collegato con un hash (HMAC con secret dedicato) e una forma mascherata dell'email: l'email in chiaro non viene mai salvata in questa tabella.

**Revoca (art. 7.3).**
- **Dal link nell'email:** il segnaposto `{revoca_consenso}` nell'email di conferma inserisce un link firmato senza scadenza, valido per tutti i consensi facoltativi dati in quell'invio. Se il segnaposto manca dal messaggio e almeno un consenso è stato dato, il link viene aggiunto in fondo. In quel caso la conferma parte in HTML con link cliccabile e versione testo alternativa. Il link apre una pagina di conferma con una casella per ogni consenso: la revoca avviene solo premendo il pulsante (mai all'apertura del link, per evitare revoche da anteprime e antivirus dei client email). La pagina è protetta da firma HMAC, controllo di origine sull'invio e limite di richieste per IP (filter `dbfb_revoke_rate_limit` / `dbfb_revoke_rate_window`). Se l'email di conferma è disattivata il link non arriva: in quel caso la revoca va gestita da admin.
- **Da admin:** nel dettaglio della risposta, blocco "Consensi facoltativi" con il pulsante "Revoca".

Ogni revoca registra data e origine (`link` o `admin`), emette l'action `dbfb_consent_revoked` e, se attivo "Invia anche le revoche dei consensi facoltativi" nel pannello Webhook, mette in coda un webhook con lo stesso sistema asincrono e firma HMAC:

```json
{
  "event": "consent_revoked",
  "form_id": 12,
  "form_title": "Prenotazione",
  "submission_id": 345,
  "consents": [{ "key": "marketing", "revoked_at": "2026-10-12T20:15:22+00:00", "source": "link" }]
}
```

**Dove compaiono:** CSV (una colonna per chiave: Sì / No / Revocato con data), webhook dell'invio (chiave `consents: [{ key, given, given_at, policy_version }]`, le altre chiavi del payload non cambiano), notifica admin (segnaposto `{consensi}`, es. `marketing: Sì; newsletter: No`), dettaglio della risposta, DSAR e Registro consensi del Privacy Hub.

### Limite invii per IP
Massimo N invii in X minuti dallo stesso IP, configurabile per form.

### Logica condizionale
Per ogni campo, abilita "Logica condizionale" nelle impostazioni:
- Scegli Mostra/Nascondi
- Seleziona il campo trigger, l'operatore e il valore
- Aggiungi più regole con logica AND (tutte) o OR (almeno una)
- Operatori: uguale, diverso, contiene, non contiene, vuoto, non vuoto, maggiore di, minore di

### Upload file
Trascina il campo "Upload file" nel builder e configura:
- Estensioni ammesse (default: jpg, jpeg, png, gif, pdf, doc, docx, xls, xlsx, zip)
- Dimensione massima per file (default: 5 MB)
- File multipli sì/no
- I file vengono salvati in `wp-content/uploads/dbfb/{form_id}/` (non nella Media Library)

**Protezione degli allegati (2.13.0+).** Gli allegati non hanno più un URL pubblico:
- il nome di ogni nuovo file ha un prefisso casuale di 24 caratteri (non indovinabile);
- `uploads/dbfb/.htaccess` nega l'accesso diretto a tutta la cartella (sintassi Apache 2.2 e 2.4), con `index.php` contro il listing; la regola viene applicata anche alle installazioni esistenti al primo accesso all'admin;
- il download avviene solo da `admin-post.php?action=dbfb_download` (login, capability `manage_options`, nonce, percorso confinato in `uploads/dbfb/` con verifica `realpath`). Lo usano la pagina Risposte, l'export CSV e l'email di notifica all'admin (dal link in un'email l'admin conferma il download con un clic). I file caricati prima della 2.13.0 restano scaricabili dallo stesso handler.
- l'email di conferma a chi ha inviato il form riporta solo il nome dell'allegato, senza link (quello admin richiederebbe un login che l'utente non ha).
- **Nginx non legge `.htaccess`**: aggiungi alla configurazione del sito `location ^~ /wp-content/uploads/dbfb/ { deny all; }` (l'admin mostra un avviso con la regola). Senza la regola, su Nginx i file restano protetti solo dal nome non prevedibile.
- I webhook ricevono nome, dimensione e un **link firmato a scadenza** al posto dell'URL diretto (vedi sezione Webhook).

### Multi-step
Trascina "Cambio pagina" tra i campi per dividere il form in step. Il frontend mostra automaticamente barra di progresso, bottoni Indietro/Avanti e validazione per step.

### Precompilazione da URL (2.15.0+)
Nelle impostazioni del campo, "Parametro URL per precompilare" (es. `arrivo`) fa compilare il campo da un link: `https://sito.it/prenota/?arrivo=2026-11-01&camera=suite`. Utile per pulsanti e campagne che portano al form con una scelta già fatta.

- **Tipi ammessi:** testo, area testo, numero, data, URL, menu a tendina, scelta singola, checkbox, campo nascosto. Email e telefono sono esclusi: un dato personale nell'URL finisce in log del server, cronologia, header Referer e analytics. File e campi di layout esclusi sempre. Filter `dbfb_prefill_allowed_types` per cambiare l'elenco.
- **Nome del parametro:** minuscole, numeri, `-` e `_`, massimo 40 caratteri. Nell'URL non conta maiuscolo/minuscolo; se un parametro è ripetuto vale il primo.
- **Valori accettati:** date solo `AAAA-MM-GG` valide; numeri solo numerici (nei limiti min/max se presenti); URL solo `http(s)`; menu, scelta singola e checkbox solo valori uguali a un'opzione (senza distinguere maiuscole/minuscole), più checkbox separate da virgola (`?extra=spa,parcheggio`). Massimo 500 caratteri. Un valore che sembra un indirizzo email viene sempre ignorato. I valori non validi si scartano senza errori.
- **Mai sovrascrivere:** un campo già compilato (autofill del browser, navigazione indietro) resta com'è. Dopo un invio riuscito il form si riprecompila con gli stessi valori.
- **Logica condizionale:** la precompilazione avviene prima, quindi un campo precompilato mostra o nasconde subito i campi collegati.
- **Cache di pagina:** il server stampa solo il nome del parametro (`data-dbfb-prefill`), uguale per tutti; l'URL viene letto nel browser. All'invio vale la normale sanitizzazione lato server.

### Campo nascosto (2.15.0+)
Il tipo "Campo nascosto" non si vede nel form ma viene inviato con le risposte: compare in Risposte, CSV, email (`{campo:id}`) e webhook. Ha un valore fisso, sostituito dal parametro URL se configurato e presente. Caso tipico: sapere da quale pulsante arriva la richiesta (`?origine=hero`, `?origine=ristorante`).

Il valore arriva dalla pagina o dall'URL, quindi chiunque può modificarlo: va bene per statistiche, non per dati di cui fidarsi. Massimo 500 caratteri, mai obbligatorio, niente logica condizionale propria (può però fare da condizione per altri campi).

### Dopo l'invio: messaggio o redirect (2.15.0+)
In Impostazioni del form, "Dopo l'invio": mostra il messaggio di successo oppure reindirizza a una pagina. L'URL deve essere di questo sito (path come `/grazie/`, relativo alla radice del dominio, o URL completo): viene verificato al salvataggio e di nuovo a ogni invio. Domini esterni solo con il filter `dbfb_redirect_allow_external` (protezione da open redirect). Il redirect avviene nel browser, dopo l'evento `dbfb:submitted`.

### Provenienza della campagna (2.17.0+)
In Impostazioni del form, "Registra la provenienza della campagna (UTM)" (disattivata di default). All'invio vengono salvati con la risposta `utm_source`, `utm_medium`, `utm_campaign`, `utm_term` e `utm_content` presenti nell'URL della pagina del form, più il percorso della pagina (senza query string).

- **Solo dall'URL, niente storage:** i parametri si leggono dall'URL corrente al momento dell'invio. Nulla viene scritto sul dispositivo del visitatore (né cookie, né sessionStorage, né localStorage), quindi non serve il consenso cookie (art. 5.3 ePrivacy). Limite voluto: se il visitatore arriva su un'altra pagina e raggiunge il form navigando, gli UTM non vengono registrati. Per le campagne conviene quindi linkare direttamente la pagina con il form.
- **Identificativi di click** (`gclid`, `fbclid`, `msclkid`, ...) esclusi per default: sono identificativi di tracciamento. Si aggiungono con il filter `dbfb_attribution_keys` e il browser li invia solo con il consenso della categoria `marketing` (DB Cookie Manager o WP Consent API; filter `dbfb_attribution_consent_category`). Senza consent manager non vengono mai inviati.
  ```php
  add_filter('dbfb_attribution_keys', function ($keys) { $keys[] = 'gclid'; return $keys; });
  ```
- **Lato server:** solo chiavi ammesse, `sanitize_text_field`, massimo 150 caratteri per valore; ignorati se l'opzione non è attiva sul form. Salvati nella chiave riservata `_attribution` del JSON della risposta (come `_fields_snapshot`): nessuna migrazione.
- **Dove compaiono:** dettaglio della risposta (blocco "Provenienza della campagna"), CSV (una colonna per chiave presente, nessuna colonna se l'opzione non è mai stata usata), webhook (chiave `attribution` separata da `fields`, esclusa da `raw_data`; assente se non c'è provenienza), segnaposto email `{utm_source}`, `{utm_medium}`, `{utm_campaign}`, `{utm_term}`, `{utm_content}`, `{pagina_form}` (vuoti se assenti), export DSAR, registro trattamenti (`dbfb_submissions` cita i dati di provenienza quando almeno un form ha l'opzione attiva).
- **Cache di pagina:** il server stampa sul `<form>` solo l'elenco delle chiavi (`data-dbfb-attribution`), uguale per tutti.

### Eventi JavaScript (2.15.0+)
Eventi DOM nativi sul `<form>`, che risalgono fino a `document` (non serve jQuery). Non contengono mai dati personali.

| Evento | `detail` | Quando |
|--------|----------|--------|
| `dbfb:prefilled` | `{ formId, fields: [id campo, …] }` | Dopo la precompilazione da URL, se almeno un campo è stato compilato |
| `dbfb:submitted` | `{ formId, redirect: bool }` | Dopo un invio riuscito, prima dell'eventuale redirect |

Il tracciamento delle conversioni resta al tema e va subordinato al consenso. Esempio con DB Cookie Manager:

```js
document.addEventListener('dbfb:submitted', function (e) {
    if (window.DBCM && window.DBCM.hasConsent('statistics') && typeof window.gtag === 'function') {
        window.gtag('event', 'generate_lead', { form_id: e.detail.formId });
    }
});
```

### Webhook (2.7.0+: async, retry, HMAC)

Attivabile per form. Inserisci l'URL e ad ogni invio il plugin fa un POST JSON con `form_id`, `form_title`, `submitted_at`, `ip`, `privacy_url`, `fields` (array con id, label, type, value) e `raw_data`. Compatibile con Zapier, Make, n8n, endpoint custom.

Chiavi aggiuntive, presenti solo quando servono (le altre non cambiano mai):
- `consents` — esito dei consensi facoltativi: `[{ key, given, given_at, policy_version }]` — **2.16.0+**
- `attribution` — provenienza della campagna: `{ utm_source, utm_medium, utm_campaign, utm_term, utm_content, landing }` (solo le chiavi presenti) — **2.17.0+**

Con l'opzione "Invia anche le revoche dei consensi facoltativi" arriva anche un POST separato con `event: "consent_revoked"` a ogni revoca (vedi "Consensi facoltativi e revoca") — **2.16.0+**

**Affidabilità.** Le deliveries sono asincrone (via WP cron). In caso di errore transient (timeout di rete, HTTP 5xx, 408, 429) il plugin ritenta automaticamente fino a 5 volte con backoff esponenziale: 1 minuto → 5 minuti → 30 minuti → 2 ore → 12 ore. Errori 4xx permanenti (400, 401, 403, 404, ecc.) vengono registrati come `failed` senza retry. Le deliveries esaurite finiscono nello stato `dead` per ispezione manuale.

Diagnostica visibile in `Form Builder → Webhook Deliveries`: tabella con filtro per stato, ultimo errore, status code HTTP, prossimo retry, retry/cancellazione bulk.

**Autenticazione del payload (HMAC SHA-256).** Per ogni form puoi configurare un `Webhook Secret`. Quando presente, ogni POST include questi header:

```
X-DBFB-Timestamp: 1746201234
X-DBFB-Signature: sha256=8f7a3...
X-DBFB-Delivery-Id: 1234
X-DBFB-Attempt: 1
```

La signature è `hmac_sha256(timestamp + "." + raw_body, secret)`. Verifica lato destinatario in pseudocode:

```python
expected = "sha256=" + hmac_sha256(secret, timestamp_header + "." + raw_request_body)
if not constant_time_compare(expected, signature_header):
    return 401
if abs(now() - int(timestamp_header)) > 300:  # max 5 min
    return 401
```

Pattern industry-standard usato da Stripe e GitHub. Il timestamp protegge da replay attack.

**Allegati nel payload (2.13.0+).** La cartella `uploads/dbfb/` non è più raggiungibile direttamente, quindi nel payload il valore `url` di ogni allegato (in `fields[].value` e in `raw_data`) è un link pubblico firmato: `admin-post.php?action=dbfb_file&f=<path>&exp=<timestamp>&sig=<hmac>`. Le chiavi del payload non cambiano. Il link non richiede login, scade dopo 7 giorni (filter `dbfb_webhook_file_link_ttl`, in secondi) ed è firmato con HMAC SHA-256 su `path|scadenza` con un secret dedicato (option `dbfb_file_link_secret`, generata una sola volta; cancellandola si invalidano tutti i link emessi). Il file viene servito come allegato, con `nosniff` e header anti-cache, con lo stesso controllo `realpath` del download admin. Il link è generato al momento di ogni tentativo di invio (anche nei retry automatici e nel "Re-invia" manuale), quindi è sempre valido per l'intero TTL dal momento della consegna. Chi scarica gli allegati in modo asincrono deve farlo entro il TTL. Per tornare all'URL diretto (inutile se la cartella è protetta): `add_filter('dbfb_webhook_signed_file_links', '__return_false');`.

### Email
Configura il mittente nelle Impostazioni globali (se l'indirizzo non è valido si usa il mittente predefinito di WordPress). Personalizza oggetto e messaggio per ogni form. Più destinatari admin separati da virgola. Le email sono in testo semplice; dalla 2.14.1 la conferma con documenti allegati è in HTML (link cliccabili) con versione testo alternativa, e lo stesso vale dalla 2.16.0 per la conferma con il link di revoca dei consensi.

Sotto ogni email, nell'editor del form, c'è l'elenco dei segnaposto disponibili: un clic lo inserisce nell'oggetto o nel messaggio, dove si trova il cursore. Segnaposto:
- `{campo:id}` — Valore di un singolo campo, identificato dal suo id: resta valido anche se l'etichetta viene rinominata. Se il campo viene eliminato, il segnaposto sparisce dall'email — **2.12.0+**
- `{nome}`, `{email}`, ecc. — Formato precedente, ancora supportato: deriva dall'etichetta del campo e smette di funzionare se l'etichetta cambia
- `{form_titolo}` — Nome del form
- `{riepilogo_dati}` — Tutti i campi compilati
- `{ip}`, `{data}`, `{sito}`
- `{privacy_url}` — URL informativa privacy del form (con fallback a quella globale WP) — **2.8.0+**
- `{documenti}` — Link ai documenti scelti in "Documenti da inviare" (solo email di conferma; se manca dal messaggio l'elenco viene aggiunto in fondo) — **2.14.0+**
- `{revoca_consenso}` — Link per revocare i consensi facoltativi dati nell'invio (solo email di conferma; vuoto se non è stato dato nessun consenso; se manca dal messaggio viene aggiunto in fondo) — **2.16.0+**
- `{consensi}` — Riepilogo dei consensi facoltativi, es. `marketing: Sì; newsletter: No` (pensato per la notifica admin) — **2.16.0+**
- `{utm_source}`, `{utm_medium}`, `{utm_campaign}`, `{utm_term}`, `{utm_content}`, `{pagina_form}` — Provenienza della campagna, se l'opzione è attiva sul form (vuoti se assenti) — **2.17.0+**

I valori inseriti dagli utenti non vengono mai interpretati come segnaposto: chi scrive `{ip}` in un campo lo ritrova letterale nell'email (2.11.2+).

### Aspetto (2.12.0+)
In **Impostazioni → Aspetto del form** scegli tre colori: sfondo, pulsanti e testo. Ogni form può sovrascriverli dal pannello **Aspetto** dell'editor; un campo vuoto usa il livello superiore (form → globale → predefinito del plugin). Il resto viene calcolato:
- **Testo dei pulsanti**: bianco o nero, quello con contrasto maggiore sul colore scelto (supera sempre 4.5:1).
- **Hover dei pulsanti**: più scuro con testo bianco, più chiaro con testo nero.
- **Link**: il colore dei pulsanti se leggibile sullo sfondo, altrimenti il colore del testo (i link restano sottolineati).
- **Campi di input**: restano bianchi con testo scuro, così sono leggibili su qualunque sfondo.

Un'anteprima mostra il risultato e un avviso segnala quando il contrasto testo/sfondo scende sotto 4.5:1. Con sfondo impostato il form riceve spazio interno e bordi arrotondati.

Per stili avanzati il CSS del frontend espone le variabili `--dbfb-bg`, `--dbfb-text`, `--dbfb-text-muted`, `--dbfb-primary`, `--dbfb-primary-hover`, `--dbfb-button-text`, `--dbfb-link` su `.dbfb-form`.

## Utilizzo

### Creare un form
1. Form Builder > Nuovo Form
2. Scegli template o parti da zero
3. Trascina campi, configura, imposta sicurezza/email/webhook
4. Anteprima, salva, copia shortcode

### Shortcode
```
[dbfb_form id="123"]
```

### Duplicare un form
Lista form > Duplica

### Esportare e importare un form (2.18.0+)
Per portare un form da un sito all'altro, per esempio dal sito di test alla produzione:

1. Sul sito di origine, nella lista dei form, clicca **"Esporta"**: scarichi un file `.json` con titolo, campi e impostazioni.
2. Sul sito di destinazione, nella lista dei form, apri **"Importa un form da file"**, scegli il file e premi **"Importa"**.
3. Usa nelle pagine il **nuovo shortcode** mostrato nell'avviso: l'import crea sempre un form nuovo, con un ID diverso, e non modifica mai i form esistenti. Se esiste già un form con lo stesso titolo, il nuovo prende il suffisso "(importato)".

Cosa succede durante l'import:
- **Indirizzi riscritti:** gli URL del sito di origine (informativa privacy, redirect, immagini, link dei consensi facoltativi) diventano quelli del sito di destinazione.
- **ID dei campi conservati:** i segnaposto `{campo:id}` delle email continuano a funzionare.
- **Documenti della conferma:** si ricollegano se nella Media Library del sito di destinazione c'è un file allo stesso indirizzo; altrimenti un avviso indica quanti vanno ricaricati.
- **Webhook disattivato:** il secret HMAC non viene mai esportato e l'URL può essere quello di test. Il webhook arriva spento: controlla URL e secret, poi riattivalo.
- **Redirect:** se dopo la riscrittura non punta al sito di destinazione, viene sostituito dal messaggio di successo.
- **Sicurezza:** campi e impostazioni ripassano dalla stessa sanitizzazione del salvataggio dal builder (HTML filtrato con `wp_kses_post`, tipi di campo sconosciuti scartati). File massimo 1 MB; servono i permessi di amministratore e il nonce. Il file non contiene risposte, consensi, allegati né altri dati personali.

L'avviso dopo l'import elenca tutto ciò che è stato cambiato o va ricontrollato.

### Risposte
Form Builder > Risposte — dettaglio modale, elimina singola/bulk, export CSV.

Dalla 2.19.0 ogni risposta ha uno stato: **Nuova**, **Letta** o **Archiviata**. Le risposte arrivano come nuove e diventano lette all'apertura del dettaglio; con le azioni di massa si possono segnare come lette, rimettere tra le nuove o archiviare. I tab in cima alla tabella filtrano per stato, la tabella mostra 50 risposte per pagina e l'elenco dei form indica quante risposte nuove ha ciascuno. Lo stato serve solo all'organizzazione: la retention cancella anche le risposte archiviate.

## Accessibilità (WCAG 2.1 AA)

- `aria-required`, `aria-invalid`, `aria-describedby` su tutti i campi
- `fieldset`/`legend` per gruppi checkbox e radio
- `role="alert"` e `aria-live` per messaggi di stato
- Focus management su errori/messaggi e dopo chiusura modali
- Focus trap e Escape su modali
- `focus-visible` con outline ad alto contrasto
- Touch target minimo 44×44px
- Contrasto ≥ 4.5:1 su tutti i testi e componenti
- `prefers-reduced-motion` e `forced-colors` supportati
- Screen reader text per "(obbligatorio)" e "(si apre in una nuova finestra)"

## Changelog

### 2.24.0 — Ordine WooCommerce da una risposta

Migrazione dello schema v7 → v8 (nuova tabella `wp_dbfb_links`), automatica.

- **"Crea ordine" nella web app:** fatturazione ricavata dalla risposta e modificabile, riga personalizzata con importo libero (IVA secondo le impostazioni del negozio), ordine "in attesa di pagamento", link di pagamento da copiare o inviato al cliente con l'email WooCommerce "Dettagli ordine".
- **Collegamento risposta ↔ ordine** nei due sensi (`_dbfb_submission_id` e `wp_dbfb_links`); l'ordine compare come "Creato da questa risposta" tra gli ordini del cliente.
- **Privacy:** il collegamento si cancella con la risposta in tutti i percorsi (eliminazione singola, multipla, del form, retention, eraser GDPR), e con l'ordine se questo viene eliminato definitivamente; ordini collegati nell'export GDPR; nuova voce "Collegamento risposte ai form e ordini WooCommerce" nel registro dei trattamenti.
- **API:** `GET /submissions/{id}/order-draft`, `POST /submissions/{id}/orders`, `POST /orders/{id}/send-invoice`.
- **Gestore form solo nell'app:** le pagine dell'amministrazione (tranne il profilo) riportano all'app, niente barra di amministrazione sul sito.
- **Controllo delle API** nella pagina Web app: avviso se un plugin di sicurezza blocca `/wp-json/` o se le Application Passwords non sono disponibili.

### 2.23.0 — WooCommerce: risposte e ordini del cliente

Nessuna migrazione dello schema. Il modulo si attiva solo con WooCommerce.

- **Ordini del cliente nel dettaglio di una risposta**, trovati per email di fatturazione e per account registrato; **"Ordini del negozio"** nella web app.
- **Compatibilità HPOS dichiarata** (`FeaturesUtil::declare_compatibility`); solo `wc_get_orders()`, testato con archiviazione HPOS e classica.
- **API:** `GET /orders` e `GET /submissions/{id}/orders`, con la capability `edit_shop_orders` (filter `dbfb_orders_capability`).
- **Repository:** `subject_email()` restituisce l'email dell'interessato con la stessa regola di `subject_hash`.

### 2.22.0 — Aggiornamenti in tempo reale e notifiche

Nessuna migrazione dello schema. Le notifiche push sono pronte all'uso; ntfy e Telegram si configurano in Form Builder → Web app.

- **Polling leggero** (`GET /updates`) ogni 15 secondi con l'app in primo piano, sospeso in background; conteggi aggiornati senza ricaricare e pulsante "Mostra le nuove risposte" nelle liste.
- **Badge** sull'icona dell'app installata (Badging API).
- **Web Push senza librerie:** chiavi VAPID generate al primo uso (option non autoload), JWT ES256 e cifratura `aes128gcm` (RFC 8291/8292) con OpenSSL di PHP. Funziona da PHP 7.4, anche sui server senza `openssl.cnf` (configurazione minima inclusa nel plugin). Sottoscrizioni per utente e per dispositivo (massimo 10), rimosse quando il push service le dichiara scadute e al logout dalla web app.
- **ntfy e Telegram** come canali alternativi, con notifica di prova dall'admin.
- **Privacy:** nessun dato delle risposte nelle notifiche; titolo del form disattivabile; endpoint push limitati ai servizi dei browser; nuova voce nel registro dei trattamenti. La disinstallazione rimuove chiavi, impostazioni, token e sottoscrizioni.

### 2.21.0 — Web app delle risposte

Nessuna migrazione dello schema. Le regole di rewrite di `/dbfb-app/` si registrano da sole dopo l'aggiornamento.

- **Web app (PWA) su `/dbfb-app/`:** elenco dei form con risposte nuove, risposte filtrate per stato, dettaglio con allegati e consensi, cambio di stato, tema chiaro e scuro, installabile su Android, iOS e desktop. Vedi la sezione "Web app delle risposte".
- **Service worker** con scope `/dbfb-app/`: in cache solo l'interfaccia, mai le risposte. A sito irraggiungibile l'app si apre comunque e lo segnala.
- **Shell senza dati personali e senza `wp_head()`**, con Content Security Policy, `X-Frame-Options: DENY`, header `no-store` e `DONOTCACHEPAGE`.
- **Login del "Gestore form"** reindirizzato all'app; nuova pagina admin Form Builder → Web app con link e istruzioni di installazione.
- **API:** nuova rotta `GET /me`.

### 2.20.0 — API REST e ruolo "Gestore form"

Nessuna migrazione dello schema. Capability e ruolo si installano da soli al primo accesso all'admin dopo l'aggiornamento.

- **API REST `dbfb/v1`:** form, risposte paginate con filtri per form, stato e `since_id`, dettaglio, cambio di stato, statistiche per giorno. Vedi la sezione "API REST".
- **Capability `dbfb_view_submissions`** assegnata all'amministratore, e nuovo **ruolo "Gestore form"** che ha solo questa capability (più `read`). Disinstallando il plugin si rimuovono entrambi.
- **Sicurezza:** permessi verificati su ogni rotta, parametri validati dallo schema (`400` se non validi), rate limit per utente, header `no-store`, allegati solo con link firmati di 15 minuti, niente IP.
- `DBFB_Submissions::signed_attachment_url()` accetta una durata del link (secondo parametro, facoltativo).

### 2.19.0 — Stato delle risposte e repository

Migrazione dello schema v6 → v7, automatica e idempotente. Le risposte già presenti diventano "lette".

- **Stato della risposta:** nuova colonna `status` (`new`, `read`, `archived`). In Risposte: badge di stato, tab di filtro con i conteggi, azioni di massa "Segna come lette / come nuove / Archivia"; aprire il dettaglio di una risposta nuova la segna come letta. L'elenco dei form mostra quante risposte nuove ha ciascuno.
- **Paginazione:** la pagina di un form mostra 50 risposte alla volta; prima caricava tutte le risposte in una sola pagina. L'elenco dei form calcola i totali con una query sola invece di una per form.
- **Interessato indicizzato:** nuova colonna `subject_hash`, HMAC-SHA256 dell'email dell'interessato con lo stesso segreto dei consensi facoltativi. Nessuna email in chiaro in più nel database. Le risposte esistenti vengono completate a batch da un cron (`dbfb_backfill_subject_hash`, 500 righe al minuto). Prepara il collegamento ai clienti WooCommerce.
- **`DBFB_Submissions_Repository`:** un'unica classe per leggere, contare, scrivere e cancellare le risposte. La cancellazione (riga, allegati, webhook deliveries, consensi) passa da un solo punto per eliminazione singola, multipla, totale, eliminazione del form ed eraser DSAR. `DB_Form_Builder::delete_form_submissions()` resta come wrapper.
- **Salvataggio più robusto:** l'INSERT scarta le colonne che una migrazione non riuscita non ha creato, invece di perdere la risposta.
- **Nuova action `dbfb_submission_saved`** dopo il salvataggio di una risposta.

### 2.18.3 — PHPCS a zero warning

Nessuna migrazione dello schema, nessun cambiamento per i form esistenti.

- **Log solo in debug:** nuovo helper `DB_Form_Builder::debug_log()`, che scrive solo con `WP_DEBUG` attivo. I messaggi di reCAPTCHA (token vuoto, errore di rete, score basso) finivano nel log PHP anche in produzione; ora si comportano come gli altri log del plugin.
- **Submission salvate con `wp_json_encode()`:** con un testo UTF-8 non valido, `json_encode()` restituiva `false` e la risposta veniva salvata vuota.
- **Disinstallazione:** `wp_delete_file()` per i file di protezione della cartella `uploads/dbfb/`.
- **Pulizia:** script del blocco Gutenberg registrato con `$in_footer` esplicito, parametro `$global` rinominato in `$global_settings`, eccezioni motivate annotate sulle operazioni dirette sul filesystem (file di protezione locali, export CSV, import JSON).

### 2.18.2 — Bonifica WPCS di sicurezza

Nessuna migrazione dello schema, nessun cambiamento per i form esistenti.

- **Fix apostrofi nelle impostazioni globali:** nome mittente, email e chiavi reCAPTCHA venivano salvati senza `wp_unslash()`, quindi "Dell'Arte" diventava "Dell\'Arte" nelle email. Corretto anche nell'email di prova. I valori già salvati con la barra vanno corretti e salvati di nuovo dalle Impostazioni.
- **Input:** `wp_unslash()` + sanitizzazione su tutti i `$_POST` / `$_GET` / `$_SERVER` letti da builder, impostazioni, azioni admin, invio del form e rilevamento IP; nonce sanificati prima di `wp_verify_nonce()`.
- **Confronti stretti:** `in_array(..., true)` su operatori delle condizioni, campi nascosti ed estensioni degli upload (in PHP 7.4 `0 == 'equals'` era vero).
- **Output e redirect:** `esc_html__()` nei `wp_die()`, `esc_html_e()` nel widget, `wp_safe_redirect()` per i redirect dell'admin.
- **CI:** le regole di sicurezza WPCS (`EscapeOutput`, `NonceVerification`, `ValidatedSanitizedInput`) passano da warning a **error**: una nuova violazione blocca la PR. Le eccezioni motivate sono annotate nel codice.

### 2.18.1 — Manutenzione della CI

Nessun cambiamento al codice del plugin.

- **Runner fissato a `ubuntu-24.04`** in CI e Release (era `ubuntu-latest`, che dal 19/10/2026 passa a Ubuntu 26).
- **Action su Node 24:** `actions/checkout` v4 → v7, `softprops/action-gh-release` v2 → v3; niente più avviso di deprecazione di Node 20.

### 2.18.0 — Esporta / importa un form

Nessuna migrazione dello schema, nessun cambiamento per i form esistenti.

- **Esporta:** link "Esporta" nella lista dei form (`admin-post.php?action=dbfb_export_form`, nonce + `manage_options`): file `.json` con formato `dbfb-form` v1, titolo, campi, impostazioni e indirizzo del sito di origine. Il secret HMAC del webhook non viene esportato; i documenti della conferma sono esportati come URL invece che come ID della Media Library.
- **Importa:** riquadro "Importa un form da file" nella lista (`admin-post.php?action=dbfb_import_form`, upload massimo 1 MB, nonce + `manage_options`). Crea sempre un form nuovo; avviso con il nuovo shortcode e l'elenco delle modifiche.
- **Adattamento al sito di destinazione:** URL del sito di origine riscritti, documenti ricollegati per URL, webhook importato disattivato, redirect non valido sostituito dal messaggio di successo, titolo con "(importato)" se già presente.
- **Sicurezza:** sanitizzazione identica al salvataggio dal builder (`DBFB_Builder::sanitize_fields()` / `sanitize_settings()`, ora pubbliche), whitelist dei tipi di campo, nuova classe `DBFB_Import_Export`.

### 2.17.0 — Provenienza delle campagne (UTM)

Nessuna migrazione dello schema. I form esistenti non cambiano finché l'opzione resta spenta (default): nessuna colonna nuova nel CSV, nessuna chiave nuova nel webhook.

- **Nuova opzione per form** "Registra la provenienza della campagna (UTM)" (`track_attribution`), nuova classe `DBFB_Attribution`.
- **Solo dall'URL:** `frontend.js` legge `utm_*` e il percorso della pagina dall'URL corrente all'invio e li invia come campo riservato `attribution`. Nessun cookie, sessionStorage o localStorage: non serve consenso (scelta privacy by design rispetto alla conservazione per tutta la visita prevista in roadmap).
- **Identificativi di click** esclusi per default; abilitabili con il filter `dbfb_attribution_keys`, inviati dal browser solo con consenso `marketing` (filter `dbfb_attribution_consent_category`).
- **Server:** whitelist delle chiavi, `sanitize_text_field`, max 150 caratteri, percorso senza query; salvataggio in `_attribution` nel JSON, esclusa dai campi come `_fields_snapshot` (`get_submission_fields()` la restituisce a parte in `attribution`).
- **Propagazione:** dettaglio della risposta, CSV (colonne per le chiavi presenti), webhook (`attribution`, rimossa da `raw_data`), segnaposto `{utm_*}` e `{pagina_form}` (anche nell'editor, con l'opzione attiva, e con valori di esempio nell'email di test), export DSAR, dichiarazione `dbfb_submissions` aggiornata.

### 2.16.0 — Consensi facoltativi per voce + revoca (art. 7.3)

Consensi aggiuntivi per form (marketing, newsletter, ...), separati dal consenso GDPR principale, con prova per voce, revoca dal link nell'email e integrazione completa con il DB Privacy Hub.

**Consensi facoltativi nel form:**
- Nuovo pannello "Consensi facoltativi" nell'editor: fino a 10 voci con chiave, testo, finalità e link all'informativa (fallback a quello del form, poi a quello globale). Impostazione `optional_consents`.
- Nel frontend: fieldset "Consensi facoltativi", caselle mai pre-spuntate e mai obbligatorie (art. 7.4); inviate a parte (`consents`), il server accetta solo le chiavi configurate.

**Prova del consenso per voce — nuova tabella `wp_dbfb_consents`:**
- `submission_id`, `form_id`, `consent_key`, `given` (registrato anche `0`), `consent_text` (testo esatto mostrato), `purpose`, `privacy_url`, `policy_version` (ID dello snapshot del Privacy Hub, `0` se assente), `subject_hash` (HMAC dell'email con secret dedicato `dbfb_consent_secret`), `subject_masked`, `given_at`, `revoked_at`, `revoke_source`. Indici su `submission_id`, `consent_key`, `policy_version`, `subject_hash`.
- Mai l'email in chiaro: l'hash serve a ritrovare i consensi di un interessato (DSAR, filtro del registro) anche dopo la retention della risposta.

**Migrazione automatica:**
- Schema v5 → v6: creazione della tabella, idempotente (`SHOW TABLES` prima di creare), anche in `admin_init` per gli aggiornamenti senza riattivazione.

**Revoca (art. 7.3):**
- Segnaposto `{revoca_consenso}` nell'email di conferma: link firmato HMAC senza scadenza (`admin-post.php?action=dbfb_revoke`), per tutti i consensi dell'invio. Aggiunto in fondo se il segnaposto manca e c'è almeno un consenso dato. La conferma con link diventa HTML con alternativa testo, come per i documenti (2.14.1).
- Pagina di conferma su GET, revoca solo su POST. Endpoint anonimo secondo la regola della suite: firma HMAC, controllo `Origin`/`Referer`, rate limit per IP con hash salato. Nessun nonce.
- Revoca manuale dal dettaglio della risposta (`revoke_source = admin`). Action `dbfb_consent_revoked($consent_id, $consent_key, $submission_id)`. Webhook di revoca opzionale per form (`webhook_on_revoke`, `event: consent_revoked`).

**Conservazione e cancellazione:**
- Retention: i consensi dati e non revocati restano fino alla revoca; consensi non dati e revocati seguono la risposta; revoche successive alla retention conservate per la stessa durata della retention, poi cancellate.
- Cascata su eliminazione singola, multipla e totale delle risposte, eliminazione del form, DSAR erase (anche per hash, per i consensi rimasti dopo la retention), disinstallazione Hard (`DROP TABLE`). In disinstallazione Soft tabella e secret restano, così i link di revoca già inviati continuano a funzionare.

**Integrazione DB Privacy Hub:**
- Registro consensi: nuova sorgente `dbfb_marketing_consents` (solo `given = 1`, email mascherata, stato attivo/revocato, filtri per data, interessato e chiave).
- Registro trattamenti: nuova dichiarazione dinamica `dbfb_marketing` (anche sul filter legacy `dbseo_processing_register`), solo se almeno un form pubblicato ha consensi facoltativi: base giuridica 6.1.a, finalità configurate, conservazione fino alla revoca, host dei webhook.
- DSAR: l'export include i consensi (gruppo "Consensi facoltativi"); l'erase li cancella.
- Blocco "Privacy capabilities" del file principale aggiornato.

**Propagazione:** CSV (una colonna per chiave: Sì/No/Revocato con data), webhook dell'invio (`consents: [{ key, given, given_at, policy_version }]`), segnaposto `{consensi}` nella notifica admin, blocco "Consensi facoltativi" nel dettaglio della risposta.

**Comportamento standalone preservato:** senza Privacy Hub i consensi vengono salvati con `policy_version = 0`; con l'Hub sono collegati allo snapshot esatto della policy.

**Nessun breaking change.** I form senza consensi facoltativi non cambiano: nessuna colonna nuova nel CSV, nessuna chiave nuova nel webhook, conferma invariata.

### 2.15.0 — Precompilazione da URL, campo nascosto, redirect ed eventi

Nessuna migrazione dello schema, nessun cambiamento per i form esistenti finché non si usano le nuove opzioni.

- **Precompilazione da URL:** nuova impostazione per campo "Parametro URL per precompilare" (`prefill_param`, minuscole, `[a-z0-9_-]`, max 40 caratteri), salvata solo per i tipi ammessi (filter `dbfb_prefill_allowed_types`; email e telefono esclusi per default). Nuova classe `DBFB_Prefill`. Il server stampa solo `data-dbfb-prefill` (cache-safe); `frontend.js` legge l'URL, valida il valore per tipo, non sovrascrive valori già presenti e lancia `change` così la logica condizionale reagisce. Un valore che sembra un indirizzo email viene sempre ignorato.
- **Campo nascosto:** nuovo tipo `hidden` con valore fisso o da parametro URL, sanitizzato con `sanitize_text_field` e massimo 500 caratteri, mai obbligatorio, senza logica condizionale propria. Visibile in Risposte, CSV, email e webhook.
- **Eventi JS:** `dbfb:submitted` dopo un invio riuscito (`detail: { formId, redirect }`) e `dbfb:prefilled` dopo la precompilazione (`detail: { formId, fields }`), come `CustomEvent` nativi che risalgono fino a `document`, senza dati personali.
- **Redirect dopo l'invio:** "Dopo l'invio: messaggio | redirect a URL". Solo pagine del sito (path o URL completo con lo stesso host di `home_url()`/`site_url()`), verificato al salvataggio (errore esplicito) e a ogni invio; domini esterni solo con il filter `dbfb_redirect_allow_external`. Il redirect avviene nel browser dopo `dbfb:submitted`.

### 2.14.1 — Link dei documenti cliccabili

- **Documenti nella conferma:** in un'email in testo semplice il link era cliccabile solo se il client di posta riconosceva l'URL da solo, e molti mostravano solo testo. Quando ci sono documenti da inviare la conferma ora parte in HTML, con un elenco di link veri (titolo del documento cliccabile), più la versione in testo semplice come alternativa (`multipart/alternative`). Il testo del messaggio e i valori inseriti dall'utente sono sempre escapati: diventano link solo i documenti scelti dall'admin, mai gli URL scritti nei campi del form. Senza documenti la conferma resta in testo semplice come prima. Vale anche per "Invia Test".

### 2.14.0 — Documenti nell'email di conferma

- **Documenti da inviare:** nel pannello "Email Conferma Utente" si scelgono uno o più file dalla Media Library (massimo 20, salvati come ID in `confirmation_documents`). Nell'email arrivano come link, uno per riga (`- Titolo: URL`), tramite il nuovo segnaposto `{documenti}`, cliccabile nell'editor. Se il segnaposto manca dal messaggio l'elenco viene aggiunto in fondo, così i documenti scelti arrivano sempre. Link e non file allegato: email leggera, niente limiti di dimensione SMTP, documento sempre aggiornato. I file cancellati dalla Media Library vengono saltati. L'interfaccia avvisa che i file della Media Library sono pubblici e non vanno usati per documenti riservati. Funziona anche con "Invia Test".

### 2.13.0 — Invii con cache di pagina, reCAPTCHA cache-safe, allegati protetti

- **Invii falliti con cache di pagina:** per i visitatori anonimi il nonce stampato nell'HTML scadeva dopo 12–24h e ogni invio veniva rifiutato con 403. Ora gli anonimi vengono verificati con controllo di origine (`Origin`, in mancanza `Referer`, dello stesso host del sito) più un limite globale di invii per IP (hash salato, filter `dbfb_submit_rate_limit` / `dbfb_submit_rate_window`); gli utenti loggati restano sul nonce. Il JavaScript non fallisce più in silenzio: `console.warn` e messaggio specifico (richiesta non valida, sessione scaduta, troppi invii).
- **reCAPTCHA compatibile con la cache:** il consenso non viene più valutato al render (la decisione finiva nella cache e valeva per tutti). Markup sempre uguale, script Google caricato da `frontend.js` solo dopo il consenso (`window.DBCM.hasConsent`, eventi `dbcm:consent` / `dbcm:ready`, WP Consent API), senza ricaricare la pagina. La categoria del filter `dbfb_recaptcha_category` arriva al client e al testo del placeholder (prima "marketing" era fisso). Lato server il token è sempre richiesto quando reCAPTCHA è attivo: senza token l'invio è rifiutato con "Per inviare il modulo accetta i cookie necessari alla verifica anti-spam". `should_load_recaptcha()` ed `enqueue_form_dependencies()` restano per compatibilità ma sono deprecate.
- **Schema DB saltato alla riattivazione:** `maybe_create_table()` segnava lo schema come aggiornato anche quando la tabella esisteva già senza migrazioni, e gli INSERT con le colonne del consenso fallivano in silenzio. Ora la versione si segna solo per tabelle create da zero; nuovo schema v5 che riesegue una volta tutti i passi di migrazione (idempotenti) per riparare le installazioni colpite. Se la tabella manca (mu-plugin, attivazione saltata) viene creata al primo `admin_init`.
- **Allegati non più pubblici:** nuovi file con prefisso casuale di 24 caratteri, `.htaccess` "deny all" su `uploads/dbfb/` (Apache 2.2 + 2.4, senza `Options` che poteva dare 500), download solo da admin con capability, nonce e controllo `realpath`. Pagina Risposte, CSV ed email di notifica all'admin usano il nuovo link; l'email di conferma all'utente mostra solo il nome del file. I webhook ricevono un link pubblico firmato (HMAC) e a scadenza (`admin-post.php?action=dbfb_file`, 7 giorni, filter `dbfb_webhook_file_link_ttl` e `dbfb_webhook_signed_file_links`), rigenerato a ogni tentativo di consegna: le chiavi del payload restano invariate. Su Nginx serve la regola `location` indicata nell'avviso admin.
- **Registro trattamenti:** il testo diceva che gli allegati stavano "nella Media Library con visibilità privata", cosa mai vera. Ora descrive la protezione reale.
- **Disinstallazione:** vengono tolti anche il cron `dbfb_cleanup_deliveries` e i `dbfb_webhook_dispatch` in coda; in modalità Hard viene eliminata anche la tabella `wp_dbfb_webhook_deliveries`. Rimosso il controllo `current_user_can()`, che con WP-CLI faceva uscire lo script senza pulire nulla.
- **Cron deliveries:** pianificato in modo idempotente anche su `admin_init`, non solo all'attivazione.
- **Registro consensi Hub:** l'export leggeva solo `_internal_limit` e si fermava a 1000 righe anche quando l'Hub ne chiedeva 50.000; ora usa `limit`. Il filtro per interessato usa `esc_like` (`%` e `_` letterali).
- **DSAR:** l'eraser ripartiva da capo a ogni chiamata e, con oltre 5000 falsi positivi del `LIKE`, non terminava mai; ora il cursore si conserva fra le chiamate e c'è un tetto di sicurezza. L'export etichettava sempre l'IP come "hash" anche quando era in chiaro, e ora include la prova del consenso (testo, data, informativa, versione della policy). Gli allegati sono esportati per nome.
- **Submit:** accettati solo ID di form `dbfb_form` pubblicati.
- **Componenti condivisi:** aggiunti l'auto-updater GitHub (`inc/class-updater.php`) e il design system `db-admin-ui.css` (dipendenza di `admin.css`). Header del plugin con il blocco "Privacy capabilities".

### 2.12.0 — Colori personalizzabili + segnaposto email cliccabili

- **Aspetto:** sfondo, colore dei pulsanti e colore del testo del form si impostano in Impostazioni (globale) e per singolo form, con anteprima e avviso di contrasto. Testo e hover dei pulsanti e colore dei link vengono calcolati per restare WCAG AA. Il CSS del frontend usa ora variabili (`--dbfb-*`) al posto dei colori fissi; i valori predefiniti sono quelli di prima, quindi i form esistenti non cambiano aspetto.
- **Segnaposto email per id campo:** nuovo formato `{campo:id}`, che non si rompe se l'etichetta viene rinominata. Il formato derivato dall'etichetta (`{nome}`) resta supportato; se due campi hanno la stessa etichetta vale il primo.
- **Segnaposto cliccabili:** sotto ogni email dell'editor c'è l'elenco dei campi reali del form e dei segnaposto generali; un clic li inserisce nel punto del cursore.
- **Traduzioni frontend:** i messaggi mostrati dal JavaScript del form (errori, file, reCAPTCHA, avanzamento multi-step) sono ora traducibili. L'avanzamento multi-step usa lo stesso testo del PHP ("Passo 1 di 3") invece di "1 / 3".
- **Escape nella lista file:** il nome del file nell'`aria-label` del pulsante "Rimuovi" è ora escapato correttamente anche per le virgolette.

### 2.11.2 — Fix sicurezza e privacy

- **CSRF sull'eliminazione dei form:** il nonce era verificato solo se presente nella richiesta; un link senza `_wpnonce` aperto da un amministratore cancellava form e risposte. Ora il nonce è obbligatorio.
- **Cancellazione di file arbitrari in `uploads/`:** il server accettava nei dati inviati anche chiavi che non corrispondevano a campi del form (e valori forgiati per i campi file), che la pulizia degli allegati trattava poi come file da cancellare. Ora le chiavi sconosciute e i valori dei campi file inviati dal client vengono scartati, e la cancellazione degli allegati agisce solo sui campi di tipo file e solo dentro `uploads/dbfb/`.
- **reCAPTCHA saltato senza consenso:** quando il captcha è configurato ma non caricato per mancanza di consenso, il limite invii per IP diventa obbligatorio.
- **Pagina Risposte:** lo script del dettaglio/selezione multipla non partiva per un apostrofo non escapato in una stringa tradotta. Tutte le stringhe inserite nel JavaScript (e nei `confirm()`) passano ora da `esc_js`.
- **Impostazioni privacy non salvate:** modalità di salvataggio IP, retention e cancellazione dati alla disinstallazione non venivano inviate al salvataggio e tornavano ai valori predefiniti. Ora vengono salvate.
- **DSAR (art. 17):** l'eraser paginava con `OFFSET` sulla stessa tabella da cui cancellava e saltava righe oltre le prime 100. Ora scorre i candidati con un cursore sull'id.
- **Email in testo semplice:** i testi delle email venivano salvati con `wp_kses_post`, che converte `&` in `&amp;` e lascia passare i tag HTML: in un'email text/plain comparivano letterali. Ora vengono salvati come testo semplice e quelli già salvati vengono ripuliti all'invio.
- **Segnaposto espansi a catena:** la sostituzione avveniva con `str_replace` in sequenza, quindi un valore scritto dall'utente che conteneva un segnaposto (es. `{ip}`) veniva a sua volta sostituito. Ora la sostituzione avviene in un solo passaggio (`strtr`).
- **Mittente vuoto:** se l'email del mittente nelle impostazioni era vuota o non valida veniva generato `From: Nome <>` e tutte le email fallivano. Ora in quel caso si usa il mittente predefinito di WordPress.
- **Webhook deliveries:** eliminazione del form, eliminazione singola/multipla/totale delle risposte, retention ed eraser DSAR cancellano ora anche le deliveries collegate, che contengono il payload completo della submission. L'eliminazione del form rimuove anche gli allegati su disco. L'id della submission collegato alla delivery viene letto subito dopo l'INSERT (prima poteva essere sovrascritto da plugin SMTP che loggano su DB).

### 2.11.1 — Fix sicurezza: sanitizzazione submission + escaping modale

- **XSS immagazzinato (importante):** i valori inviati dai form vengono ora sanitizzati lato server per tipo di campo (email → `sanitize_email`, textarea → `sanitize_textarea_field`, resto → `sanitize_text_field`) prima di essere salvati, inviati via email/webhook o mostrati nella dashboard. Prima venivano salvati grezzi: un valore contenente markup poteva eseguire script nella pagina admin delle risposte.
- **Escaping modale risposte:** nel dettaglio di una submission, gli URL degli allegati sono ora filtrati (solo `http/https`; schemi come `javascript:` neutralizzati) e le label dei campi sono escapate prima dell'inserimento nel DOM (difesa in profondità).
- **Rate-limit dietro proxy:** con `dbfb_trust_proxy_headers` attivo, da `X-Forwarded-For` viene ora letto l'ultimo hop (scritto dal proxy fidato) invece del primo (controllabile dal client). Prima un visitatore poteva iniettare un IP fittizio in testa alla catena e aggirare il rate limit ruotando indirizzi falsi.
- **CSV injection nell'export:** le celle dell'export CSV che iniziano con `= + - @` (o TAB/CR) vengono prefissate con un apice, così non vengono interpretate come formule da Excel/LibreOffice/Sheets all'apertura. Un valore inviato via form pubblico come `=HYPERLINK(...)` non è più eseguibile nel foglio scaricato.
- **Honeypot time-trap firmato:** il campo temporale anti-bot ora emette un token firmato con HMAC (timestamp + firma verificata server-side) invece di un timestamp in chiaro. Un bot non può più forgiare un valore per superare il controllo "inviato troppo in fretta". Retrocompatibile: i form serviti da cache di versioni precedenti non vengono bloccati (restano coperti da honeypot + rate limit).
- **Validazione MIME reale degli upload:** gli allegati vengono ora verificati sul contenuto reale (`wp_check_filetype_and_ext`, magic bytes via finfo), non solo sull'estensione del nome. File camuffati (es. uno script rinominato `.pdf`) o con contenuto che non corrisponde all'estensione vengono rifiutati; i MIME eseguibili sono bloccati a prescindere.
- **Hardening cartella upload:** l'`.htaccess` della cartella allegati è stato rafforzato (blocca anche `.svg`, `.html`, forza `text/plain` sugli script) e viene riscritto in modo idempotente anche sulle installazioni esistenti. Su server non-Apache (Nginx), un admin notice dismissibile ricorda la regola `location` equivalente da aggiungere alla config del server.
- **CI/QA:** aggiunti GitHub Actions: lint PHP su 7.4 e 8.3 (tutti i file) e PHPCS con ruleset WPCS (`phpcs.xml.dist`) sulla logica applicativa (`inc/`). Aggiunto workflow di release che builda lo ZIP con la cartella `db-form-builder/` e lo allega alla Release (richiesto dall'auto-updater). Aggiunti header `Requires PHP` / `Requires at least`.

### 2.11.0 — Prova del consenso GDPR (art. 7.1) + Registro consensi unificato

Estensione che chiude il cerchio dell'accountability sul consenso ai form: ogni submission registra ora la prova esplicita del consenso ricevuto, e contribuisce al **Registro consensi** unificato del DB Privacy Hub 1.3.0+.

**Salvataggio prova del consenso nelle submission:**
- 5 nuove colonne nella tabella `wp_dbfb_submissions`:
  - `gdpr_consent_given` (1 = consenso prestato, 0 = form intenzionalmente senza checkbox, NULL = non documentato)
  - `gdpr_consent_text` — il testo esatto del consenso che l'utente ha letto
  - `gdpr_consent_timestamp` — timestamp del consenso (DATETIME)
  - `gdpr_consent_privacy_url` — URL dell'informativa linkata al checkbox
  - `gdpr_consent_policy_version` — ID dello snapshot Privacy Hub in vigore al momento (0 se Hub assente)
- Vista submission admin: nuovo blocco "Consenso GDPR" con tre stati visivi (verde "documentato", arancione "intenzionale", rosso "non documentato"). Il blocco mostra timestamp, testo letto, URL informativa, versione documento esatta linkata al Privacy Hub se installato.

**Migrazione automatica:**
- Schema submissions v3 → v4: aggiunte le 5 colonne via `ALTER TABLE` idempotente. Le righe pre-2.11.0 hanno tutte le colonne consenso a NULL e nella vista admin sono marcate "Consenso non documentato (versione precedente)".
- Indice su `gdpr_consent_policy_version` per ricerche efficienti dal Registro consensi del Hub.

**Filter `dbph_consents_register`:**
- Form Builder dichiara la propria fonte di consensi al Privacy Hub via questo filter pubblico. La pagina `Privacy → Registro consensi` dell'Hub include automaticamente i consensi modulistici nella vista unificata insieme ai consensi cookie.
- Solo le submission con `gdpr_consent_given=1` sono visibili nel registro Hub: scelta architetturale per mostrare solo dati che soddisfano effettivamente l'obbligo di prova del consenso (art. 7.1 GDPR). Le submission pre-2.11.0 e quelle senza checkbox NON appaiono nel registro unificato.

**Comportamento standalone preservato:**
- Senza Privacy Hub: il consenso viene comunque salvato nelle submission, ma `gdpr_consent_policy_version=0`. Il sito è pienamente conforme al GDPR art. 7.1 anche senza Hub.
- Con Privacy Hub 1.3.0+: il consenso è linkato all'ID esatto dello snapshot Privacy Policy. Audit trail completo.

**Nessun breaking change.** Le submission esistenti continuano a funzionare. La logica della checkbox GDPR sul frontend è invariata.

### 2.10.0 — Conformità consenso GDPR by default

Rafforzamento della conformità GDPR sui form pubblici: la checkbox di consenso al trattamento dei dati personali diventa attiva di default nei nuovi form, e l'admin viene avvisato proattivamente quando esistono form pubblicati che non la richiedono.

**Default `enable_gdpr` invertito:**
- Per i nuovi form, la checkbox di consenso GDPR è attiva di default. Era disattiva nelle versioni precedenti.
- I form esistenti **non vengono modificati**: i loro setting salvati nel DB hanno priorità sul nuovo default. L'inversione si applica solo ai form ancora senza setting persistiti (cioè non aperti in editor).
- Nessuna submission persa, nessun comportamento esistente cambiato senza azione esplicita dell'admin.

**Nuovo flag per-form `gdpr_intentionally_disabled`:**
- Quando l'admin disattiva la checkbox GDPR, l'editor mostra un blocco di conferma giallo con spunta "Confermo: questo form non richiede il consenso GDPR (scelta consapevole)".
- Spuntando, il form viene marcato come scelta esplicita (es. base giuridica diversa: esecuzione contratto, legittimo interesse, autenticazione utente).
- I form così marcati **non compaiono** nell'admin notice di compliance.

**Admin notice di conformità (nuova classe `DBFB_GDPR_Compliance_Notice`):**
- Quando uno o più form pubblicati hanno consenso disattivato senza essere dichiarati come scelta consapevole, un notice arancione segnala il problema.
- Visibile **solo nelle pagine admin del Form Builder** (no rumore su altri plugin).
- Lista compatta dei form interessati con link diretto all'editor (max 5, poi "e altri N").
- Si nasconde automaticamente quando tutti i form sono o conformi o esplicitamente esenti.

**Registro privacy arricchito:**
- La voce `dbfb_submissions` nel registro Privacy Hub ora include il riepilogo dello stato consenso: quanti form richiedono consenso, quanti sono esenti per scelta, quanti sono potenzialmente non conformi.
- Visibile nell'Hub e nella Privacy Policy generata.

**Nessun breaking change.** I form esistenti continuano a funzionare esattamente come nella 2.9.0; le nuove protezioni si attivano solo dove non c'erano già.

### 2.9.0 — Integrazione DB Privacy Hub

Allineamento con il nuovo plugin **DB Privacy Hub**, che dalla v1.0.0 è il punto di raccolta unificato per le dichiarazioni privacy dell'ecosistema DB e per il routing delle richieste DSAR.

**Privacy declarations migrate al filter unificato:**
- `DBFB_Privacy_Declarations` ora si aggancia sia al filter `dbph_processing_register` (Privacy Hub, canonico) sia al filter legacy `dbseo_processing_register` (SEO Manager 1.2.x). Stessa callback per entrambi: il Privacy Hub gestisce automaticamente la dedup per id, quindi non si generano voci duplicate quando entrambi i plugin sono presenti.
- Quando il SEO Manager passa alla 1.3.0 il filter legacy non esiste più e l'unico canale è quello Hub. Nessuna azione richiesta dall'utente.

**DSAR a doppio canale:**
- `DBFB_Privacy_DSAR` registra exporter ed eraser sia via filter dell'Hub (`dbph_user_data_exporters` / `dbph_user_data_erasers`) sia via filter core di WordPress (`wp_privacy_personal_data_exporters` / `wp_privacy_personal_data_erasers`).
- Il fallback core scatta solo se l'Hub non è installato (check `class_exists('DBPH_DSAR')`). In presenza dell'Hub è lui a ribaltare le dichiarazioni sui filter core, evitando doppia registrazione.
- Comportamento standalone preservato: il plugin resta pienamente conforme alle DSAR di WordPress anche senza l'Hub, esattamente come nella 2.8.0.

**Marker `DBFB_DSAR_AVAILABLE`:**
- Nuova costante `DBFB_DSAR_AVAILABLE` definita in `db-form-builder.php`. Letta dal Privacy Hub (`DBPH_Policy_Generator::has_dbfb_dsar()`) per inserire la menzione "procedura DSAR semplificata" nella sezione "Diritti dell'interessato" della Privacy Policy generata.
- La funzionalità DSAR esiste dalla 2.5.0; la 2.9.0 la rende ispezionabile dall'esterno tramite costante.

**Nessun breaking change.** Il plugin funziona identicamente sia in standalone, sia con SEO Manager 1.2.x, sia con Privacy Hub 1.0.0+.

### 2.8.0 — Trasparenza informativa privacy + CSV header esplicativi

Chiude la roadmap iniziale 2.x con due miglioramenti complementari sulla trasparenza delle informazioni privacy.

**Informativa privacy per singolo form** — il setting `gdpr_link` esistente (con fallback automatico a `get_privacy_policy_url()` di WordPress) è ora propagato in tutti i punti che servono per audit e conformità GDPR:
- **Nuovo placeholder `{privacy_url}` nelle email**: utile per email di conferma utente del tipo "I tuoi dati sono trattati come da informativa: {privacy_url}".
- **Campo `privacy_url` nel payload webhook**: il destinatario (CRM, Zapier, ecc.) sa a quale informativa l'utente ha dato il consenso. Importante per audit GDPR lato consumer.
- **Registro privacy SEO Manager arricchito**: la voce "Salvataggio invii moduli" ora indica esplicitamente quanti form usano un'informativa specifica vs quella globale di WordPress, e segnala se nessuna informativa è configurata (warning per conformità).

**CSV export header esplicativo (#13):**
- Modalità `hashed`: header colonna IP diventa `IP (hash SHA-256)` invece del generico `IP`. Risolve confusione di chi apre il CSV in Excel e vede 64 caratteri hex senza spiegazione.
- Modalità `none`: la colonna IP **viene omessa completamente** dal CSV (niente da esportare).
- Modalità `full`: comportamento invariato, header `IP`.

### 2.7.0 — Webhook reliability (retry async + HMAC signing)

I webhook passano da fire-and-forget a delivery-guaranteed: invio asincrono via WordPress cron, retry automatici per errori transient, autenticazione del payload via HMAC SHA-256.

**Reliability:**
- **Invio asincrono**: il submit handler enqueua la delivery in una nuova tabella `wp_dbfb_webhook_deliveries` e schedula `wp_schedule_single_event` immediato. Il submit non blocca mai l'utente in attesa di un endpoint lento.
- **Retry con exponential backoff**: 5 tentativi totali, intervalli 1m → 5m → 30m → 2h → 12h. Per errori transient: timeout di rete, HTTP 5xx, 408 Request Timeout, 429 Too Many Requests.
- **Errori permanenti**: HTTP 4xx (eccetto 408/429) marcati `failed` immediatamente senza retry. È pointless ritentare un 400 Bad Request.
- **Stato `dead`**: deliveries che esauriscono i 5 tentativi vengono marcate `dead` e restano visibili per ispezione e retry manuale.
- **Nuova pagina admin**: `Form Builder → Webhook Deliveries` con filtri per stato (pending/success/failed/dead), stats counter, retry/cancellazione bulk, ultimo errore + status code visibile.

**Security:**
- **HMAC signing opt-in**: setting `Webhook Secret` per form. Se valorizzato, ogni POST include header `X-DBFB-Signature: sha256=<hmac>` calcolato come `hmac_sha256(timestamp + "." + body, secret)`. Pattern industry-standard (Stripe, GitHub).
- **Replay protection**: header `X-DBFB-Timestamp` (Unix epoch). Il destinatario può rifiutare richieste con timestamp più vecchio di N minuti.
- **Bottone "Genera"**: produce un secret crypto-strong via `window.crypto.getRandomValues()` (64 char hex).
- Headers extra sempre presenti: `X-DBFB-Delivery-Id`, `X-DBFB-Attempt` (per debug/idempotency lato destinatario).

**Schema upgrade**: nuova tabella `wp_dbfb_webhook_deliveries` (schema v2 → v3). Migrazione automatica al primo `admin_init` post-update.

**Backward compat**: form esistenti con webhook senza secret continuano a funzionare. Il payload JSON è invariato (stesso formato 2.6.x).

### 2.6.0 — Snapshot fields per submission

Risolve il bug latente delle submission rese illeggibili dalla modifica del form. Le submission salvate dalla 2.6.0 in poi conservano la propria definizione dei campi (id/type/label) al momento del submit.

- **Salvataggio snapshot al submit**: oltre ai valori, viene salvato un array `_fields_snapshot` nel JSON `data` della submission con `[{id, type, label}, ...]` per ogni campo non-layout. Niente schema upgrade DB (chiave riservata in JSON, come `path` per gli attachment 2.4.0).
- **Helper `DB_Form_Builder::get_submission_fields()`**: ritorna i field corretti per una submission. Usa lo snapshot se presente (post-2.6.0), altrimenti fallback ai field correnti del form (legacy 2.5.x).
- **Helper `DB_Form_Builder::build_submission_columns()`**: per la UI tabella e CSV export — calcola l'unione di tutti i field apparsi in qualsiasi submission + campi correnti del form, con dedup e label preferita = quella più recente.
- **UI Submissions**: header costruito dall'unione, ogni riga renderizza con il proprio snapshot. Le celle "campo aggiunto al form dopo questa submission" hanno styling distintivo (italic, grigio, tooltip).
- **CSV export**: stesso approccio — header unione, ogni riga riempie le sue colonne, le altre vuote.
- **DSAR exporter**: usa lo snapshot della submission (rispetta la coerenza temporale anche nelle richieste GDPR art. 15).
- **DSAR matching**: cerca il campo email anche tra quelli dello snapshot (utile se un campo email è stato successivamente rimosso dal form).
- **Webhook payload**: `_fields_snapshot` rimosso dal `raw_data` per non sporcare i destinatari con metadati interni del plugin.

**Backward compat**: le submission pre-2.6.0 funzionano esattamente come prima (fallback automatico).

### 2.5.0 — DSAR WordPress (art. 15 + 17 GDPR)

Integra il Form Builder con la macchina nativa di WordPress per le richieste di accesso (art. 15) e cancellazione (art. 17) dati personali via email. Quando un utente effettua una DSAR tramite `Strumenti → Esporta dati personali` o `Strumenti → Cancella dati personali`, le sue submission vengono trovate e processate automaticamente.

- **Nuovo modulo `DBFB_Privacy_DSAR`**: registra i due callback nativi WordPress:
  - `wp_privacy_personal_data_exporters` → ritorna tutte le submission che contengono l'email richiesta in un campo di tipo email del form, formattate come dati strutturati per lo ZIP scaricabile.
  - `wp_privacy_personal_data_erasers` → cancella le stesse submission insieme ai loro file allegati (riusa `delete_submission_files()` 2.4.0).
- **Strategia di matching**: pre-filtro SQL via `LIKE` per ridurre le righe da parsare, poi check fine in PHP che verifica match esatto (case-insensitive) sul valore di un campo `type=email` del form. Niente falsi positivi (es. l'email che appare nel testo libero di un campo "Messaggio").
- **Paginazione automatica**: WordPress chiama il callback con `$page` incrementale; il plugin processa 100 submission per chiamata. Funziona anche su database con migliaia di submission.
- **Filter `dbfb_dsar_email_field_ids`**: permette di estendere i campi considerati come "email" (utile per form legacy con campi `text` usati per email).
- **Robustezza**: tabella inesistente, JSON corrotto, email malformata → no error, `done=true` immediato.

### 2.4.0 — Gestione allegati pulita

Risolve il limite documentato della 2.3.1: ora la cancellazione di una submission cancella automaticamente anche i file allegati dal disco.

- **Nuovo helper `DB_Form_Builder::delete_submission_files($submission)`**: cancella i file allegati di una submission dal filesystem. Usato in tutti i path di cancellazione: delete singola, bulk delete, "Cancella TUTTE", cron retention, uninstall HARD.
- **Schema submission JSON aggiornato (additivo)**: i nuovi upload salvano anche `path` (relativo a `wp_upload_dir basedir`) accanto a `url`/`name`/`size`. Le submission esistenti (2.3.x) restano supportate via fallback su URL.
- **Path-traversal hardening**: `resolve_attachment_path()` valida che il path risolto stia sotto `wp_upload_dir basedir`. Submission con `..` nel path o URL fuori dominio vengono rifiutate silenziosamente. Backslash Windows-style normalizzati.
- **Cron retention con streaming**: cancella in batch da 200 (vs DELETE singolo della 2.3.x) per cancellare anche i file. Cap di sicurezza 10000 mantenuto. Action `dbfb_cleanup_submissions_done` ora riporta anche `$files_deleted`.
- **Uninstall HARD**: cancella i file allegati di tutte le submission, poi DROP table, poi cancella il CPT, poi rimuove le sottocartelle vuote di `wp-content/uploads/dbfb/` e i file di sicurezza (`.htaccess`, `index.php`).

**Compatibilità con submission 2.3.x esistenti**: completamente preservata. Il helper deriva il path dall'URL salvato.

### 2.3.1 — Uninstall opt-in

Aggiunge un meccanismo di cancellazione dati alla disinstallazione, opt-in con default sicuro.

- **Nuovo `uninstall.php`** che gestisce due modalità:
  - **Soft (default)**: rimuove option del plugin, transient di rate limit, disschedula il cron. Lascia intatte tabella submissions, form definiti, allegati. Pensata per disinstallazioni temporanee (debug, switch versione, migrazione hosting).
  - **Hard (opt-in)**: tutto quanto sopra + DROP della tabella `wp_dbfb_submissions` + cancellazione di tutti i post di tipo `dbfb_form` (con i loro post meta). Cancellazione in batch da 50 per non saturare la memoria.
- **Nuovo setting** `Form Builder → Impostazioni → Privacy → Cancellazione dati alla disinstallazione` (default OFF). Quando attivato, l'admin vede un alert giallo che spiega le conseguenze.
- **Multisite-safe**: in modalità multisite, ripulisce anche i site transient su `sitemeta`.
- **Nota nota tecnica**: in questa versione gli allegati nella Media Library NON vengono cancellati anche in modalità Hard (il plugin oggi salva URL+nome ma non `attachment_id`). Risolto in 2.4.0 con schema upgrade.

### 2.3.0 — Privacy by design + ecosistema DB

Allineamento al pattern dell'ecosistema DB (DB Cookie Manager 3.0.2 + DB SEO Manager 1.2.0). Tutte le modifiche sono additive: il plugin funziona standalone come prima.

- **Privacy hardening core**:
  - Nuova modalità `ip_storage_mode` (none/hashed/full), default `hashed` (SHA-256 + salt, irreversibile).
  - `get_client_ip()` riscritto secure-by-default: ignora i proxy header salvo `add_filter('dbfb_trust_proxy_headers', '__return_true')`.
  - Validazione IP via `FILTER_VALIDATE_IP` (rifiuta input malformati).
  - Schema versionato (`SCHEMA_VERSION = 2`) con `maybe_upgrade_schema()` just-in-time: aggiunge colonna `ip_hash` + indice `submitted_at` su installazioni esistenti senza perdere dati.
  - Rate limit usa hash dell'IP come chiave del transient (funziona anche in modalità `none`).
  - Webhook payload e placeholder email `{ip}` rispettano la modalità configurata.
- **Retention automatica**: nuovo cron giornaliero `dbfb_cleanup_submissions` con setting `submissions_retention_days` (default 365, range 0-3650). Cap di sicurezza 10000 righe/esecuzione. Pulsante "Pulisci ora" dalla UI. Action hook `dbfb_cleanup_submissions_done`.
- **Privacy declarations**: nuovo modulo `DBFB_Privacy_Declarations` che dichiara dinamicamente fino a 4 trattamenti al filter `dbseo_processing_register` del SEO Manager (submissions, email, recaptcha, webhooks). Inerte se SEO Manager non installato.
- **Consent gate per reCAPTCHA**: nuovo metodo `should_load_recaptcha($form_settings)` con strategia a 6 livelli (Hard Privacy → captcha config → chiavi → filter → Cookie Manager / WP Consent API → backward compat). Lazy enqueue dello script `google-recaptcha`: caricato solo on-render dello shortcode/widget se il gate lo permette. Placeholder informativo lato frontend quando il gate blocca, con link "modifica preferenze cookie" se Cookie Manager attivo. Reload automatico su `dbcm:consent` quando arriva consenso marketing. Filter `dbfb_recaptcha_consent_required` e `dbfb_recaptcha_category` per casi avanzati.
- **UI submissions**: nuova colonna IP con hash troncato `aaaaaaaa…bbbb` + tooltip esplicativo (3 stati distinguibili: hash / IP legacy / non registrato). Helper unificato `format_submission_ip()` riusato anche da CSV export. Nuovo bottone **"🗑️ Cancella TUTTE (N)"** nella toolbar bulk-actions per il diritto all'oblio (art. 17 GDPR).
- **Fix minori**: `error_log()` di errori DB ora wrapped in `WP_DEBUG` check (no rumore in produzione).

### 2.2.0
- Aggiunto: Logica condizionale (mostra/nascondi campi, 8 operatori, AND/OR)
- Aggiunto: Upload file con drag & drop (estensioni, dimensione max, multipli, validazione client + server)
- Aggiunto: Form multi-step con barra di progresso, navigazione, validazione per step
- Aggiunto: Webhook POST JSON a URL esterno (compatibile Zapier, Make, n8n)
- Aggiunto: Tipo campo "Cambio pagina" per dividere form in step
- Aggiunto: Reset automatico form dopo invio (fade messaggio + ritorno step 1)
- Migliorato: Refactor codice — da 1 file monolite a 8 classi in `inc/`
- Migliorato: Submit form usa FormData (supporto file binari)
- Migliorato: Sicurezza upload — blacklist estensioni, wp_check_filetype, .htaccess anti-PHP

### 2.1.0
- Aggiunto: Conformità WCAG 2.1 AA completa
- Aggiunto: aria-required, aria-invalid, aria-describedby, fieldset/legend
- Aggiunto: Focus management, focus trap, focus-visible
- Aggiunto: prefers-reduced-motion, forced-colors
- Aggiunto: Touch target 44×44px, contrasto ≥ 4.5:1

### 2.0.0
- Aggiunto: Duplicazione form
- Aggiunto: Anteprima form nel builder
- Aggiunto: Eliminazione singole risposte e bulk delete
- Aggiunto: Honeypot anti-spam
- Aggiunto: Checkbox GDPR/privacy
- Aggiunto: Rate limiting per IP
- Aggiunto: Notifiche admin a più destinatari

### 1.3.0
- Aggiunto: 5 template predefiniti
- Aggiunto: Blocco Gutenberg e widget classico
- Aggiunto: Test email e reCAPTCHA nelle impostazioni
- Fix: Headers already sent, reCAPTCHA v3 URL, creazione tabella DB

### 1.0.0
- Release iniziale

## Requisiti

- WordPress 5.8+
- PHP 7.4+

## Struttura

```
db-form-builder/
├── db-form-builder.php              # Bootstrap
├── uninstall.php                    # Cancellazione dati opt-in (2.3.1+)
├── README.md
├── inc/
│   ├── class-core.php               # Singleton, hooks, CPT, menu, scripts, routing, IP/cron helpers
│   ├── class-builder.php            # Form builder, save, sanitize, templates
│   ├── class-submit.php             # Submit, honeypot, GDPR, reCAPTCHA gating, file upload, webhook
│   ├── class-submissions.php        # Risposte, CSV export, download protetto allegati
│   ├── class-submissions-repository.php # Accesso unico alla tabella risposte: query, stato, cancellazione, subject_hash (2.19.0+)
│   ├── class-links.php              # Collegamenti risposta → oggetti esterni, es. ordini (2.24.0+)
│   ├── class-roles.php              # Capability dbfb_view_submissions e ruolo "Gestore form" (2.20.0+)
│   ├── class-rest.php               # API REST dbfb/v1 (2.20.0+)
│   ├── class-app.php                # Web app: rotte /dbfb-app/, shell, manifest, service worker (2.21.0+)
│   ├── class-web-push.php           # Web Push senza librerie: VAPID, cifratura RFC 8291 (2.22.0+)
│   ├── class-notifications.php      # Notifiche: push, ntfy, Telegram, polling /updates (2.22.0+)
│   ├── openssl.cnf                  # Configurazione OpenSSL minima per i server che ne sono privi (2.22.0+)
│   ├── woo/
│   │   ├── class-woo.php            # WooCommerce: ordini del cliente, ordini del negozio (2.23.0+, solo con Woo attivo)
│   │   └── class-woo-order-builder.php # Ordine da una risposta: bozza, creazione, link di pagamento (2.24.0+)
│   ├── class-email.php              # Placeholder, invio email, test
│   ├── class-prefill.php            # Precompilazione da URL: tipi ammessi, parametri (2.15.0+)
│   ├── class-consents.php           # Consensi facoltativi: prova, revoca, DSAR, registro Hub (2.16.0+)
│   ├── class-attribution.php        # Provenienza della campagna: chiavi UTM, sanitizzazione, segnaposto (2.17.0+)
│   ├── class-import-export.php      # Export / import di un form come file JSON (2.18.0+)
│   ├── class-settings.php           # Impostazioni globali, test reCAPTCHA/email, cleanup AJAX
│   ├── class-appearance.php         # Colori del frontend come variabili CSS (2.12.0+)
│   ├── class-gdpr-compliance-notice.php # Avviso form senza consenso (2.10.0+)
│   ├── class-updater.php            # GitHub auto-updater (componente condiviso, 2.13.0+)
│   ├── class-privacy-declarations.php # Registro trattamenti + sorgente consensi per il Privacy Hub
│   ├── class-privacy-dsar.php       # WP DSAR exporter + eraser (art. 15 + 17, 2.5.0+)
│   ├── class-webhook.php            # Webhook async + retry + HMAC (2.7.0+)
│   ├── class-gutenberg.php          # Blocco Gutenberg
│   └── class-widget.php             # Widget classico
├── assets/
│   ├── css/
│   │   ├── db-admin-ui.css          # Design system condiviso dei plugin DB (2.13.0+)
│   │   ├── admin.css
│   │   └── frontend.css
│   ├── app/                         # Web app (2.21.0+): app.js, app.css, sw.js, icone
│   └── js/
│       ├── admin.js                 # Builder, condizionale, file settings
│       ├── frontend.js              # Submit, condizionale, precompilazione, eventi, file drag&drop, multi-step
│       └── gutenberg-block.js
└── templates/
    ├── app/
    │   └── shell.php                # Shell HTML della web app (2.21.0+)
    ├── admin/
    │   ├── app.php                  # Pagina "Web app" (2.21.0+)
    │   ├── forms-list.php
    │   ├── form-builder.php
    │   ├── settings.php
    │   ├── submissions.php
    │   ├── submissions-list.php
    │   └── webhook-deliveries.php
    └── frontend/
        └── form.php
```
