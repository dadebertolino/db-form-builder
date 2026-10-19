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
 *    - Disschedula i cron dbfb_cleanup_submissions, dbfb_cleanup_deliveries
 *      e i dispatch dbfb_webhook_dispatch in coda (2.13.0), il backfill
 *      dbfb_backfill_subject_hash (2.19.0)
 *    - Rimuove la capability dbfb_view_submissions e il ruolo "Gestore
 *      form" (2.20.0)
 *    - Rimuove impostazioni delle notifiche, chiavi VAPID e sottoscrizioni
 *      push degli utenti (2.22.0)
 *    - Pulisce i transient dbfb_rate_*
 *    - LASCIA INTATTI: tabelle submissions e webhook deliveries, post type
 *      dbfb_form + relativi post meta, allegati in uploads/dbfb/
 *    Razionale: disinstallazioni temporanee (debug, switch versione,
 *    migrazione hosting) non devono distruggere dati utente.
 *
 *  HARD (opt-in attivo, setting `delete_data_on_uninstall = true`):
 *    - Tutto quanto sopra, in più:
 *    - Cancellazione dei file allegati delle submission (2.4.0+) via
 *      DB_Form_Builder::delete_submission_files() — riusa la stessa
 *      logica path-traversal-safe del cron retention e dei delete UI.
 *    - DROP TABLE wp_dbfb_submissions (cancella TUTTE le submission)
 *    - DROP TABLE wp_dbfb_webhook_deliveries (2.13.0)
 *    - DROP TABLE wp_dbfb_consents + option dbfb_consent_secret (2.16.0)
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

// 2.13.0: rimosso il controllo current_user_can(). WP_UNINSTALL_PLUGIN è
// definita solo da uninstall_plugin(), che WordPress chiama dopo i propri
// controlli di permesso; con WP-CLI (`wp plugin uninstall`) non c'è un utente
// corrente, quindi il controllo faceva uscire lo script senza pulire nulla.

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
// 2.13.0: anche il cron settimanale delle webhook deliveries e i dispatch
// in coda. dbfb_webhook_dispatch è pianificato con argomenti (id delivery):
// wp_clear_scheduled_hook() senza argomenti non li troverebbe, quindi usiamo
// wp_unschedule_hook() (WP 5.1+) che rimuove ogni evento dell'hook.
wp_clear_scheduled_hook('dbfb_cleanup_deliveries');
// 2.19.0: backfill di subject_hash sulle risposte pre-2.19.0.
wp_clear_scheduled_hook('dbfb_backfill_subject_hash');
if (function_exists('wp_unschedule_hook')) {
    wp_unschedule_hook('dbfb_webhook_dispatch');
} else {
    wp_clear_scheduled_hook('dbfb_webhook_dispatch');
}

// 2.2 — Option del plugin.
delete_option('dbfb_global_settings');
delete_option('dbfb_schema_version');
delete_option('dbfb_uploads_protection');
delete_option('dbfb_file_link_secret');
delete_option('dbfb_subject_backfill');

// 2.20.0: capability dbfb_view_submissions e ruolo "Gestore form". Sono
// configurazione del plugin, non dati: si rimuovono anche nella pulizia soft.
foreach (wp_roles()->role_objects as $dbfb_role) {
    if ($dbfb_role->has_cap('dbfb_view_submissions')) {
        $dbfb_role->remove_cap('dbfb_view_submissions');
    }
}
remove_role('dbfb_manager');
delete_option('dbfb_roles_version');

// 2.21.0: le regole di rewrite di /dbfb-app/ spariscono al prossimo flush;
// togliendo il marcatore, una reinstallazione le registra di nuovo.
delete_option('dbfb_app_rewrite_version');

// 2.22.0: notifiche. Chiavi VAPID, impostazioni (con i token ntfy/Telegram),
// sottoscrizioni push di tutti gli utenti, invii in coda.
delete_option('dbfb_notifications');
delete_option('dbfb_vapid_keys');
delete_metadata('user', 0, 'dbfb_push_subscriptions', '', true);
if (function_exists('wp_unschedule_hook')) {
    wp_unschedule_hook('dbfb_notify_dispatch');
} else {
    wp_clear_scheduled_hook('dbfb_notify_dispatch');
}

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

    // 3.2b — DROP della tabella webhook deliveries (2.13.0). Contiene il
    // payload completo delle submission: va rimossa insieme a esse.
    $table_dlv = $wpdb->prefix . 'dbfb_webhook_deliveries';
    $wpdb->query("DROP TABLE IF EXISTS `{$table_dlv}`");

    // 3.2c — DROP della tabella dei consensi facoltativi (2.16.0) e del suo
    // secret. In modalità Soft entrambi restano: i link di revoca già
    // inviati per email devono continuare a funzionare dopo una reinstallazione.
    $table_consents = $wpdb->prefix . 'dbfb_consents';
    $wpdb->query("DROP TABLE IF EXISTS `{$table_consents}`");
    delete_option('dbfb_consent_secret');

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
                wp_delete_file($sub_path . '/index.php');
                // rmdir fallisce silenziosamente se la cartella ha ancora file.
                @rmdir($sub_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            }
        }
        // Pulisci anche file di sicurezza alla root della cartella dbfb/.
        wp_delete_file($dbfb_root . '/.htaccess');
        wp_delete_file($dbfb_root . '/index.php');
        @rmdir($dbfb_root); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }

    // 3.5 — Avviso allegati: se delete_submission_files NON ha potuto
    // cancellare alcuni file (path traversal sospetto, permessi, file già
    // mancanti), restano sul disco. Lo loggiamo solo in WP_DEBUG.
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- solo con WP_DEBUG.
            'DB Form Builder uninstall (2.4.0): hard delete eseguita. '
            . '%d file allegati cancellati. Eventuali file residui in '
            . 'wp-content/uploads/dbfb/ vanno verificati manualmente.',
            (int) $files_deleted
        ));
    }
}

// Nessun output: WP gestirà la conferma di uninstall standard.
