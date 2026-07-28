<?php
/**
 * Presets CRUD page + AJAX
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCOTL_Admin_Presets {

    public static function init() {
        add_action( 'wp_ajax_wcotl_get_presets_json', array( __CLASS__, 'ajax_get_presets_json' ) );
    }

    public static function page() {
        global $wpdb;
        $t      = $wpdb->prefix . 'order_timeline_presets';
        $icons  = WCOTL_Icons::map();
        $notice = '';

        /* ---- DELETE ---- */
        if (
            isset( $_GET['action'], $_GET['preset_id'], $_GET['_wpnonce'] ) &&
            $_GET['action'] === 'delete_preset' &&
            wp_verify_nonce( $_GET['_wpnonce'], 'wcotl_delete_preset' )
        ) {
            $wpdb->delete( $t, [ 'id' => absint( $_GET['preset_id'] ) ] );
            $notice = '<div class="wcotl-notice wcotl-notice-success">Preset eliminato.</div>';
        }

        /* ---- EDIT (POST) ---- */
        if (
            isset( $_POST['wcotl_edit_preset'], $_POST['_wpnonce_ep'], $_POST['preset_id'] ) &&
            wp_verify_nonce( $_POST['_wpnonce_ep'], 'wcotl_edit_preset_' . absint( $_POST['preset_id'] ) )
        ) {
            $pid   = absint( $_POST['preset_id'] );
            $name  = sanitize_text_field( $_POST['preset_name'] ?? '' );
            $label = sanitize_text_field( $_POST['step_label'] ?? '' );
            $note  = sanitize_textarea_field( $_POST['step_note'] ?? '' );
            $icon  = sanitize_key( $_POST['step_icon'] ?? 'truck' );
            $order = absint( $_POST['sort_order'] ?? 0 );
            if ( $name && $label ) {
                $wpdb->update( $t, compact( 'name', 'label', 'note', 'icon', 'order' ), [ 'id' => $pid ],
                    [ '%s','%s','%s','%s','%d' ], [ '%d' ] );
                // usa i nomi colonna corretti
                $wpdb->update( $t, [
                    'preset_name' => $name,
                    'step_label'  => $label,
                    'step_note'   => $note,
                    'step_icon'   => $icon,
                    'sort_order'  => $order,
                ], [ 'id' => $pid ] );
                $notice = '<div class="wcotl-notice wcotl-notice-success">Preset aggiornato.</div>';
            } else {
                $notice = '<div class="wcotl-notice wcotl-notice-error">Nome e Descrizione step sono obbligatori.</div>';
            }
        }

        /* ---- CREATE (POST) ---- */
        if (
            isset( $_POST['wcotl_add_preset'], $_POST['_wpnonce_ap'] ) &&
            wp_verify_nonce( $_POST['_wpnonce_ap'], 'wcotl_add_preset' )
        ) {
            $name  = sanitize_text_field( $_POST['preset_name'] ?? '' );
            $label = sanitize_text_field( $_POST['step_label'] ?? '' );
            $note  = sanitize_textarea_field( $_POST['step_note'] ?? '' );
            $icon  = sanitize_key( $_POST['step_icon'] ?? 'truck' );
            $order = absint( $_POST['sort_order'] ?? 0 );
            if ( $name && $label ) {
                $wpdb->insert( $t, [
                    'preset_name' => $name,
                    'step_label'  => $label,
                    'step_note'   => $note,
                    'step_icon'   => $icon,
                    'sort_order'  => $order,
                ] );
                $notice = '<div class="wcotl-notice wcotl-notice-success">Preset creato con successo.</div>';
            } else {
                $notice = '<div class="wcotl-notice wcotl-notice-error">Nome e Descrizione step sono obbligatori.</div>';
            }
        }

        $presets   = WCOTL_DB::get_presets();
        $edit_id   = isset( $_GET['edit_preset'] ) ? absint( $_GET['edit_preset'] ) : 0;
        $edit_row  = null;
        if ( $edit_id ) {
            foreach ( $presets as $p ) {
                if ( (int) $p->id === $edit_id ) { $edit_row = $p; break; }
            }
        }
        ?>
        <div class="wrap wcotl-admin">
            <h1>
                <span class="dashicons dashicons-shortcode"></span>
                Preset Step
            </h1>
            <p style="color:#666;font-size:13px;margin-bottom:20px;">
                I preset ti permettono di pre-compilare rapidamente i campi "Descrizione", "Nota" e "Icona"
                quando aggiungi uno step a un tracking. Seleziona il preset dal menu a tendina nel form di aggiunta.
            </p>
            <?php echo wp_kses_post( $notice ); ?>

            <div style="display:grid;grid-template-columns:1fr 400px;gap:24px;align-items:start;">

                <!-- Lista preset -->
                <div>
                    <?php if ( empty( $presets ) ) : ?>
                        <div class="wcotl-card"><p style="color:#888;">Nessun preset ancora. Creane uno dal form a destra.</p></div>
                    <?php else : ?>
                    <table class="wcotl-table">
                        <thead>
                            <tr>
                                <th style="width:28px;">#</th>
                                <th>Nome Preset</th>
                                <th>Descrizione step</th>
                                <th>Icona</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $presets as $p ) :
                            $del_url  = wp_nonce_url(
                                admin_url( 'admin.php?page=wcotl-presets&action=delete_preset&preset_id=' . $p->id ),
                                'wcotl_delete_preset'
                            );
                            $edit_url = admin_url( 'admin.php?page=wcotl-presets&edit_preset=' . $p->id );
                        ?>
                        <tr>
                            <td style="color:#aaa;font-size:11px;"><?php echo absint( $p->sort_order ); ?></td>
                            <td><strong><?php echo esc_html( $p->preset_name ); ?></strong></td>
                            <td style="font-size:13px;">
                                <?php echo esc_html( $p->step_label ); ?>
                                <?php if ( $p->step_note ) : ?>
                                    <br><span style="font-size:11px;color:#888;font-style:italic;"><?php echo esc_html( mb_strimwidth( $p->step_note, 0, 60, '…' ) ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;"><?php echo esc_html( $p->step_icon ); ?></td>
                            <td>
                                <div class="wcotl-actions-row">
                                    <a href="<?php echo esc_url( $edit_url ); ?>" class="wcotl-btn wcotl-btn-secondary wcotl-btn-sm">Modifica</a>
                                    <a href="<?php echo esc_url( $del_url ); ?>" class="wcotl-btn wcotl-btn-danger wcotl-btn-sm"
                                       onclick="return confirm('Eliminare il preset «<?php echo esc_js( $p->preset_name ); ?>»?')">Elimina</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <!-- Form crea / modifica -->
                <div class="wcotl-card">
                    <h2><?php echo $edit_row ? 'Modifica Preset' : 'Nuovo Preset'; ?></h2>
                    <form method="POST">
                        <?php if ( $edit_row ) : ?>
                            <?php wp_nonce_field( 'wcotl_edit_preset_' . $edit_row->id, '_wpnonce_ep' ); ?>
                            <input type="hidden" name="wcotl_edit_preset" value="1">
                            <input type="hidden" name="preset_id" value="<?php echo absint( $edit_row->id ); ?>">
                        <?php else : ?>
                            <?php wp_nonce_field( 'wcotl_add_preset', '_wpnonce_ap' ); ?>
                            <input type="hidden" name="wcotl_add_preset" value="1">
                        <?php endif; ?>

                        <div class="wcotl-form-row">
                            <label>Nome preset <span style="color:#c0392b">*</span></label>
                            <input type="text" name="preset_name"
                                   value="<?php echo esc_attr( $edit_row ? $edit_row->preset_name : '' ); ?>"
                                   placeholder="es. Partenza magazzino Milano" required>
                            <small style="font-size:11px;color:#aaa;margin-top:2px;">Nome interno, non visibile al cliente.</small>
                        </div>
                        <div class="wcotl-form-row">
                            <label>Descrizione step <span style="color:#c0392b">*</span></label>
                            <input type="text" name="step_label"
                                   value="<?php echo esc_attr( $edit_row ? $edit_row->step_label : '' ); ?>"
                                   placeholder="es. Merce caricata a Milano" required>
                        </div>
                        <div class="wcotl-form-row">
                            <label>Nota (opzionale)</label>
                            <textarea name="step_note" placeholder="Dettagli aggiuntivi..."><?php echo esc_textarea( $edit_row ? $edit_row->step_note : '' ); ?></textarea>
                        </div>
                        <div class="wcotl-form-row">
                            <label>Icona</label>
                            <select name="step_icon">
                                <?php foreach ( array_keys( $icons ) as $k ) : ?>
                                    <option value="<?php echo esc_attr( $k ); ?>"
                                        <?php selected( $edit_row ? $edit_row->step_icon : 'truck', $k ); ?>>
                                        <?php echo esc_html( ucfirst( str_replace( '_', ' ', $k ) ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="wcotl-form-row">
                            <label>Ordine di visualizzazione</label>
                            <input type="number" name="sort_order" min="0"
                                   value="<?php echo esc_attr( $edit_row ? $edit_row->sort_order : 0 ); ?>"
                                   style="width:100px;">
                            <small style="font-size:11px;color:#aaa;margin-top:2px;">Valori più bassi appaiono prima nel selettore.</small>
                        </div>

                        <div style="display:flex;gap:8px;">
                            <button type="submit" class="wcotl-btn wcotl-btn-primary" style="flex:1;padding:10px;font-size:13px;">
                                <?php echo $edit_row ? 'Salva modifiche →' : 'Crea Preset →'; ?>
                            </button>
                            <?php if ( $edit_row ) : ?>
                                <a href="<?php echo admin_url('admin.php?page=wcotl-presets'); ?>" class="wcotl-btn wcotl-btn-secondary" style="padding:10px 14px;font-size:13px;">Annulla</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

            </div>
        </div>
        <?php
    }

    public static function ajax_get_presets_json() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );
        $presets = WCOTL_DB::get_presets();
        wp_send_json_success( $presets );
    }

}
