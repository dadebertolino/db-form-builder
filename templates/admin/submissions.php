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
            
            <div class="dbfb-bulk-actions" style="margin-bottom: 10px; display: flex; gap: 10px; align-items: center;">
                <label>
                    <input type="checkbox" id="dbfb-select-all"> 
                    <?php _e('Seleziona tutti', 'db-form-builder'); ?>
                </label>
                <button type="submit" name="dbfb_bulk_action" value="delete" class="button" id="dbfb-bulk-delete" style="display:none;"
                        onclick="return confirm('<?php _e('Eliminare le risposte selezionate? Azione irreversibile.', 'db-form-builder'); ?>');">
                    <?php _e('Elimina selezionate', 'db-form-builder'); ?>
                </button>
            </div>
            
            <table class="dbfb-submissions-table">
                <thead>
                    <tr>
                        <th style="width:30px;"></th>
                        <th><?php _e('ID', 'db-form-builder'); ?></th>
                        <th><?php _e('Data', 'db-form-builder'); ?></th>
                        <?php foreach ($form_fields as $field): ?>
                            <th><?php echo esc_html($field['label']); ?></th>
                        <?php endforeach; ?>
                        <th><?php _e('IP', 'db-form-builder'); ?></th>
                        <th><?php _e('Azioni', 'db-form-builder'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $submission): 
                        $data = json_decode($submission->data, true);
                    ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="submission_ids[]" value="<?php echo $submission->id; ?>" class="dbfb-sub-checkbox">
                            </td>
                            <td><?php echo $submission->id; ?></td>
                            <td><?php echo date_i18n('d/m/Y H:i', strtotime($submission->submitted_at)); ?></td>
                            <?php foreach ($form_fields as $field): 
                                $value = isset($data[$field['id']]) ? $data[$field['id']] : '';
                                if (is_array($value)) $value = implode(', ', $value);
                            ?>
                                <td title="<?php echo esc_attr($value); ?>">
                                    <?php echo esc_html(mb_strimwidth($value, 0, 50, '...')); ?>
                                </td>
                            <?php endforeach; ?>
                            <td><?php echo esc_html($submission->ip_address); ?></td>
                            <td class="actions" style="white-space: nowrap;">
                                <a href="#" class="dbfb-view-submission" 
                                   data-id="<?php echo $submission->id; ?>"
                                   data-date="<?php echo esc_attr(date_i18n('d/m/Y H:i:s', strtotime($submission->submitted_at))); ?>"
                                   data-ip="<?php echo esc_attr($submission->ip_address); ?>"
                                   data-fields="<?php echo esc_attr(json_encode($data)); ?>"
                                   data-labels="<?php echo esc_attr(json_encode(array_column($form_fields, 'label', 'id'))); ?>">
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
        html += '<tr><th><?php _e('IP', 'db-form-builder'); ?></th><td>' + $link.data('ip') + '</td></tr>';
        
        for (var key in labels) {
            var value = fields[key] || '';
            if (Array.isArray(value)) value = value.join(', ');
            html += '<tr><th>' + labels[key] + '</th><td style="word-break:break-word;">' + $('<div>').text(value).html() + '</td></tr>';
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
