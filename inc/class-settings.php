<?php
if (!defined('ABSPATH')) exit;

class DBFB_Settings {
    
    public static function render_page() {
        $global_settings = DB_Form_Builder::get_global_settings();
        include DBFB_PLUGIN_DIR . 'templates/admin/settings.php';
    }
    
    public static function ajax_save_global_settings() {
        check_ajax_referer('dbfb_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permessi insufficienti']);
        }
        
        $settings = [
            'recaptcha_version' => sanitize_text_field($_POST['recaptcha_version'] ?? 'v2'),
            'recaptcha_site_key' => sanitize_text_field($_POST['recaptcha_site_key'] ?? ''),
            'recaptcha_secret_key' => sanitize_text_field($_POST['recaptcha_secret_key'] ?? ''),
            'from_email' => sanitize_email($_POST['from_email'] ?? ''),
            'from_name' => sanitize_text_field($_POST['from_name'] ?? ''),
        ];
        
        update_option('dbfb_global_settings', $settings);
        wp_send_json_success(['message' => 'Impostazioni salvate con successo!']);
    }
    
    public static function ajax_test_email() {
        check_ajax_referer('dbfb_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permessi insufficienti']);
        }
        
        $to_email = sanitize_email($_POST['to_email'] ?? '');
        $from_name = sanitize_text_field($_POST['from_name'] ?? get_bloginfo('name'));
        $from_email = sanitize_email($_POST['from_email'] ?? get_option('admin_email'));
        
        if (empty($to_email) || !is_email($to_email)) {
            wp_send_json_error(['message' => 'Inserisci un indirizzo email valido']);
        }
        
        $subject = sprintf(__('[Test] DB Form Builder - %s', 'db-form-builder'), get_bloginfo('name'));
        $message = sprintf(
            __("Questa è un'email di test inviata da DB Form Builder.\n\n" .
               "Se stai leggendo questo messaggio, la configurazione email funziona correttamente!\n\n" .
               "Dettagli:\n- Sito: %s\n- Mittente: %s <%s>\n- Data: %s\n\n---\nDB Form Builder", 'db-form-builder'),
            home_url(), $from_name, $from_email, current_time('d/m/Y H:i:s')
        );
        
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('From: %s <%s>', $from_name, $from_email),
        ];
        
        $sent = wp_mail($to_email, $subject, $message, $headers);
        
        if ($sent) {
            wp_send_json_success([
                'message' => sprintf(__('✓ Email inviata con successo a %s! Controlla la casella di posta (anche lo spam).', 'db-form-builder'), $to_email)
            ]);
        } else {
            global $phpmailer;
            $error_msg = '';
            if (isset($phpmailer) && is_object($phpmailer) && !empty($phpmailer->ErrorInfo)) {
                $error_msg = $phpmailer->ErrorInfo;
            }
            wp_send_json_error([
                'message' => __('✗ Invio email fallito. ', 'db-form-builder') . 
                    ($error_msg ? $error_msg : __('Il server potrebbe non essere configurato per inviare email. Considera un plugin SMTP.', 'db-form-builder'))
            ]);
        }
    }
    
    public static function ajax_test_recaptcha() {
        check_ajax_referer('dbfb_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permessi insufficienti']);
        }
        
        $site_key = sanitize_text_field($_POST['site_key'] ?? '');
        $secret_key = sanitize_text_field($_POST['secret_key'] ?? '');
        $version = sanitize_text_field($_POST['version'] ?? 'v2');
        $token = sanitize_text_field($_POST['token'] ?? '');
        
        if (empty($site_key) || empty($secret_key)) {
            wp_send_json_error(['message' => 'Inserisci entrambe le chiavi']);
        }
        if (empty($token)) {
            wp_send_json_error(['message' => 'Token non ricevuto. Completa la verifica reCAPTCHA.']);
        }
        
        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'timeout' => 10,
            'body' => ['secret' => $secret_key, 'response' => $token]
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Errore di connessione: ' . $response->get_error_message()]);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body)) {
            wp_send_json_error(['message' => 'Risposta non valida da Google']);
        }
        
        if (!empty($body['success'])) {
            $msg = '✓ Chiavi valide! ';
            $msg .= isset($body['score']) ? 'Score: ' . $body['score'] . ' (v3)' : '(v2)';
            wp_send_json_success(['message' => $msg, 'details' => $body]);
        } else {
            $errors = $body['error-codes'] ?? ['unknown-error'];
            $error_messages = [
                'missing-input-secret' => 'Secret Key mancante',
                'invalid-input-secret' => 'Secret Key non valida',
                'missing-input-response' => 'Token mancante',
                'invalid-input-response' => 'Token non valido o scaduto',
                'bad-request' => 'Richiesta non valida',
                'timeout-or-duplicate' => 'Token scaduto o già usato',
            ];
            $msgs = array_map(function($e) use ($error_messages) {
                return $error_messages[$e] ?? $e;
            }, $errors);
            wp_send_json_error([
                'message' => '✗ Verifica fallita: ' . implode(', ', $msgs),
                'details' => $body
            ]);
        }
    }
}
