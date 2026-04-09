(function($) {
    'use strict';

    const DBFB = {
        formId: 0,
        fields: [],
        
        init: function() {
            this.formId = $('#dbfb-form-id').val() || 0;
            this.loadExistingFields();
            this.initSortable();
            this.bindEvents();
        },
        
        loadExistingFields: function() {
            const fieldsData = $('#dbfb-fields-data').val();
            if (fieldsData) {
                try {
                    this.fields = JSON.parse(fieldsData);
                    this.fields.forEach(field => this.renderField(field));
                } catch(e) {
                    console.error('Error loading fields:', e);
                }
            }
            this.updateEmptyState();
        },
        
        initSortable: function() {
            if (document.getElementById('dbfb-field-types')) {
                new Sortable(document.getElementById('dbfb-field-types'), {
                    group: {
                        name: 'fields',
                        pull: 'clone',
                        put: false
                    },
                    sort: false,
                    animation: 150
                });
            }
            
            if (document.getElementById('dbfb-fields-container')) {
                new Sortable(document.getElementById('dbfb-fields-container'), {
                    group: 'fields',
                    animation: 150,
                    handle: '.dbfb-field-header',
                    ghostClass: 'sortable-ghost',
                    chosenClass: 'sortable-chosen',
                    onAdd: (evt) => {
                        const type = $(evt.item).data('type');
                        $(evt.item).remove();
                        this.addField(type);
                    },
                    onSort: () => {
                        this.reorderFields();
                    }
                });
            }
        },
        
        bindEvents: function() {
            // Toggle settings campo
            $(document).on('click', '.dbfb-field-toggle', function(e) {
                e.preventDefault();
                $(this).closest('.dbfb-field-item').toggleClass('open');
            });
            
            // Elimina campo
            $(document).on('click', '.dbfb-field-delete', (e) => {
                e.preventDefault();
                if (confirm(dbfb.strings.confirm_delete)) {
                    const $item = $(e.currentTarget).closest('.dbfb-field-item');
                    const fieldId = $item.data('id');
                    this.fields = this.fields.filter(f => f.id !== fieldId);
                    $item.remove();
                    this.updateEmptyState();
                }
            });
            
            // Update campo
            $(document).on('change keyup', '.dbfb-field-item input, .dbfb-field-item textarea', (e) => {
                const $item = $(e.currentTarget).closest('.dbfb-field-item');
                this.updateFieldData($item);
            });
            
            // Aggiungi opzione
            $(document).on('click', '.dbfb-add-option', (e) => {
                e.preventDefault();
                const $list = $(e.currentTarget).prev('.dbfb-options-list');
                const index = $list.children().length + 1;
                $list.append(this.getOptionHTML('Opzione ' + index));
                this.updateFieldData($(e.currentTarget).closest('.dbfb-field-item'));
            });
            
            // Rimuovi opzione
            $(document).on('click', '.dbfb-remove-option', (e) => {
                e.preventDefault();
                const $item = $(e.currentTarget).closest('.dbfb-field-item');
                $(e.currentTarget).closest('.dbfb-option-item').remove();
                this.updateFieldData($item);
            });
            
            // Salva form
            $('#dbfb-save-form').on('click', (e) => {
                e.preventDefault();
                this.saveForm();
            });
            
            // Copia shortcode
            $(document).on('click', '.dbfb-shortcode', function() {
                const text = $(this).text().trim();
                navigator.clipboard.writeText(text).then(() => {
                    const original = $(this).text();
                    $(this).text('Copiato!');
                    setTimeout(() => $(this).text(original), 1500);
                });
            });
            
            // Invio test email
            $(document).on('click', '.dbfb-send-test-email', (e) => {
                e.preventDefault();
                const $btn = $(e.currentTarget);
                const emailType = $btn.data('type');
                const testEmail = emailType === 'confirmation' 
                    ? $('#dbfb-test-confirmation-email').val()
                    : $('#dbfb-test-admin-email').val();
                
                if (!testEmail) {
                    alert('Inserisci un\'email per il test');
                    return;
                }
                
                if (!this.formId) {
                    alert('Salva prima il form');
                    return;
                }
                
                $btn.prop('disabled', true).text('Invio...');
                
                $.post(dbfb.ajax_url, {
                    action: 'dbfb_send_test_email',
                    nonce: dbfb.nonce,
                    form_id: this.formId,
                    email_type: emailType,
                    test_email: testEmail
                })
                .done((response) => {
                    alert(response.success ? response.data.message : (response.data.message || 'Errore'));
                })
                .fail(() => {
                    alert('Errore durante l\'invio');
                })
                .always(() => {
                    $btn.prop('disabled', false).text('Invia Test');
                });
            });
            
            // Seleziona immagine da Media Library
            $(document).on('click', '.dbfb-select-image', (e) => {
                e.preventDefault();
                const $btn = $(e.currentTarget);
                const $item = $btn.closest('.dbfb-field-item');
                const $input = $item.find('.field-image-url');
                
                const frame = wp.media({
                    title: 'Seleziona immagine',
                    button: { text: 'Usa questa immagine' },
                    multiple: false
                });
                
                frame.on('select', () => {
                    const attachment = frame.state().get('selection').first().toJSON();
                    $input.val(attachment.url).trigger('change');
                    
                    let $preview = $item.find('.dbfb-image-preview');
                    if (!$preview.length) {
                        $preview = $('<div class="dbfb-image-preview"></div>');
                        $item.find('.dbfb-field-settings').append($preview);
                    }
                    $preview.html('<img src="' + attachment.url + '" style="max-width:200px;">');
                    
                    this.updateFieldData($item);
                });
                
                frame.open();
            });
            
            // Toggle GDPR options
            $('#dbfb-enable-gdpr').on('change', function() {
                $('#dbfb-gdpr-options').toggle(this.checked);
            });
            
            // Toggle Rate Limit options
            $('#dbfb-rate-limit-enabled').on('change', function() {
                $('#dbfb-rate-limit-options').toggle(this.checked);
            });
            
            // Anteprima
            $('#dbfb-preview-btn').on('click', () => {
                this.showPreview();
            });
            
            $('#dbfb-preview-close, #dbfb-preview-modal').on('click', function(e) {
                if (e.target === this) $('#dbfb-preview-modal').fadeOut(200);
            });
        },
        
        showPreview: function() {
            let html = '';
            
            this.fields.forEach(field => {
                if (field.type === 'divider') {
                    html += '<div style="margin:20px 0;"><hr></div>';
                    return;
                }
                if (field.type === 'html') {
                    html += '<div style="margin-bottom:15px; line-height:1.6;">' + (field.content || '') + '</div>';
                    return;
                }
                if (field.type === 'image') {
                    if (field.image_url) {
                        html += '<div style="margin-bottom:15px; text-align:center;"><img src="' + this.escapeHtml(field.image_url) + '" alt="' + this.escapeHtml(field.image_alt || '') + '" style="max-width:100%; border-radius:4px;"></div>';
                    }
                    return;
                }
                
                const req = field.required ? ' <span style="color:#d63638;">*</span>' : '';
                html += '<div style="margin-bottom:18px;">';
                html += '<label style="display:block; margin-bottom:6px; font-weight:500;">' + this.escapeHtml(field.label) + req + '</label>';
                
                switch (field.type) {
                    case 'textarea':
                        html += '<textarea style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; min-height:100px;" placeholder="' + this.escapeHtml(field.placeholder || '') + '" disabled></textarea>';
                        break;
                    case 'select':
                        html += '<select style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;" disabled><option>Seleziona...</option>';
                        (field.options || []).forEach(opt => {
                            html += '<option>' + this.escapeHtml(opt) + '</option>';
                        });
                        html += '</select>';
                        break;
                    case 'checkbox':
                        (field.options || []).forEach(opt => {
                            html += '<div style="margin-bottom:5px;"><label style="font-weight:normal;"><input type="checkbox" disabled> ' + this.escapeHtml(opt) + '</label></div>';
                        });
                        break;
                    case 'radio':
                        (field.options || []).forEach(opt => {
                            html += '<div style="margin-bottom:5px;"><label style="font-weight:normal;"><input type="radio" disabled> ' + this.escapeHtml(opt) + '</label></div>';
                        });
                        break;
                    default:
                        html += '<input type="' + field.type + '" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;" placeholder="' + this.escapeHtml(field.placeholder || '') + '" disabled>';
                }
                
                html += '</div>';
            });
            
            // GDPR
            if ($('#dbfb-enable-gdpr').is(':checked')) {
                const gdprText = $('#dbfb-gdpr-text').val() || 'Acconsento al trattamento dei dati personali';
                html += '<div style="margin-bottom:18px;"><label style="font-weight:normal;"><input type="checkbox" disabled> ' + this.escapeHtml(gdprText) + ' <span style="color:#d63638;">*</span></label></div>';
            }
            
            // Submit button
            const submitText = $('#dbfb-submit-text').val() || 'Invia';
            html += '<div style="margin-top:20px;"><button type="button" style="padding:12px 30px; background:#2271b1; color:#fff; border:none; border-radius:4px; font-size:16px; cursor:default;">' + this.escapeHtml(submitText) + '</button></div>';
            
            $('#dbfb-preview-content').html(html);
            $('#dbfb-preview-modal').fadeIn(200);
        },
        
        addField: function(type) {
            const id = 'field_' + Date.now();
            const field = {
                id: id,
                type: type,
                label: this.getFieldLabel(type),
                placeholder: '',
                required: false,
                options: ['select', 'radio', 'checkbox'].includes(type) ? ['Opzione 1', 'Opzione 2'] : [],
                content: type === 'html' ? '<p>Inserisci qui il tuo testo</p>' : '',
                image_url: '',
                image_alt: ''
            };
            
            this.fields.push(field);
            this.renderField(field);
            this.updateEmptyState();
            
            $('.dbfb-field-item[data-id="' + id + '"]').addClass('open');
        },
        
        renderField: function(field) {
            const html = `
                <div class="dbfb-field-item" data-id="${field.id}" data-type="${field.type}">
                    <div class="dbfb-field-header">
                        <div class="dbfb-field-title">
                            <span class="dashicons ${this.getFieldIcon(field.type)}"></span>
                            <span class="field-label">${field.label}</span>
                        </div>
                        <div class="dbfb-field-actions">
                            <button type="button" class="dbfb-field-toggle" title="Impostazioni">
                                <span class="dashicons dashicons-admin-generic"></span>
                            </button>
                            <button type="button" class="dbfb-field-delete delete" title="Elimina">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                    </div>
                    <div class="dbfb-field-settings">
                        ${this.getFieldSettings(field)}
                    </div>
                </div>
            `;
            
            $('#dbfb-fields-container').append(html);
        },
        
        getFieldSettings: function(field) {
            if (field.type === 'divider') {
                return `
                    <div class="dbfb-field-row">
                        <p class="description">Questo elemento aggiunge una linea separatrice nel form.</p>
                    </div>
                `;
            }
            
            if (field.type === 'html') {
                return `
                    <div class="dbfb-field-row">
                        <label>Contenuto HTML/Testo</label>
                        <textarea class="field-content" rows="5">${this.escapeHtml(field.content || '')}</textarea>
                        <p class="description">Puoi usare HTML per formattazione (es: &lt;strong&gt;, &lt;em&gt;, &lt;a&gt;)</p>
                    </div>
                `;
            }
            
            if (field.type === 'image') {
                return `
                    <div class="dbfb-field-row">
                        <label>URL Immagine</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" class="field-image-url" value="${this.escapeHtml(field.image_url || '')}" style="flex:1;">
                            <button type="button" class="button dbfb-select-image">Seleziona</button>
                        </div>
                    </div>
                    <div class="dbfb-field-row">
                        <label>Testo alternativo (alt)</label>
                        <input type="text" class="field-image-alt" value="${this.escapeHtml(field.image_alt || '')}">
                    </div>
                    ${field.image_url ? `<div class="dbfb-image-preview"><img src="${this.escapeHtml(field.image_url)}" style="max-width:200px;"></div>` : ''}
                `;
            }
            
            let html = `
                <div class="dbfb-field-row">
                    <label>Etichetta</label>
                    <input type="text" class="field-label-input" value="${this.escapeHtml(field.label)}">
                </div>
            `;
            
            if (!['checkbox', 'radio'].includes(field.type)) {
                html += `
                    <div class="dbfb-field-row">
                        <label>Placeholder</label>
                        <input type="text" class="field-placeholder" value="${this.escapeHtml(field.placeholder || '')}">
                    </div>
                `;
            }
            
            if (['select', 'radio', 'checkbox'].includes(field.type)) {
                html += `
                    <div class="dbfb-field-row">
                        <label>Opzioni</label>
                        <div class="dbfb-options-list">
                            ${(field.options || []).map(opt => this.getOptionHTML(opt)).join('')}
                        </div>
                        <button type="button" class="button dbfb-add-option">+ Aggiungi opzione</button>
                    </div>
                `;
            }
            
            html += `
                <div class="dbfb-field-row">
                    <label>
                        <input type="checkbox" class="field-required" ${field.required ? 'checked' : ''}>
                        Campo obbligatorio
                    </label>
                </div>
            `;
            
            return html;
        },
        
        getOptionHTML: function(value) {
            return `
                <div class="dbfb-option-item">
                    <input type="text" class="field-option" value="${this.escapeHtml(value)}">
                    <button type="button" class="dbfb-remove-option" title="Rimuovi">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            `;
        },
        
        updateFieldData: function($item) {
            const id = $item.data('id');
            const field = this.fields.find(f => f.id === id);
            
            if (field) {
                const labelInput = $item.find('.field-label-input').val();
                if (labelInput !== undefined) field.label = labelInput;
                
                field.placeholder = $item.find('.field-placeholder').val() || '';
                field.required = $item.find('.field-required').is(':checked');
                
                const options = [];
                $item.find('.field-option').each(function() {
                    const val = $(this).val().trim();
                    if (val) options.push(val);
                });
                if (options.length) field.options = options;
                
                field.content = $item.find('.field-content').val() || '';
                field.image_url = $item.find('.field-image-url').val() || '';
                field.image_alt = $item.find('.field-image-alt').val() || '';
                
                if (labelInput !== undefined) {
                    $item.find('.dbfb-field-title .field-label').text(field.label);
                }
            }
        },
        
        reorderFields: function() {
            const newOrder = [];
            $('#dbfb-fields-container .dbfb-field-item').each((i, el) => {
                const id = $(el).data('id');
                const field = this.fields.find(f => f.id === id);
                if (field) newOrder.push(field);
            });
            this.fields = newOrder;
        },
        
        updateEmptyState: function() {
            const $container = $('#dbfb-fields-container');
            if (this.fields.length === 0) {
                if (!$container.find('.empty-message').length) {
                    $container.addClass('empty').html('<p class="empty-message">Trascina i campi qui per costruire il form</p>');
                }
            } else {
                $container.removeClass('empty').find('.empty-message').remove();
            }
        },
        
        getFieldLabel: function(type) {
            const labels = {
                'text': 'Campo testo',
                'email': 'Email',
                'textarea': 'Area testo',
                'select': 'Menu a tendina',
                'checkbox': 'Checkbox',
                'radio': 'Scelta singola',
                'tel': 'Telefono',
                'number': 'Numero',
                'date': 'Data',
                'url': 'URL',
                'html': 'Testo statico',
                'image': 'Immagine',
                'divider': 'Separatore'
            };
            return labels[type] || 'Campo';
        },
        
        getFieldIcon: function(type) {
            const icons = {
                'text': 'dashicons-editor-textcolor',
                'email': 'dashicons-email',
                'textarea': 'dashicons-editor-paragraph',
                'select': 'dashicons-arrow-down-alt2',
                'checkbox': 'dashicons-yes',
                'radio': 'dashicons-marker',
                'tel': 'dashicons-phone',
                'number': 'dashicons-editor-ol',
                'date': 'dashicons-calendar-alt',
                'url': 'dashicons-admin-links',
                'html': 'dashicons-text',
                'image': 'dashicons-format-image',
                'divider': 'dashicons-minus'
            };
            return icons[type] || 'dashicons-admin-generic';
        },
        
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        
        saveForm: function() {
            const title = $('#dbfb-form-title').val();
            
            if (!title) {
                alert('Inserisci un titolo per il form');
                return;
            }
            
            const settings = {
                submit_text: $('#dbfb-submit-text').val(),
                success_message: $('#dbfb-success-message').val(),
                enable_captcha: $('#dbfb-enable-captcha').is(':checked'),
                enable_honeypot: $('#dbfb-enable-honeypot').is(':checked'),
                enable_gdpr: $('#dbfb-enable-gdpr').is(':checked'),
                gdpr_text: $('#dbfb-gdpr-text').val(),
                gdpr_link: $('#dbfb-gdpr-link').val(),
                rate_limit_enabled: $('#dbfb-rate-limit-enabled').is(':checked'),
                rate_limit_max: $('#dbfb-rate-limit-max').val(),
                rate_limit_window: $('#dbfb-rate-limit-window').val(),
                send_confirmation: $('#dbfb-send-confirmation').is(':checked'),
                confirmation_subject: $('#dbfb-confirmation-subject').val(),
                confirmation_message: $('#dbfb-confirmation-message').val(),
                send_admin_notification: $('#dbfb-send-admin-notification').is(':checked'),
                admin_email: $('#dbfb-admin-email').val(),
                admin_subject: $('#dbfb-admin-subject').val(),
                admin_message: $('#dbfb-admin-message').val()
            };
            
            const $btn = $('#dbfb-save-form');
            $btn.prop('disabled', true).text('Salvataggio...');
            
            $.post(dbfb.ajax_url, {
                action: 'dbfb_save_form',
                nonce: dbfb.nonce,
                form_id: this.formId,
                title: title,
                fields: JSON.stringify(this.fields),
                settings: JSON.stringify(settings)
            })
            .done((response) => {
                if (response.success) {
                    this.formId = response.data.form_id;
                    $('#dbfb-form-id').val(this.formId);
                    $('.dbfb-shortcode').text('[dbfb_form id="' + this.formId + '"]').css('opacity', 1);
                    
                    const $notice = $('<div class="dbfb-notice">' + response.data.message + '</div>');
                    $('.dbfb-wrap').prepend($notice);
                    setTimeout(() => $notice.fadeOut(300, function() { $(this).remove(); }), 3000);
                } else {
                    alert(response.data.message || dbfb.strings.error);
                }
            })
            .fail(() => {
                alert(dbfb.strings.error);
            })
            .always(() => {
                $btn.prop('disabled', false).text('Salva Form');
            });
        }
    };

    $(document).ready(function() {
        if ($('#dbfb-form-builder').length) {
            DBFB.init();
        }
        
        // Gestione selezione template
        $(document).on('click', '.dbfb-use-template', function(e) {
            e.preventDefault();
            var template = $(this).data('template');
            var $btn = $(this);
            
            if (template === 'blank') {
                $('.dbfb-templates-section').slideUp(300);
                $('.dbfb-builder-section').slideDown(300);
                return;
            }
            
            $btn.prop('disabled', true).text('Creazione...');
            
            $.post(dbfb.ajax_url, {
                action: 'dbfb_create_from_template',
                nonce: dbfb.nonce,
                template: template
            })
            .done(function(response) {
                if (response.success && response.data.redirect) {
                    window.location.href = response.data.redirect;
                } else {
                    alert(response.data.message || 'Errore');
                    $btn.prop('disabled', false).text('Usa template');
                }
            })
            .fail(function() {
                alert('Errore durante la creazione del form');
                $btn.prop('disabled', false).text('Usa template');
            });
        });
    });

})(jQuery);
