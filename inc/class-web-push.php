<?php
/**
 * DBFB_Web_Push — invio Web Push senza librerie esterne (2.22.0).
 *
 *  - VAPID (RFC 8292): coppia di chiavi P-256 generata una volta e salvata
 *    in un'option non autoload; JWT firmato ES256 per ogni push service.
 *  - Cifratura del payload (RFC 8291, content-encoding aes128gcm): ECDH con
 *    chiave effimera, HKDF-SHA256, AES-128-GCM. Tutto con le funzioni
 *    OpenSSL di PHP (7.3+ per openssl_pkey_derive), quindi niente Composer
 *    e niente estensioni gmp/bcmath.
 *
 * I destinatari sono solo i push service dei browser (allowlist di host,
 * filter dbfb_push_allowed_hosts): un endpoint arbitrario trasformerebbe il
 * sito in un proxy per richieste verso host scelti dall'utente.
 *
 * @package DBFB
 * @since   2.22.0
 */

if (!defined('ABSPATH')) exit;

class DBFB_Web_Push {

    const KEYS_OPTION = 'dbfb_vapid_keys';

    // DER di SubjectPublicKeyInfo per una chiave EC P-256 non compressa, senza i 65 byte del punto.
    const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * Il server può inviare Web Push?
     *
     * @return bool
     */
    public static function is_supported() {
        return function_exists('openssl_pkey_new')
            && function_exists('openssl_pkey_derive')
            && function_exists('openssl_encrypt')
            && defined('OPENSSL_KEYTYPE_EC')
            && in_array('aes-128-gcm', array_map('strtolower', openssl_get_cipher_methods()), true);
    }

    // =========================================================
    // CHIAVI VAPID
    // =========================================================

    /**
     * Chiavi VAPID, generate al primo uso.
     *
     * @return array|null {private: PEM, public: base64url del punto non compresso}
     */
    public static function keys() {
        static $failed = false;
        $keys = get_option(self::KEYS_OPTION);
        if (is_array($keys) && !empty($keys['private']) && !empty($keys['public'])) {
            return $keys;
        }
        // Un solo tentativo per richiesta se la generazione non riesce.
        if ($failed || !self::is_supported()) return null;

        $key = self::new_ec_key();
        $pem = '';
        $exported = $key && (openssl_pkey_export($key, $pem) || openssl_pkey_export($key, $pem, null, self::openssl_config()));
        $raw      = $exported ? self::raw_public_key($key) : '';
        if ($raw === '') {
            $failed = true;
            DB_Form_Builder::debug_log('chiavi VAPID non generate: ' . (string) openssl_error_string());
            return null;
        }

        $keys = array('private' => $pem, 'public' => self::b64url_encode($raw));
        update_option(self::KEYS_OPTION, $keys, false);
        return $keys;
    }

    /**
     * Chiave pubblica VAPID per pushManager.subscribe() (applicationServerKey).
     *
     * @return string base64url, '' se il server non supporta Web Push.
     */
    public static function public_key() {
        $keys = self::keys();
        return $keys ? (string) $keys['public'] : '';
    }

    /**
     * Header Authorization VAPID per un endpoint.
     *
     * @param string $endpoint
     * @return string|WP_Error
     */
    public static function vapid_authorization($endpoint) {
        $keys = self::keys();
        if (!$keys) return new WP_Error('dbfb_push_unsupported', 'Web Push non supportato dal server.');

        $parts = wp_parse_url($endpoint);
        $aud   = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $claims = array(
            'aud' => $aud,
            'exp' => time() + 12 * HOUR_IN_SECONDS,
            'sub' => self::subject(),
        );
        $input = self::b64url_encode(wp_json_encode(array('typ' => 'JWT', 'alg' => 'ES256')))
            . '.' . self::b64url_encode(wp_json_encode($claims, JSON_UNESCAPED_SLASHES));

        $private = openssl_pkey_get_private($keys['private']);
        if (!$private || !openssl_sign($input, $der, $private, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('dbfb_push_sign', 'Firma VAPID non riuscita.');
        }
        $signature = self::der_to_raw_signature($der);
        if ($signature === '') {
            return new WP_Error('dbfb_push_sign', 'Firma VAPID non valida.');
        }
        return 'vapid t=' . $input . '.' . self::b64url_encode($signature) . ', k=' . $keys['public'];
    }

    /**
     * Contatto VAPID: l'URL del sito se in HTTPS, altrimenti l'email
     * dell'amministratore. I push service lo usano per segnalare abusi.
     *
     * @return string
     */
    private static function subject() {
        $home = home_url('/');
        $subject = (strpos($home, 'https://') === 0) ? $home : 'mailto:' . get_option('admin_email');
        return (string) apply_filters('dbfb_push_vapid_subject', $subject);
    }

    // =========================================================
    // CIFRATURA (RFC 8291)
    // =========================================================

    /**
     * Cifra un payload per una sottoscrizione.
     *
     * @param string $payload Testo in chiaro (max ~3 KB).
     * @param string $p256dh  Chiave pubblica del browser, base64url (65 byte).
     * @param string $auth    Segreto di autenticazione, base64url (16 byte).
     * @return string|WP_Error Corpo della richiesta (header aes128gcm + record cifrato).
     */
    public static function encrypt($payload, $p256dh, $auth) {
        $ua_public = self::b64url_decode($p256dh);
        $auth      = self::b64url_decode($auth);
        if (strlen($ua_public) !== 65 || $ua_public[0] !== "\x04" || strlen($auth) !== 16) {
            return new WP_Error('dbfb_push_keys', 'Chiavi della sottoscrizione non valide.');
        }

        $ua_key = openssl_pkey_get_public(self::raw_public_to_pem($ua_public));
        $as_key = self::new_ec_key();
        if (!$ua_key || !$as_key) {
            return new WP_Error('dbfb_push_keys', 'Chiavi EC non disponibili.');
        }
        $as_public = self::raw_public_key($as_key);
        $shared    = openssl_pkey_derive($ua_key, $as_key);
        if ($as_public === '' || $shared === false || strlen($shared) !== 32) {
            return new WP_Error('dbfb_push_ecdh', 'Accordo di chiavi ECDH non riuscito.');
        }

        // IKM = HKDF(auth, ECDH, "WebPush: info" || 0x00 || ua_public || as_public, 32)
        $ikm = self::hkdf($auth, $shared, "WebPush: info\x00" . $ua_public . $as_public, 32);

        $salt  = random_bytes(16);
        $cek   = self::hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

        // Un solo record: payload + delimitatore 0x02 (ultimo record), senza padding.
        $tag        = '';
        $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            return new WP_Error('dbfb_push_encrypt', 'Cifratura AES-GCM non riuscita.');
        }

        // Header: salt (16) || record size (uint32 BE) || lunghezza keyid (1) || keyid = as_public.
        return $salt . pack('N', 4096) . chr(65) . $as_public . $ciphertext . $tag;
    }

    /**
     * HKDF-SHA256 (RFC 5869) con un solo blocco di espansione (L <= 32).
     */
    private static function hkdf($salt, $ikm, $info, $length) {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $length);
    }

    // =========================================================
    // INVIO
    // =========================================================

    /**
     * Invia un push a una sottoscrizione.
     *
     * @param array  $subscription {endpoint, p256dh, auth}
     * @param string $payload      JSON da passare al service worker.
     * @param int    $ttl          Secondi di conservazione nel push service se il dispositivo è offline.
     * @return int|WP_Error Codice HTTP del push service.
     */
    public static function send($subscription, $payload, $ttl = DAY_IN_SECONDS) {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        if (!self::is_allowed_endpoint($endpoint)) {
            return new WP_Error('dbfb_push_endpoint', 'Endpoint non ammesso.');
        }
        $body = self::encrypt($payload, (string) ($subscription['p256dh'] ?? ''), (string) ($subscription['auth'] ?? ''));
        if (is_wp_error($body)) return $body;
        $authorization = self::vapid_authorization($endpoint);
        if (is_wp_error($authorization)) return $authorization;

        $response = wp_remote_post($endpoint, array(
            'timeout'     => 5,
            'redirection' => 0,
            'headers'     => array(
                'Authorization'    => $authorization,
                'Content-Encoding' => 'aes128gcm',
                'Content-Type'     => 'application/octet-stream',
                'TTL'              => (string) (int) $ttl,
                'Urgency'          => 'normal',
            ),
            'body'        => $body,
        ));
        if (is_wp_error($response)) return $response;
        return (int) wp_remote_retrieve_response_code($response);
    }

    /**
     * Endpoint ammesso: HTTPS verso un push service noto.
     *
     * @param string $endpoint
     * @return bool
     */
    public static function is_allowed_endpoint($endpoint) {
        $parts = wp_parse_url((string) $endpoint);
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host  = strtolower($parts['host']);
        $hosts = (array) apply_filters('dbfb_push_allowed_hosts', array(
            'fcm.googleapis.com',               // Chrome, Edge (Android), Opera, Samsung Internet
            'updates.push.services.mozilla.com', // Firefox
            'push.apple.com',                   // Safari (web.push.apple.com)
            'notify.windows.com',               // Edge su Windows (wns2-*.notify.windows.com)
        ));
        foreach ($hosts as $allowed) {
            $allowed = strtolower(ltrim((string) $allowed, '.'));
            if ($host === $allowed || substr($host, -strlen('.' . $allowed)) === '.' . $allowed) {
                return true;
            }
        }
        return false;
    }

    // =========================================================
    // HELPER EC / BASE64URL
    // =========================================================

    private static function new_ec_key() {
        $args = array(
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            // Ignorato per le chiavi EC, ma PHP 7.4 rifiuta la generazione
            // se vale 0 ("private key length is too short").
            'private_key_bits' => 2048,
        );
        $key = openssl_pkey_new($args);
        if ($key === false) {
            // Server senza openssl.cnf leggibile: configurazione minima del plugin.
            $key = openssl_pkey_new($args + self::openssl_config());
        }
        return $key;
    }

    /**
     * Configurazione OpenSSL minima del plugin, per i server senza un
     * openssl.cnf leggibile (usata solo come secondo tentativo).
     *
     * @return array
     */
    private static function openssl_config() {
        return array('config' => DBFB_PLUGIN_DIR . 'inc/openssl.cnf');
    }

    /**
     * Punto pubblico non compresso (0x04 || X || Y, 65 byte) di una chiave EC.
     */
    private static function raw_public_key($key) {
        $details = openssl_pkey_get_details($key);
        if (empty($details['ec']['x']) || empty($details['ec']['y'])) return '';
        return "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    }

    private static function raw_public_to_pem($raw) {
        $der = hex2bin(self::P256_SPKI_PREFIX) . $raw;
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- formato PEM della chiave.
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Firma ECDSA da DER (output di openssl_sign) a R || S da 32 byte (JWS).
     *
     * @param string $der
     * @return string 64 byte, '' se il DER non è valido.
     */
    public static function der_to_raw_signature($der) {
        $der = (string) $der;
        if (strlen($der) < 8 || $der[0] !== "\x30") return '';
        $offset = 2;
        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (!isset($der[$offset]) || $der[$offset] !== "\x02") return '';
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $offset += 2 + $len;
            $int = ltrim($int, "\x00");
            if (strlen($int) > 32) return '';
            $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
        }
        return $out;
    }

    public static function b64url_encode($data) {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url di chiavi e JWT (RFC 7515).
        return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
    }

    public static function b64url_decode($data) {
        $data = strtr((string) $data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        $out = base64_decode($data, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- chiavi della sottoscrizione push.
        return $out === false ? '' : $out;
    }
}
