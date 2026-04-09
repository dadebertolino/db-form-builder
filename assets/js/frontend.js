(function($) {
    'use strict';

    /**
     * WCAG 2.1 AA compliant form handler
     * - Focus management on errors (2.4.3)
     * - aria-invalid toggle (4.1.2)
     * - Error messages linked via aria-describedby (1.3.1)
     * - Live region announcements (4.1.3)
     * - Reduced motion support (2.3.3)
     */
    
    // Check prefers-reduced-motion
    var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    
    function scrollToElement($el) {
        if (prefersReducedMotion) {
            // No animation for reduced motion
            window.scrollTo(0, $el.offset().top - 50);
        } else {
            $('html, body').animate({ scrollTop: $el.offset().top - 50 }, 300);
        }
    }
    
    function clearErrors($form) {
        $form.find('.dbfb-form-group').removeClass('error');
        $form.find('.dbfb-field-error').empty();
        $form.find('[aria-invalid="true"]').attr('aria-invalid', 'false');
        $form.find('.dbfb-messages-region').empty();
    }
    
    function showFieldError($form, fieldId, message) {
        var $group = $form.find('[data-field-id="' + fieldId + '"]');
        if (!$group.length) return;
        
        $group.addClass('error');
        
        // Set aria-invalid on the field
        var $input = $group.find('input, textarea, select').first();
        if ($input.length) {
            $input.attr('aria-invalid', 'true');
        }
        
        // Fill error container (linked via aria-describedby)
        var $errorDiv = $group.find('.dbfb-field-error');
        if ($errorDiv.length) {
            $errorDiv.text(message);
        }
    }
    
    function showMessage($form, type, message) {
        // Use the live region so screen readers announce immediately
        var $region = $form.find('.dbfb-messages-region');
        $region.html('<div class="dbfb-message ' + type + '" role="alert">' + message + '</div>');
        
        // Focus the message for keyboard users (WCAG 2.4.3)
        var $msg = $region.find('.dbfb-message');
        $msg.attr('tabindex', '-1').trigger('focus');
        
        scrollToElement($region);
    }
    
    function setLoadingState($form, loading) {
        var $btn = $form.find('.dbfb-submit');
        
        if (loading) {
            $form.addClass('loading');
            $btn.prop('disabled', true).attr('aria-busy', 'true');
            $btn.find('.dbfb-submit-text').attr('aria-hidden', 'true');
            $btn.find('.dbfb-submit-loading').attr('aria-hidden', 'false');
        } else {
            $form.removeClass('loading');
            $btn.prop('disabled', false).removeAttr('aria-busy');
            $btn.find('.dbfb-submit-text').attr('aria-hidden', 'false');
            $btn.find('.dbfb-submit-loading').attr('aria-hidden', 'true');
        }
    }

    $(document).on('submit', '.dbfb-form', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var formId = $form.data('form-id');
        var isV3 = $form.data('recaptcha-v3');
        var hasV2Widget = $form.find('.g-recaptcha').length > 0;
        
        // Collect data
        var formData = {};
        $form.find('[name]').each(function() {
            var $field = $(this);
            var name = $field.attr('name');
            var type = $field.attr('type');
            
            if (name === 'dbfb_website_url' || name === 'dbfb_timestamp') return;
            
            if (type === 'checkbox') {
                if (name === 'dbfb_gdpr_consent') {
                    if ($field.is(':checked')) {
                        formData[name] = $field.val();
                    }
                } else {
                    if (!formData[name]) formData[name] = [];
                    if ($field.is(':checked')) {
                        formData[name].push($field.val());
                    }
                }
            } else if (type === 'radio') {
                if ($field.is(':checked')) {
                    formData[name] = $field.val();
                }
            } else {
                formData[name] = $field.val();
            }
        });
        
        // Clear previous errors
        clearErrors($form);
        
        // Honeypot data
        var honeypotValue = $form.find('[name="dbfb_website_url"]').val() || '';
        var timestamp = $form.find('[name="dbfb_timestamp"]').val() || '';
        
        var submitForm = function(recaptchaToken) {
            setLoadingState($form, true);
            
            $.post(dbfb.ajax_url, {
                action: 'dbfb_submit_form',
                nonce: dbfb.nonce,
                form_id: formId,
                data: JSON.stringify(formData),
                recaptcha_token: recaptchaToken || '',
                dbfb_website_url: honeypotValue,
                dbfb_timestamp: timestamp
            })
            .done(function(response) {
                if (response.success) {
                    showMessage($form, 'success', response.data.message);
                    $form[0].reset();
                    
                    if (typeof grecaptcha !== 'undefined' && hasV2Widget) {
                        grecaptcha.reset();
                    }
                } else {
                    showMessage($form, 'error', response.data.message);
                    
                    // If server returns a field-specific error, highlight it
                    if (response.data.field_id) {
                        showFieldError($form, response.data.field_id, response.data.message);
                        // Focus the first invalid field
                        var $firstError = $form.find('[aria-invalid="true"]').first();
                        if ($firstError.length) $firstError.trigger('focus');
                    }
                }
            })
            .fail(function(xhr, status, error) {
                console.error('DBFB Error:', status, error);
                showMessage($form, 'error', 'Si è verificato un errore. Riprova.');
            })
            .always(function() {
                setLoadingState($form, false);
            });
        };
        
        // Handle reCAPTCHA
        if (typeof grecaptcha !== 'undefined' && dbfb.recaptcha_site_key) {
            if (isV3) {
                grecaptcha.ready(function() {
                    grecaptcha.execute(dbfb.recaptcha_site_key, {action: 'submit'}).then(function(token) {
                        submitForm(token);
                    }).catch(function(err) {
                        console.error('reCAPTCHA v3 error:', err);
                        showMessage($form, 'error', 'Errore reCAPTCHA. Ricarica la pagina e riprova.');
                    });
                });
            } else if (hasV2Widget) {
                var response = grecaptcha.getResponse();
                if (!response) {
                    showMessage($form, 'error', 'Completa la verifica "Non sono un robot"');
                    return;
                }
                submitForm(response);
            } else {
                submitForm('');
            }
        } else {
            submitForm('');
        }
    });
    
    // Real-time validation: clear error on input (WCAG 3.3.1)
    $(document).on('input change', '.dbfb-form input, .dbfb-form textarea, .dbfb-form select', function() {
        var $field = $(this);
        var $group = $field.closest('.dbfb-form-group');
        
        if ($group.hasClass('error')) {
            $group.removeClass('error');
            $field.attr('aria-invalid', 'false');
            $group.find('.dbfb-field-error').empty();
        }
    });

})(jQuery);
