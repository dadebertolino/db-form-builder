<?php
if (!defined('ABSPATH')) exit;

class DBFB_Submissions {

    public static function render_page($form_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';

        $form = get_post($form_id);
        $form_fields = get_post_meta($form_id, '_dbfb_fields', true) ?: [];

        $form_fields = array_filter($form_fields, function ($f) {
            return !in_array($f['type'], ['divider', 'html', 'image', 'pagebreak']);
        });

        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE form_id = %d ORDER BY submitted_at DESC",
            $form_id
        ));

        include DBFB_PLUGIN_DIR . 'templates/admin/submissions.php';
    }

    public static function render_all_submissions_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';

        if (isset($_GET['form_id']) && intval($_GET['form_id']) > 0) {
            self::render_page(intval($_GET['form_id']));
            return;
        }

        $forms = get_posts([
            'post_type' => 'dbfb_form',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);

        $counts = [];
        foreach ($forms as $form) {
            $counts[$form->ID] = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE form_id = %d",
                $form->ID
            ));
        }

        include DBFB_PLUGIN_DIR . 'templates/admin/submissions-list.php';
    }

    public static function ajax_export_csv() {
        check_ajax_referer('dbfb_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Permessi insufficienti');

        $form_id = intval($_GET['form_id'] ?? 0);
        if (!$form_id) wp_die('Form non valido');

        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';

        $form = get_post($form_id);
        $form_fields_raw = get_post_meta($form_id, '_dbfb_fields', true) ?: [];

        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE form_id = %d ORDER BY submitted_at DESC",
            $form_id
        ));

        // Snapshot-aware columns (2.6.0): unione di tutti i field apparsi
        // in qualsiasi submission (via snapshot) + i field correnti del form.
        $columns = DB_Form_Builder::build_submission_columns($submissions, $form_fields_raw);

        // Mappa di tipo dai field correnti per fallback nel rendering file.
        $current_types_by_id = array();
        foreach ((array) $form_fields_raw as $f) {
            if (!empty($f['id'])) $current_types_by_id[$f['id']] = $f['type'] ?? 'text';
        }

        $filename = sanitize_file_name($form->post_title) . '_' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // 2.8.0: header IP esplicativo. La modalità IP è una scelta globale
        // (Form Builder → Impostazioni → Privacy). Se 'none', niente colonna
        // IP nel CSV (non c'è nulla da esportare). Se 'hashed', l'header
        // chiarisce che il valore è un hash SHA-256 (altrimenti Excel mostra
        // 64 char hex senza spiegazione). Se 'full', header standard.
        $ip_mode = DB_Form_Builder::get_ip_storage_mode();
        $include_ip_col = ($ip_mode !== 'none');
        if ($ip_mode === 'hashed') {
            $ip_header = __('IP (hash SHA-256)', 'db-form-builder');
        } else {
            $ip_header = __('IP', 'db-form-builder');
        }

        $headers = ['ID', 'Data'];
        foreach ($columns as $col) {
            $headers[] = $col['label'];
        }
        if ($include_ip_col) {
            $headers[] = $ip_header;
        }
        fputcsv($output, array_map(array(__CLASS__, 'csv_escape'), $headers), ';');

        foreach ($submissions as $submission) {
            $info        = DB_Form_Builder::get_submission_fields($submission, $form_fields_raw);
            $data        = $info['data'];
            $row_types   = array();
            foreach ($info['fields'] as $rf) {
                $row_types[$rf['id']] = $rf['type'];
            }

            $row = [$submission->id, date('d/m/Y H:i', strtotime($submission->submitted_at))];

            foreach ($columns as $col) {
                $field_id = $col['id'];
                $value    = $data[$field_id] ?? '';
                $type     = $row_types[$field_id] ?? ($current_types_by_id[$field_id] ?? 'text');

                if ($type === 'file' && !empty($value)) {
                    // 2.13.0: link al download admin (login + capability),
                    // non più l'URL pubblico della cartella uploads/dbfb/.
                    if (isset($value['url'])) {
                        $row[] = self::attachment_download_url($value);
                    } elseif (is_array($value)) {
                        $row[] = implode(', ', array_map(function ($f) {
 return is_array($f) ? self::attachment_download_url($f) : $f;
}, $value));
                    } else {
                        $row[] = $value;
                    }
                } else {
                    $row[] = is_array($value) ? implode(', ', $value) : $value;
                }
            }

            if ($include_ip_col) {
                $ip_info = DB_Form_Builder::format_submission_ip($submission, 'full');
                $row[] = $ip_info['raw'];
            }
            fputcsv($output, array_map(array(__CLASS__, 'csv_escape'), $row), ';');
        }

        fclose($output);
        exit;
    }

    /**
     * Neutralizza la CSV injection (formula injection) su una cella.
     *
     * Excel/LibreOffice/Sheets interpretano come formula qualsiasi cella che
     * inizia con = + - @ (o TAB / CR, usati per bypassare i filtri). Un campo
     * di form pubblico tipo "=HYPERLINK(...)" o "=cmd|..." verrebbe eseguito
     * all'apertura del CSV esportato. Prefissiamo un apice: la cella resta
     * leggibile come testo e non viene valutata.
     *
     * Riferimento: OWASP "CSV Injection".
     *
     * @param mixed $cell Valore della cella.
     * @return string Valore neutralizzato.
     */
    public static function csv_escape($cell) {
        $cell = (string) $cell;
        if ($cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false) {
            return "'" . $cell;
        }
        return $cell;
    }

    // =========================================================
    // ALLEGATI: DOWNLOAD PROTETTO (2.13.0)
    // =========================================================

    /**
     * Path relativo a uploads/ di un allegato, validato su uploads/dbfb/.
     *
     * Accetta la entry salvata nella submission: usa 'path' (2.4.0+) o, per
     * le submission legacy 2.3.x, lo deriva da 'url'. Restituisce '' se il
     * path non è dentro dbfb/ o contiene segmenti sospetti.
     *
     * @param array $entry Entry di un campo file ({url, name, size, path}).
     * @return string Es. "dbfb/12/AbC...-preventivo.pdf" oppure ''.
     */
    public static function attachment_relative_path($entry) {
        if (!is_array($entry)) return '';
        $relative = '';
        if (!empty($entry['path']) && is_string($entry['path'])) {
            $relative = $entry['path'];
        } elseif (!empty($entry['url']) && is_string($entry['url'])) {
            $upload  = wp_upload_dir();
            $baseurl = trailingslashit(set_url_scheme($upload['baseurl']));
            $url     = set_url_scheme($entry['url']);
            if (strpos($url, $baseurl) === 0) {
                $relative = rawurldecode(substr($url, strlen($baseurl)));
            }
        }
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || strpos($relative, 'dbfb/') !== 0 || strpos($relative, '..') !== false) {
            return '';
        }
        return $relative;
    }

    /**
     * URL di download admin di un allegato (2.13.0).
     *
     * Punta a admin-post.php: richiede login e capability. Il nonce è legato
     * al file; se scade (es. link in un'email vecchia) l'handler propone un
     * link nuovo invece di fallire.
     *
     * @param array $entry Entry di un campo file.
     * @return string URL o '' se l'allegato non è risolvibile.
     */
    public static function attachment_download_url($entry, $with_nonce = true) {
        $relative = self::attachment_relative_path($entry);
        if ($relative === '') return '';
        return self::build_download_url($relative, $with_nonce);
    }

    /**
     * @param string $relative   Path relativo già validato.
     * @param bool   $with_nonce False per i link nelle email: generate durante
     *                           l'invio di un visitatore anonimo, un nonce non
     *                           sarebbe valido per l'admin; l'handler chiede
     *                           allora una conferma con un link fresco.
     * @return string
     */
    private static function build_download_url($relative, $with_nonce = true) {
        $args = array(
            'action' => 'dbfb_download',
            'file'   => rawurlencode($relative),
        );
        if ($with_nonce) {
            $args['_wpnonce'] = wp_create_nonce('dbfb_download|' . $relative);
        }
        return add_query_arg($args, admin_url('admin-post.php'));
    }

    /**
     * Sostituisce 'url' nei campi file con il link di download admin (2.13.0).
     *
     * Usato per il JSON del dettaglio submission in admin, così la UI non
     * espone mai l'URL diretto della cartella.
     *
     * @param array $data       Dati della submission.
     * @param array $types_by_id Mappa field_id => type.
     * @return array
     */
    public static function with_admin_attachment_urls($data, $types_by_id) {
        if (!is_array($data)) return array();
        foreach ($data as $key => $value) {
            if (($types_by_id[$key] ?? '') !== 'file' || !is_array($value)) continue;
            if (isset($value['url'])) {
                $data[$key]['url'] = self::attachment_download_url($value);
                unset($data[$key]['path']);
                continue;
            }
            foreach ($value as $i => $entry) {
                if (is_array($entry) && isset($entry['url'])) {
                    $data[$key][$i]['url'] = self::attachment_download_url($entry);
                    unset($data[$key][$i]['path']);
                }
            }
        }
        return $data;
    }

    /**
     * Handler admin-post.php?action=dbfb_download (2.13.0).
     *
     * Controlli:
     *  - login (i non loggati vengono mandati al login e poi riportati qui)
     *  - capability manage_options (filtrabile via dbfb_download_capability)
     *  - nonce legato al file; se non valido (link scaduto) mostra un link
     *    rigenerato invece del download
     *  - path confinato in uploads/dbfb/ con verifica realpath (anti
     *    path-traversal e anti-symlink)
     *
     * Il file viene sempre servito come allegato (Content-Disposition:
     * attachment + nosniff), mai renderizzato inline nel dominio admin.
     *
     * @return void
     */
    public static function handle_attachment_download() {
        if (!is_user_logged_in()) {
            auth_redirect();
            exit;
        }

        $capability = (string) apply_filters('dbfb_download_capability', 'manage_options');
        if (!current_user_can($capability)) {
            wp_die(esc_html__('Permessi insufficienti', 'db-form-builder'), '', array('response' => 403));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verificato subito sotto, legato al path.
        $relative = isset($_GET['file']) ? sanitize_text_field(wp_unslash($_GET['file'])) : '';
        $relative = self::attachment_relative_path(array('path' => $relative));
        if ($relative === '') {
            wp_die(esc_html__('Allegato non valido.', 'db-form-builder'), '', array('response' => 400));
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'dbfb_download|' . $relative)) {
            // Link scaduto (tipico dei link nelle email di notifica): l'utente
            // ha già la capability, gli proponiamo un link fresco.
            $notice = ($nonce === '')
                ? __('Conferma il download dell\'allegato.', 'db-form-builder')
                : __('Il link di download è scaduto o non è valido.', 'db-form-builder');
            wp_die(
                '<p>' . esc_html($notice) . '</p>'
                . '<p><a class="button button-primary" href="' . esc_url(self::build_download_url($relative)) . '">'
                . esc_html__('Scarica l\'allegato', 'db-form-builder') . '</a></p>',
                esc_html__('Download allegato', 'db-form-builder'),
                array('response' => 403)
            );
        }

        $real = self::resolve_attachment_file($relative);
        if ($real === '') {
            wp_die(esc_html__('Allegato non trovato.', 'db-form-builder'), '', array('response' => 404));
        }
        self::stream_attachment($real);
    }

    /**
     * Path assoluto di un allegato, confinato in uploads/dbfb/ (2.13.0).
     *
     * Condiviso dal download admin e dal link firmato dei webhook: verifica
     * realpath (anti path-traversal e anti-symlink) ed esclude i file di
     * protezione della cartella.
     *
     * @param string $relative Path relativo già passato da attachment_relative_path().
     * @return string Path assoluto o '' se il file non è servibile.
     */
    public static function resolve_attachment_file($relative) {
        $relative = (string) $relative;
        if ($relative === '') return '';
        $upload = wp_upload_dir();
        $root   = realpath(trailingslashit($upload['basedir']) . 'dbfb');
        $real   = realpath(trailingslashit($upload['basedir']) . $relative);
        if ($root === false || $real === false || !is_file($real)
            || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }
        $basename = basename($real);
        if ($basename === 'index.php' || $basename === '.htaccess') {
            return '';
        }
        return $real;
    }

    /**
     * Invia un allegato già validato come download e termina (2.13.0).
     *
     * Sempre Content-Disposition: attachment + nosniff, mai inline; header
     * anti-cache perché i link (admin o firmati) non devono finire in cache
     * condivise.
     *
     * @param string $real Path assoluto restituito da resolve_attachment_file().
     * @return void
     */
    private static function stream_attachment($real) {
        $basename = basename($real);
        // Nome proposto al download: senza il prefisso casuale (2.13.0+).
        $download_name = preg_replace('/^[A-Za-z0-9]{24}-/', '', $basename);
        $download_name = sanitize_file_name($download_name);
        $filetype = wp_check_filetype($basename);
        $mime     = !empty($filetype['type']) ? $filetype['type'] : 'application/octet-stream';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $download_name) . '"');
        header('Content-Length: ' . (string) filesize($real));
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming di un file locale già validato.
        readfile($real);
        exit;
    }

    // =========================================================
    // LINK FIRMATI PER I WEBHOOK (2.13.0)
    // =========================================================

    /**
     * Secret dedicato per firmare i link degli allegati nei webhook.
     *
     * Generato una sola volta e salvato in un'option non autoload. Separato
     * dai salt di WordPress: ruotarlo (cancellando l'option) invalida solo
     * questi link, non le sessioni degli utenti.
     *
     * @return string
     */
    private static function file_link_secret() {
        $secret = get_option('dbfb_file_link_secret', '');
        if (!is_string($secret) || strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            update_option('dbfb_file_link_secret', $secret, false);
        }
        return $secret;
    }

    /**
     * Firma HMAC di un link allegato: sha256 su "path|scadenza".
     *
     * @param string $relative Path relativo (es. "dbfb/12/AbC...-file.pdf").
     * @param int    $expires  Timestamp unix di scadenza.
     * @return string
     */
    private static function file_link_signature($relative, $expires) {
        return hash_hmac('sha256', $relative . '|' . (int) $expires, self::file_link_secret());
    }

    /**
     * URL pubblico firmato e a scadenza di un allegato (solo per i webhook).
     *
     * Non richiede login: chi ha il link può scaricare il file fino alla
     * scadenza (default 7 giorni, filter dbfb_webhook_file_link_ttl). Va
     * usato SOLO nel payload dei webhook, mai in pagine o email.
     *
     * @param array $entry Entry di un campo file ({url, name, size, path}).
     * @return string URL o '' se l'allegato non è risolvibile.
     */
    public static function signed_attachment_url($entry) {
        $relative = self::attachment_relative_path($entry);
        if ($relative === '') return '';
        $ttl = (int) apply_filters('dbfb_webhook_file_link_ttl', 7 * DAY_IN_SECONDS, $relative);
        if ($ttl <= 0) {
            $ttl = 7 * DAY_IN_SECONDS;
        }
        $expires = time() + $ttl;
        return add_query_arg(
            array(
                'action' => 'dbfb_file',
                'f'      => rawurlencode($relative),
                'exp'    => $expires,
                'sig'    => self::file_link_signature($relative, $expires),
            ),
            admin_url('admin-post.php')
        );
    }

    /**
     * Sostituisce 'url' con il link firmato in un valore di campo file.
     *
     * Accetta sia la entry singola ({url, name, ...}) sia la lista di entry.
     * Le chiavi restano invariate (compatibilità del payload); le entry non
     * risolvibili in uploads/dbfb/ vengono lasciate come sono.
     *
     * @param mixed $value Valore del campo.
     * @return mixed
     */
    public static function with_signed_attachment_urls($value) {
        if (!is_array($value)) return $value;
        if (isset($value['url']) && isset($value['name'])) {
            $signed = self::signed_attachment_url($value);
            if ($signed !== '') {
                $value['url'] = $signed;
            }
            return $value;
        }
        foreach ($value as $i => $entry) {
            if (is_array($entry) && isset($entry['url']) && isset($entry['name'])) {
                $signed = self::signed_attachment_url($entry);
                if ($signed !== '') {
                    $value[$i]['url'] = $signed;
                }
            }
        }
        return $value;
    }

    /**
     * Handler admin-post.php?action=dbfb_file (nopriv + priv, 2.13.0).
     *
     * Download pubblico tramite link firmato dei webhook. Controlli:
     *  - parametri f (path relativo), exp (scadenza), sig (HMAC)
     *  - firma confrontata con hash_equals, scadenza non superata
     *  - stesso confinamento realpath del download admin
     *
     * Risposte: 403 firma non valida, 410 link scaduto, 404 file assente
     * (la verifica del file avviene solo dopo una firma valida).
     *
     * @return void
     */
    public static function handle_signed_file_download() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- autenticato da firma HMAC, non da nonce.
        $relative = isset($_GET['f']) ? sanitize_text_field(wp_unslash($_GET['f'])) : '';
        $expires  = isset($_GET['exp']) ? absint(wp_unslash($_GET['exp'])) : 0;
        $sig      = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $relative = self::attachment_relative_path(array('path' => $relative));
        $valid = $relative !== ''
            && $expires > 0
            && $sig !== ''
            && hash_equals(self::file_link_signature($relative, $expires), $sig);
        if (!$valid) {
            wp_die(esc_html__('Link non valido.', 'db-form-builder'), '', array('response' => 403));
        }
        if ($expires < time()) {
            wp_die(esc_html__('Il link di download è scaduto.', 'db-form-builder'), '', array('response' => 410));
        }

        $real = self::resolve_attachment_file($relative);
        if ($real === '') {
            wp_die(esc_html__('Allegato non trovato.', 'db-form-builder'), '', array('response' => 404));
        }
        self::stream_attachment($real);
    }
}
