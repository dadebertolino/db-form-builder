<?php
if (!defined('ABSPATH')) exit;

class DBFB_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'dbfb_widget',
            __('DB Form Builder', 'db-form-builder'),
            ['description' => __('Inserisci un form nel widget', 'db-form-builder')]
        );
    }

    public function widget($args, $instance) {
        // before_/after_widget e before_/after_title sono markup fornito dal tema.
        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        if (!empty($instance['title'])) {
            echo $args['before_title'] . esc_html(apply_filters('widget_title', $instance['title'])) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        if (!empty($instance['form_id'])) {
            echo do_shortcode('[dbfb_form id="' . intval($instance['form_id']) . '"]');
        }

        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $form_id = !empty($instance['form_id']) ? $instance['form_id'] : '';

        $forms = get_posts([
            'post_type' => 'dbfb_form',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('Titolo:', 'db-form-builder'); ?>
            </label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                   type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('form_id')); ?>">
                <?php esc_html_e('Seleziona Form:', 'db-form-builder'); ?>
            </label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('form_id')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('form_id')); ?>">
                <option value=""><?php esc_html_e('-- Seleziona --', 'db-form-builder'); ?></option>
                <?php foreach ($forms as $form): ?>
                    <option value="<?php echo (int) $form->ID; ?>" <?php selected($form_id, $form->ID); ?>>
                        <?php echo esc_html($form->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = sanitize_text_field($new_instance['title'] ?? '');
        $instance['form_id'] = intval($new_instance['form_id'] ?? 0);
        return $instance;
    }
}
