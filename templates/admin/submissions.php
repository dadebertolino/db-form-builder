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
        
        <table class="dbfb-submissions-table">
            <thead>
                <tr>
                    <th><?php _e('ID', 'db-form-builder'); ?></th>
                    <th><?php _e('Data', 'db-form-builder'); ?></th>
                    <?php foreach ($form_fields as $field): ?>
                        <th><?php echo esc_html($field['label']); ?></th>
                    <?php endforeach; ?>
                    <th><?php _e('IP', 'db-form-builder'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($submissions as $submission): 
                    $data = json_decode($submission->data, true);
                ?>
                    <tr>
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
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
