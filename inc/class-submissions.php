<?php
if (!defined('ABSPATH')) exit;

class DBFB_Submissions {
    
    public static function render_page($form_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';
        
        $form = get_post($form_id);
        $form_fields = get_post_meta($form_id, '_dbfb_fields', true) ?: [];
        
        $form_fields = array_filter($form_fields, function($f) {
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
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

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
                    if (isset($value['url'])) {
                        $row[] = $value['url'];
                    } elseif (is_array($value)) {
                        $row[] = implode(', ', array_map(function($f) { return is_array($f) ? ($f['url'] ?? '') : $f; }, $value));
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
}
