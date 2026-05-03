<?php
/**
 * DBFB_Privacy_DSAR — Right of access (art. 15 GDPR) e right of erasure
 * (art. 17 GDPR) via la macchina nativa di WordPress.
 *
 * WordPress include `Tools → Export Personal Data` e `Tools → Erase Personal
 * Data` che orchestrano richieste DSAR partendo da un'email. Plugin che
 * salvano PII registrano "exporter" e "eraser" via due filter nativi:
 *
 *   - wp_privacy_personal_data_exporters
 *   - wp_privacy_personal_data_erasers
 *
 * WP gestisce automaticamente: invio dell'email di verifica all'utente,
 * batching della richiesta, generazione dello ZIP scaricabile, retention
 * dei risultati. Il plugin deve solo fornire i due callback.
 *
 * Strategia di matching:
 *  - Le submission del Form Builder NON sono legate a user_id: i form
 *    sono tipicamente compilati da non-loggati. L'unico modo per
 *    associare una submission a una persona è cercare l'email nei valori
 *    dei campi di tipo 'email' del form.
 *  - Match esatto (strtolower + trim) sul valore del campo, per ridurre
 *    falsi positivi (es. "info@example.com" diverso da "INFO@EXAMPLE.COM"
 *    è uguale dopo lower; "mario@x.com" diverso da "mario+spam@x.com" resta
 *    diverso).
 *  - Streaming: WP chiama il callback con $page = 1, 2, 3... finché
 *    ritorni done=true. Usiamo LIMIT/OFFSET per processare 100 submission
 *    per chiamata.
 *
 * @package DBFB
 * @since   2.5.0
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('DBFB_Privacy_DSAR')) {

    class DBFB_Privacy_DSAR {

        /**
         * Numero di submission processate per ogni chiamata callback.
         * WP invoca il callback con $page incrementale finché done=true.
         * Valore conservativo: 100 row x ~5KB JSON = 500KB di memoria
         * peak per call.
         */
        const BATCH_SIZE = 100;

        /**
         * Inizializzazione — chiamata da DB_Form_Builder->__construct().
         *
         * Aggancio doppio canale:
         *  1. Filter del DB Privacy Hub (`dbph_user_data_exporters` /
         *     `dbph_user_data_erasers`) — canale primario, usato quando
         *     l'Hub è installato. L'Hub ribalta tutto sui filter core di WP.
         *  2. Filter core di WordPress (`wp_privacy_personal_data_exporters` /
         *     `wp_privacy_personal_data_erasers`) — fallback diretto, usato
         *     quando l'Hub NON è installato, così che il plugin standalone
         *     resti pienamente conforme GDPR senza dipendenze.
         *
         * I metodi register_*_fallback() controllano `class_exists('DBPH_DSAR')`
         * per evitare doppia registrazione quando l'Hub è presente.
         */
        public static function init() {
            // Canale primario: filter dell'Hub (preferito).
            add_filter('dbph_user_data_exporters', array(__CLASS__, 'register_exporter_via_hub'));
            add_filter('dbph_user_data_erasers',   array(__CLASS__, 'register_eraser_via_hub'));

            // Fallback: filter core di WordPress, attivo solo se l'Hub non c'è.
            add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'register_exporter'));
            add_filter('wp_privacy_personal_data_erasers',   array(__CLASS__, 'register_eraser'));
        }

        /* =================================================================
         * REGISTRAZIONE VIA DB PRIVACY HUB (canale primario)
         * =============================================================== */

        public static function register_exporter_via_hub($exporters) {
            $exporters['db-form-builder'] = array(
                'label'    => __('DB Form Builder — Invii moduli', 'db-form-builder'),
                'callback' => array(__CLASS__, 'exporter_callback'),
            );
            return $exporters;
        }

        public static function register_eraser_via_hub($erasers) {
            $erasers['db-form-builder'] = array(
                'label'    => __('DB Form Builder — Invii moduli', 'db-form-builder'),
                'callback' => array(__CLASS__, 'eraser_callback'),
            );
            return $erasers;
        }

        /* =================================================================
         * EXPORTER (art. 15 — right of access)
         * =============================================================== */

        /**
         * Fallback diretto sui filter core di WP: scatta solo se il DB Privacy
         * Hub non è installato. Quando l'Hub è presente, è lui a registrare
         * l'exporter (via dbph_user_data_exporters → wp_privacy_personal_data_exporters)
         * e qui evitiamo la doppia registrazione.
         */
        public static function register_exporter($exporters) {
            if (class_exists('DBPH_DSAR')) {
                return $exporters;
            }
            $exporters['db-form-builder'] = array(
                'exporter_friendly_name' => __('DB Form Builder — Invii moduli', 'db-form-builder'),
                'callback'               => array(__CLASS__, 'exporter_callback'),
            );
            return $exporters;
        }

        /**
         * Callback: data un'email, ritorna le submission che la contengono.
         *
         * Format atteso da WP (vedi codex.wordpress.org/Plugin_Developer_Handbook):
         *   array(
         *     'data' => array(
         *       array(
         *         'group_id'    => 'dbfb-submissions',
         *         'group_label' => 'Form Builder Submissions',
         *         'item_id'     => 'dbfb-sub-42',
         *         'data'        => array(
         *           array('name' => 'Form', 'value' => 'Contattaci'),
         *           array('name' => 'Data invio', 'value' => '2026-05-02 14:30'),
         *           ...
         *         ),
         *       ),
         *       ...
         *     ),
         *     'done' => bool (true se non ci sono altre pagine),
         *   )
         *
         * @param string $email_address  Email target della DSAR.
         * @param int    $page           Pagina (1-indexed) richiesta da WP.
         * @return array
         */
        public static function exporter_callback($email_address, $page = 1) {
            $email_address = strtolower(trim((string) $email_address));
            $page = max(1, (int) $page);

            $matches = self::find_submissions_by_email($email_address, $page);

            $data = array();
            foreach ($matches['rows'] as $row) {
                $form_post = get_post($row->form_id);
                $form_title = $form_post ? $form_post->post_title : sprintf(__('Form #%d', 'db-form-builder'), $row->form_id);
                $current_fields = $form_post ? get_post_meta($row->form_id, '_dbfb_fields', true) : array();
                if (!is_array($current_fields)) $current_fields = array();

                // 2.6.0: usa lo snapshot della submission (se presente) invece
                // dei field correnti, per coerenza temporale dell'export DSAR.
                $info = DB_Form_Builder::get_submission_fields($row, $current_fields);
                $row_fields = $info['fields'];
                $payload    = $info['data'];

                $items = array(
                    array(
                        'name'  => __('Form', 'db-form-builder'),
                        'value' => $form_title,
                    ),
                    array(
                        'name'  => __('Data invio', 'db-form-builder'),
                        'value' => $row->submitted_at,
                    ),
                );

                // Per ogni campo della submission (snapshot o legacy), formattiamo il valore.
                foreach ($row_fields as $field) {
                    $field_id = $field['id'] ?? '';
                    if (!isset($payload[$field_id])) continue;

                    $value = $payload[$field_id];
                    $items[] = array(
                        'name'  => $field['label'] !== '' ? $field['label'] : $field_id,
                        'value' => self::format_value_for_export($value),
                    );
                }

                // L'IP viene incluso solo se è disponibile (rispetta storage mode).
                $ip_info = DB_Form_Builder::format_submission_ip($row, 'full');
                if ($ip_info['raw'] !== '') {
                    $items[] = array(
                        'name'  => __('Indirizzo IP', 'db-form-builder'),
                        'value' => $ip_info['raw'] . ' ' . __('(hash SHA-256, irreversibile)', 'db-form-builder'),
                    );
                }

                $data[] = array(
                    'group_id'    => 'dbfb-submissions',
                    'group_label' => __('Form Builder — Invii moduli', 'db-form-builder'),
                    'item_id'     => 'dbfb-sub-' . $row->id,
                    'data'        => $items,
                );
            }

            return array(
                'data' => $data,
                'done' => $matches['done'],
            );
        }

        /* =================================================================
         * ERASER (art. 17 — right of erasure)
         * =============================================================== */

        /**
         * Fallback diretto sui filter core di WP: vedi nota su register_exporter().
         */
        public static function register_eraser($erasers) {
            if (class_exists('DBPH_DSAR')) {
                return $erasers;
            }
            $erasers['db-form-builder'] = array(
                'eraser_friendly_name' => __('DB Form Builder — Invii moduli', 'db-form-builder'),
                'callback'             => array(__CLASS__, 'eraser_callback'),
            );
            return $erasers;
        }

        /**
         * Callback: data un'email, cancella tutte le submission che la
         * contengono insieme ai loro file allegati.
         *
         * Format atteso da WP:
         *   array(
         *     'items_removed'  => int,
         *     'items_retained' => int,
         *     'messages'       => array(string, ...),
         *     'done'           => bool,
         *   )
         *
         * @param string $email_address
         * @param int    $page
         * @return array
         */
        public static function eraser_callback($email_address, $page = 1) {
            $email_address = strtolower(trim((string) $email_address));
            $page = max(1, (int) $page);

            $matches = self::find_submissions_by_email($email_address, $page);

            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_submissions';
            $items_removed = 0;
            $files_removed = 0;
            $messages = array();

            foreach ($matches['rows'] as $row) {
                // Cancella i file allegati prima del DELETE DB
                // (riusa l'helper 2.4.0+ con validazione path-traversal).
                $files_removed += DB_Form_Builder::delete_submission_files($row);

                $deleted = $wpdb->delete($table, array('id' => $row->id), array('%d'));
                if ($deleted) $items_removed++;
            }

            if ($items_removed > 0) {
                $messages[] = sprintf(
                    /* translators: %1$d: submission cancellate, %2$d: file allegati cancellati */
                    _n(
                        'Cancellata %1$d submission del Form Builder (%2$d file allegati rimossi).',
                        'Cancellate %1$d submission del Form Builder (%2$d file allegati rimossi).',
                        $items_removed,
                        'db-form-builder'
                    ),
                    $items_removed,
                    $files_removed
                );
            }

            return array(
                'items_removed'  => $items_removed,
                'items_retained' => 0, // Cancelliamo tutto quello che troviamo: niente retention selettiva.
                'messages'       => $messages,
                'done'           => $matches['done'],
            );
        }

        /* =================================================================
         * SHARED CORE
         * =============================================================== */

        /**
         * Cerca submission che contengono l'email nei loro campi di tipo email.
         *
         * Strategia in due fasi:
         *  1. Pre-filtro SQL: la colonna `data` è LONGTEXT JSON. Usiamo
         *     LIKE '%email%' per restringere drasticamente le righe da
         *     parsare in PHP. È un'euristica: i match LIKE possono essere
         *     falsi positivi (es. l'email appare nel testo libero di un
         *     campo "Messaggio"), per questo facciamo il check fine in PHP.
         *  2. Check fine in PHP: per ogni riga candidata, parsiamo il JSON
         *     e verifichiamo che l'email corrisponda esattamente al valore
         *     di almeno un campo di tipo `email` del form di riferimento.
         *
         * @param string $email
         * @param int    $page
         * @return array {rows: object[], done: bool}
         */
        private static function find_submissions_by_email($email, $page) {
            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_submissions';

            // La tabella potrebbe non esistere in setup atipici.
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                return array('rows' => array(), 'done' => true);
            }

            // Email vuota → no match (sanity check).
            if ($email === '' || strpos($email, '@') === false) {
                return array('rows' => array(), 'done' => true);
            }

            $batch = self::BATCH_SIZE;
            $offset = ($page - 1) * $batch;

            // Pre-filtro SQL: usiamo $wpdb->esc_like per sfuggire underscore
            // e percenti nell'email che diventerebbero wildcard.
            $like = '%' . $wpdb->esc_like($email) . '%';

            $candidates = $wpdb->get_results($wpdb->prepare(
                "SELECT id, form_id, data, ip_address, ip_hash, submitted_at
                 FROM $table
                 WHERE data LIKE %s
                 ORDER BY id ASC
                 LIMIT %d OFFSET %d",
                $like, $batch, $offset
            ));

            // Stato cache form_fields per evitare get_post_meta ripetuti
            // per submission dello stesso form.
            $fields_cache = array();
            $matched = array();

            foreach ($candidates as $row) {
                $form_id = (int) $row->form_id;
                if (!isset($fields_cache[$form_id])) {
                    $fields = get_post_meta($form_id, '_dbfb_fields', true);
                    $fields_cache[$form_id] = is_array($fields) ? $fields : array();
                }
                if (self::row_contains_email($row, $fields_cache[$form_id], $email)) {
                    $matched[] = $row;
                }
            }

            // done=true quando il batch SQL è ritornato meno di BATCH_SIZE
            // righe candidate (significa che siamo a fine tabella).
            $done = count($candidates) < $batch;

            return array('rows' => $matched, 'done' => $done);
        }

        /**
         * Verifica se una submission contiene l'email cercata in un campo
         * di tipo email del form. Match case-insensitive su valore esatto.
         *
         * 2.6.0: considera anche lo snapshot della submission. Se il campo
         * email è stato rimosso dal form corrente ma esisteva al momento
         * del submit, il match continua a funzionare grazie allo snapshot.
         */
        private static function row_contains_email($row, $form_fields, $email_lc) {
            $payload = json_decode($row->data, true);
            if (!is_array($payload)) return false;

            // Identifica gli ID dei campi di tipo email da DUE fonti:
            //  - Field correnti del form (per submission legacy senza snapshot)
            //  - Snapshot della submission (per submission post-2.6.0, anche
            //    se il campo è stato successivamente rimosso dal form)
            $email_field_ids = array();
            foreach ($form_fields as $f) {
                if (($f['type'] ?? '') === 'email' && !empty($f['id'])) {
                    $email_field_ids[] = $f['id'];
                }
            }
            if (isset($payload['_fields_snapshot']) && is_array($payload['_fields_snapshot'])) {
                foreach ($payload['_fields_snapshot'] as $f) {
                    if (($f['type'] ?? '') === 'email' && !empty($f['id'])) {
                        $email_field_ids[] = $f['id'];
                    }
                }
            }
            $email_field_ids = array_values(array_unique($email_field_ids));

            // Filter pubblico: permette ad altri plugin/temi di estendere
            // la lista (es. campi 'text' usati come email per legacy).
            $email_field_ids = apply_filters(
                'dbfb_dsar_email_field_ids',
                $email_field_ids,
                $row->form_id,
                $form_fields
            );

            foreach ($email_field_ids as $field_id) {
                if (!isset($payload[$field_id])) continue;
                $value = $payload[$field_id];
                if (!is_string($value)) continue;
                if (strtolower(trim($value)) === $email_lc) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Formatta il valore di un campo per l'export DSAR (string).
         *
         * Gestisce: stringhe, array di stringhe (checkbox/multiselect),
         * file singolo (oggetto con url/name) e file multipli.
         */
        private static function format_value_for_export($value) {
            if (is_string($value)) return $value;
            if (!is_array($value))  return (string) $value;

            // File singolo: oggetto con chiavi 'url'/'name'/'size'.
            if (isset($value['url'])) {
                $name = $value['name'] ?? basename($value['url']);
                return $name . ' (' . $value['url'] . ')';
            }

            // Array di valori: può essere checkbox multipli (stringhe)
            // o file multipli (array di oggetti).
            $parts = array();
            foreach ($value as $item) {
                if (is_array($item) && isset($item['url'])) {
                    $name = $item['name'] ?? basename($item['url']);
                    $parts[] = $name . ' (' . $item['url'] . ')';
                } elseif (is_scalar($item)) {
                    $parts[] = (string) $item;
                }
            }
            return implode(', ', $parts);
        }
    }
}
