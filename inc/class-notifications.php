<?php
/**
 * DBFB_Notifications — avvisi di nuove risposte (2.22.0).
 *
 * Canali, configurabili da Form Builder → Web app:
 *  - Web Push verso i dispositivi su cui la web app ha attivato le notifiche
 *    (sottoscrizioni salvate nello user meta dbfb_push_subscriptions);
 *  - ntfy (ntfy.sh o server proprio) e Telegram, come alternativa dove il
 *    push non è affidabile (iOS senza app installata, browser chiusi).
 *
 * Contenuto minimo e senza dati personali: "Nuova risposta" + titolo del
 * form (disattivabile) + link all'app. L'invio parte da un evento cron
 * avviato subito (spawn_cron), così il submit del visitatore non aspetta.
 *
 * Per la web app espone anche GET /dbfb/v1/updates (polling leggero) e le
 * rotte di gestione delle sottoscrizioni push.
 *
 * @package DBFB
 * @since   2.22.0
 */

if (!defined('ABSPATH')) exit;

class DBFB_Notifications {

    const OPTION        = 'dbfb_notifications';
    const META          = 'dbfb_push_subscriptions';
    const DISPATCH_HOOK = 'dbfb_notify_dispatch';
    const MAX_DEVICES   = 10;

    // Una sottoscrizione appena creata può risultare per qualche istante
    // "scaduta" sul push service (404/410) finché non si è propagata: in
    // questa finestra non la si cancella (2.25.0).
    const FRESH_GRACE = 600;

    public static function init() {
        add_action('dbfb_submission_saved', array(__CLASS__, 'on_submission_saved'), 10, 2);
        add_action(self::DISPATCH_HOOK, array(__CLASS__, 'dispatch'), 10, 2);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('admin_post_dbfb_save_notifications', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_dbfb_test_notifications', array(__CLASS__, 'handle_test'));
    }

    // =========================================================
    // IMPOSTAZIONI
    // =========================================================

    public static function settings() {
        $defaults = array(
            'push'             => 1,
            'show_form_title'  => 1,
            'poll_interval'    => 15,
            'ntfy_url'         => '',
            'ntfy_token'       => '',
            'telegram_token'   => '',
            'telegram_chat_id' => '',
        );
        $saved = get_option(self::OPTION, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), $defaults);
    }

    public static function push_enabled() {
        return !empty(self::settings()['push']) && DBFB_Web_Push::is_supported() && DBFB_Web_Push::public_key() !== '';
    }

    /**
     * Intervallo di polling della web app in secondi (10–300).
     */
    public static function poll_interval() {
        return max(10, min(300, (int) self::settings()['poll_interval']));
    }

    public static function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'db-form-builder'), '', array('response' => 403));
        }
        check_admin_referer('dbfb_save_notifications');

        $current = self::settings();
        $ntfy    = isset($_POST['ntfy_url']) ? trim(esc_url_raw(wp_unslash($_POST['ntfy_url']), array('https'))) : '';
        $chat    = isset($_POST['telegram_chat_id']) ? sanitize_text_field(wp_unslash($_POST['telegram_chat_id'])) : '';
        $token   = isset($_POST['telegram_token']) ? sanitize_text_field(wp_unslash($_POST['telegram_token'])) : '';
        $ntoken  = isset($_POST['ntfy_token']) ? sanitize_text_field(wp_unslash($_POST['ntfy_token'])) : '';

        $settings = array(
            'push'             => empty($_POST['push']) ? 0 : 1,
            'show_form_title'  => empty($_POST['show_form_title']) ? 0 : 1,
            'poll_interval'    => isset($_POST['poll_interval']) ? max(10, min(300, absint($_POST['poll_interval']))) : 15,
            'ntfy_url'         => $ntfy,
            // I segreti già salvati non vengono mostrati: campo vuoto = invariato,
            // ma il token ntfy resta solo se il server è lo stesso.
            'ntfy_token'       => $ntoken !== '' ? $ntoken : ($ntfy !== '' && self::same_host($ntfy, $current['ntfy_url']) ? $current['ntfy_token'] : ''),
            'telegram_token'   => preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token) ? $token : ($chat !== '' ? $current['telegram_token'] : ''),
            'telegram_chat_id' => preg_match('/^(-?\d+|@[A-Za-z0-9_]{5,})$/', $chat) ? $chat : '',
        );
        if (!empty($_POST['ntfy_token_clear'])) $settings['ntfy_token'] = '';
        if ($settings['telegram_chat_id'] === '') $settings['telegram_token'] = '';

        update_option(self::OPTION, $settings, false);
        $args = array('dbfb_saved' => '1');
        // Token Telegram scritto ma non valido: si tiene quello di prima e si avvisa.
        if ($token !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) {
            $args['dbfb_error'] = 'telegram_token';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=dbfb-app')));
        exit;
    }

    private static function same_host($a, $b) {
        $ha = strtolower((string) wp_parse_url((string) $a, PHP_URL_HOST));
        return $ha !== '' && $ha === strtolower((string) wp_parse_url((string) $b, PHP_URL_HOST));
    }

    /**
     * Notifica di prova dall'admin: ntfy, Telegram e i dispositivi
     * dell'amministratore che la invia.
     */
    public static function handle_test() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'db-form-builder'), '', array('response' => 403));
        }
        check_admin_referer('dbfb_test_notifications');

        $message = self::test_message();
        $results = self::send_channels($message);
        $push    = self::send_push_to_user(get_current_user_id(), $message);

        $ok = (int) $results['ok'] + $push['sent'];
        $ko = (int) $results['failed'] + $push['failed'];
        wp_safe_redirect(add_query_arg(array('dbfb_test_ok' => $ok, 'dbfb_test_failed' => $ko), admin_url('admin.php?page=dbfb-app')));
        exit;
    }

    // =========================================================
    // INVIO
    // =========================================================

    /**
     * Risposta salvata: avvia l'invio in un evento cron separato.
     *
     * @param int $submission_id
     * @param int $form_id
     */
    public static function on_submission_saved($submission_id, $form_id) {
        if (!self::has_any_channel()) return;
        // L'id della risposta negli argomenti: WordPress scarta gli eventi
        // identici pianificati a meno di 10 minuti l'uno dall'altro.
        wp_schedule_single_event(time(), self::DISPATCH_HOOK, array((int) $form_id, (int) $submission_id));
        spawn_cron();
    }

    private static function has_any_channel() {
        $s = self::settings();
        return self::push_enabled()
            || $s['ntfy_url'] !== ''
            || ($s['telegram_token'] !== '' && $s['telegram_chat_id'] !== '');
    }

    /**
     * Cron: invia l'avviso di una nuova risposta su tutti i canali attivi.
     *
     * @param int $form_id
     * @param int $submission_id Solo per rendere unico l'evento cron: nessun dato della risposta esce dal sito.
     */
    public static function dispatch($form_id, $submission_id = 0) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- argomento dell'evento cron.
        $message = self::message_for_form((int) $form_id);
        self::send_channels($message);

        if (!self::push_enabled()) return;
        foreach (self::subscribed_user_ids() as $user_id) {
            if (user_can($user_id, DBFB_Roles::CAP)) {
                self::send_push_to_user($user_id, $message);
            }
        }
    }

    /**
     * Messaggio per una nuova risposta. Mai dati della risposta.
     *
     * @param int $form_id
     * @return array {title, body, url, tag}
     */
    public static function message_for_form($form_id) {
        $show = !empty(self::settings()['show_form_title']);
        $form = $show ? html_entity_decode(get_the_title($form_id), ENT_QUOTES, 'UTF-8') : '';
        $message = array(
            'title' => __('Nuova risposta', 'db-form-builder'),
            'body'  => $form !== ''
                /* translators: %s: titolo del form */
                ? sprintf(__('Form: %s', 'db-form-builder'), $form)
                : __('È arrivata una nuova risposta a un form.', 'db-form-builder'),
            'url'   => DBFB_App::url() . '#/f/' . ($show ? (int) $form_id : 0) . '?status=new',
            'tag'   => 'dbfb-form-' . ($show ? (int) $form_id : 0),
        );
        return (array) apply_filters('dbfb_notification_message', $message, (int) $form_id);
    }

    private static function test_message() {
        return array(
            'title' => __('Notifica di prova', 'db-form-builder'),
            'body'  => __('Le notifiche delle risposte ai form funzionano.', 'db-form-builder'),
            'url'   => DBFB_App::url(),
            'tag'   => 'dbfb-test',
        );
    }

    /**
     * ntfy e Telegram.
     *
     * @param array $message
     * @return array {ok: int, failed: int}
     */
    private static function send_channels($message) {
        $s   = self::settings();
        $out = array('ok' => 0, 'failed' => 0);

        if ($s['ntfy_url'] !== '') {
            $headers = array(
                'Title'        => self::header_safe($message['title']),
                'Click'        => $message['url'],
                'Tags'         => 'inbox_tray',
                'Content-Type' => 'text/plain; charset=utf-8',
            );
            if ($s['ntfy_token'] !== '') {
                $headers['Authorization'] = 'Bearer ' . $s['ntfy_token'];
            }
            $r = wp_remote_post($s['ntfy_url'], array('timeout' => 5, 'headers' => $headers, 'body' => $message['body']));
            self::count_result($r, $out, 'ntfy');
        }

        if ($s['telegram_token'] !== '' && $s['telegram_chat_id'] !== '') {
            $r = wp_remote_post('https://api.telegram.org/bot' . $s['telegram_token'] . '/sendMessage', array(
                'timeout' => 5,
                'body'    => array(
                    'chat_id'                  => $s['telegram_chat_id'],
                    'text'                     => $message['title'] . "\n" . $message['body'] . "\n" . $message['url'],
                    'disable_web_page_preview' => 'true',
                ),
            ));
            self::count_result($r, $out, 'telegram');
        }
        return $out;
    }

    private static function count_result($response, &$out, $channel) {
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            ++$out['ok'];
        } else {
            ++$out['failed'];
            // Solo il codice: niente URL (contengono token) né contenuti.
            DB_Form_Builder::debug_log("notifica $channel non inviata (HTTP $code)");
        }
    }

    /**
     * Web Push a tutti i dispositivi di un utente. Le sottoscrizioni che il
     * push service dichiara scadute (404, 410) vengono rimosse.
     *
     * @param int   $user_id
     * @param array $message
     * @return array {sent: int, failed: int}
     */
    public static function send_push_to_user($user_id, $message) {
        $out = array('sent' => 0, 'failed' => 0);
        if (!self::push_enabled()) return $out;

        $payload = wp_json_encode(array(
            'title' => (string) $message['title'],
            'body'  => (string) $message['body'],
            'url'   => (string) $message['url'],
            'tag'   => (string) $message['tag'],
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $expired = array();
        foreach (self::subscriptions($user_id) as $key => $sub) {
            $code = DBFB_Web_Push::send($sub, $payload);
            if (!is_wp_error($code) && $code >= 200 && $code < 300) {
                ++$out['sent'];
                continue;
            }
            ++$out['failed'];
            if (!is_wp_error($code) && in_array($code, array(404, 410), true)
                && time() - (int) ($sub['created'] ?? 0) > self::FRESH_GRACE) {
                $expired[] = $key;
            }
            DB_Form_Builder::debug_log('push non inviato (' . (is_wp_error($code) ? $code->get_error_code() : 'HTTP ' . $code) . ')');
        }
        if ($expired) {
            // Riletto ora: un dispositivo attivato durante gli invii non va perso.
            $fresh = self::subscriptions($user_id);
            foreach ($expired as $key) {
                unset($fresh[$key]);
            }
            self::save_subscriptions($user_id, $fresh);
        }
        return $out;
    }

    private static function header_safe($text) {
        // Header HTTP: niente a capo; ntfy accetta UTF-8 nel titolo.
        return trim(preg_replace('/[\r\n]+/', ' ', (string) $text));
    }

    // =========================================================
    // SOTTOSCRIZIONI
    // =========================================================

    public static function subscriptions($user_id) {
        $subs = get_user_meta((int) $user_id, self::META, true);
        return is_array($subs) ? $subs : array();
    }

    private static function save_subscriptions($user_id, $subs) {
        if ($subs) {
            update_user_meta((int) $user_id, self::META, $subs);
        } else {
            delete_user_meta((int) $user_id, self::META);
        }
    }

    private static function subscribed_user_ids() {
        return array_map('intval', get_users(array(
            'meta_key' => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- pochi utenti, solo nel cron di invio.
            'fields'   => 'ID',
        )));
    }

    // =========================================================
    // API REST
    // =========================================================

    public static function register_routes() {
        $ns = DBFB_REST::NAMESPACE_V1;

        register_rest_route($ns, '/updates', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'rest_updates'),
            'permission_callback' => array('DBFB_REST', 'permission_check'),
        ));

        register_rest_route($ns, '/push/subscription', array(
            // 2.25.0: il sito conosce ancora questa sottoscrizione? (autoriparazione dell'app)
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array(__CLASS__, 'rest_subscription_known'),
                'permission_callback' => array('DBFB_REST', 'permission_check'),
                'args'                => array(
                    'endpoint' => array('type' => 'string', 'required' => true),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array(__CLASS__, 'rest_subscribe'),
                'permission_callback' => array('DBFB_REST', 'permission_check'),
                'args'                => array(
                    'endpoint' => array('type' => 'string', 'format' => 'uri', 'required' => true),
                    'keys'     => array(
                        'type'       => 'object',
                        'required'   => true,
                        'properties' => array(
                            'p256dh' => array('type' => 'string', 'required' => true),
                            'auth'   => array('type' => 'string', 'required' => true),
                        ),
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array(__CLASS__, 'rest_unsubscribe'),
                'permission_callback' => array('DBFB_REST', 'permission_check'),
                'args'                => array(
                    'endpoint' => array('type' => 'string', 'required' => true),
                ),
            ),
        ));

        register_rest_route($ns, '/push/test', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'rest_test'),
            'permission_callback' => array('DBFB_REST', 'permission_check'),
        ));
    }

    /**
     * Polling leggero: id dell'ultima risposta e risposte nuove per form.
     * Due query aggregate, nessun dato delle risposte.
     */
    public static function rest_updates() {
        $by_form = array();
        $total   = 0;
        foreach (DBFB_Submissions_Repository::counts_by_form() as $form_id => $c) {
            if ($c['new'] > 0) {
                $by_form[(string) $form_id] = $c['new'];
                $total += $c['new'];
            }
        }
        return rest_ensure_response(array(
            'latest_id'   => DBFB_Submissions_Repository::latest_id(),
            'new_total'   => $total,
            'new_by_form' => (object) $by_form,
        ));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_subscribe($request) {
        if (!self::push_enabled()) {
            return new WP_Error('dbfb_push_disabled', __('Le notifiche push non sono attive su questo sito.', 'db-form-builder'), array('status' => 409));
        }
        $endpoint = (string) $request['endpoint'];
        $keys     = (array) $request['keys'];
        $p256dh   = (string) ($keys['p256dh'] ?? '');
        $auth     = (string) ($keys['auth'] ?? '');

        if (!DBFB_Web_Push::is_allowed_endpoint($endpoint)
            || strlen(DBFB_Web_Push::b64url_decode($p256dh)) !== 65
            || strlen(DBFB_Web_Push::b64url_decode($auth)) !== 16) {
            return new WP_Error('dbfb_push_invalid', __('Sottoscrizione non valida.', 'db-form-builder'), array('status' => 400));
        }

        $user_id = get_current_user_id();
        $subs    = self::subscriptions($user_id);
        $subs[hash('sha256', $endpoint)] = array(
            'endpoint' => $endpoint,
            'p256dh'   => $p256dh,
            'auth'     => $auth,
            'created'  => time(),
        );
        // Tetto di dispositivi per utente: si tengono i più recenti.
        if (count($subs) > self::MAX_DEVICES) {
            uasort($subs, function ($a, $b) {
                return (int) $b['created'] - (int) $a['created'];
            });
            $subs = array_slice($subs, 0, self::MAX_DEVICES, true);
        }
        self::save_subscriptions($user_id, $subs);
        return rest_ensure_response(array('subscribed' => true));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function rest_subscription_known($request) {
        $subs = self::subscriptions(get_current_user_id());
        return rest_ensure_response(array('known' => isset($subs[hash('sha256', (string) $request['endpoint'])])));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function rest_unsubscribe($request) {
        $user_id = get_current_user_id();
        $subs    = self::subscriptions($user_id);
        unset($subs[hash('sha256', (string) $request['endpoint'])]);
        self::save_subscriptions($user_id, $subs);
        return rest_ensure_response(array('subscribed' => false));
    }

    public static function rest_test() {
        $result = self::send_push_to_user(get_current_user_id(), self::test_message());
        return rest_ensure_response($result);
    }

    /**
     * Dati per la web app (inclusi in GET /me).
     *
     * @return array
     */
    public static function client_info() {
        $push = self::push_enabled();
        return array(
            'push'          => $push,
            'vapid_key'     => $push ? DBFB_Web_Push::public_key() : '',
            'poll_interval' => self::poll_interval(),
        );
    }

    // =========================================================
    // PRIVACY
    // =========================================================

    /**
     * Voce per il registro dei trattamenti (DB Privacy Hub).
     *
     * @return array|null
     */
    public static function privacy_entry() {
        $s = self::settings();
        $channels = array();
        if (self::push_enabled()) {
            $channels[] = __('Web Push verso i dispositivi del personale che ha attivato le notifiche nella web app (tramite i servizi push dei browser: Google, Mozilla, Apple, Microsoft)', 'db-form-builder');
        }
        if ($s['ntfy_url'] !== '') {
            $channels[] = sprintf(
                /* translators: %s: host del server ntfy */
                __('ntfy (%s)', 'db-form-builder'),
                (string) wp_parse_url($s['ntfy_url'], PHP_URL_HOST)
            );
        }
        if ($s['telegram_token'] !== '' && $s['telegram_chat_id'] !== '') {
            $channels[] = __('Telegram (Telegram Messenger Inc.)', 'db-form-builder');
        }
        if (!$channels) return null;

        return array(
            'id'             => 'dbfb_staff_notifications',
            'label'          => __('Notifiche al personale sulle nuove risposte (DB Form Builder)', 'db-form-builder'),
            'status'         => 'active',
            'purpose'        => __('Avvisare il personale incaricato che è arrivata una nuova risposta a un form, perché possa consultarla nella web app del sito.', 'db-form-builder'),
            'legal_basis'    => __('Legittimo interesse del titolare all\'organizzazione del lavoro (art. 6.1.f GDPR).', 'db-form-builder'),
            'data_collected' => __('Il messaggio non contiene dati delle risposte: solo "Nuova risposta", il titolo del form (disattivabile) e il link alla web app. Per il Web Push il sito conserva, per ogni utente del personale, l\'indirizzo di consegna e le chiavi del dispositivo forniti dal browser.', 'db-form-builder'),
            'retention'      => __('Le sottoscrizioni push restano finché l\'utente disattiva le notifiche, esce dalla web app o il servizio push le dichiara scadute.', 'db-form-builder'),
            'transfers'      => implode('; ', $channels) . '.',
        );
    }
}
