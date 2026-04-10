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
        $form_fields = get_post_meta($form_id, '_dbfb_fields', true) ?: [];
        
        $form_fields = array_filter($form_fields, function($f) {
            return !in_array($f['type'], ['divider', 'html', 'image', 'pagebreak']);
        });
        
        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE form_id = %d ORDER BY submitted_at DESC",
            $form_id
        ));
        
        $filename = sanitize_file_name($form->post_title) . '_' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        $headers = ['ID', 'Data'];
        foreach ($form_fields as $field) {
            $headers[] = $field['label'];
        }
        $headers[] = 'IP';
        fputcsv($output, $headers, ';');
        
        foreach ($submissions as $submission) {
            $data = json_decode($submission->data, true);
            $row = [$submission->id, date('d/m/Y H:i', strtotime($submission->submitted_at))];
            
            foreach ($form_fields as $field) {
                $value = $data[$field['id']] ?? '';
                if ($field['type'] === 'file' && !empty($value)) {
                    if (isset($value['url'])) {
                        $row[] = $value['url'];
                    } elseif (is_array($value)) {
                        $row[] = implode(', ', array_map(function($f) { return is_array($f) ? $f['url'] : $f; }, $value));
                    } else {
                        $row[] = $value;
                    }
                } else {
                    $row[] = is_array($value) ? implode(', ', $value) : $value;
                }
            }
            
            $row[] = $submission->ip_address;
            fputcsv($output, $row, ';');
        }
        
        fclose($output);
        exit;
    }
}
