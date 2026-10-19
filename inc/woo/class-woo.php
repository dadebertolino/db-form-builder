<?php
/**
 * DBFB_Woo — collegamento tra risposte e ordini WooCommerce (2.23.0).
 *
 * Caricato solo con WooCommerce attivo (vedi db-form-builder.php).
 *
 *  - Cliente di una risposta: l'email dell'interessato (stessa regola di
 *    subject_hash) confrontata con l'email di fatturazione degli ordini e con
 *    l'account del cliente registrato.
 *  - API: GET /orders (ultimi ordini del negozio) e GET /submissions/{id}/orders
 *    (ordini dello stesso cliente). Solo wc_get_orders(): compatibile con
 *    l'archiviazione ordini ad alte prestazioni (HPOS) e con quella classica.
 *  - Permessi: oltre a dbfb_view_submissions serve la capability degli ordini
 *    di WooCommerce (edit_shop_orders, filter dbfb_orders_capability). Il ruolo
 *    "Gestore form" non vede gli ordini finché l'amministratore non lo decide.
 *
 * @package DBFB
 * @since   2.23.0
 */

if (!defined('ABSPATH')) exit;

class DBFB_Woo {

    const MAX_CUSTOMER_ORDERS = 20;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_filter('dbfb_rest_me', array(__CLASS__, 'me_info'));
    }

    // =========================================================
    // PERMESSI
    // =========================================================

    public static function orders_capability() {
        return (string) apply_filters('dbfb_orders_capability', 'edit_shop_orders');
    }

    public static function current_user_can_view_orders() {
        return DBFB_Roles::current_user_can_view() && current_user_can(self::orders_capability());
    }

    /**
     * @return true|WP_Error
     */
    public static function orders_permission_check() {
        $base = DBFB_REST::permission_check();
        if (is_wp_error($base)) return $base;
        if (!current_user_can(self::orders_capability())) {
            return new WP_Error('rest_forbidden', __('Non hai i permessi per consultare gli ordini.', 'db-form-builder'), array('status' => 403));
        }
        return true;
    }

    /**
     * Informazioni per la web app, aggiunte a GET /me.
     *
     * @param array $me
     * @return array
     */
    public static function me_info($me) {
        $me['woo'] = array(
            'active' => true,
            'orders' => self::current_user_can_view_orders(),
            'create' => class_exists('DBFB_Woo_Order_Builder') && DBFB_Woo_Order_Builder::current_user_can_create(),
        );
        return $me;
    }

    // =========================================================
    // API REST
    // =========================================================

    public static function register_routes() {
        $ns = DBFB_REST::NAMESPACE_V1;

        register_rest_route($ns, '/orders', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'rest_orders'),
            'permission_callback' => array(__CLASS__, 'orders_permission_check'),
            'args'                => array(
                'page'     => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20),
            ),
        ));

        register_rest_route($ns, '/submissions/(?P<id>\d+)/orders', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'rest_submission_orders'),
            'permission_callback' => array(__CLASS__, 'orders_permission_check'),
            'args'                => array(
                'id' => array('type' => 'integer', 'minimum' => 1, 'required' => true),
            ),
        ));
    }

    /**
     * Ultimi ordini del negozio.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function rest_orders($request) {
        $per_page = (int) $request['per_page'];
        $result   = wc_get_orders(array(
            'type'     => 'shop_order',
            'limit'    => $per_page,
            'paged'    => (int) $request['page'],
            'orderby'  => 'date',
            'order'    => 'DESC',
            'paginate' => true,
        ));
        $items = array_map(array(__CLASS__, 'prepare_order'), $result->orders);

        $response = rest_ensure_response($items);
        $response->header('X-WP-Total', (string) (int) $result->total);
        $response->header('X-WP-TotalPages', (string) (int) $result->max_num_pages);
        return $response;
    }

    /**
     * Ordini dello stesso cliente di una risposta, più recenti prima.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_submission_orders($request) {
        $row = DBFB_Submissions_Repository::get((int) $request['id']);
        if (!$row) {
            return new WP_Error('dbfb_not_found', __('Risposta non trovata.', 'db-form-builder'), array('status' => 404));
        }
        $email  = DBFB_Submissions_Repository::subject_email($row);
        $linked = self::linked_order_ids((int) $row->id);
        $orders = self::orders_for_customer($email, $linked);

        $items = array();
        foreach ($orders as $order) {
            $item           = self::prepare_order($order);
            $item['linked'] = in_array((int) $order->get_id(), $linked, true);
            $items[]        = $item;
        }
        return rest_ensure_response(array(
            'has_email' => $email !== '',
            'customer'  => self::customer_summary($email),
            'orders'    => $items,
        ));
    }

    // =========================================================
    // ORDINI DEL CLIENTE
    // =========================================================

    /**
     * Ordini per email di fatturazione o account cliente, più quelli
     * collegati esplicitamente alla risposta (2.24.0).
     *
     * @param string $email  Email normalizzata, '' se la risposta non ne ha.
     * @param int[]  $extra  Id di ordini da includere comunque.
     * @return WC_Order[]
     */
    public static function orders_for_customer($email, $extra = array()) {
        $orders = array();
        $add = function ($found) use (&$orders) {
            foreach ((array) $found as $order) {
                if ($order instanceof WC_Order) {
                    $orders[$order->get_id()] = $order;
                }
            }
        };

        if ($email !== '') {
            $add(wc_get_orders(array(
                'type'          => 'shop_order',
                'billing_email' => $email,
                'limit'         => self::MAX_CUSTOMER_ORDERS,
                'orderby'       => 'date',
                'order'         => 'DESC',
            )));
            $user = get_user_by('email', $email);
            if ($user) {
                $add(wc_get_orders(array(
                    'type'        => 'shop_order',
                    'customer_id' => (int) $user->ID,
                    'limit'       => self::MAX_CUSTOMER_ORDERS,
                    'orderby'     => 'date',
                    'order'       => 'DESC',
                )));
            }
        }
        foreach ($extra as $order_id) {
            if (!isset($orders[(int) $order_id])) {
                $add(array(wc_get_order((int) $order_id)));
            }
        }

        uasort($orders, function ($a, $b) {
            $da = $a->get_date_created();
            $db = $b->get_date_created();
            return ($db ? $db->getTimestamp() : 0) - ($da ? $da->getTimestamp() : 0);
        });
        return array_slice(array_values($orders), 0, self::MAX_CUSTOMER_ORDERS);
    }

    /**
     * Cliente registrato con quell'email, se esiste.
     *
     * @param string $email
     * @return array|null {id, name, orders_count}
     */
    private static function customer_summary($email) {
        if ($email === '') return null;
        $user = get_user_by('email', $email);
        if (!$user) return null;
        $customer = new WC_Customer($user->ID);
        return array(
            'id'           => (int) $user->ID,
            'name'         => trim($customer->get_first_name() . ' ' . $customer->get_last_name()) ?: $user->display_name,
            'orders_count' => (int) $customer->get_order_count(),
        );
    }

    /**
     * Voce di elenco di un ordine. Solo i dati che servono a riconoscerlo.
     *
     * @param WC_Order $order
     * @return array
     */
    public static function prepare_order($order) {
        $created = $order->get_date_created();
        return array(
            'id'           => (int) $order->get_id(),
            'number'       => (string) $order->get_order_number(),
            'status'       => (string) $order->get_status(),
            'status_label' => wc_get_order_status_name($order->get_status()),
            'date'         => $created ? $created->date_i18n('Y-m-d H:i:s') : '',
            'total'        => html_entity_decode(wp_strip_all_tags($order->get_formatted_order_total()), ENT_QUOTES, 'UTF-8'),
            'customer'     => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'items'        => (int) $order->get_item_count(),
            'edit_url'     => $order->get_edit_order_url(),
            'needs_payment' => $order->needs_payment(),
        );
    }

    /**
     * Ordini creati da una risposta (tabella dei collegamenti, 2.24.0).
     *
     * @param int $submission_id
     * @return int[]
     */
    public static function linked_order_ids($submission_id) {
        return class_exists('DBFB_Links') ? DBFB_Links::objects_for_submission($submission_id, 'wc_order') : array();
    }
}
