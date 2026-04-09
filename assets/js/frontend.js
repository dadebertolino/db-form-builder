(function($) {
    'use strict';

    $(document).on('submit', '.dbfb-form', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const formId = $form.data('form-id');
        const isV3 = $form.data('recaptcha-v3');
        const hasV2Widget = $form.find('.g-recaptcha').length > 0;
        
        // Raccogli dati
        const formData = {};
        $form.find('[name]').each(function() {
            const $field = $(this);
            const name = $field.attr('name');
            const type = $field.attr('type');
            
            // Skip honeypot e timestamp (inviati separatamente)
            if (name === 'dbfb_website_url' || name === 'dbfb_timestamp') return;
            
            if (type === 'checkbox') {
                if (name === 'dbfb_gdpr_consent') {
                    // GDPR: salva come valore singolo
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
        
        // Reset errori
        $form.find('.dbfb-form-group').removeClass('error');
        $form.find('.dbfb-field-error').remove();
        $form.find('.dbfb-message').remove();
        
        // Honeypot data
        const honeypotValue = $form.find('[name="dbfb_website_url"]').val() || '';
        const timestamp = $form.find('[name="dbfb_timestamp"]').val() || '';
        
        // Funzione per inviare il form
        const submitForm = function(recaptchaToken) {
            $form.addClass('loading');
            $form.find('.dbfb-submit').prop('disabled', true);
            
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
                    $form.prepend('<div class="dbfb-message success">' + response.data.message + '</div>');
                    $form[0].reset();
                    
                    if (typeof grecaptcha !== 'undefined' && hasV2Widget) {
                        grecaptcha.reset();
                    }
                    
                    $('html, body').animate({
                        scrollTop: $form.offset().top - 50
                    }, 300);
                } else {
                    $form.prepend('<div class="dbfb-message error">' + response.data.message + '</div>');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('DBFB Error:', status, error);
                $form.prepend('<div class="dbfb-message error">Si è verificato un errore. Riprova.</div>');
            })
            .always(function() {
                $form.removeClass('loading');
                $form.find('.dbfb-submit').prop('disabled', false);
            });
        };
        
        // Gestisci reCAPTCHA
        if (typeof grecaptcha !== 'undefined' && dbfb.recaptcha_site_key) {
            if (isV3) {
                grecaptcha.ready(function() {
                    grecaptcha.execute(dbfb.recaptcha_site_key, {action: 'submit'}).then(function(token) {
                        submitForm(token);
                    }).catch(function(err) {
                        console.error('reCAPTCHA v3 error:', err);
                        $form.prepend('<div class="dbfb-message error">Errore reCAPTCHA. Ricarica la pagina e riprova.</div>');
                    });
                });
            } else if (hasV2Widget) {
                const response = grecaptcha.getResponse();
                if (!response) {
                    $form.prepend('<div class="dbfb-message error">Completa la verifica "Non sono un robot"</div>');
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

})(jQuery);
