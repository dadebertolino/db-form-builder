<?php
/**
 * DBFB_GDPR_Compliance_Notice — Admin notice di conformità GDPR.
 *
 * Quando uno o più form pubblicati hanno enable_gdpr=false E
 * gdpr_intentionally_disabled=false, mostra un notice nelle pagine admin del
 * Form Builder che invita ad attivare il consenso o a dichiararlo come scelta
 * consapevole.
 *
 * Casi coperti:
 *  - Plugin aggiornato dalla 2.x: i form esistenti senza consenso vengono
 *    segnalati. L'admin può attivare la checkbox o marcare il form come
 *    "consenso intenzionalmente disabilitato".
 *  - Nuovi form: nascono con enable_gdpr=true di default (vedi
 *    DBFB_Builder::default_settings), quindi non triggerano il notice.
 *
 * Scope del notice: SOLO le pagine admin del Form Builder
 * (admin.php?page=dbfb-*). Non globale, sarebbe rumore per chi gestisce
 * altri plugin.
 *
 * @package DBFB
 * @since   2.10.0
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('DBFB_GDPR_Compliance_Notice')) {

    class DBFB_GDPR_Compliance_Notice {

        const NOTICE_NONCE_ACTION = 'dbfb_gdpr_notice_action';

        /**
         * Inizializzazione — chiamata da DB_Form_Builder->__construct().
         */
        public static function init() {
            add_action('admin_notices', array(__CLASS__, 'render_notice'));
        }

        /**
         * Trova i form pubblicati che NON richiedono consenso GDPR e che NON
         * sono stati marcati come "consenso intenzionalmente disabilitato".
         *
         * @return array Lista di WP_Post (post_type=dbfb_form) flaggati.
         */
        public static function get_non_compliant_forms() {
            $forms = get_posts(array(
                'post_type'      => 'dbfb_form',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ));

            $non_compliant = array();
            foreach ($forms as $form) {
                $settings = get_post_meta($form->ID, '_dbfb_settings', true);
                if (!is_array($settings)) {
                    // Form senza settings = form mai aperto in editor da quando
                    // il default è cambiato. Conservativo: NON lo flagghiamo,
                    // perché i form senza settings non sono ancora attivi nella
                    // pratica. Quando l'utente apre l'editor riceverà i nuovi
                    // default (con enable_gdpr=true).
                    continue;
                }
                $enable_gdpr     = !empty($settings['enable_gdpr']);
                $intentional_off = !empty($settings['gdpr_intentionally_disabled']);

                if (!$enable_gdpr && !$intentional_off) {
                    $non_compliant[] = $form;
                }
            }

            return $non_compliant;
        }

        /**
         * Determina se siamo su una pagina admin del Form Builder.
         *
         * @return bool
         */
        private static function is_dbfb_admin_page() {
            if (!isset($_GET['page'])) return false;
            $page = (string) $_GET['page'];
            return strpos($page, 'dbfb-') === 0;
        }

        /**
         * Rende il notice quando appropriato.
         */
        public static function render_notice() {
            if (!current_user_can('manage_options')) return;
            if (!self::is_dbfb_admin_page()) return;

            $forms = self::get_non_compliant_forms();
            if (empty($forms)) return;

            $count = count($forms);

            // Lista compatta dei nomi dei form (max 5, poi "...")
            $names = array();
            $shown = array_slice($forms, 0, 5);
            foreach ($shown as $f) {
                $edit_url = admin_url('admin.php?page=dbfb-forms&action=edit&form_id=' . (int) $f->ID);
                $names[] = sprintf(
                    '<a href="%s">%s</a>',
                    esc_url($edit_url),
                    esc_html($f->post_title ?: __('(senza titolo)', 'db-form-builder'))
                );
            }
            $names_text = implode(', ', $names);
            if ($count > count($shown)) {
                $names_text .= sprintf(
                    /* translators: %d: numero di form ulteriori non mostrati */
                    _n(' e altri %d', ' e altri %d', $count - count($shown), 'db-form-builder'),
                    $count - count($shown)
                );
            }

            $headline = sprintf(
                /* translators: %d: numero di form */
                _n(
                    '%d form non richiede il consenso al trattamento dei dati personali',
                    '%d form non richiedono il consenso al trattamento dei dati personali',
                    $count,
                    'db-form-builder'
                ),
                $count
            );
            ?>
            <div class="notice notice-warning" style="border-left-color:#f0b849">
                <p style="font-size:14px;margin-top:12px">
                    <strong>DB Form Builder — <?php echo esc_html($headline); ?></strong>
                </p>
                <p>
                    <?php echo wp_kses_post($names_text); ?>
                </p>
                <p>
                    <?php
                    esc_html_e(
                        'Per conformità GDPR è raccomandato attivare la checkbox di consenso. Per ogni form, apri l\'editor e abilita "Checkbox accettazione privacy / GDPR". Se la scelta di non richiedere il consenso è consapevole (es. base giuridica diversa: contratto, legittimo interesse, autenticazione), spunta nell\'editor del form la conferma "Confermo: questo form non richiede il consenso GDPR" e il form non comparirà più in questo avviso.',
                        'db-form-builder'
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }
}
