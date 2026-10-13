<?php
if (!defined('ABSPATH')) exit;

/**
 * Esporta / importa un singolo form come file JSON (2.18.0).
 *
 * Pensato per il passaggio da un sito di test alla produzione. Il file
 * contiene solo la configurazione (titolo, campi, impostazioni): mai
 * risposte, consensi o allegati.
 *
 * Scelte di sicurezza:
 *  - il secret HMAC del webhook non viene mai esportato;
 *  - all'import il webhook arriva disattivato (va ricontrollato: URL di
 *    test, secret mancante);
 *  - campi e impostazioni ripassano dalla stessa sanitizzazione del
 *    salvataggio dal builder; tipi di campo sconosciuti scartati;
 *  - l'import crea sempre un form nuovo, non sovrascrive mai.
 *
 * Gli URL del sito di origine (informativa, redirect, immagini, link dei
 * consensi) vengono riscritti con l'indirizzo del sito di destinazione.
 */
class DBFB_Import_Export {

    const FORMAT         = 'dbfb-form';
    const FORMAT_VERSION = 1;

    /** Dimensione massima del file importato. */
    const MAX_BYTES = 1048576;

    /** Tipi di campo ammessi all'import. */
    const FIELD_TYPES = [
        'text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'tel', 'number', 'date', 'url',
        'file', 'hidden', 'html', 'image', 'divider', 'pagebreak',
    ];

    public static function init() {
        add_action('admin_post_dbfb_export_form', [__CLASS__, 'handle_export']);
        add_action('admin_post_dbfb_import_form', [__CLASS__, 'handle_import']);
    }

    public static function export_url($form_id) {
        return wp_nonce_url(
            admin_url('admin-post.php?action=dbfb_export_form&form_id=' . (int) $form_id),
            'dbfb_export_' . (int) $form_id
        );
    }

    // =========================================================
    // EXPORT
    // =========================================================

    /**
     * Contenuto del file di export.
     *
     * @param int $form_id
     * @return array|null Null se il form non esiste.
     */
    public static function build_export($form_id) {
        $form = get_post((int) $form_id);
        if (!$form || $form->post_type !== 'dbfb_form') return null;

        $fields   = get_post_meta($form->ID, '_dbfb_fields', true);
        $settings = get_post_meta($form->ID, '_dbfb_settings', true);
        if (!is_array($fields)) $fields = [];
        if (!is_array($settings)) $settings = [];

        // Mai il secret HMAC fuori dal sito.
        unset($settings['webhook_secret']);

        // I documenti della conferma sono ID della Media Library, che non
        // hanno senso su un altro sito: si esportano come URL e all'import
        // si ricollegano al file con lo stesso indirizzo, se esiste.
        $documents = [];
        foreach ((array) ($settings['confirmation_documents'] ?? []) as $doc_id) {
            $url = wp_get_attachment_url((int) $doc_id);
            if ($url) $documents[] = $url;
        }
        unset($settings['confirmation_documents']);
        $settings['confirmation_documents_urls'] = $documents;

        return [
            'format'         => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'plugin_version' => DBFB_VERSION,
            'exported_at'    => gmdate('c'),
            'source_url'     => home_url(),
            'title'          => $form->post_title,
            'fields'         => array_values($fields),
            'settings'       => $settings,
        ];
    }

    /**
     * admin-post.php?action=dbfb_export_form&form_id=N (nonce + capability).
     */
    public static function handle_export() {
        $form_id = isset($_GET['form_id']) ? absint(wp_unslash($_GET['form_id'])) : 0;
        check_admin_referer('dbfb_export_' . $form_id);
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'db-form-builder'), '', ['response' => 403]);
        }
        $data = self::build_export($form_id);
        if ($data === null) {
            wp_die(esc_html__('Form non trovato', 'db-form-builder'), '', ['response' => 404]);
        }

        $slug = sanitize_title($data['title']);
        $filename = 'dbfb-form-' . ($slug !== '' ? $slug : $form_id) . '-' . gmdate('Y-m-d') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // =========================================================
    // IMPORT
    // =========================================================

    /**
     * Crea un form da un file di export.
     *
     * @param string $json
     * @return array|WP_Error ['form_id' => int, 'warnings' => string[]]
     */
    public static function import_json($json) {
        $data = json_decode((string) $json, true);
        if (!is_array($data) || ($data['format'] ?? '') !== self::FORMAT) {
            return new WP_Error('dbfb_import_format', __('Il file non è un export di DB Form Builder.', 'db-form-builder'));
        }
        if ((int) ($data['format_version'] ?? 0) < 1 || (int) $data['format_version'] > self::FORMAT_VERSION) {
            return new WP_Error('dbfb_import_version', __('Formato del file non supportato: aggiorna DB Form Builder su questo sito e riprova.', 'db-form-builder'));
        }
        if (!isset($data['fields'], $data['settings']) || !is_array($data['fields']) || !is_array($data['settings'])) {
            return new WP_Error('dbfb_import_format', __('Il file è incompleto: mancano campi o impostazioni.', 'db-form-builder'));
        }

        $warnings = [];

        // URL del sito di origine → URL di questo sito.
        $source = untrailingslashit(esc_url_raw((string) ($data['source_url'] ?? '')));
        $target = untrailingslashit(home_url());
        $fields   = $data['fields'];
        $settings = $data['settings'];
        if ($source !== '' && $source !== $target) {
            $fields   = self::replace_urls($fields, $source, $target);
            $settings = self::replace_urls($settings, $source, $target);
            $warnings[] = sprintf(
                /* translators: 1: indirizzo del sito di origine, 2: indirizzo di questo sito */
                __('Gli indirizzi %1$s sono stati sostituiti con %2$s.', 'db-form-builder'),
                $source,
                $target
            );
        }

        // Solo tipi di campo conosciuti.
        $kept = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !in_array($field['type'] ?? '', self::FIELD_TYPES, true)) {
                $warnings[] = sprintf(
                    /* translators: %s: tipo di campo */
                    __('Campo di tipo sconosciuto "%s" ignorato.', 'db-form-builder'),
                    is_array($field) ? sanitize_text_field((string) ($field['type'] ?? '')) : '?'
                );
                continue;
            }
            $kept[] = $field;
        }

        // Documenti della conferma: ricollegati per URL alla Media Library.
        $doc_ids = [];
        $missing = 0;
        foreach ((array) ($settings['confirmation_documents_urls'] ?? []) as $url) {
            $id = attachment_url_to_postid(esc_url_raw((string) $url));
            if ($id) {
                $doc_ids[] = $id;
            } else {
                ++$missing;
            }
        }
        unset($settings['confirmation_documents_urls']);
        $settings['confirmation_documents'] = $doc_ids;
        if ($missing) {
            $warnings[] = sprintf(
                /* translators: %d: numero di documenti */
                _n('%d documento della conferma non è nella Media Library di questo sito: caricalo e aggiungilo di nuovo.', '%d documenti della conferma non sono nella Media Library di questo sito: caricali e aggiungili di nuovo.', $missing, 'db-form-builder'),
                $missing
            );
        }

        // Webhook sempre disattivato all'import.
        if (!empty($settings['enable_webhook'])) {
            $settings['enable_webhook'] = false;
            $warnings[] = __('Il webhook è stato importato disattivato: controlla URL e secret HMAC (non esportato), poi riattivalo.', 'db-form-builder');
        }
        unset($settings['webhook_secret']);

        $clean_fields   = DBFB_Builder::sanitize_fields($kept);
        $clean_settings = DBFB_Builder::sanitize_settings($settings);

        // Redirect non valido su questo sito → messaggio di successo.
        if ($clean_settings['after_submit'] === 'redirect'
            && DBFB_Submit::validate_redirect_url($clean_settings['redirect_url']) === '') {
            $clean_settings['after_submit'] = 'message';
            $warnings[] = __('Il redirect dopo l\'invio non punta a questo sito ed è stato sostituito dal messaggio di successo.', 'db-form-builder');
        }

        $title = sanitize_text_field((string) ($data['title'] ?? ''));
        if ($title === '') $title = __('Form importato', 'db-form-builder');
        $existing = get_posts([
            'post_type'      => 'dbfb_form',
            'post_status'    => 'any',
            'title'          => $title,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        if ($existing) {
            $title .= ' ' . __('(importato)', 'db-form-builder');
        }

        $form_id = wp_insert_post([
            'post_type'   => 'dbfb_form',
            'post_title'  => $title,
            'post_status' => 'publish',
        ], true);
        if (is_wp_error($form_id)) return $form_id;

        update_post_meta($form_id, '_dbfb_fields', $clean_fields);
        update_post_meta($form_id, '_dbfb_settings', $clean_settings);

        return ['form_id' => (int) $form_id, 'warnings' => $warnings];
    }

    /**
     * Sostituisce l'indirizzo del sito di origine in tutte le stringhe.
     */
    private static function replace_urls($value, $source, $target) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::replace_urls($v, $source, $target);
            }
            return $value;
        }
        return is_string($value) ? str_replace($source, $target, $value) : $value;
    }

    /**
     * admin-post.php?action=dbfb_import_form (POST multipart, nonce + capability).
     */
    public static function handle_import() {
        check_admin_referer('dbfb_import_form');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'db-form-builder'), '', ['response' => 403]);
        }

        $back = admin_url('admin.php?page=dbfb-forms');
        $file = $_FILES['dbfb_import_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- verificato sotto, letto solo come JSON.
        $error = '';
        if (!$file || !isset($file['error'], $file['tmp_name'], $file['size']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            $error = __('Nessun file ricevuto. Scegli il file .json esportato e riprova.', 'db-form-builder');
        } elseif ((int) $file['size'] > self::MAX_BYTES) {
            $error = __('File troppo grande (massimo 1 MB).', 'db-form-builder');
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $error = __('Upload non valido.', 'db-form-builder');
        }

        $result = $error === ''
            ? self::import_json((string) file_get_contents($file['tmp_name'])) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file caricato locale, non un URL.
            : new WP_Error('dbfb_import_upload', $error);

        $notice = is_wp_error($result)
            ? ['type' => 'error', 'message' => $result->get_error_message()]
            : ['type' => 'success', 'form_id' => $result['form_id'], 'warnings' => $result['warnings']];
        set_transient('dbfb_import_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg('dbfb_import', '1', $back));
        exit;
    }

    /**
     * Esito dell'ultimo import dell'utente corrente (letto una sola volta).
     */
    public static function pop_notice() {
        $key = 'dbfb_import_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if ($notice !== false) delete_transient($key);
        return is_array($notice) ? $notice : null;
    }
}
