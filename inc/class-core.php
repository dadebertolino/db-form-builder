<?php
if (!defined('ABSPATH')) exit;

class DB_Form_Builder {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Core
        add_action('init', [$this, 'register_post_type']);
        add_action('admin_init', [$this, 'handle_form_actions']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_scripts']);
        add_shortcode('dbfb_form', [$this, 'render_form_shortcode']);
        
        register_activation_hook(DBFB_PLUGIN_FILE, [$this, 'activate']);
        
        // Builder
        add_action('wp_ajax_dbfb_save_form', ['DBFB_Builder', 'ajax_save_form']);
        add_action('wp_ajax_dbfb_create_from_template', ['DBFB_Builder', 'ajax_create_from_template']);
        
        // Submit
        add_action('wp_ajax_dbfb_submit_form', ['DBFB_Submit', 'ajax_submit_form']);
        add_action('wp_ajax_nopriv_dbfb_submit_form', ['DBFB_Submit', 'ajax_submit_form']);
        
        // Submissions
        add_action('wp_ajax_dbfb_export_csv', ['DBFB_Submissions', 'ajax_export_csv']);
        
        // Email
        add_action('wp_ajax_dbfb_send_test_email', ['DBFB_Email', 'ajax_send_test_email']);
        
        // Settings
        add_action('wp_ajax_dbfb_save_global_settings', ['DBFB_Settings', 'ajax_save_global_settings']);
        add_action('wp_ajax_dbfb_test_recaptcha', ['DBFB_Settings', 'ajax_test_recaptcha']);
        add_action('wp_ajax_dbfb_test_email', ['DBFB_Settings', 'ajax_test_email']);
        
        // Gutenberg
        add_action('init', ['DBFB_Gutenberg', 'register_block']);
        
        // Widget
        add_action('widgets_init', function() { register_widget('DBFB_Widget'); });
    }
    
    // =========================================================
    // ACTIVATION & DB
    // =========================================================
    
    public function activate() {
        self::maybe_create_table();
    }
    
    public static function maybe_create_table() {
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
    
    // =========================================================
    // CPT
    // =========================================================
    
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
    
    // =========================================================
    // ADMIN MENU
    // =========================================================
    
    public function admin_menu() {
        add_menu_page(
            __('Form Builder', 'db-form-builder'),
            __('Form Builder', 'db-form-builder'),
            'manage_options', 'dbfb-forms',
            [$this, 'render_forms_page'],
            'dashicons-feedback', 30
        );
        
        add_submenu_page('dbfb-forms', __('Tutti i Form', 'db-form-builder'), __('Tutti i Form', 'db-form-builder'), 'manage_options', 'dbfb-forms', [$this, 'render_forms_page']);
        add_submenu_page('dbfb-forms', __('Nuovo Form', 'db-form-builder'), __('Nuovo Form', 'db-form-builder'), 'manage_options', 'dbfb-new-form', ['DBFB_Builder', 'render_new_form']);
        add_submenu_page('dbfb-forms', __('Risposte', 'db-form-builder'), __('Risposte', 'db-form-builder'), 'manage_options', 'dbfb-submissions', ['DBFB_Submissions', 'render_all_submissions_page']);
        add_submenu_page('dbfb-forms', __('Impostazioni', 'db-form-builder'), __('Impostazioni', 'db-form-builder'), 'manage_options', 'dbfb-settings', ['DBFB_Settings', 'render_page']);
    }
    
    // =========================================================
    // SCRIPTS
    // =========================================================
    
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
            $url = $recaptcha_version === 'v3'
                ? 'https://www.google.com/recaptcha/api.js?render=' . esc_attr($global_settings['recaptcha_site_key'])
                : 'https://www.google.com/recaptcha/api.js';
            wp_enqueue_script('google-recaptcha', $url, [], null, true);
        }
        
        wp_localize_script('dbfb-frontend', 'dbfb', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dbfb_submit_nonce'),
            'recaptcha_site_key' => $global_settings['recaptcha_site_key'],
            'recaptcha_version' => $global_settings['recaptcha_version'] ?? 'v2',
        ]);
    }
    
    // =========================================================
    // ROUTING
    // =========================================================
    
    public function render_forms_page() {
        if (isset($_GET['action']) && $_GET['action'] === 'submissions' && isset($_GET['form_id'])) {
            DBFB_Submissions::render_page(intval($_GET['form_id']));
            return;
        }
        
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['form_id'])) {
            DBFB_Builder::render_form_builder(intval($_GET['form_id']));
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
    
    // =========================================================
    // ADMIN ACTIONS (before output)
    // =========================================================
    
    public function handle_form_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'dbfb-forms') return;
        
        // Delete form
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['form_id'])) {
            $form_id = intval($_GET['form_id']);
            if (isset($_GET['_wpnonce']) && !wp_verify_nonce($_GET['_wpnonce'], 'dbfb_delete_' . $form_id)) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti', 'db-form-builder'));
            
            wp_delete_post($form_id, true);
            global $wpdb;
            $wpdb->delete($wpdb->prefix . 'dbfb_submissions', ['form_id' => $form_id], ['%d']);
            wp_redirect(admin_url('admin.php?page=dbfb-forms&deleted=1'));
            exit;
        }
        
        // Duplicate form
        if (isset($_GET['action']) && $_GET['action'] === 'duplicate' && isset($_GET['form_id'])) {
            $form_id = intval($_GET['form_id']);
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'dbfb_duplicate_' . $form_id)) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti', 'db-form-builder'));
            
            $original = get_post($form_id);
            if (!$original) wp_die(__('Form non trovato', 'db-form-builder'));
            
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
        
        // Delete single submission
        if (isset($_GET['action']) && $_GET['action'] === 'delete_submission' && isset($_GET['submission_id'])) {
            $submission_id = intval($_GET['submission_id']);
            $form_id = intval($_GET['form_id'] ?? 0);
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'dbfb_delete_sub_' . $submission_id)) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti', 'db-form-builder'));
            
            global $wpdb;
            $wpdb->delete($wpdb->prefix . 'dbfb_submissions', ['id' => $submission_id], ['%d']);
            $redirect_page = $form_id ? 'dbfb-forms&action=submissions&form_id=' . $form_id : 'dbfb-submissions';
            wp_redirect(admin_url('admin.php?page=' . $redirect_page . '&sub_deleted=1'));
            exit;
        }
        
        // Bulk delete submissions
        if (isset($_POST['dbfb_bulk_action']) && $_POST['dbfb_bulk_action'] === 'delete' && !empty($_POST['submission_ids'])) {
            $form_id = intval($_POST['form_id'] ?? 0);
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'dbfb_bulk_submissions')) {
                wp_die(__('Azione non autorizzata', 'db-form-builder'));
            }
            if (!current_user_can('manage_options')) wp_die(__('Permessi insufficienti', 'db-form-builder'));
            
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
    
    // =========================================================
    // SHARED HELPERS
    // =========================================================
    
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
    
    public static function get_client_ip() {
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
}
