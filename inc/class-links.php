<?php
/**
 * DBFB_Links — collegamenti tra risposte e oggetti esterni (2.24.0).
 *
 * Tabella wp_dbfb_links: una riga per collegamento (es. risposta → ordine
 * WooCommerce, object_type 'wc_order'). Generica, così il modulo WooCommerce
 * resta opzionale e i collegamenti seguono la risposta: vengono cancellati
 * con lei (eliminazione, retention, DSAR), mentre l'oggetto collegato resta
 * con le sue regole di conservazione.
 *
 * @package DBFB
 * @since   2.24.0
 */

if (!defined('ABSPATH')) exit;

class DBFB_Links {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'dbfb_links';
    }

    /**
     * Crea la tabella se non esiste (idempotente, chiamata dalla migrazione v8).
     */
    public static function create_table() {
        global $wpdb;
        $table = self::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id bigint(20) NOT NULL,
            object_type varchar(30) NOT NULL,
            object_id bigint(20) UNSIGNED NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY submission_id (submission_id),
            KEY object (object_type, object_id)
        ) $charset_collate;");
    }

    public static function table_exists() {
        static $exists = false;
        if (!$exists) {
            global $wpdb;
            $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table())) === self::table());
        }
        return $exists;
    }

    /**
     * @param int    $submission_id
     * @param string $object_type
     * @param int    $object_id
     * @return bool
     */
    public static function add($submission_id, $object_type, $object_id) {
        if (!self::table_exists()) return false;
        global $wpdb;
        return (bool) $wpdb->insert(self::table(), array(
            'submission_id' => (int) $submission_id,
            'object_type'   => sanitize_key($object_type),
            'object_id'     => (int) $object_id,
            'created_at'    => current_time('mysql'),
        ));
    }

    /**
     * @param int    $submission_id
     * @param string $object_type
     * @return int[]
     */
    public static function objects_for_submission($submission_id, $object_type) {
        if (!self::table_exists()) return array();
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT object_id FROM ' . self::table() . ' WHERE submission_id = %d AND object_type = %s ORDER BY id ASC',
            (int) $submission_id,
            sanitize_key($object_type)
        )));
    }

    /**
     * @param string $object_type
     * @param int    $object_id
     * @return int[]
     */
    public static function submissions_for_object($object_type, $object_id) {
        if (!self::table_exists()) return array();
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT submission_id FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d ORDER BY id ASC',
            sanitize_key($object_type),
            (int) $object_id
        )));
    }

    /**
     * @param int[] $submission_ids
     */
    public static function delete_for_submissions($submission_ids) {
        $ids = array_values(array_filter(array_map('intval', (array) $submission_ids)));
        if (!$ids || !self::table_exists()) return;
        global $wpdb;
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder %d generati in $in.
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . " WHERE submission_id IN ($in)", $chunk));
        }
    }

    /**
     * Collegamenti delle risposte di un form (prima di cancellarle).
     *
     * @param int $form_id
     */
    public static function delete_for_form($form_id) {
        if (!self::table_exists()) return;
        global $wpdb;
        $submissions = DBFB_Submissions_Repository::table();
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . " WHERE submission_id IN (SELECT id FROM $submissions WHERE form_id = %d)",
            (int) $form_id
        ));
    }

    /**
     * L'oggetto collegato è stato eliminato (es. ordine cancellato definitivamente).
     *
     * @param string $object_type
     * @param int    $object_id
     */
    public static function delete_for_object($object_type, $object_id) {
        if (!self::table_exists()) return;
        global $wpdb;
        $wpdb->delete(self::table(), array('object_type' => sanitize_key($object_type), 'object_id' => (int) $object_id), array('%s', '%d'));
    }
}
