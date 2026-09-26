<?php
if (!defined('ABSPATH')) exit;

class DBFB_Email {

    /**
     * Segnaposto per le email.
     *
     * @param WP_Post $form
     * @param array   $fields
     * @param array   $data
     * @param array   $settings
     * @param string  $audience 'admin' (default: allegati con link di download
     *                          admin) o 'user' (email di conferma a chi ha
     *                          inviato: solo il nome del file, 2.13.0).
     * @return array
     */
    public static function prepare_placeholders($form, $fields, $data, $settings, $audience = 'admin') {
        // Privacy by design (2.3.0): il placeholder {ip} nelle email rispetta
        // la modalità di storage configurata. In 'none' è vuoto, in 'hashed'
        // è l'hash, in 'full' è l'IP in chiaro. Coerente con quanto salvato
        // a DB.
        $client_ip = DB_Form_Builder::get_client_ip();
        $mode = DB_Form_Builder::get_ip_storage_mode();
        $ip_for_email = '';
        if ($mode === 'full') {
            $ip_for_email = $client_ip;
        } elseif ($mode === 'hashed') {
            $ip_for_email = DB_Form_Builder::hash_ip($client_ip);
        }

        // 2.8.0: placeholder {privacy_url} per email - URL dell'informativa
        // privacy specifica del form (con fallback a quella globale di WP).
        // Utile per email di conferma utente: "I tuoi dati sono trattati
        // come da informativa: {privacy_url}".
        $privacy_url = $settings['gdpr_link'] ?? '';
        if ($privacy_url === '' && function_exists('get_privacy_policy_url')) {
            $privacy_url = get_privacy_policy_url();
        }

        $placeholders = [
            '{form_titolo}' => $form->post_title,
            '{ip}' => $ip_for_email,
            '{data}' => current_time('d/m/Y H:i:s'),
            '{sito}' => get_bloginfo('name'),
            '{privacy_url}' => $privacy_url,
        ];

        $riepilogo = '';
        foreach ($fields as $field) {
            if (in_array($field['type'], ['divider', 'html', 'image', 'pagebreak'])) continue;

            $value = isset($data[$field['id']]) ? $data[$field['id']] : '';

            // File field: extract display value
            if ($field['type'] === 'file' && !empty($value)) {
                if (is_array($value)) {
                    // Multiple files or single file object
                    // 2.13.0: niente URL pubblico (la cartella allegati nega
                    // l'accesso diretto): link al download admin, che richiede
                    // login e capability. A chi ha inviato il form il link
                    // non servirebbe (niente login admin): solo il nome.
                    $with_link  = ($audience !== 'user');
                    $file_label = function ($f) use ($with_link) {
                        if (!is_array($f)) return (string) $f;
                        if (!$with_link) return (string) ($f['name'] ?? '');
                        $link = DBFB_Submissions::attachment_download_url($f, false);
                        return ($f['name'] ?? '') . ($link !== '' ? ' (' . $link . ')' : '');
                    };
                    if (isset($value['name'])) {
                        // Single file
                        $value = $file_label($value);
                    } else {
                        // Multiple files
                        $file_names = array_map($file_label, $value);
                        $value = implode(', ', $file_names);
                    }
                }
            } elseif (is_array($value)) {
                $value = implode(', ', $value);
            }

            // 2.12.0: segnaposto stabile basato sull'id del campo, non cambia
            // se l'etichetta viene rinominata.
            $placeholders['{campo:' . $field['id'] . '}'] = $value;
            // Legacy: segnaposto derivato dall'etichetta (es. "Nome e cognome"
            // → {nome-e-cognome}). Se due campi hanno la stessa etichetta vince
            // il primo, così l'ordine del form resta prevedibile.
            $field_key = '{' . sanitize_title($field['label']) . '}';
            if ($field_key !== '{}' && !isset($placeholders[$field_key])) {
                $placeholders[$field_key] = $value;
            }
            $riepilogo .= $field['label'] . ': ' . $value . "\n";
        }

        $placeholders['{riepilogo_dati}'] = trim($riepilogo);
        return $placeholders;
    }

    /**
     * Sostituisce i segnaposto in un solo passaggio (2.11.2).
     *
     * strtr non rielabora il testo già sostituito: un valore inviato
     * dall'utente che contiene a sua volta un segnaposto (es. "{ip}") resta
     * letterale. Con str_replace a catena veniva invece espanso.
     * I segnaposto {campo:id} di campi inesistenti vengono rimossi.
     */
    public static function replace_placeholders($text, $placeholders) {
        // Prima togliamo dal modello i {campo:id} di campi che non esistono
        // più, poi sostituiamo: così i valori dell'utente non vengono toccati.
        $text = preg_replace_callback('/\{campo:[a-z0-9_\-]+\}/', function ($m) use ($placeholders) {
            return isset($placeholders[$m[0]]) ? $m[0] : '';
        }, (string) $text);
        return strtr($text, array_map('strval', $placeholders));
    }

    /**
     * Prepara oggetto e testo per un'email text/plain (2.11.2).
     *
     * I testi salvati fino alla 2.11.1 passavano da wp_kses_post, che
     * converte "&" in "&amp;" e lascia passare i tag HTML: in un'email in
     * testo semplice comparivano letterali. Qui togliamo i tag e
     * decodifichiamo le entità PRIMA di inserire i valori dell'utente.
     */
    private static function to_plain_text($template) {
        $text = wp_strip_all_tags((string) $template);
        return html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    }

    private static function build_email($subject_tpl, $message_tpl, $placeholders) {
        $subject = self::replace_placeholders(self::to_plain_text($subject_tpl), $placeholders);
        // L'oggetto è un header: niente a capo.
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
        $message = self::replace_placeholders(self::to_plain_text($message_tpl), $placeholders);
        return [$subject, $message];
    }

    private static function get_headers() {
        $global_settings = DB_Form_Builder::get_global_settings();
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        // 2.11.2: se il mittente non è un indirizzo valido non impostiamo
        // l'header From e lasciamo il mittente predefinito di WordPress.
        // Prima veniva generato "From: Nome <>" e wp_mail falliva sempre.
        $from_email = sanitize_email($global_settings['from_email'] ?? '');
        if (is_email($from_email)) {
            $from_name = trim(str_replace(['"', '<', '>', "\r", "\n"], '', (string) ($global_settings['from_name'] ?? '')));
            $headers[] = $from_name !== ''
                ? 'From: "' . $from_name . '" <' . $from_email . '>'
                : 'From: ' . $from_email;
        }
        return $headers;
    }

    public static function send_confirmation($to, $settings, $placeholders) {
        list($subject, $message) = self::build_email(
            $settings['confirmation_subject'] ?? '',
            $settings['confirmation_message'] ?? '',
            $placeholders
        );
        return wp_mail($to, $subject, $message, self::get_headers());
    }

    public static function send_admin($settings, $placeholders) {
        $to = $settings['admin_email'];
        $recipients = array_map('trim', explode(',', $to));
        $recipients = array_filter($recipients, 'is_email');

        if (empty($recipients)) return false;

        list($subject, $message) = self::build_email(
            $settings['admin_subject'] ?? '',
            $settings['admin_message'] ?? '',
            $placeholders
        );
        $headers = self::get_headers();

        $success = true;
        foreach ($recipients as $recipient) {
            if (!wp_mail($recipient, $subject, $message, $headers)) $success = false;
        }
        return $success;
    }

    // =========================================================
    // TEST EMAIL (per form)
    // =========================================================

    public static function ajax_send_test_email() {
        check_ajax_referer('dbfb_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permessi insufficienti']);
        }

        $form_id = intval($_POST['form_id'] ?? 0);
        $email_type = sanitize_text_field($_POST['email_type'] ?? '');
        $test_email = sanitize_email($_POST['test_email'] ?? '');

        if (!$form_id || !$email_type || !$test_email) {
            wp_send_json_error(['message' => 'Parametri mancanti']);
        }

        $form = get_post($form_id);
        $form_fields = get_post_meta($form_id, '_dbfb_fields', true) ?: [];
        $form_settings = get_post_meta($form_id, '_dbfb_settings', true) ?: [];

        $sample_data = [];
        foreach ($form_fields as $field) {
            if (in_array($field['type'], ['divider', 'html', 'image', 'pagebreak'])) continue;
            switch ($field['type']) {
                case 'email':
                    $sample_data[$field['id']] = 'esempio@email.com';
                    break;
                case 'tel':
                    $sample_data[$field['id']] = '+39 123 456 7890';
                    break;
                case 'number':
                    $sample_data[$field['id']] = '42';
                    break;
                case 'date':
                    $sample_data[$field['id']] = date('Y-m-d');
                    break;
                case 'checkbox':
                    case 'radio':
                    case 'select':
                    $sample_data[$field['id']] = $field['options'][0] ?? 'Opzione esempio';
                    break;
                default:
                    $sample_data[$field['id']] = 'Valore di esempio per ' . $field['label'];
            }
        }

        $placeholders = self::prepare_placeholders(
            $form,
            $form_fields,
            $sample_data,
            $form_settings,
            $email_type === 'confirmation' ? 'user' : 'admin'
        );
        $headers = self::get_headers();

        if ($email_type === 'confirmation') {
            list($subject, $message) = self::build_email(
                $form_settings['confirmation_subject'] ?? '',
                $form_settings['confirmation_message'] ?? '',
                $placeholders
            );
        } else {
            list($subject, $message) = self::build_email(
                $form_settings['admin_subject'] ?? '',
                $form_settings['admin_message'] ?? '',
                $placeholders
            );
        }
        $subject = '[TEST] ' . $subject;

        $sent = wp_mail($test_email, $subject, $message, $headers);

        if ($sent) {
            wp_send_json_success(['message' => 'Email di test inviata a ' . $test_email]);
        } else {
            wp_send_json_error(['message' => 'Errore nell\'invio dell\'email. Verifica le impostazioni SMTP.']);
        }
    }
}
