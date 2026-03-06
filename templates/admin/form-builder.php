<?php if (!defined('ABSPATH')) exit; ?>

<div class="dbfb-wrap" id="dbfb-form-builder">
    <div class="dbfb-header">
        <h1><?php echo $form_id ? __('Modifica Form', 'db-form-builder') : __('Nuovo Form', 'db-form-builder'); ?></h1>
        <a href="<?php echo admin_url('admin.php?page=dbfb-forms'); ?>" class="button">
            &larr; <?php _e('Tutti i Form', 'db-form-builder'); ?>
        </a>
    </div>
    
    <?php if (!empty($show_templates)): ?>
    <!-- Selezione Template -->
    <div class="dbfb-templates-section">
        <h2><?php _e('Scegli un template per iniziare', 'db-form-builder'); ?></h2>
        <p class="description"><?php _e('Seleziona un template predefinito o inizia da zero', 'db-form-builder'); ?></p>
        
        <div class="dbfb-templates-grid">
            <!-- Template vuoto -->
            <div class="dbfb-template-card" data-template="blank">
                <div class="dbfb-template-icon">
                    <span class="dashicons dashicons-plus-alt2"></span>
                </div>
                <h3><?php _e('Form Vuoto', 'db-form-builder'); ?></h3>
                <p><?php _e('Inizia da zero e costruisci il tuo form', 'db-form-builder'); ?></p>
                <button type="button" class="button dbfb-use-template" data-template="blank">
                    <?php _e('Inizia da zero', 'db-form-builder'); ?>
                </button>
            </div>
            
            <?php foreach ($templates as $template_id => $template): ?>
            <div class="dbfb-template-card" data-template="<?php echo esc_attr($template_id); ?>">
                <div class="dbfb-template-icon">
                    <span class="dashicons <?php echo esc_attr($template['icon']); ?>"></span>
                </div>
                <h3><?php echo esc_html($template['name']); ?></h3>
                <p><?php echo esc_html($template['description']); ?></p>
                <button type="button" class="button button-primary dbfb-use-template" data-template="<?php echo esc_attr($template_id); ?>">
                    <?php _e('Usa template', 'db-form-builder'); ?>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($show_templates)): ?>
    <div class="dbfb-builder-section" style="display: none;">
    <?php endif; ?>
    
    <input type="hidden" id="dbfb-form-id" value="<?php echo esc_attr($form_id); ?>">
    <input type="hidden" id="dbfb-fields-data" value="<?php echo esc_attr(json_encode($form_fields)); ?>">
    
    <div class="dbfb-builder">
        <!-- Sidebar con tipi campo -->
        <div class="dbfb-sidebar">
            <h3><?php _e('Campi disponibili', 'db-form-builder'); ?></h3>
            <div class="dbfb-field-types" id="dbfb-field-types">
                <div class="dbfb-field-type" data-type="text">
                    <span class="dashicons dashicons-editor-textcolor"></span>
                    <span><?php _e('Testo', 'db-form-builder'); ?></span>
                </div>
                <div class="dbfb-field-type" data-type="email">
                    <span class="dashicons dashicons-email"></span>
                    <span><?php _e('Email', 'db-form-builder'); ?></span>
                </div>
                <div class="dbfb-field-type" data-type="textarea">
                    <span class="dashicons dashicons-editor-paragraph"></span>
                    <span><?php _e('Area testo', 'db-form-builder'); ?></span>
                </div>
                <div class="dbfb-field-type" data-type="select">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                    <span><?php _e('Menu tendina', 'db-form-builder'); ?></span>
                </div>
                <div class="dbfb-field-type" data-type="checkbox">
                    <span class="dashicons dashicons-yes"></span>
                    <span><?php _e('Checkbox', 'db-form-builder'); ?></span>
                </div>
                <div class="dbfb-field-type" data-type="radio">
                    <span class="dashicons dashicons-marker"></span>
                    <span><?php _e('Scelta singola', 'db-form-builder'); ?></span>
                </div>
                <div class="dbfb-field-type" data-type="tel">
                    <span class="dashicons dashicons-phone"></span>
                    <span><?php _e('Telefono', 'db-form-builder'); ?></span>
                </div>
                <div class="dbfb-field-type" data-type="number">
                    <span class="dashicons dashicons-editor-ol"></span>
                    <span><?php _e('Numero', 'db-form-builder'); ?></span>
                </div>
                <div class="dbfb-field-type" data-type="date">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    <span><?php _e('Data', 'db-form-builder'); ?></span>
                </div>
                <div class="dbfb-field-type" data-type="url">
                    <span class="dashicons dashicons-admin-links"></span>
                    <span><?php _e('URL', 'db-form-builder'); ?></span>
                </div>
                
                <div class="dbfb-field-type-divider"><?php _e('Contenuti', 'db-form-builder'); ?></div>
                
                <div class="dbfb-field-type" data-type="html">
                    <span class="dashicons dashicons-text"></span>
                    <span><?php _e('Testo/HTML', 'db-form-builder'); ?></span>
                </div>
                <div class="dbfb-field-type" data-type="image">
                    <span class="dashicons dashicons-format-image"></span>
                    <span><?php _e('Immagine', 'db-form-builder'); ?></span>
                </div>
                <div class="dbfb-field-type" data-type="divider">
                    <span class="dashicons dashicons-minus"></span>
                    <span><?php _e('Separatore', 'db-form-builder'); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Canvas -->
        <div class="dbfb-main">
            <div class="dbfb-canvas">
                <div class="dbfb-canvas-header">
                    <input type="text" id="dbfb-form-title" placeholder="<?php _e('Titolo del form', 'db-form-builder'); ?>" 
                           value="<?php echo esc_attr($form ? $form->post_title : ''); ?>">
                </div>
                <div class="dbfb-fields-container" id="dbfb-fields-container">
                    <!-- I campi vengono inseriti qui via JS -->
                </div>
            </div>
            
            <!-- Impostazioni -->
            <div class="dbfb-settings-panel">
                <h3><?php _e('Impostazioni Form', 'db-form-builder'); ?></h3>
                <div class="dbfb-settings-content">
                    <div class="dbfb-settings-row">
                        <label for="dbfb-submit-text"><?php _e('Testo pulsante invio', 'db-form-builder'); ?></label>
                        <input type="text" id="dbfb-submit-text" value="<?php echo esc_attr($form_settings['submit_text']); ?>">
                    </div>
                    
                    <div class="dbfb-settings-row">
                        <label for="dbfb-success-message"><?php _e('Messaggio di successo', 'db-form-builder'); ?></label>
                        <textarea id="dbfb-success-message"><?php echo esc_textarea($form_settings['success_message']); ?></textarea>
                    </div>
                    
                    <?php 
                    $global_settings = DB_Form_Builder::get_global_settings();
                    $captcha_available = !empty($global_settings['recaptcha_site_key']) && !empty($global_settings['recaptcha_secret_key']);
                    ?>
                    
                    <div class="dbfb-settings-row">
                        <label>
                            <input type="checkbox" id="dbfb-enable-captcha" <?php checked(!empty($form_settings['enable_captcha'])); ?> <?php disabled(!$captcha_available); ?>>
                            <?php _e('Abilita protezione anti-spam (reCAPTCHA)', 'db-form-builder'); ?>
                        </label>
                        <?php if (!$captcha_available): ?>
                            <p class="description" style="color: #d63638;">
                                <?php printf(__('Configura prima le chiavi reCAPTCHA nelle <a href="%s">Impostazioni</a>', 'db-form-builder'), admin_url('admin.php?page=dbfb-settings')); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Email Conferma Utente -->
            <div class="dbfb-settings-panel">
                <h3><?php _e('Email Conferma Utente', 'db-form-builder'); ?></h3>
                <div class="dbfb-settings-content">
                    <div class="dbfb-settings-row">
                        <label>
                            <input type="checkbox" id="dbfb-send-confirmation" <?php checked(!empty($form_settings['send_confirmation'])); ?>>
                            <?php _e('Invia email di conferma all\'utente', 'db-form-builder'); ?>
                        </label>
                        <p class="description"><?php _e('Richiede un campo Email nel form', 'db-form-builder'); ?></p>
                    </div>
                    
                    <div class="dbfb-settings-row">
                        <label for="dbfb-confirmation-subject"><?php _e('Oggetto', 'db-form-builder'); ?></label>
                        <input type="text" id="dbfb-confirmation-subject" value="<?php echo esc_attr($form_settings['confirmation_subject']); ?>">
                    </div>
                    
                    <div class="dbfb-settings-row">
                        <label for="dbfb-confirmation-message"><?php _e('Messaggio', 'db-form-builder'); ?></label>
                        <textarea id="dbfb-confirmation-message" rows="6"><?php echo esc_textarea($form_settings['confirmation_message']); ?></textarea>
                        <p class="description"><?php _e('Placeholder disponibili: {nome}, {email}, {riepilogo_dati}, {form_titolo}, {sito}, {data}', 'db-form-builder'); ?></p>
                    </div>
                    
                    <?php if ($form_id): ?>
                    <div class="dbfb-settings-row dbfb-test-email-row">
                        <label><?php _e('Test Email', 'db-form-builder'); ?></label>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="email" id="dbfb-test-confirmation-email" placeholder="email@esempio.com" style="max-width: 250px;">
                            <button type="button" class="button dbfb-send-test-email" data-type="confirmation">
                                <?php _e('Invia Test', 'db-form-builder'); ?>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Email Notifica Admin -->
            <div class="dbfb-settings-panel">
                <h3><?php _e('Email Notifica Amministratore', 'db-form-builder'); ?></h3>
                <div class="dbfb-settings-content">
                    <div class="dbfb-settings-row">
                        <label>
                            <input type="checkbox" id="dbfb-send-admin-notification" <?php checked(!empty($form_settings['send_admin_notification'])); ?>>
                            <?php _e('Invia notifica all\'amministratore', 'db-form-builder'); ?>
                        </label>
                    </div>
                    
                    <div class="dbfb-settings-row">
                        <label for="dbfb-admin-email"><?php _e('Email destinatario', 'db-form-builder'); ?></label>
                        <input type="email" id="dbfb-admin-email" value="<?php echo esc_attr($form_settings['admin_email']); ?>">
                        <p class="description"><?php _e('Puoi inserire più email separate da virgola', 'db-form-builder'); ?></p>
                    </div>
                    
                    <div class="dbfb-settings-row">
                        <label for="dbfb-admin-subject"><?php _e('Oggetto', 'db-form-builder'); ?></label>
                        <input type="text" id="dbfb-admin-subject" value="<?php echo esc_attr($form_settings['admin_subject']); ?>">
                    </div>
                    
                    <div class="dbfb-settings-row">
                        <label for="dbfb-admin-message"><?php _e('Messaggio', 'db-form-builder'); ?></label>
                        <textarea id="dbfb-admin-message" rows="6"><?php echo esc_textarea($form_settings['admin_message']); ?></textarea>
                        <p class="description"><?php _e('Placeholder disponibili: {riepilogo_dati}, {form_titolo}, {ip}, {data}, {sito} e tutti i campi del form', 'db-form-builder'); ?></p>
                    </div>
                    
                    <?php if ($form_id): ?>
                    <div class="dbfb-settings-row dbfb-test-email-row">
                        <label><?php _e('Test Email', 'db-form-builder'); ?></label>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="email" id="dbfb-test-admin-email" placeholder="email@esempio.com" style="max-width: 250px;">
                            <button type="button" class="button dbfb-send-test-email" data-type="admin">
                                <?php _e('Invia Test', 'db-form-builder'); ?>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="dbfb-actions">
                <button type="button" id="dbfb-save-form" class="button button-primary button-large">
                    <?php _e('Salva Form', 'db-form-builder'); ?>
                </button>
                
                <?php if ($form_id): ?>
                    <span class="dbfb-shortcode" title="<?php _e('Clicca per copiare', 'db-form-builder'); ?>">
                        [dbfb_form id="<?php echo $form_id; ?>"]
                    </span>
                <?php else: ?>
                    <span class="dbfb-shortcode" style="opacity: 0.5;">
                        <?php _e('Lo shortcode sarà disponibile dopo il salvataggio', 'db-form-builder'); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php if (!empty($show_templates)): ?>
    </div><!-- .dbfb-builder-section -->
    <?php endif; ?>
</div>
