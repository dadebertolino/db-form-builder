<?php
/**
 * Pagina admin "Web app" (2.21.0).
 *
 * @var string $app_url
 * @var bool   $pretty
 * @var string $role_name
 */
if (!defined('ABSPATH')) exit;
?>
<div class="dbfb-wrap">
    <div class="db-ui-page-header">
        <h1><?php esc_html_e('Web app delle risposte', 'db-form-builder'); ?></h1>
        <div class="db-ui-actions">
            <a class="button button-primary" href="<?php echo esc_url($app_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Apri la web app', 'db-form-builder'); ?></a>
        </div>
    </div>

    <div class="db-ui-card" style="padding:16px 20px;max-width:760px">
        <p><?php esc_html_e('Le risposte ai form, da smartphone o PC, in un\'app installabile. Indirizzo:', 'db-form-builder'); ?></p>
        <p><code style="user-select:all"><?php echo esc_html($app_url); ?></code></p>

        <?php if (!$pretty): ?>
            <div class="notice notice-warning inline"><p>
                <?php esc_html_e('I permalink del sito sono impostati su "Semplice": l\'app funziona, ma non si può installare né avviare offline. Per attivarle scegli un\'altra struttura in Impostazioni → Permalink.', 'db-form-builder'); ?>
            </p></div>
        <?php endif; ?>

        <h2><?php esc_html_e('Installazione', 'db-form-builder'); ?></h2>
        <ul style="list-style:disc;padding-left:20px">
            <li><?php esc_html_e('Android (Chrome): apri l\'indirizzo, menu ⋮ → "Installa app" o "Aggiungi a schermata Home".', 'db-form-builder'); ?></li>
            <li><?php esc_html_e('iPhone e iPad (Safari): apri l\'indirizzo, pulsante Condividi → "Aggiungi alla schermata Home".', 'db-form-builder'); ?></li>
            <li><?php esc_html_e('PC (Chrome, Edge): icona di installazione nella barra degli indirizzi.', 'db-form-builder'); ?></li>
        </ul>

        <h2><?php esc_html_e('Chi può usarla', 'db-form-builder'); ?></h2>
        <p><?php
            printf(
                /* translators: %s: nome del ruolo */
                esc_html__('Gli amministratori e gli utenti con il ruolo "%s", che vedono solo le risposte e non il resto dell\'amministrazione. Dopo il login, questi utenti arrivano direttamente all\'app.', 'db-form-builder'),
                esc_html($role_name)
            );
        ?></p>

        <h2><?php esc_html_e('Privacy e cache', 'db-form-builder'); ?></h2>
        <p><?php esc_html_e('Sul dispositivo resta in memoria solo l\'interfaccia (HTML, CSS, JavaScript e icone), mai le risposte. Se usi un plugin di cache o una CDN, escludi dalla cache il percorso /dbfb-app/: il plugin lo segnala già ai plugin di cache più diffusi (DONOTCACHEPAGE) e invia header no-store.', 'db-form-builder'); ?></p>
    </div>
</div>
