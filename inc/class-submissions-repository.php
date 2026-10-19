<?php
/**
 * DBFB_Submissions_Repository — accesso alla tabella wp_dbfb_submissions (2.19.0).
 *
 * Punto unico per leggere, contare, scrivere e cancellare le risposte,
 * condiviso da admin, DSAR e (dalla 2.20.0) dalle API REST. Prima le query
 * erano sparse in core, submissions, DSAR e template, e la cancellazione di
 * una risposta (righe + allegati + webhook deliveries + consensi) era
 * ripetuta in tre punti.
 *
 * Colonne introdotte con lo schema v7:
 *  - status:       'new' | 'read' | 'archived'. Le risposte esistenti al
 *                  momento della migrazione partono come 'read'.
 *  - subject_hash: HMAC-SHA256 dell'email dell'interessato (stesso segreto e
 *                  formato di wp_dbfb_consents.subject_hash), indicizzato.
 *                  Permette di trovare le risposte di una persona senza LIKE
 *                  sul JSON e senza salvare l'email in chiaro in un'altra
 *                  colonna. Vuoto = nessuna email. Le righe pre-2.19.0 sono
 *                  riempite a batch dal cron di backfill.
 *
 * @package DBFB
 * @since   2.19.0
 */

if (!defined('ABSPATH')) exit;

class DBFB_Submissions_Repository {

    const STATUSES = array('new', 'read', 'archived');

    // Backfill di subject_hash sulle risposte pre-2.19.0.
    const BACKFILL_HOOK   = 'dbfb_backfill_subject_hash';
    // Valore: id dell'ultima risposta processata, 'done' a fine backfill.
    const BACKFILL_OPTION = 'dbfb_subject_backfill';
    const BACKFILL_BATCH  = 500;

    public static function init() {
        add_action(self::BACKFILL_HOOK, array(__CLASS__, 'run_backfill'));
        add_action('admin_init', array(__CLASS__, 'maybe_schedule_backfill'));
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'dbfb_submissions';
    }

    /**
     * Colonne della tabella, lette una volta per richiesta.
     *
     * Serve a non scrivere né filtrare su colonne che una migrazione fallita
     * (ALTER negato dall'hosting) non ha creato: fino alla 2.12.0 un INSERT
     * con una colonna mancante faceva perdere la risposta in silenzio.
     *
     * @param bool $refresh Rilegge le colonne (dopo una migrazione).
     * @return string[]
     */
    public static function columns($refresh = false) {
        static $columns = null;
        if ($columns === null || $refresh) {
            global $wpdb;
            $table   = self::table();
            $columns = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table)
                ? (array) $wpdb->get_col("SHOW COLUMNS FROM $table", 0)
                : array();
        }
        return $columns;
    }

    public static function has_column($column) {
        return in_array($column, self::columns(), true);
    }

    public static function is_valid_status($status) {
        return in_array($status, self::STATUSES, true);
    }

    // =========================================================
    // SCRITTURA
    // =========================================================

    /**
     * Inserisce una risposta. Le chiavi che non sono colonne della tabella
     * vengono scartate (vedi columns()).
     *
     * @param array $row colonna => valore.
     * @return int ID della risposta, 0 se l'INSERT è fallito.
     */
    public static function insert(array $row) {
        global $wpdb;
        $row = array_intersect_key($row, array_flip(self::columns()));
        if (!$row || $wpdb->insert(self::table(), $row) === false) {
            return 0;
        }
        // Letto subito: plugin SMTP o di log possono sovrascrivere insert_id
        // più avanti nella stessa richiesta (2.11.2).
        return (int) $wpdb->insert_id;
    }

    /**
     * Cambia lo stato di una o più risposte.
     *
     * @param int[]  $ids
     * @param string $status Uno di STATUSES.
     * @return int Righe modificate.
     */
    public static function set_status($ids, $status) {
        $ids = self::sanitize_ids($ids);
        if (!$ids || !self::is_valid_status($status) || !self::has_column('status')) return 0;

        global $wpdb;
        $table   = self::table();
        $changed = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));
            $changed += (int) $wpdb->query($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder %d generati in $in.
                "UPDATE $table SET status = %s WHERE id IN ($in)",
                array_merge(array($status), $chunk)
            ));
        }
        return $changed;
    }

    /**
     * Cancella risposte con allegati, webhook deliveries e consensi facoltativi.
     *
     * @param int[] $ids
     * @return array {rows: int, files: int}
     */
    public static function delete($ids) {
        $ids = self::sanitize_ids($ids);
        $out = array('rows' => 0, 'files' => 0);
        if (!$ids) return $out;

        global $wpdb;
        $table = self::table();
        foreach (array_chunk($ids, 200) as $chunk) {
            $in   = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder %d generati in $in.
                "SELECT id, data FROM $table WHERE id IN ($in)",
                $chunk
            ));
            $done = self::delete_rows($rows);
            $out['rows']  += $done['rows'];
            $out['files'] += $done['files'];
        }
        return $out;
    }

    /**
     * Come delete(), per righe già lette (servono id e data).
     *
     * @param object[] $rows
     * @return array {rows: int, files: int}
     */
    public static function delete_rows($rows) {
        $out = array('rows' => 0, 'files' => 0);
        $ids = array();
        foreach ((array) $rows as $row) {
            if (empty($row->id)) continue;
            // Allegati prima del DELETE (2.4.0): dopo non sapremmo più dove sono.
            $out['files'] += DB_Form_Builder::delete_submission_files($row);
            $ids[] = (int) $row->id;
        }
        if (!$ids) return $out;

        DB_Form_Builder::delete_submission_deliveries($ids);
        DBFB_Consents::delete_for_submissions($ids);

        global $wpdb;
        $table = self::table();
        $in    = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder %d generati in $in.
        $out['rows'] = (int) $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id IN ($in)", $ids));
        return $out;
    }

    /**
     * Cancella tutte le risposte di un form, con allegati, consensi e
     * webhook deliveries (2.11.2, spostato qui nella 2.19.0).
     *
     * @param int $form_id
     */
    public static function delete_by_form($form_id) {
        global $wpdb;
        $form_id = (int) $form_id;
        $table   = self::table();

        // Allegati a batch per non saturare la memoria. Cursore sull'id: le
        // righe si cancellano tutte insieme alla fine.
        $after = 0;
        $batch = 100;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, data FROM $table WHERE form_id = %d AND id > %d ORDER BY id ASC LIMIT %d",
                $form_id, $after, $batch
            ));
            foreach ($rows as $row) {
                DB_Form_Builder::delete_submission_files($row);
                $after = (int) $row->id;
            }
        } while (count($rows) === $batch);

        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE form_id = %d", $form_id));

        // 2.16.0: anche i consensi facoltativi del form, compresi quelli
        // sopravvissuti alla retention (cancellazione esplicita dell'admin).
        DBFB_Consents::delete_for_form($form_id);
        DB_Form_Builder::delete_form_deliveries($form_id);
    }

    // =========================================================
    // LETTURA
    // =========================================================

    /**
     * @param int $id
     * @return object|null
     */
    public static function get($id) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $id)) ?: null;
    }

    /**
     * Elenco paginato di risposte.
     *
     * @param array $args {
     *     @type int    $form_id  0 = tutti i form.
     *     @type string $status   '' = tutti gli stati.
     *     @type int    $since_id Solo risposte con id maggiore (polling).
     *     @type int    $per_page 0 = nessun limite.
     *     @type int    $page     1-based.
     *     @type string $order    'DESC' (default, più recenti prima) | 'ASC'.
     * }
     * @return array {rows: object[], total: int}
     */
    public static function query($args = array()) {
        $args = wp_parse_args($args, array(
            'form_id'  => 0,
            'status'   => '',
            'since_id' => 0,
            'per_page' => 50,
            'page'     => 1,
            'order'    => 'DESC',
        ));

        list($where, $params) = self::build_where($args);
        $order    = strtoupper((string) $args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $per_page = max(0, (int) $args['per_page']);

        global $wpdb;
        $table = self::table();

        $sql = "SELECT * FROM $table $where ORDER BY id $order";
        if ($per_page > 0) {
            $sql     .= ' LIMIT %d OFFSET %d';
            $params[] = $per_page;
            $params[] = (max(1, (int) $args['page']) - 1) * $per_page;
        }
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);
        $rows = (array) $rows;

        // Senza limite il totale è il numero di righe: niente seconda query.
        if ($per_page === 0) {
            $total = count($rows);
        } else {
            list($where, $params) = self::build_where($args);
            $count_sql = "SELECT COUNT(*) FROM $table $where";
            $total     = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));
        }

        return array('rows' => $rows, 'total' => $total);
    }

    /**
     * Totale e risposte nuove per ogni form, in una sola query.
     *
     * @return array form_id => {total: int, new: int}
     */
    public static function counts_by_form() {
        global $wpdb;
        $table  = self::table();
        $select = self::has_column('status') ? "SUM(status = 'new')" : '0';
        $out    = array();
        foreach ((array) $wpdb->get_results("SELECT form_id, COUNT(*) AS total, $select AS new_count FROM $table GROUP BY form_id") as $r) {
            $out[(int) $r->form_id] = array('total' => (int) $r->total, 'new' => (int) $r->new_count);
        }
        return $out;
    }

    /**
     * Numero di risposte di un form per stato.
     *
     * @param int $form_id
     * @return array status => int (tutte le chiavi di STATUSES presenti)
     */
    public static function counts_by_status($form_id) {
        global $wpdb;
        $table = self::table();
        $out   = array_fill_keys(self::STATUSES, 0);
        if (!self::has_column('status')) {
            // Migrazione non applicata: tutto conta come già letto.
            $out['read'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE form_id = %d", (int) $form_id));
            return $out;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS n FROM $table WHERE form_id = %d GROUP BY status",
            (int) $form_id
        ));
        foreach ((array) $rows as $r) {
            if (isset($out[$r->status])) $out[$r->status] = (int) $r->n;
        }
        return $out;
    }

    /**
     * Id della risposta più recente, 0 se non ce ne sono (2.22.0, polling).
     *
     * @return int
     */
    public static function latest_id() {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var("SELECT MAX(id) FROM $table");
    }

    /**
     * Risposte per giorno e per form negli ultimi N giorni (2.20.0).
     *
     * Il giorno è quello di submitted_at, cioè l'orologio del database
     * (lo stesso usato dalla retention).
     *
     * @param int $days    Giorni, oggi compreso.
     * @param int $form_id 0 = tutti i form.
     * @return object[] {day: 'Y-m-d', form_id: int, n: int}
     */
    public static function stats_by_day($days, $form_id = 0) {
        global $wpdb;
        $table  = self::table();
        $days   = max(1, (int) $days);
        $where  = 'submitted_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)';
        $params = array($days - 1);
        if ((int) $form_id > 0) {
            $where   .= ' AND form_id = %d';
            $params[] = (int) $form_id;
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder %d in $where.
            "SELECT DATE(submitted_at) AS day, form_id, COUNT(*) AS n FROM $table WHERE $where GROUP BY DATE(submitted_at), form_id ORDER BY day ASC",
            $params
        ));
    }

    // =========================================================
    // INTERESSATO (subject_hash)
    // =========================================================

    /**
     * Hash dell'email dell'interessato di una risposta.
     *
     * L'interessato è il primo campo di tipo email (snapshot della risposta,
     * poi campi correnti del form, più quelli aggiunti dal filter
     * dbfb_dsar_email_field_ids) con un indirizzo valido. Stesso HMAC di
     * DBFB_Consents::subject_hash(), così le due tabelle si possono incrociare.
     *
     * @param array $data        Dati della risposta (con _fields_snapshot se presente).
     * @param array $form_fields Campi correnti del form.
     * @param int   $form_id
     * @return string Hash esadecimale, '' se la risposta non contiene un'email.
     */
    public static function subject_hash_for($data, $form_fields, $form_id) {
        if (!is_array($data)) return '';

        $ids = array();
        $sources = array(
            isset($data['_fields_snapshot']) && is_array($data['_fields_snapshot']) ? $data['_fields_snapshot'] : array(),
            is_array($form_fields) ? $form_fields : array(),
        );
        foreach ($sources as $fields) {
            foreach ($fields as $f) {
                if (is_array($f) && ($f['type'] ?? '') === 'email' && !empty($f['id'])) {
                    $ids[] = (string) $f['id'];
                }
            }
        }
        $ids = (array) apply_filters('dbfb_dsar_email_field_ids', array_values(array_unique($ids)), (int) $form_id, $form_fields);

        foreach ($ids as $field_id) {
            $value = $data[$field_id] ?? '';
            if (is_string($value) && is_email(trim($value))) {
                return DBFB_Consents::subject_hash($value);
            }
        }
        return '';
    }

    /**
     * Ripianifica il backfill se non è finito e il cron è andato perso
     * (cron disattivato, evento cancellato da un plugin di pulizia).
     */
    public static function maybe_schedule_backfill() {
        if (get_option(self::BACKFILL_OPTION) === 'done' || !self::has_column('subject_hash')) return;
        if (!wp_next_scheduled(self::BACKFILL_HOOK)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::BACKFILL_HOOK);
        }
    }

    /**
     * Cron: calcola subject_hash per un batch di risposte pre-2.19.0 e si
     * ripianifica finché ne restano.
     *
     * Avanza con un cursore sull'id salvato nell'option, non con
     * "subject_hash IS NULL": così termina anche dove '' e NULL non si
     * distinguono, e le righe senza email non vengono rilette.
     *
     * @return int Righe aggiornate.
     */
    public static function run_backfill() {
        $cursor = get_option(self::BACKFILL_OPTION);
        if ($cursor === 'done' || !self::has_column('subject_hash')) return 0;

        global $wpdb;
        $table = self::table();
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, form_id, data FROM $table WHERE id > %d AND subject_hash IS NULL ORDER BY id ASC LIMIT %d",
            (int) $cursor, self::BACKFILL_BATCH
        ));

        $fields_cache = array();
        foreach ((array) $rows as $row) {
            $form_id = (int) $row->form_id;
            if (!isset($fields_cache[$form_id])) {
                $fields = get_post_meta($form_id, '_dbfb_fields', true);
                $fields_cache[$form_id] = is_array($fields) ? $fields : array();
            }
            $hash = self::subject_hash_for(json_decode((string) $row->data, true), $fields_cache[$form_id], $form_id);
            $wpdb->update($table, array('subject_hash' => $hash), array('id' => (int) $row->id), array('%s'), array('%d'));
            $cursor = (int) $row->id;
        }

        if (count($rows) === self::BACKFILL_BATCH) {
            update_option(self::BACKFILL_OPTION, (int) $cursor, false);
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::BACKFILL_HOOK);
        } else {
            update_option(self::BACKFILL_OPTION, 'done', false);
        }
        return count($rows);
    }

    // =========================================================
    // HELPER
    // =========================================================

    /**
     * @param array $args Vedi query().
     * @return array {0: string WHERE, 1: array params}
     */
    private static function build_where($args) {
        $where  = array();
        $params = array();
        if ((int) $args['form_id'] > 0) {
            $where[]  = 'form_id = %d';
            $params[] = (int) $args['form_id'];
        }
        if (self::is_valid_status((string) $args['status']) && self::has_column('status')) {
            $where[]  = 'status = %s';
            $params[] = (string) $args['status'];
        }
        if ((int) $args['since_id'] > 0) {
            $where[]  = 'id > %d';
            $params[] = (int) $args['since_id'];
        }
        return array($where ? 'WHERE ' . implode(' AND ', $where) : '', $params);
    }

    private static function sanitize_ids($ids) {
        return array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
    }
}
