<?php
/**
 * DBFB_Webhook — invio webhook con retry, async, HMAC signing.
 *
 * Architettura (2.7.0+):
 *
 *   1. ENQUEUE (sincrono, nel submit handler)
 *      enqueue($form_id, $submission_id, $url, $payload)
 *      → INSERT in wp_dbfb_webhook_deliveries con status='pending',
 *        next_attempt_at=NOW(), attempts=0
 *      → wp_schedule_single_event('dbfb_webhook_dispatch', delivery_id)
 *
 *   2. DISPATCH (async, via cron)
 *      handle_dispatch($delivery_id)
 *      → SELECT row con status='pending'
 *      → costruisce headers (HMAC + timestamp) firmando il payload
 *      → wp_remote_post()
 *      → 2xx: status='success', last_status_code, fatto.
 *      → 4xx (eccetto 408/429): status='failed', no retry.
 *      → 5xx / timeout / 408 / 429: incrementa attempts, calcola backoff,
 *        se attempts >= MAX → status='dead', altrimenti re-enqueue.
 *
 *   3. RETRY POLICY
 *      Tentativi totali: 5 (1 + 4 retry)
 *      Intervalli: 1m, 5m, 30m, 2h, 12h
 *      Errori transient: HTTP 5xx, timeout, 408 Request Timeout, 429 Rate Limit
 *      Errori permanenti: HTTP 4xx (eccetto i due sopra) → no retry
 *
 * @package DBFB
 * @since   2.7.0
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('DBFB_Webhook')) {

    class DBFB_Webhook {

        /**
         * Sequenza dei delay per i retry, in secondi.
         * Indice = numero del tentativo precedente. attempts=0 → wait[0]=60s.
         * Lunghezza dell'array = numero massimo di retry totali.
         * Dopo l'ultimo elemento, la delivery va in stato 'dead'.
         */
        const RETRY_INTERVALS = array(60, 300, 1800, 7200, 43200);

        /**
         * Numero massimo di tentativi (inclusi il primo).
         * = count(RETRY_INTERVALS) + 1 (primo immediato + retry).
         * Il primo tentativo non è un "retry": è il dispatch iniziale.
         */
        const MAX_ATTEMPTS = 5;

        /**
         * HTTP status code che, se ricevuti, indicano errori transient
         * meritevoli di retry (oltre ai 5xx generici).
         *  408 Request Timeout
         *  429 Too Many Requests
         */
        const TRANSIENT_4XX_CODES = array(408, 429);

        /**
         * Inizializzazione: registra hook cron e admin.
         */
        public static function init() {
            add_action('dbfb_webhook_dispatch', array(__CLASS__, 'handle_dispatch'), 10, 1);
        }

        /**
         * Costruisce il payload del webhook a partire dai dati della submission.
         *
         * Estratto in funzione separata per testabilità: la stessa logica
         * deve poter essere chiamata anche dalla UI "Re-invia" senza
         * dipendere dal flusso di submit.
         *
         * @param WP_Post $form        Post del form
         * @param array   $form_fields Field correnti del form
         * @param array   $form_data   Dati del submit (incluso _fields_snapshot,
         *                             che NON viene incluso nel payload)
         * @param string  $client_ip   IP del client già normalizzato
         * @return array Payload pronto per JSON encoding
         */
        public static function build_payload($form, $form_fields, $form_data, $client_ip) {
            // Build structured fields. Esclude i campi solo-layout.
            $fields_data = array();
            foreach ($form_fields as $field) {
                if (in_array($field['type'] ?? '', array('divider', 'html', 'image', 'pagebreak'), true)) continue;
                $fields_data[] = array(
                    'id'    => $field['id']    ?? '',
                    'label' => $field['label'] ?? '',
                    'type'  => $field['type']  ?? '',
                    'value' => $form_data[$field['id'] ?? ''] ?? '',
                );
            }

            // IP rispetta la modalità di storage: 'none' = vuoto, 'hashed' = hash, 'full' = chiaro.
            $mode = DB_Form_Builder::get_ip_storage_mode();
            $ip_for_payload = '';
            if ($mode === 'full') {
                $ip_for_payload = $client_ip;
            } elseif ($mode === 'hashed' && $client_ip !== '') {
                $ip_for_payload = DB_Form_Builder::hash_ip($client_ip);
            }

            // Esclude metadati interni dal raw_data (vedi 2.6.0).
            $raw_data_clean = $form_data;
            unset($raw_data_clean['_fields_snapshot']);

            // 2.8.0: include URL dell'informativa privacy nel payload.
            // Permette al destinatario (CRM, Zapier, ecc.) di sapere a quale
            // informativa l'utente ha dato il consenso. Utile per audit
            // GDPR sul lato consumer del webhook.
            $form_settings = get_post_meta($form->ID, '_dbfb_settings', true);
            $privacy_url = is_array($form_settings) ? ($form_settings['gdpr_link'] ?? '') : '';
            if ($privacy_url === '' && function_exists('get_privacy_policy_url')) {
                $privacy_url = get_privacy_policy_url();
            }

            return array(
                'form_id'      => $form->ID,
                'form_title'   => $form->post_title,
                'submitted_at' => current_time('c'),
                'ip'           => $ip_for_payload,
                'privacy_url'  => $privacy_url,
                'fields'       => $fields_data,
                'raw_data'     => $raw_data_clean,
            );
        }

        /**
         * Mette in coda una delivery webhook e schedula il primo dispatch.
         *
         * @param int    $form_id
         * @param int    $submission_id
         * @param string $url
         * @param array  $payload
         * @return int|false ID della delivery creata, false se l'enqueue fallisce
         */
        public static function enqueue($form_id, $submission_id, $url, $payload) {
            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_webhook_deliveries';

            $now = current_time('mysql');
            $inserted = $wpdb->insert(
                $table,
                array(
                    'form_id'         => (int) $form_id,
                    'submission_id'   => $submission_id ? (int) $submission_id : null,
                    'url'             => (string) $url,
                    'payload'         => wp_json_encode($payload),
                    'status'          => 'pending',
                    'attempts'        => 0,
                    'created_at'      => $now,
                    'next_attempt_at' => $now,
                ),
                array('%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s')
            );

            if (!$inserted) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('DB Form Builder Webhook: enqueue fallito - ' . $wpdb->last_error);
                }
                return false;
            }

            $delivery_id = (int) $wpdb->insert_id;

            // Schedula dispatch immediato (delay 1s per uscire dalla
            // request corrente). WP-Cron processerà al prossimo pageload.
            wp_schedule_single_event(time() + 1, 'dbfb_webhook_dispatch', array($delivery_id));

            return $delivery_id;
        }

        /**
         * Handler chiamato da wp_schedule_single_event per processare una
         * singola delivery. Gestisce il retry/backoff/HMAC.
         *
         * @param int $delivery_id
         */
        public static function handle_dispatch($delivery_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_webhook_deliveries';

            $delivery = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d", (int) $delivery_id
            ));
            if (!$delivery) return;
            if ($delivery->status !== 'pending') return; // già processata

            $payload = json_decode($delivery->payload, true);
            if (!is_array($payload)) {
                self::mark_failed($delivery_id, 0, 'Payload corrotto, impossibile inviare');
                return;
            }

            // Recupera secret HMAC dal form per firmare il payload.
            $form_settings = get_post_meta((int) $delivery->form_id, '_dbfb_settings', true);
            $secret = !empty($form_settings['webhook_secret']) ? (string) $form_settings['webhook_secret'] : '';

            $body = wp_json_encode($payload);
            $timestamp = (string) time();

            $headers = array(
                'Content-Type'        => 'application/json',
                'User-Agent'          => 'DB-Form-Builder/' . DBFB_VERSION,
                'X-DBFB-Timestamp'    => $timestamp,
                'X-DBFB-Delivery-Id'  => (string) $delivery_id,
                'X-DBFB-Attempt'      => (string) ((int) $delivery->attempts + 1),
            );

            // HMAC: opt-in se il secret è configurato. Non ne forziamo uno
            // generato di default per non rompere webhook esistenti che
            // verificano headers specifici (Zapier ecc.).
            if ($secret !== '') {
                $signed_payload = $timestamp . '.' . $body;
                $signature = hash_hmac('sha256', $signed_payload, $secret);
                $headers['X-DBFB-Signature'] = 'sha256=' . $signature;
            }

            $response = wp_remote_post($delivery->url, array(
                'timeout' => 15,
                'headers' => $headers,
                'body'    => $body,
            ));

            $now = current_time('mysql');
            $attempts = (int) $delivery->attempts + 1;

            if (is_wp_error($response)) {
                // Errore di rete (timeout, DNS, TCP). Sempre transient.
                $err = $response->get_error_message();
                self::handle_transient_error($delivery_id, $attempts, 0, 'Network: ' . $err);
                return;
            }

            $code = (int) wp_remote_retrieve_response_code($response);

            if ($code >= 200 && $code < 300) {
                $wpdb->update(
                    $table,
                    array(
                        'status'           => 'success',
                        'attempts'         => $attempts,
                        'last_attempt_at'  => $now,
                        'last_status_code' => $code,
                        'last_error'       => null,
                        'next_attempt_at'  => null,
                    ),
                    array('id' => $delivery_id),
                    array('%s', '%d', '%s', '%d', '%s', '%s'),
                    array('%d')
                );
                return;
            }

            // 4xx non transient → fallimento permanente.
            if ($code >= 400 && $code < 500 && !in_array($code, self::TRANSIENT_4XX_CODES, true)) {
                self::mark_failed($delivery_id, $code, 'HTTP ' . $code . ' (errore permanente, no retry)');
                return;
            }

            // 5xx, 408, 429: transient.
            self::handle_transient_error($delivery_id, $attempts, $code, 'HTTP ' . $code);
        }

        /**
         * Gestisce errore transient: incrementa attempts, ri-schedula se ok,
         * marca dead se esauriti i retry.
         */
        private static function handle_transient_error($delivery_id, $attempts, $code, $error_msg) {
            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_webhook_deliveries';
            $now = current_time('mysql');

            // attempts == 1 dopo il primo dispatch. Indice nei RETRY_INTERVALS:
            //   attempts=1 → next dopo RETRY_INTERVALS[0] = 60s
            //   attempts=2 → next dopo RETRY_INTERVALS[1] = 300s
            //   ...
            //   attempts=5 → fine (no più retry)
            $retry_idx = $attempts - 1; // 0-based per RETRY_INTERVALS

            if ($attempts >= self::MAX_ATTEMPTS || $retry_idx >= count(self::RETRY_INTERVALS)) {
                // Esaurito.
                $wpdb->update(
                    $table,
                    array(
                        'status'           => 'dead',
                        'attempts'         => $attempts,
                        'last_attempt_at'  => $now,
                        'last_status_code' => $code ?: null,
                        'last_error'       => self::truncate($error_msg, 500),
                        'next_attempt_at'  => null,
                    ),
                    array('id' => $delivery_id),
                    array('%s', '%d', '%s', '%d', '%s', '%s'),
                    array('%d')
                );
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("DB Form Builder Webhook: delivery $delivery_id morta dopo $attempts tentativi - $error_msg");
                }
                return;
            }

            $delay = self::RETRY_INTERVALS[$retry_idx];
            $next_at_unix = time() + $delay;
            $next_at_mysql = date('Y-m-d H:i:s', $next_at_unix);

            $wpdb->update(
                $table,
                array(
                    'status'           => 'pending',
                    'attempts'         => $attempts,
                    'last_attempt_at'  => $now,
                    'last_status_code' => $code ?: null,
                    'last_error'       => self::truncate($error_msg, 500),
                    'next_attempt_at'  => $next_at_mysql,
                ),
                array('id' => $delivery_id),
                array('%s', '%d', '%s', '%d', '%s', '%s'),
                array('%d')
            );

            wp_schedule_single_event($next_at_unix, 'dbfb_webhook_dispatch', array($delivery_id));
        }

        /**
         * Marca delivery come 'failed' (errore permanente). No retry.
         */
        private static function mark_failed($delivery_id, $code, $error_msg) {
            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_webhook_deliveries';
            $wpdb->update(
                $table,
                array(
                    'status'           => 'failed',
                    'last_attempt_at'  => current_time('mysql'),
                    'last_status_code' => $code ?: null,
                    'last_error'       => self::truncate($error_msg, 500),
                    'next_attempt_at'  => null,
                ),
                array('id' => $delivery_id),
                array('%s', '%s', '%d', '%s', '%s'),
                array('%d')
            );
        }

        /**
         * Re-enqueue manuale di una delivery dead/failed (chiamato da UI admin).
         *
         * Resetta attempts a 0 e status a pending, lasciando il payload e
         * l'URL invariati per garantire idempotenza del re-invio.
         */
        public static function retry_delivery($delivery_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'dbfb_webhook_deliveries';
            $wpdb->update(
                $table,
                array(
                    'status'           => 'pending',
                    'attempts'         => 0,
                    'next_attempt_at'  => current_time('mysql'),
                    'last_error'       => null,
                    'last_status_code' => null,
                ),
                array('id' => (int) $delivery_id),
                array('%s', '%d', '%s', '%s', '%s'),
                array('%d')
            );
            wp_schedule_single_event(time() + 1, 'dbfb_webhook_dispatch', array((int) $delivery_id));
        }

        /**
         * Genera un secret HMAC sicuro (32 byte → 64 char hex).
         */
        public static function generate_secret() {
            return bin2hex(random_bytes(32));
        }

        private static function truncate($s, $max) {
            $s = (string) $s;
            // mb_strlen sicuro: WordPress richiede mbstring, ma defensivo.
            $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
            if ($len <= $max) return $s;
            $sub = function_exists('mb_substr') ? mb_substr($s, 0, $max - 1) : substr($s, 0, $max - 1);
            return $sub . '…';
        }
    }
}
