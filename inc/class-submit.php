<?php
if (!defined('ABSPATH')) exit;

class DBFB_Submit {
    
    public static function ajax_submit_form() {
        check_ajax_referer('dbfb_submit_nonce', 'nonce');
        
        $form_id = intval($_POST['form_id'] ?? 0);
        $form_data = json_decode(stripslashes($_POST['data'] ?? '{}'), true);
        $recaptcha_token = sanitize_text_field($_POST['recaptcha_token'] ?? '');
        
        if (!$form_id || empty($form_data)) {
            wp_send_json_error(['message' => 'Dati non validi']);
        }
        
        $form = get_post($form_id);
        if (!$form) {
            wp_send_json_error(['message' => 'Form non trovato']);
        }
        
        $form_fields = get_post_meta($form_id, '_dbfb_fields', true) ?: [];
        $form_settings = get_post_meta($form_id, '_dbfb_settings', true) ?: [];
        $global_settings = DB_Form_Builder::get_global_settings();
        
        // Honeypot check
        if (!empty($form_settings['enable_honeypot'])) {
            $honeypot_value = sanitize_text_field($_POST['dbfb_website_url'] ?? '');
            if (!empty($honeypot_value)) {
                wp_send_json_success([
                    'message' => $form_settings['success_message'] ?? __('Form inviato con successo!', 'db-form-builder')
                ]);
                return;
            }
            $timestamp = intval($_POST['dbfb_timestamp'] ?? 0);
            if ($timestamp > 0 && (time() - $timestamp) < 3) {
                wp_send_json_success([
                    'message' => $form_settings['success_message'] ?? __('Form inviato con successo!', 'db-form-builder')
                ]);
                return;
            }
        }
        
        // Rate limiting
        if (!empty($form_settings['rate_limit_enabled'])) {
            $ip = DB_Form_Builder::get_client_ip();
            $max_submissions = intval($form_settings['rate_limit_max'] ?? 5);
            $window_minutes = intval($form_settings['rate_limit_window'] ?? 60);
            
            $transient_key = 'dbfb_rate_' . md5($ip . '_' . $form_id);
            $submissions_count = get_transient($transient_key);
            
            if ($submissions_count !== false && $submissions_count >= $max_submissions) {
                wp_send_json_error([
                    'message' => sprintf(
                        __('Hai raggiunto il limite di %d invii. Riprova tra %d minuti.', 'db-form-builder'),
                        $max_submissions, $window_minutes
                    )
                ]);
            }
            set_transient($transient_key, ($submissions_count ?: 0) + 1, $window_minutes * 60);
        }
        
        // GDPR check
        if (!empty($form_settings['enable_gdpr'])) {
            if (empty($form_data['dbfb_gdpr_consent'])) {
                wp_send_json_error([
                    'message' => __('Devi acconsentire al trattamento dei dati personali per procedere.', 'db-form-builder')
                ]);
            }
            unset($form_data['dbfb_gdpr_consent']);
        }
        
        // reCAPTCHA
        if (!empty($form_settings['enable_captcha']) && !empty($global_settings['recaptcha_secret_key'])) {
            if (!self::verify_recaptcha($recaptcha_token, $global_settings['recaptcha_secret_key'])) {
                wp_send_json_error(['message' => __('Verifica anti-spam fallita. Riprova.', 'db-form-builder')]);
            }
        }
        
        // Hidden fields (conditional logic)
        $hidden_fields = [];
        if (!empty($_POST['hidden_fields'])) {
            $hidden_fields = json_decode(stripslashes($_POST['hidden_fields']), true);
            if (!is_array($hidden_fields)) $hidden_fields = [];
            $hidden_fields = array_map('sanitize_key', $hidden_fields);
        }
        
        // Validate required (skip hidden)
        foreach ($form_fields as $field) {
            if (in_array($field['id'], $hidden_fields)) continue;
            if ($field['type'] === 'file') {
                // File required check
                if (!empty($field['required'])) {
                    $file_key = 'dbfb_file_' . $field['id'];
                    if (empty($_FILES[$file_key]['name']) || (is_array($_FILES[$file_key]['name']) && empty($_FILES[$file_key]['name'][0]))) {
                        wp_send_json_error([
                            'message' => sprintf(__('Il campo "%s" è obbligatorio', 'db-form-builder'), $field['label'])
                        ]);
                    }
                }
                continue;
            }
            if (!empty($field['required']) && empty($form_data[$field['id']])) {
                wp_send_json_error([
                    'message' => sprintf(__('Il campo "%s" è obbligatorio', 'db-form-builder'), $field['label'])
                ]);
            }
        }
        
        // Remove hidden field data
        foreach ($hidden_fields as $hf) {
            unset($form_data[$hf]);
        }
        
        // Process file uploads
        $uploaded_files = self::process_file_uploads($form_id, $form_fields, $hidden_fields);
        if (is_wp_error($uploaded_files)) {
            wp_send_json_error(['message' => $uploaded_files->get_error_message()]);
        }
        
        // Merge file URLs into form data
        foreach ($uploaded_files as $field_id => $file_urls) {
            $form_data[$field_id] = $file_urls;
        }
        
        // Save submission
        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';
        DB_Form_Builder::maybe_create_table();
        
        $result = $wpdb->insert($table, [
            'form_id' => $form_id,
            'data' => json_encode($form_data),
            'ip_address' => DB_Form_Builder::get_client_ip(),
        ]);
        
        if ($result === false) {
            error_log('DB Form Builder: Errore inserimento DB - ' . $wpdb->last_error);
        }
        
        // Email
        $placeholders = DBFB_Email::prepare_placeholders($form, $form_fields, $form_data, $form_settings);
        
        $user_email = '';
        foreach ($form_fields as $field) {
            if ($field['type'] === 'email' && !empty($form_data[$field['id']])) {
                $user_email = sanitize_email($form_data[$field['id']]);
                break;
            }
        }
        
        if (!empty($form_settings['send_confirmation']) && $user_email) {
            DBFB_Email::send_confirmation($user_email, $form_settings, $placeholders);
        }
        
        if (!empty($form_settings['send_admin_notification']) && !empty($form_settings['admin_email'])) {
            DBFB_Email::send_admin($form_settings, $placeholders);
        }
        
        // Webhook
        if (!empty($form_settings['enable_webhook']) && !empty($form_settings['webhook_url'])) {
            self::fire_webhook($form_settings['webhook_url'], $form, $form_fields, $form_data);
        }
        
        wp_send_json_success([
            'message' => $form_settings['success_message'] ?? __('Form inviato con successo!', 'db-form-builder')
        ]);
    }
    
    private static function fire_webhook($url, $form, $form_fields, $form_data) {
        // Build structured payload
        $fields_data = [];
        foreach ($form_fields as $field) {
            if (in_array($field['type'], ['divider', 'html', 'image', 'pagebreak'])) continue;
            $value = $form_data[$field['id']] ?? '';
            $fields_data[] = [
                'id' => $field['id'],
                'label' => $field['label'],
                'type' => $field['type'],
                'value' => $value,
            ];
        }
        
        $payload = [
            'form_id' => $form->ID,
            'form_title' => $form->post_title,
            'submitted_at' => current_time('c'),
            'ip' => DB_Form_Builder::get_client_ip(),
            'fields' => $fields_data,
            'raw_data' => $form_data,
        ];
        
        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'DB-Form-Builder/' . DBFB_VERSION,
            ],
            'body' => json_encode($payload),
        ]);
        
        if (is_wp_error($response)) {
            error_log('DB Form Builder Webhook error: ' . $response->get_error_message() . ' — URL: ' . $url);
        } else {
            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                error_log('DB Form Builder Webhook HTTP ' . $code . ' — URL: ' . $url);
            }
        }
    }
    
    private static function verify_recaptcha($token, $secret_key) {
        if (empty($token)) {
            error_log('DB Form Builder: reCAPTCHA token vuoto');
            return false;
        }
        
        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'timeout' => 10,
            'body' => [
                'secret' => $secret_key,
                'response' => $token,
                'remoteip' => DB_Form_Builder::get_client_ip(),
            ]
        ]);
        
        if (is_wp_error($response)) {
            error_log('DB Form Builder: Errore reCAPTCHA - ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body)) {
            error_log('DB Form Builder: Risposta reCAPTCHA vuota');
            return false;
        }
        
        if (isset($body['score'])) {
            $valid = !empty($body['success']) && $body['score'] >= 0.5;
            if (!$valid) error_log('DB Form Builder: reCAPTCHA score basso: ' . ($body['score'] ?? 'N/A'));
            return $valid;
        }
        
        return !empty($body['success']);
    }
    
    // =========================================================
    // FILE UPLOAD
    // =========================================================
    
    private static $blocked_extensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'phps',
        'exe', 'js', 'sh', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py',
        'htaccess', 'htpasswd', 'ini', 'phar', 'svg'
    ];
    
    private static function process_file_uploads($form_id, $form_fields, $hidden_fields) {
        $uploaded = [];
        
        foreach ($form_fields as $field) {
            if ($field['type'] !== 'file') continue;
            if (in_array($field['id'], $hidden_fields)) continue;
            
            $file_key = 'dbfb_file_' . $field['id'];
            if (empty($_FILES[$file_key]['name'])) continue;
            
            $is_multiple = !empty($field['file_multiple']);
            $max_size_mb = intval($field['file_max_size'] ?? 5);
            $max_size_bytes = $max_size_mb * 1024 * 1024;
            $allowed_ext_str = $field['file_extensions'] ?? 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,zip';
            $allowed_extensions = array_map('trim', array_map('strtolower', explode(',', $allowed_ext_str)));
            
            // Normalize to arrays for uniform processing
            $names = (array) $_FILES[$file_key]['name'];
            $tmp_names = (array) $_FILES[$file_key]['tmp_name'];
            $sizes = (array) $_FILES[$file_key]['size'];
            $errors = (array) $_FILES[$file_key]['error'];
            
            // Filter out empty entries
            $file_count = count($names);
            $urls = [];
            
            for ($i = 0; $i < $file_count; $i++) {
                if (empty($names[$i]) || $errors[$i] === UPLOAD_ERR_NO_FILE) continue;
                
                // Check upload error
                if ($errors[$i] !== UPLOAD_ERR_OK) {
                    return new WP_Error('upload_error', sprintf(
                        __('Errore durante il caricamento di "%s"', 'db-form-builder'),
                        sanitize_file_name($names[$i])
                    ));
                }
                
                // Check file size
                if ($sizes[$i] > $max_size_bytes) {
                    return new WP_Error('file_too_large', sprintf(
                        __('Il file "%s" supera la dimensione massima di %d MB', 'db-form-builder'),
                        sanitize_file_name($names[$i]), $max_size_mb
                    ));
                }
                
                // Check extension
                $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
                if (in_array($ext, self::$blocked_extensions)) {
                    return new WP_Error('blocked_extension', sprintf(
                        __('Il tipo di file "%s" non è consentito per motivi di sicurezza', 'db-form-builder'),
                        $ext
                    ));
                }
                if (!in_array($ext, $allowed_extensions)) {
                    return new WP_Error('invalid_extension', sprintf(
                        __('Il tipo di file "%s" non è ammesso. Formati consentiti: %s', 'db-form-builder'),
                        $ext, $allowed_ext_str
                    ));
                }
                
                // WordPress filetype check
                $check = wp_check_filetype(sanitize_file_name($names[$i]));
                if (empty($check['type'])) {
                    return new WP_Error('invalid_filetype', sprintf(
                        __('Il file "%s" non è un tipo riconosciuto', 'db-form-builder'),
                        sanitize_file_name($names[$i])
                    ));
                }
                
                // Create upload directory
                $upload_dir = self::get_upload_dir($form_id);
                if (is_wp_error($upload_dir)) return $upload_dir;
                
                // Generate unique filename
                $safe_name = wp_unique_filename($upload_dir['path'], sanitize_file_name($names[$i]));
                $dest_path = $upload_dir['path'] . '/' . $safe_name;
                
                // Move file
                if (!move_uploaded_file($tmp_names[$i], $dest_path)) {
                    return new WP_Error('move_failed', sprintf(
                        __('Impossibile salvare il file "%s"', 'db-form-builder'),
                        sanitize_file_name($names[$i])
                    ));
                }
                
                // Set proper permissions
                @chmod($dest_path, 0644);
                
                $urls[] = [
                    'url' => $upload_dir['url'] . '/' . $safe_name,
                    'name' => sanitize_file_name($names[$i]),
                    'size' => $sizes[$i],
                ];
            }
            
            if (!empty($urls)) {
                $uploaded[$field['id']] = $is_multiple ? $urls : ($urls[0] ?? null);
            }
        }
        
        return $uploaded;
    }
    
    private static function get_upload_dir($form_id) {
        $wp_upload = wp_upload_dir();
        $base_dir = $wp_upload['basedir'] . '/dbfb/' . $form_id;
        $base_url = $wp_upload['baseurl'] . '/dbfb/' . $form_id;
        
        if (!file_exists($base_dir)) {
            if (!wp_mkdir_p($base_dir)) {
                return new WP_Error('mkdir_failed', __('Impossibile creare la cartella di upload', 'db-form-builder'));
            }
            
            // Security: prevent PHP execution in upload dir
            $htaccess = $wp_upload['basedir'] . '/dbfb/.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "# DB Form Builder - Security\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar|cgi|pl|py|sh|bat)$\">\n    Deny from all\n</FilesMatch>\nOptions -ExecCGI\n");
            }
            
            // Empty index.php to prevent directory listing
            $index = $base_dir . '/index.php';
            if (!file_exists($index)) {
                @file_put_contents($index, '<?php // Silence is golden.');
            }
        }
        
        return ['path' => $base_dir, 'url' => $base_url];
    }
}
