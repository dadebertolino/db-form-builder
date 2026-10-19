<?php
/**
 * DBFB_Roles — capability e ruolo per consultare le risposte (2.20.0).
 *
 *  - Capability `dbfb_view_submissions`: leggere le risposte e cambiarne lo
 *    stato tramite le API REST (dbfb/v1) e la web app. Assegnata
 *    all'administrator.
 *  - Ruolo "Gestore form" (`dbfb_manager`): solo `read` e la capability qui
 *    sopra. Per chi deve seguire le risposte senza accedere al resto
 *    dell'admin (form, impostazioni, utenti, contenuti).
 *
 * Installazione idempotente con marcatore di versione, ripetuta in
 * admin_init: copre aggiornamenti dall'auto-updater, che non passano
 * dall'activation hook.
 *
 * @package DBFB
 * @since   2.20.0
 */

if (!defined('ABSPATH')) exit;

class DBFB_Roles {

    const CAP     = 'dbfb_view_submissions';
    const ROLE    = 'dbfb_manager';
    const VERSION = 1;
    const OPTION  = 'dbfb_roles_version';

    public static function init() {
        add_action('admin_init', array(__CLASS__, 'maybe_install'));
    }

    public static function maybe_install() {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) return;
        self::install();
    }

    public static function install() {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::CAP);
        }
        if (!get_role(self::ROLE)) {
            add_role(self::ROLE, __('Gestore form', 'db-form-builder'), array(
                'read'    => true,
                self::CAP => true,
            ));
        }
        update_option(self::OPTION, self::VERSION, false);
    }

    /**
     * Rimuove capability e ruolo (disinstallazione). Gli utenti che avevano
     * solo il ruolo "Gestore form" restano senza ruolo sul sito.
     */
    public static function uninstall() {
        foreach (wp_roles()->role_objects as $role) {
            if ($role->has_cap(self::CAP)) {
                $role->remove_cap(self::CAP);
            }
        }
        remove_role(self::ROLE);
        delete_option(self::OPTION);
    }

    /**
     * L'utente corrente può consultare le risposte?
     *
     * @return bool
     */
    public static function current_user_can_view() {
        return current_user_can(self::CAP);
    }
}
