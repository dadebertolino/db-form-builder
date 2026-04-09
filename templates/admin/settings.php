<?php if (!defined('ABSPATH')) exit; ?>

<div class="dbfb-wrap">
    <div class="dbfb-header">
        <h1><?php _e('Impostazioni', 'db-form-builder'); ?></h1>
    </div>
    
    <div class="dbfb-settings-page">
        <form id="dbfb-global-settings-form">
            
            <!-- reCAPTCHA -->
            <div class="dbfb-settings-section">
                <h2><?php _e('Google reCAPTCHA', 'db-form-builder'); ?></h2>
                
                <div class="dbfb-notice info" style="margin-bottom: 20px; padding: 15px; background: #e7f3ff; border-left: 4px solid #2271b1;">
                    <h4 style="margin: 0 0 10px;"><?php _e('Come ottenere le chiavi reCAPTCHA (gratis)', 'db-form-builder'); ?></h4>
                    <ol style="margin: 0; padding-left: 20px;">
                        <li><?php _e('Vai su', 'db-form-builder'); ?> <a href="https://www.google.com/recaptcha/admin/create" target="_blank"><strong>Google reCAPTCHA Admin</strong></a></li>
                        <li><?php _e('Accedi con il tuo account Google', 'db-form-builder'); ?></li>
                        <li><?php _e('Clicca "+ Crea" o compila il form:', 'db-form-builder'); ?>
                            <ul style="margin: 5px 0;">
                                <li><strong><?php _e('Etichetta', 'db-form-builder'); ?>:</strong> <?php _e('nome a piacere (es: "Mio Sito")', 'db-form-builder'); ?></li>
                                <li><strong><?php _e('Tipo', 'db-form-builder'); ?>:</strong> <?php _e('Challenge (v2) → "Non sono un robot"', 'db-form-builder'); ?></li>
                                <li><strong><?php _e('Domini', 'db-form-builder'); ?>:</strong> <?php echo esc_html(parse_url(home_url(), PHP_URL_HOST)); ?></li>
                            </ul>
                        </li>
                        <li><?php _e('Accetta i termini e clicca "Invia"', 'db-form-builder'); ?></li>
                        <li><?php _e('Copia le chiavi qui sotto', 'db-form-builder'); ?></li>
                    </ol>
                    <p style="margin: 10px 0 0;">
                        <a href="https://www.google.com/recaptcha/admin/create" target="_blank" class="button button-primary">
                            <?php _e('Crea chiavi reCAPTCHA', 'db-form-builder'); ?> →
                        </a>
                        <a href="https://www.google.com/recaptcha/admin" target="_blank" class="button" style="margin-left: 10px;">
                            <?php _e('Gestisci chiavi esistenti', 'db-form-builder'); ?>
                        </a>
                    </p>
                </div>
                
                <table class="form-table">
                    <tr>
                        <th><label for="recaptcha_version"><?php _e('Versione reCAPTCHA', 'db-form-builder'); ?></label></th>
                        <td>
                            <select id="recaptcha_version" name="recaptcha_version">
                                <option value="v2" <?php selected($global_settings['recaptcha_version'] ?? 'v2', 'v2'); ?>>
                                    <?php _e('v2 - Checkbox "Non sono un robot"', 'db-form-builder'); ?>
                                </option>
                                <option value="v3" <?php selected($global_settings['recaptcha_version'] ?? 'v2', 'v3'); ?>>
                                    <?php _e('v3 - Invisibile (punteggio)', 'db-form-builder'); ?>
                                </option>
                            </select>
                            <p class="description"><?php _e('v2 mostra un checkbox, v3 è invisibile. Assicurati di usare chiavi compatibili.', 'db-form-builder'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="recaptcha_site_key"><?php _e('Site Key (Chiave del sito)', 'db-form-builder'); ?></label></th>
                        <td>
                            <input type="text" id="recaptcha_site_key" name="recaptcha_site_key" class="regular-text"
                                   value="<?php echo esc_attr($global_settings['recaptcha_site_key']); ?>"
                                   placeholder="6LcXXXXXXXXXXXXXXXXXXXXXXXXXXXXX">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="recaptcha_secret_key"><?php _e('Secret Key (Chiave segreta)', 'db-form-builder'); ?></label></th>
                        <td>
                            <input type="text" id="recaptcha_secret_key" name="recaptcha_secret_key" class="regular-text"
                                   value="<?php echo esc_attr($global_settings['recaptcha_secret_key']); ?>"
                                   placeholder="6LcXXXXXXXXXXXXXXXXXXXXXXXXXXXXX">
                        </td>
                    </tr>
                </table>
                
                <!-- Test reCAPTCHA -->
                <div class="dbfb-recaptcha-test" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <h4 style="margin-top: 0;"><?php _e('Testa le chiavi', 'db-form-builder'); ?></h4>
                    <p class="description"><?php _e('Verifica che le chiavi siano corrette prima di salvare.', 'db-form-builder'); ?></p>
                    <div id="dbfb-recaptcha-test-container" style="margin: 15px 0;"></div>
                    <button type="button" id="dbfb-test-recaptcha" class="button">
                        <?php _e('Verifica chiavi', 'db-form-builder'); ?>
                    </button>
                    <span id="dbfb-test-result" style="margin-left: 10px;"></span>
                </div>
            </div>
            
            <!-- Email Settings -->
            <div class="dbfb-settings-section">
                <h2><?php _e('Impostazioni Email', 'db-form-builder'); ?></h2>
                <p class="description">
                    <?php _e('Configura il mittente per tutte le email inviate dal plugin.', 'db-form-builder'); ?>
                </p>
                
                <table class="form-table">
                    <tr>
                        <th><label for="from_name"><?php _e('Nome Mittente', 'db-form-builder'); ?></label></th>
                        <td>
                            <input type="text" id="from_name" name="from_name" class="regular-text"
                                   value="<?php echo esc_attr($global_settings['from_name']); ?>"
                                   placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="from_email"><?php _e('Email Mittente', 'db-form-builder'); ?></label></th>
                        <td>
                            <input type="email" id="from_email" name="from_email" class="regular-text"
                                   value="<?php echo esc_attr($global_settings['from_email']); ?>"
                                   placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                            <p class="description"><?php _e('Usa un indirizzo del tuo dominio per evitare problemi di deliverability', 'db-form-builder'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <!-- Test Email -->
                <div class="dbfb-email-test" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <h4 style="margin-top: 0;"><?php _e('Testa invio email', 'db-form-builder'); ?></h4>
                    <p class="description"><?php _e('Verifica che il server possa inviare email correttamente.', 'db-form-builder'); ?></p>
                    <div style="margin: 15px 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <input type="email" id="dbfb-test-email-address" class="regular-text" 
                               placeholder="<?php _e('Email destinatario', 'db-form-builder'); ?>"
                               value="<?php echo esc_attr(get_option('admin_email')); ?>">
                        <button type="button" id="dbfb-test-email" class="button">
                            <?php _e('Invia email di test', 'db-form-builder'); ?>
                        </button>
                    </div>
                    <div id="dbfb-test-email-result"></div>
                </div>
            </div>
            
            <!-- Placeholder Reference -->
            <div class="dbfb-settings-section">
                <h2><?php _e('Riferimento Placeholder', 'db-form-builder'); ?></h2>
                <p class="description">
                    <?php _e('Usa questi placeholder nei testi delle email per inserire dinamicamente i valori:', 'db-form-builder'); ?>
                </p>
                
                <table class="widefat" style="max-width: 600px;">
                    <thead>
                        <tr>
                            <th><?php _e('Placeholder', 'db-form-builder'); ?></th>
                            <th><?php _e('Descrizione', 'db-form-builder'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>{form_titolo}</code></td><td><?php _e('Nome del form', 'db-form-builder'); ?></td></tr>
                        <tr><td><code>{riepilogo_dati}</code></td><td><?php _e('Elenco completo di tutti i campi compilati', 'db-form-builder'); ?></td></tr>
                        <tr><td><code>{nome}</code>, <code>{email}</code>, ecc.</td><td><?php _e('Valore del singolo campo (usa il nome del campo)', 'db-form-builder'); ?></td></tr>
                        <tr><td><code>{ip}</code></td><td><?php _e('Indirizzo IP del visitatore', 'db-form-builder'); ?></td></tr>
                        <tr><td><code>{data}</code></td><td><?php _e('Data e ora dell\'invio', 'db-form-builder'); ?></td></tr>
                        <tr><td><code>{sito}</code></td><td><?php _e('Nome del sito', 'db-form-builder'); ?></td></tr>
                    </tbody>
                </table>
            </div>
            
            <p class="submit">
                <button type="submit" class="button button-primary button-large" id="dbfb-save-global-settings">
                    <?php _e('Salva Impostazioni', 'db-form-builder'); ?>
                </button>
            </p>
        </form>
    </div>
</div>

<style>
.dbfb-settings-section {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}
.dbfb-settings-section h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}
</style>

<script>
jQuery(document).ready(function($) {
    var recaptchaWidgetId = null;
    var recaptchaLoaded = false;
    
    function loadRecaptchaScript(version, siteKey, callback) {
        $('script[src*="recaptcha"]').remove();
        var script = document.createElement('script');
        if (version === 'v3') {
            script.src = 'https://www.google.com/recaptcha/api.js?render=' + siteKey;
        } else {
            script.src = 'https://www.google.com/recaptcha/api.js?onload=dbfbRecaptchaCallback&render=explicit';
        }
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
        if (version === 'v3') script.onload = callback;
    }
    
    window.dbfbRecaptchaCallback = function() {
        recaptchaLoaded = true;
        renderRecaptchaWidget();
    };
    
    function renderRecaptchaWidget() {
        var siteKey = $('#recaptcha_site_key').val();
        var container = document.getElementById('dbfb-recaptcha-test-container');
        if (!siteKey || !container) return;
        container.innerHTML = '';
        if (typeof grecaptcha !== 'undefined' && grecaptcha.render) {
            try {
                recaptchaWidgetId = grecaptcha.render(container, { 'sitekey': siteKey, 'theme': 'light' });
            } catch(e) {
                container.innerHTML = '<p style="color:#d63638;">Errore nel caricamento del widget. Verifica la Site Key.</p>';
            }
        }
    }
    
    function updateTestWidget() {
        var version = $('#recaptcha_version').val();
        var siteKey = $('#recaptcha_site_key').val();
        var container = $('#dbfb-recaptcha-test-container');
        container.html('');
        $('#dbfb-test-result').html('');
        if (!siteKey) {
            container.html('<p style="color:#666;">Inserisci la Site Key per testare</p>');
            return;
        }
        if (version === 'v3') {
            container.html('<p style="color:#666;">reCAPTCHA v3 è invisibile. Clicca "Verifica chiavi" per testare.</p>');
            loadRecaptchaScript('v3', siteKey, function() { recaptchaLoaded = true; });
        } else {
            container.html('<p style="color:#666;">Caricamento widget...</p>');
            loadRecaptchaScript('v2', siteKey, null);
        }
    }
    
    $('#recaptcha_version, #recaptcha_site_key').on('change', function() { setTimeout(updateTestWidget, 100); });
    
    $('#dbfb-test-recaptcha').on('click', function() {
        var $btn = $(this), $result = $('#dbfb-test-result');
        var version = $('#recaptcha_version').val(), siteKey = $('#recaptcha_site_key').val(), secretKey = $('#recaptcha_secret_key').val();
        if (!siteKey || !secretKey) { $result.html('<span style="color:#d63638;">Inserisci entrambe le chiavi</span>'); return; }
        $btn.prop('disabled', true);
        $result.html('<span style="color:#666;">Verifica in corso...</span>');
        var getToken = new Promise(function(resolve, reject) {
            if (version === 'v3') {
                if (typeof grecaptcha !== 'undefined') { grecaptcha.ready(function() { grecaptcha.execute(siteKey, {action: 'test'}).then(resolve).catch(reject); }); } else { reject('grecaptcha non caricato'); }
            } else {
                if (typeof grecaptcha !== 'undefined' && recaptchaWidgetId !== null) { var response = grecaptcha.getResponse(recaptchaWidgetId); if (response) { resolve(response); } else { reject('Completa la verifica "Non sono un robot"'); } } else { reject('Widget non caricato'); }
            }
        });
        getToken.then(function(token) {
            $.post(dbfb.ajax_url, { action: 'dbfb_test_recaptcha', nonce: dbfb.nonce, site_key: siteKey, secret_key: secretKey, version: version, token: token })
            .done(function(response) {
                $result.html('<span style="color:' + (response.success ? '#00a32a' : '#d63638') + ';">' + response.data.message + '</span>');
                if (version === 'v2' && typeof grecaptcha !== 'undefined' && recaptchaWidgetId !== null) grecaptcha.reset(recaptchaWidgetId);
            })
            .fail(function() { $result.html('<span style="color:#d63638;">Errore di connessione</span>'); })
            .always(function() { $btn.prop('disabled', false); });
        }).catch(function(err) { $result.html('<span style="color:#d63638;">' + err + '</span>'); $btn.prop('disabled', false); });
    });
    
    <?php if (!empty($global_settings['recaptcha_site_key'])): ?>
    setTimeout(updateTestWidget, 500);
    <?php endif; ?>
    
    // Test Email
    $('#dbfb-test-email').on('click', function() {
        var $btn = $(this), $result = $('#dbfb-test-email-result');
        var toEmail = $('#dbfb-test-email-address').val();
        var fromName = $('#from_name').val() || '<?php echo esc_js(get_bloginfo('name')); ?>';
        var fromEmail = $('#from_email').val() || '<?php echo esc_js(get_option('admin_email')); ?>';
        if (!toEmail) { $result.html('<span style="color:#d63638;">Inserisci un indirizzo email</span>'); return; }
        $btn.prop('disabled', true);
        $result.html('<span style="color:#666;">Invio in corso...</span>');
        $.post(dbfb.ajax_url, { action: 'dbfb_test_email', nonce: dbfb.nonce, to_email: toEmail, from_name: fromName, from_email: fromEmail })
        .done(function(response) { $result.html('<span style="color:' + (response.success ? '#00a32a' : '#d63638') + ';">' + response.data.message + '</span>'); })
        .fail(function() { $result.html('<span style="color:#d63638;">Errore di connessione</span>'); })
        .always(function() { $btn.prop('disabled', false); });
    });
    
    // Save settings
    $('#dbfb-global-settings-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#dbfb-save-global-settings');
        $btn.prop('disabled', true).text('Salvataggio...');
        $.post(dbfb.ajax_url, {
            action: 'dbfb_save_global_settings', nonce: dbfb.nonce,
            recaptcha_version: $('#recaptcha_version').val(), recaptcha_site_key: $('#recaptcha_site_key').val(),
            recaptcha_secret_key: $('#recaptcha_secret_key').val(), from_email: $('#from_email').val(), from_name: $('#from_name').val()
        })
        .done(function(response) {
            if (response.success) {
                var $notice = $('<div class="dbfb-notice" style="margin-bottom:20px;">' + response.data.message + '</div>');
                $('.dbfb-settings-page').prepend($notice);
                setTimeout(function() { $notice.fadeOut(300, function() { $(this).remove(); }); }, 3000);
            } else { alert(response.data.message || 'Errore'); }
        })
        .fail(function() { alert('Errore durante il salvataggio'); })
        .always(function() { $btn.prop('disabled', false).text('Salva Impostazioni'); });
    });
});
</script>
