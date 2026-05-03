<?php
/**
 * DB Form Builder — Uninstall handler.
 *
 * Eseguito da WordPress quando l'admin clicca "Elimina" sul plugin (NON al
 * deactivate). WP non permette UI/dialog interattivi qui: la decisione
 * è già stata presa via il setting `delete_data_on_uninstall` salvato
 * in `dbfb_global_settings`.
 *
 * Strategia (vedi README sezione "Disinstallazione"):
 *
 *  SOFT (default, opt-in NON attivo):
 *    - Rimuove le option del plugin (settings, schema_version)
 *    - Disschedula il cron dbfb_cleanup_submissions
 *    - Pulisce i transient dbfb_rate_*
 *    - LASCIA INTATTI: tabella submissions, post type dbfb_form +
 *      relativi post meta, allegati nella Media Library
 *    Razionale: disinstallazioni temporanee (debug, switch versione,
 *    migrazione hosting) non devono distruggere dati utente.
 *
 *  HARD (opt-in attivo, setting `delete_data_on_uninstall = true`):
 *    - Tutto quanto sopra, in più:
 *    - Cancellazione dei file allegati delle submission (2.4.0+) via
 *      DB_Form_Builder::delete_submission_files() — riusa la stessa
 *      logica path-traversal-safe del cron retention e dei delete UI.
 *    - DROP TABLE wp_dbfb_submissions (cancella TUTTE le submission)
 *    - wp_delete_post() force=true su tutti i post di tipo dbfb_form
 *      (cancella i form definiti + i loro post meta automaticamente)
 *    - Pulizia della cartella wp-content/uploads/dbfb/ (file di sicurezza
 *      .htaccess, index.php, e subdirectories vuote)
 *    Razionale: l'admin ha consapevolmente scelto pulizia totale ai
 *    sensi del GDPR.
 *
 * @package DBFB
 * @since   2.3.1
 * @updated 2.4.0 — gestione allegati pulita
 */

// Sicurezza: questo file deve essere eseguito SOLO da WordPress durante
// l'uninstall. Non deve essere accessibile via richiesta diretta.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Capability check: solo admin con manage_options possono uninstallare.
// WP fa già questo controllo a monte, ma una difesa in profondità non
// guasta in caso di plugin terzi che orchestrano uninstall in modo non
// standard.
if (!current_user_can('manage_options')) {
    return;
}

global $wpdb;

/* =========================================================================
 * STEP 1 — Lettura del setting opt-in PRIMA di cancellare le option.
 * ======================================================================= */

$global_settings = get_option('dbfb_global_settings', array());
$hard_delete = !empty($global_settings['delete_data_on_uninstall']);

/* =========================================================================
 * STEP 2 — Pulizia SOFT (sempre eseguita).
 * ======================================================================= */

// 2.1 — Cron: disschedula tutte le occorrenze di dbfb_cleanup_submissions.
// wp_clear_scheduled_hook rimuove ogni evento futuro per questo hook.
wp_clear_scheduled_hook('dbfb_cleanup_submissions');

// 2.2 — Option del plugin.
delete_option('dbfb_global_settings');
delete_option('dbfb_schema_version');

// 2.3 — Transient di rate limit. dbfb_rate_* sono creati con set_transient
// a runtime, quindi vivono in wp_options come _transient_dbfb_rate_*.
// Cancelliamo entrambe le forme (con e senza site- per multisite-safety).
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_dbfb\\_rate\\_%'"
);
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_dbfb\\_rate\\_%'"
);
// Multisite (se applicabile): site transient analoghi.
if (is_multisite()) {
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '\\_site\\_transient\\_dbfb\\_rate\\_%'"
    );
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '\\_site\\_transient\\_timeout\\_dbfb\\_rate\\_%'"
    );
}

/* =========================================================================
 * STEP 3 — Pulizia HARD (solo se opt-in attivo).
 * ======================================================================= */

if ($hard_delete) {

    // 3.1 — Cancellazione degli allegati delle submission (2.4.0).
    // Carichiamo class-core.php per riusare delete_submission_files() —
    // unica fonte di verità per la logica path-traversal-safe.
    // Streaming a batch da 200 per non saturare la memoria su tabelle
    // con migliaia di submission con allegati.
    $core_file = __DIR__ . '/inc/class-core.php';
    if (file_exists($core_file)) {
        require_once $core_file;
    }
    $table = $wpdb->prefix . 'dbfb_submissions';
    $files_deleted = 0;
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table
        && class_exists('DB_Form_Builder')) {
        $offset = 0;
        $batch  = 200;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, data FROM $table LIMIT %d OFFSET %d",
                $batch, $offset
            ));
            foreach ($rows as $row) {
                $files_deleted += DB_Form_Builder::delete_submission_files($row);
            }
            $offset += $batch;
        } while (!empty($rows) && count($rows) === $batch);
    }

    // 3.2 — DROP della tabella submissions.
    // Usiamo DROP TABLE invece di TRUNCATE + drop_table per evitare
    // race condition se il cron stesse girando.
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");

    // 3.3 — Cancellazione di tutti i form definiti (CPT dbfb_form).
    // Usiamo wp_delete_post(force=true) per skippare il cestino e cancellare
    // anche i post meta (_dbfb_fields, _dbfb_settings) automaticamente.
    // Operazione fatta in batch da 50 per non saturare la memoria su siti
    // con molti form.
    $batch_size = 50;
    do {
        $form_ids = get_posts(array(
            'post_type'      => 'dbfb_form',
            'post_status'    => 'any',
            'posts_per_page' => $batch_size,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ));
        foreach ($form_ids as $form_id) {
            wp_delete_post($form_id, true); // true = force, no trash
        }
    } while (!empty($form_ids) && count($form_ids) === $batch_size);

    // 3.4 — Pulizia della cartella uploads/dbfb se vuota.
    // I file dei form sono in wp-content/uploads/dbfb/{form_id}/. Dopo aver
    // cancellato i singoli file, tentiamo di rimuovere le cartelle vuote.
    // NON usiamo rm -rf: cancelliamo solo se vuote, per evitare di
    // distruggere file che non avremmo dovuto toccare (es. allegati di
    // submission salvati con bug, o cartelle aggiunte dall'utente).
    $upload = wp_upload_dir();
    $dbfb_root = trailingslashit($upload['basedir']) . 'dbfb';
    if (is_dir($dbfb_root)) {
        // Cancella subdirectories vuote (una per form_id).
        $subdirs = @scandir($dbfb_root);
        if (is_array($subdirs)) {
            foreach ($subdirs as $sub) {
                if ($sub === '.' || $sub === '..') continue;
                $sub_path = $dbfb_root . '/' . $sub;
                if (!is_dir($sub_path)) continue;
                // Pulisci solo file index.php (creato dal plugin, no contenuto).
                @unlink($sub_path . '/index.php');
                // rmdir fallisce silenziosamente se la cartella ha ancora file.
                @rmdir($sub_path);
            }
        }
        // Pulisci anche file di sicurezza alla root della cartella dbfb/.
        @unlink($dbfb_root . '/.htaccess');
        @unlink($dbfb_root . '/index.php');
        @rmdir($dbfb_root);
    }

    // 3.5 — Avviso allegati: se delete_submission_files NON ha potuto
    // cancellare alcuni file (path traversal sospetto, permessi, file già
    // mancanti), restano sul disco. Lo loggiamo solo in WP_DEBUG.
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            'DB Form Builder uninstall (2.4.0): hard delete eseguita. '
            . '%d file allegati cancellati. Eventuali file residui in '
            . 'wp-content/uploads/dbfb/ vanno verificati manualmente.',
            (int) $files_deleted
        ));
    }
}

// Nessun output: WP gestirà la conferma di uninstall standard.
