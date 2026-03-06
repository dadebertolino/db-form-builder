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
            
            if (type === 'checkbox') {
                if (!formData[name]) formData[name] = [];
                if ($field.is(':checked')) {
                    formData[name].push($field.val());
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
        
        // Funzione per inviare il form
        const submitForm = function(recaptchaToken) {
            // Loading state
            $form.addClass('loading');
            $form.find('.dbfb-submit').prop('disabled', true);
            
            $.post(dbfb.ajax_url, {
                action: 'dbfb_submit_form',
                nonce: dbfb.nonce,
                form_id: formId,
                data: JSON.stringify(formData),
                recaptcha_token: recaptchaToken || ''
            })
            .done(function(response) {
                if (response.success) {
                    $form.prepend('<div class="dbfb-message success">' + response.data.message + '</div>');
                    $form[0].reset();
                    
                    // Reset reCAPTCHA v2 se presente
                    if (typeof grecaptcha !== 'undefined' && hasV2Widget) {
                        grecaptcha.reset();
                    }
                    
                    // Scroll al messaggio
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
                // reCAPTCHA v3 (invisibile)
                grecaptcha.ready(function() {
                    grecaptcha.execute(dbfb.recaptcha_site_key, {action: 'submit'}).then(function(token) {
                        submitForm(token);
                    }).catch(function(err) {
                        console.error('reCAPTCHA v3 error:', err);
                        $form.prepend('<div class="dbfb-message error">Errore reCAPTCHA. Ricarica la pagina e riprova.</div>');
                    });
                });
            } else if (hasV2Widget) {
                // reCAPTCHA v2 - ottieni token dal widget
                const response = grecaptcha.getResponse();
                if (!response) {
                    $form.prepend('<div class="dbfb-message error">Completa la verifica "Non sono un robot"</div>');
                    return;
                }
                submitForm(response);
            } else {
                // Nessun captcha attivo
                submitForm('');
            }
        } else {
            submitForm('');
        }
    });

})(jQuery);
