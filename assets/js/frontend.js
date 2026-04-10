(function($) {
    'use strict';

    /**
     * DB Form Builder — Frontend
     * - Conditional logic engine
     * - File upload with drag & drop
     * - FormData submission (supports files)
     * - WCAG 2.1 AA compliant
     */
    
    var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    
    // =========================================================
    // CONDITIONAL LOGIC ENGINE
    // =========================================================
    
    function getFieldValue($form, fieldId) {
        var $group = $form.find('[data-field-id="' + fieldId + '"]');
        if (!$group.length) return '';
        
        var $radio = $group.find('input[type="radio"]:checked');
        if ($radio.length) return $radio.val();
        
        var $checkboxes = $group.find('input[type="checkbox"]:checked');
        if ($group.find('input[type="checkbox"]').length) {
            var vals = [];
            $checkboxes.each(function() { vals.push($(this).val()); });
            return vals.join(', ');
        }
        
        var $input = $group.find('select, input, textarea').first();
        return $input.length ? $input.val() || '' : '';
    }
    
    function evaluateRule(fieldValue, operator, ruleValue) {
        var v = String(fieldValue).trim().toLowerCase();
        var r = String(ruleValue).trim().toLowerCase();
        
        switch (operator) {
            case 'equals':       return v === r;
            case 'not_equals':   return v !== r;
            case 'contains':     return v.indexOf(r) !== -1;
            case 'not_contains': return v.indexOf(r) === -1;
            case 'empty':        return v === '';
            case 'not_empty':    return v !== '';
            case 'greater_than': return parseFloat(fieldValue) > parseFloat(ruleValue);
            case 'less_than':    return parseFloat(fieldValue) < parseFloat(ruleValue);
            default:             return false;
        }
    }
    
    function evaluateConditions($form, conditions) {
        if (!conditions || !conditions.enabled || !conditions.rules || !conditions.rules.length) return true;
        
        var results = [];
        for (var i = 0; i < conditions.rules.length; i++) {
            var rule = conditions.rules[i];
            results.push(evaluateRule(getFieldValue($form, rule.field), rule.operator, rule.value));
        }
        
        var match = conditions.logic === 'any' 
            ? results.indexOf(true) !== -1 
            : results.indexOf(false) === -1;
        
        return conditions.action === 'show' ? match : !match;
    }
    
    function runConditionalLogic($form) {
        var hiddenFields = [];
        
        $form.find('[data-conditions]').each(function() {
            var $el = $(this);
            var conditions;
            try { conditions = JSON.parse($el.attr('data-conditions')); } catch(e) { return; }
            
            var shouldBeVisible = evaluateConditions($form, conditions);
            var isVisible = $el.is(':visible');
            
            if (shouldBeVisible && !isVisible) {
                if (prefersReducedMotion) { $el.show(); } else { $el.slideDown(200); }
                $el.removeAttr('aria-hidden');
                $el.find('input, textarea, select').prop('disabled', false);
            } else if (!shouldBeVisible && isVisible) {
                if (prefersReducedMotion) { $el.hide(); } else { $el.slideUp(200); }
                $el.attr('aria-hidden', 'true');
                $el.find('input, textarea, select').prop('disabled', true);
            }
            
            if (!shouldBeVisible) {
                var fid = $el.data('field-id');
                if (fid) hiddenFields.push(fid);
            }
        });
        
        $form.find('[name="dbfb_hidden_fields"]').val(JSON.stringify(hiddenFields));
    }
    
    function initConditionalLogic() {
        $('.dbfb-form').each(function() {
            var $form = $(this);
            if (!$form.find('[data-conditions]').length) return;
            runConditionalLogic($form);
            $form.on('input change', 'input, textarea, select', function() {
                runConditionalLogic($form);
            });
        });
    }
    
    // =========================================================
    // FILE UPLOAD — DRAG & DROP
    // =========================================================
    
    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }
    
    function initFileUploads() {
        $('.dbfb-file-dropzone').each(function() {
            var $dropzone = $(this);
            var $input = $dropzone.find('.dbfb-file-input');
            var $list = $dropzone.find('.dbfb-file-list');
            var maxSizeMB = parseInt($dropzone.data('max-size')) || 5;
            var extensions = ($dropzone.data('extensions') || '').split(',').map(function(e) { return e.trim().toLowerCase(); });
            
            // Click to open file picker
            $dropzone.on('click', function(e) {
                if ($(e.target).closest('.dbfb-file-remove').length) return;
                if ($(e.target).is('input[type="file"]')) return; // Prevent loop
                $input[0].click(); // Native click — browsers block jQuery .trigger('click') on file inputs
            });
            
            // Prevent input click from bubbling back to dropzone
            $input.on('click', function(e) {
                e.stopPropagation();
            });
            
            // Keyboard: Enter/Space
            $dropzone.on('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $input[0].click();
                }
            });
            
            // Drag events
            $dropzone.on('dragover dragenter', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $dropzone.addClass('dbfb-file-dragover');
            });
            
            $dropzone.on('dragleave drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $dropzone.removeClass('dbfb-file-dragover');
            });
            
            $dropzone.on('drop', function(e) {
                var files = e.originalEvent.dataTransfer.files;
                if (files.length) {
                    // Set files on the input
                    var dt = new DataTransfer();
                    // Keep existing files if multiple
                    if ($input.prop('multiple') && $input[0].files.length) {
                        for (var i = 0; i < $input[0].files.length; i++) {
                            dt.items.add($input[0].files[i]);
                        }
                    }
                    for (var j = 0; j < files.length; j++) {
                        dt.items.add(files[j]);
                    }
                    $input[0].files = dt.files;
                    $input.trigger('change');
                }
            });
            
            // File input change — validate & show preview
            $input.on('change', function() {
                $list.empty();
                var files = this.files;
                if (!files || !files.length) return;
                
                var maxSizeBytes = maxSizeMB * 1024 * 1024;
                var hasError = false;
                
                for (var i = 0; i < files.length; i++) {
                    var file = files[i];
                    var ext = file.name.split('.').pop().toLowerCase();
                    var sizeOk = file.size <= maxSizeBytes;
                    var extOk = extensions.indexOf(ext) !== -1;
                    var isError = !sizeOk || !extOk;
                    if (isError) hasError = true;
                    
                    var errorMsg = '';
                    if (!extOk) errorMsg = 'Formato non ammesso';
                    else if (!sizeOk) errorMsg = 'File troppo grande (max ' + maxSizeMB + ' MB)';
                    
                    var $item = $('<div class="dbfb-file-item' + (isError ? ' dbfb-file-error' : '') + '">' +
                        '<span class="dbfb-file-name">' + $('<span>').text(file.name).html() + '</span>' +
                        '<span class="dbfb-file-size">' + formatFileSize(file.size) + '</span>' +
                        (errorMsg ? '<span class="dbfb-file-error-msg">' + errorMsg + '</span>' : '') +
                        '<button type="button" class="dbfb-file-remove" data-index="' + i + '" aria-label="Rimuovi ' + $('<span>').text(file.name).html() + '">&times;</button>' +
                    '</div>');
                    
                    $list.append($item);
                }
                
                // Update dropzone state
                $dropzone.toggleClass('dbfb-file-has-files', files.length > 0);
                
                // Set aria-invalid if errors
                $input.attr('aria-invalid', hasError ? 'true' : 'false');
            });
            
            // Remove file
            $list.on('click', '.dbfb-file-remove', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var idx = parseInt($(this).data('index'));
                var dt = new DataTransfer();
                var files = $input[0].files;
                for (var i = 0; i < files.length; i++) {
                    if (i !== idx) dt.items.add(files[i]);
                }
                $input[0].files = dt.files;
                $input.trigger('change');
            });
        });
    }
    
    // =========================================================
    // FORM HELPERS
    // =========================================================
    
    function scrollToElement($el) {
        if (prefersReducedMotion) {
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
        var $input = $group.find('input, textarea, select').first();
        if ($input.length) $input.attr('aria-invalid', 'true');
        var $errorDiv = $group.find('.dbfb-field-error');
        if ($errorDiv.length) $errorDiv.text(message);
    }
    
    function showMessage($form, type, message) {
        var $region = $form.find('.dbfb-messages-region');
        $region.html('<div class="dbfb-message ' + type + '" role="alert">' + message + '</div>');
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

    // =========================================================
    // FORM SUBMIT (FormData — supports files)
    // =========================================================

    $(document).on('submit', '.dbfb-form', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var formId = $form.data('form-id');
        var isV3 = $form.data('recaptcha-v3');
        var hasV2Widget = $form.find('.g-recaptcha').length > 0;
        
        // Collect non-file data (only from enabled/visible fields)
        var formData = {};
        $form.find('[name]').each(function() {
            var $field = $(this);
            var name = $field.attr('name');
            var type = $field.attr('type');
            
            if (name === 'dbfb_website_url' || name === 'dbfb_timestamp' || name === 'dbfb_hidden_fields') return;
            if ($field.prop('disabled')) return;
            if (type === 'file') return; // Files handled via FormData
            
            if (type === 'checkbox') {
                if (name === 'dbfb_gdpr_consent') {
                    if ($field.is(':checked')) formData[name] = $field.val();
                } else {
                    if (!formData[name]) formData[name] = [];
                    if ($field.is(':checked')) formData[name].push($field.val());
                }
            } else if (type === 'radio') {
                if ($field.is(':checked')) formData[name] = $field.val();
            } else {
                formData[name] = $field.val();
            }
        });
        
        clearErrors($form);
        
        // Client-side file validation
        var fileError = false;
        $form.find('.dbfb-file-dropzone').each(function() {
            var $dz = $(this);
            var $input = $dz.find('.dbfb-file-input');
            if ($input.prop('disabled')) return; // Hidden by conditional logic
            
            if ($dz.find('.dbfb-file-error').length) {
                fileError = true;
                showMessage($form, 'error', 'Correggi gli errori nei file allegati prima di inviare.');
                return false;
            }
        });
        if (fileError) return;
        
        var honeypotValue = $form.find('[name="dbfb_website_url"]').val() || '';
        var timestamp = $form.find('[name="dbfb_timestamp"]').val() || '';
        var hiddenFields = $form.find('[name="dbfb_hidden_fields"]').val() || '[]';
        
        var submitForm = function(recaptchaToken) {
            setLoadingState($form, true);
            
            // Build FormData for file support
            var fd = new FormData();
            fd.append('action', 'dbfb_submit_form');
            fd.append('nonce', dbfb.nonce);
            fd.append('form_id', formId);
            fd.append('data', JSON.stringify(formData));
            fd.append('recaptcha_token', recaptchaToken || '');
            fd.append('dbfb_website_url', honeypotValue);
            fd.append('dbfb_timestamp', timestamp);
            fd.append('hidden_fields', hiddenFields);
            
            // Append files
            $form.find('.dbfb-file-input').each(function() {
                var $input = $(this);
                if ($input.prop('disabled')) return;
                var files = this.files;
                if (!files || !files.length) return;
                var name = $input.attr('name');
                for (var i = 0; i < files.length; i++) {
                    fd.append(name, files[i]);
                }
            });
            
            $.ajax({
                url: dbfb.ajax_url,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        showMessage($form, 'success', response.data.message);
                        $form[0].reset();
                        $form.find('.dbfb-file-list').empty();
                        $form.find('.dbfb-file-dropzone').removeClass('dbfb-file-has-files');
                        if (typeof grecaptcha !== 'undefined' && hasV2Widget) grecaptcha.reset();
                        
                        // Hide form fields while showing success
                        var $multistep = $form.find('.dbfb-multistep');
                        var $stepNav = $form.find('.dbfb-step-nav');
                        var $submitWrap = $form.find('.dbfb-submit').closest('.dbfb-form-group');
                        var $captcha = $form.find('.dbfb-recaptcha-container');
                        var $gdpr = $form.find('.dbfb-gdpr-group');
                        
                        if ($multistep.length) {
                            $multistep.hide();
                            $stepNav.hide();
                            $submitWrap.hide();
                            $captcha.hide();
                            $gdpr.hide();
                        }
                        
                        // After 5s: fade message, restore form to step 1
                        setTimeout(function() {
                            var $msg = $form.find('.dbfb-messages-region .dbfb-message.success');
                            var afterFade = function() {
                                $msg.remove();
                                
                                runConditionalLogic($form);
                                
                                if ($multistep.length) {
                                    // Reset to step 1
                                    $multistep.find('.dbfb-step').hide().attr('aria-hidden', 'true');
                                    $multistep.find('.dbfb-step').first().show().removeAttr('aria-hidden');
                                    var total = $multistep.find('.dbfb-step').length;
                                    $multistep.find('.dbfb-progress-bar').css('width', Math.round(100 / total) + '%');
                                    $multistep.find('.dbfb-progress-text').text('1 / ' + total);
                                    $multistep.find('.dbfb-progress').attr('aria-valuenow', 1);
                                    
                                    // Show form elements again
                                    $multistep.show();
                                    $stepNav.show();
                                    $form.find('.dbfb-step-prev').hide();
                                    $form.find('.dbfb-step-next').show();
                                    $form.find('.dbfb-submit[type="submit"]').hide();
                                    $captcha.show();
                                    $gdpr.show();
                                }
                            };
                            
                            if ($msg.length) {
                                if (prefersReducedMotion) {
                                    afterFade();
                                } else {
                                    $msg.fadeOut(400, afterFade);
                                }
                            }
                        }, 5000);
                    } else {
                        showMessage($form, 'error', response.data.message);
                        if (response.data.field_id) {
                            showFieldError($form, response.data.field_id, response.data.message);
                            var $firstError = $form.find('[aria-invalid="true"]').first();
                            if ($firstError.length) $firstError.trigger('focus');
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('DBFB Error:', status, error);
                    showMessage($form, 'error', 'Si è verificato un errore. Riprova.');
                },
                complete: function() {
                    setLoadingState($form, false);
                }
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
    
    // Real-time validation: clear error on input
    $(document).on('input change', '.dbfb-form input, .dbfb-form textarea, .dbfb-form select', function() {
        var $field = $(this);
        var $group = $field.closest('.dbfb-form-group');
        if ($group.hasClass('error')) {
            $group.removeClass('error');
            $field.attr('aria-invalid', 'false');
            $group.find('.dbfb-field-error').empty();
        }
    });
    
    // =========================================================
    // MULTI-STEP ENGINE
    // =========================================================
    
    function initMultiStep() {
        $('.dbfb-multistep').each(function() {
            var $multistep = $(this);
            var $form = $multistep.closest('.dbfb-form');
            var $steps = $multistep.find('.dbfb-step');
            var $prevBtn = $form.find('.dbfb-step-prev');
            var $nextBtn = $form.find('.dbfb-step-next');
            var $submitBtn = $form.find('.dbfb-submit[type="submit"]');
            var $progress = $multistep.find('.dbfb-progress');
            var $progressBar = $progress.find('.dbfb-progress-bar');
            var $progressText = $progress.find('.dbfb-progress-text');
            var totalSteps = $steps.length;
            var currentStep = 0;
            
            function goToStep(step) {
                if (step < 0 || step >= totalSteps) return;
                
                // Hide current
                $steps.eq(currentStep).hide().attr('aria-hidden', 'true');
                
                // Show target
                $steps.eq(step).show().removeAttr('aria-hidden');
                
                currentStep = step;
                
                // Update buttons
                $prevBtn.toggle(currentStep > 0);
                $nextBtn.toggle(currentStep < totalSteps - 1);
                $submitBtn.toggle(currentStep === totalSteps - 1);
                
                // Update progress
                var pct = Math.round(((currentStep + 1) / totalSteps) * 100);
                $progressBar.css('width', pct + '%');
                $progressText.text(
                    (currentStep + 1) + ' / ' + totalSteps
                );
                $progress.attr('aria-valuenow', currentStep + 1);
                
                // Scroll to top of form
                scrollToElement($form);
                
                // Focus first input in new step (a11y)
                var $firstInput = $steps.eq(currentStep).find('input, textarea, select').filter(':visible').first();
                if ($firstInput.length) {
                    setTimeout(function() { $firstInput.trigger('focus'); }, 100);
                }
            }
            
            function validateCurrentStep() {
                var $currentStepEl = $steps.eq(currentStep);
                var isValid = true;
                
                // Clear errors in current step
                $currentStepEl.find('.dbfb-form-group').removeClass('error');
                $currentStepEl.find('.dbfb-field-error').empty();
                $currentStepEl.find('[aria-invalid]').attr('aria-invalid', 'false');
                
                // Validate required visible fields in current step
                $currentStepEl.find('.dbfb-form-group:visible').each(function() {
                    var $group = $(this);
                    var $inputs = $group.find('[aria-required="true"]').filter(':visible:not(:disabled)');
                    
                    $inputs.each(function() {
                        var $input = $(this);
                        var value = '';
                        var type = $input.attr('type');
                        
                        if (type === 'radio') {
                            var name = $input.attr('name');
                            value = $group.find('input[name="' + name + '"]:checked').val() || '';
                        } else if (type === 'checkbox') {
                            value = $group.find('input[type="checkbox"]:checked').length > 0 ? 'yes' : '';
                        } else if (type === 'file') {
                            value = ($input[0].files && $input[0].files.length) ? 'yes' : '';
                        } else {
                            value = $input.val() || '';
                        }
                        
                        if (!value.trim()) {
                            isValid = false;
                            $group.addClass('error');
                            $input.attr('aria-invalid', 'true');
                            var $errorDiv = $group.find('.dbfb-field-error');
                            if ($errorDiv.length && !$errorDiv.text()) {
                                $errorDiv.text('Questo campo è obbligatorio');
                            }
                        }
                    });
                });
                
                if (!isValid) {
                    // Focus first error
                    var $firstError = $currentStepEl.find('[aria-invalid="true"]').first();
                    if ($firstError.length) $firstError.trigger('focus');
                }
                
                return isValid;
            }
            
            // Next button
            $nextBtn.on('click', function() {
                if (validateCurrentStep()) {
                    goToStep(currentStep + 1);
                }
            });
            
            // Prev button
            $prevBtn.on('click', function() {
                goToStep(currentStep - 1);
            });
            
            // Initial state
            $submitBtn.hide();
            goToStep(0);
        });
    }
    
    // =========================================================
    // INIT
    // =========================================================
    
    $(document).ready(function() {
        initConditionalLogic();
        initFileUploads();
        initMultiStep();
    });

})(jQuery);
