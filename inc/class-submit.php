<?php
if (!defined('ABSPATH')) exit;

class DBFB_Submit {

    /**
     * Versione della regola di protezione di uploads/dbfb/ (2.13.0).
     * Scritta come marcatore nel .htaccess e nell'option dbfb_uploads_protection.
     */
    const UPLOADS_PROTECTION_VERSION = '2.13.0';

    /**
     * Rate limit globale per IP delle richieste anonime (2.13.0).
     * Filtrabili via dbfb_submit_rate_limit / dbfb_submit_rate_window.
     */
    const SUBMIT_RATE_LIMIT  = 20;
    const SUBMIT_RATE_WINDOW = 600;

    public static function ajax_submit_form() {
        // 2.13.0: nonce solo per gli utenti loggati; per gli anonimi controllo
        // di origine + rate limit (vedi verify_submit_request()).
        self::verify_submit_request();

        $form_id = intval($_POST['form_id'] ?? 0);
        $form_data = json_decode(stripslashes($_POST['data'] ?? '{}'), true);
        $recaptcha_token = sanitize_text_field($_POST['recaptcha_token'] ?? '');

        if (!$form_id || empty($form_data)) {
            wp_send_json_error(['message' => 'Dati non validi']);
        }

        // 2.13.0: accettiamo solo form del plugin e pubblicati. Prima un ID
        // qualsiasi (post, pagina, bozza di form) veniva accettato.
        $form = get_post($form_id);
        if (!$form || $form->post_type !== 'dbfb_form' || $form->post_status !== 'publish') {
            wp_send_json_error(['message' => __('Form non trovato', 'db-form-builder')]);
        }

        $form_fields = get_post_meta($form_id, '_dbfb_fields', true) ?: [];
        $form_settings = get_post_meta($form_id, '_dbfb_settings', true) ?: [];
        $global_settings = DB_Form_Builder::get_global_settings();

        // Sicurezza (2.11.1): sanitizza i valori inviati PER TIPO di campo
        // prima di qualsiasi uso (salvataggio DB, email, webhook, render admin).
        // I valori arrivano da json_decode del POST e senza questo passaggio
        // verrebbero salvati grezzi → XSS immagazzinato nella dashboard admin.
        $form_data = self::sanitize_submission_data($form_data, $form_fields);

        // Honeypot check
        if (!empty($form_settings['enable_honeypot'])) {
            $honeypot_value = sanitize_text_field($_POST['dbfb_website_url'] ?? '');
            if (!empty($honeypot_value)) {
                wp_send_json_success([
                    'message' => $form_settings['success_message'] ?? __('Form inviato con successo!', 'db-form-builder')
                ]);
                return;
            }
            // Time-trap: il form emette un token firmato (timestamp + HMAC).
            // Se la submission arriva troppo in fretta (< 3s) è verosimilmente
            // un bot. Il token è firmato server-side (vedi make_time_token),
            // quindi non è falsificabile: un bot non può forgiare un timestamp
            // arbitrario per superare il controllo, e un token manomesso viene
            // scartato. Retrocompat: le submission senza token valido non
            // vengono bloccate qui (il form potrebbe essere cachato da una
            // versione precedente), restano coperte da honeypot + rate limit.
            $elapsed = self::verify_time_token($_POST['dbfb_timestamp'] ?? '');
            if ($elapsed !== null && $elapsed < 3) {
                wp_send_json_success([
                    'message' => $form_settings['success_message'] ?? __('Form inviato con successo!', 'db-form-builder')
                ]);
                return;
            }
        }

        // reCAPTCHA (2.13.0): "attivo per il form" dipende solo dalla
        // configurazione (form + chiavi + Hard Privacy), mai dal visitatore.
        // Il consenso viene gestito lato client: senza consenso lo script
        // Google non viene caricato e il client non può produrre un token.
        $captcha_enabled = DB_Form_Builder::is_recaptcha_enabled_for_form($form_settings);

        // Rate limiting
        if (!empty($form_settings['rate_limit_enabled'])) {
            $ip = DB_Form_Builder::get_client_ip();
            $max_submissions = intval($form_settings['rate_limit_max'] ?? 5);
            $window_minutes = intval($form_settings['rate_limit_window'] ?? 60);
            if ($max_submissions <= 0) $max_submissions = 5;
            if ($window_minutes <= 0) $window_minutes = 60;

            // Usiamo l'hash dell'IP come chiave del transient anche in
            // modalità storage 'none' / 'hashed': il rate limit non ha
            // bisogno dell'IP in chiaro, solo di una chiave deterministica
            // per visitatore. Coerente col principio di minimizzazione GDPR.
            $ip_key = $ip ? DB_Form_Builder::hash_ip($ip) : 'noip';
            $transient_key = 'dbfb_rate_' . md5($ip_key . '_' . $form_id);
            $submissions_count = get_transient($transient_key);

            if ($submissions_count !== false && $submissions_count >= $max_submissions) {
                wp_send_json_error([
                    'message' => sprintf(
                        __('Hai raggiunto il limite di %1$d invii. Riprova tra %2$d minuti.', 'db-form-builder'),
                        $max_submissions, $window_minutes
                    )
                ]);
            }
            set_transient($transient_key, ($submissions_count ?: 0) + 1, $window_minutes * 60);
        }

        // GDPR check (2.3.0+) e cattura prova del consenso (2.11.0).
        // Inizializziamo i campi consenso a NULL: significa "consenso non
        // documentato" (es. form senza checkbox abilitata).
        $gdpr_consent_given           = null;
        $gdpr_consent_text            = null;
        $gdpr_consent_timestamp       = null;
        $gdpr_consent_privacy_url     = null;
        $gdpr_consent_policy_version  = 0;

        if (!empty($form_settings['enable_gdpr'])) {
            if (empty($form_data['dbfb_gdpr_consent'])) {
                wp_send_json_error([
                    'message' => __('Devi acconsentire al trattamento dei dati personali per procedere.', 'db-form-builder')
                ]);
            }
            // 2.11.0: prima di scartare il valore, salviamo la prova del consenso.
            // GDPR art. 7.1 richiede di poter dimostrare il consenso ricevuto.
            $gdpr_consent_given     = 1;
            $gdpr_consent_text      = isset($form_settings['gdpr_text']) ? (string) $form_settings['gdpr_text'] : '';
            $gdpr_consent_timestamp = current_time('mysql');

            // URL informativa privacy: prima il link specifico del form,
            // fallback alla privacy policy globale di WP.
            $form_privacy_url = isset($form_settings['gdpr_link']) ? trim((string) $form_settings['gdpr_link']) : '';
            if ($form_privacy_url === '' && function_exists('get_privacy_policy_url')) {
                $form_privacy_url = (string) get_privacy_policy_url();
            }
            $gdpr_consent_privacy_url = $form_privacy_url ?: null;

            // Linka alla versione esatta del documento Privacy Hub se disponibile.
            if (class_exists('DBPH_Policy_Archive') && method_exists('DBPH_Policy_Archive', 'get_current_version_id')) {
                $gdpr_consent_policy_version = (int) DBPH_Policy_Archive::get_current_version_id();
            }

            unset($form_data['dbfb_gdpr_consent']);
        } elseif (!empty($form_settings['gdpr_intentionally_disabled'])) {
            // Form intenzionalmente senza checkbox: documentiamo che la mancanza
            // di consenso esplicito è una scelta consapevole dell'amministratore.
            $gdpr_consent_given = 0;
            $gdpr_consent_text  = __('Form configurato senza checkbox di consenso (scelta consapevole dell\'amministratore: base giuridica diversa dal consenso).', 'db-form-builder');
            $gdpr_consent_timestamp = current_time('mysql');
        }
        // Else: né enable_gdpr né intentional → tutti i campi NULL =
        // "consenso non documentato (potenzialmente non conforme)".

        // reCAPTCHA (2.13.0). Se è attivo per il form il token è SEMPRE
        // richiesto. Scelta documentata: quando manca il token e il consent
        // gate è attivo (il visitatore non ha dato il consenso alla categoria
        // richiesta, quindi lo script Google non è mai stato caricato)
        // rifiutiamo con un messaggio esplicito, coerente con il placeholder
        // mostrato nel form. Accettare invii senza token permetteva a un bot
        // di saltare la verifica semplicemente omettendo il cookie di
        // consenso; honeypot e rate limit restano comunque attivi a monte.
        if ($captcha_enabled) {
            if ($recaptcha_token === '') {
                $gate = DB_Form_Builder::recaptcha_consent_gate();
                wp_send_json_error([
                    'code'    => $gate['gated'] ? 'recaptcha_consent' : 'recaptcha_missing',
                    'message' => $gate['gated']
                        ? __('Per inviare il modulo accetta i cookie necessari alla verifica anti-spam.', 'db-form-builder')
                        : __('Verifica anti-spam non completata. Ricarica la pagina e riprova.', 'db-form-builder'),
                ]);
            }
            if (!self::verify_recaptcha($recaptcha_token, $global_settings['recaptcha_secret_key'])) {
                wp_send_json_error(['message' => __('Verifica anti-spam fallita. Riprova.', 'db-form-builder')]);
            }
        }

        // Hidden fields (conditional logic)
        $hidden_fields = [];
        if (!empty($_POST['hidden_fields'])) {
            $hidden_fields = json_decode(stripslashes($_POST['hidden_fields']), true);
            if (!is_array($hidden_fields)) $hidden_fields = [];
            $hidden_fields = array_map('sanitize_key', $hidden_fields);
        }

        // Validate required (skip hidden)
        foreach ($form_fields as $field) {
            if (in_array($field['id'], $hidden_fields)) continue;
            if ($field['type'] === 'file') {
                // File required check
                if (!empty($field['required'])) {
                    $file_key = 'dbfb_file_' . $field['id'];
                    if (empty($_FILES[$file_key]['name']) || (is_array($_FILES[$file_key]['name']) && empty($_FILES[$file_key]['name'][0]))) {
                        wp_send_json_error([
                            'message' => sprintf(__('Il campo "%s" è obbligatorio', 'db-form-builder'), $field['label'])
                        ]);
                    }
                }
                continue;
            }
            if (!empty($field['required']) && empty($form_data[$field['id']])) {
                wp_send_json_error([
                    'message' => sprintf(__('Il campo "%s" è obbligatorio', 'db-form-builder'), $field['label'])
                ]);
            }
        }

        // Remove hidden field data
        foreach ($hidden_fields as $hf) {
            unset($form_data[$hf]);
        }

        // Process file uploads
        $uploaded_files = self::process_file_uploads($form_id, $form_fields, $hidden_fields);
        if (is_wp_error($uploaded_files)) {
            wp_send_json_error(['message' => $uploaded_files->get_error_message()]);
        }

        // Merge file URLs into form data
        foreach ($uploaded_files as $field_id => $file_urls) {
            $form_data[$field_id] = $file_urls;
        }

        // Save submission
        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';
        DB_Form_Builder::maybe_create_table();
        DB_Form_Builder::maybe_upgrade_schema();

        // Privacy by design (2.3.0): l'IP viene salvato secondo la modalità
        // configurata. Default 'hashed': SHA-256 + salt, irreversibile.
        // Le nuove submission lasciano sempre vuota la colonna ip_address
        // legacy; la colonna ip_hash è il riferimento autorevole.
        $client_ip = DB_Form_Builder::get_client_ip();
        $mode = DB_Form_Builder::get_ip_storage_mode();
        $ip_address_value = '';
        $ip_hash_value = '';
        if ($mode === 'full') {
            $ip_address_value = $client_ip;
        } elseif ($mode === 'hashed') {
            $ip_hash_value = DB_Form_Builder::hash_ip($client_ip);
        }
        // mode === 'none' → entrambi vuoti.

        // Snapshot dei field (2.6.0): salviamo la definizione dei campi
        // (id/type/label) insieme alla submission. Permette di rendere le
        // submission storiche correttamente anche dopo che il form è stato
        // modificato (rinomina label, aggiunta/rimozione campi, ecc.).
        // Salviamo una chiave riservata `_fields_snapshot` nel JSON `data`
        // — niente nuova colonna SQL, schema invariato.
        // Solo i campi NON layout-only (escludiamo divider/html/image/pagebreak)
        // perché non hanno valori associati.
        $snapshot = array();
        foreach ($form_fields as $field) {
            if (in_array($field['type'] ?? '', array('divider', 'html', 'image', 'pagebreak'), true)) continue;
            $snapshot[] = array(
                'id'    => $field['id']    ?? '',
                'type'  => $field['type']  ?? '',
                'label' => $field['label'] ?? '',
            );
        }
        $form_data['_fields_snapshot'] = $snapshot;

        $result = $wpdb->insert($table, [
            'form_id' => $form_id,
            'data' => json_encode($form_data),
            'ip_address' => $ip_address_value,
            'ip_hash' => $ip_hash_value,
            'gdpr_consent_given'          => $gdpr_consent_given,
            'gdpr_consent_text'           => $gdpr_consent_text,
            'gdpr_consent_timestamp'      => $gdpr_consent_timestamp,
            'gdpr_consent_privacy_url'    => $gdpr_consent_privacy_url,
            'gdpr_consent_policy_version' => $gdpr_consent_policy_version,
        ]);

        // 2.11.2: leggiamo l'id subito dopo l'INSERT. Più avanti wp_mail può
        // far girare plugin SMTP che scrivono log su DB e sovrascrivono
        // $wpdb->insert_id, collegando la delivery alla submission sbagliata.
        $submission_id = ($result === false) ? 0 : (int) $wpdb->insert_id;

        if ($result === false && defined('WP_DEBUG') && WP_DEBUG) {
            // Loghiamo solo in debug per evitare di sporcare i log di
            // produzione. Il chiamante riceve comunque il success message,
            // ma chi sviluppa vede l'errore.
            error_log('DB Form Builder: Errore inserimento DB - ' . $wpdb->last_error);
        }

        // Email
        $placeholders = DBFB_Email::prepare_placeholders($form, $form_fields, $form_data, $form_settings);

        $user_email = '';
        foreach ($form_fields as $field) {
            if ($field['type'] === 'email' && !empty($form_data[$field['id']])) {
                $user_email = sanitize_email($form_data[$field['id']]);
                break;
            }
        }

        if (!empty($form_settings['send_confirmation']) && $user_email) {
            // 2.13.0: nella conferma all'utente gli allegati compaiono solo
            // per nome (il link di download è riservato all'admin).
            $user_placeholders = DBFB_Email::prepare_placeholders($form, $form_fields, $form_data, $form_settings, 'user');
            DBFB_Email::send_confirmation($user_email, $form_settings, $user_placeholders);
        }

        if (!empty($form_settings['send_admin_notification']) && !empty($form_settings['admin_email'])) {
            DBFB_Email::send_admin($form_settings, $placeholders);
        }

        // Webhook
        // Webhook delivery (2.7.0): enqueue async invece dell'invio sincrono.
        // L'enqueue salva in tabella delle deliveries e schedula un dispatch
        // asincrono via WP-Cron. Vantaggi: il submit non aspetta la risposta
        // del destinatario, retry automatico su fallimento, dead-letter queue
        // visibile in admin.
        if (!empty($form_settings['enable_webhook']) && !empty($form_settings['webhook_url'])) {
            $payload = DBFB_Webhook::build_payload($form, $form_fields, $form_data, $client_ip);
            DBFB_Webhook::enqueue(
                $form_id,
                $submission_id,
                $form_settings['webhook_url'],
                $payload
            );
        }

        wp_send_json_success([
            'message' => $form_settings['success_message'] ?? __('Form inviato con successo!', 'db-form-builder')
        ]);
    }

    /**
     * Verifica l'origine della richiesta di invio (2.13.0).
     *
     * Utenti loggati: nonce (le loro pagine non sono cachate e il nonce è
     * legato alla sessione).
     *
     * Visitatori anonimi: niente nonce. Con una cache di pagina (WP Rocket,
     * LiteSpeed, Cloudflare APO, ...) il nonce stampato nell'HTML scade dopo
     * 12–24h e da quel momento ogni invio veniva rifiutato con 403. Per un
     * anonimo il nonce è identico per tutti i visitatori, quindi non protegge
     * nulla: lo sostituiamo con un controllo di origine (Origin, fallback
     * Referer, dello stesso host del sito) e un rate limit globale per IP
     * (hash salato, mai in chiaro). Il rate limit per form resta attivo a valle.
     *
     * Pattern di riferimento: DBCM_Consent_API::verify_consent_request()
     * (DB Cookie Manager 3.7.1).
     *
     * @return void Termina con wp_send_json_error() se la richiesta non è valida.
     */
    private static function verify_submit_request() {
        if (is_user_logged_in()) {
            check_ajax_referer('dbfb_submit_nonce', 'nonce');
            return;
        }

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- confrontati solo come host via wp_parse_url().
        $origin = '';
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $origin = wp_unslash($_SERVER['HTTP_ORIGIN']);
        } elseif (!empty($_SERVER['HTTP_REFERER'])) {
            $origin = wp_unslash($_SERVER['HTTP_REFERER']);
        }
        // phpcs:enable

        if (!self::origin_matches($origin, array(home_url(), site_url()))) {
            wp_send_json_error(array(
                'code'    => 'bad_origin',
                'message' => __('Richiesta non valida: ricarica la pagina e riprova.', 'db-form-builder'),
            ), 403);
        }

        if (self::is_submit_rate_limited()) {
            wp_send_json_error(array(
                'code'    => 'rate_limited',
                'message' => __('Troppi invii in poco tempo. Riprova tra qualche minuto.', 'db-form-builder'),
            ), 429);
        }
    }

    /**
     * True se l'host di $origin coincide con l'host di uno degli URL del sito.
     *
     * Confronto sul solo host (case-insensitive): schema e porta possono
     * differire dietro proxy. Origin vuoto o 'null' → false.
     *
     * @param string $origin    Valore dell'header Origin o Referer.
     * @param array  $site_urls URL del sito (home_url, site_url).
     * @return bool
     */
    public static function origin_matches($origin, $site_urls) {
        $origin = is_string($origin) ? trim($origin) : '';
        if ($origin === '' || $origin === 'null') {
            return false;
        }
        $host = wp_parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);
        foreach ((array) $site_urls as $url) {
            $site_host = wp_parse_url((string) $url, PHP_URL_HOST);
            if (is_string($site_host) && strtolower($site_host) === $host) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rate limit globale per IP sugli invii anonimi (2.13.0).
     *
     * Chiave = hash salato dell'IP (mai in chiaro). Prefisso dbfb_rate_ così
     * i transient vengono ripuliti anche da uninstall.php.
     *
     * @return bool True se la richiesta supera il limite.
     */
    private static function is_submit_rate_limited() {
        $ip = DB_Form_Builder::get_client_ip();
        if ($ip === '') {
            return false;
        }
        $limit  = (int) apply_filters('dbfb_submit_rate_limit', self::SUBMIT_RATE_LIMIT);
        $window = (int) apply_filters('dbfb_submit_rate_window', self::SUBMIT_RATE_WINDOW);
        if ($limit <= 0 || $window <= 0) {
            return false;
        }
        $key   = 'dbfb_rate_g_' . substr(DB_Form_Builder::hash_ip($ip), 0, 32);
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return true;
        }
        set_transient($key, $count + 1, $window);
        return false;
    }

    /**
     * @deprecated 2.7.0 Usare DBFB_Webhook::enqueue() per l'invio async.
     * Mantenuto come shim per backward compat se altro codice (custom plugin
     * o legacy) lo richiama. Esegue l'enqueue invece dell'invio sincrono.
     */
    private static function fire_webhook($url, $form, $form_fields, $form_data) {
        $client_ip = DB_Form_Builder::get_client_ip();
        $payload = DBFB_Webhook::build_payload($form, $form_fields, $form_data, $client_ip);
        DBFB_Webhook::enqueue($form->ID, null, $url, $payload);
    }

    /**
     * Sanitizza i valori di una submission in base al tipo di ciascun campo (2.11.1).
     *
     * Difesa primaria contro XSS immagazzinato: i valori arrivano da
     * json_decode del POST utente e vengono salvati/renderizzati altrove.
     * La chiave GDPR (dbfb_gdpr_consent) e i valori speciali (array file)
     * sono gestiti a valle; qui trattiamo i valori scalari e le liste
     * (checkbox multipli) prodotti dal frontend.
     *
     * Mappa tipo → sanitizzazione:
     *  - email               → sanitize_email
     *  - textarea            → sanitize_textarea_field (preserva a capo)
     *  - number/tel/date/... → sanitize_text_field
     *  - default             → sanitize_text_field
     *  - array di scalari    → sanitize_text_field elemento per elemento
     *
     * I campi di tipo file NON sono qui: i loro valori vengono costruiti
     * server-side da process_file_uploads(), non dal payload utente.
     *
     * @param array $data   Valori decodificati dal POST.
     * @param array $fields Field definitions del form.
     * @return array Valori sanitizzati (stesse chiavi).
     */
    /**
     * Genera un token time-trap firmato per l'anti-bot dell'honeypot (2.11.1).
     *
     * Formato: "{timestamp}.{hmac}" dove hmac = HMAC-SHA256(timestamp, salt).
     * Il salt deriva da wp_salt('nonce'), quindi il token è verificabile
     * solo dal server e non forgiabile dal client. Emesso nel form come
     * valore del campo hidden dbfb_timestamp.
     *
     * @return string Token firmato.
     */
    public static function make_time_token() {
        $ts  = (string) time();
        $sig = hash_hmac('sha256', $ts, wp_salt('nonce'));
        return $ts . '.' . $sig;
    }

    /**
     * Verifica un token time-trap e restituisce i secondi trascorsi.
     *
     * Controlla la firma HMAC (constant-time) e che il timestamp sia
     * plausibile (non nel futuro, non più vecchio di 1 ora → token
     * probabilmente riciclato/cachato: lo consideriamo non valutabile).
     *
     * @param string $token Valore ricevuto in dbfb_timestamp.
     * @return int|null Secondi trascorsi dall'emissione, o null se il token
     *                  è assente/malformato/non firmato correttamente
     *                  (in tal caso il chiamante NON blocca — retrocompat).
     */
    public static function verify_time_token($token) {
        $token = is_string($token) ? $token : '';
        if (strpos($token, '.') === false) {
            return null; // formato legacy o assente: non valutabile
        }

        list($ts, $sig) = explode('.', $token, 2);
        if ($ts === '' || !ctype_digit($ts) || $sig === '') {
            return null;
        }

        $expected = hash_hmac('sha256', $ts, wp_salt('nonce'));
        if (!hash_equals($expected, $sig)) {
            return null; // firma non valida: token manomesso → non valutabile
        }

        $elapsed = time() - (int) $ts;
        if ($elapsed < 0 || $elapsed > HOUR_IN_SECONDS) {
            return null; // futuro o troppo vecchio: token riciclato/cachato
        }

        return $elapsed;
    }

    private static function sanitize_submission_data($data, $fields) {
        if (!is_array($data)) return array();

        // Mappa field_id → type per lookup O(1).
        $types = array();
        foreach ((array) $fields as $f) {
            if (!empty($f['id'])) {
                $types[$f['id']] = $f['type'] ?? 'text';
            }
        }

        $clean = array();
        foreach ($data as $key => $value) {
            // La chiave del consenso è un flag: la lasciamo passare grezza,
            // viene valutata (empty check) e poi unset a valle.
            if ($key === 'dbfb_gdpr_consent') {
                $clean[$key] = $value;
                continue;
            }

            // Sicurezza (2.11.2): scartiamo le chiavi che non corrispondono a
            // un campo del form. Senza questo filtro un client poteva
            // iniettare strutture arbitrarie (es. {"path": "..."}) che
            // delete_submission_files avrebbe poi trattato come allegati.
            if (!isset($types[$key])) {
                continue;
            }
            $type = $types[$key];

            // I valori dei campi file sono costruiti server-side da
            // process_file_uploads(): quelli inviati dal client si ignorano.
            if ($type === 'file') {
                continue;
            }

            if (is_array($value)) {
                // Checkbox multipli o liste di scalari. array_values scarta
                // eventuali chiavi associative inviate dal client.
                $clean[$key] = array_values(array_map(function ($v) {
                    return is_scalar($v) ? sanitize_text_field((string) $v) : '';
                }, $value));
                continue;
            }

            if (!is_scalar($value)) {
                $clean[$key] = '';
                continue;
            }

            $value = (string) $value;
            switch ($type) {
                case 'email':
                    $clean[$key] = sanitize_email($value);
                    break;
                case 'textarea':
                    $clean[$key] = sanitize_textarea_field($value);
                    break;
                default:
                    $clean[$key] = sanitize_text_field($value);
                    break;
            }
        }

        return $clean;
    }

    private static function verify_recaptcha($token, $secret_key) {
        if (empty($token)) {
            error_log('DB Form Builder: reCAPTCHA token vuoto');
            return false;
        }

        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'timeout' => 10,
            'body' => [
                'secret' => $secret_key,
                'response' => $token,
                'remoteip' => DB_Form_Builder::get_client_ip(),
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('DB Form Builder: Errore reCAPTCHA - ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body)) {
            error_log('DB Form Builder: Risposta reCAPTCHA vuota');
            return false;
        }

        if (isset($body['score'])) {
            $valid = !empty($body['success']) && $body['score'] >= 0.5;
            if (!$valid) error_log('DB Form Builder: reCAPTCHA score basso: ' . ($body['score'] ?? 'N/A'));
            return $valid;
        }

        return !empty($body['success']);
    }

    // =========================================================
    // FILE UPLOAD
    // =========================================================

    private static $blocked_extensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'phps',
        'exe', 'js', 'sh', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py',
        'htaccess', 'htpasswd', 'ini', 'phar', 'svg'
    ];

    private static function process_file_uploads($form_id, $form_fields, $hidden_fields) {
        $uploaded = [];

        foreach ($form_fields as $field) {
            if ($field['type'] !== 'file') continue;
            if (in_array($field['id'], $hidden_fields)) continue;

            $file_key = 'dbfb_file_' . $field['id'];
            if (empty($_FILES[$file_key]['name'])) continue;

            $is_multiple = !empty($field['file_multiple']);
            $max_size_mb = intval($field['file_max_size'] ?? 5);
            $max_size_bytes = $max_size_mb * 1024 * 1024;
            $allowed_ext_str = $field['file_extensions'] ?? 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,zip';
            $allowed_extensions = array_map('trim', array_map('strtolower', explode(',', $allowed_ext_str)));

            // Normalize to arrays for uniform processing
            $names = (array) $_FILES[$file_key]['name'];
            $tmp_names = (array) $_FILES[$file_key]['tmp_name'];
            $sizes = (array) $_FILES[$file_key]['size'];
            $errors = (array) $_FILES[$file_key]['error'];

            // Filter out empty entries
            $file_count = count($names);
            $urls = [];

            for ($i = 0; $i < $file_count; $i++) {
                if (empty($names[$i]) || $errors[$i] === UPLOAD_ERR_NO_FILE) continue;

                // Check upload error
                if ($errors[$i] !== UPLOAD_ERR_OK) {
                    return new WP_Error('upload_error', sprintf(
                        __('Errore durante il caricamento di "%s"', 'db-form-builder'),
                        sanitize_file_name($names[$i])
                    ));
                }

                // Check file size
                if ($sizes[$i] > $max_size_bytes) {
                    return new WP_Error('file_too_large', sprintf(
                        __('Il file "%1$s" supera la dimensione massima di %2$d MB', 'db-form-builder'),
                        sanitize_file_name($names[$i]), $max_size_mb
                    ));
                }

                // Check extension
                $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
                if (in_array($ext, self::$blocked_extensions)) {
                    return new WP_Error('blocked_extension', sprintf(
                        __('Il tipo di file "%s" non è consentito per motivi di sicurezza', 'db-form-builder'),
                        $ext
                    ));
                }
                if (!in_array($ext, $allowed_extensions)) {
                    return new WP_Error('invalid_extension', sprintf(
                        __('Il tipo di file "%1$s" non è ammesso. Formati consentiti: %2$s', 'db-form-builder'),
                        $ext, $allowed_ext_str
                    ));
                }

                $safe_filename = sanitize_file_name($names[$i]);

                // Validazione MIME reale del CONTENUTO (2.11.1): wp_check_filetype
                // guarda solo l'estensione nel nome. wp_check_filetype_and_ext
                // legge i magic bytes del file caricato (via finfo) e verifica
                // che il MIME reale corrisponda all'estensione dichiarata. Blocca
                // i file camuffati (es. uno script rinominato .pdf) e i mismatch
                // (contenuto che non combacia con l'estensione).
                $filetype = wp_check_filetype_and_ext($tmp_names[$i], $safe_filename);
                if (empty($filetype['type'])) {
                    return new WP_Error('invalid_filetype', sprintf(
                        __('Il file "%s" non è un tipo riconosciuto o il contenuto non corrisponde all\'estensione', 'db-form-builder'),
                        $safe_filename
                    ));
                }
                // Se WordPress ha corretto l'estensione (proper_filename), il
                // nome dichiarato non combaciava col contenuto reale: rifiuta.
                if (!empty($filetype['proper_filename'])) {
                    return new WP_Error('filetype_mismatch', sprintf(
                        __('Il contenuto del file "%s" non corrisponde alla sua estensione', 'db-form-builder'),
                        $safe_filename
                    ));
                }
                // Difesa aggiuntiva: il MIME reale non deve mai essere eseguibile.
                if (preg_match('#(application/x-php|text/x-php|application/x-httpd-php|application/x-executable|text/x-shellscript)#i', $filetype['type'])) {
                    return new WP_Error('blocked_mime', sprintf(
                        __('Il tipo di contenuto del file "%s" non è consentito per motivi di sicurezza', 'db-form-builder'),
                        $safe_filename
                    ));
                }

                // Create upload directory
                $upload_dir = self::get_upload_dir($form_id);
                if (is_wp_error($upload_dir)) return $upload_dir;

                // Nome file non prevedibile (2.13.0): prefisso casuale di 24
                // caratteri + nome sanitizzato. Seconda linea di difesa oltre
                // al deny all della cartella (che Nginx non legge).
                $random_prefix = wp_generate_password(24, false, false);
                $safe_name = wp_unique_filename($upload_dir['path'], $random_prefix . '-' . $safe_filename);
                $dest_path = $upload_dir['path'] . '/' . $safe_name;

                // Move file
                if (!move_uploaded_file($tmp_names[$i], $dest_path)) {
                    return new WP_Error('move_failed', sprintf(
                        __('Impossibile salvare il file "%s"', 'db-form-builder'),
                        sanitize_file_name($names[$i])
                    ));
                }

                // Set proper permissions
                @chmod($dest_path, 0644);

                $urls[] = [
                    'url' => $upload_dir['url'] . '/' . $safe_name,
                    'name' => sanitize_file_name($names[$i]),
                    'size' => $sizes[$i],
                    // 2.4.0: path relativo a uploads basedir, salvato per
                    // permettere la cancellazione affidabile del file fisico
                    // quando la submission viene eliminata. Salviamo il path
                    // relativo (non assoluto) così il sito è portabile fra
                    // ambienti (locale → staging → production senza patch).
                    'path' => $upload_dir['relative_path'] . '/' . $safe_name,
                ];
            }

            if (!empty($urls)) {
                $uploaded[$field['id']] = $is_multiple ? $urls : ($urls[0] ?? null);
            }
        }

        return $uploaded;
    }

    private static function get_upload_dir($form_id) {
        $wp_upload = wp_upload_dir();
        $base_dir = $wp_upload['basedir'] . '/dbfb/' . $form_id;
        $base_url = $wp_upload['baseurl'] . '/dbfb/' . $form_id;

        if (!file_exists($base_dir)) {
            if (!wp_mkdir_p($base_dir)) {
                return new WP_Error('mkdir_failed', __('Impossibile creare la cartella di upload', 'db-form-builder'));
            }
        }

        // Protezione della root dbfb/ (idempotente: la riscriviamo se manca,
        // anche su installazioni che avevano già la cartella da versioni
        // precedenti con un .htaccess meno restrittivo).
        self::harden_upload_root($wp_upload['basedir'] . '/dbfb');

        // index.php nella sottocartella del form per prevenire il listing.
        $index = $base_dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, '<?php // Silence is golden.');
        }

        return [
            'path'          => $base_dir,
            'url'           => $base_url,
            // 2.4.0: path relativo a wp_upload_dir basedir (es. "dbfb/123").
            // Usato per costruire 'path' nelle entry delle submission senza
            // hardcoding del basedir assoluto, che cambia da ambiente ad
            // ambiente. Sicuro perché parte solo da nostre concatenazioni
            // ('dbfb/' . $form_id).
            'relative_path' => 'dbfb/' . $form_id,
        ];
    }

    /**
     * Scrive/aggiorna i file di protezione nella root uploads/dbfb/ (2.11.1, 2.13.0).
     *
     * 2.13.0: .htaccess con "deny all" su TUTTA la cartella. Gli allegati non
     * sono più raggiungibili con un URL pubblico: si scaricano solo dall'admin
     * tramite admin-post.php?action=dbfb_download (capability + nonce, vedi
     * DBFB_Submissions::handle_attachment_download()). Sintassi per Apache 2.4
     * (mod_authz_core) e 2.2 (Order/Deny) in blocchi IfModule, così non
     * genera errori 500 su nessuna delle due. Niente direttiva Options: con
     * AllowOverride restrittivo causerebbe un 500.
     *
     * Su Nginx l'.htaccess è ignorato: la protezione lì è il nome file con
     * prefisso casuale di 24 caratteri più la regola da aggiungere alla config
     * del server (vedi README e admin notice).
     *
     * Idempotente: riscrive solo se il file manca o non contiene il marcatore
     * della versione corrente della regola.
     *
     * @param string $dbfb_root Path assoluto di uploads/dbfb.
     * @return bool True se la protezione risulta applicata.
     */
    public static function harden_upload_root($dbfb_root) {
        if (!is_dir($dbfb_root)) return false;

        $htaccess = $dbfb_root . '/.htaccess';
        $desired  = '# DB Form Builder - Security (' . self::UPLOADS_PROTECTION_VERSION . ")\n"
                  . "# Accesso diretto negato: gli allegati si scaricano solo dall'admin.\n"
                  . "<IfModule mod_authz_core.c>\n"
                  . "    Require all denied\n"
                  . "</IfModule>\n"
                  . "<IfModule !mod_authz_core.c>\n"
                  . "    Order allow,deny\n"
                  . "    Deny from all\n"
                  . "</IfModule>\n";

        $current = file_exists($htaccess) ? (string) @file_get_contents($htaccess) : '';
        $ok = true;
        if (strpos($current, 'Security (' . self::UPLOADS_PROTECTION_VERSION . ')') === false) {
            $ok = (false !== @file_put_contents($htaccess, $desired));
        }

        $index = $dbfb_root . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, '<?php // Silence is golden.');
        }

        return $ok;
    }
}
