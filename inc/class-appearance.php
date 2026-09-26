<?php
/**
 * Aspetto del form sul frontend (2.12.0).
 *
 * I colori del form sono variabili CSS definite su .dbfb-form in
 * frontend.css, con i valori storici come default. Questa classe calcola i
 * valori effettivi (impostazione del form → impostazione globale → default
 * del CSS) e li emette come style inline sul tag <form>.
 *
 * L'admin sceglie solo tre colori: sfondo, pulsanti, testo. Gli altri
 * vengono derivati qui per mantenere il contrasto WCAG AA:
 *  - testo dei pulsanti: bianco o nero, quello con contrasto maggiore
 *    (per qualunque colore uno dei due supera sempre 4.5:1);
 *  - hover dei pulsanti: scurito con testo bianco, schiarito con testo nero;
 *  - colore dei link: il colore dei pulsanti se leggibile sullo sfondo,
 *    altrimenti il colore del testo (i link restano sottolineati);
 *  - testo secondario: uguale al testo quando il testo è personalizzato.
 *
 * @package DBFB
 * @since   2.12.0
 */

if (!defined('ABSPATH')) exit;

class DBFB_Appearance {

    /** Chiavi dei colori, identiche in impostazioni globali e del form. */
    const KEYS = array('color_bg', 'color_primary', 'color_text');

    /**
     * Normalizza un colore esadecimale (#rgb o #rrggbb) in #rrggbb minuscolo.
     *
     * @param mixed $color
     * @return string Colore normalizzato, o '' se vuoto/non valido (= default).
     */
    public static function sanitize_color($color) {
        $color = sanitize_hex_color(trim((string) $color));
        if (!$color) return '';
        if (strlen($color) === 4) {
            $color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
        }
        return strtolower($color);
    }

    /**
     * Colori effettivi: il valore del form prevale su quello globale.
     *
     * @param array $form_settings
     * @return array {color_bg, color_primary, color_text} ('' = default CSS)
     */
    public static function get_colors($form_settings) {
        $global = DB_Form_Builder::get_global_settings();
        $colors = array();
        foreach (self::KEYS as $key) {
            $value = self::sanitize_color($form_settings[$key] ?? '');
            if ($value === '') {
                $value = self::sanitize_color($global[$key] ?? '');
            }
            $colors[$key] = $value;
        }
        return $colors;
    }

    /**
     * Variabili CSS da emettere per un form.
     *
     * @param array $form_settings
     * @return array Mappa "--dbfb-xxx" => valore (vuota se tutto default).
     */
    public static function get_css_vars($form_settings) {
        $c = self::get_colors($form_settings);
        $vars = array();

        if ($c['color_bg'] !== '') {
            $vars['--dbfb-bg'] = $c['color_bg'];
        }
        if ($c['color_text'] !== '') {
            $vars['--dbfb-text'] = $c['color_text'];
            $vars['--dbfb-text-muted'] = $c['color_text'];
        }
        if ($c['color_primary'] !== '') {
            $primary = $c['color_primary'];
            $on_primary = self::best_text_color($primary);
            $vars['--dbfb-primary'] = $primary;
            $vars['--dbfb-button-text'] = $on_primary;
            $vars['--dbfb-primary-hover'] = $on_primary === '#ffffff'
                ? self::mix($primary, '#000000', 0.2)
                : self::mix($primary, '#ffffff', 0.25);
        }

        // Link: il colore dei pulsanti solo se leggibile sullo sfondo.
        if ($c['color_bg'] !== '' || $c['color_primary'] !== '') {
            $bg      = $c['color_bg'] !== '' ? $c['color_bg'] : '#ffffff';
            $primary = $c['color_primary'] !== '' ? $c['color_primary'] : '#0056b3';
            $text    = $c['color_text'] !== '' ? $c['color_text'] : '#1a1a1a';
            if (self::contrast($primary, $bg) < 4.5) {
                $vars['--dbfb-link'] = $text;
            }
        }

        return $vars;
    }

    /**
     * Attributo style inline per il tag <form> (già escapato).
     *
     * @param array $form_settings
     * @return string Es. ' style="--dbfb-bg:#101010;"' oppure ''.
     */
    public static function style_attribute($form_settings) {
        $vars = self::get_css_vars($form_settings);
        if (empty($vars)) return '';
        $css = '';
        foreach ($vars as $name => $value) {
            $css .= $name . ':' . $value . ';';
        }
        return ' style="' . esc_attr($css) . '"';
    }

    /**
     * Il form ha uno sfondo proprio? Serve per aggiungere padding e bordi
     * arrotondati (classe dbfb-has-bg), altrimenti il testo tocca i bordi.
     */
    public static function has_background($form_settings) {
        $c = self::get_colors($form_settings);
        return $c['color_bg'] !== '';
    }

    /* =================================================================
     * UTILITÀ COLORE (WCAG 2.1)
     * =============================================================== */

    /**
     * Luminanza relativa WCAG di un colore #rrggbb.
     */
    public static function luminance($hex) {
        $rgb = self::to_rgb($hex);
        $lin = array_map(function ($c) {
            $c = $c / 255;
            return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }, $rgb);
        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    /**
     * Rapporto di contrasto WCAG fra due colori (1–21).
     */
    public static function contrast($a, $b) {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Bianco o nero, quello con contrasto maggiore sul colore dato.
     */
    public static function best_text_color($bg) {
        return self::contrast($bg, '#ffffff') >= self::contrast($bg, '#000000') ? '#ffffff' : '#000000';
    }

    /**
     * Mescola $a verso $b della quantità $amount (0–1).
     */
    public static function mix($a, $b, $amount) {
        $ra = self::to_rgb($a);
        $rb = self::to_rgb($b);
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= sprintf('%02x', (int) round($ra[$i] + ($rb[$i] - $ra[$i]) * $amount));
        }
        return $out;
    }

    private static function to_rgb($hex) {
        $hex = ltrim(self::sanitize_color($hex) ?: '#000000', '#');
        return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }
}
