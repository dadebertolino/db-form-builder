<?php
if (!defined('ABSPATH')) exit;

class DB_Form_Builder {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Core
        add_action('init', [$this, 'register_post_type']);
        add_action('admin_init', [$this, 'handle_form_actions']);
        add_action('admin_init', [__CLASS__, 'maybe_upgrade_schema']);
        add_action('admin_init', [__CLASS__, 'schedule_cleanup_cron']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_notices', [__CLASS__, 'maybe_warn_nginx_uploads']);
        add_action('admin_init', [__CLASS__, 'handle_nginx_notice_dismiss']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_scripts']);
        add_shortcode('dbfb_form', [$this, 'render_form_shortcode']);

        // Cron (2.3.0): retention automatica delle submission. Handler
        // wired anche su frontend perché wp-cron viene triggerato da
        // qualsiasi pageload, non solo admin.
        add_action('dbfb_cleanup_submissions', [__CLASS__, 'cleanup_expired_submissions']);
        add_action('dbfb_cleanup_deliveries', [__CLASS__, 'cleanup_old_deliveries']);

        register_activation_hook(DBFB_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(DBFB_PLUGIN_FILE, [__CLASS__, 'unschedule_cleanup_cron']);

        // Builder
        add_action('wp_ajax_dbfb_save_form', ['DBFB_Builder', 'ajax_save_form']);
        add_action('wp_ajax_dbfb_create_from_template', ['DBFB_Builder', 'ajax_create_from_template']);

        // Submit
        add_action('wp_ajax_dbfb_submit_form', ['DBFB_Submit', 'ajax_submit_form']);
        add_action('wp_ajax_nopriv_dbfb_submit_form', ['DBFB_Submit', 'ajax_submit_form']);

        // Submissions
        add_action('wp_ajax_dbfb_export_csv', ['DBFB_Submissions', 'ajax_export_csv']);

        // Email
        add_action('wp_ajax_dbfb_send_test_email', ['DBFB_Email', 'ajax_send_test_email']);

        // Settings
        add_action('wp_ajax_dbfb_save_global_settings', ['DBFB_Settings', 'ajax_save_global_settings']);
        add_action('wp_ajax_dbfb_test_recaptcha', ['DBFB_Settings', 'ajax_test_recaptcha']);
        add_action('wp_ajax_dbfb_test_email', ['DBFB_Settings', 'ajax_test_email']);
        add_action('wp_ajax_dbfb_cleanup_now', ['DBFB_Settings', 'ajax_cleanup_now']);

        // Gutenberg
        add_action('init', ['DBFB_Gutenberg', 'register_block']);

        // Widget
        add_action('widgets_init', function () {
 register_widget('DBFB_Widget');
});

        // Privacy declarations (2.3.0+): dichiara i trattamenti del Form
        // Builder al registro privacy unificato. Dalla 2.9.0 si aggancia sia al
        // filter dbph_processing_register del DB Privacy Hub (canonico) sia al
        // filter legacy dbseo_processing_register del SEO Manager 1.2.x.
        // Inerte se nessuno dei due plugin è installato.
        DBFB_Privacy_Declarations::init();

        // Privacy DSAR (2.5.0+): registra exporter/eraser per gestire le
        // richieste GDPR art. 15 (right of access) e art. 17 (right of erasure)
        // via Tools → Export/Erase Personal Data. Dalla 2.9.0 aggancio doppio
        // canale: filter dell'Hub (primario) + filter core di WP (fallback).
        DBFB_Privacy_DSAR::init();

        // GDPR compliance notice (2.10.0): admin notice che segnala i form
        // pubblicati senza checkbox di consenso che non sono stati marcati
        // come "consenso intenzionalmente disabilitato". Solo nelle pagine
        // admin del Form Builder.
        DBFB_GDPR_Compliance_Notice::init();

        // Webhook delivery system (2.7.0): registra l'handler del cron
        // dbfb_webhook_dispatch che processa le deliveries in coda con
        // retry/backoff esponenziale, HMAC signing opt-in, dead-letter
        // queue dopo 5 tentativi.
        DBFB_Webhook::init();
    }

    // =========================================================
    // ACTIVATION & DB
    // =========================================================

    public function activate() {
        self::maybe_create_table();
        self::maybe_upgrade_schema();
        self::schedule_cleanup_cron();
        self::schedule_deliveries_cleanup_cron();
    }

    /**
     * Pianifica il cron di pulizia delle submission scadute.
     *
     * Frequenza: daily. Hook: dbfb_cleanup_submissions.
     *
     * Idempotente: se il cron è già pianificato non fa nulla. Chiamato
     * sia da activation hook sia da maybe_upgrade_schema, così funziona
     * anche su installazioni che attivano il plugin via mu-plugins o
     * WP-CLI bulk install.
     */
    public static function schedule_cleanup_cron() {
        if (!wp_next_scheduled('dbfb_cleanup_submissions')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'dbfb_cleanup_submissions');
        }
    }

    /**
     * Pianifica il cron di pulizia delle webhook deliveries vecchie (2.7.0).
     *
     * Frequenza: weekly. Hook: dbfb_cleanup_deliveries.
     * Politica: success > 30 giorni → DELETE; failed/dead > 90 giorni → DELETE.
     * Le pending vengono lasciate (sono attive e non si toccano).
     */
    public static function schedule_deliveries_cleanup_cron() {
        if (!wp_next_scheduled('dbfb_cleanup_deliveries')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', 'dbfb_cleanup_deliveries');
        }
    }

    /**
     * Rimuove il cron al deactivate del plugin.
     *
     * Importante per evitare che il cron resti orfano dopo la
     * disattivazione: se l'utente disattiva senza disinstallare, non
     * vogliamo che WP continui a triggerare l'hook (anche se al massimo
     * sarebbe un no-op senza handler).
     */
    public static function unschedule_cleanup_cron() {
        $timestamp = wp_next_scheduled('dbfb_cleanup_submissions');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'dbfb_cleanup_submissions');
        }
        // 2.7.0: anche il cron deliveries.
        $ts2 = wp_next_scheduled('dbfb_cleanup_deliveries');
        if ($ts2) {
            wp_unschedule_event($ts2, 'dbfb_cleanup_deliveries');
        }
    }

    /**
     * Cron handler: cancella deliveries vecchie dalla tabella deliveries (2.7.0).
     *
     * Politica:
     *  - success: 30 giorni
     *  - failed/dead: 90 giorni
     *  - pending: MAI (sono attive)
     *
     * Limite per esecuzione: 5000 righe per evitare lock prolungati su
     * installazioni con webhook ad alta frequenza.
     */
    public static function cleanup_old_deliveries() {
        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_webhook_deliveries';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return 0;
        }

        $deleted = 0;

        // success > 30 giorni
        $deleted += (int) $wpdb->query(
            "DELETE FROM $table
             WHERE status = 'success'
               AND created_at < (NOW() - INTERVAL 30 DAY)
             LIMIT 5000"
        );

        // failed / dead > 90 giorni
        $deleted += (int) $wpdb->query(
            "DELETE FROM $table
             WHERE status IN ('failed', 'dead')
               AND created_at < (NOW() - INTERVAL 90 DAY)
             LIMIT 5000"
        );

        if ($deleted > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("DB Form Builder: cleanup_old_deliveries cancellate $deleted righe");
        }
        return $deleted;
    }

    /**
     * Cron handler: cancella le submission più vecchie di N giorni.
     *
     * Configurabile via global_settings['submissions_retention_days'].
     * Valore 0 = retention illimitata, il cron resta pianificato ma
     * non cancella nulla (così se l'admin riattiva la retention
     * successivamente, riprende a funzionare senza ri-installare).
     *
     * Soglia di sicurezza: cancelliamo al massimo 10000 righe per
     * esecuzione per evitare di lockare la tabella su installazioni
     * con anni di submission accumulate. Le righe rimanenti verranno
     * cancellate il giorno successivo.
     */
    public static function cleanup_expired_submissions() {
        $settings = self::get_global_settings();
        $days = (int) ($settings['submissions_retention_days'] ?? 365);

        if ($days <= 0) {
            // Retention disattivata: non cancellare nulla.
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';

        // La tabella può non esistere se il plugin è in mu-plugins ed è
        // appena stato ri-attivato.
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return 0;
        }

        // 2.4.0: cancella i file allegati delle submission scadute PRIMA
        // del DELETE DB. Selezioniamo la stessa selezione del DELETE
        // (LIMIT 10000) e per ogni riga cancelliamo i file su disco.
        // Streaming a batch ridotto (200) per controllare la memoria.
        $batch = 200;
        $cap   = 10000;
        $files_deleted = 0;
        $rows_processed = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, data FROM $table WHERE submitted_at < (NOW() - INTERVAL %d DAY) LIMIT %d",
                $days, min($batch, $cap - $rows_processed)
            ));
            foreach ($rows as $row) {
                $files_deleted += self::delete_submission_files($row);
                ++$rows_processed;
                // Cancella subito la riga DB così la query successiva non
                // ripesca le stesse righe (la WHERE submitted_at non
                // distingue chi è già stato processato).
                $wpdb->delete($table, array('id' => $row->id), array('%d'));
            }
        } while (count($rows) === $batch && $rows_processed < $cap);

        $deleted = $rows_processed;

        if ($deleted > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('DB Form Builder: cleanup_expired_submissions cancellate %d righe (retention %d giorni), %d file allegati rimossi', (int) $deleted, $days, (int) $files_deleted));
        }

        /**
         * Action emessa dopo ogni esecuzione del cron, anche con 0 righe
         * cancellate. Utile per integrazioni esterne (es. invio notifica
         * admin, log custom, ecc.).
         *
         * @param int $deleted Numero di righe cancellate (0 se retention=0
         *                     o se non c'erano scadute).
         * @param int $days    Soglia di retention applicata.
         */
        do_action('dbfb_cleanup_submissions_done', (int) $deleted, $days);

        return (int) $deleted;
    }

    /**
     * Crea la tabella delle submission se non esiste.
     *
     * Schema 2.x compatibile: la colonna ip_address è preservata per
     * backward compat. Le nuove installazioni avranno anche ip_hash.
     */
    public static function maybe_create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Tabella submissions (storica).
        $table = $wpdb->prefix . 'dbfb_submissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $sql = "CREATE TABLE $table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                form_id bigint(20) NOT NULL,
                data longtext NOT NULL,
                submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
                ip_address varchar(45),
                ip_hash varchar(64),
                gdpr_consent_given tinyint(1) DEFAULT NULL,
                gdpr_consent_text text,
                gdpr_consent_timestamp datetime DEFAULT NULL,
                gdpr_consent_privacy_url varchar(500) DEFAULT NULL,
                gdpr_consent_policy_version bigint(20) UNSIGNED DEFAULT 0,
                PRIMARY KEY (id),
                KEY form_id (form_id),
                KEY submitted_at (submitted_at),
                KEY gdpr_consent_policy_version (gdpr_consent_policy_version)
            ) $charset_collate;";
            dbDelta($sql);
        }

        // Tabella webhook deliveries (2.7.0+).
        // Una riga per ogni tentativo di invio webhook. Stati: pending,
        // success, failed, dead. Il sistema retry rilegge righe pending
        // con next_attempt_at < NOW().
        $table_dlv = $wpdb->prefix . 'dbfb_webhook_deliveries';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_dlv'") !== $table_dlv) {
            $sql_dlv = "CREATE TABLE $table_dlv (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                form_id bigint(20) NOT NULL,
                submission_id bigint(20) DEFAULT NULL,
                url text NOT NULL,
                payload longtext NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                attempts smallint(5) UNSIGNED NOT NULL DEFAULT 0,
                last_status_code smallint(5) UNSIGNED DEFAULT NULL,
                last_error text DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                last_attempt_at datetime DEFAULT NULL,
                next_attempt_at datetime DEFAULT NULL,
                PRIMARY KEY (id),
                KEY status_next (status, next_attempt_at),
                KEY form_id (form_id),
                KEY submission_id (submission_id)
            ) $charset_collate;";
            dbDelta($sql_dlv);
        }

        // Marca lo schema come aggiornato all'ultima versione.
        update_option('dbfb_schema_version', self::SCHEMA_VERSION);
    }

    /**
     * Versione corrente dello schema delle tabelle del plugin.
     *
     * Incrementare quando si aggiunge/modifica una colonna, un indice
     * o una nuova tabella. Il check di upgrade gira just-in-time in
     * admin_init e in attivazione, quindi anche installazioni in
     * mu-plugins o WP-CLI bulk hanno lo schema corretto.
     */
    const SCHEMA_VERSION = 4;

    /**
     * Aggiorna lo schema esistente da versioni precedenti.
     *
     * Migrazioni applicate:
     *  - v1 → v2 (Form Builder 2.3.0): aggiunge ip_hash + indice submitted_at
     *
     * Le submission esistenti mantengono il loro ip_address in chiaro
     * (backward compat). Per le nuove submission, il salvataggio popolerà
     * ip_hash secondo la modalità configurata e lascerà ip_address vuoto.
     */
    public static function maybe_upgrade_schema() {
        $current = (int) get_option('dbfb_schema_version', 1);

        if ($current >= self::SCHEMA_VERSION) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';

        // Se la tabella non esiste ancora (mai attivato), maybe_create_table
        // la creerà già con lo schema più recente.
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return;
        }

        // v1 → v2: aggiunge ip_hash e indice submitted_at.
        if ($current < 2) {
            $col = $wpdb->get_var("SHOW COLUMNS FROM $table LIKE 'ip_hash'");
            if (!$col) {
                $wpdb->query("ALTER TABLE $table ADD COLUMN ip_hash varchar(64) AFTER ip_address");
            }
            $idx = $wpdb->get_var("SHOW INDEX FROM $table WHERE Key_name = 'submitted_at'");
            if (!$idx) {
                // dbDelta non aggiunge indici a tabelle esistenti in modo affidabile.
                // Fallisce silenziosamente se l'indice esiste già.
                $wpdb->query("ALTER TABLE $table ADD INDEX submitted_at (submitted_at)");
            }
        }

        // v2 → v3 (Form Builder 2.7.0): crea la tabella webhook_deliveries.
        // maybe_create_table è idempotente e già controlla l'esistenza,
        // quindi possiamo richiamarlo senza rischio.
        if ($current < 3) {
            self::maybe_create_table();
        }

        // v3 → v4 (Form Builder 2.11.0): aggiunge 5 colonne consenso GDPR
        // alla tabella submissions per soddisfare l'art. 7.1 GDPR (prova
        // del consenso). Le righe pre-esistenti hanno gdpr_consent_given=NULL,
        // segnalate nella UI come "consenso non documentato (versione precedente)".
        if ($current < 4) {
            $cols_to_add = array(
                'gdpr_consent_given'           => 'tinyint(1) DEFAULT NULL AFTER ip_hash',
                'gdpr_consent_text'            => 'text AFTER gdpr_consent_given',
                'gdpr_consent_timestamp'       => 'datetime DEFAULT NULL AFTER gdpr_consent_text',
                'gdpr_consent_privacy_url'     => 'varchar(500) DEFAULT NULL AFTER gdpr_consent_timestamp',
                'gdpr_consent_policy_version'  => 'bigint(20) UNSIGNED DEFAULT 0 AFTER gdpr_consent_privacy_url',
            );
            foreach ($cols_to_add as $col => $def) {
                $exists = $wpdb->get_var("SHOW COLUMNS FROM $table LIKE '$col'");
                if (!$exists) {
                    $wpdb->query("ALTER TABLE $table ADD COLUMN $col $def");
                }
            }
            // Indice per la query "consensi linkati a una versione policy".
            $idx = $wpdb->get_var("SHOW INDEX FROM $table WHERE Key_name = 'gdpr_consent_policy_version'");
            if (!$idx) {
                $wpdb->query("ALTER TABLE $table ADD INDEX gdpr_consent_policy_version (gdpr_consent_policy_version)");
            }
        }

        update_option('dbfb_schema_version', self::SCHEMA_VERSION);
    }

    // =========================================================
    // CPT
    // =========================================================

    public function register_post_type() {
        register_post_type('dbfb_form', [
            'labels' => [
                'name' => __('Form', 'db-form-builder'),
                'singular_name' => __('Form', 'db-form-builder'),
                'add_new' => __('Nuovo Form', 'db-form-builder'),
                'add_new_item' => __('Aggiungi Nuovo Form', 'db-form-builder'),
                'edit_item' => __('Modifica Form', 'db-form-builder'),
            ],
            'public' => false,
            'show_ui' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
        ]);
    }

    // =========================================================
    // ADMIN MENU
    // =========================================================

    /**
     * Admin notice (solo pagine del plugin) per server non-Apache (2.11.1).
     *
     * La cartella upload/dbfb/ è protetta da un .htaccess che nega
     * l'esecuzione di contenuti attivi, ma Nginx ignora .htaccess. La
     * difesa primaria resta la validazione MIME lato PHP; questo avviso
     * ricorda all'admin di aggiungere la regola equivalente nella config
     * del server. Dismissibile per-utente.
     */
    public static function maybe_warn_nginx_uploads() {
        if (!current_user_can('manage_options')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos($screen->id, 'dbfb') === false) return;

        if (get_user_meta(get_current_user_id(), 'dbfb_dismissed_nginx_notice', true)) return;

        $server = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '';
        $is_apache = (stripos($server, 'apache') !== false || stripos($server, 'litespeed') !== false);
        if ($is_apache || $server === '') return;

        $dismiss_url = wp_nonce_url(
            add_query_arg('dbfb_dismiss_nginx', '1'),
            'dbfb_dismiss_nginx'
        );

        echo '<div class="notice notice-warning is-dismissible"><p><strong>'
            . esc_html__('DB Form Builder — protezione upload su Nginx', 'db-form-builder')
            . '</strong><br>'
            . esc_html__('Gli allegati dei form sono in una cartella protetta da .htaccess, ignorato da Nginx. Il contenuto dei file è comunque validato lato server, ma per una difesa esplicita aggiungi alla config del sito:', 'db-form-builder')
            . '</p><p><code style="display:block;padding:8px;user-select:all;">location ~* /uploads/dbfb/.*\.(php|phtml|phar|cgi|pl|py|sh|svg|html?)$ { deny all; }</code></p>'
            . '<p><a href="' . esc_url($dismiss_url) . '" class="button button-secondary">' . esc_html__('Ho capito, non mostrare più', 'db-form-builder') . '</a></p></div>';
    }

    /**
     * Gestisce il dismiss dell'avviso Nginx (2.11.1).
     */
    public static function handle_nginx_notice_dismiss() {
        if (!isset($_GET['dbfb_dismiss_nginx'])) return;
        if (!current_user_can('manage_options')) return;
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'dbfb_dismiss_nginx')) return;
        update_user_meta(get_current_user_id(), 'dbfb_dismissed_nginx_notice', 1);
    }

    public function admin_menu() {
        add_menu_page(
            __('Form Builder', 'db-form-builder'),
            __('Form Builder', 'db-form-builder'),
            'manage_options', 'dbfb-forms',
            [$this, 'render_forms_page'],
            'dashicons-feedback', 30
        );

        add_submenu_page('dbfb-forms', __('Tutti i Form', 'db-form-builder'), __('Tutti i Form', 'db-form-builder'), 'manage_options', 'dbfb-forms', [$this, 'render_forms_page']);
        add_submenu_page('dbfb-forms', __('Nuovo Form', 'db-form-builder'), __('Nuovo Form', 'db-form-builder'), 'manage_options', 'dbfb-new-form', ['DBFB_Builder', 'render_new_form']);
        add_submenu_page('dbfb-forms', __('Risposte', 'db-form-builder'), __('Risposte', 'db-form-builder'), 'manage_options', 'dbfb-submissions', ['DBFB_Submissions', 'render_all_submissions_page']);
        add_submenu_page('dbfb-forms', __('Webhook Deliveries', 'db-form-builder'), __('Webhook Deliveries', 'db-form-builder'), 'manage_options', 'dbfb-webhook-deliveries', [__CLASS__, 'render_webhook_deliveries_page']);
        add_submenu_page('dbfb-forms', __('Impostazioni', 'db-form-builder'), __('Impostazioni', 'db-form-builder'), 'manage_options', 'dbfb-settings', ['DBFB_Settings', 'render_page']);
    }

    /**
     * Render della pagina "Webhook Deliveries" (2.7.0).
     *
     * Mostra le ultime 200 deliveries con filtro per status (all/pending/
     * success/failed/dead). Gestisce le azioni bulk:
     *  - retry: rimette in coda le deliveries selezionate (anche dead/failed)
     *  - delete: cancella le righe dal log (la submission resta intatta)
     *
     * Capability: manage_options. Nonce su entrambe le azioni.
     */
    public static function render_webhook_deliveries_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Permessi insufficienti', 'db-form-builder'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_webhook_deliveries';

        // Handler bulk actions (POST).
        if (isset($_POST['dbfb_delivery_action']) && !empty($_POST['delivery_ids'])
            && check_admin_referer('dbfb_deliveries_action')) {
            $action = $_POST['dbfb_delivery_action'];
            $ids = array_map('intval', (array) $_POST['delivery_ids']);
            $ids = array_filter($ids);

            if ($action === 'retry') {
                $retried = 0;
                foreach ($ids as $id) {
                    DBFB_Webhook::retry_delivery($id);
                    ++$retried;
                }
                wp_redirect(add_query_arg('retried', $retried, admin_url('admin.php?page=dbfb-webhook-deliveries')));
                exit;
            }

            if ($action === 'delete') {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $deleted = (int) $wpdb->query($wpdb->prepare(
                    "DELETE FROM $table WHERE id IN ($placeholders)", $ids
                ));
                wp_redirect(add_query_arg('deleted', $deleted, admin_url('admin.php?page=dbfb-webhook-deliveries')));
                exit;
            }
        }

        // Status filter.
        $allowed_statuses = array('all', 'pending', 'success', 'failed', 'dead');
        $status_filter = isset($_GET['status']) && in_array($_GET['status'], $allowed_statuses, true)
            ? $_GET['status']
            : 'all';

        // Conta per status (per i tab).
        $count_by_status = array('pending' => 0, 'success' => 0, 'failed' => 0, 'dead' => 0);
        $count_total = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $rows = $wpdb->get_results("SELECT status, COUNT(*) AS n FROM $table GROUP BY status", ARRAY_A);
            foreach ((array) $rows as $r) {
                $count_by_status[$r['status']] = (int) $r['n'];
                $count_total += (int) $r['n'];
            }

            $where = '';
            $params = array();
            if ($status_filter !== 'all') {
                $where = 'WHERE status = %s';
                $params[] = $status_filter;
            }
            $sql = "SELECT * FROM $table $where ORDER BY id DESC LIMIT 200";
            $deliveries = $params
                ? $wpdb->get_results($wpdb->prepare($sql, $params))
                : $wpdb->get_results($sql);
        } else {
            $deliveries = array();
        }

        include DBFB_PLUGIN_DIR . 'templates/admin/webhook-deliveries.php';
    }

    // =========================================================
    // SCRIPTS
    // =========================================================

    public function admin_scripts($hook) {
        if (strpos($hook, 'dbfb') === false) return;

        wp_enqueue_media();
        wp_enqueue_style('dbfb-admin', DBFB_PLUGIN_URL . 'assets/css/admin.css', [], DBFB_VERSION);
        wp_enqueue_script('sortablejs', 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js', [], '1.15.0', true);
        wp_enqueue_script('dbfb-admin', DBFB_PLUGIN_URL . 'assets/js/admin.js', ['jquery', 'sortablejs'], DBFB_VERSION, true);

        wp_localize_script('dbfb-admin', 'dbfb', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dbfb_nonce'),
            'strings' => [
                'confirm_delete' => __('Sei sicuro di voler eliminare questo campo?', 'db-form-builder'),
                'saved' => __('Form salvato!', 'db-form-builder'),
                'error' => __('Errore durante il salvataggio', 'db-form-builder'),
            ]
        ]);
    }

    public function frontend_scripts() {
        $global_settings = self::get_global_settings();

        wp_enqueue_style('dbfb-frontend', DBFB_PLUGIN_URL . 'assets/css/frontend.css', [], DBFB_VERSION);
        wp_enqueue_script('dbfb-frontend', DBFB_PLUGIN_URL . 'assets/js/frontend.js', ['jquery'], DBFB_VERSION, true);

        // Nota (2.3.0): l'enqueue di google-recaptcha è stato spostato a
        // enqueue_form_dependencies(), chiamato solo durante il render dello
        // shortcode. Questo permette a should_load_recaptcha() di valutare il
        // consenso dell'utente PRIMA di richiedere lo script a Google. Lo
        // script, anche se enqueued tardivamente, viene comunque emesso nel
        // wp_footer (caricamento async).

        wp_localize_script('dbfb-frontend', 'dbfb', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dbfb_submit_nonce'),
            'recaptcha_site_key' => $global_settings['recaptcha_site_key'],
            'recaptcha_version' => $global_settings['recaptcha_version'] ?? 'v2',
        ]);
    }

    /**
     * Enqueue lazy delle dipendenze esterne per uno specifico form (2.3.0).
     *
     * Chiamato dal render dello shortcode/widget quando $form_settings è
     * disponibile. Permette al consent gate di valutare se l'utente può
     * caricare reCAPTCHA prima di emettere lo script verso Google.
     *
     * @param array $form_settings Settings del form (post meta _dbfb_settings).
     */
    public static function enqueue_form_dependencies($form_settings) {
        if (!self::should_load_recaptcha($form_settings)) {
            return;
        }
        $global = self::get_global_settings();
        $version = $global['recaptcha_version'] ?? 'v2';
        $url = $version === 'v3'
            ? 'https://www.google.com/recaptcha/api.js?render=' . esc_attr($global['recaptcha_site_key'])
            : 'https://www.google.com/recaptcha/api.js';
        wp_enqueue_script('google-recaptcha', $url, array(), null, true);
    }

    // =========================================================
    // ROUTING
    // =========================================================

    public function render_forms_page() {
        if (isset($_GET['action']) && $_GET['action'] === 'submissions' && isset($_GET['form_id'])) {
            DBFB_Submissions::render_page(intval($_GET['form_id']));
            return;
        }

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['form_id'])) {
            DBFB_Builder::render_form_builder(intval($_GET['form_id']));
            return;
        }

        $forms = get_posts([
            'post_type' => 'dbfb_form',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);

        include DBFB_PLUGIN_DIR . 'templates/admin/forms-list.php';
    }

    public function render_form_shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $form_id = intval($atts['id']);

        if (!$form_id) return '';

        $form_fields = get_post_meta($form_id, '_dbfb_fields', true) ?: [];
        $form_settings = get_post_meta($form_id, '_dbfb_settings', true) ?: [];

        if (empty($form_fields)) return '';

        // Lazy enqueue delle dipendenze esterne (2.3.0): qui sappiamo quale
        // form sta venendo renderizzato e possiamo applicare il consent gate.
        self::enqueue_form_dependencies($form_settings);

        ob_start();
        include DBFB_PLUGIN_DIR . 'templates/frontend/form.php';
        return ob_get_clean();
    }

    // =========================================================
    // ADMIN ACTIONS (before output)
    // =========================================================

    public function handle_form_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'dbfb-forms') return;

        // Delete form
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['form_id'])) {
            $form_id = intval($_GET['form_id']);
            if (isset($_GET['_wpnonce']) && !wp_verify_nonce($_GET['_wpnonce'], 'dbfb_delete_' . $form_id)) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti', 'db-form-builder'));

            wp_delete_post($form_id, true);
            global $wpdb;
            $wpdb->delete($wpdb->prefix . 'dbfb_submissions', ['form_id' => $form_id], ['%d']);
            wp_redirect(admin_url('admin.php?page=dbfb-forms&deleted=1'));
            exit;
        }

        // Duplicate form
        if (isset($_GET['action']) && $_GET['action'] === 'duplicate' && isset($_GET['form_id'])) {
            $form_id = intval($_GET['form_id']);
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'dbfb_duplicate_' . $form_id)) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti', 'db-form-builder'));

            $original = get_post($form_id);
            if (!$original) wp_die(__('Form non trovato', 'db-form-builder'));

            $new_form_id = wp_insert_post([
                'post_type' => 'dbfb_form',
                'post_title' => $original->post_title . ' (copia)',
                'post_status' => 'publish',
            ]);

            if (!is_wp_error($new_form_id)) {
                $fields = get_post_meta($form_id, '_dbfb_fields', true);
                $settings = get_post_meta($form_id, '_dbfb_settings', true);
                if ($fields) update_post_meta($new_form_id, '_dbfb_fields', $fields);
                if ($settings) update_post_meta($new_form_id, '_dbfb_settings', $settings);
                wp_redirect(admin_url('admin.php?page=dbfb-forms&action=edit&form_id=' . $new_form_id . '&duplicated=1'));
                exit;
            }
            wp_redirect(admin_url('admin.php?page=dbfb-forms'));
            exit;
        }

        // Delete single submission
        if (isset($_GET['action']) && $_GET['action'] === 'delete_submission' && isset($_GET['submission_id'])) {
            $submission_id = intval($_GET['submission_id']);
            $form_id = intval($_GET['form_id'] ?? 0);
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'dbfb_delete_sub_' . $submission_id)) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti', 'db-form-builder'));

            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_submissions';

            // 2.4.0: cancella i file allegati prima del DELETE.
            $submission = $wpdb->get_row($wpdb->prepare(
                "SELECT id, data FROM $table WHERE id = %d", $submission_id
            ));
            if ($submission) {
                self::delete_submission_files($submission);
            }

            $wpdb->delete($table, ['id' => $submission_id], ['%d']);
            $redirect_page = $form_id ? 'dbfb-forms&action=submissions&form_id=' . $form_id : 'dbfb-submissions';
            wp_redirect(admin_url('admin.php?page=' . $redirect_page . '&sub_deleted=1'));
            exit;
        }

        // Bulk delete submissions
        if (isset($_POST['dbfb_bulk_action']) && $_POST['dbfb_bulk_action'] === 'delete' && !empty($_POST['submission_ids'])) {
            $form_id = intval($_POST['form_id'] ?? 0);
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'dbfb_bulk_submissions')) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti', 'db-form-builder'));

            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_submissions';
            $ids = array_map('intval', (array) $_POST['submission_ids']);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));

            // 2.4.0: cancella i file allegati prima del DELETE bulk.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, data FROM $table WHERE id IN ($placeholders)", $ids
            ));
            foreach ($rows as $row) {
                self::delete_submission_files($row);
            }

            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id IN ($placeholders)", $ids));
            $redirect_page = $form_id ? 'dbfb-forms&action=submissions&form_id=' . $form_id : 'dbfb-submissions';
            wp_redirect(admin_url('admin.php?page=' . $redirect_page . '&sub_deleted=' . count($ids)));
            exit;
        }

        // Delete ALL submissions for a form (GDPR art. 17 — 2.3.0)
        // Operazione di massa volutamente separata dalla bulk-delete-selected
        // per richiedere una conferma esplicita ad alto rischio. Cancella in
        // un colpo solo TUTTE le righe del form senza serializzare gli ID.
        if (isset($_POST['dbfb_bulk_action']) && $_POST['dbfb_bulk_action'] === 'delete_all') {
            $form_id = intval($_POST['form_id'] ?? 0);
            if (!$form_id) wp_die(__('Form non valido', 'db-form-builder'));
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'dbfb_bulk_submissions')) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti', 'db-form-builder'));

            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_submissions';

            // Conta prima per il messaggio di conferma post-redirect.
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE form_id = %d", $form_id
            ));

            // 2.4.0: cancella i file allegati di TUTTE le submission del form.
            // Streamiamo a batch da 100 per non saturare la memoria su form
            // con migliaia di submission con allegati.
            $offset = 0;
            $batch  = 100;
            do {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, data FROM $table WHERE form_id = %d LIMIT %d OFFSET %d",
                    $form_id, $batch, $offset
                ));
                foreach ($rows as $row) {
                    self::delete_submission_files($row);
                }
                $offset += $batch;
            } while (count($rows) === $batch);

            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE form_id = %d", $form_id
            ));

            wp_redirect(admin_url('admin.php?page=dbfb-forms&action=submissions&form_id=' . $form_id . '&sub_deleted=' . $count));
            exit;
        }
    }

    // =========================================================
    // SHARED HELPERS
    // =========================================================

    public static function get_global_settings() {
        $defaults = [
            'recaptcha_version' => 'v2',
            'recaptcha_site_key' => '',
            'recaptcha_secret_key' => '',
            'from_email' => get_option('admin_email'),
            'from_name' => get_bloginfo('name'),
            // Privacy by design (2.3.0): IP hashato di default. Le installazioni
            // pre-2.3.0 che non hanno mai salvato le settings vedranno
            // automaticamente questo default — vecchie submission con IP in
            // chiaro restano leggibili (backward compat), nuove submission
            // useranno l'hash.
            'ip_storage_mode' => 'hashed',
            // Retention automatica (2.3.0): le submission più vecchie di N
            // giorni vengono cancellate da un cron giornaliero. Default 365
            // (un anno fiscale completo, ragionevole per il 99% dei casi).
            // 0 = retention illimitata (sconsigliato per GDPR art. 5.1.e
            // "limitazione della conservazione" — i dati personali devono
            // essere conservati solo per il tempo necessario alla finalità).
            'submissions_retention_days' => 365,
            // Disinstallazione (2.3.1): se true, l'uninstall.php cancella
            // tutti i dati (tabella submissions, form definiti, post meta).
            // Default false per evitare perdite accidentali in caso di
            // disinstallazione temporanea (debug, switch versione,
            // migrazione hosting).
            'delete_data_on_uninstall' => false,
        ];
        return wp_parse_args(get_option('dbfb_global_settings', []), $defaults);
    }

    /**
     * Restituisce l'IP del client.
     *
     * Strategia secure-by-default: usa REMOTE_ADDR. Gli header proxy
     * (X-Forwarded-For, CF-Connecting-IP, X-Real-IP) sono ignorati per
     * default perché possono essere spoofati da chiunque — un attaccante
     * può inviare qualsiasi X-Forwarded-For e inquinare il rate limit /
     * il log.
     *
     * Per siti realmente dietro proxy/CDN, l'amministratore deve
     * dichiararlo esplicitamente:
     *
     *   add_filter('dbfb_trust_proxy_headers', '__return_true');
     *
     * Questo è lo stesso pattern usato da DB Cookie Manager 3.0.0+
     * (filter `dbcm_trust_proxy_headers`).
     *
     * @return string IP del client, o stringa vuota se non disponibile.
     */
    public static function get_client_ip() {
        $ip = '';

        if (apply_filters('dbfb_trust_proxy_headers', false)) {
            // Ordine: il primo header che troviamo vince. Cloudflare per
            // primo perché in genere è la fonte più affidabile quando
            // presente.
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
            } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $ip = $_SERVER['HTTP_X_REAL_IP'];
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                // X-Forwarded-For è una catena "client, proxy1, proxy2" in cui
                // OGNI hop appende chi ha visto. Il client controlla la testa
                // della lista: può iniettare qualsiasi IP fittizio come primo
                // elemento. L'ULTIMO valore è quello scritto dal proxy fidato
                // più vicino a noi, quindi è l'unico affidabile quando ci
                // fidiamo del proxy. Prendere il primo elemento consentirebbe
                // di aggirare il rate limit ruotando IP falsi.
                $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $ip = trim(end($forwarded));
            }
        }

        if (empty($ip) && !empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        // Validazione: garantiamo che sia un IP plausibile prima di restituirlo.
        $ip = sanitize_text_field($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }

        return $ip;
    }

    /**
     * Hash irreversibile dell'IP per il salvataggio in DB.
     *
     * Usa SHA-256 con salt da wp_salt('auth') — irreversibile in pratica.
     * Lo stesso IP produce lo stesso hash, permettendo correlazioni
     * "stesso visitatore" (utile per rate limiting) senza memorizzare
     * mai l'IP in chiaro.
     *
     * Stesso pattern del consent log del DB Cookie Manager 3.0.0+.
     *
     * @param string $ip IP in chiaro (output di get_client_ip()).
     * @return string Hash SHA-256 (64 caratteri hex), o stringa vuota se IP vuoto.
     */
    public static function hash_ip($ip) {
        if (empty($ip)) return '';
        return hash('sha256', $ip . wp_salt('auth'));
    }

    /**
     * Restituisce la modalità di salvataggio IP attiva.
     *
     * Configurabile via dbfb_global_settings['ip_storage_mode']. Valori:
     *   - 'none'   : nessun IP loggato (campo vuoto)
     *   - 'hashed' : SHA-256 + salt, irreversibile (DEFAULT)
     *   - 'full'   : IP in chiaro (sconsigliato, solo per debug temporaneo)
     *
     * @return string 'none' | 'hashed' | 'full'
     */
    public static function get_ip_storage_mode() {
        $settings = self::get_global_settings();
        $mode = $settings['ip_storage_mode'] ?? 'hashed';
        return in_array($mode, array('none', 'hashed', 'full'), true) ? $mode : 'hashed';
    }

    /**
     * Formatta il valore IP di una submission per la UI/export (2.3.0).
     *
     * Le submission post-2.3.0 hanno ip_hash valorizzato e ip_address vuoto
     * (in modalità 'hashed'). Le submission pre-2.3.0 hanno solo ip_address
     * con l'IP in chiaro. Questo helper unifica la rappresentazione.
     *
     * Strategia:
     *  - Se ip_hash valorizzato: ritorna formato compatto "a3f8…e2c1" (8 char
     *    iniziali + 4 finali), con tooltip che spiega il significato.
     *  - Se solo ip_address: ritorna l'IP così com'è (legacy).
     *  - Se entrambi vuoti: ritorna "—" con tooltip "IP non registrato".
     *
     * @param object $submission Riga del DB submissions.
     * @param string $context    'short' (default, troncato) | 'full' (per CSV export)
     * @return array {
     *     @type string display  Stringa da mostrare
     *     @type string tooltip  Spiegazione contestuale
     *     @type string raw      Valore completo (per CSV export)
     * }
     */
    public static function format_submission_ip($submission, $context = 'short') {
        $hash    = isset($submission->ip_hash)    ? (string) $submission->ip_hash    : '';
        $address = isset($submission->ip_address) ? (string) $submission->ip_address : '';

        if ($hash !== '') {
            $short = strlen($hash) >= 12
                ? substr($hash, 0, 8) . '…' . substr($hash, -4)
                : $hash;
            return array(
                'display' => $context === 'full' ? $hash : $short,
                'tooltip' => __('Hash SHA-256 dell\'indirizzo IP (irreversibile). Usato solo per il rate limiting; lo stesso visitatore produce sempre lo stesso hash.', 'db-form-builder'),
                'raw'     => $hash,
            );
        }
        if ($address !== '') {
            return array(
                'display' => $address,
                'tooltip' => __('IP in chiaro (modalità legacy o "IP in chiaro" attiva).', 'db-form-builder'),
                'raw'     => $address,
            );
        }
        return array(
            'display' => '—',
            'tooltip' => __('IP non registrato (modalità "Non salvare IP").', 'db-form-builder'),
            'raw'     => '',
        );
    }

    /**
     * Restituisce i field definitions DA USARE per renderizzare una submission.
     *
     * Strategia (2.6.0+):
     *  - Submission post-2.6.0: leggono lo snapshot salvato in `_fields_snapshot`
     *    al momento del submit. La submission resta leggibile anche se il
     *    form è stato successivamente modificato.
     *  - Submission legacy (pre-2.6.0): fallback sui field correnti del form
     *    (`$current_fields`). Mantiene il comportamento 2.5.x di cui sono
     *    state generate.
     *
     * Lo snapshot contiene solo (id, type, label) per ogni field non-layout
     * — sufficiente per rendering UI/CSV/email. NON contiene options, validatori,
     * conditional logic: quelle sono runtime-only.
     *
     * Estrae anche i dati "puliti" dalla submission (senza la chiave riservata
     * `_fields_snapshot`) per evitare che venga renderizzata come campo.
     *
     * @param object $submission     Riga DB con `data` JSON.
     * @param array  $current_fields Field correnti del form (fallback per legacy).
     * @return array {
     *     fields: array  Field def da usare per il rendering [{id, type, label}, ...]
     *     data:   array  Valori della submission, senza chiavi riservate
     * }
     */
    public static function get_submission_fields($submission, $current_fields) {
        $payload = json_decode($submission->data ?? '', true);
        if (!is_array($payload)) $payload = array();

        $snapshot = isset($payload['_fields_snapshot']) ? $payload['_fields_snapshot'] : null;
        unset($payload['_fields_snapshot']); // mai esporlo come "campo"

        $fields = array();
        if (is_array($snapshot) && !empty($snapshot)) {
            // Submission post-2.6.0: usa lo snapshot.
            foreach ($snapshot as $f) {
                if (!is_array($f) || empty($f['id'])) continue;
                $fields[] = array(
                    'id'    => $f['id'],
                    'type'  => $f['type']  ?? 'text',
                    'label' => $f['label'] ?? $f['id'],
                );
            }
        } else {
            // Submission legacy pre-2.6.0: fallback sui field correnti, escludendo
            // gli elementi solo-layout (uguale a quanto fa la UI 2.5.x).
            if (!is_array($current_fields)) $current_fields = array();
            foreach ($current_fields as $f) {
                if (in_array($f['type'] ?? '', array('divider', 'html', 'image', 'pagebreak'), true)) continue;
                $fields[] = array(
                    'id'    => $f['id']    ?? '',
                    'type'  => $f['type']  ?? 'text',
                    'label' => $f['label'] ?? '',
                );
            }
        }

        return array(
            'fields' => $fields,
            'data'   => $payload,
        );
    }

    /**
     * Calcola l'unione delle "colonne" di una lista di submission, per CSV
     * export e UI tabella in presenza di submission storiche con snapshot
     * differenti.
     *
     * Strategia: deduplicato per id, label preferita = quella della submission
     * più recente (preserve mental model "questo campo si chiama oggi X").
     * Se nessuna submission ha snapshot per un id, usa la label dei campi
     * correnti del form. Se nemmeno quella, usa l'id come label.
     *
     * @param array $submissions     Lista di righe DB.
     * @param array $current_fields  Field correnti del form (per fallback label).
     * @return array Array di [{id, label}, ...] ordinato per prima apparizione.
     */
    public static function build_submission_columns($submissions, $current_fields) {
        $columns = array();          // id => label
        $current_labels = array();   // id => label correnti

        if (is_array($current_fields)) {
            foreach ($current_fields as $f) {
                if (in_array($f['type'] ?? '', array('divider', 'html', 'image', 'pagebreak'), true)) continue;
                if (!empty($f['id'])) {
                    $current_labels[$f['id']] = $f['label'] ?? $f['id'];
                }
            }
        }

        // Iteriamo dal più recente al più vecchio (assumiamo $submissions
        // già ordinato DESC come fa la UI). La prima label vinta resta.
        foreach ($submissions as $sub) {
            $info = self::get_submission_fields($sub, array());
            foreach ($info['fields'] as $f) {
                if (empty($f['id'])) continue;
                if (!isset($columns[$f['id']])) {
                    $columns[$f['id']] = $f['label'] !== '' ? $f['label'] : ($current_labels[$f['id']] ?? $f['id']);
                }
            }
        }

        // Aggiunge eventuali campi correnti del form che non sono mai apparsi
        // in nessuna submission (es. campo nuovo: vogliamo la colonna in
        // tabella, anche se vuota in tutte le righe esistenti).
        foreach ($current_labels as $id => $label) {
            if (!isset($columns[$id])) {
                $columns[$id] = $label;
            }
        }

        $out = array();
        foreach ($columns as $id => $label) {
            $out[] = array('id' => $id, 'label' => $label);
        }
        return $out;
    }

    /**
     * Cancella i file allegati di una submission dal disco (2.4.0+).
     *
     * Gli allegati del Form Builder NON sono registrati nella Media Library
     * di WordPress (vedi process_file_uploads): sono file salvati direttamente
     * in wp-content/uploads/dbfb/{form_id}/. Questo helper li cancella in
     * modo affidabile gestendo sia submission post-2.4.0 (con campo 'path'
     * relativo nel JSON) sia submission legacy 2.3.x (solo URL).
     *
     * Strategia di cancellazione:
     *  1. Per ogni campo file della submission:
     *     - Se è presente 'path' nel data JSON → cancella via path relativo
     *       a wp_upload_dir basedir (sicuro, portabile).
     *     - Altrimenti (legacy 2.3.x): tenta di derivare il path
     *       confrontando l'URL con baseurl di wp_upload_dir.
     *  2. Validazione path-traversal: il file deve risolversi sotto
     *     wp_upload_dir basedir, altrimenti viene saltato (non deve essere
     *     mai possibile cancellare file fuori dalla cartella uploads
     *     anche con dati corrotti/maliziosi nel JSON).
     *
     * @param object $submission Riga del DB con campo 'data' JSON.
     * @return int Numero di file effettivamente cancellati.
     */
    public static function delete_submission_files($submission) {
        if (empty($submission->data)) return 0;
        $data = json_decode($submission->data, true);
        if (!is_array($data)) return 0;

        $deleted = 0;
        $upload  = wp_upload_dir();
        $basedir = trailingslashit(realpath($upload['basedir']) ?: $upload['basedir']);
        $baseurl = trailingslashit($upload['baseurl']);

        foreach ($data as $key => $value) {
            // Skippa metadati interni del plugin (non sono campi file).
            if ($key === '_fields_snapshot') continue;
            // Un campo file può essere: array singolo {url, name, size, path}
            // oppure array di array (multiple). Normalizziamo.
            if (!is_array($value)) continue;

            $entries = isset($value['url']) ? array($value) : $value;
            if (!is_array($entries)) continue;

            foreach ($entries as $entry) {
                if (!is_array($entry)) continue;
                $abs_path = self::resolve_attachment_path($entry, $basedir, $baseurl);
                if ($abs_path && file_exists($abs_path) && is_file($abs_path)) {
                    if (@unlink($abs_path)) {
                        ++$deleted;
                    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("DB Form Builder: impossibile cancellare l'allegato {$abs_path} (permessi?)");
                    }
                }
            }
        }
        return $deleted;
    }

    /**
     * Risolve il path assoluto e SICURO di un allegato di submission.
     *
     * Validazione anti-path-traversal: il path risolto deve trovarsi sotto
     * wp_upload_dir basedir. Senza questa verifica, un attaccante che
     * riuscisse a iniettare ".." nel campo path di una submission potrebbe
     * far cancellare file arbitrari (wp-config.php, ecc.).
     *
     * @param array  $entry   Entry del campo file.
     * @param string $basedir Output di wp_upload_dir basedir, già con realpath e trailing slash.
     * @param string $baseurl Output di wp_upload_dir baseurl con trailing slash.
     * @return string|null Path assoluto verificato, o null se non risolvibile/non sicuro.
     */
    private static function resolve_attachment_path($entry, $basedir, $baseurl) {
        $candidate = '';

        // Strategia 1 (preferita, post-2.4.0): campo 'path' relativo.
        if (!empty($entry['path']) && is_string($entry['path'])) {
            // Pulizia: nessun separatore "../" né path assoluti.
            $relative = ltrim(str_replace('\\', '/', $entry['path']), '/');
            $candidate = $basedir . $relative;
        }
        // Strategia 2 (legacy 2.3.x): derivazione da URL.
        elseif (!empty($entry['url']) && is_string($entry['url'])) {
            $url = $entry['url'];
            if (strpos($url, $baseurl) === 0) {
                $relative = substr($url, strlen($baseurl));
                $relative = ltrim(str_replace('\\', '/', $relative), '/');
                $candidate = $basedir . $relative;
            }
        }

        if ($candidate === '') return null;

        // Validazione path-traversal: il path risolto DEVE stare sotto basedir.
        // Usa realpath solo se il file esiste; altrimenti normalizziamo
        // manualmente i ".." e controlliamo il prefisso.
        $real = realpath($candidate);
        if ($real !== false) {
            // File esiste: controllo realpath rigoroso.
            if (strpos($real . DIRECTORY_SEPARATOR, $basedir) !== 0
                && strpos($real, rtrim($basedir, '/\\')) !== 0) {
                return null;
            }
            return $real;
        }

        // File non esiste: verifichiamo che il path candidato non contenga
        // pattern sospetti.
        if (strpos($candidate, '..') !== false) return null;
        if (strpos($candidate, $basedir) !== 0) return null;
        return $candidate;
    }

    /**
     * Decide se caricare lo script reCAPTCHA per un form (2.3.0).
     *
     * Strategia in cascata:
     *  1. Hard Privacy del DB SEO Manager attivo → false (skip totale,
     *     coerente col senso di "Hard Privacy": niente integrazioni esterne).
     *  2. Form non ha enable_captcha o chiavi globali mancanti → false
     *     (recap stato attuale, niente cambiamenti).
     *  3. Filter dbfb_recaptcha_consent_required (default true):
     *     se l'admin lo disabilita esplicitamente, carichiamo sempre
     *     (ipotesi: ha valutato i rischi GDPR e si è preso la
     *     responsabilità di documentarli nell'informativa privacy).
     *  4. Categoria consenso filtrabile via dbfb_recaptcha_category
     *     (default 'marketing'): chi considera l'antispam un trattamento
     *     funzionale può impostarlo a 'functional'.
     *  5. Se c'è un consent manager (Cookie Manager DB o WP Consent API):
     *     rispetta la scelta dell'utente.
     *  6. Se NON c'è alcun consent manager: ritorna true (backward compat
     *     con installazioni 2.2.0 che non hanno mai gating del consenso —
     *     non rompiamo niente per chi non ha il Cookie Manager).
     *
     * @param array $form_settings  Le settings del form (dal post meta).
     * @return bool  true = carica reCAPTCHA, false = mostra placeholder.
     */
    public static function should_load_recaptcha($form_settings) {
        // 1. Hard Privacy del SEO Manager
        if (class_exists('DBSEO_Core')) {
            $hard_privacy = DBSEO_Core::instance()->get('hard_privacy_enabled');
            if (!empty($hard_privacy)) {
                return false;
            }
        }

        // 2. reCAPTCHA configurato per questo form?
        if (empty($form_settings['enable_captcha'])) {
            return false;
        }
        $global = self::get_global_settings();
        if (empty($global['recaptcha_site_key']) || empty($global['recaptcha_secret_key'])) {
            return false;
        }

        // 3. Consent gating richiesto?
        $consent_required = (bool) apply_filters('dbfb_recaptcha_consent_required', true);
        if (!$consent_required) {
            return true; // admin ha esplicitamente disabilitato il gating
        }

        // 4. Quale categoria?
        $category = (string) apply_filters('dbfb_recaptcha_category', 'marketing');

        // 5. Consent manager disponibile?
        if (class_exists('DBCM_Consent_API')) {
            return (bool) DBCM_Consent_API::has_consent($category);
        }
        if (function_exists('wp_has_consent')) {
            return (bool) wp_has_consent($category);
        }

        // 6. Backward compat: nessun consent manager → comportamento 2.2.0.
        return true;
    }
}
