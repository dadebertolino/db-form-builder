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
    
    <?php if (isset($_GET['duplicated'])): ?>
        <div class="dbfb-notice"><?php _e('Form duplicato con successo.', 'db-form-builder'); ?></div>
    <?php endif; ?>

    <?php
    // 2.18.0: esito dell'import (letto una sola volta).
    $import_notice = isset($_GET['dbfb_import']) ? DBFB_Import_Export::pop_notice() : null;
    if ($import_notice):
        if ($import_notice['type'] === 'error'): ?>
            <div class="notice notice-error"><p><?php echo esc_html($import_notice['message']); ?></p></div>
        <?php else:
            $imported_id = (int) $import_notice['form_id']; ?>
            <div class="notice notice-success">
                <p>
                    <?php
                    printf(
                        /* translators: %s: titolo del form importato */
                        esc_html__('Form "%s" importato.', 'db-form-builder'),
                        esc_html(get_the_title($imported_id))
                    );
                    ?>
                    <?php _e('Nuovo shortcode da usare nelle pagine:', 'db-form-builder'); ?>
                    <code>[dbfb_form id="<?php echo $imported_id; ?>"]</code>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dbfb-forms&action=edit&form_id=' . $imported_id)); ?>"><?php _e('Apri il form', 'db-form-builder'); ?></a>
                </p>
                <?php if (!empty($import_notice['warnings'])): ?>
                    <ul style="list-style:disc;margin-left:20px">
                        <?php foreach ($import_notice['warnings'] as $warning): ?>
                            <li><?php echo esc_html($warning); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif;
    endif; ?>

    <?php // 2.18.0: importa un form da file ?>
    <details class="dbfb-import-box">
        <summary><?php _e('Importa un form da file', 'db-form-builder'); ?></summary>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="dbfb_import_form">
            <?php wp_nonce_field('dbfb_import_form'); ?>
            <p>
                <label for="dbfb-import-file"><?php _e('File .json esportato da DB Form Builder', 'db-form-builder'); ?></label><br>
                <input type="file" id="dbfb-import-file" name="dbfb_import_file" accept=".json,application/json" required>
            </p>
            <p class="description"><?php _e('Viene creato un nuovo form, con un nuovo shortcode: nessun form esistente viene modificato. Gli indirizzi del sito di origine sono riscritti con quelli di questo sito; il webhook arriva disattivato da ricontrollare. Il file non contiene risposte né dati personali.', 'db-form-builder'); ?></p>
            <p><button type="submit" class="button button-primary"><?php _e('Importa', 'db-form-builder'); ?></button></p>
        </form>
    </details>
    
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
                            <a href="<?php echo esc_url(DBFB_Import_Export::export_url($form->ID)); ?>"
                               title="<?php esc_attr_e('Scarica la configurazione del form come file .json, da importare su un altro sito', 'db-form-builder'); ?>">
                                <?php _e('Esporta', 'db-form-builder'); ?>
                            </a>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=dbfb-forms&action=duplicate&form_id=' . $form->ID), 'dbfb_duplicate_' . $form->ID); ?>"
                               title="<?php _e('Crea una copia di questo form', 'db-form-builder'); ?>">
                                <?php _e('Duplica', 'db-form-builder'); ?>
                            </a>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=dbfb-forms&action=delete&form_id=' . $form->ID), 'dbfb_delete_' . $form->ID); ?>" 
                               onclick="return confirm('<?php echo esc_js(__('Sei sicuro di voler eliminare questo form e tutte le sue risposte? Questa azione è irreversibile.', 'db-form-builder')); ?>');"
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
