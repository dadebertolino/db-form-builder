<?php if (!defined('ABSPATH')) exit; ?>

<div class="dbfb-wrap">
    <div class="dbfb-header">
        <h1><?php _e('Form Builder', 'db-form-builder'); ?></h1>
        <a href="<?php echo admin_url('admin.php?page=dbfb-new-form'); ?>" class="button button-primary">
            <?php _e('Nuovo Form', 'db-form-builder'); ?>
        </a>
    </div>
    
    <?php if (isset($_GET['deleted'])): ?>
        <div class="dbfb-notice"><?php _e('Form eliminato con successo.', 'db-form-builder'); ?></div>
    <?php endif; ?>
    
    <?php if (empty($forms)): ?>
        <div class="dbfb-empty">
            <p><?php _e('Nessun form creato. Crea il tuo primo form!', 'db-form-builder'); ?></p>
        </div>
    <?php else: ?>
        <table class="dbfb-forms-table">
            <thead>
                <tr>
                    <th><?php _e('Titolo', 'db-form-builder'); ?></th>
                    <th><?php _e('Shortcode', 'db-form-builder'); ?></th>
                    <th><?php _e('Data', 'db-form-builder'); ?></th>
                    <th><?php _e('Azioni', 'db-form-builder'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $form): ?>
                    <tr>
                        <td>
                            <strong>
                                <a href="<?php echo admin_url('admin.php?page=dbfb-forms&action=edit&form_id=' . $form->ID); ?>">
                                    <?php echo esc_html($form->post_title); ?>
                                </a>
                            </strong>
                        </td>
                        <td>
                            <code class="shortcode">[dbfb_form id="<?php echo $form->ID; ?>"]</code>
                        </td>
                        <td>
                            <?php echo get_the_date('d/m/Y H:i', $form); ?>
                        </td>
                        <td class="actions">
                            <a href="<?php echo admin_url('admin.php?page=dbfb-forms&action=edit&form_id=' . $form->ID); ?>">
                                <?php _e('Modifica', 'db-form-builder'); ?>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=dbfb-forms&action=submissions&form_id=' . $form->ID); ?>">
                                <?php _e('Risposte', 'db-form-builder'); ?>
                            </a>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=dbfb-forms&action=delete&form_id=' . $form->ID), 'dbfb_delete_' . $form->ID); ?>" 
                               onclick="return confirm('<?php _e('Sei sicuro di voler eliminare questo form?', 'db-form-builder'); ?>');"
                               style="color: #d63638;">
                                <?php _e('Elimina', 'db-form-builder'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
