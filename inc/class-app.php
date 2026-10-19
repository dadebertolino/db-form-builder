<?php
/**
 * DBFB_App — web app (PWA) per consultare le risposte (2.21.0).
 *
 * Rotte sul frontend del sito, servite prima del tema:
 *  - /dbfb-app/                       shell HTML dell'app
 *  - /dbfb-app/sw.js                  service worker (scope /dbfb-app/)
 *  - /dbfb-app/manifest.webmanifest   manifest per l'installazione
 *
 * Con i permalink "semplici" le stesse rotte rispondono a ?dbfb_app=shell |
 * sw | manifest, ma il service worker non viene registrato (il suo scope
 * sarebbe l'intero sito).
 *
 * Privacy: la shell non contiene dati personali né nonce (l'app li chiede
 * a runtime: nonce da admin-ajax "rest-nonce", dati dalle API dbfb/v1), quindi
 * il service worker può metterla in cache per l'avvio offline. Le risposte
 * delle API non vengono mai salvate in cache.
 *
 * @package DBFB
 * @since   2.21.0
 */

if (!defined('ABSPATH')) exit;

class DBFB_App {

    const SLUG            = 'dbfb-app';
    const QUERY_VAR       = 'dbfb_app';
    const REWRITE_VERSION = 1;
    const REWRITE_OPTION  = 'dbfb_app_rewrite_version';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_rewrites'));
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        // Priorità 0: prima di redirect canonici e plugin di cache.
        add_action('template_redirect', array(__CLASS__, 'maybe_serve'), 0);
        add_filter('login_redirect', array(__CLASS__, 'login_redirect'), 10, 3);
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 20);
    }

    // =========================================================
    // ROTTE
    // =========================================================

    public static function register_rewrites() {
        add_rewrite_rule('^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=shell', 'top');
        add_rewrite_rule('^' . self::SLUG . '/sw\.js$', 'index.php?' . self::QUERY_VAR . '=sw', 'top');
        add_rewrite_rule('^' . self::SLUG . '/manifest\.webmanifest$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top');

        // Flush una sola volta per versione delle regole: l'auto-updater non
        // passa dall'activation hook.
        if ((int) get_option(self::REWRITE_OPTION, 0) !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option(self::REWRITE_OPTION, self::REWRITE_VERSION, false);
        }
    }

    public static function query_vars($vars) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * Permalink "belli" attivi? Senza, niente service worker.
     *
     * @return bool
     */
    public static function has_pretty_permalinks() {
        return (bool) get_option('permalink_structure');
    }

    /**
     * URL di una rotta dell'app.
     *
     * @param string $what 'shell' | 'sw' | 'manifest'
     * @return string
     */
    public static function url($what = 'shell') {
        if (!self::has_pretty_permalinks()) {
            return add_query_arg(self::QUERY_VAR, $what, home_url('/'));
        }
        $paths = array(
            'shell'    => '',
            'sw'       => 'sw.js',
            'manifest' => 'manifest.webmanifest',
        );
        return home_url('/' . self::SLUG . '/' . ($paths[$what] ?? ''));
    }

    public static function maybe_serve() {
        $what = (string) get_query_var(self::QUERY_VAR);
        if ($what === '') return;

        switch ($what) {
            case 'sw':
                self::serve_service_worker();
                break;
            case 'manifest':
                self::serve_manifest();
                break;
            default:
                self::serve_shell();
        }
        exit;
    }

    // =========================================================
    // RISPOSTE
    // =========================================================

    private static function serve_shell() {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::url()));
            exit;
        }
        if (!DBFB_Roles::current_user_can_view()) {
            wp_die(
                esc_html__('Il tuo account non ha accesso alle risposte dei form. Chiedi all\'amministratore il ruolo "Gestore form".', 'db-form-builder'),
                esc_html__('Accesso non consentito', 'db-form-builder'),
                array('response' => 403, 'back_link' => true)
            );
        }

        self::no_cache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Security-Policy: ' . self::content_security_policy());
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');

        $config = self::client_config();
        include DBFB_PLUGIN_DIR . 'templates/app/shell.php';
    }

    private static function serve_service_worker() {
        self::no_cache_headers();
        header('Content-Type: application/javascript; charset=utf-8');

        // Versione e asset iniettati qui: il file statico resta leggibile.
        $vars = array(
            'VERSION' => DBFB_VERSION,
            'SHELL'   => self::url(),
            'ASSETS'  => array_values(self::asset_urls()),
        );
        echo 'self.DBFB_SW = ' . wp_json_encode($vars, JSON_UNESCAPED_SLASHES) . ";\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON per uno script JS, non HTML.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.EscapeOutput.OutputNotEscaped -- file JS locale del plugin.
        echo file_get_contents(DBFB_PLUGIN_DIR . 'assets/app/sw.js');
    }

    private static function serve_manifest() {
        self::no_cache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');

        $assets = self::asset_urls();
        $start  = self::url();
        $scope  = self::has_pretty_permalinks() ? $start : home_url('/');
        $manifest = array(
            'id'               => $start,
            'name'             => __('Risposte ai form', 'db-form-builder') . ' — ' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'short_name'       => __('Risposte', 'db-form-builder'),
            'description'      => __('Le risposte ai form del sito, da smartphone o PC.', 'db-form-builder'),
            'lang'             => 'it',
            'start_url'        => $start,
            'scope'            => $scope,
            'display'          => 'standalone',
            'background_color' => '#ffffff',
            'theme_color'      => '#2271b1',
            'icons'            => array(
                array('src' => $assets['icon-192'], 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'),
                array('src' => $assets['icon-512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'),
                array('src' => $assets['icon-maskable'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'),
            ),
        );
        echo wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON del manifest.
    }

    /**
     * Header anti-cache per shell, manifest e service worker.
     *
     * DONOTCACHEPAGE è rispettata da WP Rocket, W3TC, WP Super Cache,
     * LiteSpeed Cache e altri plugin di cache di pagina.
     */
    private static function no_cache_headers() {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
    }

    /**
     * CSP della shell: script e stili solo dal sito (o dall'host degli asset
     * del plugin, se diverso, es. una CDN), dati solo verso il sito.
     *
     * @return string
     */
    private static function content_security_policy() {
        $self   = "'self'";
        $assets = wp_parse_url(DBFB_PLUGIN_URL);
        if (!empty($assets['host'])) {
            $origin = ($assets['scheme'] ?? 'https') . '://' . $assets['host'] . (isset($assets['port']) ? ':' . $assets['port'] : '');
            $self  .= ' ' . $origin;
        }
        return implode('; ', array(
            "default-src 'self'",
            'script-src ' . $self,
            'style-src ' . $self,
            'img-src ' . $self . ' data:',
            'manifest-src ' . $self,
            "connect-src 'self'",
            "worker-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "form-action 'self'",
        ));
    }

    /**
     * Asset statici dell'app, versionati (il service worker li tiene in cache).
     *
     * @return array chiave => URL
     */
    public static function asset_urls() {
        $base = DBFB_PLUGIN_URL . 'assets/app/';
        $ver  = rawurlencode(DBFB_VERSION);
        $out  = array();
        $files = array(
            'css'           => 'app.css',
            'js'            => 'app.js',
            'icon-192'      => 'icon-192.png',
            'icon-512'      => 'icon-512.png',
            'icon-maskable' => 'icon-maskable-512.png',
            'apple-touch'   => 'apple-touch-icon.png',
            'icon-svg'      => 'icon.svg',
        );
        foreach ($files as $key => $file) {
            $out[$key] = $base . $file . '?ver=' . $ver;
        }
        return $out;
    }

    /**
     * Configurazione per app.js. Solo valori del sito, uguali per tutti gli
     * utenti: la shell resta priva di dati personali e può stare in cache.
     *
     * @return array
     */
    private static function client_config() {
        return array(
            'version'   => DBFB_VERSION,
            'siteName'  => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'appUrl'    => self::url(),
            'restRoot'  => esc_url_raw(rest_url(DBFB_REST::NAMESPACE_V1 . '/')),
            'nonceUrl'  => add_query_arg('action', 'rest-nonce', admin_url('admin-ajax.php')),
            'loginUrl'  => wp_login_url(self::url()),
            'swUrl'     => self::has_pretty_permalinks() ? self::url('sw') : '',
            'swScope'   => self::has_pretty_permalinks() ? wp_parse_url(self::url(), PHP_URL_PATH) : '',
            'perPage'   => 20,
            'strings'   => self::strings(),
        );
    }

    private static function strings() {
        return array(
            'appTitle'       => __('Risposte', 'db-form-builder'),
            'allForms'       => __('Tutti i form', 'db-form-builder'),
            'allSubmissions' => __('Tutte le risposte', 'db-form-builder'),
            'back'           => __('Indietro', 'db-form-builder'),
            'loading'        => __('Caricamento…', 'db-form-builder'),
            'loadMore'       => __('Carica altre', 'db-form-builder'),
            'noForms'        => __('Nessun form sul sito.', 'db-form-builder'),
            'noSubmissions'  => __('Nessuna risposta.', 'db-form-builder'),
            'total'          => __('Totale', 'db-form-builder'),
            'tabAll'         => __('Tutte', 'db-form-builder'),
            'tabNew'         => __('Nuove', 'db-form-builder'),
            'tabRead'        => __('Lette', 'db-form-builder'),
            'tabArchived'    => __('Archiviate', 'db-form-builder'),
            'statusNew'      => __('Nuova', 'db-form-builder'),
            'statusRead'     => __('Letta', 'db-form-builder'),
            'statusArchived' => __('Archiviata', 'db-form-builder'),
            'filterStatus'   => __('Filtra per stato', 'db-form-builder'),
            /* translators: %d: numero di risposte nuove */
            'newCount'       => __('%d nuove', 'db-form-builder'),
            /* translators: %d: ID della risposta */
            'submission'     => __('Risposta #%d', 'db-form-builder'),
            'archive'        => __('Archivia', 'db-form-builder'),
            'restore'        => __('Ripristina', 'db-form-builder'),
            'markNew'        => __('Segna come nuova', 'db-form-builder'),
            'markRead'       => __('Segna come letta', 'db-form-builder'),
            'statusChanged'  => __('Stato aggiornato.', 'db-form-builder'),
            'empty'          => __('(vuoto)', 'db-form-builder'),
            'attachments'    => __('Allegati', 'db-form-builder'),
            'consent'        => __('Consenso al trattamento', 'db-form-builder'),
            'consentGiven'   => __('Dato', 'db-form-builder'),
            'consentNone'    => __('Modulo senza checkbox di consenso', 'db-form-builder'),
            'optionalConsents' => __('Consensi facoltativi', 'db-form-builder'),
            'yes'            => __('Sì', 'db-form-builder'),
            'no'             => __('No', 'db-form-builder'),
            'revoked'        => __('Revocato', 'db-form-builder'),
            'attribution'    => __('Provenienza', 'db-form-builder'),
            'logout'         => __('Esci', 'db-form-builder'),
            'themeDark'      => __('Tema scuro', 'db-form-builder'),
            'offline'        => __('Sei offline: i dati non si aggiornano finché la connessione non torna.', 'db-form-builder'),
            'sessionExpired' => __('La sessione è scaduta.', 'db-form-builder'),
            'login'          => __('Accedi', 'db-form-builder'),
            'forbidden'      => __('Il tuo account non ha accesso alle risposte.', 'db-form-builder'),
            'error'          => __('Si è verificato un errore. Riprova.', 'db-form-builder'),
            'unreachable'    => __('Il sito non risponde. Controlla la connessione e riprova.', 'db-form-builder'),
            'retry'          => __('Riprova', 'db-form-builder'),
            'notFound'       => __('Risposta non trovata: potrebbe essere stata eliminata.', 'db-form-builder'),
            'rateLimited'    => __('Troppe richieste in poco tempo. Riprova tra un minuto.', 'db-form-builder'),
            'linkExpires'    => __('I link agli allegati scadono dopo 15 minuti: ricarica la pagina per generarne di nuovi.', 'db-form-builder'),
            // 2.22.0: aggiornamenti e notifiche.
            'newArrived'     => __('Sono arrivate nuove risposte.', 'db-form-builder'),
            'showNew'        => __('Mostra le nuove risposte', 'db-form-builder'),
            'notifications'  => __('Notifiche', 'db-form-builder'),
            'pushOff'        => __('Ricevi un avviso su questo dispositivo quando arriva una risposta.', 'db-form-builder'),
            'pushOn'         => __('Le notifiche sono attive su questo dispositivo.', 'db-form-builder'),
            'pushEnable'     => __('Attiva le notifiche', 'db-form-builder'),
            'pushDisable'    => __('Disattiva', 'db-form-builder'),
            'pushTest'       => __('Invia una prova', 'db-form-builder'),
            'pushTestSent'   => __('Notifica di prova inviata.', 'db-form-builder'),
            'pushTestFailed' => __('Invio non riuscito. Prova a disattivare e riattivare le notifiche.', 'db-form-builder'),
            'pushDenied'     => __('Le notifiche sono bloccate per questo sito nelle impostazioni del browser.', 'db-form-builder'),
            'pushUnsupported' => __('Questo browser non supporta le notifiche push.', 'db-form-builder'),
            'pushIos'        => __('Su iPhone e iPad le notifiche funzionano solo con l\'app aggiunta alla schermata Home: tocca Condividi → "Aggiungi alla schermata Home" e apri l\'app da lì.', 'db-form-builder'),
            'pushError'      => __('Non è stato possibile attivare le notifiche. Riprova.', 'db-form-builder'),
        );
    }

    // =========================================================
    // INTEGRAZIONE CON L'ADMIN
    // =========================================================

    /**
     * Dopo il login, chi ha solo accesso alle risposte (ruolo "Gestore
     * form") va direttamente all'app invece che alla bacheca.
     *
     * @param string           $redirect_to
     * @param string           $requested
     * @param WP_User|WP_Error $user
     * @return string
     */
    public static function login_redirect($redirect_to, $requested, $user) {
        if (!($user instanceof WP_User) || !$user->has_cap(DBFB_Roles::CAP) || $user->has_cap('edit_posts')) {
            return $redirect_to;
        }
        // Rispetta un redirect esplicito diverso dalla bacheca.
        if ($requested !== '' && strpos($requested, admin_url()) !== 0) {
            return $redirect_to;
        }
        return self::url();
    }

    public static function admin_menu() {
        add_submenu_page(
            'dbfb-forms',
            __('Web app', 'db-form-builder'),
            __('Web app', 'db-form-builder'),
            'manage_options',
            'dbfb-app',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'db-form-builder'));
        }
        $app_url    = self::url();
        $pretty     = self::has_pretty_permalinks();
        $role_name  = __('Gestore form', 'db-form-builder');
        // 2.22.0: notifiche.
        $notify         = DBFB_Notifications::settings();
        $push_supported = DBFB_Web_Push::is_supported();
        include DBFB_PLUGIN_DIR . 'templates/admin/app.php';
    }
}
