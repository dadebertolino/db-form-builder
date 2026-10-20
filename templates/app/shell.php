<?php
/**
 * Shell HTML della web app (2.21.0). Nessun dato personale e nessun nonce:
 * l'utente, i form e le risposte arrivano da app.js tramite le API.
 * Niente wp_head(): nessuno script o tracker del tema e di altri plugin.
 *
 * @var array $config Vedi DBFB_App::client_config().
 */
if (!defined('ABSPATH')) exit;
$dbfb_assets = DBFB_App::asset_urls();
// 2.25.0: identità del sito (nome, colore, Icona del sito).
$dbfb_brand  = DBFB_App_Branding::settings();
$dbfb_icons  = DBFB_App_Branding::icons();
$dbfb_css    = DBFB_App_Branding::css();
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?php echo esc_attr($dbfb_brand['color']); ?>" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#16191d" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr($dbfb_brand['name']); ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title><?php echo esc_html($dbfb_brand['name']); ?></title>
<link rel="manifest" href="<?php echo esc_url(DBFB_App::url('manifest')); ?>">
<link rel="icon"<?php echo $dbfb_icons['favicon_type'] ? ' type="' . esc_attr($dbfb_icons['favicon_type']) . '"' : ''; ?> href="<?php echo esc_url($dbfb_icons['favicon']); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url($dbfb_icons['apple']); ?>">
<link rel="stylesheet" href="<?php echo esc_url($dbfb_assets['css']); ?>">
<?php // Colori del sito: consentito dalla CSP tramite hash, quindi va stampato identico a DBFB_App_Branding::css(). ?>
<style><?php echo $dbfb_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS generato da colori validati con sanitize_hex_color(). ?></style>
<script type="application/json" id="dbfb-app-config"><?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
<script src="<?php echo esc_url($dbfb_assets['js']); ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#app-main"><?php esc_html_e('Vai al contenuto', 'db-form-builder'); ?></a>
<header class="app-bar">
    <button type="button" class="icon-btn" id="app-back" hidden aria-label="<?php echo esc_attr($config['strings']['back']); ?>">
        <svg aria-hidden="true" viewBox="0 0 24 24" width="24" height="24"><path d="M15.4 5.4 14 4l-8 8 8 8 1.4-1.4L8.8 12z" fill="currentColor"/></svg>
    </button>
    <img class="app-bar-logo" id="app-logo" src="<?php echo esc_url($dbfb_icons['192']); ?>" alt="" width="28" height="28">
    <span class="app-bar-title" id="app-title"><?php echo esc_html($dbfb_brand['name']); ?></span>
    <a class="icon-btn" id="app-settings" href="#/impostazioni">
        <svg aria-hidden="true" viewBox="0 0 24 24" width="24" height="24"><path d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7 7 0 0 0-1.7-1L14.9 3h-4l-.4 2.6a7 7 0 0 0-1.7 1l-2.5-1-2 3.5L6.4 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1a7 7 0 0 0 1.7 1l.4 2.6h4l.4-2.6a7 7 0 0 0 1.7-1l2.5 1 2-3.5zM12.9 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7z" fill="currentColor"/></svg>
        <span class="visually-hidden"><?php echo esc_html($config['strings']['settings']); ?></span>
    </a>
</header>
<div class="banner" id="app-offline" role="status" hidden><?php echo esc_html($config['strings']['offline']); ?></div>
<main id="app-main" tabindex="-1" aria-busy="true">
    <p class="muted"><?php echo esc_html($config['strings']['loading']); ?></p>
    <noscript><p><?php esc_html_e('Per usare la web app serve JavaScript.', 'db-form-builder'); ?></p></noscript>
</main>
<div class="visually-hidden" id="app-live" role="status" aria-live="polite"></div>
</body>
</html>
