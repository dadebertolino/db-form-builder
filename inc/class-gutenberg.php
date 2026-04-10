<?php
if (!defined('ABSPATH')) exit;

class DBFB_Gutenberg {
    
    public static function register_block() {
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
        
        wp_localize_script('dbfb-gutenberg-block', 'dbfbBlock', ['forms' => $forms_list]);
        
        register_block_type('dbfb/form', [
            'editor_script' => 'dbfb-gutenberg-block',
            'render_callback' => [__CLASS__, 'render_block'],
            'attributes' => [
                'formId' => ['type' => 'string', 'default' => '']
            ]
        ]);
    }
    
    public static function render_block($attributes) {
        if (empty($attributes['formId'])) {
            return '<p>' . __('Seleziona un form dalle impostazioni del blocco.', 'db-form-builder') . '</p>';
        }
        return DB_Form_Builder::get_instance()->render_form_shortcode(['id' => $attributes['formId']]);
    }
}
