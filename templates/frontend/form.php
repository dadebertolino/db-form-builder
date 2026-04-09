<?php if (!defined('ABSPATH')) exit; 

$global_settings = DB_Form_Builder::get_global_settings();
$enable_captcha = !empty($form_settings['enable_captcha']) && !empty($global_settings['recaptcha_site_key']);
$recaptcha_version = $global_settings['recaptcha_version'] ?? 'v2';
$enable_honeypot = !empty($form_settings['enable_honeypot']);
$enable_gdpr = !empty($form_settings['enable_gdpr']);
?>

<form class="dbfb-form" data-form-id="<?php echo esc_attr($form_id); ?>" <?php if ($enable_captcha && $recaptcha_version === 'v3'): ?>data-recaptcha-v3="1"<?php endif; ?>>
    
    <?php // Honeypot: campo invisibile che solo i bot compilano ?>
    <?php if ($enable_honeypot): ?>
    <div style="position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;width:0;overflow:hidden;" aria-hidden="true" tabindex="-1">
        <label for="dbfb_website_url_<?php echo $form_id; ?>">Website</label>
        <input type="text" name="dbfb_website_url" id="dbfb_website_url_<?php echo $form_id; ?>" value="" autocomplete="off" tabindex="-1">
    </div>
    <input type="hidden" name="dbfb_timestamp" value="<?php echo time(); ?>">
    <?php endif; ?>
    
    <?php foreach ($form_fields as $field): ?>
        <?php 
        // Campi contenuto statico (non input)
        if ($field['type'] === 'divider'): ?>
            <div class="dbfb-divider">
                <hr>
            </div>
        <?php elseif ($field['type'] === 'html'): ?>
            <div class="dbfb-html-content">
                <?php echo wp_kses_post($field['content'] ?? ''); ?>
            </div>
        <?php elseif ($field['type'] === 'image'): ?>
            <div class="dbfb-image-content">
                <?php if (!empty($field['image_url'])): ?>
                    <img src="<?php echo esc_url($field['image_url']); ?>" 
                         alt="<?php echo esc_attr($field['image_alt'] ?? ''); ?>">
                <?php endif; ?>
            </div>
        <?php else: ?>
        <!-- Campi input -->
        <div class="dbfb-form-group">
            <label for="dbfb-<?php echo esc_attr($field['id']); ?>">
                <?php echo esc_html($field['label']); ?>
                <?php if (!empty($field['required'])): ?>
                    <span class="required">*</span>
                <?php endif; ?>
            </label>
            
            <?php switch ($field['type']):
                case 'text':
                case 'email':
                case 'tel':
                case 'number':
                case 'url':
                case 'date': ?>
                    <input type="<?php echo esc_attr($field['type']); ?>" 
                           id="dbfb-<?php echo esc_attr($field['id']); ?>"
                           name="<?php echo esc_attr($field['id']); ?>"
                           placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"
                           <?php echo !empty($field['required']) ? 'required' : ''; ?>>
                    <?php break;
                
                case 'textarea': ?>
                    <textarea id="dbfb-<?php echo esc_attr($field['id']); ?>"
                              name="<?php echo esc_attr($field['id']); ?>"
                              placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"
                              <?php echo !empty($field['required']) ? 'required' : ''; ?>></textarea>
                    <?php break;
                
                case 'select': ?>
                    <select id="dbfb-<?php echo esc_attr($field['id']); ?>"
                            name="<?php echo esc_attr($field['id']); ?>"
                            <?php echo !empty($field['required']) ? 'required' : ''; ?>>
                        <option value=""><?php _e('Seleziona...', 'db-form-builder'); ?></option>
                        <?php foreach ($field['options'] as $option): ?>
                            <option value="<?php echo esc_attr($option); ?>">
                                <?php echo esc_html($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php break;
                
                case 'checkbox': ?>
                    <div class="dbfb-checkbox-group">
                        <?php foreach ($field['options'] as $i => $option): ?>
                            <div class="dbfb-checkbox-item">
                                <input type="checkbox" 
                                       id="dbfb-<?php echo esc_attr($field['id']); ?>-<?php echo $i; ?>"
                                       name="<?php echo esc_attr($field['id']); ?>"
                                       value="<?php echo esc_attr($option); ?>">
                                <label for="dbfb-<?php echo esc_attr($field['id']); ?>-<?php echo $i; ?>">
                                    <?php echo esc_html($option); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php break;
                
                case 'radio': ?>
                    <div class="dbfb-radio-group">
                        <?php foreach ($field['options'] as $i => $option): ?>
                            <div class="dbfb-radio-item">
                                <input type="radio" 
                                       id="dbfb-<?php echo esc_attr($field['id']); ?>-<?php echo $i; ?>"
                                       name="<?php echo esc_attr($field['id']); ?>"
                                       value="<?php echo esc_attr($option); ?>"
                                       <?php echo !empty($field['required']) ? 'required' : ''; ?>>
                                <label for="dbfb-<?php echo esc_attr($field['id']); ?>-<?php echo $i; ?>">
                                    <?php echo esc_html($option); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php break;
                
            endswitch; ?>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
    
    <?php if ($enable_captcha && $recaptcha_version === 'v2'): ?>
    <div class="dbfb-form-group dbfb-recaptcha-container">
        <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($global_settings['recaptcha_site_key']); ?>"></div>
    </div>
    <?php endif; ?>
    
    <?php // GDPR Checkbox ?>
    <?php if ($enable_gdpr): ?>
    <div class="dbfb-form-group dbfb-gdpr-group">
        <div class="dbfb-checkbox-item">
            <input type="checkbox" id="dbfb-gdpr-<?php echo $form_id; ?>" name="dbfb_gdpr_consent" value="1" required>
            <label for="dbfb-gdpr-<?php echo $form_id; ?>">
                <?php 
                $gdpr_text = $form_settings['gdpr_text'] ?? __('Acconsento al trattamento dei dati personali', 'db-form-builder');
                $gdpr_link = $form_settings['gdpr_link'] ?? '';
                echo esc_html($gdpr_text);
                if ($gdpr_link): ?>
                    <a href="<?php echo esc_url($gdpr_link); ?>" target="_blank" rel="noopener"><?php _e('Leggi la Privacy Policy', 'db-form-builder'); ?></a>
                <?php endif; ?>
                <span class="required">*</span>
            </label>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="dbfb-form-group">
        <button type="submit" class="dbfb-submit">
            <?php echo esc_html($form_settings['submit_text'] ?? __('Invia', 'db-form-builder')); ?>
        </button>
    </div>
    
    <?php if ($enable_captcha): ?>
    <div class="dbfb-recaptcha-notice">
        <small>
            <?php _e('Questo sito è protetto da reCAPTCHA e si applicano la', 'db-form-builder'); ?>
            <a href="https://policies.google.com/privacy" target="_blank"><?php _e('Privacy Policy', 'db-form-builder'); ?></a>
            <?php _e('e i', 'db-form-builder'); ?>
            <a href="https://policies.google.com/terms" target="_blank"><?php _e('Termini di Servizio', 'db-form-builder'); ?></a>
            <?php _e('di Google.', 'db-form-builder'); ?>
        </small>
    </div>
    <?php endif; ?>
</form>
