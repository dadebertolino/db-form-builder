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
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#2271b1" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#16191d" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr($config['strings']['appTitle']); ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title><?php echo esc_html($config['strings']['appTitle'] . ' — ' . $config['siteName']); ?></title>
<link rel="manifest" href="<?php echo esc_url(DBFB_App::url('manifest')); ?>">
<link rel="icon" type="image/svg+xml" href="<?php echo esc_url($dbfb_assets['icon-svg']); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url($dbfb_assets['apple-touch']); ?>">
<link rel="stylesheet" href="<?php echo esc_url($dbfb_assets['css']); ?>">
<script type="application/json" id="dbfb-app-config"><?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
<script src="<?php echo esc_url($dbfb_assets['js']); ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#app-main"><?php esc_html_e('Vai al contenuto', 'db-form-builder'); ?></a>
<header class="app-bar">
    <button type="button" class="icon-btn" id="app-back" hidden aria-label="<?php echo esc_attr($config['strings']['back']); ?>">
        <svg aria-hidden="true" viewBox="0 0 24 24" width="24" height="24"><path d="M15.4 5.4 14 4l-8 8 8 8 1.4-1.4L8.8 12z" fill="currentColor"/></svg>
    </button>
    <span class="app-bar-title" id="app-title"><?php echo esc_html($config['strings']['appTitle']); ?></span>
    <span class="app-bar-user" id="app-user"></span>
    <button type="button" class="icon-btn" id="app-theme" aria-pressed="false" aria-label="<?php echo esc_attr($config['strings']['themeDark']); ?>">
        <svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22"><path d="M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9z" fill="currentColor"/></svg>
    </button>
    <a class="icon-btn" id="app-logout" hidden href="#">
        <svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22"><path d="M17 7l-1.4 1.4 2.6 2.6H8v2h10.2l-2.6 2.6L17 17l5-5zM4 5h8V3H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8v-2H4z" fill="currentColor"/></svg>
        <span class="visually-hidden"><?php echo esc_html($config['strings']['logout']); ?></span>
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
