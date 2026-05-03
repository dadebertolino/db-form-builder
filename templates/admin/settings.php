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
                <h2><?php _e('Privacy e dati personali', 'db-form-builder'); ?></h2>
                <p class="description">
                    <?php _e('Configurazione del trattamento privacy delle submission. Vedi anche il pannello "Privacy SEO" del DB SEO Manager (se installato) per il registro completo dei trattamenti.', 'db-form-builder'); ?>
                </p>

                <table class="form-table">
                    <tr>
                        <th><label for="ip_storage_mode"><?php _e('Modalità salvataggio IP', 'db-form-builder'); ?></label></th>
                        <td>
                            <?php $current_mode = $global_settings['ip_storage_mode'] ?? 'hashed'; ?>
                            <select id="ip_storage_mode" name="ip_storage_mode">
                                <option value="hashed" <?php selected($current_mode, 'hashed'); ?>>
                                    <?php _e('Hash SHA-256 (raccomandato)', 'db-form-builder'); ?>
                                </option>
                                <option value="none" <?php selected($current_mode, 'none'); ?>>
                                    <?php _e('Non salvare IP', 'db-form-builder'); ?>
                                </option>
                                <option value="full" <?php selected($current_mode, 'full'); ?>>
                                    <?php _e('IP in chiaro (sconsigliato)', 'db-form-builder'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php _e('Hash SHA-256 con salt: irreversibile in pratica, ma consente di riconoscere "stesso visitatore" per il rate limiting. Il rate limiting funziona anche con "Non salvare IP". Modificare la modalità impatta solo le NUOVE submission: quelle esistenti restano invariate.', 'db-form-builder'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="submissions_retention_days"><?php _e('Retention submission (giorni)', 'db-form-builder'); ?></label></th>
                        <td>
                            <?php $retention_days = (int) ($global_settings['submissions_retention_days'] ?? 365); ?>
                            <input type="number" id="submissions_retention_days" name="submissions_retention_days"
                                   min="0" max="3650" step="1"
                                   value="<?php echo esc_attr($retention_days); ?>"
                                   class="small-text">
                            <span class="description" style="margin-left:8px"><?php _e('giorni (0 = illimitato)', 'db-form-builder'); ?></span>
                            <p class="description">
                                <?php _e('Le submission più vecchie di N giorni vengono cancellate automaticamente da un cron giornaliero. Default 365. Per il GDPR (art. 5.1.e "limitazione della conservazione") i dati personali devono essere conservati solo per il tempo necessario alla finalità del trattamento.', 'db-form-builder'); ?>
                            </p>
                            <?php
                            // Conta quante submission verrebbero cancellate adesso (preview).
                            global $wpdb;
                            $table = $wpdb->prefix . 'dbfb_submissions';
                            $count_expired = 0;
                            if ($retention_days > 0 && $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                                $count_expired = (int) $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(*) FROM $table WHERE submitted_at < (NOW() - INTERVAL %d DAY)",
                                    $retention_days
                                ));
                            }
                            $next_run = wp_next_scheduled('dbfb_cleanup_submissions');
                            ?>
                            <p style="margin-top:10px">
                                <?php if ($retention_days === 0) : ?>
                                    <span style="color:#856404;background:#fff3cd;padding:6px 10px;border-radius:3px;display:inline-block">
                                        ⚠️ <?php _e('Retention illimitata: le submission non verranno mai cancellate automaticamente. Sconsigliato per GDPR.', 'db-form-builder'); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="description">
                                        <?php
                                        if ($count_expired > 0) {
                                            printf(
                                                /* translators: %d: numero submission scadute */
                                                esc_html(_n(
                                                    '%d submission scadut%s e in attesa di cancellazione.',
                                                    '%d submission scadut%s e in attesa di cancellazione.',
                                                    $count_expired,
                                                    'db-form-builder'
                                                )),
                                                (int) $count_expired,
                                                $count_expired === 1 ? 'a' : 'e'
                                            );
                                        } else {
                                            esc_html_e('Nessuna submission scaduta da cancellare.', 'db-form-builder');
                                        }
                                        if ($next_run) {
                                            echo ' ';
                                            printf(
                                                /* translators: %s: orario prossima esecuzione */
                                                esc_html__('Prossima esecuzione cron: %s', 'db-form-builder'),
                                                esc_html(date_i18n('d/m/Y H:i', $next_run))
                                            );
                                        }
                                        ?>
                                    </span>
                                    <?php if ($count_expired > 0) : ?>
                                        <button type="button" class="button" id="dbfb-cleanup-now" style="margin-left:8px">
                                            <?php esc_html_e('Pulisci ora', 'db-form-builder'); ?>
                                        </button>
                                        <span id="dbfb-cleanup-result" style="margin-left:8px"></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Trust dei proxy header', 'db-form-builder'); ?></th>
                        <td>
                            <p style="margin-top:0">
                                <?php _e('Per default ignoriamo X-Forwarded-For, CF-Connecting-IP, X-Real-IP — possono essere falsificati e inquinare il rate limit. Se il sito è dietro un proxy/CDN affidabile (Cloudflare, Varnish), aggiungi questo filter al tuo theme functions.php:', 'db-form-builder'); ?>
                            </p>
                            <code style="display:block;padding:10px;background:#f5f5f5;border:1px solid #ddd;margin-top:6px">add_filter('dbfb_trust_proxy_headers', '__return_true');</code>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="delete_data_on_uninstall"><?php _e('Cancellazione dati alla disinstallazione', 'db-form-builder'); ?></label></th>
                        <td>
                            <?php $delete_on_uninstall = !empty($global_settings['delete_data_on_uninstall']); ?>
                            <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                                <input type="checkbox" id="delete_data_on_uninstall" name="delete_data_on_uninstall" value="1"
                                       <?php checked($delete_on_uninstall); ?>>
                                <span>
                                    <?php _e('Cancella tutti i dati del plugin quando viene disinstallato.', 'db-form-builder'); ?>
                                </span>
                            </label>
                            <p class="description" style="margin-top:8px">
                                <?php _e('Per default, disinstallare il plugin (pulsante "Elimina" di WordPress) NON cancella né i form definiti né le submission ricevute — così se reinstalli per qualsiasi motivo (debug, switch versione, migrazione hosting) ritrovi tutto al suo posto.', 'db-form-builder'); ?>
                            </p>
                            <?php if ($delete_on_uninstall) : ?>
                                <div style="margin-top:10px;padding:10px 14px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:3px">
                                    <strong>⚠️ <?php esc_html_e('Attenzione: opzione attiva', 'db-form-builder'); ?></strong>
                                    <p style="margin:6px 0 0">
                                        <?php _e('Quando disinstallerai il plugin, verranno cancellati DEFINITIVAMENTE: tutti i form definiti, tutte le submission ricevute (incluse PII degli utenti), tutti i file allegati delle submission dal disco, e il registro delle impostazioni globali. L\'operazione è IRREVERSIBILE.', 'db-form-builder'); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

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
                        <tr><td><code>{ip}</code></td><td><?php _e('Indirizzo IP del visitatore (rispetta la modalità "Salvataggio IP" sopra: hash, vuoto, o IP in chiaro)', 'db-form-builder'); ?></td></tr>
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

    // Cleanup submissions ora (2.3.0)
    $('#dbfb-cleanup-now').on('click', function() {
        var $btn = $(this);
        var $result = $('#dbfb-cleanup-result');
        if (!confirm(<?php echo wp_json_encode(__('Cancellare ora le submission scadute? Questa operazione è irreversibile.', 'db-form-builder')); ?>)) {
            return;
        }
        $btn.prop('disabled', true).text(<?php echo wp_json_encode(__('Pulizia in corso...', 'db-form-builder')); ?>);
        $result.text('').css('color', '');
        $.post(dbfb.ajax_url, { action: 'dbfb_cleanup_now', nonce: dbfb.nonce })
            .done(function(response) {
                if (response.success) {
                    $result.css('color', '#2e7d32').text('✓ ' + response.data.message);
                    // Reload dopo 1.5s per aggiornare il counter scadute.
                    setTimeout(function() { window.location.reload(); }, 1500);
                } else {
                    $result.css('color', '#c62828').text('✗ ' + (response.data && response.data.message ? response.data.message : 'Errore'));
                    $btn.prop('disabled', false).text(<?php echo wp_json_encode(__('Pulisci ora', 'db-form-builder')); ?>);
                }
            })
            .fail(function() {
                $result.css('color', '#c62828').text('✗ ' + <?php echo wp_json_encode(__('Errore di connessione', 'db-form-builder')); ?>);
                $btn.prop('disabled', false).text(<?php echo wp_json_encode(__('Pulisci ora', 'db-form-builder')); ?>);
            });
    });
});
</script>
