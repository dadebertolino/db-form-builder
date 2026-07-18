<?php
/**
 * Plugin Name: DB Form Builder
 * Plugin URI: https://www.davidebertolino.it
 * Description: Form builder con drag & drop, logica condizionale, reCAPTCHA gated dal consenso, email personalizzabili, export CSV con header esplicativi. Privacy by design: IP hashato, retention con pulizia allegati, snapshot fields, integrazione DSAR WordPress + DB Privacy Hub, informativa privacy per singolo form, monitoraggio conformità consenso GDPR. Webhook async con retry + HMAC signing.
 * Version: 2.11.1
 * Author: Davide Bertolino
 * Author URI: https://www.davidebertolino.it
 * Text Domain: db-form-builder
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

define('DBFB_VERSION', '2.11.1');
define('DBFB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DBFB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DBFB_PLUGIN_FILE', __FILE__);

/**
 * Marker letto dal DB Privacy Hub (DBPH_Policy_Generator::has_dbfb_dsar()) per
 * decidere se inserire la menzione "procedura DSAR semplificata" nella sezione
 * "Diritti dell'interessato" della Privacy Policy generata. Esposto da DBFB
 * 2.5.0+ ma reso esplicito come costante a partire dalla 2.9.0.
 */
define('DBFB_DSAR_AVAILABLE', true);

// Includes
require_once DBFB_PLUGIN_DIR . 'inc/class-core.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-builder.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-submit.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-submissions.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-email.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-settings.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-gutenberg.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-widget.php';
// Privacy declarations: si aggancia al filter unificato dbph_processing_register
// del DB Privacy Hub e, per retrocompatibilità, anche al filter legacy
// dbseo_processing_register del SEO Manager 1.2.x. Inerte se nessuno dei due
// plugin è installato.
require_once DBFB_PLUGIN_DIR . 'inc/class-privacy-declarations.php';
// Privacy DSAR: integra le DSAR di WordPress (Tools → Export/Erase
// Personal Data) per le submission che contengono l'email richiesta.
// Si aggancia al filter Hub (dbph_user_data_exporters/erasers) se presente,
// con fallback ai filter core di WordPress per funzionamento standalone.
require_once DBFB_PLUGIN_DIR . 'inc/class-privacy-dsar.php';
// Admin notice (2.10.0): segnala i form pubblicati che non richiedono il
// consenso al trattamento dati e che non sono stati esplicitamente marcati
// come "consenso intenzionalmente disabilitato".
require_once DBFB_PLUGIN_DIR . 'inc/class-gdpr-compliance-notice.php';
// Webhook delivery system (2.7.0): retry async, HMAC signing, dead-letter.
require_once DBFB_PLUGIN_DIR . 'inc/class-webhook.php';

// Init
DB_Form_Builder::get_instance();
