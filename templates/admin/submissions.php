<?php if (!defined('ABSPATH')) exit; ?>

<div class="dbfb-wrap">
    <div class="dbfb-header">
        <h1><?php printf(__('Risposte: %s', 'db-form-builder'), esc_html($form->post_title)); ?></h1>
        <div>
            <a href="<?php echo admin_url('admin.php?page=dbfb-forms'); ?>" class="button">
                &larr; <?php _e('Tutti i Form', 'db-form-builder'); ?>
            </a>
            <?php if (!empty($submissions)): ?>
                <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=dbfb_export_csv&form_id=' . $form_id), 'dbfb_nonce', 'nonce'); ?>" 
                   class="button button-primary">
                    <?php _e('Esporta CSV', 'db-form-builder'); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (isset($_GET['sub_deleted'])): ?>
        <div class="dbfb-notice">
            <?php 
            $count = intval($_GET['sub_deleted']);
            printf(_n('%d risposta eliminata.', '%d risposte eliminate.', $count, 'db-form-builder'), $count);
            ?>
        </div>
    <?php endif; ?>
    
    <?php if (empty($submissions)): ?>
        <div class="dbfb-empty">
            <p><?php _e('Nessuna risposta ricevuta per questo form.', 'db-form-builder'); ?></p>
        </div>
    <?php else: ?>
        <p>
            <?php printf(
                _n('%d risposta ricevuta', '%d risposte ricevute', count($submissions), 'db-form-builder'),
                count($submissions)
            ); ?>
        </p>
        
        <form method="post" action="<?php echo admin_url('admin.php?page=dbfb-forms'); ?>" id="dbfb-submissions-form">
            <?php wp_nonce_field('dbfb_bulk_submissions'); ?>
            <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">
            
            <div class="dbfb-bulk-actions" style="margin-bottom: 10px; display: flex; gap: 10px; align-items: center; flex-wrap:wrap;">
                <label>
                    <input type="checkbox" id="dbfb-select-all"> 
                    <?php _e('Seleziona tutti', 'db-form-builder'); ?>
                </label>
                <button type="submit" name="dbfb_bulk_action" value="delete" class="button" id="dbfb-bulk-delete" style="display:none;"
                        onclick="return confirm('<?php _e('Eliminare le risposte selezionate? Azione irreversibile.', 'db-form-builder'); ?>');">
                    <?php _e('Elimina selezionate', 'db-form-builder'); ?>
                </button>

                <?php // GDPR right of erasure (art. 17): cancellazione di massa per il form intero. ?>
                <button type="submit" name="dbfb_bulk_action" value="delete_all" class="button"
                        style="margin-left:auto;color:#a00"
                        onclick="return confirm('<?php
                            printf(
                                /* translators: %d: numero submission del form */
                                esc_attr__('Stai per cancellare TUTTE le %d risposte di questo form. L\'operazione è irreversibile e cancella anche gli IP, gli allegati e tutti i dati associati. Continuare?', 'db-form-builder'),
                                count($submissions)
                            );
                        ?>');">
                    🗑️ <?php
                    printf(
                        /* translators: %d: numero submission del form */
                        esc_html(_n('Cancella TUTTE (%d)', 'Cancella TUTTE (%d)', count($submissions), 'db-form-builder')),
                        count($submissions)
                    );
                    ?>
                </button>
            </div>
            
            <?php
            // Snapshot-aware rendering (2.6.0): le colonne dell'header sono
            // l'unione di tutti gli ID di field apparsi in qualsiasi
            // submission (via snapshot) + i field correnti del form. Ogni
            // riga renderizza le sue colonne con la sua label storica.
            $columns = DB_Form_Builder::build_submission_columns($submissions, $form_fields);
            // Mappa di tipo per ID, dai field correnti, usata come fallback
            // per il rendering specifico dei file (che richiede il type).
            $current_types_by_id = array();
            foreach ((array) $form_fields as $f) {
                if (!empty($f['id'])) $current_types_by_id[$f['id']] = $f['type'] ?? 'text';
            }
            ?>
            <table class="dbfb-submissions-table">
                <thead>
                    <tr>
                        <th style="width:30px;"></th>
                        <th><?php _e('ID', 'db-form-builder'); ?></th>
                        <th><?php _e('Data', 'db-form-builder'); ?></th>
                        <?php foreach ($columns as $col): ?>
                            <th><?php echo esc_html($col['label']); ?></th>
                        <?php endforeach; ?>
                        <th><?php _e('IP', 'db-form-builder'); ?></th>
                        <th><?php _e('Azioni', 'db-form-builder'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $submission):
                        // Per ogni submission usiamo il suo snapshot (post-2.6.0)
                        // o i field correnti come fallback (legacy).
                        $info     = DB_Form_Builder::get_submission_fields($submission, $form_fields);
                        $row_data = $info['data'];
                        $row_fields = $info['fields'];
                        // Mappe per lookup veloce dentro il loop colonne.
                        $row_types_by_id = array();
                        $row_labels_by_id = array();
                        foreach ($row_fields as $rf) {
                            $row_types_by_id[$rf['id']]  = $rf['type'];
                            $row_labels_by_id[$rf['id']] = $rf['label'];
                        }
                    ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="submission_ids[]" value="<?php echo $submission->id; ?>" class="dbfb-sub-checkbox">
                            </td>
                            <td><?php echo $submission->id; ?></td>
                            <td><?php echo date_i18n('d/m/Y H:i', strtotime($submission->submitted_at)); ?></td>
                            <?php foreach ($columns as $col):
                                $field_id = $col['id'];
                                // La riga ha realmente questo campo nel suo snapshot?
                                $had_field = isset($row_types_by_id[$field_id]);
                                $value     = $row_data[$field_id] ?? '';
                                // Tipo: prima dallo snapshot della riga, poi fallback ai field correnti.
                                $type      = $row_types_by_id[$field_id] ?? ($current_types_by_id[$field_id] ?? 'text');
                                $display   = '';
                                if ($type === 'file' && !empty($value)) {
                                    if (isset($value['name'])) {
                                        $display = $value['name'];
                                    } elseif (is_array($value)) {
                                        $names = array_map(function($f) { return is_array($f) ? ($f['name'] ?? '') : $f; }, $value);
                                        $display = implode(', ', $names);
                                    }
                                } else {
                                    if (is_array($value)) $value = implode(', ', $value);
                                    $display = $value;
                                }
                                // Cella visivamente differente se la submission NON aveva il campo
                                // (il campo è stato aggiunto dopo, quindi vuoto storicamente).
                                $cell_extra_attr = '';
                                if (!$had_field) {
                                    $cell_extra_attr = ' style="color:#999;font-style:italic" title="' . esc_attr__('Campo aggiunto al form dopo questa submission', 'db-form-builder') . '"';
                                    if ($display === '') $display = '—';
                                }
                            ?>
                                <td<?php echo $cell_extra_attr; ?> title="<?php echo esc_attr(is_array($display) ? '' : $display); ?>">
                                    <?php echo esc_html(mb_strimwidth(is_array($display) ? '' : $display, 0, 50, '...')); ?>
                                </td>
                            <?php endforeach; ?>
                            <?php $ip_info = DB_Form_Builder::format_submission_ip($submission); ?>
                            <td title="<?php echo esc_attr($ip_info['tooltip']); ?>">
                                <code style="font-size:11px;background:#f5f5f5;padding:2px 6px;border-radius:3px"><?php echo esc_html($ip_info['display']); ?></code>
                            </td>
                            <td class="actions" style="white-space: nowrap;">
                                <a href="#" class="dbfb-view-submission" 
                                   data-id="<?php echo $submission->id; ?>"
                                   data-date="<?php echo esc_attr(date_i18n('d/m/Y H:i:s', strtotime($submission->submitted_at))); ?>"
                                   data-ip="<?php echo esc_attr($ip_info['display']); ?>"
                                   data-ip-tooltip="<?php echo esc_attr($ip_info['tooltip']); ?>"
                                   data-fields="<?php echo esc_attr(json_encode($row_data)); ?>"
                                   data-labels="<?php echo esc_attr(json_encode($row_labels_by_id)); ?>"
                                   data-gdpr-given="<?php echo esc_attr( is_null($submission->gdpr_consent_given ?? null) ? '' : (string) (int) $submission->gdpr_consent_given ); ?>"
                                   data-gdpr-text="<?php echo esc_attr( (string) ($submission->gdpr_consent_text ?? '') ); ?>"
                                   data-gdpr-timestamp="<?php echo esc_attr( (string) ($submission->gdpr_consent_timestamp ?? '') ); ?>"
                                   data-gdpr-url="<?php echo esc_attr( (string) ($submission->gdpr_consent_privacy_url ?? '') ); ?>"
                                   data-gdpr-version="<?php echo esc_attr( (string) (int) ($submission->gdpr_consent_policy_version ?? 0) ); ?>">
                                    <?php _e('Dettaglio', 'db-form-builder'); ?>
                                </a>
                                <a href="<?php echo wp_nonce_url(
                                    admin_url('admin.php?page=dbfb-forms&action=delete_submission&submission_id=' . $submission->id . '&form_id=' . $form_id),
                                    'dbfb_delete_sub_' . $submission->id
                                ); ?>"
                                   onclick="return confirm('<?php _e('Eliminare questa risposta?', 'db-form-builder'); ?>');"
                                   style="color: #d63638;">
                                    <?php _e('Elimina', 'db-form-builder'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
    <?php endif; ?>
</div>

<!-- Modale dettaglio risposta (WCAG 2.1 AA: dialog pattern) -->
<div id="dbfb-submission-modal" role="dialog" aria-modal="true" aria-labelledby="dbfb-modal-title" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:100000;">
    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:8px; padding:25px; max-width:600px; width:90%; max-height:80vh; overflow-y:auto; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
            <h3 style="margin:0;" id="dbfb-modal-title"><?php _e('Dettaglio Risposta', 'db-form-builder'); ?></h3>
            <button type="button" id="dbfb-modal-close" aria-label="<?php _e('Chiudi', 'db-form-builder'); ?>" style="background:none; border:none; font-size:24px; cursor:pointer; color:#666; min-width:44px; min-height:44px;">&times;</button>
        </div>
        <div id="dbfb-modal-content"></div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Select all
    $('#dbfb-select-all').on('change', function() {
        $('.dbfb-sub-checkbox').prop('checked', this.checked);
        toggleBulkDelete();
    });
    
    $(document).on('change', '.dbfb-sub-checkbox', toggleBulkDelete);
    
    function toggleBulkDelete() {
        var checked = $('.dbfb-sub-checkbox:checked').length;
        $('#dbfb-bulk-delete').toggle(checked > 0);
        if (checked > 0) {
            $('#dbfb-bulk-delete').text('<?php _e('Elimina selezionate', 'db-form-builder'); ?> (' + checked + ')');
        }
    }
    
    // Modale dettaglio (WCAG: focus trap, Escape, restore focus)
    var $lastFocused = null;
    
    $(document).on('click', '.dbfb-view-submission', function(e) {
        e.preventDefault();
        $lastFocused = $(this);
        var $link = $(this);
        var fields = JSON.parse($link.attr('data-fields'));
        var labels = JSON.parse($link.attr('data-labels'));
        
        var html = '<table class="widefat" style="border:0;">';
        html += '<tr><th style="width:35%;"><?php _e('Data', 'db-form-builder'); ?></th><td>' + $link.data('date') + '</td></tr>';
        var ipTooltip = $link.attr('data-ip-tooltip') || '';
        var ipCell = '<code style="font-size:11px;background:#f5f5f5;padding:2px 6px;border-radius:3px">' + ($link.data('ip') || '') + '</code>';
        if (ipTooltip) ipCell += ' <span style="color:#888;font-size:11px;margin-left:6px" title="' + ipTooltip.replace(/"/g, '&quot;') + '">ⓘ</span>';
        html += '<tr><th><?php _e('IP', 'db-form-builder'); ?></th><td>' + ipCell + '</td></tr>';

        // 2.11.0: blocco "Consenso GDPR" — solo se documentato
        var gdprGiven     = $link.attr('data-gdpr-given');
        var gdprText      = $link.attr('data-gdpr-text') || '';
        var gdprTimestamp = $link.attr('data-gdpr-timestamp') || '';
        var gdprUrl       = $link.attr('data-gdpr-url') || '';
        var gdprVersion   = parseInt($link.attr('data-gdpr-version') || '0', 10);
        var consentBlock = '';
        if (gdprGiven === '1') {
            consentBlock += '<tr style="background:#f0fdf4"><th colspan="2" style="padding-top:14px;border-top:2px solid #16a34a;color:#166534">' + '<?php _e('✓ Consenso GDPR documentato', 'db-form-builder'); ?>' + '</th></tr>';
            consentBlock += '<tr><th><?php _e('Testo letto', 'db-form-builder'); ?></th><td>' + $('<div>').text(gdprText).html() + '</td></tr>';
            consentBlock += '<tr><th><?php _e('Timestamp consenso', 'db-form-builder'); ?></th><td><code>' + gdprTimestamp + '</code></td></tr>';
            if (gdprUrl) {
                consentBlock += '<tr><th><?php _e('Privacy Policy linkata', 'db-form-builder'); ?></th><td><a href="' + gdprUrl + '" target="_blank" rel="noopener">' + gdprUrl + '</a></td></tr>';
            }
            if (gdprVersion > 0) {
                consentBlock += '<tr><th><?php _e('Versione documento', 'db-form-builder'); ?></th><td><code>v#' + gdprVersion + '</code> ' + '<span style="color:#666;font-size:12px"><?php _e('(snapshot Privacy Hub)', 'db-form-builder'); ?></span></td></tr>';
            }
        } else if (gdprGiven === '0') {
            consentBlock += '<tr style="background:#fef3c7"><th colspan="2" style="padding-top:14px;border-top:2px solid #d97706;color:#92400e">' + '<?php _e('⚠ Form senza checkbox di consenso (scelta intenzionale)', 'db-form-builder'); ?></th></tr>';
            consentBlock += '<tr><th><?php _e('Nota', 'db-form-builder'); ?></th><td>' + $('<div>').text(gdprText).html() + '</td></tr>';
            if (gdprTimestamp) {
                consentBlock += '<tr><th><?php _e('Timestamp', 'db-form-builder'); ?></th><td><code>' + gdprTimestamp + '</code></td></tr>';
            }
        } else {
            consentBlock += '<tr style="background:#fef2f2"><th colspan="2" style="padding-top:14px;border-top:2px solid #dc2626;color:#991b1b">' + '<?php _e('✗ Consenso GDPR non documentato', 'db-form-builder'); ?></th></tr>';
            consentBlock += '<tr><td colspan="2" style="font-size:13px;color:#666"><?php _e('Submission inserita prima dell\'aggiornamento alla 2.11.0 oppure form privo di checkbox di consenso senza dichiarazione consapevole. La conformità GDPR per questa specifica submission non è documentabile.', 'db-form-builder'); ?></td></tr>';
        }
        html += consentBlock;
        
        for (var key in labels) {
            var value = fields[key] || '';
            var cellHtml = '';
            
            // Escape sicuro degli URL: solo http/https, altri schemi (javascript:,
            // data:) neutralizzati per prevenire XSS via href.
            var safeUrl = function(u) {
                u = String(u == null ? '' : u);
                if (/^https?:\/\//i.test(u)) {
                    return u.replace(/"/g, '%22').replace(/</g, '%3C').replace(/>/g, '%3E');
                }
                return '#';
            };

            // Check if value is a file object or array of file objects
            if (value && typeof value === 'object' && value.url) {
                // Single file
                cellHtml = '<a href="' + safeUrl(value.url) + '" target="_blank" rel="noopener">📎 ' + $('<span>').text(value.name).html() + '</a>';
            } else if (Array.isArray(value) && value.length && value[0] && typeof value[0] === 'object' && value[0].url) {
                // Multiple files
                cellHtml = value.map(function(f) {
                    return '<a href="' + safeUrl(f.url) + '" target="_blank" rel="noopener">📎 ' + $('<span>').text(f.name).html() + '</a>';
                }).join('<br>');
            } else {
                if (Array.isArray(value)) value = value.join(', ');
                cellHtml = $('<div>').text(value).html();
            }

            // Label escapata (difesa in profondità: già sanitize_text_field al salvataggio).
            var safeLabel = $('<span>').text(labels[key]).html();
            html += '<tr><th>' + safeLabel + '</th><td style="word-break:break-word;">' + cellHtml + '</td></tr>';
        }
        html += '</table>';
        
        $('#dbfb-modal-title').text('<?php _e('Risposta', 'db-form-builder'); ?> #' + $link.data('id'));
        $('#dbfb-modal-content').html(html);
        $('#dbfb-submission-modal').fadeIn(200);
        
        // Focus the close button (WCAG 2.4.3)
        setTimeout(function() { $('#dbfb-modal-close').trigger('focus'); }, 250);
    });
    
    function closeModal() {
        $('#dbfb-submission-modal').fadeOut(200);
        // Restore focus to trigger element (WCAG 2.4.3)
        if ($lastFocused && $lastFocused.length) {
            setTimeout(function() { $lastFocused.trigger('focus'); }, 250);
        }
    }
    
    $('#dbfb-modal-close').on('click', closeModal);
    
    $('#dbfb-submission-modal').on('click', function(e) {
        if (e.target === this) closeModal();
    });
    
    // Escape key closes modal (WCAG 2.1.1)
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#dbfb-submission-modal').is(':visible')) {
            closeModal();
        }
    });
    
    // Focus trap inside modal (WCAG 2.4.3)
    $('#dbfb-submission-modal').on('keydown', function(e) {
        if (e.key !== 'Tab') return;
        var $focusable = $(this).find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
        var $first = $focusable.first();
        var $last = $focusable.last();
        
        if (e.shiftKey && document.activeElement === $first[0]) {
            e.preventDefault();
            $last.trigger('focus');
        } else if (!e.shiftKey && document.activeElement === $last[0]) {
            e.preventDefault();
            $first.trigger('focus');
        }
    });
});
</script>
