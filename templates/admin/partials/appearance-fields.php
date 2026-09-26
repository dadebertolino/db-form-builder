<?php
/**
 * Campi "Aspetto" (2.12.0): sfondo, pulsanti, testo + anteprima e avviso
 * di contrasto. Usato da Impostazioni (globale) e dall'editor del form.
 *
 * Variabili attese:
 * @var string $appearance_prefix   Prefisso degli id degli input (es. 'dbfb-' o '').
 * @var array  $appearance_values   Valori correnti {color_bg, color_primary, color_text}.
 * @var array  $appearance_inherit  Valori ereditati se il campo è vuoto (globali per il form, [] per il globale).
 * @var string $appearance_empty    Testo che spiega cosa significa lasciare vuoto.
 */
if (!defined('ABSPATH')) exit;

$appearance_fields = array(
    'color_bg'      => __('Colore di sfondo', 'db-form-builder'),
    'color_primary' => __('Colore dei pulsanti', 'db-form-builder'),
    'color_text'    => __('Colore del testo', 'db-form-builder'),
);
?>
<div class="dbfb-appearance"
     data-inherit-bg="<?php echo esc_attr($appearance_inherit['color_bg'] ?? ''); ?>"
     data-inherit-primary="<?php echo esc_attr($appearance_inherit['color_primary'] ?? ''); ?>"
     data-inherit-text="<?php echo esc_attr($appearance_inherit['color_text'] ?? ''); ?>">

    <div class="dbfb-appearance-fields">
        <?php foreach ($appearance_fields as $key => $label):
            $input_id = $appearance_prefix . str_replace('_', '-', $key); ?>
            <div class="dbfb-appearance-field">
                <label for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($label); ?></label>
                <input type="text"
                       id="<?php echo esc_attr($input_id); ?>"
                       class="dbfb-color-input"
                       data-color-key="<?php echo esc_attr(str_replace('color_', '', $key)); ?>"
                       value="<?php echo esc_attr($appearance_values[$key] ?? ''); ?>">
            </div>
        <?php endforeach; ?>
    </div>

    <p class="description"><?php echo esc_html($appearance_empty); ?></p>
    <p class="description">
        <?php _e('Il colore del testo dei pulsanti (bianco o nero) e quello al passaggio del mouse vengono calcolati automaticamente per garantire il contrasto minimo WCAG AA.', 'db-form-builder'); ?>
    </p>

    <div class="dbfb-appearance-preview" aria-hidden="true">
        <span class="dbfb-appearance-preview-label"><?php _e('Etichetta di esempio', 'db-form-builder'); ?></span>
        <span class="dbfb-appearance-preview-input"><?php _e('Campo di testo', 'db-form-builder'); ?></span>
        <span class="dbfb-appearance-preview-text">
            <?php _e('Testo con', 'db-form-builder'); ?>
            <span class="dbfb-appearance-preview-link"><?php _e('un link', 'db-form-builder'); ?></span>
        </span>
        <span class="dbfb-appearance-preview-button"><?php _e('Invia', 'db-form-builder'); ?></span>
    </div>

    <div class="dbfb-appearance-warning" role="status" aria-live="polite"></div>
</div>
