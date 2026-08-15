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
        // AJAX: update delete data preference on uninstall
        add_action( 'wp_ajax_wcotl_set_uninstall_data_preference', [ __CLASS__, 'ajax_set_uninstall_data_preference' ] );
        // Render uninstall confirmation modal on plugins list screen
        add_action( 'admin_footer', [ __CLASS__, 'render_plugins_uninstall_modal' ] );
    }

    /* ------------------------------------------------------------------
     * Menu registration
     * ------------------------------------------------------------------ */

    public static function register_menu() {
        add_submenu_page(
            'wcotl-tracking',
            'Tracking Settings',
            '⚙ Settings',
            'manage_woocommerce',
            'wcotl-settings',
            [ __CLASS__, 'page' ]
        );
    }

    /* ------------------------------------------------------------------
     * Settings registration (used only for nonce / sanitise wiring)
     * ------------------------------------------------------------------ */

    public static function register_settings() {
        register_setting( 'wcotl_settings_group', 'wcotl_17track_api_key',           'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_sync_interval',             'absint' );
        register_setting( 'wcotl_settings_group', 'wcotl_inactivity_days',           'absint' );
        register_setting( 'wcotl_settings_group', 'wcotl_delete_data_on_uninstall',  'absint' );
        register_setting( 'wcotl_settings_group', 'wcotl_turnstile_site_key',        'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_turnstile_secret_key',      'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_rate_limit_max_requests',   'absint' );
    }

    /* ------------------------------------------------------------------
     * Save handler (custom form POST to avoid WP settings-api redirect quirks)
     * ------------------------------------------------------------------ */

    public static function save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Not allowed.' );
        }
        check_admin_referer( 'wcotl_settings_nonce' );

        $api_key          = sanitize_text_field( wp_unslash( $_POST['wcotl_17track_api_key'] ?? '' ) );
        $interval         = max( 1, absint( $_POST['wcotl_sync_interval'] ?? 1 ) );
        $inactive         = max( 1, absint( $_POST['wcotl_inactivity_days'] ?? 45 ) );
        $delete_data      = ! empty( $_POST['wcotl_delete_data_on_uninstall'] ) ? 1 : 0;
        $turnstile_site   = sanitize_text_field( wp_unslash( $_POST['wcotl_turnstile_site_key'] ?? '' ) );
        $turnstile_secret = sanitize_text_field( wp_unslash( $_POST['wcotl_turnstile_secret_key'] ?? '' ) );
        $rate_limit_max   = max( 1, absint( $_POST['wcotl_rate_limit_max_requests'] ?? 15 ) );

        $old_interval = (int) get_option( 'wcotl_sync_interval', 1 );

        update_option( 'wcotl_17track_api_key',          $api_key );
        update_option( 'wcotl_inactivity_days',          $inactive );
        update_option( 'wcotl_delete_data_on_uninstall', $delete_data );
        update_option( 'wcotl_turnstile_site_key',        $turnstile_site );
        update_option( 'wcotl_turnstile_secret_key',      $turnstile_secret );
        update_option( 'wcotl_rate_limit_max_requests',   $rate_limit_max );

        // update_option triggers the reschedule hook if interval changed.
        update_option( 'wcotl_sync_interval', $interval );

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
            // Clear the cursor so this code is picked up on the very next cron tick.
            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_NEXT_SYNC_AT,  '' );
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
        // Clear the cursor so the resumed code is picked up on the very next cron tick.
        WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_NEXT_SYNC_AT, '' );

        wp_send_json_success( 'Sync resumed.' );
    }

    /* ------------------------------------------------------------------
     * Settings page UI
     * ------------------------------------------------------------------ */

    public static function page() {
        $api_key                   = get_option( 'wcotl_17track_api_key', '' );
        $sync_interval             = max( 1, (int) get_option( 'wcotl_sync_interval', 1 ) );
        $inactivity_days           = max( 1, (int) get_option( 'wcotl_inactivity_days', 45 ) );
        $delete_data_on_uninstall = (int) get_option( 'wcotl_delete_data_on_uninstall', 0 );
        $saved                     = isset( $_GET['saved'] );
        $next_sync                 = wp_next_scheduled( WCOTL_Auto_Sync::CRON_HOOK );

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
                Auto-Tracking Settings
            </h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <!-- 17TRACK configuration -->
            <div class="card" style="max-width:620px;">
                <h2><span class="dashicons dashicons-share-alt"></span> 17TRACK API</h2>
                <p style="font-size:13px;color:#646970;margin-bottom:16px;">
                    Get your API key at <a href="https://api.17track.net" target="_blank">api.17track.net</a>.
                    Auto-tracking is enabled for all codes that have a real carrier tracking number associated.
                </p>

                <form method="POST" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <?php wp_nonce_field( 'wcotl_settings_nonce' ); ?>
                    <input type="hidden" name="action" value="wcotl_save_settings">

                    <div class="wcotl-form-row">
                        <label>
                            17TRACK API Key
                            <?php if ( $api_key ) : ?>
                                <span style="color:#008a20;font-size:11px;margin-left:6px;">✓ Configured</span>
                            <?php endif; ?>
                        </label>
                        <div style="display:flex;gap:6px;">
                            <input type="password" id="wcotl_api_key_field" name="wcotl_17track_api_key"
                                   value="<?php echo esc_attr( $api_key ); ?>"
                                   placeholder="Paste your API key here"
                                   style="font-family:monospace;flex:1;">
                            <button type="button" class="button" onclick="var f = document.getElementById('wcotl_api_key_field'); f.type = f.type === 'password' ? 'text' : 'password'; this.textContent = f.type === 'password' ? 'Show' : 'Hide';">Show</button>
                        </div>
                        <small style="font-size:11px;color:#646970;display:block;margin-top:4px;">
                            Leave empty to disable auto-tracking.
                        </small>
                    </div>

                    <div class="wcotl-form-row">
                        <label>Sync Interval (hours)</label>
                        <input type="number" name="wcotl_sync_interval"
                               value="<?php echo esc_attr( $sync_interval ); ?>"
                               min="1" max="24" step="1" style="width:120px;">
                        <?php if ( $next_sync ) : ?>
                            <small style="font-size:11px;color:#646970;display:block;margin-top:4px;">
                                Next sync: <?php echo esc_html( date('d/m/Y H:i', $next_sync) ); ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="wcotl-form-row">
                        <label>Inactivity days before stopping tracking</label>
                        <input type="number" name="wcotl_inactivity_days"
                               value="<?php echo esc_attr( $inactivity_days ); ?>"
                               min="1" max="365" step="1" style="width:120px;">
                        <small style="font-size:11px;color:#646970;display:block;margin-top:4px;">
                            If no updates are received for this number of days,
                            auto-tracking is stopped and an email is sent to the admin.
                        </small>
                    </div>

                    <div class="wcotl-form-row" style="margin-top:20px;padding-top:16px;border-top:1px solid #e0e0e0;">
                        <label style="font-weight:600;display:block;margin-bottom:8px;">
                            Data Retention &amp; Uninstall
                        </label>
                        <label style="font-weight:normal;display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                            <input type="checkbox" name="wcotl_delete_data_on_uninstall" value="1" <?php checked( $delete_data_on_uninstall, 1 ); ?> style="margin-top:2px;">
                            <span>
                                <strong>Erase all data upon uninstall</strong>
                                <span style="font-size:12px;color:#646970;display:block;margin-top:2px;line-height:1.4;">
                                    If enabled, all database tables (<code>order_timeline</code>, <code>order_timeline_meta</code>, <code>order_timeline_presets</code>) and settings will be permanently dropped when deleting the plugin. If unchecked (default), all data and configurations are retained.
                                </span>
                            </span>
                        </label>
                    </div>

                    <button type="submit" class="button button-primary" style="margin-top:16px;">
                        Save Settings
                    </button>
                </form>
            </div>

            <!-- Cloudflare Turnstile & Anti-Scraping Protection -->
            <?php
            $turnstile_site_key   = get_option( 'wcotl_turnstile_site_key', '' );
            $turnstile_secret_key = get_option( 'wcotl_turnstile_secret_key', '' );
            $rate_limit_max       = max( 1, (int) get_option( 'wcotl_rate_limit_max_requests', 15 ) );
            $turnstile_configured = ( ! empty( $turnstile_site_key ) && ! empty( $turnstile_secret_key ) );
            ?>
            <div class="card" style="max-width:620px;margin-top:20px;">
                <h2>
                    <span class="dashicons dashicons-shield"></span>
                    Frontend Security &amp; Cloudflare Turnstile
                </h2>
                <p style="font-size:13px;color:#646970;margin-bottom:16px;">
                    Protect the frontend order tracking lookup shortcode (<code>[wc_order_timeline_tracking]</code>) from automated bot enumeration, scraping, and brute-force queries.
                </p>

                <form method="POST" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <?php wp_nonce_field( 'wcotl_settings_nonce' ); ?>
                    <input type="hidden" name="action" value="wcotl_save_settings">
                    <input type="hidden" name="wcotl_17track_api_key" value="<?php echo esc_attr( $api_key ); ?>">
                    <input type="hidden" name="wcotl_sync_interval" value="<?php echo esc_attr( $sync_interval ); ?>">
                    <input type="hidden" name="wcotl_inactivity_days" value="<?php echo esc_attr( $inactivity_days ); ?>">
                    <input type="hidden" name="wcotl_delete_data_on_uninstall" value="<?php echo esc_attr( $delete_data_on_uninstall ); ?>">

                    <div class="wcotl-form-row">
                        <label>
                            Cloudflare Turnstile Site Key
                            <?php if ( $turnstile_configured ) : ?>
                                <span style="color:#008a20;font-size:11px;margin-left:6px;">✓ Active</span>
                            <?php else : ?>
                                <span style="color:#8c8f94;font-size:11px;margin-left:6px;">(Optional)</span>
                            <?php endif; ?>
                        </label>
                        <input type="text" name="wcotl_turnstile_site_key"
                               value="<?php echo esc_attr( $turnstile_site_key ); ?>"
                               placeholder="e.g. 0x4AAAAAA..."
                               style="font-family:monospace;width:100%;">
                        <small style="font-size:11px;color:#646970;display:block;margin-top:4px;">
                            Obtain your Turnstile Site Key and Secret Key from the <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank">Cloudflare Dashboard</a>.
                        </small>
                    </div>

                    <div class="wcotl-form-row">
                        <label>Cloudflare Turnstile Secret Key</label>
                        <div style="display:flex;gap:6px;">
                            <input type="password" id="wcotl_turnstile_secret_field" name="wcotl_turnstile_secret_key"
                                   value="<?php echo esc_attr( $turnstile_secret_key ); ?>"
                                   placeholder="e.g. 0x4AAAAAA..."
                                   style="font-family:monospace;flex:1;">
                            <button type="button" class="button" onclick="var f = document.getElementById('wcotl_turnstile_secret_field'); f.type = f.type === 'password' ? 'text' : 'password'; this.textContent = f.type === 'password' ? 'Show' : 'Hide';">Show</button>
                        </div>
                        <small style="font-size:11px;color:#646970;display:block;margin-top:4px;">
                            Used to securely verify Turnstile challenge tokens server-side.
                        </small>
                    </div>

                    <div class="wcotl-form-row">
                        <label>Rate Limit Threshold (max requests per minute per IP)</label>
                        <input type="number" name="wcotl_rate_limit_max_requests"
                               value="<?php echo esc_attr( $rate_limit_max ); ?>"
                               min="1" max="120" step="1" style="width:120px;">
                        <small style="font-size:11px;color:#646970;display:block;margin-top:4px;">
                            Default: 15 requests/minute. Limits rapid unauthenticated tracking searches per IP.
                        </small>
                    </div>

                    <button type="submit" class="button button-primary" style="margin-top:16px;">
                        Save Security Settings
                    </button>
                </form>
            </div>

            <!-- Info box -->
            <div class="card" style="max-width:620px;background:#f0f7ff;border-color:#b8d4f8;">
                <h2 style="color:#1a5fa8;">ℹ How auto-tracking works</h2>
                <ul style="font-size:13px;line-height:1.9;color:#2d4a6e;padding-left:18px;">
                    <li>For each plugin tracking code, you can attach a <strong>real carrier tracking number</strong> (e.g. <code>RR123456789CN</code>).</li>
                    <li>The plugin polls 17TRACK every <strong><?php echo $sync_interval; ?> hour(s)</strong> and automatically imports new updates as timeline steps.</li>
                    <li>Existing manual steps <strong>are never modified or deleted</strong>: they coexist alongside automatic ones.</li>
                    <li>When 17TRACK reports the <strong>Delivered</strong> status, the actual delivery date is set automatically.</li>
                    <li>Polling automatically stops for shipments: Delivered, Expired, or <strong>inactive for <?php echo $inactivity_days; ?> days</strong>.</li>
                    <li>In case of prolonged inactivity, an email notification is sent to the site admin.</li>
                </ul>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * AJAX: update delete data preference on uninstall
     * ------------------------------------------------------------------ */

    public static function ajax_set_uninstall_data_preference() {
        check_ajax_referer( 'wcotl_uninstall_nonce', 'nonce' );
        if ( ! current_user_can( 'delete_plugins' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Not allowed.' );
        }

        $delete_data = ! empty( $_POST['delete_data'] ) ? 1 : 0;
        update_option( 'wcotl_delete_data_on_uninstall', $delete_data );

        wp_send_json_success( [ 'delete_data' => $delete_data ] );
    }

    /* ------------------------------------------------------------------
     * Modal on plugins.php when user clicks "Delete"
     * ------------------------------------------------------------------ */

    public static function render_plugins_uninstall_modal() {
        global $pagenow;
        if ( $pagenow !== 'plugins.php' ) {
            return;
        }
        if ( ! current_user_can( 'delete_plugins' ) ) {
            return;
        }

        $delete_data_on_uninstall = (int) get_option( 'wcotl_delete_data_on_uninstall', 0 );
        $plugin_basename = plugin_basename( WCOTL_PLUGIN_FILE );
        $plugin_slug     = dirname( $plugin_basename );
        ?>
        <div id="wcotl-uninstall-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.65);z-index:999999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
            <div style="background:#fff;border-radius:8px;width:520px;max-width:92%;box-shadow:0 12px 30px rgba(0,0,0,0.25);overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;">
                <div style="padding:16px 20px;border-bottom:1px solid #e2e4e7;display:flex;align-items:center;justify-content:space-between;background:#fcfcfc;">
                    <h3 style="margin:0;font-size:16px;font-weight:600;color:#1d2327;display:flex;align-items:center;gap:8px;">
                        <span class="dashicons dashicons-trash" style="color:#d63638;"></span>
                        <?php esc_html_e( 'Uninstall WC Order Timeline Tracking', 'wc-order-timeline' ); ?>
                    </h3>
                    <button type="button" id="wcotl-uninstall-close-btn" style="background:none;border:none;cursor:pointer;font-size:20px;line-height:1;color:#787c82;">&times;</button>
                </div>
                <div style="padding:20px;font-size:13px;color:#3c434a;line-height:1.5;">
                    <p style="margin-top:0;margin-bottom:14px;">
                        <?php esc_html_e( 'You are about to delete the plugin. Please choose how you want to handle existing timeline tracking data:', 'wc-order-timeline' ); ?>
                    </p>

                    <div style="background:#f9f9f9;border:1px solid #e2e4e7;border-radius:6px;padding:14px;margin-bottom:12px;">
                        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin:0;">
                            <input type="checkbox" id="wcotl-uninstall-delete-data" name="wcotl_delete_data_on_uninstall" value="1" <?php checked( $delete_data_on_uninstall, 1 ); ?> style="margin-top:2px;">
                            <span>
                                <strong style="color:#1d2327;display:block;margin-bottom:3px;">
                                    <?php esc_html_e( 'Erase all plugin database tables and settings', 'wc-order-timeline' ); ?>
                                </strong>
                                <span style="font-size:12px;color:#646970;display:block;line-height:1.4;">
                                    <?php esc_html_e( 'Permanently delete all order timeline records, custom steps, metadata, presets, and configuration options. If unchecked, all data is retained safely.', 'wc-order-timeline' ); ?>
                                </span>
                            </span>
                        </label>
                    </div>
                </div>
                <div style="padding:14px 20px;border-top:1px solid #e2e4e7;background:#f6f7f7;display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" id="wcotl-uninstall-cancel-btn" class="button">
                        <?php esc_html_e( 'Cancel', 'wc-order-timeline' ); ?>
                    </button>
                    <button type="button" id="wcotl-uninstall-confirm-btn" class="button button-primary">
                        <?php esc_html_e( 'Proceed with Deletion', 'wc-order-timeline' ); ?>
                    </button>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var modal = document.getElementById('wcotl-uninstall-modal');
            if (!modal) return;

            var checkbox   = document.getElementById('wcotl-uninstall-delete-data');
            var confirmBtn = document.getElementById('wcotl-uninstall-confirm-btn');
            var cancelBtn  = document.getElementById('wcotl-uninstall-cancel-btn');
            var closeBtn   = document.getElementById('wcotl-uninstall-close-btn');
            var targetUrl  = '';

            function openModal(url) {
                targetUrl = url;
                modal.style.display = 'flex';
            }

            function closeModal() {
                modal.style.display = 'none';
                targetUrl = '';
            }

            cancelBtn.addEventListener('click', closeModal);
            closeBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeModal();
            });

            document.addEventListener('click', function(e) {
                var link = e.target.closest('a');
                if (!link) return;
                var href = link.getAttribute('href') || '';

                var pluginBasename = <?php echo json_encode( $plugin_basename ); ?>;
                var pluginSlug     = <?php echo json_encode( $plugin_slug ); ?>;

                var isDeleteAction = (
                    (href.indexOf('action=delete-selected') !== -1 && (href.indexOf(encodeURIComponent(pluginBasename)) !== -1 || href.indexOf(pluginBasename) !== -1 || href.indexOf(pluginSlug) !== -1)) ||
                    (link.closest('tr[data-plugin="' + pluginBasename + '"]') && link.closest('span.delete')) ||
                    (link.closest('tr[data-slug="' + pluginSlug + '"]') && link.closest('span.delete'))
                );

                if (isDeleteAction) {
                    e.preventDefault();
                    e.stopPropagation();
                    openModal(link.href);
                }
            }, true);

            confirmBtn.addEventListener('click', function() {
                var deleteData = checkbox.checked ? 1 : 0;
                confirmBtn.disabled = true;
                confirmBtn.textContent = <?php echo json_encode( __( 'Saving preference...', 'wc-order-timeline' ) ); ?>;

                var params = new URLSearchParams();
                params.append('action', 'wcotl_set_uninstall_data_preference');
                params.append('nonce', <?php echo json_encode( wp_create_nonce( 'wcotl_uninstall_nonce' ) ); ?>);
                params.append('delete_data', deleteData);

                fetch(ajaxurl, {
                    method: 'POST',
                    body: params,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }
                }).then(function() {
                    window.location.href = targetUrl;
                }).catch(function() {
                    window.location.href = targetUrl;
                });
            });
        })();
        </script>
        <?php
    }
}

