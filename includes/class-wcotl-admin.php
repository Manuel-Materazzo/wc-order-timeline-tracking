<?php
/**
 * Admin menu, tracking list page, view-code page
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCOTL_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_head', array( __CLASS__, 'admin_styles' ) );
        add_action( 'admin_head', array( __CLASS__, 'admin_nonce_script' ) );
    }

    public static function register_menu() {
        add_menu_page(
            'Timeline Tracking',
            'Timeline Tracking',
            'manage_woocommerce',
            'wcotl-tracking',
            array( 'WCOTL_Admin', 'page_list' ),
            'dashicons-location-alt',
            56
        );
        add_submenu_page(
            'wcotl-tracking',
            'All Codes',
            'All Codes',
            'manage_woocommerce',
            'wcotl-tracking',
            array( 'WCOTL_Admin', 'page_list' )
        );
        add_submenu_page(
            'wcotl-tracking',
            'New Code',
            '+ New Code',
            'manage_woocommerce',
            'wcotl-new-code',
            array( 'WCOTL_Admin', 'page_new_code' )
        );
        add_submenu_page(
            'wcotl-tracking',
            'Step Presets',
            'Step Presets',
            'manage_woocommerce',
            'wcotl-presets',
            array( 'WCOTL_Admin_Presets', 'page' )
        );
    }

    public static function admin_styles() {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'wcotl' ) === false ) return;
        ?>
        <style>
        .wcotl-admin { max-width: 980px; }
        .wcotl-admin h1 { display:flex; align-items:center; gap:10px; }
        .wcotl-code-badge { font-family: monospace; background: #f0f0f1; padding: 2px 6px; border-radius: 4px; border: 1px solid #c3c4c7; }
        .wcotl-form-row { margin-bottom: 15px; }
        .wcotl-form-row label { display: block; font-weight: 600; margin-bottom: 4px; }
        .wcotl-form-row input[type="text"],
        .wcotl-form-row input[type="datetime-local"],
        .wcotl-form-row input[type="date"],
        .wcotl-form-row select,
        .wcotl-form-row textarea { width: 100%; }
        .wcotl-form-row textarea { min-height: 80px; }
        .wcotl-steps-list { list-style: none; padding: 0; margin: 0; }
        .wcotl-steps-list > li { padding: 12px 0; border-bottom: 1px solid #f0f0f1; margin: 0; }
        .wcotl-steps-list > li:last-child { border-bottom: none; }
        .wcotl-actions-row { display: flex; gap: 6px; flex-wrap: wrap; }
        .tooltip { position: relative; display: inline-block; cursor: pointer; }
        .tooltiptext { visibility: hidden; width: 300px; font-weight: normal; background-color: #1d2327; color: #fff; text-align: center; padding: 6px 10px; border-radius: 4px; position: absolute; z-index: 10; bottom: 125%; left: 50%; transform: translateX(-50%); font-size: 12px; line-height: 1.4; }
        .tooltip:hover .tooltiptext { visibility: visible; }
        .wcotl-source-badge { display: inline-block; font-size: 10px; font-weight: 600; text-transform: uppercase; padding: 2px 6px; border-radius: 3px; margin-left: 6px; vertical-align: middle; }
        .wcotl-source-auto   { background: #e7f5fe; color: #0073aa; border: 1px solid #bfe7f9; }
        .wcotl-source-manual { background: #f0f0f1; color: #50575e; border: 1px solid #c3c4c7; }
        .wcotl-sync-status { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; padding: 4px 8px; border-radius: 4px; }
        .wcotl-sync-active  { background: #edfaef; color: #008a20; border: 1px solid #b8e6bf; }
        .wcotl-sync-stopped { background: #fcf0f1; color: #d63638; border: 1px solid #f5c2c7; }
        .wcotl-sync-pending { background: #fcf9e8; color: #9a6e1a; border: 1px solid #f0c060; }
        </style>
        <?php
    }

    public static function admin_nonce_script() {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'wcotl' ) === false ) return;
        ?>
        <script>
        var WCOTL_AJAX = {
            url:   '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>',
            nonce: '<?php echo esc_js( wp_create_nonce('wcotl_admin_nonce') ); ?>'
        };
        </script>
        <?php
    }

    public static function page_list() {
        global $wpdb;
        $table = $wpdb->prefix . 'order_timeline';

        // Step deletion
        if (
            isset( $_GET['action'], $_GET['step_id'], $_GET['_wpnonce'] ) &&
            $_GET['action'] === 'delete_step' &&
            wp_verify_nonce( $_GET['_wpnonce'], 'wcotl_delete_step' )
        ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'wc-order-timeline' ), 403 );
            }
            $wpdb->delete( $table, [ 'id' => absint( $_GET['step_id'] ) ] );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Step deleted.', 'wc-order-timeline' ) . '</p></div>';
        }

        // Void / unvoid step toggle
        if (
            isset( $_GET['action'], $_GET['step_id'], $_GET['_wpnonce'] ) &&
            in_array( $_GET['action'], [ 'void_step', 'unvoid_step' ], true ) &&
            wp_verify_nonce( $_GET['_wpnonce'], 'wcotl_void_step_' . absint( $_GET['step_id'] ) )
        ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'wc-order-timeline' ), 403 );
            }
            $step_id = absint( $_GET['step_id'] );
            if ( $_GET['action'] === 'void_step' ) {
                $wpdb->update( $table, [ 'step_voided' => 1 ], [ 'id' => $step_id ] );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Step marked as unconfirmed.', 'wc-order-timeline' ) . '</p></div>';
            } else {
                $wpdb->update( $table, [ 'step_voided' => 0, 'step_void_reason' => null ], [ 'id' => $step_id ] );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Step restored.', 'wc-order-timeline' ) . '</p></div>';
            }
        }

        // Edit step (POST)
        if (
            isset( $_POST['wcotl_edit_step'], $_POST['_wpnonce_edit'], $_POST['step_id'] ) &&
            wp_verify_nonce( $_POST['_wpnonce_edit'], 'wcotl_edit_step_' . absint( $_POST['step_id'] ) )
        ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'wc-order-timeline' ), 403 );
            }
            $step_id = absint( $_POST['step_id'] );
            $label   = sanitize_text_field( wp_unslash( $_POST['step_label'] ?? '' ) );
            $note    = sanitize_textarea_field( wp_unslash( $_POST['step_note'] ?? '' ) );
            $date    = sanitize_text_field( wp_unslash( $_POST['step_date'] ?? '' ) );
            $icon    = sanitize_key( wp_unslash( $_POST['step_icon'] ?? 'truck' ) );

            if ( $label && $date ) {
                $dt = DateTime::createFromFormat( 'Y-m-d\TH:i', $date );
                if ( ! $dt ) $dt = new DateTime( $date );
                $wpdb->update( $table, [
                    'step_date'  => $dt->format('Y-m-d H:i:s'),
                    'step_label' => $label,
                    'step_note'  => $note,
                    'step_icon'  => $icon,
                ], [ 'id' => $step_id ] );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Step updated successfully!', 'wc-order-timeline' ) . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please fill in at least Date/Time and Description.', 'wc-order-timeline' ) . '</p></div>';
            }
        }

        // Delete tracking code (all steps and metadata)
        if (
            isset( $_GET['action'], $_GET['tracking_code'], $_GET['_wpnonce'] ) &&
            $_GET['action'] === 'delete_code' &&
            wp_verify_nonce( $_GET['_wpnonce'], 'wcotl_delete_code' )
        ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'wc-order-timeline' ), 403 );
            }
            $code_to_delete = sanitize_text_field( wp_unslash( $_GET['tracking_code'] ) );
            $wpdb->delete( $table, [ 'tracking_code' => $code_to_delete ] );
            $wpdb->delete( $wpdb->prefix . 'order_timeline_meta', [ 'tracking_code' => $code_to_delete ] );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Code deleted.', 'wc-order-timeline' ) . '</p></div>';
        }

        // Fetch all distinct tracking codes
        $codes = $wpdb->get_results(
            "SELECT tracking_code, MAX(order_id) as order_id, COUNT(*) as steps, MAX(step_date) as last_update
             FROM {$table}
             GROUP BY tracking_code
             ORDER BY last_update DESC"
        );

        $view_code = isset( $_GET['view'] ) ? sanitize_text_field( $_GET['view'] ) : '';
        ?>
        <div class="wrap wcotl-admin">
            <h1>
                <span class="dashicons dashicons-location-alt"></span>
                Timeline Tracking
                <a href="<?php echo admin_url('admin.php?page=wcotl-new-code'); ?>" class="button button-primary" style="margin-left:8px;">+ New Code</a>
            </h1>

            <?php if ( $view_code ) :
                WCOTL_Admin::page_view_code( $view_code );
            else : ?>

            <?php if ( empty( $codes ) ) : ?>
                <div class="card"><p>No tracking codes found. <a href="<?php echo admin_url('admin.php?page=wcotl-new-code'); ?>">Create the first one</a></p></div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Tracking Code</th>
                            <th>WC Order</th>
                            <th>Steps</th>
                            <th>Last Update</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $codes as $row ) :
                        $delete_url = wp_nonce_url(
                            admin_url( 'admin.php?page=wcotl-tracking&action=delete_code&tracking_code=' . urlencode( $row->tracking_code ) ),
                            'wcotl_delete_code'
                        );
                        $view_url   = admin_url( 'admin.php?page=wcotl-tracking&view=' . urlencode( $row->tracking_code ) );
                        $dt = new DateTime( $row->last_update );
                    ?>
                    <tr>
                        <td><span class="wcotl-code-badge"><?php echo esc_html( $row->tracking_code ); ?></span></td>
                        <td><?php echo $row->order_id ? '<a href="' . esc_url( admin_url( 'post.php?post=' . $row->order_id . '&action=edit' ) ) . '">#' . esc_html( $row->order_id ) . '</a>' : '—'; ?></td>
                        <td><?php echo absint( $row->steps ); ?></td>
                        <td><?php echo esc_html( $dt->format('d/m/Y H:i') ); ?></td>
                        <td>
                            <div class="wcotl-actions-row">
                                <a href="<?php echo esc_url( $view_url ); ?>" class="button button-secondary button-small">Manage</a>
                                <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-link-delete button-small"
                                   onclick="return confirm('Delete all steps for this code?')">Delete</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function page_view_code( $code ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'order_timeline';
        $notice = '';

        // Save estimated delivery date
        if ( isset( $_POST['wcotl_save_delivery'], $_POST['_wpnonce_delivery'] ) && wp_verify_nonce( $_POST['_wpnonce_delivery'], 'wcotl_save_delivery_' . $code ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'wc-order-timeline' ), 403 );
            }
            $delivery_date = sanitize_text_field( wp_unslash( $_POST['estimated_delivery'] ?? '' ) );
            WCOTL_DB::set_meta( $code, 'estimated_delivery', $delivery_date );
            $notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Estimated delivery date updated.', 'wc-order-timeline' ) . '</p></div>';
        }

        // Save actual delivery date
        if ( isset( $_POST['wcotl_save_delivered'], $_POST['_wpnonce_delivered'] ) && wp_verify_nonce( $_POST['_wpnonce_delivered'], 'wcotl_save_delivered_' . $code ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'wc-order-timeline' ), 403 );
            }
            $delivered_date = sanitize_text_field( wp_unslash( $_POST['delivered_at'] ?? '' ) );
            WCOTL_DB::set_meta( $code, 'delivered_at', $delivered_date );
            $notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Actual delivery date updated.', 'wc-order-timeline' ) . '</p></div>';
        }

        // Save step void reason
        if ( isset( $_POST['wcotl_save_void_reason'], $_POST['_wpnonce_void'], $_POST['step_id'] ) && wp_verify_nonce( $_POST['_wpnonce_void'], 'wcotl_void_reason_' . absint( $_POST['step_id'] ) ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'wc-order-timeline' ), 403 );
            }
            $step_id     = absint( $_POST['step_id'] );
            $void_reason = sanitize_textarea_field( wp_unslash( $_POST['step_void_reason'] ?? '' ) );
            $wpdb->update( $table, [
                'step_voided'      => 1,
                'step_void_reason' => $void_reason,
            ], [ 'id' => $step_id ] );
            $notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Step marked as unconfirmed.', 'wc-order-timeline' ) . '</p></div>';
        }

        // Restore voided step
        if ( isset( $_GET['action'], $_GET['step_id'], $_GET['_wpnonce'] ) &&
            $_GET['action'] === 'unvoid_step' &&
            wp_verify_nonce( $_GET['_wpnonce'], 'wcotl_void_step_' . absint( $_GET['step_id'] ) ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'wc-order-timeline' ), 403 );
            }
            $step_id = absint( $_GET['step_id'] );
            $wpdb->update( $table, [ 'step_voided' => 0, 'step_void_reason' => null ], [ 'id' => $step_id ] );
            $notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Step restored.', 'wc-order-timeline' ) . '</p></div>';
        }

        // Edit step
        if ( isset( $_POST['wcotl_edit_step'], $_POST['_wpnonce_edit'], $_POST['step_id'] ) && wp_verify_nonce( $_POST['_wpnonce_edit'], 'wcotl_edit_step_' . absint( $_POST['step_id'] ) ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'wc-order-timeline' ), 403 );
            }
            $step_id = absint( $_POST['step_id'] );
            $label   = sanitize_text_field( wp_unslash( $_POST['step_label'] ?? '' ) );
            $note    = sanitize_textarea_field( wp_unslash( $_POST['step_note'] ?? '' ) );
            $date    = sanitize_text_field( wp_unslash( $_POST['step_date'] ?? '' ) );
            $icon    = sanitize_key( wp_unslash( $_POST['step_icon'] ?? 'truck' ) );

            if ( $label && $date ) {
                $dt = DateTime::createFromFormat( 'Y-m-d\TH:i', $date );
                if ( ! $dt ) $dt = new DateTime( $date );
                $wpdb->update( $table, [
                    'step_date'  => $dt->format('Y-m-d H:i:s'),
                    'step_label' => $label,
                    'step_note'  => $note,
                    'step_icon'  => $icon,
                ], [ 'id' => $step_id ] );
                $notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Step updated successfully!', 'wc-order-timeline' ) . '</p></div>';
            } else {
                $notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please fill in at least Date/Time and Description.', 'wc-order-timeline' ) . '</p></div>';
            }
        }

        // Update WooCommerce order associated with code
        if ( isset( $_POST['wcotl_save_order_id'], $_POST['_wpnonce_order_id'] ) && wp_verify_nonce( $_POST['_wpnonce_order_id'], 'wcotl_save_order_id_' . $code ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'wc-order-timeline' ), 403 );
            }
            $new_order_id = absint( $_POST['order_id'] ?? 0 );
            if ( $new_order_id ) {
                WCOTL_DB::set_meta( $code, 'order_id', $new_order_id );
                // Update existing steps with new order_id
                $wpdb->update( $table, [ 'order_id' => $new_order_id ], [ 'tracking_code' => $code ] );
            } else {
                WCOTL_DB::set_meta( $code, 'order_id', '' );
                $wpdb->update( $table, [ 'order_id' => 0 ], [ 'tracking_code' => $code ] );
            }
            $notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Order updated.', 'wc-order-timeline' ) . '</p></div>';
        }

        // Save new step
        if ( isset( $_POST['wcotl_add_step'], $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'wcotl_add_step' ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'wc-order-timeline' ), 403 );
            }
            $label = sanitize_text_field( wp_unslash( $_POST['step_label'] ?? '' ) );
            $note  = sanitize_textarea_field( wp_unslash( $_POST['step_note'] ?? '' ) );
            $date  = sanitize_text_field( wp_unslash( $_POST['step_date'] ?? '' ) );
            $icon  = sanitize_key( wp_unslash( $_POST['step_icon'] ?? 'truck' ) );

            if ( $label && $date ) {
                $dt = DateTime::createFromFormat( 'Y-m-d\TH:i', $date );
                if ( ! $dt ) $dt = new DateTime( $date );

                $stored_order_id = absint( WCOTL_DB::get_meta( $code, 'order_id' ) );

                $wpdb->insert( $table, [
                    'tracking_code' => $code,
                    'order_id'      => $stored_order_id,
                    'step_date'     => $dt->format('Y-m-d H:i:s'),
                    'step_label'    => $label,
                    'step_note'     => $note,
                    'step_icon'     => $icon,
                ] );
                $notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Step added successfully!', 'wc-order-timeline' ) . '</p></div>';
            } else {
                $notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please fill in at least Date/Time and Description.', 'wc-order-timeline' ) . '</p></div>';
            }
        }

        $steps = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE tracking_code = %s ORDER BY step_date ASC", $code )
        );

        $order_id_meta = WCOTL_DB::get_meta( $code, 'order_id' );
        if ( $order_id_meta === null || $order_id_meta === '' ) {
            $order_id_from_steps = $steps ? absint( $steps[0]->order_id ) : 0;
            if ( $order_id_from_steps ) {
                WCOTL_DB::set_meta( $code, 'order_id', $order_id_from_steps );
                $order_id_meta = $order_id_from_steps;
            }
        }
        $order_id           = absint( $order_id_meta );
        $icons              = WCOTL_Icons::map();
        $estimated_delivery = WCOTL_DB::get_meta( $code, 'estimated_delivery' );
        $delivered_at       = WCOTL_DB::get_meta( $code, 'delivered_at' );

        // Auto-tracking meta
        $at_real_number  = WCOTL_DB::get_meta( $code, WCOTL_Auto_Sync::META_REAL_NUMBER );
        $at_carrier      = (int) ( WCOTL_DB::get_meta( $code, WCOTL_Auto_Sync::META_CARRIER_CODE ) ?: 0 );
        $at_registered   = WCOTL_DB::get_meta( $code, WCOTL_Auto_Sync::META_REGISTERED );
        $at_last_status  = WCOTL_DB::get_meta( $code, WCOTL_Auto_Sync::META_LAST_STATUS );
        $at_last_event   = WCOTL_DB::get_meta( $code, WCOTL_Auto_Sync::META_LAST_EVENT_DATE );
        $at_stopped      = WCOTL_DB::get_meta( $code, WCOTL_Auto_Sync::META_SYNC_STOPPED );
        $at_stop_reason  = WCOTL_DB::get_meta( $code, WCOTL_Auto_Sync::META_STOP_REASON );
        $provider_active = (bool) get_option( 'wcotl_17track_api_key', '' );

        echo wp_kses_post( $notice );
        ?>
        <p>
    		<a href="<?php echo admin_url('admin.php?page=wcotl-tracking'); ?>">← Back to list</a>
    	</p>
    	
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">
    		<?php if ( $order_id ) : ?>
                <a href="<?php echo esc_url( admin_url('post.php?post=' . $order_id . '&action=edit') ); ?>" class="button button-secondary button-small">Order #<?php echo $order_id; ?></a>
            <?php endif; ?>
    		<h2 style="margin:0;">
    			Code: 
    			<span class="wcotl-code-badge">
    				<a target="_blank" href="<?php echo esc_url( add_query_arg( 'tracking', $code, home_url( '/order-tracking/' ) ) ); ?>">
    					<?php echo esc_html($code); ?>
    				</a>
    			</span>
    		</h2>
        </div>

        <!-- Associated WooCommerce Order -->
        <div class="card" style="margin-bottom:24px;max-width:none;">
            <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <?php wp_nonce_field( 'wcotl_save_order_id_' . $code, '_wpnonce_order_id' ); ?>
                <input type="hidden" name="wcotl_save_order_id" value="1">
                <div class="wcotl-form-row" style="margin-bottom:0;width:400px;">
                    <label>Order number (WooCommerce ID)
                        <span class="dashicons dashicons-info tooltip">
                            <span class="tooltiptext">
                                The order associated with this tracking code will be automatically used for all steps.
                            </span>
                        </span>
                    </label>
                    <input type="text" name="order_id"
                           value="<?php echo $order_id ?: ''; ?>"
                           placeholder="e.g. 1042">
                </div>
                <button type="submit" class="button button-primary">
                    Save
                </button>
                <?php if ( $order_id ) : ?>
                    <span style="font-size:12px;color:#1e8449;padding-bottom:10px;">
                        ✓ Order #<?php echo $order_id; ?>
                    </span>
                <?php else : ?>
                    <span style="font-size:12px;color:#888;padding-bottom:10px;">No order associated</span>
                <?php endif; ?>
            </form>
            <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <?php wp_nonce_field( 'wcotl_save_delivery_' . $code, '_wpnonce_delivery' ); ?>
                <input type="hidden" name="wcotl_save_delivery" value="1">
                <div class="wcotl-form-row" style="margin-bottom:0;width:400px;">
                    <label>Estimated date (optional)
                        <span class="dashicons dashicons-info tooltip">
                            <span class="tooltiptext">
                                Visible to the customer on the tracking page. Leave blank to hide.
                            </span>
                        </span>
                    </label>
                    <input type="date" name="estimated_delivery"
                           value="<?php echo esc_attr( $estimated_delivery ?: '' ); ?>">
                </div>
                <button type="submit" class="button button-primary">
                    Save date
                </button>
                <?php if ( $estimated_delivery ) : ?>
                    <span style="font-size:12px;color:#008a20;padding-bottom:10px;">
                        ✓ Currently: <?php echo esc_html( (new DateTime($estimated_delivery))->format('d/m/Y') ); ?>
                    </span>
                <?php else : ?>
                    <span style="font-size:12px;color:#888;padding-bottom:10px;">Not set yet</span>
                <?php endif; ?>
            </form>
            <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <?php wp_nonce_field( 'wcotl_save_delivered_' . $code, '_wpnonce_delivered' ); ?>
                <input type="hidden" name="wcotl_save_delivered" value="1">
                <div class="wcotl-form-row" style="margin-bottom:0;width:400px;">
                    <label>
                        Actual delivery date (optional)
                        <span class="dashicons dashicons-info tooltip">
                            <span class="tooltiptext">
                                Set this date to replace the 'Estimated delivery' banner with the green completed delivery banner. Leave blank to revert to estimated date.
                            </span>
                        </span>
                    </label>
                    <input type="date" name="delivered_at"
                           value="<?php echo esc_attr( $delivered_at ?: '' ); ?>">
                </div>
                <button type="submit" class="button button-primary">
                    <?php echo $delivered_at ? 'Update' : 'Mark as delivered'; ?>
                </button>
                <?php if ( $delivered_at ) : ?>
                    <span style="font-size:12px;color:#1e8449;padding-bottom:10px;">
                        ✓ Delivered on: <?php echo esc_html( (new DateTime($delivered_at))->format('d/m/Y') ); ?>
                    </span>
                <?php else : ?>
                    <span style="font-size:12px;color:#888;align-self:center;">Not delivered yet</span>
                <?php endif; ?>
            </form>
        </div>

        <!-- Auto-tracking 17TRACK -->
        <div class="card" style="margin-bottom:24px;max-width:none;">
            <h2><span class="dashicons dashicons-share-alt"></span> Auto-Tracking (17TRACK)</h2>
            <?php if ( ! $provider_active ) : ?>
                <p style="font-size:13px;color:#646970;">
                    Auto-tracking is not active. Configure your API key in
                    <a href="<?php echo esc_url( admin_url('admin.php?page=wcotl-settings') ); ?>">Settings</a>.
                </p>
            <?php else : ?>

            <!-- Status badge -->
            <div style="margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <?php if ( $at_real_number ) : ?>
                    <?php if ( $at_stopped ) : ?>
                        <span class="wcotl-sync-status wcotl-sync-stopped">⏹ Sync stopped</span>
                        <?php if ( $at_stop_reason ) : ?>
                            <span style="font-size:12px;color:#646970;"><?php echo esc_html($at_stop_reason); ?></span>
                        <?php endif; ?>
                        <button class="button button-small"
                                onclick="wcotlResumeSync()">↺ Resume sync</button>
                    <?php elseif ( $at_registered ) : ?>
                        <span class="wcotl-sync-status wcotl-sync-active">✓ Sync active</span>
                        <?php if ( $at_last_status ) : ?>
                            <span style="font-size:12px;color:#646970;">Status: <strong><?php echo esc_html($at_last_status); ?></strong></span>
                        <?php endif; ?>
                        <?php if ( $at_last_event ) : ?>
                            <span style="font-size:12px;color:#646970;">Last event: <?php echo esc_html((new DateTime($at_last_event))->format('d/m/Y H:i')); ?></span>
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="wcotl-sync-status wcotl-sync-pending">⏳ Waiting for first sync</span>
                    <?php endif; ?>

                    <?php if ( $at_real_number && ! $at_stopped ) : ?>
                        <button class="button button-secondary button-small" id="wcotl-sync-now-btn"
                                onclick="wcotlSyncNow()">
                            ↻ Sync now
                        </button>
                    <?php endif; ?>
                <?php else : ?>
                    <span class="wcotl-sync-status wcotl-sync-pending">— No real tracking number set</span>
                <?php endif; ?>
            </div>

            <!-- Real tracking number form -->
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px;">
                <div class="wcotl-form-row" style="margin-bottom:0;width:260px;">
                    <label>Carrier tracking number
                        <span class="dashicons dashicons-info tooltip">
                            <span class="tooltiptext">The real carrier tracking code (e.g. RR123456789CN). Used to query 17TRACK.</span>
                        </span>
                    </label>
                    <input type="text" id="wcotl-real-number"
                           value="<?php echo esc_attr($at_real_number ?: ''); ?>"
                           placeholder="e.g. RR123456789CN"
                           style="font-family:monospace;">
                </div>
                <div class="wcotl-form-row" style="margin-bottom:0;width:260px;">
                    <label>Carrier
                        <span class="dashicons dashicons-info tooltip">
                            <span class="tooltiptext">Click "Detect carrier" to let 17TRACK suggest carriers automatically. If none are detected, enter the carrier code manually (find codes at <a href="https://www.17track.net/en/carriers" target="_blank">17track.net/carrier-codes</a>).</span>
                        </span>
                    </label>
                    <select id="wcotl-carrier-select">
                        <option value="0">— Auto-detect —</option>
                        <?php if ( $at_carrier ) : ?>
                            <option value="<?php echo $at_carrier; ?>" selected>Carrier #<?php echo $at_carrier; ?> (saved)</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div style="display:flex;gap:6px;">
                    <button class="button button-secondary button-small"
                            onclick="wcotlDetectCarriers()">🔍 Detect carrier</button>
                    <button class="button button-primary button-small"
                            onclick="wcotlSaveAutoTracking()">💾 Save</button>
                </div>
            </div>
            <!-- Carrier search panel (shown when auto-detect finds nothing) -->
            <div id="wcotl-manual-carrier-row" style="display:none;margin-top:8px;padding:12px 14px;background:#fff8e5;border:1px solid #f0c060;border-radius:4px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <p style="font-size:12px;margin:0;color:#7a5500;font-weight:600;">⚠ No carrier detected — search manually:</p>
                    <button class="button button-small" onclick="wcotlHideManualCarrier()" style="padding:0 6px;min-height:24px;line-height:22px;">✕</button>
                </div>
                <input type="text" id="wcotl-carrier-search"
                       placeholder="Type carrier name or code (e.g. UPS, 100002)…"
                       style="width:100%;box-sizing:border-box;margin-bottom:6px;"
                       autocomplete="off">
                <div id="wcotl-carrier-results"
                     style="max-height:200px;overflow-y:auto;border:1px solid #dcdcde;border-radius:3px;background:#fff;display:none;">
                </div>
                <p id="wcotl-carrier-search-status" style="font-size:11px;color:#888;margin:4px 0 0;"></p>
            </div>
            <div id="wcotl-at-message" style="font-size:12px;margin-top:4px;"></div>

            <script>
            var WCOTL_TRACKING_CODE = '<?php echo esc_js($code); ?>';

            function wcotlDetectCarriers() {
                var num = document.getElementById('wcotl-real-number').value.trim();
                if (!num) { alert('Please enter carrier tracking number first.'); return; }
                var msg = document.getElementById('wcotl-at-message');
                msg.style.color = '#888';
                msg.textContent = '⏳ Detecting...';
                document.getElementById('wcotl-manual-carrier-row').style.display = 'none';
                fetch(WCOTL_AJAX.url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'wcotl_detect_carriers',
                        nonce:  WCOTL_AJAX.nonce,
                        number: num
                    })
                }).then(r => r.json()).then(res => {
                    if (!res.success) { msg.style.color = '#c0392b'; msg.textContent = '❌ ' + (res.data || 'Error'); return; }
                    var carriers = res.data;
                    var sel = document.getElementById('wcotl-carrier-select');
                    sel.innerHTML = '<option value="0">— Auto-detect —</option>';
                    if (Object.keys(carriers).length === 0) {
                        // No carriers detected – show the manual input panel
                        msg.style.color = '#888';
                        msg.textContent = '';
                        document.getElementById('wcotl-manual-carrier-row').style.display = 'block';
                        var searchBox = document.getElementById('wcotl-carrier-search');
                        if (searchBox) searchBox.focus();
                    } else {
                        for (var code in carriers) {
                            var opt = document.createElement('option');
                            opt.value = code;
                            opt.textContent = carriers[code] + ' (' + code + ')';
                            sel.appendChild(opt);
                        }
                        sel.selectedIndex = 1;
                        msg.style.color = '#1e8449';
                        msg.textContent = '✓ ' + Object.keys(carriers).length + ' carrier(s) found. Select and save.';
                    }
                }).catch(e => { msg.style.color = '#c0392b'; msg.textContent = '❌ Network error.'; });
            }

            // ---- Carrier search (manual fallback) ----
            var _wcotlCarrierList  = null; // cached full list [{key, name, country}]
            var _wcotlCarrierFetch = null; // in-flight promise

            function wcotlLoadCarrierList() {
                if (_wcotlCarrierList) return Promise.resolve(_wcotlCarrierList);
                if (_wcotlCarrierFetch) return _wcotlCarrierFetch;
                var status = document.getElementById('wcotl-carrier-search-status');
                status.textContent = '⏳ Loading carrier list…';
                _wcotlCarrierFetch = fetch('https://res.17track.net/asset/carrier/info/apicarrier.all.json', { cache: 'force-cache' })
                    .then(r => r.json())
                    .then(data => {
                        // Normalise raw fields: _name → name, _country_iso → country
                        _wcotlCarrierList = data.map(function(c) {
                            return { key: c.key, name: c._name || '', country: c._country_iso || '' };
                        });
                        status.textContent = _wcotlCarrierList.length + ' carriers loaded. Type to search.';
                        return _wcotlCarrierList;
                    })
                    .catch(() => {
                        status.textContent = '❌ Could not load carrier list.';
                        return [];
                    });
                return _wcotlCarrierFetch;
            }

            function wcotlRenderCarrierResults(list) {
                var box = document.getElementById('wcotl-carrier-results');
                box.innerHTML = '';
                if (list.length === 0) {
                    box.style.display = 'none';
                    return;
                }
                list.slice(0, 60).forEach(function(c) {
                    var row = document.createElement('div');
                    row.style.cssText = 'padding:6px 10px;cursor:pointer;border-bottom:1px solid #f0f0f1;font-size:12px;display:flex;justify-content:space-between;align-items:center;';
                    row.innerHTML = '<span><strong>' + c.name + '</strong> <span style="color:#888;">– ' + (c.country || '') + '</span></span>'
                                  + '<code style="font-size:11px;background:#f0f0f1;padding:1px 5px;border-radius:3px;">#' + c.key + '</code>';
                    row.addEventListener('mouseover', function() { this.style.background = '#f0f7ff'; });
                    row.addEventListener('mouseout',  function() { this.style.background = ''; });
                    row.addEventListener('mousedown', function(e) {
                        e.preventDefault(); // don't blur the search input before click fires
                        wcotlSelectCarrierFromSearch(c.key, c.name);
                    });
                    box.appendChild(row);
                });
                if (list.length > 60) {
                    var more = document.createElement('div');
                    more.style.cssText = 'padding:5px 10px;font-size:11px;color:#888;text-align:center;';
                    more.textContent = '+ ' + (list.length - 60) + ' more – keep typing to narrow down.';
                    box.appendChild(more);
                }
                box.style.display = 'block';
            }

            function wcotlSelectCarrierFromSearch(key, name) {
                var sel = document.getElementById('wcotl-carrier-select');
                // Remove any previous manual option
                var existing = sel.querySelector('option[data-manual]');
                if (existing) existing.remove();
                var opt = document.createElement('option');
                opt.value    = key;
                opt.textContent = name + ' (#' + key + ')';
                opt.setAttribute('data-manual', '1');
                opt.selected = true;
                sel.appendChild(opt);
                // Close the panel
                document.getElementById('wcotl-manual-carrier-row').style.display = 'none';
                document.getElementById('wcotl-carrier-results').style.display = 'none';
                document.getElementById('wcotl-carrier-search').value = '';
                var msg = document.getElementById('wcotl-at-message');
                msg.style.color = '#1a5fa8';
                msg.textContent = '✓ ' + name + ' (#' + key + ') selected. Click Save to confirm.';
            }

            function wcotlHideManualCarrier() {
                document.getElementById('wcotl-manual-carrier-row').style.display = 'none';
                document.getElementById('wcotl-carrier-results').style.display = 'none';
                document.getElementById('wcotl-carrier-search').value = '';
                document.getElementById('wcotl-at-message').textContent = '';
            }

            // Wire up the search input once the DOM is ready
            document.addEventListener('DOMContentLoaded', function() {
                var searchInput = document.getElementById('wcotl-carrier-search');
                var resultsBox  = document.getElementById('wcotl-carrier-results');
                var debounce;
                searchInput.addEventListener('input', function() {
                    var q = this.value.trim().toLowerCase();
                    clearTimeout(debounce);
                    if (!q) { resultsBox.style.display = 'none'; return; }
                    debounce = setTimeout(function() {
                        wcotlLoadCarrierList().then(function(list) {
                            var filtered = list.filter(function(c) {
                                return c.name.toLowerCase().indexOf(q) !== -1
                                    || String(c.key).indexOf(q) !== -1
                                    || (c.country && c.country.toLowerCase().indexOf(q) !== -1);
                            });
                            wcotlRenderCarrierResults(filtered);
                            document.getElementById('wcotl-carrier-search-status').textContent =
                                filtered.length === 0 ? 'No carriers found.' : filtered.length + ' result(s).';
                        });
                    }, 180);
                });
                searchInput.addEventListener('focus', function() {
                    // Pre-load carrier list on first focus so it's ready instantly
                    wcotlLoadCarrierList();
                });
                searchInput.addEventListener('blur', function() {
                    // Small delay so mousedown on a result fires first
                    setTimeout(function() { resultsBox.style.display = 'none'; }, 200);
                });
            });

            function wcotlSaveAutoTracking() {
                var num     = document.getElementById('wcotl-real-number').value.trim();
                var carrier = document.getElementById('wcotl-carrier-select').value;
                var msg     = document.getElementById('wcotl-at-message');
                msg.textContent = '⏳ Saving...';
                fetch(WCOTL_AJAX.url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action:        'wcotl_save_auto_tracking',
                        nonce:         WCOTL_AJAX.nonce,
                        tracking_code: WCOTL_TRACKING_CODE,
                        real_number:   num,
                        carrier_code:  carrier
                    })
                }).then(r => r.json()).then(res => {
                    if (!res.success) { msg.style.color='#c0392b'; msg.textContent = '❌ ' + (res.data || 'Error'); return; }
                    msg.style.color = '#1e8449';
                    msg.textContent = '✓ Saved! First sync will occur on next cron run (or click "Sync now").';
                    setTimeout(() => location.reload(), 1800);
                }).catch(e => { msg.textContent = '❌ Network error.'; });
            }

            function wcotlSyncNow() {
                var btn = document.getElementById('wcotl-sync-now-btn');
                if (btn) btn.disabled = true;
                var msg = document.getElementById('wcotl-at-message');
                msg.style.color = '#888';
                msg.textContent = '⏳ Syncing...';
                fetch(WCOTL_AJAX.url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action:        'wcotl_sync_now',
                        nonce:         WCOTL_AJAX.nonce,
                        tracking_code: WCOTL_TRACKING_CODE
                    })
                }).then(r => r.json()).then(res => {
                    if (!res.success) { msg.style.color='#c0392b'; msg.textContent = '❌ ' + (res.data || 'Error'); if(btn)btn.disabled=false; return; }
                    msg.style.color = '#1e8449';
                    msg.textContent = '✓ Sync complete! Status: ' + (res.data.status || '?');
                    setTimeout(() => location.reload(), 1800);
                }).catch(e => { msg.textContent = '❌ Network error.'; if(btn)btn.disabled=false; });
            }

            function wcotlResumeSync() {
                var msg = document.getElementById('wcotl-at-message');
                msg.textContent = '⏳ Resuming...';
                fetch(WCOTL_AJAX.url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action:        'wcotl_resume_sync',
                        nonce:         WCOTL_AJAX.nonce,
                        tracking_code: WCOTL_TRACKING_CODE
                    })
                }).then(r => r.json()).then(res => {
                    if (!res.success) { msg.style.color='#c0392b'; msg.textContent = '❌ ' + (res.data || 'Error'); return; }
                    msg.style.color = '#1e8449';
                    msg.textContent = '✓ Sync resumed!';
                    setTimeout(() => location.reload(), 1000);
                }).catch(e => { msg.textContent = '❌ Network error.'; });
            }
            </script>

            <?php endif; // provider_active ?>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">

            <!-- Existing steps list -->
            <div class="card" style="max-width:none;">
                <h2>Timeline Steps (<?php echo count($steps); ?>)</h2>
                <?php if ( empty($steps) ) : ?>
                    <p style="color:#646970;">No steps yet. Add one</p>
                <?php else : ?>
                <ul class="wcotl-steps-list">
                    <?php foreach ( $steps as $s ) :
                        $dt  = new DateTime($s->step_date);
                        $del = wp_nonce_url(
                            admin_url('admin.php?page=wcotl-tracking&view=' . urlencode($code) . '&action=delete_step&step_id=' . $s->id),
                            'wcotl_delete_step'
                        );
                        $edit_nonce = wp_create_nonce( 'wcotl_edit_step_' . $s->id );
                        $void_nonce = wp_nonce_url(
                            admin_url('admin.php?page=wcotl-tracking&view=' . urlencode($code) . '&action=void_step&step_id=' . $s->id),
                            'wcotl_void_step_' . $s->id
                        );
                        $unvoid_nonce = wp_nonce_url(
                            admin_url('admin.php?page=wcotl-tracking&view=' . urlencode($code) . '&action=unvoid_step&step_id=' . $s->id),
                            'wcotl_void_step_' . $s->id
                        );
                        $is_voided = ! empty( $s->step_voided );
                        $void_reason_nonce = wp_create_nonce( 'wcotl_void_reason_' . $s->id );
                    ?>
                    <li style="flex-direction:column;align-items:stretch;<?php echo $is_voided ? 'opacity:.75;' : ''; ?>">
                        <!-- Normal view -->
                        <div class="wcotl-step-row" id="wcotl-view-<?php echo $s->id; ?>" style="display:flex;gap:12px;align-items:flex-start;">
                            <div class="wcotl-step-meta" style="flex:1;">
                                <strong style="<?php echo $is_voided ? 'text-decoration:line-through;color:#a09a94;' : ''; ?>"><?php echo esc_html($s->step_label); ?></strong>
                                <?php $src = isset($s->step_source) ? $s->step_source : 'manual'; ?>
                                <span class="wcotl-source-badge <?php echo $src === 'auto' ? 'wcotl-source-auto' : 'wcotl-source-manual'; ?>">
                                    <?php echo $src === 'auto' ? '🛰 auto' : '✍ manual'; ?>
                                </span>
                                <small style="display:block;color:#646970;font-size:12px;"><?php echo esc_html($dt->format('d/m/Y H:i')); ?> &nbsp;·&nbsp; <?php echo esc_html($s->step_icon); ?></small>
                                <?php if ($s->step_note) : ?>
                                    <p style="font-size:12px;color:#646970;margin:4px 0 0;font-style:italic;"><?php echo esc_html($s->step_note); ?></p>
                                <?php endif; ?>
                                <?php if ( $is_voided ) : ?>
                                    <p style="font-size:11px;color:#d63638;margin:4px 0 0;font-weight:600;text-transform:uppercase;">
                                        ⊘ Unconfirmed<?php if ( $s->step_void_reason ) : ?> — <em style="font-weight:normal;font-style:italic;"><?php echo esc_html($s->step_void_reason); ?></em><?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end;">
                                <?php if ( ! $is_voided ) : ?>
                                    <button type="button"
                                            class="button button-secondary button-small"
                                            onclick="wcotlToggleEdit(<?php echo $s->id; ?>)" title="Edit">✎</button>
                                    <button type="button"
                                            class="button button-small"
                                            onclick="wcotlToggleVoid(<?php echo $s->id; ?>)" title="Mark as unconfirmed">⊘</button>
                                <?php else : ?>
                                    <a href="<?php echo esc_url($unvoid_nonce); ?>"
                                       class="button button-small"
                                       title="Restore step">↩ Restore</a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url($del); ?>"
                                   class="button button-link-delete button-small"
                                   onclick="return confirm('Delete this step?')" title="Delete">✕</a>
                            </div>
                        </div>

                        <!-- Inline edit form -->
                        <div class="wcotl-edit-form" id="wcotl-edit-<?php echo $s->id; ?>" style="display:none;margin-top:12px;background:#faf9f7;border:1.5px solid #c8963e;border-radius:8px;padding:16px;">
                            <p style="font-size:12px;font-weight:600;color:#c8963e;margin-bottom:12px;text-transform:uppercase;letter-spacing:.06em;">Edit step</p>
                            <form method="POST">
                                <input type="hidden" name="wcotl_edit_step" value="1">
                                <input type="hidden" name="_wpnonce_edit" value="<?php echo esc_attr($edit_nonce); ?>">
                                <input type="hidden" name="step_id" value="<?php echo $s->id; ?>">
                                <div class="wcotl-form-row">
                                    <label>Date and Time <span style="color:#c0392b">*</span></label>
                                    <input type="datetime-local" name="step_date"
                                           value="<?php echo esc_attr($dt->format('Y-m-d\TH:i')); ?>" required>
                                </div>
                                <div class="wcotl-form-row">
                                    <label>Step description <span style="color:#c0392b">*</span></label>
                                    <input type="text" name="step_label"
                                           value="<?php echo esc_attr($s->step_label); ?>" required>
                                </div>
                                <div class="wcotl-form-row">
                                    <label>Additional note (optional)</label>
                                    <textarea name="step_note"><?php echo esc_textarea($s->step_note); ?></textarea>
                                </div>
                                <div class="wcotl-form-row">
                                    <label>Icon</label>
                                    <select name="step_icon">
                                        <?php foreach ( array_keys($icons) as $k ) : ?>
                                            <option value="<?php echo esc_attr($k); ?>" <?php selected($k, $s->step_icon); ?>>
                                                <?php echo esc_html(ucfirst(str_replace('_',' ',$k))); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="display:flex;gap:8px;">
                                    <button type="submit" class="button button-primary button-small">Save changes</button>
                                    <button type="button" class="button button-secondary button-small"
                                            onclick="wcotlToggleEdit(<?php echo $s->id; ?>)">Cancel</button>
                                </div>
                            </form>
                        </div>

                        <!-- Step voiding form (reason) -->
                        <div id="wcotl-void-<?php echo $s->id; ?>" style="display:none;margin-top:12px;background:#fcf0f1;border:1px solid #f5c2c7;border-radius:4px;padding:12px;">
                            <p style="font-size:12px;font-weight:600;color:#d63638;margin:0 0 10px;text-transform:uppercase;">⊘ Mark as unconfirmed</p>
                            <form method="POST">
                                <input type="hidden" name="wcotl_save_void_reason" value="1">
                                <input type="hidden" name="_wpnonce_void" value="<?php echo esc_attr($void_reason_nonce); ?>">
                                <input type="hidden" name="step_id" value="<?php echo $s->id; ?>">
                                <div class="wcotl-form-row">
                                    <label>Reason (visible to customer, optional)</label>
                                    <textarea name="step_void_reason" placeholder="e.g. Incorrect information provided by carrier. Shipment is still in transit."><?php echo esc_textarea( $s->step_void_reason ?? '' ); ?></textarea>
                                </div>
                                <div style="display:flex;gap:8px;">
                                    <button type="submit" class="button button-link-delete button-small"><?php esc_html_e( 'Confirm cancellation', 'wc-order-timeline' ); ?></button>
                                    <button type="button" class="button button-secondary button-small"
                                            onclick="wcotlToggleVoid(<?php echo $s->id; ?>)"><?php esc_html_e( 'Cancel', 'wc-order-timeline' ); ?></button>
                                </div>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <script>
                function wcotlToggleEdit(id) {
                    var view = document.getElementById('wcotl-view-' + id);
                    var form = document.getElementById('wcotl-edit-' + id);
                    var isOpen = form.style.display !== 'none';
                    form.style.display = isOpen ? 'none' : 'block';
                    view.style.opacity = isOpen ? '1' : '0.4';
                    var voidForm = document.getElementById('wcotl-void-' + id);
                    if (voidForm) voidForm.style.display = 'none';
                }
                function wcotlToggleVoid(id) {
                    var voidForm = document.getElementById('wcotl-void-' + id);
                    var isOpen = voidForm.style.display !== 'none';
                    voidForm.style.display = isOpen ? 'none' : 'block';
                    var editForm = document.getElementById('wcotl-edit-' + id);
                    if (editForm) editForm.style.display = 'none';
                    var view = document.getElementById('wcotl-view-' + id);
                    if (view) view.style.opacity = isOpen ? '1' : '0.5';
                }
                </script>
                <?php endif; ?>
            </div>

            <!-- Add step form -->
            <div class="card" style="max-width:none;">
                <h2>Add a step</h2>
                <form method="POST">
                    <?php wp_nonce_field('wcotl_add_step'); ?>
                    <input type="hidden" name="wcotl_add_step" value="1">
                    <input type="hidden" name="tracking_code" value="<?php echo esc_attr($code); ?>">

                    <?php $presets = WCOTL_DB::get_presets(); if ( ! empty( $presets ) ) : ?>
                    <div class="wcotl-form-row" style="background:#fcf9e8;border:1px solid #f0c060;border-radius:4px;padding:10px 14px;margin-bottom:16px;">
                        <label style="color:#9a6e1a;">⚡ Use a preset</label>
                        <select id="wcotl-preset-select" style="background:#fff;">
                            <option value="">— select preset —</option>
                            <?php foreach ( $presets as $p ) : ?>
                                <option value="<?php echo absint($p->id); ?>"
                                        data-label="<?php echo esc_attr($p->step_label); ?>"
                                        data-note="<?php echo esc_attr($p->step_note); ?>"
                                        data-icon="<?php echo esc_attr($p->step_icon); ?>">
                                    <?php echo esc_html($p->preset_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="font-size:11px;color:#646970;display:block;margin-top:4px;">Pre-fills fields below. You can edit them before saving.</small>
                    </div>
                    <script>
                    document.getElementById('wcotl-preset-select').addEventListener('change', function() {
                        var sel = this.options[this.selectedIndex];
                        if (!sel.value) return;
                        var form = this.closest('form');
                        form.querySelector('[name="step_label"]').value = sel.dataset.label || '';
                        form.querySelector('[name="step_note"]').value  = sel.dataset.note  || '';
                        var iconSel = form.querySelector('[name="step_icon"]');
                        if (iconSel && sel.dataset.icon) {
                            for (var i = 0; i < iconSel.options.length; i++) {
                                if (iconSel.options[i].value === sel.dataset.icon) {
                                    iconSel.selectedIndex = i; break;
                                }
                            }
                        }
                    });
                    </script>
                    <?php endif; ?>


                    <div class="wcotl-form-row">
                        <label>Date and Time <span style="color:#d63638">*</span></label>
                        <input type="datetime-local" name="step_date" value="<?php echo esc_attr( date('Y-m-d\TH:i') ); ?>" required>
                    </div>
                    <div class="wcotl-form-row">
                        <label>Step description <span style="color:#d63638">*</span></label>
                        <input type="text" name="step_label" placeholder="e.g. Goods loaded in Milan" required>
                    </div>
                    <div class="wcotl-form-row">
                        <label>Additional note (optional)</label>
                        <textarea name="step_note" placeholder="Extra details, customer info..."></textarea>
                    </div>
                    <div class="wcotl-form-row">
                        <label>Icon</label>
                        <select name="step_icon">
                            <?php foreach ( array_keys($icons) as $k ) : ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html(ucfirst(str_replace('_',' ',$k))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="button button-primary" style="width:100%;">Add Step</button>
                </form>
            </div>

        </div>
        <?php
    }

    public static function page_new_code() {
        $notice = '';

        if ( isset( $_POST['wcotl_create_code'], $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'wcotl_create_code' ) ) {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'wc-order-timeline' ), 403 );
            }
            $code = strtoupper( sanitize_text_field( wp_unslash( $_POST['tracking_code'] ?? '' ) ) );
            $code = preg_replace('/[^A-Z0-9\-_]/', '', $code);

            if ( strlen($code) >= 3 ) {
                $order_id_from_post = absint( $_POST['order_id'] ?? 0 );
                if ( $order_id_from_post ) {
                    WCOTL_DB::set_meta( $code, 'order_id', $order_id_from_post );
                }
                wp_redirect( admin_url('admin.php?page=wcotl-tracking&view=' . urlencode($code) ) );
                exit;
            } else {
                $notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please enter a valid code (minimum 3 characters, letters, numbers, hyphens only).', 'wc-order-timeline' ) . '</p></div>';
            }
        }

        $suggested  = 'TRK-' . date('Ymd') . '-' . strtoupper( wp_generate_password(4, false, false) );
        $order_id   = absint( $_GET['order_id'] ?? 0 );
        ?>
        <div class="wrap wcotl-admin">
            <h1>New Tracking Code</h1>
            <?php echo wp_kses_post( $notice ); ?>
            <div class="card" style="max-width:480px;">
                <h2>Create a new code</h2>
                <p style="font-size:13px;color:#646970;margin-bottom:16px;">This code will be shared with the customer. Any format is allowed.</p>
                <form method="POST">
                    <?php wp_nonce_field('wcotl_create_code'); ?>
                    <input type="hidden" name="wcotl_create_code" value="1">
                    <?php if ( $order_id ) : ?>
                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                    <?php endif; ?>
                    <div class="wcotl-form-row">
                        <label>Tracking code</label>
                        <input type="text" name="tracking_code" value="<?php echo esc_attr($suggested); ?>"
                               placeholder="e.g. TRK-20240518-001" required style="font-family:monospace;">
                    </div>
                    <?php if ( $order_id ) : ?>
                        <p style="font-size:12px;color:#008a20;margin-bottom:16px;">✓ Will be automatically associated with order <strong>#<?php echo $order_id; ?></strong>.</p>
                    <?php endif; ?>
                    <button type="submit" class="button button-primary" style="width:100%;">Create & start adding steps</button>
                </form>
            </div>
        </div>
        <?php
    }

}
