<?php
/**
 * DBFB_App_Branding — identità del sito nella web app (2.25.0).
 *
 * La web app prende l'identità del sito che la usa:
 *  - nome dell'app (schermata Home, barra, titolo), di default il nome del sito;
 *  - colore principale, da cui si ricavano tutti i colori dell'interfaccia
 *    con contrasto WCAG AA garantito (testi e pulsanti su chiaro e su scuro);
 *  - icona: l'Icona del sito di WordPress (Personalizza → Identità del sito),
 *    con quella del plugin come ripiego.
 *
 * Impostazioni in Form Builder → Web app, option dbfb_app_branding.
 *
 * @package DBFB
 * @since   2.25.0
 */

if (!defined('ABSPATH')) exit;

class DBFB_App_Branding {

    const OPTION        = 'dbfb_app_branding';
    const DEFAULT_COLOR = '#2271b1';

    // Sfondi su cui i colori derivati devono restare leggibili (vedi app.css).
    const LIGHT_BG = '#f6f7f7';
    const DARK_BG  = '#1f2328';

    public static function init() {
        add_action('admin_post_dbfb_save_app_branding', array(__CLASS__, 'handle_save'));
    }

    /**
     * @return array {name: string, color: string}
     */
    public static function settings() {
        $saved = get_option(self::OPTION, array());
        $saved = is_array($saved) ? $saved : array();
        $name  = trim((string) ($saved['name'] ?? ''));
        $color = sanitize_hex_color((string) ($saved['color'] ?? ''));
        return array(
            'name'  => $name !== '' ? $name : wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'color' => $color ? strtolower($color) : self::DEFAULT_COLOR,
            'custom_name' => $name !== '',
        );
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'db-form-builder'), '', array('response' => 403));
        }
        check_admin_referer('dbfb_save_app_branding');

        $name  = isset($_POST['app_name']) ? sanitize_text_field(wp_unslash($_POST['app_name'])) : '';
        $color = isset($_POST['app_color']) ? sanitize_hex_color(wp_unslash($_POST['app_color'])) : '';
        if (!empty($_POST['reset'])) {
            $name  = '';
            $color = '';
        }
        update_option(self::OPTION, array(
            'name'  => mb_substr($name, 0, 40),
            'color' => $color ? strtolower($color) : '',
        ), false);
        wp_safe_redirect(add_query_arg('dbfb_branding_saved', '1', admin_url('admin.php?page=dbfb-app')) . '#aspetto');
        exit;
    }

    // =========================================================
    // ICONE
    // =========================================================

    /**
     * Icone dell'app: Icona del sito se impostata, altrimenti quelle del plugin.
     *
     * @return array {site: bool, 192, 512, maskable, apple, favicon, favicon_type}
     */
    public static function icons() {
        $plugin = DBFB_App::asset_urls();
        if (function_exists('has_site_icon') && has_site_icon()) {
            return array(
                'site'         => true,
                '192'          => get_site_icon_url(192),
                '512'          => get_site_icon_url(512),
                // L'icona del sito non è pensata per il ritaglio "maskable".
                'maskable'     => '',
                'apple'        => get_site_icon_url(180),
                'favicon'      => get_site_icon_url(32),
                'favicon_type' => '',
            );
        }
        return array(
            'site'         => false,
            '192'          => $plugin['icon-192'],
            '512'          => $plugin['icon-512'],
            'maskable'     => $plugin['icon-maskable'],
            'apple'        => $plugin['apple-touch'],
            'favicon'      => $plugin['icon-svg'],
            'favicon_type' => 'image/svg+xml',
        );
    }

    /**
     * Origini (scheme://host[:port]) delle icone, per la CSP della shell:
     * l'Icona del sito può stare su una CDN delle immagini.
     *
     * @return string[]
     */
    public static function icon_origins() {
        $origins = array();
        foreach (self::icons() as $key => $url) {
            if (!is_string($url) || strpos($url, 'http') !== 0) continue;
            $p = wp_parse_url($url);
            if (!empty($p['host'])) {
                $origins[] = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            }
        }
        return array_values(array_unique($origins));
    }

    // =========================================================
    // COLORI
    // =========================================================

    /**
     * Palette dell'interfaccia ricavata dal colore principale.
     *
     * @return array {light: array, dark: array, bar: string, bar_text: string}
     */
    public static function palette() {
        $brand = self::settings()['color'];

        // Barra: il colore del sito; se né il testo bianco né quello scuro
        // arrivano a 4,5:1 (toni medi), si sposta lo sfondo quanto basta.
        $bar_text = self::best_text($brand);
        $bar      = self::contrast($bar_text, $brand) >= 4.5 ? $brand : self::readable_on($brand, $bar_text);

        // Chiaro: il colore come testo/pulsanti, scurito se serve per 4,5:1.
        $primary = self::readable_on($brand, self::LIGHT_BG);
        $light = array(
            '--primary'       => $primary,
            '--primary-hover' => self::mix($primary, '#000000', 0.18),
            '--primary-soft'  => self::mix($brand, '#ffffff', 0.9),
            '--on-primary'    => self::best_text($primary),
            '--focus'         => $primary,
            '--bar-bg'        => $bar,
            '--bar-text'      => $bar_text,
            '--brand'         => $brand,
        );

        // Scuro: il colore schiarito finché è leggibile sullo sfondo scuro.
        $primary_dark = self::readable_on($brand, self::DARK_BG);
        $dark = array(
            '--primary'       => $primary_dark,
            '--primary-hover' => self::mix($primary_dark, '#ffffff', 0.25),
            '--primary-soft'  => self::mix($brand, self::DARK_BG, 0.8),
            '--on-primary'    => self::best_text($primary_dark),
            '--focus'         => $primary_dark,
            '--bar-bg'        => self::DARK_BG,
            '--bar-text'      => '#e6e8ea',
            '--brand'         => $brand,
        );
        return array('light' => $light, 'dark' => $dark, 'bar' => $bar, 'bar_text' => $bar_text);
    }

    /**
     * CSS con le variabili della palette, con gli stessi selettori di app.css
     * per il tema scuro (automatico e scelto). Va stampato dopo app.css.
     *
     * @return string
     */
    public static function css() {
        $p    = self::palette();
        $vars = function ($set) {
            $out = '';
            foreach ($set as $k => $v) {
                $out .= $k . ':' . $v . ';';
            }
            return $out;
        };
        return ':root{' . $vars($p['light']) . '}'
            . '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){' . $vars($p['dark']) . '}}'
            . ':root[data-theme="dark"]{' . $vars($p['dark']) . '}';
    }

    /**
     * Hash CSP ('sha256-…') di un blocco <style> inline.
     *
     * @param string $css
     * @return string
     */
    public static function csp_hash($css) {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- formato richiesto dalla CSP.
        return "'sha256-" . base64_encode(hash('sha256', $css, true)) . "'";
    }

    /**
     * Rapporto di contrasto WCAG fra due colori esadecimali.
     */
    public static function contrast($a, $b) {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Il colore, scurito (su sfondo chiaro) o schiarito (su sfondo scuro) a
     * piccoli passi finché raggiunge il contrasto minimo con lo sfondo.
     */
    public static function readable_on($color, $bg, $min = 4.5) {
        $target = self::luminance($bg) > 0.5 ? '#000000' : '#ffffff';
        for ($t = 0.0; $t <= 1.0; $t += 0.04) {
            $c = self::mix($color, $target, $t);
            if (self::contrast($c, $bg) >= $min) {
                return $c;
            }
        }
        return $target;
    }

    /**
     * Testo bianco o quasi nero, quello con più contrasto sul colore.
     */
    public static function best_text($bg) {
        return self::contrast('#ffffff', $bg) >= self::contrast('#111111', $bg) ? '#ffffff' : '#111111';
    }

    public static function mix($a, $b, $t) {
        $ca = self::rgb($a);
        $cb = self::rgb($b);
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= sprintf('%02x', (int) round($ca[$i] + ($cb[$i] - $ca[$i]) * $t));
        }
        return $out;
    }

    private static function luminance($hex) {
        $l = array();
        foreach (self::rgb($hex) as $c) {
            $c   = $c / 255;
            $l[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
    }

    private static function rgb($hex) {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = ltrim(self::DEFAULT_COLOR, '#');
        }
        return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }
}
