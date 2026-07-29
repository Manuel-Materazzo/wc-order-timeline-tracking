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
            'Tutti i Codici',
            'Tutti i Codici',
            'manage_woocommerce',
            'wcotl-tracking',
            array( 'WCOTL_Admin', 'page_list' )
        );
        add_submenu_page(
            'wcotl-tracking',
            'Nuovo Codice',
            '+ Nuovo Codice',
            'manage_woocommerce',
            'wcotl-new-code',
            array( 'WCOTL_Admin', 'page_new_code' )
        );
        add_submenu_page(
            'wcotl-tracking',
            'Preset Step',
            'Preset Steps',
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
        .wcotl-table { width:100%; border-collapse:collapse; background:#fff; box-shadow:0 1px 4px rgba(0,0,0,.08); border-radius:8px; overflow:hidden; }
        .wcotl-table th { background:#1a1a2e; color:#fff; padding:10px 14px; text-align:left; font-size:12px; letter-spacing:.06em; text-transform:uppercase; }
        .wcotl-table td { padding:10px 14px; border-bottom:1px solid #f0ede8; font-size:13px; vertical-align:top; }
        .wcotl-table tr:last-child td { border-bottom:none; }
        .wcotl-table tr:hover td { background:#faf9f7; }
        .wcotl-code-badge { font-family:monospace; background:#f0ede8; padding:2px 8px; border-radius:4px; font-size:13px; letter-spacing:.06em; }
        .wcotl-btn { display:inline-block; padding:6px 14px; border-radius:6px; font-size:12px; cursor:pointer; text-decoration:none; border:none; }
        .wcotl-btn-primary   { background:#1a1a2e; color:#fff; }
        .wcotl-btn-primary:hover { background:#c8963e; color:#fff; }
        .wcotl-btn-secondary { background:#f0ede8; color:#2d2d2d; }
        .wcotl-btn-secondary:hover { background:#e2ddd8; }
        .wcotl-btn-danger    { background:#c0392b; color:#fff; }
        .wcotl-btn-danger:hover { background:#96281b; }
        .wcotl-btn-sm { padding:4px 10px; font-size:11px; }
        .wcotl-card { background:#fff; border:1px solid #e2ddd8; border-radius:10px; padding:24px 28px; margin-bottom:24px; }
        .wcotl-card h2 { margin:0 0 16px; font-size:15px; color:#1a1a2e; }
        .wcotl-form-row { display:flex; flex-direction:column; gap:6px; margin-bottom:16px; }
        .wcotl-form-row label { font-size:12px; letter-spacing:.06em; text-transform:uppercase; color:#888; }
        .wcotl-form-row input[type="text"],
        .wcotl-form-row input[type="datetime-local"],
        .wcotl-form-row select,
        .wcotl-form-row textarea { width:100%; padding:9px 12px; border:1.5px solid #e2ddd8; border-radius:7px; font-size:14px; color:#2d2d2d; background:#faf9f7; }
        .wcotl-form-row textarea { min-height:80px; resize:vertical; }
        .wcotl-form-row input:focus,
        .wcotl-form-row select:focus,
        .wcotl-form-row textarea:focus { border-color:#c8963e; outline:none; }
        .wcotl-steps-list { list-style:none; padding:0; }
        .wcotl-steps-list li { display:flex; gap:12px; align-items:flex-start; padding:10px 0; border-bottom:1px solid #f0ede8; }
        .wcotl-steps-list li:last-child { border-bottom:none; }
        .wcotl-step-meta { flex:1; }
        .wcotl-step-meta strong { display:block; font-size:14px; }
        .wcotl-step-meta small { color:#888; font-size:12px; }
        .wcotl-notice { padding:10px 16px; border-radius:7px; margin-bottom:16px; font-size:13px; }
        .wcotl-notice-success { background:#eafaf1; border:1px solid #a9dfbf; color:#1e8449; }
        .wcotl-notice-error   { background:#fdf2f0; border:1px solid #f5b7b1; color:#c0392b; }
        .wcotl-actions-row { display:flex; gap:8px; flex-wrap:wrap; }
        /* Tooltip container */
        .tooltip {
            position: relative;
            display: inline-block;
            cursor: pointer;
        }
        /* Tooltip text */
        .tooltiptext {
            visibility: hidden; /* Hidden by default */
            width: 500px;
            font-size: 12px;
            background-color: black;
            color: #ffffff;
            text-align: center;
            padding: 5px;
            border-radius: 6px;
            position: absolute;
            z-index: 1;
        }
        /* Show the tooltip text on hover */
        .tooltip:hover .tooltiptext {
            visibility: visible;
        }
        .wcotl-autotrack-card { background: linear-gradient(135deg,#f0f7ff 0%,#e8f4fd 100%); border:1.5px solid #b8d4f8; }
        .wcotl-autotrack-card h2 { color:#1a5fa8; }
        .wcotl-source-badge { display:inline-block; font-size:10px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; padding:2px 7px; border-radius:4px; margin-left:6px; vertical-align:middle; }
        .wcotl-source-auto   { background:#e8f4fd; color:#1a5fa8; border:1px solid #b8d4f8; }
        .wcotl-source-manual { background:#f0ede8; color:#6b6158; border:1px solid #e2ddd8; }
        .wcotl-sync-status { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; padding:4px 10px; border-radius:6px; }
        .wcotl-sync-active  { background:#eafaf1; color:#1e8449; border:1px solid #a9dfbf; }
        .wcotl-sync-stopped { background:#fdf2f0; color:#c0392b; border:1px solid #f5b7b1; }
        .wcotl-sync-pending { background:#fff8e8; color:#9a6e1a; border:1px solid #f0c060; }
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

        // Gestione eliminazione step
        if (
            isset( $_GET['action'], $_GET['step_id'], $_GET['_wpnonce'] ) &&
            $_GET['action'] === 'delete_step' &&
            wp_verify_nonce( $_GET['_wpnonce'], 'wcotl_delete_step' )
        ) {
            $wpdb->delete( $table, [ 'id' => absint( $_GET['step_id'] ) ] );
            echo '<div class="wcotl-notice wcotl-notice-success">Step eliminato.</div>';
        }

        // Gestione annullamento step (toggle void)
        if (
            isset( $_GET['action'], $_GET['step_id'], $_GET['_wpnonce'] ) &&
            in_array( $_GET['action'], [ 'void_step', 'unvoid_step' ], true ) &&
            wp_verify_nonce( $_GET['_wpnonce'], 'wcotl_void_step_' . absint( $_GET['step_id'] ) )
        ) {
            $step_id = absint( $_GET['step_id'] );
            if ( $_GET['action'] === 'void_step' ) {
                $wpdb->update( $table, [ 'step_voided' => 1 ], [ 'id' => $step_id ] );
                echo '<div class="wcotl-notice wcotl-notice-success">Step contrassegnato come non confermato.</div>';
            } else {
                $wpdb->update( $table, [ 'step_voided' => 0, 'step_void_reason' => null ], [ 'id' => $step_id ] );
                echo '<div class="wcotl-notice wcotl-notice-success">Step ripristinato.</div>';
            }
        }

        // Gestione modifica step (POST)
        if (
            isset( $_POST['wcotl_edit_step'], $_POST['_wpnonce_edit'], $_POST['step_id'] ) &&
            wp_verify_nonce( $_POST['_wpnonce_edit'], 'wcotl_edit_step_' . absint( $_POST['step_id'] ) )
        ) {
            $step_id = absint( $_POST['step_id'] );
            $label   = sanitize_text_field( $_POST['step_label'] ?? '' );
            $note    = sanitize_textarea_field( $_POST['step_note'] ?? '' );
            $date    = sanitize_text_field( $_POST['step_date'] ?? '' );
            $icon    = sanitize_key( $_POST['step_icon'] ?? 'truck' );

            if ( $label && $date ) {
                $dt = DateTime::createFromFormat( 'Y-m-d\TH:i', $date );
                if ( ! $dt ) $dt = new DateTime( $date );
                $wpdb->update( $table, [
                    'step_date'  => $dt->format('Y-m-d H:i:s'),
                    'step_label' => $label,
                    'step_note'  => $note,
                    'step_icon'  => $icon,
                ], [ 'id' => $step_id ] );
                echo '<div class="wcotl-notice wcotl-notice-success">Step modificato con successo!</div>';
            } else {
                echo '<div class="wcotl-notice wcotl-notice-error">Compila almeno Data/Ora e Descrizione.</div>';
            }
        }

        // Gestione eliminazione codice (tutti gli step)
        if (
            isset( $_GET['action'], $_GET['tracking_code'], $_GET['_wpnonce'] ) &&
            $_GET['action'] === 'delete_code' &&
            wp_verify_nonce( $_GET['_wpnonce'], 'wcotl_delete_code' )
        ) {
            $wpdb->delete( $table, [ 'tracking_code' => sanitize_text_field( $_GET['tracking_code'] ) ] );
            echo '<div class="wcotl-notice wcotl-notice-success">Codice eliminato.</div>';
        }

        // Recupera tutti i codici distinti
        $codes = $wpdb->get_results(
            "SELECT tracking_code, order_id, COUNT(*) as steps, MAX(step_date) as last_update
             FROM {$table}
             GROUP BY tracking_code, order_id
             ORDER BY last_update DESC"
        );

        $view_code = isset( $_GET['view'] ) ? sanitize_text_field( $_GET['view'] ) : '';
        ?>
        <div class="wrap wcotl-admin">
            <h1>
                <span class="dashicons dashicons-location-alt"></span>
                Timeline Tracking
                <a href="<?php echo admin_url('admin.php?page=wcotl-new-code'); ?>" class="wcotl-btn wcotl-btn-primary" style="font-size:13px;margin-left:8px;">+ Nuovo Codice</a>
            </h1>

            <?php if ( $view_code ) :
                WCOTL_Admin::page_view_code( $view_code );
            else : ?>

            <?php if ( empty( $codes ) ) : ?>
                <div class="wcotl-card"><p>Nessun codice di tracciamento trovato. <a href="<?php echo admin_url('admin.php?page=wcotl-new-code'); ?>">Crea il primo →</a></p></div>
            <?php else : ?>
                <table class="wcotl-table">
                    <thead>
                        <tr>
                            <th>Codice Tracking</th>
                            <th>Ordine WC</th>
                            <th>Step</th>
                            <th>Ultimo aggiornamento</th>
                            <th>Azioni</th>
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
                                <a href="<?php echo esc_url( $view_url ); ?>" class="wcotl-btn wcotl-btn-secondary wcotl-btn-sm">Gestisci</a>
                                <a href="<?php echo esc_url( $delete_url ); ?>" class="wcotl-btn wcotl-btn-danger wcotl-btn-sm"
                                   onclick="return confirm('Eliminare tutti gli step di questo codice?')">Elimina</a>
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

        // Salva data di consegna stimata
        if ( isset( $_POST['wcotl_save_delivery'], $_POST['_wpnonce_delivery'] ) && wp_verify_nonce( $_POST['_wpnonce_delivery'], 'wcotl_save_delivery_' . $code ) ) {
            $delivery_date = sanitize_text_field( $_POST['estimated_delivery'] ?? '' );
            WCOTL_DB::set_meta( $code, 'estimated_delivery', $delivery_date );
            $notice = '<div class="wcotl-notice wcotl-notice-success">Data di consegna stimata aggiornata.</div>';
        }

        // Salva data di consegna effettiva
        if ( isset( $_POST['wcotl_save_delivered'], $_POST['_wpnonce_delivered'] ) && wp_verify_nonce( $_POST['_wpnonce_delivered'], 'wcotl_save_delivered_' . $code ) ) {
            $delivered_date = sanitize_text_field( $_POST['delivered_at'] ?? '' );
            WCOTL_DB::set_meta( $code, 'delivered_at', $delivered_date );
            $notice = '<div class="wcotl-notice wcotl-notice-success">Data di consegna effettiva aggiornata.</div>';
        }

        // Salva motivo annullamento step
        if ( isset( $_POST['wcotl_save_void_reason'], $_POST['_wpnonce_void'], $_POST['step_id'] ) && wp_verify_nonce( $_POST['_wpnonce_void'], 'wcotl_void_reason_' . absint( $_POST['step_id'] ) ) ) {
            $step_id     = absint( $_POST['step_id'] );
            $void_reason = sanitize_textarea_field( wp_unslash( $_POST['step_void_reason'] ?? '' ));
            $wpdb->update( $table, [
                'step_voided'      => 1,
                'step_void_reason' => $void_reason,
            ], [ 'id' => $step_id ] );
            $notice = '<div class="wcotl-notice wcotl-notice-success">Step contrassegnato come non confermato.</div>';
        }

        // Ripristina step annullato
        if ( isset( $_GET['action'], $_GET['step_id'], $_GET['_wpnonce'] ) &&
            $_GET['action'] === 'unvoid_step' &&
            wp_verify_nonce( $_GET['_wpnonce'], 'wcotl_void_step_' . absint( $_GET['step_id'] ) ) ) {
            $step_id = absint( $_GET['step_id'] );
            $wpdb->update( $table, [ 'step_voided' => 0, 'step_void_reason' => null ], [ 'id' => $step_id ] );
            $notice = '<div class="wcotl-notice wcotl-notice-success">Step ripristinato.</div>';
        }

        // Salva modifica step
        if ( isset( $_POST['wcotl_edit_step'], $_POST['_wpnonce_edit'], $_POST['step_id'] ) && wp_verify_nonce( $_POST['_wpnonce_edit'], 'wcotl_edit_step_' . absint( $_POST['step_id'] ) ) ) {
            $step_id = absint( $_POST['step_id'] );
            $label   = sanitize_text_field( wp_unslash( $_POST['step_label'] ?? '' ));
            $note    = sanitize_textarea_field( wp_unslash($_POST['step_note'] ?? ''));
            $date    = sanitize_text_field( wp_unslash( $_POST['step_date'] ?? '' ));
            $icon    = sanitize_key( wp_unslash( $_POST['step_icon'] ?? 'truck' ));

            if ( $label && $date ) {
                $dt = DateTime::createFromFormat( 'Y-m-d\TH:i', $date );
                if ( ! $dt ) $dt = new DateTime( $date );
                $wpdb->update( $table, [
                    'step_date'  => $dt->format('Y-m-d H:i:s'),
                    'step_label' => $label,
                    'step_note'  => $note,
                    'step_icon'  => $icon,
                ], [ 'id' => $step_id ] );
                $notice = '<div class="wcotl-notice wcotl-notice-success">Step modificato con successo!</div>';
            } else {
                $notice = '<div class="wcotl-notice wcotl-notice-error">Compila almeno Data/Ora e Descrizione.</div>';
            }
        }

        // Aggiorna ordine WooCommerce associato al codice
        if ( isset( $_POST['wcotl_save_order_id'], $_POST['_wpnonce_order_id'] ) && wp_verify_nonce( $_POST['_wpnonce_order_id'], 'wcotl_save_order_id_' . $code ) ) {
            $new_order_id = absint( $_POST['order_id'] ?? 0 );
            if ( $new_order_id ) {
                WCOTL_DB::set_meta( $code, 'order_id', $new_order_id );
                // Aggiorna tutti gli step esistenti con il nuovo order_id
                $wpdb->update( $table, [ 'order_id' => $new_order_id ], [ 'tracking_code' => $code ] );
            } else {
                WCOTL_DB::set_meta( $code, 'order_id', '' );
                $wpdb->update( $table, [ 'order_id' => 0 ], [ 'tracking_code' => $code ] );
            }
            $notice = '<div class="wcotl-notice wcotl-notice-success">Ordine aggiornato.</div>';
        }

        // Salva nuovo step
        if ( isset( $_POST['wcotl_add_step'], $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'wcotl_add_step' ) ) {
            $label = sanitize_text_field( wp_unslash( $_POST['step_label'] ?? '' ));
            $note  = sanitize_textarea_field( wp_unslash( $_POST['step_note'] ?? '' ));
            $date  = sanitize_text_field( wp_unslash( $_POST['step_date'] ?? '' ));
            $icon  = sanitize_key( wp_unslash( $_POST['step_icon'] ?? 'truck' ));

            if ( $label && $date ) {
                // Converte da datetime-local (Y-m-dTH:i) a MySQL datetime
                $dt = DateTime::createFromFormat( 'Y-m-d\TH:i', $date );
                if ( ! $dt ) $dt = new DateTime( $date );

                // Usa order_id dai meta del codice
                $stored_order_id = absint( WCOTL_DB::get_meta( $code, 'order_id' ) );

                $wpdb->insert( $table, [
                    'tracking_code' => $code,
                    'order_id'      => $stored_order_id,
                    'step_date'     => $dt->format('Y-m-d H:i:s'),
                    'step_label'    => $label,
                    'step_note'     => $note,
                    'step_icon'     => $icon,
                ] );
                $notice = '<div class="wcotl-notice wcotl-notice-success">Step aggiunto con successo!</div>';
            } else {
                $notice = '<div class="wcotl-notice wcotl-notice-error">Compila almeno Data/Ora e Descrizione.</div>';
            }
        }

        $steps = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE tracking_code = %s ORDER BY step_date ASC", $code )
        );

        // Leggi order_id dai meta; se non esiste ancora, prova a ricavarlo dagli step (retrocompatibilità)
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
    		<a href="<?php echo admin_url('admin.php?page=wcotl-tracking'); ?>">← Torna alla lista</a>
    	</p>
    	
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">
    		<?php if ( $order_id ) : ?>
                <a href="<?php echo esc_url( admin_url('post.php?post=' . $order_id . '&action=edit') ); ?>" class="wcotl-btn wcotl-btn-secondary wcotl-btn-sm">Ordine #<?php echo $order_id; ?></a>
            <?php endif; ?>
    		<h2 style="margin:0;">
    			Codice: 
    			<span class="wcotl-code-badge">
    				<a target="_blank" href="<?php echo esc_url( add_query_arg( 'tracking', $code, home_url( '/order-tracking/' ) ) ); ?>">
    					<?php echo esc_html($code); ?>
    				</a>
    			</span>
    		</h2>
        </div>

        <!-- Ordine WooCommerce associato -->
        <div class="wcotl-card" style="margin-bottom:24px;">
            <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <?php wp_nonce_field( 'wcotl_save_order_id_' . $code, '_wpnonce_order_id' ); ?>
                <input type="hidden" name="wcotl_save_order_id" value="1">
                <div class="wcotl-form-row" style="margin-bottom:0;width:400px;">
                    <label>Numero ordine (ID WooCommerce)
                        <span class="dashicons dashicons-info tooltip">
                            <span class="tooltiptext">
                                L'ordine associato a questo codice di tracking, verrà usato automaticamente per tutti gli step.
                            </span>
                        </span>
                    </label>
                    <input type="text" name="order_id"
                           value="<?php echo $order_id ?: ''; ?>"
                           placeholder="es. 1042"
                           style="width:100%;padding:9px 12px;border:1.5px solid #e2ddd8;border-radius:7px;font-size:14px;color:#2d2d2d;background:#faf9f7;">
                </div>
                <button type="submit" class="wcotl-btn wcotl-btn-primary" style="padding:9px 18px;width:120px;font-size:13px;white-space:nowrap;">
                    Salva →
                </button>
                <?php if ( $order_id ) : ?>
                    <span style="font-size:12px;color:#1e8449;padding-bottom:10px;">
                        ✓ Ordine #<?php echo $order_id; ?>
                    </span>
                <?php else : ?>
                    <span style="font-size:12px;color:#888;padding-bottom:10px;">Nessun ordine associato</span>
                <?php endif; ?>
            </form>
            <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <?php wp_nonce_field( 'wcotl_save_delivery_' . $code, '_wpnonce_delivery' ); ?>
                <input type="hidden" name="wcotl_save_delivery" value="1">
                <div class="wcotl-form-row" style="margin-bottom:0;width:400px;">
                    <label>Data stimata (opzionale)
                        <span class="dashicons dashicons-info tooltip">
                            <span class="tooltiptext">
                                Visibile al cliente sulla pagina di tracking. Lascia il campo vuoto per nasconderla.
                            </span>
                        </span>
                    </label>
                    <input type="date" name="estimated_delivery"
                           value="<?php echo esc_attr( $estimated_delivery ?: '' ); ?>"
                           style="width:100%;padding:9px 12px;border:1.5px solid #e2ddd8;border-radius:7px;font-size:14px;color:#2d2d2d;background:#faf9f7;">
                </div>
                <button type="submit" class="wcotl-btn wcotl-btn-primary" style="padding:9px 18px;width:120px;font-size:13px;white-space:nowrap;">
                    Salva data →
                </button>
                <?php if ( $estimated_delivery ) : ?>
                    <span style="font-size:12px;color:#1e8449;padding-bottom:10px;">
                        ✓ Attualmente: <?php echo esc_html( (new DateTime($estimated_delivery))->format('d/m/Y') ); ?>
                    </span>
                <?php else : ?>
                    <span style="font-size:12px;color:#888;padding-bottom:10px;">Non ancora impostata</span>
                <?php endif; ?>
            </form>
            <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <?php wp_nonce_field( 'wcotl_save_delivered_' . $code, '_wpnonce_delivered' ); ?>
                <input type="hidden" name="wcotl_save_delivered" value="1">
                <div class="wcotl-form-row" style="margin-bottom:0;width:400px;">
                    <label>
                        Data consegna effettiva (opzionale)
                        <span class="dashicons dashicons-info tooltip">
                            <span class="tooltiptext">
                                Imposta questa data per sostituire il banner 'Estimated delivery' con il banner verde di consegna completata. Lascia vuoto per tornare alla data stimata.
                            </span>
                        </span>
                    </label>
                    <input type="date" name="delivered_at"
                           value="<?php echo esc_attr( $delivered_at ?: '' ); ?>"
                           style="width:100%;padding:9px 12px;border:1.5px solid <?php echo $delivered_at ? '#a9dfbf' : '#e2ddd8'; ?>;border-radius:7px;font-size:14px;color:#2d2d2d;background:<?php echo $delivered_at ? '#eafaf1' : '#faf9f7'; ?>;">
                </div>
                <button type="submit" class="wcotl-btn wcotl-btn-primary" style="padding:9px 18px;width:120px;font-size:13px;white-space:nowrap;background:<?php echo $delivered_at ? '#27ae60' : '#1a1a2e'; ?>;">
                    <?php echo $delivered_at ? 'Aggiorna →' : 'Segna come consegnato →'; ?>
                </button>
                <?php if ( $delivered_at ) : ?>
                    <span style="font-size:12px;color:#1e8449;padding-bottom:10px;">
                        ✓ Consegnato il: <?php echo esc_html( (new DateTime($delivered_at))->format('d/m/Y') ); ?>
                    </span>
                <?php else : ?>
                    <span style="font-size:12px;color:#888;align-self:center;">Non ancora consegnato</span>
                <?php endif; ?>
            </form>
        </div>

        <!-- Auto-tracking 17TRACK -->
        <div class="wcotl-card wcotl-autotrack-card" style="margin-bottom:24px;">
            <h2>🛰 Auto-Tracking (17TRACK)</h2>
            <?php if ( ! $provider_active ) : ?>
                <p style="font-size:13px;color:#888;">
                    Auto-tracking non attivo. Configura la tua API key in
                    <a href="<?php echo esc_url( admin_url('admin.php?page=wcotl-settings') ); ?>">Impostazioni</a>.
                </p>
            <?php else : ?>

            <!-- Status badge -->
            <div style="margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <?php if ( $at_real_number ) : ?>
                    <?php if ( $at_stopped ) : ?>
                        <span class="wcotl-sync-status wcotl-sync-stopped">⏹ Sync fermato</span>
                        <?php if ( $at_stop_reason ) : ?>
                            <span style="font-size:12px;color:#888;"><?php echo esc_html($at_stop_reason); ?></span>
                        <?php endif; ?>
                        <button class="wcotl-btn wcotl-btn-sm" style="background:#eafaf1;color:#1e8449;border:1px solid #a9dfbf;"
                                onclick="wcotlResumeSync()">↺ Riprendi sync</button>
                    <?php elseif ( $at_registered ) : ?>
                        <span class="wcotl-sync-status wcotl-sync-active">✓ Sync attivo</span>
                        <?php if ( $at_last_status ) : ?>
                            <span style="font-size:12px;color:#888;">Stato: <strong><?php echo esc_html($at_last_status); ?></strong></span>
                        <?php endif; ?>
                        <?php if ( $at_last_event ) : ?>
                            <span style="font-size:12px;color:#888;">Ultimo evento: <?php echo esc_html((new DateTime($at_last_event))->format('d/m/Y H:i')); ?></span>
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="wcotl-sync-status wcotl-sync-pending">⏳ In attesa di prima sync</span>
                    <?php endif; ?>

                    <?php if ( $at_real_number && ! $at_stopped ) : ?>
                        <button class="wcotl-btn wcotl-btn-secondary wcotl-btn-sm" id="wcotl-sync-now-btn"
                                onclick="wcotlSyncNow()">
                            ↻ Sync ora
                        </button>
                    <?php endif; ?>
                <?php else : ?>
                    <span class="wcotl-sync-status wcotl-sync-pending">— Nessun numero tracking reale impostato</span>
                <?php endif; ?>
            </div>

            <!-- Real tracking number form -->
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px;">
                <div class="wcotl-form-row" style="margin-bottom:0;width:260px;">
                    <label>Numero tracking corriere
                        <span class="dashicons dashicons-info tooltip">
                            <span class="tooltiptext">Il codice tracking reale del corriere (es. RR123456789CN). Questo viene usato per interrogare 17TRACK.</span>
                        </span>
                    </label>
                    <input type="text" id="wcotl-real-number"
                           value="<?php echo esc_attr($at_real_number ?: ''); ?>"
                           placeholder="es. RR123456789CN"
                           style="font-family:monospace;letter-spacing:.06em;">
                </div>
                <div class="wcotl-form-row" style="margin-bottom:0;width:260px;">
                    <label>Corriere
                        <span class="dashicons dashicons-info tooltip">
                            <span class="tooltiptext">Seleziona il corriere o usa "Auto-detect" per suggerimenti da 17TRACK. Se lasci 0 / Auto, 17TRACK prova a rilevarlo automaticamente.</span>
                        </span>
                    </label>
                    <select id="wcotl-carrier-select" style="font-size:13px;">
                        <option value="0">— Auto-detect —</option>
                        <?php if ( $at_carrier ) : ?>
                            <option value="<?php echo $at_carrier; ?>" selected>Corriere #<?php echo $at_carrier; ?> (salvato)</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div style="display:flex;gap:8px;flex-direction:column;">
                    <button class="wcotl-btn wcotl-btn-secondary wcotl-btn-sm" style="white-space:nowrap;"
                            onclick="wcotlDetectCarriers()">🔍 Detect carrier</button>
                    <button class="wcotl-btn wcotl-btn-primary wcotl-btn-sm" style="white-space:nowrap;"
                            onclick="wcotlSaveAutoTracking()">💾 Salva</button>
                </div>
            </div>
            <div id="wcotl-at-message" style="font-size:12px;margin-top:4px;"></div>

            <script>
            var WCOTL_TRACKING_CODE = '<?php echo esc_js($code); ?>';

            function wcotlDetectCarriers() {
                var num = document.getElementById('wcotl-real-number').value.trim();
                if (!num) { alert('Inserisci prima il numero tracking corriere.'); return; }
                var msg = document.getElementById('wcotl-at-message');
                msg.textContent = '⏳ Rilevamento in corso...';
                fetch(WCOTL_AJAX.url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'wcotl_detect_carriers',
                        nonce:  WCOTL_AJAX.nonce,
                        number: num
                    })
                }).then(r => r.json()).then(res => {
                    if (!res.success) { msg.textContent = '❌ ' + (res.data || 'Errore'); return; }
                    var carriers = res.data;
                    var sel = document.getElementById('wcotl-carrier-select');
                    sel.innerHTML = '<option value="0">— Auto-detect —</option>';
                    if (Object.keys(carriers).length === 0) {
                        msg.textContent = '⚠ Nessun corriere rilevato. Seleziona manualmente.';
                    } else {
                        for (var code in carriers) {
                            var opt = document.createElement('option');
                            opt.value = code;
                            opt.textContent = carriers[code] + ' (' + code + ')';
                            sel.appendChild(opt);
                        }
                        sel.selectedIndex = 1;
                        msg.textContent = '✓ ' + Object.keys(carriers).length + ' corriere/i trovato/i. Seleziona e salva.';
                        msg.style.color = '#1e8449';
                    }
                }).catch(e => { msg.textContent = '❌ Errore di rete.'; });
            }

            function wcotlSaveAutoTracking() {
                var num     = document.getElementById('wcotl-real-number').value.trim();
                var carrier = document.getElementById('wcotl-carrier-select').value;
                var msg     = document.getElementById('wcotl-at-message');
                msg.textContent = '⏳ Salvataggio...';
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
                    if (!res.success) { msg.style.color='#c0392b'; msg.textContent = '❌ ' + (res.data || 'Errore'); return; }
                    msg.style.color = '#1e8449';
                    msg.textContent = '✓ Salvato! La prima sync avverrà al prossimo cron (o usa "Sync ora").';
                    setTimeout(() => location.reload(), 1800);
                }).catch(e => { msg.textContent = '❌ Errore di rete.'; });
            }

            function wcotlSyncNow() {
                var btn = document.getElementById('wcotl-sync-now-btn');
                if (btn) btn.disabled = true;
                var msg = document.getElementById('wcotl-at-message');
                msg.style.color = '#888';
                msg.textContent = '⏳ Sync in corso...';
                fetch(WCOTL_AJAX.url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action:        'wcotl_sync_now',
                        nonce:         WCOTL_AJAX.nonce,
                        tracking_code: WCOTL_TRACKING_CODE
                    })
                }).then(r => r.json()).then(res => {
                    if (!res.success) { msg.style.color='#c0392b'; msg.textContent = '❌ ' + (res.data || 'Errore'); if(btn)btn.disabled=false; return; }
                    msg.style.color = '#1e8449';
                    msg.textContent = '✓ Sync completata! Stato: ' + (res.data.status || '?');
                    setTimeout(() => location.reload(), 1800);
                }).catch(e => { msg.textContent = '❌ Errore di rete.'; if(btn)btn.disabled=false; });
            }

            function wcotlResumeSync() {
                var msg = document.getElementById('wcotl-at-message');
                msg.textContent = '⏳ Riattivazione...';
                fetch(WCOTL_AJAX.url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action:        'wcotl_resume_sync',
                        nonce:         WCOTL_AJAX.nonce,
                        tracking_code: WCOTL_TRACKING_CODE
                    })
                }).then(r => r.json()).then(res => {
                    if (!res.success) { msg.style.color='#c0392b'; msg.textContent = '❌ ' + (res.data || 'Errore'); return; }
                    msg.style.color = '#1e8449';
                    msg.textContent = '✓ Sync riattivata!';
                    setTimeout(() => location.reload(), 1000);
                }).catch(e => { msg.textContent = '❌ Errore di rete.'; });
            }
            </script>

            <?php endif; // provider_active ?>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">

            <!-- Lista step esistenti -->
            <div class="wcotl-card">
                <h2>Step nella timeline (<?php echo count($steps); ?>)</h2>
                <?php if ( empty($steps) ) : ?>
                    <p style="color:#888;font-size:14px;">Nessuno step ancora. Aggiungine uno →</p>
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
                        <!-- Vista normale -->
                        <div class="wcotl-step-row" id="wcotl-view-<?php echo $s->id; ?>" style="display:flex;gap:12px;align-items:flex-start;">
                            <div class="wcotl-step-meta" style="flex:1;">
                                <strong style="<?php echo $is_voided ? 'text-decoration:line-through;color:#a09a94;' : ''; ?>"><?php echo esc_html($s->step_label); ?></strong>
                                <?php $src = isset($s->step_source) ? $s->step_source : 'manual'; ?>
                                <span class="wcotl-source-badge <?php echo $src === 'auto' ? 'wcotl-source-auto' : 'wcotl-source-manual'; ?>">
                                    <?php echo $src === 'auto' ? '🛰 auto' : '✍ manuale'; ?>
                                </span>
                                <small><?php echo esc_html($dt->format('d/m/Y H:i')); ?> &nbsp;·&nbsp; <?php echo esc_html($s->step_icon); ?></small>
                                <?php if ($s->step_note) : ?>
                                    <p style="font-size:12px;color:#888;margin-top:4px;font-style:italic;"><?php echo esc_html($s->step_note); ?></p>
                                <?php endif; ?>
                                <?php if ( $is_voided ) : ?>
                                    <p style="font-size:11px;color:#c0392b;margin-top:4px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;">
                                        ⊘ Non confermato<?php if ( $s->step_void_reason ) : ?> — <em style="font-weight:normal;font-style:italic;"><?php echo esc_html($s->step_void_reason); ?></em><?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end;">
                                <?php if ( ! $is_voided ) : ?>
                                    <button type="button"
                                            class="wcotl-btn wcotl-btn-secondary wcotl-btn-sm"
                                            onclick="wcotlToggleEdit(<?php echo $s->id; ?>)" title="Modifica">✎</button>
                                    <button type="button"
                                            class="wcotl-btn wcotl-btn-sm"
                                            style="background:#fff3cd;color:#856404;border:1px solid #ffc107;"
                                            onclick="wcotlToggleVoid(<?php echo $s->id; ?>)" title="Segna come non confermato">⊘</button>
                                <?php else : ?>
                                    <a href="<?php echo esc_url($unvoid_nonce); ?>"
                                       class="wcotl-btn wcotl-btn-sm"
                                       style="background:#eafaf1;color:#1e8449;border:1px solid #a9dfbf;"
                                       title="Ripristina step">↩ Ripristina</a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url($del); ?>"
                                   class="wcotl-btn wcotl-btn-danger wcotl-btn-sm"
                                   onclick="return confirm('Eliminare questo step?')" title="Elimina">✕</a>
                            </div>
                        </div>

                        <!-- Form modifica inline -->
                        <div class="wcotl-edit-form" id="wcotl-edit-<?php echo $s->id; ?>" style="display:none;margin-top:12px;background:#faf9f7;border:1.5px solid #c8963e;border-radius:8px;padding:16px;">
                            <p style="font-size:12px;font-weight:600;color:#c8963e;margin-bottom:12px;text-transform:uppercase;letter-spacing:.06em;">Modifica step</p>
                            <form method="POST">
                                <input type="hidden" name="wcotl_edit_step" value="1">
                                <input type="hidden" name="_wpnonce_edit" value="<?php echo esc_attr($edit_nonce); ?>">
                                <input type="hidden" name="step_id" value="<?php echo $s->id; ?>">
                                <div class="wcotl-form-row">
                                    <label>Data e Ora <span style="color:#c0392b">*</span></label>
                                    <input type="datetime-local" name="step_date"
                                           value="<?php echo esc_attr($dt->format('Y-m-d\TH:i')); ?>" required>
                                </div>
                                <div class="wcotl-form-row">
                                    <label>Descrizione step <span style="color:#c0392b">*</span></label>
                                    <input type="text" name="step_label"
                                           value="<?php echo esc_attr($s->step_label); ?>" required>
                                </div>
                                <div class="wcotl-form-row">
                                    <label>Nota aggiuntiva (opzionale)</label>
                                    <textarea name="step_note"><?php echo esc_textarea($s->step_note); ?></textarea>
                                </div>
                                <div class="wcotl-form-row">
                                    <label>Icona</label>
                                    <select name="step_icon">
                                        <?php foreach ( array_keys($icons) as $k ) : ?>
                                            <option value="<?php echo esc_attr($k); ?>" <?php selected($k, $s->step_icon); ?>>
                                                <?php echo esc_html(ucfirst(str_replace('_',' ',$k))); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="display:flex;gap:8px;">
                                    <button type="submit" class="wcotl-btn wcotl-btn-primary wcotl-btn-sm" style="padding:7px 16px;font-size:13px;">Salva modifiche</button>
                                    <button type="button" class="wcotl-btn wcotl-btn-secondary wcotl-btn-sm" style="padding:7px 16px;font-size:13px;"
                                            onclick="wcotlToggleEdit(<?php echo $s->id; ?>)">Annulla</button>
                                </div>
                            </form>
                        </div>

                        <!-- Form annullamento step (motivo) -->
                        <div id="wcotl-void-<?php echo $s->id; ?>" style="display:none;margin-top:12px;background:#fdf2f0;border:1.5px solid #f5b7b1;border-radius:8px;padding:16px;">
                            <p style="font-size:12px;font-weight:600;color:#c0392b;margin-bottom:10px;text-transform:uppercase;letter-spacing:.06em;">⊘ Segna come non confermato</p>
                            <form method="POST">
                                <input type="hidden" name="wcotl_save_void_reason" value="1">
                                <input type="hidden" name="_wpnonce_void" value="<?php echo esc_attr($void_reason_nonce); ?>">
                                <input type="hidden" name="step_id" value="<?php echo $s->id; ?>">
                                <div class="wcotl-form-row">
                                    <label>Motivo (visibile al cliente, opzionale)</label>
                                    <textarea name="step_void_reason" placeholder="es. Informazione errata comunicata dal corriere. La spedizione è ancora in transito."><?php echo esc_textarea( $s->step_void_reason ?? '' ); ?></textarea>
                                </div>
                                <div style="display:flex;gap:8px;">
                                    <button type="submit" class="wcotl-btn wcotl-btn-sm" style="padding:7px 16px;font-size:13px;background:#c0392b;color:#fff;">Conferma annullamento</button>
                                    <button type="button" class="wcotl-btn wcotl-btn-secondary wcotl-btn-sm" style="padding:7px 16px;font-size:13px;"
                                            onclick="wcotlToggleVoid(<?php echo $s->id; ?>)">Annulla</button>
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
                    // chiudi void se aperto
                    var voidForm = document.getElementById('wcotl-void-' + id);
                    if (voidForm) voidForm.style.display = 'none';
                }
                function wcotlToggleVoid(id) {
                    var voidForm = document.getElementById('wcotl-void-' + id);
                    var isOpen = voidForm.style.display !== 'none';
                    voidForm.style.display = isOpen ? 'none' : 'block';
                    // chiudi edit se aperto
                    var editForm = document.getElementById('wcotl-edit-' + id);
                    if (editForm) editForm.style.display = 'none';
                    var view = document.getElementById('wcotl-view-' + id);
                    if (view) view.style.opacity = isOpen ? '1' : '0.5';
                }
                </script>
                <?php endif; ?>
            </div>

            <!-- Form aggiunta step -->
            <div class="wcotl-card">
                <h2>Aggiungi uno step</h2>
                <form method="POST">
                    <?php wp_nonce_field('wcotl_add_step'); ?>
                    <input type="hidden" name="wcotl_add_step" value="1">
                    <input type="hidden" name="tracking_code" value="<?php echo esc_attr($code); ?>">

                    <?php $presets = WCOTL_DB::get_presets(); if ( ! empty( $presets ) ) : ?>
                    <div class="wcotl-form-row" style="background:#faf5ed;border:1px solid #e8d8b4;border-radius:8px;padding:14px 16px;margin-bottom:20px;">
                        <label style="color:#9a6e1a;">⚡ Usa un preset</label>
                        <select id="wcotl-preset-select" style="background:#fff;">
                            <option value="">— seleziona preset —</option>
                            <?php foreach ( $presets as $p ) : ?>
                                <option value="<?php echo absint($p->id); ?>"
                                        data-label="<?php echo esc_attr($p->step_label); ?>"
                                        data-note="<?php echo esc_attr($p->step_note); ?>"
                                        data-icon="<?php echo esc_attr($p->step_icon); ?>">
                                    <?php echo esc_html($p->preset_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="font-size:11px;color:#aaa;margin-top:4px;">Pre-compila i campi sottostanti. Puoi modificarli prima di salvare.</small>
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
                        // highlight fields briefly
                        ['step_label','step_note','step_icon'].forEach(function(n) {
                            var el = form.querySelector('[name="' + n + '"]');
                            if (el) { el.style.borderColor = '#c8963e'; setTimeout(function(){ el.style.borderColor = ''; }, 1200); }
                        });
                    });
                    </script>
                    <?php endif; ?>


                    <div class="wcotl-form-row">
                        <label>Data e Ora <span style="color:#c0392b">*</span></label>
                        <input type="datetime-local" name="step_date" value="<?php echo esc_attr( date('Y-m-d\TH:i') ); ?>" required>
                    </div>
                    <div class="wcotl-form-row">
                        <label>Descrizione step <span style="color:#c0392b">*</span></label>
                        <input type="text" name="step_label" placeholder="es. Merce caricata a Milano" required>
                    </div>
                    <div class="wcotl-form-row">
                        <label>Nota aggiuntiva (opzionale)</label>
                        <textarea name="step_note" placeholder="Dettagli extra, informazioni al cliente..."></textarea>
                    </div>
                    <div class="wcotl-form-row">
                        <label>Icona</label>
                        <select name="step_icon">
                            <?php foreach ( array_keys($icons) as $k ) : ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html(ucfirst(str_replace('_',' ',$k))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="wcotl-btn wcotl-btn-primary" style="width:100%;padding:10px;font-size:14px;">Aggiungi Step →</button>
                </form>
            </div>

        </div>
        <?php
    }

    public static function page_new_code() {
        $notice = '';

        if ( isset( $_POST['wcotl_create_code'], $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'wcotl_create_code' ) ) {
            $code = strtoupper( sanitize_text_field( $_POST['tracking_code'] ?? '' ) );
            $code = preg_replace('/[^A-Z0-9\-_]/', '', $code);

            if ( strlen($code) >= 3 ) {
                // Salva order_id nei meta se proveniente dall'ordine
                $order_id_from_post = absint( $_POST['order_id'] ?? 0 );
                if ( $order_id_from_post ) {
                    WCOTL_DB::set_meta( $code, 'order_id', $order_id_from_post );
                }
                // Redirect a gestione del codice
                wp_redirect( admin_url('admin.php?page=wcotl-tracking&view=' . urlencode($code) ) );
                exit;
            } else {
                $notice = '<div class="wcotl-notice wcotl-notice-error">Inserisci un codice valido (minimo 3 caratteri, solo lettere, numeri, trattini).</div>';
            }
        }

        // Genera codice suggerito
        $suggested  = 'TRK-' . date('Ymd') . '-' . strtoupper( wp_generate_password(4, false, false) );
        $order_id   = absint( $_GET['order_id'] ?? 0 );
        ?>
        <div class="wrap wcotl-admin">
            <h1>Nuovo Codice di Tracciamento</h1>
            <?php echo wp_kses_post( $notice ); ?>
            <div class="wcotl-card" style="max-width:480px;">
                <h2>Crea un nuovo codice</h2>
                <p style="font-size:13px;color:#888;margin-bottom:16px;">Il codice viene comunicato al cliente. Puoi usare qualsiasi formato.</p>
                <form method="POST">
                    <?php wp_nonce_field('wcotl_create_code'); ?>
                    <input type="hidden" name="wcotl_create_code" value="1">
                    <?php if ( $order_id ) : ?>
                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                    <?php endif; ?>
                    <div class="wcotl-form-row">
                        <label>Codice tracking</label>
                        <input type="text" name="tracking_code" value="<?php echo esc_attr($suggested); ?>"
                               placeholder="es. TRK-20240518-001" required style="font-family:monospace;letter-spacing:.08em;">
                    </div>
                    <?php if ( $order_id ) : ?>
                        <p style="font-size:12px;color:#1e8449;margin-bottom:16px;">✓ Sarà associato automaticamente all'ordine <strong>#<?php echo $order_id; ?></strong>.</p>
                    <?php endif; ?>
                    <button type="submit" class="wcotl-btn wcotl-btn-primary" style="width:100%;padding:10px;font-size:14px;">Crea e inizia ad aggiungere step →</button>
                </form>
            </div>
        </div>
        <?php
    }

}
