<?php
/**
 * Plugin Name: DB Form Builder
 * Plugin URI: https://www.davidebertolino.it
 * Description: Form builder con drag & drop, reCAPTCHA, email personalizzabili e export CSV
 * Version: 2.0.0
 * Author: Davide Bertolino
 * Author URI: https://www.davidebertolino.it
 * Text Domain: db-form-builder
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

define('DBFB_VERSION', '2.0.0');
define('DBFB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DBFB_PLUGIN_URL', plugin_dir_url(__FILE__));

class DB_Form_Builder {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_gutenberg_block']);
        add_action('admin_init', [$this, 'handle_form_actions']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_scripts']);
        add_action('wp_ajax_dbfb_save_form', [$this, 'ajax_save_form']);
        add_action('wp_ajax_dbfb_save_global_settings', [$this, 'ajax_save_global_settings']);
        add_action('wp_ajax_dbfb_submit_form', [$this, 'ajax_submit_form']);
        add_action('wp_ajax_nopriv_dbfb_submit_form', [$this, 'ajax_submit_form']);
        add_action('wp_ajax_dbfb_export_csv', [$this, 'ajax_export_csv']);
        add_action('wp_ajax_dbfb_send_test_email', [$this, 'ajax_send_test_email']);
        add_action('wp_ajax_dbfb_create_from_template', [$this, 'ajax_create_from_template']);
        add_shortcode('dbfb_form', [$this, 'render_form_shortcode']);
        add_action('widgets_init', [$this, 'register_widget']);
        
        register_activation_hook(__FILE__, [$this, 'activate']);
        
        add_action('wp_ajax_dbfb_test_recaptcha', [$this, 'ajax_test_recaptcha']);
        add_action('wp_ajax_dbfb_test_email', [$this, 'ajax_test_email']);
    }
    
    /**
     * Test invio email
     */
    public function ajax_test_email() {
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
               "Dettagli:\n" .
               "- Sito: %s\n" .
               "- Mittente: %s <%s>\n" .
               "- Data: %s\n\n" .
               "---\n" .
               "DB Form Builder", 'db-form-builder'),
            home_url(),
            $from_name,
            $from_email,
            current_time('d/m/Y H:i:s')
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
    
    /**
     * Test chiavi reCAPTCHA
     */
    public function ajax_test_recaptcha() {
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
            'body' => [
                'secret' => $secret_key,
                'response' => $token,
            ]
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => 'Errore di connessione: ' . $response->get_error_message()
            ]);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($body)) {
            wp_send_json_error(['message' => 'Risposta non valida da Google']);
        }
        
        if (!empty($body['success'])) {
            $msg = '✓ Chiavi valide! ';
            if (isset($body['score'])) {
                $msg .= 'Score: ' . $body['score'] . ' (v3)';
            } else {
                $msg .= '(v2)';
            }
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
    
    /**
     * Ottiene le impostazioni globali del plugin
     */
    public static function get_global_settings() {
        $defaults = [
            'recaptcha_version' => 'v2',
            'recaptcha_site_key' => '',
            'recaptcha_secret_key' => '',
            'from_email' => get_option('admin_email'),
            'from_name' => get_bloginfo('name'),
        ];
        return wp_parse_args(get_option('dbfb_global_settings', []), $defaults);
    }
    
    public function activate() {
        $this->maybe_create_table();
    }
    
    private function maybe_create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return;
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            form_id bigint(20) NOT NULL,
            data longtext NOT NULL,
            submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
            ip_address varchar(45),
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function register_post_type() {
        register_post_type('dbfb_form', [
            'labels' => [
                'name' => __('Form', 'db-form-builder'),
                'singular_name' => __('Form', 'db-form-builder'),
                'add_new' => __('Nuovo Form', 'db-form-builder'),
                'add_new_item' => __('Aggiungi Nuovo Form', 'db-form-builder'),
                'edit_item' => __('Modifica Form', 'db-form-builder'),
            ],
            'public' => false,
            'show_ui' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
        ]);
    }
    
    /**
     * Registra blocco Gutenberg
     */
    public function register_gutenberg_block() {
        if (!function_exists('register_block_type')) return;
        
        wp_register_script(
            'dbfb-gutenberg-block',
            DBFB_PLUGIN_URL . 'assets/js/gutenberg-block.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor'],
            DBFB_VERSION
        );
        
        $forms = get_posts([
            'post_type' => 'dbfb_form',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        
        $forms_list = [['value' => '', 'label' => __('Seleziona un form...', 'db-form-builder')]];
        foreach ($forms as $form) {
            $forms_list[] = ['value' => $form->ID, 'label' => $form->post_title];
        }
        
        wp_localize_script('dbfb-gutenberg-block', 'dbfbBlock', [
            'forms' => $forms_list
        ]);
        
        register_block_type('dbfb/form', [
            'editor_script' => 'dbfb-gutenberg-block',
            'render_callback' => [$this, 'render_gutenberg_block'],
            'attributes' => [
                'formId' => ['type' => 'string', 'default' => '']
            ]
        ]);
    }
    
    public function render_gutenberg_block($attributes) {
        if (empty($attributes['formId'])) {
            return '<p>' . __('Seleziona un form dalle impostazioni del blocco.', 'db-form-builder') . '</p>';
        }
        return $this->render_form_shortcode(['id' => $attributes['formId']]);
    }
    
    public function register_widget() {
        register_widget('DBFB_Widget');
    }
    
    /**
     * Template predefiniti
     */
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
                    ['id' => 'field_name', 'type' => 'text', 'label' => 'Nome e Cognome', 'placeholder' => '', 'required' => true],
                    ['id' => 'field_company', 'type' => 'text', 'label' => 'Azienda', 'placeholder' => '', 'required' => false],
                    ['id' => 'field_email', 'type' => 'email', 'label' => 'Email', 'placeholder' => '', 'required' => true],
                    ['id' => 'field_phone', 'type' => 'tel', 'label' => 'Telefono', 'placeholder' => '', 'required' => true],
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
                    ['id' => 'field_name', 'type' => 'text', 'label' => 'Nome e Cognome', 'placeholder' => '', 'required' => true],
                    ['id' => 'field_email', 'type' => 'email', 'label' => 'Email', 'placeholder' => '', 'required' => true],
                    ['id' => 'field_phone', 'type' => 'tel', 'label' => 'Telefono', 'placeholder' => '', 'required' => false],
                    ['id' => 'field_participants', 'type' => 'number', 'label' => 'Numero partecipanti', 'placeholder' => '1', 'required' => true],
                    ['id' => 'field_dietary', 'type' => 'checkbox', 'label' => 'Esigenze alimentari', 'required' => false, 'options' => ['Vegetariano', 'Vegano', 'Senza glutine', 'Altro']],
                    ['id' => 'field_notes', 'type' => 'textarea', 'label' => 'Note aggiuntive', 'placeholder' => '', 'required' => false],
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
                    ['id' => 'field_name', 'type' => 'text', 'label' => 'Nome (opzionale)', 'placeholder' => '', 'required' => false],
                    ['id' => 'field_email', 'type' => 'email', 'label' => 'Email (opzionale)', 'placeholder' => '', 'required' => false],
                    ['id' => 'field_rating', 'type' => 'radio', 'label' => 'Come valuti la tua esperienza?', 'required' => true, 'options' => ['Eccellente', 'Buona', 'Sufficiente', 'Scarsa']],
                    ['id' => 'field_recommend', 'type' => 'radio', 'label' => 'Ci consiglieresti ad un amico?', 'required' => true, 'options' => ['Sì, sicuramente', 'Probabilmente sì', 'Non saprei', 'Probabilmente no', 'No']],
                    ['id' => 'field_improve', 'type' => 'textarea', 'label' => 'Cosa potremmo migliorare?', 'placeholder' => '', 'required' => false],
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
    
    /**
     * Crea form da template via AJAX
     */
    public function ajax_create_from_template() {
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
    
    /**
     * Gestisce azioni form (delete, duplicate) prima dell'output HTML
     */
    public function handle_form_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'dbfb-forms') {
            return;
        }
        
        // Cancellazione form
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['form_id'])) {
            $form_id = intval($_GET['form_id']);
            
            if (isset($_GET['_wpnonce']) && !wp_verify_nonce($_GET['_wpnonce'], 'dbfb_delete_' . $form_id)) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            
            if (!current_user_can('manage_options')) {
                wp_die(__('Permessi insufficienti', 'db-form-builder'));
            }
            
            wp_delete_post($form_id, true);
            
            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_submissions';
            $wpdb->delete($table, ['form_id' => $form_id], ['%d']);
            
            wp_redirect(admin_url('admin.php?page=dbfb-forms&deleted=1'));
            exit;
        }
        
        // Duplicazione form
        if (isset($_GET['action']) && $_GET['action'] === 'duplicate' && isset($_GET['form_id'])) {
            $form_id = intval($_GET['form_id']);
            
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'dbfb_duplicate_' . $form_id)) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            
            if (!current_user_can('manage_options')) {
                wp_die(__('Permessi insufficienti', 'db-form-builder'));
            }
            
            $original = get_post($form_id);
            if (!$original) {
                wp_die(__('Form non trovato', 'db-form-builder'));
            }
            
            $new_form_id = wp_insert_post([
                'post_type' => 'dbfb_form',
                'post_title' => $original->post_title . ' (copia)',
                'post_status' => 'publish',
            ]);
            
            if (!is_wp_error($new_form_id)) {
                $fields = get_post_meta($form_id, '_dbfb_fields', true);
                $settings = get_post_meta($form_id, '_dbfb_settings', true);
                if ($fields) update_post_meta($new_form_id, '_dbfb_fields', $fields);
                if ($settings) update_post_meta($new_form_id, '_dbfb_settings', $settings);
                
                wp_redirect(admin_url('admin.php?page=dbfb-forms&action=edit&form_id=' . $new_form_id . '&duplicated=1'));
                exit;
            }
            
            wp_redirect(admin_url('admin.php?page=dbfb-forms'));
            exit;
        }
        
        // Eliminazione singola risposta
        if (isset($_GET['action']) && $_GET['action'] === 'delete_submission' && isset($_GET['submission_id'])) {
            $submission_id = intval($_GET['submission_id']);
            $form_id = intval($_GET['form_id'] ?? 0);
            
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'dbfb_delete_sub_' . $submission_id)) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            
            if (!current_user_can('manage_options')) {
                wp_die(__('Permessi insufficienti', 'db-form-builder'));
            }
            
            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_submissions';
            $wpdb->delete($table, ['id' => $submission_id], ['%d']);
            
            $redirect_page = $form_id ? 'dbfb-forms&action=submissions&form_id=' . $form_id : 'dbfb-submissions';
            wp_redirect(admin_url('admin.php?page=' . $redirect_page . '&sub_deleted=1'));
            exit;
        }
        
        // Eliminazione massiva risposte
        if (isset($_POST['dbfb_bulk_action']) && $_POST['dbfb_bulk_action'] === 'delete' && !empty($_POST['submission_ids'])) {
            $form_id = intval($_POST['form_id'] ?? 0);
            
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'dbfb_bulk_submissions')) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            
            if (!current_user_can('manage_options')) {
                wp_die(__('Permessi insufficienti', 'db-form-builder'));
            }
            
            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_submissions';
            $ids = array_map('intval', (array) $_POST['submission_ids']);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id IN ($placeholders)", $ids));
            
            $redirect_page = $form_id ? 'dbfb-forms&action=submissions&form_id=' . $form_id : 'dbfb-submissions';
            wp_redirect(admin_url('admin.php?page=' . $redirect_page . '&sub_deleted=' . count($ids)));
            exit;
        }
    }
    
    public function admin_menu() {
        add_menu_page(
            __('Form Builder', 'db-form-builder'),
            __('Form Builder', 'db-form-builder'),
            'manage_options',
            'dbfb-forms',
            [$this, 'render_forms_page'],
            'dashicons-feedback',
            30
        );
        
        add_submenu_page(
            'dbfb-forms',
            __('Tutti i Form', 'db-form-builder'),
            __('Tutti i Form', 'db-form-builder'),
            'manage_options',
            'dbfb-forms',
            [$this, 'render_forms_page']
        );
        
        add_submenu_page(
            'dbfb-forms',
            __('Nuovo Form', 'db-form-builder'),
            __('Nuovo Form', 'db-form-builder'),
            'manage_options',
            'dbfb-new-form',
            [$this, 'render_form_builder']
        );
        
        add_submenu_page(
            'dbfb-forms',
            __('Risposte', 'db-form-builder'),
            __('Risposte', 'db-form-builder'),
            'manage_options',
            'dbfb-submissions',
            [$this, 'render_all_submissions_page']
        );
        
        add_submenu_page(
            'dbfb-forms',
            __('Impostazioni', 'db-form-builder'),
            __('Impostazioni', 'db-form-builder'),
            'manage_options',
            'dbfb-settings',
            [$this, 'render_settings_page']
        );
    }
    
    public function admin_scripts($hook) {
        if (strpos($hook, 'dbfb') === false) return;
        
        wp_enqueue_media();
        
        wp_enqueue_style('dbfb-admin', DBFB_PLUGIN_URL . 'assets/css/admin.css', [], DBFB_VERSION);
        wp_enqueue_script('sortablejs', 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js', [], '1.15.0', true);
        wp_enqueue_script('dbfb-admin', DBFB_PLUGIN_URL . 'assets/js/admin.js', ['jquery', 'sortablejs'], DBFB_VERSION, true);
        
        wp_localize_script('dbfb-admin', 'dbfb', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dbfb_nonce'),
            'strings' => [
                'confirm_delete' => __('Sei sicuro di voler eliminare questo campo?', 'db-form-builder'),
                'saved' => __('Form salvato!', 'db-form-builder'),
                'error' => __('Errore durante il salvataggio', 'db-form-builder'),
            ]
        ]);
    }
    
    public function frontend_scripts() {
        $global_settings = self::get_global_settings();
        
        wp_enqueue_style('dbfb-frontend', DBFB_PLUGIN_URL . 'assets/css/frontend.css', [], DBFB_VERSION);
        wp_enqueue_script('dbfb-frontend', DBFB_PLUGIN_URL . 'assets/js/frontend.js', ['jquery'], DBFB_VERSION, true);
        
        if (!empty($global_settings['recaptcha_site_key'])) {
            $recaptcha_version = $global_settings['recaptcha_version'] ?? 'v2';
            
            if ($recaptcha_version === 'v3') {
                wp_enqueue_script(
                    'google-recaptcha', 
                    'https://www.google.com/recaptcha/api.js?render=' . esc_attr($global_settings['recaptcha_site_key']), 
                    [], 
                    null, 
                    true
                );
            } else {
                wp_enqueue_script(
                    'google-recaptcha', 
                    'https://www.google.com/recaptcha/api.js', 
                    [], 
                    null, 
                    true
                );
            }
        }
        
        wp_localize_script('dbfb-frontend', 'dbfb', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dbfb_submit_nonce'),
            'recaptcha_site_key' => $global_settings['recaptcha_site_key'],
            'recaptcha_version' => $global_settings['recaptcha_version'] ?? 'v2',
        ]);
    }
    
    public function render_forms_page() {
        if (isset($_GET['action']) && $_GET['action'] === 'submissions' && isset($_GET['form_id'])) {
            $this->render_submissions_page(intval($_GET['form_id']));
            return;
        }
        
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['form_id'])) {
            $this->render_form_builder(intval($_GET['form_id']));
            return;
        }
        
        $forms = get_posts([
            'post_type' => 'dbfb_form',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        include DBFB_PLUGIN_DIR . 'templates/admin/forms-list.php';
    }
    
    public function render_form_builder($form_id = 0) {
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
        ];
        
        $form_settings = wp_parse_args($form_settings, $default_settings);
        
        $templates = self::get_templates();
        $show_templates = (empty($form_id) || $form_id == 0);
        
        include DBFB_PLUGIN_DIR . 'templates/admin/form-builder.php';
    }
    
    public function render_submissions_page($form_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';
        
        $form = get_post($form_id);
        $form_fields = get_post_meta($form_id, '_dbfb_fields', true) ?: [];
        
        // Filtra solo campi input (escludi divider, html, image)
        $form_fields = array_filter($form_fields, function($f) {
            return !in_array($f['type'], ['divider', 'html', 'image']);
        });
        
        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE form_id = %d ORDER BY submitted_at DESC",
            $form_id
        ));
        
        include DBFB_PLUGIN_DIR . 'templates/admin/submissions.php';
    }
    
    public function render_settings_page() {
        $global_settings = self::get_global_settings();
        include DBFB_PLUGIN_DIR . 'templates/admin/settings.php';
    }
    
    public function render_all_submissions_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';
        
        if (isset($_GET['form_id']) && intval($_GET['form_id']) > 0) {
            $this->render_submissions_page(intval($_GET['form_id']));
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
    
    public function ajax_save_global_settings() {
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
    
    public function ajax_save_form() {
        check_ajax_referer('dbfb_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permessi insufficienti']);
        }
        
        $form_id = intval($_POST['form_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        $fields = json_decode(stripslashes($_POST['fields'] ?? '[]'), true);
        $settings = json_decode(stripslashes($_POST['settings'] ?? '{}'), true);
        
        $sanitized_fields = [];
        foreach ($fields as $field) {
            $sanitized_field = [
                'id' => sanitize_key($field['id'] ?? uniqid('field_')),
                'type' => sanitize_key($field['type'] ?? 'text'),
                'label' => sanitize_text_field($field['label'] ?? ''),
                'placeholder' => sanitize_text_field($field['placeholder'] ?? ''),
                'required' => !empty($field['required']),
                'options' => isset($field['options']) ? array_map('sanitize_text_field', $field['options']) : [],
            ];
            
            if ($field['type'] === 'html') {
                $sanitized_field['content'] = wp_kses_post($field['content'] ?? '');
            }
            if ($field['type'] === 'image') {
                $sanitized_field['image_url'] = esc_url_raw($field['image_url'] ?? '');
                $sanitized_field['image_alt'] = sanitize_text_field($field['image_alt'] ?? '');
            }
            
            $sanitized_fields[] = $sanitized_field;
        }
        
        $sanitized_settings = [
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
        ];
        
        if ($form_id > 0) {
            wp_update_post([
                'ID' => $form_id,
                'post_title' => $title,
            ]);
        } else {
            $form_id = wp_insert_post([
                'post_type' => 'dbfb_form',
                'post_title' => $title,
                'post_status' => 'publish',
            ]);
        }
        
        update_post_meta($form_id, '_dbfb_fields', $sanitized_fields);
        update_post_meta($form_id, '_dbfb_settings', $sanitized_settings);
        
        wp_send_json_success([
            'form_id' => $form_id,
            'message' => 'Form salvato con successo!'
        ]);
    }
    
    public function ajax_submit_form() {
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
        $global_settings = self::get_global_settings();
        
        // Honeypot check
        if (!empty($form_settings['enable_honeypot'])) {
            $honeypot_value = sanitize_text_field($_POST['dbfb_website_url'] ?? '');
            if (!empty($honeypot_value)) {
                // Bot detected - respond with success to not reveal detection
                wp_send_json_success([
                    'message' => $form_settings['success_message'] ?? __('Form inviato con successo!', 'db-form-builder')
                ]);
                return;
            }
            
            // Check timestamp (form submitted too fast = bot)
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
            $ip = $this->get_client_ip();
            $max_submissions = intval($form_settings['rate_limit_max'] ?? 5);
            $window_minutes = intval($form_settings['rate_limit_window'] ?? 60);
            
            $transient_key = 'dbfb_rate_' . md5($ip . '_' . $form_id);
            $submissions_count = get_transient($transient_key);
            
            if ($submissions_count !== false && $submissions_count >= $max_submissions) {
                wp_send_json_error([
                    'message' => sprintf(
                        __('Hai raggiunto il limite di %d invii. Riprova tra %d minuti.', 'db-form-builder'),
                        $max_submissions,
                        $window_minutes
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
            // Remove GDPR from saved data
            unset($form_data['dbfb_gdpr_consent']);
        }
        
        // Verifica reCAPTCHA se abilitato
        if (!empty($form_settings['enable_captcha']) && !empty($global_settings['recaptcha_secret_key'])) {
            $recaptcha_valid = $this->verify_recaptcha($recaptcha_token, $global_settings['recaptcha_secret_key']);
            if (!$recaptcha_valid) {
                wp_send_json_error(['message' => __('Verifica anti-spam fallita. Riprova.', 'db-form-builder')]);
            }
        }
        
        // Valida campi obbligatori
        foreach ($form_fields as $field) {
            if (!empty($field['required']) && empty($form_data[$field['id']])) {
                wp_send_json_error([
                    'message' => sprintf(__('Il campo "%s" è obbligatorio', 'db-form-builder'), $field['label'])
                ]);
            }
        }
        
        // Salva submission
        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';
        
        $this->maybe_create_table();
        
        $result = $wpdb->insert($table, [
            'form_id' => $form_id,
            'data' => json_encode($form_data),
            'ip_address' => $this->get_client_ip(),
        ]);
        
        if ($result === false) {
            error_log('DB Form Builder: Errore inserimento DB - ' . $wpdb->last_error);
        }
        
        // Prepara dati per sostituzione placeholder
        $placeholders = $this->prepare_email_placeholders($form, $form_fields, $form_data, $form_settings);
        
        // Trova email utente
        $user_email = '';
        foreach ($form_fields as $field) {
            if ($field['type'] === 'email' && !empty($form_data[$field['id']])) {
                $user_email = sanitize_email($form_data[$field['id']]);
                break;
            }
        }
        
        // Invia email di conferma all'utente
        if (!empty($form_settings['send_confirmation']) && $user_email) {
            $this->send_confirmation_email($user_email, $form_settings, $placeholders);
        }
        
        // Invia email all'admin (supporta più destinatari separati da virgola)
        if (!empty($form_settings['send_admin_notification']) && !empty($form_settings['admin_email'])) {
            $this->send_admin_email($form_settings, $placeholders);
        }
        
        wp_send_json_success([
            'message' => $form_settings['success_message'] ?? __('Form inviato con successo!', 'db-form-builder')
        ]);
    }
    
    /**
     * Verifica token reCAPTCHA
     */
    private function verify_recaptcha($token, $secret_key) {
        if (empty($token)) {
            error_log('DB Form Builder: reCAPTCHA token vuoto');
            return false;
        }
        
        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'timeout' => 10,
            'body' => [
                'secret' => $secret_key,
                'response' => $token,
                'remoteip' => $this->get_client_ip(),
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
            if (!$valid) {
                error_log('DB Form Builder: reCAPTCHA score basso: ' . ($body['score'] ?? 'N/A'));
            }
            return $valid;
        }
        
        return !empty($body['success']);
    }
    
    /**
     * Prepara i placeholder per le email
     */
    private function prepare_email_placeholders($form, $fields, $data, $settings) {
        $placeholders = [
            '{form_titolo}' => $form->post_title,
            '{ip}' => $this->get_client_ip(),
            '{data}' => current_time('d/m/Y H:i:s'),
            '{sito}' => get_bloginfo('name'),
        ];
        
        $riepilogo = '';
        foreach ($fields as $field) {
            if (in_array($field['type'], ['divider', 'html', 'image'])) continue;
            
            $value = isset($data[$field['id']]) ? $data[$field['id']] : '';
            if (is_array($value)) $value = implode(', ', $value);
            
            $field_key = sanitize_title($field['label']);
            $placeholders['{' . $field_key . '}'] = $value;
            
            $riepilogo .= $field['label'] . ': ' . $value . "\n";
        }
        
        $placeholders['{riepilogo_dati}'] = trim($riepilogo);
        
        return $placeholders;
    }
    
    private function replace_placeholders($text, $placeholders) {
        return str_replace(array_keys($placeholders), array_values($placeholders), $text);
    }
    
    private function send_confirmation_email($to, $settings, $placeholders) {
        $global_settings = self::get_global_settings();
        
        $subject = $this->replace_placeholders($settings['confirmation_subject'] ?? '', $placeholders);
        $message = $this->replace_placeholders($settings['confirmation_message'] ?? '', $placeholders);
        
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $global_settings['from_name'] . ' <' . $global_settings['from_email'] . '>',
        ];
        
        return wp_mail($to, $subject, $message, $headers);
    }
    
    private function send_admin_email($settings, $placeholders) {
        $global_settings = self::get_global_settings();
        
        // Supporta più destinatari separati da virgola
        $to = $settings['admin_email'];
        $recipients = array_map('trim', explode(',', $to));
        $recipients = array_filter($recipients, 'is_email');
        
        if (empty($recipients)) return false;
        
        $subject = $this->replace_placeholders($settings['admin_subject'] ?? '', $placeholders);
        $message = $this->replace_placeholders($settings['admin_message'] ?? '', $placeholders);
        
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $global_settings['from_name'] . ' <' . $global_settings['from_email'] . '>',
        ];
        
        $success = true;
        foreach ($recipients as $recipient) {
            if (!wp_mail($recipient, $subject, $message, $headers)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * Invia email di test
     */
    public function ajax_send_test_email() {
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
            if (in_array($field['type'], ['divider', 'html', 'image'])) continue;
            switch ($field['type']) {
                case 'email':
                    $sample_data[$field['id']] = 'esempio@email.com';
                    break;
                case 'tel':
                    $sample_data[$field['id']] = '+39 123 456 7890';
                    break;
                case 'number':
                    $sample_data[$field['id']] = '42';
                    break;
                case 'date':
                    $sample_data[$field['id']] = date('Y-m-d');
                    break;
                case 'checkbox':
                case 'radio':
                case 'select':
                    $sample_data[$field['id']] = $field['options'][0] ?? 'Opzione esempio';
                    break;
                default:
                    $sample_data[$field['id']] = 'Valore di esempio per ' . $field['label'];
            }
        }
        
        $placeholders = $this->prepare_email_placeholders($form, $form_fields, $sample_data, $form_settings);
        $global_settings = self::get_global_settings();
        
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $global_settings['from_name'] . ' <' . $global_settings['from_email'] . '>',
        ];
        
        if ($email_type === 'confirmation') {
            $subject = '[TEST] ' . $this->replace_placeholders($form_settings['confirmation_subject'] ?? '', $placeholders);
            $message = $this->replace_placeholders($form_settings['confirmation_message'] ?? '', $placeholders);
        } else {
            $subject = '[TEST] ' . $this->replace_placeholders($form_settings['admin_subject'] ?? '', $placeholders);
            $message = $this->replace_placeholders($form_settings['admin_message'] ?? '', $placeholders);
        }
        
        $sent = wp_mail($test_email, $subject, $message, $headers);
        
        if ($sent) {
            wp_send_json_success(['message' => 'Email di test inviata a ' . $test_email]);
        } else {
            wp_send_json_error(['message' => 'Errore nell\'invio dell\'email. Verifica le impostazioni SMTP.']);
        }
    }
    
    private function get_client_ip() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field($ip);
    }
    
    public function ajax_export_csv() {
        check_ajax_referer('dbfb_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permessi insufficienti');
        }
        
        $form_id = intval($_GET['form_id'] ?? 0);
        
        if (!$form_id) {
            wp_die('Form non valido');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'dbfb_submissions';
        
        $form = get_post($form_id);
        $form_fields = get_post_meta($form_id, '_dbfb_fields', true) ?: [];
        
        // Filtra solo campi input
        $form_fields = array_filter($form_fields, function($f) {
            return !in_array($f['type'], ['divider', 'html', 'image']);
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
            $row = [
                $submission->id,
                date('d/m/Y H:i', strtotime($submission->submitted_at))
            ];
            
            foreach ($form_fields as $field) {
                $value = $data[$field['id']] ?? '';
                $row[] = is_array($value) ? implode(', ', $value) : $value;
            }
            
            $row[] = $submission->ip_address;
            fputcsv($output, $row, ';');
        }
        
        fclose($output);
        exit;
    }
    
    public function render_form_shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $form_id = intval($atts['id']);
        
        if (!$form_id) return '';
        
        $form_fields = get_post_meta($form_id, '_dbfb_fields', true) ?: [];
        $form_settings = get_post_meta($form_id, '_dbfb_settings', true) ?: [];
        
        if (empty($form_fields)) return '';
        
        ob_start();
        include DBFB_PLUGIN_DIR . 'templates/frontend/form.php';
        return ob_get_clean();
    }
}

DB_Form_Builder::get_instance();

/**
 * Widget classico per form
 */
class DBFB_Widget extends WP_Widget {
    
    public function __construct() {
        parent::__construct(
            'dbfb_widget',
            __('DB Form Builder', 'db-form-builder'),
            ['description' => __('Inserisci un form nel widget', 'db-form-builder')]
        );
    }
    
    public function widget($args, $instance) {
        echo $args['before_widget'];
        
        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }
        
        if (!empty($instance['form_id'])) {
            echo do_shortcode('[dbfb_form id="' . intval($instance['form_id']) . '"]');
        }
        
        echo $args['after_widget'];
    }
    
    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $form_id = !empty($instance['form_id']) ? $instance['form_id'] : '';
        
        $forms = get_posts([
            'post_type' => 'dbfb_form',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php _e('Titolo:', 'db-form-builder'); ?>
            </label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" 
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" 
                   type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('form_id')); ?>">
                <?php _e('Seleziona Form:', 'db-form-builder'); ?>
            </label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('form_id')); ?>" 
                    name="<?php echo esc_attr($this->get_field_name('form_id')); ?>">
                <option value=""><?php _e('-- Seleziona --', 'db-form-builder'); ?></option>
                <?php foreach ($forms as $form): ?>
                    <option value="<?php echo $form->ID; ?>" <?php selected($form_id, $form->ID); ?>>
                        <?php echo esc_html($form->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php
    }
    
    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = sanitize_text_field($new_instance['title'] ?? '');
        $instance['form_id'] = intval($new_instance['form_id'] ?? 0);
        return $instance;
    }
}
