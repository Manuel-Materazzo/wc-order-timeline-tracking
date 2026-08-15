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
            $notice = '<div class="notice notice-success is-dismissible"><p>Preset deleted.</p></div>';
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
                // use correct column names
                $wpdb->update( $t, [
                    'preset_name' => $name,
                    'step_label'  => $label,
                    'step_note'   => $note,
                    'step_icon'   => $icon,
                    'sort_order'  => $order,
                ], [ 'id' => $pid ] );
                $notice = '<div class="notice notice-success is-dismissible"><p>Preset updated.</p></div>';
            } else {
                $notice = '<div class="notice notice-error is-dismissible"><p>Name and Step Description are required.</p></div>';
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
                $notice = '<div class="notice notice-success is-dismissible"><p>Preset created successfully.</p></div>';
            } else {
                $notice = '<div class="notice notice-error is-dismissible"><p>Name and Step Description are required.</p></div>';
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
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-shortcode"></span>
                Step Presets
            </h1>
            <p style="color:#666;font-size:13px;margin-bottom:20px;">
                Presets allow you to quickly pre-fill "Description", "Note" and "Icon" fields
                when adding a step to a tracking code. Select the preset from the dropdown menu in the add form.
            </p>
            <?php echo wp_kses_post( $notice ); ?>

            <div style="display:grid;grid-template-columns:1fr 320px;gap:24px;align-items:start;">

                <!-- Preset List -->
                <div>
                    <?php if ( empty( $presets ) ) : ?>
                        <div class="card"><p style="color:#646970;">No presets yet. Create one using the form on the right.</p></div>
                    <?php else : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:28px;">#</th>
                                <th>Preset Name</th>
                                <th>Step Description</th>
                                <th>Icon</th>
                                <th>Actions</th>
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
                                    <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-secondary button-small">Edit</a>
                                    <a href="<?php echo esc_url( $del_url ); ?>" class="button button-link-delete button-small"
                                       onclick="return confirm('Delete preset «<?php echo esc_js( $p->preset_name ); ?>»?')">Delete</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <!-- Create / Edit Form -->
                <div class="card" style="max-width:none;">
                    <h2><?php echo $edit_row ? 'Edit Preset' : 'New Preset'; ?></h2>
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
                            <label>Preset Name <span style="color:#d63638">*</span></label>
                            <input type="text" name="preset_name"
                                   value="<?php echo esc_attr( $edit_row ? $edit_row->preset_name : '' ); ?>"
                                   placeholder="e.g. Departed Milan Warehouse" required>
                            <small style="font-size:11px;color:#646970;display:block;margin-top:2px;">Internal name, not visible to customer.</small>
                        </div>
                        <div class="wcotl-form-row">
                            <label>Step Description <span style="color:#d63638">*</span></label>
                            <input type="text" name="step_label"
                                   value="<?php echo esc_attr( $edit_row ? $edit_row->step_label : '' ); ?>"
                                   placeholder="e.g. Goods loaded in Milan" required>
                        </div>
                        <div class="wcotl-form-row">
                            <label>Note (optional)</label>
                            <textarea name="step_note" placeholder="Additional details..."><?php echo esc_textarea( $edit_row ? $edit_row->step_note : '' ); ?></textarea>
                        </div>
                        <div class="wcotl-form-row">
                            <label>Icon</label>
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
                            <label>Sort Order</label>
                            <input type="number" name="sort_order" min="0"
                                   value="<?php echo esc_attr( $edit_row ? $edit_row->sort_order : 0 ); ?>"
                                   style="width:100px;">
                            <small style="font-size:11px;color:#646970;display:block;margin-top:2px;">Lower numbers appear first in the selector.</small>
                        </div>

                        <div style="display:flex;gap:8px;">
                            <button type="submit" class="button button-primary" style="flex:1;">
                                <?php echo $edit_row ? 'Save changes' : 'Create Preset'; ?>
                            </button>
                            <?php if ( $edit_row ) : ?>
                                <a href="<?php echo admin_url('admin.php?page=wcotl-presets'); ?>" class="button button-secondary">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

            </div>
        </div>
        <?php
    }

    public static function ajax_get_presets_json() {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );
        $presets = WCOTL_DB::get_presets();
        wp_send_json_success( $presets );
    }

}
