<?php
/**
 * Template: Webhook Deliveries.
 *
 * Variabili disponibili:
 *  - $deliveries: array di righe da wp_dbfb_webhook_deliveries (DESC by id, LIMIT 200)
 *  - $count_by_status: ['pending'=>N, 'success'=>N, 'failed'=>N, 'dead'=>N]
 *  - $count_total: int
 *  - $status_filter: 'all'|'pending'|'success'|'failed'|'dead'
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1><?php _e('Webhook Deliveries', 'db-form-builder'); ?></h1>

    <?php if (!empty($_GET['retried'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf(esc_html__('%d delivery messe in coda per retry.', 'db-form-builder'), (int) $_GET['retried']); ?></p>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf(esc_html__('%d delivery cancellate dal log.', 'db-form-builder'), (int) $_GET['deleted']); ?></p>
        </div>
    <?php endif; ?>

    <p class="description" style="max-width:780px">
        <?php _e('Le deliveries sono i tentativi di invio webhook. Ogni submit di un form con webhook configurato genera una delivery. Il sistema ritenta automaticamente le deliveries fallite per errori transient (timeout, 5xx, 408, 429) fino a 5 volte con backoff esponenziale (1m, 5m, 30m, 2h, 12h). Errori 4xx permanenti vengono marcati come <em>failed</em> senza retry. Esauriti i tentativi → <em>dead</em>.', 'db-form-builder'); ?>
    </p>

    <ul class="subsubsub" style="margin:14px 0">
        <?php
        $tabs = array(
            'all'     => array(__('Tutte', 'db-form-builder'), $count_total, '#555'),
            'pending' => array(__('In coda', 'db-form-builder'), $count_by_status['pending'], '#0073aa'),
            'success' => array(__('Successo', 'db-form-builder'), $count_by_status['success'], '#46b450'),
            'failed'  => array(__('Failed', 'db-form-builder'), $count_by_status['failed'], '#dc3232'),
            'dead'    => array(__('Dead', 'db-form-builder'), $count_by_status['dead'], '#a00'),
        );
        $first = true;
        foreach ($tabs as $key => $tab):
            list($label, $n, $color) = $tab;
            $url = admin_url('admin.php?page=dbfb-webhook-deliveries' . ($key !== 'all' ? '&status=' . $key : ''));
            $is_current = $status_filter === $key;
            ?>
            <li>
                <?php echo $first ? '' : '| '; ?>
                <a href="<?php echo esc_url($url); ?>"
                   class="<?php echo $is_current ? 'current' : ''; ?>"
                   <?php if ($is_current): ?>aria-current="page"<?php endif; ?>>
                    <span style="color:<?php echo esc_attr($color); ?>"><?php echo esc_html($label); ?></span>
                    <span class="count">(<?php echo (int) $n; ?>)</span>
                </a>
            </li>
            <?php $first = false; endforeach; ?>
    </ul>

    <?php if (empty($deliveries)): ?>
        <div style="padding:30px;background:#fff;border:1px solid #ddd;text-align:center;color:#666">
            <p style="margin:0">
                <?php
                if ($status_filter !== 'all') {
                    printf(
                        esc_html__('Nessuna delivery con stato "%s".', 'db-form-builder'),
                        esc_html($status_filter)
                    );
                } else {
                    _e('Ancora nessuna delivery. Le deliveries appariranno qui dopo il primo submit di un form con webhook configurato.', 'db-form-builder');
                }
                ?>
            </p>
        </div>
    <?php else: ?>
        <form method="post" action="">
            <?php wp_nonce_field('dbfb_deliveries_action'); ?>
            <div style="margin:10px 0">
                <button type="submit" name="dbfb_delivery_action" value="retry" class="button">
                    <?php _e('Retry selezionate', 'db-form-builder'); ?>
                </button>
                <button type="submit" name="dbfb_delivery_action" value="delete" class="button"
                        onclick="return confirm(<?php echo wp_json_encode(__('Cancellare definitivamente le deliveries selezionate dal log? La submission resta intatta, viene rimossa solo la traccia dell\'invio webhook.', 'db-form-builder')); ?>);">
                    <?php _e('Cancella selezionate', 'db-form-builder'); ?>
                </button>
                <span class="description"><?php _e('Mostra ultime 200 deliveries.', 'db-form-builder'); ?></span>
            </div>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <td style="width:24px"><input type="checkbox" id="dbfb-select-all-deliveries"></td>
                        <th style="width:60px"><?php _e('ID', 'db-form-builder'); ?></th>
                        <th style="width:100px"><?php _e('Stato', 'db-form-builder'); ?></th>
                        <th style="width:60px"><?php _e('Tent.', 'db-form-builder'); ?></th>
                        <th><?php _e('URL', 'db-form-builder'); ?></th>
                        <th style="width:90px"><?php _e('Form', 'db-form-builder'); ?></th>
                        <th style="width:160px"><?php _e('Ultimo tentativo', 'db-form-builder'); ?></th>
                        <th><?php _e('Esito', 'db-form-builder'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $status_meta = array(
                        'pending' => array('🕒', '#0073aa', __('In coda', 'db-form-builder')),
                        'success' => array('✓',  '#46b450', __('Successo', 'db-form-builder')),
                        'failed'  => array('✗',  '#dc3232', __('Failed', 'db-form-builder')),
                        'dead'    => array('💀', '#a00',    __('Dead', 'db-form-builder')),
                    );
                    foreach ($deliveries as $d):
                        $meta = $status_meta[$d->status] ?? array('?', '#888', $d->status);
                        list($icon, $color, $label) = $meta;
                        $form_post = get_post($d->form_id);
                        $form_title = $form_post ? $form_post->post_title : '#' . $d->form_id;
                    ?>
                        <tr>
                            <td><input type="checkbox" name="delivery_ids[]" value="<?php echo (int) $d->id; ?>"></td>
                            <td><?php echo (int) $d->id; ?></td>
                            <td>
                                <span style="color:<?php echo esc_attr($color); ?>;font-weight:600">
                                    <?php echo esc_html($icon . ' ' . $label); ?>
                                </span>
                            </td>
                            <td><?php echo (int) $d->attempts; ?> / <?php echo DBFB_Webhook::MAX_ATTEMPTS; ?></td>
                            <td style="word-break:break-all;font-size:11px;font-family:monospace">
                                <?php echo esc_html(mb_strimwidth($d->url, 0, 80, '…')); ?>
                            </td>
                            <td>
                                <?php if ($form_post): ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=dbfb-forms&action=submissions&form_id=' . $d->form_id)); ?>">
                                        <?php echo esc_html(mb_strimwidth($form_title, 0, 20, '…')); ?>
                                    </a>
                                <?php else: ?>
                                    <em><?php echo esc_html($form_title); ?></em>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:11px">
                                <?php
                                if ($d->last_attempt_at) {
                                    echo esc_html(date_i18n('d/m/Y H:i', strtotime($d->last_attempt_at)));
                                } elseif ($d->next_attempt_at) {
                                    echo esc_html__('In schedule:', 'db-form-builder') . '<br>' . esc_html(date_i18n('d/m/Y H:i', strtotime($d->next_attempt_at)));
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td style="font-size:11px">
                                <?php if ($d->last_status_code): ?>
                                    <code style="background:#f5f5f5;padding:1px 5px"><?php echo (int) $d->last_status_code; ?></code>
                                <?php endif; ?>
                                <?php if ($d->last_error): ?>
                                    <span title="<?php echo esc_attr($d->last_error); ?>" style="color:#666">
                                        <?php echo esc_html(mb_strimwidth($d->last_error, 0, 50, '…')); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($d->status === 'pending' && $d->next_attempt_at): ?>
                                    <br><span style="color:#0073aa">
                                        <?php
                                        $when = strtotime($d->next_attempt_at);
                                        if ($when > time()) {
                                            $diff = human_time_diff(time(), $when);
                                            printf(esc_html__('Prossimo retry tra %s', 'db-form-builder'), $diff);
                                        }
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <script>
        document.getElementById('dbfb-select-all-deliveries').addEventListener('change', function(e) {
            document.querySelectorAll('input[name="delivery_ids[]"]').forEach(cb => cb.checked = e.target.checked);
        });
        </script>
    <?php endif; ?>
</div>
