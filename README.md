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
- **Protezione anti-spam** — Google reCAPTCHA v2/v3 + Honeypot invisibile
- **GDPR / Privacy** — Checkbox consenso obbligatorio con link alla Privacy Policy
- **Limite invii per IP** — Configurabile per form (max N invii in X minuti)
- **Email personalizzabili** — Conferma utente + notifica admin (più destinatari) con placeholder dinamici
- **Gestione risposte** — Dettaglio modale, elimina singole/bulk, export CSV, file come link scaricabili
- **Duplica form** — Copia campi e impostazioni con un click
- **Anteprima** — Visualizza il form nel builder prima di pubblicare
- **Template predefiniti** — 5 modelli pronti all'uso
- **Integrazione WordPress** — Shortcode, blocco Gutenberg, widget classico
- **Accessibilità WCAG 2.1 AA** — ARIA completo, focus management, contrasto, reduced motion, high contrast mode

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

### Webhook
Attivabile per form. Inserisci l'URL e ad ogni invio il plugin fa un POST JSON con form_id, form_title, submitted_at, ip, fields (array con id, label, type, value) e raw_data. Compatibile con Zapier, Make, n8n, endpoint custom.

### Email
Configura il mittente nelle Impostazioni globali. Personalizza oggetto e messaggio per ogni form. Più destinatari admin separati da virgola. Placeholder:
- `{form_titolo}` — Nome del form
- `{riepilogo_dati}` — Tutti i campi compilati
- `{nome}`, `{email}`, ecc. — Singoli campi
- `{ip}`, `{data}`, `{sito}`

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
├── README.md
├── inc/
│   ├── class-core.php               # Singleton, hooks, CPT, menu, scripts, routing
│   ├── class-builder.php            # Form builder, save, sanitize, templates
│   ├── class-submit.php             # Submit, honeypot, GDPR, reCAPTCHA, file upload, webhook
│   ├── class-submissions.php        # Risposte, CSV export
│   ├── class-email.php              # Placeholder, invio email, test
│   ├── class-settings.php           # Impostazioni globali, test reCAPTCHA/email
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
