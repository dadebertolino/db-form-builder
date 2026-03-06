<?php if (!defined('ABSPATH')) exit; ?>

<div class="dbfb-wrap">
    <div class="dbfb-header">
        <h1><?php _e('Risposte', 'db-form-builder'); ?></h1>
    </div>
    
    <?php if (empty($forms)): ?>
        <div class="dbfb-empty">
            <p><?php _e('Nessun form creato.', 'db-form-builder'); ?></p>
        </div>
    <?php else: ?>
        <p><?php _e('Seleziona un form per visualizzare le risposte:', 'db-form-builder'); ?></p>
        
        <table class="dbfb-forms-table">
            <thead>
                <tr>
                    <th><?php _e('Form', 'db-form-builder'); ?></th>
                    <th><?php _e('Risposte', 'db-form-builder'); ?></th>
                    <th><?php _e('Azioni', 'db-form-builder'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $form): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($form->post_title); ?></strong>
                        </td>
                        <td>
                            <span class="dbfb-count <?php echo $counts[$form->ID] > 0 ? 'has-data' : ''; ?>">
                                <?php echo intval($counts[$form->ID]); ?>
                            </span>
                        </td>
                        <td class="actions">
                            <?php if ($counts[$form->ID] > 0): ?>
                                <a href="<?php echo admin_url('admin.php?page=dbfb-submissions&form_id=' . $form->ID); ?>">
                                    <?php _e('Visualizza', 'db-form-builder'); ?>
                                </a>
                                <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=dbfb_export_csv&form_id=' . $form->ID), 'dbfb_nonce', 'nonce'); ?>">
                                    <?php _e('Esporta CSV', 'db-form-builder'); ?>
                                </a>
                            <?php else: ?>
                                <span style="color: #999;"><?php _e('Nessuna risposta', 'db-form-builder'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<style>
.dbfb-count {
    display: inline-block;
    min-width: 30px;
    padding: 3px 10px;
    background: #f0f0f0;
    border-radius: 12px;
    text-align: center;
    font-weight: 500;
}
.dbfb-count.has-data {
    background: #d4edda;
    color: #155724;
}
</style>
