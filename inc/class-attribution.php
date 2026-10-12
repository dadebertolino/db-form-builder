<?php
if (!defined('ABSPATH')) exit;

/**
 * Provenienza della campagna (2.17.0).
 *
 * Opzione per form, disattivata di default. Gli UTM si leggono SOLO
 * dall'URL della pagina che contiene il form, al momento dell'invio: nessuna
 * scrittura sul dispositivo del visitatore (niente cookie, sessionStorage o
 * localStorage), quindi nessun consenso cookie richiesto (art. 5.3 ePrivacy).
 * Limite voluto: se il visitatore arriva su un'altra pagina e poi naviga
 * fino al form, gli UTM non vengono registrati.
 *
 * Cache-safe: il server stampa sul <form> solo l'elenco delle chiavi da
 * leggere, uguale per tutti; il valore arriva dal browser all'invio.
 *
 * I dati si salvano nella chiave riservata `_attribution` del JSON della
 * submission (come `_fields_snapshot`): nessuna migrazione dello schema.
 */
class DBFB_Attribution {

    /** Chiave riservata nel JSON della submission. */
    const DATA_KEY = '_attribution';

    /** Lunghezza massima di ogni valore. */
    const MAX_LENGTH = 150;

    /** Chiave per il path della pagina del form. */
    const LANDING_KEY = 'landing';

    /**
     * Parametri letti dall'URL.
     *
     * Per default solo gli UTM. Gli identificativi di click delle
     * piattaforme pubblicitarie (gclid, fbclid, msclkid, ...) sono
     * identificativi di tracciamento: si aggiungono solo con il filter
     * dbfb_attribution_keys e il browser li invia solo con il consenso
     * marketing (DB Cookie Manager o WP Consent API).
     *
     * @return string[]
     */
    public static function keys() {
        $defaults = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        $keys = apply_filters('dbfb_attribution_keys', $defaults);
        $clean = [];
        foreach ((array) $keys as $key) {
            $key = substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key)), 0, 40);
            if ($key !== '' && $key !== self::LANDING_KEY && !in_array($key, $clean, true)) {
                $clean[] = $key;
            }
            if (count($clean) >= 20) break;
        }
        return $clean;
    }

    /** Chiave che richiede il consenso marketing (tutto ciò che non è utm_*). */
    public static function needs_consent($key) {
        return strpos((string) $key, 'utm_') !== 0;
    }

    public static function is_enabled($settings) {
        return is_array($settings) && !empty($settings['track_attribution']);
    }

    /**
     * Attributi del <form>, '' se l'opzione è spenta. Già escapati.
     */
    public static function form_attrs($settings) {
        if (!self::is_enabled($settings)) return '';
        $category = sanitize_key(apply_filters('dbfb_attribution_consent_category', 'marketing'));
        return ' data-dbfb-attribution="' . esc_attr(implode(',', self::keys())) . '"'
            . ' data-dbfb-attribution-consent="' . esc_attr($category) . '"';
    }

    /**
     * Sanitizza i dati inviati dal browser: solo chiavi ammesse,
     * sanitize_text_field, massimo 150 caratteri; il path della pagina senza
     * query string né frammento.
     *
     * @param mixed $raw Array decodificato dal JSON del POST.
     * @return array Vuoto se non resta nulla.
     */
    public static function sanitize($raw) {
        if (!is_array($raw)) return [];
        $clean = [];
        foreach (self::keys() as $key) {
            if (!isset($raw[$key]) || !is_scalar($raw[$key])) continue;
            $value = mb_substr(sanitize_text_field((string) $raw[$key]), 0, self::MAX_LENGTH);
            if ($value !== '') $clean[$key] = $value;
        }
        if (isset($raw[self::LANDING_KEY]) && is_scalar($raw[self::LANDING_KEY])) {
            $path = (string) wp_parse_url((string) $raw[self::LANDING_KEY], PHP_URL_PATH);
            $path = mb_substr(sanitize_text_field($path), 0, self::MAX_LENGTH);
            if ($path !== '' && $path[0] === '/') $clean[self::LANDING_KEY] = $path;
        }
        return $clean;
    }

    /**
     * Dati di provenienza di una submission (dal JSON decodificato).
     */
    public static function from_data($data) {
        $value = is_array($data) ? ($data[self::DATA_KEY] ?? []) : [];
        return is_array($value) ? $value : [];
    }

    /**
     * Etichetta leggibile di una chiave.
     */
    public static function label($key) {
        $labels = [
            'utm_source'      => __('Sorgente (utm_source)', 'db-form-builder'),
            'utm_medium'      => __('Mezzo (utm_medium)', 'db-form-builder'),
            'utm_campaign'    => __('Campagna (utm_campaign)', 'db-form-builder'),
            'utm_term'        => __('Termine (utm_term)', 'db-form-builder'),
            'utm_content'     => __('Contenuto (utm_content)', 'db-form-builder'),
            self::LANDING_KEY => __('Pagina del form', 'db-form-builder'),
        ];
        return $labels[$key] ?? $key;
    }

    /**
     * Segnaposto email: {utm_source}, ..., {pagina_form}. Sempre presenti
     * (vuoti se mancano), così non restano mai letterali.
     */
    public static function placeholders($data) {
        $attr = self::from_data($data);
        $out = [];
        foreach (array_unique(array_merge(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'], self::keys())) as $key) {
            $out['{' . $key . '}'] = (string) ($attr[$key] ?? '');
        }
        $out['{pagina_form}'] = (string) ($attr[self::LANDING_KEY] ?? '');
        return $out;
    }
}
