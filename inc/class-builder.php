<?php
if (!defined('ABSPATH')) exit;

class DBFB_Builder {
    
    public static function render_new_form() {
        self::render_form_builder(0);
    }
    
    public static function render_form_builder($form_id = 0) {
        $form = null;
        $form_fields = [];
        $form_settings = [];
        
        if ($form_id > 0) {
            $form = get_post($form_id);
            $form_fields = get_post_meta($form_id, '_dbfb_fields', true) ?: [];
            $form_settings = get_post_meta($form_id, '_dbfb_settings', true) ?: [];
        }
        
        $default_settings = [
            'submit_text' => __('Invia', 'db-form-builder'),
            'success_message' => __('Grazie! Il modulo è stato inviato con successo.', 'db-form-builder'),
            'enable_captcha' => false,
            'enable_honeypot' => true,
            'enable_gdpr' => false,
            'gdpr_text' => __('Acconsento al trattamento dei dati personali secondo la Privacy Policy', 'db-form-builder'),
            'gdpr_link' => '',
            'rate_limit_enabled' => false,
            'rate_limit_max' => 5,
            'rate_limit_window' => 60,
            'send_confirmation' => true,
            'confirmation_subject' => __('Conferma iscrizione', 'db-form-builder'),
            'confirmation_message' => __("Gentile {nome},\n\nGrazie per averci contattato. Abbiamo ricevuto la tua richiesta.\n\nRiepilogo dati inviati:\n{riepilogo_dati}\n\nCordiali saluti", 'db-form-builder'),
            'send_admin_notification' => true,
            'admin_email' => get_option('admin_email'),
            'admin_subject' => __('Nuova richiesta dal form: {form_titolo}', 'db-form-builder'),
            'admin_message' => __("È stata ricevuta una nuova richiesta dal form {form_titolo}.\n\nDati inviati:\n{riepilogo_dati}\n\nIP: {ip}\nData: {data}", 'db-form-builder'),
            'enable_webhook' => false,
            'webhook_url' => '',
        ];
        
        $form_settings = wp_parse_args($form_settings, $default_settings);
        $templates = self::get_templates();
        $show_templates = (empty($form_id) || $form_id == 0);
        
        include DBFB_PLUGIN_DIR . 'templates/admin/form-builder.php';
    }
    
    // =========================================================
    // SAVE FORM
    // =========================================================
    
    public static function ajax_save_form() {
        check_ajax_referer('dbfb_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permessi insufficienti']);
        }
        
        $form_id = intval($_POST['form_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        $fields = json_decode(stripslashes($_POST['fields'] ?? '[]'), true);
        $settings = json_decode(stripslashes($_POST['settings'] ?? '{}'), true);
        
        $sanitized_fields = self::sanitize_fields($fields);
        $sanitized_settings = self::sanitize_settings($settings);
        
        if ($form_id > 0) {
            wp_update_post(['ID' => $form_id, 'post_title' => $title]);
        } else {
            $form_id = wp_insert_post([
                'post_type' => 'dbfb_form',
                'post_title' => $title,
                'post_status' => 'publish',
            ]);
        }
        
        update_post_meta($form_id, '_dbfb_fields', $sanitized_fields);
        update_post_meta($form_id, '_dbfb_settings', $sanitized_settings);
        
        wp_send_json_success(['form_id' => $form_id, 'message' => 'Form salvato con successo!']);
    }
    
    private static function sanitize_fields($fields) {
        $sanitized = [];
        foreach ($fields as $field) {
            $sf = [
                'id' => sanitize_key($field['id'] ?? uniqid('field_')),
                'type' => sanitize_key($field['type'] ?? 'text'),
                'label' => sanitize_text_field($field['label'] ?? ''),
                'placeholder' => sanitize_text_field($field['placeholder'] ?? ''),
                'required' => !empty($field['required']),
                'options' => isset($field['options']) ? array_map('sanitize_text_field', $field['options']) : [],
            ];
            
            if ($field['type'] === 'html') {
                $sf['content'] = wp_kses_post($field['content'] ?? '');
            }
            if ($field['type'] === 'image') {
                $sf['image_url'] = esc_url_raw($field['image_url'] ?? '');
                $sf['image_alt'] = sanitize_text_field($field['image_alt'] ?? '');
            }
            if ($field['type'] === 'file') {
                $sf['file_extensions'] = sanitize_text_field($field['file_extensions'] ?? 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,zip');
                $sf['file_max_size'] = intval($field['file_max_size'] ?? 5);
                $sf['file_multiple'] = !empty($field['file_multiple']);
            }
            
            // Logica condizionale
            if (!empty($field['conditions']) && is_array($field['conditions'])) {
                $conditions = $field['conditions'];
                $sanitized_rules = [];
                if (!empty($conditions['rules']) && is_array($conditions['rules'])) {
                    foreach ($conditions['rules'] as $rule) {
                        $sanitized_rules[] = [
                            'field' => sanitize_key($rule['field'] ?? ''),
                            'operator' => in_array($rule['operator'] ?? '', ['equals','not_equals','contains','not_contains','empty','not_empty','greater_than','less_than']) ? $rule['operator'] : 'equals',
                            'value' => sanitize_text_field($rule['value'] ?? ''),
                        ];
                    }
                }
                $sf['conditions'] = [
                    'enabled' => !empty($conditions['enabled']),
                    'action' => in_array($conditions['action'] ?? '', ['show', 'hide']) ? $conditions['action'] : 'show',
                    'logic' => in_array($conditions['logic'] ?? '', ['all', 'any']) ? $conditions['logic'] : 'all',
                    'rules' => $sanitized_rules,
                ];
            }
            
            $sanitized[] = $sf;
        }
        return $sanitized;
    }
    
    private static function sanitize_settings($settings) {
        return [
            'submit_text' => sanitize_text_field($settings['submit_text'] ?? 'Invia'),
            'success_message' => wp_kses_post($settings['success_message'] ?? ''),
            'enable_captcha' => !empty($settings['enable_captcha']),
            'enable_honeypot' => !empty($settings['enable_honeypot']),
            'enable_gdpr' => !empty($settings['enable_gdpr']),
            'gdpr_text' => wp_kses_post($settings['gdpr_text'] ?? ''),
            'gdpr_link' => esc_url_raw($settings['gdpr_link'] ?? ''),
            'rate_limit_enabled' => !empty($settings['rate_limit_enabled']),
            'rate_limit_max' => intval($settings['rate_limit_max'] ?? 5),
            'rate_limit_window' => intval($settings['rate_limit_window'] ?? 60),
            'send_confirmation' => !empty($settings['send_confirmation']),
            'confirmation_subject' => sanitize_text_field($settings['confirmation_subject'] ?? ''),
            'confirmation_message' => wp_kses_post($settings['confirmation_message'] ?? ''),
            'send_admin_notification' => !empty($settings['send_admin_notification']),
            'admin_email' => sanitize_text_field($settings['admin_email'] ?? ''),
            'admin_subject' => sanitize_text_field($settings['admin_subject'] ?? ''),
            'admin_message' => wp_kses_post($settings['admin_message'] ?? ''),
            'enable_webhook' => !empty($settings['enable_webhook']),
            'webhook_url' => esc_url_raw($settings['webhook_url'] ?? ''),
        ];
    }
    
    // =========================================================
    // TEMPLATES
    // =========================================================
    
    public static function get_templates() {
        return [
            'contact' => [
                'name' => __('Modulo di Contatto', 'db-form-builder'),
                'description' => __('Form classico con nome, email e messaggio', 'db-form-builder'),
                'icon' => 'dashicons-email-alt',
                'fields' => [
                    ['id' => 'field_name', 'type' => 'text', 'label' => 'Nome', 'placeholder' => 'Il tuo nome', 'required' => true],
                    ['id' => 'field_email', 'type' => 'email', 'label' => 'Email', 'placeholder' => 'La tua email', 'required' => true],
                    ['id' => 'field_subject', 'type' => 'text', 'label' => 'Oggetto', 'placeholder' => 'Oggetto del messaggio', 'required' => false],
                    ['id' => 'field_message', 'type' => 'textarea', 'label' => 'Messaggio', 'placeholder' => 'Scrivi il tuo messaggio...', 'required' => true],
                ],
                'settings' => [
                    'submit_text' => 'Invia messaggio',
                    'success_message' => 'Grazie per averci contattato! Ti risponderemo al più presto.',
                    'confirmation_subject' => 'Abbiamo ricevuto il tuo messaggio',
                    'confirmation_message' => "Ciao {nome},\n\nGrazie per averci contattato. Abbiamo ricevuto il tuo messaggio e ti risponderemo al più presto.\n\nRiepilogo:\n{riepilogo_dati}\n\nCordiali saluti,\n{sito}",
                    'admin_subject' => 'Nuovo messaggio da {nome}',
                    'admin_message' => "Hai ricevuto un nuovo messaggio dal form di contatto.\n\n{riepilogo_dati}\n\nIP: {ip}\nData: {data}",
                ]
            ],
            'newsletter' => [
                'name' => __('Iscrizione Newsletter', 'db-form-builder'),
                'description' => __('Form semplice per raccolta email', 'db-form-builder'),
                'icon' => 'dashicons-megaphone',
                'fields' => [
                    ['id' => 'field_name', 'type' => 'text', 'label' => 'Nome', 'placeholder' => 'Il tuo nome', 'required' => true],
                    ['id' => 'field_email', 'type' => 'email', 'label' => 'Email', 'placeholder' => 'La tua email', 'required' => true],
                ],
                'settings' => [
                    'submit_text' => 'Iscriviti',
                    'success_message' => 'Grazie per esserti iscritto alla nostra newsletter!',
                    'confirmation_subject' => 'Iscrizione confermata',
                    'confirmation_message' => "Ciao {nome},\n\nLa tua iscrizione alla newsletter è stata confermata.\n\nGrazie!\n{sito}",
                    'admin_subject' => 'Nuova iscrizione newsletter',
                    'admin_message' => "Nuova iscrizione alla newsletter:\n\nNome: {nome}\nEmail: {email}\n\nData: {data}",
                ]
            ],
            'quote' => [
                'name' => __('Richiesta Preventivo', 'db-form-builder'),
                'description' => __('Form per richiedere preventivi con dettagli servizio', 'db-form-builder'),
                'icon' => 'dashicons-media-document',
                'fields' => [
                    ['id' => 'field_name', 'type' => 'text', 'label' => 'Nome e Cognome', 'required' => true],
                    ['id' => 'field_company', 'type' => 'text', 'label' => 'Azienda', 'required' => false],
                    ['id' => 'field_email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                    ['id' => 'field_phone', 'type' => 'tel', 'label' => 'Telefono', 'required' => true],
                    ['id' => 'field_service', 'type' => 'select', 'label' => 'Servizio richiesto', 'required' => true, 'options' => ['Consulenza', 'Sviluppo', 'Supporto', 'Altro']],
                    ['id' => 'field_budget', 'type' => 'select', 'label' => 'Budget indicativo', 'required' => false, 'options' => ['< 1.000€', '1.000€ - 5.000€', '5.000€ - 10.000€', '> 10.000€']],
                    ['id' => 'field_message', 'type' => 'textarea', 'label' => 'Descrizione progetto', 'placeholder' => 'Descrivi brevemente il tuo progetto...', 'required' => true],
                ],
                'settings' => [
                    'submit_text' => 'Richiedi preventivo',
                    'success_message' => 'Grazie! Abbiamo ricevuto la tua richiesta e ti contatteremo entro 24-48 ore.',
                    'confirmation_subject' => 'Richiesta preventivo ricevuta',
                    'confirmation_message' => "Gentile {nome-e-cognome},\n\nAbbiamo ricevuto la tua richiesta di preventivo e la esamineremo al più presto.\n\nRiepilogo:\n{riepilogo_dati}\n\nTi contatteremo entro 24-48 ore.\n\nCordiali saluti,\n{sito}",
                    'admin_subject' => 'Nuova richiesta preventivo da {nome-e-cognome}',
                    'admin_message' => "Nuova richiesta di preventivo:\n\n{riepilogo_dati}\n\nIP: {ip}\nData: {data}",
                ]
            ],
            'event' => [
                'name' => __('Iscrizione Evento', 'db-form-builder'),
                'description' => __('Form per registrazione a eventi, corsi, webinar', 'db-form-builder'),
                'icon' => 'dashicons-calendar-alt',
                'fields' => [
                    ['id' => 'field_name', 'type' => 'text', 'label' => 'Nome e Cognome', 'required' => true],
                    ['id' => 'field_email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                    ['id' => 'field_phone', 'type' => 'tel', 'label' => 'Telefono', 'required' => false],
                    ['id' => 'field_participants', 'type' => 'number', 'label' => 'Numero partecipanti', 'placeholder' => '1', 'required' => true],
                    ['id' => 'field_dietary', 'type' => 'checkbox', 'label' => 'Esigenze alimentari', 'required' => false, 'options' => ['Vegetariano', 'Vegano', 'Senza glutine', 'Altro']],
                    ['id' => 'field_notes', 'type' => 'textarea', 'label' => 'Note aggiuntive', 'required' => false],
                ],
                'settings' => [
                    'submit_text' => 'Conferma iscrizione',
                    'success_message' => 'Iscrizione completata! Riceverai una email di conferma.',
                    'confirmation_subject' => 'Conferma iscrizione evento',
                    'confirmation_message' => "Ciao {nome-e-cognome},\n\nLa tua iscrizione è stata registrata con successo!\n\nRiepilogo:\n{riepilogo_dati}\n\nA presto!\n{sito}",
                    'admin_subject' => 'Nuova iscrizione evento',
                    'admin_message' => "Nuova iscrizione:\n\n{riepilogo_dati}\n\nData: {data}",
                ]
            ],
            'feedback' => [
                'name' => __('Feedback / Sondaggio', 'db-form-builder'),
                'description' => __('Raccogli opinioni e valutazioni', 'db-form-builder'),
                'icon' => 'dashicons-star-filled',
                'fields' => [
                    ['id' => 'field_name', 'type' => 'text', 'label' => 'Nome (opzionale)', 'required' => false],
                    ['id' => 'field_email', 'type' => 'email', 'label' => 'Email (opzionale)', 'required' => false],
                    ['id' => 'field_rating', 'type' => 'radio', 'label' => 'Come valuti la tua esperienza?', 'required' => true, 'options' => ['Eccellente', 'Buona', 'Sufficiente', 'Scarsa']],
                    ['id' => 'field_recommend', 'type' => 'radio', 'label' => 'Ci consiglieresti ad un amico?', 'required' => true, 'options' => ['Sì, sicuramente', 'Probabilmente sì', 'Non saprei', 'Probabilmente no', 'No']],
                    ['id' => 'field_improve', 'type' => 'textarea', 'label' => 'Cosa potremmo migliorare?', 'required' => false],
                ],
                'settings' => [
                    'submit_text' => 'Invia feedback',
                    'success_message' => 'Grazie per il tuo feedback! È molto importante per noi.',
                    'send_confirmation' => false,
                    'confirmation_subject' => '',
                    'confirmation_message' => '',
                    'admin_subject' => 'Nuovo feedback ricevuto',
                    'admin_message' => "Nuovo feedback:\n\n{riepilogo_dati}\n\nData: {data}",
                ]
            ],
        ];
    }
    
    public static function ajax_create_from_template() {
        check_ajax_referer('dbfb_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permessi insufficienti']);
        }
        
        $template_id = sanitize_key($_POST['template'] ?? '');
        $templates = self::get_templates();
        
        if (!isset($templates[$template_id])) {
            wp_send_json_error(['message' => 'Template non trovato']);
        }
        
        $template = $templates[$template_id];
        
        $form_id = wp_insert_post([
            'post_type' => 'dbfb_form',
            'post_title' => $template['name'],
            'post_status' => 'publish',
        ]);
        
        if (is_wp_error($form_id)) {
            wp_send_json_error(['message' => 'Errore nella creazione del form']);
        }
        
        $fields = [];
        foreach ($template['fields'] as $field) {
            $field['id'] = $field['id'] . '_' . time();
            $fields[] = $field;
        }
        update_post_meta($form_id, '_dbfb_fields', $fields);
        
        $default_settings = [
            'submit_text' => 'Invia',
            'success_message' => 'Grazie! Form inviato con successo.',
            'enable_captcha' => false,
            'send_confirmation' => true,
            'confirmation_subject' => '',
            'confirmation_message' => '',
            'send_admin_notification' => true,
            'admin_email' => get_option('admin_email'),
            'admin_subject' => '',
            'admin_message' => '',
        ];
        $settings = wp_parse_args($template['settings'], $default_settings);
        update_post_meta($form_id, '_dbfb_settings', $settings);
        
        wp_send_json_success([
            'form_id' => $form_id,
            'redirect' => admin_url('admin.php?page=dbfb-forms&action=edit&form_id=' . $form_id)
        ]);
    }
}
