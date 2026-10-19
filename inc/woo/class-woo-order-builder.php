<?php
/**
 * DBFB_Woo_Order_Builder — ordine WooCommerce da una risposta (2.24.0).
 *
 * Creazione manuale dalla web app, pensata per i preventivi:
 *  1. GET  /submissions/{id}/order-draft  dati di fatturazione ricavati dalla
 *     risposta (per tipo ed etichetta dei campi), modificabili prima di creare;
 *  2. POST /submissions/{id}/orders       ordine "in attesa di pagamento" con
 *     una riga personalizzata ("Preventivo #ID — titolo del form") e l'importo
 *     inserito; IVA secondo le impostazioni del negozio;
 *  3. POST /orders/{id}/send-invoice      email WooCommerce "Dettagli ordine"
 *     con il link di pagamento ($order->get_checkout_payment_url()).
 *
 * Collegamento nei due sensi: meta dell'ordine _dbfb_submission_id e riga in
 * wp_dbfb_links. Permesso: edit_shop_orders (filter dbfb_create_order_capability).
 *
 * @package DBFB
 * @since   2.24.0
 */

if (!defined('ABSPATH')) exit;

class DBFB_Woo_Order_Builder {

    const META_SUBMISSION = '_dbfb_submission_id';

    const BILLING_KEYS = array('first_name', 'last_name', 'company', 'email', 'phone', 'address_1', 'address_2', 'city', 'postcode', 'state', 'country');

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        // Ordine eliminato definitivamente: via il collegamento.
        add_action('woocommerce_delete_order', array(__CLASS__, 'on_order_deleted'));
    }

    public static function create_capability() {
        return (string) apply_filters('dbfb_create_order_capability', 'edit_shop_orders');
    }

    public static function current_user_can_create() {
        return DBFB_Roles::current_user_can_view() && current_user_can(self::create_capability());
    }

    /**
     * @return true|WP_Error
     */
    public static function permission_check() {
        $base = DBFB_REST::permission_check();
        if (is_wp_error($base)) return $base;
        if (!current_user_can(self::create_capability())) {
            return new WP_Error('rest_forbidden', __('Non hai i permessi per creare ordini.', 'db-form-builder'), array('status' => 403));
        }
        return true;
    }

    public static function on_order_deleted($order_id) {
        DBFB_Links::delete_for_object('wc_order', (int) $order_id);
    }

    // =========================================================
    // API REST
    // =========================================================

    public static function register_routes() {
        $ns = DBFB_REST::NAMESPACE_V1;

        register_rest_route($ns, '/submissions/(?P<id>\d+)/order-draft', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'rest_draft'),
            'permission_callback' => array(__CLASS__, 'permission_check'),
            'args'                => array('id' => array('type' => 'integer', 'minimum' => 1, 'required' => true)),
        ));

        $billing = array();
        foreach (self::BILLING_KEYS as $key) {
            $billing[$key] = array('type' => 'string');
        }
        register_rest_route($ns, '/submissions/(?P<id>\d+)/orders', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'rest_create'),
            'permission_callback' => array(__CLASS__, 'permission_check'),
            'args'                => array(
                'id'           => array('type' => 'integer', 'minimum' => 1, 'required' => true),
                'billing'      => array('type' => 'object', 'required' => true, 'properties' => $billing),
                'description'  => array('type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 200),
                'amount'       => array('type' => 'string', 'required' => true),
                'note'         => array('type' => 'string', 'default' => '', 'maxLength' => 2000),
                'send_invoice' => array('type' => 'boolean', 'default' => false),
            ),
        ));

        register_rest_route($ns, '/orders/(?P<id>\d+)/send-invoice', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'rest_send_invoice'),
            'permission_callback' => array(__CLASS__, 'permission_check'),
            'args'                => array('id' => array('type' => 'integer', 'minimum' => 1, 'required' => true)),
        ));
    }

    /**
     * Bozza dell'ordine: fatturazione ricavata dalla risposta.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_draft($request) {
        $row = DBFB_Submissions_Repository::get((int) $request['id']);
        if (!$row) return self::not_found();

        $countries = WC()->countries;
        return rest_ensure_response(array(
            'billing'            => self::billing_from_submission($row),
            'description'        => self::default_description($row),
            'currency'           => get_woocommerce_currency(),
            'currency_symbol'    => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
            'prices_include_tax' => wc_tax_enabled() && wc_prices_include_tax(),
            'tax_enabled'        => wc_tax_enabled(),
            'countries'          => (object) $countries->get_allowed_countries(),
            'linked_orders'      => DBFB_Links::objects_for_submission((int) $row->id, 'wc_order'),
        ));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_create($request) {
        $row = DBFB_Submissions_Repository::get((int) $request['id']);
        if (!$row) return self::not_found();

        $amount = self::parse_amount((string) $request['amount']);
        if ($amount <= 0) {
            return new WP_Error('dbfb_invalid_amount', __('Inserisci un importo maggiore di zero.', 'db-form-builder'), array('status' => 400, 'field' => 'amount'));
        }

        $billing = self::sanitize_billing((array) $request['billing']);
        if ($billing['email'] === '' && (bool) $request['send_invoice']) {
            return new WP_Error('dbfb_missing_email', __('Per inviare il link di pagamento serve l\'email del cliente.', 'db-form-builder'), array('status' => 400, 'field' => 'email'));
        }

        $order = self::create_order($row, $billing, sanitize_text_field((string) $request['description']), $amount, sanitize_textarea_field((string) $request['note']));
        if (is_wp_error($order)) return $order;

        $sent = false;
        if ((bool) $request['send_invoice']) {
            $sent = self::send_invoice($order);
        }
        return rest_ensure_response(self::order_result($order, $sent));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_send_invoice($request) {
        $order = wc_get_order((int) $request['id']);
        if (!$order || !$order->get_meta(self::META_SUBMISSION)) {
            return new WP_Error('dbfb_not_found', __('Ordine non trovato tra quelli creati dalle risposte.', 'db-form-builder'), array('status' => 404));
        }
        if (!$order->needs_payment() || $order->get_billing_email() === '') {
            return new WP_Error('dbfb_no_payment', __('Questo ordine non è in attesa di pagamento o non ha un\'email del cliente.', 'db-form-builder'), array('status' => 409));
        }
        return rest_ensure_response(self::order_result($order, self::send_invoice($order)));
    }

    // =========================================================
    // ORDINE
    // =========================================================

    /**
     * Crea l'ordine "in attesa di pagamento" con una riga personalizzata.
     *
     * @param object $row         Risposta.
     * @param array  $billing     Dati di fatturazione già sanificati.
     * @param string $description Nome della riga.
     * @param float  $amount      Importo: IVA inclusa se il negozio inserisce i prezzi IVA inclusa.
     * @param string $note        Nota privata facoltativa.
     * @return WC_Order|WP_Error
     */
    public static function create_order($row, $billing, $description, $amount, $note = '') {
        $customer = $billing['email'] !== '' ? get_user_by('email', $billing['email']) : false;
        $order = wc_create_order(array(
            'status'      => 'pending',
            'customer_id' => $customer ? (int) $customer->ID : 0,
            'created_via' => 'db-form-builder',
        ));
        if (is_wp_error($order)) return $order;

        foreach ($billing as $key => $value) {
            $setter = 'set_billing_' . $key;
            if ($value !== '' && is_callable(array($order, $setter))) {
                $order->{$setter}($value);
            }
        }

        // Riga senza prodotto del catalogo. Con l'IVA attiva l'importo segue
        // l'impostazione del negozio "prezzi inseriti con IVA inclusa / esclusa"
        // e le aliquote standard della sede del negozio.
        $taxes = wc_tax_enabled() ? WC_Tax::calc_tax($amount, WC_Tax::get_base_tax_rates(''), wc_prices_include_tax()) : array();
        $tax   = array_sum($taxes);
        $net   = (wc_tax_enabled() && wc_prices_include_tax()) ? $amount - $tax : $amount;

        $item = new WC_Order_Item_Product();
        $item->set_name($description);
        $item->set_quantity(1);
        $item->set_tax_class('');
        $item->set_subtotal(wc_format_decimal($net));
        $item->set_total(wc_format_decimal($net));
        if ($taxes) {
            $item->set_taxes(array('total' => $taxes, 'subtotal' => $taxes));
        }
        $order->add_item($item);
        $order->update_taxes();
        $order->calculate_totals(false);

        $order->update_meta_data(self::META_SUBMISSION, (int) $row->id);
        $order->save();

        $user = wp_get_current_user();
        $order->add_order_note(sprintf(
            /* translators: 1: ID della risposta, 2: titolo del form, 3: nome dell'utente */
            __('Ordine creato dalla risposta #%1$d al form "%2$s" da %3$s (DB Form Builder).', 'db-form-builder'),
            (int) $row->id,
            html_entity_decode(get_the_title((int) $row->form_id), ENT_QUOTES, 'UTF-8'),
            $user->exists() ? $user->display_name : 'sistema'
        ));
        if ($note !== '') {
            $order->add_order_note($note);
        }

        DBFB_Links::add((int) $row->id, 'wc_order', (int) $order->get_id());
        do_action('dbfb_order_created_from_submission', $order, (int) $row->id);
        return $order;
    }

    /**
     * Email WooCommerce "Dettagli ordine" (customer invoice) con il link di pagamento.
     *
     * @param WC_Order $order
     * @return bool
     */
    public static function send_invoice($order) {
        if ($order->get_billing_email() === '') return false;

        // Come il pulsante "Invia di nuovo i dettagli dell'ordine" di WooCommerce:
        // email manuale, senza il controllo "abilitata" delle email automatiche.
        $failed = false;
        $watch  = function () use (&$failed) {
            $failed = true;
        };
        add_action('wp_mail_failed', $watch);
        do_action('woocommerce_before_resend_order_emails', $order, 'customer_invoice');
        WC()->payment_gateways();
        WC()->shipping();
        WC()->mailer()->customer_invoice($order);
        do_action('woocommerce_after_resend_order_email', $order, 'customer_invoice');
        remove_action('wp_mail_failed', $watch);

        if ($failed) {
            $order->add_order_note(__('Invio del link di pagamento al cliente non riuscito (DB Form Builder).', 'db-form-builder'));
            return false;
        }
        $order->add_order_note(__('Link di pagamento inviato al cliente dalla web app (DB Form Builder).', 'db-form-builder'));
        return true;
    }

    private static function order_result($order, $invoice_sent) {
        $out = DBFB_Woo::prepare_order($order);
        $out['payment_url']  = $order->needs_payment() ? $order->get_checkout_payment_url() : '';
        $out['invoice_sent'] = (bool) $invoice_sent;
        $out['email']        = $order->get_billing_email();
        return $out;
    }

    // =========================================================
    // FATTURAZIONE DALLA RISPOSTA
    // =========================================================

    /**
     * Dati di fatturazione dedotti dai campi della risposta.
     *
     * Regole: email = interessato; telefono = campo "tel" o etichetta
     * telefono/cellulare; il resto per etichetta (nome, cognome, "nome e
     * cognome", azienda/ragione sociale, indirizzo/via, città/comune, CAP,
     * provincia). Paese: quello del negozio. Filter dbfb_order_billing_from_submission.
     *
     * @param object $row
     * @return array
     */
    public static function billing_from_submission($row) {
        $billing = array_fill_keys(self::BILLING_KEYS, '');
        $billing['email']   = DBFB_Submissions_Repository::subject_email($row);
        $billing['country'] = WC()->countries->get_base_country();

        $fields = get_post_meta((int) $row->form_id, '_dbfb_fields', true);
        $info   = DB_Form_Builder::get_submission_fields($row, is_array($fields) ? $fields : array());

        $patterns = array(
            'full_name'  => '/^(nome e cognome|nome cognome|nominativo|nome completo|full name|name and surname)$/u',
            'first_name' => '/^(nome|first name|firstname|name)$/u',
            'last_name'  => '/^(cognome|last name|lastname|surname)$/u',
            'company'    => '/(azienda|ragione sociale|società|societa|ditta|company)/u',
            'phone'      => '/(telefono|cellulare|tel\.?$|phone|mobile)/u',
            'address_1'  => '/^(indirizzo|via|address|indirizzo e numero civico)/u',
            'city'       => '/^(città|citta|comune|località|localita|city|town)$/u',
            'postcode'   => '/^(cap|c\.a\.p\.|codice postale|postcode|zip|zip code)$/u',
            'state'      => '/^(provincia|prov\.?|state|county)$/u',
        );

        foreach ($info['fields'] as $f) {
            $value = $info['data'][$f['id']] ?? '';
            if (!is_scalar($value)) continue;
            $value = trim((string) $value);
            if ($value === '') continue;
            $label = mb_strtolower(trim(wp_strip_all_tags((string) $f['label'])));

            if ($f['type'] === 'tel' && $billing['phone'] === '') {
                $billing['phone'] = $value;
                continue;
            }
            foreach ($patterns as $key => $re) {
                if (!preg_match($re, $label)) continue;
                if ($key === 'full_name') {
                    if ($billing['first_name'] === '') {
                        $parts = preg_split('/\s+/', $value, 2);
                        $billing['first_name'] = $parts[0];
                        $billing['last_name']  = $billing['last_name'] !== '' ? $billing['last_name'] : ($parts[1] ?? '');
                    }
                } elseif ($billing[$key] === '') {
                    $billing[$key] = $value;
                }
                break;
            }
        }

        // Provincia italiana scritta per esteso o in minuscolo: sigla se riconosciuta.
        if ($billing['state'] !== '') {
            $states = WC()->countries->get_states($billing['country']);
            if (is_array($states) && $states) {
                $upper = strtoupper($billing['state']);
                if (isset($states[$upper])) {
                    $billing['state'] = $upper;
                } else {
                    $found = array_search(mb_strtolower($billing['state']), array_map('mb_strtolower', $states), true);
                    $billing['state'] = $found !== false ? (string) $found : $billing['state'];
                }
            }
        }

        return self::sanitize_billing((array) apply_filters('dbfb_order_billing_from_submission', $billing, $row));
    }

    private static function default_description($row) {
        return sprintf(
            /* translators: 1: ID della risposta, 2: titolo del form */
            __('Preventivo #%1$d — %2$s', 'db-form-builder'),
            (int) $row->id,
            html_entity_decode(get_the_title((int) $row->form_id), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * @param array $input
     * @return array Tutte le chiavi di BILLING_KEYS, stringhe sanificate.
     */
    public static function sanitize_billing($input) {
        $out = array();
        foreach (self::BILLING_KEYS as $key) {
            $value = isset($input[$key]) && is_scalar($input[$key]) ? (string) $input[$key] : '';
            switch ($key) {
                case 'email':
                    $value = sanitize_email($value);
                    $value = is_email($value) ? strtolower($value) : '';
                    break;
                case 'country':
                    $value = strtoupper(sanitize_key($value));
                    $value = isset(WC()->countries->get_allowed_countries()[$value]) ? $value : WC()->countries->get_base_country();
                    break;
                case 'phone':
                    // Cifre, +, spazi e separatori comuni: leggibile come è stato scritto.
                    $value = trim(preg_replace('/[^\d+\s().\/-]/', '', sanitize_text_field($value)));
                    break;
                default:
                    $value = sanitize_text_field($value);
            }
            $out[$key] = mb_substr($value, 0, 200);
        }
        return $out;
    }

    /**
     * Importo da testo, con virgola o punto decimale ("1.250,50", "1250.5").
     *
     * @param string $raw
     * @return float
     */
    public static function parse_amount($raw) {
        $raw = preg_replace('/[^\d,.\-]/', '', trim($raw));
        if ($raw === '' || $raw === null) return 0.0;
        $last_comma = strrpos($raw, ',');
        $last_dot   = strrpos($raw, '.');
        if ($last_comma !== false && ($last_dot === false || $last_comma > $last_dot)) {
            // Virgola decimale: i punti sono separatori delle migliaia.
            $raw = str_replace(array('.', ','), array('', '.'), $raw);
        } else {
            $raw = str_replace(',', '', $raw);
        }
        return round((float) $raw, wc_get_price_decimals());
    }

    private static function not_found() {
        return new WP_Error('dbfb_not_found', __('Risposta non trovata.', 'db-form-builder'), array('status' => 404));
    }
}
