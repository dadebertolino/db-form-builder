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
- **Protezione anti-spam** - Google reCAPTCHA v3 (configurabile per form)
- **Email personalizzabili** - Conferma utente e notifica admin con placeholder dinamici
- **Test email** - Verifica le email prima di pubblicare
- **Gestione risposte** - Visualizza tutte le risposte per ogni form
- **Export CSV** - Esporta i dati in CSV (compatibile Excel)
- **Shortcode** - Inserisci i form ovunque con `[dbfb_form id="X"]`

## Installazione

1. Carica la cartella `db-form-builder` in `/wp-content/plugins/`
2. Attiva il plugin dal menu Plugin
3. Vai su "Form Builder" nel menu admin

## Configurazione

### reCAPTCHA
1. Vai su Form Builder > Impostazioni
2. Inserisci Site Key e Secret Key da [Google reCAPTCHA](https://www.google.com/recaptcha/admin)
3. Abilita il CAPTCHA singolarmente per ogni form

### Email
Configura il mittente nelle Impostazioni globali. Personalizza oggetto e messaggio per ogni form usando i placeholder:
- `{form_titolo}` - Nome del form
- `{riepilogo_dati}` - Tutti i campi compilati
- `{nome}`, `{email}`, ecc. - Singoli campi
- `{ip}`, `{data}`, `{sito}`

## Utilizzo

### Creare un form
1. Vai su Form Builder > Nuovo Form
2. Trascina i campi dalla sidebar al canvas
3. Configura etichette, placeholder e opzioni
4. Imposta email di conferma e notifiche
5. Salva e copia lo shortcode

### Inserire nel sito
```
[dbfb_form id="123"]
```

### Visualizzare risposte
Form Builder > Tutti i Form > Risposte

### Esportare dati
Dalla pagina risposte, clicca "Esporta CSV"

## Requisiti

- WordPress 5.0+
- PHP 7.4+

## Struttura

```
db-form-builder/
├── db-form-builder.php     # File principale
├── assets/
│   ├── css/
│   │   ├── admin.css       # Stili admin
│   │   └── frontend.css    # Stili frontend
│   └── js/
│       ├── admin.js        # Drag & drop builder
│       └── frontend.js     # Invio AJAX form
└── templates/
    ├── admin/
    │   ├── forms-list.php   # Lista form
    │   ├── form-builder.php # Editor drag & drop
    │   ├── settings.php     # Impostazioni globali
    │   └── submissions.php  # Risposte
    └── frontend/
        └── form.php         # Rendering form
```
