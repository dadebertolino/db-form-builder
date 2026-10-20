<?php
/**
 * Pagina admin "Web app" (2.21.0).
 *
 * @var string $app_url
 * @var bool   $pretty
 * @var string $role_name
 * @var array  $notify          Impostazioni delle notifiche (2.22.0).
 * @var bool   $push_supported  Il server può inviare Web Push (2.22.0).
 * @var array  $health          Esito del controllo delle API (2.24.0).
 * @var array  $app_pw          Diagnosi delle Application Passwords (2.24.1).
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

        <?php // 2.24.0: API REST bloccate o Application Passwords disattivate. ?>
        <?php if ($health['rest_ok'] === false): ?>
            <div class="notice notice-error inline"><p>
                <strong><?php esc_html_e('Le API del sito non rispondono come previsto:', 'db-form-builder'); ?></strong>
                <?php
                printf(
                    /* translators: %s: codice della risposta, es. HTTP 403 */
                    esc_html__('la web app non potrà caricare le risposte (%s). Spesso dipende da un plugin di sicurezza che blocca /wp-json/: consenti il percorso /wp-json/dbfb/v1/ agli utenti collegati.', 'db-form-builder'),
                    esc_html($health['rest_detail'])
                );
                ?>
            </p></div>
        <?php elseif ($health['rest_ok'] === null): ?>
            <div class="notice notice-warning inline"><p>
                <?php
                printf(
                    /* translators: %s: messaggio di errore */
                    esc_html__('Non è stato possibile verificare le API del sito dal server (%s). Se la web app non carica i dati, controlla che /wp-json/ non sia bloccato.', 'db-form-builder'),
                    esc_html($health['rest_detail'])
                );
                ?>
            </p></div>
        <?php endif; ?>
        <?php // 2.24.1: Application Passwords, con la causa e la correzione. ?>
        <?php if (!$app_pw['available']): ?>
            <div class="notice notice-info inline">
                <p><strong><?php esc_html_e('Application Passwords non disponibili.', 'db-form-builder'); ?></strong>
                    <?php esc_html_e('La web app funziona lo stesso (usa il login del sito): servono solo a programmi esterni che chiamano le API.', 'db-form-builder'); ?></p>
                <?php if ($app_pw['reason'] === 'no_ssl'): ?>
                    <p><?php esc_html_e('Causa: il sito è in HTTPS, ma WordPress non se ne accorge perché la connessione sicura termina su un proxy dell\'hosting e a PHP la richiesta arriva in HTTP. Lo stesso problema può influire anche su altre funzioni di WordPress che controllano l\'HTTPS.', 'db-form-builder'); ?></p>
                    <?php if ($app_pw['snippet'] !== ''): ?>
                        <p><?php
                            printf(
                                /* translators: %s: nome dell'header HTTP */
                                esc_html__('Il proxy segnala l\'HTTPS con l\'header %s. Aggiungi queste righe a wp-config.php, sopra "That\'s all, stop editing!":', 'db-form-builder'),
                                '<code>' . esc_html($app_pw['header']) . '</code>'
                            );
                        ?></p>
                        <pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px 12px;overflow-x:auto;user-select:all"><code><?php echo esc_html($app_pw['snippet']); ?></code></pre>
                        <p><?php esc_html_e('Ricarica poi questa pagina: se la correzione funziona, l\'avviso sparisce e nel profilo utente compare la sezione "Password applicazione".', 'db-form-builder'); ?></p>
                    <?php else: ?>
                        <p><?php esc_html_e('Il server non riceve nessuno degli header con cui i proxy segnalano l\'HTTPS (X-Forwarded-Proto, X-Forwarded-Ssl, Front-End-Https, CF-Visitor). Chiedi all\'hosting come rilevare l\'HTTPS in PHP, oppure se può impostare la variabile HTTPS per il sito.', 'db-form-builder'); ?></p>
                    <?php endif; ?>
                <?php elseif ($app_pw['reason'] === 'filtered'): ?>
                    <p><?php
                        if ($app_pw['plugins']) {
                            printf(
                                /* translators: %s: elenco di plugin */
                                esc_html__('Causa: sono state disattivate da un plugin. Agganciati al filtro che le controlla: %s. Cerca nelle sue impostazioni un\'opzione come "Disattiva Application Passwords".', 'db-form-builder'),
                                '<strong>' . esc_html(implode(', ', $app_pw['plugins'])) . '</strong>'
                            );
                        } else {
                            esc_html_e('Causa: sono state disattivate da un plugin o da codice personalizzato (filtro wp_is_application_passwords_available). I plugin di sicurezza come Wordfence, Solid Security o All In One WP Security hanno un\'opzione apposita.', 'db-form-builder');
                        }
                    ?></p>
                <?php else: ?>
                    <p><?php esc_html_e('Causa: il sito non usa HTTPS. Attiva un certificato SSL dall\'hosting e aggiorna gli indirizzi in Impostazioni → Generali: serve anche per installare la web app.', 'db-form-builder'); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <p><a href="<?php echo esc_url(wp_nonce_url(add_query_arg('dbfb_recheck', '1', admin_url('admin.php?page=dbfb-app')), 'dbfb_recheck')); ?>"><?php esc_html_e('Ripeti il controllo delle API', 'db-form-builder'); ?></a></p>

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

        <?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- solo messaggi dopo il redirect. ?>
        <?php if (isset($_GET['dbfb_saved'])): ?>
            <div class="notice notice-success inline"><p><?php esc_html_e('Impostazioni delle notifiche salvate.', 'db-form-builder'); ?></p></div>
        <?php endif; ?>
        <?php if (($_GET['dbfb_error'] ?? '') === 'telegram_token'): ?>
            <div class="notice notice-error inline"><p><?php esc_html_e('Il token del bot Telegram non è valido (formato 123456:ABC…): è rimasto quello salvato in precedenza.', 'db-form-builder'); ?></p></div>
        <?php endif; ?>
        <?php if (isset($_GET['dbfb_test_ok'])): ?>
            <div class="notice <?php echo absint($_GET['dbfb_test_failed'] ?? 0) ? 'notice-warning' : 'notice-success'; ?> inline"><p><?php
                printf(
                    /* translators: 1: invii riusciti, 2: invii falliti */
                    esc_html__('Notifica di prova: %1$d inviate, %2$d non riuscite. Le notifiche push di prova arrivano solo sui tuoi dispositivi con le notifiche attive nella web app.', 'db-form-builder'),
                    absint($_GET['dbfb_test_ok']),
                    absint($_GET['dbfb_test_failed'] ?? 0)
                );
            ?></p></div>
        <?php endif; ?>

        <h2 id="notifiche"><?php esc_html_e('Notifiche delle nuove risposte', 'db-form-builder'); ?></h2>
        <p><?php esc_html_e('Il messaggio non contiene mai i dati della risposta: solo "Nuova risposta", il titolo del form (se lo lasci attivo) e il link alla web app.', 'db-form-builder'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="dbfb_save_notifications">
            <?php wp_nonce_field('dbfb_save_notifications'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Notifiche push', 'db-form-builder'); ?></th>
                    <td>
                        <label><input type="checkbox" name="push" value="1" <?php checked(!empty($notify['push'])); ?> <?php disabled(!$push_supported); ?>>
                            <?php esc_html_e('Consenti di attivare le notifiche push dalla web app', 'db-form-builder'); ?></label>
                        <p class="description">
                            <?php if ($push_supported): ?>
                                <?php esc_html_e('Ogni utente le attiva dal proprio dispositivo. Su iPhone e iPad servono iOS 16.4 o successivo e l\'app aggiunta alla schermata Home.', 'db-form-builder'); ?>
                            <?php else: ?>
                                <?php esc_html_e('Non disponibili: il PHP del server non ha OpenSSL con il supporto alle curve ellittiche e ad AES-GCM. Usa ntfy o Telegram.', 'db-form-builder'); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Contenuto', 'db-form-builder'); ?></th>
                    <td><label><input type="checkbox" name="show_form_title" value="1" <?php checked(!empty($notify['show_form_title'])); ?>>
                        <?php esc_html_e('Mostra il titolo del form nella notifica', 'db-form-builder'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="dbfb-poll"><?php esc_html_e('Aggiornamento dell\'app', 'db-form-builder'); ?></label></th>
                    <td>
                        <input type="number" id="dbfb-poll" name="poll_interval" min="10" max="300" step="5" class="small-text" value="<?php echo esc_attr((int) $notify['poll_interval']); ?>">
                        <?php esc_html_e('secondi', 'db-form-builder'); ?>
                        <p class="description"><?php esc_html_e('Ogni quanto la web app aperta controlla se ci sono risposte nuove. Si ferma quando l\'app è in background. Su hosting condiviso con molti utenti conviene un valore più alto.', 'db-form-builder'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dbfb-ntfy"><?php esc_html_e('ntfy', 'db-form-builder'); ?></label></th>
                    <td>
                        <input type="url" id="dbfb-ntfy" name="ntfy_url" class="regular-text" placeholder="https://ntfy.sh/nome-del-topic" value="<?php echo esc_attr($notify['ntfy_url']); ?>">
                        <p class="description"><?php esc_html_e('URL HTTPS del topic, su ntfy.sh o su un server proprio. Sui server pubblici chiunque conosca il nome del topic legge i messaggi: scegli un nome lungo e casuale, o usa un token di accesso.', 'db-form-builder'); ?></p>
                        <p><label for="dbfb-ntfy-token"><?php esc_html_e('Token di accesso (facoltativo)', 'db-form-builder'); ?></label><br>
                            <input type="password" id="dbfb-ntfy-token" name="ntfy_token" class="regular-text" autocomplete="off" placeholder="<?php echo $notify['ntfy_token'] !== '' ? esc_attr__('Salvato: lascia vuoto per non cambiarlo', 'db-form-builder') : ''; ?>">
                            <?php if ($notify['ntfy_token'] !== ''): ?>
                                <label><input type="checkbox" name="ntfy_token_clear" value="1"> <?php esc_html_e('Rimuovi il token', 'db-form-builder'); ?></label>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Telegram', 'db-form-builder'); ?></th>
                    <td>
                        <p><label for="dbfb-tg-token"><?php esc_html_e('Token del bot', 'db-form-builder'); ?></label><br>
                            <input type="password" id="dbfb-tg-token" name="telegram_token" class="regular-text" autocomplete="off" placeholder="<?php echo $notify['telegram_token'] !== '' ? esc_attr__('Salvato: lascia vuoto per non cambiarlo', 'db-form-builder') : '123456:ABC…'; ?>"></p>
                        <p><label for="dbfb-tg-chat"><?php esc_html_e('ID della chat o @canale', 'db-form-builder'); ?></label><br>
                            <input type="text" id="dbfb-tg-chat" name="telegram_chat_id" class="regular-text" value="<?php echo esc_attr($notify['telegram_chat_id']); ?>"></p>
                        <p class="description"><?php esc_html_e('Crea un bot con @BotFather, aggiungilo alla chat o al canale e inserisci qui token e ID. Svuota l\'ID per disattivare Telegram.', 'db-form-builder'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Salva le notifiche', 'db-form-builder')); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="dbfb_test_notifications">
            <?php wp_nonce_field('dbfb_test_notifications'); ?>
            <?php submit_button(__('Invia una notifica di prova', 'db-form-builder'), 'secondary', 'submit', false); ?>
        </form>

        <h2><?php esc_html_e('Privacy e cache', 'db-form-builder'); ?></h2>
        <p><?php esc_html_e('Sul dispositivo resta in memoria solo l\'interfaccia (HTML, CSS, JavaScript e icone), mai le risposte. Se usi un plugin di cache o una CDN, escludi dalla cache il percorso /dbfb-app/: il plugin lo segnala già ai plugin di cache più diffusi (DONOTCACHEPAGE) e invia header no-store.', 'db-form-builder'); ?></p>
    </div>
</div>
