<?php if (!defined('ABSPATH')) exit; 

$global_settings = DB_Form_Builder::get_global_settings();
$enable_captcha = !empty($form_settings['enable_captcha']) && !empty($global_settings['recaptcha_site_key']);
$recaptcha_version = $global_settings['recaptcha_version'] ?? 'v2';
$enable_honeypot = !empty($form_settings['enable_honeypot']);
$enable_gdpr = !empty($form_settings['enable_gdpr']);
$form_title = get_the_title($form_id);
?>

<?php
$has_file_fields = false;
foreach ($form_fields as $f) { if ($f['type'] === 'file') { $has_file_fields = true; break; } }
?>

<form class="dbfb-form" 
      data-form-id="<?php echo esc_attr($form_id); ?>" 
      <?php if ($enable_captcha && $recaptcha_version === 'v3'): ?>data-recaptcha-v3="1"<?php endif; ?>
      <?php if ($has_file_fields): ?>enctype="multipart/form-data"<?php endif; ?>
      role="form"
      aria-label="<?php echo esc_attr($form_title); ?>"
      novalidate>
    
    <?php // Live region per messaggi - screen reader annuncerà i cambiamenti ?>
    <div class="dbfb-messages-region" aria-live="assertive" aria-atomic="true" role="status"></div>
    
    <?php // Honeypot ?>
    <?php if ($enable_honeypot): ?>
    <div style="position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;width:0;overflow:hidden;" aria-hidden="true" tabindex="-1">
        <label for="dbfb_website_url_<?php echo $form_id; ?>">Website</label>
        <input type="text" name="dbfb_website_url" id="dbfb_website_url_<?php echo $form_id; ?>" value="" autocomplete="off" tabindex="-1">
    </div>
    <input type="hidden" name="dbfb_timestamp" value="<?php echo time(); ?>">
    <?php endif; ?>
    
    <?php 
    // Multi-step: conta i pagebreak per determinare il numero di step
    $has_multistep = false;
    $total_steps = 1;
    foreach ($form_fields as $f) {
        if ($f['type'] === 'pagebreak') { $has_multistep = true; $total_steps++; }
    }
    $current_step = 0;
    ?>
    
    <?php if ($has_multistep): ?>
    <div class="dbfb-multistep" data-total-steps="<?php echo $total_steps; ?>">
        <div class="dbfb-progress" role="progressbar" aria-valuenow="1" aria-valuemin="1" aria-valuemax="<?php echo $total_steps; ?>" aria-label="<?php _e('Progresso form', 'db-form-builder'); ?>">
            <div class="dbfb-progress-bar" style="width: <?php echo round(100 / $total_steps); ?>%;">
                <span class="dbfb-progress-text"><?php printf(__('Passo %d di %d', 'db-form-builder'), 1, $total_steps); ?></span>
            </div>
        </div>
        <div class="dbfb-step dbfb-step-active" data-step="0">
    <?php endif; ?>
    
    <?php foreach ($form_fields as $field): 
        $field_id = 'dbfb-' . esc_attr($field['id']);
        $error_id = $field_id . '-error';
        $is_required = !empty($field['required']);
        $has_conditions = !empty($field['conditions']['enabled']) && !empty($field['conditions']['rules']);
        $conditions_json = $has_conditions ? esc_attr(json_encode($field['conditions'])) : '';
        $initial_hidden = $has_conditions && $field['conditions']['action'] === 'show';
    ?>
        <?php 
        if ($field['type'] === 'pagebreak'): ?>
            <?php if ($has_multistep): $current_step++; ?>
            </div><!-- /.dbfb-step -->
            <div class="dbfb-step" data-step="<?php echo $current_step; ?>" style="display:none;">
            <?php endif; ?>
        <?php elseif ($field['type'] === 'divider'): ?>
            <div class="dbfb-divider<?php echo $has_conditions ? ' dbfb-conditional' : ''; ?>" role="separator" aria-hidden="true"
                 <?php if ($has_conditions): ?>data-conditions="<?php echo $conditions_json; ?>" data-field-id="<?php echo esc_attr($field['id']); ?>"<?php endif; ?>
                 <?php if ($initial_hidden): ?>style="display:none;"<?php endif; ?>>
                <hr>
            </div>
        <?php elseif ($field['type'] === 'html'): ?>
            <div class="dbfb-html-content<?php echo $has_conditions ? ' dbfb-conditional' : ''; ?>"
                 <?php if ($has_conditions): ?>data-conditions="<?php echo $conditions_json; ?>" data-field-id="<?php echo esc_attr($field['id']); ?>"<?php endif; ?>
                 <?php if ($initial_hidden): ?>style="display:none;"<?php endif; ?>>
                <?php echo wp_kses_post($field['content'] ?? ''); ?>
            </div>
        <?php elseif ($field['type'] === 'image'): ?>
            <div class="dbfb-image-content<?php echo $has_conditions ? ' dbfb-conditional' : ''; ?>"
                 <?php if ($has_conditions): ?>data-conditions="<?php echo $conditions_json; ?>" data-field-id="<?php echo esc_attr($field['id']); ?>"<?php endif; ?>
                 <?php if ($initial_hidden): ?>style="display:none;"<?php endif; ?>>
                <?php if (!empty($field['image_url'])): ?>
                    <img src="<?php echo esc_url($field['image_url']); ?>" 
                         alt="<?php echo esc_attr($field['image_alt'] ?? ''); ?>"
                         <?php if (empty($field['image_alt'])): ?>role="presentation"<?php endif; ?>>
                <?php endif; ?>
            </div>
        <?php else: ?>
        
        <div class="dbfb-form-group<?php echo $has_conditions ? ' dbfb-conditional' : ''; ?>" data-field-id="<?php echo esc_attr($field['id']); ?>"
             <?php if ($has_conditions): ?>data-conditions="<?php echo $conditions_json; ?>"<?php endif; ?>
             <?php if ($initial_hidden): ?>style="display:none;" aria-hidden="true"<?php endif; ?>>
            
            <?php if (in_array($field['type'], ['checkbox', 'radio'])): ?>
                <?php // WCAG 1.3.1 + 4.1.2: fieldset/legend per gruppi ?>
                <fieldset>
                    <legend>
                        <?php echo esc_html($field['label']); ?>
                        <?php if ($is_required): ?>
                            <span class="required" aria-hidden="true">*</span>
                            <span class="screen-reader-text"><?php _e('(obbligatorio)', 'db-form-builder'); ?></span>
                        <?php endif; ?>
                    </legend>
                    
                    <?php if ($field['type'] === 'checkbox'): ?>
                    <div class="dbfb-checkbox-group" role="group">
                        <?php foreach ($field['options'] as $i => $option): ?>
                            <div class="dbfb-checkbox-item">
                                <input type="checkbox" 
                                       id="<?php echo $field_id; ?>-<?php echo $i; ?>"
                                       name="<?php echo esc_attr($field['id']); ?>"
                                       value="<?php echo esc_attr($option); ?>"
                                       aria-describedby="<?php echo $error_id; ?>">
                                <label for="<?php echo $field_id; ?>-<?php echo $i; ?>">
                                    <?php echo esc_html($option); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="dbfb-radio-group" role="radiogroup">
                        <?php foreach ($field['options'] as $i => $option): ?>
                            <div class="dbfb-radio-item">
                                <input type="radio" 
                                       id="<?php echo $field_id; ?>-<?php echo $i; ?>"
                                       name="<?php echo esc_attr($field['id']); ?>"
                                       value="<?php echo esc_attr($option); ?>"
                                       <?php echo $is_required ? 'required aria-required="true"' : ''; ?>
                                       aria-describedby="<?php echo $error_id; ?>">
                                <label for="<?php echo $field_id; ?>-<?php echo $i; ?>">
                                    <?php echo esc_html($option); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div id="<?php echo $error_id; ?>" class="dbfb-field-error" role="alert" aria-live="polite"></div>
                </fieldset>
            
            <?php else: ?>
                <label for="<?php echo $field_id; ?>">
                    <?php echo esc_html($field['label']); ?>
                    <?php if ($is_required): ?>
                        <span class="required" aria-hidden="true">*</span>
                        <span class="screen-reader-text"><?php _e('(obbligatorio)', 'db-form-builder'); ?></span>
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
                               id="<?php echo $field_id; ?>"
                               name="<?php echo esc_attr($field['id']); ?>"
                               placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"
                               <?php echo $is_required ? 'required aria-required="true"' : ''; ?>
                               aria-invalid="false"
                               aria-describedby="<?php echo $error_id; ?>"
                               <?php if ($field['type'] === 'email'): ?>autocomplete="email"<?php endif; ?>
                               <?php if ($field['type'] === 'tel'): ?>autocomplete="tel"<?php endif; ?>
                               <?php if ($field['type'] === 'url'): ?>autocomplete="url"<?php endif; ?>>
                        <?php break;
                    
                    case 'textarea': ?>
                        <textarea id="<?php echo $field_id; ?>"
                                  name="<?php echo esc_attr($field['id']); ?>"
                                  placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"
                                  <?php echo $is_required ? 'required aria-required="true"' : ''; ?>
                                  aria-invalid="false"
                                  aria-describedby="<?php echo $error_id; ?>"></textarea>
                        <?php break;
                    
                    case 'select': ?>
                        <select id="<?php echo $field_id; ?>"
                                name="<?php echo esc_attr($field['id']); ?>"
                                <?php echo $is_required ? 'required aria-required="true"' : ''; ?>
                                aria-invalid="false"
                                aria-describedby="<?php echo $error_id; ?>">
                            <option value=""><?php _e('Seleziona...', 'db-form-builder'); ?></option>
                            <?php foreach ($field['options'] as $option): ?>
                                <option value="<?php echo esc_attr($option); ?>">
                                    <?php echo esc_html($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php break;
                    
                    case 'file':
                        $file_ext = $field['file_extensions'] ?? 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,zip';
                        $file_max = intval($field['file_max_size'] ?? 5);
                        $file_multi = !empty($field['file_multiple']);
                        $accept_attr = '.' . implode(',.', array_map('trim', explode(',', $file_ext)));
                        $desc_id = $field_id . '-desc';
                    ?>
                        <div class="dbfb-file-dropzone" 
                             data-field-id="<?php echo esc_attr($field['id']); ?>"
                             data-max-size="<?php echo $file_max; ?>"
                             data-extensions="<?php echo esc_attr($file_ext); ?>"
                             role="button"
                             tabindex="0"
                             aria-describedby="<?php echo $desc_id; ?> <?php echo $error_id; ?>">
                            <div class="dbfb-file-dropzone-inner">
                                <span class="dbfb-file-icon" aria-hidden="true">📎</span>
                                <span class="dbfb-file-text">
                                    <?php _e('Trascina qui i file o clicca per selezionare', 'db-form-builder'); ?>
                                </span>
                            </div>
                            <input type="file" 
                                   id="<?php echo $field_id; ?>"
                                   name="dbfb_file_<?php echo esc_attr($field['id']); ?><?php echo $file_multi ? '[]' : ''; ?>"
                                   accept="<?php echo esc_attr($accept_attr); ?>"
                                   <?php echo $file_multi ? 'multiple' : ''; ?>
                                   <?php echo $is_required ? 'aria-required="true"' : ''; ?>
                                   aria-invalid="false"
                                   aria-describedby="<?php echo $desc_id; ?> <?php echo $error_id; ?>"
                                   class="dbfb-file-input">
                            <div class="dbfb-file-list" aria-live="polite"></div>
                        </div>
                        <div id="<?php echo $desc_id; ?>" class="dbfb-file-info">
                            <?php printf(
                                __('Formati: %s — Max %d MB%s', 'db-form-builder'),
                                strtoupper(str_replace(',', ', ', $file_ext)),
                                $file_max,
                                $file_multi ? ' — ' . __('File multipli', 'db-form-builder') : ''
                            ); ?>
                        </div>
                        <?php break;
                    
                endswitch; ?>
                
                <div id="<?php echo $error_id; ?>" class="dbfb-field-error" role="alert" aria-live="polite"></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
    
    <?php if ($has_multistep): ?>
    </div><!-- /.dbfb-step (last) -->
    </div><!-- /.dbfb-multistep -->
    
    <div class="dbfb-step-nav">
        <button type="button" class="dbfb-step-prev dbfb-submit" style="display:none;" aria-label="<?php _e('Passo precedente', 'db-form-builder'); ?>">
            &larr; <?php _e('Indietro', 'db-form-builder'); ?>
        </button>
        <button type="button" class="dbfb-step-next dbfb-submit" aria-label="<?php _e('Passo successivo', 'db-form-builder'); ?>">
            <?php _e('Avanti', 'db-form-builder'); ?> &rarr;
        </button>
    </div>
    <?php endif; ?>
    
    <?php if ($enable_captcha && $recaptcha_version === 'v2'): ?>
    <div class="dbfb-form-group dbfb-recaptcha-container">
        <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($global_settings['recaptcha_site_key']); ?>"></div>
    </div>
    <?php endif; ?>
    
    <?php if ($enable_gdpr): ?>
    <div class="dbfb-form-group dbfb-gdpr-group">
        <div class="dbfb-checkbox-item">
            <input type="checkbox" 
                   id="dbfb-gdpr-<?php echo $form_id; ?>" 
                   name="dbfb_gdpr_consent" 
                   value="1" 
                   required 
                   aria-required="true"
                   aria-invalid="false"
                   aria-describedby="dbfb-gdpr-error-<?php echo $form_id; ?>">
            <label for="dbfb-gdpr-<?php echo $form_id; ?>">
                <?php 
                $gdpr_text = $form_settings['gdpr_text'] ?? __('Acconsento al trattamento dei dati personali', 'db-form-builder');
                $gdpr_link = $form_settings['gdpr_link'] ?? '';
                echo esc_html($gdpr_text);
                if ($gdpr_link): ?>
                    <a href="<?php echo esc_url($gdpr_link); ?>" target="_blank" rel="noopener">
                        <?php _e('Leggi la Privacy Policy', 'db-form-builder'); ?>
                        <span class="screen-reader-text"><?php _e('(si apre in una nuova finestra)', 'db-form-builder'); ?></span>
                    </a>
                <?php endif; ?>
                <span class="required" aria-hidden="true">*</span>
                <span class="screen-reader-text"><?php _e('(obbligatorio)', 'db-form-builder'); ?></span>
            </label>
        </div>
        <div id="dbfb-gdpr-error-<?php echo $form_id; ?>" class="dbfb-field-error" role="alert" aria-live="polite"></div>
    </div>
    <?php endif; ?>
    
    <div class="dbfb-form-group">
        <input type="hidden" name="dbfb_hidden_fields" value="[]">
        <button type="submit" class="dbfb-submit" aria-live="polite">
            <span class="dbfb-submit-text"><?php echo esc_html($form_settings['submit_text'] ?? __('Invia', 'db-form-builder')); ?></span>
            <span class="dbfb-submit-loading" aria-hidden="true"><?php _e('Invio in corso...', 'db-form-builder'); ?></span>
        </button>
    </div>
    
    <?php if ($enable_captcha): ?>
    <div class="dbfb-recaptcha-notice">
        <small>
            <?php _e('Questo sito è protetto da reCAPTCHA e si applicano la', 'db-form-builder'); ?>
            <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">
                <?php _e('Privacy Policy', 'db-form-builder'); ?><span class="screen-reader-text"> <?php _e('(si apre in una nuova finestra)', 'db-form-builder'); ?></span>
            </a>
            <?php _e('e i', 'db-form-builder'); ?>
            <a href="https://policies.google.com/terms" target="_blank" rel="noopener">
                <?php _e('Termini di Servizio', 'db-form-builder'); ?><span class="screen-reader-text"> <?php _e('(si apre in una nuova finestra)', 'db-form-builder'); ?></span>
            </a>
            <?php _e('di Google.', 'db-form-builder'); ?>
        </small>
    </div>
    <?php endif; ?>
</form>
