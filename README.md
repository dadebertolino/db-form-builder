# DB Form Builder

Plugin WordPress per la creazione di form con drag & drop, logica condizionale, upload file, multi-step e webhook.

**Autore:** Davide Bertolino  
**Sito:** [https://www.davidebertolino.it](https://www.davidebertolino.it)  
**Email:** info@davidebertolino.it  
**Licenza:** GPL v2 or later

## Funzionalità

- **Form Builder drag & drop** — 14 tipi di elemento con riordino visuale
  - Input: Testo, Email, Textarea, Select, Checkbox, Radio, Telefono, Numero, Data, URL, Upload file
  - Contenuti: Testo/HTML statico, Immagine, Separatore
  - Struttura: Cambio pagina (multi-step)
- **Logica condizionale** — Mostra/nascondi campi in base alle risposte (8 operatori, AND/OR)
- **Upload file** — Drag & drop o click, estensioni configurabili, dimensione max per campo, file multipli, validazione client + server
- **Form multi-step** — Barra di progresso, navigazione avanti/indietro, validazione per step
- **Webhook** — POST JSON a URL esterno dopo ogni invio (compatibile Zapier, Make, n8n)
- **Protezione anti-spam** — Google reCAPTCHA v2/v3 (consent-gated 2.3.0+) + Honeypot invisibile
- **Privacy by design (2.3.0+)**:
  - IP hashato SHA-256 di default (modalità configurabile: hash / nessuno / chiaro)
  - Retention automatica delle submission (cron giornaliero, default 365 giorni)
  - Right of erasure GDPR art. 17 (cancellazione singola/bulk/totale dalla UI)
  - Trust dei proxy header solo via filter esplicito (anti-spoofing)
  - Dichiarazione automatica dei trattamenti al DB SEO Manager
- **GDPR / Privacy** — Checkbox consenso obbligatorio con link alla Privacy Policy
- **Limite invii per IP** — Configurabile per form (max N invii in X minuti), funziona anche con IP hashato/non salvato
- **Email personalizzabili** — Conferma utente + notifica admin (più destinatari) con placeholder dinamici
- **Gestione risposte** — Dettaglio modale, elimina singole/bulk/tutte, export CSV, file come link scaricabili
- **Duplica form** — Copia campi e impostazioni con un click
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

### Right of erasure (art. 17)

Pagina submissions di un form: tre meccanismi:
1. **"Elimina"** singola riga (già nella 2.2.0)
2. **"Elimina selezionate"** dopo bulk-select (già nella 2.2.0)
3. **🗑️ "Cancella TUTTE (N)"** per cancellare in un click tutte le risposte del form (nuovo in 2.3.0). Conferma esplicita con il numero di righe.

In tutti e tre i casi, i file allegati vengono cancellati dal disco insieme alla riga DB (2.4.0+).

### Right of access + erasure via email (DSAR — art. 15 + 17)

A partire dalla 2.5.0, il Form Builder è integrato con la macchina nativa di WordPress per le DSAR. L'admin trova in `Strumenti → Esporta dati personali` e `Strumenti → Cancella dati personali` un'interfaccia che permette, data un'email:

- **Esporta dati**: WP genera uno ZIP scaricabile contenente tutte le submission che hanno quell'email come valore di un campo di tipo email. Include nome del form, data invio, valori dei campi, nomi e URL degli allegati, IP (rispetta storage mode).
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

**Soft (default, checkbox OFF):** alla disinstallazione vengono rimossi solo le option del plugin, i transient di rate limit, e lo scheduling del cron. **Restano in DB** la tabella delle submission, i form definiti (CPT `dbfb_form`) e gli allegati nella Media Library. Pensato per disinstallazioni temporanee — se reinstalli, ritrovi tutto.

**Hard (checkbox ON):** alla disinstallazione viene fatto `DROP TABLE wp_dbfb_submissions`, vengono cancellati tutti i post di tipo `dbfb_form` (con i relativi post meta), tutti i file allegati delle submission dal disco, le sottocartelle vuote in `wp-content/uploads/dbfb/`, i file di sicurezza (`.htaccess`, `index.php`) e le option del plugin. Operazione **irreversibile**. Quando attivi il checkbox, l'UI mostra un alert giallo che riepiloga cosa accadrà.

## Integrazione con DB Cookie Manager, DB Privacy Hub, DB SEO Manager

Quando uno o più di questi plugin sono installati, il Form Builder li sfrutta automaticamente — senza configurazione.

### Consent gate per reCAPTCHA

Lo script Google reCAPTCHA viene caricato solo se l'utente ha dato consenso `marketing` (o se il sito non ha alcun consent manager). Quando il consenso manca:
- Il widget reCAPTCHA è sostituito da un **placeholder informativo** che invita a modificare le preferenze cookie.
- Il submit-side resta protetto da rate limit + honeypot (sempre attivi).
- Quando l'utente accetta `marketing`, la pagina si ricarica automaticamente (listener su `dbcm:consent`) e il widget compare.

Compatibile con: **DB Cookie Manager 3.0.0+**, qualsiasi plugin che esponga `wp_has_consent()` (Cookiebot, Complianz, Real Cookie Banner via WP Consent API). Per siti senza consent manager, il comportamento è identico alla 2.2.0 (carica sempre).

Filter per casi avanzati:
```php
// Disabilitare il consent gate (admin scelta esplicita)
add_filter('dbfb_recaptcha_consent_required', '__return_false');

// Cambiare la categoria di consenso richiesta (default: 'marketing')
add_filter('dbfb_recaptcha_category', function() { return 'functional'; });
```

Quando **DB SEO Manager Hard Privacy** è attivo, reCAPTCHA è disattivato in modo incondizionato (coerente con il significato di "Hard Privacy").

### Dichiarazione trattamenti al registro privacy unificato

Quando **DB Privacy Hub 1.0.0+** è installato, il Form Builder dichiara automaticamente fino a 4 trattamenti nel pannello "Privacy → Registro trattamenti" e li propaga nella Privacy Policy generata. Per retrocompatibilità, lo stesso aggancio funziona anche con il vecchio **DB SEO Manager 1.2.x** (dalla 1.3.0 il SEO Manager non gestisce più il registro privacy: la responsabilità è migrata nell'Hub).

| ID | Quando appare |
|---|---|
| `dbfb_submissions` | almeno 1 form pubblicato |
| `dbfb_email_notifications` | almeno 1 form ha notifica admin o conferma utente |
| `dbfb_recaptcha` | reCAPTCHA configurato globalmente E almeno 1 form lo usa |
| `dbfb_webhooks` | almeno 1 form ha webhook attivo (con elenco host destinatari deduplicato) |

Le voci sono **dinamiche**: riflettono in tempo reale `ip_storage_mode`, `submissions_retention_days`, host webhook configurati. L'admin può copiare i testi nelle proprie informative privacy.

### DSAR routing via Privacy Hub

Quando il Privacy Hub è installato, le DSAR (richieste di accesso e cancellazione) del Form Builder vengono registrate via i filter `dbph_user_data_exporters` / `dbph_user_data_erasers`, non direttamente sui filter core di WordPress. Questo permette all'Hub di tracciare ogni richiesta nel suo "Storico DSAR" (tabella `wp_dbph_dsar_log`) con timestamp di richiesta, conferma e completamento.

Senza l'Hub, il Form Builder si registra direttamente sui filter core di WordPress (`wp_privacy_personal_data_exporters/erasers`), come faceva nella 2.8.0. Comportamento standalone identico, niente regressioni.

### Marker `DBFB_DSAR_AVAILABLE`

Costante definita in `db-form-builder.php` (`define('DBFB_DSAR_AVAILABLE', true)`). Letta dal Privacy Hub per decidere se inserire la menzione "procedura DSAR semplificata via Strumenti → Esporta/Cancella dati personali" nella sezione "Diritti dell'interessato" della Privacy Policy generata.

## Filter pubblici

| Filter | Default | Uso |
|---|---|---|
| `dbfb_trust_proxy_headers` | `false` | Fidati di X-Forwarded-For/CF-Connecting-IP/X-Real-IP |
| `dbfb_recaptcha_consent_required` | `true` | Gate reCAPTCHA al consenso (false = scarica sempre) |
| `dbfb_recaptcha_category` | `'marketing'` | Categoria di consenso richiesta per reCAPTCHA |
| `dbfb_dsar_email_field_ids` | `[]` (auto) | Estende i campi considerati come "email" per il matching DSAR (2.5.0+) |

Action: `dbfb_cleanup_submissions_done($deleted, $days)` — emessa dopo ogni esecuzione del cron retention.

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
- I file vengono salvati in `wp-content/uploads/dbfb/{form_id}/`

### Multi-step
Trascina "Cambio pagina" tra i campi per dividere il form in step. Il frontend mostra automaticamente barra di progresso, bottoni Indietro/Avanti e validazione per step.

### Webhook (2.7.0+: async, retry, HMAC)

Attivabile per form. Inserisci l'URL e ad ogni invio il plugin fa un POST JSON con `form_id`, `form_title`, `submitted_at`, `ip`, `fields` (array con id, label, type, value) e `raw_data`. Compatibile con Zapier, Make, n8n, endpoint custom.

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

### Email
Configura il mittente nelle Impostazioni globali. Personalizza oggetto e messaggio per ogni form. Più destinatari admin separati da virgola. Placeholder:
- `{form_titolo}` — Nome del form
- `{riepilogo_dati}` — Tutti i campi compilati
- `{nome}`, `{email}`, ecc. — Singoli campi
- `{ip}`, `{data}`, `{sito}`
- `{privacy_url}` — URL informativa privacy del form (con fallback a quella globale WP) — **2.8.0+**

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

### Risposte
Form Builder > Risposte — dettaglio modale, elimina singola/bulk, export CSV

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

### 2.11.1 — Fix sicurezza: sanitizzazione submission + escaping modale

- **XSS immagazzinato (importante):** i valori inviati dai form vengono ora sanitizzati lato server per tipo di campo (email → `sanitize_email`, textarea → `sanitize_textarea_field`, resto → `sanitize_text_field`) prima di essere salvati, inviati via email/webhook o mostrati nella dashboard. Prima venivano salvati grezzi: un valore contenente markup poteva eseguire script nella pagina admin delle risposte.
- **Escaping modale risposte:** nel dettaglio di una submission, gli URL degli allegati sono ora filtrati (solo `http/https`; schemi come `javascript:` neutralizzati) e le label dei campi sono escapate prima dell'inserimento nel DOM (difesa in profondità).
- **Rate-limit dietro proxy:** con `dbfb_trust_proxy_headers` attivo, da `X-Forwarded-For` viene ora letto l'ultimo hop (scritto dal proxy fidato) invece del primo (controllabile dal client). Prima un visitatore poteva iniettare un IP fittizio in testa alla catena e aggirare il rate limit ruotando indirizzi falsi.
- **CSV injection nell'export:** le celle dell'export CSV che iniziano con `= + - @` (o TAB/CR) vengono prefissate con un apice, così non vengono interpretate come formule da Excel/LibreOffice/Sheets all'apertura. Un valore inviato via form pubblico come `=HYPERLINK(...)` non è più eseguibile nel foglio scaricato.
- **Honeypot time-trap firmato:** il campo temporale anti-bot ora emette un token firmato con HMAC (timestamp + firma verificata server-side) invece di un timestamp in chiaro. Un bot non può più forgiare un valore per superare il controllo "inviato troppo in fretta". Retrocompatibile: i form serviti da cache di versioni precedenti non vengono bloccati (restano coperti da honeypot + rate limit).
- **Validazione MIME reale degli upload:** gli allegati vengono ora verificati sul contenuto reale (`wp_check_filetype_and_ext`, magic bytes via finfo), non solo sull'estensione del nome. File camuffati (es. uno script rinominato `.pdf`) o con contenuto che non corrisponde all'estensione vengono rifiutati; i MIME eseguibili sono bloccati a prescindere.
- **Hardening cartella upload:** l'`.htaccess` della cartella allegati è stato rafforzato (blocca anche `.svg`, `.html`, forza `text/plain` sugli script) e viene riscritto in modo idempotente anche sulle installazioni esistenti. Su server non-Apache (Nginx), un admin notice dismissibile ricorda la regola `location` equivalente da aggiungere alla config del server.

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

- WordPress 5.0+
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
│   ├── class-submissions.php        # Risposte, CSV export
│   ├── class-email.php              # Placeholder, invio email, test
│   ├── class-settings.php           # Impostazioni globali, test reCAPTCHA/email, cleanup AJAX
│   ├── class-privacy-declarations.php # Dichiarazioni al registro privacy SEO Manager (2.3.0+)
│   ├── class-privacy-dsar.php       # WP DSAR exporter + eraser (art. 15 + 17, 2.5.0+)
│   ├── class-webhook.php            # Webhook async + retry + HMAC (2.7.0+)
│   ├── class-gutenberg.php          # Blocco Gutenberg
│   └── class-widget.php             # Widget classico
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── frontend.css
│   └── js/
│       ├── admin.js                 # Builder, condizionale, file settings
│       ├── frontend.js              # Submit, condizionale, file drag&drop, multi-step
│       └── gutenberg-block.js
└── templates/
    ├── admin/
    │   ├── forms-list.php
    │   ├── form-builder.php
    │   ├── settings.php
    │   ├── submissions.php
    │   └── submissions-list.php
    └── frontend/
        └── form.php
```
