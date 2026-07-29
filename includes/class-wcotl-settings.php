<?php
/**
 * Plugin Settings page
 *
 * Adds a "Impostazioni" submenu under the Timeline Tracking menu,
 * allowing admins to configure:
 *   - 17TRACK API key
 *   - Sync interval (hours)
 *   - Inactivity threshold (days before stopping auto-tracking)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCOTL_Settings {

    public static function init() {
        add_action( 'admin_menu',    [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init',    [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_post_wcotl_save_settings', [ __CLASS__, 'save_settings' ] );
        // AJAX: carrier auto-detect
        add_action( 'wp_ajax_wcotl_detect_carriers',  [ __CLASS__, 'ajax_detect_carriers' ] );
        // AJAX: manually trigger sync for a single code
        add_action( 'wp_ajax_wcotl_sync_now',         [ __CLASS__, 'ajax_sync_now' ] );
        // AJAX: register / update real tracking number for a code
        add_action( 'wp_ajax_wcotl_save_auto_tracking', [ __CLASS__, 'ajax_save_auto_tracking' ] );
        // AJAX: resume stopped sync
        add_action( 'wp_ajax_wcotl_resume_sync', [ __CLASS__, 'ajax_resume_sync' ] );
    }

    /* ------------------------------------------------------------------
     * Menu registration
     * ------------------------------------------------------------------ */

    public static function register_menu() {
        add_submenu_page(
            'wcotl-tracking',
            'Impostazioni Tracking',
            '⚙ Impostazioni',
            'manage_woocommerce',
            'wcotl-settings',
            [ __CLASS__, 'page' ]
        );
    }

    /* ------------------------------------------------------------------
     * Settings registration (used only for nonce / sanitise wiring)
     * ------------------------------------------------------------------ */

    public static function register_settings() {
        register_setting( 'wcotl_settings_group', 'wcotl_17track_api_key',  'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_sync_interval',    'absint' );
        register_setting( 'wcotl_settings_group', 'wcotl_inactivity_days',  'absint' );
    }

    /* ------------------------------------------------------------------
     * Save handler (custom form POST to avoid WP settings-api redirect quirks)
     * ------------------------------------------------------------------ */

    public static function save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Not allowed.' );
        }
        check_admin_referer( 'wcotl_settings_nonce' );

        $api_key  = sanitize_text_field( wp_unslash( $_POST['wcotl_17track_api_key'] ?? '' ) );
        $interval = max( 1, absint( $_POST['wcotl_sync_interval'] ?? 1 ) );
        $inactive = max( 1, absint( $_POST['wcotl_inactivity_days'] ?? 45 ) );

        $old_interval = (int) get_option( 'wcotl_sync_interval', 1 );

        update_option( 'wcotl_17track_api_key',  $api_key );
        update_option( 'wcotl_inactivity_days',  $inactive );

        // update_option triggers the reschedule hook if interval changed.
        update_option( 'wcotl_sync_interval', $interval );

        if ( $interval !== $old_interval ) {
            WCOTL_Auto_Sync::reschedule( $old_interval, $interval );
        }

        wp_redirect( admin_url( 'admin.php?page=wcotl-settings&saved=1' ) );
        exit;
    }

    /* ------------------------------------------------------------------
     * AJAX: detect carriers for a real tracking number
     * ------------------------------------------------------------------ */

    public static function ajax_detect_carriers() {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Not allowed.' );
        }

        $number   = sanitize_text_field( wp_unslash( $_POST['number'] ?? '' ) );
        $provider = WCOTL_Auto_Sync::get_provider();

        if ( ! $provider ) {
            wp_send_json_error( 'No API key configured.' );
        }

        $carriers = $provider->detect_carriers( $number );
        wp_send_json_success( $carriers );
    }

    /* ------------------------------------------------------------------
     * AJAX: save real tracking number + carrier for a plugin tracking code
     * ------------------------------------------------------------------ */

    public static function ajax_save_auto_tracking() {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Not allowed.' );
        }

        $tracking_code   = sanitize_text_field( wp_unslash( $_POST['tracking_code'] ?? '' ) );
        $real_number     = sanitize_text_field( wp_unslash( $_POST['real_number'] ?? '' ) );
        $carrier_code    = absint( $_POST['carrier_code'] ?? 0 );

        if ( ! $tracking_code ) {
            wp_send_json_error( 'Missing tracking_code.' );
        }

        if ( $real_number ) {
            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_REAL_NUMBER,   $real_number );
            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_CARRIER_CODE,  $carrier_code ?: '' );
            // Reset registration so it re-registers at provider on next sync.
            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_REGISTERED,    '' );
            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_SYNC_STOPPED,  '' );
            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_STOP_REASON,   '' );
            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_LAST_STATUS,   '' );
        } else {
            // Clearing the real number disables auto-tracking for this code.
            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_REAL_NUMBER, '' );
        }

        wp_send_json_success( 'Saved.' );
    }

    /* ------------------------------------------------------------------
     * AJAX: force sync now for a single code
     * ------------------------------------------------------------------ */

    public static function ajax_sync_now() {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Not allowed.' );
        }

        $tracking_code = sanitize_text_field( wp_unslash( $_POST['tracking_code'] ?? '' ) );
        if ( ! $tracking_code ) wp_send_json_error( 'Missing tracking_code.' );

        WCOTL_Auto_Sync::sync_code( $tracking_code );
        $status = WCOTL_DB::get_meta( $tracking_code, WCOTL_Auto_Sync::META_LAST_STATUS );
        wp_send_json_success( [ 'status' => $status ] );
    }

    /* ------------------------------------------------------------------
     * AJAX: resume stopped sync
     * ------------------------------------------------------------------ */

    public static function ajax_resume_sync() {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Not allowed.' );
        }

        $tracking_code = sanitize_text_field( wp_unslash( $_POST['tracking_code'] ?? '' ) );
        if ( ! $tracking_code ) wp_send_json_error( 'Missing tracking_code.' );

        WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_SYNC_STOPPED, '' );
        WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_STOP_REASON,  '' );
        WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_LAST_STATUS,  '' );

        wp_send_json_success( 'Sync resumed.' );
    }

    /* ------------------------------------------------------------------
     * Settings page UI
     * ------------------------------------------------------------------ */

    public static function page() {
        $api_key         = get_option( 'wcotl_17track_api_key', '' );
        $sync_interval   = max( 1, (int) get_option( 'wcotl_sync_interval', 1 ) );
        $inactivity_days = max( 1, (int) get_option( 'wcotl_inactivity_days', 45 ) );
        $saved           = isset( $_GET['saved'] );
        $next_sync       = wp_next_scheduled( WCOTL_Auto_Sync::CRON_HOOK );

        // Test provider connectivity if key is set.
        $provider_ok = false;
        $provider_err = '';
        if ( $api_key ) {
            $provider = new WCOTL_17track_Provider( $api_key );
            $provider_ok = $provider->is_configured();
        }
        ?>
        <div class="wrap wcotl-admin">
            <h1>
                <span class="dashicons dashicons-admin-settings"></span>
                Impostazioni Auto-Tracking
            </h1>

            <?php if ( $saved ) : ?>
                <div class="wcotl-notice wcotl-notice-success">Impostazioni salvate.</div>
            <?php endif; ?>

            <!-- 17TRACK configuration -->
            <div class="wcotl-card" style="max-width:620px;">
                <h2>🛰 17TRACK API</h2>
                <p style="font-size:13px;color:#888;margin-bottom:16px;">
                    Ottieni la tua API key su <a href="https://api.17track.net" target="_blank">api.17track.net</a>.
                    L'auto-tracking è abilitato per tutti i codici che hanno un numero di tracking reale associato.
                </p>

                <form method="POST" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <?php wp_nonce_field( 'wcotl_settings_nonce' ); ?>
                    <input type="hidden" name="action" value="wcotl_save_settings">

                    <div class="wcotl-form-row">
                        <label>
                            17TRACK API Key
                            <?php if ( $api_key ) : ?>
                                <span style="color:#1e8449;font-size:11px;margin-left:6px;">✓ Configurata</span>
                            <?php endif; ?>
                        </label>
                        <input type="text" name="wcotl_17track_api_key"
                               value="<?php echo esc_attr( $api_key ); ?>"
                               placeholder="Incolla qui la tua API key"
                               style="font-family:monospace;letter-spacing:.06em;">
                        <small style="font-size:11px;color:#aaa;margin-top:4px;">
                            Lascia vuoto per disabilitare l'auto-tracking.
                        </small>
                    </div>

                    <div class="wcotl-form-row">
                        <label>Intervallo sincronizzazione (ore)</label>
                        <input type="number" name="wcotl_sync_interval"
                               value="<?php echo esc_attr( $sync_interval ); ?>"
                               min="1" max="24" step="1" style="width:120px;">
                        <?php if ( $next_sync ) : ?>
                            <small style="font-size:11px;color:#888;margin-top:4px;">
                                Prossima sync: <?php echo esc_html( date('d/m/Y H:i', $next_sync) ); ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="wcotl-form-row">
                        <label>Giorni di inattività prima di fermare il tracking</label>
                        <input type="number" name="wcotl_inactivity_days"
                               value="<?php echo esc_attr( $inactivity_days ); ?>"
                               min="1" max="365" step="1" style="width:120px;">
                        <small style="font-size:11px;color:#888;margin-top:4px;">
                            Se non vengono ricevuti aggiornamenti per questo numero di giorni,
                            l'auto-tracking viene interrotto e viene inviata un'email all'admin.
                        </small>
                    </div>

                    <button type="submit" class="wcotl-btn wcotl-btn-primary" style="padding:10px 24px;font-size:14px;">
                        Salva impostazioni →
                    </button>
                </form>
            </div>

            <!-- Info box -->
            <div class="wcotl-card" style="max-width:620px;background:#f0f7ff;border-color:#b8d4f8;">
                <h2 style="color:#1a5fa8;">ℹ Come funziona l'auto-tracking</h2>
                <ul style="font-size:13px;line-height:1.9;color:#2d4a6e;padding-left:18px;">
                    <li>Per ogni codice tracking del plugin, puoi associare un <strong>numero tracking reale</strong> (quello del corriere, es. <code>RR123456789CN</code>).</li>
                    <li>Il plugin interroga 17TRACK ogni <strong><?php echo $sync_interval; ?> ora/e</strong> e importa automaticamente i nuovi aggiornamenti come step nella timeline.</li>
                    <li>Gli step manuali esistenti <strong>non vengono mai toccati</strong>: convivono con quelli automatici.</li>
                    <li>Quando 17TRACK segnala lo stato <strong>Delivered</strong>, la data di consegna effettiva viene impostata automaticamente.</li>
                    <li>Il polling si ferma automaticamente per spedizioni: Delivered, Expired, o <strong>inattive per <?php echo $inactivity_days; ?> giorni</strong>.</li>
                    <li>In caso di inattività prolungata, viene inviata un'email all'indirizzo admin del sito.</li>
                </ul>
            </div>
        </div>
        <?php
    }
}
