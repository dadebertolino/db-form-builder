<?php
if (!defined('ABSPATH')) exit;

/**
 * Consensi facoltativi per singolo form (2.16.0).
 *
 * Ogni form può chiedere N consensi aggiuntivi (marketing, newsletter, ...)
 * separati dal consenso GDPR principale: mai pre-spuntati, mai obbligatori
 * (art. 7.4), l'invio del form non dipende da questi.
 *
 * Per ogni invio si registra una riga per voce in wp_dbfb_consents, anche
 * per i consensi NON dati (given = 0): documenta la scelta. La riga conserva
 * il testo esatto mostrato, l'informativa, la versione della policy del
 * Privacy Hub e, per collegarla all'interessato anche dopo la retention,
 * hash (HMAC) e forma mascherata dell'email. Mai l'email in chiaro.
 *
 * Conservazione: i consensi dati e non revocati restano fino alla revoca,
 * anche quando la retention cancella la risposta. Tutto il resto segue la
 * risposta (cancellazioni admin, DSAR, eliminazione del form).
 *
 * Revoca (art. 7.3): link firmato HMAC nell'email ({revoca_consenso}),
 * pagina di conferma su GET, revoca solo su POST; revoca manuale da admin.
 */
class DBFB_Consents {

    /** Numero massimo di consensi facoltativi per form. */
    const MAX_PER_FORM = 10;

    /** Rate limit della pagina di revoca, per IP (hash salato). */
    const REVOKE_RATE_LIMIT  = 20;
    const REVOKE_RATE_WINDOW = 600;

    public static function init() {
        add_action('admin_post_dbfb_revoke', [__CLASS__, 'handle_revoke']);
        add_action('admin_post_nopriv_dbfb_revoke', [__CLASS__, 'handle_revoke']);
        add_action('wp_ajax_dbfb_admin_revoke_consent', [__CLASS__, 'ajax_admin_revoke']);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'dbfb_consents';
    }

    public static function table_exists() {
        // Si memorizza solo l'esito positivo: una tabella creata più avanti
        // nella stessa richiesta (migrazione) deve risultare subito.
        static $exists = false;
        if (!$exists) {
            global $wpdb;
            $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table())) === self::table());
        }
        return $exists;
    }

    /**
     * Crea la tabella se non esiste (idempotente, chiamata da maybe_create_table).
     */
    public static function create_table() {
        global $wpdb;
        $table = self::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id bigint(20) NOT NULL DEFAULT 0,
            form_id bigint(20) NOT NULL DEFAULT 0,
            consent_key varchar(40) NOT NULL,
            given tinyint(1) NOT NULL DEFAULT 0,
            consent_text text NOT NULL,
            purpose varchar(255) NOT NULL DEFAULT '',
            privacy_url varchar(500) DEFAULT NULL,
            policy_version bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            subject_hash char(64) NOT NULL DEFAULT '',
            subject_masked varchar(190) NOT NULL DEFAULT '',
            given_at datetime NOT NULL,
            revoked_at datetime DEFAULT NULL,
            revoke_source varchar(20) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY submission_id (submission_id),
            KEY consent_key (consent_key),
            KEY policy_version (policy_version),
            KEY subject_hash (subject_hash)
        ) $charset_collate;";
        dbDelta($sql);
    }

    // =========================================================
    // DEFINIZIONI (impostazioni del form)
    // =========================================================

    /**
     * Sanitizza l'elenco dei consensi facoltativi dal builder.
     *
     * Chiave: sanitize_key, max 40 caratteri, unica (derivata dal testo se
     * vuota). Voci senza testo scartate. Massimo MAX_PER_FORM.
     *
     * @param mixed $items
     * @return array Lista di ['key','text','privacy_url','purpose'].
     */
    public static function sanitize_definitions($items) {
        if (!is_array($items)) return [];
        $clean = [];
        $keys  = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $text = sanitize_text_field($item['text'] ?? '');
            if ($text === '') continue;
            $key = substr(sanitize_key($item['key'] ?? ''), 0, 40);
            if ($key === '') {
                $key = substr(sanitize_key(str_replace('-', '_', sanitize_title($text))), 0, 40);
            }
            if ($key === '' || in_array($key, $keys, true)) continue;
            $keys[]  = $key;
            $clean[] = [
                'key'         => $key,
                'text'        => $text,
                'privacy_url' => esc_url_raw($item['privacy_url'] ?? '', ['http', 'https']),
                'purpose'     => mb_substr(sanitize_text_field($item['purpose'] ?? ''), 0, 255),
            ];
            if (count($clean) >= self::MAX_PER_FORM) break;
        }
        return $clean;
    }

    /**
     * Consensi facoltativi configurati in un form.
     */
    public static function definitions($settings) {
        $list = is_array($settings) ? ($settings['optional_consents'] ?? []) : [];
        return is_array($list) ? $list : [];
    }

    /**
     * Informativa di una voce: quella della voce, poi quella del form, poi
     * quella globale di WordPress.
     */
    public static function privacy_url_for($definition, $settings) {
        $url = (string) ($definition['privacy_url'] ?? '');
        if ($url === '') $url = (string) ($settings['gdpr_link'] ?? '');
        if ($url === '' && function_exists('get_privacy_policy_url')) $url = (string) get_privacy_policy_url();
        return $url;
    }

    // =========================================================
    // REGISTRAZIONE
    // =========================================================

    /**
     * Registra le scelte di un invio: una riga per voce, dati e non dati.
     *
     * @param int    $submission_id
     * @param int    $form_id
     * @param array  $settings     Impostazioni del form.
     * @param array  $given_keys   Chiavi spuntate dal visitatore.
     * @param string $subject_email Email dell'interessato ('' se il form non ne ha).
     * @return array Righe registrate (stessa forma di for_submission()).
     */
    public static function record($submission_id, $form_id, $settings, $given_keys, $subject_email) {
        $defs = self::definitions($settings);
        if (!$defs || !$submission_id || !self::table_exists()) return [];

        $policy_version = 0;
        if (class_exists('DBPH_Policy_Archive') && method_exists('DBPH_Policy_Archive', 'get_current_version_id')) {
            $policy_version = (int) DBPH_Policy_Archive::get_current_version_id();
        }
        $given_keys = array_map('strval', (array) $given_keys);
        $now = current_time('mysql');

        global $wpdb;
        foreach ($defs as $def) {
            $wpdb->insert(self::table(), [
                'submission_id'  => (int) $submission_id,
                'form_id'        => (int) $form_id,
                'consent_key'    => $def['key'],
                'given'          => in_array($def['key'], $given_keys, true) ? 1 : 0,
                'consent_text'   => $def['text'],
                'purpose'        => $def['purpose'] ?? '',
                'privacy_url'    => self::privacy_url_for($def, $settings),
                'policy_version' => $policy_version,
                'subject_hash'   => self::subject_hash($subject_email),
                'subject_masked' => self::mask_email($subject_email),
                'given_at'       => $now,
            ], ['%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
        }
        return self::for_submission($submission_id);
    }

    /**
     * Righe dei consensi di una submission, in ordine di registrazione.
     */
    public static function for_submission($submission_id) {
        $map = self::for_submissions([(int) $submission_id]);
        return $map[(int) $submission_id] ?? [];
    }

    /**
     * Righe dei consensi raggruppate per submission_id.
     *
     * @param int[] $ids
     * @return array<int, object[]>
     */
    public static function for_submissions($ids) {
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        if (!$ids || !self::table_exists()) return [];
        global $wpdb;
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder %d generati in $in.
                'SELECT * FROM ' . self::table() . " WHERE submission_id IN ($in) ORDER BY id ASC",
                $chunk
            ));
            foreach ((array) $rows as $row) {
                $out[(int) $row->submission_id][] = $row;
            }
        }
        return $out;
    }

    /**
     * Stato leggibile di una riga: Sì / No / Revocato.
     */
    public static function status_label($row) {
        if (!(int) $row->given) return __('No', 'db-form-builder');
        if (!empty($row->revoked_at)) return __('Revocato', 'db-form-builder');
        return __('Sì', 'db-form-builder');
    }

    /**
     * Payload webhook: [{ key, given, given_at, policy_version }].
     */
    public static function webhook_items($rows) {
        $items = [];
        foreach ((array) $rows as $row) {
            $items[] = [
                'key'            => (string) $row->consent_key,
                'given'          => (bool) (int) $row->given,
                'given_at'       => mysql2date('c', $row->given_at, false),
                'policy_version' => (int) $row->policy_version,
            ];
        }
        return $items;
    }

    /**
     * Testo per il segnaposto {consensi}: "marketing: Sì; newsletter: No".
     */
    public static function summary_text($rows) {
        $parts = [];
        foreach ((array) $rows as $row) {
            $parts[] = $row->consent_key . ': ' . self::status_label($row);
        }
        return implode('; ', $parts);
    }

    // =========================================================
    // IDENTIFICAZIONE DELL'INTERESSATO
    // =========================================================

    /**
     * Secret dedicato (option non autoload), separato dai salt di WP:
     * ruotare i salt non deve invalidare i link di revoca già inviati né
     * scollegare i consensi dall'interessato nelle richieste DSAR.
     */
    private static function secret() {
        $secret = get_option('dbfb_consent_secret', '');
        if (!is_string($secret) || strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            update_option('dbfb_consent_secret', $secret, false);
        }
        return $secret;
    }

    /**
     * HMAC dell'email normalizzata: permette di ritrovare i consensi di un
     * interessato (DSAR) senza conservare l'email in chiaro.
     */
    public static function subject_hash($email) {
        $email = strtolower(trim((string) $email));
        if ($email === '' || !is_email($email)) return '';
        return hash_hmac('sha256', 'subject|' . $email, self::secret());
    }

    public static function mask_email($email) {
        $email = strtolower(trim((string) $email));
        $at = strpos($email, '@');
        if ($at === false || $at < 1) return '';
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $masked = strlen($local) <= 2
            ? str_repeat('*', strlen($local))
            : substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 2)) . substr($local, -1);
        return mb_substr($masked . '@' . $domain, 0, 190);
    }

    // =========================================================
    // REVOCA
    // =========================================================

    private static function revoke_signature($submission_id) {
        return hash_hmac('sha256', 'revoke|' . (int) $submission_id, self::secret());
    }

    /**
     * Link di revoca per una submission: nessuna scadenza (il diritto di
     * revoca non scade). Vale per tutti i consensi dati in quell'invio.
     */
    public static function revoke_url($submission_id) {
        return add_query_arg(
            [
                'action' => 'dbfb_revoke',
                's'      => (int) $submission_id,
                'sig'    => self::revoke_signature($submission_id),
            ],
            admin_url('admin-post.php')
        );
    }

    /**
     * Consensi dati e non ancora revocati di una submission.
     */
    public static function active_for_submission($submission_id) {
        return array_values(array_filter(self::for_submission($submission_id), function ($row) {
            return (int) $row->given === 1 && empty($row->revoked_at);
        }));
    }

    /**
     * Revoca dei consensi indicati (righe dati e non revocate).
     *
     * @param int[]  $consent_ids
     * @param string $source 'link' | 'admin'
     * @return int Numero di consensi revocati.
     */
    public static function revoke($consent_ids, $source) {
        $consent_ids = array_values(array_filter(array_map('intval', (array) $consent_ids)));
        if (!$consent_ids || !self::table_exists()) return 0;

        global $wpdb;
        $in = implode(',', array_fill(0, count($consent_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder %d generati in $in.
            'SELECT * FROM ' . self::table() . " WHERE id IN ($in) AND given = 1 AND revoked_at IS NULL",
            $consent_ids
        ));
        if (!$rows) return 0;

        $now = current_time('mysql');
        $source = in_array($source, ['link', 'admin'], true) ? $source : 'admin';
        $revoked = [];
        foreach ($rows as $row) {
            $ok = $wpdb->update(
                self::table(),
                ['revoked_at' => $now, 'revoke_source' => $source],
                ['id' => (int) $row->id, 'revoked_at' => null],
                ['%s', '%s'],
                ['%d', '%s']
            );
            if (!$ok) continue;
            $row->revoked_at = $now;
            $row->revoke_source = $source;
            $revoked[] = $row;

            /**
             * Un consenso facoltativo è stato revocato (2.16.0).
             * Per sincronizzare CRM e newsletter.
             *
             * @param int    $consent_id
             * @param string $consent_key
             * @param int    $submission_id
             */
            do_action('dbfb_consent_revoked', (int) $row->id, (string) $row->consent_key, (int) $row->submission_id);
        }

        self::enqueue_revoke_webhooks($revoked);
        return count($revoked);
    }

    /**
     * Webhook di revoca (opzionale per form, stesso sistema async + HMAC).
     * Una delivery per submission con tutte le voci revocate insieme.
     */
    private static function enqueue_revoke_webhooks($rows) {
        $by_submission = [];
        foreach ($rows as $row) {
            $by_submission[(int) $row->submission_id][] = $row;
        }
        foreach ($by_submission as $submission_id => $items) {
            $form_id  = (int) $items[0]->form_id;
            $settings = get_post_meta($form_id, '_dbfb_settings', true);
            if (!is_array($settings) || empty($settings['enable_webhook']) || empty($settings['webhook_url']) || empty($settings['webhook_on_revoke'])) {
                continue;
            }
            $form = get_post($form_id);
            DBFB_Webhook::enqueue($form_id, $submission_id, $settings['webhook_url'], [
                'event'         => 'consent_revoked',
                'form_id'       => $form_id,
                'form_title'    => $form ? $form->post_title : '',
                'submission_id' => $submission_id,
                'consents'      => array_map(function ($row) {
                    return [
                        'key'        => (string) $row->consent_key,
                        'revoked_at' => mysql2date('c', $row->revoked_at, false),
                        'source'     => (string) $row->revoke_source,
                    ];
                }, $items),
            ]);
        }
    }

    /**
     * Pagina pubblica di revoca: admin-post.php?action=dbfb_revoke (priv + nopriv).
     *
     * GET: pagina di conferma, mai una revoca (i client email e gli
     * antivirus aprono i link in anteprima). POST: revoca le voci scelte.
     * Endpoint anonimo, regola della suite: niente nonce (sarebbe uguale
     * per tutti), autenticazione con la firma HMAC del link, controllo di
     * Origin/Referer sul POST e rate limit per IP con hash salato.
     */
    public static function handle_revoke() {
        // phpcs:disable WordPress.Security.NonceVerification -- autenticato da firma HMAC + origine, vedi sopra.
        $submission_id = isset($_REQUEST['s']) ? absint(wp_unslash($_REQUEST['s'])) : 0;
        $sig = isset($_REQUEST['sig']) ? sanitize_text_field(wp_unslash($_REQUEST['sig'])) : '';
        $is_post = isset($_SERVER['REQUEST_METHOD']) && strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) === 'POST';
        $title = __('Revoca del consenso', 'db-form-builder');
        nocache_headers();

        if (self::is_revoke_rate_limited()) {
            wp_die(esc_html__('Troppe richieste in poco tempo. Riprova tra qualche minuto.', 'db-form-builder'), esc_html($title), ['response' => 429]);
        }
        // Link di esempio delle email di test (s = 0).
        if ($submission_id === 0 && $sig !== '' && hash_equals(self::revoke_signature(0), $sig)) {
            wp_die('<h1>' . esc_html($title) . '</h1><p>' . esc_html__('Questo è il link di esempio dell\'email di test. Nelle email reali porta alla pagina in cui chi ha compilato il form sceglie quali consensi revocare.', 'db-form-builder') . '</p>', esc_html($title), ['response' => 200]);
        }
        if (!$submission_id || $sig === '' || !hash_equals(self::revoke_signature($submission_id), $sig)) {
            wp_die(esc_html__('Link di revoca non valido. Copia il link completo dall\'email ricevuta.', 'db-form-builder'), esc_html($title), ['response' => 403]);
        }

        $active = self::active_for_submission($submission_id);

        if ($is_post) {
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- confrontati solo come host.
            $origin = !empty($_SERVER['HTTP_ORIGIN']) ? wp_unslash($_SERVER['HTTP_ORIGIN']) : (!empty($_SERVER['HTTP_REFERER']) ? wp_unslash($_SERVER['HTTP_REFERER']) : '');
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if (!DBFB_Submit::origin_matches($origin, [home_url(), site_url(), admin_url()])) {
                wp_die(esc_html__('Richiesta non valida: apri di nuovo il link dall\'email e riprova.', 'db-form-builder'), esc_html($title), ['response' => 403]);
            }
            $chosen = isset($_POST['consent_ids']) ? array_map('absint', (array) wp_unslash($_POST['consent_ids'])) : [];
            $allowed = wp_list_pluck($active, 'id');
            $allowed = array_map('intval', $allowed);
            $count = self::revoke(array_intersect($chosen, $allowed), 'link');
            $message = $count > 0
                ? sprintf(
                    /* translators: %d: numero di consensi revocati */
                    _n('Hai revocato %d consenso. Da ora non useremo più i tuoi dati per quella finalità.', 'Hai revocato %d consensi. Da ora non useremo più i tuoi dati per quelle finalità.', $count, 'db-form-builder'),
                    $count
                )
                : __('Nessun consenso selezionato: non è cambiato nulla.', 'db-form-builder');
            wp_die('<h1>' . esc_html($title) . '</h1><p>' . esc_html($message) . '</p>', esc_html($title), ['response' => 200]);
        }

        if (!$active) {
            wp_die('<h1>' . esc_html($title) . '</h1><p>' . esc_html__('Non ci sono consensi attivi da revocare per questo invio: potrebbero essere già stati revocati.', 'db-form-builder') . '</p>', esc_html($title), ['response' => 200]);
        }

        $html  = '<h1>' . esc_html($title) . '</h1>';
        $html .= '<p>' . esc_html__('Seleziona i consensi che vuoi revocare e conferma. La revoca non ha effetto sui trattamenti già svolti (art. 7.3 GDPR).', 'db-form-builder') . '</p>';
        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $html .= '<input type="hidden" name="action" value="dbfb_revoke">';
        $html .= '<input type="hidden" name="s" value="' . esc_attr($submission_id) . '">';
        $html .= '<input type="hidden" name="sig" value="' . esc_attr($sig) . '">';
        $html .= '<fieldset style="border:0;padding:0;margin:0 0 16px"><legend style="font-weight:600;margin-bottom:8px">' . esc_html__('Consensi dati', 'db-form-builder') . '</legend>';
        foreach ($active as $row) {
            $id = 'dbfb-revoke-' . (int) $row->id;
            $html .= '<p><input type="checkbox" name="consent_ids[]" value="' . esc_attr((int) $row->id) . '" id="' . esc_attr($id) . '" checked> ';
            $html .= '<label for="' . esc_attr($id) . '">' . esc_html($row->consent_text) . '</label><br>';
            $html .= '<small>' . esc_html(sprintf(
                /* translators: %s: data del consenso */
                __('Dato il %s', 'db-form-builder'),
                mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row->given_at)
            )) . '</small></p>';
        }
        $html .= '</fieldset>';
        $html .= '<p><button type="submit" class="button button-primary" style="min-height:44px;padding:0 20px">' . esc_html__('Revoca i consensi selezionati', 'db-form-builder') . '</button></p>';
        $html .= '</form>';
        wp_die($html, esc_html($title), ['response' => 200]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html composto da parti già escapate.
        // phpcs:enable WordPress.Security.NonceVerification
    }

    /**
     * Rate limit della pagina di revoca. Prefisso dbfb_rate_ così i
     * transient vengono ripuliti anche da uninstall.php.
     */
    private static function is_revoke_rate_limited() {
        $ip = DB_Form_Builder::get_client_ip();
        if ($ip === '') return false;
        $limit  = (int) apply_filters('dbfb_revoke_rate_limit', self::REVOKE_RATE_LIMIT);
        $window = (int) apply_filters('dbfb_revoke_rate_window', self::REVOKE_RATE_WINDOW);
        if ($limit <= 0 || $window <= 0) return false;
        $key   = 'dbfb_rate_revoke_' . md5(DB_Form_Builder::hash_ip($ip));
        $count = (int) get_transient($key);
        if ($count >= $limit) return true;
        set_transient($key, $count + 1, $window);
        return false;
    }

    /**
     * Revoca manuale dalla vista Risposte (revoke_source = admin).
     */
    public static function ajax_admin_revoke() {
        check_ajax_referer('dbfb_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permessi insufficienti', 'db-form-builder')], 403);
        }
        $consent_id = absint($_POST['consent_id'] ?? 0);
        if (!$consent_id || self::revoke([$consent_id], 'admin') !== 1) {
            wp_send_json_error(['message' => __('Consenso non trovato o già revocato.', 'db-form-builder')]);
        }
        wp_send_json_success([
            'message'    => __('Consenso revocato.', 'db-form-builder'),
            'revoked_at' => current_time('mysql'),
        ]);
    }

    // =========================================================
    // CANCELLAZIONE E CONSERVAZIONE
    // =========================================================

    /**
     * Cancellazione a cascata (risposta eliminata da admin o via DSAR).
     *
     * @param int[] $submission_ids
     */
    public static function delete_for_submissions($submission_ids) {
        $ids = array_values(array_filter(array_map('intval', (array) $submission_ids)));
        if (!$ids || !self::table_exists()) return;
        global $wpdb;
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder %d generati in $in.
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . " WHERE submission_id IN ($in)", $chunk));
        }
    }

    public static function delete_for_form($form_id) {
        if (!self::table_exists()) return;
        global $wpdb;
        $wpdb->delete(self::table(), ['form_id' => (int) $form_id], ['%d']);
    }

    /**
     * Retention: la risposta scade ma i consensi dati e non revocati restano
     * fino alla revoca (prova art. 7.1 per chi continua a usarli, es. CRM).
     * Le altre righe (non dati, già revocati) seguono la risposta.
     *
     * @param int[] $submission_ids
     */
    public static function on_submissions_expired($submission_ids) {
        $ids = array_values(array_filter(array_map('intval', (array) $submission_ids)));
        if (!$ids || !self::table_exists()) return;
        global $wpdb;
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));
            $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder %d generati in $in.
                'DELETE FROM ' . self::table() . " WHERE submission_id IN ($in) AND (given = 0 OR revoked_at IS NOT NULL)",
                $chunk
            ));
        }
    }

    /**
     * Consensi rimasti senza risposta (dopo la retention) e poi revocati:
     * la prova della revoca si conserva per la stessa durata della
     * retention, poi si cancella.
     *
     * @param int $days Retention in giorni.
     * @return int Righe cancellate.
     */
    public static function cleanup_revoked_orphans($days) {
        $days = (int) $days;
        if ($days <= 0 || !self::table_exists()) return 0;
        global $wpdb;
        $submissions = $wpdb->prefix . 'dbfb_submissions';
        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . " WHERE revoked_at IS NOT NULL
               AND revoked_at < DATE_SUB(NOW(), INTERVAL %d DAY)
               AND submission_id NOT IN (SELECT id FROM $submissions)",
            $days
        ));
    }

    // =========================================================
    // DSAR (art. 15 + 17)
    // =========================================================

    /**
     * Consensi di un interessato, anche quelli sopravvissuti alla retention.
     *
     * @param string $email
     * @return object[]
     */
    public static function find_by_email($email) {
        $hash = self::subject_hash($email);
        if ($hash === '' || !self::table_exists()) return [];
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE subject_hash = %s ORDER BY id ASC LIMIT 5000',
            $hash
        ));
    }

    /**
     * Voci per l'export DSAR (gruppo separato dalle risposte).
     */
    public static function dsar_export_items($email) {
        $data = [];
        foreach (self::find_by_email($email) as $row) {
            $items = [
                ['name' => __('Consenso', 'db-form-builder'), 'value' => (string) $row->consent_key],
                ['name' => __('Testo mostrato', 'db-form-builder'), 'value' => (string) $row->consent_text],
                ['name' => __('Scelta', 'db-form-builder'), 'value' => self::status_label($row)],
                ['name' => __('Data', 'db-form-builder'), 'value' => (string) $row->given_at],
            ];
            if ($row->purpose !== '') {
                $items[] = ['name' => __('Finalità', 'db-form-builder'), 'value' => (string) $row->purpose];
            }
            if (!empty($row->privacy_url)) {
                $items[] = ['name' => __('Informativa privacy', 'db-form-builder'), 'value' => (string) $row->privacy_url];
            }
            if ((int) $row->policy_version > 0) {
                $items[] = ['name' => __('Versione informativa (Privacy Hub)', 'db-form-builder'), 'value' => (string) (int) $row->policy_version];
            }
            if (!empty($row->revoked_at)) {
                $items[] = ['name' => __('Revocato il', 'db-form-builder'), 'value' => (string) $row->revoked_at];
            }
            $data[] = [
                'group_id'    => 'dbfb-consents',
                'group_label' => __('Form Builder — Consensi facoltativi', 'db-form-builder'),
                'item_id'     => 'dbfb-consent-' . (int) $row->id,
                'data'        => $items,
            ];
        }
        return $data;
    }

    /**
     * Cancella i consensi di un interessato (DSAR art. 17).
     *
     * @return int Righe cancellate.
     */
    public static function dsar_erase($email) {
        $hash = self::subject_hash($email);
        if ($hash === '' || !self::table_exists()) return 0;
        global $wpdb;
        return (int) $wpdb->delete(self::table(), ['subject_hash' => $hash], ['%s']);
    }

    // =========================================================
    // PRIVACY HUB — REGISTRO CONSENSI (sorgente dbfb_marketing_consents)
    // =========================================================

    public static function hub_count($args = []) {
        if (!self::table_exists()) return 0;
        global $wpdb;
        list($where, $params) = self::hub_where($args);
        $sql = 'SELECT COUNT(*) FROM ' . self::table() . " $where";
        return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, $params)) : $wpdb->get_var($sql));
    }

    public static function hub_query($args = []) {
        if (!self::table_exists()) return [];
        global $wpdb;
        list($where, $params) = self::hub_where($args);
        $limit = (int) ($args['limit'] ?? $args['_internal_limit'] ?? 1000);
        $limit = max(1, min(50000, $limit));
        $sql = 'SELECT * FROM ' . self::table() . " $where ORDER BY given_at DESC LIMIT $limit";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

        $titles = [];
        $out = [];
        foreach ((array) $rows as $r) {
            if (!isset($titles[$r->form_id])) {
                $form = get_post((int) $r->form_id);
                $titles[$r->form_id] = $form ? $form->post_title : sprintf(__('Form #%d', 'db-form-builder'), (int) $r->form_id);
            }
            $out[] = [
                'id'             => 'dbfb-consent-' . (int) $r->id,
                'timestamp'      => $r->given_at,
                'subject'        => $r->subject_masked !== '' ? $r->subject_masked : '—',
                'consent_type'   => sprintf(
                    /* translators: 1: chiave del consenso, 2: titolo del form */
                    __('%1$s (form: %2$s)', 'db-form-builder'),
                    $r->consent_key,
                    $titles[$r->form_id]
                ),
                'consent_text'   => (string) $r->consent_text,
                'policy_version' => (int) $r->policy_version,
                'extra'          => [
                    'status'        => empty($r->revoked_at) ? __('attivo', 'db-form-builder') : __('revocato', 'db-form-builder'),
                    'revoked_at'    => (string) $r->revoked_at,
                    'revoke_source' => (string) $r->revoke_source,
                    'consent_key'   => (string) $r->consent_key,
                    'purpose'       => (string) $r->purpose,
                    'submission_id' => (int) $r->submission_id,
                    'form_id'       => (int) $r->form_id,
                    'privacy_url'   => (string) $r->privacy_url,
                ],
            ];
        }
        return $out;
    }

    /**
     * Solo consensi dati (given = 1). Filtri: data, interessato (email
     * completa → hash, altrimenti testo sulla forma mascherata), chiave.
     */
    private static function hub_where($args) {
        global $wpdb;
        $where  = ['given = 1'];
        $params = [];
        if (!empty($args['date_from'])) {
            $where[]  = 'given_at >= %s';
            $params[] = $args['date_from'] . ' 00:00:00';
        }
        if (!empty($args['date_to'])) {
            $where[]  = 'given_at <= %s';
            $params[] = $args['date_to'] . ' 23:59:59';
        }
        if (!empty($args['subject'])) {
            $subject = (string) $args['subject'];
            if (is_email($subject)) {
                $where[]  = 'subject_hash = %s';
                $params[] = self::subject_hash($subject);
            } else {
                $where[]  = 'subject_masked LIKE %s';
                $params[] = '%' . $wpdb->esc_like($subject) . '%';
            }
        }
        if (!empty($args['consent_key'])) {
            $where[]  = 'consent_key = %s';
            $params[] = sanitize_key($args['consent_key']);
        }
        return ['WHERE ' . implode(' AND ', $where), $params];
    }
}
