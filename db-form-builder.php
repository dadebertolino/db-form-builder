<?php
/**
 * Plugin Name: DB Form Builder
 * Plugin URI: https://www.davidebertolino.it
 * Description: Form builder con drag & drop, logica condizionale, reCAPTCHA, email personalizzabili e export CSV
 * Version: 2.2.0
 * Author: Davide Bertolino
 * Author URI: https://www.davidebertolino.it
 * Text Domain: db-form-builder
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

define('DBFB_VERSION', '2.2.0');
define('DBFB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DBFB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DBFB_PLUGIN_FILE', __FILE__);

// Includes
require_once DBFB_PLUGIN_DIR . 'inc/class-core.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-builder.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-submit.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-submissions.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-email.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-settings.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-gutenberg.php';
require_once DBFB_PLUGIN_DIR . 'inc/class-widget.php';

// Init
DB_Form_Builder::get_instance();
