<?php
/**
 * DBFB_Privacy_Declarations — Dichiarazione dei trattamenti privacy del
 * Form Builder verso il registro privacy unificato dell'ecosistema DB.
 *
 * Pattern dell'ecosistema DB: ogni plugin DB dichiara i propri trattamenti
 * via il filter `dbph_processing_register` del DB Privacy Hub. Il Privacy
 * Hub raccoglie tutte le dichiarazioni e le mostra nella sua pagina
 * "Registro trattamenti" + le include nella Privacy Policy generata.
 *
 * COMPATIBILITÀ: dalla 2.9.0 il filter di riferimento è cambiato da
 * `dbseo_processing_register` (SEO Manager 1.2.x) a `dbph_processing_register`
 * (DB Privacy Hub). Per non rompere installazioni con SEO Manager 1.2.x
 * ancora attivo, ci agganciamo a entrambi: l'Hub stesso ribalta i contenuti
 * del filter legacy quando è installato. Quando il SEO Manager passa a 1.3.0
 * il filter legacy non esiste più e l'unico canale è dbph_processing_register.
 *
 * Filosofia:
 *  - Il Form Builder conosce SOLO i propri trattamenti. Non sa cosa fa
 *    il Cookie Manager o altri plugin.
 *  - Né il Privacy Hub né il SEO Manager sono obbligatori: se nessuno dei
 *    due è installato, nessun filter scatta e questa classe è inerte.
 *  - I trattamenti dichiarati sono dinamici: ispezioniamo i form
 *    pubblicati per capire quali features sono effettivamente usate
 *    (email, reCAPTCHA, webhook). Se nessun form usa i webhook,
 *    non dichiariamo il trattamento webhook — coerente con la realtà.
 *
 * Pensato per essere il modello da seguire per altri plugin DB futuri
 * che salvano PII (CRM, prenotazioni, registrazioni eventi, ecc.).
 *
 * @package DBFB
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('DBFB_Privacy_Declarations')) {

    class DBFB_Privacy_Declarations {

        /**
         * Cache dell'introspezione dei form, valida per il singolo request.
         *
         * Evitiamo di interrogare get_posts() più volte se il filter venisse
         * chiamato N volte nello stesso request (cosa che WP fa raramente
         * ma può capitare con alcuni plugin che rigenerano il registro).
         *
         * @var array|null
         */
        private static $form_features_cache = null;

        /**
         * Inizializzazione — chiamata da DB_Form_Builder->__construct().
         *
         * Si aggancia al filter unificato del Privacy Hub e, per compatibilità
         * retroattiva, anche al filter legacy del SEO Manager 1.2.x. Quando
         * SEO Manager 1.3.0+ rimuove il filter legacy, solo il primo è attivo.
         * Il Privacy Hub gestisce automaticamente la dedup per id, quindi
         * l'aggancio doppio non genera voci duplicate nel registro finale.
         */
        public static function init() {
            add_filter('dbph_processing_register',  array(__CLASS__, 'declare_processing'), 10, 1);
            add_filter('dbseo_processing_register', array(__CLASS__, 'declare_processing'), 10, 1);

            // 2.11.0: dichiara la fonte "consensi modulistici" al Privacy Hub
            // via il filter dbph_consents_register. Inerte se l'Hub non è
            // installato.
            add_filter('dbph_consents_register', array(__CLASS__, 'declare_consents_source'));
        }

        /* =================================================================
         * INTEGRAZIONE PRIVACY HUB — REGISTRO CONSENSI (2.11.0)
         * =============================================================== */

        /**
         * Dichiara la fonte "consensi form" al filter `dbph_consents_register`.
         *
         * Solo le submission con consenso esplicitamente documentato
         * (gdpr_consent_given=1) sono visibili nel registro: in linea con la
         * scelta architetturale di mostrare solo dati che soddisfano l'obbligo
         * di prova del consenso (art. 7.1 GDPR). Le submission pre-2.11.0 e
         * quelle senza checkbox attivata NON appaiono.
         */
        public static function declare_consents_source($sources) {
            $sources['dbfb_form_consents'] = array(
                'label'  => __('Form Builder — Consensi modulistici', 'db-form-builder'),
                'icon'   => 'feedback',
                'count'  => array(__CLASS__, 'hub_count_consents'),
                'query'  => array(__CLASS__, 'hub_query_consents'),
                'export' => array(__CLASS__, 'hub_query_consents'),
            );
            return $sources;
        }

        public static function hub_count_consents($args = array()) {
            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_submissions';
            list($where_sql, $params) = self::build_hub_consents_where($args);

            if (!empty($params)) {
                return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where_sql}", $params));
            }
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where_sql}");
        }

        public static function hub_query_consents($args = array()) {
            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_submissions';
            list($where_sql, $params) = self::build_hub_consents_where($args);

            $limit = isset($args['_internal_limit']) ? (int) $args['_internal_limit'] : 1000;
            $limit = max(1, min(50000, $limit));

            $sql = "SELECT id, form_id, data, submitted_at, gdpr_consent_text, gdpr_consent_timestamp, gdpr_consent_privacy_url, gdpr_consent_policy_version FROM {$table} {$where_sql} ORDER BY gdpr_consent_timestamp DESC LIMIT {$limit}";
            $rows = !empty($params)
                ? $wpdb->get_results($wpdb->prepare($sql, $params))
                : $wpdb->get_results($sql);

            $form_titles_cache = array();
            $out = array();
            foreach ((array) $rows as $r) {
                // Titolo form (cache per request).
                if (!isset($form_titles_cache[$r->form_id])) {
                    $form = get_post((int) $r->form_id);
                    $form_titles_cache[$r->form_id] = $form ? $form->post_title : sprintf(__('Form #%d', 'db-form-builder'), (int) $r->form_id);
                }
                $form_title = $form_titles_cache[$r->form_id];

                // Estrae l'email dalla submission (best-effort, se presente).
                $subject_label = '—';
                $data_decoded = json_decode((string) $r->data, true);
                if (is_array($data_decoded)) {
                    foreach ($data_decoded as $field_value) {
                        if (is_string($field_value) && is_email($field_value)) {
                            $subject_label = self::mask_email($field_value);
                            break;
                        }
                    }
                }

                $out[] = array(
                    'id'             => 'dbfb-sub-' . (int) $r->id,
                    'timestamp'      => $r->gdpr_consent_timestamp ?: $r->submitted_at,
                    'subject'        => $subject_label,
                    'consent_type'   => sprintf(__('form: %s', 'db-form-builder'), $form_title),
                    'consent_text'   => (string) $r->gdpr_consent_text,
                    'policy_version' => (int) $r->gdpr_consent_policy_version,
                    'extra'          => array(
                        'submission_id'    => (int) $r->id,
                        'form_id'          => (int) $r->form_id,
                        'form_title'       => $form_title,
                        'privacy_url'      => (string) $r->gdpr_consent_privacy_url,
                    ),
                );
            }
            return $out;
        }

        /**
         * WHERE clause per query Hub.
         * SOLO submission con consenso documentato (gdpr_consent_given=1).
         * Le submission pre-2.11.0 (NULL) e quelle senza consenso (0) sono escluse.
         *
         * @return array{0:string,1:array}
         */
        private static function build_hub_consents_where($args) {
            $where  = array('gdpr_consent_given = 1');
            $params = array();

            if (!empty($args['date_from'])) {
                $where[] = 'gdpr_consent_timestamp >= %s';
                $params[] = $args['date_from'] . ' 00:00:00';
            }
            if (!empty($args['date_to'])) {
                $where[] = 'gdpr_consent_timestamp <= %s';
                $params[] = $args['date_to'] . ' 23:59:59';
            }
            if (!empty($args['subject'])) {
                // Match parziale sul JSON `data` (best-effort).
                $where[] = 'data LIKE %s';
                $params[] = '%' . (string) $args['subject'] . '%';
            }

            return array('WHERE ' . implode(' AND ', $where), $params);
        }

        /**
         * Maschera un'email per visualizzazione: mar***@example.com.
         */
        private static function mask_email($email) {
            $email = (string) $email;
            $at = strpos($email, '@');
            if ($at === false || $at < 1) return '***';
            $local  = substr($email, 0, $at);
            $domain = substr($email, $at + 1);
            $masked = strlen($local) <= 2
                ? str_repeat('*', strlen($local))
                : substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 2)) . substr($local, -1);
            return $masked . '@' . $domain;
        }

        /**
         * Dichiara i trattamenti del Form Builder.
         *
         * Ogni voce segue il contratto del filter `dbph_processing_register`
         * (vedi DBPH_Register::collect() nel DB Privacy Hub). Lo stesso
         * contratto era usato dal vecchio filter `dbseo_processing_register`
         * del SEO Manager 1.2.x. Le voci vengono marcate `_source = 'external'`
         * dal Privacy Hub.
         *
         * @param array $register Registro corrente (può contenere voci di
         *                         altri plugin che hanno già hookato).
         * @return array
         */
        public static function declare_processing($register) {
            if (!is_array($register)) {
                $register = array();
            }

            // Se il plugin non è ancora completamente caricato (improbabile
            // ma possibile durante upgrade), saltiamo silenziosamente.
            if (!class_exists('DB_Form_Builder')) {
                return $register;
            }

            $features = self::analyze_forms();
            $global   = DB_Form_Builder::get_global_settings();

            /* ---- 1. Submissions storage (sempre se ci sono form) ----------- */
            // Se non ci sono form pubblicati, non c'è alcun trattamento attivo.
            // L'admin potrebbe aver disinstallato tutti i form ma lasciato il
            // plugin attivo: non dichiariamo trattamenti fantasma.
            if ($features['has_published_forms']) {
                $register[] = self::build_submissions_entry($global, $features);
            }

            /* ---- 2. Email notifications ----------------------------------- */
            if ($features['has_email']) {
                $register[] = self::build_email_entry($global);
            }

            /* ---- 3. reCAPTCHA --------------------------------------------- */
            if ($features['has_recaptcha']) {
                $register[] = self::build_recaptcha_entry($global);
            }

            /* ---- 4. Webhook ----------------------------------------------- */
            if ($features['has_webhook']) {
                $register[] = self::build_webhook_entry($features['webhook_hosts']);
            }

            return $register;
        }

        /**
         * Ispeziona i form pubblicati per determinare quali features sono
         * effettivamente in uso.
         *
         * @return array {
         *     @type bool  has_published_forms
         *     @type bool  has_email      send_admin_notification o send_confirmation
         *     @type bool  has_recaptcha  enable_captcha + chiavi globali presenti
         *     @type bool  has_webhook    enable_webhook + url valido
         *     @type array webhook_hosts  hostnames dei webhook configurati
         * }
         */
        private static function analyze_forms() {
            if (self::$form_features_cache !== null) {
                return self::$form_features_cache;
            }

            $features = array(
                'has_published_forms' => false,
                'has_email'           => false,
                'has_recaptcha'       => false,
                'has_webhook'         => false,
                'webhook_hosts'       => array(),
                // 2.8.0: traccia se almeno un form ha un'informativa privacy
                // specifica (gdpr_link valorizzato sul form, non solo il
                // fallback globale di WP). Esposto nel registro privacy.
                'has_specific_privacy_notice' => false,
                'forms_with_specific_notice'  => 0,
                // 2.10.0: conteggio form senza checkbox GDPR. Distingue tra
                // form non conformi (default mancato, da segnalare) e form
                // intenzionalmente disattivati (scelta consapevole). Esposti
                // nel registro privacy come informazione di compliance.
                'forms_total'                 => 0,
                'forms_without_gdpr'          => 0,
                'forms_gdpr_intentional_off'  => 0,
            );

            $forms = get_posts(array(
                'post_type'      => 'dbfb_form',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ));

            if (empty($forms)) {
                return self::$form_features_cache = $features;
            }

            $features['has_published_forms'] = true;

            // reCAPTCHA è una feature globale: serve solo se le chiavi sono
            // configurate. Se mancano, anche un form con enable_captcha=true
            // non lo carica realmente.
            $global = DB_Form_Builder::get_global_settings();
            $recaptcha_configured = !empty($global['recaptcha_site_key']) && !empty($global['recaptcha_secret_key']);

            foreach ($forms as $form_id) {
                $settings = get_post_meta($form_id, '_dbfb_settings', true);
                if (!is_array($settings)) continue;

                if (!empty($settings['send_admin_notification']) || !empty($settings['send_confirmation'])) {
                    $features['has_email'] = true;
                }
                if ($recaptcha_configured && !empty($settings['enable_captcha'])) {
                    $features['has_recaptcha'] = true;
                }
                if (!empty($settings['enable_webhook']) && !empty($settings['webhook_url'])) {
                    $features['has_webhook'] = true;
                    $host = wp_parse_url($settings['webhook_url'], PHP_URL_HOST);
                    if ($host && !in_array($host, $features['webhook_hosts'], true)) {
                        $features['webhook_hosts'][] = $host;
                    }
                }
                // 2.8.0: rileva form con informativa privacy specifica.
                if (!empty($settings['enable_gdpr']) && !empty($settings['gdpr_link'])) {
                    $features['has_specific_privacy_notice'] = true;
                    $features['forms_with_specific_notice']++;
                }
                // 2.10.0: conteggio form senza checkbox GDPR.
                $features['forms_total']++;
                if (empty($settings['enable_gdpr'])) {
                    if (!empty($settings['gdpr_intentionally_disabled'])) {
                        $features['forms_gdpr_intentional_off']++;
                    } else {
                        $features['forms_without_gdpr']++;
                    }
                }
            }

            return self::$form_features_cache = $features;
        }

        /**
         * Costruisce la voce per il trattamento "Salvataggio submission".
         *
         * Riflette dinamicamente la modalità IP storage e la retention
         * configurate, così l'admin vede SEMPRE quello che il plugin sta
         * realmente facendo nel registro privacy.
         */
        private static function build_submissions_entry($global, $features = array()) {
            $ip_mode = $global['ip_storage_mode'] ?? 'hashed';
            $retention_days = (int) ($global['submissions_retention_days'] ?? 365);

            $ip_label = self::ip_mode_label($ip_mode);

            if ($retention_days > 0) {
                $retention_text = sprintf(
                    /* translators: %d: giorni di retention */
                    __('Cancellazione automatica dopo %d giorni (cron giornaliero, configurabile da Form Builder → Impostazioni → Privacy).', 'db-form-builder'),
                    $retention_days
                );
            } else {
                $retention_text = __('Nessuna cancellazione automatica (retention illimitata configurata). Sconsigliato per GDPR art. 5.1.e.', 'db-form-builder');
            }

            // 2.8.0: descrizione dei link informativa privacy in uso.
            $privacy_notice_text = '';
            if (!empty($features['has_specific_privacy_notice'])) {
                $privacy_notice_text = sprintf(
                    /* translators: %d: numero di form con informativa specifica */
                    _n(
                        '%d form ha un\'informativa privacy dedicata (linkata accanto al checkbox di consenso). Gli altri form usano l\'informativa globale di WordPress.',
                        '%d form hanno un\'informativa privacy dedicata (linkata accanto al checkbox di consenso). Gli altri form usano l\'informativa globale di WordPress.',
                        $features['forms_with_specific_notice'],
                        'db-form-builder'
                    ),
                    $features['forms_with_specific_notice']
                );
            } elseif (function_exists('get_privacy_policy_url') && get_privacy_policy_url()) {
                $privacy_notice_text = __('Tutti i form usano l\'informativa privacy globale del sito (configurata in Impostazioni → Privacy di WordPress).', 'db-form-builder');
            } else {
                $privacy_notice_text = __('Nessuna informativa privacy configurata: né a livello globale (Impostazioni → Privacy di WordPress) né per singolo form. Si raccomanda di configurarne almeno una per la conformità GDPR.', 'db-form-builder');
            }

            // 2.10.0: stato della checkbox di consenso GDPR sui form pubblicati.
            // Distingue tra form non conformi (consenso disattivato senza scelta
            // consapevole) e form intenzionalmente esenti (base giuridica diversa).
            $consent_status_text = '';
            $forms_total = (int) ($features['forms_total'] ?? 0);
            $forms_no_gdpr = (int) ($features['forms_without_gdpr'] ?? 0);
            $forms_intent_off = (int) ($features['forms_gdpr_intentional_off'] ?? 0);
            $forms_with_gdpr = $forms_total - $forms_no_gdpr - $forms_intent_off;

            if ($forms_total > 0) {
                $parts = array();
                if ($forms_with_gdpr > 0) {
                    $parts[] = sprintf(
                        /* translators: %d: numero form con consenso */
                        _n(
                            '%d form richiede il consenso esplicito dell\'utente tramite checkbox',
                            '%d form richiedono il consenso esplicito dell\'utente tramite checkbox',
                            $forms_with_gdpr,
                            'db-form-builder'
                        ),
                        $forms_with_gdpr
                    );
                }
                if ($forms_intent_off > 0) {
                    $parts[] = sprintf(
                        /* translators: %d: numero form senza consenso, intenzionali */
                        _n(
                            '%d form opera senza checkbox di consenso per scelta consapevole dell\'amministratore (base giuridica diversa: contratto, legittimo interesse o autenticazione)',
                            '%d form operano senza checkbox di consenso per scelta consapevole dell\'amministratore (base giuridica diversa: contratto, legittimo interesse o autenticazione)',
                            $forms_intent_off,
                            'db-form-builder'
                        ),
                        $forms_intent_off
                    );
                }
                if ($forms_no_gdpr > 0) {
                    $parts[] = sprintf(
                        /* translators: %d: numero form non conformi */
                        _n(
                            'ATTENZIONE: %d form non richiede il consenso e non è stato dichiarato come scelta consapevole — situazione potenzialmente non conforme GDPR, da rivedere',
                            'ATTENZIONE: %d form non richiedono il consenso e non sono stati dichiarati come scelta consapevole — situazione potenzialmente non conforme GDPR, da rivedere',
                            $forms_no_gdpr,
                            'db-form-builder'
                        ),
                        $forms_no_gdpr
                    );
                }
                $consent_status_text = ' ' . implode('. ', $parts) . '.';
            }

            return array(
                'id'             => 'dbfb_submissions',
                'label'          => __('Salvataggio invii moduli (DB Form Builder)', 'db-form-builder'),
                'status'         => 'active',
                'purpose'        => __('Conservare le submissions ricevute dai moduli pubblicati sul sito per consentire all\'amministratore di leggerle, esportarle in CSV e dare seguito alle richieste degli utenti.', 'db-form-builder'),
                'legal_basis'    => __('Consenso esplicito dell\'interessato (art. 6.1.a GDPR) raccolto tramite checkbox del modulo. Per moduli senza checkbox GDPR esplicita: legittimo interesse (art. 6.1.f) limitato alla gestione della comunicazione richiesta dall\'utente.', 'db-form-builder'),
                'data_collected' => sprintf(
                    /* translators: 1: descrizione modalità IP, 2: descrizione informativa privacy, 3: stato consenso GDPR sui form */
                    __('Tutti i campi compilati dall\'utente (nome, email, messaggio, eventuali allegati e altri campi configurati nel form), timestamp dell\'invio, %1$s. Gli allegati sono salvati nella Media Library di WordPress con visibilità privata di default. Le richieste di accesso (art. 15 GDPR) e di cancellazione (art. 17) via email sono gestite automaticamente tramite Strumenti → Esporta/Cancella dati personali di WordPress. %2$s%3$s', 'db-form-builder'),
                    $ip_label,
                    $privacy_notice_text,
                    $consent_status_text
                ),
                'retention'      => $retention_text,
                'transfers'      => __('Nessuno per il salvataggio in sé. Il database delle submission risiede nella stessa istanza WordPress del sito.', 'db-form-builder'),
            );
        }

        /**
         * Costruisce la voce per il trattamento "Notifiche email".
         */
        private static function build_email_entry($global) {
            $from_email = $global['from_email'] ?? get_option('admin_email');

            return array(
                'id'             => 'dbfb_email_notifications',
                'label'          => __('Notifiche email (DB Form Builder)', 'db-form-builder'),
                'status'         => 'active',
                'purpose'        => __('Inviare al titolare del sito una notifica via email a ogni invio modulo, e/o inviare all\'utente una conferma di ricezione.', 'db-form-builder'),
                'legal_basis'    => __('Consenso esplicito (art. 6.1.a GDPR) per la conferma all\'utente; legittimo interesse (art. 6.1.f) per la notifica all\'amministratore.', 'db-form-builder'),
                'data_collected' => sprintf(
                    /* translators: %s: indirizzo from email configurato */
                    __('Stesso set di dati delle submission, formattato in testo email. Mittente configurato: %s. Le email vengono inviate via wp_mail() — il trasporto effettivo dipende dalla configurazione SMTP del sito (in assenza di plugin SMTP, mail() del server PHP).', 'db-form-builder'),
                    $from_email
                ),
                'retention'      => __('La conservazione delle email è di responsabilità del provider del sistema email del titolare e dell\'utente destinatario, non del plugin.', 'db-form-builder'),
                'transfers'      => __('Nessuno diretto dal plugin. Eventuali trasferimenti dipendono dal provider SMTP configurato (es. Mailgun, SendGrid, Amazon SES) — l\'amministratore deve dichiararli separatamente nel proprio registro privacy.', 'db-form-builder'),
            );
        }

        /**
         * Costruisce la voce per il trattamento "reCAPTCHA".
         *
         * Caso delicato: reCAPTCHA è un trasferimento extra-UE (Google,
         * USA) che richiede menzione esplicita. Anche con Consent Mode,
         * lo script di Google viene caricato sul browser dell'utente.
         */
        private static function build_recaptcha_entry($global) {
            $version = $global['recaptcha_version'] ?? 'v2';

            return array(
                'id'             => 'dbfb_recaptcha',
                'label'          => __('Antispam Google reCAPTCHA (DB Form Builder)', 'db-form-builder'),
                'status'         => 'active',
                'purpose'        => __('Proteggere i moduli pubblici da invii automatizzati (bot, spam) tramite il servizio Google reCAPTCHA.', 'db-form-builder'),
                'legal_basis'    => __('Legittimo interesse del titolare alla protezione dei propri sistemi (art. 6.1.f GDPR). Si consiglia comunque di subordinare il caricamento dello script al consenso "marketing" tramite il DB Cookie Manager: lo script reCAPTCHA imposta cookie e raccoglie segnali di tracciamento del comportamento utente.', 'db-form-builder'),
                'data_collected' => sprintf(
                    /* translators: %s: versione reCAPTCHA */
                    __('reCAPTCHA versione %s. Google riceve: indirizzo IP del visitatore, user-agent, segnali di interazione (movimenti del mouse, tempo di permanenza), cookie di Google associati al browser. Il sito riceve solo un token di validazione che viene verificato server-side.', 'db-form-builder'),
                    strtoupper($version)
                ),
                'retention'      => __('La conservazione dei dati raccolti da Google è regolata dalla privacy policy di Google.', 'db-form-builder'),
                'transfers'      => __('TRASFERIMENTO EXTRA-UE: Google LLC (Stati Uniti). Base giuridica del trasferimento: Standard Contractual Clauses (SCC) e Data Privacy Framework UE-USA (DPF). L\'informativa privacy del sito DEVE menzionare questo trasferimento se reCAPTCHA è attivo.', 'db-form-builder'),
            );
        }

        /**
         * Costruisce la voce per il trattamento "Webhook".
         *
         * Elenca esplicitamente gli host destinatari così l'admin sa
         * a chi sta inviando i dati.
         */
        private static function build_webhook_entry($hosts) {
            $hosts_text = empty($hosts)
                ? __('(nessun host valido configurato)', 'db-form-builder')
                : implode(', ', $hosts);

            return array(
                'id'             => 'dbfb_webhooks',
                'label'          => __('Webhook in uscita (DB Form Builder)', 'db-form-builder'),
                'status'         => 'active',
                'purpose'        => __('Inviare i dati delle submission a sistemi esterni configurati dall\'amministratore (es. CRM, automazione, database custom) tramite richiesta HTTP POST.', 'db-form-builder'),
                'legal_basis'    => __('Consenso esplicito dell\'interessato (art. 6.1.a GDPR) o esecuzione di un contratto (art. 6.1.b), a seconda della finalità del sistema destinatario. L\'amministratore è responsabile della valutazione di adeguatezza e della stipula di eventuali Data Processing Agreement con il fornitore destinatario.', 'db-form-builder'),
                'data_collected' => __('Tutti i dati della submission (campi del form + IP secondo la modalità configurata + timestamp). Payload inviato come JSON.', 'db-form-builder'),
                'retention'      => __('La conservazione dei dati lato sistema destinatario è di responsabilità del fornitore di quel sistema.', 'db-form-builder'),
                'transfers'      => sprintf(
                    /* translators: %s: lista host destinatari */
                    __('Trasferimento ai seguenti host esterni: %s. Verificare se ognuno è UE o extra-UE e in quest\'ultimo caso assicurarsi che esista una base giuridica adeguata (SCC, decisione di adeguatezza, ecc.).', 'db-form-builder'),
                    $hosts_text
                ),
            );
        }

        /**
         * Restituisce una descrizione user-friendly della modalità di
         * storage IP in uso (per la voce "Dati raccolti").
         */
        private static function ip_mode_label($mode) {
            switch ($mode) {
                case 'none':
                    return __('nessun indirizzo IP loggato (modalità "Non salvare IP")', 'db-form-builder');
                case 'full':
                    return __('indirizzo IP completo del visitatore (modalità sconsigliata "IP in chiaro")', 'db-form-builder');
                case 'hashed':
                default:
                    return __('hash SHA-256 dell\'indirizzo IP (con salt WP_AUTH_KEY, irreversibile in pratica — usato solo per il rate limiting)', 'db-form-builder');
            }
        }
    }
}
