<?php
/**
 * DBFB_REST — API REST delle risposte, namespace dbfb/v1 (2.20.0).
 *
 * Rotte:
 *  - GET   /forms                 form con totale e risposte nuove
 *  - GET   /submissions           risposte paginate (form_id, status, since_id, page, per_page)
 *  - GET   /submissions/{id}      dettaglio con campi, allegati, consensi, provenienza
 *  - PATCH /submissions/{id}      cambio di stato (new | read | archived)
 *  - GET   /stats                 risposte per giorno e per form (days, form_id)
 *
 * Autenticazione: quella standard di WordPress. Cookie + nonce `wp_rest` per
 * la web app sullo stesso dominio, Application Passwords per i client
 * esterni. Ogni rotta richiede la capability dbfb_view_submissions (vedi
 * DBFB_Roles) e passa da un rate limit per utente.
 *
 * Le risposte contengono dati personali: header no-store su tutte le rotte,
 * niente IP nel dettaglio, allegati solo tramite link firmati a scadenza breve.
 *
 * @package DBFB
 * @since   2.20.0
 */

if (!defined('ABSPATH')) exit;

class DBFB_REST {

    const NAMESPACE_V1 = 'dbfb/v1';

    // Rate limit per utente: richieste massime nella finestra (secondi).
    const RATE_LIMIT  = 120;
    const RATE_WINDOW = 60;

    // Validità dei link agli allegati restituiti dal dettaglio.
    const FILE_LINK_TTL = 15 * MINUTE_IN_SECONDS;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_filter('rest_pre_dispatch', array(__CLASS__, 'rate_limit'), 10, 3);
        add_filter('rest_post_dispatch', array(__CLASS__, 'no_store_headers'), 10, 3);
    }

    public static function register_routes() {
        $ns = self::NAMESPACE_V1;

        register_rest_route($ns, '/forms', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'get_forms'),
            'permission_callback' => array(__CLASS__, 'permission_check'),
        ));

        register_rest_route($ns, '/submissions', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'get_submissions'),
            'permission_callback' => array(__CLASS__, 'permission_check'),
            'args'                => array(
                'form_id'  => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
                'status'   => array('type' => 'string', 'enum' => array_merge(array(''), DBFB_Submissions_Repository::STATUSES), 'default' => ''),
                'since_id' => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
                'page'     => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20),
            ),
        ));

        register_rest_route($ns, '/submissions/(?P<id>\d+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array(__CLASS__, 'get_submission'),
                'permission_callback' => array(__CLASS__, 'permission_check'),
                'args'                => array(
                    'id' => array('type' => 'integer', 'minimum' => 1, 'required' => true),
                ),
            ),
            array(
                'methods'             => 'PATCH',
                'callback'            => array(__CLASS__, 'update_submission'),
                'permission_callback' => array(__CLASS__, 'permission_check'),
                'args'                => array(
                    'id'     => array('type' => 'integer', 'minimum' => 1, 'required' => true),
                    'status' => array('type' => 'string', 'enum' => DBFB_Submissions_Repository::STATUSES, 'required' => true),
                ),
            ),
        ));

        register_rest_route($ns, '/stats', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'get_stats'),
            'permission_callback' => array(__CLASS__, 'permission_check'),
            'args'                => array(
                'days'    => array('type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30),
                'form_id' => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
            ),
        ));
    }

    // =========================================================
    // PERMESSI
    // =========================================================

    /**
     * Login e capability.
     *
     * Niente effetti collaterali qui: WordPress richiama il callback anche
     * per calcolare l'header Allow, quindi più volte per richiesta.
     *
     * @return true|WP_Error
     */
    public static function permission_check() {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_not_logged_in', __('Accesso richiesto.', 'db-form-builder'), array('status' => 401));
        }
        if (!DBFB_Roles::current_user_can_view()) {
            return new WP_Error('rest_forbidden', __('Non hai i permessi per consultare le risposte.', 'db-form-builder'), array('status' => 403));
        }
        return true;
    }

    /**
     * Rate limit per utente sulle rotte del namespace, una volta per richiesta.
     * Gli utenti senza capability non vengono contati: li ferma permission_check().
     *
     * @param mixed           $result
     * @param WP_REST_Server  $server
     * @param WP_REST_Request $request
     * @return mixed
     */
    public static function rate_limit($result, $server, $request) {
        if ($result !== null || !self::is_own_route($request) || !DBFB_Roles::current_user_can_view()) {
            return $result;
        }
        $limit  = (int) apply_filters('dbfb_rest_rate_limit', self::RATE_LIMIT);
        $window = (int) apply_filters('dbfb_rest_rate_window', self::RATE_WINDOW);
        if ($limit <= 0 || $window <= 0) {
            return $result;
        }
        // Prefisso dbfb_rate_: ripulito dalla disinstallazione con gli altri limiti.
        $key   = 'dbfb_rate_rest_' . get_current_user_id();
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return new WP_Error('dbfb_rate_limited', __('Troppe richieste. Riprova tra poco.', 'db-form-builder'), array('status' => 429));
        }
        set_transient($key, $count + 1, $window);
        return $result;
    }

    private static function is_own_route($request) {
        return strpos((string) $request->get_route(), '/' . self::NAMESPACE_V1 . '/') === 0;
    }

    /**
     * Nessuna cache (browser, proxy, CDN) per le risposte del namespace.
     *
     * @param WP_HTTP_Response $response
     * @param WP_REST_Server   $server
     * @param WP_REST_Request  $request
     * @return WP_HTTP_Response
     */
    public static function no_store_headers($response, $server, $request) {
        if (self::is_own_route($request) && $response instanceof WP_HTTP_Response) {
            $response->header('Cache-Control', 'no-store, private');
        }
        return $response;
    }

    // =========================================================
    // CALLBACK
    // =========================================================

    public static function get_forms() {
        $forms = get_posts(array(
            'post_type'      => 'dbfb_form',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));
        $counts = DBFB_Submissions_Repository::counts_by_form();

        $out = array();
        foreach ($forms as $form) {
            $out[] = array(
                'id'    => (int) $form->ID,
                'title' => self::form_title($form->ID),
                'total' => $counts[$form->ID]['total'] ?? 0,
                'new'   => $counts[$form->ID]['new'] ?? 0,
            );
        }
        return rest_ensure_response($out);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_submissions($request) {
        $per_page = (int) $request['per_page'];
        $result   = DBFB_Submissions_Repository::query(array(
            'form_id'  => (int) $request['form_id'],
            'status'   => (string) $request['status'],
            'since_id' => (int) $request['since_id'],
            'per_page' => $per_page,
            'page'     => (int) $request['page'],
        ));

        $items = array();
        foreach ($result['rows'] as $row) {
            $items[] = self::prepare_summary($row);
        }

        $response = rest_ensure_response($items);
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) (int) ceil($result['total'] / $per_page));
        return $response;
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function get_submission($request) {
        $row = DBFB_Submissions_Repository::get((int) $request['id']);
        if (!$row) {
            return self::not_found();
        }
        return rest_ensure_response(self::prepare_detail($row));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function update_submission($request) {
        $id = (int) $request['id'];
        if (!DBFB_Submissions_Repository::get($id)) {
            return self::not_found();
        }
        DBFB_Submissions_Repository::set_status(array($id), (string) $request['status']);
        return rest_ensure_response(self::prepare_summary(DBFB_Submissions_Repository::get($id)));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_stats($request) {
        $days = (int) $request['days'];
        $rows = DBFB_Submissions_Repository::stats_by_day($days, (int) $request['form_id']);

        $by_day  = array();
        $by_form = array();
        foreach ($rows as $r) {
            $day = (string) $r->day;
            $fid = (string) (int) $r->form_id;
            $n   = (int) $r->n;
            if (!isset($by_day[$day])) {
                $by_day[$day] = array('day' => $day, 'total' => 0, 'forms' => array());
            }
            $by_day[$day]['total']      += $n;
            $by_day[$day]['forms'][$fid] = $n;
            $by_form[$fid]               = ($by_form[$fid] ?? 0) + $n;
        }

        return rest_ensure_response(array(
            'days'    => $days,
            'total'   => array_sum($by_form),
            'by_form' => (object) $by_form,
            'by_day'  => array_values($by_day),
        ));
    }

    // =========================================================
    // FORMATTAZIONE
    // =========================================================

    /**
     * Voce di elenco: niente dati completi, solo un'anteprima.
     *
     * @param object $row
     * @return array
     */
    private static function prepare_summary($row) {
        $info = DB_Form_Builder::get_submission_fields($row, self::current_fields($row->form_id));
        return array(
            'id'           => (int) $row->id,
            'form_id'      => (int) $row->form_id,
            'form_title'   => self::form_title($row->form_id),
            'status'       => self::row_status($row),
            'submitted_at' => (string) $row->submitted_at,
            'preview'      => self::preview($info['fields'], $info['data']),
        );
    }

    /**
     * Dettaglio completo di una risposta.
     *
     * @param object $row
     * @return array
     */
    private static function prepare_detail($row) {
        $info = DB_Form_Builder::get_submission_fields($row, self::current_fields($row->form_id));

        $fields = array();
        foreach ($info['fields'] as $f) {
            $fields[] = array(
                'id'    => (string) $f['id'],
                'label' => (string) $f['label'],
                'type'  => (string) $f['type'],
                'value' => self::format_value($info['data'][$f['id']] ?? '', $f['type']),
            );
        }

        $gdpr = null;
        if (isset($row->gdpr_consent_given) && $row->gdpr_consent_given !== null) {
            $gdpr = array(
                'given'          => (bool) (int) $row->gdpr_consent_given,
                'text'           => (string) ($row->gdpr_consent_text ?? ''),
                'timestamp'      => (string) ($row->gdpr_consent_timestamp ?? ''),
                'privacy_url'    => (string) ($row->gdpr_consent_privacy_url ?? ''),
                'policy_version' => (int) ($row->gdpr_consent_policy_version ?? 0),
            );
        }

        $consents = array();
        foreach (DBFB_Consents::for_submissions(array((int) $row->id))[(int) $row->id] ?? array() as $c) {
            $consents[] = array(
                'key'        => (string) $c->consent_key,
                'text'       => (string) $c->consent_text,
                'given'      => (bool) (int) $c->given,
                'given_at'   => (string) $c->given_at,
                'revoked_at' => (string) $c->revoked_at,
            );
        }

        return array_merge(self::prepare_summary($row), array(
            'fields'       => $fields,
            'gdpr_consent' => $gdpr,
            'consents'     => $consents,
            'attribution'  => (object) $info['attribution'],
        ));
    }

    /**
     * Valore di un campo per il JSON: stringa, lista di stringhe, oppure
     * lista di allegati {name, size, url} con link firmati a scadenza breve.
     *
     * @param mixed  $value
     * @param string $type
     * @return mixed
     */
    private static function format_value($value, $type) {
        if ($type === 'file' && is_array($value)) {
            $entries = isset($value['url']) ? array($value) : $value;
            $files   = array();
            foreach ($entries as $entry) {
                if (!is_array($entry) || !isset($entry['url'])) continue;
                $files[] = array(
                    'name' => (string) ($entry['name'] ?? ''),
                    'size' => (int) ($entry['size'] ?? 0),
                    'url'  => DBFB_Submissions::signed_attachment_url($entry, self::FILE_LINK_TTL),
                );
            }
            return $files;
        }
        if (is_array($value)) {
            return array_values(array_map('strval', array_filter($value, 'is_scalar')));
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Anteprima per l'elenco: primo valore testuale non vuoto, troncato.
     *
     * @param array $fields
     * @param array $data
     * @return string
     */
    private static function preview($fields, $data) {
        foreach ($fields as $f) {
            if (in_array($f['type'], array('file', 'hidden'), true)) continue;
            $value = $data[$f['id']] ?? '';
            if (is_array($value)) $value = implode(', ', array_filter($value, 'is_scalar'));
            $value = trim((string) $value);
            if ($value !== '') {
                return mb_strimwidth($value, 0, 80, '…');
            }
        }
        return '';
    }

    private static function row_status($row) {
        $status = (string) ($row->status ?? '');
        return DBFB_Submissions_Repository::is_valid_status($status) ? $status : 'read';
    }

    private static function form_title($form_id) {
        static $titles = array();
        $form_id = (int) $form_id;
        if (!isset($titles[$form_id])) {
            $title = get_the_title($form_id);
            $titles[$form_id] = $title !== ''
                ? html_entity_decode($title, ENT_QUOTES, 'UTF-8')
                /* translators: %d: ID del form */
                : sprintf(__('Form #%d', 'db-form-builder'), $form_id);
        }
        return $titles[$form_id];
    }

    private static function current_fields($form_id) {
        static $cache = array();
        $form_id = (int) $form_id;
        if (!isset($cache[$form_id])) {
            $fields = get_post_meta($form_id, '_dbfb_fields', true);
            $cache[$form_id] = is_array($fields) ? $fields : array();
        }
        return $cache[$form_id];
    }

    private static function not_found() {
        return new WP_Error('dbfb_not_found', __('Risposta non trovata.', 'db-form-builder'), array('status' => 404));
    }
}
