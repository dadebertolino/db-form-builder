<?php
if (!defined('ABSPATH')) exit;

/**
 * Precompilazione dei campi da parametri dell'URL (2.15.0).
 *
 * Cache-safe: il server stampa solo il nome del parametro
 * (data-dbfb-prefill="arrivo") sul gruppo del campo, con markup identico per
 * tutti i visitatori. La lettura dell'URL e la validazione dei valori
 * avvengono in frontend.js (initPrefill). Lato server non cambia nulla:
 * all'invio vale la sanitizzazione per tipo già presente.
 */
class DBFB_Prefill {

    /** Lunghezza massima del nome del parametro. */
    const PARAM_MAX_LENGTH = 40;

    /** Lunghezza massima del valore di un campo nascosto. */
    const HIDDEN_MAX_LENGTH = 500;

    /**
     * Tipi di campo precompilabili da URL.
     *
     * Email e telefono esclusi per default: un dato personale nell'URL
     * finisce in log del server, cronologia del browser, header Referer e
     * strumenti di analytics. File e campi di layout esclusi sempre.
     * Il campo nascosto è ammesso a parte (vedi supports()).
     *
     * @return string[]
     */
    public static function allowed_types() {
        $types = apply_filters(
            'dbfb_prefill_allowed_types',
            ['text', 'textarea', 'number', 'date', 'url', 'select', 'radio', 'checkbox']
        );
        $never = ['file', 'divider', 'html', 'image', 'pagebreak', 'hidden'];
        return array_values(array_diff(array_map('sanitize_key', (array) $types), $never));
    }

    /**
     * Il tipo di campo accetta un parametro di precompilazione?
     */
    public static function supports($type) {
        return $type === 'hidden' || in_array($type, self::allowed_types(), true);
    }

    /**
     * Normalizza il nome del parametro: minuscole, solo [a-z0-9_-], max 40.
     */
    public static function sanitize_param($param) {
        $param = strtolower(trim((string) $param));
        $param = preg_replace('/[^a-z0-9_\-]/', '', $param);
        return substr($param, 0, self::PARAM_MAX_LENGTH);
    }

    /**
     * Attributo HTML per il gruppo del campo, '' se non precompilabile.
     *
     * @param array $field Campo salvato.
     * @return string Già escapato.
     */
    public static function attr($field) {
        $param = self::sanitize_param($field['prefill_param'] ?? '');
        if ($param === '' || !self::supports($field['type'] ?? '')) return '';
        return ' data-dbfb-prefill="' . esc_attr($param) . '"';
    }
}
