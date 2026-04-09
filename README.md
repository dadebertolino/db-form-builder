# DB Form Builder

Plugin WordPress per la creazione di form con drag & drop.

**Autore:** Davide Bertolino  
**Sito:** [https://www.davidebertolino.it](https://www.davidebertolino.it)  
**Email:** info@davidebertolino.it  
**Licenza:** GPL v2 or later

## Funzionalità

- **Form Builder drag & drop** - Trascina i campi per costruire il form
- **13 tipi di elemento:**
  - Input: Testo, Email, Textarea, Select, Checkbox, Radio, Telefono, Numero, Data, URL
  - Contenuti: Testo/HTML statico, Immagine, Separatore
- **Protezione anti-spam** - Google reCAPTCHA v2/v3 + Honeypot invisibile
- **GDPR / Privacy** - Checkbox consenso obbligatorio con link alla Privacy Policy
- **Limite invii per IP** - Controlla spam limitando invii per indirizzo IP
- **Email personalizzabili** - Conferma utente e notifica admin con placeholder dinamici
- **Notifiche multiple** - Invio a più destinatari admin separati da virgola
- **Test email** - Verifica le email prima di pubblicare
- **Gestione risposte** - Visualizza, dettaglio modale, elimina singole o in blocco
- **Duplica form** - Crea copie di form esistenti con un click
- **Anteprima form** - Visualizza il form nel builder prima di pubblicare
- **Export CSV** - Esporta i dati in CSV (compatibile Excel)
- **Template predefiniti** - 5 template pronti all'uso
- **Shortcode** - Inserisci i form ovunque con `[dbfb_form id="X"]`
- **Blocco Gutenberg** - Inserisci i form dall'editor blocchi
- **Widget classico** - Per sidebar e footer

## Installazione

1. Carica la cartella `db-form-builder` in `/wp-content/plugins/`
2. Attiva il plugin dal menu Plugin
3. Vai su "Form Builder" nel menu admin

## Configurazione

### reCAPTCHA
1. Vai su Form Builder > Impostazioni
2. Inserisci Site Key e Secret Key da [Google reCAPTCHA](https://www.google.com/recaptcha/admin)
3. Abilita il CAPTCHA singolarmente per ogni form

### Honeypot (alternativa a reCAPTCHA)
Attivabile per singolo form. Aggiunge un campo nascosto che solo i bot compilano + verifica tempo di compilazione. Nessun impatto visivo per gli utenti.

### GDPR
Per ogni form puoi attivare un checkbox obbligatorio di consenso privacy, con testo personalizzabile e link alla tua Privacy Policy.

### Limite invii per IP
Configurabile per form: massimo N invii in X minuti dallo stesso IP.

### Email
Configura il mittente nelle Impostazioni globali. Personalizza oggetto e messaggio per ogni form usando i placeholder:
- `{form_titolo}` - Nome del form
- `{riepilogo_dati}` - Tutti i campi compilati
- `{nome}`, `{email}`, ecc. - Singoli campi
- `{ip}`, `{data}`, `{sito}`

Per notifiche a più admin, separa le email con virgola.

## Utilizzo

### Creare un form
1. Vai su Form Builder > Nuovo Form
2. Scegli un template o inizia da zero
3. Trascina i campi dalla sidebar al canvas
4. Configura etichette, placeholder e opzioni
5. Imposta sicurezza (honeypot, GDPR, rate limit)
6. Imposta email di conferma e notifiche
7. Usa "Anteprima" per verificare il risultato
8. Salva e copia lo shortcode

### Inserire nel sito
```
[dbfb_form id="123"]
```

### Duplicare un form
Dalla lista form, clicca "Duplica" per creare una copia.

### Visualizzare risposte
Form Builder > Risposte oppure dalla lista form > Risposte

### Eliminare risposte
Dalla pagina risposte: elimina singolarmente o usa "Seleziona tutti" + "Elimina selezionate"

### Esportare dati
Dalla pagina risposte, clicca "Esporta CSV"

## Changelog

### 2.0.0
- Aggiunto: Duplicazione form
- Aggiunto: Anteprima form nel builder
- Aggiunto: Eliminazione singole risposte
- Aggiunto: Eliminazione massiva risposte (bulk)
- Aggiunto: Dettaglio risposta in modale
- Aggiunto: Honeypot anti-spam (campo nascosto + timestamp)
- Aggiunto: Checkbox GDPR/privacy obbligatorio
- Aggiunto: Limite invii per IP (rate limiting)
- Aggiunto: Notifiche admin a più destinatari
- Migliorato: Conferma eliminazione form più esplicita
- Migliorato: Campi statici esclusi da CSV e riepilogo email

### 1.3.0
- Fix: Headers already sent su eliminazione form
- Fix: reCAPTCHA v3 script URL con render=SITE_KEY
- Fix: Creazione tabella DB just-in-time
- Aggiunto: Template predefiniti (5)
- Aggiunto: Blocco Gutenberg
- Aggiunto: Widget classico
- Aggiunto: Test email e reCAPTCHA nelle impostazioni
- Aggiunto: Impostazioni globali email

### 1.0.0
- Release iniziale

## Requisiti

- WordPress 5.0+
- PHP 7.4+

## Struttura

```
db-form-builder/
├── db-form-builder.php          # File principale
├── README.md
├── assets/
│   ├── css/
│   │   ├── admin.css            # Stili admin
│   │   └── frontend.css         # Stili frontend
│   └── js/
│       ├── admin.js             # Drag & drop builder + anteprima
│       ├── frontend.js          # Invio AJAX form + honeypot
│       └── gutenberg-block.js   # Blocco Gutenberg
└── templates/
    ├── admin/
    │   ├── forms-list.php       # Lista form + duplica
    │   ├── form-builder.php     # Editor + anteprima + sicurezza
    │   ├── settings.php         # Impostazioni globali
    │   ├── submissions.php      # Risposte + elimina + dettaglio
    │   └── submissions-list.php # Lista risposte per form
    └── frontend/
        └── form.php             # Rendering form + honeypot + GDPR
```
