<?php
if (!defined('ABSPATH')) exit;

class DBFB_Email {
    
    public static function prepare_placeholders($form, $fields, $data, $settings) {
        $placeholders = [
            '{form_titolo}' => $form->post_title,
            '{ip}' => DB_Form_Builder::get_client_ip(),
            '{data}' => current_time('d/m/Y H:i:s'),
            '{sito}' => get_bloginfo('name'),
        ];
        
        $riepilogo = '';
        foreach ($fields as $field) {
            if (in_array($field['type'], ['divider', 'html', 'image', 'pagebreak'])) continue;
            
            $value = isset($data[$field['id']]) ? $data[$field['id']] : '';
            
            // File field: extract display value
            if ($field['type'] === 'file' && !empty($value)) {
                if (is_array($value)) {
                    // Multiple files or single file object
                    if (isset($value['name'])) {
                        // Single file
                        $value = $value['name'] . ' (' . $value['url'] . ')';
                    } else {
                        // Multiple files
                        $file_names = array_map(function($f) {
                            return is_array($f) ? $f['name'] . ' (' . $f['url'] . ')' : $f;
                        }, $value);
                        $value = implode(', ', $file_names);
                    }
                }
            } elseif (is_array($value)) {
                $value = implode(', ', $value);
            }
            
            $field_key = sanitize_title($field['label']);
            $placeholders['{' . $field_key . '}'] = $value;
            $riepilogo .= $field['label'] . ': ' . $value . "\n";
        }
        
        $placeholders['{riepilogo_dati}'] = trim($riepilogo);
        return $placeholders;
    }
    
    public static function replace_placeholders($text, $placeholders) {
        return str_replace(array_keys($placeholders), array_values($placeholders), $text);
    }
    
    private static function get_headers() {
        $global_settings = DB_Form_Builder::get_global_settings();
        return [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $global_settings['from_name'] . ' <' . $global_settings['from_email'] . '>',
        ];
    }
    
    public static function send_confirmation($to, $settings, $placeholders) {
        $subject = self::replace_placeholders($settings['confirmation_subject'] ?? '', $placeholders);
        $message = self::replace_placeholders($settings['confirmation_message'] ?? '', $placeholders);
        return wp_mail($to, $subject, $message, self::get_headers());
    }
    
    public static function send_admin($settings, $placeholders) {
        $to = $settings['admin_email'];
        $recipients = array_map('trim', explode(',', $to));
        $recipients = array_filter($recipients, 'is_email');
        
        if (empty($recipients)) return false;
        
        $subject = self::replace_placeholders($settings['admin_subject'] ?? '', $placeholders);
        $message = self::replace_placeholders($settings['admin_message'] ?? '', $placeholders);
        $headers = self::get_headers();
        
        $success = true;
        foreach ($recipients as $recipient) {
            if (!wp_mail($recipient, $subject, $message, $headers)) $success = false;
        }
        return $success;
    }
    
    // =========================================================
    // TEST EMAIL (per form)
    // =========================================================
    
    public static function ajax_send_test_email() {
        check_ajax_referer('dbfb_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permessi insufficienti']);
        }
        
        $form_id = intval($_POST['form_id'] ?? 0);
        $email_type = sanitize_text_field($_POST['email_type'] ?? '');
        $test_email = sanitize_email($_POST['test_email'] ?? '');
        
        if (!$form_id || !$email_type || !$test_email) {
            wp_send_json_error(['message' => 'Parametri mancanti']);
        }
        
        $form = get_post($form_id);
        $form_fields = get_post_meta($form_id, '_dbfb_fields', true) ?: [];
        $form_settings = get_post_meta($form_id, '_dbfb_settings', true) ?: [];
        
        $sample_data = [];
        foreach ($form_fields as $field) {
            if (in_array($field['type'], ['divider', 'html', 'image', 'pagebreak'])) continue;
            switch ($field['type']) {
                case 'email': $sample_data[$field['id']] = 'esempio@email.com'; break;
                case 'tel': $sample_data[$field['id']] = '+39 123 456 7890'; break;
                case 'number': $sample_data[$field['id']] = '42'; break;
                case 'date': $sample_data[$field['id']] = date('Y-m-d'); break;
                case 'checkbox': case 'radio': case 'select':
                    $sample_data[$field['id']] = $field['options'][0] ?? 'Opzione esempio'; break;
                default:
                    $sample_data[$field['id']] = 'Valore di esempio per ' . $field['label'];
            }
        }
        
        $placeholders = self::prepare_placeholders($form, $form_fields, $sample_data, $form_settings);
        $headers = self::get_headers();
        
        if ($email_type === 'confirmation') {
            $subject = '[TEST] ' . self::replace_placeholders($form_settings['confirmation_subject'] ?? '', $placeholders);
            $message = self::replace_placeholders($form_settings['confirmation_message'] ?? '', $placeholders);
        } else {
            $subject = '[TEST] ' . self::replace_placeholders($form_settings['admin_subject'] ?? '', $placeholders);
            $message = self::replace_placeholders($form_settings['admin_message'] ?? '', $placeholders);
        }
        
        $sent = wp_mail($test_email, $subject, $message, $headers);
        
        if ($sent) {
            wp_send_json_success(['message' => 'Email di test inviata a ' . $test_email]);
        } else {
            wp_send_json_error(['message' => 'Errore nell\'invio dell\'email. Verifica le impostazioni SMTP.']);
        }
    }
}
