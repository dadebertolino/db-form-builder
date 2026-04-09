<?php if (!defined('ABSPATH')) exit; ?>

<div class="dbfb-wrap" id="dbfb-form-builder">
    <div class="dbfb-header">
        <h1><?php echo $form_id ? __('Modifica Form', 'db-form-builder') : __('Nuovo Form', 'db-form-builder'); ?></h1>
        <div>
            <?php if ($form_id): ?>
                <button type="button" id="dbfb-preview-btn" class="button" title="<?php _e('Anteprima form', 'db-form-builder'); ?>">
                    <span class="dashicons dashicons-visibility" style="margin-top:3px;"></span> <?php _e('Anteprima', 'db-form-builder'); ?>
                </button>
            <?php endif; ?>
            <a href="<?php echo admin_url('admin.php?page=dbfb-forms'); ?>" class="button">
                &larr; <?php _e('Tutti i Form', 'db-form-builder'); ?>
            </a>
        </div>
    </div>
    
    <?php if (!empty($show_templates)): ?>
    <!-- Selezione Template -->
    <div class="dbfb-templates-section">
        <h2><?php _e('Scegli un template per iniziare', 'db-form-builder'); ?></h2>
        <p class="description"><?php _e('Seleziona un template predefinito o inizia da zero', 'db-form-builder'); ?></p>
        
        <div class="dbfb-templates-grid">
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
            
            <!-- Impostazioni Form -->
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
            
            <!-- Sicurezza & Privacy -->
            <div class="dbfb-settings-panel">
                <h3><?php _e('Sicurezza & Privacy', 'db-form-builder'); ?></h3>
                <div class="dbfb-settings-content">
                    <div class="dbfb-settings-row">
                        <label>
                            <input type="checkbox" id="dbfb-enable-honeypot" <?php checked(!empty($form_settings['enable_honeypot'])); ?>>
                            <?php _e('Honeypot anti-spam (invisibile, alternativa a reCAPTCHA)', 'db-form-builder'); ?>
                        </label>
                        <p class="description"><?php _e('Aggiunge un campo nascosto che solo i bot compilano. Nessun impatto visivo.', 'db-form-builder'); ?></p>
                    </div>
                    
                    <div class="dbfb-settings-row">
                        <label>
                            <input type="checkbox" id="dbfb-enable-gdpr" <?php checked(!empty($form_settings['enable_gdpr'])); ?>>
                            <?php _e('Checkbox accettazione privacy / GDPR', 'db-form-builder'); ?>
                        </label>
                    </div>
                    
                    <div class="dbfb-settings-row" id="dbfb-gdpr-options" style="<?php echo empty($form_settings['enable_gdpr']) ? 'display:none;' : ''; ?> margin-left: 25px;">
                        <div style="margin-bottom: 10px;">
                            <label for="dbfb-gdpr-text"><?php _e('Testo del consenso', 'db-form-builder'); ?></label>
                            <input type="text" id="dbfb-gdpr-text" value="<?php echo esc_attr($form_settings['gdpr_text']); ?>" style="width:100%; max-width:500px;">
                        </div>
                        <div>
                            <label for="dbfb-gdpr-link"><?php _e('Link alla Privacy Policy (opzionale)', 'db-form-builder'); ?></label>
                            <input type="url" id="dbfb-gdpr-link" value="<?php echo esc_attr($form_settings['gdpr_link']); ?>" placeholder="https://..." style="width:100%; max-width:500px;">
                        </div>
                    </div>
                    
                    <div class="dbfb-settings-row">
                        <label>
                            <input type="checkbox" id="dbfb-rate-limit-enabled" <?php checked(!empty($form_settings['rate_limit_enabled'])); ?>>
                            <?php _e('Limita invii per IP', 'db-form-builder'); ?>
                        </label>
                    </div>
                    
                    <div class="dbfb-settings-row" id="dbfb-rate-limit-options" style="<?php echo empty($form_settings['rate_limit_enabled']) ? 'display:none;' : ''; ?> margin-left: 25px;">
                        <div style="display: flex; gap: 15px; align-items: center;">
                            <div>
                                <label for="dbfb-rate-limit-max"><?php _e('Max invii', 'db-form-builder'); ?></label>
                                <input type="number" id="dbfb-rate-limit-max" value="<?php echo esc_attr($form_settings['rate_limit_max']); ?>" min="1" max="100" style="width:80px;">
                            </div>
                            <div>
                                <label for="dbfb-rate-limit-window"><?php _e('In minuti', 'db-form-builder'); ?></label>
                                <input type="number" id="dbfb-rate-limit-window" value="<?php echo esc_attr($form_settings['rate_limit_window']); ?>" min="1" max="1440" style="width:80px;">
                            </div>
                        </div>
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
                        <input type="text" id="dbfb-admin-email" value="<?php echo esc_attr($form_settings['admin_email']); ?>">
                        <p class="description"><?php _e('Puoi inserire più email separate da virgola (es: admin@sito.it, staff@sito.it)', 'db-form-builder'); ?></p>
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

<!-- Modale Anteprima -->
<?php if ($form_id): ?>
<div id="dbfb-preview-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:100000;">
    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:8px; padding:30px; max-width:700px; width:90%; max-height:85vh; overflow-y:auto; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #eee; padding-bottom:10px;">
            <h3 style="margin:0;">
                <span class="dashicons dashicons-visibility" style="margin-right:5px;"></span>
                <?php _e('Anteprima Form', 'db-form-builder'); ?>
            </h3>
            <button type="button" id="dbfb-preview-close" style="background:none; border:none; font-size:24px; cursor:pointer; color:#666;">&times;</button>
        </div>
        <div id="dbfb-preview-content" style="border:1px solid #eee; border-radius:4px; padding:20px;"></div>
        <p style="margin:15px 0 0; color:#999; font-size:12px; text-align:center;">
            <?php _e('Questa è un\'anteprima. Il form non è funzionante in questa modalità.', 'db-form-builder'); ?>
        </p>
    </div>
</div>
<?php endif; ?>
